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

// Janela de edição de táticas. A regra mora em backend/tatica_janela.php —
// antes era copiada aqui, e quando a janela virou liga/desliga esta cópia
// ficou pra trás: o painel seguia fechando sozinho às 17h.
require_once __DIR__ . '/../backend/tatica_janela.php';
taticaGarantirTabelaJanela($pdo);
$janelaTatica = taticaJanela($pdo, $league);
$tacticOpen   = $janelaTatica['open'];
$tacticReason = $janelaTatica['reason'];

// Times atualizados na temporada ativa (mesmo sinal que já bloqueia trocas).
$stSeason = $pdo->prepare("SELECT id FROM seasons WHERE league = ? AND (status IS NULL OR status <> 'completed') ORDER BY id DESC LIMIT 1");
$stSeason->execute([$league]);
$seasonId = $stSeason->fetchColumn();
$seasonId = $seasonId ? (int)$seasonId : null;

$draftDone = draftConcluidoNaTemporada($pdo, $seasonId);

$stTeams = $pdo->prepare("SELECT id, CONCAT(city,' ',name) AS nm FROM teams WHERE league = ? ORDER BY city, name");
$stTeams->execute([$league]);
$teams = $stTeams->fetchAll(PDO::FETCH_ASSOC);

/* A MESMA CONTA DA BOLINHA DA ABA TIMES, e agora também aqui.
   Este painel contava pelo `elencoAtualizadoNaTemporada`, que aceita uma linha
   em player_season_log como prova de atualização — e o log da temporada nasce
   cheio por outros caminhos. Resultado: o painel dizia "30 de 30" enquanto a
   aba Times mostrava relógios amarelos, e o /timesstatus do bot (que já usa a
   régua da bolinha) discordava dos dois.

   Uma conta só, em leagueRosterUpdateStatus: bolinha, checklist, este painel e
   o bot respondem igual. */
require_once __DIR__ . '/../backend/league_cap.php';
$rosterStatus = $seasonId ? leagueRosterUpdateStatus($pdo, $league, $seasonId) : null;
$updatedCount = $rosterStatus['done'] ?? 0;
$notUpdated   = $rosterStatus['pendentes'] ?? [];

echo json_encode([
    'success' => true,
    'league' => $league,
    'trades_enabled' => (int)$ls['trades_enabled'],
    'fa_enabled' => (int)$ls['fa_enabled'],
    'cap_min' => (int)($ls['cap_min'] ?? 0),
    'cap_max' => (int)($ls['cap_max'] ?? 0),
    'max_trades' => (int)($ls['max_trades'] ?? 3),
    // Sem prazo nenhum: a janela e liga/desliga.
    'tactic_window' => [
        'open' => $tacticOpen,
        'reason' => $tacticReason,
    ],
    'draft_concluido' => $draftDone,
    'teams_total' => count($teams),
    'teams_updated' => $updatedCount,
    'teams_not_updated' => $notUpdated,
]);
