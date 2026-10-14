<?php
/**
 * FBA GEO — o GeoGuessr da liga.
 *
 * Aparece a foto de um lugar do mundo e você diz onde é: o país, na múltipla
 * escolha, ou o ponto no mapa. Dois modos, dois rankings (@see games/core/geo.php).
 *
 * ── A CASCA É DO SITE, O RESTO É DO JOGO ─────────────────────────────
 *
 * Pedido do Marcos (14/10/2026): "a única coisa que deve ter padrão é o menu
 * lateral com padrão do site, mas o resto pode ficar à vontade pra
 * estilizar". Então esta página entra pelo shell do app — sidebar, topbar e
 * a cor que a pessoa escolheu — e daí pra dentro tem identidade própria:
 * foto em tela cheia, chips grandes, o mapa com a linha do palpite até o
 * lugar certo. Um jogo de adivinhar paisagem não pode parecer um relatório.
 *
 * ── SÓ POR LINK, POR ENQUANTO ────────────────────────────────────────
 *
 * Não existe card em games.php: quem tem o endereço entra, o resto da liga
 * não o descobre passeando pela lista. Pra publicar, é só acrescentar o card.
 *
 * ── A PARTIDA É DO SERVIDOR ──────────────────────────────────────────
 *
 * Cada rodada nasce no banco com a resposta guardada, e o palpite é conferido
 * contra ela. A tela não sabe a resposta antes de responder.
 */

/* O conexao.php é quem faz o login do games valer aqui e quem garante a linha
   em games_usuarios — sem ela não há onde creditar moeda. O resto é o que o
   menu lateral do site pede. */
require_once __DIR__ . '/../core/conexao.php';
require_once __DIR__ . '/../../backend/observador.php';
require_once __DIR__ . '/../core/geo.php';

$user = getUserSession();
$user_id = (int)$user['id'];
/* O cartão do time no menu. Vem por timeDaTela pra respeitar o modo
   observador, igual às outras telas. */
$team = timeDaTela($pdo, $user_id);

geoTabelas($pdo);
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$msg = $_GET['m'] ?? null;
$erro = null;
$resolvida = null;          // a rodada que acabou de ser respondida

/* ── AS AÇÕES ────────────────────────────────────────────────────────
   Tudo é POST e termina em redirect, menos o palpite — ele precisa mostrar
   na mesma tela o que era o lugar, e um redirect jogaria isso fora. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'comecar') {
        geoAbrirPartida($pdo, $user_id, (string)($_POST['modo'] ?? 'pais'));
        header('Location: /games/games/geo.php');
        exit;
    }

    if ($acao === 'desistir') {
        $pdo->prepare("UPDATE geo_partidas SET status = 'fim', fim_em = NOW()
                        WHERE user_id = ? AND status = 'jogando'")->execute([$user_id]);
        header('Location: /games/games/geo.php?m=' . urlencode('Partida encerrada.'));
        exit;
    }

    if ($acao === 'palpite') {
        $partida = geoPartidaAberta($pdo, $user_id);
        $rodada  = $partida ? geoRodadaAtual($pdo, (int)$partida['id']) : null;
        if (!$partida || !$rodada) {
            $erro = 'Essa rodada já foi respondida.';
        } else {
            $modoDaVez = (string)$partida['modo'];
            $resolvida = geoResponder($pdo, $partida, $rodada, [
                'pais' => (string)($_POST['pais'] ?? ''),
                'lat'  => (float)($_POST['lat'] ?? 0),
                'lon'  => (float)($_POST['lon'] ?? 0),
            ]);
            $resolvida['modo'] = $modoDaVez;
            $resolvida['palpite_lat'] = (float)($_POST['lat'] ?? 0);
            $resolvida['palpite_lon'] = (float)($_POST['lon'] ?? 0);
        }
    }
}

$partida   = geoPartidaAberta($pdo, $user_id);
$rodada    = $partida ? geoRodadaAtual($pdo, (int)$partida['id']) : null;
$recorde   = geoMeuRecorde($pdo, $user_id);
$restantes = geoPremiadasRestantes($pdo, $user_id);
$nLugares  = count(geoLugares());
$nPaises   = count(array_unique(array_column(geoLugares(), 'pais')));
$moedas    = (int)($pdo->query('SELECT pontos FROM games_usuarios WHERE id = ' . $user_id)->fetchColumn() ?: 0);

/* O mapa só é carregado onde ele é usado: na rodada do modo mapa e no
   resultado dela, que desenha a linha do palpite até o lugar. */
