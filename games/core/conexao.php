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

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM usuarios LIKE 'acertos_eventos'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN acertos_eventos INT NOT NULL DEFAULT 0 AFTER fba_points");
            $pdo->exec("
                UPDATE usuarios u
                LEFT JOIN (
                    SELECT p.id_usuario AS user_id, COUNT(*) AS acertos
                    FROM palpites p
                    JOIN opcoes o ON p.opcao_id = o.id
                    JOIN eventos e ON o.evento_id = e.id
                    WHERE e.status = 'encerrada'
                      AND e.vencedor_opcao_id IS NOT NULL
                      AND e.vencedor_opcao_id = p.opcao_id
                    GROUP BY p.id_usuario
                ) t ON t.user_id = u.id
                SET u.acertos_eventos = COALESCE(t.acertos, 0)
            ");
        }
    } catch (PDOException $e) {
        // Silencia erro de ajuste de schema para nao quebrar a conexao
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM usuarios LIKE 'tapas_disponiveis'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN tapas_disponiveis INT NOT NULL DEFAULT 2 AFTER numero_tapas");
            $pdo->exec("UPDATE usuarios SET tapas_disponiveis = 2 WHERE tapas_disponiveis IS NULL");
        }
    } catch (PDOException $e) {
        // Silencia erro de ajuste de schema para nao quebrar a conexao
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fba_shop_purchases (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            item VARCHAR(30) NOT NULL,
            qty INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_item_date (user_id, item, created_at)
        )");
    } catch (PDOException $e) {
        // Silencia erro de ajuste de schema para nao quebrar a conexao
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS poker_salas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            status VARCHAR(20) NOT NULL DEFAULT 'esperando',
            stage VARCHAR(20) NOT NULL DEFAULT 'showdown',
            pote INT NOT NULL DEFAULT 0,
            bet_atual INT NOT NULL DEFAULT 0,
            community_cards VARCHAR(255) NOT NULL DEFAULT '',
            deck TEXT NULL,
            turno_posicao INT NULL,
            vencedor_info VARCHAR(255) NULL,
            vencedor_mao VARCHAR(50) NULL
        )");
        $pdo->exec("INSERT IGNORE INTO poker_salas (id, status, stage, pote, bet_atual, community_cards, deck, turno_posicao, vencedor_info)
            VALUES (1, 'esperando', 'showdown', 0, 0, '', '', NULL, NULL)");
    } catch (PDOException $e) {
        // Silencia erro de ajuste de schema para nao quebrar a conexao
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM poker_salas LIKE 'vencedor_mao'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE poker_salas ADD COLUMN vencedor_mao VARCHAR(50) NULL AFTER vencedor_info");
        }
    } catch (PDOException $e) {
        // Silencia erro de ajuste de schema para nao quebrar a conexao
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS poker_jogadores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_sala INT NOT NULL,
            id_usuario INT NOT NULL,
            nome VARCHAR(120) NOT NULL,
            chips INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'ativo',
            posicao INT NOT NULL,
            cards VARCHAR(20) NOT NULL DEFAULT '',
            bet_round INT NOT NULL DEFAULT 0,
            pronto TINYINT(1) NOT NULL DEFAULT 0,
            aguardando TINYINT(1) NOT NULL DEFAULT 0,
            pronto_deadline DATETIME NULL,
            UNIQUE KEY uniq_sala_usuario (id_sala, id_usuario),
            UNIQUE KEY uniq_sala_posicao (id_sala, posicao)
        )");
    } catch (PDOException $e) {
        // Silencia erro de ajuste de schema para nao quebrar a conexao
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM poker_jogadores LIKE 'pronto'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE poker_jogadores ADD COLUMN pronto TINYINT(1) NOT NULL DEFAULT 0 AFTER bet_round");
        }
    } catch (PDOException $e) {
        // Silencia erro de ajuste de schema para nao quebrar a conexao
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM poker_jogadores LIKE 'aguardando'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE poker_jogadores ADD COLUMN aguardando TINYINT(1) NOT NULL DEFAULT 0 AFTER pronto");
        }
    } catch (PDOException $e) {
        // Silencia erro de ajuste de schema para nao quebrar a conexao
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM poker_jogadores LIKE 'pronto_deadline'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE poker_jogadores ADD COLUMN pronto_deadline DATETIME NULL AFTER aguardando");
        }
    } catch (PDOException $e) {
        // Silencia erro de ajuste de schema para nao quebrar a conexao
    }
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}
?>
