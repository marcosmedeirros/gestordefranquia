<?php
$pdo = new PDO('mysql:host=localhost;dbname=pikafumogames;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$sql = file_get_contents('create_loot_tables.sql');
$pdo->exec($sql);

echo json_encode(['sucesso' => true, 'mensagem' => 'Tabela usuario_inventario criada com sucesso!']);
?>
