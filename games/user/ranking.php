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
    $stmtMe = $pdo->prepare("SELECT nome, pontos, is_admin, fba_points, COALESCE(numero_tapas, 0) as numero_tapas FROM usuarios WHERE id = :id");
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
    }

    /* ── Sidebar Layout ── */
    .page-layout { display: flex; min-height: 100vh; }
    .sidebar {
      width: 240px; flex-shrink: 0;
      background: var(--panel); border-right: 1px solid var(--border);
      display: flex; flex-direction: column;
      position: fixed; top: 0; left: 0; bottom: 0; z-index: 200;
      overflow-y: auto;
    }
    .page-content { flex: 1; margin-left: 240px; min-width: 0; }
    .sb-header {
      display: flex; align-items: center; gap: 10px;
      padding: 16px 14px 12px; border-bottom: 1px solid var(--border); flex-shrink: 0;
    }
    .sb-logo {
      width: 30px; height: 30px; border-radius: 8px; background: var(--red);
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 11px; color: #fff; flex-shrink: 0;
    }
    .sb-brand { font-weight: 800; font-size: 13px; color: var(--text); flex: 1; }
    .sb-brand span { color: var(--red); }
    .sb-close { display: none; background: none; border: none; color: var(--text-2); font-size: 18px; cursor: pointer; padding: 4px; }
    .sb-user { padding: 14px; border-bottom: 1px solid var(--border); }
    .sb-avatar {
      width: 40px; height: 40px; border-radius: 50%;
      background: var(--red-soft); border: 2px solid var(--border-red);
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 15px; color: var(--red); margin-bottom: 8px;
    }
    .sb-user-name { font-size: 13px; font-weight: 700; color: #fff; }
    .sb-user-role { font-size: 10px; color: var(--text-3); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
    .sb-stats { padding: 10px 14px; border-bottom: 1px solid var(--border); display: flex; flex-direction: row; gap: 6px; }
    .sb-stat { display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1; gap: 4px; padding: 8px 4px; background: var(--panel-2); border-radius: 8px; border: 1px solid var(--border); }
    .sb-stat i { font-size: 13px; color: var(--red); }
    .sb-stat-info { display: flex; flex-direction: column; align-items: center; }
    .sb-stat-val { font-size: 12px; font-weight: 700; color: var(--text); line-height: 1.2; }
    .sb-stat-label { font-size: 9px; color: var(--text-3); }
    .sb-nav { flex: 1; padding: 8px 0; overflow-y: auto; }
    .sb-nav-section { font-size: 9px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 8px 14px 4px; }
    .sb-link {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 14px; text-decoration: none;
      font-size: 12px; font-weight: 500; color: var(--text-2);
      transition: all var(--t) var(--ease); border-left: 3px solid transparent;
    }
    .sb-link i { width: 16px; text-align: center; font-size: 13px; }
    .sb-link:hover { background: var(--panel-2); color: var(--text); border-left-color: var(--border-md); }
    .sb-link.active { background: var(--red-soft); color: var(--red); border-left-color: var(--red); font-weight: 700; }
    .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); flex-shrink: 0; }
    .sb-logout {
      display: flex; align-items: center; gap: 8px;
      width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border);
      background: transparent; color: var(--text-2); text-decoration: none;
      font-family: var(--font); font-size: 12px; font-weight: 600;
      transition: all var(--t) var(--ease);
    }
    .sb-logout:hover { background: rgba(252,0,37,.1); border-color: var(--border-red); color: var(--red); }
    .mob-bar {
      display: none; align-items: center; gap: 12px;
      height: 52px; padding: 0 14px;
      background: var(--panel); border-bottom: 1px solid var(--border);
      position: sticky; top: 0; z-index: 100;
    }
    .mob-ham {
      background: none; border: 1px solid var(--border); border-radius: 8px;
      color: var(--text); width: 34px; height: 34px;
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; font-size: 16px; flex-shrink: 0;
    }
    .mob-title { font-size: 14px; font-weight: 800; color: var(--text); flex: 1; }
    .mob-title span { color: var(--red); }
    .mob-chips { display: flex; align-items: center; gap: 6px; }
    .mob-chip { display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 20px; background: var(--panel-2); border: 1px solid var(--border); font-size: 11px; font-weight: 700; color: var(--text); white-space: nowrap; }
    .mob-chip i { font-size: 11px; }
    .mob-back {
      width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border);
      background: transparent; color: var(--text-2);
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; text-decoration: none; transition: all var(--t) var(--ease);
    }
    .mob-back:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }
    .sb-overlay {
      display: none; position: fixed; inset: 0; background: rgba(0,0,0,.6);
      z-index: 199; backdrop-filter: blur(2px);
    }
    @media (max-width: 768px) {
      .sidebar { transform: translateX(-100%); transition: transform 280ms var(--ease); }
      .sidebar.open { transform: translateX(0); }
      .page-content { margin-left: 0; }
      .mob-bar { display: flex; }
      .sb-close { display: flex; align-items: center; justify-content: center; }
      .sb-overlay.open { display: block; }
    }
  </style>
