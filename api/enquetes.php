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
$uid     = (int)$user['id'];
$ehAdmin = ($user['user_type'] ?? '') === 'admin';

/*
 * CRIAR É SÓ DO ADMIN; APOSTAR É DE TODO MUNDO.
 *
 * A trava começou valendo pra tudo, e aí o sistema não funcionava: só admin
 * entrava, só ele criava, e quem cria não pode apostar na própria enquete —
 * então não havia ninguém pra apostar em nada.
 *
 * O motivo da trava era o criador declarar o próprio resultado. Isso se
 * resolve limitando quem CRIA, não quem aposta: com as enquetes saindo de
 * quem responde pela liga, a liga inteira pode participar delas.
 */
enqTabelas($pdo);
$corpo   = json_decode(file_get_contents('php://input'), true) ?: [];
$acao    = $_GET['acao'] ?? $corpo['acao'] ?? 'listar';

// A ação precisa estar lida ANTES da trava — senão ela testa um $acao vazio
// e nunca barra ninguém.
$soAdmin = ['criar'];
if (in_array($acao, $soAdmin, true) && !$ehAdmin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Por enquanto, só o admin geral cria enquetes.']);
    exit;
}

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
        'categoria'  => $e['categoria'] ?: '',
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
            // A lista vem do motor pra tela não ter a própria cópia dela.
            'categorias' => ENQ_CATEGORIAS,
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

    /*
     * AS AÇÕES DO PAINEL DE ADMIN.
     *
     * Declarar o resultado é do dono, e a API barra o admin no caminho normal
     * (enqFechar recusa quem não criou). Aqui é outro caminho, de outra tela:
     * o admin geral precisa poder resolver uma aposta cujo dono sumiu, ou
     * consertar um resultado declarado errado. Fica separado justamente pra
     * essa diferença ser explícita — e não um `if ($ehAdmin)` escondido no
     * meio do fluxo que todo GM usa.
     */
    if (str_starts_with($acao, 'admin_')) {
        if (!$ehAdmin) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'erro' => 'Só o admin geral.']);
            exit;
        }

        if ($acao === 'admin_listar') {
            $st = $pdo->prepare("SELECT e.*, g.nome AS criador_nome
                                 FROM enquetes e LEFT JOIN games_usuarios g ON g.id = e.criador_id
                                 ORDER BY FIELD(e.status,'aberta','fechada','paga','cancelada'), e.id DESC
                                 LIMIT 200");
            $st->execute();
            // Monta com o uid do CRIADOR, não o do admin: 'sou_dono' e 'meu'
            // são do ponto de vista de quem olha, e no painel quem olha é a
            // administração — o que interessa ali é o estado da aposta.
            $lista = array_map(fn($e) => enqMontar($pdo, $e, (int)$e['criador_id']), $st->fetchAll(PDO::FETCH_ASSOC));
            echo json_encode(['ok' => true, 'enquetes' => $lista,
                              'categorias' => ENQ_CATEGORIAS], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($acao === 'admin_fechar') {
            // Passa o criador como autor: o pagamento é dele pra quem apostou,
            // e enqFechar cobra que quem declara seja o dono.
            $enqId = (int)($corpo['enquete_id'] ?? 0);
            $st = $pdo->prepare("SELECT criador_id FROM enquetes WHERE id = ?");
            $st->execute([$enqId]);
            $dono = (int)($st->fetchColumn() ?: 0);
            if (!$dono) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'erro' => 'Aposta não encontrada.']);
                exit;
            }
            $r = enqFechar($pdo, $dono, $enqId, (int)($corpo['alternativa_id'] ?? 0), true);
            // Quem declarou de verdade fica registrado: o extrato é o que
            // permite conferir depois, e dizer que foi o dono seria mentira.
            if (!empty($r['ok'])) {
                $pdo->prepare("UPDATE enquetes SET resultado_por = ? WHERE id = ?")
                    ->execute([$uid, $enqId]);
            }
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($acao === 'admin_cancelar') {
            $r = enqCancelar($pdo, $uid, (int)($corpo['enquete_id'] ?? 0), true);
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'erro' => 'Ação de admin desconhecida.']);
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
