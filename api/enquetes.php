<?php
/**
 * API das enquetes. Toda decisão de moeda mora no motor
 * (games/core/enquetes_motor.php); aqui é só a porta.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/games/core/enquetes_motor.php';

$user = getUserSession();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Faça login.']);
    exit;
}

$pdo = db();
enqTabelas($pdo);
$uid     = (int)$user['id'];
$ehAdmin = ($user['user_type'] ?? '') === 'admin';
$corpo   = json_decode(file_get_contents('php://input'), true) ?: [];
$acao    = $_GET['acao'] ?? $corpo['acao'] ?? 'listar';

/** Uma enquete pronta pra tela, com as odds de agora. */
function enqMontar(PDO $pdo, array $e, int $uid): array
{
    $sa = $pdo->prepare("SELECT * FROM enquete_alternativas WHERE enquete_id = ? ORDER BY ordem, id");
    $sa->execute([(int)$e['id']]);
    $alts  = $sa->fetchAll(PDO::FETCH_ASSOC);
    $somas = enqSomas($pdo, (int)$e['id']);
    $odds  = enqOddsAtuais($alts, $somas);

    // O que EU apostei, por alternativa: é o que a tela marca como "sua".
    $sm = $pdo->prepare("SELECT alternativa_id, SUM(valor) v FROM enquete_apostas
                          WHERE enquete_id = ? AND id_usuario = ? GROUP BY alternativa_id");
    $sm->execute([(int)$e['id'], $uid]);
    $meu = [];
    foreach ($sm->fetchAll(PDO::FETCH_ASSOC) as $m) $meu[(int)$m['alternativa_id']] = (int)$m['v'];

    return [
        'id'         => (int)$e['id'],
        'titulo'     => $e['titulo'],
        'descricao'  => $e['descricao'],
        'status'     => $e['status'],
        'criador'    => $e['criador_nome'] ?? '',
        'criador_id' => (int)$e['criador_id'],
        'sou_dono'   => (int)$e['criador_id'] === $uid,
        'max_pessoa' => (int)$e['max_por_pessoa'],
        'max_total'  => (int)$e['max_total'],
        'apostado'   => $somas['total'],
        'retido'     => (int)$e['retido'],
        'vencedora'  => $e['vencedora_id'] ? (int)$e['vencedora_id'] : null,
        'fecha_em'   => $e['fecha_em'],
        'meu_total'  => array_sum($meu),
        'alternativas' => array_map(fn($a) => [
            'id'    => (int)$a['id'],
            'texto' => $a['texto'],
            'odd'   => $odds[(int)$a['id']] ?? (float)$a['odd_inicial'],
            'odd_inicial' => (float)$a['odd_inicial'],
            'apostado' => $somas['porAlt'][(int)$a['id']] ?? 0,
            'meu'   => $meu[(int)$a['id']] ?? 0,
        ], $alts),
    ];
}

try {
    if ($acao === 'listar') {
        $st = $pdo->prepare("SELECT e.*, g.nome AS criador_nome
                             FROM enquetes e LEFT JOIN games_usuarios g ON g.id = e.criador_id
                             ORDER BY FIELD(e.status,'aberta','fechada','paga','cancelada'), e.id DESC
                             LIMIT 60");
        $st->execute();
        $lista = array_map(fn($e) => enqMontar($pdo, $e, $uid), $st->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode([
            'ok' => true, 'enquetes' => $lista,
            'saldo' => enqSaldo($pdo, $uid),
            'livre' => enqSaldoLivre($pdo, $uid),
            'retido' => enqRetidoTotal($pdo, $uid),
            'admin' => $ehAdmin,
            'limites' => ['odd_min' => ENQ_ODD_MIN, 'odd_max' => ENQ_ODD_MAX,
                          'aposta_min' => ENQ_APOSTA_MIN, 'dias_max' => ENQ_DIAS_MAX,
                          'taxa' => ENQ_TAXA_CASA, 'alt_max' => ENQ_ALT_MAX],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($acao === 'criar') {
        $r = enqCriar($pdo, $uid, $corpo);
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($acao === 'apostar') {
        $r = enqApostar($pdo, $uid, (int)($corpo['enquete_id'] ?? 0),
                        (int)($corpo['alternativa_id'] ?? 0), (int)($corpo['valor'] ?? 0));
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($acao === 'fechar') {
        $r = enqFechar($pdo, $uid, (int)($corpo['enquete_id'] ?? 0),
                       (int)($corpo['alternativa_id'] ?? 0), $ehAdmin);
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($acao === 'cancelar') {
        $r = enqCancelar($pdo, $uid, (int)($corpo['enquete_id'] ?? 0), $ehAdmin);
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($acao === 'extrato') {
        // Quem declarou o resultado e pra onde foi cada moeda. É o contrapeso
        // de deixar o criador declarar sozinho: dá pra conferir depois.
        $id = (int)($_GET['id'] ?? 0);
        $st = $pdo->prepare("SELECT x.*, g.nome FROM enquete_extrato x
                             LEFT JOIN games_usuarios g ON g.id = x.id_usuario
                             WHERE x.enquete_id = ? ORDER BY x.id");
        $st->execute([$id]);
        $q = $pdo->prepare("SELECT e.resultado_por, g.nome FROM enquetes e
                            LEFT JOIN games_usuarios g ON g.id = e.resultado_por WHERE e.id = ?");
        $q->execute([$id]);
        echo json_encode(['ok' => true, 'linhas' => $st->fetchAll(PDO::FETCH_ASSOC),
                          'resultado_por' => $q->fetch(PDO::FETCH_ASSOC) ?: null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Ação desconhecida.']);
} catch (Throwable $e) {
    error_log('[enquetes/api] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro interno.']);
}
