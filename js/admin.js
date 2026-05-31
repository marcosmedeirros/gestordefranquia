const api = async (path, options = {}) => {
  const res = await fetch(`/api/${path}`, {
    headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
    credentials: 'same-origin',
    ...options,
  });
  const text = await res.text();
  let body = {};
  if (text) {
    try {
      body = JSON.parse(text);
    } catch {
      body = { error: text };
    }
  }
  if (!res.ok || body.success === false) {
    const message = body.error || body.message || 'Erro desconhecido';
    throw { ...body, error: message };
  }
  return body;
};

const _leagues = window.ADMIN_LEAGUES && window.ADMIN_LEAGUES.length
  ? window.ADMIN_LEAGUES
  : ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

let appState = {
  view: 'home',
  currentLeague: null,
  currentTeam: null,
  teamDetails: null,
  currentFAleague: _leagues[0] || 'ELITE',
  adminLeagueFilter: null,
  tradeFilters: { league: 'ALL', status: 'all', teamId: '' }
};
let adminFreeAgents = [];
const freeAgencyTeamsCache = {};

function updateTradeFilter(nextFilters = {}) {
  if (Object.prototype.hasOwnProperty.call(nextFilters, 'league')
    && nextFilters.league !== appState.tradeFilters.league) {
    nextFilters.teamId = '';
  }

  appState.tradeFilters = {
    ...appState.tradeFilters,
    ...nextFilters
  };
  showTrades(appState.tradeFilters.status || 'all');
}

// ── Gestão de Usuários ────────────────────────────────────────────
let _gestaoUsers = [];
let _gestaoLeague = _leagues[0] || 'ELITE';

async function showGestao(league) {
  appState.view = 'gestao';
  updateBreadcrumb();
  if (league) _gestaoLeague = league;

  const container = document.getElementById('mainContainer');
  const leagueTabs = _leagues.map(lg => `
    <button class="btn btn-sm ${lg === _gestaoLeague ? 'btn-orange' : 'btn-outline-orange'}"
            onclick="showGestao('${lg}')">${lg}</button>`).join('');

  container.innerHTML = `
    <div class="mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="d-flex gap-2 flex-wrap">${leagueTabs}</div>
      <button class="btn btn-sm btn-outline-orange" onclick="showGestao('${_gestaoLeague}')">
        <i class="bi bi-arrow-repeat"></i>
      </button>
    </div>
    <div class="d-flex gap-2 mb-3 flex-wrap">
      <button class="btn-ghost" style="padding:8px 16px;gap:8px;display:inline-flex;align-items:center" onclick="showOuvidoriaModal()">
        <i class="bi bi-chat-left-dots-fill" style="color:#8b5cf6"></i> Ouvidoria
      </button>
      <button class="btn-ghost" style="padding:8px 16px;gap:8px;display:inline-flex;align-items:center" onclick="showHallOfFame()">
        <i class="bi bi-award-fill" style="color:#eab308"></i> Hall da Fama
      </button>
      <a href="/thepathetic-edit.php" class="btn-ghost" style="padding:8px 16px;gap:8px;display:inline-flex;align-items:center;text-decoration:none">
        <i class="bi bi-newspaper" style="color:#fc0025"></i> The Pathetic
      </a>
    </div>
    <div id="gestaoTableContainer">
      <div class="text-center py-5"><div class="spinner-border text-orange"></div></div>
    </div>`;

  try {
    const data = await api(`admin.php?action=get_users&league=${_gestaoLeague}`);
    _gestaoUsers = data.users || [];
    renderGestaoTable(_gestaoUsers);
  } catch (e) {
    document.getElementById('gestaoTableContainer').innerHTML =
      `<div class="alert alert-danger">Erro ao carregar usuários: ${escapeHtml(e.error || 'Desconhecido')}</div>`;
  }
}

function renderGestaoTable(users) {
  const container = document.getElementById('gestaoTableContainer');
  if (!users.length) {
    container.innerHTML = '<div class="alert alert-info">Nenhum usuário nesta liga.</div>';
    return;
  }

  const rows = users.map(u => {
    const adminBadges = (u.admin_leagues || []).map(l =>
      `<span class="badge bg-gradient-orange me-1" style="font-size:10px">${l}</span>`).join('') || '<span class="text-muted" style="font-size:12px">—</span>';
    const teamPhoto = u.team_photo
      ? `<img src="${escapeHtml(u.team_photo)}" style="width:30px;height:30px;border-radius:8px;object-fit:cover;border:1px solid var(--border)" onerror="this.style.display='none'">`
      : `<div style="width:30px;height:30px;border-radius:8px;background:var(--panel-3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center"><i class="bi bi-people" style="font-size:14px;color:var(--text-3)"></i></div>`;
    const teamName = u.team_city
      ? `${escapeHtml(u.team_city)} ${escapeHtml(u.team_name || '')}`
      : (u.team_name ? escapeHtml(u.team_name) : '<span class="text-muted">—</span>');

    return `
      <tr>
        <td>
          <div style="font-weight:600">${escapeHtml(u.name)}</div>
          <div style="font-size:11px;color:var(--text-3)">${escapeHtml(u.email)}</div>
        </td>
        <td>
          <div class="d-flex align-items-center gap-2">
            ${teamPhoto}
            <span style="font-size:13px">${teamName}</span>
          </div>
        </td>
        <td>${adminBadges}</td>
        <td>
          <button class="btn btn-sm btn-outline-orange" onclick="openGestaoEdit(${u.id})">
            <i class="bi bi-pencil-fill"></i>
          </button>
        </td>
      </tr>`;
  }).join('');

  container.innerHTML = `
    <div class="table-responsive">
      <table class="table table-dark table-hover" style="font-size:13px">
        <thead>
          <tr>
            <th>Usuário</th>
            <th>Time</th>
            <th>Ligas Admin</th>
            <th style="width:60px"></th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

function openGestaoEdit(userId) {
  const u = _gestaoUsers.find(x => x.id == userId);
  if (!u) return;

  const allLeagues = ['ELITE','NEXT','RISE','ROOKIE'];
  const adminChecks = window.IS_GLOBAL_ADMIN ? allLeagues.map(l => `
    <div class="form-check form-check-inline">
      <input class="form-check-input" type="checkbox" id="ck-${l}" value="${l}" ${(u.admin_leagues||[]).includes(l) ? 'checked' : ''}>
      <label class="form-check-label" for="ck-${l}">${l}</label>
    </div>`).join('') : '';

  const resetBtn = window.IS_GLOBAL_ADMIN ? `
    <button type="button" class="btn btn-outline-warning w-100 mt-2" onclick="confirmResetPassword(${u.id}, '${escapeHtml(u.name)}')">
      <i class="bi bi-key-fill me-1"></i>Redefinir senha para fbabrasil123
    </button>` : '';

  const modalHtml = `
    <div class="modal fade" id="gestaoEditModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-person-gear me-2" style="color:var(--red)"></i>Editar Usuário</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" id="gedit-user-id" value="${u.id}">
            <input type="hidden" id="gedit-team-id" value="${u.team_id || ''}">

            <div class="mb-3">
              <label class="form-label text-light-gray">Nome</label>
              <input type="text" id="gedit-name" class="form-control" value="${escapeHtml(u.name)}">
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">E-mail</label>
              <input type="email" id="gedit-email" class="form-control" value="${escapeHtml(u.email)}">
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Logo do Time</label>
              <div class="d-flex align-items-center gap-3">
                <div style="width:60px;height:60px;border-radius:10px;background:var(--panel-3);border:1px solid var(--border);overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center">
                  <img id="gedit-photo-preview" src="${escapeHtml(u.team_photo || '')}"
                       style="width:100%;height:100%;object-fit:cover;${u.team_photo ? '' : 'display:none'}"
                       onerror="this.style.display='none'">
                  <i class="bi bi-image" id="gedit-photo-placeholder" style="font-size:22px;color:var(--text-3);${u.team_photo ? 'display:none' : ''}"></i>
                </div>
                <div>
                  <label class="btn-ghost" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;padding:7px 13px;font-size:12px">
                    <i class="bi bi-upload"></i> Enviar nova logo
                    <input type="file" id="gedit-team-photo-file" accept="image/png,image/jpeg,image/webp" style="display:none" onchange="onGestaoPhotoChange(this)">
                  </label>
                  <input type="hidden" id="gedit-team-photo" value="${escapeHtml(u.team_photo || '')}">
                  <div id="gedit-photo-name" style="font-size:11px;color:var(--text-3);margin-top:4px">${u.team_photo ? 'Logo atual salva' : 'Sem logo'}</div>
                </div>
              </div>
            </div>
            ${window.IS_GLOBAL_ADMIN ? `
            <div class="mb-3">
              <label class="form-label text-light-gray">Ligas Admin</label>
              <div class="d-flex flex-wrap gap-2 mt-1">${adminChecks}</div>
            </div>` : ''}
            ${resetBtn}
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-orange" onclick="saveGestaoUser()">
              <i class="bi bi-save me-1"></i>Salvar
            </button>
          </div>
        </div>
      </div>
    </div>`;

  document.body.insertAdjacentHTML('beforeend', modalHtml);
  const modal = new bootstrap.Modal(document.getElementById('gestaoEditModal'));
  modal.show();
  document.getElementById('gestaoEditModal').addEventListener('hidden.bs.modal', function() { this.remove(); });
}

function onGestaoPhotoChange(input) {
  const file = input.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    const data = e.target.result;
    document.getElementById('gedit-team-photo').value = data;
    const preview = document.getElementById('gedit-photo-preview');
    if (preview) { preview.src = data; preview.style.display = ''; }
    const ph = document.getElementById('gedit-photo-placeholder');
    if (ph) ph.style.display = 'none';
    const nameEl = document.getElementById('gedit-photo-name');
    if (nameEl) nameEl.textContent = file.name;
  };
  reader.readAsDataURL(file);
}

async function saveGestaoUser() {
  const userId   = parseInt(document.getElementById('gedit-user-id').value);
  const teamId   = parseInt(document.getElementById('gedit-team-id').value) || null;
  const name     = document.getElementById('gedit-name').value.trim();
  const email    = document.getElementById('gedit-email').value.trim();
  const teamPhoto = document.getElementById('gedit-team-photo').value.trim();

  try {
    await api('admin.php?action=update_user', {
      method: 'POST',
      body: JSON.stringify({ user_id: userId, team_id: teamId, name, email, team_photo: teamPhoto })
    });

    if (window.IS_GLOBAL_ADMIN) {
      const leagues = Array.from(document.querySelectorAll('#gestaoEditModal .form-check-input:checked')).map(c => c.value);
      await api('admin.php?action=set_user_league_admin', {
        method: 'POST',
        body: JSON.stringify({ user_id: userId, leagues })
      });
    }

    bootstrap.Modal.getInstance(document.getElementById('gestaoEditModal'))?.hide();
    showAlert('success', 'Usuário atualizado!');
    showGestao(_gestaoLeague);
  } catch (e) {
    showAlert('danger', 'Erro: ' + (e.error || 'Desconhecido'));
  }
}

async function confirmResetPassword(userId, userName) {
  if (!confirm(`Redefinir a senha de "${userName}" para fbabrasil123?`)) return;
  try {
    await api('admin.php?action=reset_user_password', {
      method: 'POST',
      body: JSON.stringify({ user_id: userId })
    });
    showAlert('success', `Senha de ${userName} redefinida!`);
  } catch (e) {
    showAlert('danger', 'Erro: ' + (e.error || 'Desconhecido'));
  }
}

async function init() {
  if (window.location.hash === '#temporadas' && typeof showSeasonsManagement === 'function') {
    history.replaceState(null, '', window.location.pathname);
    showSeasonsManagement();
  } else {
    showLeague(_leagues[0]);
  }
}

// showHome() mantido para compatibilidade com botões "Voltar" nas sub-views
function showHome() { showLeague(appState.currentLeague || _leagues[0]); }

function updateBreadcrumb() {
  const breadcrumb = document.getElementById('breadcrumb');
  const breadcrumbContainer = document.getElementById('breadcrumbContainer');
  const pageTitle = document.getElementById('pageTitle');

  const leagueBack = appState.currentLeague || _leagues[0];
  breadcrumb.innerHTML = `<li class="breadcrumb-item"><a href="#" onclick="showLeague('${leagueBack}'); return false;">${leagueBack}</a></li>`;

  if (appState.view === 'league') {
    breadcrumbContainer.style.display = 'none';
    pageTitle.textContent = `Liga ${appState.currentLeague}`;
  } else {
    breadcrumbContainer.style.display = 'block';
    const labels = {
      team:         () => { breadcrumb.innerHTML += `<li class="breadcrumb-item active">${appState.currentTeam?.city} ${appState.currentTeam?.name}</li>`; return `${appState.currentTeam?.city} ${appState.currentTeam?.name}`; },
      trades:       () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Trades</li>'; return 'Gerenciar Trades'; },
      config:       () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Configurações</li>'; return 'Configurações das Ligas'; },
      seasons:      () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Temporadas</li>'; return 'Gerenciar Temporadas'; },
      ranking:      () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Rankings</li>'; return 'Rankings Globais'; },
      freeagency:   () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Leilões</li>'; return 'Gerenciar Leilões'; },
      faadmin:      () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Free Agency</li>'; return 'Free Agency'; },
      punicoes:     () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Punições</li>'; return 'Punições'; },
      coins:        () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Moedas</li>'; return 'Gerenciar Moedas'; },
      tapas:        () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Tapas</li>'; return 'Gerenciar Tapas'; },
      userApprovals:() => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Aprovação de Usuários</li>'; return 'Aprovar Usuários'; },
      halloffame:   () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Hall da Fama</li>'; return 'Hall da Fama'; },
      dispensas:    () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Dispensas</li>'; return 'Dispensas por Temporada'; },
      pontuacao:    () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Pontuação</li>'; return 'Pontuação por Temporada'; },
      gestao:       () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Gestão</li>'; return 'Gestão de Usuários'; },
      draft:        () => { breadcrumb.innerHTML += '<li class="breadcrumb-item active">Draft</li>'; return `Draft — ${appState.currentLeague || ''}`; },
    };
    const fn = labels[appState.view];
    pageTitle.textContent = fn ? fn() : 'Painel Administrativo';
  }

  // Atualiza aba ativa do quicknav
  document.querySelectorAll('.admin-qnav-btn').forEach(b => b.classList.remove('active'));
  const activeId = appState.view === 'gestao'
    ? 'qnav-gestao'
    : appState.view === 'seasons'
      ? 'qnav-temporadas'
      : `qnav-${(appState.currentLeague || _leagues[0]).toLowerCase()}`;
  const activeBtn = document.getElementById(activeId);
  if (activeBtn) activeBtn.classList.add('active');
}

function escapeHtml(value) {
  const map = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
  };
  return String(value ?? '').replace(/[&<>"']/g, (ch) => map[ch]);
}




async function loadOuvidoriaMessages() {
  const list = document.getElementById('ouvidoriaList');
  const modalList = document.getElementById('ouvidoriaModalList');
  const totalEl = document.getElementById('ouvidoriaTotal');
  const subjectFilter = document.getElementById('ouvidoriaSubjectFilter')?.value || '';
  if (list) {
    list.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-orange"></div></div>';
  }
  if (modalList) {
    modalList.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-orange"></div></div>';
  }

  try {
    const params = new URLSearchParams({ limit: 8 });
    if (subjectFilter) {
      params.set('subject', subjectFilter);
    }
    const data = await api(`ouvidoria.php?${params.toString()}`);
    const messages = data.messages || [];
    if (totalEl) {
      totalEl.textContent = data.total ?? messages.length;
    }

    const renderHtml = () => {
      if (messages.length === 0) {
        return '<div class="text-center py-4 text-light-gray">Nenhuma mensagem ainda.</div>';
      }

      return messages.map(msg => {
        const date = msg.created_at ? new Date(msg.created_at).toLocaleString('pt-BR') : '-';
        const subject = escapeHtml(msg.subject || 'Reclamação');
        const content = escapeHtml(msg.message || '').replace(/\n/g, '<br>');
        return `
          <div class="bg-dark border border-secondary rounded p-3 mb-2">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div>
                <div class="text-light-gray small"><i class="bi bi-clock me-1"></i>${date}</div>
                <div class="mt-1"><span class="badge bg-secondary">${subject}</span></div>
              </div>
              <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteOuvidoriaMessage(${msg.id})">
                <i class="bi bi-trash"></i>
              </button>
            </div>
            <div class="text-white mt-2">${content}</div>
          </div>
        `;
      }).join('');
    };

    if (list) {
      list.innerHTML = renderHtml();
    }
    if (modalList) {
      modalList.innerHTML = renderHtml();
    }
  } catch (e) {
    if (list) {
      list.innerHTML = '<div class="alert alert-danger">Erro ao carregar ouvidoria.</div>';
    }
    if (modalList) {
      modalList.innerHTML = '<div class="alert alert-danger">Erro ao carregar ouvidoria.</div>';
    }
  }
}

function ensureOuvidoriaModal() {
  if (document.getElementById('ouvidoriaModal')) return;

  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = 'ouvidoriaModal';
  modal.tabIndex = -1;
  modal.innerHTML = `
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-chat-left-dots me-2 text-orange"></i>Ouvidoria</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <label class="text-light-gray" for="ouvidoriaSubjectFilter">Assunto</label>
            <select id="ouvidoriaSubjectFilter" class="form-select form-select-sm" style="max-width: 220px;">
              <option value="">Todos</option>
              <option value="Reclamação">Reclamação</option>
              <option value="Sugestão">Sugestão</option>
              <option value="Erro de Gameplay">Erro de Gameplay</option>
            </select>
          </div>
          <div id="ouvidoriaModalList"><div class="text-center py-3"><div class="spinner-border text-orange"></div></div></div>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-outline-light" onclick="loadOuvidoriaMessages()">
            <i class="bi bi-arrow-clockwise me-1"></i>Atualizar
          </button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);

  const filter = modal.querySelector('#ouvidoriaSubjectFilter');
  if (filter) {
    filter.addEventListener('change', () => loadOuvidoriaMessages());
  }
}

function showOuvidoriaModal() {
  ensureOuvidoriaModal();
  loadOuvidoriaMessages();
  const modalEl = document.getElementById('ouvidoriaModal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
}

async function deleteOuvidoriaMessage(messageId) {
  if (!messageId) return;
  const confirmed = confirm('Apagar esta mensagem da ouvidoria?');
  if (!confirmed) return;

  try {
    await api('ouvidoria.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'delete_message', message_id: messageId })
    });
    loadOuvidoriaMessages();
  } catch (e) {
    alert(e.error || 'Erro ao apagar mensagem.');
  }
}

function ensureCopyRosterModal() {
  if (document.getElementById('copyRosterModal')) return;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = 'copyRosterModal';
  modal.tabIndex = -1;
  modal.innerHTML = `
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-clipboard-check me-2 text-orange"></i>Elencos da liga</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <textarea id="copyRosterTextarea" class="form-control bg-dark text-white border-secondary" rows="14" readonly></textarea>
          <small class="text-light-gray d-block mt-2">Toque e segure para copiar no celular.</small>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-outline-light" id="copyRosterClipboardBtn">
            <i class="bi bi-clipboard me-1"></i>Copiar
          </button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);

  const copyBtn = modal.querySelector('#copyRosterClipboardBtn');
  if (copyBtn) {
    copyBtn.addEventListener('click', async () => {
      const textarea = document.getElementById('copyRosterTextarea');
      if (!textarea) return;
      try {
        await navigator.clipboard.writeText(textarea.value);
        alert('Elencos copiados para a área de transferência!');
      } catch (e) {
        textarea.focus();
        textarea.select();
      }
    });
  }
}

function ensureCopyPicksModal() {
  if (document.getElementById('copyPicksModal')) return;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = 'copyPicksModal';
  modal.tabIndex = -1;
  modal.innerHTML = `
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content bg-dark border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white"><i class="bi bi-calendar2-check me-2 text-orange"></i>Picks 1ª rodada</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <textarea id="copyPicksTextarea" class="form-control bg-dark text-white border-secondary" rows="14" readonly></textarea>
          <small class="text-light-gray d-block mt-2">Toque e segure para copiar no celular.</small>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-outline-light" id="copyPicksClipboardBtn">
            <i class="bi bi-clipboard me-1"></i>Copiar
          </button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);

  modal.querySelector('#copyPicksClipboardBtn').addEventListener('click', async () => {
    const textarea = document.getElementById('copyPicksTextarea');
    if (!textarea) return;
    try {
      await navigator.clipboard.writeText(textarea.value);
      alert('Picks copiadas para a área de transferência!');
    } catch (e) {
      textarea.focus();
      textarea.select();
    }
  });
}

async function copyLeaguePicks() {
  const league = appState.currentLeague || document.getElementById('copyRosterLeague')?.value || 'ELITE';
  ensureCopyPicksModal();
  const textarea = document.getElementById('copyPicksTextarea');
  if (textarea) textarea.value = 'Carregando...';
  const modalEl = document.getElementById('copyPicksModal');
  if (modalEl) new bootstrap.Modal(modalEl).show();

  try {
    const data = await api(`admin.php?action=copy_picks&league=${league}`);
    if (textarea) textarea.value = data.text || 'Nenhuma pick encontrada.';
  } catch (e) {
    if (textarea) textarea.value = e.error || 'Erro ao copiar picks.';
  }
}

async function copyLeagueRosters() {
  const league = appState.currentLeague || document.getElementById('copyRosterLeague')?.value || 'ELITE';
  ensureCopyRosterModal();
  const textarea = document.getElementById('copyRosterTextarea');
  if (textarea) {
    textarea.value = 'Carregando...';
  }
  const modalEl = document.getElementById('copyRosterModal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }

  try {
    const data = await api(`admin.php?action=copy_rosters&league=${league}`);
    if (textarea) {
      textarea.value = data.text || 'Nenhum elenco encontrado.';
    }
  } catch (e) {
    if (textarea) {
      textarea.value = e.error || 'Erro ao copiar elencos.';
    }
  }
}

async function showLeague(league) {
  appState.view = 'league';
  appState.currentLeague = league;
  updateBreadcrumb();

  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border" style="color:var(--red)"></div></div>';

  try {
    const [data, seasonData, draftData] = await Promise.all([
      api(`admin.php?action=teams&league=${league}`),
      api(`seasons.php?action=list_seasons&league=${league}`).catch(() => ({ seasons: [] })),
      api(`draft.php?action=active_draft&league=${league}`).catch(() => ({ draft: null }))
    ]);
    const teams = data.teams || [];
    const seasons = seasonData.seasons || [];
    const currentSeason = seasons.find(s => s.status !== 'completed') || seasons[0] || null;
    const seasonYear = currentSeason
      ? (currentSeason.start_year && currentSeason.season_number
          ? (parseInt(currentSeason.start_year) + parseInt(currentSeason.season_number) - 1)
          : (currentSeason.year || '—'))
      : '—';
    const seasonNumber = currentSeason ? (parseInt(currentSeason.season_number) || 1) : '—';
    const totalSeasons = currentSeason?.sprint_max_seasons || seasons[0]?.sprint_max_seasons || '—';

    const teamCards = teams.map(t => `
      <div class="col-6 col-md-4 col-xl-3">
        <div class="team-card" onclick="showTeam(${t.id})">
          <div class="d-flex align-items-center gap-2 mb-2">
            <img src="${escapeHtml(t.photo_url || '/img/default-team.png')}" class="team-logo" onerror="this.src='/img/default-team.png'">
            <div style="min-width:0">
              <div style="font-size:13px;font-weight:700;color:var(--text);line-height:1.2">${escapeHtml(t.city)}</div>
              <div style="font-size:12px;font-weight:600;color:var(--text-2);line-height:1.2">${escapeHtml(t.name)}</div>
              <div style="font-size:11px;color:var(--text-3)">${escapeHtml(t.owner_name)}</div>
            </div>
          </div>
          <div class="d-flex justify-content-between flex-wrap gap-1" style="font-size:11px">
            <span style="color:var(--text-2)"><i class="bi bi-people-fill" style="color:var(--red)"></i> ${t.player_count}</span>
            <span style="color:var(--text-2)"><i class="bi bi-star-fill" style="color:var(--red)"></i> ${t.cap_top8}</span>
            <span style="color:var(--text-2)"><i class="bi bi-hand-index-thumb" style="color:#f59e0b"></i> ${parseInt(t.tapas||0)}</span>
            <span style="color:var(--text-2)"><i class="bi bi-arrow-left-right" style="color:#3b82f6"></i> ${parseInt(t.trades_used||0)}</span>
            <span style="color:var(--text-2)"><i class="bi bi-person-dash" style="color:#22c55e"></i> ${parseInt(t.waivers_used||0)}</span>
          </div>
          <div class="d-flex align-items-center justify-content-between mt-2" style="font-size:11px;border-top:1px solid rgba(255,255,255,.06);padding-top:6px" onclick="event.stopPropagation()">
            <span style="color:var(--text-2)"><i class="bi bi-exclamation-triangle-fill" style="color:#f43f5e"></i> Avisos</span>
            <div class="d-flex align-items-center gap-1">
              <button class="btn-ghost" style="padding:1px 7px;font-size:12px;line-height:1.4" onclick="event.stopPropagation();_adminCardAvisosAdj(${t.id},'${escapeHtml(league)}',-1,this)">−</button>
              <span id="avisos-count-${t.id}" style="font-weight:700;min-width:18px;text-align:center;color:${parseInt(t.avisos_count||0)>0?'#f43f5e':'var(--text-2)'}">${parseInt(t.avisos_count||0)}</span>
              <button class="btn-ghost" style="padding:1px 7px;font-size:12px;line-height:1.4" onclick="event.stopPropagation();_adminCardAvisosAdj(${t.id},'${escapeHtml(league)}',1,this)">+</button>
            </div>
          </div>
        </div>
      </div>`).join('');

    const actions = [
      { icon: 'bi-person-check-fill', label: 'Aprovar<br>Usuários',  fn: 'showUserApprovals()',    color: '#fc0025', bg: 'rgba(252,0,37,.12)',   badgeId: 'action-badge-approvals' },
      { icon: 'bi-arrow-left-right',  label: 'Trades',               fn: 'showTrades()',            color: '#3b82f6', bg: 'rgba(59,130,246,.12)' },
      { icon: 'bi-people-fill',       label: 'Free Agency',          fn: 'showFAAdmin()',           color: '#22c55e', bg: 'rgba(34,197,94,.12)'  },
      { icon: 'bi-hammer',            label: 'Leilões',              fn: 'showFreeAgency()',        color: '#f59e0b', bg: 'rgba(245,158,11,.12)' },
      { icon: 'bi-bar-chart-steps',         label: 'Pontuação<br>por Time',      fn: 'showPointsManagement()',    color: '#06b6d4', bg: 'rgba(6,182,212,.12)'   },
      { icon: 'bi-clipboard-data-fill',     label: 'Pontuação da<br>Temporada',  fn: `showRegistroPontuacao('${league}')`,   color: '#10b981', bg: 'rgba(16,185,129,.12)'  },
      { icon: 'bi-shield-check',            label: 'FBA SERASA',                fn: 'showSerasaAdmin()',         color: '#8b5cf6', bg: 'rgba(139,92,246,.12)'  },
      { icon: 'bi-person-dash-fill',        label: 'Dispensas',                 fn: 'showDispensas()',           color: '#ef4444', bg: 'rgba(239,68,68,.12)'   },
      { icon: 'bi-hand-index-thumb',        label: 'Tapas',                     fn: 'showTapas()',               color: '#f97316', bg: 'rgba(249,115,22,.12)'  },
      { icon: 'bi-clipboard-check',         label: 'Diretrizes',                fn: 'showDirectives()',          color: '#14b8a6', bg: 'rgba(20,184,166,.12)'  },
      { icon: 'bi-exclamation-triangle-fill', label: 'Punições',               fn: 'showPunicoes()',            color: '#f43f5e', bg: 'rgba(244,63,94,.12)'   },
      { icon: 'bi-trophy-fill',             label: 'Draft',                     fn: 'showAdminDraft()',          color: '#a855f7', bg: 'rgba(168,85,247,.12)'  },
      { icon: 'bi-coin',                    label: 'Moedas',                    fn: 'showCoins()',               color: '#f59e0b', bg: 'rgba(245,158,11,.12)'  },
    ];

    const actionTiles = actions.map(a => `
      <button class="action-tile" onclick="${a.fn}">
        <div class="action-tile-icon" style="background:${a.bg};color:${a.color}">
          <i class="bi ${a.icon}"></i>
        </div>
        <div class="action-tile-label">${a.label}</div>
        ${a.badgeId ? `<span class="action-tile-badge" id="${a.badgeId}" style="display:none">0</span>` : ''}
      </button>`).join('');

    const activeDraft = draftData?.draft;
    const draftCard = (activeDraft && ['setup', 'in_progress'].includes(activeDraft.status) && !currentSeason) ? (() => {
      const isRunning = activeDraft.status === 'in_progress';
      const statusColor = isRunning ? '#22c55e' : '#f59e0b';
      const statusBg = isRunning ? 'rgba(34,197,94,.1)' : 'rgba(245,158,11,.1)';
      const statusLabel = isRunning ? 'Em andamento' : 'Configurando';
      const timerEl = (isRunning && activeDraft.pick_deadline_ts)
        ? `<span id="admin-draft-pick-timer" style="font-size:12px;font-weight:700;font-variant-numeric:tabular-nums;color:#22c55e;margin-left:6px">⏱ --:--</span>`
        : '';
      const sub = isRunning
        ? `Rodada ${activeDraft.current_round || 1} · Pick ${activeDraft.current_pick || 1}${timerEl}`
        : 'Aguardando configuração da ordem de picks';
      return `
      <div class="panel mb-3" style="border-color:rgba(168,85,247,.35)">
        <div class="panel-header">
          <div>
            <div class="panel-title"><i class="bi bi-trophy-fill" style="color:#a855f7"></i> Draft Inicial — ${league}</div>
            <div class="panel-sub">${sub}</div>
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <span class="pun-badge" style="background:${statusBg};color:${statusColor};border:1px solid ${statusColor}40">${statusLabel}</span>
            <button class="btn-ghost" style="color:#a855f7;border-color:rgba(168,85,247,.3)" onclick="showAdminDraft('${league}')">
              <i class="bi bi-arrow-right-circle me-1"></i> Gerenciar Draft
            </button>
          </div>
        </div>
      </div>`;
    })() : '';

    container.innerHTML = `
      <div class="league-hero">
        <div>
          <div class="league-hero-name">
            <small>Liga</small>
            ${league}
          </div>
        </div>
        <div class="league-hero-stats">
          <div class="league-hero-stat">
            <div class="league-hero-stat-val">${teams.length}</div>
            <div class="league-hero-stat-lbl">Times</div>
          </div>
          <div class="league-hero-stat">
            <div class="league-hero-stat-val" style="font-size:15px;color:var(--text)">${seasonYear}</div>
            <div class="league-hero-stat-lbl">Temp. ${seasonNumber}</div>
          </div>
          <div class="league-hero-stat">
            <div class="league-hero-stat-val" style="color:var(--red)">${seasonNumber}<span style="font-size:13px;font-weight:400;color:var(--text-3)">/${totalSeasons}</span></div>
            <div class="league-hero-stat-lbl">Temporadas</div>
          </div>
        </div>
        <div class="league-hero-tools">
          <div class="league-search-wrap">
            <input type="text" id="leaguePlayerSearch" placeholder="Buscar jogador…">
            <button id="leaguePlayerSearchBtn"><i class="bi bi-search"></i></button>
          </div>
          <button class="btn-ghost" id="copyRosterBtn">
            <i class="bi bi-clipboard"></i> Elencos
          </button>
          <button class="btn-ghost" id="copyPicksBtn">
            <i class="bi bi-calendar2-check"></i> Picks
          </button>
          ${currentSeason
            ? `<button class="btn-ghost" style="color:#10b981;border-color:rgba(16,185,129,.3)" onclick="showAvancarTemporada('${league}')">
                 <i class="bi bi-arrow-right-circle-fill me-1"></i>Avançar Temporada
               </button>`
            : `<button class="btn-ghost" style="color:#f97316;border-color:rgba(249,115,22,.3)" onclick="showAvancarTemporada('${league}')">
                 <i class="bi bi-play-circle-fill me-1"></i>Criar Sprint
               </button>`
          }
        </div>
      </div>

      <div id="leaguePlayerSearchResults"></div>

      ${draftCard}

      <div class="action-grid">${actionTiles}</div>

      <div id="leagueConfigInline" class="panel mb-3" style="display:none">
        <div class="panel-header">
          <div class="panel-title"><i class="bi bi-sliders" style="color:#94a3b8"></i> Configurações</div>
          <button class="btn-ghost" style="padding:6px 10px;font-size:12px" id="saveConfigInlineBtn">
            <i class="bi bi-save2 me-1"></i>Salvar
          </button>
        </div>
        <div id="leagueConfigInlineBody"></div>
      </div>

      <div class="panel mb-3" id="leagueQuickSearchPanel">
        <div class="panel-header">
          <div class="panel-title"><i class="bi bi-search" style="color:#94a3b8"></i> Busca Rápida</div>
          <div style="display:flex;gap:6px">
            <button class="btn-ghost" id="srchTabPlayer" style="font-size:12px" onclick="setLeagueSearchType('player')"><i class="bi bi-person-fill me-1"></i>Jogador</button>
            <button class="btn-ghost" id="srchTabPick" style="font-size:12px;color:var(--text-2)" onclick="setLeagueSearchType('pick')"><i class="bi bi-calendar2-check me-1"></i>Pick</button>
          </div>
        </div>
        <div id="srchPlayerPanel">
          <div class="d-flex gap-2">
            <input type="text" id="srchPlayerInput" class="form-control bg-dark text-white" style="border-color:rgba(252,0,37,.35);font-size:13px" placeholder="Nome do jogador...">
            <button class="btn btn-sm" style="background:var(--red);color:#fff;white-space:nowrap;padding:6px 14px" onclick="runLeaguePlayerSearch()"><i class="bi bi-search"></i></button>
          </div>
          <div id="srchPlayerResults" class="mt-2"></div>
        </div>
        <div id="srchPickPanel" style="display:none">
          <select id="srchPickTeam" class="form-select bg-dark text-white" style="border-color:rgba(252,0,37,.35);font-size:13px" onchange="runLeaguePickSearch(this.value)">
            <option value="">Selecionar time...</option>
          </select>
          <div id="srchPickResults" class="mt-2"></div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-header">
          <div class="panel-title" style="margin-bottom:0"><i class="bi bi-people-fill"></i> Times</div>
          <span style="font-size:12px;color:var(--text-3)">${teams.length} cadastrados</span>
        </div>
        <div class="row g-2 mt-1">${teamCards || '<div class="col-12"><p class="empty-state">Nenhum time cadastrado.</p></div>'}</div>
      </div>
    `;

    setupLeaguePlayerSearch(league);
    setupLeagueQuickSearch(league);
    document.getElementById('copyRosterBtn')?.addEventListener('click', copyLeagueRosters);
    document.getElementById('copyPicksBtn')?.addEventListener('click', copyLeaguePicks);

    if (activeDraft?.status === 'in_progress' && activeDraft?.pick_deadline_ts) {
      _startAdminDraftTimer(Number(activeDraft.pick_deadline_ts), 'admin-draft-pick-timer');
    }

    try {
      const approvalData = await api('user-approval.php');
      const count = (approvalData.users || []).length;
      const badge = document.getElementById('action-badge-approvals');
      if (badge && count > 0) { badge.textContent = count; badge.style.display = 'inline-flex'; }
    } catch (e) {}

    ensureOuvidoriaModal();
    _loadLeagueConfigInline(league);
  } catch (e) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar liga</div>';
  }
}

