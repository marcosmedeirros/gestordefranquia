<?php
/**
 * OAUTH DO MCP — o que faz o app do Claude aceitar o servidor da liga.
 *
 * O Claude Code se conecta com um token no cabeçalho e pronto. O app (celular,
 * claude.ai) não: conector remoto lá só entra se o servidor souber conversar
 * OAuth 2.1, e é isso que este arquivo é. O cliente se registra sozinho
 * (RFC 7591), manda o usuário aprovar numa tela nossa, e troca o código por um
 * token — com PKCE, que é o que protege um cliente público.
 *
 * Quem aprova é o DONO DA LIGA e mais ninguém: a tela de autorização exige
 * sessão de admin geral do site. Não é firula — as ferramentas de escrita
 * aplicam direto no banco de produção, então um GM autorizando o próprio
 * Claude teria mão em time alheio.
 *
 * Token no banco vai como hash: se alguém ler a tabela, não sai de lá com uma
 * credencial que funcione. É a mesma razão de senha não virar texto puro.
 *
 * O token antigo, de cabeçalho fixo (mcp_config.token), continua valendo em
 * paralelo — é o que o Claude Code usa, e não faz sentido quebrar o que já
 * está ligado.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mcp_core.php';

const MCP_OAUTH_ESCOPO        = 'fba';
const MCP_OAUTH_CODIGO_VIDA   = 300;        // 5 min, como manda a especificação
const MCP_OAUTH_ACESSO_VIDA   = 3600;       // 1 hora
const MCP_OAUTH_REFRESH_VIDA  = 2592000;    // 30 dias

function mcpOauthGarantirTabelas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_oauth_clients (
        client_id VARCHAR(64) PRIMARY KEY,
        client_name VARCHAR(190) NULL,
        redirect_uris TEXT NOT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_oauth_codes (
        code_hash CHAR(64) PRIMARY KEY,
        client_id VARCHAR(64) NOT NULL,
        user_id INT NOT NULL,
        redirect_uri TEXT NOT NULL,
        code_challenge VARCHAR(190) NOT NULL,
        scope VARCHAR(190) NOT NULL,
        expira_em DATETIME NOT NULL,
        usado TINYINT(1) NOT NULL DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_expira (expira_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_oauth_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token_hash CHAR(64) NOT NULL UNIQUE,
        tipo ENUM('access','refresh') NOT NULL,
        client_id VARCHAR(64) NOT NULL,
        user_id INT NOT NULL,
        scope VARCHAR(190) NOT NULL,
        expira_em DATETIME NOT NULL,
        revogado TINYINT(1) NOT NULL DEFAULT 0,
        usado_em DATETIME NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_expira (expira_em),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** O endereço do site, do jeito que o navegador chegou nele. */
function mcpOauthEmissor(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'fbabrasil.com.br';
    $https = ($_SERVER['HTTPS'] ?? '') === 'on'
          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
          || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
    return ($https ? 'https://' : 'http://') . $host;
}

function mcpOauthUrlRecurso(): string { return mcpOauthEmissor() . '/api/mcp.php'; }

/** O que o cliente lê pra saber como pedir autorização (RFC 8414). */
function mcpOauthMetadataAS(): array
{
    $base = mcpOauthEmissor();
    return [
        'issuer'                                => $base,
        'authorization_endpoint'                => $base . '/api/oauth/authorize.php',
        'token_endpoint'                        => $base . '/api/oauth/token.php',
        'registration_endpoint'                 => $base . '/api/oauth/register.php',
        'scopes_supported'                      => [MCP_OAUTH_ESCOPO],
        'response_types_supported'              => ['code'],
        'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported'      => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none'],
        'service_documentation'                 => $base . '/api/mcp.php',
    ];
}

/** E isto diz a quem proteger o recurso (RFC 9728). */
function mcpOauthMetadataRecurso(): array
{
    return [
        'resource'              => mcpOauthUrlRecurso(),
        'authorization_servers' => [mcpOauthEmissor()],
        'scopes_supported'      => [MCP_OAUTH_ESCOPO],
        'bearer_methods_supported' => ['header'],
    ];
}

function mcpOauthHash(string $valor): string { return hash('sha256', $valor); }

