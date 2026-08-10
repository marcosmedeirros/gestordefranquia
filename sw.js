// Service Worker para FBA Manager PWA
// Subir esta versao a cada mudanca de CSS: o activate apaga todo cache com
// nome diferente, e o CSS e servido em Cache First (ficaria preso na versao
// antiga no navegador de quem ja acessou).
// v17: a subida de versão apaga o cache antigo no activate — e isso importa
// nesta versão, porque é o que limpa de uma vez as fotos 1040x760 que ficaram
// acumuladas no aparelho de quem já usava o app.
const CACHE_NAME = 'fba-manager-v17';

// Cache separado pro que é baixado durante o uso (fotos, ícones, JS versionado).
// Antes ia tudo junto com a casca do app, num cache sem teto que só era limpo
// quando o CACHE_NAME mudava — e como cada foto de jogador e cada deploy de JS
// entram com URL nova, ele só crescia. Aqui tem limite e descarte do mais
// antigo, e a casca fica fora disso pra nunca ser evictada.
const RUNTIME_CACHE = 'fba-runtime-v1';
const RUNTIME_MAX_ENTRADAS = 120;

const OFFLINE_URL = '/offline.html';

/** Mantém o cache de runtime no teto, descartando as entradas mais antigas. */
async function limitarCache(nome, max) {
  try {
    const cache = await caches.open(nome);
    const chaves = await cache.keys();          // vem em ordem de inserção
    if (chaves.length <= max) return;
    await Promise.all(chaves.slice(0, chaves.length - max).map(k => cache.delete(k)));
  } catch (e) {
    // Falhar aqui não pode derrubar a resposta que já foi entregue.
  }
}

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
          // O de runtime sobrevive à troca de versão da casca: ele se controla
          // sozinho pelo teto, e jogar fora as fotos a cada deploy só faria o
          // aparelho baixar tudo de novo.
          if (cacheName !== CACHE_NAME && cacheName !== RUNTIME_CACHE) {
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
      fetch(event.request, { cache: 'no-store' })
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
            const cache = await caches.open(RUNTIME_CACHE);
            await cache.put(event.request, networkResponse.clone());
            limitarCache(RUNTIME_CACHE, RUNTIME_MAX_ENTRADAS);
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
          const cache = await caches.open(RUNTIME_CACHE);
          await cache.put(event.request, responseClone);
          limitarCache(RUNTIME_CACHE, RUNTIME_MAX_ENTRADAS);
        }
        return fetchResponse;
      } catch {
        // Fallback para imagens
        if (event.request.destination === 'image') {
          const fb = await caches.match('/img/default-team.png');
          if (fb) return fb;
        }
        // Sem isto a função devolvia undefined e o respondWith derrubava a
        // requisição com um erro opaco, em vez de deixar o navegador tratar.
        return Response.error();
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
      // Sem tag, cada push vira uma notificação NOVA e elas se empilham. Com
      // inscrição duplicada no servidor isso virava 6 avisos iguais de uma vez
      // e travava o aparelho. Mesma tag = a nova substitui a anterior, então
      // uma duplicata que escape não vira uma pilha.
      tag: data.primaryKey ? String(data.primaryKey) : 'fba-geral',
      // renotify false: substituir não deve vibrar de novo.
      renotify: false,
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
