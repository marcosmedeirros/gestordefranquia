<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Redefinir Senha - FBA Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css" />
</head>
<body class="login-page">
    <div class="container vh-100 d-flex align-items-center justify-content-center">
        <div class="card bg-dark-panel border-orange" style="max-width: 500px; width: 100%;">
            <div class="card-body p-5">
                <div class="text-center mb-4">
                    <img src="/img/fba-logo.png" alt="FBA" height="60" class="mb-3">
                    <h2 class="text-white fw-bold mb-2">Redefinir Senha</h2>
                    <p class="text-light-gray">Crie uma nova senha para sua conta</p>
                </div>

                <div id="reset-message"></div>

                <form id="form-reset-password">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['token'] ?? '') ?>">
                    
                    <div class="mb-3">
                        <label class="form-label text-light-gray">Nova Senha</label>
                        <input type="password" name="password" class="form-control form-control-lg" 
                               placeholder="Mínimo 6 caracteres" required minlength="6">
                        <small class="text-light-gray">A senha deve ter pelo menos 6 caracteres</small>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label text-light-gray">Confirmar Nova Senha</label>
                        <input type="password" name="password_confirm" class="form-control form-control-lg" 
                               placeholder="Digite a senha novamente" required minlength="6">
                    </div>
                    
                    <button type="submit" class="btn btn-orange btn-lg w-100 mb-3">
                        <i class="bi bi-check-circle me-2"></i>Redefinir Senha
                    </button>
                    
                    <div class="text-center">
                        <a href="/login.php" class="text-orange text-decoration-none">
                            <i class="bi bi-arrow-left me-1"></i>Voltar para o login
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const api = (path, options = {}) => fetch(`/api/${path}`, {
            headers: { 'Content-Type': 'application/json' },
            ...options,
        }).then(async res => {
            const body = await res.json().catch(() => ({}));
            if (!res.ok) throw body;
            return body;
        });

        const showMessage = (elementId, message, type = 'danger') => {
            const el = document.getElementById(elementId);
            el.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>`;
        };

        document.getElementById('form-reset-password').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            
            const password = formData.get('password');
            const passwordConfirm = formData.get('password_confirm');
            const token = formData.get('token');

            if (!token) {
                showMessage('reset-message', 'Token inválido. Solicite um novo link de recuperação.', 'danger');
                return;
            }

            if (password !== passwordConfirm) {
                showMessage('reset-message', 'As senhas não coincidem.', 'danger');
                return;
            }

            if (password.length < 6) {
                showMessage('reset-message', 'A senha deve ter pelo menos 6 caracteres.', 'danger');
                return;
            }

            try {
                const result = await api('reset-password-confirm.php', {
                    method: 'POST',
                    body: JSON.stringify({ token, password })
                });
                
                showMessage('reset-message', 'Senha redefinida com sucesso! Redirecionando...', 'success');
                setTimeout(() => {
                    window.location.href = '/login.php';
                }, 2000);
            } catch (err) {
                showMessage('reset-message', err.error || 'Erro ao redefinir senha', 'danger');
            }
        });
    </script>
</body>
</html>
