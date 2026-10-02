<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo = db();

/* Aqui o time continua sendo o DO ADMIN, de propósito: a timeline é de todas
   as ligas (tem filtro próprio na tela) e este $team_id é de quem posta. No
   observador, trocá-lo faria o post sair no nome do time observado — e o modo
   é um óculos, não um login como outra pessoa. */
$team_id = $_SESSION['team_id'] ?? null;
$team = [];
if ($team_id) {
    $stmt = $pdo->prepare("SELECT id, name, city, photo_url, league FROM teams WHERE id = ?");
    $stmt->execute([$team_id]);
    $team = $stmt->fetch() ?: [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="theme-color" content="#fc0025">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>FBAX — FBA Manager</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">
    <?php include 'includes/head-pwa.php'; ?>
    <style>
        :root {
            --red: #fc0025; --red-2: color-mix(in srgb, var(--red) 85%, white); --red-soft: color-mix(in srgb, var(--red) 10%, transparent); --red-glow: color-mix(in srgb, var(--red) 18%, transparent);
            --bg: #07070a; --panel: #101013; --panel-2: #16161a; --panel-3: #1c1c21;
            --border: rgba(255,255,255,.06); --border-md: rgba(255,255,255,.10); --border-red: color-mix(in srgb, var(--red) 22%, transparent);
            --text: #f0f0f3; --text-2: #868690; --text-3: #7d7d85;
            --green: #22c55e; --amber: #f59e0b; --blue: #3b82f6; --purple: #a855f7;
            --sidebar-w: 260px; --font: 'Montserrat', sans-serif;
            --radius: 14px; --radius-sm: 10px; --radius-xs: 6px;
            --ease: cubic-bezier(.2,.8,.2,1); --t: 200ms;
        }
        :root[data-theme="light"] {
            --bg: #f6f7fb; --panel: #ffffff; --panel-2: #f2f4f8; --panel-3: #e9edf4;
            --border: #e3e6ee; --border-md: #d7dbe6; --border-red: color-mix(in srgb, var(--red) 18%, transparent);
            --text: #111217; --text-2: #5b6270; --text-3: #657080;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }
        .app { display: flex; min-height: 100vh; }
        .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }
        .page-hero-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin: 32px 32px 4px; }
        .page-hero-title { font-size: 26px; font-weight: 800; color: var(--text); margin: 0 32px 4px; display: flex; align-items: center; gap: 10px; }
        .page-hero-sub { font-size: 13px; color: var(--text-2); margin: 0 32px 18px; max-width: 640px; }
        .content { padding: 0 32px 48px; flex: 1; }
        .topbar { display: none; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); padding: 0 16px; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 200; }
        .topbar-title { font-size: 15px; font-weight: 700; color: var(--text); }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn { background: transparent; border: 1px solid var(--border); color: var(--text); width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 299; }
        .sb-overlay.show { display: block; }

        .sidebar { position: fixed; top: 0; left: 0; width: 260px; height: 100vh; background: var(--panel); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 300; transition: transform var(--t) var(--ease); overflow-y: auto; scrollbar-width: none; }
        .sidebar::-webkit-scrollbar { display: none; }
        .sb-brand { padding: 22px 18px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .sb-logo { width: 34px; height: 34px; border-radius: 9px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; flex-shrink: 0; }
        .sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
        .sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
        .sb-team { margin: 14px 14px 0; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-team-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
        .sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }
        .sb-nav { flex: 1; padding: 12px 10px 8px; }
        .sb-section { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 12px 10px 6px; }
        .sb-nav a { font-family:'Inter',sans-serif; display: flex; align-items: center; gap: 10px; padding: 10px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-size: 13px; font-weight: 500; text-decoration: none; margin-bottom: 2px; transition: all var(--t) var(--ease); }
        .sb-nav a i { font-size: 15px; width: 18px; text-align: center; flex-shrink: 0; }
        .sb-nav a:hover { background: var(--panel-2); color: var(--text); }
        .sb-nav a.active { background: var(--red-soft); color: var(--red); font-weight: 600; }
        .sb-nav a.active i { color: var(--red); }
        .sb-theme-toggle { margin: 0 14px 12px; padding: 8px 10px; border-radius: 10px; border: 1px solid var(--border); background: var(--panel-2); color: var(--text); display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all var(--t) var(--ease); }
        .sb-theme-toggle:hover { border-color: var(--border-red); color: var(--red); }
        .sb-footer { padding: 12px 14px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .sb-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-username { font-size: 12px; font-weight: 500; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sb-logout { width: 26px; height: 26px; border-radius: 7px; background: transparent; border: 1px solid var(--border); color: var(--text-2); display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; transition: all var(--t) var(--ease); text-decoration: none; flex-shrink: 0; }
        .sb-logout:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

        /* ── FBAX ─────────────────────────────────────────────────────────
           Coluna única e estreita, como todo feed de texto: a linha longa
           cansa, e aqui o conteúdo é leitura, não vitrine de foto. O Timeline
           antigo tinha grid de fotos justamente porque era de outro formato. */
        .fx-col { max-width: 600px; margin: 0 auto; }

        /* A barra de cima: filtros à esquerda, sino à direita. */
        .fx-barra { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 14px; }
        .fx-chips { display: flex; gap: 8px; flex-wrap: wrap; flex: 1; min-width: 0; }

        /* O SINO. Fica na barra e não no menu lateral porque o aviso é desta
           tela: quem está lendo o feed é quem quer saber que foi respondido. */
        .fx-sino { position: relative; flex: none; width: 38px; height: 34px; border-radius: 999px;
            background: var(--panel-2); border: 1px solid var(--border); color: var(--text-2);
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            font-size: 15px; transition: all var(--t) var(--ease); }
        .fx-sino:hover { border-color: var(--border-md); color: var(--text); }
        .fx-sino.tem { color: var(--red); border-color: var(--border-red); }
        .fx-sino-n { position: absolute; top: -5px; right: -4px; min-width: 17px; height: 17px;
            padding: 0 4px; border-radius: 999px; background: var(--red); color: #fff;
            font-size: 10px; font-weight: 800; line-height: 17px; font-variant-numeric: tabular-nums; }

        /* Um aviso da lista. A linha inteira é clicável — abrir a conversa é
           a única coisa que se quer fazer com ele. */
        .fx-aviso { display: flex; gap: 10px; align-items: flex-start; width: 100%; text-align: left;
            padding: 12px 14px; background: var(--panel); border: 1px solid var(--border); border-top: 0;
            color: var(--text); font-family: var(--font); cursor: pointer; }
        .fx-aviso:first-of-type { border-top: 1px solid var(--border); border-radius: var(--radius) var(--radius) 0 0; }
        .fx-aviso:last-of-type { border-radius: 0 0 var(--radius) var(--radius); }
        /* Opaco de propósito: var(--red-soft) é translúcido e, com o painel
           por cima da página, o conteúdo de trás aparecia através do aviso. */
        .fx-aviso.novo { background: color-mix(in srgb, var(--red) 10%, var(--panel)); }
        .fx-aviso:hover { background: var(--panel-2); }
        .fx-aviso i.ico { font-size: 15px; color: var(--red); line-height: 1.3; }
        .fx-aviso-txt { flex: 1; min-width: 0; font-size: 13.5px; line-height: 1.45; }
        .fx-aviso-txt b { font-weight: 700; }
        .fx-aviso-txt small { display: block; color: var(--text-3); font-size: 12px; margin-top: 2px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .fx-aviso-quando { flex: none; color: var(--text-3); font-size: 11.5px; }

        /* O time marcado no texto. Só pinta: não existe página de um time só
           pra onde mandar, e um link que não leva a lugar nenhum frustra mais
           do que a cor resolve. */
        .fx-mencao { color: var(--red); font-weight: 600; }

        /* A lista que completa o @. Posição fixa, como o menu do repost: ela
           nasce colada no campo e o campo pode estar dentro de um modal. */
        .fx-mencoes { position: fixed; z-index: 500; display: none; min-width: 220px; max-width: 320px;
            max-height: 240px; overflow-y: auto; background: var(--panel-2);
            border: 1px solid var(--border-md); border-radius: var(--radius-sm); padding: 4px;
            box-shadow: 0 12px 28px rgba(0,0,0,.5); }
        .fx-mencoes.show { display: block; }
        .fx-mencao-item { display: flex; align-items: center; gap: 8px; width: 100%; padding: 7px 9px;
            border: 0; background: transparent; color: var(--text); font-family: var(--font);
            font-size: 13px; text-align: left; cursor: pointer; border-radius: 7px; }
        .fx-mencao-item:hover, .fx-mencao-item.sel { background: var(--panel-3); }
        .fx-mencao-item img { width: 22px; height: 22px; border-radius: 50%; object-fit: cover; flex: none; }
        .fx-mencao-item .arroba { color: var(--text-3); font-size: 12px; }
        .fx-mencao-item .nome { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .fx-chip { padding: 6px 14px; border-radius: 999px; background: var(--panel-2); border: 1px solid var(--border);
            color: var(--text-2); font-size: 12px; font-weight: 600; cursor: pointer; font-family: var(--font); transition: all var(--t) var(--ease); }
        .fx-chip:hover { border-color: var(--border-md); color: var(--text); }
        .fx-chip.active { background: var(--red-soft); border-color: var(--red); color: var(--red); }

        /* O compositor. Cresce com o texto — caixa fixa de cinco linhas num
           feed onde quase todo post tem uma só é espaço morto no topo. */
        .fx-box { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px; margin-bottom: 16px; }
        .fx-box textarea { width: 100%; background: transparent; border: 0; resize: none; color: var(--text);
            font-family: var(--font); font-size: 15px; line-height: 1.45; outline: none; min-height: 52px; }
        .fx-box textarea::placeholder { color: var(--text-3); }
        .fx-box-pe { display: flex; align-items: center; gap: 8px; margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border); }
        .fx-icone { width: 34px; height: 34px; border-radius: 50%; background: transparent; border: 0; color: var(--red);
            font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background var(--t) var(--ease); }
        .fx-icone:hover { background: var(--red-soft); }
        .fx-conta { margin-left: auto; font-size: 12px; color: var(--text-3); font-variant-numeric: tabular-nums; }
        .fx-conta.perto { color: var(--amber); }
        .fx-conta.estourou { color: var(--red); }
        .fx-enviar { padding: 8px 20px; border-radius: 999px; background: var(--red); border: 0; color: #fff;
            font-weight: 700; font-size: 13px; cursor: pointer; font-family: var(--font); transition: opacity var(--t) var(--ease); }
        .fx-enviar:disabled { opacity: .45; cursor: default; }
        .fx-previa { position: relative; margin-top: 10px; }
        .fx-previa img { width: 100%; border-radius: var(--radius-sm); display: block; }
        .fx-previa button { position: absolute; top: 8px; right: 8px; width: 28px; height: 28px; border-radius: 50%;
            background: rgba(0,0,0,.65); border: 0; color: #fff; cursor: pointer; }

        /* O post. Avatar à esquerda, conteúdo à direita — a forma que faz a
           conversa ser lida de cima a baixo sem procurar onde cada fala começa. */
        .fx-post { display: flex; gap: 12px; padding: 14px; border: 1px solid var(--border); border-top: 0;
            background: var(--panel); transition: background var(--t) var(--ease); }
        .fx-post:first-of-type { border-top: 1px solid var(--border); border-radius: var(--radius) var(--radius) 0 0; }
        .fx-post:last-of-type { border-radius: 0 0 var(--radius) var(--radius); }
        .fx-post.alvo { background: var(--panel-2); }
        .fx-post .avatar { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; flex-shrink: 0;
            border: 1px solid var(--border-md); background: var(--panel-3); }
        .fx-corpo { flex: 1; min-width: 0; }
        .fx-topo { display: flex; align-items: baseline; gap: 6px; flex-wrap: wrap; font-size: 13px; }
        .fx-time { font-weight: 700; color: var(--text); }
        .fx-liga { font-size: 10px; font-weight: 700; letter-spacing: .4px; padding: 1px 6px; border-radius: 999px;
            background: var(--panel-3); color: var(--text-2); }
        .fx-gm, .fx-quando { color: var(--text-3); font-size: 12px; }
        .fx-texto { font-size: 15px; line-height: 1.5; color: var(--text); margin-top: 3px; white-space: pre-wrap; word-wrap: break-word; }
        .fx-foto { margin-top: 10px; border-radius: var(--radius-sm); border: 1px solid var(--border); overflow: hidden; }
        .fx-foto img { width: 100%; display: block; }

        /* O repost citado: o post de dentro vira um cartão, pra ninguém
           confundir a palavra de quem citou com a de quem foi citado. */
        .fx-citado { margin-top: 10px; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 10px; background: var(--panel-2); }
        .fx-citado .fx-texto { font-size: 14px; }
        .fx-citado .avatar { width: 22px; height: 22px; }
        .fx-citado-topo { display: flex; align-items: center; gap: 6px; font-size: 12px; }
        .fx-sumiu { font-size: 13px; color: var(--text-3); font-style: italic; }

        .fx-marca { font-size: 12px; color: var(--text-3); display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }

        .fx-acoes { display: flex; gap: 4px; margin-top: 8px; }
        .fx-acao { display: flex; align-items: center; gap: 5px; padding: 5px 10px; border-radius: 999px;
            background: transparent; border: 0; color: var(--text-3); font-size: 12.5px; font-family: var(--font);
            cursor: pointer; transition: all var(--t) var(--ease); font-variant-numeric: tabular-nums; }
        .fx-acao:hover { background: var(--panel-3); color: var(--text); }
        .fx-acao.on.curtir { color: var(--red); }
        .fx-acao.on.repostar { color: var(--green); }
        .fx-acao.apagar:hover { color: var(--red); }

        /* O menu do repost. Dois caminhos a partir do mesmo botão, como no
           Twitter: repostar seco é um clique, citar abre o compositor. Dois
           botões soltos na barra de ações deixariam cinco ícones por post. */
        .fx-menu { position: fixed; z-index: 450; background: var(--panel); border: 1px solid var(--border-md);
            border-radius: var(--radius-sm); padding: 6px; min-width: 212px; display: none;
            box-shadow: 0 12px 32px rgba(0,0,0,.45); }
        .fx-menu.show { display: block; }
        .fx-menu button { display: flex; align-items: center; gap: 9px; width: 100%; padding: 9px 10px;
            background: transparent; border: 0; border-radius: var(--radius-xs); color: var(--text);
            font-family: var(--font); font-size: 13.5px; font-weight: 600; cursor: pointer; text-align: left; }
        .fx-menu button:hover { background: var(--panel-3); }
        .fx-menu button i { color: var(--text-2); }

        .fx-vazio { text-align: center; padding: 40px 16px; color: var(--text-3); font-size: 13.5px; }
        .fx-mais { width: 100%; margin-top: 14px; padding: 10px; border-radius: var(--radius-sm);
            background: var(--panel-2); border: 1px solid var(--border); color: var(--text-2);
            font-family: var(--font); font-size: 13px; font-weight: 600; cursor: pointer; }

        /* A thread abre por cima, como no Twitter: sair dela devolve o feed na
           mesma rolagem, e não no topo. */
        .fx-modal { position: fixed; inset: 0; background: rgba(0,0,0,.72); z-index: 400; display: none;
            align-items: flex-start; justify-content: center; padding: 24px 16px; overflow-y: auto; }
        .fx-modal.show { display: flex; }
        .fx-modal-cx { width: 100%; max-width: 600px; }
        .fx-modal-topo { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .fx-voltar { width: 34px; height: 34px; border-radius: 50%; background: var(--panel-2); border: 1px solid var(--border);
            color: var(--text); cursor: pointer; display: flex; align-items: center; justify-content: center; }

        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; } }
        /* A dobra do menu é a mesma do resto do site (992px): sem este
           bloco a barra lateral continuava ocupando 260px no celular e o
           feed ficava espremido numa faixa de texto quebrado. */
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .topbar { display: flex; }
            .page-hero-eyebrow, .page-hero-title, .page-hero-sub { margin-left: 16px; margin-right: 16px; }
            .content { padding: 0 16px 48px; }
        }
        @media (max-width: 760px) {
            .content { padding: 0 14px 40px; }
            .page-hero-eyebrow, .page-hero-title, .page-hero-sub { margin-left: 14px; margin-right: 14px; }
            .fx-post { padding: 12px; }
            .fx-post .avatar { width: 38px; height: 38px; }
            .fx-texto { font-size: 14.5px; }
        }
    <?php include __DIR__ . '/includes/accent-color.php'; ?>
    </style>
</head>
<body>
<div class="app">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="sb-overlay" id="sbOverlay"></div>

    <main class="main">
        <header class="topbar">
            <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
            <div class="topbar-title">FBA <em>X</em></div>
        </header>

        <div class="page-hero-eyebrow">FBA · Comunidade</div>
        <h1 class="page-hero-title"><i class="bi bi-chat-square-text-fill" style="color:var(--red)"></i>FBAX</h1>
        <p class="page-hero-sub">O que a liga está falando. Poste, responda, reposte — texto, foto, ou os dois.</p>

        <div class="content">
            <div class="fx-col">
                <div class="fx-barra">
                    <div class="fx-chips" id="fxChips">
                        <button class="fx-chip active" data-league="">Todas</button>
                        <button class="fx-chip" data-league="ELITE">ELITE</button>
                        <button class="fx-chip" data-league="NEXT">NEXT</button>
                        <button class="fx-chip" data-league="RISE">RISE</button>
                        <button class="fx-chip" data-league="ROOKIE">ROOKIE</button>
                    </div>
                    <button type="button" class="fx-sino" id="fxSino" title="Seus avisos">
                        <i class="bi bi-bell"></i><span class="fx-sino-n" id="fxSinoN" hidden>0</span>
                    </button>
                </div>

                <div class="fx-box" id="fxCompositor" style="display:none">
                    <textarea id="fxTexto" placeholder="O que está acontecendo?" maxlength="600"></textarea>
                    <div class="fx-previa" id="fxPrevia" style="display:none">
                        <img id="fxPreviaImg" alt="">
                        <button type="button" id="fxTirarFoto" title="Tirar a foto"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <div class="fx-box-pe">
                        <button type="button" class="fx-icone" id="fxBtnFoto" title="Adicionar foto"><i class="bi bi-image"></i></button>
                        <input type="file" id="fxArquivo" accept="image/*" style="display:none">
                        <span class="fx-conta" id="fxConta">600</span>
                        <button type="button" class="fx-enviar" id="fxPostar" disabled>Postar</button>
                    </div>
                </div>

                <div id="fxFeed"><div class="fx-vazio">Carregando…</div></div>
                <button type="button" class="fx-mais" id="fxMais" style="display:none">Carregar mais</button>
            </div>
        </div>
    </main>
</div>

<div class="fx-menu" id="fxMenuRepost">
    <button type="button" data-rp="seco"><i class="bi bi-arrow-repeat"></i><span id="fxRpSecoTxt">Repostar</span></button>
    <button type="button" data-rp="citar"><i class="bi bi-pencil-square"></i>Citar com comentário</button>
</div>

<div class="fx-modal" id="fxCitar">
    <div class="fx-modal-cx">
        <div class="fx-modal-topo">
            <button type="button" class="fx-voltar" id="fxCitarFechar"><i class="bi bi-x-lg"></i></button>
            <strong style="font-size:15px">Citar post</strong>
        </div>
        <div class="fx-box">
            <textarea id="fxCitarTexto" placeholder="Diga alguma coisa sobre isso…" maxlength="600"></textarea>
            <div class="fx-previa" id="fxCitarPrevia" style="display:none">
                <img id="fxCitarPreviaImg" alt="">
                <button type="button" id="fxCitarTirarFoto" title="Tirar a foto"><i class="bi bi-x-lg"></i></button>
            </div>
            <div id="fxCitarAlvo"></div>
            <div class="fx-box-pe">
                <button type="button" class="fx-icone" id="fxCitarBtnFoto" title="Adicionar foto"><i class="bi bi-image"></i></button>
                <input type="file" id="fxCitarArquivo" accept="image/*" style="display:none">
                <span class="fx-conta" id="fxCitarConta">600</span>
                <button type="button" class="fx-enviar" id="fxCitarEnviar">Postar</button>
            </div>
        </div>
    </div>
</div>

<div class="fx-modal" id="fxAvisos">
    <div class="fx-modal-cx">
        <div class="fx-modal-topo">
            <button type="button" class="fx-voltar" id="fxAvisosFechar"><i class="bi bi-arrow-left"></i></button>
            <strong style="font-size:15px">Seus avisos</strong>
            <button type="button" class="fx-chip" id="fxAvisosLer" style="margin-left:auto">Marcar tudo como lido</button>
        </div>
        <div id="fxAvisosLista"></div>
    </div>
</div>

<div class="fx-mencoes" id="fxMencoes"></div>

<div class="fx-modal" id="fxModal">
    <div class="fx-modal-cx">
        <div class="fx-modal-topo">
            <button type="button" class="fx-voltar" id="fxFechar"><i class="bi bi-arrow-left"></i></button>
            <strong style="font-size:15px">Post</strong>
        </div>
        <div id="fxThread"></div>
    </div>
</div>

<script>
    window.MY_TEAM_ID = <?= (int)($team['id'] ?? 0) ?>;
</script>
<script src="<?= assetUrl('/js/fbax.js') ?>"></script>
<script src="<?= assetUrl('/js/pwa.js') ?>"></script>
<script>
    const sidebar = document.getElementById('sidebar');
    const sbOverlay = document.getElementById('sbOverlay');
    const menuBtn = document.getElementById('menuBtn');
    if (menuBtn) menuBtn.addEventListener('click', () => { sidebar.classList.add('open'); sbOverlay.classList.add('show'); });
    if (sbOverlay) sbOverlay.addEventListener('click', () => { sidebar.classList.remove('open'); sbOverlay.classList.remove('show'); });
    const themeKey = 'fba-theme';
    const themeBtn = document.getElementById('themeToggle');
    const applyTheme = (theme) => {
        if (theme === 'light') {
            document.documentElement.setAttribute('data-theme', 'light');
            if (themeBtn) { themeBtn.innerHTML = '<i class="bi bi-moon-fill"></i><span>Tema escuro</span>'; themeBtn.setAttribute('aria-pressed','true'); }
        } else {
            document.documentElement.removeAttribute('data-theme');
            if (themeBtn) { themeBtn.innerHTML = '<i class="bi bi-sun-fill"></i><span>Tema claro</span>'; themeBtn.setAttribute('aria-pressed','false'); }
        }
    };
    applyTheme(localStorage.getItem(themeKey) || 'dark');
    if (themeBtn) themeBtn.addEventListener('click', () => {
        const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        localStorage.setItem(themeKey, next);
        applyTheme(next);
    });
</script>
</body>
</html>
