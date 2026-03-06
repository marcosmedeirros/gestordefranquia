<?php
// Protótipo do álbum de figurinhas FBA – versão estática/localStorage.
// Gate simples: só acessa com o parâmetro ?k=album2026 (não linke em menus).
$secret = 'album2026';
if (!isset($_GET['k']) || $_GET['k'] !== $secret) {
    http_response_code(404);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Álbum FBA – Protótipo</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #05060d;
            --panel: #0f111c;
            --panel-2: #16192a;
            --stroke: #222845;
            --text: #e9ecf8;
            --muted: #9aa3c7;
            --accent: #ff3264;
            --glow: 0 12px 40px rgba(255, 50, 100, 0.25);
            --rounded: 20px;
            --grid-gap: 14px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Space Grotesk', 'Segoe UI', system-ui, sans-serif;
            background:
                radial-gradient(circle at 20% 25%, rgba(255, 50, 100, 0.18), transparent 38%),
                radial-gradient(circle at 85% 70%, rgba(75, 123, 255, 0.18), transparent 40%),
                linear-gradient(135deg, #05060d 0%, #0b0d18 55%, #05060d 100%);
            color: var(--text);
            min-height: 100vh;
        }

        a { color: inherit; text-decoration: none; }

        .page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 32px 20px 60px;
        }

        .hero {
            background: linear-gradient(120deg, rgba(255, 50, 100, 0.25), rgba(9, 12, 28, 0.9));
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 26px;
            padding: 22px 24px;
            box-shadow: var(--glow);
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
            align-items: center;
            justify-content: space-between;
        }

        .hero-title {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .hero-badge {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: linear-gradient(145deg, #ff6f91, #7c3aed);
            display: grid;
            place-items: center;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .hero h1 {
            margin: 0;
            font-size: 1.7rem;
            letter-spacing: -0.02em;
        }

        .muted { color: var(--muted); }

        .pill {
            padding: 6px 10px;
            border: 1px solid var(--stroke);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.04);
            display: inline-flex;
            gap: 6px;
            align-items: center;
            font-size: 0.9rem;
        }

        .layout {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 18px;
            margin-top: 22px;
        }

        @media (max-width: 960px) {
            .layout { grid-template-columns: 1fr; }
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--stroke);
            border-radius: var(--rounded);
            padding: 18px 18px 20px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
        }

        .card h2, .card h3 {
            margin: 0 0 10px;
            font-size: 1.05rem;
            letter-spacing: -0.01em;
        }

        .progress {
            height: 14px;
            border-radius: 999px;
            background: #0b0d18;
            border: 1px solid var(--stroke);
            overflow: hidden;
            position: relative;
        }

        .progress span {
            display: block;
            height: 100%;
            background: linear-gradient(90deg, #ff5f8e, #7c3aed);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .stat {
            border: 1px solid var(--stroke);
            background: var(--panel-2);
            border-radius: 12px;
            padding: 10px 12px;
        }

        .stat strong { display: block; font-size: 1.2rem; }

        .pack-area {
            border: 1px dashed rgba(255, 255, 255, 0.25);
            border-radius: 18px;
            padding: 14px;
            background: rgba(255, 255, 255, 0.02);
            min-height: 240px;
        }

        .btn {
            border: none;
            background: linear-gradient(120deg, #ff4d7a, #7c3aed);
            color: #fff;
            border-radius: 14px;
            padding: 12px 16px;
            font-weight: 700;
            letter-spacing: 0.01em;
            cursor: pointer;
            box-shadow: var(--glow);
            transition: transform 0.1s ease, box-shadow 0.2s ease;
        }

        .btn:active { transform: translateY(1px); box-shadow: none; }

        .pack-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: var(--grid-gap);
            margin-top: 14px;
        }

        .sticker {
            border-radius: 14px;
            border: 1px solid var(--stroke);
            background: var(--panel-2);
            padding: 10px;
            display: grid;
            gap: 8px;
            position: relative;
            overflow: hidden;
        }

        .sticker img {
            width: 100%;
            height: 170px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: linear-gradient(145deg, #14192f, #0b0d18);
        }

        .sticker .code {
            font-size: 0.85rem;
            letter-spacing: 0.05em;
            color: var(--muted);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.85rem;
            background: rgba(255, 255, 255, 0.06);
        }

        .rarity {
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .rarity-lendaria { color: #f7c948; }
        .rarity-epica { color: #d06bff; }
        .rarity-rara { color: #53c5ff; }
        .rarity-comum { color: #9ae6b4; }

        .status-chip {
            font-size: 0.85rem;
            color: #cdd4ee;
            padding: 5px 8px;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.03);
        }

        .album-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: var(--grid-gap);
        }

        .album-controls {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        select {
            background: var(--panel-2);
            border: 1px solid var(--stroke);
            color: var(--text);
            border-radius: 10px;
            padding: 9px 10px;
            font-size: 0.95rem;
        }

        .empty {
            text-align: center;
            color: var(--muted);
            padding: 24px 12px;
        }

        .glow-border {
            position: absolute;
            inset: 0;
            border-radius: 14px;
            pointer-events: none;
            opacity: 0.18;
            background: linear-gradient(140deg, rgba(255, 50, 100, 0.6), rgba(124, 58, 237, 0.55));
            filter: blur(16px);
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="hero">
            <div class="hero-title">
                <div class="hero-badge">FBA</div>
                <div>
                    <h1>Álbum de Figurinhas</h1>
                    <div class="muted">Protótipo local – salvo no navegador</div>
                </div>
            </div>
            <div class="pill">
                <span>Pacote traz <strong>3 figurinhas</strong></span>
            </div>
        </div>

        <div class="layout">
            <div class="card">
                <h2>Pacotes</h2>
                <div class="muted">Abra um pacote para revelar 3 figurinhas com raridades diferentes.</div>
                <div class="stats">
                    <div class="stat">
                        <span class="muted">Figurinhas no álbum</span>
                        <strong id="statCollected">0/0</strong>
                    </div>
                    <div class="stat">
                        <span class="muted">Repetidas</span>
                        <strong id="statDuplicates">0</strong>
                    </div>
                </div>
                <div style="margin: 14px 0 10px;">
                    <div class="muted" style="margin-bottom: 6px;">Progresso do álbum</div>
                    <div class="progress"><span id="progressBar" style="width:0%"></span></div>
                </div>
                <button class="btn" id="openPackBtn">Abrir pacote agora</button>
                <div class="pack-area" id="packArea">
                    <div class="muted empty">Nenhum pacote aberto ainda.</div>
                </div>
            </div>

            <div class="card">
                <div class="album-controls">
                    <h2 style="margin:0;">Album</h2>
                    <select id="categoryFilter">
                        <option value="all">Todas as categorias</option>
                    </select>
                </div>
                <div class="album-grid" id="albumGrid"></div>
            </div>
        </div>
    </div>

    <script>
        const FALLBACK_IMAGE = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="400" height="560" viewBox="0 0 400 560"><defs><linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%"><stop stop-color="%2355c0ff" offset="0%"/><stop stop-color="%23ff4d7a" offset="100%"/></linearGradient></defs><rect width="400" height="560" rx="26" fill="%230b0d18"/><rect x="18" y="18" width="364" height="524" rx="20" fill="url(%23g)" opacity="0.25"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="%23e9ecf8" font-family="Space Grotesk" font-size="26" letter-spacing="2">FBA</text></svg>';

        const rarityConfig = {
            lendaria: { label: 'Lendária', color: '#f7c948', weight: 0.05 },
            epica: { label: 'Épica', color: '#d06bff', weight: 0.15 },
            rara: { label: 'Rara', color: '#53c5ff', weight: 0.3 },
            comum: { label: 'Comum', color: '#9ae6b4', weight: 0.5 },
        };

        const stickers = [
            { id: '001', name: 'Ícone da Liga', category: 'Lendas', rarity: 'lendaria', image: 'img/001.png', blurb: 'Símbolo máximo da história FBA.' },
            { id: '002', name: 'MVP Histórico', category: 'Lendas', rarity: 'lendaria', image: 'img/002.png', blurb: 'Temporada inesquecível.' },
            { id: '010', name: 'All-Star Capitão', category: 'All-Stars', rarity: 'epica', image: 'img/010.png', blurb: 'Referência na conferência.' },
            { id: '011', name: 'Showtime Guard', category: 'All-Stars', rarity: 'epica', image: 'img/011.png', blurb: 'Handles e visão de jogo.' },
            { id: '020', name: 'Bloqueio Aéreo', category: 'Defesa', rarity: 'rara', image: 'img/020.png', blurb: 'Proteção de garrafão.' },
            { id: '021', name: 'Muralha', category: 'Defesa', rarity: 'rara', image: 'img/021.png', blurb: 'Defesa sólida noite após noite.' },
            { id: '022', name: 'Ladrão de Bolas', category: 'Defesa', rarity: 'rara', image: 'img/022.png', blurb: 'Transição em ritmo acelerado.' },
            { id: '030', name: 'Rookie Sensação', category: 'Novatos', rarity: 'rara', image: 'img/030.png', blurb: 'Chegou fazendo barulho.' },
            { id: '031', name: 'Rookie Playmaker', category: 'Novatos', rarity: 'rara', image: 'img/031.png', blurb: 'Assistências cirúrgicas.' },
            { id: '040', name: 'Especialista de 3', category: 'Perímetro', rarity: 'comum', image: 'img/040.png', blurb: 'Mão quente do perímetro.' },
            { id: '041', name: 'Motor do Time', category: 'Perímetro', rarity: 'comum', image: 'img/041.png', blurb: 'Ritmo constante.' },
            { id: '042', name: 'Reboteiro', category: 'Garrafão', rarity: 'comum', image: 'img/042.png', blurb: 'Dono dos rebotes.' },
            { id: '043', name: 'Sixth Man', category: 'Elenco', rarity: 'comum', image: 'img/043.png', blurb: 'Energia do banco.' },
            { id: '044', name: 'Técnico Estratégico', category: 'Staff', rarity: 'comum', image: 'img/044.png', blurb: 'Planos de jogo afiados.' },
            { id: '050', name: 'Mascote Oficial', category: 'Extras', rarity: 'comum', image: 'img/050.png', blurb: 'Carisma na quadra.' },
        ];

        const storageKey = 'album_fba_colecao';
        const state = {
            collection: loadCollection(),
            lastPack: [],
        };

        function loadCollection() {
            try {
                const saved = localStorage.getItem(storageKey);
                return saved ? JSON.parse(saved) : {};
            } catch (err) {
                console.warn('Não foi possível carregar o álbum salvo', err);
                return {};
            }
        }

        function saveCollection() {
            localStorage.setItem(storageKey, JSON.stringify(state.collection));
        }

        function pickRarity() {
            const total = Object.values(rarityConfig).reduce((sum, r) => sum + r.weight, 0);
            const roll = Math.random() * total;
            let acc = 0;
            for (const key in rarityConfig) {
                acc += rarityConfig[key].weight;
                if (roll <= acc) return key;
            }
            return 'comum';
        }

        function pullSticker() {
            const rarity = pickRarity();
            const pool = stickers.filter((s) => s.rarity === rarity);
            if (!pool.length) return null;
            const pick = pool[Math.floor(Math.random() * pool.length)];
            state.collection[pick.id] = (state.collection[pick.id] || 0) + 1;
            return pick;
        }

        function openPack() {
            state.lastPack = [];
            for (let i = 0; i < 3; i += 1) {
                const sticker = pullSticker();
                if (sticker) state.lastPack.push(sticker);
            }
            saveCollection();
            renderPack();
            renderAlbum();
            renderStats();
        }

        function renderPack() {
            const area = document.getElementById('packArea');
            if (!state.lastPack.length) {
                area.innerHTML = '<div class="muted empty">Nenhum pacote aberto ainda.</div>';
                return;
            }
            const cards = state.lastPack.map((sticker) => {
                const rarity = rarityConfig[sticker.rarity] || {};
                return `
                    <div class="sticker">
                        <div class="glow-border"></div>
                        <img src="${sticker.image}" alt="${sticker.name}" onerror="this.onerror=null;this.src='${FALLBACK_IMAGE}';">
                        <div class="code">#${sticker.id}</div>
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                            <div>
                                <div style="font-weight:700;">${sticker.name}</div>
                                <div class="muted" style="font-size:0.9rem;">${sticker.blurb || ''}</div>
                            </div>
                            <div class="badge">
                                <span class="rarity rarity-${sticker.rarity}">${rarity.label || sticker.rarity}</span>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
            area.innerHTML = `<div class="pack-grid">${cards}</div>`;
        }

        function renderAlbum() {
            const grid = document.getElementById('albumGrid');
            const filter = document.getElementById('categoryFilter').value;
            const filtered = stickers.filter((s) => filter === 'all' || s.category === filter);
            if (!filtered.length) {
                grid.innerHTML = '<div class="empty">Nenhuma figurinha nesta categoria ainda.</div>';
                return;
            }
            grid.innerHTML = filtered.map((sticker) => {
                const owned = state.collection[sticker.id] || 0;
                const placed = owned > 0;
                const rarity = rarityConfig[sticker.rarity] || {};
                const duplicates = Math.max(owned - 1, 0);
                return `
                    <div class="sticker">
                        <div class="glow-border"></div>
                        <img src="${sticker.image}" alt="${sticker.name}" onerror="this.onerror=null;this.src='${FALLBACK_IMAGE}';">
                        <div class="code">#${sticker.id} • ${sticker.category}</div>
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
                            <div>
                                <div style="font-weight:700;">${sticker.name}</div>
                                <div class="muted" style="font-size:0.9rem;">${sticker.blurb || ''}</div>
                            </div>
                            <span class="badge">
                                <span class="rarity rarity-${sticker.rarity}">${rarity.label || sticker.rarity}</span>
                            </span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <span class="status-chip">${placed ? 'Colada' : 'Faltando'}</span>
                            ${duplicates ? `<span class="status-chip">Repetidas: ${duplicates}</span>` : ''}
                        </div>
                    </div>
                `;
            }).join('');
        }

        function renderStats() {
            const total = stickers.length;
            const ownedIds = Object.keys(state.collection).filter((id) => state.collection[id] > 0);
            const owned = ownedIds.length;
            const duplicates = Object.values(state.collection).reduce((acc, qty) => acc + Math.max(qty - 1, 0), 0);
            const percent = total ? Math.round((owned / total) * 100) : 0;
            document.getElementById('statCollected').textContent = `${owned}/${total}`;
            document.getElementById('statDuplicates').textContent = duplicates;
            document.getElementById('progressBar').style.width = `${percent}%`;
        }

        function setupCategoryFilter() {
            const select = document.getElementById('categoryFilter');
            const categories = Array.from(new Set(stickers.map((s) => s.category))).sort();
            categories.forEach((cat) => {
                const opt = document.createElement('option');
                opt.value = cat;
                opt.textContent = cat;
                select.appendChild(opt);
            });
            select.addEventListener('change', renderAlbum);
        }

        document.getElementById('openPackBtn').addEventListener('click', openPack);
        setupCategoryFilter();
        renderAlbum();
        renderStats();
    </script>
</body>
</html>
