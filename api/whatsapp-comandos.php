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
require_once __DIR__ . '/../backend/helpers.php';   // CAP_TOP_N
require_once __DIR__ . '/../backend/playoff_series.php';

/**
 * Nome de exibição do time: "Cidade Nome", como o resto do app monta.
 *
 * Não é cidade + mascot: são campos diferentes e, quando divergem, o mascot
 * dá o nome errado. Aceita team_name pra quando a consulta precisou apelidar
 * a coluna (JOIN com players, que também tem `name`).
 */
function wcNomeDoTime(array $t): string
{
    $nome = trim((string)($t['team_name'] ?? $t['name'] ?? ''));
    $composto = trim(trim((string)($t['city'] ?? '')) . ' ' . $nome);
    return $composto !== '' ? $composto : ($nome !== '' ? $nome : '?');
}

/**
 * As dez skills, na ordem em que fazem sentido ler: ataque, defesa, resto.
 * Rótulo curto de propósito — no WhatsApp cada linha conta.
 */
const WC_SKILLS = [
    'skill_in'     => 'Garrafão',
    'skill_mid'    => 'Média',
    'skill_3pt'    => '3 pontos',
    'skill_post_d' => 'Def. poste',
    'skill_per_d'  => 'Def. perím.',
    'skill_play'   => 'Passe',
    'skill_reb'    => 'Rebote',
    'skill_athl'   => 'Atletismo',
    'skill_iq'     => 'QI de jogo',
    'skill_pot'    => 'Potencial',
];

/** Nota em número, pra dar pra comparar letra com letra. Mesma escala do site. */
function wcNotaSkill($v): ?float
{
    if ($v === null || $v === '' || $v === '-') return null;
    if (is_numeric($v)) return (float)$v;
    $tabela = ['A+'=>95,'A'=>90,'A-'=>85,'B+'=>80,'B'=>75,'B-'=>70,
               'C+'=>65,'C'=>60,'C-'=>55,'D+'=>50,'D'=>45,'D-'=>40,'F'=>30];
    return $tabela[strtoupper(trim((string)$v))] ?? null;
}

/**
 * Skills do jogador, já resolvidas.
 *
 * A coluna skill_* manda; o JSON player_skill_grades é o retrato antigo e só
 * vale onde a coluna está vazia. Mesma regra do statsjogadores.php e do
 * player.php — se eu lesse só uma das fontes, jogador cadastrado pelo caminho
 * antigo apareceria sem skill nenhuma.
 *
 * Devolve [rótulo => valor], só com as preenchidas.
 */
function wcSkillsDoJogador(array $p): array
{
    $json = [];
    if (!empty($p['player_skill_grades'])) {
        $d = json_decode((string)$p['player_skill_grades'], true);
        if (is_array($d)) $json = $d;
    }
    // As chaves do JSON não são iguais às das colunas.
    $doJson = ['skill_in'=>'in','skill_mid'=>'mid','skill_3pt'=>'pt3','skill_post_d'=>'post_d',
               'skill_per_d'=>'per_d','skill_play'=>'play','skill_reb'=>'reb','skill_athl'=>'athl',
               'skill_iq'=>'iq','skill_pot'=>'pot'];

    $out = [];
    foreach (WC_SKILLS as $col => $rotulo) {
        $v = $p[$col] ?? null;
        if ($v === null || $v === '' || $v === '-') $v = $json[$doJson[$col]] ?? null;
        if ($v === null || $v === '' || $v === '-') continue;
        $out[$rotulo] = $v;
    }
    return $out;
}

/** Trecho do SELECT com as colunas de skill — usado em mais de uma consulta. */
function wcColunasSkill(string $alias = 'p'): string
{
    return $alias . '.player_skill_grades, '
         . implode(', ', array_map(fn($c) => $alias . '.' . $c, array_keys(WC_SKILLS)));
}

/**
 * Números da temporada mais recente em que o jogador teve lançamento.
 *
 * Não fixo na temporada "atual" de propósito: quem não jogou ainda apareceria
 * zerado, e mostrar o último ano que ele tem é mais útil que mostrar nada.
 * O retorno diz de qual temporada é, pra ninguém confundir.
 */
