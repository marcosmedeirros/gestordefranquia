<?php
require_once dirname(__DIR__) . '/helpers.php';

$gmId = (int) League::gmTeam();
render_header('Diretoria e técnico');
if (!$gmId) {
    page_head('Diretoria e técnico', ['eyebrow' => 'Elenco']);
    echo note('Você ainda não comanda um time. <a href="' . url('gmselect') . '">Escolher uma franquia</a>', 'info');
    render_footer();
    exit;
}

$t         = League::team($gmId);
$color     = (string) ($t['primary_color'] ?? '#333');
$roster    = League::roster($gmId);
$goal      = League::boardGoalProgress();
$oc        = League::ownerConfidence();
$pat       = League::boardPatience();
$coach     = League::gmCoach();
$block     = League::tradeBlock();
$decisions = League::pendingDecisions();
$upcoming  = League::upcomingGames($gmId, 8);
$nextGame  = $upcoming[0] ?? null;

$focus   = array_values(array_filter($roster, fn($p) => !empty($p['dev_focus'])));
$onBlock = array_values(array_filter($roster, fn($p) => in_array((int) $p['id'], $block, true)));

// Resumo da rotação salva. Lesionado não conta (na escalação ele fica fora).
$healthy  = array_values(array_filter($roster, fn($p) => (int) ($p['injury_games'] ?? 0) === 0));
$nStart   = count(array_filter($healthy, fn($p) => (int) ($p['is_starter'] ?? 0) === 1));
$inRot    = count(array_filter($healthy, fn($p) => (int) ($p['min_target'] ?? 0) > 0));
$totalMin = array_sum(array_map(fn($p) => max(0, min(48, (int) ($p['min_target'] ?? 0))), $healthy));
// Como o motor escala: os 8 primeiros disponíveis (titular antes, depois OVR). Soma zero = minutos automáticos.
$avail = array_values(array_filter($healthy, fn($p) => (int) ($p['rest_games'] ?? 0) === 0));
usort($avail, fn($a, $b) => [(int) $b['is_starter'], (int) $b['ovr'], (int) $a['id']] <=> [(int) $a['is_starter'], (int) $a['ovr'], (int) $b['id']]);
$top8     = array_slice($avail, 0, 8);
$autoMins = $totalMin === 0;
$playing  = $autoMins ? count($top8) : count(array_filter($top8, fn($p) => (int) ($p['min_target'] ?? 0) > 0));

// save-scheme, save-coach e save-rotation voltam para esta tela com ?saved= (e ?w= com os avisos da rotação).
$saved = is_string($_GET['saved'] ?? null) ? $_GET['saved'] : '';
$warns = is_string($_GET['w'] ?? null) && $_GET['w'] !== '' ? explode('|', $_GET['w']) : [];

$goalTone = ['andamento' => 'info', 'cumprida' => 'ok', 'falhou' => 'bad'];
$goalText = ['andamento' => 'em andamento', 'cumprida' => 'cumprida', 'falhou' => 'não cumprida'];
$ocTone   = $oc ? ((int) $oc['value'] >= 45 ? 'ok' : ((int) $oc['value'] >= 30 ? 'warn' : 'bad')) : '';

// O que cada esquema faz e contra o quê ele rende: cada ataque castiga uma defesa e sofre contra outra.
$schemeInfo = League::SCHEME_INFO;
$curOff = in_array($t['scheme_off'] ?? '', League::SCHEMES_OFF, true) ? $t['scheme_off'] : League::SCHEMES_OFF[0];
$curDef = in_array($t['scheme_def'] ?? '', League::SCHEMES_DEF, true) ? $t['scheme_def'] : League::SCHEMES_DEF[0];

