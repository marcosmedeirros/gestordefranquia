<?php
/**
 * rookie-sorteio.php
 *
 * Onde o GM cadastrado na ROOKIE cai enquanto não tem time — requireAuth()
 * manda pra cá (ver backend/auth.php) até existir uma linha dele em teams.
 *
 * Três estados, tudo decidido no client via polling em
 * api/drafts-aleatorios.php?action=meu_draft_marca (sem id — ela acha
 * sozinha o draft de marca onde o usuário tem uma pick pendente):
 *   1. Sem draft ainda: aguardando o admin sortear a ordem (roleta.php).
 *   2. Draft rolando, mas não é a vez dele: fila ao vivo.
 *   3. É a vez dele: escolhe o time da NBA (catálogo fechado, sem repetir).
 * Assim que a escolha é registrada, teams ganha a linha dele e o próximo
 * load de qualquer página normal já passa direto pelo gate.
 */
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/nba_teams.php';
requireAuth();

$pdo = db();
$user = getUserSession();

// O admin da ROOKIE acompanha o sorteio sem participar dele: ele tem time, então
// caía no redirect abaixo e não conseguia abrir a página de jeito nenhum.
$ehAdminRookie = hasAdminAccess($pdo, (int)$user['id'])
    && in_array('ROOKIE', array_map('strtoupper', getAdminLeagues($pdo, (int)$user['id'])), true);

// Já tem time? Não devia ter caído aqui, mas se caiu (favorito antigo,
// aba antiga aberta), manda pro dashboard em vez de mostrar sorteio de novo.
if (!$ehAdminRookie) {
    $stmt = $pdo->prepare('SELECT 1 FROM teams WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    if ($stmt->fetchColumn()) {
        header('Location: /dashboard.php');
        exit;
    }
}

