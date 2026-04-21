<?php
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/auth.php';

$token = $_GET['token'] ?? null;
if (!$token) {
    http_response_code(403);
    echo 'Token inválido.';
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Draft Inicial — Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* ── Tokens ──────────────────────────────────── */
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
            --green:      #22c55e;
            --amber:      #f59e0b;
            --blue:       #3b82f6;
            --font:       'Poppins', sans-serif;
            --radius:     14px;
            --radius-sm:  10px;
            --ease:       cubic-bezier(.2,.8,.2,1);
            --t:          200ms;
        }

        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }

        /* ── Layout ──────────────────────────────────── */
        .app-wrap { max-width: 1280px; margin: 0 auto; padding: 24px 20px 48px; }

        /* ── Topbar ──────────────────────────────────── */
        .app-topbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 14px; flex-wrap: wrap;
            padding: 16px 20px;
            background: var(--panel);
            border-bottom: 1px solid var(--border);
            margin-bottom: 24px;
        }
        .app-topbar-left { display: flex; align-items: center; gap: 12px; }
        .app-logo { width: 32px; height: 32px; border-radius: 8px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px; color: #fff; flex-shrink: 0; }
        .app-title { font-size: 15px; font-weight: 700; line-height: 1.1; }
        .app-title span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }

        .token-display {
            display: flex; align-items: center; gap: 6px;
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: 8px; padding: 5px 10px;
        }
        .token-display code { font-size: 11px; color: var(--text-3); max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .token-copy-btn {
            background: transparent; border: none; color: var(--text-3);
            cursor: pointer; font-size: 12px; padding: 0;
            transition: color var(--t) var(--ease);
        }
        .token-copy-btn:hover { color: var(--red); }

        /* ── Feedback ────────────────────────────────── */
        #feedback { margin-bottom: 16px; }
        .fb-alert {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            padding: 12px 16px; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 500;
        }
        .fb-alert.success { background: rgba(34,197,94,.10); border: 1px solid rgba(34,197,94,.2); color: var(--green); }
        .fb-alert.danger  { background: rgba(239,68,68,.10);  border: 1px solid rgba(239,68,68,.2);  color: #ef4444; }
        .fb-alert.warning { background: rgba(245,158,11,.10); border: 1px solid rgba(245,158,11,.2); color: var(--amber); }
        .fb-alert.info    { background: rgba(59,130,246,.10); border: 1px solid rgba(59,130,246,.2); color: var(--blue); }
        .fb-close { background: none; border: none; color: inherit; cursor: pointer; font-size: 15px; opacity: .7; }
        .fb-close:hover { opacity: 1; }

        /* ── Hero ────────────────────────────────────── */
        .hero {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 22px 28px;
            margin-bottom: 16px;
        }
        .hero-eyebrow { font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--red); margin-bottom: 6px; }
        .hero-title { font-size: clamp(1.3rem, 2vw, 1.75rem); font-weight: 800; margin-bottom: 4px; }
        .hero-sub { font-size: 13px; color: var(--text-2); }

        /* ── Stat grid ───────────────────────────────── */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 10px;
            margin-top: 18px;
        }
        .stat-card {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
        }
        .stat-label { font-size: 10px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: var(--text-2); margin-bottom: 6px; }
        .stat-value { font-size: 1.25rem; font-weight: 700; }

        /* ── Session card ────────────────────────────── */
        .session-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 22px;
            margin-bottom: 20px;
        }
        .session-title { font-size: 13px; font-weight: 700; margin-bottom: 3px; }

        /* ── Progress ────────────────────────────────── */
        .prog-bar-wrap { height: 6px; background: var(--panel-3); border-radius: 999px; overflow: hidden; margin-top: 12px; }
        .prog-bar-fill { height: 100%; background: linear-gradient(90deg, var(--red), #ff2a44); border-radius: 999px; transition: width .5s ease; }

        /* ── Panel card ──────────────────────────────── */
        .panel-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            height: 100%;
        }
        .panel-card-head {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
        }
        .panel-card-title { font-size: 14px; font-weight: 700; }
        .panel-card-body { padding: 20px; }

        /* ── Order list ──────────────────────────────── */
        .order-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 10px 12px;
            margin-bottom: 6px;
        }
        .order-rank {
            width: 30px; height: 30px;
            border-radius: 50%;
            background: var(--red-soft);
            border: 1px solid var(--border-red);
            display: grid; place-items: center;
            font-weight: 700; font-size: 13px; color: var(--red);
            flex-shrink: 0;
        }
        .team-chip { display: flex; align-items: center; gap: 10px; }
        .team-chip img { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-md); flex-shrink: 0; }
        .team-chip-name { font-size: 13px; font-weight: 600; line-height: 1.2; }
        .team-chip-gm { font-size: 11px; color: var(--text-2); }

        .order-actions { display: flex; gap: 4px; flex-shrink: 0; }
        .order-btn {
            width: 28px; height: 28px; border-radius: 7px;
            background: transparent; border: 1px solid var(--border);
            color: var(--text-2); display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 12px; transition: all var(--t) var(--ease);
        }
        .order-btn:hover { border-color: var(--border-md); color: var(--text); }
        .order-btn:disabled { opacity: .3; cursor: not-allowed; }

        /* ── Tabs ────────────────────────────────────── */
        .custom-tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--border); margin-bottom: 18px; }
        .custom-tab {
            padding: 9px 14px; font-size: 13px; font-weight: 600;
            color: var(--text-2); background: transparent; border: none;
            border-bottom: 2px solid transparent; margin-bottom: -1px;
            cursor: pointer; transition: all var(--t) var(--ease);
            font-family: var(--font);
        }
        .custom-tab:hover { color: var(--text); }
        .custom-tab.active { color: var(--red); border-bottom-color: var(--red); }

        /* ── Table ───────────────────────────────────── */
        .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .data-table th {
            font-size: 10px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase;
            color: var(--text-3); padding: 10px 12px; border-bottom: 1px solid var(--border);
            text-align: left; white-space: nowrap;
        }
        .data-table th.sortable { cursor: pointer; user-select: none; }
        .data-table th.sortable:hover { color: var(--text-2); }
        .data-table th.sortable.active { color: var(--text); }
        .data-table th.sortable .sort-indicator { margin-left: 4px; font-size: .8em; }
        .data-table td { padding: 10px 12px; border-bottom: 1px solid var(--border); color: var(--text-2); vertical-align: middle; }
        .data-table td.td-name { font-weight: 600; color: var(--text); }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table tbody tr:hover td { background: var(--panel-2); }
        .data-table-wrap { background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; }

        /* ── Status pills ────────────────────────────── */
        .status-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
        .status-pill.setup      { background: rgba(245,158,11,.12); color: var(--amber); border: 1px solid rgba(245,158,11,.25); }
        .status-pill.in_progress{ background: rgba(34,197,94,.12);  color: var(--green); border: 1px solid rgba(34,197,94,.25); }
        .status-pill.completed  { background: var(--panel-3); color: var(--text-2); border: 1px solid var(--border); }

        .badge-available { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; background: rgba(34,197,94,.10); color: var(--green); border: 1px solid rgba(34,197,94,.2); }
        .badge-drafted   { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; background: var(--panel-3); color: var(--text-3); border: 1px solid var(--border); }

        /* ── Buttons ─────────────────────────────────── */
        .btn-red {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; border-radius: 9px;
            background: var(--red); border: none; color: #fff;
            font-family: var(--font); font-size: 13px; font-weight: 600;
            cursor: pointer; transition: filter var(--t) var(--ease);
            text-decoration: none;
        }
        .btn-red:hover { filter: brightness(1.1); color: #fff; }
        .btn-red:disabled { opacity: .5; cursor: not-allowed; }

        .btn-ghost {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; border-radius: 9px;
            background: transparent; border: 1px solid var(--border-md); color: var(--text-2);
            font-family: var(--font); font-size: 13px; font-weight: 600;
            cursor: pointer; transition: all var(--t) var(--ease);
            text-decoration: none;
        }
        .btn-ghost:hover { border-color: var(--border-red); color: var(--red); background: var(--red-soft); }
        .btn-ghost:disabled { opacity: .4; cursor: not-allowed; }

        .btn-amber {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; border-radius: 9px;
            background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.3); color: var(--amber);
            font-family: var(--font); font-size: 13px; font-weight: 600;
            cursor: pointer; transition: all var(--t) var(--ease);
        }
        .btn-amber:hover { background: rgba(245,158,11,.2); }
        .btn-amber:disabled { opacity: .4; cursor: not-allowed; }

        .btn-green {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; border-radius: 9px;
            background: var(--green); border: none; color: #fff;
            font-family: var(--font); font-size: 13px; font-weight: 600;
            cursor: pointer; transition: filter var(--t) var(--ease);
        }
        .btn-green:hover { filter: brightness(1.1); }

        .btn-sm-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px; border-radius: 7px;
            background: transparent; border: 1px solid var(--border);
            color: var(--text-2); font-size: 12px; cursor: pointer;
            transition: all var(--t) var(--ease);
        }
        .btn-sm-icon:hover { border-color: var(--border-md); color: var(--text); }
        .btn-sm-icon.danger:hover { border-color: rgba(239,68,68,.4); color: #ef4444; background: rgba(239,68,68,.08); }
        .btn-sm-icon.amber:hover { border-color: rgba(245,158,11,.4); color: var(--amber); background: rgba(245,158,11,.08); }

        /* ── Search input ────────────────────────────── */
        .search-input {
            width: 100%;
            background: var(--panel-2); border: 1px solid var(--border-md);
            border-radius: 9px; padding: 9px 12px;
            color: var(--text); font-family: var(--font); font-size: 13px;
            outline: none; transition: border-color var(--t) var(--ease);
        }
        .search-input:focus { border-color: var(--red); }
        .search-input::placeholder { color: var(--text-3); }

        /* ── Form fields ─────────────────────────────── */
        .field-label { font-size: 12px; font-weight: 600; color: var(--text-2); margin-bottom: 5px; display: block; }
        .field-input {
            width: 100%;
            background: var(--panel-2); border: 1px solid var(--border-md);
            border-radius: 8px; padding: 9px 12px;
            color: var(--text); font-family: var(--font); font-size: 13px;
            outline: none; transition: border-color var(--t) var(--ease);
        }
        .field-input:focus { border-color: var(--red); }
        .field-input::placeholder { color: var(--text-3); }

        /* ── Lottery ─────────────────────────────────── */
        .lottery-stage {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 16px;
            min-height: 100px;
            margin-bottom: 14px;
        }
        .lottery-track { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; align-items: center; min-height: 56px; }
        .lottery-ball {
            width: 46px; height: 46px; border-radius: 50%;
            background: var(--panel-3); border: 1px solid var(--border-md);
            display: grid; place-items: center;
            transition: transform 200ms ease, border-color 200ms ease;
            animation: floatBall 3s ease-in-out infinite;
        }
        .lottery-ball img { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; }
        .lottery-ball.active { transform: scale(1.15); border-color: var(--border-red); background: var(--red-soft); }

        @keyframes floatBall {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-4px); }
        }

        .lottery-results { display: grid; gap: 6px; }
        .lottery-result {
            display: flex; align-items: center; gap: 10px;
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 8px 12px;
        }
        .lottery-result img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
        .lottery-rank {
            width: 28px; height: 28px; border-radius: 50%;
            background: var(--red-soft); border: 1px solid var(--border-red);
            display: grid; place-items: center;
            font-weight: 700; font-size: 12px; color: var(--red); flex-shrink: 0;
        }

        /* ── Manual order row ────────────────────────── */
        .manual-order-row {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 10px; align-items: center;
            background: var(--panel-2); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 8px 12px;
            margin-bottom: 6px;
        }
        .manual-position-select { width: 68px; }

        /* ── Order mode btns ─────────────────────────── */
        .order-mode-active { background: var(--red-soft) !important; border-color: var(--border-red) !important; color: var(--red) !important; }

        /* ── Pagination ──────────────────────────────── */
        #poolPagination .pagination { margin: 0; }
        #poolPagination .page-link { background: var(--panel-2); border-color: var(--border); color: var(--text-2); font-family: var(--font); font-size: 12px; }
        #poolPagination .page-link:hover { background: var(--panel-3); color: var(--text); }
        #poolPagination .page-item.active .page-link { background: var(--red); border-color: var(--red); color: #fff; }
        #poolPagination .page-item.disabled .page-link { opacity: .4; }

        /* ── Modal overrides ─────────────────────────── */
        .modal-content { background: var(--panel); border: 1px solid var(--border-md); border-radius: var(--radius); color: var(--text); font-family: var(--font); }
        .modal-header { border-bottom: 1px solid var(--border); padding: 18px 20px; }
        .modal-header .modal-title { font-size: 14px; font-weight: 700; }
        .modal-body { padding: 20px; }
        .modal-footer { border-top: 1px solid var(--border); padding: 14px 20px; gap: 8px; }

        /* ── Rounds section ──────────────────────────── */
        .round-section { margin-bottom: 20px; }
        .round-section-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .round-section-title { font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--text-2); }

        /* ── Empty state ─────────────────────────────── */
        .state-empty { padding: 28px 16px; text-align: center; color: var(--text-3); font-size: 13px; }

        /* ── Back link ───────────────────────────────── */
        .back-link { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: var(--text-2); text-decoration: none; transition: color var(--t) var(--ease); }
        .back-link:hover { color: var(--red); }

        /* ── Bootstrap overrides ─────────────────────── */
        .form-control, .form-select {
            background: var(--panel-2); border-color: var(--border-md);
            color: var(--text); font-family: var(--font);
        }
        .form-control:focus, .form-select:focus {
            background: var(--panel-2); border-color: var(--red);
            color: var(--text); box-shadow: none;
        }
        .form-control::placeholder { color: var(--text-3); }
        .form-label { color: var(--text-2); font-size: 12px; font-weight: 600; }
        .form-select option { background: var(--panel-2); }
        .form-check-input { background-color: var(--panel-3); border-color: var(--border-md); }
        .form-check-input:checked { background-color: var(--red); border-color: var(--red); }
        .nav-tabs { border-bottom: 1px solid var(--border); }
        .nav-tabs .nav-link { color: var(--text-2); border: none; border-bottom: 2px solid transparent; font-family: var(--font); font-size: 13px; font-weight: 600; }
        .nav-tabs .nav-link.active { color: var(--red); border-bottom-color: var(--red); background: transparent; }
        .nav-tabs .nav-link:hover { color: var(--text); }

        /* ── Responsive ──────────────────────────────── */
        @media (max-width: 768px) {
            .app-wrap { padding: 16px 14px 40px; }
            .hero { padding: 18px 20px; }
            .manual-order-row { grid-template-columns: 1fr; }
            .manual-position-select { width: 100%; }
        }
    </style>
