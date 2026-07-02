<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/League.php';

if (!defined('APP_BASE')) {
    define('APP_BASE', Database::config()['app_base'] ?? '');
}

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/**
 * URL do logo oficial de um time (ESPN CDN).
 * Fallback para a mesma URL se a sigla já for padrão ESPN.
 */
function logo_url(string $abbr): string {
    // Mapeamento: sigla interna → sigla ESPN
    static $map = [
        'GSW' => 'gs',   // Golden State Warriors
        'NOP' => 'no',   // New Orleans Pelicans
        'NYK' => 'ny',   // New York Knicks
        'SAS' => 'sa',   // San Antonio Spurs
        'UTA' => 'utah', // Utah Jazz
        'WAS' => 'wsh',  // Washington Wizards
        'BKN' => 'bkn',  // Brooklyn Nets
    ];
    $esp = $map[strtoupper($abbr)] ?? strtolower($abbr);
    return "https://a.espncdn.com/i/teamlogos/nba/500/{$esp}.png";
}

/**
 * Renderiza um logo de time com fallback para badge colorido.
 * $size: 'sm'(24px) | 'md'(36px) | 'lg'(56px) | 'xl'(80px)
 */
function team_logo(string $abbr, string $color = '#333', string $size = 'md', string $class = ''): string {
    $url = logo_url($abbr);
    $sz  = ['sm'=>24,'md'=>36,'lg'=>56,'xl'=>80,'hero'=>120][$size] ?? 36;
    return '<img src="'.e($url).'" alt="'.e($abbr).'" class="tlogo tlogo-'.$size.' '.$class.'"
              width="'.$sz.'" height="'.$sz.'"
              onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">'
          .'<span class="tlogo-fb tlogo-'.$size.' '.$class.'" style="background:'.$color.';display:none">'.e($abbr).'</span>';
}

/**
 * URL da foto de um jogador (NBA CDN ou face local).
 */
function player_photo_url(int $nbaId, string $name, string $teamColor = '#1a1a2e', int $playerId = 0, string $pos = ''): string {
    if ($nbaId > 0) {
        return "https://cdn.nba.com/headshots/nba/latest/260x190/{$nbaId}.png";
    }
    $pid = $playerId ?: (abs(crc32($name)) % 9000 + 1);
    return APP_BASE . '/face.php?id=' . $pid . '&name=' . rawurlencode($name) . '&pos=' . rawurlencode($pos);
}

/**
 * Renderiza a foto de um jogador.
 * NBA CDN para jogadores reais, face.php local como fallback universal.
 * $size: 'sm'(40px) | 'md'(64px) | 'lg'(96px) | 'hero'(140px)
 */
function player_photo(int $nbaId, string $name, string $teamColor = '#1a1a2e', string $size = 'md', string $class = '', int $playerId = 0, string $pos = ''): string {
    $sizes = ['sm'=>40,'md'=>64,'lg'=>96,'hero'=>140,'xl'=>180];
    $px  = $sizes[$size] ?? 64;
    $pid = $playerId ?: (abs(crc32($name)) % 9000 + 1);
    $faceUrl  = APP_BASE . '/face.php?id=' . $pid . '&name=' . rawurlencode($name) . '&pos=' . rawurlencode($pos);
    $baseClass = 'player-photo player-photo-' . $size . ($class ? ' ' . $class : '');
    if ($nbaId > 0) {
        $cdn = "https://cdn.nba.com/headshots/nba/latest/260x190/{$nbaId}.png";
        return '<img src="' . e($cdn) . '" alt="' . e($name) . '" class="' . $baseClass . '"'
             . ' width="' . $px . '" height="' . round($px * 0.73) . '"'
             . ' onerror="this.onerror=null;this.src=\'' . e($faceUrl) . '\';this.style.height=this.width+\'px\';this.style.objectFit=\'cover\'">';
    }
    return '<img src="' . e($faceUrl) . '" alt="' . e($name) . '" class="' . $baseClass . '"'
         . ' width="' . $px . '" height="' . $px . '" style="object-fit:cover">';
}

function url(string $page, array $params = []): string
{
    $params['p'] = $page;
    return 'index.php?' . http_build_query($params);
}

function avg($total, $gp, int $dec = 1): string
{
    $gp = (int) $gp;
    return $gp ? number_format($total / $gp, $dec) : '0.0';
}

function pct($made, $att): string
{
    $att = (int) $att;
    return $att ? number_format($made / $att * 100, 1) . '%' : '0.0%';
}