function setupLeaguePlayerSearch(league) {
  const input = document.getElementById('leaguePlayerSearch');
  const button = document.getElementById('leaguePlayerSearchBtn');
  const results = document.getElementById('leaguePlayerSearchResults');
  if (!input || !results) return;

  let debounceTimer = null;

  const runSearch = async () => {
    const term = (input.value || '').trim();
    if (term.length < 2) {
      results.innerHTML = '';
      return;
    }
    results.innerHTML = '<div class="search-results-panel"><div class="spinner-border" style="color:var(--red);width:1.25rem;height:1.25rem" role="status"></div></div>';
    try {
      const data = await api(`admin.php?action=search_players&league=${encodeURIComponent(league)}&query=${encodeURIComponent(term)}`);
      const players = data.players || [];
      if (!players.length) {
        results.innerHTML = '<div class="search-results-panel" style="color:var(--text-3)">Nenhum jogador encontrado.</div>';
        return;
      }
      const rows = players.map(p => {
        const ovr = p.ovr != null ? p.ovr : '-';
        const age = p.age != null ? p.age : '-';
        return `<div class="search-result-row">
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--text)">${escapeHtml(p.name)}</div>
            <div style="font-size:11px;color:var(--text-3)">${p.position || '-'} · OVR ${ovr} · ${age} anos</div>
          </div>
          <div style="font-size:12px;color:var(--text-2);text-align:right">${escapeHtml((p.team_city||'') + ' ' + (p.team_name||''))}</div>
        </div>`;
      }).join('');
      results.innerHTML = `<div class="search-results-panel">${rows}</div>`;
    } catch (e) {
      results.innerHTML = `<div class="search-results-panel" style="color:var(--red)">${e.error || 'Erro ao buscar.'}</div>`;
    }
  };

  input.addEventListener('input', () => {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(runSearch, 350);
  });
  button?.addEventListener('click', runSearch);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); runSearch(); }
  });
}

// =====================================================================
// League Quick Search
// =====================================================================

const _leagueSearchCache = { players: [], ownedPicks: [], awayPicks: [] };

function setupLeagueQuickSearch(league) {
  const input = document.getElementById('srchPlayerInput');
  if (!input) return;
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); runLeaguePlayerSearch(); }
  });
}

function setLeagueSearchType(type) {
  const playerPanel = document.getElementById('srchPlayerPanel');
  const pickPanel   = document.getElementById('srchPickPanel');
  const tabPlayer   = document.getElementById('srchTabPlayer');
  const tabPick     = document.getElementById('srchTabPick');
  if (!playerPanel) return;
  if (type === 'player') {
    playerPanel.style.display = '';
    pickPanel.style.display   = 'none';
    tabPlayer.style.color = 'var(--text)';
    tabPick.style.color   = 'var(--text-2)';
  } else {
    playerPanel.style.display = 'none';
    pickPanel.style.display   = '';
    tabPlayer.style.color = 'var(--text-2)';
    tabPick.style.color   = 'var(--text)';
    _populateSrchPickTeams();
  }
}

async function _populateSrchPickTeams() {
  const sel = document.getElementById('srchPickTeam');
  if (!sel || sel.dataset.loaded) return;
  const league = appState.currentLeague;
  try {
    const data = await api(`admin.php?action=teams&league=${encodeURIComponent(league)}`);
    sel.innerHTML = '<option value="">Selecionar time...</option>';
    (data.teams || []).forEach(t => {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = `${t.city} ${t.name}`;
      sel.appendChild(opt);
    });
    sel.dataset.loaded = '1';
  } catch (e) {}
}

async function runLeaguePlayerSearch() {
  const input   = document.getElementById('srchPlayerInput');
  const results = document.getElementById('srchPlayerResults');
  if (!input || !results) return;
  const term = input.value.trim();
  if (term.length < 2) { results.innerHTML = ''; return; }
  const league = appState.currentLeague;
  results.innerHTML = '<div style="color:var(--text-2);font-size:13px;padding:6px 0">Buscando...</div>';
  try {
    const data = await api(`admin.php?action=search_players&league=${encodeURIComponent(league)}&query=${encodeURIComponent(term)}`);
    let players = (data.players || []);
    players.sort((a, b) => (Number(b.ovr) || 0) - (Number(a.ovr) || 0));
    players = players.slice(0, 10);
    _leagueSearchCache.players = players;
    if (!players.length) {
      results.innerHTML = '<div style="color:var(--text-3);font-size:13px;padding:6px 0">Nenhum jogador encontrado.</div>';
      return;
    }
    results.innerHTML = players.map(p => `
      <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06)">
        <div>
          <div style="font-size:13px;font-weight:600;color:var(--text)">${escapeHtml(p.name)}</div>
          <div style="font-size:11px;color:var(--text-3)">${p.position||'-'} · OVR ${p.ovr??'-'} · ${p.age??'-'} anos · ${escapeHtml((p.team_city||'') + ' ' + (p.team_name||''))}</div>
        </div>
        <div style="display:flex;gap:4px;flex-shrink:0">
          <button class="btn-ghost" style="padding:4px 8px;font-size:11px" title="Mover time" onclick="srchMovePlayer(${p.id})"><i class="bi bi-arrow-left-right"></i></button>
          <button class="btn-ghost" style="padding:4px 8px;font-size:11px" title="Editar" onclick="srchEditPlayer(${p.id})"><i class="bi bi-pencil"></i></button>
          <button class="btn-ghost" style="padding:4px 8px;font-size:11px;color:var(--red)" title="Deletar" onclick="srchDeletePlayer(${p.id})"><i class="bi bi-trash"></i></button>
        </div>
      </div>`).join('');
  } catch (e) {
    results.innerHTML = `<div style="color:var(--red);font-size:13px;padding:6px 0">Erro: ${e.error || 'Erro ao buscar'}</div>`;
  }
}

async function runLeaguePickSearch(teamId) {
  if (!teamId) teamId = document.getElementById('srchPickTeam')?.value;
  const results = document.getElementById('srchPickResults');
  if (!results || !teamId) return;
  results.innerHTML = '<div style="color:var(--text-2);font-size:13px;padding:6px 0">Buscando...</div>';
  try {
    const data = await api(`picks.php?team_id=${teamId}&include_away=1`);
    const owned = (data.picks || []).filter(p => Number(p.round) === 1);
    const away  = (data.picks_away || []).filter(p => Number(p.round) === 1);
    _leagueSearchCache.ownedPicks = owned;
    _leagueSearchCache.awayPicks  = away;
    if (!owned.length && !away.length) {
      results.innerHTML = '<div style="color:var(--text-3);font-size:13px;padding:6px 0">Nenhuma pick de 1ª rodada encontrada.</div>';
      return;
    }
    const swBadge = st => st
      ? `<span style="font-size:10px;color:#f59e0b;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);border-radius:4px;padding:1px 5px;margin-left:4px">${st}</span>`
      : '';
    const pickRow = (p, isAway) => `
      <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06)">
        <div>
          <div style="font-size:13px;font-weight:600;color:var(--text)">${p.season_year} R${p.round}${swBadge(p.swap_type)}</div>
          <div style="font-size:11px;color:var(--text-3)">${isAway
            ? 'Atual: ' + escapeHtml((p.current_team_city||'') + ' ' + (p.current_team_name||''))
            : 'Original: ' + escapeHtml((p.original_team_city||'') + ' ' + (p.original_team_name||''))
          }</div>
        </div>
        <div style="display:flex;gap:4px;flex-shrink:0">
          <button class="btn-ghost" style="padding:4px 8px;font-size:11px" title="Mover dono" onclick="srchMovePick(${p.id},${isAway})"><i class="bi bi-arrow-left-right"></i></button>
          <button class="btn-ghost" style="padding:4px 8px;font-size:11px;color:#f59e0b" title="SWAP" onclick="srchSwapPick(${p.id},${isAway})">SWAP</button>
        </div>
      </div>`;
    let html = '';
    if (owned.length) {
      html += `<div style="font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.08em;padding-top:4px;padding-bottom:2px">Picks que o time possui</div>`;
      html += owned.map(p => pickRow(p, false)).join('');
    }
    if (away.length) {
      html += `<div style="font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.08em;padding-top:${owned.length?'12':'4'}px;padding-bottom:2px">Picks originais do time (em outros times)</div>`;
      html += away.map(p => pickRow(p, true)).join('');
    }
    results.innerHTML = html;
  } catch (e) {
    results.innerHTML = `<div style="color:var(--red);font-size:13px;padding:6px 0">Erro: ${e.error || 'Erro ao buscar picks'}</div>`;
  }
}

// --- Player actions from search context ---

