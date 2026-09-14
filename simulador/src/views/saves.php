<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/Accounts.php';

$uid    = Accounts::userId();
$user   = Accounts::user();
$saves  = Accounts::saves($uid);
$free   = Accounts::freeSlots($uid);
$active = Accounts::activeSaveId();

// Dados estáticos (não exige save ativo)
$allTeams = require dirname(dirname(__DIR__)) . '/data/teams.php';
usort($allTeams, fn($a,$b) => [$a['conf'], $a['city']] <=> [$b['conf'], $b['city']]);
$eras = require dirname(dirname(__DIR__)) . '/data/eras.php';
$teamByAbbr = [];
foreach ($allTeams as $t) $teamByAbbr[$t['abbr']] = $t;

// Estrelas de cada era (para os cartões do assistente)
$eraStars = [
  'modern'  => 'Jokić · Giannis · Curry · LeBron',
  'era2016' => 'Curry · LeBron · Durant · Westbrook',
  'era2009' => 'Kobe · LeBron · Wade · CP3 · Rose',
  'era2003' => 'LeBron · Carmelo · Wade · Duncan',
  'era1997' => 'Jordan · Pippen · Malone · Shaq',
  'era1987' => 'Magic · Bird · Jordan · Kareem',
  'era1980' => 'Magic · Bird · Kareem · Dr. J',
];

// Opções do perfil do GM: name do rádio => [título, ícone, opções, padrão]
$optGroups = [
  'coach_style' => ['Estilo do técnico', 'clipboard-data', [
      'equilibrado'     => ['sliders', 'Equilibrado', 'Se adapta ao jogo, sem tendência marcada.'],
      'ofensivo'        => ['lightning-charge-fill', 'Ofensivo', 'Ritmo alto e pontuação acima da média.'],
      'defensivo'       => ['shield-fill', 'Defensivo', 'Defesa intensa e controle do ritmo.'],
      'desenvolvimento' => ['graph-up-arrow', 'Desenvolvimento', 'Os jovens evoluem mais rápido.'],
      'vencedor'        => ['trophy-fill', 'Vencedor', 'Cresce no clutch e nos playoffs.'],
  ], 'equilibrado'],
  'difficulty' => ['Dificuldade', 'speedometer2', [
      'facil'   => ['feather', 'Fácil', 'A IA é menos agressiva nas trocas e negociações.'],
      'normal'  => ['controller', 'Normal', 'Experiência equilibrada. Recomendado.'],
      'dificil' => ['fire', 'Difícil', 'A IA negocia melhor e as decisões pesam mais.'],
  ], 'normal'],
  'potential_type' => ['Potencial dos jogadores', 'stars', [
      'real'      => ['bar-chart-fill', 'Real', 'Potencial baseado na carreira de verdade.'],
      'aleatorio' => ['dice-5-fill', 'Aleatório', 'Cada save revela talentos diferentes.'],
  ], 'real'],
];

/** Cores de um time de data/teams.php no formato do tema do jogo (--team, --team-bright...). */
function saves_team_style(?array $t): string
{
    return team_theme_style($t ? ['primary_color' => $t['primary'] ?? null, 'secondary_color' => $t['secondary'] ?? null] : null);
}