</head>
<body>

<!-- Topbar -->
<div class="app-topbar">
    <div class="app-topbar-left">
        <div class="app-logo">FBA</div>
        <div class="app-title">Draft Inicial <span>Painel do Admin</span></div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <div class="token-display">
            <i class="bi bi-key" style="font-size:12px;color:var(--text-3)"></i>
            <code id="tokenDisplay"></code>
            <button class="token-copy-btn" onclick="copyToken()" title="Copiar token"><i class="bi bi-clipboard"></i></button>
        </div>
        <a href="dashboard.php" class="back-link"><i class="bi bi-arrow-left"></i> Dashboard</a>
    </div>
</div>

<div class="app-wrap">

    <div id="feedback"></div>

    <!-- Hero -->
    <section class="hero" id="heroSection">
        <div class="hero-eyebrow">Painel do Admin</div>
        <h1 class="hero-title">Draft Inicial</h1>
        <p class="hero-sub">Configure a ordem, acompanhe as rodadas e registre cada pick.</p>
        <div class="stat-grid" id="statGrid"></div>
    </section>

    <!-- Session status -->
    <div class="session-card">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-3 align-items-start">
            <div>
                <div class="session-title">Status da Sessão</div>
                <div id="sessionSummary" style="font-size:13px;color:var(--text-2)"></div>
            </div>
            <div class="d-flex flex-wrap gap-2" id="actionButtons"></div>
        </div>
        <div style="margin-top:14px">
            <div class="d-flex justify-content-between mb-1" style="font-size:12px;color:var(--text-2)">
                <span id="progressLabel"></span>
                <span id="progressPercent"></span>
            </div>
            <div class="prog-bar-wrap">
                <div class="prog-bar-fill" id="progressBar" style="width:0%"></div>
            </div>
        </div>
    </div>

    <!-- Main grid -->
    <div class="row g-4">
        <!-- Order column -->
        <div class="col-lg-5">
            <div class="panel-card">
                <div class="panel-card-head">
                    <div>
                        <div class="panel-card-title">Ordem da 1ª Rodada</div>
                        <div id="orderEditHint" style="font-size:11px;color:var(--text-2);margin-top:2px"></div>
                    </div>
                    <button class="btn-ghost d-none" id="orderEditButton" onclick="openOrderModal()" style="padding:6px 12px;font-size:12px">
                        <i class="bi bi-sliders"></i> Editar
                    </button>
                </div>
                <div class="panel-card-body" id="orderList" style="min-height:80px">
                    <div class="state-empty">Carregando…</div>
                </div>
            </div>
        </div>

        <!-- Content column: tabs -->
        <div class="col-lg-7">
            <div class="panel-card">
                <div class="panel-card-head">
                    <ul class="nav nav-tabs border-0 mb-0" id="contentTabs" role="tablist" style="flex:1">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="players-tab" data-bs-toggle="tab" data-bs-target="#players" type="button" role="tab">Jogadores</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="rounds-tab" data-bs-toggle="tab" data-bs-target="#rounds-pane" type="button" role="tab">Rodadas</button>
                        </li>
                    </ul>
                </div>

                <div class="panel-card-body">
                    <div class="tab-content">

                        <!-- Players tab -->
                        <div class="tab-pane fade show active" id="players" role="tabpanel">
                            <div class="d-flex flex-column flex-md-row justify-content-between gap-2 align-items-start mb-3">
                                <div>
                                    <div style="font-size:14px;font-weight:700">Jogadores do Pool</div>
                                    <div style="font-size:11px;color:var(--text-2);margin-top:2px">Importe via CSV ou adicione manualmente.</div>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn-amber" style="padding:7px 12px;font-size:12px" data-bs-toggle="modal" data-bs-target="#importCSVModal">
                                        <i class="bi bi-file-earmark-arrow-up"></i> Importar CSV
                                    </button>
                                    <button class="btn-red" style="padding:7px 12px;font-size:12px" data-bs-toggle="modal" data-bs-target="#addPlayerModal">
                                        <i class="bi bi-person-plus"></i> Novo Jogador
                                    </button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <input type="text" id="poolSearch" class="search-input" placeholder="Filtrar por nome ou posição…">
                            </div>
                            <div class="data-table-wrap" id="poolWrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Jogador</th>
                                            <th>Posição</th>
                                            <th>OVR</th>
                                            <th>Idade</th>
                                            <th>Status</th>
                                            <th style="text-align:right">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody id="poolTable"></tbody>
                                </table>
                            </div>
                            <div id="poolPagination" class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2"></div>
                        </div>

                        <!-- Rounds tab -->
                        <div class="tab-pane fade" id="rounds-pane" role="tabpanel">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                <div style="font-size:14px;font-weight:700">Rodadas e Picks</div>
                                <span style="font-size:11px;color:var(--text-2)" id="roundsMeta"></span>
                            </div>
                            <div id="rounds"></div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

