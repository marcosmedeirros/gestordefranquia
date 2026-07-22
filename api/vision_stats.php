<?php
/**
 * Leitura da tela de estatísticas do jogo por foto (Google Cloud Vision OCR).
 *
 * Lê a aba "Per Game": NAME, POS, GS, GP, MIN, PTS, REB, AST, STL, BLK, TO...
 * Guardamos GP, MIN, PTS, REB, AST, STL e BLK.
 *
 * Como o Vision devolve só palavras soltas com coordenadas, o casamento é
 * posicional: acha a linha do cabeçalho, guarda o X de cada coluna e associa
 * cada número da linha à coluna mais próxima.
 *
 * Exclusivo da ELITE e sujeito à mesma cota de vision_skills.php.
 */
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

const STATS_LIMIT = 6;
const STATS_MONTHLY_CAP = 900;
const STATS_UNLIMITED_EMAILS = ['medeirros99@gmail.com'];
const STATS_LEAGUES = ['ELITE'];

$user = getUserSession();
$pdo  = db();

$stmtTeam = $pdo->prepare('SELECT id, league FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
if (!$team || !in_array($team['league'] ?? '', STATS_LEAGUES, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'A atualização por foto é exclusiva da ELITE. Nas demais ligas a atualização é manual.']);
    exit;
}
$teamId = (int)$team['id'];

$stmtSeason = $pdo->prepare("SELECT id, season_number FROM seasons WHERE league = ? AND status <> 'completed' ORDER BY id DESC LIMIT 1");
$stmtSeason->execute([$team['league']]);
$season = $stmtSeason->fetch(PDO::FETCH_ASSOC);
$seasonId = $season ? (int)$season['id'] : null;

