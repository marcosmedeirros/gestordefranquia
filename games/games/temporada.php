<?php
/**
 * MINHA TEMPORADA — um jogo do /games.
 *
 * Escolha um time da FBA e leve ele pela temporada: 82 jogos, um a um, com
 * box score. Os outros times da liga você só acompanha na tabela — é o
 * pedido, e também o que deixa a partida rápida: o motor resolve a noite
 * inteira e para no seu jogo.
 *
 * ── O MOTOR É O DO SIMULADOR, A CASA É O /games ───────────────────────
 * A simulação por posses, a classificação, o play-in, os playoffs e os
 * líderes vêm de /simulador/src — código pronto e rodado. Mas o jogo é daqui:
 * tela própria, card no hub, save próprio em games/storage/temporada, e nada
 * disso aparece no "Meus saves" do simulador. @see games/core/temporada.php
 *
 * ── UMA TELA SÓ ──────────────────────────────────────────────────────
 * Sem menu lateral e sem navegação interna: o jogo cabe numa página, e o que
 * muda é o que está acontecendo nela. Antes de começar, a escolha do time;
 * depois, a temporada. O box score abre no lugar do painel de jogos, com
 * ?jogo=ID, e volta com um clique.
 */

require_once __DIR__ . '/../core/conexao.php';
require_once __DIR__ . '/../core/temporada.php';

$user = getUserSession();
if (!$user || (int) $user['id'] <= 0) {
    header('Location: /login.php?next=' . rawurlencode('/games/games/temporada.php'));
    exit;
}
$user_id = (int) $user['id'];

$msg = null;
$erro = $_GET['e'] ?? null;

/* ── AS AÇÕES ────────────────────────────────────────────────────────
   Tudo POST e tudo termina em redirect: sem isso, o F5 depois de simular um
   jogo simularia outro, e a pessoa perderia partidas sem querer. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'comecar') {
        $r = temporadaCriar($user_id, (string) ($_POST['liga'] ?? ''),
                            (int) ($_POST['fba_team_id'] ?? 0), (string) ($_POST['gm_nome'] ?? ''));
        header('Location: /games/games/temporada.php' . ($r['ok'] ? '' : '?liga=' . urlencode((string) ($_POST['liga'] ?? '')) . '&e=' . urlencode($r['erro'])));
        exit;
    }

    if ($acao === 'apagar') {
        temporadaApagar($user_id);
        header('Location: /games/games/temporada.php'); exit;
    }

    if (temporadaAbrir($user_id)) {
        if ($acao === 'proximo')  { $r = temporadaProximoJogo(); $ultimo = $r['jogo']; }
        elseif ($acao === 'varios') { $r = temporadaSimularVarios((int) ($_POST['n'] ?? 5)); $ultimo = $r['jogo']; }
        else $ultimo = null;
        // Volta já no box score do último jogo seu: é o que a pessoa quer ver.
        header('Location: /games/games/temporada.php' . ($ultimo ? '?jogo=' . (int) $ultimo : ''));
        exit;
    }
    header('Location: /games/games/temporada.php'); exit;
}

$tem = temporadaAbrir($user_id);

/* O PAGAMENTO É POR RECONCILIAÇÃO, não por gatilho: olha o chaveamento e paga
   a etapa que ainda não saiu. Roda a cada carregamento porque o time pode ter
   passado de fase em qualquer um dos botões de simular — pendurar o pagamento
   em cada um seria pendurar um esquecimento em cada um. */
$premiosNovos = $tem ? TemporadaPremios::reconciliar($user_id) : [];

$moedas = (int) ($pdo->query('SELECT pontos FROM games_usuarios WHERE id = ' . $user_id)->fetchColumn() ?: 0);

$liga = strtoupper(trim((string) ($_GET['liga'] ?? '')));
if (!in_array($liga, TemporadaLiga::LIGAS, true)) $liga = '';

$est = $tem ? temporadaEstado() : null;
$jogoId = (int) ($_GET['jogo'] ?? 0);
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Minha Temporada · FBA</title>
<meta name="theme-color" content="#fc0025">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="/css/moeda.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Barlow+Condensed:wght@600;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#07090f; --card:#101521; --card2:#161c2b; --linha:#222a3c;
  --txt:#e8edf7; --txt2:#97a3bb; --txt3:#657089;
  --acento:#fc0025; --ok:#22c55e; --ouro:#eab308;
  --time:#3b82f6;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font-family:Inter,system-ui,sans-serif;
     -webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
