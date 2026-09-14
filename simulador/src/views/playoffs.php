<?php
require_once dirname(__DIR__) . '/helpers.php';

$phase   = League::phase();
$season  = League::season();
$gmId    = (int) League::gmTeam();
$gm      = $gmId ? League::team($gmId) : null;
$bracket = League::playoffBracket();
$round   = (int) Database::meta('playoff_round', 0);
$champId = $phase === 'offseason' ? (int) Database::meta('champion_id', 0) : 0;
$playin  = json_decode((string) Database::meta('playin_map', ''), true);
$playin  = is_array($playin) ? $playin : [];

$teams = [];
foreach (League::allTeams() as $t) $teams[(int) $t['id']] = $t;

$byRound = [];
foreach ($bracket as $s) $byRound[(int) $s['round']][(string) $s['conf']][] = $s;

$confName   = ['E' => 'Leste', 'W' => 'Oeste'];
$roundNames = [1 => '1ª rodada', 2 => 'Semifinal', 3 => 'Final da conferência', 4 => 'Finais'];
$confOrder  = ($gm && $gm['conf'] === 'W') ? ['W', 'E'] : ['E', 'W'];

/** Situação de uma série: [texto, está num jogo que pode encerrar a série]. */
$seriesState = function (array $s): array {
    $hw  = (int) $s['high_wins'];
    $lw  = (int) $s['low_wins'];
    $win = (int) ($s['winner_id'] ?? 0);
    $top = max($hw, $lw);
    $low = min($hw, $lw);
    if ($win) return [($win === (int) $s['high_seed_id'] ? $s['high_abbr'] : $s['low_abbr']) . " venceu $top-$low", false];
    if ($hw + $lw === 0) return ['Jogo 1 a disputar', false];
    if ($hw === $lw) return ["Empate $hw-$lw" . ($hw === 3 ? ' · jogo 7' : ''), $hw === 3];
    return [($hw > $lw ? $s['high_abbr'] : $s['low_abbr']) . " lidera $top-$low" . ($top === 3 ? ' · pode fechar' : ''), $top === 3];
};

/** Uma série da chave (ou a vaga ainda sem times). */
$seriesCard = function (?array $s) use ($teams, $gmId, $seriesState): string {
    if (!$s) {
        $tbd = '<div class="po-team"><span class="po-seed"></span><span class="po-tbd">A definir</span></div>';
        return '<div class="po-series tbd">' . $tbd . $tbd . '</div>';
    }
    $hi  = (int) $s['high_seed_id'];
    $lo  = (int) $s['low_seed_id'];
    $win = (int) ($s['winner_id'] ?? 0);
    $row = function (int $id, string $abbr, int $seed, int $wins) use ($teams, $win): string {
        $t = $teams[$id] ?? [];
        return '<a class="po-team' . ($win ? ($win === $id ? ' won' : ' lost') : '') . '" href="' . url('team', ['id' => $id]) . '">'
            . '<span class="po-seed">' . $seed . '</span>'
            . team_logo($abbr, (string) ($t['primary_color'] ?? '#333'), 'sm')
            . '<b>' . e($abbr) . '</b><span class="po-name">' . e((string) ($t['name'] ?? '')) . '</span>'
            . '<span class="po-wins">' . $wins . '</span></a>';
    };
    [$status, $hot] = $seriesState($s);
    $cls = 'po-series' . ($gmId && ($gmId === $hi || $gmId === $lo) ? ' mine' : '') . ($win ? ' done' : '') . ($hot ? ' hot' : '');
    return '<div class="' . $cls . '">'
        . $row($hi, (string) $s['high_abbr'], (int) $s['high_seed'], (int) $s['high_wins'])
        . $row($lo, (string) $s['low_abbr'], (int) $s['low_seed'], (int) $s['low_wins'])
        . '<div class="po-foot">' . e($status) . '</div></div>';
};

/** Resultado de um jogo já disputado: ['w' => vencedor, 'l' => perdedor] ou null. */
$result = function (int $gameId): ?array {
    $g = $gameId > 0 ? League::game($gameId) : null;
    if (!$g || empty($g['played'])) return null;
    $homeWon = (int) $g['home_pts'] > (int) $g['away_pts'];
    return ['w' => (int) ($homeWon ? $g['home_id'] : $g['away_id']), 'l' => (int) ($homeWon ? $g['away_id'] : $g['home_id'])];
};