</div><!-- .app-wrap -->

<!-- ══════ Modais ══════ -->

<!-- Order Modal -->
<div class="modal fade" id="orderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Configurar Ordem do Draft</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:8px;padding:10px 14px;font-size:12px;color:var(--amber);margin-bottom:14px">
                    Utilize os botões para ajustar manualmente ou clique em "Sorteio" para gerar uma ordem aleatória. O formato snake será aplicado nas demais rodadas.
                </div>
                <div class="d-flex flex-column flex-md-row gap-2 mb-3">
                    <button class="btn-ghost flex-fill" type="button" id="orderModeManual" onclick="setOrderMode('manual')">
                        <i class="bi bi-list-ol"></i> Ordenar manualmente
                    </button>
                    <button class="btn-amber flex-fill" type="button" id="orderModeLottery" onclick="setOrderMode('lottery')">
                        <i class="bi bi-shuffle"></i> Sorteio (loteria)
                    </button>
                </div>
                <div id="lotterySection" class="d-none">
                    <div class="lottery-stage" id="lotteryStage">
                        <div class="text-center state-empty" id="lotteryPlaceholder">Clique em Sorteio para iniciar.</div>
                        <div class="lottery-track" id="lotteryTrack"></div>
                    </div>
                    <div class="lottery-results" id="lotteryResults"></div>
                </div>
                <div id="manualSection" class="d-none">
                    <div style="font-size:11px;color:var(--text-2);margin-bottom:10px">Defina a posição de cada time antes de aplicar.</div>
                    <div id="manualOrderList"></div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn-ghost" type="button" id="resetOrderButton" onclick="resetManualOrder()">
                        <i class="bi bi-arrow-counterclockwise"></i> Resetar
                    </button>
                    <button class="btn-amber" type="button" id="lotteryButton" onclick="randomizeOrder()">
                        <i class="bi bi-shuffle"></i> Iniciar sorteio
                    </button>
                </div>
                <button class="btn-green" type="button" id="applyOrderButton" onclick="submitManualOrder()">
                    <i class="bi bi-check2-circle"></i> Aplicar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add Player Modal -->
<div class="modal fade" id="addPlayerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="addPlayerForm">
                <div class="modal-header">
                    <h5 class="modal-title">Adicionar Jogador</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="field-label">Nome</label>
                            <input type="text" name="name" class="field-input" required placeholder="Nome completo">
                        </div>
                        <div class="col-sm-6">
                            <label class="field-label">Posição</label>
                            <select name="position" class="field-input">
                                <option value="PG">PG</option>
                                <option value="SG">SG</option>
                                <option value="SF" selected>SF</option>
                                <option value="PF">PF</option>
                                <option value="C">C</option>
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <label class="field-label">Idade</label>
                            <input type="number" name="age" min="16" max="45" class="field-input" required placeholder="22">
                        </div>
                        <div class="col-sm-3">
                            <label class="field-label">OVR</label>
                            <input type="number" name="ovr" min="40" max="99" class="field-input" required placeholder="75">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn-red">Adicionar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Player Modal -->
