<?php
// Sidebar público para páginas em /public
$publicPage = basename($_SERVER['PHP_SELF'] ?? '');
$links = [
    ['href' => '/dashboard.php', 'icon' => 'bi-house-door-fill', 'label' => 'Dashboard'],
    ['href' => '/teams.php', 'icon' => 'bi-people-fill', 'label' => 'Times'],
    ['href' => '/players.php', 'icon' => 'bi-person-lines-fill', 'label' => 'Jogadores'],
    ['href' => '/my-roster.php', 'icon' => 'bi-person-fill', 'label' => 'Meu Elenco'],
    ['href' => '/picks.php', 'icon' => 'bi-calendar-check-fill', 'label' => 'Picks'],
    ['href' => '/trades.php', 'icon' => 'bi-arrow-left-right', 'label' => 'Trades'],
    ['href' => '/free-agency.php', 'icon' => 'bi-coin', 'label' => 'Free Agency'],
    ['href' => '/leilao.php', 'icon' => 'bi-hammer', 'label' => 'Leilão'],
    ['href' => '/drafts.php', 'icon' => 'bi-trophy', 'label' => 'Draft'],
    ['href' => '/rankings.php', 'icon' => 'bi-bar-chart-fill', 'label' => 'Rankings'],
    ['href' => '/history.php', 'icon' => 'bi-clock-history', 'label' => 'Histórico'],
    ['href' => '/settings.php', 'icon' => 'bi-gear-fill', 'label' => 'Configurações'],
];
?>
<div class="col-md-3 p-0">
    <div class="dashboard-sidebar">
        <div class="text-center mb-4">
            <img src="/img/default-team.png" alt="Time" class="team-avatar">
            <h5 class="text-white mb-1">Menu</h5>
        </div>
        <hr style="border-color: var(--fba-border);">
        <ul class="sidebar-menu">
            <?php foreach ($links as $link): ?>
                <li>
                    <a href="<?= htmlspecialchars($link['href']) ?>" class="<?= str_ends_with($link['href'], '/' . $publicPage) ? 'active' : '' ?>">
                        <i class="bi <?= htmlspecialchars($link['icon']) ?>"></i>
                        <?= htmlspecialchars($link['label']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <hr style="border-color: var(--fba-border);">
        <div class="text-center">
            <a href="/logout.php" class="btn btn-outline-danger btn-sm w-100">
                <i class="bi bi-box-arrow-right me-2"></i>Sair
            </a>
        </div>
    </div>
</div>
