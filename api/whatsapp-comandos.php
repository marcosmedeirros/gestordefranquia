<?php
/**
 * Os comandos que o bot responde no grupo.
 *
 * Separado do webhook de propósito: aqui não há nada de HTTP, só PDO entrando e
 * texto saindo. Dá pra testar cada comando pela linha de comando sem simular
 * requisição nenhuma.
 *
 * Todo comando devolve string (a resposta) ou null (não é comando meu — fica
 * quieto, porque o grupo é de gente conversando e bot que responde "comando
 * inválido" a cada barra vira ruído).
 */

require_once __DIR__ . '/../backend/salary_cap.php';

/** Nome de exibição do time: "Cidade Mascote", com fallback pro name. */
function wcNomeDoTime(array $t): string
{
    $composto = trim(trim((string)($t['city'] ?? '')) . ' ' . trim((string)($t['mascot'] ?? '')));
    return $composto !== '' ? $composto : trim((string)($t['name'] ?? '?'));
}

/** Coluna de OVR — o banco antigo usava 'overall'. */
function wcColunaOvr(PDO $pdo): string
{
    static $col = null;
    if ($col === null) {
        $st = $pdo->query("SHOW COLUMNS FROM players LIKE 'ovr'");
        $col = ($st && $st->rowCount() > 0) ? 'ovr' : 'overall';
    }
    return $col;
}

/** A liga cobra folha em dinheiro (ELITE hoje) ou soma de OVR? */
function wcLigaEmSalario(PDO $pdo, string $league): bool
{
    $st = $pdo->prepare("SELECT cap_mode FROM league_settings WHERE league = ?");
    $st->execute([$league]);
    return (($st->fetchColumn() ?: 'ovr_sum') === 'salary');
}

/**
 * Acha o time pelo que a pessoa digitou. Ela vai escrever "Lakers", não
 * "Los Angeles Lakers" — então procuro em cidade, mascote, nome e no
 * "cidade mascote" concatenado, e aceito o pedaço no meio da palavra.
 */