function wcStatsDoJogador(PDO $pdo, int $playerId): ?array
{
    try {
        $st = $pdo->prepare("
            SELECT ps.season_number, ps.games, ps.min_pg, ps.pts_pg, ps.reb_pg,
                   ps.ast_pg, ps.stl_pg, ps.blk_pg
            FROM player_season_stats ps
            WHERE ps.player_id = ? AND ps.games > 0
            ORDER BY ps.season_number DESC, ps.id DESC
            LIMIT 1
        ");
        $st->execute([$playerId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('[whatsapp-cmd] stats: ' . $e->getMessage());
        return null;
    }
}

/** Número curto pro WhatsApp: 24.1 vira "24,1" e 7.0 vira "7". */
function wcNum($v): string
{
    $n = (float)$v;
    return $n == floor($n) ? (string)(int)$n : number_format($n, 1, ',', '');
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
 * "Los Angeles Lakers" — então aceito o pedaço no meio da palavra.
 *
 * Procuro no nome completo ("Cidade Nome", que é como o time aparece) e também
 * no mascot: ele não é usado pra exibir, mas alguém pode digitar por ele.
 */
function wcAcharTimes(PDO $pdo, string $termo): array
{
    $like = '%' . $termo . '%';
    $st = $pdo->prepare("
        SELECT t.id, t.name, t.city, t.mascot, t.league, t.conference, u.name AS gm
        FROM teams t
        LEFT JOIN users u ON u.id = t.user_id
        WHERE t.name LIKE ? OR t.city LIKE ? OR t.mascot LIKE ?
           OR CONCAT(t.city, ' ', t.name) LIKE ?
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
        . "*Seu time* (pelo seu telefone)\n"
        . "/meuelenco · /meucap · /minhaspicks\n\n"
        . "*Consulta*\n"
        . "/jogador _nome_ — time, idade, OVR e salário\n"
        . "/comparar _um_ x _outro_ — jogadores lado a lado\n"
        . "/comparartime _um_ x _outro_ — times lado a lado\n"
        . "/confronto _um_ x _outro_ — o histórico entre dois times\n"
        . "/time _nome_ — elenco, folha e campanha\n"
        . "/cap _time_ — folha e espaço no cap\n"
        . "/picks _time_ — picks que o time tem\n\n"
        . "*Liga*\n"
        . "/classificacao _liga_ — a tabela\n"
        . "/trocas — as últimas trocas aprovadas\n"
        . "/lendas — os marcados como LENDA\n"
        . "/hall — o Hall da Fama\n"
        . "/premios — os prêmios da temporada\n"
        . "/guia — o guia do GM\n\n"
        . "Ex.: /comparar lebron x tatum  •  /meucap  •  /trocas";
}

function wcJogador(PDO $pdo, string $termo): string
{
    if ($termo === '') return "Use assim: /jogador lebron";

    $ovr = wcColunaOvr($pdo);
    $st = $pdo->prepare("
        SELECT p.id, p.name, p.age, p.position, p.secondary_position, p.{$ovr} AS ovr,
               p.seasons_in_league, p.team_id, COALESCE(p.is_lenda, 0) AS is_lenda,
               " . wcColunasSkill('p') . ",
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

    if ($s = wcStatsDoJogador($pdo, (int)$p['id'])) {
        $txt .= "\n📊 *Temporada {$s['season_number']}*\n"
              . wcNum($s['pts_pg']) . ' pts · ' . wcNum($s['reb_pg']) . ' reb · ' . wcNum($s['ast_pg']) . " ast\n"
              . wcNum($s['stl_pg']) . ' rou · ' . wcNum($s['blk_pg']) . ' toc · ' . wcNum($s['min_pg']) . ' min'
              . ' em ' . (int)$s['games'] . " jogos\n";
    }

    if ($skills = wcSkillsDoJogador($p)) {
        $txt .= "\n⭐ *Skills*\n";
        // Duas por linha: dez linhas soltas viram parede de texto no celular.
        $pares = array_chunk(array_map(
            fn($k, $v) => $k . ' *' . $v . '*',
            array_keys($skills), $skills
        ), 2);
        foreach ($pares as $par) $txt .= implode('  ·  ', $par) . "\n";
    }

    return rtrim($txt);
}

/**
 * O quinteto titular, na ordem PG, SG, SF, PF, C.
 *
 * Não é "os 5 melhores": um time com três alas e nenhum pivô não joga com três
 * alas. Pra cada vaga eu procuro, nesta ordem — titular da posição, titular que
 * joga ali de segunda, qualquer um da posição, qualquer um que jogue ali de
 * segunda. Dentro de cada faixa vale o maior OVR, e ninguém ocupa duas vagas.
 *
 * Vaga sem candidato fica vazia em vez de ser preenchida com quem sobrou:
 * mostrar um armador de pivô esconderia justamente o buraco do elenco.
 *
 * Devolve [posição => jogador|null].
 */
function wcQuintetoTitular(array $elenco): array
{
    $vagas = ['PG' => null, 'SG' => null, 'SF' => null, 'PF' => null, 'C' => null];
    $usados = [];

    $pos = fn($p, $campo) => strtoupper(trim((string)($p[$campo] ?? '')));
    $ehTitular = fn($p) => ($p['role'] ?? '') === 'Titular';

    // Da preferência mais forte pra mais fraca. Só desce de faixa depois de
    // esgotar a anterior em TODAS as vagas seria pior: uma vaga sem titular
    // roubaria o titular de outra. Resolvo vaga a vaga, faixa a faixa.
    foreach (array_keys($vagas) as $vaga) {
        foreach ([
            fn($p) => $ehTitular($p) && $pos($p, 'position') === $vaga,
            fn($p) => $ehTitular($p) && $pos($p, 'secondary_position') === $vaga,
            fn($p) => $pos($p, 'position') === $vaga,
            fn($p) => $pos($p, 'secondary_position') === $vaga,
        ] as $criterio) {
            $candidatos = array_filter($elenco, fn($p) => !in_array($p['name'], $usados, true) && $criterio($p));
            if (!$candidatos) continue;
            // O elenco já vem por OVR desc, então o primeiro é o melhor.
            $escolhido = reset($candidatos);
            $vagas[$vaga] = $escolhido;
            $usados[] = $escolhido['name'];
            break;
        }
    }

    // Passe de ajuste: a busca acima é gulosa, então pode deixar uma vaga vazia
    // com jogador sobrando no elenco. É o caso do time com dois SF, um deles
    // capaz de jogar PF: o melhor pega SF, o PF fica vazio e o outro SF não
    // entra. Aqui eu tento a troca — quem está numa vaga que ele cobre de
    // segunda desce pra vaga vazia, e o que sobrou assume a dele.
    foreach ($vagas as $vaga => $ocupante) {
        if ($ocupante !== null) continue;

        foreach ($vagas as $outraVaga => $outro) {
            if ($outro === null || $pos($outro, 'secondary_position') !== $vaga) continue;

            $substituto = null;
            foreach ($elenco as $p) {
                if (in_array($p['name'], $usados, true)) continue;
                if ($pos($p, 'position') === $outraVaga || $pos($p, 'secondary_position') === $outraVaga) {
                    $substituto = $p;
                    break;   // elenco já vem por OVR desc
                }
            }
            if (!$substituto) continue;

            $vagas[$vaga] = $outro;
            $vagas[$outraVaga] = $substituto;
            $usados[] = $substituto['name'];
            break;
        }
    }

    return $vagas;
}

function wcTime(PDO $pdo, string $termo, ?array $jaResolvido = null): string
{
    if ($jaResolvido) {
        $t = $jaResolvido;
    } else {
        if ($termo === '') return "Use assim: /time lakers";
        [$t, $erro] = wcResolverTime($pdo, $termo);
        if ($erro) return $erro;
    }

    $ovr = wcColunaOvr($pdo);
    $st = $pdo->prepare("SELECT name, position, secondary_position, role, age, {$ovr} AS ovr
                         FROM players WHERE team_id = ? ORDER BY {$ovr} DESC");
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
        $quinteto = wcQuintetoTitular($elenco);

        $txt .= "\n*Quinteto titular:*\n";
        foreach ($quinteto as $vaga => $p) {
            $txt .= $p
                ? "{$vaga}: {$p['name']} {$p['ovr']} | {$p['age']}y\n"
                : "{$vaga}: _sem jogador na posição_\n";
        }

        // O banco é quem o GM marcou como banco, e só. Era "todo mundo que
        // não coube no quinteto", o que misturava reserva de verdade com
        // titular que perdeu a vaga pra um companheiro de mesma posição —
        // duas coisas diferentes na mesma lista.
        //
        // Sem ordem de posição aqui: vale o OVR, na ordem em que o elenco
        // já vem do SELECT.
        //
        // Quem está marcado como Banco mas subiu ao quinteto (acontece quando
        // a posição não tem titular) sai daqui: o quinteto já mostrou ele, e
        // repetir o mesmo nome duas vezes na mensagem parece defeito.
        $noQuinteto = [];
        foreach ($quinteto as $p) {
            if ($p) $noQuinteto[] = $p['name'];
        }
        $banco = array_values(array_filter($elenco,
            fn($p) => strcasecmp(trim((string)($p['role'] ?? '')), 'Banco') === 0
                   && !in_array($p['name'], $noQuinteto, true)));

        if ($banco) {
            $txt .= "\n*Banco:* (" . count($banco) . ")\n";
            foreach ($banco as $p) {
                $pos = strtoupper(trim((string)($p['position'] ?? ''))) ?: '--';
                $txt .= "{$pos}: {$p['name']} {$p['ovr']} | {$p['age']}y\n";
            }
        }
    }
    return rtrim($txt);
}

function wcCap(PDO $pdo, string $termo, ?array $jaResolvido = null): string
{
    if ($jaResolvido) {
        $t = $jaResolvido;
    } else {
        if ($termo === '') return "Use assim: /cap lakers";
        [$t, $erro] = wcResolverTime($pdo, $termo);
        if ($erro) return $erro;
    }

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

function wcPicks(PDO $pdo, string $termo, ?array $jaResolvido = null): string
{
    if ($jaResolvido) {
        $t = $jaResolvido;
    } else {
        if ($termo === '') return "Use assim: /picks lakers";
        [$t, $erro] = wcResolverTime($pdo, $termo);
        if ($erro) return $erro;
    }

    $st = $pdo->prepare("
        SELECT p.season_year, p.round, p.original_team_id, o.city AS o_city, o.name AS o_name
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
            $rot .= ' (do ' . wcNomeDoTime(['city' => $p['o_city'], 'name' => $p['o_name']]) . ')';
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

function wcTrocas(PDO $pdo, string $termo, ?string $ligaDoGrupo): string
{
    $liga = $termo !== '' ? wcNormalizarLiga($termo) : $ligaDoGrupo;
    $filtro = $liga ? ' AND t.league = ?' : '';
    $params = $liga ? [$liga] : [];

    $st = $pdo->prepare("
        SELECT t.id, t.league, t.updated_at,
               de.city AS de_city, de.name AS de_name,
               pra.city AS pra_city, pra.name AS pra_name
        FROM trades t
        JOIN teams de  ON de.id  = t.from_team_id
        JOIN teams pra ON pra.id = t.to_team_id
        WHERE t.status = 'accepted' {$filtro}
        ORDER BY t.updated_at DESC, t.id DESC
        LIMIT 5
    ");
    $st->execute($params);
    $trocas = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$trocas) return 'Nenhuma troca aprovada' . ($liga ? " na {$liga}" : '') . ' ainda.';

    // Os itens guardam nome/OVR copiados na hora da troca — então continuam
    // certos mesmo depois de o jogador rodar por mais times.
    $stItens = $pdo->prepare("SELECT from_team, player_name, player_ovr, pick_id FROM trade_items WHERE trade_id = ? ORDER BY id");

    $txt = '*Últimas trocas' . ($liga ? " — {$liga}" : '') . "*\n";
    foreach ($trocas as $t) {
        $stItens->execute([(int)$t['id']]);
        $vai = []; $vem = [];
        foreach ($stItens->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $rot = $i['player_name']
                ? $i['player_name'] . ($i['player_ovr'] ? ' (' . $i['player_ovr'] . ')' : '')
                : ($i['pick_id'] ? 'uma pick' : '?');
            if (!empty($i['from_team'])) $vai[] = $rot; else $vem[] = $rot;
        }
        $deNome  = wcNomeDoTime(['city' => $t['de_city'],  'name' => $t['de_name']]);
        $praNome = wcNomeDoTime(['city' => $t['pra_city'], 'name' => $t['pra_name']]);

        $txt .= "\n*{$deNome}* ⇄ *{$praNome}*"
              . ($liga ? '' : ' _' . $t['league'] . '_') . "\n"
              . '→ ' . ($vai ? implode(', ', $vai) : 'nada') . "\n"
              . '← ' . ($vem ? implode(', ', $vem) : 'nada') . "\n";
    }
    return rtrim($txt);
}

function wcComparar(PDO $pdo, string $termo): string
{
    // Aceita "x", "vs", "versus" ou "e" como separador — o pessoal digita
    // qualquer um dos quatro.
    $partes = preg_split('/\s+(?:x|vs\.?|versus|e)\s+/iu', $termo, 2);
    if (count($partes) < 2 || trim($partes[0]) === '' || trim($partes[1]) === '') {
        return "Use assim: /comparar lebron x tatum";
    }

    $ovr = wcColunaOvr($pdo);
    $achar = function (string $nome) use ($pdo, $ovr) {
        $st = $pdo->prepare("
            SELECT p.id, p.name, p.age, p.position, p.{$ovr} AS ovr, p.seasons_in_league,
                   p.team_id, COALESCE(p.is_lenda,0) AS is_lenda,
                   " . wcColunasSkill('p') . ",
                   t.city, t.name AS team_name, t.league
            FROM players p JOIN teams t ON t.id = p.team_id
            WHERE p.name LIKE ? ORDER BY p.{$ovr} DESC LIMIT 1
        ");
        $st->execute(['%' . trim($nome) . '%']);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    };

    $a = $achar($partes[0]);
    $b = $achar($partes[1]);
    if (!$a) return 'Não achei jogador com "' . trim($partes[0]) . '".';
    if (!$b) return 'Não achei jogador com "' . trim($partes[1]) . '".';
    if ((int)$a['id'] === (int)$b['id']) return 'Os dois nomes acharam o mesmo jogador (' . $a['name'] . ').';

    $salario = function (array $p) use ($pdo): ?int {
        if (!wcLigaEmSalario($pdo, (string)$p['league'])) return null;
        foreach ((getTeamCapSummary($pdo, (int)$p['team_id'])['roster'] ?? []) as $r) {
            if ((int)$r['id'] === (int)$p['id']) return (int)$r['total_salary'];
        }
        return null;
    };
    $sa = $salario($a); $sb = $salario($b);

    // Marca quem leva em cada quesito — é o que a pessoa quer ver de relance.
    $m = fn($x, $y, bool $maiorGanha = true) => $x === $y ? '' : (($maiorGanha ? $x > $y : $x < $y) ? ' ✅' : '');

    $linha = function (string $rotulo, $va, $vb, string $sufA = '', string $sufB = '') {
        return "{$rotulo}: {$va}{$sufA}  |  {$vb}{$sufB}\n";
    };

    $txt = '*' . $a['name'] . '*' . (!empty($a['is_lenda']) ? ' 👑' : '')
         . "\n_vs_\n"
         . '*' . $b['name'] . '*' . (!empty($b['is_lenda']) ? ' 👑' : '') . "\n\n"
         . $linha('OVR', $a['ovr'], $b['ovr'], $m((int)$a['ovr'], (int)$b['ovr']), $m((int)$b['ovr'], (int)$a['ovr']))
         // Mais novo é vantagem: por isso este compara ao contrário.
         . $linha('Idade', $a['age'] . ' anos', $b['age'] . ' anos',
                  $m((int)$a['age'], (int)$b['age'], false), $m((int)$b['age'], (int)$a['age'], false))
         . $linha('Posição', $a['position'], $b['position'])
         . $linha('Temporadas', (int)$a['seasons_in_league'], (int)$b['seasons_in_league']);

    if ($sa !== null || $sb !== null) {
        // Salário menor é vantagem, mas só compara quando os dois têm.
        $txt .= $linha('Salário', $sa !== null ? $sa . 'M' : '—', $sb !== null ? $sb . 'M' : '—',
            ($sa !== null && $sb !== null) ? $m($sa, $sb, false) : '',
            ($sa !== null && $sb !== null) ? $m($sb, $sa, false) : '');
    }

    // ── Números da temporada ─────────────────────────────────────────────
    $ea = wcStatsDoJogador($pdo, (int)$a['id']);
    $eb = wcStatsDoJogador($pdo, (int)$b['id']);
    if ($ea || $eb) {
        // Se cada um tem lançamento de uma temporada diferente, dizer qual —
        // senão a comparação parece do mesmo ano e não é.
        $mesmaTemp = $ea && $eb && (int)$ea['season_number'] === (int)$eb['season_number'];
        $txt .= "\n📊 *" . ($mesmaTemp ? 'Temporada ' . (int)$ea['season_number'] : 'Última temporada de cada um') . "*\n";

        foreach ([['pts_pg','Pontos'], ['reb_pg','Rebotes'], ['ast_pg','Assist.'],
                  ['stl_pg','Roubos'], ['blk_pg','Tocos'], ['min_pg','Minutos']] as [$campo, $rot]) {
            $va = $ea ? (float)$ea[$campo] : null;
            $vb = $eb ? (float)$eb[$campo] : null;
            if ($va === null && $vb === null) continue;
            $txt .= $linha($rot,
                $va !== null ? wcNum($va) : '—',
                $vb !== null ? wcNum($vb) : '—',
                ($va !== null && $vb !== null) ? $m($va, $vb) : '',
                ($va !== null && $vb !== null) ? $m($vb, $va) : '');
        }
        if (!$mesmaTemp) {
            $txt .= '_' . ($ea ? 'T' . (int)$ea['season_number'] : 'sem dados')
                  . ' · ' . ($eb ? 'T' . (int)$eb['season_number'] : 'sem dados') . "_\n";
        }
    }

    // ── Skills ───────────────────────────────────────────────────────────
    $ska = wcSkillsDoJogador($a);
    $skb = wcSkillsDoJogador($b);
    if ($ska && !$skb) {
        // Dez linhas de "A+ | —" não comparam nada, só ocupam a tela.
        $txt .= "\n_" . $b['name'] . " ainda não tem skills cadastradas._\n";
    } elseif ($skb && !$ska) {
        $txt .= "\n_" . $a['name'] . " ainda não tem skills cadastradas._\n";
    } elseif ($ska && $skb) {
        $txt .= "\n⭐ *Skills*\n";
        foreach (WC_SKILLS as $rotulo) {
            $va = $ska[$rotulo] ?? null;
            $vb = $skb[$rotulo] ?? null;
            if ($va === null && $vb === null) continue;
            // Compara pela nota numérica: sem isso "A" e "B+" seriam só textos.
            $na = wcNotaSkill($va);
            $nb = wcNotaSkill($vb);
            $podeComparar = $na !== null && $nb !== null;
            $txt .= $linha($rotulo, $va ?? '—', $vb ?? '—',
                $podeComparar ? $m($na, $nb) : '',
                $podeComparar ? $m($nb, $na) : '');
        }
    }

    $txt .= "\n" . wcNomeDoTime($a) . ' (' . $a['league'] . ')'
          . '  |  ' . wcNomeDoTime($b) . ' (' . $b['league'] . ')';
    return $txt;
}

/**
 * Títulos do time na edição (sprint) atual da liga dele.
 *
 * O campeão de uma temporada é gravado em DOIS lugares — playoff_results
 * (position='champion') e season_history (champion_team_id) — e nem sempre nos
 * dois. Conto das duas fontes deduplicando por temporada: assim funciona seja
 * qual for a que o admin usou, e uma temporada registrada nas duas não vira
 * dois títulos.
 *
 * Retorna ['titulos' => int, 'edicao' => int|null].
 */
function wcTitulosNaEdicao(PDO $pdo, int $teamId, string $league): array
{
    try {
        $st = $pdo->prepare("SELECT id, sprint_number FROM sprints
                             WHERE league = ?
                             ORDER BY (status = 'active') DESC, sprint_number DESC
                             LIMIT 1");
        $st->execute([$league]);
        $sprint = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sprint) return ['titulos' => 0, 'edicao' => null];

        $st = $pdo->prepare("
            SELECT COUNT(DISTINCT s.id)
            FROM seasons s
            WHERE s.sprint_id = ?
              AND (EXISTS (SELECT 1 FROM playoff_results pr
                           WHERE pr.season_id = s.id AND pr.team_id = ? AND pr.position = 'champion')
                OR EXISTS (SELECT 1 FROM season_history sh
                           WHERE sh.season_id = s.id AND sh.champion_team_id = ?))
        ");
        $st->execute([(int)$sprint['id'], $teamId, $teamId]);

        return ['titulos' => (int)$st->fetchColumn(), 'edicao' => (int)$sprint['sprint_number']];
    } catch (Throwable $e) {
        // Tabela de playoffs ausente num banco antigo não pode derrubar a
        // comparação inteira — a linha some e o resto continua.
        error_log('[whatsapp-cmd] titulos: ' . $e->getMessage());
        return ['titulos' => 0, 'edicao' => null];
    }
}

/**
 * Quantas séries de playoff o time venceu, e até onde chegou de melhor.
 *
 * O banco guarda só ONDE o time parou (playoff_results.position), não quantas
 * séries ganhou — mas dá pra derivar: quem perdeu na 1ª rodada ganhou zero
 * séries, quem caiu na 2ª ganhou uma, e assim por diante até o campeão com
 * quatro. É a mesma contagem que qualquer um faria de cabeça.
 */
function wcPlayoffsNaEdicao(PDO $pdo, int $teamId, ?int $sprintId): array
{
    $SERIES = ['first_round' => 0, 'second_round' => 1, 'conference_final' => 2,
               'runner_up' => 3, 'champion' => 4];
    $NOME   = ['first_round' => '1ª rodada', 'second_round' => 'semi de conf.',
               'conference_final' => 'final de conf.', 'runner_up' => 'vice', 'champion' => 'campeão'];

    $out = ['series' => 0, 'melhor' => null, 'aparicoes' => 0];
    if (!$sprintId) return $out;

    try {
        $st = $pdo->prepare("
            SELECT pr.position
            FROM playoff_results pr
            JOIN seasons s ON s.id = pr.season_id
            WHERE pr.team_id = ? AND s.sprint_id = ?
        ");
        $st->execute([$teamId, $sprintId]);
        $melhorValor = -1;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pos) {
            if (!isset($SERIES[$pos])) continue;
            $out['series'] += $SERIES[$pos];
            $out['aparicoes']++;
            if ($SERIES[$pos] > $melhorValor) { $melhorValor = $SERIES[$pos]; $out['melhor'] = $NOME[$pos]; }
        }
    } catch (Throwable $e) {
        error_log('[whatsapp-cmd] playoffs: ' . $e->getMessage());
    }
    return $out;
}

/** Sprint (edição) atual da liga. */
function wcSprintAtual(PDO $pdo, string $league): ?array
{
    try {
        $st = $pdo->prepare("SELECT id, sprint_number FROM sprints WHERE league = ?
                             ORDER BY (status = 'active') DESC, sprint_number DESC LIMIT 1");
        $st->execute([$league]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * O que só existe ENTRE os dois: trocas que fizeram um com o outro.
 *
 * Numa comparação de times isso é o dado mais específico que dá pra dar — e é
 * o que costuma render discussão no grupo.
 */
function wcEntreOsDois(PDO $pdo, int $aId, int $bId): array
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM trades
                             WHERE status = 'accepted'
                               AND ((from_team_id = ? AND to_team_id = ?)
                                 OR (from_team_id = ? AND to_team_id = ?))");
        $st->execute([$aId, $bId, $bId, $aId]);
        return ['trocas' => (int)$st->fetchColumn()];
    } catch (Throwable $e) {
        return ['trocas' => 0];
    }
}

function wcCompararTimes(PDO $pdo, string $termo): string
{
    // Só x/vs/versus aqui. O /comparar de jogador também aceita "e", mas nome
    // de time tem muito mais chance de conter " e " no meio e ser partido no
    // lugar errado.
    $partes = preg_split('/\s+(?:x|vs\.?|versus)\s+/iu', $termo, 2);
    if (count($partes) < 2 || trim($partes[0]) === '' || trim($partes[1]) === '') {
        return "Use assim: /comparartime lakers x celtics";
    }

    [$a, $erroA] = wcResolverTime($pdo, trim($partes[0]));
    if ($erroA) return $erroA;
    [$b, $erroB] = wcResolverTime($pdo, trim($partes[1]));
    if ($erroB) return $erroB;
    if ((int)$a['id'] === (int)$b['id']) return 'Os dois nomes acharam o mesmo time (' . wcNomeDoTime($a) . ').';

    $ovr = wcColunaOvr($pdo);
    $stElenco = $pdo->prepare("SELECT name, {$ovr} AS ovr, age, COALESCE(is_lenda,0) AS is_lenda
                               FROM players WHERE team_id = ? ORDER BY {$ovr} DESC");

    $medir = function (array $t) use ($pdo, $stElenco) {
        $stElenco->execute([(int)$t['id']]);
        $elenco = $stElenco->fetchAll(PDO::FETCH_ASSOC);
        $ovrs = array_map(fn($p) => (int)$p['ovr'], $elenco);
        $idades = array_map(fn($p) => (int)$p['age'], $elenco);

        $m = [
            'elenco'  => count($elenco),
            'melhor'  => $ovrs ? max($ovrs) : 0,
            // Soma dos CAP_TOP_N melhores é o que vale de CAP fora da ELITE — comparar
            // por aí diz mais que a média do elenco inteiro, que afunda com
            // quem tem banco grande.
            'topn'    => array_sum(array_slice($ovrs, 0, CAP_TOP_N)),
            'idade'   => $idades ? round(array_sum($idades) / count($idades), 1) : 0,
            'lendas'  => count(array_filter($elenco, fn($p) => !empty($p['is_lenda']))),
            'nomes'   => array_slice(array_map(fn($p) => $p['name'] . ' (' . $p['ovr'] . ')', $elenco), 0, 3),
            'folha'   => null,
        ];

        if (wcLigaEmSalario($pdo, (string)$t['league'])) {
            $cap = getTeamCapSummary($pdo, (int)$t['id']);
            $m['folha'] = (int)$cap['payroll'];
            $m['espaco'] = (int)$cap['space'];
        }

        $st = $pdo->prepare("SELECT COUNT(*) FROM picks WHERE team_id = ?");
        $st->execute([(int)$t['id']]);
        $m['picks'] = (int)$st->fetchColumn();

        $m += wcTitulosNaEdicao($pdo, (int)$t['id'], (string)$t['league']);

        $sprint = wcSprintAtual($pdo, (string)$t['league']);
        $m['playoffs'] = wcPlayoffsNaEdicao($pdo, (int)$t['id'], $sprint ? (int)$sprint['id'] : null);

        $temp = wcTemporadaAtiva($pdo, (string)$t['league']);
        $m['campanha'] = null;
        if ($temp) {
            $st = $pdo->prepare("SELECT wins, losses FROM season_standings WHERE season_id = ? AND team_id = ?");
            $st->execute([(int)$temp['id'], (int)$t['id']]);
            if ($c = $st->fetch(PDO::FETCH_ASSOC)) $m['campanha'] = $c['wins'] . '-' . $c['losses'];
        }
        return $m;
    };

    $ma = $medir($a);
    $mb = $medir($b);

    $marca = fn($x, $y, bool $maiorGanha = true) => $x === $y ? '' : (($maiorGanha ? $x > $y : $x < $y) ? ' ✅' : '');
    $linha = fn(string $r, $va, $vb, string $sa = '', string $sb = '') => "{$r}: {$va}{$sa}  |  {$vb}{$sb}\n";

    $txt = '*' . wcNomeDoTime($a) . '*' . "\n_vs_\n" . '*' . wcNomeDoTime($b) . "*\n";
    if ($a['league'] !== $b['league']) $txt .= "_{$a['league']} · {$b['league']}_\n";
    $txt .= "\n";

    // Título vem primeiro: é o que decide discussão. O rótulo diz de qual
    // edição, senão "2 títulos" parece ser de sempre — e quando os times são de
    // ligas diferentes, cada um está numa edição com número próprio.
    if ($ma['edicao'] !== null || $mb['edicao'] !== null) {
        $ed = ($ma['edicao'] !== null && $ma['edicao'] === $mb['edicao'])
            ? 'Títulos (edição ' . $ma['edicao'] . ')'
            : 'Títulos na edição';
        $txt .= $linha($ed, $ma['titulos'] . ($ma['edicao'] !== null && $ma['edicao'] !== $mb['edicao'] ? ' (ed. ' . $ma['edicao'] . ')' : ''),
                            $mb['titulos'] . ($mb['edicao'] !== null && $ma['edicao'] !== $mb['edicao'] ? ' (ed. ' . $mb['edicao'] . ')' : ''),
                       $marca($ma['titulos'], $mb['titulos']), $marca($mb['titulos'], $ma['titulos']));
    }

    $txt .= $linha('Melhor jogador', $ma['melhor'], $mb['melhor'], $marca($ma['melhor'], $mb['melhor']), $marca($mb['melhor'], $ma['melhor']))
        . $linha('Soma top ' . CAP_TOP_N, $ma['topn'], $mb['topn'], $marca($ma['topn'], $mb['topn']), $marca($mb['topn'], $ma['topn']))
        . $linha('Elenco', $ma['elenco'], $mb['elenco'])
        // Elenco mais novo é vantagem: compara ao contrário.
        . $linha('Idade média', $ma['idade'], $mb['idade'], $marca($ma['idade'], $mb['idade'], false), $marca($mb['idade'], $ma['idade'], false))
        . $linha('Picks', $ma['picks'], $mb['picks'], $marca($ma['picks'], $mb['picks']), $marca($mb['picks'], $ma['picks']));

    if ($ma['lendas'] || $mb['lendas']) {
        $txt .= $linha('Lendas 👑', $ma['lendas'], $mb['lendas'], $marca($ma['lendas'], $mb['lendas']), $marca($mb['lendas'], $ma['lendas']));
    }

    // Folha só compara quando as DUAS ligas cobram em dinheiro — 44M contra
    // "soma de OVR" não é comparação, é confusão.
    if ($ma['folha'] !== null && $mb['folha'] !== null) {
        // Folha vai sem ✅ de propósito: folha alta não é defeito, é elenco
        // caro. Quem diz quem está melhor de dinheiro é o espaço — e marcar os
        // dois seria a mesma informação repetida ao contrário.
        $txt .= $linha('Folha', $ma['folha'] . 'M', $mb['folha'] . 'M')
              . $linha('Espaço no cap', $ma['espaco'] . 'M', $mb['espaco'] . 'M',
                       $marca($ma['espaco'], $mb['espaco']), $marca($mb['espaco'], $ma['espaco']));
    }

    if ($ma['campanha'] || $mb['campanha']) {
        $txt .= $linha('Campanha', $ma['campanha'] ?: '—', $mb['campanha'] ?: '—');
    }

    // ── Playoffs da edição ───────────────────────────────────────────────
    $pa = $ma['playoffs']; $pb = $mb['playoffs'];
    if ($pa['aparicoes'] || $pb['aparicoes']) {
        $txt .= "\n🏀 *Playoffs na edição*\n"
              . $linha('Séries vencidas', $pa['series'], $pb['series'],
                       $marca($pa['series'], $pb['series']), $marca($pb['series'], $pa['series']))
              . $linha('Melhor campanha', $pa['melhor'] ?: 'não foi', $pb['melhor'] ?: 'não foi')
              . $linha('Vezes no mata-mata', $pa['aparicoes'], $pb['aparicoes'],
                       $marca($pa['aparicoes'], $pb['aparicoes']), $marca($pb['aparicoes'], $pa['aparicoes']));
    }

    // ── O que só existe entre os dois ────────────────────────────────────
    $entre = wcEntreOsDois($pdo, (int)$a['id'], (int)$b['id']);
    if ($entre['trocas'] > 0) {
        $txt .= "\n🔁 Já fizeram *" . $entre['trocas'] . ' troca'
              . ($entre['trocas'] === 1 ? '' : 's') . "* entre si.\n";
    }

    $txt .= "\n*Melhores:*\n"
          . '• ' . implode(', ', $ma['nomes'] ?: ['elenco vazio']) . "\n"
          . '• ' . implode(', ', $mb['nomes'] ?: ['elenco vazio']);

    return $txt;
}

/**
 * O histórico que existe SÓ entre dois times.
 *
 * Não há placar jogo a jogo no banco — o app guarda campanha (vitórias e
 * derrotas da temporada) e resultado de playoff, não o resultado de cada
 * partida. Então "confronto direto" aqui é o que os dois construíram um contra
 * o outro fora de quadra: trocas, quem ficou com pick de quem, e quais
 * jogadores mudaram de lado.
 */
function wcHistoricoEntre(PDO $pdo, array $a, array $b): array
{
    $aId = (int)$a['id']; $bId = (int)$b['id'];
    $out = ['trocas' => [], 'picks_a_com_b' => 0, 'picks_b_com_a' => 0, 'foram' => [], 'vieram' => []];

    try {
        $st = $pdo->prepare("
            SELECT id, from_team_id, updated_at
            FROM trades
            WHERE status = 'accepted'
              AND ((from_team_id = ? AND to_team_id = ?) OR (from_team_id = ? AND to_team_id = ?))
            ORDER BY updated_at DESC, id DESC
        ");
        $st->execute([$aId, $bId, $bId, $aId]);
        $out['trocas'] = $st->fetchAll(PDO::FETCH_ASSOC);

        // Quem mudou de lado. O item guarda o nome copiado na hora da troca,
        // então continua certo mesmo que o jogador já tenha saído dos dois.
        $stItens = $pdo->prepare("SELECT from_team, player_name, player_ovr FROM trade_items
                                  WHERE trade_id = ? AND player_name IS NOT NULL");
        foreach ($out['trocas'] as $t) {
            $stItens->execute([(int)$t['id']]);
            foreach ($stItens->fetchAll(PDO::FETCH_ASSOC) as $i) {
                // from_team=1 é quem PROPÔS a troca. Se o proponente foi o time
                // A, o item saiu de A; senão saiu de B.
                $saiuDeA = ((int)$t['from_team_id'] === $aId) === (bool)$i['from_team'];
                $rot = $i['player_name'] . ($i['player_ovr'] ? ' (' . $i['player_ovr'] . ')' : '');
                if ($saiuDeA) $out['foram'][] = $rot; else $out['vieram'][] = $rot;
            }
        }
    } catch (Throwable $e) {
        error_log('[whatsapp-cmd] confronto trocas: ' . $e->getMessage());
    }

    try {
        // Pick que nasceu de um e hoje está com o outro.
        $st = $pdo->prepare("SELECT COUNT(*) FROM picks WHERE original_team_id = ? AND team_id = ?");
        $st->execute([$aId, $bId]);
        $out['picks_a_com_b'] = (int)$st->fetchColumn();
        $st->execute([$bId, $aId]);
        $out['picks_b_com_a'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('[whatsapp-cmd] confronto picks: ' . $e->getMessage());
    }

    return $out;
}

function wcConfronto(PDO $pdo, string $termo): string
{
    $partes = preg_split('/\s+(?:x|vs\.?|versus)\s+/iu', $termo, 2);
    if (count($partes) < 2 || trim($partes[0]) === '' || trim($partes[1]) === '') {
        return "Use assim: /confronto lakers x celtics";
    }

    [$a, $erroA] = wcResolverTime($pdo, trim($partes[0]));
    if ($erroA) return $erroA;
    [$b, $erroB] = wcResolverTime($pdo, trim($partes[1]));
    if ($erroB) return $erroB;
    if ((int)$a['id'] === (int)$b['id']) return 'Os dois nomes acharam o mesmo time (' . wcNomeDoTime($a) . ').';

    $nomeA = wcNomeDoTime($a); $nomeB = wcNomeDoTime($b);
    $h = wcHistoricoEntre($pdo, $a, $b);

    $txt = "⚔️ *{$nomeA}*\n_contra_\n*{$nomeB}*\n";

    // ── Campanha e playoffs, lado a lado ─────────────────────────────────
    $marca = fn($x, $y) => $x === $y ? '' : ($x > $y ? ' ✅' : '');
    $linha = fn(string $r, $va, $vb, string $sa = '', string $sb = '') => "{$r}: {$va}{$sa}  |  {$vb}{$sb}\n";

    $tA = wcTitulosNaEdicao($pdo, (int)$a['id'], (string)$a['league']);
    $tB = wcTitulosNaEdicao($pdo, (int)$b['id'], (string)$b['league']);
    $spA = wcSprintAtual($pdo, (string)$a['league']);
    $spB = wcSprintAtual($pdo, (string)$b['league']);
    $pA = wcPlayoffsNaEdicao($pdo, (int)$a['id'], $spA ? (int)$spA['id'] : null);
    $pB = wcPlayoffsNaEdicao($pdo, (int)$b['id'], $spB ? (int)$spB['id'] : null);

    $txt .= "\n🏆 *Na edição atual*\n"
          . $linha('Títulos', $tA['titulos'], $tB['titulos'], $marca($tA['titulos'], $tB['titulos']), $marca($tB['titulos'], $tA['titulos']))
          . $linha('Séries de playoff', $pA['series'], $pB['series'], $marca($pA['series'], $pB['series']), $marca($pB['series'], $pA['series']))
          . $linha('Melhor campanha', $pA['melhor'] ?: 'não foi', $pB['melhor'] ?: 'não foi');

    // ── Confronto de verdade: séries de playoff entre os dois ────────────
    $duelo = playoffSeriesEntre($pdo, (int)$a['id'], (int)$b['id']);
    if ($duelo['series']) {
        $txt .= "\n🥊 *Já se enfrentaram no playoff*\n"
              . "*{$duelo['a']} x {$duelo['b']}* em séries\n\n";
        $fases = playoffFases();
        foreach (array_slice($duelo['series'], 0, 6) as $s) {
            $venceu = (int)$s['winner_team_id'] === (int)$a['id'] ? $nomeA : $nomeB;
            $placar = playoffPlacarPorJogos((int)$s['jogos']);
            $txt .= '• T' . (int)$s['season_number'] . ' · ' . ($fases[$s['fase']] ?? $s['fase'])
                  . ': *' . $venceu . '* ' . ($placar ? $placar[0] . '-' . $placar[1] : '') . "\n";
        }
        if (count($duelo['series']) > 6) $txt .= '_+' . (count($duelo['series']) - 6) . " séries antigas_\n";
    }

    // ── O núcleo: o que houve entre os dois ──────────────────────────────
    $txt .= "\n🔁 *Negócios entre eles*\n";
    if (!$h['trocas']) {
        $txt .= "_Nunca trocaram nada._\n";
    } else {
        $n = count($h['trocas']);
        $txt .= $n . ' troca' . ($n === 1 ? '' : 's') . " fechada" . ($n === 1 ? '' : 's');
        if (!empty($h['trocas'][0]['updated_at'])) {
            $txt .= ' · última em ' . date('d/m/Y', strtotime((string)$h['trocas'][0]['updated_at']));
        }
        $txt .= "\n";

        // Quem mandou quem. Corta em 5 porque no grupo ninguém lê 20 nomes.
        $lista = function (array $nomes): string {
            $mostra = array_slice($nomes, 0, 5);
            $resto = count($nomes) - count($mostra);
            return implode(', ', $mostra) . ($resto > 0 ? " _+{$resto}_" : '');
        };
        if ($h['foram'])  $txt .= "→ {$nomeA} mandou: " . $lista($h['foram']) . "\n";
        if ($h['vieram']) $txt .= "← {$nomeB} mandou: " . $lista($h['vieram']) . "\n";
    }

    if ($h['picks_a_com_b'] || $h['picks_b_com_a']) {
        $txt .= "\n🎯 *Picks na mão do outro*\n";
        if ($h['picks_a_com_b']) $txt .= "{$nomeB} tem *{$h['picks_a_com_b']}* pick(s) do {$nomeA}\n";
        if ($h['picks_b_com_a']) $txt .= "{$nomeA} tem *{$h['picks_b_com_a']}* pick(s) do {$nomeB}\n";
    }

    $txt .= "\n_" . ($duelo['series']
            ? 'O placar de cada jogo o app não guarda — só o total da série.'
            : 'Ainda não se cruzaram no playoff, ou a série não foi registrada.') . "_"
          . "\nElenco lado a lado: /comparartime " . mb_strtolower($a['name']) . ' x ' . mb_strtolower($b['name']);

    return $txt;
}

function wcLendas(PDO $pdo, string $termo, ?string $ligaDoGrupo): string
{
    $liga = $termo !== '' ? wcNormalizarLiga($termo) : $ligaDoGrupo;
    $ovr = wcColunaOvr($pdo);
    $filtro = $liga ? ' AND t.league = ?' : '';

    $st = $pdo->prepare("
        SELECT p.name, p.age, p.position, p.{$ovr} AS ovr, t.city, t.name AS team_name, t.league
        FROM players p JOIN teams t ON t.id = p.team_id
        WHERE COALESCE(p.is_lenda,0) = 1 {$filtro}
        ORDER BY p.{$ovr} DESC LIMIT 25
    ");
    $st->execute($liga ? [$liga] : []);
    $lendas = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$lendas) return 'Nenhuma LENDA marcada' . ($liga ? " na {$liga}" : '') . ' ainda.';

    $txt = '👑 *Lendas' . ($liga ? " — {$liga}" : '') . '* (' . count($lendas) . ")\n\n";
    foreach ($lendas as $l) {
        $txt .= "• *{$l['name']}* — {$l['ovr']} OVR, {$l['age']} anos — " . wcNomeDoTime($l)
              . ($liga ? '' : ' _' . $l['league'] . '_') . "\n";
    }
    return rtrim($txt);
}

function wcHall(PDO $pdo, string $termo, ?string $ligaDoGrupo): string
{
    $liga = $termo !== '' ? wcNormalizarLiga($termo) : $ligaDoGrupo;
    $filtro = $liga ? ' AND league = ?' : '';

    $st = $pdo->prepare("
        SELECT team_name, gm_name, titles, league FROM hall_of_fame
        WHERE is_active = 1 {$filtro}
        ORDER BY titles DESC, team_name ASC LIMIT 25
    ");
    $st->execute($liga ? [$liga] : []);
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$linhas) return 'O Hall da Fama' . ($liga ? " da {$liga}" : '') . ' está vazio.';

    $txt = '🏛️ *Hall da Fama' . ($liga ? " — {$liga}" : '') . "*\n\n";
    foreach ($linhas as $i => $l) {
        $txt .= ($i + 1) . '. *' . $l['team_name'] . '*'
              . ($l['gm_name'] ? ' — ' . $l['gm_name'] : '')
              . ' — ' . (int)$l['titles'] . ' título' . ((int)$l['titles'] === 1 ? '' : 's')
              . ($liga ? '' : ' _' . $l['league'] . '_') . "\n";
    }
    return rtrim($txt);
}

function wcPremios(PDO $pdo, string $termo, ?string $ligaDoGrupo): string
{
    // O termo pode ser a liga ou um ano ("/premios 2027").
    $ano = preg_match('/^\d{4}$/', trim($termo)) ? (int)trim($termo) : null;
    $liga = $ano ? $ligaDoGrupo : ($termo !== '' ? wcNormalizarLiga($termo) : $ligaDoGrupo);
    if (!$liga) $liga = 'ELITE';

    if (!$ano) {
        $st = $pdo->prepare("SELECT MAX(season_year) FROM awards WHERE league = ?");
        $st->execute([$liga]);
        $ano = (int)($st->fetchColumn() ?: 0);
    }
    if (!$ano) return "A {$liga} ainda não tem prêmios cadastrados.";

    $st = $pdo->prepare("
        SELECT a.award_name, a.player_name, a.points, t.city, t.name AS team_name
        FROM awards a LEFT JOIN teams t ON t.id = a.team_id
        WHERE a.league = ? AND a.season_year = ?
        ORDER BY a.id
    ");
    $st->execute([$liga, $ano]);
    $premios = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$premios) return "Nada premiado na {$liga} em {$ano}.";

    $txt = "🏆 *Prêmios {$liga} — {$ano}*\n\n";
    foreach ($premios as $p) {
        $quem = $p['player_name'] ?: ($p['team_name'] ? wcNomeDoTime($p) : '—');
        $txt .= '*' . $p['award_name'] . ':* ' . $quem
              . ($p['player_name'] && $p['team_name'] ? ' (' . wcNomeDoTime($p) . ')' : '') . "\n";
    }
    return rtrim($txt);
}

// ─────────────────────────────────────────────────────────────────────────
// Comandos que sabem quem perguntou
// ─────────────────────────────────────────────────────────────────────────

/**
 * Acha o time de quem mandou a mensagem, pelo telefone.
 *
 * O users.phone é gravado normalizado (normalizeBrazilianPhone), e o
 * identificador que a Evolution manda é do mesmo formato — 5511999999999. Mas
 * comparar inteiro é frágil: o WhatsApp de números antigos às vezes vem sem o
 * 9 do celular, e alguns cadastros estão sem o 55. Então comparo pelos últimos
 * 8 dígitos, que é a parte que nunca muda.
 *
 * Se 8 dígitos baterem com mais de uma pessoa, não adivinha — devolve erro.
 * Retorna [time|null, mensagemDeErro|null].
 */
function wcTimeDeQuemPerguntou(PDO $pdo, ?string $telefone, ?string $ligaDoGrupo): array
{
    $digitos = preg_replace('/\D+/', '', (string)$telefone);
    if (strlen($digitos) < 8) {
        return [null, 'Não consegui identificar seu número por aqui. Use o comando com o nome do time, tipo /cap lakers.'];
    }
    $fim = substr($digitos, -8);

    // A comparação é em PHP, não em SQL: REGEXP_REPLACE só existe do MySQL 8
    // pra cima e eu não controlo a versão da hospedagem. São poucas dezenas de
    // linhas (uma por GM com time), então filtrar aqui não custa nada.
    $todos = $pdo->query("
        SELECT t.id, t.name, t.city, t.mascot, t.league, t.conference,
               u.name AS gm, u.id AS user_id, u.phone
        FROM users u JOIN teams t ON t.user_id = u.id
        WHERE u.phone IS NOT NULL AND u.phone <> ''
        ORDER BY FIELD(t.league,'ELITE','NEXT','RISE','ROOKIE')
    ")->fetchAll(PDO::FETCH_ASSOC);

    $times = array_values(array_filter($todos, function ($t) use ($fim) {
        $d = preg_replace('/\D+/', '', (string)$t['phone']);
        return strlen($d) >= 8 && substr($d, -8) === $fim;
    }));

    if (!$times) {
        return [null, "Não achei seu cadastro pelo telefone. Confere se o número está no seu perfil no site — ou use o comando com o nome do time."];
    }

    // Um GM pode ter time em mais de uma liga. No Chat Off de uma liga, é o
    // dela que interessa.
    if (count($times) > 1 && $ligaDoGrupo) {
        foreach ($times as $t) if ($t['league'] === $ligaDoGrupo) return [$t, null];
    }
    if (count($times) > 1) {
        $lista = implode(', ', array_map(fn($t) => $t['league'], $times));
        return [null, "Você tem time em mais de uma liga ({$lista}). Use o comando com o nome do time."];
    }
    return [$times[0], null];
}

function wcMeuElenco(PDO $pdo, ?string $telefone, ?string $ligaDoGrupo): string
{
    [$t, $erro] = wcTimeDeQuemPerguntou($pdo, $telefone, $ligaDoGrupo);
    return $erro ?: wcTime($pdo, '', $t);
}

function wcMeuCap(PDO $pdo, ?string $telefone, ?string $ligaDoGrupo): string
{
    [$t, $erro] = wcTimeDeQuemPerguntou($pdo, $telefone, $ligaDoGrupo);
    return $erro ?: wcCap($pdo, '', $t);
}

function wcMinhasPicks(PDO $pdo, ?string $telefone, ?string $ligaDoGrupo): string
{
    [$t, $erro] = wcTimeDeQuemPerguntou($pdo, $telefone, $ligaDoGrupo);
    return $erro ?: wcPicks($pdo, '', $t);
}

// ─────────────────────────────────────────────────────────────────────────

/**
 * Roteia o texto pro comando. Retorna null quando não é comando conhecido —
 * o webhook trata null como "não responder".
 *
 * $ligaDoGrupo é a liga do grupo de onde veio a mensagem, quando ele é de uma
 * liga só. Serve pros comandos que dá pra responder sem argumento.
 */
function wcResponderComando(PDO $pdo, string $texto, ?string $ligaDoGrupo = null, ?string $telefone = null): ?string
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

            case 'confronto':
            case 'duelo':
                return wcConfronto($pdo, $arg);

            case 'comparartime':
            case 'comparartimes':
            case 'compararelenco':
                return wcCompararTimes($pdo, $arg);

            case 'comparar':
            case 'compara':
            case 'vs':
                return wcComparar($pdo, $arg);

            case 'trocas':
            case 'troca':
                return wcTrocas($pdo, $arg, $ligaDoGrupo);

            case 'lendas':
            case 'lenda':
                return wcLendas($pdo, $arg, $ligaDoGrupo);

            case 'hall':
            case 'halldafama':
                return wcHall($pdo, $arg, $ligaDoGrupo);

            case 'premios':
            case 'prêmios':
            case 'premio':
                return wcPremios($pdo, $arg, $ligaDoGrupo);

            // ── Quem perguntou ──────────────────────────────────────────
            case 'meuelenco':
            case 'meutime':
                return wcMeuElenco($pdo, $telefone, $ligaDoGrupo);

            case 'meucap':
            case 'minhafolha':
                return wcMeuCap($pdo, $telefone, $ligaDoGrupo);

            case 'minhaspicks':
            case 'meuspicks':
                return wcMinhasPicks($pdo, $telefone, $ligaDoGrupo);

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
