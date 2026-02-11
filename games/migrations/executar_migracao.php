<?php
/**
 * EXECUTAR_MIGRACAO.PHP - Executa migrações do banco de dados
 * Este arquivo cria a tabela usuario_avatars no banco de dados
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Parâmetros de conexão
$host = 'localhost';
$db = 'pikafumogames';
$user = 'root';
$pass = '';

try {
    // Conectar ao banco
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // SQL para criar a tabela
    $sql = "
    CREATE TABLE IF NOT EXISTS usuario_avatars (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        color VARCHAR(50) DEFAULT 'default',
        hardware VARCHAR(50) DEFAULT 'none',
        clothing VARCHAR(50) DEFAULT 'none',
        footwear VARCHAR(50) DEFAULT 'none',
        elite VARCHAR(50) DEFAULT 'none',
        aura VARCHAR(50) DEFAULT 'none',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    // Executar
    $pdo->exec($sql);
    
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Tabela usuario_avatars criada com sucesso!'
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro: ' . $e->getMessage()
    ]);
}
?>
