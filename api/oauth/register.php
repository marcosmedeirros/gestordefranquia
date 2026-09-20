<?php
/**
 * Registro dinâmico de cliente (RFC 7591).
 *
 * O app do Claude chega aqui sozinho, antes de qualquer login, e sai com um
 * client_id. Parece aberto demais, mas não é: um client_id não dá acesso a
 * nada. O acesso só nasce quando o dono da liga aprova na tela de autorização,
 * e é lá que a porta é estreita.
 */

require_once __DIR__ . '/../../backend/mcp_oauth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'invalid_request', 'error_description' => 'Use POST.']);
    exit;
}

$corpo = json_decode(file_get_contents('php://input'), true);
if (!is_array($corpo)) $corpo = $_POST;

try {
    $pdo = db();
    mcpOauthLimpar($pdo);
    $resposta = mcpOauthRegistrarCliente($pdo, $corpo);
    http_response_code(201);
    echo json_encode($resposta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_redirect_uri', 'error_description' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[mcp-oauth] register: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server_error']);
}
