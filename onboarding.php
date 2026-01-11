<?php
require_once __DIR__ . '/backend/auth.php';
requireAuth();

$user = getUserSession();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
    <title>Configuração Inicial - FBA Manager</title>
    
    <!-- PWA Meta Tags -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0a0a0c">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="apple-touch-icon" href="/img/icon-192.png">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css" />
</head>
<body>
    <div class="container py-5">
        <div class="onboarding-container">
            <div class="text-center mb-5">
                <img src="/img/fba-logo.png" alt="FBA" height="80" class="mb-3">
                <h1 class="fw-bold text-white">Bem-vindo, <?= htmlspecialchars($user['name']) ?>!</h1>
                <p class="text-light-gray">Vamos configurar sua franquia em 3 passos simples</p>
            </div>

            <!-- Step Indicator -->
            <div class="step-indicator mb-5">
                <div class="step-item">
                    <div class="step active" id="step-indicator-1">1</div>
                    <span class="step-label">Perfil</span>
                </div>
                <div class="step-line"></div>
                <div class="step-item">
                    <div class="step" id="step-indicator-2">2</div>
                    <span class="step-label">Time</span>
                </div>
            </div>

            <!-- Step 1: Perfil do Usuário -->
            <div class="step-content active" id="step-1">
                <div class="card bg-dark-panel border-orange">
                    <div class="card-body p-4">
                        <h3 class="mb-4 text-white"><i class="bi bi-person-circle me-2 text-orange"></i>Seu Perfil</h3>
                        
                        <div class="row">
                            <div class="col-md-4 text-center mb-4">
                                <div class="photo-upload-container">
                                    <img src="<?= htmlspecialchars($user['photo_url'] ?? '/img/default-avatar.png') ?>" 
                                         alt="Foto" class="photo-preview" id="user-photo-preview">
                                    <label for="user-photo-upload" class="photo-upload-overlay">
                                        <i class="bi bi-camera-fill"></i>
                                        <span>Adicionar Foto</span>
                                    </label>
                                    <input type="file" id="user-photo-upload" class="d-none" accept="image/*">
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label text-white fw-bold">Nome</label>
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" readonly>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-white fw-bold">E-mail</label>
                                    <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" disabled aria-disabled="true" title="Seu e-mail não pode ser alterado">
                                    <small class="text-light-gray">Seu e-mail está vinculado à conta e não pode ser editado.</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-white fw-bold">Liga</label>
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($user['league']) ?>" readonly>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-end mt-4">
                            <button class="btn btn-orange btn-lg" onclick="nextStep(2)">
                                Próximo <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2: Dados do Time -->
            <div class="step-content" id="step-2">
                <div class="card bg-dark-panel border-orange">
                    <div class="card-body p-4">
                        <h3 class="mb-4 text-white"><i class="bi bi-trophy me-2 text-orange"></i>Dados do Seu Time</h3>
                        
                        <form id="form-team">
                            <div class="text-center mb-4">
                                <div class="photo-upload-container mx-auto" style="width: 150px;">
                                    <img src="/img/default-team.png" alt="Logo" class="photo-preview" id="team-photo-preview">
                                    <label for="team-photo-upload" class="photo-upload-overlay">
                                        <i class="bi bi-image-fill"></i>
                                        <span>Logo do Time</span>
                                    </label>
                                    <input type="file" id="team-photo-upload" class="d-none" accept="image/*">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-white fw-bold">Nome do Time *</label>
                                    <input type="text" name="name" class="form-control form-control-lg" placeholder="Ex: Lakers" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-white fw-bold">Cidade *</label>
                                    <input type="text" name="city" class="form-control form-control-lg" placeholder="Ex: Los Angeles" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-white fw-bold">Mascote</label>
                                <input type="text" name="mascot" class="form-control form-control-lg" placeholder="Ex: Águia Dourada">
                                <small class="text-light-gray">Opcional</small>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-white fw-bold">Conferência *</label>
                                    <select name="conference" class="form-select form-select-lg" required>
                                        <option value="">Selecione...</option>
                                        <option value="LESTE">LESTE</option>
                                        <option value="OESTE">OESTE</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3 d-flex align-items-end">
                                    <small class="text-light-gray">Usamos a conferência para organizar tabelas e confrontos.</small>
                                </div>
                            </div>
                        </form>
                        
                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-white btn-lg" onclick="prevStep(1)">
                                <i class="bi bi-arrow-left me-2"></i>Voltar
                            </button>
                            <button type="button" class="btn btn-orange btn-lg" onclick="saveTeamAndFinish()">
                                Concluir <i class="bi bi-check-circle ms-2"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>



        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/onboarding.js"></script>
    <script src="/js/pwa.js"></script>
</body>
</html>
