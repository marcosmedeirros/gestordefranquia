<?php
/**
 * O LEILÃO QUE ACONTECE INTEIRO NO WHATSAPP.
 *
 * Antes o leilão era feito à mão pelos admins no Gameplay: anunciavam, iam
 * repassando cada proposta, contavam o relógio e depois alguém registrava a
 * troca no app. Era o maior gargalo da liga. Aqui o bot faz o papel do admin:
 *
 *   1. O dono manda no PRIVADO do bot: /leilao Nome do Jogador
 *      → confere slot de leilão e requisito (85+) e anuncia no Gameplay.
 *   2. Quem quer o jogador manda no privado: /leilao Jogador + Jogador + Pick 2026 R1
 *      → o bot posta no Gameplay, UMA proposta por vez.
 *   3. O dono responde /aceitar ou /recusar no grupo (ou /leilao aceitar no privado).
 *      Aceitar não fecha: a proposta vira a melhor até agora e o leilão segue.
 *      A próxima só é postada depois da resposta — igual os admins faziam.
 *   4. Fecha em 20 min, ou 5 min sem nada novo. A última aceita leva: a troca
 *      é feita no app e o slot é consumido (com ou sem troca).
 *
 * Um leilão por liga de cada vez: o /aceitar no grupo não diz de qual leilão
 * é, e dois ao mesmo tempo no mesmo Gameplay seria a confusão que isto veio
 * resolver.
 *
 * Não inclui api/leilao.php porque aquele arquivo exige sessão e imprime JSON.
 * A transferência abaixo é a mesma de _executarTrocaLeilao, só pra este caso
 * (sem jogador avulso e sem itens extras do vendedor).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/leilao_bot.php';     // botGrupoDaCerimonia, LEILAO_BOT_TIPO
require_once __DIR__ . '/salary_cap.php';
require_once __DIR__ . '/picks_usadas.php';

/** Requisito pra ir a leilão. Por enquanto é só esse. */
const LW_OVR_MINIMO = 85;
/** Duração máxima do leilão. */
const LW_DURACAO_MIN = 20;
/** Fecha antes se ficar esse tempo sem proposta nova nem decisão. */
const LW_OCIOSO_MIN = 5;

const LW_IDS_LIGA = ['ELITE' => 1, 'NEXT' => 2, 'RISE' => 3, 'ROOKIE' => 4];

function lwGarantirTabelas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS leilao_whats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            leilao_id INT NOT NULL,
            liga VARCHAR(10) NOT NULL,
            grupo_jid VARCHAR(80) NOT NULL,
            vendedor_team_id INT NOT NULL,
            vendedor_user_id INT NOT NULL,
            status ENUM('aberto','encerrando','encerrado') NOT NULL DEFAULT 'aberto',
            inicio DATETIME NOT NULL,
            fim_max DATETIME NOT NULL,
            ultima_atividade DATETIME NOT NULL,
            encerrado_em DATETIME NULL,
            resultado VARCHAR(20) NULL,
            UNIQUE KEY uk_leilao (leilao_id),
            KEY idx_status (status, liga)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS leilao_whats_propostas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lw_id INT NOT NULL,
            proposta_id INT NOT NULL,
            team_id INT NOT NULL,
            autor_jid VARCHAR(80) NULL,
            status ENUM('aguardando','na_vez','aceita','superada','recusada','substituida','descartada') NOT NULL DEFAULT 'aguardando',
            postada_em DATETIME NULL,
            decidida_em DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_proposta (proposta_id),
            KEY idx_lw (lw_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('[leilao_whats] tabelas: ' . $e->getMessage());
    }
}

/* ─── quem é quem ─────────────────────────────────────────────────────────── */

/**
 * O telefone de quem escreveu no privado.
 *
 * Na conversa particular o remoteJid É a pessoa. Quando ele vem como LID, o
 * telefone de verdade às vezes vem num campo paralelo — o nome muda entre
 * versões da Evolution, então tento os conhecidos.
 */
function lwJidDaConversaPrivada(array $m, string $de): string
{
    foreach ([$m['key']['remoteJidAlt'] ?? null, $m['key']['senderPn'] ?? null,
              $m['key']['participantPn'] ?? null, $m['senderPn'] ?? null, $de] as $c) {
        $c = trim((string)$c);
        if ($c !== '' && (str_contains($c, '@s.whatsapp.net') || !str_contains($c, '@'))) return $c;
    }
    return $de;
}

