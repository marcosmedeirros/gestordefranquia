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

        } elseif (isset($_POST['criar_grade']) && $souAdminGeral) {
            // O botão existe pra isto não depender de SSH: evento é DADO, e
            // o deploy leva só código — sem alguém criar as lives, o
            // calendário de produção fica vazio e a escala não tem em que se
            // pendurar. Chama a MESMA função do script, então os dois
            // caminhos não divergem.
            require_once __DIR__ . '/backend/semear_lives.php';
            $r = semearLives($pdo, true);
            $_SESSION['escala_flash'] = ['ok', $r['criados']
                ? count($r['criados']) . ' live(s) criada(s): ' . implode(' · ', $r['criados'])
                : 'A grade já estava toda criada — nada a fazer.'];

        } elseif (isset($_POST['add_disp']) && $podeEscalar) {
            // O admin põe gente na lista na mão. A enquete do grupo continua
            // sendo o caminho normal, mas quem combinou por fora — ou não
            // está no grupo — entrava por lugar nenhum.
            $quem = (int)($_POST['usuario'] ?? 0);
            $r = $quem
                ? escalaAdicionar($pdo, $quem, $liga, (string)($_POST['funcao'] ?? ''), $semana)
                : ['ok' => false, 'novo' => false, 'erro' => 'Escolha uma pessoa da lista.'];
            $_SESSION['escala_flash'] = $r['ok']
                ? ['ok', $r['novo'] ? 'Adicionado à lista.' : 'Essa pessoa já estava nessa função.']
                : ['erro', (string)$r['erro']];

        } elseif (isset($_POST['tirar_disp']) && $podeEscalar) {
            [$ok, $txt, $aindaEscalado] = escalaTirarDisponibilidade(
                $pdo, (int)$_POST['usuario'], $liga, (string)$_POST['funcao'], $semana
            );
            // Sair da lista não desfaz escalação: quem já foi avisado que vai
            // narrar continua narrando até alguém tirar de propósito.
            if ($ok && $aindaEscalado) {
                $txt .= " Atenção: ela segue escalada em {$aindaEscalado} live(s) desta semana.";
            }
            $_SESSION['escala_flash'] = [$ok ? 'ok' : 'erro', $txt];
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
// Todo mundo da liga fica à mão pro seletor: o admin monta a escala quando
// quiser, e não só depois que a enquete encheu.
$genteDaLiga = $podeEscalar ? escalaGenteDaLiga($pdo, $liga) : [];
$escalados   = escalaDaSemana($pdo, [$liga], $semana);

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
  <?php /* Os mesmos tokens do calendário e das outras telas — inclusive os
           derivados de --red, que é o que faz a cor escolhida pelo usuário
           valer aqui, e o --sidebar-w, de que o shell precisa pra empurrar
           o conteúdo pro lado da barra. */ ?>
  :root { --red:#fc0025;
          --red-2:color-mix(in srgb, var(--red) 85%, white);
          --red-soft:color-mix(in srgb, var(--red) 10%, transparent);
          --red-glow:color-mix(in srgb, var(--red) 18%, transparent);
          --border-red:color-mix(in srgb, var(--red) 22%, transparent);
          --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
          --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
          --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85;
          --green:#22c55e; --amber:#f59e0b; --blue:#3b82f6;
          --sidebar-w:260px;
          --font:'Montserrat',sans-serif;
          --radius:14px; --radius-sm:10px; --radius-xs:6px;
          --ease:cubic-bezier(.2,.8,.2,1); --t:200ms; }
  :root[data-theme="light"] { --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
          --border:#e3e6ee; --border-md:#d7dbe6; --text:#12141a; --text-2:#5a6070; --text-3:#7a8092; }
</style>

<?php /* Barra lateral, topbar, main e hero — o mesmo shell das outras telas. */ ?>
<?php include __DIR__ . '/includes/shell-css.php'; ?>

<style>
  *{box-sizing:border-box}
  .dash-sub{max-width:70ch;line-height:1.55}
  /* O voltar vai pro canto do hero, que já é space-between. */
  /* margin-left:auto e não só o space-between do hero: quando o texto ocupa
     a linha inteira no celular, o voltar quebra pra baixo — e sem isto ele
     cairia colado na esquerda, longe de onde o polegar espera. */
  .volta{margin-left:auto;color:var(--text-3);text-decoration:none;font-size:16px;line-height:1;
         border:1px solid var(--border-md);border-radius:9px;padding:8px 10px;flex:none}
  .volta:hover{color:var(--text);border-color:var(--red)}
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
  /* O × só aparece no hover da linha: quatro colunas de nomes com um × fixo
     em cada vira uma parede de botões de apagar. No toque não há hover, e aí
     ele fica sempre visível — senão no celular não teria como tirar ninguém. */
  .p form button{background:none;border:0;color:var(--text-3);cursor:pointer;font-size:14px;
                 line-height:1;padding:0 3px;opacity:0;transition:opacity var(--t) var(--ease)}
  .p:hover form button,.p form button:focus{opacity:1}
  .p form button:hover{color:var(--red)}
  @media (hover:none){ .p form button{opacity:.65} }

  .add-disp{display:flex;gap:6px;margin-top:8px}
  .busca{flex:1;min-width:0;font-family:var(--font);font-size:12px;border-radius:8px;padding:6px 9px;
         background:var(--panel-2);border:1px solid var(--border-md);color:var(--text)}
  .busca:focus{outline:none;border-color:var(--red)}
  .bt-add{flex:none;padding:6px 10px}

  .live{border:1px solid var(--border);border-radius:var(--radius-sm);padding:13px 15px;margin-bottom:10px;background:var(--panel-2)}
  .live-head{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:10px}
  .live-dia{font-size:13.5px;font-weight:800}
  .live-hora{font-size:12px;color:var(--red);font-weight:800}
  .live-tit{font-size:12.5px;color:var(--text-2);flex:1;min-width:0}
  .vaga{display:flex;align-items:center;gap:9px;padding:6px 0;border-top:1px solid var(--border);flex-wrap:wrap}
  .vaga-rot{width:118px;flex:none;font-size:11.5px;font-weight:800;display:flex;align-items:center;gap:6px}
  /* O fundo é a cor da função, escrita no style inline. Tentei currentColor
     aqui e o número sumiu: currentColor resolve com o color do PRÓPRIO
     elemento, e o color dele é justamente o que eu troco na linha seguinte —
     fundo e texto acabavam na mesma cor. */
  .vaga-n{border-radius:999px;min-width:16px;height:16px;display:inline-flex;
          align-items:center;justify-content:center;font-size:10px;font-weight:800;
          padding:0 4px;color:var(--panel-2)}
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
  .btn-grade{background:var(--red);border:0;color:#fff;border-radius:10px;padding:10px 18px;
             font-family:inherit;font-size:12.5px;font-weight:800;cursor:pointer;
             display:inline-flex;align-items:center;gap:8px}
  .btn-grade:hover{filter:brightness(1.1)}
  @media (max-width:620px){ .vaga-rot{width:100%} select{max-width:100%;flex:1} }
<?php include __DIR__ . '/includes/accent-color.php'; ?>
</style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
    <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
    <div class="topbar-title">FBA <em>Escala</em></div>
</header>

<main class="main">
    <div class="dash-hero">
        <div>
            <div class="dash-eyebrow">Organização</div>
            <h1 class="dash-title">Escala das Lives</h1>
            <p class="dash-sub">
              A lista de cada função se enche sozinha pelo grupo (/comentarista,
              /narrador, /operacional, /transmissao) — e você também põe e tira
              gente na mão, a qualquer hora. A escala é montada em cima das
              lives que já estão no calendário, e quem entra recebe aviso.
            </p>
        </div>
        <!-- Volta pro ADMIN e não pro painel: quem chega aqui vem do card em
             Gestão, e mandar a pessoa pro dashboard obrigaria a refazer o
             caminho inteiro pra escalar a próxima liga. -->
        <a class="volta" href="/admin.php" title="Voltar ao admin"><i class="bi bi-arrow-left"></i></a>
    </div>

    <?php /* .content é o que dá o respiro lateral nas outras telas (32px). */ ?>
    <div class="content">

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

  <?php /* O "Eu topo" saiu daqui: esta é uma tela de administração, e quem
           se oferece faz isso pelo grupo, com /comentarista e companhia. Um
           painel de admin com um formulário pra si mesmo no topo confundia
           quem vem montar a escala dos outros. */ ?>

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
              <?php if ($podeEscalar): ?>
              <form method="POST" style="display:inline"
                    data-confirmar="Tirar <?= $esc($g['nome']) ?> de <?= $esc($f['rotulo']) ?>?">
                <input type="hidden" name="usuario" value="<?= (int)$g['id'] ?>">
                <input type="hidden" name="funcao" value="<?= $k ?>">
                <button type="submit" name="tirar_disp" value="1" title="Tirar da lista">&times;</button>
              </form>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
        </div>

        <?php if ($podeEscalar): ?>
        <?php
          // Só quem ainda não está NESTA função aparece pra adicionar.
          $jaNaFuncao = array_column($disponiveis[$k], 'id');
          $podeAddAqui = array_values(array_filter(
              $genteDaLiga, fn($g) => !in_array($g['id'], $jaNaFuncao, true)
          ));
        ?>
        <?php if ($podeAddAqui): ?>
        <?php /* Um <input list> e não um <select>: com trinta e poucos GMs a
                 lista rolável é pior que digitar três letras do nome. O
                 datalist filtra por nome enquanto se digita, é nativo, e
                 funciona no teclado do celular sem JS nenhum. */ ?>
        <form method="POST" class="add-disp">
          <input type="hidden" name="funcao" value="<?= $k ?>">
          <input class="busca" list="gente-liga" name="busca_<?= $k ?>"
                 placeholder="Buscar pelo nome…" autocomplete="off"
                 data-alvo="add-<?= $k ?>">
          <input type="hidden" name="usuario" id="add-<?= $k ?>">
          <button class="bt bt-add" name="add_disp" value="1" title="Adicionar à lista">
            <i class="bi bi-plus-lg"></i>
          </button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($podeEscalar): ?>
    <?php /* Uma lista só, compartilhada pelas quatro funções: o navegador
             guarda um datalist por id, e repetir os 30 nomes quatro vezes
             só engordaria o HTML. */ ?>
    <datalist id="gente-liga">
      <?php foreach ($genteDaLiga as $g): ?>
      <option data-id="<?= (int)$g['id'] ?>" value="<?= $esc($g['nome']) ?>"></option>
      <?php endforeach; ?>
    </datalist>
    <?php endif; ?>
  </div>

  <!-- ── A escala ────────────────────────────────────────────────────── -->
  <div class="cx">
    <h2>A escala da semana</h2>
    <?php if (!$lives): ?>
      <div class="vazio">
        Nenhuma live da <?= $liga ?> no calendário desta semana.<br>
        <a href="/calendario.php" style="color:var(--red);text-decoration:none">Marcar no calendário</a>
        — a escala é montada em cima do que está lá.
        <?php if ($souAdminGeral): ?>
          <!-- O botão aparece aqui e não num painel escondido: é exatamente
               no momento em que a escala está vazia que ele resolve algo. -->
          <form method="post" style="margin-top:14px">
            <button class="btn-grade" name="criar_grade" value="1"
                    data-confirmar="Criar a grade fixa de lives (NEXT, ELITE, RISE e ROOKIE) no calendário? Já existentes não são duplicadas.">
              <i class="bi bi-calendar-plus"></i> Criar a grade fixa de lives
            </button>
          </form>
        <?php endif; ?>
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
          // Quem já está na função sai das duas listas — senão o admin
          // escolheria alguém que já está lá.
          $livre  = fn($g) => !in_array($g['id'], $jaTem, true);
          // Quem se ofereceu PRA ESSA função vem primeiro: é a informação
          // que a enquete produziu, e enterrá-la no meio da liga inteira
          // faria a enquete não servir pra nada.
          $ofereceu = array_values(array_filter($disponiveis[$k], $livre));
          $idsOfer  = array_column($ofereceu, 'id');
          $outros   = array_values(array_filter(
              $genteDaLiga,
              fn($g) => $livre($g) && !in_array($g['id'], $idsOfer, true)
          ));
          $opcoes = array_merge($ofereceu, $outros);
        ?>
        <div class="vaga">
          <div class="vaga-rot" style="color:<?= $f['cor'] ?>">
            <i class="bi <?= $f['icone'] ?>"></i> <?= $esc($f['rotulo']) ?>
            <?php /* A contagem só aparece do segundo em diante. Ela existe pra
                     dizer que a função aceita mais de uma pessoa — "Comentarista"
                     sozinho, com um nome do lado, se lê como vaga única, e foi
                     assim que a tela passou a impressão de ter limite. */ ?>
            <?php if (count($nessa) > 1): ?>
            <span class="vaga-n" style="background:<?= $f['cor'] ?>"><?= count($nessa) ?></span>
            <?php endif; ?>
          </div>
          <div class="chips">
            <?php foreach ($nessa as $p): ?>
            <span class="chip">
              <?= $esc($p['nome']) ?>
              <?php if ($podeEscalar): ?>
              <form method="POST" style="display:inline"
                    data-confirmar="Tirar <?= $esc($p['nome']) ?> da escala?">
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
                <?php /* "Escalar mais…" quando já tem gente: é a frase que
                         responde, sem precisar tentar, a pergunta "posso pôr
                         dois comentaristas?". Não há limite nenhum de pessoas
                         por função. */ ?>
                <option value=""><?= $nessa ? 'Escalar mais…' : 'Escalar…' ?></option>
                <?php if ($ofereceu): ?>
                <optgroup label="Se ofereceu">
                  <?php foreach ($ofereceu as $g): ?>
                  <option value="<?= (int)$g['id'] ?>"><?= $esc($g['nome']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
                <?php if ($outros): ?>
                <optgroup label="<?= $ofereceu ? 'Outros da liga' : 'Ninguém se ofereceu — toda a liga' ?>">
                  <?php foreach ($outros as $g): ?>
                  <option value="<?= (int)$g['id'] ?>"><?= $esc($g['nome']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
              </select>
              <button class="bt" name="escalar" value="1">Escalar</button>
            </form>
            <?php else: ?>
            <span class="vazio-vaga">todos já escalados</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>

    </div><!-- /.content -->
</main>

<?php /* O hambúrguer do mobile. Sem isto a barra lateral existe mas não abre
         no celular — que é justamente onde ela fica escondida. */ ?>
<script src="/js/sidebar.js"></script>
<script src="/js/tema.js"></script>

<?php if ($podeEscalar): ?>
<script>
/**
 * O datalist devolve o NOME digitado, e o servidor precisa do id. Isto faz a
 * tradução na hora do envio.
 *
 * Casa por nome exato de propósito. Digitar meio nome e mandar não escala a
 * primeira pessoa que começa igual — o hidden fica vazio e o servidor
 * responde "Escolha uma pessoa da lista". Escalar a pessoa errada por causa
 * de um prefixo seria pior que um aviso.
 */
(function () {
  var porNome = {};
  document.querySelectorAll('#gente-liga option').forEach(function (o) {
    // O primeiro vence: se dois GMs tiverem o mesmo nome, o servidor ainda
    // valida o id, e o admin vê quem entrou no aviso.
    if (!(o.value in porNome)) porNome[o.value] = o.dataset.id;
  });

  document.querySelectorAll('.add-disp').forEach(function (form) {
    var busca = form.querySelector('.busca');
    var alvo  = document.getElementById(busca.dataset.alvo);

    var casar = function () { alvo.value = porNome[busca.value.trim()] || ''; };
    busca.addEventListener('input', casar);
    busca.addEventListener('change', casar);

    form.addEventListener('submit', function (ev) {
      casar();
      if (alvo.value) return;
      ev.preventDefault();
      window.avisarSite(
        busca.value.trim()
          ? 'Não achei "' + busca.value.trim() + '" na liga. Escolha um nome da lista.'
          : 'Digite o nome de quem entra nessa função.',
        'aviso'
      );
      busca.focus();
    });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
