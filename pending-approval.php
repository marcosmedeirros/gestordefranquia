<?php
session_start();
require_once __DIR__ . '/backend/config.php';
require_once __DIR__ . '/backend/db.php';

// Se o usuário não está logado, redireciona para login
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

// Buscar dados do usuário
$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Se o usuário não existe ou já foi aprovado, redireciona
if (!$user) {
    header('Location: /login.php');
    exit;
}

if ($user['approved'] == 1) {
    header('Location: /dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aguardando Aprovação - FBA Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }

        .approval-card {
            max-width: 500px;
            width: 100%;
            background: var(--fba-panel);
            border: 2px solid var(--fba-brand);
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
            text-align: center;
        }

        .approval-icon {
            width: 100px;
            height: 100px;
            margin: 0 auto 30px;
            background: linear-gradient(135deg, var(--fba-brand) 0%, #ff2a44 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 50px;
            color: white;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }

        .approval-title {
            color: var(--fba-text);
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .approval-message {
            color: var(--fba-text-muted);
            font-size: 1.1rem;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .user-info {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--fba-border);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .user-info-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--fba-border);
        }

        .user-info-item:last-child {
            border-bottom: none;
        }

        .user-info-label {
            color: var(--fba-text-muted);
            font-weight: 500;
        }

        .user-info-value {
            color: var(--fba-text);
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="approval-card">
        <div class="approval-icon">
            <i class="bi bi-hourglass-split"></i>
        </div>

        <h1 class="approval-title">Aguardando Aprovação</h1>

        <p class="approval-message">
            Seu cadastro foi realizado com sucesso! <br>
            Aguarde a aprovação de um administrador para ter acesso completo ao sistema.
        </p>

        <div class="user-info">
            <div class="user-info-item">
                <span class="user-info-label">
                    <i class="bi bi-person me-2"></i>Nome
                </span>
                <span class="user-info-value"><?= htmlspecialchars($user['name']) ?></span>
            </div>
            <div class="user-info-item">
                <span class="user-info-label">
                    <i class="bi bi-envelope me-2"></i>E-mail
                </span>
                <span class="user-info-value"><?= htmlspecialchars($user['email']) ?></span>
            </div>
            <div class="user-info-item">
                <span class="user-info-label">
                    <i class="bi bi-trophy me-2"></i>Liga
                </span>
                <span class="user-info-value"><?= htmlspecialchars($user['league']) ?></span>
            </div>
            <div class="user-info-item">
                <span class="user-info-label">
                    <i class="bi bi-calendar me-2"></i>Cadastro
                </span>
                <span class="user-info-value"><?= date('d/m/Y', strtotime($user['created_at'])) ?></span>
            </div>
        </div>

        <div class="alert alert-info mb-0">
            <i class="bi bi-info-circle me-2"></i>
            Você receberá um e-mail assim que seu acesso for liberado.
        </div>

        <div class="mt-4">
            <a href="/logout.php" class="btn btn-outline-danger">
                <i class="bi bi-box-arrow-right me-2"></i>Sair
            </a>
        </div>
    </div>
</body>
</html>
