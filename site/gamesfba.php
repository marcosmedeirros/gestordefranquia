<?php
// Lista os jogos dinamicamente a partir dos arquivos em site/gamesfba/*.php,
// pulando os desativados pelo admin em siteadmin.php (site_games.active).
require_once __DIR__ . '/../backend/db.php';
$pdo = db();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_games (
        slug VARCHAR(80) NOT NULL PRIMARY KEY,
        active TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

$inactiveSlugs = [];
try {
    $stmt = $pdo->query("SELECT slug FROM site_games WHERE active = 0");
    $inactiveSlugs = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$games = [];
foreach (glob(__DIR__ . '/gamesfba/*.php') as $file) {
    $slug = basename($file, '.php');
    if (in_array($slug, $inactiveSlugs, true)) continue;

    $html = file_get_contents($file);
    $title = $slug;
    if (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1])));
    }
    $desc = '';
    if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']/is', $html, $m)) {
        $desc = trim(html_entity_decode($m[1]));
    }
    $icon = 'bi-controller';
    if (preg_match('/<meta\s+name=["\']game-icon["\']\s+content=["\'](.*?)["\']/is', $html, $m)) {
        $icon = trim($m[1]);
    }
    $games[] = [
        'title' => $title,
        'desc'  => $desc,
        'icon'  => $icon,
        'href'  => '/site/gamesfba/' . basename($file),
    ];
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Games · FBA</title>
<link rel="icon" type="image/png" href="/img/fba-logo-default-cropped.png" />
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Anton&family=Oswald:wght@500;700&family=Barlow+Condensed:wght@600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root {
  --bg: #0a0a0a;
  --bg-2: #131313;
  --bg-3: #1a1a1a;
  --ink: #f5f5f5;
  --ink-mute: #a0a0a0;
  --ink-dim: #6a6a6a;
  --line: #262626;
  --line-2: #333;

  --red: #E63946;
  --red-deep: #b1232f;
  --green: #2EA85B;
  --blue: #2A6FDB;

  --max: 960px;
  --pad: clamp(20px, 4vw, 64px);

  --font-display: "Bebas Neue", "Anton", "Oswald", Impact, sans-serif;
  --font-body: "Inter", -apple-system, "Helvetica Neue", Arial, sans-serif;
  --font-mono: "JetBrains Mono", ui-monospace, "SF Mono", Menlo, monospace;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: var(--bg); color: var(--ink); }
body {
  font-family: var(--font-body);
  font-size: 16px;
  line-height: 1.6;
  -webkit-font-smoothing: antialiased;
}
img { max-width: 100%; display: block; }
a { color: var(--red); text-decoration: none; }
button { font: inherit; cursor: pointer; }
.wrap { max-width: var(--max); margin: 0 auto; padding-inline: var(--pad); }

.gm-topline { height: 4px; background: linear-gradient(90deg, var(--blue), var(--red), var(--blue)); background-size: 200%; animation: gmBar 3s linear infinite; }
@keyframes gmBar { 0% { background-position: 0% } 100% { background-position: 200% } }

.gm-hero { padding: 56px 0 32px; border-bottom: 1px solid var(--line); }
.gm-eyebrow {
  display: inline-flex; align-items: center; gap: 10px;
  padding: 6px 12px;
  border: 1px solid var(--blue);
  color: var(--blue);
  font-family: var(--font-mono);
  font-size: 11px;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  margin-bottom: 20px;
}
.gm-title {
  font-family: var(--font-display);
  font-weight: 400;
  text-transform: uppercase;
  line-height: 0.9;
  font-size: clamp(48px, 9vw, 96px);
  margin: 0;
}
.gm-sub { color: var(--ink-mute); font-size: 15px; margin-top: 14px; max-width: 60ch; }

.gm-section { padding: 56px 0; border-bottom: 1px solid var(--line); }
.gm-section-head { display: flex; align-items: baseline; gap: 14px; margin-bottom: 28px; flex-wrap: wrap; }
.gm-section-head h2 { font-family: var(--font-display); text-transform: uppercase; font-size: clamp(32px, 5vw, 48px); line-height: 1; margin: 0; }
.gm-section-tag { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.15em; text-transform: uppercase; color: var(--ink-dim); }

.gm-card {
  background: var(--bg-2);
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 32px;
}
@media (max-width: 600px) { .gm-card { padding: 22px; } }

