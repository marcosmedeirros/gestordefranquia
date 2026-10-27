<?php
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

$user = getUserSession();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$pdo      = db();
// A ponte com o antigo banco do games saiu na fusão; tapas agora vivem só
// aqui no banco do site (e a tela está escondida do menu).
$pdoGames = null;
$method   = $_SERVER['REQUEST_METHOD'];
$action   = $_GET['action'] ?? '';
/* ── ADMIN GERAL E ADMIN DE LIGA ──────────────────────────────────────
 *
 * Isto exigia `user_type === 'admin'` nas quatro ações de administração, e
 * com isso só o dono da liga enxergava o card de Badges: quem administra
 * uma liga recebia "Acesso negado" na própria. Era o único card do admin
 * com essa trava — o resto do painel já trabalha com os dois níveis.
 *
 * Agora o admin de liga entra, mas SÓ NA LIGA DELE: `exigirLiga()` abaixo
 * é chamada em toda ação que lê ou escreve algo de um time, com a liga do
 * alvo. O admin geral passa por todas.
 */
$isAdmin       = ($user['user_type'] ?? 'jogador') === 'admin';
$ligasDoAdmin  = $isAdmin ? ['ELITE', 'NEXT', 'RISE', 'ROOKIE'] : getAdminLeagues($pdo, (int)$user['id']);
$podeAdmin     = $isAdmin || !empty($ligasDoAdmin);

/**
 * A liga de um pedido de badge.
 *
 * O pedido guarda a liga, mas o time é a fonte da verdade: badge pedida
 * antes de o GM mudar de liga teria o rótulo antigo, e a permissão tem que
 * seguir onde o time está AGORA.
 */
function tapasLigaDoPedido(PDO $pdo, array $req): string {
    $t = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
    $t->execute([(int)($req['team_id'] ?? 0)]);
    $doTime = (string)($t->fetchColumn() ?: '');
    return $doTime !== '' ? $doTime : (string)($req['league'] ?? '');
}

/** Barra quem não administra ESTA liga. O admin geral nunca é barrado. */
function exigirLiga(bool $isGlobal, array $ligas, ?string $league): void {
    if ($isGlobal) return;
    $l = strtoupper(trim((string)$league));
    if ($l === '' || !in_array($l, $ligas, true)) err('Acesso negado', 403);
}

function out(array $data): void { echo json_encode($data); exit; }
function err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function ensureTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tapas_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        team_id INT NOT NULL,
        player_id INT NOT NULL,
        player_name VARCHAR(255) NOT NULL,
        league VARCHAR(20) NOT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        action_type ENUM('tapa','badge') NULL,
        badge_name VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at TIMESTAMP NULL,
        INDEX idx_tr_team (team_id),
        INDEX idx_tr_player (player_id),
        INDEX idx_tr_status (status),
        INDEX idx_tr_league (league)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $cols = $pdo->query("SHOW COLUMNS FROM players")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('tapa_count', $cols)) {
        $pdo->exec("ALTER TABLE players ADD COLUMN tapa_count INT NOT NULL DEFAULT 0");
    }
    if (!in_array('badge_name', $cols)) {
        $pdo->exec("ALTER TABLE players ADD COLUMN badge_name VARCHAR(255) NULL");
    }
}

ensureTables($pdo);

// ── helpers games DB ──────────────────────────────────────────────────────────

