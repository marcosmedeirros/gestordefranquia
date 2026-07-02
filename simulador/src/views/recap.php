<?php
require_once dirname(__DIR__) . '/helpers.php';
render_header('Avançar');

$since = (int) ($_GET['since'] ?? 0);
$label = $_GET['label'] ?? '';
$backUrl = $_GET['back'] ?? url('home');
// extrai a chave de página (ex.: "home") da URL completa em $backUrl, para
// passar aos botões de ação das mensagens (decisão/renovação).
preg_match('/[?&]p=([a-z]+)/', $backUrl, $bm);
$backPage = $bm[1] ?? 'home';

$events = $since > 0 ? League::inboxSince($since) : [];
$cta = League::nextAction();
?>
<div class="recap-head">
  <div class="recap-date"><?= e($label) ?></div>
  <?php if ($events): ?>
    <div class="recap-count"><?= count($events) ?> acontecimento<?= count($events) === 1 ? '' : 's' ?></div>
  <?php else: ?>
    <div class="recap-count muted">Sem novidades por aqui.</div>
  <?php endif; ?>
</div>

<?php if ($events): ?>
<section class="card">
  <div class="inbox-list">
    <?php foreach ($events as $m): render_inbox_msg($m, $backPage); endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($cta): ?>
<section class="cta-card recap-cta">
  <div class="cta-info"><span class="cta-note"><?= e($cta['note']) ?></span></div>
  <a class="btn btn-primary btn-lg cta-btn" href="<?= e($cta['href']) ?>"
     <?= isset($cta['confirm']) ? 'onclick="return confirm(\''.e($cta['confirm']).'\')"' : '' ?>><?= e($cta['label']) ?></a>
  <?php if (!empty($cta['alt'])): ?>
    <a class="cta-alt" href="<?= e($cta['alt']['href']) ?>" onclick="return confirm('<?= e($cta['alt']['confirm']) ?>')"><?= e($cta['alt']['label']) ?></a>
  <?php endif; ?>
</section>
<?php endif; ?>

<div class="recap-actions">
  <a class="im-link" href="<?= e($backUrl) ?>">← Ver painel sem avançar</a>
</div>
<?php render_footer(); ?>
