<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
if (($user['email'] ?? '') !== 'medeirros15@gmail.com') {
    http_response_code(403);
    die('Acesso restrito.');
}
$pdo = db();

// ── Times em destaque ─────────────────────────────────────────────────────────
function isVipTeam(string $name): bool {
    $n = strtolower($name);
    return str_contains($n, 'wyvern') || str_contains($n, 'dog') || str_contains($n, 'voidmaker');
}

function teamHtml(string $cityName, string $extraClass = 'team'): string {
    $safe = htmlspecialchars($cityName);
    if (isVipTeam($cityName)) {
        return '<span class="' . $extraClass . ' team-vip">' . $safe . ' ★</span>';
    }
    return '<span class="' . $extraClass . '">' . $safe . '</span>';
}

// ── Helper: label de pick ─────────────────────────────────────────────────────
function pickLabel(PDO $pdo, int $pickId): string {
    try {
        $s = $pdo->prepare('SELECT p.season_year, p.round, t.city, t.name FROM picks p JOIN teams t ON t.id = p.original_team_id WHERE p.id = ?');
        $s->execute([$pickId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return htmlspecialchars("Pick #$pickId");
        return htmlspecialchars($r['season_year'] . ' R' . $r['round'] . ' (' . $r['city'] . ' ' . $r['name'] . ')');
    } catch (Exception) { return htmlspecialchars("Pick #$pickId"); }
}

// ── Helper: label de player (retorna HTML seguro, destaca OVR 90+) ────────────
function playerLabel(PDO $pdo, int $playerId): string {
    try {
        $ovrCol = 'ovr';
        try { if ($pdo->query("SHOW COLUMNS FROM players LIKE 'overall'")->fetch()) $ovrCol = 'overall'; } catch(Exception) {}
        $s = $pdo->prepare("SELECT name, position, $ovrCol AS ovr FROM players WHERE id = ?");
        $s->execute([$playerId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return htmlspecialchars("Player #$playerId");
        $ovr  = (int)$r['ovr'];
        $text = htmlspecialchars($r['name']) . ' (' . htmlspecialchars($r['position'] ?? '?') . ', OVR ' . $ovr . ')';
        if ($ovr >= 90) return '<span class="player-star">' . $text . ' ★</span>';
        return $text;
    } catch (Exception) { return htmlspecialchars("Player #$playerId"); }
}

// ── Helper: itens de uma trade regular ────────────────────────────────────────
function regularItems(PDO $pdo, int $tradeId): array {
    $s = $pdo->prepare('SELECT * FROM trade_items WHERE trade_id = ?');
    $s->execute([$tradeId]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $fromItems = []; $toItems = [];
    foreach ($rows as $it) {
        $label = $it['player_id'] ? playerLabel($pdo, (int)$it['player_id'])
                                  : ($it['pick_id'] ? pickLabel($pdo, (int)$it['pick_id']) : '?');
        if ((int)$it['from_team'] === 1) $fromItems[] = $label;
        else                              $toItems[]   = $label;
    }
    return ['from' => $fromItems, 'to' => $toItems];
}

// ── Helper: itens de uma multi-trade por time ─────────────────────────────────
function multiItems(PDO $pdo, int $tradeId): array {
    $s = $pdo->prepare('SELECT * FROM multi_trade_items WHERE trade_id = ?');
    $s->execute([$tradeId]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    // Agrupa: "Time X → Time Y: item"
    $lines = [];
    foreach ($rows as $it) {
        $label = '';
        if (!empty($it['player_name'])) {
            $ovr   = (int)($it['player_ovr'] ?? 0);
            $label = htmlspecialchars($it['player_name']) . ' (' . htmlspecialchars($it['player_position'] ?? '?') . ', OVR ' . $ovr . ')';
            if ($ovr >= 90) $label = '<span class="player-star">' . $label . ' ★</span>';
        } elseif ($it['player_id']) {
            $label = playerLabel($pdo, (int)$it['player_id']);
        } elseif ($it['pick_id']) {
            $label = pickLabel($pdo, (int)$it['pick_id']);
        } else {
            $label = '?';
        }
        $key = (int)$it['from_team_id'] . '→' . (int)$it['to_team_id'];
        $lines[$key][] = $label;
    }
    return $lines;
}

// ── Helper: tempo relativo ────────────────────────────────────────────────────
function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'agora mesmo';
    if ($diff < 3600)   return floor($diff/60) . 'min atrás';
    if ($diff < 86400)  return floor($diff/3600) . 'h atrás';
    return floor($diff/86400) . 'd atrás';
}

// ── Busca trades regulares NEXT pendentes ─────────────────────────────────────
$regularTrades = [];
try {
    $s = $pdo->prepare("
        SELECT t.*,
               tf.city AS from_city, tf.name AS from_name,
               tt.city AS to_city,   tt.name AS to_name
        FROM trades t
        JOIN teams tf ON tf.id = t.from_team_id
        JOIN teams tt ON tt.id = t.to_team_id
        WHERE t.league = 'NEXT' AND t.status = 'pending'
        ORDER BY t.created_at DESC
    ");
    $s->execute();
    $regularTrades = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// ── Busca multi-trades NEXT pendentes ─────────────────────────────────────────
$multiTrades = [];
try {
    $s = $pdo->prepare("
        SELECT mt.*
        FROM multi_trades mt
        WHERE mt.league = 'NEXT' AND mt.status = 'pending'
        ORDER BY mt.created_at DESC
    ");
    $s->execute();
    $rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $mt) {
        $tid = (int)$mt['id'];
        // Times e status de aceitação
        $st = $pdo->prepare('
            SELECT t.id, t.city, t.name, mtt.accepted_at
            FROM multi_trade_teams mtt
            JOIN teams t ON t.id = mtt.team_id
            WHERE mtt.trade_id = ?
            ORDER BY mtt.id ASC
        ');
        $st->execute([$tid]);
        $mt['teams'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $mt['items'] = multiItems($pdo, $tid);
        $multiTrades[] = $mt;
    }
} catch (Exception $e) {}

$total = count($regularTrades) + count($multiTrades);

// ── Diagnóstico: time Alchimists ──────────────────────────────────────────────
$diagTeam = null;
$diagUser = null;
$diagLeagueSettings = null;
$diagTrades = [];
$diagWarnings = [];
try {
    $s = $pdo->query("SELECT t.*, u.id AS u_id, u.name AS u_name, u.email AS u_email
                       FROM teams t
                       LEFT JOIN users u ON u.id = t.user_id
                       WHERE t.name LIKE '%lchimist%' OR t.city LIKE '%lchimist%'
                       LIMIT 5");
    $diagTeams = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($diagTeams as $dt) {
        $tid = (int)$dt['id'];

        // Sem user associado?
        if (!$dt['user_id']) $diagWarnings[$tid][] = '❌ Sem user_id — time não está vinculado a nenhum usuário';

        // Usuário tem mais de um time na mesma liga?
        if ($dt['user_id']) {
            $sx = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE user_id = ?');
            $sx->execute([$dt['user_id']]);
            $cnt = (int)$sx->fetchColumn();
            if ($cnt > 1) $diagWarnings[$tid][] = "⚠️ Usuário tem {$cnt} times — LIMIT 1 pode pegar o errado";
        }

        // Trades habilitadas na liga?
        $sl = $pdo->prepare('SELECT max_trades, trades_enabled FROM league_settings WHERE league = ?');
        $sl->execute([$dt['league']]);
        $ls = $sl->fetch(PDO::FETCH_ASSOC);
        if ($ls && !(int)($ls['trades_enabled'] ?? 1)) $diagWarnings[$tid][] = '❌ Trades desativadas na liga ' . $dt['league'];

        // Ban de trades?
        $sb = $pdo->prepare('SELECT ban_trades_until_cycle, trades_used, current_cycle FROM teams WHERE id = ?');
        $sb->execute([$tid]);
        $tb = $sb->fetch(PDO::FETCH_ASSOC);
        if ($tb && (int)($tb['ban_trades_until_cycle'] ?? 0) > 0) {
            $banUntil = (int)$tb['ban_trades_until_cycle'];
            $cur = (int)($tb['current_cycle'] ?? 0);
            if ($cur <= $banUntil) $diagWarnings[$tid][] = "❌ Trades banidas até ciclo {$banUntil} (ciclo atual: {$cur})";
        }

        // Limite atingido?
        $maxT = (int)($ls['max_trades'] ?? 10);
        $used = (int)($tb['trades_used'] ?? 0);
        if ($used >= $maxT) $diagWarnings[$tid][] = "❌ Limite de trades atingido: {$used}/{$maxT}";

        if (empty($diagWarnings[$tid])) $diagWarnings[$tid][] = '✅ Nenhum problema encontrado';

        $diagTeams[$tid] = $dt + ['ls' => $ls, 'tb' => $tb];
    }
    $diagTeams = array_combine(array_column($diagTeams, 'id'), $diagTeams);
} catch (Exception $e) { $diagTeams = []; }

// ── Trades aceitas NEXT ───────────────────────────────────────────────────────
$acceptedRegular = [];
try {
    $s = $pdo->prepare("
        SELECT t.*,
               tf.city AS from_city, tf.name AS from_name,
               tt.city AS to_city,   tt.name AS to_name
        FROM trades t
        JOIN teams tf ON tf.id = t.from_team_id
        JOIN teams tt ON tt.id = t.to_team_id
        WHERE t.league = 'NEXT' AND t.status = 'accepted'
          AND t.updated_at >= NOW() - INTERVAL 3 DAY
        ORDER BY t.updated_at DESC
    ");
    $s->execute();
    $acceptedRegular = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

$acceptedMulti = [];
try {
    $s = $pdo->prepare("
        SELECT mt.*
        FROM multi_trades mt
        WHERE mt.league = 'NEXT' AND mt.status = 'accepted'
          AND mt.updated_at >= NOW() - INTERVAL 3 DAY
        ORDER BY mt.updated_at DESC
    ");
    $s->execute();
    $rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $mt) {
        $tid = (int)$mt['id'];
        $st = $pdo->prepare('
            SELECT t.id, t.city, t.name, mtt.accepted_at
            FROM multi_trade_teams mtt
            JOIN teams t ON t.id = mtt.team_id
            WHERE mtt.trade_id = ?
            ORDER BY mtt.id ASC
        ');
        $st->execute([$tid]);
        $mt['teams'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $mt['items'] = multiItems($pdo, $tid);
        $acceptedMulti[] = $mt;
    }
} catch (Exception $e) {}

// Mescla e ordena por data desc
$allAccepted = array_merge(
    array_map(fn($t) => $t + ['_type' => 'regular'], $acceptedRegular),
    array_map(fn($t) => $t + ['_type' => 'multi'],   $acceptedMulti)
);
usort($allAccepted, fn($a, $b) => strtotime($b['updated_at'] ?? $b['created_at']) <=> strtotime($a['updated_at'] ?? $a['created_at']));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dev — Trades Pendentes NEXT</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0d0d10;color:#e5e5e5;font-family:'Inter',system-ui,sans-serif;font-size:14px;padding:24px}
h1{font-size:20px;font-weight:700;margin-bottom:4px;color:#fff}
.sub{color:#888;font-size:13px;margin-bottom:28px}
.section-title{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:12px;padding-bottom:6px;border-bottom:1px solid #222}
.card{background:#161618;border:1px solid #2a2a2e;border-radius:10px;padding:16px;margin-bottom:12px}
.card-header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px}
.badge-pending{background:rgba(251,191,36,.12);color:#fbbf24}
.badge-multi{background:rgba(139,92,246,.12);color:#a78bfa}
.time{font-size:11px;color:#666}
.teams-row{display:flex;align-items:center;gap:8px;font-weight:600;font-size:14px;flex-wrap:wrap}
.team{background:#1e1e22;border:1px solid #2a2a2e;border-radius:6px;padding:4px 10px}
.arrow{color:#555;font-size:13px}
.items-block{margin-top:10px}
.items-label{font-size:11px;color:#666;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em}
.items-list{display:flex;flex-direction:column;gap:3px}
.item{font-size:13px;color:#ccc;padding:4px 8px;background:#1a1a1d;border-radius:5px}
.item-arrow{color:#555;font-size:11px}
.accept-grid{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
.accept-chip{display:flex;align-items:center;gap:6px;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:600}
.accepted{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:#4ade80}
.pending-chip{background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.2);color:#fbbf24}
.dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.dot-ok{background:#4ade80}
.dot-wait{background:#fbbf24}
.empty{text-align:center;padding:48px;color:#555;font-size:14px}
.notes{font-size:12px;color:#888;margin-top:8px;font-style:italic;padding:6px 8px;background:#111;border-radius:5px;border-left:2px solid #2a2a2e}
.multi-items-section{margin-top:10px;border-top:1px solid #222;padding-top:10px}
.multi-move{display:flex;align-items:flex-start;gap:8px;padding:6px 0;border-bottom:1px solid #1a1a1d}
.multi-move:last-child{border-bottom:none}
.tag{font-size:11px;color:#888;white-space:nowrap}
.refresh{position:fixed;top:16px;right:16px;background:#1e1e22;border:1px solid #2a2a2e;color:#888;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px;text-decoration:none}
.refresh:hover{color:#fff;border-color:#444}
section+section{margin-top:32px}
/* ── Destaques ── */
.player-star{color:#fcd34d;font-weight:700}
.team-vip{border-color:#f59e0b !important;color:#fbbf24 !important;text-shadow:0 0 6px rgba(251,191,36,.25)}
</style>
</head>
<body>

<a class="refresh" href="marcos-dev.php">↻ Atualizar</a>

<h1>Trades Pendentes — NEXT</h1>
<p class="sub"><?= $total ?> trade<?= $total !== 1 ? 's' : '' ?> aguardando · atualizado <?= date('H:i:s') ?></p>

<!-- ── Diagnóstico Alchimists ── -->
<?php if ($diagTeams): foreach ($diagTeams as $dt): $tid = (int)$dt['id']; $warnings = $diagWarnings[$tid] ?? []; ?>
<div class="card" style="margin-bottom:20px;border-color:#333">
    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#888;margin-bottom:10px">🔍 Diagnóstico — <?= htmlspecialchars($dt['city'] . ' ' . $dt['name']) ?></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;margin-bottom:12px;font-size:12px">
        <div style="background:#111;padding:8px;border-radius:6px">
            <div style="color:#666;margin-bottom:2px">Time ID</div>
            <div style="color:#ccc">#<?= $tid ?></div>
        </div>
        <div style="background:#111;padding:8px;border-radius:6px">
            <div style="color:#666;margin-bottom:2px">Liga</div>
            <div style="color:#ccc"><?= htmlspecialchars($dt['league'] ?? '—') ?></div>
        </div>
        <div style="background:#111;padding:8px;border-radius:6px">
            <div style="color:#666;margin-bottom:2px">Usuário vinculado</div>
            <div style="color:#ccc"><?= $dt['u_id'] ? '#' . $dt['u_id'] . ' ' . htmlspecialchars($dt['u_name'] ?? '') : '<span style="color:#f87171">Nenhum</span>' ?></div>
        </div>
        <div style="background:#111;padding:8px;border-radius:6px">
            <div style="color:#666;margin-bottom:2px">Email</div>
            <div style="color:#ccc"><?= htmlspecialchars($dt['u_email'] ?? '—') ?></div>
        </div>
        <div style="background:#111;padding:8px;border-radius:6px">
            <div style="color:#666;margin-bottom:2px">Trades usadas / máx</div>
            <div style="color:#ccc"><?= (int)($dt['tb']['trades_used'] ?? 0) ?> / <?= (int)(($dt['ls']['max_trades'] ?? 10)) ?></div>
        </div>
        <div style="background:#111;padding:8px;border-radius:6px">
            <div style="color:#666;margin-bottom:2px">Trades habilitadas</div>
            <div style="color:#ccc"><?= (int)($dt['ls']['trades_enabled'] ?? 1) ? '✅ Sim' : '❌ Não' ?></div>
        </div>
        <div style="background:#111;padding:8px;border-radius:6px">
            <div style="color:#666;margin-bottom:2px">Ban até ciclo</div>
            <div style="color:#ccc"><?= (int)($dt['tb']['ban_trades_until_cycle'] ?? 0) ?: '—' ?> (atual: <?= (int)($dt['tb']['current_cycle'] ?? 0) ?>)</div>
        </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:5px">
        <?php foreach ($warnings as $w): ?>
        <div style="font-size:12px;padding:5px 8px;background:#111;border-radius:5px"><?= htmlspecialchars($w) ?></div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; endif; ?>

<?php if ($total === 0): ?>
<div class="empty">Nenhuma trade pendente na NEXT no momento.</div>
<?php endif; ?>

<?php if ($regularTrades): ?>
<section>
<div class="section-title">Trades 2 times (<?= count($regularTrades) ?>)</div>
<?php foreach ($regularTrades as $t):
    $items = regularItems($pdo, (int)$t['id']);
?>
<div class="card">
    <div class="card-header">
        <div>
            <span class="badge badge-pending">● Pendente</span>
            <span class="time" style="margin-left:8px"><?= timeAgo($t['created_at']) ?> · ID #<?= (int)$t['id'] ?></span>
        </div>
    </div>
    <div class="teams-row">
        <?= teamHtml($t['from_city'] . ' ' . $t['from_name']) ?>
        <span class="arrow">⇄</span>
        <?= teamHtml($t['to_city'] . ' ' . $t['to_name']) ?>
    </div>
    <div class="items-block" style="display:flex;gap:16px;flex-wrap:wrap;margin-top:12px">
        <?php if ($items['from']): ?>
        <div style="flex:1;min-width:160px">
            <div class="items-label">Enviado por <?= htmlspecialchars($t['from_city'] . ' ' . $t['from_name']) ?></div>
            <div class="items-list">
                <?php foreach ($items['from'] as $it): ?>
                <div class="item"><?= $it ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($items['to']): ?>
        <div style="flex:1;min-width:160px">
            <div class="items-label">Enviado por <?= htmlspecialchars($t['to_city'] . ' ' . $t['to_name']) ?></div>
            <div class="items-list">
                <?php foreach ($items['to'] as $it): ?>
                <div class="item"><?= $it ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php if (!empty($t['notes'])): ?>
    <div class="notes"><?= htmlspecialchars($t['notes']) ?></div>
    <?php endif; ?>
    <?php
        $fromFull = $t['from_city'] . ' ' . $t['from_name'];
        $toFull   = $t['to_city']   . ' ' . $t['to_name'];
    ?>
    <div class="accept-grid" style="margin-top:12px">
        <div class="accept-chip accepted<?= isVipTeam($fromFull) ? ' team-vip' : '' ?>"><div class="dot dot-ok"></div><?= htmlspecialchars($fromFull) ?><?= isVipTeam($fromFull) ? ' ★' : '' ?> (propôs)</div>
        <div class="accept-chip pending-chip<?= isVipTeam($toFull) ? ' team-vip' : '' ?>"><div class="dot dot-wait"></div><?= htmlspecialchars($toFull) ?><?= isVipTeam($toFull) ? ' ★' : '' ?> (aguardando)</div>
    </div>
</div>
<?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($multiTrades): ?>
<section>
<div class="section-title">Multi-trades (<?= count($multiTrades) ?>)</div>
<?php foreach ($multiTrades as $mt):
    $pending  = array_filter($mt['teams'], fn($tm) => empty($tm['accepted_at']));
    $accepted = array_filter($mt['teams'], fn($tm) => !empty($tm['accepted_at']));
    $teamMap  = [];
    foreach ($mt['teams'] as $tm) $teamMap[(int)$tm['id']] = $tm['city'] . ' ' . $tm['name'];
?>
<div class="card">
    <div class="card-header">
        <div>
            <span class="badge badge-multi">⬡ Multi-trade</span>
            <span class="badge badge-pending" style="margin-left:6px">● Pendente</span>
            <span class="time" style="margin-left:8px"><?= timeAgo($mt['created_at']) ?> · ID #<?= (int)$mt['id'] ?></span>
        </div>
        <div style="font-size:12px;color:#888"><?= count($accepted) ?>/<?= count($mt['teams']) ?> aceitaram</div>
    </div>

    <!-- Times envolvidos + quem aceitou -->
    <div class="accept-grid">
        <?php foreach ($mt['teams'] as $tm):
            $ok      = !empty($tm['accepted_at']);
            $tmFull  = $tm['city'] . ' ' . $tm['name'];
            $isVip   = isVipTeam($tmFull);
        ?>
        <div class="accept-chip <?= $ok ? 'accepted' : 'pending-chip' ?><?= $isVip ? ' team-vip' : '' ?>">
            <div class="dot <?= $ok ? 'dot-ok' : 'dot-wait' ?>"></div>
            <?= htmlspecialchars($tmFull) ?><?= $isVip ? ' ★' : '' ?>
            <?php if ($ok): ?><span style="font-weight:400;opacity:.7">✓</span><?php else: ?><span style="font-weight:400;opacity:.7">aguardando</span><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Movimentações -->
    <?php if ($mt['items']): ?>
    <div class="multi-items-section">
        <div class="items-label" style="margin-bottom:8px">Movimentações</div>
        <?php foreach ($mt['items'] as $key => $labels):
            [$fromId, $toId] = explode('→', $key);
            $fromName = $teamMap[(int)$fromId] ?? "Time #$fromId";
            $toName   = $teamMap[(int)$toId]   ?? "Time #$toId";
        ?>
        <div class="multi-move">
            <div style="min-width:0;flex:1">
                <div class="tag" style="margin-bottom:4px"><?= htmlspecialchars($fromName) ?> → <?= htmlspecialchars($toName) ?></div>
                <div class="items-list">
                    <?php foreach ($labels as $lbl): ?>
                    <div class="item"><?= $lbl ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($mt['notes'])): ?>
    <div class="notes"><?= htmlspecialchars($mt['notes']) ?></div>
    <?php endif; ?>

    <?php if (count($pending) > 0): ?>
    <div style="margin-top:10px;font-size:12px;color:#fbbf24">
        ⏳ Falta aceitar: <?= implode(', ', array_map(fn($t) => htmlspecialchars($t['city'] . ' ' . $t['name']), $pending)) ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</section>
<?php endif; ?>


<?php if ($allAccepted): ?>
<section style="margin-top:40px">
<div class="section-title" style="color:#4ade80;border-color:#1a3a2a">
    Trades Aceitas — NEXT (<?= count($allAccepted) ?>)
</div>
<?php foreach ($allAccepted as $tr):
    $isMulti = $tr['_type'] === 'multi';
?>
<div class="card" style="opacity:.85">
    <div class="card-header">
        <div>
            <?php if ($isMulti): ?>
            <span class="badge badge-multi">⬡ Multi-trade</span>
            <?php endif; ?>
            <span class="badge" style="background:rgba(34,197,94,.10);color:#4ade80;margin-left:<?= $isMulti ? 6 : 0 ?>px">✓ Aceita</span>
            <span class="time" style="margin-left:8px"><?= timeAgo($tr['updated_at'] ?? $tr['created_at']) ?> · ID #<?= (int)$tr['id'] ?></span>
        </div>
    </div>

    <?php if (!$isMulti): ?>
    <div class="teams-row">
        <?= teamHtml($tr['from_city'] . ' ' . $tr['from_name']) ?>
        <span class="arrow">⇄</span>
        <?= teamHtml($tr['to_city'] . ' ' . $tr['to_name']) ?>
    </div>
    <?php
        $items = regularItems($pdo, (int)$tr['id']);
    ?>
    <div class="items-block" style="display:flex;gap:16px;flex-wrap:wrap;margin-top:12px">
        <?php if ($items['from']): ?>
        <div style="flex:1;min-width:160px">
            <div class="items-label">Enviado por <?= htmlspecialchars($tr['from_city'] . ' ' . $tr['from_name']) ?></div>
            <div class="items-list">
                <?php foreach ($items['from'] as $it): ?>
                <div class="item"><?= $it ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($items['to']): ?>
        <div style="flex:1;min-width:160px">
            <div class="items-label">Enviado por <?= htmlspecialchars($tr['to_city'] . ' ' . $tr['to_name']) ?></div>
            <div class="items-list">
                <?php foreach ($items['to'] as $it): ?>
                <div class="item"><?= $it ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php else: /* multi-trade */ ?>
    <?php
        $teamMap = [];
        foreach ($tr['teams'] as $tm) $teamMap[(int)$tm['id']] = $tm['city'] . ' ' . $tm['name'];
    ?>
    <div class="accept-grid">
        <?php foreach ($tr['teams'] as $tm):
            $tmFull = $tm['city'] . ' ' . $tm['name'];
            $isVip  = isVipTeam($tmFull);
        ?>
        <div class="accept-chip accepted<?= $isVip ? ' team-vip' : '' ?>">
            <div class="dot dot-ok"></div>
            <?= htmlspecialchars($tmFull) ?><?= $isVip ? ' ★' : '' ?> ✓
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($tr['items']): ?>
    <div class="multi-items-section">
        <div class="items-label" style="margin-bottom:8px">Movimentações</div>
        <?php foreach ($tr['items'] as $key => $labels):
            [$fromId, $toId] = explode('→', $key);
            $fromName = $teamMap[(int)$fromId] ?? "Time #$fromId";
            $toName   = $teamMap[(int)$toId]   ?? "Time #$toId";
        ?>
        <div class="multi-move">
            <div style="min-width:0;flex:1">
                <div class="tag" style="margin-bottom:4px"><?= htmlspecialchars($fromName) ?> → <?= htmlspecialchars($toName) ?></div>
                <div class="items-list">
                    <?php foreach ($labels as $lbl): ?>
                    <div class="item"><?= $lbl ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($tr['notes'])): ?>
    <div class="notes"><?= htmlspecialchars($tr['notes']) ?></div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</section>
<?php endif; ?>

</body>
</html>
