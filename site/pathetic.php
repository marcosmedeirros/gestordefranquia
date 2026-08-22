<?php
// A LANDING DO JORNAL — a cara pública do The Pathetic.
//
// Mostra as MESMAS notícias de /thepathetic.php, no dialeto do /site/ (Bebas
// Neue, vermelho #E63946, coluna de 960px, o nav e o rodapé de sempre). Quem
// chega aqui não está logado: é o site aberto, e a notícia é o que convida.
//
// O grau manda igual: manchete abre a página, destaques dividem a linha, o
// resto entra na lista. O que muda pro app é só a roupa.
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/pathetic.php';
$pdo = db();

$idPedido = isset($_GET['n']) ? (int)$_GET['n'] : 0;
$materia  = $idPedido > 0 ? patheticUma($pdo, $idPedido) : null;

$noticias = patheticPublicadas($pdo, 40);
$capa     = patheticCapa($noticias);

// O HTML antigo continua no banco e vira o pé da página, como no app.
$pageContent = '';
try {
    $stmt = $pdo->prepare("SELECT content FROM site_pages WHERE page_key = 'thepathetic' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $pageContent = $row ? (string)($row['content'] ?? '') : '';
} catch (Exception $e) {}

$e = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$selo = function (string $g) {
    $r = PATHETIC_GRAU_INFO[$g]['rotulo'] ?? 'Notícia';
    return '<span class="np-selo np-' . $g . '">' . htmlspecialchars($r, ENT_QUOTES, 'UTF-8') . '</span>';
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title><?= $materia ? $e($materia['titulo']) . ' · The Pathetic' : 'The Pathetic · FBA' ?></title>
<meta name="description" content="<?= $e($materia ? patheticResumo($materia, 160) : 'As notícias da FBA, em primeira mão.') ?>">
<meta property="og:type" content="<?= $materia ? 'article' : 'website' ?>">
<meta property="og:title" content="<?= $e($materia ? $materia['titulo'] : 'The Pathetic') ?>">
<meta property="og:description" content="<?= $e($materia ? patheticResumo($materia, 160) : 'As notícias da FBA, em primeira mão.') ?>">
<?php $ogFoto = $materia ? patheticSrcFoto($materia['foto'] ?? '') : ''; if ($ogFoto !== ''): ?>
<meta property="og:image" content="<?= $e(preg_match('#^https?://#i', $ogFoto) ? $ogFoto : 'https://fbabrasil.com.br' . $ogFoto) ?>">
<?php endif; ?>
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
.wrap { max-width: var(--max); margin: 0 auto; padding-inline: var(--pad); }

.tp-topline { height: 4px; background: linear-gradient(90deg, var(--red), #ff6b35, var(--red)); background-size: 200%; animation: tpBar 3s linear infinite; }
@keyframes tpBar { 0% { background-position: 0% } 100% { background-position: 200% } }

.tp-hero { padding: 56px 0 32px; border-bottom: 1px solid var(--line); }
.tp-eyebrow {
  display: inline-flex; align-items: center; gap: 10px;
  padding: 6px 12px;
  border: 1px solid var(--red);
  color: var(--red);
  font-family: var(--font-mono);
  font-size: 11px;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  margin-bottom: 20px;
}
.tp-title {
  font-family: var(--font-display);
  font-weight: 400;
  text-transform: uppercase;
  line-height: 0.9;
  font-size: clamp(48px, 9vw, 96px);
  margin: 0;
}
.tp-sub { color: var(--ink-mute); font-size: 15px; margin-top: 14px; }

.tp-content { padding: 48px 0 80px; font-size: 17px; line-height: 1.7; }
.tp-content h1, .tp-content h2, .tp-content h3 { font-family: var(--font-display); text-transform: uppercase; line-height: 1; letter-spacing: 0.01em; }
.tp-content h2 { font-size: 36px; margin: 40px 0 16px; }
.tp-content h3 { font-size: 24px; margin: 28px 0 12px; }
.tp-content p { margin: 0 0 18px; color: var(--ink-mute); }
.tp-content a { color: var(--red); }
.tp-content img { border: 1px solid var(--line); margin: 20px 0; }

.tp-empty { text-align: center; padding: 100px 24px; color: var(--ink-dim); }
.tp-empty i { font-size: 48px; display: block; margin-bottom: 16px; }
.tp-empty p { font-size: 15px; }

/* ═══ AS NOTÍCIAS ═══════════════════════════════════════════════════
   Mesma hierarquia do jornal dentro do app: manchete larga, destaques
   dividindo a linha, o resto em lista. O que muda é o dialeto — aqui as
   fontes e a paleta são as do /site/, senão a página muda de cara no meio
   quando alguém vem do gamesfba. */
.np-selo { font-family: var(--font-mono); font-size: 10px; letter-spacing: .18em;
  text-transform: uppercase; padding: 3px 8px; white-space: nowrap; }
.np-manchete { background: var(--red); color: #fff; }
.np-destaque { background: rgba(230,57,70,.12); color: var(--red); box-shadow: inset 0 0 0 1px rgba(230,57,70,.4); }
.np-noticia  { background: var(--bg-3); color: var(--ink-dim); box-shadow: inset 0 0 0 1px var(--line-2); }
.np-chapeu { font-family: var(--font-mono); font-size: 10px; letter-spacing: .18em;
  text-transform: uppercase; color: var(--red); }
.np-meta { font-family: var(--font-mono); font-size: 10.5px; letter-spacing: .12em;
  text-transform: uppercase; color: var(--ink-dim); display: flex; align-items: center;
  gap: 8px; flex-wrap: wrap; }
.np-meta b { color: var(--ink-mute); font-weight: 500; }
.np-topo { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; margin-bottom: 10px; }

.np-manchete-card { display: block; padding-bottom: 34px; margin-bottom: 34px;
  border-bottom: 1px solid var(--line-2); }
.np-manchete-card .np-foto { aspect-ratio: 21/9; overflow: hidden; background: var(--bg-2);
  border: 1px solid var(--line); margin-bottom: 20px; }
.np-manchete-card .np-foto img { width: 100%; height: 100%; object-fit: cover;
  transition: transform .5s cubic-bezier(.2,.8,.2,1); }
.np-manchete-card:hover .np-foto img { transform: scale(1.02); }
.np-manchete-card h2 { font-family: var(--font-display); font-weight: 400;
  text-transform: uppercase; line-height: .95; letter-spacing: .01em;
  font-size: clamp(30px, 6vw, 60px); margin: 0 0 14px; color: var(--ink); }
.np-manchete-card:hover h2 { color: var(--red); }
.np-manchete-card p { color: var(--ink-mute); font-size: 17px; line-height: 1.6;
  margin: 0 0 14px; max-width: 60ch; }

.np-faixa { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 30px; padding-bottom: 34px; margin-bottom: 34px; border-bottom: 1px solid var(--line-2); }
.np-destaque-card { display: block; }
.np-destaque-card .np-foto { aspect-ratio: 16/9; overflow: hidden; background: var(--bg-2);
  border: 1px solid var(--line); margin-bottom: 14px; }
.np-destaque-card .np-foto img { width: 100%; height: 100%; object-fit: cover; transition: transform .4s; }
.np-destaque-card:hover .np-foto img { transform: scale(1.03); }
.np-destaque-card h3 { font-family: var(--font-display); font-weight: 400;
  text-transform: uppercase; line-height: 1; font-size: clamp(20px, 3vw, 28px);
  margin: 0 0 10px; color: var(--ink); }
.np-destaque-card:hover h3 { color: var(--red); }
.np-destaque-card p { color: var(--ink-mute); font-size: 14.5px; line-height: 1.55; margin: 0 0 10px; }

.np-secao { font-family: var(--font-mono); font-size: 11px; letter-spacing: .22em;
  text-transform: uppercase; color: var(--ink-dim); display: flex; align-items: center;
  gap: 14px; margin: 0 0 22px; }
.np-secao::after { content: ""; flex: 1; height: 1px; background: var(--line); }

.np-lista { display: flex; flex-direction: column; }
.np-item { display: flex; gap: 18px; align-items: flex-start; padding: 20px 0;
  border-top: 1px solid var(--line); }
.np-item:first-child { border-top: none; padding-top: 0; }
.np-item .np-foto { width: 148px; aspect-ratio: 3/2; flex: none; overflow: hidden;
  background: var(--bg-2); border: 1px solid var(--line); }
.np-item .np-foto img { width: 100%; height: 100%; object-fit: cover; }
.np-item-txt { flex: 1; min-width: 0; }
.np-item h4 { font-family: var(--font-display); font-weight: 400; text-transform: uppercase;
  line-height: 1.05; font-size: 21px; margin: 0 0 8px; color: var(--ink); }
.np-item:hover h4 { color: var(--red); }
.np-item p { color: var(--ink-mute); font-size: 14px; line-height: 1.55; margin: 0 0 9px; }

/* A matéria aberta. Coluna estreita e corpo grande: a landing pode ser larga,
   o texto não — 68 caracteres é o que o olho lê sem se perder na volta. */
.np-materia { max-width: 700px; }
.np-materia h1 { font-family: var(--font-display); font-weight: 400; text-transform: uppercase;
  line-height: .95; font-size: clamp(32px, 6.5vw, 62px); margin: 0 0 16px; }
.np-materia .np-linha-fina { font-size: clamp(16px, 2.2vw, 20px); line-height: 1.55;
  color: var(--ink-mute); margin: 0 0 20px; }
.np-materia .np-credito { padding: 14px 0; border-top: 1px solid var(--line);
  border-bottom: 1px solid var(--line); margin-bottom: 26px; }
.np-materia figure { margin: 0 0 8px; border: 1px solid var(--line); }
.np-materia figcaption { font-family: var(--font-mono); font-size: 10.5px; letter-spacing: .12em;
  text-transform: uppercase; color: var(--ink-dim); margin: 8px 0 26px; }
.np-corpo { font-size: 17.5px; line-height: 1.75; color: var(--ink-mute); }
.np-corpo p { margin: 0 0 1.05em; }
.np-corpo strong { color: var(--ink); font-weight: 600; }
.np-voltar { display: inline-flex; align-items: center; gap: 9px; margin-top: 38px;
  padding-top: 20px; border-top: 1px solid var(--line); width: 100%;
  font-family: var(--font-mono); font-size: 11px; letter-spacing: .18em;
  text-transform: uppercase; color: var(--ink-dim); }
.np-voltar:hover { color: var(--red); }

.np-arquivo { margin-top: 60px; padding-top: 30px; border-top: 1px solid var(--line-2); }

@media (max-width: 600px) {
  .np-item { gap: 13px; padding: 16px 0; }
  .np-item .np-foto { width: 104px; }
  .np-item h4 { font-size: 17px; }
  .np-item p { display: none; }
  .np-faixa { gap: 26px; }
}

/* Matérias */
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

/* O conteudo vindo do banco (site_pages) traz seu proprio header sticky
   (header.site), feito pra ficar sozinho no topo. Aqui ele entra abaixo
   do menu padrao (.fbanav-wrap, 68px), entao empurra o sticky pra nao
   ficar escondido atras do menu ao rolar a pagina. */
.tp-content header.site { top: 68px; z-index: 40; }
</style>
</head>
<body>
<div class="tp-topline"></div>
<?php include __DIR__ . '/_partials/nav.php'; ?>

<?php if (!$materia): ?>
<header class="tp-hero">
  <div class="wrap">
    <span class="tp-eyebrow"><i class="bi bi-newspaper"></i> Jornal da liga</span>
    <h1 class="tp-title">The<br />Pathetic.</h1>
    <p class="tp-sub">Cobertura, bastidores e análises de cada rodada, temporada e transferência da FBA.</p>
  </div>
</header>
<?php endif; ?>

<div class="wrap tp-content">

<?php if ($materia): /* ── UMA MATÉRIA ── */ ?>

  <article class="np-materia">
    <div class="np-topo">
      <?= $selo($materia['grau']) ?>
      <?php if (trim((string)$materia['chapeu']) !== ''): ?>
        <span class="np-chapeu"><?= $e($materia['chapeu']) ?></span>
      <?php endif; ?>
    </div>

    <h1><?= $e($materia['titulo']) ?></h1>

    <?php $r = trim((string)$materia['resumo']); if ($r !== ''): ?>
      <p class="np-linha-fina"><?= $e($r) ?></p>
    <?php endif; ?>

    <div class="np-meta np-credito">
      <b>Por <?= $e($materia['autor_nome']) ?></b>
      <span>·</span>
      <span><?= $e(patheticQuando($materia['publicada_em'] ?: $materia['criada_em'])) ?></span>
      <?php if (trim((string)$materia['texto']) !== ''): ?>
        <span>·</span><span><?= patheticMinutos($materia['texto']) ?> min</span>
      <?php endif; ?>
    </div>

    <?php $f = patheticSrcFoto($materia['foto']); if ($f !== ''): ?>
      <figure><img src="<?= $e($f) ?>" alt="<?= $e($materia['titulo']) ?>"></figure>
      <figcaption><?= $e(trim((string)$materia['foto_credito'])) ?></figcaption>
    <?php endif; ?>

    <div class="np-corpo"><?= patheticTextoHtml($materia['texto']) ?></div>

    <a class="np-voltar" href="/site/pathetic.php">← Todas as notícias</a>
  </article>

<?php elseif (!$noticias && trim($pageContent) === ''): ?>

  <div class="tp-empty">
      <i class="bi bi-newspaper"></i>
      <p>Nenhuma notícia publicada ainda.</p>
  </div>

<?php else: /* ── A CAPA ── */ ?>

  <?php if ($capa['manchete']): $m = $capa['manchete']; $f = patheticSrcFoto($m['foto']); ?>
    <a class="np-manchete-card" href="/site/pathetic.php?n=<?= (int)$m['id'] ?>">
      <?php if ($f !== ''): ?>
        <div class="np-foto"><img src="<?= $e($f) ?>" alt="<?= $e($m['titulo']) ?>" fetchpriority="high"></div>
      <?php endif; ?>
      <div class="np-topo">
        <?= $selo('manchete') ?>
        <?php if (trim((string)$m['chapeu']) !== ''): ?><span class="np-chapeu"><?= $e($m['chapeu']) ?></span><?php endif; ?>
      </div>
      <h2><?= $e($m['titulo']) ?></h2>
      <?php $r = patheticResumo($m, 220); if ($r !== ''): ?><p><?= $e($r) ?></p><?php endif; ?>
      <div class="np-meta"><b><?= $e($m['autor_nome']) ?></b><span>·</span><span><?= $e(patheticQuando($m['publicada_em'] ?: $m['criada_em'])) ?></span></div>
    </a>
  <?php endif; ?>

  <?php if ($capa['destaques']): ?>
    <div class="np-faixa">
      <?php foreach (array_slice($capa['destaques'], 0, 2) as $d): $f = patheticSrcFoto($d['foto']); ?>
        <a class="np-destaque-card" href="/site/pathetic.php?n=<?= (int)$d['id'] ?>">
          <?php if ($f !== ''): ?>
            <div class="np-foto"><img src="<?= $e($f) ?>" alt="<?= $e($d['titulo']) ?>" loading="lazy"></div>
          <?php endif; ?>
          <div class="np-topo">
            <?= $selo($d['grau']) ?>
            <?php if (trim((string)$d['chapeu']) !== ''): ?><span class="np-chapeu"><?= $e($d['chapeu']) ?></span><?php endif; ?>
          </div>
          <h3><?= $e($d['titulo']) ?></h3>
          <?php $r = patheticResumo($d, 140); if ($r !== ''): ?><p><?= $e($r) ?></p><?php endif; ?>
          <div class="np-meta"><b><?= $e($d['autor_nome']) ?></b><span>·</span><span><?= $e(patheticQuando($d['publicada_em'] ?: $d['criada_em'])) ?></span></div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php $lista = array_merge(array_slice($capa['destaques'], 2), $capa['noticias']); if ($lista): ?>
    <h2 class="np-secao">Mais notícias</h2>
    <div class="np-lista">
      <?php foreach ($lista as $n): $f = patheticSrcFoto($n['foto']); ?>
        <a class="np-item" href="/site/pathetic.php?n=<?= (int)$n['id'] ?>">
          <?php if ($f !== ''): ?>
            <div class="np-foto"><img src="<?= $e($f) ?>" alt="<?= $e($n['titulo']) ?>" loading="lazy"></div>
          <?php endif; ?>
          <div class="np-item-txt">
            <div class="np-topo" style="margin-bottom:8px">
              <?= $selo($n['grau']) ?>
              <?php if (trim((string)$n['chapeu']) !== ''): ?><span class="np-chapeu"><?= $e($n['chapeu']) ?></span><?php endif; ?>
            </div>
            <h4><?= $e($n['titulo']) ?></h4>
            <?php $r = patheticResumo($n, 130); if ($r !== ''): ?><p><?= $e($r) ?></p><?php endif; ?>
            <div class="np-meta"><b><?= $e($n['autor_nome']) ?></b><span>·</span><span><?= $e(patheticQuando($n['publicada_em'] ?: $n['criada_em'])) ?></span></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (trim($pageContent) !== ''): ?>
    <section class="np-arquivo">
      <h2 class="np-secao">Do arquivo</h2>
      <?= $pageContent ?>
    </section>
  <?php endif; ?>

<?php endif; ?>
</div>

<footer class="foot">
  <div class="wrap foot-bottom">
    <span>© 2026 FBA · Federação Brasileira de Arena</span>
    <span>Não afiliada à NBA ou Take-Two Interactive</span>
  </div>
</footer>
</body>
</html>
