// Menu lateral (mobile): abrir/fechar via botão hambúrguer + overlay.
// Compatível com os dois padrões de id usados no app: #menuBtn (maioria)
// e #sidebarToggle (trades.php, trade-simulator.php).
document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sbOverlay');
    const menuBtn = document.getElementById('menuBtn') || document.getElementById('sidebarToggle');
    if (!sidebar || !overlay) return;

    const openSidebar = () => {
        sidebar.classList.add('open');
        overlay.classList.add('show');
    };
    const closeSidebar = () => {
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
    };

    if (menuBtn) {
        menuBtn.addEventListener('click', () => {
            if (sidebar.classList.contains('open')) closeSidebar();
            else openSidebar();
        });
    }

    overlay.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar.classList.contains('open')) closeSidebar();
    });

    sidebar.querySelectorAll('.sb-nav a').forEach((link) => {
        link.addEventListener('click', closeSidebar);
    });

    handleBrokenImages();
});

// Fallback de imagem quebrada (foto de usuário/time) para o placeholder padrão.
function handleBrokenImages() {
    const defaultTeamImg = '/img/default-team.png';
    const defaultAvatarImg = '/img/default-avatar.png';

    const applyFallback = (img) => {
        if (img.dataset.fallbackApplied) return;
        img.dataset.fallbackApplied = 'true';
        img.src = (img.classList.contains('team-avatar') || img.classList.contains('team-logo'))
            ? defaultTeamImg
            : defaultAvatarImg;
    };

    document.querySelectorAll('img').forEach((img) => {
        if (!img.src || img.src === window.location.href || img.src.endsWith('/')) {
            applyFallback(img);
        }
        img.addEventListener('error', () => applyFallback(img));
    });

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType !== 1) return;
                const imgs = node.tagName === 'IMG' ? [node] : (node.querySelectorAll ? node.querySelectorAll('img') : []);
                imgs.forEach((img) => img.addEventListener('error', () => applyFallback(img)));
            });
        });
    });
    observer.observe(document.body, { childList: true, subtree: true });
}
