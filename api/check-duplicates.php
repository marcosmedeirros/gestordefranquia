<?php
session_start();
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';

$user = getUserSession();
if (!$user || $user['user_type'] !== 'admin') {
    die('Acesso negado');
}

$pdo = db();

// Verificar duplicatas por ID
$stmt = $pdo->prepare('SELECT id, COUNT(*) as cnt FROM teams GROUP BY id HAVING cnt > 1');
$stmt->execute();
$duplicates = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'message' => 'Verificação de duplicatas',
    'duplicates' => $duplicates,
    'total' => count($duplicates)
]);
?>
