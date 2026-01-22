<?php
require_once __DIR__ . '/backend/auth.php';
requireAuth(true); // Admin apenas

$user = getUserSession();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar Jogadores do Draft</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body class="bg-dark-main">
    <button class="sidebar-toggle" id="sidebarToggle">
        <i class="bi bi-list fs-4"></i>
    </button>
    
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid py-4">
            <div class="row mb-4">
                <div class="col-12">
                    <h2 class="text-white">
                        <i class="bi bi-file-earmark-arrow-up text-orange me-2"></i>
                        Importar Jogadores do Draft
                    </h2>
                </div>
            </div>

            <!-- Seletor de Draft -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card bg-dark-panel border-orange">
                        <div class="card-body">
                            <h5 class="text-white mb-3">1. Selecione a Temporada</h5>
                            <div class="mb-3">
                                <label class="form-label text-white">Liga</label>
                                <select class="form-select bg-dark text-white border-orange" id="leagueSelect" onchange="loadSeasons()">
                                    <option value="">Selecione...</option>
                                    <option value="ELITE">ELITE</option>
                                    <option value="NEXT">NEXT</option>
                                    <option value="RISE">RISE</option>
                                    <option value="ROOKIE">ROOKIE</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-white">Temporada</label>
                                <select class="form-select bg-dark text-white border-orange" id="seasonSelect" disabled>
                                    <option value="">Selecione uma liga primeiro...</option>
                                </select>
                                <small class="text-light-gray">Selecione a temporada para importar os jogadores</small>
                            </div>
                            <button class="btn btn-orange w-100" onclick="confirmSeason()">
                                <i class="bi bi-check-circle me-2"></i>Confirmar Temporada
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Template CSV -->
                <div class="col-md-6">
                    <div class="card bg-dark-panel border-orange">
                        <div class="card-body">
                            <h5 class="text-white mb-3">
                                <i class="bi bi-info-circle text-orange me-2"></i>
                                Formato do CSV
                            </h5>
                            <p class="text-light-gray mb-3">
                                O arquivo CSV deve ter as seguintes colunas (primeira linha):
                            </p>
                            <div class="bg-dark rounded p-3 mb-3">
                                <code class="text-success">nome,posicao,idade,ovr</code>
                            </div>
                            <p class="text-light-gray mb-3">Exemplo:</p>
                            <div class="bg-dark rounded p-3 mb-3">
                                <code class="text-white" style="display: block; white-space: pre;">nome,posicao,idade,ovr
