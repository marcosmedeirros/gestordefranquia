const THEME_STORAGE_KEY = 'fba-theme-preference';
const VALID_THEMES = ['dark', 'light'];
let pendingBodyTheme = null;
let bodyThemeListenerAttached = false;

function getStoredTheme() {
    try {
        const stored = localStorage.getItem(THEME_STORAGE_KEY);
        return VALID_THEMES.includes(stored) ? stored : null;
    } catch (err) {
        return null;
    }
}

function setBodyThemeAttribute(theme) {
    if (document.body) {
        document.body.setAttribute('data-theme', theme);
    } else {
        pendingBodyTheme = theme;
        if (!bodyThemeListenerAttached) {
            document.addEventListener('DOMContentLoaded', function applyPendingTheme() {
                if (pendingBodyTheme) {
                    document.body?.setAttribute('data-theme', pendingBodyTheme);
                    pendingBodyTheme = null;
                }
            }, { once: true });
            bodyThemeListenerAttached = true;
        }
    }
}

function setTheme(theme, persist = true) {
    const normalized = VALID_THEMES.includes(theme) ? theme : 'dark';
    document.documentElement.setAttribute('data-theme', normalized);
    setBodyThemeAttribute(normalized);
    if (persist) {
        try {
            localStorage.setItem(THEME_STORAGE_KEY, normalized);
        } catch (err) {
            console.warn('Não foi possível salvar a preferência de tema.', err);
        }
    }
    document.dispatchEvent(new CustomEvent('themechange', { detail: { theme: normalized } }));
}

(function initializeTheme() {
    const stored = getStoredTheme();
    if (stored) {
        setTheme(stored, false);
    } else {
        const prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
        setTheme(prefersLight ? 'light' : 'dark', false);
    }

    if (window.matchMedia) {
        const mediaQuery = window.matchMedia('(prefers-color-scheme: light)');
        mediaQuery.addEventListener('change', event => {
            if (getStoredTheme()) return;
            setTheme(event.matches ? 'light' : 'dark', false);
        });
    }
})();

function initThemeToggle(sidebar) {
    if (!sidebar) return;

    let existingToggle = sidebar.querySelector('.sidebar-theme-toggle');
    if (!existingToggle) {
        existingToggle = document.createElement('button');
        existingToggle.type = 'button';
        existingToggle.className = 'sidebar-theme-toggle';
        const textBlocks = sidebar.querySelectorAll('.text-center');
        const logoutBlock = textBlocks[textBlocks.length - 1];
        if (logoutBlock && logoutBlock.parentElement === sidebar) {
            sidebar.insertBefore(existingToggle, logoutBlock);
        } else {
            sidebar.appendChild(existingToggle);
        }
    }

    const renderToggle = () => {
        const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
        const isLight = currentTheme === 'light';
        existingToggle.classList.toggle('active', isLight);
        existingToggle.innerHTML = `
            <div class="theme-toggle-icon">
                <i class="bi ${isLight ? 'bi-moon-stars-fill' : 'bi-brightness-high-fill'}"></i>
            </div>
            <div class="theme-toggle-copy">
                <span>${isLight ? 'Modo escuro' : 'Modo claro'}</span>
                <small>${isLight ? 'Voltar para o visual original' : 'Ativar versão clara'}</small>
            </div>
            <div class="theme-toggle-switch ${isLight ? 'active' : ''}">
                <span></span>
            </div>
        `;
    };

    existingToggle.addEventListener('click', () => {
        const currentTheme = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
        const nextTheme = currentTheme === 'light' ? 'dark' : 'light';
        setTheme(nextTheme, true);
        renderToggle();
    });

    document.addEventListener('themechange', renderToggle);
    renderToggle();
}

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
    initThemeToggle(sidebar);
    
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
