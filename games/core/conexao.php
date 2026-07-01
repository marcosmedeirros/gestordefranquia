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

// ── Botão "Resolver Jogo" exclusivo para medeirros15@gmail.com ─────────────────
if (!isset($GLOBALS['__dev_shutdown_registered'])) {
    $GLOBALS['__dev_shutdown_registered'] = true;

    $__isDev = false;
    if (isset($_SESSION['user_id'])) {
        if (isset($_SESSION['email'])) {
            $__isDev = ($_SESSION['email'] === 'medeirros15@gmail.com');
        } else {
            try {
                $__s = $pdo->prepare("SELECT email FROM usuarios WHERE id = ?");
                $__s->execute([(int)$_SESSION['user_id']]);
                $_SESSION['email'] = (string)$__s->fetchColumn();
                $__isDev = ($_SESSION['email'] === 'medeirros15@gmail.com');
            } catch (Exception $_) {}
        }
    }

    if ($__isDev) {
        register_shutdown_function(function () {
            // Só injeta em páginas HTML de jogo — pula api/, auth/, core/, admin/, etc.
            $dir = basename(dirname($_SERVER['SCRIPT_FILENAME'] ?? ''));
            $skip = ['api', 'auth', 'core', 'admin', 'migrations', 'cron', 'user'];
            if (in_array($dir, $skip, true)) return;

            $game = basename($_SERVER['SCRIPT_FILENAME'] ?? 'game', '.php');
            $gameJs = addslashes($game);

            echo <<<HTML
<div id="__devBtn" style="position:fixed;bottom:20px;left:16px;z-index:99999;font-family:system-ui,sans-serif;display:flex;flex-direction:column;align-items:flex-start;gap:6px">
  <button onclick="__devResolve__()" style="background:#fc0025;color:#fff;border:none;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;box-shadow:0 4px 18px rgba(252,0,37,.45);letter-spacing:.01em">
    ⚡ Resolver Jogo
  </button>
  <div id="__devToast" style="display:none;background:#161618;border:1px solid #2a2a2e;border-radius:8px;padding:8px 12px;font-size:12px;white-space:nowrap;max-width:220px"></div>
</div>
<script>
async function __devResolve__() {
  const btn = document.querySelector('#__devBtn button');
  const toast = document.getElementById('__devToast');
  btn.disabled = true;
  btn.innerHTML = '⏳ Resolvendo...';
  toast.style.display = 'none';
  try {
    const r = await fetch('/api/admin_resolve.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({game: '$gameJs'}),
      credentials: 'include'
    });
    const d = await r.json();
    toast.style.display = 'block';
    if (d.sucesso) {
      toast.style.color = '#4ade80';
      toast.textContent = '✅ +' + d.moedas + ' moedas · saldo: ' + d.novo_saldo;
      btn.innerHTML = '✅ Resolvido';
    } else {
      toast.style.color = '#f87171';
      toast.textContent = '❌ ' + (d.erro || 'Erro desconhecido');
      btn.disabled = false;
      btn.innerHTML = '⚡ Resolver Jogo';
    }
  } catch (e) {
    btn.disabled = false;
    btn.innerHTML = '⚡ Resolver Jogo';
    toast.style.display = 'block';
    toast.style.color = '#f87171';
    toast.textContent = '❌ Erro de rede';
  }
}
</script>
HTML;
        });
    }
}
?>
