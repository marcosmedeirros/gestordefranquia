<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/PlayerFace.php';

$id = (int) ($_GET['id'] ?? 0);
$p  = $id > 0 ? League::player($id) : null;
if (!$p) {
    render_header('Jogador');
    page_head('Jogador', ['eyebrow' => 'Liga']);
    echo empty_state('Jogador não encontrado.', 'Ele pode ter saído da liga, ou o link está errado.', 'person-x');
    render_footer();
    exit;
}

$gmId     = (int) League::gmTeam();
$teamId   = (int) ($p['team_id'] ?? 0);
$mine     = $gmId > 0 && $teamId === $gmId;
$color    = (string) ($p['primary_color'] ?? '#333');
$ovr      = (int) $p['ovr'];
$gp       = (int) ($p['gp'] ?? 0);
$inj      = (int) ($p['injury_games'] ?? 0);
$rest     = (int) ($p['rest_games'] ?? 0);
$morale   = (int) ($p['morale'] ?? 75);
$seasPro  = (int) ($p['seasons_pro'] ?? 0);
$years    = (int) ($p['contract_years'] ?? 0);
$minT     = max(0, min(48, (int) ($p['min_target'] ?? 0)));
$flex     = Cap::flexOf($p);
$potG     = potGrade($p);
$focus    = !empty($p['dev_focus']);
$onBlock  = $mine && in_array($id, League::tradeBlock(), true);
$log      = League::playerGameLog($id, 20);
$career   = League::playerCareer($id);
$awards   = League::playerAwardsWon($id);
$season   = League::season();
$teamName = teamFull(['abbr' => (string) $p['abbr'], 'city' => (string) $p['city'], 'name' => (string) $p['team_name']]);
$ht       = (int) ($p['ht'] ?? 0) > 0 ? number_format((int) $p['ht'] / 100, 2, ',', '') . ' m' : '';
$games    = fn(int $n): string => $n . ($n === 1 ? ' jogo' : ' jogos');

// League::player() junta season_stats com s.*, e o total de rebotes da temporada
// (season_stats.reb) cobre a nota de rebote (players.reb). As notas vêm do
// elenco, que dá apelido às colunas da temporada e não mistura as duas.
$rt = $p;
foreach (League::roster($teamId) as $r) {
    if ((int) $r['id'] === $id) { $rt = $r; break; }
}
$attrs = [
    'Interior'        => (int) $rt['ins'],
    'Média distância' => (int) $rt['mid'],
    'Bola de 3'       => (int) $rt['thr'],
    'Armação'         => (int) $rt['pmk'],
    'Rebote'          => (int) $rt['reb'],
    'Defesa'          => (int) $rt['def'],
    'Atletismo'       => (int) $rt['ath'],
    'Fôlego'          => (int) $rt['sta'],
];

$awardInfo = [
    'MVP'        => ['award-fill',       'MVP da temporada'],
    'Finals MVP' => ['trophy-fill',      'MVP das Finais'],
    'DPOY'       => ['shield-fill',      'Defensor do ano'],
    'ROY'        => ['stars',            'Novato do ano'],
    'MIP'        => ['graph-up-arrow',   'Quem mais evoluiu'],
    '6º Homem'   => ['person-plus-fill', '6º homem do ano'],
    'All-NBA 1'  => ['star-fill',        'All-NBA · 1º time'],
    'All-NBA 2'  => ['star-fill',        'All-NBA · 2º time'],
    'All-NBA 3'  => ['star-fill',        'All-NBA · 3º time'],
];

$salSub = Cap::salarySource($p) === 'rookie' ? 'contrato de calouro' : 'tabela do OVR ' . $ovr;
if ((int) ($p['award_bonus'] ?? 0) > 0) $salSub .= ' + bônus de ' . (int) $p['award_bonus'] . 'M';

