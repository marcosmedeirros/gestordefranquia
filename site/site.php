<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
$pdo = db();
$isLoggedIn = (bool) getUserSession();

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

// Números reais do site — nada fabricado. Times/temporada por liga, total de
// times e usuários aprovados. Alimenta os cards de estatística da página.
$leagueStats = ['ELITE' => ['teams' => 0, 'season' => null], 'NEXT' => ['teams' => 0, 'season' => null], 'RISE' => ['teams' => 0, 'season' => null], 'ROOKIE' => ['teams' => 0, 'season' => null]];
try {
    $stmt = $pdo->query("SELECT league, COUNT(*) c FROM teams GROUP BY league");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($leagueStats[$r['league']])) $leagueStats[$r['league']]['teams'] = (int)$r['c'];
    }
    $stmt = $pdo->query("SELECT league, MAX(season_number) sn FROM seasons GROUP BY league");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($leagueStats[$r['league']])) $leagueStats[$r['league']]['season'] = (int)$r['sn'];
    }
} catch (Exception $e) {}
$totalTeams = array_sum(array_column($leagueStats, 'teams'));

$totalActivePlayers = 0;
try {
    $totalActivePlayers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE approved = 1")->fetchColumn();
} catch (Exception $e) {}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>FBA · Fantasy Basquete Brasil - Liga de NBA 2K</title>
<meta name="description" content="A FBA é a maior liga de fantasy basquete e NBA 2K Pro-Am do Brasil. Monte seu time, dispute o draft, feche trocas e seja o GM. Entre na lista de espera e jogue basquete de verdade." />
<meta name="keywords" content="fantasy basquete, FBA, NBA fantasy, liga de basquete, NBA 2K, fantasy NBA Brasil, liga de NBA 2K, Pro-Am" />
<meta name="robots" content="index, follow" />
<link rel="canonical" href="https://fbabrasil.com.br/" />

<meta property="og:type" content="website" />
<meta property="og:site_name" content="FBA - Fantasy Basquete Brasil" />
<meta property="og:title" content="FBA · Fantasy Basquete Brasil - Liga de NBA 2K" />
<meta property="og:description" content="A maior liga de fantasy basquete e NBA 2K Pro-Am do Brasil. Monte seu time, dispute o draft, feche trocas e seja o GM." />
<meta property="og:url" content="https://fbabrasil.com.br/" />
<meta property="og:image" content="https://fbabrasil.com.br/img/fba-logo-default-cropped.png" />
<meta property="og:locale" content="pt_BR" />

<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="FBA · Fantasy Basquete Brasil - Liga de NBA 2K" />
<meta name="twitter:description" content="A maior liga de fantasy basquete e NBA 2K Pro-Am do Brasil. Monte seu time, dispute o draft, feche trocas e seja o GM." />
<meta name="twitter:image" content="https://fbabrasil.com.br/img/fba-logo-default-cropped.png" />

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "SportsOrganization",
  "name": "FBA - Fantasy Basquete Brasil",
  "alternateName": "FBA",
  "url": "https://fbabrasil.com.br/",
  "logo": "https://fbabrasil.com.br/img/fba-logo-default-cropped.png",
  "description": "A maior liga de fantasy basquete e NBA 2K Pro-Am do Brasil, com sistema de acesso e descenso entre divisões.",
  "sport": "Basketball",
  "areaServed": "BR"
}
</script>

<link rel="icon" type="image/png" href="/img/fba-logo-default-cropped.png" />
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Inter:wght@500;600&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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

  --max: 1320px;
  --pad: clamp(20px, 4vw, 64px);

  --font-display: "Montserrat", -apple-system, "Helvetica Neue", Arial, sans-serif;
  --font-body: "Montserrat", -apple-system, "Helvetica Neue", Arial, sans-serif;
  --font-mono: "Montserrat", -apple-system, "Helvetica Neue", Arial, sans-serif;
  --font-nav: "Inter", -apple-system, "Helvetica Neue", Arial, sans-serif;

  --radius-lg: 26px;
  --radius-md: 18px;
  --radius-sm: 12px;
  --shadow-soft: 0 16px 40px -12px rgba(0,0,0,.5);
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
  font-weight: 800;
  letter-spacing: -0.03em;
  line-height: 1.04;
}
.mono { font-family: var(--font-mono); font-weight: 700; letter-spacing: 0.08em; }
.eyebrow {
  font-family: var(--font-mono);
  font-weight: 700;
  font-size: 12px;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ink-mute);
}

