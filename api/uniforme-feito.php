<?php
/**
 * O FORNECEDOR MARCA QUE O UNIFORME FOI FEITO.
 *
 * Fica num endpoint próprio, e não dentro de api/admin.php, porque quem chama
 * aqui NÃO é admin: é o GM que produz as City Editions. O "feito" dele é um
 * estado diferente do "aplicado" do admin — ver lojaFornecedorConfirmarFeito.
 *
 * A autorização não vem de parâmetro nenhum: o fornecedor é o usuário logado,
 * e o UPDATE lá dentro carrega `fornecedor_id = ele` no WHERE. Um id de pedido
 * chutado não assina o trabalho de outro.
 */
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/backend/loja.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$user = getUserSession();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$eu = (int)$user['id'];
if (!isset(lojaFornecedores()[$eu])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Você não é fornecedor de uniformes.']);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
$pedido = (int)($body['id'] ?? $_POST['id'] ?? 0);

$pdo = db();
$r = lojaFornecedorConfirmarFeito($pdo, $eu, $pedido);

if (!$r['ok']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $r['erro']], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => true, 'uniformes' => lojaUniformesDoFornecedor($pdo, $eu)], JSON_UNESCAPED_UNICODE);
