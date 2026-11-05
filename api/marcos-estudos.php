<?php
/**
 * API da página de estudos do Marcos (marcos-estudos.php). SÓ O DONO, só leitura.
 *
 *   GET ?acao=lista&grupo=elite|outras|historico   uma linha por jogador, com as métricas
 *   GET ?acao=vida&id=N                            a carreira completa de uma vida
 *   GET ?acao=nomes                                nomes com 2+ vidas (a lista do estudo por nome)
 *   GET ?acao=nome&nome=X[&min=3]                  todas as vidas de um nome e a média delas
 *
 * As contas moram em backend/estudos_jogadores.php.
 */
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/estudos_jogadores.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
requireAuth();

/** O mesmo dono do marcos-dev.php. */
const DONO_ESTUDOS = 'medeirros99@gmail.com';

function estResponde(array $d, int $http = 200): never
{
    http_response_code($http);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

$user = getUserSession();
if (strtolower(trim((string)($user['email'] ?? ''))) !== DONO_ESTUDOS) {
    estResponde(['ok' => false, 'erro' => 'Sem acesso.'], 403);
}

// O histórico completo carrega ~36 mil linhas: dá folga de memória só aqui.
@ini_set('memory_limit', '512M');
// A lista grande passa de 2 MB; comprimida cai pra uma fração.
if (function_exists('ob_gzhandler') && !headers_sent()) ob_start('ob_gzhandler');

$pdo  = db();
$acao = (string)($_GET['acao'] ?? '');

try {
    if ($acao === 'lista') {
        $grupo = (string)($_GET['grupo'] ?? '');
        if (!in_array($grupo, ['elite', 'outras', 'historico'], true)) estResponde(['ok' => false, 'erro' => 'Grupo inválido.'], 400);
        estResponde(['ok' => true, 'grupo' => $grupo, 'linhas' => estudosLista($pdo, $grupo)]);
    }
    if ($acao === 'vida') {
        $v = estudosVida($pdo, (int)($_GET['id'] ?? 0));
        if (!$v) estResponde(['ok' => false, 'erro' => 'Jogador não encontrado.'], 404);
        estResponde(['ok' => true] + $v);
    }
    if ($acao === 'nomes') {
        estResponde(['ok' => true, 'nomes' => estudosNomes($pdo)]);
    }
    if ($acao === 'nome') {
        $nome = trim((string)($_GET['nome'] ?? ''));
        if ($nome === '') estResponde(['ok' => false, 'erro' => 'Informe o nome.'], 400);
        $min = max(1, min(12, (int)($_GET['min'] ?? ESTUDOS_MIN_TEMP_COORTE)));
        $r = estudosNome($pdo, $nome, $min);
        estResponde($r, $r['ok'] ? 200 : 404);
    }
} catch (Throwable $e) {
    error_log('[marcos-estudos] ' . $e->getMessage());
    estResponde(['ok' => false, 'erro' => 'Falhou ao consultar: ' . $e->getMessage()], 500);
}

estResponde(['ok' => false, 'erro' => 'Ação desconhecida.'], 400);
