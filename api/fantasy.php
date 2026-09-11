<?php
/**
 * API do Fantasy FBA. A regra mora em backend/fantasy.php.
 *
 * GET               → estado da rodada, mercado, meu time, ranking e regras
 * POST salvar       → {escalacao:{PG,SG,SF,PF,C}, capitao}
 * POST nome         → {nome}
 * POST fechar | reabrir | encerrar  → só admin
 */
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/fantasy.php';

header('Content-Type: application/json; charset=utf-8');

$user = getUserSession();
if (empty($user['id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Faça login.']);
    exit;
}
$pdo = db();
fanGarantirTabelas($pdo);
$ehAdmin = hasAdminAccess($pdo, (int)$user['id']);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['ok' => true] + fanEstado($pdo, $user, $ehAdmin), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $corpo = json_decode(file_get_contents('php://input'), true) ?: [];
    $acao = (string)($corpo['acao'] ?? '');

    if (in_array($acao, ['fechar', 'reabrir', 'encerrar'], true) && !$ehAdmin) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'erro' => 'Só admin.']);
        exit;
    }

    $r = match ($acao) {
        'salvar'   => fanSalvarEscalacao($pdo, $user, $corpo),
        'nome'     => fanSalvarNome($pdo, $user, (string)($corpo['nome'] ?? '')),
        'fechar'   => fanFecharMercado($pdo),
        'reabrir'  => fanReabrirMercado($pdo),
        'encerrar' => fanEncerrarRodada($pdo),
        default    => ['ok' => false, 'erro' => 'Ação inválida.'],
    };
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[fantasy/api] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro no servidor.']);
}
