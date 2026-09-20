<?php
/**
 * Metadados do servidor de autorização (RFC 8414) e do recurso (RFC 9728).
 *
 * Um arquivo só porque as duas respostas são a mesma ideia: "como falar
 * comigo". Qual das duas sai depende de `?tipo=`, que o .htaccess preenche a
 * partir do endereço /.well-known pedido.
 *
 * Aberto, sem token: é isso que o cliente lê ANTES de ter qualquer credencial.
 */

require_once __DIR__ . '/../../backend/mcp_oauth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
// O app do Claude lê isto do navegador, de outra origem.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

$tipo = (string)($_GET['tipo'] ?? 'as');
echo json_encode($tipo === 'recurso' ? mcpOauthMetadataRecurso() : mcpOauthMetadataAS(),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
