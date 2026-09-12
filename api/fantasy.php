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
        // ?liga=ID → a tabela ou a chave de uma liga do usuário (modal da aba Ranking).
        if (isset($_GET['liga'])) {
            echo json_encode(fanTabelaLiga($pdo, (int)$user['id'], (int)$_GET['liga']), JSON_UNESCAPED_UNICODE);
            exit;
        }
        // ?convite=CODIGO → nome, formato e entrada da liga, antes de entrar.
        if (isset($_GET['convite'])) {
            echo json_encode(fanPreviaConvite($pdo, (int)$user['id'], (string)$_GET['convite']), JSON_UNESCAPED_UNICODE);
            exit;
        }
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
        'criar_liga'   => fanCriarLiga($pdo, $user, (string)($corpo['nome'] ?? ''), (string)($corpo['tipo'] ?? ''), (int)($corpo['entrada'] ?? 0), (int)($corpo['tamanho'] ?? 0)),
        'entrar_liga'  => fanEntrarLiga($pdo, $user, (string)($corpo['codigo'] ?? '')),
        'iniciar_liga' => fanIniciarMataMata($pdo, $user, (int)($corpo['liga_id'] ?? 0)),
        'sair_liga'    => fanSairLiga($pdo, $user, (int)($corpo['liga_id'] ?? 0)),
        default    => ['ok' => false, 'erro' => 'Ação inválida.'],
    };
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[fantasy/api] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro no servidor.']);
}
