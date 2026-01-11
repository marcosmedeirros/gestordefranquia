<?php
session_start();
require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

requireAuth();

$user = $_SESSION['user'];
$league = $user['league'];

$pdo = getDB();
$stmt = $pdo->prepare("SELECT id, name FROM teams WHERE user_id = ? AND league = ?");
$stmt->execute([$user['id'], $league]);
$userTeam = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$userTeam) {
    header('Location: onboarding.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Trades - FBA Manager</title>
    
    <!-- PWA Meta Tags -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0a0a0c">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="apple-touch-icon" href="/img/icon-192.png">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        .trade-card {
            background: var(--fba-card-bg);
            border: 1px solid var(--fba-border);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        .trade-card:hover {
            border-color: var(--fba-orange);
            box-shadow: 0 4px 12px rgba(241, 117, 7, 0.2);
        }
        .trade-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--fba-border);
        }
        .trade-date {
            font-size: 0.9rem;
            color: var(--fba-text-muted);
        }
        .trade-status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: bold;
        }
        .trade-status.pending {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        .trade-status.accepted {
            background: rgba(0, 255, 0, 0.2);
            color: #00ff00;
        }
        .trade-status.rejected {
            background: rgba(255, 0, 0, 0.2);
            color: #ff0000;
        }
        .trade-sides {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 20px;
            align-items: center;
            margin-bottom: 15px;
        }
        .trade-side {
            background: var(--fba-dark-bg);
            padding: 15px;
            border-radius: 6px;
            border-left: 3px solid var(--fba-orange);
        }
        .trade-side-title {
            font-weight: bold;
            color: var(--fba-orange);
            margin-bottom: 10px;
            font-size: 0.9rem;
        }
        .trade-player {
            display: flex;
            align-items: center;
            padding: 8px 0;
            color: var(--fba-text);
        }
        .trade-player-info {
            flex: 1;
        }
        .trade-player-name {
            font-weight: 500;
        }
        .trade-player-stats {
            font-size: 0.8rem;
            color: var(--fba-text-muted);
        }
        .trade-exchange {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--fba-orange);
        }
        .trade-exchange i {
            font-size: 1.5rem;
        }
        .trade-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--fba-border);
        }
        .btn-trade-accept {
            background: #00ff00;
            color: #000;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        .btn-trade-accept:hover {
            background: #00dd00;
            transform: translateY(-2px);
        }
        .btn-trade-reject {
            background: #ff3333;
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        .btn-trade-reject:hover {
            background: #dd0000;
            transform: translateY(-2px);
        }
        .no-trades {
            text-align: center;
            padding: 40px 20px;
            color: var(--fba-text-muted);
        }
        .no-trades i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: var(--fba-orange);
            opacity: 0.5;
        }
        .btn-new-trade {
            background: var(--fba-orange);
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .btn-new-trade:hover {
            background: darkorange;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(241, 117, 7, 0.3);
        }
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--fba-border);
        }
        .tab-btn {
            padding: 10px 20px;
            background: transparent;
            border: none;
            color: var(--fba-text-muted);
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        .tab-btn.active {
            color: var(--fba-orange);
            border-bottom-color: var(--fba-orange);
        }
        .tab-btn:hover {
            color: var(--fba-text);
        }
    </style>
</head>
<body class="fba-dark">
    <div class="container-fluid">
        <div class="row" style="min-height: 100vh;">
            <!-- Sidebar -->
            <?php include 'components/sidebar.php'; ?>
            
            <!-- Main Content -->
            <div class="col-md-9 p-4">
                <div class="mb-4">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <h1 class="display-5 mb-2" style="color: var(--fba-text);">
                                <i class="bi bi-arrow-left-right"></i> Trades
                            </h1>
                            <p class="text-muted">Negocie jogadores com outros times da sua liga</p>
                        </div>
                        <button class="btn-new-trade" data-bs-toggle="modal" data-bs-target="#newTradeModal">
                            <i class="bi bi-plus-circle"></i> Nova Negociação
                        </button>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="tabs">
                    <button class="tab-btn active" onclick="filterTrades('all')">Todas (0)</button>
                    <button class="tab-btn" onclick="filterTrades('pending')">Pendentes (0)</button>
                    <button class="tab-btn" onclick="filterTrades('accepted')">Aceitas (0)</button>
                    <button class="tab-btn" onclick="filterTrades('rejected')">Rejeitadas (0)</button>
                </div>

                <!-- Trades List -->
                <div id="trades-container">
                    <div class="text-center py-5">
                        <div class="spinner-border" style="color: var(--fba-orange);" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                    </div>
                </div>

                <!-- New Trade Modal -->
                <div class="modal fade" id="newTradeModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content" style="background: var(--fba-card-bg); border: 1px solid var(--fba-border);">
                            <div class="modal-header" style="border-color: var(--fba-border);">
                                <h5 class="modal-title">Nova Negociação</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p class="text-muted mb-3">Funcionalidade em desenvolvimento. Em breve você poderá iniciar negociações com outros times.</p>
                            </div>
                            <div class="modal-footer" style="border-color: var(--fba-border);">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    <script src="js/app.js"></script>
    <script>
        const userTeamId = <?= $userTeam['id'] ?>;
        let allTrades = [];

        async function loadTrades() {
            try {
                const response = await fetch(`/api/trades.php`);
                const data = await response.json();
                
                if (!data.success) {
                    document.getElementById('trades-container').innerHTML = `
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Nenhuma negociação iniciada ainda
                        </div>
                    `;
                    return;
                }

                allTrades = data.trades || [];
                renderTrades(allTrades);
                updateTabCounts();
            } catch (err) {
                document.getElementById('trades-container').innerHTML = `
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Nenhuma negociação iniciada ainda
                    </div>
                `;
            }
        }

        function renderTrades(trades) {
            if (trades.length === 0) {
                document.getElementById('trades-container').innerHTML = `
                    <div class="no-trades">
                        <i class="bi bi-arrow-left-right"></i>
                        <h5>Nenhuma negociação</h5>
                        <p>Inicie uma negociação com outro time para começar</p>
                    </div>
                `;
                return;
            }

            let html = '';
            trades.forEach(trade => {
                const statusClass = trade.status.toLowerCase();
                const statusText = {
                    'pending': 'Pendente',
                    'accepted': 'Aceita',
                    'rejected': 'Rejeitada'
                }[trade.status] || trade.status;

                html += `
                    <div class="trade-card">
                        <div class="trade-header">
                            <div>
                                <h5 style="margin: 0; color: var(--fba-text);">${trade.team_from} vs ${trade.team_to}</h5>
                                <div class="trade-date">${new Date(trade.created_at).toLocaleDateString('pt-BR')}</div>
                            </div>
                            <span class="trade-status ${statusClass}">${statusText}</span>
                        </div>
                        <div class="trade-sides">
                            <div class="trade-side">
                                <div class="trade-side-title">${trade.team_from}</div>
                                <div class="trade-player">
                                    <div class="trade-player-info">
                                        <div class="trade-player-name">${trade.player_from}</div>
                                        <div class="trade-player-stats">OVR: ${trade.ovr_from}</div>
                                    </div>
                                </div>
                            </div>
                            <div class="trade-exchange">
                                <i class="bi bi-arrow-left-right"></i>
                            </div>
                            <div class="trade-side">
                                <div class="trade-side-title">${trade.team_to}</div>
                                <div class="trade-player">
                                    <div class="trade-player-info">
                                        <div class="trade-player-name">${trade.player_to}</div>
                                        <div class="trade-player-stats">OVR: ${trade.ovr_to}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        ${trade.status === 'pending' && trade.is_receiver ? `
                            <div class="trade-actions">
                                <button class="btn-trade-accept" onclick="acceptTrade(${trade.id})">
                                    <i class="bi bi-check-circle"></i> Aceitar
                                </button>
                                <button class="btn-trade-reject" onclick="rejectTrade(${trade.id})">
                                    <i class="bi bi-x-circle"></i> Rejeitar
                                </button>
                            </div>
                        ` : ''}
                    </div>
                `;
            });

            document.getElementById('trades-container').innerHTML = html;
        }

        function filterTrades(status) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');

            if (status === 'all') {
                renderTrades(allTrades);
            } else {
                const filtered = allTrades.filter(t => t.status.toLowerCase() === status);
                renderTrades(filtered);
            }
        }

        function updateTabCounts() {
            const counts = {
                all: allTrades.length,
                pending: allTrades.filter(t => t.status === 'pending').length,
                accepted: allTrades.filter(t => t.status === 'accepted').length,
                rejected: allTrades.filter(t => t.status === 'rejected').length
            };

            document.querySelectorAll('.tab-btn').forEach(btn => {
                const text = btn.textContent;
                const status = text.split(' ')[0].toLowerCase();
                const key = status === 'todas' ? 'all' : status === 'pendentes' ? 'pending' : status === 'aceitas' ? 'accepted' : 'rejected';
                btn.textContent = `${text.split(' ')[0]} (${counts[key]})`;
            });
        }

        async function acceptTrade(tradeId) {
            if (!confirm('Tem certeza que deseja aceitar esta negociação?')) return;
            // Implementar depois
        }

        async function rejectTrade(tradeId) {
            if (!confirm('Tem certeza que deseja rejeitar esta negociação?')) return;
            // Implementar depois
        }

        document.addEventListener('DOMContentLoaded', loadTrades);
    </script>
    <script src="/js/pwa.js"></script>
</body>
</html>