render_lobby_header('Meus saves', ['page' => 'saves', 'home' => $active ? url('home') : url('saves')]);
page_head('Meus saves', [
    'eyebrow' => 'Simulador FBA',
    'sub' => $saves
        ? 'Continue uma dinastia ou comece outra. Cada save guarda uma liga inteira: elencos, finanças e história.'
        : 'Comece sua primeira dinastia: escolha a era, a franquia e o jeito do seu técnico.',
    'actions' => $free ? '<button type="button" class="btn btn-team btn-lg" data-open-wizard>' . bi('plus-lg') . 'Nova dinastia</button>' : '',
]);
?>
<div class="slots">
  <?php foreach ($saves as $s):
    $t = $teamByAbbr[$s['team_abbr'] ?? ''] ?? null;
    $isActive = $active && (int) $s['id'] === $active;
    $updated = date('d/m/Y', strtotime($s['updated_at'] ?? $s['created_at']));
    $diff = ['facil' => 'Fácil', 'normal' => 'Normal', 'dificil' => 'Difícil'][$s['difficulty'] ?? ''] ?? '';
    $teamName = $t ? $t['city'] . ' ' . $t['name'] : (string) $s['team_abbr'];
  ?>
  <article class="slot<?= $isActive ? ' is-active' : '' ?>" style="<?= e(saves_team_style($t)) ?>">
    <div class="slot-top">
      <?= team_logo((string) $s['team_abbr'], (string) ($t['primary'] ?? '#333'), 'lg') ?>
      <div class="slot-id">
        <span class="eyebrow">Slot <?= (int) $s['slot'] ?></span>
        <b><?= e($s['name']) ?></b>
        <small><?= e($teamName) ?><?= !empty($s['gm_name']) ? ' · GM ' . e($s['gm_name']) : '' ?></small>
      </div>
    </div>
    <div class="slot-meta">
      <?php if ($isActive): ?><?= chip('Em jogo', 'ok', 'circle-fill') ?><?php endif; ?>
      <?php if (!empty($s['era_name'])): ?><?= chip((string) $s['era_name'], '', 'hourglass-split') ?><?php endif; ?>
      <?php if ($diff !== ''): ?><?= chip($diff, '', 'speedometer2') ?><?php endif; ?>
      <?= chip('Salvo em ' . $updated, '', 'clock-history') ?>
    </div>
    <div class="slot-actions">
      <a class="btn btn-team" href="<?= url('home', ['action' => 'load-save', 'save' => $s['id']]) ?>"><?= bi('play-fill') ?><?= $isActive ? 'Voltar ao jogo' : 'Continuar' ?></a>
      <a class="btn btn-danger btn-icon" href="<?= url('home', ['action' => 'delete-save', 'save' => $s['id']]) ?>"
         aria-label="Excluir <?= e($s['name']) ?>" title="Excluir save"<?= link_attrs(['confirm' => [
           'text' => 'Excluir a dinastia "' . $s['name'] . '"? O save e a liga dele somem para sempre.',
           'title' => 'Excluir dinastia', 'ok' => 'Excluir', 'danger' => true]]) ?>><?= bi('trash3') ?></a>
    </div>
  </article>
  <?php endforeach; ?>

  <?php if ($free): ?>
  <button type="button" class="slot slot-new" data-open-wizard>
    <?= bi('plus-lg') ?><b>Nova dinastia</b><small>Slot <?= min($free) ?> livre</small>
  </button>
  <?php else: ?>
  <div class="slot slot-new is-full">
    <?= bi('lock-fill') ?><b>Slots lotados</b><small>O limite é de <?= Accounts::MAX_SAVES ?> saves. Exclua um para criar outro.</small>
  </div>
  <?php endif; ?>
</div>

