<?php
/**
 * AS FERRAMENTAS DO MCP DA FBA.
 *
 * Cada função aqui responde uma pergunta que o dono da liga recebe no WhatsApp
 * e hoje só dava pra responder abrindo o banco: como está o cap do time, de
 * quem é a pick, essa troca cabe, quem ainda tem moeda.
 *
 * A saída é TEXTO, não JSON: quem lê é um assistente, e tabela em texto cabe em
 * menos espaço e se lê melhor do que objeto aninhado. Números de dinheiro saem
 * em milhões, como a liga fala.
 *
 * As quatro ferramentas que escrevem (mover pick, mover jogador, moedas e SQL)
 * aplicam direto, sem etapa de confirmação — foi a escolha do dono. Em troca,
 * toda chamada fica no `mcp_log` e as travas que existem são as que evitam
 * estrago de digitação: nada de DROP/TRUNCATE, UPDATE e DELETE só com WHERE, e
 * um teto de linhas afetadas.
 */

require_once __DIR__ . '/mcp_core.php';
require_once __DIR__ . '/salary_cap.php';
require_once __DIR__ . '/draft_swaps.php';
require_once __DIR__ . '/fa_resolve.php';

const MCP_SQL_MAX_LINHAS = 200;   // teto de linhas que um UPDATE/DELETE pode tocar

/** O catálogo que o assistente enxerga. */
function mcpFerramentas(): array
{
    $liga = ['type' => 'string', 'description' => 'ELITE, NEXT, RISE ou ROOKIE'];
    $time = ['type' => 'string', 'description' => 'Nome, apelido ou id do time (ex.: "Paisley", "87")'];

    return [
        [
            'name' => 'liga_panorama',
            'description' => 'Visão geral de uma liga: temporada, times com moedas, e a ordem da última classificação registrada. Comece por aqui quando não souber os nomes dos times.',
            'inputSchema' => ['type' => 'object', 'properties' => ['liga' => $liga], 'required' => ['liga']],
        ],
        [
            'name' => 'time',
            'description' => 'Dossiê de um time: elenco com salário e Cap Flex, situação no cap, moedas, vagas no elenco, picks e trocas recentes. É a ferramenta para "como está o time X".',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'time' => $time, 'liga' => $liga,
            ], 'required' => ['time']],
        ],
        [
            'name' => 'picks',
            'description' => 'As picks de um time (ou da liga inteira), com dono atual, origem, proteção e swap. Avisa quando faltam duas primeiras rodadas seguidas (Stepien Rule).',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'time' => $time, 'liga' => $liga,
                'ano' => ['type' => 'integer', 'description' => 'Só as picks deste ano de draft'],
            ]],
        ],
        [
            'name' => 'trades',
            'description' => 'Trocas recentes, com o que cada lado deu. Filtra por time, por situação (pending, accepted, rejected, cancelled, countered) e por liga.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'time' => $time, 'liga' => $liga,
                'situacao' => ['type' => 'string', 'description' => 'pending | accepted | rejected | cancelled | countered'],
                'limite' => ['type' => 'integer', 'description' => 'Quantas trocas (padrão 10, máx 40)'],
            ]],
        ],
        [
            'name' => 'trade',
            'description' => 'Uma troca em detalhe e o que ela faz com os dois times: salário que entra e sai, espaço no cap depois, tamanho do elenco e Stepien Rule. É a ferramenta para "essa troca pode?".',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Número da troca'],
            ], 'required' => ['id']],
        ],
        [
            'name' => 'free_agency',
            'description' => 'A Free Agency da liga: moedas de cada time, vagas no elenco, agentes livres disponíveis e as ofertas em aberto.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'liga' => $liga,
                'jogador' => ['type' => 'string', 'description' => 'Só as ofertas por este agente livre'],
            ], 'required' => ['liga']],
        ],
        [
            'name' => 'classificacao',
            'description' => 'A classificação registrada de uma temporada, do 1º ao último, com playoffs e a ordem geral — a mesma régua que define as moedas da Free Agency.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'liga' => $liga,
                'temporada' => ['type' => 'integer', 'description' => 'Número da temporada (padrão: a última com classificação)'],
            ], 'required' => ['liga']],
        ],
        [
            'name' => 'consulta',
            'description' => 'Um SELECT livre no banco, para o que as outras ferramentas não cobrem. Só leitura.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'sql' => ['type' => 'string', 'description' => 'SELECT ... (ou WITH ...). Máximo 200 linhas no resultado.'],
            ], 'required' => ['sql']],
        ],
        [
            'name' => 'mover_pick',
            'description' => 'ESCREVE. Passa uma pick para outro time (correção de troca, devolução, punição).',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'pick_id' => ['type' => 'integer', 'description' => 'Id da pick (aparece em picks e trade)'],
                'para' => $time,
                'motivo' => ['type' => 'string', 'description' => 'Por que está mudando de dono'],
            ], 'required' => ['pick_id', 'para', 'motivo']],
        ],
        [
            'name' => 'mover_jogador',
            'description' => 'ESCREVE. Passa um jogador para outro time. Recusa se o time de destino já tiver 15.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'jogador' => ['type' => 'string', 'description' => 'Nome ou id do jogador'],
                'para' => $time,
                'motivo' => ['type' => 'string'],
            ], 'required' => ['jogador', 'para', 'motivo']],
        ],
        [
            'name' => 'moedas',
            'description' => 'ESCREVE. Mexe nas moedas de Free Agency de um time: soma (ou desconta, com valor negativo) ou define um saldo.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'time' => $time,
                'somar' => ['type' => 'integer', 'description' => 'Quanto somar ao saldo (negativo desconta)'],
                'definir' => ['type' => 'integer', 'description' => 'Saldo final, no lugar de somar'],
                'motivo' => ['type' => 'string'],
            ], 'required' => ['time', 'motivo']],
        ],
        [
            'name' => 'executar_sql',
            'description' => 'ESCREVE. INSERT, UPDATE ou DELETE para o que as outras ferramentas não fazem. UPDATE e DELETE exigem WHERE e no máximo 200 linhas. DROP, TRUNCATE e ALTER são recusados.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'sql' => ['type' => 'string'],
                'motivo' => ['type' => 'string'],
            ], 'required' => ['sql', 'motivo']],
        ],
    ];
}

