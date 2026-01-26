(function(){
  const league = window.__USER_LEAGUE__;
  const teamId = window.__TEAM_ID__;

  const api = async (path, body, method = 'POST') => {
    const url = `/api/${path}`;
    const opts = { method, headers: { 'Content-Type': 'application/json' } };
    if (body) opts.body = JSON.stringify(body);
    const res = await fetch(url, opts);
    return res.json();
  };

  const isAdmin = document.querySelector('span.badge.bg-gradient-orange')?.textContent?.includes('Admin')
    || document.querySelector('.sidebar-menu a[href="/admin.php"]') !== null
    || false;

  const elRumorsCount = document.getElementById('rumorsCount');
  const elRumorsList = document.getElementById('rumorsList');
  const elAdminCommentsList = document.getElementById('adminCommentsList');
  const elSubmitRumorBtn = document.getElementById('submitRumorBtn');
  const elRumorContent = document.getElementById('rumorContent');
  const elAddAdminCommentBtn = document.getElementById('addAdminCommentBtn');

  async function loadRumors() {
    try {
      const resp = await fetch(`/api/rumors.php?league=${encodeURIComponent(league)}`);
      const data = await resp.json();
      if (!data.success) throw new Error(data.error || 'Erro ao carregar rumores');
      renderAdminComments(data.admin_comments || []);
      renderRumors(data.rumors || []);
    } catch (e) {
      console.error(e);
    }
  }

  function renderAdminComments(comments) {
    elAdminCommentsList.innerHTML = '';
    if (comments.length === 0) {
      elAdminCommentsList.innerHTML = '<div class="alert alert-secondary">Sem comentários do admin.</div>';
      return;
    }
    comments.forEach(c => {
      const div = document.createElement('div');
      div.className = 'list-group-item bg-dark text-white border-orange d-flex justify-content-between align-items-start';
      div.innerHTML = `
        <div>
          <div class="small text-light-gray">${escapeHtml(c.admin_name || 'Admin')} · ${formatDate(c.created_at)}</div>
          <div>${escapeHtml(c.content)}</div>
        </div>
        ${isAdmin ? `<button class="btn btn-sm btn-outline-danger" data-id="${c.id}"><i class="bi bi-trash"></i></button>` : ''}
      `;
      if (isAdmin) {
        div.querySelector('button')?.addEventListener('click', async () => {
          const id = parseInt(div.querySelector('button').getAttribute('data-id'));
          const res = await api('rumors.php', { action: 'delete_admin_comment', comment_id: id });
          if (res.success) loadRumors();
        });
      }
      elAdminCommentsList.appendChild(div);
    });
  }

  function renderRumors(rumors) {
    elRumorsList.innerHTML = '';
    elRumorsCount.textContent = `${rumors.length} rumores`;
    if (rumors.length === 0) {
      elRumorsList.innerHTML = '<div class="alert alert-secondary">Nenhum rumor publicado ainda.</div>';
      return;
    }
    rumors.forEach(r => {
      const card = document.createElement('div');
      card.className = 'player-card';
      const canDelete = isAdmin || (teamId && parseInt(r.team_id) === parseInt(teamId));
      card.innerHTML = `
        <div class="d-flex align-items-center justify-content-between">
          <div class="team-chip mb-2">
            <img src="${escapeAttr(r.photo_url || '/img/default-team.png')}" alt="${escapeAttr(r.city || '')} ${escapeAttr(r.name || '')}">
            <span class="text-white small">${escapeHtml(r.city || '')} ${escapeHtml(r.name || '')}</span>
          </div>
          <div class="text-light-gray small">${formatDate(r.created_at)}</div>
        </div>
        <div class="player-name">${escapeHtml(r.content)}</div>
        ${canDelete ? `<div class="mt-2 text-end"><button class="btn btn-sm btn-outline-danger" data-id="${r.id}"><i class="bi bi-trash"></i> Remover</button></div>` : ''}
      `;
      if (canDelete) {
        card.querySelector('button')?.addEventListener('click', async () => {
          const id = parseInt(card.querySelector('button').getAttribute('data-id'));
          const res = await api('rumors.php', { action: 'delete_rumor', rumor_id: id });
          if (res.success) loadRumors();
        });
      }
      elRumorsList.appendChild(card);
    });
  }

  function formatDate(dt) {
    if (!dt) return '';
    try {
      const d = new Date(dt);
      return d.toLocaleString('pt-BR');
    } catch { return dt; }
  }

  function escapeHtml(s){
    return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
  }
  function escapeAttr(s){
    return String(s || '').replace(/["']/g, c => ({'"':'&quot;',"'":'&#39;'})[c]);
  }

  if (elSubmitRumorBtn) {
    elSubmitRumorBtn.addEventListener('click', async () => {
      const content = (elRumorContent.value || '').trim();
      if (!content) return;
      const res = await api('rumors.php', { action: 'add_rumor', content });
      if (res.success) {
        elRumorContent.value = '';
        loadRumors();
      }
    });
  }

  if (elAddAdminCommentBtn) {
    elAddAdminCommentBtn.addEventListener('click', async () => {
      const content = prompt('Comentário do Admin (máx 500 chars):');
      if (!content) return;
      const res = await api('rumors.php', { action: 'add_admin_comment', league, content });
      if (res.success) loadRumors();
    });
  }

  // Load when tab becomes active
  const rumorsTabBtn = document.getElementById('rumors-tab');
  if (rumorsTabBtn) {
    rumorsTabBtn.addEventListener('shown.bs.tab', loadRumors);
  }
  // Also load immediately if already active via deep link
  if (document.getElementById('rumors')?.classList.contains('show')) {
    loadRumors();
  }
})();