function srchMovePlayer(playerId) {
  const p = _leagueSearchCache.players.find(x => x.id == playerId);
  if (!p) return;
  const league = appState.currentLeague;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Mover ${escapeHtml(p.name)}</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="mb-3"><label class="form-label text-light-gray">Time de destino</label>
<select class="form-select bg-dark text-white border-orange" id="srchMovePlayerTeam"><option value="">Carregando...</option></select></div></div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="_applySrchMovePlayer(${playerId})">Mover</button></div></div></div>`;
  document.body.appendChild(modal);
  api(`admin.php?action=teams&league=${encodeURIComponent(league)}`).then(data => {
    const sel = modal.querySelector('#srchMovePlayerTeam');
    sel.innerHTML = '';
    (data.teams || []).filter(t => t.id != p.team_id).forEach(t => {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = `${t.city} ${t.name}`;
      sel.appendChild(opt);
    });
  });
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function _applySrchMovePlayer(playerId) {
  const teamId = parseInt(document.getElementById('srchMovePlayerTeam')?.value);
  if (!teamId) { alert('Selecione o time destino!'); return; }
  try {
    await api('admin.php?action=player', { method: 'PUT', body: JSON.stringify({ player_id: playerId, team_id: teamId }) });
    const m = document.querySelector('.modal.show');
    bootstrap.Modal.getInstance(m)?.hide();
    showAlert('success', 'Jogador movido!');
    runLeaguePlayerSearch();
  } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
}

function srchEditPlayer(playerId) {
  const p = _leagueSearchCache.players.find(x => x.id == playerId);
  if (!p) return;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Editar ${escapeHtml(p.name)}</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<div class="mb-3"><label class="form-label text-light-gray">Posição</label>
<input type="text" class="form-control bg-dark text-white border-orange" id="srchEditPos" value="${escapeHtml(p.position||'')}"></div>
<div class="row">
<div class="col-6 mb-3"><label class="form-label text-light-gray">Idade</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="srchEditAge" value="${p.age||''}" min="16" max="60"></div>
<div class="col-6 mb-3"><label class="form-label text-light-gray">OVR</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="srchEditOvr" value="${p.ovr||0}" min="0" max="99"></div>
</div>
<div class="mb-3"><label class="form-label text-light-gray">Papel</label>
<select class="form-select bg-dark text-white border-orange" id="srchEditRole">
<option value="Titular" ${p.role==='Titular'?'selected':''}>Titular</option>
<option value="Banco" ${p.role==='Banco'?'selected':''}>Banco</option>
<option value="Outro" ${p.role==='Outro'?'selected':''}>Outro</option>
<option value="G-League" ${p.role==='G-League'?'selected':''}>G-League</option>
</select></div>
</div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="_applySrchEditPlayer(${playerId})">Salvar</button></div></div></div>`;
  document.body.appendChild(modal);
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function _applySrchEditPlayer(playerId) {
  const ageVal = parseInt(document.getElementById('srchEditAge')?.value || '', 10);
  const data = {
    player_id: playerId,
    position: document.getElementById('srchEditPos').value,
    ovr: parseInt(document.getElementById('srchEditOvr').value, 10),
    role: document.getElementById('srchEditRole').value,
  };
  if (!Number.isNaN(ageVal)) data.age = ageVal;
  try {
    await api('admin.php?action=player', { method: 'PUT', body: JSON.stringify(data) });
    const m = document.querySelector('.modal.show');
    bootstrap.Modal.getInstance(m)?.hide();
    showAlert('success', 'Jogador atualizado!');
    runLeaguePlayerSearch();
  } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
}

async function srchDeletePlayer(playerId) {
  if (!confirm('Deletar jogador?')) return;
  try {
    await api(`admin.php?action=player&id=${playerId}`, { method: 'DELETE' });
    showAlert('success', 'Jogador deletado!');
    runLeaguePlayerSearch();
  } catch (e) { alert('Erro ao deletar jogador'); }
}

// --- Pick actions from search context ---

function srchMovePick(pickId, isAway) {
  const cache = isAway ? _leagueSearchCache.awayPicks : _leagueSearchCache.ownedPicks;
  const p = cache.find(x => x.id == pickId);
  if (!p) return;
  const league = appState.currentLeague;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Mover Pick — ${p.season_year} R${p.round}</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<p style="font-size:13px;color:var(--text-2);margin-bottom:12px">Original: <strong>${escapeHtml((p.original_team_city||p.current_team_city||'') + ' ' + (p.original_team_name||p.current_team_name||''))}</strong></p>
<div class="mb-3"><label class="form-label text-light-gray">Mover para o time</label>
<select class="form-select bg-dark text-white border-orange" id="srchMovePickTeam"><option value="">Carregando...</option></select></div>
</div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="_applySrchMovePick(${pickId},${isAway})">Mover</button></div></div></div>`;
  document.body.appendChild(modal);
  api(`admin.php?action=teams&league=${encodeURIComponent(league)}`).then(data => {
    const sel = modal.querySelector('#srchMovePickTeam');
    sel.innerHTML = '';
    (data.teams || []).forEach(t => {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = `${t.city} ${t.name}`;
      if (t.id == p.team_id) opt.selected = true;
      sel.appendChild(opt);
    });
  });
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function _applySrchMovePick(pickId, isAway) {
  const cache = isAway ? _leagueSearchCache.awayPicks : _leagueSearchCache.ownedPicks;
  const p = cache.find(x => x.id == pickId);
  if (!p) return;
  const destTeamId = parseInt(document.getElementById('srchMovePickTeam')?.value);
  if (!destTeamId) { alert('Selecione o time destino!'); return; }
  try {
    await api('admin.php?action=pick', { method: 'PUT', body: JSON.stringify({
      pick_id: pickId,
      team_id: destTeamId,
      original_team_id: p.original_team_id,
      season_year: p.season_year,
      round: p.round,
      swap_type: p.swap_type || null,
      notes: p.notes || null
    })});
    const m = document.querySelector('.modal.show');
    bootstrap.Modal.getInstance(m)?.hide();
    showAlert('success', 'Pick movida!');
    const teamId = document.getElementById('srchPickTeam')?.value;
    if (teamId) runLeaguePickSearch(teamId);
  } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
}

function srchSwapPick(pickId, isAway) {
  const cache = isAway ? _leagueSearchCache.awayPicks : _leagueSearchCache.ownedPicks;
  const p = cache.find(x => x.id == pickId);
  if (!p) return;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog modal-sm"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white" style="font-size:14px">Swap — ${p.season_year} R${p.round}</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="d-flex flex-column gap-2">
<button type="button" class="btn ${!p.swap_type ? 'btn-orange' : 'btn-secondary'}" onclick="_applySrchSwap(${pickId},${isAway},'')">Nenhum</button>
<button type="button" class="btn ${p.swap_type==='SW' ? 'btn-orange' : 'btn-outline-light'}" onclick="_applySrchSwap(${pickId},${isAway},'SW')">SW — Worst</button>
<button type="button" class="btn ${p.swap_type==='SB' ? 'btn-orange' : 'btn-outline-light'}" onclick="_applySrchSwap(${pickId},${isAway},'SB')">SB — Best</button>
</div></div></div></div>`;
  document.body.appendChild(modal);
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function _applySrchSwap(pickId, isAway, swapType) {
  const cache = isAway ? _leagueSearchCache.awayPicks : _leagueSearchCache.ownedPicks;
  const p = cache.find(x => x.id == pickId);
  if (!p) return;
  try {
    await api('admin.php?action=pick', { method: 'PUT', body: JSON.stringify({
      pick_id: pickId,
      team_id: p.team_id,
      original_team_id: p.original_team_id,
      season_year: p.season_year,
      round: p.round,
      swap_type: swapType || null,
      notes: p.notes || null
    })});
    const m = document.querySelector('.modal.show');
    bootstrap.Modal.getInstance(m)?.hide();
    showAlert('success', swapType ? `Swap: ${swapType}` : 'Swap removido');
    const teamId = document.getElementById('srchPickTeam')?.value;
    if (teamId) runLeaguePickSearch(teamId);
  } catch (e) { alert('Erro: ' + (e.error || 'Desconhecido')); }
}

async function showTeam(teamId) {
  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';
  try {
    const data = await api(`admin.php?action=team_details&team_id=${teamId}`);
    appState.teamDetails = data.team;
    appState.currentTeam = data.team;
    appState.view = 'team';
    updateBreadcrumb();
    const t = data.team;
    const ovrStyle = ovr => ovr >= 80
      ? 'background:rgba(74,222,128,.15);color:#4ade80;border:1px solid rgba(74,222,128,.3)'
      : ovr >= 70
        ? 'background:rgba(251,191,36,.15);color:#fbbf24;border:1px solid rgba(251,191,36,.3)'
        : 'background:rgba(148,163,184,.15);color:#94a3b8;border:1px solid rgba(148,163,184,.3)';
    container.innerHTML = `
<div class="mb-3"><button class="btn btn-back" onclick="showLeague('${t.league}')"><i class="bi bi-arrow-left"></i> Voltar</button></div>

<div class="panel mb-3">
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
    <img src="${t.photo_url || '/img/default-team.png'}" alt="logo"
         style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid var(--red)">
    <div style="flex:1;min-width:0">
      <div style="font-size:20px;font-weight:700;color:var(--text)">${escapeHtml(t.city)} ${escapeHtml(t.name)}</div>
      <div style="font-size:13px;color:var(--text-3);margin-top:2px">${escapeHtml(t.owner_name)}</div>
      <span class="badge bg-gradient-orange mt-1">${t.league}</span>
    </div>
    <button class="btn-ghost" onclick="editTeam(${t.id})"><i class="bi bi-pencil-fill me-1"></i>Editar</button>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
    <div class="pun-card" style="flex:1;min-width:110px;padding:12px 16px;text-align:center">
      <div style="font-size:20px;font-weight:700;color:var(--red)">${t.cap_top8}${t.restricted_bonus > 0 ? ` <small style="color:#f59e0b;font-size:.65em">+${t.restricted_bonus}</small>` : ''}</div>
      <div style="font-size:11px;color:var(--text-3)">CAP Top 8${t.restricted_bonus > 0 ? ` · ${t.restricted_eligible} Franquia${t.restricted_eligible > 1 ? 's' : ''}` : ''}</div>
    </div>
    <div class="pun-card" style="flex:1;min-width:110px;padding:12px 16px;text-align:center;cursor:pointer" onclick="editTeamCounter(${t.id}, 'trades_used', ${parseInt(t.trades_used || 0)})">
      <div style="font-size:20px;font-weight:700;color:#38bdf8" id="tradesUsedDisplay">${parseInt(t.trades_used || 0)}</div>
      <div style="font-size:11px;color:var(--text-3)">Trocas feitas <i class="bi bi-pencil-fill" style="font-size:9px"></i></div>
    </div>
    <div class="pun-card" style="flex:1;min-width:110px;padding:12px 16px;text-align:center;cursor:pointer" onclick="editTeamCounter(${t.id}, 'waivers_used', ${parseInt(t.waivers_used || 0)})">
      <div style="font-size:20px;font-weight:700;color:#4ade80" id="waiversUsedDisplay">${parseInt(t.waivers_used || 0)}</div>
      <div style="font-size:11px;color:var(--text-3)">Dispensas feitas <i class="bi bi-pencil-fill" style="font-size:9px"></i></div>
    </div>
  </div>
</div>

<div class="panel mb-3">
  <div class="panel-header">
    <div class="panel-title"><i class="bi bi-people-fill"></i> Elenco <span style="font-size:12px;color:var(--text-3);font-weight:400">(${t.players.length})</span></div>
    <button class="btn-ghost" onclick="addPlayer(${t.id})"><i class="bi bi-plus-circle me-1"></i>Adicionar</button>
  </div>
  ${t.players.length === 0
    ? '<div style="text-align:center;padding:24px;color:var(--text-3)">Nenhum jogador no elenco</div>'
    : t.players.map(p => {
        const isFE = t.league === 'RISE' && Number(p.was_traded) === 0 && Number(p.drafted_by_team_id) === t.id && Number(p.ovr) >= 90;
        const isLoyal = t.league === 'RISE' && Number(p.was_traded) === 0;
        const borderColor = isFE ? 'rgba(245,158,11,.6)' : (isLoyal ? 'rgba(6,182,212,.6)' : '');
        return `<div class="pun-card" style="display:flex;align-items:center;gap:12px${borderColor ? ';border-left:3px solid '+borderColor : ''}">
  <div style="flex:1;min-width:0">
    <span style="font-weight:600;color:var(--text)">${escapeHtml(p.name)}</span>${isFE ? ' <span style="background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.35);border-radius:999px;font-size:10px;font-weight:700;padding:2px 6px">Franquia</span>' : (isLoyal ? ' <span style="background:rgba(6,182,212,.15);color:#06b6d4;border:1px solid rgba(6,182,212,.35);border-radius:999px;font-size:10px;font-weight:700;padding:2px 6px">Leal</span>' : '')}
    <div style="font-size:12px;color:var(--text-3);margin-top:2px">${escapeHtml(p.position)} · ${p.age} anos · ${escapeHtml(p.role)}</div>
  </div>
  <span style="${ovrStyle(p.ovr)};border-radius:6px;padding:3px 8px;font-size:13px;font-weight:700">${p.ovr}</span>
  <div style="display:flex;gap:6px">
    <button class="btn-ghost" style="padding:5px 8px" onclick="editPlayer(${p.id})"><i class="bi bi-pencil-fill"></i></button>
    <button class="btn-ghost" style="padding:5px 8px;color:#ef4444" onclick="deletePlayer(${p.id})"><i class="bi bi-trash-fill"></i></button>
  </div>
</div>`;
      }).join('')}
</div>

${(() => {
    const curYear = new Date().getFullYear();
    const picks = (t.picks || []).filter(p => Number(p.season_year) >= curYear);
    const swapLabel = type => type === 'SW' ? '(SW) Worst' : type === 'SB' ? '(SB) Best' : escapeHtml(type);
    const pickRows = !picks.length
      ? '<div style="text-align:center;padding:24px;color:var(--text-3)">Nenhum pick</div>'
      : picks.map(p => `<div class="pun-card" style="display:flex;align-items:center;gap:10px">
  <div style="flex:1;min-width:0">
    <span style="font-weight:600;color:var(--text)">${p.season_year} · ${p.round}ª rodada</span>${p.swap_type ? ` <span style="background:rgba(252,0,37,.12);color:var(--red);border:1px solid rgba(252,0,37,.25);border-radius:6px;padding:2px 6px;font-size:11px;font-weight:700">${swapLabel(p.swap_type)}</span>` : ''}
    <div style="font-size:12px;color:var(--text-3);margin-top:2px">${escapeHtml(p.city)} ${escapeHtml(p.team_name)}</div>
  </div>
  <div style="display:flex;gap:5px;align-items:center;flex-shrink:0">
    <button class="btn-ghost" style="padding:3px 7px;font-size:10px;font-weight:700;${p.swap_type ? 'color:var(--red);border-color:rgba(252,0,37,.25)' : 'color:var(--text-3)'}" onclick="quickSwapType(${p.id})" title="Tipo de swap">SWAP?</button>
    <button class="btn-ghost" style="padding:5px 7px" onclick="movePick(${p.id})" title="Mover para outro time"><i class="bi bi-arrow-left-right"></i></button>
    <button class="btn-ghost" style="padding:5px 7px" onclick="editPick(${p.id})"><i class="bi bi-pencil-fill"></i></button>
    <button class="btn-ghost" style="padding:5px 7px;color:#ef4444" onclick="deletePick(${p.id})"><i class="bi bi-trash-fill"></i></button>
  </div>
</div>`).join('');
    return `<div class="panel mb-3">
  <div class="panel-header">
    <div class="panel-title"><i class="bi bi-calendar-check-fill"></i> Picks <span style="font-size:12px;color:var(--text-3);font-weight:400">(${picks.length})</span></div>
    <button class="btn-ghost" onclick="addPick(${t.id})"><i class="bi bi-plus-circle me-1"></i>Adicionar</button>
  </div>
  ${pickRows}
</div>`;
  })()}`;
  } catch (e) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar time</div>';
  }
}

async function editTeamCounter(teamId, field, currentValue) {
  const labels = { trades_used: 'Trocas feitas', waivers_used: 'Dispensas feitas' };
  const displayIds = { trades_used: 'tradesUsedDisplay', waivers_used: 'waiversUsedDisplay' };
  const label = labels[field] || field;
  const newVal = prompt(`Novo valor para "${label}" (atual: ${currentValue}):`, currentValue);
  if (newVal === null) return;
  const parsed = parseInt(newVal, 10);
  if (isNaN(parsed) || parsed < 0) return alert('Valor inválido. Informe um número inteiro >= 0.');
  try {
    await api('admin.php?action=team', {
      method: 'PUT',
      body: JSON.stringify({ team_id: teamId, [field]: parsed })
    });
    const el = document.getElementById(displayIds[field]);
    if (el) el.textContent = parsed;
  } catch (e) {
    alert('Erro ao atualizar: ' + (e.error || 'Desconhecido'));
  }
}

async function showTrades() {
  const _wasInTrades = appState.view === 'trades';
  appState.view = 'trades';
  appState.tradeFilters.status = 'accepted';
  if (appState.currentLeague && !_wasInTrades) appState.tradeFilters.league = appState.currentLeague;
  updateBreadcrumb();

  const container = document.getElementById('mainContainer');
  const leagueFilter = (appState.tradeFilters.league || 'ALL').toUpperCase();
  const teamFilter = appState.tradeFilters.teamId || '';

  const leagueOptions = [
    { value: 'ALL', label: 'Todas as ligas' },
    { value: 'ELITE', label: 'ELITE' },
    { value: 'NEXT', label: 'NEXT' },
    { value: 'RISE', label: 'RISE' },
    { value: 'ROOKIE', label: 'ROOKIE' }
  ];

  const _tradeBack = appState.currentLeague ? `showLeague('${appState.currentLeague}')` : 'showHome()';
  container.innerHTML = `
<div class="mb-4"><button class="btn btn-back" onclick="${_tradeBack}"><i class="bi bi-arrow-left"></i> Voltar</button></div>
<div class="panel mb-3">
  <div class="panel-header">
    <div class="panel-title"><i class="bi bi-arrow-left-right"></i> Trades <span id="tradesCountBadge" style="font-size:12px;font-weight:400;color:var(--text-3)"></span></div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <select style="background:var(--panel-2);border:1px solid var(--border-md);color:var(--text);border-radius:var(--radius-sm);padding:5px 10px;font-size:12px" onchange="updateTradeFilter({ league: this.value })">
        ${leagueOptions.map(opt => `<option value="${opt.value}" ${opt.value === leagueFilter ? 'selected' : ''}>${opt.label}</option>`).join('')}
      </select>
      <select style="background:var(--panel-2);border:1px solid var(--border-md);color:var(--text);border-radius:var(--radius-sm);padding:5px 10px;font-size:12px;min-width:160px" id="adminTradeTeamFilter" onchange="updateTradeFilter({ teamId: this.value })">
        <option value="">Todos os times</option>
      </select>
    </div>
  </div>
</div>
<div id="tradesListContainer"><div class="text-center py-4"><div class="spinner-border" style="color:var(--red)"></div></div></div>`;
  
  try {
    const teamUrl = leagueFilter && leagueFilter !== 'ALL'
      ? `admin.php?action=teams&league=${encodeURIComponent(leagueFilter)}`
      : 'admin.php?action=teams';
    const teamsData = await api(teamUrl);
    const teams = teamsData.teams || [];

    const teamSelect = document.getElementById('adminTradeTeamFilter');
    if (teamSelect) {
      const previous = teamFilter;
      teamSelect.innerHTML = '<option value="">Todos os times</option>';
      const sortedTeams = [...teams].sort((a, b) => {
        const aLabel = `${a.league || ''} ${a.city || ''} ${a.name || ''}`.trim();
        const bLabel = `${b.league || ''} ${b.city || ''} ${b.name || ''}`.trim();
        return aLabel.localeCompare(bLabel);
      });
      sortedTeams.forEach((team) => {
        const option = document.createElement('option');
        option.value = String(team.id);
        option.textContent = leagueFilter === 'ALL'
          ? `${team.league || '-'} - ${team.city} ${team.name}`
          : `${team.city} ${team.name}`;
        teamSelect.appendChild(option);
      });
      if (previous && sortedTeams.some((team) => String(team.id) === String(previous))) {
        teamSelect.value = String(previous);
      }
    }

    let url = 'admin.php?action=trades&status=accepted';
    if (leagueFilter && leagueFilter !== 'ALL') {
      url += `&league=${encodeURIComponent(leagueFilter)}`;
    }
    if (teamFilter) {
      url += `&team_id=${encodeURIComponent(teamFilter)}`;
    }
    const data = await api(url);
    const trades = data.trades || [];
    const tc = document.getElementById('tradesListContainer');

    const badge = document.getElementById('tradesCountBadge');
    if (badge) badge.textContent = `(${trades.length})`;

    const filteredTrades = trades;
    
    if (filteredTrades.length === 0) {
      tc.innerHTML = '<div class="text-center py-5 text-light-gray">Nenhuma trade</div>';
      return;
    }
    
    const formatAdminTradePlayer = (player) => {
      if (!player) return '';
      const name = player.name || 'Jogador (dispensado)';
      const position = player.position || '-';
      const ovr = player.ovr ?? '?';
      const age = player.age ?? '?';
      return `${name} (${position}, ${ovr}/${age})`;
    };

    const renderTradeAssets = (players = [], picks = []) => {
      const playerItems = players.map(p =>
        `<div style="font-size:12px;color:var(--text);padding:2px 0"><i class="bi bi-person-fill" style="color:var(--red);margin-right:4px"></i>${formatAdminTradePlayer(p)}</div>`
      ).join('');
      const pickItems = picks.map(pk => {
        const roundNumber = parseInt(pk.round, 10);
        const roundLabel = Number.isNaN(roundNumber) ? `${pk.round}ª rodada` : `${roundNumber}ª rodada`;
        const seasonLabel = pk.season_year ? `${pk.season_year}` : 'Temporada indefinida';
        const originalTeam = `${pk.city} ${pk.team_name}`;
        const swapTag = pk.swap_type ? ` <span style="font-size:10px;color:var(--text-3)">${pk.swap_type}</span>` : '';
        return `<div style="font-size:12px;color:var(--text);padding:2px 0"><i class="bi bi-ticket-detailed" style="color:var(--red);margin-right:4px"></i>${seasonLabel} ${roundLabel} - ${originalTeam}${swapTag}</div>`;
      }).join('');
      const content = playerItems + pickItems;
      return content || '<span style="font-size:12px;color:var(--text-3)">Nada</span>';
    };

    const formatMultiTradeItemDetail = (item) => {
      if (!item) return 'Item';
      if (item.player_id || item.player_name) {
        return formatAdminTradePlayer({
          name: item.player_name,
          position: item.player_position,
          age: item.player_age,
          ovr: item.player_ovr
        });
      }
      if (item.pick_id) {
        const roundNumber = parseInt(item.round, 10);
        const roundLabel = Number.isNaN(roundNumber) ? `${item.round}ª rodada` : `${roundNumber}ª rodada`;
        const seasonLabel = item.season_year ? `${item.season_year}` : 'Temporada indefinida';
        const originalTeam = `${item.original_team_city || ''} ${item.original_team_name || ''}`.trim() || 'Time indefinido';
        return `${seasonLabel} ${roundLabel} - ${originalTeam}`;
      }
      return 'Item';
    };

    const renderMultiTradeCard = (tr) => {
      const statusColor = { pending: '#f59e0b', accepted: '#22c55e', cancelled: '#64748b' }[tr.status] || '#64748b';
      const statusLabel = { pending: 'Pendente', accepted: 'Aceita', cancelled: 'Cancelada' }[tr.status] || tr.status;

      const teamMap = {};
      (tr.teams || []).forEach(team => {
        teamMap[team.id] = `${team.city} ${team.name}`;
      });
      const leagueLabel = tr.league || '-';
      const isAccepted = Number(tr.is_in_game || 0) === 1;

      const teamsLine = (tr.teams || []).map(t => teamMap[t.id] || `Time ${t.id}`).join(' · ');

      const byTeam = {};
      (tr.items || []).forEach(item => {
        const toId = String(item.to_team_id);
        if (!byTeam[toId]) byTeam[toId] = [];
        byTeam[toId].push(item);
      });
      const itemsHtml = Object.keys(byTeam).length > 0
        ? Object.entries(byTeam).map(([toId, teamItems]) => {
            const toLabel = teamMap[toId] || `Time ${toId}`;
            const rows = teamItems.map(item => {
              const detail = formatMultiTradeItemDetail(item);
              const fromLabel = teamMap[String(item.from_team_id)];
              const fromHtml = fromLabel ? `<span style="color:var(--text-3);font-size:11px">de ${fromLabel} → </span>` : '';
              return `<div style="font-size:12px;color:var(--text);padding:2px 0">${fromHtml}${detail}</div>`;
            }).join('');
            return `<div style="margin-bottom:8px"><div style="font-size:11px;font-weight:600;color:var(--red);margin-bottom:2px">${toLabel} recebe:</div>${rows}</div>`;
          }).join('')
        : '<span style="color:var(--text-3);font-size:12px">Nenhum item</span>';

      const pendingNote = tr.status === 'pending'
        ? `<span style="font-size:11px;color:#06b6d4">Aceites: ${tr.teams_accepted || 0}/${tr.teams_total || 0}</span>`
        : '';

      return `<div class="pun-card${isAccepted ? ' pun-card-reverted' : ''}" data-trade-id="${tr.id}" style="margin-bottom:10px">
  <div class="pun-card-head">
    <div>
      <div class="pun-card-title">Trade múltipla <span style="font-size:11px;font-weight:400;color:var(--text-3)">${leagueLabel}</span></div>
      <div class="pun-card-sub">${teamsLine}</div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-shrink-0">
      ${pendingNote}
      <span class="pun-badge" style="background:${statusColor}20;color:${statusColor};border-color:${statusColor}40">${statusLabel}</span>
      <label style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text-3);cursor:pointer">
        <input type="checkbox" ${isAccepted ? 'checked' : ''} onchange="toggleAdminTradeAccept(${tr.id}, this.checked)" style="width:14px;height:14px;cursor:pointer">
        Game
      </label>
      ${tr.status === 'accepted' ? `<button class="btn-ghost" style="padding:3px 8px;font-size:11px" onclick="revertMultiTrade(${tr.id})">Reverter</button>` : ''}
    </div>
  </div>
  <div style="margin-top:10px">${itemsHtml}</div>
  ${tr.notes ? `<div class="pun-card-meta" style="margin-top:8px"><i class="bi bi-chat-left-text me-1"></i>${tr.notes}</div>` : ''}
  <div class="pun-card-meta">${new Date(tr.created_at).toLocaleString('pt-BR')}</div>
</div>`;
    };

    tc.innerHTML = filteredTrades.map(tr => {
      if (tr.is_multi) {
        return renderMultiTradeCard(tr);
      }
      const statusColor = { pending: '#f59e0b', accepted: '#22c55e', rejected: '#ef4444', cancelled: '#64748b', countered: '#06b6d4' }[tr.status] || '#64748b';
      const statusLabel = { pending: 'Pendente', accepted: 'Aceita', rejected: 'Recusada', cancelled: 'Cancelada', countered: 'Counter' }[tr.status] || tr.status;
      const isAccepted = Number(tr.is_in_game || 0) === 1;

      const offerHtml = renderTradeAssets(tr.offer_players || [], tr.offer_picks || []);
      const requestHtml = renderTradeAssets(tr.request_players || [], tr.request_picks || []);

      return `<div class="pun-card${isAccepted ? ' pun-card-reverted' : ''}" data-trade-id="${tr.id}" style="margin-bottom:10px">
  <div class="pun-card-head">
    <div>
      <div class="pun-card-title">${tr.from_city} ${tr.from_name} <i class="bi bi-arrow-right" style="color:var(--red);margin:0 4px"></i> ${tr.to_city} ${tr.to_name}</div>
      <div class="pun-card-sub">${tr.from_league || '-'} · ${new Date(tr.created_at).toLocaleDateString('pt-BR')}</div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-shrink-0">
      <span class="pun-badge" style="background:${statusColor}20;color:${statusColor};border-color:${statusColor}40">${statusLabel}</span>
      <label style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text-3);cursor:pointer">
        <input type="checkbox" ${isAccepted ? 'checked' : ''} onchange="toggleAdminTradeAccept(${tr.id}, this.checked)" style="width:14px;height:14px;cursor:pointer">
        Game
      </label>
      ${tr.status === 'pending' ? `<button class="btn-ghost" style="padding:3px 8px;font-size:11px;color:#ef4444;border-color:rgba(239,68,68,.3)" onclick="cancelTrade(${tr.id})">Cancelar</button>` : ''}
      ${tr.status === 'accepted' ? `<button class="btn-ghost" style="padding:3px 8px;font-size:11px" onclick="revertTrade(${tr.id})">Reverter</button>` : ''}
    </div>
  </div>
  ${tr.notes ? `<div class="pun-card-meta" style="margin-top:6px"><i class="bi bi-chat-left-text me-1"></i>${tr.notes}</div>` : ''}
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px">
    <div>
      <div style="font-size:11px;font-weight:600;color:var(--red);margin-bottom:4px">${tr.from_city} ${tr.from_name} oferece:</div>
      ${offerHtml}
    </div>
    <div>
      <div style="font-size:11px;font-weight:600;color:var(--red);margin-bottom:4px">${tr.to_city} ${tr.to_name} oferece:</div>
      ${requestHtml}
    </div>
  </div>
</div>`;
    }).join('');
  } catch (e) {
    document.getElementById('tradesListContainer').innerHTML = '<div class="alert alert-danger">Erro</div>';
  }
}

// ========== HALL DA FAMA ==========
let hallOfFameLeague = 'ELITE';

async function showHallOfFame() {
  appState.view = 'halloffame';
  updateBreadcrumb();

  const _hofInitLeague = appState.currentLeague || hallOfFameLeague || 'ELITE';
  const _hofBack = appState.currentLeague ? `showLeague('${appState.currentLeague}')` : 'showHome()';

  const container = document.getElementById('mainContainer');
  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back" onclick="${_hofBack}"><i class="bi bi-arrow-left"></i> Voltar</button>
    </div>

    <div style="display:grid;grid-template-columns:340px 1fr;gap:16px;align-items:start">

      <!-- Formulário -->
      <div class="pun-card">
        <div class="pun-card-head">
          <div class="pun-card-title"><i class="bi bi-award-fill" style="color:var(--amber);margin-right:6px"></i>Adicionar no Hall da Fama</div>
        </div>
        <div style="padding:16px;display:flex;flex-direction:column;gap:12px">

          <div>
            <div style="font-size:11px;font-weight:600;color:var(--text-3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.6px">Tipo</div>
            <select id="hofType" style="width:100%;background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;padding:8px 10px;color:var(--text);font-size:13px;outline:none">
              <option value="active">Ativo (liga + time)</option>
              <option value="inactive">Inativo (nome + GM)</option>
            </select>
          </div>

          <div id="hofActiveFields">
            <div style="margin-bottom:10px">
              <div style="font-size:11px;font-weight:600;color:var(--text-3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.6px">Liga</div>
              <select id="hofLeague" style="width:100%;background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;padding:8px 10px;color:var(--text);font-size:13px;outline:none">
                ${_leagues.map(l => `<option value="${l}"${l === _hofInitLeague ? ' selected' : ''}>${l}</option>`).join('')}
              </select>
            </div>
            <div>
              <div style="font-size:11px;font-weight:600;color:var(--text-3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.6px">Time</div>
              <select id="hofTeam" style="width:100%;background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;padding:8px 10px;color:var(--text);font-size:13px;outline:none"></select>
            </div>
          </div>

          <div id="hofInactiveFields" style="display:none">
            <div style="font-size:11px;font-weight:600;color:var(--text-3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.6px">Nome do GM</div>
            <input type="text" id="hofGmName" placeholder="Ex: John Doe"
              style="width:100%;background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;padding:8px 10px;color:var(--text);font-size:13px;outline:none">
          </div>

          <div>
            <div style="font-size:11px;font-weight:600;color:var(--text-3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.6px">Títulos</div>
            <input type="number" id="hofTitles" min="0" value="0"
              style="width:100%;background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;padding:8px 10px;color:var(--text);font-size:13px;outline:none">
          </div>

          <button id="hofAddBtn" class="btn-ghost" style="width:100%;justify-content:center;color:#22c55e;border-color:rgba(34,197,94,.3);padding:9px">
            <i class="bi bi-plus-circle me-1"></i>Adicionar
          </button>
        </div>
      </div>

      <!-- Lista -->
      <div class="pun-card">
        <div class="pun-card-head">
          <div class="pun-card-title"><i class="bi bi-list-stars" style="color:var(--amber);margin-right:6px"></i>Lista do Hall da Fama</div>
        </div>
        <div id="hofList" style="padding:4px 0">
          <div style="text-align:center;padding:32px"><div class="spinner-border" style="width:24px;height:24px;border-width:3px;border-color:var(--border-md);border-top-color:var(--red)"></div></div>
        </div>
      </div>

    </div>
  `;

  document.getElementById('hofType').addEventListener('change', toggleHallOfFameType);
  document.getElementById('hofLeague').addEventListener('change', (e) => {
    hallOfFameLeague = e.target.value;
    loadHallOfFameTeams(hallOfFameLeague);
  });
  document.getElementById('hofAddBtn').addEventListener('click', submitHallOfFameEntry);

  hallOfFameLeague = document.getElementById('hofLeague').value || _hofInitLeague;
  loadHallOfFameTeams(hallOfFameLeague);
  loadHallOfFameList();
}

function toggleHallOfFameType() {
  const type = document.getElementById('hofType').value;
  const activeFields = document.getElementById('hofActiveFields');
  const inactiveFields = document.getElementById('hofInactiveFields');
  if (type === 'inactive') {
    activeFields.style.display = 'none';
    inactiveFields.style.display = 'block';
  } else {
    activeFields.style.display = 'block';
    inactiveFields.style.display = 'none';
  }
}

async function loadHallOfFameTeams(league) {
  const select = document.getElementById('hofTeam');
  if (!select) return;
  select.innerHTML = '<option>Carregando...</option>';
  try {
    const data = await api(`admin.php?action=teams&league=${league}`);
    const teams = data.teams || [];
    if (!teams.length) {
      select.innerHTML = '<option value="">Sem times na liga</option>';
      return;
    }
    select.innerHTML = teams
      .map(t => `<option value="${t.id}">${t.city} ${t.name}</option>`)
      .join('');
  } catch (e) {
    select.innerHTML = '<option value="">Erro ao carregar</option>';
  }
}

async function submitHallOfFameEntry() {
  const type = document.getElementById('hofType').value;
  const titles = parseInt(document.getElementById('hofTitles').value || '0', 10);

  const payload = {
    is_active: type === 'active' ? 1 : 0,
    titles: Number.isNaN(titles) ? 0 : titles
  };

  if (type === 'active') {
    payload.league = document.getElementById('hofLeague').value;
    payload.team_id = parseInt(document.getElementById('hofTeam').value || '0', 10);
  } else {
    payload.gm_name = (document.getElementById('hofGmName').value || '').trim();
  }

  try {
    await api('admin.php?action=hall_of_fame', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    document.getElementById('hofTitles').value = 0;
    document.getElementById('hofGmName').value = '';
    loadHallOfFameList();
  } catch (e) {
    alert(e.error || 'Erro ao salvar');
  }
}

async function loadHallOfFameList() {
  const container = document.getElementById('hofList');
  if (!container) return;
  container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-orange"></div></div>';
  try {
    const data = await api('admin.php?action=hall_of_fame');
    const items = data.items || [];
    if (!items.length) {
      container.innerHTML = '<p class="empty-state" style="padding:32px">Nenhum registro ainda.</p>';
      return;
    }

    container.innerHTML = items.map(item => {
      const isActive = item.is_active;
      const sc = isActive ? '#22c55e' : 'var(--text-3)';
      return `
      <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border)">
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:700;color:var(--text)">${escapeHtml(item.team_name || item.gm_name || '—')}</div>
          <div style="display:flex;align-items:center;gap:6px;margin-top:3px;flex-wrap:wrap">
            ${item.league ? `<span style="font-size:10px;font-weight:700;background:var(--red-soft);color:var(--red);border:1px solid rgba(252,0,37,.2);border-radius:999px;padding:1px 7px">${item.league}</span>` : ''}
            <span style="font-size:10px;font-weight:600;color:${sc}">${isActive ? 'Ativo' : 'Inativo'}</span>
            ${item.gm_name && item.team_name ? `<span style="font-size:11px;color:var(--text-3)">GM: ${escapeHtml(item.gm_name)}</span>` : ''}
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
          <input type="number" min="0" value="${item.titles || 0}" data-hof-title="${item.id}"
            style="width:64px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:7px;padding:5px 8px;color:var(--amber);font-size:13px;font-weight:700;text-align:center;outline:none">
          <button class="btn-ghost" style="padding:5px 8px;color:#22c55e" onclick="saveHallOfFameTitles(${item.id})" title="Salvar">
            <i class="bi bi-floppy"></i>
          </button>
          <button class="btn-ghost" style="padding:5px 8px;color:#ef4444" onclick="deleteHallOfFameEntry(${item.id})" title="Remover">
            <i class="bi bi-trash3"></i>
          </button>
        </div>
      </div>`;
    }).join('');
  } catch (e) {
    container.innerHTML = '<p class="empty-state" style="padding:32px;color:#ef4444">Erro ao carregar lista.</p>';
  }
}

async function saveHallOfFameTitles(id) {
  const input = document.querySelector(`[data-hof-title="${id}"]`);
  if (!input) return;
  const titles = parseInt(input.value || '0', 10);
  try {
    await api('admin.php?action=hall_of_fame', {
      method: 'PUT',
      body: JSON.stringify({ id, titles: Number.isNaN(titles) ? 0 : titles })
    });
  } catch (e) {
    alert(e.error || 'Erro ao salvar');
  }
}

async function deleteHallOfFameEntry(id) {
  if (!confirm('Remover este registro do Hall da Fama?')) return;
  try {
    await api('admin.php?action=hall_of_fame', {
      method: 'DELETE',
      body: JSON.stringify({ id })
    });
    loadHallOfFameList();
  } catch (e) {
    alert(e.error || 'Erro ao remover');
  }
}

async function toggleAdminTradeAccept(tradeId, checked) {
  const card = document.querySelector(`[data-trade-id="${tradeId}"]`);
  if (card) {
    card.classList.toggle('is-accepted', checked);
  }
  try {
    await api('admin.php?action=trade_in_game', {
      method: 'PUT',
      body: JSON.stringify({ trade_id: tradeId, is_in_game: checked ? 1 : 0 })
    });
  } catch (e) {
    if (card) {
      card.classList.toggle('is-accepted', !checked);
    }
    alert(e.error || 'Erro ao atualizar status da trade.');
  }
}

async function showConfig() {
  appState.view = 'config';
  updateBreadcrumb();

  const _cfgLeague = appState.currentLeague || null;
  const _cfgBack = _cfgLeague ? `showLeague('${_cfgLeague}')` : 'showHome()';

  const container = document.getElementById('mainContainer');
  container.innerHTML = `
<div class="mb-4"><button class="btn btn-back" onclick="${_cfgBack}"><i class="bi bi-arrow-left"></i> Voltar</button></div>
<div id="configContainer"><div class="text-center py-4"><div class="spinner-border" style="color:var(--red)"></div></div></div>`;

  try {
    const [cfgData, seasonData] = await Promise.all([
      api('admin.php?action=leagues'),
      _cfgLeague
        ? api(`seasons.php?action=list_seasons&league=${_cfgLeague}`).catch(() => ({ seasons: [] }))
        : Promise.resolve({ seasons: [] })
    ]);
    const allLeagues = cfgData.leagues || [];
    const filtered = _cfgLeague ? allLeagues.filter(lg => lg.league === _cfgLeague) : allLeagues;
    const seasons = seasonData.seasons || [];
    const currentSeason = seasons.find(s => s.status !== 'completed') || seasons[0] || null;
    const seasonYear = currentSeason
      ? (currentSeason.start_year && currentSeason.season_number
          ? (parseInt(currentSeason.start_year) + parseInt(currentSeason.season_number) - 1)
          : (currentSeason.year || '—'))
      : '—';
    const seasonNumber = currentSeason ? (parseInt(currentSeason.season_number) || '—') : '—';
    const totalSeasons = seasons.length || '—';

    document.getElementById('configContainer').innerHTML = filtered.map(lg => `
<div class="panel mb-4">

  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px">
    <div>
      <div style="font-size:10px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:var(--red);margin-bottom:4px">Central da Liga</div>
      <div style="font-size:30px;font-weight:900;color:var(--text);letter-spacing:-.5px;line-height:1">${lg.league}</div>
      <div style="font-size:12px;color:var(--text-3);margin-top:4px">${lg.team_count} ${lg.team_count === 1 ? 'time' : 'times'} cadastrados</div>
    </div>
    <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center">
      <div style="text-align:center;min-width:48px">
        <div style="font-size:22px;font-weight:800;color:var(--text)">${seasonYear}</div>
        <div style="font-size:10px;color:var(--text-3);font-weight:500;margin-top:1px">Temp. ${seasonNumber}</div>
      </div>
      <div style="width:1px;height:36px;background:var(--border)"></div>
      <div style="text-align:center;min-width:56px">
        <div style="font-size:22px;font-weight:800;color:var(--red)">${seasonNumber}<span style="font-size:13px;font-weight:400;color:var(--text-3)">/${totalSeasons}</span></div>
        <div style="font-size:10px;color:var(--text-3);font-weight:500;margin-top:1px">Temporadas</div>
      </div>
      <div style="width:1px;height:36px;background:var(--border)"></div>
      <div style="text-align:center;min-width:60px">
        <div style="font-size:16px;font-weight:700;color:var(--text)">${lg.cap_min}–${lg.cap_max}</div>
        <div style="font-size:10px;color:var(--text-3);font-weight:500;margin-top:1px">CAP Range</div>
      </div>
    </div>
    <button class="btn-orange" onclick="saveLeagueSettings()" style="align-self:flex-start">
      <i class="bi bi-save2 me-1"></i>Salvar
    </button>
  </div>

  <hr style="border-color:var(--border);margin:0 0 20px">

  <div style="font-size:12px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.1em;margin-bottom:12px">Regras e Limites</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:24px">
    <div>
      <div style="font-size:11px;font-weight:600;color:var(--text-2);margin-bottom:6px">CAP Mínimo</div>
      <input type="number" class="form-control" value="${lg.cap_min}" data-league="${lg.league}" data-field="cap_min" />
    </div>
    <div>
      <div style="font-size:11px;font-weight:600;color:var(--text-2);margin-bottom:6px">CAP Máximo</div>
      <input type="number" class="form-control" value="${lg.cap_max}" data-league="${lg.league}" data-field="cap_max" />
    </div>
    <div>
      <div style="font-size:11px;font-weight:600;color:var(--text-2);margin-bottom:6px">Máx. Trocas/Temp.</div>
      <input type="number" class="form-control" value="${lg.max_trades || 3}" data-league="${lg.league}" data-field="max_trades" />
    </div>
  </div>
  <div style="margin-bottom:24px">
    <div style="font-size:11px;font-weight:600;color:var(--text-2);margin-bottom:6px"><i class="bi bi-webhook me-1"></i>Webhook N8N (trades 80+)</div>
    <input type="text" class="form-control" placeholder="https://n8n.exemplo.com/webhook/..." value="${lg.n8n_webhook_url || ''}" data-league="${lg.league}" data-field="n8n_webhook_url" />
    <div style="font-size:11px;color:var(--text-3);margin-top:4px">Disparado automaticamente quando uma trade com jogador OVR 80+ for aceita nesta liga.</div>
  </div>

  <div style="font-size:12px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.1em;margin-bottom:12px">Status da Liga</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:24px">
    <div style="background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:16px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
        <div style="width:32px;height:32px;border-radius:9px;background:rgba(59,130,246,.12);display:flex;align-items:center;justify-content:center">
          <i class="bi bi-arrow-left-right" style="color:#3b82f6;font-size:14px"></i>
        </div>
        <span style="font-size:13px;font-weight:600;color:var(--text)">Trades</span>
        <span style="margin-left:auto;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;${(lg.trades_enabled ?? 1) == 1 ? 'background:rgba(37,198,119,.15);color:#25c677;border:1px solid rgba(37,198,119,.25)' : 'background:rgba(252,0,37,.12);color:var(--red);border:1px solid var(--border-red)'}">${(lg.trades_enabled ?? 1) == 1 ? 'Ativas' : 'Bloqueadas'}</span>
      </div>
      <div style="display:flex;gap:6px">
        <button class="btn ${(lg.trades_enabled ?? 1) == 1 ? 'btn-success' : 'btn-outline-success'} flex-grow-1"
          style="font-size:12px;padding:7px 10px"
          onclick="toggleTrades('${lg.league}', 1)" id="tradesOnBtn_${lg.league}">
          Ativas
        </button>
        <button class="btn ${(lg.trades_enabled ?? 1) == 0 ? 'btn-danger' : 'btn-outline-danger'} flex-grow-1"
          style="font-size:12px;padding:7px 10px"
          onclick="toggleTrades('${lg.league}', 0)" id="tradesOffBtn_${lg.league}">
          Bloqueadas
        </button>
      </div>
    </div>
    <div style="background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:16px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
        <div style="width:32px;height:32px;border-radius:9px;background:rgba(34,197,94,.12);display:flex;align-items:center;justify-content:center">
          <i class="bi bi-coin" style="color:#22c55e;font-size:14px"></i>
        </div>
        <span style="font-size:13px;font-weight:600;color:var(--text)">Free Agency</span>
        <span style="margin-left:auto;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;${(lg.fa_enabled ?? 1) == 1 ? 'background:rgba(37,198,119,.15);color:#25c677;border:1px solid rgba(37,198,119,.25)' : 'background:rgba(252,0,37,.12);color:var(--red);border:1px solid var(--border-red)'}">${(lg.fa_enabled ?? 1) == 1 ? 'Ativa' : 'Bloqueada'}</span>
      </div>
      <div style="display:flex;gap:6px">
        <button class="btn ${(lg.fa_enabled ?? 1) == 1 ? 'btn-success' : 'btn-outline-success'} flex-grow-1"
          style="font-size:12px;padding:7px 10px"
          onclick="toggleFA('${lg.league}', 1)" id="faOnBtn_${lg.league}">
          Ativa
        </button>
        <button class="btn ${(lg.fa_enabled ?? 1) == 0 ? 'btn-danger' : 'btn-outline-danger'} flex-grow-1"
          style="font-size:12px;padding:7px 10px"
          onclick="toggleFA('${lg.league}', 0)" id="faOffBtn_${lg.league}">
          Bloqueada
        </button>
      </div>
    </div>
  </div>

  <div style="font-size:12px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.1em;margin-bottom:12px">Edital da Liga</div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="file" class="form-control" id="edital_file_${lg.league}" accept=".pdf,.doc,.docx" style="flex:1;min-width:180px" />
    <button class="btn-orange" onclick="uploadEdital('${lg.league}')"><i class="bi bi-upload me-1"></i>Upload</button>
  </div>
  ${lg.edital_file ? `<div style="display:flex;align-items:center;gap:10px;margin-top:10px;padding:10px 12px;background:rgba(37,198,119,.08);border:1px solid rgba(37,198,119,.2);border-radius:10px">
    <i class="bi bi-file-earmark-check" style="color:#25c677;font-size:16px"></i>
    <span style="font-size:12px;color:#25c677;flex:1">${lg.edital_file}</span>
    <a href="/api/edital.php?action=download_edital&league=${lg.league}" class="btn btn-sm btn-outline-light" download target="_blank"><i class="bi bi-download me-1"></i>Baixar</a>
    <button class="btn btn-sm btn-outline-danger" onclick="deleteEdital('${lg.league}')"><i class="bi bi-trash"></i></button>
  </div>` : `<div style="font-size:12px;color:var(--text-3);margin-top:8px"><i class="bi bi-info-circle me-1"></i>Nenhum arquivo enviado</div>`}

</div>`).join('');
  } catch (e) {}
}

async function saveLeagueSettings() {
  const inputs = document.querySelectorAll('#configContainer input[data-league], #configContainer textarea[data-league]');
  const groups = {};
  inputs.forEach(inp => {
    const lg = inp.dataset.league;
    groups[lg] = groups[lg] || { league: lg };
    const value = (inp.dataset.field === 'edital' || inp.dataset.field === 'n8n_webhook_url') ? inp.value : parseInt(inp.value);
    groups[lg][inp.dataset.field] = value;
  });
  
  const btn = document.getElementById('saveConfigBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';
  
  try {
    await Promise.all(Object.values(groups).map(e => api('admin.php?action=league_settings', { method: 'PUT', body: JSON.stringify(e) })));
    btn.classList.add('btn-success');
    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Salvo!';
    setTimeout(() => {
      btn.classList.remove('btn-success');
      btn.innerHTML = '<i class="bi bi-save2 me-1"></i>Salvar';
      btn.disabled = false;
    }, 2000);
  } catch (e) {
    alert('Erro ao salvar');
    btn.innerHTML = '<i class="bi bi-save2 me-1"></i>Salvar';
    btn.disabled = false;
  }
}

async function _loadLeagueConfigInline(league) {
  const section = document.getElementById('leagueConfigInline');
  const body = document.getElementById('leagueConfigInlineBody');
  if (!section || !body) return;
  try {
    const data = await api('admin.php?action=leagues');
    const lg = (data.leagues || []).find(l => l.league === league);
    if (!lg) return;
    section.style.display = '';
    const tradesOn = (lg.trades_enabled ?? 1) == 1;
    const faOn = (lg.fa_enabled ?? 1) == 1;
    const badgeStyle = (on) => on
      ? 'background:rgba(37,198,119,.15);color:#25c677;border:1px solid rgba(37,198,119,.25)'
      : 'background:rgba(252,0,37,.12);color:var(--red);border:1px solid var(--border-red)';
    body.innerHTML = `
      <div style="display:flex;align-items:flex-end;flex-wrap:wrap;gap:12px">
        <div style="display:flex;flex-direction:column;gap:4px">
          <div style="font-size:11px;font-weight:600;color:var(--text-2)">CAP Mínimo</div>
          <input type="number" class="form-control form-control-sm" style="width:90px" value="${lg.cap_min}" data-league="${lg.league}" data-field="cap_min">
        </div>
        <div style="display:flex;flex-direction:column;gap:4px">
          <div style="font-size:11px;font-weight:600;color:var(--text-2)">CAP Máximo</div>
          <input type="number" class="form-control form-control-sm" style="width:90px" value="${lg.cap_max}" data-league="${lg.league}" data-field="cap_max">
        </div>
        <div style="display:flex;flex-direction:column;gap:4px">
          <div style="font-size:11px;font-weight:600;color:var(--text-2)">Máx. Trocas/Temp.</div>
          <input type="number" class="form-control form-control-sm" style="width:90px" value="${lg.max_trades || 3}" data-league="${lg.league}" data-field="max_trades">
        </div>
        <div style="display:flex;flex-direction:column;gap:4px;flex:1;min-width:180px">
          <div style="font-size:11px;font-weight:600;color:var(--text-2)"><i class="bi bi-webhook me-1"></i>Webhook N8N</div>
          <input type="text" class="form-control form-control-sm" placeholder="https://n8n.exemplo.com/webhook/..." value="${lg.n8n_webhook_url || ''}" data-league="${lg.league}" data-field="n8n_webhook_url">
        </div>
        <div style="background:var(--red-soft);border:1px solid var(--border-red);border-radius:10px;padding:7px 12px;text-align:center;min-width:80px">
          <div style="font-size:13px;font-weight:700;color:var(--red)">${lg.cap_min}–${lg.cap_max}</div>
          <div style="font-size:10px;color:var(--text-3)">CAP Range</div>
        </div>

        <div style="width:1px;height:36px;background:var(--border);flex-shrink:0"></div>

        <div style="display:flex;align-items:center;gap:7px;background:var(--panel-3);border:1px solid var(--border);border-radius:10px;padding:8px 12px">
          <i class="bi bi-arrow-left-right" style="color:#3b82f6;font-size:13px;flex-shrink:0"></i>
          <span style="font-size:12px;font-weight:600;color:var(--text);white-space:nowrap">Trades</span>
          <span id="tradesBadge_${lg.league}" style="font-size:10px;font-weight:700;padding:1px 7px;border-radius:999px;white-space:nowrap;${badgeStyle(tradesOn)}">${tradesOn ? 'Ativas' : 'Bloqueadas'}</span>
          <div style="display:flex;gap:4px;margin-left:4px">
            <button class="btn btn-sm ${tradesOn ? 'btn-success' : 'btn-outline-success'}"
              style="font-size:11px;padding:4px 10px"
              onclick="toggleTrades('${lg.league}', 1)" id="tradesOnBtn_${lg.league}">Ativas</button>
            <button class="btn btn-sm ${!tradesOn ? 'btn-danger' : 'btn-outline-danger'}"
              style="font-size:11px;padding:4px 10px"
              onclick="toggleTrades('${lg.league}', 0)" id="tradesOffBtn_${lg.league}">Bloqueadas</button>
          </div>
        </div>

        <div style="display:flex;align-items:center;gap:7px;background:var(--panel-3);border:1px solid var(--border);border-radius:10px;padding:8px 12px">
          <i class="bi bi-coin" style="color:#22c55e;font-size:13px;flex-shrink:0"></i>
          <span style="font-size:12px;font-weight:600;color:var(--text);white-space:nowrap">Free Agency</span>
          <span id="faBadge_${lg.league}" style="font-size:10px;font-weight:700;padding:1px 7px;border-radius:999px;white-space:nowrap;${badgeStyle(faOn)}">${faOn ? 'Ativa' : 'Bloqueada'}</span>
          <div style="display:flex;gap:4px;margin-left:4px">
            <button class="btn btn-sm ${faOn ? 'btn-success' : 'btn-outline-success'}"
              style="font-size:11px;padding:4px 10px"
              onclick="toggleFA('${lg.league}', 1)" id="faOnBtn_${lg.league}">Ativa</button>
            <button class="btn btn-sm ${!faOn ? 'btn-danger' : 'btn-outline-danger'}"
              style="font-size:11px;padding:4px 10px"
              onclick="toggleFA('${lg.league}', 0)" id="faOffBtn_${lg.league}">Bloqueada</button>
          </div>
        </div>
      </div>`;
    document.getElementById('saveConfigInlineBtn')?.addEventListener('click', async () => {
      const inputs = body.querySelectorAll('input[data-league]');
      const payload = { league };
      inputs.forEach(inp => { payload[inp.dataset.field] = inp.type === 'number' ? parseInt(inp.value) : inp.value; });
      const btn = document.getElementById('saveConfigInlineBtn');
      if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; }
      try {
        await api('admin.php?action=league_settings', { method: 'PUT', body: JSON.stringify(payload) });
        showAlert('success', 'Configurações salvas!');
      } catch (e) { alert(e.error || 'Erro ao salvar'); }
      finally { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-save2 me-1"></i>Salvar'; } }
    });
  } catch (e) {}
}

function editTeam(teamId) {
  const t = appState.currentTeam;
  if (!t || t.id != teamId) return;
  
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Editar Time</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="mb-3"><label class="form-label text-light-gray">Cidade</label>
<input type="text" class="form-control bg-dark text-white border-orange" id="editTeamCity" value="${t.city}"></div>
<div class="mb-3"><label class="form-label text-light-gray">Nome</label>
<input type="text" class="form-control bg-dark text-white border-orange" id="editTeamName" value="${t.name}"></div>
<div class="mb-3"><label class="form-label text-light-gray">Conferência</label>
<select class="form-select bg-dark text-white border-orange" id="editTeamConference">
<option value="">Sem conferência</option><option value="LESTE" ${t.conference === 'LESTE' ? 'selected' : ''}>LESTE</option>
<option value="OESTE" ${t.conference === 'OESTE' ? 'selected' : ''}>OESTE</option></select></div></div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="saveTeamEdit(${teamId})">Salvar</button></div></div></div>`;
  
  document.body.appendChild(modal);
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function saveTeamEdit(teamId) {
  try {
    await api('admin.php?action=team', {
      method: 'PUT',
      body: JSON.stringify({
        team_id: teamId,
        city: document.getElementById('editTeamCity').value,
        name: document.getElementById('editTeamName').value,
        conference: document.getElementById('editTeamConference').value
      })
    });
    bootstrap.Modal.getInstance(document.querySelector('.modal')).hide();
    await showTeam(teamId);
    alert('Atualizado!');
  } catch (e) { alert('Erro'); }
}

function editPlayer(playerId) {
  const p = appState.teamDetails.players.find(p => p.id == playerId);
  if (!p) return;
  
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Editar ${p.name}</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="mb-3"><label class="form-label text-light-gray">Posição</label>
<input type="text" class="form-control bg-dark text-white border-orange" id="editPlayerPosition" value="${p.position}"></div>
<div class="row">
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">Pos. Secundária</label>
<select class="form-select bg-dark text-white border-orange" id="editPlayerSecondaryPosition">
<option value="" ${!p.secondary_position ? 'selected' : ''}>Sem</option>
<option value="PG" ${p.secondary_position === 'PG' ? 'selected' : ''}>PG</option>
<option value="SG" ${p.secondary_position === 'SG' ? 'selected' : ''}>SG</option>
<option value="SF" ${p.secondary_position === 'SF' ? 'selected' : ''}>SF</option>
<option value="PF" ${p.secondary_position === 'PF' ? 'selected' : ''}>PF</option>
<option value="C" ${p.secondary_position === 'C' ? 'selected' : ''}>C</option>
</select></div>
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">Idade</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="editPlayerAge" value="${p.age || ''}" min="16" max="60"></div>
</div>
<div class="mb-3"><label class="form-label text-light-gray">OVR</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="editPlayerOvr" value="${p.ovr}" min="0" max="99"></div>
<div class="mb-3"><label class="form-label text-light-gray">Papel</label>
<select class="form-select bg-dark text-white border-orange" id="editPlayerRole">
<option value="Titular" ${p.role === 'Titular' ? 'selected' : ''}>Titular</option>
<option value="Banco" ${p.role === 'Banco' ? 'selected' : ''}>Banco</option>
<option value="Outro" ${p.role === 'Outro' ? 'selected' : ''}>Outro</option>
<option value="G-League" ${p.role === 'G-League' ? 'selected' : ''}>G-League</option></select></div>
${appState.currentTeam.league === 'RISE' ? `<div class="mb-3 p-3 rounded" style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25)">
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" role="switch" id="editPlayerFranchise" ${Number(p.is_franchise_player) === 1 ? 'checked' : ''}>
<label class="form-check-label" for="editPlayerFranchise" style="color:#f59e0b;font-weight:600">🏆 Elegível Restricted CAP</label></div>
<small class="text-light-gray d-block mt-1">Override manual: marca o jogador como elegível para bônus de CAP independente das regras automáticas.</small></div>` : ''}
<div class="mb-3"><label class="form-label text-light-gray">Transferir</label>
<select class="form-select bg-dark text-white border-orange" id="editPlayerTeam"><option value="">Manter no time</option></select></div></div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="savePlayerEdit(${playerId})">Salvar</button></div></div></div>`;
  
  document.body.appendChild(modal);
  api('admin.php?action=teams').then(data => {
    const select = modal.querySelector('#editPlayerTeam');
    const currentLeague = appState.currentTeam.league;
    data.teams.forEach(t => {
      // Apenas times da mesma liga, exceto o time atual
      if (t.id != appState.currentTeam.id && t.league === currentLeague) {
        const opt = document.createElement('option');
        opt.value = t.id;
        opt.textContent = `${t.city} ${t.name}`;
        select.appendChild(opt);
      }
    });
  });
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function savePlayerEdit(playerId) {
  const secondaryPos = document.getElementById('editPlayerSecondaryPosition')?.value;
  const data = {
    player_id: playerId,
    position: document.getElementById('editPlayerPosition').value,
    secondary_position: (secondaryPos !== undefined) ? (secondaryPos || null) : undefined,
    ovr: parseInt(document.getElementById('editPlayerOvr').value, 10),
    role: document.getElementById('editPlayerRole').value,
    is_franchise_player: document.getElementById('editPlayerFranchise')?.checked ? 1 : 0
  };
  const ageVal = parseInt(document.getElementById('editPlayerAge')?.value || '', 10);
  if (!Number.isNaN(ageVal)) data.age = ageVal;
  if (data.secondary_position === undefined) delete data.secondary_position;

  const teamId = document.getElementById('editPlayerTeam').value;
  if (teamId) data.team_id = parseInt(teamId, 10) || teamId;

  try {
    await api('admin.php?action=player', { method: 'PUT', body: JSON.stringify(data) });
    const modalEl = document.querySelector('.modal.show') || document.querySelector('.modal');
    bootstrap.Modal.getInstance(modalEl)?.hide();
    await showTeam(appState.currentTeam.id);
    showAlert('success', 'Jogador atualizado!');
  } catch (e) {
    showAlert('danger', 'Erro ao salvar: ' + (e.error || e.message || 'Erro desconhecido'));
  }
}

async function deletePlayer(playerId) {
  if (!confirm('Deletar jogador?')) return;
  try {
    await api(`admin.php?action=player&id=${playerId}`, { method: 'DELETE' });
    await showTeam(appState.currentTeam.id);
    alert('Deletado!');
  } catch (e) { alert('Erro'); }
}

async function cancelTrade(tradeId) {
  if (!confirm('Cancelar trade?')) return;
  try {
    await api('admin.php?action=cancel_trade', { method: 'PUT', body: JSON.stringify({ trade_id: tradeId }) });
    await showTrades();
    alert('Cancelada!');
  } catch (e) { alert('Erro'); }
}

async function revertTrade(tradeId) {
  if (!confirm('REVERTER trade? Jogadores voltarão aos times originais.')) return;
  try {
    await api('admin.php?action=revert_trade', { method: 'PUT', body: JSON.stringify({ trade_id: tradeId }) });
    await showTrades();
    alert('Revertida!');
  } catch (e) { alert('Erro'); }
}

async function revertMultiTrade(tradeId) {
  if (!confirm('REVERTER trade múltipla? Itens voltarão aos times originais.')) return;
  try {
    await api('admin.php?action=revert_multi_trade', { method: 'PUT', body: JSON.stringify({ trade_id: tradeId }) });
    await showTrades();
    alert('Revertida!');
  } catch (e) { alert('Erro'); }
}

function addPlayer(teamId) {
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.id = 'addPlayerModal';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Adicionar Jogador</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<div class="mb-3"><label class="form-label text-light-gray">Nome</label>
<input type="text" class="form-control bg-dark text-white border-orange" id="addPlayerName" placeholder="Nome completo do jogador"></div>
<div class="row">
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">Posição</label>
<select class="form-select bg-dark text-white border-orange" id="addPlayerPosition">
<option value="PG">PG</option>
<option value="SG">SG</option>
<option value="SF">SF</option>
<option value="PF">PF</option>
<option value="C">C</option>
</select></div>
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">Pos. Secundária</label>
<select class="form-select bg-dark text-white border-orange" id="addPlayerSecondaryPosition">
<option value="">Nenhuma</option>
<option value="PG">PG</option>
<option value="SG">SG</option>
<option value="SF">SF</option>
<option value="PF">PF</option>
<option value="C">C</option>
</select></div>
</div>
<div class="row">
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">Idade</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="addPlayerAge" value="25" min="18" max="45"></div>
<div class="col-md-6 mb-3"><label class="form-label text-light-gray">OVR</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="addPlayerOvr" value="70" min="0" max="99"></div>
</div>
<div class="mb-3"><label class="form-label text-light-gray">Papel</label>
<select class="form-select bg-dark text-white border-orange" id="addPlayerRole">
<option value="Titular">Titular</option>
<option value="Banco" selected>Banco</option>
<option value="Outro">Outro</option>
<option value="G-League">G-League</option></select></div>
</div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="saveNewPlayer(${teamId})">Adicionar</button></div></div></div>`;

  document.body.appendChild(modal);
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function saveNewPlayer(teamId) {
  const data = {
    team_id: teamId,
    name: document.getElementById('addPlayerName').value.trim(),
    position: document.getElementById('addPlayerPosition').value,
    secondary_position: document.getElementById('addPlayerSecondaryPosition').value || null,
    age: parseInt(document.getElementById('addPlayerAge').value),
    ovr: parseInt(document.getElementById('addPlayerOvr').value),
    role: document.getElementById('addPlayerRole').value
  };
  
  if (!data.name || !data.position) {
    alert('Nome e posição são obrigatórios!');
    return;
  }
  
  try {
    await api('admin.php?action=player', { method: 'POST', body: JSON.stringify(data) });
    const modalEl = document.getElementById('addPlayerModal');
    if (modalEl) bootstrap.Modal.getInstance(modalEl)?.hide();
    await showTeam(teamId);
    alert('Jogador adicionado!');
  } catch (e) {
    alert('Erro ao adicionar jogador: ' + (e.error || 'Desconhecido'));
  }
}

function addPick(teamId) {
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Adicionar Pick</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<div class="mb-3"><label class="form-label text-light-gray">Temporada</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="addPickYear" value="${new Date().getFullYear()}" min="2025"></div>
<div class="mb-3"><label class="form-label text-light-gray">Rodada</label>
<select class="form-select bg-dark text-white border-orange" id="addPickRound">
<option value="1">1ª Rodada</option>
<option value="2">2ª Rodada</option></select></div>
<div class="mb-3"><label class="form-label text-light-gray">Time Original</label>
<select class="form-select bg-dark text-white border-orange" id="addPickOriginalTeam">
<option value="">Carregando...</option></select></div>
<div class="mb-3"><label class="form-label text-light-gray">Observações (opcional)</label>
<textarea class="form-control bg-dark text-white border-orange" id="addPickNotes" rows="2" placeholder="Informações adicionais sobre este pick"></textarea></div>
</div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="saveNewPick(${teamId})">Adicionar</button></div></div></div>`;
  
  document.body.appendChild(modal);
  
  // Carregar times para seleção
  api('admin.php?action=teams').then(data => {
    const select = modal.querySelector('#addPickOriginalTeam');
    select.innerHTML = '<option value="">Selecione o time original</option>';
    data.teams.forEach(t => {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = `${t.city} ${t.name} (${t.league})`;
      if (t.id == teamId) opt.selected = true;
      select.appendChild(opt);
    });
  });
  
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function saveNewPick(teamId) {
  const data = {
    team_id: teamId,
    original_team_id: parseInt(document.getElementById('addPickOriginalTeam').value),
    season_year: parseInt(document.getElementById('addPickYear').value),
    round: document.getElementById('addPickRound').value,
    notes: document.getElementById('addPickNotes').value.trim() || null
  };
  
  if (!data.original_team_id) {
    alert('Selecione o time original!');
    return;
  }
  
  try {
    await api('admin.php?action=pick', { method: 'POST', body: JSON.stringify(data) });
    bootstrap.Modal.getInstance(document.querySelector('.modal')).hide();
    await showTeam(teamId);
    alert('Pick adicionado!');
  } catch (e) { 
    alert('Erro ao adicionar pick: ' + (e.error || 'Desconhecido')); 
  }
}

function editPick(pickId) {
  const p = appState.teamDetails.picks.find(pk => pk.id == pickId);
  if (!p) return;

  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Editar Pick</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<div class="mb-3"><label class="form-label text-light-gray">Temporada</label>
<input type="number" class="form-control bg-dark text-white border-orange" id="editPickYear" value="${p.season_year}" min="2025"></div>
<div class="mb-3"><label class="form-label text-light-gray">Rodada</label>
<select class="form-select bg-dark text-white border-orange" id="editPickRound">
<option value="1" ${p.round == 1 ? 'selected' : ''}>1ª Rodada</option>
<option value="2" ${p.round == 2 ? 'selected' : ''}>2ª Rodada</option></select></div>
<div class="mb-3"><label class="form-label text-light-gray">Time Original (da pick)</label>
<select class="form-select bg-dark text-white border-orange" id="editPickOriginalTeam">
<option value="">Carregando...</option></select></div>
<div class="mb-3"><label class="form-label text-light-gray">Dono atual — mover pick</label>
<select class="form-select bg-dark text-white border-orange" id="editPickOwnerTeam">
<option value="">Carregando...</option></select></div>
<div class="mb-3"><label class="form-label text-light-gray">Tipo de Swap</label>
<select class="form-select bg-dark text-white border-orange" id="editPickSwapType">
<option value="" ${!p.swap_type ? 'selected' : ''}>Nenhum</option>
<option value="SW" ${p.swap_type === 'SW' ? 'selected' : ''}>SW — Worst</option>
<option value="SB" ${p.swap_type === 'SB' ? 'selected' : ''}>SB — Best</option>
</select></div>
<div class="mb-3"><label class="form-label text-light-gray">Observações (opcional)</label>
<textarea class="form-control bg-dark text-white border-orange" id="editPickNotes" rows="2">${p.notes || ''}</textarea></div>
</div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="savePickEdit(${pickId})">Salvar</button></div></div></div>`;

  document.body.appendChild(modal);

  api('admin.php?action=teams').then(data => {
    const origSelect = modal.querySelector('#editPickOriginalTeam');
    const ownerSelect = modal.querySelector('#editPickOwnerTeam');
    origSelect.innerHTML = '';
    ownerSelect.innerHTML = '';
    data.teams.forEach(t => {
      const opt1 = document.createElement('option');
      opt1.value = t.id;
      opt1.textContent = `${t.city} ${t.name} (${t.league})`;
      if (t.id == p.original_team_id) opt1.selected = true;
      origSelect.appendChild(opt1);

      const opt2 = opt1.cloneNode(true);
      if (t.id == p.team_id) opt2.selected = true;
      ownerSelect.appendChild(opt2);
    });
  });

  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function savePickEdit(pickId) {
  const ownerTeamId = parseInt(document.getElementById('editPickOwnerTeam')?.value || 0);
  const swapType = document.getElementById('editPickSwapType')?.value || null;
  const data = {
    pick_id: pickId,
    team_id: ownerTeamId || appState.currentTeam.id,
    original_team_id: parseInt(document.getElementById('editPickOriginalTeam').value),
    season_year: parseInt(document.getElementById('editPickYear').value),
    round: document.getElementById('editPickRound').value,
    swap_type: swapType || null,
    notes: document.getElementById('editPickNotes').value.trim() || null
  };

  if (!data.original_team_id) {
    alert('Selecione o time original!');
    return;
  }

  try {
    await api('admin.php?action=pick', { method: 'PUT', body: JSON.stringify(data) });
    bootstrap.Modal.getInstance(document.querySelector('.modal.show'))?.hide();
    await showTeam(appState.currentTeam.id);
    showAlert('success', 'Pick atualizado!');
  } catch (e) {
    alert('Erro ao atualizar pick: ' + (e.error || 'Desconhecido'));
  }
}

async function deletePick(pickId) {
  if (!confirm('Deletar este pick?')) return;
  try {
    await api(`admin.php?action=pick&id=${pickId}`, { method: 'DELETE' });
    await showTeam(appState.currentTeam.id);
    showAlert('success', 'Pick deletado!');
  } catch (e) { alert('Erro ao deletar pick!'); }
}

function quickSwapType(pickId) {
  const p = appState.teamDetails.picks.find(pk => pk.id == pickId);
  if (!p) return;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog modal-sm"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white" style="font-size:14px">Swap — ${p.season_year} R${p.round}</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<div class="d-flex flex-column gap-2">
<button type="button" class="btn ${!p.swap_type ? 'btn-orange' : 'btn-secondary'}" onclick="applySwapType(${pickId}, '')">Nenhum</button>
<button type="button" class="btn ${p.swap_type === 'SW' ? 'btn-orange' : 'btn-outline-light'}" onclick="applySwapType(${pickId}, 'SW')">SW — Worst</button>
<button type="button" class="btn ${p.swap_type === 'SB' ? 'btn-orange' : 'btn-outline-light'}" onclick="applySwapType(${pickId}, 'SB')">SB — Best</button>
</div></div></div></div>`;
  document.body.appendChild(modal);
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function applySwapType(pickId, swapType) {
  const p = appState.teamDetails.picks.find(pk => pk.id == pickId);
  if (!p) return;
  try {
    await api('admin.php?action=pick', { method: 'PUT', body: JSON.stringify({
      pick_id: pickId,
      team_id: p.team_id,
      original_team_id: p.original_team_id,
      season_year: p.season_year,
      round: p.round,
      swap_type: swapType || null,
      notes: p.notes || null
    })});
    const openModal = document.querySelector('.modal.show');
    if (openModal) bootstrap.Modal.getInstance(openModal)?.hide();
    await showTeam(appState.currentTeam.id);
    showAlert('success', swapType ? `Tipo definido como ${swapType}` : 'Swap type removido');
  } catch (e) {
    alert('Erro: ' + (e.error || 'Desconhecido'));
  }
}

function movePick(pickId) {
  const p = appState.teamDetails.picks.find(pk => pk.id == pickId);
  if (!p) return;
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `<div class="modal-dialog"><div class="modal-content bg-dark-panel"><div class="modal-header border-orange">
<h5 class="modal-title text-white">Mover Pick — ${p.season_year} R${p.round}</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<p style="font-size:13px;color:var(--text-2);margin-bottom:12px">Pick original: <strong>${escapeHtml(p.city || '')} ${escapeHtml(p.team_name || '')}</strong></p>
<div class="mb-3"><label class="form-label text-light-gray">Mover para o time</label>
<select class="form-select bg-dark text-white border-orange" id="movePickDestTeam">
<option value="">Carregando...</option></select></div>
</div>
<div class="modal-footer border-orange">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="applyMovePick(${pickId})">Mover</button>
</div></div></div>`;
  document.body.appendChild(modal);
  api('admin.php?action=teams').then(data => {
    const select = modal.querySelector('#movePickDestTeam');
    select.innerHTML = '';
    const currentLeague = ((appState.currentTeam?.league) || appState.currentLeague || '').toUpperCase();
    const teams = currentLeague
      ? data.teams.filter(t => (t.league || '').toUpperCase() === currentLeague)
      : data.teams;
    teams.forEach(t => {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = `${t.city} ${t.name}`;
      if (t.id == p.team_id) opt.selected = true;
      select.appendChild(opt);
    });
  });
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function applyMovePick(pickId) {
  const p = appState.teamDetails.picks.find(pk => pk.id == pickId);
  if (!p) return;
  const destTeamId = parseInt(document.getElementById('movePickDestTeam').value);
  if (!destTeamId) { alert('Selecione o time destino!'); return; }
  try {
    await api('admin.php?action=pick', { method: 'PUT', body: JSON.stringify({
      pick_id: pickId,
      team_id: destTeamId,
      original_team_id: p.original_team_id,
      season_year: p.season_year,
      round: p.round,
      swap_type: p.swap_type || null,
      notes: p.notes || null
    })});
    const openModal = document.querySelector('.modal.show');
    if (openModal) bootstrap.Modal.getInstance(openModal)?.hide();
    await showTeam(appState.currentTeam.id);
    showAlert('success', 'Pick movida com sucesso!');
  } catch (e) {
    alert('Erro ao mover pick: ' + (e.error || 'Desconhecido'));
  }
}

// Função para upload de edital
async function uploadEdital(league) {
  const fileInput = document.getElementById(`edital_file_${league}`);
  const file = fileInput.files[0];
  
  if (!file) {
    alert('Selecione um arquivo primeiro!');
    return;
  }
  
  // Validação de tipo de arquivo
  const allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
  if (!allowedTypes.includes(file.type)) {
    alert('Apenas arquivos PDF ou Word são permitidos!');
    return;
  }
  
  // Validação de tamanho (10MB)
  if (file.size > 10 * 1024 * 1024) {
    alert('Arquivo muito grande! Máximo: 10MB');
    return;
  }
  
  const formData = new FormData();
  formData.append('file', file);
  formData.append('league', league);
  
  try {
    const response = await fetch('api/edital.php?action=upload_edital', {
      method: 'POST',
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      alert('Edital enviado com sucesso!');
      showConfig(); // Recarrega para mostrar o arquivo
    } else {
      alert('Erro: ' + (result.error || 'Falha no upload'));
    }
  } catch (e) {
    alert('Erro ao enviar arquivo: ' + e.message);
  }
}

// Função para deletar edital
async function deleteEdital(league) {
  if (!confirm('Tem certeza que deseja remover o edital desta liga?')) return;
  
  try {
    const response = await fetch('api/edital.php?action=delete_edital', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ league })
    });
    
    const result = await response.json();
    
    if (result.success) {
      alert('Edital removido!');
      showConfig(); // Recarrega
    } else {
      alert('Erro: ' + (result.error || 'Falha ao remover'));
    }
  } catch (e) {
    alert('Erro ao remover arquivo: ' + e.message);
  }
}

document.addEventListener('DOMContentLoaded', init);

// ========== DIRETRIZES ==========
function formatDeadlineDateTime(value) {
  if (!value) return '-';
  try {
    return new Intl.DateTimeFormat('pt-BR', {
      timeZone: 'America/Sao_Paulo',
      dateStyle: 'short',
      timeStyle: 'short'
    }).format(new Date(value));
  } catch (e) {
    try {
      return new Date(value).toLocaleString('pt-BR');
    } catch (err) {
      return value;
    }
  }
}

function formatDirectiveTimestamp(value) {
  if (!value) return '-';
  try {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '-';
    return date.toLocaleString('pt-BR');
  } catch (e) {
    return '-';
  }
}

function normalizeDirectiveMinutes(raw) {
  if (!raw) return {};
  if (typeof raw === 'string') {
    try {
      raw = JSON.parse(raw);
    } catch (e) {
      return {};
    }
  }
  if (Array.isArray(raw)) {
    return raw.reduce((acc, row) => {
      if (row && row.player_id) acc[row.player_id] = row.minutes_per_game;
      return acc;
    }, {});
  }
  if (typeof raw === 'object') return raw;
  return {};
}

function normalizeDirectivePlayerInfo(raw) {
  if (!raw) return {};
  if (typeof raw === 'string') {
    try {
      raw = JSON.parse(raw);
    } catch (e) {
      return {};
    }
  }
  if (Array.isArray(raw)) {
    return raw.reduce((acc, row) => {
      if (row && row.player_id) {
        acc[row.player_id] = {
          name: row.player_name || row.name || '?',
          position: row.player_position || row.position || '?',
          ovr: row.player_ovr ?? row.ovr ?? null,
          age: row.player_age ?? row.age ?? null
        };
      }
      return acc;
    }, {});
  }
  if (typeof raw === 'object') return raw;
  return {};
}

function filterDirCards(val) {
  const q = (val || '').trim().toLowerCase();
  document.querySelectorAll('.admin-check-card').forEach(card => {
    const name = card.dataset.teamName || '';
    card.style.display = (!q || name.includes(q)) ? '' : 'none';
  });
}

function filterPtsTeam(val) {
  const q = (val || '').trim().toLowerCase();
  document.querySelectorAll('#ptsMgmtContent [data-team-name]').forEach(row => {
    row.style.display = (!q || (row.dataset.teamName || '').includes(q)) ? '' : 'none';
  });
}

async function showDirectives() {
  appState.view = 'directives';
  updateBreadcrumb();

  const _dirLeague = appState.currentLeague || null;
  const _dirBack = _dirLeague ? `showLeague('${_dirLeague}')` : 'showHome()';

  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';

  try {
    const apiUrl = _dirLeague
      ? `diretrizes.php?action=list_deadlines_admin&league=${encodeURIComponent(_dirLeague)}`
      : 'diretrizes.php?action=list_deadlines_admin';
    const data = await api(apiUrl);
    const deadlines = data.deadlines || [];

    container.innerHTML = `
      <div class="mb-4">
        <button class="btn btn-back" onclick="${_dirBack}"><i class="bi bi-arrow-left"></i> Voltar</button>
        <button class="btn btn-orange float-end" onclick="showCreateDeadlineModal()">
          <i class="bi bi-plus-circle me-2"></i>Criar Prazo
        </button>
      </div>
      
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title"><i class="bi bi-calendar-event"></i> Prazos de Diretrizes</div>
        </div>
        ${deadlines.length === 0
          ? '<p class="empty-state">Nenhum prazo configurado.</p>'
          : deadlines.map(d => {
              const isPlayoffs = (d.phase || 'regular') === 'playoffs';
              const phaseColor = isPlayoffs ? '#ef4444' : '#06b6d4';
              const statusColor = d.is_active ? '#22c55e' : 'var(--text-3)';
              return `
              <div class="pun-card mb-2">
                <div class="pun-card-head">
                  <div>
                    <div class="pun-card-title">${escapeHtml(d.description || 'Sem descrição')}</div>
                    <div class="pun-card-sub">${formatDeadlineDateTime(d.deadline_date_iso || d.deadline_date)}</div>
                  </div>
                  <div class="d-flex align-items-center gap-2 flex-shrink-0 flex-wrap">
                    <span class="pun-badge" style="background:${phaseColor}20;color:${phaseColor};border-color:${phaseColor}40">${isPlayoffs ? 'Playoffs' : 'Regular'}</span>
                    <span class="pun-badge" style="background:${statusColor}20;color:${statusColor};border-color:${statusColor}40">${d.is_active ? 'Ativo' : 'Inativo'}</span>
                    <span style="font-size:11px;color:#06b6d4">${d.submissions_count} time(s)</span>
                    <button class="btn-ghost" style="padding:3px 8px;font-size:11px" onclick="viewDirectives(${d.id}, '${d.league}')"><i class="bi bi-eye me-1"></i>Ver</button>
                    <button class="btn-ghost" style="padding:3px 8px;font-size:11px" onclick="toggleDeadlineStatus(${d.id}, ${d.is_active})">
                      <i class="bi bi-toggle-${d.is_active ? 'on' : 'off'}"></i>
                    </button>
                    <button class="btn-ghost" style="padding:3px 8px;font-size:11px;color:#ef4444" onclick="deleteDeadline(${d.id}, '${d.league}')"><i class="bi bi-trash"></i></button>
                  </div>
                </div>
              </div>`;
            }).join('')}
      </div>
    `;
  } catch (e) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar prazos</div>';
  }
}

function showCreateDeadlineModal() {
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.innerHTML = `
    <div class="modal-dialog">
      <div class="modal-content bg-dark-panel border-orange">
        <div class="modal-header border-orange">
          <h5 class="modal-title text-white">Criar Prazo de Diretrizes</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label text-white">Liga</label>
            <select class="form-select bg-dark text-white border-orange" id="deadline-league">
              ${_leagues.map(l => `<option value="${l}"${l === (appState.currentLeague || '') ? ' selected' : ''}>${l}</option>`).join('')}
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label text-white">Data do Prazo</label>
            <input type="date" class="form-control bg-dark text-white border-orange" id="deadline-date" required>
          </div>
          <div class="mb-3">
            <label class="form-label text-white">Horário limite (Horário de São Paulo)</label>
            <input type="time" class="form-control bg-dark text-white border-orange" id="deadline-time" value="23:59" required>
            <small class="text-light-gray">O prazo será salvo considerando o fuso America/Sao_Paulo.</small>
          </div>
          <div class="mb-3">
            <label class="form-label text-white">Descrição</label>
            <input type="text" class="form-control bg-dark text-white border-orange" id="deadline-description" 
                   placeholder="Ex: Diretrizes da Rodada 1">
          </div>
          <div class="mb-3">
            <label class="form-label text-white">Fase</label>
            <select class="form-select bg-dark text-white border-orange" id="deadline-phase">
              <option value="regular" selected>Temporada Regular (máx 40 min)</option>
              <option value="playoffs">Playoffs (máx 45 min)</option>
            </select>
            <small class="text-light-gray">Define o limite máximo de minutagem por jogador no formulário.</small>
          </div>
        </div>
        <div class="modal-footer border-orange">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-orange" onclick="createDeadline()">Criar</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
  const bsModal = new bootstrap.Modal(modal);
  bsModal.show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function createDeadline() {
  const league = document.getElementById('deadline-league').value;
  const date = document.getElementById('deadline-date').value;
  const time = document.getElementById('deadline-time').value;
  const description = document.getElementById('deadline-description').value;
  const phase = document.getElementById('deadline-phase').value;
  
  if (!date) {
    alert('Preencha a data');
    return;
  }
  if (!time) {
    alert('Preencha o horário');
    return;
  }
  
  try {
    await api('diretrizes.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'create_deadline', league, deadline_date: date, deadline_time: time, description, phase })
    });
    alert('Prazo criado com sucesso!');
    const modalEl = document.querySelector('.modal.show') || document.querySelector('.modal');
    if (modalEl) {
      const modalInstance = bootstrap.Modal.getInstance(modalEl);
      if (modalInstance) {
        modalInstance.hide();
      }
    }
    showDirectives();
  } catch (e) {
    alert('Erro ao criar prazo: ' + (e.error || e.message));
  }
}

async function toggleDeadlineStatus(id, currentStatus) {
  try {
    await api('diretrizes.php', {
      method: 'PUT',
      body: JSON.stringify({ id, is_active: currentStatus ? 0 : 1 })
    });
    showDirectives();
  } catch (e) {
    alert('Erro ao atualizar status');
  }
}

async function deleteDeadline(id, league) {
  const confirmMsg = `Tem certeza que deseja excluir este prazo de diretrizes da liga ${league}?\n\nTodas as diretrizes enviadas para este prazo também serão excluídas!`;
  if (!confirm(confirmMsg)) return;
  
  try {
    await api('diretrizes.php', {
      method: 'DELETE',
      body: JSON.stringify({ id })
    });
    alert('Prazo excluído com sucesso!');
    showDirectives();
  } catch (e) {
    alert('Erro ao excluir prazo: ' + (e.error || e.message));
  }
}

async function viewDirectives(deadlineId, league) {
  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';
  
  try {
  const data = await api(`diretrizes.php?action=view_all_directives_admin&deadline_id=${deadlineId}&league=${encodeURIComponent(league)}&debug=1&_ts=${Date.now()}`);
  const directives = Array.isArray(data.directives) ? data.directives.filter(Boolean) : [];
  const fallbackNotice = data.fallback ? '<div class="alert alert-info mb-3">Mostrando diretrizes recentes da liga (prazo sem envios).</div>' : '';
  const debugPayload = JSON.stringify(data || {}, null, 2);
  const debugInfo = `
    <div class="alert alert-warning small mb-3">
      Debug: total=${data.debug?.total_directives ?? 'N/A'} · deadline=${data.debug?.deadline_count ?? 'N/A'} · league=${data.debug?.league_count ?? 'N/A'} · join=${data.debug?.league_join_count ?? 'N/A'} · fallback=${data.fallback ? '1' : '0'}
      <pre class="mt-2 mb-0" style="white-space: pre-wrap;">${debugPayload}</pre>
    </div>
  `;
    
    // Mapear labels para os novos valores
    const gameStyleLabels = {
      'balanced': 'Balanced', 'triangle': 'Triangle', 'grit_grind': 'Grit & Grind',
      'pace_space': 'Pace & Space', 'perimeter_centric': 'Perimeter Centric',
      'post_centric': 'Post Centric', 'seven_seconds': 'Seven Seconds',
      'defense': 'Defense', 'defensive_focus': 'Defensive Focus',
      'franchise_player': 'Franchise Player', 'most_stars': 'Maior nº de Estrelas'
    };
    const offenseStyleLabels = {
      'no_preference': 'No Preference', 'pick_roll': 'Pick & Roll',
      'neutral': 'Neutral Focus', 'play_through_star': 'Play Through Star',
      'get_to_basket': 'Get to Basket', 'get_shooters_open': 'Get Shooters Open', 'feed_post': 'Feed Post'
    };
    const paceLabels = {
      'no_preference': 'No Preference', 'patient': 'Patient', 'average': 'Average', 'shoot_at_will': 'Shoot at Will'
    };
    const defAggrLabels = {
      'physical': 'Physical', 'no_preference': 'No Preference', 'conservative': 'Conservative', 'neutral': 'Neutral'
    };
    const offRebLabels = {
      'limit_transition': 'Limit Transition', 'no_preference': 'No Preference', 
      'crash_glass': 'Crash Offensive Glass', 'some_crash': 'Some Crash, Others Get Back'
    };
    const defRebLabels = {
      'run_transition': 'Run in Transition', 'crash_glass': 'Crash Defensive Glass', 
      'some_crash': 'Some Crash Others Run', 'no_preference': 'No Preference'
    };
    const defFocusLabels = {
      'no_preference': 'No Preference', 'neutral': 'Neutral Defensive Focus',
      'protect_paint': 'Protect the Paint', 'limit_perimeter': 'Limit Perimeter Shots'
    };
    const rotationLabels = { 'manual': 'Manual', 'auto': 'Automática' };
    
    container.innerHTML = `
      <div class="mb-4">
        <button class="btn btn-back" onclick="showDirectives()"><i class="bi bi-arrow-left"></i> Voltar</button>
      </div>
      
      <div class="card bg-dark-panel border-orange">
        <div class="card-header bg-transparent border-orange d-flex align-items-center justify-content-between flex-wrap gap-2">
          <h5 class="text-white mb-0"><i class="bi bi-clipboard-data me-2"></i>Diretrizes Enviadas - Liga ${league}</h5>
          <input type="text" id="dirTeamSearch" class="form-control form-control-sm" style="width:200px;font-size:13px" placeholder="Filtrar por time..." oninput="filterDirCards(this.value)">
        </div>
        <div class="card-body">
          ${fallbackNotice}
          ${directives.length === 0 ? 
            `${debugInfo}${fallbackNotice}<p class="text-light-gray text-center py-4">Nenhuma diretriz enviada ainda</p>` :
            directives.map(d => {
              const submittedAt = d.submitted_at || d.created_at || null;
              const isAccepted = Number(d.admin_accepted || 0) === 1;
              const pm = normalizeDirectiveMinutes(d.player_minutes);
              const playerInfo = normalizeDirectivePlayerInfo(d.player_info);
              const isManualRotation = d.rotation_style === 'manual';
              const prev = d.previous_directive || null;
              const prevPm = prev ? normalizeDirectiveMinutes(prev.player_minutes) : {};
              const hasPrev = !!prev;
              const changedField = (field) => hasPrev && String(prev?.[field] ?? '') !== String(d?.[field] ?? '');
              
              // Coletar IDs dos titulares
              const starterIds = [];
              for (let i = 1; i <= 5; i++) {
                const id = d['starter_' + i + '_id'];
                if (id) starterIds.push(parseInt(id));
              }
              
              const starters = [1,2,3,4,5].map(i => {
                const id = d['starter_' + i + '_id'];
                // Só mostrar minutos se rotação for manual
                const m = isManualRotation && id && pm[id] ? `${pm[id]} min` : '';
                const name = d['starter_' + i + '_name'] || '?';
                const pos = d['starter_' + i + '_pos'] || '?';
                const ovr = d['starter_' + i + '_ovr'] ?? '?';
                const age = d['starter_' + i + '_age'] ?? '?';
                const prevId = prev ? prev['starter_' + i + '_id'] : null;
                const starterChanged = hasPrev && String(prevId ?? '') !== String(id ?? '');
                const minutesChanged = isManualRotation && hasPrev && id && typeof prevPm[id] !== 'undefined' && String(prevPm[id]) !== String(pm[id]);
                const rowClass = (starterChanged || minutesChanged) ? 'text-danger' : '';
                return `<li class="${rowClass}">${name} (${pos}, ${ovr}/${age}y)${m ? ' - ' + m : ''}</li>`;
              }).join('');
              
              // Banco dinâmico: pegar dos player_minutes os que não são titulares
              const benchItems = [];
              const prevStarterIds = [];
              if (hasPrev) {
                for (let i = 1; i <= 5; i++) {
                  const pid = prev['starter_' + i + '_id'];
                  if (pid) prevStarterIds.push(parseInt(pid));
                }
              }
              const prevBenchIds = [];
              if (hasPrev) {
                if (prev && prev.player_minutes && Object.keys(prevPm).length > 0) {
                  Object.keys(prevPm).forEach(playerId => {
                    const id = parseInt(playerId);
                    if (!prevStarterIds.includes(id)) prevBenchIds.push(id);
                  });
                } else {
                  for (let i = 1; i <= 3; i++) {
                    const bid = prev['bench_' + i + '_id'];
                    if (bid) prevBenchIds.push(parseInt(bid));
                  }
                }
              }
              Object.keys(pm).forEach(playerId => {
                const id = parseInt(playerId);
                if (!starterIds.includes(id)) {
                  // Usar player_info para pegar nome e posição
                  let name = '?', pos = '?', ovr = '?', age = '?';
                  if (playerInfo[id]) {
                    name = playerInfo[id].name || '?';
                    pos = playerInfo[id].position || '?';
                    ovr = playerInfo[id].ovr ?? '?';
                    age = playerInfo[id].age ?? '?';
                  } else {
                    // Fallback para bench_X columns (compatibilidade)
                    for (let i = 1; i <= 3; i++) {
                      if (parseInt(d['bench_' + i + '_id']) === id) {
                        name = d['bench_' + i + '_name'] || '?';
                        pos = d['bench_' + i + '_pos'] || '?';
                        ovr = d['bench_' + i + '_ovr'] ?? '?';
                        age = d['bench_' + i + '_age'] ?? '?';
                        break;
                      }
                    }
                  }
                  // Só mostrar minutos se rotação for manual
                  const minLabel = isManualRotation ? ` - ${pm[playerId]} min` : '';
                  const benchChanged = hasPrev && prevBenchIds.length > 0 && !prevBenchIds.includes(id);
                  const minutesChanged = isManualRotation && hasPrev && typeof prevPm[id] !== 'undefined' && String(prevPm[id]) !== String(pm[playerId]);
                  const rowClass = (benchChanged || minutesChanged) ? 'text-danger' : '';
                  benchItems.push(`<li class="${rowClass}">${name} (${pos}, ${ovr}/${age}y)${minLabel}</li>`);
                }
              });
              const bench = benchItems.length > 0 ? benchItems.join('') : '<li class="text-light-gray">Nenhum jogador no banco</li>';
              
              // Jogadores enviados para a G-League
              const gLeaguePlayers = [1, 2].map(i => {
                const id = d[`gleague_${i}_id`];
                if (!id) return null;
                const info = playerInfo[id] || {};
                const name = d[`gleague_${i}_name`] || info.name || '?';
                const pos = d[`gleague_${i}_pos`] || info.position || '?';
                const ovr = d[`gleague_${i}_ovr`] ?? info.ovr ?? '?';
                const age = d[`gleague_${i}_age`] ?? info.age ?? '?';
                return `<li>${name} (${pos}, ${ovr}/${age}y)</li>`;
              }).filter(Boolean);
              const gLeagueList = gLeaguePlayers.length > 0 ? gLeaguePlayers.join('') : '<li class="text-light-gray">Nenhum jogador enviado para a G-League</li>';
              
              const isEliteLeague = ['ELITE', 'NEXT'].includes(String(league || '').toUpperCase());
              const isEliteOnly = String(league || '').toUpperCase() === 'ELITE';
              let technicalModelValue = d.technical_model || null;
              let playbookValue = d.playbook || null;
              if ((!technicalModelValue || !playbookValue) && d.directive_profile) {
                try {
                  const profile = typeof d.directive_profile === 'string'
                    ? JSON.parse(d.directive_profile)
                    : d.directive_profile;
                  if (profile && !technicalModelValue && profile.technical_model) {
                    technicalModelValue = profile.technical_model;
                  }
                  if (profile && !playbookValue && profile.playbook) {
                    playbookValue = profile.playbook;
                  }
                } catch (e) {
                  // ignore JSON parse errors
                }
              }
              const technicalModelLabel = escapeHtml(technicalModelValue || 'Nao informado');
              const playbookLabel = escapeHtml(playbookValue || 'Nao informado');

              return `
              <div class="card bg-dark mb-3 admin-check-card ${isAccepted ? 'is-accepted' : ''}" data-directive-id="${d.id}" data-team-name="${((d.city||'') + ' ' + (d.team_name||'')).toLowerCase()}">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <div>
                    <h6 class="text-white mb-0">${d.city} ${d.team_name}</h6>
                    <small class="text-light-gray">Enviado em ${formatDirectiveTimestamp(submittedAt || d.submitted_at)}</small>
                  </div>
                  <div class="d-flex align-items-center gap-2">
                    <div class="form-check form-switch m-0">
                      <input class="form-check-input" type="checkbox" role="switch" ${isAccepted ? 'checked' : ''} onchange="toggleAdminDirectiveAccept(${d.id}, this.checked)">
                      <label class="form-check-label text-light-gray">Foi pro jogo</label>
                    </div>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteDirective(${d.id}, ${deadlineId}, '${league}')">
                      <i class="bi bi-trash"></i> Excluir
                    </button>
                  </div>
                </div>
                <div class="card-body">
                  <div class="row">
                    <div class="col-md-6">
                      <h6 class="text-orange mb-2">Quinteto Titular</h6>
                      <ul class="text-light-gray small">
                        ${starters}
                      </ul>
                    </div>
                    <div class="col-md-6">
                      <h6 class="text-orange mb-2">Banco (${benchItems.length} jogadores)</h6>
                      <ul class="text-light-gray small">
                        ${bench}
                      </ul>
                    </div>
                    ${isEliteOnly ? `<div class="col-md-6 col-lg-4 mt-3">
                      <h6 class="text-orange mb-2">G-League</h6>
                      <ul class="text-light-gray small">
                        ${gLeagueList}
                      </ul>
                    </div>` : ''}
                    <div class="col-12 mt-3">
                      <h6 class="text-orange">Estilo de Jogo</h6>
                      <div class="row text-light-gray small">
                        <div class="col-md-4">Game Style: ${changedField('game_style') ? `<span class="text-danger">${gameStyleLabels[d.game_style] || d.game_style}</span>` : (gameStyleLabels[d.game_style] || d.game_style)}</div>
                        <div class="col-md-4">Rotação: ${changedField('rotation_style') ? `<span class="text-danger">${rotationLabels[d.rotation_style] || d.rotation_style}</span>` : (rotationLabels[d.rotation_style] || d.rotation_style)}</div>
                      </div>
                    </div>
                    <div class="col-12 mt-3">
                      <h6 class="text-orange">Configurações</h6>
                      <div class="row text-light-gray small">
                        <div class="col-md-4">Offensive Focus: ${changedField('offense_style') ? `<span class="text-danger">${offenseStyleLabels[d.offense_style] || d.offense_style}</span>` : (offenseStyleLabels[d.offense_style] || d.offense_style)}</div>
                        <div class="col-md-4">Offensive Tempo: ${changedField('pace') ? `<span class="text-danger">${paceLabels[d.pace] || d.pace}</span>` : (paceLabels[d.pace] || d.pace)}</div>
                        <div class="col-md-4">Offensive Rebounding: ${changedField('offensive_rebound') ? `<span class="text-danger">${offRebLabels[d.offensive_rebound] || d.offensive_rebound}</span>` : (offRebLabels[d.offensive_rebound] || d.offensive_rebound)}</div>
                        <div class="col-md-4 mt-2">Defensive Focus: ${changedField('defensive_focus') ? `<span class="text-danger">${defFocusLabels[d.defensive_focus] || d.defensive_focus || 'No Preference'}</span>` : (defFocusLabels[d.defensive_focus] || d.defensive_focus || 'No Preference')}</div>
                        <div class="col-md-4 mt-2">Defensive Aggression: ${changedField('offensive_aggression') ? `<span class="text-danger">${defAggrLabels[d.offensive_aggression] || d.offensive_aggression}</span>` : (defAggrLabels[d.offensive_aggression] || d.offensive_aggression)}</div>
                        <div class="col-md-4 mt-2">Defensive Rebounding: ${changedField('defensive_rebound') ? `<span class="text-danger">${defRebLabels[d.defensive_rebound] || d.defensive_rebound}</span>` : (defRebLabels[d.defensive_rebound] || d.defensive_rebound)}</div>
                      </div>
                    </div>
                    ${isEliteLeague ? `<div class="col-12 mt-3">
                      <h6 class="text-orange">Tecnicas</h6>
                      <div class="row text-light-gray small">
                        <div class="col-md-4">Modelo técnico: ${changedField('technical_model') ? `<span class="text-danger">${technicalModelLabel}</span>` : technicalModelLabel}${parseInt(d.technical_model_changed) === 1 ? ' <span class="badge bg-warning text-dark ms-2">ALTERADO</span>' : ''}</div>
                      </div>
                      <div class="text-light-gray small mt-2">Playbook: ${changedField('playbook') ? `<span class="text-danger">${playbookLabel}</span>` : playbookLabel}</div>
                    </div>` : ''}
                    ${isManualRotation ? `<div class="col-12 mt-3">
                      <h6 class="text-orange">Rotação e Foco</h6>
                      <div class="row text-light-gray small">
                        <div class="col-md-6">Jogadores na Rotação: ${changedField('rotation_players') ? `<span class="text-danger">${d.rotation_players || 10}</span>` : (d.rotation_players || 10)}</div>
                        <div class="col-md-6">Foco Veteranos: ${changedField('veteran_focus') ? `<span class="text-danger">${d.veteran_focus || 50}%</span>` : (d.veteran_focus || 50) + '%'}</div>
                      </div>
                    </div>` : ''}
                    ${d.notes ? `<div class="col-12 mt-3"><h6 class="text-orange">Observações</h6><p class="text-light-gray">${changedField('notes') ? `<span class="text-danger">${d.notes}</span>` : d.notes}</p></div>` : ''}
                  </div>
                </div>
              </div>
            `;
            }).join('')
          }
        </div>
      </div>
    `;
  } catch (e) {
    container.innerHTML = `<div class="alert alert-danger">Erro ao carregar diretrizes: ${e.error || e.message || 'Desconhecido'}</div>`;
  }
}

async function toggleAdminDirectiveAccept(directiveId, checked) {
  const card = document.querySelector(`[data-directive-id="${directiveId}"]`);
  try {
    await api('diretrizes.php', {
      method: 'PATCH',
      body: JSON.stringify({ directive_id: directiveId, accepted: checked })
    });
    if (card) card.classList.toggle('is-accepted', checked);
  } catch (e) {
    alert(e.error || 'Erro ao salvar aceite da diretriz');
    // Reverter o checkbox visualmente
    const checkbox = card?.querySelector('input[type="checkbox"]');
    if (checkbox) checkbox.checked = !checked;
  }
}

// Função para excluir diretriz
async function deleteDirective(directiveId, deadlineId, league) {
  if (!confirm('Tem certeza que deseja excluir esta diretriz? O time terá que enviar novamente.')) return;
  
  try {
    await api('diretrizes.php', {
      method: 'DELETE',
      body: JSON.stringify({ action: 'delete_directive', directive_id: directiveId })
    });
    alert('Diretriz excluída com sucesso');
    viewDirectives(deadlineId, league);
  } catch (e) {
    alert(e.error || 'Erro ao excluir diretriz');
  }
}

// ========== FREE AGENCY ADMIN ==========

async function showPunicoes() {
  appState.view = 'punicoes';
  updateBreadcrumb();

  const league = appState.currentLeague || _leagues[0] || 'ELITE';
  const container = document.getElementById('mainContainer');
  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back" onclick="showLeague('${league}')"><i class="bi bi-arrow-left"></i> Voltar</button>
    </div>

    <div class="row g-4">
      <div class="col-lg-4">

        <div class="panel">
          <div class="panel-header">
            <div class="panel-title" style="margin-bottom:0"><i class="bi bi-plus-circle-fill"></i> Nova punição</div>
          </div>
          <div class="d-flex flex-column gap-3 mt-1">
            <div>
              <div class="pun-field-label">Motivo</div>
              <select id="punicaoMotive" class="form-select"></select>
            </div>
            <input type="hidden" id="punicaoLeague" value="${league}">
            <div>
              <div class="pun-field-label">Time</div>
              <select id="punicaoTeam" class="form-select"></select>
            </div>
            <div>
              <div class="pun-field-label">Consequência</div>
              <select id="punicaoType" class="form-select"></select>
            </div>
            <div id="punicaoPickRow" style="display:none">
              <div class="pun-field-label">Pick específica</div>
              <select id="punicaoPick" class="form-select"></select>
            </div>
            <div id="punicaoScopeRow" style="display:none">
              <div class="pun-field-label">Temporada</div>
              <select id="punicaoScope" class="form-select">
                <option value="current">Temporada atual</option>
                <option value="next">Próxima temporada</option>
              </select>
            </div>
            <div>
              <div class="pun-field-label">Observações</div>
              <textarea id="punicaoNotes" class="form-control" rows="3" placeholder="Detalhes ou contexto..."></textarea>
            </div>
            <div>
              <div class="pun-field-label">Data da punição</div>
              <input type="datetime-local" id="punicaoDate" class="form-control">
            </div>
            <button id="punicaoSubmit" class="btn-orange" style="width:100%;justify-content:center;padding:10px">
              <i class="bi bi-check2-circle"></i> Registrar punição
            </button>
          </div>
        </div>

        <div class="panel">
          <div class="panel-header">
            <div class="panel-title" style="margin-bottom:0"><i class="bi bi-tag-fill"></i> Cadastrar motivo</div>
          </div>
          <div class="d-flex flex-column gap-3 mt-1">
            <div>
              <div class="pun-field-label">Novo motivo</div>
              <input type="text" id="newMotiveLabel" class="form-control" placeholder="Ex: Diretrizes erradas">
            </div>
            <button class="btn-ghost" style="width:100%;justify-content:center" id="newMotiveBtn">
              <i class="bi bi-plus-circle"></i> Salvar motivo
            </button>
          </div>
        </div>

        <div class="panel">
          <div class="panel-header">
            <div class="panel-title" style="margin-bottom:0"><i class="bi bi-lightning-fill"></i> Cadastrar consequência</div>
          </div>
          <div class="d-flex flex-column gap-3 mt-1">
            <div>
              <div class="pun-field-label">Nova consequência</div>
              <input type="text" id="newPunishmentLabel" class="form-control" placeholder="Ex: Perda de pick específica">
            </div>
            <button class="btn-ghost" style="width:100%;justify-content:center" id="newPunishmentBtn">
              <i class="bi bi-plus-circle"></i> Salvar consequência
            </button>
          </div>
        </div>

      </div>

      <div class="col-lg-8">
        <div class="panel">
          <div class="panel-header">
            <div class="panel-title" style="margin-bottom:0"><i class="bi bi-clock-history"></i> Histórico de punições — ${league}</div>
            <div class="admin-sel">
              <label>Time</label>
              <select id="punicaoHistoryTeam"><option value="">Todos os times</option></select>
            </div>
          </div>
          <input type="hidden" id="punicaoHistoryLeague" value="${league}">
          <div id="punicoesList">
            <p class="empty-state">Selecione uma liga ou time para ver as punições.</p>
          </div>
        </div>
      </div>
    </div>
  `;

  if (typeof window.initPunicoes === 'function') {
    window.initPunicoes(league);
  }
}

async function showFAAdmin() {
  appState.view = 'faadmin';
  updateBreadcrumb();

  const league = appState.currentLeague || _leagues[0] || 'ELITE';
  const container = document.getElementById('mainContainer');
  const leagueOpts = (_leagues || [league]).map(l =>
    `<option value="${l}" ${l === league ? 'selected' : ''}>${l}</option>`
  ).join('');

  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back" onclick="showLeague('${league}')"><i class="bi bi-arrow-left"></i> Voltar</button>
    </div>

    <div class="panel mb-4">
      <div class="panel-header">
        <div class="panel-title"><i class="bi bi-person-check-fill" style="color:var(--red);margin-right:8px;"></i>Solicitações Free Agency — ${league}</div>
      </div>
      <input type="hidden" id="faNewAdminLeague" value="${league}">
      <div id="faNewAdminRequests"><p class="empty-state">Carregando...</p></div>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div>
          <div class="panel-title"><i class="bi bi-people-fill" style="color:#22c55e;margin-right:8px;"></i>Lances Ganhos por Time</div>
          <div class="panel-sub">Jogadores contratados via Free Agency</div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
          <select id="adminFaLeagueFilter" class="form-select form-select-sm" style="width:auto" onchange="loadAdminFaHistory()">
            <option value="">Todas as ligas</option>
            ${leagueOpts}
          </select>
          <select id="adminFaSeasonFilter" class="form-select form-select-sm" style="width:auto" onchange="loadAdminFaHistory()">
            <option value="">Todas as temp.</option>
          </select>
        </div>
      </div>
      <div id="adminFaHistoryContainer"><p class="empty-state">Carregando...</p></div>
    </div>

    <div class="modal fade" id="modalFaChangeTeam" tabindex="-1">
      <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header"><h5 class="modal-title">Mudar Time</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <input type="hidden" id="faChangeReqId">
            <p id="faChangePlayerName" style="font-weight:600;font-size:14px;margin-bottom:12px"></p>
            <label style="font-size:12px;color:var(--text-2);display:block;margin-bottom:5px">Novo time</label>
            <select id="faChangeNewTeam" class="form-select"></select>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
            <button class="btn btn-warning btn-sm fw-bold" onclick="adminFaChangeTeamConfirm()"><i class="bi bi-arrow-left-right me-1"></i>Confirmar</button>
          </div>
        </div>
      </div>
    </div>
  `;

  if (typeof carregarSolicitacoesNovaFA === 'function') carregarSolicitacoesNovaFA();
  loadAdminFaHistory();
}

async function loadAdminFaHistory() {
  const container = document.getElementById('adminFaHistoryContainer');
  if (!container) return;
  container.innerHTML = '<p class="empty-state">Carregando...</p>';

  const leagueFil = document.getElementById('adminFaLeagueFilter')?.value || '';
  const seasonFil = document.getElementById('adminFaSeasonFilter')?.value || '';

  try {
    const params = new URLSearchParams({ action: 'admin_fa_history' });
    if (leagueFil) params.set('league', leagueFil);
    if (seasonFil) params.set('season_year', seasonFil);

    const data = await fetch(`/api/free-agency.php?${params}`).then(r => r.json());
    if (!data.success) { container.innerHTML = '<p class="empty-state text-danger">Erro ao carregar.</p>'; return; }

    const seasonSel = document.getElementById('adminFaSeasonFilter');
    if (seasonSel && !seasonSel.dataset.loaded && data.seasons?.length) {
      data.seasons.forEach(y => {
        const o = document.createElement('option');
        o.value = y; o.textContent = y;
        seasonSel.appendChild(o);
      });
      seasonSel.dataset.loaded = '1';
    }

    const rows = data.rows || [];
    if (!rows.length) { container.innerHTML = '<p class="empty-state">Nenhum lance ganho encontrado.</p>'; return; }

    const byTeam = {};
    rows.forEach(r => {
      const key = r.team_full_name?.trim() || '—';
      if (!byTeam[key]) byTeam[key] = [];
      byTeam[key].push(r);
    });

    let html = '';
    Object.entries(byTeam).sort(([a],[b]) => a.localeCompare(b)).forEach(([team, players]) => {
      const tRows = players.map(p => {
        const rid = p.request_id || 0;
        const pn = (p.player_name || '').replace(/'/g, "\\'");
        return `<tr>
          <td><strong>${p.player_name}</strong></td>
          <td>${p.position || ''}${p.secondary_position ? `<span style="color:var(--text-3)">/${p.secondary_position}</span>` : ''}</td>
          <td><strong style="color:var(--red)">${p.ovr}</strong></td>
          <td>${p.age}</td>
          <td><span class="badge bg-secondary">${p.league}</span></td>
          <td>${p.season_year || '—'}</td>
          <td style="white-space:nowrap">
            <button class="btn btn-outline-warning btn-sm py-0 px-2 me-1" title="Mudar time" onclick="openFaChangeTeam(${rid},'${pn}')"><i class="bi bi-arrow-left-right"></i></button>
            <button class="btn btn-outline-danger btn-sm py-0 px-2" title="Reverter" onclick="adminFaRevertPlayer(${rid},'${pn}')"><i class="bi bi-arrow-counterclockwise"></i></button>
          </td>
        </tr>`;
      }).join('');

      html += `
        <div class="mb-4">
          <div style="font-size:13px;font-weight:700;color:var(--text);padding:7px 0 6px;border-bottom:1px solid var(--border);margin-bottom:8px;display:flex;align-items:center;gap:8px">
            <i class="bi bi-people-fill" style="color:#22c55e"></i>${team}
            <span style="font-size:11px;font-weight:400;color:var(--text-3);margin-left:auto">${players.length} jogador${players.length !== 1 ? 'es' : ''}</span>
          </div>
          <div style="overflow-x:auto">
            <table class="table table-dark table-sm mb-0" style="font-size:12px">
              <thead><tr><th>Jogador</th><th>Pos</th><th>OVR</th><th>Idade</th><th>Liga</th><th>Temp.</th><th></th></tr></thead>
              <tbody>${tRows}</tbody>
            </table>
          </div>
        </div>`;
    });

    container.innerHTML = html;
  } catch(e) {
    container.innerHTML = '<p class="empty-state text-danger">Erro de conexão.</p>';
  }
}

async function adminFaRevertPlayer(requestId, playerName) {
  if (!requestId) { showAlert('danger', 'ID inválido.'); return; }
  if (!confirm(`Reverter contratação de "${playerName}"?\nO jogador será removido do time e as moedas devolvidas.`)) return;
  try {
    const r = await fetch('/api/free-agency.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'admin_fa_revert', request_id: requestId })
    });
    const d = await r.json();
    if (d.success) { showAlert('success', d.message || 'Revertido.'); loadAdminFaHistory(); }
    else showAlert('danger', d.error || 'Erro ao reverter.');
  } catch(e) { showAlert('danger', 'Erro de conexão.'); }
}

async function openFaChangeTeam(requestId, playerName) {
  if (!requestId) { showAlert('danger', 'ID inválido.'); return; }
  document.getElementById('faChangeReqId').value = requestId;
  document.getElementById('faChangePlayerName').textContent = playerName;
  const sel = document.getElementById('faChangeNewTeam');
  sel.innerHTML = '<option>Carregando...</option>';
  try {
    const r = await fetch('/api/teams.php?action=list');
    const d = await r.json();
    const teams = d.teams || d.data || [];
    sel.innerHTML = teams.map(t =>
      `<option value="${t.id}">${[(t.city||''), (t.name||'')].join(' ').trim()} (${t.league||''})</option>`
    ).join('');
  } catch(e) { sel.innerHTML = '<option value="">Erro ao carregar times</option>'; }
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalFaChangeTeam')).show();
}

async function adminFaChangeTeamConfirm() {
  const requestId = parseInt(document.getElementById('faChangeReqId').value);
  const newTeamId = parseInt(document.getElementById('faChangeNewTeam').value);
  if (!requestId || !newTeamId) { showAlert('danger', 'Selecione um time.'); return; }
  try {
    const r = await fetch('/api/free-agency.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'admin_fa_change_team', request_id: requestId, new_team_id: newTeamId })
    });
    const d = await r.json();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalFaChangeTeam')).hide();
    if (d.success) { showAlert('success', d.message || 'Time alterado.'); loadAdminFaHistory(); }
    else showAlert('danger', d.error || 'Erro ao mudar time.');
  } catch(e) { showAlert('danger', 'Erro de conexão.'); }
}

async function showFreeAgency() {
  appState.view = 'freeagency';
  updateBreadcrumb();

  const league = appState.currentLeague || _leagues[0] || 'ELITE';
  const container = document.getElementById('mainContainer');

  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back" onclick="showLeague('${league}')"><i class="bi bi-arrow-left"></i> Voltar</button>
    </div>

    <div class="row g-4 mb-4">
      <div class="col-lg-5">
        <div class="panel h-100">
          <div class="panel-header">
            <div class="panel-title"><i class="bi bi-search" style="color:#f59e0b;margin-right:8px"></i>Cadastrar Leilão</div>
          </div>
          <div style="position:relative;margin-bottom:12px">
            <div class="d-flex gap-2">
              <input id="leilaoSearchInput" type="text" class="form-control" placeholder="Buscar jogador da liga ${league}..."
                style="background:var(--panel-2);border:1px solid var(--border-md);color:var(--text);border-radius:var(--radius-sm);padding:8px 12px;font-size:13px;">
              <button class="btn-ghost" style="padding:7px 14px;white-space:nowrap" onclick="_leilaoDoSearch()">
                <i class="bi bi-search"></i>
              </button>
            </div>
            <div id="leilaoSearchDrop" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:50;
              background:var(--panel-3);border:1px solid var(--border-md);border-radius:var(--radius-sm);
              max-height:220px;overflow-y:auto;margin-top:2px"></div>
          </div>
          <div id="leilaoSelectedInfo" style="display:none;padding:10px 14px;background:var(--panel-3);
            border-radius:var(--radius-sm);border:1px solid var(--border-md);margin-bottom:12px">
            <div id="leilaoSelectedName" style="font-weight:600;font-size:14px;color:var(--text)"></div>
            <div id="leilaoSelectedSub" style="font-size:12px;color:var(--text-3);margin-top:2px"></div>
          </div>
          <button id="leilaoStartBtn" style="display:none;width:100%;padding:10px;font-size:13px;font-weight:600;
            background:rgba(245,158,11,.12);color:#f59e0b;border:1px solid rgba(245,158,11,.3);
            border-radius:var(--radius-sm);cursor:pointer" onclick="_leilaoStart()">
            <i class="bi bi-hammer me-2"></i>Começar Leilão
          </button>
        </div>
      </div>

      <div class="col-lg-7">
        <div class="panel h-100">
          <div class="panel-header">
            <div class="panel-title"><i class="bi bi-broadcast" style="color:#22c55e;margin-right:8px"></i>Leilões Ativos</div>
            <button class="btn-ghost" style="padding:6px 10px;font-size:12px" onclick="_leilaoLoadActive()">
              <i class="bi bi-arrow-repeat"></i>
            </button>
          </div>
          <div id="leilaoAtivosContainer"><p class="empty-state">Carregando...</p></div>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><i class="bi bi-clock-history" style="color:var(--text-3);margin-right:8px"></i>Histórico de Leilões</div>
        <button class="btn-ghost" style="padding:6px 10px;font-size:12px" onclick="_leilaoLoadHistory()">
          <i class="bi bi-arrow-repeat"></i>
        </button>
      </div>
      <div id="leilaoHistoricoContainer"><p class="empty-state">Carregando...</p></div>
    </div>
  `;

  document.getElementById('leilaoSearchInput').addEventListener('keydown', e => {
    if (e.key === 'Enter') _leilaoDoSearch();
  });

  _leilaoSearchResult = null;
  await Promise.all([_leilaoLoadActive(), _leilaoLoadHistory()]);
}

// ============================================
// LEILÕES - GESTÃO ADMIN
// ============================================

let _leilaoSearchResult = null;
let _leilaoAtivos = [];

async function _leilaoDoSearch() {
  const input = document.getElementById('leilaoSearchInput');
  const drop = document.getElementById('leilaoSearchDrop');
  const term = input?.value.trim();
  if (!term || term.length < 2) return;
  const league = appState.currentLeague || _leagues[0] || 'ELITE';
  drop.innerHTML = '<div style="padding:10px;font-size:12px;color:var(--text-3)">Buscando...</div>';
  drop.style.display = 'block';
  try {
    const data = await api(`team.php?action=search_player&query=${encodeURIComponent(term)}&league=${encodeURIComponent(league)}`);
    const players = data.players || [];
    if (!players.length) {
      drop.innerHTML = '<div style="padding:10px;font-size:12px;color:var(--text-3)">Nenhum jogador encontrado nesta liga</div>';
      return;
    }
    drop.innerHTML = players.map(p => {
      const ovr = p.ovr || p.overall || '—';
      const teamName = p.team_name || '';
      return `<div
        data-pid="${p.id}" data-tid="${p.team_id || 0}"
        data-name="${escapeHtml(p.name)}" data-pos="${escapeHtml(p.position || '')}"
        data-ovr="${ovr === '—' ? 0 : ovr}"
        onclick="_leilaoSelectFromEl(this)"
        style="padding:10px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--border);
               display:flex;justify-content:space-between;align-items:center;transition:background .15s"
        onmouseover="this.style.background='var(--panel-2)'" onmouseout="this.style.background=''">
        <div>
          <span style="font-weight:600;color:var(--text)">${escapeHtml(p.name)}</span>
          <span style="color:var(--text-3);margin-left:6px;font-size:11px">${escapeHtml(p.position || '')} · ${escapeHtml(teamName)}</span>
        </div>
        <span style="font-size:11px;color:var(--text-3)">OVR ${ovr}</span>
      </div>`;
    }).join('');
  } catch (e) {
    drop.innerHTML = `<div style="padding:10px;font-size:12px;color:#ef4444">${e.error || 'Erro na busca'}</div>`;
  }
}

function _leilaoSelectFromEl(el) {
  _leilaoSelect(
    parseInt(el.dataset.pid),
    parseInt(el.dataset.tid),
    el.dataset.name,
    el.dataset.pos,
    parseInt(el.dataset.ovr)
  );
}

function _leilaoSelect(playerId, teamId, name, pos, ovr) {
  _leilaoSearchResult = { id: playerId, team_id: teamId, name, pos, ovr };
  const drop = document.getElementById('leilaoSearchDrop');
  if (drop) drop.style.display = 'none';
  const inp = document.getElementById('leilaoSearchInput');
  if (inp) inp.value = name;
  const infoBox = document.getElementById('leilaoSelectedInfo');
  const nameEl = document.getElementById('leilaoSelectedName');
  const subEl = document.getElementById('leilaoSelectedSub');
  const btn = document.getElementById('leilaoStartBtn');
  if (infoBox) infoBox.style.display = 'block';
  if (nameEl) nameEl.textContent = name;
  if (subEl) subEl.textContent = `${pos} · OVR ${ovr}`;
  if (btn) btn.style.display = 'block';
}

async function _leilaoStart() {
  if (!_leilaoSearchResult) return;
  const league = appState.currentLeague || _leagues[0] || 'ELITE';
  const leagueId = leagueIdByName[league];
  if (!leagueId) { alert('ID da liga não encontrado.'); return; }
  const btn = document.getElementById('leilaoStartBtn');
  if (btn) btn.disabled = true;
  try {
    await api('leilao.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'cadastrar',
        player_id: _leilaoSearchResult.id,
        team_id: _leilaoSearchResult.team_id || null,
        league_id: leagueId
      })
    });
    _leilaoSearchResult = null;
    const infoBox = document.getElementById('leilaoSelectedInfo');
    const inp = document.getElementById('leilaoSearchInput');
    if (infoBox) infoBox.style.display = 'none';
    if (inp) inp.value = '';
    if (btn) { btn.style.display = 'none'; btn.disabled = false; }
    showAlert('success', 'Leilão iniciado!');
    await _leilaoLoadActive();
  } catch (e) {
    if (btn) btn.disabled = false;
    alert(e.error || 'Erro ao iniciar leilão');
  }
}

async function _leilaoLoadActive() {
  const container = document.getElementById('leilaoAtivosContainer');
  if (!container) return;
  const league = appState.currentLeague || _leagues[0] || 'ELITE';
  try {
    const data = await api('leilao.php?action=listar_admin');
    _leilaoAtivos = (data.leiloes || []).filter(l => l.league_name === league && l.status === 'ativo');
    _leilaoRenderActive();
    // Auto-abre propostas de leilões com pelo menos 1 proposta
    for (const l of _leilaoAtivos) {
      if (Number(l.total_propostas) > 0) {
        leilaoTogglePropostas(l.id);
      }
    }
  } catch (e) {
    container.innerHTML = `<p class="empty-state" style="color:#ef4444">${e.error || 'Erro ao carregar'}</p>`;
  }
}

function _leilaoRenderActive() {
  const container = document.getElementById('leilaoAtivosContainer');
  if (!container) return;
  if (!_leilaoAtivos.length) {
    container.innerHTML = '<p class="empty-state">Nenhum leilão ativo nesta liga.</p>';
    return;
  }
  const now = Date.now() / 1000;
  container.innerHTML = _leilaoAtivos.map(l => {
    const ts = Number(l.data_fim_ts) || 0;
    const secsLeft = ts - now;
    const expired = ts > 0 && secsLeft <= 0;
    const timeStr = ts === 0 ? '—' : expired ? 'Expirado' : _leilaoFmtTime(secsLeft);
    const timeColor = expired ? '#ef4444' : secsLeft < 300 ? '#f59e0b' : '#22c55e';
    return `
      <div class="pun-card" style="margin-bottom:12px" id="leilao-card-${l.id}">
        <div class="pun-card-head">
          <div>
            <div class="pun-card-title">${l.player_name}
              <span style="font-size:11px;font-weight:400;color:var(--text-3)">&nbsp;${l.position || ''} · OVR ${l.ovr || '—'}</span>
            </div>
            <div class="pun-card-sub">${l.team_name || 'Sem time'} · ${l.total_propostas || 0} proposta(s)</div>
          </div>
          <div class="d-flex align-items-center gap-2 flex-shrink-0">
            <span style="font-size:12px;font-weight:600;color:${timeColor}">${timeStr}</span>
            <button class="btn-ghost" style="padding:4px 10px;font-size:11px"
              onclick="leilaoTogglePropostas(${l.id})"><i class="bi bi-list-ul me-1"></i>Propostas</button>
            <button class="btn-ghost" style="padding:4px 10px;font-size:11px;color:#ef4444"
              onclick="leiaoCancelar(${l.id})" title="Cancelar leilão"><i class="bi bi-x-lg"></i></button>
          </div>
        </div>
        <div id="leilao-propostas-${l.id}" style="display:none;margin-top:10px;padding-top:10px;border-top:1px solid var(--border)">
          <p class="empty-state" style="font-size:12px">Carregando...</p>
        </div>
      </div>`;
  }).join('');
}

function _leilaoFmtTime(secs) {
  if (secs <= 0) return 'Expirado';
  const h = Math.floor(secs / 3600);
  const m = Math.floor((secs % 3600) / 60);
  const s = Math.floor(secs % 60);
  if (h > 0) return `${h}h ${m}m`;
  return `${m}:${String(s).padStart(2, '0')}`;
}

window.leilaoTogglePropostas = async function(leilaoId) {
  const div = document.getElementById(`leilao-propostas-${leilaoId}`);
  if (!div) return;
  if (div.style.display !== 'none') { div.style.display = 'none'; return; }
  div.style.display = 'block';
  div.innerHTML = '<p class="empty-state" style="font-size:12px">Carregando...</p>';
  try {
    const data = await api(`leilao.php?action=ver_propostas&leilao_id=${leilaoId}`);
    const propostas = data.propostas || [];
    if (!propostas.length) {
      div.innerHTML = '<p class="empty-state" style="font-size:12px">Nenhuma proposta ainda.</p>';
      return;
    }
    const _statusOrd = { pendente: 0, aceita: 1, recusada: 2 };
    propostas.sort((a, b) => (_statusOrd[a.status] ?? 3) - (_statusOrd[b.status] ?? 3));
    div.innerHTML = propostas.map(p => {
      const jogs = (p.jogadores || []).map(j => escapeHtml(j.name)).join(', ') || '—';
      const obs = (p.obs || p.notas || '').trim();
      const statusMap = { aceita: '#22c55e', recusada: '#ef4444', pendente: '#f59e0b' };
      const sc = statusMap[p.status] || 'var(--text-3)';
      // picks separadas por rodada
      const _esc = s => escapeHtml(String(s ?? ''));
      const _pickBadge = pk => {
        const orig = (pk.original_team_name || '').trim();
        return `<span style="display:inline-flex;align-items:center;background:rgba(59,130,246,.1);color:#3b82f6;border:1px solid rgba(59,130,246,.25);border-radius:6px;padding:3px 9px;font-size:11px;margin:2px 2px 2px 0">${_esc(pk.season_year)} R${pk.round||'?'}${orig ? `<span style="font-size:10px;color:var(--text);margin-left:5px">${_esc(orig)}</span>` : ''}</span>`;
      };
      const allPicks = p.picks || [];
      const r1 = allPicks.filter(pk => Number(pk.round) === 1);
      const r2 = allPicks.filter(pk => Number(pk.round) !== 1);
      let picksHtml = '';
      if (allPicks.length) {
        if (r1.length) picksHtml += `<div style="font-size:9px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px">1ª Rodada</div><div>${r1.map(_pickBadge).join('')}</div>`;
        if (r2.length) picksHtml += `<div style="font-size:9px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin:${r1.length ? '8px' : '0'} 0 3px">2ª Rodada</div><div>${r2.map(_pickBadge).join('')}</div>`;
      } else {
        picksHtml = '<span style="color:var(--text-3);font-size:12px">—</span>';
      }
      return `
        <div style="padding:12px 14px;background:var(--panel-2);border-radius:var(--radius-sm);
          margin-bottom:8px;border:1px solid var(--border)">
          <div class="d-flex justify-content-between align-items-start gap-2" style="margin-bottom:10px">
            <div style="font-weight:600;font-size:13px;color:var(--text)">${escapeHtml(p.team_name || '—')}</div>
            <div class="d-flex align-items-center gap-1 flex-shrink-0">
              <span style="font-size:10px;font-weight:600;color:${sc};text-transform:uppercase">${p.status}</span>
              ${p.status === 'pendente' ? `
                <button class="btn-ghost" style="padding:3px 9px;font-size:11px;color:#22c55e;margin-left:6px"
                  onclick="leilaoAceitar(${p.id},${leilaoId})">Aceitar</button>
                <button class="btn-ghost" style="padding:3px 9px;font-size:11px;color:#ef4444"
                  onclick="leilaoRecusar(${p.id},${leilaoId})">Recusar</button>
              ` : ''}
            </div>
          </div>
          <div style="font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Jogadores</div>
          <div style="font-size:12px;color:var(--text);margin-bottom:10px">${jogs}</div>
          <div style="font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Picks</div>
          <div>${picksHtml}</div>
          ${obs ? `<div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:8px;padding:10px 12px;margin-top:10px"><div style="font-size:10px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px"><i class="bi bi-sticky me-1"></i>Observação</div><div style="font-size:13px;color:var(--text)">${_esc(obs)}</div></div>` : ''}
        </div>`;
    }).join('');
  } catch (e) {
    div.innerHTML = `<p class="empty-state" style="font-size:12px;color:#ef4444">${e.error || 'Erro'}</p>`;
  }
};

window.leilaoAceitar = async function(propostaId, leilaoId) {
  if (!confirm('Aceitar esta proposta? O jogador será transferido.')) return;
  try {
    await api('leilao.php', { method: 'POST', body: JSON.stringify({ action: 'aceitar_proposta', proposta_id: propostaId }) });
    showAlert('success', 'Proposta aceita!');
    await Promise.all([_leilaoLoadActive(), _leilaoLoadHistory()]);
  } catch (e) { alert(e.error || 'Erro ao aceitar'); }
};

window.leilaoRecusar = async function(propostaId, leilaoId) {
  if (!confirm('Recusar esta proposta?')) return;
  try {
    await api('leilao.php', { method: 'POST', body: JSON.stringify({ action: 'recusar_proposta', proposta_id: propostaId }) });
    showAlert('success', 'Proposta recusada.');
    await leilaoTogglePropostas(leilaoId);
  } catch (e) { alert(e.error || 'Erro ao recusar'); }
};

window.leiaoCancelar = async function(leilaoId) {
  if (!confirm('Cancelar este leilão? Todas as propostas serão recusadas.')) return;
  try {
    await api('leilao.php', { method: 'POST', body: JSON.stringify({ action: 'cancelar', leilao_id: leilaoId }) });
    showAlert('success', 'Leilão cancelado.');
    await Promise.all([_leilaoLoadActive(), _leilaoLoadHistory()]);
  } catch (e) { alert(e.error || 'Erro ao cancelar'); }
};

async function _leilaoLoadHistory() {
  const container = document.getElementById('leilaoHistoricoContainer');
  if (!container) return;
  const league = appState.currentLeague || _leagues[0] || 'ELITE';
  const leagueId = leagueIdByName[league] || null;
  try {
    const url = leagueId ? `leilao.php?action=historico&league_id=${leagueId}` : 'leilao.php?action=historico';
    const data = await api(url);
    const leiloes = data.leiloes || [];
    if (!leiloes.length) {
      container.innerHTML = '<p class="empty-state">Nenhum leilão finalizado nesta liga.</p>';
      return;
    }
    container.innerHTML = leiloes.map(l => {
      const winnerHtml = l.winner_team_name
        ? `<span style="color:#22c55e;font-weight:600">${escapeHtml(l.winner_team_name)}</span>`
        : `<span style="color:var(--text-3)">Sem vencedor</span>`;
      const dateStr = l.data_fim ? String(l.data_fim).split(' ')[0] : '—';
      const nProp = Number(l.total_propostas) || 0;
      return `
        <div class="pun-card" style="margin-bottom:10px">
          <div class="pun-card-head">
            <div style="flex:1;min-width:0">
              <div class="pun-card-title">${escapeHtml(l.player_name || '—')}
                <span style="font-size:11px;font-weight:400;color:var(--text-3)">&nbsp;${escapeHtml(l.position || '')}${l.ovr ? ' · OVR ' + l.ovr : ''}</span>
              </div>
              <div class="pun-card-sub">
                ${escapeHtml(l.team_name || '—')} · Vencedor: ${winnerHtml} · ${dateStr}
                ${nProp > 0 ? `<span style="margin-left:6px;font-size:11px;color:var(--text-3)">${nProp} oferta(s)</span>` : ''}
              </div>
            </div>
            <button class="btn-ghost" style="padding:4px 10px;font-size:11px;flex-shrink:0"
              onclick="_leilaoToggleHistPropostas(${l.id}, this)">
              <i class="bi bi-list-ul me-1"></i>Ver Ofertas
            </button>
          </div>
          <div id="leilao-hist-propostas-${l.id}" style="display:none;margin-top:10px;padding-top:10px;border-top:1px solid var(--border)"></div>
        </div>`;
    }).join('');
  } catch (e) {
    container.innerHTML = `<p class="empty-state" style="color:#ef4444">${e.error || 'Erro ao carregar histórico'}</p>`;
  }
}

window._leilaoToggleHistPropostas = async function(leilaoId, btn) {
  const div = document.getElementById(`leilao-hist-propostas-${leilaoId}`);
  if (!div) return;
  if (div.style.display !== 'none') {
    div.style.display = 'none';
    if (btn) btn.innerHTML = '<i class="bi bi-list-ul me-1"></i>Ver Ofertas';
    return;
  }
  div.style.display = 'block';
  if (btn) btn.innerHTML = '<i class="bi bi-chevron-up me-1"></i>Fechar';
  div.innerHTML = '<p class="empty-state" style="font-size:12px">Carregando...</p>';
  try {
    const data = await api(`leilao.php?action=ver_propostas&leilao_id=${leilaoId}`);
    const propostas = data.propostas || [];
    if (!propostas.length) {
      div.innerHTML = '<p class="empty-state" style="font-size:12px">Nenhuma oferta registrada neste leilão.</p>';
      return;
    }
    const statusMap = { aceita: '#22c55e', recusada: '#ef4444', pendente: '#f59e0b' };
    div.innerHTML = propostas.map(p => {
      const sc = statusMap[p.status] || 'var(--text-3)';
      const borderColor = p.status === 'aceita' ? 'rgba(34,197,94,.3)' : 'var(--border)';
      const jogsHtml = (p.jogadores || []).length
        ? (p.jogadores || []).map(j => {
            const meta = [j.position, j.ovr ? `OVR ${j.ovr}` : null, j.age ? `${j.age} anos` : null].filter(Boolean).join(' · ');
            return `<div style="display:flex;align-items:baseline;gap:6px">
              <span style="color:#fff;font-weight:500">${escapeHtml(j.name)}</span>
              ${meta ? `<span style="font-size:10px;color:var(--text-3)">${meta}</span>` : ''}
            </div>`;
          }).join('')
        : '<span style="color:var(--text-3)">—</span>';
      const obs2 = (p.obs || p.notas || '').trim();
      const _esc2 = s => escapeHtml(String(s ?? ''));
      const _badge2 = pk => {
        const orig = (pk.original_team_name || pk.current_team_name || '').trim();
        return `<span style="display:inline-flex;align-items:center;background:rgba(59,130,246,.1);color:#3b82f6;border:1px solid rgba(59,130,246,.25);border-radius:6px;padding:3px 9px;font-size:11px;margin:2px 2px 2px 0">${_esc2(pk.season_year)} R${pk.round||'?'}${orig ? `<span style="font-size:10px;color:var(--text);margin-left:5px">${_esc2(orig)}</span>` : ''}</span>`;
      };
      const allPicks2 = p.picks || [];
      const r1b = allPicks2.filter(pk => Number(pk.round) === 1);
      const r2b = allPicks2.filter(pk => Number(pk.round) !== 1);
      let picksHtml = '';
      if (allPicks2.length) {
        if (r1b.length) picksHtml += `<div style="font-size:9px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px">1ª Rodada</div><div>${r1b.map(_badge2).join('')}</div>`;
        if (r2b.length) picksHtml += `<div style="font-size:9px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin:${r1b.length ? '8px' : '0'} 0 3px">2ª Rodada</div><div>${r2b.map(_badge2).join('')}</div>`;
      } else {
        picksHtml = '<span style="color:var(--text-3);font-size:12px">—</span>';
      }
      return `
        <div style="padding:10px 12px;background:var(--panel-2);border-radius:var(--radius-sm);
          margin-bottom:8px;border:1px solid ${borderColor}">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div style="flex:1;min-width:0">
              <div style="font-weight:700;font-size:13px;color:#fff;margin-bottom:6px">${escapeHtml(p.team_name || '—')}</div>
              <div style="font-size:11px;color:var(--text-3);margin-bottom:2px">Jogadores</div>
              <div style="font-size:12px;margin-bottom:6px">${jogsHtml}</div>
              <div style="font-size:11px;color:var(--text-3);margin-bottom:2px">Picks</div>
              <div style="font-size:12px;margin-bottom:${obs2 ? 6 : 0}px">${picksHtml}</div>
              ${obs2 ? `<div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:8px;padding:10px 12px;margin-top:6px"><div style="font-size:10px;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px"><i class="bi bi-sticky me-1"></i>Observação</div><div style="font-size:13px;color:var(--text)">${_esc2(obs2)}</div></div>` : ''}
            </div>
            <span style="font-size:10px;font-weight:700;color:${sc};text-transform:uppercase;
              flex-shrink:0;padding:3px 9px;background:${sc}18;border:1px solid ${sc}44;border-radius:999px">
              ${p.status}
            </span>
          </div>
        </div>`;
    }).join('');
  } catch (e) {
    div.innerHTML = `<p class="empty-state" style="font-size:12px;color:#ef4444">${e.error || 'Erro ao carregar ofertas'}</p>`;
  }
};

function setFreeAgencyLeague(league) {
  appState.currentFAleague = league;
}

function refreshAdminFreeAgency() {
  _leilaoLoadActive();
}

// ========== MOEDAS ==========
let coinsLeague = 'ELITE';

async function showCoins(league) {
  league = league || appState.currentLeague || 'ELITE';
  coinsLeague = league;
  appState.view = 'coins';
  updateBreadcrumb();

  const container = document.getElementById('mainContainer');
  container.innerHTML = `
<div class="mb-3"><button class="btn btn-back" onclick="showLeague('${league}')"><i class="bi bi-arrow-left"></i> Voltar</button></div>

<div class="panel mb-3">
  <div class="panel-header">
    <div>
      <div class="panel-title" style="margin-bottom:0"><i class="bi bi-coin" style="color:#f59e0b"></i> Moedas — ${league}</div>
      <div class="panel-sub">Free Agency coins dos times da liga</div>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <button class="btn-ghost" onclick="openBulkCoinsModal()"><i class="bi bi-people-fill me-1"></i>Distribuir para Liga</button>
      <button class="btn-orange" onclick="saveAllCoins()"><i class="bi bi-save2 me-1"></i>Salvar</button>
    </div>
  </div>
  <div id="coinsContainer">
    <div class="text-center py-4"><div class="spinner-border" style="color:var(--red)"></div></div>
  </div>
</div>

<div class="modal fade" id="addCoinsModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-coin me-2" style="color:#f59e0b"></i>Gerenciar Moedas</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="coinsTeamId">
        <div class="mb-3">
          <label class="pun-field-label">Time</label>
          <input type="text" class="form-control" id="coinsTeamName" readonly>
        </div>
        <div class="mb-3">
          <label class="pun-field-label">Saldo Atual</label>
          <input type="text" class="form-control" id="coinsCurrentBalance" readonly>
        </div>
        <div class="mb-3">
          <label class="pun-field-label">Operação</label>
          <select class="form-select" id="coinsOperation">
            <option value="add">Adicionar</option>
            <option value="remove">Remover</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="pun-field-label">Quantidade</label>
          <input type="number" class="form-control" id="coinsAmount" min="1" value="100">
        </div>
        <div class="mb-3">
          <label class="pun-field-label">Motivo</label>
          <input type="text" class="form-control" id="coinsReason" placeholder="Ex: Prêmio de temporada">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-orange" onclick="submitCoins()">Confirmar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="bulkCoinsModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-people-fill me-2" style="color:#f59e0b"></i>Distribuir Moedas</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="pun-card mb-3" style="border-left:3px solid #f59e0b;font-size:13px">
          <i class="bi bi-info-circle me-2" style="color:#f59e0b"></i>Adicionará moedas para <strong>todos os times</strong> da liga <strong>${league}</strong>.
        </div>
        <input type="hidden" id="bulkCoinsLeague" value="${league}">
        <div class="mb-3">
          <label class="pun-field-label">Quantidade por Time</label>
          <input type="number" class="form-control" id="bulkCoinsAmount" min="1" value="100">
        </div>
        <div class="mb-3">
          <label class="pun-field-label">Motivo</label>
          <input type="text" class="form-control" id="bulkCoinsReason" placeholder="Ex: Início de temporada">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-orange" onclick="submitBulkCoins()">Distribuir</button>
      </div>
    </div>
  </div>
</div>`;

  loadCoinsTeams();
}

async function loadCoinsTeams() {
  const container = document.getElementById('coinsContainer');
  if (!container) return;
  try {
    const data = await api(`admin.php?action=coins&league=${coinsLeague}`);
    const teams = data.teams || [];
    if (teams.length === 0) {
      container.innerHTML = '<div style="text-align:center;padding:32px;color:var(--text-3)">Nenhum time encontrado.</div>';
      return;
    }
    const totalCoins = teams.reduce((sum, t) => sum + parseInt(t.moedas || 0), 0);
    container.innerHTML = `
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
  <div class="pun-card" style="flex:1;min-width:120px;padding:12px 16px;text-align:center">
    <div style="font-size:20px;font-weight:700;color:#f59e0b"><i class="bi bi-coin me-1"></i><span data-coins-total>${totalCoins.toLocaleString()}</span></div>
    <div style="font-size:11px;color:var(--text-3)">Total na liga</div>
  </div>
  <div class="pun-card" style="flex:1;min-width:120px;padding:12px 16px;text-align:center">
    <div style="font-size:20px;font-weight:700;color:var(--text)">${teams.length}</div>
    <div style="font-size:11px;color:var(--text-3)">Times</div>
  </div>
</div>
${teams.map(t => {
  const coins = parseInt(t.moedas || 0);
  return `<div class="pun-card" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
  <div style="flex:1;min-width:140px">
    <span style="font-weight:600;color:var(--text)">${escapeHtml(t.city)} ${escapeHtml(t.name)}</span>
    <div style="font-size:12px;color:var(--text-3);margin-top:2px">${escapeHtml(t.owner_name || '')}</div>
  </div>
  <div style="display:flex;align-items:center;gap:6px">
    <i class="bi bi-coin" style="color:#f59e0b;font-size:14px"></i>
    <input type="number" min="0" value="${coins}" data-original="${coins}"
           id="coins-input-${t.id}"
           style="width:90px;background:var(--panel-3);border:1px solid var(--border);border-radius:8px;padding:5px 8px;color:var(--text);font-size:13px;font-family:var(--font)">
    <button class="btn-ghost" style="padding:5px 9px" title="Histórico" onclick="showCoinsHistory(${t.id}, '${escapeHtml(t.city + ' ' + t.name)}')"><i class="bi bi-clock-history"></i></button>
  </div>
</div>`;
}).join('')}`;
  } catch (e) {
    container.innerHTML = `<div style="color:#ef4444;padding:16px">Erro: ${e.error || 'Desconhecido'}</div>`;
  }
}

async function saveAllCoins() {
  const container = document.getElementById('coinsContainer');
  if (!container) return;
  const inputs = container.querySelectorAll('input[type="number"][data-original]');
  const changes = [];
  inputs.forEach(el => {
    const newBalance = parseInt(el.value);
    const originalBalance = parseInt(el.dataset.original || 0);
    if (!isNaN(newBalance) && newBalance >= 0 && newBalance !== originalBalance) {
      const teamId = el.id.replace('coins-input-', '');
      changes.push({ el, teamId, newBalance, delta: newBalance - originalBalance });
    }
  });
  if (changes.length === 0) { showAlert('info', 'Nenhuma alteração.'); return; }
  try {
    await Promise.all(changes.map(c =>
      api('admin.php?action=coins', {
        method: 'POST',
        body: JSON.stringify({
          team_id: c.teamId,
          operation: c.delta > 0 ? 'add' : 'remove',
          amount: Math.abs(c.delta),
          reason: 'Ajuste administrativo'
        })
      })
    ));
    changes.forEach(c => { c.el.dataset.original = String(c.newBalance); });
    let total = 0;
    inputs.forEach(el => { total += parseInt(el.value || 0); });
    const totalEl = container.querySelector('[data-coins-total]');
    if (totalEl) totalEl.textContent = total.toLocaleString();
    showAlert('success', `${changes.length} time(s) atualizados!`);
  } catch (e) {
    alert('Erro: ' + (e.error || 'Desconhecido'));
  }
}

function openCoinsModal(teamId, teamName, currentBalance) {
  document.getElementById('coinsTeamId').value = teamId;
  document.getElementById('coinsTeamName').value = teamName;
  document.getElementById('coinsCurrentBalance').value = parseInt(currentBalance).toLocaleString();
  document.getElementById('coinsOperation').value = 'add';
  document.getElementById('coinsAmount').value = 100;
  document.getElementById('coinsReason').value = '';
  
  new bootstrap.Modal(document.getElementById('addCoinsModal')).show();
}

function openBulkCoinsModal() {
  document.getElementById('bulkCoinsAmount').value = 100;
  document.getElementById('bulkCoinsReason').value = '';
  new bootstrap.Modal(document.getElementById('bulkCoinsModal')).show();
}

async function submitCoins() {
  const teamId = document.getElementById('coinsTeamId').value;
  const operation = document.getElementById('coinsOperation').value;
  const amount = parseInt(document.getElementById('coinsAmount').value);
  const reason = document.getElementById('coinsReason').value.trim() || 'Ajuste administrativo';
  
  if (!teamId || !amount || amount <= 0) {
    alert('Preencha uma quantidade válida.');
    return;
  }
  
  try {
    const result = await api('admin.php?action=coins', {
      method: 'POST',
      body: JSON.stringify({ team_id: teamId, operation, amount, reason })
    });
    
    bootstrap.Modal.getInstance(document.getElementById('addCoinsModal'))?.hide();
    alert(result.message);
    loadCoinsTeams();
  } catch (e) {
    alert('Erro: ' + (e.error || 'Desconhecido'));
  }
}

async function submitBulkCoins() {
  const league = document.getElementById('bulkCoinsLeague').value;
  const amount = parseInt(document.getElementById('bulkCoinsAmount').value);
  const reason = document.getElementById('bulkCoinsReason').value.trim() || 'Distribuição de moedas';
  
  if (!amount || amount <= 0) {
    alert('Preencha uma quantidade válida.');
    return;
  }
  
  if (!confirm(`Tem certeza que deseja adicionar ${amount} moedas para TODOS os times da liga ${league}?`)) {
    return;
  }
  
  try {
    const result = await api('admin.php?action=coins_bulk', {
      method: 'POST',
      body: JSON.stringify({ league, amount, reason })
    });
    
    bootstrap.Modal.getInstance(document.getElementById('bulkCoinsModal'))?.hide();
    alert(result.message);
    coinsLeague = league;
    showCoins(league);
  } catch (e) {
    alert('Erro: ' + (e.error || 'Desconhecido'));
  }
}

async function showCoinsHistory(teamId, teamName) {
  const container = document.getElementById('coinsContainer');
  if (!container) return;
  container.innerHTML = '<div class="text-center py-4"><div class="spinner-border" style="color:var(--red)"></div></div>';
  try {
    const data = await api(`admin.php?action=coins_log&team_id=${teamId}`);
    const logs = data.logs || [];
    const typeMap = {
      admin_add:    { label: 'Adição Admin',   color: '#4ade80', bg: 'rgba(74,222,128,.15)',  border: 'rgba(74,222,128,.3)'  },
      admin_remove: { label: 'Remoção Admin',  color: '#ef4444', bg: 'rgba(239,68,68,.15)',   border: 'rgba(239,68,68,.3)'   },
      admin_bulk:   { label: 'Distribuição',   color: '#38bdf8', bg: 'rgba(56,189,248,.15)',  border: 'rgba(56,189,248,.3)'  },
      fa_bid:       { label: 'Lance FA',       color: '#f59e0b', bg: 'rgba(245,158,11,.15)',  border: 'rgba(245,158,11,.3)'  },
      fa_win:       { label: 'Vitória FA',     color: '#a855f7', bg: 'rgba(168,85,247,.15)',  border: 'rgba(168,85,247,.3)'  },
      fa_refund:    { label: 'Reembolso FA',   color: '#94a3b8', bg: 'rgba(148,163,184,.15)', border: 'rgba(148,163,184,.3)' },
    };
    container.innerHTML = `
<div class="mb-3">
  <button class="btn btn-back" onclick="loadCoinsTeams()"><i class="bi bi-arrow-left"></i> Voltar</button>
</div>
<div style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:12px">
  <i class="bi bi-coin me-2" style="color:#f59e0b"></i>Histórico — ${escapeHtml(teamName)}
</div>
${logs.length === 0 ? '<div style="text-align:center;padding:32px;color:var(--text-3)">Nenhum histórico encontrado.</div>' :
  logs.map(log => {
    const date = new Date(log.created_at);
    const dateStr = date.toLocaleDateString('pt-BR') + ' ' + date.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
    const t = typeMap[log.type] || { label: log.type, color: '#94a3b8', bg: 'rgba(148,163,184,.15)', border: 'rgba(148,163,184,.3)' };
    const amt = parseInt(log.amount || 0);
    const pos = amt >= 0;
    return `<div class="pun-card" style="display:flex;align-items:center;gap:12px">
  <div style="flex:1;min-width:0">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px">
      <span style="background:${t.bg};color:${t.color};border:1px solid ${t.border};border-radius:999px;font-size:10px;font-weight:700;padding:2px 8px">${t.label}</span>
      <span style="font-size:11px;color:var(--text-3)">${dateStr}</span>
    </div>
    <div style="font-size:12px;color:var(--text-3)">${escapeHtml(log.reason || '-')}</div>
  </div>
  <div style="text-align:right">
    <div style="font-size:14px;font-weight:700;color:${pos ? '#4ade80' : '#ef4444'}">${pos ? '+' : ''}${amt.toLocaleString()}</div>
    <div style="font-size:11px;color:var(--text-3)">Saldo: ${parseInt(log.balance_after || 0).toLocaleString()}</div>
  </div>
</div>`;
  }).join('')}`;
  } catch (e) {
    container.innerHTML = `<div style="color:#ef4444;padding:16px">Erro: ${e.error || 'Desconhecido'}</div>`;
  }
}

// ========== TAPAS ==========
let tapasLeague = 'ELITE';

async function showTapas() {
  const _wasInTapas = appState.view === 'tapas';
  if (appState.currentLeague && !_wasInTapas) tapasLeague = appState.currentLeague;
  appState.view = 'tapas';
  updateBreadcrumb();

  const _tapasBack = appState.currentLeague ? `showLeague('${appState.currentLeague}')` : 'showHome()';

  const container = document.getElementById('mainContainer');
  container.innerHTML = `
    <div class="mb-4" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <button class="btn btn-back" onclick="${_tapasBack}"><i class="bi bi-arrow-left"></i> Voltar</button>
      <span class="text-light-gray" style="font-size:14px;font-weight:600">Tapas — ${tapasLeague}</span>
    </div>

    <div id="tapasContainer">
      <div class="text-center py-5"><div class="spinner-border text-orange"></div></div>
    </div>

    <!-- Approval confirm modal -->
    <div style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center" id="tapasApproveOverlay">
      <div style="background:var(--panel-3,#1c1c21);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:24px;width:100%;max-width:380px;margin:16px">
        <div style="font-size:15px;font-weight:700;color:var(--text,#f0f0f3);margin-bottom:12px">
          <i class="bi bi-check-circle" style="color:#22c55e"></i> Confirmar Aprovação
        </div>
        <div style="font-size:13px;color:var(--text,#f0f0f3);margin-bottom:6px" id="tapasApproveInfo"></div>
        <div style="font-size:12px;color:var(--text-2,#868690);margin-bottom:20px" id="tapasApproveTypeInfo"></div>
        <input type="hidden" id="tapasApproveReqId">
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button onclick="closeTapasApprove()" style="padding:8px 16px;border-radius:8px;border:1px solid rgba(255,255,255,.1);background:none;color:var(--text-2,#868690);font-weight:600;font-size:13px;cursor:pointer">Cancelar</button>
          <button onclick="submitTapasApprove()" style="padding:8px 18px;border-radius:8px;border:none;background:#22c55e;color:#fff;font-weight:700;font-size:13px;cursor:pointer">Aprovar</button>
        </div>
      </div>
    </div>
  `;

  loadTapasData();
}

function changeTapasLeague(league) {
  tapasLeague = league;
  showTapas();
}

function openTapasApprove(reqId, playerName, teamName, actionType, badgeName) {
  document.getElementById('tapasApproveReqId').value = reqId;
  document.getElementById('tapasApproveInfo').innerHTML =
    `<strong>${escapeHtml(playerName)}</strong> &mdash; ${escapeHtml(teamName)}`;
  const typeLabel = actionType === 'badge'
    ? `<i class="bi bi-award" style="color:#a78bfa"></i> Badge: <strong style="color:#a78bfa">${escapeHtml(badgeName || '')}</strong>`
    : `<i class="bi bi-hand-index-thumb" style="color:#f97316"></i> <strong style="color:#f97316">Tapa</strong>`;
  document.getElementById('tapasApproveTypeInfo').innerHTML = typeLabel;
  document.getElementById('tapasApproveOverlay').style.display = 'flex';
}

function closeTapasApprove() {
  document.getElementById('tapasApproveOverlay').style.display = 'none';
}

async function submitTapasApprove() {
  const reqId = parseInt(document.getElementById('tapasApproveReqId').value);
  try {
    await fetch('/api/tapas.php?action=admin_approve', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ request_id: reqId })
    }).then(r => r.json()).then(d => { if (d.success === false) throw d; });
    closeTapasApprove();
    loadTapasData();
  } catch(e) {
    alert(e.error || 'Erro ao aprovar');
  }
}

async function rejectTapasRequest(reqId) {
  if (!confirm('Rejeitar esta solicitação?')) return;
  try {
    await fetch('/api/tapas.php?action=admin_reject', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ request_id: reqId })
    }).then(r => r.json()).then(d => { if (d.success === false) throw d; });
    loadTapasData();
  } catch(e) {
    alert(e.error || 'Erro ao rejeitar');
  }
}

async function quickTapasAdminChange(teamId, operation) {
  try {
    await fetch('/api/tapas.php?action=admin_set_tapas', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ team_id: teamId, amount: 1, operation })
    }).then(r => r.json()).then(d => {
      if (d.success === false) throw d;
      const span = document.getElementById(`tapas-val-${teamId}`);
      if (span && d.new_tapas !== undefined) span.textContent = d.new_tapas;
    });
  } catch(e) {
    alert(e.error || 'Erro ao atualizar tapas');
  }
}

async function loadTapasData() {
  const container = document.getElementById('tapasContainer');
  if (!container) return;

  try {
    const data = await fetch(`/api/tapas.php?action=admin_get_all&league=${encodeURIComponent(tapasLeague)}`)
      .then(r => r.json());
    if (data.success === false) throw data;

    const teams    = data.teams    || [];
    const requests = data.requests || [];

    const totalTapas    = teams.reduce((s, t) => s + parseInt(t.tapas || 0), 0);
    const totalTapasUsed = teams.reduce((s, t) => s + parseInt(t.tapas_used || 0), 0);

    const requestsHtml = requests.length === 0
      ? '<div style="text-align:center;padding:20px;color:var(--text-3)">Nenhuma solicitação pendente.</div>'
      : requests.map(r => {
          const isBadge   = r.action_type === 'badge';
          const typeChip  = isBadge
            ? `<span style="display:inline-flex;align-items:center;gap:4px;background:rgba(139,92,246,.15);color:#a78bfa;border:1px solid rgba(139,92,246,.35);border-radius:999px;font-size:10px;font-weight:700;padding:2px 7px"><i class="bi bi-award"></i> ${escapeHtml(r.badge_name || '')}</span>`
            : `<span style="display:inline-flex;align-items:center;gap:4px;background:rgba(249,115,22,.15);color:#f97316;border:1px solid rgba(249,115,22,.35);border-radius:999px;font-size:10px;font-weight:700;padding:2px 7px"><i class="bi bi-hand-index-thumb"></i> Tapa</span>`;
          const pn = escapeHtml(r.player_name).replace(/'/g,"\\'");
          const tn = escapeHtml(r.team_city+' '+r.team_name).replace(/'/g,"\\'");
          const at = escapeHtml(r.action_type || 'tapa').replace(/'/g,"\\'");
          const bn = escapeHtml(r.badge_name || '').replace(/'/g,"\\'");
          return `
          <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;background:var(--panel-2);border:1px solid rgba(255,255,255,.07);border-radius:10px;margin-bottom:8px">
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span style="font-weight:700;font-size:13px;color:var(--text)">${escapeHtml(r.player_name)}</span>
                ${typeChip}
              </div>
              <div style="font-size:11px;color:var(--text-3);margin-top:3px">
                ${escapeHtml(r.team_city)} ${escapeHtml(r.team_name)}
                &bull; ${escapeHtml(r.owner_name)}
                &bull; ${escapeHtml(r.player_position)} OVR ${r.player_ovr}
              </div>
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0">
              <button onclick="openTapasApprove(${r.id},'${pn}','${tn}','${at}','${bn}')"
                style="padding:6px 12px;border-radius:8px;border:none;background:rgba(34,197,94,.15);color:#22c55e;font-weight:700;font-size:12px;cursor:pointer">
                <i class="bi bi-check-lg"></i> OK
              </button>
              <button onclick="rejectTapasRequest(${r.id})"
                style="padding:6px 12px;border-radius:8px;border:none;background:rgba(239,68,68,.12);color:#ef4444;font-weight:700;font-size:12px;cursor:pointer">
                <i class="bi bi-x-lg"></i>
              </button>
            </div>
          </div>`;
        }).join('');

    container.innerHTML = `
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px">
        <div style="flex:1;min-width:120px;background:var(--panel-2);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:14px 18px;text-align:center">
          <div style="font-size:24px;font-weight:800;color:#f97316">${totalTapas}</div>
          <div style="font-size:11px;color:var(--text-3);margin-top:3px;text-transform:uppercase;letter-spacing:.5px">Disponíveis</div>
        </div>
        <div style="flex:1;min-width:120px;background:var(--panel-2);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:14px 18px;text-align:center">
          <div style="font-size:24px;font-weight:800;color:var(--text-2)">${totalTapasUsed}</div>
          <div style="font-size:11px;color:var(--text-3);margin-top:3px;text-transform:uppercase;letter-spacing:.5px">Usados</div>
        </div>
        <div style="flex:1;min-width:120px;background:var(--panel-2);border:1px solid ${requests.length ? 'rgba(245,158,11,.3)' : 'rgba(255,255,255,.07)'};border-radius:10px;padding:14px 18px;text-align:center">
          <div style="font-size:24px;font-weight:800;color:${requests.length ? '#f59e0b' : 'var(--text-3)'}">${requests.length}</div>
          <div style="font-size:11px;color:var(--text-3);margin-top:3px;text-transform:uppercase;letter-spacing:.5px">Pendentes</div>
        </div>
      </div>

      ${requests.length ? `
      <div style="background:var(--panel-3);border:1px solid rgba(245,158,11,.25);border-radius:12px;padding:16px 18px;margin-bottom:20px">
        <div style="font-size:13px;font-weight:700;color:#f59e0b;margin-bottom:12px"><i class="bi bi-clock-fill"></i> Solicitações Pendentes (${requests.length})</div>
        ${requestsHtml}
      </div>` : ''}

      <div style="background:var(--panel-3);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:16px 18px">
        <div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:12px"><i class="bi bi-people-fill" style="color:#f97316"></i> Times — ${tapasLeague}</div>
        ${teams.length === 0
          ? '<div style="text-align:center;padding:20px;color:var(--text-3)">Nenhum time encontrado.</div>'
          : `<div style="display:grid;gap:8px">
              ${teams.map(t => `
                <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--panel-2);border:1px solid rgba(255,255,255,.06);border-radius:10px">
                  <div style="flex:1;min-width:0">
                    <div style="font-weight:700;font-size:13px;color:var(--text)">${escapeHtml(t.city)} ${escapeHtml(t.name)}</div>
                    <div style="font-size:11px;color:var(--text-3)">${escapeHtml(t.owner_name)}</div>
                  </div>
                  <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
                    <span style="font-size:11px;color:var(--text-3)">Tapas:</span>
                    <button onclick="quickTapasAdminChange(${t.id},'remove')" style="width:26px;height:26px;border-radius:6px;border:1px solid rgba(255,255,255,.1);background:none;color:var(--text-2);font-size:14px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center">−</button>
                    <span id="tapas-val-${t.id}" style="font-weight:800;font-size:15px;color:#f97316;min-width:24px;text-align:center">${parseInt(t.tapas || 0)}</span>
                    <button onclick="quickTapasAdminChange(${t.id},'add')" style="width:26px;height:26px;border-radius:6px;border:1px solid rgba(255,255,255,.1);background:none;color:var(--text-2);font-size:14px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center">+</button>
                    <span style="font-size:11px;color:var(--text-3);margin-left:4px">usados: ${parseInt(t.tapas_used || 0)}</span>
                  </div>
                </div>`).join('')}
             </div>`}
      </div>
    `;
  } catch(e) {
    container.innerHTML = `<div style="color:#ef4444;padding:16px">Erro ao carregar: ${e.error || 'Desconhecido'}</div>`;
  }
}

// keep legacy aliases used by action tile
function loadTapasTeams() { loadTapasData(); }
async function quickTapasChange(teamId, teamName, operation) { await quickTapasAdminChange(teamId, operation); }

// ========================================
// APROVAÇÃO DE USUÁRIOS
// ========================================

async function showUserApprovals() {
  appState.view = 'userApprovals';
  updateBreadcrumb();

  const _uaLeague = appState.currentLeague || null;
  const _uaBack = _uaLeague ? `showLeague('${_uaLeague}')` : 'showHome()';

  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center p-5"><div class="spinner-border text-orange" role="status"></div></div>';

  try {
    const data = await api('user-approval.php');
    const allUsers = data.users || [];
    const users = _uaLeague
      ? allUsers.filter(u => (u.league || '').toUpperCase() === _uaLeague)
      : allUsers;

    let html = `
      <div class="mb-4"><button class="btn btn-back" onclick="${_uaBack}"><i class="bi bi-arrow-left"></i> Voltar</button></div>
      <div class="row">
        <div class="col-12">
          <h2 class="text-white mb-4">
            <i class="bi bi-person-check text-orange me-2"></i>
            Aprovação de Usuários${_uaLeague ? ` — ${_uaLeague}` : ''}
          </h2>
        </div>
      </div>
    `;

    if (users.length === 0) {
      html += `
        <div class="alert alert-info">
          <i class="bi bi-info-circle me-2"></i>
          Não há usuários aguardando aprovação.
        </div>
      `;
    } else {
      html += `
        <div class="row g-4">
          ${users.map(user => {
            const createdDate = new Date(user.created_at);
            const dateStr = createdDate.toLocaleDateString('pt-BR') + ' ' + 
                          createdDate.toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'});
            
            return `
              <div class="col-md-6 col-lg-4">
                <div class="card bg-dark-panel border-orange h-100">
                  <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                      <div class="bg-gradient-orange rounded-circle d-flex align-items-center justify-content-center" 
                           style="width: 50px; height: 50px; min-width: 50px;">
                        <i class="bi bi-person-fill text-white fs-4"></i>
                      </div>
                      <div class="ms-3 flex-grow-1">
                        <h5 class="text-white mb-1">${escapeHtml(user.name || user.username || user.email || 'Usuário')}</h5>
                        <p class="text-light-gray mb-0 small">
                          <i class="bi bi-clock me-1"></i>${dateStr}
                        </p>
                      </div>
                    </div>
                    
                    <div class="mb-3">
                      <p class="text-light-gray mb-1 small">
                        <i class="bi bi-envelope me-2"></i>${user.email}
                      </p>
                    </div>
                    
                    <div class="d-flex gap-2">
                      <button class="btn btn-success flex-fill" onclick="approveUser(${user.id}, '${escapeHtml(user.name || user.username || '')}')">
                        <i class="bi bi-check-circle me-1"></i>Aprovar
                      </button>
                      <button class="btn btn-danger flex-fill" onclick="rejectUser(${user.id}, '${escapeHtml(user.name || user.username || '')}')">
                        <i class="bi bi-x-circle me-1"></i>Rejeitar
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            `;
          }).join('')}
        </div>
      `;
    }
    
    container.innerHTML = html;
  } catch (e) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar usuários: ' + (e.error || 'Desconhecido') + '</div>';
  }
}

async function toggleTrades(league, enabled) {
  try {
    await api('admin.php?action=league_settings', {
      method: 'PUT',
      body: JSON.stringify({ league, trades_enabled: enabled })
    });
    const onBtn  = document.getElementById(`tradesOnBtn_${league}`);
    const offBtn = document.getElementById(`tradesOffBtn_${league}`);
    const badge  = document.getElementById(`tradesBadge_${league}`);
    const on = enabled == 1;
    if (onBtn)  onBtn.className  = `btn btn-sm ${on ? 'btn-success' : 'btn-outline-success'}`;
    if (offBtn) offBtn.className = `btn btn-sm ${!on ? 'btn-danger' : 'btn-outline-danger'}`;
    if (badge) {
      badge.textContent = on ? 'Ativas' : 'Bloqueadas';
      badge.style.cssText = `font-size:10px;font-weight:700;padding:1px 7px;border-radius:999px;white-space:nowrap;${on
        ? 'background:rgba(37,198,119,.15);color:#25c677;border:1px solid rgba(37,198,119,.25)'
        : 'background:rgba(252,0,37,.12);color:var(--red);border:1px solid var(--border-red)'}`;
    }
    showAlert('success', `Trocas ${on ? 'ativadas' : 'desativadas'} para a liga ${league}!`);
  } catch (e) {
    showAlert('danger', 'Erro ao atualizar status de trades');
  }
}

async function toggleFA(league, enabled) {
  try {
    await api('admin.php?action=league_settings', {
      method: 'PUT',
      body: JSON.stringify({ league, fa_enabled: enabled })
    });
    const onBtn  = document.getElementById(`faOnBtn_${league}`);
    const offBtn = document.getElementById(`faOffBtn_${league}`);
    const badge  = document.getElementById(`faBadge_${league}`);
    const on = enabled == 1;
    if (onBtn)  onBtn.className  = `btn btn-sm ${on ? 'btn-success' : 'btn-outline-success'}`;
    if (offBtn) offBtn.className = `btn btn-sm ${!on ? 'btn-danger' : 'btn-outline-danger'}`;
    if (badge) {
      badge.textContent = on ? 'Ativa' : 'Bloqueada';
      badge.style.cssText = `font-size:10px;font-weight:700;padding:1px 7px;border-radius:999px;white-space:nowrap;${on
        ? 'background:rgba(37,198,119,.15);color:#25c677;border:1px solid rgba(37,198,119,.25)'
        : 'background:rgba(252,0,37,.12);color:var(--red);border:1px solid var(--border-red)'}`;
    }
    showAlert('success', `Free Agency ${on ? 'ativada' : 'desativada'} para a liga ${league}!`);
  } catch (e) {
    showAlert('danger', 'Erro ao atualizar status da Free Agency');
  }
}

async function approveUser(userId, username) {
  if (!confirm(`Deseja aprovar o usuário "${username}"?`)) return;
  
  try {
    const result = await api('user-approval.php', {
      method: 'PUT',
      body: JSON.stringify({
        user_id: userId,
        action: 'approve'
      })
    });
    
    if (result.success) {
      showAlert('success', `Usuário "${username}" aprovado com sucesso!`);
      await showUserApprovals(); // Recarrega a lista
      updatePendingUsersCount(); // Atualiza o badge no home
    }
  } catch (e) {
    showAlert('danger', 'Erro ao aprovar usuário: ' + (e.error || 'Desconhecido'));
  }
}

async function rejectUser(userId, username) {
  if (!confirm(`Deseja REJEITAR e EXCLUIR o usuário "${username}"?\n\nEsta ação não pode ser desfeita!`)) return;
  
  try {
    const result = await api('user-approval.php', {
      method: 'PUT',
      body: JSON.stringify({
        user_id: userId,
        action: 'reject'
      })
    });
    
    if (result.success) {
      showAlert('success', `Usuário "${username}" rejeitado e removido.`);
      await showUserApprovals(); // Recarrega a lista
      updatePendingUsersCount(); // Atualiza o badge no home
    }
  } catch (e) {
    showAlert('danger', 'Erro ao rejeitar usuário: ' + (e.error || 'Desconhecido'));
  }
}

async function updatePendingUsersCount() {
  try {
    const approvalData = await api('user-approval.php');
    const pendingCount = (approvalData.users || []).length;
    const badge = document.getElementById('pending-users-count');
    if (badge) {
      if (pendingCount > 0) {
        badge.textContent = pendingCount;
        badge.style.display = 'inline-block';
      } else {
        badge.style.display = 'none';
      }
    }
  } catch (e) {
    console.error('Erro ao atualizar contagem de usuários pendentes:', e);
  }
}

// ── Dispensas ─────────────────────────────────────────────────────────────────
let _dispensasCache = [];

async function showDispensas() {
  appState.view = 'dispensas';
  updateBreadcrumb();

  const _dispLeague = appState.currentLeague || null;
  const _dispBack = _dispLeague ? `showLeague('${_dispLeague}')` : 'showHome()';

  const container = document.getElementById('mainContainer');
  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back" onclick="${_dispBack}"><i class="bi bi-arrow-left"></i> Voltar</button>
      <span class="text-light-gray ms-3" style="font-size:14px;font-weight:600">Dispensas — ${_dispLeague || 'Liga'}</span>
    </div>
    <div class="panel mb-4">
      <div class="panel-title"><i class="bi bi-person-dash-fill"></i> Filtrar por Temporada</div>
      <div class="d-flex flex-wrap gap-3 align-items-end">
        <input type="hidden" id="dispensasLeague" value="${_dispLeague || ''}">
        <div>
          <label class="form-label text-light-gray small mb-1">Temporada</label>
          <select class="form-select form-select-sm" id="dispensasSeason" style="min-width:130px">
            <option value="">Todas</option>
          </select>
        </div>
      </div>
    </div>
    <div id="dispensasResult"></div>
  `;

  document.getElementById('dispensasSeason').addEventListener('change', renderDispensasTable);

  await loadDispensas();
}

async function loadDispensas() {
  const league = document.getElementById('dispensasLeague')?.value || 'ELITE';
  const resultEl = document.getElementById('dispensasResult');
  if (!resultEl) return;

  resultEl.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-orange" role="status"></div></div>';

  try {
    const data = await api(`free-agency.php?action=waivers&league=${encodeURIComponent(league)}`);
    _dispensasCache = data.waivers || [];

    // Populate season dropdown from data
    const seasonSel = document.getElementById('dispensasSeason');
    if (seasonSel) {
      const years = [...new Set(_dispensasCache.map(w => w.season_year).filter(Boolean))].sort((a, b) => b - a);
      seasonSel.innerHTML = '<option value="">Todas</option>' + years.map(y => `<option value="${y}">${y}</option>`).join('');
    }

    renderDispensasTable();
  } catch (err) {
    resultEl.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Erro ao carregar dispensas: ${escapeHtml(err.error || '')}</div>`;
  }
}

function renderDispensasTable() {
  const resultEl = document.getElementById('dispensasResult');
  if (!resultEl) return;

  const selectedYear = document.getElementById('dispensasSeason')?.value || '';
  const filtered = selectedYear
    ? _dispensasCache.filter(w => String(w.season_year) === selectedYear)
    : _dispensasCache;

  if (!filtered.length) {
    resultEl.innerHTML = '<div class="text-light-gray text-center py-4">Nenhuma dispensa encontrada para os filtros selecionados.</div>';
    return;
  }

  // Group by team, sort teams alphabetically
  const byTeam = {};
  filtered.forEach(w => {
    const team = w.original_team_name || 'Sem time';
    if (!byTeam[team]) byTeam[team] = [];
    byTeam[team].push(w);
  });
  const sortedTeams = Object.keys(byTeam).sort();

  let html = `<div class="panel">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <span class="text-light-gray small">${filtered.length} dispensa(s) encontrada(s) em ${sortedTeams.length} time(s)</span>
    </div>`;

  sortedTeams.forEach(team => {
    const players = byTeam[team].sort((a, b) => new Date(b.waived_at) - new Date(a.waived_at));
    html += `
      <div class="mb-4">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span style="font-weight:700;font-size:14px;color:var(--red)"><i class="bi bi-shield-fill me-1"></i>${escapeHtml(team)}</span>
          <span class="badge bg-secondary">${players.length}</span>
        </div>
        <div>
          ${players.map(w => `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border)">
              <span style="font-size:13px;font-weight:600;color:var(--text)">${escapeHtml(w.name || '-')}</span>
              <span style="font-size:11px;color:var(--text-3)">${w.season_year || '-'}</span>
            </div>`).join('')}
        </div>
      </div>
    `;
  });

  html += '</div>';
  resultEl.innerHTML = html;
}

// ══════════════════════════════════════════════
// PONTUAÇÃO POR TEMPORADA
// ══════════════════════════════════════════════

const PTS_REGULAR = [
  {v:0, l:'— Nenhum —'},
  {v:4, l:'1° Lugar (+4 pts)'},
  {v:3, l:'2°–4° Lugar (+3 pts)'},
  {v:2, l:'5°–8° Lugar (+2 pts)'}
];
// valores cumulativos: 1ªRod(+1) + Semi(+2=3) + FinConf(+3=6) + Vice(+2=8) + Campeão(+3=11)
const PTS_PLAYOFF = [
  {v:0,  l:'— Não participou —'},
  {v:1,  l:'1ª Rodada (+1 pt)'},
  {v:3,  l:'Semifinalista (+3 pts acum.)'},
  {v:6,  l:'Final de Conferência (+6 pts acum.)'},
  {v:8,  l:'Vice-Campeão (+8 pts acum.)'},
  {v:11, l:'Campeão (+11 pts acum.)'}
];
const PTS_AWARDS = ['MVP','DPOY','MIP','6° Homem','ROY'];

function buildPtsForm(seasonId, league, leagueTeams, inputClass) {
  const sid = String(seasonId);
  const isElite = (league||'').toUpperCase() === 'ELITE';
  const sel = 'background:var(--panel-2);border:1px solid var(--border-md);border-radius:7px;padding:3px 5px;color:var(--text);font-size:11px;flex-shrink:0';

  const teamRows = leagueTeams.map(t => `
    <div data-team-name="${(t.team_name||'').toLowerCase()}" style="display:flex;align-items:center;gap:6px;padding:5px 0;border-bottom:1px solid var(--border)">
      <span style="font-size:12px;color:var(--text);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escapeHtml(t.team_name||'')}</span>
      <select class="pts-reg-sel" data-team-id="${t.team_id}" onchange="calcPtsPreview('${sid}')" style="${sel};max-width:150px">
        ${PTS_REGULAR.map(o=>`<option value="${o.v}">${o.l}</option>`).join('')}
      </select>
      <select class="pts-play-sel" data-team-id="${t.team_id}" onchange="calcPtsPreview('${sid}')" style="${sel};max-width:190px">
        ${PTS_PLAYOFF.map(o=>`<option value="${o.v}">${o.l}</option>`).join('')}
      </select>
    </div>`).join('');

  const awardRows = PTS_AWARDS.map(a => `
    <div style="display:flex;align-items:center;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--border)">
      <span style="font-size:12px;color:var(--text)">${escapeHtml(a)} <span style="font-size:10px;color:var(--text-3)">(+1 pt)</span></span>
      <select class="pts-award-sel" data-award="${escapeHtml(a)}" onchange="calcPtsPreview('${sid}')" style="${sel};max-width:200px">
        <option value="0">— Nenhum —</option>
        ${leagueTeams.map(t=>`<option value="${t.team_id}">${escapeHtml(t.team_name||'')}</option>`).join('')}
      </select>
    </div>`).join('');

  const nbaCupHtml = isElite ? `
    <div style="margin-top:12px;padding:10px;background:rgba(245,158,11,.05);border:1px solid rgba(245,158,11,.2);border-radius:8px">
      <div style="font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#f59e0b;margin-bottom:6px">NBA Cup — ELITE</div>
      <div style="display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:12px;color:var(--text)">Campeão NBA Cup <span style="font-size:10px;color:var(--text-3)">(+2 pts)</span></span>
        <select class="pts-nbacup-sel" onchange="calcPtsPreview('${sid}')" style="${sel};max-width:200px">
          <option value="0">— Nenhum —</option>
          ${leagueTeams.map(t=>`<option value="${t.team_id}">${escapeHtml(t.team_name||'')}</option>`).join('')}
        </select>
      </div>
    </div>` : '';

  const hiddenInputs = leagueTeams.map(t =>
    `<input type="hidden" class="${inputClass}" data-team-id="${t.team_id}" value="0">`).join('');

  return `
    <div style="font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin-bottom:6px">Temporada Regular + Playoffs</div>
    <div style="font-size:10px;color:var(--text-3);display:flex;gap:6px;padding-bottom:4px;border-bottom:1px solid var(--border);margin-bottom:2px">
      <span style="flex:1">Time</span><span style="width:150px;text-align:center">T. Regular</span><span style="width:190px;text-align:center">Playoffs</span>
    </div>
    ${teamRows}
    <div style="margin-top:12px">
      <div style="font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin-bottom:6px">Prêmios Individuais</div>
      ${awardRows}
    </div>
    ${nbaCupHtml}
    <div style="margin-top:12px;background:var(--panel-3);border-radius:8px;padding:10px">
      <div style="font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin-bottom:6px">Prévia — Total por Time</div>
      ${leagueTeams.map(t=>`
        <div style="display:flex;justify-content:space-between;padding:2px 0">
          <span style="font-size:11px;color:var(--text-2)">${escapeHtml(t.team_name||'')}</span>
          <span class="pts-pv-val" data-tid="${t.team_id}" style="font-size:11px;font-weight:700;color:var(--text-3)">0 pts</span>
        </div>`).join('')}
    </div>
    ${hiddenInputs}`;
}

function calcPtsPreview(seasonId) {
  const sid = String(seasonId);
  const newForm  = document.getElementById(`pts-form-${sid}`);
  const editForm = document.getElementById(`pts-edit-form-${sid}`);
  const form = (editForm && editForm.style.display !== 'none') ? editForm : newForm;
  if (!form) return;

  const totals = {};
  form.querySelectorAll('.pts-reg-sel').forEach(sel => {
    const tid = sel.dataset.teamId;
    totals[tid] = (totals[tid]||0) + parseInt(sel.value||'0', 10);
  });
  form.querySelectorAll('.pts-play-sel').forEach(sel => {
    const tid = sel.dataset.teamId;
    totals[tid] = (totals[tid]||0) + parseInt(sel.value||'0', 10);
  });
  form.querySelectorAll('.pts-award-sel').forEach(sel => {
    if (sel.value && sel.value !== '0') totals[sel.value] = (totals[sel.value]||0) + 1;
  });
  form.querySelectorAll('.pts-nbacup-sel').forEach(sel => {
    if (sel.value && sel.value !== '0') totals[sel.value] = (totals[sel.value]||0) + 2;
  });

  form.querySelectorAll('input[type="hidden"][data-team-id]').forEach(inp => {
    const tid = inp.dataset.teamId;
    const pts = totals[tid] || 0;
    inp.value = pts;
  });
  form.querySelectorAll('.pts-pv-val').forEach(el => {
    const tid = el.dataset.tid;
    const pts = totals[tid] || 0;
    el.textContent = `${pts} pts`;
    el.style.color = pts > 0 ? 'var(--red)' : 'var(--text-3)';
  });
}

async function showPointsManagement(league) {
  league = league || appState.currentLeague || 'ELITE';
  appState.view = 'pontuacao';
  updateBreadcrumb();
  const container = document.getElementById('mainContainer');
  const _ptsBack = appState.currentLeague ? `showLeague('${appState.currentLeague}')` : 'showHome()';

  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back me-2" onclick="${_ptsBack}"><i class="bi bi-arrow-left"></i> Voltar</button>
      <span class="text-light-gray" style="font-size:14px;font-weight:600">Pontuação por Time — ${league}</span>
    </div>
    <div id="ptsMgmtContent">
      <div class="text-center py-5"><div class="spinner-border text-orange"></div></div>
    </div>`;

  let data;
  try {
    data = await api(`history-points.php?action=get_league_seasons_overview&league=${encodeURIComponent(league)}`);
  } catch (e) {
    document.getElementById('ptsMgmtContent').innerHTML =
      `<div class="alert alert-danger">Erro: ${escapeHtml(e.error || 'Falha ao carregar dados')}</div>`;
    return;
  }

  const seasons     = data.seasons      || [];
  const leagueTeams = data.league_teams || [];

  if (!seasons.length) {
    document.getElementById('ptsMgmtContent').innerHTML =
      `<div class="alert alert-info">Nenhuma temporada encontrada para ${league}.</div>`;
    return;
  }

  const fmtTitle = s => s.year ? String(s.year)
    : ([s.sprint_number ? `Sprint ${s.sprint_number}` : '', s.season_number ? `Temp. ${s.season_number}` : ''].filter(Boolean).join(' · ') || 'Temporada');

  // Mais recente no topo
  const html = [...seasons].reverse().map(s => {
    const title = fmtTitle(s);

    if (s.points_registered) {
      // Inputs de edição pré-preenchidos com valores atuais
      const editInputs = leagueTeams.map(t => {
        const existing = (s.teams || []).find(st => String(st.team_id) === String(t.team_id));
        return `
          <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--border)">
            <span style="font-size:12px;color:var(--text)">${escapeHtml(t.team_name||'')}</span>
            <input type="number" class="form-control form-control-sm pts-edit-input" data-team-id="${t.team_id}"
              value="${existing ? existing.points : 0}" min="0" style="max-width:90px">
          </div>`;
      }).join('');

      return `
        <div class="pun-card mb-2" id="pts-season-${s.season_id}">
          <div class="pun-card-head">
            <div class="pun-card-title">
              <i class="bi bi-trophy-fill" style="color:var(--red);margin-right:6px"></i>${escapeHtml(title)}
            </div>
            <div class="d-flex gap-2 align-items-center flex-shrink-0">
              <span class="pun-badge" style="background:rgba(34,197,94,.1);color:#22c55e;border-color:rgba(34,197,94,.3)">Registrado</span>
              <button class="btn-ghost" style="padding:3px 10px;font-size:11px" onclick="toggleEditPtsForm(${s.season_id})">
                <i class="bi bi-pencil me-1"></i>Editar
              </button>
              <button class="btn-ghost" style="padding:3px 10px;font-size:11px;color:#ef4444"
                onclick="deletePtsMgmt(${s.season_id}, '${escapeHtml(league)}')">
                <i class="bi bi-trash3 me-1"></i>Limpar
              </button>
            </div>
          </div>
          <div id="pts-view-${s.season_id}" style="margin-top:8px">
            ${(s.teams||[]).map((t, ti) => `
              <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--border)">
                <div style="display:flex;align-items:center;gap:8px">
                  <span style="font-size:11px;color:var(--text-3);width:20px;text-align:right">${ti + 1}°</span>
                  <span style="font-size:13px;color:var(--text)">${escapeHtml(t.team_name||'')}</span>
                </div>
                <span style="font-size:13px;font-weight:700;color:var(--red)">${t.points} pts</span>
              </div>`).join('')}
          </div>
          <div id="pts-edit-form-${s.season_id}" style="display:none;margin-top:12px">
            ${editInputs}
            <div class="d-flex gap-2 mt-3">
              <button class="btn-ghost" style="color:#22c55e"
                onclick="saveEditPtsMgmt(${s.season_id}, '${escapeHtml(league)}')">
                <i class="bi bi-save me-1"></i>Salvar
              </button>
              <button class="btn-ghost" onclick="toggleEditPtsForm(${s.season_id})">Cancelar</button>
            </div>
          </div>
        </div>`;
    } else {
      return `
        <div class="pun-card mb-2" id="pts-season-${s.season_id}">
          <div class="pun-card-head">
            <div class="pun-card-title">
              <i class="bi bi-clipboard-check" style="color:var(--text-3);margin-right:6px"></i>${escapeHtml(title)}
            </div>
            <div class="d-flex gap-2 align-items-center flex-shrink-0">
              <span class="pun-badge pun-badge-off">Pendente</span>
              <button class="btn-ghost" style="padding:3px 10px;font-size:11px"
                onclick="togglePtsForm(${s.season_id})">
                <i class="bi bi-plus-circle me-1"></i>Registrar
              </button>
            </div>
          </div>
          <div id="pts-form-${s.season_id}" style="display:none;margin-top:12px">
            ${buildPtsForm(s.season_id, league, leagueTeams, 'pts-mgmt-input')}
            <div class="d-flex gap-2 mt-3">
              <button class="btn-ghost" style="color:#22c55e"
                onclick="savePtsMgmt(${s.season_id}, '${escapeHtml(league)}')">
                <i class="bi bi-save me-1"></i>Salvar
              </button>
              <button class="btn-ghost" onclick="togglePtsForm(${s.season_id})">Cancelar</button>
            </div>
          </div>
        </div>`;
    }
  }).join('');

  document.getElementById('ptsMgmtContent').innerHTML =
    html || '<div class="alert alert-info">Nenhuma temporada encontrada.</div>';
}

function togglePtsForm(seasonId) {
  const form = document.getElementById(`pts-form-${seasonId}`);
  if (form) form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

async function savePtsMgmt(seasonId, league) {
  calcPtsPreview(String(seasonId));
  const card = document.getElementById(`pts-season-${seasonId}`);
  if (!card) return;
  const inputs = card.querySelectorAll('.pts-mgmt-input');
  const team_points = Array.from(inputs).map(inp => ({
    team_id: parseInt(inp.dataset.teamId, 10),
    points:  parseInt(inp.value || '0', 10)
  }));

  try {
    await api('history-points.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'save_season_points', season_id: seasonId, league, team_points })
    });
    showAlert('success', 'Pontuação salva com sucesso!');
    showPointsManagement(league);
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao salvar pontuação');
  }
}

function toggleEditPtsForm(seasonId) {
  const view = document.getElementById(`pts-view-${seasonId}`);
  const form = document.getElementById(`pts-edit-form-${seasonId}`);
  if (!view || !form) return;
  const isOpen = form.style.display !== 'none';
  view.style.display = isOpen ? 'block' : 'none';
  form.style.display = isOpen ? 'none' : 'block';
}

async function saveEditPtsMgmt(seasonId, league) {
  const card = document.getElementById(`pts-season-${seasonId}`);
  if (!card) return;
  const inputs = card.querySelectorAll('.pts-edit-input');
  const team_points = Array.from(inputs).map(inp => ({
    team_id: parseInt(inp.dataset.teamId, 10),
    points:  parseInt(inp.value || '0', 10)
  }));

  try {
    await api('history-points.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'edit_season_points', season_id: seasonId, league, team_points })
    });
    showAlert('success', 'Pontuação atualizada com sucesso!');
    showPointsManagement(league);
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao atualizar pontuação');
  }
}