/* ============ Nav (mantido igual ao original — nao mexer) ============ */
.nav {
  position: fixed; inset: 0 0 auto 0;
  z-index: 50;
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  background: rgba(10,10,10,0.72);
  border-bottom: 1px solid var(--line);
  font-family: var(--font-nav);
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
  padding-top: 108px;
  padding-bottom: 56px;
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
  font-size: clamp(38px, 6vw, 80px);
  margin: 0;
}
.hero-title .red { color: var(--red); }
.hero-title .stroke {
  -webkit-text-stroke: 2px var(--ink);
  color: transparent;
}
.hero-tag {
  display: inline-flex; align-items: center; gap: 10px;
  padding: 7px 14px;
  border-radius: 100px;
  border: 1px solid var(--red);
  color: var(--red);
  font-family: var(--font-mono);
  font-weight: 600;
  font-size: 11px;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  margin-bottom: 22px;
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
  display: inline-flex; align-items: center; gap: 10px;
  padding: 15px 26px;
  border-radius: 100px;
  font-family: var(--font-display);
  font-weight: 700;
  font-size: 15px;
  letter-spacing: -0.005em;
  transition: transform .2s, background .2s, color .2s, border-color .2s;
}
.btn-primary {
  background: var(--red);
  color: white;
}
.btn-primary:hover { background: var(--red-deep); transform: translateY(-2px); }
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
  border-radius: var(--radius-lg);
  background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0));
  padding: 26px;
  position: relative;
  overflow: hidden;
}
.hero-side::before {
  content: "";
  position: absolute; top: 0; left: 0;
  width: 40px; height: 4px; background: var(--red);
  border-radius: 0 0 4px 0;
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
.league-status-list { margin-top: 18px; }
.league-status-row {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px;
  padding: 14px 0;
  border-top: 1px solid var(--line);
}
.league-status-row:last-of-type { border-bottom: 1px solid var(--line); }
.league-status-name { display: flex; align-items: center; gap: 10px; min-width: 0; }
.league-status-name .badge {
  width: 40px; height: 26px;
  border-radius: 8px;
  background: var(--bg-3); border: 1px solid var(--line);
  display: grid; place-items: center;
  font-family: var(--font-display); font-weight: 700; font-size: 10px;
}
.league-status-name .name { font-weight: 700; font-size: 14px; }
.league-status-info { font-size: 12.5px; color: var(--ink-mute); white-space: nowrap; }
.ticker-meta {
  display: flex; justify-content: space-between;
  font-family: var(--font-mono); font-size: 10px;
  letter-spacing: 0.18em; text-transform: uppercase; color: var(--ink-dim);
  margin-top: 14px;
}

/* Hero marquee */
.marquee {
  margin-top: 44px;
  border-top: 1px solid var(--line);
  border-bottom: 1px solid var(--line);
  padding: 14px 0;
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
  font-weight: 700;
  font-size: 20px;
  letter-spacing: 0.01em;
  color: var(--ink-mute);
  display: inline-flex; align-items: center; gap: 56px;
}
.marquee-item .dot { color: var(--red); }
@keyframes marquee {
  from { transform: translateX(0); }
  to { transform: translateX(-50%); }
}

/* ============ Section header ============ */
.section { padding: 60px 0; position: relative; }
.section-head {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 24px;
  align-items: end;
  margin-bottom: 36px;
}
.section-head h2 {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: clamp(28px, 4vw, 46px);
  line-height: 1.08;
  margin: 0;
  letter-spacing: -0.02em;
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
  gap: 48px;
  align-items: start;
}
@media (max-width: 900px) { .about-grid { grid-template-columns: 1fr; gap: 32px; } }
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
  gap: 14px;
}
.stat {
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 28px 24px;
  position: relative;
  transition: border-color .25s;
}
.stat:hover { border-color: var(--line-2); }
.stat-num {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: 52px;
  line-height: 1;
  letter-spacing: -0.02em;
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
.divisions-intro {
  font-size: 17px;
  line-height: 1.6;
  color: var(--ink-mute);
  max-width: 760px;
  margin: -12px 0 32px;
}
.divisions-intro b { color: var(--ink); }
.div-list {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}
@media (max-width: 820px) { .div-list { grid-template-columns: 1fr; } }

.div-row {
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: var(--radius-lg);
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 28px;
  cursor: pointer;
  position: relative;
  overflow: hidden;
  transition: border-color .3s, transform .3s, box-shadow .3s;
}
.div-row:hover { border-color: var(--div-color, var(--line-2)); transform: translateY(-4px); box-shadow: var(--shadow-soft); }
.div-row::before {
  content: "";
  position: absolute; top: -60%; right: -20%;
  width: 60%; aspect-ratio: 1;
  background: radial-gradient(circle, var(--div-color, var(--red)) 0%, transparent 70%);
  opacity: 0;
  pointer-events: none;
  transition: opacity .35s;
}
.div-row > * { position: relative; }
.div-row:hover::before { opacity: 0.16; }
.div-top { display: flex; align-items: center; gap: 16px; }
.div-num {
  font-family: var(--font-mono);
  font-weight: 700;
  font-size: 12px;
  color: var(--ink-dim);
  letter-spacing: 0.1em;
}
.div-logo {
  width: 60px; height: 60px;
  display: grid; place-items: center;
  background: var(--bg-3);
  border: 1px solid var(--line);
  border-radius: var(--radius-sm);
  flex-shrink: 0;
}
.div-logo img { max-width: 65%; max-height: 65%; }
.div-info { min-width: 0; }
.div-name {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: 24px;
  line-height: 1.1;
  display: flex; align-items: baseline; gap: 8px;
  color: var(--div-color);
}
.div-name .fba {
  -webkit-text-stroke: 1px var(--ink);
  color: transparent;
  font-size: 0.5em;
  letter-spacing: 0.04em;
}
.div-tag {
  font-family: var(--font-mono);
  font-weight: 600;
  font-size: 11px;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--ink-mute);
  margin-top: 4px;
}
.div-desc {
  font-size: 14.5px;
  color: var(--ink-mute);
  line-height: 1.6;
  text-wrap: pretty;
  flex: 1;
}
.div-stats {
  display: flex; gap: 24px;
  font-family: var(--font-mono); font-size: 10.5px;
  letter-spacing: 0.08em; text-transform: uppercase; color: var(--ink-dim);
  padding-top: 16px;
  border-top: 1px solid var(--line);
}
.div-stats b {
  display: block;
  font-family: var(--font-display);
  font-size: 22px;
  color: var(--ink);
  font-weight: 800;
  letter-spacing: -0.01em;
  line-height: 1;
  margin-bottom: 6px;
}
.div-action {
  display: inline-flex; align-items: center; gap: 10px;
  padding: 12px 20px;
  border-radius: 100px;
  background: var(--div-color);
  color: white;
  font-family: var(--font-display);
  font-weight: 700;
  font-size: 14px;
  letter-spacing: -0.005em;
  transition: gap .2s, transform .2s;
  white-space: nowrap;
  align-self: flex-start;
}
.div-action:hover { gap: 16px; }

