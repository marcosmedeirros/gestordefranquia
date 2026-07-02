<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/PlayerFace.php';

render_header('Times da Liga');

$allTeams = array_filter(League::allTeams(), fn($t) => (int)$t['active']);
usort($allTeams, fn($a,$b) => $b['wins'] <=> $a['wins']);

// Time selecionado
$selId = (int)($_GET['id'] ?? 0);
if (!$selId && !empty($allTeams)) {
    $gmId = League::gmTeam();
    $selId = $gmId ?: (int)reset($allTeams)['id'];
}
$selTeam  = $selId ? League::team($selId) : null;
$selRoster= $selId ? League::rosterFull($selId) : [];
$selPicks  = $selId ? League::teamPicks($selId) : [];

// Busca global de jogador
$search = trim($_GET['q'] ?? '');
$searchResults = [];
if (strlen($search) >= 2) {
    $searchResults = League::searchPlayers($search);
}

// Separar por conferência
$east = array_filter($allTeams, fn($t) => $t['conf'] === 'E');
$west = array_filter($allTeams, fn($t) => $t['conf'] === 'W');

// Médias calculadas inline
$pAvg = fn($total, $gp, $dec=1) => ($gp > 0) ? number_format($total/$gp, $dec) : '—';
$fgPct= fn($m,$a) => ($a > 0) ? number_format($m/$a*100, 1).'%' : '—';

// Conf/seed
$standE = League::standings('E');
$standW = League::standings('W');
$seeds = [];
foreach(array_merge($standE, $standW) as $s) { $seeds[(int)$s['id']] = (int)$s['seed']; }
?>

<!-- Topbar com busca -->
<div class="teams-topbar">
  <h1 class="page-title" style="margin:0">🏢 Times da Liga</h1>
  <form method="get" class="teams-search-form">
    <input type="hidden" name="p" value="teams">
    <input type="hidden" name="id" value="<?= $selId ?>">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="🔍 Buscar jogador..."
           class="teams-search-input" autocomplete="off">
  </form>
</div>

