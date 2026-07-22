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

// Quantas leituras por time por temporada. 32 times x 4 temporadas/mes x 6 =
// 768 chamadas no pior caso, dentro da cota gratuita de 1.000/mes do Vision.
const VISION_LIMIT = 6;
// Freio geral: por mais que os times individualmente respeitem o limite, a liga
// inteira nunca passa disto no mes, para a cota gratuita nao virar cobranca.
const VISION_MONTHLY_CAP = 900;
const VISION_UNLIMITED_EMAILS = ['medeirros99@gmail.com'];
// Leitura por foto e recurso da ELITE; as demais ligas atualizam manualmente.
const VISION_LEAGUES = ['ELITE'];

$user = getUserSession();
$pdo  = db();

// Garantir tabela de controle
$pdo->exec("CREATE TABLE IF NOT EXISTS vision_skill_usage (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    team_id    INT NOT NULL,
    season_id  INT,
    count      INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_team_season (team_id, season_id)
)");

// Buscar time do usuário
$stmtTeam = $pdo->prepare('SELECT id, league FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
$teamId = $team ? (int)$team['id'] : null;

// Só a ELITE usa leitura por foto.
if (!$team || !in_array($team['league'] ?? '', VISION_LEAGUES, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'A atualização por foto é exclusiva da ELITE. Nas demais ligas a atualização é manual.']);
    exit;
}

// Buscar temporada ativa
$seasonId = null;
if ($team) {
    $stmtSeason = $pdo->prepare("SELECT id FROM seasons WHERE league = ? AND status <> 'completed' ORDER BY id DESC LIMIT 1");
    $stmtSeason->execute([$team['league']]);
    $seasonId = $stmtSeason->fetchColumn() ?: null;
}

// Verificar limite
$stmtUsage = $pdo->prepare('SELECT count FROM vision_skill_usage WHERE team_id = ? AND season_id <=> ?');
$stmtUsage->execute([$teamId, $seasonId]);
$currentCount = (int)($stmtUsage->fetchColumn() ?: 0);

$isUnlimited = in_array($user['email'] ?? '', VISION_UNLIMITED_EMAILS);
if (!$isUnlimited && $currentCount >= VISION_LIMIT) {
    http_response_code(429);
    echo json_encode(['error' => "Limite de " . VISION_LIMIT . " análises por temporada atingido.", 'limit' => VISION_LIMIT, 'used' => $currentCount]);
    exit;
}

// Freio da liga no mes corrente. Conta o consumo real de chamadas ao Vision,
// nao por time, para nunca ultrapassar a cota gratuita.
$pdo->exec("CREATE TABLE IF NOT EXISTS vision_monthly_usage (
    ym    CHAR(7) NOT NULL PRIMARY KEY,
    count INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$ym = date('Y-m');
$stmtMonth = $pdo->prepare('SELECT count FROM vision_monthly_usage WHERE ym = ?');
$stmtMonth->execute([$ym]);
$monthCount = (int)($stmtMonth->fetchColumn() ?: 0);
if ($monthCount >= VISION_MONTHLY_CAP) {
    http_response_code(429);
    echo json_encode([
        'error' => 'A liga atingiu o limite mensal de leituras por foto. Atualize manualmente ou tente no próximo mês.',
        'limit' => VISION_LIMIT, 'used' => $currentCount,
    ]);
    exit;
}

// Buscar elenco
$stmt = $pdo->prepare('SELECT p.id, p.name FROM players p WHERE p.team_id = ? ORDER BY p.name');
$stmt->execute([$teamId]);
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
    echo json_encode(['detected' => [], 'roster' => $rosterPlayers, 'used' => $currentCount, 'limit' => VISION_LIMIT]);
    exit;
}