/* ============ How it works ============ */
.how {
  background: var(--bg);
  border-top: 1px solid var(--line);
}
.how-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
}
@media (max-width: 900px) { .how-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 560px) { .how-grid { grid-template-columns: 1fr; } }
.how-step {
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 16px;
  padding: 32px 28px 36px;
  min-height: 280px;
  display: flex; flex-direction: column;
  position: relative;
  overflow: hidden;
  transition: border-color .3s;
}
.how-step:hover { border-color: var(--line-2); }
.how-step .num {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: 44px;
  color: var(--red);
  line-height: 1;
}
.how-step .title {
  font-family: var(--font-display);
  font-weight: 700;
  font-size: 20px;
  margin: 16px 0 10px;
  letter-spacing: -0.01em;
}
.how-step .body { color: var(--ink-mute); font-size: 15px; line-height: 1.55; }
.how-step::after {
  content: "";
  position: absolute; left: 0; bottom: 0;
  width: 0; height: 3px; background: var(--red);
  transition: width .5s;
}
.how-step:hover::after { width: 100%; }

/* ============ CTA banner ============ */
.cta-banner {
  background: var(--red);
  color: white;
  padding: 56px 0;
  position: relative;
  overflow: hidden;
  border-radius: var(--radius-lg);
  margin: 0 var(--pad);
  width: auto;
}
.cta-banner::before {
  content: "FBA · FBA · FBA · FBA · FBA · FBA · FBA · FBA · FBA · FBA";
  position: absolute; inset: 0;
  font-family: var(--font-display); font-weight: 800; font-size: 200px;
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
  font-weight: 800;
  font-size: clamp(28px, 4.5vw, 48px);
  margin: 0;
  line-height: 1.15;
  letter-spacing: -0.02em;
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
  padding: 56px 0 32px;
}
.foot-grid {
  display: grid;
  grid-template-columns: 1.4fr 1fr 1fr 1fr;
  gap: 32px;
  margin-bottom: 40px;
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
  border-radius: 100px;
  border: 1px solid var(--line-2);
  color: var(--ink-mute);
  font-family: var(--font-mono);
  font-weight: 700;
  font-size: 12px;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  transition: all .2s;
}
.teams-tab.active { background: var(--tab-color, var(--red)); border-color: var(--tab-color, var(--red)); color: white; }
.teams-tab:hover:not(.active) { border-color: var(--ink-mute); color: var(--ink); }
.teams-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 12px;
}
.team-card {
  background: var(--bg-2);
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 20px 14px;
  display: flex; flex-direction: column; align-items: center; gap: 10px;
  text-align: center;
  transition: border-color .2s, transform .2s;
}
.team-card:hover { border-color: var(--line-2); transform: translateY(-2px); }
.team-card img { width: 52px; height: 52px; object-fit: cover; border-radius: 8px; border: 1px solid var(--line-2); background: var(--bg-3); }
.team-card-name { font-size: 12px; font-weight: 600; color: var(--ink); line-height: 1.3; }
.team-card-conf { font-family: var(--font-mono); font-size: 9px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--ink-dim); }