<?php if ($search && $searchResults): ?>
<!-- Resultados de busca de jogador -->
<section class="card" style="margin-bottom:20px">
  <div class="card-head"><h2>🔍 Resultados para "<?= e($search) ?>"</h2>
    <a href="<?= url('teams', ['id'=>$selId]) ?>" class="btn" style="font-size:12px;padding:4px 10px">✕ Limpar</a>
  </div>
  <table class="box-table">
    <thead><tr><th></th><th>Jogador</th><th>Time</th><th>Pos</th><th>Idade</th><th>OVR</th><th>PPG</th><th>RPG</th><th>APG</th></tr></thead>
    <tbody>
    <?php foreach ($searchResults as $p):
      $gp = max(1,(int)$p['gp']);
      $ovrC = $p['ovr']>=90?'elite':($p['ovr']>=80?'star':($p['ovr']>=75?'good':'role'));
    ?>
    <tr>
      <td><img class="face-mini" src="<?= PlayerFace::url((int)$p['id'], $p['name'], $p['pos']) ?>" alt=""></td>
      <td class="bx-name"><a href="<?= url('player',['id'=>$p['id']]) ?>"><?= e($p['name']) ?></a></td>
      <td>
        <a href="<?= url('teams',['id'=>$p['team_id']]) ?>" style="display:flex;align-items:center;gap:6px">
          <?= team_logo($p['abbr'], $p['primary_color'], 'sm') ?>
          <span style="font-size:12px;color:var(--muted)"><?= e($p['abbr']) ?></span>
        </a>
      </td>
      <td><?= e($p['pos']) ?></td>
      <td class="num"><?= $p['age'] ?></td>
      <td class="num"><span class="ovr ovr-<?= $ovrC ?>"><?= $p['ovr'] ?></span></td>
      <td class="num"><?= $pAvg($p['s_pts']??0,$p['gp']??0) ?></td>
      <td class="num"><?= $pAvg($p['s_reb']??0,$p['gp']??0) ?></td>
      <td class="num"><?= $pAvg($p['s_ast']??0,$p['gp']??0) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<div class="teams-layout">

  <!-- ═══ LEFT: lista de times ═══ -->
  <div class="teams-sidebar">

    <?php foreach ([['E','🔵 Conferência Leste',$east],['W','🔴 Conferência Oeste',$west]] as [$conf,$label,$group]): ?>
    <div class="ts-conf-header"><?= $label ?></div>
    <?php foreach ($group as $t):
      $isActive = (int)$t['id'] === $selId;
      $g = (int)$t['wins'] + (int)$t['losses'];
      $pct = $g ? number_format($t['wins']/$g*100, 0).'%' : '—';
      $seed = $seeds[(int)$t['id']] ?? null;
    ?>
    <a href="<?= url('teams',['id'=>$t['id']]) ?>"
       class="ts-team-row <?= $isActive ? 'active' : '' ?>">
      <?= team_logo($t['abbr'], $t['primary_color'], 'sm') ?>
      <div class="tsr-info">
        <span class="tsr-name"><?= e($t['abbr']) ?></span>
        <span class="tsr-full"><?= e(teamFull($t)) ?></span>
      </div>
      <div class="tsr-record">
        <span class="tsr-wl"><?= (int)$t['wins'] ?>-<?= (int)$t['losses'] ?></span>
        <?php if ($seed): ?><span class="tsr-seed">#<?= $seed ?></span><?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
    <?php endforeach; ?>

  </div><!-- /teams-sidebar -->

  <!-- ═══ RIGHT: elenco do time selecionado ═══ -->
  <div class="teams-main">
  <?php if ($selTeam): ?>

    <!-- Team hero -->
    <div class="team-hero-v2" style="background:linear-gradient(135deg,<?= e($selTeam['primary_color']) ?>,<?= e($selTeam['secondary_color']??$selTeam['primary_color']) ?>99);margin-bottom:18px">
      <?= team_logo($selTeam['abbr'], $selTeam['primary_color'], 'xl', 'th-logo') ?>
      <div class="th-body">
        <h1><?= e(teamFull($selTeam)) ?></h1>
        <p class="th-meta">
          <?= $selTeam['conf']==='E'?'Conf. Leste':'Conf. Oeste' ?> · <?= e($selTeam['div']) ?>
          · <strong style="font-size:18px"><?= $selTeam['wins'] ?>-<?= $selTeam['losses'] ?></strong>
          · Folha <?= money(League::teamPayroll($selId)) ?>
          · Química <?= (int)$selTeam['chemistry'] ?>
        </p>
        <p class="th-sub">
          ⚔️ <?= e($selTeam['scheme_off']??'—') ?> · 🛡️ <?= e($selTeam['scheme_def']??'—') ?>
          &nbsp;·&nbsp;
          <a href="<?= url('team',['id'=>$selId]) ?>" style="color:rgba(255,255,255,.8)">Ver página do time →</a>
        </p>
      </div>
    </div>

    <!-- Tabela de jogadores -->
    <?php
    // Colunas de stats ordenáveis (padrão: OVR)
    $sortCol = $_GET['sort'] ?? 'ovr';
    $sortDir = $_GET['dir']  ?? 'desc';
    $validSort = ['ovr','age','gp','ppg','rpg','apg','spg','bpg','fgp'];
    if (!in_array($sortCol, $validSort)) $sortCol = 'ovr';

    // Enriquece com médias calculadas
    foreach ($selRoster as &$p) {
        $gp = max(1,(int)$p['gp']);
        $p['_ppg'] = $p['gp'] ? round($p['s_pts']/$gp,1)  : 0;
        $p['_rpg'] = $p['gp'] ? round($p['s_reb']/$gp,1)  : 0;
        $p['_apg'] = $p['gp'] ? round($p['s_ast']/$gp,1)  : 0;
        $p['_spg'] = $p['gp'] ? round($p['s_stl']/$gp,1)  : 0;
        $p['_bpg'] = $p['gp'] ? round($p['s_blk']/$gp,1)  : 0;
        $p['_fgp'] = $p['s_fga'] ? round($p['s_fgm']/$p['s_fga']*100,1) : 0;
    }
    unset($p);

    // Sort
    $colKey = [
        'ovr'=>'ovr','age'=>'age','gp'=>'gp','ppg'=>'_ppg','rpg'=>'_rpg',
        'apg'=>'_apg','spg'=>'_spg','bpg'=>'_bpg','fgp'=>'_fgp',
    ];
    $key = $colKey[$sortCol] ?? 'ovr';
    usort($selRoster, function($a,$b) use($key, $sortDir) {
        $av = is_numeric($a[$key]) ? (float)$a[$key] : 0;
        $bv = is_numeric($b[$key]) ? (float)$b[$key] : 0;
        return $sortDir === 'asc' ? $av <=> $bv : $bv <=> $av;
    });

    // Helper para link de coluna sortável
    $th = function(string $label, string $col) use($selId, $sortCol, $sortDir, $search) {
        $newDir = ($sortCol === $col && $sortDir === 'desc') ? 'asc' : 'desc';
        $active = $sortCol === $col;
        $arrow  = $active ? ($sortDir==='desc'?'↓':'↑') : '';
        $params = ['id'=>$selId,'sort'=>$col,'dir'=>$newDir];
        if ($search) $params['q'] = $search;
        return '<th class="sort-th'.($active?' sort-active':'').'" onclick="location.href=\''.url('teams',$params).'\'">'
              .$label.' <span class="sort-arrow">'.$arrow.'</span></th>';
    };
    ?>

    <section class="card" style="margin-bottom:16px">
      <div class="card-head">
        <h2>📋 Elenco <span class="muted" style="font-size:14px;font-weight:400"><?= count($selRoster) ?> jogadores</span></h2>
        <div style="display:flex;gap:6px;align-items:center">
          <span class="pill">Clique no cabeçalho para ordenar</span>
        </div>
      </div>
      <div class="table-scroll">
      <table class="box-table roster-table teams-roster-table">
        <thead><tr>
          <th style="width:44px"></th>
          <th style="min-width:160px">Jogador</th>
          <th>Pos</th>
          <?= $th('Idade','age') ?>
          <?= $th('OVR','ovr') ?>
          <th>Pot</th>
          <?= $th('JG','gp') ?>
          <?= $th('PPG','ppg') ?>
          <?= $th('RPG','rpg') ?>
          <?= $th('APG','apg') ?>
          <?= $th('SPG','spg') ?>
          <?= $th('BPG','bpg') ?>
          <?= $th('FG%','fgp') ?>
        </tr></thead>
        <tbody>
        <?php foreach ($selRoster as $p):
          $gp   = (int)$p['gp'];
          $inj  = (int)($p['injury_games']??0);
          $ovrC = $p['ovr']>=90?'elite':($p['ovr']>=80?'star':($p['ovr']>=75?'good':'role'));
          $nbaId= (int)($p['nba_id']??0);
          $pid  = (int)$p['id'];
        ?>
        <tr class="<?= $p['is_starter']?'starter':'' ?> <?= $inj?'inj-row':'' ?>">
          <td style="padding:4px 6px">
            <?= player_photo($nbaId, $p['name'], $selTeam['primary_color'], 'sm', 'face-mini-round', $pid, $p['pos']) ?>
          </td>
          <td class="bx-name">
            <a href="<?= url('player',['id'=>$pid]) ?>"><?= e($p['name']) ?></a>
            <?php if ($p['is_starter']): ?><span class="tag">T</span><?php endif; ?>
            <?php if ((int)($p['seasons_pro']??1) === 0): ?><span class="tag" style="background:#7b2ff7">R</span><?php endif; ?>
            <?php if ($inj): ?><span class="badge-inj">🩹<?= $inj ?>j</span><?php endif; ?>
          </td>
          <td class="num"><?= e($p['pos']) ?></td>
          <td class="num"><?= $p['age'] ?></td>
          <td class="num"><span class="ovr ovr-<?= $ovrC ?>"><?= $p['ovr'] ?></span></td>
          <td class="num"><?= (int)($p['potential']??0) > (int)$p['ovr'] ? '<span class="muted">'.(int)$p['potential'].'</span>' : '—' ?></td>
          <td class="num"><?= $gp ?: '—' ?></td>
          <td class="num <?= $p['_ppg'] >= 20 ? 'stat-hot' : ($p['_ppg'] >= 12 ? 'stat-good' : '') ?>"><?= $gp ? $p['_ppg'] : '—' ?></td>
          <td class="num <?= $p['_rpg'] >= 10 ? 'stat-hot' : ($p['_rpg'] >= 7 ? 'stat-good' : '') ?>"><?= $gp ? $p['_rpg'] : '—' ?></td>
          <td class="num <?= $p['_apg'] >= 7 ? 'stat-hot' : ($p['_apg'] >= 4 ? 'stat-good' : '') ?>"><?= $gp ? $p['_apg'] : '—' ?></td>
          <td class="num"><?= $gp ? $p['_spg'] : '—' ?></td>
          <td class="num"><?= $gp ? $p['_bpg'] : '—' ?></td>
          <td class="num"><?= $gp && $p['s_fga'] ? $p['_fgp'].'%' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div><!-- /table-scroll -->
    </section>

    <!-- Draft Picks -->
    <?php if ($selPicks): ?>
    <section class="card" style="margin-bottom:16px">
      <div class="card-head"><h2>🎯 Draft Picks futuras</h2></div>
      <div class="picks-list">
        <?php foreach ($selPicks as $pk):
          $own = (int)$pk['original_team_id'] === (int)$pk['owner_team_id']; ?>
          <span class="pick-chip <?= (int)$pk['round']===1?'pick-r1':'pick-r2' ?>">
            R<?= (int)$pk['round'] ?> · <?= League::draftYearLabel((int)$pk['year']) ?>
            <?php if (!$own): ?><span class="muted"> (via <?= e($pk['orig_abbr']) ?>)</span><?php endif; ?>
          </span>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <!-- Resumo rápido do time -->
    <section class="card">
      <div class="card-head"><h2>📊 Resumo da Temporada</h2></div>
      <?php
      $totalGp  = max(1, $selTeam['wins'] + $selTeam['losses']);
      // Calcular médias do time somando de todos os jogadores
      $teamPts = $teamReb = $teamAst = $teamStl = $teamBlk = $teamFgm = $teamFga = 0;
      foreach ($selRoster as $p) {
          $teamPts += (int)$p['s_pts'];
          $teamReb += (int)$p['s_reb'];
          $teamAst += (int)$p['s_ast'];
          $teamStl += (int)$p['s_stl'];
          $teamBlk += (int)$p['s_blk'];
          $teamFgm += (int)$p['s_fgm'];
          $teamFga += (int)$p['s_fga'];
      }
      $avgOvr = $selRoster ? round(array_sum(array_column($selRoster, 'ovr')) / count($selRoster), 1) : 0;
      ?>
      <div class="team-summary-grid">
        <div class="tsm-item">
          <span class="tsm-val"><?= number_format($teamPts/$totalGp, 1) ?></span>
          <span class="tsm-lbl">PPG Time</span>
        </div>
        <div class="tsm-item">
          <span class="tsm-val"><?= number_format($teamReb/$totalGp, 1) ?></span>
          <span class="tsm-lbl">RPG Time</span>
        </div>
        <div class="tsm-item">
          <span class="tsm-val"><?= number_format($teamAst/$totalGp, 1) ?></span>
          <span class="tsm-lbl">APG Time</span>
        </div>
        <div class="tsm-item">
          <span class="tsm-val"><?= number_format($teamStl/$totalGp, 1) ?></span>
          <span class="tsm-lbl">SPG Time</span>
        </div>
        <div class="tsm-item">
          <span class="tsm-val"><?= number_format($teamBlk/$totalGp, 1) ?></span>
          <span class="tsm-lbl">BPG Time</span>
        </div>
        <div class="tsm-item">
          <span class="tsm-val"><?= $teamFga ? number_format($teamFgm/$teamFga*100, 1).'%' : '—' ?></span>
          <span class="tsm-lbl">FG% Time</span>
        </div>
        <div class="tsm-item">
          <span class="tsm-val"><?= $avgOvr ?></span>
          <span class="tsm-lbl">OVR Médio</span>
        </div>
        <div class="tsm-item">
          <span class="tsm-val"><?= money(League::teamPayroll($selId)) ?></span>
          <span class="tsm-lbl">Folha Salarial</span>
        </div>
      </div>
    </section>

  <?php else: ?>
    <div class="card" style="padding:40px;text-align:center;color:var(--muted)">Selecione um time na lista.</div>
  <?php endif; ?>
  </div><!-- /teams-main -->

</div><!-- /teams-layout -->

<?php render_footer(); ?>