async function deletePtsMgmt(seasonId, league) {
  if (!confirm(`Tem certeza? Isso irá ZERAR todos os pontos desta temporada para a liga ${league} e liberar os locks. O lock do playoff também será removido, permitindo novo cadastro.`)) return;

  try {
    await api('history-points.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'delete_season_points', season_id: seasonId, league })
    });
    showAlert('success', 'Pontos da temporada zerados. Os locks foram liberados.');
    showPointsManagement(league);
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao zerar pontuação');
  }
}

// ── Registro de Pontuação (formulário inteligente) ───────────────────

async function showRegistroPontuacao(league) {
  league = league || appState.currentLeague || 'ELITE';
  appState.view = 'pontuacao';
  updateBreadcrumb();

  const container = document.getElementById('mainContainer');
  const back = appState.currentLeague ? `showLeague('${appState.currentLeague}')` : 'showHome()';

  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back me-2" onclick="${back}"><i class="bi bi-arrow-left"></i> Voltar</button>
      <span class="text-light-gray" style="font-size:14px;font-weight:600">Pontuação da Temporada — ${league}</span>
    </div>
    <div id="regPtsContent"><div class="text-center py-5"><div class="spinner-border text-orange"></div></div></div>`;

  let overviewData;
  try {
    overviewData = await api(`history-points.php?action=get_league_seasons_overview&league=${encodeURIComponent(league)}`);
  } catch(e) {
    document.getElementById('regPtsContent').innerHTML = `<div class="alert alert-danger">Erro ao carregar dados.</div>`;
    return;
  }

  const leagueTeams = overviewData?.league_teams || [];
  const seasons     = overviewData?.seasons     || [];

  // Pega a temporada mais recente ainda não registrada
  const pending = [...seasons].reverse().find(s => !s.points_registered);
  // Também carrega a já registrada mais recente para referência
  const registered = [...seasons].reverse().find(s => s.points_registered);

  if (!leagueTeams.length) {
    document.getElementById('regPtsContent').innerHTML = `<div class="alert alert-warning">Nenhum time encontrado para ${league}.</div>`;
    return;
  }

  const fmtTitle = s => [
    s.sprint_number ? `Sprint ${s.sprint_number}` : '',
    s.season_number ? `Temporada ${s.season_number}` : '',
    s.year          ? String(s.year)                 : ''
  ].filter(Boolean).join(' · ');

  const sel = (cls, tid, opts) => `
    <select class="${cls}" data-team-id="${tid}" onchange="_regPtsRecalc()"
      style="background:var(--panel-2);border:1px solid var(--border);border-radius:8px;padding:5px 9px;font-size:12px;color:var(--text)">
      ${opts}
    </select>`;

  const regularOpts = `
    <option value="0" selected>— Nenhum —</option>
    <option value="4">1º Lugar (+4 pts)</option>
    <option value="3">2º ao 4º (+3 pts)</option>
    <option value="2">5º ao 8º (+2 pts)</option>`;

  // valores CUMULATIVOS: 1ªRod+1 / Semi+2=3 / FinConf+3=6 / Vice+2=8 / Camp+3=11
  const playoffOpts = `
    <option value="0" selected>Não participou (+0)</option>
    <option value="1">1ª Rodada (+1 pt)</option>
    <option value="3">Semifinalista (+3 pts)</option>
    <option value="6">Finalista de Conferência (+6 pts)</option>
    <option value="8">Vice-Campeão (+8 pts)</option>
    <option value="11">Campeão (+11 pts)</option>`;

  const teamOpts = `<option value="">— Nenhum —</option>` +
    leagueTeams.map(t => `<option value="${t.team_id}">${escapeHtml(t.team_name || '')}</option>`).join('');

  const awards = [
    { key: 'mvp',   label: 'MVP'      },
    { key: 'dpoy',  label: 'DPOY'     },
    { key: 'mip',   label: 'MIP'      },
    { key: 'sexto', label: '6º Homem' },
    { key: 'roy',   label: 'ROY'      },
  ];

  const teamsHtml = leagueTeams.map(t => `
    <div class="pun-card mb-2" style="padding:12px 14px">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <span style="font-size:13px;font-weight:700;color:var(--text);min-width:130px">${escapeHtml(t.team_name || '')}</span>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex:1">
          <div>
            <div style="font-size:10px;color:var(--text-3);margin-bottom:3px;text-transform:uppercase;letter-spacing:.05em">Temporada Regular</div>
            ${sel('reg-season-sel', t.team_id, regularOpts)}
          </div>
          <div>
            <div style="font-size:10px;color:var(--text-3);margin-bottom:3px;text-transform:uppercase;letter-spacing:.05em">Playoffs</div>
            ${sel('playoff-sel', t.team_id, playoffOpts)}
          </div>
        </div>
        <div style="min-width:60px;text-align:right">
          <div style="font-size:10px;color:var(--text-3);margin-bottom:3px;text-transform:uppercase">Total</div>
          <span id="rpt-${t.team_id}" style="font-size:20px;font-weight:800;color:var(--red)">2</span>
          <span style="font-size:11px;color:var(--text-3)"> pts</span>
        </div>
      </div>
    </div>`).join('');

  const awardsHtml = awards.map(a => `
    <div style="display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border)">
      <span style="font-size:13px;color:var(--text);font-weight:600">${a.label}
        <span style="color:#22c55e;font-weight:400;font-size:11px"> +1 pt</span>
      </span>
      <select class="award-sel" data-award="${a.key}" onchange="_regPtsRecalc()"
        style="background:var(--panel-2);border:1px solid var(--border);border-radius:8px;padding:5px 9px;font-size:12px;color:var(--text);min-width:180px">
        ${teamOpts}
      </select>
    </div>`).join('');

  const nbaCupHtml = league === 'ELITE' ? `
    <div class="mt-3">
      <div style="font-size:11px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">
        <i class="bi bi-trophy-fill" style="color:#f59e0b;margin-right:5px"></i>NBA Cup <span style="font-size:10px;font-weight:400;color:var(--text-3)">(Somente ELITE)</span>
      </div>
      <div class="pun-card" style="padding:4px 14px">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:7px 0">
          <span style="font-size:13px;color:var(--text);font-weight:600">Campeão NBA Cup
            <span style="color:#f59e0b;font-weight:400;font-size:11px"> +2 pts</span>
          </span>
          <select id="nbaCupWinner" onchange="_regPtsRecalc()"
            style="background:var(--panel-2);border:1px solid var(--border);border-radius:8px;padding:5px 9px;font-size:12px;color:var(--text);min-width:180px">
            ${teamOpts}
          </select>
        </div>
      </div>
    </div>` : '';

  let html = '';

  if (pending) {
    html += `
      <div class="panel mb-3">
        <div class="panel-header">
          <div class="panel-title"><i class="bi bi-clipboard-data-fill" style="color:#10b981"></i> ${escapeHtml(fmtTitle(pending))}</div>
          <span class="pun-badge pun-badge-off">Pendente</span>
        </div>
        <div style="margin-bottom:20px">
          <div style="font-size:11px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px">Times</div>
          ${teamsHtml}
        </div>
        <div>
          <div style="font-size:11px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">
            <i class="bi bi-award-fill" style="color:#22c55e;margin-right:5px"></i>Prêmios Individuais
          </div>
          <div class="pun-card" style="padding:4px 14px">${awardsHtml}</div>
        </div>
        ${nbaCupHtml}
        <div class="mt-4">
          <button class="btn-orange" onclick="_regPtsSave(${pending.season_id}, '${league}')">
            <i class="bi bi-save me-2"></i>Registrar Pontuação
          </button>
        </div>
      </div>`;
  } else {
    html += `<div class="alert alert-info"><i class="bi bi-check-circle me-2"></i>Todas as temporadas desta liga já têm pontuação registrada. Use "Pontuação por Time" para editar.</div>`;
  }

  // Referência visual das regras
  html += `
    <div class="panel">
      <div class="panel-title"><i class="bi bi-calculator-fill" style="color:var(--text-3)"></i> Sistema de Pontuação</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;font-size:12px">
        <div>
          <div style="color:#eab308;font-weight:700;margin-bottom:6px">Playoffs</div>
          <div style="color:var(--text-2);line-height:2">
            Campeão: <strong style="color:var(--text)">+5 pts</strong><br>
            Vice-Campeão: <strong style="color:var(--text)">+2 pts</strong><br>
            Finalista Conf.: <strong style="color:var(--text)">+3 pts</strong><br>
            Semifinalista: <strong style="color:var(--text)">+2 pts</strong><br>
            1ª Rodada: <strong style="color:var(--text)">+1 pt</strong>
          </div>
        </div>
        <div>
          <div style="color:#06b6d4;font-weight:700;margin-bottom:6px">Temporada Regular</div>
          <div style="color:var(--text-2);line-height:2">
            1º Lugar: <strong style="color:var(--text)">+4 pts</strong><br>
            2º ao 4º: <strong style="color:var(--text)">+3 pts</strong><br>
            5º ao 8º: <strong style="color:var(--text)">+2 pts</strong>
          </div>
        </div>
        <div>
          <div style="color:#22c55e;font-weight:700;margin-bottom:6px">Prêmios Individuais</div>
          <div style="color:var(--text-2);line-height:2">
            MVP / DPOY / MIP / 6º Homem / ROY:<br>
            <strong style="color:var(--text)">+1 pt cada</strong>
          </div>
        </div>
        ${league === 'ELITE' ? `
        <div>
          <div style="color:#f59e0b;font-weight:700;margin-bottom:6px">NBA Cup <span style="font-size:10px;color:var(--text-3)">(ELITE)</span></div>
          <div style="color:var(--text-2);line-height:2">
            Campeão: <strong style="color:var(--text)">+2 pts</strong>
          </div>
        </div>` : ''}
      </div>
    </div>`;

  document.getElementById('regPtsContent').innerHTML = html;
  _regPtsRecalc();
}

function _regPtsRecalcForTeam(teamId) {
  let pts = 0;
  const reg = document.querySelector(`.reg-season-sel[data-team-id="${teamId}"]`);
  if (reg) pts += parseInt(reg.value || '0', 10);
  const po = document.querySelector(`.playoff-sel[data-team-id="${teamId}"]`);
  if (po) pts += parseInt(po.value || '0', 10);
  document.querySelectorAll('.award-sel').forEach(s => {
    if (String(s.value) === String(teamId)) pts += 1;
  });
  const nbaCup = document.getElementById('nbaCupWinner');
  if (nbaCup && String(nbaCup.value) === String(teamId)) pts += 2;
  const el = document.getElementById(`rpt-${teamId}`);
  if (el) el.textContent = pts;
  return pts;
}

function _regPtsRecalc() {
  document.querySelectorAll('.reg-season-sel').forEach(s => _regPtsRecalcForTeam(s.dataset.teamId));
}

async function _regPtsSave(seasonId, league) {
  const teamPoints = [];
  document.querySelectorAll('.reg-season-sel').forEach(s => {
    const tid = parseInt(s.dataset.teamId, 10);
    teamPoints.push({ team_id: tid, points: _regPtsRecalcForTeam(tid) });
  });

  const summary = teamPoints.map(tp => {
    const name = document.querySelector(`.reg-season-sel[data-team-id="${tp.team_id}"]`)?.closest('.pun-card')?.querySelector('span')?.textContent || tp.team_id;
    return `${name}: ${tp.points} pts`;
  }).join('\n');

  if (!confirm(`Confirmar registro de pontuação para ${league}?\n\n${summary}\n\nEsta ação não poderá ser desfeita.`)) return;

  try {
    await api('history-points.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'save_season_points', season_id: seasonId, league, team_points: teamPoints })
    });
    showAlert('success', 'Pontuação registrada com sucesso!');
    showRegistroPontuacao(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao registrar pontuação');
  }
}

// ── FBA SERASA Admin ─────────────────────────────────────────────────

async function showSerasaAdmin() {
  const league = appState.currentLeague || 'ELITE';
  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border" style="color:var(--red)"></div></div>';

  try {
    // Gera avisos automáticos para trades pendentes > 24h
    let novoAvisos = 0;
    try {
      const chk = await api('admin.php?action=check_overdue_trades', {
        method: 'POST',
        body: JSON.stringify({ league })
      });
      novoAvisos = chk.avisos_gerados || 0;
    } catch (_) {}

    const data = await api(`admin.php?action=teams&league=${league}`);
    const teams = (data.teams || []).slice().sort((a, b) => (parseInt(b.avisos_count||0)) - (parseInt(a.avisos_count||0)));

    const getScore = (n) => {
      if (n <= 2) return { label: 'Excelente', color: '#22c55e', bg: 'rgba(34,197,94,.10)',  border: 'rgba(34,197,94,.3)'  };
      if (n <= 4) return { label: 'Bom',       color: '#3b82f6', bg: 'rgba(59,130,246,.10)', border: 'rgba(59,130,246,.3)' };
      if (n <= 6) return { label: 'Regular',   color: '#eab308', bg: 'rgba(234,179,8,.10)',  border: 'rgba(234,179,8,.3)'  };
      if (n <= 8) return { label: 'Ruim',      color: '#f97316', bg: 'rgba(249,115,22,.10)', border: 'rgba(249,115,22,.3)' };
      return              { label: 'Péssimo',  color: '#ef4444', bg: 'rgba(239,68,68,.10)',  border: 'rgba(239,68,68,.3)'  };
    };

    const rows = teams.map(t => {
      const n = parseInt(t.avisos_count || 0);
      const s = getScore(n);
      return `
        <div class="pun-card" style="display:flex;align-items:center;gap:12px;padding:10px 14px" id="serasa-row-${t.id}">
          <img src="${escapeHtml(t.photo_url || '/img/default-team.png')}" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md)" onerror="this.src='/img/default-team.png'">
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600;color:var(--text)">${escapeHtml(t.city)} ${escapeHtml(t.name)}</div>
            <div style="font-size:11px;color:var(--text-3)">${escapeHtml(t.owner_name)}</div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;flex-shrink:0" id="serasa-info-${t.id}">
            <span style="font-size:11px;color:var(--text-3)">${n} aviso${n !== 1 ? 's' : ''}</span>
            <span style="padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;color:${s.color};background:${s.bg};border:1px solid ${s.border}">${s.label}</span>
            <button class="btn-ghost" style="padding:2px 8px;font-size:11px" title="Editar avisos" onclick="_serasaEditAvisos(${t.id}, '${escapeHtml(league)}', ${n})"><i class="bi bi-pencil"></i></button>
          </div>
        </div>`;
    }).join('');

    const legend = [
      ['#22c55e','rgba(34,197,94,.3)','Excelente (0–2)'],
      ['#3b82f6','rgba(59,130,246,.3)','Bom (3–4)'],
      ['#eab308','rgba(234,179,8,.3)','Regular (5–6)'],
      ['#f97316','rgba(249,115,22,.3)','Ruim (7–8)'],
      ['#ef4444','rgba(239,68,68,.3)','Péssimo (9+)'],
    ].map(([c, b, l]) => `<span style="padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;color:${c};background:${c}1a;border:1px solid ${b}">${l}</span>`).join('');

    const novoAvisoBanner = novoAvisos > 0
      ? `<div style="background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#ef4444;display:flex;align-items:center;gap:8px">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span><strong>${novoAvisos} aviso${novoAvisos > 1 ? 's' : ''}</strong> gerado${novoAvisos > 1 ? 's' : ''} automaticamente por trade${novoAvisos > 1 ? 's' : ''} pendente${novoAvisos > 1 ? 's' : ''} há mais de 24h.</span>
        </div>`
      : '';

    container.innerHTML = `
      <div class="mb-4"><button class="btn btn-back" onclick="showLeague('${league}')"><i class="bi bi-arrow-left"></i> Voltar</button></div>
      ${novoAvisoBanner}
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title"><i class="bi bi-shield-check" style="color:#8b5cf6"></i> FBA SERASA — ${league}</div>
          <span style="font-size:12px;color:var(--text-3)">${teams.length} times</span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">${legend}</div>
        <div>${rows || '<p class="empty-state">Nenhum time encontrado.</p>'}</div>
      </div>`;
  } catch (e) {
    container.innerHTML = `<div class="alert alert-danger">Erro: ${escapeHtml(e.error || 'Desconhecido')}</div>`;
  }
}

function _serasaEditAvisos(teamId, league, current) {
  const infoEl = document.getElementById(`serasa-info-${teamId}`);
  if (!infoEl) return;

  infoEl.innerHTML = `
    <input id="_serasaInput_${teamId}" type="number" min="0" max="99" value="${current}"
      style="width:60px;padding:3px 6px;font-size:13px;background:var(--panel-2);border:1px solid var(--border-red);border-radius:8px;color:var(--text);text-align:center">
    <button class="btn-ghost" style="padding:3px 10px;font-size:12px;color:#22c55e;border-color:rgba(34,197,94,.3)"
      onclick="_serasaSaveAvisos(${teamId}, '${league}')"><i class="bi bi-check-lg"></i></button>
    <button class="btn-ghost" style="padding:3px 10px;font-size:12px"
      onclick="showSerasaAdmin()"><i class="bi bi-x-lg"></i></button>`;

  document.getElementById(`_serasaInput_${teamId}`)?.focus();
}

async function _adminCardAvisosAdj(teamId, league, delta, btn) {
  const spanEl = document.getElementById(`avisos-count-${teamId}`);
  const current = parseInt(spanEl?.textContent ?? '0', 10);
  const newCount = Math.max(0, current + delta);
  if (newCount === current) return;
  btn.disabled = true;
  try {
    const res = await api('admin.php?action=set_team_avisos', {
      method: 'POST',
      body: JSON.stringify({ team_id: teamId, league, count: newCount })
    });
    const n = res.count;
    if (spanEl) {
      spanEl.textContent = n;
      spanEl.style.color = n > 0 ? '#f43f5e' : 'var(--text-2)';
    }
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao atualizar avisos');
  } finally {
    btn.disabled = false;
  }
}

async function _serasaSaveAvisos(teamId, league) {
  const input = document.getElementById(`_serasaInput_${teamId}`);
  const count = parseInt(input?.value ?? '-1', 10);
  if (isNaN(count) || count < 0) { showAlert('danger', 'Número inválido'); return; }

  try {
    const res = await api('admin.php?action=set_team_avisos', {
      method: 'POST',
      body: JSON.stringify({ team_id: teamId, league, count })
    });
    const n = res.count;
    const getScore = (n) => {
      if (n <= 2) return { label: 'Excelente', color: '#22c55e', bg: 'rgba(34,197,94,.10)',  border: 'rgba(34,197,94,.3)'  };
      if (n <= 4) return { label: 'Bom',       color: '#3b82f6', bg: 'rgba(59,130,246,.10)', border: 'rgba(59,130,246,.3)' };
      if (n <= 6) return { label: 'Regular',   color: '#eab308', bg: 'rgba(234,179,8,.10)',  border: 'rgba(234,179,8,.3)'  };
      if (n <= 8) return { label: 'Ruim',      color: '#f97316', bg: 'rgba(249,115,22,.10)', border: 'rgba(249,115,22,.3)' };
      return              { label: 'Péssimo',  color: '#ef4444', bg: 'rgba(239,68,68,.10)',  border: 'rgba(239,68,68,.3)'  };
    };
    const s = getScore(n);
    const infoEl = document.getElementById(`serasa-info-${teamId}`);
    if (infoEl) infoEl.innerHTML = `
      <span style="font-size:11px;color:var(--text-3)">${n} aviso${n !== 1 ? 's' : ''}</span>
      <span style="padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;color:${s.color};background:${s.bg};border:1px solid ${s.border}">${s.label}</span>
      <button class="btn-ghost" style="padding:2px 8px;font-size:11px" title="Editar avisos" onclick="_serasaEditAvisos(${teamId}, '${league}', ${n})"><i class="bi bi-pencil"></i></button>`;
    showAlert('success', 'Avisos atualizados');
  } catch (e) {
    showAlert('danger', e.error || 'Erro ao salvar');
  }
}

// ── Draft Admin ──────────────────────────────────────────────────────

let _adminDraftTimerInterval = null;

function _startAdminDraftTimer(deadlineTs, elId) {
  clearInterval(_adminDraftTimerInterval);
  const update = () => {
    const el = document.getElementById(elId);
    if (!el) { clearInterval(_adminDraftTimerInterval); return; }
    const remaining = deadlineTs - Math.floor(Date.now() / 1000);
    if (remaining <= 0) {
      el.textContent = '⏱ Expirado';
      el.style.color = '#ef4444';
      clearInterval(_adminDraftTimerInterval);
      return;
    }
    const m = String(Math.floor(remaining / 60)).padStart(2, '0');
    const s = String(remaining % 60).padStart(2, '0');
    el.textContent = `⏱ ${m}:${s}`;
    el.style.color = remaining < 300 ? '#f59e0b' : '#22c55e';
    if (el.style.border) el.style.borderColor = remaining < 300 ? 'rgba(245,158,11,.2)' : 'rgba(34,197,94,.2)';
  };
  update();
  _adminDraftTimerInterval = setInterval(update, 1000);
}

async function showAdminDraft(league) {
  league = league || appState.currentLeague;
  appState.view = 'draft';
  updateBreadcrumb();

  const container = document.getElementById('mainContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border" style="color:var(--red)"></div></div>';

  const back = `<button class="btn btn-back" onclick="showLeague('${league}')"><i class="bi bi-arrow-left"></i> Voltar</button>`;

  try {
    const [seasonData, draftData] = await Promise.all([
      api(`seasons.php?action=current_season&league=${league}`).catch(() => ({ season: null })),
      api(`draft.php?action=active_draft&league=${league}`).catch(() => ({ draft: null }))
    ]);

    const season = seasonData.season;
    const draft = draftData.draft;

    if (!season) {
      container.innerHTML = `
        <div class="mb-4">${back}</div>
        <div class="panel"><div style="padding:20px">
          <p class="empty-state">Nenhuma temporada ativa para ${league}. Crie uma temporada primeiro em Temporadas.</p>
        </div></div>`;
      return;
    }

    let orderData = null;
    if (draft) {
      try { orderData = await api(`draft.php?action=draft_order&draft_session_id=${draft.id}`); } catch(e) {}
    }

    let availablePlayers = [];
    if (draft && (draft.status === 'in_progress' || draft.status === 'setup')) {
      try {
        const pd = await api(`draft.php?action=available_players&season_id=${draft.season_id}`);
        availablePlayers = pd.players || [];
      } catch(e) {}
    }

    let leagueTeams = [];
    if (draft && draft.status === 'setup') {
      try {
        const td = await api(`draft.php?action=league_teams&league=${league}`);
        leagueTeams = td.teams || [];
      } catch(e) {}
    }

    const order = orderData?.order || [];
    const draftStatus = draft?.status || null;
    const currentRound = draft?.current_round || 1;
    const currentPick = draft?.current_pick || 1;

    const statusMap = { setup: ['#f59e0b', 'Configurando'], in_progress: ['#22c55e', 'Em andamento'], completed: ['#94a3b8', 'Concluído'] };
    const [statusColor, statusLabel] = draftStatus ? (statusMap[draftStatus] || ['#94a3b8', draftStatus]) : ['#94a3b8', 'Sem sessão'];

    // Session panel
    let sessionPanel = '';
    if (!draft) {
      sessionPanel = `
        <div class="panel mb-3">
          <div class="panel-header">
            <div class="panel-title"><i class="bi bi-trophy-fill" style="color:#a855f7"></i> Draft — ${escapeHtml(season.league)} T${escapeHtml(String(season.season_number))}</div>
          </div>
          <div style="padding:16px">
            <p style="color:var(--text-2);font-size:13px;margin-bottom:12px">Nenhuma sessão de draft criada para esta temporada.</p>
            <button class="btn-ghost" style="color:#a855f7" onclick="_adminDraftCreateSession('${league}', ${season.id})">
              <i class="bi bi-plus-circle me-1"></i>Criar Sessão de Draft
            </button>
          </div>
        </div>`;
    } else {
      const actionBtns = [];
      if (draftStatus === 'setup') {
        actionBtns.push(`<button class="btn-ghost" style="color:#22c55e" onclick="_adminDraftStart(${draft.id}, '${league}')"><i class="bi bi-play-fill me-1"></i>Iniciar Draft</button>`);
        actionBtns.push(`<button class="btn-ghost" style="color:#ef4444;font-size:11px" onclick="_adminDraftDelete(${draft.id}, '${league}')"><i class="bi bi-trash me-1"></i>Excluir</button>`);
      }
      if (draftStatus === 'in_progress') {
        actionBtns.push(`<button class="btn-ghost" style="color:#ef4444" onclick="_adminDraftFinalize(${draft.id}, '${league}')"><i class="bi bi-check2-all me-1"></i>Finalizar</button>`);
      }
      actionBtns.push(`<button class="btn-ghost" style="color:#a855f7" onclick="_adminDraftAddPlayerModal(${draft.id}, ${draft.season_id}, '${league}')"><i class="bi bi-person-plus me-1"></i>Adicionar Jogador</button>`);

      const currentInfo = draftStatus === 'in_progress' ? `
        <div style="padding:10px 16px;border-top:1px solid var(--border);display:flex;gap:16px;align-items:center;flex-wrap:wrap">
          <span style="font-size:12px;color:var(--text-3)">Rodada: <strong style="color:var(--text)">${currentRound}</strong></span>
          <span style="font-size:12px;color:var(--text-3)">Pick: <strong style="color:var(--text)">${currentPick}</strong></span>
          <span style="font-size:12px;color:var(--text-3)">Rodadas: <strong style="color:var(--text)">${draft.total_rounds || 2}</strong></span>
          ${draft.pick_deadline_ts ? `<span id="admin-draft-detail-timer" style="font-size:13px;font-weight:700;font-variant-numeric:tabular-nums;color:#22c55e;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);border-radius:6px;padding:2px 10px">⏱ --:--</span>` : ''}
        </div>` : '';

      sessionPanel = `
        <div class="panel mb-3">
          <div class="panel-header">
            <div class="panel-title"><i class="bi bi-trophy-fill" style="color:#a855f7"></i> Draft — ${escapeHtml(season.league)} T${escapeHtml(String(season.season_number))}</div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
              <span class="pun-badge" style="background:${statusColor}20;color:${statusColor};border-color:${statusColor}40">${statusLabel}</span>
              ${actionBtns.join('')}
            </div>
          </div>
          ${currentInfo}
        </div>`;
    }

    // Draft order panel
    let orderPanel = '';
    if (draft && draftStatus !== 'completed') {
      let orderContent = '';

      if (draftStatus === 'setup') {
        const round1 = order.filter(o => parseInt(o.round) === 1);
        const teamsOptions = leagueTeams.map(t => `<option value="${t.id}">${escapeHtml(t.city)} ${escapeHtml(t.name)}</option>`).join('');

        const orderRows = round1.map((o, i) => `
          <div style="display:flex;align-items:center;justify-content:space-between;padding:7px 8px;border-radius:6px;background:var(--panel-2);margin-bottom:5px">
            <div style="display:flex;align-items:center;gap:8px">
              <span style="font-size:11px;color:var(--text-3);width:18px;text-align:right;flex-shrink:0">${i + 1}.</span>
              <span style="font-size:13px;color:var(--text)">${escapeHtml(o.team_city)} ${escapeHtml(o.team_name)}</span>
            </div>
            <button class="btn-ghost" style="padding:2px 7px;font-size:11px;color:#ef4444" onclick="_adminDraftRemoveFromOrder(${o.id}, ${draft.id}, '${league}')">
              <i class="bi bi-x"></i>
            </button>
          </div>`).join('') || `<p style="font-size:13px;color:var(--text-3);text-align:center;padding:12px 0">Nenhum time adicionado ainda.</p>`;

        orderContent = `
          <div style="padding:12px 16px">
            <div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
              <select id="draftOrderTeamSelect" style="background:var(--panel-2);color:var(--text);border:1px solid var(--panel-border);border-radius:6px;padding:6px 10px;font-size:13px;flex:1;min-width:160px">
                <option value="">Selecione o time…</option>
                ${teamsOptions}
              </select>
              <button class="btn-ghost" onclick="_adminDraftAddToOrder(${draft.id}, '${league}')" style="white-space:nowrap"><i class="bi bi-plus me-1"></i>Adicionar</button>
              ${round1.length > 0 ? `<button class="btn-ghost" style="color:#ef4444;font-size:11px" onclick="_adminDraftClearOrder(${draft.id}, '${league}')"><i class="bi bi-trash me-1"></i>Limpar tudo</button>` : ''}
            </div>
            ${orderRows}
          </div>`;

      } else {
        const rounds = [...new Set(order.map(o => parseInt(o.round)))].sort((a, b) => a - b);
        const roundsHtml = rounds.map(r => {
          const roundPicks = order.filter(o => parseInt(o.round) === r);
          const pickRows = roundPicks.map(o => {
            const isCurrent = r === currentRound && parseInt(o.pick_position) === currentPick && !o.picked_player_id;
            const isDone = !!o.picked_player_id;
            const rowBg = isCurrent ? 'background:rgba(168,85,247,.12);border-left:3px solid #a855f7;' : isDone ? 'opacity:.65;' : '';
            return `
              <div style="display:grid;grid-template-columns:22px 1fr auto;gap:8px;align-items:center;padding:7px 8px;border-radius:6px;background:var(--panel-2);margin-bottom:5px;${rowBg}">
                <span style="font-size:11px;color:var(--text-3);text-align:right">${o.pick_position}.</span>
                <div>
                  <span style="font-size:13px;color:var(--text)">${escapeHtml(o.team_city)} ${escapeHtml(o.team_name)}</span>
                  ${isDone ? `<br><span style="font-size:11px;color:#22c55e"><i class="bi bi-check me-1"></i>${escapeHtml(o.player_name || '')}${o.player_position ? ' · ' + o.player_position : ''}${o.player_ovr ? ' · OVR ' + o.player_ovr : ''}</span>` : ''}
                  ${isCurrent ? `<br><span style="font-size:11px;color:#a855f7"><i class="bi bi-cursor-fill me-1"></i>Escolhendo agora…</span>` : ''}
                </div>
                ${!isDone ? `<div style="display:flex;gap:5px;flex-shrink:0">
                  <button class="btn-ghost" style="padding:3px 8px;font-size:11px" onclick="_adminDraftPickModal(${draft.id}, ${o.id}, ${draft.season_id}, '${league}')"><i class="bi bi-person-check me-1"></i>Pick</button>
                  <button class="btn-ghost" style="padding:3px 8px;font-size:11px;color:#f59e0b;border-color:rgba(245,158,11,.3)" title="Trocar dono da pick" onclick="_adminDraftChangeOwnerModal(${draft.id}, ${o.id}, ${o.round}, ${o.pick_position}, ${o.team_id}, '${league}')"><i class="bi bi-arrow-left-right"></i></button>
                </div>` : '<span></span>'}
              </div>`;
          }).join('');
          return `<div style="margin-bottom:14px"><div style="font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Rodada ${r}</div>${pickRows}</div>`;
        }).join('');

        orderContent = `<div style="padding:12px 16px">${roundsHtml || '<p style="color:var(--text-3);font-size:13px;text-align:center;padding:12px">Sem picks definidos.</p>'}</div>`;
      }

      const round1Count = order.filter(o => parseInt(o.round) === 1).length;
      orderPanel = `
        <div class="panel mb-3">
          <div class="panel-header">
            <div class="panel-title"><i class="bi bi-list-ol" style="color:#94a3b8"></i> Ordem do Draft</div>
            <span style="font-size:11px;color:var(--text-3)">${round1Count} time${round1Count !== 1 ? 's' : ''} · ${draft.total_rounds || 2} rodadas</span>
          </div>
          ${orderContent}
        </div>`;
    }

    // Available players panel
    let playersPanel = '';
    if (draft) {
      const draftSid = draft.id;
      const draftSeasonId = draft.season_id;
      const importBtns = `
        <div class="d-flex gap-2 align-items-center flex-wrap">
          <button class="btn-ghost" style="padding:5px 11px;font-size:12px;color:#94a3b8"
            onclick="_adminDraftDownloadTemplate()">
            <i class="bi bi-download me-1"></i>Modelo CSV
          </button>
          <button class="btn-ghost" style="padding:5px 11px;font-size:12px;color:#a855f7"
            onclick="_adminDraftImportModal(${draftSid}, ${draftSeasonId}, '${league}')">
            <i class="bi bi-upload me-1"></i>Importar CSV
          </button>
          ${availablePlayers.length > 0 ? `<button class="btn-ghost" style="padding:5px 11px;font-size:12px;color:#ef4444"
            onclick="_adminDraftClearPool(${draftSeasonId}, '${league}')">
            <i class="bi bi-trash me-1"></i>Apagar todos
          </button>` : ''}
        </div>`;

      if (availablePlayers.length > 0) {
        const playerRows = availablePlayers.slice(0, 60).map(p => `
          <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)">
            <div>
              <span style="font-size:13px;color:var(--text)">${escapeHtml(p.name)}</span>
              <span style="font-size:11px;color:var(--text-3);margin-left:6px">${escapeHtml(p.position || '')} · OVR ${p.ovr || '-'} · ${p.age || '-'}a</span>
            </div>
            <button class="btn-ghost" style="padding:3px 7px;color:#ef4444;flex-shrink:0" title="Excluir jogador"
              onclick="_adminDraftDeletePlayer(${p.id}, '${league}')">
              <i class="bi bi-trash" style="font-size:13px"></i>
            </button>
          </div>`).join('');
        const more = availablePlayers.length > 60 ? `<p style="font-size:11px;color:var(--text-3);text-align:center;margin-top:8px">+${availablePlayers.length - 60} jogadores</p>` : '';

        playersPanel = `
          <div class="panel mb-3">
            <div class="panel-header" style="flex-wrap:wrap;gap:10px">
              <div class="panel-title"><i class="bi bi-people-fill" style="color:#94a3b8"></i> Pool de Jogadores
                <span style="font-size:11px;font-weight:400;color:var(--text-3);margin-left:6px">${availablePlayers.length} disponíveis</span>
              </div>
              ${importBtns}
            </div>
            <div style="padding:4px 16px 10px">${playerRows}${more}</div>
          </div>`;
      } else {
        playersPanel = `
          <div class="panel mb-3">
            <div class="panel-header" style="flex-wrap:wrap;gap:10px">
              <div class="panel-title"><i class="bi bi-people-fill" style="color:#94a3b8"></i> Pool de Jogadores</div>
              ${importBtns}
            </div>
            <div style="padding:4px 16px 16px"><p class="empty-state" style="padding:16px 0">Nenhum jogador no pool. Use "Adicionar Jogador" ou importe um CSV.</p></div>
          </div>`;
      }
    }

    container.innerHTML = `
      <div class="mb-4">${back}</div>
      ${sessionPanel}
      ${orderPanel}
      ${playersPanel}`;

    if (draftStatus === 'in_progress' && draft?.pick_deadline_ts) {
      _startAdminDraftTimer(Number(draft.pick_deadline_ts), 'admin-draft-detail-timer');
    }

  } catch(e) {
    container.innerHTML = `
      <div class="mb-4">${back}</div>
      <div class="alert alert-danger">Erro ao carregar draft: ${escapeHtml(e.error || e.message || 'Desconhecido')}</div>`;
  }
}

