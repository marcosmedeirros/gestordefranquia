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

if (!empty($team['league'])) {
  try {
    $stmtDraftSeason = $pdo->prepare('
      SELECT s.season_number, s.year, sp.start_year
      FROM draft_sessions ds
      JOIN seasons s ON ds.season_id = s.id
      LEFT JOIN sprints sp ON s.sprint_id = sp.id
      WHERE ds.league = ? AND ds.status IN ("setup", "in_progress")
      ORDER BY ds.created_at DESC
      LIMIT 1
    ');
    $stmtDraftSeason->execute([$team['league']]);
    $draftSeason = $stmtDraftSeason->fetch();
    if ($draftSeason && isset($draftSeason['start_year'], $draftSeason['season_number'])) {
      $currentSeasonYear = (int)$draftSeason['start_year'] + (int)$draftSeason['season_number'] - 1;
    } elseif ($draftSeason && isset($draftSeason['year'])) {
      $currentSeasonYear = (int)$draftSeason['year'];
    }
  } catch (Exception $e) {
    // fallback to previously computed value
  }
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

// Gera avisos SERASA automaticamente para trades pendentes > 24h (roda a cada carregamento, silencioso)
try {
    $stmtOverdue = $pdo->query("
        SELECT t.id, t.to_team_id, t.league
        FROM trades t
        WHERE t.status = 'pending'
          AND t.created_at < NOW() - INTERVAL 24 HOUR
          AND NOT EXISTS (
              SELECT 1 FROM team_punishments tp
              WHERE tp.team_id = t.to_team_id
                AND tp.type = 'AVISO_TRADE'
                AND tp.notes LIKE CONCAT('%Trade #', t.id, '%')
          )
    ");
    $overdueRows = $stmtOverdue ? $stmtOverdue->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!empty($overdueRows)) {
        $stmtIns = $pdo->prepare("
            INSERT INTO team_punishments (team_id, league, type, motive, punishment_label, effect_type, notes, season_scope, created_by)
            VALUES (?, ?, 'AVISO_TRADE', ?, 'Aviso de trade (SERASA)', 'AVISO_TRADE', ?, 'current', 1)
        ");
        foreach ($overdueRows as $tr) {
            $desc = "Trade #{$tr['id']} pendente há mais de 24h sem resposta";
            $stmtIns->execute([$tr['to_team_id'], $tr['league'], $desc, $desc]);
        }
    }
} catch (Exception $e) { /* silencia — não pode travar a página */ }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
  <?php include __DIR__ . '/includes/head-pwa.php'; ?>
  <title>Trades - FBA Manager</title>
  <meta name="theme-color" content="#fc0025">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="FBA Manager">
  <link rel="manifest" href="/manifest.json?v=3">
  <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/styles.css">
  <style>
    :root {
      --red:        #fc0025;
      --red-2:      color-mix(in srgb, var(--red) 85%, white);
      --red-soft:   color-mix(in srgb, var(--red) 10%, transparent);
      --red-glow:   color-mix(in srgb, var(--red) 18%, transparent);
      --bg:         #07070a;
      --panel:      #101013;
      --panel-2:    #16161a;
      --panel-3:    #1c1c21;
      --border:     rgba(255,255,255,.06);
      --border-md:  rgba(255,255,255,.10);
      --border-red: color-mix(in srgb, var(--red) 22%, transparent);
      --text:       #f0f0f3;
      --text-2:     #868690;
      --text-3:     #7d7d85;
      --green:      #22c55e;
      --amber:      #f59e0b;
      --blue:       #3b82f6;
      --sidebar-w:  260px;
      --font:       'Montserrat', sans-serif;
      --radius:     14px;
      --radius-sm:  10px;
      --radius-xs:  6px;
      --ease:       cubic-bezier(.2,.8,.2,1);
      --t:          200ms;
    }
    :root[data-theme="light"] {
      --bg: #f6f7fb; --panel: #ffffff; --panel-2: #f2f4f8; --panel-3: #e9edf4;
      --border: #e3e6ee; --border-md: #d7dbe6; --border-red: color-mix(in srgb, var(--red) 18%, transparent);
      --text: #111217; --text-2: #5b6270; --text-3: #657080;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

    .app { display: flex; min-height: 100vh; }

    /* Main */
    .main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }
    .topbar {
      position: fixed; top: 0; left: 0; right: 0; z-index: 240;
      background: var(--panel); border-bottom: 1px solid var(--border);
      padding: 0 16px; height: 54px;
      display: none; align-items: center; gap: 12px;
    }
    .topbar-menu-btn {
      display: none; background: none; border: none; color: var(--text-2);
      font-size: 20px; cursor: pointer; padding: 4px;
    }
    .topbar-title { font-size: 14px; font-weight: 600; color: var(--text); }
    .topbar-right { margin-left: auto; display: flex; align-items: center; gap: 8px; }

    /* ── Sidebar ── */
    .sidebar { position: fixed; top: 0; left: 0; width: 260px; height: 100vh; background: var(--panel); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 300; transition: transform var(--t) var(--ease); overflow-y: auto; scrollbar-width: none; }
    .sidebar::-webkit-scrollbar { display: none; }
    .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 299; }
    .sb-overlay.show, .sb-overlay.active { display: block; }
    .sb-brand { padding: 22px 18px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
    .sb-logo { width: 34px; height: 34px; border-radius: 9px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; flex-shrink: 0; }
    .sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
    .sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
    .sb-team { margin: 14px 14px 0; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
    .sb-team-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
    .sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }
    .sb-nav { flex: 1; padding: 12px 10px 8px; }
    .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 6px; }
    .sb-nav a { font-family:'Inter',sans-serif; display: flex; align-items: center; gap: 10px; padding: 10px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all var(--t) var(--ease); }
    .sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
    .sb-nav a:hover { background: var(--panel-2); color: var(--text); }
    .sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
    .sb-nav a.active i { color: var(--red); }
    .sb-theme-toggle { margin: 0 14px 12px; padding: 8px 10px; border-radius: 10px; border: 1px solid var(--border); background: var(--panel-2); color: var(--text); display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all var(--t) var(--ease); }
    .sb-theme-toggle:hover { border-color: var(--border-red); color: var(--red); }
    .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
    .sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sb-logout { width: 26px; height: 26px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease); text-decoration: none; flex-shrink: 0; }
    .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

    .page-hero {
      padding: 28px 28px 0;
      display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 14px;
    }
    .page-hero-title { font-size: 22px; font-weight: 700; color: var(--text); line-height: 1.2; }
    .page-hero-sub { font-size: 13px; color: var(--text); margin-top: 4px; }
    .page-hero-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

    .content { padding: 24px 28px 40px; }

    /* Panel card */
    .panel-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    .panel-card-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
    .panel-card-title { font-size: 14px; font-weight: 600; color: var(--text); }
    .panel-card-body { padding: 20px; }

    /* Buttons */
    .btn-r {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 500;
      border: none; cursor: pointer; transition: opacity var(--t), background var(--t);
    }
    .btn-r:hover { opacity: .85; }
    .btn-r.primary { background: var(--red); color: #fff; }
    .btn-r.secondary { background: var(--panel-3); color: var(--text-2); border: 1px solid var(--border); }
    .btn-r.outline { background: transparent; color: var(--red); border: 1px solid var(--border-red); }
    .btn-r:disabled { opacity: .4; cursor: not-allowed; }

    /* Badge */
    .tag { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
    .tag.gray  { background: var(--panel-3); color: var(--text-2); }
    .tag.green { background: rgba(34,197,94,.12); color: #22c55e; }
    .tag.red   { background: color-mix(in srgb, var(--red) 12%, transparent); color: var(--red); }

    /* Tabs */
    .nav-tabs { border-bottom: 1px solid var(--border); }
    .nav-tabs .nav-link {
      background: transparent; border: none; color: var(--text-2);
      font-weight: 500; font-size: 13px; padding: 10px 18px;
      border-bottom: 2px solid transparent; margin-bottom: -1px;
      transition: color var(--t), border-color var(--t);
    }
    .nav-tabs .nav-link:hover { color: var(--text); background: var(--red-soft); border-bottom-color: var(--border-red); }
    .nav-tabs .nav-link.active { color: var(--red); border-bottom-color: var(--red); font-weight: 600; background: transparent; }

    /* Form controls */
    .form-control, .form-select {
      background: var(--panel-2); color: var(--text);
      border: 1px solid var(--border); border-radius: 8px;
      font-family: var(--font); font-size: 13px;
    }
    .form-control:focus, .form-select:focus {
      background: var(--panel-2); color: var(--text);
      border-color: var(--red); box-shadow: 0 0 0 3px var(--red-glow);
      outline: none;
    }
    .form-control::placeholder { color: var(--text-3); }
    .form-label { font-size: 12px; font-weight: 600; color: var(--text-2); text-transform: uppercase; letter-spacing: .04em; }

    /* Modal */
    .modal-content { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); color: var(--text); }
    .modal-header { border-bottom: 1px solid var(--border); padding: 16px 20px; }
    .modal-title { font-size: 15px; font-weight: 600; }
    .modal-footer { border-top: 1px solid var(--border); padding: 14px 20px; }
    .modal-body { padding: 20px; }
    .btn-close-white { filter: invert(1) grayscale(100%) brightness(200%); }

    /* Pick selector */
    .pick-selector {
      background: var(--panel-2); border: 1px solid var(--border);
      border-radius: var(--radius-sm); padding: 14px;
    }
    .pick-options {
      max-height: 200px; overflow-y: auto;
      display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px;
    }
    .pick-option-card {
      display: flex; justify-content: space-between; align-items: center;
      background: var(--panel-3); border: 1px solid var(--border);
      border-radius: 8px; padding: 10px 12px;
      transition: border var(--t);
    }
    .pick-option-card:hover { border-color: var(--red); }
    .pick-option-card.is-selected { opacity: .5; }
    .pick-title { color: var(--text); font-weight: 600; font-size: 13px; margin-bottom: 2px; }
    .pick-meta { font-size: 12px; color: var(--text-2); }
    .selected-picks { display: flex; flex-direction: column; gap: 8px; }
    .selected-pick-card {
      display: flex; flex-wrap: wrap; gap: 8px;
      justify-content: space-between; align-items: center;
      background: var(--red-soft); border: 1px solid var(--border-red);
      border-radius: 8px; padding: 10px 12px;
    }
    .selected-pick-info { flex: 1; min-width: 180px; }
    .selected-pick-actions { display: flex; gap: 8px; align-items: center; }
    .pick-protection-select {
      background: #fff; color: #000; border: 1px solid var(--red);
      border-radius: 6px; padding: 6px 8px; min-width: 130px; font-size: 12px;
    }
    .pick-empty-state {
      text-align: center; padding: 12px;
      background: var(--panel-2); border: 1px dashed var(--border);
      border-radius: 8px; color: var(--text-2); font-size: 13px;
    }

    /* Reaction chips */
    .reaction-bar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .reaction-chip {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 4px 10px; border-radius: 999px;
      border: 1px solid var(--border); background: var(--panel-2);
      color: var(--text); font-size: 12px; cursor: pointer;
      transition: border var(--t), background var(--t);
    }
    .reaction-chip.active { border-color: var(--red); background: var(--red-soft); }
    .reaction-count { font-size: 11px; color: var(--text-2); }

    /* Team chip */
    .team-chip {
      display: inline-flex; align-items: center; gap: 8px;
      background: var(--panel-2); border: 1px solid var(--border);
      border-radius: 30px; padding: 6px 12px;
    }
    .team-chip-badge {
      width: 28px; height: 28px; border-radius: 50%;
      background: var(--panel-3); border: 1px solid var(--border-md);
      display: inline-flex; align-items: center; justify-content: center;
      font-weight: 600; font-size: 12px; color: var(--text);
    }

    /* Trade value verdict badges */
    .tv-verdict-badge{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:8px;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;white-space:nowrap}
    .tv-neutral{background:var(--panel-3);border:1px solid var(--border);color:var(--text-3)}
    .tv-valid{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#22c55e}
    .tv-warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:var(--amber)}
    .tv-invalid{background:color-mix(in srgb, var(--red) 12%, transparent);border:1px solid color-mix(in srgb, var(--red) 30%, transparent);color:var(--red)}
    .tv-robbery{background:color-mix(in srgb, var(--red) 20%, transparent);border:2px solid color-mix(in srgb, var(--red) 55%, transparent);color:var(--red);animation:tv-pulse 1.2s ease-in-out infinite}
    @keyframes tv-pulse{0%,100%{box-shadow:0 0 0 0 color-mix(in srgb, var(--red) 40%, transparent)}50%{box-shadow:0 0 0 6px color-mix(in srgb, var(--red) 0%, transparent)}}

    /* CAP impact card */
    .cap-card {
      background: var(--panel-2); border: 1px solid var(--border);
      border-radius: var(--radius-sm); padding: 14px;
    }

    /* ── Trade List cards ── */
    .tl-player-card {
      display: flex; align-items: center; justify-content: space-between;
      padding: 12px 16px; border-bottom: 1px solid var(--border); gap: 12px;
      transition: background var(--t) var(--ease);
    }
    .tl-player-card:last-child { border-bottom: none; }
    .tl-player-card:hover { background: var(--panel-2); }
    .tl-player-name { font-size: 14px; font-weight: 600; color: var(--text); margin-bottom: 4px; }
    .tl-player-meta { font-size: 12px; color: var(--text-2); line-height: 1.5; }
    .tl-team-chip {
      display: inline-flex; align-items: center; gap: 6px;
      background: var(--panel-3); border: 1px solid var(--border);
      border-radius: 20px; padding: 4px 12px;
      font-size: 11px; font-weight: 500; color: var(--text-2);
      white-space: nowrap; flex-shrink: 0;
    }
    .tl-team-badge { font-size: 10px; font-weight: 700; color: var(--red); }
    @media (max-width: 576px) {
      .tl-player-card { flex-direction: column; align-items: flex-start; gap: 8px; }
      .tl-team-chip { align-self: flex-start; }
    }

    /* Trade cards */
    .tc { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; margin-bottom: 12px; }
    .tc-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
    .tc-header > div:first-child { flex: 1; min-width: 0; }
    .tc-title { font-size: 14px; font-weight: 600; color: var(--text); margin-bottom: 4px; overflow-wrap: break-word; word-break: break-word; }
    .tc-date { font-size: 12px; color: var(--text-2); }
    .tc-side-title { font-size: 11px; font-weight: 600; color: var(--text-2); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 8px; }
    .tc-item { font-size: 13px; color: var(--text); padding: 4px 0; display: flex; align-items: center; gap: 6px; }
    .tc-notes { background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-xs); padding: 10px 12px; margin-top: 12px; font-size: 13px; color: var(--text-2); }
    .tc-response-notes { background: var(--panel-2); border-left: 3px solid var(--amber); border-radius: 0 var(--radius-xs) var(--radius-xs) 0; padding: 10px 12px; margin-top: 10px; }
    .tc-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
    .btn-r.sm { padding: 6px 12px; font-size: 12px; }
    .tag.amber { background: rgba(245,158,11,.12); color: var(--amber); }
    .tag.blue  { background: rgba(59,130,246,.12); color: var(--blue); }

    @media (max-width: 992px) {
      :root { --sidebar-w: 0px; }
      .sidebar { transform: translateX(-260px); }
      .sidebar.open { transform: translateX(0); }
      .main { margin-left: 0; width: 100%; padding-top: 54px; }
      .topbar { display: flex; }
      .topbar-menu-btn { display: flex; }
      .page-hero { padding: 16px 16px 0; }
      .content { padding: 16px 16px 32px; }
    }
    @media (max-width: 576px) {
      .nav-tabs { overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
      .nav-tabs::-webkit-scrollbar { display: none; }
      .nav-tabs .nav-link { white-space: nowrap; padding: 10px 12px; font-size: 12px; }
      .page-hero-title { font-size: 18px; }
      .tc { padding: 14px; }
      .tc-header { gap: 8px; }
    }
  <?php include __DIR__ . '/includes/accent-color.php'; ?>
    </style>
</head>
<body>
  <div class="app">

    <!-- ═══ SIDEBAR ════════════════════════════════════════════ -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Overlay mobile -->
    <div class="sb-overlay" id="sbOverlay"></div>

    <main class="main">
      <!-- Topbar -->
      <div class="topbar">
        <button class="topbar-menu-btn" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <span class="topbar-title"><i class="bi bi-arrow-left-right me-2" style="color:var(--red)"></i>Trades</span>
        <div class="topbar-right">
          <?php if (hasAdminAccess($pdo, (int)$user['id'])): ?>
            <div class="d-flex align-items-center gap-2">
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="tradesStatusToggle" <?= ($tradesEnabled ?? 1) == 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="tradesStatusToggle" style="font-size:12px;color:var(--text-2)">Ativar trocas</label>
              </div>
              <span id="tradesStatusBadge" class="tag <?= ($tradesEnabled ?? 1) == 1 ? 'green' : 'red' ?>">
                <?= ($tradesEnabled ?? 1) == 1 ? 'Abertas' : 'Bloqueadas' ?>
              </span>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Page hero -->
      <div class="page-hero">
        <div>
          <h1 class="page-hero-title"><i class="bi bi-arrow-left-right me-2" style="color:var(--red)"></i>Trades</h1>
          <p class="page-hero-sub">Gerencie e acompanhe todas as trocas da sua liga</p>
        </div>
        <div class="page-hero-actions">
          <span class="tag gray"><?= $tradeCount ?>/<?= $maxTrades ?> trocas usadas</span>
          <?php if ($tradesEnabled == 0): ?>
            <button class="btn-r secondary" disabled><i class="bi bi-lock-fill"></i>Bloqueadas</button>
          <?php elseif ($tradeCount >= $maxTrades): ?>
            <button class="btn-r secondary" disabled><i class="bi bi-lightning-fill"></i>Trade Rápida</button>
            <button class="btn-r primary" disabled><i class="bi bi-plus-circle"></i>Nova Trade</button>
          <?php else: ?>
            <button class="btn-r secondary" data-bs-toggle="modal" data-bs-target="#proposeTradeModal">
              <i class="bi bi-lightning-fill"></i>Trade Rápida
            </button>
            <a href="/trade-simulator.php?propose=1" class="btn-r primary" style="text-decoration:none">
              <i class="bi bi-plus-circle"></i>Nova Trade
            </a>
          <?php endif; ?>
        </div>
      </div>

      <div class="content">
        <?php if (!$teamId): ?>
          <div class="alert alert-warning">Você ainda não possui um time.</div>
        <?php else: ?>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4" id="tradesTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" id="received-tab" data-bs-toggle="tab" data-bs-target="#received" type="button">
              <i class="bi bi-inbox-fill me-1"></i>Recebidas
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="sent-tab" data-bs-toggle="tab" data-bs-target="#sent" type="button">
              <i class="bi bi-send-fill me-1"></i>Enviadas
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button">
              <i class="bi bi-clock-history me-1"></i>Histórico
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="league-tab" data-bs-toggle="tab" data-bs-target="#league" type="button">
              <i class="bi bi-trophy me-1"></i>Trocas Gerais
            </button>
          </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="tradesTabContent">
          <!-- Recebidas -->
          <div class="tab-pane fade show active" id="received" role="tabpanel">
            <div id="receivedTradesList"></div>
          </div>

          <!-- Enviadas -->
          <div class="tab-pane fade" id="sent" role="tabpanel">
            <div id="sentTradesList"></div>
          </div>

          <!-- Histórico -->
          <div class="tab-pane fade" id="history" role="tabpanel">
            <div id="historyTradesList"></div>
          </div>

          <!-- Trocas Gerais -->
          <div class="tab-pane fade" id="league" role="tabpanel">
            <div class="panel-card">
              <div class="panel-card-header">
                <div>
                  <div class="panel-card-title"><i class="bi bi-trophy me-2" style="color:var(--red)"></i>Todas as trocas desta liga</div>
                  <div style="font-size:12px;color:var(--text-2);margin-top:2px">Histórico completo de negociações aceitas na sua liga.</div>
                </div>
                <span class="tag gray" id="leagueTradesCount">0 trocas</span>
              </div>
              <div class="panel-card-body">
                <div class="row g-2 mb-3">
                  <div class="col-12 col-md-7">
                    <input type="text" class="form-control" id="leagueTradesSearch" placeholder="Buscar jogador nas trocas...">
                  </div>
                  <div class="col-12 col-md-5">
                    <select class="form-select" id="leagueTradesTeamFilter">
                      <option value="">Todos os times</option>
                    </select>
                  </div>
                </div>
                <div id="leagueTradesList"></div>
              </div>
            </div>
          </div>

        </div>

        <?php endif; ?>
      </div>
    </main>
  </div>

  <!-- Modal: Propor Trade -->
  <div class="modal fade" id="proposeTradeModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2" style="color:var(--red)"></i>Propor Trade</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="proposeTradeForm">
            <div class="mb-4">
              <label class="form-label">Para qual time?</label>
              <select class="form-select" id="targetTeam" required>
                <option value="">Selecione...</option>
              </select>
            </div>
            <div class="row">
              <div class="col-md-6">
                <p style="font-size:13px;font-weight:600;color:var(--red);margin-bottom:12px">Você oferece</p>
                <div class="mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0">Jogadores</label>
                    <small style="color:var(--text-2);font-size:11px">Adicionar e revisar seleção</small>
                  </div>
                  <div class="pick-selector">
                    <div class="pick-options" id="offerPlayersOptions"></div>
                    <div class="selected-picks" id="offerPlayersSelected"></div>
                  </div>
                </div>
                <div class="mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0">Picks</label>
                    <small style="color:var(--text-2);font-size:11px">Adicione picks na proposta</small>
                  </div>
                  <div class="pick-selector">
                    <div class="pick-options" id="offerPicksOptions"></div>
                    <div class="selected-picks" id="offerPicksSelected"></div>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <p style="font-size:13px;font-weight:600;color:var(--red);margin-bottom:12px">Você quer</p>
                <div class="mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0">Jogadores</label>
                    <small style="color:var(--text-2);font-size:11px">Selecione atletas do time alvo</small>
                  </div>
                  <div class="pick-selector">
                    <div class="pick-options" id="requestPlayersOptions"></div>
                    <div class="selected-picks" id="requestPlayersSelected"></div>
                  </div>
                </div>
                <div class="mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0">Picks</label>
                    <small style="color:var(--text-2);font-size:11px">Selecione picks do time alvo</small>
                  </div>
                  <div class="pick-selector">
                    <div class="pick-options" id="requestPicksOptions"></div>
                    <div class="selected-picks" id="requestPicksSelected"></div>
                  </div>
                </div>
              </div>
            </div>
            <!-- Impacto no CAP + Valor -->
            <div class="row g-3 mb-3" id="capImpactRow">
              <div class="col-md-6">
                <div class="cap-card">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <span style="font-size:13px;color:var(--text)">Seu time</span>
                    <span class="tag gray" id="capMyDelta">±0</span>
                  </div>
                  <div style="font-size:12px;color:var(--text-2)">Atual: <span style="color:var(--text)" id="capMyCurrent">-</span></div>
                  <div style="font-size:12px;color:var(--text-2)">Após trade: <span style="color:var(--red);font-weight:600" id="capMyProjected">-</span></div>
                  <div style="font-size:12px;color:var(--text-2);margin-top:4px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:4px">
                    <span>Jogadores: <span style="color:var(--text)" id="capMyPlayers">-</span> → <span style="color:var(--red);font-weight:600" id="capMyPlayersProjected">-</span></span>
                    <span style="color:var(--text-3)">Valor: <span style="color:var(--text);font-weight:600" id="tvMyValue">—</span></span>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="cap-card">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <span style="font-size:13px;color:var(--text)" id="capTargetLabel">Time alvo</span>
                    <span class="tag gray" id="capTargetDelta">±0</span>
                  </div>
                  <div style="font-size:12px;color:var(--text-2)">Atual: <span style="color:var(--text)" id="capTargetCurrent">-</span></div>
                  <div style="font-size:12px;color:var(--text-2)">Após trade: <span style="color:var(--red);font-weight:600" id="capTargetProjected">-</span></div>
                  <div style="font-size:12px;color:var(--text-2);margin-top:4px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:4px">
                    <span>Jogadores: <span style="color:var(--text)" id="capTargetPlayers">-</span> → <span style="color:var(--red);font-weight:600" id="capTargetPlayersProjected">-</span></span>
                    <span style="color:var(--text-3)">Valor: <span style="color:var(--text);font-weight:600" id="tvTargetValue">—</span> <span id="tvVerdict" class="tv-verdict-badge tv-neutral" style="font-size:9px;padding:3px 8px"><i class="bi bi-hourglass-split"></i></span></span>
                  </div>
                </div>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label">Mensagem (opcional)</label>
              <textarea class="form-control" id="tradeNotes" rows="2"></textarea>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-r secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn-r secondary" id="copyQuickTradeBtn" onclick="copyQuickTrade()"><i class="bi bi-clipboard"></i>Copiar</button>
          <?php if ($tradesEnabled == 0): ?>
            <button type="button" class="btn-r secondary" id="submitTradeBtn" disabled><i class="bi bi-lock-fill"></i>Enviar Proposta</button>
          <?php else: ?>
            <button type="button" class="btn-r primary" id="submitTradeBtn"><i class="bi bi-send"></i>Enviar Proposta</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal: Trade Múltipla -->
  <div class="modal fade" id="multiTradeModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-people-fill me-2" style="color:var(--red)"></i>Trade Múltipla</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="multiTradeForm">
            <div class="mb-3">
              <label class="form-label">Times participantes (máx. 7)</label>
              <div id="multiTradeTeamsList" class="d-flex flex-column gap-2"></div>
            </div>
            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label mb-0">Itens da troca</label>
                <button type="button" class="btn-r secondary" style="padding:6px 10px;font-size:12px" id="addMultiTradeItemBtn">
                  <i class="bi bi-plus-lg"></i>Adicionar item
                </button>
              </div>
              <div id="multiTradeItems"></div>
            </div>
            <div class="mb-3">
              <label class="form-label">Mensagem (opcional)</label>
              <textarea class="form-control" id="multiTradeNotes" rows="2"></textarea>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-r secondary" data-bs-dismiss="modal">Cancelar</button>
          <?php if ($tradesEnabled == 0): ?>
            <button type="button" class="btn-r secondary" id="submitMultiTradeBtn" disabled><i class="bi bi-lock-fill"></i>Enviar Trade Múltipla</button>
          <?php else: ?>
            <button type="button" class="btn-r primary" id="submitMultiTradeBtn"><i class="bi bi-send"></i>Enviar Trade Múltipla</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <script>
    window.__TEAM_ID__ = <?= $teamId ? (int)$teamId : 'null' ?>;
    window.__USER_LEAGUE__ = '<?= htmlspecialchars($user['league'], ENT_QUOTES) ?>';
    window.__CURRENT_SEASON_YEAR__ = <?= (int)$currentSeasonYear ?>;
    window.__TEAM_NAME__ = '<?= htmlspecialchars(trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? '')), ENT_QUOTES) ?>';
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/trade-value.js?v=20260716"></script>
  <script src="/js/trades.js?v=20260722"></script>
  <script src="/js/pwa.js?v=20260130"></script>
  <script>
    // Mobile sidebar
    (function(){
      const sidebar = document.getElementById('sidebar');
      const overlay = document.getElementById('sbOverlay');
      const btn = document.getElementById('sidebarToggle');
      if (!sidebar || !overlay) return;
      const open  = () => { sidebar.classList.add('open');  overlay.classList.add('active'); };
      const close = () => { sidebar.classList.remove('open'); overlay.classList.remove('active'); };
      if (btn) btn.addEventListener('click', open);
      overlay.addEventListener('click', close);
    })();

    // Theme
    (function(){
      const key = 'fba-theme';
      const themeBtn = document.querySelector('[data-theme-toggle]');
      const apply = (t) => {
        if (t === 'light') {
          document.documentElement.setAttribute('data-theme','light');
          if (themeBtn) themeBtn.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>';
        } else {
          document.documentElement.removeAttribute('data-theme');
          if (themeBtn) themeBtn.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
        }
      };
      apply(localStorage.getItem(key) || 'dark');
      if (themeBtn) themeBtn.addEventListener('click', () => {
        const next = document.documentElement.hasAttribute('data-theme') ? 'dark' : 'light';
        localStorage.setItem(key, next); apply(next);
      });
    })();
  </script>
  <?php if (hasAdminAccess($pdo, (int)$user['id'])): ?>
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
          if (enabled === 1) {
            badge.className = 'tag green'; badge.textContent = 'Abertas';
          } else {
            badge.className = 'tag red'; badge.textContent = 'Bloqueadas';
          }
        } catch (err) {
          alert('Erro ao atualizar status das trocas');
          e.target.checked = !e.target.checked;
        }
      });
    })();
  </script>
  <?php endif; ?>
  <script>
  function copyQuickTrade() {
    const targetSelect = document.getElementById('targetTeam');
    const targetName = targetSelect ? (targetSelect.options[targetSelect.selectedIndex]?.text || 'Time alvo') : 'Time alvo';
    const myName = window.__TEAM_NAME__ || 'Seu time';
    const lines = [];

    const offerPlayers = (typeof playerState !== 'undefined') ? (playerState.offer.selected || []) : [];
    const requestPlayers = (typeof playerState !== 'undefined') ? (playerState.request.selected || []) : [];
    const offerPicks = (typeof pickState !== 'undefined') ? (pickState.offer.selected || []) : [];
    const requestPicks = (typeof pickState !== 'undefined') ? (pickState.request.selected || []) : [];

    lines.push(`${targetName.toUpperCase()} recebe:`);
    offerPlayers.forEach(p => lines.push(`  • ${p.name} (${p.position || p.pos || ''}, OVR ${p.ovr}/${p.age}a)`));
    offerPicks.forEach(p => {
      const swap = p.swapRole ? ` [${p.swapRole}]` : '';
      lines.push(`  • Pick ${p.season_year} R${p.round}${p.original_team_name ? ` (${p.original_team_name})` : ''}${swap}`);
    });
    lines.push('');
    lines.push(`${myName.toUpperCase()} recebe:`);
    requestPlayers.forEach(p => lines.push(`  • ${p.name} (${p.position || p.pos || ''}, OVR ${p.ovr}/${p.age}a)`));
    requestPicks.forEach(p => {
      const swap = p.swapRole ? ` [${p.swapRole}]` : '';
      lines.push(`  • Pick ${p.season_year} R${p.round}${p.original_team_name ? ` (${p.original_team_name})` : ''}${swap}`);
    });

    const text = lines.join('\n').trim();
    if (!text || (offerPlayers.length + offerPicks.length + requestPlayers.length + requestPicks.length) === 0) {
      alert('Nenhum item na trade para copiar.'); return;
    }

    const btn = document.getElementById('copyQuickTradeBtn');
    const restore = () => { if (btn) btn.innerHTML = '<i class="bi bi-clipboard"></i>Copiar'; };
    navigator.clipboard.writeText(text).then(() => {
      if (btn) btn.innerHTML = '<i class="bi bi-check2"></i>Copiado!';
      setTimeout(restore, 2000);
    }).catch(() => {
      const ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); if (btn) btn.innerHTML = '<i class="bi bi-check2"></i>Copiado!'; }
      catch(e) { alert(text); }
      document.body.removeChild(ta);
      setTimeout(restore, 2000);
    });
  }
  </script>
</body>
</html>
