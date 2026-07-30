<?php
/**
 * Painel de Controle Centralizado (admin): visão única do que hoje está
 * espalhado em Config/Tática/Agendador — trades, free agency, janela de
 * edição de táticas, e quantos times já atualizaram o elenco na temporada
 * ativa. As ações reaproveitam os mesmos endpoints já usados nas telas
 * individuais (nada de estado duplicado).
 */
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
header('Content-Type: application/json');

requireAuth();
$user = getUserSession();
$pdo  = db();
if (!hasAdminAccess($pdo, (int)$user['id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
    exit;
}

$validLeagues = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
$league = strtoupper((string)($_GET['league'] ?? 'ELITE'));
if (!in_array($league, $validLeagues, true)) {
    echo json_encode(['success' => false, 'error' => 'Liga inválida']);
    exit;
}

// league_settings: trades/FA já são geridos em Config; só lemos aqui.
$stLs = $pdo->prepare("SELECT COALESCE(trades_enabled,1) AS trades_enabled, COALESCE(fa_enabled,1) AS fa_enabled,
                               cap_min, cap_max, max_trades
                        FROM league_settings WHERE league = ?");
$stLs->execute([$league]);
$ls = $stLs->fetch(PDO::FETCH_ASSOC) ?: ['trades_enabled' => 1, 'fa_enabled' => 1, 'cap_min' => 0, 'cap_max' => 0, 'max_trades' => 3];

// Janela de edição de táticas — mesma lógica de api/tactics.php::getEditWindow,
// duplicada aqui de propósito (tactics.php é um endpoint de execução direta,
// não uma lib para dar require).
$pdo->exec("CREATE TABLE IF NOT EXISTS tactic_edit_windows (
    league VARCHAR(20) PRIMARY KEY,
    daily_cutoff_time TIME NOT NULL DEFAULT '17:00:00',
    manual_open_until DATETIME NULL,
    manual_closed TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$stW = $pdo->prepare('SELECT * FROM tactic_edit_windows WHERE league = ?');
$stW->execute([$league]);
$w = $stW->fetch(PDO::FETCH_ASSOC);
if (!$w) {
    $pdo->prepare('INSERT IGNORE INTO tactic_edit_windows (league) VALUES (?)')->execute([$league]);
    $w = ['daily_cutoff_time' => '17:00:00', 'manual_open_until' => null, 'manual_closed' => 0];
}
$agora = date('Y-m-d H:i:s');
$manualOpenUntil = $w['manual_open_until'] ?? null;
if ($manualOpenUntil && $manualOpenUntil > $agora) {
    $tacticOpen = true; $tacticReason = 'aberta manualmente pelo admin';
} elseif (!empty($w['manual_closed'])) {
    $tacticOpen = false; $tacticReason = 'fechada manualmente pelo admin';
} else {
    $nowTime = date('H:i:s');
    $tacticOpen = $nowTime < $w['daily_cutoff_time'];
    $tacticReason = $tacticOpen ? null : ('fechada após ' . substr($w['daily_cutoff_time'], 0, 5));
}

// Times atualizados na temporada ativa (mesmo sinal que já bloqueia trocas).
$stSeason = $pdo->prepare("SELECT id FROM seasons WHERE league = ? AND (status IS NULL OR status <> 'completed') ORDER BY id DESC LIMIT 1");
$stSeason->execute([$league]);
$seasonId = $stSeason->fetchColumn();
$seasonId = $seasonId ? (int)$seasonId : null;

$draftDone = draftConcluidoNaTemporada($pdo, $seasonId);

$stTeams = $pdo->prepare("SELECT id, CONCAT(city,' ',name) AS nm FROM teams WHERE league = ? ORDER BY city, name");
$stTeams->execute([$league]);
$teams = $stTeams->fetchAll(PDO::FETCH_ASSOC);

$updatedCount = 0;
$notUpdated = [];
foreach ($teams as $t) {
    if (elencoAtualizadoNaTemporada($pdo, (int)$t['id'], $seasonId)) {
        $updatedCount++;
    } else {
        $notUpdated[] = $t['nm'];
    }
}

echo json_encode([
    'success' => true,
    'league' => $league,
    'trades_enabled' => (int)$ls['trades_enabled'],
    'fa_enabled' => (int)$ls['fa_enabled'],
    'cap_min' => (int)($ls['cap_min'] ?? 0),
    'cap_max' => (int)($ls['cap_max'] ?? 0),
    'max_trades' => (int)($ls['max_trades'] ?? 3),
    'tactic_window' => [
        'open' => $tacticOpen,
        'reason' => $tacticReason,
        'daily_cutoff_time' => substr($w['daily_cutoff_time'], 0, 5),
    ],
    'draft_concluido' => $draftDone,
    'teams_total' => count($teams),
    'teams_updated' => $updatedCount,
    'teams_not_updated' => $notUpdated,
]);