/* ============ Hall da Fama ============ */
.hof-section { background: var(--bg-2); border-top: 1px solid var(--line); }
.hof-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 14px;
}
.hof-card {
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 24px;
  display: flex; align-items: center; gap: 16px;
  transition: border-color .2s;
}
.hof-card:hover { border-color: var(--line-2); }
.hof-rank { font-family: var(--font-display); font-weight: 800; font-size: 30px; color: var(--red); line-height: 1; flex-shrink: 0; width: 48px; }
.hof-info { min-width: 0; }
.hof-name { font-size: 15px; font-weight: 700; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hof-meta { font-size: 12px; color: var(--ink-mute); margin-top: 3px; }
.hof-titles { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--red); margin-top: 5px; }

/* ============ Como Jogamos ============ */
.system { background: var(--bg); border-top: 1px solid var(--line); }
.system-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 14px;
}
.system-card { background: var(--bg-2); border: 1px solid var(--line); border-radius: 16px; padding: 28px; transition: border-color .2s; }
.system-card:hover { border-color: var(--line-2); }
.system-card i { font-size: 26px; color: var(--red); margin-bottom: 14px; display: block; }
.system-card h3 { font-family: var(--font-display); font-weight: 700; font-size: 19px; margin-bottom: 10px; letter-spacing: -0.01em; }
.system-card p { color: var(--ink-mute); font-size: 14px; line-height: 1.6; }
.system-card p + p { margin-top: 10px; }
.system-card b { color: var(--ink); }

