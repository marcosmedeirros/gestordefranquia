document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('historyContainer');
    const league = container?.dataset?.league || 'ELITE';
    loadHistory(league);
});

async function loadHistory(league) {
    const container = document.getElementById('historyContainer');

    try {
        container.innerHTML = `
            <div class="state-empty">
                <div class="spinner" style="margin-bottom:16px"></div>
                <p>Carregando histórico…</p>
            </div>`;

        const res  = await fetch(`/api/history-points.php?action=get_history&league=${encodeURIComponent(league)}`);
        const data = await res.json();

        if (!data.success) throw new Error(data.error || 'Erro ao carregar histórico');

        const history = data.history[league] || [];

        if (history.length === 0) {
            container.innerHTML = `
                <div class="state-empty">
                    <i class="bi bi-clock-history"></i>
                    <p>O histórico de temporadas da liga ${league.toUpperCase()} aparecerá aqui após ser registrado.</p>
                </div>`;
            return;
        }

        const awards = [
            { key: 'champion',   nameKey: 'champion_name',     teamKey: null,                 icon: '🏆', cls: 'gold',   label: 'Campeão'     },
            { key: 'runner_up',  nameKey: 'runner_up_name',    teamKey: null,                 icon: '🥈', cls: 'silver', label: 'Vice-Campeão' },
            { key: 'mvp',        nameKey: 'mvp_player',        teamKey: 'mvp_team_name',      icon: '⭐', cls: 'amber',  label: 'MVP'          },
            { key: 'dpoy',       nameKey: 'dpoy_player',       teamKey: 'dpoy_team_name',     icon: '🛡️', cls: 'blue',   label: 'DPOY'         },
            { key: 'mip',        nameKey: 'mip_player',        teamKey: 'mip_team_name',      icon: '📈', cls: 'green',  label: 'MIP'          },
            { key: 'sixth_man',  nameKey: 'sixth_man_player',  teamKey: 'sixth_man_team_name',icon: '👤', cls: 'purple', label: '6º Homem'     },
            { key: 'roy',        nameKey: 'roy_player',        teamKey: 'roy_team_name',      icon: '🌟', cls: 'red',    label: 'ROY'          },
        ];

        let html = '';

        history.forEach(season => {
            const yearLabel = season.year || '';
            const sprintLabel = season.sprint_number ? `Sprint ${season.sprint_number}` : '';
            const titleParts = [sprintLabel, yearLabel].filter(Boolean);
            const title = titleParts.join(' · ') || 'Temporada';

            const chips = awards
                .filter(a => season[a.nameKey])
                .map(a => `
                    <div class="award-chip">
                        <div class="award-icon ${a.cls}">${a.icon}</div>
                        <div>
                            <div class="award-label ${a.cls}">${a.label}</div>
                            <div class="award-name">${season[a.nameKey]}</div>
                            ${a.teamKey && season[a.teamKey] ? `<div class="award-team">${season[a.teamKey]}</div>` : ''}
                        </div>
                    </div>`)
                .join('');

            const draftBtn = season.has_draft_history ? `
                <div class="season-foot">
                    <button class="btn-ghost-sm" onclick="viewDraftHistory(${season.season_id})">
                        <i class="bi bi-list-ol"></i> Ver Draft
                    </button>
                </div>` : '';

            html += `
                <div class="season-card">
                    <div class="season-head">
                        <div class="season-head-left">
                            <div class="season-icon">🏆</div>
                            <div>
                                <div class="season-title">${title}</div>
                            </div>
                        </div>
                    </div>
                    <div class="season-body">
                        ${chips ? `<div class="awards-grid">${chips}</div>` : '<p style="color:var(--text-3);font-size:13px">Sem premiações registradas.</p>'}
                    </div>
                    ${draftBtn}
                </div>`;
        });

        container.innerHTML = html;

    } catch (err) {
        console.error(err);
        container.innerHTML = `
            <div class="state-empty">
                <i class="bi bi-exclamation-triangle"></i>
                <p>Erro ao carregar histórico: ${err.message}</p>
            </div>`;
    }
}

async function viewDraftHistory(seasonId) {
    try {
        const res  = await fetch(`/api/draft.php?action=draft_history&season_id=${seasonId}`);
        const data = await res.json();

        if (!data.success) throw new Error(data.error || 'Erro ao carregar histórico do draft');

        const order = data.draft_order || [];
        if (order.length === 0) {
            alert('Nenhum registro de draft encontrado para esta temporada.');
            return;
        }

        const existing = document.getElementById('draftHistoryModal');
        if (existing) existing.remove();

        const rows = order.map(p => `
            <tr>
                <td>
                    <span class="pick-pill ${p.round === 1 ? 'r1' : 'r2'}">R${p.round} #${p.pick_position}</span>
                </td>
                <td class="td-player">${p.player_name || '-'}</td>
                <td><span class="pos-pill">${p.player_position || '-'}</span></td>
                <td>${p.player_ovr || '-'}</td>
                <td>${p.team_city || ''} ${p.team_name || ''}${p.traded_from_city ? ` <span style="color:var(--text-3);font-size:11px">(via ${p.traded_from_city})</span>` : ''}</td>
            </tr>`).join('');

        document.body.insertAdjacentHTML('beforeend', `
            <div class="modal fade" id="draftHistoryModal" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div class="modal-title"><i class="bi bi-list-ol"></i> Histórico do Draft</div>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" style="padding:0">
                            <div style="overflow-x:auto">
                                <table class="draft-table">
                                    <thead>
                                        <tr>
                                            <th>Pick</th>
                                            <th>Jogador</th>
                                            <th>Pos</th>
                                            <th>OVR</th>
                                            <th>Time</th>
                                        </tr>
                                    </thead>
                                    <tbody>${rows}</tbody>
                                </table>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn-ghost-sm" data-bs-dismiss="modal">Fechar</button>
                        </div>
                    </div>
                </div>
            </div>`);

        new bootstrap.Modal(document.getElementById('draftHistoryModal')).show();

    } catch (err) {
        console.error(err);
        alert(err.message || 'Erro ao carregar histórico do draft');
    }
}
