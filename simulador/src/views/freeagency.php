<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/Offseason.php';
render_header('Agentes livres');

$phase = League::phase();
$isWindow = $phase === 'freeagency';
if (!Cap::signingOpen()) {
    page_head('Agentes livres', ['eyebrow' => 'Mercado', 'sub' => 'Jogadores sem clube, prontos para assinar.']);
    echo '<section class="panel">' . empty_state('Contratações fechadas nesta fase', 'Elas voltam na pré-temporada, na free agency e na temporada regular.', 'lock-fill') . '</section>';
    render_footer();
    exit;
}

$gm = League::gmTeam();
// save antigo ou mercado esvaziado: a lista curta se completa ao abrir a página
if (in_array($phase, ['preseason', 'regular'], true)) { try { Offseason::marketRefresh(false, true); } catch (Throwable $e) {} }
$season = $isWindow ? (int) Database::meta('fa_season', League::season() + 1) : League::season();
$fas = Offseason::freeAgents(80);
$gmTeam = $gm ? League::team($gm) : null;
$cap = $gm ? Cap::summary($gm) : null;
$rosterCount = $cap ? $cap['count'] : 0;
$signed = League::transactions($season, 200);
$signed = array_values(array_filter($signed, fn($x) => in_array($x['type'], ['free agency', 'dispensa'], true)));
$space = $cap ? max(0, $cap['space']) : 0;

// O que cabe: salário pela tabela por OVR contra o espaço e as vagas do elenco
$full = $gm && $rosterCount >= Cap::ROSTER_MAX;
$rows = [];
$nFit = 0;
foreach ($fas as $p) {
    // o mesmo preço que a assinatura cobra (Offseason::signFreeAgent): tabela por OVR, rookie scale do calouro e bônus de prêmio
    $sal = Cap::playerSalaryM($p) * Cap::M;
    $fits = $gm && $rosterCount < Cap::ROSTER_MAX && $sal <= $space;
    if ($fits) $nFit++;
    $rows[] = ['p' => $p, 'sal' => $sal, 'fits' => $fits];
}
$maxOvr = 0; // maior OVR cujo salário cabe no espaço
for ($o = 99; $o >= 77; $o--) {
    if (Cap::ovrSalary($o) * Cap::M <= $space) { $maxOvr = $o; break; }
}
$filter = ($gm && ($_GET['f'] ?? '') === 'cabe') ? 'cabe' : 'todos';
$list = $filter === 'cabe' ? array_values(array_filter($rows, fn($r) => $r['fits'])) : $rows;

if ($cap) {
    [$stTxt, $stTone] = $cap['status'] === 'over' ? ['Acima do teto: nada cabe', 'bad']
        : ($full ? ['Elenco cheio', 'bad']
        : ($space < 2 * Cap::M ? ['Sem espaço no teto', 'bad']
        : ($space < 3 * Cap::M ? ['Só salário mínimo cabe', 'warn'] : ['Pode contratar', 'ok'])));
}

$actions = '';
if ($isWindow) $actions .= '<a class="btn btn-ghost" href="' . url('draft', ['season' => $season]) . '">' . bi('mortarboard-fill') . 'Resultado do draft</a>';
if ($gm) $actions .= '<a class="btn btn-ghost" href="' . url('cap') . '">' . bi('cash-coin') . 'Folha e teto</a>';

