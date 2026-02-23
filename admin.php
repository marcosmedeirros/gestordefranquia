<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();
$user = getUserSession();
if (($user['user_type'] ?? 'jogador') !== 'admin') {
  header('Location: /dashboard.php');
  exit;
}
$pdo = db();

// Buscar time do usuário (se tiver)
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
  <meta name="theme-color" content="#fc0025">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="FBA Manager">
  <meta name="mobile-web-app-capable" content="yes">
  <link rel="manifest" href="/manifest.json?v=3">
  <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
  <title>Admin - Ligas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/css/styles.css" />
  <style>
    .admin-check-card {
      border: 2px solid var(--bs-danger) !important;
    }
    .admin-check-card.is-accepted {
      border-color: var(--bs-success) !important;
    }
  </style>
</head>
<body>
  <!-- Botão Hamburguer para Mobile -->
  <button class="sidebar-toggle" id="sidebarToggle">
    <i class="bi bi-list fs-4"></i>
  </button>
  
  <!-- Overlay para fechar sidebar no mobile -->
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <div class="dashboard-sidebar" id="sidebar">
    <div class="text-center mb-4">
      <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>" alt="Admin" class="team-avatar">
      <h5 class="text-white mb-1"><?= $team ? htmlspecialchars($team['city'] . ' ' . $team['name']) : 'Admin' ?></h5>
      <span class="badge bg-gradient-orange"><?= $team ? htmlspecialchars($team['league']) : 'Painel' ?></span>
    </div>
    <hr style="border-color: var(--fba-border);">
    <ul class="sidebar-menu">
      <li><a href="/dashboard.php"><i class="bi bi-house-door-fill"></i>Dashboard</a></li>
      <li><a href="/teams.php"><i class="bi bi-people-fill"></i>Times</a></li>
      <li><a href="/my-roster.php"><i class="bi bi-person-fill"></i>Meu Elenco</a></li>
      <li><a href="/picks.php"><i class="bi bi-calendar-check-fill"></i>Picks</a></li>
      <li><a href="/trades.php"><i class="bi bi-arrow-left-right"></i>Trades</a></li>
      <li><a href="/free-agency.php"><i class="bi bi-coin"></i>Free Agency</a></li>
  <li><a href="/leilao.php"><i class="bi bi-hammer"></i>Leilão</a></li>
      <li><a href="/drafts.php"><i class="bi bi-trophy"></i>Draft</a></li>
      <li><a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i>Rankings</a></li>
      <li><a href="/history.php"><i class="bi bi-clock-history"></i>Histórico</a></li>
      <li><a href="/admin.php" class="active"><i class="bi bi-shield-lock-fill"></i>Admin</a></li>
  <li><a href="/punicoes.php"><i class="bi bi-exclamation-triangle-fill"></i>Punições</a></li>
      <li><a href="/temporadas.php"><i class="bi bi-calendar3"></i>Temporadas</a></li>
      <li><a href="/settings.php"><i class="bi bi-gear-fill"></i>Configurações</a></li>
    </ul>
    <hr style="border-color: var(--fba-border);">
    <div class="text-center">
      <a href="/logout.php" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-box-arrow-right me-2"></i>Sair</a>
    </div>
  </div>

  <div class="dashboard-content">
    <!-- Breadcrumb Navigation -->
    <div class="breadcrumb-admin" id="breadcrumbContainer" style="display: none;">
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0" id="breadcrumb">
          <li class="breadcrumb-item"><a href="#" onclick="showHome(); return false;">Admin</a></li>
        </ol>
      </nav>
    </div>

    <!-- Header -->
    <div class="page-header mb-4">
      <h1 class="text-white fw-bold mb-0">
        <i class="bi bi-shield-lock-fill me-2 text-orange"></i>
        <span id="pageTitle">Painel Administrativo</span>
      </h1>
    </div>

    <!-- Container Principal -->
    <div id="mainContainer">
      <!-- Conteúdo será carregado dinamicamente aqui -->
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/js/sidebar.js?v=<?= time() ?>"></script>
  <script src="/js/admin.js?v=<?= time() ?>"></script>
  <script src="/js/seasons.js?v=<?= time() ?>"></script>
  <script>
    // Toggle Sidebar Mobile (código legado - sidebar.js já gerencia isso)
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    sidebarToggle.addEventListener('click', () => {
      sidebar.classList.toggle('active');
      overlay.classList.toggle('active');
    });
    
    overlay.addEventListener('click', () => {
      sidebar.classList.remove('active');
      overlay.classList.remove('active');
    });
    
    // Fechar sidebar ao clicar em um link no mobile
    if (window.innerWidth <= 768) {
      document.querySelectorAll('.sidebar-menu a').forEach(link => {
        link.addEventListener('click', () => {
          sidebar.classList.remove('active');
          overlay.classList.remove('active');
        });
      });
    }
  </script>
  <script src="/js/pwa.js"></script>
</body>
</html>
