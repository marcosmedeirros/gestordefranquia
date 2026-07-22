<?php
/**
 * Menu lateral padrão do app.
 * Requer $user (getUserSession()) e $pdo (db()) já definidos pela página.
 * $team é opcional — se não definido, o cartão do time não é exibido.
 * Uso: <?php include __DIR__ . '/includes/sidebar.php'; ?>
 */
if (!isset($pdo)) {
    $pdo = db();
}
$__sbCurrent = basename($_SERVER['SCRIPT_NAME'] ?? '');
$__sbIsAdmin = !empty($user['id']) && hasAdminAccess($pdo, (int)$user['id']);
$__sbIsElite = (($team['league'] ?? $user['league'] ?? '') === 'ELITE');

if (!function_exists('sbActive')) {
    function sbActive(string $page, string $current): string
    {
        return $page === $current ? ' class="active"' : '';
    }
}
?>
<aside class="sidebar" id="sidebar">
    <?php if (!empty($team)): ?>
    <div class="sb-team">
        <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>"
             alt="<?= htmlspecialchars(trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? ''))) ?>"
             onerror="this.src='/img/default-team.png'">
        <div>
            <div class="sb-team-name"><?= htmlspecialchars(trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? ''))) ?></div>
            <div class="sb-team-league"><?= htmlspecialchars($team['league'] ?? $user['league'] ?? '') ?></div>
        </div>
    </div>
    <?php endif; ?>

    <button type="button" class="gs-trigger" id="gsTrigger" aria-label="Buscar jogadores e times">
        <i class="bi bi-search"></i>
        <span class="gs-trigger-text">Buscar jogador ou time…</span>
        <span class="gs-kbd">Ctrl K</span>
    </button>

    <nav class="sb-nav">
        <div class="sb-section">Principal</div>
        <a href="/dashboard.php"<?= sbActive('dashboard.php', $__sbCurrent) ?>><i class="bi bi-house-door-fill"></i> Dashboard</a>
        <a href="/teams.php"<?= sbActive('teams.php', $__sbCurrent) ?>><i class="bi bi-people-fill"></i> Times</a>
        <a href="/my-roster.php"<?= sbActive('my-roster.php', $__sbCurrent) ?>><i class="bi bi-person-fill"></i> Meu Elenco</a>
        <a href="/players.php"<?= sbActive('players.php', $__sbCurrent) ?>><i class="bi bi-person-lines-fill"></i> Jogadores</a>
        <a href="/picks.php"<?= sbActive('picks.php', $__sbCurrent) ?>><i class="bi bi-calendar-check-fill"></i> Picks</a>
        <a href="/trades.php"<?= sbActive('trades.php', $__sbCurrent) ?>><i class="bi bi-arrow-left-right"></i> Trades</a>
        <a href="/mercado.php"<?= sbActive('mercado.php', $__sbCurrent) ?>><i class="bi bi-shop"></i> Mercado</a>
        <a href="/free-agency.php"<?= sbActive('free-agency.php', $__sbCurrent) ?>><i class="bi bi-coin"></i> Free Agency</a>
        <a href="/leilao.php"<?= sbActive('leilao.php', $__sbCurrent) ?>><i class="bi bi-hammer"></i> Leilão</a>
        <a href="/drafts.php"<?= sbActive('drafts.php', $__sbCurrent) ?>><i class="bi bi-trophy"></i> Draft</a>
        <?php if ($__sbIsElite): ?>
        <a href="/cap.php"<?= sbActive('cap.php', $__sbCurrent) ?>><i class="bi bi-cash-stack"></i> Salary Cap</a>
        <?php endif; ?>
        <a href="/tapas.php"<?= sbActive('tapas.php', $__sbCurrent) ?>><i class="bi bi-hand-index-thumb"></i> Tapas</a>

        <div class="sb-section">Liga</div>
        <a href="/rankings.php"<?= sbActive('rankings.php', $__sbCurrent) ?>><i class="bi bi-bar-chart-fill"></i> Rankings</a>
        <a href="/history.php"<?= sbActive('history.php', $__sbCurrent) ?>><i class="bi bi-clock-history"></i> Histórico</a>
        <a href="/hall-da-fama.php"<?= sbActive('hall-da-fama.php', $__sbCurrent) ?>><i class="bi bi-award-fill"></i> Hall da Fama</a>
        <a href="/diretrizes.php"<?= sbActive('diretrizes.php', $__sbCurrent) ?>><i class="bi bi-clipboard-data"></i> Diretrizes</a>
        <a href="/mundo-fba.php"<?= sbActive('mundo-fba.php', $__sbCurrent) ?>><i class="bi bi-globe2"></i> Mundo FBA</a>
        <a href="/estatisticas.php"<?= sbActive('estatisticas.php', $__sbCurrent) ?>><i class="bi bi-bar-chart-line-fill"></i> Estatísticas</a>
        <a href="/ouvidoria.php"<?= sbActive('ouvidoria.php', $__sbCurrent) ?>><i class="bi bi-chat-dots"></i> Ouvidoria</a>
        <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> FBA Games</a>
        <a href="/thepathetic.php"<?= sbActive('thepathetic.php', $__sbCurrent) ?>><i class="bi bi-newspaper"></i> The Pathetic</a>

        <?php if ($__sbIsAdmin): ?>
        <div class="sb-section">Admin</div>
        <a href="/admin.php"<?= sbActive('admin.php', $__sbCurrent) ?>><i class="bi bi-shield-lock-fill"></i> Admin</a>
        <a href="/punicoes.php"<?= sbActive('punicoes.php', $__sbCurrent) ?>><i class="bi bi-exclamation-triangle-fill"></i> Punições</a>
        <a href="/lottery.php"<?= sbActive('lottery.php', $__sbCurrent) ?>><i class="bi bi-shuffle"></i> Loteria (ELITE)</a>
        <?php endif; ?>

        <div class="sb-section">Conta</div>
        <a href="/team-public-page.php"<?= sbActive('team-public-page.php', $__sbCurrent) ?>><i class="bi bi-globe2"></i> Página do Time</a>
        <a href="/settings.php"<?= sbActive('settings.php', $__sbCurrent) ?>><i class="bi bi-gear-fill"></i> Minha Conta</a>
    </nav>

    <button class="sb-theme-toggle" type="button" id="themeToggle">
        <i class="bi bi-moon"></i>
        <span>Modo escuro</span>
    </button>

    <div class="sb-footer">
        <img src="<?= htmlspecialchars(getUserPhoto($user['photo_url'] ?? null)) ?>"
             alt="<?= htmlspecialchars($user['name'] ?? '') ?>"
             class="sb-avatar"
             onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name'] ?? 'U') ?>&background=1c1c21&color=<?= accentColorHex($user['accent_color'] ?? null) ?>'">
        <span class="sb-username"><?= htmlspecialchars($user['name'] ?? '') ?></span>
        <a href="/logout.php" class="sb-logout" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
    </div>
