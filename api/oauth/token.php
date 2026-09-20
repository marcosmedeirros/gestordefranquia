<?php
/**
 * Troca o código (ou o refresh) pelo token de acesso.
 *
 * Sem senha de cliente: o cliente é público e quem prova a posse é o PKCE, no
 * code_verifier. Erro aqui sai no formato que a especificação manda
 * (`{"error":"invalid_grant"}`), e sempre com a mesma palavra — dizer qual
 * parte do pedido falhou ajudaria mais quem está tentando adivinhar do que
 * quem está integrando de verdade.
 */

require_once __DIR__ . '/../../backend/mcp_oauth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'invalid_request']);
    exit;
}

// Form encoded é o normal; JSON aparece em alguns clientes, e custa nada aceitar.
$dados = $_POST;
if (!$dados) {
    $corpo = json_decode(file_get_contents('php://input'), true);
    if (is_array($corpo)) $dados = $corpo;
}

$tipo     = (string)($dados['grant_type'] ?? '');
$clientId = (string)($dados['client_id'] ?? '');

try {
    $pdo = db();
    mcpOauthLimpar($pdo);

    if ($clientId === '' || !mcpOauthCliente($pdo, $clientId)) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_client']);
        exit;
    }

    if ($tipo === 'authorization_code') {
        $resposta = mcpOauthTrocarCodigo(
            $pdo,
            (string)($dados['code'] ?? ''),
            $clientId,
            (string)($dados['redirect_uri'] ?? ''),
            (string)($dados['code_verifier'] ?? '')
        );
    } elseif ($tipo === 'refresh_token') {
        $resposta = mcpOauthUsarRefresh($pdo, (string)($dados['refresh_token'] ?? ''), $clientId);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'unsupported_grant_type']);
        exit;
    }

    echo json_encode($resposta, JSON_UNESCAPED_SLASHES);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage() ?: 'invalid_grant']);
} catch (Throwable $e) {
    error_log('[mcp-oauth] token: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server_error']);
}
