<?php
/**
 * Execute este script UMA VEZ na linha de comando para gerar as chaves VAPID:
 *   php setup-push.php
 *
 * Isso vai criar backend/vapid-config.php com suas chaves privadas.
 * NÃO commite esse arquivo no git.
 */

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    die("ERRO: vendor/autoload.php não encontrado.\nExecute primeiro: composer install\n");
}

require_once $autoload;

$configFile = __DIR__ . '/backend/vapid-config.php';

if (file_exists($configFile)) {
    echo "backend/vapid-config.php já existe. Delete o arquivo e rode novamente se quiser regenerar.\n";
    $config = require $configFile;
    echo "Chave pública atual: " . $config['vapid_public_key'] . "\n";
    exit(0);
}

use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();

$content = "<?php\nreturn [\n";
$content .= "    'vapid_public_key'  => '" . $keys['publicKey'] . "',\n";
$content .= "    'vapid_private_key' => '" . $keys['privateKey'] . "',\n";
$content .= "    'vapid_subject'     => 'mailto:admin@fbabrasil.com.br',\n";
$content .= "];\n";

file_put_contents($configFile, $content);

echo "✅ Chaves VAPID geradas com sucesso!\n";
echo "Chave pública: " . $keys['publicKey'] . "\n";
echo "Arquivo criado: backend/vapid-config.php\n";
echo "\nADICIONE backend/vapid-config.php ao seu .gitignore!\n";
