<?php
/**
 * Administração do quiz: banco de perguntas, rodada em aberto e apuração.
 *
 * Só admin global — o quiz é do grupo principal, não de uma liga.
 */
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/backend/helpers.php';
require_once dirname(__DIR__) . '/backend/quiz.php';

$user = getUserSession();
if (!$user || ($user['user_type'] ?? 'jogador') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Apenas admin geral']);
    exit;
}

$pdo = db();
quizGarantirTabelas($pdo);

function qaErro(int $http, string $msg): void {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function qaCorpo(): array { return json_decode(file_get_contents('php://input'), true) ?: []; }

$acao = $_GET['action'] ?? qaCorpo()['action'] ?? 'estado';

try {
    if ($acao === 'estado') {
        $tot = $pdo->query("SELECT
            COUNT(*) total,
            SUM(tipo='certa') certas,
            SUM(tipo='votos') votos,
            SUM(usada_em IS NULL AND ativa=1) inéditas,
            SUM(ativa=0) inativas
            FROM quiz_perguntas")->fetch(PDO::FETCH_ASSOC);

        $st = $pdo->query("SELECT r.id, r.aberta_em, r.fecha_em, r.grupo_jid,
                                  p.texto, p.tipo, p.op1, p.op2, p.op3, p.op4,
                                  (SELECT COUNT(*) FROM quiz_votos v WHERE v.rodada_id = r.id) votos
                           FROM quiz_rodadas r JOIN quiz_perguntas p ON p.id = r.pergunta_id
                           WHERE r.fechada_em IS NULL ORDER BY r.id DESC LIMIT 1");
        $aberta = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        $ultimas = $pdo->query("SELECT r.id, r.fechada_em, r.vencedora, p.texto, p.tipo,
                                       (SELECT COUNT(*) FROM quiz_votos v WHERE v.rodada_id = r.id) votos
                                FROM quiz_rodadas r JOIN quiz_perguntas p ON p.id = r.pergunta_id
                                WHERE r.fechada_em IS NOT NULL
                                ORDER BY r.fechada_em DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'contagem' => $tot, 'aberta' => $aberta,
                          'ultimas' => $ultimas, 'premio' => QUIZ_PREMIO]);
        exit;
    }

    if ($acao === 'listar') {
        $tipo = $_GET['tipo'] ?? '';
        $busca = trim((string)($_GET['q'] ?? ''));
        $sql = "SELECT id, tipo, categoria, texto, op1, op2, op3, op4, correta, explicacao, ativa, usada_em
                FROM quiz_perguntas WHERE 1";
        $args = [];
        if (in_array($tipo, ['certa','votos'], true)) { $sql .= " AND tipo = ?"; $args[] = $tipo; }
        if ($busca !== '') { $sql .= " AND texto LIKE ?"; $args[] = '%' . $busca . '%'; }
        $sql .= " ORDER BY id DESC LIMIT 300";
        $st = $pdo->prepare($sql);
        $st->execute($args);
        echo json_encode(['success' => true, 'perguntas' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($acao === 'salvar') {
        $c = qaCorpo();
        $id    = (int)($c['id'] ?? 0);
        $tipo  = in_array($c['tipo'] ?? '', ['certa','votos'], true) ? $c['tipo'] : 'certa';
        $texto = trim((string)($c['texto'] ?? ''));
        $ops   = array_map(fn($o) => trim((string)$o), (array)($c['opcoes'] ?? []));
        $cat   = trim((string)($c['categoria'] ?? '')) ?: null;
        $exp   = trim((string)($c['explicacao'] ?? '')) ?: null;

        if ($texto === '') qaErro(400, 'Escreva a pergunta.');
        if (count($ops) !== 4 || count(array_filter($ops, fn($o) => $o !== '')) !== 4) {
            qaErro(400, 'Preencha as quatro opções.');
        }
        if (count(array_unique($ops)) !== 4) qaErro(400, 'As quatro opções precisam ser diferentes.');

        // Na de resposta certa a opção é obrigatória. Sem ela o bot apuraria
        // como se fosse voto, e a pergunta que TEM resposta viraria enquete.
        $correta = null;
        if ($tipo === 'certa') {
            $correta = (int)($c['correta'] ?? 0);
            if ($correta < 1 || $correta > 4) qaErro(400, 'Marque qual das quatro é a resposta certa.');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE quiz_perguntas SET tipo=?, categoria=?, texto=?,
                           op1=?, op2=?, op3=?, op4=?, correta=?, explicacao=? WHERE id=?")
                ->execute([$tipo, $cat, $texto, $ops[0], $ops[1], $ops[2], $ops[3], $correta, $exp, $id]);
            echo json_encode(['success' => true, 'id' => $id, 'message' => 'Pergunta atualizada.']);
        } else {
            $pdo->prepare("INSERT INTO quiz_perguntas (tipo, categoria, texto, op1, op2, op3, op4, correta, explicacao, criada_por)
                           VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$tipo, $cat, $texto, $ops[0], $ops[1], $ops[2], $ops[3], $correta, $exp, (int)$user['id']]);
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Pergunta criada.']);
        }
        exit;
    }

    if ($acao === 'alternar') {
        $id = (int)(qaCorpo()['id'] ?? 0);
        if (!$id) qaErro(400, 'id obrigatório');
        $pdo->prepare("UPDATE quiz_perguntas SET ativa = 1 - ativa WHERE id = ?")->execute([$id]);
        $st = $pdo->prepare("SELECT ativa FROM quiz_perguntas WHERE id = ?");
        $st->execute([$id]);
        $ativa = (int)$st->fetchColumn();
        echo json_encode(['success' => true, 'ativa' => $ativa,
            'message' => $ativa ? 'Voltou pro sorteio.' : 'Fora do sorteio.']);
        exit;
    }

    if ($acao === 'excluir') {
        $id = (int)(qaCorpo()['id'] ?? 0);
        if (!$id) qaErro(400, 'id obrigatório');
        // Rodada guarda o resultado do dia; apagar a pergunta levaria junto.
        $st = $pdo->prepare("SELECT COUNT(*) FROM quiz_rodadas WHERE pergunta_id = ?");
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) {
            qaErro(409, 'Essa pergunta já foi ao ar — desative em vez de apagar, senão o resultado daquele dia some junto.');
        }
        $pdo->prepare("DELETE FROM quiz_perguntas WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Pergunta apagada.']);
        exit;
    }

    /** Carrega o banco inicial. Rodar de novo não duplica. */
    if ($acao === 'popular') {
        $sementes = require dirname(__DIR__) . '/backend/seeds/quiz_perguntas.php';
        $existe = $pdo->prepare("SELECT COUNT(*) FROM quiz_perguntas WHERE texto = ?");
        $ins = $pdo->prepare("INSERT INTO quiz_perguntas (tipo, categoria, texto, op1, op2, op3, op4, correta, explicacao)
                              VALUES (?,?,?,?,?,?,?,?,?)");
        $novas = 0; $puladas = 0;
        foreach ($sementes as $s) {
            [$tipo, $cat, $texto, $ops, $correta, $exp] = $s + [null, null, null, null, null, null];
            $existe->execute([$texto]);
            if ((int)$existe->fetchColumn() > 0) { $puladas++; continue; }
            $ins->execute([$tipo, $cat, $texto, $ops[0], $ops[1], $ops[2], $ops[3], $correta, $exp]);
            $novas++;
        }
        echo json_encode(['success' => true, 'novas' => $novas, 'puladas' => $puladas,
            'message' => $novas . ' pergunta(s) adicionadas'
                       . ($puladas ? ', ' . $puladas . ' já existiam' : '') . '.']);
        exit;
    }

    /** Fecha a rodada aberta na hora, sem esperar o prazo. */
    if ($acao === 'apurar_agora') {
        $st = $pdo->query("SELECT id, grupo_jid FROM quiz_rodadas WHERE fechada_em IS NULL ORDER BY id DESC LIMIT 1");
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) qaErro(409, 'Não há rodada aberta.');
        $txt = quizFechar($pdo, (int)$r['id']);
        if ($txt === null) qaErro(409, 'Não deu pra apurar.');
        whatsappEnfileirar($pdo, $r['grupo_jid'], $txt, true, 'quiz');
        echo json_encode(['success' => true, 'message' => 'Apurado e postado no grupo.']);
        exit;
    }

    /** Manda a pergunta do dia agora, fora do horário. */
    if ($acao === 'abrir_agora') {
        $grupo = trim((string)($pdo->query("SELECT grupo_principal FROM whatsapp_config WHERE id = 1")->fetchColumn() ?: ''));
        if ($grupo === '') qaErro(409, 'Sem grupo principal configurado.');
        $txt = quizAbrir($pdo, $grupo);
        if ($txt === null) qaErro(409, 'Já há rodada aberta, ou o banco de perguntas está vazio.');
        if (!whatsappEnfileirar($pdo, $grupo, $txt, true, 'quiz')) qaErro(409, 'O bot está desligado.');
        echo json_encode(['success' => true, 'message' => 'Pergunta postada no grupo.']);
        exit;
    }

    qaErro(400, 'Ação desconhecida.');
} catch (Throwable $e) {
    error_log('[quiz-admin] ' . $acao . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno. O admin foi avisado no log.']);
}
