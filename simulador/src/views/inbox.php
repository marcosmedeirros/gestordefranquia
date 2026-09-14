<?php
require_once dirname(__DIR__) . '/helpers.php';

$msgs      = League::inboxList(100);
$unread    = League::inboxUnread();
$decisions = League::pendingDecisions();
$filtro    = in_array($_GET['f'] ?? '', ['novas', 'importantes'], true) ? $_GET['f'] : 'todas';

/** Há quanto tempo a mensagem chegou (tempo real, não o calendário do jogo). */
function inbox_when(?string $iso): string
{
    if (!$iso) return '';
    $ts = strtotime($iso);
    if (!$ts) return '';
    $diff = time() - $ts;
    if ($diff < 60) return 'agora';
    if ($diff < 3600) return floor($diff / 60) . ' min';
    if ($diff < 86400) return floor($diff / 3600) . ' h';
    return floor($diff / 86400) . ' d';
}

render_header('Caixa de entrada');
page_head('Caixa de entrada', [
    'eyebrow' => 'Início',
    'sub' => $unread
        ? ($unread === 1 ? 'Uma mensagem nova.' : "$unread mensagens novas.") . ' Decisões e renovações se resolvem direto aqui.'
        : 'Tudo lido. Avance os dias para movimentar a liga.',
]);
?>
<div class="stack">
  <?php render_decisions($decisions, url('inbox')); ?>

  <nav class="seg" aria-label="Filtrar mensagens">
    <?php foreach (['todas' => 'Todas', 'novas' => 'Novas', 'importantes' => 'Importantes'] as $k => $label): ?>
      <a class="<?= $filtro === $k ? 'on' : '' ?>" href="<?= url('inbox', $k === 'todas' ? [] : ['f' => $k]) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <section class="panel">
    <?php
      $n = 0;
      echo '<div class="feed">';
      foreach ($msgs as $m) {
          if ($decisions && $m['kind'] === 'decision') continue; // já estão no painel de decisões
          if ($filtro === 'novas' && !empty($m['is_read'])) continue;
          if ($filtro === 'importantes' && empty($m['urgent'])) continue;
          render_inbox_msg($m, 'inbox', [
              'when' => 'Temporada ' . (int) $m['season'] . ' · ' . inbox_when($m['created_at'] ?? null),
              'unread' => empty($m['is_read']),
          ]);
          $n++;
      }
      echo '</div>';
      if (!$n) {
          echo empty_state($filtro === 'todas' ? 'Nenhuma mensagem ainda.' : 'Nada neste filtro.', 'Avance os dias para movimentar a liga.', 'inbox');
      }
    ?>
  </section>
</div>
<?php
// Abrir a caixa conta como leitura: o contador do menu zera na próxima tela.
if ($unread > 0) League::inboxMarkRead();
render_footer();
