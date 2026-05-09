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
              <label class="form-label text-light-gray">Foto do Time (URL)</label>
              <div class="d-flex gap-2">
                <input type="url" id="gedit-team-photo" class="form-control" value="${escapeHtml(u.team_photo || '')}" placeholder="https://..." oninput="updateGestaoPhotoPreview()">
                <img id="gedit-photo-preview" src="${escapeHtml(u.team_photo || '')}" style="width:44px;height:44px;border-radius:9px;object-fit:cover;border:1px solid var(--border);flex-shrink:0;${u.team_photo ? '' : 'display:none'}" onerror="this.style.display='none'">
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

function updateGestaoPhotoPreview() {
  const input = document.getElementById('gedit-team-photo');
  const preview = document.getElementById('gedit-photo-preview');
  if (!input || !preview) return;
  const url = input.value.trim();
  if (url) { preview.src = url; preview.style.display = ''; }
  else { preview.style.display = 'none'; }
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
    const data = await api(`admin.php?action=teams&league=${league}`);
    const teams = data.teams || [];

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
        </div>
      </div>`).join('');

    const actions = [
      { icon: 'bi-person-check-fill', label: 'Aprovar<br>Usuários',  fn: 'showUserApprovals()',    color: '#fc0025', bg: 'rgba(252,0,37,.12)',   badgeId: 'action-badge-approvals' },
      { icon: 'bi-arrow-left-right',  label: 'Trades',               fn: 'showTrades()',            color: '#3b82f6', bg: 'rgba(59,130,246,.12)' },
      { icon: 'bi-people-fill',       label: 'Free Agency',          fn: 'showFAAdmin()',           color: '#22c55e', bg: 'rgba(34,197,94,.12)'  },
      { icon: 'bi-hammer',            label: 'Leilões',              fn: 'showFreeAgency()',        color: '#f59e0b', bg: 'rgba(245,158,11,.12)' },
      { icon: 'bi-bar-chart-steps',   label: 'Pontuação',            fn: 'showPointsManagement()',  color: '#06b6d4', bg: 'rgba(6,182,212,.12)'  },
      { icon: 'bi-person-dash-fill',  label: 'Dispensas',            fn: 'showDispensas()',         color: '#ef4444', bg: 'rgba(239,68,68,.12)'  },
      { icon: 'bi-hand-index-thumb',  label: 'Tapas',                fn: 'showTapas()',             color: '#f97316', bg: 'rgba(249,115,22,.12)' },
      { icon: 'bi-clipboard-check',   label: 'Diretrizes',           fn: 'showDirectives()',        color: '#14b8a6', bg: 'rgba(20,184,166,.12)' },
      { icon: 'bi-exclamation-triangle-fill', label: 'Punições',    fn: 'showPunicoes()',          color: '#f43f5e', bg: 'rgba(244,63,94,.12)'  },
      { icon: 'bi-trophy-fill',              label: 'Draft',        fn: 'showAdminDraft()',        color: '#a855f7', bg: 'rgba(168,85,247,.12)' },
    ];

    const actionTiles = actions.map(a => `
      <button class="action-tile" onclick="${a.fn}">
        <div class="action-tile-icon" style="background:${a.bg};color:${a.color}">
          <i class="bi ${a.icon}"></i>
        </div>
        <div class="action-tile-label">${a.label}</div>
        ${a.badgeId ? `<span class="action-tile-badge" id="${a.badgeId}" style="display:none">0</span>` : ''}
      </button>`).join('');

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
        </div>
      </div>

      <div id="leaguePlayerSearchResults"></div>

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

      <div class="panel">
        <div class="panel-header">
          <div class="panel-title" style="margin-bottom:0"><i class="bi bi-people-fill"></i> Times</div>
          <span style="font-size:12px;color:var(--text-3)">${teams.length} cadastrados</span>
        </div>
        <div class="row g-2 mt-1">${teamCards || '<div class="col-12"><p class="empty-state">Nenhum time cadastrado.</p></div>'}</div>
      </div>
    `;

    setupLeaguePlayerSearch(league);
    document.getElementById('copyRosterBtn')?.addEventListener('click', copyLeagueRosters);
    document.getElementById('copyPicksBtn')?.addEventListener('click', copyLeaguePicks);

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
    container.innerHTML = `<div class="mb-4"><button class="btn btn-back" onclick="showLeague('${t.league}')"><i class="bi bi-arrow-left"></i> Voltar</button></div>
<div class="bg-dark-panel border-orange rounded p-4 mb-4"><div class="row align-items-center">
<div class="col-md-2 text-center"><img src="${t.photo_url || '/img/default-team.png'}" class="img-fluid rounded-circle border border-orange" style="max-width:100px;"></div>
<div class="col-md-6"><h2 class="text-white mb-2">${t.city} ${t.name}</h2><p class="text-light-gray mb-1"><strong>Proprietário:</strong> ${t.owner_name}</p>
<p class="text-light-gray mb-0"><strong>Liga:</strong> <span class="badge bg-gradient-orange">${t.league}</span></p></div>
<div class="col-md-4 text-end"><button class="btn btn-outline-orange mb-2 w-100" onclick="editTeam(${t.id})"><i class="bi bi-pencil-fill me-2"></i>Editar</button>
<div class="bg-dark rounded p-3 mb-2"><h4 class="text-orange mb-0">${t.cap_top8}${t.restricted_bonus > 0 ? ` <small style="color:#f59e0b;font-size:.7em">+${t.restricted_bonus}</small>` : ''}</h4><small class="text-light-gray">CAP Top 8${t.restricted_bonus > 0 ? ` · <span style="color:#f59e0b">🏆 ${t.restricted_eligible} Franquia${t.restricted_eligible > 1 ? 's' : ''}</span>` : ''}</small></div>
<div class="bg-dark rounded p-3 mb-2"><h4 class="text-warning mb-0">${parseInt(t.tapas || 0)}</h4><small class="text-light-gray">Tapas</small></div>
<div class="bg-dark rounded p-3 mb-2 d-flex justify-content-between align-items-center">
  <div><h4 class="text-info mb-0" id="tradesUsedDisplay">${parseInt(t.trades_used || 0)}</h4><small class="text-light-gray">Trocas feitas</small></div>
  <button class="btn btn-sm btn-outline-info" onclick="editTeamCounter(${t.id}, 'trades_used', ${parseInt(t.trades_used || 0)})"><i class="bi bi-pencil-fill"></i></button>
</div>
<div class="bg-dark rounded p-3 d-flex justify-content-between align-items-center">
  <div><h4 class="text-success mb-0" id="waiversUsedDisplay">${parseInt(t.waivers_used || 0)}</h4><small class="text-light-gray">Dispensas feitas</small></div>
  <button class="btn btn-sm btn-outline-success" onclick="editTeamCounter(${t.id}, 'waivers_used', ${parseInt(t.waivers_used || 0)})"><i class="bi bi-pencil-fill"></i></button>
</div></div></div></div>
<ul class="nav nav-tabs mb-3"><li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#roster-tab">Elenco (${t.players.length})</button></li>
<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#picks-tab">Picks (${t.picks ? t.picks.length : 0})</button></li></ul>
<div class="tab-content">
<div class="tab-pane fade show active" id="roster-tab">
<div class="d-flex justify-content-between mb-3">
<h5 class="text-white mb-0">Jogadores</h5>
<button class="btn btn-sm btn-orange" onclick="addPlayer(${t.id})"><i class="bi bi-plus-circle me-1"></i>Adicionar Jogador</button>
</div>
<div class="table-responsive"><table class="table table-dark table-hover">
<thead><tr><th>Jogador</th><th>Pos</th><th>Idade</th><th>OVR</th><th>Papel</th><th>Ações</th></tr></thead>
<tbody>${t.players.map(p => {
  const isFE = t.league === 'RISE' && (Number(p.is_franchise_player) === 1 || (Number(p.was_traded) === 0 && Number(p.drafted_by_team_id) === t.id && Number(p.ovr) >= 90));
  const rowStyle = isFE ? ' style="background:rgba(245,158,11,.08);border-left:3px solid rgba(245,158,11,.45)"' : '';
  const fBadge = isFE ? ' <span style="background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.35);border-radius:999px;font-size:10px;font-weight:700;padding:2px 6px">🏆 Franquia</span>' : '';
  return `<tr${rowStyle}><td><strong>${p.name}</strong>${fBadge}</td><td>${p.position}</td><td>${p.age}</td>
<td><span class="badge ${p.ovr >= 80 ? 'bg-success' : p.ovr >= 70 ? 'bg-warning text-dark' : 'bg-secondary'}">${p.ovr}</span></td><td>${p.role}</td>
<td><button class="btn btn-sm btn-outline-orange me-1" onclick="editPlayer(${p.id})"><i class="bi bi-pencil-fill"></i></button>
<button class="btn btn-sm btn-outline-danger" onclick="deletePlayer(${p.id})"><i class="bi bi-trash-fill"></i></button></td></tr>`;
}).join('')}</tbody></table></div>
</div>
<div class="tab-pane fade" id="picks-tab">
<div class="d-flex justify-content-between mb-3">
<h5 class="text-white mb-0">Picks</h5>
<button class="btn btn-sm btn-orange" onclick="addPick(${t.id})"><i class="bi bi-plus-circle me-1"></i>Adicionar Pick</button>
</div>
${t.picks && t.picks.length > 0 ? `<div class="table-responsive"><table class="table table-dark"><thead><tr><th>Temporada</th><th>Rodada</th><th>Time Original</th><th>Ações</th></tr></thead>
<tbody>${t.picks.map(p => `<tr><td>${p.season_year}</td><td>${p.round}ª${p.swap_type ? ` <span class="badge bg-secondary ms-1">${p.swap_type}</span>` : ''}</td><td>${p.city} ${p.team_name}</td>
<td><button class="btn btn-sm btn-outline-orange me-1" onclick="editPick(${p.id})"><i class="bi bi-pencil-fill"></i></button>
<button class="btn btn-sm btn-outline-danger" onclick="deletePick(${p.id})"><i class="bi bi-trash-fill"></i></button></td></tr>`).join('')}</tbody></table></div>` : '<div class="text-center py-5 text-light-gray">Nenhum pick</div>'}
</div></div>`;
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

    <div class="row g-4">
      <div class="col-lg-5">
        <div class="bg-dark-panel border-orange rounded p-4">
          <h5 class="text-white mb-3"><i class="bi bi-award-fill text-orange me-2"></i>Adicionar no Hall da Fama</h5>
          <div class="mb-3">
            <label class="form-label text-light-gray">Tipo</label>
            <select class="form-select bg-dark text-white border-orange" id="hofType">
              <option value="active" selected>Ativo (liga + time)</option>
              <option value="inactive">Inativo (nome + GM)</option>
            </select>
          </div>
          <div id="hofActiveFields">
            <div class="mb-3">
              <label class="form-label text-light-gray">Liga</label>
              <select class="form-select bg-dark text-white border-orange" id="hofLeague">
                ${_leagues.map(l => `<option value="${l}"${l === _hofInitLeague ? ' selected' : ''}>${l}</option>`).join('')}
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Time</label>
              <select class="form-select bg-dark text-white border-orange" id="hofTeam"></select>
            </div>
          </div>
          <div id="hofInactiveFields" style="display:none;">
            <div class="mb-3">
              <label class="form-label text-light-gray">Nome do GM</label>
              <input type="text" class="form-control bg-dark text-white border-orange" id="hofGmName" placeholder="Ex: John Doe">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label text-light-gray">Titulos</label>
            <input type="number" class="form-control bg-dark text-white border-orange" id="hofTitles" min="0" value="0">
          </div>
          <button class="btn btn-orange w-100" id="hofAddBtn"><i class="bi bi-plus-circle me-1"></i>Adicionar</button>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="bg-dark-panel border-orange rounded p-4">
          <h5 class="text-white mb-3"><i class="bi bi-list-stars text-orange me-2"></i>Lista do Hall da Fama</h5>
          <div id="hofList"><div class="text-center py-4"><div class="spinner-border text-orange"></div></div></div>
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
      container.innerHTML = '<div class="text-light-gray">Nenhum registro ainda.</div>';
      return;
    }

    container.innerHTML = `
      <div class="table-responsive">
        <table class="table table-dark table-hover">
          <thead>
            <tr>
              <th>Tipo</th>
              <th>Liga</th>
              <th>Time</th>
              <th>GM</th>
              <th class="text-center" style="width: 120px;">Titulos</th>
              <th class="text-center" style="width: 140px;">Acoes</th>
            </tr>
          </thead>
          <tbody>
            ${items.map(item => `
              <tr>
                <td>${item.is_active ? 'Ativo' : 'Inativo'}</td>
                <td>${item.league || '-'}</td>
                <td><strong>${item.team_name || '-'}</strong></td>
                <td>${item.gm_name || '-'}</td>
                <td class="text-center">
                  <input type="number" class="form-control form-control-sm bg-dark text-white border-orange" min="0" value="${item.titles || 0}" data-hof-title="${item.id}">
                </td>
                <td class="text-center">
                  <div class="d-flex justify-content-center gap-2">
                    <button class="btn btn-sm btn-success" onclick="saveHallOfFameTitles(${item.id})">
                      <i class="bi bi-save"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteHallOfFameEntry(${item.id})">
                      <i class="bi bi-trash"></i>
                    </button>
                  </div>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `;
  } catch (e) {
    container.innerHTML = '<div class="text-danger">Erro ao carregar lista.</div>';
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
  const _cfgTitle = _cfgLeague ? `Configurações — ${_cfgLeague}` : 'Configurações das Ligas';

  const container = document.getElementById('mainContainer');
  container.innerHTML = `<div class="mb-4"><button class="btn btn-back" onclick="${_cfgBack}"><i class="bi bi-arrow-left"></i> Voltar</button></div>