/** Formata um valor em dólares no estilo NBA: $48.8M, $850K, $0. */
function money($v): string
{
    $v = (int) $v;
    if ($v >= 1000000) return '$' . number_format($v / 1000000, 1) . 'M';
    if ($v >= 1000)    return '$' . number_format($v / 1000, 0) . 'K';
    return '$' . $v;
}

/**
 * Retorna [city, name] histórico de um time, se o ano do save estiver num período diferente do nome atual.
 * Retorna null se não houver nome histórico para a era atual.
 */
function historicalTeamName(string $abbr): ?array
{
    static $cache = [];
    if (isset($cache[$abbr])) return $cache[$abbr];

    try {
        $eraStart = (int) Database::meta('era_start', 2026);
        $season   = (int) Database::meta('season', 1);
        $year     = $eraStart + ($season - 1);

        $names = require dirname(__DIR__) . '/data/historical_names.php';
        if (!isset($names[$abbr])) { $cache[$abbr] = null; return null; }

        foreach ($names[$abbr] as $entry) {
            if ($year >= (int)$entry['start'] && $year <= (int)$entry['end']) {
                $cache[$abbr] = $entry;
                return $entry;
            }
        }
    } catch (Throwable $e) {}

    $cache[$abbr] = null;
    return null;
}

function teamFull(array $t): string
{
    $hist = historicalTeamName($t['abbr'] ?? '');
    if ($hist) return $hist['city'] . ' ' . $hist['name'];
    return $t['city'] . ' ' . ($t['name'] ?? $t['team_name'] ?? '');
}

/** Converte um atributo (0-99) em nota de olheiro (A+..D). */
function grade($v): string
{
    $v = (int) $v;
    return match (true) {
        $v >= 92 => 'A+', $v >= 87 => 'A', $v >= 83 => 'B+', $v >= 78 => 'B',
        $v >= 73 => 'C+', $v >= 68 => 'C', $v >= 62 => 'D+', default => 'D',
    };
}
function gradeClass($v): string
{
    $v = (int) $v;
    return $v >= 83 ? 'g-a' : ($v >= 73 ? 'g-b' : ($v >= 65 ? 'g-c' : 'g-d'));
}

/** Item do menu top nav. */
function nav_item(string $page, string $cur, string $icon, string $label, array $params = []): void
{
    $active = $cur === $page ? ' active' : '';
    echo '<a class="tn-tab' . $active . '" href="' . url($page, $params) . '">' . e($label) . '</a>';
}

function fba_head(string $title): void
{
    ?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> — FBA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Barlow+Condensed:wght@600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<?php
}

/** Cabeçalho leve para páginas de conta/saves (não acessa o banco do jogo). */
function render_auth_header(string $title = 'FBA'): void
{
    fba_head($title);
    ?>
<body class="auth-body">
<div class="auth-shell">
  <div class="auth-brand">
    <img src="assets/img/fba-logo.svg" alt="FBA" class="auth-logo">
    <div class="auth-brand-txt"><span class="ab-name">FBA</span><span class="ab-sub">Franchise Basketball Association · GM Simulator</span></div>
  </div>
  <main class="auth-main">
<?php
}

function render_auth_footer(): void
{
    ?>
  </main>
  <p class="auth-foot">FBA — simulação não-oficial · dados aproximados da NBA</p>
</div>
</body>
</html>
<?php
}

