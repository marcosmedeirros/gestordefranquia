<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch() ?: null;
$teamId = $team['id'] ?? null;

// Buscar limite de trades da liga
$maxTrades = 10; // Default
$tradesEnabled = 1; // Default: ativas
if ($team) {
    $stmtSettings = $pdo->prepare('SELECT max_trades, trades_enabled FROM league_settings WHERE league = ?');
    $stmtSettings->execute([$team['league']]);
    $settings = $stmtSettings->fetch();
    $maxTrades = $settings['max_trades'] ?? 10;
    $tradesEnabled = $settings['trades_enabled'] ?? 1;
}

$currentSeasonYear = null;
if (!empty($team['league'])) {
  try {
    $stmtSeason = $pdo->prepare('
      SELECT s.season_number, s.year, sp.start_year
      FROM seasons s
      LEFT JOIN sprints sp ON s.sprint_id = sp.id
      WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ("completed"))
      ORDER BY s.created_at DESC
      LIMIT 1
    ');
    $stmtSeason->execute([$team['league']]);
    $currentSeason = $stmtSeason->fetch();
    if ($currentSeason && isset($currentSeason['start_year'], $currentSeason['season_number'])) {
      $currentSeasonYear = (int)$currentSeason['start_year'] + (int)$currentSeason['season_number'] - 1;
    } elseif ($currentSeason && isset($currentSeason['year'])) {
      $currentSeasonYear = (int)$currentSeason['year'];
    }
  } catch (Exception $e) {
    $currentSeasonYear = null;
  }
}
if (!$currentSeasonYear) {
  $currentSeasonYear = (int)date('Y');
}