/* Full games grid */
.gm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
.gm-game-card {
  display: block;
  background: var(--bg-2);
  border: 1px solid var(--line);
  border-radius: 12px;
  overflow: hidden;
  transition: border-color .2s, transform .2s;
}
.gm-game-card:hover { border-color: var(--red); transform: translateY(-2px); }
.gm-game-thumb {
  height: 140px;
  background: linear-gradient(135deg, rgba(230,57,70,.18), rgba(42,111,219,.14));
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-display); font-size: 15px; letter-spacing: .04em; text-transform: uppercase; color: var(--ink-mute);
  border-bottom: 1px solid var(--line);
}
.gm-game-thumb i { font-size: 40px; color: var(--red); }
.gm-game-info { padding: 18px 20px 20px; }
.gm-game-info h3 { font-family: var(--font-display); text-transform: uppercase; font-size: 22px; margin: 0 0 6px; letter-spacing: .01em; }
.gm-game-info p { color: var(--ink-mute); font-size: 13px; line-height: 1.5; margin: 0 0 14px; }
.gm-game-cta { display: inline-flex; align-items: center; gap: 8px; font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.15em; text-transform: uppercase; color: var(--red); }

/* Quiz */
.qz-progress { display: flex; gap: 6px; margin-bottom: 22px; }
.qz-progress span { flex: 1; height: 4px; background: var(--line); border-radius: 2px; overflow: hidden; }
.qz-progress span i { display: block; height: 100%; width: 0%; background: var(--blue); transition: width .3s; }
.qz-progress span.done i { width: 100%; }
.qz-progress span.current i { width: 50%; }
.qz-question { font-family: var(--font-display); font-size: clamp(24px, 4vw, 34px); text-transform: uppercase; line-height: 1.1; margin-bottom: 24px; }
.qz-options { display: grid; gap: 10px; }
.qz-opt {
  text-align: left;
  background: var(--bg-3);
  border: 1px solid var(--line-2);
  color: var(--ink);
  padding: 14px 18px;
  border-radius: 8px;
  font-size: 15px;
  font-weight: 500;
  transition: border-color .15s, background .15s;
}
.qz-opt:hover:not(:disabled) { border-color: var(--blue); }
.qz-opt.correct { border-color: var(--green); background: rgba(46,168,91,.14); color: var(--green); }
.qz-opt.wrong { border-color: var(--red); background: rgba(230,57,70,.14); color: var(--red); }
.qz-opt:disabled { cursor: default; opacity: .7; }
.qz-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 24px; }
.qz-score { font-family: var(--font-mono); font-size: 12px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--ink-mute); }
.qz-next { background: var(--blue); color: white; border: none; padding: 10px 22px; border-radius: 8px; font-family: var(--font-display); font-size: 15px; letter-spacing: 0.05em; display: none; }
.qz-next.show { display: inline-flex; align-items: center; gap: 8px; }
.qz-result { text-align: center; padding: 20px 0; }
.qz-result .big { font-family: var(--font-display); font-size: 72px; color: var(--blue); line-height: 1; }
.qz-result p { color: var(--ink-mute); margin-top: 10px; }
.qz-retry { margin-top: 20px; background: transparent; border: 1px solid var(--line-2); color: var(--ink); padding: 10px 22px; border-radius: 8px; font-family: var(--font-display); font-size: 15px; letter-spacing: 0.05em; }
.qz-retry:hover { border-color: var(--blue); color: var(--blue); }

/* Reflex */
.rx-pad {
  height: 240px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  flex-direction: column; gap: 8px;
  background: var(--bg-3);
  border: 1px solid var(--line-2);
  transition: background .15s, border-color .15s;
  user-select: none;
}
.rx-pad.wait { background: var(--bg-3); }
.rx-pad.go { background: rgba(46,168,91,.18); border-color: var(--green); }
.rx-pad.early { background: rgba(230,57,70,.18); border-color: var(--red); }
.rx-msg { font-family: var(--font-display); font-size: clamp(26px, 5vw, 40px); text-transform: uppercase; letter-spacing: .02em; }
.rx-hint { font-family: var(--font-mono); font-size: 12px; color: var(--ink-dim); letter-spacing: .08em; text-transform: uppercase; }
.rx-stats { display: flex; gap: 28px; margin-top: 22px; flex-wrap: wrap; }
.rx-stat b { display: block; font-family: var(--font-display); font-size: 36px; color: var(--ink); line-height: 1; }
.rx-stat span { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--ink-dim); }

