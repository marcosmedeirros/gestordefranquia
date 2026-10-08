<?php
/**
 * FBA DRAFT — o FUT Draft da liga.
 *
 * Escolhe a formação, monta onze cartas escolhendo uma entre cinco por vaga,
 * e joga uma partida narrada minuto a minuto valendo moedas. Contra a
 * máquina ou contra o time de outro GM.
 *
 * ── AINDA NÃO ESTÁ NO HUB, E ISSO É DE PROPÓSITO ─────────────────────
 *
 * Pedido do Marcos em 07/10/2026: "não coloca lá ainda, somente por link".
 * Então não existe card em games/index.clean.php — quem tem o endereço
 * entra, o resto da liga não vê. Pra publicar depois, é só acrescentar o
 * card; nada aqui depende disso.
 *
 * ── O DRAFT MORA NA SESSÃO, O TIME MORA NO BANCO ─────────────────────
 *
 * Enquanto a pessoa escolhe, o estado é da sessão: são onze passos num
 * minuto, e gravar cada clique no banco seria onze escritas pra uma coisa
 * que pode ser abandonada no meio. Quando o time fica pronto, aí sim ele é
 * gravado — porque a partir daí ele enfrenta gente, aparece pros outros e
 * precisa sobreviver ao navegador fechar.
 *
 * @see games/core/draftfut.php           formações, cartas e química
 * @see games/core/draftfut_partida.php   a partida minuto a minuto
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../core/conexao.php';
require_once __DIR__ . '/../core/draftfut_partida.php';

if (!isset($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$user_id = (int)$_SESSION['user_id'];

/* ── O QUE CUSTA E O QUE PAGA ────────────────────────────────────────
   A entrada existe pra a escolha ter peso: draft de graça vira reroll até
   sair o time perfeito. O prêmio por vitória cobre a entrada com folga, e
   o empate devolve quase tudo — perder um draft bem montado num 1x1 não
   pode doer mais que não ter jogado. */
const DF_ENTRADA = 50;
const DF_VITORIA = 150;
const DF_EMPATE  = 40;

