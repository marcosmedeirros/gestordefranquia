// Sidebar Toggle para Mobile
document.addEventListener('DOMContentLoaded', function() {
    // Criar botão hambúrguer se não existir
    if (!document.querySelector('.sidebar-toggle')) {
        const toggleBtn = document.createElement('button');
        toggleBtn.className = 'sidebar-toggle';
        toggleBtn.innerHTML = '<i class="bi bi-list fs-4"></i>';
        toggleBtn.setAttribute('aria-label', 'Toggle Menu');
        document.body.appendChild(toggleBtn);
    }
    
    // Criar overlay se não existir
    if (!document.querySelector('.sidebar-overlay')) {
        const overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);
    }
    
    const sidebar = document.querySelector('.dashboard-sidebar');
    const toggleBtn = document.querySelector('.sidebar-toggle');
    const overlay = document.querySelector('.sidebar-overlay');
    
    if (!sidebar || !toggleBtn || !overlay) return;
    
    // Abrir sidebar
    toggleBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        sidebar.classList.add('active');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    });
    
    // Fechar sidebar ao clicar no overlay
    overlay.addEventListener('click', function() {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    });
    
    // Fechar sidebar ao clicar em um link (apenas no mobile)
    const sidebarLinks = sidebar.querySelectorAll('.sidebar-menu a');
    sidebarLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    });
    
    // Garantir que o menu tenha o link para Jogadores
    const sidebarMenu = sidebar.querySelector('.sidebar-menu');
    if (sidebarMenu && !sidebarMenu.querySelector('a[href="/players.php"]')) {
        const playersItem = document.createElement('li');
        const currentPath = window.location.pathname.replace(/\/$/, '');
        const isActive = currentPath === '/players.php';
        playersItem.innerHTML = `
            <a href="/players.php" class="${isActive ? 'active' : ''}">
                <i class="bi bi-person-lines-fill"></i>
                Jogadores
            </a>`;
        const picksLink = sidebarMenu.querySelector('a[href="/picks.php"]');
        if (picksLink && picksLink.parentElement) {
            picksLink.parentElement.insertAdjacentElement('afterend', playersItem);
        } else {
            sidebarMenu.appendChild(playersItem);
        }
    }
    
    // Fechar ao pressionar ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('active')) {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
    
    // Tratar imagens quebradas - fallback para imagem padrão
    handleBrokenImages();
});

// Função para tratar imagens quebradas
function handleBrokenImages() {
    const defaultTeamImg = '/img/default-team.png';
    const defaultAvatarImg = '/img/default-avatar.png';
    
    // Tratar todas as imagens
    document.querySelectorAll('img').forEach(img => {
        // Se a src está vazia, definir fallback
        if (!img.src || img.src === window.location.href || img.src.endsWith('/')) {
            img.src = img.classList.contains('team-avatar') || img.classList.contains('team-logo') 
                ? defaultTeamImg 
                : defaultAvatarImg;
        }
        
        // Adicionar handler de erro
        img.addEventListener('error', function() {
            if (!this.dataset.fallbackApplied) {
                this.dataset.fallbackApplied = 'true';
                this.src = this.classList.contains('team-avatar') || this.classList.contains('team-logo')
                    ? defaultTeamImg
                    : defaultAvatarImg;
            }
        });
    });
    
    // Observer para novas imagens adicionadas dinamicamente
    const observer = new MutationObserver(mutations => {
        mutations.forEach(mutation => {
            mutation.addedNodes.forEach(node => {
                if (node.nodeType === 1) {
                    const imgs = node.tagName === 'IMG' ? [node] : node.querySelectorAll?.('img') || [];
                    imgs.forEach(img => {
                        if (!img.src || img.src === window.location.href) {
                            img.src = img.classList.contains('team-avatar') || img.classList.contains('team-logo')
                                ? defaultTeamImg
                                : defaultAvatarImg;
                        }
                        img.addEventListener('error', function() {
                            if (!this.dataset.fallbackApplied) {
                                this.dataset.fallbackApplied = 'true';
                                this.src = this.classList.contains('team-avatar') || this.classList.contains('team-logo')
                                    ? defaultTeamImg
                                    : defaultAvatarImg;
                            }
                        });
                    });
                }
            });
        });
    });
    
    observer.observe(document.body, { childList: true, subtree: true });
}
