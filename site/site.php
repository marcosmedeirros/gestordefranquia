<?php
require_once __DIR__ . '/../backend/db.php';
$pdo = db();

// Times por divisão, priorizando o logo mapeado em _partials/team-logos.php,
// com fallback pra foto cadastrada no app e por último o logo padrão.
$teamLogos = require __DIR__ . '/_partials/team-logos.php';
$teamsByLeague = ['ELITE' => [], 'NEXT' => [], 'RISE' => []];
try {
    $stmt = $pdo->query("SELECT city, name, league, photo_url, conference FROM teams WHERE league IN ('ELITE','NEXT','RISE') ORDER BY league, city ASC, name ASC");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $teamName = trim($t['city'] . ' ' . $t['name']);
        $teamsByLeague[$t['league']][] = [
            'name'  => $teamName,
            'photo' => $teamLogos[$teamName] ?? ($t['photo_url'] ?: '/img/default-team.png'),
            'conf'  => $t['conference'],
        ];
    }
} catch (Exception $e) {}

// Hall da Fama — times com mais títulos históricos
$hallOfFame = [];
try {
    $stmt = $pdo->query("SELECT team_name, league, gm_name, titles FROM hall_of_fame WHERE is_active = 1 AND team_name IS NOT NULL ORDER BY titles DESC LIMIT 8");
    $hallOfFame = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>FBA · A Liga de NBA 2K do Brasil</title>
<link rel="icon" type="image/png" href="/img/fba-logo-default-cropped.png" />
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Anton&family=Oswald:wght@500;700&family=Barlow+Condensed:wght@600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet" />
<style>
/* ============ FBA — Design tokens ============ */
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
  --orange: #E8862E;

  --max: 1440px;
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
  line-height: 1.55;
  -webkit-font-smoothing: antialiased;
  text-rendering: optimizeLegibility;
  overflow-x: hidden;
}
img { max-width: 100%; display: block; }
a { color: inherit; text-decoration: none; }
button { font: inherit; cursor: pointer; border: 0; background: 0; color: inherit; }

.wrap { max-width: var(--max); margin: 0 auto; padding-inline: var(--pad); }

/* ============ Type ============ */
.display {
  font-family: var(--font-display);
  font-weight: 400;
  letter-spacing: -0.01em;
  line-height: 0.88;
  text-transform: uppercase;
}
.mono { font-family: var(--font-mono); letter-spacing: 0.02em; }
.eyebrow {
  font-family: var(--font-mono);
  font-size: 12px;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: var(--ink-mute);
}

/* ============ Nav ============ */
.nav {
  position: fixed; inset: 0 0 auto 0;
  z-index: 50;
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  background: rgba(10,10,10,0.72);
  border-bottom: 1px solid var(--line);
}
.nav-inner {
  display: flex; align-items: center; justify-content: space-between;
  height: 68px;
}
.nav-logo {
  display: flex; align-items: center; gap: 10px;
  font-family: var(--font-display);
  font-size: 22px;
  letter-spacing: 0.04em;
}
.nav-logo img { height: 36px; width: auto; }
.nav-links { display: flex; gap: 32px; align-items: center; }
.nav-links a {
  font-size: 13px;
  font-weight: 500;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--ink-mute);
  transition: color .2s;
}
.nav-links a:hover { color: var(--ink); }
.nav-cta {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 10px 18px;
  background: var(--red);
  color: white;
  font-weight: 600;
  font-size: 13px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  clip-path: polygon(8px 0, 100% 0, calc(100% - 8px) 100%, 0 100%);
  transition: background .2s, transform .2s;
}
.nav-cta:hover { background: var(--red-deep); }
@media (max-width: 880px) { .nav-links { display: none; } }

/* ============ Hero ============ */
.hero {
  position: relative;
  padding-top: 140px;
  padding-bottom: 80px;
  overflow: hidden;
  background:
    radial-gradient(ellipse 80% 50% at 80% 0%, rgba(230,57,70,0.12), transparent 60%),
    radial-gradient(ellipse 60% 40% at 0% 100%, rgba(230,57,70,0.08), transparent 60%),
    var(--bg);
}
.hero-grid {
  display: grid;
  grid-template-columns: 1.3fr 1fr;
  gap: 60px;
  align-items: end;
}
@media (max-width: 980px) { .hero-grid { grid-template-columns: 1fr; } }

.hero-title {
  font-size: clamp(72px, 13vw, 200px);
  margin: 0;
}
.hero-title .red { color: var(--red); }
.hero-title .stroke {
  -webkit-text-stroke: 2px var(--ink);
  color: transparent;
}
.hero-tag {
  display: inline-flex; align-items: center; gap: 10px;
  padding: 6px 12px;
  border: 1px solid var(--red);
  color: var(--red);
  font-family: var(--font-mono);
  font-size: 11px;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  margin-bottom: 28px;
}
.hero-tag::before {
  content: "";
  width: 6px; height: 6px;
  background: var(--red);
  border-radius: 50%;
  animation: pulse 1.6s ease-in-out infinite;
}
@keyframes pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.4; transform: scale(0.7); }
}
.hero-sub {
  max-width: 460px;
  color: var(--ink-mute);
  font-size: 17px;
  line-height: 1.55;
  margin: 24px 0 0 0;
}
.hero-ctas {
  display: flex; gap: 14px; flex-wrap: wrap;
  margin-top: 36px;
}
.btn {
  display: inline-flex; align-items: center; gap: 12px;
  padding: 18px 28px;
  font-family: var(--font-display);
  font-size: 18px;
  letter-spacing: 0.05em;
  transition: transform .2s, background .2s, color .2s;
}
.btn-primary {
  background: var(--red);
  color: white;
  clip-path: polygon(10px 0, 100% 0, calc(100% - 10px) 100%, 0 100%);
}
.btn-primary:hover { background: var(--red-deep); }
.btn-ghost {
  border: 1px solid var(--line-2);
  color: var(--ink);
}
.btn-ghost:hover { border-color: var(--ink); background: var(--ink); color: var(--bg); }
.btn .arrow { display: inline-block; transition: transform .2s; }
.btn:hover .arrow { transform: translateX(4px); }