$schemeGroup = function (string $title, string $name, array $options, string $current) use ($schemeInfo): string {
    $lid = 'sg-' . $name;
    $h = '<div class="sub-h" id="' . e($lid) . '">' . e($title) . '</div><div class="mg-opts" role="radiogroup" aria-labelledby="' . e($lid) . '">';
    foreach ($options as $s) {
        $h .= '<label class="mg-opt"><input type="radio" name="' . e($name) . '" value="' . e($s) . '"' . ($s === $current ? ' checked' : '') . '>'
            . '<span><b>' . e($s) . '</b><small>' . e($schemeInfo[$s] ?? '') . '</small></span></label>';
    }
    return $h . '</div>';
};

$coachAttrs = [
    'ofensivo'        => ['lightning-charge-fill', 'Ofensivo',        'Deixa o ataque mais eficiente'],
    'defensivo'       => ['shield-fill',           'Defensivo',       'Melhora a defesa do time'],
    'desenvolvimento' => ['graph-up-arrow',        'Desenvolvimento', 'Jovens evoluem mais na entressafra'],
    'gestao'          => ['people-fill',           'Gestão',          'Cuida da moral e da química'],
    'intensidade'     => ['fire',                  'Intensidade',     'Mais rebotes, mas mais desgaste e lesões'],
];

$playerRow = fn(array $p): string => '<div class="spread">'
    . player_who($p, (string) $p['pos'] . ' · ' . (int) $p['age'] . ' anos', $color) . ovr_badge($p['ovr'], 'sm') . '</div>';