$precisaMapa = ($partida && $rodada && $partida['modo'] === 'mapa')
            || ($resolvida && ($resolvida['modo'] ?? '') === 'mapa');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>FBA Geo</title>
<link rel="manifest" href="/manifest.json?v=3">
<meta name="theme-color" content="#fc0025">
<link rel="icon" type="image/png" href="/img/fba-logo.png?v=3">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Inter:wght@400;600;700;800;900&family=Barlow+Condensed:wght@600;700&display=swap" rel="stylesheet">
<style>
/* Os tokens do app: é o que faz o menu lateral e a topbar aparecerem do
   jeito certo, e o que traz o tema claro de graça. */
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
<?php include __DIR__ . '/../../includes/shell-css.php'; ?>
<?php if ($precisaMapa): ?>
  <?php /* Leaflet + tiles do OpenStreetMap: os dois de graça, e a atribuição
       fica no canto do mapa, como a licença pede. */ ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<?php endif; ?>
<style>
/* ══════════════════════════════════════════════════════════════════
   O VISUAL DO JOGO. Daqui pra baixo nada é do app: é um jogo de olhar
   paisagem, então a foto manda e o resto se cala em volta dela.
   ══════════════════════════════════════════════════════════════════ */
.content{font-family:Inter,system-ui,sans-serif}
#geo{max-width:1080px;margin:0 auto;padding:0 0 48px;
  --c:var(--blue)}

/* ── A FOTO ──────────────────────────────────────────────────────────
   Ela é o enunciado da pergunta, então ocupa o quanto puder: largura
   inteira, 16/10 no desktop e mais alta no celular (foto deitada em tela
   em pé vira uma tarja). O gradiente existe pra o texto que fica por cima
   não depender da sorte do céu da foto. */
.geo-palco{position:relative;border-radius:22px;overflow:hidden;background:var(--panel-2);
  aspect-ratio:16/10;box-shadow:0 24px 60px rgba(0,0,0,.45)}
.geo-palco img{width:100%;height:100%;object-fit:cover;display:block;
  animation:geoEntra .5s var(--ease)}
@keyframes geoEntra{from{opacity:0;transform:scale(1.04)}}
.geo-palco .carregando{position:absolute;inset:0;display:grid;place-items:center;
  color:var(--text-3);font-size:13px;letter-spacing:.4px}
.geo-palco::after{content:"";position:absolute;inset:auto 0 0;height:45%;pointer-events:none;
  background:linear-gradient(to top,rgba(0,0,0,.82),transparent)}
.geo-hud{position:absolute;left:0;right:0;top:0;display:flex;align-items:flex-start;gap:12px;
  padding:16px 18px;z-index:2}
.geo-hud .caixa{display:flex;flex-direction:column;gap:1px;padding:9px 14px;border-radius:14px;
  background:rgba(8,8,12,.62);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.1)}