<div class="modal fade" id="editPlayerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="editPlayerForm">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Jogador</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="player_id" id="editPlayerId">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="field-label">Nome</label>
                            <input type="text" name="name" id="editPlayerName" class="field-input" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="field-label">Posição</label>
                            <select name="position" id="editPlayerPosition" class="field-input">
                                <option value="PG">PG</option>
                                <option value="SG">SG</option>
                                <option value="SF">SF</option>
                                <option value="PF">PF</option>
                                <option value="C">C</option>
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <label class="field-label">Idade</label>
                            <input type="number" name="age" id="editPlayerAge" min="16" max="45" class="field-input" required>
                        </div>
                        <div class="col-sm-3">
                            <label class="field-label">OVR</label>
                            <input type="number" name="ovr" id="editPlayerOvr" min="40" max="99" class="field-input" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn-red">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import CSV Modal -->
<div class="modal fade" id="importCSVModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="importCSVForm" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Importar Jogadores via CSV</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p style="font-size:12px;color:var(--text-2);margin-bottom:14px">Formato: <code style="color:var(--red)">name,position,age,ovr</code>. Use o template para evitar erros.</p>
                    <div class="mb-3">
                        <label class="field-label">Arquivo CSV</label>
                        <input type="file" name="csv_file" class="field-input" accept=".csv" required style="padding:6px 10px;cursor:pointer">
                    </div>
                    <button type="button" class="btn-ghost" style="padding:6px 12px;font-size:12px" onclick="downloadCSVTemplate()">
                        <i class="bi bi-download"></i> Baixar Template
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn-red">Importar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Daily Schedule Modal -->
<div class="modal fade" id="dailyScheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Agendamento (1 round por dia)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p style="font-size:12px;color:var(--text-2);margin-bottom:14px">00:01 libera o round do dia (Brasília). Sem relógio: as picks avançam somente quando alguém escolhe.</p>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="field-label">Dia 01 (DD/MM/AAAA)</label>
                        <input type="text" id="modalDailyScheduleStart" class="field-input" placeholder="dd/mm/aaaa">
                    </div>
                    <div class="col-sm-6">
                        <label class="field-label">Previsão de término</label>
                        <input type="text" class="field-input" id="modalDailyScheduleEnd" readonly style="opacity:.6">
                    </div>
                    <div class="col-12 d-flex justify-content-end">
                        <button class="btn-amber" id="modalSaveScheduleBtn" onclick="saveDailySchedule()">
                            <i class="bi bi-calendar-check"></i> Salvar agendamento
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const TOKEN = '<?php echo htmlspecialchars($token, ENT_QUOTES); ?>';
    const API_URL = 'api/initdraft.php';
    const LOTTERY_STORAGE_KEY = `initdraft_lottery_${TOKEN}`;
    const LOTTERY_BALL_COUNT = 30;

    const state = {
        session: null,
        order: [],
        teams: [],
        pool: [],
        manualOrder: [],
        search: '',
        poolPage: 1,
        poolPerPage: 15,
        lotteryDrawn: false,
        lotteryQueue: [],
        lotteryIndex: 0,
        orderMode: 'manual',
        canEditOrder: false,
    };

    const elements = {
        tokenDisplay: document.getElementById('tokenDisplay'),
        statGrid: document.getElementById('statGrid'),
        sessionSummary: document.getElementById('sessionSummary'),
        actionButtons: document.getElementById('actionButtons'),
        progressLabel: document.getElementById('progressLabel'),
        progressPercent: document.getElementById('progressPercent'),
        progressBar: document.getElementById('progressBar'),
        orderList: document.getElementById('orderList'),
        manualOrderList: document.getElementById('manualOrderList'),
        poolTable: document.getElementById('poolTable'),
        poolPagination: document.getElementById('poolPagination'),
        roundsContainer: document.getElementById('rounds'),
        roundsMeta: document.getElementById('roundsMeta'),
        feedback: document.getElementById('feedback'),
        lotteryStage: document.getElementById('lotteryStage'),
        lotteryTrack: document.getElementById('lotteryTrack'),
        lotteryResults: document.getElementById('lotteryResults'),
        lotteryButton: document.getElementById('lotteryButton'),
        lotterySection: document.getElementById('lotterySection'),
        manualSection: document.getElementById('manualSection'),
        orderModeManual: document.getElementById('orderModeManual'),
        orderModeLottery: document.getElementById('orderModeLottery'),
        lotteryPlaceholder: document.getElementById('lotteryPlaceholder'),
        applyOrderButton: document.getElementById('applyOrderButton'),
        orderEditButton: document.getElementById('orderEditButton'),
        orderEditHint: document.getElementById('orderEditHint'),
        resetOrderButton: document.getElementById('resetOrderButton'),
    };

    elements.tokenDisplay.textContent = TOKEN;
    state.lotteryDrawn = localStorage.getItem(LOTTERY_STORAGE_KEY) === '1';

    document.getElementById('poolSearch').addEventListener('input', (event) => {
        state.search = event.target.value.toLowerCase();
        state.poolPage = 1;
        renderPool();
    });

    document.getElementById('addPlayerForm').addEventListener('submit', handleAddPlayer);
    document.getElementById('editPlayerForm').addEventListener('submit', handleEditPlayer);
    document.getElementById('importCSVForm').addEventListener('submit', handleImportCSV);

    const orderModal = new bootstrap.Modal(document.getElementById('orderModal'));

    document.getElementById('orderModal').addEventListener('show.bs.modal', () => {
        renderManualOrderList();
        setOrderMode('select');
        resetLotteryView();
        updateLotteryButton();
    });

    function showMessage(message, type = 'success') {
        elements.feedback.innerHTML = `
            <div class="fb-alert ${type}" style="margin-bottom:12px">
                <span>${message}</span>
                <button class="fb-close" onclick="this.parentElement.remove()"><i class="bi bi-x"></i></button>
            </div>`;
        setTimeout(() => { const el = elements.feedback.firstChild; if (el) el.remove(); }, 5000);
    }

    function copyToken() {
        navigator.clipboard.writeText(TOKEN).then(() => showMessage('Token copiado para a área de transferência.'));
    }

    function shuffle(list) {
        const array = [...list];
        for (let i = array.length - 1; i > 0; i -= 1) {
            const j = Math.floor(Math.random() * (i + 1));
            [array[i], array[j]] = [array[j], array[i]];
        }
        return array;
    }

    function openDraftViewer() {
        window.open(`initdraftselecao.php?token=${encodeURIComponent(TOKEN)}`, '_blank');
    }

    async function loadState() {
        try {
            const [stateRes, poolRes] = await Promise.all([
                fetch(`${API_URL}?action=state&token=${TOKEN}`).then((r) => r.json()),
                fetch(`${API_URL}?action=pool&token=${TOKEN}`).then((r) => r.json()),
            ]);

            if (!stateRes.success) throw new Error(stateRes.error || 'Erro ao carregar sessão');
            state.session = stateRes.session;
            state.order = stateRes.order || [];
            state.teams = stateRes.teams || [];
            state.pool = poolRes.success ? poolRes.players : [];
            state.canEditOrder = !!stateRes.can_edit_order;
            state.manualOrder = getRoundOneOrder();
            if (state.order.length) {
                state.lotteryDrawn = true;
            }
            render();
            return true;
        } catch (error) {
            showMessage(error.message, 'danger');
            return false;
        }
    }

    function render() {
        renderStats();
        renderActions();
        updateOrderEditVisibility();
        renderOrder();
        renderPool();
        renderRounds();
        updateLotteryButton();
    }

    function updateOrderEditVisibility() {
        const canEdit = state.canEditOrder;
        elements.orderEditButton?.classList.toggle('d-none', !canEdit);
        if (elements.orderEditHint) {
            elements.orderEditHint.textContent = canEdit
                ? 'Edite manualmente ou utilize o sorteio animado.'
                : 'Ordem bloqueada após a primeira pick.';
        }
    }

    function setOrderMode(mode) {
        const isManual = mode === 'manual';
        const isLottery = mode === 'lottery';
        state.orderMode = mode;
        elements.manualSection?.classList.toggle('d-none', !isManual);
        elements.lotterySection?.classList.toggle('d-none', !isLottery);
        elements.orderModeManual?.classList.toggle('order-mode-active', isManual);
        elements.orderModeLottery?.classList.toggle('order-mode-active', isLottery);
        elements.lotteryButton?.classList.toggle('d-none', !isLottery);
        elements.resetOrderButton?.classList.toggle('d-none', !isManual);
        if (elements.applyOrderButton) {
            const hideApply = !isManual && (!isLottery || !state.lotteryDrawn);
            elements.applyOrderButton.classList.toggle('d-none', hideApply);
        }
        if (isManual) {
            renderManualOrderList();
        } else if (isLottery) {
            resetLotteryView();
        }
        updateLotteryButton();
    }

    function renderStats() {
        const session = state.session;
        if (!session) return;

        const order = state.order || [];
        const drafted = order.filter((pick) => pick.picked_player_id).length;
        const total = order.length || (session.total_rounds ?? 0) * (state.teams.length || 0);
        const progress = total ? Math.round((drafted / total) * 100) : 0;
        const nextPick = order.find((pick) => !pick.picked_player_id);
        const statusLabel = { setup: 'Configuração', in_progress: 'Em andamento', completed: 'Concluído' }[session.status] || 'Status';

        elements.statGrid.innerHTML = `
            <div class="stat-card">
                <p class="stat-label">Status</p>
                <div class="status-pill ${session.status}">${statusLabel}</div>
            </div>
            <div class="stat-card">
                <p class="stat-label">Rodada Atual</p>
                <p class="stat-value">${session.current_round ?? '—'}</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Próximo Time</p>
                <p class="stat-value" style="font-size:1rem">${formatTeamLabel(nextPick)}</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Total de Rodadas</p>
                <p class="stat-value">${session.total_rounds}</p>
            </div>
        `;

        elements.sessionSummary.innerHTML = `Liga: <strong>${session.league}</strong>`;
        elements.progressLabel.textContent = `${drafted} de ${total} picks realizados`;
        elements.progressPercent.textContent = `${progress}%`;
        elements.progressBar.style.width = `${progress}%`;
    }

    function computeScheduleEndDate(startDate, totalRounds) {
        if (!startDate) return '';
        const rounds = parseInt(totalRounds, 10);
        if (Number.isNaN(rounds) || rounds < 1) return '';
        const base = new Date(`${startDate}T00:00:00-03:00`);
        if (Number.isNaN(base.getTime())) return '';
        base.setDate(base.getDate() + (rounds - 1));
        const y = base.getFullYear();
        const m = String(base.getMonth() + 1).padStart(2, '0');
        const d = String(base.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function formatDateBr(isoDate) {
        if (!isoDate) return '';
        const [y, m, d] = isoDate.split('-');
        if (!y || !m || !d) return '';
        return `${d}/${m}/${y}`;
    }

    function parseDateBrToIso(brDate) {
        if (!brDate) return '';
        const parts = brDate.split('/');
        if (parts.length !== 3) return '';
        const [d, m, y] = parts.map((p) => p.trim());
        if (!d || !m || !y) return '';
        return `${y}-${m.padStart(2, '0')}-${d.padStart(2, '0')}`;
    }

    async function saveDailySchedule() {
        try {
            const startDateBr = document.getElementById('modalDailyScheduleStart')?.value || '';
            const startDate = parseDateBrToIso(startDateBr);
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'set_daily_schedule', token: TOKEN, enabled: 1, start_date: startDate }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao salvar agendamento');
            showMessage('Agendamento salvo. O draft iniciará automaticamente às 00:00:01 (Brasília) no Dia 01 informado.', 'success');
            const modal = bootstrap.Modal.getInstance(document.getElementById('dailyScheduleModal'));
            modal?.hide();
            await loadState();
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    function renderActions() {
        const session = state.session;
        if (!session) return;

        const buttons = [];

        if (state.canEditOrder) {
            buttons.push(`<button class="btn-ghost" style="padding:7px 14px;font-size:12px" onclick="openOrderModal()"><i class="bi bi-sliders me-1"></i>Ordem</button>`);
        }

        if (session.status === 'setup') {
            const startDate = session.daily_schedule_start_date || '';
            const endDate = computeScheduleEndDate(startDate, session.total_rounds);
            if (startDate) {
                buttons.push(`
                    <div style="font-size:12px;color:var(--text-2)">
                        Início: <strong style="color:var(--text)">${formatDateBr(startDate)}</strong><br>
                        Fim: <strong style="color:var(--text)">${formatDateBr(endDate) || '—'}</strong>
                    </div>
                `);
            } else {
                buttons.push(`<button class="btn-green" style="padding:7px 14px;font-size:12px" onclick="openScheduleStartPicker()"><i class="bi bi-calendar-event me-1"></i>Definir dia de início</button>`);
            }
        }

        if (session.status === 'in_progress') {
            buttons.push(`<button class="btn-ghost" style="padding:7px 14px;font-size:12px" onclick="loadState()"><i class="bi bi-arrow-clockwise me-1"></i>Atualizar</button>`);
            buttons.push(`<button class="btn-ghost" style="padding:7px 14px;font-size:12px" onclick="openDraftViewer()"><i class="bi bi-eye me-1"></i>Ver página do draft</button>`);
            buttons.push(`<button class="btn-red" style="padding:7px 14px;font-size:12px" onclick="finalizeDraft()"><i class="bi bi-flag me-1"></i>Finalizar</button>`);
        }

        if (session.status === 'completed') {
            buttons.push(`<span class="status-pill completed">Draft concluído</span>`);
        }

        elements.actionButtons.innerHTML = buttons.join('');
    }

    function openScheduleStartPicker() {
        const session = state.session;
        if (!session) return;
        document.getElementById('modalDailyScheduleStart').value = formatDateBr(session.daily_schedule_start_date || '');
        document.getElementById('modalDailyScheduleStart').disabled = session.status !== 'setup';
        const endDate = computeScheduleEndDate(session.daily_schedule_start_date || '', session.total_rounds);
        document.getElementById('modalDailyScheduleEnd').value = formatDateBr(endDate) || '—';
        document.getElementById('modalSaveScheduleBtn').disabled = session.status !== 'setup';
        const modal = new bootstrap.Modal(document.getElementById('dailyScheduleModal'));
        modal.show();
        const startInput = document.getElementById('modalDailyScheduleStart');
        if (startInput) {
            startInput.oninput = () => {
                const iso = parseDateBrToIso(startInput.value || '');
                const computed = computeScheduleEndDate(iso, session.total_rounds);
                document.getElementById('modalDailyScheduleEnd').value = formatDateBr(computed) || '—';
            };
        }
    }

    function renderOrder() {
        if (!state.manualOrder.length) {
            elements.orderList.innerHTML = '<p class="state-empty">Defina a ordem para desbloquear o draft.</p>';
            return;
        }

        const teamsById = Object.fromEntries(state.teams.map((team) => [team.id, team]));
        const allowEdit = state.canEditOrder;
        elements.orderList.innerHTML = state.manualOrder
            .map((teamId, index) => {
                const team = teamsById[teamId] || {};
                const actionButtons = allowEdit
                    ? `<div class="order-actions">
                            <button class="order-btn" ${index === 0 ? 'disabled' : ''} onclick="moveManualTeam(${index}, -1)"><i class="bi bi-arrow-up"></i></button>
                            <button class="order-btn" ${index === state.manualOrder.length - 1 ? 'disabled' : ''} onclick="moveManualTeam(${index}, 1)"><i class="bi bi-arrow-down"></i></button>
                        </div>`
                    : '';
                return `
                    <div class="order-item">
                        <div class="d-flex align-items-center gap-3 flex-grow-1">
                            <div class="order-rank">${index + 1}</div>
                            <div class="team-chip">
                                <img src="${team.photo_url || '/img/default-team.png'}" alt="${team.name || 'Time'}" onerror="this.src='/img/default-team.png'">
                                <div>
                                    <div class="team-chip-name">${team.city || ''} ${team.name || ''}</div>
                                    <div class="team-chip-gm">${team.owner_name || 'Sem GM'}</div>
                                </div>
                            </div>
                        </div>
                        ${actionButtons}
                    </div>`;
            })
            .join('');
    }

    function renderManualOrderList() {
        if (!state.manualOrder.length) {
            elements.manualOrderList.innerHTML = '<div class="state-empty">Carregando...</div>';
            return;
        }
        const teamsById = Object.fromEntries(state.teams.map((team) => [team.id, team]));
        const total = state.manualOrder.length;
        const options = Array.from({ length: total }, (_, idx) => idx + 1);
        elements.manualOrderList.innerHTML = state.manualOrder
            .map((teamId, index) => {
                const team = teamsById[teamId] || {};
                return `
                    <div class="manual-order-row">
                        <select class="field-input manual-position-select" onchange="updateManualOrderPosition(${teamId}, this.value)">
                            ${options.map((pos) => `<option value="${pos}" ${pos === index + 1 ? 'selected' : ''}>#${pos}</option>`).join('')}
                        </select>
                        <div class="d-flex align-items-center gap-2">
                            <img src="${team.photo_url || '/img/default-team.png'}" alt="${team.name || 'Time'}" onerror="this.src='/img/default-team.png'" style="width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md)">
                            <div>
                                <div class="team-chip-name">${team.city || ''} ${team.name || ''}</div>
                                <div class="team-chip-gm">${team.owner_name || 'Sem GM'}</div>
                            </div>
                        </div>
                        <div style="font-size:10px;color:var(--text-3)">#${team.id}</div>
                    </div>`;
            })
            .join('');
    }

    function updateManualOrderPosition(teamId, position) {
        const newPos = parseInt(position, 10);
        if (!Number.isFinite(newPos)) return;
        const index = state.manualOrder.indexOf(parseInt(teamId, 10));
        if (index === -1) return;
        const updated = [...state.manualOrder];
        const [removed] = updated.splice(index, 1);
        updated.splice(newPos - 1, 0, removed);
        state.manualOrder = updated;
        renderManualOrderList();
        renderOrder();
    }

    function resetLotteryView() {
        if (!elements.lotteryTrack || !elements.lotteryResults) return;
        elements.lotteryResults.innerHTML = '';
        elements.lotteryTrack.innerHTML = '';
        elements.lotteryPlaceholder?.classList.remove('d-none');
        state.lotteryQueue = [];
        state.lotteryIndex = 0;
    }

    function buildBallTeams(teams = []) {
        if (!teams.length) {
            return Array.from({ length: LOTTERY_BALL_COUNT }, () => ({ photo_url: '/img/default-team.png' }));
        }
        const filled = [];
        for (let i = 0; i < LOTTERY_BALL_COUNT; i += 1) {
            filled.push(teams[i % teams.length]);
        }
        return filled;
    }

    function updateLotteryButton() {
        if (!elements.lotteryButton) return;
        if (!state.canEditOrder) {
            elements.lotteryButton.disabled = true;
            elements.lotteryButton.innerHTML = '<i class="bi bi-lock-fill me-1"></i>Ordem bloqueada';
            return;
        }

        elements.lotteryButton.disabled = state.lotteryDrawn;
        if (state.lotteryDrawn) {
            elements.lotteryButton.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Sorteio concluído';
            return;
        }

        elements.lotteryButton.innerHTML = state.lotteryQueue.length
            ? '<i class="bi bi-shuffle me-1"></i>Sortear próximo'
            : '<i class="bi bi-shuffle me-1"></i>Iniciar sorteio';
    }

    function startLottery(orderDetails = []) {
        if (!elements.lotteryTrack || !elements.lotteryResults) return;
        state.lotteryQueue = orderDetails.length ? orderDetails : state.teams;
        state.lotteryIndex = 0;
        elements.lotteryResults.innerHTML = '';
        const ballTeams = buildBallTeams(state.teams);
        elements.lotteryTrack.innerHTML = ballTeams
            .map((team) => `
                <div class="lottery-ball">
                    <img src="${team.photo_url || '/img/default-team.png'}" alt="${team.name || 'Time'}" onerror="this.src='/img/default-team.png'">
                </div>`)
            .join('');

        elements.lotteryPlaceholder?.classList.add('d-none');
        updateLotteryButton();
    }

    async function drawNextLottery() {
        if (!elements.lotteryTrack || !elements.lotteryResults) return;
        if (!state.lotteryQueue.length) return;
        if (state.lotteryIndex >= state.lotteryQueue.length) {
            state.lotteryDrawn = true;
            localStorage.setItem(LOTTERY_STORAGE_KEY, '1');
            updateLotteryButton();
            showMessage('Sorteio concluído.');
            return;
        }

        const balls = Array.from(elements.lotteryTrack.querySelectorAll('.lottery-ball'));
        balls.forEach((ball) => ball.classList.remove('active'));
        if (balls.length) {
            const ballIndex = Math.floor(Math.random() * balls.length);
            balls[ballIndex]?.classList.add('active');
        }

        const team = state.lotteryQueue[state.lotteryIndex] || {};
        elements.lotteryResults.insertAdjacentHTML(
            'beforeend',
            `<div class="lottery-result">
                <span class="lottery-rank">${state.lotteryIndex + 1}</span>
                <img src="${team.photo_url || '/img/default-team.png'}" alt="${team.name || 'Time'}" onerror="this.src='/img/default-team.png'">
                <div>
                    <div class="team-chip-name">${team.city || ''} ${team.name || ''}</div>
                    <div class="team-chip-gm">${team.owner_name || 'Sem GM'}</div>
                </div>
            </div>`
        );

        state.lotteryIndex += 1;
        if (state.lotteryIndex >= state.lotteryQueue.length) {
            state.lotteryDrawn = true;
            localStorage.setItem(LOTTERY_STORAGE_KEY, '1');
            if (elements.applyOrderButton) {
                elements.applyOrderButton.classList.remove('d-none');
            }
            try {
                if (state.session?.total_rounds) {
                    await submitLotteryOrder();
                    showMessage('Sorteio concluído e ordem salva automaticamente.');
                } else {
                    showMessage('Sorteio concluído. Clique em Aplicar para definir o número de rodadas e salvar.', 'info');
                }
            } catch (error) {
                showMessage(error.message || 'Erro ao salvar ordem do sorteio', 'danger');
            }
        }
        updateLotteryButton();
    }

    async function submitLotteryOrder() {
        if (!state.session?.total_rounds) {
            throw new Error('Defina o total de rodadas clicando em Aplicar.');
        }
        if (!state.lotteryQueue.length) {
            throw new Error('Nenhuma ordem sorteada.');
        }
        const teamIds = state.lotteryQueue.map((team) => team.id).filter(Boolean);
        const res = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'set_manual_order', token: TOKEN, team_ids: teamIds }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Erro ao salvar ordem do sorteio');
        state.manualOrder = teamIds;
        renderManualOrderList();
        renderOrder();
    }

    function renderPool() {
        const filtered = (state.pool || []).filter((player) => {
            if (!state.search) return true;
            const needle = state.search;
            return (
                (player.name || '').toLowerCase().includes(needle) ||
                (player.position || '').toLowerCase().includes(needle)
            );
        });

        if (!filtered.length) {
            elements.poolTable.innerHTML = '<tr><td colspan="7" class="state-empty">Nenhum jogador no pool.</td></tr>';
            elements.poolPagination.innerHTML = '';
            return;
        }

        const totalPages = Math.ceil(filtered.length / state.poolPerPage);
        if (state.poolPage > totalPages) state.poolPage = totalPages;
        const start = (state.poolPage - 1) * state.poolPerPage;
        const end = start + state.poolPerPage;
        const paginated = filtered.slice(start, end);

        elements.poolTable.innerHTML = paginated
            .map((player, index) => {
                const globalIndex = start + index + 1;
                const drafted = player.draft_status === 'drafted';
                const canDelete = state.session?.status === 'setup' && !drafted;
                const canEdit = state.session?.status === 'setup' && !drafted;

                const deleteBtn = canDelete
                    ? `<button class="btn-sm-icon danger" onclick="deleteInitDraftPlayer(${player.id}, '${(player.name || '').replace(/'/g, "\\'")}')"><i class="bi bi-trash"></i></button>`
                    : '';
                const editBtn = canEdit
                    ? `<button class="btn-sm-icon amber" onclick="openEditPlayer(${player.id})"><i class="bi bi-pencil"></i></button>`
                    : '';

                const actions = (editBtn || deleteBtn) ? `<div style="display:flex;gap:4px;justify-content:flex-end">${editBtn} ${deleteBtn}</div>` : '<span style="color:var(--text-3)">—</span>';

                return `
                    <tr>
                        <td>${globalIndex}</td>
                        <td class="td-name">${player.name}</td>
                        <td>${player.position}</td>
                        <td style="font-weight:700;color:var(--text)">${player.ovr}</td>
                        <td>${player.age ?? '—'}</td>
                        <td><span class="${drafted ? 'badge-drafted' : 'badge-available'}">${drafted ? 'Drafted' : 'Disponível'}</span></td>
                        <td style="text-align:right">${actions}</td>
                    </tr>`;
            })
            .join('');

        renderPoolPagination(totalPages, filtered.length);
    }

    function renderPoolPagination(totalPages, totalItems) {
        if (totalPages <= 1) {
            elements.poolPagination.innerHTML = `<span style="font-size:11px;color:var(--text-2)">${totalItems} jogadores</span>`;
            return;
        }

        const maxButtons = 5;
        let startPage = Math.max(1, state.poolPage - Math.floor(maxButtons / 2));
        let endPage = Math.min(totalPages, startPage + maxButtons - 1);
        if (endPage - startPage < maxButtons - 1) {
            startPage = Math.max(1, endPage - maxButtons + 1);
        }

        let html = '<nav><ul class="pagination pagination-sm mb-0">';
        html += `<li class="page-item ${state.poolPage === 1 ? 'disabled' : ''}"><a class="page-link" href="#" onclick="changePoolPage(${state.poolPage - 1}); return false;">&laquo;</a></li>`;
        if (startPage > 1) {
            html += `<li class="page-item"><a class="page-link" href="#" onclick="changePoolPage(1); return false;">1</a></li>`;
            if (startPage > 2) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
        for (let i = startPage; i <= endPage; i++) {
            html += `<li class="page-item ${i === state.poolPage ? 'active' : ''}"><a class="page-link" href="#" onclick="changePoolPage(${i}); return false;">${i}</a></li>`;
        }
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            html += `<li class="page-item"><a class="page-link" href="#" onclick="changePoolPage(${totalPages}); return false;">${totalPages}</a></li>`;
        }
        html += `<li class="page-item ${state.poolPage === totalPages ? 'disabled' : ''}"><a class="page-link" href="#" onclick="changePoolPage(${state.poolPage + 1}); return false;">&raquo;</a></li>`;
        html += '</ul></nav>';
        html += `<span style="font-size:11px;color:var(--text-2)">${totalItems} jogadores</span>`;
        elements.poolPagination.innerHTML = html;
    }

    function changePoolPage(page) {
        const totalPages = Math.ceil((state.pool || []).filter((player) => {
            if (!state.search) return true;
            const needle = state.search;
            return (
                (player.name || '').toLowerCase().includes(needle) ||
                (player.position || '').toLowerCase().includes(needle)
            );
        }).length / state.poolPerPage);
        if (page < 1 || page > totalPages) return;
        state.poolPage = page;
        renderPool();
    }

    function renderRounds() {
        if (!state.order.length) {
            elements.roundsContainer.innerHTML = '<div class="state-empty">Nenhuma ordem configurada ainda.</div>';
            elements.roundsMeta.textContent = '';
            return;
        }

        const grouped = state.order.reduce((acc, pick) => {
            acc[pick.round] = acc[pick.round] || [];
            acc[pick.round].push(pick);
            return acc;
        }, {});

        elements.roundsMeta.textContent = `${Object.keys(grouped).length} rodadas · ${state.order.length} picks`;

        const roundsHtml = Object.keys(grouped)
            .sort((a, b) => a - b)
            .map((round) => {
                const picks = grouped[round].sort((a, b) => a.pick_position - b.pick_position);
                const rows = picks
                    .map((pick) => {
                        const player = pick.player_name
                            ? `<span style="color:var(--green);font-weight:600">${pick.player_name}</span> <span style="color:var(--text-2);font-size:11px">(${pick.player_position ?? ''} — OVR ${pick.player_ovr ?? '—'})</span>`
                            : '<span style="color:var(--text-3)">—</span>';
                        return `
                            <tr>
                                <td style="font-weight:700;color:var(--text)">${pick.pick_position}</td>
                                <td>
                                    <div class="team-chip">
                                        <img src="${pick.team_photo || '/img/default-team.png'}" alt="${pick.team_name}" onerror="this.src='/img/default-team.png'" style="width:28px;height:28px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md)">
                                        <div>
                                            <div class="team-chip-name">${pick.team_city || ''} ${pick.team_name || ''}</div>
                                            <div class="team-chip-gm">${pick.team_owner || ''}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>${player}</td>
                                <td style="text-align:right">${pick.picked_player_id ? '<i class="bi bi-check2-circle" style="color:var(--green)"></i>' : ''}</td>
                            </tr>`;
                    })
                    .join('');
                return `
                    <div class="round-section">
                        <div class="round-section-head">
                            <span class="round-section-title">Rodada ${round}</span>
                            <span style="font-size:11px;color:var(--text-3)">${picks.length} picks</span>
                        </div>
                        <div class="data-table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Pick</th>
                                        <th>Time</th>
                                        <th>Jogador</th>
                                        <th style="text-align:right">✓</th>
                                    </tr>
                                </thead>
                                <tbody>${rows}</tbody>
                            </table>
                        </div>
                    </div>`;
            })
            .join('');

        elements.roundsContainer.innerHTML = roundsHtml;
    }

    function getRoundOneOrder() {
        if (!state.order.length) {
            return state.teams.map((team) => team.id);
        }
        return state.order
            .filter((pick) => pick.round === 1)
            .sort((a, b) => a.pick_position - b.pick_position)
            .map((pick) => pick.team_id);
    }

    function moveManualTeam(index, delta) {
        const newIndex = index + delta;
        if (newIndex < 0 || newIndex >= state.manualOrder.length) return;
        const updated = [...state.manualOrder];
        const [removed] = updated.splice(index, 1);
        updated.splice(newIndex, 0, removed);
        state.manualOrder = updated;
        renderManualOrderList();
        renderOrder();
    }

    function resetManualOrder() {
        state.manualOrder = getRoundOneOrder();
        renderManualOrderList();
        renderOrder();
    }

    function openOrderModal() {
        if (!state.canEditOrder) {
            showMessage('A ordem não pode mais ser alterada após a primeira pick.', 'warning');
            return;
        }
        renderManualOrderList();
        resetLotteryView();
        setOrderMode('select');
        orderModal.show();
    }

    async function randomizeOrder() {
        if (!state.canEditOrder) {
            showMessage('A ordem não pode mais ser alterada após a primeira pick.', 'warning');
            return;
        }
        if (state.lotteryDrawn) {
            showMessage('O sorteio já foi realizado. Você pode ajustar a ordem manualmente.', 'warning');
            return;
        }
        if (!state.teams.length) {
            const loaded = await loadState();
            if (!loaded || !state.teams.length) {
                showMessage('Sem times para sortear.', 'warning');
                return;
            }
        }
        try {
            if (!state.lotteryQueue.length) {
                setOrderMode('lottery');
                const orderDetails = shuffle([...state.teams]);
                state.manualOrder = orderDetails.map((team) => team.id);
                renderManualOrderList();
                renderOrder();
                startLottery(orderDetails);
                updateLotteryButton();
            }

            await drawNextLottery();
            updateLotteryButton();
            state.order = state.lotteryQueue.slice(0, state.lotteryIndex).map((team, index) => ({
                ...team,
                position: index + 1,
            }));
            if (state.lotteryDrawn) {
                showMessage('Ordem sorteada com sucesso.');
            }
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    async function submitManualOrder() {
        try {
            if (!state.canEditOrder) {
                showMessage('A ordem não pode mais ser alterada após a primeira pick.', 'warning');
                return;
            }
            if (state.orderMode === 'lottery' && state.lotteryQueue.length) {
                state.manualOrder = state.lotteryQueue.map((team) => team.id).filter(Boolean);
            }
            if (!state.manualOrder.length) {
                state.manualOrder = getRoundOneOrder();
            }
            if (!state.manualOrder.length) {
                showMessage('Defina a ordem antes de aplicar.', 'warning');
                return;
            }

            const roundsOk = await ensureTotalRounds();
            if (!roundsOk) return;

            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'set_manual_order', token: TOKEN, team_ids: state.manualOrder }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao aplicar ordem');

            state.lotteryDrawn = true;
            updateOrderEditVisibility();
            await loadState();
            orderModal.hide();
            showMessage('Ordem e rodadas definidas com sucesso!', 'success');
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    async function ensureTotalRounds() {
        const currentRounds = state.session?.total_rounds ?? '';
        const inputRounds = prompt('Quantas rodadas o draft terá?', currentRounds);
        if (inputRounds === null) {
            return false;
        }
        const roundsValue = parseInt(inputRounds, 10);
        if (Number.isNaN(roundsValue) || roundsValue < 1 || roundsValue > 10) {
            showMessage('Informe um número de rodadas entre 1 e 10.', 'warning');
            return false;
        }

        if (!state.session || roundsValue !== state.session.total_rounds) {
            const roundsRes = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'set_total_rounds', token: TOKEN, total_rounds: roundsValue }),
            });
            const roundsData = await roundsRes.json();
            if (!roundsData.success) throw new Error(roundsData.error || 'Erro ao atualizar rodadas');
            if (state.session) {
                state.session.total_rounds = roundsData.total_rounds;
            }
            renderStats();
        }

        return true;
    }

    async function startDraft() {
        if (!confirm('Deseja iniciar o draft?')) return;
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'start', token: TOKEN }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao iniciar');
            showMessage('Draft iniciado.');
            loadState();
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    async function finalizeDraft() {
        if (!confirm('Deseja finalizar o draft? Certifique-se de que todas as picks foram feitas.')) return;
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'finalize', token: TOKEN }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao finalizar');
            showMessage('Draft finalizado com sucesso.');
            loadState();
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    async function makePick(playerId) {
        if (!confirm('Confirmar pick deste jogador?')) return;
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'make_pick', token: TOKEN, player_id: playerId }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Falha ao registrar pick');
            showMessage('Pick registrada.');
            loadState();
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    async function deleteInitDraftPlayer(playerId, playerName) {
        if (!confirm(`Remover ${playerName} do draft inicial?`)) return;
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_player', token: TOKEN, player_id: playerId }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao remover jogador');
            showMessage('Jogador removido do pool.');
            loadState();
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    async function handleAddPlayer(event) {
        event.preventDefault();
        const formData = new FormData(event.target);
        const payload = Object.fromEntries(formData.entries());
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'add_player', token: TOKEN, ...payload }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao adicionar jogador');
            showMessage('Jogador adicionado ao pool.');
            event.target.reset();
            bootstrap.Modal.getInstance(document.getElementById('addPlayerModal')).hide();
            loadState();
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    function openEditPlayer(playerId) {
        const player = state.pool.find(p => p.id === playerId);
        if (!player) {
            showMessage('Jogador não encontrado.', 'warning');
            return;
        }
        document.getElementById('editPlayerId').value = player.id;
        document.getElementById('editPlayerName').value = player.name || '';
        document.getElementById('editPlayerPosition').value = player.position || 'SF';
        document.getElementById('editPlayerAge').value = player.age || 19;
        document.getElementById('editPlayerOvr').value = player.ovr || 70;
        new bootstrap.Modal(document.getElementById('editPlayerModal')).show();
    }

    async function handleEditPlayer(event) {
        event.preventDefault();
        const formData = new FormData(event.target);
        const payload = Object.fromEntries(formData.entries());
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'edit_player', token: TOKEN, ...payload }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao editar jogador');
            showMessage('Jogador atualizado com sucesso.');
            bootstrap.Modal.getInstance(document.getElementById('editPlayerModal')).hide();
            loadState();
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    async function handleImportCSV(event) {
        event.preventDefault();
        const form = event.target;
        const fileInput = form.querySelector('input[type="file"]');
        if (!fileInput.files.length) {
            showMessage('Selecione um arquivo CSV.', 'warning');
            return;
        }
        const formData = new FormData(form);
        formData.append('action', 'import_csv');
        formData.append('token', TOKEN);
        try {
            const res = await fetch(API_URL, { method: 'POST', body: formData });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao importar CSV');
            showMessage(`Importação concluída: ${data.imported} jogadores.`);
            form.reset();
            bootstrap.Modal.getInstance(document.getElementById('importCSVModal')).hide();
            loadState();
        } catch (error) {
            showMessage(error.message, 'danger');
        }
    }

    function downloadCSVTemplate() {
        const csv = 'name,position,age,ovr\nJohn Doe,SF,22,75';
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'initdraft-template.csv';
        link.click();
        URL.revokeObjectURL(url);
    }

    function formatTeamLabel(pick) {
        if (!pick) return '—';
        return `${pick.team_city ?? ''} ${pick.team_name ?? ''}`.trim() || '—';
    }

    loadState();
</script>
</body>
</html>
