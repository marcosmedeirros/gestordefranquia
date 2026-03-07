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
            --bg: #060712;
            --panel: #0d0f1c;
            --panel-2: #15182a;
            --panel-3: #1c2140;
            --stroke: #202640;
            --text: #e9ecf8;
            --muted: #a3add2;
            --accent: #ff4f7d;
            --accent-2: #6d8bff;
            --glow: 0 14px 46px rgba(255, 79, 125, 0.26);
            --rounded: 22px;
            --grid-gap: 14px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Space Grotesk', 'Segoe UI', system-ui, sans-serif;
            color: var(--text);
            min-height: 100vh;
            background:
                radial-gradient(circle at 18% 24%, rgba(255, 79, 125, 0.16), transparent 35%),
                radial-gradient(circle at 80% 70%, rgba(100, 141, 255, 0.16), transparent 40%),
                linear-gradient(140deg, #050712 0%, #0b0d18 55%, #050712 100%);
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(135deg, rgba(255, 255, 255, 0.03) 0 1px, transparent 1px 12px),
                radial-gradient(circle at 30% 10%, rgba(255, 255, 255, 0.12), transparent 36%);
            background-size: 12px 12px, 100% 100%;
            pointer-events: none;
            opacity: 0.55;
        }

        a { color: inherit; text-decoration: none; }

        .page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 32px 20px 60px;
        }

        .hero {
            background: linear-gradient(110deg, rgba(255, 79, 125, 0.24), rgba(14, 17, 34, 0.94));
            border: 1px solid rgba(255, 255, 255, 0.07);
            border-radius: 28px;
            padding: 24px 26px;
            box-shadow: var(--glow);
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
            align-items: center;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .hero::after {
            content: '';
            position: absolute;
            width: 280px;
            height: 280px;
            background: radial-gradient(circle, rgba(109, 139, 255, 0.18), transparent 55%);
            top: -80px;
            right: -60px;
            filter: blur(2px);
        }

        .hero-title {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .hero-badge {
            width: 58px;
            height: 58px;
            border-radius: 16px;
            background: conic-gradient(from 120deg, #ff4f7d, #6d8bff, #ffb347, #ff4f7d);
            display: grid;
            place-items: center;
            font-weight: 800;
            letter-spacing: 0.02em;
            color: #050712;
            box-shadow: 0 10px 32px rgba(0, 0, 0, 0.35);
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
            grid-template-columns: 360px 1fr;
            gap: 18px;
            margin-top: 22px;
        }

        @media (max-width: 960px) {
            .layout { grid-template-columns: 1fr; }
        }

        .card {
            background: linear-gradient(160deg, rgba(28, 33, 64, 0.28), rgba(13, 15, 28, 0.92));
            border: 1px solid var(--stroke);
            border-radius: var(--rounded);
            padding: 18px 18px 20px;
            box-shadow: 0 12px 34px rgba(0, 0, 0, 0.3);
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.05), transparent 50%, rgba(255, 255, 255, 0.02));
            pointer-events: none;
            opacity: 0.6;
        }

        .card > * { position: relative; z-index: 1; }

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
            background: linear-gradient(145deg, rgba(28, 33, 64, 0.6), rgba(13, 15, 28, 0.9));
            min-height: 260px;
            position: relative;
            overflow: hidden;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.04);
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
            transition: transform 0.1s ease, box-shadow 0.2s ease, filter 0.2s ease;
        }

        .btn:active { transform: translateY(1px); box-shadow: none; }
        .btn:hover { filter: brightness(1.05); }

        .pack-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: var(--grid-gap);
            margin-top: 14px;
        }

        .tabs {
            display: inline-flex;
            border: 1px solid var(--stroke);
            border-radius: 12px;
            overflow: hidden;
            background: var(--panel-2);
        }

        .tab {
            padding: 10px 14px;
            cursor: pointer;
            color: var(--muted);
            border-right: 1px solid var(--stroke);
            background: transparent;
        }

        .tab:last-child { border-right: none; }

        .tab.active {
            color: #fff;
            background: linear-gradient(120deg, #ff4d7a33, #7c3aed33);
        }

        .hidden { display: none; }

        @keyframes packPulse {
            0% { transform: translateY(0) rotate(-1deg); }
            50% { transform: translateY(-4px) rotate(1deg); }
            100% { transform: translateY(0) rotate(-1deg); }
        }

        .pack-foil {
            width: 170px;
            height: 230px;
            margin: 20px auto;
            border-radius: 16px;
            background: linear-gradient(135deg, #22305f, #0b0d18 45%, #ff4d7a 62%, #0b0d18);
            border: 1px solid rgba(255,255,255,0.18);
            position: relative;
            box-shadow: 0 14px 42px rgba(0,0,0,0.42);
            animation: packPulse 1.4s ease-in-out infinite;
            overflow: hidden;
        }

        .pack-foil::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 16px;
            background: linear-gradient(60deg, rgba(255,255,255,0.08), transparent 35%, rgba(255,255,255,0.18));
            mix-blend-mode: screen;
        }

        .pack-open {
            animation: none;
            transform: scale(1.04);
            box-shadow: var(--glow);
        }

        .reveal-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: var(--grid-gap);
            margin-top: 10px;
        }

        .sticker.reveal {
            opacity: 0;
            transform: translateY(14px) scale(0.98);
            animation: popIn 0.32s ease forwards;
        }

        @keyframes popIn {
            0% { opacity: 0; transform: translateY(14px) scale(0.98); }
            70% { opacity: 1; transform: translateY(-2px) scale(1.02); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        .sticker {
            border-radius: 18px;
            border: 1px solid var(--stroke);
            background: radial-gradient(circle at 20% 20%, rgba(255, 255, 255, 0.04), transparent 40%), var(--panel-2);
            padding: 12px;
            display: grid;
            gap: 9px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 12px 30px rgba(0,0,0,0.32);
            isolation: isolate;
        }

        .sticker .frame {
            position: absolute;
            inset: -4px;
            background-image: var(--frame);
            background-size: cover;
            background-position: center;
            opacity: 0.45;
            filter: saturate(1.1);
            mix-blend-mode: screen;
        }

        .sticker .content {
            position: relative;
            z-index: 1;
            display: grid;
            gap: 8px;
        }

        .sticker-thumb {
            position: relative;
        }

        .sticker img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: linear-gradient(145deg, #14192f, #0b0d18);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06);
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
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 0.85rem;
            background: rgba(255, 255, 255, 0.08);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.07);
        }

        .rarity {
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .rarity-lendaria { color: #f7c948; }
        .rarity-epica { color: #d8a7ff; }
        .rarity-rara { color: #6bd4ff; }
        .rarity-comum { color: #a7f3ce; }

        .status-chip {
            font-size: 0.85rem;
            color: #cdd4ee;
            padding: 5px 8px;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.03);
        }

        .sticker-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }

        .card-chip {
            position: absolute;
            top: 10px;
            left: 10px;
            background: rgba(5, 7, 18, 0.72);
            border: 1px solid rgba(255,255,255,0.14);
            border-radius: 10px;
            padding: 6px 8px;
            font-size: 0.8rem;
            color: #e9ecf8;
            backdrop-filter: blur(6px);
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

        <div style="margin-top:18px; display:flex; justify-content:flex-start;">
            <div class="tabs">
                <div class="tab active" data-tab="shop">Loja</div>
                <div class="tab" data-tab="album">Álbum</div>
            </div>
        </div>

        <div class="layout">
            <div class="card tab-content" data-tab-content="shop">
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

            <div class="card tab-content hidden" data-tab-content="album">
                <div class="album-controls">
                    <h2 style="margin:0;">Álbum</h2>
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

        const rarityFrames = {
            lendaria: 'figuras/4-lendario.jpeg',
            epica: 'figuras/3-epico.jpeg',
            rara: 'figuras/2-raro.jpeg',
            comum: 'figuras/1-comum.jpeg',
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
            const area = document.getElementById('packArea');
            area.innerHTML = `
                <div class="pack-foil" id="packFoil"></div>
                <div class="muted" style="text-align:center;">Abrindo pacote...</div>
            `;
            const foil = document.getElementById('packFoil');
            foil.classList.remove('pack-open');

            // Simula o rasgar do pacote antes de revelar
            setTimeout(() => {
                foil.classList.add('pack-open');
                state.lastPack = [];
                for (let i = 0; i < 3; i += 1) {
                    const sticker = pullSticker();
                    if (sticker) state.lastPack.push(sticker);
                }
                saveCollection();
                renderPack(true);
                renderAlbum();
                renderStats();
            }, 700);
        }

        function renderPack(withReveal = false) {
            const area = document.getElementById('packArea');
            if (!state.lastPack.length) {
                area.innerHTML = '<div class="muted empty">Nenhum pacote aberto ainda.</div>';
                return;
            }
            const cards = state.lastPack.map((sticker, idx) => {
                const rarity = rarityConfig[sticker.rarity] || {};
                const frame = rarityFrames[sticker.rarity] || rarityFrames.comum;
                const delay = withReveal ? idx * 90 : 0;
                return `
                    <div class="sticker ${withReveal ? 'reveal' : ''}" style="animation-delay:${delay}ms; --frame:url('${frame}');">
                        <div class="frame"></div>
                        <div class="glow-border"></div>
                        <div class="content">
                            <div class="sticker-thumb">
                                <span class="card-chip">${sticker.category}</span>
                                <img src="${sticker.image}" alt="${sticker.name}" onerror="this.onerror=null;this.src='${FALLBACK_IMAGE}';">
                            </div>
                            <div class="code">#${sticker.id}</div>
                            <div class="sticker-meta">
                                <div>
                                    <div style="font-weight:700;">${sticker.name}</div>
                                    <div class="muted" style="font-size:0.9rem;">${sticker.blurb || ''}</div>
                                </div>
                                <div class="badge">
                                    <span class="rarity rarity-${sticker.rarity}">${rarity.label || sticker.rarity}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
            area.innerHTML = `<div class="reveal-grid">${cards}</div>`;
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
                const frame = rarityFrames[sticker.rarity] || rarityFrames.comum;
                const duplicates = Math.max(owned - 1, 0);
                return `
                    <div class="sticker" style="--frame:url('${frame}');">
                        <div class="frame"></div>
                        <div class="glow-border"></div>
                        <div class="content">
                            <div class="sticker-thumb">
                                <span class="card-chip">${sticker.category}</span>
                                <img src="${sticker.image}" alt="${sticker.name}" onerror="this.onerror=null;this.src='${FALLBACK_IMAGE}';">
                            </div>
                            <div class="code">#${sticker.id} • ${sticker.category}</div>
                            <div class="sticker-meta">
                                <div>
                                    <div style="font-weight:700;">${sticker.name}</div>
                                    <div class="muted" style="font-size:0.9rem;">${sticker.blurb || ''}</div>
                                </div>
                                <span class="badge">
                                    <span class="rarity rarity-${sticker.rarity}">${rarity.label || sticker.rarity}</span>
                                </span>
                            </div>
                            <div class="sticker-meta">
                                <span class="status-chip">${placed ? 'Colada' : 'Faltando'}</span>
                                ${duplicates ? `<span class="status-chip">Repetidas: ${duplicates}</span>` : ''}
                            </div>
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

        function setupTabs() {
            document.querySelectorAll('.tab').forEach((tab) => {
                tab.addEventListener('click', () => {
                    const target = tab.dataset.tab;
                    document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t === tab));
                    document.querySelectorAll('.tab-content').forEach((panel) => {
                        panel.classList.toggle('hidden', panel.dataset.tabContent !== target);
                    });
                });
            });
        }

        document.getElementById('openPackBtn').addEventListener('click', openPack);
        setupCategoryFilter();
        setupTabs();
        renderAlbum();
        renderStats();
    </script>
</body>
</html>
