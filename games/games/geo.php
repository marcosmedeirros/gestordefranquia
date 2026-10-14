<?php
/**
 * FBA GEO — o GeoGuessr da liga.
 *
 * Aparece a foto de um lugar do mundo e você diz onde é: o país, na múltipla
 * escolha, ou o ponto no mapa. Dois modos, dois rankings (@see games/core/geo.php).
 *
 * ── SÓ POR LINK, POR ENQUANTO ────────────────────────────────────────
 *
 * Pedido do Marcos (14/10/2026): "o geoguess só por link por enquanto".
 * Então não existe card em games.php — quem tem o endereço entra, o resto da
 * liga não o descobre passeando pela lista. Pra publicar depois, é só
 * acrescentar o card lá; nada aqui depende disso.
 *
 * ── A PARTIDA É DO SERVIDOR ──────────────────────────────────────────
 *
 * Cada rodada nasce no banco com a resposta guardada, e o palpite é conferido
 * contra ela. A tela não sabe a resposta antes de responder — não há o que
 * ler no console, e recarregar não sorteia outro lugar.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../core/conexao.php';
require_once __DIR__ . '/../core/geo.php';

if (!isset($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$user_id = (int)$_SESSION['user_id'];

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
            $resolvida = geoResponder($pdo, $partida, $rodada, [
                'pais' => (string)($_POST['pais'] ?? ''),
                'lat'  => (float)($_POST['lat'] ?? 0),
                'lon'  => (float)($_POST['lon'] ?? 0),
            ]);
        }
    }
}

$partida   = geoPartidaAberta($pdo, $user_id);
$rodada    = $partida ? geoRodadaAtual($pdo, (int)$partida['id']) : null;
$recorde   = geoMeuRecorde($pdo, $user_id);
$restantes = geoPremiadasRestantes($pdo, $user_id);
$moedas    = (int)($pdo->query('SELECT pontos FROM games_usuarios WHERE id = ' . $user_id)->fetchColumn() ?: 0);
$nLugares  = count(geoLugares());
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>FBA Geo</title>
<link rel="icon" type="image/png" href="/img/fba-logo.png?v=3">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700&family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<?php if ($partida && $partida['modo'] === 'mapa'): ?>
  <?php /* O mapa é Leaflet com tiles do OpenStreetMap: os dois de graça, e a
       atribuição vai no canto do mapa, como a licença pede. */ ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<?php endif; ?>
<style>
:root{
  --bg:#07070a; --painel:#101013; --painel2:#16161a; --painel3:#1c1c21;
  --borda:rgba(255,255,255,.07); --borda2:rgba(255,255,255,.14);
  --txt:#f0f0f3; --txt2:#9a9aa3; --txt3:#7d7d85;
  --verde:#22c55e; --amarelo:#f5c518; --vermelho:#ff5c7a; --azul:#38bdf8;
  --display:'Barlow Condensed',Inter,system-ui,sans-serif;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font-family:Inter,system-ui,sans-serif;
  font-size:15px;line-height:1.5}
a{color:inherit}
.topo{display:flex;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid var(--borda);
  position:sticky;top:0;background:rgba(7,7,10,.92);backdrop-filter:blur(8px);z-index:20}
.topo h1{font-family:var(--display);font-size:22px;margin:0;letter-spacing:.5px}
.topo h1 b{color:var(--azul)}
.topo .dir{margin-left:auto;display:flex;align-items:center;gap:10px}
.pilula{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;
  background:var(--painel);font-size:13px;font-weight:700}
.pilula i{color:var(--amarelo)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 16px;
  border-radius:12px;border:1px solid var(--borda2);background:var(--painel);color:var(--txt);
  font:inherit;font-size:13.5px;font-weight:700;cursor:pointer;text-decoration:none}
