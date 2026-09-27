<?php
/**
 * API da conferência de off-season (ver backend/revisao.php).
 *
 * REGRA DE QUEM PODE: o GM só mexe em coisa que passa pelo time DELE — mover
 * um jogador do seu time pra outro, puxar uma pick de outro time pro seu,
 * dispensar quem é seu. Montar movimentação entre dois times alheios é do
 * admin. Sem isso a tela viraria um admin aberto pra liga inteira.
 */

require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/revisao.php';

header('Content-Type: application/json; charset=utf-8');
requireAuth();

$user = getUserSession();
$pdo  = db();
revisaoGarantirTabelas($pdo);

$userId  = (int)$user['id'];
$ehAdmin = hasAdminAccess($pdo, $userId);

$st = $pdo->prepare('SELECT id, league FROM teams WHERE user_id = ? LIMIT 1');
$st->execute([$userId]);
$meu = $st->fetch(PDO::FETCH_ASSOC) ?: null;
$meuTimeId = $meu ? (int)$meu['id'] : 0;

$acao   = $_GET['action'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];
$corpo  = $metodo === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

/*
 * QUAL LIGA ESTA REQUISIÇÃO CONFERE. Vem do pedido (a tela manda em toda
 * chamada), e cai na liga do próprio GM quando não vier — senão um GM da
 * ELITE abriria a tela e levaria um "não é da NEXT" sem entender por quê.
 */
$ligaPedida = $_GET['liga'] ?? $corpo['liga'] ?? ($meu['league'] ?? null);
revisaoLiga($ligaPedida);

