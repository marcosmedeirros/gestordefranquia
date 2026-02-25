// Service Worker para FBA Manager PWA
const CACHE_NAME = 'fba-manager-v12';
const OFFLINE_URL = '/offline.html';

// Arquivos essenciais para cache (apenas CSS e imagens, não JS)
const STATIC_ASSETS = [
  '/',
  '/dashboard.php',
  '/login.php',
  '/css/styles.css',
  '/img/default-team.png',
  '/img/icons/icon-192.png?v=6',
  '/img/icons/icon-512.png?v=6',
  '/manifest.json?v=6',
  '/offline.html',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
  'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap'
];

// Instalar Service Worker
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('[SW] Caching static assets');
        return cache.addAll(STATIC_ASSETS);
      })
      .then(() => self.skipWaiting())
  );
});

// Receber mensagens do client
self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

// Ativar Service Worker
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            console.log('[SW] Removing old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Estratégia de fetch: Network First, fallback to Cache
self.addEventListener('fetch', event => {
  // Ignorar requisições não-GET e APIs
  if (event.request.method !== 'GET') return;
  
  const url = new URL(event.request.url);
  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    return;
  }
  
  // Para APIs, sempre buscar da rede
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(
      fetch(event.request)
        .catch(() => new Response(JSON.stringify({ error: 'Offline' }), {
          headers: { 'Content-Type': 'application/json' }
        }))
    );
    return;
  }

  // Fotos enviadas por usuarios: sempre buscar da rede
  if (url.pathname.startsWith('/uploads/players/')) {
    event.respondWith(
      fetch(event.request, { cache: 'no-store' })
        .catch(() => caches.match(event.request))
    );
    return;
  }
  
  // Para navegação (páginas HTML)
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request)
        .catch(() => caches.match(OFFLINE_URL))
    );
    return;
  }
  
  // Para assets estáticos: Network First para JS, Cache First para outros
  event.respondWith(
    (async () => {
      // Para arquivos JS: sempre buscar da rede primeiro
      if (url.pathname.endsWith('.js')) {
        try {
          const networkResponse = await fetch(event.request);
          if (networkResponse.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(event.request, networkResponse.clone());
          }
          return networkResponse;
        } catch {
          const cachedResponse = await caches.match(event.request);
          if (cachedResponse) return cachedResponse;
        }
      }
      
      // Para outros assets: Cache First
      const cachedResponse = await caches.match(event.request);
      if (cachedResponse) {
        return cachedResponse;
      }
      
      try {
        const fetchResponse = await fetch(event.request);
        // Cachear recursos estáticos (exceto JS que já é tratado acima)
        if (fetchResponse.ok && 
            (url.pathname.endsWith('.css') || 
             url.pathname.endsWith('.png') || 
             url.pathname.endsWith('.jpg') ||
             url.pathname.endsWith('.ico'))) {
          const responseClone = fetchResponse.clone();
          const cache = await caches.open(CACHE_NAME);
          cache.put(event.request, responseClone);
        }
        return fetchResponse;
      } catch {
        // Fallback para imagens
        if (event.request.destination === 'image') {
          return caches.match('/img/default-team.png');
        }
      }
    })()
  );
});

// Push Notifications (para futuro uso)
self.addEventListener('push', event => {
  if (event.data) {
    const data = event.data.json();
    const options = {
      body: data.body || 'Nova notificação do FBA Manager',
      icon: '/img/icons/icon-192.png?v=6',
      badge: '/img/icons/icon-96.png?v=6',
      vibrate: [100, 50, 100],
      data: {
        dateOfArrival: Date.now(),
        primaryKey: data.primaryKey || 1,
        url: data.url || '/dashboard.php'
      },
      actions: [
        { action: 'explore', title: 'Ver' },
        { action: 'close', title: 'Fechar' }
      ]
    };
    event.waitUntil(
      self.registration.showNotification(data.title || 'FBA Manager', options)
    );
  }
});

// Clique em notificação
self.addEventListener('notificationclick', event => {
  event.notification.close();
  if (event.action === 'explore' || !event.action) {
    const urlToOpen = event.notification.data?.url || '/dashboard.php';
    event.waitUntil(
      clients.matchAll({ type: 'window', includeUncontrolled: true })
        .then(windowClients => {
          for (const client of windowClients) {
            if (client.url === urlToOpen && 'focus' in client) {
              return client.focus();
            }
          }
          if (clients.openWindow) {
            return clients.openWindow(urlToOpen);
          }
        })
    );
  }
});
