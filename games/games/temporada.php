<?php
/**
 * MINHA TEMPORADA — um jogo do /games.
 *
 * Escolha um time da FBA e leve ele pela temporada: 82 jogos, um a um. Os
 * outros times da liga você acompanha pela tabela — é o pedido, e é o que
 * deixa a partida rápida: o motor resolve a noite inteira e para no seu jogo.
 *
 * ── O JOGO É ASSISTIDO, NÃO LIDO ─────────────────────────────────────
 * A primeira versão mostrava o placar final numa linha de tabela, e estava
 * certa e morta: "está muito feio, sem cara de jogo — o jogo tem que ser
 * simulado, o tempo passando ali e os pontos surgindo" (24/11/2026).
 *
 * Agora a partida é TRANSMITIDA. O motor simula de uma vez (dois décimos de
 * segundo) e guarda o lance a lance; a tela reproduz isso com o relógio
 * andando, o placar subindo e a narração entrando. Não é um "ao vivo" de
 * mentira: o resultado já existe e não muda se a aba fechar no meio — o que
 * a pessoa controla é a velocidade com que ela vê acontecer.
 *
 * ── O MOTOR É O DO SIMULADOR, A CASA É O /games ───────────────────────
 * Simulação por posses, classificação, play-in, playoffs e líderes vêm de
 * /simulador/src. Mas o jogo é daqui: tela própria, card no hub, save próprio
 * em games/storage/temporada. @see games/core/temporada.php
 */

require_once __DIR__ . '/../core/conexao.php';
require_once __DIR__ . '/../core/temporada.php';

$user = getUserSession();
if (!$user || (int) $user['id'] <= 0) {
    header('Location: /login.php?next=' . rawurlencode('/games/games/temporada.php'));
    exit;
}
$user_id = (int) $user['id'];
$erro = $_GET['e'] ?? null;

