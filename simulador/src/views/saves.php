<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/Accounts.php';

fba_head('Meus Saves — FBA');

$uid   = Accounts::userId();
$user  = Accounts::user();
$saves = Accounts::saves($uid);
$free  = Accounts::freeSlots($uid);
$err   = $_GET['err'] ?? null;

// Dados estáticos (não exige save ativo)
$allTeams = require dirname(dirname(__DIR__)) . '/data/teams.php';
usort($allTeams, fn($a,$b) => [$a['conf'], $a['city']] <=> [$b['conf'], $b['city']]);
$eras = require dirname(dirname(__DIR__)) . '/data/eras.php';

// Mapa extra de estrelas por era (para os cards do wizard)
$eraStars = [
  'modern'  => 'Jokić · Giannis · Curry · LeBron',
  'era2016' => 'Curry · LeBron · Durant · Westbrook',
  'era2009' => 'Kobe · LeBron · Wade · CP3 · Rose',
  'era2003' => 'LeBron · Carmelo · Wade · Duncan',
  'era1997' => 'Jordan · Pippen · Malone · Shaq',
  'era1987' => 'Magic · Bird · Jordan · Kareem',
  'era1980' => 'Magic · Bird · Kareem · Dr. J',
];
$eraEmojis = [
  'modern'  => '🏀', 'era2016' => '⚡', 'era2009' => '🔥',
  'era2003' => '👑', 'era1997' => '🐂', 'era1987' => '✨', 'era1980' => '🌟',
];
?>
<body class="auth-body">

<!-- ===== LAYOUT PRINCIPAL ===== -->
<div class="saves-layout">

  <!-- Topbar -->
  <div class="saves-topbar">
    <div class="logo-row">
      <img src="assets/img/fba-logo.svg" alt="FBA">
      <span>FBA</span>
    </div>
    <div style="display:flex;align-items:center;gap:18px">
      <?php if ($user): ?><span class="muted" style="font-size:14px">👤 <?= e($user['username']) ?></span><?php endif; ?>
      <a class="logout-link" href="<?= url('home', ['action' => 'logout']) ?>">⎋ Sair</a>
    </div>
  </div>

  <!-- Corpo -->
  <div class="saves-body">
    <h1 class="saves-title">Selecionar Save</h1>
    <p class="saves-sub">Carregue uma partida existente ou comece uma nova dinastia.</p>

    <?php if ($err): ?><div class="auth-err" style="margin-bottom:18px"><?= e($err) ?></div><?php endif; ?>

    <!-- Grade de slots -->
    <div class="slots-grid">
      <?php foreach ($saves as $s):
        // pegar cor primária do time
        $tc = '#E4002B';
        foreach ($allTeams as $t) { if ($t['abbr'] === $s['team_abbr']) { $tc = $t['primary'] ?? '#E4002B'; break; } }
        $updated = date('d/m/Y', strtotime($s['updated_at'] ?? $s['created_at']));
      ?>
      <div class="slot-card">
        <div class="slot-banner" style="background:linear-gradient(135deg,<?= e($tc) ?>,<?= e($tc) ?>88)">
          <span class="slot-num">SLOT <?= (int)$s['slot'] ?></span>
          <span class="slot-abbr"><?= e($s['team_abbr']) ?></span>
          <div class="slot-info">
            <strong><?= e($s['name']) ?></strong>
            <span><?= e($s['gm_name']) ?></span>
          </div>
        </div>
        <div class="slot-body">
          <div class="slot-meta">
            <?php if (!empty($s['era_name'])): ?>
            <div class="sm-item"><strong><?= e($s['era_name']) ?></strong>Era</div>
            <?php endif; ?>
            <div class="sm-item"><strong><?= e($updated) ?></strong>Último jogo</div>
          </div>
          <div class="slot-actions">
            <a class="btn btn-primary" href="<?= url('home', ['action' => 'load-save', 'save' => $s['id']]) ?>">▶ Continuar</a>
            <a class="btn btn-danger btn-sm" href="<?= url('home', ['action' => 'delete-save', 'save' => $s['id']]) ?>"
               onclick="return confirm('Excluir \'<?= e(addslashes($s['name'])) ?>\'? Esta ação é permanente.')">🗑</a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>

      <?php if ($free): ?>
      <div class="slot-new" data-open-wizard>
        <div class="sn-icon">+</div>
        <div class="sn-label">NOVA DINASTIA</div>
        <div style="margin-top:6px;font-size:12px;color:var(--muted)">Slot <?= min($free) ?> disponível</div>
      </div>
      <?php else: ?>
      <div class="slot-new" style="cursor:default;pointer-events:none;opacity:.4">
        <div class="sn-icon">🔒</div>
        <div class="sn-label">SLOTS LOTADOS</div>
        <div style="margin-top:6px;font-size:12px;color:var(--muted)">Exclua um save para criar outro</div>
      </div>
      <?php endif; ?>
    </div><!-- /slots-grid -->

    <?php if (!$saves && !$free): ?>
    <p class="muted" style="text-align:center;margin-top:40px">Você atingiu o limite de <?= Accounts::MAX_SAVES ?> saves.</p>
    <?php endif; ?>
  </div><!-- /saves-body -->

