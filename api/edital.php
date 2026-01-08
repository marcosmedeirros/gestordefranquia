<?php
session_start();

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

$user = getUserSession();
if (!$user) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Usuário não autenticado']);
    exit;
}

$pdo = db();
$action = $_GET['action'] ?? $_POST['action'] ?? null;

// Download de edital - permitido para todos os usuários
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'download_edital') {
    $league = $_GET['league'] ?? null;
    
    if (!$league || !in_array($league, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Liga inválida']);
        exit;
    }
    
    // Buscar arquivo
    $stmt = $pdo->prepare("SELECT edital_file FROM league_settings WHERE league = ?");
    $stmt->execute([$league]);
    $result = $stmt->fetch();
    
    if (!$result || !$result['edital_file']) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Edital não encontrado']);
        exit;
    }
    
    $filePath = dirname(__DIR__) . '/uploads/editais/' . $result['edital_file'];
    
    if (!file_exists($filePath)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Arquivo não encontrado']);
        exit;
    }
    
    // Download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

// Verificar se é admin para ações de modificação
if (($user['user_type'] ?? 'jogador') !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Acesso negado']);
    exit;
}

header('Content-Type: application/json');

// Upload de edital - apenas admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_edital') {
    $league = $_POST['league'] ?? null;
    
    if (!$league || !in_array($league, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Liga inválida']);
        exit;
    }
    
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Arquivo não enviado ou erro no upload']);
        exit;
    }
    
    $file = $_FILES['file'];
    $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $maxSize = 10 * 1024 * 1024; // 10MB
    
    // Validar tipo
    if (!in_array($file['type'], $allowedTypes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Apenas arquivos PDF ou Word são permitidos']);
        exit;
    }
    
    // Validar tamanho
    if ($file['size'] > $maxSize) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Arquivo muito grande. Máximo: 10MB']);
        exit;
    }
    
    // Criar diretório se não existir
    $uploadDir = dirname(__DIR__) . '/uploads/editais';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Extensão do arquivo
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = strtolower($league) . '_edital.' . $ext;
    $filePath = $uploadDir . '/' . $fileName;
    
    // Deletar arquivo antigo se existir
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    
    // Mover arquivo
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao salvar arquivo']);
        exit;
    }
    
    // Atualizar banco de dados
    try {
        $stmt = $pdo->prepare('UPDATE league_settings SET edital_file = ? WHERE league = ?');
        $stmt->execute([$fileName, $league]);
        
        echo json_encode(['success' => true, 'file_name' => $fileName]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Deletar edital - apenas admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_edital') {
    $data = json_decode(file_get_contents('php://input'), true);
    $league = $data['league'] ?? null;
    
    if (!$league || !in_array($league, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Liga inválida']);
        exit;
    }
    
    try {
        // Buscar arquivo atual
        $stmt = $pdo->prepare("SELECT edital_file FROM league_settings WHERE league = ?");
        $stmt->execute([$league]);
        $result = $stmt->fetch();
        
        if ($result && $result['edital_file']) {
            $filePath = dirname(__DIR__) . '/uploads/editais/' . $result['edital_file'];
            
            // Deletar arquivo físico se existir
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        
        // Limpar registro no banco
        $stmt = $pdo->prepare("UPDATE league_settings SET edital_file = NULL WHERE league = ?");
        $stmt->execute([$league]);
        
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao deletar edital: ' . $e->getMessage()]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Ação inválida']);
