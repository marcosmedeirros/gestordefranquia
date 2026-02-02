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
    <title>Drafts - FBA Manager</title>
    
    <!-- PWA Meta Tags -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0a0a0c">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        .draft-card {
            background: var(--fba-card-bg);
            border: 1px solid var(--fba-border);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        .draft-card:hover {
            border-color: var(--fba-orange);
            box-shadow: 0 4px 12px rgba(241, 117, 7, 0.2);
        }
        .draft-year {
            font-size: 2rem;
            font-weight: bold;
            color: var(--fba-orange);
            margin-bottom: 10px;
        }
        .draft-team {
            display: flex;
            align-items: center;
            padding: 10px;
            background: var(--fba-dark-bg);
            border-radius: 6px;
            margin-bottom: 8px;
            border-left: 3px solid var(--fba-orange);
        }
        .draft-pick-order {
            font-weight: bold;
            color: var(--fba-orange);
            min-width: 40px;
        }
        .draft-player-name {
            flex: 1;
            margin-left: 15px;
        }
        .draft-player-stats {
            display: flex;
            gap: 15px;
            font-size: 0.9rem;
            color: var(--fba-text-muted);
        }
        .draft-player-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .draft-player-stat-value {
            font-weight: bold;
            color: var(--fba-text);
            font-size: 1.1rem;
        }
        .draft-player-stat-label {
            font-size: 0.8rem;
            text-transform: uppercase;
        }
        .btn-draft-action {
            background: var(--fba-orange);
            border: none;
            color: #fff;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        .btn-draft-action:hover {
            background: darkorange;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(241, 117, 7, 0.3);
        }
        .no-drafts {
            text-align: center;
            padding: 40px 20px;
            color: var(--fba-text-muted);
        }
        .no-drafts i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: var(--fba-orange);
            opacity: 0.5;
        }
        .draft-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85rem;
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
                    <h1 class="display-5 mb-2" style="color: var(--fba-text);">
                        <i class="bi bi-calendar2-event"></i> Drafts
                    </h1>
                    <p class="text-muted">Acompanhe os drafts da sua liga e gerencie as seleções do seu time</p>
                </div>

                <!-- Drafts List -->
                <div id="drafts-container">
                    <div class="text-center py-5">
                        <div class="spinner-border" style="color: var(--fba-orange);" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                    </div>
                </div>

                <!-- New Draft Modal -->
                <div class="modal fade" id="newDraftModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content" style="background: var(--fba-card-bg); border: 1px solid var(--fba-border);">
                            <div class="modal-header" style="border-color: var(--fba-border);">
                                <h5 class="modal-title">Novo Draft</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Ano do Draft</label>
                                    <input type="number" class="form-control" id="draftYear" min="2024" max="2030" value="2026">
                                </div>
                            </div>
                            <div class="modal-footer" style="border-color: var(--fba-border);">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="button" class="btn" style="background: var(--fba-orange); color: #fff;" onclick="createDraft()">Criar Draft</button>
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
        const userLeague = '<?= $league ?>';

        async function loadDrafts() {
            try {
                const response = await fetch(`/api/drafts.php?league=${userLeague}`);
                const data = await response.json();
                
                if (!data.success) {
                    document.getElementById('drafts-container').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i> ${data.error || 'Erro ao carregar drafts'}
                        </div>
                    `;
                    return;
                }

                const drafts = data.drafts || [];
                if (drafts.length === 0) {
                    document.getElementById('drafts-container').innerHTML = `
                        <div class="no-drafts">
                            <i class="bi bi-calendar-x"></i>
                            <h5>Nenhum draft cadastrado</h5>
                            <p>Crie um novo draft para começar a gerenciar as seleções da sua liga</p>
                            <button class="btn" style="background: var(--fba-orange); color: #fff;" data-bs-toggle="modal" data-bs-target="#newDraftModal">
                                <i class="bi bi-plus-circle"></i> Novo Draft
                            </button>
                        </div>
                    `;
                    return;
                }

                let html = '';
                drafts.forEach(draft => {
                    html += `
                        <div class="draft-card">
                            <div class="draft-year">Draft ${draft.year}</div>
                            <div id="draft-${draft.id}-players"></div>
                            <div class="draft-actions mt-3">
                                <button class="btn btn-sm btn-outline-secondary" onclick="editDraft(${draft.id})">
                                    <i class="bi bi-pencil"></i> Editar
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteDraft(${draft.id})">
                                    <i class="bi bi-trash"></i> Remover
                                </button>
                            </div>
                        </div>
                    `;
                });

                document.getElementById('drafts-container').innerHTML = html;

                // Carregar jogadores de cada draft
                drafts.forEach(draft => {
                    loadDraftPlayers(draft.id);
                });
            } catch (err) {
                document.getElementById('drafts-container').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i> Erro ao carregar drafts
                    </div>
                `;
            }
        }

        async function loadDraftPlayers(draftId) {
            try {
                const response = await fetch(`/api/drafts.php?draft_id=${draftId}`);
                const data = await response.json();
                
                if (!data.success) return;

                const players = data.players || [];
                let html = '';

                if (players.length === 0) {
                    html = '<p class="text-muted text-center my-3">Nenhum jogador neste draft</p>';
                } else {
                    players.forEach((player, index) => {
                        html += `
                            <div class="draft-team">
                                <div class="draft-pick-order">#${index + 1}</div>
                                <div class="draft-player-name">
                                    <strong>${player.name}</strong>
                                    <small class="text-muted d-block">${player.position}</small>
                                </div>
                                <div class="draft-player-stats">
                                    <div class="draft-player-stat">
                                        <div class="draft-player-stat-value">${player.age}</div>
                                        <div class="draft-player-stat-label">Idade</div>
                                    </div>
                                    <div class="draft-player-stat">
                                        <div class="draft-player-stat-value" style="color: ${getOvrColor(player.ovr)}">${player.ovr}</div>
                                        <div class="draft-player-stat-label">Overall</div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                }

                document.getElementById(`draft-${draftId}-players`).innerHTML = html;
            } catch (err) {
                // Silenciosamente falhar
            }
        }

        function getOvrColor(ovr) {
            if (ovr >= 95) return '#00ff00';
            if (ovr >= 90) return '#80ff00';
            if (ovr >= 85) return '#ffff00';
            if (ovr >= 80) return '#ff9900';
            return '#ff0000';
        }

        async function createDraft() {
            const year = document.getElementById('draftYear').value;
            if (!year) {
                alert('Informe o ano do draft');
                return;
            }

            try {
                const response = await fetch('/api/drafts.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ year })
                });
                const data = await response.json();
                
                if (!data.success) {
                    alert(data.error || 'Erro ao criar draft');
                    return;
                }

                bootstrap.Modal.getInstance(document.getElementById('newDraftModal')).hide();
                loadDrafts();
            } catch (err) {
                alert('Erro ao criar draft');
            }
        }

        function editDraft(draftId) {
            alert('Funcionalidade em desenvolvimento');
        }

        async function deleteDraft(draftId) {
            if (!confirm('Tem certeza que deseja remover este draft?')) return;

            try {
                const response = await fetch(`/api/drafts.php?id=${draftId}`, {
                    method: 'DELETE'
                });
                const data = await response.json();
                
                if (!data.success) {
                    alert(data.error || 'Erro ao remover draft');
                    return;
                }

                loadDrafts();
            } catch (err) {
                alert('Erro ao remover draft');
            }
        }

        // Carregar drafts ao abrir a página
        document.addEventListener('DOMContentLoaded', loadDrafts);
    </script>
    <script src="/js/pwa.js"></script>
</body>
</html>
