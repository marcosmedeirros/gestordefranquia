<?php
require_once dirname(__DIR__) . '/helpers.php';
render_header('Pré-Temporada');

$day   = League::preseasonDay();
$total = League::PRESEASON_DAYS;
$pct   = (int) round($day / $total * 100);
$gmId  = League::gmTeam();
$msgs  = League::inboxList(40);
?>
<h1 class="page-title">🏀 Pré-Temporada</h1>
<p class="legend">Janela de <?= $total ?> dias antes do início da temporada. Faça trocas, contrate agentes livres e
   acompanhe os acontecimentos da liga na sua caixa de entrada. Avance os dias quando estiver pronto.</p>

<div class="ps-head">
  <div class="ps-day"><?= $day ?><small> / <?= $total ?> dias</small></div>
  <div class="ps-prog-wrap">
    <div class="ps-prog-label">Progresso da pré-temporada</div>
    <div class="ps-prog"><span style="width:<?= $pct ?>%"></span></div>
  </div>
  <div class="ps-actions">
    <a class="btn btn-primary" href="<?= url('home', ['action'=>'preseason-advance']) ?>">▶ Avançar dia</a>
    <a class="btn" href="<?= url('home', ['action'=>'preseason-finish']) ?>"
       onclick="return confirm('Encerrar a pré-temporada e iniciar a temporada agora?')">⏭ Iniciar temporada</a>
  </div>
</div>

<!-- Atalhos de gestão -->
<div class="ps-quick">
  <a href="<?= url('trades') ?>"><span class="pq-ic">🔄</span>Central de Trocas</a>
  <a href="<?= url('freeagency') ?>"><span class="pq-ic">✍️</span>Free Agency</a>
  <a href="<?= url('lineup') ?>"><span class="pq-ic">📋</span>Escalação</a>
  <a href="<?= url('cap') ?>"><span class="pq-ic">💰</span>Contratos</a>
</div>

<!-- Feed de acontecimentos (caixa de entrada) -->
<section class="card">
  <div class="card-head">
    <h2>📬 Acontecimentos da Liga</h2>
    <a class="link-more" href="<?= url('inbox') ?>">Ver caixa completa →</a>
  </div>
  <?php if (!$msgs): ?>
    <p class="muted" style="font-size:11px">Avance os dias para movimentar a liga — trocas, contratações e rumores aparecerão aqui.</p>
  <?php else: ?>
  <div class="inbox-list">
    <?php foreach (array_slice($msgs, 0, 12) as $m): ?>
      <div class="inbox-msg <?= $m['urgent'] ? 'urgent' : '' ?> kind-<?= e($m['kind']) ?>">
        <div class="im-icon"><?= e($m['icon'] ?: '📬') ?></div>
        <div class="im-body">
          <div class="im-from"><?= e($m['sender']) ?> · T<?= (int)$m['season'] ?></div>
          <div class="im-title"><?= e($m['title']) ?></div>
          <?php if (!empty($m['body'])): ?><div class="im-text"><?= e($m['body']) ?></div><?php endif; ?>
          <?php render_inbox_actions($m, 'preseason'); ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php render_footer(); ?>