function render_header(string $title = 'FBA'): void
{
    $phase = League::phase();
    $phaseLabel = ['regular' => 'Temporada Regular', 'playin' => 'Play-In', 'playoffs' => 'Playoffs',
                   'lottery' => 'Loteria do Draft', 'draft' => 'Draft', 'preseason' => 'Pré-Temporada',
                   'freeagency' => 'Free Agency', 'offseason' => 'Off-season'][$phase] ?? $phase;
    $day    = League::currentDay();
    $total  = League::totalDays();
    $season = League::season();
    $gmId   = League::gmTeam();
    $gm     = $gmId ? League::team($gmId) : null;
    $gmName = Database::meta('gm_name');
    $user   = Accounts::user();
    $cur    = $_GET['p'] ?? 'home';
    $eraName = Database::meta('era_name');
    // Visibilidade de abas por fase — cada evento aparece só no seu momento.
    $showPlayoffs = in_array($phase, ['playin','playoffs','offseason'], true); // pós-temporada + revisão
    $showLottery  = $phase === 'lottery';
    $showDraft    = $phase === 'draft';
    $showFA       = $phase === 'freeagency';
    $showPreseason = $phase === 'preseason';
    $inboxUnread  = $gmId ? League::inboxUnread() : 0;
    fba_head($title);
    // Identidade visual: injeta as cores do time controlado como CSS vars.
    $tp = $gm['primary_color']   ?? '#E4002B';
    $ts = $gm['secondary_color'] ?? '#111111';
    ?>
<body style="--team-primary:<?= e($tp) ?>;--team-secondary:<?= e($ts) ?>;">
<div class="fba-app">

  <!-- TOP BAR: brand + record + user -->
  <nav class="top-nav">
    <!-- Brand (left) -->
    <div class="tn-brand">
      <?php if ($gm):
        $hist = historicalTeamName($gm['abbr']); ?>
        <?= team_logo($gm['abbr'], $gm['primary_color'], 'sm', 'tn-logo') ?>
        <div class="tn-brand-txt">
          <div class="tn-team-name"><?= e($hist ? $hist['city'].' '.$hist['name'] : $gm['city'].' '.$gm['name']) ?></div>
          <div class="tn-gm-sub">GM MODE · T<?= $season ?></div>
        </div>
      <?php else: ?>
        <a class="tn-logo-link" href="<?= url('home') ?>">
          <img src="assets/img/fba-logo.svg" alt="FBA" style="height:26px">
          <span class="tn-brand-name">FBA</span>
        </a>
      <?php endif; ?>
    </div>

    <!-- Center: phase + date pill (only during active season) -->
    <?php if (in_array($phase, ['regular','playin','playoffs'])): ?>
    <div class="tn-center">
      <span class="tn-phase-pill phase-<?= $phase ?>"><?= e($phaseLabel) ?></span>
      <span class="tn-date-txt">📅 <?= e(League::dateLabel($day)) ?><?= $phase === 'regular' ? ' · '.$day.'/'.$total : '' ?></span>
    </div>
    <?php elseif ($eraName): ?>
    <div class="tn-center">
      <span class="tn-phase-pill"><?= e($eraName) ?></span>
      <span class="tn-date-txt"><?= e($phaseLabel) ?></span>
    </div>
    <?php endif; ?>

    <!-- Right: record + user -->
    <div class="tn-right">
      <?php if ($gm): ?>
        <div class="tn-record">
          <div class="tn-rec-num"><?= (int)$gm['wins'] ?>-<?= (int)$gm['losses'] ?></div>
          <div class="tn-rec-sub"><?= e($gm['conf'] === 'E' ? 'Leste' : 'Oeste') ?></div>
        </div>
      <?php endif; ?>
      <div class="tn-user-acts">
        <?php if ($user): ?>
          <span class="tn-avatar" title="<?= e($user['username']) ?>"><?= strtoupper(substr($user['username'], 0, 1)) ?></span>
        <?php endif; ?>
        <a class="tn-act" href="<?= url('saves') ?>">Saves</a>
        <a class="tn-act tn-logout" href="<?= url('home', ['action' => 'logout']) ?>">Sair</a>
      </div>
    </div>
  </nav>

  <!-- NAV TABS BAR (sempre visível, largura total) -->
  <div class="nav-tabs-bar">
    <?php
      nav_item('home',      $cur, '', 'Início');
      if ($showPreseason) nav_item('preseason', $cur, '', 'Pré-Temporada');
      if ($gm) {
        // Aba Mensagens com contador de não lidas
        $active = $cur === 'inbox' ? ' active' : '';
        $badge = $inboxUnread > 0 ? ' <span class="tab-badge">' . $inboxUnread . '</span>' : '';
        echo '<a class="tn-tab' . $active . '" href="' . url('inbox') . '">Mensagens' . $badge . '</a>';
      }
      nav_item('standings', $cur, '', 'Classificação');
      if ($showPlayoffs) nav_item('playoffs', $cur, '', 'Playoffs');
      nav_item('power',     $cur, '', 'Power Rankings');
      nav_item('schedule',  $cur, '', 'Jogos');
      nav_item('leaders',   $cur, '', 'Líderes');
      nav_item('cap',       $cur, '', 'Contratos');
      // Eventos da entressafra — só aparecem no momento de cada um:
      if ($showLottery) nav_item('lottery', $cur, '', 'Loteria');
      if ($showDraft)   nav_item('draft',   $cur, '', 'Draft');
      if ($showFA)      nav_item('freeagency', $cur, '', 'Free Agency');
      nav_item('history',   $cur, '', 'Histórico');
      nav_item('teams',     $cur, '', 'Times');
      if ($gm):
        nav_item('manage',  $cur, '', 'Meu Time');
        nav_item('lineup',  $cur, '', 'Escalação');
        nav_item('trades',  $cur, '', 'Trocas');
      endif;
    ?>
  </div>

  <!-- Page content -->
  <div class="app-body">
    <div class="container">
<?php
  // Toast de auto-save (desaparece em 3s)
  if (!empty($_GET['autosaved'])):
?>
<div class="autosave-toast" id="asToast">💾 Salvo automaticamente</div>
<script>setTimeout(function(){var t=document.getElementById('asToast');if(t){t.style.opacity='0';setTimeout(function(){t.remove()},400);}},2800);</script>
<?php
  endif;
}