// Cota: a leitura de estatísticas divide o mesmo balde da leitura de skills,
// porque as duas consomem a mesma cota do Google.
$pdo->exec("CREATE TABLE IF NOT EXISTS vision_skill_usage (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    team_id    INT NOT NULL,
    season_id  INT,
    count      INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_team_season (team_id, season_id)
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS vision_monthly_usage (
    ym    CHAR(7) NOT NULL PRIMARY KEY,
    count INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$stmtUsage = $pdo->prepare('SELECT count FROM vision_skill_usage WHERE team_id = ? AND season_id <=> ?');
$stmtUsage->execute([$teamId, $seasonId]);
$currentCount = (int)($stmtUsage->fetchColumn() ?: 0);

$isUnlimited = in_array($user['email'] ?? '', STATS_UNLIMITED_EMAILS);
if (!$isUnlimited && $currentCount >= STATS_LIMIT) {
    http_response_code(429);
    echo json_encode(['error' => 'Limite de ' . STATS_LIMIT . ' análises por temporada atingido.', 'limit' => STATS_LIMIT, 'used' => $currentCount]);
    exit;
}

$ym = date('Y-m');
$stmtMonth = $pdo->prepare('SELECT count FROM vision_monthly_usage WHERE ym = ?');
$stmtMonth->execute([$ym]);
if ((int)($stmtMonth->fetchColumn() ?: 0) >= STATS_MONTHLY_CAP) {
    http_response_code(429);
    echo json_encode(['error' => 'A liga atingiu o limite mensal de leituras por foto. Atualize manualmente ou tente no próximo mês.', 'limit' => STATS_LIMIT, 'used' => $currentCount]);
    exit;
}

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
    echo json_encode(['detected' => [], 'roster' => $rosterPlayers, 'used' => $currentCount, 'limit' => STATS_LIMIT]);
    exit;
}

$pdo->prepare('INSERT INTO vision_skill_usage (team_id, season_id, count) VALUES (?, ?, 1)
    ON DUPLICATE KEY UPDATE count = count + 1')->execute([$teamId, $seasonId]);
$currentCount++;
$pdo->prepare('INSERT INTO vision_monthly_usage (ym, count) VALUES (?, 1)
    ON DUPLICATE KEY UPDATE count = count + 1')->execute([$ym]);

$detected = parseStatsTable($annotations);
$matched  = statsMatchPlayers($detected, $rosterPlayers);

echo json_encode([
    'detected'      => $matched,
    'roster'        => $rosterPlayers,
    'used'          => $currentCount,
    'limit'         => STATS_LIMIT,
    'season_id'     => $seasonId,
    'season_number' => $season['season_number'] ?? null,
]);

// ─── Helpers ────────────────────────────────────────────────

function statsWordBounds($annotation) {
    $verts = $annotation['boundingPoly']['vertices'] ?? [];
    if (empty($verts)) return null;
    $xs = array_filter(array_column($verts, 'x'), fn($v) => $v !== null);
    $ys = array_filter(array_column($verts, 'y'), fn($v) => $v !== null);
    if (empty($xs) || empty($ys)) return null;
    return [
        'x' => (min($xs) + max($xs)) / 2,
        'y' => (min($ys) + max($ys)) / 2,
    ];
}

/**
 * O jogo mostra as colunas por jogo sempre com UMA casa decimal (0.8, 42.6).
 * O OCR às vezes perde o ponto e devolve "18" no lugar de "1.8", ou "426" no
 * lugar de "42.6" — o que inflaria a estatística em 10x. Quando o token vem
 * sem separador nessas colunas, a casa decimal é recolocada.
 * Jogos (GP) é inteiro de verdade e fica intacto.
 */
function normalizaValorEstatistica(string $raw, string $campo): float
{
    $raw = str_replace(',', '.', trim($raw));
    if ($campo === 'games') {
        return (float)round((float)$raw);
    }
    if (strpos($raw, '.') !== false) {
        return (float)$raw;
    }
    // Sem ponto: o último dígito é a casa decimal.
    return ((float)$raw) / 10;
}

function parseStatsTable($annotations) {
    array_shift($annotations); // a primeira anotação é o texto inteiro

    $words = [];
    foreach ($annotations as $ann) {
        $b = statsWordBounds($ann);
        if (!$b) continue;
        $words[] = array_merge(['text' => $ann['description']], $b);
    }
    if (empty($words)) return [];

    usort($words, function ($a, $b) {
        if (abs($a['y'] - $b['y']) < 12) return $a['x'] <=> $b['x'];
        return $a['y'] <=> $b['y'];
    });

    // Agrupa em linhas pela proximidade vertical
    $rows = [[$words[0]]];
    for ($i = 1; $i < count($words); $i++) {
        $lastY = end($rows[count($rows) - 1])['y'];
        if (abs($words[$i]['y'] - $lastY) < 12) {
            $rows[count($rows) - 1][] = $words[$i];
        } else {
            $rows[] = [$words[$i]];
        }
    }

    // Cabeçalho: a linha que tem as colunas de estatística
    $alvo = ['GP' => 'games', 'MIN' => 'min_pg', 'PTS' => 'pts_pg', 'REB' => 'reb_pg',
             'AST' => 'ast_pg', 'STL' => 'stl_pg', 'BLK' => 'blk_pg'];
    $headerRowIdx = -1;
    $colX = [];
    foreach ($rows as $ri => $row) {
        $texts = array_map(fn($w) => strtoupper(trim($w['text'])), $row);
        $hits = count(array_intersect(array_keys($alvo), $texts));
        if ($hits >= 4) {
            $headerRowIdx = $ri;
            foreach ($row as $w) {
                $t = strtoupper(trim($w['text']));
                if (isset($alvo[$t]) && !isset($colX[$alvo[$t]])) $colX[$alvo[$t]] = $w['x'];
            }
            break;
        }
    }
    if ($headerRowIdx < 0 || count($colX) < 4) return [];

    // Colunas que existem na tela mas não guardamos — precisam ser conhecidas
    // para não "roubar" um número da coluna vizinha que nos interessa.
    $ignoradas = [];
    foreach ($rows[$headerRowIdx] as $w) {
        $t = strtoupper(trim($w['text']));
        if (in_array($t, ['GS', 'TO', 'FLS', 'FG%', 'FG', '3P%', 'FT%', 'POS'], true)) {
            $ignoradas[] = $w['x'];
        }
    }

    $primeiraColX = min($colX);
    $skipTokens = ['PG','SG','SF','PF','C','G','F','NAME','POS','GS','GP','MIN','PTS',
                   'REB','AST','STL','BLK','TO','FLS'];

    $detected = [];
    for ($ri = $headerRowIdx + 1; $ri < count($rows); $ri++) {
        $row = $rows[$ri];
        usort($row, fn($a, $b) => $a['x'] <=> $b['x']);

        $nameWords = [];
        $numeros   = [];
        foreach ($row as $w) {
            $t = trim($w['text']);
            // Números aceitos: inteiros (82) e decimais (42.6). Guardamos o
            // texto cru porque a ausência de ponto precisa ser tratada depois.
            if (preg_match('/^\d{1,3}([.,]\d)?$/', $t) && $w['x'] >= $primeiraColX - 40) {
                $numeros[] = ['x' => $w['x'], 'raw' => $t];
            } elseif ($w['x'] < $primeiraColX - 40) {
                // O OCR captura lixo gráfico (bordas do realce) junto do nome;
                // só sobrevive o que parece nome mesmo.
                $limpo = preg_replace('/[^A-Za-zÀ-ÿ.\'\- ]/u', '', $t);
                $limpo = trim($limpo);
                if ($limpo !== ''
                    && !in_array(strtoupper($limpo), $skipTokens, true)
                    && preg_match('/[A-Za-zÀ-ÿ]/u', $limpo)) {
                    $nameWords[] = $limpo;
                }
            }
        }
        if (empty($nameWords) || count($numeros) < 3) continue;

        // Cada número vai para a coluna cujo X é o mais próximo. Se a coluna
        // mais próxima for uma das ignoradas, o número é descartado.
        $valores = [];
        foreach ($numeros as $n) {
            $melhorCampo = null; $melhorDist = PHP_INT_MAX;
            foreach ($colX as $campo => $x) {
                $d = abs($n['x'] - $x);
                if ($d < $melhorDist) { $melhorDist = $d; $melhorCampo = $campo; }
            }
            foreach ($ignoradas as $ix) {
                if (abs($n['x'] - $ix) < $melhorDist) { $melhorCampo = null; break; }
            }
            // Longe demais de qualquer coluna conhecida: provavelmente outra coluna
            if ($melhorCampo === null || $melhorDist > 60) continue;
            if (!isset($valores[$melhorCampo])) {
                $valores[$melhorCampo] = normalizaValorEstatistica($n['raw'], $melhorCampo);
            }
        }
        if (count($valores) < 3) continue;

        $detected[] = [
            'name'   => implode(' ', $nameWords),
            'games'  => (int)($valores['games'] ?? 0),
            'min_pg' => (float)($valores['min_pg'] ?? 0),
            'pts_pg' => (float)($valores['pts_pg'] ?? 0),
            'reb_pg' => (float)($valores['reb_pg'] ?? 0),
            'ast_pg' => (float)($valores['ast_pg'] ?? 0),
            'stl_pg' => (float)($valores['stl_pg'] ?? 0),
            'blk_pg' => (float)($valores['blk_pg'] ?? 0),
        ];
    }

    return $detected;
}

/** Casa "J. Wall" com "John Wall": sobrenome igual + inicial do nome. */
function statsMatchPlayers($detected, $rosterPlayers) {
    foreach ($detected as &$d) {
        $d['player_id'] = null;
        $d['matched_name'] = null;

        $det = mb_strtolower(trim($d['name']));
        $bestScore = 0; $bestPlayer = null;

        foreach ($rosterPlayers as $rp) {
            $ros = mb_strtolower(trim($rp['name']));
            if ($det === $ros) {
                $d['player_id'] = $rp['id'];
                $d['matched_name'] = $rp['name'];
                break;
            }
            $detParts = preg_split('/\s+/', $det);
            $rosParts = preg_split('/\s+/', $ros);
            $detLast = end($detParts);
            $rosLast = end($rosParts);
            $score = 0;
            if ($detLast === $rosLast) {
                $score = 80;
                $di = ltrim($detParts[0] ?? '', '');
                $ri = $rosParts[0] ?? '';
                if ($di !== '' && $ri !== '' && $di[0] === $ri[0]) $score = 95;
            }
            similar_text($det, $ros, $pct);
            if ($pct > $score) $score = $pct;
            if ($score > $bestScore) { $bestScore = $score; $bestPlayer = $rp; }
        }

        if (!$d['player_id'] && $bestPlayer && $bestScore >= 60) {
            $d['player_id'] = $bestPlayer['id'];
            $d['matched_name'] = $bestPlayer['name'];
        }
    }
    return $detected;
}