/** Quem escreve no banco — o transporte usa pra marcar o log. */
function mcpFerramentaEscreve(string $nome): bool
{
    return in_array($nome, ['mover_pick', 'mover_jogador', 'moedas', 'executar_sql'], true);
}

function mcpExecutar(PDO $pdo, string $nome, array $a): string
{
    return match ($nome) {
        'liga_panorama' => mcpLigaPanorama($pdo, mcpLiga($a['liga'] ?? null)),
        'time'          => mcpTime($pdo, (string)($a['time'] ?? ''), mcpLiga($a['liga'] ?? null)),
        'picks'         => mcpPicks($pdo, $a),
        'trades'        => mcpTrades($pdo, $a),
        'trade'         => mcpTrade($pdo, (int)($a['id'] ?? 0)),
        'classificacao' => mcpClassificacao($pdo, mcpLiga($a['liga'] ?? null), isset($a['temporada']) ? (int)$a['temporada'] : null),
        'free_agency'   => mcpFreeAgency($pdo, mcpLiga($a['liga'] ?? null), (string)($a['jogador'] ?? '')),
        'consulta'      => mcpConsulta($pdo, (string)($a['sql'] ?? '')),
        'mover_pick'    => mcpMoverPick($pdo, (int)($a['pick_id'] ?? 0), (string)($a['para'] ?? ''), (string)($a['motivo'] ?? '')),
        'mover_jogador' => mcpMoverJogador($pdo, (string)($a['jogador'] ?? ''), (string)($a['para'] ?? ''), (string)($a['motivo'] ?? '')),
        'moedas'        => mcpMoedas($pdo, $a),
        'executar_sql'  => mcpExecutarSql($pdo, (string)($a['sql'] ?? ''), (string)($a['motivo'] ?? '')),
        default         => throw new McpErro('Ferramenta desconhecida: ' . $nome),
    };
}

// ── Leitura ─────────────────────────────────────────────────────────────

