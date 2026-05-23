<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$cfg = require __DIR__ . '/../backend/config.php';
$visionKey = $cfg['app']['google_vision_key'] ?? '';

if (!$visionKey || $visionKey === 'API_KEY') {
    http_response_code(503);
    echo json_encode(['error' => 'Google Vision API não configurada.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (empty($body['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Imagem não enviada.']);
    exit;
}

$user = getUserSession();
$pdo = db();
$stmt = $pdo->prepare('SELECT p.id, p.name FROM players p INNER JOIN teams t ON p.team_id = t.id WHERE t.user_id = ? ORDER BY p.name');
$stmt->execute([$user['id']]);
$rosterPlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$imageData = $body['image'];
if (strpos($imageData, 'base64,') !== false) {
    $imageData = explode('base64,', $imageData)[1];
}

$visionUrl = "https://vision.googleapis.com/v1/images:annotate?key={$visionKey}";
$visionBody = json_encode([
    'requests' => [[
        'image' => ['content' => $imageData],
        'features' => [['type' => 'TEXT_DETECTION', 'maxResults' => 2000]]
    ]]
]);

$ch = curl_init($visionUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $visionBody);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$visionResponse = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'Erro ao contactar Google Vision: ' . $curlError]);
    exit;
}

$visionData = json_decode($visionResponse, true);

if (!empty($visionData['error'])) {
    http_response_code(502);
    echo json_encode(['error' => 'Google Vision: ' . ($visionData['error']['message'] ?? 'Erro desconhecido')]);
    exit;
}

$annotations = $visionData['responses'][0]['textAnnotations'] ?? [];
if (empty($annotations)) {
    echo json_encode(['detected' => [], 'roster' => $rosterPlayers]);
    exit;
}

$detected = parseSkillTable($annotations);
$matched = autoMatchPlayers($detected, $rosterPlayers);

echo json_encode(['detected' => $matched, 'roster' => $rosterPlayers]);

// ─── Helpers ────────────────────────────────────────────────

function wordBounds($annotation) {
    $verts = $annotation['boundingPoly']['vertices'] ?? [];
    if (empty($verts)) return null;
    $xs = array_filter(array_column($verts, 'x'), fn($v) => $v !== null);
    $ys = array_filter(array_column($verts, 'y'), fn($v) => $v !== null);
    if (empty($xs) || empty($ys)) return null;
    return [
        'x'  => (min($xs) + max($xs)) / 2,
        'y'  => (min($ys) + max($ys)) / 2,
        'x1' => min($xs), 'x2' => max($xs),
        'y1' => min($ys), 'y2' => max($ys),
    ];
}

