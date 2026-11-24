<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/League.php';

if (!defined('APP_BASE')) {
    // Endereço público do jogo, tirado do próprio pedido: /games/simulador em
    // produção, /simulador/public no Docker local, vazio no php -S. É o que
    // faz os links absolutos (face.php, api.php) valerem em qualquer um deles.
    $__path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    $__base = preg_match('~/[a-z_]+\.php$~', $__path) ? substr($__path, 0, (int) strrpos($__path, '/')) : rtrim($__path, '/');
    define('APP_BASE', PHP_SAPI === 'cli' ? '' : $__base);
    unset($__path, $__base);
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
          .'<span class="tlogo-fb tlogo-'.$size.' '.$class.'" style="background:'.e($color).';display:none">'.e($abbr).'</span>';
}

/**
 * URL da foto de um jogador (NBA CDN ou face local).
 */
function player_photo_url(int $nbaId, string $name, string $teamColor = '#1a1a2e', int $playerId = 0, string $pos = ''): string {
    if ($nbaId > 0) {
        return "https://cdn.nba.com/headshots/nba/latest/260x190/{$nbaId}.png";
    }
    $pid = $playerId ?: (abs(crc32($name)) % 9000 + 1);
    return APP_BASE . '/face.php?id=' . $pid . '&name=' . rawurlencode($name) . '&pos=' . rawurlencode($pos) . '&c=' . rawurlencode(ltrim($teamColor, '#'));
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
    $faceUrl  = APP_BASE . '/face.php?id=' . $pid . '&name=' . rawurlencode($name) . '&pos=' . rawurlencode($pos) . '&c=' . rawurlencode(ltrim($teamColor, '#'));
    $baseClass = 'player-photo player-photo-' . $size . ($class ? ' ' . $class : '');
    // foto grande fica no topo da tela: carrega já, sem esperar o lazy
    $lazy = in_array($size, ['hero', 'xl'], true) ? '' : ' loading="lazy"';
    if ($nbaId > 0) {
        $cdn = "https://cdn.nba.com/headshots/nba/latest/260x190/{$nbaId}.png";
        return '<img src="' . e($cdn) . '" alt="' . e($name) . '" class="' . $baseClass . '"' . $lazy
             . ' width="' . $px . '" height="' . $px . '"'
             . ' onerror="this.onerror=null;this.src=\'' . e($faceUrl) . '\'">';
    }
    return '<img src="' . e($faceUrl) . '" alt="' . e($name) . '" class="' . $baseClass . '"' . $lazy
         . ' width="' . $px . '" height="' . $px . '">';
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

/**
 * Potencial como os olheiros veem: uma nota (A+…D) com erro estável por
 * jogador, nunca o número. Ninguém sabe se o cara vai virar 99 ou parar no 84 —
 * é preciso desenvolver e descobrir. Devolve '' quando não há teto acima do OVR.
 */
function potGrade(array $p): string
{
    $ovr = (int) ($p['ovr'] ?? 0);
    $pot = (int) ($p['potential'] ?? $ovr);
    if ($pot <= $ovr) return '';
    $noise = (abs(crc32((string) ($p['name'] ?? '') . (string) ($p['id'] ?? ''))) % 9) - 4; // -4..+4, fixo por jogador
    return grade(max($ovr + 1, min(99, $pot + $noise)));
}

function gradient(array $t): string
{
    $p = $t['primary_color'] ?? $t['home_color'] ?? '#333';
    $s = $t['secondary_color'] ?? '#111';
    return "background:linear-gradient(135deg,$p,$s);";
}

/* ═════════════════════════════════════════════════════════════════════
   IDENTIDADE DO TIME — as cores do time controlado viram o tema do jogo
   ═════════════════════════════════════════════════════════════════════ */

function hex_rgb(string $hex): array
{
    $h = ltrim(trim($hex), '#');
    if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $h)) return [228, 0, 43];
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}

function rgb_lum(array $c): float
{
    $l = array_map(function ($v) {
        $v = $v / 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }, $c);
    return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
}

function rgb_sat(array $c): float
{
    $max = max($c) / 255;
    $min = min($c) / 255;
    return $max <= 0 ? 0.0 : ($max - $min) / $max;
}

/**
 * Variáveis CSS do tema a partir das cores do time. Time de cor quase preta
 * (Spurs, Nets) usa a secundária; a versão "bright" é clareada até ler bem no
 * fundo escuro (texto, ícone ativo), a "ink" é a cor de texto sobre a do time.
 */
function team_theme_style(?array $t): string
{
    $p = hex_rgb((string) ($t['primary_color'] ?? '#E4002B'));
    $s = hex_rgb((string) ($t['secondary_color'] ?? '#111111'));
    $base = (rgb_sat($p) < 0.2 && rgb_lum($p) < 0.1 && rgb_lum($s) > rgb_lum($p)) ? $s : $p;
    $bright = $base;
    $i = 0;
    while (rgb_lum($bright) < 0.26 && $i++ < 14) {
        $bright = array_map(fn($v) => (int) round($v + (255 - $v) * 0.16), $bright);
    }
    $ink = rgb_lum($base) > 0.42 ? '#0b0f16' : '#ffffff';
    [$r, $g, $b] = $bright;
    return sprintf('--team:#%02x%02x%02x;--team-bright:rgb(%d,%d,%d);--team-ink:%s;--team-soft:rgba(%d,%d,%d,.14);--team-line:rgba(%d,%d,%d,.42)',
        $base[0], $base[1], $base[2], $r, $g, $b, $ink, $r, $g, $b, $r, $g, $b);
}