function wcAcharTimes(PDO $pdo, string $termo): array
{
    $like = '%' . $termo . '%';
    $st = $pdo->prepare("
        SELECT t.id, t.name, t.city, t.mascot, t.league, t.conference, u.name AS gm
        FROM teams t
        LEFT JOIN users u ON u.id = t.user_id
        WHERE t.name LIKE ? OR t.city LIKE ? OR t.mascot LIKE ?
           OR CONCAT(t.city, ' ', t.mascot) LIKE ?
        ORDER BY t.league, t.city
        LIMIT 6
    ");
    $st->execute([$like, $like, $like, $like]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Resolve o termo pra UM time. Se der ambíguo, devolve a lista pra pessoa
 * escolher em vez de chutar o primeiro — chutar é pior que perguntar.
 * Retorna [time|null, mensagemDeErro|null].
 */
function wcResolverTime(PDO $pdo, string $termo): array
{
    $times = wcAcharTimes($pdo, $termo);
    if (!$times) {
        return [null, "Não achei time com \"{$termo}\"."];
    }
    if (count($times) > 1) {
        // Match exato desempata: quem digitou "Heat" quer o Heat, mesmo que
        // exista um "Heaters" na lista.
        $exatos = array_values(array_filter($times, function ($t) use ($termo) {
            $alvo = mb_strtolower($termo);
            return mb_strtolower((string)$t['mascot']) === $alvo
                || mb_strtolower((string)$t['city']) === $alvo
                || mb_strtolower((string)$t['name']) === $alvo
                || mb_strtolower(wcNomeDoTime($t)) === $alvo;
        }));
        if (count($exatos) === 1) return [$exatos[0], null];

        $lista = implode("\n", array_map(fn($t) => '• ' . wcNomeDoTime($t) . ' (' . $t['league'] . ')', $times));
        return [null, "Achei mais de um com \"{$termo}\":\n{$lista}\n\nSeja mais específico."];
    }
    return [$times[0], null];
}

/** Temporada ativa da liga (a que está rolando), pra classificação. */
function wcTemporadaAtiva(PDO $pdo, string $league): ?array
{
    $st = $pdo->prepare("SELECT id, season_number, status FROM seasons
                         WHERE league = ? AND status IN ('regular','playoffs')
                         ORDER BY season_number DESC LIMIT 1");
    $st->execute([$league]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if ($s) return $s;

    $st = $pdo->prepare("SELECT id, season_number, status FROM seasons
                         WHERE league = ? ORDER BY season_number DESC LIMIT 1");
    $st->execute([$league]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Normaliza o nome da liga que a pessoa digitou. */
function wcNormalizarLiga(string $termo): ?string
{
    $t = mb_strtoupper(trim($termo));
    foreach (['ELITE', 'NEXT', 'RISE', 'ROOKIE'] as $liga) {
        if ($t === $liga || str_starts_with($liga, $t)) return $liga;
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────────
// Comandos
// ─────────────────────────────────────────────────────────────────────────

function wcAjuda(): string
{
    return "*Comandos da FBA*\n\n"
        . "/jogador _nome_ — time, idade, OVR e salário\n"
        . "/time _nome_ — elenco, folha e campanha\n"
        . "/cap _time_ — folha detalhada e espaço no cap\n"
        . "/picks _time_ — picks que o time tem\n"
        . "/classificacao _liga_ — tabela da liga\n"
        . "/guia — o guia do GM\n\n"
        . "Ex.: /jogador lebron  •  /cap lakers  •  /classificacao elite";
}

function wcJogador(PDO $pdo, string $termo): string
{
    if ($termo === '') return "Use assim: /jogador lebron";

    $ovr = wcColunaOvr($pdo);
    $st = $pdo->prepare("
        SELECT p.id, p.name, p.age, p.position, p.secondary_position, p.{$ovr} AS ovr,
               p.seasons_in_league, p.team_id, COALESCE(p.is_lenda, 0) AS is_lenda,
               t.city, t.mascot, t.name AS team_name, t.league
        FROM players p JOIN teams t ON t.id = p.team_id
        WHERE p.name LIKE ?
        ORDER BY p.{$ovr} DESC
        LIMIT 8
    ");
    $st->execute(['%' . $termo . '%']);
    $achados = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$achados) return "Não achei jogador com \"{$termo}\".";

    // Vários: lista enxuta, senão a mensagem vira parede de texto no grupo.
    if (count($achados) > 1) {
        $linhas = array_map(function ($p) {
            return '• *' . $p['name'] . '* — ' . $p['ovr'] . ' OVR, '
                . $p['position'] . ', ' . $p['age'] . ' anos — '
                . wcNomeDoTime($p) . ' (' . $p['league'] . ')';
        }, $achados);
        return "Achei " . count($achados) . " com \"{$termo}\":\n" . implode("\n", $linhas);
    }

    $p = $achados[0];
    $pos = $p['position'] . ($p['secondary_position'] ? '/' . $p['secondary_position'] : '');
    $txt = "*{$p['name']}*" . (!empty($p['is_lenda']) ? ' 👑 LENDA' : '') . "\n"
        . wcNomeDoTime($p) . " — {$p['league']}\n\n"
        . "OVR: *{$p['ovr']}*\n"
        . "Posição: {$pos}\n"
        . "Idade: {$p['age']} anos\n"
        . "Temporadas na liga: " . (int)$p['seasons_in_league'] . "\n";

    // Salário só faz sentido onde a liga cobra folha em dinheiro.
    if (wcLigaEmSalario($pdo, (string)$p['league'])) {
        $cap = getTeamCapSummary($pdo, (int)$p['team_id']);
        foreach (($cap['roster'] ?? []) as $r) {
            if ((int)$r['id'] === (int)$p['id']) {
                $txt .= "Salário: *{$r['total_salary']}M*\n";
                break;
            }
        }
    }
    return rtrim($txt);
}

function wcTime(PDO $pdo, string $termo): string
{
    if ($termo === '') return "Use assim: /time lakers";

    [$t, $erro] = wcResolverTime($pdo, $termo);
    if ($erro) return $erro;

    $ovr = wcColunaOvr($pdo);
    $st = $pdo->prepare("SELECT name, position, age, {$ovr} AS ovr FROM players
                         WHERE team_id = ? ORDER BY {$ovr} DESC");
    $st->execute([(int)$t['id']]);
    $elenco = $st->fetchAll(PDO::FETCH_ASSOC);

    $txt = '*' . wcNomeDoTime($t) . "*\n"
        . $t['league'] . ($t['conference'] ? ' — ' . $t['conference'] : '') . "\n"
        . 'GM: ' . ($t['gm'] ?: 'sem dono') . "\n\n"
        . 'Elenco: ' . count($elenco) . " jogadores\n";

    if (wcLigaEmSalario($pdo, (string)$t['league'])) {
        $cap = getTeamCapSummary($pdo, (int)$t['id']);
        $espaco = (int)$cap['space'];
        $txt .= "Folha: *{$cap['payroll']}M* de {$cap['cap_max']}M"
             . ' (' . ($espaco >= 0 ? "sobra {$espaco}M" : 'estourou ' . abs($espaco) . 'M') . ")\n";
    }

    // Campanha da temporada corrente, se já houver jogo registrado.
    $temp = wcTemporadaAtiva($pdo, (string)$t['league']);
    if ($temp) {
        $st = $pdo->prepare("SELECT wins, losses, position FROM season_standings
                             WHERE season_id = ? AND team_id = ?");
        $st->execute([(int)$temp['id'], (int)$t['id']]);
        if ($c = $st->fetch(PDO::FETCH_ASSOC)) {
            $txt .= "Campanha: {$c['wins']}-{$c['losses']}"
                 . ($c['position'] ? " ({$c['position']}º)" : '') . "\n";
        }
    }

    if ($elenco) {
        $txt .= "\n*Melhores:*\n";
        foreach (array_slice($elenco, 0, 5) as $p) {
            $txt .= "• {$p['name']} — {$p['ovr']} OVR, {$p['position']}, {$p['age']} anos\n";
        }
    }
    return rtrim($txt);
}

function wcCap(PDO $pdo, string $termo): string
{
    if ($termo === '') return "Use assim: /cap lakers";

    [$t, $erro] = wcResolverTime($pdo, $termo);
    if ($erro) return $erro;

    if (!wcLigaEmSalario($pdo, (string)$t['league'])) {
        return wcNomeDoTime($t) . " está na {$t['league']}, que não usa folha em dinheiro — o limite lá é por soma de OVR.";
    }

    $cap = getTeamCapSummary($pdo, (int)$t['id']);
    $espaco = (int)$cap['space'];
    $rotulo = [
        'over_the_cap'  => '🔴 acima do teto',
        'abaixo_do_piso' => '🟡 abaixo do piso',
        'dentro_do_cap' => '🟢 dentro do cap',
    ][$cap['status']] ?? $cap['status'];

    $txt = '*Cap — ' . wcNomeDoTime($t) . "*\n{$rotulo}\n\n"
        . "Folha: *{$cap['payroll']}M*\n"
        . "Teto: {$cap['cap_max']}M (base {$cap['cap_base']}M)\n"
        . "Piso: {$cap['cap_floor']}M\n"
        . ($espaco >= 0 ? "Espaço: *{$espaco}M*\n" : "Estouro: *" . abs($espaco) . "M*\n");

    if ((int)$cap['cap_flex_total'] > 0) {
        $txt .= "Cap Flex: +{$cap['cap_flex_total']}M ({$cap['cap_flex_used_slots']} de {$cap['cap_flex_max_players']})\n";
    }
    if ((int)$cap['cap_loyalty_total'] > 0) {
        $txt .= "Lealdade: +{$cap['cap_loyalty_total']}M ({$cap['cap_loyalty_used_slots']} de {$cap['cap_loyalty_max_players']})\n";
    }

    $roster = $cap['roster'] ?? [];
    usort($roster, fn($a, $b) => (int)$b['total_salary'] <=> (int)$a['total_salary']);
    if ($roster) {
        $txt .= "\n*Maiores salários:*\n";
        foreach (array_slice($roster, 0, 6) as $r) {
            $txt .= "• {$r['name']} — {$r['total_salary']}M\n";
        }
    }
    return rtrim($txt);
}

function wcPicks(PDO $pdo, string $termo): string
{
    if ($termo === '') return "Use assim: /picks lakers";

    [$t, $erro] = wcResolverTime($pdo, $termo);
    if ($erro) return $erro;

    $st = $pdo->prepare("
        SELECT p.season_year, p.round, p.original_team_id, o.city AS o_city, o.mascot AS o_mascot, o.name AS o_name
        FROM picks p
        LEFT JOIN teams o ON o.id = p.original_team_id
        WHERE p.team_id = ?
        ORDER BY p.season_year ASC, p.round ASC
    ");
    $st->execute([(int)$t['id']]);
    $picks = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$picks) return wcNomeDoTime($t) . ' não tem nenhuma pick.';

    $porAno = [];
    foreach ($picks as $p) {
        $rot = $p['round'] . 'ª';
        // Pick que veio de outro time: dizer de quem é o que importa numa troca.
        if ((int)$p['original_team_id'] !== (int)$t['id']) {
            $rot .= ' (do ' . wcNomeDoTime(['city' => $p['o_city'], 'mascot' => $p['o_mascot'], 'name' => $p['o_name']]) . ')';
        }
        $porAno[(int)$p['season_year']][] = $rot;
    }

    $txt = '*Picks — ' . wcNomeDoTime($t) . "*\n" . count($picks) . " no total\n\n";
    foreach ($porAno as $ano => $lista) {
        $txt .= "*{$ano}:* " . implode(', ', $lista) . "\n";
    }
    return rtrim($txt);
}

function wcClassificacao(PDO $pdo, string $termo, ?string $ligaDoGrupo = null): string
{
    // Sem argumento, vale a liga do grupo: quem digita /classificacao no Chat
    // Off da RISE quer a RISE, não a ELITE. Só cai no padrão quando o grupo não
    // é de liga nenhuma (o principal, o Geral).
    $liga = wcNormalizarLiga($termo !== '' ? $termo : ($ligaDoGrupo ?: 'ELITE'));
    if (!$liga) return "Liga não reconhecida. Use ELITE, NEXT, RISE ou ROOKIE.";

    $temp = wcTemporadaAtiva($pdo, $liga);
    if (!$temp) return "A {$liga} ainda não tem temporada cadastrada.";

    $st = $pdo->prepare("
        SELECT s.wins, s.losses, s.position, s.conference, t.city, t.mascot, t.name
        FROM season_standings s JOIN teams t ON t.id = s.team_id
        WHERE s.season_id = ?
        ORDER BY s.wins DESC, s.losses ASC
        LIMIT 30
    ");
    $st->execute([(int)$temp['id']]);
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$linhas) return "A {$liga} ainda não tem jogos registrados na temporada {$temp['season_number']}.";

    $txt = "*Classificação {$liga}* — temporada {$temp['season_number']}\n\n";
    $i = 1;
    foreach ($linhas as $l) {
        $txt .= str_pad((string)$i, 2, ' ', STR_PAD_LEFT) . '. '
             . wcNomeDoTime($l) . " — {$l['wins']}-{$l['losses']}\n";
        $i++;
    }
    return rtrim($txt);
}

// ─────────────────────────────────────────────────────────────────────────

/**
 * Roteia o texto pro comando. Retorna null quando não é comando conhecido —
 * o webhook trata null como "não responder".
 *
 * $ligaDoGrupo é a liga do grupo de onde veio a mensagem, quando ele é de uma
 * liga só. Serve pros comandos que dá pra responder sem argumento.
 */
function wcResponderComando(PDO $pdo, string $texto, ?string $ligaDoGrupo = null): ?string
{
    $texto = trim($texto);
    if ($texto === '' || $texto[0] !== '/') return null;

    // Separa "/cap lakers" em "cap" e "lakers".
    $partes = preg_split('/\s+/', substr($texto, 1), 2);
    $cmd = mb_strtolower($partes[0] ?? '');
    $arg = trim($partes[1] ?? '');

    // O WhatsApp deixa mencionar o bot; tirar o @ evita comando engolido.
    $arg = trim(preg_replace('/@\d+/', '', $arg));

    try {
        switch ($cmd) {
            case 'ajuda':
            case 'comandos':
            case 'help':
                return wcAjuda();

            case 'jogador':
            case 'player':
                return wcJogador($pdo, $arg);

            case 'time':
            case 'elenco':
                return wcTime($pdo, $arg);

            case 'cap':
            case 'folha':
                return wcCap($pdo, $arg);

            case 'picks':
            case 'pick':
                return wcPicks($pdo, $arg);

            case 'classificacao':
            case 'classificação':
            case 'tabela':
                return wcClassificacao($pdo, $arg, $ligaDoGrupo);

            case 'guia':
                return "*Guia do GM:* https://fbabrasil.com.br/guia.php";

            default:
                return null;
        }
    } catch (Throwable $e) {
        // Erro meu não pode virar stack trace no grupo.
        error_log('[whatsapp-cmd] ' . $cmd . ': ' . $e->getMessage());
        return 'Deu erro aqui ao buscar isso. Avisa o admin.';
    }
}
