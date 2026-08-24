<?php
/**
 * COPA DO MUNDO DE ALGUMA COISA.
 *
 * O admin geral cria uma copa do que quiser, põe os competidores e sorteia.
 * A galera vota confronto a confronto; quem tem mais voto avança, até sobrar
 * um campeão.
 *
 * Uma tela só, e não uma pública + uma de admin: o admin precisa ver
 * exatamente o que a galera vê pra decidir quando fechar a rodada. Os
 * controles dele aparecem no meio do chaveamento, e pra quem não é admin
 * simplesmente não existem.
 *
 * O motor (sorteio, apuração, pontos) vive em games/core/copa_motor.php.
 */

require_once __DIR__ . '/../core/conexao.php';
require_once __DIR__ . '/../core/copa_motor.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['user_type'] ?? '') === 'admin');

copaTabelas($pdo);

$msg = $erro = null;
$destaqueSorteio = false;

/* ── Ações ──────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    $tid  = (int)($_POST['torneio_id'] ?? 0);

    try {
        if ($acao === 'votar') {
            $r = copaVotar($pdo, $tid, (int)$_POST['confronto_id'], (int)$_POST['escolha_id'], $userId);
            if (!$r['ok']) $_SESSION['copa_flash'] = ['erro', $r['erro']];

        } elseif ($acao === 'criar' && $isAdmin) {
            // Um nome por linha. Aceita também vírgula, porque colar de uma
            // lista pronta é o caminho mais provável e separar na mão seria
            // trabalho à toa.
            $bruto = str_replace(["\r", ','], ["\n", "\n"], (string)($_POST['nomes'] ?? ''));
            $r = copaCriar($pdo, (string)($_POST['titulo'] ?? ''), explode("\n", $bruto), $userId);
            if ($r['ok']) {
                $_SESSION['copa_flash'] = ['ok', 'Copa criada e chaveamento sorteado!'];
                $_SESSION['copa_sorteio'] = $r['id'];
                header('Location: ?copa=' . $r['id']);
                exit;
            }
            $_SESSION['copa_flash'] = ['erro', $r['erro']];
            $_SESSION['copa_form'] = ['titulo' => $_POST['titulo'] ?? '', 'nomes' => $_POST['nomes'] ?? ''];

        } elseif ($acao === 'votacao' && $isAdmin) {
            copaVotacao($pdo, $tid, !empty($_POST['abrir']));
            $_SESSION['copa_flash'] = ['ok', !empty($_POST['abrir'])
                ? 'Votação ABERTA — a galera já pode votar.'
                : 'Votação fechada. Ninguém vota até você abrir de novo.'];

        } elseif ($acao === 'fechar' && $isAdmin) {
            $r = copaFecharRodada($pdo, $tid);
            if ($r['ok']) {
                $t = 'Rodada apurada: ' . $r['decididos'] . ' no voto';
                if ($r['sorteados']) $t .= ', ' . $r['sorteados'] . ' no sorteio (empate)';
                $t .= '. ' . $r['pagos'] . ' pessoa(s) receberam FBA Points.';
                if ($r['campeao']) $t = '🏆 CAMPEÃO: ' . $r['campeao'] . '! ' . $t;
                $_SESSION['copa_flash'] = ['ok', $t];
            } else {
                $_SESSION['copa_flash'] = ['erro', (string)$r['erro']];
            }

        } elseif ($acao === 'apagar' && $isAdmin) {
            copaApagar($pdo, $tid);
            $_SESSION['copa_flash'] = ['ok', 'Copa apagada.'];
            header('Location: ?');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['copa_flash'] = ['erro', 'Deu erro: ' . $e->getMessage()];
    }
    header('Location: ?copa=' . $tid);
    exit;
}

if (!empty($_SESSION['copa_flash'])) {
    [$t, $x] = $_SESSION['copa_flash'];
    unset($_SESSION['copa_flash']);
    if ($t === 'ok') $msg = $x; else $erro = $x;
}
$form = $_SESSION['copa_form'] ?? ['titulo' => '', 'nomes' => ''];
unset($_SESSION['copa_form']);

/* ── Dados da tela ──────────────────────────────────────────────────── */
$copaId = (int)($_GET['copa'] ?? 0);
$copa   = $copaId ? copaTorneio($pdo, $copaId) : copaAtual($pdo);
$novo   = $isAdmin && isset($_GET['nova']);

