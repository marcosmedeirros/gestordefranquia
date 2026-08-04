<?php
/**
 * Manda uma notificação push de verdade pro usuário logado, agora, sem passar
 * pela preferência de tipo — é um teste, ele pediu explicitamente.
 *
 * Existe porque "diz que está ativado mas não chega nada" é uma reclamação
 * que não dá pra depurar sem ver o navegador da pessoa. Isto aqui devolve um
 * veredito imediato: quantas inscrições existem e quantas o serviço de push
 * aceitou — que é o sinal mais forte disponível sem estar olhando o celular
 * de quem reclamou.
 */
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/backend/push.php';

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

$pdo = db();

$resultado = sendPushRaw($pdo, (int)$user['id'], [
    'title' => '🔔 Teste de notificação',
    'body'  => 'Se esta mensagem apareceu, as notificações estão funcionando neste aparelho.',
    'url'   => '/settings.php',
]);

if ($resultado['total'] === 0) {
    echo json_encode(['success' => false, 'error' => 'sem_inscricao']);
    exit;
}

echo json_encode([
    'success'  => true,
    'total'    => $resultado['total'],
    'enviados' => $resultado['sent'],
    'falharam' => $resultado['failed'],
]);
