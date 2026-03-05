<?php
session_start();
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("SELECT nome, pontos FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar usuário: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alterar Senha - FBA games</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-dark: #121212;
            --secondary-dark: #1e1e1e;
            --border-dark: #333;
            --accent-green: #FC082B;
        }
        body {
            background-color: var(--primary-dark);
            color: #e0e0e0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar-custom {
            background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%);
            border-bottom: 1px solid var(--border-dark);
            padding: 15px 20px;
        }
        .brand-name {
            font-size: 1.3rem;
            font-weight: 900;
            background: linear-gradient(135deg, var(--accent-green), #ff5a6e);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-decoration: none;
        }
        .saldo-badge {
            background-color: var(--accent-green);
            color: #000;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 800;
            font-size: 1rem;
            box-shadow: 0 0 12px rgba(252, 8, 43, 0.25);
        }
        .form-card {
            background: var(--secondary-dark);
            border: 1px solid var(--border-dark);
            border-radius: 14px;
            padding: 24px;
            max-width: 520px;
            margin: 40px auto;
            box-shadow: 0 10px 26px rgba(0,0,0,0.35);
        }
        .btn-accent {
            background: linear-gradient(135deg, var(--accent-green), #ff5a6e);
            border: none;
            font-weight: 700;
        }
        .btn-accent:hover {
            opacity: 0.9;
        }
        .helper {
            color: #9e9e9e;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="navbar-custom d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <a href="../index.php" class="brand-name"><i class="bi bi-controller me-1"></i>FBA games</a>
            <a href="../index.php" class="btn btn-sm btn-outline-light ms-2">Voltar</a>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="saldo-badge"><i class="bi bi-coin me-1"></i><?= number_format((float)($usuario['pontos'] ?? 0), 0, ',', '.') ?> pts</span>
            <a href="../auth/logout.php" class="btn btn-sm btn-outline-danger border-0" title="Sair">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>

    <div class="form-card">
        <div class="mb-3 text-center">
            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-dark bg-opacity-75" style="width:56px;height:56px;">
                <i class="bi bi-shield-lock-fill fs-3 text-danger"></i>
            </div>
            <h4 class="mt-3 mb-1">Alterar senha</h4>
            <p class="helper mb-0">Atualize a senha da sua conta. Use uma senha forte e guarde em local seguro.</p>
        </div>

        <div id="alert-area"></div>

        <form id="changePasswordForm" class="mt-3">
            <div class="mb-3">
                <label class="form-label">Senha atual</label>
                <input type="password" name="current_password" class="form-control form-control-lg" placeholder="Digite a senha atual" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Nova senha</label>
                <input type="password" name="new_password" class="form-control form-control-lg" placeholder="Mínimo 6 caracteres" required minlength="6">
            </div>
            <div class="mb-4">
                <label class="form-label">Confirmar nova senha</label>
                <input type="password" name="confirm_password" class="form-control form-control-lg" placeholder="Repita a nova senha" required minlength="6">
            </div>
            <button type="submit" class="btn btn-accent btn-lg w-100">
                <i class="bi bi-check2-circle me-2"></i>Salvar nova senha
            </button>
        </form>
    </div>

    <script>
        const form = document.getElementById('changePasswordForm');
        const alertArea = document.getElementById('alert-area');

        function showAlert(type, message) {
            alertArea.innerHTML = `
                <div class="alert alert-${type} border-0 py-2 mb-3">
                    <i class="bi ${type === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle'} me-2"></i>${message}
                </div>
            `;
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            alertArea.innerHTML = '';
            const data = {
                current_password: form.current_password.value.trim(),
                new_password: form.new_password.value.trim(),
                confirm_password: form.confirm_password.value.trim(),
            };

            if (data.new_password.length < 6) {
                showAlert('danger', 'A nova senha deve ter pelo menos 6 caracteres.');
                return;
            }
            if (data.new_password !== data.confirm_password) {
                showAlert('danger', 'A confirmação não confere com a nova senha.');
                return;
            }

            try {
                const res = await fetch('../api/change-password.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data),
                });
                const body = await res.json();
                if (!res.ok || !body.success) {
                    throw new Error(body.error || 'Não foi possível alterar a senha.');
                }
                showAlert('success', 'Senha alterada com sucesso! Use a nova senha no próximo login.');
                form.reset();
            } catch (err) {
                showAlert('danger', err.message);
            }
        });
    </script>
</body>
</html>