<div class="d-flex justify-content-between mb-3"><h4 class="text-white mb-0">${_cfgTitle}</h4>
<button class="btn btn-orange" id="saveConfigBtn"><i class="bi bi-save2 me-1"></i>Salvar Tudo</button></div>
<div id="configContainer"><div class="text-center py-4"><div class="spinner-border text-orange"></div></div></div>`;

  try {
    const data = await api('admin.php?action=leagues');
    const allLeagues = data.leagues || [];
    const filtered = _cfgLeague ? allLeagues.filter(lg => lg.league === _cfgLeague) : allLeagues;
    document.getElementById('configContainer').innerHTML = filtered.map(lg => `
<div class="bg-dark-panel border-orange rounded p-4 mb-4">
<div class="row mb-3">
<div class="col-12"><h4 class="text-orange mb-1">${lg.league}</h4><small class="text-light-gray">${lg.team_count} ${lg.team_count === 1 ? 'time' : 'times'}</small></div>
</div>
<div class="row g-3 mb-3">
<div class="col-md-3"><label class="form-label text-light-gray mb-1">CAP Mínimo</label>
<input type="number" class="form-control bg-dark text-white border-orange" value="${lg.cap_min}" data-league="${lg.league}" data-field="cap_min" /></div>
<div class="col-md-3"><label class="form-label text-light-gray mb-1">CAP Máximo</label>
<input type="number" class="form-control bg-dark text-white border-orange" value="${lg.cap_max}" data-league="${lg.league}" data-field="cap_max" /></div>
<div class="col-md-3"><label class="form-label text-light-gray mb-1">Máx. Trocas/Temporada</label>
<input type="number" class="form-control bg-dark text-white border-orange" value="${lg.max_trades || 3}" data-league="${lg.league}" data-field="max_trades" /></div>
<div class="col-md-3 d-flex align-items-end"><div class="badge bg-gradient-orange fs-6 w-100 py-2">${lg.cap_min} - ${lg.cap_max} CAP</div></div>
</div>
<div class="row mb-3">
<div class="col-md-6">
<label class="form-label text-light-gray mb-2">Status das Trocas</label>
<div class="d-flex gap-2">
<button class="btn ${(lg.trades_enabled ?? 1) == 1 ? 'btn-success' : 'btn-outline-success'} flex-grow-1" 
  onclick="toggleTrades('${lg.league}', 1)" id="tradesOnBtn_${lg.league}">
