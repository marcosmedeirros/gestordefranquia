<?php
/**
 * API do calendário das ligas.
 *
 *   GET  ?acao=eventos&de=YYYY-MM-DD&ate=YYYY-MM-DD[&ligas=ELITE,NEXT]
 *   GET  ?acao=proximos[&ligas=...]
 *   POST acao=salvar     → cria ou edita (admin da liga do evento)
 *   POST acao=apagar     → remove (admin da liga do evento)
 *
 * Ler é livre pra quem está logado: o calendário é da organização e ninguém
 * ganha nada escondendo a data da live das outras ligas. Escrever exige ser
 * admin DAQUELA liga — admin da NEXT não marca evento na ELITE.
 */

require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/calendario.php';

header('Content-Type: application/json; charset=utf-8');

$user = getUserSession();
if (!$user) { http_response_code(401); echo json_encode(['erro' => 'Sessão expirada.']); exit; }

$pdo = db();
ensureCalendarioTables($pdo);
$uid = (int)$user['id'];

// A ação pode vir da query ou do corpo JSON. O PHP não preenche $_POST pra
// application/json, e ler só dele já quebrou o salvamento de outra tela.
$corpoBruto = file_get_contents('php://input');
$corpoJson  = ($corpoBruto !== '' && str_starts_with(ltrim($corpoBruto), '{'))
            ? (json_decode($corpoBruto, true) ?: [])
            : [];
$acao = $_GET['acao'] ?? $_POST['acao'] ?? ($corpoJson['acao'] ?? '');

$minhasLigasAdmin = getAdminLeagues($pdo, $uid);
$podeNaLiga = fn(string $liga) => in_array(strtoupper($liga), array_map('strtoupper', $minhasLigasAdmin), true);

/** As ligas pedidas na query, ou todas quando não vier nada. */
$ligasPedidas = function () {
    $q = trim((string)($_GET['ligas'] ?? ''));
    if ($q === '') return CALENDARIO_LIGAS;
    $l = array_filter(array_map('trim', explode(',', strtoupper($q))));
    $l = array_values(array_intersect($l, CALENDARIO_LIGAS));
    return $l ?: CALENDARIO_LIGAS;
};

// ── Eventos de um intervalo ──────────────────────────────────────────
if ($acao === 'eventos') {
    $de  = trim((string)($_GET['de'] ?? ''));
    $ate = trim((string)($_GET['ate'] ?? ''));
    if (!strtotime($de) || !strtotime($ate)) {
        http_response_code(400); echo json_encode(['erro' => 'Intervalo inválido.']); exit;
    }
    $eventos = calendarioEventos($pdo, $ligasPedidas(),
        date('Y-m-d 00:00:00', strtotime($de)), date('Y-m-d 23:59:59', strtotime($ate)));

    echo json_encode([
        'ok' => true,
        'eventos' => array_map(function ($e) use ($podeNaLiga) {
            $e['id'] = (int)$e['id'];
            $e['dia_inteiro'] = (bool)$e['dia_inteiro'];
            $e['cor'] = calendarioCor($e['league']);
            $e['tipo_rotulo'] = calendarioRotuloTipo($e['tipo']);
            $e['tipo_icone'] = calendarioIconeTipo($e['tipo']);
            // Vai por evento pra tela não precisar repetir a regra: ela só
            // desenha o lápis onde este campo for verdadeiro.
            $e['posso_editar'] = $podeNaLiga($e['league']);
            return $e;
        }, $eventos),
    ]);
    exit;
}

// ── Próximos eventos ─────────────────────────────────────────────────
if ($acao === 'proximos') {
    $eventos = calendarioProximos($pdo, $ligasPedidas(), (int)($_GET['limite'] ?? 8));
    echo json_encode(['ok' => true, 'eventos' => array_map(function ($e) {
        $e['id'] = (int)$e['id'];
        $e['dia_inteiro'] = (bool)$e['dia_inteiro'];
        $e['cor'] = calendarioCor($e['league']);
        $e['tipo_rotulo'] = calendarioRotuloTipo($e['tipo']);
        $e['tipo_icone'] = calendarioIconeTipo($e['tipo']);
        return $e;
    }, $eventos)]);
    exit;
}

