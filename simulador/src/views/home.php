<?php
require_once dirname(__DIR__) . '/helpers.php';
$phase = League::phase();
$day = League::currentDay();
$season  = League::season();
$eraName = Database::meta('era_name');
$phaseLabel = ['regular'=>'Temporada Regular','playin'=>'Play-In','playoffs'=>'Playoffs',
               'lottery'=>'Loteria','draft'=>'Draft','freeagency'=>'Free Agency','offseason'=>'Off-season'][$phase] ?? ucfirst($phase);
$east = League::standings('E');
$west = League::standings('W');
$ppgLeaders = League::leaders('pts', 5);
$todays = ($phase === 'offseason') ? [] : League::gamesByDay($day);
$awardRace = ($phase === 'regular' && $day >= 10) ? League::awardRace() : null;
$champ = null;
if ($phase === 'offseason' && Database::meta('champion_id')) {
    $champ = League::team((int) Database::meta('champion_id'));
}
$goal = League::boardGoalProgress();
$headlines = League::headlines(6);
$power = League::powerRankings();
$gmId = League::gmTeam();
$gmToday = null;
if ($gmId && in_array($phase, ['regular','playin','playoffs'])) {
    $gg = League::gmGameOnDay($day);
    if ($gg && !$gg['played']) $gmToday = $gg;
}

// ── DC dashboard data ──
$gmTeamData    = $gmId ? League::team($gmId) : null;
$gmTopPerf     = [];
$gmRecentForm  = [];
$gmPPG         = 0.0;
$gmStreak      = '';
$gmNextGame    = null;
$gmHomeW = $gmHomeL = $gmAwayW = $gmAwayL = 0;
if ($gmId && $gmTeamData && in_array($phase, ['regular','playin','playoffs'])) {
    // Top performers
    $roster = League::roster($gmId);
    $perf = array_values(array_filter($roster, fn($p) => (int)$p['gp'] > 0));
    usort($perf, fn($a, $b) => ($b['s_pts']/$b['gp']) <=> ($a['s_pts']/$a['gp']));
    $gmTopPerf = array_slice($perf, 0, 4);

    $db = Database::conn();

    // Last 5 games for form + home/away split
    $st = $db->prepare(
        "SELECT CASE WHEN home_id=:t THEN home_pts ELSE away_pts END AS my_pts,
                CASE WHEN home_id=:t THEN away_pts ELSE home_pts END AS op_pts,
                CASE WHEN home_id=:t THEN 1 ELSE 0 END AS is_home
         FROM games WHERE (home_id=:t OR away_id=:t) AND played=1
         ORDER BY day DESC LIMIT 5"
    );
    $st->execute([':t' => $gmId]);
    $gmRecentForm = array_reverse($st->fetchAll());
    foreach ($gmRecentForm as $g) {
        $w = (int)$g['my_pts'] > (int)$g['op_pts'];
        if ($g['is_home']) { $w ? $gmHomeW++ : $gmHomeL++; }
        else               { $w ? $gmAwayW++ : $gmAwayL++; }
    }

    // Streak from recent form
    if ($gmRecentForm) {
        $rev  = array_reverse($gmRecentForm);
        $lw   = (int)$rev[0]['my_pts'] > (int)$rev[0]['op_pts'];
        $cnt  = 0;
        foreach ($rev as $g) { if (((int)$g['my_pts'] > (int)$g['op_pts']) === $lw) $cnt++; else break; }
        $gmStreak = ($lw ? 'V' : 'D') . $cnt;
    }

    // Team PPG
    $r = $db->prepare("SELECT AVG(CASE WHEN home_id=:t THEN home_pts ELSE away_pts END) AS ppg FROM games WHERE (home_id=:t OR away_id=:t) AND played=1");
    $r->execute([':t' => $gmId]);
    $gmPPG = round((float)(($r->fetch())['ppg'] ?? 0), 1);

    // Next unplayed game
    if (!$gmToday) {
        $ns = $db->prepare(
            "SELECT g.*, at.abbr AS away_abbr, ht.abbr AS home_abbr
             FROM games g JOIN teams at ON at.id=g.away_id JOIN teams ht ON ht.id=g.home_id
             WHERE (g.home_id=:t OR g.away_id=:t) AND g.played=0 ORDER BY g.day ASC LIMIT 1"
        );
        $ns->execute([':t' => $gmId]);
        $gmNextGame = $ns->fetch() ?: null;
    }
}

