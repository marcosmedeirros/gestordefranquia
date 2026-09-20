<?php
/**
 * Mostra (criando na primeira vez) o token do MCP.
 *
 * Só na linha de comando: o token dá acesso de escrita ao banco da liga, então
 * ele não passa por navegador nem por endereço público. Uso:
 *
 *   /opt/alt/php83/usr/bin/php ferramentas/mcp_token.php
 *   /opt/alt/php83/usr/bin/php ferramentas/mcp_token.php --trocar
 *
 * `--trocar` gera outro e invalida o anterior — é o que fazer se o token
 * aparecer num print, num commit ou numa conversa.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/mcp_core.php';

$pdo = db();
mcpGarantirTabelas($pdo);

if (in_array('--trocar', $argv, true)) {
    $pdo->prepare("DELETE FROM mcp_config WHERE chave = 'token'")->execute();
    echo "Token anterior invalidado.\n";
}

$token = mcpToken($pdo);
echo "token: $token\n\n";
echo "Claude Code:\n";
echo "  claude mcp add --transport http fba https://fbabrasil.com.br/api/mcp.php \\\n";
echo "    --header \"Authorization: Bearer $token\"\n";