async function _adminDraftChangeOwnerModal(draftId, pickId, round, pickPos, currentTeamId, league) {
  document.getElementById('_adminChangeOwnerModal')?.remove();

  const modal = document.createElement('div');
  modal.id = '_adminChangeOwnerModal';
  modal.className = 'modal fade';
  modal.setAttribute('tabindex', '-1');
  modal.innerHTML = `
    <div class="modal-dialog modal-sm">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2"></i>Trocar Dono da Pick</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p style="font-size:13px;color:var(--text-2);margin-bottom:12px">Rodada ${round} · Pick #${pickPos}</p>
          <label class="pun-field-label">Novo dono</label>
          <select id="_adminChangeOwnerSelect" class="form-select mt-1">
            <option value="">Carregando…</option>
          </select>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn-orange" onclick="_adminDraftConfirmChangeOwner(${draftId}, ${pickId}, ${round}, ${pickPos}, '${league}')">
            <i class="bi bi-check me-1"></i>Confirmar
          </button>
        </div>
      </div>
    </div>`;
  document.body.appendChild(modal);

  const bsModal = new bootstrap.Modal(modal);
  bsModal.show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());

  const select = document.getElementById('_adminChangeOwnerSelect');
  try {
    const data = await api(`draft.php?action=league_teams&league=${encodeURIComponent(league)}`);
    const teams = data.teams || [];
    select.innerHTML = '<option value="">Selecione o time…</option>';
    teams
      .filter(t => Number(t.id) !== Number(currentTeamId))
      .sort((a, b) => `${a.city} ${a.name}`.localeCompare(`${b.city} ${b.name}`))
      .forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.id;
        opt.textContent = `${t.city} ${t.name}`;
        select.appendChild(opt);
      });
  } catch(e) {
    select.innerHTML = '<option value="">Erro ao carregar times</option>';
  }
}

