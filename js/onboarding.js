let currentTeamId = null;
const addedPlayers = [];

const api = (path, options = {}) => fetch(`/api/${path}`, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
}).then(async res => {
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw body;
    return body;
});

function nextStep(step) {
    // Hide all steps
    document.querySelectorAll('.step-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.step').forEach(el => el.classList.remove('active'));
    
    // Show target step
    document.getElementById(`step-${step}`).classList.add('active');
    document.getElementById(`step-indicator-${step}`).classList.add('active');
    
    // Mark previous steps as completed
    for (let i = 1; i < step; i++) {
        document.getElementById(`step-indicator-${i}`).classList.add('completed');
    }
    
    window.scrollTo(0, 0);
}

function prevStep(step) {
    nextStep(step);
}

async function saveTeamAndNext() {
    const form = document.getElementById('form-team');
    const formData = new FormData(form);
    
    const data = {
        name: formData.get('name'),
        city: formData.get('city'),
        mascot: formData.get('mascot'),
        photo_url: formData.get('photo_url')
    };
    
    if (!data.name || !data.city) {
        alert('Por favor, preencha o nome e cidade do time.');
        return;
    }
    
    try {
        const result = await api('team.php', {
            method: 'POST',
            body: JSON.stringify(data)
        });
        
        currentTeamId = result.team_id;
        nextStep(3);
    } catch (err) {
        alert(err.error || 'Erro ao criar time');
    }
}

// Form player handler
document.getElementById('form-player').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    if (!currentTeamId) {
        alert('Time não foi criado ainda. Volte e crie o time primeiro.');
        return;
    }
    
    const formData = new FormData(e.target);
    const data = {
        team_id: currentTeamId,
        name: formData.get('name'),
        age: parseInt(formData.get('age')) || 25,
        position: formData.get('position'),
        role: formData.get('role'),
        ovr: parseInt(formData.get('ovr')) || 75,
        available_for_trade: false
    };
    
    if (!data.name) {
        alert('Por favor, informe o nome do jogador.');
        return;
    }
    
    try {
        const result = await api('players.php', {
            method: 'POST',
            body: JSON.stringify(data)
        });
        
        addedPlayers.push({...data, id: result.player_id});
        renderPlayersList();
        e.target.reset();
        
        // Success message
        const alert = document.createElement('div');
        alert.className = 'alert alert-success alert-dismissible fade show';
        alert.innerHTML = `<i class="bi bi-check-circle me-2"></i>Jogador ${data.name} adicionado com sucesso!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
        document.getElementById('form-player').prepend(alert);
        setTimeout(() => alert.remove(), 3000);
    } catch (err) {
        alert(err.error || 'Erro ao adicionar jogador');
    }
});

function renderPlayersList() {
    const list = document.getElementById('players-list');
    
    if (addedPlayers.length === 0) {
        list.innerHTML = '<p class="text-muted text-center">Nenhum jogador adicionado ainda.</p>';
        return;
    }
    
    list.innerHTML = `
        <h5 class="text-white mb-3">Jogadores Adicionados (${addedPlayers.length})</h5>
        <div class="list-group">
            ${addedPlayers.map(p => `
                <div class="list-group-item bg-dark-panel text-white border-secondary d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${p.name}</strong>
                        <span class="badge bg-orange ms-2">${p.position}</span>
                        <span class="badge bg-secondary ms-1">${p.role}</span>
                        <small class="text-muted ms-2">OVR: ${p.ovr}</small>
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}

function finishOnboarding() {
    if (addedPlayers.length === 0) {
        const confirm = window.confirm('Você não adicionou nenhum jogador. Deseja prosseguir mesmo assim?');
        if (!confirm) return;
    }
    
    window.location.href = '/dashboard.php';
}

// Photo upload preview
document.getElementById('user-photo-upload')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(event) {
            document.getElementById('user-photo-preview').src = event.target.result;
        };
        reader.readAsDataURL(file);
    }
});
