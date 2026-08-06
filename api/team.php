<?php
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/salary_cap.php';

/**
 * Resumo da folha pro "Copiar Time" — só nas ligas em salary cap.
 * Retorna null nas ligas de Top 8 (aí o copiar segue com cap_top8) e também
 * quando algo falha: notícia de cap não pode derrubar a listagem de times.
 */
function teamSalarySummaryForCopy(PDO $pdo, int $teamId, string $league): ?array
{
    static $modoPorLiga = [];
    $league = strtoupper($league);
    if ($league === '') return null;

    if (!array_key_exists($league, $modoPorLiga)) {
        try {
            $st = $pdo->prepare('SELECT cap_mode FROM league_settings WHERE league = ?');
            $st->execute([$league]);
            $modoPorLiga[$league] = ($st->fetchColumn() ?: 'ovr_sum') === 'salary';
        } catch (Throwable $e) {
            $modoPorLiga[$league] = false;
        }
    }
    if (!$modoPorLiga[$league]) return null;

    try {
        $s = getTeamCapSummary($pdo, $teamId);
        return [
            'payroll'   => (int)$s['payroll'],
            'cap_max'   => (int)$s['cap_max'],
            'cap_floor' => (int)$s['cap_floor'],
            'space'     => (int)$s['space'],
            'status'    => $s['status'],
        ];
    } catch (Throwable $e) {
        error_log('[team.php salary] team_id=' . $teamId . ' ' . $e->getMessage());
        return null;
    }
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

function teamColumnExists(PDO $pdo, string $column): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM teams LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch();
}

function playersColumnExists(PDO $pdo, string $column): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM players LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch();
}

function appendPhoneFields(array &$row): void {
    $rawPhone = $row['owner_phone'] ?? '';
    $normalizedPhone = $rawPhone !== '' ? normalizeBrazilianPhone($rawPhone) : null;
    if (!$normalizedPhone && $rawPhone !== '') {
        $digits = preg_replace('/\D+/', '', $rawPhone);
        if ($digits !== '') {
            $normalizedPhone = str_starts_with($digits, '55') ? $digits : '55' . $digits;
        }
    }
    $row['owner_phone_display'] = $rawPhone !== '' ? formatBrazilianPhone($rawPhone) : null;
    $row['owner_phone_whatsapp'] = $normalizedPhone;
}

function playerOvrColumnForDetails(PDO $pdo): string {
    try {
        $hasOvr = $pdo->query("SHOW COLUMNS FROM players LIKE 'ovr'")->fetch();
        if ($hasOvr) {
            return 'ovr';
        }
        $hasOverall = $pdo->query("SHOW COLUMNS FROM players LIKE 'overall'")->fetch();
        if ($hasOverall) {
            return 'overall';
        }
    } catch (Exception $e) {
    }
    return 'ovr';
}

/**
 * Sincroniza contador de trades por time com base em current_cycle/trades_cycle.
 * Retorna o valor atual de trades_used (0 quando reseta ou não encontrado).
 */
