document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('historyContainer');
    const league = container?.dataset?.league || 'ELITE';
    loadHistory(league);
});

async function loadHistory(league) {
    const container = document.getElementById('historyContainer');

    try {
        container.innerHTML = Array.from({length: 3}, () => `
            <div class="sk-season-card">
              <div class="sk-season-head">
                <div class="sk" style="width:36px;height:36px;border-radius:9px"></div>
                <div class="sk" style="width:120px;height:16px"></div>
              </div>
              <div class="sk-season-body">
                <div class="sk sk-chip"></div><div class="sk sk-chip"></div>
                <div class="sk sk-chip"></div><div class="sk sk-chip"></div>
              </div>
            </div>`).join('');

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
            { key: 'champion',   nameKey: 'champion_name',     teamKey: null,                 icon: '🏆', cls: 'gold',   label: 'Campeão'      },
            { key: 'runner_up',  nameKey: 'runner_up_name',    teamKey: null,                 icon: '🥈', cls: 'silver', label: 'Vice-Campeão' },
            ...(league === 'ELITE' ? [
            { key: 'nba_cup',    nameKey: 'nba_cup_team_name', teamKey: null,                 icon: '🏆', cls: 'amber',  label: 'NBA Cup'      },
            ] : []),
            { key: 'mvp',        nameKey: 'mvp_player',        teamKey: 'mvp_team_name',      icon: '⭐', cls: 'amber',  label: 'MVP'          },
            { key: 'dpoy',       nameKey: 'dpoy_player',       teamKey: 'dpoy_team_name',     icon: '🛡️', cls: 'blue',   label: 'DPOY'         },
            { key: 'mip',        nameKey: 'mip_player',        teamKey: 'mip_team_name',      icon: '📈', cls: 'green',  label: 'MIP'          },
            { key: 'sixth_man',  nameKey: 'sixth_man_player',  teamKey: 'sixth_man_team_name',icon: '👤', cls: 'purple', label: '6º Homem'     },
            { key: 'roy',        nameKey: 'roy_player',        teamKey: 'roy_team_name',      icon: '🌟', cls: 'red',    label: 'ROY'          },
        ];

        let html = '';

        history.forEach(season => {
            const title = season.year
                ? String(season.year)
                : [
                    season.sprint_number ? `Sprint ${season.sprint_number}` : '',
                    season.season_number ? `Temp. ${season.season_number}`  : ''
                  ].filter(Boolean).join(' · ') || '—';

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

            const footButtons = [];
            if (season.has_draft_history) {
                footButtons.push(`<button class="btn-ghost-sm" onclick="viewDraftHistory(${season.season_id})"><i class="bi bi-list-ol"></i> Ver Draft</button>`);
            }
            if (season.season_id) {
                footButtons.push(`<button class="btn-ghost-sm" onclick="viewSeasonStandings(${season.season_id})"><i class="bi bi-list-ol"></i> Ver Classificação</button>`);
                footButtons.push(`<button class="btn-ghost-sm" onclick="viewSeasonTrades(${season.season_id})"><i class="bi bi-arrow-left-right"></i> Ver Trades</button>`);
            }
            const footHtml = footButtons.length ? `<div class="season-foot" style="gap:8px;flex-wrap:wrap">${footButtons.join('')}</div>` : '';

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
                    ${footHtml}
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

function _formatTradeItem(item) {
    if (item.pick_year) {
        return `Pick ${item.pick_round === '2' ? '2ª' : '1ª'} rodada ${item.pick_year}`;
    }
    return `${item.player_name || 'Jogador'}${item.player_position ? ` (${item.player_position})` : ''}${item.player_ovr ? ` · ${item.player_ovr} OVR` : ''}`;
}

async function viewSeasonTrades(seasonId) {
    try {
        const res  = await fetch(`/api/history-points.php?action=season_trades&season_id=${seasonId}`);
        const data = await res.json();

        if (!data.success) throw new Error(data.error || 'Erro ao carregar trocas da temporada');

        const trades = data.trades || [];
        if (trades.length === 0) {
            alert('Nenhuma troca aceita foi encontrada na janela desta temporada.');
            return;
        }

        const existing = document.getElementById('seasonTradesModal');
        if (existing) existing.remove();

        const rows = trades.map(t => `
            <div style="padding:12px 16px;border-bottom:1px solid var(--line, var(--border))">
                <div style="font-size:12px;font-weight:700;margin-bottom:6px">${t.from_city} ${t.from_name} <i class="bi bi-arrow-left-right" style="margin:0 6px;color:var(--red)"></i> ${t.to_city} ${t.to_name}</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:12px;color:var(--text-2)">
                    <div>${t.from_items.length ? t.from_items.map(_formatTradeItem).map(x => `<div>• ${x}</div>`).join('') : '<div>—</div>'}</div>
                    <div>${t.to_items.length ? t.to_items.map(_formatTradeItem).map(x => `<div>• ${x}</div>`).join('') : '<div>—</div>'}</div>
                </div>
            </div>`).join('');

        document.body.insertAdjacentHTML('beforeend', `
            <div class="modal fade" id="seasonTradesModal" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div class="modal-title"><i class="bi bi-arrow-left-right"></i> Maiores Trocas da Temporada</div>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" style="padding:0">${rows}</div>
                        <div class="modal-footer">
                            <button type="button" class="btn-ghost-sm" data-bs-dismiss="modal">Fechar</button>
                        </div>
                    </div>
                </div>
            </div>`);

        new bootstrap.Modal(document.getElementById('seasonTradesModal')).show();

    } catch (err) {
        console.error(err);
        alert(err.message || 'Erro ao carregar trocas da temporada');
    }
}

function _standingsRowHtml(s, idx) {
    const pos = parseInt(s.position, 10);
    const logo = s.photo_url
        ? `<img src="${s.photo_url}" alt="" style="width:26px;height:26px;border-radius:7px;object-fit:cover;flex-shrink:0" onerror="this.style.display='none'">`
        : `<div style="width:26px;height:26px;border-radius:7px;background:var(--panel-3);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:var(--text-3);flex-shrink:0">${(s.city||'?')[0]}</div>`;
    const isTop = pos <= 8;
    const posColor = pos === 1 ? 'var(--amber, #f59e0b)' : (isTop ? 'var(--red)' : 'var(--text-3)');
    const cut = pos === 8 ? `<div style="display:flex;align-items:center;gap:8px;margin:6px 0;padding:0 14px"><div style="flex:1;height:1px;background:var(--border-red)"></div><span style="font-size:9px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--red)">Playoffs</span><div style="flex:1;height:1px;background:var(--border)"></div></div>` : '';
    return `
        <div style="display:flex;align-items:center;gap:10px;padding:8px 14px;${isTop ? '' : 'opacity:.72'}">
            <span style="width:26px;text-align:center;font-weight:800;font-size:14px;color:${posColor};flex-shrink:0">${pos}º</span>
            ${logo}
            <span style="font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${s.city || ''} ${s.name || ''}</span>
        </div>${cut}`;
}

async function viewSeasonStandings(seasonId) {
    try {
        const res  = await fetch(`/api/history-points.php?action=season_standings&season_id=${seasonId}`);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Erro ao carregar classificação');

        const standings = data.standings || [];
        if (standings.length === 0) {
            alert('A classificação completa desta temporada ainda não foi registrada. A partir de agora, ao registrar a pontuação com todos os times, ela aparecerá aqui.');
            return;
        }

        let body;
        if (data.has_conference) {
            const leste = standings.filter(s => s.conference === 'LESTE');
            const oeste = standings.filter(s => s.conference === 'OESTE');
            const col = (title, list) => `
                <div style="flex:1;min-width:240px">
                    <div style="font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--red);padding:10px 14px 4px"><i class="bi bi-geo-alt me-1"></i>${title}</div>
                    ${list.map(_standingsRowHtml).join('') || '<div style="padding:10px 14px;color:var(--text-3);font-size:13px">—</div>'}
                </div>`;
            body = `<div style="display:flex;flex-wrap:wrap;gap:8px">${col('Conferência Leste', leste)}${col('Conferência Oeste', oeste)}</div>`;
        } else {
            body = `<div>${standings.map(_standingsRowHtml).join('')}</div>`;
        }

        const existing = document.getElementById('seasonStandingsModal');
        if (existing) existing.remove();

        document.body.insertAdjacentHTML('beforeend', `
            <div class="modal fade" id="seasonStandingsModal" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div class="modal-title"><i class="bi bi-list-ol"></i> Classificação Final da Temporada</div>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" style="padding:8px 0 4px">${body}</div>
                        <div class="modal-footer">
                            <button type="button" class="btn-ghost-sm" data-bs-dismiss="modal">Fechar</button>
                        </div>
                    </div>
                </div>
            </div>`);

        new bootstrap.Modal(document.getElementById('seasonStandingsModal')).show();

    } catch (err) {
        console.error(err);
        alert(err.message || 'Erro ao carregar classificação');
    }
}
