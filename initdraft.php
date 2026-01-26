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
    body { background: #111; color: #eee; }
    .border-orange { border-color: #ff7a00 !important; }
    .text-orange { color: #ff7a00 !important; }
    .bg-orange { background: #ff7a00 !important; color: #111 !important; }
    .card { background: #1a1a1a; border-color: #333; }
    .table-dark { --bs-table-bg: #101010; }
  </style>
  <script>
    const TOKEN = new URLSearchParams(location.search).get('token');
    async function api(path, opts={}) {
      const res = await fetch(path, opts);
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Erro de API');
      return data;
    }

    let gState = { session: null, order: [] };

    async function loadState() {
      const data = await api(`/api/initdraft.php?action=state&token=${TOKEN}`);
      gState = data;
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

      const container = document.getElementById('rounds');
      container.innerHTML = '';
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
        container.appendChild(card);
      });

      // Botões
      const actions = document.getElementById('actions');
      actions.innerHTML = '';
      if (s.status === 'setup') {
        actions.innerHTML = `
          <button class="btn btn-outline-light me-2" onclick="openImport()"><i class="bi bi-file-earmark-arrow-up"></i> Importar Jogadores</button>
          <button class="btn btn-outline-info me-2" onclick="openAddPlayer()"><i class="bi bi-person-plus"></i> Adicionar Jogador</button>
          <button class="btn btn-outline-warning me-2" onclick="randomizeOrder()"><i class="bi bi-shuffle"></i> Sortear Ordem</button>
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
    }

    function openImport() {
      const el = document.getElementById('importModal');
      const modal = new bootstrap.Modal(el);
      el.querySelector('textarea').value = '';
      modal.show();
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
      } catch (e) { alert(e.message); }
    }

    async function doImport() {
      const txt = document.getElementById('importText').value.trim();
      if (!txt) return;
      try {
        const players = JSON.parse(txt); // Espera JSON: [{name,position,age,ovr,...}]
        await api('/api/initdraft.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'import_players', token: TOKEN, players })
        });
        bootstrap.Modal.getInstance(document.getElementById('importModal')).hide();
        await loadState();
      } catch (e) {
        alert('Erro ao importar: ' + e.message);
      }
    }

    async function randomizeOrder() {
      try {
        await api('/api/initdraft.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'randomize_order', token: TOKEN }) });
        await loadState();
      } catch (e) { alert(e.message); }
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

    window.addEventListener('DOMContentLoaded', loadState);
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

  <!-- Import Modal -->
  <div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-orange">Importar Jogadores (JSON)</h5>
          <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p>Exemplo: [{"name":"John Doe","position":"SG","age":22,"ovr":78}]</p>
          <textarea id="importText" class="form-control" rows="10" placeholder='Cole o JSON aqui'></textarea>
        </div>
        <div class="modal-footer border-orange">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" onclick="doImport()">Importar</button>
        </div>
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
              <div class="col-8"><input name="name" class="form-control" placeholder="Nome" required></div>
              <div class="col-4">
                <select name="position" class="form-select" required>
                  <option value="PG">PG</option>
                  <option value="SG">SG</option>
                  <option value="SF" selected>SF</option>
                  <option value="PF">PF</option>
                  <option value="C">C</option>
                </select>
              </div>
              <div class="col-4"><input type="number" name="age" min="16" max="45" class="form-control" placeholder="Idade" required></div>
              <div class="col-4"><input type="number" name="ovr" min="1" max="99" class="form-control" placeholder="OVR" required></div>
              <div class="col-4"><input name="photo_url" class="form-control" placeholder="Foto (URL)"></div>
              <div class="col-12"><input name="secondary_position" class="form-control" placeholder="Posição Secundária (opcional)"></div>
              <div class="col-12"><textarea name="bio" class="form-control" rows="2" placeholder="Bio"></textarea></div>
              <div class="col-6"><textarea name="strengths" class="form-control" rows="2" placeholder="Forças"></textarea></div>
              <div class="col-6"><textarea name="weaknesses" class="form-control" rows="2" placeholder="Fraquezas"></textarea></div>
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

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