/* Hero side panel — live ticker */
.hero-side {
  border: 1px solid var(--line);
  background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0));
  padding: 28px;
  position: relative;
}
.hero-side::before {
  content: "";
  position: absolute; top: -1px; left: -1px;
  width: 40px; height: 4px; background: var(--red);
}
.live-row { display: flex; justify-content: space-between; align-items: center; }
.live-dot {
  display: inline-flex; align-items: center; gap: 8px;
  font-family: var(--font-mono); font-size: 11px;
  letter-spacing: 0.2em; text-transform: uppercase; color: var(--red);
}
.live-dot::before {
  content: ""; width: 8px; height: 8px; background: var(--red); border-radius: 50%;
  animation: pulse 1.2s infinite;
}
.ticker { margin-top: 20px; }
.ticker-match {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  padding: 16px 0;
  border-top: 1px solid var(--line);
}
.ticker-match:last-of-type { border-bottom: 1px solid var(--line); }
.ticker-team { display: flex; align-items: center; gap: 10px; min-width: 0; }
.ticker-team.right { justify-content: flex-end; }
.ticker-team .badge {
  width: 28px; height: 28px;
  background: var(--bg-3); border: 1px solid var(--line);
  display: grid; place-items: center;
  font-family: var(--font-display); font-size: 13px; color: var(--ink-mute);
}
.ticker-team .name { font-weight: 600; font-size: 14px; }
.ticker-score {
  font-family: var(--font-display);
  font-size: 28px;
  padding: 0 18px;
  color: var(--ink);
  letter-spacing: 0.04em;
}
.ticker-score .sep { color: var(--ink-dim); margin: 0 4px; }
.ticker-meta {
  display: flex; justify-content: space-between;
  font-family: var(--font-mono); font-size: 10px;
  letter-spacing: 0.18em; text-transform: uppercase; color: var(--ink-dim);
  margin-top: 14px;
}

/* Hero marquee */
.marquee {
  margin-top: 64px;
  border-top: 1px solid var(--line);
  border-bottom: 1px solid var(--line);
  padding: 18px 0;
  overflow: hidden;
  white-space: nowrap;
  mask-image: linear-gradient(90deg, transparent, black 8%, black 92%, transparent);
}
.marquee-track {
  display: inline-flex; gap: 56px;
  animation: marquee 40s linear infinite;
}
.marquee-item {
  font-family: var(--font-display);
  font-size: 26px;
  letter-spacing: 0.05em;
  color: var(--ink-mute);
  display: inline-flex; align-items: center; gap: 56px;
}
.marquee-item .dot { color: var(--red); }
@keyframes marquee {
  from { transform: translateX(0); }
  to { transform: translateX(-50%); }
}

/* ============ Section header ============ */
.section { padding: 120px 0; position: relative; }
.section-head {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 24px;
  align-items: end;
  margin-bottom: 56px;
}
.section-head h2 {
  font-family: var(--font-display);
  font-size: clamp(52px, 8vw, 120px);
  line-height: 0.9;
  margin: 0;
  letter-spacing: -0.01em;
  text-transform: uppercase;
}
.section-num {
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--ink-dim);
  letter-spacing: 0.2em;
}
.section-link {
  font-family: var(--font-mono);
  font-size: 11px;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: var(--ink-mute);
  display: inline-flex; align-items: center; gap: 8px;
  border-bottom: 1px solid var(--line-2);
  padding-bottom: 6px;
  transition: color .2s, border-color .2s;
}
.section-link:hover { color: var(--red); border-color: var(--red); }
@media (max-width: 700px) {
  .section-head { grid-template-columns: 1fr; }
  .section-head > * { grid-column: 1; }
}

/* ============ About / Stats ============ */
.about {
  background: var(--bg);
  border-top: 1px solid var(--line);
}
.about-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 80px;
  align-items: start;
}
@media (max-width: 900px) { .about-grid { grid-template-columns: 1fr; gap: 40px; } }
.about-copy {
  font-size: 22px;
  line-height: 1.45;
  color: var(--ink);
  text-wrap: pretty;
}
.about-copy .accent { color: var(--red); }
.about-copy p + p { margin-top: 20px; color: var(--ink-mute); font-size: 17px; line-height: 1.6; }

.stats-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1px;
  background: var(--line);
  border: 1px solid var(--line);
}
.stat {
  background: var(--bg);
  padding: 32px 24px;
  position: relative;
}
.stat-num {
  font-family: var(--font-display);
  font-size: 76px;
  line-height: 0.9;
  color: var(--ink);
}
.stat-num sup { color: var(--red); font-size: 0.5em; vertical-align: top; margin-left: 4px; }
.stat-label {
  margin-top: 8px;
  font-family: var(--font-mono);
  font-size: 11px;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: var(--ink-mute);
}
.stat-trend { position: absolute; top: 20px; right: 20px; font-family: var(--font-mono); font-size: 10px; color: var(--red); letter-spacing: 0.1em; }

