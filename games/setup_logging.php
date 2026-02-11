<?php
/**
 * SETUP_LOGGING.PHP - Configurar logging de erros
 */

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Configurar PHP para logar erros
ini_set('log_errors', 1);
ini_set('error_log', $logDir . '/php_errors.log');
ini_set('display_errors', 0);

// Criação de arquivo de teste
$testLog = $logDir . '/test.log';
file_put_contents($testLog, "Logging ativo em " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

error_log("=== LOGGING INICIADO ===");

echo "✓ Logging configurado<br>";
echo "📁 Diretório: $logDir<br>";
echo "📄 Arquivo de erros: " . ini_get('error_log') . "<br>";

if (is_writable($logDir)) {
    echo "✓ Diretório é gravável<br>";
} else {
    echo "✗ Diretório NÃO é gravável<br>";
}

?>
