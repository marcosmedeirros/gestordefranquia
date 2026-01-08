<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>FBA Manager Control - Sistema de Gestão de Franquias</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css" />
</head>
<body class="login-page">
    <?php
    if (isset($_GET['verified']) && $_GET['verified'] == '1') {
        echo '<div class="position-fixed top-0 start-50 translate-middle-x mt-3" style="z-index: 9999;">
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>E-mail verificado com sucesso! Faça login.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>';
    }
    ?>
    
    <div class="container-fluid vh-100">
        <div class="row h-100">
            <!-- Left Side - Branding -->
            <div class="col-lg-6 d-flex align-items-center justify-content-center bg-gradient-dark p-5">
                <div class="text-center text-white">
                    <img src="/img/fba-logo.png" alt="FBA Manager" class="img-fluid mb-4" style="max-height: 180px;">
                    <h1 class="display-4 fw-bold mb-3">FBA Manager Control</h1>
                    <p class="lead mb-4 text-light-gray">
                        Sistema completo de gestão da sua franquia de basquete.<br>
                        Gerencie times, jogadores, drafts e muito mais em um só lugar.
                    </p>
                    <div class="d-flex justify-content-center gap-5 mt-5">
                        <div>
                            <i class="bi bi-people-fill display-6 text-orange mb-2 d-block"></i>
                            <p class="mb-0 text-light-gray">Gestão de Times</p>
                        </div>
                        <div>
                            <i class="bi bi-trophy-fill display-6 text-orange mb-2 d-block"></i>
                            <p class="mb-0 text-light-gray">4 Ligas</p>
                        </div>
                        <div>
                            <i class="bi bi-graph-up-arrow display-6 text-orange mb-2 d-block"></i>
                            <p class="mb-0 text-light-gray">Estatísticas</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side - Login/Register Forms -->
            <div class="col-lg-6 d-flex align-items-center justify-content-center p-5 bg-dark">
                <div class="w-100" style="max-width: 450px;">
                    
                    <!-- Login Form -->
                    <div id="login-form-container">
                        <h2 class="mb-4 fw-bold text-white">Entrar na sua conta</h2>
                        
                        <div id="login-message"></div>
                        
                        <form id="form-login">
                            <div class="mb-3">
                                <label class="form-label text-light-gray">E-mail</label>
                                <input name="email" type="email" class="form-control form-control-lg" placeholder="seu@email.com" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-light-gray">Senha</label>
                                <input name="password" type="password" class="form-control form-control-lg" placeholder="••••••••" required>
                            </div>
                            <button type="submit" class="btn btn-orange btn-lg w-100 mb-3">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Entrar
                            </button>
                        </form>
                        
                        <div class="text-center">
                            <a href="#" class="text-orange text-decoration-none d-block mb-3" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal">
                                <i class="bi bi-key me-1"></i>Esqueceu a senha?
                            </a>
                            <p class="text-light-gray mb-0">
                                Não tem uma conta?
                                <a href="#" class="text-orange text-decoration-none fw-bold" onclick="showRegisterForm(); return false;">
                                    Quero me cadastrar
                                </a>
                            </p>
                        </div>
                    </div>

                    <!-- Register Form -->
                    <div id="register-form-container" style="display: none;">
                        <h2 class="mb-4 fw-bold text-white">Criar nova conta</h2>
                        
                        <div id="register-message"></div>
                        
                        <form id="form-register">
                            <div class="mb-3">
                                <label class="form-label text-light-gray">Nome completo</label>
                                <input name="name" class="form-control form-control-lg" placeholder="Seu nome completo" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-light-gray">E-mail</label>
                                <input name="email" type="email" class="form-control form-control-lg" placeholder="seu@email.com" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-light-gray">Senha</label>
                                <input name="password" type="password" class="form-control form-control-lg" placeholder="Mínimo 6 caracteres" required minlength="6">
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-light-gray">Liga</label>
                                <select name="league" class="form-select form-select-lg" required>
                                    <option value="">Selecione sua liga</option>
                                    <option value="ROOKIE">🌱 ROOKIE - Liga Rookie</option>
                                    <option value="RISE">🌟 RISE - Liga Rise</option>
                                    <option value="PRIME">💎 PRIME - Liga Prime</option>
                                    <option value="ELITE">🏆 ELITE - Liga Elite</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-orange btn-lg w-100 mb-3">
                                <i class="bi bi-person-plus me-2"></i>Criar conta
                            </button>
                        </form>
                        
                        <div class="text-center">
                            <p class="text-light-gray mb-0">
                                Já tem uma conta?
                                <a href="#" class="text-orange text-decoration-none fw-bold" onclick="showLoginForm(); return false;">
                                    Fazer login
                                </a>
                            </p>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-labelledby="forgotPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark-panel border-orange">
                <div class="modal-header border-orange">
                    <h5 class="modal-title text-white" id="forgotPasswordModalLabel">
                        <i class="bi bi-key me-2 text-orange"></i>Recuperar Senha
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-light-gray mb-4">Digite seu e-mail cadastrado e enviaremos um link para redefinir sua senha.</p>
                    
                    <div id="forgot-password-message"></div>
                    
                    <form id="form-forgot-password">
                        <div class="mb-3">
                            <label class="form-label text-light-gray">E-mail</label>
                            <input type="email" name="email" class="form-control form-control-lg" placeholder="seu@email.com" required>
                        </div>
                        <button type="submit" class="btn btn-orange w-100">
                            <i class="bi bi-envelope me-2"></i>Enviar Link de Recuperação
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/login.js"></script>
    <script>
        function showRegisterForm() {
            document.getElementById('login-form-container').style.display = 'none';
            document.getElementById('register-form-container').style.display = 'block';
        }
        
        function showLoginForm() {
            document.getElementById('register-form-container').style.display = 'none';
            document.getElementById('login-form-container').style.display = 'block';
        }
    </script>
</body>
</html>