/* ============ Divisions ============ */
.divisions {
  background: var(--bg-2);
  border-top: 1px solid var(--line);
}
.div-list {
  display: grid;
  gap: 1px;
  background: var(--line);
  border-top: 1px solid var(--line);
  border-bottom: 1px solid var(--line);
}
.div-row {
  background: var(--bg);
  display: grid;
  grid-template-columns: 80px 1fr 1.4fr 1fr auto;
  align-items: center;
  gap: 40px;
  padding: 36px var(--pad);
  cursor: pointer;
  position: relative;
  transition: background .35s, padding .35s;
}
.div-row::before {
  content: "";
  position: absolute; inset: 0;
  background: linear-gradient(90deg, var(--div-color, var(--red)) 0%, transparent 50%);
  opacity: 0;
  pointer-events: none;
  transition: opacity .35s;
}
.div-row > * { position: relative; }
.div-row:hover { background: var(--bg-3); }
.div-row:hover::before { opacity: 0.12; }
.div-row.coming { opacity: 0.55; cursor: default; }
.div-row.coming:hover { background: var(--bg); }

.div-num {
  font-family: var(--font-mono);
  font-size: 13px;
  color: var(--ink-dim);
  letter-spacing: 0.1em;
}
.div-logo {
  width: 96px; height: 96px;
  display: grid; place-items: center;
  background: var(--bg-3);
  border: 1px solid var(--line);
}
.div-logo img { max-width: 70%; max-height: 70%; }
.div-info { min-width: 0; }
.div-name {
  font-family: var(--font-display);
  font-size: clamp(48px, 6vw, 88px);
  line-height: 0.88;
  display: flex; align-items: baseline; gap: 16px;
  color: var(--div-color);
}
.div-name .fba {
  -webkit-text-stroke: 1.5px var(--ink);
  color: transparent;
  font-size: 0.42em;
  letter-spacing: 0.04em;
}
.div-tag {
  font-family: var(--font-mono);
  font-size: 11px;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: var(--ink-mute);
  margin-top: 10px;
}
.div-desc {
  font-size: 15px;
  color: var(--ink-mute);
  line-height: 1.55;
  text-wrap: pretty;
  max-width: 42ch;
}
.div-stats {
  display: flex; gap: 28px;
  font-family: var(--font-mono); font-size: 11px;
  letter-spacing: 0.12em; text-transform: uppercase; color: var(--ink-dim);
}
.div-stats b {
  display: block;
  font-family: var(--font-display);
  font-size: 32px;
  color: var(--ink);
  font-weight: 400;
  letter-spacing: 0.02em;
  line-height: 1;
  margin-bottom: 6px;
}
.div-action {
  display: inline-flex; align-items: center; gap: 10px;
  padding: 14px 22px;
  background: var(--div-color);
  color: white;
  font-family: var(--font-display);
  font-size: 15px;
  letter-spacing: 0.06em;
  transition: gap .2s;
  white-space: nowrap;
}
.div-action:hover { gap: 16px; }
.div-action.disabled {
  background: var(--bg-3);
  color: var(--ink-dim);
  border: 1px solid var(--line);
  pointer-events: none;
}

@media (max-width: 1100px) {
  .div-row { grid-template-columns: 1fr; gap: 18px; padding: 28px var(--pad); }
  .div-num { display: none; }
  .div-name { font-size: 56px; }
}

/* ============ How it works ============ */
.how {
  background: var(--bg);
  border-top: 1px solid var(--line);
}
.how-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1px;
  background: var(--line);
  border: 1px solid var(--line);
}
@media (max-width: 900px) { .how-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 560px) { .how-grid { grid-template-columns: 1fr; } }
.how-step {
  background: var(--bg);
  padding: 32px 28px 36px;
  min-height: 280px;
  display: flex; flex-direction: column;
  position: relative;
}
.how-step .num {
  font-family: var(--font-display);
  font-size: 76px;
  color: var(--red);
  line-height: 0.85;
}
.how-step .title {
  font-family: var(--font-display);
  font-size: 28px;
  margin: 20px 0 12px;
  letter-spacing: 0.01em;
  text-transform: uppercase;
}
.how-step .body { color: var(--ink-mute); font-size: 15px; line-height: 1.55; }
.how-step::after {
  content: "";
  position: absolute; left: 0; bottom: 0;
  width: 0; height: 3px; background: var(--red);
  transition: width .5s;
}
.how-step:hover::after { width: 100%; }

/* ============ Champions ============ */
.champs {
  background: var(--bg-2);
  border-top: 1px solid var(--line);
}
.champ-grid {
  display: grid;
  grid-template-columns: 1.4fr 1fr 1fr;
  gap: 1px;
  background: var(--line);
  border: 1px solid var(--line);
}
@media (max-width: 900px) { .champ-grid { grid-template-columns: 1fr; } }
.champ-card {
  background: var(--bg);
  padding: 28px;
  position: relative;
  overflow: hidden;
}
.champ-card.lead { grid-row: span 2; padding: 32px; min-height: 480px; display: flex; flex-direction: column; justify-content: space-between; }
@media (max-width: 900px) { .champ-card.lead { grid-row: auto; min-height: 360px; } }
.champ-img {
  background:
    repeating-linear-gradient(45deg, rgba(255,255,255,0.04) 0 2px, transparent 2px 14px),
    var(--bg-3);
  border: 1px solid var(--line);
  aspect-ratio: 16/10;
  display: grid; place-items: center;
  font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.2em;
  color: var(--ink-dim); text-transform: uppercase; text-align: center;
  margin-bottom: 24px;
}
.champ-card.lead .champ-img { aspect-ratio: 16/9; margin-bottom: 0; }
.champ-card.lead .champ-content { padding-top: 24px; }
.champ-meta {
  display: flex; gap: 14px;
  font-family: var(--font-mono); font-size: 10px;
  letter-spacing: 0.2em; text-transform: uppercase; color: var(--ink-dim);
  margin-bottom: 14px;
}
.champ-meta .pill {
  padding: 2px 8px;
  background: var(--red);
  color: white;
  letter-spacing: 0.15em;
}
.champ-title {
  font-family: var(--font-display);
  font-size: 32px;
  line-height: 1;
  letter-spacing: 0.01em;
  text-transform: uppercase;
}
.champ-card.lead .champ-title { font-size: 48px; }
.champ-team {
  margin-top: 12px;
  color: var(--ink-mute);
  font-size: 14px;
}

