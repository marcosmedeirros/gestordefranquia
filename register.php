<?php
require_once __DIR__ . '/backend/db.php';
$pdo = db();

$token = trim($_GET['token'] ?? '');
$waitlistRow = null;
if ($token !== '') {
    $stmt = $pdo->prepare("SELECT * FROM waitlist_requests WHERE token = ? AND status != 'registered' LIMIT 1");
    $stmt->execute([$token]);
    $waitlistRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
	<meta name="theme-color" content="#fc0025">
	<title>FBA Manager - Cadastro</title>

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
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 24px;
		}
		.auth-card {
			width: 100%;
			max-width: 460px;
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
		.form-control {
			background: var(--panel-3) !important;
			border: 1px solid var(--border) !important;
			color: var(--text) !important;
			border-radius: var(--radius-sm);
			min-height: 46px;
		}
		.form-control:focus {
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
		.auth-links { font-size: 13px; color: var(--text-2); }
		.auth-links a { color: var(--red); font-weight: 700; }
	</style>
</head>
<body>

<?php if (!$waitlistRow): ?>
	<div class="auth-card">
		<div class="auth-head">
			<h2><i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i>Link inválido</h2>
		</div>
		<div class="auth-body">
			<p class="text-secondary">Esse link de cadastro não é válido ou já foi utilizado. Fale com o administrador da liga pra pedir um novo.</p>
			<a href="/login.php" class="btn btn-ghost w-100">Voltar pro login</a>
		</div>
	</div>
<?php else: ?>
	<div class="auth-card">
		<div class="auth-head">
			<h2><i class="bi bi-person-plus me-2 text-danger"></i>Criar conta</h2>
			<span class="chip">Liga ROOKIE</span>
		</div>
		<div class="auth-body">
			<div id="register-message"></div>
			<form id="form-register">
				<div class="mb-3">
					<label class="form-label">Nome completo</label>
					<input name="name" class="form-control" value="<?= htmlspecialchars($waitlistRow['name']) ?>" required>
				</div>
				<div class="mb-3">
					<label class="form-label">E-mail</label>
					<input name="email" type="email" class="form-control" placeholder="seu@email.com" required>
				</div>
				<div class="mb-3">
					<label class="form-label">Telefone (WhatsApp)</label>
					<input name="phone" type="tel" class="form-control" value="<?= htmlspecialchars($waitlistRow['phone']) ?>" required maxlength="13">
				</div>
				<div class="mb-3">
					<label class="form-label">Senha</label>
					<input id="registerPassword" name="password" type="password" class="form-control" placeholder="Mínimo 6 caracteres" required minlength="6">
				</div>
				<p class="text-secondary" style="font-size:12px">Você vai entrar na <strong>Liga ROOKIE</strong>.</p>
				<button type="submit" class="btn btn-auth w-100">
					<i class="bi bi-person-plus me-2"></i>Criar conta
				</button>
			</form>
		</div>
	</div>
	<script>
		document.getElementById('form-register').addEventListener('submit', async (e) => {
			e.preventDefault();
			const formData = new FormData(e.target);
			const data = {
				name: formData.get('name'),
				email: formData.get('email'),
				password: formData.get('password'),
				phone: (formData.get('phone') || '').replace(/\D/g, ''),
				waitlist_token: <?= json_encode($token) ?>
			};
			const msgEl = document.getElementById('register-message');
			try {
				const res = await fetch('/api/register.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify(data)
				});
				const body = await res.json().catch(() => ({}));
				if (!res.ok) throw body;
				msgEl.innerHTML = `<div class="alert alert-success">Cadastro concluído! Redirecionando...</div>`;
				setTimeout(() => { window.location.href = '/login.php'; }, 1500);
			} catch (err) {
				msgEl.innerHTML = `<div class="alert alert-danger">${err.error || 'Erro ao cadastrar'}</div>`;
			}
		});
	</script>
<?php endif; ?>

</body>
</html>
