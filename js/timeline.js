// Timeline — feed global entre todos os GMs, perfil (com seguir), grid de
// posts e busca de contas. Postar/curtir/apagar sempre vai pra
// api/team-feed.php (mesma lógica de escrita do Feed do Time); aqui é só
// leitura global + o toggle de seguir.

function tlEsc(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

async function tlFetch(url, opts) {
  const res = await fetch(url, opts || {});
  const data = await res.json();
  if (!res.ok || data.error) throw new Error(data.error || 'Erro desconhecido');
  return data;
}
function tlGet(action, params) {
  const qs = new URLSearchParams({ action, ...params }).toString();
  return tlFetch(`/api/timeline.php?${qs}`);
}
function tlPost(action, body) {
  return tlFetch('/api/timeline.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...body }) });
}
function tfPost(action, body) {
  return tlFetch('/api/team-feed.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...body }) });
}

function tlDataCurta(iso) {
  if (!iso) return '';
  return new Date(String(iso).replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

/* ---- Tabs ---- */
function tlApply(tab) {
  document.querySelectorAll('[data-tl-tab]').forEach(el => el.classList.toggle('tl-hidden', el.dataset.tlTab !== tab));
  document.querySelectorAll('.tl-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
}

/* ---- Feed global ---- */
let tlFeedLeague = '';
let tlFeedPosts = [];
let tlFeedTimeline = [];
let tlFeedStorias = []; // grupos de stories por time (barra do topo do Feed)
const TL_ICONES = { trade: '🔄', punicao: '⚠️', premio: '🏆', playoff: '🏀', draft: '🎓' };

/** Barra de stories no topo do Feed — um círculo por time com story ativa. */
function tlRenderStoriesBar() {
  const bar = document.getElementById('tlStoriesBar');
  if (!bar) return;
  if (!tlFeedStorias.length) { bar.innerHTML = ''; return; }
  bar.innerHTML = tlFeedStorias.map((g, i) => `
    <button type="button" class="tl-story" data-idx="${i}">
      <span class="tl-story-ring ${g.tem_nao_vista ? 'unseen' : ''}">
        <img src="${tlEsc(g.team_photo || '/img/default-team.png')}" onerror="this.src='/img/default-team.png'">
      </span>
      <span class="tl-story-name">${tlEsc(g.team_name)}</span>
    </button>`).join('');
  bar.querySelectorAll('.tl-story').forEach(btn => btn.addEventListener('click', () => tlAbrirStoriasDoTime(Number(btn.dataset.idx))));
}

/** Abre o visualizador ciclando pelas stories de um time (a partir da barra global). */
function tlAbrirStoriasDoTime(grupoIdx) {
  const grupo = tlFeedStorias[grupoIdx];
  if (!grupo || !grupo.stories.length) return;
  let i = grupo.stories.findIndex(s => !s.vista_por_mim);
  if (i === -1) i = 0;

  const overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;inset:0;background:#000;z-index:2000;display:flex;align-items:center;justify-content:center;flex-direction:column';
  document.body.appendChild(overlay);

  function render() {
    const s = grupo.stories[i];
    if (!s.vista_por_mim) {
      tfPost('visualizar_story', { story_id: s.id }).catch(() => {});
      s.vista_por_mim = true;
      grupo.tem_nao_vista = grupo.stories.some(x => !x.vista_por_mim);
      tlRenderStoriesBar();
    }
    overlay.innerHTML = `
      <div style="position:absolute;top:16px;left:16px;right:16px;display:flex;gap:4px">
        ${grupo.stories.map((_, idx) => `<div style="flex:1;height:2.5px;border-radius:2px;background:${idx <= i ? '#fff' : 'rgba(255,255,255,.3)'}"></div>`).join('')}
      </div>
      <div style="position:absolute;top:28px;left:16px;display:flex;align-items:center;gap:8px">
        <img src="${tlEsc(grupo.team_photo || '/img/default-team.png')}" style="width:28px;height:28px;border-radius:50%;object-fit:cover" onerror="this.src='/img/default-team.png'">
        <span style="color:#fff;font-size:13px;font-weight:700">${tlEsc(grupo.team_name)}</span>
      </div>
      <button id="tlStoryClose" style="position:absolute;top:20px;right:16px;background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:8px;padding:6px 12px;cursor:pointer;font-size:18px;line-height:1">×</button>
      <div id="tlStoryPrev" style="position:absolute;left:0;top:0;bottom:0;width:35%;cursor:pointer"></div>
      <div id="tlStoryNext" style="position:absolute;right:0;top:0;bottom:0;width:65%;cursor:pointer"></div>
      <img src="${tlEsc(s.photo_url)}" style="max-width:92vw;max-height:78vh;border-radius:12px;object-fit:contain">
      ${s.texto ? `<div style="color:#fff;margin-top:14px;font-size:14px;max-width:80vw;text-align:center">${tlEsc(s.texto)}</div>` : ''}`;

    overlay.querySelector('#tlStoryClose').addEventListener('click', () => overlay.remove());
    overlay.querySelector('#tlStoryPrev').addEventListener('click', () => { if (i > 0) { i--; render(); } });
    overlay.querySelector('#tlStoryNext').addEventListener('click', () => { if (i < grupo.stories.length - 1) { i++; render(); } else { overlay.remove(); } });
  }
  render();
}

function tlMesclar(posts, timeline) {
  const p = posts.map(x => ({ ...x, _tipo: 'post', _data: x.created_at }));
  const t = timeline.map(x => ({ ...x, _tipo: x.tipo, _data: x.data }));
  return p.concat(t).sort((a, b) => new Date(b._data) - new Date(a._data));
}

/** Card de post no formato clássico de rede social: cabeçalho, foto de ponta
    a ponta, ação de curtir, "N curtidas" e legenda com o nome em negrito. */
function tlFeedCardPost(p) {
  return `<div class="card">
    <div class="card-head">
      <img src="${tlEsc(p.team_photo || '/img/default-team.png')}" onerror="this.src='/img/default-team.png'">
      <div style="min-width:0">
        <a class="card-author" href="/timeline.php?tab=perfil&team_id=${p.team_id}">${tlEsc(p.team_name)}</a>
        <div class="card-league">${tlEsc(p.team_league || '')} · ${tlEsc(p.author_name)}</div>
      </div>
      <span class="card-time">${tlDataCurta(p.created_at)}</span>
    </div>
    ${p.photo_url ? `<img class="card-photo" src="${tlEsc(p.photo_url)}">` : ''}
    <div class="card-body">
      <div class="card-actions">
        <button type="button" class="like-btn ${p.liked_by_me ? 'liked' : ''}" data-post-id="${p.id}" data-liked="${p.liked_by_me ? '1' : '0'}">
          <i class="bi ${p.liked_by_me ? 'bi-heart-fill' : 'bi-heart'}"></i>
        </button>
      </div>
      <div class="card-likes" data-like-count>${p.like_count} curtida${p.like_count === 1 ? '' : 's'}</div>
      ${p.texto ? `<div class="card-caption"><b>${tlEsc(p.team_name)}</b>${tlEsc(p.texto)}</div>` : ''}
    </div>
  </div>`;
}
/** Evento automático dentro do Perfil de um time — sem nome/link do time (é redundante, já estamos nele). Visual discreto de propósito, não é um post. */
function tlAutoRowPerfil(it) {
  return `<div class="auto-row">
    <span class="auto-icon">${TL_ICONES[it._tipo] || '•'}</span>
    <span class="auto-text">${tlEsc(it.texto)}</span>
    <span class="auto-time">${tlDataCurta(it._data)}</span>
  </div>`;
}
function tlAutoRow(it) {
  return `<div class="auto-row">
    <span class="auto-icon">${TL_ICONES[it._tipo] || '•'}</span>
    <span class="auto-text"><a href="/timeline.php?tab=perfil&team_id=${it.team_id}" style="color:inherit;text-decoration:none"><b>${tlEsc(it.team_name)}</b></a> — ${tlEsc(it.texto)}</span>
    <span class="auto-time">${tlDataCurta(it._data)}</span>
  </div>`;
}

async function tlCarregarFeed(before) {
  const box = document.getElementById('tlFeedList');
  try {
    const data = await tlGet('feed', { league: tlFeedLeague, before: before || '' });
    if (before) { tlFeedPosts = tlFeedPosts.concat(data.posts); tlFeedTimeline = tlFeedTimeline.concat(data.timeline); }
    else { tlFeedPosts = data.posts; tlFeedTimeline = data.timeline; }
    if (!before && data.stories) { tlFeedStorias = data.stories; tlRenderStoriesBar(); }

    const itens = tlMesclar(tlFeedPosts, tlFeedTimeline);
    if (!itens.length) {
      box.innerHTML = '<div class="empty">Nada por aqui ainda.</div>';
      document.getElementById('tlFeedMore').style.display = 'none';
      return;
    }
    box.innerHTML = itens.map(it => it._tipo === 'post' ? tlFeedCardPost(it) : tlAutoRow(it)).join('');
    document.getElementById('tlFeedMore').style.display = (data.posts.length >= 20 || data.timeline.length >= 20) ? '' : 'none';

    box.querySelectorAll('.like-btn').forEach(btn => btn.addEventListener('click', () => tlToggleLike(btn, tlFeedPosts)));
  } catch (e) {
    box.innerHTML = `<div class="empty">Erro ao carregar o feed: ${tlEsc(e.message)}</div>`;
  }
}

async function tlToggleLike(btn, listaPosts) {
  const postId = Number(btn.dataset.postId);
  const jaCurtiu = btn.dataset.liked === '1';
  try {
    const data = await tfPost(jaCurtiu ? 'descurtir' : 'curtir', { post_id: postId });
    const post = listaPosts.find(p => p.id === postId);
    if (post) { post.like_count = data.like_count; post.liked_by_me = data.liked_by_me; }
    btn.dataset.liked = data.liked_by_me ? '1' : '0';
    btn.classList.toggle('liked', data.liked_by_me);
    btn.querySelector('i').className = data.liked_by_me ? 'bi bi-heart-fill' : 'bi bi-heart';
    const likesEl = btn.closest('.card')?.querySelector('[data-like-count]');
    if (likesEl) likesEl.textContent = `${data.like_count} curtida${data.like_count === 1 ? '' : 's'}`;
  } catch (e) { alert(e.message); }
}

/* ---- Posts (grid) ---- */
let tlPostsLeague = '';
async function tlCarregarPostsGrid() {
  const box = document.getElementById('tlPostsGrid');
  try {
    const data = await tlGet('posts_grid', { league: tlPostsLeague });
    if (!data.posts.length) { box.innerHTML = '<div class="empty">Nenhum post ainda.</div>'; return; }
    box.innerHTML = data.posts.map(p => `
      <a class="tl-grid-item" href="/timeline.php?tab=perfil&team_id=${p.team_id}">
        ${p.photo_url ? `<img src="${tlEsc(p.photo_url)}">` : `<div class="no-photo">${tlEsc(p.texto || '')}</div>`}
      </a>`).join('');
  } catch (e) {
    box.innerHTML = `<div class="empty">Erro ao carregar: ${tlEsc(e.message)}</div>`;
  }
}

/* ---- Pesquisar ---- */
let tlSearchTimeout = null;
async function tlBuscarContas(q) {
  const box = document.getElementById('tlSearchResults');
  if (q.length < 2) { box.innerHTML = ''; return; }
  try {
    const data = await tlGet('buscar_contas', { q });
    if (!data.resultados.length) { box.innerHTML = '<div class="empty">Nenhum resultado.</div>'; return; }
    box.innerHTML = data.resultados.map(r => `
      <a class="tl-search-item" href="/timeline.php?tab=perfil&team_id=${r.team_id}" style="text-decoration:none;color:inherit">
        <img src="${tlEsc(r.photo_url || '/img/default-team.png')}" onerror="this.src='/img/default-team.png'">
        <div><div style="font-size:13px;font-weight:600">${tlEsc(r.time_label)}</div><div style="font-size:11px;color:var(--text-3)">${tlEsc(r.gm_label)} · ${tlEsc(r.league)}</div></div>
      </a>`).join('');
  } catch (e) {
    box.innerHTML = `<div class="empty">Erro na busca: ${tlEsc(e.message)}</div>`;
  }
}

/* ---- Perfil ---- */
let tlPerfilTeamId = null;
let tlPerfilData = null;
let tlPerfilFotoBase64 = null;

async function tlCarregarPerfil(teamId) {
  const box = document.getElementById('tlPerfilBox');
  box.innerHTML = '<div class="empty">Carregando...</div>';
  try {
    const data = await tlGet('perfil', teamId ? { team_id: teamId } : {});
    tlPerfilData = data;
    tlPerfilTeamId = data.team.id;
    tlRenderPerfil();
  } catch (e) {
    box.innerHTML = `<div class="empty">${tlEsc(e.message)}</div>`;
  }
}

function tlRenderPerfil() {
  const d = tlPerfilData;
  const box = document.getElementById('tlPerfilBox');
  const nomeTime = `${d.team.city} ${d.team.name}`.trim();

  let html = `
    <div class="tl-profile-header">
      <img class="tl-profile-avatar" src="${tlEsc(d.team.photo_url || '/img/default-team.png')}" onerror="this.src='/img/default-team.png'">
      <div style="flex:1;min-width:0">
        <div class="tl-profile-name">${tlEsc(nomeTime)}</div>
        <div class="tl-profile-sub">${tlEsc(d.team.league)}</div>
        <div class="tl-profile-stats"><span><b>${d.follower_count}</b> seguidores</span><span><b>${d.following_count}</b> seguindo</span></div>
      </div>
      ${!d.is_own ? `<button type="button" class="btn-ghost ${d.is_following ? 'following' : ''}" id="tlBtnSeguir">${d.is_following ? 'Seguindo' : 'Seguir'}</button>` : ''}
    </div>`;

  if (d.stories && d.stories.length) {
    html += `<div class="tl-stories">
      ${d.stories.map((s, i) => `
        <button type="button" class="tl-story tl-story-avatar" data-idx="${i}">
          <span class="tl-story-ring ${s.vista_por_mim ? '' : 'unseen'}">
            <img src="${tlEsc(s.photo_url)}">
          </span>
          <span class="tl-story-name">Story</span>
        </button>`).join('')}
    </div>`;
  }

  if (d.can_post) {
    html += `<div class="card">
      <textarea id="tlComposerTexto" placeholder="Compartilhe algo sobre o time..." rows="2"
        style="width:100%;background:var(--panel-2);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:10px 12px;font-family:var(--font);font-size:13px;resize:vertical"></textarea>
      <div style="display:flex;align-items:center;gap:10px;margin-top:10px;flex-wrap:wrap">
        <label class="btn-ghost" style="cursor:pointer">
          <i class="bi bi-image"></i> Foto
          <input type="file" id="tlComposerFoto" accept="image/*" style="display:none">
        </label>
        <span id="tlComposerFotoNome" style="font-size:11px;color:var(--text-3)"></span>
        <div style="margin-left:auto;display:flex;gap:8px">
          <button type="button" class="btn-ghost" id="tlBtnStory">Story (24h)</button>
          <button type="button" class="btn-orange" id="tlBtnPostar">Postar</button>
        </div>
      </div>
    </div>`;
  }

  const itens = tlMesclar(d.posts, d.timeline);
  html += itens.length
    ? itens.map(it => it._tipo === 'post' ? tlPerfilCardPost(it) : tlAutoRowPerfil(it)).join('')
    : '<div class="empty">Nada por aqui ainda.</div>';

  box.innerHTML = html;
  tlWirePerfil();
}

function tlPerfilCardPost(p) {
  const podeApagar = tlPerfilData.can_post;
  return `<div class="card">
    <div class="card-head">
      <img src="${tlEsc(p.author_photo || '/img/default-team.png')}" onerror="this.src='/img/default-team.png'">
      <div style="min-width:0">
        <span class="card-author">${tlEsc(p.author_name)}</span>
      </div>
      <span class="card-time">${tlDataCurta(p.created_at)}</span>
    </div>
    ${p.photo_url ? `<img class="card-photo" src="${tlEsc(p.photo_url)}">` : ''}
    <div class="card-body">
      <div class="card-actions">
        <button type="button" class="like-btn ${p.liked_by_me ? 'liked' : ''}" data-post-id="${p.id}" data-liked="${p.liked_by_me ? '1' : '0'}">
          <i class="bi ${p.liked_by_me ? 'bi-heart-fill' : 'bi-heart'}"></i>
        </button>
        ${podeApagar ? `<button type="button" class="del-btn" data-post-id="${p.id}"><i class="bi bi-trash"></i></button>` : ''}
      </div>
      <div class="card-likes" data-like-count>${p.like_count} curtida${p.like_count === 1 ? '' : 's'}</div>
      ${p.texto ? `<div class="card-caption"><b>${tlEsc(p.author_name)}</b>${tlEsc(p.texto)}</div>` : ''}
    </div>
  </div>`;
}

function tlComprimirImagem(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error('Não foi possível ler a imagem.'));
    reader.onload = () => {
      const img = new Image();
      img.onerror = () => reject(new Error('Arquivo de imagem inválido.'));
      img.onload = () => {
        const maxLado = 1600;
        let { width, height } = img;
        if (width > maxLado || height > maxLado) {
          const escala = maxLado / Math.max(width, height);
          width = Math.round(width * escala); height = Math.round(height * escala);
        }
        const canvas = document.createElement('canvas');
        canvas.width = width; canvas.height = height;
        canvas.getContext('2d').drawImage(img, 0, 0, width, height);
        resolve(canvas.toDataURL('image/jpeg', 0.82));
      };
      img.src = reader.result;
    };
    reader.readAsDataURL(file);
  });
}

function tlWirePerfil() {
  const btnSeguir = document.getElementById('tlBtnSeguir');
  if (btnSeguir) btnSeguir.addEventListener('click', async () => {
    btnSeguir.disabled = true;
    try {
      const data = await tlPost(tlPerfilData.is_following ? 'deixar_de_seguir' : 'seguir', { team_id: tlPerfilTeamId });
      tlPerfilData.is_following = data.is_following;
      tlPerfilData.follower_count = data.follower_count;
      tlRenderPerfil();
    } catch (e) { alert(e.message); btnSeguir.disabled = false; }
  });

  document.querySelectorAll('#tlPerfilBox .like-btn').forEach(btn => btn.addEventListener('click', () => tlToggleLike(btn, tlPerfilData.posts)));
  document.querySelectorAll('#tlPerfilBox .del-btn').forEach(btn => btn.addEventListener('click', async () => {
    if (!confirm('Apagar este post?')) return;
    try {
      await tfPost('excluir_post', { post_id: Number(btn.dataset.postId) });
      tlPerfilData.posts = tlPerfilData.posts.filter(p => p.id !== Number(btn.dataset.postId));
      tlRenderPerfil();
    } catch (e) { alert(e.message); }
  }));

  document.querySelectorAll('.tl-story-avatar').forEach(btn => btn.addEventListener('click', () => tlAbrirStory(Number(btn.dataset.idx))));

  const inputFoto = document.getElementById('tlComposerFoto');
  if (inputFoto) inputFoto.addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    document.getElementById('tlComposerFotoNome').textContent = 'Processando...';
    try {
      tlPerfilFotoBase64 = await tlComprimirImagem(file);
      document.getElementById('tlComposerFotoNome').textContent = file.name;
    } catch (err) { alert(err.message); tlPerfilFotoBase64 = null; }
  });

  const btnPostar = document.getElementById('tlBtnPostar');
  if (btnPostar) btnPostar.addEventListener('click', async (e) => {
    const texto = document.getElementById('tlComposerTexto').value.trim();
    if (!texto && !tlPerfilFotoBase64) { alert('Escreva algo ou adicione uma foto.'); return; }
    e.target.disabled = true;
    try {
      const data = await tfPost('postar', { team_id: tlPerfilTeamId, texto, photo_base64: tlPerfilFotoBase64 || '' });
      tlPerfilData.posts = data.posts;
      tlPerfilFotoBase64 = null;
      tlRenderPerfil();
    } catch (err) { alert(err.message); }
    e.target.disabled = false;
  });

  const btnStory = document.getElementById('tlBtnStory');
  if (btnStory) btnStory.addEventListener('click', async (e) => {
    if (!tlPerfilFotoBase64) { alert('Stories precisam de uma foto — escolha uma acima.'); return; }
    const texto = document.getElementById('tlComposerTexto').value.trim();
    e.target.disabled = true;
    try {
      const data = await tfPost('postar_story', { team_id: tlPerfilTeamId, texto, photo_base64: tlPerfilFotoBase64 });
      tlPerfilData.stories = data.stories;
      tlPerfilFotoBase64 = null;
      tlRenderPerfil();
    } catch (err) { alert(err.message); }
    e.target.disabled = false;
  });
}

function tlAbrirStory(idx) {
  const s = tlPerfilData.stories[idx];
  if (!s) return;
  tfPost('visualizar_story', { story_id: s.id }).catch(() => {});
  s.vista_por_mim = true;

  const overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;inset:0;background:#000;z-index:2000;display:flex;align-items:center;justify-content:center;flex-direction:column';
  overlay.innerHTML = `
    <div style="position:absolute;top:16px;right:16px;z-index:2;display:flex;gap:10px">
      ${tlPerfilData.can_post ? `<button id="tlStoryDel" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:8px;padding:6px 10px;cursor:pointer"><i class="bi bi-trash"></i></button>` : ''}
      <button id="tlStoryClose" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:8px;padding:6px 12px;cursor:pointer;font-size:18px;line-height:1">×</button>
    </div>
    <img src="${tlEsc(s.photo_url)}" style="max-width:92vw;max-height:78vh;border-radius:12px;object-fit:contain">
    ${s.texto ? `<div style="color:#fff;margin-top:14px;font-size:14px;max-width:80vw;text-align:center">${tlEsc(s.texto)}</div>` : ''}`;
  document.body.appendChild(overlay);

  const fechar = () => overlay.remove();
  overlay.querySelector('#tlStoryClose').addEventListener('click', fechar);
  overlay.addEventListener('click', (e) => { if (e.target === overlay) fechar(); });
  const btnDel = overlay.querySelector('#tlStoryDel');
  if (btnDel) btnDel.addEventListener('click', async () => {
    if (!confirm('Apagar essa story?')) return;
    try {
      await tfPost('excluir_story', { story_id: s.id });
      tlPerfilData.stories = tlPerfilData.stories.filter(x => x.id !== s.id);
      tlRenderPerfil();
      fechar();
    } catch (e) { alert(e.message); }
  });
}

/* ---- Boot ---- */
document.addEventListener('DOMContentLoaded', () => {
  const params = new URLSearchParams(location.search);
  const tabInicial = params.get('tab') || 'feed';
  tlApply(tabInicial);

  document.getElementById('tlTabs').addEventListener('click', (e) => {
    const btn = e.target.closest('.tl-tab');
    if (!btn) return;
    tlApply(btn.dataset.tab);
    if (btn.dataset.tab === 'perfil' && !tlPerfilData) tlCarregarPerfil(window.MY_TEAM_ID || null);
    if (btn.dataset.tab === 'posts') tlCarregarPostsGrid();
  });

  document.getElementById('tlFeedChips').addEventListener('click', (e) => {
    const btn = e.target.closest('.tl-chip');
    if (!btn) return;
    document.querySelectorAll('#tlFeedChips .tl-chip').forEach(b => b.classList.toggle('active', b === btn));
    tlFeedLeague = btn.dataset.league;
    tlCarregarFeed();
  });
  document.getElementById('tlPostsChips').addEventListener('click', (e) => {
    const btn = e.target.closest('.tl-chip');
    if (!btn) return;
    document.querySelectorAll('#tlPostsChips .tl-chip').forEach(b => b.classList.toggle('active', b === btn));
    tlPostsLeague = btn.dataset.league;
    tlCarregarPostsGrid();
  });
  document.getElementById('tlFeedMore').addEventListener('click', () => {
    const itens = tlMesclar(tlFeedPosts, tlFeedTimeline);
    const before = itens.length ? itens[itens.length - 1]._data : null;
    if (before) tlCarregarFeed(before);
  });

  document.getElementById('tlSearchInput').addEventListener('input', (e) => {
    clearTimeout(tlSearchTimeout);
    const q = e.target.value.trim();
    tlSearchTimeout = setTimeout(() => tlBuscarContas(q), 250);
  });

  tlCarregarFeed();
  if (tabInicial === 'perfil') tlCarregarPerfil(params.get('team_id') || window.MY_TEAM_ID || null);
  if (tabInicial === 'posts') tlCarregarPostsGrid();
});