/* ═════════════════════════════════════════════════════════════════════
   COMPONENTES — todas as telas montam a interface com estes pedaços
   ═════════════════════════════════════════════════════════════════════ */

/** Ícone do Bootstrap Icons. */
function bi(string $name): string
{
    return '<i class="bi bi-' . e($name) . '" aria-hidden="true"></i>';
}

/** Faixa de carta do OVR: bronze (<70), prata (70–79), ouro (80–89), elite (90+). */
function ovr_tier(int $ovr): string
{
    return $ovr >= 90 ? 't-elite' : ($ovr >= 80 ? 't-gold' : ($ovr >= 70 ? 't-silver' : 't-bronze'));
}

function ovr_badge($ovr, string $size = ''): string
{
    $o = (int) $ovr;
    return '<span class="ovr ' . ovr_tier($o) . ($size !== '' ? ' ' . e($size) : '') . '" title="OVR ' . $o . '">' . $o . '</span>';
}

function chip(string $text, string $tone = '', string $icon = ''): string
{
    return '<span class="chip' . ($tone !== '' ? ' ' . e($tone) : '') . '">' . ($icon !== '' ? bi($icon) : '') . e($text) . '</span>';
}

/** Aviso em linha. $html é confiável (monte com e() antes). Tons: info, ok, bad, warn, go. */
function note(string $html, string $tone = 'info', string $icon = ''): string
{
    $icon = $icon !== '' ? $icon : (['ok' => 'check-circle-fill', 'bad' => 'exclamation-octagon-fill',
        'warn' => 'exclamation-triangle-fill', 'go' => 'lightning-charge-fill'][$tone] ?? 'info-circle-fill');
    return '<div class="note ' . e($tone) . '">' . bi($icon) . '<div>' . $html . '</div></div>';
}

function empty_state(string $title, string $text = '', string $icon = 'inbox'): string
{
    return '<div class="empty">' . bi($icon) . '<b>' . e($title) . '</b>' . ($text !== '' ? e($text) : '') . '</div>';
}

function stat_tile(string $label, string $value, string $sub = '', string $tone = ''): string
{
    return '<div class="stat"><small>' . e($label) . '</small><b' . ($tone !== '' ? ' class="' . e($tone) . '"' : '') . '>'
        . e($value) . '</b>' . ($sub !== '' ? '<span>' . e($sub) . '</span>' : '') . '</div>';
}

function meter(float $pct, string $tone = ''): string
{
    $pct = max(0, min(100, $pct));
    return '<div class="meter' . ($tone !== '' ? ' ' . e($tone) : '') . '"><span style="width:' . round($pct, 1) . '%"></span></div>';
}

/** Paciência da diretoria (ou qualquer "vidas"): barrinhas acesas de $max. */
function pips(int $on, int $max): string
{
    $h = '<span class="pips' . ($on <= 1 ? ' low' : '') . '" title="' . $on . ' de ' . $max . '">';
    for ($i = 1; $i <= $max; $i++) $h .= '<i class="' . ($i <= $on ? 'on' : '') . '"></i>';
    return $h . '</span>';
}

/** Sequência de resultados: aceita 'V'/'D', 'W'/'L' ou booleanos. */
function form_boxes(array $items): string
{
    $h = '<span class="form">';
    foreach ($items as $f) {
        $w = $f === true || $f === 'V' || $f === 'W' || $f === 1;
        $h .= '<i class="' . ($w ? 'w' : 'l') . '">' . ($w ? 'V' : 'D') . '</i>';
    }
    return $h . '</span>';
}

/** Cabeçalho de tela. $o: eyebrow, sub (texto), sub_html (confiável), actions (html). */
function page_head(string $title, array $o = []): void
{
    echo '<header class="page-head"><div class="ph-txt">';
    if (!empty($o['eyebrow'])) echo '<span class="eyebrow">' . e($o['eyebrow']) . '</span>';
    echo '<h1>' . e($title) . '</h1>';
    if (!empty($o['sub'])) echo '<p class="ph-sub">' . e($o['sub']) . '</p>';
    if (!empty($o['sub_html'])) echo '<p class="ph-sub">' . $o['sub_html'] . '</p>';
    echo '</div>';
    if (!empty($o['actions'])) echo '<div class="ph-actions">' . $o['actions'] . '</div>';
    echo '</header>';
}

/** Cabeçalho de painel. $o: icon, meta (texto), more ([rótulo, href]), right (html). */
function panel_head(string $title, array $o = []): string
{
    $right = (string) ($o['right'] ?? '');
    if (!empty($o['meta'])) $right .= '<span class="ph-meta">' . e($o['meta']) . '</span>';
    if (!empty($o['more'])) $right .= '<a class="more" href="' . e($o['more'][1]) . '">' . e($o['more'][0]) . bi('chevron-right') . '</a>';
    return '<div class="panel-head"><h2>' . (!empty($o['icon']) ? bi($o['icon']) : '') . e($title) . '</h2>'
        . ($right !== '' ? '<div class="row">' . $right . '</div>' : '') . '</div>';
}