function render_footer(): void
{
    ?>
    </div><!-- /container -->
  </div><!-- /app-body -->
</div><!-- /fba-app -->
<script src="assets/js/app.js"></script>
</body>
</html>
<?php
}

function gradient(array $t): string
{
    $p = $t['primary_color'] ?? $t['home_color'] ?? '#333';
    $s = $t['secondary_color'] ?? '#111';
    return "background:linear-gradient(135deg,$p,$s);";
}

/**
 * Renderiza as ações de UMA mensagem da caixa de entrada (decisão, renovação
 * de contrato ou link simples). Usado por home.php, preseason.php e inbox.php
 * para que qualquer pendência do GM seja resolvida direto na mensagem, em
 * qualquer uma das 3 telas.
 */
function render_inbox_actions(array $m, string $backPage): void
{
    $kind = $m['kind'];

    if ($kind === 'decision_done' || $kind === 'resign_done') {
        echo '<div class="im-resolved">✓ Resolvido</div>';
        return;
    }

    if ($kind === 'resign' && (int) $m['ref_id'] > 0) {
        $rp = League::player((int) $m['ref_id']);
        if ($rp && (int) $rp['contract_years'] <= 1) {
            $d = League::resignDemand($rp);
            echo '<div class="im-actions">';
            echo '<a class="btn btn-sm btn-primary" href="' . url('home', ['action'=>'resign','pid'=>$m['ref_id'],'choice'=>'accept','back'=>$backPage]) . '">'
               . '✅ Renovar · ' . $d['years'] . 'a / ' . money($d['salary']) . '</a>';
            echo '<a class="btn btn-sm" href="' . url('home', ['action'=>'resign','pid'=>$m['ref_id'],'choice'=>'reject','back'=>$backPage]) . '"'
               . ' onclick="return confirm(\'Recusar renovação de ' . e($rp['name']) . '?\')">❌ Recusar</a>';
            echo '</div>';
        }
        return;
    }

    if ($kind === 'decision' && (int) $m['ref_id'] > 0) {
        $d = League::decision((int) $m['ref_id']);
        if ($d && $d['status'] === 'pending') {
            $opts = json_decode($d['options'], true) ?: [];
            echo '<div class="im-actions">';
            foreach ($opts as $key => $label) {
                echo '<a class="btn btn-sm" href="' . url('home', ['action'=>'decide','id'=>$d['id'],'choice'=>$key,'back'=>url($backPage)]) . '">' . e($label) . '</a>';
            }
            echo '</div>';
        }
        return;
    }

    if (!empty($m['link'])) {
        echo '<div class="im-actions"><a class="im-link" href="' . e($m['link']) . '">Abrir →</a></div>';
    }
}

/** Renderiza uma mensagem no estilo compacto (.inbox-msg), usado no home e na pré-temporada. */
function render_inbox_msg(array $m, string $backPage): void
{
    $urgent = !empty($m['urgent']) ? 'urgent' : '';
    echo '<div class="inbox-msg ' . $urgent . ' kind-' . e($m['kind']) . '">';
    echo '<div class="im-icon">' . e($m['icon'] ?: '📬') . '</div>';
    echo '<div class="im-body">';
    echo '<div class="im-from">' . e($m['sender']) . '</div>';
    echo '<div class="im-title">' . e($m['title']) . '</div>';
    if (!empty($m['body'])) echo '<div class="im-text">' . e($m['body']) . '</div>';
    render_inbox_actions($m, $backPage);
    echo '</div></div>';
}

/** Painel de decisões pendentes do GM (cada uma com botões de escolha). */
function render_decisions(array $decisions, ?string $back = null): void
{
    if (!$decisions) return;
    echo '<section class="card decisions-card"><div class="card-head"><h2>📨 Decisões pendentes</h2></div>';
    foreach ($decisions as $d) {
        $opts = json_decode($d['options'], true) ?: [];
        echo '<div class="decision"><h3>' . e($d['title']) . '</h3><p>' . e($d['body']) . '</p><div class="decision-opts">';
        foreach ($opts as $key => $label) {
            $params = ['action' => 'decide', 'id' => $d['id'], 'choice' => $key];
            if ($back) $params['back'] = $back;
            echo '<a class="btn btn-sm" href="' . url('home', $params) . '">' . e($label) . '</a>';
        }
        echo '</div></div>';
    }
    echo '</section>';
}