page_head('Diretoria e técnico', [
    'eyebrow' => 'Elenco',
    'sub' => 'O que a diretoria cobra de você, quem comanda o time e como ele joga.',
]);
?>
<div class="stack">
  <?php if ($saved === 'scheme'): ?>
    <?= note('Estilo de jogo salvo. Vale a partir do próximo jogo.', 'ok') ?>
  <?php elseif ($saved === 'coach'): ?>
    <?= note('Nome do técnico salvo.', 'ok') ?>
  <?php elseif ($saved === 'rotation'): ?>
    <?= $warns
        ? note('<b>Rotação salva, mas atenção:</b> ' . e(implode(' ', $warns)) . ' <a href="' . url('lineup') . '">Ajustar na escalação</a>', 'warn')
        : note('Rotação salva. <a href="' . url('lineup') . '">Voltar para a escalação</a>', 'ok') ?>
  <?php endif; ?>

  <?php render_decisions($decisions, url('manage')); ?>

  <section class="panel" id="diretoria">
    <?= panel_head('Diretoria', ['icon' => 'bank2', 'meta' => 'Temporada ' . League::season()]) ?>
    <div class="grid cols-3 mg-board">
      <div class="mg-box">
        <span class="label">Meta da temporada</span>
        <?php if ($goal): ?>
          <b class="mg-title"><?= e($goal['desc']) ?></b>
          <div class="row mg-line">
            <?= chip($goalText[$goal['status']] ?? (string) $goal['status'], $goalTone[$goal['status']] ?? '') ?>
            <span class="muted"><?= e($goal['detail']) ?></span>
          </div>
        <?php else: ?>
          <b class="mg-title">Sem meta</b>
          <p class="muted">A diretoria ainda não definiu uma meta para esta temporada.</p>
        <?php endif; ?>
      </div>

      <div class="mg-box">
        <span class="label">Paciência</span>
        <div class="row mg-pat">
          <?= pips($pat, League::PATIENCE_MAX) ?>
          <b class="mg-num"><?= $pat ?><small>/<?= League::PATIENCE_MAX ?></small></b>
          <?php if ($pat <= 1): ?><?= chip('Última chance', 'bad', 'exclamation-triangle-fill') ?><?php endif; ?>
        </div>
        <p class="muted">Meta cumprida devolve 1. Meta perdida tira 1, ou 2 se nem o mínimo sair. Se zerar, você é demitido.</p>
      </div>

      <div class="mg-box">
        <span class="label">Confiança do dono</span>
        <?php if ($oc): ?>
          <div class="row mg-line">
            <b class="mg-num"><?= (int) $oc['value'] ?><small>%</small></b>
            <?= chip((string) $oc['label'], $ocTone) ?>
          </div>
          <?= meter((float) $oc['value'], $ocTone) ?>
          <p class="muted">Compara a campanha com o que o elenco promete. Sequências pesam.</p>
        <?php else: ?>
          <p class="muted">Sem avaliação por enquanto.</p>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <div class="grid cols-2 mg-duo">
    <section class="panel" id="estilo">
      <?= panel_head('Estilo de jogo', ['icon' => 'clipboard2-pulse-fill']) ?>
      <form method="post" action="<?= url('home', ['action' => 'save-scheme']) ?>" class="mg-scheme">
        <input type="hidden" name="team" value="<?= $gmId ?>">
        <?= $schemeGroup('Ataque', 'scheme_off', League::SCHEMES_OFF, (string) $curOff) ?>
        <?= $schemeGroup('Defesa', 'scheme_def', League::SCHEMES_DEF, (string) $curDef) ?>
        <div class="mg-actions">
          <button class="btn btn-team" type="submit"><?= bi('check2-circle') ?>Salvar estilo</button>
        </div>
      </form>
    </section>

    <?php if ($coach):
      // coaches.wins/losses/seasons nunca são atualizados pelo jogo; mostrar o recorde daria sempre 0-0.
      $coachName = trim((string) $coach['name']); ?>
    <section class="panel" id="tecnico">
      <?= panel_head('Técnico', ['icon' => 'person-badge-fill', 'right' => chip('Estilo ' . ($coach['style'] ?? 'equilibrado'), 'team')]) ?>
      <div class="mg-coach">
        <span class="mg-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($coachName, 0, 1))) ?></span>
        <div class="mg-coach-txt">
          <b class="mg-coach-name"><?= e($coachName) ?></b>
          <small class="muted">Comanda o <?= e(teamFull($t)) ?> · <?= (int) $t['wins'] ?>-<?= (int) $t['losses'] ?> na temporada</small>
        </div>
        <details class="mg-rename">
          <summary class="btn btn-sm btn-ghost"><?= bi('pencil-fill') ?><span class="mg-rn-closed">Renomear</span><span class="mg-rn-open">Cancelar</span></summary>
          <form method="post" action="<?= url('home', ['action' => 'save-coach']) ?>" class="form-row">
            <div class="field">
              <label for="coachName">Nome do técnico</label>
              <input id="coachName" class="input" type="text" name="coach_name" value="<?= e($coachName) ?>" maxlength="40" required>
            </div>
            <button class="btn btn-team" type="submit">Salvar nome</button>
          </form>
        </details>
      </div>
      <div class="mg-attrs">
        <?php foreach ($coachAttrs as $key => [$icon, $label, $tip]): $v = (int) ($coach[$key] ?? 0); ?>
        <div class="mg-attr">
          <?= bi($icon) ?>
          <div class="mg-attr-txt"><b><?= e($label) ?></b><small><?= e($tip) ?></small></div>
          <b class="mg-attr-val"><?= $v ?></b>
          <?= meter($v) ?>
        </div>
        <?php endforeach; ?>
      </div>
      <p class="muted mg-foot">Os atributos vêm do estilo escolhido ao criar o save e pesam nos jogos. Desenvolvimento soma com o foco de treino.</p>
    </section>
    <?php endif; ?>
  </div>

  <div class="grid cols-2 mg-duo">
    <section class="panel" id="projeto">
      <?= panel_head('Projeto do elenco', ['icon' => 'compass-fill', 'more' => ['Escalação', url('lineup')]]) ?>
      <div class="mg-subhead">
        <span class="sub-h">Foco de treino</span>
        <?= chip(count($focus) . ' de ' . League::DEV_FOCUS_MAX, count($focus) ? 'info' : '', 'bullseye') ?>
      </div>
      <p class="muted mg-hint">Até <?= League::DEV_FOCUS_MAX ?> jogadores de até 25 anos evoluem mais na entressafra.</p>
      <div class="stack-sm mg-list">
        <?php foreach ($focus as $p) echo $playerRow($p); ?>
        <?php if (!$focus): ?><p class="dim mg-empty">Ninguém no foco. Toque num jogador jovem na escalação para escolher.</p><?php endif; ?>
      </div>

      <div class="divider"></div>

      <div class="mg-subhead">
        <span class="sub-h">Vitrine de trocas</span>
        <?= chip(count($onBlock) . (count($onBlock) === 1 ? ' jogador' : ' jogadores'), count($onBlock) ? 'warn' : '', 'megaphone-fill') ?>
      </div>
      <p class="muted mg-hint">A liga sabe que estão à venda e manda propostas na caixa de entrada.</p>
      <div class="stack-sm mg-list">
        <?php foreach ($onBlock as $p) echo $playerRow($p); ?>
        <?php if (!$onBlock): ?><p class="dim mg-empty">Ninguém na vitrine.</p><?php endif; ?>
      </div>
    </section>

    <section class="panel" id="rotacao">
      <?= panel_head('Rotação', ['icon' => 'stopwatch']) ?>
      <div class="stats">
        <?= stat_tile('Titulares', $nStart . '/5', $nStart === 5 ? 'quinteto completo' : 'precisa de 5', $nStart === 5 ? 'pos' : 'warn') ?>
        <?= stat_tile('Na rotação', (string) $playing, $autoMins ? 'automático' : ($inRot > 8 ? $inRot . ' com minutos' : 'jogam até 8'),
            $playing >= 5 && $playing <= 8 && ($autoMins || $inRot <= 8) ? 'pos' : 'warn') ?>
        <?= stat_tile('Minutos', $autoMins ? 'Auto' : (string) $totalMin,
            $autoMins ? 'o jogo divide os 240' : ($totalMin === 240 ? 'fechou 240' : 'o jogo ajusta para 240'), !$autoMins && $totalMin === 240 ? 'pos' : '') ?>
      </div>
      <p class="muted mg-foot">Titulares e minutos se definem na escalação, junto com conversa, descanso e dispensa de cada jogador.</p>
      <div class="mg-actions">
        <a class="btn btn-team" href="<?= url('lineup') ?>"><?= bi('people-fill') ?>Abrir a escalação</a>
      </div>
    </section>
  </div>

  <div class="grid cols-side">
    <section class="panel">
      <?= panel_head('Próximos jogos', ['icon' => 'calendar3', 'more' => ['Calendário', url('schedule')]]) ?>
      <?php render_team_schedule($upcoming); ?>
    </section>

    <section class="panel" id="scouting">
      <?= panel_head('Scouting', ['icon' => 'binoculars-fill', 'meta' => $nextGame
          ? (!empty($nextGame['is_home']) ? 'vs ' : '@ ') . $nextGame['opp_abbr'] . ' · ' . League::dateLabel((int) $nextGame['day'])
          : '']) ?>
      <?php if ($nextGame): ?>
        <?php render_scout_card(League::scoutReport((int) $nextGame['opp_id'], $gmId)); ?>
      <?php else: ?>
        <?= empty_state('Sem adversário marcado.', 'O relatório aparece quando houver um próximo jogo.', 'binoculars') ?>
      <?php endif; ?>
    </section>
  </div>
</div>

<script>
// O aviso de "salvo" não deve voltar num F5.
(function () {
  try {
    var u = new URL(window.location.href);
    if (!u.searchParams.has('saved') && !u.searchParams.has('w')) return;
    u.searchParams.delete('saved');
    u.searchParams.delete('w');
    history.replaceState(history.state, '', u.pathname + u.search + u.hash);
  } catch (err) {}
})();
</script>
<?php render_footer(); ?>
