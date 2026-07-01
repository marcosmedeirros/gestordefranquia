<?php
session_start();
require '../core/conexao.php';

header('Content-Type: application/json');

// Acesso exclusivo para medeirros99@gmail.com
$email = $_SESSION['email'] ?? '';
if ($email !== 'medeirros99@gmail.com') {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Sem permissão']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método inválido']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$game = trim($data['game'] ?? 'desconhecido');

$MOEDAS = 200;

try {
    $pdo->beginTransaction();

    $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :id")
        ->execute([':val' => $MOEDAS, ':id' => $userId]);

    $stmt = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $novoSaldo = (int)$stmt->fetchColumn();

    $pdo->commit();

    echo json_encode([
        'sucesso'    => true,
        'game'       => $game,
        'moedas'     => $MOEDAS,
        'novo_saldo' => $novoSaldo,
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
}