// ── Técnico / GM ──
$gmCoach = ($gmId && in_array($phase, ['regular','playin','playoffs','offseason'])) ? League::gmCoach() : null;

// ── Narrativa: manchetes relacionadas ao MEU time ──
$gmStorylines = [];
if ($gmId && $gmTeamData) {
    $abbr = $gmTeamData['abbr'] ?? '';
    foreach (League::headlines(20) as $h) {
        if ($abbr && (stripos($h['text'], $abbr) !== false
            || stripos($h['text'], $gmTeamData['name'] ?? '###') !== false
            || (int)($h['team_id'] ?? 0) === $gmId)) {
            $gmStorylines[] = $h['text'];
        }
        if (count($gmStorylines) >= 4) break;
    }
    // storylines geradas a partir do estado do time
    if ($gmStreak && (int)substr($gmStreak,1) >= 3) {
        $tipo = substr($gmStreak,0,1) === 'V' ? 'embala com' : 'tropeça em';
        array_unshift($gmStorylines, ($gmTeamData['city'] ?? '').' '.($gmTeamData['name'] ?? '').' '.$tipo.' '.substr($gmStreak,1).' jogos seguidos.');
    }
    if ($gmTopPerf) {
        $star = $gmTopPerf[0];
        $gmStorylines[] = e($star['name']).' lidera o time com '.avg($star['s_pts']??0, $star['gp']??1).' pts/jogo.';
    }
    $gmStorylines = array_slice($gmStorylines, 0, 4);
}

// ---- Ação central única (CTA) conforme a fase ----
$cta = League::nextAction();

render_header('Início');
?>
<?php if ($champ): ?>
<div class="champion-banner" style="<?= gradient($champ) ?>">
  🏆 Campeão da Temporada <?= League::season() ?>: <strong><?= e(teamFull($champ)) ?></strong>
</div>
<?php endif; ?>

<?php if (!empty($_GET['dmsg'])): ?><div class="injury-note" style="background:#10371f;border-color:#1f6b3a;color:#9bffc0"><?= e($_GET['dmsg']) ?></div><?php endif; ?>

<?php $gmHero = $gmId && $gmTeamData && in_array($phase, ['regular','playin','playoffs']); ?>

<?php if ($gmHero):
  $g2 = (int)$gmTeamData['wins'] + (int)$gmTeamData['losses'];
  $pctStr = $g2 ? number_format($gmTeamData['wins'] / $g2, 3) : '.000';
  // seed na conferência
  $mySeed = null;
  foreach (League::standings($gmTeamData['conf']) as $s) { if ((int)$s['id'] === $gmId) { $mySeed = $s['seed']; break; } }
  $coachStyleLabels = ['equilibrado'=>'Equilibrado','ofensivo'=>'Ofensivo','defensivo'=>'Defensivo','desenvolvimento'=>'Desenvolvedor','gestao'=>'Gestor'];