function mcpLigaPanorama(PDO $pdo, ?string $liga): string
{
    if (!$liga) throw new McpErro('Diga a liga.');
    $t = mcpTemporadas($pdo, $liga);
    $out = "LIGA $liga\n";
    $out .= 'Temporada corrente: ' . ($t['atual']
        ? "{$t['atual']['season_number']} (ano {$t['atual']['year']}, {$t['atual']['status']})" : '—') . "\n";
    $out .= 'Última com classificação: ' . ($t['ultima_classificada']
        ? (string)$t['ultima_classificada']['season_number'] : '—') . "\n";
    $out .= 'Salário/cap: ' . (capLigaUsaSalario($pdo, $liga) ? 'sim' : 'não') . "\n\n";

    $st = $pdo->prepare("SELECT t.id, CONCAT(t.city,' ',t.name) nome, t.conference,
                                COALESCE(t.moedas,0) moedas, u.name gm,
                                (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id) elenco
                           FROM teams t LEFT JOIN users u ON u.id = t.user_id
                          WHERE t.league = ? ORDER BY t.city, t.name");
    $st->execute([$liga]);
    $times = $st->fetchAll(PDO::FETCH_ASSOC);
    $out .= count($times) . " times:\n";
    $out .= sprintf("%-5s %-28s %-7s %-7s %-6s %s\n", 'id', 'time', 'conf', 'elenco', 'moedas', 'GM');
    foreach ($times as $x) {
        $out .= sprintf("%-5d %-28s %-7s %-7s %-6d %s\n", $x['id'], $x['nome'],
            (string)$x['conference'], $x['elenco'] . '/' . ELENCO_MAX, $x['moedas'], (string)$x['gm']);
    }
    return $out;
}

function mcpTime(PDO $pdo, string $busca, ?string $liga): string
{
    $t = mcpAcharTime($pdo, $busca, $liga);
    $id = (int)$t['id'];
    $out = "#{$id} {$t['nome']} — {$t['league']}" . ($t['conference'] ? " ({$t['conference']})" : '')
         . "\nGM: " . ($t['gm'] ?: '—') . " | moedas de FA: {$t['moedas']}\n";

    $cap = getTeamCapSummary($pdo, $id);
    $usaSalario = capLigaUsaSalario($pdo, (string)$t['league']);
    if ($usaSalario) {
        $out .= sprintf("Cap: folha %dM de %dM (base %dM + flex %dM) — %s, espaço %dM\n",
            $cap['payroll'], $cap['cap_max'], $cap['cap_base'], $cap['cap_flex_total'],
            str_replace('_', ' ', $cap['status']), $cap['space']);
    }

    $elenco = count($cap['roster']);
    $out .= "Elenco: $elenco/" . ELENCO_MAX . " (vagas: " . max(0, ELENCO_MAX - $elenco) . ")\n\n";
    $out .= sprintf("%-26s %-4s %-8s %-8s %s\n", 'jogador', 'ovr', $usaSalario ? 'salário' : '', $usaSalario ? 'flex' : '', 'obs');
    foreach ($cap['roster'] as $p) {
        $obs = [];
        if ($p['is_lenda'])        $obs[] = 'lenda';
        if ($p['is_loyal'])        $obs[] = 'leal';
        if ($p['is_rookie_scale']) $obs[] = 'calouro';
        if ($p['award_bonus'] > 0) $obs[] = 'prêmio +' . $p['award_bonus'] . 'M';
        $out .= sprintf("%-26s %-4d %-8s %-8s %s\n", mb_substr($p['name'], 0, 26), $p['ovr'],
            $usaSalario ? $p['total_salary'] . 'M' : '',
            $usaSalario ? ($p['cap_flex_counted'] ? '+' . $p['cap_flex_value'] . 'M' : ($p['cap_flex_value'] ? '(' . $p['cap_flex_value'] . 'M)' : '')) : '',
            implode(', ', $obs));
    }

    $out .= "\n" . mcpTextoPicks($pdo, $id, (string)$t['league'], null);

    $st = $pdo->prepare("SELECT tr.id, tr.status, tr.created_at, tr.from_team_id, tr.to_team_id,
                                CONCAT(a.city,' ',a.name) de, CONCAT(b.city,' ',b.name) para
                           FROM trades tr JOIN teams a ON a.id = tr.from_team_id JOIN teams b ON b.id = tr.to_team_id
                          WHERE tr.from_team_id = ? OR tr.to_team_id = ?
                       ORDER BY tr.id DESC LIMIT 8");
    $st->execute([$id, $id]);
    $out .= "\nTrocas recentes:\n";
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $tr) {
        $out .= sprintf("  #%-6d [%-9s] %s -> %s  %s\n", $tr['id'], $tr['status'], $tr['de'], $tr['para'],
            substr((string)$tr['created_at'], 0, 16));
    }
    return $out;
}

/** O bloco de picks de um time — usado solto e dentro do dossiê. */
function mcpTextoPicks(PDO $pdo, int $teamId, string $liga, ?int $ano): string
{
    $sql = "SELECT p.id, p.season_year, p.round, p.protection, p.swap_type,
                   CONCAT(o.city,' ',o.name) origem, p.original_team_id
              FROM picks p JOIN teams o ON o.id = p.original_team_id
             WHERE p.team_id = ?" . ($ano ? " AND p.season_year = ?" : '') . "
          ORDER BY p.season_year, p.round, o.city";
    $st = $pdo->prepare($sql);
    $st->execute($ano ? [$teamId, $ano] : [$teamId]);
    $picks = $st->fetchAll(PDO::FETCH_ASSOC);

    $out = "Picks (" . count($picks) . "):\n";
    foreach ($picks as $p) {
        $extra = [];
        if ($p['protection']) $extra[] = 'prot ' . $p['protection'];
        if ($p['swap_type'])  $extra[] = 'swap ' . $p['swap_type'];
        $propria = (int)$p['original_team_id'] === $teamId;
        $out .= sprintf("  #%-6d %s R%s %s%s\n", $p['id'], $p['season_year'], $p['round'],
            $propria ? 'própria' : 'do ' . $p['origem'], $extra ? ' [' . implode(', ', $extra) . ']' : '');
    }

    // Stepien: dois anos seguidos sem a PRÓPRIA pick de 1ª rodada.
    $st = $pdo->prepare("SELECT season_year, team_id FROM picks
                          WHERE original_team_id = ? AND round = '1' ORDER BY season_year");
    $st->execute([$teamId]);
    $faltando = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if ((int)$p['team_id'] !== $teamId) $faltando[] = (int)$p['season_year'];
    }
    if ($faltando) {
        $out .= '  1ª rodada própria fora: ' . implode(', ', $faltando) . "\n";
        $seguidos = [];
        foreach ($faltando as $ano2) if (in_array($ano2 + 1, $faltando, true)) $seguidos[] = "$ano2 e " . ($ano2 + 1);
        if ($seguidos) $out .= '  *** STEPIEN: anos seguidos sem 1ª rodada — ' . implode('; ', $seguidos) . "\n";
    }
    return $out;
}

function mcpPicks(PDO $pdo, array $a): string
{
    $liga = mcpLiga($a['liga'] ?? null);
    $ano  = isset($a['ano']) ? (int)$a['ano'] : null;

    if (!empty($a['time'])) {
        $t = mcpAcharTime($pdo, (string)$a['time'], $liga);
        return "{$t['nome']} ({$t['league']})\n" . mcpTextoPicks($pdo, (int)$t['id'], (string)$t['league'], $ano);
    }
    if (!$liga) throw new McpErro('Diga o time ou a liga.');

    $sql = "SELECT p.id, p.season_year, p.round, p.protection, p.swap_type,
                   CONCAT(o.city,' ',o.name) origem, CONCAT(d.city,' ',d.name) dono,
                   p.original_team_id, p.team_id
              FROM picks p JOIN teams o ON o.id = p.original_team_id JOIN teams d ON d.id = p.team_id
             WHERE o.league = ?" . ($ano ? " AND p.season_year = ?" : '') . "
          ORDER BY p.season_year, p.round, o.city";
    $st = $pdo->prepare($sql);
    $st->execute($ano ? [$liga, $ano] : [$liga]);
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC);

    $out = "Picks da $liga" . ($ano ? " em $ano" : '') . " — " . count($linhas) . "\n";
    $out .= "(só as que mudaram de dono aparecem com o dono ao lado)\n";
    foreach ($linhas as $p) {
        if ((int)$p['original_team_id'] === (int)$p['team_id'] && !$p['protection'] && !$p['swap_type']) continue;
        $extra = [];
        if ($p['protection']) $extra[] = 'prot ' . $p['protection'];
        if ($p['swap_type'])  $extra[] = 'swap ' . $p['swap_type'];
        $out .= sprintf("  #%-6d %s R%s de %-26s -> %-26s %s\n", $p['id'], $p['season_year'], $p['round'],
            $p['origem'], $p['dono'], $extra ? '[' . implode(', ', $extra) . ']' : '');
    }
    return $out;
}