/** Time em linha (logo + nome + linha menor), com link pra página do time. */
function team_who(array $t, string $small = '', bool $link = true): string
{
    $inner = team_logo((string) $t['abbr'], (string) ($t['primary_color'] ?? '#333'), 'sm')
        . '<span class="w-txt"><b>' . e(teamFull($t)) . '</b>' . ($small !== '' ? '<small>' . e($small) . '</small>' : '') . '</span>';
    return $link && !empty($t['id'])
        ? '<a class="who" href="' . url('team', ['id' => $t['id']]) . '">' . $inner . '</a>'
        : '<span class="who">' . $inner . '</span>';
}

/** Jogador em linha (foto + nome + posição/idade), com link pra ficha. */
function player_who(array $p, string $small = '', string $teamColor = '#1a1a2e'): string
{
    $photo = player_photo((int) ($p['nba_id'] ?? 0), (string) $p['name'], $teamColor, 'sm', 'face', (int) ($p['id'] ?? 0), (string) ($p['pos'] ?? ''));
    if ($small === '') {
        $small = trim((string) ($p['pos'] ?? '') . (isset($p['age']) ? ' · ' . (int) $p['age'] . ' anos' : ''), ' ·');
    }
    return '<a class="who" href="' . url('player', ['id' => $p['id']]) . '">' . $photo
        . '<span class="w-txt"><b>' . e((string) $p['name']) . '</b>' . ($small !== '' ? '<small>' . e($small) . '</small>' : '') . '</span></a>';
}

/** Placar compacto de um jogo (lista de jogos do dia, calendário). */
function game_tile(array $g, ?int $gmId = null): string
{
    $mine = $gmId && ((int) ($g['home_id'] ?? 0) === $gmId || (int) ($g['away_id'] ?? 0) === $gmId);
    $played = !empty($g['played']);
    $aw = $played && (int) $g['away_pts'] > (int) $g['home_pts'];
    $hw = $played && (int) $g['home_pts'] > (int) $g['away_pts'];
    $row = function (string $abbr, $pts, bool $won) use ($played): string {
        return '<div class="gt-row' . ($played && !$won ? ' lost' : '') . '">' . team_logo($abbr, '#333', 'sm')
            . '<b>' . e($abbr) . '</b>' . ($played ? '<span class="pts">' . (int) $pts . '</span>' : '') . '</div>';
    };
    $ot = (int) ($g['ot'] ?? 0);
    $foot = $played ? 'Final' . ($ot ? ' · PR' . ($ot > 1 ? $ot : '') : '') : ($mine ? '<span class="go">Seu jogo</span>' : 'A jogar');
    $cta = $played ? 'Box score' : ($mine ? 'Comandar' : 'Prévia');
    $href = url('game', ['id' => $g['id']] + ($mine && !$played ? ['live' => 1] : []));
    return '<a class="gtile' . ($mine ? ' mine' : '') . '" href="' . $href . '">'
        . $row((string) $g['away_abbr'], $g['away_pts'] ?? 0, $aw) . $row((string) $g['home_abbr'], $g['home_pts'] ?? 0, $hw)
        . '<div class="gt-foot"><span>' . $foot . '</span><span>' . $cta . '</span></div></a>';
}

/** Atributos de confirmação/carregamento de um link: ['confirm' => [text,title,ok,danger], 'busy' => '...']. */
function link_attrs(array $a): string
{
    $out = '';
    if (!empty($a['confirm']) && is_array($a['confirm'])) {
        $c = $a['confirm'];
        $out .= ' data-confirm="' . e((string) $c['text']) . '"';
        if (!empty($c['title'])) $out .= ' data-confirm-title="' . e((string) $c['title']) . '"';
        if (!empty($c['ok'])) $out .= ' data-confirm-ok="' . e((string) $c['ok']) . '"';
        if (!empty($c['danger'])) $out .= ' data-confirm-danger';
    }
    if (!empty($a['busy'])) $out .= ' data-busy="' . e((string) $a['busy']) . '"';
    return $out;
}

function phase_label(string $phase): string
{
    require_once __DIR__ . '/NextStep.php';
    return NextStep::phaseName($phase);
}

/* ═════════════════════════════════════════════════════════════════════
   CASCA DO JOGO — cabeçalho (trilho, HUD, abas) e rodapé (dock Próxima)
   ═════════════════════════════════════════════════════════════════════ */

/** Arquivo estático com ?v= da data de modificação (o servidor guarda CSS/JS por um mês). */
function asset_v(string $rel): string
{
    $f = dirname(__DIR__) . '/public/' . $rel;
    return $rel . (is_file($f) ? '?v=' . filemtime($f) : '');
}