.btn:hover{border-color:var(--azul)}
.btn.pri{background:var(--azul);border-color:var(--azul);color:#06222e}
.btn.grande{padding:14px 26px;font-size:15.5px}
.wrap{max-width:1000px;margin:0 auto;padding:18px}
.bloco{background:var(--painel);border-radius:18px;padding:20px;margin-bottom:16px}
.bloco h2{font-family:var(--display);font-size:24px;margin:0 0 6px;letter-spacing:.3px}
.sub{color:var(--txt2);font-size:13.5px;margin:0}

/* ── OS DOIS MODOS ─────────────────────────────────────────────────── */
.modos{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:14px;margin-top:16px}
.modo{display:flex;flex-direction:column;gap:8px;padding:18px;border-radius:16px;background:var(--painel2);
  position:relative;overflow:hidden;text-align:left;border:none;color:inherit;font:inherit;cursor:pointer}
.modo::before{content:"";position:absolute;inset:-50% 30% auto -10%;height:170px;pointer-events:none;
  background:radial-gradient(60% 100% at 30% 0,color-mix(in srgb,var(--c) 26%,transparent),transparent 70%)}
.modo > *{position:relative}
.modo:hover{background:var(--painel3)}
.modo i.ico{font-size:22px;color:var(--c)}
.modo b{font-family:var(--display);font-size:21px;letter-spacing:.3px}
.modo small{color:var(--txt2);font-size:12.5px;line-height:1.45}
.modo .rec{margin-top:4px;font-size:12px;color:var(--txt3)}
.modo .rec b{font-family:Inter;font-size:13px;color:var(--c)}

/* ── A RODADA ──────────────────────────────────────────────────────── */
.placar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px}
.placar .n{font-family:var(--display);font-size:30px;line-height:1}
.placar .r{font-size:12px;color:var(--txt3);text-transform:uppercase;letter-spacing:.6px}
.foto{position:relative;width:100%;aspect-ratio:16/10;border-radius:16px;overflow:hidden;background:var(--painel2)}
.foto img{width:100%;height:100%;object-fit:cover;display:block}
.foto .carregando{position:absolute;inset:0;display:grid;place-items:center;color:var(--txt3);font-size:13px}
.opcoes{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:9px;margin-top:14px}
/* Cada opção é um form, e era ELE que ocupava a coluna — o botão dentro
   encolhia até o tamanho do nome do país, virando um alvo pequeno no
   celular. O form some do layout e o botão ocupa a coluna inteira. */
.opcoes form{display:contents}
.opcoes button{width:100%;padding:14px 16px;border-radius:13px;border:1px solid transparent;
  background:var(--painel2);color:var(--txt);font:inherit;font-size:14.5px;font-weight:600;
  cursor:pointer;text-align:left}
.opcoes button:hover{background:var(--painel3);border-color:var(--borda2)}
.opcoes button:disabled{opacity:.5;cursor:default}
#mapa{height:min(55vh,460px);border-radius:16px;margin-top:14px;background:var(--painel2)}
.mapa-l{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px}
.mapa-l .sub{flex:1;min-width:200px}

/* ── O RESULTADO DA RODADA ─────────────────────────────────────────── */
.result{padding:18px;border-radius:16px;margin-bottom:16px;background:var(--painel2);
  border-left:4px solid var(--c)}
.result h3{font-family:var(--display);font-size:23px;margin:0 0 4px;letter-spacing:.3px}
.result .onde{font-size:15px;color:var(--txt)}
.result .onde b{color:var(--c)}
.result .credito{font-size:11.5px;color:var(--txt3);margin-top:10px}
.result .credito a{color:var(--txt2)}
.fim{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}

/* ── RANKING ───────────────────────────────────────────────────────── */
.ranks{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:16px}
.rank-l{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:11px;font-size:13.5px}
.rank-l:nth-child(odd){background:var(--painel2)}
.rank-l .pos{font-family:var(--display);font-size:17px;color:var(--txt3);min-width:22px}
.rank-l .gm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rank-l .v{font-weight:800;color:var(--azul);font-variant-numeric:tabular-nums}
.rank-l.eu{background:color-mix(in srgb,var(--azul) 14%,transparent)}
.aviso{padding:12px 16px;border-radius:12px;margin-bottom:14px;font-size:13.5px}
.aviso.ok{background:color-mix(in srgb,var(--verde) 14%,transparent);color:var(--verde)}
.aviso.err{background:color-mix(in srgb,var(--vermelho) 14%,transparent);color:var(--vermelho)}
@media (max-width:560px){.wrap{padding:12px}.bloco{padding:16px}}
</style>
</head>
<body>

<header class="topo">
  <h1>FBA <b>Geo</b></h1>
  <div class="dir">
    <span class="pilula"><i class="bi bi-coin"></i> <?= number_format($moedas, 0, ',', '.') ?></span>
    <a class="btn" href="/games.php"><i class="bi bi-arrow-left"></i> Games</a>
  </div>
</header>

<div class="wrap">
<?php if ($msg): ?><div class="aviso ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="aviso err"><?= e($erro) ?></div><?php endif; ?>