/**
 * Registro dinâmico: o cliente chega sem credencial nenhuma e sai com um id.
 *
 * Cliente público (sem segredo) porque é o que o app é — o que garante que o
 * código não foi interceptado é o PKCE, não um segredo que teria de viajar.
 */
function mcpOauthRegistrarCliente(PDO $pdo, array $dados): array
{
    mcpOauthGarantirTabelas($pdo);

    $redirects = $dados['redirect_uris'] ?? [];
    if (!is_array($redirects) || !$redirects) {
        throw new InvalidArgumentException('redirect_uris é obrigatório.');
    }
    foreach ($redirects as $uri) {
        if (!is_string($uri) || !preg_match('#^https://#i', $uri)) {
            // http:// só passaria em localhost, e nenhum cliente nosso é local.
            throw new InvalidArgumentException('redirect_uri precisa ser https: ' . (string)$uri);
        }
    }

    $clientId = 'fba-' . bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO mcp_oauth_clients (client_id, client_name, redirect_uris)
                   VALUES (?, ?, ?)")
        ->execute([
            $clientId,
            mb_substr((string)($dados['client_name'] ?? 'cliente MCP'), 0, 190),
            json_encode(array_values($redirects), JSON_UNESCAPED_SLASHES),
        ]);

    return [
        'client_id'                  => $clientId,
        'client_id_issued_at'        => time(),
        'redirect_uris'              => array_values($redirects),
        'token_endpoint_auth_method' => 'none',
        'grant_types'                => ['authorization_code', 'refresh_token'],
        'response_types'             => ['code'],
        'client_name'                => (string)($dados['client_name'] ?? 'cliente MCP'),
    ];
}

function mcpOauthCliente(PDO $pdo, string $clientId): ?array
{
    mcpOauthGarantirTabelas($pdo);
    $st = $pdo->prepare("SELECT * FROM mcp_oauth_clients WHERE client_id = ?");
    $st->execute([$clientId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) return null;
    $c['redirect_uris'] = json_decode((string)$c['redirect_uris'], true) ?: [];
    return $c;
}

/** Comparação exata, como manda a especificação — nada de prefixo. */
function mcpOauthRedirectValido(array $cliente, string $redirectUri): bool
{
    foreach ($cliente['redirect_uris'] as $u) {
        if (hash_equals((string)$u, $redirectUri)) return true;
    }
    return false;
}

function mcpOauthCriarCodigo(PDO $pdo, string $clientId, int $userId, string $redirectUri,
                             string $codeChallenge, string $scope): string
{
    mcpOauthGarantirTabelas($pdo);
    $codigo = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO mcp_oauth_codes
                     (code_hash, client_id, user_id, redirect_uri, code_challenge, scope, expira_em)
                   VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))")
        ->execute([mcpOauthHash($codigo), $clientId, $userId, $redirectUri,
                   $codeChallenge, $scope, MCP_OAUTH_CODIGO_VIDA]);
    return $codigo;
}

function mcpOauthEmitirToken(PDO $pdo, string $tipo, string $clientId, int $userId, string $scope): string
{
    $token = bin2hex(random_bytes(32));
    $vida  = $tipo === 'access' ? MCP_OAUTH_ACESSO_VIDA : MCP_OAUTH_REFRESH_VIDA;
    $pdo->prepare("INSERT INTO mcp_oauth_tokens (token_hash, tipo, client_id, user_id, scope, expira_em)
                   VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))")
        ->execute([mcpOauthHash($token), $tipo, $clientId, $userId, $scope, $vida]);
    return $token;
}

/**
 * Troca o código pelo par de tokens, conferindo o PKCE.
 *
 * Código é de uso único: o segundo uso não só falha como derruba o que já foi
 * emitido a partir dele — é o jeito de um código roubado não valer nada
 * depois que o dono já usou.
 */
