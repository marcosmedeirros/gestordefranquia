<?php
/**
 * ── FANTASY NBA ──────────────────────────────────────────────────────
 *
 * A tela do fantasy de jogadores reais da NBA. Escala hoje, confere amanhã.
 * A conta mora em backend/fantasy_nba.php; aqui é só a tela.
 *
 * ── POR QUE O MERCADO VAI EM JSON E NÃO EM HTML ──────────────────────
 *
 * São 517 jogadores. Em HTML, com foto e botão, isso é mais de 1 MB de
 * marcação pra uma lista que a pessoa vai filtrar em dois segundos. Vai como
 * dado e o navegador desenha só o que está em tela — é o que mantém a página
 * abrindo rápido no celular, que é onde ela vai ser usada todo dia.
 *
 * ── A CASCA É A DOS JOGOS ────────────────────────────────────────────
 *
 * Header com voltar e moedas, sem menu lateral — igual Copero, Hoops, Geo e
 * os outros. @see games/games/geo.php
 */

require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/fantasy_nba.php';
requireAuth();

$user    = getUserSession();
$user_id = (int)$user['id'];
$pdo     = db();
fnbaTabelas($pdo);

/* A conta de moedas do Games é a mesma dos outros jogos. */
$pdo->prepare('INSERT IGNORE INTO games_usuarios (id, nome, pontos) VALUES (?,?,0)')
    ->execute([$user_id, $user['name'] ?? 'GM']);
$moedas = (int)($pdo->query('SELECT pontos FROM games_usuarios WHERE id = ' . $user_id)->fetchColumn() ?: 0);

$erros = [];
$ok    = '';
$rodada = fnbaRodadaDoDia($pdo);

/* ── O POST ─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'escalar') {
    $ids     = array_map('intval', (array)($_POST['jogadores'] ?? []));
    $capitao = (int)($_POST['capitao'] ?? 0);
    if (!$rodada) {
        $erros[] = 'Não há rodada hoje.';
    } else {
        $r = fnbaEscalar($pdo, (int)$rodada['id'], $user_id, $ids, $capitao);
        if ($r['ok']) {
            /* Redirect pra o F5 não reenviar a escalação. */
            header('Location: /fantasynba.php?salvo=1');
            exit;
        }
        $erros = $r['erros'];
    }
}
if (isset($_GET['salvo'])) $ok = 'Escalação salva. Boa sorte hoje!';

/* ── O QUE A TELA MOSTRA ────────────────────────────────────────────── */
$aberta     = $rodada ? fnbaAberta($rodada) : false;
$escalacao  = $rodada ? fnbaEscalacao($pdo, (int)$rodada['id'], $user_id) : [];
$mercado    = fnbaMercado($pdo);

