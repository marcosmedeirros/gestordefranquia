<?php
/**
 * O ENDEREÇO DO MCP — é aqui que o Claude conversa com a liga.
 *
 * Protocolo: MCP sobre HTTP (JSON-RPC 2.0 no POST). Só os métodos que um
 * servidor de ferramentas precisa responder: initialize, tools/list,
 * tools/call e ping. Notificação (mensagem sem `id`) não tem resposta, e é o
 * que a especificação manda.
 *
 * Resposta em JSON puro, sem SSE: a especificação do transporte HTTP permite,
 * e aqui nada demora a ponto de precisar de stream.
 *
 * Como ligar no Claude Code:
 *   claude mcp add --transport http fba https://fbabrasil.com.br/api/mcp.php \
 *     --header "Authorization: Bearer <token>"
 * O token sai de `php ferramentas/mcp_token.php` no servidor.
 *
 * As ferramentas estão em backend/mcp_tools.php; o que é comum (token, log,
 * achar time) em backend/mcp_core.php.
 */

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/mcp_tools.php';
require_once __DIR__ . '/../backend/mcp_oauth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
// O app do Claude fala com este endereço de outra origem.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version, Mcp-Session-Id');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

const MCP_PROTOCOLO_PADRAO = '2025-06-18';

function mcpResposta($id, array $resultado): void
{
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $resultado],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mcpErroRpc($id, int $codigo, string $mensagem, int $http = 200): void
{
    http_response_code($http);
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $codigo, 'message' => $mensagem]],
        JSON_UNESCAPED_UNICODE);
    exit;
}

/* GET serve só pra saber se está de pé — o MCP em si é todo no POST. Sem
   token aqui: a resposta não diz nada sobre a liga. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    echo json_encode(['ok' => true, 'servidor' => 'fba-mcp', 'versao' => MCP_VERSAO,
                      'protocolo' => MCP_PROTOCOLO_PADRAO], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mcpErroRpc(null, -32600, 'Use POST.', 405);
}

$pdo = db();

/* O 401 aponta pro documento que diz como se autenticar (RFC 9728). É por
   este cabeçalho que o app do Claude descobre sozinho o caminho do OAuth —
   sem ele, o conector só diria "não autorizado" e pararia. */
if (!mcpAutenticado($pdo)) {
    header('WWW-Authenticate: Bearer realm="FBA", resource_metadata="'
         . mcpOauthEmissor() . '/.well-known/oauth-protected-resource"');
    mcpErroRpc(null, -32001, 'Token inválido ou ausente.', 401);
}

$corpo = json_decode(file_get_contents('php://input'), true);
if (!is_array($corpo)) mcpErroRpc(null, -32700, 'JSON inválido.');

// Lote: a especificação permite array de mensagens. Raro, mas barato de honrar.
$mensagens = isset($corpo['jsonrpc']) ? [$corpo] : $corpo;
$respostas = [];

foreach ($mensagens as $msg) {
    if (!is_array($msg)) continue;
    $id     = $msg['id'] ?? null;
    $metodo = (string)($msg['method'] ?? '');
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
    $ehNotificacao = !array_key_exists('id', $msg);

    try {
        switch ($metodo) {
            case 'initialize':
                $pedido = (string)($params['protocolVersion'] ?? '');
                $resultado = [
                    'protocolVersion' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $pedido) ? $pedido : MCP_PROTOCOLO_PADRAO,
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => ['name' => 'fba', 'version' => MCP_VERSAO],
                    'instructions'    => 'Ferramentas da FBA (ligas ELITE, NEXT, RISE e ROOKIE). '
                                       . 'Leitura: liga_panorama, time, picks, trades, trade, free_agency, '
                                       . 'classificacao, consulta. Escrita, que aplica na hora: mover_pick, '
                                       . 'mover_jogador, moedas, executar_sql. Valores de salário em milhões.',
                ];
                break;

            case 'notifications/initialized':
            case 'notifications/cancelled':
                $resultado = [];
                break;

            case 'ping':
                $resultado = [];
                break;

            case 'tools/list':
                $resultado = ['tools' => mcpFerramentas()];
                break;

            case 'tools/call':
                $nome = (string)($params['name'] ?? '');
                $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                $escreve = mcpFerramentaEscreve($nome);
                try {
                    $texto = mcpExecutar($pdo, $nome, $args);
                    mcpRegistrar($pdo, $nome, $args, $escreve, true, $texto);
                    $resultado = ['content' => [['type' => 'text', 'text' => $texto]], 'isError' => false];
                } catch (McpErro $e) {
                    // Erro de uso volta como conteúdo, não como falha de
                    // protocolo: assim o assistente lê o motivo e corrige a
                    // chamada em vez de só ver "deu erro".
                    mcpRegistrar($pdo, $nome, $args, $escreve, false, $e->getMessage());
                    $resultado = ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
                } catch (Throwable $e) {
                    error_log('[mcp] ' . $nome . ': ' . $e->getMessage());
                    mcpRegistrar($pdo, $nome, $args, $escreve, false, $e->getMessage());
                    $resultado = ['content' => [['type' => 'text', 'text' => 'Falhou: ' . $e->getMessage()]], 'isError' => true];
                }
                break;

            default:
                if ($ehNotificacao) continue 2;
                $respostas[] = ['jsonrpc' => '2.0', 'id' => $id,
                                'error' => ['code' => -32601, 'message' => 'Método desconhecido: ' . $metodo]];
                continue 2;
        }
    } catch (Throwable $e) {
        error_log('[mcp] ' . $metodo . ': ' . $e->getMessage());
        if ($ehNotificacao) continue;
        $respostas[] = ['jsonrpc' => '2.0', 'id' => $id,
                        'error' => ['code' => -32603, 'message' => 'Erro interno.']];
        continue;
    }

    if ($ehNotificacao) continue;
    // Resultado vazio tem que sair como {} e não como []: em JSON os dois são
    // coisas diferentes, e o cliente espera objeto (ping, initialized).
    $respostas[] = ['jsonrpc' => '2.0', 'id' => $id, 'result' => $resultado ?: new stdClass()];
}

// Só notificações: nada a devolver, e o 202 diz isso sem inventar corpo.
if (!$respostas) {
    http_response_code(202);
    exit;
}

echo json_encode(count($respostas) === 1 ? $respostas[0] : $respostas,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
