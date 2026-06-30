<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user     = getUserSession();
$pdo      = db();
$is_admin = hasAdminAccess($pdo, (int)$user['id']);
if (!$is_admin) { http_response_code(403); die('Acesso restrito.'); }

// ── Helper: label de pick ─────────────────────────────────────────────────────
function pickLabel(PDO $pdo, int $pickId): string {
    try {
        $s = $pdo->prepare('SELECT p.season_year, p.round, t.city, t.name FROM picks p JOIN teams t ON t.id = p.original_team_id WHERE p.id = ?');
        $s->execute([$pickId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return "Pick #$pickId";
        return $r['season_year'] . ' R' . $r['round'] . ' (' . $r['city'] . ' ' . $r['name'] . ')';
    } catch (Exception) { return "Pick #$pickId"; }
}

// ── Helper: label de player ───────────────────────────────────────────────────
function playerLabel(PDO $pdo, int $playerId): string {
    try {
        $ovrCol = 'ovr';
        try { if ($pdo->query("SHOW COLUMNS FROM players LIKE 'overall'")->fetch()) $ovrCol = 'overall'; } catch(Exception) {}
        $s = $pdo->prepare("SELECT name, position, $ovrCol AS ovr FROM players WHERE id = ?");
        $s->execute([$playerId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return "Player #$playerId";
        return $r['name'] . ' (' . $r['position'] . ', OVR ' . $r['ovr'] . ')';
    } catch (Exception) { return "Player #$playerId"; }
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
            $label = $it['player_name'] . ' (' . ($it['player_position'] ?? '?') . ', OVR ' . ($it['player_ovr'] ?? '?') . ')';
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
        ORDER BY t.updated_at DESC
        LIMIT 50
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
        ORDER BY mt.updated_at DESC
        LIMIT 50
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
</style>
</head>
<body>

<a class="refresh" href="marcos-dev.php">↻ Atualizar</a>

<h1>Trades Pendentes — NEXT</h1>
<p class="sub"><?= $total ?> trade<?= $total !== 1 ? 's' : '' ?> aguardando · atualizado <?= date('H:i:s') ?></p>

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
        <span class="team"><?= htmlspecialchars($t['from_city'] . ' ' . $t['from_name']) ?></span>
        <span class="arrow">⇄</span>
        <span class="team"><?= htmlspecialchars($t['to_city'] . ' ' . $t['to_name']) ?></span>
    </div>
    <div class="items-block" style="display:flex;gap:16px;flex-wrap:wrap;margin-top:12px">
        <?php if ($items['from']): ?>
        <div style="flex:1;min-width:160px">
            <div class="items-label">Enviado por <?= htmlspecialchars($t['from_city'] . ' ' . $t['from_name']) ?></div>
            <div class="items-list">
                <?php foreach ($items['from'] as $it): ?>
                <div class="item"><?= htmlspecialchars($it) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($items['to']): ?>
        <div style="flex:1;min-width:160px">
            <div class="items-label">Enviado por <?= htmlspecialchars($t['to_city'] . ' ' . $t['to_name']) ?></div>
            <div class="items-list">
                <?php foreach ($items['to'] as $it): ?>
                <div class="item"><?= htmlspecialchars($it) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php if (!empty($t['notes'])): ?>
    <div class="notes"><?= htmlspecialchars($t['notes']) ?></div>
    <?php endif; ?>
    <div class="accept-grid" style="margin-top:12px">
        <div class="accept-chip accepted"><div class="dot dot-ok"></div><?= htmlspecialchars($t['from_city'] . ' ' . $t['from_name']) ?> (propôs)</div>
        <div class="accept-chip pending-chip"><div class="dot dot-wait"></div><?= htmlspecialchars($t['to_city'] . ' ' . $t['to_name']) ?> (aguardando)</div>
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
        <?php foreach ($mt['teams'] as $tm): ?>
        <?php $ok = !empty($tm['accepted_at']); ?>
        <div class="accept-chip <?= $ok ? 'accepted' : 'pending-chip' ?>">
            <div class="dot <?= $ok ? 'dot-ok' : 'dot-wait' ?>"></div>
            <?= htmlspecialchars($tm['city'] . ' ' . $tm['name']) ?>
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
                    <div class="item"><?= htmlspecialchars($lbl) ?></div>
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
        <span class="team"><?= htmlspecialchars($tr['from_city'] . ' ' . $tr['from_name']) ?></span>
        <span class="arrow">⇄</span>
        <span class="team"><?= htmlspecialchars($tr['to_city'] . ' ' . $tr['to_name']) ?></span>
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
                <div class="item"><?= htmlspecialchars($it) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($items['to']): ?>
        <div style="flex:1;min-width:160px">
            <div class="items-label">Enviado por <?= htmlspecialchars($tr['to_city'] . ' ' . $tr['to_name']) ?></div>
            <div class="items-list">
                <?php foreach ($items['to'] as $it): ?>
                <div class="item"><?= htmlspecialchars($it) ?></div>
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
        <?php foreach ($tr['teams'] as $tm): ?>
        <div class="accept-chip accepted">
            <div class="dot dot-ok"></div>
            <?= htmlspecialchars($tm['city'] . ' ' . $tm['name']) ?> ✓
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
                    <div class="item"><?= htmlspecialchars($lbl) ?></div>
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