$nbaTeamsList = nbaTeams();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
	<meta name="theme-color" content="#fc0025">
	<title>FBA Manager - Sorteio da ROOKIE</title>

	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
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
			--green: #22c55e;
			--font: 'Montserrat', sans-serif;
			--radius: 16px;
			--radius-sm: 10px;
		}
		:root[data-theme="light"] {
			--bg: #f6f7fb;
			--panel: #ffffff;
			--panel-2: #f2f4f8;
			--panel-3: #e9edf4;
			--border: #e3e6ee;
			--text: #111217;
			--text-2: #5b6270;
		}
		html, body { min-height: 100%; }
		body {
			margin: 0;
			font-family: var(--font);
			background:
				radial-gradient(1200px 500px at 12% 8%, color-mix(in srgb, var(--red) 16%, transparent), transparent 55%),
				radial-gradient(1000px 420px at 88% 90%, color-mix(in srgb, var(--red) 8%, transparent), transparent 55%),
				var(--bg);
			color: var(--text);
			min-height: 100vh;
			display: flex;
			align-items: flex-start;
			justify-content: center;
			padding: 40px 20px;
		}
		.rs-wrap { width: 100%; max-width: 620px; }
		.rs-head { text-align: center; margin-bottom: 24px; }
		.rs-logo { width: 52px; height: 52px; border-radius: 15px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 16px; color: #fff; margin: 0 auto 14px; }
		.rs-title { font-size: 21px; font-weight: 800; }
		.rs-sub { font-size: 13px; color: var(--text-2); margin-top: 4px; }

		.rs-card { background: linear-gradient(180deg, var(--panel-2), var(--panel)); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
		.rs-card-head { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
		.rs-card-head i { color: var(--red); font-size: 18px; }
		.rs-card-head span { font-weight: 700; font-size: 14px; }
		.rs-card-body { padding: 20px; }

		.rs-spinner { width: 34px; height: 34px; border: 3px solid var(--border); border-top-color: var(--red); border-radius: 50%; margin: 0 auto 16px; animation: rs-spin 1s linear infinite; }
		@keyframes rs-spin { to { transform: rotate(360deg); } }

		.rs-lista { display: flex; flex-direction: column; gap: 8px; margin-top: 14px; }
		.rs-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; background: var(--panel-3); border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; }
		.rs-item img { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; flex-shrink: 0; background: var(--panel); }
		.rs-item.rs-vez { border-color: var(--red); background: color-mix(in srgb, var(--red) 10%, var(--panel-3)); }
		.rs-item.rs-feito { opacity: .55; }
		.rs-item .rs-tag { margin-left: auto; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-2); }
		.rs-item.rs-vez .rs-tag { color: var(--red); }
		.rs-item.rs-feito .rs-tag { color: var(--green); }
		.rs-item .rs-num { width: 22px; height: 22px; border-radius: 50%; background: var(--panel); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: var(--text-2); flex-shrink: 0; }

		.rs-select { background: var(--panel-3) !important; border: 1px solid var(--border) !important; color: var(--text) !important; border-radius: var(--radius-sm); min-height: 46px; }
		.rs-select:focus { border-color: var(--red) !important; box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--red) 15%, transparent) !important; }
		.rs-preview { display: none; align-items: center; gap: 10px; margin-top: 12px; background: var(--panel-3); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 10px 12px; }
		.rs-preview.show { display: flex; }
		.rs-preview img { width: 34px; height: 34px; object-fit: contain; flex-shrink: 0; }
		.rs-preview-name { font-size: 13px; font-weight: 700; }
		.rs-preview-conf { font-size: 11px; color: var(--text-2); }

		.btn-rs { background: var(--red); border: 1px solid var(--red); color: #fff; border-radius: 10px; font-size: 13px; font-weight: 700; min-height: 46px; width: 100%; margin-top: 14px; }
		.btn-rs:hover:not(:disabled) { filter: brightness(1.08); color: #fff; }
		.btn-rs:disabled { opacity: .55; cursor: not-allowed; }

		.rs-empty { text-align: center; padding: 10px 0; color: var(--text-2); font-size: 13px; }

		/* Card de "já escolheu": aparece acima de tudo assim que o time é confirmado,
		   com a saída pro app. Antes a página redirecionava sozinha em 1,8s. */
		.rs-pronto {
			display: flex; align-items: center; gap: 14px;
			background: var(--panel); border: 1px solid var(--border);
			border-left: 3px solid var(--green);
			border-radius: var(--radius); padding: 16px 18px; margin-bottom: 16px;
		}
		.rs-pronto-logo { width: 46px; height: 46px; object-fit: contain; flex-shrink: 0; }
		.rs-pronto-txt { flex: 1; min-width: 0; }
		.rs-pronto-label { font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: var(--green); margin-bottom: 2px; }
		.rs-pronto-time { font-size: 15px; font-weight: 800; line-height: 1.25; }
		.rs-pronto-sub { font-size: 12px; color: var(--text-2); margin-top: 2px; }
		.rs-pronto-btn {
			background: var(--red); border: 1px solid var(--red); color: #fff;
			border-radius: var(--radius-sm); font-size: 13px; font-weight: 700;
			padding: 11px 16px; text-decoration: none; white-space: nowrap; flex-shrink: 0;
			display: inline-flex; align-items: center; gap: 7px;
		}
		.rs-pronto-btn:hover { filter: brightness(1.08); color: #fff; }
		@media (max-width: 520px) {
			.rs-pronto { flex-wrap: wrap; }
			.rs-pronto-btn { width: 100%; justify-content: center; }
		}
	</style>
</head>
<body>

<div class="rs-wrap">
	<div class="rs-head">
		<div class="rs-logo">FBA</div>
		<h1 class="rs-title">Sorteio da liga ROOKIE</h1>
		<p class="rs-sub"><?= $ehAdminRookie
			? 'Acompanhando o sorteio como admin da liga.'
			: 'Olá, ' . htmlspecialchars(explode(' ', $user['name'])[0]) . '! Falta só escolher seu time.' ?></p>
	</div>

	<?php /* Preenchido só pra quem já confirmou o time (ver renderFinalizado). */ ?>
	<div id="rsTopo"></div>

	<div class="rs-card">
		<div class="rs-card-head">
			<i class="bi bi-shuffle" id="rsHeadIcon"></i>
			<span id="rsHeadTitle">Carregando...</span>
		</div>
		<div class="rs-card-body" id="rsBody">
			<div class="rs-spinner"></div>
			<p class="rs-empty">Verificando o sorteio da liga...</p>
		</div>
	</div>
</div>

<script>
	const NBA_TEAMS = <?= json_encode($nbaTeamsList, JSON_UNESCAPED_UNICODE) ?>;
	const NBA_LOGO = (id) => `https://cdn.nba.com/logos/nba/${id}/global/L/logo.svg`;
	const MEU_USER_ID = <?= (int)$user['id'] ?>;
	const MEU_NOME = <?= json_encode($user['name'], JSON_UNESCAPED_UNICODE) ?>;
	// Admin da ROOKIE abre a mesma tela, mas não tem pick: vê a fila em modo
	// espectador. Como ele não está em picks[], a lógica de estado já cai sozinha
	// no renderFila — o que muda aqui é só o texto, que senão fala do "seu time".
	const SOU_ADMIN_ROOKIE = <?= $ehAdminRookie ? 'true' : 'false' ?>;
	let escolhendo = false;
	let redirecionando = false; // trava a contagem pro app (ver renderFinalizado)

	function avatarUrl(nome, foto) {
		if (foto) return foto;
		return `https://ui-avatars.com/api/?name=${encodeURIComponent(nome)}&background=1c1c21&color=fc0025`;
	}

	function renderEsperando(esperando) {
		document.getElementById('rsHeadIcon').className = 'bi bi-hourglass-split';
		document.getElementById('rsHeadTitle').textContent = 'Aguardando o sorteio';
		const body = document.getElementById('rsBody');
		const outros = esperando.filter(u => Number(u.id) !== MEU_USER_ID);
		// O admin não entra na fila (a API já exclui admins da lista de espera),
		// então ele não aparece como "(você)" no topo — só vê quem está esperando.
		const total = outros.length + (SOU_ADMIN_ROOKIE ? 0 : 1);
		body.innerHTML = `
			<p style="font-size:13px;color:var(--text-2);margin-bottom:4px">${SOU_ADMIN_ROOKIE
				? 'O sorteio da ordem ainda não começou. Gire a roleta da ROOKIE e depois crie o sorteio de times da NBA — esta tela atualiza sozinha.'
				: 'O admin da liga ainda não iniciou o sorteio da ordem. Assim que ele girar a roleta, esta tela atualiza sozinha.'}</p>
			${SOU_ADMIN_ROOKIE ? `<p style="font-size:12px;margin-bottom:10px"><a href="/roleta.php" style="color:var(--red)">Ir pras roletas <i class="bi bi-box-arrow-up-right"></i></a></p>` : ''}
			<div class="rs-lista">
				${SOU_ADMIN_ROOKIE ? '' : `<div class="rs-item">
					<img src="${avatarUrl(MEU_NOME, null)}">
					<span><strong>${escapeHtml(MEU_NOME)}</strong> (você)</span>
				</div>`}
				${outros.map(u => `
					<div class="rs-item">
						<img src="${avatarUrl(u.name, u.photo_url)}">
						<span>${escapeHtml(u.name)}</span>
					</div>`).join('')}
			</div>
			<p class="rs-empty" style="margin-top:14px">${total} pessoa${total === 1 ? '' : 's'} aguardando.</p>
		`;
	}

	function renderFila(estado, jaEscolhi = false) {
		document.getElementById('rsHeadIcon').className = 'bi bi-list-ol';
		document.getElementById('rsHeadTitle').textContent = 'Ordem do sorteio';
		const body = document.getElementById('rsBody');
		const intro = SOU_ADMIN_ROOKIE
			? 'Ordem sorteada. Acompanhe a fila aqui — pra registrar a escolha de um GM ausente, use o quadro do draft.'
			: (jaEscolhi
				? 'Seu time já está definido. Se quiser, acompanhe por aqui o resto do sorteio.'
				: 'A ordem já foi sorteada! Aguarde sua vez pra escolher o time.');
		body.innerHTML = `
			<p style="font-size:13px;color:var(--text-2);margin-bottom:4px">${intro}</p>
			${SOU_ADMIN_ROOKIE ? `<p style="font-size:12px;margin-bottom:10px"><a href="/draft-aleatorio.php?id=${estado.id}" style="color:var(--red)">Abrir o quadro do draft <i class="bi bi-box-arrow-up-right"></i></a></p>` : ''}
			<div class="rs-lista">
				${estado.picks.map(p => {
					const feito = p.player_name !== null;
					const pulou = Number(p.skipped) === 1;
					const vez = !feito && !pulou && Number(p.pick_number) === estado.vez_pick_number;
					const cls = feito ? 'rs-feito' : (vez ? 'rs-vez' : '');
					const tag = feito ? `<i class="bi bi-check-circle-fill"></i> ${escapeHtml(p.player_name)}` : (vez ? 'Escolhendo agora' : (pulou ? 'Pulou' : ''));
					return `
						<div class="rs-item ${cls}">
							<span class="rs-num">${p.pick_number}</span>
							<span>${escapeHtml(p.nome_display)}${Number(p.user_id) === MEU_USER_ID ? ' (você)' : ''}</span>
							<span class="rs-tag">${tag}</span>
						</div>`;
				}).join('')}
			</div>
		`;
	}

	function renderEscolher(estado, minhaPickNumber) {
		document.getElementById('rsHeadIcon').className = 'bi bi-trophy';
		document.getElementById('rsHeadTitle').textContent = 'Sua vez — escolha seu time';
		const tomados = new Set(estado.picks.filter(p => p.nba_team_id).map(p => Number(p.nba_team_id)));
		const body = document.getElementById('rsBody');
		body.innerHTML = `
			<p style="font-size:13px;color:var(--text-2);margin-bottom:12px">Pick #${minhaPickNumber} — escolha o time da NBA que vai representar seu GM na liga. Depois de confirmar não dá pra trocar sozinho.</p>
			<select id="rsTeamSelect" class="form-control rs-select">
				<option value="">Escolha um time...</option>
				${['LESTE', 'OESTE'].map(conf => `
					<optgroup label="Conferência ${conf === 'LESTE' ? 'Leste' : 'Oeste'}">
						${NBA_TEAMS.filter(t => t.conference === conf).map(t => `
							<option value="${t.id}" ${tomados.has(t.id) ? 'disabled' : ''}>
								${escapeHtml(t.city + ' ' + t.name)}${tomados.has(t.id) ? ' — já escolhido' : ''}
							</option>`).join('')}
					</optgroup>`).join('')}
			</select>
			<div class="rs-preview" id="rsPreview">
				<img id="rsPreviewLogo" src="" alt="">
				<div>
					<div class="rs-preview-name" id="rsPreviewName"></div>
					<div class="rs-preview-conf" id="rsPreviewConf"></div>
				</div>
			</div>
			<div id="rsMsg"></div>
			<button class="btn-rs" id="rsConfirmar" disabled><i class="bi bi-check-circle me-2"></i>Confirmar time</button>
		`;
		document.getElementById('rsTeamSelect').addEventListener('change', (e) => {
			const id = parseInt(e.target.value, 10);
			const team = NBA_TEAMS.find(t => t.id === id);
			const box = document.getElementById('rsPreview');
			document.getElementById('rsConfirmar').disabled = !team;
			if (!team) { box.classList.remove('show'); return; }
			document.getElementById('rsPreviewLogo').src = NBA_LOGO(team.id);
			document.getElementById('rsPreviewName').textContent = `${team.city} ${team.name}`;
			document.getElementById('rsPreviewConf').textContent = team.conference === 'LESTE' ? 'Conferência Leste' : 'Conferência Oeste';
			box.classList.add('show');
		});
		document.getElementById('rsConfirmar').addEventListener('click', () => confirmarTime(estado.id, minhaPickNumber));
	}

	/**
	 * Já escolheu: card no topo confirmando o time, com o botão pro app, e a fila
	 * do sorteio continuando embaixo.
	 *
	 * O redirecionamento automático segue existindo, só que com folga pra dar
	 * tempo de ler o card (antes eram 1,8s e a tela trocava antes da confirmação
	 * ser lida). Quem não quiser esperar clica no botão.
	 */
	function renderFinalizado(estado, minhaPick) {
		// A fila continua acompanhando o poll; o card do topo é montado uma vez só.
		// Sem essa ordem, cada volta de 4s reescrevia o card e apagava a contagem
		// por um instante, e a trava reiniciaria o cronômetro sem nunca chegar ao fim.
		renderFila(estado, true);
		if (redirecionando) return;
		redirecionando = true;

		const logo = minhaPick.nba_team_id
			? `<img class="rs-pronto-logo" src="${NBA_LOGO(Number(minhaPick.nba_team_id))}" alt="">`
			: '';
		document.getElementById('rsTopo').innerHTML = `
			<div class="rs-pronto">
				${logo}
				<div class="rs-pronto-txt">
					<div class="rs-pronto-label"><i class="bi bi-check-circle-fill"></i> Time definido</div>
					<div class="rs-pronto-time">${escapeHtml(minhaPick.player_name)}</div>
					<div class="rs-pronto-sub" id="rsProntoSub">Sua franquia na ROOKIE já está criada.</div>
				</div>
				<a class="rs-pronto-btn" href="/dashboard.php"><i class="bi bi-box-arrow-in-right"></i> Acessar o dashboard</a>
			</div>`;

		let faltam = 6;
		const sub = () => document.getElementById('rsProntoSub');
		const tick = () => {
			const el = sub();
			if (el) el.textContent = `Sua franquia na ROOKIE já está criada. Levando você pro app em ${faltam}s...`;
			if (faltam-- <= 0) { clearInterval(timer); window.location.href = '/dashboard.php'; }
		};
		tick();
		const timer = setInterval(tick, 1000);
	}

	async function confirmarTime(draftId, pickNumber) {
		const select = document.getElementById('rsTeamSelect');
		const nbaTeamId = select.value;
		const btn = document.getElementById('rsConfirmar');
		const msg = document.getElementById('rsMsg');
		if (!nbaTeamId) return;
		btn.disabled = true;
		select.disabled = true;
		try {
			const res = await fetch('/api/drafts-aleatorios.php', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ action: 'escolher', id: draftId, pick_number: pickNumber, nba_team_id: nbaTeamId }),
			});
			const data = await res.json();
			if (!data.success) throw new Error(data.error || 'Erro ao confirmar o time.');
			escolhendo = false;
			await atualizar();
		} catch (e) {
			msg.innerHTML = `<div class="alert alert-danger" style="margin-top:10px;font-size:12px">${escapeHtml(e.message)}</div>`;
			btn.disabled = false;
			select.disabled = false;
		}
	}

	function escapeHtml(s) {
		const d = document.createElement('div');
		d.textContent = s ?? '';
		return d.innerHTML;
	}

	async function atualizar() {
		if (escolhendo) return; // não pisa na tela enquanto o usuário está escolhendo
		try {
			const res = await fetch('/api/drafts-aleatorios.php?action=meu_draft_marca');
			const data = await res.json();
			if (!data.success) return;

			if (!data.draft_id) {
				renderEsperando(data.esperando || []);
				return;
			}

			const minhaPick = data.picks.find(p => Number(p.user_id) === MEU_USER_ID);
			if (!minhaPick) { renderFila(data); return; }

			if (minhaPick.player_name !== null) {
				renderFinalizado(data, minhaPick);
				return;
			}

			if (data.vez_pick_number === Number(minhaPick.pick_number)) {
				escolhendo = true;
				renderEscolher(data, Number(minhaPick.pick_number));
			} else {
				renderFila(data);
			}
		} catch (e) {
			// silencioso — próximo poll tenta de novo
		}
	}

	atualizar();
	setInterval(atualizar, 4000);
</script>

</body>
</html>
