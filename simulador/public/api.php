<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/src/Accounts.php';
require_once dirname(__DIR__) . '/src/League.php';

// Bootstrap do save ativo (api.php é um entry point separado do index.php).
Accounts::startSession();
$saveId = Accounts::activeSaveId();
if (!$saveId) { http_response_code(403); echo json_encode(['error' => 'Sessão expirada — recarregue a página e entre novamente.']); exit; }
$act = Accounts::activate($saveId);
if (isset($act['error'])) { http_response_code(403); echo json_encode(['error' => 'Save indisponível.']); exit; }

$live = $_GET['live'] ?? null;

// ---------- Modo AO VIVO (comando do GM, período a período) ----------
if ($live === 'start') {
    $gameId = (int) ($_GET['game'] ?? 0);
    $g = League::gmLiveGame($gameId);
    if (!$g) { http_response_code(400); echo json_encode(['error' => 'Jogo indisponível para comando ao vivo.']); exit; }
    $res = League::liveStart($gameId);
    if (isset($res['error'])) { http_response_code(400); echo json_encode($res); exit; }

    // jogadores do adversário para o menu de marcação dupla (top OVR)
    $oppId = $res['gm_side'] === 'home' ? (int) $g['away_id'] : (int) $g['home_id'];
    $opp = array_slice(array_values(array_filter(League::roster($oppId), fn($p) => !($p['injury_games'] ?? 0))), 0, 8);
    $oppStars = array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'pos' => $p['pos'], 'ovr' => (int) $p['ovr']], $opp);

    echo json_encode([
        'ok' => true,
        'gm_side' => $res['gm_side'],
        'resumed' => $res['resumed'] ?? false,
        'score' => $res['score'] ?? ['home' => 0, 'away' => 0],
        'period' => $res['period'] ?? 0,
        'cur_timeouts' => $res['timeouts'] ?? null,
        'game' => [
            'id' => (int) $g['id'], 'home_id' => (int) $g['home_id'], 'away_id' => (int) $g['away_id'],
            'home_abbr' => $g['home_abbr'], 'away_abbr' => $g['away_abbr'],
            'home_name' => $g['home_city'] . ' ' . $g['home_name'], 'away_name' => $g['away_city'] . ' ' . $g['away_name'],
            'home_color' => $g['home_color'], 'away_color' => $g['away_color'],
        ],
        'schemes_off' => League::SCHEMES_OFF,
        'schemes_def' => League::SCHEMES_DEF,
        'opp_players' => $oppStars,
        'timeouts' => 7,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($live === 'step') {
    $controls = [
        'off' => $_POST['off'] ?? null,
        'def' => $_POST['def'] ?? null,
        'double_team' => (int) ($_POST['double_team'] ?? 0),
        'timeout' => !empty($_POST['timeout']),
    ];
    $res = League::liveStep($controls);
    if (isset($res['error'])) { http_response_code(400); echo json_encode($res); exit; }
    if (!empty($res['done'])) {
        $res['box'] = League::boxScore((int) $res['game_id']);
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- Modo padrão: simula (se preciso) e devolve o play-by-play completo ----------
$gameId = (int) ($_GET['game'] ?? 0);
if (!$gameId) { http_response_code(400); echo json_encode(['error' => 'game id required']); exit; }

$g = League::game($gameId);
if (!$g) { http_response_code(404); echo json_encode(['error' => 'game not found']); exit; }

if (!$g['played']) {
    League::simulateGame($gameId);
    $g = League::game($gameId);
}

$pbp = $g['pbp'] ? json_decode($g['pbp'], true) : [];
$box = League::boxScore($gameId);

echo json_encode([
    'game' => [
        'id' => (int) $g['id'],
        'home_id' => (int) $g['home_id'],
        'away_id' => (int) $g['away_id'],
        'home_abbr' => $g['home_abbr'], 'away_abbr' => $g['away_abbr'],
        'home_name' => $g['home_city'] . ' ' . $g['home_name'],
        'away_name' => $g['away_city'] . ' ' . $g['away_name'],
        'home_color' => $g['home_color'], 'away_color' => $g['away_color'],
        'home_pts' => (int) $g['home_pts'], 'away_pts' => (int) $g['away_pts'],
        'ot' => (int) $g['ot'],
    ],
    'pbp' => $pbp,
    'box' => $box,
], JSON_UNESCAPED_UNICODE);
