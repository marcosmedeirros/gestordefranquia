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
	<title>FBA Manager - Redefinir senha</title>

	<?php include __DIR__ . '/includes/head-pwa.php'; ?>

	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/css/styles.css" />

	<style>
		:root {
			--red: #fc0025;
			--bg: #07070a;
			--panel: #111116;
			--panel-2: #191921;
			--border: rgba(255,255,255,.08);
			--text: #f2f2f5;
			--text-2: #8c8c98;
			--font: 'Poppins', sans-serif;
		}

		html, body { height: 100%; }
		body {
			margin: 0;
			font-family: var(--font);
			color: var(--text);
			background:
				radial-gradient(900px 460px at 15% 10%, rgba(252,0,37,.16), transparent 55%),
				radial-gradient(900px 460px at 85% 95%, rgba(252,0,37,.09), transparent 55%),
				var(--bg);
		}

		.reset-shell {
			min-height: 100vh;
			display: grid;
			place-items: center;
			padding: 28px 14px;
		}

		.reset-wrap {
			width: 100%;
			max-width: 1040px;
			display: grid;
			grid-template-columns: 1fr 1fr;
			border: 1px solid var(--border);
			border-radius: 20px;
			overflow: hidden;
			background: linear-gradient(180deg, var(--panel-2), var(--panel));
			box-shadow: 0 20px 40px rgba(0,0,0,.35);
		}

		.brand-side {
			padding: 32px;
			border-right: 1px solid var(--border);
			display: flex;
			flex-direction: column;
			justify-content: center;
			background: linear-gradient(180deg, rgba(252,0,37,.08), transparent 45%);
		}
		.brand-logo { max-width: 340px; width: 100%; height: auto; }
		.brand-title { margin: 14px 0 0; font-size: 34px; font-weight: 800; line-height: 1.05; }
		.brand-sub { margin-top: 8px; color: var(--text-2); }
		.brand-tip {
			margin-top: 20px;
			border: 1px solid var(--border);
			border-radius: 12px;
			padding: 12px;
			color: var(--text-2);
			font-size: 13px;
		}

		.form-side { padding: 26px; display: flex; align-items: center; }

		.form-card {
			width: 100%;
			border: 1px solid var(--border);
			border-radius: 14px;
			background: rgba(0,0,0,.18);
			padding: 18px;
		}

		.form-head {
			display: flex;
			align-items: center;
			justify-content: space-between;
			margin-bottom: 14px;
			padding-bottom: 12px;
			border-bottom: 1px solid var(--border);
		}
		.form-head h2 { margin: 0; font-size: 20px; font-weight: 800; }
		.badge-red {
			border: 1px solid rgba(252,0,37,.3);
			background: rgba(252,0,37,.12);
			color: var(--red);
			border-radius: 999px;
			font-size: 11px;
			font-weight: 700;
			padding: 5px 10px;
		}

		.form-label {
			font-size: 11px;
			text-transform: uppercase;
			letter-spacing: .5px;
			color: var(--text-2);
			font-weight: 700;
			margin-bottom: 6px;
		}

		.form-control {
			background: #202028 !important;
			border: 1px solid var(--border) !important;
			color: var(--text) !important;
			min-height: 46px;
			border-radius: 10px;
		}
		.form-control:focus {
			border-color: var(--red) !important;
			box-shadow: 0 0 0 .2rem rgba(252,0,37,.15) !important;
		}

		.btn-main {
			width: 100%;
			min-height: 46px;
			border-radius: 10px;
			background: var(--red);
			border: 1px solid var(--red);
			color: #fff;
			font-size: 13px;
			font-weight: 700;
		}
		.btn-main:hover { filter: brightness(1.08); color: #fff; }

		.helper-link { margin-top: 12px; text-align: center; font-size: 13px; }
		.helper-link a { color: var(--red); font-weight: 700; text-decoration: none; }
		.helper-link a:hover { text-decoration: underline; }

		@media (max-width: 900px) {
			.reset-wrap { grid-template-columns: 1fr; }
			.brand-side { border-right: 0; border-bottom: 1px solid var(--border); }
			.brand-logo { max-width: 280px; }
			.brand-title { font-size: 28px; }
		}
	</style>
</head>
<body>
	<?php $token = htmlspecialchars($_GET['token'] ?? ''); ?>

	<div class="reset-shell">
		<div class="reset-wrap">
			<section class="brand-side">
				<img src="/img/fba-logo.png" alt="FBA 2K League Brasil" class="brand-logo" onerror="this.onerror=null;this.src='/img/logo-fba-preta.jpg';">
				<h1 class="brand-title">Nova senha, mesmo dominio da liga</h1>
				<p class="brand-sub">Defina uma senha forte para continuar gerindo seu time com seguranca.</p>
				<div class="brand-tip">
					<i class="bi bi-shield-check me-1"></i>
					Use no minimo 6 caracteres e evite senhas repetidas em outros servicos.
				</div>
			</section>

			<section class="form-side">
				<div class="form-card">
					<div class="form-head">
						<h2><i class="bi bi-key me-2 text-danger"></i>Redefinir senha</h2>
						<span class="badge-red">Seguranca</span>
					</div>

					<div id="msg"></div>

					<form id="formReset">
						<input type="hidden" name="token" value="<?= $token ?>">

						<div class="mb-3">
							<label class="form-label">Nova senha</label>
							<input name="password" type="password" class="form-control" required minlength="6" placeholder="Digite sua nova senha">
						</div>

						<button type="submit" class="btn btn-main">
							<i class="bi bi-check-circle me-2"></i>Salvar nova senha
						</button>
					</form>

					<div class="helper-link">
						<a href="/login.php"><i class="bi bi-arrow-left me-1"></i>Voltar para o login</a>
					</div>
				</div>
			</section>
		</div>
	</div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
	<script>
		document.getElementById('formReset').addEventListener('submit', async (e) => {
			e.preventDefault();
			const form = e.currentTarget;
			const msg = document.getElementById('msg');
			msg.innerHTML = '';

			const fd = new FormData(form);
			const payload = {
				token: fd.get('token'),
				password: fd.get('password')
			};

			try {
				const res = await fetch('/api/reset-password-confirm.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify(payload)
				});

				const data = await res.json();
				if (!res.ok || !data.success) {
					throw new Error(data.error || 'Falha ao redefinir senha');
				}

				msg.innerHTML = '<div class="alert alert-success">Senha redefinida com sucesso. Redirecionando...</div>';
				setTimeout(() => window.location.href = '/login.php', 1400);
			} catch (err) {
				msg.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
			}
		});
	</script>
</body>
</html>