<?php /* ── O RESULTADO DA RODADA QUE ACABOU ─────────────────────────── */ ?>
<?php if ($resolvida):
  $lug = $resolvida['lugar'];
  $cor = $resolvida['acertou'] ? 'var(--verde)' : 'var(--vermelho)'; ?>
  <div class="result" style="--c:<?= $cor ?>">
    <h3><?= $resolvida['acertou'] ? 'Acertou!' : ($resolvida['km'] !== null ? 'Olha só…' : 'Errou') ?></h3>
    <div class="onde">Era <b><?= e($lug['nome']) ?></b>, <?= e($lug['pais']) ?>.
      <?php if ($resolvida['km'] !== null): ?>
        Você ficou a <b><?= number_format($resolvida['km'], 0, ',', '.') ?> km</b>
        e fez <b><?= number_format($resolvida['pontos'], 0, ',', '.') ?></b> pontos.
      <?php endif; ?>
    </div>
    <div class="credito">
      foto de <?= e($lug['autor']) ?> · <?= e($lug['licenca']) ?> ·
      <a target="_blank" rel="noopener"
         href="https://commons.wikimedia.org/wiki/File:<?= rawurlencode($lug['img']) ?>">Wikimedia Commons</a>
    </div>

    <?php if (!empty($resolvida['fim'])): ?>
      <div class="fim">
        <form method="POST" style="margin:0">
          <input type="hidden" name="acao" value="comecar">
          <input type="hidden" name="modo" value="<?= $resolvida['km'] !== null ? 'mapa' : 'pais' ?>">
          <button class="btn pri" type="submit"><i class="bi bi-arrow-repeat"></i> Jogar de novo</button>
        </form>
        <a class="btn" href="/games/games/geo.php"><i class="bi bi-house"></i> Tela inicial</a>
      </div>
      <div class="credito" style="margin-top:12px">
        <?php if ($resolvida['km'] !== null): ?>
          Fim de partida: <b><?= number_format($resolvida['pontos_total'], 0, ',', '.') ?></b> pontos nas
          <?= GEO_RODADAS_MAPA ?> rodadas.
        <?php else: ?>
          Fim de série: <b><?= (int)$resolvida['acertos_total'] ?></b>
          acerto<?= (int)$resolvida['acertos_total'] === 1 ? '' : 's' ?> seguidos.
        <?php endif; ?>
        <?= !empty($resolvida['moedas'])
            ? ' Você ganhou ' . (int)$resolvida['moedas'] . ' moedas.'
            : ' (sem moedas: o teto de ' . GEO_PREMIADAS_DIA . ' partidas premiadas do dia já passou)' ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($partida && $rodada):
  /* ── JOGANDO ──────────────────────────────────────────────────────── */
  $lug = $rodada['dados'];
  $ehMapa = $partida['modo'] === 'mapa';
  $opcoes = $ehMapa ? [] : (array)json_decode((string)$rodada['opcoes'], true); ?>
  <div class="bloco">
    <div class="placar">
      <div>
        <div class="n"><?= $ehMapa ? (int)$rodada['n'] . '/' . GEO_RODADAS_MAPA : (int)$partida['acertos'] ?></div>
        <div class="r"><?= $ehMapa ? 'rodada' : 'acertos seguidos' ?></div>
      </div>
      <?php if ($ehMapa): ?>
        <div>
          <div class="n"><?= number_format((int)$partida['pontos'], 0, ',', '.') ?></div>
          <div class="r">pontos</div>
        </div>
      <?php endif; ?>
      <form method="POST" style="margin-left:auto">
        <input type="hidden" name="acao" value="desistir">
        <button class="btn" type="submit"><i class="bi bi-x-circle"></i> Encerrar</button>
      </form>
    </div>

    <div class="foto">
      <div class="carregando">carregando a foto…</div>
      <img src="<?= e(geoFoto((string)$lug['img'])) ?>" alt="Onde é isso?" loading="eager"
           onload="this.previousElementSibling.remove()">
    </div>

    <?php if ($ehMapa): ?>
      <div id="mapa"></div>
      <form method="POST" class="mapa-l" id="formMapa">
        <input type="hidden" name="acao" value="palpite">
        <input type="hidden" name="lat" id="lat"><input type="hidden" name="lon" id="lon">
        <p class="sub" id="dicaMapa">Clique no mapa onde você acha que é — depois confirme.</p>
        <button class="btn pri grande" type="submit" id="btnMapa" disabled>
          <i class="bi bi-geo-alt-fill"></i> Confirmar palpite</button>
      </form>
    <?php else: ?>
      <div class="opcoes">
        <?php foreach ($opcoes as $op): ?>
          <form method="POST" style="margin:0">
            <input type="hidden" name="acao" value="palpite">
            <input type="hidden" name="pais" value="<?= e($op) ?>">
            <button type="submit"><?= e($op) ?></button>
          </form>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

