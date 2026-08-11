<?php
/**
 * QUIZ DO DIA — uma pergunta por dia, cinco opções, e quem votou com a
 * maioria leva 100 moedas.
 *
 * Não existe resposta certa: o jogo é adivinhar o que a galera pensa. Por
 * isso a apuração só aparece DEPOIS que você vota — se o placar estivesse
 * à mostra antes, bastava seguir o líder e não sobraria jogo nenhum.
 *
 * O dia fecha à meia-noite de Brasília e é apurado com preguiça, na
 * primeira visita do dia seguinte, em vez de por cron. Foi decisão
 * consciente: cron aqui é uma dependência a mais pra dar errado calada, e
 * quem abre a página é justamente quem quer ver o resultado. Se ninguém
 * abrir por três dias, a próxima visita apura os três.
 *
 * A fila de perguntas vive no banco (games/admin/quiz-admin.php edita).
 * Uma pergunta só estreia quando alguém abre o jogo naquele dia — dia sem
 * ninguém não queima pergunta.
 */

require __DIR__ . '/../core/conexao.php';

$idUsuario = (int)($_SESSION['user_id'] ?? 0);
if ($idUsuario <= 0) { header('Location: /login.php'); exit; }

const QUIZ_PREMIO    = 100;  // moedas pra quem votou com a maioria
const QUIZ_MIN_VOTOS = 3;    // abaixo disso o dia não paga ninguém

