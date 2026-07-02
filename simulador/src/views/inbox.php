<?php
require_once dirname(__DIR__) . '/helpers.php';
render_header('Mensagens');

$msgs   = League::inboxList(100);
$unread = League::inboxUnread();

// rótulo de tempo relativo simples a partir de created_at
function inbox_when(?string $iso): string {
    if (!$iso) return '';
    $ts = strtotime($iso);
    if (!$ts) return '';
    $diff = time() - $ts;
    if ($diff < 3600) return floor($diff/60) . ' min';
    if ($diff < 86400) return floor($diff/3600) . 'h';
    return floor($diff/86400) . 'd';
}
?>
<div class="card-head page">
  <h1 class="page-title">📬 Caixa de Entrada</h1>
  <?php if ($unread > 0): ?>
    <a class="btn btn-sm" href="<?= url('home', ['action'=>'inbox-read']) ?>">✓ Marcar todas como lidas (<?= $unread ?>)</a>
  <?php endif; ?>
</div>
<p class="legend">Tudo que acontece na liga e na sua franquia chega aqui — decisões da diretoria, pedidos de
   agentes, trocas, contratações e notícias. <?= $unread > 0 ? "<strong>$unread não lida(s).</strong>" : 'Tudo em dia.' ?></p>

<?php if (!$msgs): ?>
  <section class="card"><p class="muted">Nenhuma mensagem ainda. Avance os dias para movimentar a liga.</p></section>
<?php else: ?>
  <?php foreach ($msgs as $m): ?>
    <div class="inbox-page-msg <?= $m['is_read'] ? 'read' : 'unread' ?> <?= $m['urgent'] ? 'urgent' : '' ?> kind-<?= e($m['kind']) ?>">
      <div class="ipm-icon"><?= e($m['icon'] ?: '📬') ?></div>
      <div class="ipm-body">
        <div class="ipm-top">
          <span class="ipm-sender"><?= e($m['sender']) ?> · Temporada <?= (int)$m['season'] ?></span>
          <span class="ipm-when"><?= inbox_when($m['created_at']) ?></span>
        </div>
        <div class="ipm-title"><?= e($m['title']) ?></div>
        <?php if (!empty($m['body'])): ?><div class="ipm-text"><?= e($m['body']) ?></div><?php endif; ?>
        <?php render_inbox_actions($m, 'inbox'); ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php render_footer(); ?>
