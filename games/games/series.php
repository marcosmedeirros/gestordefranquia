<?php
/**
 * SÉRIES — o diário de quem assiste.
 *
 * Marque o que viu, o que está vendo e o que quer ver; dê nota do que
 * assistiu; escolha as cinco favoritas. Cada ficha mostra três notas que são
 * três perguntas diferentes: o que o mundo achou (IMDb), o que a liga achou
 * (FBA) e o que VOCÊ achou.
 *
 * A regra do jogo está em backend/series.php — aqui é só a tela e o
 * roteamento. O catálogo entra por games/core/series_importar_cli.php.
 *
 * SÓ SÉRIE, sem filme: é o recorte do jogo e está na origem dos dados.
 */

session_start();
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/series.php';

$idUsuario = (int)($_SESSION['user_id'] ?? 0);
$pdo = db();

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ── AS AÇÕES RESPONDEM JSON ──────────────────────────────────────────
   A tela nunca recarrega pra marcar uma série: quem está varrendo a grade
   marcando cinco coisas perderia o lugar a cada clique. */
if ($idUsuario > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $acao  = (string)($_POST['acao'] ?? '');
    $serie = (int)($_POST['serie'] ?? 0);

    if ($acao === 'marcar') {
        echo json_encode(seriesMarcar($pdo, $idUsuario, $serie, (string)($_POST['estado'] ?? ''))
            + ['perfil' => seriesPerfil($pdo, $idUsuario)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($acao === 'avaliar') {
        $n = $_POST['nota'] === '' ? null : (int)$_POST['nota'];
        echo json_encode(seriesAvaliar($pdo, $idUsuario, $serie, $n)
            + ['perfil' => seriesPerfil($pdo, $idUsuario)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($acao === 'favoritar') {
        echo json_encode(seriesFavoritar($pdo, $idUsuario, $serie)
            + ['perfil' => seriesPerfil($pdo, $idUsuario)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => false, 'erro' => 'Ação desconhecida.']);
    exit;
}

/* A busca também é JSON: uma consulta por pausa na digitação. */
if ($idUsuario > 0 && isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($_GET['json'] === 'busca') {
        echo json_encode(['series' => seriesBuscar($pdo, (string)($_GET['q'] ?? ''), $idUsuario)],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_GET['json'] === 'serie') {
        echo json_encode(['serie' => seriesUma($pdo, (int)($_GET['id'] ?? 0), $idUsuario)],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([]);
    exit;
}

$aba      = (string)($_GET['aba'] ?? 'catalogo');
$catalogo = $idUsuario > 0 ? seriesQuantasNoCatalogo($pdo) : 0;
$perfil   = $idUsuario > 0 ? seriesPerfil($pdo, $idUsuario) : null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Séries — FBA Games</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Barlow+Condensed:wght@600;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#08090c; --panel:#111318; --panel2:#171a21; --panel3:#1e222b;
  --borda:#252a35; --borda2:#333a49;
  --txt:#f2f4f7; --txt2:#98a1b0; --txt3:#68707e;
  /* O ROXO É DO JOGO. Os outros jogos da casa são verdes e vermelhos de
     quadra; séries não é esporte, e a cor separa a aba na hora. */
  --acento:#a855f7; --acento-2:#4c1d95;
  --imdb:#f5c518; --fba:#22c55e;
  --display:'Barlow Condensed','Inter',system-ui,sans-serif;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font:15px/1.5 Inter,system-ui,sans-serif;
  -webkit-font-smoothing:antialiased}
a{color:inherit}
#app{max-width:1180px;margin:0 auto;padding:14px 14px 90px}

.topo{position:sticky;top:0;z-index:40;display:flex;align-items:center;gap:10px;
  margin:0 -14px 14px;padding:10px 14px;background:var(--bg);flex-wrap:wrap;
  border-bottom:1px solid transparent;transition:border-color .2s}
.topo.rolou{border-bottom-color:var(--borda)}
.voltar{width:34px;height:34px;display:flex;align-items:center;justify-content:center;
  border-radius:10px;border:1px solid var(--borda);background:var(--panel);text-decoration:none;flex-shrink:0}
.marca{font-family:var(--display);font-size:21px;font-weight:700;letter-spacing:.3px;
  display:flex;align-items:center;gap:8px;white-space:nowrap}
.marca i{color:var(--acento)}
.busca{position:relative;flex:1;min-width:190px;display:flex;align-items:center}
.busca > .bi{position:absolute;left:11px;color:var(--txt3);font-size:13px;pointer-events:none}
.busca input{width:100%;padding:9px 12px 9px 33px;border-radius:11px;border:1px solid var(--borda);
  background:var(--panel);color:var(--txt);font-size:13.5px}
.busca input:focus{outline:none;border-color:var(--acento)}

.abas{display:flex;gap:4px;margin-bottom:16px;overflow-x:auto;scrollbar-width:none}
.abas::-webkit-scrollbar{display:none}
.abas a{padding:8px 13px;border-radius:10px;font-size:13px;font-weight:700;color:var(--txt2);
  text-decoration:none;white-space:nowrap}
.abas a.on{background:var(--panel2);color:var(--txt)}

/* ── A GRADE DE PÔSTERES ────────────────────────────────────────────
   O pôster é o que faz achar a série: ninguém lê trinta títulos, mas todo
   mundo reconhece a capa de longe. Por isso ele é o card inteiro, e o texto
   entra por baixo. */
.grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(132px,1fr));gap:13px}
.card{background:none;border:0;padding:0;text-align:left;color:inherit;cursor:pointer;
  display:flex;flex-direction:column;gap:6px}
.capa{position:relative;aspect-ratio:2/3;border-radius:11px;overflow:hidden;
  background:var(--panel2);border:1px solid var(--borda)}
.capa img{width:100%;height:100%;object-fit:cover;display:block}
.capa .vazia{width:100%;height:100%;display:flex;align-items:center;justify-content:center;
  color:var(--txt3);font-size:26px}
.card:hover .capa{border-color:var(--acento)}
.selo{position:absolute;top:6px;left:6px;display:flex;align-items:center;gap:3px;
  padding:2px 6px;border-radius:7px;font-size:10.5px;font-weight:800;
  background:rgba(0,0,0,.72);backdrop-filter:blur(3px)}
.selo.imdb{color:var(--imdb)}
.marca-estado{position:absolute;top:6px;right:6px;width:23px;height:23px;border-radius:7px;
  display:flex;align-items:center;justify-content:center;font-size:12px;
  background:rgba(0,0,0,.72);backdrop-filter:blur(3px)}
.marca-estado.assistida{color:var(--fba)}
.marca-estado.assistindo{color:var(--acento)}
.marca-estado.quero{color:var(--txt2)}
.minha-nota{position:absolute;bottom:6px;right:6px;padding:2px 7px;border-radius:7px;
  font-size:11.5px;font-weight:900;background:var(--acento);color:#fff}
.c-tit{font-size:12.5px;font-weight:700;line-height:1.25;overflow:hidden;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.c-ano{font-size:11px;color:var(--txt3);font-variant-numeric:tabular-nums}

.bloco{background:var(--panel);border:1px solid var(--borda);border-radius:14px;
  padding:15px;margin-bottom:13px}
.bloco h3{margin:0 0 12px;font-family:var(--display);font-size:17px;font-weight:700;
  letter-spacing:.3px;display:flex;align-items:center;gap:8px;text-transform:uppercase}
.bloco h3 i{color:var(--acento)}
.vazio{color:var(--txt3);font-size:13px;padding:14px 0;text-align:center}

.fichas{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.ficha{text-align:center}
.ficha .v{font-family:var(--display);font-size:28px;font-weight:700;line-height:1}
.ficha .r{font-size:10.5px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px;margin-top:3px}

/* ── O POPUP DO JOGO, nunca o do navegador ──────────────────────────
   Vale pra tudo: a ficha da série, a confirmação e o recado de erro. */
.fundo{position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);z-index:60;
  display:flex;align-items:center;justify-content:center;padding:16px}
.fundo[hidden]{display:none}
.pop{width:100%;max-width:620px;max-height:88vh;overflow-y:auto;background:var(--panel);
  border:1px solid var(--borda2);border-radius:16px;padding:16px;animation:sobe .18s ease}
@keyframes sobe{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}
.pop-topo{display:flex;gap:14px;margin-bottom:14px}
.pop-capa{width:122px;flex-shrink:0;border-radius:11px;overflow:hidden;background:var(--panel2);
  border:1px solid var(--borda);aspect-ratio:2/3}
.pop-capa img{width:100%;height:100%;object-fit:cover;display:block}
.pop-tit{font-family:var(--display);font-size:25px;font-weight:700;line-height:1.1;letter-spacing:.2px}
.pop-sub{font-size:12px;color:var(--txt3);margin-top:3px}
.pop-gen{font-size:11.5px;color:var(--txt2);margin-top:7px}
.pop-sin{font-size:13px;color:var(--txt2);line-height:1.5;margin:12px 0}

.notas{display:flex;gap:8px;margin-top:11px;flex-wrap:wrap}
.nota-caixa{flex:1;min-width:80px;padding:8px 10px;border-radius:10px;background:var(--panel3);
  border:1px solid var(--borda);text-align:center}
.nota-caixa .n{font-family:var(--display);font-size:22px;font-weight:700;line-height:1}
.nota-caixa .r{font-size:9.5px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
.nota-caixa.imdb .n{color:var(--imdb)}
.nota-caixa.fba .n{color:var(--fba)}
.nota-caixa.eu .n{color:var(--acento)}

.rot{font-size:10.5px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px;
  font-weight:700;margin:14px 0 6px}
.estados{display:flex;gap:6px;flex-wrap:wrap}
.est{flex:1;min-width:92px;padding:9px 8px;border-radius:10px;border:1px solid var(--borda);
  background:var(--panel3);color:var(--txt2);font-size:12px;font-weight:700;cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:5px}
.est:hover{border-color:var(--borda2);color:var(--txt)}
.est.on{border-color:var(--acento);background:rgba(168,85,247,.14);color:var(--acento)}
.notinhas{display:flex;gap:4px;flex-wrap:wrap}
.notinha{width:34px;height:34px;border-radius:9px;border:1px solid var(--borda);
  background:var(--panel3);color:var(--txt2);font-size:12.5px;font-weight:800;cursor:pointer}
.notinha:hover{border-color:var(--borda2);color:var(--txt)}
.notinha.on{background:var(--acento);border-color:var(--acento);color:#fff}
.notinha:disabled{opacity:.35;cursor:not-allowed}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 14px;
  border-radius:11px;border:1px solid var(--borda2);background:var(--panel3);color:var(--txt);
  font-size:13px;font-weight:700;cursor:pointer;text-decoration:none}
.btn.pri{background:var(--acento);border-color:var(--acento);color:#fff}
.btn.fav.on{background:var(--imdb);border-color:var(--imdb);color:#111}
.pop-acoes{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}
.recado{font-size:12.5px;color:#fca5a5;margin-top:9px;min-height:17px}

/* ── O TOP 5 ────────────────────────────────────────────────────────── */
.top5{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}
.top5 .pos{position:absolute;bottom:6px;left:6px;width:21px;height:21px;border-radius:6px;
  background:var(--imdb);color:#111;font-size:11px;font-weight:900;
  display:flex;align-items:center;justify-content:center}

.linha-liga{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--borda);
  font-size:13px}
.linha-liga:last-child{border-bottom:0}
.linha-liga img{width:32px;height:48px;object-fit:cover;border-radius:6px;flex-shrink:0;background:var(--panel2)}
.linha-liga .q{font-weight:700}
.linha-liga .t{color:var(--txt2);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.linha-liga .n{font-weight:900;color:var(--acento);font-variant-numeric:tabular-nums}

@media (max-width:560px){
  .fichas{grid-template-columns:repeat(2,1fr);row-gap:14px}
  .top5{grid-template-columns:repeat(3,1fr)}
  .grade{grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:10px}
  .pop-topo{flex-direction:column}
  .pop-capa{width:100%;max-width:170px;margin:0 auto}
}
</style>
</head>
<body>
<div id="app">

  <div class="topo">
    <a href="../../games.php" class="voltar" title="Voltar"><i class="bi bi-arrow-left"></i></a>
    <div class="marca"><i class="bi bi-projector-fill"></i> Séries</div>
    <?php if ($idUsuario > 0): ?>
      <div class="busca">
        <i class="bi bi-search"></i>
        <input type="search" id="busca" autocomplete="off" spellcheck="false"
               placeholder="Buscar série" aria-label="Buscar série">
      </div>
    <?php endif; ?>
  </div>

<?php if ($idUsuario <= 0): ?>
  <div class="bloco">
    <h3><i class="bi bi-person-lock"></i> Precisa entrar</h3>
    <p style="color:var(--txt2);margin:0 0 13px">
      O que você marca fica salvo na sua conta — entre na FBA pra começar o seu diário.
    </p>
    <a class="btn pri" href="../../login.php"><i class="bi bi-box-arrow-in-right"></i> Entrar</a>
  </div>

<?php elseif ($catalogo === 0): ?>
  <?php /* CATÁLOGO VAZIO É ESTADO, NÃO ERRO. Quem abrir antes de a importação
       rodar merece saber o que falta em vez de uma grade em branco. */ ?>
  <div class="bloco">
    <h3><i class="bi bi-hourglass-split"></i> O catálogo ainda não chegou</h3>
    <p style="color:var(--txt2);margin:0 0 6px">
      As séries entram por uma importação que roda na mão, uma vez:
    </p>
    <pre style="background:var(--panel3);border:1px solid var(--borda);border-radius:10px;
                padding:11px;font-size:12px;overflow-x:auto;color:var(--txt2)">php games/core/series_importar_cli.php --chave=SUA_CHAVE_TMDB --gravar</pre>
    <p style="color:var(--txt3);font-size:12.5px;margin:0">
      A chave é gratuita e sai em dois minutos em themoviedb.org → Configurações → API.
    </p>
  </div>

<?php else: ?>
  <div class="abas">
    <?php foreach (['catalogo' => 'Catálogo', 'minhas' => 'Minhas séries',
                    'perfil' => 'Meu perfil', 'liga' => 'A liga'] as $k => $rot): ?>
      <a href="?aba=<?= $k ?>" class="<?= $aba === $k ? 'on' : '' ?>"><?= h($rot) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($aba === 'catalogo'): ?>
    <div class="bloco">
      <h3><i class="bi bi-collection-play-fill"></i> <span id="tituloGrade">Mais populares</span></h3>
      <div class="grade" id="grade"></div>
      <div class="vazio" id="gradeVazia" hidden>Nenhuma série com esse nome.</div>
    </div>

  <?php elseif ($aba === 'minhas'): ?>
    <?php foreach (SERIES_ESTADOS as $chave => $rotulo): ?>
      <?php $lista = seriesMinhas($pdo, $idUsuario, $chave); ?>
      <div class="bloco">
        <h3>
          <i class="bi bi-<?= $chave === 'assistida' ? 'check-circle-fill'
                            : ($chave === 'assistindo' ? 'play-circle-fill' : 'bookmark-fill') ?>"></i>
          <?= h($rotulo) ?>
          <span style="margin-left:auto;font-family:Inter;font-size:12px;color:var(--txt3);
                       text-transform:none;letter-spacing:0"><?= count($lista) ?></span>
        </h3>
        <?php if (!$lista): ?>
          <div class="vazio">Nada aqui ainda — marque no catálogo.</div>
        <?php else: ?>
          <div class="grade"><?php foreach ($lista as $s) echo cartaoDeSerie($s); ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

  <?php elseif ($aba === 'perfil'): ?>
    <div class="bloco">
      <h3><i class="bi bi-person-badge-fill"></i> Meu diário</h3>
      <div class="fichas">
        <div class="ficha"><div class="v" id="pAssistidas"><?= (int)$perfil['assistidas'] ?></div><div class="r">assistidas</div></div>
        <div class="ficha"><div class="v" id="pAssistindo"><?= (int)$perfil['assistindo'] ?></div><div class="r">assistindo</div></div>
        <div class="ficha"><div class="v" id="pQuero"><?= (int)$perfil['quero'] ?></div><div class="r">quero ver</div></div>
        <div class="ficha"><div class="v" id="pNota"><?= $perfil['nota_media'] !== null
              ? number_format((float)$perfil['nota_media'], 1, ',', '') : '—' ?></div>
          <div class="r">sua nota média</div></div>
      </div>
    </div>

    <div class="bloco">
      <h3><i class="bi bi-star-fill"></i> Top <?= SERIES_TOP ?></h3>
      <?php if (!$perfil['top']): ?>
        <div class="vazio">Abra uma série que você já assistiu e toque na estrela.</div>
      <?php else: ?>
        <div class="top5"><?php foreach ($perfil['top'] as $s) echo cartaoDeSerie($s, true); ?></div>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <div class="bloco">
      <h3><i class="bi bi-people-fill"></i> O que a liga andou vendo</h3>
      <?php $mov = seriesMovimentoDaLiga($pdo, 25); ?>
      <?php if (!$mov): ?>
        <div class="vazio">Ninguém marcou nada ainda. Seja o primeiro.</div>
      <?php else: ?>
        <?php foreach ($mov as $m): ?>
          <div class="linha-liga">
            <?php $u = seriesPoster($m['poster']); ?>
            <?php if ($u): ?><img src="<?= h($u) ?>" alt="" loading="lazy"><?php else: ?>
              <img alt="" style="background:var(--panel3)"><?php endif; ?>
            <span class="q"><?= h($m['quem']) ?></span>
            <span class="t"><?= h(SERIES_ESTADOS[$m['estado']] ?? '') ?>
              <b style="color:var(--txt)"><?= h($m['titulo']) ?></b></span>
            <?php if ($m['nota'] !== null): ?><span class="n"><?= (int)$m['nota'] ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php
/**
 * O cartão de uma série na grade.
 *
 * Mora numa função porque a grade nasce em quatro lugares — catálogo, as três
 * listas de "minhas" e o top 5 — e escrito em cada um ia divergir no primeiro
 * ajuste de layout.
 */
function cartaoDeSerie(array $s, bool $comPosicao = false): string
{
    $url = seriesPoster($s['poster']);
    $ico = ['assistida' => 'check-lg', 'assistindo' => 'play-fill', 'quero' => 'bookmark-fill'];

    $html = '<button class="card" data-serie="' . (int)$s['id'] . '">'
          . '<span class="capa">'
          . ($url ? '<img src="' . h($url) . '" alt="" loading="lazy">'
                  : '<span class="vazia"><i class="bi bi-tv"></i></span>');

    if ($s['nota_imdb'] !== null) {
        $html .= '<span class="selo imdb"><i class="bi bi-star-fill"></i>'
               . number_format((float)$s['nota_imdb'], 1, ',', '') . '</span>';
    }
    if (!empty($s['estado'])) {
        $html .= '<span class="marca-estado ' . h($s['estado']) . '">'
               . '<i class="bi bi-' . ($ico[$s['estado']] ?? 'dot') . '"></i></span>';
    }
    if (!empty($s['minha_nota'])) {
        $html .= '<span class="minha-nota">' . (int)$s['minha_nota'] . '</span>';
    }
    if ($comPosicao && !empty($s['favorita'])) {
        $html .= '<span class="pos">' . (int)$s['favorita'] . '</span>';
    }

    return $html . '</span>'
         . '<span class="c-tit">' . h($s['titulo']) . '</span>'
         . '<span class="c-ano">'
         . h(seriesAnos($s['ano_inicio'] ? (int)$s['ano_inicio'] : null,
                        $s['ano_fim'] ? (int)$s['ano_fim'] : null, !empty($s['em_exibicao'])))
         . '</span></button>';
}
?>

<?php if ($idUsuario > 0 && $catalogo > 0): ?>
<?php /* ── A FICHA DA SÉRIE ─────────────────────────────────────────────
     Um popup e não uma página: marcar é um gesto de meio segundo, e ir e
     voltar de página a cada série perderia o lugar na grade. */ ?>
<div class="fundo" id="popSerie" hidden>
  <div class="pop">
    <div class="pop-topo">
      <div class="pop-capa" id="fCapa"></div>
      <div style="min-width:0;flex:1">
        <div class="pop-tit" id="fTitulo"></div>
        <div class="pop-sub" id="fSub"></div>
        <div class="pop-gen" id="fGen"></div>
        <div class="notas">
          <div class="nota-caixa imdb"><div class="n" id="fImdb">—</div><div class="r">IMDb</div></div>
          <div class="nota-caixa fba"><div class="n" id="fFba">—</div><div class="r" id="fFbaR">FBA</div></div>
          <div class="nota-caixa eu"><div class="n" id="fEu">—</div><div class="r">você</div></div>
        </div>
      </div>
    </div>

    <div class="pop-sin" id="fSinopse"></div>

    <div class="rot">Onde essa entra</div>
    <div class="estados" id="fEstados">
      <?php foreach (SERIES_ESTADOS as $k => $rot): ?>
        <button class="est" data-estado="<?= h($k) ?>"><?= h($rot) ?></button>
      <?php endforeach; ?>
    </div>

    <div class="rot" id="fRotNota">Sua nota</div>
    <div class="notinhas" id="fNotas">
      <?php for ($n = 1; $n <= 10; $n++): ?>
        <button class="notinha" data-nota="<?= $n ?>"><?= $n ?></button>
      <?php endfor; ?>
    </div>

    <div class="recado" id="fRecado"></div>

    <div class="pop-acoes">
      <button class="btn fav" id="fFav"><i class="bi bi-star"></i> <span>Favorita</span></button>
      <button class="btn pri" data-fechar>Pronto</button>
    </div>
  </div>
</div>

<script>
(function () {
  var POSTER = <?= json_encode(SERIES_IMG_BASE . SERIES_POSTER_G) ?>;
  var ESTADOS = <?= json_encode(SERIES_ESTADOS, JSON_UNESCAPED_UNICODE) ?>;
  var ICONE = {assistida: 'check-lg', assistindo: 'play-fill', quero: 'bookmark-fill'};

  var pop = document.getElementById('popSerie');
  var grade = document.getElementById('grade');
  var atual = null;                       // a série aberta no popup

  function $(id) { return document.getElementById(id); }
  function num(v) { return v === null || v === undefined || v === '' ? null : Number(v); }
  function umaCasa(v) { return v === null ? '—' : String(v).replace('.', ','); }

  /* ── O cartão, igual ao do PHP ──────────────────────────────────────
     Ele nasce nos dois lados: no servidor, pras grades que já vêm prontas, e
     aqui, pra busca que troca a grade sem recarregar. */
  function cartao(s) {
    var capa = s.poster
      ? '<img src="' + POSTER.replace('w500', 'w342') + s.poster + '" alt="" loading="lazy">'
      : '<span class="vazia"><i class="bi bi-tv"></i></span>';
    var selo = s.nota_imdb !== null
      ? '<span class="selo imdb"><i class="bi bi-star-fill"></i>' + umaCasa(s.nota_imdb) + '</span>' : '';
    var est = s.estado
      ? '<span class="marca-estado ' + s.estado + '"><i class="bi bi-' + (ICONE[s.estado] || 'dot') + '"></i></span>' : '';
    var minha = s.minha_nota ? '<span class="minha-nota">' + s.minha_nota + '</span>' : '';
    var ano = s.ano_inicio ? (s.em_exibicao == 1 ? s.ano_inicio + '–'
             : (s.ano_fim && s.ano_fim != s.ano_inicio ? s.ano_inicio + '–' + s.ano_fim : s.ano_inicio)) : '';
    return '<button class="card" data-serie="' + s.id + '"><span class="capa">'
         + capa + selo + est + minha + '</span>'
         + '<span class="c-tit">' + escapa(s.titulo) + '</span>'
         + '<span class="c-ano">' + ano + '</span></button>';
  }
  function escapa(t) {
    return String(t).replace(/[&<>"]/g, function (c) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c];
    });
  }

  /* ── A busca ────────────────────────────────────────────────────────
     Uma consulta por PAUSA na digitação, não por tecla: quem escreve "breaking
     bad" dispararia doze idas ao servidor e jogaria onze fora. */
  var busca = document.getElementById('busca');
  var relogio = null;
  if (busca && grade) {
    busca.addEventListener('input', function () {
      clearTimeout(relogio);
      relogio = setTimeout(carregarGrade, 220);
    });
  }

  function carregarGrade() {
    if (!grade) return;
    var q = busca ? busca.value.trim() : '';
    fetch(location.pathname + '?json=busca&q=' + encodeURIComponent(q))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var lista = d.series || [];
        grade.innerHTML = lista.map(cartao).join('');
        $('gradeVazia').hidden = lista.length > 0;
        $('tituloGrade').textContent = q ? 'Resultados de "' + q + '"' : 'Mais populares';
      })
      .catch(function () {});
  }
  if (grade) carregarGrade();

  /* ── Abrir a ficha ─────────────────────────────────────────────────── */
  document.addEventListener('click', function (e) {
    var c = e.target.closest('.card');
    if (!c) return;
    abrir(Number(c.dataset.serie));
  });

  function abrir(id) {
    fetch(location.pathname + '?json=serie&id=' + id)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.serie) return;
        atual = d.serie;
        pintar();
        pop.hidden = false;
      });
  }

  function pintar() {
    var s = atual;
    $('fCapa').innerHTML = s.poster
      ? '<img src="' + POSTER + s.poster + '" alt="">'
      : '<span class="vazia" style="display:flex;align-items:center;justify-content:center;height:100%"><i class="bi bi-tv"></i></span>';
    $('fTitulo').textContent = s.titulo;

    var partes = [];
    var ano = s.ano_inicio ? (s.em_exibicao == 1 ? s.ano_inicio + '–'
             : (s.ano_fim && s.ano_fim != s.ano_inicio ? s.ano_inicio + '–' + s.ano_fim : s.ano_inicio)) : '';
    if (ano) partes.push(ano);
    if (s.temporadas) partes.push(s.temporadas + (s.temporadas == 1 ? ' temporada' : ' temporadas'));
    if (s.episodios) partes.push(s.episodios + ' episódios');
    if (s.titulo_original && s.titulo_original !== s.titulo) partes.push(s.titulo_original);
    $('fSub').textContent = partes.join(' · ');
    $('fGen').textContent = s.generos || '';
    $('fSinopse').textContent = s.sinopse || 'Sem sinopse no catálogo.';

    $('fImdb').textContent = umaCasa(num(s.nota_imdb));
    $('fFba').textContent  = umaCasa(num(s.nota_fba));
    $('fFbaR').textContent = Number(s.votos_fba) > 0
      ? 'FBA · ' + s.votos_fba + (Number(s.votos_fba) === 1 ? ' nota' : ' notas') : 'FBA';
    $('fEu').textContent   = s.minha_nota ? s.minha_nota : '—';

    document.querySelectorAll('#fEstados .est').forEach(function (b) {
      b.classList.toggle('on', b.dataset.estado === s.estado);
    });

    /* SÓ QUEM ASSISTIU AVALIA — e a tela diz por quê antes de a pessoa
       tentar, em vez de recusar depois do clique. */
    var podeAvaliar = s.estado === 'assistida' || s.estado === 'assistindo';
    document.querySelectorAll('#fNotas .notinha').forEach(function (b) {
      b.disabled = !podeAvaliar;
      b.classList.toggle('on', podeAvaliar && Number(b.dataset.nota) === Number(s.minha_nota));
    });
    $('fRotNota').textContent = podeAvaliar
      ? 'Sua nota' : 'Sua nota — marque como assistida pra poder dar';

    var fav = $('fFav');
    fav.classList.toggle('on', !!s.favorita);
    fav.querySelector('i').className = s.favorita ? 'bi bi-star-fill' : 'bi bi-star';
    fav.querySelector('span').textContent = s.favorita ? 'No seu top (' + s.favorita + 'º)' : 'Favorita';
    $('fRecado').textContent = '';
  }

  /* ── As ações ───────────────────────────────────────────────────────── */
  function manda(corpo) {
    return fetch(location.pathname, {method: 'POST', body: new URLSearchParams(corpo)})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { $('fRecado').textContent = d.erro || 'Não deu.'; return null; }
        $('fRecado').textContent = '';
        atualizarPerfil(d.perfil);
        return d;
      })
      .catch(function () { $('fRecado').textContent = 'Sem resposta do servidor.'; return null; });
  }

  document.getElementById('fEstados').addEventListener('click', function (e) {
    var b = e.target.closest('.est');
    if (!b || !atual) return;
    manda({acao: 'marcar', serie: atual.id, estado: b.dataset.estado}).then(function (d) {
      if (!d) return;
      atual.estado = d.estado;
      /* Sair da lista leva nota e favorita junto — o servidor já fez isso, e a
         tela tem que contar a mesma história. */
      if (!d.estado || d.estado === 'quero') { atual.minha_nota = null; atual.favorita = null; }
      pintar(); recarregarGrade();
    });
  });

  document.getElementById('fNotas').addEventListener('click', function (e) {
    var b = e.target.closest('.notinha');
    if (!b || b.disabled || !atual) return;
    manda({acao: 'avaliar', serie: atual.id, nota: b.dataset.nota}).then(function (d) {
      if (!d) return;
      atual.minha_nota = d.nota;
      atual.nota_fba = d.media;
      atual.votos_fba = d.votos;
      pintar(); recarregarGrade();
    });
  });

  document.getElementById('fFav').addEventListener('click', function () {
    if (!atual) return;
    manda({acao: 'favoritar', serie: atual.id}).then(function (d) {
      if (!d) return;
      atual.favorita = d.favorita;
      pintar();
    });
  });

  function recarregarGrade() { if (grade) carregarGrade(); }

  /* O perfil muda a cada marca, e o número no topo do perfil não pode mentir
     até o próximo F5. */
  function atualizarPerfil(p) {
    if (!p) return;
    var mapa = {pAssistidas: p.assistidas, pAssistindo: p.assistindo, pQuero: p.quero};
    Object.keys(mapa).forEach(function (id) { if ($(id)) $(id).textContent = mapa[id]; });
    if ($('pNota')) $('pNota').textContent = p.nota_media === null ? '—' : umaCasa(p.nota_media);
  }

  /* ── Fechar ─────────────────────────────────────────────────────────── */
  pop.addEventListener('click', function (e) {
    if (e.target === pop || e.target.hasAttribute('data-fechar')) {
      pop.hidden = true;
      atual = null;
      /* O top 5 e as listas do servidor ficam velhos depois de marcar; quem
         está nelas vê o certo ao fechar, sem precisar apertar F5. */
      if (!grade) location.reload();
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !pop.hidden) { pop.hidden = true; atual = null; if (!grade) location.reload(); }
  });

  var topo = document.querySelector('.topo');
  addEventListener('scroll', function () { topo.classList.toggle('rolou', scrollY > 4); }, {passive: true});
})();
</script>
<?php endif; ?>
</body>
</html>