// ── Criar ou editar ──────────────────────────────────────────────────
if ($acao === 'salvar') {
    [$valido, $dados, $erro] = calendarioValidar($corpoJson);
    if (!$valido) { http_response_code(400); echo json_encode(['erro' => $erro]); exit; }

    if (!$podeNaLiga($dados['league'])) {
        http_response_code(403);
        echo json_encode(['erro' => 'Você não administra a ' . $dados['league'] . '.']);
        exit;
    }

    $id = (int)($corpoJson['id'] ?? 0);
    try {
        if ($id > 0) {
            // Na edição, a liga ATUAL do evento também precisa ser sua — senão
            // dava pra "mover" um evento da ELITE pra NEXT sem administrar a
            // ELITE, e o evento sairia do calendário de quem cuida dele.
            $st = $pdo->prepare("SELECT league FROM calendario_eventos WHERE id = ?");
            $st->execute([$id]);
            $ligaAtual = $st->fetchColumn();
            if ($ligaAtual === false) {
                http_response_code(404); echo json_encode(['erro' => 'Evento não encontrado.']); exit;
            }
            if (!$podeNaLiga((string)$ligaAtual)) {
                http_response_code(403);
                echo json_encode(['erro' => 'Esse evento é da ' . $ligaAtual . '.']);
                exit;
            }
            $pdo->prepare("UPDATE calendario_eventos
                           SET league=?, tipo=?, titulo=?, inicio=?, fim=?, dia_inteiro=?, link=?, descricao=?,
                               repete=?, repete_ate=?
                           WHERE id=?")
                ->execute([$dados['league'], $dados['tipo'], $dados['titulo'], $dados['inicio'],
                           $dados['fim'], $dados['dia_inteiro'], $dados['link'], $dados['descricao'],
                           $dados['repete'], $dados['repete_ate'], $id]);
        } else {
            $pdo->prepare("INSERT INTO calendario_eventos
                           (league, tipo, titulo, inicio, fim, dia_inteiro, link, descricao, repete, repete_ate, criado_por)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$dados['league'], $dados['tipo'], $dados['titulo'], $dados['inicio'],
                           $dados['fim'], $dados['dia_inteiro'], $dados['link'], $dados['descricao'],
                           $dados['repete'], $dados['repete_ate'], $uid]);
            $id = (int)$pdo->lastInsertId();
        }
    } catch (Throwable $e) {
        error_log('[calendario] salvar: ' . $e->getMessage());
        http_response_code(500); echo json_encode(['erro' => 'Não deu pra salvar.']); exit;
    }

    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

// ── Apagar ───────────────────────────────────────────────────────────
if ($acao === 'apagar') {
    $id = (int)($corpoJson['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['erro' => 'Evento inválido.']); exit; }

    try {
        $st = $pdo->prepare("SELECT league FROM calendario_eventos WHERE id = ?");
        $st->execute([$id]);
        $liga = $st->fetchColumn();
        if ($liga === false) { http_response_code(404); echo json_encode(['erro' => 'Evento não encontrado.']); exit; }
        if (!$podeNaLiga((string)$liga)) {
            http_response_code(403);
            echo json_encode(['erro' => 'Esse evento é da ' . $liga . '.']);
            exit;
        }
        $pdo->prepare("DELETE FROM calendario_eventos WHERE id = ?")->execute([$id]);
    } catch (Throwable $e) {
        error_log('[calendario] apagar: ' . $e->getMessage());
        http_response_code(500); echo json_encode(['erro' => 'Não deu pra apagar.']); exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['erro' => 'Ação desconhecida']);