// Incrementar uso
$pdo->prepare('INSERT INTO vision_skill_usage (team_id, season_id, count) VALUES (?, ?, 1)
    ON DUPLICATE KEY UPDATE count = count + 1')
    ->execute([$teamId, $seasonId]);
$currentCount++;

// Consumo mensal da liga (o freio geral olha para este contador).
$pdo->prepare('INSERT INTO vision_monthly_usage (ym, count) VALUES (?, 1)
    ON DUPLICATE KEY UPDATE count = count + 1')
    ->execute([$ym]);

$detected = parseSkillTable($annotations);
$matched  = autoMatchPlayers($detected, $rosterPlayers);

echo json_encode(['detected' => $matched, 'roster' => $rosterPlayers, 'used' => $currentCount, 'limit' => VISION_LIMIT]);

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

/**
 * Quebra um token em caracteres com X interpolado ao longo da caixa.
 *
 * O fundo pontilhado da tela de skills é lido como zeros, que se colam aos
 * valores: um único token vem como "C0000000027000000094000000A", com a
 * posição, a idade, o rating e a primeira nota grudados. Trabalhando por
 * caractere dá para reancorar cada pedaço na coluna certa.
 */
function explodeTokenChars(array $word): array {
    $txt = $word['text'];
    $len = mb_strlen($txt);
    if ($len <= 1) return [$word];
    $x1 = $word['x1']; $x2 = $word['x2'];
    $passo = ($x2 - $x1) / $len;
    $out = [];
    for ($i = 0; $i < $len; $i++) {
        $out[] = [
            'text' => mb_substr($txt, $i, 1),
            'x'    => $x1 + ($i + 0.5) * $passo,
            'y'    => $word['y'],
            'x1'   => $x1 + $i * $passo,
            'x2'   => $x1 + ($i + 1) * $passo,
            'y1'   => $word['y1'], 'y2' => $word['y2'],
        ];
    }
    return $out;
}

/**
 * Reconstrói as notas de uma linha lendo os caracteres próximos de cada coluna.
 * Numa célula com seta de mudança ("A+ ▼ A") a nota vigente é a da direita.
 */
function gradesFromChars(array $rowWords, array $colX, array $skillKeys): array {
    $chars = [];
    foreach ($rowWords as $w) {
        foreach (explodeTokenChars($w) as $c) {
            $t = strtoupper($c['text']);
            if (preg_match('/^[A-DF+\-]$/', $t)) $chars[] = ['t' => $t, 'x' => $c['x']];
        }
    }
    usort($chars, fn($a, $b) => $a['x'] <=> $b['x']);

    // Meia-distância entre colunas define o alcance de cada uma.
    $xs = [];
    foreach ($skillKeys as $k) if (isset($colX[$k])) $xs[$k] = $colX[$k];
    if (count($xs) < 2) return [];
    $ordenadas = $xs; asort($ordenadas);
    $vals = array_values($ordenadas);
    $meio = [];
    for ($i = 0; $i < count($vals) - 1; $i++) $meio[] = ($vals[$i + 1] - $vals[$i]) / 2;
    $alcance = $meio ? min($meio) * 0.9 : 40;

    $grades = [];
    foreach ($xs as $key => $cx) {
        $doCampo = array_values(array_filter($chars, fn($c) => abs($c['x'] - $cx) <= $alcance));
        if (!$doCampo) continue;
        // Letra mais à direita = valor vigente; o sinal vem logo depois dela.
        $idxLetra = null;
        for ($i = count($doCampo) - 1; $i >= 0; $i--) {
            if (preg_match('/^[A-DF]$/', $doCampo[$i]['t'])) { $idxLetra = $i; break; }
        }
        if ($idxLetra === null) continue;
        $nota = $doCampo[$idxLetra]['t'];
        if (isset($doCampo[$idxLetra + 1]) && in_array($doCampo[$idxLetra + 1]['t'], ['+', '-'], true)) {
            $nota .= $doCampo[$idxLetra + 1]['t'];
        }
        $grades[$key] = $nota;
    }
    return $grades;
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
        'IN'     => 'in',     'MID'    => 'mid',    '3PT'  => 'pt3',
        'POST'   => 'post_d', 'PER'    => 'per_d',  'PLAY' => 'play',
        'REB'    => 'reb',    'ATHL'   => 'athl',   'IQ'   => 'iq',
        'POT'    => 'pot',    'AGE'    => '_age',   'RATING' => '_rating',
    ];
    $colX = [];
    foreach ($headerRow as $w) {
        $t = strtoupper($w['text']);
        if (isset($headerKeyMap[$t])) $colX[$headerKeyMap[$t]] = $w['x'];
    }
    if (empty($colX)) return [];

    $skillKeys   = ['in','mid','pt3','post_d','per_d','play','reb','athl','iq','pot'];
    $numericKeys = ['_age','_rating'];
    $skillStartX = min(array_filter($colX, fn($k) => in_array($k, $skillKeys), ARRAY_FILTER_USE_KEY)) - 50;
    $validGrades = ['A+','A','A-','B+','B','B-','C+','C','C-','D+','D','D-','F'];

    // Skip position codes and header tokens
    $skipTokens = ['PG','SG','SF','PF','C','G','F','NAME','POS','AGE','RATING','OVR','OVERALL'];

    $detected = [];
    for ($ri = $headerRowIdx + 1; $ri < count($rows); $ri++) {
        $row = $rows[$ri];
        usort($row, fn($a,$b) => $a['x'] <=> $b['x']);

        $nameWords   = [];
        $gradeWords  = [];
        $numericWords = [];

        foreach ($row as $w) {
            $t = strtoupper($w['text']);
            if (in_array($t, $validGrades) && $w['x'] >= $skillStartX) {
                $gradeWords[] = $w;
            } elseif (preg_match('/^\d{1,3}$/', $w['text']) && $w['x'] >= ($colX['_age'] ?? PHP_INT_MAX) - 30) {
                $numericWords[] = $w;
            } elseif ($w['x'] < $skillStartX && !in_array($t, $skipTokens) && !preg_match('/^\d+$/', $w['text'])) {
                $nameWords[] = $w;
            }
        }

        // Leitura por caractere: cobre as linhas em que o OCR cola o ruído do
        // fundo aos valores. O caminho antigo, por token inteiro, perdia a
        // maioria das notas e todos os sinais + e -.
        $grades = gradesFromChars($row, $colX, $skillKeys);
        if (empty($nameWords) && empty($grades)) continue;

        // Nome: só o que parece nome, sem posição, números ou ruído colado.
        $nomePartes = [];
        foreach ($nameWords as $nw) {
            foreach (preg_split('/0{3,}/', $nw['text']) as $pedaco) {
                $limpo = trim(preg_replace('/[^A-Za-zÀ-ÿ.\'\- ]/u', '', $pedaco));
                if ($limpo !== '' && preg_match('/[A-Za-zÀ-ÿ]{2,}|^[A-Z]\.$/u', $limpo)) {
                    $nomePartes[] = $limpo;
                }
            }
        }
        $name = trim(implode(' ', $nomePartes));
        if (!$name || empty($grades)) continue;

        // Assign numeric values to age/rating columns
        $age    = null;
        $rating = null;
        foreach ($numericWords as $nw) {
            $nearestKey  = null;
            $nearestDist = PHP_INT_MAX;
            foreach ($numericKeys as $key) {
                if (!isset($colX[$key])) continue;
                $d = abs($nw['x'] - $colX[$key]);
                if ($d < $nearestDist) { $nearestDist = $d; $nearestKey = $key; }
            }
            if ($nearestKey === '_age'    && $age    === null) $age    = (int)$nw['text'];
            if ($nearestKey === '_rating' && $rating === null) $rating = (int)$nw['text'];
        }

        if (!empty($grades)) {
            $detected[] = ['name' => $name, 'grades' => $grades, 'age' => $age, 'rating' => $rating];
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
