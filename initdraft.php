<?php
// Página única do Draft Inicial (acesso por token). Não aparece em menus.
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/auth.php';

$token = $_GET['token'] ?? null;
if (!$token) {
    http_response_code(400);
    echo 'Token obrigatório.';
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Draft Inicial</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background: #111; color: #fff; }
    .border-orange { border-color: #ff7a00 !important; }
    .text-orange { color: #ff7a00 !important; }
    .bg-orange { background: #ff7a00 !important; color: #111 !important; }
    .card { background: #1a1a1a; border-color: #333; color: #fff; }
    .table-dark { --bs-table-bg: #101010; }
    .table-dark thead th { color: #fff; }
    .form-label, .form-select, .form-control { color: #fff; }
    .form-select, .form-control { background-color: #0f0f0f; border-color: #444; }
    .order-list-item { background: transparent; border-color: #333; color: #fff; }
    .order-list-item .badge { width: 48px; }
    .order-actions button { min-width: 36px; }
    .lottery-row { border: 1px solid rgba(255,122,0,0.3); border-radius: 8px; padding: 0.75rem; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.75rem; }
    .lottery-row img { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,122,0,0.6); }
    .lottery-placeholder { border: 1px dashed rgba(255,122,0,0.5); border-radius: 8px; padding: 1.25rem; text-align: center; color: #bbb; }
  </style>
  <script>
    const TOKEN = new URLSearchParams(location.search).get('token');
    async function api(path, opts={}) {
      const res = await fetch(path, opts);
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Erro de API');
      return data;
    }

    let gState = { session: null, order: [], teams: [] };
    let manualOrderIds = [];
    let lotteryTimers = [];
    let activeOrderTab = 'manual';

    function escapeHtml(str = '') {
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    async function loadState() {
      const data = await api(`/api/initdraft.php?action=state&token=${TOKEN}`);
      gState = data;
      gState.teams = data.teams || [];
      render();
    }

    function render() {
      const s = gState.session;
      const order = gState.order || [];
      document.getElementById('sessionInfo').innerHTML = `
        <div class="d-flex gap-3 align-items-center">
          <span class="badge bg-orange">${s.league}</span>
          <span class="badge ${s.status==='completed'?'bg-success':'bg-secondary'}">${s.status}</span>
          <span>Rodada Atual: <strong>${s.current_round}</strong></span>
          <span>Pick Atual: <strong>${s.current_pick}</strong></span>
          <span>Total de Rodadas: <strong>${s.total_rounds}</strong></span>
          <span>Token: <code>${s.access_token}</code></span>
        </div>
      `;

      // Agrupar por rodada
      const rounds = {};
      for (const p of order) {
        if (!rounds[p.round]) rounds[p.round] = [];
        rounds[p.round].push(p);
      }

      // Tabs: Rodadas | Jogadores
      const container = document.getElementById('rounds');
      container.innerHTML = `
        <ul class="nav nav-tabs mb-3" id="initTabs" role="tablist">
          <li class="nav-item" role="presentation"><button class="nav-link active" id="tab-rounds" data-bs-toggle="tab" data-bs-target="#tabRounds" type="button" role="tab">Rodadas</button></li>
          <li class="nav-item" role="presentation"><button class="nav-link" id="tab-players" data-bs-toggle="tab" data-bs-target="#tabPlayers" type="button" role="tab">Jogadores</button></li>
        </ul>
        <div class="tab-content">
          <div class="tab-pane fade show active" id="tabRounds" role="tabpanel"><div id="roundsInner"></div></div>
          <div class="tab-pane fade" id="tabPlayers" role="tabpanel"><div id="playersList"></div></div>
        </div>
      `;
      const roundsContainer = document.getElementById('roundsInner');
      roundsContainer.innerHTML = '';
      Object.keys(rounds).sort((a,b)=>a-b).forEach(r => {
        const picks = rounds[r];
        const rows = picks.map(p => `
          <tr>
            <td><span class="badge ${p.round%2? 'bg-orange':'bg-secondary'}">R${p.round} #${p.pick_position}</span></td>
            <td>${p.team_city} ${p.team_name}</td>
            <td>${p.player_name ? `<strong>${p.player_name}</strong>` : '<em>-</em>'}</td>
            <td>${p.player_position || '-'}</td>
            <td>${p.player_ovr || '-'}</td>
          </tr>
        `).join('');
        const card = document.createElement('div');
        card.className = 'card mb-3';
        card.innerHTML = `
          <div class="card-header border-orange">
            <strong class="text-orange">Rodada ${r}</strong>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-dark table-sm align-middle">
                <thead>
                  <tr><th>Pick</th><th>Time</th><th>Jogador</th><th>Pos</th><th>OVR</th></tr>
                </thead>
                <tbody>${rows}</tbody>
              </table>
            </div>
          </div>
        `;
        roundsContainer.appendChild(card);
      });

      // Botões
  const actions = document.getElementById('actions');
  actions.innerHTML = '';
      if (s.status === 'setup') {
        actions.innerHTML = `
          <button class="btn btn-outline-light me-2" onclick="openImport()"><i class="bi bi-file-earmark-arrow-up"></i> Importar CSV</button>
          <button class="btn btn-outline-info me-2" onclick="openAddPlayer()"><i class="bi bi-person-plus"></i> Adicionar Jogador</button>
          <button class="btn btn-outline-warning me-2" onclick="openOrderModal()"><i class="bi bi-shuffle"></i> Definir / Sortear Ordem</button>
          <button class="btn btn-success" onclick="startDraft()"><i class="bi bi-play"></i> Iniciar Draft</button>
        `;
      } else if (s.status === 'in_progress') {
        actions.innerHTML = `
          <button class="btn btn-primary me-2" onclick="openPickModal()"><i class="bi bi-hand-index-thumb"></i> Fazer Pick</button>
          <button class="btn btn-danger" onclick="finalizeDraft()"><i class="bi bi-flag"></i> Finalizar Draft</button>
        `;
      } else if (s.status === 'completed') {
        actions.innerHTML = `<span class="badge bg-success">Draft Finalizado</span>`;
      }

      // Ensure pool is loaded for players tab
      loadPool();
    }

    function openImport() {
      const el = document.getElementById('importCSVModal');
      new bootstrap.Modal(el).show();
    }

    function openAddPlayer() {
      const el = document.getElementById('addPlayerModal');
      el.querySelector('form').reset();
      new bootstrap.Modal(el).show();
    }

    async function doAddPlayer(ev) {
      ev?.preventDefault();
      const form = document.getElementById('addPlayerForm');
      const payload = Object.fromEntries(new FormData(form).entries());
      payload.age = Number(payload.age||0);
      payload.ovr = Number(payload.ovr||0);
      try {
        await api('/api/initdraft.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'add_player', token: TOKEN, ...payload }) });
        bootstrap.Modal.getInstance(document.getElementById('addPlayerModal')).hide();
        await loadState();
        // refresh pool view as well
        await loadPool();
      } catch (e) { alert(e.message); }
    }

    async function loadPool() {
      try {
        const data = await api(`/api/initdraft.php?action=pool&token=${TOKEN}`);
        renderPlayers(data.players || []);
      } catch (e) {
        console.error('Erro ao carregar pool', e);
      }
    }

    function renderPlayers(players) {
      const el = document.getElementById('playersList');
      if (!el) return;
      if (players.length === 0) {
        el.innerHTML = '<div class="alert alert-secondary">Nenhum jogador no pool.</div>';
        return;
      }
      el.innerHTML = `
        <div class="table-responsive">
          <table class="table table-dark table-sm align-middle">
            <thead><tr><th>Nome</th><th>Pos</th><th>Idade</th><th>OVR</th><th>Status</th></tr></thead>
            <tbody>
              ${players.map(p => `
                <tr class="${p.draft_status === 'drafted' ? 'table-secondary' : ''}">
                  <td>${p.name}</td>
                  <td><span class="badge bg-orange">${p.position}</span></td>
                  <td>${p.age}</td>
                  <td>${p.ovr}</td>
                  <td>${p.draft_status === 'drafted' ? '<span class="badge bg-success">Draftado</span>' : '<span class="badge bg-secondary">Disponível</span>'}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      `;
    }

    async function submitImportCSV(ev) {
      ev?.preventDefault();
      const fileInput = document.getElementById('csvFileInput');
      const file = fileInput.files[0];
      if (!file) { alert('Selecione um arquivo CSV'); return; }
      const fd = new FormData();
      fd.append('action', 'import_csv');
      fd.append('token', TOKEN);
      fd.append('csv_file', file);
      try {
        const res = await fetch('/api/initdraft.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Falha ao importar');
        bootstrap.Modal.getInstance(document.getElementById('importCSVModal')).hide();
        await loadState();
      } catch (e) {
        alert('Erro ao importar: ' + e.message);
      }
    }

    function downloadCSVTemplate() {
      const rows = [
        ['name','position','age','ovr'],
        ['John Doe','SG',22,78],
        ['Jane Smith','PF',24,82]
      ];
      const csv = rows.map(r => r.join(',')).join('\n');
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'initdraft_template.csv';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }

    function getRoundOneOrderIds() {
      const roundOne = (gState.order || []).filter(p => p.round === 1).sort((a,b) => a.pick_position - b.pick_position);
      if (roundOne.length > 0) {
        return roundOne.map(p => parseInt(p.team_id, 10));
      }
      return (gState.teams || []).map(t => parseInt(t.id, 10));
    }

    function openOrderModal() {
      if (!gState.teams || gState.teams.length === 0) {
        alert('Nenhum time encontrado para esta liga.');
        return;
      }
      manualOrderIds = getRoundOneOrderIds();
      renderManualOrderList();
      resetLotteryView();
      const modal = new bootstrap.Modal(document.getElementById('orderModal'));
      const manualTabTrigger = document.querySelector('#orderTabs button[data-bs-target="#manualTab"]');
      if (manualTabTrigger) {
        new bootstrap.Tab(manualTabTrigger).show();
      }
      modal.show();
      setActiveOrderTab('manual');
    }

    function setActiveOrderTab(tab) {
      activeOrderTab = tab;
      const saveBtn = document.getElementById('manualOrderSave');
      const randomBtn = document.getElementById('lotteryActionBtn');
      if (tab === 'manual') {
        saveBtn?.classList.remove('d-none');
        randomBtn?.classList.add('d-none');
      } else {
        saveBtn?.classList.add('d-none');
        randomBtn?.classList.remove('d-none');
      }
    }

    function renderManualOrderList() {
      const list = document.getElementById('manualOrderList');
      if (!list) return;
      const teamsById = Object.fromEntries((gState.teams || []).map(t => [parseInt(t.id, 10), t]));
      list.innerHTML = manualOrderIds.map((teamId, idx) => {
        const t = teamsById[teamId];
        const label = t ? `${t.city} ${t.name}` : `Time #${teamId}`;
        const safeLabel = escapeHtml(label);
        const ownerInfo = t && t.owner_name ? `<div class="text-muted small">Manager: ${escapeHtml(t.owner_name)}</div>` : '';
        return `
          <li class="list-group-item order-list-item d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-3">
              <span class="badge bg-orange">#${idx + 1}</span>
              <div>
                <strong>${safeLabel}</strong>
                ${ownerInfo}
              </div>
            </div>
            <div class="order-actions btn-group">
              <button class="btn btn-sm btn-outline-light" ${idx === 0 ? 'disabled' : ''} onclick="moveManualTeam(${idx}, -1)"><i class="bi bi-arrow-up"></i></button>
              <button class="btn btn-sm btn-outline-light" ${idx === manualOrderIds.length - 1 ? 'disabled' : ''} onclick="moveManualTeam(${idx}, 1)"><i class="bi bi-arrow-down"></i></button>
            </div>
          </li>
        `;
      }).join('');
    }

    function moveManualTeam(index, direction) {
      const targetIndex = index + direction;
      if (targetIndex < 0 || targetIndex >= manualOrderIds.length) return;
      [manualOrderIds[index], manualOrderIds[targetIndex]] = [manualOrderIds[targetIndex], manualOrderIds[index]];
      renderManualOrderList();
    }

    async function submitManualOrder() {
      if (manualOrderIds.length === 0) {
        alert('Defina a ordem completa dos times.');
        return;
      }
      if (manualOrderIds.length !== (gState.teams || []).length) {
        alert('A ordem precisa incluir todos os times.');
        return;
      }
      try {
        await api('/api/initdraft.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'set_manual_order', token: TOKEN, team_ids: manualOrderIds })
        });
        bootstrap.Modal.getInstance(document.getElementById('orderModal'))?.hide();
        await loadState();
      } catch (e) {
        alert(e.message);
      }
    }

    function resetLotteryView() {
      clearLotteryAnimation();
      const stage = document.getElementById('lotteryStage');
      const container = document.getElementById('lotteryResults');
      if (stage) stage.innerHTML = '<div class="lottery-placeholder">Clique em "Sortear Ordem" para iniciar o sorteio animado.</div>';
      if (container) container.innerHTML = '';
    }

    function clearLotteryAnimation() {
      lotteryTimers.forEach(timer => clearTimeout(timer));
      lotteryTimers = [];
    }

    async function handleRandomizeOrder() {
      try {
        const stage = document.getElementById('lotteryStage');
        if (stage) stage.innerHTML = '<div class="text-center"><div class="spinner-border text-warning" role="status"></div><p class="mt-2 mb-0">Sorteando ordem...</p></div>';
        const data = await api('/api/initdraft.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'randomize_order', token: TOKEN }) });
        const details = data.order_details || [];
        playLotteryAnimation(details);
        // Recarrega estado após a animação para evitar travamentos na renderização
        const delay = Math.max(2000, details.length * 700);
        setTimeout(() => { loadState(); }, delay);
      } catch (e) {
        alert(e.message);
        resetLotteryView();
      }
    }

    function playLotteryAnimation(orderDetails) {
      resetLotteryView();
      const container = document.getElementById('lotteryResults');
      const stage = document.getElementById('lotteryStage');
      if (!orderDetails || orderDetails.length === 0) {
        if (stage) stage.innerHTML = '<div class="alert alert-warning">Não foi possível obter a ordem sorteada.</div>';
        return;
      }
      orderDetails.forEach((team, idx) => {
        const timer = setTimeout(() => {
          const teamLabel = `${team.city ?? 'Time'} ${team.name ?? ''}`.trim();
          if (stage) stage.innerHTML = `<div class="text-center"><small class="text-uppercase text-light">Pick #${idx + 1}</small><h5 class="mt-1 text-orange">${escapeHtml(teamLabel)}</h5></div>`;
          if (container) {
            const row = document.createElement('div');
            row.className = 'lottery-row';
            row.innerHTML = `
              <span class="badge bg-orange fs-6">#${idx + 1}</span>
              <img src="${team.photo_url || '/img/default-team.png'}" alt="${escapeHtml(teamLabel)}">
              <div>
                <div class="fw-bold">${escapeHtml(teamLabel)}</div>
              </div>
            `;
            container.appendChild(row);
          }
          if (idx === orderDetails.length - 1 && stage) {
            stage.innerHTML = '<div class="alert alert-success mb-0">Sorteio concluído! A ordem foi aplicada ao draft.</div>';
          }
        }, idx * 700);
        lotteryTimers.push(timer);
      });
    }
    async function startDraft() {
      try {
        await api('/api/initdraft.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'start', token: TOKEN }) });
        await loadState();
      } catch (e) { alert(e.message); }
    }

    async function openPickModal() {
      try {
        const data = await api(`/api/initdraft.php?action=available_players&token=${TOKEN}`);
        const list = data.players || [];
        const body = document.getElementById('pickList');
        body.innerHTML = list.map(p => `
          <tr>
            <td><strong>${p.name}</strong></td>
            <td><span class="badge bg-orange">${p.position}</span></td>
            <td>${p.ovr}</td>
            <td><button class="btn btn-sm btn-success" onclick="makePick(${p.id})"><i class="bi bi-check2"></i> Escolher</button></td>
          </tr>
        `).join('');
        new bootstrap.Modal(document.getElementById('pickModal')).show();
      } catch (e) { alert(e.message); }
    }

    async function makePick(playerId) {
      try {
        await api('/api/initdraft.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'make_pick', token: TOKEN, player_id: playerId }) });
        bootstrap.Modal.getInstance(document.getElementById('pickModal')).hide();
        await loadState();
      } catch (e) { alert(e.message); }
    }

    async function finalizeDraft() {
      if (!confirm('Finalizar o draft? Certifique-se de que todas as picks foram realizadas.')) return;
      try {
        await api('/api/initdraft.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'finalize', token: TOKEN }) });
        await loadState();
      } catch (e) { alert(e.message); }
    }

    window.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('#orderTabs button[data-bs-toggle="tab"]').forEach(btn => {
        btn.addEventListener('shown.bs.tab', (event) => {
          const target = event.target.getAttribute('data-bs-target');
          setActiveOrderTab(target === '#manualTab' ? 'manual' : 'random');
        });
      });
      const orderModalEl = document.getElementById('orderModal');
      if (orderModalEl) {
        orderModalEl.addEventListener('hidden.bs.modal', resetLotteryView);
      }
      loadState();
    });
  </script>
</head>
<body>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h3 text-orange mb-0"><i class="bi bi-trophy-fill me-2"></i>Draft Inicial</h1>
      <div id="actions"></div>
    </div>

    <div class="card mb-3">
      <div class="card-body" id="sessionInfo"></div>
    </div>

    <div id="rounds"></div>
  </div>

  <!-- Import CSV Modal -->
  <div class="modal fade" id="importCSVModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-orange">Importar Jogadores via CSV</h5>
          <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form onsubmit="submitImportCSV(event)">
          <div class="modal-body">
            <p class="text-white mb-2">Formato esperado de colunas: <code>name,position,age,ovr</code></p>
            <div class="mb-3">
              <label class="form-label text-white">Selecione o arquivo CSV</label>
              <input type="file" class="form-control" id="csvFileInput" accept=".csv" required />
            </div>
            <div class="d-flex justify-content-end">
              <button type="button" class="btn btn-outline-warning" onclick="downloadCSVTemplate()">
                <i class="bi bi-download me-2"></i>Baixar Template CSV
              </button>
            </div>
          </div>
          <div class="modal-footer border-orange">
            <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
            <button class="btn btn-primary" type="submit">Importar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Pick Modal -->
  <div class="modal fade" id="pickModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-orange">Escolher Jogador</h5>
          <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="table-responsive">
            <table class="table table-dark table-sm align-middle">
              <thead><tr><th>Jogador</th><th>Pos</th><th>OVR</th><th></th></tr></thead>
              <tbody id="pickList"></tbody>
            </table>
          </div>
        </div>
        <div class="modal-footer border-orange">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Add Player Modal -->
  <div class="modal fade" id="addPlayerModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-orange">Adicionar Jogador</h5>
          <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form id="addPlayerForm" onsubmit="doAddPlayer(event)">
          <div class="modal-body">
            <div class="row g-2">
              <div class="col-8"><input name="name" class="form-control text-white" placeholder="Nome" required></div>
              <div class="col-4">
                <select name="position" class="form-select text-white" required>
                  <option value="PG">PG</option>
                  <option value="SG">SG</option>
                  <option value="SF" selected>SF</option>
                  <option value="PF">PF</option>
                  <option value="C">C</option>
                </select>
              </div>
              <div class="col-4"><input type="number" name="age" min="16" max="45" class="form-control text-white" placeholder="Idade" required></div>
              <div class="col-4"><input type="number" name="ovr" min="1" max="99" class="form-control text-white" placeholder="OVR" required></div>
              <!-- Campos reduzidos conforme solicitado -->
            </div>
          </div>
          <div class="modal-footer border-orange">
            <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancelar</button>
            <button class="btn btn-primary" type="submit">Adicionar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Order Modal -->
  <div class="modal fade" id="orderModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-orange">Configurar Ordem do Draft</h5>
          <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <ul class="nav nav-pills mb-3" id="orderTabs" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#manualTab" type="button" role="tab">Ordem Manual</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#randomTab" type="button" role="tab">Sorteio Animado</button>
            </li>
          </ul>
          <div class="tab-content">
            <div class="tab-pane fade show active" id="manualTab" role="tabpanel">
              <div class="alert alert-secondary">Utilize os botões de seta para definir a ordem da primeira rodada. As demais rodadas seguirão o formato snake automaticamente.</div>
              <ul class="list-group" id="manualOrderList"></ul>
            </div>
            <div class="tab-pane fade" id="randomTab" role="tabpanel">
              <div id="lotteryStage" class="mb-3"></div>
              <div id="lotteryResults"></div>
            </div>
          </div>
        </div>
        <div class="modal-footer border-orange">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Fechar</button>
          <button class="btn btn-outline-warning d-none" id="lotteryActionBtn" type="button" onclick="handleRandomizeOrder()">
            <i class="bi bi-shuffle me-2"></i>Sortear Ordem
          </button>
          <button class="btn btn-success" id="manualOrderSave" type="button" onclick="submitManualOrder()">
            <i class="bi bi-check2-circle me-2"></i>Aplicar Ordem Manual
          </button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