function mcpTrades(PDO $pdo, array $a): string
{
    $liga   = mcpLiga($a['liga'] ?? null);
    $limite = max(1, min(40, (int)($a['limite'] ?? 10)));
    $where  = []; $params = [];

    if (!empty($a['time'])) {
        $t = mcpAcharTime($pdo, (string)$a['time'], $liga);
        $where[] = '(tr.from_team_id = ? OR tr.to_team_id = ?)';
        $params[] = (int)$t['id']; $params[] = (int)$t['id'];
        $liga = $liga ?: (string)$t['league'];
    }
    if ($liga)               { $where[] = 'tr.league = ?';  $params[] = $liga; }
    if (!empty($a['situacao'])) { $where[] = 'tr.status = ?'; $params[] = (string)$a['situacao']; }
    if (!$where) throw new McpErro('Diga pelo menos a liga ou o time.');

    $st = $pdo->prepare("SELECT tr.id, tr.status, tr.created_at, tr.league,
                                CONCAT(a.city,' ',a.name) de, CONCAT(b.city,' ',b.name) para
                           FROM trades tr JOIN teams a ON a.id = tr.from_team_id JOIN teams b ON b.id = tr.to_team_id
                          WHERE " . implode(' AND ', $where) . "
                       ORDER BY tr.id DESC LIMIT $limite");
    $st->execute($params);
    $trades = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$trades) return 'Nenhuma troca com esses filtros.';

    $out = '';
    foreach ($trades as $tr) {
        $out .= "#{$tr['id']} [{$tr['status']}] {$tr['de']} -> {$tr['para']}  " . substr((string)$tr['created_at'], 0, 16) . "\n";
        foreach (mcpItensDaTrade($pdo, (int)$tr['id']) as $i) $out .= '    ' . $i['texto'] . "\n";
    }
    return $out;
}