/* ── AS AÇÕES ────────────────────────────────────────────────────────
   Tudo POST e tudo termina em redirect: sem isso, o F5 depois de simular um
   jogo simularia outro, e a pessoa perderia partidas sem querer. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'comecar') {
        $r = temporadaCriar($user_id, (string) ($_POST['liga'] ?? ''),
                            (int) ($_POST['fba_team_id'] ?? 0), (string) ($_POST['gm_nome'] ?? ''));
        header('Location: /games/games/temporada.php' . ($r['ok'] ? ''
            : '?liga=' . urlencode((string) ($_POST['liga'] ?? '')) . '&e=' . urlencode($r['erro'])));
        exit;
    }

    if ($acao === 'apagar') { temporadaApagar($user_id); header('Location: /games/games/temporada.php'); exit; }

    if (temporadaAbrir($user_id)) {
        if ($acao === 'proximo') {
            $r = temporadaProximoJogo();
            // Volta direto na transmissão do jogo: é o que a pessoa clicou pra ver.
            header('Location: /games/games/temporada.php' . ($r['jogo'] ? '?jogo=' . (int) $r['jogo'] . '&vivo=1' : ''));
            exit;
        }
        if ($acao === 'varios') {
            temporadaSimularVarios((int) ($_POST['n'] ?? 5));
            header('Location: /games/games/temporada.php'); exit;
        }
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

$est    = $tem ? temporadaEstado() : null;
$jogoId = (int) ($_GET['jogo'] ?? 0);
$aoVivo = $tem && $jogoId && !empty($_GET['vivo']);
$trans  = $tem && $jogoId ? temporadaJogoAoVivo($jogoId) : null;
if (!$trans) { $aoVivo = false; $jogoId = 0; }

/** Cor válida ou a reserva. */
$cor = fn($c, $padrao = '#334155') => preg_match('/^#[0-9a-f]{6}$/i', trim((string) $c)) ? trim((string) $c) : $padrao;
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Minha Temporada · FBA</title>
<meta name="theme-color" content="#0a0e17">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Barlow+Condensed:wght@500;600;700&family=Oswald:wght@500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#070a11; --bg2:#0b0f19; --card:#111725; --card2:#182031; --linha:#242e42;
  --txt:#eaf0fb; --txt2:#95a2bb; --txt3:#5f6b85;
  --fba:#fc0025; --ok:#2bd97c; --perda:#f8615a; --ouro:#f5c842;
  --casa:#3b82f6; --fora:#64748b;
}
*{box-sizing:border-box}
html,body{margin:0;background:var(--bg);color:var(--txt)}
body{font-family:Inter,system-ui,-apple-system,sans-serif;-webkit-font-smoothing:antialiased;
     background-image:radial-gradient(1100px 520px at 50% -140px,#15203a 0%,transparent 70%)}
a{color:inherit;text-decoration:none}
button{font-family:inherit}
.cond{font-family:'Barlow Condensed',Inter,sans-serif;letter-spacing:.4px;text-transform:uppercase}
.placar-font{font-family:Oswald,'Barlow Condensed',sans-serif;font-variant-numeric:tabular-nums}

/* ───────── topbar ───────── */
.topbar{display:flex;align-items:center;gap:12px;padding:10px 16px;background:#070a11e8;
        backdrop-filter:blur(10px);border-bottom:1px solid var(--linha);position:sticky;top:0;z-index:40}
.topbar .voltar{width:36px;height:36px;display:grid;place-items:center;border-radius:11px;
                background:var(--card2);color:var(--txt2);flex:none}
.topbar .marca{font-family:'Barlow Condensed',sans-serif;font-size:23px;letter-spacing:.6px;
               text-transform:uppercase;flex:1;font-weight:700}
.topbar .marca em{color:var(--fba);font-style:normal}
.saldo{display:flex;align-items:center;gap:6px;background:#f5c84218;border:1px solid #f5c84240;
       padding:6px 12px;border-radius:99px;font-weight:800;font-size:14px;color:var(--ouro);flex:none}

.wrap{max-width:1080px;margin:0 auto;padding:16px 14px 70px}
.painel{background:var(--card);border:1px solid var(--linha);border-radius:18px;padding:16px;margin-bottom:14px}
.painel > h2{font-family:'Barlow Condensed',sans-serif;font-size:20px;text-transform:uppercase;
             letter-spacing:.7px;margin:0 0 13px;display:flex;align-items:center;gap:9px;font-weight:700}
.painel > h2 .sub{font-family:Inter,sans-serif;font-size:11.5px;font-weight:600;color:var(--txt3);
                  text-transform:none;letter-spacing:0;margin-left:auto;text-align:right}
.dim{color:var(--txt2);font-size:13px;line-height:1.6}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;cursor:pointer;
     background:var(--card2);color:var(--txt);padding:12px 17px;border-radius:12px;
     font-weight:700;font-size:14px;transition:.14s}
.btn:hover{background:#212c42;transform:translateY(-1px)}
.btn-fba{background:linear-gradient(135deg,#fc0025,#c4001d);color:#fff;
         box-shadow:0 6px 20px -6px #fc002580}
.btn-fba:hover{background:linear-gradient(135deg,#ff1a3c,#d40020)}
.btn-g{padding:16px 26px;font-size:16.5px;border-radius:14px}
.btn-fraco{background:transparent;border:1px solid var(--linha);color:var(--txt2)}
.btn:disabled{opacity:.45;cursor:default;transform:none}
.linha-btns{display:flex;gap:9px;flex-wrap:wrap}

/* ───────── hero do time ───────── */
.hero{position:relative;overflow:hidden;border:1px solid var(--linha);border-radius:20px;
      padding:20px;margin-bottom:14px;background:var(--card)}
.hero::before{content:'';position:absolute;inset:0;
      background:linear-gradient(105deg,var(--time) -25%,transparent 62%);opacity:.9}
.hero > *{position:relative}
.hero-topo{display:flex;gap:16px;align-items:center}
.hero-escudo{width:76px;height:76px;border-radius:16px;object-fit:cover;flex:none;background:#0006;
     display:grid;place-items:center;font-weight:900;font-size:23px;border:1px solid #ffffff20}
.hero .eyebrow{font-size:10.5px;text-transform:uppercase;letter-spacing:1.6px;color:#ffffffb8;font-weight:800}
.hero h1{margin:3px 0 5px;font-size:27px;line-height:1.08;font-weight:900;letter-spacing:-.4px}
.hero .campanha{font-size:13.5px;color:#ffffffd4;font-weight:600}
.hero-num{display:flex;gap:10px;margin-top:16px}
.hero-num div{flex:1;background:#00000045;border:1px solid #ffffff14;border-radius:13px;
              padding:10px;text-align:center}
.hero-num b{display:block;font-size:21px;font-weight:900;line-height:1.1}
.hero-num span{font-size:9.5px;text-transform:uppercase;letter-spacing:1.1px;color:#ffffffa8;font-weight:800}
@media(max-width:560px){.hero h1{font-size:23px}.hero-escudo{width:60px;height:60px}}

/* ───────── faixa de resultados ───────── */
.faixa{display:flex;gap:5px;flex-wrap:wrap;margin-top:14px}
.chip{width:28px;height:28px;border-radius:9px;display:grid;place-items:center;font-size:11.5px;
      font-weight:900;border:1px solid transparent}
.chip.v{background:#2bd97c20;color:var(--ok);border-color:#2bd97c40}
.chip.d{background:#f8615a1c;color:var(--perda);border-color:#f8615a38}

/* ───────── TRANSMISSÃO ───────── */
.tv{border-radius:20px;overflow:hidden;border:1px solid var(--linha);margin-bottom:14px;background:var(--card)}
.tv-placar{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:8px;
           padding:20px 14px 16px;position:relative;
           background:linear-gradient(100deg,var(--corA) -30%,#0b0f19 38%,#0b0f19 62%,var(--corB) 130%)}
.tv-time{display:flex;flex-direction:column;align-items:center;gap:7px;min-width:0}
.tv-time img,.tv-time .es{width:50px;height:50px;border-radius:13px;object-fit:cover;
     display:grid;place-items:center;font-weight:900;font-size:15px;background:#0007;border:1px solid #ffffff1f}
.tv-time .ab{font-family:'Barlow Condensed',sans-serif;font-size:17px;font-weight:700;letter-spacing:.6px}
.tv-time .pt{font-family:Oswald,sans-serif;font-size:52px;font-weight:600;line-height:.95;
     font-variant-numeric:tabular-nums;transition:transform .18s}
.tv-time .pt.pulou{transform:scale(1.22);color:var(--ouro)}
.tv-meio{text-align:center;padding:0 6px}
.tv-q{font-family:'Barlow Condensed',sans-serif;font-size:14px;letter-spacing:2px;color:var(--txt2);font-weight:700}
.tv-relogio{font-family:Oswald,sans-serif;font-size:27px;font-variant-numeric:tabular-nums;
            letter-spacing:1px;margin-top:2px}
.tv-barra{height:3px;background:#0b0f19}
.tv-barra i{display:block;height:100%;background:linear-gradient(90deg,var(--corA),var(--corB));
            width:0;transition:width .2s linear}
.tv-ctrl{display:flex;gap:8px;align-items:center;padding:11px 14px;background:var(--card2);
         border-top:1px solid var(--linha);flex-wrap:wrap}
.vel{display:flex;gap:4px;background:#0b0f19;border-radius:10px;padding:3px}
.vel button{background:transparent;border:0;color:var(--txt3);padding:6px 11px;border-radius:8px;
            font-size:12.5px;font-weight:800;cursor:pointer}
.vel button.on{background:var(--fba);color:#fff}
.tv-feed{padding:6px 0;max-height:330px;overflow:auto;background:var(--bg2)}
.lance{display:flex;gap:10px;align-items:flex-start;padding:8px 14px;font-size:13.3px;
       border-left:3px solid transparent;animation:entra .22s ease-out}
@keyframes entra{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.lance .rl{font-family:Oswald,sans-serif;font-size:11px;color:var(--txt3);min-width:46px;
           font-variant-numeric:tabular-nums;padding-top:1px}
.lance .tx{flex:1;min-width:0;color:var(--txt2);line-height:1.45}
.lance.cesta{background:#ffffff06}
.lance.cesta .tx{color:var(--txt);font-weight:600}
.lance.cesta3{background:#f5c84212;border-left-color:var(--ouro)}
.lance.cesta3 .tx{color:#fff;font-weight:700}
.lance.evento{background:#fc002510;border-left-color:var(--fba)}
.tv-fim{padding:18px 14px;text-align:center;border-top:1px solid var(--linha)}
.tv-fim .r{font-family:'Barlow Condensed',sans-serif;font-size:27px;font-weight:700;letter-spacing:1px}
.tv-fim .r.ganhou{color:var(--ok)} .tv-fim .r.perdeu{color:var(--perda)}

/* ───────── listas e tabelas ───────── */
table{width:100%;border-collapse:collapse;font-size:13.4px}
th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:1.1px;color:var(--txt3);
   font-weight:800;padding:0 7px 8px}
td{padding:7px;border-top:1px solid var(--linha)}
tr.eu td{background:#fc002512}
tr.eu td:first-child{box-shadow:inset 3px 0 0 var(--fba)}
.num2{text-align:right;font-variant-numeric:tabular-nums}
.duas{display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media(max-width:780px){.duas{grid-template-columns:1fr}}
.mini{width:22px;height:22px;border-radius:7px;object-fit:cover;vertical-align:-6px;margin-right:7px;
      display:inline-grid;place-items:center;font-size:9px;font-weight:900;background:#0007}

/* ───────── próximo jogo ───────── */
.duelo{display:flex;align-items:center;justify-content:center;gap:14px;padding:6px 0 18px}
.duelo .lado{display:flex;flex-direction:column;align-items:center;gap:7px;flex:1;min-width:0;text-align:center}
.duelo img,.duelo .es{width:56px;height:56px;border-radius:14px;object-fit:cover;
      display:grid;place-items:center;font-weight:900;font-size:16px;background:#0007}
.duelo b{font-size:13.5px;line-height:1.25}
.duelo small{color:var(--txt3);font-size:11.5px}
.duelo .x{font-family:'Barlow Condensed',sans-serif;font-size:22px;color:var(--txt3);flex:none}

/* ───────── corridas ───────── */
.corridas{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px}
.corrida b.rot{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:1.2px;
               color:var(--ouro);margin-bottom:8px;font-weight:800}
.corrida .l{display:flex;gap:8px;align-items:center;padding:4px 0;font-size:13px}
.corrida .l .p{width:14px;color:var(--txt3);font-weight:900;font-size:11px}
.corrida .l .nm{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.corrida .l .vl{color:var(--txt2);font-size:11.5px;font-weight:700;font-variant-numeric:tabular-nums}

/* ───────── chaveamento ───────── */
.rodada{margin-bottom:16px}
.rodada > b{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:1.3px;
            color:var(--txt3);margin-bottom:8px;font-weight:800}
.serie{display:flex;align-items:center;gap:9px;background:var(--card2);border:1px solid var(--linha);
       border-radius:12px;padding:9px 11px;margin-bottom:7px;font-size:13.3px}
.serie.eu{border-color:var(--fba);background:#fc00250e}
.serie .t{flex:1;min-width:0;display:flex;align-items:center;gap:7px}
.serie .t.out{color:var(--txt3)}
.serie img,.serie .es{width:24px;height:24px;border-radius:7px;object-fit:cover;flex:none;
      display:grid;place-items:center;font-size:9px;font-weight:900;background:#0007}
.serie .w{font-family:Oswald,sans-serif;font-size:17px;font-variant-numeric:tabular-nums;flex:none}
.serie .w.ganha{color:var(--ok)}
.serie .seed{color:var(--txt3);font-size:10.5px;font-weight:800;flex:none}

/* ───────── escolha do time ───────── */
.ligas{display:grid;grid-template-columns:repeat(auto-fit,minmax(168px,1fr));gap:10px}
.ligacard{display:block;background:var(--card2);border:1px solid var(--linha);border-radius:14px;padding:15px;transition:.14s}
.ligacard:hover{border-color:#3a4760;transform:translateY(-2px)}
.ligacard.on{border-color:var(--fba);background:#fc002510}
.ligacard b{font-family:'Barlow Condensed',sans-serif;font-size:22px;letter-spacing:.6px;font-weight:700}
.ligacard p{margin:4px 0 0;font-size:12.3px;color:var(--txt2);line-height:1.5}
.times{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:9px}
.timecard{position:relative;display:flex;gap:11px;align-items:center;background:var(--card2);
          border:1px solid var(--linha);border-radius:14px;padding:11px;cursor:pointer;transition:.13s}
.timecard:hover{border-color:#3a4760}
.timecard input{position:absolute;opacity:0;pointer-events:none}
.timecard:has(input:checked){border-color:var(--fba);background:#fc002510}
.timecard img,.timecard .es{width:44px;height:44px;border-radius:11px;object-fit:cover;flex:none;
          display:grid;place-items:center;font-weight:900;font-size:13px;color:#fff}
.timecard .n{flex:1;min-width:0}
/* OS FILHOS SÃO BLOCO DE PROPÓSITO: .n é <span> (precisa ser, pra caber dentro
   do <label>), e span é inline — altura de filho inline é ignorada. Sem isto a
   barra de força nascia com 0 de largura e sumia da tela. */
.timecard .n b{display:block;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.timecard .n small{display:block;color:var(--txt3);font-size:11.3px}
.barra{display:block;height:5px;border-radius:99px;background:#070a11;overflow:hidden;margin:6px 0 4px}
.barra i{display:block;height:100%}
.inp{background:#070a11;border:1px solid var(--linha);color:var(--txt);padding:12px 14px;
     border-radius:12px;font:inherit;width:230px;max-width:100%}

/* ───────── moedas ───────── */
.etapa{display:flex;align-items:center;gap:10px;padding:9px 0;border-top:1px solid var(--linha);font-size:13.4px}
.etapa:first-of-type{border-top:0}
.etapa.feita{color:var(--txt)} .etapa.feita i{color:var(--ok)}
.etapa.falta{color:var(--txt3)}
.etapa .v{margin-left:auto;font-weight:800;font-variant-numeric:tabular-nums}
.etapa.feita .v{color:var(--ouro)}
.aviso{border-radius:14px;padding:13px 15px;margin-bottom:14px;font-size:13.5px;line-height:1.6}
.aviso.ok{background:#f5c8421a;border:1px solid #f5c84250}
.aviso.err{background:#f8615a18;border:1px solid #f8615a50}
</style>
</head>
<body>

<header class="topbar">
  <a class="voltar" href="/games.php" title="Voltar aos games"><i class="bi bi-arrow-left"></i></a>
  <div class="marca">Minha <em>Temporada</em></div>
  <span class="saldo"><i class="bi bi-coin"></i> <?= number_format($moedas, 0, ',', '.') ?></span>
</header>

<div class="wrap">

<?php if ($erro): ?><div class="aviso err"><?= e($erro) ?></div><?php endif; ?>

<?php if ($premiosNovos): $tot = array_sum(array_column($premiosNovos, 'moedas')); ?>
  <div class="aviso ok">
    <b><i class="bi bi-coin"></i> +<?= number_format($tot, 0, ',', '.') ?> moedas</b><br>
    <?php foreach ($premiosNovos as $p): ?><?= e($p['rotulo']) ?> — +<?= (int) $p['moedas'] ?><br><?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!$tem): /* ═════════════ ESCOLHA DO TIME ═════════════ */ ?>

  <div class="painel" style="background:linear-gradient(135deg,#1a2338,#111725)">
    <h2><i class="bi bi-trophy-fill" style="color:var(--ouro)"></i> Minha Temporada</h2>
    <p class="dim">
      Pegue um time da FBA e leve ele até o título. Você <b>assiste a cada um dos 82 jogos</b> —
      relógio correndo, placar subindo, lance a lance — e acompanha o resto da liga pela tabela.
      No fim vêm o play-in, os playoffs e as corridas de MVP, Evolução, 6º Homem, Defensor e Novato.
    </p>
    <p class="dim" style="margin-bottom:0">
      Cada etapa vencida paga moeda, e paga <b>mais quanto pior for o elenco que você escolher</b>.
      Os elencos são os que estão no site agora.
    </p>
  </div>

  <div class="painel">
    <h2><i class="bi bi-diagram-3-fill"></i> 1. A liga</h2>
    <div class="ligas">
      <?php foreach ([
        'ELITE'  => 'A divisão de cima. 32 times, os melhores elencos.',
        'NEXT'   => '30 times, um degrau abaixo da ELITE.',
        'RISE'   => '30 times em formação.',
        'ROOKIE' => '30 times, a porta de entrada.',
      ] as $l => $txt): ?>
        <a class="ligacard<?= $l === $liga ? ' on' : '' ?>" href="?liga=<?= $l ?>"><b><?= $l ?></b><p><?= e($txt) ?></p></a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($liga):
    $times = TemporadaLiga::timesDaLiga($liga);
    $ovrs = array_map(fn($t) => (float) $t['ovr_medio'], $times);
    $piso = $ovrs ? min($ovrs) : 0; $topo = $ovrs ? max($ovrs) : 1;
  ?>
  <form method="post">
    <input type="hidden" name="acao" value="comecar">
    <input type="hidden" name="liga" value="<?= e($liga) ?>">
    <div class="painel">
      <h2><i class="bi bi-people-fill"></i> 2. O time <span class="sub">do elenco mais forte<br>pro mais fraco</span></h2>
      <div class="times">
        <?php foreach ($times as $i => $t):
          $nome = trim((string) $t['city'] . ' ' . (string) $t['name']);
          $media = (float) $t['ovr_medio'];
          $forca = $topo > $piso ? (int) round(($media - $piso) / ($topo - $piso) * 100) : 50;
          $c = $cor($t['cor']);
          $logo = trim((string) $t['photo_url']);
          [$recado, $corR] = $forca >= 80 ? ['Favorito — paga o mínimo', 'var(--txt3)']
                           : ($forca >= 50 ? ['Briga pelos playoffs', 'var(--txt3)']
                           : ($forca >= 25 ? ['Azarão — paga bem', 'var(--ouro)']
                           : ['Pior da liga — paga o máximo', 'var(--ok)']));
        ?>
        <label class="timecard">
          <input type="radio" name="fba_team_id" value="<?= (int) $t['id'] ?>" <?= $i === 0 ? 'required' : '' ?>>
          <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" loading="lazy" style="background:<?= e($c) ?>">
          <?php else: ?><span class="es" style="background:<?= e($c) ?>"><?= e(mb_strtoupper(mb_substr($nome, 0, 2))) ?></span><?php endif; ?>
          <span class="n">
            <b><?= e($nome) ?></b>
            <small>OVR <?= number_format($media, 1, ',', '.') ?> · teto <?= (int) $t['teto'] ?> · <?= strtoupper((string) $t['conference']) === 'LESTE' ? 'Leste' : 'Oeste' ?></small>
            <span class="barra"><i style="width:<?= max(4, $forca) ?>%;background:<?= e($c) ?>"></i></span>
            <small style="color:<?= $corR ?>"><?= e($recado) ?></small>
          </span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="painel">
      <h2><i class="bi bi-person-badge"></i> 3. Seu nome</h2>
      <p><input name="gm_nome" maxlength="40" placeholder="GM" class="inp"></p>
      <button class="btn btn-fba btn-g" type="submit"><i class="bi bi-play-fill"></i> Começar a temporada</button>
      <p class="dim" style="margin-bottom:0">Monta a liga inteira com os elencos de agora e o calendário de 82 jogos.</p>
    </div>
  </form>
  <?php endif; ?>

<?php elseif ($aoVivo): /* ═════════════ TRANSMISSÃO ═════════════ */
  $jg = $trans['jogo'];
  $tc = $trans['times'];
  $casaId = (int) $jg['home_id']; $foraId = (int) $jg['away_id'];
  $corCasa = $cor($tc[$casaId]['primary_color'] ?? '', '#3b82f6');
  $corFora = $cor($tc[$foraId]['primary_color'] ?? '', '#64748b');
  $euCasa  = $casaId === $est['gm_id'];
  $ganhei  = $euCasa ? (int) $jg['home_pts'] > (int) $jg['away_pts'] : (int) $jg['away_pts'] > (int) $jg['home_pts'];
  $etapa   = $jg['stage'] !== 'regular' ? strtoupper((string) $jg['stage']) : 'Temporada regular';
?>
  <div class="tv" style="--corA:<?= e($corFora) ?>;--corB:<?= e($corCasa) ?>">
    <div class="tv-placar">
      <?php foreach ([[$foraId, 'fora', $corFora], [null, null, null], [$casaId, 'casa', $corCasa]] as [$tid, $lado, $c]):
        if ($tid === null): ?>
          <div class="tv-meio">
            <div class="tv-q" id="tvQ">1º QUARTO</div>
            <div class="tv-relogio placar-font" id="tvRelogio">12:00</div>
          </div>
        <?php continue; endif;
        $t = $tc[$tid]; ?>
        <div class="tv-time">
          <?php if (!empty($t['logo_url'])): ?><img src="<?= e($t['logo_url']) ?>" alt="">
          <?php else: ?><span class="es" style="background:<?= e($c) ?>"><?= e($t['abbr']) ?></span><?php endif; ?>
          <span class="ab"><?= e($t['abbr']) ?></span>
          <span class="pt placar-font" id="pt<?= $lado ?>">0</span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="tv-barra"><i id="tvBarra"></i></div>

    <div class="tv-ctrl">
      <button class="btn" id="btPausar" style="padding:8px 14px"><i class="bi bi-pause-fill"></i> Pausar</button>
      <div class="vel">
        <button data-v="1">1x</button><button data-v="2" class="on">2x</button>
        <button data-v="4">4x</button><button data-v="10">10x</button>
      </div>
      <button class="btn btn-fraco" id="btPular" style="padding:8px 14px;margin-left:auto">Pular pro fim</button>
    </div>

    <div class="tv-feed" id="tvFeed"></div>

    <div class="tv-fim" id="tvFim" style="display:none">
      <div class="r <?= $ganhei ? 'ganhou' : 'perdeu' ?>"><?= $ganhei ? 'Vitória!' : 'Derrota' ?></div>
      <p class="dim"><?= e($etapa) ?><?= (int) $jg['ot'] > 0 ? ' · ' . (int) $jg['ot'] . ' prorrogação' . ((int) $jg['ot'] > 1 ? 'ões' : '') : '' ?></p>
      <div class="linha-btns" style="justify-content:center">
        <form method="post"><button class="btn btn-fba btn-g" name="acao" value="proximo"><i class="bi bi-fast-forward-fill"></i> Próximo jogo</button></form>
        <a class="btn" href="/games/games/temporada.php">Voltar à temporada</a>
      </div>
    </div>
  </div>

  <div class="painel" id="boxPainel" style="display:none">
    <h2><i class="bi bi-clipboard-data"></i> Box score</h2>
    <div class="duas">
      <?php
      $porTime = [];
      foreach ($trans['box'] as $b) $porTime[(int) $b['team_id']][] = $b;
      foreach ([$foraId, $casaId] as $tid): ?>
      <div>
        <table>
          <thead><tr><th><?= e(trim(($tc[$tid]['city'] ?? '') . ' ' . ($tc[$tid]['name'] ?? ''))) ?></th>
            <th class="num2">MIN</th><th class="num2">PTS</th><th class="num2">REB</th>
            <th class="num2">AST</th><th class="num2">FG</th></tr></thead>
          <tbody>
          <?php foreach ($porTime[$tid] ?? [] as $b): ?>
            <tr><td><?= e($b['name']) ?> <small style="color:var(--txt3)"><?= e($b['pos']) ?></small></td>
              <td class="num2"><?= (int) $b['min'] ?></td><td class="num2"><b><?= (int) $b['pts'] ?></b></td>
              <td class="num2"><?= (int) $b['reb'] ?></td><td class="num2"><?= (int) $b['ast'] ?></td>
              <td class="num2"><?= (int) $b['fgm'] ?>/<?= (int) $b['fga'] ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <script>
  /* ── A TRANSMISSÃO ──────────────────────────────────────────────────
     O jogo JÁ ACONTECEU: o motor simulou tudo e guardou o lance a lance.
     Aqui é reprodução — o relógio anda, o placar sobe e a narração entra no
     ritmo que a pessoa escolher. Fechar a aba no meio não muda o resultado.

     Cesta demora um pouco mais que rebote de propósito: é o lance que a
     pessoa veio ver, e passar reto por ele no mesmo tempo de um passe errado
     faz o jogo inteiro virar uma lista rolando. */
  const PBP   = <?= json_encode(array_values($trans['pbp']), JSON_UNESCAPED_UNICODE) ?>;
  const CASA  = <?= $casaId ?>;
  const Q_ROT = {Q1:'1º QUARTO', Q2:'2º QUARTO', Q3:'3º QUARTO', Q4:'4º QUARTO',
                 PR1:'PRORROGAÇÃO', PR2:'2ª PRORROGAÇÃO', PR3:'3ª PRORROGAÇÃO', FIM:'FIM'};

  const $ = id => document.getElementById(id);
  const feed = $('tvFeed');
  let i = 0, vel = 2, rodando = true, timer = null;

  function pinta(ev) {
    $('tvQ').textContent = Q_ROT[ev.q] || ev.q || '';
    if (ev.clock) $('tvRelogio').textContent = ev.clock;
    for (const [el, v] of [[$('ptcasa'), ev.home_pts], [$('ptfora'), ev.away_pts]]) {
      const antes = +el.textContent;
      if (antes !== v) {
        el.textContent = v;
        el.classList.add('pulou');
        setTimeout(() => el.classList.remove('pulou'), 190);
      }
    }
    const cesta = ev.t === 'made' || ev.t === 'ft';
    const tres  = ev.t === 'made' && ev.pts === 3;
    const div = document.createElement('div');
    div.className = 'lance' + (tres ? ' cesta3' : cesta ? ' cesta' : '')
                  + (ev.t === 'injury' || ev.t === 'timeout' ? ' evento' : '');
    div.innerHTML = '<span class="rl">' + (ev.clock || '') + '</span><span class="tx"></span>';
    div.querySelector('.tx').textContent = ev.text || '';
    feed.prepend(div);
    // O DOM não precisa guardar o jogo inteiro: 40 lances já é mais do que
    // alguém rola pra trás, e 500 nós deixam a animação travada no celular.
    while (feed.childElementCount > 40) feed.lastElementChild.remove();
    $('tvBarra').style.width = Math.round((i / PBP.length) * 100) + '%';
  }

  function passo() {
    if (i >= PBP.length) return fim();
    const ev = PBP[i++];
    pinta(ev);
    const base = (ev.t === 'made' || ev.t === 'ft') ? 320 : 170;
    if (rodando) timer = setTimeout(passo, Math.max(16, base / vel));
  }

  function fim() {
    rodando = false;
    clearTimeout(timer);
    const u = PBP[PBP.length - 1];
    if (u) { $('ptcasa').textContent = u.home_pts; $('ptfora').textContent = u.away_pts; }
    $('tvQ').textContent = 'FIM DE JOGO';
    $('tvRelogio').textContent = '0:00';
    $('tvBarra').style.width = '100%';
    $('tvFim').style.display = 'block';
    $('boxPainel').style.display = 'block';
    $('btPausar').disabled = true;
    $('btPular').disabled = true;
  }

  $('btPausar').onclick = () => {
    rodando = !rodando;
    $('btPausar').innerHTML = rodando
      ? '<i class="bi bi-pause-fill"></i> Pausar' : '<i class="bi bi-play-fill"></i> Continuar';
    if (rodando) passo();
    else clearTimeout(timer);
  };
  $('btPular').onclick = () => {
    // Mostra os últimos lances pra não terminar com o feed vazio.
    clearTimeout(timer);
    for (let k = Math.max(i, PBP.length - 12); k < PBP.length; k++) { i = k + 1; pinta(PBP[k]); }
    fim();
  };
  document.querySelectorAll('.vel button').forEach(b => b.onclick = () => {
    document.querySelectorAll('.vel button').forEach(x => x.classList.remove('on'));
    b.classList.add('on');
    vel = +b.dataset.v;
  });

  if (PBP.length) passo(); else fim();
  </script>

<?php else: /* ═════════════ A TEMPORADA ═════════════ */
  $meu = $est['time'];
  $corTime = $cor($meu['primary_color'] ?? '', '#3b82f6');
  $fase = $est['fase'];
  $faseRot = ['regular' => 'Temporada regular', 'playin' => 'Play-in',
              'playoffs' => 'Playoffs', 'offseason' => 'Temporada encerrada'][$fase] ?? $fase;
  $conq = TemporadaPremios::conquistadas();
  $pagas = TemporadaPremios::pagas();
  $meusJogos = temporadaMeusJogos($est['gm_id'], 14);
?>

  <div class="hero" style="--time:<?= e($corTime) ?>">
    <div class="hero-topo">
      <?php if (!empty($meu['logo_url'])): ?><img class="hero-escudo" src="<?= e($meu['logo_url']) ?>" alt="">
      <?php else: ?><span class="hero-escudo" style="background:<?= e($corTime) ?>"><?= e($meu['abbr']) ?></span><?php endif; ?>
      <div style="flex:1;min-width:0">
        <div class="eyebrow"><?= e($est['liga']) ?> · <?= e($faseRot) ?></div>
        <h1><?= e(temporadaNome($meu)) ?></h1>
        <div class="campanha">
          <?= (int) $meu['wins'] ?>–<?= (int) $meu['losses'] ?>
          <?php if ($est['posicao']): ?> · <?= $est['posicao'] ?>º no <?= $meu['conf'] === 'E' ? 'Leste' : 'Oeste' ?><?php endif; ?>
          · GM <?= e($est['gm_nome']) ?>
        </div>
      </div>
    </div>
    <div class="hero-num">
      <div><b><?= $est['jogados'] ?>/82</b><span>jogos</span></div>
      <div><b><?= $est['forca'] ?></b><span>força</span></div>
      <div><b><?= $est['rank'] ?>º</b><span>de <?= $est['times'] ?></span></div>
      <div><b style="color:var(--ouro)"><?= TemporadaPremios::multiplicador() ?>x</b><span>moedas</span></div>
    </div>
    <?php if ($meusJogos): ?>
    <div class="faixa">
      <?php foreach (array_reverse($meusJogos) as $g):
        $casa = (int) $g['home_id'] === $est['gm_id'];
        $v = (int) ($casa ? $g['home_pts'] : $g['away_pts']) > (int) ($casa ? $g['away_pts'] : $g['home_pts']);
      ?><a class="chip <?= $v ? 'v' : 'd' ?>" href="?jogo=<?= (int) $g['id'] ?>&vivo=1"
           title="<?= $v ? 'Vitória' : 'Derrota' ?> — rever"><?= $v ? 'V' : 'D' ?></a><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($fase !== 'offseason'): $prox = temporadaProximoAdversario($est['gm_id']); ?>
  <div class="painel">
    <h2><i class="bi bi-broadcast"></i> Próximo jogo</h2>
    <?php if ($prox):
      $euEmCasa = (int) $prox['home_id'] === $est['gm_id'];
      $pre = $euEmCasa ? 'away_' : 'home_';
      $advCor = $cor($prox[$pre . 'cor']);
    ?>
      <div class="duelo">
        <div class="lado">
          <?php if (!empty($meu['logo_url'])): ?><img src="<?= e($meu['logo_url']) ?>" alt="">
          <?php else: ?><span class="es" style="background:<?= e($corTime) ?>"><?= e($meu['abbr']) ?></span><?php endif; ?>
          <b><?= e(temporadaNome($meu)) ?></b>
          <small><?= (int) $meu['wins'] ?>–<?= (int) $meu['losses'] ?> · <?= $euEmCasa ? 'em casa' : 'fora' ?></small>
        </div>
        <span class="x">×</span>
        <div class="lado">
          <?php if (!empty($prox[$pre . 'logo'])): ?><img src="<?= e($prox[$pre . 'logo']) ?>" alt="">
          <?php else: ?><span class="es" style="background:<?= e($advCor) ?>"><?= e($prox[$pre . 'abbr']) ?></span><?php endif; ?>
          <b><?= e(temporadaNome($prox, $pre)) ?></b>
          <small><?= (int) $prox[$pre . 'v'] ?>–<?= (int) $prox[$pre . 'd'] ?></small>
        </div>
      </div>
      <form method="post" style="text-align:center">
        <button class="btn btn-fba btn-g" name="acao" value="proximo" style="width:100%;max-width:380px">
          <i class="bi bi-play-circle-fill"></i> Assistir ao jogo
        </button>
      </form>
      <div class="linha-btns" style="justify-content:center;margin-top:10px">
        <form method="post"><input type="hidden" name="n" value="5">
          <button class="btn btn-fraco" name="acao" value="varios">Pular 5 jogos</button></form>
        <form method="post"><input type="hidden" name="n" value="82">
          <button class="btn btn-fraco" name="acao" value="varios">Pular até o fim</button></form>
      </div>
      <p class="dim" style="text-align:center;margin:10px 0 0;font-size:12px">
        Pular simula sem transmitir — o resultado é o mesmo, você só não vê acontecer.
      </p>
    <?php else: ?>
      <p class="dim">Sem jogo marcado pro seu time agora.</p>
      <form method="post"><input type="hidden" name="n" value="82">
        <button class="btn btn-fba" name="acao" value="varios"><i class="bi bi-fast-forward-fill"></i> Avançar</button></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php /* ── CHAVEAMENTO NO LUGAR DA TABELA ───────────────────────────
           Quando a temporada regular acaba, a classificação deixa de ser a
           pergunta: o que importa é quem joga contra quem e quantas faltam.
           A tabela some e o chaveamento ocupa o lugar dela. */
  $chave = in_array($fase, ['playin', 'playoffs', 'offseason'], true)
         ? temporadaChaveamento($est['gm_id']) : null;
  if ($chave && ($chave['playin'] || $chave['rodadas'])): ?>
  <div class="painel">
    <h2><i class="bi bi-trophy-fill" style="color:var(--ouro)"></i> Playoffs</h2>
    <?php if ($chave['playin']): ?>
      <div class="rodada"><b>Play-in</b>
      <?php foreach ($chave['playin'] as $g):
        $eu = in_array($est['gm_id'], [(int) $g['home_id'], (int) $g['away_id']], true);
        $casaGanhou = (int) $g['home_pts'] > (int) $g['away_pts']; ?>
        <div class="serie <?= $eu ? 'eu' : '' ?>">
          <span class="t <?= $g['played'] && $casaGanhou ? '' : ($g['played'] ? 'out' : '') ?>">
            <?php if ($g['alogo']): ?><img src="<?= e($g['alogo']) ?>" alt=""><?php else: ?><span class="es" style="background:<?= e($cor($g['acor'])) ?>"><?= e($g['aa']) ?></span><?php endif; ?>
            <?= e(trim($g['ac'] . ' ' . $g['an'])) ?></span>
          <span class="w"><?= $g['played'] ? (int) $g['away_pts'] . '–' . (int) $g['home_pts'] : 'a jogar' ?></span>
          <span class="t" style="justify-content:flex-end">
            <?= e(trim($g['hc'] . ' ' . $g['hn'])) ?>
            <?php if ($g['hlogo']): ?><img src="<?= e($g['hlogo']) ?>" alt=""><?php else: ?><span class="es" style="background:<?= e($cor($g['hcor'])) ?>"><?= e($g['ha']) ?></span><?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php $rotR = [1 => 'Primeira rodada', 2 => 'Semifinais de conferência',
                   3 => 'Finais de conferência', 4 => 'Final da FBA'];
    foreach ($chave['rodadas'] as $r => $lista): ?>
      <div class="rodada"><b><?= e($rotR[$r] ?? ('Rodada ' . $r)) ?></b>
      <?php foreach ($lista as $s):
        $vH = (int) $s['winner_id'] === (int) $s['high_seed_id'];
        $vL = (int) $s['winner_id'] === (int) $s['low_seed_id']; ?>
        <div class="serie <?= $s['eu'] ? 'eu' : '' ?>">
          <span class="t <?= $vL ? 'out' : '' ?>">
            <span class="seed"><?= (int) $s['high_seed'] ?></span>
            <?php if ($s['hlogo']): ?><img src="<?= e($s['hlogo']) ?>" alt=""><?php else: ?><span class="es" style="background:<?= e($cor($s['hcor'])) ?>"><?= e($s['ha']) ?></span><?php endif; ?>
            <?= e(trim($s['hc'] . ' ' . $s['hn'])) ?></span>
          <span class="w <?= $vH ? 'ganha' : '' ?>"><?= (int) $s['high_wins'] ?></span>
          <span style="color:var(--txt3)">–</span>
          <span class="w <?= $vL ? 'ganha' : '' ?>"><?= (int) $s['low_wins'] ?></span>
          <span class="t <?= $vH ? 'out' : '' ?>" style="justify-content:flex-end">
            <?= e(trim($s['ac'] . ' ' . $s['an'])) ?>
            <?php if ($s['alogo']): ?><img src="<?= e($s['alogo']) ?>" alt=""><?php else: ?><span class="es" style="background:<?= e($cor($s['acor'])) ?>"><?= e($s['aa']) ?></span><?php endif; ?>
            <span class="seed"><?= (int) $s['low_seed'] ?></span></span>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php else: /* temporada regular: a tabela */ ?>
  <div class="painel">
    <h2><i class="bi bi-table"></i> Classificação</h2>
    <div class="duas">
      <?php foreach (['E' => 'Leste', 'W' => 'Oeste'] as $cf => $rot): ?>
      <div>
        <table>
          <thead><tr><th><?= $rot ?></th><th class="num2">V</th><th class="num2">D</th><th class="num2">%</th></tr></thead>
          <tbody>
          <?php foreach (array_slice(League::standings($cf), 0, 12) as $i2 => $t):
            $meuT = (int) $t['id'] === $est['gm_id']; ?>
            <tr class="<?= $meuT ? 'eu' : '' ?>">
              <td><span style="color:var(--txt3);font-weight:800"><?= $i2 + 1 ?></span>
                <?php if (!empty($t['logo_url'])): ?><img class="mini" src="<?= e($t['logo_url']) ?>" alt="" loading="lazy">
                <?php else: ?><span class="mini" style="background:<?= e($cor($t['primary_color'])) ?>"><?= e(mb_substr($t['abbr'], 0, 2)) ?></span><?php endif; ?>
                <?= e(temporadaNome($t)) ?></td>
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
  <?php endif; ?>

  <?php $race = $est['jogados'] >= 5 ? League::awardRace() : null; if ($race): ?>
  <div class="painel">
    <h2><i class="bi bi-award-fill" style="color:var(--ouro)"></i> Corrida pelos prêmios <span class="sub">pts · reb · ast</span></h2>
    <div class="corridas">
      <?php
      $pra = fn($p) => number_format((float) $p['ppg'], 1) . ' · ' . number_format((float) $p['rpg'], 1)
                     . ' · ' . number_format((float) $p['apg'], 1);
      foreach ([
        'mvp'   => ['MVP',      $pra],
        'mip'   => ['Evolução', fn($p) => '+' . number_format((float) $p['score'], 1) . ' s/ o OVR'],
        'sexto' => ['6º Homem', $pra],
        'dpoy'  => ['Defensor', fn($p) => number_format((float) $p['bpg'], 1) . ' toc · ' . number_format((float) $p['spg'], 1) . ' rb'],
        'roy'   => ['Novato',   $pra],
      ] as $k => [$rot, $val]): ?>
        <div class="corrida"><b class="rot"><?= e($rot) ?></b>
          <?php if (!empty($race[$k])): foreach (array_slice($race[$k], 0, 3) as $i2 => $p): ?>
            <div class="l"><span class="p"><?= $i2 + 1 ?></span>
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
    <h2><i class="bi bi-coin" style="color:var(--ouro)"></i> Moedas da temporada
      <span class="sub"><?= $est['rank'] ?>º elenco de <?= $est['times'] ?><br><?= TemporadaPremios::multiplicador() ?>x o valor base</span></h2>
    <?php foreach (TemporadaPremios::tabela() as $k => $i2):
      $feita = !empty($conq[$i2['rodada']]); $pago = in_array($k, $pagas, true); ?>
      <div class="etapa <?= $feita ? 'feita' : 'falta' ?>">
        <i class="bi bi-<?= $feita ? 'check-circle-fill' : 'circle' ?>"></i>
        <span><?= e($i2['rotulo']) ?></span>
        <span class="v"><?= number_format($i2['moedas'], 0, ',', '.') ?><?= $pago ? ' ✓' : '' ?></span>
      </div>
    <?php endforeach; ?>
    <p class="dim" style="margin-bottom:0">
      Quanto mais fraco o elenco, mais cada etapa paga. O seu tem força <?= $est['forca'] ?>/100.
    </p>
  </div>

  <div class="painel">
    <h2><i class="bi bi-arrow-counterclockwise"></i> Recomeçar</h2>
    <p class="dim">Apagar esta temporada e escolher outro time. O que já foi pago em moeda fica —
      mas as etapas que faltavam somem junto.</p>
    <form method="post" onsubmit="return confirm('Apagar esta temporada e começar outra? Não dá pra desfazer.')">
      <button class="btn btn-fraco" name="acao" value="apagar"><i class="bi bi-trash3"></i> Apagar e escolher outro time</button>
    </form>
  </div>

<?php endif; ?>
</div>
</body>
</html>
