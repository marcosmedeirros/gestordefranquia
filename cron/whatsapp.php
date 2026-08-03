<?php
/**
 * Cron da fila de WhatsApp.
 *
 * O envio normal acontece no fim do request que gerou o aviso, mas se a
 * instância da Evolution estiver fora do ar naquele momento a mensagem fica
 * pendente. Este cron é quem garante que ela sai depois.
 *
 * Agendar a cada 5 minutos na Hostinger, com esta linha de cron:
 *   [barra]5 [asterisco] [asterisco] [asterisco] [asterisco]
 *   /usr/bin/php /home/u289267434/domains/fbabrasil.com.br/public_html/cron/whatsapp.php
 * (na interface da Hostinger é só escolher "a cada 5 minutos")
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/whatsapp.php';

$pdo = db();

if (!whatsappConfig($pdo)) {
    echo "[whatsapp-cron] integração desativada — nada a fazer\n";
    exit;
}

$r = whatsappProcessarFila($pdo, 200);
$linha = sprintf('[%s] whatsapp enviadas=%d falhas=%d', date('Y-m-d H:i:s'), $r['enviadas'], $r['falhas']);

echo $linha . "\n";

$logDir = dirname(__DIR__) . '/games/logs';
if (is_dir($logDir) || @mkdir($logDir, 0775, true)) {
    @file_put_contents($logDir . '/whatsapp-cron.log', $linha . "\n", FILE_APPEND);
}