/** Os itens de uma troca, já resolvidos em texto (jogador ou pick). */
function mcpItensDaTrade(PDO $pdo, int $tradeId): array
{
    $st = $pdo->prepare("SELECT ti.*, p.season_year, p.round, p.team_id AS pick_dono,
                                CONCAT(o.city,' ',o.name) origem, CONCAT(d.city,' ',d.name) dono
                           FROM trade_items ti
                      LEFT JOIN picks p ON p.id = ti.pick_id
                      LEFT JOIN teams o ON o.id = p.original_team_id
                      LEFT JOIN teams d ON d.id = p.team_id
                          WHERE ti.trade_id = ? ORDER BY ti.from_team DESC, ti.id");
    $st->execute([$tradeId]);
    $itens = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $lado = $i['from_team'] ? 'quem propôs dá' : 'quem recebe dá';
        $itens[] = [
            'dados' => $i,
            'texto' => $i['pick_id']
                ? sprintf('%s: pick #%d %s R%s de %s (hoje com %s)', $lado, $i['pick_id'],
                    (string)$i['season_year'], (string)$i['round'], (string)$i['origem'], (string)$i['dono'])
                : sprintf('%s: %s (%s, %s ovr)', $lado, (string)$i['player_name'],
                    (string)$i['player_position'], (string)$i['player_ovr']),
        ];
    }
    return $itens;
}

function mcpTrade(PDO $pdo, int $id): string
{
    if ($id <= 0) throw new McpErro('Diga o número da troca.');
    $st = $pdo->prepare("SELECT tr.*, CONCAT(a.city,' ',a.name) de, CONCAT(b.city,' ',b.name) para
                           FROM trades tr JOIN teams a ON a.id = tr.from_team_id JOIN teams b ON b.id = tr.to_team_id
                          WHERE tr.id = ?");
    $st->execute([$id]);
    $tr = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tr) throw new McpErro("Troca #$id não existe.");

    $out = "Troca #{$tr['id']} [{$tr['status']}] — {$tr['league']}\n"
         . "{$tr['de']} (propôs) <-> {$tr['para']}\n"
         . 'criada ' . substr((string)$tr['created_at'], 0, 16)
         . ($tr['notes'] ? "\nrecado: {$tr['notes']}" : '') . "\n\n";

    $saiDe = 0; $vaiPra = 0; $qtdDe = 0; $qtdPra = 0;
    foreach (mcpItensDaTrade($pdo, $id) as $i) {
        $out .= '  ' . $i['texto'] . "\n";
        $d = $i['dados'];
        if (empty($d['pick_id'])) {
            if ($d['from_team']) { $qtdDe++;  $saiDe  += (int)$d['player_ovr']; }
            else                 { $qtdPra++; $vaiPra += (int)$d['player_ovr']; }
        }
    }

    // O que a troca faz com cada lado. Salário sai do cap real, não do item.
    foreach ([['id' => (int)$tr['from_team_id'], 'nome' => $tr['de'], 'sai' => $qtdDe, 'entra' => $qtdPra],
              ['id' => (int)$tr['to_team_id'],   'nome' => $tr['para'], 'sai' => $qtdPra, 'entra' => $qtdDe]] as $lado) {
        $cap = getTeamCapSummary($pdo, $lado['id']);
        $elenco = count($cap['roster']);
        $depois = $elenco - $lado['sai'] + $lado['entra'];
        $out .= "\n{$lado['nome']}: elenco $elenco -> $depois"
              . ($depois > ELENCO_MAX ? ' *** PASSA DE ' . ELENCO_MAX . ' ***' : '');
        if (capLigaUsaSalario($pdo, (string)$tr['league'])) {
            $out .= sprintf(" | folha %dM de %dM (espaço %dM)", $cap['payroll'], $cap['cap_max'], $cap['space']);
        }
        $out .= "\n" . mcpTextoStepien($pdo, $lado['id']);
    }
    return $out;
}

