<?php
// ranking.php - CLASSIFICAÇÃO GERAL
session_start();
require '../core/conexao.php';
require '../core/avatar.php';
require '../core/sequencia_dias.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$hiddenRankingEmailLower = 'medeirros99@gmail.com';

try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos, is_admin, fba_points FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar perfil: " . $e->getMessage());
}

$id_rei_xadrez = null;
$id_rei_pinguim = null;
$id_rei_flappy = null;
$id_rei_pnip = null;

try {
    $stmtChess = $pdo->query("SELECT vencedor FROM xadrez_partidas WHERE status = 'finalizada' GROUP BY vencedor ORDER BY COUNT(*) DESC LIMIT 1");
    $id_rei_xadrez = $stmtChess->fetchColumn();
    $stmtDino = $pdo->query("SELECT id_usuario FROM dino_historico GROUP BY id_usuario ORDER BY MAX(pontuacao_final) DESC LIMIT 1");
    $id_rei_pinguim = $stmtDino->fetchColumn();
    $stmtFlappy = $pdo->query("SELECT id_usuario FROM flappy_historico ORDER BY pontuacao DESC LIMIT 1");
    $id_rei_flappy = $stmtFlappy->fetchColumn();
    $stmtPNIP = $pdo->query("SELECT vencedor_id FROM naval_salas WHERE status = 'fim' AND vencedor_id IS NOT NULL GROUP BY vencedor_id ORDER BY COUNT(*) DESC LIMIT 1");
    $id_rei_pnip = $stmtPNIP->fetchColumn();
} catch (Exception $e) {
}

$sequencias_usuario = [];
try {
    $stmt = $pdo->query("SELECT user_id, jogo, sequencia_atual FROM usuario_sequencias_dias WHERE sequencia_atual > 0");
    $todas_sequencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($todas_sequencias as $seq) {
        $uid = $seq['user_id'];
        if (!isset($sequencias_usuario[$uid])) $sequencias_usuario[$uid] = [];
        $sequencias_usuario[$uid][$seq['jogo']] = $seq['sequencia_atual'];
    }
} catch (Exception $e) {
    $sequencias_usuario = [];
}

$maior_cafe = null;
try {
    $stmt = $pdo->query("SELECT id, nome, cafes_feitos FROM usuarios WHERE cafes_feitos > 0 ORDER BY cafes_feitos DESC LIMIT 1");
    $maior_cafe = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $maior_cafe = null;
}

