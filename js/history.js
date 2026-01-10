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
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="bi bi-clock-history display-1 text-muted"></i>
                    <h4 class="text-white mt-3">Nenhum histórico registrado</h4>
                    <p class="text-muted">O histórico de temporadas da liga ${league} aparecerá aqui após ser registrado.</p>
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
                            </div>
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