/** Só a linha do Stepien, pra usar dentro da análise da troca. */
function mcpTextoStepien(PDO $pdo, int $teamId): string
{
    $st = $pdo->prepare("SELECT season_year, team_id FROM picks
                          WHERE original_team_id = ? AND round = '1' ORDER BY season_year");
    $st->execute([$teamId]);
    $faltando = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if ((int)$p['team_id'] !== $teamId) $faltando[] = (int)$p['season_year'];
    }
    if (!$faltando) return "  1ª rodada: todas em casa\n";
    $seguidos = [];
    foreach ($faltando as $ano) if (in_array($ano + 1, $faltando, true)) $seguidos[] = "$ano+" . ($ano + 1);
    return '  1ª rodada fora: ' . implode(', ', $faltando)
         . ($seguidos ? '  *** STEPIEN (' . implode(', ', $seguidos) . ') ***' : '') . "\n";
}

function mcpClassificacao(PDO $pdo, ?string $liga, ?int $temporada): string
{
    if (!$liga) throw new McpErro('Diga a liga.');
    if ($temporada) {
        $st = $pdo->prepare("SELECT id, season_number FROM seasons WHERE league = ? AND season_number = ?");
        $st->execute([$liga, $temporada]);
    } else {
        $st = $pdo->prepare("SELECT s.id, s.season_number FROM seasons s
                              WHERE s.league = ?
                                AND EXISTS (SELECT 1 FROM season_standings ss WHERE ss.season_id = s.id)
                           ORDER BY s.season_number DESC, s.id DESC LIMIT 1");
        $st->execute([$liga]);
    }
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) throw new McpErro('Não achei essa temporada na ' . $liga . '.');

    $st = $pdo->prepare("SELECT id FROM teams WHERE league = ? ORDER BY city, name");
    $st->execute([$liga]);
    $ids = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id'));
    $ordem = classificacaoGeralDaTemporada($pdo, (int)$s['id'], $ids);
    if (!$ordem) return "Temporada {$s['season_number']} da $liga: sem classificação registrada.";

    $st = $pdo->prepare("SELECT ss.team_id, ss.position, ss.overall_position, ss.conference, ss.wins,
                                pr.position playoff, CONCAT(t.city,' ',t.name) nome
                           FROM season_standings ss
                           JOIN teams t ON t.id = ss.team_id
                      LEFT JOIN playoff_results pr ON pr.season_id = ss.season_id AND pr.team_id = ss.team_id
                          WHERE ss.season_id = ?");
    $st->execute([(int)$s['id']]);
    $dados = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $dados[(int)$r['team_id']] = $r;

    asort($ordem);
    $out = "Classificação da $liga — temporada {$s['season_number']}\n";
    $out .= sprintf("%-4s %-28s %-18s %-8s %s\n", '#', 'time', 'playoffs', 'seed', 'ordem geral');
    foreach ($ordem as $tid => $pos) {
        $d = $dados[$tid] ?? [];
        $out .= sprintf("%-4d %-28s %-18s %-8s %s\n", $pos, (string)($d['nome'] ?? ('#' . $tid)),
            (string)($d['playoff'] ?? '—'),
            ($d['conference'] ?? '') . ' ' . (string)($d['position'] ?? ''),
            $d['overall_position'] !== null ? (string)$d['overall_position'] : 'não declarada');
    }
    $out .= "\nÉ esta ordem que define as moedas da Free Agency (1º recebe menos).\n";
    return $out;
}

function mcpFreeAgency(PDO $pdo, ?string $liga, string $jogador): string
{
    if (!$liga) throw new McpErro('Diga a liga.');

    $st = $pdo->prepare("SELECT t.id, CONCAT(t.city,' ',t.name) nome, COALESCE(t.moedas,0) moedas,
                                (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id) elenco
                           FROM teams t WHERE t.league = ? ORDER BY moedas DESC, nome");
    $st->execute([$liga]);
    $out = "Moedas e vagas na $liga:\n";
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $vagas = ELENCO_MAX - (int)$t['elenco'];
        $out .= sprintf("  %-28s %-4d moedas  elenco %d/%d%s\n", $t['nome'], $t['moedas'],
            $t['elenco'], ELENCO_MAX, $vagas <= 0 ? '  (SEM VAGA)' : '');
    }

    $sql = "SELECT fa.id, fa.name, fa.overall, fa.position, fa.age, fa.status, fa.min_bid
              FROM free_agents fa WHERE fa.league = ? AND fa.status = 'available'";
    $params = [$liga];
    if ($jogador !== '') { $sql .= " AND fa.name LIKE ?"; $params[] = '%' . $jogador . '%'; }
    $sql .= " ORDER BY fa.overall DESC LIMIT 40";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $livres = $st->fetchAll(PDO::FETCH_ASSOC);

    $out .= "\nAgentes livres disponíveis (" . count($livres) . "):\n";
    foreach ($livres as $f) {
        $out .= sprintf("  #%-5d %-26s %-4s %-3d anos  ovr %-3d  mínimo %d\n", $f['id'],
            $f['name'], (string)$f['position'], (int)$f['age'], (int)$f['overall'], (int)$f['min_bid']);

        $st2 = $pdo->prepare("SELECT o.amount, o.priority, o.status, CONCAT(t.city,' ',t.name) time
                                FROM free_agent_offers o JOIN teams t ON t.id = o.team_id
                               WHERE o.free_agent_id = ? AND o.status = 'pending'
                            ORDER BY o.amount DESC");
        $st2->execute([(int)$f['id']]);
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $out .= sprintf("       oferta %-4d de %-26s (prioridade %s)\n", $o['amount'], $o['time'], $o['priority']);
        }
    }
    return $out;
}

function mcpConsulta(PDO $pdo, string $sql): string
{
    $sql = trim(rtrim(trim($sql), ';'));
    if ($sql === '') throw new McpErro('Mande o SELECT.');
    if (!preg_match('/^(select|with|show|describe|explain)\b/i', $sql)) {
        throw new McpErro('Só leitura aqui. Para escrever, use executar_sql.');
    }
    if (preg_match('/\b(insert|update|delete|drop|truncate|alter|create|replace|grant)\b/i', $sql)) {
        throw new McpErro('Essa consulta tem palavra de escrita. Use executar_sql se a intenção é mudar algo.');
    }
    $st = $pdo->query($sql);
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$linhas) return '(nenhuma linha)';
    $corte = array_slice($linhas, 0, MCP_SQL_MAX_LINHAS);

    $colunas = array_keys($corte[0]);
    $out = implode(' | ', $colunas) . "\n";
    foreach ($corte as $l) $out .= implode(' | ', array_map(fn($v) => (string)$v, array_values($l))) . "\n";
    if (count($linhas) > count($corte)) $out .= '... (' . count($linhas) . " linhas no total, mostrei " . count($corte) . ")\n";
    return $out;
}

