<?php
/**
 * CONFERÊNCIA DE OFF-SEASON — a tela do GM.
 *
 * Mostra o elenco e as picks do time dele com o que está fora da regra em
 * destaque, deixa arrumar ali mesmo (mover, dispensar, mandar ou puxar pick)
 * e termina no botão "Meu time está OK". Ver backend/revisao.php pra história.
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
$team    = $stmtTeam->fetch() ?: null;
$teamId  = $team['id'] ?? null;
$ehAdmin = hasAdminAccess($pdo, (int)$user['id']);

// Admin pode abrir o time de qualquer um pra ajudar.
$verTime = $teamId ? (int)$teamId : 0;
if ($ehAdmin && !empty($_GET['time'])) $verTime = (int)$_GET['time'];

$daLiga = $team && ($team['league'] ?? '') === REVISAO_LIGA;
if ($ehAdmin && $verTime) $daLiga = true;
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
    .rv-wrap{display:flex;flex-direction:column;gap:18px;max-width:1040px}

    /* ── faixa de situação ─────────────────────────────────────── */
    .rv-status{border-radius:12px;padding:16px 18px;border:1px solid var(--border-md,#2a2a31);
      background:var(--panel,#0e0e12);display:flex;flex-wrap:wrap;gap:16px;align-items:center;
      justify-content:space-between}
    .rv-status.ruim{border-color:#7a2020;background:color-mix(in srgb,#fc0025 8%,var(--panel,#0e0e12))}
    .rv-status.bom{border-color:#1f6b38;background:color-mix(in srgb,#2fd06a 8%,var(--panel,#0e0e12))}
    .rv-status h2{font-size:17px;font-weight:700;margin:0 0 4px}
    .rv-status p{margin:0;font-size:13.5px;color:var(--text-2,#9aa)}
    .rv-nums{display:flex;gap:22px;flex-wrap:wrap}
    .rv-num b{display:block;font-size:22px;font-weight:800;line-height:1;font-variant-numeric:tabular-nums}
    .rv-num span{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--text-2,#9aa)}
    .rv-num.alerta b{color:#ff5a6e}

    .rv-pend{list-style:none;margin:8px 0 0;padding:0;display:flex;flex-direction:column;gap:4px}
    .rv-pend li{font-size:13.5px;color:#ff8a97;display:flex;gap:7px;align-items:flex-start}
    .rv-pend li i{margin-top:2px}

    /* ── blocos ────────────────────────────────────────────────── */
    .rv-box{border:1px solid var(--border-md,#2a2a31);border-radius:12px;background:var(--panel,#0e0e12);
      overflow:hidden}
    .rv-box > header{padding:13px 16px;border-bottom:1px solid var(--border-md,#2a2a31);
      display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
    .rv-box > header h3{margin:0;font-size:14px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
    .rv-box > header .cnt{font-size:12.5px;color:var(--text-2,#9aa)}

    .rv-linha{display:flex;align-items:center;gap:12px;padding:10px 16px;
      border-bottom:1px solid var(--border,#1c1c22)}
    .rv-linha:last-child{border-bottom:0}
    .rv-linha:hover{background:var(--panel-2,#16161a)}
    .rv-ovr{font-weight:800;font-size:15px;min-width:30px;text-align:right;font-variant-numeric:tabular-nums}
    .rv-nome{flex:1;min-width:0}
    .rv-nome b{display:block;font-size:14.5px;font-weight:600;white-space:nowrap;overflow:hidden;
      text-overflow:ellipsis}
    .rv-nome small{color:var(--text-2,#9aa);font-size:12px}
    .rv-acoes{display:flex;gap:6px;flex-shrink:0}
    .rv-mini{background:var(--panel-2,#16161a);border:1px solid var(--border-md,#2a2a31);
      color:var(--text-2,#9aa);border-radius:7px;padding:5px 10px;font-size:12.5px;font-weight:600;
      cursor:pointer;font-family:inherit;white-space:nowrap}
    .rv-mini:hover{color:#fff;border-color:var(--red,#fc0025)}
    .rv-mini.perigo:hover{color:#ff5a6e;border-color:#7a2020}
    .rv-tag{font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
      padding:1px 6px;border-radius:4px;background:var(--panel-2,#16161a);color:var(--text-2,#9aa)}

    .rv-vazio{padding:22px 16px;text-align:center;color:var(--text-2,#9aa);font-size:13.5px}

    /* ── confirmar ─────────────────────────────────────────────── */
    .rv-fim{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;
      padding:18px;border:1px dashed var(--border-md,#2a2a31);border-radius:12px}
    .rv-ok-btn{background:#1f9d4d;border:0;color:#fff;border-radius:9px;padding:12px 22px;
      font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;gap:9px;align-items:center}
    .rv-ok-btn:hover{background:#25b95c}
    .rv-ok-btn:disabled{opacity:.45;cursor:not-allowed}

    /* ── painel do admin ───────────────────────────────────────── */
    .rv-painel{display:grid;gap:8px;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));padding:14px 16px}
    .rv-card{border:1px solid var(--border-md,#2a2a31);border-radius:9px;padding:10px 12px;
      background:var(--panel-2,#16161a);font-size:13px}
    .rv-card.ok{border-color:#1f6b38}
    .rv-card.torto{border-color:#7a2020}
    .rv-card b{display:block;font-size:13.5px;margin-bottom:3px}
    .rv-card span{color:var(--text-2,#9aa);font-size:12px}
    .rv-card .pr{color:#ff8a97;font-size:12px}

    @media (max-width:640px){
      .rv-linha{flex-wrap:wrap}
      .rv-acoes{width:100%;justify-content:flex-end}
      .rv-status{flex-direction:column;align-items:flex-start}
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
        <?php if (!$daLiga): ?>
          <div class="rv-box"><div class="rv-vazio">
            <i class="bi bi-info-circle" style="font-size:22px;display:block;margin-bottom:8px"></i>
            A conferência está aberta só para a <b><?= htmlspecialchars(REVISAO_LIGA) ?></b> por enquanto.
          </div></div>
        <?php else: ?>

        <div class="rv-wrap">

          <?php if ($ehAdmin): ?>
          <div class="rv-box">
            <header>
              <h3><i class="bi bi-people me-2"></i>Quem já confirmou</h3>
              <span class="cnt" id="pnResumo">carregando…</span>
            </header>
            <div class="rv-painel" id="pnGrid"></div>
          </div>
          <?php endif; ?>

          <!-- situação do time -->
          <div class="rv-status" id="rvStatus">
            <div>
              <h2 id="rvNome">—</h2>
              <p id="rvFrase">Carregando o time…</p>
              <ul class="rv-pend" id="rvPend"></ul>
            </div>
            <div class="rv-nums">
              <div class="rv-num" id="nJog"><b>—</b><span>Jogadores</span></div>
              <div class="rv-num" id="nCap"><b>—</b><span>Cap (10 melhores)</span></div>
            </div>
          </div>

          <!-- elenco -->
          <div class="rv-box">
            <header>
              <h3><i class="bi bi-person-lines-fill me-2"></i>Elenco</h3>
              <span class="cnt" id="cntElenco"></span>
            </header>
            <div id="rvElenco"><div class="rv-vazio">Carregando…</div></div>
          </div>

          <!-- picks -->
          <div class="rv-box">
            <header>
              <h3><i class="bi bi-calendar-check me-2"></i>Picks</h3>
              <button class="rv-mini" onclick="abrirPuxar()">
                <i class="bi bi-box-arrow-in-down"></i> Pegar pick de outro time
              </button>
            </header>
            <div id="rvPicks"><div class="rv-vazio">Carregando…</div></div>
          </div>

          <!-- confirmar -->
          <div class="rv-fim">
            <div>
              <div style="font-weight:700;font-size:15px" id="okTitulo">Está tudo certo?</div>
              <div style="font-size:13px;color:var(--text-2,#9aa)" id="okAjuda">
                Confirme só quando o elenco e as picks estiverem do jeito que ficaram na off-season.
              </div>
            </div>
            <button class="rv-ok-btn" id="btnOk" onclick="confirmarOk()">
              <i class="bi bi-check2-circle"></i> Meu time está OK
            </button>
          </div>

        </div>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <!-- modal: mover jogador / mandar pick -->
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

  <!-- modal: pegar pick de outro time -->
  <div class="modal fade" id="pxModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Pegar pick de outro time</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label class="form-label" style="font-size:13px;font-weight:600">De qual time</label>
          <select class="form-select mb-3" id="pxTime" onchange="carregarPicksDe()"></select>
          <label class="form-label" style="font-size:13px;font-weight:600">Qual pick</label>
          <select class="form-select" id="pxPick"><option value="">Escolha o time primeiro</option></select>
          <div class="alert alert-danger mt-3" id="pxErro" style="display:none;font-size:13px"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-r secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn-r primary" onclick="puxarPick()">Trazer pra mim</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  const TIME_ID = <?= (int)$verTime ?>;
  const EH_ADMIN = <?= $ehAdmin ? 'true' : 'false' ?>;
  let EST = null;          // último estado carregado
  let mvAlvo = null;       // o que o modal de mover está movendo

  async function api(url, opts) {
    const r = await fetch('/api/revisao.php' + url, opts);
    const d = await r.json().catch(() => ({}));
    if (!r.ok || d.success === false) throw new Error(d.error || 'Erro na requisição');
    return d;
  }
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

  // ── carregar e desenhar ────────────────────────────────────────
  async function carregar() {
    const d = await api('?action=estado' + (TIME_ID ? '&team_id=' + TIME_ID : ''));
    EST = d;
    document.getElementById('rvNome').textContent = d.nome;

    const st = document.getElementById('rvStatus');
    const pend = document.getElementById('rvPend');
    st.classList.remove('bom', 'ruim');
    pend.innerHTML = '';

    if (d.pendencias.length) {
      st.classList.add('ruim');
      document.getElementById('rvFrase').textContent = 'O time está fora das regras:';
      pend.innerHTML = d.pendencias.map(p =>
        `<li><i class="bi bi-exclamation-triangle-fill"></i>${esc(p)}</li>`).join('');
    } else if (d.ok_em) {
      st.classList.add('bom');
      document.getElementById('rvFrase').textContent =
        'Confirmado em ' + d.ok_em.replace('T', ' ').slice(0, 16) + '.';
    } else {
      st.classList.add('bom');
      document.getElementById('rvFrase').textContent =
        'Dentro das regras. Falta você confirmar que o elenco e as picks estão certos.';
    }

    const nj = document.getElementById('nJog');
    nj.querySelector('b').textContent = d.qtd;
    nj.classList.toggle('alerta', d.qtd > 15 || d.qtd < 13);
    const nc = document.getElementById('nCap');
    nc.querySelector('b').textContent = d.cap;
    nc.querySelector('span').textContent = `Cap · teto ${d.cap_max}`;
    nc.classList.toggle('alerta', d.cap > d.cap_max || d.cap < d.cap_min);

    // elenco
    document.getElementById('cntElenco').textContent = d.qtd + ' jogadores';
    document.getElementById('rvElenco').innerHTML = d.elenco.length ? d.elenco.map(p => `
      <div class="rv-linha">
        <span class="rv-ovr">${p.ovr}</span>
        <span class="rv-nome">
          <b>${esc(p.name)}</b>
          <small>${esc(p.position)}${p.secondary_position ? ' / ' + esc(p.secondary_position) : ''}
            · ${p.age}a · <span class="rv-tag">${esc(p.role)}</span></small>
        </span>
        <span class="rv-acoes">
          <button class="rv-mini" onclick="abrirMover('jogador',${p.id},'${esc(p.name).replace(/'/g, "\\'")}')">Mover</button>
          <button class="rv-mini perigo" onclick="dispensar(${p.id},'${esc(p.name).replace(/'/g, "\\'")}')">Dispensar</button>
        </span>
      </div>`).join('') : '<div class="rv-vazio">Sem jogadores.</div>';

    // picks
    document.getElementById('rvPicks').innerHTML = d.picks.length ? d.picks.map(k => `
      <div class="rv-linha">
        <span class="rv-nome">
          <b>${k.season_year} · ${k.round}ª rodada</b>
          <small>${esc(k.origem)}${k.swap_type ? ' · <span class="rv-tag">swap ' + esc(k.swap_type) + '</span>' : ''}</small>
        </span>
        <span class="rv-acoes">
          <button class="rv-mini" onclick="abrirMover('pick',${k.id},'${k.season_year} · ${k.round}ª (${esc(k.origem).replace(/'/g, "\\'")})')">Enviar</button>
        </span>
      </div>`).join('') : '<div class="rv-vazio">Sem picks.</div>';

    // botão de confirmar
    const btn = document.getElementById('btnOk');
    if (d.ok_em) {
      btn.disabled = true;
      btn.innerHTML = '<i class="bi bi-check2-all"></i> Time confirmado';
      document.getElementById('okTitulo').textContent = 'Confirmado';
      document.getElementById('okAjuda').textContent =
        'Se mexer em algo depois disso, a confirmação cai e você precisa confirmar de novo.';
    } else {
      btn.disabled = d.pendencias.length > 0;
      btn.innerHTML = '<i class="bi bi-check2-circle"></i> Meu time está OK';
      document.getElementById('okTitulo').textContent =
        d.pendencias.length ? 'Arrume as pendências acima' : 'Está tudo certo?';
      document.getElementById('okAjuda').textContent = d.pendencias.length
        ? 'O botão libera quando o elenco e o cap estiverem dentro da regra.'
        : 'Confirme só quando o elenco e as picks estiverem do jeito que ficaram na off-season.';
    }
  }

  // ── ações ──────────────────────────────────────────────────────
  function abrirMover(tipo, id, rotulo) {
    mvAlvo = { tipo, id };
    document.getElementById('mvTitulo').textContent =
      tipo === 'jogador' ? 'Mover jogador' : 'Enviar pick';
    document.getElementById('mvOque').textContent =
      (tipo === 'jogador' ? 'Mandando ' : 'Mandando a pick ') + rotulo + ' para:';
    document.getElementById('mvErro').style.display = 'none';
    const sel = document.getElementById('mvTime');
    sel.innerHTML = EST.times.filter(t => Number(t.id) !== Number(EST.team_id))
      .map(t => `<option value="${t.id}">${esc(t.nome)}</option>`).join('');
    new bootstrap.Modal(document.getElementById('mvModal')).show();
  }

  document.getElementById('mvConfirma').addEventListener('click', async () => {
    const btn = document.getElementById('mvConfirma');
    const err = document.getElementById('mvErro');
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
      bootstrap.Modal.getInstance(document.getElementById('mvModal')).hide();
      await carregar();
      if (EH_ADMIN) carregarPainel();
    } catch (e) {
      err.textContent = e.message; err.style.display = 'block';
    } finally { btn.disabled = false; }
  });

  async function dispensar(id, nome) {
    /* Dispensa não volta atrás: o jogador cai na free agency e some do elenco. */
    if (!window.fbaConfirm) {
      if (!confirmarSimples(nome)) return;
    } else if (!await window.fbaConfirm(`Dispensar ${nome}? Ele vai pra free agency.`)) return;
    try {
      await api('?action=dispensar', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: id }),
      });
      await carregar();
      if (EH_ADMIN) carregarPainel();
    } catch (e) { alertaSimples(e.message); }
  }

  function confirmarSimples(nome) { return window.confirm(`Dispensar ${nome}? Ele vai pra free agency.`); }
  function alertaSimples(msg) { window.fbaAlert ? window.fbaAlert(msg) : window.alert(msg); }

  function abrirPuxar() {
    document.getElementById('pxErro').style.display = 'none';
    document.getElementById('pxPick').innerHTML = '<option value="">Escolha o time primeiro</option>';
    document.getElementById('pxTime').innerHTML =
      '<option value="">Escolha…</option>' +
      EST.times.filter(t => Number(t.id) !== Number(EST.team_id))
        .map(t => `<option value="${t.id}">${esc(t.nome)}</option>`).join('');
    new bootstrap.Modal(document.getElementById('pxModal')).show();
  }

  async function carregarPicksDe() {
    const id = document.getElementById('pxTime').value;
    const sel = document.getElementById('pxPick');
    if (!id) { sel.innerHTML = '<option value="">Escolha o time primeiro</option>'; return; }
    sel.innerHTML = '<option value="">Carregando…</option>';
    try {
      const d = await api('?action=picks_time&team_id=' + id);
      sel.innerHTML = d.picks.length
        ? '<option value="">Escolha a pick</option>' + d.picks.map(k =>
            `<option value="${k.id}"${k.swap_type ? ' disabled' : ''}>${k.season_year} · ${k.round}ª (${esc(k.origem)})${k.swap_type ? ' — em swap' : ''}</option>`).join('')
        : '<option value="">Esse time não tem picks</option>';
    } catch (e) { sel.innerHTML = '<option value="">Erro ao carregar</option>'; }
  }

  async function puxarPick() {
    const err = document.getElementById('pxErro');
    const pick = Number(document.getElementById('pxPick').value);
    if (!pick) { err.textContent = 'Escolha a pick.'; err.style.display = 'block'; return; }
    try {
      await api('?action=mover_pick', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ pick_id: pick, to_team_id: EST.team_id }),
      });
      bootstrap.Modal.getInstance(document.getElementById('pxModal')).hide();
      await carregar();
      if (EH_ADMIN) carregarPainel();
    } catch (e) { err.textContent = e.message; err.style.display = 'block'; }
  }

  async function confirmarOk() {
    const btn = document.getElementById('btnOk');
    btn.disabled = true;
    try {
      await api('?action=ok', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ team_id: EST.team_id }),
      });
      await carregar();
      if (EH_ADMIN) carregarPainel();
    } catch (e) { alertaSimples(e.message); btn.disabled = false; }
  }

  // ── painel do admin ────────────────────────────────────────────
  async function carregarPainel() {
    try {
      const d = await api('?action=painel');
      const ok = d.times.filter(t => t.ok_em).length;
      document.getElementById('pnResumo').textContent = `${ok} de ${d.times.length} confirmaram`;
      document.getElementById('pnGrid').innerHTML = d.times.map(t => `
        <a class="rv-card ${t.ok_em ? 'ok' : (t.problemas.length ? 'torto' : '')}"
           href="/revisao.php?time=${t.id}" style="text-decoration:none;color:inherit;display:block">
          <b>${esc(t.nome)}</b>
          <span>${t.qtd} jog · cap ${t.cap}${t.gm ? ' · ' + esc(t.gm) : ''}</span>
          ${t.ok_em ? '<div style="color:#4ade80;font-size:12px;margin-top:3px"><i class="bi bi-check2-circle"></i> confirmado</div>'
                    : (t.problemas.length ? `<div class="pr">${esc(t.problemas.join(' · '))}</div>` : '')}
        </a>`).join('');
    } catch (e) { document.getElementById('pnResumo').textContent = 'erro ao carregar'; }
  }

  <?php if ($daLiga): ?>
  carregar().catch(e => {
    document.getElementById('rvFrase').textContent = e.message;
  });
  <?php if ($ehAdmin): ?>carregarPainel();<?php endif; ?>
  <?php endif; ?>
  </script>
</body>
</html>
