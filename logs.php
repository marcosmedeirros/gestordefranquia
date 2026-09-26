<?php
/**
 * O SUPERLOG NA TELA — toda escrita no banco dos últimos 7 dias.
 *
 * Lê os arquivos de ~/superlog (ver backend/superlog.php). Só admin: o log
 * mostra o que cada pessoa mexeu, e isso não é para a liga inteira ver.
 *
 * É render no servidor de propósito. Uma tela que existe pra quando algo deu
 * muito errado não pode depender de JS carregando JSON por cima de uma API
 * que talvez seja parte do problema.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/superlog.php';
requireAuth();

$user = getUserSession();
$pdo  = db();
if (!hasAdminAccess($pdo, (int)$user['id'])) {
    http_response_code(403);
    exit('Sem permissão.');
}

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch() ?: null;

$espaco  = superlogEspaco();
$dias    = array_keys($espaco['arquivos']);
$dia     = $_GET['dia'] ?? ($dias[0] ?? null);
$tabela  = trim($_GET['tabela'] ?? '');
$busca   = trim($_GET['q'] ?? '');
$limite  = min(2000, max(50, (int)($_GET['n'] ?? 300)));

$linhas = superlogLer($limite * 3, $tabela !== '' ? $tabela : null, $dia);

/* A busca livre roda aqui em cima do que já veio: são no máximo alguns
   milhares de linhas, e um índice pra isso seria mais peça pra manter. */
if ($busca !== '') {
    $alvo = mb_strtolower($busca);
    $linhas = array_values(array_filter($linhas, function ($r) use ($alvo) {
        $agulha = mb_strtolower(($r['sql'] ?? '') . ' ' . json_encode($r['p'] ?? [], JSON_UNESCAPED_UNICODE)
                  . ' ' . ($r['s'] ?? '') . ' ' . ($r['tb'] ?? ''));
        return mb_strpos($agulha, $alvo) !== false;
    }));
}
$linhas = array_slice($linhas, 0, $limite);

/* Nome de quem mexeu, resolvido em uma consulta só. */
$nomes = [];
$ids = array_values(array_unique(array_filter(array_column($linhas, 'u'))));
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, name FROM users WHERE id IN ($in)");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $nomes[(int)$u['id']] = $u['name'];
}

/* As tabelas mais mexidas no dia, pros atalhos de filtro. */
$porTabela = [];
foreach (superlogLer(4000, null, $dia) as $r) {
    $t = $r['tb'] ?? '';
    if ($t !== '') $porTabela[$t] = ($porTabela[$t] ?? 0) + 1;
}
arsort($porTabela);