</div><!-- /saves-layout -->


<?php if ($free): ?>
<!-- ===== WIZARD OVERLAY ===== -->
<div class="wizard-overlay" id="wizardOverlay">
  <button class="wiz-close" id="wizClose">✕ Fechar</button>

  <div class="wizard-inner">

    <!-- Barra de progresso -->
    <div class="wiz-progress">
      <div class="wiz-step-dot active" data-step="1">
        <div class="wiz-dot">1</div>
        <span class="wiz-dot-label">Era</span>
        <div class="wiz-line"></div>
      </div>
      <div class="wiz-step-dot" data-step="2">
        <div class="wiz-dot">2</div>
        <span class="wiz-dot-label">Franquia</span>
        <div class="wiz-line"></div>
      </div>
      <div class="wiz-step-dot" data-step="3">
        <div class="wiz-dot">3</div>
        <span class="wiz-dot-label">GM</span>
        <div class="wiz-line"></div>
      </div>
      <div class="wiz-step-dot" data-step="4">
        <div class="wiz-dot">4</div>
        <span class="wiz-dot-label">Confirmar</span>
      </div>
    </div>

    <div id="wizErr" class="wiz-err"></div>

    <!-- ══════════════════ STEP 1: ERA ══════════════════ -->
    <div class="wiz-panel active" id="step1">
      <h2 class="wiz-h">Escolha a era</h2>
      <p class="wiz-sub">Cada era começa com elencos históricos reais. A história se desenvolve a partir do ano escolhido.</p>

      <div class="era-grid">
        <?php foreach ($eras as $key => $era):
          $emoji = $eraEmojis[$key] ?? '🏀';
          $stars = $eraStars[$key] ?? '';
          $yr    = (int)($era['start_year'] ?? 2026);
          $yrLabel = ($yr-1) . '-' . substr($yr, 2);
        ?>
        <label class="era-card" data-era-name="<?= e($era['name']) ?>" data-era-year="<?= e($yrLabel) ?>">
          <input type="radio" name="wiz_era" value="<?= e($key) ?>">
          <div class="era-badge"><?= $emoji ?> <?= e($yrLabel) ?></div>
          <div class="era-sel-icon">✓</div>
          <div style="margin-top:20px"></div>
          <div class="era-nm"><?= e($era['name']) ?></div>
          <div class="era-dc"><?= e($era['desc']) ?></div>
          <?php if ($stars): ?><div class="era-stars">⭐ <?= e($stars) ?></div><?php endif; ?>
        </label>
        <?php endforeach; ?>
      </div>

      <div class="wiz-nav">
        <span></span>
        <button class="btn btn-primary btn-lg" data-wiz-next>Escolher franquia →</button>
      </div>
    </div>

    <!-- ══════════════════ STEP 2: TEAM ══════════════════ -->
    <div class="wiz-panel" id="step2">
      <h2 class="wiz-h">Escolha sua franquia</h2>
      <p class="wiz-sub">Você assumirá o controle desta franquia. Os outros 29 times serão gerenciados pela IA.</p>

      <input type="text" class="tp-search" id="teamSearch" placeholder="🔍  Buscar time..." autocomplete="off">

      <div class="tp-conf-label">Conferência Leste</div>
      <div class="team-pick-grid">
        <?php foreach ($allTeams as $t): if ($t['conf'] !== 'E') continue; ?>
        <label class="team-pick-card"
               style="background:linear-gradient(135deg,<?= e($t['primary']) ?>,<?= e($t['secondary'] ?? $t['primary']) ?>cc)"
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

      <div class="tp-conf-label">Conferência Oeste</div>
      <div class="team-pick-grid">
        <?php foreach ($allTeams as $t): if ($t['conf'] !== 'W') continue; ?>
        <label class="team-pick-card"
               style="background:linear-gradient(135deg,<?= e($t['primary']) ?>,<?= e($t['secondary'] ?? $t['primary']) ?>cc)"
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

      <div class="wiz-nav">
        <button class="btn btn-lg" data-wiz-prev>← Voltar</button>
        <button class="btn btn-primary btn-lg" data-wiz-next>Configurar GM →</button>
      </div>
    </div>

    <!-- ══════════════════ STEP 3: GM SETUP ══════════════════ -->
    <div class="wiz-panel" id="step3">
      <h2 class="wiz-h">Perfil do GM</h2>
      <p class="wiz-sub">Defina a identidade da sua franquia. Esses parâmetros moldam como sua história começa.</p>

      <div class="gm-fields">
        <div class="gm-field">
          <span class="gm-flabel">Nome do GM</span>
          <input class="gm-input" type="text" id="f_gm_name" placeholder="Seu nome" maxlength="30" required>
        </div>
        <div class="gm-field">
          <span class="gm-flabel">Nome do Save</span>
          <input class="gm-input" type="text" id="f_save_name" placeholder="Minha Dinastia" maxlength="40">
        </div>
      </div>

      <!-- Estilo de jogo -->
      <div class="opt-group">
        <div class="opt-group-title">🎯 Estilo de jogo</div>
        <div class="opt-cards">
          <label class="opt-card" data-default>
            <input type="radio" name="coach_style" value="equilibrado" checked>
            <span class="oc-icon">⚖️</span>
            <span class="oc-lbl">Equilibrado</span>
            <span class="oc-desc">Jogo adaptável, sem tendência marcada</span>
          </label>
          <label class="opt-card">
            <input type="radio" name="coach_style" value="ofensivo">
            <span class="oc-icon">⚡</span>
            <span class="oc-lbl">Ofensivo</span>
            <span class="oc-desc">Pace alto, pontuação acima da média</span>
          </label>
          <label class="opt-card">
            <input type="radio" name="coach_style" value="defensivo">
            <span class="oc-icon">🛡️</span>
            <span class="oc-lbl">Defensivo</span>
            <span class="oc-desc">Intensidade defensiva, controle de ritmo</span>
          </label>
          <label class="opt-card">
            <input type="radio" name="coach_style" value="desenvolvimento">
            <span class="oc-icon">📈</span>
            <span class="oc-lbl">Desenvolvimento</span>
            <span class="oc-desc">Jovens progridem mais rápido</span>
          </label>
          <label class="opt-card">
            <input type="radio" name="coach_style" value="vencedor">
            <span class="oc-icon">🏆</span>
            <span class="oc-lbl">Vencedor</span>
            <span class="oc-desc">Clutch e playoffs no DNA do time</span>
          </label>
        </div>
      </div>

      <!-- Dificuldade -->
      <div class="opt-group">
        <div class="opt-group-title">⚙️ Dificuldade</div>
        <div class="opt-cards">
          <label class="opt-card">
            <input type="radio" name="difficulty" value="facil">
            <span class="oc-icon">🌱</span>
            <span class="oc-lbl">Fácil</span>
            <span class="oc-desc">IA menos agressiva nas trocas e negociações</span>
          </label>
          <label class="opt-card" data-default>
            <input type="radio" name="difficulty" value="normal" checked>
            <span class="oc-icon">🎮</span>
            <span class="oc-lbl">Normal</span>
            <span class="oc-desc">Experiência balanceada — recomendado</span>
          </label>
          <label class="opt-card">
            <input type="radio" name="difficulty" value="dificil">
            <span class="oc-icon">🔥</span>
            <span class="oc-lbl">Difícil</span>
            <span class="oc-desc">IA otimizada, decisões mais complexas</span>
          </label>
        </div>
      </div>

      <!-- Potencial dos jogadores -->
      <div class="opt-group">
        <div class="opt-group-title">🧬 Potencial dos jogadores</div>
        <div class="opt-cards">
          <label class="opt-card" data-default>
            <input type="radio" name="potential_type" value="real" checked>
            <span class="oc-icon">📊</span>
            <span class="oc-lbl">Real</span>
            <span class="oc-desc">Potenciais baseados na carreira histórica</span>
          </label>
          <label class="opt-card">
            <input type="radio" name="potential_type" value="aleatorio">
            <span class="oc-icon">🎲</span>
            <span class="oc-lbl">Aleatório</span>
            <span class="oc-desc">Cada save tem revelações diferentes</span>
          </label>
        </div>
      </div>

      <div class="wiz-nav">
        <button class="btn btn-lg" data-wiz-prev>← Voltar</button>
        <button class="btn btn-primary btn-lg" data-wiz-next>Revisar →</button>
      </div>
    </div>

    <!-- ══════════════════ STEP 4: CONFIRM ══════════════════ -->
    <div class="wiz-panel" id="step4">
      <h2 class="wiz-h">Pronto para jogar?</h2>
      <p class="wiz-sub">Verifique as configurações da sua nova dinastia antes de entrar em quadra.</p>

      <!-- Banner do time -->
      <div class="confirm-banner">
        <div class="cb-stripe" id="confStripe"></div>
        <div class="cb-body">
          <div class="cb-badge" id="confBadge">—</div>
          <div class="cb-meta">
            <h2 id="confTeam">—</h2>
            <p>GM: <strong id="confGM">—</strong> · Save: <strong id="confSave">—</strong></p>
          </div>
        </div>
      </div>

      <!-- Grade de detalhes -->
      <div class="confirm-items">
        <div class="ci-box"><div class="ci-lbl">Era</div><div class="ci-val brand" id="confEra">—</div></div>
        <div class="ci-box"><div class="ci-lbl">Estilo</div><div class="ci-val" id="confStyle">—</div></div>
        <div class="ci-box"><div class="ci-lbl">Dificuldade</div><div class="ci-val" id="confDiff">—</div></div>
        <div class="ci-box"><div class="ci-lbl">Potencial</div><div class="ci-val" id="confPot">—</div></div>
      </div>

      <!-- Formulário hidden que submete -->
      <form method="post" action="<?= url('home', ['action' => 'create-save']) ?>" id="wizForm">
        <input type="hidden" name="slot"           id="h_slot" value="<?= min($free) ?>">
        <input type="hidden" name="era"            id="h_era"  value="">
        <input type="hidden" name="team"           id="h_team" value="">
        <input type="hidden" name="gm_name"        id="h_gm_name" value="">
        <input type="hidden" name="name"           id="h_save_name" value="">
        <input type="hidden" name="coach_style"    id="h_coach_style" value="equilibrado">
        <input type="hidden" name="difficulty"     id="h_difficulty" value="normal">
        <input type="hidden" name="potential_type" id="h_potential_type" value="real">

        <div class="wiz-nav">
          <button class="btn btn-lg" type="button" data-wiz-prev>← Editar</button>
          <button class="btn btn-primary btn-lg" type="submit"
                  onclick="return confirm('Criar a nova dinastia? Isso pode levar alguns segundos.')">
            🏀 Criar Dinastia
          </button>
        </div>
      </form>
    </div>

  </div><!-- /wizard-inner -->
</div><!-- /wizard-overlay -->
<?php endif; ?>

<script src="assets/js/app.js"></script>
<script src="assets/js/wizard.js"></script>
</body>
</html>
