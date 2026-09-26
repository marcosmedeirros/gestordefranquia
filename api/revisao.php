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
    if (ligaDoTime($pdo, $teamId) !== REVISAO_LIGA) falha("O $rotulo não é da " . REVISAO_LIGA . '.');
}

/** Sem ser admin, o time do GM tem que estar numa das pontas. */
function exigirMinhaPonta(bool $ehAdmin, int $meuTimeId, array $pontas): void
{
    if ($ehAdmin) return;
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

    /* Confirmar com o time fora das regras só esconderia o problema. */
    $p = revisaoPendencias($pdo, $teamId);
    if ($p['itens']) {
        falha('O time ainda está irregular: ' . implode(' · ', $p['itens']));
    }

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
        $s->execute([REVISAO_LIGA]);
        $sid = $s->fetchColumn() ?: null;
    } catch (Throwable $e) { /* free agency aguenta sem season_id */ }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO free_agents (name, age, position, secondary_position, overall,
                          min_bid, status, league, original_team_id, original_team_name, waived_at,
                          season_id, is_retirement, created_at)
                       VALUES (?,?,?,?,?,0,'available',?,?,?,NOW(),?,0,NOW())")
            ->execute([$p['name'], $p['age'], $p['position'], $p['secondary_position'] ?: null,
                       $p['ovr'], REVISAO_LIGA, $origem, revisaoNomeTime($pdo, $origem), $sid]);
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

    /* Pick em swap anda junto com o par e com o rótulo SB/SW; mover só um
       lado deixa o par quebrado, e quem arruma depois sou eu no SQL. */
    if (!empty($pk['swap_type'])) {
        falha('Essa pick está num swap (' . $pk['swap_type'] . '). Fale com o admin — mover só um lado quebra o par.');
    }

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
