<?php

require_once __DIR__ . '/../backend/mcp_oauth.php';

$cliente = ['redirect_uris' => [
    'http://127.0.0.1:33418',
    'https://vscode.dev/redirect',
]];

$casos = [
    'http://127.0.0.1:33418' => true,
    'http://127.0.0.1:33418/' => true,
    'http://127.0.0.1:49152/' => true,
    'https://vscode.dev/redirect' => true,
    'http://127.0.0.1:49152/other' => false,
    'http://127.0.0.1:49152/?other=1' => false,
    'http://localhost:49152/' => false,
    'http://example.com:49152/' => false,
    'https://vscode.dev/redirect/' => false,
];

foreach ($casos as $uri => $esperado) {
    if (mcpOauthRedirectValido($cliente, $uri) !== $esperado) {
        fwrite(STDERR, "Resultado inesperado para $uri\n");
        exit(1);
    }
}

$clienteSemPorta = ['redirect_uris' => ['http://127.0.0.1/']];
if (!mcpOauthRedirectUriPermitida('http://127.0.0.1/', false)
    || mcpOauthRedirectUriPermitida('http://127.0.0.1/')
    || !mcpOauthRedirectValido($clienteSemPorta, 'http://127.0.0.1:49152/')
    || mcpOauthRedirectValido($clienteSemPorta, 'http://127.0.0.1/')
    || mcpOauthRedirectValido($clienteSemPorta, 'http://127.0.0.1:49152/outro')) {
    fwrite(STDERR, "Falha na validação de redirect loopback sem porta registrada.\n");
    exit(1);
}

echo "Redirects OAuth: todos os casos passaram.\n";