function getGamesUserId(PDO $pdo, int $userId): ?int {
    $stmt = $pdo->prepare("SELECT games_user_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $val = $stmt->fetchColumn();
    return ($val !== false && $val !== null) ? (int)$val : null;
}

function getGamesTapas(?PDO $pdoGames, ?int $gamesUserId): int {
    if (!$pdoGames || !$gamesUserId) return 0;
    $stmt = $pdoGames->prepare("SELECT COALESCE(numero_tapas,0) FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->execute([$gamesUserId]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (int)$val : 0;
}

function decrementGamesTapas(?PDO $pdoGames, ?int $gamesUserId): void {
    if (!$pdoGames || !$gamesUserId) return;
    $pdoGames->prepare("UPDATE usuarios SET numero_tapas = GREATEST(COALESCE(numero_tapas,0)-1,0) WHERE id = ?")
        ->execute([$gamesUserId]);
}

// ── GET my_status ─────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'my_status') {
    $stmt = $pdo->prepare("SELECT t.id, t.tapas_used FROM teams t WHERE t.user_id = ? LIMIT 1");
    $stmt->execute([$user['id']]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$team) out(['tapas' => 0, 'tapas_used' => 0, 'pending' => []]);

    $gamesUserId = getGamesUserId($pdo, $user['id']);
    $tapas       = getGamesTapas($pdoGames, $gamesUserId);

    $pend = $pdo->prepare("SELECT r.*, p.tapa_count, p.badge_name AS player_badge FROM tapas_requests r
        LEFT JOIN players p ON p.id = r.player_id
        WHERE r.team_id = ? ORDER BY r.created_at DESC");
    $pend->execute([$team['id']]);
    $requests = $pend->fetchAll(PDO::FETCH_ASSOC);

    out(['success' => true, 'tapas' => $tapas, 'tapas_used' => (int)$team['tapas_used'], 'requests' => $requests]);
}

// ── GET league_recipients ─────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'league_recipients') {
    $league = $_GET['league'] ?? $user['league'] ?? 'ELITE';
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.position, p.ovr, p.tapa_count, p.badge_name,
               t.city, t.name AS team_name
        FROM players p
        INNER JOIN teams t ON t.id = p.team_id
        WHERE t.league = ?
          AND (p.tapa_count > 0 OR p.badge_name IS NOT NULL)
        ORDER BY p.tapa_count DESC, p.name ASC
    ");
    $stmt->execute([$league]);
    out(['success' => true, 'players' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── POST request_tapa ─────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'request_tapa') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $playerId = (int)($body['player_id'] ?? 0);
    if (!$playerId) err('Jogador inválido');

    $stmtT = $pdo->prepare("SELECT id, league FROM teams WHERE user_id = ? LIMIT 1");
    $stmtT->execute([$user['id']]);
    $team = $stmtT->fetch(PDO::FETCH_ASSOC);
    if (!$team) err('Time não encontrado');

    // check balance in games DB
    $gamesUserId = getGamesUserId($pdo, $user['id']);
    $tapas       = getGamesTapas($pdoGames, $gamesUserId);
    if ($tapas <= 0) err('Sem tapas disponíveis');

    $stmtP = $pdo->prepare("SELECT id, name FROM players WHERE id = ? AND team_id = ?");
    $stmtP->execute([$playerId, $team['id']]);
    $player = $stmtP->fetch(PDO::FETCH_ASSOC);
    if (!$player) err('Jogador não encontrado no seu elenco');

    // bloqueia apenas se já há pendentes suficientes para consumir todo o saldo
    $stmtPend = $pdo->prepare("SELECT COUNT(*) FROM tapas_requests WHERE team_id = ? AND status = 'pending'");
    $stmtPend->execute([$team['id']]);
    $pendingCount = (int)$stmtPend->fetchColumn();
    if ($pendingCount >= $tapas) err('Você já tem ' . $pendingCount . ' solicitação(ões) pendente(s) e apenas ' . $tapas . ' tapa(s) disponível(is)');

    $actionType = $body['action_type'] ?? 'tapa';
    $badgeName  = trim($body['badge_name'] ?? '');
    if (!in_array($actionType, ['tapa', 'badge'])) err('Tipo de ação inválido');
    if ($actionType === 'badge' && $badgeName === '') err('Nome do badge obrigatório');

    $ins = $pdo->prepare("INSERT INTO tapas_requests (team_id, player_id, player_name, league, status, action_type, badge_name)
        VALUES (?, ?, ?, ?, 'pending', ?, ?)");
    $ins->execute([$team['id'], $playerId, $player['name'], $team['league'], $actionType, $actionType === 'badge' ? $badgeName : null]);

    out(['success' => true, 'message' => 'Solicitação enviada com sucesso']);
}

// ── GET admin_get_all ─────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'admin_get_all') {
    if (!$podeAdmin) err('Acesso negado', 403);
    $league = $_GET['league'] ?? 'ELITE';
    exigirLiga($isAdmin, $ligasDoAdmin, $league);

    $stmtTeams = $pdo->prepare("
        SELECT t.id, t.city, t.name, t.user_id,
               u.name AS owner_name
        FROM teams t
        LEFT JOIN users u ON u.id = t.user_id
        WHERE t.league = ?
        ORDER BY t.city, t.name
    ");
    $stmtTeams->execute([$league]);
    $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

    /* BADGES COMPRADAS QUE O GM AINDA NÃO USOU, por time.
       Era aqui que vinha o contador de TAPAS de cada time, lido do banco do
       games — que saiu na fusão. Desde então o número era sempre 0, e a tela
       de Badges mostrava um "Tapas: − 0 +" que não fazia nada. O que o admin
       quer ver por time é o saldo de badges: o mesmo inventário que o GM
       gasta no pedido (badgesCompradas), contado de uma vez pra liga toda. */
    $compradasPorUser = [];
    $userIds = array_values(array_filter(array_map(fn($t) => (int)($t['user_id'] ?? 0), $teams)));
    if ($userIds) {
        try {
            $ph = implode(',', array_fill(0, count($userIds), '?'));
            $stInv = $pdo->prepare("SELECT id_usuario, COUNT(*) AS n FROM loja_inventario
                                     WHERE item_key = 'badge' AND usado_em IS NULL AND id_usuario IN ($ph)
                                     GROUP BY id_usuario");
            $stInv->execute($userIds);
            foreach ($stInv->fetchAll(PDO::FETCH_ASSOC) as $r) $compradasPorUser[(int)$r['id_usuario']] = (int)$r['n'];
        } catch (Throwable $e) { error_log('[tapas/inventario] ' . $e->getMessage()); }
    }
    foreach ($teams as &$team) {
        $team['badges_compradas'] = $compradasPorUser[(int)($team['user_id'] ?? 0)] ?? 0;
        unset($team['user_id']);
    }
    unset($team);

    /*
     * SÓ O SPRINT ATIVO.
     *
     * A liga recomeça a cada sprint, e pedido de badge de sprint encerrado é
     * de outra era: o time pode nem existir mais, e o jogador quase nunca
     * está no mesmo elenco. Sem este corte o card abria com pendências de
     * meses atrás misturadas com as de hoje.
     *
     * O corte é por DATA porque `tapas_requests` não guarda sprint — o que
     * existe é a data em que o sprint começou. Sem sprint ativo, não filtra:
     * melhor mostrar tudo do que esconder o que está valendo.
     */
    $desdeSprint = null;
    try {
        $stSp = $pdo->prepare("SELECT start_date FROM sprints
                                WHERE league = ? AND status = 'active'
                             ORDER BY id DESC LIMIT 1");
        $stSp->execute([$league]);
        $desdeSprint = $stSp->fetchColumn() ?: null;
    } catch (Throwable $e) { error_log('[tapas/sprint] ' . $e->getMessage()); }

    $filtroSprint = $desdeSprint ? ' AND r.created_at >= ?' : '';
    $paramsSprint = $desdeSprint ? [$desdeSprint] : [];

    $stmtReq = $pdo->prepare("
        SELECT r.*, t.city AS team_city, t.name AS team_name,
               u.name AS owner_name,
               p.ovr AS player_ovr, p.position AS player_position
        FROM tapas_requests r
        INNER JOIN teams t ON t.id = r.team_id
        LEFT JOIN users u ON u.id = t.user_id
        INNER JOIN players p ON p.id = r.player_id
        WHERE r.league = ? AND r.status = 'pending'{$filtroSprint}
        ORDER BY r.created_at ASC
    ");
    $stmtReq->execute(array_merge([$league], $paramsSprint));
    $requests = $stmtReq->fetchAll(PDO::FETCH_ASSOC);

    // As badges já aplicadas neste sprint, por time — a lista que abre ao clicar no time.
    $stmtAplicadas = $pdo->prepare("
        SELECT r.team_id, r.player_id, r.player_name, r.badge_name,
               p.position, p.ovr
        FROM tapas_requests r
        LEFT JOIN players p ON p.id = r.player_id
        WHERE r.league = ? AND r.status = 'approved' AND r.action_type = 'badge'{$filtroSprint}
        ORDER BY r.team_id, r.processed_at DESC
    ");
    $stmtAplicadas->execute(array_merge([$league], $paramsSprint));
    $aplicadasPorTime = [];
    foreach ($stmtAplicadas->fetchAll(PDO::FETCH_ASSOC) as $ap) {
        $aplicadasPorTime[(int)$ap['team_id']][] = $ap;
    }
    $pendentesPorTime = [];
    foreach ($requests as $rq) {
        if (($rq['action_type'] ?? '') === 'badge') {
            $pendentesPorTime[(int)$rq['team_id']] = ($pendentesPorTime[(int)$rq['team_id']] ?? 0) + 1;
        }
    }
    foreach ($teams as &$team) {
        $team['badges_aplicadas'] = $aplicadasPorTime[(int)$team['id']] ?? [];
        $team['badges_pendentes'] = $pendentesPorTime[(int)$team['id']] ?? 0;
    }
    unset($team);

    $stmtHistory = $pdo->prepare("
        SELECT r.player_name, r.action_type, r.badge_name, r.processed_at,
               t.city AS team_city, t.name AS team_name,
               p.position, p.ovr
        FROM tapas_requests r
        INNER JOIN teams t ON t.id = r.team_id
        LEFT JOIN players p ON p.id = r.player_id
        WHERE r.league = ? AND r.status = 'approved' AND r.action_type = 'badge'{$filtroSprint}
        ORDER BY r.processed_at DESC
    ");
    $stmtHistory->execute(array_merge([$league], $paramsSprint));
    $history = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

    out(['success' => true, 'teams' => $teams, 'requests' => $requests, 'history' => $history]);
}

// ── POST admin_set_tapas ──────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'admin_set_tapas') {
    if (!$podeAdmin) err('Acesso negado', 403);
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $teamId    = (int)($body['team_id'] ?? 0);
    $amount    = (int)($body['amount'] ?? 0);
    $operation = $body['operation'] ?? 'set';
    if (!$teamId) err('Time inválido');

    /* A liga sai do time, e não do pedido: quem manda o POST não escolhe
       em que liga ele vale. */
    $stLiga = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
    $stLiga->execute([$teamId]);
    exigirLiga($isAdmin, $ligasDoAdmin, (string)($stLiga->fetchColumn() ?: ''));

    // resolve games_user_id for this team
    $rowU = $pdo->prepare("SELECT u.games_user_id FROM teams t JOIN users u ON u.id = t.user_id WHERE t.id = ? LIMIT 1");
    $rowU->execute([$teamId]);
    $gamesUserId = (int)($rowU->fetchColumn() ?? 0) ?: null;

    if ($pdoGames && $gamesUserId) {
        if ($operation === 'add') {
            $pdoGames->prepare("UPDATE usuarios SET numero_tapas = GREATEST(0, COALESCE(numero_tapas,0) + ?) WHERE id = ?")
                ->execute([$amount, $gamesUserId]);
        } elseif ($operation === 'remove') {
            $pdoGames->prepare("UPDATE usuarios SET numero_tapas = GREATEST(0, COALESCE(numero_tapas,0) - ?) WHERE id = ?")
                ->execute([$amount, $gamesUserId]);
        } else {
            $pdoGames->prepare("UPDATE usuarios SET numero_tapas = ? WHERE id = ?")
                ->execute([$amount, $gamesUserId]);
        }
        $newVal = getGamesTapas($pdoGames, $gamesUserId);
    } else {
        // fallback: update teams.tapas if games DB unavailable
        if ($operation === 'add') {
            $pdo->prepare("UPDATE teams SET tapas = tapas + ? WHERE id = ?")->execute([$amount, $teamId]);
        } elseif ($operation === 'remove') {
            $pdo->prepare("UPDATE teams SET tapas = GREATEST(0, tapas - ?) WHERE id = ?")->execute([$amount, $teamId]);
        } else {
            $pdo->prepare("UPDATE teams SET tapas = ? WHERE id = ?")->execute([$amount, $teamId]);
        }
        $stmtNew = $pdo->prepare("SELECT tapas FROM teams WHERE id = ?");
        $stmtNew->execute([$teamId]);
        $newVal = (int)$stmtNew->fetchColumn();
    }

    out(['success' => true, 'new_tapas' => $newVal]);
}

// ── POST admin_approve ────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'admin_approve') {
    if (!$podeAdmin) err('Acesso negado', 403);
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $requestId = (int)($body['request_id'] ?? 0);
    if (!$requestId) err('Solicitação inválida');

    $stmtR = $pdo->prepare("SELECT * FROM tapas_requests WHERE id = ? AND status = 'pending'");
    $stmtR->execute([$requestId]);
    $req = $stmtR->fetch(PDO::FETCH_ASSOC);
    if (!$req) err('Solicitação não encontrada ou já processada');
    exigirLiga($isAdmin, $ligasDoAdmin, tapasLigaDoPedido($pdo, $req));

    $actionType = $req['action_type'] ?? 'tapa';
    $badgeName  = $req['badge_name'] ?? '';

    // resolve games_user_id for the team owner
    $rowU = $pdo->prepare("SELECT u.games_user_id FROM teams t JOIN users u ON u.id = t.user_id WHERE t.id = ? LIMIT 1");
    $rowU->execute([$req['team_id']]);
    $gamesUserId = (int)($rowU->fetchColumn() ?? 0) ?: null;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE tapas_requests SET status='approved', processed_at=NOW() WHERE id=?")
            ->execute([$requestId]);

        if ($actionType === 'tapa') {
            $pdo->prepare("UPDATE players SET tapa_count = tapa_count + 1 WHERE id = ?")
                ->execute([$req['player_id']]);
        } else {
            $pdo->prepare("UPDATE players SET badge_name = ? WHERE id = ?")
                ->execute([$badgeName, $req['player_id']]);
        }
        /*
         * É AQUI QUE A BADGE É CONSUMIDA — na aplicação, não no pedido.
         *
         * Enquanto o pedido espera, ela continua no inventário do GM: se for
         * recusado, ele não perde nada. O que impede pedir duas com uma badge
         * só é a checagem no request_badge (pendentes < compradas).
         *
         * O saldo antigo de tapas só é debitado em pedido do tipo 'tapa'. Para
         * badge, a moeda é o item da loja — descontar dos dois seria cobrar
         * duas vezes pela mesma coisa.
         */
        if ($actionType === 'badge') {
            $donoId = 0;
            try {
                $stDono = $pdo->prepare("SELECT user_id FROM teams WHERE id = ? LIMIT 1");
                $stDono->execute([$req['team_id']]);
                $donoId = (int)$stDono->fetchColumn();
            } catch (Throwable $e) { /* segue: a badge é aplicada de todo jeito */ }
            // Não impede a aprovação se não achar item comprado: pode ser um
            // pedido antigo, ou uma badge combinada por fora. O admin decidiu.
            if ($donoId > 0) badgeConsumir($pdo, $donoId);
        } else {
            decrementGamesTapas($pdoGames, $gamesUserId);
            $pdo->prepare("UPDATE teams SET tapas_used = tapas_used + 1 WHERE id = ?")
                ->execute([$req['team_id']]);
        }

        $pdo->commit();
        out(['success' => true, 'message' => 'Solicitação aprovada']);
    } catch (Exception $e) {
        $pdo->rollBack();
        err('Erro ao processar: ' . $e->getMessage());
    }
}

// ── POST cancel_tapa ──────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'cancel_tapa') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $requestId = (int)($body['request_id'] ?? 0);
    if (!$requestId) err('Solicitação inválida');

    $stmtT = $pdo->prepare("SELECT id FROM teams WHERE user_id = ? LIMIT 1");
    $stmtT->execute([$user['id']]);
    $team = $stmtT->fetch(PDO::FETCH_ASSOC);
    if (!$team) err('Time não encontrado');

    $rows = $pdo->prepare("DELETE FROM tapas_requests WHERE id = ? AND team_id = ? AND status = 'pending'");
    $rows->execute([$requestId, $team['id']]);

    if ($rows->rowCount() === 0) err('Solicitação não encontrada ou já processada');

    out(['success' => true, 'message' => 'Solicitação cancelada. Tapa devolvido.']);
}

// ── POST admin_reject ─────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'admin_reject') {
    if (!$podeAdmin) err('Acesso negado', 403);
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $requestId = (int)($body['request_id'] ?? 0);
    if (!$requestId) err('Solicitação inválida');

    /* Recusar também é ato de administração daquela liga. */
    $stRej = $pdo->prepare("SELECT * FROM tapas_requests WHERE id = ?");
    $stRej->execute([$requestId]);
    $pedidoRej = $stRej->fetch(PDO::FETCH_ASSOC);
    if ($pedidoRej) exigirLiga($isAdmin, $ligasDoAdmin, tapasLigaDoPedido($pdo, $pedidoRej));

    // Nada a devolver: a badge só sai do inventário quando o admin APLICA.
    // Recusado, o GM continua com ela e pode pedir de novo em outro jogador.
    $pdo->prepare("UPDATE tapas_requests SET status='rejected', processed_at=NOW() WHERE id = ? AND status='pending'")
        ->execute([$requestId]);

    out(['success' => true, 'message' => 'Solicitação rejeitada']);
}

/* ── BADGE COMPRADA NA LOJA ───────────────────────────────────────────────────
 *
 * A badge do catálogo fica guardada no inventário até o GM dizer em qual
 * jogador quer, e com que nome. Aí sim vira pedido pro admin — este é um dos
 * casos em que a aprovação existe por um motivo: o sistema sabe aplicar, mas
 * não sabe se "Clamps" cabe num pivô de 60 de defesa.
 *
 * O saldo é o próprio inventário: itens `badge` que ainda não foram usados.
 * Não existe contador paralelo pra desencontrar do que foi comprado.
 */

/**
 * Quantas badges o GM comprou e ainda não foram aplicadas.
 *
 * A BADGE SÓ É CONSUMIDA QUANDO O ADMIN APLICA, e não no pedido. Enquanto
 * espera, ela continua guardada — se o pedido for recusado não há nada a
 * devolver, porque nada saiu.
 */
function badgesCompradas(PDO $pdo, int $userId): int
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM loja_inventario
                              WHERE id_usuario = ? AND item_key = 'badge' AND usado_em IS NULL");
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Quantos pedidos de badge estão esperando o admin.
 *
 * Entra na conta do que ainda dá pra pedir: quem tem UMA badge e já pediu uma
 * não pode pedir outra, senão dois pedidos aprovados gastariam uma badge só.
 */
function badgesPendentes(PDO $pdo, int $teamId): int
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM tapas_requests
                              WHERE team_id = ? AND action_type = 'badge' AND status = 'pending'");
        $st->execute([$teamId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** O que sobra pra pedir agora: comprado menos o que já está na fila. */
function badgesDisponiveis(PDO $pdo, int $userId, int $teamId): int
{
    return max(0, badgesCompradas($pdo, $userId) - badgesPendentes($pdo, $teamId));
}

/**
 * Consome uma badge — chamado quando o admin APLICA, não quando o GM pede.
 *
 * @return bool false se não havia badge comprada (pedido antigo, ou badge dada
 *              por fora); o chamador decide se isso impede a aprovação.
 */
function badgeConsumir(PDO $pdo, int $userId): bool
{
    try {
        $st = $pdo->prepare("UPDATE loja_inventario SET usado_em = NOW(), atendido_em = NOW()
                              WHERE id_usuario = ? AND item_key = 'badge' AND usado_em IS NULL
                              ORDER BY comprado_em ASC LIMIT 1");
        $st->execute([$userId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('[tapas/consumir badge] ' . $e->getMessage());
        return false;
    }
}

if ($method === 'GET' && $action === 'badges_status') {
    $stmt = $pdo->prepare("SELECT id, league FROM teams WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user['id']]);
    $timeBadge = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $teamId = (int)($timeBadge['id'] ?? 0);
    if (!$teamId) out(['success' => true, 'disponiveis' => 0, 'pedidos' => []]);

    // "Seus pedidos" só do sprint ativo da liga — o mesmo corte por data do card do admin.
    $desdeSprint = null;
    try {
        $stSp = $pdo->prepare("SELECT start_date FROM sprints WHERE league = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $stSp->execute([(string)($timeBadge['league'] ?? '')]);
        $desdeSprint = $stSp->fetchColumn() ?: null;
    } catch (Throwable $e) { error_log('[tapas/badges sprint] ' . $e->getMessage()); }

    $st = $pdo->prepare("SELECT id, player_name, badge_name, status, created_at
                           FROM tapas_requests
                          WHERE team_id = ? AND action_type = 'badge'" . ($desdeSprint ? " AND created_at >= ?" : "") . "
                          ORDER BY created_at DESC LIMIT 20");
    $st->execute($desdeSprint ? [$teamId, $desdeSprint] : [$teamId]);

    out(['success' => true,
         'disponiveis' => badgesDisponiveis($pdo, (int)$user['id'], $teamId),
         'compradas'   => badgesCompradas($pdo, (int)$user['id']),
         'pendentes'   => badgesPendentes($pdo, $teamId),
         'pedidos' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method === 'POST' && $action === 'request_badge') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $playerId  = (int)($body['player_id'] ?? 0);
    $badgeName = trim((string)($body['badge_name'] ?? ''));
    if (!$playerId)        err('Escolha o jogador.');
    if ($badgeName === '') err('Escreva o nome da badge.');
    if (mb_strlen($badgeName) > 60) err('Nome da badge muito longo.');

    $stmtT = $pdo->prepare("SELECT id, league FROM teams WHERE user_id = ? LIMIT 1");
    $stmtT->execute([$user['id']]);
    $team = $stmtT->fetch(PDO::FETCH_ASSOC);
    if (!$team) err('Time não encontrado');

    // O jogador tem que ser DO ELENCO — senão dava pra dar badge no time dos
    // outros mandando um id qualquer.
    $stmtP = $pdo->prepare("SELECT id, name FROM players WHERE id = ? AND team_id = ?");
    $stmtP->execute([$playerId, $team['id']]);
    $player = $stmtP->fetch(PDO::FETCH_ASSOC);
    if (!$player) err('Jogador não encontrado no seu elenco');

    /*
     * Um pedido por badge comprada.
     *
     * A badge só sai do inventário quando o admin aplica, então o que limita o
     * pedido é: comprei 1 e já pedi 1 → não posso pedir outra. Sem isto, dois
     * pedidos aprovados gastariam uma badge só.
     */
    $compradas = badgesCompradas($pdo, (int)$user['id']);
    $pendentes = badgesPendentes($pdo, (int)$team['id']);
    if ($compradas <= 0) {
        err('Você não tem badge. Compre uma na loja dos Games.');
    }
    if ($pendentes >= $compradas) {
        err($pendentes === 1
            ? 'Você já tem um pedido de badge esperando. Aguarde a organização responder.'
            : "Você tem $pendentes pedidos esperando e $compradas badge(s) — aguarde a organização responder.");
    }

    $pdo->prepare("INSERT INTO tapas_requests
                    (team_id, player_id, player_name, league, status, action_type, badge_name)
                    VALUES (?, ?, ?, ?, 'pending', 'badge', ?)")
        ->execute([$team['id'], $playerId, $player['name'], $team['league'], $badgeName]);

    out(['success' => true,
         'message' => 'Pedido enviado. A organização vai avaliar.',
         'disponiveis' => badgesDisponiveis($pdo, (int)$user['id'], (int)$team['id'])]);
}

// ── POST cancel_badge ─────────────────────────────────────────────────────────
// O GM desiste de um pedido de badge que a organização ainda não respondeu.
// Não há o que devolver: a badge só sai do inventário quando o admin APLICA,
// então apagar o pedido pendente já libera a vaga pra pedir de novo.
if ($method === 'POST' && $action === 'cancel_badge') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $requestId = (int)($body['request_id'] ?? 0);
    if (!$requestId) err('Pedido inválido');

    $stmtT = $pdo->prepare("SELECT id FROM teams WHERE user_id = ? LIMIT 1");
    $stmtT->execute([$user['id']]);
    $team = $stmtT->fetch(PDO::FETCH_ASSOC);
    if (!$team) err('Time não encontrado');

    $rows = $pdo->prepare("DELETE FROM tapas_requests
                            WHERE id = ? AND team_id = ? AND action_type = 'badge' AND status = 'pending'");
    $rows->execute([$requestId, $team['id']]);
    if ($rows->rowCount() === 0) err('Esse pedido não está mais aguardando — a organização já respondeu.');

    out(['success' => true,
         'message' => 'Pedido de badge cancelado. A badge continua sua.',
         'disponiveis' => badgesDisponiveis($pdo, (int)$user['id'], (int)$team['id'])]);
}

err('Ação inválida');
