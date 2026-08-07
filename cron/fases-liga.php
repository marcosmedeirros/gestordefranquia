<?php
/**
 * Cron: resolve as dispensas vencidas (waiver 12h) e os eventos agendados
 * (fechar/abrir trades, fechar Free Agency). Rodar a cada poucos minutos.
 *
 * Sem cron configurado, o próprio acesso às telas (Dispensas / Agendador de
 * Fases no admin) já resolve o que estiver vencido — isto aqui só garante que
 * a hora marcada seja respeitada com precisão, mesmo sem ninguém abrir o site.
 */
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/waivers.php';
require_once __DIR__ . '/../backend/scheduler.php';

$pdo = db();
$waivers = resolveExpiredWaivers($pdo);
$scheduler = runDueScheduledEvents($pdo);

echo "OK " . date('c') . " waivers(resolved=" . $waivers['resolved'] . ") scheduler(done=" . $scheduler['done'] . ",failed=" . $scheduler['failed'] . ")\n";