/* ============ CTA banner ============ */
.cta-banner {
  background: var(--red);
  color: white;
  padding: 80px 0;
  position: relative;
  overflow: hidden;
}
.cta-banner::before {
  content: "FBA · FBA · FBA · FBA · FBA · FBA · FBA · FBA · FBA · FBA";
  position: absolute; inset: 0;
  font-family: var(--font-display); font-size: 280px;
  line-height: 1; color: rgba(255,255,255,0.06);
  white-space: nowrap; overflow: hidden;
  pointer-events: none;
  letter-spacing: -0.02em;
}
.cta-inner {
  position: relative;
  display: grid;
  grid-template-columns: 1.4fr auto;
  gap: 40px;
  align-items: center;
}
@media (max-width: 800px) { .cta-inner { grid-template-columns: 1fr; } }
.cta-inner h2 {
  font-family: var(--font-display);
  font-size: clamp(48px, 7vw, 96px);
  margin: 0;
  line-height: 0.92;
  letter-spacing: -0.005em;
  text-transform: uppercase;
}
.cta-inner .btn-primary {
  background: black;
  color: white;
}
.cta-inner .btn-primary:hover { background: white; color: var(--red); }

/* ============ FAQ ============ */
.faq {
  background: var(--bg);
  border-top: 1px solid var(--line);
}
.faq-list {
  border-top: 1px solid var(--line);
}
.faq-item {
  border-bottom: 1px solid var(--line);
}
.faq-q {
  width: 100%;
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 24px;
  padding: 28px 0;
  text-align: left;
  font-family: var(--font-display);
  font-size: clamp(22px, 3vw, 32px);
  text-transform: uppercase;
  letter-spacing: 0.005em;
  transition: color .2s;
}
.faq-q:hover { color: var(--red); }
.faq-q .idx { font-family: var(--font-mono); font-size: 12px; color: var(--ink-dim); letter-spacing: 0.15em; }
.faq-q .toggle {
  width: 28px; height: 28px;
  border: 1px solid var(--line-2);
  display: grid; place-items: center;
  font-family: var(--font-mono); font-size: 18px;
  transition: background .2s, border-color .2s;
}
.faq-item.open .faq-q .toggle { background: var(--red); border-color: var(--red); color: white; }
.faq-a {
  display: grid;
  grid-template-rows: 0fr;
  transition: grid-template-rows .35s ease;
}
.faq-item.open .faq-a { grid-template-rows: 1fr; }
.faq-a > div { overflow: hidden; }
.faq-a p {
  margin: 0;
  padding: 0 0 28px 60px;
  color: var(--ink-mute);
  font-size: 16px;
  line-height: 1.6;
  max-width: 70ch;
}

/* ============ Footer ============ */
.foot {
  background: black;
  border-top: 1px solid var(--line);
  padding: 80px 0 40px;
}
.foot-grid {
  display: grid;
  grid-template-columns: 1.4fr 1fr 1fr 1fr;
  gap: 40px;
  margin-bottom: 60px;
}
@media (max-width: 800px) { .foot-grid { grid-template-columns: 1fr 1fr; } }
.foot-brand img { height: 56px; width: auto; margin-bottom: 20px; }
.foot-brand p { color: var(--ink-mute); font-size: 14px; max-width: 36ch; line-height: 1.6; }
.foot-col h4 {
  font-family: var(--font-mono);
  font-size: 11px;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: var(--ink-mute);
  margin: 0 0 16px;
}
.foot-col ul { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
.foot-col a { font-size: 14px; color: var(--ink); transition: color .2s; }
.foot-col a:hover { color: var(--red); }
.foot-bottom {
  display: flex; justify-content: space-between;
  padding-top: 28px;
  border-top: 1px solid var(--line);
  font-family: var(--font-mono);
  font-size: 11px;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--ink-dim);
  flex-wrap: wrap; gap: 12px;
}

/* ============ Times por divisão ============ */
.teams-section {
  background: var(--bg);
  border-top: 1px solid var(--line);
}
.teams-tabs { display: flex; gap: 8px; margin-bottom: 32px; flex-wrap: wrap; }
.teams-tab {
  padding: 9px 18px;
  border: 1px solid var(--line-2);
  color: var(--ink-mute);
  font-family: var(--font-mono);
  font-size: 12px;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  transition: all .2s;
}
.teams-tab.active { background: var(--tab-color, var(--red)); border-color: var(--tab-color, var(--red)); color: white; }
.teams-tab:hover:not(.active) { border-color: var(--ink-mute); color: var(--ink); }
.teams-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 1px;
  background: var(--line);
  border: 1px solid var(--line);
}
.team-card {
  background: var(--bg-2);
  padding: 20px 14px;
  display: flex; flex-direction: column; align-items: center; gap: 10px;
  text-align: center;
}
.team-card img { width: 52px; height: 52px; object-fit: cover; border-radius: 8px; border: 1px solid var(--line-2); background: var(--bg-3); }
.team-card-name { font-size: 12px; font-weight: 600; color: var(--ink); line-height: 1.3; }
.team-card-conf { font-family: var(--font-mono); font-size: 9px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--ink-dim); }