function syncTeamTradeCounter(PDO $pdo, int $teamId): int
{
    try {
        $stmt = $pdo->prepare('SELECT current_cycle, trades_cycle, trades_used FROM teams WHERE id = ?');
        $stmt->execute([$teamId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 0;
        }
        $currentCycle = (int)($row['current_cycle'] ?? 0);
        $tradesCycle = (int)($row['trades_cycle'] ?? 0);
        $tradesUsed = (int)($row['trades_used'] ?? 0);

    // Se trades_cycle ainda não estiver inicializado, alinhar com current_cycle e não zerar o contador.
    if ($currentCycle > 0 && $tradesCycle <= 0) {
      $pdo->prepare('UPDATE teams SET trades_cycle = ? WHERE id = ?')
        ->execute([$currentCycle, $teamId]);
      return $tradesUsed;
    }

    // Só zera quando já existe um ciclo anterior registrado e ele mudou
    if ($currentCycle > 0 && $tradesCycle > 0 && $tradesCycle !== $currentCycle) {
            $pdo->prepare('UPDATE teams SET trades_used = 0, trades_cycle = ? WHERE id = ?')
                ->execute([$currentCycle, $teamId]);
            return 0;
        }

        return $tradesUsed;
    } catch (Exception $e) {
        return 0;
    }
}

// Contador de trades (mostrar exatamente o campo trades_used do time logado)
$tradeCount = (int)($team['trades_used'] ?? 0);
$remainingTrades = max(0, (int)$maxTrades - $tradeCount);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <?php include __DIR__ . '/includes/head-pwa.php'; ?>
  <title>Trades - FBA Manager</title>
  
  <!-- PWA Meta Tags -->
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#0a0a0c">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="FBA Manager">
  <link rel="apple-touch-icon" href="/img/icon-192.png">
  
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/css/styles.css" />
  <style>
    .nav-tabs {
      border-bottom: 2px solid var(--fba-border);
    }
    .nav-tabs .nav-link {
      background: transparent;
      border: none;
      color: var(--fba-text-muted);
      font-weight: 500;
      padding: 12px 24px;
      transition: all 0.3s ease;
      border-bottom: 3px solid transparent;
      margin-bottom: -2px;
    }
    .nav-tabs .nav-link:hover {
      background: rgba(252, 0, 37, 0.12);
      color: var(--fba-brand);
      border-bottom-color: var(--fba-brand);
    }
    .nav-tabs .nav-link.active {
      background: rgba(252, 0, 37, 0.16);
      color: var(--fba-brand);
      border-bottom-color: var(--fba-brand);
      font-weight: 600;
    }

    .trade-list-panel {
      background: var(--fba-panel);
      border: 1px solid var(--fba-border);
      border-radius: 10px;
      padding: 20px;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
    }

    .trade-list-search .form-control,
    .trade-list-search .form-select {
      background: var(--fba-panel-2);
      color: var(--fba-text);
      border: 1px solid var(--fba-border);
    }

    .trade-list-search .form-control:focus,
    .trade-list-search .form-select:focus {
      border-color: var(--fba-brand);
      box-shadow: 0 0 0 0.25rem rgba(252, 0, 37, 0.25);
    }

    .player-card {
      background: var(--fba-panel-2);
      border: 1px solid var(--fba-border);
      border-radius: 10px;
      padding: 16px;
      margin-bottom: 12px;
      transition: transform 0.2s ease, border 0.2s ease;
    }

    .player-card:hover {
      border-color: var(--fba-orange);
      transform: translateY(-2px);
      box-shadow: 0 10px 20px rgba(241, 117, 7, 0.25);
    }

    .player-name {
      font-weight: 600;
      color: var(--fba-text);
      font-size: 1.05rem;
    }

    .player-meta {
      font-size: 0.9rem;
      color: var(--fba-text-muted);
    }

    /* Ocultar seção de pick swaps (temporariamente) */
    #pick-swaps,
    .pick-swaps,
    .pick-swap {
      display: none !important;
    }

    .team-chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid var(--fba-border);
      border-radius: 30px;
      padding: 6px 14px;
    }

    .team-chip-badge {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.1);
      border: 1px solid rgba(255,255,255,0.2);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 0.85rem;
      letter-spacing: 0.05em;
      color: var(--fba-text);
    }

    #playersList .alert {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid var(--fba-border);
      color: var(--fba-text);
    }

    .pick-selector {
      background: rgba(255, 255, 255, 0.02);
      border: 1px solid var(--fba-border);
      border-radius: 12px;
      padding: 16px;
      box-shadow: inset 0 0 20px rgba(0, 0, 0, 0.2);
    }

    .pick-options {
      max-height: 220px;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 10px;
      margin-bottom: 14px;
    }

    .pick-option-card {
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: var(--fba-dark-bg);
      border: 1px solid var(--fba-border);
      border-radius: 10px;
      padding: 10px 14px;
      transition: border 0.2s ease, transform 0.2s ease;
    }

    .pick-option-card:hover {
      border-color: var(--fba-orange);
      transform: translateY(-1px);
    }

    .pick-option-card.is-selected {
      opacity: 0.6;
    }

    .reaction-bar {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      align-items: center;
    }

    .reaction-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 999px;
      border: 1px solid var(--fba-border);
      background: rgba(255, 255, 255, 0.04);
      color: var(--fba-text);
      font-size: 0.85rem;
      cursor: pointer;
      transition: border 0.2s ease, background 0.2s ease;
    }

    .reaction-chip.active {
      border-color: var(--fba-orange);
      background: rgba(241, 117, 7, 0.15);
    }

    .reaction-count {
      font-size: 0.75rem;
      color: var(--fba-text-muted);
    }

    .pick-title {
      color: var(--fba-text);
      font-weight: 600;
      margin-bottom: 2px;
    }

    .pick-meta {
      font-size: 0.85rem;
      color: var(--fba-text-muted);
    }

    .selected-picks {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .selected-pick-card {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      justify-content: space-between;
      align-items: center;
      background: rgba(241, 117, 7, 0.08);
      border: 1px solid rgba(241, 117, 7, 0.6);
      border-radius: 10px;
      padding: 12px 14px;
    }

    .selected-pick-info {
      flex: 1;
      min-width: 200px;
    }

    .selected-pick-actions {
      display: flex;
      gap: 10px;
      align-items: center;
    }

    .pick-protection-select {
      background: #ffffff;
      color: #000000;
      border: 1px solid var(--fba-orange);
      border-radius: 8px;
      padding: 6px 10px;
      min-width: 140px;
    }

    .pick-protection-select:hover,
    .pick-protection-select:focus {
      background: #ffffff;
      color: #000000;
      box-shadow: none;
    }

    .pick-protection-select option {
      color: #000000;
    }

    .pick-empty-state {
      text-align: center;
      padding: 12px;
      background: rgba(255, 255, 255, 0.03);
      border: 1px dashed var(--fba-border);
      border-radius: 10px;
      color: var(--fba-text-muted);
      font-size: 0.9rem;
    }

    /* === PROFESSIONAL TRADES REDESIGN === */
    .trades-page .dashboard-content {
      padding: 32px;
    }

    .trades-shell {
      max-width: 100%;
      margin: 0 auto;
    }

    /* Header Section */
    .trades-header {
      background: linear-gradient(135deg, rgba(20, 20, 30, 0.8) 0%, rgba(30, 20, 50, 0.4) 100%);
      border: 1px solid rgba(252, 0, 37, 0.15);
      border-radius: 20px;
      padding: 28px;
      margin-bottom: 28px;
      -webkit-backdrop-filter: blur(12px);
      backdrop-filter: blur(12px);
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    }

    .trades-header .page-header {
      margin-bottom: 24px;
    }

    .trades-header h1 {
      font-size: 2rem;
      font-weight: 800;
      letter-spacing: -0.5px;
      margin-bottom: 0;
    }

    .trades-stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
    }

    .trade-stat-card {
      background: color-mix(in srgb, var(--fba-panel) 85%, transparent);
      border: 1px solid rgba(252, 0, 37, 0.1);
      border-radius: 16px;
      padding: 16px;
      transition: all 0.3s cubic-bezier(0.2, 0.8, 0.2, 1);
      position: relative;
      overflow: hidden;
    }

    .trade-stat-card::before {
      content: "";
      position: absolute;
      inset: 0;
      background: radial-gradient(circle at 100% 0%, rgba(252, 0, 37, 0.1), transparent 60%);
      opacity: 0;
      transition: opacity 0.3s ease;
    }

    .trade-stat-card:hover {
      border-color: rgba(252, 0, 37, 0.25);
      transform: translateY(-2px);
      box-shadow: 0 12px 32px rgba(252, 0, 37, 0.1);
    }

    .trade-stat-card:hover::before {
      opacity: 1;
    }

    .trade-stat-label {
      color: var(--fba-text-muted);
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      margin-bottom: 8px;
      display: block;
    }

    .trade-stat-value {
      color: var(--fba-text);
      font-size: 2rem;
      font-weight: 800;
      line-height: 1;
      letter-spacing: -0.02em;
    }

    .trade-stat-value.is-ok {
      color: #10b981;
    }

    .trade-stat-value.is-alert {
      color: #f97316;
    }

    /* Tabs */
    .trades-page .nav-tabs {
      border-bottom: 2px solid var(--fba-border);
      gap: 12px;
      flex-wrap: nowrap;
      overflow-x: auto;
      padding-bottom: 0;
      margin-bottom: 28px;
      scrollbar-width: none;
    }

    .trades-page .nav-tabs::-webkit-scrollbar {
      display: none;
    }

    .trades-page .nav-tabs .nav-item {
      margin-bottom: 0;
    }

    .trades-page .nav-tabs .nav-link {
      border: none;
      background: transparent;
      color: var(--fba-text-muted);
      border-bottom: 3px solid transparent;
      border-radius: 0;
      white-space: nowrap;
      font-size: 0.95rem;
      font-weight: 600;
      padding: 12px 16px;
      margin: 0;
      transition: all 0.3s ease;
      position: relative;
    }

    .trades-page .nav-tabs .nav-link:hover {
      color: var(--fba-text);
      border-bottom-color: rgba(252, 0, 37, 0.4);
    }

    .trades-page .nav-tabs .nav-link.active {
      color: var(--fba-brand);
      border-bottom-color: var(--fba-brand);
    }

    /* Tab Content */
    .trades-page .tab-content {
      animation: fadeInUp 0.4s cubic-bezier(0.2, 0.8, 0.2, 1);
    }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(10px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* Light theme refinements */
    :root[data-theme="light"] .trades-header {
      background: linear-gradient(135deg, rgba(252, 0, 37, 0.04), rgba(255, 255, 255, 0.2));
      border-color: rgba(227, 230, 238, 0.8);
    }

    :root[data-theme="light"] .trade-stat-card {
      background: rgba(255, 255, 255, 0.7);
      border-color: rgba(227, 230, 238, 0.9);
    }

    :root[data-theme="light"] .trade-stat-card:hover {
      border-color: rgba(252, 0, 37, 0.3);
      box-shadow: 0 8px 24px rgba(20, 20, 30, 0.08);
    }

    :root[data-theme="light"] .trade-card {
      background: rgba(255, 255, 255, 0.85);
      border-color: rgba(227, 230, 238, 0.9);
    }

    :root[data-theme="light"] .trade-card:hover {
      background: rgba(255, 255, 255, 0.95);
    }

    :root[data-theme="light"] .player-trade-card {
      background: rgba(255, 255, 255, 0.9);
      border-color: rgba(227, 230, 238, 0.9);
    }

    :root[data-theme="light"] .player-trade-card:hover {
      background: rgba(255, 255, 255, 0.98);
    }

    :root[data-theme="light"] .trade-team-badge {
      background: rgba(252, 0, 37, 0.06);
      border-color: rgba(252, 0, 37, 0.2);
    }

    :root[data-theme="light"] .trade-side {
      background: rgba(20, 20, 30, 0.04);
    }

    :root[data-theme="light"] .trades-page .nav-tabs {
      border-bottom-color: rgba(227, 230, 238, 0.9);
    }

    :root[data-theme="light"] .trades-page .nav-tabs .nav-link {
      color: rgba(91, 98, 112, 0.8);
    }

    :root[data-theme="light"] .trades-page .nav-tabs .nav-link:hover {
      border-bottom-color: rgba(252, 0, 37, 0.3);
    }

    :root[data-theme="light"] .trades-page .nav-tabs .nav-link.active {
      color: var(--fba-brand);
    }

    .trades-page .tab-pane {
      animation: fadeInUp 0.4s cubic-bezier(0.2, 0.8, 0.2, 1);
    }

    .trades-page .trade-list-panel {
      background: transparent;
      border: 0;
      border-radius: 0;
      padding: 0;
      box-shadow: none;
    }

    /* Trade Cards Grid */
    .trades-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 16px;
    }

    .trade-card {
      background: color-mix(in srgb, var(--fba-panel) 90%, transparent);
      border: 1px solid var(--fba-border);
      border-radius: 14px;
      padding: 16px;
      transition: all 0.3s cubic-bezier(0.2, 0.8, 0.2, 1);
      position: relative;
      overflow: hidden;
    }

    .trade-card::before {
      content: "";
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(252, 0, 37, 0.05), transparent);
      transition: left 0.5s ease;
    }

    .trade-card:hover {
      border-color: rgba(252, 0, 37, 0.3);
      box-shadow: 0 16px 40px rgba(252, 0, 37, 0.12);
      transform: translateY(-2px);
    }

    .trade-card:hover::before {
      left: 100%;
    }

    .trade-card-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      margin-bottom: 12px;
    }

    .trade-card-teams {
      display: flex;
      align-items: center;
      gap: 12px;
      flex: 1;
    }

    .trade-team-badge {
      display: flex;
      align-items: center;
      gap: 8px;
      background: rgba(252, 0, 37, 0.08);
      border: 1px solid rgba(252, 0, 37, 0.15);
      border-radius: 12px;
      padding: 6px 12px;
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--fba-text);
    }

    .trade-team-arrow {
      color: var(--fba-brand);
      font-size: 1rem;
    }

    .trade-card-status {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      border-radius: 12px;
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }

    .trade-status-pending {
      background: rgba(251, 146, 60, 0.1);
      color: #ea580c;
    }

    .trade-status-accepted {
      background: rgba(16, 185, 129, 0.1);
      color: #10b981;
    }

    .trade-status-rejected {
      background: rgba(239, 68, 68, 0.1);
      color: #ef4444;
    }

    .trade-card-body {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
      margin-bottom: 12px;
    }

    .trade-side {
      padding: 12px;
      background: rgba(0, 0, 0, 0.2);
      border-radius: 10px;
    }

    .trade-side-title {
      font-size: 0.8rem;
      color: var(--fba-text-muted);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-bottom: 8px;
      font-weight: 600;
    }

    .trade-items {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .trade-item {
      font-size: 0.9rem;
      color: var(--fba-text);
      padding: 6px 0;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    .trade-item:last-child {
      border-bottom: none;
    }

    .trade-item-name {
      font-weight: 600;
      color: var(--fba-text);
    }

    .trade-item-meta {
      font-size: 0.8rem;
      color: var(--fba-text-muted);
      display: block;
    }

    .trade-card-footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding-top: 12px;
      border-top: 1px solid rgba(255, 255, 255, 0.06);
      font-size: 0.85rem;
      color: var(--fba-text-muted);
    }

    /* Players Trade List Grid */
    .players-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: 12px;
    }

    .player-trade-card {
      background: color-mix(in srgb, var(--fba-panel) 92%, transparent);
      border: 1px solid var(--fba-border);
      border-radius: 12px;
      padding: 12px;
      transition: all 0.3s cubic-bezier(0.2, 0.8, 0.2, 1);
      cursor: pointer;
    }

    .player-trade-card:hover {
      border-color: var(--fba-brand);
      transform: translateY(-4px);
      box-shadow: 0 12px 28px rgba(252, 0, 37, 0.15);
      background: color-mix(in srgb, var(--fba-panel) 95%, transparent);
    }

    .player-trade-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      margin-bottom: 8px;
    }

    .player-trade-name {
      font-weight: 700;
      color: var(--fba-text);
      font-size: 0.95rem;
      flex: 1;
    }

    .player-trade-ovr {
      background: rgba(252, 0, 37, 0.15);
      color: var(--fba-brand);
      padding: 2px 8px;
      border-radius: 6px;
      font-size: 0.8rem;
      font-weight: 700;
    }

    .player-trade-info {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 6px;
      font-size: 0.8rem;
      color: var(--fba-text-muted);
    }

    .player-trade-stat {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .player-trade-label {
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      opacity: 0.8;
    }

    .player-trade-value {
      font-weight: 600;
      color: var(--fba-text);
    }

    .player-trade-team {
      margin-top: 8px;
      padding-top: 8px;
      border-top: 1px solid rgba(255, 255, 255, 0.06);
      font-size: 0.8rem;
      color: var(--fba-text-muted);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    /* Modal Refinements */
    .trades-page .modal-content {
      border-radius: 20px;
      overflow: hidden;
      box-shadow: 0 40px 80px rgba(0, 0, 0, 0.4);
      border: 1px solid rgba(252, 0, 37, 0.15);
    }

    .trades-page .modal.fade .modal-dialog {
      transform: translateY(30px) scale(0.95);
      opacity: 0;
      transition: all 0.4s cubic-bezier(0.2, 0.8, 0.2, 1);
    }

    .trades-page .modal.show .modal-dialog {
      transform: translateY(0) scale(1);
      opacity: 1;
    }

    .trades-page .pick-selector {
      border-radius: 12px;
      background: transparent;
      border: 1px solid var(--fba-border);
    }

    .trades-page .page-actions .btn {
      min-height: 44px;
      border-radius: 12px;
      font-weight: 600;
    }

    /* Responsividade */
    @media (max-width: 1024px) {
      .trades-header {
        padding: 20px;
      }

      .trades-stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .players-grid {
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
      }
    }

    @media (max-width: 768px) {
      .trades-page .dashboard-content {
        padding: 16px;
      }

      .trades-header {
        padding: 16px;
        margin-bottom: 16px;
        border-radius: 16px;
      }

      .trades-header h1 {
        font-size: 1.5rem;
      }

      .trades-stats-grid {
        grid-template-columns: 1fr;
      }

      .trade-stat-value {
        font-size: 1.75rem;
      }

      .trades-page .nav-tabs {
        margin-bottom: 16px;
        gap: 8px;
      }

      .trades-page .nav-tabs .nav-link {
        font-size: 0.85rem;
        padding: 10px 12px;
      }

      .trade-card-body {
        grid-template-columns: 1fr;
      }

      .players-grid {
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
      }

      .modal-dialog {
        margin: 10px !important;
      }
    }

    @media (max-width: 480px) {
      .trades-stats-grid {
        gap: 12px;
      }

      .trade-stat-value {
        font-size: 1.5rem;
      }

      .players-grid {
        grid-template-columns: 1fr;
      }

      .page-actions {
        flex-direction: column;
      }

      .page-actions .btn {
        width: 100%;
      }
    }
  </style>
</head>
<body class="trades-page">
  <!-- Sidebar -->
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <!-- Main Content -->
  <div class="dashboard-content">
    <div class="trades-shell">
      <div class="trades-header">
        <div class="page-header" style="margin-bottom: 0;">
          <div style="flex: 1;">
            <h1 class="text-white mb-2">
              <i class="bi bi-arrow-left-right me-2 text-orange" style="font-size: 1.8rem;"></i>Negociações
            </h1>
            <p class="text-muted mb-0" style="font-size: 0.9rem;">Gerencie todas as suas trocas de jogadores e picks</p>
          </div>
          <div class="d-flex flex-wrap gap-2" style="align-items: flex-start;">
            <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
              <div class="d-flex align-items-center gap-2">
                <div class="form-check form-switch" style="margin: 0;">
                  <input class="form-check-input" type="checkbox" role="switch" id="tradesStatusToggle" <?= ($tradesEnabled ?? 1) == 1 ? 'checked' : '' ?>>
                  <label class="form-check-label text-muted" for="tradesStatusToggle" style="font-size: 0.85rem;">Ativar</label>
                </div>
                <span id="tradesStatusBadge" class="badge" style="<?= ($tradesEnabled ?? 1) == 1 ? 'background-color: #10b981;' : 'background-color: #ef4444;' ?> font-size: 0.8rem;">
                  <?= ($tradesEnabled ?? 1) == 1 ? 'Ativas' : 'Bloqueadas' ?>
                </span>
              </div>
            <?php endif; ?>
            <?php if ($tradesEnabled == 0): ?>
              <button class="btn btn-sm" style="background: rgba(100, 100, 120, 0.3); color: var(--fba-text-muted); border-radius: 10px; padding: 6px 12px;" disabled>
                <i class="bi bi-lock-fill me-1"></i>Bloqueadas
              </button>
            <?php else: ?>
              <button class="btn btn-sm btn-orange" data-bs-toggle="modal" data-bs-target="#proposeTradeModal" style="border-radius: 10px; min-width: 120px;" <?= $tradeCount >= $maxTrades ? 'disabled' : '' ?>>
                <i class="bi bi-plus-circle me-1"></i>Nova
              </button>
              <button class="btn btn-sm btn-outline-orange" data-bs-toggle="modal" data-bs-target="#multiTradeModal" style="border-radius: 10px; min-width: 120px;" <?= $tradeCount >= $maxTrades ? 'disabled' : '' ?>>
                <i class="bi bi-people-fill me-1"></i>Múltipla
              </button>
            <?php endif; ?>
          </div>
        </div>

        <div class="trades-stats-grid" style="margin-top: 24px;">
          <div class="trade-stat-card">
            <span class="trade-stat-label">Usadas</span>
            <div class="trade-stat-value"><?= htmlspecialchars((string)$tradeCount) ?></div>
          </div>
          <div class="trade-stat-card">
            <span class="trade-stat-label">Restantes</span>
            <div class="trade-stat-value <?= $remainingTrades > 0 ? 'is-ok' : 'is-alert' ?>"><?= htmlspecialchars((string)$remainingTrades) ?></div>
          </div>
          <div class="trade-stat-card">
            <span class="trade-stat-label">Limite</span>
            <div class="trade-stat-value"><?= htmlspecialchars((string)$maxTrades) ?></div>
          </div>
        </div>
      </div>

    <?php if (!$teamId): ?>
      <div class="alert alert-warning">Você ainda não possui um time.</div>
    <?php else: ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs" id="tradesTabs" role="tablist" style="margin-bottom: 24px;">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="received-tab" data-bs-toggle="tab" data-bs-target="#received" type="button">
          <i class="bi bi-inbox-fill me-2"></i>Recebidas
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="sent-tab" data-bs-toggle="tab" data-bs-target="#sent" type="button">
          <i class="bi bi-send-fill me-2"></i>Enviadas
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button">
          <i class="bi bi-clock-history me-2"></i>Histórico
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="league-tab" data-bs-toggle="tab" data-bs-target="#league" type="button">
          <i class="bi bi-trophy me-2"></i>Liga
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="rumors-tab" data-bs-toggle="tab" data-bs-target="#rumors" type="button">
          <i class="bi bi-megaphone me-2"></i>Rumores
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="trade-list-tab" data-bs-toggle="tab" data-bs-target="#trade-list" type="button">
          <i class="bi bi-list-stars me-2"></i>Disponíveis
        </button>
      </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="tradesTabContent">
      <!-- Trades Recebidas -->
      <div class="tab-pane fade show active" id="received" role="tabpanel">
        <div id="receivedTradesList" class="trades-grid"></div>
      </div>

      <!-- Trades Enviadas -->
      <div class="tab-pane fade" id="sent" role="tabpanel">
        <div id="sentTradesList" class="trades-grid"></div>
      </div>

      <!-- Histórico -->
      <div class="tab-pane fade" id="history" role="tabpanel">
        <div id="historyTradesList" class="trades-grid"></div>
      </div>

      <!-- Todas as trades da liga -->
      <div class="tab-pane fade" id="league" role="tabpanel">
        <div class="mb-4">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
              <h5 class="text-white mb-0" style="font-weight: 700; font-size: 1.2rem;">
                <i class="bi bi-trophy me-2 text-orange"></i>Trocas da Liga
              </h5>
              <p class="text-muted mb-0 mt-2" style="font-size: 0.85rem;">Histórico completo de negociações aceitas</p>
            </div>
            <span class="badge bg-secondary" id="leagueTradesCount" style="font-size: 0.9rem; padding: 8px 12px;">0</span>
          </div>
          <div class="d-flex flex-column flex-md-row gap-2 mb-4">
            <input type="text" class="form-control" id="leagueTradesSearch" placeholder="Buscar jogador..." style="flex: 1;">
            <select class="form-select" id="leagueTradesTeamFilter" style="flex: 0 0 auto; min-width: 180px;">
              <option value="">Todos os times</option>
            </select>
          </div>
          <div id="leagueTradesList" class="trades-grid"></div>
        </div>
      </div>

      <!-- Rumores (GMs e comentários do Admin) -->
      <div class="tab-pane fade" id="rumors" role="tabpanel">
        <div class="mb-4">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
              <h5 class="text-white mb-0" style="font-weight: 700; font-size: 1.2rem;">
                <i class="bi bi-megaphone me-2 text-orange"></i>Conversas da Liga
              </h5>
              <p class="text-muted mb-0 mt-2" style="font-size: 0.85rem;">Comentários e rumores sobre possíveis negociações</p>
            </div>
            <span class="badge bg-secondary" id="rumorsCount" style="font-size: 0.9rem; padding: 8px 12px;">0</span>
          </div>

          <!-- Comentários do Admin -->
          <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="text-white mb-0" style="font-weight: 600;">
                <i class="bi bi-pin-angle-fill me-2 text-orange"></i>Avisos da Administração
              </h6>
              <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
              <button class="btn btn-sm btn-outline-orange" id="addAdminCommentBtn" style="border-radius: 10px;">
                <i class="bi bi-plus-lg me-1"></i>Adicionar
              </button>
              <?php endif; ?>
            </div>
            <div id="adminCommentsList" class="m-0"></div>
          </div>

          <!-- Nova publicação -->
          <div style="background: rgba(20, 20, 30, 0.5); border: 1px solid var(--fba-border); border-radius: 12px; padding: 16px; margin-bottom: 24px;">
            <label class="form-label text-white" style="font-weight: 600; margin-bottom: 8px;">Seu comentário</label>
            <textarea class="form-control" id="rumorContent" rows="2" placeholder="Procuro SG com OVR 80+ ou vendo PF..." style="border-radius: 10px;"></textarea>
            <div class="d-flex justify-content-end mt-2">
              <button class="btn btn-orange" id="submitRumorBtn" style="border-radius: 10px; min-width: 140px;">
                <i class="bi bi-megaphone-fill me-1"></i>Publicar
              </button>
            </div>
          </div>

          <!-- Lista de rumores -->
          <div id="rumorsList"></div>
        </div>
      </div>

      <!-- Trade List (Disponíveis para troca na sua liga) -->
      <div class="tab-pane fade" id="trade-list" role="tabpanel">
        <div class="mb-4">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
              <h5 class="text-white mb-0" style="font-weight: 700; font-size: 1.2rem;">
                <i class="bi bi-list-stars me-2 text-orange"></i>Jogadores para Troca
              </h5>
              <p class="text-muted mb-0 mt-2" style="font-size: 0.85rem;">Todos os atletas marcados como disponíveis na sua liga</p>
            </div>
            <span class="badge bg-secondary" id="countBadge" style="font-size: 0.9rem; padding: 8px 12px;">0 jogadores</span>
          </div>
          <div class="d-flex flex-column flex-md-row gap-2 mb-4">
            <input type="text" class="form-control" id="searchInput" placeholder="Procurar por nome..." style="flex: 1;">
            <select class="form-select" id="sortSelect" style="flex: 0 0 auto; min-width: 180px;">
              <option value="ovr_desc">OVR (Maior primeiro)</option>
              <option value="ovr_asc">OVR (Menor primeiro)</option>
              <option value="name_asc">Nome (A-Z)</option>
              <option value="name_desc">Nome (Z-A)</option>
              <option value="age_asc">Idade (Menor)</option>
              <option value="age_desc">Idade (Maior)</option>
              <option value="position_asc">Posição (A-Z)</option>
              <option value="position_desc">Posição (Z-A)</option>
              <option value="team_asc">Time (A-Z)</option>
              <option value="team_desc">Time (Z-A)</option>
            </select>
          </div>
          <div id="playersList" class="players-grid"></div>
        </div>
      </div>
    </div>

    <?php endif; ?>
    </div>
  </div>

  <!-- Modal: Propor Trade -->
  <div class="modal fade" id="proposeTradeModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
      <div class="modal-content bg-dark-panel border-orange">
        <div class="modal-header border-bottom border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-arrow-left-right me-2 text-orange"></i>Propor Trade</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="proposeTradeForm">
            <!-- Selecionar time -->
            <div class="mb-4">
              <label class="form-label text-white fw-bold">Para qual time?</label>
              <select class="form-select bg-dark text-white border-orange" id="targetTeam" required>
                <option value="">Selecione...</option>
              </select>
            </div>

            <div class="row">
              <!-- O que você oferece -->
              <div class="col-md-6">
                <h6 class="text-orange mb-3">Você oferece</h6>
                <div class="mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label text-white mb-0">Jogadores</label>
                    <small class="text-light-gray">Adicionar e revisar seleção</small>
                  </div>
                  <div class="pick-selector">
                    <div class="pick-options" id="offerPlayersOptions"></div>
                    <div class="selected-picks" id="offerPlayersSelected"></div>
                  </div>
                </div>
                <div class="mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label text-white mb-0">Picks</label>
                    <small class="text-light-gray">Adicione picks na proposta</small>
                  </div>
                  <div class="pick-selector">
                    <div class="pick-options" id="offerPicksOptions"></div>
                    <div class="selected-picks" id="offerPicksSelected"></div>
                  </div>
                </div>
              </div>

              <!-- O que você quer -->
              <div class="col-md-6">
                <h6 class="text-orange mb-3">Você quer</h6>
                <div class="mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label text-white mb-0">Jogadores</label>
                    <small class="text-light-gray">Selecione atletas do time alvo</small>
                  </div>
                  <div class="pick-selector">
                    <div class="pick-options" id="requestPlayersOptions"></div>
                    <div class="selected-picks" id="requestPlayersSelected"></div>
                  </div>
                </div>
                <div class="mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label text-white mb-0">Picks</label>
                    <small class="text-light-gray">Selecione picks do time alvo</small>
                  </div>
                  <div class="pick-selector">
                    <div class="pick-options" id="requestPicksOptions"></div>
                    <div class="selected-picks" id="requestPicksSelected"></div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Impacto no CAP (top 8 OVR) -->
            <div class="row g-3 mb-3" id="capImpactRow">
              <div class="col-md-6">
                <div class="card bg-dark border border-orange h-100">
                  <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                      <span class="text-light">Seu time</span>
                      <span class="badge bg-secondary" id="capMyDelta">±0</span>
                    </div>
                    <div class="small text-light-gray">Atual: <span class="text-white" id="capMyCurrent">-</span></div>
                    <div class="small text-light-gray">Após trade: <span class="text-orange fw-bold" id="capMyProjected">-</span></div>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="card bg-dark border border-orange h-100">
                  <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                      <span class="text-light" id="capTargetLabel">Time alvo</span>
                      <span class="badge bg-secondary" id="capTargetDelta">±0</span>
                    </div>
                    <div class="small text-light-gray">Atual: <span class="text-white" id="capTargetCurrent">-</span></div>
                    <div class="small text-light-gray">Após trade: <span class="text-orange fw-bold" id="capTargetProjected">-</span></div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Nota -->
            <div class="mb-3">
              <label class="form-label text-white">Mensagem (opcional)</label>
              <textarea class="form-control bg-dark text-white border-orange" id="tradeNotes" rows="2"></textarea>
            </div>
          </form>
        </div>
        <div class="modal-footer border-top border-orange">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <?php if ($tradesEnabled == 0): ?>
            <button type="button" class="btn btn-secondary" id="submitTradeBtn" disabled title="Trades desativadas pelo administrador">
              <i class="bi bi-lock-fill me-1"></i>Enviar Proposta
            </button>
          <?php else: ?>
            <button type="button" class="btn btn-orange" id="submitTradeBtn">
              <i class="bi bi-send me-1"></i>Enviar Proposta
            </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal: Trade Múltipla -->
  <div class="modal fade" id="multiTradeModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
      <div class="modal-content bg-dark-panel border-orange">
        <div class="modal-header border-bottom border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-people-fill me-2 text-orange"></i>Trade Múltipla</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="multiTradeForm">
            <div class="mb-3">
              <label class="form-label text-white fw-bold">Times participantes (máx. 7)</label>
              <div id="multiTradeTeamsList" class="d-flex flex-column gap-2"></div>
            </div>

            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label text-white fw-bold mb-0">Itens da troca</label>
                <button type="button" class="btn btn-sm btn-outline-orange" id="addMultiTradeItemBtn">
                  <i class="bi bi-plus-lg me-1"></i>Adicionar item
                </button>
              </div>
              <div id="multiTradeItems"></div>
            </div>

            <div class="mb-3">
              <label class="form-label text-white">Mensagem (opcional)</label>
              <textarea class="form-control bg-dark text-white border-orange" id="multiTradeNotes" rows="2"></textarea>
            </div>
          </form>
        </div>
        <div class="modal-footer border-top border-orange">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <?php if ($tradesEnabled == 0): ?>
            <button type="button" class="btn btn-secondary" id="submitMultiTradeBtn" disabled title="Trades desativadas pelo administrador">
              <i class="bi bi-lock-fill me-1"></i>Enviar Trade Múltipla
            </button>
          <?php else: ?>
            <button type="button" class="btn btn-orange" id="submitMultiTradeBtn">
              <i class="bi bi-send me-1"></i>Enviar Trade Múltipla
            </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function () {
      // Inicializar popovers
      const popoverTriggerList = document.querySelectorAll('[data-bs-toggle="popover"], [data-popin="true"]');
      popoverTriggerList.forEach((el) => {
        if (!el.disabled) {
          new bootstrap.Popover(el);
        }
      });

      // Melhorar renderização de cards
      let renderCount = 0;
      const enhanceCards = () => {
        const observer = new MutationObserver(() => {
          // Aplicar classes ao trade-card quando renderizado
          document.querySelectorAll('#receivedTradesList > div, #sentTradesList > div, #historyTradesList > div').forEach((card) => {
            if (!card.classList.contains('trade-card')) {
              card.classList.add('trade-card');
            }
          });

          // Aplicar classes ao player-card
          document.querySelectorAll('#playersList > div').forEach((card) => {
            if (!card.classList.contains('player-trade-card')) {
              card.classList.add('player-trade-card');
            }
          });

          // Aplicar classes aos league trades
          document.querySelectorAll('#leagueTradesList > div').forEach((card) => {
            if (!card.classList.contains('trade-card')) {
              card.classList.add('trade-card');
            }
          });

          renderCount++;
          if (renderCount > 50) observer.disconnect();
        });

        observer.observe(document.body, { childList: true, subtree: true });
      };

      enhanceCards();
    })();

    window.__TEAM_ID__ = <?= $teamId ? (int)$teamId : 'null' ?>;
    window.__USER_LEAGUE__ = '<?= htmlspecialchars($user['league'], ENT_QUOTES) ?>';
    window.__CURRENT_SEASON_YEAR__ = <?= (int)$currentSeasonYear ?>;
    window.__TEAM_NAME__ = '<?= htmlspecialchars(trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? '')), ENT_QUOTES) ?>';
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/sidebar.js"></script>
  <script src="/js/trades.js?v=20260309"></script>
  <script src="/js/trade-list.js?v=20260130"></script>
  <script src="/js/rumors.js?v=20260130"></script>
  <script src="/js/pwa.js?v=20260130"></script>
  <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
  <script>
    (function(){
      const toggle = document.getElementById('tradesStatusToggle');
      const badge = document.getElementById('tradesStatusBadge');
      const league = window.__USER_LEAGUE__;
      if (!toggle || !league) return;
      toggle.addEventListener('change', async (e) => {
        const enabled = e.target.checked ? 1 : 0;
        try {
          const res = await fetch('/api/admin.php?action=league_settings', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ league: league, trades_enabled: enabled })
          });
          const data = await res.json();
          if (!res.ok || data.success === false) throw new Error(data.error || 'Erro ao salvar');
          // Atualiza badge
          if (enabled === 1) {
            badge.className = 'badge bg-success';
            badge.textContent = 'Trocas abertas';
          } else {
            badge.className = 'badge bg-danger';
            badge.textContent = 'Trocas bloqueadas';
          }
        } catch (err) {
          alert('Erro ao atualizar status das trocas');
          // Reverte o switch
          e.target.checked = !e.target.checked;
        }
      });
    })();
  </script>
  <?php endif; ?>
</body>
</html>
