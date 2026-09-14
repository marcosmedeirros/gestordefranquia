<?php
require_once dirname(__DIR__) . '/helpers.php';

$cats = [
    'pts' => ['Pontos', 'bullseye'],
    'reb' => ['Rebotes', 'arrow-down-up'],
    'ast' => ['Assistências', 'share-fill'],
    'stl' => ['Roubos', 'hand-index-thumb-fill'],
    'blk' => ['Tocos', 'shield-fill'],
];
$cat    = array_key_exists((string) ($_GET['cat'] ?? ''), $cats) ? (string) $_GET['cat'] : 'pts';
$gmId   = (int) League::gmTeam();
$gmAbbr = $gmId ? (string) (League::team($gmId)['abbr'] ?? '') : '';
$rows   = League::leaders($cat, 15);
$tops   = [];
foreach ($cats as $k => $_) $tops[$k] = $k === $cat ? ($rows[0] ?? null) : (League::leaders($k, 1)[0] ?? null);

// A consulta de líderes não traz OVR nem idade: busca a ficha de cada um
$fichas = [];
$ficha = function (array $l) use (&$fichas): array {
    $id = (int) $l['id'];
    if (!isset($fichas[$id])) $fichas[$id] = League::player($id) ?: [];
    return $fichas[$id];
};
$num = fn($v): string => number_format((float) $v, 1);
$isMine = fn(array $l): bool => $gmAbbr !== '' && (string) $l['abbr'] === $gmAbbr;
[$label, $icon] = $cats[$cat];

render_header('Líderes');
page_head('Líderes', [
    'eyebrow' => 'Liga · Temporada ' . League::season(),
    'sub' => 'Quem mais produz por jogo na temporada. Toque numa categoria para ver o ranking dela.',
]);
?>
<div class="stack">
  <nav class="ld-cats" aria-label="Categorias">
    <?php foreach ($cats as $k => [$lbl, $ic]): $tp = $tops[$k]; ?>
      <a class="ld-cat<?= $k === $cat ? ' on' : '' ?>" href="<?= url('leaders', ['cat' => $k]) ?>"<?= $k === $cat ? ' aria-current="page"' : '' ?>>
        <span class="ld-cat-h"><?= bi($ic) ?><?= e($lbl) ?></span>
        <?php if ($tp): ?>
          <span class="ld-cat-v"><?= $num($tp['avg']) ?></span>
          <span class="ld-cat-p">
            <?= player_photo((int) ($tp['nba_id'] ?? 0), (string) $tp['name'], (string) ($tp['primary_color'] ?? '#1a1a2e'), 'sm', '', (int) $tp['id'], (string) $tp['pos']) ?>
            <span><b><?= e($tp['name']) ?></b><small><?= e($tp['abbr']) ?> · <?= e($tp['pos']) ?></small></span>
          </span>
        <?php else: ?>
          <span class="ld-cat-v dim">—</span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <section class="panel">
    <?= panel_head($label . ' por jogo', ['icon' => $icon, 'meta' => $rows ? 'Os ' . count($rows) . ' melhores da temporada' : '']) ?>
    <?php if (!$rows): ?>
      <?= empty_state('Ainda sem estatísticas.', 'Os líderes aparecem depois dos primeiros jogos da temporada.', 'bar-chart') ?>
    <?php else:
      $lead = $rows[0];
      $lf = $ficha($lead); ?>
      <a class="ld-lead<?= $isMine($lead) ? ' mine' : '' ?>" href="<?= url('player', ['id' => $lead['id']]) ?>">
        <span class="ld-rank1">1</span>
        <?= player_photo((int) ($lead['nba_id'] ?? 0), (string) $lead['name'], (string) ($lead['primary_color'] ?? '#1a1a2e'), 'md', '', (int) $lead['id'], (string) $lead['pos']) ?>
        <span class="ld-lead-txt">
          <span class="eyebrow">Líder em <?= e(mb_strtolower($label)) ?></span>
          <b><?= e($lead['name']) ?></b>
          <span class="ld-lead-meta">
            <?= isset($lf['ovr']) ? ovr_badge($lf['ovr'], 'sm') : '' ?>
            <?= team_logo((string) $lead['abbr'], (string) ($lead['primary_color'] ?? '#333'), 'sm') ?>
            <span><?= e($lead['abbr']) ?> · <?= e($lead['pos']) ?><?= isset($lf['age']) ? ' · ' . (int) $lf['age'] . ' anos' : '' ?></span>
          </span>
        </span>
        <span class="ld-lead-val"><b><?= $num($lead['avg']) ?></b><small><?= (int) $lead['gp'] ?> jogos</small></span>
      </a>

      <?php if (count($rows) > 1): ?>
      <div class="table-wrap">
        <table class="tbl ld-tbl">
          <thead><tr><th class="c">#</th><th>Jogador</th><th class="c">OVR</th><th class="num hide-sm">Jogos</th><th class="num">Média</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($rows, 1) as $i => $l): $f = $ficha($l); ?>
            <tr<?= $isMine($l) ? ' class="mine"' : '' ?>>
              <td class="rank"><?= $i + 2 ?></td>
              <td><?= player_who($l, $l['pos'] . ' · ' . $l['abbr'], (string) ($l['primary_color'] ?? '#1a1a2e')) ?></td>
              <td class="c"><?= isset($f['ovr']) ? ovr_badge($f['ovr'], 'sm') : '' ?></td>
              <td class="num hide-sm"><?= (int) $l['gp'] ?></td>
              <td class="num"><span class="ld-val"><?= $num($l['avg']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>
<?php render_footer(); ?>