// Seu time nos playoffs: [frase, tom, rótulo do chip, ícone]
$you = null;
if ($gm) {
    $gs = null;
    foreach ($bracket as $s) {
        if ((int) $s['high_seed_id'] === $gmId || (int) $s['low_seed_id'] === $gmId) $gs = $s; // fica a rodada mais alta
    }
    if ($gs) {
        $r    = (int) $gs['round'];
        $high = (int) $gs['high_seed_id'] === $gmId;
        $my   = $high ? (int) $gs['high_wins'] : (int) $gs['low_wins'];
        $op   = $high ? (int) $gs['low_wins'] : (int) $gs['high_wins'];
        $opp  = $high ? (string) $gs['low_abbr'] : (string) $gs['high_abbr'];
        $win  = (int) ($gs['winner_id'] ?? 0);
        $rn   = $roundNames[$r] ?? 'Playoffs';
        if ($win === $gmId && $r === 4) $you = ["Campeão em cima do $opp, $my-$op", 'ok', 'Campeão', 'trophy-fill'];
        elseif ($win === $gmId)         $you = ["Passou pelo $opp por $my-$op", 'ok', 'Classificado', 'check-circle-fill'];
        elseif ($win)                   $you = ["Eliminado pelo $opp, $op-$my", 'bad', 'Eliminado', 'x-circle-fill'];
        elseif ($my + $op === 0)        $you = ["Pega o $opp, jogo 1 a disputar", 'info', $rn, 'hourglass-split'];
        elseif ($my === $op)            $you = ["Série empatada com o $opp, $my-$op", 'info', $rn, 'hourglass-split'];
        elseif ($my > $op)              $you = ["Você lidera contra o $opp, $my-$op", 'ok', $rn, 'arrow-up-right'];
        else                            $you = ["$opp lidera a série, $op-$my", 'warn', $rn, 'arrow-down-right'];
    } else {
        $seed = null;
        foreach (League::standings((string) $gm['conf']) as $st) { if ((int) $st['id'] === $gmId) { $seed = (int) $st['seed']; break; } }
        $cn = $confName[$gm['conf']] ?? '';
        $m  = $playin[(string) $gm['conf']] ?? [];
        if ($phase === 'playin' && $seed !== null && $seed <= 6) {
            $you = ["Vaga direta como {$seed}º do $cn", 'ok', 'Classificado', 'check-circle-fill'];
        } elseif ($phase === 'playin' && $seed !== null && $seed <= 10) {
            $a = $result((int) ($m['A'] ?? 0));
            $b = $result((int) ($m['B'] ?? 0));
            $c = $result((int) ($m['C'] ?? 0));
            if (($b && $b['l'] === $gmId) || ($c && $c['l'] === $gmId)) $you = ['Caiu no play-in', 'bad', 'Eliminado', 'x-circle-fill'];
            elseif ($a && $a['w'] === $gmId) $you = ['Garantiu a vaga de 7º no play-in', 'ok', 'Classificado', 'check-circle-fill'];
            else $you = ['Disputando o play-in pelas últimas vagas', 'warn', 'Play-in', 'shuffle'];
        } elseif ($bracket && $seed !== null && $seed <= 10 && $playin) {
            $you = ['Caiu no play-in', 'bad', 'Eliminado', 'x-circle-fill'];
        } elseif ($bracket || $phase === 'playin') {
            $you = ['Ficou fora dos playoffs nesta temporada', 'bad', 'Fora', 'x-circle-fill'];
        }
    }
}

$renderPlayin = function () use ($playin, $confName, $confOrder, $gmId): void {
    $labels = [
        'A' => ['7º x 8º', 'quem vence fica com a vaga de 7º'],
        'B' => ['9º x 10º', 'quem perde está fora'],
        'C' => ['Vaga de 8º', 'perdedor do 7x8 contra o vencedor do 9x10'],
    ];
    echo '<section class="panel">' . panel_head('Play-in', ['icon' => 'shuffle', 'meta' => 'Do 7º ao 10º brigam pelas vagas de 7º e 8º']);
    $firstConf = true;
    foreach ($confOrder as $c) {
        if (empty($playin[$c])) continue;
        echo '<div class="po-pi-conf"><div class="sub-h' . ($firstConf ? ' first' : '') . '">Conferência ' . e($confName[$c]) . '</div><div class="po-pi-list">';
        $firstConf = false;
        foreach ($labels as $k => [$title, $desc]) {
            $gid = (int) ($playin[$c][$k] ?? 0);
            $g = $gid > 0 ? League::game($gid) : null;
            echo '<div class="po-pi"><span class="po-pi-lbl"><b>' . e($title) . '</b> · ' . e($desc) . '</span>'
                . ($g ? game_tile($g, $gmId) : '<div class="po-pi-tbd">A definir</div>') . '</div>';
        }
        echo '</div></div>';
    }
    echo '</section>';
};