function syncTeamTradeCounterLocal(PDO $pdo, int $teamId): int {
    try {
        $stmt = $pdo->prepare('SELECT current_cycle, trades_cycle, trades_used FROM teams WHERE id = ?');
        $stmt->execute([$teamId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 0;
        $currentCycle = (int)($row['current_cycle'] ?? 0);
        $tradesCycle = (int)($row['trades_cycle'] ?? 0);
        $tradesUsed = (int)($row['trades_used'] ?? 0);

        if ($currentCycle > 0 && $tradesCycle !== $currentCycle) {
            $pdo->prepare('UPDATE teams SET trades_used = 0, trades_cycle = ? WHERE id = ?')
                ->execute([$currentCycle, $teamId]);
            return 0;
        }

        if ($currentCycle > 0 && $tradesCycle <= 0) {
            $pdo->prepare('UPDATE teams SET trades_cycle = ? WHERE id = ?')
                ->execute([$currentCycle, $teamId]);
        }

        return $tradesUsed;
    } catch (Exception $e) {
        return 0;
    }
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? null;

    /**
     * Elencos da liga em texto, pro botão "Elencos" de teams.php.
     * Vivia só em api/admin.php, que barra qualquer não-admin logo no topo do arquivo — então
     * pro jogador comum a resposta era 403 e a tela mostrava "Nenhum elenco encontrado", como se
     * não houvesse elenco. Aqui a liga sai SEMPRE da sessão do usuário (ele não escolhe), então
     * ninguém enxerga liga que já não pudesse ver na própria página de times.
     */
    if ($action === 'copy_rosters') {
        $user = getUserSession();
        if (!$user) jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);

        $league = strtoupper(trim((string)($user['league'] ?? '')));
        $isAdmin = hasAdminAccess($pdo, (int)$user['id']);
        $leagueParam = strtoupper(trim($_GET['league'] ?? ''));
        if ($leagueParam !== '' && $isAdmin) $league = $leagueParam;
        if (!in_array($league, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
            jsonResponse(400, ['error' => 'Liga inválida']);
        }

        $stmtTeams = $pdo->prepare('SELECT t.id, t.city, t.name, u.name AS owner_name FROM teams t LEFT JOIN users u ON t.user_id = u.id WHERE t.league = ? ORDER BY t.city, t.name');
        $stmtTeams->execute([$league]);
        $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);
        if (!$teams) jsonResponse(200, ['success' => true, 'text' => 'Nenhum time encontrado.']);

        $teamIds = array_map(static fn($row) => (int)$row['id'], $teams);
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $playerOvr = playerOvrColumnForDetails($pdo);
        $stmtPlayers = $pdo->prepare(
            'SELECT team_id, name, position, age, role, ' . $playerOvr . ' AS ovr
             FROM players
             WHERE team_id IN (' . $placeholders . ')
             ORDER BY team_id,
               CASE role
                 WHEN "Titular" THEN 1
                 WHEN "Banco" THEN 2
                 WHEN "Outro" THEN 3
                 WHEN "G-League" THEN 4
                 ELSE 5
               END,
               ' . $playerOvr . ' DESC,
               name ASC'
        );
        $stmtPlayers->execute($teamIds);

        $playersByTeam = [];
        foreach ($stmtPlayers->fetchAll(PDO::FETCH_ASSOC) as $player) {
            $playersByTeam[(int)$player['team_id']][] = $player;
        }

        $lines = [];
        foreach ($teams as $team) {
            $lines[] = '*' . trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? '')) . '*';
            $lines[] = 'GM: ' . ($team['owner_name'] ?? '-');
            $roster = $playersByTeam[(int)$team['id']] ?? [];
            if (!$roster) {
                $lines[] = '- Sem jogadores';
            } else {
                $main    = array_values(array_filter($roster, fn($p) => ($p['role'] ?? '') !== 'G-League'));
                $gleague = array_values(array_filter($roster, fn($p) => ($p['role'] ?? '') === 'G-League'));
                foreach ($main as $p) {
                    $lines[] = sprintf('- %s | %s | OVR %s | %s anos', $p['position'], $p['name'], $p['ovr'] ?? '-', $p['age'] ?? '-');
                }
                if ($gleague) {
                    $lines[] = '*G-League*';
                    foreach ($gleague as $p) {
                        $lines[] = sprintf('- %s | %s | OVR %s | %s anos', $p['position'], $p['name'], $p['ovr'] ?? '-', $p['age'] ?? '-');
                    }
                }
            }
            $lines[] = '';
        }

        jsonResponse(200, ['success' => true, 'text' => trim(implode("\n", $lines))]);
    }

    if ($action === 'list_players' || $action === 'search_player') {
        $user = getUserSession();
        if (!$user) {
            jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);
        }
        $isAdmin = hasAdminAccess($pdo, (int)$user['id']);
        $league = $user['league'] ?? 'ROOKIE';
        $leagueParam = strtoupper(trim($_GET['league'] ?? ''));
        if ($leagueParam !== '' && $isAdmin) {
            $league = $leagueParam;
        }
        $currentUserId = $user['id'];
    }

    if ($action === 'list_players') {
        $query = trim($_GET['query'] ?? '');
        $position = strtoupper(trim($_GET['position'] ?? ''));
        $teamFilter = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
    $ovrMin = isset($_GET['ovr_min']) ? (int)$_GET['ovr_min'] : null;
    $ovrMax = isset($_GET['ovr_max']) ? (int)$_GET['ovr_max'] : null;
    $ageMin = isset($_GET['age_min']) ? (int)$_GET['age_min'] : null;
    $ageMax = isset($_GET['age_max']) ? (int)$_GET['age_max'] : null;
        $availableForTrade = isset($_GET['available_for_trade']) && $_GET['available_for_trade'] === '1';
        // Função no elenco. Lista fechada: o valor vai direto para a comparação
        // com a coluna ENUM, então não aceita nada fora dela.
        $rolesValidos = ['Titular', 'Banco', 'Outro', 'G-League'];
        $role = trim($_GET['role'] ?? '');
        if ($role !== '' && !in_array($role, $rolesValidos, true)) $role = '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? 50);
        if ($perPage <= 0) $perPage = 50;
        if ($perPage > 200) $perPage = 200;
        $offset = ($page - 1) * $perPage;
        $params = [$league];
        $where = 't.league = ?';
        if ($availableForTrade) {
            $where .= ' AND p.available_for_trade = 1';
        }
        if ($query !== '') {
            $where .= ' AND p.name LIKE ?';
            $params[] = '%' . $query . '%';
        }
        if ($position !== '') {
            $where .= ' AND p.position = ?';
            $params[] = $position;
        }
        if ($ovrMin !== null && $ovrMin > 0) {
            $where .= ' AND p.ovr >= ?';
            $params[] = $ovrMin;
        }
        if ($ovrMax !== null && $ovrMax > 0) {
            $where .= ' AND p.ovr <= ?';
            $params[] = $ovrMax;
        }
        if ($ageMin !== null && $ageMin > 0) {
            $where .= ' AND p.age >= ?';
            $params[] = $ageMin;
        }
        if ($ageMax !== null && $ageMax > 0) {
            $where .= ' AND p.age <= ?';
            $params[] = $ageMax;
        }
        if ($teamFilter > 0) {
            $where .= ' AND t.id = ?';
            $params[] = $teamFilter;
        }
        if ($role !== '') {
            $where .= ' AND p.role = ?';
            $params[] = $role;
        }

        // Estatísticas da temporada corrente da liga, para a listagem poder
        // mostrar e ordenar por PTS/REB/AST. LEFT JOIN: quem não tem registro
        // continua aparecendo, com os campos nulos.
        $seasonAtual = null;
        try {
            $stSeason = $pdo->prepare("SELECT id FROM seasons WHERE league = ?
                                       AND (status IS NULL OR status <> 'completed')
                                       ORDER BY id DESC LIMIT 1");
            $stSeason->execute([$league]);
            $seasonAtual = $stSeason->fetchColumn() ?: null;
        } catch (Exception $e) { $seasonAtual = null; }

        $joinStats = $seasonAtual
            ? 'LEFT JOIN player_season_stats ps ON ps.player_id = p.id AND ps.season_id = ' . (int)$seasonAtual
            : 'LEFT JOIN player_season_stats ps ON 1 = 0';

        // Ordenação: só nomes de coluna conhecidos entram na consulta.
        $ordens = [
            'ovr'  => 'p.ovr DESC, p.name ASC',
            'name' => 'p.name ASC',
            'age'  => 'p.age ASC, p.ovr DESC',
            'pts'  => 'ps.pts_pg IS NULL, ps.pts_pg DESC, p.ovr DESC',
            'reb'  => 'ps.reb_pg IS NULL, ps.reb_pg DESC, p.ovr DESC',
            'ast'  => 'ps.ast_pg IS NULL, ps.ast_pg DESC, p.ovr DESC',
        ];
        $sortKey = strtolower(trim($_GET['sort'] ?? 'ovr'));
        $orderBy = $ordens[$sortKey] ?? $ordens['ovr'];

        $countStmt = $pdo->prepare("SELECT COUNT(*)
            FROM players p
            JOIN teams t ON p.team_id = t.id
            JOIN users u ON t.user_id = u.id
            WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

                $hasBadgesCount = playersColumnExists($pdo, 'badges_count');
                $badgesSelect = $hasBadgesCount ? ', p.badges_count' : ', NULL as badges_count';
                $hasTapaCount = playersColumnExists($pdo, 'tapa_count');
                $tapasSelect  = $hasTapaCount ? ', COALESCE(p.tapa_count,0) AS tapa_count, p.badge_name' : ', 0 AS tapa_count, NULL AS badge_name';

                try {
            $stmt = $pdo->prepare("
                                SELECT p.id, p.name, p.nba_player_id, p.foto_adicional, p.age, p.ovr, p.position, p.secondary_position
                                    {$badgesSelect}{$tapasSelect},
                  p.role,
                  p.was_traded, p.drafted_by_team_id,
                  -- usados só pra calcular o salário do cap (ver getPlayerBaseSalary)
                  p.draft_round, p.draft_pick_position, p.drafted_season_number,
                  COALESCE(p.available_for_trade, 0) as available_for_trade,
                  COALESCE(p.player_tag, NULL) as player_tag,
                  COALESCE(p.player_tag_color, NULL) as player_tag_color,
                  COALESCE(p.player_tag_copy, 0) as player_tag_copy,
                  t.id as team_id, t.city, t.name as team_name, t.league,
                  u.phone as owner_phone,
                  ps.games, ps.min_pg, ps.pts_pg, ps.reb_pg, ps.ast_pg, ps.stl_pg, ps.blk_pg
                FROM players p
                JOIN teams t ON p.team_id = t.id
                JOIN users u ON t.user_id = u.id
                {$joinStats}
                WHERE {$where}
                ORDER BY {$orderBy}
                LIMIT {$perPage} OFFSET {$offset}
            ");
        } catch (Exception $e) {
            $stmt = $pdo->prepare("
                                SELECT p.id, p.name, p.nba_player_id, p.foto_adicional, p.age, p.ovr, p.position, p.secondary_position
                                    {$badgesSelect},
                  p.was_traded, p.drafted_by_team_id,
                  COALESCE(p.available_for_trade, 0) as available_for_trade,
                  t.id as team_id, t.city, t.name as team_name, t.league,
                  u.phone as owner_phone
                FROM players p
                JOIN teams t ON p.team_id = t.id
                JOIN users u ON t.user_id = u.id
                WHERE {$where}
                ORDER BY p.ovr DESC, p.name ASC
                LIMIT {$perPage} OFFSET {$offset}
            ");
        }
        $stmt->execute($params);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
        markLoyaltyEligibility($pdo, $players); // is_loyal / cap_bonus_eligible (tag "Leal" + destaque)

        // Salário que o jogador ocupa no teto. Só a ELITE tem salary cap, então
        // nas outras ligas o campo nem vai — a coluna some da tela por isso.
        // A temporada é resolvida uma vez, fora do laço: define quem é calouro.
        $capLigaTemSalario = strtoupper(trim($league)) === 'ELITE';
        if ($capLigaTemSalario) {
            $temporadaCap = temporadaAtivaDaLiga($pdo, $league);
            $numTemporadaCap = $temporadaCap ? (int)$temporadaCap['season_number'] : null;
            foreach ($players as &$player) {
                $player['cap_salario'] = getPlayerBaseSalary($player, $numTemporadaCap);
                $player['cap_rookie']  = capEhCalouroNaTemporadaAtual($player, $numTemporadaCap);
            }
            unset($player);
        }

        foreach ($players as &$player) {
            appendPhoneFields($player);
        }
        unset($player);

        // OVR delta: diferença entre as últimas 2 temporadas registradas
        $playerIds = array_column($players, 'id');
        if (!empty($playerIds)) {
            try {
                $inPh = implode(',', array_fill(0, count($playerIds), '?'));
                $stmtDelta = $pdo->prepare("
                    SELECT player_id, ovr, season_id
                    FROM player_season_log
                    WHERE player_id IN ($inPh)
                      AND season_id IN (SELECT id FROM seasons WHERE sprint_id IN
                                        (SELECT id FROM sprints WHERE status = 'active'))
                    ORDER BY player_id ASC, season_id DESC
                ");
                $stmtDelta->execute($playerIds);
                $byPlayer = [];
                foreach ($stmtDelta->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $pid = (int)$row['player_id'];
                    if (!isset($byPlayer[$pid])) $byPlayer[$pid] = [];
                    if (count($byPlayer[$pid]) < 2) $byPlayer[$pid][] = (int)$row['ovr'];
                }
                foreach ($players as &$p) {
                    $ovrs = $byPlayer[(int)$p['id']] ?? [];
                    $p['ovr_delta'] = count($ovrs) === 2 ? ($ovrs[0] - $ovrs[1]) : 0;
                }
                unset($p);
            } catch (Exception $e) { /* tabela pode não existir ainda */ }
        }

        jsonResponse(200, [
            'players' => $players,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 1
            ]
        ]);
    }

    if ($action === 'player_details') {
        $playerId = isset($_GET['player_id']) ? (int)$_GET['player_id'] : 0;
        if ($playerId <= 0) {
            jsonResponse(400, ['error' => 'player_id é obrigatório.']);
        }

        $user = getUserSession();
        if (!$user) {
            jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);
        }

        $isAdmin = hasAdminAccess($pdo, (int)$user['id']);

        $stmtPlayer = $pdo->prepare('
            SELECT p.*, t.id AS team_id, t.city, t.name AS team_name, t.league,
                   u.name AS owner_name, u.phone AS owner_phone
            FROM players p
            JOIN teams t ON p.team_id = t.id
            JOIN users u ON t.user_id = u.id
            WHERE p.id = ?
            LIMIT 1
        ');
        $stmtPlayer->execute([$playerId]);
        $player = $stmtPlayer->fetch(PDO::FETCH_ASSOC);
        if (!$player) {
            jsonResponse(404, ['error' => 'Jogador não encontrado.']);
        }

        if (!$isAdmin && isset($player['league']) && $player['league'] !== ($user['league'] ?? '')) {
            jsonResponse(403, ['error' => 'Sem permissão para acessar este jogador.']);
        }

        $playerName = (string)($player['name'] ?? '');
        $playerLeague = (string)($player['league'] ?? ($user['league'] ?? ''));
        $ovrColumn = playerOvrColumnForDetails($pdo);

        $stmtTrades = $pdo->prepare("
            SELECT
                t.id AS trade_id,
                t.league,
                t.status,
                t.created_at,
                t.updated_at,
                from_team.city AS from_city,
                from_team.name AS from_name,
                to_team.city AS to_city,
                to_team.name AS to_name,
                ti.from_team,
                ti.player_name,
                ti.player_position,
                ti.player_age,
                ti.player_ovr,
                'single' AS trade_type
            FROM trade_items ti
            JOIN trades t ON t.id = ti.trade_id
            JOIN teams from_team ON t.from_team_id = from_team.id
            JOIN teams to_team ON t.to_team_id = to_team.id
                        WHERE ti.pick_id IS NULL
                            AND t.status = 'accepted'
                            AND t.league = ?
                            AND (ti.player_id = ? OR (ti.player_id IS NULL AND ti.player_name = ?))
            ORDER BY t.created_at DESC
        ");
        $stmtTrades->execute([$playerLeague, $playerId, $playerName]);
        $tradeRows = $stmtTrades->fetchAll(PDO::FETCH_ASSOC);

        $multiRows = [];
        try {
            $stmtMulti = $pdo->prepare("
                SELECT
                    mt.id AS trade_id,
                    mt.league,
                    mt.status,
                    mt.created_at,
                    mt.updated_at,
                    from_team.city AS from_city,
                    from_team.name AS from_name,
                    to_team.city AS to_city,
                    to_team.name AS to_name,
                    1 AS from_team,
                    mti.player_name,
                    mti.player_position,
                    mti.player_age,
                    mti.player_ovr,
                    'multi' AS trade_type
                FROM multi_trade_items mti
                JOIN multi_trades mt ON mt.id = mti.trade_id
                JOIN teams from_team ON from_team.id = mti.from_team_id
                JOIN teams to_team ON to_team.id = mti.to_team_id
                                WHERE mti.pick_id IS NULL
                                    AND mt.status = 'accepted'
                                    AND mt.league = ?
                                    AND (mti.player_id = ? OR (mti.player_id IS NULL AND mti.player_name = ?))
                ORDER BY mt.created_at DESC
            ");
            $stmtMulti->execute([$playerLeague, $playerId, $playerName]);
            $multiRows = $stmtMulti->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            $multiRows = [];
        }

        $tradeRows = array_merge($tradeRows, $multiRows);

        $transfers = [];
        $transferKeys = [];
        $ovrHistory = [];

        foreach ($tradeRows as $row) {
            $fromTeamName = trim(($row['from_city'] ?? '') . ' ' . ($row['from_name'] ?? ''));
            $toTeamName = trim(($row['to_city'] ?? '') . ' ' . ($row['to_name'] ?? ''));
            $sent = (int)($row['from_team'] ?? 0) === 1;
            $from = $sent ? $fromTeamName : $toTeamName;
            $to = $sent ? $toTeamName : $fromTeamName;

            $year = null;
            if (!empty($row['created_at'])) {
                try {
                    $year = (int)(new DateTime($row['created_at']))->format('Y');
                } catch (Exception $e) {
                    $year = null;
                }
            }

            $key = strtolower(trim($year . '|' . $from . '|' . $to));
            if (!isset($transferKeys[$key])) {
                $transferKeys[$key] = true;
                $transfers[] = [
                    'trade_id' => (int)$row['trade_id'],
                    'league' => $row['league'],
                    'status' => $row['status'],
                    'from_team' => $from,
                    'to_team' => $to,
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                    'year' => $year
                ];
            }

            if ($row['player_age'] !== null || $row['player_ovr'] !== null) {
                $ovrHistory[] = [
                    'age' => $row['player_age'] !== null ? (int)$row['player_age'] : null,
                    'ovr' => $row['player_ovr'] !== null ? (int)$row['player_ovr'] : null,
                    'source' => 'trade',
                    'date' => $row['created_at']
                ];
            }
        }

        if (isset($player['age']) || isset($player['ovr'])) {
            $ovrHistory[] = [
                'age' => isset($player['age']) ? (int)$player['age'] : null,
                'ovr' => isset($player['ovr']) ? (int)$player['ovr'] : null,
                'source' => 'current',
                'date' => null
            ];
        }

        $ovrByAge = [];
        foreach ($ovrHistory as $entry) {
            if ($entry['age'] === null) {
                continue;
            }
            $ageKey = (int)$entry['age'];
            if (!isset($ovrByAge[$ageKey]) || ((int)$entry['ovr'] > (int)($ovrByAge[$ageKey]['ovr'] ?? 0))) {
                $ovrByAge[$ageKey] = $entry;
            }
        }
        ksort($ovrByAge);
        $ovrTimeline = array_values($ovrByAge);



        appendPhoneFields($player);

        // Histórico por temporada
        $seasonLog = [];
        $ovrDelta  = 0;
        try {
            $stmtLog = $pdo->prepare("
                SELECT season_id, season_number, sprint_number, year, team_id, team_name, ovr, age
                FROM player_season_log
                WHERE player_id = ?
                  AND season_id IN (SELECT id FROM seasons WHERE sprint_id IN
                                    (SELECT id FROM sprints WHERE status = 'active'))
                ORDER BY season_id ASC
            ");
            $stmtLog->execute([$playerId]);
            $seasonLog = $stmtLog->fetchAll(PDO::FETCH_ASSOC);
            if (count($seasonLog) >= 2) {
                $last = (int)($seasonLog[count($seasonLog)-1]['ovr'] ?? 0);
                $prev = (int)($seasonLog[count($seasonLog)-2]['ovr'] ?? 0);
                $ovrDelta = $last - $prev;
            }
        } catch (Exception $e) {}

        jsonResponse(200, [
            'player' => [
                'id' => (int)$player['id'],
                'name' => $player['name'],
                'age' => $player['age'] ?? null,
                'position' => $player['position'] ?? null,
                'secondary_position' => $player['secondary_position'] ?? null,
                'ovr' => $player[$ovrColumn] ?? ($player['ovr'] ?? null),
                'team_id' => (int)$player['team_id'],
                'team_name' => trim(($player['city'] ?? '') . ' ' . ($player['team_name'] ?? '')),
                'league' => $player['league'] ?? null,
                'owner_name' => $player['owner_name'] ?? null,
                'owner_phone' => $player['owner_phone_whatsapp'] ?? null,
                'player_skill_grades' => $player['player_skill_grades'] ?? null,
                'skill_in'     => $player['skill_in']     ?? null,
                'skill_mid'    => $player['skill_mid']    ?? null,
                'skill_3pt'    => $player['skill_3pt']    ?? null,
                'skill_post_d' => $player['skill_post_d'] ?? null,
                'skill_per_d'  => $player['skill_per_d']  ?? null,
                'skill_play'   => $player['skill_play']   ?? null,
                'skill_reb'    => $player['skill_reb']    ?? null,
                'skill_athl'   => $player['skill_athl']   ?? null,
                'skill_iq'     => $player['skill_iq']     ?? null,
                'skill_pot'    => $player['skill_pot']    ?? null,
            ],
            'transfers' => $transfers,
            'ovr_timeline' => $ovrTimeline,
            'season_log' => $seasonLog,
            'ovr_delta' => $ovrDelta,
            'awards' => []
        ]);
    }

    if ($action === 'search_player') {
        $query = trim($_GET['query'] ?? '');
        if ($query === '' || mb_strlen($query) < 2) {
            jsonResponse(200, ['players' => []]);
        }
        $stmt = $pdo->prepare('
                 SELECT p.id, p.name, p.nba_player_id, p.foto_adicional, p.age, p.ovr, p.position, p.secondary_position,
                   p.was_traded, p.drafted_by_team_id,
                   t.id as team_id, t.city, t.name as team_name, t.league,
                   u.phone as owner_phone
            FROM players p
            JOIN teams t ON p.team_id = t.id
            JOIN users u ON t.user_id = u.id
            WHERE t.league = ? AND p.name LIKE ?
            ORDER BY p.ovr DESC, p.name ASC
            LIMIT 50
        ');
        $stmt->execute([$league, '%' . $query . '%']);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($players as &$player) {
            appendPhoneFields($player);
        }
        unset($player);

        jsonResponse(200, ['players' => $players]);
    }


    if ($action === 'team_info') {
        $user = getUserSession();
        if (!$user) jsonResponse(401, ['error' => 'Sessão expirada.']);

        $requestedTeamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
        if ($requestedTeamId > 0) {
            // Fetch any team in user's league
            $userLeague = $user['league'] ?? '';
            $stmtT = $pdo->prepare('SELECT t.*, u.name AS owner_name FROM teams t JOIN users u ON u.id = t.user_id WHERE t.id = ? AND t.league = ? LIMIT 1');
            $stmtT->execute([$requestedTeamId, $userLeague]);
        } else {
            $stmtT = $pdo->prepare('SELECT t.*, u.name AS owner_name FROM teams t JOIN users u ON u.id = t.user_id WHERE t.user_id = ? LIMIT 1');
            $stmtT->execute([$user['id']]);
        }
        $teamRow = $stmtT->fetch(PDO::FETCH_ASSOC);
        if (!$teamRow) jsonResponse(404, ['error' => 'Time não encontrado.']);
        $tid = (int)$teamRow['id'];

        $tapasExtra = playersColumnExists($pdo, 'tapa_count') ? ', COALESCE(tapa_count,0) AS tapa_count, badge_name' : ', 0 AS tapa_count, NULL AS badge_name';
        $stmtP = $pdo->prepare("SELECT id, name, position, secondary_position, ovr, age, role, foto_adicional, nba_player_id,
                                        drafted_by_team_id, drafted_season_number, was_traded, is_franchise_player,
                                        COALESCE(player_tag, NULL) as player_tag, COALESCE(player_tag_color, NULL) as player_tag_color
                                        {$tapasExtra}
                                 FROM players WHERE team_id = ? ORDER BY ovr DESC");
        $stmtP->execute([$tid]);

        $teamPlayers = $stmtP->fetchAll(PDO::FETCH_ASSOC);
        foreach ($teamPlayers as &$p) { $p['team_id'] = $tid; }
        unset($p);
        // is_loyal (tag "Leal", qualquer liga) e cap_bonus_eligible (leal + OVR>=90
        // + draftado pelo draft da própria temporada) — mesma régua em toda liga.
        markLoyaltyEligibility($pdo, $teamPlayers);

        $roster = ['Titular' => [], 'Banco' => [], 'G-League' => [], 'Outro' => []];
        foreach ($teamPlayers as $p) {
            $role = isset($roster[$p['role']]) ? $p['role'] : 'Outro';
            $roster[$role][] = $p;
        }

        $cap = topEightCap($pdo, $tid);
        $capBonus = restrictedCapBonus($pdo, $tid);

        $stmtTCount = $pdo->prepare("SELECT COUNT(*) FROM trades WHERE status = 'accepted' AND (from_team_id = ? OR to_team_id = ?)");
        $stmtTCount->execute([$tid, $tid]);
        $tradesCount = (int)$stmtTCount->fetchColumn();

        $stmtTrades = $pdo->prepare("
            SELECT tr.id, tr.updated_at,
                   TRIM(CONCAT(COALESCE(tf.city,''),' ',tf.name)) AS from_team_name,
                   TRIM(CONCAT(COALESCE(tt.city,''),' ',tt.name)) AS to_team_name,
                   tr.from_team_id, tr.to_team_id
            FROM trades tr
            JOIN teams tf ON tf.id = tr.from_team_id
            JOIN teams tt ON tt.id = tr.to_team_id
            WHERE tr.status = 'accepted' AND (tr.from_team_id = ? OR tr.to_team_id = ?)
            ORDER BY tr.updated_at DESC LIMIT 3
        ");
        $stmtTrades->execute([$tid, $tid]);
        $lastTrades = $stmtTrades->fetchAll(PDO::FETCH_ASSOC);

        // Buscar itens (jogadores) de cada trade
        foreach ($lastTrades as $k => $tr) {
            try {
                $stmtItems = $pdo->prepare(
                    "SELECT player_name, player_position, player_ovr, from_team
                     FROM trade_items WHERE trade_id = ? AND pick_id IS NULL
                     ORDER BY from_team DESC, player_name ASC"
                );
                $stmtItems->execute([$tr['id']]);
                $lastTrades[$k]['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {
                $lastTrades[$k]['items'] = [];
            }
        }

        $titles = 0;
        try {
            $stmtHof = $pdo->prepare("SELECT titles FROM hall_of_fame WHERE team_id = ? LIMIT 1");
            $stmtHof->execute([$tid]);
            $hofRow = $stmtHof->fetch(PDO::FETCH_ASSOC);
            $titles = $hofRow ? (int)$hofRow['titles'] : 0;
        } catch (Exception $e) {}

        jsonResponse(200, [
            'team'         => [
                'id'         => $tid,
                'name'       => $teamRow['name'],
                'city'       => $teamRow['city'],
                'photo_url'  => $teamRow['photo_url'] ?? null,
                'league'     => $teamRow['league'],
                'owner_name' => $teamRow['owner_name'],
                'team_tag'   => $teamRow['team_tag'] ?? null,
                'conference' => $teamRow['conference'] ?? null,
            ],
            'cap'          => $cap,
            'restricted_bonus' => $capBonus,
            'trades_count' => $tradesCount,
            'last_trades'  => $lastTrades,
            'roster'       => $roster,
            'titles'       => $titles,
        ]);
    }

    $teamId = isset($_GET['id']) ? (int) $_GET['id'] : null;
    $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
    $leagueParam = isset($_GET['league']) ? strtoupper(trim($_GET['league'])) : null;

    // Obter league do usuário da sessão ou do time vinculado
    $user = getUserSession();
    if (!$user) {
        jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);
    }

    $league = null;
    $isAdmin = hasAdminAccess($pdo, (int)$user['id']);

    if ($leagueParam && $isAdmin) {
        $league = $leagueParam;
    } else {
        $teamLeagueStmt = $pdo->prepare('SELECT league FROM teams WHERE user_id = ? LIMIT 1');
        $teamLeagueStmt->execute([$user['id']]);
        $teamLeague = $teamLeagueStmt->fetch();
        if ($teamLeague && !empty($teamLeague['league'])) {
            $league = $teamLeague['league'];
        }
    }

    if (!$league) {
        $league = $user['league'] ?? 'ROOKIE';
    }
    
    $sql = 'SELECT t.id, t.name, t.city, t.mascot, t.photo_url, t.league, t.division_id, d.name AS division_name, t.user_id, u.photo_url AS user_photo, u.name AS owner_name, u.phone AS owner_phone
            FROM teams t
            LEFT JOIN divisions d ON d.id = t.division_id
            LEFT JOIN users u ON u.id = t.user_id
            WHERE t.league = ?';
    $params = [$league];
    $clauses = [];
    if ($teamId) {
        $clauses[] = 't.id = ?';
        $params[] = $teamId;
    }
    if ($userId) {
        $clauses[] = 't.user_id = ?';
        $params[] = $userId;
    }
    if ($clauses) {
        $sql .= ' AND ' . implode(' AND ', $clauses);
    }
    $sql .= ' ORDER BY t.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $teams = $stmt->fetchAll();

    foreach ($teams as &$team) {
        $team['cap_top8'] = topEightCap($pdo, (int) $team['id']);
        // Ligas no salary cap (ELITE) precisam da folha pro "Copiar Time" —
        // sem isso ele mostraria a soma de OVR do Top 8, que lá não vale nada.
        $team['salary'] = teamSalarySummaryForCopy($pdo, (int) $team['id'], (string)($team['league'] ?? ''));
        $rawPhone = $team['owner_phone'] ?? '';
        $normalizedPhone = $rawPhone !== '' ? normalizeBrazilianPhone($rawPhone) : null;
        if (!$normalizedPhone && $rawPhone !== '') {
            $digits = preg_replace('/\D+/', '', $rawPhone);
            if ($digits !== '') {
                $normalizedPhone = str_starts_with($digits, '55') ? $digits : '55' . $digits;
            }
        }
        $team['owner_phone_display'] = $rawPhone !== '' ? formatBrazilianPhone($rawPhone) : null;
        $team['owner_phone_whatsapp'] = $normalizedPhone;
        // Sincronizar e expor contador de trades por time
        $team['trades_used'] = syncTeamTradeCounterLocal($pdo, (int)$team['id']);
    }

    jsonResponse(200, ['teams' => $teams]);
}

if ($method === 'POST') {
    $body = readJsonBody();

    // ── save_ai_tag: IA auto-classifica o time ──────────────────────────────
    if (($body['action'] ?? '') === 'save_ai_tag') {
        $user = getUserSession();
        if (!$user) jsonResponse(401, ['error' => 'Sessão expirada.']);
        $validTags = ['Contending', 'Buying', 'Selling', 'Rebuilding'];
        $tag = $body['tag'] ?? null;
        if (!$tag || !in_array($tag, $validTags, true)) jsonResponse(400, ['error' => 'Tag inválida.']);
        $season = isset($body['season']) ? (int)$body['season'] : 0;
        // auto-migrate columns
        try { $pdo->exec("ALTER TABLE teams ADD COLUMN IF NOT EXISTS team_tag_source VARCHAR(10) NULL DEFAULT NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE teams ADD COLUMN IF NOT EXISTS team_tag_ai_season INT NULL DEFAULT NULL"); } catch (Exception $e) {}
        $stmt = $pdo->prepare("UPDATE teams SET team_tag = ?, team_tag_source = 'ai', team_tag_ai_season = ? WHERE user_id = ?");
        $stmt->execute([$tag, $season, $user['id']]);
        jsonResponse(200, ['success' => true, 'tag' => $tag]);
    }

    // ── sprint_review: revisão obrigatória dos dados no início de cada sprint ──
    // Salva time + GM de uma vez só e marca a sprint como revisada, pra o popup
    // bloqueante do dashboard parar de aparecer até a próxima sprint começar.
    if (($body['action'] ?? '') === 'sprint_review') {
        $sessionUser = getUserSession();
        if (!$sessionUser || !isset($sessionUser['id'])) jsonResponse(401, ['error' => 'Sessão expirada.']);
        $userId = (int) $sessionUser['id'];

        $stmtT = $pdo->prepare('SELECT id, league, name, city, photo_url FROM teams WHERE user_id = ? LIMIT 1');
        $stmtT->execute([$userId]);
        $teamRow = $stmtT->fetch();
        if (!$teamRow) jsonResponse(404, ['error' => 'Time não encontrado.']);

        $rvName   = trim((string)($body['name'] ?? ''));
        $rvCity   = trim((string)($body['city'] ?? ''));
        $rvMascot = trim((string)($body['mascot'] ?? ''));
        $rvGm     = trim((string)($body['gm_name'] ?? ''));
        $rvEmail  = trim((string)($body['email'] ?? ''));
        $rvPhoto  = trim((string)($body['photo_url'] ?? ''));

        if ($rvName === '' || $rvCity === '' || $rvMascot === '' || $rvGm === '') {
            jsonResponse(422, ['error' => 'Preencha nome do time, cidade, mascote e nome do GM.']);
        }
        if ($rvEmail === '' || !filter_var($rvEmail, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(422, ['error' => 'E-mail inválido.']);
        }
        $stmtDup = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
        $stmtDup->execute([$rvEmail, $userId]);
        if ($stmtDup->fetch()) jsonResponse(422, ['error' => 'Esse e-mail já está em uso por outro GM.']);

        // Logo nova (data URL) — mesmo tratamento do PUT abaixo.
        if ($rvPhoto !== '' && str_starts_with($rvPhoto, 'data:image/')) {
            try {
                $commaPos = strpos($rvPhoto, ',');
                $meta = substr($rvPhoto, 0, $commaPos);
                $base64 = substr($rvPhoto, $commaPos + 1);
                $ext = 'png';
                if (preg_match('/data:(image\/(png|jpeg|jpg|webp));base64/i', $meta, $m)) {
                    $mime = strtolower($m[1]);
                    if ($mime === 'image/jpeg' || $mime === 'image/jpg') { $ext = 'jpg'; }
                    if ($mime === 'image/webp') { $ext = 'webp'; }
                }
                $binary = base64_decode($base64);
                if ($binary === false) throw new Exception('Falha ao decodificar imagem.');
                $dirFs = __DIR__ . '/../img/teams';
                if (!is_dir($dirFs)) { @mkdir($dirFs, 0775, true); }
                $filename = 'team-' . $userId . '-' . time() . '.' . $ext;
                if (file_put_contents($dirFs . '/' . $filename, $binary) === false) {
                    throw new Exception('Falha ao salvar imagem.');
                }
                $rvPhoto = '/img/teams/' . $filename;
            } catch (Exception $e) {
                $rvPhoto = '';
            }
        }

        // Sprint ativa da liga do time — é o que marca "já revisou".
        $stmtSp = $pdo->prepare("SELECT id FROM sprints WHERE league = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $stmtSp->execute([(string)$teamRow['league']]);
        $sprintId = (int) ($stmtSp->fetchColumn() ?: 0);

        if (!teamColumnExists($pdo, 'sprint_review_sprint_id')) {
            try { $pdo->exec("ALTER TABLE teams ADD COLUMN sprint_review_sprint_id INT NULL DEFAULT NULL"); } catch (Exception $e) {}
        }
        if (!teamColumnExists($pdo, 'sprint_review_sprint_id')) {
            jsonResponse(500, ['error' => 'Não foi possível preparar o banco para a revisão.']);
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')
                ->execute([$rvGm, $rvEmail, $userId]);
            $pdo->prepare('UPDATE teams SET name = ?, city = ?, mascot = ?, photo_url = ?, sprint_review_sprint_id = ? WHERE id = ?')
                ->execute([
                    $rvName,
                    $rvCity,
                    $rvMascot,
                    $rvPhoto !== '' ? $rvPhoto : $teamRow['photo_url'],
                    $sprintId ?: null,
                    (int) $teamRow['id'],
                ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[sprint_review] ' . $e->getMessage());
            jsonResponse(400, ['error' => 'Erro ao salvar os dados.']);
        }

        $_SESSION['user_name'] = $rvGm;
        $_SESSION['user_email'] = $rvEmail;

        jsonResponse(200, ['success' => true]);
    }

    $name = trim($body['name'] ?? '');
    $city = trim($body['city'] ?? '');
    $mascot = trim($body['mascot'] ?? '');
    $conference = strtoupper(trim($body['conference'] ?? ''));
    $divisionId = $body['division_id'] ?? null;
    $userId = $body['user_id'] ?? null;
    $photoUrl = trim($body['photo_url'] ?? '');
    
    // Obter usuário e liga da sessão quando user_id não for fornecido
    $sessionUser = getUserSession();
    if (!$userId && isset($sessionUser['id'])) {
        $userId = (int) $sessionUser['id'];
    }
    if (!$userId) {
        jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);
    }

    // Obter league do usuário
    $userStmt = $pdo->prepare('SELECT league FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $userRow = $userStmt->fetch();
    if (!$userRow) {
        jsonResponse(404, ['error' => 'Usuário não encontrado.']);
    }
    $league = $sessionUser['league'] ?? $userRow['league'];

    // Mascote é opcional no onboarding; permitir vazio
    if ($name === '' || $city === '') {
        jsonResponse(422, ['error' => 'Nome e cidade são obrigatórios.']);
    }
    // Conferência é obrigatória somente se coluna existir
    $hasConference = teamColumnExists($pdo, 'conference');
    if ($hasConference) {
        if (!in_array($conference, ['LESTE', 'OESTE'], true)) {
            jsonResponse(422, ['error' => 'Conferência inválida. Escolha LESTE ou OESTE.']);
        }
    }

    // Se a foto vier como data URL, salvar em img/teams e substituir por caminho relativo
    if ($photoUrl && str_starts_with($photoUrl, 'data:image/')) {
        $savedPath = null;
        try {
            $commaPos = strpos($photoUrl, ',');
            $meta = substr($photoUrl, 0, $commaPos);
            $base64 = substr($photoUrl, $commaPos + 1);
            $mime = null;
            if (preg_match('/data:(image\/(png|jpeg|jpg|webp));base64/i', $meta, $m)) {
                $mime = strtolower($m[1]);
            }
            $ext = 'png';
            if ($mime === 'image/jpeg' || $mime === 'image/jpg') { $ext = 'jpg'; }
            if ($mime === 'image/webp') { $ext = 'webp'; }
            $binary = base64_decode($base64);
            if ($binary === false) { throw new Exception('Falha ao decodificar imagem.'); }

            $dirFs = __DIR__ . '/../img/teams';
            if (!is_dir($dirFs)) { @mkdir($dirFs, 0775, true); }
            $filename = 'team-' . $userId . '-' . time() . '.' . $ext;
            $fullPath = $dirFs . '/' . $filename;
            if (file_put_contents($fullPath, $binary) === false) {
                throw new Exception('Falha ao salvar imagem.');
            }
            // Caminho público
            $savedPath = '/img/teams/' . $filename;
            $photoUrl = $savedPath;
        } catch (Exception $e) {
            // Se falhar, ignora a foto para não quebrar o cadastro
            $photoUrl = '';
        }
    }

    if ($divisionId) {
        $checkDiv = $pdo->prepare('SELECT id FROM divisions WHERE id = ?');
        $checkDiv->execute([$divisionId]);
        if (!$checkDiv->fetch()) {
            jsonResponse(404, ['error' => 'Divisão não encontrada.']);
        }
    }

    if ($hasConference) {
        $stmt = $pdo->prepare('INSERT INTO teams (user_id, league, conference, name, city, mascot, division_id, photo_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $league, $conference, $name, $city, $mascot !== '' ? $mascot : '', $divisionId, $photoUrl ?: null]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO teams (user_id, league, name, city, mascot, division_id, photo_url) VALUES (?, ?, ?, ?, ?, ?, ?)');
        // Mascote não pode ser NULL na tabela; use string vazia quando não fornecido
        $stmt->execute([$userId, $league, $name, $city, $mascot !== '' ? $mascot : '', $divisionId, $photoUrl ?: null]);
    }
    $teamId = (int) $pdo->lastInsertId();

    jsonResponse(201, ['message' => 'Time criado.', 'team_id' => $teamId]);
}

if ($method === 'PUT') {
    $body = readJsonBody();
    $name = trim($body['name'] ?? '');
    $city = trim($body['city'] ?? '');
    $mascot = trim($body['mascot'] ?? '');
    $conference = strtoupper(trim($body['conference'] ?? ''));
    $photoUrl = trim($body['photo_url'] ?? '');
    $teamTagRaw = $body['team_tag'] ?? null;
    $validTags = ['Contending', 'Buying', 'Selling', 'Rebuilding'];
    $teamTag = ($teamTagRaw !== null && in_array($teamTagRaw, $validTags, true)) ? $teamTagRaw : null;

    $sessionUser = getUserSession();
    if (!isset($sessionUser['id'])) {
        jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);
    }
    $userId = (int) $sessionUser['id'];

    // Buscar time do usuário
    $stmt = $pdo->prepare('SELECT id, league, conference, photo_url, custom_header, use_custom_header FROM teams WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $team = $stmt->fetch();
    if (!$team) {
        jsonResponse(404, ['error' => 'Time não encontrado para o usuário.']);
    }

    // Salvar logo se vier como data URL
    if ($photoUrl && str_starts_with($photoUrl, 'data:image/')) {
        try {
            $commaPos = strpos($photoUrl, ',');
            $meta = substr($photoUrl, 0, $commaPos);
            $base64 = substr($photoUrl, $commaPos + 1);
            $mime = null;
            if (preg_match('/data:(image\/(png|jpeg|jpg|webp));base64/i', $meta, $m)) {
                $mime = strtolower($m[1]);
            }
            $ext = 'png';
            if ($mime === 'image/jpeg' || $mime === 'image/jpg') { $ext = 'jpg'; }
            if ($mime === 'image/webp') { $ext = 'webp'; }
            $binary = base64_decode($base64);
            if ($binary === false) { throw new Exception('Falha ao decodificar imagem.'); }

            $dirFs = __DIR__ . '/../img/teams';
            if (!is_dir($dirFs)) { @mkdir($dirFs, 0775, true); }
            $filename = 'team-' . $userId . '-' . time() . '.' . $ext;
            $fullPath = $dirFs . '/' . $filename;
            if (file_put_contents($fullPath, $binary) === false) {
                throw new Exception('Falha ao salvar imagem.');
            }
            $photoUrl = '/img/teams/' . $filename;
        } catch (Exception $e) {
            $photoUrl = '';
        }
    }

    $hasConference = teamColumnExists($pdo, 'conference');
    if ($hasConference && $conference !== '' && !in_array($conference, ['LESTE', 'OESTE'], true)) {
        jsonResponse(422, ['error' => 'Conferência inválida.']);
    }

    if (!teamColumnExists($pdo, 'team_tag')) {
        try { $pdo->exec("ALTER TABLE teams ADD COLUMN team_tag VARCHAR(20) NULL DEFAULT NULL"); } catch (Exception $e) {}
    }

    $setParts = ['name = ?', 'city = ?', 'mascot = ?', 'photo_url = ?', 'team_tag = ?'];
    $params = [
        $name !== '' ? $name : $team['name'],
        $city !== '' ? $city : $team['city'],
        $mascot !== '' ? $mascot : '',
        $photoUrl !== '' ? $photoUrl : $team['photo_url'],
        $teamTag,
    ];
    if ($hasConference) {
        $setParts[] = 'conference = ?';
        $params[] = $conference !== '' ? $conference : ($team['conference'] ?? null);
    }
    $params[] = (int) $team['id'];
    $pdo->prepare('UPDATE teams SET ' . implode(', ', $setParts) . ' WHERE id = ?')->execute($params);

    jsonResponse(200, ['message' => 'Time atualizado.']);

    // Unreachable — jsonResponse exits; guard for static analysis only.
    exit;
}

// ── PATCH: cabeçalho personalizado ──────────────────────────────────────────
if ($method === 'PATCH') {
    $body = readJsonBody();
    $sessionUser = getUserSession();
    if (!isset($sessionUser['id'])) {
        jsonResponse(401, ['error' => 'Sessão expirada ou usuário não autenticado.']);
    }
    $userId = (int) $sessionUser['id'];

    $stmt = $pdo->prepare('SELECT id FROM teams WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $team = $stmt->fetch();
    if (!$team) {
        jsonResponse(404, ['error' => 'Time não encontrado.']);
    }

    if (!teamColumnExists($pdo, 'custom_header')) {
        try { $pdo->exec('ALTER TABLE teams ADD COLUMN custom_header TEXT NULL'); } catch (Exception $e) {}
    }
    if (!teamColumnExists($pdo, 'use_custom_header')) {
        try { $pdo->exec('ALTER TABLE teams ADD COLUMN use_custom_header TINYINT(1) NOT NULL DEFAULT 0'); } catch (Exception $e) {}
    }

    $customHeader    = $body['custom_header']    ?? null;
    $useCustomHeader = isset($body['use_custom_header']) ? (int)(bool)$body['use_custom_header'] : null;

    $setParts = [];
    $params   = [];
    if ($customHeader !== null)    { $setParts[] = 'custom_header = ?';    $params[] = $customHeader; }
    if ($useCustomHeader !== null) { $setParts[] = 'use_custom_header = ?'; $params[] = $useCustomHeader; }

    if ($setParts) {
        $params[] = (int) $team['id'];
        $pdo->prepare('UPDATE teams SET ' . implode(', ', $setParts) . ' WHERE id = ?')->execute($params);
    }

    jsonResponse(200, ['message' => 'Cabeçalho salvo.']);
}

jsonResponse(405, ['error' => 'Method not allowed']);