/** Mini-calendário de um time: lista de jogos (com data, mando, adversário e resultado). */
function render_team_schedule(array $games): void
{
    if (!$games) { echo '<p class="muted">Sem jogos para mostrar.</p>'; return; }
    echo '<div class="mini-cal">';
    foreach ($games as $g) {
        $cls = 'mc-row';
        $res = '';
        if (!empty($g['played'])) {
            $cls .= $g['win'] ? ' mc-win' : ' mc-loss';
            $res = '<span class="mc-res">' . ($g['win'] ? 'V' : 'D') . ' ' . (int)$g['my_pts'] . '-' . (int)$g['op_pts'] . '</span>';
        } else {
            $res = '<a class="btn btn-sm" href="' . url('game', ['id' => $g['id']]) . '">ver →</a>';
        }
        echo '<div class="' . $cls . '">'
            . '<span class="mc-date">' . e(League::dateLabel((int)$g['day'])) . '</span>'
            . '<span class="mc-vs">' . ($g['is_home'] ? 'vs' : '@') . ' <span class="dot" style="background:' . e($g['opp_color']) . '"></span>'
            . '<a href="' . url('team', ['id' => $g['opp_id']]) . '">' . e($g['opp_abbr']) . '</a></span>'
            . $res . '</div>';
    }
    echo '</div>';
}

/** Card de scouting de um adversário. */
function render_scout_card(array $r): void
{
    $t = $r['team'];
    echo '<div class="scout">';
    echo '<div class="scout-head" style="' . gradient($t) . '">'
        . '<div class="scout-abbr">' . e($t['abbr']) . '</div>'
        . '<div><strong>' . e(teamFull($t)) . '</strong><br><span class="muted">'
        . ($t['conf'] === 'E' ? 'Leste' : 'Oeste') . ' · ' . e($r['record']) . ' · '
        . e($r['scheme_off']) . ' / ' . e($r['scheme_def']) . '</span></div></div>';

    // forma recente
    if ($r['form']) {
        echo '<div class="scout-form">Forma: ';
        foreach ($r['form'] as $f) echo '<span class="form-' . ($f === 'V' ? 'w' : 'l') . '">' . $f . '</span>';
        echo '</div>';
    }

    // confronto direto na temporada
    if (!empty($r['h2h'])) {
        $h = $r['h2h'];
        echo '<div class="scout-h2h">🤝 Confronto na temporada: <strong>' . (int)$h['wins'] . '–' . (int)$h['losses'] . '</strong> (você)';
        if ($h['games']) {
            echo ' <span class="muted">·</span> ';
            foreach ($h['games'] as $gm) {
                echo '<span class="h2h-g ' . ($gm['win'] ? 'h2h-w' : 'h2h-l') . '">' . ($gm['win'] ? 'V' : 'D')
                    . ' ' . (int)$gm['my'] . '-' . (int)$gm['op'] . '</span>';
            }
        } else {
            echo ' <span class="muted">— ainda não se enfrentaram.</span>';
        }
        echo '</div>';
    }

    echo '<div class="scout-cols">';
    echo '<div><h4>💪 Forças</h4>' . (($r['strengths']) ? '<ul><li>' . implode('</li><li>', array_map('e', $r['strengths'])) . '</li></ul>' : '<p class="muted">—</p>') . '</div>';
    echo '<div><h4>🎯 Fraquezas</h4>' . (($r['weaknesses']) ? '<ul><li>' . implode('</li><li>', array_map('e', $r['weaknesses'])) . '</li></ul>' : '<p class="muted">—</p>') . '</div>';
    echo '</div>';

    // jogadores-chave
    echo '<h4>⭐ Jogadores-chave</h4><div class="scout-players">';
    foreach ($r['players'] as $p) {
        $inj = (int)($p['injury_games'] ?? 0);
        echo '<a class="scout-p" href="' . url('player', ['id' => $p['id']]) . '">'
            . '<span class="ovr ovr-' . ($p['ovr']>=90?'elite':($p['ovr']>=80?'star':($p['ovr']>=75?'good':'role'))) . '">' . (int)$p['ovr'] . '</span> '
            . e($p['name']) . ' <span class="muted">' . e($p['pos']) . ($inj ? ' · 🩹' : '') . '</span></a>';
    }
    echo '</div>';

    // plano sugerido
    echo '<h4>🧠 Plano sugerido</h4><ul class="scout-tips">';
    foreach ($r['tips'] as $tip) echo '<li>' . e($tip) . '</li>';
    echo '</ul></div>';
}
