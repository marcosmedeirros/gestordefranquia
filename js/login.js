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

// Login form
document.getElementById('form-login').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = {
        email: formData.get('email'),
        password: formData.get('password')
    };

    try {
        const result = await api('login.php', {
            method: 'POST',
            body: JSON.stringify(data)
        });
        
        showMessage('login-message', 'Login realizado com sucesso! Redirecionando...', 'success');
        setTimeout(() => {
            window.location.href = '/dashboard.php';
        }, 1000);
    } catch (err) {
        showMessage('login-message', err.error || 'Erro ao fazer login', 'danger');
    }
});

// Register form
document.getElementById('form-register').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = {
        name: formData.get('name'),
        email: formData.get('email'),
        password: formData.get('password'),
        league: formData.get('league'),
        user_type: 'jogador'
    };

    try {
        const result = await api('register.php', {
            method: 'POST',
            body: JSON.stringify(data)
        });
        
        showMessage('register-message', 'Cadastro realizado! Redirecionando para configuração...', 'success');
        // Redireciona para onboarding
        setTimeout(() => {
            window.location.href = '/onboarding.php';
        }, 1500);
    } catch (err) {
        showMessage('register-message', err.error || 'Erro ao cadastrar', 'danger');
    }
});

// Forgot password form
document.getElementById('form-forgot-password').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = {
        email: formData.get('email')
    };

    try {
        const result = await api('reset-password.php', {
            method: 'POST',
            body: JSON.stringify(data)
        });
        
        showMessage('forgot-password-message', result.message || 'Link de recuperação enviado! Verifique seu e-mail.', 'success');
        e.target.reset();
        
        // Fecha o modal após 3 segundos
        setTimeout(() => {
            const modal = bootstrap.Modal.getInstance(document.getElementById('forgotPasswordModal'));
            modal.hide();
        }, 3000);
    } catch (err) {
        showMessage('forgot-password-message', err.error || 'Erro ao enviar e-mail de recuperação', 'danger');
    }
});
