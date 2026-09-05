<?php
/**
 * Importação em massa de estatísticas e atributos.
 *
 * A tela de stats mostra quem está sem lançamento na temporada, mas até aqui
 * o único jeito de preencher era o GM abrir o próprio elenco, um por um.
 * Quando metade da liga não lança, ninguém tinha por onde. Estas duas actions
 * dão a lista de pendentes e aceitam um CSV de volta.
 *
 *   GET  ?action=pendentes&tipo=stats|skills   → quem falta, com id e time
 *   POST  {action:'importar', tipo, csv}       → grava o que veio no CSV
 *
 * QUEM PODE: qualquer GM logado, e sobre a liga INTEIRA — não só o próprio
 * elenco. É decisão da liga: o gargalo era justamente depender de uma pessoa
 * só pra lançar o que todo mundo já tem na mão, e aqui quem tiver a planilha
 * resolve. A porta que continua fechada é a de fora: a liga é sempre a do
 * usuário logado, e id de jogador de outra liga é recusado com o motivo na
 * resposta, em vez de passar batido como se tivesse gravado.
 */
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo  = db();
$user = getUserSession();

$liga = ligaAtualDoUsuario($pdo, $user) ?: ($user['league'] ?? 'ELITE');

/** As dez notas, na ordem em que aparecem no CSV e na tela. */
const COLUNAS_SKILL = ['in', 'mid', 'pt3', 'post_d', 'per_d', 'play', 'reb', 'athl', 'iq', 'pot'];
/** A coluna do banco de cada nota — `pt3` é `skill_3pt`, e só ela foge do padrão. */
const COLUNA_DO_SKILL = [
    'in' => 'skill_in', 'mid' => 'skill_mid', 'pt3' => 'skill_3pt',
    'post_d' => 'skill_post_d', 'per_d' => 'skill_per_d', 'play' => 'skill_play',
    'reb' => 'skill_reb', 'athl' => 'skill_athl', 'iq' => 'skill_iq', 'pot' => 'skill_pot',
];
/** As notas aceitas. Qualquer outra coisa no CSV é recusada com o motivo. */
const NOTAS_VALIDAS = ['A+','A','A-','B+','B','B-','C+','C','C-','D+','D','D-','F','-'];

/**
 * A temporada que RECEBE o lançamento.
 *
 * Não é a "em andamento": a que acabou de nascer ainda está no draft e não
 * teve jogo nenhum — quem lança agora está com os números da anterior na mão.
 * O marco é a classificação; ver backend/stats_temporada.php.
 */
function temporadaDaLiga(PDO $pdo, string $liga): ?array
{
    require_once __DIR__ . '/../backend/stats_temporada.php';
    return statsTemporadaAlvo($pdo, $liga)['alvo'] ?: null;
}

/**
 * Quem ainda não tem lançamento.
 *
 * `stats` olha a temporada corrente: quem não tem linha em
 * player_season_stats dela está pendente, mesmo que tenha jogado a anterior.
 * `skills` olha o cadastro do jogador, que não é por temporada — pendente é
 * quem está sem nota nenhuma preenchida.
 */
