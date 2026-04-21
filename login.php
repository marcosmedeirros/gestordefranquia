<!DOCTYPE html>
<html lang="pt-BR">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
	<meta name="theme-color" content="#fc0025">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<meta name="apple-mobile-web-app-title" content="FBA Manager">
	<meta name="mobile-web-app-capable" content="yes">
	<link rel="manifest" href="/manifest.json">
	<link rel="apple-touch-icon" href="/img/fba-logo.png">
	<title>FBA Manager - Login</title>

	<?php
	$headPwaPath = __DIR__ . '/includes/head-pwa.php';
	if (!is_file($headPwaPath)) {
		$headPwaPath = __DIR__ . '/../includes/head-pwa.php';
	}
	if (is_file($headPwaPath)) {
		include $headPwaPath;
	}
	?>

	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/css/styles.css" />

	<style>
		:root {
			--red: #fc0025;
			--red-2: #ff2a44;
			--bg: #07070a;
			--panel: #101013;
			--panel-2: #16161a;
			--panel-3: #1c1c21;
			--border: rgba(255,255,255,.08);
			--text: #f0f0f3;
			--text-2: #8d8d98;
			--font: 'Poppins', sans-serif;
			--radius: 16px;
			--radius-sm: 10px;
		}

		html, body { height: 100%; }
		body {
			margin: 0;
			font-family: var(--font);
			background:
				radial-gradient(1200px 500px at 12% 8%, rgba(252,0,37,.16), transparent 55%),
				radial-gradient(1000px 420px at 88% 90%, rgba(252,0,37,.08), transparent 55%),
				var(--bg);
			color: var(--text);
		}

		.auth-shell {
			min-height: 100vh;
			display: grid;
			grid-template-columns: 1.1fr 1fr;
			gap: 0;
		}

		.brand-side {
			padding: 48px;
			display: flex;
			align-items: center;
			justify-content: center;
		}

		.brand-wrap { max-width: 560px; }
		.brand-logo-wrap {
			max-width: 500px;
			width: 100%;
		}
		.brand-logo {
			position: static;
			width: 100%;
			height: auto;
			object-fit: contain;
			display: block;
		}
		.brand-kicker {
			margin-top: 18px;
			font-size: 11px;
			letter-spacing: 1.4px;
			text-transform: uppercase;
			color: var(--red);
			font-weight: 700;
		}
		.brand-title { margin: 6px 0 0; font-size: 38px; font-weight: 800; line-height: 1.06; }
		.brand-sub { margin: 10px 0 0; color: var(--text-2); font-size: 14px; }

		.brand-points {
			margin-top: 26px;
			display: grid;
			grid-template-columns: repeat(3, minmax(0, 1fr));
			gap: 10px;
		}
		.point-card {
			background: linear-gradient(180deg, var(--panel-2), var(--panel));
			border: 1px solid var(--border);
			border-radius: 12px;
			padding: 12px;
			text-align: center;
		}
		.point-card i { color: var(--red); font-size: 20px; }
		.point-card span { display: block; margin-top: 4px; color: var(--text-2); font-size: 12px; }

		.form-side {
			padding: 38px 24px;
			display: flex;
			align-items: center;
			justify-content: center;
		}

		.auth-card {
			width: 100%;
			max-width: 500px;
			background: linear-gradient(180deg, var(--panel-2), var(--panel));
			border: 1px solid var(--border);
			border-radius: var(--radius);
			box-shadow: 0 18px 40px rgba(0,0,0,.35);
			overflow: hidden;
		}

		.auth-head {
			padding: 18px 20px;
			border-bottom: 1px solid var(--border);
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 10px;
		}
		.auth-head h2 { margin: 0; font-size: 18px; font-weight: 800; }
		.chip {
			border: 1px solid rgba(252,0,37,.3);
			color: var(--red);
			background: rgba(252,0,37,.10);
			border-radius: 999px;
			padding: 5px 10px;
			font-size: 11px;
			font-weight: 700;
		}

		.auth-body { padding: 20px; }

		.form-label {
			font-size: 11px;
			text-transform: uppercase;
			letter-spacing: .5px;
			color: var(--text-2);
			font-weight: 700;
			margin-bottom: 6px;
		}

		.form-control, .form-select {
			background: var(--panel-3) !important;
			border: 1px solid var(--border) !important;
			color: var(--text) !important;
			border-radius: var(--radius-sm);
			min-height: 46px;
		}
		.form-control:focus, .form-select:focus {
			border-color: var(--red) !important;
			box-shadow: 0 0 0 .2rem rgba(252,0,37,.15) !important;
		}
		.form-control::placeholder { color: #6d6d78; }

		.btn-auth {
			background: var(--red);
			border: 1px solid var(--red);
			color: #fff;
			border-radius: 10px;
			font-size: 13px;
			font-weight: 700;
			min-height: 46px;
		}
		.btn-auth:hover { filter: brightness(1.08); color: #fff; }

		.btn-ghost {
			background: transparent;
			border: 1px solid var(--border);
			color: var(--text-2);
			border-radius: 10px;
			min-height: 46px;
			font-weight: 700;
		}
		.btn-ghost:hover { background: var(--panel-3); color: var(--text); }

		.auth-links { font-size: 13px; color: var(--text-2); }
		.auth-links a { color: var(--red); font-weight: 700; }

		.modal-content {
			background: linear-gradient(180deg, var(--panel-2), var(--panel)) !important;
			border: 1px solid var(--border) !important;
			border-radius: 14px !important;
			color: var(--text);
		}
		.modal-header, .modal-footer { border-color: var(--border) !important; }

		@media (max-width: 1024px) {
			.auth-shell { grid-template-columns: 1fr; }
			.brand-side { padding: 26px 16px 6px; }
			.brand-wrap { text-align: center; }
			.brand-logo-wrap { max-width: 320px; margin: 0 auto; }
			.brand-title { font-size: 28px; }
			.brand-points { grid-template-columns: 1fr 1fr 1fr; }
			.form-side { padding: 12px 16px 26px; }
			.auth-card { max-width: 620px; }
		}

		@media (max-width: 580px) {
			.brand-points { grid-template-columns: 1fr; }
			.auth-head { flex-direction: column; align-items: flex-start; }
		}
	</style>
</head>
<body>
	<?php
	if (isset($_GET['verified']) && $_GET['verified'] == '1') {
		echo '<div class="position-fixed top-0 start-50 translate-middle-x mt-3" style="z-index: 9999; width: min(92vw, 620px);">
			<div class="alert alert-success alert-dismissible fade show" role="alert">
				<i class="bi bi-check-circle-fill me-2"></i>E-mail verificado com sucesso! Faca login.
				<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
			</div>
		</div>';
	}
	?>

	<div class="auth-shell">
		<section class="brand-side">
			<div class="brand-wrap">
				<div class="brand-logo-wrap">
					<img src="/img/fba-logo.png?v=3"
					     alt="FBA 2K League Brasil"
					     class="brand-logo"
					     width="6480"
					     height="3240"
					     decoding="async"
					     loading="eager"
					     onerror="this.onerror=null;this.src='/img/logo-fba-preta.jpg?v=3';">
				</div>
				<div class="brand-kicker">Plataforma oficial</div>
				<h1 class="brand-title">Gestao completa da sua franquia</h1>
				<p class="brand-sub">Controle roster, trades, draft e estrategia da liga em um unico painel.</p>
				<div class="brand-points">
					<div class="point-card"><i class="bi bi-people-fill"></i><span>Franquias</span></div>
					<div class="point-card"><i class="bi bi-trophy-fill"></i><span>4 ligas</span></div>
					<div class="point-card"><i class="bi bi-arrow-left-right"></i><span>Mercado ativo</span></div>
				</div>
			</div>
		</section>

		<section class="form-side">
			<div class="auth-card">
				<div class="auth-head" id="auth-head-login">
					<h2><i class="bi bi-box-arrow-in-right me-2 text-danger"></i>Entrar</h2>
					<span class="chip">Acesso</span>
				</div>

				<div class="auth-body">
					<div id="login-form-container">
						<div id="login-message"></div>
						<form id="form-login">
							<div class="mb-3">
								<label class="form-label">E-mail</label>
								<input name="email" type="email" class="form-control" placeholder="seu@email.com" required>
							</div>
							<div class="mb-3">
								<label class="form-label">Senha</label>
								<div class="input-group">
									<input id="loginPassword" name="password" type="password" class="form-control" placeholder="Digite sua senha" required>
									<button class="btn btn-ghost" type="button" id="toggleLoginPassword" aria-label="Mostrar ou ocultar senha">
										<i class="bi bi-eye"></i>
									</button>
								</div>
							</div>
							<button type="submit" class="btn btn-auth w-100 mb-3">
								<i class="bi bi-box-arrow-in-right me-2"></i>Entrar na conta
							</button>
						</form>

						<div class="text-center auth-links">
							<a href="#" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal"><i class="bi bi-key me-1"></i>Esqueci a senha</a>
							<p class="mb-0 mt-2">Nao tem conta?
								<a href="#" onclick="showRegisterForm(); return false;">Criar agora</a>
							</p>
						</div>
					</div>

					<div id="register-form-container" style="display: none;">
						<div id="register-message"></div>
						<form id="form-register">
							<div class="mb-3">
								<label class="form-label">Nome completo</label>
								<input name="name" class="form-control" placeholder="Seu nome completo" required>
							</div>
							<div class="mb-3">
								<label class="form-label">E-mail</label>
								<input name="email" type="email" class="form-control" placeholder="seu@email.com" required>
							</div>
							<div class="mb-3">
								<label class="form-label">Telefone (WhatsApp)</label>
								<input name="phone" type="tel" class="form-control" placeholder="Ex.: 55999999999" required maxlength="13">
								<small class="text-secondary">Digite apenas numeros (DDD + telefone).</small>
							</div>
							<div class="mb-3">
								<label class="form-label">Senha</label>
								<div class="input-group">
									<input id="registerPassword" name="password" type="password" class="form-control" placeholder="Minimo 6 caracteres" required minlength="6">
									<button class="btn btn-ghost" type="button" id="toggleRegisterPassword" aria-label="Mostrar ou ocultar senha">
										<i class="bi bi-eye"></i>
									</button>
								</div>
							</div>
							<div class="mb-3">
								<label class="form-label">Liga</label>
								<select name="league" class="form-select" required>
									<option value="">Selecione sua liga</option>
									<option value="ROOKIE">ROOKIE - Liga Rookie</option>
									<option value="RISE">RISE - Liga Rise</option>
									<option value="NEXT">NEXT - Liga Next</option>
									<option value="ELITE">ELITE - Liga Elite</option>
								</select>
							</div>
							<button type="submit" class="btn btn-auth w-100 mb-3">
								<i class="bi bi-person-plus me-2"></i>Criar conta
							</button>
						</form>
						<div class="text-center auth-links">
							<p class="mb-0">Ja tem conta?
								<a href="#" onclick="showLoginForm(); return false;">Fazer login</a>
							</p>
						</div>
					</div>
				</div>
			</div>
		</section>
	</div>

	<div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-labelledby="forgotPasswordModalLabel" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="forgotPasswordModalLabel"><i class="bi bi-key me-2 text-danger"></i>Recuperar senha</h5>
					<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<p class="text-secondary mb-3">Digite seu e-mail cadastrado para receber o link de redefinicao.</p>
					<div id="forgot-password-message"></div>
					<form id="form-forgot-password">
						<div class="mb-3">
							<label class="form-label">E-mail</label>
							<input type="email" name="email" class="form-control" placeholder="seu@email.com" required>
						</div>
						<button type="submit" class="btn btn-auth w-100">
							<i class="bi bi-envelope me-2"></i>Enviar link de recuperacao
						</button>
					</form>
				</div>
			</div>
		</div>
	</div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
	<script src="/js/login.js"></script>
	<script src="/js/pwa.js"></script>
	<script>
		function showRegisterForm() {
			document.getElementById('login-form-container').style.display = 'none';
			document.getElementById('register-form-container').style.display = 'block';
			document.getElementById('auth-head-login').innerHTML = '<h2><i class="bi bi-person-plus me-2 text-danger"></i>Criar conta</h2><span class="chip">Cadastro</span>';
		}

		function showLoginForm() {
			document.getElementById('register-form-container').style.display = 'none';
			document.getElementById('login-form-container').style.display = 'block';
			document.getElementById('auth-head-login').innerHTML = '<h2><i class="bi bi-box-arrow-in-right me-2 text-danger"></i>Entrar</h2><span class="chip">Acesso</span>';
		}

		document.addEventListener('DOMContentLoaded', () => {
			const bindToggle = (btnId, inputId) => {
				const btn = document.getElementById(btnId);
				const input = document.getElementById(inputId);
				if (!btn || !input) return;
				btn.addEventListener('click', () => {
					const isPwd = input.type === 'password';
					input.type = isPwd ? 'text' : 'password';
					const icon = btn.querySelector('i');
					if (icon) icon.className = isPwd ? 'bi bi-eye-slash' : 'bi bi-eye';
				});
			};
			bindToggle('toggleLoginPassword', 'loginPassword');
			bindToggle('toggleRegisterPassword', 'registerPassword');
		});
	</script>
</body>
</html>