function lgKb(int $b): string { return $b < 1024 ? $b . ' B' : round($b / 1024, 1) . ' KB'; }
function lgVerbo(string $sql): string {
    if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)/i', $sql, $m)) return strtoupper($m[1]);
    return '?';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
  <?php include __DIR__ . '/includes/head-pwa.php'; ?>
  <title>Log do sistema - FBA Manager</title>
  <meta name="theme-color" content="#fc0025">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/styles.css">
  <style>
    :root{
      --red:#fc0025;
      --red-2:color-mix(in srgb, var(--red) 85%, white);
      --red-soft:color-mix(in srgb, var(--red) 10%, transparent);
      --red-glow:color-mix(in srgb, var(--red) 18%, transparent);
      --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
      --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
      --border-red:color-mix(in srgb, var(--red) 22%, transparent);
      --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85;
      --green:#22c55e; --amber:#f59e0b; --blue:#3b82f6;
      --sidebar-w:260px;
      --font:'Montserrat',sans-serif;
      --radius:14px; --radius-sm:10px; --radius-xs:6px;
      --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
    }
    :root[data-theme="light"]{
      --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
      --border:#e3e6ee; --border-md:#d7dbe6; --text:#12141a; --text-2:#5b6172; --text-3:#6b7080;
    }
  </style>
  <?php include __DIR__ . '/includes/shell-css.php'; ?>
  <style>
    .lg-wrap{display:flex;flex-direction:column;gap:14px;max-width:1180px}
    .lg-topo{border:1px solid var(--border-md);border-radius:12px;background:var(--panel);
      padding:15px 17px;display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between}
    .lg-topo h2{margin:0 0 3px;font-size:16px;font-weight:700}
    .lg-topo p{margin:0;font-size:13px;color:var(--text-2);max-width:66ch}
    .lg-espaco{text-align:right}
    .lg-espaco b{font-size:22px;font-weight:800;display:block;line-height:1;font-variant-numeric:tabular-nums}
    .lg-espaco span{font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:var(--text-2)}

    .lg-form{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
    .lg-form input, .lg-form select{background:var(--panel-2);border:1px solid var(--border-md);
      color:var(--text);border-radius:8px;padding:7px 12px;font-size:13px;font-family:inherit}
    .lg-form input[type=search]{flex:1;min-width:190px}
    .lg-form button{background:var(--red);border:0;color:#fff;border-radius:8px;padding:7px 18px;
      font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}
    .lg-chips{display:flex;flex-wrap:wrap;gap:6px}
    .lg-chip{background:var(--panel-2);border:1px solid var(--border-md);color:var(--text-2);
      border-radius:999px;padding:4px 12px;font-size:12px;font-weight:600;text-decoration:none}
    .lg-chip:hover{color:var(--text)}
    .lg-chip.on{background:var(--red);border-color:var(--red);color:#fff}
    .lg-chip small{opacity:.7;margin-left:5px}

    .lg-tab{border:1px solid var(--border-md);border-radius:12px;background:var(--panel);overflow:hidden}
    .lg-l{display:grid;grid-template-columns:74px 66px 132px 128px 1fr;gap:12px;padding:9px 15px;
      border-bottom:1px solid var(--border);align-items:start;font-size:13px}
    .lg-l:last-child{border-bottom:0}
    .lg-l:hover{background:var(--panel-2)}
    .lg-l.cab{background:var(--panel-2);font-size:11px;font-weight:700;letter-spacing:.07em;
      text-transform:uppercase;color:var(--text-2);position:sticky;top:0;z-index:2}
    .lg-hora{font-variant-numeric:tabular-nums;color:var(--text-2)}
    .lg-verbo{font-size:10.5px;font-weight:800;letter-spacing:.04em;padding:1px 6px;border-radius:4px;
      display:inline-block}
    .v-INSERT{background:color-mix(in srgb,#22c55e 16%,transparent);color:#4ade80}
    .v-UPDATE{background:color-mix(in srgb,#3b82f6 18%,transparent);color:#7ab0f5}
    .v-DELETE{background:color-mix(in srgb,#fc0025 16%,transparent);color:#ff8a97}
    .v-REPLACE{background:color-mix(in srgb,#f59e0b 16%,transparent);color:#fbbf24}
    .lg-tb{font-weight:600;word-break:break-word}
    .lg-quem{color:var(--text-2);word-break:break-word}
    .lg-quem small{display:block;font-size:11px;opacity:.75}
    .lg-sql{font-family:ui-monospace,Menlo,monospace;font-size:12px;line-height:1.5;word-break:break-word}
    .lg-par{color:var(--text-2);font-size:11.5px;margin-top:2px;word-break:break-word}
    .lg-n{color:var(--text-3);font-size:11px}

    .lg-vazio{padding:30px;text-align:center;color:var(--text-2);font-size:14px}
    @media (max-width:900px){
      .lg-l{grid-template-columns:1fr;gap:3px}
      .lg-l.cab{display:none}
      .lg-l{padding:12px 14px}
      .lg-hora::after{content:' · '}
      .lg-hora,.lg-verbo,.lg-tb{display:inline-block}
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
        <span class="topbar-title"><i class="bi bi-journal-text me-2" style="color:var(--red)"></i>Log do sistema</span>
      </div>

      <div class="content">
        <div class="lg-wrap">

          <div class="lg-topo">
            <div>
              <h2>Toda escrita no banco, dos últimos 7 dias</h2>
              <p>
                Gravado em arquivo fora do banco, porque em 26/09 o banco inteiro foi apagado e
                uma tabela de auditoria teria ido junto. Guarda o comando e os valores — dá pra
                refazer o que foi perdido sem perguntar pra ninguém. Some sozinho depois de 7 dias.
              </p>
            </div>
            <div class="lg-espaco">
              <b><?= lgKb($espaco['bytes']) ?></b>
              <span><?= count($espaco['arquivos']) ?> dia(s) guardados</span>
            </div>
          </div>

          <form class="lg-form" method="get">
            <select name="dia">
              <?php foreach ($dias as $d): ?>
                <option value="<?= htmlspecialchars($d) ?>"<?= $d === $dia ? ' selected' : '' ?>>
                  <?= htmlspecialchars($d) ?> (<?= lgKb($espaco['arquivos'][$d]) ?>)
                </option>
              <?php endforeach; ?>
              <?php if (!$dias): ?><option value="">sem arquivos ainda</option><?php endif; ?>
            </select>
            <input type="search" name="q" value="<?= htmlspecialchars($busca) ?>"
                   placeholder="Buscar no comando, nos valores ou no script…">
            <input type="hidden" name="tabela" value="<?= htmlspecialchars($tabela) ?>">
            <select name="n">
              <?php foreach ([100, 300, 800, 2000] as $op): ?>
                <option value="<?= $op ?>"<?= $op === $limite ? ' selected' : '' ?>><?= $op ?> linhas</option>
              <?php endforeach; ?>
            </select>
            <button type="submit"><i class="bi bi-search"></i> Filtrar</button>
          </form>

          <?php if ($porTabela): ?>
          <div class="lg-chips">
            <a class="lg-chip<?= $tabela === '' ? ' on' : '' ?>"
               href="?dia=<?= urlencode((string)$dia) ?>&q=<?= urlencode($busca) ?>&n=<?= $limite ?>">Todas</a>
            <?php foreach (array_slice($porTabela, 0, 14, true) as $tb => $qt): ?>
              <a class="lg-chip<?= $tabela === $tb ? ' on' : '' ?>"
                 href="?dia=<?= urlencode((string)$dia) ?>&tabela=<?= urlencode($tb) ?>&q=<?= urlencode($busca) ?>&n=<?= $limite ?>">
                <?= htmlspecialchars($tb) ?><small><?= $qt ?></small>
              </a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <div class="lg-tab">
            <div class="lg-l cab">
              <span>Hora</span><span>O quê</span><span>Tabela</span><span>Quem</span><span>Comando</span>
            </div>
            <?php if (!$linhas): ?>
              <div class="lg-vazio">
                <?= $espaco['bytes'] === 0
                    ? 'O log ainda não tem nada. Ele começa a encher na primeira escrita no banco.'
                    : 'Nenhuma linha com esse filtro.' ?>
              </div>
            <?php else: foreach ($linhas as $r):
              $v = lgVerbo($r['sql'] ?? '');
              $quem = isset($r['u']) && $r['u'] ? ($nomes[(int)$r['u']] ?? ('#' . $r['u'])) : null; ?>
              <div class="lg-l">
                <span class="lg-hora"><?= htmlspecialchars($r['t'] ?? '') ?></span>
                <span><span class="lg-verbo v-<?= $v ?>"><?= $v ?></span></span>
                <span class="lg-tb"><?= htmlspecialchars($r['tb'] ?? '—') ?></span>
                <span class="lg-quem">
                  <?= $quem ? htmlspecialchars($quem) : '<i style="opacity:.5">sem login</i>' ?>
                  <small><?= htmlspecialchars($r['s'] ?? '') ?></small>
                </span>
                <span>
                  <span class="lg-sql"><?= htmlspecialchars($r['sql'] ?? '') ?></span>
                  <?php if (!empty($r['p'])): ?>
                    <div class="lg-par"><?= htmlspecialchars(json_encode($r['p'], JSON_UNESCAPED_UNICODE)) ?></div>
                  <?php endif; ?>
                  <?php if (isset($r['n']) && $r['n'] !== null): ?>
                    <div class="lg-n"><?= (int)$r['n'] ?> linha(s) afetada(s)</div>
                  <?php endif; ?>
                </span>
              </div>
            <?php endforeach; endif; ?>
          </div>

          <?php if ($linhas && count($linhas) >= $limite): ?>
            <div style="font-size:12.5px;color:var(--text-2)">
              Mostrando as <?= $limite ?> mais recentes. Aumente o limite acima pra ver mais fundo.
            </div>
          <?php endif; ?>

        </div>
      </div>
    </main>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