/** <head> do jogo. Carrega o CSS da tela (assets/css/pages/{p}.css) quando existe. */
function fba_head(string $title): void
{
    $pg = preg_replace('/[^a-z]/', '', (string) ($GLOBALS['__sim_page'] ?? ($_GET['p'] ?? '')));
    ?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#070a10">
<title><?= e($title) ?> · Simulador · FBA Games</title>
<link rel="icon" href="/img/icons/icon-96.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(asset_v('assets/css/game.css')) ?>">
<?php if ($pg !== '' && is_file(dirname(__DIR__) . '/public/assets/css/pages/' . $pg . '.css')): ?>
<link rel="stylesheet" href="<?= e(asset_v('assets/css/pages/' . $pg . '.css')) ?>">
<?php endif; ?>
</head>
<?php
}

/**
 * Menu do jogo: cinco seções (as mesmas no trilho do desktop e nas abas do
 * celular). Os itens de fase (pré-temporada, loteria, draft, playoffs,
 * prêmios) só aparecem no momento deles.
 */
function sim_nav(): array
{
    $phase = League::phase();
    $gm = League::gmTeam();
    $unread = $gm ? League::inboxUnread() : 0;
    $capC = $gm ? Cap::gmCompliance() : null;

    $inicio = ['home' => 'Central', 'inbox' => 'Caixa de entrada'];
    if ($phase === 'preseason') $inicio['preseason'] = 'Pré-temporada';

    $elenco = ['lineup' => 'Escalação', 'manage' => 'Diretoria e técnico'];

    $mercado = ['trades' => 'Trocas'];
    if (Cap::signingOpen()) $mercado['freeagency'] = 'Agentes livres';
    $mercado['cap'] = 'Folha e teto';
    if ($phase === 'lottery') $mercado['lottery'] = 'Loteria';
    if ($phase === 'draft') $mercado['draftroom'] = 'Sala do draft';
    $mercado['draft'] = 'Drafts';

    $liga = ['standings' => 'Classificação', 'schedule' => 'Jogos'];
    if (in_array($phase, ['playin', 'playoffs', 'offseason'], true)) $liga['playoffs'] = 'Playoffs';
    if ($phase === 'offseason') $liga['awards'] = 'Prêmios';
    $liga += ['leaders' => 'Líderes', 'power' => 'Power ranking', 'teams' => 'Times', 'history' => 'História', 'search' => 'Busca'];

    return [
        'inicio'  => ['label' => 'Início',  'icon' => 'house-door-fill',  'items' => $inicio,  'badge' => $unread],
        'elenco'  => ['label' => 'Elenco',  'icon' => 'people-fill',      'items' => $elenco],
        'mercado' => ['label' => 'Mercado', 'icon' => 'arrow-left-right', 'items' => $mercado,
                      'flag' => ($capC && $capC['status'] !== 'ok') ? 'cap' : ''],
        'liga'    => ['label' => 'Liga',    'icon' => 'trophy-fill',      'items' => $liga],
        'mais'    => ['label' => 'Mais',    'icon' => 'grid-fill',        'items' => ['saves' => 'Meus saves', 'gmselect' => 'Franquias']],
    ];
}

function sim_section_of(string $page, array $nav): string
{
    foreach ($nav as $key => $s) {
        if (isset($s['items'][$page])) return $key;
    }
    return ['player' => 'liga', 'team' => 'liga', 'game' => 'liga', 'recap' => 'inicio', 'draftroom' => 'mercado',
            'lottery' => 'mercado', 'awards' => 'liga', 'playoffs' => 'liga', 'preseason' => 'inicio'][$page] ?? 'inicio';
}

/** Contador ao lado de um item do menu (não lidas na caixa, alerta na folha). */
function sim_item_badge(string $page, array $nav): string
{
    if ($page === 'inbox' && !empty($nav['inicio']['badge'])) return '<span class="badge">' . min(99, (int) $nav['inicio']['badge']) . '</span>';
    if ($page === 'cap' && !empty($nav['mercado']['flag'])) return '<span class="badge go">!</span>';
    return '';
}

/** Texto do relógio da fase no HUD. */
function hud_clock(string $phase): string
{
    switch ($phase) {
        case 'regular':    return 'Dia ' . League::currentDay() . '/' . (League::totalDays() ?: 82);
        case 'preseason':  return 'Dia ' . League::preseasonDay() . '/' . League::PRESEASON_DAYS;
        case 'playin':
        case 'playoffs':   return League::dateLabel(League::currentDay());
        case 'lottery':    return 'Sorteio';
        case 'draft':      return 'Em andamento';
        case 'freeagency': return 'Janela aberta';
        case 'offseason':  return 'Temporada ' . League::season();
    }
    return '';
}