/* ============ Waitlist modal ============ */
.modal-overlay {
  position: fixed; inset: 0; z-index: 200;
  background: rgba(0,0,0,.7);
  backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
  display: flex; align-items: center; justify-content: center;
  padding: 20px;
}
.modal-card {
  width: 100%; max-width: 420px;
  background: linear-gradient(180deg, var(--bg-3), var(--bg-2));
  border: 1px solid var(--line-2);
  border-radius: var(--radius-lg);
  padding: 26px;
  box-shadow: var(--shadow-soft);
}
.modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.modal-close {
  width: 30px; height: 30px; border-radius: 100px;
  border: 1px solid var(--line-2); color: var(--ink-mute);
  display: grid; place-items: center; font-size: 13px;
  transition: all .2s;
}
.modal-close:hover { color: var(--ink); border-color: var(--ink); }
.modal-title {
  font-family: var(--font-display); font-weight: 800; font-size: 24px;
  letter-spacing: -0.02em; margin: 4px 0 6px;
}
.modal-sub { color: var(--ink-mute); font-size: 13.5px; line-height: 1.5; margin-bottom: 18px; }
.modal-error {
  background: rgba(230,57,70,.12); border: 1px solid rgba(230,57,70,.3);
  color: #ff8a93; font-size: 13px; padding: 10px 14px; border-radius: 10px;
  margin-bottom: 14px;
}
.modal-label {
  display: block; font-family: var(--font-mono); font-weight: 700; font-size: 10.5px;
  letter-spacing: 0.1em; text-transform: uppercase; color: var(--ink-mute);
  margin: 14px 0 6px;
}
.modal-input {
  width: 100%; background: var(--bg); border: 1px solid var(--line-2);
  border-radius: var(--radius-sm); padding: 12px 14px;
  color: var(--ink); font-family: var(--font-body); font-size: 15px;
}
.modal-input:focus { outline: none; border-color: var(--red); }
.modal-success { text-align: center; padding: 10px 0 4px; }
.modal-success p { color: var(--ink-mute); font-size: 15px; line-height: 1.6; margin-bottom: 18px; }

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
window.__IS_LOGGED_IN__ = <?= $isLoggedIn ? 'true' : 'false' ?>;
window.__SITE_DATA__ = {
  teamsByLeague: <?= json_encode($teamsByLeague, JSON_UNESCAPED_UNICODE) ?>,
  hallOfFame: <?= json_encode($hallOfFame, JSON_UNESCAPED_UNICODE) ?>,
  leagueStats: <?= json_encode($leagueStats, JSON_UNESCAPED_UNICODE) ?>,
  totalTeams: <?= json_encode($totalTeams) ?>,
  totalActivePlayers: <?= json_encode($totalActivePlayers) ?>
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
    desc: "A divisão de elite da FBA. Os melhores times do Brasil disputam o título nacional em uma temporada regular seguida de playoffs eliminatórios. O campeão leva premiação em dinheiro e muito mais.",
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
    tag: "Liga de estreantes · Primeira temporada em breve",
    desc: "Liga voltada para jogadores iniciantes no competitivo de 2K. Mentoria de jogadores das divisões superiores e ambiente acolhedor para novatos.",
    stats: [
      { v: "3", l: "Times" },
      { v: "Em breve", l: "Estreia" },
      { v: "Fila", l: "de espera" },
    ],
    waitlist: true,
  },
];

const LEAGUE_STATUS = [
  { id: "ELITE", abbr: "ELT", name: "Elite", color: "#E63946" },
  { id: "NEXT", abbr: "NXT", name: "Next", color: "#2EA85B" },
  { id: "RISE", abbr: "RSE", name: "Rise", color: "#2A6FDB" },
];

const STEPS = [
  { n: "01", t: "Monte o time", b: "Reúna seu squad com até 7 jogadores. Toda equipe precisa de capitão, GM e roster definido antes da inscrição." },
  { n: "02", t: "Escolha a divisão", b: "Inscreva-se na divisão correspondente ao seu nível. A FBA avalia o histórico competitivo para validar a categoria." },
  { n: "03", t: "Temporada regular", b: "Disputa de rodadas semanais transmitidas no Twitch e YouTube oficial. Calendário fechado de jogos por divisão." },
  { n: "04", t: "Playoffs & Título", b: "Os 8 melhores de cada divisão avançam aos playoffs. Mata-mata em série melhor de 5 até a grande final." },
];