/** Os times (de todas as ligas) do dono deste número. */
function lwTimesDoNumero(PDO $pdo, string $jid): array
{
    if (str_contains($jid, '@lid')) return [];
    $digitos = preg_replace('/\D+/', '', explode('@', $jid)[0] ?? '');
    if (strlen($digitos) < 8) return [];

    $todos = $pdo->query("SELECT t.id, t.name, t.city, t.league, u.id AS user_id, u.name AS gm, u.phone
                            FROM users u JOIN teams t ON t.user_id = u.id
                           WHERE u.phone IS NOT NULL AND u.phone <> ''")->fetchAll(PDO::FETCH_ASSOC);
    $so = fn($t) => preg_replace('/\D+/', '', (string)$t['phone']);

    // Número inteiro primeiro; os últimos 8 só como rede (mesma regra do bot).
    $achados = array_values(array_filter($todos, fn($t) => $so($t) === $digitos));
    if (!$achados) {
        $fim = substr($digitos, -8);
        $achados = array_values(array_filter($todos, fn($t) => strlen($so($t)) >= 8 && substr($so($t), -8) === $fim));
        // Dois donos diferentes com o mesmo final: não adivinha.
        if (count(array_unique(array_column($achados, 'user_id'))) > 1) return [];
    }
    return $achados;
}

function lwNaoTeAchei(string $jid): string
{
    if (str_contains($jid, '@lid')) {
        return "O WhatsApp não me passou seu número, então não sei qual é o seu time. "
             . "Tenta de novo daqui a pouco — se continuar, fala com um admin.";
    }
    $d = preg_replace('/\D+/', '', explode('@', $jid)[0] ?? '');
    return "Não achei seu cadastro por este número (terminado em " . substr($d, -4) . "). "
         . "Confere o telefone no seu perfil do site.";
}

/* ─── texto ───────────────────────────────────────────────────────────────── */

function lwLinhaJogador(array $p): string
{
    return trim(($p['position'] ?: '?') . ': ' . $p['name'] . ' ' . (int)$p['ovr'] . '/' . (int)$p['age'] . 'y');
}

function lwLinhaPick(array $pk, int $donoTeamId): string
{
    $txt = 'Pick ' . $pk['season_year'] . ' R' . $pk['round'];
    if ((int)$pk['original_team_id'] !== $donoTeamId && !empty($pk['origem'])) {
        $txt .= ' (' . $pk['origem'] . ')';
    }
    return $txt;
}

/** "Lakers envia:" + os itens, no formato do Gameplay. */
function lwBlocoDaProposta(PDO $pdo, int $propostaId): string
{
    $st = $pdo->prepare("SELECT lp.team_id, t.name FROM leilao_propostas lp JOIN teams t ON t.id = lp.team_id WHERE lp.id = ?");
    $st->execute([$propostaId]);
    $cab = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cab) return '';

    $linhas = [];
    $st = $pdo->prepare("SELECT p.name, p.position, p.ovr, p.age FROM leilao_proposta_jogadores x
                           JOIN players p ON p.id = x.player_id WHERE x.proposta_id = ? ORDER BY x.id");
    $st->execute([$propostaId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $linhas[] = '* ' . lwLinhaJogador($p);

    $st = $pdo->prepare("SELECT pk.season_year, pk.round, pk.original_team_id, o.name AS origem
                           FROM leilao_proposta_picks x JOIN picks pk ON pk.id = x.pick_id
                      LEFT JOIN teams o ON o.id = pk.original_team_id
                          WHERE x.proposta_id = ? ORDER BY x.id");
    $st->execute([$propostaId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $pk) $linhas[] = '* ' . lwLinhaPick($pk, (int)$cab['team_id']);

    return $cab['name'] . " envia:\n\n" . implode("\n", $linhas);
}

function lwAjuda(): string
{
    return "🔨 *Leilão pelo WhatsApp*\n\n"
         . "*Abrir leilão* de um jogador seu (" . LW_OVR_MINIMO . "+ e com slot de leilão):\n"
         . "/leilao Nome do Jogador\n\n"
         . "*Mandar proposta* no leilão aberto da sua liga:\n"
         . "/oferta Seu jogador + Pick 2026 R1\n\n"
         . "*Decidir* (se o leilão é seu): ✅ ou ❌ no Gameplay (mandando o emoji ou reagindo à proposta) — também valem /aceitar e /recusar, ou ✅ / ❌ aqui no privado.\n\n"
         . "O leilão fecha em " . LW_DURACAO_MIN . " min, ou " . LW_OCIOSO_MIN . " min sem proposta nova. A última proposta aceita leva.";
}

function lwMinutosRestantes(array $lw): int
{
    return max(0, (int)ceil((strtotime($lw['fim_max']) - time()) / 60));
}

/**
 * Link que abre o privado do bot já com "/oferta " digitado.
 * O número vem de whatsapp_config.numero_bot, que o webhook aprende sozinho.
 * Sem número conhecido, null — a mensagem sai sem o link.
 */
function lwLinkDoBot(PDO $pdo, string $texto = '/oferta '): ?string
{
    try {
        $n = preg_replace('/\D+/', '', (string)$pdo->query("SELECT numero_bot FROM whatsapp_config WHERE id = 1")->fetchColumn());
    } catch (Throwable $e) {
        return null;
    }
    return strlen($n) >= 10 ? 'https://wa.me/' . $n . '?text=' . rawurlencode($texto) : null;
}

/* ─── consultas ───────────────────────────────────────────────────────────── */

function lwLeilaoAbertoDaLiga(PDO $pdo, string $liga): ?array
{
    $st = $pdo->prepare("SELECT w.*, l.player_id, p.name AS jogador, p.position, p.ovr, p.age,
                                t.name AS vendedor_nome
                           FROM leilao_whats w
                           JOIN leilao_jogadores l ON l.id = w.leilao_id
                      LEFT JOIN players p ON p.id = l.player_id
                           JOIN teams t ON t.id = w.vendedor_team_id
                          WHERE w.status = 'aberto' AND w.liga = ? LIMIT 1");
    $st->execute([$liga]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Nome digitado casa com o jogador? Exato (sem acento/caixa) ou contido. */
function lwNomeCasa(string $digitado, string $nome): bool
{
    $n = fn($s) => mb_strtolower(trim(preg_replace('/\s+/', ' ',
        iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s)));
    $a = $n($digitado);
    $b = $n($nome);
    return $a !== '' && ($a === $b || (mb_strlen($a) >= 4 && str_contains($b, $a)));
}

/**
 * Acha UM jogador do time pelo nome. Aceita "PG: Fulano 89/21y" (o formato
 * que o próprio bot posta), porque é o que as pessoas vão copiar e colar.
 * Devolve [jogador|null, erro|null].
 */
function lwAcharJogadorDoTime(PDO $pdo, int $teamId, string $texto): array
{
    $limpo = trim(preg_replace('/^[A-Z]{1,2}\s*:\s*/i', '', trim($texto)));
    $limpo = trim(preg_replace('/\s+\d{2}\s*\/\s*\d{2}\s*y?$/i', '', $limpo));
    if ($limpo === '') return [null, 'nome vazio'];

    $st = $pdo->prepare("SELECT id, name, position, ovr, age, team_id FROM players WHERE team_id = ?");
    $st->execute([$teamId]);
    $elenco = $st->fetchAll(PDO::FETCH_ASSOC);

    $exatos = array_values(array_filter($elenco, fn($p) => lwNomeCasa($limpo, $p['name'])
        && mb_strtolower(trim($limpo)) === mb_strtolower(trim($p['name']))));
    if (count($exatos) === 1) return [$exatos[0], null];

    $parecidos = array_values(array_filter($elenco, fn($p) => lwNomeCasa($limpo, $p['name'])));
    if (count($parecidos) === 1) return [$parecidos[0], null];
    if (count($parecidos) > 1) {
        return [null, "\"{$limpo}\" bate com mais de um: " . implode(', ', array_column($parecidos, 'name')) . '. Escreve o nome completo.'];
    }
    return [null, "não achei \"{$limpo}\" no seu elenco"];
}

/** Ano da temporada em andamento — pick de ano anterior não vale. */
function lwAnoAtual(PDO $pdo, string $liga): ?int
{
    try {
        $st = $pdo->prepare("SELECT year FROM seasons WHERE league = ? AND status != 'completed' ORDER BY id DESC LIMIT 1");
        $st->execute([$liga]);
        $a = $st->fetchColumn();
        return $a ? (int)$a : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * É pick? "Pick 2026 R1", "2026 R1", "2026 1ª", "1a 2026", "R2 2027 Lakers".
 * Devolve [ano, rodada, resto] ou null quando não parece pick.
 */
function lwLerPick(string $texto): ?array
{
    $t = trim($texto);
    if (!preg_match('/\b(20\d{2})\b/', $t, $mAno)) return null;
    $rodada = null;
    if (preg_match('/\b(?:r|rd|round)\s*([12])\b/i', $t, $mm)
        || preg_match('/\b([12])\s*(?:ª|º|a|o|st|nd)?\s*(?:rodada|round|r)\b/iu', $t, $mm)
        || preg_match('/\b([12])\s*(?:ª|º)/u', $t, $mm)
        || preg_match('/\b([12])(?:a|o|st|nd)\b/i', $t, $mm)) {
        $rodada = (int)$mm[1];
    }
    if (!$rodada) return null;

    $resto = str_replace([$mAno[0], $mm[0]], ' ', $t);
    $resto = preg_replace('/\b(pick|picks|escolha|de|da|do|via)\b|[()*•\-]/iu', ' ', $resto);
    return [(int)$mAno[1], $rodada, trim(preg_replace('/\s+/', ' ', $resto))];
}

/** Acha a pick do time. Devolve [pick|null, erro|null]. */
function lwAcharPickDoTime(PDO $pdo, int $teamId, string $liga, int $ano, int $rodada, string $origem): array
{
    $st = $pdo->prepare("SELECT pk.id, pk.season_year, pk.round, pk.original_team_id, pk.team_id,
                                o.name AS origem, o.city AS origem_cidade
                           FROM picks pk LEFT JOIN teams o ON o.id = pk.original_team_id
                          WHERE pk.team_id = ? AND CAST(pk.season_year AS UNSIGNED) = ? AND pk.round = ?");
    $st->execute([$teamId, $ano, (string)$rodada]);
    $usadas = picksJaUsadas($pdo);
    $lista = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($p) => empty($usadas[(int)$p['id']])));

    $atual = lwAnoAtual($pdo, $liga);
    if ($atual && $ano < $atual) return [null, "a pick {$ano} R{$rodada} é de ano que já passou"];

    if ($origem !== '') {
        $lista = array_values(array_filter($lista, fn($p) =>
            lwNomeCasa($origem, (string)$p['origem']) || lwNomeCasa($origem, trim($p['origem_cidade'] . ' ' . $p['origem']))));
    }
    if (count($lista) === 1) return [$lista[0], null];
    if (!$lista) return [null, "você não tem a pick {$ano} R{$rodada}" . ($origem !== '' ? " ({$origem})" : '')];

    // Mais de uma do mesmo ano e rodada: a sua primeiro, se estiver entre elas.
    foreach ($lista as $p) if ((int)$p['original_team_id'] === $teamId && $origem === '') return [$p, null];
    $ops = array_map(fn($p) => "Pick {$ano} R{$rodada} {$p['origem']}", $lista);
    return [null, "você tem mais de uma {$ano} R{$rodada}. Diz qual: " . implode(' / ', $ops)];
}

/* ─── privado: /leilao ────────────────────────────────────────────────────── */

/**
 * O único comando que o bot atende no privado.
 * Devolve o texto de resposta pra quem escreveu.
 */
function lwComandoPrivado(PDO $pdo, string $texto, string $jid): string
{
    lwGarantirTabelas($pdo);

    /* /oferta Jogador + Pick 2026 R1 — proposta sem repetir o leiloado.
       Há um leilão aberto por liga, então a liga da pessoa já diz qual é. Se
       ela escrever o nome do leiloado mesmo assim, ele sai da lista. */
    if (preg_match('~^/oferta(\s|$)~iu', trim($texto))) {
        $times = lwTimesDoNumero($pdo, $jid);
        if (!$times) return lwNaoTeAchei($jid);
        $resto = trim(preg_replace('~^/oferta~iu', '', trim($texto)));
        $partes = array_values(array_filter(array_map(fn($p) => trim(ltrim(trim($p), "*•- \t")),
                  preg_split('/\s*[+\n,;]\s*/u', $resto)), 'strlen'));
        foreach ($times as $t) {
            $lw = lwLeilaoAbertoDaLiga($pdo, $t['league']);
            if (!$lw) continue;
            if ((int)$t['id'] === (int)$lw['vendedor_team_id']) {
                return "Esse leilão é seu. Responda as propostas com ✅ ou ❌ no Gameplay.";
            }
            if ($partes && lwNomeCasa(preg_replace('/^[A-Z]{1,2}\s*:\s*|\s+\d{2}\s*\/\s*\d{2}\s*y?$/i', '', $partes[0]), (string)$lw['jogador'])) {
                array_shift($partes);
            }
            return lwReceberProposta($pdo, $lw, $t, $partes, $jid);
        }
        return "Não tem leilão aberto na sua liga agora. Quando abrir, o bot anuncia no Gameplay.";
    }

    $arg = trim(preg_replace('~^/leil[aã]o\b~iu', '', trim($texto)));
    if ($arg === '' || in_array(mb_strtolower($arg), ['ajuda', 'help', '?'], true)) return lwAjuda();

    $times = lwTimesDoNumero($pdo, $jid);
    if (!$times) return lwNaoTeAchei($jid);

    $palavra = mb_strtolower($arg);
    if (in_array($palavra, ['aceitar', 'aceito', 'recusar', 'recuso'], true)) {
        $cmd = str_starts_with($palavra, 'aceit') ? 'aceitar' : 'recusar';
        return lwDecidir($pdo, $cmd, $times, true);
    }

    // "Jogador + item + item". Quebra de linha e vírgula também separam,
    // porque é assim que o pessoal escreve lista no celular.
    $partes = array_values(array_filter(array_map('trim', preg_split('/\s*[+\n,;]\s*/u', $arg)), 'strlen'));
    $partes = array_map(fn($p) => trim(ltrim($p, "*•- \t")), $partes);
    $alvo = array_shift($partes);

    // Proposta num leilão aberto de alguma liga da pessoa?
    foreach ($times as $t) {
        $lw = lwLeilaoAbertoDaLiga($pdo, $t['league']);
        if ($lw && lwNomeCasa(preg_replace('/^[A-Z]{1,2}\s*:\s*|\s+\d{2}\s*\/\s*\d{2}\s*y?$/i', '', $alvo), (string)$lw['jogador'])) {
            if ((int)$t['id'] === (int)$lw['vendedor_team_id']) {
                return "Esse leilão é seu. Pra decidir a proposta da vez: /aceitar ou /recusar no Gameplay, ou /leilao aceitar aqui.";
            }
            return lwReceberProposta($pdo, $lw, $t, $partes, $jid);
        }
    }

    if ($partes) {
        return "Não achei leilão aberto de \"{$alvo}\" na sua liga. Pra mandar proposta, o nome antes do + tem que ser o do jogador leiloado.";
    }
    return lwAbrirLeilao($pdo, $times, $alvo);
}

function lwAbrirLeilao(PDO $pdo, array $times, string $nome): string
{
    // O jogador tem que ser de um dos times da pessoa.
    $achado = null; $erros = [];
    foreach ($times as $t) {
        [$p, $erro] = lwAcharJogadorDoTime($pdo, (int)$t['id'], $nome);
        if ($p) { $achado = [$p, $t]; break; }
        $erros[] = $erro;
    }
    if (!$achado) {
        return "Não achei leilão aberto nem jogador seu com esse nome: " . ($erros[0] ?? $nome) . ".\n\nManda /leilao pra ver como funciona.";
    }
    [$p, $t] = $achado;
    $liga = (string)$t['league'];

    if ((int)$p['ovr'] < LW_OVR_MINIMO) {
        return "❌ {$p['name']} tem OVR {$p['ovr']}. Só vai a leilão jogador " . LW_OVR_MINIMO . "+.";
    }

    $aberto = lwLeilaoAbertoDaLiga($pdo, $liga);
    if ($aberto) {
        return "⏳ Já tem leilão rolando na {$liga} ({$aberto['jogador']}, do {$aberto['vendedor_nome']}). "
             . "Fecha em até " . lwMinutosRestantes($aberto) . " min — tenta de novo depois.";
    }

    $st = $pdo->prepare("SELECT 1 FROM leilao_jogadores WHERE player_id = ? AND status = 'ativo' LIMIT 1");
    $st->execute([(int)$p['id']]);
    if ($st->fetchColumn()) return "❌ {$p['name']} já está num leilão ativo no app.";

    $grupo = botGrupoDaCerimonia($pdo, $liga);
    if (!$grupo) return "Não achei o grupo Gameplay da {$liga} cadastrado no bot. Fala com um admin.";

    require_once __DIR__ . '/loja.php';
    lojaGarantirTabela($pdo);

    $pdo->beginTransaction();
    try {
        // Trava os slots do dono: dois /leilao ao mesmo tempo não gastam o mesmo.
        $st = $pdo->prepare("SELECT id FROM loja_inventario
                              WHERE id_usuario = ? AND item_key = 'slot_leilao' AND atendido_em IS NULL FOR UPDATE");
        $st->execute([(int)$t['user_id']]);
        $slots = count($st->fetchAll(PDO::FETCH_COLUMN));
        if ($slots < 1) {
            $pdo->rollBack();
            return "❌ Você não tem slot de leilão. Compra na loja do site e tenta de novo.";
        }
        // Recheca com a trava: outro leilão pode ter aberto nesse meio-tempo.
        $st = $pdo->prepare("SELECT id FROM leilao_whats WHERE status IN ('aberto','encerrando') AND liga = ? FOR UPDATE");
        $st->execute([$liga]);
        if ($st->fetchColumn()) {
            $pdo->rollBack();
            return "⏳ Acabou de abrir outro leilão na {$liga}. Tenta de novo quando ele fechar.";
        }

        $pdo->prepare("INSERT INTO leilao_jogadores (player_id, team_id, league_id, data_inicio, data_fim, status)
                       VALUES (?, ?, ?, NOW(), NOW() + INTERVAL " . LW_DURACAO_MIN . " MINUTE, 'ativo')")
            ->execute([(int)$p['id'], (int)$t['id'], LW_IDS_LIGA[$liga] ?? 0]);
        $leilaoId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO leilao_whats
                         (leilao_id, liga, grupo_jid, vendedor_team_id, vendedor_user_id, inicio, fim_max, ultima_atividade)
                       VALUES (?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL " . LW_DURACAO_MIN . " MINUTE, NOW())")
            ->execute([$leilaoId, $liga, $grupo, (int)$t['id'], (int)$t['user_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[leilao_whats] abrir: ' . $e->getMessage());
        return "Deu erro ao abrir o leilão. Tenta de novo em instantes.";
    }

    $anuncio = "🔨 *LEILÃO ABERTO*\n\n"
             . "{$t['name']} leiloa:\n\n"
             . "* " . lwLinhaJogador($p) . "\n\n"
             . "Mande sua proposta no *privado do bot*:\n"
             . "/oferta Jogador + Pick 2026 R1\n"
             . (($link = lwLinkDoBot($pdo)) ? "👉 Chamar o bot: {$link}\n" : '')
             . "\n"
             . "⏱ Fecha em " . LW_DURACAO_MIN . " min, ou " . LW_OCIOSO_MIN . " min sem proposta nova.";
    whatsappEnfileirar($pdo, $grupo, $anuncio, true, LEILAO_BOT_TIPO);

    return "✅ Leilão de *{$p['name']}* aberto e anunciado no Gameplay da {$liga}.\n\n"
         . "As propostas vão aparecer lá, uma por vez. Responda cada uma com ✅ ou ❌ no grupo "
         . "(ou aqui no privado). O slot é consumido quando o leilão fechar.";
}

function lwReceberProposta(PDO $pdo, array $lw, array $time, array $itens, string $jid): string
{
    if (strtotime($lw['fim_max']) <= time()) return "⏱ O leilão de {$lw['jogador']} já fechou.";
    if (!$itens) {
        return "Faltou o que você oferece. Exemplo:\n/oferta Jogador + Pick 2026 R1";
    }

    $teamId = (int)$time['id'];
    $jogadores = []; $picks = []; $erros = [];
    foreach ($itens as $item) {
        $pick = lwLerPick($item);
        if ($pick) {
            [$pk, $erro] = lwAcharPickDoTime($pdo, $teamId, $lw['liga'], $pick[0], $pick[1], $pick[2]);
            if ($pk) $picks[(int)$pk['id']] = $pk; else $erros[] = $erro;
        } else {
            [$pl, $erro] = lwAcharJogadorDoTime($pdo, $teamId, $item);
            if ($pl) $jogadores[(int)$pl['id']] = $pl; else $erros[] = $erro;
        }
    }
    if ($erros) return "❌ Proposta não enviada:\n• " . implode("\n• ", $erros);

    // Cap: mesma trava do leilão no app — só o teto, só onde a liga usa salário.
    $liga = (string)$lw['liga'];
    try {
        if (capLigaUsaSalario($pdo, $liga)) {
            $doVendedor = capSalariosDoTime($pdo, (int)$lw['vendedor_team_id'], $liga);
            $meus = capSalariosDoTime($pdo, $teamId, $liga);
            $recebe = (int)($doVendedor[(int)$lw['player_id']] ?? 0);
            $envia = 0;
            foreach (array_keys($jogadores) as $pid) $envia += (int)($meus[$pid] ?? 0);
            $espaco = (int)(getTeamCapSummary($pdo, $teamId)['space'] ?? 0);
            $delta = $recebe - $envia;
            if ($delta > max(0, $espaco)) {
                $falta = $delta - max(0, $espaco);
                return "❌ Essa proposta te deixa {$falta}M acima do teto: você recebe {$recebe}M, manda {$envia}M "
                     . "e tem {$espaco}M de espaço. Inclua mais salário.";
            }
        }
    } catch (Throwable $e) {
        error_log('[leilao_whats] cap: ' . $e->getMessage());
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id, status FROM leilao_whats WHERE id = ? FOR UPDATE");
        $st->execute([(int)$lw['id']]);
        if (($st->fetch(PDO::FETCH_ASSOC)['status'] ?? '') !== 'aberto') {
            $pdo->rollBack();
            return "⏱ O leilão de {$lw['jogador']} acabou de fechar.";
        }

        // Uma proposta na mão de cada time. A que está esperando a vez é
        // trocada pela nova; a que já está no grupo precisa da resposta antes.
        $st = $pdo->prepare("SELECT id, proposta_id, status FROM leilao_whats_propostas
                              WHERE lw_id = ? AND team_id = ? AND status IN ('aguardando','na_vez')");
        $st->execute([(int)$lw['id'], $teamId]);
        $substituida = false;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $antiga) {
            if ($antiga['status'] === 'na_vez') {
                $pdo->rollBack();
                return "⏳ Sua proposta anterior está no Gameplay esperando a resposta do {$lw['vendedor_nome']}. Manda a nova depois que ele responder.";
            }
            $pdo->prepare("UPDATE leilao_whats_propostas SET status = 'substituida', decidida_em = NOW() WHERE id = ?")
                ->execute([(int)$antiga['id']]);
            $pdo->prepare("UPDATE leilao_propostas SET status = 'recusada' WHERE id = ?")
                ->execute([(int)$antiga['proposta_id']]);
            $substituida = true;
        }

        $pdo->prepare("INSERT INTO leilao_propostas (leilao_id, team_id, obs, status, is_personalized, created_at)
                       VALUES (?, ?, 'Proposta pelo WhatsApp', 'pendente', 0, NOW())")
            ->execute([(int)$lw['leilao_id'], $teamId]);
        $propostaId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare("INSERT INTO leilao_proposta_jogadores (proposta_id, player_id) VALUES (?, ?)");
        foreach (array_keys($jogadores) as $pid) $ins->execute([$propostaId, $pid]);
        $ins = $pdo->prepare("INSERT INTO leilao_proposta_picks (proposta_id, pick_id) VALUES (?, ?)");
        foreach (array_keys($picks) as $pid) $ins->execute([$propostaId, $pid]);

        $pdo->prepare("INSERT INTO leilao_whats_propostas (lw_id, proposta_id, team_id, autor_jid) VALUES (?, ?, ?, ?)")
            ->execute([(int)$lw['id'], $propostaId, $teamId, mb_substr($jid, 0, 80)]);
        $pdo->prepare("UPDATE leilao_whats SET ultima_atividade = NOW() WHERE id = ?")->execute([(int)$lw['id']]);

        $st = $pdo->prepare("SELECT COUNT(*) FROM leilao_whats_propostas WHERE lw_id = ? AND status IN ('aguardando','na_vez') AND proposta_id <> ?");
        $st->execute([(int)$lw['id'], $propostaId]);
        $naFrente = (int)$st->fetchColumn();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[leilao_whats] proposta: ' . $e->getMessage());
        return "Deu erro ao registrar a proposta. Tenta de novo.";
    }

    return "📨 Proposta " . ($substituida ? 'atualizada' : 'recebida') . ":\n\n"
         . lwBlocoDaProposta($pdo, $propostaId) . "\n\n"
         . ($naFrente > 0 ? "Tem {$naFrente} na sua frente — ela vai pro Gameplay quando for a vez."
                          : "Vai pro Gameplay em instantes.");
}

/* ─── decisão do dono ─────────────────────────────────────────────────────── */

/**
 * /aceitar ou /recusar sem código, no Gameplay. Devolve null quando não há
 * leilão do WhatsApp esperando essa pessoa — aí o comando segue pro fluxo
 * antigo (o que usa código).
 */
function lwDecidirNoGrupo(PDO $pdo, string $cmd, string $deQuem, string $grupoJid): ?string
{
    lwGarantirTabelas($pdo);
    $st = $pdo->prepare("SELECT COUNT(*) FROM leilao_whats WHERE status = 'aberto' AND grupo_jid = ?");
    $st->execute([$grupoJid]);
    if (!(int)$st->fetchColumn()) return null;

    if (str_contains($deQuem, '@lid') || strlen(preg_replace('/\D+/', '', explode('@', $deQuem)[0] ?? '')) < 8) {
        return "O WhatsApp não me passou seu número neste grupo, então não sei se o leilão é seu. "
             . "Manda */leilao {$cmd}* no privado do bot.";
    }
    $times = lwTimesDoNumero($pdo, $deQuem);
    if (!$times) return lwNaoTeAchei($deQuem);
    return lwDecidir($pdo, $cmd, $times, false, $grupoJid);
}

function lwDecidir(PDO $pdo, string $cmd, array $times, bool $noPrivado, ?string $grupoJid = null): string
{
    $ids = array_map(fn($t) => (int)$t['id'], $times);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT w.*, p.name AS jogador, t.name AS vendedor_nome
              FROM leilao_whats w
              JOIN leilao_jogadores l ON l.id = w.leilao_id
         LEFT JOIN players p ON p.id = l.player_id
              JOIN teams t ON t.id = w.vendedor_team_id
             WHERE w.status = 'aberto' AND w.vendedor_team_id IN ($ph)";
    $params = $ids;
    if ($grupoJid) { $sql .= " AND w.grupo_jid = ?"; $params[] = $grupoJid; }
    $st = $pdo->prepare($sql . " LIMIT 1");
    $st->execute($params);
    $lw = $st->fetch(PDO::FETCH_ASSOC);
    if (!$lw) return "Você não tem leilão aberto" . ($grupoJid ? ' neste grupo' : '') . ".";

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT wp.id, wp.proposta_id, wp.team_id, t.name AS time_nome
                               FROM leilao_whats_propostas wp JOIN teams t ON t.id = wp.team_id
                              WHERE wp.lw_id = ? AND wp.status = 'na_vez' LIMIT 1 FOR UPDATE");
        $st->execute([(int)$lw['id']]);
        $vez = $st->fetch(PDO::FETCH_ASSOC);
        if (!$vez) {
            $pdo->rollBack();
            return "Não tem proposta esperando resposta no leilão de {$lw['jogador']} agora.";
        }

        if ($cmd === 'aceitar') {
            // A anterior deixa de ser a melhor. No app ela volta a "recusada".
            $st = $pdo->prepare("SELECT proposta_id FROM leilao_whats_propostas WHERE lw_id = ? AND status = 'aceita'");
            $st->execute([(int)$lw['id']]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $pdo->prepare("UPDATE leilao_propostas SET status = 'recusada' WHERE id = ?")->execute([(int)$pid]);
            }
            $pdo->prepare("UPDATE leilao_whats_propostas SET status = 'superada' WHERE lw_id = ? AND status = 'aceita'")
                ->execute([(int)$lw['id']]);
            $pdo->prepare("UPDATE leilao_whats_propostas SET status = 'aceita', decidida_em = NOW() WHERE id = ?")
                ->execute([(int)$vez['id']]);
            $pdo->prepare("UPDATE leilao_propostas SET status = 'aceita' WHERE id = ?")->execute([(int)$vez['proposta_id']]);
        } else {
            $pdo->prepare("UPDATE leilao_whats_propostas SET status = 'recusada', decidida_em = NOW() WHERE id = ?")
                ->execute([(int)$vez['id']]);
            $pdo->prepare("UPDATE leilao_propostas SET status = 'recusada' WHERE id = ?")->execute([(int)$vez['proposta_id']]);
        }
        // Decisão também conta como movimento: quem foi superado ganha os
        // minutos pra cobrir.
        $pdo->prepare("UPDATE leilao_whats SET ultima_atividade = NOW() WHERE id = ?")->execute([(int)$lw['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[leilao_whats] decidir: ' . $e->getMessage());
        return "Deu erro ao registrar a decisão. Tenta de novo.";
    }

    $txtGrupo = $cmd === 'aceitar'
        ? "✅ *{$lw['vendedor_nome']} aceitou* a proposta do *{$vez['time_nome']}*. É a melhor até agora — ainda dá pra cobrir.\n"
          . (($link = lwLinkDoBot($pdo)) ? "👉 Cobrir: {$link}\n" : '')
          . "⏱ Até " . lwMinutosRestantes($lw) . " min, ou " . LW_OCIOSO_MIN . " min sem proposta nova."
        : "❌ *{$lw['vendedor_nome']} recusou* a proposta do *{$vez['time_nome']}*.";

    if ($noPrivado) {
        whatsappEnfileirar($pdo, (string)$lw['grupo_jid'], $txtGrupo, true, LEILAO_BOT_TIPO);
        return $cmd === 'aceitar' ? "✅ Aceita. Avisei no Gameplay." : "❌ Recusada. Avisei no Gameplay.";
    }
    return $txtGrupo;
}

/**
 * ✅ / ❌ como decisão. Só quando a mensagem é SÓ o emoji (repetido vale):
 * "✅ fechado com ele" é conversa, não resposta.
 * Devolve 'aceitar', 'recusar' ou null.
 */
function lwEmojiDecisao(string $texto): ?string
{
    // Seletor de variação (✔️ = ✔ + FE0F) e espaços não mudam o sentido.
    $t = preg_replace('/[\x{FE0E}\x{FE0F}\x{200D}\s]+/u', '', $texto);
    if ($t === null || $t === '') return null;
    if (preg_match('/^[\x{2705}\x{2714}\x{2611}]+$/u', $t)) return 'aceitar';   // ✅ ✔ ☑
    if (preg_match('/^[\x{274C}\x{2716}\x{274E}]+$/u', $t)) return 'recusar';   // ❌ ✖ ❎
    return null;
}

/** Quem mandou é o dono de um leilão aberto neste grupo, com proposta na vez? */
function lwVendedorComVezNoGrupo(PDO $pdo, string $deQuem, string $grupoJid): bool
{
    lwGarantirTabelas($pdo);
    $times = lwTimesDoNumero($pdo, $deQuem);
    if (!$times) return false;
    $ids = array_map(fn($t) => (int)$t['id'], $times);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT 1 FROM leilao_whats w
                          WHERE w.status = 'aberto' AND w.grupo_jid = ? AND w.vendedor_team_id IN ($ph)
                            AND EXISTS (SELECT 1 FROM leilao_whats_propostas p
                                         WHERE p.lw_id = w.id AND p.status = 'na_vez')
                          LIMIT 1");
    $st->execute(array_merge([$grupoJid], $ids));
    return (bool)$st->fetchColumn();
}

/* ─── o relógio ───────────────────────────────────────────────────────────── */

/**
 * Roda no pulso do worker (api/whatsapp-bot.php, a cada ~5 s): posta a
 * próxima proposta e fecha o leilão que venceu.
 */
function lwDespachar(PDO $pdo): void
{
    try {
        lwGarantirTabelas($pdo);
        $abertos = $pdo->query("SELECT w.*, (w.fim_max <= NOW()) AS venceu,
                                       (w.ultima_atividade <= NOW() - INTERVAL " . LW_OCIOSO_MIN . " MINUTE) AS ocioso,
                                       l.status AS status_app
                                  FROM leilao_whats w JOIN leilao_jogadores l ON l.id = w.leilao_id
                                 WHERE w.status = 'aberto'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($abertos as $lw) {
            $st = $pdo->prepare("SELECT status, COUNT(*) n FROM leilao_whats_propostas
                                  WHERE lw_id = ? AND status IN ('aguardando','na_vez') GROUP BY status");
            $st->execute([(int)$lw['id']]);
            $fila = $st->fetchAll(PDO::FETCH_KEY_PAIR);

            $semNada = empty($fila['aguardando']) && empty($fila['na_vez']);
            if ($lw['status_app'] !== 'ativo' || $lw['venceu'] || ($lw['ocioso'] && $semNada)) {
                lwEncerrar($pdo, (int)$lw['id']);
                continue;
            }
            if (empty($fila['na_vez']) && !empty($fila['aguardando'])) {
                lwPostarProxima($pdo, $lw);
            }
        }
    } catch (Throwable $e) {
        error_log('[leilao_whats] despachar: ' . $e->getMessage());
    }
}

function lwPostarProxima(PDO $pdo, array $lw): void
{
    $st = $pdo->prepare("SELECT id, proposta_id FROM leilao_whats_propostas
                          WHERE lw_id = ? AND status = 'aguardando' ORDER BY id LIMIT 1");
    $st->execute([(int)$lw['id']]);
    $prox = $st->fetch(PDO::FETCH_ASSOC);
    if (!$prox) return;

    // Marca antes de enfileirar, e só se ninguém marcou: dois pulsos do
    // worker juntos não postam a mesma proposta duas vezes.
    $up = $pdo->prepare("UPDATE leilao_whats_propostas SET status = 'na_vez', postada_em = NOW()
                          WHERE id = ? AND status = 'aguardando'
                            AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM leilao_whats_propostas
                                                            WHERE lw_id = ? AND status = 'na_vez') x)");
    $up->execute([(int)$prox['id'], (int)$lw['id']]);
    if ($up->rowCount() < 1) return;

    $st = $pdo->prepare("SELECT t.name, u.phone, p.name AS jogador FROM teams t
                      LEFT JOIN users u ON u.id = t.user_id
                           JOIN leilao_jogadores l ON l.id = ?
                      LEFT JOIN players p ON p.id = l.player_id
                          WHERE t.id = ?");
    $st->execute([(int)$lw['leilao_id'], (int)$lw['vendedor_team_id']]);
    $v = $st->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Dono', 'phone' => null, 'jogador' => ''];

    $numero = whatsappNumero($v['phone'] ?? null);
    $marca = $numero ? '@' . $numero : '*' . $v['name'] . '*';

    $txt = "🔨 Proposta por *{$v['jogador']}*\n\n"
         . lwBlocoDaProposta($pdo, (int)$prox['proposta_id']) . "\n\n"
         . "{$marca}, responda ✅ pra aceitar ou ❌ pra recusar";
    whatsappEnfileirar($pdo, (string)$lw['grupo_jid'], $txt, true, LEILAO_BOT_TIPO, null, $numero ? [$numero] : null);
}

/**
 * Fecha o leilão: executa a última aceita (se houver e ainda for possível),
 * consome o slot e anuncia no Gameplay.
 */
function lwEncerrar(PDO $pdo, int $lwId): void
{
    lwGarantirTabelas($pdo);
    // Só um fecha: o UPDATE condicional é a trava.
    $up = $pdo->prepare("UPDATE leilao_whats SET status = 'encerrando' WHERE id = ? AND status = 'aberto'");
    $up->execute([$lwId]);
    if ($up->rowCount() < 1) return;

    $st = $pdo->prepare("SELECT w.*, l.player_id, l.status AS status_app, l.proposta_aceita_id,
                                p.name AS jogador, p.position, p.ovr, p.age, t.name AS vendedor_nome
                           FROM leilao_whats w JOIN leilao_jogadores l ON l.id = w.leilao_id
                      LEFT JOIN players p ON p.id = l.player_id
                           JOIN teams t ON t.id = w.vendedor_team_id WHERE w.id = ?");
    $st->execute([$lwId]);
    $lw = $st->fetch(PDO::FETCH_ASSOC);
    if (!$lw) return;

    $resultado = 'sem_troca';
    $motivo = null;
    $vencedor = null;
    $propostaId = null;

    $pdo->beginTransaction();
    try {
        if ($lw['status_app'] === 'finalizado') {
            // Fechado pelo app enquanto rolava: a troca já foi feita lá.
            $resultado = $lw['proposta_aceita_id'] ? 'troca' : 'sem_troca';
            $propostaId = $lw['proposta_aceita_id'] ? (int)$lw['proposta_aceita_id'] : null;
        } elseif ($lw['status_app'] !== 'ativo') {
            $resultado = 'cancelado';
        } else {
            $st = $pdo->prepare("SELECT wp.proposta_id, wp.team_id, t.name FROM leilao_whats_propostas wp
                                   JOIN teams t ON t.id = wp.team_id
                                  WHERE wp.lw_id = ? AND wp.status = 'aceita' ORDER BY wp.decidida_em DESC LIMIT 1");
            $st->execute([$lwId]);
            $vencedor = $st->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($vencedor) {
                $propostaId = (int)$vencedor['proposta_id'];
                $motivo = lwTrocaAindaPossivel($pdo, $lw, $vencedor);
                if ($motivo === null) {
                    lwExecutarTroca($pdo, $lw, $vencedor);
                    $resultado = 'troca';
                } else {
                    $resultado = 'invalida';
                }
            }
            if ($resultado !== 'troca') {
                $pdo->prepare("UPDATE leilao_propostas SET status = 'recusada' WHERE leilao_id = ? AND status <> 'recusada'")
                    ->execute([(int)$lw['leilao_id']]);
                $pdo->prepare("UPDATE leilao_jogadores SET status = 'cancelado', data_fim = NOW() WHERE id = ?")
                    ->execute([(int)$lw['leilao_id']]);
            }
        }

        $pdo->prepare("UPDATE leilao_whats_propostas SET status = 'descartada'
                        WHERE lw_id = ? AND status IN ('aguardando','na_vez')")->execute([$lwId]);

        // O slot vai embora em qualquer desfecho — foi o combinado.
        $st = $pdo->prepare("SELECT id FROM loja_inventario
                              WHERE id_usuario = ? AND item_key = 'slot_leilao' AND atendido_em IS NULL
                           ORDER BY comprado_em ASC, id ASC LIMIT 1 FOR UPDATE");
        $st->execute([(int)$lw['vendedor_user_id']]);
        if ($slot = $st->fetchColumn()) {
            $pdo->prepare("UPDATE loja_inventario SET atendido_em = NOW(), obs = ? WHERE id = ?")
                ->execute([mb_substr('Leilão no WhatsApp: ' . $lw['jogador'] . ' (#' . $lw['leilao_id'] . ')', 0, 250), (int)$slot]);
        }

        $pdo->prepare("UPDATE leilao_whats SET status = 'encerrado', encerrado_em = NOW(), resultado = ? WHERE id = ?")
            ->execute([$resultado, $lwId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Volta pra aberto: o próximo pulso tenta de novo em vez de largar o leilão preso.
        $pdo->prepare("UPDATE leilao_whats SET status = 'aberto' WHERE id = ? AND status = 'encerrando'")->execute([$lwId]);
        error_log('[leilao_whats] encerrar #' . $lwId . ': ' . $e->getMessage());
        return;
    }

    $linhaJog = lwLinhaJogador(['name' => $lw['jogador']] + $lw);
    if ($resultado === 'troca' && $propostaId) {
        $st = $pdo->prepare("SELECT t.name FROM leilao_propostas lp JOIN teams t ON t.id = lp.team_id WHERE lp.id = ?");
        $st->execute([$propostaId]);
        $nomeVencedor = (string)$st->fetchColumn();
        $txt = "🏁 *LEILÃO ENCERRADO*\n\n"
             . "*{$linhaJog}* vai para o *{$nomeVencedor}*.\n\n"
             . lwBlocoDaProposta($pdo, $propostaId) . "\n\n"
             . "✅ Troca feita no app.";
    } elseif ($resultado === 'invalida') {
        $txt = "🏁 *LEILÃO ENCERRADO*\n\n"
             . "A proposta aceita do *{$vencedor['name']}* não pôde ser executada: {$motivo}.\n"
             . "*{$linhaJog}* continua no *{$lw['vendedor_nome']}*.";
    } elseif ($resultado === 'cancelado') {
        $txt = "🏁 O leilão de *{$lw['jogador']}* foi cancelado.";
    } else {
        $txt = "🏁 *LEILÃO ENCERRADO*\n\n"
             . "Nenhuma proposta aceita. *{$linhaJog}* continua no *{$lw['vendedor_nome']}*.";
    }
    whatsappEnfileirar($pdo, (string)$lw['grupo_jid'], $txt, true, LEILAO_BOT_TIPO);
}

/** Null se dá pra executar; senão, o motivo em português. Chamar com transação aberta. */
function lwTrocaAindaPossivel(PDO $pdo, array $lw, array $vencedor): ?string
{
    $winner = (int)$vencedor['team_id'];
    $seller = (int)$lw['vendedor_team_id'];

    $st = $pdo->prepare("SELECT team_id FROM players WHERE id = ? FOR UPDATE");
    $st->execute([(int)$lw['player_id']]);
    if ((int)$st->fetchColumn() !== $seller) return "{$lw['jogador']} não está mais no {$lw['vendedor_nome']}";

    $st = $pdo->prepare("SELECT p.name, p.team_id FROM leilao_proposta_jogadores x
                           JOIN players p ON p.id = x.player_id WHERE x.proposta_id = ? FOR UPDATE");
    $st->execute([(int)$vencedor['proposta_id']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if ((int)$p['team_id'] !== $winner) return "{$p['name']} não está mais no {$vencedor['name']}";
    }

    $usadas = picksJaUsadas($pdo, true);
    $st = $pdo->prepare("SELECT pk.id, pk.team_id, pk.season_year, pk.round FROM leilao_proposta_picks x
                           JOIN picks pk ON pk.id = x.pick_id WHERE x.proposta_id = ? FOR UPDATE");
    $st->execute([(int)$vencedor['proposta_id']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $pk) {
        if ((int)$pk['team_id'] !== $winner) return "a Pick {$pk['season_year']} R{$pk['round']} não é mais do {$vencedor['name']}";
        if (!empty($usadas[(int)$pk['id']])) return "a Pick {$pk['season_year']} R{$pk['round']} já foi usada no draft";
    }
    return null;
}

/** A transferência — mesma de _executarTrocaLeilao. Chamar com transação aberta. */
function lwExecutarTroca(PDO $pdo, array $lw, array $vencedor): void
{
    $propostaId = (int)$vencedor['proposta_id'];
    $winner = (int)$vencedor['team_id'];
    $seller = (int)$lw['vendedor_team_id'];
    $leilaoId = (int)$lw['leilao_id'];

    $pdo->prepare("UPDATE leilao_propostas SET status = 'aceita' WHERE id = ?")->execute([$propostaId]);
    $pdo->prepare("UPDATE leilao_propostas SET status = 'recusada' WHERE leilao_id = ? AND id <> ?")->execute([$leilaoId, $propostaId]);
    $pdo->prepare("UPDATE leilao_jogadores SET status = 'finalizado', proposta_aceita_id = ?, data_fim = NOW() WHERE id = ?")
        ->execute([$propostaId, $leilaoId]);

    // Quem muda de time chega no banco, como na trade, no draft e na FA.
    $mover = $pdo->prepare("UPDATE players SET team_id = ?, role = 'Banco' WHERE id = ?");
    $mover->execute([$winner, (int)$lw['player_id']]);

    $st = $pdo->prepare("SELECT player_id FROM leilao_proposta_jogadores WHERE proposta_id = ?");
    $st->execute([$propostaId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) $mover->execute([$seller, (int)$pid]);

    $st = $pdo->prepare("SELECT pick_id FROM leilao_proposta_picks WHERE proposta_id = ?");
    $st->execute([$propostaId]);
    $picks = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if ($picks) {
        $ph = implode(',', array_fill(0, count($picks), '?'));
        $pdo->prepare("UPDATE picks SET team_id = ? WHERE id IN ($ph)")->execute(array_merge([$seller], $picks));
    }
}
