// Registrar Service Worker e funcionalidades PWA
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js')
      .then(registration => {
        console.log('[PWA] Service Worker registrado:', registration.scope);

        registration.update();
        
        // Verificar atualizações
        registration.addEventListener('updatefound', () => {
          const newWorker = registration.installing;
          newWorker.addEventListener('statechange', () => {
            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
              // Nova versão disponível
              showUpdateNotification();
            }
          });
        });

        let refreshing = false;
        navigator.serviceWorker.addEventListener('controllerchange', () => {
          if (refreshing) return;
          refreshing = true;
          window.location.reload();
        });
      })
      .catch(err => {
        console.log('[PWA] Erro ao registrar Service Worker:', err);
      });
  });
}

// Notificação de atualização disponível
function showUpdateNotification() {
  if (document.getElementById('pwa-update-toast')) return;
  
  const toast = document.createElement('div');
  toast.id = 'pwa-update-toast';
  toast.innerHTML = `
    <div class="pwa-toast">
      <div class="pwa-toast-content">
        <i class="bi bi-arrow-repeat"></i>
        <span>Nova versão disponível!</span>
      </div>
      <button onclick="updateApp()" class="pwa-toast-btn">Atualizar</button>
    </div>
  `;
  document.body.appendChild(toast);
}

function updateApp() {
  if (navigator.serviceWorker.controller) {
    navigator.serviceWorker.controller.postMessage({ type: 'SKIP_WAITING' });
  }
  window.location.reload();
}

// Detectar se é PWA instalado
function isPWA() {
  return window.matchMedia('(display-mode: standalone)').matches ||
         window.navigator.standalone === true ||
         document.referrer.includes('android-app://');
}

// Botão de instalação (A2HS - Add to Home Screen)
let deferredPrompt;

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPrompt = e;
  
  // Mostrar botão de instalação se não estiver em modo PWA
  if (!isPWA()) {
    showInstallButton();
  }
});

function showInstallButton() {
  // Verificar se já mostrou recentemente
  const lastPrompt = localStorage.getItem('pwa-install-prompt');
  if (lastPrompt && Date.now() - parseInt(lastPrompt) < 24 * 60 * 60 * 1000) {
    return; // Não mostrar novamente por 24h
  }
  
  // Criar banner de instalação
  const banner = document.createElement('div');
  banner.id = 'pwa-install-banner';
  banner.innerHTML = `
    <div class="pwa-banner">
      <div class="pwa-banner-content">
        <img src="/img/icons/icon-192.png?v=6" alt="FBA" class="pwa-banner-icon" onerror="this.style.display='none'">
        <div class="pwa-banner-text">
          <strong>Instalar FBA Manager</strong>
          <span>Adicione à tela inicial para acesso rápido</span>
        </div>
      </div>
      <div class="pwa-banner-actions">
        <button onclick="dismissInstallBanner()" class="pwa-banner-dismiss">Depois</button>
        <button onclick="installApp()" class="pwa-banner-install">Instalar</button>
      </div>
    </div>
  `;
  document.body.appendChild(banner);
  
  // Animar entrada
  setTimeout(() => banner.classList.add('show'), 100);
}

async function installApp() {
  if (!deferredPrompt) return;
  
  deferredPrompt.prompt();
  const { outcome } = await deferredPrompt.userChoice;
  
  if (outcome === 'accepted') {
    console.log('[PWA] App instalado!');
  }
  
  deferredPrompt = null;
  dismissInstallBanner();
}

function dismissInstallBanner() {
  const banner = document.getElementById('pwa-install-banner');
  if (banner) {
    banner.classList.remove('show');
    setTimeout(() => banner.remove(), 300);
  }
  localStorage.setItem('pwa-install-prompt', Date.now().toString());
}

// Detectar quando app foi instalado
window.addEventListener('appinstalled', () => {
  console.log('[PWA] App adicionado à tela inicial');
  deferredPrompt = null;
  dismissInstallBanner();
});

// Detectar conexão online/offline
window.addEventListener('online', () => {
  document.body.classList.remove('offline');
  showConnectionStatus('online');
});

window.addEventListener('offline', () => {
  document.body.classList.add('offline');
  showConnectionStatus('offline');
});

function showConnectionStatus(status) {
  const existing = document.getElementById('connection-status');
  if (existing) existing.remove();
  
  const statusEl = document.createElement('div');
  statusEl.id = 'connection-status';
  statusEl.className = `connection-status ${status}`;
  statusEl.innerHTML = status === 'online' 
    ? '<i class="bi bi-wifi"></i> Conexão restaurada'
    : '<i class="bi bi-wifi-off"></i> Sem conexão';
  
  document.body.appendChild(statusEl);
  
  setTimeout(() => statusEl.classList.add('show'), 10);
  setTimeout(() => {
    statusEl.classList.remove('show');
    setTimeout(() => statusEl.remove(), 300);
  }, 3000);
}

// Verificar estado inicial de conexão
if (!navigator.onLine) {
  document.body.classList.add('offline');
}
