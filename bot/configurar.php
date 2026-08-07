<?php
/**
 * Monta o bot/whatsapp-local.config.php numa tacada.
 *
 * A chave da Evolution é lida do próprio container (docker inspect), então a
 * única coisa que você precisa colar é o token do site — que aparece em
 * Admin → integração de WhatsApp, campo bot_token.
 *
 *   php bot/configurar.php <token-do-site> [url-do-site]
 *
 * Exemplo:
 *   php bot/configurar.php abc123... https://fbabrasil.com.br
 *
 * O arquivo gerado fica fora do git (.gitignore) porque carrega dois segredos.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$token = $argv[1] ?? '';
$site  = $argv[2] ?? 'https://fbabrasil.com.br';

if ($token === '') {
    fwrite(STDERR, "Uso: php bot/configurar.php <token-do-site> [url-do-site]\n");
    fwrite(STDERR, "O token está no Admin, na integração de WhatsApp (bot_token).\n");
    exit(1);
}

/** Lê uma variável de ambiente de dentro do container da Evolution. */
function envDoContainer(string $container, string $chave): ?string
{
    $saida = @shell_exec('docker inspect ' . escapeshellarg($container)
        . ' --format "{{range .Config.Env}}{{println .}}{{end}}" 2>&1');
    if (!$saida) return null;
    foreach (explode("\n", $saida) as $linha) {
        if (strpos($linha, $chave . '=') === 0) return trim(substr($linha, strlen($chave) + 1));
    }
    return null;
}

$apiKey = envDoContainer('fba-evolution', 'AUTHENTICATION_API_KEY');
if (!$apiKey) {
    fwrite(STDERR, "Não consegui ler a chave do container 'fba-evolution'.\n");
    fwrite(STDERR, "O Docker Desktop está aberto e o container de pé? (docker ps)\n");
    exit(1);
}

// Qual instância está conectada. Evita chutar o nome.
$instancia = 'fba';
$ch = curl_init('http://localhost:8081/instance/fetchInstances');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => ['apikey: ' . $apiKey]]);
$resp = json_decode((string)curl_exec($ch), true);
unset($ch);
if (is_array($resp)) {
    foreach ($resp as $i) {
        $nome   = $i['name'] ?? ($i['instance']['instanceName'] ?? null);
        $estado = $i['connectionStatus'] ?? ($i['instance']['state'] ?? '');
        if ($nome && $estado === 'open') { $instancia = $nome; break; }
    }
}

$conteudo = "<?php\n"
    . "// Gerado por bot/configurar.php — não versionar (tem segredos).\n"
    . "return [\n"
    . "    'site_url'  => " . var_export(rtrim($site, '/'), true) . ",\n"
    . "    'bot_token' => " . var_export($token, true) . ",\n"
    . "    'evolution_url'       => 'http://localhost:8081',\n"
    . "    'evolution_instancia' => " . var_export($instancia, true) . ",\n"
    . "    'evolution_api_key'   => " . var_export($apiKey, true) . ",\n"
    . "];\n";

file_put_contents(__DIR__ . '/whatsapp-local.config.php', $conteudo);

echo "Config gravada em bot/whatsapp-local.config.php\n";
echo "  site:      $site\n";
echo "  instancia: $instancia\n";
echo "  chave da Evolution: lida do container\n\n";
echo "Testar agora:  php bot/whatsapp-local.php\n";