/* ============ Components ============ */

function Nav() {
  return (
    <nav className="nav">
      <div className="wrap nav-inner">
        <a href="#top" className="nav-logo">
          <img src="/img/fba-logo-web.png" alt="FBA" />
        </a>
        <div className="nav-links">
          <a href="#about">FBA</a>
          <a href="#divisions">Divisões</a>
          <a href="#how">Como funciona</a>
          <a href="/site/gamesfba.php">Games</a>
          <a href="/site/pathetic.php">The Pathetic</a>
        </div>
        <a href={window.__IS_LOGGED_IN__ ? "/dashboard.php" : "/login.php"} className="nav-cta">Jogar</a>
      </div>
    </nav>
  );
}

function Hero({ onOpenWaitlist }) {
  return (
    <header className="hero" id="top">
      <div className="wrap">
        <div className="hero-grid">
          <div>
            <span className="hero-tag">Lista de espera aberta</span>
            <h1 className="display hero-title">
              Jogue <span className="red">basquete</span><br />
              de verdade.
            </h1>
            <p className="hero-sub">
              A maior liga competitiva de NBA 2K do Brasil. Quatro divisões,
              um caminho — da Rookie à Elite, prove o seu valor na quadra.
            </p>
            <div className="hero-ctas">
              <button type="button" onClick={onOpenWaitlist} className="btn btn-primary" style={{ border: "none" }}>
                Entrar na lista de espera <span className="arrow">→</span>
              </button>
              <a href="https://www.youtube.com/@fba2kleaguebrasil" target="_blank" rel="noopener noreferrer" className="btn btn-ghost">
                <i className="bi bi-youtube"></i> YouTube da FBA
              </a>
            </div>
          </div>

          <aside className="hero-side">
            <div className="live-row">
              <span className="live-dot">Liga em andamento</span>
            </div>
            <div className="league-status-list">
              {LEAGUE_STATUS.map((lg) => {
                const s = window.__SITE_DATA__?.leagueStats?.[lg.id];
                return (
                  <div className="league-status-row" key={lg.id}>
                    <div className="league-status-name">
                      <span className="badge" style={{ color: lg.color, borderColor: lg.color }}>{lg.abbr}</span>
                      <span className="name">{lg.name}</span>
                    </div>
                    <div className="league-status-info">
                      {s?.season ? `${s.season}ª temporada` : "Em breve"} · {s?.teams ?? 0} times
                    </div>
                  </div>
                );
              })}
            </div>
            <div className="ticker-meta">
              <span>{window.__SITE_DATA__?.totalTeams ?? 0} times</span>
              <span>{window.__SITE_DATA__?.totalActivePlayers ?? 0} jogadores</span>
            </div>
          </aside>
        </div>

        <div className="marquee">
          <div className="marquee-track">
            {[...Array(2)].map((_, k) => (
              <span className="marquee-item" key={k}>
                Elite <span className="dot">●</span> Next <span className="dot">●</span> Rise <span className="dot">●</span> Rookie: lista de espera <span className="dot">●</span> Pro-Am <span className="dot">●</span> NBA 2K <span className="dot">●</span> Brasil <span className="dot">●</span>&nbsp;
              </span>
            ))}
          </div>
        </div>
      </div>
    </header>
  );
}

