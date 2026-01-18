/**
 * History.js - Visualização do Histórico de Temporadas
 * Mostra: Campeão, Vice, MVP, DPOY, MIP, 6º Homem
 */

document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('historyContainer');
    const league = container?.dataset?.league || 'ELITE';
    
    loadHistory(league);
});

async function loadHistory(league) {
    const container = document.getElementById('historyContainer');
    
    try {
        container.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-orange"></div>
                <p class="text-muted mt-2">Carregando histórico...</p>
            </div>
        `;
        
        const response = await fetch(`/api/history-points.php?action=get_history&league=${encodeURIComponent(league)}`);
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Erro ao carregar histórico');
        }
        
        const history = data.history[league] || [];
        
        if (history.length === 0) {
            const leagueLabel = league.toUpperCase();
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="bi bi-clock-history display-1 text-white-50"></i>
                    <h4 class="text-white mt-3">Nenhum histórico registrado</h4>
                    <p class="text-white mt-2">O histórico de temporadas da liga ${leagueLabel} aparecerá aqui após ser registrado.</p>
                </div>
            `;
            return;
        }
        
        // Renderizar histórico
        let html = '<div class="row g-4">';
        
        history.forEach(season => {
            html += `
                <div class="col-12">
                    <div class="card bg-dark border-orange" style="border-radius: 15px;">
                        <div class="card-header bg-transparent border-orange">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 text-white">
                                    <i class="bi bi-trophy-fill text-orange me-2"></i>
                                    Sprint ${season.sprint_number} - Temporada ${season.season_number}
                                </h5>
                                <span class="badge bg-orange">${season.year}</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <!-- Campeão -->
                                ${season.champion_name ? `
                                <div class="col-md-6 col-lg-4">
                                    <div class="d-flex align-items-center p-3 bg-dark-secondary rounded border border-orange">
                                        <i class="bi bi-trophy-fill text-warning fs-3 me-3"></i>
                                        <div>
                                            <small class="text-orange">Campeão</small>
                                            <div class="text-white fw-bold">${season.champion_name}</div>
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                                
                                <!-- Vice-Campeão -->
                                ${season.runner_up_name ? `
                                <div class="col-md-6 col-lg-4">
                                    <div class="d-flex align-items-center p-3 bg-dark-secondary rounded border border-secondary">
                                        <i class="bi bi-award-fill text-secondary fs-3 me-3"></i>
                                        <div>
                                            <small class="text-light-gray">Vice-Campeão</small>
                                            <div class="text-white fw-bold">${season.runner_up_name}</div>
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                                
                                <!-- MVP -->
                                ${season.mvp_player ? `
                                <div class="col-md-6 col-lg-4">
                                    <div class="d-flex align-items-center p-3 bg-dark-secondary rounded border border-warning">
                                        <i class="bi bi-star-fill text-warning fs-3 me-3"></i>
                                        <div>
                                            <small class="text-warning">MVP</small>
                                            <div class="text-white fw-bold">${season.mvp_player}</div>
                                            ${season.mvp_team_name ? `<small class="text-light-gray">${season.mvp_team_name}</small>` : ''}
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                                
                                <!-- DPOY -->
                                ${season.dpoy_player ? `
                                <div class="col-md-6 col-lg-4">
                                    <div class="d-flex align-items-center p-3 bg-dark-secondary rounded border border-info">
                                        <i class="bi bi-shield-fill text-info fs-3 me-3"></i>
                                        <div>
                                            <small class="text-info">DPOY</small>
                                            <div class="text-white fw-bold">${season.dpoy_player}</div>
                                            ${season.dpoy_team_name ? `<small class="text-light-gray">${season.dpoy_team_name}</small>` : ''}
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                                
                                <!-- MIP -->
                                ${season.mip_player ? `
                                <div class="col-md-6 col-lg-4">
                                    <div class="d-flex align-items-center p-3 bg-dark-secondary rounded border border-success">
                                        <i class="bi bi-graph-up-arrow text-success fs-3 me-3"></i>
                                        <div>
                                            <small class="text-success">MIP</small>
                                            <div class="text-white fw-bold">${season.mip_player}</div>
                                            ${season.mip_team_name ? `<small class="text-light-gray">${season.mip_team_name}</small>` : ''}
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                                
                                <!-- 6º Homem -->
                                ${season.sixth_man_player ? `
                                <div class="col-md-6 col-lg-4">
                                    <div class="d-flex align-items-center p-3 bg-dark-secondary rounded border border-primary">
                                        <i class="bi bi-person-fill-add text-primary fs-3 me-3"></i>
                                        <div>
                                            <small class="text-primary">6º Homem</small>
                                            <div class="text-white fw-bold">${season.sixth_man_player}</div>
                                            ${season.sixth_man_team_name ? `<small class="text-light-gray">${season.sixth_man_team_name}</small>` : ''}
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                                
                                <!-- ROY -->
                                ${season.roy_player ? `
                                <div class="col-md-6 col-lg-4">
                                    <div class="d-flex align-items-center p-3 bg-dark-secondary rounded border border-danger">
                                        <i class="bi bi-person-up text-danger fs-3 me-3"></i>
                                        <div>
                                            <small class="text-danger">ROY</small>
                                            <div class="text-white fw-bold">${season.roy_player}</div>
                                            ${season.roy_team_name ? `<small class="text-light-gray">${season.roy_team_name}</small>` : ''}
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                            </div>
                            
                            <!-- Botão Ver Draft -->
                            ${season.has_draft_history ? `
                            <div class="mt-3 text-end">
                                <button class="btn btn-sm btn-outline-orange" onclick="viewDraftHistory(${season.season_id})">
                                    <i class="bi bi-list-ol me-1"></i>Ver Draft
                                </button>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;
        });
        
        html += '</div>';
        container.innerHTML = html;
        
    } catch (error) {
        console.error('Erro ao carregar histórico:', error);
        container.innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                Erro ao carregar histórico: ${error.message}
            </div>
        `;
    }
}

// Visualizar histórico do draft de uma temporada
async function viewDraftHistory(seasonId) {
    try {
        const response = await fetch(`/api/draft.php?action=draft_history&season_id=${seasonId}`);
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Erro ao carregar histórico do draft');
        }
        
        const order = data.draft_order || [];
        
        if (order.length === 0) {
            alert('Nenhum registro de draft encontrado para esta temporada.');
            return;
        }
        
        // Criar modal com o histórico
        let modalHtml = `
            <div class="modal fade" id="draftHistoryModal" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content bg-dark border-orange">
                        <div class="modal-header border-orange">
                            <h5 class="modal-title text-white">
                                <i class="bi bi-list-ol me-2 text-orange"></i>
                                Histórico do Draft - Temporada ${data.season?.season_number || ''}
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="table-responsive">
                                <table class="table table-dark table-sm">
                                    <thead>
                                        <tr>
                                            <th>Pick</th>
                                            <th>Jogador</th>
                                            <th>Pos</th>
                                            <th>OVR</th>
                                            <th>Time</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${order.map((p, idx) => `
                                            <tr>
                                                <td>
                                                    <span class="badge ${p.round === 1 ? 'bg-orange' : 'bg-secondary'}">
                                                        R${p.round} #${p.pick_position}
                                                    </span>
                                                </td>
                                                <td class="text-white fw-bold">${p.player_name || '-'}</td>
                                                <td><span class="badge bg-orange">${p.player_position || '-'}</span></td>
                                                <td>${p.player_ovr || '-'}</td>
                                                <td class="text-light-gray">
                                                    ${p.team_city} ${p.team_name}
                                                    ${p.traded_from_city ? `<small class="text-muted">(via ${p.traded_from_city})</small>` : ''}
                                                </td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="modal-footer border-orange">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Remover modal existente se houver
        const existing = document.getElementById('draftHistoryModal');
        if (existing) existing.remove();
        
        // Adicionar modal ao body
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Mostrar modal
        const modal = new bootstrap.Modal(document.getElementById('draftHistoryModal'));
        modal.show();
        
    } catch (error) {
        console.error('Erro:', error);
        alert(error.message || 'Erro ao carregar histórico do draft');
    }
}
