const api = (path, options = {}) => fetch(`/api/${path}`, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
}).then(async res => {
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw body;
    return body;
});

const placeholderUser = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="120" height="120" fill="%23f17507"%3E%3Crect width="120" height="120" rx="12" fill="%23121212"/%3E%3Ctext x="50%25" y="50%25" text-anchor="middle" dy=".3em" fill="%23f17507" font-size="48" font-weight="bold"%3EGM%3C/text%3E%3C/svg%3E';

const placeholderTeam = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="120" height="120" fill="%23f17507"%3E%3Crect width="120" height="120" rx="12" fill="%23121212"/%3E%3Ctext x="50%25" y="50%25" text-anchor="middle" dy=".3em" fill="%23f17507" font-size="48" font-weight="bold"%3E%F0%9F%8F%80%3C/text%3E%3C/svg%3E';

const renderRosterCard = (team) => {
    const players = team.players || [];
    const picks = team.picks || [];
    
    const playerList = players.map(p => {
        const tradeClass = p.available_for_trade ? 'trade' : 'notrade';
        const tradeText = p.available_for_trade ? 'Disponível' : 'Fechado';
        
        // Define a foto
        const customPhoto = (p.foto_adicional || '').toString().trim();
        const photoUrl = customPhoto
            ? customPhoto
            : (p.nba_player_id
                ? `https://cdn.nba.com/headshots/nba/latest/260x190/${p.nba_player_id}.png`
                : `https://ui-avatars.com/api/?name=${encodeURIComponent(p.name)}&background=121212&color=f17507&rounded=true&bold=true`);

        return `
            <div class="d-flex justify-content-between align-items-center p-2 bg-dark rounded mb-2">
                <div class="d-flex align-items-center gap-2">
                    <img src="${photoUrl}" alt="${p.name}" 
                         style="width: 45px; height: 45px; object-fit: cover; border-radius: 50%; border: 1px solid var(--fba-orange); background: #1a1a1a;"
                         onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(p.name)}&background=121212&color=f17507&rounded=true&bold=true'">
                    <div>
                        <strong class="text-white">${p.name}</strong>
                        <small class="d-block text-muted">
                            ${p.position} • OVR ${p.ovr} • ${p.age} anos • ${p.role}
                        </small>
                    </div>
                </div>
                <span class="badge badge-${tradeClass}">${tradeText}</span>
            </div>
        `;
    }).join('') || '<p class="text-muted">Sem jogadores cadastrados.</p>';

    const pickList = picks.map(pk => {
        const base = `${pk.season_year} R${pk.round}`;
        // Se a pick foi trocada (original_team_id != team_id), mostrar "via"
        const isTraded = pk.original_team_id && pk.team_id && pk.original_team_id != pk.team_id;
        if (isTraded && pk.last_owner_city && pk.last_owner_name) {
            return `${base} <small class="text-info">(via ${pk.last_owner_city})</small>`;
        } else if (isTraded && pk.original_city && pk.original_name) {
            return `${base} <small class="text-info">(via ${pk.original_city})</small>`;
        }
        return base;
    }).join(' • ') || 'Sem picks';

    const capClass = team.cap_top8 < 618 ? 'text-danger' : team.cap_top8 > 648 ? 'text-danger' : 'text-success';

    return `
        <div class="col-lg-6">
            <div class="card bg-dark-panel border-orange h-100">
                <div class="card-header bg-transparent border-orange">
                    <div class="d-flex align-items-center gap-3">
                        <img src="${team.user_photo || placeholderUser}" alt="GM" class="avatar rounded-circle">
                        <img src="${team.team_photo || placeholderTeam}" alt="Time" class="avatar-small rounded">
                        <div class="flex-grow-1">
                            <h5 class="mb-0 text-orange">${team.name}</h5>
                            <small class="text-muted">${team.city} • ${team.mascot}</small>
                            <small class="d-block text-muted">GM: ${team.user_name || 'N/D'}</small>
                        </div>
                        <div class="text-end">
                            <div class="badge bg-gradient-orange">CAP</div>
                            <h4 class="mb-0 ${capClass}">${team.cap_top8}</h4>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <small class="text-muted">
                            <i class="bi bi-trophy me-1"></i>Divisão: ${team.division_name || 'N/D'} • 
                            <i class="bi bi-calendar-check ms-2 me-1"></i>Picks: ${pickList}
                        </small>
                    </div>
                    <div class="roster-players">
                        ${playerList}
                    </div>
                </div>
            </div>
        </div>
    `;
};

const loadRosters = async () => {
    const grid = document.getElementById('roster-grid');
    const status = document.getElementById('roster-status');
    
    try {
        const result = await api('rosters.php');
        status.style.display = 'none';
        
        if (!result.teams || result.teams.length === 0) {
            grid.innerHTML = '<div class="col-12"><p class="text-center text-muted">Nenhum time encontrado.</p></div>';
            return;
        }

        grid.innerHTML = result.teams.map(renderRosterCard).join('');
    } catch (err) {
        status.innerHTML = `<div class="alert alert-danger">Erro ao carregar elencos</div>`;
    }
};

document.getElementById('btn-refresh-rosters').addEventListener('click', loadRosters);
loadRosters();
