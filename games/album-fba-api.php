<?php
session_start();
require 'core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'NÃ£o autenticado']);
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS fba_market_listings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        seller_user_id INT NOT NULL,
        buyer_user_id INT NULL,
        card_id INT NOT NULL,
        price_points INT NOT NULL,
        status ENUM('active','sold','cancelled') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        sold_at TIMESTAMP NULL DEFAULT NULL,
        cancelled_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_market_status (status, created_at),
        INDEX idx_market_seller (seller_user_id, status),
        INDEX idx_market_card (card_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $col = $pdo->query("SHOW COLUMNS FROM fba_cards LIKE 'collection_name'");
    if (!$col || $col->rowCount() === 0) {
        $pdo->exec("ALTER TABLE fba_cards ADD COLUMN collection_name VARCHAR(120) NOT NULL DEFAULT 'Geral' AFTER team_id");
        $pdo->exec("ALTER TABLE fba_cards ADD INDEX idx_collection (collection_name)");
    }
}
function packs(): array
{
    return [
        'basico' => ['price' => 30, 'cards' => 1, 'rates' => ['lendario' => 1, 'epico' => 4, 'rara' => 20, 'comum' => 75]],
        'premium' => ['price' => 60, 'cards' => 1, 'rates' => ['lendario' => 5, 'epico' => 15, 'rara' => 30, 'comum' => 50]],
        'ultra' => ['price' => 100, 'cards' => 2, 'rates' => ['lendario' => 10, 'epico' => 25, 'rara' => 40, 'comum' => 25]],
    ];
}
function master(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT c.id, COALESCE(c.collection_name, 'Geral') colecao, t.nome team, c.nome, c.posicao, c.raridade, c.ovr, c.img_url, c.created_at
        FROM fba_cards c INNER JOIN fba_card_teams t ON t.id = c.team_id WHERE c.ativo = 1 ORDER BY colecao, t.nome, c.ovr DESC, c.nome");
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id' => (int)$r['id'], 'collection' => $r['colecao'], 'team' => $r['team'], 'name' => $r['nome'], 'position' => strtoupper($r['posicao']), 'rarity' => $r['raridade'], 'ovr' => (int)$r['ovr'], 'img' => $r['img_url'], 'created_at' => $r['created_at']];
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
    $sql = "SELECT u.id user_id, u.nome, COALESCE(c1.ovr,0)+COALESCE(c2.ovr,0)+COALESCE(c3.ovr,0)+COALESCE(c4.ovr,0)+COALESCE(c5.ovr,0) total_ovr
        FROM usuarios u
        LEFT JOIN fba_user_team uq ON uq.user_id=u.id
        LEFT JOIN fba_cards c1 ON c1.id=uq.slot_pg
        LEFT JOIN fba_cards c2 ON c2.id=uq.slot_sg
        LEFT JOIN fba_cards c3 ON c3.id=uq.slot_sf
        LEFT JOIN fba_cards c4 ON c4.id=uq.slot_pf
        LEFT JOIN fba_cards c5 ON c5.id=uq.slot_c
        ORDER BY total_ovr DESC, u.nome ASC LIMIT 50";
    $stmt = $pdo->query($sql);
    return array_map(static fn($r) => ['user_id' => (int)$r['user_id'], 'name' => $r['nome'], 'ovr' => (int)$r['total_ovr']], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function rankingTeam(PDO $pdo, int $targetUserId): array
{
    $stmt = $pdo->prepare("SELECT u.nome, ut.slot_pg, ut.slot_sg, ut.slot_sf, ut.slot_pf, ut.slot_c
        FROM usuarios u
        LEFT JOIN fba_user_team ut ON ut.user_id = u.id
        WHERE u.id = :id
        LIMIT 1");
    $stmt->execute([':id' => $targetUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        out(['ok' => false, 'message' => 'Usuário não encontrado'], 404);
    }

    $slots = [
        'PG' => $row['slot_pg'] ? (int)$row['slot_pg'] : null,
        'SG' => $row['slot_sg'] ? (int)$row['slot_sg'] : null,
        'SF' => $row['slot_sf'] ? (int)$row['slot_sf'] : null,
        'PF' => $row['slot_pf'] ? (int)$row['slot_pf'] : null,
        'C' => $row['slot_c'] ? (int)$row['slot_c'] : null,
    ];

    $cardIds = array_values(array_filter($slots, static fn($id) => $id !== null));
    $cardsById = [];
    if ($cardIds) {
        $ph = implode(',', array_fill(0, count($cardIds), '?'));
        $q = $pdo->prepare("SELECT c.id, COALESCE(c.collection_name, 'Geral') AS colecao, t.nome AS team, c.nome, c.posicao, c.raridade, c.ovr, c.img_url
            FROM fba_cards c
            INNER JOIN fba_card_teams t ON t.id = c.team_id
            WHERE c.id IN ($ph)");
        $q->execute($cardIds);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $card) {
            $cardsById[(int)$card['id']] = [
                'id' => (int)$card['id'],
                'collection' => $card['colecao'],
                'team' => $card['team'],
                'name' => $card['nome'],
                'position' => strtoupper((string)$card['posicao']),
                'rarity' => $card['raridade'],
                'ovr' => (int)$card['ovr'],
                'img' => $card['img_url'],
            ];
        }
    }

    $lineup = [];
    $total = 0;
    foreach ($slots as $position => $cardId) {
        $card = $cardId ? ($cardsById[$cardId] ?? null) : null;
        if ($card) {
            $total += (int)$card['ovr'];
        }
        $lineup[] = [
            'slot' => $position,
            'card' => $card,
        ];
    }

    return [
        'name' => $row['nome'],
        'ovr' => $total,
        'lineup' => $lineup,
    ];
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
    $excludedCollection = 'Rookie Stars';
    $stmt = $pdo->prepare("SELECT c.id, COALESCE(c.collection_name, 'Geral') colecao, t.nome team, c.nome, c.posicao, c.raridade, c.ovr, c.img_url
        FROM fba_cards c INNER JOIN fba_card_teams t ON t.id=c.team_id WHERE c.ativo=1 AND c.raridade=:r AND COALESCE(c.collection_name, 'Geral') <> :exclude ORDER BY RAND() LIMIT 1");
    $stmt->execute([':r' => $rar, ':exclude' => $excludedCollection]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        $stmt2 = $pdo->prepare("SELECT c.id, COALESCE(c.collection_name, 'Geral') colecao, t.nome team, c.nome, c.posicao, c.raridade, c.ovr, c.img_url
            FROM fba_cards c INNER JOIN fba_card_teams t ON t.id=c.team_id WHERE c.ativo=1 AND COALESCE(c.collection_name, 'Geral') <> :exclude ORDER BY RAND() LIMIT 1");
        $stmt2->execute([':exclude' => $excludedCollection]);
        $c = $stmt2->fetch(PDO::FETCH_ASSOC);
    }
    if (!$c) return null;
    return ['id' => (int)$c['id'], 'collection' => $c['colecao'], 'team' => $c['team'], 'name' => $c['nome'], 'position' => strtoupper($c['posicao']), 'rarity' => $c['raridade'], 'ovr' => (int)$c['ovr'], 'img' => $c['img_url']];
}

function marketPriceCaps(): array
{
    return ['comum' => 20, 'rara' => 40, 'epico' => 60, 'lendario' => 100];
}

function cardById(PDO $pdo, int $cardId): ?array
{
    $stmt = $pdo->prepare("SELECT id, COALESCE(collection_name, 'Geral') collection_name, nome, raridade, ativo FROM fba_cards WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $cardId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'collection' => (string)$row['collection_name'],
        'name' => (string)$row['nome'],
        'rarity' => (string)$row['raridade'],
        'active' => (int)$row['ativo'] === 1
    ];
}
schema($pdo);

$meStmt = $pdo->prepare("SELECT nome, pontos, is_admin FROM usuarios WHERE id=:id");
$meStmt->execute([':id' => $user_id]);
$me = $meStmt->fetch(PDO::FETCH_ASSOC);
if (!$me) out(['ok' => false, 'message' => 'UsuÃ¡rio invÃ¡lido'], 400);
$is_admin = ((int)($me['is_admin'] ?? 0) === 1);

$action = $_GET['action'] ?? '';

if ($action === 'bootstrap') out(['ok' => true, 'user' => ['id' => $user_id, 'name' => $me['nome'], 'coins' => (int)$me['pontos'], 'is_admin' => $is_admin], 'master_data' => master($pdo), 'collection' => collection($pdo, $user_id), 'my_team' => team($pdo, $user_id), 'ranking' => ranking($pdo), 'pack_types' => packs()]);
if ($action === 'ranking') out(['ok' => true, 'ranking' => ranking($pdo)]);
if ($action === 'ranking_team') {
    $targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    if ($targetUserId <= 0) {
        out(['ok' => false, 'message' => 'Usuário inválido'], 400);
    }
    out(['ok' => true, 'team' => rankingTeam($pdo, $targetUserId)]);
}

if ($action === 'market_state') {
    $listStmt = $pdo->query("
        SELECT m.id, m.seller_user_id, m.card_id, m.price_points, m.created_at,
               u.nome seller_name,
               c.nome card_name, c.raridade card_rarity, COALESCE(c.collection_name, 'Geral') card_collection,
               t.nome card_team, c.img_url card_img
        FROM fba_market_listings m
        INNER JOIN usuarios u ON u.id = m.seller_user_id
        INNER JOIN fba_cards c ON c.id = m.card_id
        INNER JOIN fba_card_teams t ON t.id = c.team_id
        WHERE m.status = 'active'
        ORDER BY m.created_at DESC, m.id DESC
        LIMIT 500
    ");
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $myRows = array_values(array_filter($rows, static fn($r) => (int)$r['seller_user_id'] === $user_id));
    $coinsStmt = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id");
    $coinsStmt->execute([':id' => $user_id]);
    $coins = (int)$coinsStmt->fetchColumn();

    out([
        'ok' => true,
        'coins' => $coins,
        'collection' => collection($pdo, $user_id),
        'listings' => $rows,
        'my_listings' => $myRows,
        'price_caps' => marketPriceCaps()
    ]);
}

if ($action === 'market_create_listing') {
    $b = body();
    $cardId = (int)($b['card_id'] ?? 0);
    $price = (int)($b['price_points'] ?? 0);
    if ($cardId <= 0 || $price <= 0) {
        out(['ok' => false, 'message' => 'Dados invalidos'], 400);
    }

    $card = cardById($pdo, $cardId);
    if (!$card || !$card['active']) {
        out(['ok' => false, 'message' => 'Carta invalida'], 400);
    }
    $caps = marketPriceCaps();
    $max = (int)($caps[$card['rarity']] ?? 0);
    if ($max <= 0 || $price > $max) {
        out(['ok' => false, 'message' => 'Preco acima do limite da raridade'], 400);
    }

    try {
        $pdo->beginTransaction();
        $qtyStmt = $pdo->prepare("SELECT quantidade FROM fba_user_collection WHERE user_id = :u AND card_id = :c FOR UPDATE");
        $qtyStmt->execute([':u' => $user_id, ':c' => $cardId]);
        $qty = (int)$qtyStmt->fetchColumn();
        if ($qty < 2) {
            $pdo->rollBack();
            out(['ok' => false, 'message' => 'So e possivel anunciar cartas duplicadas'], 400);
        }

        $dec = $pdo->prepare("UPDATE fba_user_collection SET quantidade = quantidade - 1 WHERE user_id = :u AND card_id = :c AND quantidade >= 2");
        $dec->execute([':u' => $user_id, ':c' => $cardId]);
        if ($dec->rowCount() !== 1) {
            $pdo->rollBack();
            out(['ok' => false, 'message' => 'Falha ao reservar carta'], 400);
        }

        $ins = $pdo->prepare("INSERT INTO fba_market_listings (seller_user_id, card_id, price_points, status) VALUES (:s, :c, :p, 'active')");
        $ins->execute([':s' => $user_id, ':c' => $cardId, ':p' => $price]);
        $pdo->commit();
        out(['ok' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        out(['ok' => false, 'message' => 'Erro ao criar anuncio'], 500);
    }
}

if ($action === 'market_cancel_listing') {
    $b = body();
    $listingId = (int)($b['listing_id'] ?? 0);
    if ($listingId <= 0) {
        out(['ok' => false, 'message' => 'Anuncio invalido'], 400);
    }

    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare("SELECT id, seller_user_id, card_id, status FROM fba_market_listings WHERE id = :id FOR UPDATE");
        $q->execute([':id' => $listingId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'active') {
            $pdo->rollBack();
            out(['ok' => false, 'message' => 'Anuncio nao esta ativo'], 400);
        }
        if ((int)$row['seller_user_id'] !== $user_id) {
            $pdo->rollBack();
            out(['ok' => false, 'message' => 'Sem permissao para cancelar'], 403);
        }

        $up = $pdo->prepare("UPDATE fba_market_listings SET status = 'cancelled', cancelled_at = NOW() WHERE id = :id AND status = 'active'");
        $up->execute([':id' => $listingId]);
        if ($up->rowCount() !== 1) {
            $pdo->rollBack();
            out(['ok' => false, 'message' => 'Falha ao cancelar anuncio'], 400);
        }

        $ret = $pdo->prepare("INSERT INTO fba_user_collection (user_id, card_id, quantidade) VALUES (:u, :c, 1) ON DUPLICATE KEY UPDATE quantidade = quantidade + 1");
        $ret->execute([':u' => $user_id, ':c' => (int)$row['card_id']]);

        $pdo->commit();
        out(['ok' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        out(['ok' => false, 'message' => 'Erro ao cancelar anuncio'], 500);
    }
}

if ($action === 'market_buy_listing') {
    $b = body();
    $listingId = (int)($b['listing_id'] ?? 0);
    if ($listingId <= 0) {
        out(['ok' => false, 'message' => 'Anuncio invalido'], 400);
    }

    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare("SELECT id, seller_user_id, card_id, price_points, status FROM fba_market_listings WHERE id = :id FOR UPDATE");
        $q->execute([':id' => $listingId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'active') {
            $pdo->rollBack();
            out(['ok' => false, 'message' => 'Anuncio nao esta ativo'], 400);
        }
        $sellerId = (int)$row['seller_user_id'];
        if ($sellerId === $user_id) {
            $pdo->rollBack();
            out(['ok' => false, 'message' => 'Nao pode comprar o proprio anuncio'], 400);
        }
        $price = (int)$row['price_points'];

        $buyerQ = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE");
        $buyerQ->execute([':id' => $user_id]);
        $buyerCoins = (int)$buyerQ->fetchColumn();
        if ($buyerCoins < $price) {
            $pdo->rollBack();
            out(['ok' => false, 'message' => 'Pontos insuficientes'], 400);
        }

        $sellerQ = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE");
        $sellerQ->execute([':id' => $sellerId]);
        if ($sellerQ->fetchColumn() === false) {
            $pdo->rollBack();
            out(['ok' => false, 'message' => 'Vendedor invalido'], 400);
        }

        $pdo->prepare("UPDATE usuarios SET pontos = pontos - :p WHERE id = :id")->execute([':p' => $price, ':id' => $user_id]);
        $pdo->prepare("UPDATE usuarios SET pontos = pontos + :p WHERE id = :id")->execute([':p' => $price, ':id' => $sellerId]);
        $pdo->prepare("INSERT INTO fba_user_collection (user_id, card_id, quantidade) VALUES (:u, :c, 1) ON DUPLICATE KEY UPDATE quantidade = quantidade + 1")
            ->execute([':u' => $user_id, ':c' => (int)$row['card_id']]);
        $done = $pdo->prepare("UPDATE fba_market_listings SET status = 'sold', buyer_user_id = :b, sold_at = NOW() WHERE id = :id AND status = 'active'");
        $done->execute([':b' => $user_id, ':id' => $listingId]);
        if ($done->rowCount() !== 1) {
            $pdo->rollBack();
            out(['ok' => false, 'message' => 'Falha ao concluir compra'], 400);
        }
        $pdo->commit();
        out(['ok' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        out(['ok' => false, 'message' => 'Erro ao comprar carta'], 500);
    }
}

if ($action === 'buy_pack') {
    $b = body();
    $type = (string)($b['packType'] ?? '');
    $cfg = packs();
    if (!isset($cfg[$type])) out(['ok' => false, 'message' => 'Pacote invÃ¡lido'], 400);
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

if ($action === 'clear_team') {
    $s = $pdo->prepare("INSERT INTO fba_user_team (user_id,slot_pg,slot_sg,slot_sf,slot_pf,slot_c) VALUES (:u,NULL,NULL,NULL,NULL,NULL)
        ON DUPLICATE KEY UPDATE slot_pg=NULL,slot_sg=NULL,slot_sf=NULL,slot_pf=NULL,slot_c=NULL");
    $s->execute([':u' => $user_id]);
    out(['ok' => true, 'my_team' => [null, null, null, null, null]]);
}

if ($action === 'save_team') {
    $b = body();
    $arr = $b['team'] ?? null;
    if (!is_array($arr) || count($arr) !== 5) out(['ok' => false, 'message' => 'Time invÃ¡lido'], 400);
    $slots = [];
    foreach ($arr as $v) $slots[] = ($v && (int)$v > 0) ? (int)$v : null;
    $nn = array_values(array_filter($slots, static fn($x) => $x !== null));
    if (count($nn) !== count(array_unique($nn))) out(['ok' => false, 'message' => 'Carta repetida no quinteto'], 400);
    if ($nn) {
        $ph = implode(',', array_fill(0, count($nn), '?'));
        $q = $pdo->prepare("SELECT card_id FROM fba_user_collection WHERE user_id=? AND card_id IN ($ph)");
        $q->execute(array_merge([$user_id], $nn));
        if (count($q->fetchAll(PDO::FETCH_COLUMN)) !== count($nn)) out(['ok' => false, 'message' => 'VocÃª nÃ£o possui todas as cartas'], 400);

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
                out(['ok' => false, 'message' => "A carta selecionada para {$expectedPos} deve ser da mesma posiÃ§Ã£o."], 400);
            }
        }
    }
    $s = $pdo->prepare("INSERT INTO fba_user_team (user_id,slot_pg,slot_sg,slot_sf,slot_pf,slot_c) VALUES (:u,:pg,:sg,:sf,:pf,:c)
        ON DUPLICATE KEY UPDATE slot_pg=VALUES(slot_pg),slot_sg=VALUES(slot_sg),slot_sf=VALUES(slot_sf),slot_pf=VALUES(slot_pf),slot_c=VALUES(slot_c)");
    $s->execute([':u' => $user_id, ':pg' => $slots[0], ':sg' => $slots[1], ':sf' => $slots[2], ':pf' => $slots[3], ':c' => $slots[4]]);
    out(['ok' => true]);
}

if ($action === 'admin_create_card') {
    if (!$is_admin) out(['ok' => false, 'message' => 'Sem permissÃ£o'], 403);
    $collection = trim((string)($_POST['collection_name'] ?? ''));
    $team = trim((string)($_POST['team_name'] ?? ''));
    $name = trim((string)($_POST['card_name'] ?? ''));
    $pos = strtoupper(trim((string)($_POST['position'] ?? '')));
    $rar = trim((string)($_POST['rarity'] ?? ''));
    $ovr = (int)($_POST['ovr'] ?? 0);

    if ($collection === '' || $team === '' || $name === '' || !in_array($pos, ['PG', 'SG', 'SF', 'PF', 'C'], true) || !in_array($rar, ['comum', 'rara', 'epico', 'lendario'], true) || $ovr < 50 || $ovr > 99) {
        out(['ok' => false, 'message' => 'Dados invÃ¡lidos'], 400);
    }
    if (!isset($_FILES['card_image']) || !is_array($_FILES['card_image']) || (int)($_FILES['card_image']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        out(['ok' => false, 'message' => 'Upload da imagem Ã© obrigatÃ³rio'], 400);
    }

    $imgFile = $_FILES['card_image'];
    $tmpPath = $imgFile['tmp_name'] ?? '';
    $mime = '';
    if (is_string($tmpPath) && $tmpPath !== '' && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
            $mime = is_string($detected) ? $detected : '';
        }
    }
    if ($mime === '') {
        $mime = (string)($imgFile['type'] ?? '');
    }
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];
    if (!isset($allowed[$mime])) {
        out(['ok' => false, 'message' => 'Formato invÃ¡lido. Use JPG, PNG ou WEBP'], 400);
    }

    try {
        $pdo->beginTransaction();
        $t = $pdo->prepare("INSERT INTO fba_card_teams (nome) VALUES (:n) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
        $t->execute([':n' => $team]);
        $teamId = (int)$pdo->lastInsertId();

        $c = $pdo->prepare("INSERT INTO fba_cards (team_id,collection_name,nome,posicao,raridade,ovr,img_url,created_by) VALUES (:t,:c,:n,:p,:r,:o,'',:u)");
        $c->execute([':t' => $teamId, ':c' => $collection, ':n' => $name, ':p' => $pos, ':r' => $rar, ':o' => $ovr, ':u' => $user_id]);
        $cardId = (int)$pdo->lastInsertId();

        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'album-fba' . DIRECTORY_SEPARATOR . 'figuras';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('NÃ£o foi possÃ­vel criar diretÃ³rio de imagens');
        }

        $ext = $allowed[$mime];
        $finalName = $cardId . '.' . $ext;
        $finalPath = $dir . DIRECTORY_SEPARATOR . $finalName;

        if (!move_uploaded_file($tmpPath, $finalPath)) {
            throw new RuntimeException('Falha ao salvar imagem');
        }

        $img = 'album-fba/figuras/' . $finalName;
        $u = $pdo->prepare("UPDATE fba_cards SET img_url = :img WHERE id = :id");
        $u->execute([':img' => $img, ':id' => $cardId]);

        $pdo->commit();
        out(['ok' => true, 'card' => ['id' => $cardId, 'collection' => $collection, 'team' => $team, 'name' => $name, 'position' => $pos, 'rarity' => $rar, 'ovr' => $ovr, 'img' => $img]]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[album-fba] admin_create_card erro: ' . $e->getMessage());
        out(['ok' => false, 'message' => 'Erro ao cadastrar carta: ' . $e->getMessage()], 500);
    }
}


if ($action === 'admin_update_card') {
    if (!$is_admin) out(['ok' => false, 'message' => 'Sem permissão'], 403);
    $cardId = (int)($_POST['card_id'] ?? 0);
    $collection = trim((string)($_POST['collection_name'] ?? ''));
    $team = trim((string)($_POST['team_name'] ?? ''));
    $name = trim((string)($_POST['card_name'] ?? ''));
    $pos = strtoupper(trim((string)($_POST['position'] ?? '')));
    $rar = trim((string)($_POST['rarity'] ?? ''));
    $ovr = (int)($_POST['ovr'] ?? 0);

    if ($cardId <= 0 || $collection === '' || $team === '' || $name === '' || !in_array($pos, ['PG', 'SG', 'SF', 'PF', 'C'], true) || !in_array($rar, ['comum', 'rara', 'epico', 'lendario'], true) || $ovr < 50 || $ovr > 99) {
        out(['ok' => false, 'message' => 'Dados inválidos'], 400);
    }
    $stmtCard = $pdo->prepare("SELECT id, img_url FROM fba_cards WHERE id = :id AND ativo = 1");
    $stmtCard->execute([':id' => $cardId]);
    $currentCard = $stmtCard->fetch(PDO::FETCH_ASSOC);
    if (!$currentCard) {
        out(['ok' => false, 'message' => 'Carta não encontrada'], 404);
    }

    $newImg = (string)($currentCard['img_url'] ?? '');
    $hasUpload = isset($_FILES['card_image']) && is_array($_FILES['card_image']) && (int)($_FILES['card_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

    if ($hasUpload) {
        $imgFile = $_FILES['card_image'];
        $tmpPath = $imgFile['tmp_name'] ?? '';
        $mime = '';
        if (is_string($tmpPath) && $tmpPath !== '' && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
                $mime = is_string($detected) ? $detected : '';
            }
        }
        if ($mime === '') {
            $mime = (string)($imgFile['type'] ?? '');
        }
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];
        if (!isset($allowed[$mime])) {
            out(['ok' => false, 'message' => 'Formato inválido. Use JPG, PNG ou WEBP'], 400);
        }

        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'album-fba' . DIRECTORY_SEPARATOR . 'figuras';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            out(['ok' => false, 'message' => 'Falha ao criar diretório de imagens'], 500);
        }
        $ext = $allowed[$mime];
        $finalName = $cardId . '.' . $ext;
        $finalPath = $dir . DIRECTORY_SEPARATOR . $finalName;
        if (!move_uploaded_file($tmpPath, $finalPath)) {
            out(['ok' => false, 'message' => 'Falha ao salvar nova imagem'], 500);
        }
        $newImg = 'album-fba/figuras/' . $finalName;
    }

    try {
        $pdo->beginTransaction();
        $t = $pdo->prepare("INSERT INTO fba_card_teams (nome) VALUES (:n) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
        $t->execute([':n' => $team]);
        $teamId = (int)$pdo->lastInsertId();

        $u = $pdo->prepare("UPDATE fba_cards SET team_id = :t, collection_name = :c, nome = :n, posicao = :p, raridade = :r, ovr = :o, img_url = :i WHERE id = :id");
        $u->execute([
            ':t' => $teamId,
            ':c' => $collection,
            ':n' => $name,
            ':p' => $pos,
            ':r' => $rar,
            ':o' => $ovr,
            ':i' => $newImg,
            ':id' => $cardId
        ]);

        $pdo->commit();
        out(['ok' => true, 'card' => ['id' => $cardId, 'collection' => $collection, 'team' => $team, 'name' => $name, 'position' => $pos, 'rarity' => $rar, 'ovr' => $ovr, 'img' => $newImg]]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[album-fba] admin_update_card erro: ' . $e->getMessage());
        out(['ok' => false, 'message' => 'Erro ao atualizar carta: ' . $e->getMessage()], 500);
    }
}

if ($action === 'admin_delete_card') {
    if (!$is_admin) out(['ok' => false, 'message' => 'Sem permissão'], 403);
    $b = body();
    $cardId = (int)($b['card_id'] ?? 0);
    if ($cardId <= 0) {
        out(['ok' => false, 'message' => 'Carta inválida'], 400);
    }

    try {
        $pdo->beginTransaction();
        $check = $pdo->prepare("SELECT id FROM fba_cards WHERE id = :id");
        $check->execute([':id' => $cardId]);
        if (!$check->fetchColumn()) {
            $pdo->rollBack();
            out(['ok' => false, 'message' => 'Carta não encontrada'], 404);
        }

        $pdo->prepare("DELETE FROM fba_user_collection WHERE card_id = :id")->execute([':id' => $cardId]);
        $pdo->prepare("UPDATE fba_user_team SET slot_pg = NULL WHERE slot_pg = :id")->execute([':id' => $cardId]);
        $pdo->prepare("UPDATE fba_user_team SET slot_sg = NULL WHERE slot_sg = :id")->execute([':id' => $cardId]);
        $pdo->prepare("UPDATE fba_user_team SET slot_sf = NULL WHERE slot_sf = :id")->execute([':id' => $cardId]);
        $pdo->prepare("UPDATE fba_user_team SET slot_pf = NULL WHERE slot_pf = :id")->execute([':id' => $cardId]);
        $pdo->prepare("UPDATE fba_user_team SET slot_c = NULL WHERE slot_c = :id")->execute([':id' => $cardId]);
        $pdo->prepare("UPDATE fba_cards SET ativo = 0 WHERE id = :id")->execute([':id' => $cardId]);

        $pdo->commit();
        out(['ok' => true, 'deleted_id' => $cardId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[album-fba] admin_delete_card erro: ' . $e->getMessage());
        out(['ok' => false, 'message' => 'Erro ao excluir carta: ' . $e->getMessage()], 500);
    }
}
out(['ok' => false, 'message' => 'AÃ§Ã£o invÃ¡lida'], 400);