<i class="bi bi-check-circle me-1"></i>Trocas Ativas
</button>
<button class="btn ${(lg.trades_enabled ?? 1) == 0 ? 'btn-danger' : 'btn-outline-danger'} flex-grow-1" 
  onclick="toggleTrades('${lg.league}', 0)" id="tradesOffBtn_${lg.league}">
<i class="bi bi-x-circle me-1"></i>Trocas Bloqueadas
</button>
</div>
<small class="text-light-gray mt-1 d-block">
${(lg.trades_enabled ?? 1) == 1 ? '✅ Usuários podem propor e aceitar trades' : '🚫 Botão de trade desativado para esta liga'}
</small>
</div>
</div>
<div class="row mb-3">
<div class="col-md-6">
<label class="form-label text-light-gray mb-2">Status da Free Agency</label>
<div class="d-flex gap-2">
<button class="btn ${(lg.fa_enabled ?? 1) == 1 ? 'btn-success' : 'btn-outline-success'} flex-grow-1" 
  onclick="toggleFA('${lg.league}', 1)" id="faOnBtn_${lg.league}">
<i class="bi bi-check-circle me-1"></i>FA Ativa
</button>
<button class="btn ${(lg.fa_enabled ?? 1) == 0 ? 'btn-danger' : 'btn-outline-danger'} flex-grow-1" 
  onclick="toggleFA('${lg.league}', 0)" id="faOffBtn_${lg.league}">
<i class="bi bi-x-circle me-1"></i>FA Bloqueada
</button>
</div>
<small class="text-light-gray mt-1 d-block">
${(lg.fa_enabled ?? 1) == 1 ? '✅ Usuários podem enviar propostas na FA' : '🚫 Botão de enviar proposta desativado na FA'}
</small>
</div>
</div>
<div class="row">
<div class="col-12"><label class="form-label text-light-gray mb-1">Edital da Liga (PDF/Word)</label>
<div class="input-group">
<input type="file" class="form-control bg-dark text-white border-orange" id="edital_file_${lg.league}" accept=".pdf,.doc,.docx" />
<button class="btn btn-orange" onclick="uploadEdital('${lg.league}')"><i class="bi bi-upload me-1"></i>Upload</button>
</div>
${lg.edital_file ? `<div class="mt-2 d-flex align-items-center gap-2">
<span class="text-success flex-grow-1"><i class="bi bi-file-earmark-check"></i> ${lg.edital_file}</span>
<a href="/api/edital.php?action=download_edital&league=${lg.league}" class="btn btn-sm btn-outline-light" download target="_blank">
<i class="bi bi-download me-1"></i>Baixar
</a>
<button class="btn btn-sm btn-outline-danger" onclick="deleteEdital('${lg.league}')"><i class="bi bi-trash"></i></button>
</div>` : '<small class="text-light-gray mt-1">Nenhum arquivo enviado</small>'}
</div>
</div>
</div>`).join('');
    
    document.getElementById('saveConfigBtn').addEventListener('click', saveLeagueSettings);
  } catch (e) {}
}

async function saveLeagueSettings() {
  const inputs = document.querySelectorAll('#configContainer input[data-league], #configContainer textarea[data-league]');
  const groups = {};
  inputs.forEach(inp => {
    const lg = inp.dataset.league;
    groups[lg] = groups[lg] || { league: lg };
    const value = inp.dataset.field === 'edital' ? inp.value : parseInt(inp.value);
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
    body.innerHTML = `
      <div class="row g-3 align-items-end">
        <div class="col-6 col-md-3">
          <label class="form-label text-light-gray small mb-1">CAP Mínimo</label>
          <input type="number" class="form-control form-control-sm" value="${lg.cap_min}" data-league="${lg.league}" data-field="cap_min">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label text-light-gray small mb-1">CAP Máximo</label>
          <input type="number" class="form-control form-control-sm" value="${lg.cap_max}" data-league="${lg.league}" data-field="cap_max">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label text-light-gray small mb-1">Máx. Trocas/Temp.</label>
          <input type="number" class="form-control form-control-sm" value="${lg.max_trades || 3}" data-league="${lg.league}" data-field="max_trades">
        </div>
      </div>
      <div class="row g-3 mt-1">
        <div class="col-6 col-md-4">
          <label class="form-label text-light-gray small mb-1">Trades</label>
          <div class="d-flex gap-2">
            <button class="btn btn-sm ${(lg.trades_enabled ?? 1) == 1 ? 'btn-success' : 'btn-outline-success'} flex-grow-1"
              onclick="toggleTrades('${lg.league}', 1)" id="tradesOnBtn_${lg.league}">Ativas</button>
            <button class="btn btn-sm ${(lg.trades_enabled ?? 1) == 0 ? 'btn-danger' : 'btn-outline-danger'} flex-grow-1"
              onclick="toggleTrades('${lg.league}', 0)" id="tradesOffBtn_${lg.league}">Bloqueadas</button>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label text-light-gray small mb-1">Free Agency</label>
          <div class="d-flex gap-2">
            <button class="btn btn-sm ${(lg.fa_enabled ?? 1) == 1 ? 'btn-success' : 'btn-outline-success'} flex-grow-1"
              onclick="toggleFA('${lg.league}', 1)" id="faOnBtn_${lg.league}">Ativa</button>
            <button class="btn btn-sm ${(lg.fa_enabled ?? 1) == 0 ? 'btn-danger' : 'btn-outline-danger'} flex-grow-1"
              onclick="toggleFA('${lg.league}', 0)" id="faOffBtn_${lg.league}">Bloqueada</button>
          </div>
        </div>
      </div>`;
    document.getElementById('saveConfigInlineBtn')?.addEventListener('click', async () => {
      const inputs = body.querySelectorAll('input[data-league]');
      const payload = { league };
      inputs.forEach(inp => { payload[inp.dataset.field] = parseInt(inp.value); });
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
  const data = { player_id: playerId, position: document.getElementById('editPlayerPosition').value,
    secondary_position: document.getElementById('editPlayerSecondaryPosition')?.value || null,
    age: parseInt(document.getElementById('editPlayerAge')?.value || '', 10),
    ovr: parseInt(document.getElementById('editPlayerOvr').value, 10), role: document.getElementById('editPlayerRole').value,
    is_franchise_player: document.getElementById('editPlayerFranchise')?.checked ? 1 : 0 };
  if (Number.isNaN(data.age)) {
    delete data.age;
  }
  const teamId = document.getElementById('editPlayerTeam').value;
  if (teamId) data.team_id = teamId;
  
  try {
    await api('admin.php?action=player', { method: 'PUT', body: JSON.stringify(data) });
    bootstrap.Modal.getInstance(document.querySelector('.modal')).hide();
    await showTeam(appState.currentTeam.id);
    alert('Atualizado!');
  } catch (e) { alert('Erro'); }
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
<div class="mb-3"><label class="form-label text-light-gray">Time Original</label>
<select class="form-select bg-dark text-white border-orange" id="editPickOriginalTeam">
<option value="">Carregando...</option></select></div>
<div class="mb-3"><label class="form-label text-light-gray">Observações (opcional)</label>
<textarea class="form-control bg-dark text-white border-orange" id="editPickNotes" rows="2">${p.notes || ''}</textarea></div>
</div>
<div class="modal-footer border-orange"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
<button type="button" class="btn btn-orange" onclick="savePickEdit(${pickId})">Salvar</button></div></div></div>`;
  
  document.body.appendChild(modal);
  
  // Carregar times para seleção
  api('admin.php?action=teams').then(data => {
    const select = modal.querySelector('#editPickOriginalTeam');
    select.innerHTML = '';
    data.teams.forEach(t => {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = `${t.city} ${t.name} (${t.league})`;
      if (t.id == p.original_team_id) opt.selected = true;
      select.appendChild(opt);
    });
  });
  
  new bootstrap.Modal(modal).show();
  modal.addEventListener('hidden.bs.modal', () => modal.remove());
}

