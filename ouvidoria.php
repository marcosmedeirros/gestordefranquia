<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo = db();

// Buscar time do usuario para a sidebar
$stmtTeam = $pdo->prepare('SELECT t.* FROM teams t WHERE t.user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <title>Ouvidoria - FBA Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css">
</head>
<body>
    <!-- Botao Hamburguer para Mobile -->
    <button class="sidebar-toggle" id="sidebarToggle">
        <i class="bi bi-list fs-4"></i>
    </button>

    <!-- Overlay para fechar sidebar no mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="d-flex">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div class="dashboard-content">
            <div class="mb-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <h1 class="text-white fw-bold mb-0">
                        <i class="bi bi-chat-left-dots text-orange me-2"></i>Ouvidoria
                    </h1>
                    <span class="badge bg-dark border border-warning text-warning">
                        <i class="bi bi-shield-lock me-1"></i>Anonimo
                    </span>
                </div>
                <p class="text-light-gray mb-0">Envie uma mensagem anonima para a administracao.</p>
            </div>

            <div class="card bg-dark-panel border-orange">
                <div class="card-header bg-dark border-bottom border-orange">
                    <h5 class="mb-0 text-white"><i class="bi bi-envelope me-2 text-orange"></i>Nova mensagem</h5>
                </div>
                <div class="card-body">
                    <div id="ouvidoriaAlert" class="alert d-none" role="alert"></div>
                    <form id="ouvidoriaForm">
                        <div class="mb-3">
                            <label for="ouvidoriaMessage" class="form-label">Mensagem</label>
                            <textarea id="ouvidoriaMessage" class="form-control" rows="5" maxlength="1000" placeholder="Digite sua mensagem..."></textarea>
                            <div class="d-flex justify-content-between mt-2">
                                <small class="text-light-gray">Nao salvamos seu nome ou time.</small>
                                <small class="text-light-gray" id="ouvidoriaCounter">0/1000</small>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-orange" id="ouvidoriaSubmit">
                            <i class="bi bi-send me-2"></i>Enviar
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/sidebar.js?v=<?= time() ?>"></script>
    <script src="/js/pwa.js"></script>
    <script>
        const form = document.getElementById('ouvidoriaForm');
        const messageInput = document.getElementById('ouvidoriaMessage');
        const submitBtn = document.getElementById('ouvidoriaSubmit');
        const alertBox = document.getElementById('ouvidoriaAlert');
        const counter = document.getElementById('ouvidoriaCounter');

        const updateCounter = () => {
            const len = messageInput.value.length;
            counter.textContent = `${len}/1000`;
        };

        messageInput.addEventListener('input', updateCounter);
        updateCounter();

        const showAlert = (message, type) => {
            alertBox.textContent = message;
            alertBox.className = `alert alert-${type}`;
            alertBox.classList.remove('d-none');
        };

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const message = messageInput.value.trim();
            if (!message) {
                showAlert('Digite uma mensagem antes de enviar.', 'warning');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Enviando';

            try {
                const res = await fetch('/api/ouvidoria.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ message })
                });
                const data = await res.json();
                if (!res.ok || data.success === false) {
                    throw new Error(data.error || 'Falha ao enviar');
                }
                messageInput.value = '';
                updateCounter();
                showAlert('Mensagem enviada com sucesso.', 'success');
            } catch (err) {
                showAlert(err.message || 'Erro ao enviar mensagem.', 'danger');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-send me-2"></i>Enviar';
            }
        });
    </script>
</body>
</html>
