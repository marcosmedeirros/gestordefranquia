<?php
/**
 * API do acompanhamento da atualização de elenco (admin). A conta e o motivo
 * estão em backend/trava_elenco.php — e ela NÃO barra troca de ninguém.
 *
 *   GET  ?league=X                            → quem está em dia e quem deve
 *   POST {action:'progressao', league}        → "saiu a progression": tira a foto das letras
 *   POST {action:'desfazer_progressao', league}
 */
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/trava_elenco.php';
header('Content-Type: application/json; charset=utf-8');

requireAuth();
$user = getUserSession();
$pdo  = db();
$uid  = (int)($user['id'] ?? 0);
if (!hasAdminAccess($pdo, $uid)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
    exit;
}

$corpo = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
$liga  = strtoupper(trim((string)($_GET['league'] ?? $corpo['league'] ?? '')));
if (!in_array($liga, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
    echo json_encode(['success' => false, 'error' => 'Liga inválida']);
    exit;
}
// Admin de liga só mexe na dele; o geral, em todas.
if (($user['user_type'] ?? '') !== 'admin' && !in_array($liga, getAdminLeagues($pdo, $uid), true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Você não administra esta liga.']);
    exit;
}

function tvResponde(array $d, int $http = 200): never
{
    http_response_code($http);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($corpo['action'] ?? '');
    if ($acao === 'progressao') {
        $r = travaElencoLiberarProgressao($pdo, $liga, $uid);
        if (!$r['ok']) tvResponde(['success' => false, 'error' => $r['erro'] ?? 'Falhou.'], 400);
    } elseif ($acao === 'desfazer_progressao') {
        travaElencoDesfazerProgressao($pdo, $liga);
    } else {
        tvResponde(['success' => false, 'error' => 'Ação desconhecida.'], 400);
    }
    // Depois de mexer, devolve o estado novo: a tela redesenha com ele.
}

$cfg  = travaElencoConfig($pdo, $liga);
$temp = temporadaAtivaDaLiga($pdo, $liga);
$emProg = travaElencoEmProgressao($cfg, $temp);
$times = travaElencoTimesDaLiga($pdo, $liga);

tvResponde([
    'success'        => true,
    'league'         => $liga,
    'min_jogadores'  => (int)$cfg['min_jogadores'],
    'modo'           => $emProg ? 'letras' : 'estatisticas',
    'progressao_em'  => $emProg ? $cfg['progressao_em'] : null,
    // Quem clicou, pro painel avisar antes de um segundo admin clicar junto.
    'progressao_por' => $emProg ? travaElencoQuemMarcou($pdo, $liga) : null,
    'temporada'      => $temp ? (int)$temp['season_number'] : null,
    'times_total'    => count($times),
    'times_em_falta' => count(array_filter($times, fn($t) => $t['em_falta'])),
    'times'          => $times,
]);