async function savePickEdit(pickId) {
  const data = {
    pick_id: pickId,
    team_id: appState.currentTeam.id,
    original_team_id: parseInt(document.getElementById('editPickOriginalTeam').value),
    season_year: parseInt(document.getElementById('editPickYear').value),
    round: document.getElementById('editPickRound').value,
    notes: document.getElementById('editPickNotes').value.trim() || null
  };
  
  if (!data.original_team_id) {
    alert('Selecione o time original!');
    return;
  }
  
  try {
    await api('admin.php?action=pick', { method: 'PUT', body: JSON.stringify(data) });
    bootstrap.Modal.getInstance(document.querySelector('.modal')).hide();
    await showTeam(appState.currentTeam.id);
    alert('Pick atualizado!');
  } catch (e) { 
    alert('Erro ao atualizar pick: ' + (e.error || 'Desconhecido')); 
  }
}

async function deletePick(pickId) {
  if (!confirm('Deletar este pick?')) return;
  try {
    await api(`admin.php?action=pick&id=${pickId}`, { method: 'DELETE' });
    await showTeam(appState.currentTeam.id);
    alert('Pick deletado!');
  } catch (e) { alert('Erro ao deletar pick!'); }
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
          position: row.player_position || row.position || '?'
        };
      }
      return acc;
    }, {});
  }
  if (typeof raw === 'object') return raw;
  return {};
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
      
      <div class="card bg-dark-panel border-orange">
        <div class="card-header bg-transparent border-orange">
          <h5 class="text-white mb-0"><i class="bi bi-calendar-event me-2"></i>Prazos de Diretrizes</h5>
        </div>
        <div class="card-body">
          ${deadlines.length === 0 ? 
            '<p class="text-light-gray text-center py-4">Nenhum prazo configurado</p>' :
            `<div class="table-responsive"><table class="table table-dark">
              <thead><tr>
                <th>Liga</th><th>Prazo (Horário de Brasília)</th><th>Descrição</th><th>Fase</th><th>Status</th><th>Envios</th><th>Ações</th>
              </tr></thead>
              <tbody>${deadlines.map(d => `
                <tr>
                  <td><span class="badge bg-gradient-orange">${d.league}</span></td>
                  <td>${formatDeadlineDateTime(d.deadline_date_iso || d.deadline_date)}</td>
                  <td>${d.description || '-'}</td>
                  <td>${(d.phase || 'regular') === 'playoffs' ? '<span class="badge bg-danger">Playoffs</span>' : '<span class="badge bg-info">Regular</span>'}</td>
                  <td>${d.is_active ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Inativo</span>'}</td>
                  <td><span class="badge bg-info">${d.submissions_count} time(s)</span></td>
                  <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="viewDirectives(${d.id}, '${d.league}')" title="Ver diretrizes">
                      <i class="bi bi-eye"></i> Ver
                    </button>
                    <button class="btn btn-sm btn-outline-${d.is_active ? 'warning' : 'success'}" onclick="toggleDeadlineStatus(${d.id}, ${d.is_active})" title="${d.is_active ? 'Desativar' : 'Ativar'}">
                      <i class="bi bi-toggle-${d.is_active ? 'on' : 'off'}"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteDeadline(${d.id}, '${d.league}')" title="Excluir prazo">
                      <i class="bi bi-trash"></i>
                    </button>
                  </td>
                </tr>
              `).join('')}</tbody>
            </table></div>`
          }
        </div>
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
        <div class="card-header bg-transparent border-orange">
          <h5 class="text-white mb-0"><i class="bi bi-clipboard-data me-2"></i>Diretrizes Enviadas - Liga ${league}</h5>
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
                const prevId = prev ? prev['starter_' + i + '_id'] : null;
                const starterChanged = hasPrev && String(prevId ?? '') !== String(id ?? '');
                const minutesChanged = isManualRotation && hasPrev && id && typeof prevPm[id] !== 'undefined' && String(prevPm[id]) !== String(pm[id]);
                const rowClass = (starterChanged || minutesChanged) ? 'text-danger' : '';
                return `<li class="${rowClass}">${name} (${pos})${m ? ' - ' + m : ''}</li>`;
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
                  let name = '?', pos = '?';
                  if (playerInfo[id]) {
                    name = playerInfo[id].name || '?';
                    pos = playerInfo[id].position || '?';
                  } else {
                    // Fallback para bench_X columns (compatibilidade)
                    for (let i = 1; i <= 3; i++) {
                      if (parseInt(d['bench_' + i + '_id']) === id) {
                        name = d['bench_' + i + '_name'] || '?';
                        pos = d['bench_' + i + '_pos'] || '?';
                        break;
                      }
                    }
                  }
                  // Só mostrar minutos se rotação for manual
                  const minLabel = isManualRotation ? ` - ${pm[playerId]} min` : '';
                  const benchChanged = hasPrev && prevBenchIds.length > 0 && !prevBenchIds.includes(id);
                  const minutesChanged = isManualRotation && hasPrev && typeof prevPm[id] !== 'undefined' && String(prevPm[id]) !== String(pm[playerId]);
                  const rowClass = (benchChanged || minutesChanged) ? 'text-danger' : '';
                  benchItems.push(`<li class="${rowClass}">${name} (${pos})${minLabel}</li>`);
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
                return `<li>${name} (${pos})</li>`;
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
              <div class="card bg-dark mb-3 admin-check-card ${isAccepted ? 'is-accepted' : ''}" data-directive-id="${d.id}">
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
            <div>
              <div class="pun-field-label">Liga</div>
              <select id="punicaoLeague" class="form-select"></select>
            </div>
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
            <div class="panel-title" style="margin-bottom:0"><i class="bi bi-clock-history"></i> Histórico de punições</div>
            <div class="d-flex gap-2 flex-wrap">
              <div class="admin-sel">
                <label>Liga</label>
                <select id="punicaoHistoryLeague"></select>
              </div>
              <div class="admin-sel">
                <label>Time</label>
                <select id="punicaoHistoryTeam"><option value="">Todos os times</option></select>
              </div>
            </div>
          </div>
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
  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back" onclick="showLeague('${league}')"><i class="bi bi-arrow-left"></i> Voltar</button>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><i class="bi bi-person-check-fill" style="color:var(--red);margin-right:8px;"></i>Solicitações Free Agency</div>
        <div class="admin-sel">
          <label for="faNewAdminLeague">Liga</label>
          <select id="faNewAdminLeague" onchange="typeof carregarSolicitacoesNovaFA==='function'&&carregarSolicitacoesNovaFA()">
            ${_leagues.map(lg => `<option value="${lg}"${lg === league ? ' selected' : ''}>${lg}</option>`).join('')}
          </select>
        </div>
      </div>
      <div id="faNewAdminRequests"><p class="empty-state">Carregando...</p></div>
    </div>
  `;

  if (typeof carregarSolicitacoesNovaFA === 'function') {
    carregarSolicitacoesNovaFA();
  }
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
    div.innerHTML = propostas.map(p => {
      const jogs = (p.jogadores || []).map(j => j.name).join(', ') || '—';
      const picks = (p.picks || []).map(pk => `${pk.season_year} R${pk.round}`).join(', ') || '—';
      const statusMap = { aceita: '#22c55e', recusada: '#ef4444', pendente: '#f59e0b' };
      const sc = statusMap[p.status] || 'var(--text-3)';
      return `
        <div style="padding:10px;background:var(--panel-2);border-radius:var(--radius-sm);
          margin-bottom:8px;border:1px solid var(--border)">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div style="flex:1;min-width:0">
              <div style="font-weight:600;font-size:13px;color:var(--text)">${p.team_name}</div>
              <div style="font-size:11px;color:var(--text-3);margin-top:2px">Jogadores: ${jogs}</div>
              <div style="font-size:11px;color:var(--text-3)">Picks: ${picks}</div>
              ${p.notas ? `<div style="font-size:11px;color:var(--text-3);font-style:italic;margin-top:2px">"${p.notas}"</div>` : ''}
            </div>
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
    container.innerHTML = `
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <thead>
            <tr style="border-bottom:1px solid var(--border)">
              <th style="padding:8px 12px;text-align:left;color:var(--text-3);font-weight:500">Jogador</th>
              <th style="padding:8px 12px;text-align:left;color:var(--text-3);font-weight:500">Time origem</th>
              <th style="padding:8px 12px;text-align:left;color:var(--text-3);font-weight:500">Vencedor</th>
              <th style="padding:8px 12px;text-align:left;color:var(--text-3);font-weight:500">Data</th>
            </tr>
          </thead>
          <tbody>
            ${leiloes.map(l => `
              <tr style="border-bottom:1px solid var(--border)">
                <td style="padding:8px 12px;color:var(--text);font-weight:600">${l.player_name || '—'}</td>
                <td style="padding:8px 12px;color:var(--text-3)">${l.team_name || '—'}</td>
                <td style="padding:8px 12px;color:${l.winner_team_name ? '#22c55e' : 'var(--text-3)'}">
                  ${l.winner_team_name || '<span style="color:var(--text-3)">Sem vencedor</span>'}
                </td>
                <td style="padding:8px 12px;color:var(--text-3)">${l.data_fim ? String(l.data_fim).split(' ')[0] : '—'}</td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>`;
  } catch (e) {
    container.innerHTML = `<p class="empty-state" style="color:#ef4444">${e.error || 'Erro ao carregar histórico'}</p>`;
  }
}

function setFreeAgencyLeague(league) {
  appState.currentFAleague = league;
}

function refreshAdminFreeAgency() {
  _leilaoLoadActive();
}

// ========== MOEDAS ==========
let coinsLeague = 'ELITE';

async function showCoins() {
  appState.view = 'coins';
  updateBreadcrumb();
  
  const container = document.getElementById('mainContainer');
  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back" onclick="showHome()"><i class="bi bi-arrow-left"></i> Voltar</button>
    </div>
    
    <div class="row mb-4">
      <div class="col-md-8">
        <ul class="nav nav-tabs" id="coinsLeagueTabs">
          <li class="nav-item">
            <button class="nav-link ${coinsLeague === 'ELITE' ? 'active' : ''}" onclick="changeCoinsLeague('ELITE')">ELITE</button>
          </li>
          <li class="nav-item">
            <button class="nav-link ${coinsLeague === 'NEXT' ? 'active' : ''}" onclick="changeCoinsLeague('NEXT')">NEXT</button>
          </li>
          <li class="nav-item">
            <button class="nav-link ${coinsLeague === 'RISE' ? 'active' : ''}" onclick="changeCoinsLeague('RISE')">RISE</button>
          </li>
          <li class="nav-item">
            <button class="nav-link ${coinsLeague === 'ROOKIE' ? 'active' : ''}" onclick="changeCoinsLeague('ROOKIE')">ROOKIE</button>
          </li>
        </ul>
      </div>
      <div class="col-md-4 text-end">
        <button class="btn btn-orange" onclick="openBulkCoinsModal()">
          <i class="bi bi-people-fill me-2"></i>Distribuir para Liga
        </button>
      </div>
    </div>
    
    <div id="coinsContainer">
      <div class="text-center py-5"><div class="spinner-border text-orange"></div></div>
    </div>
    
    <!-- Modal Adicionar Moedas -->
    <div class="modal fade" id="addCoinsModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content bg-dark-panel border-orange">
          <div class="modal-header border-orange">
            <h5 class="modal-title text-white"><i class="bi bi-coin text-orange me-2"></i>Gerenciar Moedas</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" id="coinsTeamId">
            <div class="mb-3">
              <label class="form-label text-light-gray">Time</label>
              <input type="text" class="form-control bg-dark text-white" id="coinsTeamName" readonly>
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Saldo Atual</label>
              <div class="input-group">
                <span class="input-group-text bg-dark text-orange border-orange"><i class="bi bi-coin"></i></span>
                <input type="text" class="form-control bg-dark text-white" id="coinsCurrentBalance" readonly>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Operação</label>
              <select class="form-select bg-dark text-white border-secondary" id="coinsOperation">
                <option value="add">Adicionar</option>
                <option value="remove">Remover</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Quantidade</label>
              <input type="number" class="form-control bg-dark text-white border-secondary" id="coinsAmount" min="1" value="100">
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Motivo</label>
              <input type="text" class="form-control bg-dark text-white border-secondary" id="coinsReason" placeholder="Ex: Prêmio de temporada">
            </div>
          </div>
          <div class="modal-footer border-orange">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-orange" onclick="submitCoins()">Confirmar</button>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Modal Distribuir em Massa -->
    <div class="modal fade" id="bulkCoinsModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content bg-dark-panel border-orange">
          <div class="modal-header border-orange">
            <h5 class="modal-title text-white"><i class="bi bi-people-fill text-orange me-2"></i>Distribuir Moedas</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-info bg-dark border-orange text-white">
              <i class="bi bi-info-circle me-2"></i>
              Esta ação adicionará moedas para TODOS os times da liga selecionada.
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Liga</label>
              <select class="form-select bg-dark text-white border-secondary" id="bulkCoinsLeague">
                <option value="ELITE">ELITE</option>
                <option value="NEXT">NEXT</option>
                <option value="RISE">RISE</option>
                <option value="ROOKIE">ROOKIE</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Quantidade por Time</label>
              <input type="number" class="form-control bg-dark text-white border-secondary" id="bulkCoinsAmount" min="1" value="100">
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Motivo</label>
              <input type="text" class="form-control bg-dark text-white border-secondary" id="bulkCoinsReason" placeholder="Ex: Início de temporada">
            </div>
          </div>
          <div class="modal-footer border-orange">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-orange" onclick="submitBulkCoins()">Distribuir</button>
          </div>
        </div>
      </div>
    </div>
  `;
  
  loadCoinsTeams();
}

