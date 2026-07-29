/**
 * Roleta de Eliminação de Anos (admin, por liga)
 */

const _rlEsc = s => (s || '').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

const RL_COLORS = ['#fc0025', '#f59e0b', '#3b82f6', '#22c55e', '#8b5cf6', '#ec4899', '#06b6d4', '#eab308', '#f97316', '#14b8a6'];

let rlCurrentLeague = (typeof adminLeagues !== 'undefined' && adminLeagues.length) ? adminLeagues[0] : 'ELITE';
let rlRenderedPool = [];   // [{id, year}] na ordem exibida na roleta agora
let rlSpinning = false;

async function _rlFetch(url, options = {}) {
  const res = await fetch(url, { headers: { 'Content-Type': 'application/json' }, ...options });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); }
  catch (e) { throw new Error('Resposta inválida do servidor'); }
  if (!data.success) throw new Error(data.error || 'Erro desconhecido');
  return data;
}

function rlRenderLeagueTabs() {
  const el = document.getElementById('leagueTabs');
  if (!el || typeof adminLeagues === 'undefined') return;
  el.innerHTML = adminLeagues.map(lg =>
    `<button type="button" class="league-tab${lg === rlCurrentLeague ? ' active' : ''}" onclick="rlSwitchLeague('${lg}')">${_rlEsc(lg)}</button>`
  ).join('');
}

function rlSwitchLeague(league) {
  if (rlSpinning || league === rlCurrentLeague) return;
  rlCurrentLeague = league;
  rlRenderLeagueTabs();
  rlLoadState();
}

async function rlLoadState() {
  const poolListEl = document.getElementById('poolList');
  const histListEl = document.getElementById('histList');
  if (poolListEl) poolListEl.innerHTML = '<p style="color:var(--text-3);font-size:13px">Carregando...</p>';
  if (histListEl) histListEl.innerHTML = '<p style="color:var(--text-3);font-size:13px">Carregando...</p>';

  try {
    const data = await _rlFetch(`api/roleta.php?action=state&league=${encodeURIComponent(rlCurrentLeague)}`);
    rlRenderedPool = data.pool || [];
    rlRenderPoolList(data.pool || []);
    rlRenderHistory(data.eliminated || []);
    rlRenderWinnerBanner(data.finished, data.winner);
    rlRenderWheel(data.pool || []);
    rlUpdateSpinButton(data.pool || []);
  } catch (e) {
    if (poolListEl) poolListEl.innerHTML = `<p style="color:#ef4444;font-size:13px">${_rlEsc(e.message)}</p>`;
  }
}

function rlRenderPoolList(pool) {
  const el = document.getElementById('poolList');
  if (!el) return;
  if (!pool.length) {
    el.innerHTML = '<div class="empty"><i class="bi bi-inbox"></i><p>Nenhum ano cadastrado ainda.</p></div>';
    return;
  }
  el.innerHTML = `<div class="pool-list">${pool.map(p => `
    <span class="pool-chip">${p.year}<button type="button" onclick="rlRemoveYear(${p.id})" title="Remover"><i class="bi bi-x"></i></button></span>
  `).join('')}</div>`;
}

function rlRenderHistory(eliminated) {
  const el = document.getElementById('histList');
  if (!el) return;
  if (!eliminated.length) {
    el.innerHTML = '<div class="empty"><i class="bi bi-clock-history"></i><p>Nenhum giro ainda.</p></div>';
    return;
  }
  el.innerHTML = `<div class="hist-list">${eliminated.map(h => {
    const time = h.eliminated_at ? new Date(h.eliminated_at.replace(' ', 'T')).toLocaleString('pt-BR', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' }) : '';
    return `<div class="hist-row">
      <span class="hist-order">${h.elimination_order}</span>
      <span class="hist-year">${h.year}</span>
      <span class="hist-time">${_rlEsc(time)}</span>
    </div>`;
  }).join('')}</div>`;
}

function rlRenderWinnerBanner(finished, winner) {
  const el = document.getElementById('winnerArea');
  if (!el) return;
  if (finished && winner) {
    el.innerHTML = `<div class="winner-banner">
      <div class="wb-label"><i class="bi bi-trophy-fill me-1"></i>Vencedor da roleta — ${_rlEsc(rlCurrentLeague)}</div>
      <div class="wb-year">${winner}</div>
    </div>`;
  } else {
    el.innerHTML = '';
  }
}

