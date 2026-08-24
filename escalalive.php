<?php
/**
 * A ESCALA DAS LIVES.
 *
 * Duas coisas numa tela só, porque são dois lados da mesma semana: quem se
 * ofereceu, e quem foi escalado. Separar em páginas obrigaria o admin a ir
 * e voltar pra montar uma escala — que é justamente olhar a lista e
 * escolher dela.
 *
 * Quem não é admin vê a mesma tela sem os botões: dá pra conferir quem está
 * escalado no quê sem precisar perguntar no grupo.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/escala_live.php';

requireAuth();
$user = getUserSession();
$pdo  = db();
escalaGarantirTabelas($pdo);

$souAdminGeral = hasGlobalAdminAccess($pdo, (int)$user['id']);
$ligasAdmin = array_values(array_intersect(
    array_map('strtoupper', getAdminLeagues($pdo, (int)$user['id'])),
    CALENDARIO_LIGAS
));
// Admin geral manda em todas; admin de liga, só na dele. A escala é da liga,
// então quem administra a NEXT não escala gente na live da ELITE.
if ($souAdminGeral) $ligasAdmin = CALENDARIO_LIGAS;

$semana = escalaSemanaDe($_GET['semana'] ?? null);
$fim    = (new DateTimeImmutable($semana))->modify('+6 days')->format('Y-m-d');

$liga = strtoupper(trim((string)($_GET['liga'] ?? '')));
if (!in_array($liga, CALENDARIO_LIGAS, true)) {
    $liga = strtoupper((string)($user['league'] ?? '')) ?: CALENDARIO_LIGAS[0];
    if (!in_array($liga, CALENDARIO_LIGAS, true)) $liga = CALENDARIO_LIGAS[0];
}
$podeEscalar = in_array($liga, $ligasAdmin, true);

$msg = $erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['escalar']) && $podeEscalar) {
            [$ok, $txt] = escalaEscalar(
                $pdo, (int)$_POST['evento_id'], (string)$_POST['data'], $liga,
                (string)$_POST['funcao'], (int)$_POST['usuario'], (int)$user['id']
            );
            $_SESSION['escala_flash'] = [$ok ? 'ok' : 'erro', $txt];

        } elseif (isset($_POST['tirar']) && $podeEscalar) {
            [$ok, $txt] = escalaTirar($pdo, (int)$_POST['tirar'], $ligasAdmin);
            $_SESSION['escala_flash'] = [$ok ? 'ok' : 'erro', $txt];

        } elseif (isset($_POST['eu_topo'])) {
            // Qualquer pessoa pode se oferecer pela tela — o grupo é o caminho
            // normal, mas quem não está nele não fica de fora por isso.
            $r = escalaResponder($pdo, (int)$user['id'], $liga,
                                 (array)($_POST['funcao'] ?? []), 'site', $semana);
            $_SESSION['escala_flash'] = $r['ok']
                ? ['ok', $r['funcoes'] ? 'Anotado. Você entrou na lista.' : 'Você saiu da lista desta semana.']
                : ['erro', (string)$r['erro']];
        }
    } catch (Throwable $e) {
        $_SESSION['escala_flash'] = ['erro', 'Deu erro: ' . $e->getMessage()];
    }
    header('Location: /escalalive.php?liga=' . urlencode($liga) . '&semana=' . urlencode($semana));
    exit;
}

if (!empty($_SESSION['escala_flash'])) {
    [$t, $x] = $_SESSION['escala_flash'];
    unset($_SESSION['escala_flash']);
    if ($t === 'ok') $msg = $x; else $erro = $x;
}

$FUNCOES     = escalaFuncoes();
$disponiveis = escalaDisponiveis($pdo, $liga, $semana);
$lives       = escalaLivesDaSemana($pdo, [$liga], $semana);
$escalados   = escalaDaSemana($pdo, [$liga], $semana);

// O que EU topei nesta semana — pra tela abrir com as caixas já marcadas.
$euTopo = [];
foreach ($disponiveis as $f => $gente) {
    foreach ($gente as $g) if ((int)$g['id'] === (int)$user['id']) $euTopo[] = $f;
}

$semanaAnterior = (new DateTimeImmutable($semana))->modify('-7 days')->format('Y-m-d');
$semanaSeguinte = (new DateTimeImmutable($semana))->modify('+7 days')->format('Y-m-d');
$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$DIAS = ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#fc0025">
<title>Escala das Lives — FBA Manager</title>
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  :root { --red:#fc0025; --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
          --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
          --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85; --green:#22c55e;
          --font:'Montserrat',sans-serif; --radius:14px; --radius-sm:10px; }
  :root[data-theme="light"] { --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
          --border:#e3e6ee; --border-md:#d7dbe6; --text:#12141a; --text-2:#5a6070; --text-3:#7a8092; }
  *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font-family:var(--font);font-size:14px}
  .wrap{max-width:1040px;margin:0 auto;padding:22px 16px 60px}
  .topo{display:flex;align-items:center;gap:12px;margin-bottom:4px}
  .topo h1{font-size:20px;font-weight:800;margin:0}
  .topo a.volta{color:var(--text-3);text-decoration:none;font-size:16px}
  .sub{color:var(--text-3);font-size:13px;margin-bottom:18px;max-width:70ch;line-height:1.55}
  .aviso{border-radius:var(--radius-sm);padding:11px 15px;font-size:13px;margin-bottom:14px}
  .aviso.ok{background:color-mix(in srgb,var(--green) 10%,transparent);border:1px solid color-mix(in srgb,var(--green) 30%,transparent);color:var(--green)}
  .aviso.bad{background:color-mix(in srgb,var(--red) 10%,transparent);border:1px solid color-mix(in srgb,var(--red) 30%,transparent);color:var(--red)}

  .barra{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:18px}
  .pill{background:var(--panel-2);border:1px solid var(--border-md);color:var(--text-2);
        border-radius:20px;padding:5px 14px 5px 6px;font-size:12.5px;font-weight:700;
        text-decoration:none;display:inline-flex;align-items:center;gap:7px}
  .pill img{width:22px;height:22px;object-fit:contain;flex:none}
  /* A pill apagada fica com o escudo dessaturado: a liga escolhida é a
     única a cores, e isso se lê antes do texto. */
  .pill:not(.on) img{filter:grayscale(1) opacity(.55)}
  .pill:hover img{filter:none}
  .live-logo{width:26px;height:26px;object-fit:contain;flex:none}
  .pill.on{background:color-mix(in srgb,var(--red) 10%,transparent);border-color:color-mix(in srgb,var(--red) 22%,transparent);color:var(--red)}
  .semana{margin-left:auto;display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--text-2)}
  .semana a{color:var(--text-3);text-decoration:none;font-size:15px}

  .cx{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;margin-bottom:14px}
  .cx h2{font-size:12px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3);margin:0 0 12px}

  .funcs{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
  .func-tit{display:flex;align-items:center;gap:7px;font-size:12.5px;font-weight:800;margin-bottom:8px}
  .gente{display:flex;flex-direction:column;gap:5px}
  .p{display:flex;align-items:center;gap:8px;font-size:12.5px;padding:4px 6px;border-radius:8px}
  .p:hover{background:var(--panel-2)}
  .p img{width:24px;height:24px;border-radius:50%;object-fit:cover;flex:none;border:1px solid var(--border-md)}
  .p span{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .ninguem{font-size:12px;color:var(--text-3);padding:4px 6px}

  .live{border:1px solid var(--border);border-radius:var(--radius-sm);padding:13px 15px;margin-bottom:10px;background:var(--panel-2)}
  .live-head{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:10px}
  .live-dia{font-size:13.5px;font-weight:800}
  .live-hora{font-size:12px;color:var(--red);font-weight:800}
  .live-tit{font-size:12.5px;color:var(--text-2);flex:1;min-width:0}
  .vaga{display:flex;align-items:center;gap:9px;padding:6px 0;border-top:1px solid var(--border);flex-wrap:wrap}
  .vaga-rot{width:118px;flex:none;font-size:11.5px;font-weight:800;display:flex;align-items:center;gap:6px}
  .chips{display:flex;gap:6px;flex-wrap:wrap;flex:1;min-width:140px}
  .chip{display:inline-flex;align-items:center;gap:6px;background:var(--panel-3);border:1px solid var(--border-md);
        border-radius:20px;padding:3px 6px 3px 10px;font-size:12px;font-weight:700}
  .chip button{background:none;border:0;color:var(--text-3);cursor:pointer;font-size:13px;line-height:1;padding:0 2px}
  .chip button:hover{color:var(--red)}
  .vazio-vaga{font-size:11.5px;color:var(--text-3)}
  select,.bt{font-family:var(--font);font-size:12px;border-radius:8px;padding:5px 9px}
  select{background:var(--panel-3);border:1px solid var(--border-md);color:var(--text);max-width:170px}
  .bt{background:var(--red);border:0;color:#fff;font-weight:700;cursor:pointer;padding:6px 13px}
  .bt:hover{filter:brightness(1.1)}
  .bt.sec{background:var(--panel-3);color:var(--text-2);border:1px solid var(--border-md)}

  .topo-check{display:flex;flex-wrap:wrap;gap:9px;margin-bottom:12px}
  .chk{display:flex;align-items:center;gap:7px;background:var(--panel-2);border:1px solid var(--border-md);
       border-radius:20px;padding:7px 14px;cursor:pointer;font-size:12.5px;font-weight:700;user-select:none}
  .chk input{width:15px;height:15px;accent-color:var(--red);cursor:pointer}
  .vazio{text-align:center;padding:30px 16px;color:var(--text-3);font-size:13px}
  @media (max-width:620px){ .vaga-rot{width:100%} select{max-width:100%;flex:1} }
</style>
</head>
<body>
<div class="wrap">
  <div class="topo">
    <!-- Volta pro ADMIN e não pro painel: quem chega aqui vem do card em
         Gestão, e mandar a pessoa pro dashboard obrigaria a refazer o
         caminho inteiro pra escalar a próxima liga. -->
    <a class="volta" href="/admin.php" title="Voltar ao admin"><i class="bi bi-arrow-left"></i></a>
    <h1>Escala das Lives</h1>
  </div>
  <div class="sub">
    Todo domingo o bot abre a chamada no grupo. Quem topa participar responde
    dizendo as funções — dá pra dizer mais de uma. Depois a escala é montada
    em cima das lives que já estão no calendário, e quem entra recebe aviso.
  </div>

  <?php if ($msg): ?><div class="aviso ok"><i class="bi bi-check-circle-fill"></i> <?= $esc($msg) ?></div><?php endif; ?>
  <?php if ($erro): ?><div class="aviso bad"><i class="bi bi-exclamation-triangle-fill"></i> <?= $esc($erro) ?></div><?php endif; ?>

  <div class="barra">
    <?php foreach (CALENDARIO_LIGAS as $lg): ?>
    <a class="pill <?= $lg === $liga ? 'on' : '' ?>"
       href="/escalalive.php?liga=<?= $lg ?>&semana=<?= $esc($semana) ?>">
      <!-- O escudo e reconhecido antes da palavra: quem vem escalar a NEXT
           acha o botao pela cor do escudo, e nao lendo quatro siglas. -->
      <img src="/img/logo-<?= strtolower($lg) ?>.png" alt="" onerror="this.style.display='none'">
      <?= $lg ?>
    </a>
    <?php endforeach; ?>
    <div class="semana">
      <a href="/escalalive.php?liga=<?= $liga ?>&semana=<?= $semanaAnterior ?>" title="Semana anterior"><i class="bi bi-chevron-left"></i></a>
      <?= date('d/m', strtotime($semana)) ?> — <?= date('d/m', strtotime($fim)) ?>
      <a href="/escalalive.php?liga=<?= $liga ?>&semana=<?= $semanaSeguinte ?>" title="Próxima semana"><i class="bi bi-chevron-right"></i></a>
    </div>
  </div>

  <!-- ── Eu topo ─────────────────────────────────────────────────────── -->
  <div class="cx">
    <h2>Eu topo, nesta semana</h2>
    <form method="POST">
      <div class="topo-check">
        <?php foreach ($FUNCOES as $k => $f): ?>
        <label class="chk">
          <input type="checkbox" name="funcao[]" value="<?= $k ?>" <?= in_array($k, $euTopo, true) ? 'checked' : '' ?>>
          <i class="bi <?= $f['icone'] ?>" style="color:<?= $f['cor'] ?>"></i>
          <?= $esc($f['rotulo']) ?>
        </label>
        <?php endforeach; ?>
      </div>
      <!-- Salvar com tudo desmarcado é como se sai da semana — por isso o
           botão não fica desabilitado quando não há nada marcado. -->
      <button class="bt" name="eu_topo" value="1">Salvar minha disponibilidade</button>
      <span style="font-size:11.5px;color:var(--text-3);margin-left:8px">
        Desmarcar tudo e salvar tira você da lista.
      </span>
    </form>
  </div>

  <!-- ── Quem se ofereceu ────────────────────────────────────────────── -->
  <div class="cx">
    <h2>Quem se ofereceu — <?= $liga ?></h2>
    <div class="funcs">
      <?php foreach ($FUNCOES as $k => $f): ?>
      <div>
        <div class="func-tit" style="color:<?= $f['cor'] ?>">
          <i class="bi <?= $f['icone'] ?>"></i> <?= $esc($f['rotulo']) ?>
          <span style="color:var(--text-3);font-weight:700">(<?= count($disponiveis[$k]) ?>)</span>
        </div>
        <div class="gente">
          <?php if (!$disponiveis[$k]): ?>
            <div class="ninguem">Ninguém ainda.</div>
          <?php else: foreach ($disponiveis[$k] as $g): ?>
            <div class="p">
              <img src="<?= $esc($g['foto'] ?: '/img/default-avatar.png') ?>" alt=""
                   onerror="this.src='/img/default-avatar.png'">
              <span><?= $esc($g['nome']) ?></span>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── A escala ────────────────────────────────────────────────────── -->
  <div class="cx">
    <h2>A escala da semana</h2>
    <?php if (!$lives): ?>
      <div class="vazio">
        Nenhuma live da <?= $liga ?> no calendário desta semana.<br>
        <a href="/calendario.php" style="color:var(--red);text-decoration:none">Marcar no calendário</a>
        — a escala é montada em cima do que está lá.
      </div>
    <?php else: foreach ($lives as $lv):
      $dia = (int)date('w', strtotime($lv['data'])); ?>
      <div class="live">
        <div class="live-head">
          <img class="live-logo" src="/img/logo-<?= strtolower($liga) ?>.png" alt=""
               onerror="this.style.display='none'">
          <span class="live-dia"><?= $DIAS[$dia] ?>, <?= date('d/m', strtotime($lv['data'])) ?></span>
          <?php if (empty($lv['dia_inteiro'])): ?>
          <span class="live-hora"><?= substr((string)$lv['inicio'], 11, 5) ?></span>
          <?php endif; ?>
          <span class="live-tit"><?= $esc($lv['titulo']) ?></span>
        </div>

        <?php foreach ($FUNCOES as $k => $f):
          $chave = $lv['id'] . '|' . $lv['data'] . '|' . $k;
          $nessa = $escalados[$chave] ?? [];
          $jaTem = array_column($nessa, 'id');
          // Só quem se ofereceu PRA ESSA função entra no seletor, e quem já
          // está nela sai da lista — senão o admin escolhe alguém que já está.
          $opcoes = array_values(array_filter($disponiveis[$k], fn($g) => !in_array($g['id'], $jaTem, true)));
        ?>
        <div class="vaga">
          <div class="vaga-rot" style="color:<?= $f['cor'] ?>">
            <i class="bi <?= $f['icone'] ?>"></i> <?= $esc($f['rotulo']) ?>
          </div>
          <div class="chips">
            <?php foreach ($nessa as $p): ?>
            <span class="chip">
              <?= $esc($p['nome']) ?>
              <?php if ($podeEscalar): ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('Tirar <?= $esc($p['nome']) ?> da escala?')">
                <input type="hidden" name="tirar" value="<?= (int)$p['escala_id'] ?>">
                <button type="submit" title="Tirar">&times;</button>
              </form>
              <?php endif; ?>
            </span>
            <?php endforeach; ?>
            <?php if (!$nessa && !$podeEscalar): ?><span class="vazio-vaga">—</span><?php endif; ?>
          </div>

          <?php if ($podeEscalar): ?>
            <?php if ($opcoes): ?>
            <form method="POST" style="display:flex;gap:6px;align-items:center">
              <input type="hidden" name="evento_id" value="<?= (int)$lv['id'] ?>">
              <input type="hidden" name="data" value="<?= $esc($lv['data']) ?>">
              <input type="hidden" name="funcao" value="<?= $k ?>">
              <select name="usuario" required>
                <option value="">Escalar…</option>
                <?php foreach ($opcoes as $g): ?>
                <option value="<?= (int)$g['id'] ?>"><?= $esc($g['nome']) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="bt" name="escalar" value="1">Escalar</button>
            </form>
            <?php else: ?>
            <span class="vazio-vaga">
              <?= $disponiveis[$k] ? 'todos já escalados' : 'ninguém se ofereceu' ?>
            </span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
</body>
</html>