<?php if ($free): ?>
<div class="wizard-overlay" id="wizardOverlay" role="dialog" aria-modal="true" aria-label="Nova dinastia">
  <div class="wizard-inner">

    <div class="wiz-top">
      <div class="wiz-progress">
        <?php foreach ([1 => 'Era', 2 => 'Franquia', 3 => 'GM', 4 => 'Confirmar'] as $n => $lbl): ?>
        <div class="wiz-step-dot<?= $n === 1 ? ' active' : '' ?>" data-step="<?= $n ?>">
          <span class="wiz-dot"><?= $n ?></span><span class="wiz-dot-label"><?= e($lbl) ?></span><?php if ($n < 4): ?><span class="wiz-line"></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-sm btn-ghost wiz-close" id="wizClose" aria-label="Fechar"><?= bi('x-lg') ?><span class="hide-mob">Fechar</span></button>
    </div>

    <div class="wiz-body">
      <div id="wizErr" class="note bad wiz-err" role="alert"></div>

      <!-- Passo 1: era -->
      <div class="wiz-panel active" id="step1">
        <span class="eyebrow">Passo 1 de 4</span>
        <h2 class="wiz-h">Escolha a era</h2>
        <p class="wiz-sub">Cada era começa com os elencos reais daquele ano. A partir dali, a história é sua.</p>

        <div class="era-grid">
          <?php foreach ($eras as $key => $era):
            $stars = $eraStars[$key] ?? '';
            $yr    = (int)($era['start_year'] ?? 2026);
            $yrLabel = ($yr-1) . '-' . substr((string) $yr, 2);
          ?>
          <label class="era-card" data-era-name="<?= e($era['name']) ?>" data-era-year="<?= e($yrLabel) ?>">
            <input type="radio" name="wiz_era" value="<?= e($key) ?>">
            <span class="era-check"><?= bi('check-lg') ?></span>
            <span class="era-year"><?= e($yrLabel) ?></span>
            <b class="era-nm"><?= e($era['name']) ?></b>
            <span class="era-dc"><?= e($era['desc']) ?></span>
            <?php if ($stars): ?><span class="era-stars"><?= bi('star-fill') ?><span><?= e($stars) ?></span></span><?php endif; ?>
          </label>
          <?php endforeach; ?>
        </div>

        <div class="wiz-nav">
          <button type="button" class="btn btn-team btn-lg" data-wiz-next>Escolher franquia<?= bi('arrow-right') ?></button>
        </div>
      </div>

      <!-- Passo 2: franquia -->
      <div class="wiz-panel" id="step2">
        <span class="eyebrow">Passo 2 de 4</span>
        <h2 class="wiz-h">Escolha sua franquia</h2>
        <p class="wiz-sub">Você comanda este time. Os outros <span id="wizOthers"><?= count($allTeams) - 1 ?></span> ficam com a IA.</p>

        <div class="tp-search">
          <?= bi('search') ?>
          <input type="search" class="input" id="teamSearch" placeholder="Buscar time" autocomplete="off" aria-label="Buscar time">
        </div>

        <?php foreach (['E' => 'Conferência Leste', 'W' => 'Conferência Oeste'] as $conf => $confName): ?>
        <div class="sub-h"><?= e($confName) ?></div>
        <div class="team-pick-grid">
          <?php foreach ($allTeams as $t): if ($t['conf'] !== $conf) continue;
            // eras em que o time já existe no começo (as outras o recebem por expansão)
            $inEras = array_keys(array_filter($eras, fn($er) => empty($er['teams']) || in_array($t['abbr'], $er['teams'], true))); ?>
          <label class="team-pick-card" style="<?= e(saves_team_style($t)) ?>" data-eras="<?= e(implode(' ', $inEras)) ?>"
                 data-team-name="<?= e($t['name']) ?>"
                 data-team-city="<?= e($t['city']) ?>"
                 data-team-color="<?= e($t['primary']) ?>"
                 data-search="<?= e(strtolower($t['city'].' '.$t['name'].' '.$t['abbr'])) ?>">
            <input type="radio" name="wiz_team" value="<?= e($t['abbr']) ?>">
            <span class="tpc-abbr"><?= e($t['abbr']) ?></span>
            <span class="tpc-city"><?= e($t['city']) ?></span>
            <span class="tpc-name"><?= e($t['name']) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <div class="wiz-nav">
          <button type="button" class="btn btn-lg" data-wiz-prev><?= bi('arrow-left') ?>Voltar</button>
          <button type="button" class="btn btn-team btn-lg" data-wiz-next>Configurar GM<?= bi('arrow-right') ?></button>
        </div>
      </div>

      <!-- Passo 3: perfil do GM -->
      <div class="wiz-panel" id="step3">
        <span class="eyebrow">Passo 3 de 4</span>
        <h2 class="wiz-h">Perfil do GM</h2>
        <p class="wiz-sub">Seu nome, o nome do save e o jeito do seu técnico. Isso molda como a história começa.</p>

        <div class="gm-fields">
          <div class="field">
            <label for="f_gm_name">Nome do GM</label>
            <input class="input" type="text" id="f_gm_name" placeholder="Seu nome" maxlength="30" required autocomplete="off" value="<?= e((string) ($user['name'] ?? '')) ?>">
          </div>
          <div class="field">
            <label for="f_save_name">Nome do save</label>
            <input class="input" type="text" id="f_save_name" placeholder="Minha dinastia" maxlength="40" autocomplete="off">
          </div>
        </div>

        <?php foreach ($optGroups as $radio => [$title, $gIcon, $opts, $def]): ?>
        <div class="opt-group">
          <div class="sub-h"><?= bi($gIcon) ?><?= e($title) ?></div>
          <div class="opt-cards">
            <?php foreach ($opts as $val => [$oIcon, $lbl, $desc]): $isDef = $val === $def; ?>
            <label class="opt-card<?= $isDef ? ' opt-sel' : '' ?>"<?= $isDef ? ' data-default' : '' ?>>
              <input type="radio" name="<?= e($radio) ?>" value="<?= e($val) ?>"<?= $isDef ? ' checked' : '' ?>>
              <?= bi($oIcon) ?><b><?= e($lbl) ?></b><small><?= e($desc) ?></small>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <div class="wiz-nav">
          <button type="button" class="btn btn-lg" data-wiz-prev><?= bi('arrow-left') ?>Voltar</button>
          <button type="button" class="btn btn-team btn-lg" data-wiz-next>Revisar<?= bi('arrow-right') ?></button>
        </div>
      </div>

      <!-- Passo 4: confirmar -->
      <div class="wiz-panel" id="step4">
        <span class="eyebrow">Passo 4 de 4</span>
        <h2 class="wiz-h">Tudo pronto?</h2>
        <p class="wiz-sub">Confira a nova dinastia antes de entrar em quadra.</p>

        <div class="confirm-banner">
          <div class="cb-stripe" id="confStripe"></div>
          <div class="cb-body">
            <div class="cb-badge" id="confBadge">—</div>
            <div class="cb-meta">
              <span class="label">Sua franquia</span>
              <h2 id="confTeam">—</h2>
              <p>GM <b id="confGM">—</b> · Save <b id="confSave">—</b></p>
            </div>
          </div>
        </div>

        <div class="confirm-items">
          <div class="ci-box"><span class="ci-lbl">Era</span><b class="ci-val" id="confEra">—</b></div>
          <div class="ci-box"><span class="ci-lbl">Estilo</span><b class="ci-val" id="confStyle">—</b></div>
          <div class="ci-box"><span class="ci-lbl">Dificuldade</span><b class="ci-val" id="confDiff">—</b></div>
          <div class="ci-box"><span class="ci-lbl">Potencial</span><b class="ci-val" id="confPot">—</b></div>
        </div>

        <!-- Formulário que cria o save (o assistente preenche os campos escondidos) -->
        <form method="post" action="<?= url('home', ['action' => 'create-save']) ?>" id="wizForm" data-busy="Criando a dinastia">
          <input type="hidden" name="slot"           id="h_slot" value="<?= min($free) ?>">
          <input type="hidden" name="era"            id="h_era"  value="">
          <input type="hidden" name="team"           id="h_team" value="">
          <input type="hidden" name="gm_name"        id="h_gm_name" value="">
          <input type="hidden" name="name"           id="h_save_name" value="">
          <input type="hidden" name="coach_style"    id="h_coach_style" value="equilibrado">
          <input type="hidden" name="difficulty"     id="h_difficulty" value="normal">
          <input type="hidden" name="potential_type" id="h_potential_type" value="real">

          <div class="wiz-nav">
            <button class="btn btn-lg" type="button" data-wiz-prev><?= bi('arrow-left') ?>Editar</button>
            <button class="btn btn-team btn-lg" type="submit"
                    data-confirm="Criar a nova dinastia? Isso leva alguns segundos." data-confirm-title="Nova dinastia" data-confirm-ok="Criar dinastia">
              <?= bi('play-fill') ?>Criar dinastia
            </button>
          </div>
        </form>
      </div>
    </div>

  </div>
</div>
<script src="<?= e(asset_v('assets/js/wizard.js')) ?>"></script>
<?php endif; ?>
<?php render_lobby_footer(); ?>