/** Avisos de ação (?msg=, ?err=, ?dmsg=, ?autosaved=) viram toasts no topo. */
function render_toasts(): void
{
    $items = [];
    if (!empty($_GET['err'])) $items[] = ['bad', (string) $_GET['err']];
    foreach (['msg', 'dmsg'] as $k) {
        if (empty($_GET[$k])) continue;
        $m = (string) $_GET[$k];
        $bad = preg_match('/^(⚠️|⚠|⛔|❌|🚫)/u', $m) || stripos($m, 'erro') !== false || stripos($m, 'sem espaço') !== false;
        $items[] = [$bad ? 'bad' : 'ok', $m];
    }
    if (!empty($_GET['autosaved'])) $items[] = ['info', 'Jogo salvo automaticamente.'];
    if (!$items) return;
    echo '<div class="toasts" role="status" aria-live="polite">';
    foreach ($items as [$tone, $text]) {
        $icon = ['ok' => 'check-circle-fill', 'bad' => 'exclamation-triangle-fill', 'info' => 'cloud-check-fill'][$tone];
        echo '<div class="toast ' . $tone . '">' . bi($icon) . '<div>' . e($text) . '</div>'
            . '<button type="button" class="t-x" aria-label="Fechar">' . bi('x-lg') . '</button></div>';
    }
    echo '</div>';
}

/**
 * Cabeçalho de toda tela do jogo. $opt:
 *  - page: chave da tela (padrão ?p=)
 *  - dock: false esconde o botão Próxima (jogo ao vivo em andamento)
 */
function render_header(string $title = 'FBA', array $opt = []): void
{
    $page = (string) ($opt['page'] ?? ($_GET['p'] ?? 'home'));
    $GLOBALS['__sim_page'] = $page;
    $GLOBALS['__sim_dock'] = (bool) ($opt['dock'] ?? true);
    $phase = League::phase();
    $gmId = (int) League::gmTeam();
    $gm = $gmId ? League::team($gmId) : null;
    $nav = sim_nav();
    $sec = sim_section_of($page, $nav);

    fba_head($title);
    echo '<body style="' . e(team_theme_style($gm)) . '">';
    echo '<a class="sr-only" href="#conteudo">Pular para o conteúdo</a>';
    echo '<div class="g-app' . ($GLOBALS['__sim_dock'] ? '' : ' no-dock') . '">';

    // ── trilho (desktop) ──
    echo '<aside class="g-rail" aria-label="Menu do jogo">';
    echo '<a class="rail-brand" href="' . url('home') . '"><span class="rb-mark">FBA</span><span><b>Simulador</b><small>FBA Games</small></span></a>';
    echo '<nav>';
    foreach ($nav as $key => $s) {
        if ($key === 'mais') continue;
        $on = $key === $sec;
        $flag = !empty($s['badge']) ? '<span class="badge">' . min(99, (int) $s['badge']) . '</span>'
              : (!empty($s['flag']) ? '<span class="badge go">!</span>' : '');
        echo '<a class="rail-link' . ($on ? ' on' : '') . '" href="' . url((string) array_key_first($s['items'])) . '"'
            . ($on ? ' aria-current="true"' : '') . '>' . bi($s['icon']) . e($s['label']) . $flag . '</a>';
        if ($on) {
            echo '<div class="rail-sub">';
            foreach ($s['items'] as $pg => $label) {
                echo '<a class="' . ($pg === $page ? 'on' : '') . '" href="' . url($pg) . '"' . ($pg === $page ? ' aria-current="page"' : '') . '>'
                    . '<span>' . e($label) . '</span>' . sim_item_badge($pg, $nav) . '</a>';
            }
            echo '</div>';
        }
    }
    echo '</nav><div class="rail-foot">';
    foreach ($nav['mais']['items'] as $pg => $label) {
        echo '<a class="rail-link' . ($pg === $page ? ' on' : '') . '" href="' . url($pg) . '">' . bi($pg === 'saves' ? 'floppy-fill' : 'buildings-fill') . e($label) . '</a>';
    }
    echo '<a class="rail-link" href="/games.php">' . bi('controller') . 'Voltar para o Games</a>';
    echo '</div></aside>';

    echo '<div class="g-main">';

    // ── HUD: o placar do seu time ──
    echo '<header class="hud">';
    if ($gm) {
        $seed = null;
        foreach (League::standings($gm['conf']) as $st) {
            if ((int) $st['id'] === $gmId) { $seed = (int) $st['seed']; break; }
        }
        $conf = $gm['conf'] === 'E' ? 'Leste' : 'Oeste';
        $rec = (int) $gm['wins'] . '-' . (int) $gm['losses'];
        echo '<a class="hud-team" href="' . url('manage') . '">' . team_logo($gm['abbr'], $gm['primary_color'] ?? '#333', 'md')
            . '<span class="ht-txt"><b>' . e(teamFull($gm)) . '</b><small><span class="hide-desk">' . $rec . ' · </span>'
            . ($seed ? $seed . 'º no ' . $conf : $conf) . ' · Temporada ' . League::season() . '</small></span></a>';
        echo '<span class="hud-rec" title="Campanha">' . $rec . '</span>';
        echo '<div class="hud-stats">';
        echo '<a class="hud-stat" href="' . url($phase === 'preseason' ? 'preseason' : 'schedule') . '"><small>' . e(phase_label($phase)) . '</small><b>' . e(hud_clock($phase)) . '</b></a>';
        $cs = Cap::summary($gmId);
        $capTxt = $cs['status'] === 'over' ? Cap::m((int) ($cs['excess'] ?? 0)) . ' acima'
                : ($cs['status'] === 'under' ? Cap::m((int) ($cs['deficit'] ?? 0)) . ' abaixo do piso' : Cap::m((int) $cs['space']) . ' livre');
        $capTone = $cs['status'] === 'over' ? 'bad' : ($cs['status'] === 'under' ? 'warn' : 'ok');
        echo '<a class="hud-stat opt" href="' . url('cap') . '"><small>Teto salarial</small><b class="' . $capTone . '">' . e($capTxt) . '</b></a>';
        echo '<a class="hud-stat opt" href="' . url('manage') . '"><small>Diretoria</small>' . pips(League::boardPatience(), League::PATIENCE_MAX) . '</a>';
        echo '</div>';
    } else {
        echo '<a class="hud-team" href="' . url('home') . '"><span class="rb-mark">FBA</span><span class="ht-txt"><b>Simulador</b><small>' . e(phase_label($phase)) . '</small></span></a>';
        echo '<div class="hud-stats"></div>';
    }
    $unread = (int) ($nav['inicio']['badge'] ?? 0);
    echo '<a class="hud-bell" href="' . url('inbox') . '" aria-label="Caixa de entrada' . ($unread ? ", $unread não lidas" : '') . '">'
        . bi('bell-fill') . ($unread ? '<span class="badge">' . min(99, $unread) . '</span>' : '') . '</a>';
    echo '</header>';

    // ── abas da seção (celular) ──
    echo '<nav class="subnav" aria-label="' . e($nav[$sec]['label']) . '">';
    foreach ($nav[$sec]['items'] as $pg => $label) {
        echo '<a class="' . ($pg === $page ? 'on' : '') . '" href="' . url($pg) . '">' . e($label) . sim_item_badge($pg, $nav) . '</a>';
    }
    if ($sec === 'mais') echo '<a href="/games.php">' . bi('controller') . 'Voltar para o Games</a>';
    echo '</nav>';

    // ── barra de abas (celular) ──
    echo '<nav class="tabbar" aria-label="Seções do jogo">';
    foreach ($nav as $key => $s) {
        echo '<a class="' . ($key === $sec ? 'on' : '') . '" href="' . url((string) array_key_first($s['items'])) . '">' . bi($s['icon'])
            . '<span>' . e($s['label']) . '</span>'
            . (!empty($s['badge']) ? '<span class="badge">' . min(99, (int) $s['badge']) . '</span>' : '') . '</a>';
    }
    echo '</nav>';

    echo '<main class="g-content" id="conteudo">';
    render_toasts();
}

