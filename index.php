<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    session_start();
    require_once __DIR__ . '/backend/auth.php';

    // Se não estiver logado, redireciona para login
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }

    // Redireciona para dashboard
    header('Location: /dashboard.php');
    exit;
} catch (Exception $e) {
    error_log('Erro no index.php: ' . $e->getMessage());
    http_response_code(500);
    echo '<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Erro - FBA Manager</title>
    <style>
        body { font-family: system-ui; background: #000; color: #fff; padding: 40px; }
        .error { background: #1a1a1a; border-left: 4px solid #f17507; padding: 20px; border-radius: 8px; }
        h1 { color: #f17507; }
        code { background: #2a2a2a; padding: 2px 8px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="error">
        <h1>⚠️ Erro no Servidor</h1>
        <p>Ocorreu um erro ao processar sua solicitação.</p>
        <p><strong>Possível causa:</strong> Schema do banco de dados desatualizado.</p>
        <p><strong>Solução:</strong> Execute a migração acessando: <code>https://marcosmedeiros.page/backend/migrate.php</code></p>
    </div>
</body>
</html>';
    exit;
}
