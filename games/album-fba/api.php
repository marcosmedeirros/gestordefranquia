<?php
require_once __DIR__ . '/../../backend/auth.php';
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/helpers.php';

header('Content-Type: application/json');

$secret = 'album2026';
$key = (string)($_GET['k'] ?? '');
if ($key !== $secret) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Nao encontrado']);
    exit;
}

$user = getUserSession();
if (!$user || empty($user['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sessao expirada']);
    exit;
}
$isAdmin = (($_SESSION['is_admin'] ?? false) || (($user['user_type'] ?? '') === 'admin'));

$pdo = db();
$userId = (int)$user['id'];

function jsonErrorAlbum(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function jsonSuccessAlbum(array $payload = []): void
{
    echo json_encode(array_merge(['success' => true], $payload));
    exit;
}

function ensureAlbumTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS album_fba_collection (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        sticker_id VARCHAR(10) NOT NULL,
        quantity INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_album_user_sticker (user_id, sticker_id),
        INDEX idx_album_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS album_fba_market (
        id INT AUTO_INCREMENT PRIMARY KEY,
        seller_user_id INT NOT NULL,
        buyer_user_id INT NULL,
        sticker_id VARCHAR(10) NOT NULL,
        rarity VARCHAR(20) NOT NULL,
        price_points INT NOT NULL,
        status ENUM('active','sold','cancelled') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        sold_at TIMESTAMP NULL DEFAULT NULL,
        cancelled_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_album_market_status_created (status, created_at),
        INDEX idx_album_market_seller_status (seller_user_id, status),
        INDEX idx_album_market_buyer (buyer_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS album_fba_pack_collections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        collection_name VARCHAR(120) NOT NULL,
        in_pack TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_album_pack_collection (collection_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function stickerCatalog(): array
{
    return [
        ['id' => '001', 'name' => 'Ícone da Liga', 'category' => 'Lendas', 'rarity' => 'lendaria', 'image' => 'img/001.png', 'blurb' => 'Símbolo máximo da história FBA.'],
        ['id' => '002', 'name' => 'MVP Histórico', 'category' => 'Lendas', 'rarity' => 'lendaria', 'image' => 'img/002.png', 'blurb' => 'Temporada inesquecível.'],
        ['id' => '010', 'name' => 'All-Star Capitão', 'category' => 'All-Stars', 'rarity' => 'epica', 'image' => 'img/010.png', 'blurb' => 'Referência na conferência.'],
        ['id' => '011', 'name' => 'Showtime Guard', 'category' => 'All-Stars', 'rarity' => 'epica', 'image' => 'img/011.png', 'blurb' => 'Handles e visão de jogo.'],
        ['id' => '020', 'name' => 'Bloqueio Aéreo', 'category' => 'Defesa', 'rarity' => 'rara', 'image' => 'img/020.png', 'blurb' => 'Proteção de garrafão.'],
        ['id' => '021', 'name' => 'Muralha', 'category' => 'Defesa', 'rarity' => 'rara', 'image' => 'img/021.png', 'blurb' => 'Defesa sólida noite após noite.'],
        ['id' => '022', 'name' => 'Ladrão de Bolas', 'category' => 'Defesa', 'rarity' => 'rara', 'image' => 'img/022.png', 'blurb' => 'Transição em ritmo acelerado.'],
        ['id' => '030', 'name' => 'Rookie Sensação', 'category' => 'Novatos', 'rarity' => 'rara', 'image' => 'img/030.png', 'blurb' => 'Chegou fazendo barulho.'],
        ['id' => '031', 'name' => 'Rookie Playmaker', 'category' => 'Novatos', 'rarity' => 'rara', 'image' => 'img/031.png', 'blurb' => 'Assistências cirúrgicas.'],
        ['id' => '040', 'name' => 'Especialista de 3', 'category' => 'Perímetro', 'rarity' => 'comum', 'image' => 'img/040.png', 'blurb' => 'Mão quente do perímetro.'],
        ['id' => '041', 'name' => 'Motor do Time', 'category' => 'Perímetro', 'rarity' => 'comum', 'image' => 'img/041.png', 'blurb' => 'Ritmo constante.'],
        ['id' => '042', 'name' => 'Reboteiro', 'category' => 'Garrafão', 'rarity' => 'comum', 'image' => 'img/042.png', 'blurb' => 'Dono dos rebotes.'],
        ['id' => '043', 'name' => 'Sixth Man', 'category' => 'Elenco', 'rarity' => 'comum', 'image' => 'img/043.png', 'blurb' => 'Energia do banco.'],
        ['id' => '044', 'name' => 'Técnico Estratégico', 'category' => 'Staff', 'rarity' => 'comum', 'image' => 'img/044.png', 'blurb' => 'Planos de jogo afiados.'],
        ['id' => '050', 'name' => 'Mascote Oficial', 'category' => 'Extras', 'rarity' => 'comum', 'image' => 'img/050.png', 'blurb' => 'Carisma na quadra.'],
    ];
}

function uniqueCollections(array $catalog): array
{
    $set = [];
    foreach ($catalog as $sticker) {
        $name = trim((string)($sticker['category'] ?? ''));
        if ($name === '') {
            $name = 'Geral';
        }
        $set[$name] = true;
    }
    $collections = array_keys($set);
    sort($collections);
    return $collections;
}

function ensurePackCollections(PDO $pdo, array $collections): void
{
    if (empty($collections)) {
        return;
    }
    $stmt = $pdo->prepare('INSERT IGNORE INTO album_fba_pack_collections (collection_name, in_pack) VALUES (?, 1)');
    foreach ($collections as $collection) {
        $stmt->execute([$collection]);
    }
}

function fetchPackCollections(PDO $pdo, array $collections): array
{
    ensurePackCollections($pdo, $collections);
    if (empty($collections)) {
        return [];
    }
    $in = implode(',', array_fill(0, count($collections), '?'));
    $stmt = $pdo->prepare("SELECT collection_name, in_pack FROM album_fba_pack_collections WHERE collection_name IN ($in) ORDER BY collection_name");
    $stmt->execute($collections);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function enabledPackCollections(PDO $pdo, array $collections): array
{
    $rows = fetchPackCollections($pdo, $collections);
    $enabled = [];
    foreach ($rows as $row) {
        if ((int)($row['in_pack'] ?? 0) === 1) {
            $enabled[] = $row['collection_name'];
        }
    }
    return $enabled;
}

function rarityWeights(): array
{
    return [
        'lendaria' => 0.05,
        'epica' => 0.15,
        'rara' => 0.30,
        'comum' => 0.50,
    ];
}

function rarityPriceCapsAlbum(): array
{
    return [
        'comum' => 20,
        'rara' => 40,
        'epica' => 60,
        'lendaria' => 100,
    ];
}

function pickRarityAlbum(array $weights): string
{
    $total = array_sum($weights);
    $roll = mt_rand() / mt_getrandmax() * $total;
    $acc = 0;
    foreach ($weights as $rarity => $weight) {
        $acc += $weight;
        if ($roll <= $acc) {
            return $rarity;
        }
    }
    return 'comum';
}

function buildCollectionMap(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT sticker_id, quantity FROM album_fba_collection WHERE user_id = ?');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $row) {
        $map[$row['sticker_id']] = (int)$row['quantity'];
    }
    return $map;
}

function fetchUserPointsAlbum(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT pontos FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function buildStickerMapAlbum(): array
{
    $map = [];
    foreach (stickerCatalog() as $sticker) {
        $map[$sticker['id']] = $sticker;
    }
    return $map;
}

ensureAlbumTables($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? '');

if ($method === 'GET' && $action === 'state') {
    $collection = buildCollectionMap($pdo, $userId);
    jsonSuccessAlbum([
        'collection' => $collection,
        'user_points' => fetchUserPointsAlbum($pdo, $userId),
    ]);
}

if ($method === 'GET' && $action === 'market_state') {
    $collection = buildCollectionMap($pdo, $userId);
    $userPoints = fetchUserPointsAlbum($pdo, $userId);

    $stmtActive = $pdo->prepare("
        SELECT
            m.id,
            m.seller_user_id,
            m.sticker_id,
            m.rarity,
            m.price_points,
            m.created_at,
            u.nome AS seller_name
        FROM album_fba_market m
        JOIN usuarios u ON u.id = m.seller_user_id
        WHERE m.status = 'active'
        ORDER BY m.created_at DESC, m.id DESC
        LIMIT 300
    ");
    $stmtActive->execute();
    $activeListings = $stmtActive->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmtMine = $pdo->prepare("
        SELECT
            id,
            seller_user_id,
            sticker_id,
            rarity,
            price_points,
            created_at
        FROM album_fba_market
        WHERE status = 'active'
          AND seller_user_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 100
    ");
    $stmtMine->execute([$userId]);
    $myListings = $stmtMine->fetchAll(PDO::FETCH_ASSOC) ?: [];

    jsonSuccessAlbum([
        'collection' => $collection,
        'user_points' => $userPoints,
        'active_listings' => $activeListings,
        'my_listings' => $myListings,
        'price_caps' => rarityPriceCapsAlbum(),
    ]);
}

if ($method === 'GET' && $action === 'pack_collections') {
    if (!$isAdmin) {
        jsonErrorAlbum('Somente admin.', 403);
    }
    $catalog = stickerCatalog();
    $collections = uniqueCollections($catalog);
    $rows = fetchPackCollections($pdo, $collections);
    jsonSuccessAlbum(['collections' => $rows]);
}

if ($method === 'POST' && $action === 'pack_collections') {
    if (!$isAdmin) {
        jsonErrorAlbum('Somente admin.', 403);
    }
    $body = readJsonBody();
    $collection = trim((string)($body['collection'] ?? ''));
    $enabled = isset($body['enabled']) ? (int)$body['enabled'] : null;
    if ($collection === '' || $enabled === null) {
        jsonErrorAlbum('Dados invalidos.');
    }
    $catalog = stickerCatalog();
    $collections = uniqueCollections($catalog);
    ensurePackCollections($pdo, $collections);
    $stmt = $pdo->prepare('UPDATE album_fba_pack_collections SET in_pack = ? WHERE collection_name = ?');
    $stmt->execute([$enabled ? 1 : 0, $collection]);
    jsonSuccessAlbum(['collection' => $collection, 'enabled' => $enabled ? 1 : 0]);
}

if ($method === 'POST' && $action === 'create_listing') {
    $body = readJsonBody();
    $stickerId = trim((string)($body['sticker_id'] ?? ''));
    $pricePoints = (int)($body['price_points'] ?? 0);

    $stickerMap = buildStickerMapAlbum();
    $sticker = $stickerMap[$stickerId] ?? null;
    if (!$sticker) {
        jsonErrorAlbum('Cartinha invalida');
    }

    $rarity = (string)$sticker['rarity'];
    $priceCaps = rarityPriceCapsAlbum();
    $minAllowed = (int)($priceCaps[$rarity] ?? 1);
    if ($pricePoints < $minAllowed) {
        jsonErrorAlbum('Preco abaixo do minimo para a raridade da cartinha');
    }

    try {
        $pdo->beginTransaction();

        $stmtQty = $pdo->prepare('
            SELECT quantity
            FROM album_fba_collection
            WHERE user_id = ? AND sticker_id = ?
            FOR UPDATE
        ');
        $stmtQty->execute([$userId, $stickerId]);
        $qty = (int)($stmtQty->fetchColumn() ?: 0);
        if ($qty < 2) {
            $pdo->rollBack();
            jsonErrorAlbum('Voce so pode anunciar cartinhas duplicadas');
        }

        $stmtDecrease = $pdo->prepare('
            UPDATE album_fba_collection
            SET quantity = quantity - 1
            WHERE user_id = ? AND sticker_id = ? AND quantity >= 2
        ');
        $stmtDecrease->execute([$userId, $stickerId]);
        if ($stmtDecrease->rowCount() !== 1) {
            $pdo->rollBack();
            jsonErrorAlbum('Nao foi possivel reservar a cartinha para venda');
        }

        $stmtInsert = $pdo->prepare('
            INSERT INTO album_fba_market (seller_user_id, sticker_id, rarity, price_points, status)
            VALUES (?, ?, ?, ?, "active")
        ');
        $stmtInsert->execute([$userId, $stickerId, $rarity, $pricePoints]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonErrorAlbum('Erro ao criar anuncio');
    }

    jsonSuccessAlbum([]);
}

if ($method === 'POST' && $action === 'cancel_listing') {
    $body = readJsonBody();
    $listingId = (int)($body['listing_id'] ?? 0);
    if ($listingId <= 0) {
        jsonErrorAlbum('Anuncio invalido');
    }

    try {
        $pdo->beginTransaction();

        $stmtListing = $pdo->prepare('
            SELECT id, seller_user_id, sticker_id, status
            FROM album_fba_market
            WHERE id = ?
            FOR UPDATE
        ');
        $stmtListing->execute([$listingId]);
        $listing = $stmtListing->fetch(PDO::FETCH_ASSOC);
        if (!$listing || $listing['status'] !== 'active') {
            $pdo->rollBack();
            jsonErrorAlbum('Anuncio nao esta mais ativo');
        }
        if ((int)$listing['seller_user_id'] !== $userId) {
            $pdo->rollBack();
            jsonErrorAlbum('Voce nao pode cancelar este anuncio', 403);
        }

        $stmtCancel = $pdo->prepare('
            UPDATE album_fba_market
            SET status = "cancelled", cancelled_at = NOW()
            WHERE id = ? AND status = "active"
        ');
        $stmtCancel->execute([$listingId]);
        if ($stmtCancel->rowCount() !== 1) {
            $pdo->rollBack();
            jsonErrorAlbum('Nao foi possivel cancelar o anuncio');
        }

        $stmtReturnSticker = $pdo->prepare('
            INSERT INTO album_fba_collection (user_id, sticker_id, quantity)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE quantity = quantity + 1
        ');
        $stmtReturnSticker->execute([$userId, $listing['sticker_id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonErrorAlbum('Erro ao cancelar anuncio');
    }

    jsonSuccessAlbum([]);
}

if ($method === 'POST' && $action === 'buy_listing') {
    $body = readJsonBody();
    $listingId = (int)($body['listing_id'] ?? 0);
    if ($listingId <= 0) {
        jsonErrorAlbum('Anuncio invalido');
    }

    try {
        $pdo->beginTransaction();

        $stmtListing = $pdo->prepare('
            SELECT id, seller_user_id, sticker_id, price_points, status
            FROM album_fba_market
            WHERE id = ?
            FOR UPDATE
        ');
        $stmtListing->execute([$listingId]);
        $listing = $stmtListing->fetch(PDO::FETCH_ASSOC);
        if (!$listing || $listing['status'] !== 'active') {
            $pdo->rollBack();
            jsonErrorAlbum('Anuncio nao esta mais ativo');
        }

        $sellerId = (int)$listing['seller_user_id'];
        $pricePoints = (int)$listing['price_points'];
        if ($sellerId === $userId) {
            $pdo->rollBack();
            jsonErrorAlbum('Voce nao pode comprar o proprio anuncio');
        }

        $stmtBuyer = $pdo->prepare('SELECT pontos FROM usuarios WHERE id = ? FOR UPDATE');
        $stmtBuyer->execute([$userId]);
        $buyerPoints = (int)($stmtBuyer->fetchColumn() ?: 0);
        if ($buyerPoints < $pricePoints) {
            $pdo->rollBack();
            jsonErrorAlbum('Pontos insuficientes para comprar esta cartinha');
        }

        $stmtSeller = $pdo->prepare('SELECT pontos FROM usuarios WHERE id = ? FOR UPDATE');
        $stmtSeller->execute([$sellerId]);
        $sellerExists = $stmtSeller->fetchColumn();
        if ($sellerExists === false) {
            $pdo->rollBack();
            jsonErrorAlbum('Vendedor invalido');
        }

        $stmtDebitBuyer = $pdo->prepare('UPDATE usuarios SET pontos = pontos - ? WHERE id = ?');
        $stmtDebitBuyer->execute([$pricePoints, $userId]);

        $stmtCreditSeller = $pdo->prepare('UPDATE usuarios SET pontos = pontos + ? WHERE id = ?');
        $stmtCreditSeller->execute([$pricePoints, $sellerId]);

        $stmtAddSticker = $pdo->prepare('
            INSERT INTO album_fba_collection (user_id, sticker_id, quantity)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE quantity = quantity + 1
        ');
        $stmtAddSticker->execute([$userId, $listing['sticker_id']]);

        $stmtMarkSold = $pdo->prepare('
            UPDATE album_fba_market
            SET status = "sold", buyer_user_id = ?, sold_at = NOW()
            WHERE id = ? AND status = "active"
        ');
        $stmtMarkSold->execute([$userId, $listingId]);
        if ($stmtMarkSold->rowCount() !== 1) {
            $pdo->rollBack();
            jsonErrorAlbum('Nao foi possivel concluir a compra');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonErrorAlbum('Erro ao concluir compra');
    }

    jsonSuccessAlbum([]);
}

if ($method === 'POST' && $action === 'open_pack') {
    $body = readJsonBody();
    $count = (int)($body['count'] ?? 3);
    if ($count < 1) {
        $count = 1;
    }
    if ($count > 3) {
        $count = 3;
    }
    if ($count === 1 && !$isAdmin) {
        jsonErrorAlbum('Somente admin pode adicionar cartinha manualmente', 403);
    }

    $catalog = stickerCatalog();
    $collections = uniqueCollections($catalog);
    $enabledCollections = enabledPackCollections($pdo, $collections);
    if (!empty($collections) && empty($enabledCollections)) {
        jsonErrorAlbum('Nenhuma colecao ativa para pacotinhos.', 400);
    }
    if (!empty($enabledCollections)) {
        $catalog = array_values(array_filter($catalog, static function (array $sticker) use ($enabledCollections): bool {
            $collection = trim((string)($sticker['category'] ?? ''));
            if ($collection === '') {
                $collection = 'Geral';
            }
            return in_array($collection, $enabledCollections, true);
        }));
    }
    if (empty($catalog)) {
        jsonErrorAlbum('Nenhuma cartinha disponivel para pacotinhos.', 400);
    }
    $weights = rarityWeights();
    $byRarity = [];
    foreach ($catalog as $sticker) {
        $rarity = $sticker['rarity'];
        if (!isset($byRarity[$rarity])) {
            $byRarity[$rarity] = [];
        }
        $byRarity[$rarity][] = $sticker;
    }

    $pulled = [];
    $stmtUpsert = $pdo->prepare('
        INSERT INTO album_fba_collection (user_id, sticker_id, quantity)
        VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE quantity = quantity + 1
    ');

    for ($i = 0; $i < $count; $i++) {
        $rarity = pickRarityAlbum($weights);
        $pool = $byRarity[$rarity] ?? [];
        if (!$pool) {
            $pool = $catalog;
        }
        $picked = $pool[array_rand($pool)];
        $stmtUpsert->execute([$userId, $picked['id']]);
        $pulled[] = $picked;
    }

    $collection = buildCollectionMap($pdo, $userId);
    jsonSuccessAlbum([
        'pack' => $pulled,
        'collection' => $collection,
        'user_points' => fetchUserPointsAlbum($pdo, $userId),
    ]);
}

jsonErrorAlbum('Acao invalida', 405);