</aside>

<!-- ── Busca global (super filtro) ───────────────────────────────── -->
<style>
.gs-trigger{margin:12px 14px 0;display:flex;align-items:center;gap:8px;width:calc(100% - 28px);
  background:var(--panel-2,#16161a);border:1px solid var(--border,rgba(255,255,255,.06));
  border-radius:10px;padding:9px 11px;color:var(--text-3,#7d7d85);font-family:inherit;font-size:12.5px;
  cursor:pointer;transition:all .18s;text-align:left;flex-shrink:0}
.gs-trigger:hover{border-color:var(--red,#fc0025);color:var(--text-2,#868690)}
.gs-trigger i{font-size:13px;flex-shrink:0}
.gs-trigger-text{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gs-kbd{font-size:9px;font-weight:700;letter-spacing:.4px;padding:2px 5px;border-radius:5px;
  background:var(--panel-3,#1c1c21);border:1px solid var(--border,rgba(255,255,255,.08));flex-shrink:0}

.gs-overlay{display:none;position:fixed;inset:0;z-index:4000;background:rgba(0,0,0,.68);backdrop-filter:blur(5px);
  align-items:flex-start;justify-content:center;padding:10vh 16px 16px}
.gs-overlay.open{display:flex}
.gs-modal{width:100%;max-width:640px;background:var(--panel,#101013);border:1px solid var(--border-md,rgba(255,255,255,.10));
  border-radius:16px;box-shadow:0 30px 80px -20px rgba(0,0,0,.8);overflow:hidden;display:flex;flex-direction:column;max-height:76vh}
.gs-inputwrap{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--border,rgba(255,255,255,.06))}
.gs-inputwrap i{color:var(--red,#fc0025);font-size:16px}
.gs-input{flex:1;background:transparent;border:none;outline:none;color:var(--text,#f0f0f3);
  font-family:inherit;font-size:15px}
.gs-input::placeholder{color:var(--text-3,#7d7d85)}
.gs-esc{font-size:10px;font-weight:700;color:var(--text-3,#7d7d85);border:1px solid var(--border,rgba(255,255,255,.08));
  border-radius:5px;padding:2px 6px}
.gs-results{overflow-y:auto;padding:6px}
.gs-group-title{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;
  color:var(--text-3,#7d7d85);padding:10px 12px 6px}
.gs-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:10px;cursor:pointer;
  color:var(--text,#f0f0f3);text-decoration:none}
.gs-item:hover,.gs-item.active{background:var(--panel-2,#16161a)}
.gs-item.active{outline:1px solid var(--red,#fc0025)}
.gs-logo{width:30px;height:30px;border-radius:8px;object-fit:contain;background:var(--panel-3,#1c1c21);
  border:1px solid var(--border,rgba(255,255,255,.08));flex-shrink:0}
.gs-pos{font-size:10px;font-weight:800;color:var(--red,#fc0025);min-width:26px;text-align:center;flex-shrink:0}
.gs-main{flex:1;min-width:0}
.gs-name{font-size:13.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gs-meta{font-size:11px;color:var(--text-3,#7d7d85);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gs-tag{font-size:9px;font-weight:800;padding:2px 7px;border-radius:999px;flex-shrink:0;text-transform:uppercase;
  background:rgba(245,158,11,.14);border:1px solid rgba(245,158,11,.35);color:#f59e0b}
.gs-league{font-size:9px;font-weight:700;color:var(--text-3,#7d7d85);border:1px solid var(--border,rgba(255,255,255,.08));
  border-radius:999px;padding:2px 7px;flex-shrink:0}
.gs-empty{padding:26px 16px;text-align:center;color:var(--text-3,#7d7d85);font-size:13px}
@media(max-width:640px){.gs-overlay{padding:6vh 10px 10px}.gs-modal{max-height:84vh}}
</style>

<div class="gs-overlay" id="gsOverlay" role="dialog" aria-label="Busca">
  <div class="gs-modal">
    <div class="gs-inputwrap">
      <i class="bi bi-search"></i>
      <input type="text" class="gs-input" id="gsInput" placeholder="Buscar jogador ou time…" autocomplete="off" spellcheck="false">
      <span class="gs-esc">ESC</span>
    </div>
    <div class="gs-results" id="gsResults">
      <div class="gs-empty">Digite ao menos 2 letras para buscar.</div>
    </div>
  </div>
</div>

<script>
(function(){
  const overlay = document.getElementById('gsOverlay');
  const input   = document.getElementById('gsInput');
  const results = document.getElementById('gsResults');
  const trigger = document.getElementById('gsTrigger');
  if (!overlay || !input || !results) return;

  let items = [];      // [{href}]
  let active = -1;
  let timer = null;
  const esc = s => String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

  function open(){
    overlay.classList.add('open');
    input.value = '';
    results.innerHTML = '<div class="gs-empty">Digite ao menos 2 letras para buscar.</div>';
    items = []; active = -1;
    setTimeout(() => input.focus(), 30);
  }
  function close(){ overlay.classList.remove('open'); }

  trigger && trigger.addEventListener('click', open);
  overlay.addEventListener('mousedown', e => { if (e.target === overlay) close(); });

  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); open(); return; }
    if (!overlay.classList.contains('open')) return;
    if (e.key === 'Escape') { close(); return; }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (!items.length) return;
      active += (e.key === 'ArrowDown' ? 1 : -1);
      if (active < 0) active = items.length - 1;
      if (active >= items.length) active = 0;
      paintActive();
      return;
    }
    if (e.key === 'Enter' && active >= 0 && items[active]) { window.location.href = items[active].href; }
  });

  function paintActive(){
    const els = results.querySelectorAll('.gs-item');
    els.forEach((el,i) => el.classList.toggle('active', i === active));
    const cur = els[active];
    if (cur) cur.scrollIntoView({ block:'nearest' });
  }

  input.addEventListener('input', () => {
    clearTimeout(timer);
    const q = input.value.trim();
    if (q.length < 2) {
      results.innerHTML = '<div class="gs-empty">Digite ao menos 2 letras para buscar.</div>';
      items = []; active = -1;
      return;
    }
    timer = setTimeout(() => run(q), 250);
  });

  async function run(q){
    try {
      const res = await fetch('/api/search.php?q=' + encodeURIComponent(q));
      const d = await res.json();
      if (!d.success) { results.innerHTML = '<div class="gs-empty">Erro na busca.</div>'; return; }
      render(d);
    } catch(e) {
      results.innerHTML = '<div class="gs-empty">Erro na busca.</div>';
    }
  }

  function render(d){
    const players = d.players || [], teams = d.teams || [];
    items = []; active = -1;
    if (!players.length && !teams.length) {
      results.innerHTML = '<div class="gs-empty">Nada encontrado.</div>';
      return;
    }
    let html = '';
    if (players.length) {
      html += '<div class="gs-group-title">Jogadores</div>';
      players.forEach(p => {
        const href = '/player.php?id=' + p.id;
        items.push({ href });
        const onde = p.retired
          ? `<span class="gs-tag">Aposentado${p.last_year ? ' ' + p.last_year : ''}</span>`
          : '';
        html += `<a class="gs-item" href="${href}">
          <span class="gs-pos">${esc(p.position)}</span>
          <span class="gs-main">
            <span class="gs-name">${esc(p.name)}</span>
            <span class="gs-meta">${p.ovr}/${p.age}y${p.team_name ? ' · ' + esc(p.team_name) : ''}</span>
          </span>
          ${onde}<span class="gs-league">${esc(p.league)}</span>
        </a>`;
      });
    }
    if (teams.length) {
      html += '<div class="gs-group-title">Times</div>';
      teams.forEach(t => {
        const href = '/team-history.php?team_id=' + t.id;
        items.push({ href });
        html += `<a class="gs-item" href="${href}">
          <img class="gs-logo" src="${esc(t.photo_url)}" alt="" onerror="this.src='/img/default-team.png'">
          <span class="gs-main">
            <span class="gs-name">${esc(t.name)}</span>
            <span class="gs-meta">${esc(t.owner_name || '')}${t.conference ? ' · ' + esc(t.conference) : ''}</span>
          </span>
          <span class="gs-league">${esc(t.league)}</span>
        </a>`;
      });
    }
    results.innerHTML = html;
  }
})();
</script>
