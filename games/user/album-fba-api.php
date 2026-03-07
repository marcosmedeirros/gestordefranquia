<?php
session_start();
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Não autenticado']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

function out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array
{
    $raw = file_get_contents('php://input');
    $obj = $raw ? json_decode($raw, true) : null;
    return is_array($obj) ? $obj : [];
}

function schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS fba_card_teams (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(120) NOT NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fba_cards (
        id INT AUTO_INCREMENT PRIMARY KEY, team_id INT NOT NULL, nome VARCHAR(120) NOT NULL, posicao VARCHAR(10) NOT NULL,
        raridade ENUM('comum','rara','epico','lendario') NOT NULL, ovr INT NOT NULL, img_url VARCHAR(500) NOT NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1, created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_team (team_id), INDEX idx_rarity (raridade)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fba_user_collection (
        user_id INT NOT NULL, card_id INT NOT NULL, quantidade INT NOT NULL DEFAULT 1, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, card_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fba_user_team (
        user_id INT PRIMARY KEY, slot_pg INT NULL, slot_sg INT NULL, slot_sf INT NULL, slot_pf INT NULL, slot_c INT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function packs(): array
{
    return [
        'basico' => ['price' => 30, 'cards' => 2, 'rates' => ['lendario' => 1, 'epico' => 4, 'rara' => 20, 'comum' => 75]],
        'premium' => ['price' => 60, 'cards' => 2, 'rates' => ['lendario' => 5, 'epico' => 15, 'rara' => 30, 'comum' => 50]],
        'ultra' => ['price' => 100, 'cards' => 3, 'rates' => ['lendario' => 10, 'epico' => 25, 'rara' => 40, 'comum' => 25]],
    ];
}

function master(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT c.id, t.nome team, c.nome, c.posicao, c.raridade, c.ovr, c.img_url
        FROM fba_cards c INNER JOIN fba_card_teams t ON t.id = c.team_id WHERE c.ativo = 1 ORDER BY t.nome, c.ovr DESC, c.nome");
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id' => (int)$r['id'], 'team' => $r['team'], 'name' => $r['nome'], 'position' => strtoupper($r['posicao']), 'rarity' => $r['raridade'], 'ovr' => (int)$r['ovr'], 'img' => $r['img_url']];
    }
    return $out;
}

function collection(PDO $pdo, int $uid): array
{
    $stmt = $pdo->prepare("SELECT card_id, quantidade FROM fba_user_collection WHERE user_id = :u");
    $stmt->execute([':u' => $uid]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['card_id']] = (int)$r['quantidade'];
    return $out;
}

function team(PDO $pdo, int $uid): array
{
    $stmt = $pdo->prepare("SELECT slot_pg,slot_sg,slot_sf,slot_pf,slot_c FROM fba_user_team WHERE user_id=:u");
    $stmt->execute([':u' => $uid]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return [null, null, null, null, null];
    return [$r['slot_pg'] ? (int)$r['slot_pg'] : null, $r['slot_sg'] ? (int)$r['slot_sg'] : null, $r['slot_sf'] ? (int)$r['slot_sf'] : null, $r['slot_pf'] ? (int)$r['slot_pf'] : null, $r['slot_c'] ? (int)$r['slot_c'] : null];
}

function ranking(PDO $pdo): array
{
    $sql = "SELECT u.nome, COALESCE(c1.ovr,0)+COALESCE(c2.ovr,0)+COALESCE(c3.ovr,0)+COALESCE(c4.ovr,0)+COALESCE(c5.ovr,0) total_ovr
        FROM usuarios u
        LEFT JOIN fba_user_team uq ON uq.user_id=u.id
        LEFT JOIN fba_cards c1 ON c1.id=uq.slot_pg
        LEFT JOIN fba_cards c2 ON c2.id=uq.slot_sg
        LEFT JOIN fba_cards c3 ON c3.id=uq.slot_sf
        LEFT JOIN fba_cards c4 ON c4.id=uq.slot_pf
        LEFT JOIN fba_cards c5 ON c5.id=uq.slot_c
        ORDER BY total_ovr DESC, u.nome ASC LIMIT 50";
    $stmt = $pdo->query($sql);
    return array_map(static fn($r) => ['name' => $r['nome'], 'ovr' => (int)$r['total_ovr']], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function roll(array $rates): string
{
    $n = mt_rand(1, 100);
    if ($n <= (int)$rates['lendario']) return 'lendario';
    if ($n <= (int)$rates['lendario'] + (int)$rates['epico']) return 'epico';
    if ($n <= (int)$rates['lendario'] + (int)$rates['epico'] + (int)$rates['rara']) return 'rara';
    return 'comum';
}

function draw(PDO $pdo, array $rates): ?array
{
    $rar = roll($rates);
    $stmt = $pdo->prepare("SELECT c.id, t.nome team, c.nome, c.posicao, c.raridade, c.ovr, c.img_url
        FROM fba_cards c INNER JOIN fba_card_teams t ON t.id=c.team_id WHERE c.ativo=1 AND c.raridade=:r ORDER BY RAND() LIMIT 1");
    $stmt->execute([':r' => $rar]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        $stmt2 = $pdo->query("SELECT c.id, t.nome team, c.nome, c.posicao, c.raridade, c.ovr, c.img_url
            FROM fba_cards c INNER JOIN fba_card_teams t ON t.id=c.team_id WHERE c.ativo=1 ORDER BY RAND() LIMIT 1");
        $c = $stmt2->fetch(PDO::FETCH_ASSOC);
    }
    if (!$c) return null;
    return ['id' => (int)$c['id'], 'team' => $c['team'], 'name' => $c['nome'], 'position' => strtoupper($c['posicao']), 'rarity' => $c['raridade'], 'ovr' => (int)$c['ovr'], 'img' => $c['img_url']];
}

schema($pdo);

$meStmt = $pdo->prepare("SELECT nome, pontos, is_admin FROM usuarios WHERE id=:id");
$meStmt->execute([':id' => $user_id]);
$me = $meStmt->fetch(PDO::FETCH_ASSOC);
if (!$me) out(['ok' => false, 'message' => 'Usuário inválido'], 400);
$is_admin = ((int)($me['is_admin'] ?? 0) === 1);

$action = $_GET['action'] ?? '';

if ($action === 'bootstrap') out(['ok' => true, 'user' => ['name' => $me['nome'], 'coins' => (int)$me['pontos'], 'is_admin' => $is_admin], 'master_data' => master($pdo), 'collection' => collection($pdo, $user_id), 'my_team' => team($pdo, $user_id), 'ranking' => ranking($pdo), 'pack_types' => packs()]);
if ($action === 'ranking') out(['ok' => true, 'ranking' => ranking($pdo)]);

if ($action === 'buy_pack') {
    $b = body();
    $type = (string)($b['packType'] ?? '');
    $cfg = packs();
    if (!isset($cfg[$type])) out(['ok' => false, 'message' => 'Pacote inválido'], 400);
    try {
        $pdo->beginTransaction();
        $u = $pdo->prepare("SELECT pontos FROM usuarios WHERE id=:id FOR UPDATE");
        $u->execute([':id' => $user_id]);
        $coins = (int)$u->fetchColumn();
        if ($coins < (int)$cfg[$type]['price']) {
            $pdo->rollBack();
            out(['ok' => false, 'message' => 'Moedas insuficientes'], 400);
        }
        $d = $pdo->prepare("UPDATE usuarios SET pontos = pontos - :p WHERE id=:id");
        $d->execute([':p' => (int)$cfg[$type]['price'], ':id' => $user_id]);
        $coll = collection($pdo, $user_id);
        $cards = [];
        $bonusPoints = 0;
        $cardsToDraw = max(1, (int)($cfg[$type]['cards'] ?? 3));
        for ($i = 0; $i < $cardsToDraw; $i++) {
            $card = draw($pdo, $cfg[$type]['rates']);
            if (!$card) {
                $pdo->rollBack();
                out(['ok' => false, 'message' => 'Sem cartas cadastradas'], 400);
            }
            $isNew = !isset($coll[$card['id']]);
            $ins = $pdo->prepare("INSERT INTO fba_user_collection (user_id, card_id, quantidade) VALUES (:u,:c,1) ON DUPLICATE KEY UPDATE quantidade=quantidade+1");
            $ins->execute([':u' => $user_id, ':c' => $card['id']]);
            $coll[$card['id']] = ($coll[$card['id']] ?? 0) + 1;
            if ($coll[$card['id']] >= 5) {
                $bonusPoints += 3;
            }
            $card['is_new'] = $isNew;
            $cards[] = $card;
        }
        if ($bonusPoints > 0) {
            $bonus = $pdo->prepare("UPDATE usuarios SET pontos = pontos + :b WHERE id = :id");
            $bonus->execute([':b' => $bonusPoints, ':id' => $user_id]);
        }
        $n = $pdo->prepare("SELECT pontos FROM usuarios WHERE id=:id");
        $n->execute([':id' => $user_id]);
        $final = (int)$n->fetchColumn();
        $pdo->commit();
        out(['ok' => true, 'coins' => $final, 'cards' => $cards, 'collection' => $coll, 'bonus_points' => $bonusPoints]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        out(['ok' => false, 'message' => 'Erro ao abrir pacote'], 500);
    }
}

if ($action === 'save_team') {
    $b = body();
    $arr = $b['team'] ?? null;
    if (!is_array($arr) || count($arr) !== 5) out(['ok' => false, 'message' => 'Time inválido'], 400);
    $slots = [];
    foreach ($arr as $v) $slots[] = ($v && (int)$v > 0) ? (int)$v : null;
    $nn = array_values(array_filter($slots, static fn($x) => $x !== null));
    if (count($nn) !== count(array_unique($nn))) out(['ok' => false, 'message' => 'Carta repetida no quinteto'], 400);
    if ($nn) {
        $ph = implode(',', array_fill(0, count($nn), '?'));
        $q = $pdo->prepare("SELECT card_id FROM fba_user_collection WHERE user_id=? AND card_id IN ($ph)");
        $q->execute(array_merge([$user_id], $nn));
        if (count($q->fetchAll(PDO::FETCH_COLUMN)) !== count($nn)) out(['ok' => false, 'message' => 'Você não possui todas as cartas'], 400);

        $qPos = $pdo->prepare("SELECT id, UPPER(posicao) AS posicao FROM fba_cards WHERE id IN ($ph)");
        $qPos->execute($nn);
        $posById = [];
        foreach ($qPos->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $posById[(int)$row['id']] = (string)$row['posicao'];
        }
        $expectedBySlot = ['PG', 'SG', 'SF', 'PF', 'C'];
        foreach ($slots as $idx => $cardId) {
            if (!$cardId) continue;
            $expectedPos = $expectedBySlot[$idx];
            $actualPos = $posById[(int)$cardId] ?? null;
            if ($actualPos !== $expectedPos) {
                out(['ok' => false, 'message' => "A carta selecionada para {$expectedPos} deve ser da mesma posição."], 400);
            }
        }
    }
    $s = $pdo->prepare("INSERT INTO fba_user_team (user_id,slot_pg,slot_sg,slot_sf,slot_pf,slot_c) VALUES (:u,:pg,:sg,:sf,:pf,:c)
        ON DUPLICATE KEY UPDATE slot_pg=VALUES(slot_pg),slot_sg=VALUES(slot_sg),slot_sf=VALUES(slot_sf),slot_pf=VALUES(slot_pf),slot_c=VALUES(slot_c)");
    $s->execute([':u' => $user_id, ':pg' => $slots[0], ':sg' => $slots[1], ':sf' => $slots[2], ':pf' => $slots[3], ':c' => $slots[4]]);
    out(['ok' => true]);
}

if ($action === 'admin_create_card') {
    if (!$is_admin) out(['ok' => false, 'message' => 'Sem permissão'], 403);
    $b = body();
    $team = trim((string)($b['team_name'] ?? ''));
    $name = trim((string)($b['card_name'] ?? ''));
    $pos = strtoupper(trim((string)($b['position'] ?? '')));
    $rar = trim((string)($b['rarity'] ?? ''));
    $ovr = (int)($b['ovr'] ?? 0);
    $img = trim((string)($b['img_url'] ?? ''));
    if ($team === '' || $name === '' || $img === '' || !in_array($pos, ['PG', 'SG', 'SF', 'PF', 'C'], true) || !in_array($rar, ['comum', 'rara', 'epico', 'lendario'], true) || $ovr < 50 || $ovr > 99) {
        out(['ok' => false, 'message' => 'Dados inválidos'], 400);
    }
    try {
        $pdo->beginTransaction();
        $t = $pdo->prepare("INSERT INTO fba_card_teams (nome) VALUES (:n) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
        $t->execute([':n' => $team]);
        $teamId = (int)$pdo->lastInsertId();
        $c = $pdo->prepare("INSERT INTO fba_cards (team_id,nome,posicao,raridade,ovr,img_url,created_by) VALUES (:t,:n,:p,:r,:o,:i,:u)");
        $c->execute([':t' => $teamId, ':n' => $name, ':p' => $pos, ':r' => $rar, ':o' => $ovr, ':i' => $img, ':u' => $user_id]);
        $cardId = (int)$pdo->lastInsertId();
        $pdo->commit();
        out(['ok' => true, 'card' => ['id' => $cardId, 'team' => $team, 'name' => $name, 'position' => $pos, 'rarity' => $rar, 'ovr' => $ovr, 'img' => $img]]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        out(['ok' => false, 'message' => 'Erro ao cadastrar carta'], 500);
    }
}

out(['ok' => false, 'message' => 'Ação inválida'], 400);
