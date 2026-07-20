<?php
/**
 * Menu lateral padrão do app.
 * Requer $user (getUserSession()) e $pdo (db()) já definidos pela página.
 * $team é opcional — se não definido, o cartão do time não é exibido.
 * Uso: <?php include __DIR__ . '/includes/sidebar.php'; ?>
 */
if (!isset($pdo)) {
    $pdo = db();
}
$__sbCurrent = basename($_SERVER['SCRIPT_NAME'] ?? '');
$__sbIsAdmin = !empty($user['id']) && hasAdminAccess($pdo, (int)$user['id']);

if (!function_exists('sbActive')) {
    function sbActive(string $page, string $current): string
    {
        return $page === $current ? ' class="active"' : '';
    }
}
?>
<aside class="sidebar" id="sidebar">
    <div class="sb-brand">
        <div class="sb-logo">FBA</div>
        <div class="sb-brand-text">FBA Manager<span>Liga <?= htmlspecialchars($user['league'] ?? '') ?></span></div>
    </div>

    <?php if (!empty($team)): ?>
    <div class="sb-team">
        <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>"
             alt="<?= htmlspecialchars(trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? ''))) ?>"
             onerror="this.src='/img/default-team.png'">
        <div>
            <div class="sb-team-name"><?= htmlspecialchars(trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? ''))) ?></div>
            <div class="sb-team-league"><?= htmlspecialchars($team['league'] ?? $user['league'] ?? '') ?></div>
        </div>
    </div>
    <?php endif; ?>

    <nav class="sb-nav">
        <div class="sb-section">Principal</div>
        <a href="/dashboard.php"<?= sbActive('dashboard.php', $__sbCurrent) ?>><i class="bi bi-house-door-fill"></i> Dashboard</a>
        <a href="/teams.php"<?= sbActive('teams.php', $__sbCurrent) ?>><i class="bi bi-people-fill"></i> Times</a>
        <a href="/my-roster.php"<?= sbActive('my-roster.php', $__sbCurrent) ?>><i class="bi bi-person-fill"></i> Meu Elenco</a>
        <a href="/players.php"<?= sbActive('players.php', $__sbCurrent) ?>><i class="bi bi-person-lines-fill"></i> Jogadores</a>
        <a href="/picks.php"<?= sbActive('picks.php', $__sbCurrent) ?>><i class="bi bi-calendar-check-fill"></i> Picks</a>
        <a href="/trades.php"<?= sbActive('trades.php', $__sbCurrent) ?>><i class="bi bi-arrow-left-right"></i> Trades</a>
        <a href="/mercado.php"<?= sbActive('mercado.php', $__sbCurrent) ?>><i class="bi bi-shop"></i> Mercado</a>
        <a href="/free-agency.php"<?= sbActive('free-agency.php', $__sbCurrent) ?>><i class="bi bi-coin"></i> Free Agency</a>
        <a href="/leilao.php"<?= sbActive('leilao.php', $__sbCurrent) ?>><i class="bi bi-hammer"></i> Leilão</a>
        <a href="/drafts.php"<?= sbActive('drafts.php', $__sbCurrent) ?>><i class="bi bi-trophy"></i> Draft</a>
        <a href="/tapas.php"<?= sbActive('tapas.php', $__sbCurrent) ?>><i class="bi bi-hand-index-thumb"></i> Tapas</a>

        <div class="sb-section">Liga</div>
        <a href="/rankings.php"<?= sbActive('rankings.php', $__sbCurrent) ?>><i class="bi bi-bar-chart-fill"></i> Rankings</a>
        <a href="/history.php"<?= sbActive('history.php', $__sbCurrent) ?>><i class="bi bi-clock-history"></i> Histórico</a>
        <a href="/hall-da-fama.php"<?= sbActive('hall-da-fama.php', $__sbCurrent) ?>><i class="bi bi-award-fill"></i> Hall da Fama</a>
        <a href="/diretrizes.php"<?= sbActive('diretrizes.php', $__sbCurrent) ?>><i class="bi bi-clipboard-data"></i> Diretrizes</a>
        <a href="/mundo-fba.php"<?= sbActive('mundo-fba.php', $__sbCurrent) ?>><i class="bi bi-globe2"></i> Mundo FBA</a>
        <a href="/estatisticas.php"<?= sbActive('estatisticas.php', $__sbCurrent) ?>><i class="bi bi-bar-chart-line-fill"></i> Estatísticas</a>
        <a href="/ouvidoria.php"<?= sbActive('ouvidoria.php', $__sbCurrent) ?>><i class="bi bi-chat-dots"></i> Ouvidoria</a>
        <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> FBA Games</a>
        <a href="/thepathetic.php"<?= sbActive('thepathetic.php', $__sbCurrent) ?>><i class="bi bi-newspaper"></i> The Pathetic</a>

        <?php if ($__sbIsAdmin): ?>
        <div class="sb-section">Admin</div>
        <a href="/admin.php"<?= sbActive('admin.php', $__sbCurrent) ?>><i class="bi bi-shield-lock-fill"></i> Admin</a>
        <a href="/punicoes.php"<?= sbActive('punicoes.php', $__sbCurrent) ?>><i class="bi bi-exclamation-triangle-fill"></i> Punições</a>
        <?php endif; ?>

        <div class="sb-section">Conta</div>
        <a href="/team-public-page.php"<?= sbActive('team-public-page.php', $__sbCurrent) ?>><i class="bi bi-globe2"></i> Página do Time</a>
        <a href="/settings.php"<?= sbActive('settings.php', $__sbCurrent) ?>><i class="bi bi-gear-fill"></i> Minha Conta</a>
    </nav>

    <button class="sb-theme-toggle" type="button" id="themeToggle">
        <i class="bi bi-moon"></i>
        <span>Modo escuro</span>
    </button>

    <div class="sb-footer">
        <img src="<?= htmlspecialchars(getUserPhoto($user['photo_url'] ?? null)) ?>"
             alt="<?= htmlspecialchars($user['name'] ?? '') ?>"
             class="sb-avatar"
             onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name'] ?? 'U') ?>&background=1c1c21&color=<?= accentColorHex($user['accent_color'] ?? null) ?>'">
        <span class="sb-username"><?= htmlspecialchars($user['name'] ?? '') ?></span>
        <a href="/logout.php" class="sb-logout" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
    </div>
</aside>