async function _adminDraftConfirmChangeOwner(draftId, pickId, round, pickPos, league) {
  const select = document.getElementById('_adminChangeOwnerSelect');
  const toTeamId = Number(select?.value || 0);
  if (!toTeamId) { showAlert('danger', 'Selecione o time que vai receber a pick.'); return; }
  try {
    await api('draft.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'trade_pick', draft_session_id: draftId, pick_id: pickId, to_team_id: toTeamId })
    });
    const modal = document.getElementById('_adminChangeOwnerModal');
    if (modal) bootstrap.Modal.getInstance(modal)?.hide();
    showAlert('success', `Pick R${round}·#${pickPos} transferida com sucesso!`);
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao trocar dono da pick');
  }
}

async function _adminDraftCreateSession(league, seasonId) {
  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'create_session', season_id: seasonId }) });
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao criar sessão de draft');
  }
}

async function _adminDraftStart(draftSessionId, league) {
  if (!confirm('Iniciar o draft? Verifique se a ordem dos times está definida antes de continuar.')) return;
  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'start_draft', draft_session_id: draftSessionId }) });
    showAlert('success', 'Draft iniciado!');
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao iniciar draft');
  }
}

async function _adminDraftFinalize(draftSessionId, league) {
  if (!confirm('Finalizar o draft? Isso marca o draft como concluído.')) return;
  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'finalize_draft', draft_session_id: draftSessionId }) });
    showAlert('success', 'Draft finalizado!');
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao finalizar draft');
  }
}

