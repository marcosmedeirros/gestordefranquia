// Permite definir um prefixo (ex.: /public) via data-base no body para cenários em subpastas.
const BASE = document.body.dataset.base || '';

bindForm('form-register', 'register-result', 'register.php');
bindForm('form-login', 'login-result', 'login.php');
bindForm('form-team', 'team-result', 'team.php');
bindForm('form-player', 'player-result', 'players.php');
const api = (path, options = {}) => fetch(`${BASE}/api/${path}`, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
}).then(async res => {
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw body;
    return body;
});

const serializeForm = (form) => {
    const formData = new FormData(form);
    const data = {};
    formData.forEach((v, k) => { data[k] = v; });
    form.querySelectorAll('input[type="checkbox"]').forEach(cb => {
        data[cb.name] = cb.checked;
    });
    ['age','ovr','user_id','team_id','division_id'].forEach(key => {
        if (data[key] !== undefined && data[key] !== '') data[key] = Number(data[key]);
    });
    return data;
};

const bindForm = (formId, resultId, apiPath) => {
    const form = document.getElementById(formId);
    const output = document.getElementById(resultId);
    if (!form || !output) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const data = serializeForm(form);
        try {
            const res = await api(apiPath, { method: 'POST', body: JSON.stringify(data) });
            output.textContent = JSON.stringify(res, null, 2);
        } catch (err) {
            output.textContent = JSON.stringify(err, null, 2);
        }
    });
};

bindForm('form-register', 'register-result', 'register.php');
bindForm('form-login', 'login-result', 'login.php');
bindForm('form-team', 'team-result', 'team.php');
bindForm('form-player', 'player-result', 'players.php');

const teamsList = document.getElementById('teams-list');
const btnLoadTeams = document.getElementById('btn-load-teams');
if (teamsList && btnLoadTeams) {
    btnLoadTeams.addEventListener('click', async () => {
        teamsList.textContent = 'Carregando...';
        try {
            const res = await api('team.php');
            teamsList.textContent = JSON.stringify(res, null, 2);
        } catch (err) {
            teamsList.textContent = JSON.stringify(err, null, 2);
        }
    });
}

const rosterGrid = document.getElementById('roster-grid');
const rosterStatus = document.getElementById('roster-status');

const placeholderUser = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120" fill="none"%3E%3Crect width="120" height="120" rx="24" fill="%230c1426"/%3E%3Cpath d="M60 62c11 0 20-9 20-20S71 22 60 22 40 31 40 42s9 20 20 20Z" fill="%2338bdf8"/%3E%3Cpath d="M30 96c4-14 16-24 30-24s26 10 30 24" stroke="%2338bdf8" stroke-width="8" stroke-linecap="round"/%3E%3C/svg%3E';
const placeholderTeam = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120" fill="none"%3E%3Crect width="120" height="120" rx="20" fill="%23111827"/%3E%3Cpath d="M32 80l26-40 30 40H32Z" fill="%23256fe8"/%3E%3Ccircle cx="58" cy="48" r="10" fill="%2338bdf8"/%3E%3C/svg%3E';

const renderRosterCard = (team) => {
    const players = team.players || [];
    const picks = team.picks || [];
    const playerList = players.map(p => {
        const trade = p.available_for_trade ? '<span class="badge trade">Disponivel para troca</span>' : '<span class="badge notrade">Fechado para troca</span>';
        return `<div class="player-row">
            <div>
                <div><strong>${p.name}</strong> · ${p.position}</div>
                <div class="player-meta">OVR ${p.ovr} · ${p.age} anos · ${p.role}</div>
            </div>
            ${trade}
        </div>`;
    }).join('') || '<div class="muted">Sem jogadores cadastrados.</div>';

    const pickList = picks.map(pk => `${pk.season_year} R${pk.round}`).join(' · ') || 'Sem picks';

    return `<article class="roster-card">
        <div class="roster-head">
            <div class="avatar-stack">
                <img class="avatar" src="${team.user_photo || placeholderUser}" alt="GM" />
                <img class="avatar small" src="${team.team_photo || placeholderTeam}" alt="Time" />
                <div>
                    <div><strong>${team.name}</strong></div>
                    <div class="muted">${team.city} · ${team.mascot}</div>
                    <div class="muted">GM: ${team.user_name || 'N/D'}</div>
                </div>
            </div>
            <div class="cap">CAP top 8: ${team.cap_top8}</div>
        </div>
        <div class="muted">Divisão: ${team.division_name || 'N/D'} · Picks: ${pickList}</div>
        <div class="player-list">${playerList}</div>
    </article>`;
};

const loadRosters = async () => {
    if (!rosterGrid) return;
    rosterStatus.textContent = 'Carregando...';
    rosterGrid.innerHTML = '';
    try {
        const res = await api('rosters.php');
        rosterStatus.textContent = `${res.teams.length} time(s) carregado(s)`;
        rosterGrid.innerHTML = res.teams.map(renderRosterCard).join('');
    } catch (err) {
        rosterStatus.textContent = 'Erro ao carregar';
        rosterGrid.innerHTML = `<pre>${JSON.stringify(err, null, 2)}</pre>`;
    }
};

if (rosterGrid) {
    const btn = document.getElementById('btn-refresh-rosters');
    if (btn) btn.addEventListener('click', loadRosters);
    loadRosters();
}