$renderFinals = function () use ($byRound, $teams, $gmId, $seriesState): void {
    $fin = $byRound[4]['F'][0] ?? null;
    $mine = $fin && $gmId && in_array($gmId, [(int) $fin['high_seed_id'], (int) $fin['low_seed_id']], true);
    echo '<section class="panel po-finals' . ($mine ? ' hot' : '') . '">';
    echo panel_head('Finais', ['icon' => 'trophy-fill', 'meta' => 'Campeão do Leste contra campeão do Oeste']);
    if ($fin) {
        $hi  = (int) $fin['high_seed_id'];
        $lo  = (int) $fin['low_seed_id'];
        $win = (int) ($fin['winner_id'] ?? 0);
        $side = function (int $id, string $abbr, string $sub, bool $lost) use ($teams): string {
            $t = $teams[$id] ?? [];
            return '<a class="mu-side' . ($lost ? ' lost' : '') . '" href="' . url('team', ['id' => $id]) . '">'
                . team_logo($abbr, (string) ($t['primary_color'] ?? '#333'), 'lg')
                . '<b>' . e($abbr) . '</b><small>' . e($sub) . '</small></a>';
        };
        echo '<div class="matchup">'
            . $side($hi, (string) $fin['high_abbr'], 'Leste · ' . (int) $fin['high_seed'] . 'º', $win && $win !== $hi)
            . '<div class="mu-mid"><span class="po-fin-score">' . (int) $fin['high_wins'] . '-' . (int) $fin['low_wins'] . '</span></div>'
            . $side($lo, (string) $fin['low_abbr'], 'Oeste · ' . (int) $fin['low_seed'] . 'º', $win && $win !== $lo)
            . '</div>';
        echo '<p class="po-fin-status">' . e($seriesState($fin)[0]) . '</p>';
    } else {
        $tbd = '<div class="mu-side"><span class="po-q">?</span><b>%s</b><small>A definir</small></div>';
        echo '<div class="matchup">' . sprintf($tbd, 'Leste') . '<div class="mu-mid">x</div>' . sprintf($tbd, 'Oeste') . '</div>';
    }
    echo '</section>';
};

$renderConf = function (string $c) use ($byRound, $roundNames, $confName, $phase, $round, $seriesCard): void {
    echo '<section class="panel">' . panel_head('Conferência ' . $confName[$c], ['icon' => 'diagram-3-fill']);
    echo '<div class="po-rounds">';
    foreach ([1 => 4, 2 => 2, 3 => 1] as $r => $slots) {
        $list  = $byRound[$r][$c] ?? [];
        $now   = $phase === 'playoffs' && $round === $r;
        $title = $r === 3 ? 'Final do ' . $confName[$c] : $roundNames[$r];
        echo '<div class="po-round' . ($now ? ' now' : '') . '">';
        echo '<div class="po-round-h"><span>' . e($title) . '</span>' . ($now ? chip('Agora', 'info') : '') . '</div>';
        echo '<div class="po-list' . ($slots === 1 ? ' one' : '') . '">';
        for ($i = 0; $i < $slots; $i++) echo $seriesCard($list[$i] ?? null);
        echo '</div></div>';
    }
    echo '</div></section>';
};

render_header('Playoffs');
page_head('Playoffs', [
    'eyebrow' => 'Liga · Temporada ' . $season,
    'sub' => 'Oito times por conferência, em séries melhor de sete. Quem vence quatro jogos avança.',
]);
?>
<div class="stack">
  <?php if ($champId && ($champ = $teams[$champId] ?? League::team($champId))):
    $fin = $byRound[4]['F'][0] ?? null;
    $runner = null;
    $score = '';
    if ($fin) {
        $runnerId = (int) $fin['high_seed_id'] === $champId ? (int) $fin['low_seed_id'] : (int) $fin['high_seed_id'];
        $runner = $teams[$runnerId] ?? null;
        $score = max((int) $fin['high_wins'], (int) $fin['low_wins']) . '-' . min((int) $fin['high_wins'], (int) $fin['low_wins']);
    } ?>
  <section class="panel hot po-champ">
    <?= team_logo((string) $champ['abbr'], (string) ($champ['primary_color'] ?? '#333'), 'xl') ?>
    <div>
      <span class="eyebrow">Campeão da temporada <?= $season ?></span>
      <h2><?= e(teamFull($champ)) ?></h2>
      <?php if ($runner): ?><p class="muted">Venceu o <?= e(teamFull($runner)) ?> por <?= e($score) ?> nas finais.</p><?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($you && !($champId && $champId === $gmId)): ?>
  <section class="panel hot po-you">
    <?= team_logo((string) $gm['abbr'], (string) ($gm['primary_color'] ?? '#333'), 'lg') ?>
    <div class="po-you-txt">
      <div class="row po-you-top"><span class="eyebrow">Seu time</span><?= chip($you[2], $you[1], $you[3]) ?></div>
      <b><?= e($you[0]) ?></b>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!$bracket && !$playin): ?>
    <section class="panel">
      <?= empty_state('Os playoffs ainda não começaram.', 'A chave sai no fim da temporada regular, depois do play-in.', 'trophy') ?>
      <div class="row po-empty-cta"><a class="btn" href="<?= url('standings') ?>"><?= bi('bar-chart-fill') ?>Ver a classificação</a></div>
    </section>
  <?php else:
    if ($phase === 'playin' && $playin) $renderPlayin();
    if (!empty($byRound[4])) $renderFinals();
    if ($bracket) foreach ($confOrder as $c) $renderConf($c);
    if ($bracket && empty($byRound[4])) $renderFinals();
    if ($phase !== 'playin' && $playin) $renderPlayin();
  endif; ?>
</div>
<?php render_footer(); ?>