function falha(string $msg, int $codigo = 400): never
{
    http_response_code($codigo);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function ligaDoTime(PDO $pdo, int $teamId): string
{
    $st = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
    $st->execute([$teamId]);
    return (string)($st->fetchColumn() ?: '');
}

/** O time precisa existir e ser da liga em conferência. */
function exigirDaLiga(PDO $pdo, int $teamId, string $rotulo): void
{
    if ($teamId <= 0) falha("Escolha o $rotulo.");
    if (ligaDoTime($pdo, $teamId) !== revisaoLiga()) falha("O $rotulo não é da " . revisaoLiga() . '.');
}

/**
 * Quem pode mexer. Com REVISAO_ABERTA ligada, qualquer GM da liga mexe em
 * qualquer time — é a janela de regularização, e tudo fica no revisao_log.
 * Fechada, volta a regra normal: admin em tudo, GM só onde o time dele entra.
 */
function exigirMinhaPonta(bool $ehAdmin, int $meuTimeId, array $pontas): void
{
    if (REVISAO_ABERTA || $ehAdmin) return;
    if (!$meuTimeId || !in_array($meuTimeId, array_map('intval', $pontas), true)) {
        falha('Você só pode mexer em algo que passa pelo seu time.', 403);
    }
}

// ─── GET estado: elenco, picks e pendências de um time ───────────────────
if ($metodo === 'GET' && $acao === 'estado') {
    $teamId = (int)($_GET['team_id'] ?? $meuTimeId);
    if (!$teamId) falha('Você não tem time nesta liga.', 404);
    exigirDaLiga($pdo, $teamId, 'time');

    $p = revisaoPendencias($pdo, $teamId);
    echo json_encode([
        'success'    => true,
        'team_id'    => $teamId,
        'nome'       => revisaoNomeTime($pdo, $teamId),
        'meu'        => $teamId === $meuTimeId,
        'elenco'     => revisaoElenco($pdo, $teamId),
        'picks'      => revisaoPicks($pdo, $teamId),
        'qtd'        => $p['qtd'],
        'cap'        => $p['cap'],
        'cap_min'    => $p['faixa']['min'],
        'cap_max'    => $p['faixa']['max'],
        'pendencias' => $p['itens'],
        'ok_em'      => revisaoEstaOk($pdo, $teamId),
        'times'      => revisaoTimes($pdo),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── GET tudo: a liga inteira numa tacada, pra tela de todos os times ────
if ($metodo === 'GET' && $acao === 'tudo') {
    $d = revisaoTudo($pdo);
    $d['success']   = true;
    $d['meu_time']  = $meuTimeId;
    $d['eh_admin']  = $ehAdmin;
    $d['aberta']    = REVISAO_ABERTA;
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── GET buscar: onde está tal jogador / tal pick, na liga inteira ───────
if ($metodo === 'GET' && $acao === 'buscar') {
    $r = revisaoBuscar($pdo, (string)($_GET['q'] ?? ''));
    echo json_encode(['success' => true] + $r, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── GET free_agents: quem está livre, pro "adicionar jogador" ───────────
if ($metodo === 'GET' && $acao === 'free_agents') {
    echo json_encode(['success' => true, 'fa' => revisaoFreeAgents($pdo, (string)($_GET['q'] ?? ''))],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── POST add_fa: traz um free agent pro time ────────────────────────────
if ($metodo === 'POST' && $acao === 'add_fa') {
    $faId   = (int)($corpo['fa_id'] ?? 0);
    $destino = (int)($corpo['to_team_id'] ?? 0);
    if (!$faId) falha('Escolha o jogador.');
    exigirDaLiga($pdo, $destino, 'time');
    exigirMinhaPonta($ehAdmin, $meuTimeId, [$destino]);

    $st = $pdo->prepare("SELECT * FROM free_agents WHERE id = ? AND status = 'available'");
    $st->execute([$faId]);
    $fa = $st->fetch(PDO::FETCH_ASSOC);
    if (!$fa) falha('Esse jogador não está mais livre.', 404);

    $pdo->beginTransaction();
    try {
        /* was_traded = 1 porque ele não foi draftado por este time: entrar
           pela free agency não pode virar lealdade no Cap Flex. */
        $ins = $pdo->prepare("INSERT INTO players (team_id, was_traded, is_lenda, name, age,
                                 seasons_in_league, position, secondary_position, role,
                                 available_for_trade, ovr, created_at)
                              VALUES (?,1,0,?,?,0,?,?,'Banco',0,?,NOW())");
        $ins->execute([$destino, $fa['name'], (int)$fa['age'], $fa['position'],
                       $fa['secondary_position'] ?: null, (int)$fa['overall']]);
        $pdo->prepare("UPDATE free_agents SET status = 'signed' WHERE id = ?")->execute([$faId]);
        revisaoRegistrar($pdo, $destino, $userId, 'add_fa',
            sprintf('%s (%s %s) da free agency -> %s', $fa['name'], $fa['overall'],
                    $fa['position'], revisaoNomeTime($pdo, $destino)));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[revisao add_fa] ' . $e->getMessage());
        falha('Não consegui adicionar. Tente de novo.', 500);
    }
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── POST criar_jogador: o que não está em lugar nenhum do app ───────────
if ($metodo === 'POST' && $acao === 'criar_jogador') {
    $destino = (int)($corpo['to_team_id'] ?? 0);
    exigirDaLiga($pdo, $destino, 'time');
    exigirMinhaPonta($ehAdmin, $meuTimeId, [$destino]);

    $nome = trim((string)($corpo['name'] ?? ''));
    $pos  = strtoupper(trim((string)($corpo['position'] ?? '')));
    $sec  = strtoupper(trim((string)($corpo['secondary_position'] ?? '')));
    $ovr  = (int)($corpo['ovr'] ?? 0);
    $idade = (int)($corpo['age'] ?? 0);

    if (mb_strlen($nome) < 2)                       falha('Escreva o nome do jogador.');
    if (!in_array($pos, ['PG','SG','SF','PF','C'], true)) falha('Escolha a posição.');
    if ($sec !== '' && !in_array($sec, ['PG','SG','SF','PF','C'], true)) falha('Posição secundária inválida.');
    if ($ovr < 40 || $ovr > 99)                     falha('O OVR tem que estar entre 40 e 99.');
    if ($idade < 16 || $idade > 45)                 falha('A idade tem que estar entre 16 e 45.');

    $ja = $pdo->prepare("SELECT p.id FROM players p JOIN teams t ON t.id = p.team_id
                          WHERE p.name = ? AND t.league = ?");
    $ja->execute([$nome, revisaoLiga()]);
    if ($ja->fetchColumn()) falha('Já existe um jogador com esse nome na liga. Mova ele em vez de criar outro.');

    $pdo->prepare("INSERT INTO players (team_id, was_traded, is_lenda, name, age, seasons_in_league,
                      position, secondary_position, role, available_for_trade, ovr, created_at)
                   VALUES (?,1,0,?,?,0,?,?,'Banco',0,?,NOW())")
        ->execute([$destino, $nome, $idade, $pos, $sec !== '' ? $sec : null, $ovr]);
    revisaoRegistrar($pdo, $destino, $userId, 'criar_jogador',
        sprintf('%s (%s %s, %sa) criado em %s', $nome, $ovr, $pos, $idade, revisaoNomeTime($pdo, $destino)));

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── GET picks_time: as picks de outro time, pra "pegar de X" ────────────
if ($metodo === 'GET' && $acao === 'picks_time') {
    $teamId = (int)($_GET['team_id'] ?? 0);
    exigirDaLiga($pdo, $teamId, 'time');
    echo json_encode(['success' => true, 'picks' => revisaoPicks($pdo, $teamId)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── GET painel: quem já confirmou (admin) ───────────────────────────────
if ($metodo === 'GET' && $acao === 'painel') {
    if (!$ehAdmin) falha('Sem permissão.', 403);
    echo json_encode(['success' => true, 'times' => revisaoPainel($pdo)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── POST ok: "meu time está OK" ─────────────────────────────────────────
if ($metodo === 'POST' && $acao === 'ok') {
    $teamId = (int)($corpo['team_id'] ?? $meuTimeId);
    exigirDaLiga($pdo, $teamId, 'time');
    exigirMinhaPonta($ehAdmin, $meuTimeId, [$teamId]);

    /*
     * TIME IRREGULAR TAMBÉM CONFIRMA.
     *
     * Isto recusava quem estivesse fora do cap ou do tamanho de elenco. Na
     * prática travava a conferência inteira: na ELITE, 23 dos 32 times estavam
     * irregulares, dez deles só por ter 16 jogadores em vez de 15 — e nenhum
     * conseguia dizer sequer que o elenco estava correto. São duas perguntas
     * diferentes, e a tela só faz a primeira: "os jogadores e as picks estão
     * certos?". O cap se resolve na free agency.
     *
     * A pendência continua aparecendo em vermelho na linha do time; o que
     * mudou é que ela deixou de ser impedimento.
     */
    $pdo->prepare('INSERT INTO revisao_ok (team_id, user_id, confirmado_em) VALUES (?,?,NOW())
                   ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), confirmado_em = NOW()')
        ->execute([$teamId, $userId]);
    $pdo->prepare('INSERT INTO revisao_log (team_id, user_id, acao, detalhe, criado_em)
                   VALUES (?,?,?,?,NOW())')
        ->execute([$teamId, $userId, 'ok', 'time confirmado']);

    echo json_encode(['success' => true, 'ok_em' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── POST dispensar ──────────────────────────────────────────────────────
if ($metodo === 'POST' && $acao === 'dispensar') {
    $playerId = (int)($corpo['player_id'] ?? 0);
    if (!$playerId) falha('Escolha o jogador.');

    $st = $pdo->prepare('SELECT * FROM players WHERE id = ?');
    $st->execute([$playerId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) falha('Jogador não encontrado.', 404);

    $origem = (int)$p['team_id'];
    exigirDaLiga($pdo, $origem, 'time do jogador');
    exigirMinhaPonta($ehAdmin, $meuTimeId, [$origem]);

    $sid = null;
    try {
        $s = $pdo->prepare("SELECT id FROM seasons WHERE league = ? ORDER BY year DESC LIMIT 1");
        $s->execute([revisaoLiga()]);
        $sid = $s->fetchColumn() ?: null;
    } catch (Throwable $e) { /* free agency aguenta sem season_id */ }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO free_agents (name, age, position, secondary_position, overall,
                          min_bid, status, league, original_team_id, original_team_name, waived_at,
                          season_id, is_retirement, created_at)
                       VALUES (?,?,?,?,?,0,'available',?,?,?,NOW(),?,0,NOW())")
            ->execute([$p['name'], $p['age'], $p['position'], $p['secondary_position'] ?: null,
                       $p['ovr'], revisaoLiga(), $origem, revisaoNomeTime($pdo, $origem), $sid]);
        $pdo->prepare('DELETE FROM players WHERE id = ?')->execute([$playerId]);
        revisaoRegistrar($pdo, $origem, $userId, 'dispensar',
            sprintf('%s (%s %s) -> free agency', $p['name'], $p['ovr'], $p['position']));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[revisao dispensar] ' . $e->getMessage());
        falha('Não consegui dispensar. Tente de novo.', 500);
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── POST mover_jogador ──────────────────────────────────────────────────
if ($metodo === 'POST' && $acao === 'mover_jogador') {
    $playerId = (int)($corpo['player_id'] ?? 0);
    $destino  = (int)($corpo['to_team_id'] ?? 0);
    if (!$playerId) falha('Escolha o jogador.');
    exigirDaLiga($pdo, $destino, 'time de destino');

    $st = $pdo->prepare('SELECT id, name, ovr, position, team_id FROM players WHERE id = ?');
    $st->execute([$playerId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) falha('Jogador não encontrado.', 404);

    $origem = (int)$p['team_id'];
    if ($origem === $destino) falha('O jogador já está nesse time.');
    exigirDaLiga($pdo, $origem, 'time do jogador');
    exigirMinhaPonta($ehAdmin, $meuTimeId, [$origem, $destino]);

    /* was_traded = 1 porque isto É uma troca: sem ele o jogador continuaria
       contando como leal no Cap Flex. Foi esse o bug do Oscar Robertson. */
    $pdo->prepare("UPDATE players SET team_id = ?, was_traded = 1, role = 'Banco',
                          lineup_slot = NULL WHERE id = ?")
        ->execute([$destino, $playerId]);
    revisaoRegistrar($pdo, $origem, $userId, 'mover_jogador',
        sprintf('%s (%s %s) %s -> %s', $p['name'], $p['ovr'], $p['position'],
                revisaoNomeTime($pdo, $origem), revisaoNomeTime($pdo, $destino)),
        [$destino]);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── POST mover_pick: enviar pra outro time, ou puxar de outro ───────────
if ($metodo === 'POST' && $acao === 'mover_pick') {
    $pickId  = (int)($corpo['pick_id'] ?? 0);
    $destino = (int)($corpo['to_team_id'] ?? 0);
    if (!$pickId) falha('Escolha a pick.');
    exigirDaLiga($pdo, $destino, 'time de destino');

    $st = $pdo->prepare("SELECT p.id, p.season_year, p.round, p.team_id, p.swap_type,
                                TRIM(CONCAT(COALESCE(o.city,''),' ',o.name)) origem
                           FROM picks p JOIN teams o ON o.id = p.original_team_id
                          WHERE p.id = ?");
    $st->execute([$pickId]);
    $pk = $st->fetch(PDO::FETCH_ASSOC);
    if (!$pk) falha('Pick não encontrada.', 404);

    $dono = (int)$pk['team_id'];
    if ($dono === $destino) falha('A pick já é desse time.');
    exigirDaLiga($pdo, $dono, 'dono da pick');
    exigirMinhaPonta($ehAdmin, $meuTimeId, [$dono, $destino]);

    /*
     * PICK EM SWAP TAMBÉM ANDA. Isto era um bloqueio, e estava errado: o
     * direito do swap é negociável como qualquer pick, e recusar só empurrava
     * o GM pro admin (onde o Dias tentou em 26/09 e também não conseguiu).
     *
     * O que não pode mudar é o PAR: só o dono se move, enquanto swap_type e
     * swap_pair_pick_id ficam onde estão. Mexer no rótulo SB/SW aqui é que
     * quebraria o par, e é isso que segue fora do alcance da tela.
     */
    $pdo->prepare('UPDATE picks SET team_id = ? WHERE id = ?')->execute([$destino, $pickId]);
    revisaoRegistrar($pdo, $dono, $userId, 'mover_pick',
        sprintf('%s R%s (%s) %s -> %s', $pk['season_year'], $pk['round'], $pk['origem'],
                revisaoNomeTime($pdo, $dono), revisaoNomeTime($pdo, $destino)),
        [$destino]);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── GET historico: o que já foi mexido (pra tela mostrar embaixo) ───────
if ($metodo === 'GET' && $acao === 'historico') {
    $teamId = (int)($_GET['team_id'] ?? $meuTimeId);
    $lim = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $st = $pdo->prepare("SELECT l.acao, l.detalhe, l.criado_em, u.name AS quem
                           FROM revisao_log l LEFT JOIN users u ON u.id = l.user_id
                          WHERE l.team_id = ? ORDER BY l.id DESC LIMIT $lim");
    $st->execute([$teamId]);
    echo json_encode(['success' => true, 'itens' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    exit;
}

falha('Ação desconhecida.', 404);
