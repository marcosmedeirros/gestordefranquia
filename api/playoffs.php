<?php
/**
 * Os playoffs de uma liga, temporada a temporada — pra ler e pra preencher.
 *
 * O chaveamento já morava no banco (playoff_series), mas só entrava por carona:
 * quem registrava a pontuação da temporada no admin preenchia as séries no
 * mesmo formulário, e quem não preenchia deixava o buraco. Resultado: a ELITE
 * tem vinte e cinco temporadas e só duas com chaveamento. O que faltava não era
 * tabela, era uma tela onde a liga olhasse o histórico e completasse o que
 * ninguém anotou na época.
 *
 * O placar da série NÃO é gravado: ele sai do número de jogos, porque numa
 * melhor de 7 o vencedor sempre faz 4 — 6 jogos é 4-2. Ver
 * backend/playoff_series.php, que é quem manda nessa regra.
 *
 * Escrita é de quem administra a liga, e só a dela.
 */

require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/playoff_series.php';

header('Content-Type: application/json; charset=utf-8');

try { requireAuth(); } catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Não autorizado']);
    exit;
}

$user = getUserSession();
$pdo  = db();
ensurePlayoffSeriesTable($pdo);

const PLAYOFFS_LIGAS = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

$entrada = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entrada = json_decode(file_get_contents('php://input'), true) ?: [];
}

$liga = strtoupper(trim((string)($entrada['liga'] ?? $_GET['liga'] ?? 'ELITE')));
if (!in_array($liga, PLAYOFFS_LIGAS, true)) {
    echo json_encode(['ok' => false, 'erro' => 'Liga desconhecida']);
    exit;
}

$ligasAdmin = array_map('strtoupper', getAdminLeagues($pdo, (int)$user['id']));
$podeEditar = in_array($liga, $ligasAdmin, true);

$acao = (string)($entrada['acao'] ?? $_GET['acao'] ?? 'listar');