// ── Escrita ─────────────────────────────────────────────────────────────

function mcpMoverPick(PDO $pdo, int $pickId, string $para, string $motivo): string
{
    if ($pickId <= 0)     throw new McpErro('Diga o id da pick.');
    if (trim($motivo) === '') throw new McpErro('Escreva o motivo — ele fica no registro.');

    $st = $pdo->prepare("SELECT p.*, CONCAT(d.city,' ',d.name) dono, CONCAT(o.city,' ',o.name) origem, o.league
                           FROM picks p JOIN teams d ON d.id = p.team_id JOIN teams o ON o.id = p.original_team_id
                          WHERE p.id = ?");
    $st->execute([$pickId]);
    $pick = $st->fetch(PDO::FETCH_ASSOC);
    if (!$pick) throw new McpErro("Pick #$pickId não existe.");

    $destino = mcpAcharTime($pdo, $para, (string)$pick['league']);
    if ((int)$destino['id'] === (int)$pick['team_id']) {
        return "A pick #$pickId já é do {$destino['nome']}. Nada mudou.";
    }
    if ((string)$destino['league'] !== (string)$pick['league']) {
        throw new McpErro('Pick da ' . $pick['league'] . ' não pode ir pra um time da ' . $destino['league'] . '.');
    }

    // Mesmo UPDATE da reversão de troca do admin: o swap morre junto, porque
    // ele descreve um acerto entre os dois times antigos.
    $pdo->prepare("UPDATE picks SET team_id = ?, last_owner_team_id = ?,
                          swap_type = NULL, swap_locked = 0, swap_pair_pick_id = NULL
                    WHERE id = ? AND team_id = ?")
        ->execute([(int)$destino['id'], (int)$pick['team_id'], $pickId, (int)$pick['team_id']]);

    return "Pick #$pickId ({$pick['season_year']} R{$pick['round']}, origem {$pick['origem']}): "
         . "{$pick['dono']} -> {$destino['nome']}.\nMotivo: $motivo";
}

function mcpMoverJogador(PDO $pdo, string $jogador, string $para, string $motivo): string
{
    if (trim($motivo) === '') throw new McpErro('Escreva o motivo.');
    $p = mcpAcharJogador($pdo, $jogador);
    $destino = mcpAcharTime($pdo, $para, (string)($p['league'] ?? ''));

    if ((int)$destino['id'] === (int)$p['team_id']) return "{$p['name']} já está no {$destino['nome']}.";

    $st = $pdo->prepare('SELECT COUNT(*) FROM players WHERE team_id = ?');
    $st->execute([(int)$destino['id']]);
    $quantos = (int)$st->fetchColumn();
    if ($quantos >= ELENCO_MAX) {
        throw new McpErro("{$destino['nome']} já tem $quantos jogadores (o limite é " . ELENCO_MAX . '). Dispense alguém antes.');
    }

    $pdo->prepare("UPDATE players SET team_id = ?, role = 'Banco' WHERE id = ?")
        ->execute([(int)$destino['id'], (int)$p['id']]);

    return "{$p['name']} ({$p['ovr']} ovr): " . (string)($p['time'] ?? 'sem time') . " -> {$destino['nome']} "
         . '(' . ($quantos + 1) . '/' . ELENCO_MAX . ").\nMotivo: $motivo";
}

function mcpMoedas(PDO $pdo, array $a): string
{
    $motivo = trim((string)($a['motivo'] ?? ''));
    if ($motivo === '') throw new McpErro('Escreva o motivo — ele aparece no extrato do time.');
    $temSomar   = array_key_exists('somar', $a) && $a['somar'] !== null && $a['somar'] !== '';
    $temDefinir = array_key_exists('definir', $a) && $a['definir'] !== null && $a['definir'] !== '';
    if ($temSomar === $temDefinir) throw new McpErro('Use somar OU definir, um dos dois.');

    $t = mcpAcharTime($pdo, (string)($a['time'] ?? ''), mcpLiga($a['liga'] ?? null));
    $antes = (int)$t['moedas'];
    $novo  = $temDefinir ? (int)$a['definir'] : $antes + (int)$a['somar'];
    if ($novo < 0) throw new McpErro("Isso deixaria o {$t['nome']} com $novo moedas. Saldo negativo não.");

    $pdo->prepare('UPDATE teams SET moedas = ? WHERE id = ?')->execute([$novo, (int)$t['id']]);
    try {
        $pdo->prepare('INSERT INTO team_coins_log (team_id, amount, balance_after, reason, admin_id, type)
                       VALUES (?, ?, ?, ?, NULL, ?)')
            ->execute([(int)$t['id'], $novo - $antes, $novo, $motivo, 'mcp']);
    } catch (Throwable $e) {
        error_log('[mcp] log de moedas: ' . $e->getMessage());
    }
    return "{$t['nome']}: $antes -> $novo moedas (" . sprintf('%+d', $novo - $antes) . ").\nMotivo: $motivo";
}

function mcpExecutarSql(PDO $pdo, string $sql, string $motivo): string
{
    $sql = trim(rtrim(trim($sql), ';'));
    if ($sql === '')          throw new McpErro('Mande o comando.');
    if (trim($motivo) === '') throw new McpErro('Escreva o motivo.');

    if (preg_match('/\b(drop|truncate|alter|create|rename|grant|revoke)\b/i', $sql, $m)) {
        throw new McpErro('"' . $m[1] . '" não passa por aqui. Mudança de estrutura é migração, no código.');
    }
    if (!preg_match('/^(insert|update|delete)\b/i', $sql, $tipo)) {
        throw new McpErro('Aqui só INSERT, UPDATE ou DELETE. Pra ler, use consulta.');
    }
    $comando = strtolower($tipo[1]);
    if (in_array($comando, ['update', 'delete'], true) && !preg_match('/\bwhere\b/i', $sql)) {
        throw new McpErro('UPDATE e DELETE sem WHERE, não. Isso pegaria a tabela inteira.');
    }

    /* Conta antes de executar: um WHERE mal escrito aparece aqui, e não depois
       de ter mexido em meia liga. O teto é baixo de propósito — mudança em
       massa é caso de script conferido, não de uma chamada de ferramenta. */
    if (in_array($comando, ['update', 'delete'], true)) {
        if (preg_match('/^update\s+([`\w\.]+)\s+set\b.*?\bwhere\b(.*)$/is', $sql, $m2)) {
            $conta = "SELECT COUNT(*) FROM {$m2[1]} WHERE {$m2[2]}";
        } elseif (preg_match('/^delete\s+from\s+([`\w\.]+)\s*\bwhere\b(.*)$/is', $sql, $m2)) {
            $conta = "SELECT COUNT(*) FROM {$m2[1]} WHERE {$m2[2]}";
        } else {
            $conta = null;
        }
        if ($conta) {
            try {
                $n = (int)$pdo->query($conta)->fetchColumn();
                if ($n > MCP_SQL_MAX_LINHAS) {
                    throw new McpErro("Esse comando pegaria $n linhas, acima do teto de " . MCP_SQL_MAX_LINHAS . '.');
                }
            } catch (McpErro $e) {
                throw $e;
            } catch (Throwable $e) {
                error_log('[mcp] contagem prévia falhou: ' . $e->getMessage());
            }
        }
    }

    $afetadas = $pdo->exec($sql);
    $extra = $comando === 'insert' ? ' (id ' . $pdo->lastInsertId() . ')' : '';
    return strtoupper($comando) . ": $afetadas linha(s)$extra.\nMotivo: $motivo";
}
