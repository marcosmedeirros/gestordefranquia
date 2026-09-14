<?php
require_once dirname(__DIR__) . '/helpers.php';
$teams = League::allTeams();
$current = League::gmTeam();
$fired = League::isFired();
$cur = $current ? League::team($current) : null;
render_header($fired ? 'Nova franquia' : ($current ? 'Franquias' : 'Escolha sua franquia'));

// força de cada elenco calculada uma vez: a ordenação e os cartões usam o mesmo número
$strength = [];
foreach ($teams as $t) $strength[(int) $t['id']] = League::teamStrength((int) $t['id']);
usort($teams, fn($a, $b) => $strength[(int) $b['id']] <=> $strength[(int) $a['id']]);
$avg = League::avgStrength();
$canPick = $fired || !$current;

$tiers = [
    'title'    => ['Candidatos ao título', 'trophy-fill', 'ok'],
    'playoffs' => ['Times de playoffs', 'bar-chart-fill', 'info'],
    'playin'   => ['Briga pelo play-in', 'shuffle', 'warn'],
    'rebuild'  => ['Reconstrução', 'tools', 'bad'],
];
$groups = array_fill_keys(array_keys($tiers), []);
foreach ($teams as $t) {
    $d = $strength[(int) $t['id']] - $avg;
    $groups[$d >= 8 ? 'title' : ($d >= 0 ? 'playoffs' : ($d >= -8 ? 'playin' : 'rebuild'))][] = $t;
}

if ($fired && $cur): ?>
<section class="panel alert gm-fired">
  <?= team_logo($cur['abbr'], $cur['primary_color'] ?? '#333', 'xl') ?>
  <div>
    <span class="eyebrow">Demitido</span>
    <h1>O <?= e(teamFull($cur)) ?> te dispensou</h1>
    <p>A diretoria perdeu a paciência com as temporadas abaixo da meta. Sua carreira continua: outras franquias querem o seu trabalho.
       O técnico vai com você, a paciência da diretoria recomeça e a meta sai do elenco que você encontrar.</p>
  </div>
</section>
<div class="sub-h gm-lead">Escolha a nova casa</div>
<?php elseif ($current):
    page_head('Franquias da liga', [
        'eyebrow' => 'Liga',
        'sub_html' => 'Você comanda o <b class="strong">' . e($cur ? teamFull($cur) : '') . '</b>. Só dá para assumir outra franquia quando a diretoria te demite, e isso acontece quando a paciência dela chega a zero. <a class="link" href="' . url('manage') . '">Ver a diretoria</a>',
    ]);
else:
    page_head('Escolha sua franquia', [
        'eyebrow' => 'Nova carreira',
        'sub' => 'Time forte cobra título logo de cara. Time em reconstrução dá tempo para montar o elenco. A meta da diretoria sai do elenco que você assumir.',
    ]);
endif; ?>

<div class="stack" id="franquias">
  <?php foreach ($tiers as $key => [$label, $icon, $tone]): if (!$groups[$key]) continue; $n = count($groups[$key]); ?>
  <section class="gm-tier">
    <?= panel_head($label, ['icon' => $icon, 'right' => chip($n === 1 ? '1 time' : "$n times", $tone)]) ?>
    <div class="gm-grid">
      <?php foreach ($groups[$key] as $t):
        $tid = (int) $t['id'];
        $str = $strength[$tid];
        $d = $str - $avg;
        $cap = Cap::summary($tid);
        $isCur = (int) $current === $tid;
        $hire = $canPick && !$isCur;
        $titles = (int) $t['titles'];
        $capTone = $cap['status'] === 'over' ? 'neg' : ($cap['status'] === 'under' ? 'warn-txt' : '');
        $capSub = $cap['status'] === 'over' ? Cap::m((int) ($cap['excess'] ?? 0)) . ' acima do teto'
                : ($cap['status'] === 'under' ? Cap::m((int) ($cap['deficit'] ?? 0)) . ' abaixo do piso' : Cap::m((int) $cap['space']) . ' livre'); ?>
        <a class="gm-card<?= $isCur ? ' is-cur' : '' ?><?= $hire ? ' can-hire' : '' ?>"
           href="<?= $hire ? url('home', ['action' => 'set-gm', 'team' => $tid]) : url('team', ['id' => $tid]) ?>"
           <?= $hire ? link_attrs(['confirm' => ['text' => 'Assumir o ' . teamFull($t) . '?', 'title' => 'Assumir franquia', 'ok' => 'Assumir']]) : '' ?>>
          <img class="gmc-wm" src="<?= e(logo_url((string) $t['abbr'])) ?>" alt="" aria-hidden="true" loading="lazy" onerror="this.remove()">
          <div class="gmc-top">
            <?= team_logo($t['abbr'], $t['primary_color'] ?? '#333', 'lg') ?>
            <div class="gmc-id">
              <b><?= e(teamFull($t)) ?></b>
              <small><?= $t['conf'] === 'E' ? 'Leste' : 'Oeste' ?> · <?= (int) $t['wins'] ?>-<?= (int) $t['losses'] ?><?= $titles ? ' · ' . ($titles === 1 ? '1 título' : "$titles títulos") : '' ?></small>
            </div>
          </div>
          <div class="gmc-stats">
            <div><small>Força</small><b><?= $str ?></b><span class="<?= $d > 0 ? 'pos' : ($d < 0 ? 'neg' : '') ?>"><?= $d > 0 ? "$d acima da média" : ($d < 0 ? abs($d) . ' abaixo da média' : 'na média') ?></span></div>
            <div><small>Folha</small><b><?= e(Cap::m((int) $cap['payroll'])) ?></b><span class="<?= $capTone ?>"><?= e($capSub) ?></span></div>
          </div>
          <div class="gmc-foot">
            <?php if ($isCur): ?>
              <?= $fired ? chip('Te demitiu', 'bad', 'x-circle-fill') : chip('Sua franquia', 'team', 'check-circle-fill') ?>
              <span class="more">Ver time<?= bi('chevron-right') ?></span>
            <?php elseif ($hire): ?>
              <span class="btn btn-team btn-sm gmc-cta">Assumir<?= bi('chevron-right') ?></span>
            <?php else: ?>
              <span class="more">Ver time<?= bi('chevron-right') ?></span>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<?php render_footer(); ?>