function rlRenderWheel(pool) {
  const wheel = document.getElementById('wheel');
  if (!wheel) return;
  // Reseta rotação sem animação antes de re-renderizar (evita girar "de volta" visualmente)
  wheel.style.transition = 'none';
  wheel.style.transform = 'rotate(0deg)';
  void wheel.offsetWidth; // força reflow
  wheel.style.transition = '';

  if (pool.length < 2) {
    const msg = pool.length === 1
      ? 'Só resta um ano — já é o vencedor.'
      : 'Cadastre pelo menos 2 anos para começar.';
    wheel.innerHTML = `<div class="wheel-empty-msg">${_rlEsc(msg)}</div>`;
    return;
  }

  const n = pool.length;
  const segAngle = 360 / n;
  const stops = pool.map((p, i) => {
    const color = RL_COLORS[i % RL_COLORS.length];
    return `${color} ${i * segAngle}deg ${(i + 1) * segAngle}deg`;
  }).join(', ');

  let html = `<div style="position:absolute;inset:0;border-radius:50%;background:conic-gradient(from 0deg, ${stops})"></div>`;
  pool.forEach((p, i) => {
    const mid = i * segAngle + segAngle / 2;
    // A faixa do rótulo aponta "leste" (90°) na orientação neutra do CSS, mas o
    // conic-gradient usa 0°=norte — subtrai 90° pra alinhar o rótulo com sua fatia.
    html += `<div class="wheel-seg-label" style="transform:rotate(${mid - 90}deg)"><span>${_rlEsc(String(p.year))}</span></div>`;
  });
  wheel.innerHTML = html;
}

function rlUpdateSpinButton(pool) {
  const btn = document.getElementById('btnSpin');
  if (!btn) return;
  btn.disabled = rlSpinning || pool.length < 2;
}

async function rlAddYear() {
  const input = document.getElementById('yearInput');
  const year = parseInt(input?.value, 10);
  if (!year || year < 1900 || year > 2200) {
    alert('Informe um ano válido.');
    return;
  }
  const btn = document.getElementById('btnAddYear');
  if (btn) btn.disabled = true;
  try {
    await _rlFetch('api/roleta.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'add_year', league: rlCurrentLeague, year })
    });
    if (input) input.value = '';
    await rlLoadState();
  } catch (e) {
    alert(e.message);
  } finally {
    if (btn) btn.disabled = false;
  }
}

async function rlRemoveYear(id) {
  if (rlSpinning) return;
  try {
    await _rlFetch('api/roleta.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'remove_year', league: rlCurrentLeague, id })
    });
    await rlLoadState();
  } catch (e) {
    alert(e.message);
  }
}

async function rlReset() {
  if (rlSpinning) return;
  if (!confirm(`Reiniciar a roleta de ${rlCurrentLeague}? Todos os anos e o histórico desta liga serão apagados.`)) return;
  try {
    await _rlFetch('api/roleta.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'reset', league: rlCurrentLeague })
    });
    await rlLoadState();
  } catch (e) {
    alert(e.message);
  }
}

async function rlClearHistory() {
  if (rlSpinning) return;
  if (!confirm(`Limpar o histórico de eliminação de ${rlCurrentLeague}? Os anos já eliminados voltam para a roleta (os anos cadastrados não são apagados).`)) return;
  try {
    await _rlFetch('api/roleta.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'clear_history', league: rlCurrentLeague })
    });
    await rlLoadState();
  } catch (e) {
    alert(e.message);
  }
}

async function rlSpin() {
  if (rlSpinning || rlRenderedPool.length < 2) return;
  rlSpinning = true;
  const btn = document.getElementById('btnSpin');
  if (btn) btn.disabled = true;

  const poolAtSpinTime = rlRenderedPool.slice();

  try {
    const data = await _rlFetch('api/roleta.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'spin', league: rlCurrentLeague })
    });

    const eliminatedYear = data.eliminated_year;
    const idx = poolAtSpinTime.findIndex(p => p.year === eliminatedYear);
    const wheel = document.getElementById('wheel');

    if (wheel && idx !== -1) {
      const n = poolAtSpinTime.length;
      const segAngle = 360 / n;
      const targetMid = idx * segAngle + segAngle / 2;
      const spins = 5; // voltas completas antes de parar, só efeito visual
      const finalRotation = spins * 360 + (360 - targetMid);
      wheel.style.transform = `rotate(${finalRotation}deg)`;

      await new Promise(resolve => setTimeout(resolve, 4300));
    }

    rlSpinning = false;
    rlRenderPoolList(data.pool || []);
    rlRenderHistory(data.eliminated || []);
    rlRenderWinnerBanner(data.finished, data.winner);
    rlRenderedPool = data.pool || [];
    rlRenderWheel(data.pool || []);
    rlUpdateSpinButton(data.pool || []);
  } catch (e) {
    rlSpinning = false;
    if (btn) btn.disabled = rlRenderedPool.length < 2;
    alert(e.message);
  }
}

document.addEventListener('DOMContentLoaded', function () {
  rlRenderLeagueTabs();
  rlLoadState();

  document.getElementById('btnAddYear')?.addEventListener('click', rlAddYear);
  document.getElementById('yearInput')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); rlAddYear(); }
  });
  document.getElementById('btnReset')?.addEventListener('click', rlReset);
  document.getElementById('btnClearHistory')?.addEventListener('click', rlClearHistory);
  document.getElementById('btnSpin')?.addEventListener('click', rlSpin);
});
