<?php
/**
 * criar-retrospectiva.php
 * Cria (ou atualiza) as tabelas para a Retrospectiva Anual FBA Games.
 * Execute pelo painel admin. Seguro rodar mais de uma vez.
 */

require_once __DIR__ . '/../core/conexao.php';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>
<style>body{font-family:monospace;background:#0a0a0f;color:#e0e0e0;padding:24px}
.ok{color:#22c55e}.err{color:#ef4444}.info{color:#818cf8}.warn{color:#f59e0b}
pre{background:#111;padding:10px;border:1px solid #333;border-radius:6px;overflow:auto;font-size:12px}</style>
</head><body>
<h2 style='color:#f59e0b'>🗓️ Retrospectiva Anual — Criar / Atualizar Tabelas</h2><hr>";

// ── 1. Acessos diários ────────────────────────────────────────────────────────
runSQL($pdo, 'usuario_acessos', "
    CREATE TABLE IF NOT EXISTS usuario_acessos (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT  NOT NULL,
        data_acesso DATE NOT NULL,
        UNIQUE KEY uniq_user_date (user_id, data_acesso),
        INDEX idx_user (user_id),
        INDEX idx_data (data_acesso)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 2. Retrospectiva anual ────────────────────────────────────────────────────
runSQL($pdo, 'retrospectiva_anual', "
    CREATE TABLE IF NOT EXISTS retrospectiva_anual (
        id          INT  AUTO_INCREMENT PRIMARY KEY,
        user_id     INT  NOT NULL,
        ano         YEAR NOT NULL,

        -- ── Acumulados mensalmente (somados ANTES de cada reset) ──────────
        total_fba_points        INT DEFAULT 0,
        total_moedas            INT DEFAULT 0,

        -- ── Acessos & Coleção ────────────────────────────────────────────
        total_acessos           INT DEFAULT 0,
        total_figurinhas        INT DEFAULT 0,

        -- ── Pinguim (dino_historico) ─────────────────────────────────────
        pinguim_recorde         INT DEFAULT 0,
        pinguim_total_pts       INT DEFAULT 0,
        pinguim_partidas        INT DEFAULT 0,

        -- ── Flappy (flappy_historico) ────────────────────────────────────
        flappy_recorde          INT DEFAULT 0,
        flappy_total_pts        INT DEFAULT 0,
        flappy_partidas         INT DEFAULT 0,

        -- ── Termo (termo_historico) ──────────────────────────────────────
        termo_partidas          INT DEFAULT 0,
        termo_acertos           INT DEFAULT 0,
        termo_streak_max        INT DEFAULT 0,

        -- ── Termo Dueto (termo_dueto_historico) ──────────────────────────
        termo_dueto_partidas    INT DEFAULT 0,
        termo_dueto_acertos     INT DEFAULT 0,

        -- ── Memória (memoria_historico) ──────────────────────────────────
        memoria_partidas        INT DEFAULT 0,
        memoria_acertos         INT DEFAULT 0,
        memoria_streak_max      INT DEFAULT 0,

        -- ── Conexo (conexoes_historico) ──────────────────────────────────
        conexo_partidas         INT DEFAULT 0,
        conexo_acertos          INT DEFAULT 0,

        -- ── Box NBA (boxnba_historico) ───────────────────────────────────
        boxnba_partidas         INT DEFAULT 0,
        boxnba_acertos          INT DEFAULT 0,
        boxnba_streak_max       INT DEFAULT 0,

        -- ── Grade / HoopGrid (grade_historico) ───────────────────────────
        grade_partidas          INT DEFAULT 0,
        grade_acertos           INT DEFAULT 0,

        -- ── Bomba (bomba_historico) ──────────────────────────────────────
        bomba_partidas          INT DEFAULT 0,
        bomba_acertos           INT DEFAULT 0,
        bomba_diamantes         INT DEFAULT 0,
        bomba_streak_max        INT DEFAULT 0,

        -- ── Quem Sou Eu NBA (quemsoueu_basquete) ─────────────────────────
        qse_nba_partidas        INT DEFAULT 0,
        qse_nba_acertos         INT DEFAULT 0,

        -- ── Quem Sou Eu Futebol (quemsoueu_futebol) ──────────────────────
        qse_fut_partidas        INT DEFAULT 0,
        qse_fut_acertos         INT DEFAULT 0,

        -- ── Apostas (palpites + eventos) ─────────────────────────────────
        apostas_total           INT     DEFAULT 0,
        apostas_acertadas       INT     DEFAULT 0,
        apostas_erradas         INT     DEFAULT 0,
        apostas_total_apostado  DECIMAL(12,2) DEFAULT 0,
        apostas_total_ganhos    DECIMAL(12,2) DEFAULT 0,

        -- ── Tigrinho (tigrinho_historico) ────────────────────────────────
        tigrinho_spins          INT     DEFAULT 0,
        tigrinho_apostado       DECIMAL(12,2) DEFAULT 0,
        tigrinho_ganhos         DECIMAL(12,2) DEFAULT 0,

        -- ── Meta ─────────────────────────────────────────────────────────
        snapshot_feito          TINYINT DEFAULT 0,
        criado_em               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        UNIQUE KEY uniq_user_ano (user_id, ano),
        INDEX idx_ano (ano)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 3. ALTER: adiciona colunas que possam faltar em tabelas já existentes ─────
$novaColunas = [
    'retrospectiva_anual' => [
        "ADD COLUMN IF NOT EXISTS termo_streak_max       INT DEFAULT 0 AFTER termo_acertos",
        "ADD COLUMN IF NOT EXISTS memoria_streak_max     INT DEFAULT 0 AFTER memoria_acertos",
        "ADD COLUMN IF NOT EXISTS boxnba_streak_max      INT DEFAULT 0 AFTER boxnba_acertos",
        "ADD COLUMN IF NOT EXISTS grade_partidas         INT DEFAULT 0 AFTER boxnba_streak_max",
        "ADD COLUMN IF NOT EXISTS grade_acertos          INT DEFAULT 0 AFTER grade_partidas",
        "ADD COLUMN IF NOT EXISTS bomba_partidas         INT DEFAULT 0 AFTER grade_acertos",
        "ADD COLUMN IF NOT EXISTS bomba_acertos          INT DEFAULT 0 AFTER bomba_partidas",
        "ADD COLUMN IF NOT EXISTS bomba_diamantes        INT DEFAULT 0 AFTER bomba_acertos",
        "ADD COLUMN IF NOT EXISTS bomba_streak_max       INT DEFAULT 0 AFTER bomba_diamantes",
        "ADD COLUMN IF NOT EXISTS apostas_total          INT DEFAULT 0 AFTER qse_fut_acertos",
        "ADD COLUMN IF NOT EXISTS apostas_acertadas      INT DEFAULT 0 AFTER apostas_total",
        "ADD COLUMN IF NOT EXISTS apostas_erradas        INT DEFAULT 0 AFTER apostas_acertadas",
        "ADD COLUMN IF NOT EXISTS apostas_total_apostado DECIMAL(12,2) DEFAULT 0 AFTER apostas_erradas",
        "ADD COLUMN IF NOT EXISTS apostas_total_ganhos   DECIMAL(12,2) DEFAULT 0 AFTER apostas_total_apostado",
        "ADD COLUMN IF NOT EXISTS tigrinho_spins         INT DEFAULT 0 AFTER apostas_total_ganhos",
        "ADD COLUMN IF NOT EXISTS tigrinho_apostado      DECIMAL(12,2) DEFAULT 0 AFTER tigrinho_spins",
        "ADD COLUMN IF NOT EXISTS tigrinho_ganhos        DECIMAL(12,2) DEFAULT 0 AFTER tigrinho_apostado",
    ],
];

foreach ($novaColunas as $tabela => $alters) {
    foreach ($alters as $alter) {
        try {
            $pdo->exec("ALTER TABLE {$tabela} {$alter}");
            echo "<p class='ok' style='font-size:11px'>✅ {$tabela}: {$alter}</p>";
        } catch (PDOException $e) {
            // Coluna já existe ou erro — mostra apenas se for erro real
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                echo "<p class='warn' style='font-size:11px'>⚠️ {$tabela}: {$alter} — " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        }
    }
}

echo "<hr><h3 class='ok'>✅ Concluído!</h3>
<p class='info'>
<strong>Próximos passos:</strong><br>
1. <code>index.clean.php</code> já registra acessos diários.<br>
2. <code>cron/reset-mensal.php</code> já acumula FBA Points e Moedas antes de zerar.<br>
3. Em dezembro execute: <code>cron/retrospectiva-snapshot.php?key=...</code><br>
4. Pode forçar snapshot a qualquer hora com <code>?key=...&amp;ano=2025</code>
</p>
</body></html>";

// ── Helper ────────────────────────────────────────────────────────────────────
function runSQL(PDO $pdo, string $tabela, string $sql): void {
    try {
        $pdo->exec($sql);
        $existe = $pdo->query("SHOW TABLES LIKE '{$tabela}'")->rowCount() > 0;
        $cols   = $pdo->query("DESCRIBE {$tabela}")->fetchAll(PDO::FETCH_COLUMN);
        echo $existe
            ? "<p class='ok'>✅ <strong>{$tabela}</strong> — " . count($cols) . " colunas</p>"
            : "<p class='err'>❌ <strong>{$tabela}</strong> não encontrada após criação.</p>";
    } catch (PDOException $e) {
        echo "<p class='err'>❌ <strong>{$tabela}</strong>: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}