try {
    $sql = "SELECT u.id, u.nome, u.pontos, (u.pontos - 50) as lucro_liquido
            FROM usuarios u
            WHERE LOWER(u.email) <> :hidden_email
            ORDER BY lucro_liquido DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':hidden_email' => $hiddenRankingEmailLower]);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar ranking: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#fc0025">
  <title>Ranking - FBA Games</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root {
      --red:        #fc0025;
      --red-soft:   rgba(252,0,37,.10);
      --bg:         #07070a;
      --panel:      #101013;
      --panel-2:    #16161a;
      --panel-3:    #1c1c21;
      --border:     rgba(255,255,255,.06);
      --border-md:  rgba(255,255,255,.10);
      --border-red: rgba(252,0,37,.22);
      --text:       #f0f0f3;
      --text-2:     #868690;
      --text-3:     #48484f;
      --amber:      #f59e0b;
      --green:      #22c55e;
      --font:       'Poppins', sans-serif;
      --radius:     14px;
      --radius-sm:  10px;
      --ease:       cubic-bezier(.2,.8,.2,1);
      --t:          200ms;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

    .topbar {
      position: sticky; top: 0; z-index: 300; height: 58px;
      background: var(--panel); border-bottom: 1px solid var(--border);
      display: flex; align-items: center; padding: 0 24px; gap: 16px;
    }
    .topbar-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; flex-shrink: 0; }
    .topbar-logo {
      width: 32px; height: 32px; border-radius: 8px; background: var(--red);
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 12px; color: #fff;
    }
    .topbar-name { font-weight: 800; font-size: 15px; color: var(--text); }
    .topbar-name span { color: var(--red); }
    .topbar-spacer { flex: 1; }
    .topbar-balances { display: flex; align-items: center; gap: 8px; }
    .balance-chip {
      display: flex; align-items: center; gap: 6px;
      background: var(--panel-2); border: 1px solid var(--border);
      border-radius: 999px; padding: 5px 12px;
      font-size: 12px; font-weight: 700; color: var(--text);
    }
    .balance-chip i { color: var(--red); font-size: 13px; }
    .balance-chip.fba i { color: var(--amber); }
    .topbar-actions { display: flex; align-items: center; gap: 6px; }
    .icon-btn {
      width: 32px; height: 32px; border-radius: 8px;
      background: transparent; border: 1px solid var(--border);
      color: var(--text-2); display: flex; align-items: center; justify-content: center;
      font-size: 14px; cursor: pointer; text-decoration: none;
      transition: all var(--t) var(--ease);
    }
    .icon-btn:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

    .main { max-width: 860px; margin: 0 auto; padding: 28px 20px 60px; }

    .section-label {
      display: flex; align-items: center; gap: 8px;
      font-size: 10px; font-weight: 700; letter-spacing: 1.2px;
      text-transform: uppercase; color: var(--text-3);
      margin-bottom: 18px; margin-top: 28px;
    }
    .section-label i { color: var(--red); font-size: 13px; }

    .ranking-panel {
      background: var(--panel); border: 1px solid var(--border);
      border-radius: var(--radius); overflow: hidden;
    }
    .ranking-panel-head {
      padding: 20px 20px 14px;
      border-bottom: 1px solid var(--border);
    }
    .ranking-panel-head h4 { font-size: 15px; font-weight: 800; color: var(--text); margin: 0 0 2px; }
    .ranking-panel-head p { font-size: 12px; color: var(--text-3); margin: 0; }

    .col-heads {
      display: grid; grid-template-columns: 56px 1fr 120px 120px;
      align-items: center; gap: 10px;
      padding: 10px 16px; border-bottom: 1px solid var(--border);
      font-size: 10px; font-weight: 700; text-transform: uppercase;
      letter-spacing: .6px; color: var(--text-3);
    }

    .player-row {
      display: grid; grid-template-columns: 56px 1fr 120px 120px;
      align-items: center; gap: 10px;
      padding: 12px 16px; border-bottom: 1px solid var(--border);
      transition: background var(--t) var(--ease);
    }
    .player-row:last-child { border-bottom: none; }
    .player-row:hover { background: var(--panel-2); }
    .player-row.me { background: rgba(252,0,37,.06); border-color: var(--border-red); }

    .player-pos {
      display: flex; align-items: center; justify-content: center;
      font-size: 18px;
    }
    .player-pos.num { font-size: 13px; font-weight: 700; color: var(--text-3); }

    .player-info { display: flex; align-items: center; gap: 10px; min-width: 0; }
    .player-name-wrap { min-width: 0; }
    .player-name {
      font-size: 13px; font-weight: 600; color: var(--text);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .player-tags { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
    .tag {
      font-size: 10px; font-weight: 700; padding: 2px 7px;
      border-radius: 999px; white-space: nowrap;
    }
    .tag-xadrez  { background: rgba(245,158,11,.12); color: #fbbf24; border: 1px solid rgba(245,158,11,.2); }
    .tag-pinguim { background: rgba(6,182,212,.12); color: #22d3ee; border: 1px solid rgba(6,182,212,.2); }
    .tag-flappy  { background: rgba(249,115,22,.12); color: #fb923c; border: 1px solid rgba(249,115,22,.2); }
    .tag-naval   { background: rgba(59,130,246,.12); color: #60a5fa; border: 1px solid rgba(59,130,246,.2); }
    .tag-termo   { background: rgba(168,85,247,.12); color: #c084fc; border: 1px solid rgba(168,85,247,.2); }
    .tag-memoria { background: rgba(34,197,94,.12); color: #4ade80; border: 1px solid rgba(34,197,94,.2); }
    .tag-cafe    { background: rgba(180,83,9,.12); color: #d97706; border: 1px solid rgba(180,83,9,.2); }
    .tag-voce    { background: var(--red-soft); color: var(--red); border: 1px solid var(--border-red); }

    .player-val {
      font-size: 13px; font-weight: 600; text-align: right; color: var(--text-2);
    }
    .player-val.lucro-pos  { color: var(--red); font-weight: 700; }
    .player-val.lucro-neg  { color: #f87171; }
    .player-val.lucro-zero { color: var(--text-3); }

    .empty-state {
      text-align: center; padding: 56px 20px; color: var(--text-3);
    }
    .empty-state i { font-size: 32px; margin-bottom: 10px; display: block; }
    .empty-state p { font-size: 13px; margin: 0; }

    @media (max-width: 600px) {
      .col-heads { display: none; }
      .player-row { grid-template-columns: 44px 1fr; }
      .player-row .player-val { display: none; }
      .balance-chip.fba { display: none; }
    }
  </style>
</head>
<body>

<div class="topbar">
  <a href="../index.php" class="topbar-brand">
    <div class="topbar-logo">FBA</div>
    <span class="topbar-name">FBA <span>Games</span></span>
  </a>
  <div class="topbar-spacer"></div>
  <div class="topbar-balances">
    <div class="balance-chip">
      <i class="bi bi-coin"></i>
      <?= number_format($meu_perfil['pontos'], 0, ',', '.') ?> moedas
    </div>
    <div class="balance-chip fba">
      <i class="bi bi-gem"></i>
      <?= number_format($meu_perfil['fba_points'] ?? 0, 0, ',', '.') ?> FBA
    </div>
  </div>
  <div class="topbar-actions">
    <?php if (!empty($meu_perfil['is_admin']) && $meu_perfil['is_admin'] == 1): ?>
      <a href="../admin/dashboard.php" class="icon-btn" title="Admin"><i class="bi bi-gear-fill"></i></a>
    <?php endif; ?>
    <a href="../index.php" class="icon-btn" title="Voltar"><i class="bi bi-arrow-left"></i></a>
    <a href="../auth/logout.php" class="icon-btn" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
  </div>
</div>

<div class="main">
  <div class="section-label" style="margin-top:0"><i class="bi bi-trophy-fill"></i>Ranking Global</div>

  <div class="ranking-panel">
    <div class="ranking-panel-head">
      <h4>Classificação Geral</h4>
      <p>Lucro acumulado · base 50 pts</p>
    </div>

    <?php if (empty($usuarios)): ?>
      <div class="empty-state">
        <i class="bi bi-ghost"></i>
        <p>Nenhum jogador no ranking ainda.</p>
      </div>
    <?php else: ?>
      <div class="col-heads">
        <span style="text-align:center">#</span>
        <span>Jogador</span>
        <span style="text-align:right">Moedas</span>
        <span style="text-align:right">Lucro</span>
      </div>
      <?php
      $posicao = 1;
      foreach ($usuarios as $user):
        $lucro = $user['lucro_liquido'];
        $lucroCls = $lucro > 0 ? 'lucro-pos' : ($lucro < 0 ? 'lucro-neg' : 'lucro-zero');
        $sinal = $lucro > 0 ? '+' : '';
        $isMe = $user['id'] == $user_id;
        $medals = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
        $avatar = obterCustomizacaoAvatar($pdo, $user['id']);
      ?>
      <div class="player-row <?= $isMe ? 'me' : '' ?>">
        <div class="player-pos <?= $posicao > 3 ? 'num' : '' ?>">
          <?= isset($medals[$posicao]) ? $medals[$posicao] : '#'.$posicao ?>
        </div>
        <div class="player-info">
          <?= avatarHTML($avatar, 'mini') ?>
          <div class="player-name-wrap">
            <div class="player-name"><?= htmlspecialchars($user['nome']) ?></div>
            <div class="player-tags">
              <?php if ($user['id'] == $id_rei_xadrez): ?>
                <span class="tag tag-xadrez">♟️ KING</span>
              <?php endif; ?>
              <?php if ($user['id'] == $id_rei_pinguim): ?>
                <span class="tag tag-pinguim">🐧 PRO</span>
              <?php endif; ?>
              <?php if ($user['id'] == $id_rei_flappy): ?>
                <span class="tag tag-flappy">🐦 FLY</span>
              <?php endif; ?>
              <?php if ($user['id'] == $id_rei_pnip): ?>
                <span class="tag tag-naval">🚢 NAVAL</span>
              <?php endif; ?>
              <?php if (!empty($sequencias_usuario[$user['id']]['termo'])): ?>
                <span class="tag tag-termo">📝 Termo x<?= $sequencias_usuario[$user['id']]['termo'] ?></span>
              <?php endif; ?>
              <?php if (!empty($sequencias_usuario[$user['id']]['memoria'])): ?>
                <span class="tag tag-memoria">🧠 Memória x<?= $sequencias_usuario[$user['id']]['memoria'] ?></span>
              <?php endif; ?>
              <?php if ($maior_cafe && $maior_cafe['id'] == $user['id'] && $maior_cafe['cafes_feitos'] > 0): ?>
                <span class="tag tag-cafe">☕ Café x<?= $maior_cafe['cafes_feitos'] ?></span>
              <?php endif; ?>
              <?php if ($isMe): ?>
                <span class="tag tag-voce">você</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="player-val"><?= number_format($user['pontos'], 0, ',', '.') ?></div>
        <div class="player-val <?= $lucroCls ?>"><?= $sinal . number_format($lucro, 0, ',', '.') ?></div>
      </div>
      <?php
        $posicao++;
      endforeach;
      ?>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
  tooltipTriggerList.map(function(el) { return new bootstrap.Tooltip(el); });
</script>
</body>
</html>
