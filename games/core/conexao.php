<?php
// core/conexao.php
//
// Ponto único de integração do games com o fbabrasil.com.br. Depois da fusão
// este arquivo não abre mais conexão própria nem tem login próprio: usa o
// banco e a sessão do site. Como as 55 páginas do games já dão require nele,
// trocar aqui liga o sistema inteiro de uma vez.

// Garantir que as funções de data usem o fuso horário de Brasília
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/auth.php';

// Quem manda no acesso é o site — não existe mais login do games.
requireAuth();

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Schema do games: roda uma vez só, quando a tabela de perfil ainda não
    // existe. Em regime normal custa uma consulta barata por request — as
    // outras tabelas do games cada página cria sozinha, como sempre fez.
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'games_usuarios'")->fetch()) {
            $__schema = @file_get_contents(__DIR__ . '/../../sql/games_merge.sql');
            if ($__schema !== false) {
                $pdo->exec($__schema);
            }
        }
    } catch (PDOException $e) {
        error_log('[games] schema inicial: ' . $e->getMessage());
    }

    // Perfil de jogo do GM: nasce sozinho no primeiro acesso, espelhando
    // nome/e-mail/liga do cadastro do site. O id é o mesmo do users.
    $__uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($__uid > 0) {
        try {
            $__st = $pdo->prepare("SELECT nome, email, is_admin FROM games_usuarios WHERE id = ?");
            $__st->execute([$__uid]);
            $__perfil = $__st->fetch(PDO::FETCH_ASSOC);

            if (!$__perfil) {
                $pdo->prepare("
                    INSERT IGNORE INTO games_usuarios (id, nome, email, league, is_admin)
                    SELECT id, name, email, COALESCE(league, 'ROOKIE'), ?
                    FROM users WHERE id = ?
                ")->execute([
                    (($_SESSION['user_type'] ?? '') === 'admin') ? 1 : 0,
                    $__uid,
                ]);
                $__st->execute([$__uid]);
                $__perfil = $__st->fetch(PDO::FETCH_ASSOC) ?: [];
            }

            // Compatibilidade: o código do games lê estas chaves de sessão,
            // que no site têm outro nome.
            $_SESSION['nome']     = $__perfil['nome'] ?? ($_SESSION['user_name'] ?? '');
            $_SESSION['email']    = $__perfil['email'] ?? ($_SESSION['user_email'] ?? '');
            $_SESSION['is_admin'] = (($_SESSION['user_type'] ?? '') === 'admin') ? 1 : 0;
        } catch (PDOException $e) {
            error_log('[games] perfil do usuario: ' . $e->getMessage());
        }
    }
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM games_usuarios LIKE 'fba_points'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE games_usuarios ADD COLUMN fba_points INT NOT NULL DEFAULT 0 AFTER pontos");
        }
    } catch (PDOException $e) {
        // Silencia erro de ajuste de schema para nao quebrar a conexao
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM games_usuarios LIKE 'acertos_eventos'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE games_usuarios ADD COLUMN acertos_eventos INT NOT NULL DEFAULT 0 AFTER fba_points");
            $pdo->exec("
                UPDATE games_usuarios u
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
        $stmt = $pdo->prepare("SHOW COLUMNS FROM games_usuarios LIKE 'tapas_disponiveis'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE games_usuarios ADD COLUMN tapas_disponiveis INT NOT NULL DEFAULT 2 AFTER numero_tapas");
            $pdo->exec("UPDATE games_usuarios SET tapas_disponiveis = 2 WHERE tapas_disponiveis IS NULL");
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

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM opcoes LIKE 'img_url'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE opcoes ADD COLUMN img_url VARCHAR(500) NULL DEFAULT NULL");
        }
    } catch (PDOException $e) {
        // Silencia erro de ajuste de schema para nao quebrar a conexao
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fba_game_controls (
            id INT AUTO_INCREMENT PRIMARY KEY,
            game_key VARCHAR(40) NOT NULL UNIQUE,
            is_double TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        $pdo->exec("INSERT IGNORE INTO fba_game_controls (game_key, is_double) VALUES
            ('memoria', 0),
            ('termo', 0),
            ('flappy', 0),
            ('pinguim', 0),
            ('ai', 0)
        ");
    } catch (PDOException $e) {
        // Silencia erro de ajuste de schema para nao quebrar a conexao
    }
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}

if (!function_exists('getGameDoubleSetting')) {
    function getGameDoubleSetting(PDO $pdo, string $gameKey): bool {
        try {
            $stmt = $pdo->prepare('SELECT is_double FROM fba_game_controls WHERE game_key = :k LIMIT 1');
            $stmt->execute([':k' => $gameKey]);
            return (int)$stmt->fetchColumn() === 1;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('getGamePointsMultiplier')) {
    function getGamePointsMultiplier(PDO $pdo, string $gameKey): int {
        return getGameDoubleSetting($pdo, $gameKey) ? 2 : 1;
    }
}

?>