</head>
<body>

<div class="page-layout">
<div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sb-header">
    <div class="sb-logo">FBA</div>
    <span class="sb-brand">FBA <span>Games</span></span>
    <button class="sb-close" onclick="closeSidebar()"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="sb-user">
    <div class="sb-avatar"><?= strtoupper(substr($meu_perfil['nome'] ?? 'U', 0, 1)) ?></div>
    <div class="sb-user-name"><?= htmlspecialchars($meu_perfil['nome'] ?? '') ?></div>
    <div class="sb-user-role"><?= !empty($meu_perfil['is_admin']) ? 'Admin' : 'Jogador' ?></div>
  </div>
  <div class="sb-stats">
    <div class="sb-stat">
      <i class="bi bi-hand-index-fill" style="color:var(--green)"></i>
      <div class="sb-stat-val"><?= number_format($meu_perfil['numero_tapas'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">Tapas</div>
    </div>
    <div class="sb-stat">
      <i class="bi bi-coin" style="color:var(--amber)"></i>
      <div class="sb-stat-val"><?= number_format($meu_perfil['pontos'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">Moedas</div>
    </div>
    <div class="sb-stat">
      <i class="bi bi-gem" style="color:#a78bfa"></i>
      <div class="sb-stat-val"><?= number_format($meu_perfil['fba_points'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">FBA Pts</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-section">Menu</div>
    <a href="../index.php" class="sb-link">
      <i class="bi bi-lightning-charge"></i>Apostas
    </a>
    <a href="../games.php" class="sb-link">
      <i class="bi bi-joystick"></i>Games
    </a>
    <a href="ranking-geral.php" class="sb-link active">
      <i class="bi bi-trophy"></i>Ranking Geral
    </a>
    <?php if (!empty($meu_perfil['is_admin'])): ?>
    <div class="sb-nav-section">Admin</div>
    <a href="../admin/controlegames.php" class="sb-link">
      <i class="bi bi-gear-fill"></i>Controle de Jogos
    </a>
    <a href="../admin/dashboard.php" class="sb-link">
      <i class="bi bi-receipt-cutoff"></i>Controle Apostas
    </a>
    <?php endif; ?>
  </nav>
  <div class="sb-footer">
    <a href="../auth/logout.php" class="sb-logout">
      <i class="bi bi-box-arrow-right"></i>Sair
    </a>
  </div>
</aside>

<div class="page-content">
<div class="mob-bar">
  <button class="mob-ham" onclick="openSidebar()"><i class="bi bi-list"></i></button>
  <span class="mob-title">FBA <span>Games</span></span>
  <div class="mob-chips">
    <span class="mob-chip"><i class="bi bi-coin" style="color:var(--amber)"></i><?= number_format($meu_perfil['pontos'] ?? 0, 0, ',', '.') ?></span>
    <span class="mob-chip"><i class="bi bi-gem" style="color:#a78bfa"></i><?= number_format($meu_perfil['fba_points'] ?? 0, 0, ',', '.') ?></span>
  </div>
  <a href="../index.php" class="mob-back" title="Voltar"><i class="bi bi-arrow-left"></i></a>
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

</div><!-- /page-content -->
</div><!-- /page-layout -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openSidebar() {
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('sbOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sbOverlay').classList.remove('open');
  document.body.style.overflow = '';
}
</script>
</body>
</html>
