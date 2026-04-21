<?php
require_once __DIR__ . '/backend/auth.php';
requireAuth(true); // Admin apenas

$user = getUserSession();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Importar Jogadores do Draft — Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        /* ── Tokens ─────────────��─────────────────────── */
        :root {
            --red:        #fc0025;
            --red-soft:   rgba(252,0,37,.10);
            --red-glow:   rgba(252,0,37,.18);
            --bg:         #07070a;
            --panel:      #101013;
            --panel-2:    #16161a;
            --panel-3:    #1c1c21;
            --border:     rgba(255,255,255,.06);
            --border-md:  rgba(255,255,255,.10);
            --border-red: rgba(252,0,37,.22);
            --text:       #f0f0f3;
            --text-2:     #868690;
            --text-3:     #48484f;
            --green:      #22c55e;
            --amber:      #f59e0b;
            --blue:       #3b82f6;
            --font:       'Poppins', sans-serif;
            --radius:     14px;
            --radius-sm:  10px;
            --ease:       cubic-bezier(.2,.8,.2,1);
            --t:          200ms;
        }

        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }

        /* ── Layout ────────────���──────────────────────── */
        .app-wrap { max-width: 960px; margin: 0 auto; padding: 24px 20px 56px; }

        /* ── Topbar ──────────────────────────────���────── */
        .app-topbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 14px; flex-wrap: wrap;
            padding: 14px 20px;
            background: var(--panel);
            border-bottom: 1px solid var(--border);
            margin-bottom: 28px;
        }
        .app-topbar-left { display: flex; align-items: center; gap: 12px; }
        .app-logo { width: 32px; height: 32px; border-radius: 8px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px; color: #fff; flex-shrink: 0; }
        .app-title { font-size: 15px; font-weight: 700; line-height: 1.1; }
        .app-title span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
        .back-link { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: var(--text-2); text-decoration: none; transition: color var(--t) var(--ease); }
        .back-link:hover { color: var(--red); }

        /* ── Page header ──────────────────────────────── */
        .page-head { margin-bottom: 24px; }
        .page-eyebrow { font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--red); margin-bottom: 5px; }
        .page-title { font-size: 1.5rem; font-weight: 800; margin-bottom: 4px; }
        .page-sub { font-size: 13px; color: var(--text-2); }

        /* ── Panel card ──────────────────���────────────── */
        .panel-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            height: 100%;
        }
        .panel-card-head {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
        }
        .panel-card-title { font-size: 14px; font-weight: 700; margin-bottom: 2px; }
        .panel-card-sub { font-size: 12px; color: var(--text-2); }
        .panel-card-body { padding: 20px; }

        /* ── Step badge ───────────────��───────────────── */
        .step-badge {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px; border-radius: 50%;
            background: var(--red-soft); border: 1px solid var(--border-red);
            font-size: 11px; font-weight: 700; color: var(--red);
            margin-right: 8px; flex-shrink: 0;
        }

        /* ── Form fields ──────────────────────────────── */
        .field-label { font-size: 12px; font-weight: 600; color: var(--text-2); margin-bottom: 5px; display: block; }
        .field-input {
            width: 100%;
            background: var(--panel-2); border: 1px solid var(--border-md);
            border-radius: 8px; padding: 9px 12px;
            color: var(--text); font-family: var(--font); font-size: 13px;
            outline: none; transition: border-color var(--t) var(--ease);
        }
        .field-input:focus { border-color: var(--red); }
        .field-input::placeholder { color: var(--text-3); }
        .field-input option { background: var(--panel-2); }
        .field-input:disabled { opacity: .5; cursor: not-allowed; }
        .field-hint { font-size: 11px; color: var(--text-3); margin-top: 5px; }

        /* ── Code block ─────────────────────��─────────── */
        .code-block {
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: 8px; padding: 12px 14px;
            font-family: 'Courier New', monospace; font-size: 12px;
            color: var(--green); white-space: pre; overflow-x: auto;
        }
        .code-block.example { color: var(--text-2); }

        /* ── Info box ─────────────────────────────────── */
        .info-box {
            background: rgba(59,130,246,.07); border: 1px solid rgba(59,130,246,.2);
            border-radius: 8px; padding: 12px 14px;
            font-size: 12px; color: var(--blue);
            display: flex; align-items: flex-start; gap: 8px;
        }
        .info-box i { margin-top: 1px; flex-shrink: 0; }

        /* ── Buttons ───────────────────────────��──────── */
        .btn-red {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
            width: 100%; padding: 10px 18px; border-radius: 9px;
            background: var(--red); border: none; color: #fff;
            font-family: var(--font); font-size: 13px; font-weight: 600;
            cursor: pointer; transition: filter var(--t) var(--ease);
        }
        .btn-red:hover { filter: brightness(1.12); }
        .btn-red:disabled { opacity: .5; cursor: not-allowed; }

        .btn-ghost {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
            width: 100%; padding: 10px 18px; border-radius: 9px;
            background: transparent; border: 1px solid var(--border-md); color: var(--text-2);
            font-family: var(--font); font-size: 13px; font-weight: 600;
            cursor: pointer; transition: all var(--t) var(--ease);
        }
        .btn-ghost:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }

        .btn-green {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
            width: 100%; padding: 10px 18px; border-radius: 9px;
            background: var(--green); border: none; color: #fff;
            font-family: var(--font); font-size: 13px; font-weight: 600;
            cursor: pointer; transition: filter var(--t) var(--ease);
        }
        .btn-green:hover { filter: brightness(1.1); }

        /* ── Alert ────────────────────────────────────── */
        .fb-alert {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 16px; border-radius: 9px; font-size: 13px; font-weight: 500;
            margin-bottom: 16px;
        }
        .fb-alert.success { background: rgba(34,197,94,.09); border: 1px solid rgba(34,197,94,.2); color: var(--green); }
        .fb-alert.danger  { background: rgba(239,68,68,.09); border: 1px solid rgba(239,68,68,.2); color: #ef4444; }

        /* ── Divider ────────────���─────────────────────── */
        hr { border-color: var(--border); margin: 20px 0; }

        /* ── Responsive ─────────────���─────────────────── */
        @media (max-width: 768px) {
            .app-wrap { padding: 16px 14px 40px; }
        }
    </style>
</head>
<body>

<!-- Topbar -->
<div class="app-topbar">
    <div class="app-topbar-left">
        <div class="app-logo">FBA</div>
        <div class="app-title">Importar Jogadores <span>Admin — Draft</span></div>
    </div>
    <a href="admin.php" class="back-link"><i class="bi bi-arrow-left"></i> Admin</a>
</div>

<div class="app-wrap">

    <div class="page-head">
        <div class="page-eyebrow">Admin · Draft</div>
        <h1 class="page-title">Importar Jogadores do Draft</h1>
        <p class="page-sub">Selecione a temporada e faça o upload do CSV com os jogadores.</p>
    </div>

    <!-- Feedback -->
    <div id="feedback"></div>

    <!-- Passo 1 + Formato -->
    <div class="row g-4 mb-4">
        <!-- Seleção de temporada -->
        <div class="col-md-6">
            <div class="panel-card">
                <div class="panel-card-head">
                    <div class="d-flex align-items-center gap-1">
                        <span class="step-badge">1</span>
                        <div>
                            <div class="panel-card-title">Selecione a Temporada</div>
                            <div class="panel-card-sub">Escolha liga e temporada de destino</div>
                        </div>
                    </div>
                </div>
                <div class="panel-card-body">
                    <div class="mb-3">
                        <label class="field-label">Liga</label>
                        <select class="field-input" id="leagueSelect" onchange="loadSeasons()">
                            <option value="">Selecione...</option>
                            <option value="ELITE">ELITE</option>
                            <option value="NEXT">NEXT</option>
                            <option value="RISE">RISE</option>
                            <option value="ROOKIE">ROOKIE</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="field-label">Temporada</label>
                        <select class="field-input" id="seasonSelect" disabled>
                            <option value="">Selecione uma liga primeiro...</option>
                        </select>
                        <div class="field-hint">A temporada de destino dos jogadores importados</div>
                    </div>
                    <button class="btn-red" onclick="confirmSeason()">
                        <i class="bi bi-check2-circle"></i> Confirmar Temporada
                    </button>
                </div>
            </div>
        </div>

        <!-- Formato CSV -->
        <div class="col-md-6">
            <div class="panel-card">
                <div class="panel-card-head">
                    <div class="panel-card-title">Formato do CSV</div>
                    <div class="panel-card-sub">Estrutura esperada no arquivo</div>
                </div>
                <div class="panel-card-body">
                    <div class="field-label" style="margin-bottom:8px">Cabeçalho obrigatório</div>
                    <div class="code-block mb-3">nome,posicao,idade,ovr</div>

                    <div class="field-label" style="margin-bottom:8px">Exemplo de dados</div>
                    <div class="code-block example mb-4">LeBron James,SF,39,96
Stephen Curry,PG,35,95
Kevin Durant,PF,35,94</div>

                    <button class="btn-ghost" onclick="downloadTemplate()">
                        <i class="bi bi-download"></i> Baixar Template CSV
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Passo 2: Upload -->
    <div id="uploadSection" style="display:none">
        <div class="panel-card">
            <div class="panel-card-head">
                <div class="d-flex align-items-center gap-1">
                    <span class="step-badge">2</span>
                    <div>
                        <div class="panel-card-title">Importar Arquivo CSV</div>
                        <div class="panel-card-sub" id="selectedSeasonInfo" style="color:var(--amber)"></div>
                    </div>
                </div>
            </div>
            <div class="panel-card-body">
                <div class="info-box mb-4">
                    <i class="bi bi-info-circle"></i>
                    <span>O arquivo será processado linha a linha. Linhas com erro serão ignoradas e o resultado será exibido abaixo.</span>
                </div>
                <div class="mb-4">
                    <label class="field-label">Arquivo CSV</label>
                    <input type="file" id="csvFile" accept=".csv" class="field-input" style="padding:7px 10px;cursor:pointer">
                    <div class="field-hint">Apenas arquivos .csv são aceitos</div>
                </div>
                <button class="btn-green" onclick="importPlayers()">
                    <i class="bi bi-upload"></i> Importar Jogadores
                </button>
            </div>
        </div>
    </div>

</div><!-- .app-wrap -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let currentSeasonId = null;
    let currentSeasonInfo = null;

    function showFeedback(type, message) {
        const fb = document.getElementById('feedback');
        fb.innerHTML = `
            <div class="fb-alert ${type}">
                <i class="bi bi-${type === 'success' ? 'check-circle' : 'x-circle'}" style="margin-top:1px;flex-shrink:0"></i>
                <span>${message}</span>
            </div>`;
        if (type === 'success') {
            setTimeout(() => { fb.innerHTML = ''; }, 6000);
        }
        fb.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    async function api(endpoint, options = {}) {
        const response = await fetch(`/api/${endpoint}`, {
            ...options,
            headers: { ...options.headers }
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
                option.textContent = `Temporada ${season.season_number} · Sprint ${season.sprint_number} (${season.year})`;
                option.dataset.seasonInfo = JSON.stringify(season);
                seasonSelect.appendChild(option);
            });

            seasonSelect.disabled = false;
        } catch (e) {
            seasonSelect.innerHTML = '<option value="">Erro ao carregar temporadas</option>';
            showFeedback('danger', 'Erro ao carregar temporadas: ' + (e.error || e.message || 'Desconhecido'));
        }
    }

    function confirmSeason() {
        const seasonSelect = document.getElementById('seasonSelect');
        const seasonId = seasonSelect.value;

        if (!seasonId) {
            showFeedback('danger', 'Selecione uma temporada antes de continuar.');
            return;
        }

        const selectedOption = seasonSelect.options[seasonSelect.selectedIndex];
        currentSeasonId = parseInt(seasonId);
        currentSeasonInfo = JSON.parse(selectedOption.dataset.seasonInfo);

        document.getElementById('selectedSeasonInfo').textContent =
            `${currentSeasonInfo.league} · Temporada ${currentSeasonInfo.season_number} · ${currentSeasonInfo.year}`;
        document.getElementById('uploadSection').style.display = 'block';
        document.getElementById('feedback').innerHTML = '';
        document.getElementById('uploadSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    async function importPlayers() {
        const fileInput = document.getElementById('csvFile');
        const file = fileInput.files[0];

        if (!file) {
            showFeedback('danger', 'Selecione um arquivo CSV.');
            return;
        }
        if (!currentSeasonId) {
            showFeedback('danger', 'Selecione uma temporada primeiro.');
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
                showFeedback('success', data.message);
                fileInput.value = '';
            } else {
                let errorMsg = data.error || 'Erro desconhecido';
                if (data.file && data.line) errorMsg += ` (${data.file}:${data.line})`;
                throw new Error(errorMsg);
            }
        } catch (e) {
            showFeedback('danger', 'Erro: ' + (e.message || e.error || 'Desconhecido'));
        }
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
</body>
</html>