.topbar{display:flex;align-items:center;gap:12px;padding:10px 16px;background:#0b0f18;
        border-bottom:1px solid var(--linha);position:sticky;top:0;z-index:30}
.topbar .voltar{width:36px;height:36px;display:grid;place-items:center;border-radius:10px;
                background:var(--card2);color:var(--txt2)}
.topbar .titulo{font-family:'Barlow Condensed',sans-serif;font-size:22px;letter-spacing:.5px;
                text-transform:uppercase;flex:1}
.topbar .titulo em{color:var(--acento);font-style:normal}
.saldo{display:flex;align-items:center;gap:6px;background:var(--card2);padding:7px 12px;
       border-radius:99px;font-weight:700;font-size:14px;color:var(--ouro)}
.wrap{max-width:1040px;margin:0 auto;padding:18px 16px 60px}
.painel{background:var(--card);border:1px solid var(--linha);border-radius:16px;
        padding:16px;margin-bottom:16px}
.painel h2{font-family:'Barlow Condensed',sans-serif;font-size:19px;text-transform:uppercase;
           letter-spacing:.6px;margin:0 0 12px;display:flex;align-items:center;gap:8px}
.painel h2 .sub{font-family:Inter,sans-serif;font-size:12px;font-weight:600;color:var(--txt3);
                text-transform:none;letter-spacing:0;margin-left:auto}
.dim{color:var(--txt2);font-size:13px;line-height:1.55}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;cursor:pointer;
     background:var(--card2);color:var(--txt);padding:11px 16px;border-radius:11px;
     font-weight:700;font-size:14px;font-family:inherit}
