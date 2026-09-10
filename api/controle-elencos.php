<?php
/**
 * API do controle de elencos (admin).
 *
 *   GET  ?acao=times&liga=X              → cards: o que falta em cada time
 *   GET  ?acao=elenco&liga=X[&time=ID]   → jogadores de um time, ou da liga toda
 *   POST acao=salvar {liga, tipo, linhas} → grava o que foi revisado
 *
 * A regra mora em backend/controle_elencos.php; aqui é só a porta, com o
 * escopo de admin: admin geral vê todas as ligas, admin de liga só as dele.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/controle_elencos.php';

$user = getUserSession();
if (!$user) { http_response_code(401); echo json_encode(['ok' => false, 'erro' => 'Sessão expirada.']); exit; }

$pdo = db();
// Aberta a qualquer usuário logado (pedido de 10/09/2026): a edição de letras
// e estatísticas foi centralizada aqui, com atalho na página de stats. Cada
// gravação continua no histórico com o id de quem enviou, e dá pra reverter.
$ligas = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

$corpo = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $corpo = json_decode(file_get_contents('php://input'), true) ?: [];
}
$acao = (string)($_GET['acao'] ?? $corpo['acao'] ?? '');
$liga = strtoupper(trim((string)($_GET['liga'] ?? $corpo['liga'] ?? '')));

if (!in_array($liga, $ligas, true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Você não administra esta liga.']);
    exit;
}

if ($acao === 'times') {
    echo json_encode(['ok' => true] + ceTimesDaLiga($pdo, $liga), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'elenco') {
    $timeId = (int)($_GET['time'] ?? 0);
    if ($timeId) {
        // O time tem que ser da liga pedida — senão o escopo de admin de liga
        // seria contornado trocando só o número do time na URL.
        $st = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
        $st->execute([$timeId]);
        if (strtoupper((string)$st->fetchColumn()) !== $liga) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'erro' => 'Time não encontrado nesta liga.']);
            exit;
        }
    }
    $temporadaId = (int)($_GET['temporada'] ?? 0) ?: null;
    echo json_encode(['ok' => true, 'jogadores' => ceJogadores($pdo, $liga, $timeId ?: null, $temporadaId)],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'salvar') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405); echo json_encode(['ok' => false, 'erro' => 'Use POST.']); exit;
    }
    // Admin não recebe moeda; o resto recebe por time (ver ceGravar).
    $ehAdmin = ($user['user_type'] ?? 'jogador') === 'admin'
            || in_array($liga, getAdminLeagues($pdo, (int)$user['id']), true);
    $r = ceGravar($pdo, (int)$user['id'], $liga, (string)($corpo['tipo'] ?? ''),
                  is_array($corpo['linhas'] ?? null) ? $corpo['linhas'] : [], $ehAdmin,
                  (int)($corpo['temporada'] ?? 0) ?: null);
    if (!$r['ok']) http_response_code(400);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'erro' => 'Ação desconhecida.']);