/** O dock: a próxima jogada, com o porquê e os atalhos. */
function render_dock(string $page): void
{
    require_once __DIR__ . '/NextStep.php';
    try {
        $s = NextStep::resolve($page);
    } catch (Throwable $e) {
        error_log('dock: ' . $e->getMessage());
        return;
    }
    $alts = $s['alts'] ?? [];
    $more = count($alts) > 2; // no desktop cabem dois atalhos; o resto fica no menu
    echo '<div class="dock tone-' . e($s['tone']) . ($more ? ' has-more' : '') . '" id="dock" data-step="' . e($s['key']) . '"><div class="dock-in">';
    if ($alts || $s['hint'] !== '') {
        echo '<details class="dock-menu"><summary aria-label="Detalhes e outras opções">' . bi('three-dots') . '</summary><div class="dm-list">';
        echo '<div class="dm-hint"><b>' . e($s['kicker']) . ($s['clock'] !== '' ? ' · ' . e($s['clock']) : '') . '</b><br>' . e($s['hint']) . '</div>';
        foreach ($alts as $a) {
            echo '<a href="' . e($a['href']) . '"' . link_attrs($a) . '>' . bi($a['icon'] ?? 'chevron-right') . e($a['label']) . '</a>';
        }
        echo '</div></details>';
    }
    echo '<div class="dock-ctx"><div class="dock-kicker"><span>' . e($s['kicker']) . '</span>'
        . ($s['clock'] !== '' ? '<span class="dk-clock">' . e($s['clock']) . '</span>' : '') . '</div>'
        . ($s['hint'] !== '' ? '<div class="dock-hint" title="' . e($s['hint']) . '">' . e($s['hint']) . '</div>' : '') . '</div>';
    if ($alts) {
        echo '<div class="dock-alts">';
        foreach (array_slice($alts, 0, 2) as $a) {
            echo '<a class="dock-alt" href="' . e($a['href']) . '"' . link_attrs($a) . '>' . bi($a['icon'] ?? 'chevron-right') . e($a['label']) . '</a>';
        }
        echo '</div>';
    }
    echo '<a class="dock-go" href="' . e($s['href']) . '"' . link_attrs($s) . '><span class="dg-txt"><small>Próxima</small><b>'
        . e($s['label']) . '</b></span>' . bi($s['icon']) . '</a>';
    echo '</div></div>';
}

function render_footer(): void
{
    $page = (string) ($GLOBALS['__sim_page'] ?? ($_GET['p'] ?? 'home'));
    echo '</main></div>';
    if (!empty($GLOBALS['__sim_dock'])) render_dock($page);
    echo '</div>';
    echo '<script src="' . e(asset_v('assets/js/app.js')) . '"></script>';
    echo '</body></html>';
}