function dfTabelas(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS draftfut_times (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        nome VARCHAR(60) NOT NULL,
        formacao VARCHAR(12) NOT NULL,
        time_json TEXT NOT NULL,
        forca INT NOT NULL,
        quimica INT NOT NULL,
        vitorias INT NOT NULL DEFAULT 0,
        empates INT NOT NULL DEFAULT 0,
        derrotas INT NOT NULL DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_df_user (id_usuario),
        INDEX idx_df_forca (forca)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS draftfut_partidas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        time_casa INT NOT NULL,
        time_fora INT NULL,
        fora_json TEXT NULL,
        semente INT NOT NULL,
        gols_casa INT NOT NULL,
        gols_fora INT NOT NULL,
        moedas INT NOT NULL DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dfp_casa (time_casa)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
dfTabelas($pdo);

/** O saldo de moedas de quem está jogando. */
function dfMoedas(PDO $pdo, int $uid): int
{
    $st = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ?');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

/** Mexe no saldo. Negativo cobra, positivo paga. Nunca deixa abaixo de zero. */
function dfMoedasMexer(PDO $pdo, int $uid, int $delta): void
{
    $pdo->prepare('UPDATE games_usuarios SET pontos = GREATEST(0, pontos + ?) WHERE id = ?')
        ->execute([$delta, $uid]);
}

$msg = null; $erro = null;
$d = &$_SESSION['draftfut'];

/* ═══════════════════════════ AÇÕES ══════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'comecar') {
        $formacao = (string)($_POST['formacao'] ?? '');
        if (!isset(DFUT_FORMACOES[$formacao])) {
            $erro = 'Formação inválida.';
        } elseif (dfMoedas($pdo, $user_id) < DF_ENTRADA) {
            $erro = 'Você precisa de ' . DF_ENTRADA . ' moedas pra entrar no draft.';
        } else {
            dfMoedasMexer($pdo, $user_id, -DF_ENTRADA);
            $d = ['formacao' => $formacao, 'vaga' => 0, 'time' => [], 'usados' => [],
                  'opcoes' => null, 'resultado' => null];
            $msg = 'Boa sorte! Entrada de ' . DF_ENTRADA . ' moedas paga.';
        }
    }

    if ($acao === 'escolher' && $d && $d['opcoes']) {
        $i = (int)($_POST['carta'] ?? -1);
        if (isset($d['opcoes'][$i])) {
            $c = $d['opcoes'][$i];
            $d['time'][$d['vaga']] = $c;
            $d['usados'][] = $c['nome'];
            $d['vaga']++;
            $d['opcoes'] = null;          // a próxima vaga sorteia na hora de desenhar
        }
    }

    if ($acao === 'jogar' && $d && $d['vaga'] >= DFUT_VAGAS) {
        $formacao = $d['formacao'];
        $time = $d['time'];
        $forca = draftFutForcaDoTime($formacao, $time);
        $quim  = draftFutQuimica($formacao, $time)['total'];

        $nome = trim((string)($_POST['nome'] ?? '')) ?: 'Time de ' . $user_id;
        $pdo->prepare('INSERT INTO draftfut_times (id_usuario, nome, formacao, time_json, forca, quimica)
                       VALUES (?,?,?,?,?,?)')
            ->execute([$user_id, mb_substr($nome, 0, 60), $formacao,
                       json_encode($time, JSON_UNESCAPED_UNICODE), $forca, $quim]);
        $meuId = (int)$pdo->lastInsertId();

        /* O ADVERSÁRIO: o time de outro GM quando existe algum de força
           parecida, senão a máquina. Assim o PvP acontece sem ninguém
           precisar estar online — o time do outro já está salvo. */
        $modo = (string)($_POST['modo'] ?? 'maquina');
        $adv = null; $advId = null;
        if ($modo === 'pvp') {
            $st = $pdo->prepare('SELECT * FROM draftfut_times
                                  WHERE id_usuario <> ? AND ABS(forca - ?) <= 6
                               ORDER BY RAND() LIMIT 1');
            $st->execute([$user_id, $forca]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $advId = (int)$r['id'];
                $adv = ['nome' => $r['nome'], 'formacao' => $r['formacao'],
                        'time' => json_decode($r['time_json'], true), 'forca' => (int)$r['forca']];
            }
        }
        $semente = random_int(1, 2000000000);
        if (!$adv) {
            $adv = draftFutAdversarioDaMaquina($forca, $semente);
            $modo = 'maquina';
        }

        $p = draftFutPartida(['nome' => $nome, 'formacao' => $formacao, 'time' => $time, 'forca' => $forca],
                             $adv, $semente);

        $premio = $p['placar'][0] > $p['placar'][1] ? DF_VITORIA
                : ($p['placar'][0] === $p['placar'][1] ? DF_EMPATE : 0);
        if ($premio) dfMoedasMexer($pdo, $user_id, $premio);

        $col = $p['placar'][0] > $p['placar'][1] ? 'vitorias'
             : ($p['placar'][0] === $p['placar'][1] ? 'empates' : 'derrotas');
        $pdo->prepare("UPDATE draftfut_times SET $col = $col + 1 WHERE id = ?")->execute([$meuId]);
        if ($advId) {
            $inv = $col === 'vitorias' ? 'derrotas' : ($col === 'derrotas' ? 'vitorias' : 'empates');
            $pdo->prepare("UPDATE draftfut_times SET $inv = $inv + 1 WHERE id = ?")->execute([$advId]);
        }

        $pdo->prepare('INSERT INTO draftfut_partidas (time_casa, time_fora, fora_json, semente, gols_casa, gols_fora, moedas)
                       VALUES (?,?,?,?,?,?,?)')
            ->execute([$meuId, $advId, $advId ? null : json_encode($adv, JSON_UNESCAPED_UNICODE),
                       $semente, $p['placar'][0], $p['placar'][1], $premio]);

        $d['resultado'] = ['partida' => $p, 'adv' => $adv, 'modo' => $modo,
                           'premio' => $premio, 'forca' => $forca, 'quimica' => $quim, 'nome' => $nome];
    }

    if ($acao === 'novo') { $d = null; }

    header('Location: /games/games/draftfut.php' . ($msg ? '?m=' . urlencode($msg) : ($erro ? '?e=' . urlencode($erro) : '')));
    exit;
}

$msg = $_GET['m'] ?? null;
$erro = $_GET['e'] ?? null;

/* Sorteia as opções da vaga atual, se o draft está em andamento. */
if ($d && $d['vaga'] < DFUT_VAGAS && !$d['opcoes']) {
    [$lo, $hi] = draftFutFaixa($d['vaga']);
    $vaga = DFUT_FORMACOES[$d['formacao']][$d['vaga']];
    $d['opcoes'] = draftFutOpcoes($vaga[1], $d['usados'], $lo, $hi);
}

$moedas = dfMoedas($pdo, $user_id);
$emDraft = $d && $d['vaga'] < DFUT_VAGAS;
$pronto  = $d && $d['vaga'] >= DFUT_VAGAS && empty($d['resultado']);
$fim     = $d && !empty($d['resultado']);
$quimParcial = $d ? draftFutQuimica($d['formacao'], $d['time']) : ['total' => 0, 'jogadores' => []];

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FBA Draft</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Oswald:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
:root{
  --bg:#07070a; --panel:#101013; --panel2:#16161a; --panel3:#1c1c21;
  --borda:rgba(255,255,255,.08); --borda2:rgba(255,255,255,.14);
  --txt:#f0f0f3; --txt2:#9a9aa4; --txt3:#70707a;
  --verde:#22c55e; --amarelo:#f5c518; --vermelho:#fc0025; --azul:#3b82f6;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font-family:'Montserrat',sans-serif;padding-bottom:50px}
a{color:inherit}
.topo{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
  padding:14px 18px;border-bottom:1px solid var(--borda);background:var(--panel)}
.topo h1{margin:0;font-family:'Oswald',sans-serif;font-size:22px;letter-spacing:.5px}
.topo h1 span{color:var(--vermelho)}
.saldo{background:var(--panel3);border:1px solid var(--borda2);border-radius:999px;
  padding:6px 14px;font-weight:700;font-size:13px}
.saldo i{color:var(--amarelo)}
.wrap{max-width:1040px;margin:0 auto;padding:18px}
.aviso{padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:13.5px;font-weight:600}
.aviso.ok{background:rgba(34,197,94,.12);color:var(--verde);border:1px solid rgba(34,197,94,.3)}
.aviso.err{background:rgba(252,0,37,.12);color:#ff5c7a;border:1px solid rgba(252,0,37,.3)}
.bloco{background:var(--panel);border:1px solid var(--borda);border-radius:14px;padding:18px;margin-bottom:16px}
.bloco h2{margin:0 0 4px;font-family:'Oswald',sans-serif;font-size:19px;letter-spacing:.4px}
.bloco p.sub{margin:0 0 14px;color:var(--txt2);font-size:13.5px}
.btn{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--borda2);background:var(--panel3);
  color:var(--txt);font:inherit;font-weight:700;font-size:13px;border-radius:10px;padding:10px 16px;cursor:pointer}
.btn:hover{border-color:var(--vermelho)}
.btn.pri{background:var(--vermelho);border-color:var(--vermelho);color:#fff}
.btn:disabled{opacity:.45;cursor:not-allowed}

/* Formações */
.forms{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.form-card{background:var(--panel2);border:1px solid var(--borda);border-radius:12px;padding:14px;
  text-align:center;cursor:pointer;font:inherit;color:var(--txt)}
.form-card:hover{border-color:var(--vermelho)}
.form-card b{display:block;font-family:'Oswald',sans-serif;font-size:21px;letter-spacing:1px}
.form-card small{color:var(--txt3);font-size:11.5px}

/* Cartas */
.cartas{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.carta{background:linear-gradient(160deg,#2a2a33,#15151a);border:1px solid var(--borda2);
  border-radius:14px;padding:14px 12px;text-align:center;cursor:pointer;font:inherit;color:var(--txt);
  display:flex;flex-direction:column;align-items:center;gap:6px;transition:transform .12s}
.carta:hover{transform:translateY(-4px);border-color:var(--amarelo)}
.carta .ovr{font-family:'Oswald',sans-serif;font-size:30px;line-height:1;color:var(--amarelo)}
.carta .pos{font-size:10.5px;font-weight:800;letter-spacing:1px;color:var(--txt2)}
.carta img{width:34px;height:34px;object-fit:contain}
.carta .nm{font-weight:700;font-size:13px;line-height:1.2}
.carta .cl{font-size:11px;color:var(--txt3)}
.carta .lg{font-size:10px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px}

/* Escalação */
.escal{display:flex;flex-direction:column;gap:6px;margin-top:4px}
.esc-l{display:grid;grid-template-columns:44px 1fr auto auto;gap:10px;align-items:center;
  background:var(--panel2);border:1px solid var(--borda);border-radius:9px;padding:7px 11px;font-size:13px}
.esc-l .rot{font-size:10px;font-weight:800;color:var(--txt3);letter-spacing:.5px}
.esc-l .nm{font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.esc-l .nm small{color:var(--txt3);font-weight:400}
.esc-l .ov{font-family:'Oswald',sans-serif;font-size:16px;color:var(--amarelo)}
.esc-l .qm{font-size:11px;font-weight:700;padding:2px 7px;border-radius:999px}
.qm.alta{background:rgba(34,197,94,.15);color:var(--verde)}
.qm.media{background:rgba(245,197,24,.15);color:var(--amarelo)}
.qm.baixa{background:rgba(252,0,37,.15);color:#ff5c7a}
.esc-l.vazia{opacity:.35}

.nums{display:flex;gap:18px;flex-wrap:wrap;margin:12px 0}
.num{background:var(--panel3);border:1px solid var(--borda);border-radius:10px;padding:9px 16px;text-align:center}
.num b{display:block;font-family:'Oswald',sans-serif;font-size:24px;line-height:1}
.num small{color:var(--txt3);font-size:11px;text-transform:uppercase;letter-spacing:.5px}

/* Partida */
.placar{display:flex;align-items:center;justify-content:center;gap:18px;margin:6px 0 16px;flex-wrap:wrap}
.placar .t{text-align:center;min-width:130px}
.placar .t b{display:block;font-size:14px}
.placar .t small{color:var(--txt3);font-size:11px}
.placar .g{font-family:'Oswald',sans-serif;font-size:46px;line-height:1}
.narra{display:flex;flex-direction:column;gap:7px;max-height:420px;overflow-y:auto;padding-right:4px}
.lance{display:grid;grid-template-columns:40px 1fr;gap:10px;align-items:start;font-size:13.5px;
  padding:7px 10px;border-radius:8px;background:var(--panel2);border-left:3px solid var(--borda2);
  opacity:0;transform:translateY(6px);animation:ent .25s forwards}
@keyframes ent{to{opacity:1;transform:none}}
.lance .m{font-family:'Oswald',sans-serif;color:var(--txt3);font-size:13px}
.lance.gol{border-left-color:var(--verde);background:rgba(34,197,94,.1);font-weight:700}
.lance.defesa{border-left-color:var(--azul)}
.lance.perdeu{border-left-color:var(--amarelo)}
.lance.fim{border-left-color:var(--vermelho);font-weight:700}
.lance .pl{font-family:'Oswald',sans-serif;color:var(--verde);margin-left:6px}
@media (max-width:620px){
  .wrap{padding:12px}
  .esc-l{grid-template-columns:38px 1fr auto auto;gap:7px;font-size:12px}
  .placar .g{font-size:36px}
}
</style>
</head>
<body>

<div class="topo">
  <h1>FBA <span>Draft</span></h1>
  <div style="display:flex;gap:10px;align-items:center">
    <span class="saldo"><i class="bi bi-coin"></i> <?= number_format($moedas, 0, ',', '.') ?></span>
    <a class="btn" href="/games/"><i class="bi bi-arrow-left"></i> Games</a>
  </div>
</div>

<div class="wrap">
<?php if ($msg): ?><div class="aviso ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="aviso err"><?= e($erro) ?></div><?php endif; ?>

<?php if (!$d): ?>
  <?php /* ── 1. ESCOLHER A FORMAÇÃO ─────────────────────────────── */ ?>
  <div class="bloco">
    <h2>Escolha a formação</h2>
    <p class="sub">Depois de começar você não troca mais — e as cinco cartas de cada vaga
       dependem dela. Entrada: <b><?= DF_ENTRADA ?> moedas</b>. Vitória paga
       <b><?= DF_VITORIA ?></b>, empate <b><?= DF_EMPATE ?></b>.</p>
    <div class="forms">
      <?php foreach (DFUT_FORMACOES as $nome => $vagas): ?>
        <form method="POST">
          <input type="hidden" name="acao" value="comecar">
          <input type="hidden" name="formacao" value="<?= e($nome) ?>">
          <button class="form-card" type="submit" <?= $moedas < DF_ENTRADA ? 'disabled' : '' ?>>
            <b><?= e($nome) ?></b>
            <small><?= e(implode(' · ', array_slice(array_unique(array_column($vagas, 0)), 0, 5))) ?></small>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
    <?php if ($moedas < DF_ENTRADA): ?>
      <p class="sub" style="margin-top:12px;color:#ff5c7a">
        Você tem <?= $moedas ?> moedas e a entrada custa <?= DF_ENTRADA ?>.</p>
    <?php endif; ?>
  </div>

<?php elseif ($emDraft): ?>
  <?php $vaga = DFUT_FORMACOES[$d['formacao']][$d['vaga']]; ?>
  <div class="bloco">
    <h2>Vaga <?= $d['vaga'] + 1 ?> de <?= DFUT_VAGAS ?> — <?= e($vaga[0]) ?></h2>
    <p class="sub">Formação <b><?= e($d['formacao']) ?></b> · escolha uma das cinco.
       Química parcial: <b><?= $quimParcial['total'] ?></b>.</p>
    <div class="cartas">
      <?php foreach ($d['opcoes'] as $i => $c): ?>
        <form method="POST">
          <input type="hidden" name="acao" value="escolher">
          <input type="hidden" name="carta" value="<?= $i ?>">
          <button class="carta" type="submit">
            <span class="ovr"><?= (int)$c['ovr'] ?></span>
            <span class="pos"><?= e($c['pos']) ?><?= $c['pos'] !== $vaga[1] ? ' ⚠' : '' ?></span>
            <?php if ($c['escudo']): ?><img src="<?= e($c['escudo']) ?>" alt="" loading="lazy"><?php endif; ?>
            <span class="nm"><?= e($c['nome']) ?></span>
            <span class="cl"><?= e($c['clube']) ?></span>
            <span class="lg"><?= e($c['liga']) ?></span>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
  <?php include __DIR__ . '/draftfut_escalacao.php'; ?>

<?php elseif ($pronto): ?>
  <div class="bloco">
    <h2>Time pronto</h2>
    <p class="sub">Dá o nome e escolhe o adversário.</p>
    <div class="nums">
      <div class="num"><b><?= draftFutForcaDoTime($d['formacao'], $d['time']) ?></b><small>Força</small></div>
      <div class="num"><b><?= $quimParcial['total'] ?></b><small>Química</small></div>
      <div class="num"><b><?= e($d['formacao']) ?></b><small>Formação</small></div>
    </div>
    <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <input type="hidden" name="acao" value="jogar">
      <input type="text" name="nome" maxlength="60" placeholder="Nome do seu time" required
             style="background:var(--panel3);border:1px solid var(--borda2);border-radius:10px;
                    color:var(--txt);padding:10px 13px;font:inherit;font-size:13px;flex:1;min-width:180px">
      <button class="btn pri" type="submit" name="modo" value="maquina">
        <i class="bi bi-cpu"></i> Contra a máquina</button>
      <button class="btn" type="submit" name="modo" value="pvp">
        <i class="bi bi-people-fill"></i> Contra outro GM</button>
    </form>
    <p class="sub" style="margin:10px 0 0;font-size:12px">
      Sem nenhum time de GM na sua faixa de força, o jogo cai na máquina — e avisa.</p>
  </div>
  <?php include __DIR__ . '/draftfut_escalacao.php'; ?>

<?php else:
  $r = $d['resultado']; $p = $r['partida']; ?>
  <div class="bloco">
    <h2>Resultado</h2>
    <div class="placar">
      <div class="t"><b><?= e($r['nome']) ?></b><small>força <?= $r['forca'] ?> · química <?= $r['quimica'] ?></small></div>
      <div class="g"><?= $p['placar'][0] ?> <span style="color:var(--txt3)">x</span> <?= $p['placar'][1] ?></div>
      <div class="t"><b><?= e($r['adv']['nome']) ?></b><small>força <?= $r['adv']['forca'] ?>
        · <?= $r['modo'] === 'pvp' ? 'outro GM' : 'máquina' ?></small></div>
    </div>
    <div style="text-align:center;margin-bottom:14px">
      <?php if ($r['premio'] > 0): ?>
        <span class="aviso ok" style="display:inline-block"><i class="bi bi-coin"></i>
          +<?= $r['premio'] ?> moedas</span>
      <?php else: ?>
        <span class="aviso err" style="display:inline-block">Sem prêmio dessa vez.</span>
      <?php endif; ?>
    </div>
    <div class="narra" id="narra"></div>
    <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
      <form method="POST"><input type="hidden" name="acao" value="novo">
        <button class="btn pri" type="submit"><i class="bi bi-arrow-repeat"></i> Novo draft</button></form>
      <button class="btn" type="button" id="pular">Mostrar tudo</button>
    </div>
  </div>
  <?php include __DIR__ . '/draftfut_escalacao.php'; ?>
  <script>
  /* A NARRAÇÃO SAI NO RITMO DO JOGO, não de uma vez: a graça é acompanhar o
     placar virar. Quem não tem paciência tem o "Mostrar tudo". */
  const LANCES = <?= json_encode($p['lances'], JSON_UNESCAPED_UNICODE) ?>;
  const alvo = document.getElementById('narra');
  let i = 0, timer = null;
  function desenha(l){
    const d = document.createElement('div');
    d.className = 'lance ' + l.tipo;
    d.innerHTML = `<span class="m">${l.min}'</span><span>${l.texto}` +
      (l.placar ? `<span class="pl">${l.placar}</span>` : '') + `</span>`;
    alvo.appendChild(d);
    alvo.scrollTop = alvo.scrollHeight;
  }
  function passo(){
    if (i >= LANCES.length) { clearInterval(timer); return; }
    desenha(LANCES[i++]);
  }
  timer = setInterval(passo, 700);
  passo();
  document.getElementById('pular').onclick = () => {
    clearInterval(timer);
    while (i < LANCES.length) desenha(LANCES[i++]);
  };
  </script>
<?php endif; ?>
</div>
</body>
</html>