function parseSkillTable($annotations) {
    array_shift($annotations); // remove full-text annotation

    $words = [];
    foreach ($annotations as $ann) {
        $b = wordBounds($ann);
        if (!$b) continue;
        $words[] = array_merge(['text' => $ann['description']], $b);
    }
    if (empty($words)) return [];

    usort($words, function($a, $b) {
        if (abs($a['y'] - $b['y']) < 12) return $a['x'] <=> $b['x'];
        return $a['y'] <=> $b['y'];
    });

    // Group into rows
    $rows = [[$words[0]]];
    for ($i = 1; $i < count($words); $i++) {
        $lastY = end($rows[count($rows)-1])['y'];
        if (abs($words[$i]['y'] - $lastY) < 12) {
            $rows[count($rows)-1][] = $words[$i];
        } else {
            $rows[] = [$words[$i]];
        }
    }

    // Find header row (has skill column names)
    $skillHeaders = ['IN', 'MID', '3PT', 'POST', 'PER', 'PLAY', 'REB', 'ATHL', 'IQ', 'POT'];
    $headerRowIdx = -1;
    $headerRow = null;
    foreach ($rows as $ri => $row) {
        $texts = array_map(fn($w) => strtoupper($w['text']), $row);
        $hits = count(array_intersect($skillHeaders, $texts));
        if ($hits >= 5) { $headerRowIdx = $ri; $headerRow = $row; break; }
    }
    if ($headerRow === null) return [];

    // Map header words → column X positions
    $headerKeyMap = [
        'IN'   => 'in',   'MID'  => 'mid',  '3PT'  => 'pt3',
        'POST' => 'post_d','PER' => 'per_d', 'PLAY' => 'play',
        'REB'  => 'reb',  'ATHL' => 'athl', 'IQ'   => 'iq',
        'POT'  => 'pot',
    ];
    $colX = [];
    foreach ($headerRow as $w) {
        $t = strtoupper($w['text']);
        if (isset($headerKeyMap[$t])) $colX[$headerKeyMap[$t]] = $w['x'];
    }
    if (empty($colX)) return [];

    $skillStartX = min($colX) - 50;
    $validGrades = ['A+','A','A-','B+','B','B-','C+','C','C-','D+','D','D-','F'];

    // Skip position/age codes
    $skipTokens = ['PG','SG','SF','PF','C','G','F','NAME','POS','AGE','RATING','OVR','OVERALL'];

    $detected = [];
    for ($ri = $headerRowIdx + 1; $ri < count($rows); $ri++) {
        $row = $rows[$ri];
        usort($row, fn($a,$b) => $a['x'] <=> $b['x']);

        $nameWords  = [];
        $gradeWords = [];

        foreach ($row as $w) {
            $t = strtoupper($w['text']);
            if (in_array($t, $validGrades) && $w['x'] >= $skillStartX) {
                $gradeWords[] = $w;
            } elseif ($w['x'] < $skillStartX && !in_array($t, $skipTokens) && !preg_match('/^\d+$/', $w['text'])) {
                $nameWords[] = $w;
            }
        }

        if (empty($nameWords) || empty($gradeWords)) continue;

        $name = trim(implode(' ', array_column($nameWords, 'text')));
        if (!$name) continue;

        // Assign each grade to nearest column
        $grades = [];
        foreach ($gradeWords as $gw) {
            $nearestKey = null;
            $nearestDist = PHP_INT_MAX;
            foreach ($colX as $key => $cx) {
                $d = abs($gw['x'] - $cx);
                if ($d < $nearestDist) { $nearestDist = $d; $nearestKey = $key; }
            }
            if ($nearestKey && !isset($grades[$nearestKey])) {
                $grades[$nearestKey] = strtoupper($gw['text']);
            }
        }

        if (!empty($grades)) {
            $detected[] = ['name' => $name, 'grades' => $grades];
        }
    }

    return $detected;
}

function autoMatchPlayers($detected, $rosterPlayers) {
    foreach ($detected as &$d) {
        $d['player_id']    = null;
        $d['matched_name'] = null;

        $det = strtolower(trim($d['name']));
        $bestScore = 0;
        $bestPlayer = null;

        foreach ($rosterPlayers as $rp) {
            $ros = strtolower(trim($rp['name']));

            if ($det === $ros) {
                $d['player_id']    = $rp['id'];
                $d['matched_name'] = $rp['name'];
                break;
            }

            $detParts = explode(' ', $det);
            $rosParts = explode(' ', $ros);
            $detLast  = end($detParts);
            $rosLast  = end($rosParts);
            $score    = 0;

            if ($detLast === $rosLast) {
                $score = 80;
                if (!empty($detParts[0]) && !empty($rosParts[0]) && $detParts[0][0] === $rosParts[0][0]) {
                    $score = 95;
                }
            }

            similar_text($det, $ros, $pct);
            if ($pct > $score) $score = $pct;

            if ($score > $bestScore) { $bestScore = $score; $bestPlayer = $rp; }
        }

        if (!$d['player_id'] && $bestPlayer && $bestScore >= 60) {
            $d['player_id']    = $bestPlayer['id'];
            $d['matched_name'] = $bestPlayer['name'];
        }
    }
    return $detected;
}
