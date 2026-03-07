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

function rarityWeights(): array
{
    return [
        'lendaria' => 0.05,
        'epica' => 0.15,
        'rara' => 0.30,
        'comum' => 0.50,
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

ensureAlbumTables($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? '');

if ($method === 'GET' && $action === 'state') {
    $collection = buildCollectionMap($pdo, $userId);
    jsonSuccessAlbum(['collection' => $collection]);
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
        'collection' => $collection
    ]);
}

jsonErrorAlbum('Acao invalida', 405);