/** Lobby: telas fora de uma franquia (saves). Sem HUD nem dock. $opt: page, home (href da marca), right (html). */
function render_lobby_header(string $title, array $opt = []): void
{
    $GLOBALS['__sim_page'] = (string) ($opt['page'] ?? ($_GET['p'] ?? 'saves'));
    fba_head($title);
    echo '<body><div class="lobby"><header class="lobby-top">';
    echo '<a class="lobby-brand" href="' . e((string) ($opt['home'] ?? url('saves'))) . '"><span class="rb-mark">FBA</span><span><b>Simulador</b><small>FBA Games</small></span></a>';
    echo '<div class="row">' . ($opt['right'] ?? '') . '<a class="btn btn-sm btn-ghost" href="/games.php">' . bi('controller') . 'Games</a></div>';
    echo '</header><main class="lobby-main" id="conteudo">';
    render_toasts();
}

function render_lobby_footer(): void
{
    echo '</main></div><script src="' . e(asset_v('assets/js/app.js')) . '"></script></body></html>';
}

function render_auth_header(string $title = 'FBA'): void { render_lobby_header($title); }
function render_auth_footer(): void { render_lobby_footer(); }

/* ═════════════════════════════════════════════════════════════════════
   CAIXA DE ENTRADA, DECISÕES, CALENDÁRIO E SCOUTING
   ═════════════════════════════════════════════════════════════════════ */

/**
 * Ações de UMA mensagem da caixa de entrada (decisão, renovação de contrato ou
 * link simples). Qualquer pendência do GM se resolve direto na mensagem, em
 * qualquer tela que mostre a caixa.
 */
function render_inbox_actions(array $m, string $backPage): void
{
    $kind = $m['kind'];

    if ($kind === 'decision_done' || $kind === 'resign_done') {
        echo '<div class="feed-done">' . bi('check2-circle') . 'Resolvido</div>';
        return;
    }

    if ($kind === 'resign' && (int) $m['ref_id'] > 0) {
        $rp = League::player((int) $m['ref_id']);
        if ($rp && (int) $rp['contract_years'] <= 1) {
            $d = League::resignDemand($rp);
            echo '<div class="feed-actions">';
            echo '<a class="btn btn-sm btn-ok" href="' . url('home', ['action' => 'resign', 'pid' => $m['ref_id'], 'choice' => 'accept', 'back' => $backPage]) . '">'
               . bi('pen-fill') . 'Renovar · ' . $d['years'] . ' ' . ($d['years'] > 1 ? 'anos' : 'ano') . ' por ' . money($d['salary']) . '</a>';
            echo '<a class="btn btn-sm btn-ghost" href="' . url('home', ['action' => 'resign', 'pid' => $m['ref_id'], 'choice' => 'reject', 'back' => $backPage]) . '"'
               . ' data-confirm="Recusar a renovação de ' . e($rp['name']) . '?" data-confirm-title="Renovação de contrato" data-confirm-ok="Recusar">Recusar</a>';
            echo '</div>';
        }
        return;
    }

    if ($kind === 'decision' && (int) $m['ref_id'] > 0) {
        $d = League::decision((int) $m['ref_id']);
        if ($d && $d['status'] === 'pending') {
            $opts = json_decode($d['options'], true) ?: [];
            echo '<div class="feed-actions">';
            foreach ($opts as $key => $label) {
                echo '<a class="btn btn-sm" href="' . url('home', ['action' => 'decide', 'id' => $d['id'], 'choice' => $key, 'back' => url($backPage)]) . '">' . e($label) . '</a>';
            }
            echo '</div>';
        }
        return;
    }

    if (!empty($m['link'])) {
        echo '<div class="feed-actions"><a class="more" href="' . e($m['link']) . '">Abrir' . bi('chevron-right') . '</a></div>';
    }
}

/**
 * Separa o emoji que abre um título ("💰 Novo teto" → ['💰', 'Novo teto']). As
 * mensagens do jogo já nascem com emoji no título; no feed ele vira o ícone
 * da linha em vez de aparecer duas vezes.
 */
function split_title_icon(string $title): array
{
    if (preg_match('/^(\X)\s+(.+)$/us', trim($title), $m) && !preg_match('/[\p{L}\p{N}]/u', $m[1])) {
        return [$m[1], $m[2]];
    }
    return [null, $title];
}

/** Uma mensagem no feed. $o: when (texto), unread (bool). */
function render_inbox_msg(array $m, string $backPage, array $o = []): void
{
    [$tIcon, $title] = split_title_icon((string) $m['title']);
    $icon = $tIcon ?? ($m['icon'] ?: '📬');
    $cls = 'feed-item' . (!empty($m['urgent']) ? ' urgent' : '') . (!empty($o['unread']) ? ' unread' : '');
    echo '<article class="' . $cls . '"><div class="feed-ic" aria-hidden="true">' . e($icon) . '</div><div>';
    echo '<div class="feed-top"><span class="feed-from">' . e($m['sender']) . '</span>'
        . (!empty($o['when']) ? '<span class="feed-when">' . e($o['when']) . '</span>' : '') . '</div>';
    echo '<div class="feed-title">' . e($title) . '</div>';
    if (!empty($m['body'])) echo '<div class="feed-text">' . e($m['body']) . '</div>';
    render_inbox_actions($m, $backPage);
    echo '</div></article>';
}