if (!empty($_SESSION['copa_sorteio']) && $copa && (int)$_SESSION['copa_sorteio'] === (int)$copa['id']) {
    $destaqueSorteio = true;
    unset($_SESSION['copa_sorteio']);
}

$chave = $comps = $ranking = [];
$rodadas = 0;
if ($copa) {
    $chave   = copaChave($pdo, (int)$copa['id'], $userId);
    $comps   = copaCompetidores($pdo, (int)$copa['id']);
    $ranking = copaRanking($pdo, (int)$copa['id']);
    $rodadas = (int)$copa['rodadas'];
}
$lista = copaLista($pdo);

$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$nomeDe = fn($id) => $id && isset($comps[$id]) ? $comps[$id]['nome'] : '—';

// A minha situação na copa, pro cabeçalho dizer em que degrau eu estou.
$minhaSeq = null;
if ($copa && $userId) {
    $st = $pdo->prepare("SELECT * FROM copa_sequencias WHERE torneio_id=? AND user_id=?");
    $st->execute([(int)$copa['id'], $userId]);
    $minhaSeq = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#f59e0b">
<title><?= $copa ? $esc($copa['titulo']) : 'Copa do Mundo' ?> — FBA Games</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  :root{
    --ouro:#f59e0b; --ouro-2:#fbbf24;
    --bg:#08080b; --panel:#101014; --panel-2:#16161b; --panel-3:#1e1e25;
    --border:rgba(255,255,255,.07); --border-md:rgba(255,255,255,.13);
    --text:#f2f2f5; --text-2:#8a8a95; --text-3:#6f6f7a;
    --verde:#22c55e; --vermelho:#ef4444;
    --font:'Montserrat',sans-serif; --raio:14px;
  }
  :root[data-theme="light"]{
    --bg:#f6f7fb; --panel:#fff; --panel-2:#f1f3f8; --panel-3:#e6eaf2;
    --border:#e3e6ee; --border-md:#d3d8e4; --text:#12141a; --text-2:#5a6070; --text-3:#7a8092;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font-family:var(--font);font-size:14px;
       min-height:100vh;padding-bottom:50px}
  a{color:inherit;text-decoration:none}
  .wrap{max-width:1240px;margin:0 auto;padding:18px 16px}

  /* ── Topo ─────────────────────────────────────────────────────── */
  .topo{display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
  .voltar{color:var(--text-3);font-size:17px;border:1px solid var(--border-md);
          border-radius:9px;padding:7px 10px;line-height:1;flex:none}
  .voltar:hover{color:var(--text);border-color:var(--ouro)}
  .tit{font-size:21px;font-weight:900;line-height:1.15;flex:1;min-width:180px}
  .tit small{display:block;font-size:10.5px;font-weight:800;letter-spacing:1.3px;
             text-transform:uppercase;color:var(--ouro);margin-bottom:2px}

  .aviso{border-radius:11px;padding:11px 14px;font-size:12.5px;font-weight:700;
         margin-bottom:14px;display:flex;gap:9px;align-items:flex-start;line-height:1.45}
  .aviso.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#4ade80}
  .aviso.bad{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#f87171}

  .cx{background:var(--panel);border:1px solid var(--border);border-radius:var(--raio);
      padding:16px 18px;margin-bottom:14px}
  .cx h2{font-size:11px;font-weight:900;letter-spacing:1.2px;text-transform:uppercase;
         color:var(--text-3);margin-bottom:12px}

  /* ── Estado / controles ───────────────────────────────────────── */
  .estado{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
  .selo{font-size:10.5px;font-weight:900;letter-spacing:.7px;text-transform:uppercase;
        padding:5px 11px;border-radius:999px;border:1px solid}
  .selo.aberta{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.35);color:#4ade80}
  .selo.fechada{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.3);color:#f87171}
  .selo.rodada{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.35);color:var(--ouro-2)}
  .selo.neutro{background:var(--panel-2);border-color:var(--border-md);color:var(--text-2)}

  .bt{font-family:inherit;font-size:12.5px;font-weight:800;border-radius:10px;padding:9px 15px;
      cursor:pointer;border:1px solid var(--border-md);background:var(--panel-2);color:var(--text);
      display:inline-flex;align-items:center;gap:7px}
  .bt:hover{border-color:var(--ouro);color:var(--ouro-2)}
  .bt.ouro{background:var(--ouro);border-color:var(--ouro);color:#1a1205}
  .bt.ouro:hover{filter:brightness(1.08);color:#1a1205}
  .bt.verde{background:var(--verde);border-color:var(--verde);color:#052e16}
  .bt.verde:hover{filter:brightness(1.08);color:#052e16}
  .bt.perigo{color:#f87171;border-color:rgba(239,68,68,.3)}
  .bt.perigo:hover{border-color:var(--vermelho);color:var(--vermelho)}

  /* ── O chaveamento ────────────────────────────────────────────── */
  /* Rola na horizontal no próprio contêiner: uma copa de 64 tem 6 colunas e
     não cabe em celular nenhum. O corpo da página nunca rola pro lado. */
  .bracket-wrap{overflow-x:auto;padding-bottom:10px;-webkit-overflow-scrolling:touch}
  .bracket{display:flex;gap:14px;min-width:min-content;align-items:stretch}
  .coluna{display:flex;flex-direction:column;justify-content:space-around;gap:8px;
          min-width:206px;flex:none}
  .col-tit{font-size:10px;font-weight:900;letter-spacing:1px;text-transform:uppercase;
           color:var(--text-3);text-align:center;padding-bottom:6px;position:sticky;top:0}
  .col-tit.agora{color:var(--ouro)}

  .duelo{background:var(--panel-2);border:1px solid var(--border);border-radius:11px;
         overflow:hidden;display:flex;flex-direction:column}
  .duelo.ativo{border-color:rgba(245,158,11,.45);box-shadow:0 0 0 1px rgba(245,158,11,.12)}
  .lado{display:flex;align-items:center;gap:8px;padding:9px 11px;position:relative;
        font-size:12.5px;font-weight:700;background:none;border:0;width:100%;text-align:left;
        font-family:inherit;color:var(--text);cursor:default}
  .lado + .lado{border-top:1px solid var(--border)}
  .lado .nome{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .lado .n{font-size:11.5px;font-weight:900;color:var(--text-3);font-variant-numeric:tabular-nums}
  /* A barra de proporção fica ATRÁS do nome, não do lado: com 32 confrontos
     na tela, uma barra separada por linha viraria uma parede de gráfico. */
  .lado .barra{position:absolute;inset:0;background:rgba(245,158,11,.13);
               transform-origin:left;transition:transform .3s ease;z-index:0}
  .lado .nome,.lado .n,.lado i{position:relative;z-index:1}
  .lado.vence{color:var(--ouro-2)}
  .lado.vence .n{color:var(--ouro-2)}
  .lado.perde{opacity:.42}
  .lado.meu::after{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;
                   background:var(--verde);z-index:2}
  button.lado{cursor:pointer}
  button.lado:hover{background:var(--panel-3)}
  .bye{padding:9px 11px;font-size:11px;font-weight:800;color:var(--text-3);
       text-transform:uppercase;letter-spacing:.6px;border-top:1px solid var(--border)}
  .duelo-pe{padding:5px 11px;font-size:9.5px;font-weight:800;color:var(--text-3);
            border-top:1px solid var(--border);display:flex;justify-content:space-between;gap:8px}
  .vazio-duelo{padding:14px 11px;font-size:11.5px;color:var(--text-3);text-align:center}

  .campeao{background:linear-gradient(135deg,rgba(245,158,11,.16),rgba(245,158,11,.04));
           border:1px solid rgba(245,158,11,.4);border-radius:var(--raio);padding:22px;
           text-align:center;margin-bottom:14px}
  .campeao i{font-size:34px;color:var(--ouro)}
  .campeao .nome{font-size:26px;font-weight:900;margin-top:6px;line-height:1.15}
  .campeao .rot{font-size:10.5px;font-weight:900;letter-spacing:1.6px;
                text-transform:uppercase;color:var(--ouro);margin-top:4px}

  /* ── Formulário e listas ──────────────────────────────────────── */
  label{display:block;font-size:10.5px;font-weight:900;letter-spacing:.8px;
        text-transform:uppercase;color:var(--text-3);margin-bottom:6px}
  input[type=text],textarea,select{width:100%;background:var(--panel-2);color:var(--text);
        border:1px solid var(--border-md);border-radius:10px;padding:10px 12px;
        font-family:inherit;font-size:13px}
  input:focus,textarea:focus,select:focus{outline:none;border-color:var(--ouro)}
  textarea{min-height:180px;resize:vertical;line-height:1.6}
  .campo{margin-bottom:14px}
  .dica{font-size:11.5px;color:var(--text-3);margin-top:6px;line-height:1.5}
  .contador{font-weight:900;color:var(--ouro-2)}
  .presets{display:flex;gap:7px;flex-wrap:wrap;margin-top:8px}
  .preset{font-size:11px;font-weight:800;padding:6px 11px;border-radius:8px;
          border:1px solid var(--border-md);background:var(--panel-2);color:var(--text-2);cursor:pointer}
  .preset:hover{border-color:var(--ouro);color:var(--ouro-2)}

  table{width:100%;border-collapse:collapse;font-size:12.5px}
  th{text-align:left;font-size:9.5px;font-weight:900;letter-spacing:.8px;text-transform:uppercase;
     color:var(--text-3);padding:0 8px 8px}
  td{padding:8px;border-top:1px solid var(--border)}
  td.num{text-align:right;font-variant-numeric:tabular-nums;font-weight:800}
  tr.eu td{color:var(--ouro-2)}

  .copas{display:flex;gap:8px;flex-wrap:wrap}
  .copa-lk{font-size:11.5px;font-weight:800;padding:7px 12px;border-radius:9px;
           border:1px solid var(--border-md);background:var(--panel-2);color:var(--text-2)}
  .copa-lk.on{border-color:var(--ouro);color:var(--ouro-2);background:rgba(245,158,11,.1)}

  .vazio{text-align:center;padding:34px 16px;color:var(--text-3);font-size:13px;line-height:1.6}

  @media (max-width:620px){
    .wrap{padding:14px 12px}
    .tit{font-size:18px}
    .coluna{min-width:184px}
  }
  <?php /* O sorteio recém-feito entra caindo, um confronto por vez. É o
           momento que a galera assiste — sem isso o chaveamento simplesmente
           aparece pronto e o sorteio não acontece pra ninguém. */ ?>
  @keyframes cai{from{opacity:0;transform:translateY(-10px) scale(.97)}to{opacity:1;transform:none}}
  .sorteando .coluna:first-child .duelo{animation:cai .34s backwards}
  @media (prefers-reduced-motion:reduce){.sorteando .coluna:first-child .duelo{animation:none}}
</style>
</head>
<body>
<div class="wrap">

  <div class="topo">
    <a class="voltar" href="/games.php" title="Voltar aos games"><i class="bi bi-arrow-left"></i></a>
    <div class="tit">
      <small>Copa do Mundo</small>
      <?= $copa ? $esc($copa['titulo']) : 'Nenhuma copa ainda' ?>
    </div>
    <?php if ($isAdmin && !$novo): ?>
    <a class="bt ouro" href="?nova=1"><i class="bi bi-plus-lg"></i> Nova copa</a>
    <?php endif; ?>
  </div>

  <?php if ($msg): ?><div class="aviso ok"><i class="bi bi-check-circle-fill"></i><span><?= $esc($msg) ?></span></div><?php endif; ?>
  <?php if ($erro): ?><div class="aviso bad"><i class="bi bi-exclamation-triangle-fill"></i><span><?= $esc($erro) ?></span></div><?php endif; ?>

<?php if ($novo): /* ── CRIAR ────────────────────────────────────────── */ ?>
  <div class="cx">
    <h2>Criar uma copa</h2>
    <form method="post">
      <input type="hidden" name="acao" value="criar">
      <div class="campo">
        <label>Do que é a copa?</label>
        <input type="text" name="titulo" maxlength="120" required
               placeholder="Copa do Mundo dos Salgados, Melhor Camisa da NBA…"
               value="<?= $esc($form['titulo']) ?>">
      </div>
      <div class="campo">
        <label>Competidores — um por linha <span class="contador" id="conta"></span></label>
        <textarea name="nomes" id="nomes" required
                  placeholder="Coxinha&#10;Pastel&#10;Empada&#10;Kibe"><?= $esc($form['nomes']) ?></textarea>
        <div class="dica" id="previsao">
          Pode colar uma lista pronta — vírgula também separa. Nomes repetidos
          e linhas vazias são descartados.
        </div>
        <?php /* Os presets não travam nada: são só um atalho pra saber quantos
                 faltam. Qualquer número de 2 a 64 monta chaveamento. */ ?>
        <div class="presets">
          <?php foreach (COPA_TAMANHOS as $tam): ?>
          <button type="button" class="preset" data-alvo="<?= $tam ?>"><?= $tam ?> competidores</button>
          <?php endforeach; ?>
        </div>
      </div>
      <div style="display:flex;gap:9px;flex-wrap:wrap">
        <button class="bt ouro"><i class="bi bi-shuffle"></i> Sortear o chaveamento</button>
        <a class="bt" href="?">Cancelar</a>
      </div>
    </form>
  </div>

<?php elseif (!$copa): ?>
  <div class="cx"><div class="vazio">
    Nenhuma copa foi criada ainda.<br>
    <?= $isAdmin ? 'Clique em <b>Nova copa</b> pra começar.' : 'Assim que o admin criar uma, ela aparece aqui.' ?>
  </div></div>

<?php else: /* ── A COPA ───────────────────────────────────────────── */
  $encerrada = $copa['status'] !== 'ativo';
  $rodAtual  = (int)$copa['rodada_atual'];
  $votando   = !$encerrada && !empty($copa['votacao']);
?>

  <?php if ($encerrada && $copa['campeao_id']): ?>
  <div class="campeao">
    <i class="bi bi-trophy-fill"></i>
    <div class="nome"><?= $esc($nomeDe((int)$copa['campeao_id'])) ?></div>
    <div class="rot">Campeão · <?= $esc($copa['titulo']) ?></div>
  </div>
  <?php endif; ?>

  <div class="estado">
    <span class="selo rodada"><?= $esc(copaNomeRodada($rodAtual, $rodadas)) ?></span>
    <?php if (!$encerrada): ?>
      <span class="selo <?= $votando ? 'aberta' : 'fechada' ?>">
        <?= $votando ? 'Votação aberta' : 'Votação fechada' ?>
      </span>
    <?php else: ?>
      <span class="selo neutro">Encerrada</span>
    <?php endif; ?>
    <span class="selo neutro"><?= (int)$copa['tamanho'] ?> competidores</span>
    <?php if ($minhaSeq && (int)$minhaSeq['pontos'] > 0): ?>
      <span class="selo neutro">
        Você: <?= (int)$minhaSeq['pontos'] ?> pts
        <?php if ((int)$minhaSeq['sequencia'] > 0): ?>· 🔥 degrau <?= (int)$minhaSeq['sequencia'] + 1 ?><?php endif; ?>
      </span>
    <?php endif; ?>
  </div>

  <?php if ($isAdmin && !$encerrada): ?>
  <div class="cx">
    <h2>Controle do admin</h2>
    <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:center">
      <form method="post" style="display:inline">
        <input type="hidden" name="acao" value="votacao">
        <input type="hidden" name="torneio_id" value="<?= (int)$copa['id'] ?>">
        <input type="hidden" name="abrir" value="<?= $votando ? '' : '1' ?>">
        <button class="bt <?= $votando ? '' : 'verde' ?>">
          <i class="bi bi-<?= $votando ? 'pause-fill' : 'play-fill' ?>"></i>
          <?= $votando ? 'Fechar a votação' : 'Abrir a votação' ?>
        </button>
      </form>

      <form method="post" style="display:inline" onsubmit="return confirm('Apurar os votos, pagar quem acertou e montar a próxima rodada? Não dá pra desfazer.')">
        <input type="hidden" name="acao" value="fechar">
        <input type="hidden" name="torneio_id" value="<?= (int)$copa['id'] ?>">
        <button class="bt ouro"><i class="bi bi-flag-fill"></i>
          Apurar <?= $esc(mb_strtolower(copaNomeRodada($rodAtual, $rodadas))) ?> e avançar
        </button>
      </form>

      <span class="dica" style="margin:0">
        <?= copaQuantosVotaram($pdo, (int)$copa['id'], $rodAtual) ?> pessoa(s) votaram nesta rodada.
      </span>

      <form method="post" style="margin-left:auto"
            onsubmit="return confirm('APAGAR a copa inteira, com votos e pontuação? Não dá pra desfazer.')">
        <input type="hidden" name="acao" value="apagar">
        <input type="hidden" name="torneio_id" value="<?= (int)$copa['id'] ?>">
        <button class="bt perigo"><i class="bi bi-trash"></i> Apagar</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="cx">
    <h2>O chaveamento</h2>
    <div class="bracket-wrap<?= $destaqueSorteio ? ' sorteando' : '' ?>">
      <div class="bracket">
        <?php for ($r = 1; $r <= $rodadas; $r++): ?>
        <div class="coluna">
          <div class="col-tit <?= (!$encerrada && $r === $rodAtual) ? 'agora' : '' ?>">
            <?= $esc(copaNomeRodada($r, $rodadas)) ?>
          </div>

          <?php if (empty($chave[$r])): ?>
            <div class="duelo"><div class="vazio-duelo">aguardando</div></div>
          <?php else: foreach ($chave[$r] as $i => $c):
            $aId = (int)$c['a_id'];
            $bId = (int)$c['b_id'];
            $venc = (int)$c['vencedor_id'];
            $bye  = !$bId;
            $podeVotar = $votando && $r === $rodAtual && !$venc && $bId && $userId;
            $total = (int)$c['votos_a'] + (int)$c['votos_b'];
            // A barra mostra a fatia de cada um. Sem voto nenhum ela fica em
            // zero — 50/50 numa disputa vazia parece empate técnico, e não é.
            $fa = $total ? (int)$c['votos_a'] / $total : 0;
            $fb = $total ? (int)$c['votos_b'] / $total : 0;
            $mostraVotos = $total > 0 || ($r === $rodAtual && !$encerrada);
          ?>
          <div class="duelo <?= $podeVotar ? 'ativo' : '' ?>">
            <?php
              // Os dois lados saem do mesmo molde: botão quando dá pra votar,
              // div quando não. Duplicar o markup faria o placar divergir de
              // um lado pro outro na primeira mudança.
              $lado = function ($id, $votos, $fatia) use ($c, $venc, $podeVotar, $copa, $esc, $nomeDe, $mostraVotos) {
                  if (!$id) return;
                  $cls = 'lado';
                  if ($venc) $cls .= $venc === $id ? ' vence' : ' perde';
                  if ((int)$c['meu_voto'] === $id) $cls .= ' meu';
                  $tag = $podeVotar ? 'button' : 'div';
                  ?>
                  <?php if ($podeVotar): ?>
                  <form method="post" style="display:contents">
                    <input type="hidden" name="acao" value="votar">
                    <input type="hidden" name="torneio_id" value="<?= (int)$copa['id'] ?>">
                    <input type="hidden" name="confronto_id" value="<?= (int)$c['id'] ?>">
                    <input type="hidden" name="escolha_id" value="<?= $id ?>">
                  <?php endif; ?>
                  <<?= $tag ?> class="<?= $cls ?>"<?= $podeVotar ? ' type="submit"' : '' ?>>
                    <span class="barra" style="transform:scaleX(<?= round($fatia, 3) ?>)"></span>
                    <?php if ($venc === $id): ?><i class="bi bi-caret-right-fill"></i><?php endif; ?>
                    <span class="nome"><?= $esc($nomeDe($id)) ?></span>
                    <?php if ($mostraVotos): ?><span class="n"><?= (int)$votos ?></span><?php endif; ?>
                  </<?= $tag ?>>
                  <?php if ($podeVotar): ?></form><?php endif; ?>
                  <?php
              };
              $lado($aId, $c['votos_a'], $fa);
            ?>
            <?php if ($bye): ?>
              <div class="bye"><i class="bi bi-fast-forward-fill"></i> passou sem confronto</div>
            <?php else: $lado($bId, $c['votos_b'], $fb); endif; ?>

            <?php if (!empty($c['no_sorteio']) || ($c['meu_voto'] && $venc)): ?>
            <div class="duelo-pe">
              <?php if (!empty($c['no_sorteio'])): ?><span>empate · decidido no sorteio</span><?php endif; ?>
              <?php if ($c['meu_voto'] && $venc): ?>
                <span style="color:<?= (int)$c['meu_voto'] === $venc ? 'var(--verde)' : 'var(--vermelho)' ?>">
                  <?= (int)$c['meu_voto'] === $venc ? 'você acertou' : 'você errou' ?>
                </span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; endif; ?>
        </div>
        <?php endfor; ?>
      </div>
    </div>
    <?php if (!$encerrada && !$votando): ?>
    <div class="dica" style="margin-top:12px">
      A votação está fechada. Quando o admin abrir, é só clicar no competidor
      pra votar — dá pra trocar o voto enquanto a rodada estiver aberta.
    </div>
    <?php elseif ($votando): ?>
    <div class="dica" style="margin-top:12px">
      Clique no nome pra votar. Cada palpite certo vale FBA Points, e quem
      acerta a maioria da rodada sobe um degrau — o próximo acerto passa a
      valer mais.
    </div>
    <?php endif; ?>
  </div>

  <?php if ($ranking): ?>
  <div class="cx">
    <h2>Quem está indo bem</h2>
    <table>
      <thead><tr><th>#</th><th>GM</th><th class="num">Acertos</th><th class="num">Degrau</th><th class="num">FBA Points</th></tr></thead>
      <tbody>
        <?php foreach ($ranking as $i => $r): ?>
        <tr class="<?= (int)$r['user_id'] === $userId ? 'eu' : '' ?>">
          <td><?= $i + 1 ?></td>
          <td><?= $esc($r['nome']) ?></td>
          <td class="num"><?= (int)$r['acertos'] ?></td>
          <td class="num"><?= (int)$r['sequencia'] > 0 ? '🔥 ' . ((int)$r['sequencia'] + 1) : '—' ?></td>
          <td class="num"><?= (int)$r['pontos'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
<?php endif; ?>

  <?php if (count($lista) > 1 || ($lista && !$novo)): ?>
  <div class="cx">
    <h2>Todas as copas</h2>
    <div class="copas">
      <?php foreach ($lista as $c): ?>
      <a class="copa-lk <?= $copa && (int)$c['id'] === (int)$copa['id'] ? 'on' : '' ?>"
         href="?copa=<?= (int)$c['id'] ?>">
        <?= $esc($c['titulo']) ?>
        <?php if ($c['status'] !== 'ativo'): ?> · encerrada<?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<?php if ($novo): ?>
<script>
/* Conta os nomes enquanto a pessoa digita e diz o que vai sair: quantos
   jogam a primeira rodada e quantos passam direto. Descobrir isso só depois
   de sortear seria tarde — o sorteio não se desfaz. */
(function () {
  var ta = document.getElementById('nomes');
  var conta = document.getElementById('conta');
  var prev = document.getElementById('previsao');
  var base = prev.textContent;

  function limpos() {
    var vistos = {}, out = [];
    ta.value.split(/[\n,]/).forEach(function (n) {
      n = n.trim().replace(/\s+/g, ' ');
      if (!n) return;
      var k = n.toLowerCase();
      if (vistos[k]) return;
      vistos[k] = 1;
      out.push(n);
    });
    return out;
  }

  function atualizar() {
    var n = limpos().length;
    conta.textContent = n ? '(' + n + ')' : '';
    if (n < 2) { prev.textContent = base; return; }
    if (n > 64) { prev.textContent = 'São ' + n + ' — o máximo é 64.'; return; }

    var slots = 2; while (slots < n) slots *= 2;
    var byes = slots - n;
    var rodadas = Math.round(Math.log2(slots));
    var txt = n + ' competidores · chaveamento de ' + slots + ' · ' + rodadas + ' rodadas até o campeão';
    if (byes > 0) txt += ' · ' + byes + ' passam sem confronto na primeira (sorteados)';
    prev.textContent = txt;
  }

  ta.addEventListener('input', atualizar);
  atualizar();

  document.querySelectorAll('.preset').forEach(function (b) {
    b.addEventListener('click', function () {
      var faltam = Number(b.dataset.alvo) - limpos().length;
      prev.textContent = faltam > 0 ? 'Faltam ' + faltam + ' pra chegar em ' + b.dataset.alvo + '.'
                       : faltam < 0 ? 'Passou ' + (-faltam) + ' de ' + b.dataset.alvo + '.'
                       : 'São exatamente ' + b.dataset.alvo + '. Pode sortear.';
    });
  });
})();
</script>
<?php endif; ?>

<?php if ($destaqueSorteio): ?>
<script>
/* Escalona a entrada dos confrontos sorteados. O atraso vai no style e não
   numa classe por índice: são até 32 confrontos, e 32 regras de CSS pra isso
   seria pior que uma linha de JS. */
document.querySelectorAll('.sorteando .coluna:first-child .duelo').forEach(function (d, i) {
  d.style.animationDelay = Math.min(i * 55, 1800) + 'ms';
});
</script>
<?php endif; ?>
</body>
</html>