function About() {
  const d = window.__SITE_DATA__ || {};
  const totalTeams = d.totalTeams ?? 0;
  const totalPlayers = d.totalActivePlayers ?? 0;
  const eliteSeason = d.leagueStats?.ELITE?.season;

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
              Fundada em 2021, hoje reúne {totalPlayers} jogadores em {totalTeams} times ativos em todo o país.
            </p>
            <p>
              Cada temporada tem playoffs eliminatórios, com toda a competição
              acompanhada de perto pela comunidade — do draft até a grande final.
            </p>
            <p>
              Mais do que uma liga: uma comunidade. Aqui você joga, aprende, sobe
              de nível e — quem sabe — levanta a taça.
            </p>
          </div>

          <div className="stats-grid">
            <div className="stat">
              <div className="stat-num">{totalPlayers}</div>
              <div className="stat-label">Jogadores ativos</div>
            </div>
            <div className="stat">
              <div className="stat-num">32</div>
              <div className="stat-label">Times na Elite</div>
            </div>
            <div className="stat">
              <div className="stat-num">{eliteSeason ? eliteSeason + "ª" : "—"}</div>
              <div className="stat-label">Temporada da Elite</div>
            </div>
            <div className="stat">
              <div className="stat-num">{totalTeams}</div>
              <div className="stat-label">Times em todas as ligas</div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

function Divisions({ onOpenWaitlist }) {
  return (
    <section className="section divisions" id="divisions">
      <div className="wrap">
        <div className="section-head">
          <span className="section-num">02 / Divisões</span>
          <h2>Quatro Divisões.</h2>
          <a className="section-link" href="#teams">Os times →</a>
        </div>
        <p className="divisions-intro">
          Sistema de acesso e descenso: a cada temporada, <b>4 times sobem</b> e <b>4 times descem</b> entre
          as divisões. Na Elite, o campeão fatura <b>premiação em dinheiro</b> e muito mais.
        </p>
        <div className="div-list">
          {DIVISIONS.map((d, i) => (
            <div
              key={d.id}
              className="div-row"
              id={d.id}
              style={{ "--div-color": d.color }}
            >
              <div className="div-top">
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
                <span className="div-num" style={{ marginLeft: "auto" }}>0{i + 1}</span>
              </div>
              <div className="div-desc">{d.desc}</div>
              <div className="div-stats">
                {d.stats.map((s, j) => (
                  <div key={j}><b>{s.v}</b>{s.l}</div>
                ))}
              </div>
              {d.waitlist ? (
                <button type="button" onClick={onOpenWaitlist} className="div-action" style={{ border: "none" }}>
                  Entrar na lista de espera <span>→</span>
                </button>
              ) : (
                <a href="#teams" className="div-action">
                  Ver elenco <span>→</span>
                </a>
              )}
            </div>
          ))}
        </div>
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
          <h2>Games &amp; The Pathetic.</h2>
          <a className="section-link" href="#hall-da-fama">Hall da Fama →</a>
        </div>
        <div className="div-list">
          {ITEMS.map((d, i) => (
            <div
              key={d.id}
              className="div-row"
              style={{ "--div-color": d.color }}
            >
              <div className="div-top">
                <div className="div-logo">
                  <i className={"bi " + (d.id === "games" ? "bi-controller" : "bi-newspaper")} style={{ fontSize: 22, color: d.color }}></i>
                </div>
                <div className="div-info">
                  <div className="div-name">
                    <span className="fba">FBA</span>
                    <span>{d.name}</span>
                  </div>
                  <div className="div-tag">{d.tag}</div>
                </div>
                <span className="div-num" style={{ marginLeft: "auto" }}>0{i + 1}</span>
              </div>
              <div className="div-desc">{d.desc}</div>
              <a href={d.href} className="div-action">
                {d.cta} <span>→</span>
              </a>
            </div>
          ))}
        </div>
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
          <h2>Como Funciona.</h2>
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
          <h2>Como Jogamos.</h2>
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
          <h2>Hall da Fama.</h2>
          <a className="section-link" href="#inscricao">Inscreva-se →</a>
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

function CtaBanner({ onOpenWaitlist }) {
  return (
    <section className="cta-banner" id="inscricao">
      <div className="wrap cta-inner">
        <h2>
          Bora entrar<br />
          na próxima<br />
          temporada?
        </h2>
        <button type="button" onClick={onOpenWaitlist} className="btn btn-primary" style={{ border: "none" }}>
          Entrar na lista de espera <span className="arrow">→</span>
        </button>
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
            <img src="/img/fba-logo-web.png" alt="FBA" />
            <p>A maior liga competitiva de NBA 2K do Brasil. Fundada em 2021.</p>
          </div>
          <div className="foot-col">
            <h4>Liga</h4>
            <ul>
              <li><a href="#about">Sobre a FBA</a></li>
              <li><a href="/site/gamesfba.php">Games</a></li>
              <li><a href="/site/pathetic.php">The Pathetic</a></li>
              <li><a href="#how">Regulamento</a></li>
              <li><a href="#hall-da-fama">Hall of fame</a></li>
            </ul>
          </div>
          <div className="foot-col">
            <h4>Jogar</h4>
            <ul>
              <li><a href="#inscricao">Inscrever time</a></li>
              <li><a href="#">Free agency</a></li>
              <li><a href="#">Calendário</a></li>
            </ul>
          </div>
          <div className="foot-col">
            <h4>Conectar</h4>
            <ul>
              <li><a href="#">Discord</a></li>
              <li><a href="#">Instagram</a></li>
              <li><a href="#">Twitch</a></li>
              <li><a href="https://www.youtube.com/@fba2kleaguebrasil" target="_blank" rel="noopener noreferrer">YouTube</a></li>
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

function WaitlistModal({ open, onClose }) {
  const [name, setName] = React.useState("");
  const [phone, setPhone] = React.useState("");
  const [status, setStatus] = React.useState("idle"); // idle | sending | done | error
  const [message, setMessage] = React.useState("");

  React.useEffect(() => {
    if (open) {
      document.body.style.overflow = "hidden";
    } else {
      document.body.style.overflow = "";
    }
    return () => { document.body.style.overflow = ""; };
  }, [open]);

  if (!open) return null;

  const handleClose = () => {
    onClose();
    setTimeout(() => { setStatus("idle"); setMessage(""); setName(""); setPhone(""); }, 250);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setStatus("sending");
    try {
      const res = await fetch("/api/waitlist.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name, phone: phone.replace(/\D/g, "") }),
      });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) throw body;
      setStatus("done");
      setMessage(body.message || "Obrigado pelo contato! Você está na lista de espera.");
    } catch (err) {
      setStatus("error");
      setMessage(err.error || "Erro ao enviar pedido. Tenta de novo.");
    }
  };

  return (
    <div className="modal-overlay" onClick={handleClose}>
      <div className="modal-card" onClick={(e) => e.stopPropagation()}>
        <div className="modal-head">
          <span className="eyebrow">Lista de espera · Rookie</span>
          <button type="button" className="modal-close" onClick={handleClose} aria-label="Fechar">✕</button>
        </div>
        <h3 className="modal-title">Entrar na lista de espera</h3>
        {status === "done" ? (
          <div className="modal-success">
            <p>{message}</p>
            <button type="button" className="btn btn-primary" onClick={handleClose}>Fechar</button>
          </div>
        ) : (
          <form onSubmit={handleSubmit}>
            <p className="modal-sub">Deixe seu nome e telefone que a gente entra em contato pelo WhatsApp com o link de cadastro.</p>
            {status === "error" && <div className="modal-error">{message}</div>}
            <label className="modal-label">Nome completo</label>
            <input className="modal-input" value={name} onChange={(e) => setName(e.target.value)} placeholder="Seu nome completo" required />
            <label className="modal-label">Telefone (WhatsApp)</label>
            <input className="modal-input" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="Ex.: 55999999999" type="tel" maxLength={13} required />
            <button type="submit" className="btn btn-primary" style={{ width: "100%", justifyContent: "center", marginTop: 14 }} disabled={status === "sending"}>
              {status === "sending" ? "Enviando..." : "Solicitar participação"}
            </button>
          </form>
        )}
      </div>
    </div>
  );
}

/* ============ App ============ */
function App() {
  const [waitlistOpen, setWaitlistOpen] = React.useState(false);
  const openWaitlist = () => setWaitlistOpen(true);
  return (
    <>
      <Nav />
      <Hero onOpenWaitlist={openWaitlist} />
      <About />
      <Divisions onOpenWaitlist={openWaitlist} />
      <TeamsByDivision />
      <HowItWorks />
      <SystemFBA />
      <MoreContent />
      <HallOfFame />
      <CtaBanner onOpenWaitlist={openWaitlist} />
      <Footer />
      <WaitlistModal open={waitlistOpen} onClose={() => setWaitlistOpen(false)} />
    </>
  );
}

ReactDOM.createRoot(document.getElementById("root")).render(<App />);
</script>
</body>
</html>