try {
    if ($acao === 'salvar') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Método inválido');
        if (!$podeEditar) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'erro' => 'Você não administra a ' . $liga]);
            exit;
        }

        $seasonId = (int)($entrada['season_id'] ?? 0);

        /* A TEMPORADA TEM QUE SER DA LIGA QUE ELE ADMINISTRA.
           Sem esta conferência, o id da temporada no corpo do pedido é um
           caminho aberto: admin da ROOKIE reescrevendo o chaveamento da ELITE
           sem nunca passar por uma tela dela. */
        $st = $pdo->prepare('SELECT id, league FROM seasons WHERE id = ?');
        $st->execute([$seasonId]);
        $temporada = $st->fetch(PDO::FETCH_ASSOC);
        if (!$temporada || strtoupper((string)$temporada['league']) !== $liga) {
            echo json_encode(['ok' => false, 'erro' => 'Temporada não é da ' . $liga]);
            exit;
        }

        $series = is_array($entrada['series'] ?? null) ? $entrada['series'] : [];
        $r = salvarPlayoffSeries($pdo, $seasonId, $liga, $series);

        echo json_encode([
            'ok'        => true,
            'salvas'    => $r['salvas'],
            'ignoradas' => $r['ignoradas'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Listar ────────────────────────────────────────────────────────────
    $st = $pdo->prepare("
        SELECT s.id, s.season_number, s.status, sp.sprint_number
          FROM seasons s
     LEFT JOIN sprints sp ON sp.id = s.sprint_id
         WHERE s.league = ?
      ORDER BY sp.sprint_number DESC, s.season_number DESC
    ");
    $st->execute([$liga]);
    $temporadas = $st->fetchAll(PDO::FETCH_ASSOC);

    $ids = array_map(fn($t) => (int)$t['id'], $temporadas);
    $porTemporada = [];
    foreach ($ids as $id) {
        $porTemporada[$id] = ['series' => [], 'podio' => [], 'participantes' => []];
    }

    $timesUsados = [];

    if ($ids) {
        $marcas = implode(',', array_fill(0, count($ids), '?'));

        $q = $pdo->prepare("SELECT id, season_id, fase, conferencia, team_a_id, team_b_id,
                                   winner_team_id, jogos
                              FROM playoff_series
                             WHERE season_id IN ({$marcas})
                          ORDER BY FIELD(fase,'r1','r2','cf','final'), conferencia, id");
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $sid = (int)$s['season_id'];
            $porTemporada[$sid]['series'][] = [
                'id'          => (int)$s['id'],
                'fase'        => $s['fase'],
                'conferencia' => $s['conferencia'],
                'a'           => (int)$s['team_a_id'],
                'b'           => (int)$s['team_b_id'],
                'vencedor'    => (int)$s['winner_team_id'],
                'jogos'       => (int)$s['jogos'],
            ];
            foreach (['team_a_id', 'team_b_id'] as $c) $timesUsados[(int)$s[$c]] = true;
        }

        /* O PÓDIO OFICIAL vem de playoff_results, que é outra tabela e outra
           história: ela guarda ONDE cada time parou e é ela que a pontuação da
           temporada lê. Vem junto de propósito — serve de conferência pra quem
           preenche o chaveamento agora, anos depois, e é o único jeito de a
           tela avisar quando as duas versões discordarem. Nada aqui escreve
           nela: mexer em pontuação registrada é assunto do admin. */
        $q = $pdo->prepare("SELECT season_id, team_id, position FROM playoff_results
                             WHERE season_id IN ({$marcas})");
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $porTemporada[(int)$r['season_id']]['podio'][$r['position']][] = (int)$r['team_id'];
            $timesUsados[(int)$r['team_id']] = true;
        }

        /* Quem jogou AQUELA temporada, com a conferência daquela época. É o que
           enche os selects do editor: oferecer os times de hoje faria o admin
           procurar um time que nem existia, e some quem saiu da liga desde
           então. */
        $q = $pdo->prepare("SELECT season_id, team_id, conference, position
                              FROM season_standings
                             WHERE season_id IN ({$marcas})
                          ORDER BY conference, position");
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $porTemporada[(int)$r['season_id']]['participantes'][] = [
                'id'   => (int)$r['team_id'],
                'conf' => $r['conference'] ? strtoupper((string)$r['conference']) : null,
                'seed' => $r['position'] !== null ? (int)$r['position'] : null,
            ];
            $timesUsados[(int)$r['team_id']] = true;
        }
    }

    // Os times da liga hoje + todo mundo que apareceu lá atrás (rebaixado,
    // renomeado, movido de liga): sem eles, série antiga vira "time #47".
    $times = [];
    $st = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(city,''),' ',COALESCE(name,''))) AS nome,
                                conference, league
                           FROM teams WHERE league = ?");
    $st->execute([$liga]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $times[(int)$t['id']] = [
            'nome' => trim((string)$t['nome']) ?: ('Time #' . (int)$t['id']),
            'conf' => $t['conference'] ? strtoupper((string)$t['conference']) : null,
            'daLiga' => true,
        ];
    }
    $faltando = array_values(array_diff(array_keys($timesUsados), array_keys($times)));
    if ($faltando) {
        $marcas = implode(',', array_fill(0, count($faltando), '?'));
        $st = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(city,''),' ',COALESCE(name,''))) AS nome,
                                    conference
                               FROM teams WHERE id IN ({$marcas})");
        $st->execute($faltando);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $times[(int)$t['id']] = [
                'nome' => trim((string)$t['nome']) ?: ('Time #' . (int)$t['id']),
                'conf' => $t['conference'] ? strtoupper((string)$t['conference']) : null,
                'daLiga' => false,
            ];
        }
    }

    $saida = [];
    foreach ($temporadas as $t) {
        $id = (int)$t['id'];
        $saida[] = [
            'id'            => $id,
            'numero'        => (int)$t['season_number'],
            'era'           => $t['sprint_number'] !== null ? (int)$t['sprint_number'] : null,
            'status'        => (string)$t['status'],
            'series'        => $porTemporada[$id]['series'],
            'podio'         => $porTemporada[$id]['podio'],
            'participantes' => $porTemporada[$id]['participantes'],
        ];
    }

    echo json_encode([
        'ok'          => true,
        'liga'        => $liga,
        'pode_editar' => $podeEditar,
        'fases'       => playoffFases(),
        'melhor_de'   => PLAYOFF_MELHOR_DE,
        'times'       => $times,
        'temporadas'  => $saida,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[playoffs] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro interno.']);
}