/** Painel de decisões pendentes do GM (âncora #decisoes, alvo do botão Próxima). */
function render_decisions(array $decisions, ?string $back = null): void
{
    if (!$decisions) return;
    $n = count($decisions);
    echo '<section class="panel alert" id="decisoes">';
    echo panel_head($n === 1 ? 'Decisão pendente' : 'Decisões pendentes', ['icon' => 'envelope-exclamation-fill',
        'right' => chip($n === 1 ? 'esperando você' : "$n esperando você", 'warn')]);
    echo '<div class="feed">';
    foreach ($decisions as $d) {
        $opts = json_decode($d['options'], true) ?: [];
        [$tIcon, $title] = split_title_icon((string) $d['title']);
        echo '<article class="feed-item urgent"><div class="feed-ic" aria-hidden="true">' . e($tIcon ?? '📨') . '</div><div>';
        echo '<div class="feed-title">' . e($title) . '</div><div class="feed-text">' . e($d['body']) . '</div><div class="feed-actions">';
        foreach ($opts as $key => $label) {
            $params = ['action' => 'decide', 'id' => $d['id'], 'choice' => $key];
            if ($back) $params['back'] = $back;
            echo '<a class="btn btn-sm" href="' . url('home', $params) . '">' . e($label) . '</a>';
        }
        echo '</div></div></article>';
    }
    echo '</div></section>';
}

/** Mini-calendário de um time: data, mando, adversário e resultado. */
function render_team_schedule(array $games): void
{
    if (!$games) { echo empty_state('Sem jogos para mostrar.', '', 'calendar-x'); return; }
    echo '<div class="table-wrap"><table class="tbl compact"><tbody>';
    foreach ($games as $g) {
        $played = !empty($g['played']);
        echo '<tr>';
        echo '<td class="dim" style="white-space:nowrap">' . e(League::dateLabel((int) $g['day'])) . '</td>';
        echo '<td><a class="who" href="' . url('team', ['id' => $g['opp_id']]) . '"><span class="dim">' . ($g['is_home'] ? 'vs' : '@') . '</span>'
            . team_logo((string) $g['opp_abbr'], (string) ($g['opp_color'] ?? '#333'), 'sm') . '<b>' . e($g['opp_abbr']) . '</b></a></td>';
        if ($played) {
            echo '<td class="num">' . chip(($g['win'] ? 'V ' : 'D ') . (int) $g['my_pts'] . '-' . (int) $g['op_pts'], $g['win'] ? 'ok' : 'bad') . '</td>';
        } else {
            echo '<td class="num"><a class="more" href="' . url('game', ['id' => $g['id']]) . '">Prévia' . bi('chevron-right') . '</a></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

/** Scouting de um adversário: forma, confronto, forças/fraquezas, destaques e plano. */
function render_scout_card(array $r): void
{
    $t = $r['team'];
    echo '<div class="stack-sm">';
    echo '<div class="spread">' . team_who($t, ($t['conf'] === 'E' ? 'Leste' : 'Oeste') . ' · ' . $r['record'] . ' · ' . $r['scheme_off'] . ' / ' . $r['scheme_def'])
        . ($r['form'] ? form_boxes($r['form']) : '') . '</div>';

    if (!empty($r['h2h'])) {
        $h = $r['h2h'];
        $games = '';
        foreach ($h['games'] ?? [] as $gm) {
            $games .= ' ' . chip(($gm['win'] ? 'V ' : 'D ') . (int) $gm['my'] . '-' . (int) $gm['op'], $gm['win'] ? 'ok' : 'bad');
        }
        echo note('Confronto na temporada: <b>' . (int) $h['wins'] . '–' . (int) $h['losses'] . '</b> pra você.'
            . ($games !== '' ? $games : ' Ainda não se enfrentaram.'), 'info', 'people-fill');
    }

    $list = function (array $items): string {
        return $items ? '<ul class="muted">' . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', $items)) . '</ul>' : '<p class="dim">—</p>';
    };
    echo '<div class="grid cols-2">';
    echo '<div><div class="sub-h">Forças</div>' . $list($r['strengths'] ?? []) . '</div>';
    echo '<div><div class="sub-h">Fraquezas</div>' . $list($r['weaknesses'] ?? []) . '</div>';
    echo '</div>';

    echo '<div class="sub-h">Jogadores-chave</div><div class="stack-sm">';
    foreach ($r['players'] as $p) {
        $inj = (int) ($p['injury_games'] ?? 0);
        echo '<div class="spread">' . player_who($p, (string) $p['pos'] . ($inj ? ' · lesionado' : ''), (string) ($t['primary_color'] ?? '#333')) . ovr_badge($p['ovr'], 'sm') . '</div>';
    }
    echo '</div>';

    if (!empty($r['tips'])) {
        echo '<div class="sub-h">Plano sugerido</div>' . $list($r['tips']);
    }
    echo '</div>';
}
