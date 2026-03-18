<?php
// core/conexao.php

// Garantir que as funções de data usem o fuso horário de Brasília
date_default_timezone_set('America/Sao_Paulo');

$host = 'localhost';
$dbname = 'u289267434_gamesfba';
$user = 'u289267434_gamesfba';
$pass = 'Gamesfba@123';

try {
    // Conexão com PDO
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    
    // Configura o PDO para lançar exceções em caso de erro (bom para debug)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM usuarios LIKE 'fba_points'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN fba_points INT NOT NULL DEFAULT 0 AFTER pontos");
        }
    } catch (PDOException $e) {
        // Silencia erro de ajuste de schema para nao quebrar a conexao
    }
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}
?>
