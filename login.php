<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login - FBA Manager Control</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css" />
</head>
<body class="d-flex align-items-center min-vh-100">
    <?php
    // Mensagens de verificação
    if (isset($_GET['verified']) && $_GET['verified'] == '1') {
        echo '<div class="position-fixed top-0 start-50 translate-middle-x mt-3" style="z-index: 9999;">
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>E-mail verificado com sucesso! Faça login.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>';
    }
    ?>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-10 col-lg-8">
                <div class="text-center mb-4">
                    <img src="/img/fba-logo.png" alt="FBA" height="80" class="mb-3">
                    <h1 class="display-5 fw-bold text-white">FBA Manager Control</h1>
                    <p class="text-light-gray">Gerencie sua franquia de basquete fantasy</p>
                </div>

                <div class="row g-4">
                    <!-- Login Card -->
                    <div class="col-lg-6">
                        <div class="card bg-dark-panel border-orange h-100">
                            <div class="card-header bg-transparent border-orange">
                                <h4 class="mb-0"><i class="bi bi-box-arrow-in-right me-2 text-orange"></i>Login</h4>
                            </div>
                            <div class="card-body">
                                <form id="form-login">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="bi bi-envelope me-2"></i>E-mail</label>
                                        <input name="email" type="email" class="form-control bg-dark text-light" placeholder="seu@email.com" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label"><i class="bi bi-lock me-2"></i>Senha</label>
                                        <input name="password" type="password" class="form-control bg-dark text-light" placeholder="••••••••" required>
                                    </div>
                                    <button type="submit" class="btn btn-orange w-100">
                                        <i class="bi bi-box-arrow-in-right me-2"></i>Entrar
                                    </button>
                                </form>
                                <div id="login-message" class="mt-3"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Register Card -->
                    <div class="col-lg-6">
                        <div class="card bg-dark-panel border-orange h-100">
                            <div class="card-header bg-transparent border-orange">
                                <h4 class="mb-0"><i class="bi bi-person-plus me-2 text-orange"></i>Cadastro</h4>
                            </div>
                            <div class="card-body">
                                <form id="form-register">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="bi bi-person me-2"></i>Nome</label>
                                        <input name="name" class="form-control bg-dark text-light" placeholder="Seu nome completo" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label"><i class="bi bi-envelope me-2"></i>E-mail</label>
                                        <input name="email" type="email" class="form-control bg-dark text-light" placeholder="seu@email.com" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label"><i class="bi bi-trophy me-2 text-orange"></i>Liga</label>
                                        <select name="league" class="form-control bg-dark text-light" required>
                                            <option value="">Selecione sua liga</option>
                                            <option value="ROOKIE">🥉 ROOKIE - Iniciante</option>
                                            <option value="RISE">🥈 RISE - Intermediário</option>
                                            <option value="PRIME">🥇 PRIME - Avançado</option>
                                            <option value="ELITE">💎 ELITE - Elite</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label"><i class="bi bi-lock me-2"></i>Senha</label>
                                        <input name="password" type="password" class="form-control bg-dark text-light" placeholder="••••••••" required>
                                    </div>
                                    <button type="submit" class="btn btn-orange w-100">
                                        <i class="bi bi-person-plus me-2"></i>Criar conta
                                    </button>
                                </form>
                                <div id="register-message" class="mt-3"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <small class="text-light-gray">
                        <i class="bi bi-shield-check me-1"></i>
                        Após o cadastro, verifique seu e-mail para ativar sua conta
                    </small>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/login.js"></script>
</body>
</html>
