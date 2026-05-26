<?php
// Define timezone padrão para todo o sistema: São Paulo/Brasília
date_default_timezone_set('America/Sao_Paulo');

// Inicia sessão apenas se ainda não foi iniciada, mantendo usuário logado por mais tempo
if (session_status() === PHP_SESSION_NONE) {
    $sessionLifetime = 60 * 60 * 24 * 30; // 30 dias
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);

    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function requireAuth() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
    
    // Verificar se o usuário está aprovado
    if (isset($_SESSION['user_approved']) && $_SESSION['user_approved'] == 0) {
        // Se não estiver na página de aprovação pendente, redireciona
        $currentPage = basename($_SERVER['PHP_SELF']);
        if ($currentPage !== 'pending-approval.php' && $currentPage !== 'logout.php') {
            header('Location: /pending-approval.php');
            exit;
        }
    }
}

function getUserSession() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'] ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'user_type' => $_SESSION['user_type'] ?? 'jogador',
        'league' => $_SESSION['user_league'] ?? 'ROOKIE',
        'photo_url' => $_SESSION['user_photo'] ?? null,
        'phone' => $_SESSION['user_phone'] ?? null,
        'approved' => $_SESSION['user_approved'] ?? 1,
    ];
}

function setUserSession($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_type'] = $user['user_type'];
    $_SESSION['user_league'] = $user['league'];
    $_SESSION['user_photo'] = $user['photo_url'] ?? null;
    $_SESSION['user_phone'] = $user['phone'] ?? null;
    $_SESSION['user_approved'] = $user['approved'] ?? 1;
}

function destroyUserSession() {
    session_destroy();
    $_SESSION = [];
}

function getAdminLeagues(PDO $pdo, int $userId): array {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS league_admins (
            user_id INT NOT NULL,
            league  VARCHAR(20) NOT NULL,
            PRIMARY KEY (user_id, league)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    $stmtUser = $pdo->prepare("SELECT user_type FROM users WHERE id = ? LIMIT 1");
    $stmtUser->execute([$userId]);
    $userType = $stmtUser->fetchColumn();

    if ($userType === 'admin') {
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM league_admins WHERE user_id = ?");
        $stmtCount->execute([$userId]);
        if ((int)$stmtCount->fetchColumn() === 0) {
            foreach (['ELITE', 'NEXT', 'RISE', 'ROOKIE'] as $l) {
                try {
                    $pdo->prepare("INSERT IGNORE INTO league_admins (user_id, league) VALUES (?, ?)")
                        ->execute([$userId, $l]);
                } catch (Exception $e) {}
            }
        }
    }

    $stmt = $pdo->prepare("SELECT league FROM league_admins WHERE user_id = ? ORDER BY league");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function isLeagueAdmin(PDO $pdo, int $userId, string $league): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM league_admins WHERE user_id = ? AND league = ? LIMIT 1");
    $stmt->execute([$userId, strtoupper($league)]);
    return (bool)$stmt->fetch();
}

// Retorna true para admin global (user_type='admin') OU admin de qualquer liga (league_admins)
function hasAdminAccess(PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare("SELECT user_type FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn() === 'admin') return true;
    $stmt2 = $pdo->prepare("SELECT 1 FROM league_admins WHERE user_id = ? LIMIT 1");
    $stmt2->execute([$userId]);
    return (bool)$stmt2->fetch();
}
