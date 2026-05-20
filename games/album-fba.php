<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: auth/login.php'); exit; }

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
  <title>Álbum FBA</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --red:#fc0025; --red-soft:rgba(252,0,37,.10); --red-glow:rgba(252,0,37,.18);
      --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
      --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10); --border-red:rgba(252,0,37,.22);
      --text:#f0f0f3; --text-2:#868690; --text-3:#48484f;
      --amber:#f59e0b; --green:#22c55e;
      --font:'Poppins',sans-serif; --radius:14px; --radius-sm:10px;
      --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
    }
    *,*::before,*::after{box-sizing:border-box}
    body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased;overflow-x:hidden}
    h1,h2,h3,.fba-title{font-family:'Oswald',sans-serif;text-transform:uppercase}
    .hidden{display:none !important}

    /* ── Topbar ── */
    .topbar{position:sticky;top:0;z-index:300;height:54px;background:var(--panel);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 16px;gap:12px}
    .topbar-brand{display:flex;align-items:center;gap:8px;text-decoration:none}
    .topbar-logo{width:30px;height:30px;border-radius:8px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:11px;color:#fff}
    .topbar-name{font-weight:800;font-size:14px;color:var(--text)}
    .topbar-name span{color:var(--red)}
    .balance-chip{display:flex;align-items:center;gap:5px;background:var(--panel-2);border:1px solid var(--border);border-radius:999px;padding:4px 12px;font-size:12px;font-weight:700}
    .balance-chip i{color:var(--red)}
    .icon-btn{width:30px;height:30px;border-radius:8px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:14px;cursor:pointer;text-decoration:none;transition:all var(--t)}
    .icon-btn:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}

    /* ── Tabs ── */
    .fba-tabs{background:var(--panel);border-bottom:1px solid var(--border);display:flex;overflow-x:auto;scrollbar-width:none;padding:0 4px}
    .fba-tabs::-webkit-scrollbar{display:none}
    .fba-tab{padding:0 16px;height:44px;border:none;border-bottom:2px solid transparent;background:transparent;color:var(--text-2);font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;transition:color var(--t),border-color var(--t);display:flex;align-items:center;gap:6px}
    .fba-tab:hover{color:var(--text)}
    .fba-tab.active-tab{color:var(--text);border-bottom-color:var(--red)}
    .tab-dot{width:6px;height:6px;border-radius:50%;background:transparent;transition:background var(--t)}
    .fba-tab.active-tab .tab-dot{background:var(--red)}

    /* ── Inputs / Buttons ── */
    .fba-input{background:var(--panel-2);border:1px solid var(--border-md);border-radius:var(--radius-sm);padding:8px 12px;color:var(--text);font-family:var(--font);font-size:13px;outline:none;transition:border-color var(--t);width:100%}
    .fba-input:focus{border-color:var(--red)}
    .fba-input::placeholder{color:var(--text-3)}
    select.fba-input{cursor:pointer}
    .btn-primary{background:var(--red);color:#fff;border:none;border-radius:var(--radius-sm);padding:9px 16px;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;transition:opacity var(--t)}
    .btn-primary:hover{opacity:.85}
    .btn-ghost{background:transparent;border:1px solid var(--border-md);border-radius:var(--radius-sm);padding:8px 14px;color:var(--text-2);font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer;transition:all var(--t)}
    .btn-ghost:hover{background:var(--panel-2);color:var(--text)}

    /* ── Panels ── */
    .fba-panel{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius)}
    .fba-panel-red{background:var(--panel);border:1px solid var(--border-red);border-radius:var(--radius)}

    /* ── Accordion (Álbum) ── */
    .fba-acc-item{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
    .fba-acc-head{width:100%;display:flex;align-items:center;gap:10px;padding:12px 16px;cursor:pointer;background:transparent;border:none;color:var(--text);font-family:var(--font);text-align:left;transition:background var(--t)}
    .fba-acc-head:hover{background:var(--panel-2)}
    .fba-acc-title{font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;color:var(--red);letter-spacing:.5px;flex-shrink:0;min-width:0}
    .fba-acc-bar{flex:1;height:3px;background:var(--panel-3);border-radius:99px;overflow:hidden;min-width:40px}
    .fba-acc-fill{height:100%;background:var(--red);border-radius:99px;transition:width .5s}
    .fba-acc-count{font-size:11px;color:var(--text-2);font-weight:600;flex-shrink:0}
    .fba-acc-icon{color:var(--text-2);font-size:12px;flex-shrink:0;transition:transform .25s var(--ease)}
    .fba-acc-icon.open{transform:rotate(180deg)}
    .fba-acc-body{display:none;padding:4px 14px 14px}
    .fba-acc-body.open{display:block}

    /* ── Card grid ── */
    .fba-card-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(88px,1fr));gap:8px}
    @media(min-width:640px){.fba-card-grid{grid-template-columns:repeat(auto-fill,minmax(108px,1fr))}}
    @media(min-width:1024px){.fba-card-grid{grid-template-columns:repeat(auto-fill,minmax(118px,1fr))}}

    /* ── Rarity ── */
    .rarity-comum{border-color:#4a4a52;background:linear-gradient(145deg,var(--panel-2),var(--panel-3))}
    .rarity-rara{border-color:#ef4444;background:linear-gradient(145deg,#2a0a0a,#6b1111);box-shadow:0 0 10px rgba(239,68,68,.5)}
    .rarity-epico{border-color:#ff6b6b;background:linear-gradient(145deg,#3b0f0f,#9b1c1c);box-shadow:0 0 15px rgba(255,107,107,.6)}
    .rarity-lendario{border-color:#fff;background:linear-gradient(145deg,#400000,#b30000);box-shadow:0 0 20px rgba(255,255,255,.8);animation:pulse-white 2s infinite}
    @keyframes pulse-white{0%,100%{box-shadow:0 0 12px rgba(255,255,255,.6)}50%{box-shadow:0 0 28px rgba(255,255,255,1)}}

    /* ── Album slot ── */
    .album-slot{aspect-ratio:2.5/3.5;border:2px dashed var(--border-md);background:rgba(16,16,19,.5);border-radius:10px;overflow:hidden;position:relative;display:flex;align-items:center;justify-content:center;transition:all .3s}
    .album-slot.collected{border-style:solid;border-width:2px}
    .album-slot:not(.collected){filter:grayscale(100%) opacity(40%)}

    /* ── Collections tab ── */
    .coll-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
    .coll-card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:16px;display:flex;flex-direction:column;gap:8px;transition:border-color var(--t)}
    .coll-card.complete{border-color:rgba(34,197,94,.3)}
    .coll-card.redeemed{opacity:.65}
    .coll-card-name{font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;color:var(--red)}
    .coll-card-bar{height:4px;background:var(--panel-3);border-radius:99px;overflow:hidden}
    .coll-card-fill{height:100%;background:var(--red);border-radius:99px}
    .coll-status{font-size:11px;font-weight:700;padding:3px 8px;border-radius:999px;display:inline-block}
    .coll-status.s-redeemed{background:rgba(255,255,255,.07);color:var(--text-2)}
    .coll-status.s-complete{background:rgba(34,197,94,.15);color:#4ade80}
    .coll-status.s-progress{background:rgba(255,255,255,.04);color:var(--text-3)}

    /* ── Packs ── */
    .packs-grid{display:flex;flex-wrap:wrap;justify-content:center;gap:24px;padding:28px 0}
    .pack-wrap{display:flex;flex-direction:column;align-items:center;gap:10px}
    .pack-card{width:148px;height:188px;border-radius:14px;position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;cursor:pointer;border:2px solid var(--border-red);transition:transform .2s var(--ease),box-shadow .2s var(--ease)}
    .pack-card:hover{transform:translateY(-8px) scale(1.04)}
    .pack-card-name{font-family:'Oswald',sans-serif;font-size:22px;font-weight:700;letter-spacing:2px;color:#fff;text-shadow:0 2px 8px rgba(0,0,0,.7)}
    .pack-card-sub{font-size:11px;color:rgba(255,255,255,.5)}
    .pack-info-btn{position:absolute;top:8px;right:8px;width:24px;height:24px;border-radius:50%;border:1.5px solid rgba(255,255,255,.45);background:rgba(0,0,0,.5);color:#fff;font-weight:800;font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:2}
    .pack-buy-btn{padding:8px 22px;border-radius:999px;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;border:none;transition:all var(--t)}
    .pack-buy-default{background:var(--panel-2);border:1px solid var(--border-md)!important;color:var(--text);border:none}
    .pack-buy-red{background:rgba(252,0,37,.15);border:1px solid var(--border-red)!important;color:var(--red);border:none}
    .pack-buy-white{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2)!important;color:#fff;border:none}
    .pack-hint{font-size:11px;color:var(--text-3);min-height:16px;text-align:center}
    .pack-note{font-size:11px;color:var(--text-3);text-align:center;max-width:150px}

    /* ── Shake ── */
    .shaking{animation:shake .5s cubic-bezier(.36,.07,.19,.97) both;animation-iteration-count:3}
    @keyframes shake{10%,90%{transform:translate3d(-2px,0,0) rotate(-2deg)}20%,80%{transform:translate3d(4px,0,0) rotate(2deg)}30%,50%,70%{transform:translate3d(-6px,0,0) rotate(-4deg)}40%,60%{transform:translate3d(6px,0,0) rotate(4deg)}}

    /* ── Modals ── */
    .modal{backdrop-filter:blur(12px);background:rgba(0,0,0,.88)}
    .fba-modal-box{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius)}
    .fba-modal-box-red{background:var(--panel);border:1px solid var(--border-red);border-radius:var(--radius)}

    /* ── Card flip ── */
    .revealed-card{opacity:0;transform:scale(.5) translateY(100px);transition:all .6s cubic-bezier(.175,.885,.32,1.275)}
    .revealed-card.show{opacity:1;transform:scale(1) translateY(0)}
    .card-container{perspective:1000px;cursor:pointer}
    .card-inner{position:relative;width:100%;height:100%;transition:transform .6s cubic-bezier(.175,.885,.32,1.275);transform-style:preserve-3d}
    .card-container.flipped .card-inner{transform:rotateY(180deg)}
    .card-front,.card-back{position:absolute;width:100%;height:100%;backface-visibility:hidden;border-radius:.75rem;overflow:hidden}
    .card-back{background:linear-gradient(135deg,var(--panel-3),var(--bg));border:4px solid var(--border-md)}
    .card-front{transform:rotateY(180deg)}

    /* ── Basketball court ── */
    .basketball-court{background-color:#2a1a0e;background-image:repeating-linear-gradient(90deg,transparent,transparent 40px,rgba(255,255,255,.03) 40px,rgba(255,255,255,.03) 80px);border:3px solid var(--red);position:relative;width:100%;max-width:500px;aspect-ratio:1/1.2;margin:0 auto;border-radius:10px;overflow:hidden;box-shadow:0 0 40px rgba(252,0,37,.15),inset 0 0 50px rgba(0,0,0,.5)}
    .court-paint{border:3px solid rgba(255,255,255,.3);border-bottom:none;position:absolute;bottom:0;left:50%;transform:translateX(-50%);width:160px;height:200px;background:rgba(252,0,37,.08)}
    .court-3pt{border:3px solid rgba(255,255,255,.2);border-radius:50%;position:absolute;bottom:-50px;left:50%;transform:translateX(-50%);width:450px;height:400px;pointer-events:none}
    .court-slot{position:absolute;width:70px;height:98px;transform:translate(-50%,-50%);border:2px dashed rgba(255,255,255,.5);border-radius:8px;background:rgba(0,0,0,.4);cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;color:var(--text-2);font-weight:bold;font-size:1.5rem;z-index:10}
    .court-slot:hover{background:rgba(252,0,37,.12);border-color:var(--red);border-style:solid;color:var(--red)}
    .court-slot img{width:100%;height:100%;object-fit:cover;border-radius:6px;border:2px solid rgba(255,255,255,.3)}
    .pos-pg{top:15%;left:50%}.pos-sg{top:40%;left:20%}.pos-sf{top:40%;left:80%}.pos-pf{top:75%;left:30%}.pos-c{top:75%;left:70%}

    @media(max-width:640px){
      .basketball-court{max-width:320px}.court-paint{width:120px;height:160px}.court-3pt{width:320px;height:300px;bottom:-40px}.court-slot{width:52px;height:74px;font-size:1.15rem}
      .pack-card{width:128px;height:166px}.pack-card-name{font-size:18px}
      .revealed-card{width:9.5rem !important;height:13.5rem !important}
      .topbar-name{display:none}
    }
  </style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
  <a href="index.php" class="topbar-brand">
    <div class="topbar-logo">FBA</div>
    <span class="topbar-name">FBA <span>Games</span></span>
  </a>
  <div style="flex:1"></div>
  <div class="balance-chip">
    <i class="bi bi-coin"></i>
    Moedas: <strong id="coin-count" style="font-size:14px;margin-left:2px">0</strong>
  </div>
  <a href="index.php" class="icon-btn" title="Voltar"><i class="bi bi-arrow-left"></i></a>
</div>

<!-- Tabs -->
<div class="fba-tabs">
  <button onclick="switchTab('album')"    id="tab-album"    class="fba-tab active-tab"><span class="tab-dot"></span>Meu Álbum</button>
  <button onclick="switchTab('colecoes')" id="tab-colecoes" class="fba-tab"><span class="tab-dot"></span>Coleções</button>
  <button onclick="switchTab('market')"   id="tab-market"   class="fba-tab"><span class="tab-dot"></span>Mercado</button>
  <button onclick="switchTab('trades')"   id="tab-trades"   class="fba-tab"><span class="tab-dot"></span>Trocas</button>
  <button onclick="switchTab('store')"    id="tab-store"    class="fba-tab"><span class="tab-dot"></span>Pacotes</button>
  <button onclick="switchTab('admin')"    id="tab-admin"    class="fba-tab hidden"><span class="tab-dot"></span>Admin</button>
</div>

<main style="max-width:1200px;margin:0 auto;padding:20px 16px 60px">

  <!-- ── Meu Álbum ── -->
  <section id="section-album" style="display:block">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;flex-wrap:wrap">
      <div>
        <h2 class="fba-title" style="font-size:20px;color:var(--text)">Plantel FBA 2026</h2>
        <p id="album-progress" style="font-size:12px;color:var(--text-2);margin-top:2px">Progresso: 0 figurinhas</p>
      </div>
      <input id="album-collection-filter" type="text" placeholder="🔍 Pesquisar coleção..." class="fba-input" style="max-width:230px">
    </div>
    <div id="album-container" style="display:flex;flex-direction:column;gap:8px"></div>
  </section>

  <!-- ── Coleções ── -->
  <section id="section-colecoes" style="display:none">
    <div style="margin-bottom:20px">
      <h2 class="fba-title" style="font-size:20px;color:var(--text)">Coleções</h2>
      <p style="font-size:12px;color:var(--text-2);margin-top:2px">Complete uma coleção inteira e resgate 500 FBA Points.</p>
    </div>
    <div id="collection-rewards" class="coll-grid"></div>
  </section>

  <!-- ── Mercado ── -->
  <section id="section-market" style="display:none">
    <div style="margin-bottom:18px">
      <h2 class="fba-title" style="font-size:20px;color:var(--text)">Mercado de Cartas</h2>
      <p style="font-size:12px;color:var(--text-2);margin-top:2px">Compre e venda figurinhas com outros usuários.</p>
    </div>

    <div style="display:grid;gap:12px;margin-bottom:12px">
      <!-- Criar anúncio -->
      <div class="fba-panel-red" style="padding:16px 18px">
        <div style="font-size:12px;font-weight:700;color:var(--text-2);letter-spacing:.5px;text-transform:uppercase;margin-bottom:12px;display:flex;align-items:center;gap:7px">
          <i class="bi bi-tag-fill" style="color:var(--red)"></i> Criar anúncio
        </div>
        <div style="display:grid;grid-template-columns:1fr;gap:8px">
          <select id="market-sell-card" class="fba-input"></select>
          <div style="display:grid;grid-template-columns:1fr auto;gap:8px">
            <input id="market-sell-price" type="number" min="1" step="1" class="fba-input" placeholder="Preço em pontos">
            <button id="market-sell-btn" class="btn-primary">Anunciar</button>
          </div>
        </div>
        <p id="market-sell-hint" style="color:var(--text-2);font-size:11px;margin-top:6px"></p>
      </div>

      <!-- Minhas cartas à venda -->
      <div class="fba-panel-red" style="padding:16px 18px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
          <div style="font-size:12px;font-weight:700;color:var(--text-2);letter-spacing:.5px;text-transform:uppercase;display:flex;align-items:center;gap:7px">
            <i class="bi bi-person-lines-fill" style="color:var(--red)"></i> Minhas cartas à venda
          </div>
          <button id="market-toggle-mine" class="btn-ghost" style="font-size:11px;padding:5px 10px">Mostrar</button>
        </div>
        <div id="market-mine-wrap" class="hidden" style="margin-top:12px">
          <div id="market-mine-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px"></div>
        </div>
      </div>
    </div>

    <!-- Listagens -->
    <div class="fba-panel-red" style="padding:16px 18px">
      <div style="font-size:12px;font-weight:700;color:var(--text-2);letter-spacing:.5px;text-transform:uppercase;margin-bottom:12px;display:flex;align-items:center;gap:7px">
        <i class="bi bi-grid-3x3-gap-fill" style="color:var(--red)"></i> Cartas disponíveis
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:8px;margin-bottom:10px">
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
      <label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--text-2);margin-bottom:12px;cursor:pointer">
        <input id="market-filter-missing" type="checkbox" style="accent-color:var(--red)"> Ainda não tenho
      </label>
      <div id="market-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px"></div>
    </div>
    <p id="market-feedback" style="font-size:12px;color:var(--text-2);margin-top:10px"></p>
  </section>

  <!-- ── Trocas ── -->
  <section id="section-trades" style="display:none">
    <div style="margin-bottom:18px">
      <h2 class="fba-title" style="font-size:20px;color:var(--text)">Trocas</h2>
      <p style="font-size:12px;color:var(--text-2);margin-top:2px">Troque figurinhas duplicadas por pacotes especiais.</p>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px">
      <!-- Premium -->
      <div class="fba-panel-red" style="padding:20px">
        <div style="margin-bottom:14px">
          <div class="fba-title" style="font-size:15px;color:var(--text)">3 figurinhas = Premium</div>
          <div style="font-size:12px;color:var(--text-2);margin-top:3px">Troque 3 duplicadas por um pacote premium</div>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px">
          <select id="trade-premium-1" class="fba-input"></select>
          <select id="trade-premium-2" class="fba-input"></select>
          <select id="trade-premium-3" class="fba-input"></select>
          <button id="trade-premium-btn" class="btn-primary" style="width:100%;padding:10px;margin-top:4px">Trocar por Premium</button>
        </div>
      </div>

      <!-- Ultra -->
      <div class="fba-panel-red" style="padding:20px">
        <div style="margin-bottom:14px">
          <div class="fba-title" style="font-size:15px;color:var(--text)">5 figurinhas = Ultra</div>
          <div style="font-size:12px;color:var(--text-2);margin-top:3px">Troque 5 duplicadas por um pacote ultra</div>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px">
          <select id="trade-ultra-1" class="fba-input"></select>
          <select id="trade-ultra-2" class="fba-input"></select>
          <select id="trade-ultra-3" class="fba-input"></select>
          <select id="trade-ultra-4" class="fba-input"></select>
          <select id="trade-ultra-5" class="fba-input"></select>
          <button id="trade-ultra-btn" class="btn-primary" style="width:100%;padding:10px;margin-top:4px">Trocar por Ultra</button>
        </div>
      </div>

      <!-- Missing -->
      <div class="fba-panel-red" style="padding:20px">
        <div style="margin-bottom:14px">
          <div class="fba-title" style="font-size:15px;color:var(--text)">10 figurinhas = 1 nova</div>
          <div style="font-size:12px;color:var(--text-2);margin-top:3px">Receba uma figurinha que você ainda não tem</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
          <?php for ($i = 1; $i <= 10; $i++): ?>
          <select id="trade-missing-<?= $i ?>" class="fba-input" style="font-size:11px;padding:7px 8px"></select>
          <?php endfor; ?>
        </div>
        <button id="trade-missing-btn" class="btn-primary" style="width:100%;padding:10px;margin-top:10px">Trocar por nova</button>
      </div>
    </div>
    <p id="trade-feedback" style="font-size:12px;color:var(--text-2);margin-top:10px"></p>
  </section>

  <!-- ── Pacotes ── -->
  <section id="section-store" style="display:none;text-align:center">
    <h2 class="fba-title" style="font-size:20px;color:var(--text)">Loja de Pacotes</h2>
    <p style="font-size:12px;color:var(--text-2);margin-top:4px">Abra pacotes e colecione figurinhas do plantel FBA 2026</p>

    <div class="packs-grid">
      <!-- Diário -->
      <div class="pack-wrap">
        <div id="pack-daily" class="pack-card" style="background:linear-gradient(135deg,var(--panel-3),var(--panel-2))" onclick="claimDailyPack()">
          <div style="font-size:30px">📅</div>
          <div class="pack-card-name">DIÁRIO</div>
          <div class="pack-card-sub">Grátis · 1× por dia</div>
        </div>
        <button id="pack-daily-btn" type="button" onclick="claimDailyPack()" class="pack-buy-btn pack-buy-default">Resgatar</button>
        <div id="pack-daily-hint" class="pack-hint"></div>
        <div class="pack-note">Disponível todos os dias às 14h</div>
      </div>

      <!-- Básico -->
      <div class="pack-wrap">
        <div id="pack-basico" class="pack-card" style="background:linear-gradient(135deg,var(--panel-3),var(--panel))" onclick="openPack('basico')">
          <button type="button" class="pack-info-btn" onclick="event.stopPropagation();showPackOdds('basico')">?</button>
          <div style="font-size:28px">📦</div>
          <div class="pack-card-name">BÁSICO</div>
        </div>
        <button onclick="openPack('basico')" class="pack-buy-btn pack-buy-default">30 moedas</button>
      </div>

      <!-- Premium -->
      <div class="pack-wrap">
        <div id="pack-premium" class="pack-card" style="background:linear-gradient(135deg,#7f1d1d,#1f0a0a);box-shadow:0 12px 30px rgba(252,0,37,.3)" onclick="openPack('premium')">
          <button type="button" class="pack-info-btn" onclick="event.stopPropagation();showPackOdds('premium')">?</button>
          <div style="font-size:28px">🔥</div>
          <div class="pack-card-name">PREMIUM</div>
        </div>
        <button onclick="openPack('premium')" class="pack-buy-btn pack-buy-red" style="border:1px solid var(--border-red)">60 moedas</button>
      </div>

      <!-- Ultra -->
      <div class="pack-wrap">
        <div id="pack-ultra" class="pack-card" style="background:linear-gradient(135deg,#ddd,#b91c1c);box-shadow:0 12px 30px rgba(255,255,255,.12)" onclick="openPack('ultra')">
          <button type="button" class="pack-info-btn" onclick="event.stopPropagation();showPackOdds('ultra')" style="border-color:rgba(0,0,0,.3);background:rgba(0,0,0,.35)">?</button>
          <div style="font-size:28px">👑</div>
          <div class="pack-card-name" style="color:#111">ULTRA</div>
        </div>
        <button onclick="openPack('ultra')" class="pack-buy-btn pack-buy-white" style="border:1px solid rgba(255,255,255,.2)">100 moedas</button>
      </div>
    </div>
  </section>

  <!-- ── Admin ── -->
  <section id="section-admin" style="display:none">
    <div style="margin-bottom:18px">
      <h2 class="fba-title" style="font-size:20px;color:var(--text)">Admin de Cartas</h2>
      <p style="font-size:12px;color:var(--text-2);margin-top:2px">Cadastre e gerencie figurinhas do plantel.</p>
    </div>

    <div class="fba-panel-red" style="padding:16px 18px;margin-bottom:16px">
      <div style="font-size:12px;font-weight:700;color:var(--text-2);letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;display:flex;align-items:center;gap:7px">
        <i class="bi bi-collection-fill" style="color:var(--red)"></i> Coleções nos pacotinhos
      </div>
      <p style="color:var(--text-2);font-size:12px;margin-bottom:12px">Ative para permitir que a coleção apareça nos pacotes.</p>
      <div id="admin-pack-collections" style="display:flex;flex-wrap:wrap;gap:8px"></div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px">
      <!-- Form -->
      <div class="fba-panel-red" style="padding:18px">
        <div style="font-size:12px;font-weight:700;color:var(--text-2);letter-spacing:.5px;text-transform:uppercase;margin-bottom:14px;display:flex;align-items:center;gap:7px">
          <i class="bi bi-plus-circle-fill" style="color:var(--red)"></i> <span id="admin-form-title">Nova carta</span>
        </div>
        <form id="admin-card-form" style="display:flex;flex-direction:column;gap:9px">
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
          <div style="display:grid;grid-template-columns:1fr 1fr 72px;gap:8px">
            <select id="admin-position" class="fba-input">
              <option>PG</option><option>SG</option><option>SF</option><option>PF</option><option>C</option>
            </select>
            <select id="admin-rarity" class="fba-input">
              <option value="comum">Comum</option><option value="rara">Rara</option>
              <option value="epico">Épica</option><option value="lendario">Lendária</option>
            </select>
            <input id="admin-ovr" type="number" min="50" max="99" placeholder="OVR" class="fba-input" required>
          </div>
          <input id="admin-image-file" type="file" accept="image/*" class="fba-input" style="font-size:12px">
          <div style="display:grid;grid-template-columns:1fr auto auto;gap:6px">
            <button id="admin-save-btn" class="btn-primary" type="submit">Cadastrar Carta</button>
            <button id="admin-cancel-edit-btn" class="btn-ghost hidden" type="button">Cancelar</button>
            <button id="admin-delete-btn" style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.2);color:#f87171;border-radius:var(--radius-sm);padding:9px 12px;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer" class="hidden" type="button">Excluir</button>
          </div>
          <small style="color:var(--text-3);font-size:11px">Na edição, a imagem é opcional (envie só se quiser trocar).</small>
        </form>
        <p id="admin-feedback" style="margin-top:10px;font-size:12px;color:var(--text-2)"></p>
      </div>

      <!-- Cards list -->
      <div class="fba-panel-red" style="padding:18px">
        <div style="font-size:12px;font-weight:700;color:var(--text-2);letter-spacing:.5px;text-transform:uppercase;margin-bottom:12px;display:flex;align-items:center;gap:7px">
          <i class="bi bi-card-list" style="color:var(--red)"></i> Últimas cartas
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
          <select id="admin-filter-collection" class="fba-input" style="font-size:12px">
            <option value="">Todas as coleções</option>
          </select>
          <select id="admin-filter-rarity" class="fba-input" style="font-size:12px">
            <option value="">Todos os tipos</option>
            <option value="comum">Comum</option><option value="rara">Rara</option>
            <option value="epico">Épica</option><option value="lendario">Lendária</option>
          </select>
        </div>
        <button id="admin-filter-clear" class="btn-ghost" style="width:100%;margin-bottom:10px;font-size:12px" type="button">Limpar filtros</button>
        <div id="admin-cards-list" style="display:flex;flex-direction:column;gap:7px;max-height:420px;overflow-y:auto;padding-right:4px"></div>
      </div>
    </div>
  </section>

  <!-- ── Quinteto (mantido para compatibilidade) ── -->
  <section id="section-team" style="display:none;text-align:center">
    <h2 class="fba-title" style="font-size:24px;margin-bottom:6px">Quinteto Ideal</h2>
    <p style="color:var(--text-2);margin-bottom:20px;font-size:13px">Escale suas melhores cartas na quadra.</p>
    <div style="display:flex;justify-content:center;margin-bottom:20px">
      <div style="background:var(--panel-2);border:1px solid var(--border-red);border-radius:999px;padding:10px 30px;font-size:14px">
        OVR: <span id="team-ovr-display" style="font-size:26px;font-weight:900;color:var(--text);margin-left:6px">0</span>
      </div>
    </div>
    <div style="margin-bottom:18px">
      <button type="button" onclick="clearTeam()" class="btn-ghost">Limpar escalação</button>
    </div>
    <div class="basketball-court">
      <div class="court-3pt"></div>
      <div class="court-paint"></div>
      <div class="court-slot pos-pg" onclick="openSelectModal(0)" id="court-slot-0">+</div>
      <div class="court-slot pos-sg" onclick="openSelectModal(1)" id="court-slot-1">+</div>
      <div class="court-slot pos-sf" onclick="openSelectModal(2)" id="court-slot-2">+</div>
      <div class="court-slot pos-pf" onclick="openSelectModal(3)" id="court-slot-3">+</div>
      <div class="court-slot pos-c"  onclick="openSelectModal(4)" id="court-slot-4">+</div>
    </div>
  </section>

  <!-- ── Ranking (mantido para compatibilidade) ── -->
  <section id="section-ranking" style="display:none;text-align:center">
    <h2 class="fba-title" style="font-size:24px;margin-bottom:6px">Ranking Global</h2>
    <div style="max-width:680px;margin:20px auto;background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden">
      <table style="width:100%;text-align:left">
        <thead>
          <tr style="background:var(--panel-2);color:var(--text-3);font-size:10px;text-transform:uppercase;letter-spacing:.6px">
            <th style="padding:13px;width:56px;text-align:center;border-bottom:1px solid var(--border)">Pos</th>
            <th style="padding:13px;border-bottom:1px solid var(--border)">Jogador</th>
            <th style="padding:13px;width:90px;text-align:center;border-bottom:1px solid var(--border)">OVR</th>
          </tr>
        </thead>
        <tbody id="ranking-tbody"></tbody>
      </table>
    </div>
  </section>

</main>

<!-- Modal reveal -->
<div id="reveal-modal" class="fixed inset-0 modal z-50 hidden flex-col justify-center items-center">
  <h2 class="text-4xl fba-title text-white mb-10 animate-pulse" id="reveal-title">Revelando...</h2>
  <div id="revealed-cards-container" class="flex flex-wrap justify-center gap-6 max-w-5xl px-4"></div>
  <div class="mt-12 flex flex-wrap justify-center gap-3">
    <button id="btn-close-modal" onclick="closeRevealModal()" style="padding:12px 32px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:var(--radius-sm);font-family:var(--font);font-size:14px;font-weight:700;color:var(--text);cursor:pointer" class="hidden fba-title">Fechar</button>
    <button id="btn-open-again" onclick="openPackAgain()" style="padding:12px 32px;background:var(--red);border:none;border-radius:var(--radius-sm);font-family:var(--font);font-size:14px;font-weight:700;color:#fff;cursor:pointer" class="hidden fba-title">Abrir novamente</button>
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

<!-- Modal confirmação resgate -->
<div id="redeem-confirm-modal" class="fixed inset-0 modal z-50 hidden flex-col justify-center items-center px-4">
  <div class="fba-modal-box-red p-6 w-full max-w-sm text-center">
    <div style="width:50px;height:50px;border-radius:50%;background:rgba(252,0,37,.12);border:1px solid var(--border-red);display:flex;align-items:center;justify-content:center;margin:0 auto 14px">
      <i class="bi bi-award-fill" style="font-size:20px;color:var(--red)"></i>
    </div>
    <h3 class="fba-title text-xl mb-1" style="color:var(--text)">Resgatar Coleção</h3>
    <p id="redeem-confirm-collection-name" style="color:var(--red);font-size:14px;font-weight:700;margin-bottom:8px"></p>
    <p style="color:var(--text-2);font-size:13px;margin-bottom:18px">Você receberá <strong style="color:var(--text)">500 FBA Points</strong> por completar esta coleção. Esta ação não pode ser desfeita.</p>
    <div style="display:flex;gap:10px">
      <button id="redeem-confirm-cancel" class="btn-ghost" style="flex:1;padding:10px">Cancelar</button>
      <button id="redeem-confirm-ok" class="btn-primary" style="flex:1;padding:10px">Resgatar</button>
    </div>
  </div>
</div>

<script src="album-fba.js"></script>
</body>
</html>
