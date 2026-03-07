<?php
// resetar.php - REDEFINIÇÃO DE SENHA (FBA games)
session_start();

$token = trim($_GET['token'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir Senha - FBA games</title>

    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🔑</text></svg>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    <style>
        body, html { height: 100%; margin: 0; background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; }
        .row-full { height: 100vh; width: 100%; margin: 0; }

        .left-side {
            background: linear-gradient(135deg, #000000 0%, #1e1e1e 100%);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 50px;
            border-right: 1px solid #333;
        }

        .right-side {
            background-color: #121212;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            padding: 40px;
            background: #1e1e1e;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            border: 1px solid #333;
        }

        .brand-text { font-size: 2.4rem; font-weight: 800; margin-bottom: 20px; color: #FC082B; }
        .hero-text { font-size: 1.1rem; line-height: 1.6; opacity: 0.8; color: #aaa; }

        .form-control {
            background-color: #2b2b2b; border: 1px solid #444; color: #fff;
        }
        .form-control:focus {
            background-color: #2b2b2b; border-color: #FC082B; color: #fff; box-shadow: 0 0 0 0.25rem rgba(252, 8, 43, 0.25);
        }
        .form-label { color: #ccc; }

        .btn-success-custom {
            background-color: #FC082B; color: #000; font-weight: 800; border: none;
            transition: 0.3s;
        }
        .btn-success-custom:hover { background-color: #e00627; box-shadow: 0 0 15px rgba(252, 8, 43, 0.4); }

        @media (max-width: 768px) {
            .row-full { height: auto; }
            .left-side { padding: 40px 20px; text-align: center; border-right: none; border-bottom: 1px solid #333; }
            .right-side { padding: 40px 20px; height: auto; }
        }
    </style>
</head>
<body>

    <div class="row row-full">
        <div class="col-md-6 left-side">
            <div>
                <h1 class="brand-text">Nova senha 🔑</h1>
                <p class="hero-text">
                    Defina uma nova senha para acessar o <strong>FBA games</strong>.
                </p>
            </div>
        </div>

        <div class="col-md-6 right-side">
            <div class="login-card">
                <h3 class="text-center mb-2 fw-bold text-white"><i class="bi bi-shield-lock-fill me-2"></i>Redefinir senha</h3>
                <p class="text-center text-secondary small mb-4">Digite sua nova senha abaixo</p>

                <div id="reset-message"></div>

                <form id="form-reset">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <div class="mb-3">
                        <label class="form-label">Nova Senha</label>
                        <input type="password" name="senha" class="form-control form-control-lg" placeholder="Mínimo 6 caracteres" required minlength="6">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Confirmar Senha</label>
                        <input type="password" name="confirmar" class="form-control form-control-lg" placeholder="Digite novamente" required minlength="6">
                    </div>

                    <button type="submit" class="btn btn-success-custom btn-lg w-100 mb-3">Atualizar senha</button>

                    <div class="text-center border-top border-secondary pt-3">
                        <a href="login.php" class="btn btn-outline-light btn-sm fw-bold w-50">Voltar ao login</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

</body>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const api = (path, options = {}) => fetch(path, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
}).then(async res => {
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw body;
    return body;
});

const showMessage = (elementId, message, type = 'danger') => {
    const el = document.getElementById(elementId);
    const bg = type === 'success' ? 'success' : 'danger';
    el.innerHTML = `<div class="alert alert-${type} text-center p-2 small border-0 bg-${bg} bg-opacity-25 text-white">${message}</div>`;
};

const form = document.getElementById('form-reset');
const token = (form.querySelector('input[name="token"]').value || '').trim();

if (!token) {
    showMessage('reset-message', 'Token inválido. Solicite um novo link.');
    form.querySelector('button[type="submit"]').disabled = true;
}

form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const password = (form.senha.value || '').trim();
    const confirm = (form.confirmar.value || '').trim();

    if (!token) {
        showMessage('reset-message', 'Token inválido. Solicite um novo link.');
        return;
    }

    if (password === '' || confirm === '') {
        showMessage('reset-message', 'Preencha todos os campos.');
        return;
    }

    if (password !== confirm) {
        showMessage('reset-message', 'As senhas não coincidem.');
        return;
    }

    if (password.length < 6) {
        showMessage('reset-message', 'A senha deve ter pelo menos 6 caracteres.');
        return;
    }

    try {
        await api('../api/reset-password-confirm.php', {
            method: 'POST',
            body: JSON.stringify({ token, password })
        });

        showMessage('reset-message', 'Senha redefinida com sucesso! Redirecionando para o login...', 'success');
        setTimeout(() => {
            window.location.href = 'login.php';
        }, 2000);
    } catch (err) {
        showMessage('reset-message', err.error || 'Erro ao redefinir senha. Solicite um novo link.');
    }
});
</script>
</html>
