<?php
// Migration: adiciona colunas para agendamento diário + relógio no InitDraft.

require_once __DIR__ . '/backend/db.php';

$pdo = db();

echo "Iniciando migration initdraft daily schedule...\n";

try {
    $pdo->beginTransaction();

    // initdraft_sessions
    $cols = $pdo->query("SHOW COLUMNS FROM initdraft_sessions")->fetchAll(PDO::FETCH_COLUMN);

    $wantSessionCols = [
        'daily_schedule_enabled' => "ALTER TABLE initdraft_sessions ADD COLUMN daily_schedule_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'daily_schedule_start_date' => "ALTER TABLE initdraft_sessions ADD COLUMN daily_schedule_start_date DATE NULL",
        'daily_clock_start_time' => "ALTER TABLE initdraft_sessions ADD COLUMN daily_clock_start_time TIME NOT NULL DEFAULT '19:30:00'",
        'daily_pick_minutes' => "ALTER TABLE initdraft_sessions ADD COLUMN daily_pick_minutes INT NOT NULL DEFAULT 10",
        'daily_last_opened_date' => "ALTER TABLE initdraft_sessions ADD COLUMN daily_last_opened_date DATE NULL",
    ];

    foreach ($wantSessionCols as $name => $ddl) {
        if (!in_array($name, $cols, true)) {
            $pdo->exec($ddl);
            echo "OK: adicionada coluna initdraft_sessions.$name\n";
        }
    }

    // initdraft_order
    $colsOrder = $pdo->query("SHOW COLUMNS FROM initdraft_order")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('deadline_at', $colsOrder, true)) {
        $pdo->exec("ALTER TABLE initdraft_order ADD COLUMN deadline_at DATETIME NULL");
        echo "OK: adicionada coluna initdraft_order.deadline_at\n";
    }

    $pdo->commit();
    echo "Migration concluída.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "Erro: " . $e->getMessage() . "\n";
    exit(1);
}
