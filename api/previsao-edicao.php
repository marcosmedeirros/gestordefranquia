<?php
/**
 * API do palpite da edição (ver backend/previsao_edicao.php).
 *
 *   POST {action:'salvar', picks:{sobe:[ids], cai:[ids], top3:[ids]}}
 *   POST {action:'pular'}
 *   GET  ?action=resultado        só admin global: votos por time e bloco
 *
 * A liga NUNCA vem do pedido: sai do time do próprio usuário, porque é ela que
 * decide o que se pergunta e de quais times se escolhe.
 */

require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/previsao_edicao.php';

header('Content-Type: application/json; charset=utf-8');
requireAuth();

$user   = getUserSession();
$pdo    = db();
$userId = (int)($user['id'] ?? 0);

function prevResponde(array $d, int $http = 200): never
{
    http_response_code($http);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'];
$corpo  = $metodo === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
$acao   = $_GET['action'] ?? $corpo['action'] ?? '';

if ($acao === 'resultado') {
    if (($user['user_type'] ?? 'jogador') !== 'admin') prevResponde(['success' => false, 'error' => 'Acesso negado'], 403);
    prevResponde(['success' => true] + previsaoResultado($pdo));
}

if ($metodo !== 'POST') prevResponde(['success' => false, 'error' => 'Método inválido'], 405);

// Palpite encerrado (PREVISAO_ATIVA em backend/previsao_edicao.php): nem voto nem pulo.
if (!PREVISAO_ATIVA) prevResponde(['success' => false, 'error' => 'Este palpite foi encerrado.'], 410);

$st = $pdo->prepare('SELECT league FROM teams WHERE user_id = ? LIMIT 1');
$st->execute([$userId]);
$liga = strtoupper(trim((string)($st->fetchColumn() ?: '')));
if ($liga === '') prevResponde(['success' => false, 'error' => 'Você precisa ter um time.'], 403);

if ($acao === 'salvar') {
    $r = previsaoSalvar($pdo, $userId, $liga, is_array($corpo['picks'] ?? null) ? $corpo['picks'] : []);
    prevResponde($r['ok'] ? ['success' => true] : ['success' => false, 'error' => $r['erro']], $r['ok'] ? 200 : 422);
}

if ($acao === 'pular') {
    previsaoPular($pdo, $userId, $liga);
    prevResponde(['success' => true]);
}

prevResponde(['success' => false, 'error' => 'Ação inválida'], 400);
