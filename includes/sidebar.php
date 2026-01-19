<?php
// Garantir que a conexão está disponível
if (!function_exists('db')) {
    require_once __DIR__ . '/../backend/db.php';
}
// Buscar dados do usuário e time se não existirem
if (!isset($user) || !isset($team)) {
    $pdo = db();
    $userId = $_SESSION['user_id'] ?? null;
    
    if ($userId) {
        $stmt = $pdo->prepare("
            SELECT u.*, t.id as team_id, t.name as team_name, t.city, t.photo_url, t.league
            FROM users u
            LEFT JOIN teams t ON t.user_id = u.id
            WHERE u.id = ?
        ");
        $stmt->execute([$userId]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($userData) {
            $user = [
                'id' => $userData['id'],
                'name' => $userData['name'],
                'league' => $userData['league'] ?? 'ELITE',
                'user_type' => $userData['user_type'] ?? 'jogador'
            ];
            $team = [
                'id' => $userData['team_id'],
                'name' => $userData['team_name'],
                'city' => $userData['city'],
                'photo_url' => $userData['photo_url'],
                'league' => $userData['league']
            ];
        }
    }
}

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<div class="dashboard-sidebar">
    <div class="text-center mb-4">
        <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>" 
             alt="<?= htmlspecialchars($team['name'] ?? 'Time') ?>" class="team-avatar">
        <h5 class="text-white mb-1"><?= htmlspecialchars(($team['city'] ?? '') . ' ' . ($team['name'] ?? '')) ?></h5>
        <span class="badge bg-gradient-orange"><?= htmlspecialchars($user['league'] ?? 'ELITE') ?></span>
    </div>

    <hr style="border-color: var(--fba-border);">

    <ul class="sidebar-menu">
        <li>
            <a href="/dashboard.php" class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
                <i class="bi bi-house-door-fill"></i>
                Dashboard
            </a>
        </li>
        <li>
            <a href="/teams.php" class="<?= $currentPage === 'teams.php' ? 'active' : '' ?>">
                <i class="bi bi-people-fill"></i>
                Times
            </a>
        </li>
        <li>
            <a href="/my-roster.php" class="<?= $currentPage === 'my-roster.php' ? 'active' : '' ?>">
                <i class="bi bi-person-fill"></i>
                Meu Elenco
            </a>
        </li>
        <li>
            <a href="/picks.php" class="<?= $currentPage === 'picks.php' ? 'active' : '' ?>">
                <i class="bi bi-calendar-check-fill"></i>
                Picks
            </a>
        </li>
        <li>
            <a href="/trades.php" class="<?= $currentPage === 'trades.php' ? 'active' : '' ?>">
                <i class="bi bi-arrow-left-right"></i>
                Trades
            </a>
        </li>
        <li>
            <a href="/free-agency.php" class="<?= $currentPage === 'free-agency.php' ? 'active' : '' ?>">
                <i class="bi bi-coin"></i>
                Free Agency
            </a>
        </li>
        <li>
            <a href="/drafts.php" class="<?= $currentPage === 'drafts.php' ? 'active' : '' ?>">
                <i class="bi bi-trophy"></i>
                Draft
            </a>
        </li>
        <li>
            <a href="/rankings.php" class="<?= $currentPage === 'rankings.php' ? 'active' : '' ?>">
                <i class="bi bi-bar-chart-fill"></i>
                Rankings
            </a>
        </li>
        <li>
            <a href="/history.php" class="<?= $currentPage === 'history.php' ? 'active' : '' ?>">
                <i class="bi bi-clock-history"></i>
                Histórico
            </a>
        </li>
        <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
        <li>
            <a href="/admin.php" class="<?= $currentPage === 'admin.php' ? 'active' : '' ?>">
                <i class="bi bi-shield-lock-fill"></i>
                Admin
            </a>
        </li>
        <li>
            <a href="/temporadas.php" class="<?= $currentPage === 'temporadas.php' ? 'active' : '' ?>">
                <i class="bi bi-calendar3"></i>
                Temporadas
            </a>
        </li>
        <?php endif; ?>
        <li>
            <a href="/settings.php" class="<?= $currentPage === 'settings.php' ? 'active' : '' ?>">
                <i class="bi bi-gear-fill"></i>
                Configurações
            </a>
        </li>
    </ul>

    <hr style="border-color: var(--fba-border);">

    <div class="text-center">
        <a href="/logout.php" class="btn btn-outline-danger btn-sm w-100">
            <i class="bi bi-box-arrow-right me-2"></i>Sair
        </a>
    </div>
</div>