// ── Tabelas ────────────────────────────────────────────────────────────
// data_uso com UNIQUE: é a chave que garante uma pergunta por dia mesmo
// com dois acessos simultâneos. NULL repetido o MySQL aceita, então a
// fila (todas com data_uso NULL) convive com ela sem problema.
$pdo->exec("CREATE TABLE IF NOT EXISTS quiz_perguntas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pergunta VARCHAR(255) NOT NULL,
    opcoes TEXT NOT NULL,
    ordem INT NOT NULL DEFAULT 0,
    data_uso DATE NULL,
    resolvido_em DATETIME NULL,
    vencedoras VARCHAR(40) NULL,
    total_votos INT NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_dia (data_uso),
    INDEX idx_fila (data_uso, ordem, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS quiz_votos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pergunta_id INT NOT NULL,
    id_usuario INT NOT NULL,
    opcao TINYINT NOT NULL,
    pago TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_voto (pergunta_id, id_usuario),
    INDEX idx_pergunta (pergunta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

require_once __DIR__ . '/../core/quiz_perguntas.php';
quizSemear($pdo);

// ── Funções ────────────────────────────────────────────────────────────

function quizHoje(): string {
    return (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
}

function quizOpcoes(array $p): array {
    $o = json_decode((string)$p['opcoes'], true);
    return is_array($o) ? array_values($o) : [];
}

function quizVencedoras(array $p): array {
    $v = json_decode((string)($p['vencedoras'] ?? ''), true);
    return is_array($v) ? array_map('intval', $v) : [];
}

/** Votos por opção, indexado pelo número da opção. */
function quizContagem(PDO $pdo, int $perguntaId): array {
    $st = $pdo->prepare("SELECT opcao, COUNT(*) AS n FROM quiz_votos WHERE pergunta_id = ? GROUP BY opcao");
    $st->execute([$perguntaId]);
    $out = [];
    foreach ($st as $r) $out[(int)$r['opcao']] = (int)$r['n'];
    return $out;
}

/**
 * Fecha uma pergunta e paga quem acertou a maioria.
 *
 * Marcar `resolvido_em` ANTES de contar é o que torna isto idempotente:
 * dois acessos ao mesmo tempo e só um acha linha pra marcar; o outro fica
 * preso no lock até o commit e então enxerga rowCount 0. Sem essa ordem,
 * dois visitantes simultâneos pagariam o prêmio duas vezes.
 */
function quizResolver(PDO $pdo, int $perguntaId): void
{
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare("UPDATE quiz_perguntas SET resolvido_em = NOW()
                                WHERE id = ? AND resolvido_em IS NULL");
        $claim->execute([$perguntaId]);
        if ($claim->rowCount() === 0) { $pdo->rollBack(); return; }

        $contagem = quizContagem($pdo, $perguntaId);
        $total    = array_sum($contagem);

        // Dia fraco não paga: com um voto só, quem jogasse sozinho colhia
        // 100 moedas por dia sem disputar com ninguém.
        $vencedoras = [];
        if ($total >= QUIZ_MIN_VOTOS) {
            $max = max($contagem);
            // Empate paga os dois lados — a regra é "votou com a maioria",
            // e num empate os dois grupos são a maioria.
            foreach ($contagem as $op => $n) if ($n === $max) $vencedoras[] = $op;
        }

        $pdo->prepare("UPDATE quiz_perguntas SET vencedoras = ?, total_votos = ? WHERE id = ?")
            ->execute([json_encode($vencedoras), $total, $perguntaId]);

        if ($vencedoras) {
            $in = implode(',', array_fill(0, count($vencedoras), '?'));
            $pdo->prepare("UPDATE games_usuarios g
                           JOIN quiz_votos v ON v.id_usuario = g.id
                           SET g.pontos = g.pontos + ?, v.pago = 1
                           WHERE v.pergunta_id = ? AND v.opcao IN ($in) AND v.pago = 0")
                ->execute(array_merge([QUIZ_PREMIO, $perguntaId], $vencedoras));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[quiz] resolver ' . $perguntaId . ': ' . $e->getMessage());
    }
}

/** Apura tudo que já virou o dia e ainda não foi fechado. */
function quizResolverPendentes(PDO $pdo): void
{
    $st = $pdo->prepare("SELECT id FROM quiz_perguntas
                         WHERE data_uso IS NOT NULL AND data_uso < ? AND resolvido_em IS NULL
                         ORDER BY data_uso LIMIT 20");
    $st->execute([quizHoje()]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) quizResolver($pdo, (int)$id);
}

/** A pergunta de hoje, estreando a próxima da fila se ainda não teve. */
function quizPerguntaDeHoje(PDO $pdo): ?array
{
    $hoje = quizHoje();
    $st = $pdo->prepare("SELECT * FROM quiz_perguntas WHERE data_uso = ? LIMIT 1");
    $st->execute([$hoje]);
    if ($p = $st->fetch(PDO::FETCH_ASSOC)) return $p;

    try {
        $pdo->prepare("UPDATE quiz_perguntas SET data_uso = ?
                       WHERE data_uso IS NULL ORDER BY ordem, id LIMIT 1")->execute([$hoje]);
    } catch (PDOException $e) {
        error_log('[quiz] estrear pergunta: ' . $e->getMessage());
    }
    $st->execute([$hoje]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function quizMeuVoto(PDO $pdo, int $perguntaId, int $uid): ?int
{
    $st = $pdo->prepare("SELECT opcao FROM quiz_votos WHERE pergunta_id = ? AND id_usuario = ? LIMIT 1");
    $st->execute([$perguntaId, $uid]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int)$v;
}

// ── Fluxo ──────────────────────────────────────────────────────────────
quizResolverPendentes($pdo);
$pergunta = quizPerguntaDeHoje($pdo);
$meuVoto  = $pergunta ? quizMeuVoto($pdo, (int)$pergunta['id'], $idUsuario) : null;

// Votar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $opcao = isset($_POST['opcao']) ? (int)$_POST['opcao'] : -1;

    if (!$pergunta)                          { echo json_encode(['ok'=>false,'erro'=>'não tem pergunta hoje']); exit; }
    if (!empty($pergunta['resolvido_em']))   { echo json_encode(['ok'=>false,'erro'=>'a votação de hoje já fechou']); exit; }
    if ($opcao < 0 || $opcao >= count(quizOpcoes($pergunta))) {
        echo json_encode(['ok'=>false,'erro'=>'opção inválida']); exit;
    }
    if ($meuVoto !== null)                   { echo json_encode(['ok'=>false,'erro'=>'você já votou hoje']); exit; }

    // INSERT IGNORE + UNIQUE(pergunta_id, id_usuario): dois cliques rápidos
    // não viram dois votos, e o voto é final — trocar depois de ver o
    // placar acabaria com a graça de adivinhar a maioria.
    $st = $pdo->prepare("INSERT IGNORE INTO quiz_votos (pergunta_id, id_usuario, opcao) VALUES (?,?,?)");
    $st->execute([(int)$pergunta['id'], $idUsuario, $opcao]);
    echo json_encode(['ok' => true]);
    exit;
}

// Placar ao vivo — só pra quem já votou.
if (isset($_GET['placar'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pergunta || $meuVoto === null) { echo json_encode(['ok'=>false]); exit; }
    echo json_encode(['ok'=>true, 'contagem'=>quizContagem($pdo, (int)$pergunta['id'])]);
    exit;
}

$contagem = ($pergunta && $meuVoto !== null) ? quizContagem($pdo, (int)$pergunta['id']) : [];

// Último resultado apurado, pra mostrar como foi ontem.
$ultimo = $pdo->query("SELECT * FROM quiz_perguntas WHERE resolvido_em IS NOT NULL
                       ORDER BY data_uso DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
$ultimoContagem = $ultimo ? quizContagem($pdo, (int)$ultimo['id']) : [];
$ultimoMeuVoto  = $ultimo ? quizMeuVoto($pdo, (int)$ultimo['id'], $idUsuario) : null;

$stPontos = $pdo->prepare("SELECT pontos FROM games_usuarios WHERE id = ?");
$stPontos->execute([$idUsuario]);
$pontosUsuario = (int)($stPontos->fetchColumn() ?: 0);

$naFila = (int)$pdo->query("SELECT COUNT(*) FROM quiz_perguntas WHERE data_uso IS NULL")->fetchColumn();
$souAdmin = (int)($_SESSION['is_admin'] ?? 0) === 1;

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>Quiz do Dia — FBA Games</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
/* Mesmos tokens dos outros jogos (base: buildplayer.php / caminho.php). */
:root{
  --bg:#07070a;--panel:#101013;--panel2:#16161a;--panel3:#1c1c21;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.14);
  --red:#fc0025;--red-soft:rgba(252,0,37,.12);--red-glow:rgba(252,0,37,.25);
  --text:#f0f0f3;--text2:#868690;--text3:#3c3c44;
  --green:#22c55e;--green-soft:rgba(34,197,94,.12);
  --amber:#f59e0b;--amber-soft:rgba(245,158,11,.12);
  --radius:14px;
  --font:'Poppins',sans-serif;
  --num:ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,monospace;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;
  -webkit-font-smoothing:antialiased;overflow-x:hidden}

.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;
  background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.topbar-left{display:flex;align-items:center;gap:10px}
.back-btn{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;
  border:1px solid var(--border);background:transparent;color:var(--text2);text-decoration:none;
  font-size:14px;transition:.2s;flex-shrink:0}
.back-btn:hover{border-color:var(--red);color:var(--red)}
.game-title{font-size:15px;font-weight:800;color:var(--text)}
.game-title span{color:var(--red)}
.daily-badge{margin-left:7px;font-size:9px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;
  color:var(--amber);background:var(--amber-soft);border:1px solid rgba(245,158,11,.3);
  padding:2px 7px;border-radius:20px;vertical-align:middle}
.topbar-right{display:flex;align-items:center;gap:6px}
.chip{display:flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;background:var(--panel2);
  border:1px solid var(--border);font-size:11px;font-weight:700;color:var(--amber);white-space:nowrap}
.chip b{font-family:var(--num);font-variant-numeric:tabular-nums;font-weight:700}

.main{max-width:520px;margin:0 auto;padding:14px 14px 40px}
.card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);
  padding:16px;margin-bottom:14px}
.card-title{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;
  color:var(--text2);margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;gap:8px}
.pergunta{font-size:18px;font-weight:800;line-height:1.35;letter-spacing:-.3px;margin-bottom:14px}

/* Opções ainda votáveis */
.op{display:flex;align-items:center;gap:11px;width:100%;text-align:left;background:var(--panel2);
  color:var(--text);border:1px solid var(--border);border-radius:11px;padding:13px 14px;margin-bottom:8px;
  font-family:var(--font);font-size:14px;font-weight:700;cursor:pointer;transition:.15s}
.op:hover{border-color:var(--red);background:var(--red-soft);transform:translateY(-1px)}
.op:disabled{opacity:.5;cursor:default;transform:none}
.op-letra{display:flex;align-items:center;justify-content:center;width:24px;height:24px;flex:none;
  border-radius:7px;background:var(--panel3);color:var(--text2);font-family:var(--num);
  font-size:11px;font-weight:700}

/* Opções já apuradas ou em contagem ao vivo */
.res{position:relative;overflow:hidden;border:1px solid var(--border);border-radius:11px;
  background:var(--panel2);padding:12px 14px;margin-bottom:8px}
.res-barra{position:absolute;inset:0 auto 0 0;background:var(--panel3);transition:width .5s ease}
.res.venceu{border-color:var(--green);background:var(--green-soft)}
.res.venceu .res-barra{background:rgba(34,197,94,.18)}
.res.meu{border-color:var(--red)}
.res.meu.venceu{border-color:var(--green)}
.res-linha{position:relative;display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:700}
.res-txt{flex:1;min-width:0}
.res-n{font-family:var(--num);font-variant-numeric:tabular-nums;font-size:13px;color:var(--text2)}
.res-pct{font-family:var(--num);font-variant-numeric:tabular-nums;font-size:13px;font-weight:800;min-width:42px;text-align:right}
.selo-meu{font-size:8.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;
  color:var(--red);background:var(--red-soft);border:1px solid var(--red-glow);padding:2px 6px;border-radius:20px}

.aviso{display:flex;align-items:flex-start;gap:10px;background:var(--panel2);border:1px solid var(--border);
  border-radius:11px;padding:12px 13px;font-size:12.5px;line-height:1.5;color:var(--text2)}
.aviso i{color:var(--amber);font-size:15px;line-height:1.2;flex:none}
.aviso.ok{border-color:rgba(34,197,94,.3);background:var(--green-soft);color:var(--text)}
.aviso.ok i{color:var(--green)}
.aviso b{color:var(--text)}

.rodape{font-size:11.5px;color:var(--text2);line-height:1.6;text-align:center;padding:0 8px}
.rodape b{color:var(--text)}
.vazio{text-align:center;padding:20px 0}
.vazio i{font-size:34px;color:var(--text3);display:block;margin-bottom:10px}
.vazio p{font-size:13px;color:var(--text2);line-height:1.5}
.link-admin{display:inline-block;margin-top:12px;font-size:12px;font-weight:700;color:var(--red);text-decoration:none}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <a href="/games.php" class="back-btn" title="Voltar"><i class="bi bi-arrow-left"></i></a>
    <span class="game-title">Quiz do <span>Dia</span><span class="daily-badge">diário</span></span>
  </div>
  <div class="topbar-right">
    <div class="chip"><i class="bi bi-coin"></i><b id="moedas"><?= $pontosUsuario ?></b></div>
  </div>
</div>

<div class="main">

<?php if (!$pergunta): ?>
  <div class="card">
    <div class="vazio">
      <i class="bi bi-inbox"></i>
      <p>A fila de perguntas acabou.<br>Volte amanhã — ou avise um admin.</p>
      <?php if ($souAdmin): ?>
        <a class="link-admin" href="/games/admin/quiz-admin.php">Cadastrar perguntas &rsaquo;</a>
      <?php endif; ?>
    </div>
  </div>

<?php else: $opcoes = quizOpcoes($pergunta); ?>

  <div class="card">
    <div class="card-title">
      <span>Pergunta de hoje</span>
      <span id="prazo" style="color:var(--text3)"></span>
    </div>
    <div class="pergunta"><?= e($pergunta['pergunta']) ?></div>

    <?php if ($meuVoto === null): ?>
      <?php foreach ($opcoes as $i => $op): ?>
        <button class="op" data-opcao="<?= $i ?>">
          <span class="op-letra"><?= chr(65 + $i) ?></span>
          <span><?= e($op) ?></span>
        </button>
      <?php endforeach; ?>
      <div class="aviso" style="margin-top:12px">
        <i class="bi bi-exclamation-circle-fill"></i>
        <span>O voto é <b>definitivo</b> e o placar só aparece depois dele — a graça é adivinhar
        a maioria, não seguir ela.</span>
      </div>

    <?php else: $total = max(1, array_sum($contagem)); ?>
      <div id="placar">
        <?php foreach ($opcoes as $i => $op):
          $n = $contagem[$i] ?? 0; $pct = round($n * 100 / $total); ?>
          <div class="res <?= $i === $meuVoto ? 'meu' : '' ?>">
            <div class="res-barra" style="width:<?= $pct ?>%"></div>
            <div class="res-linha">
              <span class="res-txt"><?= e($op) ?></span>
              <?php if ($i === $meuVoto): ?><span class="selo-meu">seu voto</span><?php endif; ?>
              <span class="res-n"><?= $n ?></span>
              <span class="res-pct"><?= $pct ?>%</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="aviso" style="margin-top:12px">
        <i class="bi bi-hourglass-split"></i>
        <span>Voto registrado. À meia-noite a opção mais votada leva
        <b><?= QUIZ_PREMIO ?> moedas</b> pra todo mundo que votou nela.</span>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($ultimo):
  $opUlt = quizOpcoes($ultimo);
  $venc  = quizVencedoras($ultimo);
  $totUlt = max(1, array_sum($ultimoContagem));
  $ganhei = $ultimoMeuVoto !== null && in_array($ultimoMeuVoto, $venc, true);
  $dataUlt = DateTime::createFromFormat('Y-m-d', (string)$ultimo['data_uso']);
?>
  <div class="card">
    <div class="card-title">
      <span>Resultado de <?= $dataUlt ? e($dataUlt->format('d/m')) : 'ontem' ?></span>
      <span style="color:var(--text3)"><?= (int)$ultimo['total_votos'] ?> voto<?= (int)$ultimo['total_votos'] === 1 ? '' : 's' ?></span>
    </div>
    <div class="pergunta" style="font-size:15px"><?= e($ultimo['pergunta']) ?></div>

    <?php foreach ($opUlt as $i => $op):
      $n = $ultimoContagem[$i] ?? 0; $pct = round($n * 100 / $totUlt);
      $cls = (in_array($i, $venc, true) ? 'venceu ' : '') . ($i === $ultimoMeuVoto ? 'meu' : ''); ?>
      <div class="res <?= trim($cls) ?>">
        <div class="res-barra" style="width:<?= $pct ?>%"></div>
        <div class="res-linha">
          <span class="res-txt"><?= e($op) ?></span>
          <?php if ($i === $ultimoMeuVoto): ?><span class="selo-meu">seu voto</span><?php endif; ?>
          <span class="res-n"><?= $n ?></span>
          <span class="res-pct"><?= $pct ?>%</span>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($ganhei): ?>
      <div class="aviso ok" style="margin-top:12px">
        <i class="bi bi-check-circle-fill"></i>
        <span>Você votou com a maioria e ganhou <b><?= QUIZ_PREMIO ?> moedas</b>.</span>
      </div>
    <?php elseif ($ultimoMeuVoto !== null && !$venc): ?>
      <div class="aviso" style="margin-top:12px">
        <i class="bi bi-dash-circle-fill"></i>
        <span>Poucos votos naquele dia — precisa de pelo menos
        <b><?= QUIZ_MIN_VOTOS ?></b> pra valer prêmio.</span>
      </div>
    <?php elseif ($ultimoMeuVoto !== null): ?>
      <div class="aviso" style="margin-top:12px">
        <i class="bi bi-x-circle-fill"></i>
        <span>Dessa vez a maioria foi por outro lado. Amanhã tem mais.</span>
      </div>
    <?php else: ?>
      <div class="aviso" style="margin-top:12px">
        <i class="bi bi-eye-fill"></i>
        <span>Você não votou nesse dia.</span>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

  <p class="rodape">
    Uma pergunta por dia, cinco opções, sem resposta certa.<br>
    Quem votar na <b>mais votada</b> leva <b><?= QUIZ_PREMIO ?> moedas</b> na virada do dia.
    <?php if ($souAdmin && $naFila <= 5): ?>
      <br><a class="link-admin" href="/games/admin/quiz-admin.php">
        <?= $naFila ?> pergunta<?= $naFila === 1 ? '' : 's' ?> na fila — cadastrar mais &rsaquo;</a>
    <?php endif; ?>
  </p>
</div>

<script>
// ── Voto ──────────────────────────────────────────────────────────────
document.querySelectorAll(".op").forEach(b => b.addEventListener("click", async () => {
  document.querySelectorAll(".op").forEach(x => x.disabled = true);
  try {
    const r = await fetch(location.href, {
      method:"POST",
      headers:{"Content-Type":"application/x-www-form-urlencoded"},
      body:"opcao=" + encodeURIComponent(b.dataset.opcao)
    }).then(r => r.json());
    if (!r.ok) { alert(r.erro || "não deu pra registrar o voto"); document.querySelectorAll(".op").forEach(x => x.disabled = false); return; }
    location.reload();
  } catch (e) {
    alert("falha de conexão");
    document.querySelectorAll(".op").forEach(x => x.disabled = false);
  }
}));

// ── Quanto falta pra virar o dia ──────────────────────────────────────
// Meia-noite de Brasília, não do relógio de quem está olhando: quem abrir
// de outro fuso veria a contagem errada.
const prazo = document.getElementById("prazo");
function brasilia(){
  return new Date(new Date().toLocaleString("en-US", {timeZone:"America/Sao_Paulo"}));
}
function tick(){
  if (!prazo) return;
  const agora = brasilia();
  const fim = new Date(agora); fim.setHours(24,0,0,0);
  const s = Math.max(0, Math.floor((fim - agora)/1000));
  const h = Math.floor(s/3600), m = Math.floor(s%3600/60);
  prazo.textContent = h > 0 ? `fecha em ${h}h${String(m).padStart(2,"0")}` : `fecha em ${m}min`;
}
tick(); setInterval(tick, 30000);

// ── Placar ao vivo, só pra quem já votou ──────────────────────────────
const placar = document.getElementById("placar");
if (placar) setInterval(async () => {
  try {
    const r = await fetch(location.pathname + "?placar=1").then(r => r.json());
    if (!r.ok) return;
    const total = Math.max(1, Object.values(r.contagem).reduce((a,b) => a+b, 0));
    placar.querySelectorAll(".res").forEach((el, i) => {
      const n = r.contagem[i] || 0, pct = Math.round(n*100/total);
      el.querySelector(".res-barra").style.width = pct + "%";
      el.querySelector(".res-n").textContent = n;
      el.querySelector(".res-pct").textContent = pct + "%";
    });
  } catch (e) {}
}, 25000);
</script>
</body>
</html>