/* ============ Hall da Fama ============ */
.hof-section { background: var(--bg-2); border-top: 1px solid var(--line); }
.hof-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 1px;
  background: var(--line);
  border: 1px solid var(--line);
}
.hof-card { background: var(--bg); padding: 24px; display: flex; align-items: center; gap: 16px; }
.hof-rank { font-family: var(--font-display); font-size: 40px; color: var(--red); line-height: 1; flex-shrink: 0; width: 48px; }
.hof-info { min-width: 0; }
.hof-name { font-size: 15px; font-weight: 700; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hof-meta { font-size: 12px; color: var(--ink-mute); margin-top: 3px; }
.hof-titles { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--red); margin-top: 5px; }

/* ============ Como Jogamos ============ */
.system { background: var(--bg); border-top: 1px solid var(--line); }
.system-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 1px;
  background: var(--line);
  border: 1px solid var(--line);
  margin-bottom: 1px;
}
.system-card { background: var(--bg-2); padding: 28px; }
.system-card i { font-size: 26px; color: var(--red); margin-bottom: 14px; display: block; }
.system-card h3 { font-family: var(--font-display); text-transform: uppercase; font-size: 22px; margin-bottom: 10px; letter-spacing: 0.01em; }
.system-card p { color: var(--ink-mute); font-size: 14px; line-height: 1.6; }
.system-card p + p { margin-top: 10px; }
.system-card b { color: var(--ink); }

/* ============ Focus ============ */
:focus-visible { outline: 2px solid var(--red); outline-offset: 3px; }
</style>
</head>
<body>
<div id="root"></div>

<script src="https://unpkg.com/react@18.3.1/umd/react.development.js" integrity="sha384-hD6/rw4ppMLGNu3tX5cjIb+uRZ7UkRJ6BPkLpg4hAu/6onKUg4lLsHAs9EBPT82L" crossorigin="anonymous"></script>
<script src="https://unpkg.com/react-dom@18.3.1/umd/react-dom.development.js" integrity="sha384-u6aeetuaXnQ38mYT8rp6sbXaQe3NL9t+IBXmnYxwkUI2Hw4bsp2Wvmx4yRQF1uAm" crossorigin="anonymous"></script>
<script src="https://unpkg.com/@babel/standalone@7.29.0/babel.min.js" integrity="sha384-m08KidiNqLdpJqLq95G/LEi8Qvjl/xUYll3QILypMoQ65QorJ9Lvtp2RXYGBFj1y" crossorigin="anonymous"></script>