function pendentes(PDO $pdo, string $liga, string $tipo, ?int $seasonId): array
{
    if ($tipo === 'skills') {
        $sql = "SELECT p.id, p.name, p.position, p.ovr, CONCAT(t.city,' ',t.name) AS time
                FROM players p
                JOIN teams t ON t.id = p.team_id
                WHERE t.league = ?
                  AND COALESCE(NULLIF(p.skill_in, ''), '-') = '-'
                  AND COALESCE(NULLIF(p.skill_mid, ''), '-') = '-'
                  AND COALESCE(NULLIF(p.skill_3pt, ''), '-') = '-'
                ORDER BY t.city, t.name, p.ovr DESC, p.name";
        $st = $pdo->prepare($sql);
        $st->execute([$liga]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // Sem temporada aberta não há o que cobrar de estatística.
    if (!$seasonId) return [];

    /* LINHA ZERADA É LANÇAMENTO QUE FALTA, e não lançamento feito.
       Só `ps.id IS NULL` deixava de fora quem tinha a linha criada com tudo em
       zero — 293 jogadores só na ROOKIE. Eles sumiam do modelo de CSV, e sem
       aparecer no modelo não havia como corrigi-los em massa: o GM via "0" na
       tela e não tinha por onde atualizar.

       A importação sempre soube gravar por cima (ON DUPLICATE KEY UPDATE); era
       a LISTA que os escondia. */
    $sql = "SELECT p.id, p.name, p.position, p.ovr, CONCAT(t.city,' ',t.name) AS time
            FROM players p
            JOIN teams t ON t.id = p.team_id
            LEFT JOIN player_season_stats ps ON ps.player_id = p.id AND ps.season_id = ?
            WHERE t.league = ?
              AND (ps.id IS NULL
                   OR (COALESCE(ps.games,0) = 0 AND COALESCE(ps.min_pg,0) = 0
                       AND COALESCE(ps.pts_pg,0) = 0 AND COALESCE(ps.reb_pg,0) = 0
                       AND COALESCE(ps.ast_pg,0) = 0 AND COALESCE(ps.stl_pg,0) = 0
                       AND COALESCE(ps.blk_pg,0) = 0))
            ORDER BY t.city, t.name, p.ovr DESC, p.name";
    $st = $pdo->prepare($sql);
    $st->execute([$seasonId, $liga]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Quebra o CSV em linhas de células.
 *
 * Aceita vírgula e ponto-e-vírgula: o Excel em português salva com `;`, e
 * exigir vírgula faria o admin abrir o arquivo num editor de texto pra
 * trocar separador. O cabeçalho é reconhecido pela primeira célula e
 * descartado; sem ele o arquivo também funciona.
 */
function lerCsv(string $csv): array
{
    $csv = str_replace(["\r\n", "\r"], "\n", trim($csv));
    if ($csv === '') return [];
    $linhas = [];
    foreach (explode("\n", $csv) as $i => $linha) {
        if (trim($linha) === '') continue;
        $sep = (substr_count($linha, ';') > substr_count($linha, ',')) ? ';' : ',';
        $cels = array_map('trim', str_getcsv($linha, $sep));
        // Cabeçalho: só a primeira linha, e só se a primeira célula não for número.
        if ($i === 0 && !is_numeric($cels[0] ?? '')) continue;
        $linhas[] = $cels;
    }
    return $linhas;
}

/** Número com vírgula decimal também vale — é como o Excel BR escreve. */
function numeroCsv($v, float $max): float
{
    $v = str_replace(',', '.', trim((string)$v));
    if ($v === '' || !is_numeric($v)) return 0.0;
    return max(0.0, min($max, round((float)$v, 1)));
}

// ── GET: a lista de pendentes ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tipo = ($_GET['tipo'] ?? 'stats') === 'skills' ? 'skills' : 'stats';
    $temp = temporadaDaLiga($pdo, $liga);
    $lista = pendentes($pdo, $liga, $tipo, $temp ? (int)$temp['id'] : null);
    echo json_encode([
        'success'       => true,
        'tipo'          => $tipo,
        'league'        => $liga,
        'season_number' => $temp['season_number'] ?? null,
        'total'         => count($lista),
        'jogadores'     => $lista,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST: a importação ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
if (($body['action'] ?? '') !== 'importar') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ação desconhecida.']);
    exit;
}

$tipo   = ($body['tipo'] ?? 'stats') === 'skills' ? 'skills' : 'stats';
$linhas = lerCsv((string)($body['csv'] ?? ''));
if (!$linhas) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'O CSV veio vazio.']);
    exit;
}

// Os jogadores da liga, com o time — nada fora daqui é gravado.
$stmtLiga = $pdo->prepare("SELECT p.id, p.name, p.team_id FROM players p
                           JOIN teams t ON t.id = p.team_id WHERE t.league = ?");
$stmtLiga->execute([$liga]);
$daLiga = [];
foreach ($stmtLiga->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $daLiga[(int)$p['id']] = ['nome' => $p['name'], 'team_id' => (int)$p['team_id']];
}

$gravados = 0;
$recusados = [];   // [linha, motivo] — quem importou precisa saber o que ficou de fora

if ($tipo === 'stats') {
    $temp = temporadaDaLiga($pdo, $liga);
    if (!$temp) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nenhuma temporada em andamento nesta liga.']);
        exit;
    }

    $sql = "INSERT INTO player_season_stats
              (player_id, season_id, season_number, league, team_id,
               games, min_pg, pts_pg, reb_pg, ast_pg, stl_pg, blk_pg, source)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'manual')
            ON DUPLICATE KEY UPDATE
              games=VALUES(games), min_pg=VALUES(min_pg), pts_pg=VALUES(pts_pg),
              reb_pg=VALUES(reb_pg), ast_pg=VALUES(ast_pg), stl_pg=VALUES(stl_pg),
              blk_pg=VALUES(blk_pg), source=VALUES(source), team_id=VALUES(team_id)";
    $stmt = $pdo->prepare($sql);

    $pdo->beginTransaction();
    try {
        foreach ($linhas as $n => $c) {
            $pid = (int)($c[0] ?? 0);
            if (!isset($daLiga[$pid])) {
                $recusados[] = ['linha' => $n + 1, 'motivo' => 'id ' . ($c[0] ?? '?') . ' não é da liga'];
                continue;
            }
            /* ROU e TOC vêm de STL e BLK. Quem copia a coluna TO do print —
               turnover, sigla quase igual a TOC — manda número que jogador
               nenhum faz: o recorde da NBA é 3,7 roubos e 5,6 tocos.
               RECUSA em vez de saturar: cortar 8,8 tocos em 6 gravaria um dado
               igualmente errado, só que sem deixar pista de que houve engano. */
            foreach ([['rou', 7, 5.0, 'STL'], ['toc', 8, 6.0, 'BLK']] as [$rot, $ix, $teto, $daTela]) {
                $bruto = str_replace(',', '.', trim((string)($c[$ix] ?? '')));
                if (is_numeric($bruto) && (float)$bruto > $teto) {
                    $recusados[] = ['linha' => $n + 1, 'motivo' =>
                        strtoupper($rot) . ' = ' . $bruto . ' é alto demais (máx ' . $teto . '). '
                        . 'Essa coluna vem de ' . $daTela . ' — confira se não copiou a coluna TO, que é turnover.'];
                    continue 2;
                }
            }

            // Colunas: id, nome, jogos, min, pts, reb, ast, rou, toc
            $stmt->execute([
                $pid, (int)$temp['id'], (int)$temp['season_number'], $liga, $daLiga[$pid]['team_id'],
                max(0, min(200, (int)($c[2] ?? 0))),
                numeroCsv($c[3] ?? 0, 60),
                numeroCsv($c[4] ?? 0, 99),
                numeroCsv($c[5] ?? 0, 50),
                numeroCsv($c[6] ?? 0, 50),
                numeroCsv($c[7] ?? 0, 5),
                numeroCsv($c[8] ?? 0, 6),
            ]);
            $gravados++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[stats-import] stats: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao gravar as estatísticas.']);
        exit;
    }
} else {
    // As notas vão pras colunas E pro JSON: o resto do sistema lê dos dois
    // lugares, com a coluna tendo prioridade (veja statsjogadores.php).
    $sets = [];
    foreach (COLUNAS_SKILL as $k) $sets[] = COLUNA_DO_SKILL[$k] . ' = ?';
    $stmt = $pdo->prepare('UPDATE players SET player_skill_grades = ?, ' . implode(', ', $sets) . ' WHERE id = ?');

    $pdo->beginTransaction();
    try {
        foreach ($linhas as $n => $c) {
            $pid = (int)($c[0] ?? 0);
            if (!isset($daLiga[$pid])) {
                $recusados[] = ['linha' => $n + 1, 'motivo' => 'id ' . ($c[0] ?? '?') . ' não é da liga'];
                continue;
            }
            // Colunas: id, nome, in, mid, 3pt, post_d, per_d, play, reb, athl, iq, pot
            $notas = [];
            $erro = null;
            foreach (COLUNAS_SKILL as $i => $chave) {
                $v = strtoupper(trim((string)($c[$i + 2] ?? '')));
                if ($v === '') $v = '-';
                if (!in_array($v, NOTAS_VALIDAS, true)) {
                    $erro = 'nota "' . $v . '" inválida em ' . $chave;
                    break;
                }
                $notas[$chave] = $v;
            }
            if ($erro) {
                $recusados[] = ['linha' => $n + 1, 'motivo' => $erro];
                continue;
            }

            $valores = [json_encode($notas, JSON_UNESCAPED_UNICODE)];
            foreach (COLUNAS_SKILL as $chave) $valores[] = $notas[$chave];
            $valores[] = $pid;
            $stmt->execute($valores);
            $gravados++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[stats-import] skills: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao gravar os atributos.']);
        exit;
    }
}

echo json_encode([
    'success'   => true,
    'tipo'      => $tipo,
    'gravados'  => $gravados,
    'recusados' => $recusados,
], JSON_UNESCAPED_UNICODE);