.geo-hud .caixa b{font-family:'Barlow Condensed',Inter,sans-serif;font-size:27px;line-height:1;
  letter-spacing:.5px;color:#fff}
.geo-hud .caixa span{font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:#c9c9d2}
.geo-hud form{margin-left:auto}
.geo-sair{padding:9px 13px;border-radius:12px;border:1px solid rgba(255,255,255,.14);
  background:rgba(8,8,12,.62);backdrop-filter:blur(10px);color:#e7e7ee;font:inherit;font-size:12.5px;
  font-weight:700;cursor:pointer}
.geo-sair:hover{border-color:var(--red);color:#fff}
.geo-pergunta{position:absolute;left:18px;bottom:16px;z-index:2;font-family:'Barlow Condensed',Inter,sans-serif;
  font-size:30px;font-weight:700;letter-spacing:.6px;color:#fff;text-shadow:0 2px 20px rgba(0,0,0,.6)}
.geo-pergunta small{display:block;font-family:Inter,sans-serif;font-size:12.5px;font-weight:500;
  letter-spacing:0;color:#cfcfda;text-shadow:none;margin-top:2px}

/* ── AS OPÇÕES DE PAÍS ───────────────────────────────────────────────
   Alvo grande, número à esquerda e o nome inteiro: no celular isto é
   apertado com o polegar, e nome cortado é palpite no escuro. */
.geo-ops{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,250px),1fr));gap:10px;
  margin-top:16px}
.geo-ops form{display:contents}
.geo-ops button{display:flex;align-items:center;gap:12px;width:100%;padding:16px 18px;
  border-radius:16px;border:1px solid var(--border);background:var(--panel);color:var(--text);
  font:inherit;font-size:15.5px;font-weight:700;cursor:pointer;text-align:left;
  transition:transform var(--t) var(--ease),border-color var(--t) var(--ease),background var(--t) var(--ease)}
.geo-ops button i{display:grid;place-items:center;width:26px;height:26px;border-radius:9px;flex:none;
  background:var(--panel-3);color:var(--text-3);font-size:12px;font-style:normal;font-weight:800}
.geo-ops button:hover{transform:translateY(-2px);border-color:var(--c);background:var(--panel-2)}
.geo-ops button:hover i{background:color-mix(in srgb,var(--c) 22%,transparent);color:var(--c)}
.geo-ops button:disabled{opacity:.45;transform:none;cursor:default}

/* ── O MAPA DO PALPITE ───────────────────────────────────────────────*/
.geo-mapa{height:min(52vh,440px);border-radius:20px;margin-top:16px;overflow:hidden;
  border:1px solid var(--border)}
.geo-mapa-l{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:12px}
.geo-mapa-l p{flex:1;min-width:220px;margin:0;color:var(--text-2);font-size:13px}
.geo-confirmar{padding:15px 28px;border-radius:14px;border:none;cursor:pointer;font:inherit;
  font-size:15.5px;font-weight:800;background:var(--c);color:#06202e;
  transition:filter var(--t) var(--ease),opacity var(--t) var(--ease)}
.geo-confirmar:disabled{opacity:.35;cursor:not-allowed}
.geo-confirmar:not(:disabled):hover{filter:brightness(1.08)}

/* ── O RESULTADO DA RODADA ───────────────────────────────────────────
   O veredito é a primeira coisa, grande; a distância vem como número; e o
   mapa embaixo mostra a linha do palpite até onde era de verdade — é o
   momento em que se aprende alguma coisa jogando. */
.geo-result{position:relative;overflow:hidden;border-radius:22px;padding:22px;margin-bottom:18px;
  background:var(--panel);border:1px solid var(--border);animation:geoSobe .35s var(--ease)}
@keyframes geoSobe{from{opacity:0;transform:translateY(10px)}}
.geo-result::before{content:"";position:absolute;inset:-60% 35% auto -10%;height:230px;pointer-events:none;
  background:radial-gradient(60% 100% at 30% 0,color-mix(in srgb,var(--c) 28%,transparent),transparent 70%)}
.geo-result > *{position:relative}
.geo-veredito{display:flex;align-items:center;gap:12px;font-family:'Barlow Condensed',Inter,sans-serif;
  font-size:34px;font-weight:700;letter-spacing:.5px;line-height:1;color:var(--c)}
.geo-veredito i{font-size:28px}
.geo-era{font-size:17px;margin-top:8px}
.geo-era b{font-weight:800}
.geo-numeros{display:flex;gap:26px;flex-wrap:wrap;margin-top:14px}
.geo-numeros div b{display:block;font-family:'Barlow Condensed',Inter,sans-serif;font-size:30px;
  line-height:1;color:var(--text)}
.geo-numeros div span{font-size:10.5px;text-transform:uppercase;letter-spacing:.9px;color:var(--text-3)}
.geo-credito{margin-top:14px;font-size:11.5px;color:var(--text-3)}
.geo-credito a{color:var(--text-2)}
.geo-fim{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:16px}
.geo-fim .geo-voltar{display:inline-flex;align-items:center;gap:8px;padding:15px 22px;border-radius:14px;
  border:1px solid var(--border-md);background:var(--panel-2);color:var(--text-2);font-size:14.5px;
  font-weight:700}
.geo-fim .geo-voltar:hover{border-color:var(--c);color:var(--text)}
.geo-mapa-res{height:260px;border-radius:16px;margin-top:16px;overflow:hidden;border:1px solid var(--border)}

/* ── A ENTRADA: OS DOIS MODOS ────────────────────────────────────────*/
.geo-capa{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:16px}
.geo-modo{position:relative;overflow:hidden;display:flex;flex-direction:column;gap:10px;
  padding:24px;border-radius:22px;background:var(--panel);border:1px solid var(--border);
  text-align:left;color:inherit;font:inherit;cursor:pointer;
  transition:transform var(--t) var(--ease),border-color var(--t) var(--ease)}
.geo-modo::before{content:"";position:absolute;inset:-55% 25% auto -10%;height:240px;pointer-events:none;
  background:radial-gradient(60% 100% at 30% 0,color-mix(in srgb,var(--c) 30%,transparent),transparent 70%)}
.geo-modo > *{position:relative}
.geo-modo:hover{transform:translateY(-3px);border-color:color-mix(in srgb,var(--c) 45%,transparent)}
.geo-modo .ico{display:grid;place-items:center;width:46px;height:46px;border-radius:15px;font-size:22px;
  background:color-mix(in srgb,var(--c) 16%,transparent);color:var(--c)}
.geo-modo h3{margin:0;font-family:'Barlow Condensed',Inter,sans-serif;font-size:27px;font-weight:700;
  letter-spacing:.4px}
.geo-modo p{margin:0;color:var(--text-2);font-size:13.5px;line-height:1.5}
.geo-modo .rec{margin-top:6px;padding-top:12px;border-top:1px solid var(--border);
  font-size:12px;color:var(--text-3)}
.geo-modo .rec b{font-family:'Barlow Condensed',Inter,sans-serif;font-size:19px;color:var(--c);
  margin-right:3px}
.geo-nums{display:flex;flex-wrap:wrap;gap:6px 20px;font-size:12.5px;color:var(--text-3);margin:2px 0 18px}
.geo-nums b{color:var(--text-2)}

/* ── RANKING ─────────────────────────────────────────────────────────*/
.geo-ranks{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:18px;
  margin-top:26px}
.geo-rank h4{margin:0 0 10px;font-family:'Barlow Condensed',Inter,sans-serif;font-size:21px;
  font-weight:700;letter-spacing:.3px}
.geo-linha{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:13px;font-size:14px}
.geo-linha:nth-child(odd){background:var(--panel)}
.geo-linha .pos{font-family:'Barlow Condensed',Inter,sans-serif;font-size:19px;color:var(--text-3);
  min-width:24px;text-align:center}
.geo-linha .pos.ouro{color:#f5c518}.geo-linha .pos.prata{color:#c5ccd6}.geo-linha .pos.bronze{color:#cd7f32}
.geo-linha .gm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}
.geo-linha .v{font-weight:800;color:var(--c);font-variant-numeric:tabular-nums}
.geo-linha.eu{background:color-mix(in srgb,var(--c) 14%,transparent)}
.geo-vazio{color:var(--text-3);font-size:13.5px}
.geo-aviso{padding:13px 17px;border-radius:14px;margin-bottom:16px;font-size:13.5px}
.geo-aviso.ok{background:color-mix(in srgb,var(--green) 13%,transparent);color:var(--green)}
.geo-aviso.err{background:color-mix(in srgb,var(--red) 13%,transparent);color:var(--red-2)}

@media (max-width:620px){
  .geo-palco{aspect-ratio:4/3;border-radius:18px}
  .geo-pergunta{font-size:24px;left:14px;bottom:12px}
  .geo-hud{padding:12px}
  .geo-veredito{font-size:28px}
  .geo-modo{padding:20px}
}
<?php include __DIR__ . '/../../includes/accent-color.php'; ?>
</style>
</head>
<body>

<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
  <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
  <div class="topbar-title">FBA <em>Geo</em></div>
</header>

<main class="main">
  <div class="dash-hero">
    <div>
      <div class="dash-eyebrow">Jogos da liga</div>
      <h1 class="dash-title">FBA Geo</h1>
      <p class="dash-sub">Olhe a foto e diga onde é no mundo.</p>
    </div>
  </div>

<div class="content">
<div id="geo">
<?php if ($msg): ?><div class="geo-aviso ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="geo-aviso err"><?= e($erro) ?></div><?php endif; ?>

<?php /* ── O RESULTADO DA RODADA QUE ACABOU ─────────────────────────── */ ?>
<?php if ($resolvida):
  $lug = $resolvida['lugar'];
  $ehMapa = ($resolvida['modo'] ?? '') === 'mapa';
  /* ── O VEREDITO ─────────────────────────────────────────────────────
     No mapa não existe certo e errado, existe por quanto — e chamar de
     "quase lá" um palpite que caiu a 10.000 km é piada involuntária. A
     frase e a cor acompanham a distância, que é a única coisa que o modo
     mede de verdade. */
  if (!$ehMapa) {
      [$frase, $ico, $cor] = $resolvida['acertou']
          ? ['Acertou!', 'check-circle-fill', 'var(--green)']
          : ['Errou', 'x-circle-fill', 'var(--red)'];
  } else {
      $km = (float)$resolvida['km'];
      [$frase, $ico, $cor] = $km <= 50   ? ['Na mosca!',  'bullseye',      'var(--green)']
                           : ($km <= 500  ? ['Perto',      'geo-fill',      'var(--green)']
                           : ($km <= 2000 ? ['Quase lá',   'rulers',        'var(--amber)']
                           : ($km <= 6000 ? ['Longe',      'rulers',        'var(--amber)']
                                          : ['Outro mundo','globe-americas','var(--red)'])));
  } ?>
  <div class="geo-result" style="--c:<?= $cor ?>">
    <div class="geo-veredito">
      <i class="bi bi-<?= $ico ?>"></i> <?= $frase ?>
    </div>
    <div class="geo-era">Era <b><?= e($lug['nome']) ?></b>, <?= e($lug['pais']) ?>.</div>

    <?php if ($ehMapa): ?>
      <div class="geo-numeros">
        <div><b><?= number_format((float)$resolvida['km'], 0, ',', '.') ?> km</b><span>de distância</span></div>
        <div><b><?= number_format((int)$resolvida['pontos'], 0, ',', '.') ?></b><span>pontos na rodada</span></div>
        <div><b><?= number_format((int)$resolvida['pontos_total'], 0, ',', '.') ?></b><span>no total</span></div>
      </div>
      <?php /* O MAPA DA CORREÇÃO: o palpite, o lugar e a linha entre os dois.
           É aqui que a pessoa descobre que confundiu Porto com Lisboa — e é
           por isso que ele vale mais que a frase "errou por 300 km". */ ?>
      <div class="geo-mapa-res" id="mapaRes"
           data-plat="<?= (float)$resolvida['palpite_lat'] ?>" data-plon="<?= (float)$resolvida['palpite_lon'] ?>"
           data-clat="<?= (float)$lug['lat'] ?>" data-clon="<?= (float)$lug['lon'] ?>"
           data-nome="<?= e($lug['nome']) ?>"></div>
    <?php endif; ?>

    <?php /* O CRÉDITO DA FOTO. As licenças CC exigem autor e licença visíveis,
         então ele aparece aqui e não escondido num rodapé. Quando o Commons
         não devolveu o autor, a linha some em vez de inventar um nome — o
         link pro arquivo original fica, e é lá que o crédito completo está. */
    $autor = trim((string)($lug['autor'] ?? ''));
    $lic   = trim((string)($lug['licenca'] ?? '')); ?>
    <div class="geo-credito">
      <?= $autor !== '' ? 'foto de ' . e($autor) . ' · ' : '' ?><?= $lic !== '' ? e($lic) . ' · ' : '' ?>
      <a target="_blank" rel="noopener"
         href="https://commons.wikimedia.org/wiki/File:<?= rawurlencode((string)$lug['img']) ?>">Wikimedia Commons</a>
    </div>

    <?php if (!empty($resolvida['fim'])): ?>
      <div class="geo-numeros" style="margin-top:18px">
        <?php if ($ehMapa): ?>
          <div><b><?= number_format((int)$resolvida['pontos_total'], 0, ',', '.') ?></b><span>pontos na partida</span></div>
        <?php else: ?>
          <div><b><?= (int)$resolvida['acertos_total'] ?></b><span>acertos seguidos</span></div>
        <?php endif; ?>
        <div><b><?= !empty($resolvida['moedas']) ? '+' . (int)$resolvida['moedas'] : '0' ?></b><span>moedas</span></div>
      </div>
      <?php if (empty($resolvida['moedas'])): ?>
        <p class="geo-credito">Sem moedas desta vez: o teto de <?= GEO_PREMIADAS_DIA ?> partidas
           premiadas do dia já passou. O ranking continua valendo.</p>
      <?php endif; ?>
      <div class="geo-fim">
        <form method="POST" style="margin:0">
          <input type="hidden" name="acao" value="comecar">
          <input type="hidden" name="modo" value="<?= $ehMapa ? 'mapa' : 'pais' ?>">
          <button class="geo-confirmar" type="submit"><i class="bi bi-arrow-repeat"></i> Jogar de novo</button>
        </form>
        <a class="geo-voltar" href="/games/games/geo.php"><i class="bi bi-house"></i> Tela inicial</a>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($partida && $rodada):
  /* ── JOGANDO ──────────────────────────────────────────────────────── */
  $lug = $rodada['dados'];
  $ehMapa = $partida['modo'] === 'mapa';
  $opcoes = $ehMapa ? [] : (array)json_decode((string)$rodada['opcoes'], true);
  $letras = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H']; ?>
  <div style="--c:<?= $ehMapa ? 'var(--blue)' : 'var(--green)' ?>">
    <div class="geo-palco">
      <div class="carregando">carregando a foto…</div>
      <img src="<?= e(geoFoto((string)$lug['img'])) ?>" alt="Onde é isso?"
           onload="this.previousElementSibling && this.previousElementSibling.remove()">
      <div class="geo-hud">
        <div class="caixa">
          <b><?= $ehMapa ? (int)$rodada['n'] . '/' . GEO_RODADAS_MAPA : (int)$partida['acertos'] ?></b>
          <span><?= $ehMapa ? 'rodada' : 'seguidos' ?></span>
        </div>
        <?php if ($ehMapa): ?>
          <div class="caixa">
            <b><?= number_format((int)$partida['pontos'], 0, ',', '.') ?></b><span>pontos</span>
          </div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="acao" value="desistir">
          <button class="geo-sair" type="submit"><i class="bi bi-x-lg"></i> Encerrar</button>
        </form>
      </div>
      <div class="geo-pergunta">
        <?= $ehMapa ? 'Onde fica?' : 'Que país é esse?' ?>
        <small><?= $ehMapa
            ? 'Clique no mapa — quanto mais perto, mais pontos.'
            : 'Uma chance. Errou, a série acaba.' ?></small>
      </div>
    </div>

    <?php if ($ehMapa): ?>
      <div class="geo-mapa" id="mapa"></div>
      <form method="POST" class="geo-mapa-l" id="formMapa">
        <input type="hidden" name="acao" value="palpite">
        <input type="hidden" name="lat" id="lat"><input type="hidden" name="lon" id="lon">
        <p id="dicaMapa">Clique no mapa onde você acha que é. Dá pra mudar quantas vezes quiser
          antes de confirmar.</p>
        <button class="geo-confirmar" type="submit" id="btnMapa" disabled>
          <i class="bi bi-geo-alt-fill"></i> Confirmar palpite</button>
      </form>
    <?php else: ?>
      <div class="geo-ops">
        <?php foreach ($opcoes as $k => $op): ?>
          <form method="POST">
            <input type="hidden" name="acao" value="palpite">
            <input type="hidden" name="pais" value="<?= e($op) ?>">
            <button type="submit"><i><?= $letras[$k] ?? '?' ?></i> <?= e($op) ?></button>
          </form>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

<?php else:
  /* ── A ENTRADA ────────────────────────────────────────────────────── */ ?>
  <div class="geo-nums">
    <span><b><?= number_format($nLugares, 0, ',', '.') ?></b> lugares</span>
    <span><b><?= $nPaises ?></b> países</span>
    <span><b><?= number_format($moedas, 0, ',', '.') ?></b> moedas na conta</span>
    <span>hoje ainda valem moedas <b><?= $restantes ?></b> partida<?= $restantes === 1 ? '' : 's' ?></span>
  </div>

  <div class="geo-capa">
    <form method="POST" style="margin:0;display:contents">
      <input type="hidden" name="acao" value="comecar">
      <input type="hidden" name="modo" value="pais">
      <button class="geo-modo" type="submit" style="--c:var(--green)">
        <span class="ico"><i class="bi bi-flag-fill"></i></span>
        <h3>Que país é esse?</h3>
        <p>Seis opções por foto. Acertou, vem outra; errou, acabou. O ranking é a maior
           sequência — e cada acerto paga <?= GEO_MOEDA_ACERTO ?> moedas.</p>
        <span class="rec"><b><?= (int)$recorde['pais']['a'] ?></b> seu recorde
          · <?= (int)$recorde['pais']['n'] ?> partida<?= (int)$recorde['pais']['n'] === 1 ? '' : 's' ?></span>
      </button>
    </form>
    <form method="POST" style="margin:0;display:contents">
      <input type="hidden" name="acao" value="comecar">
      <input type="hidden" name="modo" value="mapa">
      <button class="geo-modo" type="submit" style="--c:var(--blue)">
        <span class="ico"><i class="bi bi-geo-alt-fill"></i></span>
        <h3>Onde fica no mapa?</h3>
        <p><?= GEO_RODADAS_MAPA ?> rodadas, clicando no mapa do mundo. Quanto mais perto, mais
           pontos: 5.000 no alvo. Cada 1.000 pontos vale <?= GEO_MOEDA_MAPA_MIL ?> moeda.</p>
        <span class="rec"><b><?= number_format((int)$recorde['mapa']['p'], 0, ',', '.') ?></b> seu recorde
          · <?= (int)$recorde['mapa']['n'] ?> partida<?= (int)$recorde['mapa']['n'] === 1 ? '' : 's' ?></span>
      </button>
    </form>
  </div>

  <div class="geo-ranks">
    <?php foreach ([['pais', 'Maiores sequências', 'seguidos', 'var(--green)'],
                    ['mapa', 'Mais pontos no mapa', 'pts', 'var(--blue)']] as [$modo, $titulo, $un, $cor]):
      $r = geoRanking($pdo, $modo, 8); ?>
      <div class="geo-rank" style="--c:<?= $cor ?>">
        <h4><?= e($titulo) ?></h4>
        <?php if (!$r): ?>
          <p class="geo-vazio">Ninguém jogou ainda. Seja o primeiro.</p>
        <?php else: foreach ($r as $i => $x):
          $medalha = [0 => 'ouro', 1 => 'prata', 2 => 'bronze'][$i] ?? ''; ?>
          <div class="geo-linha<?= (int)$x['user_id'] === $user_id ? ' eu' : '' ?>">
            <span class="pos <?= $medalha ?>"><?= $i + 1 ?></span>
            <span class="gm"><?= e($x['gm'] ?: 'GM') ?></span>
            <span class="v"><?= number_format((int)$x['melhor'], 0, ',', '.') ?> <?= $un ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <p class="geo-credito" style="margin-top:22px">
    As fotos vêm do <a href="https://commons.wikimedia.org" target="_blank" rel="noopener">Wikimedia
    Commons</a> e os lugares do Wikidata — tudo de licença livre, com o autor creditado em cada
    rodada. O mapa é OpenStreetMap.</p>
<?php endif; ?>
</div>
</div>
</main>

<?php /* O botão de menu do celular e o de tema, os mesmos de todas as telas. */ ?>
<script src="/js/popups.js"></script>
<script src="/js/sidebar.js"></script>
<script src="/js/tema.js"></script>

<?php if ($partida && $rodada && $partida['modo'] === 'mapa'): ?>
<script>
/* O MAPA DO PALPITE. Mundo inteiro visível, um alfinete por clique, e o
   botão só acende quando há onde cravar — confirmar sem palpite mandaria o
   ponto (0,0), no meio do Atlântico. */
(function(){
  var mapa = L.map('mapa', {worldCopyJump: true, minZoom: 1}).setView([18, 0], 2);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 12, attribution: '© OpenStreetMap'
  }).addTo(mapa);

  var pino = null;
  mapa.on('click', function(ev){
    var p = ev.latlng.wrap();
    if (pino) pino.setLatLng(p); else pino = L.marker(p).addTo(mapa);
    document.getElementById('lat').value = p.lat.toFixed(5);
    document.getElementById('lon').value = p.lng.toFixed(5);
    document.getElementById('btnMapa').disabled = false;
    document.getElementById('dicaMapa').textContent =
      'Palpite cravado. Dá pra mudar clicando de novo, ou confirme aí do lado.';
  });

  /* Dois cliques no confirmar mandariam duas respostas pra mesma rodada; a
     segunda o servidor recusa, mas o botão travado evita o susto. */
  document.getElementById('formMapa').addEventListener('submit', function(){
    document.getElementById('btnMapa').disabled = true;
  });
})();
</script>
<?php endif; ?>