function mcpOauthTrocarCodigo(PDO $pdo, string $codigo, string $clientId,
                              string $redirectUri, string $codeVerifier): array
{
    mcpOauthGarantirTabelas($pdo);
    $hash = mcpOauthHash($codigo);
    $st = $pdo->prepare("SELECT * FROM mcp_oauth_codes WHERE code_hash = ?");
    $st->execute([$hash]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new RuntimeException('invalid_grant');

    if ((int)$c['usado'] === 1) {
        $pdo->prepare("UPDATE mcp_oauth_tokens SET revogado = 1 WHERE client_id = ? AND user_id = ?")
            ->execute([$c['client_id'], (int)$c['user_id']]);
        throw new RuntimeException('invalid_grant');
    }
    if (strtotime((string)$c['expira_em']) < time())            throw new RuntimeException('invalid_grant');
    if (!hash_equals((string)$c['client_id'], $clientId))       throw new RuntimeException('invalid_grant');
    if (!hash_equals((string)$c['redirect_uri'], $redirectUri)) throw new RuntimeException('invalid_grant');

    $calculado = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    if (!hash_equals((string)$c['code_challenge'], $calculado)) throw new RuntimeException('invalid_grant');

    $pdo->prepare("UPDATE mcp_oauth_codes SET usado = 1 WHERE code_hash = ?")->execute([$hash]);

    return [
        'access_token'  => mcpOauthEmitirToken($pdo, 'access',  $clientId, (int)$c['user_id'], (string)$c['scope']),
        'token_type'    => 'Bearer',
        'expires_in'    => MCP_OAUTH_ACESSO_VIDA,
        'refresh_token' => mcpOauthEmitirToken($pdo, 'refresh', $clientId, (int)$c['user_id'], (string)$c['scope']),
        'scope'         => (string)$c['scope'],
    ];
}

/** Refresh rotativo: o antigo morre no mesmo instante em que o novo nasce. */
function mcpOauthUsarRefresh(PDO $pdo, string $refresh, string $clientId): array
{
    mcpOauthGarantirTabelas($pdo);
    $hash = mcpOauthHash($refresh);
    $st = $pdo->prepare("SELECT * FROM mcp_oauth_tokens WHERE token_hash = ? AND tipo = 'refresh'");
    $st->execute([$hash]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t || (int)$t['revogado'] === 1)                  throw new RuntimeException('invalid_grant');
    if (strtotime((string)$t['expira_em']) < time())       throw new RuntimeException('invalid_grant');
    if (!hash_equals((string)$t['client_id'], $clientId))  throw new RuntimeException('invalid_grant');

    $pdo->prepare("UPDATE mcp_oauth_tokens SET revogado = 1 WHERE id = ?")->execute([(int)$t['id']]);

    return [
        'access_token'  => mcpOauthEmitirToken($pdo, 'access',  $clientId, (int)$t['user_id'], (string)$t['scope']),
        'token_type'    => 'Bearer',
        'expires_in'    => MCP_OAUTH_ACESSO_VIDA,
        'refresh_token' => mcpOauthEmitirToken($pdo, 'refresh', $clientId, (int)$t['user_id'], (string)$t['scope']),
        'scope'         => (string)$t['scope'],
    ];
}

/** O token que chegou no cabeçalho vale? Devolve o dono, ou null. */
function mcpOauthValidarAcesso(PDO $pdo, string $token): ?array
{
    if ($token === '') return null;
    mcpOauthGarantirTabelas($pdo);
    $st = $pdo->prepare("SELECT t.*, u.name, u.user_type FROM mcp_oauth_tokens t
                           JOIN users u ON u.id = t.user_id
                          WHERE t.token_hash = ? AND t.tipo = 'access' AND t.revogado = 0");
    $st->execute([mcpOauthHash($token)]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return null;
    if (strtotime((string)$t['expira_em']) < time()) return null;

    // Marca o uso sem falar nada se falhar: é estatística, não autorização.
    try {
        $pdo->prepare("UPDATE mcp_oauth_tokens SET usado_em = NOW() WHERE id = ?")->execute([(int)$t['id']]);
    } catch (Throwable $e) {}

    return ['user_id' => (int)$t['user_id'], 'nome' => (string)$t['name'], 'scope' => (string)$t['scope']];
}

/** Limpeza do que já morreu. Barata e sem transação: roda no fluxo normal. */
function mcpOauthLimpar(PDO $pdo): void
{
    try {
        $pdo->exec("DELETE FROM mcp_oauth_codes  WHERE expira_em < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $pdo->exec("DELETE FROM mcp_oauth_tokens WHERE expira_em < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    } catch (Throwable $e) {
        error_log('[mcp-oauth] limpeza: ' . $e->getMessage());
    }
}