render_header((string) $p['name']);
?>
<div class="stack">
  <section class="pl-hero<?= $mine ? ' mine' : '' ?>">
    <div class="pl-card">
      <div class="pl-card-top"><?= ovr_badge($ovr, 'lg') ?><span class="pos-tag"><?= e((string) $p['pos']) ?></span></div>
      <?= player_photo((int) ($p['nba_id'] ?? 0), (string) $p['name'], $color, 'hero', 'pl-photo', $id, (string) $p['pos']) ?>
      <div class="pl-card-foot"><?= team_logo((string) $p['abbr'], $color, 'sm') ?><span><?= e((string) $p['abbr']) ?></span></div>
    </div>

    <div class="pl-side">
      <div class="pl-id">
        <a class="eyebrow" href="<?= url('team', ['id' => $teamId]) ?>"><?= e($teamName) ?></a>
        <h1><?= e((string) $p['name']) ?></h1>
        <p class="pl-meta"><?= e((string) $p['pos']) ?> · <?= (int) $p['age'] ?> anos<?= $ht !== '' ? ' · ' . $ht : '' ?> · <?= $seasPro === 0 ? 'calouro' : $seasPro . ($seasPro === 1 ? ' temporada' : ' temporadas') . ' na liga' ?></p>
      </div>

      <div class="pl-more">
        <div class="row pl-chips">
          <?php if ($mine && !$inj): ?>
            <?= chip(((int) ($p['is_starter'] ?? 0) === 1 ? 'Titular' : ($minT > 0 ? 'Reserva' : 'Fora da rotação')) . ($minT > 0 ? ' · ' . $minT . ' min' : ''), 'team', 'person-check-fill') ?>
          <?php endif; ?>
          <?php if ($seasPro === 0): ?><?= chip('Calouro', 'info', 'stars') ?><?php endif; ?>
          <?php if ($inj): ?><?= chip((trim((string) ($p['injury_desc'] ?? '')) ?: 'Lesionado') . ' · fora ' . $games($inj), 'bad', 'bandaid-fill') ?><?php endif; ?>
          <?php if ($rest && !$inj): ?><?= chip('Descanso · ' . $games($rest), 'info', 'moon-stars-fill') ?><?php endif; ?>
          <?php if ($potG !== ''): ?><?= chip('Potencial ' . $potG, 'info', 'graph-up-arrow') ?><?php endif; ?>
          <?php if ($mine): ?>
            <?= chip('Moral ' . $morale, $morale >= 80 ? 'ok' : ($morale < 55 ? 'bad' : ''), $morale < 55 ? 'emoji-frown' : 'emoji-smile') ?>
          <?php elseif ($morale >= 80): ?>
            <?= chip('Moral alta', 'ok', 'emoji-smile') ?>
          <?php elseif ($morale < 55): ?>
            <?= chip('Insatisfeito', 'bad', 'emoji-frown') ?>
          <?php endif; ?>
          <?php if ($mine && $focus): ?><?= chip('Foco de treino', 'info', 'bullseye') ?><?php endif; ?>
          <?php if ($onBlock): ?><?= chip('Na vitrine', 'warn', 'megaphone-fill') ?><?php endif; ?>
          <?php if (!empty($p['retired'])): ?><?= chip('Aposentado', '', 'door-closed') ?><?php endif; ?>
        </div>

        <?php if ((int) ($p['salary'] ?? 0) > 0): ?>
        <div class="stats pl-contract">
          <?= stat_tile('Salário por ano', money($p['salary']), $salSub) ?>
          <?= stat_tile('Contrato', $years > 0 ? $years . ($years === 1 ? ' ano' : ' anos') : 'Expirando', $years > 0 ? 'de vínculo' : 'fim do vínculo') ?>
          <?php if ($flex > 0): ?><?= stat_tile('Cap Flex', '+' . $flex . 'M', 'draftado aqui: soma no teto') ?><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($mine): ?>
        <div class="row pl-actions">
          <a class="btn" href="<?= url('home', ['action' => 'boost-morale', 'pid' => $id]) ?>"><?= bi('chat-heart-fill') ?>Conversar</a>
          <?php if (!$inj): ?>
            <a class="btn" href="<?= url('home', ['action' => 'rest-player', 'pid' => $id]) ?>"><?= bi('moon-stars-fill') ?>Dar descanso</a>
          <?php endif; ?>
          <?php if ((int) $p['age'] <= 25 || $focus): ?>
            <a class="btn" href="<?= url('home', ['action' => 'dev-focus', 'pid' => $id]) ?>"><?= bi('bullseye') ?><?= $focus ? 'Tirar do foco' : 'Foco de treino' ?></a>
          <?php endif; ?>
          <a class="btn" href="<?= url('home', ['action' => 'trade-block', 'pid' => $id]) ?>"><?= bi('megaphone-fill') ?><?= $onBlock ? 'Tirar da vitrine' : 'Pôr na vitrine' ?></a>
          <a class="btn btn-danger" href="<?= url('home', ['action' => 'release', 'pid' => $id, 'back' => 'lineup']) ?>"<?= link_attrs(['confirm' => [
              'text' => 'Dispensar ' . $p['name'] . '? Ele vira agente livre e o salário sai da folha na hora.',
              'title' => 'Dispensar jogador', 'ok' => 'Dispensar', 'danger' => true]]) ?>><?= bi('box-arrow-right') ?>Dispensar</a>
        </div>
        <?php elseif ($gmId && $teamId && Cap::tradesOpen()): ?>
        <div class="row pl-actions">
          <a class="btn btn-team" href="<?= url('trades', ['ai' => $teamId]) ?>"><?= bi('arrow-left-right') ?>Negociar com o <?= e((string) $p['abbr']) ?></a>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <div class="grid cols-2">
    <section class="panel">
      <?= panel_head('Temporada ' . $season, ['icon' => 'bar-chart-fill', 'meta' => $gp ? $games($gp) : '']) ?>
      <?php if ($gp): ?>
      <div class="stats">
        <?= stat_tile('Pontos', avg($p['pts'] ?? 0, $gp)) ?>
        <?= stat_tile('Rebotes', avg($p['reb'] ?? 0, $gp)) ?>
        <?= stat_tile('Assistências', avg($p['ast'] ?? 0, $gp)) ?>
        <?= stat_tile('Minutos', avg($p['min'] ?? 0, $gp)) ?>
        <?= stat_tile('Roubos', avg($p['stl'] ?? 0, $gp)) ?>
        <?= stat_tile('Tocos', avg($p['blk'] ?? 0, $gp)) ?>
        <?= stat_tile('Arremessos', pct($p['fgm'] ?? 0, $p['fga'] ?? 0), (int) ($p['fgm'] ?? 0) . ' de ' . (int) ($p['fga'] ?? 0)) ?>
        <?= stat_tile('Bolas de 3', pct($p['tpm'] ?? 0, $p['tpa'] ?? 0), (int) ($p['tpm'] ?? 0) . ' de ' . (int) ($p['tpa'] ?? 0)) ?>
      </div>
      <?php else: ?>
        <?= empty_state('Ainda não jogou nesta temporada.', $inj ? 'Está no departamento médico.' : 'Os números aparecem depois do primeiro jogo.', 'hourglass-split') ?>
      <?php endif; ?>
    </section>

    <section class="panel">
      <?= panel_head('Atributos', ['icon' => 'sliders', 'right' => ovr_badge($ovr, 'sm')]) ?>
      <div class="pl-attrs">
        <?php foreach ($attrs as $label => $v): ?>
        <div class="pl-attr">
          <span class="pl-attr-lbl"><?= e($label) ?></span>
          <?= meter($v) ?>
          <span class="grade <?= gradeClass($v) ?>"><?= grade($v) ?></span>
          <b class="pl-attr-val"><?= $v ?></b>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($potG !== ''): ?>
        <p class="muted pl-pot">Potencial <b class="strong"><?= e($potG) ?></b> é a estimativa dos olheiros. O teto real só aparece com o desenvolvimento.</p>
      <?php endif; ?>
    </section>
  </div>

  <?php if ($career): ?>
  <section class="panel pad-0">
    <?= panel_head('Carreira', ['icon' => 'clock-history', 'meta' => count($career) . (count($career) === 1 ? ' temporada' : ' temporadas')]) ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Temp.</th><th>Time</th><th class="num hide-sm">Idade</th><th class="c">OVR</th><th class="num">J</th>
            <th class="num">PTS</th><th class="num">REB</th><th class="num">AST</th>
            <th class="num hide-sm">ROU</th><th class="num hide-sm">TOC</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach (array_reverse($career) as $s): $sgp = max(1, (int) $s['gp']); ?>
          <tr>
            <td class="pl-season">T<?= (int) $s['season'] ?></td>
            <td><?php if (!empty($s['team_id'])): ?><a class="link" href="<?= url('team', ['id' => (int) $s['team_id']]) ?>"><?= e((string) ($s['abbr'] ?? '—')) ?></a><?php else: ?><span class="dim">—</span><?php endif; ?></td>
            <td class="num hide-sm"><?= (int) $s['age'] ?></td>
            <td class="c"><?= ovr_badge($s['ovr'], 'sm') ?></td>
            <td class="num"><?= (int) $s['gp'] ?></td>
            <td class="num"><?= number_format((int) $s['pts'] / $sgp, 1) ?></td>
            <td class="num"><?= number_format((int) $s['reb'] / $sgp, 1) ?></td>
            <td class="num"><?= number_format((int) $s['ast'] / $sgp, 1) ?></td>
            <td class="num hide-sm"><?= number_format((int) $s['stl'] / $sgp, 1) ?></td>
            <td class="num hide-sm"><?= number_format((int) $s['blk'] / $sgp, 1) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($awards): ?>
  <section class="panel">
    <?= panel_head('Prêmios', ['icon' => 'trophy-fill', 'meta' => count($awards) . (count($awards) === 1 ? ' conquista' : ' conquistas')]) ?>
    <div class="pl-awards">
      <?php foreach (array_reverse($awards) as $aw): [$aIcon, $aLabel] = $awardInfo[$aw['type']] ?? ['award-fill', (string) $aw['type']]; ?>
      <div class="pl-award">
        <?= bi($aIcon) ?>
        <div>
          <b><?= e($aLabel) ?></b>
          <small>Temporada <?= (int) $aw['season'] ?><?= !empty($aw['abbr']) ? ' · ' . e((string) $aw['abbr']) : '' ?><?= !empty($aw['value']) ? ' · ' . e((string) $aw['value']) : '' ?></small>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="panel pad-0">
    <?= panel_head('Últimos jogos', ['icon' => 'list-ol', 'meta' => $log ? 'últimos ' . count($log) : '']) ?>
    <?php if (!$log): ?>
      <div class="pl-log-empty"><?= empty_state('Nenhum jogo ainda.', 'As atuações aparecem aqui depois de cada partida.', 'calendar-x') ?></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="tbl compact">
        <thead>
          <tr>
            <th>Data</th><th>Jogo</th><th class="num">MIN</th><th class="num">PTS</th><th class="num">REB</th><th class="num">AST</th>
            <th class="num hide-sm">ROU</th><th class="num hide-sm">TOC</th><th class="num hide-sm">FG</th><th class="num hide-sm">3P</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($log as $b):
          $bt   = (int) ($b['team_id'] ?? 0) ?: $teamId;
          $home = (int) $b['home_id'] === $bt;
          $my   = (int) ($home ? $b['home_pts'] : $b['away_pts']);
          $op   = (int) ($home ? $b['away_pts'] : $b['home_pts']);
          $won  = $my > $op;
          $date = preg_replace('/^[^,]+,\s*/u', '', League::dateLabel((int) $b['day'])); ?>
          <tr>
            <td class="dim pl-date"><?= e((string) $date) ?></td>
            <td>
              <a class="who" href="<?= url('game', ['id' => (int) $b['game_id']]) ?>"><span class="w-txt">
                <b><?= $home ? 'vs' : '@' ?> <?= e((string) ($home ? $b['away_abbr'] : $b['home_abbr'])) ?></b>
                <small><span class="<?= $won ? 'pos' : 'neg' ?>"><?= $won ? 'V' : 'D' ?></span> <?= $my ?>-<?= $op ?></small>
              </span></a>
            </td>
            <td class="num"><?= (int) round((float) $b['min']) ?></td>
            <td class="num"><b><?= (int) $b['pts'] ?></b></td>
            <td class="num"><?= (int) $b['reb'] ?></td>
            <td class="num"><?= (int) $b['ast'] ?></td>
            <td class="num hide-sm"><?= (int) ($b['stl'] ?? 0) ?></td>
            <td class="num hide-sm"><?= (int) ($b['blk'] ?? 0) ?></td>
            <td class="num hide-sm"><?= (int) $b['fgm'] ?>-<?= (int) $b['fga'] ?></td>
            <td class="num hide-sm"><?= (int) $b['tpm'] ?>-<?= (int) $b['tpa'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>
</div>
<?php render_footer(); ?>
