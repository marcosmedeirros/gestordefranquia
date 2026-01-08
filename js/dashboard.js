const api = (path, options = {}) => fetch(`/api/${path}`, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
}).then(async res => {
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw body;
    return body;
});

const showMessage = (elementId, message, type = 'success') => {
    const el = document.getElementById(elementId);
    el.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>`;
    setTimeout(() => { el.innerHTML = ''; }, 5000);
};

// Criar time
document.getElementById('form-team').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = {
        user_id: parseInt(formData.get('user_id')),
        name: formData.get('name'),
        city: formData.get('city'),
        mascot: formData.get('mascot'),
        photo_url: formData.get('photo_url') || null
    };

    try {
        const result = await api('team.php', { method: 'POST', body: JSON.stringify(data) });
        showMessage('team-message', 'Time criado com sucesso!', 'success');
        e.target.reset();
        loadTeams();
    } catch (err) {
        showMessage('team-message', err.error || 'Erro ao criar time', 'danger');
    }
});

// Adicionar jogador
document.getElementById('form-player').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = {
        team_id: parseInt(formData.get('team_id')),
        name: formData.get('name'),
        age: parseInt(formData.get('age')),
        position: formData.get('position'),
        role: formData.get('role'),
        ovr: parseInt(formData.get('ovr')),
        available_for_trade: formData.get('available_for_trade') === 'on'
    };

    try {
        const result = await api('players.php', { method: 'POST', body: JSON.stringify(data) });
        showMessage('player-message', 'Jogador adicionado com sucesso!', 'success');
        e.target.reset();
    } catch (err) {
        showMessage('player-message', err.error || 'Erro ao adicionar jogador', 'danger');
    }
});

// Carregar times
const loadTeams = async () => {
    const list = document.getElementById('teams-list');
    list.innerHTML = '<div class="text-center"><div class="spinner-border text-orange" role="status"></div></div>';
    
    try {
        const result = await api('team.php');
        if (!result.teams || result.teams.length === 0) {
            list.innerHTML = '<p class="text-muted text-center">Nenhum time encontrado. Crie seu primeiro time!</p>';
            return;
        }

        const html = `
            <table class="table table-dark table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Cidade</th>
                        <th>Mascote</th>
                        <th>Divisão</th>
                    </tr>
                </thead>
                <tbody>
                    ${result.teams.map(t => `
                        <tr>
                            <td>${t.id}</td>
                            <td><strong class="text-orange">${t.name}</strong></td>
                            <td>${t.city}</td>
                            <td>${t.mascot}</td>
                            <td>${t.division_name || 'N/A'}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
        list.innerHTML = html;
    } catch (err) {
        list.innerHTML = `<div class="alert alert-danger">Erro ao carregar times</div>`;
    }
};

document.getElementById('btn-load-teams').addEventListener('click', loadTeams);
loadTeams();