page_head('Agentes livres', [
    'eyebrow' => $isWindow ? 'Free agency · Temporada ' . $season : 'Mercado',
    'sub' => $isWindow
        ? 'A janela antes da nova temporada. Contrate quem cabe no teto enquanto os outros times também assinam.'
        : 'Jogadores sem clube. Qualquer time pode assinar, desde que o salário caiba no espaço do teto e o elenco tenha vaga (máximo de ' . Cap::ROSTER_MAX . ').',
    'actions' => $actions,
]);
?>
<div class="stack">
  <?php if ($isWindow): ?>
    <?= note('<b>Free agency aberta.</b> Contrate antes de começar a temporada ' . (int) $season
        . '. Quando terminar, use o botão <b>Próxima</b>: a liga completa os elencos e a temporada começa. Você precisa estar dentro do teto.', 'go', 'calendar2-check-fill') ?>
  <?php endif; ?>

  <?php if ($gmTeam && $cap):
    $capPct = $cap['cap_max'] > 0 ? $cap['payroll'] / max($cap['cap_max'], $cap['payroll']) * 100 : 0;
    $capTone = ['ok' => 'ok', 'over' => 'bad', 'under' => 'warn'][$cap['status']];
  ?>
  <section class="panel fa-cap <?= $stTone === 'bad' ? 'alert' : 'hot' ?>">
    <?= panel_head('Seu poder de contratação', ['icon' => 'wallet2', 'right' => chip($stTxt, $stTone, $stTone === 'ok' ? 'check-circle-fill' : 'exclamation-triangle-fill')]) ?>
    <div class="stats">
      <?= $cap['status'] === 'over'
          ? stat_tile('Espaço no teto', '−' . Cap::m((int) $cap['excess']), 'acima do teto de ' . Cap::m((int) $cap['cap_max']), 'neg')
          : stat_tile('Espaço no teto', Cap::m($space), 'folha ' . Cap::m((int) $cap['payroll']) . ' de ' . Cap::m((int) $cap['cap_max']), $space >= 3 * Cap::M ? 'pos' : ($space >= 2 * Cap::M ? 'warn' : 'neg')) ?>
      <?= stat_tile('Vagas no elenco', (string) max(0, Cap::ROSTER_MAX - $rosterCount), 'elenco ' . $rosterCount . '/' . Cap::ROSTER_MAX, $full ? 'neg' : '') ?>
      <?= stat_tile('Cabem no teto', (string) $nFit, 'de ' . count($fas) . ' agentes livres', $nFit ? 'pos' : 'neg') ?>
      <?= $maxOvr && !$full
          ? stat_tile('Cabe até', 'OVR ' . $maxOvr, 'salário de ' . Cap::m(Cap::ovrSalary($maxOvr) * Cap::M) . '/ano')
          : stat_tile('Cabe até', '—', $full ? 'abra uma vaga antes' : 'nem o mínimo de ' . Cap::m(Cap::VETERAN_MIN * Cap::M)) ?>
    </div>
    <div class="fa-capbar">
      <?= meter($capPct, $capTone) ?>
      <div class="fa-scale"><span>Folha <b><?= Cap::m((int) $cap['payroll']) ?></b></span><span>Teto <b><?= Cap::m((int) $cap['cap_max']) ?></b></span></div>
    </div>
    <?php if ($stTone !== 'ok'): ?>
      <?= note('Sem espaço ou sem vaga? Dispense ou troque alguém em <a href="' . url('cap') . '#folha">Folha e teto</a>. O salário do dispensado sai da folha na hora.', 'warn', 'lightbulb-fill') ?>
    <?php endif; ?>
  </section>
  <?php else: ?>
    <?= note('Você não está no modo GM: a IA cuida das contratações. <a href="' . url('gmselect') . '">Assumir uma franquia</a>', 'info') ?>
  <?php endif; ?>

  <div class="grid cols-main">
    <section class="panel pad-0" id="lista">
      <?= panel_head('Disponíveis', ['icon' => 'person-plus-fill', 'right' => $gm
          ? '<nav class="seg" aria-label="Filtrar agentes livres">'
            . '<a class="' . ($filter === 'todos' ? 'on' : '') . '" href="' . url('freeagency') . '#lista">Todos · ' . count($fas) . '</a>'
            . '<a class="' . ($filter === 'cabe' ? 'on' : '') . '" href="' . url('freeagency', ['f' => 'cabe']) . '#lista">Cabem · ' . $nFit . '</a></nav>'
          : '', 'meta' => $gm ? '' : count($fas) . ' jogadores']) ?>
      <?php if ($gm): ?><p class="fa-help">Salário que a assinatura cobra: tabela por OVR, calouro pela rookie scale. <span class="pos">Verde</span> cabe no seu espaço; <span class="neg">vermelho</span> não cabe.</p><?php endif; ?>
      <?php if ($list): ?>
      <div class="table-wrap">
        <table class="tbl fa-tbl">
          <thead><tr>
            <th>Jogador</th><th class="c">OVR</th><th class="num">Salário</th>
            <th class="c hide-sm">Int</th><th class="c hide-sm">3P</th><th class="c hide-sm">Arm</th><th class="c hide-sm">Reb</th><th class="c hide-sm">Def</th>
            <?php if ($gm): ?><th class="num"><span class="sr-only">Ação</span></th><?php endif; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($list as $r): $p = $r['p']; $sal = $r['sal']; $fits = $r['fits'];
            $small = $p['pos'] . ' · ' . (int) $p['age'] . ' anos'
                . (Cap::salarySource($p) === 'rookie' ? ' · rookie scale' : '')
                . ((int) ($p['award_bonus'] ?? 0) ? ' · bônus +' . (int) $p['award_bonus'] . 'M' : ''); ?>
            <tr class="<?= $gm ? ($fits ? 'fit' : 'nofit') : '' ?>">
              <td><?= player_who($p, $small) ?></td>
              <td class="c"><?= ovr_badge($p['ovr'], 'sm') ?></td>
              <td class="num"><span class="sal <?= $gm ? ($fits ? 'pos' : 'neg') : '' ?>"><?= Cap::m($sal) ?></span><span class="dim hide-sm">/ano</span></td>
              <?php foreach (['ins', 'thr', 'pmk', 'reb', 'def'] as $k): ?>
                <td class="c hide-sm"><span class="grade <?= gradeClass($p[$k]) ?>"><?= grade($p[$k]) ?></span></td>
              <?php endforeach; ?>
              <?php if ($gm): ?>
              <td class="num">
                <?php if ($fits):
                  $left = $space - $sal; ?>
                  <a class="btn btn-sm btn-primary fa-sign" href="<?= url('home', ['action' => 'sign-fa', 'fa' => $p['id']]) ?>" aria-label="Contratar <?= e($p['name']) ?>"
                     <?= link_attrs(['confirm' => ['text' => 'Contratar ' . $p['name'] . ' por ' . Cap::m($sal) . '/ano? Sobra ' . Cap::m($left) . ' de espaço no teto e o elenco fica com ' . ($rosterCount + 1) . '/' . Cap::ROSTER_MAX . '.',
                         'title' => 'Contratar agente livre', 'ok' => 'Contratar']]) ?>><?= bi('person-plus-fill') ?><span class="hide-sm">Contratar</span></a>
                <?php elseif ($full): ?>
                  <span class="chip warn" title="Elenco cheio"><?= bi('people-fill') ?><span class="hide-sm">Sem vaga</span></span>
                <?php else: ?>
                  <span class="chip bad" title="Não cabe no teto: faltam <?= e(Cap::m($sal - $space)) ?>"><?= bi('x-circle-fill') ?><span class="hide-sm">Não cabe</span></span>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php elseif ($filter === 'cabe'): ?>
        <?= empty_state('Ninguém cabe no seu teto agora.', 'Libere salário em Folha e teto ou veja a lista completa.', 'wallet2') ?>
      <?php else: ?>
        <?= empty_state('Sem agentes livres disponíveis.', 'O mercado se renova ao longo do ano.', 'person-x') ?>
      <?php endif; ?>
    </section>

    <section class="panel">
      <?= panel_head('Movimentações', ['icon' => 'arrow-repeat', 'meta' => 'Temporada ' . $season]) ?>
      <?php if ($signed): ?>
        <div class="headlines">
          <?php foreach (array_slice($signed, 0, 14) as $x): ?>
            <div class="headline fa-move <?= $x['type'] === 'dispensa' ? 'out' : 'in' ?>"><span><?= e($x['description']) ?></span></div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <?= empty_state('Nenhuma movimentação ainda.', 'Contratações e dispensas da temporada aparecem aqui.', 'arrow-repeat') ?>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php render_footer(); ?>