.foot {
  background: black;
  border-top: 1px solid var(--line);
  padding: 40px 0;
}
.foot-bottom {
  display: flex; justify-content: space-between;
  font-family: var(--font-mono);
  font-size: 11px;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--ink-dim);
  flex-wrap: wrap; gap: 12px;
}
@media (max-width: 600px) { .wrap { padding-inline: 16px; } }
</style>
</head>
<body>
<div class="gm-topline"></div>
<?php include __DIR__ . '/_partials/nav.php'; ?>

<header class="gm-hero">
  <div class="wrap">
    <span class="gm-eyebrow"><i class="bi bi-controller"></i> Games da liga</span>
    <h1 class="gm-title">FBA<br />Games.</h1>
    <p class="gm-sub">Mini-games oficiais da FBA. Teste seus conhecimentos sobre a liga e seus reflexos — não salva nada, é só pra divertir.</p>
  </div>
</header>

<section class="gm-section">
  <div class="wrap">
    <div class="gm-section-head">
      <h2>Jogos</h2>
      <span class="gm-section-tag">Experiências completas · Toque para abrir</span>
    </div>
    <?php if (empty($games)): ?>
    <p style="color:var(--ink-dim);font-size:14px">Nenhum jogo publicado ainda.</p>
    <?php else: ?>
    <div class="gm-grid">
      <?php foreach ($games as $g): ?>
      <a class="gm-game-card" href="<?= htmlspecialchars($g['href']) ?>">
        <div class="gm-game-thumb"><i class="bi <?= htmlspecialchars($g['icon']) ?>"></i></div>
        <div class="gm-game-info">
          <h3><?= htmlspecialchars($g['title']) ?></h3>
          <?php if ($g['desc']): ?><p><?= htmlspecialchars($g['desc']) ?></p><?php endif; ?>
          <span class="gm-game-cta">Jogar <i class="bi bi-arrow-right"></i></span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="gm-section">
  <div class="wrap">
    <div class="gm-section-head">
      <h2>Quiz Relâmpago</h2>
      <span class="gm-section-tag">5 perguntas · Quanto você conhece a FBA?</span>
    </div>
    <div class="gm-card">
      <div id="qzProgress" class="qz-progress"></div>
      <div id="qzBody"></div>
    </div>
  </div>
</section>

<section class="gm-section">
  <div class="wrap">
    <div class="gm-section-head">
      <h2>Teste de Reflexo</h2>
      <span class="gm-section-tag">Clique assim que o quadro ficar verde</span>
    </div>
    <div class="gm-card">
      <div id="rxPad" class="rx-pad wait">
        <div class="rx-msg" id="rxMsg">CLIQUE PRA COMEÇAR</div>
        <div class="rx-hint" id="rxHint">Aguarde ficar verde, depois clique o mais rápido possível</div>
      </div>
      <div class="rx-stats">
        <div class="rx-stat"><b id="rxLast">—</b><span>Seu tempo</span></div>
        <div class="rx-stat"><b id="rxBest">—</b><span>Recorde</span></div>
      </div>
    </div>
  </div>
</section>

<footer class="foot">
  <div class="wrap foot-bottom">
    <span>© 2026 FBA · Federação Brasileira de Arena</span>
    <span>Não afiliada à NBA ou Take-Two Interactive</span>
  </div>
</footer>

<script>
/* ===== Quiz Relâmpago ===== */
const QUIZ = [
  { q: "Quantas divisões compõem a FBA?", opts: ["3", "4", "5", "6"], correct: 1 },
  { q: "Qual é a divisão principal (top tier) da liga?", opts: ["Rookie", "Rise", "Next", "Elite"], correct: 3 },
  { q: "Até quantos jogadores um squad pode ter na inscrição?", opts: ["5", "6", "7", "10"], correct: 2 },
  { q: "Quantos times de cada divisão avançam aos playoffs?", opts: ["4", "6", "8", "12"], correct: 2 },
  { q: "Quanto tempo dura, em média, cada temporada da FBA?", opts: ["1 mês", "4 meses", "6 meses", "1 ano"], correct: 1 },
];

let qzIndex = 0;
let qzScore = 0;
let qzAnswered = false;

function qzRenderProgress() {
  const el = document.getElementById('qzProgress');
  el.innerHTML = QUIZ.map((_, i) => {
    const cls = i < qzIndex ? 'done' : (i === qzIndex ? 'current' : '');
    return `<span class="${cls}"><i></i></span>`;
  }).join('');
}