<script>
window.__SITE_DATA__ = {
  teamsByLeague: <?= json_encode($teamsByLeague, JSON_UNESCAPED_UNICODE) ?>,
  hallOfFame: <?= json_encode($hallOfFame, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script type="text/babel">
/* ============ Data ============ */
const DIVISIONS = [
  {
    id: "elite",
    name: "Elite",
    color: "#E63946",
    logo: "/img/logo-elite.png",
    tag: "Liga principal · Top tier",
    desc: "A divisão de elite da FBA. Os melhores times do Brasil disputam o título nacional em uma temporada regular seguida de playoffs eliminatórios.",
    stats: [
      { v: "32", l: "Times" },
      { v: "20", l: "Temporadas" },
      { v: "Top", l: "Nível" },
    ],
  },
  {
    id: "next",
    name: "Next",
    color: "#2EA85B",
    logo: "/img/logo-next.png",
    tag: "Segunda divisão · Acesso à Elite",
    desc: "A porta de entrada para a Elite. Times que se destacam na Next conquistam vaga na divisão principal pela classificação direta.",
    stats: [
      { v: "30", l: "Times" },
      { v: "20", l: "Temporadas" },
      { v: "Acesso", l: "à Elite" },
    ],
  },
  {
    id: "rise",
    name: "Rise",
    color: "#2A6FDB",
    logo: "/img/logo-rise.png",
    tag: "Terceira divisão · Em ascensão",
    desc: "Para equipes em formação que querem competir em alto nível. Foco em desenvolver entrosamento e talento.",
    stats: [
      { v: "30", l: "Times" },
      { v: "15", l: "Temporadas" },
      { v: "Acesso", l: "à Next" },
    ],
  },
  {
    id: "rookie",
    name: "Rookie",
    color: "#E8862E",
    logo: "/img/logo-rookie.png",
    tag: "Liga de estreantes · Em breve",
    desc: "Liga voltada para jogadores iniciantes no competitivo de 2K. Mentoria de jogadores das divisões superiores e ambiente acolhedor para novatos.",
    stats: [
      { v: "—", l: "Times" },
      { v: "Em breve", l: "Estreia" },
      { v: "Grátis", l: "Inscrição" },
    ],
    coming: true,
  },
];

const TICKER = [
  { home: "RIO", homeName: "Rio Kings", hs: 78, away: "SPC", awayName: "SP Captains", as: 82, q: "FINAL", div: "Elite" },
  { home: "BHZ", homeName: "BH Zenith", hs: 64, away: "CWB", awayName: "Curitiba Wolves", as: 64, q: "Q4 · 02:14", div: "Elite" },
  { home: "REC", homeName: "Recife Reef", hs: 21, away: "BSB", awayName: "Brasília Bolts", as: 19, q: "Q1 · 04:08", div: "Next" },
];

const STEPS = [
  { n: "01", t: "Monte o time", b: "Reúna seu squad com até 7 jogadores. Toda equipe precisa de capitão, GM e roster definido antes da inscrição." },
  { n: "02", t: "Escolha a divisão", b: "Inscreva-se na divisão correspondente ao seu nível. A FBA avalia o histórico competitivo para validar a categoria." },
  { n: "03", t: "Temporada regular", b: "Disputa de rodadas semanais transmitidas no Twitch e YouTube oficial. Calendário fechado de jogos por divisão." },
  { n: "04", t: "Playoffs & Título", b: "Os 8 melhores de cada divisão avançam aos playoffs. Mata-mata em série melhor de 5 até a grande final." },
];

const CHAMPIONS = [
  { season: "S5", div: "Elite", title: "Rio Kings", team: "Campeão da temporada 2025 · MVP: D. Almeida", lead: true },
  { season: "S5", div: "Next", title: "BH Zenith", team: "Acesso direto à Elite S6" },
  { season: "S5", div: "Rise", title: "Salvador Surge", team: "Acesso à Next S6" },
  { season: "S4", div: "Elite", title: "SP Captains", team: "Bicampeões consecutivos" },
  { season: "S4", div: "Next", title: "Curitiba Wolves", team: "Acesso à Elite S5" },
];

/* ============ Components ============ */

function Nav() {
  return (
    <nav className="nav">
      <div className="wrap nav-inner">
        <a href="#top" className="nav-logo">
          <img src="/img/fba-logo.png" alt="FBA" />
        </a>
        <div className="nav-links">
          <a href="#about">FBA</a>
          <a href="#divisions">Divisões</a>
          <a href="#how">Como funciona</a>
          <a href="/site/gamesfba.php">Games</a>
          <a href="/site/pathetic.php">The Pathetic</a>
          <a href="#champions">Campeões</a>
        </div>
        <a href="/login.php" className="nav-cta">Jogar</a>
      </div>
    </nav>
  );
}

function Hero() {
  return (
    <header className="hero" id="top">
      <div className="wrap">
        <div className="hero-grid">
          <div>
            <span className="hero-tag">Temporada 6 · Inscrições abertas</span>
            <h1 className="display hero-title">
              Jogue<br />
              <span className="red">Basquete</span><br />
              <span className="stroke">de Verdade.</span>
            </h1>
            <p className="hero-sub">
              A maior liga competitiva de NBA 2K do Brasil. Quatro divisões,
              um caminho — da Rookie à Elite, prove o seu valor na quadra.
            </p>
            <div className="hero-ctas">
              <a href="#inscricao" className="btn btn-primary">
                Inscrever Time <span className="arrow">→</span>
              </a>
              <a href="#how" className="btn btn-ghost">
                Como funciona <span className="arrow">↓</span>
              </a>
            </div>
          </div>

          <aside className="hero-side">
            <div className="live-row">
              <span className="live-dot">Ao vivo · Hoje</span>
              <span className="mono" style={{ fontSize: 11, color: "var(--ink-dim)", letterSpacing: "0.18em" }}>RD 14</span>
            </div>
            <div className="ticker">
              {TICKER.map((m, i) => (
                <div className="ticker-match" key={i}>
                  <div className="ticker-team">
                    <span className="badge">{m.home}</span>
                    <span className="name">{m.homeName}</span>
                  </div>
                  <div className="ticker-score">
                    {m.hs}<span className="sep">·</span>{m.as}
                  </div>
                  <div className="ticker-team right">
                    <span className="name">{m.awayName}</span>
                    <span className="badge">{m.away}</span>
                  </div>
                </div>
              ))}
            </div>
            <div className="ticker-meta">
              <span>{TICKER[1].q}</span>
              <span>{TICKER[1].div}</span>
              <span>Twitch.tv/fbaoficial</span>
            </div>
          </aside>
        </div>

        <div className="marquee">
          <div className="marquee-track">
            {[...Array(2)].map((_, k) => (
              <span className="marquee-item" key={k}>
                Elite <span className="dot">●</span> Next <span className="dot">●</span> Rise <span className="dot">●</span> Rookie em breve <span className="dot">●</span> Pro-Am <span className="dot">●</span> NBA 2K <span className="dot">●</span> Brasil <span className="dot">●</span> Temporada 6 <span className="dot">●</span>&nbsp;
              </span>
            ))}
          </div>
        </div>
      </div>
    </header>
  );
}

function About() {
  return (
    <section className="section about" id="about">
      <div className="wrap">
        <div className="section-head">
          <span className="section-num">01 / Sobre</span>
          <h2>A Liga.</h2>
          <a className="section-link" href="#divisions">Divisões →</a>
        </div>

        <div className="about-grid">
          <div className="about-copy">
            <p>
              A FBA é a <span className="accent">primeira liga 100% brasileira</span> de
              NBA 2K Pro-Am com sistema de acesso e descenso entre divisões.
              Fundada em 2021, hoje reúne mais de 600 jogadores em todo o país.
            </p>
            <p>
              Cada temporada dura 4 meses e termina com playoffs eliminatórios.
              Toda a competição é transmitida ao vivo nos nossos canais oficiais,
              com narração profissional e estatísticas em tempo real.
            </p>
            <p>
              Mais do que uma liga: uma comunidade. Aqui você joga, aprende, sobe
              de nível e — quem sabe — levanta a taça.
            </p>
          </div>

          <div className="stats-grid">
            <div className="stat">
              <span className="stat-trend">+38% YoY</span>
              <div className="stat-num">624</div>
              <div className="stat-label">Jogadores ativos</div>
            </div>
            <div className="stat">
              <div className="stat-num">48</div>
              <div className="stat-label">Times credenciados</div>
            </div>
            <div className="stat">
              <div className="stat-num">5<sup>x</sup></div>
              <div className="stat-label">Temporadas disputadas</div>
            </div>
            <div className="stat">
              <span className="stat-trend">2026</span>
              <div className="stat-num">25K</div>
              <div className="stat-label">Premiação total · R$</div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

function Divisions() {
  return (
    <section className="section divisions" id="divisions">
      <div className="wrap">
        <div className="section-head">
          <span className="section-num">02 / Divisões</span>
          <h2>Quatro<br />Divisões.</h2>
          <a className="section-link" href="#teams">Os times →</a>
        </div>
      </div>
      <div className="div-list">
        {DIVISIONS.map((d, i) => (
          <div
            key={d.id}
            className={"div-row" + (d.coming ? " coming" : "")}
            id={d.id}
            style={{ "--div-color": d.color }}
          >
            <span className="div-num">0{i + 1}</span>
            <div className="div-logo">
              <img src={d.logo} alt={d.name} />
            </div>
            <div className="div-info">
              <div className="div-name">
                <span className="fba">FBA</span>
                <span>{d.name}</span>
              </div>
              <div className="div-tag">{d.tag}</div>
            </div>
            <div className="div-desc">
              <p>{d.desc}</p>
              <div className="div-stats" style={{ marginTop: 14 }}>
                {d.stats.map((s, j) => (
                  <div key={j}><b>{s.v}</b>{s.l}</div>
                ))}
              </div>
            </div>
            {d.coming ? (
              <a href="#" className="div-action disabled" aria-disabled="true">
                Em breve <span>◷</span>
              </a>
            ) : (
              <a href="/login.php" className="div-action">
                Inscrever <span>→</span>
              </a>
            )}
          </div>
        ))}
      </div>
    </section>
  );
}

function MoreContent() {
  const ITEMS = [
    {
      id: "games",
      name: "Games",
      tag: "Modos extras · Jogue agora",
      desc: "Games paralelos dentro da FBA. Teste seus conhecimentos e reflexos com os mini-games oficiais da liga.",
      color: "#2A6FDB",
      href: "/site/gamesfba.php",
      cta: "Jogar",
    },
    {
      id: "pathetic",
      name: "The Pathetic",
      tag: "Jornal da liga",
      desc: "O jornal oficial da FBA. Cobertura, bastidores e análises de cada rodada, temporada e transferência da liga.",
      color: "#E63946",
      href: "/site/pathetic.php",
      cta: "Ler",
    },
  ];
  return (
    <section className="section divisions" id="conteudo">
      <div className="wrap">
        <div className="section-head">
          <span className="section-num">06 / Conteúdo</span>
          <h2>Games<br />& The Pathetic.</h2>
          <a className="section-link" href="#hall-da-fama">Hall da Fama →</a>
        </div>
      </div>
      <div className="div-list">
        {ITEMS.map((d, i) => (
          <div
            key={d.id}
            className="div-row"
            style={{ "--div-color": d.color }}
          >
            <span className="div-num">0{i + 1}</span>
            <div className="div-logo">
              <span className="mono" style={{ fontSize: 11, color: "var(--ink-dim)" }}>Logo</span>
            </div>
            <div className="div-info">
              <div className="div-name">
                <span className="fba">FBA</span>
                <span>{d.name}</span>
              </div>
              <div className="div-tag">{d.tag}</div>
            </div>
            <div className="div-desc">{d.desc}</div>
            <a href={d.href} className="div-action">
              {d.cta} <span>→</span>
            </a>
          </div>
        ))}
      </div>
    </section>
  );
}

function HowItWorks() {
  return (
    <section className="section how" id="how">
      <div className="wrap">
        <div className="section-head">
          <span className="section-num">04 / Formato</span>
          <h2>Como<br />Funciona.</h2>
          <a className="section-link" href="#sistema">Sistema →</a>
        </div>
        <div className="how-grid">
          {STEPS.map((s) => (
            <div className="how-step" key={s.n}>
              <div className="num">{s.n}</div>
              <div className="title">{s.t}</div>
              <div className="body">{s.b}</div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

function TeamsByDivision() {
  const teamsByLeague = window.__SITE_DATA__?.teamsByLeague || {};
  const leagues = [
    { id: "ELITE", label: "Elite", color: "#E63946" },
    { id: "NEXT", label: "Next", color: "#2EA85B" },
    { id: "RISE", label: "Rise", color: "#2A6FDB" },
  ];
  const [active, setActive] = React.useState("ELITE");
  const teams = teamsByLeague[active] || [];
  const activeLeague = leagues.find((l) => l.id === active);

  return (
    <section className="section teams-section" id="teams">
      <div className="wrap">
        <div className="section-head">
          <span className="section-num">03 / Elencos</span>
          <h2>Os Times.</h2>
          <a className="section-link" href="#how">Como funciona →</a>
        </div>

        <div className="teams-tabs">
          {leagues.map((l) => (
            <button
              key={l.id}
              className={"teams-tab" + (active === l.id ? " active" : "")}
              style={{ "--tab-color": l.color }}
              onClick={() => setActive(l.id)}
            >
              {l.label} <span className="mono" style={{ opacity: 0.7 }}>({(teamsByLeague[l.id] || []).length})</span>
            </button>
          ))}
        </div>

        {teams.length > 0 ? (
          <div className="teams-grid">
            {teams.map((t, i) => (
              <div className="team-card" key={i}>
                <img src={t.photo} alt={t.name} onError={(e) => { e.target.src = "/img/default-team.png"; }} />
                <div>
                  <div className="team-card-name">{t.name}</div>
                  {t.conf && <div className="team-card-conf">{t.conf}</div>}
                </div>
              </div>
            ))}
          </div>
        ) : (
          <p style={{ color: "var(--ink-dim)", fontSize: 14 }}>Nenhum time cadastrado nessa divisão ainda.</p>
        )}
      </div>
    </section>
  );
}

function SystemFBA() {
  const CARDS = [
    {
      icon: "bi-calendar-range",
      title: "20 Temporadas",
      body: "Cada ciclo (sprint) da Elite e da Next dura 20 temporadas. Na Rise, 15. Ao final do sprint, os times são reclassificados de acordo com a pontuação acumulada.",
    },
    {
      icon: "bi-person-plus-fill",
      title: "Draft em 2 Rodadas",
      body: "Todo início de temporada tem draft de novatos: 1ª rodada no formato completo, com tempo por pick, e 2ª rodada em formato rápido de ofertas.",
    },
    {
      icon: "bi-cash-stack",
      title: "Salary Cap",
      body: "Cada time monta seu elenco respeitando um teto salarial. Trocas, contratações e o mercado de free agency giram em torno do cap disponível.",
    },
    {
      icon: "bi-arrow-down-up",
      title: "Acesso e Descenso",
      body: "Ao fim de cada temporada, os melhores colocados de Next e Rise sobem de divisão, e os piores colocados de cada divisão caem — sempre com risco e recompensa em jogo.",
    },
  ];
  return (
    <section className="section system" id="sistema">
      <div className="wrap">
        <div className="section-head">
          <span className="section-num">05 / Sistema</span>
          <h2>Como<br />Jogamos.</h2>
          <a className="section-link" href="#conteudo">Conteúdo →</a>
        </div>
        <div className="system-grid">
          {CARDS.map((c) => (
            <div className="system-card" key={c.title}>
              <i className={"bi " + c.icon}></i>
              <h3>{c.title}</h3>
              <p>{c.body}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

function HallOfFame() {
  const items = window.__SITE_DATA__?.hallOfFame || [];
  if (!items.length) return null;
  return (
    <section className="section hof-section" id="hall-da-fama">
      <div className="wrap">
        <div className="section-head">
          <span className="section-num">07 / Lendas</span>
          <h2>Hall da<br />Fama.</h2>
          <a className="section-link" href="#champions">Campeões →</a>
        </div>
        <div className="hof-grid">
          {items.map((h, i) => (
            <div className="hof-card" key={i}>
              <div className="hof-rank">{String(i + 1).padStart(2, "0")}</div>
              <div className="hof-info">
                <div className="hof-name">{h.team_name}</div>
                <div className="hof-meta">{h.gm_name}{h.league ? " · " + h.league : ""}</div>
                <div className="hof-titles">{h.titles} título{h.titles == 1 ? "" : "s"}</div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

function Champions() {
  return (
    <section className="section champs" id="champions">
      <div className="wrap">
        <div className="section-head">
          <span className="section-num">08 / Histórico</span>
          <h2>Campeões.</h2>
          <a className="section-link" href="#top">Voltar ao topo →</a>
        </div>
        <div className="champ-grid">
          {CHAMPIONS.map((c, i) => (
            <article key={i} className={"champ-card" + (c.lead ? " lead" : "")}>
              <div className="champ-img">
                [ Foto do time campeão ]<br />{c.title} · {c.season}
              </div>
              <div className="champ-content">
                <div className="champ-meta">
                  <span className="pill">{c.season}</span>
                  <span>{c.div}</span>
                </div>
                <div className="champ-title">{c.title}</div>
                <div className="champ-team">{c.team}</div>
              </div>
            </article>
          ))}
        </div>
      </div>
    </section>
  );
}

function CtaBanner() {
  return (
    <section className="cta-banner" id="inscricao">
      <div className="wrap cta-inner">
        <h2>
          A próxima<br />
          temporada começa<br />
          em 6 semanas.
        </h2>
        <a href="#" className="btn btn-primary">
          Inscrever meu time <span className="arrow">→</span>
        </a>
      </div>
    </section>
  );
}

function Footer() {
  return (
    <footer className="foot">
      <div className="wrap">
        <div className="foot-grid">
          <div className="foot-brand">
            <img src="/img/fba-logo.png" alt="FBA" />
            <p>A maior liga competitiva de NBA 2K do Brasil. Fundada em 2021.</p>
          </div>
          <div className="foot-col">
            <h4>Liga</h4>
            <ul>
              <li><a href="#about">Sobre a FBA</a></li>
              <li><a href="#games">Games</a></li>
              <li><a href="#pathetic">The Pathetic</a></li>
              <li><a href="#how">Regulamento</a></li>
              <li><a href="#champions">Hall of fame</a></li>
            </ul>
          </div>
          <div className="foot-col">
            <h4>Jogar</h4>
            <ul>
              <li><a href="#inscricao">Inscrever time</a></li>
              <li><a href="#">Free agency</a></li>
              <li><a href="#">Calendário</a></li>
              <li><a href="#">Premiação</a></li>
            </ul>
          </div>
          <div className="foot-col">
            <h4>Conectar</h4>
            <ul>
              <li><a href="#">Discord</a></li>
              <li><a href="#">Instagram</a></li>
              <li><a href="#">Twitch</a></li>
              <li><a href="#">YouTube</a></li>
            </ul>
          </div>
        </div>
        <div className="foot-bottom">
          <span>© 2026 FBA · Federação Brasileira de Arena</span>
          <span>Não afiliada à NBA ou Take-Two Interactive</span>
        </div>
      </div>
    </footer>
  );
}

/* ============ App ============ */
function App() {
  return (
    <>
      <Nav />
      <Hero />
      <About />
      <Divisions />
      <TeamsByDivision />
      <HowItWorks />
      <SystemFBA />
      <MoreContent />
      <HallOfFame />
      <Champions />
      <CtaBanner />
      <Footer />
    </>
  );
}

ReactDOM.createRoot(document.getElementById("root")).render(<App />);
</script>
</body>
</html>