function changeCoinsLeague(league) {
  coinsLeague = league;
  showCoins();
}

async function loadCoinsTeams() {
  const container = document.getElementById('coinsContainer');
  
  try {
    const data = await api(`admin.php?action=coins&league=${coinsLeague}`);
    const teams = data.teams || [];
    
    if (teams.length === 0) {
      container.innerHTML = '<div class="alert alert-info bg-dark border-orange text-white">Nenhum time encontrado nesta liga.</div>';
      return;
    }
    
    const totalCoins = teams.reduce((sum, t) => sum + parseInt(t.moedas), 0);
    
    container.innerHTML = `
      <div class="row mb-3">
        <div class="col-md-6">
          <div class="bg-dark-panel border-orange rounded p-3">
            <h6 class="text-light-gray mb-1">Total de Moedas na Liga</h6>
            <h3 class="text-orange mb-0"><i class="bi bi-coin me-2"></i>${totalCoins.toLocaleString()}</h3>
          </div>
        </div>
        <div class="col-md-6">
          <div class="bg-dark-panel border-orange rounded p-3">
            <h6 class="text-light-gray mb-1">Times</h6>
            <h3 class="text-white mb-0"><i class="bi bi-people-fill me-2 text-orange"></i>${teams.length}</h3>
          </div>
        </div>
      </div>
      
      <div class="table-responsive">
        <table class="table table-dark table-hover">
          <thead>
            <tr>
              <th>Time</th>
              <th>Proprietário</th>
              <th class="text-end">Moedas</th>
              <th class="text-center">Ações</th>
            </tr>
          </thead>
          <tbody>
            ${teams.map(t => `
              <tr>
                <td><strong>${t.city} ${t.name}</strong></td>
                <td class="text-light-gray">${t.owner_name}</td>
                <td class="text-end">
                  <span class="badge ${parseInt(t.moedas) > 0 ? 'bg-success' : 'bg-secondary'} fs-6">
                    <i class="bi bi-coin me-1"></i>${parseInt(t.moedas).toLocaleString()}
                  </span>
                </td>
                <td class="text-center">
                  <button class="btn btn-sm btn-success" onclick="openCoinsModal(${t.id}, '${t.city} ${t.name}', ${t.moedas})" title="Gerenciar moedas">
                    <i class="bi bi-coin"></i>
                  </button>
                  <button class="btn btn-sm btn-outline-secondary" onclick="showCoinsHistory(${t.id}, '${t.city} ${t.name}')" title="Ver histórico">
                    <i class="bi bi-clock-history"></i>
                  </button>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `;
  } catch (e) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar times: ' + (e.error || 'Desconhecido') + '</div>';
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
  document.getElementById('bulkCoinsLeague').value = coinsLeague;
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
    
    // Atualizar para a liga que foi distribuída
    coinsLeague = league;
    showCoins();
  } catch (e) {
    alert('Erro: ' + (e.error || 'Desconhecido'));
  }
}

async function showCoinsHistory(teamId, teamName) {
  const container = document.getElementById('coinsContainer');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-orange"></div></div>';
  
  try {
    const data = await api(`admin.php?action=coins_log&team_id=${teamId}`);
    const logs = data.logs || [];
    
    let html = `
      <div class="mb-3">
        <button class="btn btn-back" onclick="loadCoinsTeams()"><i class="bi bi-arrow-left"></i> Voltar para lista</button>
      </div>
      <div class="bg-dark-panel border-orange rounded p-3 mb-3">
        <h5 class="modal-title text-white"><i class="bi bi-coin text-orange me-2"></i>Histórico de Moedas: ${teamName}</h5>
      </div>
    `;
    
    if (logs.length === 0) {
      html += '<div class="alert alert-info bg-dark border-orange text-white">Nenhum histórico encontrado.</div>';
    } else {
      html += `
        <div class="table-responsive">
          <table class="table table-dark table-hover">
            <thead>
              <tr>
                <th>Data</th>
                <th>Tipo</th>
                <th class="text-end">Alteração</th>
                <th class="text-end">Saldo</th>
                <th>Motivo</th>
              </tr>
            </thead>
            <tbody>
              ${logs.map(log => {
                const date = new Date(log.created_at);
                const dateStr = date.toLocaleDateString('pt-BR') + ' ' + date.toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'});
                const typeLabels = {
                  'admin_add': '<span class="badge bg-success">Adição Admin</span>',
                  'admin_remove': '<span class="badge bg-danger">Remoção Admin</span>',
                  'admin_bulk': '<span class="badge bg-info">Distribuição</span>',
                  'fa_bid': '<span class="badge bg-warning text-dark">Lance FA</span>',
                  'fa_win': '<span class="badge bg-primary">Vitória FA</span>',
                  'fa_refund': '<span class="badge bg-secondary">Reembolso FA</span>'
                };
                return `
                  <tr>
                    <td class="text-light-gray">${dateStr}</td>
                    <td>${typeLabels[log.type] || log.type}</td>
                    <td class="text-end">
                      <span class="${parseInt(log.amount) >= 0 ? 'text-success' : 'text-danger'}">
                        ${parseInt(log.amount) >= 0 ? '+' : ''}${parseInt(log.amount).toLocaleString()}
                      </span>
                    </td>
                    <td class="text-end">${parseInt(log.balance_after).toLocaleString()}</td>
                    <td class="text-light-gray">${log.reason || '-'}</td>
                  </tr>
                `;
              }).join('')}
            </tbody>
          </table>
        </div>
      `;
    }
    
    container.innerHTML = html;
  } catch (e) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar histórico: ' + (e.error || 'Desconhecido') + '</div>';
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
    <div class="mb-4">
      <button class="btn btn-back" onclick="${_tapasBack}"><i class="bi bi-arrow-left"></i> Voltar</button>
      <span class="text-light-gray ms-3" style="font-size:14px;font-weight:600">Tapas — ${tapasLeague}</span>
    </div>

    <div id="tapasContainer">
      <div class="text-center py-5"><div class="spinner-border text-orange"></div></div>
    </div>

    <div class="modal fade" id="tapasModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content bg-dark-panel border-orange">
          <div class="modal-header border-orange">
            <h5 class="modal-title text-white"><i class="bi bi-hand-index-thumb text-warning me-2"></i>Gerenciar Tapas</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" id="tapasTeamId">
            <input type="hidden" id="tapasOperation" value="set">
            <div class="mb-3">
              <label class="form-label text-light-gray">Time</label>
              <input type="text" class="form-control bg-dark text-white" id="tapasTeamName" readonly>
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Tapas atuais</label>
              <div class="input-group">
                <span class="input-group-text bg-dark text-warning border-orange"><i class="bi bi-hand-index-thumb"></i></span>
                <input type="text" class="form-control bg-dark text-white" id="tapasCurrentBalance" readonly>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label text-light-gray">Quantidade</label>
              <input type="number" class="form-control bg-dark text-white border-secondary" id="tapasAmount" min="0" value="0">
            </div>
          </div>
          <div class="modal-footer border-orange">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-orange" onclick="submitTapas()">Confirmar</button>
          </div>
        </div>
      </div>
    </div>
  `;

  loadTapasTeams();
}

function changeTapasLeague(league) {
  tapasLeague = league;
  showTapas();
}

async function loadTapasTeams() {
  const container = document.getElementById('tapasContainer');

  try {
    const data = await api(`admin.php?action=tapas&league=${tapasLeague}`);
    const teams = data.teams || [];

    if (teams.length === 0) {
      container.innerHTML = '<div class="alert alert-info bg-dark border-orange text-white">Nenhum time encontrado nesta liga.</div>';
      return;
    }

    const totalTapas = teams.reduce((sum, t) => sum + parseInt(t.tapas || 0), 0);
    const totalTapasUsed = teams.reduce((sum, t) => sum + parseInt(t.tapas_used || 0), 0);

    container.innerHTML = `
      <div class="row mb-3">
        <div class="col-md-4">
          <div class="bg-dark-panel border-orange rounded p-3">
            <h6 class="text-light-gray mb-1">Total de Tapas na Liga</h6>
            <h3 class="text-warning mb-0"><i class="bi bi-hand-index-thumb me-2"></i>${totalTapas.toLocaleString()}</h3>
          </div>
        </div>
        <div class="col-md-4">
          <div class="bg-dark-panel border-orange rounded p-3">
            <h6 class="text-light-gray mb-1">Tapas usados</h6>
            <h3 class="text-danger mb-0"><i class="bi bi-hand-index-thumb me-2"></i>${totalTapasUsed.toLocaleString()}</h3>
          </div>
        </div>
        <div class="col-md-4">
          <div class="bg-dark-panel border-orange rounded p-3">
            <h6 class="text-light-gray mb-1">Times</h6>
            <h3 class="text-white mb-0"><i class="bi bi-people-fill me-2 text-orange"></i>${teams.length}</h3>
          </div>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-dark table-hover">
          <thead>
            <tr>
              <th>Time</th>
              <th>Proprietário</th>
              <th class="text-end">Tapas</th>
              <th class="text-end">Usados</th>
              <th class="text-center">Ações</th>
            </tr>
          </thead>
          <tbody>
            ${teams.map(t => `
              <tr>
                <td><strong>${t.city} ${t.name}</strong></td>
                <td class="text-light-gray">${t.owner_name}</td>
                <td class="text-end">
                  <span class="badge ${parseInt(t.tapas || 0) > 0 ? 'bg-warning text-dark' : 'bg-secondary'} fs-6">
                    <i class="bi bi-hand-index-thumb me-1"></i>${parseInt(t.tapas || 0).toLocaleString()}
                  </span>
                </td>
                <td class="text-end">
                  <span class="badge ${parseInt(t.tapas_used || 0) > 0 ? 'bg-danger' : 'bg-secondary'} fs-6">
                    <i class="bi bi-hand-index-thumb me-1"></i>${parseInt(t.tapas_used || 0).toLocaleString()}
                  </span>
                </td>
                <td class="text-center">
                  <div class="d-flex justify-content-center gap-2">
                    <button class="btn btn-sm btn-success" onclick="quickTapasChange(${t.id}, '${t.city} ${t.name}', 'add')" title="Adicionar tapas">
                      <i class="bi bi-plus"></i>
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="quickTapasChange(${t.id}, '${t.city} ${t.name}', 'remove')" title="Remover tapas">
                      <i class="bi bi-dash"></i>
                    </button>
                  </div>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `;
  } catch (e) {
    container.innerHTML = '<div class="alert alert-danger">Erro ao carregar times: ' + (e.error || 'Desconhecido') + '</div>';
  }
}

function openTapasModal(teamId, teamName, currentBalance, operation = 'set') {
  document.getElementById('tapasTeamId').value = teamId;
  document.getElementById('tapasTeamName').value = teamName;
  document.getElementById('tapasCurrentBalance').value = parseInt(currentBalance || 0).toLocaleString();
  document.getElementById('tapasOperation').value = operation;
  document.getElementById('tapasAmount').value = 0;

  new bootstrap.Modal(document.getElementById('tapasModal')).show();
}

async function quickTapasChange(teamId, teamName, operation) {
  try {
    const result = await api('admin.php?action=tapas', {
      method: 'POST',
      body: JSON.stringify({ team_id: teamId, amount: 1, operation })
    });

    loadTapasTeams();
  } catch (e) {
    alert(`Erro ao atualizar tapas de ${teamName}: ${e.error || 'Desconhecido'}`);
  }
}

async function submitTapas() {
  const teamId = document.getElementById('tapasTeamId').value;
  const amount = parseInt(document.getElementById('tapasAmount').value);
  const operation = document.getElementById('tapasOperation').value || 'set';

  if (!teamId || Number.isNaN(amount) || amount < 0) {
    alert('Preencha uma quantidade válida.');
    return;
  }

  try {
    const result = await api('admin.php?action=tapas', {
      method: 'POST',
      body: JSON.stringify({ team_id: teamId, amount, operation })
    });

    bootstrap.Modal.getInstance(document.getElementById('tapasModal'))?.hide();
    alert(result.message || 'Tapas atualizados com sucesso.');
    loadTapasTeams();
  } catch (e) {
    alert('Erro: ' + (e.error || 'Desconhecido'));
  }
}

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
                        <h5 class="text-white mb-1">${user.username}</h5>
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
                      <button class="btn btn-success flex-fill" onclick="approveUser(${user.id}, '${user.username}')">
                        <i class="bi bi-check-circle me-1"></i>Aprovar
                      </button>
                      <button class="btn btn-danger flex-fill" onclick="rejectUser(${user.id}, '${user.username}')">
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
      body: JSON.stringify({
        league: league,
        trades_enabled: enabled
      })
    });
    
    // Atualiza os botões visualmente
    const onBtn = document.getElementById(`tradesOnBtn_${league}`);
    const offBtn = document.getElementById(`tradesOffBtn_${league}`);
    
    if (enabled == 1) {
      onBtn.className = 'btn btn-success flex-grow-1';
      offBtn.className = 'btn btn-outline-danger flex-grow-1';
    } else {
      onBtn.className = 'btn btn-outline-success flex-grow-1';
      offBtn.className = 'btn btn-danger flex-grow-1';
    }
    
    showAlert('success', `Trocas ${enabled == 1 ? 'ativadas' : 'desativadas'} para a liga ${league}!`);
  } catch (e) {
    showAlert('danger', 'Erro ao atualizar status de trades');
  }
}

async function toggleFA(league, enabled) {
  try {
    await api('admin.php?action=league_settings', {
      method: 'PUT',
      body: JSON.stringify({
        league: league,
        fa_enabled: enabled
      })
    });

    const onBtn = document.getElementById(`faOnBtn_${league}`);
    const offBtn = document.getElementById(`faOffBtn_${league}`);

    if (enabled == 1) {
      onBtn.className = 'btn btn-success flex-grow-1';
      offBtn.className = 'btn btn-outline-danger flex-grow-1';
    } else {
      onBtn.className = 'btn btn-outline-success flex-grow-1';
      offBtn.className = 'btn btn-danger flex-grow-1';
    }

    showAlert('success', `Free Agency ${enabled == 1 ? 'ativada' : 'desativada'} para a liga ${league}!`);
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
        <table class="table table-dark table-sm mb-0" style="font-size:13px">
          <thead>
            <tr>
              <th>Jogador</th>
              <th>Pos.</th>
              <th>OVR</th>
              <th>Idade</th>
              <th>Temporada</th>
              <th>Data</th>
            </tr>
          </thead>
          <tbody>
            ${players.map(w => `
              <tr>
                <td class="text-white fw-semibold">${escapeHtml(w.name || '-')}</td>
                <td><span class="badge bg-secondary">${escapeHtml(w.position || '-')}</span></td>
                <td><span class="badge bg-gradient-orange">${w.overall || '-'}</span></td>
                <td>${w.age || '-'}</td>
                <td>${w.season_year || '-'}</td>
                <td class="text-light-gray">${w.waived_at ? new Date(w.waived_at).toLocaleDateString('pt-BR') : '-'}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `;
  });

  html += '</div>';
  resultEl.innerHTML = html;
}

// ══════════════════════════════════════════════
// PONTUAÇÃO POR TEMPORADA
// ══════════════════════════════════════════════

async function showPointsManagement(league) {
  league = league || appState.currentLeague || 'ELITE';
  appState.view = 'pontuacao';
  updateBreadcrumb();
  const container = document.getElementById('mainContainer');

  const _ptsBack = appState.currentLeague ? `showLeague('${appState.currentLeague}')` : 'showHome()';

  container.innerHTML = `
    <div class="mb-4">
      <button class="btn btn-back me-2" onclick="${_ptsBack}"><i class="bi bi-arrow-left"></i> Voltar</button>
      <span class="text-light-gray" style="font-size:14px;font-weight:600">Pontuação — ${league}</span>
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

  const seasons    = data.seasons    || [];
  const leagueTeams = data.league_teams || [];

  if (!seasons.length) {
    document.getElementById('ptsMgmtContent').innerHTML =
      `<div class="alert alert-info">Nenhuma temporada encontrada para a liga ${league}.</div>`;
    return;
  }

  let html = '';
  seasons.forEach((s) => {
    const title = [
      s.sprint_number ? `Sprint ${s.sprint_number}` : '',
      s.season_number ? `Temp ${s.season_number}`   : '',
      s.year          ? String(s.year)               : ''
    ].filter(Boolean).join(' · ');

    if (s.points_registered) {
      const rows = (s.teams || []).map((t, ti) => `
        <tr>
          <td style="color:var(--text-3);font-size:12px;width:32px">${ti + 1}°</td>
          <td style="font-weight:600">${escapeHtml(t.team_name || '')}</td>
          <td class="text-end" style="color:var(--red);font-weight:700">${t.points} pts</td>
        </tr>`).join('');

      const editInputs = leagueTeams.map(t => {
        const pts = (s.teams || []).find(st => String(st.team_id) === String(t.team_id));
        return `
        <tr>
          <td style="font-weight:600">${escapeHtml(t.team_name || '')}</td>
          <td>
            <input type="number" class="form-control form-control-sm pts-edit-input"
              data-team-id="${t.team_id}" value="${pts ? pts.points : 0}" min="0" style="max-width:100px">
          </td>
        </tr>`;
      }).join('');

      html += `
      <div class="bg-dark-panel rounded mb-3" id="pts-season-${s.season_id}">
        <div class="d-flex align-items-center justify-content-between px-3 py-2"
             style="border-bottom:1px solid var(--border)">
          <span style="font-weight:700;color:var(--text)">🏆 ${escapeHtml(title)}</span>
          <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-gradient-orange" style="font-size:10px">Registrado</span>
            <button class="btn btn-sm btn-outline-orange" style="padding:2px 10px;font-size:11px" onclick="toggleEditPtsForm(${s.season_id})">
              <i class="bi bi-pencil me-1"></i>Editar
            </button>
            <button class="btn btn-sm" style="padding:2px 10px;font-size:11px;border:1px solid rgba(239,68,68,.3);color:#ef4444;background:rgba(239,68,68,.08)" onclick="deletePtsMgmt(${s.season_id}, '${escapeHtml(league)}')">
              <i class="bi bi-trash3 me-1"></i>Zerar
            </button>
          </div>
        </div>
        <div id="pts-view-${s.season_id}">
          <div class="table-responsive">
            <table class="table table-dark table-sm mb-0">
              <tbody>${rows}</tbody>
            </table>
          </div>
        </div>
        <div id="pts-edit-form-${s.season_id}" style="display:none;padding:16px">
          <div class="table-responsive">
            <table class="table table-dark table-sm mb-3">
              <thead><tr><th>Time</th><th>Pontos</th></tr></thead>
              <tbody>${editInputs}</tbody>
            </table>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-orange" onclick="saveEditPtsMgmt(${s.season_id}, '${escapeHtml(league)}')">
              <i class="bi bi-save me-1"></i>Salvar Alterações
            </button>
            <button class="btn btn-sm btn-outline-orange" onclick="toggleEditPtsForm(${s.season_id})">Cancelar</button>
          </div>
        </div>
      </div>`;
    } else {
      const teamInputs = leagueTeams.map(t => `
        <tr>
          <td style="font-weight:600">${escapeHtml(t.team_name || '')}</td>
          <td>
            <input type="number" class="form-control form-control-sm pts-mgmt-input"
              data-team-id="${t.team_id}" value="0" min="0" style="max-width:100px">
          </td>
        </tr>`).join('');

      html += `
      <div class="bg-dark-panel rounded mb-3" id="pts-season-${s.season_id}">
        <div class="d-flex align-items-center justify-content-between px-3 py-2"
             style="border-bottom:1px solid var(--border)">
          <span style="font-weight:700;color:var(--text)">📋 ${escapeHtml(title)}</span>
          <button class="btn btn-sm btn-orange" onclick="togglePtsForm(${s.season_id})">
            <i class="bi bi-plus-circle me-1"></i>Cadastrar Pontuação
          </button>
        </div>
        <div id="pts-form-${s.season_id}" style="display:none;padding:16px">
          <div class="table-responsive">
            <table class="table table-dark table-sm mb-3">
              <thead><tr><th>Time</th><th>Pontos</th></tr></thead>
              <tbody>${teamInputs}</tbody>
            </table>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-orange"
              onclick="savePtsMgmt(${s.season_id}, '${escapeHtml(league)}')">
              <i class="bi bi-save me-1"></i>Salvar
            </button>
            <button class="btn btn-sm btn-outline-orange" onclick="togglePtsForm(${s.season_id})">
              Cancelar
            </button>
          </div>
        </div>
      </div>`;
    }
  });

  document.getElementById('ptsMgmtContent').innerHTML =
    html || '<div class="alert alert-info">Nenhuma temporada encontrada.</div>';
}

function togglePtsForm(seasonId) {
  const form = document.getElementById(`pts-form-${seasonId}`);
  if (form) form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

async function savePtsMgmt(seasonId, league) {
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

// ── Draft Admin ──────────────────────────────────────────────────────

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
        <div style="padding:10px 16px;border-top:1px solid var(--panel-border);display:flex;gap:16px">
          <span style="font-size:12px;color:var(--text-3)">Rodada: <strong style="color:var(--text)">${currentRound}</strong></span>
          <span style="font-size:12px;color:var(--text-3)">Pick: <strong style="color:var(--text)">${currentPick}</strong></span>
          <span style="font-size:12px;color:var(--text-3)">Rodadas: <strong style="color:var(--text)">${draft.total_rounds || 2}</strong></span>
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
                ${!isDone ? `<button class="btn-ghost" style="padding:3px 8px;font-size:11px" onclick="_adminDraftPickModal(${draft.id}, ${o.id}, ${draft.season_id}, '${league}')"><i class="bi bi-person-check me-1"></i>Pick</button>` : '<span></span>'}
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
    if (draft && availablePlayers.length > 0) {
      const playerRows = availablePlayers.slice(0, 60).map(p => `
        <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--panel-border)">
          <div>
            <span style="font-size:13px;color:var(--text)">${escapeHtml(p.name)}</span>
            <span style="font-size:11px;color:var(--text-3);margin-left:6px">${escapeHtml(p.position || '')} · OVR ${p.ovr || '-'} · ${p.age || '-'}a</span>
          </div>
        </div>`).join('');
      const more = availablePlayers.length > 60 ? `<p style="font-size:11px;color:var(--text-3);text-align:center;margin-top:8px">+${availablePlayers.length - 60} jogadores</p>` : '';

      playersPanel = `
        <div class="panel mb-3">
          <div class="panel-header">
            <div class="panel-title"><i class="bi bi-people-fill" style="color:#94a3b8"></i> Pool de Jogadores</div>
            <span style="font-size:11px;color:var(--text-3)">${availablePlayers.length} disponíveis</span>
          </div>
          <div style="padding:4px 16px 10px">${playerRows}${more}</div>
        </div>`;
    } else if (draft) {
      playersPanel = `
        <div class="panel mb-3">
          <div class="panel-header">
            <div class="panel-title"><i class="bi bi-people-fill" style="color:#94a3b8"></i> Pool de Jogadores</div>
          </div>
          <div style="padding:16px"><p class="empty-state">Nenhum jogador no pool. Use "Adicionar Jogador" para incluir jogadores no draft.</p></div>
        </div>`;
    }

    container.innerHTML = `
      <div class="mb-4">${back}</div>
      ${sessionPanel}
      ${orderPanel}
      ${playersPanel}`;

  } catch(e) {
    container.innerHTML = `
      <div class="mb-4">${back}</div>
      <div class="alert alert-danger">Erro ao carregar draft: ${escapeHtml(e.error || e.message || 'Desconhecido')}</div>`;
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