?>
<!-- ═══════════ GM COMMAND CENTER ═══════════ -->
<section class="gm-hero">
  <div class="gm-hero-bg"></div>
  <div class="gm-hero-inner">

    <!-- Identidade + treinador -->
    <div class="gmh-identity">
      <div class="gmh-logo"><?= team_logo($gmTeamData['abbr'], $gmTeamData['primary_color'], 'lg') ?></div>
      <div class="gmh-id-txt">
        <div class="gmh-team"><?= e(($gmTeamData['city']??'').' '.($gmTeamData['name']??'')) ?></div>
        <div class="gmh-sub"><?= e($eraName ?: 'Era Atual') ?> · Temporada <?= $season ?> · <?= e($phaseLabel) ?></div>
        <?php if ($gmCoach): ?>
        <div class="gmh-coach">
          <span class="gmh-coach-ava"><?= strtoupper(substr($gmCoach['name'] ?: 'T', 0, 1)) ?></span>
          <div>
            <div class="gmh-coach-name"><?= e($gmCoach['name'] ?: 'Técnico') ?> <span class="gmh-coach-role">· Gerente Geral</span></div>
            <div class="gmh-coach-attrs">
              <span title="Estilo"><?= e($coachStyleLabels[$gmCoach['style']] ?? ucfirst($gmCoach['style'])) ?></span>
              <span>OFE <strong><?= (int)$gmCoach['ofensivo'] ?></strong></span>
              <span>DEF <strong><?= (int)$gmCoach['defensivo'] ?></strong></span>
              <span>DES <strong><?= (int)$gmCoach['desenvolvimento'] ?></strong></span>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Stats grandes -->
    <div class="gmh-stats">
      <div class="gmh-stat">
        <div class="gmh-stat-num"><?= (int)$gmTeamData['wins'] ?>-<?= (int)$gmTeamData['losses'] ?></div>
        <div class="gmh-stat-lbl"><?= $pctStr ?> · <?= $mySeed ? $mySeed.'º '.($gmTeamData['conf']==='E'?'Leste':'Oeste') : ($gmTeamData['conf']==='E'?'Leste':'Oeste') ?></div>
      </div>
      <div class="gmh-stat">
        <div class="gmh-stat-num <?= $gmStreak && substr($gmStreak,0,1)==='V' ? 'pos' : ($gmStreak ? 'neg' : '') ?>"><?= $gmStreak ?: '—' ?></div>
        <div class="gmh-stat-lbl">sequência</div>
      </div>
      <div class="gmh-stat">
        <div class="gmh-stat-num"><?= $gmPPG > 0 ? $gmPPG : '—' ?></div>
        <div class="gmh-stat-lbl">pontos/jogo</div>
      </div>
    </div>

    <!-- Missão -->
    <?php if ($goal): ?>
    <div class="gmh-mission goal-<?= $goal['status'] ?>">
      <div class="gmh-mission-label">🎯 Missão da Temporada</div>
      <div class="gmh-mission-desc"><?= e($goal['desc']) ?></div>
      <div class="gmh-mission-detail"><?= e($goal['detail']) ?>
        <span class="gmh-mission-badge"><?= ['andamento'=>'em andamento','cumprida'=>'✅ cumprida','falhou'=>'❌ não cumprida'][$goal['status']] ?></span>
      </div>
    </div>
    <?php endif; ?>

    <!-- Próximo passo (CTA) -->
    <?php if ($cta): ?>
    <div class="gmh-next">
      <a class="gmh-next-btn" href="<?= e($cta['href']) ?>"
         <?= isset($cta['confirm']) ? 'onclick="return confirm(\''.e($cta['confirm']).'\')"' : '' ?>>
        <span class="gmh-next-kicker">▶ Próximo passo</span>
        <span class="gmh-next-label"><?= e($cta['label']) ?></span>
      </a>
      <?php if (!empty($cta['alt'])): ?>
        <a class="gmh-next-alt" href="<?= e($cta['alt']['href']) ?>" onclick="return confirm('<?= e($cta['alt']['confirm']) ?>')"><?= e($cta['alt']['label']) ?></a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
</section>

<!-- ═══════════ CAIXA DE ENTRADA DO GM (fonte única: League::inboxList) ═══════════ -->
<?php
  $inboxItems = League::inboxList(6);
  // "Jogo de hoje" é um lembrete efêmero (muda todo dia) — não fica salvo na caixa,
  // só é injetado no topo da prévia enquanto for válido.
  if ($gmToday) {
      array_unshift($inboxItems, [
          'kind' => 'game', 'icon' => '🎮', 'sender' => 'Sala de Comando',
          'title' => 'Jogo hoje: ' . $gmToday['away_abbr'] . ' @ ' . $gmToday['home_abbr'],
          'body' => 'Comande sua equipe ao vivo ou simule a partida.',
          'link' => url('game', ['id' => $gmToday['id'], 'live' => 1]),
          'ref_id' => 0, 'urgent' => true,
      ]);
  }
  $inboxItems = array_slice($inboxItems, 0, 6);
  $inboxUnreadTotal = League::inboxUnread();
?>
<section class="card gm-inbox">
  <div class="card-head">
    <h2>📬 Caixa de Entrada<?= $inboxUnreadTotal ? ' <span class="inbox-badge">' . $inboxUnreadTotal . '</span>' : '' ?></h2>
    <a class="link-more" href="<?= url('inbox') ?>">Ver todas →</a>
  </div>
  <?php if (!$inboxItems): ?>
    <p class="muted" style="font-size:11px">Nenhuma mensagem nova. Avance as datas para movimentar a liga.</p>
  <?php else: ?>
  <div class="inbox-list">
    <?php foreach ($inboxItems as $m): render_inbox_msg($m, 'home'); endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<!-- ═══════════ 3-COL: PERFORMERS · FORMA · NARRATIVA ═══════════ -->