.btn:hover{background:#1d2537}
.btn-acao{background:var(--acento);color:#fff}
.btn-acao:hover{background:#d8001f}
.btn-g{padding:15px 24px;font-size:16px}
.btn-fraco{background:transparent;border:1px solid var(--linha);color:var(--txt2)}
.linha-btns{display:flex;gap:10px;flex-wrap:wrap}

/* ── hero do time ── */
.hero{display:flex;gap:16px;align-items:center;background:
      linear-gradient(100deg,var(--time) -40%,var(--card) 58%);
      border:1px solid var(--linha);border-radius:18px;padding:18px;margin-bottom:16px}
.hero img,.hero .escudo{width:72px;height:72px;border-radius:14px;object-fit:cover;flex:none;
      background:#0008;display:grid;place-items:center;font-weight:900;font-size:22px}
.hero .quem{flex:1;min-width:0}
.hero .eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:1.4px;color:#ffffffb0;font-weight:800}
.hero h1{margin:2px 0 4px;font-size:26px;line-height:1.1;font-weight:900}
.hero .campanha{font-size:14px;color:#ffffffd0;font-weight:600}
.hero .num{display:flex;gap:18px;flex:none;text-align:center}
.hero .num b{display:block;font-size:24px;font-weight:900;line-height:1}
.hero .num span{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#ffffffa0;font-weight:700}
@media(max-width:620px){.hero{flex-wrap:wrap}.hero .num{width:100%;justify-content:space-around}}

/* ── grade de times na escolha ── */
.times{display:grid;grid-template-columns:repeat(auto-fill,minmax(238px,1fr));gap:10px}
.timecard{position:relative;display:flex;gap:11px;align-items:center;background:var(--card2);
          border:1px solid var(--linha);border-radius:13px;padding:11px;cursor:pointer}
.timecard input{position:absolute;opacity:0;pointer-events:none}
.timecard:has(input:checked){border-color:var(--acento);box-shadow:0 0 0 1px var(--acento) inset}
.timecard img,.timecard .escudo{width:42px;height:42px;border-radius:10px;object-fit:cover;flex:none;
          display:grid;place-items:center;font-weight:900;font-size:13px;color:#fff}
.timecard .n{flex:1;min-width:0}
.timecard .n b{display:block;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* OS FILHOS SÃO BLOCO DE PROPÓSITO. O .n é um <span> (precisa ser, pra caber
   dentro do <label>), e span é inline: altura e largura de filho inline são
   ignoradas. Sem isto a barra de força nascia com 0 de largura e sumia da
   tela — só o nome e as duas linhas de texto apareciam. */
.timecard .n small{display:block;color:var(--txt3);font-size:11.5px}
.barra{display:block;height:5px;border-radius:99px;background:#0b0f18;overflow:hidden;margin:5px 0}
.barra i{display:block;height:100%}
.ligas{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}
.ligacard{display:block;background:var(--card2);border:1px solid var(--linha);border-radius:13px;padding:14px}
.ligacard.on{border-color:var(--acento)}
.ligacard b{font-family:'Barlow Condensed',sans-serif;font-size:21px;letter-spacing:.5px}
.ligacard p{margin:4px 0 0;font-size:12.5px;color:var(--txt2);line-height:1.5}

/* ── tabelas ── */
table{width:100%;border-collapse:collapse;font-size:13.5px}
th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:1px;color:var(--txt3);
   font-weight:800;padding:0 8px 7px}
td{padding:7px 8px;border-top:1px solid var(--linha)}
tr.eu td{background:#fc002514}
.num2{text-align:right;font-variant-numeric:tabular-nums}
.duas{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:760px){.duas{grid-template-columns:1fr}}

/* ── jogos ── */
.jogo{display:flex;align-items:center;gap:10px;padding:9px 0;border-top:1px solid var(--linha)}
.jogo:first-child{border-top:0}
.jogo .res{font-weight:900;font-size:15px;font-variant-numeric:tabular-nums}
.jogo .vd{width:22px;height:22px;border-radius:7px;display:grid;place-items:center;
          font-size:11px;font-weight:900;flex:none}
.vd.v{background:#22c55e22;color:var(--ok)}
.vd.d{background:#ef444422;color:#f87171}
.jogo .adv{flex:1;min-width:0;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.jogo .box{font-size:12px;color:var(--txt3);font-weight:700}

/* ── corridas de prêmio ── */
.corridas{display:grid;grid-template-columns:repeat(auto-fit,minmax(185px,1fr));gap:14px}
.corrida b.rot{display:block;font-size:11px;text-transform:uppercase;letter-spacing:1.1px;
               color:var(--txt3);margin-bottom:7px}
.corrida .l{display:flex;gap:7px;align-items:center;padding:4px 0;font-size:13px}
.corrida .l .p{width:16px;color:var(--txt3);font-weight:800;font-size:11px}
.corrida .l .nm{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.corrida .l .vl{color:var(--txt2);font-size:12px;font-weight:700}

/* ── moedas ── */
.etapa{display:flex;align-items:center;gap:9px;padding:8px 0;border-top:1px solid var(--linha);font-size:13.5px}
.etapa:first-child{border-top:0}
.etapa.feita{color:var(--txt)}
.etapa.falta{color:var(--txt3)}
.etapa .v{margin-left:auto;font-weight:800;font-variant-numeric:tabular-nums}
.etapa.feita .v{color:var(--ouro)}
.aviso{border-radius:12px;padding:12px 14px;margin-bottom:14px;font-size:13.5px;line-height:1.55}
.aviso.ok{background:#eab30818;border:1px solid #eab30855}
.aviso.err{background:#ef444418;border:1px solid #ef444455}
</style>
</head>
<body>

<header class="topbar">
  <a class="voltar" href="/games.php" title="Voltar aos games"><i class="bi bi-arrow-left"></i></a>
  <div class="titulo">Minha <em>Temporada</em></div>
  <span class="saldo" title="Suas moedas"><i class="bi bi-coin"></i> <b><?= number_format($moedas, 0, ',', '.') ?></b></span>
</header>

<div class="wrap">

<?php if ($erro): ?><div class="aviso err"><?= e($erro) ?></div><?php endif; ?>

<?php if ($premiosNovos): $tot = array_sum(array_column($premiosNovos, 'moedas')); ?>
  <div class="aviso ok">
    <b><i class="bi bi-coin"></i> Você ganhou <?= number_format($tot, 0, ',', '.') ?> moedas.</b><br>
    <?php foreach ($premiosNovos as $p): ?>
      <?= e($p['rotulo']) ?> — +<?= (int) $p['moedas'] ?><br>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!$tem): /* ══════════ ESCOLHA DO TIME ══════════ */ ?>

  <div class="painel">
    <h2><i class="bi bi-trophy-fill"></i> Minha Temporada</h2>
    <p class="dim">
      Escolha um time da FBA e simule a temporada dele: <b>82 jogos</b>, um a um, com box score
      completo. Os outros times da liga você acompanha pela tabela. No fim vêm o play-in, os
      playoffs e as corridas de MVP, Evolução, 6º Homem, Defensor e Novato.<br>
      Cada etapa vencida paga moeda — e paga <b>mais quanto pior for o elenco que você pegar</b>.
      Os elencos são os que estão no site agora.
    </p>
  </div>

  <div class="painel">
    <h2><i class="bi bi-diagram-3-fill"></i> 1. A liga</h2>
    <div class="ligas">
      <?php
      $resumo = [
        'ELITE'  => '32 times. Os melhores elencos da FBA.',
        'NEXT'   => '30 times, um degrau abaixo da ELITE.',
        'RISE'   => '30 times em formação.',
        'ROOKIE' => '30 times, a porta de entrada.',
      ];
      foreach (TemporadaLiga::LIGAS as $l): ?>
        <a class="ligacard<?= $l === $liga ? ' on' : '' ?>" href="?liga=<?= $l ?>">
          <b><?= $l ?></b><p><?= e($resumo[$l]) ?></p>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($liga):
    $times = TemporadaLiga::timesDaLiga($liga);
    $ovrs = array_map(fn($t) => (float) $t['ovr_medio'], $times);
    $piso = $ovrs ? min($ovrs) : 0;
    $topo = $ovrs ? max($ovrs) : 1;
  ?>
  <form method="post">
    <input type="hidden" name="acao" value="comecar">
    <input type="hidden" name="liga" value="<?= e($liga) ?>">

    <div class="painel">
      <h2><i class="bi bi-people-fill"></i> 2. O time <span class="sub">do elenco mais forte pro mais fraco</span></h2>
      <div class="times">
        <?php foreach ($times as $i => $t):
          $nome = trim((string) $t['city'] . ' ' . (string) $t['name']);
          $media = (float) $t['ovr_medio'];
          $forca = $topo > $piso ? (int) round(($media - $piso) / ($topo - $piso) * 100) : 50;
          $cor = preg_match('/^#[0-9a-f]{6}$/i', trim((string) $t['cor'])) ? trim((string) $t['cor']) : '#334155';
          $logo = trim((string) $t['photo_url']);
          $recado = $forca >= 80 ? 'Favorito — título paga o mínimo'
                  : ($forca >= 50 ? 'Briga pelos playoffs'
                  : ($forca >= 25 ? 'Azarão — título paga bem' : 'Pior da liga — paga o máximo'));
        ?>
        <label class="timecard">
          <input type="radio" name="fba_team_id" value="<?= (int) $t['id'] ?>" <?= $i === 0 ? 'required' : '' ?>>
          <?php if ($logo): ?>
            <img src="<?= e($logo) ?>" alt="" loading="lazy" style="background:<?= e($cor) ?>">
          <?php else: ?>
            <span class="escudo" style="background:<?= e($cor) ?>"><?= e(mb_substr($nome, 0, 2)) ?></span>
          <?php endif; ?>
          <span class="n">
            <b><?= e($nome) ?></b>
            <small>OVR <?= number_format($media, 1, ',', '.') ?> · teto <?= (int) $t['teto'] ?> · <?= strtoupper((string) $t['conference']) === 'LESTE' ? 'Leste' : 'Oeste' ?></small>
            <span class="barra"><i style="width:<?= max(4, $forca) ?>%;background:<?= e($cor) ?>"></i></span>
            <small><?= e($recado) ?></small>
          </span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="painel">
      <h2><i class="bi bi-person-badge"></i> 3. Seu nome</h2>
      <p><input name="gm_nome" maxlength="40" placeholder="GM"
                style="background:var(--card2);border:1px solid var(--linha);color:var(--txt);
                       padding:11px 13px;border-radius:11px;font:inherit;width:220px"></p>
      <button class="btn btn-acao btn-g" type="submit"><i class="bi bi-play-fill"></i> Começar a temporada</button>
      <p class="dim" style="margin-bottom:0">Monta a liga inteira com os elencos de agora e o calendário de 82 jogos. Leva alguns segundos.</p>
    </div>
  </form>
  <?php endif; ?>

<?php else: /* ══════════ A TEMPORADA ══════════ */
  $meu = $est['time'];
  $corTime = preg_match('/^#[0-9a-f]{6}$/i', (string) ($meu['primary_color'] ?? '')) ? $meu['primary_color'] : '#3b82f6';
  $fase = $est['fase'];
  $faseRot = ['regular' => 'Temporada regular', 'playin' => 'Play-in', 'playoffs' => 'Playoffs', 'offseason' => 'Temporada encerrada'][$fase] ?? $fase;
  $conq = TemporadaPremios::conquistadas();
  $pagas = TemporadaPremios::pagas();
?>

  <div class="hero" style="--time:<?= e($corTime) ?>">
    <?php if (!empty($meu['logo_url'])): ?>
      <img src="<?= e($meu['logo_url']) ?>" alt="">
    <?php else: ?>
      <span class="escudo" style="background:<?= e($corTime) ?>"><?= e($meu['abbr']) ?></span>
    <?php endif; ?>
    <div class="quem">
      <div class="eyebrow"><?= e($est['liga']) ?> · <?= e($faseRot) ?></div>
      <h1><?= e(temporadaNome($meu)) ?></h1>
      <div class="campanha">
        <?= (int) $meu['wins'] ?>–<?= (int) $meu['losses'] ?>
        <?php if ($est['posicao']): ?> · <?= $est['posicao'] ?>º no <?= $meu['conf'] === 'E' ? 'Leste' : 'Oeste' ?><?php endif; ?>
        · GM <?= e($est['gm_nome']) ?>
      </div>
    </div>
    <div class="num">
      <div><b><?= $est['jogados'] ?></b><span>de 82</span></div>
      <div><b><?= $est['forca'] ?></b><span>força</span></div>
      <div><b><?= TemporadaPremios::multiplicador() ?>x</b><span>moedas</span></div>
    </div>
  </div>

  <?php if ($jogoId && ($jg = League::game($jogoId))): /* ── BOX SCORE ── */
    $box = League::boxScore($jogoId);
    $porTime = [];
    foreach ($box as $b) $porTime[(int) $b['team_id']][] = $b;
  ?>
  <div class="painel">
    <h2><i class="bi bi-clipboard-data"></i>
      <?= e(trim($jg['away_city'] . ' ' . $jg['away_name'])) ?> <?= (int) $jg['away_pts'] ?>
      × <?= (int) $jg['home_pts'] ?> <?= e(trim($jg['home_city'] . ' ' . $jg['home_name'])) ?>
      <a class="sub" href="/games/games/temporada.php">fechar ✕</a>
    </h2>
    <div class="duas">
      <?php foreach ([[$jg['away_id'], $jg['away_abbr']], [$jg['home_id'], $jg['home_abbr']]] as [$tid, $tab]): ?>
      <div>
        <table>
          <thead><tr><th><?= e($tab) ?></th><th class="num2">MIN</th><th class="num2">PTS</th>
            <th class="num2">REB</th><th class="num2">AST</th><th class="num2">FG</th></tr></thead>
          <tbody>
          <?php foreach ($porTime[(int) $tid] ?? [] as $b): ?>
            <tr>
              <td><?= e($b['name']) ?> <small style="color:var(--txt3)"><?= e($b['pos']) ?></small></td>
              <td class="num2"><?= (int) $b['min'] ?></td>
              <td class="num2"><b><?= (int) $b['pts'] ?></b></td>
              <td class="num2"><?= (int) $b['reb'] ?></td>
              <td class="num2"><?= (int) $b['ast'] ?></td>
              <td class="num2"><?= (int) $b['fgm'] ?>/<?= (int) $b['fga'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($fase !== 'offseason'): $prox = temporadaProximoAdversario($est['gm_id']); ?>
  <div class="painel">
    <h2><i class="bi bi-play-circle-fill"></i> Próximo jogo</h2>
    <?php if ($prox):
      $euEmCasa = (int) $prox['home_id'] === $est['gm_id'];
      $pre = $euEmCasa ? 'away_' : 'home_';
      $advLogo = $prox[$pre . 'logo'] ?? '';
    ?>
      <div class="jogo" style="border:0;padding-bottom:14px">
        <?php if ($advLogo): ?><img src="<?= e($advLogo) ?>" alt="" width="40" height="40" style="border-radius:10px;object-fit:cover"><?php endif; ?>
        <div class="adv">
          <b><?= $euEmCasa ? 'contra' : 'fora, contra' ?> <?= e(temporadaNome($prox, $pre)) ?></b><br>
          <small style="color:var(--txt3)"><?= (int) $prox[$pre . 'v'] ?>–<?= (int) $prox[$pre . 'd'] ?> na temporada</small>
        </div>
      </div>
      <form method="post" class="linha-btns">
        <button class="btn btn-acao btn-g" name="acao" value="proximo"><i class="bi bi-fast-forward-fill"></i> Simular este jogo</button>
        <button class="btn" name="acao" value="varios" formnovalidate><i class="bi bi-skip-forward-fill"></i> Simular 5</button>
        <input type="hidden" name="n" value="5">
      </form>
      <form method="post" style="margin-top:10px">
        <input type="hidden" name="n" value="82">
        <button class="btn btn-fraco" name="acao" value="varios">Simular até o fim da temporada</button>
      </form>
    <?php else: ?>
      <p class="dim">Sem jogo marcado pro seu time agora.</p>
      <form method="post" class="linha-btns">
        <input type="hidden" name="n" value="82">
        <button class="btn btn-acao" name="acao" value="varios"><i class="bi bi-fast-forward-fill"></i> Avançar</button>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="painel">
    <h2><i class="bi bi-coin"></i> Moedas da temporada
      <span class="sub"><?= $est['rank'] ?>º elenco de <?= $est['times'] ?> · <?= TemporadaPremios::multiplicador() ?>x</span></h2>
    <?php foreach (TemporadaPremios::tabela() as $k => $i):
      $feita = !empty($conq[$i['rodada']]); $pago = in_array($k, $pagas, true); ?>
      <div class="etapa <?= $feita ? 'feita' : 'falta' ?>">
        <i class="bi bi-<?= $feita ? 'check-circle-fill' : 'circle' ?>"></i>
        <span><?= e($i['rotulo']) ?></span>
        <span class="v"><?= number_format($i['moedas'], 0, ',', '.') ?><?= $pago ? ' ✓' : '' ?></span>
      </div>
    <?php endforeach; ?>
    <p class="dim" style="margin-bottom:0">
      Quanto mais fraco o elenco, mais cada etapa paga. O seu tem força <?= $est['forca'] ?>/100,
      então tudo aqui vale <?= TemporadaPremios::multiplicador() ?>x o valor base.
    </p>
  </div>

  <?php $meusJogos = temporadaMeusJogos($est['gm_id'], 8); if ($meusJogos): ?>
  <div class="painel">
    <h2><i class="bi bi-list-ol"></i> Seus últimos jogos</h2>
    <?php foreach ($meusJogos as $g):
      $casa = (int) $g['home_id'] === $est['gm_id'];
      $meusPts = (int) ($casa ? $g['home_pts'] : $g['away_pts']);
      $delesPts = (int) ($casa ? $g['away_pts'] : $g['home_pts']);
      $pre = $casa ? 'away_' : 'home_';
      $v = $meusPts > $delesPts;
    ?>
      <a class="jogo" href="?jogo=<?= (int) $g['id'] ?>">
        <span class="vd <?= $v ? 'v' : 'd' ?>"><?= $v ? 'V' : 'D' ?></span>
        <span class="res"><?= $meusPts ?>–<?= $delesPts ?></span>
        <span class="adv"><?= $casa ? 'vs' : '@' ?> <?= e(temporadaNome($g, $pre)) ?>
          <?php if ($g['stage'] !== 'regular'): ?><small style="color:var(--acento)"> · <?= e($g['stage']) ?></small><?php endif; ?>
        </span>
        <span class="box">box score ›</span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php $race = $est['jogados'] >= 5 ? League::awardRace() : null; if ($race): ?>
  <div class="painel">
    <h2><i class="bi bi-award-fill"></i> Corrida pelos prêmios <span class="sub">pts · reb · ast por jogo</span></h2>
    <div class="corridas">
      <?php
      /* MOSTRA PONTO, REBOTE E ASSISTÊNCIA — não só o ponto.
         A corrida é ordenada por produção inteira (pts + rebotes + assistências,
         com pesos), mas a tela mostrava só os pontos. Dava uma lista que
         parecia errada: 24,1 em primeiro e 24,8 em terceiro. Com as três
         linhas, a ordem se explica sozinha. */
      $pra = fn($p) => number_format((float) $p['ppg'], 1) . ' · '
                     . number_format((float) $p['rpg'], 1) . ' · '
                     . number_format((float) $p['apg'], 1);
      foreach ([
        'mvp'   => ['MVP',      $pra],
        'mip'   => ['Evolução', fn($p) => '+' . number_format((float) $p['score'], 1) . ' acima do OVR'],
        'sexto' => ['6º Homem', $pra],
        'dpoy'  => ['Defensor', fn($p) => number_format((float) $p['bpg'], 1) . ' toc · ' . number_format((float) $p['spg'], 1) . ' rb'],
        'roy'   => ['Novato',   $pra],
      ] as $k => [$rot, $val]): ?>
        <div class="corrida">
          <b class="rot"><?= e($rot) ?></b>
          <?php if (!empty($race[$k])): foreach (array_slice($race[$k], 0, 3) as $i => $p): ?>
            <div class="l"><span class="p"><?= $i + 1 ?></span>
              <span class="nm"><?= e($p['name']) ?> <small style="color:var(--txt3)"><?= e($p['abbr']) ?></small></span>
              <span class="vl"><?= e($val($p)) ?></span></div>
          <?php endforeach; else: ?>
            <div class="l" style="color:var(--txt3)">Poucos jogos ainda.</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="painel">
    <h2><i class="bi bi-table"></i> Classificação</h2>
    <div class="duas">
      <?php foreach (['E' => 'Leste', 'W' => 'Oeste'] as $cf => $rot): ?>
      <div>
        <table>
          <thead><tr><th><?= $rot ?></th><th class="num2">V</th><th class="num2">D</th><th class="num2">%</th></tr></thead>
          <tbody>
          <?php foreach (array_slice(League::standings($cf), 0, 12) as $i => $t): ?>
            <tr class="<?= (int) $t['id'] === $est['gm_id'] ? 'eu' : '' ?>">
              <td><span style="color:var(--txt3)"><?= $i + 1 ?></span> <?= e(temporadaNome($t)) ?></td>
              <td class="num2"><?= (int) $t['wins'] ?></td>
              <td class="num2"><?= (int) $t['losses'] ?></td>
              <td class="num2"><?= number_format((float) $t['pct'], 3, ',', '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (in_array($fase, ['playin', 'playoffs', 'offseason'], true)):
    $series = Database::conn()->query(
      "SELECT s.round, s.conf, s.high_wins, s.low_wins, s.winner_id,
              h.city hc, h.name hn, h.abbr ha, a.city ac, a.name an, a.abbr aa,
              s.high_seed_id, s.low_seed_id
         FROM playoff_series s
         JOIN teams h ON h.id = s.high_seed_id
         JOIN teams a ON a.id = s.low_seed_id
        ORDER BY s.round, s.conf")->fetchAll();
    if ($series): ?>
  <div class="painel">
    <h2><i class="bi bi-trophy-fill"></i> Playoffs</h2>
    <?php $rotR = [1 => 'Primeira rodada', 2 => 'Semifinais de conferência', 3 => 'Finais de conferência', 4 => 'Final da FBA'];
    $porRodada = [];
    foreach ($series as $s) $porRodada[(int) $s['round']][] = $s;
    foreach ($porRodada as $r => $lista): ?>
      <b class="rot" style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:1.1px;color:var(--txt3);margin:14px 0 6px"><?= e($rotR[$r] ?? ('Rodada ' . $r)) ?></b>
      <?php foreach ($lista as $s):
        $euAqui = in_array($est['gm_id'], [(int) $s['high_seed_id'], (int) $s['low_seed_id']], true); ?>
        <div class="etapa <?= $euAqui ? 'feita' : 'falta' ?>">
          <span><?= e(trim($s['hc'] . ' ' . $s['hn'])) ?> <b><?= (int) $s['high_wins'] ?></b>
            × <b><?= (int) $s['low_wins'] ?></b> <?= e(trim($s['ac'] . ' ' . $s['an'])) ?></span>
          <?php if ($s['winner_id']): ?><span class="v"><?= e((int) $s['winner_id'] === (int) $s['high_seed_id'] ? $s['ha'] : $s['aa']) ?></span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; endif; ?>

  <div class="painel">
    <h2><i class="bi bi-arrow-counterclockwise"></i> Recomeçar</h2>
    <p class="dim">
      Apagar a temporada em curso e escolher outro time. O que já foi pago em moeda
      <b>fica</b> — mas as etapas que faltavam somem junto com ela.
    </p>
    <form method="post" onsubmit="return confirm('Apagar esta temporada e começar outra? Não dá pra desfazer.')">
      <button class="btn btn-fraco" name="acao" value="apagar"><i class="bi bi-trash3"></i> Apagar e escolher outro time</button>
    </form>
  </div>

<?php endif; ?>
</div>
</body>
</html>
