<?php
session_start();
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';

$user = getUserSession();
if (!$user || $user['user_type'] !== 'admin') {
    die('Acesso negado');
}

$pdo = db();

// Criar um novo time de teste
$newTeam = [
    'user_id' => 2,  // Você pode ajustar isso
    'league' => 'ROOKIE',
    'conference' => 'OESTE',
    'name' => 'Test Team',
    'city' => 'Test City',
    'mascot' => 'Test Mascot',
    'photo_url' => NULL
];

$stmt = $pdo->prepare('
    INSERT INTO teams (user_id, league, conference, name, city, mascot, photo_url)
    VALUES (?, ?, ?, ?, ?, ?, ?)
');

try {
    $result = $stmt->execute([
        $newTeam['user_id'],
        $newTeam['league'],
        $newTeam['conference'],
        $newTeam['name'],
        $newTeam['city'],
        $newTeam['mascot'],
        $newTeam['photo_url']
    ]);
    
    $teamId = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Time criado com sucesso!',
        'team_id' => $teamId,
        'team' => $newTeam
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
