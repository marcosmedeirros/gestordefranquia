<?php
/**
 * Upload de arquivo de vídeo para os slots de liga (Progression, Sistemas,
 * Free Agency), alternativa a colar um link do YouTube/Vimeo direto no campo.
 * O arquivo salvo vira uma URL normal em league_settings — o dashboard já
 * reconhece extensão de vídeo direto (resolveVideoEmbed() em helpers.php) e
 * toca num <video> nativo, com suporte a captura de frame.
 */

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

header('Content-Type: application/json; charset=utf-8');

$user = getUserSession();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Usuário não autenticado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$pdo = db();

try {
    $pdo->exec("ALTER TABLE league_settings ADD COLUMN IF NOT EXISTS progression_video_url TEXT NULL");
    $pdo->exec("ALTER TABLE league_settings ADD COLUMN IF NOT EXISTS sistemas_video_url TEXT NULL");
    $pdo->exec("ALTER TABLE league_settings ADD COLUMN IF NOT EXISTS freeagency_video_url TEXT NULL");
} catch (Exception $e) { /* silencia — coluna pode já existir */ }

$validLeagues = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
$slotColumns = [
    'progression' => 'progression_video_url',
    'sistemas'    => 'sistemas_video_url',
    'freeagency'  => 'freeagency_video_url',
];

$league = strtoupper(trim($_POST['league'] ?? ''));
$slot   = $_POST['slot'] ?? '';

if (!in_array($league, $validLeagues, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Liga inválida']);
    exit;
}
if (!isset($slotColumns[$slot])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Vídeo inválido']);
    exit;
}

$isGlobalAdmin = ($user['user_type'] ?? 'jogador') === 'admin';
if (!$isGlobalAdmin && !isLeagueAdmin($pdo, (int)$user['id'], $league)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Você não tem permissão para administrar esta liga.']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['file']['error'] ?? null;
    $msg = in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        ? 'Arquivo muito grande para o limite do servidor.'
        : 'Arquivo não enviado ou erro no upload.';
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$file = $_FILES['file'];
$allowedExt = ['mp4', 'webm', 'mov', 'ogg', 'ogv'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Formato não suportado. Use mp4, webm, mov ou ogg.']);
    exit;
}

$maxSize = 300 * 1024 * 1024; // 300MB
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Arquivo muito grande. Máximo: 300MB.']);
    exit;
}

$uploadDir = dirname(__DIR__) . '/uploads/league-videos';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$slug = strtolower($league) . '_' . $slot;
foreach (glob($uploadDir . '/' . $slug . '_*') ?: [] as $old) {
    @unlink($old);
}

$fileName = $slug . '_' . time() . '.' . $ext;
$filePath = $uploadDir . '/' . $fileName;

if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao salvar arquivo']);
    exit;
}

$url = '/uploads/league-videos/' . $fileName;
$column = $slotColumns[$slot];

try {
    $stmtCheck = $pdo->prepare('SELECT id FROM league_settings WHERE league = ?');
    $stmtCheck->execute([$league]);
    if ($stmtCheck->fetch()) {
        $pdo->prepare("UPDATE league_settings SET {$column} = ? WHERE league = ?")->execute([$url, $league]);
    } else {
        $pdo->prepare("INSERT INTO league_settings (league, {$column}) VALUES (?, ?)")->execute([$league, $url]);
    }
    echo json_encode(['success' => true, 'url' => $url]);
} catch (Exception $e) {
    @unlink($filePath);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao salvar no banco de dados.']);
}
