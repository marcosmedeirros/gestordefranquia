<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$teams = [
    'Anchorage Envood',
    'Athens Olympics',
    'Boston Panthers',
    'Buffalo Blackouts',
    'Calgary Mooses',
    'Chicago Dope',
    'Colorado Frostborn',
    'Dallas Blues',
    'El Paso Guerreros',
    'Hawaii Heatwave',
    'Houston Parfums',
    'Kansas City Swifties',
    'Kentucky Cavalinhos',
    'Los Angeles Celestials',
    'Los Angeles Souks',
    'Louisville Shuffle',
    'México City Catrinas',
    'Miami Sunsets',
    'Milwaukee Beezz',
    'New Jersey Reapers',
    'New York Mafia',
    'Oakland Blue Foxes',
    'Oklahoma Gunslingers',
    'Oregon Puddles',
    'Orlando Black Lions',
    'Philadelphia Devils',
    'Pittsburgh Phantoms',
    'San Antonio Vultures',
    'San Francisco JoyBoys',
    'San Jose Carpinteros',
    'St. Louis Archers',
    'Washington Peacemakers'
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Álbum FBA - Pacotes e Figurinhas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Poppins:wght@300;400;600&display=swap');
        body { font-family: 'Poppins', sans-serif; background-color: #050505; color: #fff; overflow-x: hidden; }
        h1, h2, h3, .fba-title { font-family: 'Oswald', sans-serif; text-transform: uppercase; }
        .hidden { display: none !important; }

        .rarity-comum { border-color: #b0b0b0; background: linear-gradient(145deg, #1a1a1a, #2a2a2a); }
        .rarity-rara { border-color: #ef4444; background: linear-gradient(145deg, #2a0a0a, #6b1111); box-shadow: 0 0 10px rgba(239, 68, 68, 0.55); }
        .rarity-epico { border-color: #ff6b6b; background: linear-gradient(145deg, #3b0f0f, #9b1c1c); box-shadow: 0 0 15px rgba(255, 107, 107, 0.65); }
        .rarity-lendario { border-color: #ffffff; background: linear-gradient(145deg, #400000, #b30000); box-shadow: 0 0 20px rgba(255, 255, 255, 0.8); animation: pulse-white 2s infinite; }
        @keyframes pulse-white { 0% { box-shadow: 0 0 12px rgba(255,255,255,.6); } 50% { box-shadow: 0 0 28px rgba(255,255,255,1); } 100% { box-shadow: 0 0 12px rgba(255,255,255,.6); } }

        .pack { transition: transform .2s; cursor: pointer; border: 3px solid #ef4444; box-shadow: 0 10px 25px rgba(239, 68, 68, .45); }
        .pack:hover { transform: scale(1.05) translateY(-10px); }
        .pack-info-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 30px;
            height: 30px;
            border-radius: 9999px;
            border: 2px solid rgba(255,255,255,.75);
            background: rgba(0,0,0,.55);
            color: #fff;
            font-weight: 800;
            line-height: 1;
            z-index: 2;
        }
        .pack-info-btn:hover { background: rgba(0,0,0,.78); }
        .shaking { animation: shake .5s cubic-bezier(.36,.07,.19,.97) both; animation-iteration-count: 3; }
        @keyframes shake {
            10%, 90% { transform: translate3d(-2px, 0, 0) rotate(-2deg); }
            20%, 80% { transform: translate3d(4px, 0, 0) rotate(2deg); }
            30%, 50%, 70% { transform: translate3d(-6px, 0, 0) rotate(-4deg); }
            40%, 60% { transform: translate3d(6px, 0, 0) rotate(4deg); }
        }

        .modal { backdrop-filter: blur(10px); background-color: rgba(0, 0, 0, 0.86); }
        .revealed-card { opacity: 0; transform: scale(.5) translateY(100px); transition: all .6s cubic-bezier(.175,.885,.32,1.275); }
        .revealed-card.show { opacity: 1; transform: scale(1) translateY(0); }

        .card-container { perspective: 1000px; cursor: pointer; }
        .card-inner { position: relative; width: 100%; height: 100%; transition: transform .6s cubic-bezier(.175,.885,.32,1.275); transform-style: preserve-3d; }
        .card-container.flipped .card-inner { transform: rotateY(180deg); }
        .card-front, .card-back { position: absolute; width: 100%; height: 100%; backface-visibility: hidden; border-radius: .75rem; overflow: hidden; }
        .card-back { background: linear-gradient(135deg, #191919, #070707); border: 4px solid #303030; }
        .card-front { transform: rotateY(180deg); }

        .album-slot { aspect-ratio: 2.5 / 3.5; border: 2px dashed #3d3d3d; background-color: rgba(32, 32, 32, .5); transition: all .3s; }
        .album-slot.collected { border-style: solid; border-width: 3px; background-image: url('https://www.transparenttextures.com/patterns/carbon-fibre.png'); }
        .album-slot:not(.collected) { filter: grayscale(100%) opacity(40%); }

        .basketball-court {
            background-color: #2a2a2a;
            background-image: repeating-linear-gradient(90deg, transparent, transparent 40px, rgba(255,255,255,.04) 40px, rgba(255,255,255,.04) 80px);
            border: 4px solid #ef4444;
            position: relative;
            width: 100%;
            max-width: 500px;
            aspect-ratio: 1 / 1.2;
            margin: 0 auto;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: inset 0 0 50px rgba(0,0,0,.45);
        }
        .court-paint { border: 4px solid #ffffff; border-bottom: none; position: absolute; bottom: 0; left: 50%; transform: translateX(-50%); width: 160px; height: 200px; background-color: #1c1c1c; }
        .court-3pt { border: 4px solid #ffffff; border-radius: 50%; position: absolute; bottom: -50px; left: 50%; transform: translateX(-50%); width: 450px; height: 400px; pointer-events: none; }
        .court-slot { position: absolute; width: 70px; height: 98px; transform: translate(-50%, -50%); border: 2px dashed rgba(255,255,255,.8); border-radius: 8px; background: rgba(0,0,0,.45); cursor: pointer; transition: all .2s; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.5rem; z-index: 10; }
        .court-slot:hover { background: rgba(255,255,255,.1); border-style: solid; }
        .court-slot img { width: 100%; height: 100%; object-fit: cover; border-radius: 6px; border: 2px solid white; }
        .pos-pg { top: 15%; left: 50%; } .pos-sg { top: 40%; left: 20%; } .pos-sf { top: 40%; left: 80%; } .pos-pf { top: 75%; left: 30%; } .pos-c { top: 75%; left: 70%; }
        @media (max-width: 640px) {
            .basketball-court { max-width: 320px; }
            .court-paint { width: 120px; height: 160px; }
            .court-3pt { width: 320px; height: 300px; bottom: -40px; }
            .court-slot { width: 52px; height: 74px; font-size: 1.15rem; }
            .pack { width: 11rem !important; height: 14rem !important; }
            .revealed-card { width: 9.5rem !important; height: 13.5rem !important; }
        }
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <header class="bg-black border-b border-red-700 p-4 sticky top-0 z-10 shadow-lg">
        <div class="container mx-auto flex justify-between items-center gap-3">
            <div class="flex items-center gap-3">
                <img src="/img/fba-logo.png" alt="FBA" class="h-12 w-auto" onerror="this.style.display='none'">
            </div>
            <div class="flex items-center gap-3">
                <a href="index.php" class="bg-zinc-900 px-3 py-2 rounded-lg border border-red-700 text-sm hover:bg-zinc-800">Voltar</a>
                <div class="bg-zinc-900 px-4 py-2 rounded-lg border border-red-700">Moedas: <span id="coin-count" class="text-xl font-bold ml-2 text-white">0</span></div>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-4 mt-6 flex md:justify-center justify-start gap-2 md:gap-4 border-b border-zinc-700 pb-2 flex-nowrap overflow-x-auto">
        <button onclick="switchTab('album')" id="tab-album" class="px-4 md:px-6 py-2 rounded-t-lg bg-red-700 font-bold fba-title">Meu Álbum</button>
        <button onclick="switchTab('team')" id="tab-team" class="px-4 md:px-6 py-2 rounded-t-lg bg-zinc-900 text-zinc-300 font-bold fba-title hover:bg-zinc-800">Meu Time</button>
        <button onclick="switchTab('ranking')" id="tab-ranking" class="px-4 md:px-6 py-2 rounded-t-lg bg-zinc-900 text-zinc-300 font-bold fba-title hover:bg-zinc-800">Ranking</button>
        <button onclick="switchTab('market')" id="tab-market" class="px-4 md:px-6 py-2 rounded-t-lg bg-zinc-900 text-zinc-300 font-bold fba-title hover:bg-zinc-800">Mercado</button>
        <button onclick="switchTab('trades')" id="tab-trades" class="px-4 md:px-6 py-2 rounded-t-lg bg-zinc-900 text-zinc-300 font-bold fba-title hover:bg-zinc-800">Trocas</button>
        <button onclick="switchTab('store')" id="tab-store" class="px-4 md:px-6 py-2 rounded-t-lg bg-zinc-900 text-zinc-300 font-bold fba-title hover:bg-zinc-800">Pacotes</button>
        <button onclick="switchTab('admin')" id="tab-admin" class="px-4 md:px-6 py-2 rounded-t-lg bg-zinc-900 text-zinc-300 font-bold fba-title hover:bg-zinc-800 hidden">Admin</button>
    </div>

    <main class="container mx-auto px-4 py-8 flex-grow">
        <section id="section-album" class="block">
            <h2 class="text-2xl font-bold fba-title">Plantel FBA 2026</h2>
            <p class="text-zinc-400" id="album-progress">Progresso: 0 figurinhas</p>
            <div class="mt-4">
                <div class="flex items-center justify-between gap-3 mb-2">
                    <h3 class="fba-title text-lg text-red-400">Resgate de Colecoes</h3>
                    <span class="text-xs text-zinc-400">Completo = 500 FBA Points</span>
                </div>
                <div id="collection-rewards" class="flex gap-3 overflow-x-auto pb-2"></div>
            </div>
            <div class="mt-4 max-w-md">
                <input id="album-collection-filter" type="text" placeholder="Pesquisar por coleção..." class="w-full bg-zinc-900 border border-zinc-600 rounded px-3 py-2">
            </div>
            <div id="album-container" class="flex flex-col gap-8 mt-6"></div>
        </section>

        <section id="section-team" class="hidden text-center">
            <h2 class="text-3xl font-bold fba-title mb-2">Quinteto Ideal</h2>
            <p class="text-zinc-400 mb-6">Escale suas melhores cartas na quadra.</p>
            <div class="flex justify-center mb-8">
                <div class="bg-zinc-900 border border-red-700 rounded-full px-8 py-3">OVR DO TIME: <span id="team-ovr-display" class="text-3xl font-black text-white ml-2">0</span></div>
            </div>
            <div class="mb-6">
                <button type="button" onclick="clearTeam()" class="bg-zinc-900 hover:bg-zinc-800 border border-red-700 rounded-lg px-5 py-2 font-bold">Limpar escalação</button>
            </div>
            <div class="basketball-court shadow-2xl">
                <div class="court-3pt"></div>
                <div class="court-paint"></div>
                <div class="court-slot pos-pg" onclick="openSelectModal(0)" id="court-slot-0">+</div>
                <div class="court-slot pos-sg" onclick="openSelectModal(1)" id="court-slot-1">+</div>
                <div class="court-slot pos-sf" onclick="openSelectModal(2)" id="court-slot-2">+</div>
                <div class="court-slot pos-pf" onclick="openSelectModal(3)" id="court-slot-3">+</div>
                <div class="court-slot pos-c" onclick="openSelectModal(4)" id="court-slot-4">+</div>
            </div>
        </section>

        <section id="section-ranking" class="hidden text-center">
            <h2 class="text-3xl font-bold fba-title mb-2">Ranking Global</h2>
            <div class="max-w-3xl mx-auto bg-black rounded-xl border border-red-700 overflow-hidden shadow-2xl mt-8">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-zinc-900 text-zinc-300 text-sm uppercase tracking-wider fba-title">
                            <th class="p-4 w-20 text-center border-b border-zinc-700">Pos</th>
                            <th class="p-4 border-b border-zinc-700">Jogador</th>
                            <th class="p-4 w-32 text-center border-b border-zinc-700">OVR</th>
                        </tr>
                    </thead>
                    <tbody id="ranking-tbody"></tbody>
                </table>
            </div>
        </section>

        <section id="section-market" class="hidden">
            <h2 class="text-3xl font-bold fba-title mb-2">Mercado de Cartas</h2>
            <p class="text-zinc-400 mb-4">Venda duplicadas e compre cartas de outros usuarios com pontos.</p>

            <div class="bg-black border border-red-700 rounded-xl p-4 mb-4">
                <h3 class="fba-title text-xl mb-3">Criar anuncio</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <select id="market-sell-card" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2 md:col-span-2"></select>
                    <input id="market-sell-price" type="number" min="1" step="1" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2" placeholder="Preco em pontos">
                    <button id="market-sell-btn" class="bg-red-700 hover:bg-red-600 rounded px-4 py-2 font-bold">Anunciar</button>
                </div>
                <p id="market-sell-hint" class="text-zinc-400 text-sm mt-2"></p>
            </div>

            <div class="bg-black border border-red-700 rounded-xl p-4 mb-4">
                <div class="flex items-center justify-between gap-2 mb-3">
                    <h3 class="fba-title text-xl">Minhas cartas a venda</h3>
                    <button id="market-toggle-mine" class="bg-zinc-800 hover:bg-zinc-700 border border-zinc-600 rounded px-3 py-2 text-sm font-bold">Ver minhas cartas a venda</button>
                </div>
                <div id="market-mine-wrap" class="hidden">
                    <div id="market-mine-list" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3"></div>
                </div>
            </div>

            <div class="bg-black border border-red-700 rounded-xl p-4">
                <h3 class="fba-title text-xl mb-3">Cartas a venda</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                    <input id="market-filter-name" type="text" placeholder="Filtrar por nome" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2">
                    <select id="market-filter-collection" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2">
                        <option value="">Todas as colecoes</option>
                    </select>
                    <select id="market-filter-rarity" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2">
                        <option value="">Todas as raridades</option>
                        <option value="comum">Comum</option>
                        <option value="rara">Rara</option>
                        <option value="epico">Epica</option>
                        <option value="lendario">Lendaria</option>
                    </select>
                </div>
                <label class="inline-flex items-center gap-2 text-sm text-zinc-300 mb-3">
                    <input id="market-filter-missing" type="checkbox" class="accent-red-500">
                    Ainda não tenho
                </label>
                <div id="market-list" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3"></div>
            </div>
            <p id="market-feedback" class="text-sm text-zinc-300 mt-3"></p>
        </section>

        <section id="section-trades" class="hidden">
            <h2 class="text-3xl font-bold fba-title mb-2">Trocas</h2>
            <p class="text-zinc-400 mb-4">Selecione figurinhas do seu album e troque por pacotes especiais.</p>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="bg-black border border-red-700 rounded-xl p-4">
                    <h3 class="fba-title text-xl mb-3">3 figurinhas = Pacote Premium</h3>
                    <div class="grid grid-cols-1 gap-2">
                        <select id="trade-premium-1" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <select id="trade-premium-2" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <select id="trade-premium-3" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <button id="trade-premium-btn" class="bg-red-700 hover:bg-red-600 rounded px-4 py-2 font-bold">Trocar por Premium</button>
                    </div>
                </div>

                <div class="bg-black border border-red-700 rounded-xl p-4">
                    <h3 class="fba-title text-xl mb-3">5 figurinhas = Pacote Ultra</h3>
                    <div class="grid grid-cols-1 gap-2">
                        <select id="trade-ultra-1" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <select id="trade-ultra-2" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <select id="trade-ultra-3" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <select id="trade-ultra-4" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <select id="trade-ultra-5" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <button id="trade-ultra-btn" class="bg-red-700 hover:bg-red-600 rounded px-4 py-2 font-bold">Trocar por Ultra</button>
                    </div>
                </div>

                <div class="bg-black border border-red-700 rounded-xl p-4">
                    <h3 class="fba-title text-xl mb-3">10 figurinhas = 1 nova</h3>
                    <p class="text-zinc-400 text-sm mb-3">Recebe uma figurinha que voce ainda nao tem.</p>
                    <div class="grid grid-cols-1 gap-2">
                        <select id="trade-missing-1" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <select id="trade-missing-2" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <select id="trade-missing-3" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <select id="trade-missing-4" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <select id="trade-missing-5" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <select id="trade-missing-6" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <select id="trade-missing-7" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <select id="trade-missing-8" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <select id="trade-missing-9" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <select id="trade-missing-10" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2"></select>
                        <button id="trade-missing-btn" class="bg-red-700 hover:bg-red-600 rounded px-4 py-2 font-bold">Trocar por nova</button>
                    </div>
                </div>
            </div>
            <p id="trade-feedback" class="text-sm text-zinc-300 mt-3"></p>
        </section>

        <section id="section-store" class="hidden text-center">
            <h2 class="text-3xl font-bold fba-title mb-2">Loja de Pacotes</h2>
            <div class="flex flex-wrap justify-center gap-8 mt-10">
                <div class="flex flex-col items-center">
                    <div id="pack-daily" class="pack w-64 h-80 rounded-xl relative flex flex-col justify-center items-center overflow-hidden" style="background:linear-gradient(135deg,#1f2937,#0f172a)">
                        <h3 class="text-3xl font-black italic">DIARIO</h3>
                        <div class="text-sm text-zinc-200 mt-2">1x por dia</div>
                    </div>
                    <button id="pack-daily-btn" class="mt-4 bg-zinc-800 hover:bg-zinc-700 px-6 py-2 rounded-full font-bold border border-red-700">Resgatar</button>
                    <div id="pack-daily-hint" class="text-xs text-zinc-400 mt-2"></div>
                </div>
                <div class="flex flex-col items-center">
                    <div id="pack-basico" class="pack w-64 h-80 rounded-xl relative flex flex-col justify-center items-center overflow-hidden" style="background:linear-gradient(135deg,#2e2e2e,#111111)" onclick="openPack('basico')"><button type="button" class="pack-info-btn" onclick="event.stopPropagation(); showPackOdds('basico')">!</button><h3 class="text-3xl font-black italic">BÁSICO</h3></div>
                    <button class="mt-4 bg-zinc-800 hover:bg-zinc-700 px-6 py-2 rounded-full font-bold border border-red-700" onclick="openPack('basico')">30</button>
                </div>
                <div class="flex flex-col items-center">
                    <div id="pack-premium" class="pack w-64 h-80 rounded-xl relative flex flex-col justify-center items-center overflow-hidden" style="background:linear-gradient(135deg,#7f1d1d,#1f0a0a)" onclick="openPack('premium')"><button type="button" class="pack-info-btn" onclick="event.stopPropagation(); showPackOdds('premium')">!</button><h3 class="text-3xl font-black italic">PREMIUM</h3></div>
                    <button class="mt-4 bg-red-800 hover:bg-red-700 px-6 py-2 rounded-full font-bold border border-red-500" onclick="openPack('premium')">60</button>
                </div>
                <div class="flex flex-col items-center">
                    <div id="pack-ultra" class="pack w-64 h-80 rounded-xl relative flex flex-col justify-center items-center overflow-hidden" style="background:linear-gradient(135deg,#ffffff,#b91c1c)" onclick="openPack('ultra')"><button type="button" class="pack-info-btn" onclick="event.stopPropagation(); showPackOdds('ultra')">!</button><h3 class="text-3xl font-black italic text-black">ULTRA</h3></div>
                    <button class="mt-4 bg-white hover:bg-zinc-200 text-black px-6 py-2 rounded-full font-bold border border-red-700" onclick="openPack('ultra')">100</button>
                </div>
            </div>
        </section>

        <section id="section-admin" class="hidden">
            <h2 class="text-3xl font-bold fba-title mb-2">Admin de Cartas</h2>
            <p class="text-zinc-400 mb-6">Cadastrar por coleção, time, posição, raridade e upload de imagem.</p>
            <div class="bg-black border border-red-700 rounded-xl p-5 mb-6">
                <h3 class="fba-title text-xl mb-2">Colecoes nos pacotinhos</h3>
                <p class="text-zinc-400 mb-4">Ative para permitir que a colecao apareca nos pacotes. Desative para remover a chance.</p>
                <div id="admin-pack-collections" class="flex flex-wrap gap-2"></div>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-black border border-red-700 rounded-xl p-5">
                    <form id="admin-card-form" class="space-y-3">
                        <input type="hidden" id="admin-card-id" value="">
                        <input id="admin-collection" placeholder="Nome da coleção" class="w-full bg-zinc-900 border border-zinc-600 rounded px-3 py-2" required>
                        <select id="admin-team" class="w-full bg-zinc-900 border border-zinc-600 rounded px-3 py-2" required>
                            <option value="">Selecione o time</option>
                            <?php foreach ($teams as $team): ?>
                                <option value="<?= htmlspecialchars($team, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($team, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                            <option value="__other__">Outro (digitar)</option>
                        </select>
                        <input id="admin-team-other" placeholder="Digite o nome do time" class="w-full bg-zinc-900 border border-zinc-600 rounded px-3 py-2 hidden">
                        <input id="admin-name" placeholder="Nome da carta" class="w-full bg-zinc-900 border border-zinc-600 rounded px-3 py-2" required>
                        <div class="grid grid-cols-3 gap-3">
                            <select id="admin-position" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2">
                                <option>PG</option><option>SG</option><option>SF</option><option>PF</option><option>C</option>
                            </select>
                            <select id="admin-rarity" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2">
                                <option value="comum">Comum</option><option value="rara">Rara</option><option value="epico">Épica</option><option value="lendario">Lendária</option>
                            </select>
                            <input id="admin-ovr" type="number" min="50" max="99" placeholder="OVR" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2" required>
                        </div>
                        <input id="admin-image-file" type="file" accept="image/*" class="w-full bg-zinc-900 border border-zinc-600 rounded px-3 py-2">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                            <button id="admin-save-btn" class="w-full bg-red-700 hover:bg-red-600 rounded py-2 font-bold" type="submit">Cadastrar Carta</button>
                            <button id="admin-cancel-edit-btn" class="w-full bg-zinc-700 hover:bg-zinc-600 rounded py-2 font-bold hidden" type="button">Cancelar edição</button>
                            <button id="admin-delete-btn" class="w-full bg-red-900 hover:bg-red-800 rounded py-2 font-bold hidden" type="button">Excluir carta</button>
                        </div>
                        <small class="text-zinc-400 block">Na edição, a imagem é opcional (envie só se quiser trocar).</small>
                    </form>
                    <p id="admin-feedback" class="mt-3 text-sm text-zinc-300"></p>
                </div>
                <div class="bg-black border border-red-700 rounded-xl p-5">
                    <h3 class="fba-title text-xl mb-3">Últimas Cartas</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mb-3">
                        <select id="admin-filter-collection" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2">
                            <option value="">Todas as coleções</option>
                        </select>
                        <select id="admin-filter-rarity" class="bg-zinc-900 border border-zinc-600 rounded px-3 py-2">
                            <option value="">Todos os tipos</option>
                            <option value="comum">Comum</option>
                            <option value="rara">Rara</option>
                            <option value="epico">Épica</option>
                            <option value="lendario">Lendária</option>
                        </select>
                        <button id="admin-filter-clear" class="bg-zinc-800 hover:bg-zinc-700 rounded py-2 font-bold" type="button">Limpar filtros</button>
                    </div>
                    <div id="admin-cards-list" class="space-y-2 max-h-[420px] overflow-y-auto pr-2"></div>
                </div>
            </div>
        </section>
    </main>

    <div id="reveal-modal" class="fixed inset-0 modal z-50 hidden flex-col justify-center items-center">
        <h2 class="text-4xl fba-title text-white mb-10 animate-pulse" id="reveal-title">Revelando...</h2>
        <div id="revealed-cards-container" class="flex flex-wrap justify-center gap-6 max-w-5xl px-4"></div>
        <div class="mt-12 flex flex-wrap justify-center gap-3">
            <button id="btn-close-modal" class="px-8 py-3 bg-zinc-700 hover:bg-zinc-600 rounded-lg font-bold fba-title hidden" onclick="closeRevealModal()">Fechar</button>
            <button id="btn-open-again" class="px-8 py-3 bg-red-700 hover:bg-red-600 rounded-lg font-bold fba-title hidden" onclick="openPackAgain()">Abrir novamente</button>
        </div>
    </div>

    <div id="select-modal" class="fixed inset-0 modal z-50 hidden flex-col justify-center items-center">
        <div class="bg-black border border-red-700 rounded-xl p-6 w-11/12 max-w-4xl max-h-[80vh] flex flex-col">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl fba-title text-white">Selecione uma Carta</h2>
                <button onclick="closeSelectModal()" class="text-zinc-400 hover:text-white text-3xl font-bold">&times;</button>
            </div>
            <div id="select-cards-container" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4 overflow-y-auto pr-2 pb-4"></div>
        </div>
    </div>

    <div id="album-card-modal" class="fixed inset-0 modal z-50 hidden flex-col justify-center items-center py-6 px-3">
        <div class="bg-black border border-red-700 rounded-xl p-4 w-full max-w-[20rem] sm:max-w-[22rem] max-h-[92vh] overflow-hidden relative">
            <button onclick="closeAlbumCardModal()" class="absolute top-2 right-3 text-zinc-300 hover:text-white text-3xl font-bold leading-none" aria-label="Fechar">&times;</button>
            <div class="flex justify-between items-center mb-3 pr-8">
                <h3 class="fba-title text-xl text-white">Carta</h3>
            </div>
            <img id="album-card-modal-img" src="" alt="Carta" class="w-full max-h-[58vh] object-contain rounded-lg border border-zinc-700">
            <div class="mt-3 text-white font-bold" id="album-card-modal-name"></div>
            <div class="text-zinc-300 text-sm" id="album-card-modal-meta"></div>
            <div class="text-red-300 text-sm mt-1" id="album-card-modal-count"></div>
            <div class="text-zinc-400 text-xs mt-2">Aperte ESC para sair</div>
        </div>
    </div>

    <div id="ranking-team-modal" class="fixed inset-0 modal z-50 hidden flex-col justify-center items-center py-6 px-3">
        <div class="bg-black border border-red-700 rounded-xl p-4 w-full max-w-5xl max-h-[92vh] overflow-hidden relative">
            <button onclick="closeRankingTeamModal()" class="absolute top-2 right-3 text-zinc-300 hover:text-white text-3xl font-bold leading-none" aria-label="Fechar">&times;</button>
            <div class="pr-8 mb-3">
                <h3 class="fba-title text-xl text-white" id="ranking-team-modal-title">Quinteto</h3>
                <div class="text-sm text-white font-bold">OVR: <span id="ranking-team-modal-ovr">0</span></div>
            </div>
            <div id="ranking-team-modal-loading" class="text-zinc-300 text-sm mb-3 hidden">Carregando quinteto...</div>
            <div id="ranking-team-modal-grid" class="grid grid-cols-2 md:grid-cols-5 gap-3 overflow-y-auto max-h-[72vh] pr-1"></div>
            <div class="text-zinc-400 text-xs mt-3">Aperte ESC para sair</div>
        </div>
    </div>

    <script src="album-fba.js"></script>
</body>
</html>