LeBron James,SF,39,96
Stephen Curry,PG,35,95
Kevin Durant,PF,35,94</code>
                            </div>
                            <button class="btn btn-outline-orange w-100" onclick="downloadTemplate()">
                                <i class="bi bi-download me-2"></i>Baixar Template CSV
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Upload -->
            <div class="row" id="uploadSection" style="display: none;">
                <div class="col-12">
                    <div class="card bg-dark-panel border-orange">
                        <div class="card-body">
                            <h5 class="text-white mb-3">2. Importar Arquivo CSV</h5>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                Temporada selecionada: <strong id="selectedSeasonInfo"></strong>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label text-white">Selecione o arquivo CSV</label>
                                <input type="file" class="form-control bg-dark text-white border-orange" 
                                       id="csvFile" accept=".csv">
                                <small class="text-light-gray">
                                    Apenas arquivos .csv são aceitos
                                </small>
                            </div>

                            <button class="btn btn-success btn-lg w-100" onclick="importPlayers()">
                                <i class="bi bi-upload me-2"></i>Importar Jogadores
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Resultado -->
            <div class="row mt-4" id="resultSection" style="display: none;">
                <div class="col-12">
                    <div class="alert" id="resultAlert"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentSeasonId = null;
        let currentSeasonInfo = null;

        async function api(endpoint, options = {}) {
            const response = await fetch(`/api/${endpoint}`, {
                ...options,
                headers: {
                    ...options.headers,
                }
            });
            const data = await response.json();
            if (!response.ok) throw data;
            return data;
        }

        async function loadSeasons() {
            const league = document.getElementById('leagueSelect').value;
            const seasonSelect = document.getElementById('seasonSelect');
            
            if (!league) {
                seasonSelect.disabled = true;
                seasonSelect.innerHTML = '<option value="">Selecione uma liga primeiro...</option>';
                return;
            }

            try {
                seasonSelect.disabled = true;
                seasonSelect.innerHTML = '<option value="">Carregando...</option>';
                
                const data = await api(`seasons.php?action=list&league=${league}`);
                const seasons = data.seasons || [];
                
                if (seasons.length === 0) {
                    seasonSelect.innerHTML = '<option value="">Nenhuma temporada encontrada</option>';
                    return;
                }
                
                seasonSelect.innerHTML = '<option value="">Selecione uma temporada...</option>';
                seasons.forEach(season => {
                    const option = document.createElement('option');
                    option.value = season.id;
                    option.textContent = `Temporada ${season.season_number} - Sprint ${season.sprint_number} (${season.year})`;
                    option.dataset.seasonInfo = JSON.stringify(season);
                    seasonSelect.appendChild(option);
                });
                
                seasonSelect.disabled = false;
            } catch (e) {
                console.error('Erro ao carregar temporadas:', e);
                seasonSelect.innerHTML = '<option value="">Erro ao carregar temporadas</option>';
                alert('Erro ao carregar temporadas: ' + (e.error || e.message || 'Desconhecido'));
            }
        }

        async function confirmSeason() {
            const seasonSelect = document.getElementById('seasonSelect');
            const seasonId = seasonSelect.value;

            if (!seasonId) {
                alert('Selecione uma temporada');
                return;
            }

            const selectedOption = seasonSelect.options[seasonSelect.selectedIndex];
            currentSeasonId = parseInt(seasonId);
            currentSeasonInfo = JSON.parse(selectedOption.dataset.seasonInfo);
            
            document.getElementById('selectedSeasonInfo').textContent = 
                `${currentSeasonInfo.league} - Temporada ${currentSeasonInfo.season_number} - ${currentSeasonInfo.year}`;
            document.getElementById('uploadSection').style.display = 'block';
            document.getElementById('resultSection').style.display = 'none';
        }

        async function importPlayers() {
            const fileInput = document.getElementById('csvFile');
            const file = fileInput.files[0];

            if (!file) {
                alert('Selecione um arquivo CSV');
                return;
            }

            if (!currentSeasonId) {
                alert('Selecione uma temporada primeiro');
                return;
            }

            const formData = new FormData();
            formData.append('csv_file', file);
            formData.append('season_id', currentSeasonId);

            try {
                const response = await fetch('/api/import-draft-players.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (response.ok && data.success) {
                    showResult('success', data.message);
                    fileInput.value = '';
                } else {
                    // Monta mensagem de erro detalhada
                    let errorMsg = data.error || 'Erro desconhecido';
                    if (data.file && data.line) {
                        errorMsg += ` (${data.file}:${data.line})`;
                    }
                    throw new Error(errorMsg);
                }
            } catch (e) {
                console.error('Erro na importação:', e);
                showResult('danger', 'Erro: ' + (e.message || e.error || 'Desconhecido'));
            }
        }

        function showResult(type, message) {
            const resultSection = document.getElementById('resultSection');
            const resultAlert = document.getElementById('resultAlert');
            
            resultAlert.className = `alert alert-${type}`;
            resultAlert.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle' : 'x-circle'} me-2"></i>${message}`;
            resultSection.style.display = 'block';

            setTimeout(() => {
                resultSection.style.display = 'none';
            }, 5000);
        }

        function downloadTemplate() {
            const csv = 'nome,posicao,idade,ovr\nLeBron James,SF,39,96\nStephen Curry,PG,35,95\nKevin Durant,PF,35,94\n';
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'template-draft-players.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }
    </script>
    <script src="js/sidebar.js"></script>
</body>
</html>
