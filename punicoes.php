<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
if (($user['user_type'] ?? 'jogador') !== 'admin') {
    header('Location: /dashboard.php');
    exit;
}

$pdo = db();

$team = null;
try {
    $s = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
    $s->execute([$user['id']]);
    $team = $s->fetch() ?: null;
} catch (Exception $e) {}

$currentSeason     = null;
$seasonDisplayYear = (int)date('Y');
try {
    $s = $pdo->prepare("
        SELECT s.season_number, s.year, sp.sprint_number, sp.start_year
        FROM seasons s
        INNER JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ('completed'))
        ORDER BY s.created_at DESC LIMIT 1
    ");
    $s->execute([$user['league']]);
    $currentSeason = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($currentSeason) {
        $seasonDisplayYear = isset($currentSeason['start_year'], $currentSeason['season_number'])
            ? (int)$currentSeason['start_year'] + (int)$currentSeason['season_number'] - 1
            : (int)($currentSeason['year'] ?? date('Y'));
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#fc0025">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="manifest" href="/manifest.json?v=3">
    <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
    <title>Punições - FBA Manager</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">

    <style>
        :root {
            --red:        #fc0025;
            --red-soft:   color-mix(in srgb, var(--red) 10%, transparent);
            --red-glow:   color-mix(in srgb, var(--red) 18%, transparent);
            --bg:         #07070a;
            --panel:      #101013;
            --panel-2:    #16161a;
            --panel-3:    #1c1c21;
            --border:     rgba(255,255,255,.06);
            --border-md:  rgba(255,255,255,.10);
            --border-red: color-mix(in srgb, var(--red) 22%, transparent);
            --text:       #f0f0f3;
            --text-2:     #868690;
            --text-3:     #7d7d85;
            --green:      #22c55e;
            --amber:      #f59e0b;
            --sidebar-w:  260px;
            --font:       'Montserrat', sans-serif;
            --radius:     14px;
            --radius-sm:  10px;
            --ease:       cubic-bezier(.2,.8,.2,1);
            --t:          200ms;
        }
        :root[data-theme="light"] {
            --bg:         #f6f7fb;
            --panel:      #ffffff;
            --panel-2:    #f2f4f8;
            --panel-3:    #e9edf4;
            --border:     #e3e6ee;
            --border-md:  #d7dbe6;
            --border-red: color-mix(in srgb, var(--red) 18%, transparent);
            --text:       #111217;
            --text-2:     #5b6270;
            --text-3:     #657080;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

        .app { display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: 260px; height: 100vh;
            background: var(--panel); border-right: 1px solid var(--border);
            display: flex; flex-direction: column;
            z-index: 300; overflow-y: auto; scrollbar-width: none;
            transition: transform var(--t) var(--ease);
        }
        .sidebar::-webkit-scrollbar { display: none; }
        .sb-brand { padding: 22px 18px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .sb-logo { width: 34px; height: 34px; border-radius: 9px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; flex-shrink: 0; }
        .sb-brand-text { font-weight: 700; font-size: 15px; line-height: 1.1; }
        .sb-brand-text span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
        .sb-team { margin: 14px 14px 0; background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .sb-team img { width: 40px; height: 40px; border-radius: 9px; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .sb-team-name { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.2; }
        .sb-team-league { font-size: 11px; color: var(--red); font-weight: 600; }
        .sb-season { margin: 10px 14px 0; background: var(--red-soft); border: 1px solid var(--border-red); border-radius: 8px; padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .sb-season-label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); }
        .sb-season-val { font-size: 14px; font-weight: 700; color: var(--red); }
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

        /* Topbar mobile */
        .topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 54px; background: var(--panel); border-bottom: 1px solid var(--border); align-items: center; padding: 0 16px; gap: 12px; z-index: 240; }
        .topbar-title { font-weight: 700; font-size: 15px; flex: 1; }
        .topbar-title em { color: var(--red); font-style: normal; }
        .menu-btn { width: 34px; height: 34px; border-radius: 9px; background: var(--panel-2); border: 1px solid var(--border); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 17px; }
        .sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(4px); z-index: 250; }
        .sb-overlay.show { display: block; }

        /* Main */
        .main { margin-left: var(--sidebar-w); min-height: 100vh; width: calc(100% - var(--sidebar-w)); display: flex; flex-direction: column; }
        .page-hero { padding: 28px 32px 20px; border-bottom: 1px solid var(--border); }
        .page-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 1.4px; text-transform: uppercase; color: var(--red); margin-bottom: 4px; }
        .page-title { font-size: 22px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .page-title i { color: var(--red); }
        .content { padding: 24px 32px 48px; flex: 1; }

        /* Panel cards */
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
        }
        .panel-head {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 8px;
        }
        .panel-head-title { font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .panel-head-title i { color: var(--red); font-size: 15px; }
        .panel-body { padding: 18px; }

        /* Form fields override */
        .form-label { font-size: 12px; font-weight: 600; color: var(--text-2); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
        .form-control, .form-select {
            background: var(--panel-2) !important;
            border: 1px solid var(--border-md) !important;
            color: var(--text) !important;
            border-radius: var(--radius-sm) !important;
            font-family: var(--font);
            font-size: 13px;
            transition: border-color var(--t) var(--ease);
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--border-red) !important;
            box-shadow: 0 0 0 3px var(--red-glow) !important;
            background: var(--panel-2) !important;
            color: var(--text) !important;
        }
        .form-control::placeholder { color: var(--text-3); }

        /* Submit button */
        .btn-submit {
            width: 100%; padding: 10px; border-radius: var(--radius-sm);
            background: var(--red); border: none; color: #fff;
            font-family: var(--font); font-size: 13px; font-weight: 600;
            cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: filter var(--t) var(--ease);
        }
        .btn-submit:hover { filter: brightness(1.1); }
        .btn-submit-outline {
            width: 100%; padding: 10px; border-radius: var(--radius-sm);
            background: transparent; border: 1px solid var(--border-md); color: var(--text-2);
            font-family: var(--font); font-size: 13px; font-weight: 600;
            cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: all var(--t) var(--ease);
        }
        .btn-submit-outline:hover { border-color: var(--border-red); color: var(--red); }
        .btn-submit:disabled { opacity: .45; cursor: not-allowed; }
        .btn-link-sm {
            width: 100%; margin-top: 10px; padding: 6px; background: none; border: none;
            color: var(--text-3); font-family: var(--font); font-size: 12px; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        .btn-link-sm:hover { color: var(--text-2); }
        /* "Punição avulsa": botão de verdade, só mais discreto que o
           "Aplicar punição" que fica logo acima — mesma largura e altura,
           fundo do painel em vez de vermelho. Os dois são ações; a diferença
           é que esta é a de exceção, e a cor diz isso sem esconder o botão. */
        .btn-avulsa {
            width: 100%; margin-top: 8px; padding: 10px; border-radius: var(--radius-sm);
            background: var(--panel-2); border: 1px solid var(--border); color: var(--text-2);
            font-family: var(--font); font-size: 12.5px; font-weight: 600;
            cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: all var(--t) var(--ease);
        }
        .btn-avulsa:hover { border-color: var(--border-md); color: var(--text); background: var(--panel-3); }
        .btn-avulsa:focus-visible { outline: 2px solid var(--red); outline-offset: 2px; }
        /* Aberto, o botão fica marcado: senão, depois de clicar, nada na tela
           liga ele ao painel que apareceu embaixo. */
        .btn-avulsa.aberto { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }
        .lbl-hint { color: var(--text-3); font-weight: 500; font-size: 11px; }
        .avulsa-nota { color: var(--text-3); font-size: 12px; line-height: 1.5; margin: 0 0 14px; }

        /* A PRÉVIA: o degrau do edital e o que a pena vai fazer.
           O admin confirma sabendo o resultado — antes ele escolhia uma
           consequência de uma lista solta e descobria o efeito depois. */
        .previa {
            background: rgba(245,158,11,.07);
            border: 1px solid rgba(245,158,11,.28);
            border-radius: var(--radius-sm);
            padding: 12px 14px; margin-bottom: 16px;
        }
        .previa-degrau {
            font-size: 11px; font-weight: 800; letter-spacing: .05em;
            text-transform: uppercase; color: #f59e0b; margin-bottom: 6px;
            display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
        }
        .previa-efeito {
            display: flex; align-items: flex-start; gap: 7px;
            font-size: 13px; color: var(--text); line-height: 1.45; margin-top: 7px;
        }
        .previa-efeito i { color: #f59e0b; font-size: 11px; margin-top: 4px; flex-shrink: 0; }
        .previa-efeito small { display: block; color: var(--text-3); font-weight: 500; }
        .previa-aviso {
            margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(245,158,11,.2);
            font-size: 12px; color: var(--text-2); line-height: 1.5;
        }
        .previa-nada { font-size: 13px; color: var(--text-2); }
        /* A escada do quadro: uma linha por ocorrência, com o degrau do time
           marcado. Tabela e não cards — é uma lista de pares curtos, e caixa
           em volta de cada linha esconderia a progressão, que é o que importa
           ler aqui. */
        .escada { margin-top: 10px; padding-top: 10px; border-top: 1px dashed var(--border-md); }
        .escada-h {
            font-size: 9.5px; font-weight: 800; letter-spacing: .09em; text-transform: uppercase;
            color: var(--text-3); margin-bottom: 6px;
        }
        .escada-l {
            display: flex; gap: 8px; align-items: baseline; padding: 3px 0;
            font-size: 12px; color: var(--text-2); line-height: 1.35;
        }
        .escada-oc {
            flex-shrink: 0; min-width: 46px; font-weight: 800; font-size: 10.5px;
            color: var(--text-3); text-transform: uppercase; letter-spacing: .03em;
        }
        /* O degrau que vale agora: é a única linha que muda de cor, senão a
           marcação some no meio de quatro linhas iguais. */
        .escada-l.aqui { color: var(--text); font-weight: 600; }
        .escada-l.aqui .escada-oc { color: #f59e0b; }
        /* O check "já cumpriu": caixa e texto na mesma linha clicável, com a
           explicação embaixo — sem ela ninguém sabe se marcar pune ou não. */
        .chk-cumprida {
            display: flex; align-items: flex-start; gap: 9px; margin-bottom: 14px;
            padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm);
            background: var(--panel-2); cursor: pointer;
        }
        .chk-cumprida:hover { border-color: var(--border-md); }
        .chk-cumprida input { margin-top: 2px; flex-shrink: 0; width: 15px; height: 15px; accent-color: #f59e0b; }
        .chk-cumprida span { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.3; }
        .chk-cumprida small { display: block; margin-top: 2px; font-size: 11px; font-weight: 500; color: var(--text-3); }
        .chk-cumprida:has(input:checked) { border-color: #f59e0b; background: rgba(245,158,11,.08); }

        /* Quem está cumprindo alguma coisa agora */
        .ativa-item {
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
            background: var(--panel-2); border: 1px solid var(--border);
            border-left: 3px solid #dc2626;
            border-radius: var(--radius-sm); padding: 11px 14px; margin-bottom: 8px;
        }
        .ativa-time { font-weight: 700; font-size: 13px; color: var(--text); min-width: 150px; }
        .ativa-tags { display: flex; gap: 6px; flex-wrap: wrap; flex: 1; }
        .ativa-tag {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 999px;
            background: rgba(220,38,38,.14); color: #f87171; border: 1px solid rgba(220,38,38,.35);
        }
        .ativa-tag small { font-weight: 500; opacity: .8; }

        /* Punishment card */
        .pun-item {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
            margin-bottom: 10px;
            transition: border-color var(--t) var(--ease);
        }
        .pun-item:last-child { margin-bottom: 0; }
        .pun-item:hover { border-color: var(--border-md); }
        .pun-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 999px;
            font-size: 10px; font-weight: 700; letter-spacing: .5px;
            background: var(--red-soft); border: 1px solid var(--border-red); color: var(--red);
        }
        .pun-badge-off { background: var(--panel-3); border: 1px solid var(--border); color: var(--text-3); }

        /* O BOTÃO REVERTER.
           Ele usava .btn-ghost, que só existe no CSS do admin.php — resto da
           época em que esta tela tinha uma cópia lá. Com a cópia removida, o
           botão ficava sem regra nenhuma e o navegador desenhava o botão
           cinza padrão dele no meio do card. Classe própria, definida aqui,
           na mesma altura do selo "Ativa" que fica do lado.
           Discreto de propósito: reverter é ação de exceção, e botão vermelho
           forte ao lado de cada punição convida ao clique errado. */
        .btn-reverter {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 11px; border-radius: 999px;
            background: transparent; border: 1px solid var(--border); color: var(--text-3);
            font-family: var(--font); font-size: 10.5px; font-weight: 700; letter-spacing: .3px;
            cursor: pointer; transition: all var(--t) var(--ease);
        }
        .btn-reverter:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }
        .btn-reverter:focus-visible { outline: 2px solid var(--red); outline-offset: 2px; }

        /* Mesmo caso do .btn-reverter: o js escreve .empty-state nas listas
           vazias ("Nenhuma punição registrada") e a regra também tinha
           ficado só no admin.php. Sem ela o texto saía encostado à esquerda,
           com a margem padrão do <p>. */
        .empty-state { text-align: center; color: var(--text-2); padding: 32px 0; margin: 0; font-size: 14px; }

        /* ── Card de punição (remodelado) ───────────────── */
        .pun-v2 { display:flex; overflow:hidden; background:var(--panel-2); border:1px solid var(--border);
            border-radius:12px; margin-bottom:10px; transition:border-color var(--t) var(--ease), box-shadow var(--t) var(--ease); }
        .pun-v2:last-child { margin-bottom:0; }
        .pun-v2:hover { border-color:var(--border-md); box-shadow:0 4px 16px -10px rgba(0,0,0,.6); }
        .pun-v2-bar { width:4px; background:var(--red); flex-shrink:0; }
        .pun-v2.is-reverted { opacity:.6; }
        .pun-v2.is-reverted .pun-v2-bar { background:var(--text-3); }
        .pun-v2-body { flex:1; min-width:0; padding:13px 15px; }
        .pun-v2-top { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
        .pun-v2-team { display:flex; align-items:center; gap:9px; min-width:0; }
        .pun-v2-logo { width:32px; height:32px; border-radius:9px; background:var(--panel-3); border:1px solid var(--border-md);
            display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; color:var(--red); flex-shrink:0; }
        .pun-v2-teamname { display:block; font-size:13.5px; font-weight:600; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .pun-v2-league { display:block; font-size:10px; font-weight:700; color:var(--text-3); letter-spacing:.5px; }
        .pun-v2-actions { display:flex; align-items:center; gap:8px; flex-shrink:0; }
        .pun-v2-chips { display:flex; gap:6px; flex-wrap:wrap; margin-top:10px; }
        .pun-chip { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; padding:3px 9px;
            border-radius:999px; background:var(--panel-3); border:1px solid var(--border); color:var(--text-2); }
        .pun-chip.type { background:color-mix(in srgb, var(--red) 12%, transparent); border-color:var(--border-red); color:var(--red); }
        .pun-v2-motive { font-size:12.5px; color:var(--text-2); margin-top:9px; line-height:1.45; }
        .pun-v2-date { font-size:10.5px; color:var(--text-3); margin-top:9px; display:flex; align-items:center; gap:5px; }
        @media (max-width: 640px) {
            .pun-v2-top { flex-direction:column; gap:8px; }
            .pun-v2-actions { width:100%; }
        }

        /* Bootstrap compat */
        .bg-dark-panel { background: var(--panel-2) !important; }
        .border-orange { border-color: var(--border-red) !important; }
        .text-orange { color: var(--red) !important; }
        .text-light-gray { color: var(--text-2) !important; }
        .btn-orange { background: var(--red); border-color: var(--red); color: #fff; font-family: var(--font); }
        .btn-orange:hover { filter: brightness(1.1); color: #fff; }
        .btn-outline-orange { border-color: var(--red); color: var(--red); font-family: var(--font); }
        .btn-outline-orange:hover { background: var(--red-soft); color: var(--red); }
        .bg-gradient-orange, .badge.bg-orange { background: var(--red) !important; }

        /* Responsive */
        @media (max-width: 991px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform: translateX(-260px); }
            .sidebar.open { transform: translateX(0); }
            .topbar { display: flex; }
            .main { margin-left: 0; width: 100%; padding-top: 54px; }
            .page-hero { padding: 20px 16px 16px; }
            .content { padding: 16px 16px 40px; }
        }
    <?php include __DIR__ . '/includes/accent-color.php'; ?>
    </style>
</head>
<body>

<div class="app">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="sb-overlay" id="sbOverlay"></div>

    <header class="topbar">
        <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
        <div class="topbar-title">FBA <em>Manager</em></div>
        <span style="font-size:11px;font-weight:700;color:var(--red)"><?= $seasonDisplayYear ?></span>
    </header>

    <main class="main">
        <div class="page-hero" style="display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:10px">
            <div>
                <div class="page-eyebrow">Admin · <?= htmlspecialchars($user['league']) ?></div>
                <h1 class="page-title"><i class="bi bi-exclamation-triangle-fill"></i> Punições</h1>
            </div>
            <button type="button" class="btn btn-outline-danger btn-sm" id="btnZerarPunicoesAvisos">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Zerar punições e avisos da liga
            </button>
        </div>

        <div class="content">
            <div class="row g-4">

                <!-- Coluna esquerda: formulários -->
                <div class="col-lg-4">

                    <!-- Aplicar punição, pelo quadro do edital -->
                    <div class="panel mb-3">
                        <div class="panel-head">
                            <span class="panel-head-title"><i class="bi bi-plus-circle-fill"></i> Aplicar punição</span>
                        </div>
                        <div class="panel-body">
                            <div class="mb-3">
                                <label class="form-label">Liga</label>
                                <select id="punicaoLeague" class="form-select"></select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Time</label>
                                <select id="punicaoTeam" class="form-select"></select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Infração <span class="lbl-hint">(quadro do edital)</span></label>
                                <select id="punicaoInfracao" class="form-select"></select>
                            </div>

                            <!-- A prévia: em que degrau o time está e o que a pena faz -->
                            <div id="punicaoPrevia" class="previa" style="display:none"></div>

                            <div class="mb-3">
                                <label class="form-label">Observações <span class="lbl-hint">(opcional)</span></label>
                                <textarea id="punicaoNotes" class="form-control" rows="2" placeholder="O que aconteceu, print, contexto..."></textarea>
                            </div>
                            <?php /* JÁ CUMPRIU: registra sem cobrar. É pra quando o GM já pagou
                                     a pena fora do sistema — o admin aplicou na mão, ou virou
                                     acordo no grupo. Nasce DESMARCADO: punição nova pune. */ ?>
                            <label class="chk-cumprida">
                                <input type="checkbox" id="punicaoJaCumprida">
                                <span>Já cumpriu<small>Fica registrada e conta pra reincidência, mas não pune o GM de novo</small></span>
                            </label>
                            <button id="punicaoSubmit" class="btn-submit" disabled>
                                <i class="bi bi-check2-circle"></i> Aplicar punição
                            </button>
                            <?php /* Botão, e não link: abrir a avulsa é uma ação
                                     do mesmo peso que aplicar pelo quadro — só é
                                     a menos usada. Como texto solto embaixo do
                                     botão vermelho passava por rodapé, e quem
                                     precisava do caso omisso não achava. */ ?>
                            <button type="button" id="punicaoAvancado" class="btn-avulsa" aria-expanded="false" aria-controls="painelAvulsa">
                                <i class="bi bi-sliders"></i> Punição avulsa (fora do quadro)
                            </button>
                        </div>
                    </div>

                    <!-- Punição avulsa: o caminho antigo, agora escondido -->
                    <div class="panel mb-3" id="painelAvulsa" style="display:none">
                        <div class="panel-head">
                            <span class="panel-head-title"><i class="bi bi-sliders"></i> Punição avulsa</span>
                        </div>
                        <div class="panel-body">
                            <p class="avulsa-nota">
                                Para o que o quadro não prevê — o edital chama de caso omisso.
                                A consequência é escolhida na mão.
                            </p>
                            <div class="mb-3">
                                <label class="form-label">Motivo</label>
                                <select id="punicaoMotive" class="form-select"></select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Consequência</label>
                                <select id="punicaoType" class="form-select"></select>
                            </div>
                            <div class="mb-3" id="punicaoPickRow" style="display:none;">
                                <label class="form-label">Pick específica</label>
                                <select id="punicaoPick" class="form-select"></select>
                            </div>
                            <div class="mb-3" id="punicaoScopeRow" style="display:none;">
                                <label class="form-label">Por quanto tempo</label>
                                <select id="punicaoDuracao" class="form-select"></select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Data da punição (manual)</label>
                                <input type="datetime-local" id="punicaoDate" class="form-control" />
                            </div>
                            <?php /* O mesmo check do quadro. Duas caixas e não uma compartilhada:
                                     os dois painéis abrem juntos, e uma caixa só faria o admin
                                     marcar aqui e aplicar lá em cima sem perceber. */ ?>
                            <label class="chk-cumprida">
                                <input type="checkbox" id="punicaoJaCumpridaAvulsa">
                                <span>Já cumpriu<small>Só registra — não perde pick, não bloqueia trade nem FA</small></span>
                            </label>
                            <button id="punicaoSubmitAvulsa" class="btn-submit-outline">
                                <i class="bi bi-check2-circle"></i> Registrar avulsa
                            </button>
                        </div>
                    </div>


                </div>

                <!-- Coluna direita: o que está pegando, e o histórico -->
                <div class="col-lg-8">
                    <div class="panel mb-3">
                        <div class="panel-head">
                            <span class="panel-head-title"><i class="bi bi-exclamation-octagon-fill"></i> Cumprindo punição agora</span>
                        </div>
                        <div class="panel-body">
                            <div id="punicoesAtivas" style="color:var(--text-2);font-size:13px">Escolha uma liga.</div>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-head" style="justify-content:space-between; flex-wrap:wrap; gap:10px;">
                            <span class="panel-head-title"><i class="bi bi-clock-history"></i> Histórico de punições</span>
                            <div class="d-flex gap-2 flex-wrap">
                                <select id="punicaoHistoryLeague" class="form-select form-select-sm" style="width:auto;min-width:120px">
                                    <option value="">Todas as ligas</option>
                                </select>
                                <select id="punicaoHistoryTeam" class="form-select form-select-sm" style="width:auto;min-width:140px">
                                    <option value="">Todos os times</option>
                                </select>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div id="punicoesList" style="color:var(--text-2);font-size:13px">
                                Selecione um time para ver as punições.
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

</div><!-- /.app -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Sidebar toggle
    (function () {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sbOverlay');
        const menuBtn = document.getElementById('menuBtn');
        if (!sidebar) return;
        const close = () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); };
        if (menuBtn) menuBtn.addEventListener('click', () => { const open = sidebar.classList.toggle('open'); overlay.classList.toggle('show', open); });
        if (overlay) overlay.addEventListener('click', close);
        document.querySelectorAll('.sb-nav a').forEach(a => a.addEventListener('click', close));
    })();
</script>
<script src="<?= assetUrl('/js/punicoes.js') ?>"></script>
</body>
</html>