<?php else:
  /* ── A TELA INICIAL ───────────────────────────────────────────────── */ ?>
  <div class="bloco">
    <h2>FBA Geo</h2>
    <p class="sub">Aparece a foto de um lugar do mundo e você diz onde é. São
      <?= number_format($nLugares, 0, ',', '.') ?> lugares em 158 países — cidades e pontos
      conhecidos, com foto do Wikimedia Commons.</p>
    <div class="modos">
      <form method="POST" style="margin:0">
        <input type="hidden" name="acao" value="comecar">
        <input type="hidden" name="modo" value="pais">
        <button class="modo" type="submit" style="--c:var(--verde)">
          <i class="bi bi-flag-fill ico"></i>
          <b>Qual é o país?</b>
          <small>Seis opções por foto. Acertou, vem outra; errou, acabou — o ranking é a
            maior sequência. Cada acerto paga <?= GEO_MOEDA_ACERTO ?> moedas.</small>
          <span class="rec">seu recorde: <b><?= (int)$recorde['pais']['a'] ?></b> seguidos
            · <?= (int)$recorde['pais']['n'] ?> partidas</span>
        </button>
      </form>
      <form method="POST" style="margin:0">
        <input type="hidden" name="acao" value="comecar">
        <input type="hidden" name="modo" value="mapa">
        <button class="modo" type="submit" style="--c:var(--azul)">
          <i class="bi bi-geo-alt-fill ico"></i>
          <b>Onde fica no mapa?</b>
          <small><?= GEO_RODADAS_MAPA ?> rodadas, clicando no mapa. Quanto mais perto, mais
            pontos — 5.000 no alvo. Cada 1.000 pontos paga <?= GEO_MOEDA_MAPA_MIL ?> moeda.</small>
          <span class="rec">seu recorde: <b><?= number_format((int)$recorde['mapa']['p'], 0, ',', '.') ?></b> pontos
            · <?= (int)$recorde['mapa']['n'] ?> partidas</span>
        </button>
      </form>
    </div>
    <p class="sub" style="margin-top:14px;font-size:12.5px">
      Hoje ainda valem moedas <b><?= $restantes ?></b> partida<?= $restantes === 1 ? '' : 's' ?>
      — depois disso dá pra jogar à vontade, valendo só o ranking.</p>
  </div>

  <div class="bloco">
    <h2>Os melhores</h2>
    <div class="ranks">
      <?php foreach ([['pais', 'Maior sequência de países', 'seguidos'],
                      ['mapa', 'Mais pontos no mapa', 'pts']] as [$modo, $titulo, $un]):
        $r = geoRanking($pdo, $modo, 10); ?>
        <div>
          <p class="sub" style="margin-bottom:8px;font-weight:700;color:var(--txt)"><?= e($titulo) ?></p>
          <?php if (!$r): ?>
            <p class="sub">Ninguém jogou ainda. Seja o primeiro.</p>
          <?php else: foreach ($r as $i => $x): ?>
            <div class="rank-l<?= (int)$x['user_id'] === $user_id ? ' eu' : '' ?>">
              <span class="pos"><?= $i + 1 ?></span>
              <span class="gm"><?= e($x['gm'] ?: 'GM') ?></span>
              <span class="v"><?= number_format((int)$x['melhor'], 0, ',', '.') ?> <?= $un ?></span>
            </div>
          <?php endforeach; endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
</div>

<?php if ($partida && $rodada && $partida['modo'] === 'mapa'): ?>
<script>
/* O MAPA DO PALPITE. Mundo inteiro visível, um alfinete por clique, e o
   botão só acende quando há onde cravar — confirmar sem palpite mandaria o
   ponto (0,0), no meio do Atlântico. */
(function(){
  var mapa = L.map('mapa', {worldCopyJump: true, zoomControl: true}).setView([15, 0], 2);
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
      'Palpite em ' + p.lat.toFixed(2) + ', ' + p.lng.toFixed(2) + ' — dá pra mudar clicando de novo.';
  });

  /* Dois cliques no confirmar mandariam duas respostas pra mesma rodada; a
     segunda o servidor recusa, mas o botão travado evita o susto. */
  document.getElementById('formMapa').addEventListener('submit', function(){
    document.getElementById('btnMapa').disabled = true;
  });
})();
</script>
<?php endif; ?>
</body>
</html>
