<?php
require_once dirname(__DIR__) . '/helpers.php';

$pd    = League::preseasonDay();
$tot   = League::PRESEASON_DAYS;
$msgs  = League::inboxList(30);
$isPre = League::phase() === 'preseason';
$left  = max(0, $tot - $pd);

render_header('Pré-temporada');
page_head('Pré-temporada', [
    'eyebrow' => 'Temporada ' . League::season(),
    'sub' => 'A janela antes da estreia. Ajuste o elenco com trocas e contratações enquanto a liga também se mexe a cada dia.',
]);

if (!$isPre) {
    echo note('A pré-temporada já terminou. <a href="' . url('home') . '">Voltar à central</a>', 'info');
    render_footer();
    exit;
}
?>
<div class="stack">
  <section class="panel hot">
    <div class="spread">
      <div>
        <span class="label">Contagem para a estreia</span>
        <span class="big-num">Dia <?= $pd ?><small> de <?= $tot ?></small></span>
      </div>
      <?= chip($left === 0 ? 'A temporada começa no próximo avanço' : ($left === 1 ? 'Falta 1 dia' : "Faltam $left dias"), 'go', 'hourglass-split') ?>
    </div>
    <div style="margin-top:14px"><?= meter($pd / $tot * 100, 'go') ?></div>
  </section>

  <div class="qlinks">
    <a class="qlink" href="<?= url('trades') ?>"><?= bi('arrow-left-right') ?><span><b>Trocas</b><small>Negocie com os outros times</small></span></a>
    <a class="qlink" href="<?= url('freeagency') ?>"><?= bi('person-plus-fill') ?><span><b>Agentes livres</b><small>Contrate quem cabe no teto</small></span></a>
    <a class="qlink" href="<?= url('lineup') ?>"><?= bi('people-fill') ?><span><b>Escalação</b><small>Titulares e minutos</small></span></a>
    <a class="qlink" href="<?= url('cap') ?>"><?= bi('cash-coin') ?><span><b>Folha e teto</b><small>Contratos e espaço salarial</small></span></a>
  </div>

  <section class="panel">
    <?= panel_head('Acontecimentos da liga', ['icon' => 'broadcast', 'more' => ['Caixa de entrada', url('inbox')]]) ?>
    <?php if ($msgs): ?>
      <div class="feed">
        <?php foreach (array_slice($msgs, 0, 12) as $m) render_inbox_msg($m, 'preseason', ['unread' => empty($m['is_read'])]); ?>
      </div>
    <?php else: ?>
      <?= empty_state('A liga ainda está quieta.', 'Trocas, contratações e rumores aparecem aqui conforme os dias passam.', 'broadcast') ?>
    <?php endif; ?>
  </section>
</div>
<?php render_footer(); ?>
