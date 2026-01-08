<?php
require_once __DIR__ . '/backend/auth.php';
requireAuth();

$user = getUserSession();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Configuração Inicial - FBA Manager</title>
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
                <div class="step active" id="step-indicator-1">1</div>
                <div class="step" id="step-indicator-2">2</div>
                <div class="step" id="step-indicator-3">3</div>
            </div>

            <!-- Step 1: Perfil do Usuário -->
            <div class="step-content active" id="step-1">
                <div class="card bg-dark-panel border-orange">
                    <div class="card-body p-4">
                        <h3 class="mb-4 text-white"><i class="bi bi-person-circle me-2 text-orange"></i>Seu Perfil</h3>
                        
                        <div class="row">
                            <div class="col-md-4 text-center mb-4">
                                <img src="<?= htmlspecialchars($user['photo_url'] ?? '/img/default-avatar.png') ?>" 
                                     alt="Foto" class="team-avatar" id="user-photo-preview">
                                <label for="user-photo-upload" class="btn btn-outline-orange btn-sm mt-2">
                                    <i class="bi bi-camera me-1"></i>Alterar Foto
                                </label>
                                <input type="file" id="user-photo-upload" class="d-none" accept="image/*">
                            </div>
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label text-light-gray">Nome</label>
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" readonly>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-light-gray">E-mail</label>
                                    <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" readonly>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-light-gray">Liga</label>
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
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-light-gray">Nome do Time *</label>
                                    <input type="text" name="name" class="form-control form-control-lg" placeholder="Ex: Lakers" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-light-gray">Cidade *</label>
                                    <input type="text" name="city" class="form-control form-control-lg" placeholder="Ex: Los Angeles" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-light-gray">Mascote</label>
                                <input type="text" name="mascot" class="form-control form-control-lg" placeholder="Ex: Águia Dourada">
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-light-gray">URL da Logo do Time</label>
                                <input type="url" name="photo_url" class="form-control form-control-lg" placeholder="https://...">
                                <small class="text-muted">Cole o link de uma imagem para a logo do seu time</small>
                            </div>
                        </form>
                        
                        <div class="d-flex justify-content-between mt-4">
                            <button class="btn btn-outline-orange btn-lg" onclick="prevStep(1)">
                                <i class="bi bi-arrow-left me-2"></i>Voltar
                            </button>
                            <button class="btn btn-orange btn-lg" onclick="saveTeamAndNext()">
                                Próximo <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 3: Cadastro do Elenco -->
            <div class="step-content" id="step-3">
                <div class="card bg-dark-panel border-orange">
                    <div class="card-body p-4">
                        <h3 class="mb-4 text-white"><i class="bi bi-people me-2 text-orange"></i>Monte Seu Elenco</h3>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            Você pode adicionar jogadores agora ou fazer isso mais tarde no dashboard.
                        </div>
                        
                        <form id="form-player">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-light-gray">Nome do Jogador</label>
                                    <input type="text" name="name" class="form-control" placeholder="Ex: LeBron James">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label text-light-gray">Idade</label>
                                    <input type="number" name="age" class="form-control" placeholder="25">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label text-light-gray">OVR</label>
                                    <input type="number" name="ovr" class="form-control" placeholder="85" max="99">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-light-gray">Posição</label>
                                    <select name="position" class="form-select">
                                        <option value="PG">PG - Point Guard</option>
                                        <option value="SG">SG - Shooting Guard</option>
                                        <option value="SF">SF - Small Forward</option>
                                        <option value="PF">PF - Power Forward</option>
                                        <option value="C">C - Center</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label text-light-gray">Função</label>
                                    <select name="role" class="form-select">
                                        <option value="Titular">Titular</option>
                                        <option value="Banco">Banco</option>
                                        <option value="Outro">Outro</option>
                                        <option value="G-League">G-League</option>
                                    </select>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-outline-orange mb-3">
                                <i class="bi bi-plus-circle me-2"></i>Adicionar Jogador
                            </button>
                        </form>
                        
                        <div id="players-list" class="mt-4"></div>
                        
                        <div class="d-flex justify-content-between mt-4">
                            <button class="btn btn-outline-orange btn-lg" onclick="prevStep(2)">
                                <i class="bi bi-arrow-left me-2"></i>Voltar
                            </button>
                            <button class="btn btn-orange btn-lg" onclick="finishOnboarding()">
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
</body>
</html>
