<?php
// core/conexao.php

$host = 'localhost';
$dbname = 'u289267434_lumacio';
$user = 'u289267434_macoinhas';
$pass = 'Zonete@13';

try {
    // Conexão com PDO
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    
    // Configura o PDO para lançar exceções em caso de erro (bom para debug)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}
?>
