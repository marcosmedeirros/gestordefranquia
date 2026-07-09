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

// Solicitação de participação (lista de espera — sem cadastro aberto)
document.getElementById('form-register').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = {
        name: formData.get('name'),
        phone: (formData.get('phone') || '').replace(/\D/g, '')
    };

    try {
        const result = await api('waitlist.php', {
            method: 'POST',
            body: JSON.stringify(data)
        });

        showMessage('register-message', result.message || 'Obrigado pelo contato! Você está na lista de espera.', 'success');
        e.target.reset();
    } catch (err) {
        showMessage('register-message', err.error || 'Erro ao enviar pedido', 'danger');
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