async function _adminDraftDelete(draftSessionId, league) {
  if (!confirm('Excluir esta sessão de draft? Todos os picks e a ordem serão removidos. Esta ação não pode ser desfeita.')) return;
  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'delete_session', draft_session_id: draftSessionId }) });
    showAlert('success', 'Sessão de draft excluída.');
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao excluir sessão');
  }
}

async function _adminDraftAddToOrder(draftSessionId, league) {
  const sel = document.getElementById('draftOrderTeamSelect');
  const teamId = sel?.value;
  if (!teamId) { showAlert('warning', 'Selecione um time.'); return; }
  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'add_to_order', draft_session_id: draftSessionId, team_id: parseInt(teamId) }) });
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao adicionar time à ordem');
  }
}

async function _adminDraftRemoveFromOrder(pickId, draftSessionId, league) {
  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'remove_from_order', pick_id: pickId, draft_session_id: draftSessionId }) });
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao remover time da ordem');
  }
}

async function _adminDraftClearOrder(draftSessionId, league) {
  if (!confirm('Limpar toda a ordem do draft?')) return;
  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'clear_order', draft_session_id: draftSessionId }) });
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao limpar ordem');
  }
}

async function _adminDraftDeletePlayer(playerId, league) {
  try {
    await api('seasons.php?action=delete_draft_player', { method: 'POST', body: JSON.stringify({ player_id: playerId }) });
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao excluir jogador');
  }
}