<?php if ($resolvida && ($resolvida['modo'] ?? '') === 'mapa'): ?>
<script>
/* O MAPA DA CORREÇÃO: alfinete no palpite, bandeira no lugar certo e a
   linha entre os dois, com o enquadramento ajustado pra os dois caberem. */
(function(){
  var el = document.getElementById('mapaRes');
  if (!el) return;
  var p = [parseFloat(el.dataset.plat), parseFloat(el.dataset.plon)];
  var c = [parseFloat(el.dataset.clat), parseFloat(el.dataset.clon)];
  var mapa = L.map('mapaRes', {zoomControl: false, attributionControl: false, dragging: false,
                               scrollWheelZoom: false, doubleClickZoom: false});
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 10}).addTo(mapa);
  L.marker(p).addTo(mapa).bindTooltip('seu palpite', {permanent: false});
  L.circleMarker(c, {radius: 9, color: '#22c55e', weight: 3, fillOpacity: .9})
    .addTo(mapa).bindTooltip(el.dataset.nome, {permanent: true, direction: 'top'});
  L.polyline([p, c], {color: '#f59e0b', weight: 2, dashArray: '6 6'}).addTo(mapa);
  mapa.fitBounds(L.latLngBounds([p, c]).pad(0.35));
})();
</script>
<?php endif; ?>
</body>
</html>
