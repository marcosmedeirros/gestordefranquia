<?php
session_start();
$_SESSION['user_id'] = 1; // Usar user_id = 1 para teste

require 'core/conexao.php';
require 'core/avatar.php';

// Simular POST
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['api'] = 'abrir_caixa';
$_POST['tipo_caixa'] = 'basica';

// Chamar lógica
header('Content-Type: application/json');
$user_id = $_SESSION['user_id'];
$tipo_caixa = $_POST['tipo_caixa'] ?? '';
$resultado = abrirLootBox($pdo, $user_id, $tipo_caixa);
echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