$jogosHoje = [];
if ($rodada) {
    $st = $pdo->prepare("SELECT g.*, tc.sigla AS casa, tf.sigla AS fora, tc.logo AS logo_casa, tf.logo AS logo_fora
                           FROM fnba_jogos g
                      LEFT JOIN fnba_times tc ON tc.id = g.casa_id
                      LEFT JOIN fnba_times tf ON tf.id = g.fora_id
                          WHERE g.rodada_id = ? ORDER BY g.inicio");
    $st->execute([(int)$rodada['id']]);
    $jogosHoje = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* A última rodada já apurada — é o que a pessoa volta pra ver. */
$ultima = $pdo->query("SELECT * FROM fnba_rodadas WHERE status = 'apurada' ORDER BY dia DESC LIMIT 1")
              ->fetch(PDO::FETCH_ASSOC) ?: null;
$meuUltimo = null; $rankingUltimo = [];
if ($ultima) {
    $st = $pdo->prepare('SELECT * FROM fnba_resultados WHERE rodada_id = ? AND user_id = ?');
    $st->execute([(int)$ultima['id'], $user_id]);
    $meuUltimo = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $rankingUltimo = fnbaRanking($pdo, (int)$ultima['id'], 10);
}

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$escalados = array_column($escalacao, 'jogador_id');
$capAtual  = 0;
foreach ($escalacao as $x) if ((int)$x['capitao'] === 1) $capAtual = (int)$x['jogador_id'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="theme-color" content="#fc0025">
<title>Fantasy NBA</title>
<link rel="icon" type="image/png" href="/img/fba-logo.png?v=3">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
  --red:#fc0025; --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
  --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
  --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85;
  --green:#22c55e; --amber:#f59e0b; --blue:#3b82f6; --roxo:#a855f7;
  --sidebar-w:0px;
  --font:'Montserrat',sans-serif; --radius:14px; --radius-sm:10px;
  --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
}
:root[data-theme="light"]{
  --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
  --border:#e3e6ee; --border-md:#d7dbe6; --text:#12141a; --text-2:#5b6172; --text-3:#6b7080;
}
</style>
<?php include __DIR__ . '/includes/shell-css.php'; ?>
<style>
.topbar{display:flex}
.main{padding-top:54px}
.fn-voltar{display:grid;place-items:center;width:34px;height:34px;flex:none;border-radius:10px;
  color:var(--text-2);background:var(--panel-2);border:1px solid var(--border-md);text-decoration:none;font-size:15px}
.fn-voltar:hover{color:var(--text)}
.fn-saldo{display:inline-flex;align-items:center;gap:6px;flex:none;padding:6px 12px;border-radius:999px;
  font-size:12.5px;color:var(--text-2);background:var(--panel-2);border:1px solid var(--border-md)}
.fn-saldo b,.fn-saldo i{color:var(--amber)}
.dash-hero{max-width:1180px;margin-inline:auto;width:100%;box-sizing:border-box}
#fn{max-width:1180px;margin:0 auto;padding:0 0 56px;font-family:Inter,system-ui,sans-serif}

.fn-aviso{border-radius:12px;padding:12px 16px;font-size:13px;margin-bottom:14px;border:1px solid}
.fn-aviso.ok{background:rgba(34,197,94,.10);border-color:rgba(34,197,94,.3);color:#86efac}
.fn-aviso.err{background:rgba(239,68,68,.10);border-color:rgba(239,68,68,.3);color:#fca5a5}
.fn-aviso.info{background:rgba(59,130,246,.08);border-color:rgba(59,130,246,.25);color:var(--text-2)}

/* ── A barra da rodada ───────────────────────────────────────────── */
.fn-rodada{display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:var(--panel);
  border:1px solid var(--border-md);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px}
.fn-rodada .dia{font-family:var(--font);font-weight:800;font-size:17px;color:var(--text)}
.fn-rodada .sub{font-size:12px;color:var(--text-2)}
.fn-prazo{margin-left:auto;display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;
  background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:var(--amber);
  font-weight:700;font-size:13px;white-space:nowrap}
.fn-prazo.fechado{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.3);color:#fca5a5}
.fn-jogos{display:flex;gap:7px;flex-wrap:wrap;margin-top:4px}
.fn-jogo{display:inline-flex;align-items:center;gap:5px;padding:5px 9px;border-radius:8px;
  background:var(--panel-2);border:1px solid var(--border);font-size:11px;color:var(--text-2)}
.fn-jogo img{width:16px;height:16px;object-fit:contain}
.fn-jogo b{color:var(--text);font-weight:700}

/* ── O time montado ──────────────────────────────────────────────── */
.fn-grid{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:16px;align-items:start}
@media(max-width:980px){ .fn-grid{grid-template-columns:1fr} }
.fn-card{background:var(--panel);border:1px solid var(--border-md);border-radius:var(--radius);padding:16px}
.fn-tit{font-family:var(--font);font-weight:800;font-size:13px;letter-spacing:.4px;text-transform:uppercase;
  color:var(--text);margin-bottom:12px;display:flex;align-items:center;gap:8px}
.fn-tit i{color:var(--red)}
.fn-tit .cnt{margin-left:auto;font-size:11px;color:var(--text-3);font-weight:600;letter-spacing:0;text-transform:none}

.fn-slots{display:flex;flex-direction:column;gap:8px}
.fn-slot{display:flex;align-items:center;gap:10px;padding:10px;border-radius:12px;background:var(--panel-2);
  border:1px dashed var(--border-md);min-height:58px}
.fn-slot.cheio{border-style:solid;border-color:var(--border-md)}
.fn-slot .pos{width:28px;height:28px;flex:none;border-radius:8px;display:grid;place-items:center;
  font-weight:800;font-size:11px;background:var(--panel-3);color:var(--text-2)}
.fn-slot.cheio .pos{background:rgba(59,130,246,.18);color:#93c5fd}
.fn-slot img.rosto{width:36px;height:36px;border-radius:50%;object-fit:cover;background:var(--panel-3);flex:none}
.fn-slot .nm{flex:1;min-width:0}
.fn-slot .nm b{display:block;font-size:13px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fn-slot .nm span{font-size:11px;color:var(--text-2)}
.fn-slot .vazio{flex:1;font-size:12.5px;color:var(--text-3)}
.fn-slot .preco{font-weight:800;font-size:13px;color:var(--amber);flex:none}
.fn-acoes{display:flex;gap:4px;flex:none}
.fn-mini{width:28px;height:28px;border-radius:8px;border:1px solid var(--border-md);background:var(--panel-3);
  color:var(--text-2);cursor:pointer;display:grid;place-items:center;font-size:12px}
.fn-mini:hover{color:var(--text)}
.fn-mini.cap.on{background:rgba(245,158,11,.18);border-color:rgba(245,158,11,.4);color:var(--amber)}

.fn-orc{display:flex;align-items:center;gap:10px;margin-top:14px;padding-top:14px;border-top:1px solid var(--border)}
.fn-orc .barra{flex:1;height:7px;border-radius:4px;background:var(--panel-3);overflow:hidden}
.fn-orc .barra i{display:block;height:100%;background:var(--green);border-radius:4px;transition:width var(--t)}
.fn-orc.estourou .barra i{background:var(--red)}
.fn-orc b{font-size:13px;font-weight:800;color:var(--text);white-space:nowrap}
.fn-orc.estourou b{color:var(--red)}

.fn-salvar{width:100%;margin-top:12px;padding:14px;border-radius:12px;border:none;cursor:pointer;
  background:var(--red);color:#fff;font-family:var(--font);font-weight:800;font-size:14px;letter-spacing:.3px}
.fn-salvar:disabled{opacity:.4;cursor:not-allowed}

/* ── O mercado ───────────────────────────────────────────────────── */
.fn-filtros{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.fn-busca{flex:1;min-width:150px;padding:10px 14px;border-radius:10px;background:var(--panel-2);
  border:1px solid var(--border-md);color:var(--text);font-size:13px;font-family:inherit}
.fn-busca::placeholder{color:var(--text-3)}
.fn-chip{padding:9px 14px;border-radius:10px;background:var(--panel-2);border:1px solid var(--border-md);
  color:var(--text-2);cursor:pointer;font-size:12px;font-weight:700}
.fn-chip.on{background:rgba(252,0,37,.14);border-color:rgba(252,0,37,.4);color:#fca5a5}

.fn-lista{max-height:min(62vh,620px);overflow-y:auto;display:flex;flex-direction:column;gap:6px;
  -webkit-overflow-scrolling:touch}
.fn-lin{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:10px;background:var(--panel-2);
  border:1px solid transparent;cursor:pointer}
.fn-lin:hover{border-color:var(--border-md)}
.fn-lin.dentro{background:rgba(34,197,94,.10);border-color:rgba(34,197,94,.3)}
.fn-lin.caro{opacity:.45}
.fn-lin img{width:34px;height:34px;border-radius:50%;object-fit:cover;background:var(--panel-3);flex:none}
.fn-lin .nm{flex:1;min-width:0}
.fn-lin .nm b{display:block;font-size:13px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fn-lin .nm span{font-size:11px;color:var(--text-2)}
.fn-lin .pos{font-size:10px;font-weight:800;padding:3px 7px;border-radius:6px;background:var(--panel-3);
  color:var(--text-2);flex:none}
.fn-lin .preco{font-weight:800;font-size:13px;color:var(--amber);flex:none;width:52px;text-align:right}
.fn-vazio{padding:24px;text-align:center;color:var(--text-3);font-size:13px}

/* ── O resultado de ontem ────────────────────────────────────────── */
.fn-res{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:12px;background:var(--panel-2);
  border:1px solid var(--border);margin-bottom:10px}
.fn-res .big{font-family:var(--font);font-weight:900;font-size:26px;color:var(--green);line-height:1}
.fn-res .lbl{font-size:11px;color:var(--text-2)}
.fn-rank{display:flex;flex-direction:column;gap:4px}
.fn-rank .l{display:flex;align-items:center;gap:10px;padding:7px 10px;border-radius:9px;font-size:12.5px}
.fn-rank .l:nth-child(odd){background:var(--panel-2)}
.fn-rank .l.eu{background:rgba(252,0,37,.12);box-shadow:inset 0 0 0 1px rgba(252,0,37,.3)}
.fn-rank .p{width:22px;font-weight:800;color:var(--text-3);flex:none}
.fn-rank .n{flex:1;min-width:0;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fn-rank .v{font-weight:800;color:var(--text)}
.fn-rank .m{font-size:11px;color:var(--amber);flex:none}

.fn-regra{font-size:11px;line-height:1.6;color:var(--text-3);margin-top:14px;padding-top:12px;border-top:1px solid var(--border)}
.fn-regra b{color:var(--text-2)}
</style>
</head>
<body>

<header class="topbar">
  <a class="fn-voltar" href="/games.php" title="Voltar aos games"><i class="bi bi-arrow-left"></i></a>
  <div class="topbar-title">Fantasy <em>NBA</em></div>
  <span class="fn-saldo"><i class="bi bi-coin"></i> <b><?= number_format($moedas, 0, ',', '.') ?></b></span>
</header>

<main class="main">
  <div class="dash-hero">
    <div>
      <div class="dash-eyebrow">Jogos da liga</div>
      <h1 class="dash-title">Fantasy NBA</h1>
      <p class="dash-sub">Escale cinco da NBA de verdade. Todo dia tem rodada.</p>
    </div>
  </div>

<div class="content">
<div id="fn">

<?php if ($ok): ?><div class="fn-aviso ok"><?= $e($ok) ?></div><?php endif; ?>
<?php foreach ($erros as $x): ?><div class="fn-aviso err"><?= $e($x) ?></div><?php endforeach; ?>

<?php if (!$rodada): ?>
  <div class="fn-aviso info"><b>Hoje não tem jogo na NBA.</b> A próxima rodada abre no próximo dia
    com partidas — volta amanhã.</div>
<?php else:
  $dt = new DateTimeImmutable($rodada['dia']);
  $diasSem = ['Sun'=>'Domingo','Mon'=>'Segunda','Tue'=>'Terça','Wed'=>'Quarta','Thu'=>'Quinta','Fri'=>'Sexta','Sat'=>'Sábado'];
?>
  <div class="fn-rodada">
    <div>
      <div class="dia"><?= $diasSem[$dt->format('D')] ?? '' ?>, <?= $dt->format('d/m') ?></div>
      <div class="sub"><?= (int)$rodada['jogos'] ?> jogo<?= $rodada['jogos'] == 1 ? '' : 's' ?> na NBA</div>
      <div class="fn-jogos">
        <?php foreach ($jogosHoje as $g): ?>
          <span class="fn-jogo">
            <?php if ($g['logo_fora']): ?><img src="<?= $e($g['logo_fora']) ?>" alt=""><?php endif; ?>
            <b><?= $e($g['fora']) ?></b> @ <b><?= $e($g['casa']) ?></b>
            <?php if ($g['logo_casa']): ?><img src="<?= $e($g['logo_casa']) ?>" alt=""><?php endif; ?>
            · <?= substr((string)$g['inicio'], 11, 5) ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
    <span class="fn-prazo <?= $aberta ? '' : 'fechado' ?>">
      <i class="bi bi-<?= $aberta ? 'clock' : 'lock-fill' ?>"></i>
      <?= $aberta ? 'Escale até ' . substr((string)$rodada['fecha_em'], 11, 5) : 'Escalação fechada' ?>
    </span>
  </div>

  <form method="POST" id="formEscala">
  <input type="hidden" name="acao" value="escalar">
  <div class="fn-grid">

    <?php /* ── O MERCADO ── */ ?>
    <div class="fn-card">
      <div class="fn-tit"><i class="bi bi-people-fill"></i> Mercado
        <span class="cnt" id="cntMercado"></span></div>
      <div class="fn-filtros">
        <input class="fn-busca" id="busca" type="search" placeholder="Buscar jogador ou time…" autocomplete="off">
        <button type="button" class="fn-chip on" data-pos="">Todos</button>
        <button type="button" class="fn-chip" data-pos="G">Armador</button>
        <button type="button" class="fn-chip" data-pos="F">Ala</button>
        <button type="button" class="fn-chip" data-pos="C">Pivô</button>
      </div>
      <div class="fn-lista" id="lista"></div>
    </div>

    <?php /* ── O TIME ── */ ?>
    <div class="fn-card">
      <div class="fn-tit"><i class="bi bi-clipboard-check-fill"></i> Seu time
        <span class="cnt"><?= FNBA_ELENCO ?> jogadores</span></div>
      <div class="fn-slots" id="slots"></div>

      <div class="fn-orc" id="orc">
        <div class="barra"><i id="barraOrc" style="width:0%"></i></div>
        <b id="txtOrc">F$ 0,0 / <?= number_format(FNBA_ORCAMENTO, 0, ',', '.') ?></b>
      </div>

      <?php if ($aberta): ?>
        <button class="fn-salvar" id="btnSalvar" type="submit" disabled>Salvar escalação</button>
      <?php else: ?>
        <div class="fn-aviso info" style="margin:12px 0 0">A rodada fechou às
          <?= substr((string)$rodada['fecha_em'], 11, 5) ?>. Volte amanhã.</div>
      <?php endif; ?>

      <p class="fn-regra">
        <b>Como pontua:</b> ponto 1 · rebote 1,2 · assistência 1,5 · roubo 3 · toco 3 · <b>erro −1</b>.
        O <b>capitão</b> (a estrelinha) vale o dobro.<br>
        <b>A formação</b> é 2 armadores, 2 alas e 1 pivô, dentro de F$ <?= number_format(FNBA_ORCAMENTO, 0, ',', '.') ?>.
        O preço sai da média da temporada passada.<br>
        <b>Dá pra trocar</b> quantas vezes quiser até o primeiro jogo do dia.
      </p>
    </div>
  </div>
  </form>
<?php endif; ?>

<?php if ($ultima): ?>
  <div class="fn-card" style="margin-top:16px">
    <div class="fn-tit"><i class="bi bi-trophy-fill"></i> Rodada de
      <?= $e((new DateTimeImmutable($ultima['dia']))->format('d/m')) ?>
      <span class="cnt"><?= count($rankingUltimo) ?> no top</span></div>

    <?php if ($meuUltimo): ?>
      <div class="fn-res">
        <div>
          <div class="big"><?= number_format((float)$meuUltimo['pontos'], 1, ',', '.') ?></div>
          <div class="lbl">seus pontos</div>
        </div>
        <div>
          <div class="big" style="color:var(--text)"><?= (int)$meuUltimo['posicao'] ?>º</div>
          <div class="lbl">sua posição</div>
        </div>
        <?php if ((int)$meuUltimo['moedas'] > 0): ?>
        <div>
          <div class="big" style="color:var(--amber)">+<?= (int)$meuUltimo['moedas'] ?></div>
          <div class="lbl">moedas</div>
        </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="fn-rank">
      <?php foreach ($rankingUltimo as $i => $r): ?>
        <div class="l <?= (int)$r['user_id'] === $user_id ? 'eu' : '' ?>">
          <span class="p"><?= $i + 1 ?>º</span>
          <span class="n"><?= $e($r['nome'] ?? 'GM') ?></span>
          <?php if ((int)$r['moedas'] > 0): ?><span class="m">+<?= (int)$r['moedas'] ?></span><?php endif; ?>
          <span class="v"><?= number_format((float)$r['pontos'], 1, ',', '.') ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (!$rankingUltimo): ?><div class="fn-vazio">Ninguém escalou nessa rodada.</div><?php endif; ?>
    </div>
  </div>
<?php endif; ?>

</div>
</div>
</main>

<script src="/js/popups.js?v=<?= @filemtime(__DIR__ . '/js/popups.js') ?: 1 ?>"></script>
<script>
/* ── O MONTADOR DE TIME ───────────────────────────────────────────────
   Tudo acontece aqui e só o resultado vai pro servidor — que confere de
   novo, porque validação de tela é conveniência, não regra. @see fnbaValidar */
(function(){
  const MERCADO  = <?= json_encode(array_map(fn($j) => [
        'id' => (int)$j['id'], 'n' => $j['nome'], 'p' => $j['pos'],
        'c' => (float)$j['preco'], 'm' => (float)$j['media_fp'],
        't' => $j['sigla'] ?? '', 'f' => $j['foto'] ?? '',
      ], $mercado), JSON_UNESCAPED_UNICODE) ?>;
  const FORMACAO  = <?= json_encode(FNBA_FORMACAO) ?>;
  const ORCAMENTO = <?= FNBA_ORCAMENTO ?>;
  const ABERTA    = <?= $aberta ? 'true' : 'false' ?>;
  const ROTULO    = {G:'Armador', F:'Ala', C:'Pivô'};

  let escolhidos = <?= json_encode(array_map('intval', $escalados)) ?>;
  let capitao    = <?= (int)$capAtual ?>;
  let filtroPos  = '';
  let busca      = '';

  const porId = {};
  MERCADO.forEach(j => porId[j.id] = j);

  const $ = s => document.querySelector(s);
  const lista = $('#lista'), slots = $('#slots');

  const custo = () => escolhidos.reduce((s, id) => s + (porId[id]?.c || 0), 0);
  const contaPos = p => escolhidos.filter(id => porId[id]?.p === p).length;

  /* Cabe mais um desta posição? É o que deixa a linha apagada no mercado
     antes de o clique virar erro. */
  function cabe(j){
    if (escolhidos.includes(j.id)) return true;
    if (escolhidos.length >= <?= FNBA_ELENCO ?>) return false;
    if (contaPos(j.p) >= (FORMACAO[j.p] || 0)) return false;
    return custo() + j.c <= ORCAMENTO + 0.001;
  }

  function alternar(id){
    if (!ABERTA) return;
    const i = escolhidos.indexOf(id);
    if (i >= 0) {
      escolhidos.splice(i, 1);
      if (capitao === id) capitao = 0;
    } else {
      if (!cabe(porId[id])) return;
      escolhidos.push(id);
      if (!capitao) capitao = id;      // o primeiro já vira capitão
    }
    desenhar();
  }

  function desenharSlots(){
    const ordem = [];
    Object.entries(FORMACAO).forEach(([pos, n]) => {
      const desta = escolhidos.filter(id => porId[id]?.p === pos);
      for (let k = 0; k < n; k++) ordem.push({pos, id: desta[k] || null});
    });
    slots.innerHTML = ordem.map(s => {
      const j = s.id ? porId[s.id] : null;
      if (!j) return `<div class="fn-slot"><span class="pos">${s.pos}</span>
        <span class="vazio">Escolha um ${ROTULO[s.pos].toLowerCase()}</span></div>`;
      const ehCap = capitao === j.id;
      return `<div class="fn-slot cheio">
        <span class="pos">${s.pos}</span>
        ${j.f ? `<img class="rosto" src="${j.f}" alt="" loading="lazy">` : ''}
        <span class="nm"><b>${j.n}</b><span>${j.t} · ${j.m.toFixed(1)} méd${ehCap ? ' · <b style="color:var(--amber)">capitão</b>' : ''}</span></span>
        <span class="preco">${j.c.toFixed(1)}</span>
        ${ABERTA ? `<span class="fn-acoes">
          <button type="button" class="fn-mini cap ${ehCap ? 'on' : ''}" data-cap="${j.id}" title="Capitão (dobra os pontos)"><i class="bi bi-star-fill"></i></button>
          <button type="button" class="fn-mini" data-tira="${j.id}" title="Tirar"><i class="bi bi-x-lg"></i></button>
        </span>` : ''}
      </div>`;
    }).join('');
  }

  function desenharMercado(){
    const q = busca.trim().toLowerCase();
    const vis = MERCADO.filter(j =>
      (!filtroPos || j.p === filtroPos) &&
      (!q || j.n.toLowerCase().includes(q) || (j.t || '').toLowerCase().includes(q)));
    $('#cntMercado').textContent = vis.length + ' de ' + MERCADO.length;
    if (!vis.length) { lista.innerHTML = '<div class="fn-vazio">Ninguém com esse nome.</div>'; return; }
    /* Só os 120 primeiros: a lista é rolável e ninguém percorre 517 linhas
       com o dedo — quem procura alguém específico usa a busca. */
    lista.innerHTML = vis.slice(0, 120).map(j => {
      const dentro = escolhidos.includes(j.id);
      const bloq = !dentro && !cabe(j);
      return `<div class="fn-lin ${dentro ? 'dentro' : ''} ${bloq ? 'caro' : ''}" data-id="${j.id}">
        ${j.f ? `<img src="${j.f}" alt="" loading="lazy">` : '<img alt="">'}
        <span class="pos">${j.p}</span>
        <span class="nm"><b>${j.n}</b><span>${j.t} · ${j.m.toFixed(1)} pts de média</span></span>
        <span class="preco">${j.c.toFixed(1)}</span>
      </div>`;
    }).join('');
  }

  function desenharOrcamento(){
    const c = custo(), pc = Math.min(100, c / ORCAMENTO * 100);
    $('#barraOrc').style.width = pc + '%';
    $('#txtOrc').textContent = 'F$ ' + c.toFixed(1).replace('.', ',') + ' / ' + ORCAMENTO;
    $('#orc').classList.toggle('estourou', c > ORCAMENTO + 0.001);
    const btn = $('#btnSalvar');
    if (btn) {
      const completo = escolhidos.length === <?= FNBA_ELENCO ?> && c <= ORCAMENTO + 0.001 && capitao;
      btn.disabled = !completo;
      btn.textContent = completo ? 'Salvar escalação'
        : `Faltam ${<?= FNBA_ELENCO ?> - escolhidos.length} jogador(es)`;
    }
  }

  function desenhar(){
    desenharSlots(); desenharMercado(); desenharOrcamento();
    document.querySelectorAll('input[name="jogadores[]"],input[name="capitao"]').forEach(i => i.remove());
    const f = $('#formEscala');
    if (!f) return;
    escolhidos.forEach(id => {
      const i = document.createElement('input');
      i.type = 'hidden'; i.name = 'jogadores[]'; i.value = id; f.appendChild(i);
    });
    const c = document.createElement('input');
    c.type = 'hidden'; c.name = 'capitao'; c.value = capitao; f.appendChild(c);
  }

  lista.addEventListener('click', ev => {
    const l = ev.target.closest('.fn-lin');
    if (l) alternar(+l.dataset.id);
  });
  slots.addEventListener('click', ev => {
    const cap = ev.target.closest('[data-cap]');
    if (cap) { capitao = +cap.dataset.cap; desenhar(); return; }
    const tira = ev.target.closest('[data-tira]');
    if (tira) alternar(+tira.dataset.tira);
  });
  $('#busca').addEventListener('input', ev => { busca = ev.target.value; desenharMercado(); });
  document.querySelectorAll('.fn-chip').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('.fn-chip').forEach(o => o.classList.remove('on'));
    b.classList.add('on'); filtroPos = b.dataset.pos; desenharMercado();
  }));

  desenhar();
})();
</script>
</body>
</html>
