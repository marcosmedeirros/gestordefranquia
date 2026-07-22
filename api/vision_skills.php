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

// Quantas leituras por time por temporada. 92 times (ELITE+NEXT+RISE) x 4
// temporadas/mes x 4 = 1.472 no pior caso. Passa ~472 da cota gratuita de
// 1.000/mes, o que custa cerca de US$ 0,71 (~R$ 3,60) por mes.
const VISION_LIMIT = 4;
// Freio geral: por mais que os times individualmente respeitem o limite, a liga
// inteira nunca passa disto no mes, para o excedente nao fugir do previsto.
const VISION_MONTHLY_CAP = 1600;
const VISION_UNLIMITED_EMAILS = ['medeirros99@gmail.com'];
// Ligas com leitura por foto liberada (ROOKIE fica de fora).
const VISION_LEAGUES = ['ELITE', 'NEXT', 'RISE'];

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

// Ligas com leitura por foto liberada.
if (!$team || !in_array($team['league'] ?? '', VISION_LEAGUES, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'A atualização por foto não está disponível para a sua liga.']);
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

// O Google cobra a chamada mesmo quando nao acha texto nenhum, entao o uso e
// contado assim que a resposta chega. Contar so no sucesso deixaria uma imagem
// ruim repetida furar o freio sem aparecer em lugar nenhum.
$pdo->prepare('INSERT INTO vision_skill_usage (team_id, season_id, count) VALUES (?, ?, 1)
    ON DUPLICATE KEY UPDATE count = count + 1')
    ->execute([$teamId, $seasonId]);
$currentCount++;
$pdo->prepare('INSERT INTO vision_monthly_usage (ym, count) VALUES (?, 1)
    ON DUPLICATE KEY UPDATE count = count + 1')
    ->execute([$ym]);

$annotations = $visionData['responses'][0]['textAnnotations'] ?? [];
if (empty($annotations)) {
    echo json_encode(['detected' => [], 'roster' => $rosterPlayers, 'used' => $currentCount, 'limit' => VISION_LIMIT]);
    exit;
}

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

    $xs = [];
    foreach ($skillKeys as $k) if (isset($colX[$k])) $xs[$k] = $colX[$k];
    if (count($xs) < 2) return [];
    asort($xs);
    $chaves = array_keys($xs);
    $vals   = array_values($xs);

    $grades = [];
    foreach ($chaves as $i => $key) {
        // Os valores são alinhados à esquerda, começando um pouco antes do
        // centro do cabeçalho, e a célula se estende para a direita quando tem
        // seta de mudança ("A+ ▼A"). Por isso a janela é assimétrica, e
        // proporcional ao espaçamento das colunas em vez de um valor fixo.
        $gapDir = isset($vals[$i + 1]) ? $vals[$i + 1] - $vals[$i] : ($vals[$i] - $vals[$i - 1]);
        $ini = $vals[$i] - $gapDir * 0.25;
        $fim = $vals[$i] + $gapDir * 0.65;

        $doCampo = array_values(array_filter($chars, fn($c) => $c['x'] >= $ini && $c['x'] <= $fim));
        if (!$doCampo) continue;

        // Nota vigente = a letra mais à direita da célula; o sinal vem logo
        // depois dela. Antes da seta fica o valor anterior, que é descartado.
        $idxLetra = null;
        for ($j = count($doCampo) - 1; $j >= 0; $j--) {
            if (preg_match('/^[A-DF]$/', $doCampo[$j]['t'])) { $idxLetra = $j; break; }
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

/**
 * Nome, idade e rating pelas colunas do cabeçalho.
 *
 * O nome fica à esquerda da coluna POS; sem esse corte, a sigla da posição
 * entra colada no nome ("J. Johnson SG"). Idade e rating são lidos dígito a
 * dígito, porque o rating chega junto com a variação ("91-2" = 91, delta -2).
 */
function nameAgeRatingFromChars(array $rowWords, array $colX): array {
    $chars = [];
    foreach ($rowWords as $w) {
        foreach (explodeTokenChars($w) as $c) $chars[] = $c;
    }
    usort($chars, fn($a, $b) => $a['x'] <=> $b['x']);

    $posX    = $colX['_pos']    ?? null;
    $ageX    = $colX['_age']    ?? null;
    $ratingX = $colX['_rating'] ?? null;
    $nameX   = $colX['_name']   ?? 0;

    // Limite do nome: bem antes da coluna de posição.
    $limiteNome = $posX !== null ? $nameX + ($posX - $nameX) * 0.6 : ($ageX ?? PHP_INT_MAX);

    $nome = '';
    foreach ($chars as $c) {
        if ($c['x'] >= $limiteNome) continue;
        if (preg_match('/^[A-Za-zÀ-ÿ.\'\-]$/u', $c['text'])) $nome .= $c['text'];
        elseif ($nome !== '' && substr($nome, -1) !== ' ') $nome .= ' ';
    }
    $nome = trim(preg_replace('/\s+/', ' ', $nome));

    // Idade e rating só saem de um token inteiro e limpo ("34", "94"). Quando o
    // ruído do fundo cola tudo num token só, montar o número caractere a
    // caractere produzia valores plausíveis mas errados (94 virava 40) — e um
    // rating errado vira OVR errado. Nesses casos preferimos devolver nada e
    // deixar o campo para o GM preencher na revisão.
    $numero = function (?float $cx, int $min, int $max) use ($rowWords): ?int {
        if ($cx === null) return null;
        $melhor = null; $melhorDist = PHP_INT_MAX;
        foreach ($rowWords as $w) {
            $t = trim($w['text']);
            if (!preg_match('/^\d{2}$/', $t)) continue;
            $v = (int)$t;
            if ($v < $min || $v > $max) continue;
            $d = abs($w['x'] - $cx);
            if ($d <= 45 && $d < $melhorDist) { $melhorDist = $d; $melhor = $v; }
        }
        return $melhor;
    };

    return [
        'name'   => $nome,
        'age'    => $numero($ageX, 15, 50),
        'rating' => $numero($ratingX, 40, 99),
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
        'IN'     => 'in',     'MID'    => 'mid',    '3PT'  => 'pt3',
        'POST'   => 'post_d', 'PER'    => 'per_d',  'PLAY' => 'play',
        'REB'    => 'reb',    'ATHL'   => 'athl',   'IQ'   => 'iq',
        'POT'    => 'pot',    'AGE'    => '_age',   'RATING' => '_rating',
        // NAME e POS delimitam onde o nome termina — sem POS, a sigla da
        // posição vem colada no nome.
        'NAME'   => '_name',  'POS'    => '_pos',
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
        // Tudo pela leitura por caractere, ancorada nas colunas do cabeçalho.
        $grades = gradesFromChars($row, $colX, $skillKeys);
        // Uma linha de jogador tem as 10 notas. Exigir a maioria descarta
        // linhas de interface ("Sort", "View") que caem na mesma faixa.
        if (count($grades) < 6) continue;

        $info = nameAgeRatingFromChars($row, $colX);
        $name = $info['name'];
        if ($name === '' || !preg_match('/[A-Za-zÀ-ÿ]{2,}/u', $name)) continue;

        $detected[] = [
            'name'   => $name,
            'grades' => $grades,
            'age'    => $info['age'],
            'rating' => $info['rating'],
        ];
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