function qzRenderQuestion() {
  qzAnswered = false;
  qzRenderProgress();
  const body = document.getElementById('qzBody');
  if (qzIndex >= QUIZ.length) {
    body.innerHTML = `
      <div class="qz-result">
        <div class="big">${qzScore}/${QUIZ.length}</div>
        <p>${qzScore === QUIZ.length ? 'Perfeito! Você manja da FBA.' : qzScore >= 3 ? 'Mandou bem!' : 'Dá uma passada pelo site e tenta de novo.'}</p>
        <button class="qz-retry" onclick="qzRestart()"><i class="bi bi-arrow-repeat"></i> Jogar de novo</button>
      </div>`;
    return;
  }
  const item = QUIZ[qzIndex];
  body.innerHTML = `
    <div class="qz-question">${item.q}</div>
    <div class="qz-options">
      ${item.opts.map((o, i) => `<button class="qz-opt" data-i="${i}" onclick="qzAnswer(${i})">${o}</button>`).join('')}
    </div>
    <div class="qz-footer">
      <span class="qz-score">Pontos: ${qzScore}/${QUIZ.length}</span>
      <button class="qz-next" id="qzNextBtn" onclick="qzNext()">Próxima <i class="bi bi-arrow-right"></i></button>
    </div>`;
}

function qzAnswer(i) {
  if (qzAnswered) return;
  qzAnswered = true;
  const item = QUIZ[qzIndex];
  const opts = document.querySelectorAll('.qz-opt');
  opts.forEach((btn, idx) => {
    btn.disabled = true;
    if (idx === item.correct) btn.classList.add('correct');
    else if (idx === i) btn.classList.add('wrong');
  });
  if (i === item.correct) qzScore++;
  document.querySelector('.qz-score').textContent = `Pontos: ${qzScore}/${QUIZ.length}`;
  document.getElementById('qzNextBtn').classList.add('show');
}

function qzNext() {
  qzIndex++;
  qzRenderQuestion();
}

function qzRestart() {
  qzIndex = 0;
  qzScore = 0;
  qzRenderQuestion();
}

qzRenderQuestion();

/* ===== Teste de Reflexo ===== */
const rxPad = document.getElementById('rxPad');
const rxMsg = document.getElementById('rxMsg');
const rxHint = document.getElementById('rxHint');
const rxLast = document.getElementById('rxLast');
const rxBest = document.getElementById('rxBest');

let rxState = 'idle'; // idle | waiting | go
let rxTimer = null;
let rxStartTs = 0;

function rxLoadBest() {
  const v = parseInt(localStorage.getItem('fba_reflex_best') || '0', 10);
  rxBest.textContent = v > 0 ? v + 'ms' : '—';
}
rxLoadBest();

function rxSetIdle() {
  rxState = 'idle';
  rxPad.className = 'rx-pad wait';
  rxMsg.textContent = 'CLIQUE PRA COMEÇAR';
  rxHint.textContent = 'Aguarde ficar verde, depois clique o mais rápido possível';
}

function rxStart() {
  rxState = 'waiting';
  rxPad.className = 'rx-pad wait';
  rxMsg.textContent = 'AGUARDE...';
  rxHint.textContent = 'Não clique ainda';
  const delay = 1000 + Math.random() * 2500;
  rxTimer = setTimeout(() => {
    rxState = 'go';
    rxPad.className = 'rx-pad go';
    rxMsg.textContent = 'CLIQUE!';
    rxHint.textContent = '';
    rxStartTs = performance.now();
  }, delay);
}

rxPad.addEventListener('click', () => {
  if (rxState === 'idle') {
    rxStart();
    return;
  }
  if (rxState === 'waiting') {
    clearTimeout(rxTimer);
    rxPad.className = 'rx-pad early';
    rxMsg.textContent = 'CEDO DEMAIS!';
    rxHint.textContent = 'Clique pra tentar de novo';
    rxState = 'idle';
    return;
  }
  if (rxState === 'go') {
    const elapsed = Math.round(performance.now() - rxStartTs);
    rxLast.textContent = elapsed + 'ms';
    const best = parseInt(localStorage.getItem('fba_reflex_best') || '0', 10);
    if (best === 0 || elapsed < best) {
      localStorage.setItem('fba_reflex_best', String(elapsed));
      rxLoadBest();
    }
    rxPad.className = 'rx-pad wait';
    rxMsg.textContent = elapsed + 'ms — clique pra tentar de novo';
    rxHint.textContent = '';
    rxState = 'idle';
  }
});
</script>
</body>
</html>
