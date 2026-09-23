<?php
ob_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
// Proteção de pick: a trava do ano seguinte e quem pode proteger (só ELITE).
require_once dirname(__DIR__) . '/backend/pick_protection.php';
require_once dirname(__DIR__) . '/backend/draft_swaps.php';  // findActiveDraftSession()

// Verificar autenticação
$user = getUserSession();
if (!$user) {
    http_response_code(401);
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$pdo = db();

function buildDraftOrderMap(PDO $pdo, int $draftSessionId): array
{
    $map = [];
    try {
        $stmt = $pdo->prepare('SELECT id, team_id, original_team_id, pick_position, round FROM draft_order WHERE draft_session_id = ? ORDER BY round ASC, pick_position ASC, id ASC');
        $stmt->execute([$draftSessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        /* NADA DE CONTAR O QUE AINDA ESTÁ NA URNA. Este mapa vira o "Escolha
           N" das picks na página de Times e na Trade Machine. Durante a
           cerimônia da loteria, a vaga não revelada não entra: a pick sai sem
           número, como antes do sorteio. */
        $naUrna = draftPosicoesNaUrna($pdo, $draftSessionId);
        $overall = 1;
        foreach ($rows as $row) {
            if (isset($naUrna[(int)$row['pick_position']])) { $overall++; continue; }
            $key = (int)$row['original_team_id'] . '-' . (int)$row['round'];
            $map[$key] = [
                'draft_order_id' => (int)$row['id'],
                'team_id' => (int)$row['team_id'],
                'original_team_id' => (int)$row['original_team_id'],
                'round' => (int)$row['round'],
                'pick_position' => (int)$row['pick_position'],
                'pick_number' => $overall
            ];
            $overall++;
        }
    } catch (Exception $e) {
        return [];
    }
    return $map;
}

/**
 * O PAR DE CADA PICK EM SWAP, por ano.
 *
 * Mesma funcao de api/trades.php: devolve o tipo (SB/SW) e a ORIGEM da pick
 * emparelhada, que e o que liga o swap as duas vagas do draft.
 */
function buildSwapPairMap(PDO $pdo, int $ano): array
{
    $mapa = [];
    try {
        $st = $pdo->prepare("SELECT p.id, p.swap_type, par.original_team_id AS par_origem
                               FROM picks p
                               JOIN picks par ON par.id = p.swap_pair_pick_id
                              WHERE p.season_year = ? AND p.round = 1
                                AND p.swap_type IN ('SB','SW') AND par.round = 1");
        $st->execute([$ano]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mapa[(int)$r['id']] = ['tipo' => strtoupper((string)$r['swap_type']),
                                      'par_origem' => (int)$r['par_origem']];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $mapa;
}

function applyDraftContextToPick(array $pick, ?array $draftSession, array $draftMap, ?int $sessionSeasonId = null, ?int $sessionYear = null, array $swapMap = []): array
{
    if (!$draftSession) {
        return $pick;
    }
    /*
     * QUEM LIGA A PICK À VAGA É O ANO, não o season_id.
     *
     * Esta era a última cópia do defeito que api/trades.php já tinha
     * corrigido. Aqui a comparação por ano só rodava quando a pick NÃO tinha
     * season_id — e como quase toda pick tem, o que valia na prática era o
     * season_id. O resultado ficava exatamente invertido:
     *
     * Medido na NEXT em 23/09/2026, com o draft da temporada 4 (ano 2019,
     * distribuindo a classe de 2020). A pick de 2020 do Dallas tem season_id
     * 167 e era RECUSADA por não ser 179; a pick de 2024 do mesmo time tem
     * season_id 179 — o da sessão — e era ANOTADA como "Escolha 15". A tela
     * mostrava "2024 R1 · Draft atual" e a classe certa desaparecia.
     *
     * O season_id da pick é o da temporada em que ela foi GERADA, não o do
     * draft que vai distribuí-la: a classe de 2024 foi criada na temporada 4
     * junto com as outras. Por isso ele não serve de chave, e o ano serve.
     */
    if ($sessionYear && !empty($pick['season_year']) && (int)$pick['season_year'] !== $sessionYear) {
        return $pick;
    }
    $round = isset($pick['round']) ? (int)$pick['round'] : 0;
    $originalTeamId = isset($pick['original_team_id']) ? (int)$pick['original_team_id'] : 0;
    if ($round <= 0 || $originalTeamId <= 0) {
        return $pick;
    }
    $key = $originalTeamId . '-' . $round;
    if (!isset($draftMap[$key])) {
        return $pick;
    }
    $info = $draftMap[$key];

    /* NO SWAP, A VAGA DA ORIGEM NAO E A VAGA DO DONO.
       As duas vagas do par sao redistribuidas: a de numero menor vai pro dono
       da pick SB e a maior pro dono da SW. Sem isto a pagina de Picks mostrava
       a vaga da ORIGEM — a mesma falha que aparecia na Trade Machine. */
    $pickId = (int)($pick['id'] ?? 0);
    if ($pickId && isset($swapMap[$pickId])) {
        $par = $swapMap[$pickId];
        $chavePar = $par['par_origem'] . '-' . $round;
        if ($par['par_origem'] > 0 && isset($draftMap[$chavePar])) {
            $vagaPar = $draftMap[$chavePar];
            $minha = (int)$info['pick_position'];
            $dele  = (int)$vagaPar['pick_position'];
            $melhor = $minha <= $dele ? $info : $vagaPar;
            $pior   = $minha <= $dele ? $vagaPar : $info;
            $info = ($par['tipo'] === 'SB') ? $melhor : $pior;
        }
    }

    $pick['draft_session_id'] = (int)$draftSession['id'];
    $pick['draft_pick_number'] = (int)$info['pick_number'];
    $pick['draft_pick_position'] = (int)$info['pick_position'];
    $pick['draft_round'] = (int)$info['round'];
    return $pick;
}

/**
 * O ano da classe de picks que este draft distribui.
 *
 * Delega pra draftAnoDasPicks(), que é a mesma resposta usada pela Trade
 * Machine, pelos cards de troca e pela sincronização da ordem do draft.
 *
 * A conta anterior estava errada de duas formas ao mesmo tempo. Primeiro,
 * respondia o ano da TEMPORADA e não o da classe de picks — o draft da
 * temporada de 2019 distribui a classe de 2020, e ela devolvia 2019.
 * Segundo, usava isset(start_year, season_number), e isset é verdadeiro pra
 * ZERO: numa base com start_year = 0 ela devolvia season_number - 1, um
 * "ano" 1. Quando esse número sai diferente do resto do sistema, a escolha
 * perde o número no card e a transferência da vaga não acontece.
 */
function computeSeasonDisplayYear(?array $row, ?PDO $pdo = null, ?int $seasonId = null): ?int
{
    if ($pdo && $seasonId) {
        $ano = draftAnoDasPicks($pdo, $seasonId);
        if ($ano > 0) return $ano;
    }
    if (!$row) return null;
    // Sem PDO (chamadas antigas): a conta velha, mas sem o furo do isset.
    if (!empty($row['start_year']) && isset($row['season_number'])) {
        return (int)$row['start_year'] + (int)$row['season_number'] - 1;
    }
    return !empty($row['year']) ? (int)$row['year'] : null;
}

// POST - Desabilitado: sistema gera picks automaticamente
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(405);
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Edição manual de picks desabilitada. As picks são geradas automaticamente.']);
    exit;
}

// DELETE - Desabilitado
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    http_response_code(405);
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Exclusão manual de picks desabilitada. As picks são geridas automaticamente.']);
    exit;
}

// PUT - Desabilitado
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    http_response_code(405);
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Atualização manual de picks desabilitada. As picks são geradas automaticamente.']);
    exit;
}

// GET - Listar picks
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $teamId = $_GET['team_id'] ?? null;
    $includeAway = isset($_GET['include_away']) && $_GET['include_away'] === '1';

    if (!$teamId) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Team ID não informado']);
        exit;
    }

    // ── Calcular ano corrente ANTES da query para filtrar no SQL ──────────
    $stmtTeam = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
    $stmtTeam->execute([$teamId]);
    $league = $stmtTeam->fetchColumn() ?: null;

    // O corte vem do ponto único (backend/helpers.php): as três telas de pick
    // faziam esta conta cada uma de um jeito e discordavam entre si.
    $currentYear = anoDeCorteDasPicks($pdo, $league);
    // ─────────────────────────────────────────────────────────────────────

    $stmt = $pdo->prepare('
        SELECT p.*,
               orig.city as original_team_city, orig.name as original_team_name,
               last_t.city as last_owner_city, last_t.name as last_owner_name,
               swap_team.id as swap_partner_team_id,
               swap_team.city as swap_partner_city, swap_team.name as swap_partner_name
        FROM picks p
        LEFT JOIN teams orig ON p.original_team_id = orig.id
        LEFT JOIN teams last_t ON p.last_owner_team_id = last_t.id
        LEFT JOIN picks swap_pick ON p.swap_pair_pick_id = swap_pick.id
        LEFT JOIN teams swap_team ON swap_pick.original_team_id = swap_team.id
        WHERE p.team_id = ?
          AND (p.season_year IS NULL OR p.season_year >= ?)
        ORDER BY p.season_year, p.round
    ');
    $stmt->execute([$teamId, $currentYear]);
    $picks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $seasonId = null;
    $seasonYear = null;
    foreach ($picks as $pick) {
        if (!$seasonId && !empty($pick['season_id'])) {
            $seasonId = (int)$pick['season_id'];
        }
        if (!$seasonYear && !empty($pick['season_year'])) {
            $seasonYear = (int)$pick['season_year'];
        }
        if ($seasonId || $seasonYear) {
            break;
        }
    }

    $draftSession = findActiveDraftSession($pdo, $league, $seasonId, $seasonYear);
    if ($draftSession) {
        $draftMap = buildDraftOrderMap($pdo, (int)$draftSession['id']);
        if (!empty($draftMap)) {
            $sessionSeasonId = !empty($draftSession['season_id']) ? (int)$draftSession['season_id'] : null;
            $sessionYear = null;
            if ($sessionSeasonId) {
                try {
                    $stmtSeason = $pdo->prepare('SELECT s.season_number, s.year, sp.start_year FROM seasons s LEFT JOIN sprints sp ON s.sprint_id = sp.id WHERE s.id = ?');
                    $stmtSeason->execute([$sessionSeasonId]);
                    $sessionYear = computeSeasonDisplayYear($stmtSeason->fetch(PDO::FETCH_ASSOC) ?: null, $pdo, $sessionSeasonId);
                } catch (Exception $e) {
                    $sessionYear = null;
                }
            }
            // O par do swap decide qual das duas vagas é do dono desta pick.
            $swapMap = $sessionYear ? buildSwapPairMap($pdo, $sessionYear) : [];
            $picks = array_map(static function ($pick) use ($draftSession, $draftMap, $sessionSeasonId, $sessionYear, $swapMap) {
                return applyDraftContextToPick($pick, $draftSession, $draftMap, $sessionSeasonId, $sessionYear, $swapMap);
            }, $picks);
        }
    }

    $picks = array_values(array_filter($picks, function($pick) use ($currentYear) {
        $y = (int)($pick['season_year'] ?? 0);
        return $y >= $currentYear;
    }));

    // ?sem_usadas=1 (Trade Machine): pick que já virou jogador no draft não é
    // mais moeda de troca. Opcional porque a página de Picks mostra a escolha
    // feita de propósito — é o histórico do time.
    if (($_GET['sem_usadas'] ?? '') === '1') {
        require_once dirname(__DIR__) . '/backend/picks_usadas.php';
        $usadas = picksJaUsadas($pdo);
        $picks = array_values(array_filter($picks, fn($pick) => empty($usadas[(int)($pick['id'] ?? 0)])));
    }

    // Proteção de pick (só ELITE): a tela precisa saber de duas coisas — se a
    // pick está travada por servir de lastro, e se pode receber proteção. A
    // regra fica no backend; aqui só é entregue pronta pra não haver uma
    // segunda versão dela no navegador.
    $picks = protecaoAnotarPicks($pdo, $picks, (string)$league);

    // O parenteses da linha de "copiar time" tambem sai pronto daqui:
    // e a MESMA funcao que o dashboard e o my-roster usam, e as tres
    // telas tinham cada uma a sua copia dessa linha.
    /* `usada` diz se o time JÁ ESCOLHEU com aquela pick.
       A aba Times filtrava a lista por ano ("season_year >= o ano corrente"),
       e ano não responde: a pick de 1ª rodada de 2027 do New York tinha sido
       gasta no draft, a de 2ª rodada do mesmo ano não — cortar por ano ou
       mostrava a gasta como patrimônio ou escondia a que ele ainda tem.
       O campo vai junto em vez de a API filtrar sozinha: a Trade Machine
       também consome este endpoint e tem as suas próprias regras.
       @see backend/picks_usadas.php */
    require_once dirname(__DIR__) . '/backend/picks_usadas.php';
    $picksUsadas = picksJaUsadas($pdo);
    foreach ($picks as &$__pk) {
        $__pk['copia'] = pickCopiaParenteses($__pk);
        $__pk['usada'] = isset($picksUsadas[(int)$__pk['id']]);
    }
    unset($__pk);

    $payload = ['success' => true, 'picks' => $picks,
                'protecoes' => protecaoLigaUsa($league) ? PICK_PROTECOES : []];

    if ($includeAway) {
        $stmtAway = $pdo->prepare('
            SELECT p.*, current_owner.city as current_team_city, current_owner.name as current_team_name,
                   swap_team.city as swap_partner_city, swap_team.name as swap_partner_name
            FROM picks p
            LEFT JOIN teams current_owner ON p.team_id = current_owner.id
            LEFT JOIN picks swap_pick ON p.swap_pair_pick_id = swap_pick.id
            LEFT JOIN teams swap_team ON swap_pick.original_team_id = swap_team.id
            WHERE p.original_team_id = ? AND p.team_id <> ?
            ORDER BY p.season_year, p.round
        ');
        $stmtAway->execute([$teamId, $teamId]);
        $payload['picks_away'] = $stmtAway->fetchAll(PDO::FETCH_ASSOC);
    }

    ob_end_clean();
    echo json_encode($payload);
    exit;
}

ob_end_clean();
echo json_encode(['success' => false, 'error' => 'Método não suportado']);