async function _adminDraftClearPool(seasonId, league) {
  if (!confirm('Apagar todos os jogadores disponíveis do pool? Esta ação não pode ser desfeita.')) return;
  try {
    await api('seasons.php?action=clear_draft_pool', { method: 'POST', body: JSON.stringify({ season_id: seasonId }) });
    showAlert('success', 'Pool de jogadores limpo.');
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao limpar pool');
  }
}

async function _adminDraftAddPlayerModal(draftSessionId, seasonId, league) {
  document.getElementById('adminDraftPlayerModal')?.remove();

  const modal = document.createElement('div');
  modal.id = 'adminDraftPlayerModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1100;display:flex;align-items:center;justify-content:center;padding:16px';
  modal.innerHTML = `
    <div class="panel" style="width:100%;max-width:420px;padding:0">
      <div class="panel-header">
        <div class="panel-title"><i class="bi bi-person-plus" style="color:#a855f7"></i> Adicionar Jogador ao Draft</div>
        <button class="btn-ghost" style="padding:4px 8px" onclick="document.getElementById('adminDraftPlayerModal').remove()"><i class="bi bi-x-lg"></i></button>
      </div>
      <div style="padding:16px">
        <div class="row g-2 mb-3">
          <div class="col-12">
            <label style="font-size:11px;color:var(--text-3)">Nome</label>
            <input id="draftPlayerName" type="text" class="form-control form-control-sm" placeholder="Nome completo do jogador">
          </div>
          <div class="col-4">
            <label style="font-size:11px;color:var(--text-3)">Posição</label>
            <input id="draftPlayerPos" type="text" class="form-control form-control-sm" placeholder="PG, SG…">
          </div>
          <div class="col-4">
            <label style="font-size:11px;color:var(--text-3)">OVR</label>
            <input id="draftPlayerOvr" type="number" min="1" max="99" class="form-control form-control-sm" placeholder="75">
          </div>
          <div class="col-4">
            <label style="font-size:11px;color:var(--text-3)">Idade</label>
            <input id="draftPlayerAge" type="number" min="18" max="45" class="form-control form-control-sm" placeholder="22">
          </div>
        </div>
        <div class="d-flex gap-2 justify-content-end">
          <button class="btn-ghost" onclick="document.getElementById('adminDraftPlayerModal').remove()">Cancelar</button>
          <button class="btn-ghost" style="color:#a855f7" onclick="_adminDraftSubmitPlayer(${draftSessionId}, '${league}')">
            <i class="bi bi-plus me-1"></i>Adicionar
          </button>
        </div>
      </div>
    </div>`;
  document.body.appendChild(modal);
  document.getElementById('draftPlayerName')?.focus();
}

async function _adminDraftSubmitPlayer(draftSessionId, league) {
  const name = document.getElementById('draftPlayerName')?.value.trim();
  const position = (document.getElementById('draftPlayerPos')?.value.trim() || '').toUpperCase();
  const ovr = parseInt(document.getElementById('draftPlayerOvr')?.value || '0');
  const age = parseInt(document.getElementById('draftPlayerAge')?.value || '0');

  if (!name || !position || !ovr || !age) { showAlert('warning', 'Preencha todos os campos.'); return; }

  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'add_draft_player', draft_session_id: draftSessionId, name, position, ovr, age }) });
    document.getElementById('adminDraftPlayerModal')?.remove();
    showAlert('success', 'Jogador adicionado ao pool!');
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao adicionar jogador');
  }
}

async function _adminDraftPickModal(draftSessionId, pickId, seasonId, league) {
  let players = [];
  try {
    const pd = await api(`draft.php?action=available_players&season_id=${seasonId}`);
    players = pd.players || [];
  } catch(e) {}

  document.getElementById('adminDraftPickModal')?.remove();

  const playerOptions = players.map(p =>
    `<option value="${p.id}">${escapeHtml(p.name)} · ${escapeHtml(p.position || '?')} · OVR ${p.ovr || '-'} · ${p.age || '-'}a</option>`
  ).join('');

  const modal = document.createElement('div');
  modal.id = 'adminDraftPickModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1100;display:flex;align-items:center;justify-content:center;padding:16px';
  modal.innerHTML = `
    <div class="panel" style="width:100%;max-width:440px;padding:0">
      <div class="panel-header">
        <div class="panel-title"><i class="bi bi-person-check" style="color:#a855f7"></i> Fazer Pick</div>
        <button class="btn-ghost" style="padding:4px 8px" onclick="document.getElementById('adminDraftPickModal').remove()"><i class="bi bi-x-lg"></i></button>
      </div>
      <div style="padding:16px">
        <div class="mb-3">
          <label style="font-size:11px;color:var(--text-3);margin-bottom:4px;display:block">Jogador</label>
          <select id="draftPickPlayerSelect" class="form-select form-select-sm" style="background:var(--panel-2);color:var(--text);border:1px solid var(--panel-border)">
            <option value="">Selecione o jogador…</option>
            ${playerOptions}
          </select>
          ${players.length === 0 ? '<p style="font-size:12px;color:#ef4444;margin-top:6px">Nenhum jogador disponível no pool. Adicione jogadores primeiro.</p>' : ''}
        </div>
        <div class="d-flex gap-2 justify-content-end">
          <button class="btn-ghost" onclick="document.getElementById('adminDraftPickModal').remove()">Cancelar</button>
          <button class="btn-ghost" style="color:#22c55e" onclick="_adminDraftSubmitPick(${draftSessionId}, ${pickId}, '${league}')">
            <i class="bi bi-check me-1"></i>Confirmar Pick
          </button>
        </div>
      </div>
    </div>`;
  document.body.appendChild(modal);
}

async function _adminDraftSubmitPick(draftSessionId, pickId, league) {
  const playerId = document.getElementById('draftPickPlayerSelect')?.value;
  if (!playerId) { showAlert('warning', 'Selecione um jogador.'); return; }

  try {
    await api('draft.php', { method: 'POST', body: JSON.stringify({ action: 'make_pick', draft_session_id: draftSessionId, pick_id: pickId, player_id: parseInt(playerId) }) });
    document.getElementById('adminDraftPickModal')?.remove();
    showAlert('success', 'Pick realizado com sucesso!');
    showAdminDraft(league);
  } catch(e) {
    showAlert('danger', e.error || 'Erro ao fazer pick');
  }
}

// ── Draft CSV Import ────────────────────────────────────────────────────────

function _adminDraftDownloadTemplate() {
  const csv = 'name,position,ovr,age\nLeBron James,SF,97,39\nStephen Curry,PG,96,36\n';
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'draft_pool_modelo.csv';
  a.click();
  URL.revokeObjectURL(url);
}

function _adminDraftImportModal(draftSessionId, seasonId, league) {
  document.getElementById('adminDraftImportModal')?.remove();

  const modal = document.createElement('div');
  modal.id = 'adminDraftImportModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1100;display:flex;align-items:center;justify-content:center;padding:16px;overflow-y:auto';
  modal.innerHTML = `
    <div class="panel" style="width:100%;max-width:560px;padding:0">
      <div class="panel-header" style="padding:16px 18px 0">
        <div class="panel-title"><i class="bi bi-upload" style="color:#a855f7"></i> Importar Jogadores via CSV</div>
        <button class="btn-ghost" style="padding:4px 8px" onclick="document.getElementById('adminDraftImportModal').remove()"><i class="bi bi-x-lg"></i></button>
      </div>
      <div style="padding:16px 18px">
        <p style="font-size:12px;color:var(--text-3);margin-bottom:12px">
          O CSV deve ter as colunas: <strong style="color:var(--text)">name, position, ovr, age</strong>. A primeira linha é o cabeçalho e será ignorada.
          <button class="btn-ghost" style="padding:2px 8px;font-size:11px;margin-left:6px" onclick="_adminDraftDownloadTemplate()">
            <i class="bi bi-download me-1"></i>Baixar modelo
          </button>
        </p>

        <div id="draftImportDropzone"
          style="border:2px dashed var(--border-md);border-radius:var(--radius-sm);padding:28px 16px;text-align:center;cursor:pointer;transition:border-color .2s;margin-bottom:12px"
          onclick="document.getElementById('draftImportFileInput').click()"
          ondragover="event.preventDefault();this.style.borderColor='#a855f7'"
          ondragleave="this.style.borderColor=''"
          ondrop="_adminDraftHandleDrop(event,${draftSessionId},'${league}')">
          <i class="bi bi-file-earmark-text" style="font-size:28px;color:var(--text-3)"></i>
          <p style="font-size:13px;color:var(--text-2);margin-top:8px;margin-bottom:0">Arraste o arquivo CSV aqui ou clique para selecionar</p>
          <p style="font-size:11px;color:var(--text-3);margin-top:4px">Apenas .csv</p>
        </div>
        <input type="file" id="draftImportFileInput" accept=".csv,text/csv" style="display:none"
          onchange="_adminDraftFileSelected(this,${draftSessionId},'${league}')">

        <div id="draftImportPreview" style="display:none">
          <div style="font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">
            Preview — <span id="draftImportCount">0</span> jogadores
          </div>
          <div id="draftImportTable" style="max-height:220px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm)"></div>
          <div class="d-flex gap-2 justify-content-end mt-3">
            <button class="btn-ghost" onclick="document.getElementById('adminDraftImportModal').remove()">Cancelar</button>
            <button class="btn-ghost" style="color:#a855f7" id="draftImportConfirmBtn"
              onclick="_adminDraftConfirmImport(${draftSessionId},'${league}')">
              <i class="bi bi-check-lg me-1"></i>Importar todos
            </button>
          </div>
        </div>
      </div>
    </div>`;
  document.body.appendChild(modal);
}

let _draftImportRows = [];

function _adminDraftHandleDrop(event, draftSessionId, league) {
  event.preventDefault();
  document.getElementById('draftImportDropzone').style.borderColor = '';
  const file = event.dataTransfer.files?.[0];
  if (file) _adminDraftParseCSV(file, draftSessionId, league);
}

function _adminDraftFileSelected(input, draftSessionId, league) {
  const file = input.files?.[0];
  if (file) _adminDraftParseCSV(file, draftSessionId, league);
}

function _adminDraftParseCSV(file, draftSessionId, league) {
  const reader = new FileReader();
  reader.onload = (e) => {
    const text = e.target.result;
    const lines = text.split(/\r?\n/).filter(l => l.trim());
    if (lines.length < 2) { showAlert('warning', 'Arquivo vazio ou sem dados.'); return; }

    // Detectar separador (vírgula ou ponto-e-vírgula)
    const sep = lines[0].includes(';') ? ';' : ',';
    const headers = lines[0].split(sep).map(h => h.trim().toLowerCase().replace(/['"]/g, ''));

    const nameIdx = headers.indexOf('name');
    const posIdx  = headers.indexOf('position');
    const ovrIdx  = headers.indexOf('ovr');
    const ageIdx  = headers.indexOf('age');

    if (nameIdx < 0 || posIdx < 0 || ovrIdx < 0 || ageIdx < 0) {
      showAlert('danger', 'Cabeçalho inválido. Esperado: name, position, ovr, age');
      return;
    }

    _draftImportRows = [];
    const errRows = [];

    for (let i = 1; i < lines.length; i++) {
      const cols = lines[i].split(sep).map(c => c.trim().replace(/^["']|["']$/g, ''));
      const name = cols[nameIdx] || '';
      const pos  = (cols[posIdx] || '').toUpperCase();
      const ovr  = parseInt(cols[ovrIdx], 10);
      const age  = parseInt(cols[ageIdx], 10);

      if (!name || !pos || isNaN(ovr) || isNaN(age) || ovr <= 0 || age <= 0) {
        errRows.push(i + 1);
        continue;
      }
      _draftImportRows.push({ name, position: pos, ovr, age });
    }

    const preview = document.getElementById('draftImportPreview');
    const countEl = document.getElementById('draftImportCount');
    const tableEl = document.getElementById('draftImportTable');
    if (!preview || !countEl || !tableEl) return;

    if (_draftImportRows.length === 0) {
      showAlert('warning', 'Nenhuma linha válida encontrada no CSV.');
      return;
    }

    countEl.textContent = _draftImportRows.length;
    const warnHtml = errRows.length
      ? `<p style="font-size:11px;color:#f59e0b;margin-bottom:6px"><i class="bi bi-exclamation-triangle me-1"></i>${errRows.length} linha(s) inválida(s) ignorada(s) (linhas: ${errRows.slice(0, 5).join(', ')}${errRows.length > 5 ? '…' : ''})</p>`
      : '';

    tableEl.innerHTML = `
      ${warnHtml}
      <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead>
          <tr style="background:var(--panel-2)">
            <th style="padding:7px 10px;text-align:left;color:var(--text-3);font-weight:500">Nome</th>
            <th style="padding:7px 10px;color:var(--text-3);font-weight:500">Pos</th>
            <th style="padding:7px 10px;color:var(--text-3);font-weight:500">OVR</th>
            <th style="padding:7px 10px;color:var(--text-3);font-weight:500">Idade</th>
          </tr>
        </thead>
        <tbody>
          ${_draftImportRows.slice(0, 50).map(p => `
            <tr style="border-top:1px solid var(--border)">
              <td style="padding:6px 10px;color:var(--text)">${escapeHtml(p.name)}</td>
              <td style="padding:6px 10px;color:var(--text-3);text-align:center">${escapeHtml(p.position)}</td>
              <td style="padding:6px 10px;color:#a855f7;font-weight:600;text-align:center">${p.ovr}</td>
              <td style="padding:6px 10px;color:var(--text-3);text-align:center">${p.age}</td>
            </tr>`).join('')}
          ${_draftImportRows.length > 50 ? `<tr><td colspan="4" style="padding:6px 10px;color:var(--text-3);text-align:center">+${_draftImportRows.length - 50} mais…</td></tr>` : ''}
        </tbody>
      </table>`;

    preview.style.display = 'block';
    document.getElementById('draftImportDropzone').style.display = 'none';
  };
  reader.readAsText(file, 'UTF-8');
}

async function _adminDraftConfirmImport(draftSessionId, league) {
  if (!_draftImportRows.length) { showAlert('warning', 'Nenhum dado para importar.'); return; }

  const btn = document.getElementById('draftImportConfirmBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importando...'; }

  try {
    const res = await api('draft.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'import_draft_players', draft_session_id: draftSessionId, players: _draftImportRows })
    });
    document.getElementById('adminDraftImportModal')?.remove();
    _draftImportRows = [];
    showAlert('success', res.message || `${res.inserted} jogador(es) importado(s)!`);
    showAdminDraft(league);
  } catch(e) {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Importar todos'; }
    showAlert('danger', e.error || 'Erro ao importar jogadores');
  }
}


