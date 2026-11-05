<?php
/**
 * API da página de estudos do Marcos (marcos-estudos.php). SÓ O DONO.
 *
 * Leitura (GET):
 *   ?acao=lista&grupo=elite|outras|historico|favoritos|lixeira   uma linha por jogador, com as métricas
 *   ?acao=vida&id=N                                              a carreira completa de uma vida
 *   ?acao=nomes                                                  nomes com 2+ vidas (a lista do estudo por nome)
 *   ?acao=nome&nome=X[&min=3][&lendas=1]                         todas as vidas de um nome e a média delas
 *   Todas aceitam as opções de peak: &plato=1&queda=1&atrib=0&ponto=queda|ultimo
 *
 * Escrita (POST, corpo JSON) — só mexe na camada do Marcos (estudos_ajustes.php), nunca no histórico do jogo:
 *   acao=fav        {id, valor}          favorita / desfavorita
 *   acao=excluir    {id, valor}          manda pra lixeira / restaura
 *   acao=salvar     {id, nome, lenda, nota, tshot, ttouch, temporadas:[...]}   o editor
 *   acao=reverter   {id}                 desfaz as correções do jogador
 *   acao=nova_vida  {nome}               cria um jogador na mão
 *   acao=real       {nome, pe, ap, nota} estimativa da VIDA REAL (peak end e aposentadoria) de um nome; vazio = não sei
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
$opc  = estudosOpcoes($_GET);
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$corpo = $post ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];

try {
    if ($post) {
        $id = (int)($corpo['id'] ?? 0);
        if ($acao === 'real') {   // estimativa da vida real, por NOME (vale pra todas as vidas dele)
            estudosReaisSalvar($pdo, (string)($corpo['nome'] ?? ''), $corpo['pe'] ?? null, $corpo['ap'] ?? null, (string)($corpo['nota'] ?? ''));
            estResponde(['ok' => true]);
        }
        if ($acao === 'nova_vida') {
            $novo = estudosNovaVida($pdo, (string)($corpo['nome'] ?? ''));
            estResponde(['ok' => true, 'id' => $novo]);
        }
        if (!in_array($acao, ['fav', 'excluir', 'salvar', 'reverter'], true)) estResponde(['ok' => false, 'erro' => 'Ação desconhecida.'], 400);
        if ($id <= 0) estResponde(['ok' => false, 'erro' => 'Jogador inválido.'], 400);
        if ($acao === 'fav')      estudosFavoritar($pdo, $id, !empty($corpo['valor']));
        if ($acao === 'excluir')  estudosExcluir($pdo, $id, !empty($corpo['valor']));
        if ($acao === 'reverter') estudosReverter($pdo, $id);
        if ($acao === 'salvar')   estudosSalvarVida($pdo, $id, $corpo);
        estResponde(['ok' => true, 'id' => $id]);
    }

    if ($acao === 'lista') {
        $grupo = (string)($_GET['grupo'] ?? '');
        if (!in_array($grupo, ['elite', 'outras', 'historico', 'favoritos', 'lixeira'], true)) estResponde(['ok' => false, 'erro' => 'Grupo inválido.'], 400);
        estResponde(['ok' => true, 'grupo' => $grupo, 'linhas' => estudosLista($pdo, $grupo, $opc)]);
    }
    if ($acao === 'vida') {
        $v = estudosVida($pdo, (int)($_GET['id'] ?? 0), $opc);
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
        $r = estudosNome($pdo, $nome, $min, $opc, !empty($_GET['lendas']));
        estResponde($r, $r['ok'] ? 200 : 404);
    }
} catch (InvalidArgumentException $e) {
    estResponde(['ok' => false, 'erro' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[marcos-estudos] ' . $e->getMessage());
    estResponde(['ok' => false, 'erro' => 'Falhou ao consultar: ' . $e->getMessage()], 500);
}

estResponde(['ok' => false, 'erro' => 'Ação desconhecida.'], 400);
