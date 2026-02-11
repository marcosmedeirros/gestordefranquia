<?php
/**
 * GAMES/AVATAR.PHP - Elite Protocol v7 (Avatar + Caixas + Loja)
 * Página unificada com avatar integrado e loot boxes
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../core/conexao.php';
require '../core/avatar.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Carrega dados do usuário
try {
    $stmt = $pdo->prepare("SELECT nome, pontos FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar usuário");
}

// API para abrir caixa (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api']) && $_POST['api'] === 'abrir_caixa') {
    header('Content-Type: application/json; charset=utf-8');
    
    // Debug logging
    error_log("=== ABRIR CAIXA DEBUG ===");
    error_log("POST data: " . json_encode($_POST));
    error_log("User ID: " . $user_id);
    
    $tipo_caixa = $_POST['tipo_caixa'] ?? '';
    error_log("Tipo caixa: " . $tipo_caixa);
    
    if (empty($tipo_caixa)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Tipo de caixa não especificado']);
        exit;
    }
    
    $resultado = abrirLootBox($pdo, $user_id, $tipo_caixa);
    error_log("Resultado: " . json_encode($resultado));
    
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    exit;
}

// API para salvar avatar atual (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api']) && $_POST['api'] === 'salvar_avatar') {
    header('Content-Type: application/json');
    $color = $_POST['color'] ?? 'default';
    $hardware = $_POST['hardware'] ?? 'none';
    $clothing = $_POST['clothing'] ?? 'none';
    $footwear = $_POST['footwear'] ?? 'none';
    $elite = $_POST['elite'] ?? 'none';
    $aura = $_POST['aura'] ?? 'none';
    $ok = salvarCustomizacaoAvatar($pdo, $user_id, $color, $hardware, $clothing, $footwear, $elite, $aura);
    echo json_encode(['sucesso' => (bool)$ok]);
    exit;
}

// Obter inventário
$inventario = obterInventario($pdo, $user_id);
// Mapa de posse por categoria -> item_id => true
$owned_map = [];
foreach ($inventario as $it) {
    if (!isset($owned_map[$it['categoria']])) $owned_map[$it['categoria']] = [];
    $owned_map[$it['categoria']][$it['item_id']] = true;
}

// Converter dados para JSON
$componentes_json = json_encode($AVATAR_COMPONENTES);
$loot_boxes_json = json_encode($LOOT_BOXES);
$customizacao_atual = obterCustomizacaoAvatar($pdo, $user_id);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pikafumo - Elite Protocol v7</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #020617; color: #e2e8f0; font-family: 'Inter', sans-serif; overflow-x: hidden; }
        /* Header padrão do site */
        .navbar-custom { background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%); border-bottom: 1px solid #333; padding: 15px 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.5); }
        .brand-name { font-size: 1.5rem; font-weight: 900; background: linear-gradient(135deg, #00e676, #76ff03); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; text-decoration: none; }
        .saldo-badge { background-color: #00e676; color: #000; padding: 10px 20px; border-radius: 25px; font-weight: 800; font-size: 1.1em; box-shadow: 0 0 15px rgba(0, 230, 118, 0.3); }
        
        .avatar-container { position: relative; width: 260px; height: 300px; }
        .layer { position: absolute; top: 0; left: 0; width: 100%; height: 100%; transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        
        .item-card { background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.05); backdrop-filter: blur(12px); transition: all 0.2s ease-out; cursor: pointer; position: relative; overflow: hidden; border-radius: 16px; }
        .item-card:hover { border-color: #6366f1; transform: translateY(-4px); background: rgba(30, 41, 59, 0.8); }
        .item-card.equipped { border-color: #10b981; box-shadow: 0 0 20px rgba(16, 185, 129, 0.15); background: rgba(16, 185, 129, 0.05); }
        
        .rarity-tag { position: absolute; top: 0; right: 0; font-size: 7px; padding: 2px 8px; font-weight: 900; border-bottom-left-radius: 10px; text-transform: uppercase; letter-spacing: 0.1em; }
        .common { background: #475569; color: white; }
        .rare { background: #2563eb; color: white; }
        .epic { background: #9333ea; color: white; }
        .legendary { background: #f59e0b; color: black; box-shadow: 0 0 15px rgba(245, 158, 11, 0.3); }
        .mythic { background: #ff00ff; color: white; }

        @keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
        #main-avatar { animation: float 6s ease-in-out infinite; }
        
        @keyframes weapon-float { 0%, 100% { transform: translateY(0) rotate(0deg); } 50% { transform: translateY(-5px) rotate(2deg); } }
        .animate-weapon { animation: weapon-float 3s infinite ease-in-out; }

        @keyframes aura-pulse { 0%, 100% { transform: scale(1); opacity: 0.4; } 50% { transform: scale(1.3); opacity: 0.7; } }
        .animate-aura { animation: aura-pulse 3s infinite ease-in-out; }

        #case-carousel { display: flex; gap: 12px; transition: transform 7s cubic-bezier(0.1, 0, 0.1, 1); will-change: transform; }
        .case-item { min-width: 120px; height: 120px; background: #0f172a; border: 1px solid rgba(255,255,255,0.1); border-radius: 20px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }

        .modal-blur { backdrop-filter: blur(20px); background: rgba(0,0,0,0.9); }
        .case-button { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .case-button:hover { transform: scale(1.05) translateY(-2px); }
        
        .custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
    </style>
    </head>
    <body class="p-3 md:p-6">

    <div class="max-w-[1500px] mx-auto w-full">
        
        <!-- Header estilo Elite Protocol v7 -->
        <header class="flex flex-col xl:flex-row justify-between items-center gap-4 mb-10 bg-slate-900/40 p-6 rounded-[40px] border border-white/5 shadow-2xl backdrop-blur-md">
            <div class="text-center xl:text-left">
                <h1 class="text-4xl font-black text-white tracking-tighter uppercase italic">Personalize o Thiaguinho</h1>
                <p class="text-slate-400 text-[10px] font-bold uppercase tracking-[0.4em] mt-1">Obtenha itens nas caixas ao lado!</p>
            </div>
            <div class="flex flex-wrap justify-center xl:justify-end items-center gap-3">
                <button onclick="openCaseModal('basica')" class="case-button bg-slate-800 border border-white/10 p-3 rounded-2xl flex flex-col items-center min-w-[100px]">
                    <i class="bi bi-box text-slate-400 text-xl"></i>
                    <span class="text-[9px] font-black uppercase text-slate-500 mt-1">Bolicheiro</span>
                    <span class="text-xs font-black text-white"><?= number_format($LOOT_BOXES['basica']['preco'], 0) ?> pts</span>
                </button>
                <button onclick="openCaseModal('top')" class="case-button bg-indigo-900/40 border border-indigo-500/30 p-3 rounded-2xl flex flex-col items-center min-w-[100px]">
                    <i class="bi bi-box-seam text-indigo-400 text-xl"></i>
                    <span class="text-[9px] font-black uppercase text-indigo-400 mt-1">Pnip</span>
                    <span class="text-xs font-black text-white"><?= number_format($LOOT_BOXES['top']['preco'], 0) ?> pts</span>
                </button>
                <button onclick="openCaseModal('premium')" class="case-button bg-amber-500 text-black p-3 rounded-2xl flex flex-col items-center min-w-[100px] shadow-lg shadow-amber-500/20">
                    <i class="bi bi-box-seam-fill text-xl"></i>
                    <span class="text-[9px] font-black uppercase mt-1">PDSA</span>
                    <span class="text-xs font-black"><?= number_format($LOOT_BOXES['premium']['preco'], 0) ?> pts</span>
                </button>
                <div class="bg-emerald-500 px-4 py-2 rounded-full border border-emerald-400 flex items-center gap-2 min-w-fit shadow-lg shadow-emerald-500/30">
                    <i class="bi bi-coin text-black text-sm"></i>
                    <span class="text-sm font-black text-black" id="balance-display"><?= number_format($usuario['pontos'], 0) ?> pts</span>
                </div>
                <a href="../index.php" class="bg-slate-700 hover:bg-slate-600 border border-white/10 text-white p-3 rounded-xl transition shadow-lg flex items-center justify-center" title="Voltar ao painel"><i class="bi bi-arrow-left text-lg"></i></a>
            </div>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-start">
            
            <!-- Avatar Preview -->
            <div class="lg:col-span-5 flex flex-col items-center justify-center bg-slate-900/20 rounded-[60px] p-12 border border-white/5 relative overflow-hidden">
                <div class="avatar-container" id="main-avatar">
                    <div id="layer-fx" class="layer flex items-center justify-center opacity-0"><div class="w-80 h-80 rounded-full blur-[110px]"></div></div>
                    <svg class="layer" viewBox="0 0 100 120" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <linearGradient id="heroGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" style="stop-color:rgba(255,255,255,0.15);stop-opacity:1" />
                                <stop offset="100%" style="stop-color:rgba(0,0,0,0.3);stop-opacity:1" />
                            </linearGradient>
                        </defs>
                        <ellipse cx="50" cy="115" rx="35" ry="8" fill="black" opacity="0.3" />
                        <rect id="svg-body" x="20" y="20" width="60" height="85" rx="12" fill="#6366f1" stroke="#000" stroke-width="4"/>
                        <rect x="20" y="20" width="60" height="85" rx="12" fill="url(#heroGrad)"/>
                        <rect id="svg-backpack" x="5" y="40" width="15" height="45" rx="6" fill="#4338ca" stroke="#000" stroke-width="4"/>
                        <g id="layer-clothes"></g>
                        <g id="layer-shoes"></g>
                        <rect x="30" y="30" width="45" height="30" rx="8" fill="#0f172a" stroke="#000" stroke-width="3"/>
                        <g id="eyes">
                            <rect x="38" y="41" width="8" height="8" rx="2" fill="#818cf8"><animate attributeName="height" values="8;1;8" dur="4s" repeatCount="indefinite" /></rect>
                            <rect x="54" y="41" width="8" height="8" rx="2" fill="#818cf8"><animate attributeName="height" values="8;1;8" dur="4s" repeatCount="indefinite" /></rect>
                        </g>
                        <g id="svg-elite" class="animate-weapon"></g>
                    </svg>
                    <div id="layer-hat" class="layer flex justify-center text-7xl -top-6 pointer-events-none drop-shadow-2xl"></div>
                </div>
                
                <div class="mt-6 text-center w-full">
                    <div class="flex flex-wrap gap-2 justify-center" id="stat-badges"></div>
                </div>
            </div>

            <!-- Shop Grid -->
            <div class="lg:col-span-7 flex flex-col gap-6">
                <!-- Tabs -->
                <div class="flex flex-wrap p-2 bg-slate-900/80 rounded-[20px] border border-white/5 shadow-lg gap-2 justify-between">
                    <button onclick="renderStore('colors')" class="flex-1 min-w-[110px] py-3 px-4 rounded-xl text-[10px] font-black uppercase tracking-widest transition" id="tab-colors">Cores</button>
                    <button onclick="renderStore('hardware')" class="flex-1 min-w-[110px] py-3 px-4 rounded-xl text-[10px] font-black uppercase tracking-widest transition" id="tab-hardware">Hardware</button>
                    <button onclick="renderStore('clothing')" class="flex-1 min-w-[110px] py-3 px-4 rounded-xl text-[10px] font-black uppercase tracking-widest transition" id="tab-clothing">Roupas</button>
                    <button onclick="renderStore('footwear')" class="flex-1 min-w-[110px] py-3 px-4 rounded-xl text-[10px] font-black uppercase tracking-widest transition" id="tab-footwear">Sapatos</button>
                    <button onclick="renderStore('elite')" class="flex-1 min-w-[110px] py-3 px-4 rounded-xl text-[10px] font-black uppercase tracking-widest transition" id="tab-elite">Elite</button>
                </div>

                <!-- Grid -->
                <div id="store-grid" class="grid grid-cols-2 sm:grid-cols-3 gap-4 overflow-y-auto pr-2 custom-scrollbar" style="max-height: 580px;"></div>
            </div>

        </div>
    </div>

    <!-- Case Modal -->
    <div id="case-modal" class="fixed inset-0 modal-blur z-50 hidden flex items-center justify-center p-6">
        <div id="case-container" class="bg-slate-950 w-full max-w-3xl rounded-[40px] border border-white/10 p-12 relative overflow-hidden text-center shadow-[0_0_100px_rgba(0,0,0,0.5)]">
            <h2 id="case-title" class="text-3xl font-black text-white mb-10 tracking-tighter italic uppercase">Descodificando...</h2>
            
            <div class="relative w-full h-36 flex items-center overflow-hidden bg-slate-900/50 rounded-2xl mb-10 border border-white/5">
                <div id="case-indicator" class="absolute left-1/2 top-0 bottom-0 w-1 bg-white z-10 shadow-[0_0_20px_white]"></div>
                <div id="case-carousel" class="px-[50%]"></div>
            </div>

            <div id="result-area" class="hidden scale-in-center">
                <div id="winner-tag" class="text-[10px] font-black uppercase tracking-[0.4em] mb-4 text-slate-400">Resultado</div>
                <div id="winner-name" class="text-4xl font-black text-white mb-8 tracking-tighter uppercase italic">---</div>
                <button onclick="closeCaseModal()" class="bg-white text-black font-black py-4 px-16 rounded-xl hover:bg-slate-200 transition shadow-xl uppercase tracking-widest text-xs">Confirmar</button>
            </div>
        </div>
    </div>

    <script>
        // Global error handler para debugar problemas
        window.onerror = function(msg, url, lineNo, columnNo, error) {
            console.error('❌ GLOBAL ERROR:', {msg, url, lineNo, columnNo, error: error?.stack});
            return false;
        };
        window.addEventListener('error', (e) => {
            console.error('❌ ERROR EVENT:', e.error?.stack || e);
        });
        
        // Dados do servidor
        const allItems = <?= $componentes_json ?>;
        const caseTiers = <?= $loot_boxes_json ?>;
        const userBalance = <?= $usuario['pontos'] ?>;
        const equippedServer = <?= json_encode($customizacao_atual) ?>;
        const OWNED_MAP = <?= json_encode($owned_map) ?>;

        let state = {
            balance: userBalance,
            inventory: [],
            equipped: {
                colors: equippedServer.color || 'default',
                hardware: equippedServer.hardware || 'none',
                clothing: equippedServer.clothing || 'none',
                footwear: equippedServer.footwear || 'none',
                elite: equippedServer.elite || 'none'
            },
            currentTab: 'colors'
        };

        console.log('=== DEBUG AVATAR ===');
        console.log('DOM Ready:', document.readyState);
        console.log('Modal element exists:', !!document.getElementById('case-modal'));
        console.log('OWNED_MAP:', OWNED_MAP);
        console.log('allItems keys:', Object.keys(allItems));
        console.log('userBalance:', userBalance);
        console.log('equippedServer:', equippedServer);
        
        // Se modal não existe, log de erro imediato
        if(!document.getElementById('case-modal')) {
            console.error('CRÍTICO: Element case-modal não encontrado no DOM!');
        }

        function hardwareSVG(id, size=44){
            const w=size, h=size;
            switch(id){
                case 'antenna_dish':
                    return `<svg width="${w}" height="${h}" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M6 18 L10 14" stroke="#94a3b8" stroke-width="2"/>
                        <circle cx="10" cy="14" r="2" fill="#0ea5e9"/>
                        <path d="M12 6 a8 8 0 0 1 8 8" stroke="#eab308" stroke-width="2" fill="none"/>
                        <path d="M12 8 a6 6 0 0 1 6 6" stroke="#facc15" stroke-width="2" fill="none"/>
                        <path d="M3 11 a9 9 0 0 0 9 9" fill="#64748b"/></svg>`;
                case 'pixel_crown':
                case 'crown_mythic':
                    const gold = id==='crown_mythic' ? '#f59e0b' : '#facc15';
                    return `<svg width="${w}" height="${h}" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 17 L5 9 L9 13 L12 8 L15 13 L19 9 L21 17 Z" fill="${gold}" stroke="#000" stroke-width="1.2"/>
                        <rect x="4" y="17" width="16" height="3" rx="1" fill="#000000" opacity=".6"/></svg>`;
                case 'robot_ears':
                    return `<svg width="${w}" height="${h}" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <rect x="4" y="8" width="4" height="8" rx="2" fill="#93c5fd" stroke="#1e3a8a"/>
                        <rect x="16" y="8" width="4" height="8" rx="2" fill="#93c5fd" stroke="#1e3a8a"/>
                        <rect x="8" y="10" width="8" height="4" rx="2" fill="#1f2937"/></svg>`;
                case 'halo':
                    return `<svg width="${w}" height="${h}" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <ellipse cx="12" cy="6" rx="7" ry="3" fill="none" stroke="#fde047" stroke-width="2"/></svg>`;
                case 'spiky_hair':
                    return `<svg width="${w}" height="${h}" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M4 10 L8 4 L10 9 L14 3 L15 9 L20 5 L19 11 Z" fill="#f43f5e" stroke="#000" stroke-width="1"/></svg>`;
                case 'visor_tech':
                    return `<svg width="${w}" height="${h}" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <rect x="4" y="8" width="16" height="6" rx="3" fill="#0ea5e9" stroke="#000"/>
                        <rect x="6" y="10" width="5" height="2" rx="1" fill="#111827" opacity=".6"/></svg>`;
                default:
                    return `<svg width="${w}" height="${h}" viewBox="0 0 24 24"><circle cx="12" cy="12" r="6" fill="#334155"/></svg>`
            }
        }

        function getItemPreview(item, category, id) {
            if(category === 'colors') {
                return `<div class="w-12 h-12 rounded-full shadow-lg border-2 border-white/20" style="background:${item.primary}"></div>`;
            }
            // Hardware com SVG customizado
            if(category === 'hardware') {
                const hid = id || 'none';
                return hardwareSVG(hid, 44);
            }
            // Previews específicos por item (SVGs compactos)
            if(category === 'clothing') {
                switch(id){
                    case 'tuxedo':
                        return `<svg width="44" height="44" viewBox="0 0 24 24"><rect x="4" y="8" width="16" height="12" rx="2" fill="#1e293b" stroke="black" stroke-width="1.5"/><path d="M9,8 L12,13 L15,8 Z" fill="#fff"/><rect x="11.4" y="8" width="1.2" height="5" fill="#ef4444"/></svg>`;
                    case 'spartan_armor':
                    case 'cyber_vest':
                    case 'void_armor':
                        return `<svg width="44" height="44" viewBox="0 0 24 24"><rect x="3" y="9" width="18" height="9" rx="2" fill="#475569" stroke="#000" stroke-width="1.5"/><circle cx="12" cy="13" r="2" fill="#3b82f6"/></svg>`;
                    case 'cloak':
                    case 'matrix_coat':
                        return `<svg width="44" height="44" viewBox="0 0 24 24"><path d="M6,6 L3,20 L21,20 L18,6 Z" fill="#7c3aed" opacity="0.6" stroke="#000" stroke-width="1.2"/></svg>`;
                    case 'none':
                    default:
                        return `<svg width="44" height="44" viewBox="0 0 24 24"><rect x="6" y="10" width="12" height="8" rx="2" fill="#334155"/></svg>`;
                }
            }
            if(category === 'footwear') {
                switch(id){
                    case 'sneakers':
                        return `<svg width="44" height="44" viewBox="0 0 24 24"><rect x="5" y="15" width="7" height="4" rx="1.5" fill="#ef4444" stroke="#000" stroke-width="1"/><rect x="12" y="15" width="7" height="4" rx="1.5" fill="#ef4444" stroke="#000" stroke-width="1"/></svg>`;
                    case 'mag_boots':
                        return `<svg width="44" height="44" viewBox="0 0 24 24"><rect x="5" y="15" width="7" height="5" rx="1" fill="#64748b" stroke="#000" stroke-width="1"/><rect x="12" y="15" width="7" height="5" rx="1" fill="#64748b" stroke="#000" stroke-width="1"/></svg>`;
                    case 'jet_thrusters':
                        return `<svg width="44" height="44" viewBox="0 0 24 24"><rect x="6" y="14" width="5" height="4" rx="1" fill="#94a3b8" stroke="#000" stroke-width="1"/><rect x="13" y="14" width="5" height="4" rx="1" fill="#94a3b8" stroke="#000" stroke-width="1"/><path d="M6,18 L8,22 L10,18 Z" fill="#fbbf24"/><path d="M13,18 L15,22 L17,18 Z" fill="#fbbf24"/></svg>`;
                    case 'hover_pads':
                        return `<svg width="44" height="44" viewBox="0 0 24 24"><rect x="6" y="15" width="5" height="3" rx="1" fill="#94a3b8"/><rect x="13" y="15" width="5" height="3" rx="1" fill="#94a3b8"/><rect x="5" y="19" width="6" height="1.5" fill="#22d3ee" opacity=".7"/><rect x="13" y="19" width="6" height="1.5" fill="#22d3ee" opacity=".7"/></svg>`;
                    case 'infinity_boots':
                        return `<svg width="44" height="44" viewBox="0 0 24 24"><path d="M6,16 h4 a2,2 0 1 1 0,4 h-4 a2,2 0 1 1 0-4 Z M14,16 h4 a2,2 0 1 1 0,4 h-4 a2,2 0 1 1 0-4 Z" fill="#f59e0b"/></svg>`;
                    case 'none':
                    default:
                        return `<svg width="44" height="44" viewBox="0 0 24 24"><rect x="5" y="17" width="14" height="2" fill="#334155"/></svg>`;
                }
            }
            if(category === 'elite') {
                switch(id){
                    case 'light_sword':
                        return `<svg width="44" height="44" viewBox="0 0 24 24"><rect x="11" y="6" width="2" height="12" rx="1" fill="#3b82f6" stroke="#fff" stroke-width="0.5"/><rect x="9" y="15" width="6" height="2" fill="#1e293b"/></svg>`;
                    case 'arm_cannon':
                        return `<svg width="44" height="44" viewBox="0 0 24 24"><rect x="14" y="11" width="6" height="4" rx="1" fill="#334155"/><rect x="12" y="12" width="3" height="6" fill="#1e293b"/></svg>`;
                    case 'plasma_rifle':
                        return `<svg width="44" height="44" viewBox="0 0 24 24"><rect x="6" y="11" width="12" height="3" rx="1.5" fill="#475569"/><rect x="9" y="14" width="8" height="2" fill="#0ea5e9"/></svg>`;
                    case 'pet_drone':
                        return `<svg width="44" height="44" viewBox="0 0 24 24"><rect x="15" y="6" width="6" height="6" rx="3" fill="#facc15" stroke="#000" stroke-width="0.8"/><circle cx="18" cy="9" r="1.3" fill="#000"/></svg>`;
                    case 'magic_orb':
                        return `<svg width="44" height="44" viewBox="0 0 24 24"><circle cx="18" cy="12" r="3.2" fill="#a78bfa" stroke="#000" stroke-width="0.8"/></svg>`;
                    case 'infinity_gauntlet':
                        return `<svg width="44" height="44" viewBox="0 0 24 24"><rect x="14" y="13" width="4" height="4" rx="1" fill="#d97706" stroke="#000" stroke-width="0.6"/></svg>`;
                    case 'none':
                    default:
                        return `<svg width="44" height="44" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5" fill="#334155"/></svg>`;
                }
            }
            return `<div class="w-10 h-10 bg-slate-700 rounded"></div>`;
        }

        function renderStore(cat) {
            state.currentTab = cat;
            const grid = document.getElementById('store-grid');
            grid.innerHTML = '';
            document.querySelectorAll('[id^="tab-"]').forEach(btn => btn.classList.remove('bg-indigo-500/20', 'text-indigo-400', 'border-indigo-500/30'));
            document.getElementById(`tab-${cat}`).classList.add('bg-indigo-500/20', 'text-indigo-400', 'border-indigo-500/30');

            const items = allItems[cat] || {};
            Object.keys(items).forEach(key => {
                const item = items[key];
                const isEquipped = state.equipped[cat] === key;
                const isFree = (cat === 'colors' && key === 'default') || (cat !== 'colors' && key === 'none');
                const isOwned = isFree || (OWNED_MAP[cat] && OWNED_MAP[cat][key] === true);
                const card = document.createElement('div');
                card.className = `item-card p-6 flex flex-col items-center justify-between text-center ${isEquipped ? 'equipped' : ''}`;
                card.innerHTML = `
                    <span class="rarity-tag ${item.rarity}">${item.rarity}</span>
                    <div class="my-4 flex items-center justify-center h-16">${getItemPreview(item, cat, key)}</div>
                    <div class="w-full">
                        <div class="text-[9px] font-black uppercase text-slate-400 mb-2 truncate">${item.nome}</div>
                        ${isOwned
                            ? `<button onclick="equip('${cat}', '${key}')" class="w-full py-2 text-[9px] font-black rounded-lg border border-white/5 ${isEquipped ? 'bg-emerald-500 text-black' : 'text-indigo-400 hover:bg-slate-700'} uppercase transition">${isEquipped ? '✓ Equipado' : 'Equipar'}</button>`
                            : `<button disabled class="w-full py-2 text-[9px] font-black rounded-lg border border-white/5 bg-slate-800/60 text-slate-500 uppercase">Bloqueado</button>`}
                    </div>`;
                grid.appendChild(card);
            });
        }

        function equip(cat, id) {
            // Atualiza o estado local e aplica no avatar
            state.equipped[cat] = id;
            updateAvatar();
            renderStore(cat);
            saveCurrentAvatar();
        }

        async function saveCurrentAvatar(){
            try{
                const params = new URLSearchParams();
                params.append('api','salvar_avatar');
                params.append('color', state.equipped.colors);
                params.append('hardware', state.equipped.hardware);
                params.append('clothing', state.equipped.clothing);
                params.append('footwear', state.equipped.footwear);
                params.append('elite', state.equipped.elite);
                params.append('aura', 'none');
                await fetch(window.location.href, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: params });
            }catch(e){ console.error('Falha ao salvar avatar', e); }
        }

        function updateAvatar() {
            const getItem = (cat, id) => allItems[cat][id] || Object.values(allItems[cat])[0];
            const color = getItem('colors', state.equipped.colors);
            const hardware = getItem('hardware', state.equipped.hardware);
            const clothing = getItem('clothing', state.equipped.clothing);
            const footwear = getItem('footwear', state.equipped.footwear);
            const elite = getItem('elite', state.equipped.elite);

            document.getElementById('svg-body').setAttribute('fill', color.primary);
            document.getElementById('svg-backpack').setAttribute('fill', color.secondary);

            const hatKey = state.equipped.hardware;
            document.getElementById('layer-hat').innerHTML = hardwareSVG(hatKey, 64);

            const fxLayer = document.getElementById('layer-fx');
            fxLayer.style.opacity = '0';

            const clothesLayer = document.getElementById('layer-clothes');
            clothesLayer.innerHTML = '';
            const clothingKey = state.equipped.clothing;
            if (clothingKey === 'tuxedo') {
                clothesLayer.innerHTML = `<rect x="20" y="60" width="60" height="45" rx="2" fill="#1e293b" stroke="black" stroke-width="2"/><path d="M40,60 L50,85 L60,60" fill="white"/><rect x="47" y="60" width="6" height="25" fill="#ef4444"/>`;
            } else if (['spartan_armor','cyber_vest','void_armor'].includes(clothingKey)) {
                clothesLayer.innerHTML = `<rect x="15" y="55" width="70" height="30" rx="4" fill="#475569" stroke="black" stroke-width="2"/><circle cx="50" cy="70" r="8" fill="#3b82f6" opacity="0.8"/>`;
            } else if (['cloak','matrix_coat'].includes(clothingKey)) {
                clothesLayer.innerHTML = `<path d="M20,30 L5,110 L95,110 L80,30 Z" fill="#7c3aed" opacity="0.4" stroke="black" stroke-width="2"/>`;
            }

            const shoesLayer = document.getElementById('layer-shoes');
            shoesLayer.innerHTML = '';
            const footwearKey = state.equipped.footwear;
            if (footwearKey === 'sneakers') {
                shoesLayer.innerHTML = `<rect x="25" y="105" width="20" height="10" rx="3" fill="#ef4444" stroke="black" stroke-width="2"/><rect x="55" y="105" width="20" height="10" rx="3" fill="#ef4444" stroke="black" stroke-width="2"/><rect x="25" y="112" width="20" height="4" fill="white"/><rect x="55" y="112" width="20" height="4" fill="white"/>`;
            } else if (['jet_thrusters','hover_pads'].includes(footwearKey)) {
                shoesLayer.innerHTML = `<rect x="25" y="105" width="15" height="8" rx="2" fill="#94a3b8" stroke="black" stroke-width="2"/><rect x="60" y="105" width="15" height="8" rx="2" fill="#94a3b8" stroke="black" stroke-width="2"/><path d="M25,113 L32,125 L40,113" fill="#fbbf24" class="animate-pulse"/><path d="M60,113 L67,125 L75,113" fill="#fbbf24" class="animate-pulse"/>`;
            } else if (footwearKey === 'mag_boots') {
                shoesLayer.innerHTML = `<rect x="24" y="104" width="22" height="11" rx="2" fill="#64748b" stroke="black" stroke-width="2"/><rect x="54" y="104" width="22" height="11" rx="2" fill="#64748b" stroke="black" stroke-width="2"/>`;
            }

            const eliteLayer = document.getElementById('svg-elite');
            eliteLayer.innerHTML = '';
            const eliteKey = state.equipped.elite;
            if (['arm_cannon','plasma_rifle'].includes(eliteKey)) {
                eliteLayer.innerHTML = `<rect x="75" y="60" width="20" height="10" rx="2" fill="#334155" stroke="black" stroke-width="2"/><rect x="70" y="65" width="8" height="15" fill="#1e293b" stroke="black" stroke-width="2"/>`;
            } else if (eliteKey === 'light_sword') {
                eliteLayer.innerHTML = `<rect x="75" y="40" width="6" height="50" rx="2" fill="#3b82f6" stroke="#fff" stroke-width="1"/><rect x="70" y="85" width="16" height="6" fill="#1e293b"/>`;
            } else if (eliteKey === 'pet_drone') {
                eliteLayer.innerHTML = `<rect x="85" y="20" width="15" height="15" rx="8" fill="#facc15" stroke="black" stroke-width="2"/><circle cx="92" cy="27" r="3" fill="#000" class="animate-pulse"/>`;
            } else if (eliteKey === 'magic_orb') {
                eliteLayer.innerHTML = `<circle cx="85" cy="55" r="6" fill="#a78bfa" stroke="#000" stroke-width="2" class="animate-pulse"/>`;
            } else if (eliteKey === 'infinity_gauntlet') {
                eliteLayer.innerHTML = `<rect x="70" y="70" width="12" height="12" rx="2" fill="#d97706" stroke="#000" stroke-width="2"/>`;
            }

            document.getElementById('stat-badges').innerHTML = `
                <span class=\"px-4 py-1 rounded-full bg-white/5 border border-white/10 text-[9px] font-black text-slate-500 uppercase tracking-widest\">${clothing.nome}</span>
                <span class=\"px-4 py-1 rounded-full bg-indigo-500/10 border border-indigo-500/20 text-[9px] font-black text-indigo-400 uppercase tracking-widest\">${elite.nome}</span>
            `;
        }

        async function openCaseModal(tierKey) {
            console.log('🎁 Abrindo caixa:', tierKey);
            
            const tier = caseTiers[tierKey];
            if(!tier) {
                alert("Tipo de caixa inválido!");
                return;
            }
            
            if(state.balance < tier.preco) {
                alert("SALDO INSUFICIENTE. Você tem " + state.balance + " pts, precisa de " + tier.preco + " pts.");
                return;
            }

            const modal = document.getElementById('case-modal');
            const carousel = document.getElementById('case-carousel');
            const resultArea = document.getElementById('result-area');
            const container = document.getElementById('case-container');
            
            // Proteção contra elementos null
            if (!modal || !carousel || !resultArea || !container) {
                console.error('❌ Modal elements not found:', { modal, carousel, resultArea, container });
                alert('Erro ao carregar modal. Recarregue a página.');
                return;
            }
            
            modal.classList.remove('hidden'); 
            resultArea.classList.add('hidden');
            carousel.style.transition = 'none'; 
            carousel.style.transform = 'translateX(0)';
            document.getElementById('case-title').innerText = `Abrindo ${tier.nome}...`;

            const allPossibleItems = [];
            Object.keys(allItems).forEach(cat => { 
                Object.keys(allItems[cat]).forEach(key => { 
                    const item = allItems[cat][key]; 
                    if(item.preco > 0 && key !== 'default' && key !== 'none') {
                        allPossibleItems.push({ ...item, id: key, category: cat }); 
                    }
                }); 
            });
            
            console.log('📦 Itens possíveis:', allPossibleItems.length);
            
            const lotteryPool = [];
            // Alinha probabilidades do front com o servidor (core/avatar.php -> $LOOT_BOXES)
            allPossibleItems.forEach(item => {
                const r = item.rarity; 
                let c = 0;
                if(tierKey==='basica'){
                    if(r==='common') c=85; else if(r==='rare') c=14; else if(r==='epic') c=1;
                } else if(tierKey==='top'){
                    if(r==='common') c=50; else if(r==='rare') c=35; else if(r==='epic') c=13; else if(r==='legendary') c=2;
                } else if(tierKey==='premium'){
                    if(r==='common') c=20; else if(r==='rare') c=30; else if(r==='epic') c=30; else if(r==='legendary') c=18; else if(r==='mythic') c=2;
                }
                for(let i=0;i<c;i++) lotteryPool.push(item);
            });

            console.log('🎲 Pool de sorteio:', lotteryPool.length);

            carousel.innerHTML = '';
            for(let i=0;i<85;i++){ 
                const rand = lotteryPool[Math.floor(Math.random()*lotteryPool.length)]; 
                const div=document.createElement('div'); 
                div.className='case-item border border-white/5'; 
                div.innerHTML=getItemPreview(rand, rand.category, rand.id); 
                carousel.appendChild(div); 
            }

            let winner = lotteryPool[Math.floor(Math.random()*lotteryPool.length)];
            console.log('🏆 Vencedor visual (antes da API):', winner);
            
            try {
                console.log('📡 Enviando requisição para servidor...');
                const fd = new FormData();
                fd.append('api', 'abrir_caixa');
                fd.append('tipo_caixa', tierKey);
                
                console.log('Enviando:', {api: 'abrir_caixa', tipo_caixa: tierKey});
                
                const resp = await fetch(window.location.href, { 
                    method: 'POST', 
                    body: fd 
                });
                
                console.log('✅ Resposta HTTP:', resp.status, resp.statusText);
                
                if (!resp.ok) {
                    throw new Error(`HTTP ${resp.status}`);
                }
                
                const text = await resp.text();
                console.log('📄 Resposta bruta:', text);
                
                let data;
                try {
                    data = JSON.parse(text);
                } catch(e) {
                    console.error('❌ Erro ao parsear JSON:', e, 'Texto:', text);
                    alert('Erro ao processar resposta do servidor');
                    modal.classList.add('hidden');
                    return;
                }
                
                console.log('🔄 Dados processados:', data);
                
                if(data && data.sucesso){
                    console.log('✅ Caixa aberta com sucesso!');
                    state.balance = parseInt(data.pontos_restantes) || 0; 
                    updateBalance();
                    const cat = data.categoria; 
                    const key = data.item_id;
                    console.log('Item obtido:', {categoria: cat, id: key, nome: data.item_nome});
                    
                    if(allItems[cat] && allItems[cat][key]){
                        winner = { ...allItems[cat][key], id:key, category:cat };
                        console.log('🏆 Vencedor atualizado:', winner);
                    }
                }
                else if(data && data.mensagem){ 
                    console.warn('⚠️ Aviso:', data.mensagem);
                    alert(data.mensagem); 
                    modal.classList.add('hidden');
                    return;
                }
                else {
                    console.error('❌ Resposta inválida:', data);
                    alert('Erro desconhecido ao abrir caixa'); 
                    modal.classList.add('hidden');
                    return;
                }
            } catch(e){ 
                console.error('❌ Exceção ao chamar API:', e); 
                alert('Erro ao abrir caixa: ' + e.message);
                modal.classList.add('hidden');
                return;
            }

            const targetIndex = 75; 
            carousel.children[targetIndex].innerHTML = getItemPreview(winner, winner.category, winner.id); 
            carousel.children[targetIndex].classList.add('bg-white/5');
            
            setTimeout(()=>{
                carousel.style.transition='transform 7s cubic-bezier(0.1, 0, 0.1, 1)';
                const targetEl = carousel.children[targetIndex];
                const parent = carousel.parentElement; // faixa visível
                const targetCenter = targetEl.offsetLeft + (targetEl.offsetWidth/2);
                const desired = Math.max(0, targetCenter - (parent.clientWidth/2));
                carousel.style.transform = `translateX(-${desired}px)`;
            },50);
            
            setTimeout(()=>{ 
                const nameEl=document.getElementById('winner-name'); 
                const tagEl=document.getElementById('winner-tag'); 
                
                if(!nameEl || !tagEl) {
                    console.error('❌ Elementos do resultado não encontrados');
                    return;
                }
                
                nameEl.innerText = (winner.nome||'---').toUpperCase(); 
                nameEl.style.color = (winner.rarity==='legendary'||winner.rarity==='mythic') ? '#f59e0b':'#fff'; 
                tagEl.innerText = `NOVO ${String(winner.rarity||'item').toUpperCase()} ENCONTRADO`; 
                tagEl.className = 'text-[10px] font-black uppercase tracking-[0.4em] mb-4 text-indigo-400'; 
                resultArea.classList.remove('hidden'); 
                
                // Atualiza posse em memória para liberar botão Equipar sem recarregar
                if(!OWNED_MAP[winner.category]) OWNED_MAP[winner.category] = {}; 
                OWNED_MAP[winner.category][winner.id] = true; 
                
                // Atualiza grid atual se estiver na mesma categoria
                if(state.currentTab === winner.category) renderStore(state.currentTab);
                
                console.log('🎉 Resultado exibido!');
            },7500);
        }

        function closeCaseModal(){ 
            const modal = document.getElementById('case-modal');
            if(modal) modal.classList.add('hidden');
        }
        function updateBalance(){ document.getElementById('balance-display').innerText = `${state.balance.toLocaleString()} pts`; }
        renderStore('colors'); updateAvatar();
    </script>
    </body>
    </html>