<div class="dc-dash-mid">
  <!-- Top Performers -->
  <div class="dc-mid-card">
    <div class="dc-mid-label">⭐ Destaques do Elenco</div>
    <?php foreach ($gmTopPerf as $p): ?>
      <div class="dc-perf-row">
        <div>
          <div class="dc-pr-name"><a href="<?= url('player',['id'=>$p['id']]) ?>"><?= e($p['name']) ?></a></div>
          <div class="dc-pr-pos"><?= e($p['pos']) ?> · OVR <?= (int)$p['ovr'] ?></div>
        </div>
        <div style="text-align:right">
          <div class="dc-pr-stat"><?= avg($p['s_pts']??0, $p['gp']??1) ?></div>
          <div class="dc-pr-lbl">PPG</div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$gmTopPerf): ?>
      <p class="muted" style="font-size:10px">Jogue alguns jogos para ver as stats.</p>
    <?php endif; ?>
  </div>

  <!-- Forma Recente -->
  <div class="dc-mid-card">
    <div class="dc-mid-label">📈 Forma Recente</div>
    <div class="dc-form-row">
      <?php if ($gmRecentForm): ?>
        <?php foreach ($gmRecentForm as $g): $win = (int)$g['my_pts'] > (int)$g['op_pts']; ?>
          <div class="dc-fb <?= $win ? 'dc-fb-w' : 'dc-fb-l' ?>"><?= $win ? 'V' : 'D' ?></div>
        <?php endforeach; ?>
      <?php else: ?>
        <span class="muted" style="font-size:10px">Sem jogos ainda.</span>
      <?php endif; ?>
    </div>
    <div class="dc-form-stats">
      <span>Casa: <?= $gmHomeW ?>-<?= $gmHomeL ?> | Fora: <?= $gmAwayW ?>-<?= $gmAwayL ?></span>
      <?php if ($gmPPG > 0): ?><span><?= $gmPPG ?> PPG de média</span><?php endif; ?>
    </div>
  </div>

  <!-- Narrativa da temporada -->
  <div class="dc-mid-card">
    <div class="dc-mid-label">📰 Sua Temporada</div>
    <?php if ($gmStorylines): ?>
      <?php foreach ($gmStorylines as $story): ?>
        <div class="dc-story"><span class="dc-story-bar"></span><span><?= $story ?></span></div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="muted" style="font-size:10px">A história da sua temporada será escrita a cada jogo.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; /* fim gmHero */ ?>

<?php /* CTA + meta para saves SEM hero (não-GM ou fora de temporada ativa) */ ?>
<?php if (!$gmHero && $cta): ?>
<section class="cta-card">
  <div class="cta-info"><span class="cta-note"><?= e($cta['note']) ?></span></div>
  <a class="btn btn-primary btn-lg cta-btn" href="<?= e($cta['href']) ?>"
     <?= isset($cta['confirm']) ? 'onclick="return confirm(\''.e($cta['confirm']).'\')"' : '' ?>><?= e($cta['label']) ?></a>
</section>
<?php endif; ?>

<?php if (!$gmHero && $gmId) render_decisions(League::pendingDecisions(), url('home')); ?>

<?php if (!$gmHero && $goal): ?>
<div class="board-goal goal-<?= $goal['status'] ?>">
  🎯 <strong>Meta da diretoria:</strong> <?= e($goal['desc']) ?>
  <span class="goal-detail"><?= e($goal['detail']) ?></span>
  <span class="goal-badge"><?= ['andamento'=>'em andamento','cumprida'=>'✅ cumprida','falhou'=>'❌ não cumprida'][$goal['status']] ?></span>
</div>
<?php endif; ?>

<?php if ($phase === 'offseason'):
  $aw = League::awards(League::season());
  $byType = [];
  foreach ($aw as $a) { $byType[$a['type']][] = $a; }
  $main = ['MVP' => '🏅 MVP', 'Finals MVP' => '🏆 MVP das Finais', 'DPOY' => '🛡️ Defensor do Ano', 'ROY' => '🌟 Novato do Ano'];
