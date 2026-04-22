<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$teams = [
    'Anchorage Envood','Athens Olympics','Boston Panthers','Buffalo Blackouts',
    'Calgary Mooses','Chicago Dope','Colorado Frostborn','Dallas Blues',
    'El Paso Guerreros','Hawaii Heatwave','Houston Parfums','Kansas City Swifties',
    'Kentucky Cavalinhos','Los Angeles Celestials','Los Angeles Souks','Louisville Shuffle',
    'México City Catrinas','Miami Sunsets','Milwaukee Beezz','New Jersey Reapers',
    'New York Mafia','Oakland Blue Foxes','Oklahoma Gunslingers','Oregon Puddles',
    'Orlando Black Lions','Philadelphia Devils','Pittsburgh Phantoms','San Antonio Vultures',
    'San Francisco JoyBoys','San Jose Carpinteros','St. Louis Archers','Washington Peacemakers'
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#fc0025">
  <title>Álbum FBA - Pacotes e Figurinhas</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    /* ── FBA Design Tokens ── */
    :root {
      --red:        #fc0025;
      --red-soft:   rgba(252,0,37,.10);
      --red-glow:   rgba(252,0,37,.18);
      --bg:         #07070a;
      --panel:      #101013;
      --panel-2:    #16161a;
      --panel-3:    #1c1c21;
      --border:     rgba(255,255,255,.06);
      --border-md:  rgba(255,255,255,.10);
      --border-red: rgba(252,0,37,.22);
      --text:       #f0f0f3;
      --text-2:     #868690;
      --text-3:     #48484f;
      --amber:      #f59e0b;
      --green:      #22c55e;
      --font:       'Poppins', sans-serif;
      --radius:     14px;
      --radius-sm:  10px;
      --ease:       cubic-bezier(.2,.8,.2,1);
      --t:          200ms;
    }
    *, *::before, *::after { box-sizing: border-box; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; overflow-x: hidden; }
    h1, h2, h3, .fba-title { font-family: 'Oswald', sans-serif; text-transform: uppercase; }
    .hidden { display: none !important; }

    /* ── Topbar ── */
    .topbar {
      position: sticky; top: 0; z-index: 300; height: 58px;
      background: var(--panel); border-bottom: 1px solid var(--border);
      display: flex; align-items: center; padding: 0 20px; gap: 14px;
    }
    .topbar-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; flex-shrink: 0; }
    .topbar-logo {
      width: 32px; height: 32px; border-radius: 8px; background: var(--red);
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 12px; color: #fff; font-family: var(--font);
    }
    .topbar-name { font-weight: 800; font-size: 15px; color: var(--text); font-family: var(--font); }
    .topbar-name span { color: var(--red); }
    .topbar-spacer { flex: 1; }
    .balance-chip {
      display: flex; align-items: center; gap: 6px;
      background: var(--panel-2); border: 1px solid var(--border);
      border-radius: 999px; padding: 5px 13px;
      font-size: 12px; font-weight: 700; color: var(--text); font-family: var(--font);
    }
    .balance-chip i { color: var(--red); font-size: 13px; }
    .topbar-actions { display: flex; align-items: center; gap: 6px; }
    .icon-btn {
      width: 32px; height: 32px; border-radius: 8px;
      background: transparent; border: 1px solid var(--border);
      color: var(--text-2); display: flex; align-items: center; justify-content: center;
      font-size: 14px; cursor: pointer; text-decoration: none;
      transition: all var(--t) var(--ease);
    }
    .icon-btn:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

    /* ── Tab bar ── */
    .fba-tabs-wrap {
      background: var(--panel); border-bottom: 1px solid var(--border);
      padding: 10px 20px 0; overflow-x: auto;
      display: flex; gap: 2px; scrollbar-width: none;
    }
    .fba-tabs-wrap::-webkit-scrollbar { display: none; }
    .fba-tab {
      padding: 9px 20px; border-radius: 10px 10px 0 0;
      border: 1px solid transparent; border-bottom: none;
      background: transparent; color: var(--text-2);
      font-family: var(--font); font-size: 12px; font-weight: 700;
      cursor: pointer; white-space: nowrap; transition: all var(--t) var(--ease);
    }
    .fba-tab:hover { color: var(--text); background: var(--panel-2); }
    .fba-tab.active-tab {
      background: var(--bg); color: var(--text);
      border-color: var(--border); border-bottom-color: var(--bg);
    }
    .fba-tab.active-tab .tab-dot { background: var(--red); }
    .tab-dot {
      display: inline-block; width: 6px; height: 6px; border-radius: 50%;
      background: transparent; margin-right: 6px; vertical-align: middle;
      transition: background var(--t);
    }

    /* ── Panel/card overrides ── */
    .fba-panel {
      background: var(--panel); border: 1px solid var(--border);
      border-radius: var(--radius); overflow: hidden;
    }
    .fba-panel-head {
      padding: 14px 18px; border-bottom: 1px solid var(--border);
      font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px;
    }
    .fba-panel-head i { color: var(--red); }
    .fba-panel-body { padding: 18px; }

    /* Rarity card styles */
    .rarity-comum  { border-color: #4a4a52; background: linear-gradient(145deg, var(--panel-2), var(--panel-3)); }
    .rarity-rara   { border-color: #ef4444; background: linear-gradient(145deg, #2a0a0a, #6b1111); box-shadow: 0 0 10px rgba(239,68,68,.5); }
    .rarity-epico  { border-color: #ff6b6b; background: linear-gradient(145deg, #3b0f0f, #9b1c1c); box-shadow: 0 0 15px rgba(255,107,107,.6); }
    .rarity-lendario { border-color: #ffffff; background: linear-gradient(145deg, #400000, #b30000); box-shadow: 0 0 20px rgba(255,255,255,.8); animation: pulse-white 2s infinite; }
    @keyframes pulse-white { 0% { box-shadow: 0 0 12px rgba(255,255,255,.6); } 50% { box-shadow: 0 0 28px rgba(255,255,255,1); } 100% { box-shadow: 0 0 12px rgba(255,255,255,.6); } }

    /* Pack cards */
    .pack { transition: transform .2s; cursor: pointer; border: 3px solid var(--red); box-shadow: 0 10px 25px rgba(252,0,37,.35); }
    .pack:hover { transform: scale(1.05) translateY(-10px); }
    .pack-info-btn {
      position: absolute; top: 10px; right: 10px; width: 30px; height: 30px;
      border-radius: 9999px; border: 2px solid rgba(255,255,255,.6);
      background: rgba(0,0,0,.55); color: #fff; font-weight: 800; line-height: 1; z-index: 2;
      cursor: pointer; display: flex; align-items: center; justify-content: center;
    }
    .pack-info-btn:hover { background: rgba(0,0,0,.78); }

    /* Shake animation */
    .shaking { animation: shake .5s cubic-bezier(.36,.07,.19,.97) both; animation-iteration-count: 3; }
    @keyframes shake {
      10%, 90% { transform: translate3d(-2px,0,0) rotate(-2deg); }
      20%, 80% { transform: translate3d(4px,0,0) rotate(2deg); }
      30%, 50%, 70% { transform: translate3d(-6px,0,0) rotate(-4deg); }
      40%, 60% { transform: translate3d(6px,0,0) rotate(4deg); }
    }

    /* Modals */
    .modal { backdrop-filter: blur(12px); background: rgba(0,0,0,.88); }
    .fba-modal-box {
      background: var(--panel); border: 1px solid var(--border);
      border-radius: var(--radius);
    }
    .fba-modal-box-red { background: var(--panel); border: 1px solid var(--border-red); border-radius: var(--radius); }

    /* Card flip */
    .revealed-card { opacity: 0; transform: scale(.5) translateY(100px); transition: all .6s cubic-bezier(.175,.885,.32,1.275); }
    .revealed-card.show { opacity: 1; transform: scale(1) translateY(0); }
    .card-container { perspective: 1000px; cursor: pointer; }
    .card-inner { position: relative; width: 100%; height: 100%; transition: transform .6s cubic-bezier(.175,.885,.32,1.275); transform-style: preserve-3d; }
    .card-container.flipped .card-inner { transform: rotateY(180deg); }
    .card-front, .card-back { position: absolute; width: 100%; height: 100%; backface-visibility: hidden; border-radius: .75rem; overflow: hidden; }
    .card-back { background: linear-gradient(135deg, var(--panel-3), var(--bg)); border: 4px solid var(--border-md); }
    .card-front { transform: rotateY(180deg); }

    /* Album slots */
    .album-slot { aspect-ratio: 2.5/3.5; border: 2px dashed var(--border-md); background: rgba(16,16,19,.5); transition: all .3s; }
    .album-slot.collected { border-style: solid; border-width: 3px; }
    .album-slot:not(.collected) { filter: grayscale(100%) opacity(40%); }

    /* Basketball court */
    .basketball-court {
      background-color: #2a1a0e;
      background-image: repeating-linear-gradient(90deg, transparent, transparent 40px, rgba(255,255,255,.03) 40px, rgba(255,255,255,.03) 80px);
      border: 3px solid var(--red);
      position: relative; width: 100%; max-width: 500px; aspect-ratio: 1/1.2;
      margin: 0 auto; border-radius: 10px; overflow: hidden;
      box-shadow: 0 0 40px rgba(252,0,37,.15), inset 0 0 50px rgba(0,0,0,.5);
    }
    .court-paint { border: 3px solid rgba(255,255,255,.3); border-bottom: none; position: absolute; bottom: 0; left: 50%; transform: translateX(-50%); width: 160px; height: 200px; background: rgba(252,0,37,.08); }
    .court-3pt { border: 3px solid rgba(255,255,255,.2); border-radius: 50%; position: absolute; bottom: -50px; left: 50%; transform: translateX(-50%); width: 450px; height: 400px; pointer-events: none; }
    .court-slot {
      position: absolute; width: 70px; height: 98px; transform: translate(-50%,-50%);
      border: 2px dashed rgba(255,255,255,.5); border-radius: 8px;
      background: rgba(0,0,0,.4); cursor: pointer; transition: all .2s;
      display: flex; align-items: center; justify-content: center;
      color: var(--text-2); font-weight: bold; font-size: 1.5rem; z-index: 10;
    }
    .court-slot:hover { background: rgba(252,0,37,.12); border-color: var(--red); border-style: solid; color: var(--red); }
    .court-slot img { width: 100%; height: 100%; object-fit: cover; border-radius: 6px; border: 2px solid rgba(255,255,255,.3); }
    .pos-pg { top: 15%; left: 50%; } .pos-sg { top: 40%; left: 20%; } .pos-sf { top: 40%; left: 80%; }
    .pos-pf { top: 75%; left: 30%; } .pos-c { top: 75%; left: 70%; }

    /* FBA form inputs */
    .fba-input {
      background: var(--panel-2); border: 1px solid var(--border-md);
      border-radius: var(--radius-sm); padding: 8px 12px;
      color: var(--text); font-family: var(--font); font-size: 13px;
      outline: none; transition: border-color var(--t) var(--ease); width: 100%;
    }
    .fba-input:focus { border-color: var(--red); }
    .fba-input::placeholder { color: var(--text-3); }
    select.fba-input { cursor: pointer; }

    /* Tailwind overrides for inner sections */
    .bg-black, .bg-zinc-900, .bg-zinc-800 { background: var(--panel) !important; }
    .border-red-700, .border-red-500 { border-color: var(--border-red) !important; }
    .text-zinc-400, .text-zinc-300, .text-zinc-500 { color: var(--text-2) !important; }
    .border-zinc-700, .border-zinc-600 { border-color: var(--border-md) !important; }

    @media (max-width: 640px) {
      .basketball-court { max-width: 320px; }
      .court-paint { width: 120px; height: 160px; }
      .court-3pt { width: 320px; height: 300px; bottom: -40px; }
      .court-slot { width: 52px; height: 74px; font-size: 1.15rem; }
      .pack { width: 11rem !important; height: 14rem !important; }
      .revealed-card { width: 9.5rem !important; height: 13.5rem !important; }
      .topbar-name { display: none; }
    }
  </style>
</head>
<body class="min-h-screen flex flex-col">

  <!-- Topbar -->
  <div class="topbar">
    <a href="index.php" class="topbar-brand">
      <div class="topbar-logo">FBA</div>
      <span class="topbar-name">FBA <span>Games</span></span>
    </a>
    <div class="topbar-spacer"></div>
    <div class="balance-chip">
      <i class="bi bi-coin"></i>
      Moedas: <span id="coin-count" style="font-size:15px;margin-left:2px">0</span>
    </div>
    <div class="topbar-actions">
      <a href="index.php" class="icon-btn" title="Voltar"><i class="bi bi-arrow-left"></i></a>
    </div>
  </div>

  <!-- Tab bar -->
  <div class="fba-tabs-wrap">
    <button onclick="switchTab('album')"   id="tab-album"   class="fba-tab active-tab"><span class="tab-dot"></span>Meu Álbum</button>
    <button onclick="switchTab('team')"    id="tab-team"    class="fba-tab"><span class="tab-dot"></span>Meu Time</button>
    <button onclick="switchTab('ranking')" id="tab-ranking" class="fba-tab"><span class="tab-dot"></span>Ranking</button>
    <button onclick="switchTab('market')"  id="tab-market"  class="fba-tab"><span class="tab-dot"></span>Mercado</button>
    <button onclick="switchTab('trades')"  id="tab-trades"  class="fba-tab"><span class="tab-dot"></span>Trocas</button>
    <button onclick="switchTab('store')"   id="tab-store"   class="fba-tab"><span class="tab-dot"></span>Pacotes</button>
    <button onclick="switchTab('admin')"   id="tab-admin"   class="fba-tab hidden"><span class="tab-dot"></span>Admin</button>
  </div>

  <main class="container mx-auto px-4 py-8 flex-grow" style="max-width:1200px">

    <!-- Álbum -->
    <section id="section-album" class="block">
      <h2 class="text-2xl font-bold fba-title" style="color:var(--text)">Plantel FBA 2026</h2>
      <p style="color:var(--text-2);font-size:13px;margin-top:4px" id="album-progress">Progresso: 0 figurinhas</p>
      <div class="mt-4">
        <div class="flex items-center justify-between gap-3 mb-2">
          <h3 class="fba-title text-lg" style="color:var(--red)">Resgate de Coleções</h3>
          <span style="font-size:11px;color:var(--text-3)">Completo = 500 FBA Points</span>
        </div>
        <div id="collection-rewards" class="flex gap-3 overflow-x-auto pb-2"></div>
      </div>
      <div class="mt-4 max-w-md">
        <input id="album-collection-filter" type="text" placeholder="Pesquisar por coleção..." class="fba-input">
      </div>
      <div id="album-container" class="flex flex-col gap-8 mt-6"></div>
    </section>

    <!-- Meu Time -->
    <section id="section-team" class="hidden text-center">
      <h2 class="text-3xl font-bold fba-title mb-2" style="color:var(--text)">Quinteto Ideal</h2>
      <p style="color:var(--text-2);margin-bottom:1.5rem">Escale suas melhores cartas na quadra.</p>
      <div class="flex justify-center mb-8">
        <div style="background:var(--panel-2);border:1px solid var(--border-red);border-radius:999px;padding:10px 32px;font-size:14px">
          OVR DO TIME: <span id="team-ovr-display" style="font-size:28px;font-weight:900;color:var(--text);margin-left:8px">0</span>
        </div>
      </div>
      <div class="mb-6">
        <button type="button" onclick="clearTeam()" style="background:var(--panel-2);border:1px solid var(--border-md);border-radius:var(--radius-sm);padding:8px 20px;color:var(--text-2);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer">
          Limpar escalação
        </button>
      </div>
      <div class="basketball-court shadow-2xl">
        <div class="court-3pt"></div>
        <div class="court-paint"></div>
        <div class="court-slot pos-pg" onclick="openSelectModal(0)" id="court-slot-0">+</div>
        <div class="court-slot pos-sg" onclick="openSelectModal(1)" id="court-slot-1">+</div>
        <div class="court-slot pos-sf" onclick="openSelectModal(2)" id="court-slot-2">+</div>
        <div class="court-slot pos-pf" onclick="openSelectModal(3)" id="court-slot-3">+</div>
        <div class="court-slot pos-c"  onclick="openSelectModal(4)" id="court-slot-4">+</div>
      </div>
    </section>

    <!-- Ranking -->
    <section id="section-ranking" class="hidden text-center">
      <h2 class="text-3xl font-bold fba-title mb-2" style="color:var(--text)">Ranking Global</h2>
      <div class="max-w-3xl mx-auto mt-8" style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden">
        <table class="w-full text-left">
          <thead>
            <tr style="background:var(--panel-2);color:var(--text-3);font-size:10px;text-transform:uppercase;letter-spacing:.6px" class="fba-title">
              <th class="p-4 w-20 text-center" style="border-bottom:1px solid var(--border)">Pos</th>
              <th class="p-4" style="border-bottom:1px solid var(--border)">Jogador</th>
              <th class="p-4 w-32 text-center" style="border-bottom:1px solid var(--border)">OVR</th>
            </tr>
          </thead>
          <tbody id="ranking-tbody"></tbody>
        </table>
      </div>
    </section>

    <!-- Mercado -->
    <section id="section-market" class="hidden">
      <h2 class="text-3xl font-bold fba-title mb-2" style="color:var(--text)">Mercado de Cartas</h2>
      <p style="color:var(--text-2);margin-bottom:1rem;font-size:13px">Venda duplicadas e compre cartas de outros usuários com pontos.</p>

      <div style="background:var(--panel);border:1px solid var(--border-red);border-radius:var(--radius);padding:18px;margin-bottom:14px">
        <h3 class="fba-title text-xl mb-3" style="color:var(--text)">Criar anúncio</h3>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
          <select id="market-sell-card" class="fba-input md:col-span-2"></select>
          <input id="market-sell-price" type="number" min="1" step="1" class="fba-input" placeholder="Preço em pontos">
          <button id="market-sell-btn" style="background:var(--red);color:#fff;border:none;border-radius:var(--radius-sm);padding:9px 16px;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer">Anunciar</button>
        </div>
        <p id="market-sell-hint" style="color:var(--text-2);font-size:12px;margin-top:8px"></p>
      </div>

      <div style="background:var(--panel);border:1px solid var(--border-red);border-radius:var(--radius);padding:18px;margin-bottom:14px">
        <div class="flex items-center justify-between gap-2 mb-3">
          <h3 class="fba-title text-xl" style="color:var(--text)">Minhas cartas à venda</h3>
          <button id="market-toggle-mine" style="background:var(--panel-2);border:1px solid var(--border-md);border-radius:var(--radius-sm);padding:6px 12px;color:var(--text-2);font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer">Ver minhas cartas à venda</button>
        </div>
        <div id="market-mine-wrap" class="hidden">
          <div id="market-mine-list" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3"></div>
        </div>
      </div>

      <div style="background:var(--panel);border:1px solid var(--border-red);border-radius:var(--radius);padding:18px">
        <h3 class="fba-title text-xl mb-3" style="color:var(--text)">Cartas à venda</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
          <input id="market-filter-name" type="text" placeholder="Filtrar por nome" class="fba-input">
          <select id="market-filter-collection" class="fba-input">
            <option value="">Todas as coleções</option>
          </select>
          <select id="market-filter-rarity" class="fba-input">
            <option value="">Todas as raridades</option>
            <option value="comum">Comum</option>
            <option value="rara">Rara</option>
            <option value="epico">Épica</option>
            <option value="lendario">Lendária</option>
          </select>
        </div>
        <label style="display:inline-flex;align-items:center;gap:8px;font-size:12px;color:var(--text-2);margin-bottom:12px;cursor:pointer">
          <input id="market-filter-missing" type="checkbox" style="accent-color:var(--red)">
          Ainda não tenho
        </label>
        <div id="market-list" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3"></div>
      </div>
      <p id="market-feedback" style="font-size:12px;color:var(--text-2);margin-top:10px"></p>
    </section>

    <!-- Trocas -->
    <section id="section-trades" class="hidden">
      <h2 class="text-3xl font-bold fba-title mb-2" style="color:var(--text)">Trocas</h2>
      <p style="color:var(--text-2);font-size:13px;margin-bottom:1rem">Selecione figurinhas do seu álbum e troque por pacotes especiais.</p>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div style="background:var(--panel);border:1px solid var(--border-red);border-radius:var(--radius);padding:18px">
          <h3 class="fba-title text-xl mb-3" style="color:var(--text)">3 figurinhas = Pacote Premium</h3>
          <div class="grid grid-cols-1 gap-2">
            <select id="trade-premium-1" class="fba-input"></select>
            <select id="trade-premium-2" class="fba-input"></select>
            <select id="trade-premium-3" class="fba-input"></select>
            <button id="trade-premium-btn" style="background:var(--red);color:#fff;border:none;border-radius:var(--radius-sm);padding:9px;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer">Trocar por Premium</button>
          </div>
        </div>

        <div style="background:var(--panel);border:1px solid var(--border-red);border-radius:var(--radius);padding:18px">
          <h3 class="fba-title text-xl mb-3" style="color:var(--text)">5 figurinhas = Pacote Ultra</h3>
          <div class="grid grid-cols-1 gap-2">
            <select id="trade-ultra-1" class="fba-input"></select>
            <select id="trade-ultra-2" class="fba-input"></select>
            <select id="trade-ultra-3" class="fba-input"></select>
            <select id="trade-ultra-4" class="fba-input"></select>
            <select id="trade-ultra-5" class="fba-input"></select>
            <button id="trade-ultra-btn" style="background:var(--red);color:#fff;border:none;border-radius:var(--radius-sm);padding:9px;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer">Trocar por Ultra</button>
          </div>
        </div>

        <div style="background:var(--panel);border:1px solid var(--border-red);border-radius:var(--radius);padding:18px">
          <h3 class="fba-title text-xl mb-3" style="color:var(--text)">10 figurinhas = 1 nova</h3>
          <p style="color:var(--text-2);font-size:12px;margin-bottom:10px">Recebe uma figurinha que você ainda não tem.</p>
          <div class="grid grid-cols-1 gap-2">
            <?php for ($i = 1; $i <= 10; $i++): ?>
            <select id="trade-missing-<?= $i ?>" class="fba-input"></select>
            <?php endfor; ?>
            <button id="trade-missing-btn" style="background:var(--red);color:#fff;border:none;border-radius:var(--radius-sm);padding:9px;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer">Trocar por nova</button>
          </div>
        </div>
      </div>
      <p id="trade-feedback" style="font-size:12px;color:var(--text-2);margin-top:10px"></p>
    </section>

    <!-- Pacotes -->
    <section id="section-store" class="hidden text-center">
      <h2 class="text-3xl font-bold fba-title mb-2" style="color:var(--text)">Loja de Pacotes</h2>
      <div class="flex flex-wrap justify-center gap-8 mt-10">
        <!-- Diário -->
        <div class="flex flex-col items-center">
          <div id="pack-daily" class="pack w-64 h-80 rounded-xl relative flex flex-col justify-center items-center overflow-hidden"
               style="background:linear-gradient(135deg,var(--panel-3),var(--panel-2))" onclick="claimDailyPack()">
            <h3 class="text-3xl font-black italic" style="color:var(--text)">DIÁRIO</h3>
            <div style="font-size:12px;color:var(--text-2);margin-top:8px">1x por dia</div>
          </div>
          <button id="pack-daily-btn" type="button" onclick="claimDailyPack()"
                  style="margin-top:14px;background:var(--panel-2);border:1px solid var(--border-red);border-radius:999px;padding:8px 24px;font-family:var(--font);font-size:13px;font-weight:700;color:var(--text);cursor:pointer">
            Resgatar
          </button>
          <div id="pack-daily-hint" style="font-size:11px;color:var(--text-3);margin-top:6px"></div>
          <div style="font-size:11px;color:var(--text-3);margin-top:4px">Disponível todos os dias às 14hrs</div>
        </div>
        <!-- Básico -->
        <div class="flex flex-col items-center">
          <div id="pack-basico" class="pack w-64 h-80 rounded-xl relative flex flex-col justify-center items-center overflow-hidden"
               style="background:linear-gradient(135deg,var(--panel-3),var(--panel))" onclick="openPack('basico')">
            <button type="button" class="pack-info-btn" onclick="event.stopPropagation();showPackOdds('basico')">!</button>
            <h3 class="text-3xl font-black italic" style="color:var(--text)">BÁSICO</h3>
          </div>
          <button onclick="openPack('basico')"
                  style="margin-top:14px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:999px;padding:8px 24px;font-family:var(--font);font-size:13px;font-weight:700;color:var(--text);cursor:pointer">
            30
          </button>
        </div>
        <!-- Premium -->
        <div class="flex flex-col items-center">
          <div id="pack-premium" class="pack w-64 h-80 rounded-xl relative flex flex-col justify-center items-center overflow-hidden"
               style="background:linear-gradient(135deg,#7f1d1d,#1f0a0a)" onclick="openPack('premium')">
            <button type="button" class="pack-info-btn" onclick="event.stopPropagation();showPackOdds('premium')">!</button>
            <h3 class="text-3xl font-black italic" style="color:var(--text)">PREMIUM</h3>
          </div>
          <button onclick="openPack('premium')"
                  style="margin-top:14px;background:rgba(252,0,37,.15);border:1px solid var(--border-red);border-radius:999px;padding:8px 24px;font-family:var(--font);font-size:13px;font-weight:700;color:var(--red);cursor:pointer">
            60
          </button>
        </div>
        <!-- Ultra -->
        <div class="flex flex-col items-center">
          <div id="pack-ultra" class="pack w-64 h-80 rounded-xl relative flex flex-col justify-center items-center overflow-hidden"
               style="background:linear-gradient(135deg,#ffffff,#b91c1c)" onclick="openPack('ultra')">
            <button type="button" class="pack-info-btn" onclick="event.stopPropagation();showPackOdds('ultra')">!</button>
            <h3 class="text-3xl font-black italic text-black">ULTRA</h3>
          </div>
          <button onclick="openPack('ultra')"
                  style="margin-top:14px;background:#fff;border:1px solid var(--red);border-radius:999px;padding:8px 24px;font-family:var(--font);font-size:13px;font-weight:700;color:#000;cursor:pointer">
            100
          </button>
        </div>
      </div>
    </section>

    <!-- Admin -->
    <section id="section-admin" class="hidden">
      <h2 class="text-3xl font-bold fba-title mb-2" style="color:var(--text)">Admin de Cartas</h2>
      <p style="color:var(--text-2);font-size:13px;margin-bottom:1.5rem">Cadastrar por coleção, time, posição, raridade e upload de imagem.</p>

      <div style="background:var(--panel);border:1px solid var(--border-red);border-radius:var(--radius);padding:18px;margin-bottom:20px">
        <h3 class="fba-title text-xl mb-2" style="color:var(--text)">Coleções nos pacotinhos</h3>
        <p style="color:var(--text-2);font-size:12px;margin-bottom:14px">Ative para permitir que a coleção apareça nos pacotes.</p>
        <div id="admin-pack-collections" class="flex flex-wrap gap-2"></div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div style="background:var(--panel);border:1px solid var(--border-red);border-radius:var(--radius);padding:18px">
          <form id="admin-card-form" class="space-y-3">
            <input type="hidden" id="admin-card-id" value="">
            <input id="admin-collection" placeholder="Nome da coleção" class="fba-input" required>
            <select id="admin-team" class="fba-input" required>
              <option value="">Selecione o time</option>
              <?php foreach ($teams as $team): ?>
                <option value="<?= htmlspecialchars($team, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($team, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
              <option value="__other__">Outro (digitar)</option>
            </select>
            <input id="admin-team-other" placeholder="Digite o nome do time" class="fba-input hidden">
            <input id="admin-name" placeholder="Nome da carta" class="fba-input" required>
            <div class="grid grid-cols-3 gap-3">
              <select id="admin-position" class="fba-input">
                <option>PG</option><option>SG</option><option>SF</option><option>PF</option><option>C</option>
              </select>
              <select id="admin-rarity" class="fba-input">
                <option value="comum">Comum</option><option value="rara">Rara</option>
                <option value="epico">Épica</option><option value="lendario">Lendária</option>
              </select>
              <input id="admin-ovr" type="number" min="50" max="99" placeholder="OVR" class="fba-input" required>
            </div>
            <input id="admin-image-file" type="file" accept="image/*" class="fba-input">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
              <button id="admin-save-btn" style="background:var(--red);color:#fff;border:none;border-radius:var(--radius-sm);padding:9px;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer" type="submit">Cadastrar Carta</button>
              <button id="admin-cancel-edit-btn" style="background:var(--panel-2);border:1px solid var(--border-md);color:var(--text-2);border-radius:var(--radius-sm);padding:9px;font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer" class="hidden" type="button">Cancelar edição</button>
              <button id="admin-delete-btn" style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.2);color:#f87171;border-radius:var(--radius-sm);padding:9px;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer" class="hidden" type="button">Excluir carta</button>
            </div>
            <small style="color:var(--text-3);font-size:11px;display:block">Na edição, a imagem é opcional (envie só se quiser trocar).</small>
          </form>
          <p id="admin-feedback" style="margin-top:10px;font-size:12px;color:var(--text-2)"></p>
        </div>
        <div style="background:var(--panel);border:1px solid var(--border-red);border-radius:var(--radius);padding:18px">
          <h3 class="fba-title text-xl mb-3" style="color:var(--text)">Últimas Cartas</h3>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mb-3">
            <select id="admin-filter-collection" class="fba-input">
              <option value="">Todas as coleções</option>
            </select>
            <select id="admin-filter-rarity" class="fba-input">
              <option value="">Todos os tipos</option>
              <option value="comum">Comum</option>
              <option value="rara">Rara</option>
              <option value="epico">Épica</option>
              <option value="lendario">Lendária</option>
            </select>
            <button id="admin-filter-clear" style="background:var(--panel-2);border:1px solid var(--border-md);color:var(--text-2);border-radius:var(--radius-sm);padding:8px;font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer" type="button">Limpar filtros</button>
          </div>
          <div id="admin-cards-list" class="space-y-2 max-h-[420px] overflow-y-auto pr-2"></div>
        </div>
      </div>
    </section>

  </main>

  <!-- Modal reveal -->
  <div id="reveal-modal" class="fixed inset-0 modal z-50 hidden flex-col justify-center items-center">
    <h2 class="text-4xl fba-title text-white mb-10 animate-pulse" id="reveal-title">Revelando...</h2>
    <div id="revealed-cards-container" class="flex flex-wrap justify-center gap-6 max-w-5xl px-4"></div>
    <div class="mt-12 flex flex-wrap justify-center gap-3">
      <button id="btn-close-modal" onclick="closeRevealModal()"
              style="padding:12px 32px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:var(--radius-sm);font-family:var(--font);font-size:14px;font-weight:700;color:var(--text);cursor:pointer"
              class="hidden fba-title">Fechar</button>
      <button id="btn-open-again" onclick="openPackAgain()"
              style="padding:12px 32px;background:var(--red);border:none;border-radius:var(--radius-sm);font-family:var(--font);font-size:14px;font-weight:700;color:#fff;cursor:pointer"
              class="hidden fba-title">Abrir novamente</button>
    </div>
  </div>

  <!-- Modal seleção -->
  <div id="select-modal" class="fixed inset-0 modal z-50 hidden flex-col justify-center items-center">
    <div class="fba-modal-box-red p-6 w-11/12 max-w-4xl max-h-[80vh] flex flex-col">
      <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl fba-title" style="color:var(--text)">Selecione uma Carta</h2>
        <button onclick="closeSelectModal()" style="color:var(--text-2);font-size:28px;font-weight:bold;background:none;border:none;cursor:pointer;line-height:1">&times;</button>
      </div>
      <div id="select-cards-container" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4 overflow-y-auto pr-2 pb-4"></div>
    </div>
  </div>

  <!-- Modal carta do álbum -->
  <div id="album-card-modal" class="fixed inset-0 modal z-50 hidden flex-col justify-center items-center py-6 px-3">
    <div class="fba-modal-box p-4 w-full max-w-[20rem] sm:max-w-[22rem] max-h-[92vh] overflow-hidden relative">
      <button onclick="closeAlbumCardModal()" style="position:absolute;top:8px;right:12px;color:var(--text-2);font-size:28px;font-weight:bold;background:none;border:none;cursor:pointer;line-height:1" aria-label="Fechar">&times;</button>
      <div class="flex justify-between items-center mb-3 pr-8">
        <h3 class="fba-title text-xl" style="color:var(--text)">Carta</h3>
      </div>
      <img id="album-card-modal-img" src="" alt="Carta" style="width:100%;max-height:58vh;object-fit:contain;border-radius:10px;border:1px solid var(--border-md)">
      <div style="margin-top:10px;color:var(--text);font-weight:700" id="album-card-modal-name"></div>
      <div style="color:var(--text-2);font-size:12px" id="album-card-modal-meta"></div>
      <div style="color:var(--red);font-size:12px;margin-top:4px" id="album-card-modal-count"></div>
      <div style="color:var(--text-3);font-size:11px;margin-top:8px">Aperte ESC para sair</div>
    </div>
  </div>

  <!-- Modal ranking team -->
  <div id="ranking-team-modal" class="fixed inset-0 modal z-50 hidden flex-col justify-center items-center py-6 px-3">
    <div class="fba-modal-box p-4 w-full max-w-5xl max-h-[92vh] overflow-hidden relative">
      <button onclick="closeRankingTeamModal()" style="position:absolute;top:8px;right:12px;color:var(--text-2);font-size:28px;font-weight:bold;background:none;border:none;cursor:pointer;line-height:1" aria-label="Fechar">&times;</button>
      <div class="pr-8 mb-3">
        <h3 class="fba-title text-xl" style="color:var(--text)" id="ranking-team-modal-title">Quinteto</h3>
        <div style="font-size:13px;font-weight:700;color:var(--text-2)">OVR: <span id="ranking-team-modal-ovr" style="color:var(--text)">0</span></div>
      </div>
      <div id="ranking-team-modal-loading" style="color:var(--text-2);font-size:13px;margin-bottom:10px" class="hidden">Carregando quinteto...</div>
      <div id="ranking-team-modal-grid" class="grid grid-cols-2 md:grid-cols-5 gap-3 overflow-y-auto max-h-[72vh] pr-1"></div>
      <div style="color:var(--text-3);font-size:11px;margin-top:10px">Aperte ESC para sair</div>
    </div>
  </div>

  <script src="album-fba.js"></script>
</body>
</html>
