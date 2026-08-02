// Central dos Drafts Aleatórios — lista os drafts criados a partir das roletas,
// dá acesso a cada um e permite criar um novo em cima de uma roleta concluída.

const _dcEsc = s => (s == null ? '' : String(s))
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

async function _dcFetch(url, options = {}) {
  const res = await fetch(url, { headers: { 'Content-Type': 'application/json' }, ...options });
  const txt = await res.text();
  let data;
  try { data = JSON.parse(txt); } catch (e) { throw new Error('Resposta inválida do servidor'); }
  if (!data.success) throw new Error(data.error || 'Erro desconhecido');
  return data;
}

async function dcCarregar() {
  const grid = document.getElementById('draftsGrid');
  try {
    const data = await _dcFetch('/api/drafts-aleatorios.php?action=listar');
    const cards = data.drafts.map(d => {
      const resolvidas = d.feitas + d.puladas;
      const pct = d.total ? Math.round((resolvidas / d.total) * 100) : 0;
      let label = 'Em aberto', cor = 'var(--text-3)', bg = 'var(--panel-3)';
      if (d.finalizado) { label = 'Finalizado'; cor = '#22c55e'; bg = 'rgba(34,197,94,.12)'; }
      else if (d.completo) { label = 'Pronto pra finalizar'; cor = '#3b82f6'; bg = 'rgba(59,130,246,.12)'; }
      else if (resolvidas > 0) { label = 'Em andamento'; cor = '#f59e0b'; bg = 'rgba(245,158,11,.12)'; }

      return `
      <div class="dc-card">
        <div class="dc-card-main" onclick="window.location.href='/draft-aleatorio.php?id=${d.id}'">
          <div class="dc-card-icon"><i class="bi bi-shuffle"></i></div>
          <div class="dc-card-title">${_dcEsc(d.titulo)}</div>
          <div class="dc-card-sub">${d.league ? _dcEsc(d.league) + ' · ' : ''}${d.feitas}/${d.total} escolhidos${d.puladas ? ` · ${d.puladas} pulada${d.puladas > 1 ? 's' : ''}` : ''}</div>
          <div class="dc-card-progress"><div style="width:${pct}%"></div></div>
          <span class="dc-card-status" style="color:${cor};background:${bg}">${label}</span>
        </div>
        <div class="dc-card-actions">
          <a class="btn-ghost" href="/draft-aleatorio.php?id=${d.id}"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir</a>
          <button type="button" class="btn-ghost" onclick="dcCopiarLink(${d.id}, this)" title="Copiar link do draft"><i class="bi bi-link-45deg"></i></button>
          <button type="button" class="btn-ghost" onclick="dcRenomear(${d.id}, '${_dcEsc(d.titulo).replace(/'/g, "\\'")}')"><i class="bi bi-pencil"></i></button>
          <button type="button" class="btn-ghost dc-btn-del" onclick="dcExcluir(${d.id}, '${_dcEsc(d.titulo).replace(/'/g, "\\'")}')"><i class="bi bi-trash"></i></button>
        </div>
      </div>`;
    });

    cards.push(`
      <button type="button" class="dc-card dc-card-new" id="btnNovoDraftCard">
        <i class="bi bi-plus-circle"></i>
        <span style="font-size:13px;font-weight:700">Criar draft de uma roleta</span>
      </button>`);

    grid.innerHTML = cards.join('');
    document.getElementById('btnNovoDraftCard').addEventListener('click', dcAbrirModalNovo);
  } catch (e) {
    grid.innerHTML = `<div class="empty"><i class="bi bi-exclamation-triangle"></i><p>Erro ao carregar: ${_dcEsc(e.message)}</p></div>`;
  }
}

async function dcAbrirModalNovo() {
  const lista = document.getElementById('dcRoletasLista');
  lista.innerHTML = '<div class="dc-ac-empty">Carregando roletas...</div>';
  document.getElementById('modalNovoDraft').classList.add('open');
  try {
    const data = await _dcFetch('/api/drafts-aleatorios.php?action=roletas_disponiveis');
    if (!data.roletas.length) {
      lista.innerHTML = `<div class="dc-ac-empty">Nenhuma roleta concluída disponível. Termine o sorteio de uma roleta em <a href="/roleta.php" style="color:var(--red)">Roletas</a> — ou ela já virou draft.</div>`;
      return;
    }
    lista.innerHTML = data.roletas.map(r => `
      <button type="button" class="dc-roleta-item" onclick="dcCriarDeRoleta(${r.id})">
        <div>
          <div class="dc-roleta-nome">${_dcEsc(r.titulo)}</div>
          <div class="dc-roleta-meta">${r.league ? _dcEsc(r.league) + ' · ' : ''}${r.total} participantes · ${_dcEsc(r.tipo)}</div>
        </div>
        <i class="bi bi-arrow-right"></i>
      </button>`).join('');
  } catch (e) {
    lista.innerHTML = `<div class="dc-ac-empty">${_dcEsc(e.message)}</div>`;
  }
}
window.dcAbrirModalNovo = dcAbrirModalNovo;

function dcFecharModalNovo() { document.getElementById('modalNovoDraft').classList.remove('open'); }
window.dcFecharModalNovo = dcFecharModalNovo;

async function dcCriarDeRoleta(roletaId) {
  try {
    const data = await _dcFetch('/api/drafts-aleatorios.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'criar_de_roleta', roleta_id: roletaId }),
    });
    window.location.href = `/draft-aleatorio.php?id=${data.id}`;
  } catch (e) { alert(e.message); }
}
window.dcCriarDeRoleta = dcCriarDeRoleta;

async function dcRenomear(id, atual) {
  const titulo = prompt('Novo nome do draft:', atual);
  if (titulo === null) return;
  if (!titulo.trim()) { alert('O nome não pode ficar vazio.'); return; }
  try {
    await _dcFetch('/api/drafts-aleatorios.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'renomear', id, titulo: titulo.trim() }),
    });
    dcCarregar();
  } catch (e) { alert(e.message); }
}
window.dcRenomear = dcRenomear;

function dcCopiarLink(id, btn) {
  const url = `${window.location.origin}/draft-aleatorio.php?id=${id}`;
  const original = btn.innerHTML;
  navigator.clipboard.writeText(url).then(() => {
    btn.innerHTML = '<i class="bi bi-check2"></i>';
    setTimeout(() => { btn.innerHTML = original; }, 1600);
  }).catch(() => alert('Não consegui copiar o link.'));
}
window.dcCopiarLink = dcCopiarLink;

async function dcExcluir(id, titulo) {
  if (!confirm(`Excluir o draft "${titulo}"? As escolhas registradas nele são apagadas. A roleta de origem continua intacta e dá pra gerar o draft de novo.`)) return;
  try {
    await _dcFetch('/api/drafts-aleatorios.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'excluir', id }),
    });
    dcCarregar();
  } catch (e) { alert(e.message); }
}
window.dcExcluir = dcExcluir;

document.addEventListener('DOMContentLoaded', () => {
  const btnTop = document.getElementById('btnNovoDraftTop');
  if (btnTop) btnTop.addEventListener('click', dcAbrirModalNovo);
  dcCarregar();
});