?>
<section class="card span2">
  <div class="card-head"><h2>Premiações da Temporada <?= League::season() ?></h2>
    <a class="link-more" href="<?= url('history') ?>">Histórico completo →</a></div>
  <div class="awards-row">
    <?php foreach ($main as $type => $label): if (empty($byType[$type])) continue; $a = $byType[$type][0]; ?>
      <div class="award-card">
        <div class="award-label"><?= $label ?></div>
        <a class="award-name" href="<?= url('player',['id'=>$a['player_id']]) ?>"><?= e($a['player_name']) ?></a>
        <div class="award-meta"><?= e($a['abbr']) ?> · <?= e($a['value']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php /* ===== CORRIDA PELOS PRÊMIOS ===== */
if ($awardRace): ?>
<section class="card award-race-card">
  <div class="card-head">
    <h2>🏅 Corrida pelos Prêmios</h2>
    <span class="muted" style="font-size:12px">dia <?= $day ?>/82</span>
  </div>
  <div class="ar-grid">

    <div>
      <div class="ar-cat-title"><span class="ar-trophy">🏅</span> MVP</div>
      <?php if ($awardRace['mvp']): ?>
        <?php foreach ($awardRace['mvp'] as $i => $p): ?>
        <div class="ar-row">
          <span class="ar-rank <?= $i===0?'gold':'' ?>"><?= $i+1 ?></span>
          <span class="ar-name"><a href="<?= url('player',['id'=>$p['id']]) ?>"><?= e($p['name']) ?></a>
            <span class="ar-abbr"><?= e($p['abbr']) ?></span></span>
          <span class="ar-val"><?= number_format((float)$p['ppg'],1) ?> pts</span>
        </div>
        <?php endforeach; ?>
      <?php else: ?><div class="ar-empty">Jogos insuficientes.</div><?php endif; ?>
    </div>

    <div>
      <div class="ar-cat-title"><span class="ar-trophy">🛡️</span> DPOY</div>
      <?php if ($awardRace['dpoy']): ?>
        <?php foreach ($awardRace['dpoy'] as $i => $p): ?>
        <div class="ar-row">
          <span class="ar-rank <?= $i===0?'gold':'' ?>"><?= $i+1 ?></span>
          <span class="ar-name"><a href="<?= url('player',['id'=>$p['id']]) ?>"><?= e($p['name']) ?></a>
            <span class="ar-abbr"><?= e($p['abbr']) ?></span></span>
          <span class="ar-val"><?= number_format((float)$p['bpg'],1) ?>b+<?= number_format((float)$p['spg'],1) ?>s</span>
        </div>
        <?php endforeach; ?>
      <?php else: ?><div class="ar-empty">Jogos insuficientes.</div><?php endif; ?>
    </div>

    <div>
      <div class="ar-cat-title"><span class="ar-trophy">🌟</span> ROY</div>
      <?php if ($awardRace['roy']): ?>
        <?php foreach ($awardRace['roy'] as $i => $p): ?>
        <div class="ar-row">
          <span class="ar-rank <?= $i===0?'gold':'' ?>"><?= $i+1 ?></span>
          <span class="ar-name"><a href="<?= url('player',['id'=>$p['id']]) ?>"><?= e($p['name']) ?></a>
            <span class="ar-abbr"><?= e($p['abbr']) ?></span></span>
          <span class="ar-val"><?= number_format((float)$p['ppg'],1) ?> pts</span>
        </div>
        <?php endforeach; ?>
      <?php else: ?><div class="ar-empty">Nenhum calouro com jogos.</div><?php endif; ?>
    </div>

  </div>
</section>
<?php endif; ?>

<?php /* ===== MANCHETES EM DESTAQUE ===== */ ?>
<section class="card news-card">
  <div class="card-head"><h2>📰 Manchetes da Liga</h2><a class="link-more" href="<?= url('history') ?>">Histórico →</a></div>
  <?php if (!$headlines): ?>
    <p class="muted">Avance as datas para gerar notícias da liga.</p>
  <?php else: ?>
  <div class="news-feature">
    <?php foreach ($headlines as $h): ?>
      <div class="nf-item"><span class="nf-bar"></span><span class="nf-text"><?= e($h['text']) ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<div class="dashboard">
  <section class="card span2">
    <div class="card-head">
      <h2><?= $phase === 'playoffs' ? 'Playoffs — ' . e(League::dateLabel($day)) : ($phase === 'offseason' ? 'Off-season' : 'Jogos · ' . e(League::dateLabel($day))) ?></h2>
      <a class="link-more" href="<?= url('schedule') ?>">Ver calendário →</a>
    </div>
    <?php if (!$todays): ?>
      <p class="muted">Nenhum jogo agendado. <?= $phase === 'playoffs' ? 'Avance para gerar a próxima leva de jogos.' : '' ?></p>
    <?php else: ?>
    <div class="games-grid">
      <?php foreach ($todays as $g):
        $isGmGame = $gmId && ((int)$g['home_id'] === $gmId || (int)$g['away_id'] === $gmId);
        $awayW = $g['played'] && $g['away_pts'] > $g['home_pts'];
        $homeW = $g['played'] && $g['home_pts'] > $g['away_pts'];
      ?>
        <a class="game-card <?= $g['played'] ? 'played' : '' ?> <?= $isGmGame ? 'gm-game' : '' ?>"
           href="<?= url('game', ['id' => $g['id']]) ?>">
          <?php if ($isGmGame): ?><span class="gc-mine">MEU JOGO</span><?php endif; ?>
          <div class="gc-teams">
            <div class="gc-side">
              <img src="<?= e(logo_url($g['away_abbr'])) ?>" style="width:38px;height:38px;object-fit:contain" alt="<?= e($g['away_abbr']) ?>">
              <span class="gc-abbr"><?= e($g['away_abbr']) ?></span>
              <?php if ($g['played']): ?><span class="gc-pts <?= $awayW?'winner':'loser' ?>"><?= $g['away_pts'] ?></span><?php endif; ?>
            </div>
            <div class="gc-sep">
              <?= $g['played'] ? 'FIN' : '@' ?>
              <?php if ($g['ot']): ?><br><span class="ot-tag">PR<?= $g['ot']>1?$g['ot']:'' ?></span><?php endif; ?>
            </div>
            <div class="gc-side">
              <img src="<?= e(logo_url($g['home_abbr'])) ?>" style="width:38px;height:38px;object-fit:contain" alt="<?= e($g['home_abbr']) ?>">
              <span class="gc-abbr"><?= e($g['home_abbr']) ?></span>
              <?php if ($g['played']): ?><span class="gc-pts <?= $homeW?'winner':'loser' ?>"><?= $g['home_pts'] ?></span><?php endif; ?>
            </div>
          </div>
          <div class="gc-status <?= !$g['played']?'pending':'' ?>">
            <?= $g['played'] ? 'Ver box score' : ($isGmGame ? '🎮 Comandar' : 'Simular') ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
</div>

<div class="dashboard">
  <section class="card">
    <div class="card-head"><h2>🔥 Líderes de pontos</h2><a class="link-more" href="<?= url('leaders') ?>">Todos →</a></div>
    <table class="mini-table">
      <?php foreach ($ppgLeaders as $l): ?>
        <tr>
          <td><a href="<?= url('player', ['id' => $l['id']]) ?>"><?= e($l['name']) ?></a> <span class="muted"><?= e($l['abbr']) ?></span></td>
          <td class="num"><strong><?= e($l['avg']) ?></strong> pts</td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$ppgLeaders): ?><tr><td class="muted">Avance as datas para ver os líderes.</td></tr><?php endif; ?>
    </table>
  </section>
  <section class="card">
    <div class="card-head"><h2>📊 Power Rankings — Top 5</h2><a class="link-more" href="<?= url('power') ?>">Ver tudo →</a></div>
    <table class="mini-table ranked">
      <?php foreach (array_slice($power, 0, 5) as $t): ?>
        <tr><td class="rank"><?= $t['rank'] ?></td>
            <td><span class="dot" style="background:<?= e($t['primary_color']) ?>"></span><?= e($t['city'].' '.$t['name']) ?>
                <span class="muted"><?= $t['wins'] ?>-<?= $t['losses'] ?></span></td>
            <td class="num"><strong><?= number_format($t['power'],1) ?></strong></td></tr>
      <?php endforeach; ?>
    </table>
  </section>
</div>

<div class="dashboard dash-2">
  <section class="card">
    <div class="card-head"><h2>Conferência Leste</h2></div>
    <?php include __DIR__ . '/_standings_table.php'; renderStandings(array_slice($east, 0, 8), true); ?>
    <a class="link-more" href="<?= url('standings') ?>">Tabela completa →</a>
  </section>
  <section class="card">
    <div class="card-head"><h2>Conferência Oeste</h2></div>
    <?php renderStandings(array_slice($west, 0, 8), true); ?>
    <a class="link-more" href="<?= url('standings') ?>">Tabela completa →</a>
  </section>
</div>
<?php render_footer(); ?>
