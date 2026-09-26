<?php
/**
 * CONFERÊNCIA DE OFF-SEASON — a liga inteira numa tela só.
 *
 * Nasceu em 26/09/2026, depois que o banco caiu e ~34h de trocas voltaram a
 * ser reconstruídas a partir do que cada GM lembrava no WhatsApp. O gargalo
 * não foi aplicar: foi descobrir o que estava errado, time por time.
 *
 * Aqui estão os 30 times abertos, com elenco e picks (do ano em curso pra
 * frente), e qualquer GM arruma qualquer um enquanto a janela está aberta
 * (REVISAO_ABERTA, em backend/revisao.php). Cada time termina no "Time OK".
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/revisao.php';
requireAuth();

$user = getUserSession();
$pdo  = db();
revisaoGarantirTabelas($pdo);

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch() ?: null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
  <?php include __DIR__ . '/includes/head-pwa.php'; ?>
  <title>Conferência - FBA Manager</title>
  <meta name="theme-color" content="#fc0025">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/styles.css">
  <style>
    .rv-wrap{display:flex;flex-direction:column;gap:14px;max-width:1120px}

    .rv-topo{border:1px solid var(--border-md,#2a2a31);border-radius:12px;background:var(--panel,#0e0e12);
      padding:15px 17px;display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between}
    .rv-topo h2{margin:0 0 3px;font-size:16px;font-weight:700}
    .rv-topo p{margin:0;font-size:13px;color:var(--text-2,#9aa);max-width:62ch}
    .rv-prog{display:flex;align-items:baseline;gap:8px}
    .rv-prog b{font-size:26px;font-weight:800;font-variant-numeric:tabular-nums;line-height:1}
    .rv-prog span{font-size:12px;color:var(--text-2,#9aa)}

    .rv-filtros{display:flex;flex-wrap:wrap;gap:7px}
    .rv-pill{background:var(--panel-2,#16161a);color:var(--text-2,#9aa);border:1px solid var(--border-md,#2a2a31);
      border-radius:999px;padding:6px 14px;font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit}
    .rv-pill:hover{color:var(--text,#fff)}
    .rv-pill.on{background:var(--red,#fc0025);border-color:var(--red,#fc0025);color:#fff}
    .rv-busca{flex:1;min-width:170px;background:var(--panel-2,#16161a);border:1px solid var(--border-md,#2a2a31);
      color:var(--text,#fff);border-radius:999px;padding:6px 15px;font-size:13px;font-family:inherit}

    /* ── card de time ──────────────────────────────────────────── */
    .tm{border:1px solid var(--border-md,#2a2a31);border-radius:12px;background:var(--panel,#0e0e12);
      overflow:hidden}
    .tm.ok{border-color:#1f6b38}
    .tm.torto{border-color:#7a2020}
    .tm > summary{padding:13px 16px;cursor:pointer;display:flex;align-items:center;gap:13px;
      list-style:none;flex-wrap:wrap}
    .tm > summary::-webkit-details-marker{display:none}
    .tm > summary:hover{background:var(--panel-2,#16161a)}
    .tm-seta{color:var(--text-2,#9aa);transition:transform .15s}
    .tm[open] .tm-seta{transform:rotate(90deg)}
    .tm-nome{flex:1;min-width:150px}
    .tm-nome b{display:block;font-size:15px;font-weight:700}
    .tm-nome small{color:var(--text-2,#9aa);font-size:12px}
    .tm-nums{display:flex;gap:16px;align-items:center}
    .tm-n{text-align:right}
    .tm-n b{display:block;font-size:16px;font-weight:800;font-variant-numeric:tabular-nums;line-height:1.1}
    .tm-n span{font-size:10.5px;letter-spacing:.05em;text-transform:uppercase;color:var(--text-2,#9aa)}
    .tm-n.alerta b{color:#ff5a6e}
    .tm-sel{font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;white-space:nowrap}
    .tm-sel.ok{background:color-mix(in srgb,#2fd06a 16%,transparent);color:#4ade80}
    .tm-sel.torto{background:color-mix(in srgb,#fc0025 14%,transparent);color:#ff8a97}
    .tm-sel.pend{background:var(--panel-2,#16161a);color:var(--text-2,#9aa)}

    .tm-corpo{border-top:1px solid var(--border-md,#2a2a31);padding:0}
    .tm-cols{display:grid;grid-template-columns:1fr 340px;gap:0}
    .tm-cols > div{padding:12px 16px}
    .tm-cols > div:first-child{border-right:1px solid var(--border,#1c1c22)}
    .tm-h{font-size:11.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
      color:var(--text-2,#9aa);margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;gap:8px}

    .ln{display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid var(--border,#1c1c22)}
    .ln:last-child{border-bottom:0}
    .ln-ovr{font-weight:800;font-size:14px;min-width:26px;text-align:right;font-variant-numeric:tabular-nums}
    .ln-nome{flex:1;min-width:0}
    .ln-nome b{display:block;font-size:13.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .ln-nome small{color:var(--text-2,#9aa);font-size:11.5px}
    .ln-acoes{display:flex;gap:5px;flex-shrink:0}
    .mini{background:var(--panel-2,#16161a);border:1px solid var(--border-md,#2a2a31);color:var(--text-2,#9aa);
      border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:600;cursor:pointer;font-family:inherit;
      white-space:nowrap}
    .mini:hover{color:#fff;border-color:var(--red,#fc0025)}
    .mini.perigo:hover{color:#ff5a6e;border-color:#7a2020}
    .mini.verde{border-color:#1f6b38;color:#4ade80}
    .mini.verde:hover{background:#1f9d4d;color:#fff;border-color:#1f9d4d}
    .tag{font-size:10px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;padding:0 5px;
      border-radius:3px;background:var(--panel-2,#16161a);color:var(--text-2,#9aa)}
    .vazio{color:var(--text-2,#9aa);font-size:12.5px;padding:8px 0}

    .tm-pend{padding:9px 16px;background:color-mix(in srgb,#fc0025 7%,transparent);
      border-top:1px solid var(--border,#1c1c22);font-size:12.5px;color:#ff8a97;
      display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .tm-rodape{padding:11px 16px;border-top:1px solid var(--border,#1c1c22);display:flex;
      justify-content:flex-end;gap:8px;flex-wrap:wrap;align-items:center}

    @media (max-width:820px){
      .tm-cols{grid-template-columns:1fr}
      .tm-cols > div:first-child{border-right:0;border-bottom:1px solid var(--border,#1c1c22)}
    }
    @media (max-width:560px){
      .tm > summary{gap:9px}
      .tm-nums{width:100%;justify-content:flex-start;gap:20px}
      .ln{flex-wrap:wrap}
      .ln-acoes{width:100%;justify-content:flex-end}
    }
  </style>
</head>
<body>
  <div class="app">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="sb-overlay" id="sbOverlay"></div>

    <main class="main">
      <div class="topbar">
        <button class="topbar-menu-btn" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <span class="topbar-title">
          <i class="bi bi-clipboard2-check me-2" style="color:var(--red)"></i>Conferência
        </span>
      </div>

      <div class="content">
        <div class="rv-wrap">

          <div class="rv-topo">
            <div>
              <h2>Confira os elencos e as picks da <?= htmlspecialchars(REVISAO_LIGA) ?></h2>
              <p>
                Todos os times estão abertos: se um jogador seu está no time errado, mova daqui mesmo.
                Picks do ano em curso pra frente. Cada movimento fica registrado com o seu nome.
              </p>
            </div>
            <div class="rv-prog"><b id="pgN">—</b><span id="pgT">de 30 confirmados</span></div>
          </div>

          <div class="rv-filtros">
            <input class="rv-busca" id="busca" placeholder="Buscar time ou jogador…" oninput="desenhar()">
            <button class="rv-pill on" data-f="todos" onclick="filtro(this)">Todos</button>
            <button class="rv-pill" data-f="torto" onclick="filtro(this)">Irregulares</button>
            <button class="rv-pill" data-f="pend" onclick="filtro(this)">Falta confirmar</button>
            <button class="rv-pill" data-f="ok" onclick="filtro(this)">Confirmados</button>
          </div>

          <div id="lista"><div class="vazio" style="padding:26px;text-align:center">Carregando a liga…</div></div>
        </div>
      </div>
    </main>
  </div>

  <!-- modal: mover jogador / enviar pick -->
  <div class="modal fade" id="mvModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="mvTitulo">Para qual time?</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p id="mvOque" style="font-size:14px;color:var(--text-2,#9aa)"></p>
          <select class="form-select" id="mvTime"></select>
          <div class="alert alert-danger mt-3" id="mvErro" style="display:none;font-size:13px"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-r secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn-r primary" id="mvConfirma">Mover</button>
        </div>
      </div>
    </div>
  </div>

  <!-- modal: dispensar -->
  <div class="modal fade" id="dpModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Dispensar</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <p id="dpTexto" style="font-size:14px;margin:0"></p>
          <div class="alert alert-danger mt-3" id="dpErro" style="display:none;font-size:13px"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-r secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn-r primary" id="dpConfirma">Dispensar</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  let DADOS = null, FILTRO = 'todos', ABERTOS = new Set(), mvAlvo = null, dpAlvo = null;

  async function api(url, opts) {
    const r = await fetch('/api/revisao.php' + url, opts);
    const d = await r.json().catch(() => ({}));
    if (!r.ok || d.success === false) throw new Error(d.error || 'Erro na requisição');
    return d;
  }
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const atr = s => esc(s).replace(/'/g, '&#39;');

  async function carregar(manterAbertos = true) {
    if (!manterAbertos) ABERTOS.clear();
    DADOS = await api('?action=tudo');
    desenhar();
  }

  function filtro(btn) {
    document.querySelectorAll('.rv-pill').forEach(b => b.classList.remove('on'));
    btn.classList.add('on');
    FILTRO = btn.dataset.f;
    desenhar();
  }

  function desenhar() {
    if (!DADOS) return;
    const q = (document.getElementById('busca').value || '').trim().toLowerCase();
    const ok = DADOS.times.filter(t => t.ok_em).length;
    document.getElementById('pgN').textContent = ok;
    document.getElementById('pgT').textContent = `de ${DADOS.times.length} confirmados`;

    const lista = DADOS.times.filter(t => {
      if (FILTRO === 'ok' && !t.ok_em) return false;
      if (FILTRO === 'pend' && t.ok_em) return false;
      if (FILTRO === 'torto' && !t.pendencias.length) return false;
      if (!q) return true;
      return t.nome.toLowerCase().includes(q)
          || (t.gm || '').toLowerCase().includes(q)
          || t.elenco.some(p => p.name.toLowerCase().includes(q));
    });

    document.getElementById('lista').innerHTML = lista.length
      ? lista.map(cardTime).join('')
      : '<div class="vazio" style="padding:26px;text-align:center">Nenhum time com esse filtro.</div>';
  }

  function cardTime(t) {
    const estado = t.pendencias.length ? 'torto' : (t.ok_em ? 'ok' : 'pend');
    const selo = estado === 'ok'
      ? '<span class="tm-sel ok"><i class="bi bi-check2-circle"></i> Confirmado</span>'
      : (estado === 'torto'
          ? '<span class="tm-sel torto">Irregular</span>'
          : '<span class="tm-sel pend">Falta confirmar</span>');
    const capAlerta = (t.cap > DADOS.cap_max || t.cap < DADOS.cap_min) ? ' alerta' : '';
    const jogAlerta = (t.qtd > 15 || t.qtd < 13) ? ' alerta' : '';

    return `
    <details class="tm ${estado}" data-id="${t.id}"${ABERTOS.has(t.id) ? ' open' : ''}
             ontoggle="ABERTOS[this.open?'add':'delete'](${t.id})">
      <summary>
        <i class="bi bi-chevron-right tm-seta"></i>
        <span class="tm-nome">
          <b>${esc(t.nome)}</b>
          <small>${t.gm ? esc(t.gm) : 'sem GM'}</small>
        </span>
        <span class="tm-nums">
          <span class="tm-n${jogAlerta}"><b>${t.qtd}</b><span>Jog</span></span>
          <span class="tm-n${capAlerta}"><b>${t.cap}</b><span>Cap</span></span>
        </span>
        ${selo}
      </summary>

      <div class="tm-corpo">
        ${t.pendencias.length ? `<div class="tm-pend">
            <i class="bi bi-exclamation-triangle-fill"></i>${esc(t.pendencias.join(' · '))}
          </div>` : ''}

        <div class="tm-cols">
          <div>
            <div class="tm-h"><span>Elenco</span><span>${t.qtd} jogadores</span></div>
            ${t.elenco.length ? t.elenco.map(p => `
              <div class="ln">
                <span class="ln-ovr">${p.ovr}</span>
                <span class="ln-nome">
                  <b>${esc(p.name)}</b>
                  <small>${esc(p.position)}${p.secondary_position ? ' / ' + esc(p.secondary_position) : ''}
                    · ${p.age}a · <span class="tag">${esc(p.role)}</span></small>
                </span>
                <span class="ln-acoes">
                  <button class="mini" onclick="abrirMover('jogador',${p.id},${t.id},'${atr(p.name)}')">Mover</button>
                  <button class="mini perigo" onclick="abrirDispensa(${p.id},'${atr(p.name)}','${atr(t.nome)}')">Dispensar</button>
                </span>
              </div>`).join('') : '<div class="vazio">Sem jogadores.</div>'}
          </div>

          <div>
            <div class="tm-h"><span>Picks${DADOS.ano ? ' · de ' + DADOS.ano + ' em diante' : ''}</span>
              <span>${t.picks.length}</span></div>
            ${t.picks.length ? t.picks.map(k => `
              <div class="ln">
                <span class="ln-nome">
                  <b>${k.season_year} · ${k.round}ª rodada</b>
                  <small>${esc(k.origem)}${k.swap_type ? ' · <span class="tag">swap ' + esc(k.swap_type) + '</span>' : ''}</small>
                </span>
                <span class="ln-acoes">
                  <button class="mini" onclick="abrirMover('pick',${k.id},${t.id},'${k.season_year} · ${k.round}ª (${atr(k.origem)})')">Enviar</button>
                </span>
              </div>`).join('') : '<div class="vazio">Sem picks daqui pra frente.</div>'}
          </div>
        </div>

        <div class="tm-rodape">
          ${t.ok_em
            ? `<span style="font-size:12.5px;color:#4ade80">Confirmado em ${esc(String(t.ok_em).replace('T',' ').slice(0,16))}</span>`
            : (t.pendencias.length
                ? '<span style="font-size:12.5px;color:var(--text-2,#9aa)">Arrume as pendências pra poder confirmar.</span>'
                : `<button class="mini verde" onclick="confirmarTime(${t.id})">
                     <i class="bi bi-check2-circle"></i> Time OK</button>`)}
        </div>
      </div>
    </details>`;
  }

  // ── mover ──────────────────────────────────────────────────────
  function abrirMover(tipo, id, origemId, rotulo) {
    mvAlvo = { tipo, id, origemId };
    document.getElementById('mvTitulo').textContent = tipo === 'jogador' ? 'Mover jogador' : 'Enviar pick';
    document.getElementById('mvOque').textContent =
      (tipo === 'jogador' ? 'Mandando ' : 'Mandando a pick ') + rotulo + ' para:';
    document.getElementById('mvErro').style.display = 'none';
    document.getElementById('mvTime').innerHTML = DADOS.times
      .filter(t => Number(t.id) !== Number(origemId))
      .map(t => `<option value="${t.id}">${esc(t.nome)}</option>`).join('');
    new bootstrap.Modal(document.getElementById('mvModal')).show();
  }

  document.getElementById('mvConfirma').addEventListener('click', async () => {
    const btn = document.getElementById('mvConfirma'), err = document.getElementById('mvErro');
    const destino = Number(document.getElementById('mvTime').value);
    btn.disabled = true;
    try {
      const acao = mvAlvo.tipo === 'jogador' ? 'mover_jogador' : 'mover_pick';
      const corpo = mvAlvo.tipo === 'jogador'
        ? { player_id: mvAlvo.id, to_team_id: destino }
        : { pick_id: mvAlvo.id, to_team_id: destino };
      await api('?action=' + acao, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(corpo),
      });
      ABERTOS.add(destino);
      bootstrap.Modal.getInstance(document.getElementById('mvModal')).hide();
      await carregar();
    } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
    finally { btn.disabled = false; }
  });

  // ── dispensar ──────────────────────────────────────────────────
  function abrirDispensa(id, nome, time) {
    dpAlvo = id;
    document.getElementById('dpTexto').innerHTML =
      `<b>${esc(nome)}</b> sai do ${esc(time)} e vai pra free agency. Isso não volta atrás.`;
    document.getElementById('dpErro').style.display = 'none';
    new bootstrap.Modal(document.getElementById('dpModal')).show();
  }

  document.getElementById('dpConfirma').addEventListener('click', async () => {
    const btn = document.getElementById('dpConfirma'), err = document.getElementById('dpErro');
    btn.disabled = true;
    try {
      await api('?action=dispensar', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: dpAlvo }),
      });
      bootstrap.Modal.getInstance(document.getElementById('dpModal')).hide();
      await carregar();
    } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
    finally { btn.disabled = false; }
  });

  // ── confirmar ──────────────────────────────────────────────────
  async function confirmarTime(id) {
    try {
      await api('?action=ok', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ team_id: id }),
      });
      await carregar();
    } catch (e) {
      const t = DADOS.times.find(x => Number(x.id) === Number(id));
      alert(e.message + (t ? '\n\n' + t.nome : ''));
    }
  }

  carregar().catch(e => {
    document.getElementById('lista').innerHTML =
      `<div class="vazio" style="padding:26px;text-align:center">${esc(e.message)}</div>`;
  });
  </script>
</body>
</html>
