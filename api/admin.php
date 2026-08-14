<?php
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/backend/helpers.php';
require_once dirname(__DIR__) . '/backend/league_cap.php';
// Tabela OVR→salário e a regra da lenda, usadas pelo painel de conferência do cap.
require_once dirname(__DIR__) . '/backend/salary_cap.php';
require_once dirname(__DIR__) . '/backend/nba_sync.php';

$user = getUserSession();
if (!$user) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso negado']);
    exit;
}

$pdo = db();
$isGlobalAdminApi = ($user['user_type'] ?? 'jogador') === 'admin';
$apiAdminLeagues  = $isGlobalAdminApi ? ['ELITE','NEXT','RISE','ROOKIE'] : getAdminLeagues($pdo, (int)$user['id']);
if (!$isGlobalAdminApi && empty($apiAdminLeagues)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso negado']);
    exit;
}

/**
 * Garante que o admin autenticado tem permissão sobre a liga alvo desta ação.
 * Admin global sempre passa. Admin de liga só passa se $league estiver entre suas ligas.
 * Encerra a resposta com 403 se não tiver permissão.
 */
function requireLeagueScope(bool $isGlobalAdmin, array $adminLeagues, ?string $league): void {
    if ($isGlobalAdmin) return;
    $league = $league ? strtoupper(trim($league)) : null;
    if (!$league || !in_array($league, $adminLeagues, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Você não tem permissão para administrar esta liga.']);
        exit;
    }
}

// Ensure n8n_webhook_url column exists
try {
    $pdo->exec("ALTER TABLE league_settings ADD COLUMN IF NOT EXISTS n8n_webhook_url TEXT NULL");
} catch (Exception $e) { /* silencia — coluna pode já existir ou engine não suporta IF NOT EXISTS */ }

// Ensure league video columns exist (Progression, Sistemas, Free Agency)
try {
    $pdo->exec("ALTER TABLE league_settings ADD COLUMN IF NOT EXISTS progression_video_url TEXT NULL");
    $pdo->exec("ALTER TABLE league_settings ADD COLUMN IF NOT EXISTS sistemas_video_url TEXT NULL");
    $pdo->exec("ALTER TABLE league_settings ADD COLUMN IF NOT EXISTS freeagency_video_url TEXT NULL");
} catch (Exception $e) { /* silencia — coluna pode já existir ou engine não suporta IF NOT EXISTS */ }

// Ensure CAP auto-recalc columns/history table exist
ensureLeagueCapAutoTables($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$validLeagues = ['ELITE','NEXT','RISE','ROOKIE'];
ensurePlayerRestrictionColumns($pdo);

// Helpers para colunas e OVR
function columnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) { return false; }
}
function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) { return false; }
}
function playerOvrColumn(PDO $pdo): string {
    return columnExists($pdo, 'players', 'ovr') ? 'ovr' : (columnExists($pdo, 'players', 'overall') ? 'overall' : 'ovr');
}

function ensureTradeInGameColumn(PDO $pdo): void
{
    try {
        if (!columnExists($pdo, 'trades', 'is_in_game')) {
            $pdo->exec('ALTER TABLE trades ADD COLUMN is_in_game TINYINT(1) NOT NULL DEFAULT 0');
        }
    } catch (Exception $e) {}
    try {
        if (tableExists($pdo, 'multi_trades') && !columnExists($pdo, 'multi_trades', 'is_in_game')) {
            $pdo->exec('ALTER TABLE multi_trades ADD COLUMN is_in_game TINYINT(1) NOT NULL DEFAULT 0');
        }
    } catch (Exception $e) {}
}

ensureTradeInGameColumn($pdo);

// GET - Listar dados do admin
if ($method === 'GET') {
    switch ($action) {

        /**
         * Painel de conferência do cap: a tabela OVR→salário com quantos jogadores
         * ativos da liga estão em cada faixa, mais as lendas marcadas.
         *
         * Serve pra bater o cap "no olho" sem abrir time por time — era isso que
         * o pedido chamava de "a cola da tabela".
         */
        // Quem conta como "jovem do draft inicial". Os três números vivem aqui
        // porque aparecem em três lugares — a consulta, a conferência em PHP e
        // o texto da tela — e três cópias divergem no primeiro ajuste.
        case 'cap_tabela':
            define('JOVENS_IDADE_MIN', 19);
            define('JOVENS_IDADE_MAX', 23);
            define('JOVENS_OVR_MIN', 78);
            define('JOVENS_RODADAS', 4);

            $ligaCap = strtoupper(trim($_GET['league'] ?? 'ELITE'));
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $ligaCap);
            ensurePlayerRestrictionColumns($pdo);
            $capBaseFloorAdmin = capBaseEFloorDaLiga($pdo, $ligaCap);

            // Quantos jogadores por OVR, só de times da liga.
            $stCont = $pdo->prepare('SELECT p.ovr, COUNT(*) AS n
                                     FROM players p JOIN teams t ON t.id = p.team_id
                                     WHERE t.league = ? GROUP BY p.ovr');
            $stCont->execute([$ligaCap]);
            $porOvr = [];
            foreach ($stCont->fetchAll(PDO::FETCH_ASSOC) as $r) $porOvr[(int)$r['ovr']] = (int)$r['n'];

            // A tabela vai do 99 até "77 ou menos", igual ao regulamento.
            $linhas = [];
            for ($ovr = 99; $ovr >= 78; $ovr--) {
                $linhas[] = [
                    'ovr' => (string)$ovr,
                    'salario' => capOvrSalary($ovr),
                    'jogadores' => $porOvr[$ovr] ?? 0,
                ];
            }
            $abaixo = 0;
            foreach ($porOvr as $ovr => $n) if ($ovr <= 77) $abaixo += $n;
            $linhas[] = ['ovr' => '77 ou menos', 'salario' => CAP_VETERAN_MINIMUM_MILLIONS, 'jogadores' => $abaixo];

            // Lendas marcadas, com o que elas custam de fato.
            $stL = $pdo->prepare("SELECT p.id, p.name, p.ovr, p.age,
                                         TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS time_nome
                                  FROM players p JOIN teams t ON t.id = p.team_id
                                  WHERE t.league = ? AND p.is_lenda = 1
                                  ORDER BY p.ovr DESC, p.name");
            $stL->execute([$ligaCap]);
            $lendas = [];
            foreach ($stL->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $lendas[] = [
                    'id' => (int)$r['id'],
                    'name' => $r['name'],
                    'ovr' => (int)$r['ovr'],
                    'age' => $r['age'] !== null ? (int)$r['age'] : null,
                    'time' => $r['time_nome'],
                    'salario' => getPlayerBaseSalary(['ovr' => (int)$r['ovr'], 'is_lenda' => 1]),
                    'acima_do_piso' => capOvrSalary((int)$r['ovr']) > CAP_LENDA_MINIMO_MILLIONS,
                ];
            }

            $stTimes = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE league = ?');
            $stTimes->execute([$ligaCap]);

            // A folha de cada time. Vem junto porque a pergunta que leva alguém
            // a abrir esta tela quase nunca para na distribuição por OVR: é
            // "quem está estourando o cap", e sem isso a resposta exigia abrir
            // time por time.
            //
            // Só quando pedido (?times=1): getTeamCapSummary faz várias
            // consultas por time, e a tabela por OVR sozinha é bem mais barata.
            $times = [];
            $calouros = [];
            $jovens = [];
            if (!empty($_GET['times'])) {
                $stT = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(city,''),' ',COALESCE(name,''))) AS nome
                                      FROM teams WHERE league = ? ORDER BY city, name");
                $stT->execute([$ligaCap]);
                // A pick de cada calouro — o número que explica o salário dele.
                $stPick = $pdo->prepare("SELECT draft_round, draft_pick_position FROM players WHERE id = ?");

                foreach ($stT->fetchAll(PDO::FETCH_ASSOC) as $t) {
                    try {
                        $s = getTeamCapSummary($pdo, (int)$t['id']);
                        $naEscala = array_values(array_filter($s['roster'] ?? [], fn($p) => !empty($p['is_rookie_scale'])));

                        $times[] = [
                            'id'         => (int)$t['id'],
                            'nome'       => $t['nome'],
                            'jogadores'  => count($s['roster'] ?? []),
                            'folha'      => (int)($s['payroll'] ?? 0),
                            'cap_max'    => (int)($s['cap_max'] ?? 0),
                            'espaco'     => (int)($s['space'] ?? 0),
                            'status'     => $s['status'] ?? null,
                            'calouros'   => count($naEscala),
                        ];

                        // Quanto a rookie scale está custando ou economizando.
                        // O número existe no sistema desde sempre, mas em
                        // lugar nenhum ele é MOSTRADO: dá pra ver o salário do
                        // calouro e dá pra ver a tabela por OVR, mas ninguém
                        // faz a subtração — que é justamente a que diz se a
                        // pick foi boa.
                        foreach ($naEscala as $p) {
                            $stPick->execute([(int)$p['id']]);
                            $pk = $stPick->fetch(PDO::FETCH_ASSOC) ?: [];
                            $pelaTabela = capOvrSalary((int)$p['ovr']);
                            $calouros[] = [
                                'id'          => (int)$p['id'],
                                'name'        => $p['name'],
                                'time'        => $t['nome'],
                                'ovr'         => (int)$p['ovr'],
                                'round'       => $pk['draft_round'] !== null ? (int)$pk['draft_round'] : null,
                                'pick'        => $pk['draft_pick_position'] !== null ? (int)$pk['draft_pick_position'] : null,
                                'paga'        => (int)$p['base_salary'],
                                'pela_tabela' => $pelaTabela,
                                // Positivo = a escala está saindo mais barata
                                // que o OVR dele. Negativo = pagando caro.
                                'economia'    => $pelaTabela - (int)$p['base_salary'],
                            ];
                        }
                    } catch (Throwable $e) {
                        error_log('[cap_tabela] time ' . $t['id'] . ': ' . $e->getMessage());
                    }
                }
                usort($calouros, fn($a, $b) => $b['economia'] <=> $a['economia']);

                /**
                 * Os jovens que vieram das 4 primeiras rodadas do Draft Inicial.
                 *
                 * Eles NÃO estão na rookie scale — o draft inicial não é draft de
                 * calouro, e o sistema limpa a rodada deles justamente por isso.
                 * Mas são a mesma pergunta: qual é o time jovem que a liga tem, e
                 * quanto ele custa. Sem isto, a única safra visível é a do draft
                 * anual, que numa liga nova é pequena ou nem existe.
                 *
                 * A rodada sai do initdraft_order, que aponta pro pool pelo id —
                 * casar por nome (o único caminho pela tabela players) erraria com
                 * jogador renomeado depois.
                 *
                 * Idade e OVR são os de HOJE quando dá pra achar o jogador no
                 * elenco; do pool quando não dá. O filtro é sobre o valor de hoje:
                 * a pergunta é quem é jovem AGORA, não quem era na estreia.
                 */
                try {
                    // Subconsulta com WHERE por fora, não HAVING: sem GROUP BY,
                    // o HAVING depende do modo do servidor, e o daqui (MariaDB)
                    // não é o da hospedagem (MySQL). Filtrando na consulta de
                    // fora, o resultado é o mesmo nos dois.
                    $stJ = $pdo->prepare(str_replace(
                        ['{IDADE_MIN}', '{IDADE_MAX}', '{OVR_MIN}', '{RODADAS}'],
                        [JOVENS_IDADE_MIN, JOVENS_IDADE_MAX, JOVENS_OVR_MIN, JOVENS_RODADAS],
                        "
                        SELECT * FROM (
                            SELECT p.id, ip.name, io.round, io.pick_position,
                                   p.age AS idade, p.ovr AS ovr,
                                   TRIM(CONCAT(COALESCE(tp.city,''),' ',COALESCE(tp.name,''))) AS time
                            FROM initdraft_order io
                            JOIN initdraft_sessions s ON s.id = io.initdraft_session_id
                            JOIN initdraft_pool ip     ON ip.id = io.picked_player_id
                            -- Casa com quem está na LIGA hoje, não com o elenco
                            -- de quem draftou: jogador trocado continua sendo da
                            -- liga, e é a liga que a lista descreve. De quebra
                            -- ele aparece com o time e os números de agora.
                            JOIN players p             ON p.name = ip.name
                            JOIN teams tp              ON tp.id = p.team_id AND tp.league = s.league
                            WHERE s.league = ? AND io.round <= {RODADAS} AND io.picked_player_id IS NOT NULL
                        ) q
                        WHERE q.idade BETWEEN {IDADE_MIN} AND {IDADE_MAX} AND q.ovr >= {OVR_MIN}
                        ORDER BY q.ovr DESC, q.idade ASC
                    "));
                    $stJ->execute([$ligaCap]);
                    $vistos = [];
                    foreach ($stJ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        // Confere de novo em PHP, com as MESMAS constantes. O
                        // critério é o que a tela promete em texto; se o banco
                        // deixar passar algo, quem aparece ainda bate com o que
                        // está escrito.
                        $idade = (int)$r['idade'];
                        $ovrAtual = (int)$r['ovr'];
                        if ($idade < JOVENS_IDADE_MIN || $idade > JOVENS_IDADE_MAX
                            || $ovrAtual < JOVENS_OVR_MIN) continue;

                        // Dois jogadores de mesmo nome na liga dariam a mesma
                        // linha duas vezes — o casamento é por nome, é o que
                        // existe depois que a migração limpou as colunas de
                        // draft desses jogadores.
                        if (isset($vistos[(int)$r['id']])) continue;
                        $vistos[(int)$r['id']] = true;

                        // O que ele custa DE VERDADE: a tabela por OVR ou o
                        // piso do draft inicial, o que for maior. Mostrar só a
                        // tabela aqui faria esta lista discordar da folha do
                        // time logo acima, na mesma tela.
                        $peloOvr = capOvrSalary((int)$r['ovr']);
                        $pisoDele = capPisoDraftInicial([
                            'initdraft_round' => (int)$r['round'],
                            'ovr'             => (int)$r['ovr'],
                            'age'             => $idade,
                        ]);

                        $jovens[] = [
                            'name'      => $r['name'],
                            'time'      => $r['time'],
                            'round'     => (int)$r['round'],
                            'pick'      => (int)$r['pick_position'],
                            'idade'     => (int)$r['idade'],
                            'ovr'       => (int)$r['ovr'],
                            'salario'   => max($peloOvr, $pisoDele),
                            // Pra tela poder dizer QUEM está no piso: sem isso,
                            // um 76 aparecendo com 10M parece erro de tabela.
                            'piso'      => $pisoDele > $peloOvr ? $pisoDele : 0,
                        ];
                    }
                } catch (Throwable $e) {
                    // Liga que nunca teve draft inicial não tem as tabelas.
                    error_log('[cap_tabela] jovens do draft inicial: ' . $e->getMessage());
                }
            }

            echo json_encode([
                'success' => true,
                'league' => $ligaCap,
                'linhas' => $linhas,
                'lendas' => $lendas,
                'times' => $times,
                'calouros' => $calouros,
                'jovens' => $jovens,
                // A tela escreve o critério em texto; que ele venha daqui.
                'jovens_criterio' => [
                    'idade_min' => JOVENS_IDADE_MIN,
                    'idade_max' => JOVENS_IDADE_MAX,
                    'ovr_min'   => JOVENS_OVR_MIN,
                    'rodadas'   => JOVENS_RODADAS,
                ],
                'total_jogadores' => array_sum($porOvr),
                'total_times' => (int)$stTimes->fetchColumn(),
                'lenda_minimo' => CAP_LENDA_MINIMO_MILLIONS,
                'cap_base' => $capBaseFloorAdmin['base'],
                'cap_piso' => $capBaseFloorAdmin['floor'],
            ]);
            exit;

        case 'maintenance_status':
            if (!$isGlobalAdminApi) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Apenas admin geral']); exit; }
            ensureMaintenanceModeTable($pdo);
            $stmtMaint = $pdo->query("SELECT m.enabled, m.message, m.enabled_at, u.name AS enabled_by_name
                                       FROM maintenance_mode m LEFT JOIN users u ON u.id = m.enabled_by
                                       WHERE m.id = 1 LIMIT 1");
            $maintRow = $stmtMaint ? $stmtMaint->fetch(PDO::FETCH_ASSOC) : null;
            echo json_encode([
                'success' => true,
                'enabled' => (bool)($maintRow['enabled'] ?? false),
                'message' => $maintRow['message'] ?? null,
                'enabled_at' => $maintRow['enabled_at'] ?? null,
                'enabled_by_name' => $maintRow['enabled_by_name'] ?? null,
            ]);
            break;

        case 'league_invite':
            // Link de convite reutilizável da liga. Hoje só a ROOKIE: o cadastro
            // por convite monta o time escolhendo uma franquia real da NBA, e
            // esse fluxo é exclusivo dela.
            require_once __DIR__ . '/../backend/nba_teams.php';
            $ligaConvite = strtoupper(trim((string)($_GET['league'] ?? 'ROOKIE')));
            if ($ligaConvite !== 'ROOKIE') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Convite reutilizável só existe na ROOKIE.']);
                exit;
            }
            if (!in_array($ligaConvite, $apiAdminLeagues, true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Sem acesso a essa liga']);
                exit;
            }
            ensureLeagueInviteColumn($pdo);
            $stmtInv = $pdo->prepare('SELECT invite_token FROM league_settings WHERE league = ? LIMIT 1');
            $stmtInv->execute([$ligaConvite]);
            $tokenConvite = $stmtInv->fetchColumn() ?: null;
            echo json_encode([
                'success' => true,
                'league'  => $ligaConvite,
                'token'   => $tokenConvite,
            ]);
            break;

        case 'get_users':
            // Gestão de usuários é exclusiva do Admin Geral (não interfere no admin de liga)
            if (!$isGlobalAdminApi) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Apenas admin geral']); exit; }
            $leagueFilter = strtoupper(trim((string)($_GET['league'] ?? '')));
            $allowedLeagues = $isGlobalAdminApi ? $validLeagues : $apiAdminLeagues;

            $params = [];
            $where  = '';
            if ($leagueFilter && in_array($leagueFilter, $allowedLeagues, true)) {
                $where    = 'WHERE u.league = ?';
                $params[] = $leagueFilter;
            } elseif (!$isGlobalAdminApi) {
                $ph    = implode(',', array_fill(0, count($allowedLeagues), '?'));
                $where = "WHERE u.league IN ($ph)";
                $params = $allowedLeagues;
            }

            $stmt = $pdo->prepare("
                SELECT u.id, u.name, u.email, u.user_type, u.photo_url, u.league, u.phone,
                       t.id AS team_id, t.name AS team_name, t.city AS team_city,
                       t.photo_url AS team_photo, t.league AS team_league
                FROM users u
                LEFT JOIN teams t ON t.user_id = u.id
                $where
                ORDER BY u.league, u.name
            ");
            $stmt->execute($params);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($users as &$row) {
                $stmtAL = $pdo->prepare("SELECT league FROM league_admins WHERE user_id = ?");
                $stmtAL->execute([$row['id']]);
                $row['admin_leagues'] = $stmtAL->fetchAll(PDO::FETCH_COLUMN);
                // O número é guardado só em dígitos (5511987654321) porque é
                // assim que o bot casa a menção. Na tela ele vai formatado,
                // com o veredito de se o WhatsApp consegue reconhecer.
                $row['phone_formatado'] = formatBrazilianPhone($row['phone'] ?? null);
                $row['phone_check'] = !empty($row['phone'])
                    ? whatsappNumeroUsavel($row['phone'])
                    : null;
            }
            unset($row);

            echo json_encode(['success' => true, 'users' => $users]);
            break;

        case 'copy_rosters':
            $league = strtoupper(trim((string)($_GET['league'] ?? '')));
            if (!in_array($league, $validLeagues, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga inválida']);
                break;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $league);

            $stmtTeams = $pdo->prepare('SELECT t.id, t.city, t.name, u.name AS owner_name FROM teams t LEFT JOIN users u ON t.user_id = u.id WHERE t.league = ? ORDER BY t.city, t.name');
            $stmtTeams->execute([$league]);
            $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);
            if (!$teams) {
                echo json_encode(['success' => true, 'text' => 'Nenhum time encontrado.']);
                break;
            }

            $teamIds = array_map(static function ($row) {
                return (int)$row['id'];
            }, $teams);
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
            $playerOvr = playerOvrColumn($pdo);
            $stmtPlayers = $pdo->prepare(
                'SELECT id, team_id, name, position, age, role, ' . $playerOvr . ' AS ovr
                 FROM players
                 WHERE team_id IN (' . $placeholders . ')
                 ORDER BY team_id,
                   CASE role
                     WHEN "Titular" THEN 1
                     WHEN "Banco" THEN 2
                     WHEN "Outro" THEN 3
                     WHEN "G-League" THEN 4
                     WHEN "G-League" THEN 4
                     ELSE 5
                   END,
                   ' . $playerOvr . ' DESC,
                   name ASC'
            );
            $stmtPlayers->execute($teamIds);
            $players = $stmtPlayers->fetchAll(PDO::FETCH_ASSOC);

            $playersByTeam = [];
            foreach ($players as $player) {
                $playersByTeam[(int)$player['team_id']][] = $player;
            }

            $lines = [];
            foreach ($teams as $team) {
                $teamName = trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? ''));
                $lines[] = '*' . $teamName . '*';
                $lines[] = 'GM: ' . ($team['owner_name'] ?? '-');
                $roster = $playersByTeam[(int)$team['id']] ?? [];
                if (!$roster) {
                    $lines[] = '- Sem jogadores';
                } else {
                    // Salário por jogador (só ELITE): fora dela o mapa vem vazio.
                    $salarios = capSalariosDoTime($pdo, (int)$team['id'], $league);
                    $linha = function (array $player) use ($salarios): string {
                        $base = sprintf('- %s | %s | OVR %s | %s anos', $player['position'], $player['name'],
                                        $player['ovr'] ?? '-', $player['age'] ?? '-');
                        $sal = $salarios[(int)($player['id'] ?? 0)] ?? null;
                        return $sal === null ? $base : $base . ' | ' . $sal . 'M';
                    };
                    $main    = array_values(array_filter($roster, fn($p) => ($p['role'] ?? '') !== 'G-League'));
                    $gleague = array_values(array_filter($roster, fn($p) => ($p['role'] ?? '') === 'G-League'));
                    foreach ($main as $player) {
                        $lines[] = $linha($player);
                    }
                    if ($gleague) {
                        $lines[] = '*G-League*';
                        foreach ($gleague as $player) {
                            $lines[] = $linha($player);
                        }
                    }
                    if ($salarios) {
                        $lines[] = 'Folha: ' . array_sum($salarios) . 'M';
                    }
                }
                $lines[] = '';
            }

            echo json_encode(['success' => true, 'text' => trim(implode("\n", $lines))]);
            break;

        case 'copy_picks':
            $league = strtoupper(trim((string)($_GET['league'] ?? '')));
            if (!in_array($league, $validLeagues, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga inválida']);
                break;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $league);

            $stmtTeams = $pdo->prepare('SELECT t.id, t.city, t.name FROM teams t WHERE t.league = ? ORDER BY t.city, t.name');
            $stmtTeams->execute([$league]);
            $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);
            if (!$teams) {
                echo json_encode(['success' => true, 'text' => 'Nenhum time encontrado.']);
                break;
            }

            $teamIds = array_map(static fn($r) => (int)$r['id'], $teams);
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));

            // Busca o ano da temporada atual da liga
            $currentYear = (int)date('Y');
            try {
                $stmtSY = $pdo->prepare('
                    SELECT COALESCE(sp.start_year + s.season_number - 1, s.year) AS yr
                    FROM seasons s
                    LEFT JOIN sprints sp ON s.sprint_id = sp.id
                    WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN (\'completed\'))
                    ORDER BY s.created_at DESC LIMIT 1
                ');
                $stmtSY->execute([$league]);
                $yr = (int)($stmtSY->fetchColumn() ?: 0);
                if ($yr > 0) $currentYear = $yr;
            } catch (Exception $e) {}

            $stmtPicks = $pdo->prepare("
                SELECT p.team_id, p.season_year, p.original_team_id,
                       orig.city AS orig_city, orig.name AS orig_name
                FROM picks p
                LEFT JOIN teams orig ON p.original_team_id = orig.id
                WHERE p.team_id IN ($placeholders)
                  AND p.round = 1
                  AND p.season_year >= ?
                ORDER BY p.team_id, p.season_year
            ");
            $stmtPicks->execute([...$teamIds, $currentYear]);
            $picksRaw = $stmtPicks->fetchAll(PDO::FETCH_ASSOC);

            $picksByTeam = [];
            foreach ($picksRaw as $pk) {
                $picksByTeam[(int)$pk['team_id']][] = $pk;
            }

            $lines = [];
            foreach ($teams as $team) {
                $teamName = trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? ''));
                $lines[] = '*' . $teamName . '*';
                $teamPicks = $picksByTeam[(int)$team['id']] ?? [];
                if (!$teamPicks) {
                    $lines[] = '- Sem picks de 1ª rodada';
                } else {
                    // Só picks de 1ª entram nesta lista, então o peso é o mesmo em
                    // todas as linhas: vai no total, não repetido em cada uma.
                    $peso = strtoupper($league) === 'ELITE' && capLigaUsaSalario($pdo, $league)
                          ? capValorDaPickNaTroca(1) : 0;
                    foreach ($teamPicks as $pk) {
                        $traded = (int)$pk['original_team_id'] !== (int)$team['id'];
                        $orig = $traded ? trim(($pk['orig_city'] ?? '') . ' ' . ($pk['orig_name'] ?? '')) : '';
                        $lines[] = '- ' . $pk['season_year'] . ($traded ? ' (de ' . $orig . ')' : '');
                    }
                    if ($peso > 0) {
                        $lines[] = 'Peso na troca: ' . ($peso * count($teamPicks)) . 'M (' . $peso . 'M por pick de 1ª)';
                    }
                }
                $lines[] = '';
            }

            echo json_encode(['success' => true, 'text' => trim(implode("\n", $lines))]);
            break;

        case 'games_users':
            // Perfis de jogo (aba Games do Admin): saldo de moedas/FBA Points
            // e quem é admin do Games.
            ensureGamesSchema($pdo);
            if (!hasGamesAdminAccess($pdo, (int)$user['id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Sem acesso ao admin do Games']);
                exit;
            }
            $busca = trim((string)($_GET['q'] ?? ''));
            $sqlGU = "
                SELECT u.id, u.name, u.email, u.league, u.user_type,
                       COALESCE(g.pontos, 0) AS pontos,
                       COALESCE(g.fba_points, 0) AS fba_points,
                       COALESCE(g.acertos_eventos, 0) AS acertos_eventos,
                       COALESCE(g.is_admin, 0) AS games_admin,
                       (g.id IS NOT NULL) AS tem_perfil
                FROM users u
                LEFT JOIN games_usuarios g ON g.id = u.id
            ";
            $paramsGU = [];
            if ($busca !== '') {
                $sqlGU .= " WHERE u.name LIKE ? OR u.email LIKE ? ";
                $paramsGU[] = '%' . $busca . '%';
                $paramsGU[] = '%' . $busca . '%';
            }
            $sqlGU .= " ORDER BY u.name ASC";
            $stmtGU = $pdo->prepare($sqlGU);
            $stmtGU->execute($paramsGU);
            echo json_encode(['success' => true, 'users' => $stmtGU->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // Preenche nba_player_id dos jogadores sem foto (draft, FA, cadastro
        // direto). Roda todo dia sozinho pelo cron; isto aqui é o botão em
        // Gestão pra rodar na hora, sem esperar. Não é por liga — toca o
        // cadastro inteiro — então só admin global mesmo.
        // Manda o abraço do dia na hora, sem esperar as 15h. Mesma função do
        // cron, com forçar=true: ignora o horário e a marca do dia, porque quem
        // clicou quer agora, mesmo que já tenha saído hoje.
        case 'disparar_abraco': {
            if (!$isGlobalAdminApi) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores globais.']);
                break;
            }
            require_once __DIR__ . '/../backend/whatsapp_abraco.php';
            $r = enviarAbracoDoDia($pdo, true);

            if (!$r['enviado']) {
                $motivos = [
                    'sem_grupo'      => 'O grupo principal do WhatsApp não está configurado.',
                    'sem_candidatos' => 'Nenhum GM com time pra sortear.',
                    'bot_desligado'  => 'O bot do WhatsApp está desligado — a mensagem não entrou na fila.',
                ];
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => $motivos[$r['motivo']] ?? $r['motivo']]);
                break;
            }
            echo json_encode([
                'success' => true,
                'nome' => $r['nome'],
                'time' => $r['time'],
                'com_mencao' => $r['com_mencao'],
            ]);
            break;
        }

        case 'sync_fotos':
            if (!$isGlobalAdminApi) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas administradores globais.']);
                break;
            }
            $r = syncNbaPlayerPhotos($pdo);
            if (!$r['ok']) {
                http_response_code(502);
                echo json_encode(['success' => false, 'error' => $r['erro']]);
                break;
            }
            echo json_encode([
                'success' => true,
                'atualizados' => $r['atualizados'],
                'total_verificados' => $r['total_verificados'],
                'sem_correspondencia' => $r['sem_correspondencia'],
            ]);
            break;

        case 'leagues':
            // Listar todas as ligas com configurações
            $stmtLeagues = $pdo->query("SELECT name FROM leagues ORDER BY FIELD(name,'ELITE','NEXT','RISE','ROOKIE')");
            $leagues = $stmtLeagues->fetchAll(PDO::FETCH_COLUMN);
            if (!$isGlobalAdminApi) {
                $leagues = array_values(array_intersect($leagues, $apiAdminLeagues));
            }

            $result = [];
            foreach ($leagues as $league) {
                if (!columnExists($pdo, 'league_settings', 'cap_flex_a_partir_da_temporada')) {
                    try { $pdo->exec("ALTER TABLE league_settings ADD COLUMN cap_flex_a_partir_da_temporada INT NULL"); } catch (Exception $e) {}
                }
                $stmtCfg = $pdo->prepare('SELECT cap_min, cap_max, cap_mode, max_trades, edital, trades_enabled, fa_enabled, COALESCE(n8n_webhook_url, \'\') as n8n_webhook_url, COALESCE(progression_video_url, \'\') as progression_video_url, COALESCE(sistemas_video_url, \'\') as sistemas_video_url, COALESCE(freeagency_video_url, \'\') as freeagency_video_url, cap_auto_last_season, cap_auto_margin, cap_auto_margin_pct, cap_flex_a_partir_da_temporada FROM league_settings WHERE league = ?');
                $stmtCfg->execute([$league]);
                $cfg = $stmtCfg->fetch() ?: ['cap_min' => 0, 'cap_max' => 0, 'cap_mode' => 'ovr_sum', 'max_trades' => 3, 'edital' => null, 'trades_enabled' => 1, 'fa_enabled' => 1, 'n8n_webhook_url' => '', 'progression_video_url' => '', 'sistemas_video_url' => '', 'freeagency_video_url' => '', 'cap_auto_last_season' => null, 'cap_auto_margin' => LEAGUE_CAP_DEFAULT_OVR_MARGIN, 'cap_auto_margin_pct' => LEAGUE_CAP_DEFAULT_SALARY_MARGIN];

                $stmtSprintCfg = $pdo->prepare('SELECT max_seasons FROM league_sprint_config WHERE league = ?');
                $stmtSprintCfg->execute([$league]);
                $maxSeasons = (int)($stmtSprintCfg->fetchColumn() ?: 0);

                $stmtTeams = $pdo->prepare('SELECT COUNT(*) as total FROM teams WHERE league = ?');
                $stmtTeams->execute([$league]);
                $teamCount = $stmtTeams->fetch()['total'];

                $result[] = [
                    'league' => $league,
                    'cap_min' => (int)$cfg['cap_min'],
                    'cap_max' => (int)$cfg['cap_max'],
                    'max_trades' => (int)$cfg['max_trades'],
                    'max_seasons' => $maxSeasons,
                    'edital' => $cfg['edital'],
                    'edital_file' => $cfg['edital'],
                    'trades_enabled' => (int)($cfg['trades_enabled'] ?? 1),
                    'fa_enabled' => (int)($cfg['fa_enabled'] ?? 1),
                    'n8n_webhook_url' => $cfg['n8n_webhook_url'] ?? '',
                    'progression_video_url' => $cfg['progression_video_url'] ?? '',
                    'sistemas_video_url' => $cfg['sistemas_video_url'] ?? '',
                    'freeagency_video_url' => $cfg['freeagency_video_url'] ?? '',
                    'cap_mode' => $cfg['cap_mode'] ?? 'ovr_sum',
                    'cap_auto_last_season' => $cfg['cap_auto_last_season'] !== null ? (int)$cfg['cap_auto_last_season'] : null,
                    'cap_auto_margin' => (int)($cfg['cap_auto_margin'] ?? LEAGUE_CAP_DEFAULT_OVR_MARGIN),
                    'cap_auto_margin_pct' => (int)($cfg['cap_auto_margin_pct'] ?? LEAGUE_CAP_DEFAULT_SALARY_MARGIN),
                    'cap_flex_a_partir_da_temporada' => isset($cfg['cap_flex_a_partir_da_temporada']) && $cfg['cap_flex_a_partir_da_temporada'] !== null
                        ? (int)$cfg['cap_flex_a_partir_da_temporada'] : null,
                    'team_count' => (int)$teamCount
                ];
            }

            echo json_encode(['success' => true, 'leagues' => $result]);
            break;

        case 'cap_history':
            // Histórico dos recálculos automáticos de CAP de uma liga (mais recentes primeiro)
            $league = strtoupper(trim($_GET['league'] ?? ''));
            if (!in_array($league, $validLeagues, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga inválida']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $league);
            $stmtHist = $pdo->prepare('SELECT season_number, cap_mode, avg_value, margin, cap_min, cap_max, teams_total, teams_above, teams_below, created_at
                                        FROM league_cap_history WHERE league = ? ORDER BY id DESC LIMIT 10');
            $stmtHist->execute([$league]);
            echo json_encode(['success' => true, 'history' => $stmtHist->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'nba_teams':
            // Os 30 times da NBA + quais já foram escolhidos, pro seletor de "Criar GM" na ROOKIE.
            require_once dirname(__DIR__) . '/backend/nba_teams.php';
            echo json_encode(['success' => true, 'teams' => nbaTeams(), 'taken' => nbaTeamsTaken($pdo)]);
            break;

        case 'teams':
            // Listar todos os times com detalhes
            $league = $_GET['league'] ?? null;
            
            $query = "
                SELECT
                    t.id, t.city, t.name, t.mascot, t.league, t.conference, t.photo_url,
                    COALESCE(t.tapas, 0) as tapas,
                    COALESCE(t.trades_used, 0) as trades_used,
                    COALESCE(t.waivers_used, 0) as waivers_used,
                    u.name as owner_name, u.email as owner_email,
                    d.name as division_name,
                    (SELECT COUNT(*) FROM players WHERE team_id = t.id) as player_count,
                    (SELECT COUNT(*) FROM team_punishments tp WHERE tp.team_id = t.id AND tp.type = 'AVISO_TRADE' AND tp.reverted_at IS NULL) as avisos_count
                FROM teams t
                JOIN users u ON t.user_id = u.id
                LEFT JOIN divisions d ON t.division_id = d.id
            ";
            
            if ($league) {
                requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $league);
                $query .= " WHERE t.league = ? ORDER BY t.city, t.name";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$league]);
            } elseif (!$isGlobalAdminApi) {
                $ph = implode(',', array_fill(0, count($apiAdminLeagues), '?'));
                $query .= " WHERE t.league IN ($ph) ORDER BY FIELD(t.league,'ELITE','NEXT','RISE','ROOKIE'), t.city, t.name";
                $stmt = $pdo->prepare($query);
                $stmt->execute($apiAdminLeagues);
            } else {
                $query .= " ORDER BY FIELD(t.league,'ELITE','NEXT','RISE','ROOKIE'), t.city, t.name";
                $stmt = $pdo->query($query);
            }

            $teams = [];
            while ($team = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $capTop8 = topOvrCap($pdo, $team['id']);
                $team['cap_top8'] = $capTop8;
                $team['restricted_eligible'] = restrictedEligibleCount($pdo, (int)$team['id']);
                $team['restricted_bonus'] = restrictedCapBonus($pdo, (int)$team['id']);
                $teams[] = $team;
            }

            echo json_encode(['success' => true, 'teams' => $teams]);
            break;

        case 'search_players':
            $league = $_GET['league'] ?? null;
            $query = trim((string)($_GET['query'] ?? ''));

            if (!$league || $query === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga e busca obrigatorias']);
                break;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $league);

            $ovrCol = playerOvrColumn($pdo);
            $stmt = $pdo->prepare("SELECT p.id, p.name, p.position, p.age, p.{$ovrCol} as ovr,
                t.city as team_city, t.name as team_name
                FROM players p
                JOIN teams t ON p.team_id = t.id
                WHERE t.league = ? AND p.name LIKE ?
                ORDER BY p.{$ovrCol} DESC, p.name ASC
                LIMIT 50");
            $stmt->execute([$league, '%' . $query . '%']);
            $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'players' => $players]);
            break;

        case 'team_details':
            // Detalhes completos de um time específico
            $teamId = $_GET['team_id'] ?? null;
            if (!$teamId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Team ID obrigatório']);
                exit;
            }

            $stmtTeam = $pdo->prepare("
                SELECT 
                    t.*, 
                    u.name as owner_name, u.email as owner_email,
                    d.name as division_name
                FROM teams t
                JOIN users u ON t.user_id = u.id
                LEFT JOIN divisions d ON t.division_id = d.id
                WHERE t.id = ?
            ");
            $stmtTeam->execute([$teamId]);
            $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);

            if (!$team) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $team['league'] ?? null);

            // Buscar jogadores
            $stmtPlayers = $pdo->prepare("
                SELECT * FROM players
                WHERE team_id = ?
                ORDER BY ovr DESC, role, name
            ");
            $stmtPlayers->execute([$teamId]);
            $team['players'] = $stmtPlayers->fetchAll(PDO::FETCH_ASSOC);
            markLoyaltyEligibility($pdo, $team['players']); // is_loyal — pro checkbox "Leal" na edição já vir marcado certo

            // Buscar picks
            $stmtPicks = $pdo->prepare("
                SELECT p.*, t.city, t.name as team_name,
                       tp.city as swap_partner_city, tp.name as swap_partner_name
                FROM picks p
                JOIN teams t ON p.original_team_id = t.id
                LEFT JOIN picks pp ON pp.id = p.swap_pair_pick_id
                LEFT JOIN teams tp ON tp.id = pp.original_team_id
                WHERE p.team_id = ?
                ORDER BY p.season_year, p.round
            ");
            $stmtPicks->execute([$teamId]);
            $team['picks'] = $stmtPicks->fetchAll(PDO::FETCH_ASSOC);

            $team['cap_top8'] = topOvrCap($pdo, $teamId);
            $team['restricted_eligible'] = restrictedEligibleCount($pdo, (int)$teamId);
            $team['restricted_bonus'] = $team['restricted_eligible'] * 2;

            echo json_encode(['success' => true, 'team' => $team]);
            break;

        case 'tapas':
            // Listar times com tapas
            $league = isset($_GET['league']) ? strtoupper($_GET['league']) : null;

            $query = "
                SELECT 
                    t.id, t.city, t.name, t.league,
                    COALESCE(t.tapas, 0) as tapas,
                    COALESCE(t.tapas_used, 0) as tapas_used,
                    u.name as owner_name
                FROM teams t
                JOIN users u ON t.user_id = u.id
            ";

            if ($league && in_array($league, $validLeagues, true)) {
                $query .= " WHERE t.league = ? ORDER BY t.tapas DESC, t.city, t.name";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$league]);
            } else {
                $query .= " ORDER BY FIELD(t.league,'ELITE','NEXT','RISE','ROOKIE'), t.tapas DESC, t.city, t.name";
                $stmt = $pdo->query($query);
            }

            $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'teams' => $teams, 'league' => $league]);
            break;

        case 'trades':
            // Listar todas as trades
            $status = $_GET['status'] ?? 'all'; // all, pending, accepted, rejected, cancelled
            $league = $_GET['league'] ?? null;
            $seasonYear = $_GET['season_year'] ?? null;
            $teamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
            
            $conditions = [];
            $params = [];
            
            if ($status !== 'all') {
                $conditions[] = "t.status = ?";
                $params[] = $status;
            }
            
            if ($league) {
                requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $league);
                $conditions[] = "from_team.league = ?";
                $params[] = $league;
            } elseif (!$isGlobalAdminApi) {
                $ph = implode(',', array_fill(0, count($apiAdminLeagues), '?'));
                $conditions[] = "from_team.league IN ($ph)";
                foreach ($apiAdminLeagues as $al) { $params[] = $al; }
            }

            if ($teamId > 0) {
                $conditions[] = '(t.from_team_id = ? OR t.to_team_id = ?)';
                $params[] = $teamId;
                $params[] = $teamId;
            }

            if ($seasonYear) {
                $conditions[] = "YEAR(t.created_at) = ?";
                $params[] = (int)$seasonYear;
            }
            
            $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
            
            $query = "
                SELECT 
                    t.*,
                    from_team.city as from_city,
                    from_team.name as from_name,
                    from_team.league as from_league,
                    to_team.city as to_city,
                    to_team.name as to_name,
                    to_team.league as to_league
                FROM trades t
                JOIN teams from_team ON t.from_team_id = from_team.id
                JOIN teams to_team ON t.to_team_id = to_team.id
                $whereClause
                ORDER BY t.created_at DESC
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Para cada trade, buscar itens
            foreach ($trades as &$trade) {
                $ovrCol = playerOvrColumn($pdo);
                $stmtOfferPlayers = $pdo->prepare("SELECT 
                    COALESCE(p.id, ti.player_id) AS id,
                    COALESCE(p.name, ti.player_name, CONCAT('Jogador #', ti.player_id)) AS name,
                    COALESCE(p.position, ti.player_position) AS position,
                    COALESCE(p.age, ti.player_age) AS age,
                    COALESCE(p.{$ovrCol}, ti.player_ovr) AS ovr
                 FROM trade_items ti
                 LEFT JOIN players p ON p.id = ti.player_id
                 WHERE ti.trade_id = ? AND ti.from_team = TRUE AND ti.pick_id IS NULL");
                $stmtOfferPlayers->execute([$trade['id']]);
                $trade['offer_players'] = $stmtOfferPlayers->fetchAll(PDO::FETCH_ASSOC);
                
                $stmtOfferPicks = $pdo->prepare('
                    SELECT pk.*, t.city, t.name as team_name FROM picks pk
                    JOIN trade_items ti ON pk.id = ti.pick_id
                    JOIN teams t ON pk.original_team_id = t.id
                    WHERE ti.trade_id = ? AND ti.from_team = TRUE AND ti.pick_id IS NOT NULL
                ');
                $stmtOfferPicks->execute([$trade['id']]);
                $trade['offer_picks'] = $stmtOfferPicks->fetchAll(PDO::FETCH_ASSOC);
                
                $stmtRequestPlayers = $pdo->prepare("SELECT 
                    COALESCE(p.id, ti.player_id) AS id,
                    COALESCE(p.name, ti.player_name, CONCAT('Jogador #', ti.player_id)) AS name,
                    COALESCE(p.position, ti.player_position) AS position,
                    COALESCE(p.age, ti.player_age) AS age,
                    COALESCE(p.{$ovrCol}, ti.player_ovr) AS ovr
                 FROM trade_items ti
                 LEFT JOIN players p ON p.id = ti.player_id
                 WHERE ti.trade_id = ? AND ti.from_team = FALSE AND ti.pick_id IS NULL");
                $stmtRequestPlayers->execute([$trade['id']]);
                $trade['request_players'] = $stmtRequestPlayers->fetchAll(PDO::FETCH_ASSOC);
                
                $stmtRequestPicks = $pdo->prepare('
                    SELECT pk.*, t.city, t.name as team_name FROM picks pk
                    JOIN trade_items ti ON pk.id = ti.pick_id
                    JOIN teams t ON pk.original_team_id = t.id
                    WHERE ti.trade_id = ? AND ti.from_team = FALSE AND ti.pick_id IS NOT NULL
                ');
                $stmtRequestPicks->execute([$trade['id']]);
                $trade['request_picks'] = $stmtRequestPicks->fetchAll(PDO::FETCH_ASSOC);
            }

            $multiTrades = [];
            if (tableExists($pdo, 'multi_trades') && tableExists($pdo, 'multi_trade_items') && tableExists($pdo, 'multi_trade_teams')) {
                $multiConditions = [];
                $multiParams = [];

                if ($status !== 'all') {
                    if ($status === 'rejected') {
                        $multiConditions[] = '1 = 0';
                    } else {
                        $multiConditions[] = 'mt.status = ?';
                        $multiParams[] = $status;
                    }
                }

                if ($league) {
                    $multiConditions[] = 'COALESCE(mt.league, creator.league) = ?';
                    $multiParams[] = $league;
                } elseif (!$isGlobalAdminApi) {
                    $ph = implode(',', array_fill(0, count($apiAdminLeagues), '?'));
                    $multiConditions[] = "COALESCE(mt.league, creator.league) IN ($ph)";
                    foreach ($apiAdminLeagues as $al) { $multiParams[] = $al; }
                }

                if ($teamId > 0) {
                    $multiConditions[] = 'EXISTS (SELECT 1 FROM multi_trade_teams mtt WHERE mtt.trade_id = mt.id AND mtt.team_id = ?)';
                    $multiParams[] = $teamId;
                }

                if ($seasonYear) {
                    $multiConditions[] = 'YEAR(mt.created_at) = ?';
                    $multiParams[] = (int)$seasonYear;
                }

                $multiWhere = !empty($multiConditions) ? 'WHERE ' . implode(' AND ', $multiConditions) : '';
                $multiQuery = "
                    SELECT
                        mt.*,
                        COALESCE(mt.league, creator.league) AS league,
                        creator.city AS creator_city,
                        creator.name AS creator_name,
                        (SELECT COUNT(*) FROM multi_trade_teams WHERE trade_id = mt.id) AS teams_total,
                        (SELECT COUNT(*) FROM multi_trade_teams WHERE trade_id = mt.id AND accepted_at IS NOT NULL) AS teams_accepted
                    FROM multi_trades mt
                    JOIN teams creator ON mt.created_by_team_id = creator.id
                    {$multiWhere}
                    ORDER BY mt.created_at DESC
                ";

                $stmtMulti = $pdo->prepare($multiQuery);
                $stmtMulti->execute($multiParams);
                $multiTrades = $stmtMulti->fetchAll(PDO::FETCH_ASSOC);

                $ovrCol = playerOvrColumn($pdo);
                $stmtTeams = $pdo->prepare('SELECT t.id, t.city, t.name FROM multi_trade_teams mtt JOIN teams t ON t.id = mtt.team_id WHERE mtt.trade_id = ?');
                $stmtItems = $pdo->prepare('SELECT * FROM multi_trade_items WHERE trade_id = ?');
                $stmtPickInfo = $pdo->prepare('SELECT pk.*, t.city as original_team_city, t.name as original_team_name, lo.city as last_owner_city, lo.name as last_owner_name FROM picks pk JOIN teams t ON pk.original_team_id = t.id LEFT JOIN teams lo ON pk.last_owner_team_id = lo.id WHERE pk.id = ?');

                foreach ($multiTrades as &$trade) {
                    $trade['is_multi'] = true;

                    $stmtTeams->execute([$trade['id']]);
                    $trade['teams'] = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

                    $stmtItems->execute([$trade['id']]);
                    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($items as &$item) {
                        if (!empty($item['player_id']) && (empty($item['player_name']) || empty($item['player_ovr']))) {
                            $stmtP = $pdo->prepare("SELECT name, position, age, {$ovrCol} AS ovr FROM players WHERE id = ?");
                            $stmtP->execute([(int)$item['player_id']]);
                            $p = $stmtP->fetch(PDO::FETCH_ASSOC) ?: [];
                            $item['player_name'] = $item['player_name'] ?: ($p['name'] ?? null);
                            $item['player_position'] = $item['player_position'] ?: ($p['position'] ?? null);
                            $item['player_age'] = $item['player_age'] ?: ($p['age'] ?? null);
                            $item['player_ovr'] = $item['player_ovr'] ?: ($p['ovr'] ?? null);
                        }
                        if (!empty($item['pick_id'])) {
                            $stmtPickInfo->execute([(int)$item['pick_id']]);
                            $pick = $stmtPickInfo->fetch(PDO::FETCH_ASSOC) ?: [];
                            $item['season_year'] = $pick['season_year'] ?? null;
                            $item['round'] = $pick['round'] ?? null;
                            $item['original_team_id'] = $pick['original_team_id'] ?? null;
                            $item['original_team_city'] = $pick['original_team_city'] ?? null;
                            $item['original_team_name'] = $pick['original_team_name'] ?? null;
                            $item['last_owner_team_id'] = $pick['last_owner_team_id'] ?? null;
                            $item['last_owner_city'] = $pick['last_owner_city'] ?? null;
                            $item['last_owner_name'] = $pick['last_owner_name'] ?? null;
                        }
                    }
                    unset($item);

                    $trade['items'] = $items;
                }
                unset($trade);
            }

            if (!empty($multiTrades)) {
                $trades = array_merge($trades, $multiTrades);
                usort($trades, static function ($a, $b) {
                    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
                });
            }

            echo json_encode(['success' => true, 'trades' => $trades]);
            break;

        // ── Draft Class Bank ─────────────────────────────────────────────────────
        case 'draft_class_bank':
            // Garantir tabelas
            $pdo->exec("CREATE TABLE IF NOT EXISTS draft_class_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_dct_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS draft_class_template_players (
                id INT AUTO_INCREMENT PRIMARY KEY,
                template_id INT NOT NULL,
                name VARCHAR(120) NOT NULL,
                position VARCHAR(20) NOT NULL,
                ovr INT NOT NULL,
                age INT NOT NULL,
                INDEX idx_dctp_tpl (template_id),
                CONSTRAINT fk_dctp_tpl FOREIGN KEY (template_id) REFERENCES draft_class_templates(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // Tabela já existia sem essa coluna em produção — CREATE TABLE IF NOT EXISTS não adiciona
            // coluna em tabela já criada, precisa do ALTER (mesmo padrão do pick_hint em draft_pool).
            try { $pdo->exec("ALTER TABLE draft_class_template_players ADD COLUMN pick_hint INT NULL"); } catch (Exception $e) {}

            if ($method === 'GET') {
                $subAction = $_GET['sub'] ?? 'list';
                if ($subAction === 'list') {
                    // O banco é por liga: classe cadastrada na ELITE é da
                    // ELITE. Sem o filtro, a aba de cada liga mostrava o bolo
                    // de todo mundo — e a roleta de uma liga podia oferecer
                    // classe que outra já tinha reservado.
                    //
                    // As que ainda não têm liga (existiam antes disso) entram
                    // junto, marcadas, pra alguém atribuir sem precisar caçá-las.
                    $ligaClasses = strtoupper(trim((string)($_GET['league'] ?? '')));
                    if ($ligaClasses !== '' && in_array($ligaClasses, $validLeagues, true)) {
                        requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $ligaClasses);
                        $stmtCls = $pdo->prepare("SELECT id, name, league, created_at,
                            (SELECT COUNT(*) FROM draft_class_template_players WHERE template_id=dct.id) AS player_count
                            FROM draft_class_templates dct
                            WHERE dct.league = ? OR dct.league IS NULL
                            ORDER BY dct.league IS NULL, created_at DESC");
                        $stmtCls->execute([$ligaClasses]);
                        $rows = $stmtCls->fetchAll(PDO::FETCH_ASSOC);
                    } else {
                        $rows = $pdo->query("SELECT id, name, league, created_at,
                            (SELECT COUNT(*) FROM draft_class_template_players WHERE template_id=dct.id) AS player_count
                            FROM draft_class_templates dct ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
                    }
                    echo json_encode(['success' => true, 'templates' => $rows]);
                } elseif ($subAction === 'players') {
                    $tplId = (int)($_GET['template_id'] ?? 0);
                    if (!$tplId) { echo json_encode(['success' => false, 'error' => 'template_id obrigatório']); break; }
                    $stmt = $pdo->prepare("SELECT id, name, position, ovr, age, pick_hint FROM draft_class_template_players WHERE template_id=? ORDER BY COALESCE(pick_hint, 999999) ASC, ovr DESC");
                    $stmt->execute([$tplId]);
                    echo json_encode(['success' => true, 'players' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                }
                break;
            }
            break;

        case 'hall_of_fame':
            // Só GET chega aqui (estamos dentro do bloco if ($method === 'GET') do topo do arquivo).
            // POST/PUT/DELETE de hall_of_fame são tratados nos cases 'hall_of_fame' correspondentes
            // de cada bloco de método, mais abaixo no arquivo.
            ensureHallOfFameTable($pdo);
            echo json_encode(['success' => true, 'groups' => getHallOfFameGrouped($pdo)]);
            break;

        case 'divisions':
            // Listar divisões por liga
            $league = $_GET['league'] ?? null;
            if (!$league) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga obrigatória']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $league);

            $stmt = $pdo->prepare("SELECT * FROM divisions WHERE league = ? ORDER BY importance DESC, name");
            $stmt->execute([$league]);
            $divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'divisions' => $divisions]);
            break;

        case 'coins':
            // Listar times com moedas
            $league = isset($_GET['league']) ? strtoupper($_GET['league']) : null;
            
            $query = "
                SELECT 
                    t.id, t.city, t.name, t.league, 
                    COALESCE(t.moedas, 0) as moedas,
                    u.name as owner_name
                FROM teams t
                JOIN users u ON t.user_id = u.id
            ";
            
            if ($league && in_array($league, $validLeagues, true)) {
                requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $league);
                $query .= " WHERE t.league = ? ORDER BY t.moedas DESC, t.city, t.name";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$league]);
            } elseif (!$isGlobalAdminApi) {
                $ph = implode(',', array_fill(0, count($apiAdminLeagues), '?'));
                $query .= " WHERE t.league IN ($ph) ORDER BY FIELD(t.league,'ELITE','NEXT','RISE','ROOKIE'), t.moedas DESC, t.city, t.name";
                $stmt = $pdo->prepare($query);
                $stmt->execute($apiAdminLeagues);
            } else {
                $query .= " ORDER BY FIELD(t.league,'ELITE','NEXT','RISE','ROOKIE'), t.moedas DESC, t.city, t.name";
                $stmt = $pdo->query($query);
            }

            $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'teams' => $teams, 'league' => $league]);
            break;

        case 'coins_log':
            // Histórico de moedas de um time
            $teamId = $_GET['team_id'] ?? null;
            if (!$teamId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Team ID obrigatório']);
                exit;
            }

            $stmtCoinsLogTeam = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
            $stmtCoinsLogTeam->execute([$teamId]);
            $coinsLogTeamLeague = $stmtCoinsLogTeam->fetchColumn();
            if ($coinsLogTeamLeague === false) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $coinsLogTeamLeague);

            $stmt = $pdo->prepare("
                SELECT * FROM team_coins_log
                WHERE team_id = ?
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$teamId]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'logs' => $logs]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    exit;
}

// PUT - Atualizar dados
if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'hall_of_fame':
            ensureHallOfFameTable($pdo);
            $id = isset($data['id']) ? (int)$data['id'] : 0;
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ID obrigatorio']);
                exit;
            }

            $stmtHofExistingRow = $pdo->prepare('SELECT league FROM hall_of_fame WHERE id = ?');
            $stmtHofExistingRow->execute([$id]);
            $hofExistingRow = $stmtHofExistingRow->fetch(PDO::FETCH_ASSOC);
            if (!$hofExistingRow) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Registro não encontrado']);
                exit;
            }
            // Escopa pela liga atual do registro (se houver). Registros históricos
            // sem liga definida ficam restritos ao admin global.
            if (!empty($hofExistingRow['league'])) {
                requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $hofExistingRow['league']);
            } elseif (!$isGlobalAdminApi) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Você não tem permissão para administrar esta liga.']);
                exit;
            }
            // Se o payload pedir para mover o registro pra outra liga, o admin
            // também precisa ter permissão sobre a liga de destino.
            if (isset($data['league']) && in_array($data['league'], ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
                requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $data['league']);
            }

            $titles = isset($data['titles']) ? (int)$data['titles'] : null;
            if ($titles !== null && $titles < 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Titulos invalidos']);
                exit;
            }

            $fields = [];
            $params = [];
            if ($titles !== null) {
                $fields[] = 'titles = ?';
                $params[] = $titles;
            }

            $teamName = isset($data['team_name']) ? trim((string)$data['team_name']) : null;
            $gmName = isset($data['gm_name']) ? trim((string)$data['gm_name']) : null;
            if ($teamName !== null) {
                $fields[] = 'team_name = ?';
                $params[] = $teamName;
            }
            if ($gmName !== null) {
                $fields[] = 'gm_name = ?';
                $params[] = $gmName;
            }

            $validLeaguesHof = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
            if (isset($data['league']) && in_array($data['league'], $validLeaguesHof, true)) {
                $fields[] = 'league = ?';
                $params[] = $data['league'];
            }

            if (empty($fields)) {
                echo json_encode(['success' => true]);
                exit;
            }

            $params[] = $id;
            $stmt = $pdo->prepare('UPDATE hall_of_fame SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $stmt->execute($params);
            echo json_encode(['success' => true]);
            exit;

        case 'league_settings':
            // Atualizar configurações de liga
            $league = $data['league'] ?? null;
            $cap_min = isset($data['cap_min']) ? (int)$data['cap_min'] : null;
            $cap_max = isset($data['cap_max']) ? (int)$data['cap_max'] : null;
            $max_trades = isset($data['max_trades']) ? (int)$data['max_trades'] : null;
            $edital = $data['edital'] ?? null;
            $trades_enabled = isset($data['trades_enabled']) ? (int)$data['trades_enabled'] : null;
            $fa_enabled = isset($data['fa_enabled']) ? (int)$data['fa_enabled'] : null;

            // Estado ANTES de gravar: sem isto não dá pra saber se o toggle
            // realmente virou, e o admin salvando outra coisa qualquer da
            // tela dispararia notificação sem nada ter mudado.
            $antesToggles = ['trades_enabled' => null, 'fa_enabled' => null];
            if ($trades_enabled !== null || $fa_enabled !== null) {
                $stAntes = $pdo->prepare('SELECT trades_enabled, fa_enabled FROM league_settings WHERE league = ?');
                $stAntes->execute([$league]);
                $linhaAntes = $stAntes->fetch(PDO::FETCH_ASSOC) ?: [];
                $antesToggles['trades_enabled'] = isset($linhaAntes['trades_enabled']) ? (int)$linhaAntes['trades_enabled'] : null;
                $antesToggles['fa_enabled']     = isset($linhaAntes['fa_enabled'])     ? (int)$linhaAntes['fa_enabled']     : null;
            }
            $n8n_webhook_url = array_key_exists('n8n_webhook_url', $data) ? trim((string)$data['n8n_webhook_url']) : null;
            $progression_video_url = array_key_exists('progression_video_url', $data) ? trim((string)$data['progression_video_url']) : null;
            $sistemas_video_url = array_key_exists('sistemas_video_url', $data) ? trim((string)$data['sistemas_video_url']) : null;
            $freeagency_video_url = array_key_exists('freeagency_video_url', $data) ? trim((string)$data['freeagency_video_url']) : null;
            $cap_auto_margin = isset($data['cap_auto_margin']) ? (int)$data['cap_auto_margin'] : null;
            $cap_auto_margin_pct = isset($data['cap_auto_margin_pct']) ? (int)$data['cap_auto_margin_pct'] : null;
            $max_seasons = isset($data['max_seasons']) ? (int)$data['max_seasons'] : null;
            // Temporada a partir da qual o Cap Flex vale. Vazio/0 = sempre ligado.
            $cap_flex_desde = array_key_exists('cap_flex_a_partir_da_temporada', $data)
                ? (($data['cap_flex_a_partir_da_temporada'] === '' || $data['cap_flex_a_partir_da_temporada'] === null)
                    ? 0 : max(0, (int)$data['cap_flex_a_partir_da_temporada']))
                : null;

            if (!$league) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga obrigatória']);
                exit;
            }
            if (!in_array(strtoupper($league), $validLeagues, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga inválida']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $league);

            if ($cap_min !== null && $cap_min < 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'cap_min não pode ser negativo']);
                exit;
            }
            if ($cap_max !== null && $cap_max < 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'cap_max não pode ser negativo']);
                exit;
            }
            if ($cap_min !== null && $cap_max !== null && $cap_min > $cap_max) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'cap_min não pode ser maior que cap_max']);
                exit;
            }
            if ($max_seasons !== null && $max_seasons < 1) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'max_seasons deve ser pelo menos 1']);
                exit;
            }

            $updates = [];
            $params = [];

            if ($cap_min !== null) {
                $updates[] = 'cap_min = ?';
                $params[] = $cap_min;
            }
            if ($cap_max !== null) {
                $updates[] = 'cap_max = ?';
                $params[] = $cap_max;
            }
            if ($max_trades !== null) {
                $updates[] = 'max_trades = ?';
                $params[] = $max_trades;
            }
            if ($edital !== null) {
                $updates[] = 'edital = ?';
                $params[] = $edital;
            }
            if ($trades_enabled !== null) {
                $updates[] = 'trades_enabled = ?';
                $params[] = $trades_enabled;
            }
            if ($fa_enabled !== null) {
                $updates[] = 'fa_enabled = ?';
                $params[] = $fa_enabled;
            }
            if ($n8n_webhook_url !== null) {
                $updates[] = 'n8n_webhook_url = ?';
                $params[] = $n8n_webhook_url;
            }
            if ($progression_video_url !== null) {
                $updates[] = 'progression_video_url = ?';
                $params[] = $progression_video_url;
            }
            if ($sistemas_video_url !== null) {
                $updates[] = 'sistemas_video_url = ?';
                $params[] = $sistemas_video_url;
            }
            if ($freeagency_video_url !== null) {
                $updates[] = 'freeagency_video_url = ?';
                $params[] = $freeagency_video_url;
            }
            if ($cap_auto_margin !== null) {
                $updates[] = 'cap_auto_margin = ?';
                $params[] = max(0, $cap_auto_margin);
            }
            if ($cap_auto_margin_pct !== null) {
                $updates[] = 'cap_auto_margin_pct = ?';
                $params[] = max(0, $cap_auto_margin_pct);
            }
            if ($cap_flex_desde !== null) {
                if (!columnExists($pdo, 'league_settings', 'cap_flex_a_partir_da_temporada')) {
                    $pdo->exec("ALTER TABLE league_settings ADD COLUMN cap_flex_a_partir_da_temporada INT NULL");
                }
                $updates[] = 'cap_flex_a_partir_da_temporada = ?';
                $params[] = $cap_flex_desde ?: null;   // 0/vazio grava NULL = sempre ligado
            }

            if (empty($updates) && $max_seasons === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Nenhum campo para atualizar']);
                exit;
            }

            if (!empty($updates)) {
                $params[] = $league;

                // Verifica se já existe
                $stmtCheck = $pdo->prepare('SELECT id FROM league_settings WHERE league = ?');
                $stmtCheck->execute([$league]);

                if ($stmtCheck->fetch()) {
                    $sql = 'UPDATE league_settings SET ' . implode(', ', $updates) . ' WHERE league = ?';
                } else {
                    $sql = 'INSERT INTO league_settings (league, ' . implode(', ', array_map(function($u) {
                        return explode(' = ', $u)[0];
                    }, $updates)) . ') VALUES (?, ' . implode(', ', array_fill(0, count($updates), '?')) . ')';
                    array_unshift($params, $league);
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }

            // max_seasons vive numa tabela separada (league_sprint_config, usada
            // pelo sistema de sprints em api/seasons.php) — edição intencional do
            // admin aqui sempre vale, ao contrário do seed automático que só
            // preenche quando a liga ainda não tem linha nenhuma.
            if ($max_seasons !== null) {
                $pdo->prepare("INSERT INTO league_sprint_config (league, max_seasons) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE max_seasons = VALUES(max_seasons)")
                    ->execute([$league, $max_seasons]);
            }

            // Ao fechar a janela de trocas, cancela automaticamente qualquer
            // troca ainda pendente daquela liga (1x1 e multi-times).
            if ($trades_enabled === 0) {
                try {
                    $pdo->prepare("UPDATE trades SET status = 'cancelled' WHERE league = ? AND status = 'pending'")
                        ->execute([$league]);
                } catch (Exception $e) {
                    error_log('Erro ao cancelar trades pendentes: ' . $e->getMessage());
                }
                try {
                    $pdo->prepare("UPDATE multi_trades SET status = 'cancelled' WHERE league = ? AND status = 'pending'")
                        ->execute([$league]);
                } catch (Exception $e) {
                    error_log('Erro ao cancelar multi_trades pendentes: ' . $e->getMessage());
                }
            }

            require_once __DIR__ . '/../backend/push.php';
            responderEDepoisNotificar(
                ['success' => true],
                function () use ($pdo, $league, $trades_enabled, $fa_enabled, $antesToggles) {
                    if ($trades_enabled !== null && $antesToggles['trades_enabled'] !== $trades_enabled) {
                        sendPushToLeague($pdo, $league, $trades_enabled === 1
                            ? ['title' => '🔄 Trades abertas na ' . $league,
                               'body'  => 'A janela de trocas está no ar. Bora negociar.',
                               'url'   => '/trades.php']
                            : ['title' => '🔒 Trades fechadas na ' . $league,
                               'body'  => 'A janela de trocas foi encerrada. As pendentes foram canceladas.',
                               'url'   => '/trades.php'],
                            'trades');
                    }
                    if ($fa_enabled !== null && $antesToggles['fa_enabled'] !== $fa_enabled) {
                        sendPushToLeague($pdo, $league, $fa_enabled === 1
                            ? ['title' => '💰 Free Agency aberta na ' . $league,
                               'body'  => 'A janela de propostas está no ar. Corra pros free agents!',
                               'url'   => '/free-agency.php']
                            : ['title' => '🔒 Free Agency fechada na ' . $league,
                               'body'  => 'A janela de propostas foi encerrada.',
                               'url'   => '/free-agency.php'],
                            'free_agency');
                    }
                }
            );
            exit;

        case 'team':
            // Atualizar informações do time
            $teamId = $data['team_id'] ?? null;
            $city = $data['city'] ?? null;
            $name = $data['name'] ?? null;
            $mascot = $data['mascot'] ?? null;
            $conference = $data['conference'] ?? null;
            $divisionId = $data['division_id'] ?? null;

            if (!$teamId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Team ID obrigatório']);
                exit;
            }

            $stmtTeamScope = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
            $stmtTeamScope->execute([$teamId]);
            $teamScopeLeague = $stmtTeamScope->fetchColumn();
            if ($teamScopeLeague === false) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $teamScopeLeague);

            $updates = [];
            $params = [];

            if ($city !== null) {
                $updates[] = 'city = ?';
                $params[] = $city;
            }
            if ($name !== null) {
                $updates[] = 'name = ?';
                $params[] = $name;
            }
            if ($mascot !== null) {
                $updates[] = 'mascot = ?';
                $params[] = $mascot;
            }
            if ($conference !== null) {
                $updates[] = 'conference = ?';
                $params[] = $conference;
            }
            if ($divisionId !== null) {
                $updates[] = 'division_id = ?';
                $params[] = $divisionId;
            }
            if (isset($data['trades_used'])) {
                $updates[] = 'trades_used = ?';
                $params[] = max(0, (int)$data['trades_used']);
            }
            if (isset($data['waivers_used'])) {
                $updates[] = 'waivers_used = ?';
                $params[] = max(0, (int)$data['waivers_used']);
            }

            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Nenhum campo para atualizar']);
                exit;
            }

            $params[] = $teamId;
            $sql = 'UPDATE teams SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            echo json_encode(['success' => true]);
            break;

        case 'player':
            // Atualizar jogador ou transferir para outro time
            $playerId = $data['player_id'] ?? null;
            $teamId   = array_key_exists('team_id', $data) ? $data['team_id'] : null;
            $nome     = array_key_exists('name', $data) ? trim((string)$data['name']) : null;
            $ovr      = $data['ovr'] ?? null;
            $role     = $data['role'] ?? null;
            $position = $data['position'] ?? null;
            $age      = $data['age'] ?? null;
            $isFranchisePlayer = array_key_exists('is_franchise_player', $data) ? $data['is_franchise_player'] : null;
            $loyalOverride = array_key_exists('loyal_override', $data) ? $data['loyal_override'] : null;

            if (!$playerId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Player ID obrigatório']);
                exit;
            }

            $stmtPlayerScope = $pdo->prepare('SELECT t.league FROM players p JOIN teams t ON t.id = p.team_id WHERE p.id = ?');
            $stmtPlayerScope->execute([$playerId]);
            $playerScopeLeague = $stmtPlayerScope->fetchColumn();
            if ($playerScopeLeague === false) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Jogador não encontrado']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $playerScopeLeague);

            $updates = [];
            $params  = [];

            if ($teamId !== null) {
                $newTeamId = (int)$teamId;
                if ($newTeamId <= 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Time de destino inválido']);
                    exit;
                }
                $stmtDestTeamScope = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
                $stmtDestTeamScope->execute([$newTeamId]);
                $destTeamLeague = $stmtDestTeamScope->fetchColumn();
                if ($destTeamLeague === false) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Time de destino não encontrado']);
                    exit;
                }
                requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $destTeamLeague);
                $updates[] = 'team_id = ?';
                $params[]  = $newTeamId;
            }
            if ($nome !== null) {
                // Nome vazio apagaria a identidade do jogador em toda a base
                // (o histórico de trocas guarda o nome, não o id). E a coluna
                // é varchar(120): cortar aqui evita o erro cru do MySQL.
                if ($nome === '') {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'O nome não pode ficar vazio.']);
                    exit;
                }
                $updates[] = 'name = ?';
                $params[]  = mb_substr($nome, 0, 120);
            }
            if ($ovr !== null) {
                $updates[] = 'ovr = ?';
                $params[]  = (int)$ovr;
            }
            if ($role !== null) {
                $updates[] = 'role = ?';
                $params[]  = $role;
            }
            if ($position !== null) {
                $updates[] = 'position = ?';
                $params[]  = $position;
            }
            if ($age !== null) {
                $updates[] = 'age = ?';
                $params[]  = (int)$age;
            }
            if (array_key_exists('secondary_position', $data)) {
                $updates[] = 'secondary_position = ?';
                $params[]  = ($data['secondary_position'] !== null && $data['secondary_position'] !== '')
                    ? $data['secondary_position'] : null;
            }
            if ($isFranchisePlayer !== null) {
                ensurePlayerRestrictionColumns($pdo);
                $updates[] = 'is_franchise_player = ?';
                $params[]  = ($isFranchisePlayer === 1 || $isFranchisePlayer === '1' || $isFranchisePlayer === true) ? 1 : 0;
            }
            if ($loyalOverride !== null) {
                ensurePlayerRestrictionColumns($pdo);
                $updates[] = 'loyal_override = ?';
                $params[]  = ($loyalOverride === 1 || $loyalOverride === '1' || $loyalOverride === true) ? 1 : 0;
            }

            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Nenhum campo para atualizar']);
                exit;
            }

            $params[] = $playerId;
            $sql = 'UPDATE players SET ' . implode(', ', $updates) . ' WHERE id = ?';

            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                // players tem UNIQUE (team_id, name): renomear pra um nome que
                // ja existe no time cai aqui. Sem esta mensagem o admin via
                // "Erro ao atualizar jogador" e nao fazia ideia do motivo.
                if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062) {
                    http_response_code(409);
                    echo json_encode(['success' => false,
                        'error' => 'Ja existe um jogador com esse nome neste time.']);
                } else {
                    error_log('[admin update player] ' . $e->getMessage());
                    http_response_code(500);
                    echo json_encode(['success' => false, 'error' => 'Erro ao atualizar jogador']);
                }
            }
            break;

        case 'cancel_trade':
            // Cancelar trade (admin pode cancelar qualquer trade)
            $tradeId = $data['trade_id'] ?? null;

            if (!$tradeId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Trade ID obrigatório']);
                exit;
            }

            $stmtCancelTradeLeague = $pdo->prepare('SELECT COALESCE(t.league, ft.league) AS league FROM trades t JOIN teams ft ON t.from_team_id = ft.id WHERE t.id = ?');
            $stmtCancelTradeLeague->execute([$tradeId]);
            $cancelTradeLeague = $stmtCancelTradeLeague->fetchColumn();
            if ($cancelTradeLeague === false) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Trade não encontrada']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $cancelTradeLeague);

            // Só cancela trade pendente — 'accepted' já moveu ativos (usar revert_trade) e
            // cancelar uma já 'rejected'/'cancelled'/'countered' não faz sentido.
            $stmt = $pdo->prepare("UPDATE trades SET status = 'cancelled' WHERE id = ? AND status = 'pending'");
            $stmt->execute([$tradeId]);
            if ($stmt->rowCount() === 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Só é possível cancelar trades pendentes.']);
                exit;
            }

            echo json_encode(['success' => true, 'message' => 'Trade cancelada']);
            break;

        case 'trade_in_game':
            $tradeId = $data['trade_id'] ?? null;
            $isInGame = isset($data['is_in_game']) ? (int)$data['is_in_game'] : null;
            // Front-end já sabe se o card é de trade simples ou múltipla (tr.is_multi) e manda
            // isso explícito — evita colisão de ID entre as duas tabelas (sequências independentes).
            // Se não vier (chamada antiga/externa), cai no fallback por tentativa.
            $isMultiFlag = array_key_exists('is_multi', $data) ? (bool)$data['is_multi'] : null;

            if (!$tradeId || $isInGame === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Trade ID e status obrigatorios']);
                exit;
            }

            if ($isMultiFlag === true) {
                $tigLeague = false;
                if (tableExists($pdo, 'multi_trades')) {
                    $s = $pdo->prepare('SELECT COALESCE(mt.league, ct.league) AS league FROM multi_trades mt JOIN teams ct ON ct.id = mt.created_by_team_id WHERE mt.id = ?');
                    $s->execute([$tradeId]);
                    $tigLeague = $s->fetchColumn();
                }
            } elseif ($isMultiFlag === false) {
                $s = $pdo->prepare('SELECT COALESCE(t.league, ft.league) AS league FROM trades t JOIN teams ft ON t.from_team_id = ft.id WHERE t.id = ?');
                $s->execute([$tradeId]);
                $tigLeague = $s->fetchColumn();
            } else {
                // Fallback (sem is_multi informado): tenta trades, depois multi_trades
                $stmtTigLeague = $pdo->prepare('SELECT COALESCE(t.league, ft.league) AS league FROM trades t JOIN teams ft ON t.from_team_id = ft.id WHERE t.id = ?');
                $stmtTigLeague->execute([$tradeId]);
                $tigLeague = $stmtTigLeague->fetchColumn();
                if ($tigLeague === false && tableExists($pdo, 'multi_trades')) {
                    $stmtTigMultiLeague = $pdo->prepare('SELECT COALESCE(mt.league, ct.league) AS league FROM multi_trades mt JOIN teams ct ON ct.id = mt.created_by_team_id WHERE mt.id = ?');
                    $stmtTigMultiLeague->execute([$tradeId]);
                    $tigLeague = $stmtTigMultiLeague->fetchColumn();
                }
            }
            if ($tigLeague === false) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Trade não encontrada']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $tigLeague);

            if ($isMultiFlag === true) {
                $pdo->prepare('UPDATE multi_trades SET is_in_game = ? WHERE id = ?')->execute([$isInGame ? 1 : 0, $tradeId]);
            } elseif ($isMultiFlag === false) {
                $pdo->prepare('UPDATE trades SET is_in_game = ? WHERE id = ?')->execute([$isInGame ? 1 : 0, $tradeId]);
            } else {
                // Fallback: tenta trades primeiro; se não afetou nenhuma linha, tenta multi_trades
                $stmt = $pdo->prepare('UPDATE trades SET is_in_game = ? WHERE id = ?');
                $stmt->execute([$isInGame ? 1 : 0, $tradeId]);
                if ($stmt->rowCount() === 0 && tableExists($pdo, 'multi_trades')) {
                    $pdo->prepare('UPDATE multi_trades SET is_in_game = ? WHERE id = ?')
                        ->execute([$isInGame ? 1 : 0, $tradeId]);
                }
            }

            echo json_encode(['success' => true]);
            break;

        case 'clear_pick_swap':
            // Limpar campos de swap de uma pick específica (usado para corrigir picks travadas após reversão de trade)
            $pickId = $data['pick_id'] ?? null;
            if (!$pickId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'pick_id obrigatório']);
                exit;
            }
            $stmtClearPickScope = $pdo->prepare('SELECT t.league FROM picks p JOIN teams t ON t.id = p.team_id WHERE p.id = ?');
            $stmtClearPickScope->execute([(int)$pickId]);
            $clearPickLeague = $stmtClearPickScope->fetchColumn();
            if ($clearPickLeague === false) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Pick não encontrada']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $clearPickLeague);

            // Swap é par: limpar só um lado deixava a outra pick apontando pra
            // uma que não é mais swap. Ela seguia travada (swap_locked = 1) e
            // com swap_pair_pick_id pendurado, aparecendo como "SB · Melhor"
            // contra ninguém — e o próximo swap dela era recusado com "pick já
            // está travada", sem que houvesse swap algum.
            $stmtPar = $pdo->prepare('SELECT swap_pair_pick_id FROM picks WHERE id = ?');
            $stmtPar->execute([(int)$pickId]);
            $parId = (int)($stmtPar->fetchColumn() ?: 0);

            $stmtClear = $pdo->prepare('UPDATE picks SET swap_type = NULL, swap_locked = 0, swap_pair_pick_id = NULL WHERE id = ?');
            $stmtClear->execute([(int)$pickId]);
            // Só limpa o par se ele realmente apontava de volta — pick que
            // está em outro swap não pode ser desfeita de carona.
            if ($parId > 0) {
                $pdo->prepare('UPDATE picks SET swap_type = NULL, swap_locked = 0, swap_pair_pick_id = NULL
                               WHERE id = ? AND swap_pair_pick_id = ?')->execute([$parId, (int)$pickId]);
            }
            echo json_encode(['success' => true,
                'message' => $parId > 0
                    ? 'Swap desfeito nas duas picks do par.'
                    : 'Campos de swap da pick limpos com sucesso']);
            break;

        case 'revert_trade':
            // Reverter trade aceita
            $tradeId = $data['trade_id'] ?? null;

            if (!$tradeId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Trade ID obrigatório']);
                exit;
            }

            $stmtRevertTradeLeague = $pdo->prepare('SELECT COALESCE(t.league, ft.league) AS league FROM trades t JOIN teams ft ON t.from_team_id = ft.id WHERE t.id = ?');
            $stmtRevertTradeLeague->execute([$tradeId]);
            $revertTradeLeague = $stmtRevertTradeLeague->fetchColumn();
            if ($revertTradeLeague === false) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Trade não encontrada']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $revertTradeLeague);

            try {
                $pdo->beginTransaction();

                // Buscar trade (FOR UPDATE evita corrida entre dois cliques de reverter)
                $stmtTrade = $pdo->prepare("SELECT * FROM trades WHERE id = ? AND status = 'accepted' FOR UPDATE");
                $stmtTrade->execute([$tradeId]);
                $trade = $stmtTrade->fetch(PDO::FETCH_ASSOC);

                if (!$trade) {
                    throw new Exception('Trade não encontrada ou não foi aceita');
                }

                // Buscar itens da trade
                $stmtItems = $pdo->prepare('SELECT * FROM trade_items WHERE trade_id = ?');
                $stmtItems->execute([$tradeId]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                $playersReverted = [];
                $picksReverted = [];
                $errors = [];

                // Reverter transferências
                foreach ($items as $item) {
                    // originalTeamId: time que deve receber de volta. expectedCurrentTeamId: time
                    // que deveria estar com o ativo agora (quem recebeu nesta troca) — só revertemos
                    // se o ativo ainda estiver lá; se já foi negociado de novo pra um terceiro time,
                    // não mexemos (evita "roubar" um ativo de um time não relacionado a esta troca).
                    $originalTeamId = $item['from_team'] ? $trade['from_team_id'] : $trade['to_team_id'];
                    $expectedCurrentTeamId = $item['from_team'] ? $trade['to_team_id'] : $trade['from_team_id'];

                    if ($item['player_id']) {
                        // Verificar time atual do jogador
                        $stmtCheckPlayer = $pdo->prepare('SELECT team_id, name FROM players WHERE id = ?');
                        $stmtCheckPlayer->execute([$item['player_id']]);
                        $player = $stmtCheckPlayer->fetch(PDO::FETCH_ASSOC);

                        if (!$player) {
                            $errors[] = "Jogador ID {$item['player_id']} não encontrado (pode ter sido dispensado)";
                        } elseif ((int)$player['team_id'] === (int)$expectedCurrentTeamId) {
                            $stmtRevert = $pdo->prepare('UPDATE players SET team_id = ? WHERE id = ?');
                            $stmtRevert->execute([$originalTeamId, $item['player_id']]);
                            $playersReverted[] = $player['name'];
                        } elseif ((int)$player['team_id'] === (int)$originalTeamId) {
                            // Jogador já está no time original (pode ter sido revertido antes)
                            $playersReverted[] = $player['name'] . ' (já estava no time)';
                        } else {
                            $errors[] = "Jogador {$player['name']} não está no time esperado (foi negociado novamente) — não revertido";
                        }
                    }

                    if ($item['pick_id']) {
                        // Verificar estado atual da pick
                        $stmtCheckPick = $pdo->prepare('SELECT team_id, original_team_id, last_owner_team_id, season_year, round FROM picks WHERE id = ?');
                        $stmtCheckPick->execute([$item['pick_id']]);
                        $pick = $stmtCheckPick->fetch(PDO::FETCH_ASSOC);

                        if (!$pick) {
                            $errors[] = "Pick ID {$item['pick_id']} não encontrada";
                        } elseif ((int)$pick['team_id'] === (int)$expectedCurrentTeamId) {
                            // O last_owner deve ser quem tinha antes da trade atual
                            // Se from_team=true, o dono original era from_team, então last_owner deve ser NULL ou from_team
                            // Se from_team=false, o dono original era to_team
                            $lastOwnerBeforeTrade = $item['from_team'] ? null : $trade['to_team_id'];

                            $stmtRevert = $pdo->prepare('UPDATE picks SET team_id = ?, last_owner_team_id = ?, swap_type = NULL, swap_locked = 0, swap_pair_pick_id = NULL WHERE id = ?');
                            $stmtRevert->execute([$originalTeamId, $lastOwnerBeforeTrade, $item['pick_id']]);
                            $picksReverted[] = "{$pick['season_year']} R{$pick['round']}";
                        } elseif ((int)$pick['team_id'] === (int)$originalTeamId) {
                            // Pick já está no time original (caso swap), limpar campos de swap residuais
                            $stmtClearSwap = $pdo->prepare('UPDATE picks SET swap_type = NULL, swap_locked = 0, swap_pair_pick_id = NULL WHERE id = ?');
                            $stmtClearSwap->execute([$item['pick_id']]);
                            $picksReverted[] = "{$pick['season_year']} R{$pick['round']} (já estava no time)";
                        } else {
                            $errors[] = "Pick {$pick['season_year']} R{$pick['round']} não está no time esperado (foi negociada novamente) — não revertida";
                        }
                    }
                }

                // Atualizar status da trade
                $revertLog = "[Admin] Trade revertida em " . date('Y-m-d H:i:s');
                if (!empty($playersReverted)) {
                    $revertLog .= "\nJogadores revertidos: " . implode(', ', $playersReverted);
                }
                if (!empty($picksReverted)) {
                    $revertLog .= "\nPicks revertidas: " . implode(', ', $picksReverted);
                }
                if (!empty($errors)) {
                    $revertLog .= "\nAvisos: " . implode('; ', $errors);
                }

                $stmtUpdate = $pdo->prepare("UPDATE trades SET status = 'cancelled', notes = CONCAT(IFNULL(notes, ''), '\n', ?) WHERE id = ?");
                $stmtUpdate->execute([$revertLog, $tradeId]);

                $pdo->commit();

                $response = [
                    'success' => true,
                    'message' => 'Trade revertida com sucesso',
                    'players_reverted' => count($playersReverted),
                    'picks_reverted' => count($picksReverted)
                ];

                if (!empty($errors)) {
                    $response['warnings'] = $errors;
                }

                echo json_encode($response);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Erro interno do servidor.']);
            }
            break;

        case 'revert_multi_trade':
            // Reverter trade múltipla aceita
            $tradeId = $data['trade_id'] ?? null;

            if (!$tradeId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Trade ID obrigatório']);
                exit;
            }

            if (!tableExists($pdo, 'multi_trades') || !tableExists($pdo, 'multi_trade_items')) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Multi-trades não habilitadas']);
                exit;
            }

            $stmtRevertMultiLeague = $pdo->prepare('SELECT COALESCE(mt.league, ct.league) AS league FROM multi_trades mt JOIN teams ct ON ct.id = mt.created_by_team_id WHERE mt.id = ?');
            $stmtRevertMultiLeague->execute([$tradeId]);
            $revertMultiLeague = $stmtRevertMultiLeague->fetchColumn();
            if ($revertMultiLeague === false) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Trade não encontrada']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $revertMultiLeague);

            try {
                $pdo->beginTransaction();

                $stmtTrade = $pdo->prepare("SELECT * FROM multi_trades WHERE id = ? AND status = 'accepted' FOR UPDATE");
                $stmtTrade->execute([$tradeId]);
                $trade = $stmtTrade->fetch(PDO::FETCH_ASSOC);

                if (!$trade) {
                    throw new Exception('Trade não encontrada ou não foi aceita');
                }

                $stmtItems = $pdo->prepare('SELECT * FROM multi_trade_items WHERE trade_id = ?');
                $stmtItems->execute([$tradeId]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                $playersReverted = [];
                $picksReverted = [];
                $errors = [];

                $stmtCheckPlayer = $pdo->prepare('SELECT team_id, name FROM players WHERE id = ?');
                $stmtMovePlayer = $pdo->prepare('UPDATE players SET team_id = ? WHERE id = ?');
                $stmtCheckPick = $pdo->prepare('SELECT team_id, season_year, round FROM picks WHERE id = ?');
                $stmtMovePick = $pdo->prepare('UPDATE picks SET team_id = ?, last_owner_team_id = ?, swap_type = NULL, swap_locked = 0, swap_pair_pick_id = NULL WHERE id = ?');
                $stmtClearPickSwap = $pdo->prepare('UPDATE picks SET swap_type = NULL, swap_locked = 0, swap_pair_pick_id = NULL WHERE id = ?');

                foreach ($items as $item) {
                    if (!empty($item['player_id'])) {
                        $stmtCheckPlayer->execute([(int)$item['player_id']]);
                        $player = $stmtCheckPlayer->fetch(PDO::FETCH_ASSOC);

                        if (!$player) {
                            $errors[] = "Jogador ID {$item['player_id']} não encontrado";
                        } elseif ((int)$player['team_id'] === (int)$item['to_team_id']) {
                            $stmtMovePlayer->execute([(int)$item['from_team_id'], (int)$item['player_id']]);
                            $playersReverted[] = $player['name'];
                        } elseif ((int)$player['team_id'] === (int)$item['from_team_id']) {
                            $playersReverted[] = $player['name'] . ' (já estava no time)';
                        } else {
                            $errors[] = "Jogador {$player['name']} não está no time esperado";
                        }
                    }

                    if (!empty($item['pick_id'])) {
                        $stmtCheckPick->execute([(int)$item['pick_id']]);
                        $pick = $stmtCheckPick->fetch(PDO::FETCH_ASSOC);

                        if (!$pick) {
                            $errors[] = "Pick ID {$item['pick_id']} não encontrada";
                        } elseif ((int)$pick['team_id'] === (int)$item['to_team_id']) {
                            $stmtMovePick->execute([(int)$item['from_team_id'], (int)$item['to_team_id'], (int)$item['pick_id']]);
                            $picksReverted[] = "{$pick['season_year']} R{$pick['round']}";
                        } elseif ((int)$pick['team_id'] === (int)$item['from_team_id']) {
                            // Pick já está no time original (caso swap), limpar campos de swap residuais
                            $stmtClearPickSwap->execute([(int)$item['pick_id']]);
                            $picksReverted[] = "{$pick['season_year']} R{$pick['round']} (já estava no time)";
                        } else {
                            $errors[] = "Pick {$item['pick_id']} não está no time esperado";
                        }
                    }
                }

                $revertLog = "[Admin] Trade múltipla revertida em " . date('Y-m-d H:i:s');
                if (!empty($playersReverted)) {
                    $revertLog .= "\nJogadores revertidos: " . implode(', ', $playersReverted);
                }
                if (!empty($picksReverted)) {
                    $revertLog .= "\nPicks revertidas: " . implode(', ', $picksReverted);
                }
                if (!empty($errors)) {
                    $revertLog .= "\nAvisos: " . implode('; ', $errors);
                }

                $stmtUpdate = $pdo->prepare("UPDATE multi_trades SET status = 'cancelled', notes = CONCAT(IFNULL(notes, ''), '\n', ?) WHERE id = ?");
                $stmtUpdate->execute([$revertLog, $tradeId]);

                $pdo->commit();

                $response = [
                    'success' => true,
                    'message' => 'Trade múltipla revertida com sucesso',
                    'players_reverted' => count($playersReverted),
                    'picks_reverted' => count($picksReverted)
                ];

                if (!empty($errors)) {
                    $response['warnings'] = $errors;
                }

                echo json_encode($response);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Erro interno do servidor.']);
            }
            break;

        case 'pick':
            // Atualizar ou adicionar pick
            $pickId = $data['pick_id'] ?? null;
            $teamId = $data['team_id'] ?? null;
            $originalTeamId = $data['original_team_id'] ?? null;
            $seasonYear = $data['season_year'] ?? null;
            $round = $data['round'] ?? null;
            $notes = $data['notes'] ?? null;
            $swapTypeRaw = isset($data['swap_type']) ? trim((string)$data['swap_type']) : null;
            $swapType = ($swapTypeRaw !== null && $swapTypeRaw !== '') ? strtoupper($swapTypeRaw) : null;
            if ($swapType !== null && !in_array($swapType, ['SW', 'SB'])) $swapType = null;
            $swapLocked = $swapType !== null ? 1 : 0;

            if (!$teamId || !$originalTeamId || !$seasonYear || !$round) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Dados obrigatórios ausentes']);
                exit;
            }

            $stmtPickTeamScope = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
            $stmtPickTeamScope->execute([(int)$teamId]);
            $pickTeamLeague = $stmtPickTeamScope->fetchColumn();
            if ($pickTeamLeague === false) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Time de destino inválido']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $pickTeamLeague);

            $stmtPickOrigTeamScope = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
            $stmtPickOrigTeamScope->execute([(int)$originalTeamId]);
            $pickOrigTeamLeague = $stmtPickOrigTeamScope->fetchColumn();
            if ($pickOrigTeamLeague === false) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Time original inválido']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $pickOrigTeamLeague);

            if ($pickId) {
                $stmtExistingPickScope = $pdo->prepare('SELECT t.league FROM picks p JOIN teams t ON t.id = p.team_id WHERE p.id = ?');
                $stmtExistingPickScope->execute([(int)$pickId]);
                $existingPickLeague = $stmtExistingPickScope->fetchColumn();
                if ($existingPickLeague !== false) {
                    requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $existingPickLeague);
                }
            }

            if ($pickId) {
                // Atualizar pick existente
                $stmt = $pdo->prepare('
                    UPDATE picks
                    SET team_id = ?, original_team_id = ?, season_year = ?, round = ?, notes = ?, swap_type = ?, swap_locked = ?, swap_pair_pick_id = CASE WHEN ? IS NULL THEN NULL ELSE swap_pair_pick_id END, auto_generated = 0, last_owner_team_id = ?
                    WHERE id = ?
                ');
                $stmt->execute([$teamId, $originalTeamId, $seasonYear, $round, $notes, $swapType, $swapLocked, $swapType, $teamId, $pickId]);
            } else {
                // Reutilizar pick existente com mesma origem/ano/rodada ou criar um novo
                $stmtExisting = $pdo->prepare('
                    SELECT id FROM picks WHERE original_team_id = ? AND season_year = ? AND round = ?
                ');
                $stmtExisting->execute([$originalTeamId, $seasonYear, $round]);
                $existingId = $stmtExisting->fetchColumn();

                if ($existingId) {
                    $stmt = $pdo->prepare('
                        UPDATE picks 
                        SET team_id = ?, original_team_id = ?, season_year = ?, round = ?, notes = ?, auto_generated = 0, last_owner_team_id = ?
                        WHERE id = ?
                    ');
                    $stmt->execute([$teamId, $originalTeamId, $seasonYear, $round, $notes, $teamId, $existingId]);
                    $pickId = $existingId;
                } else {
                    $stmt = $pdo->prepare('
                        INSERT INTO picks (team_id, original_team_id, season_year, round, auto_generated, last_owner_team_id, notes)
                        VALUES (?, ?, ?, ?, 0, ?, ?)
                    ');
                    $stmt->execute([$teamId, $originalTeamId, $seasonYear, $round, $teamId, $notes]);
                    $pickId = $pdo->lastInsertId();
                }
            }

            echo json_encode(['success' => true, 'pick_id' => $pickId]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    exit;
}

// POST - Adicionar novos dados
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    switch ($action) {
        case 'games_user_saldo':
            // Ajuste de moedas / FBA Points de um GM, pela aba Games do Admin.
            ensureGamesSchema($pdo);
            if (!hasGamesAdminAccess($pdo, (int)$user['id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Sem acesso ao admin do Games']);
                exit;
            }
            $alvoId = (int)($data['user_id'] ?? 0);
            $pontos = isset($data['pontos']) ? (int)$data['pontos'] : null;
            $fbaPts = isset($data['fba_points']) ? (int)$data['fba_points'] : null;
            if ($alvoId <= 0 || ($pontos === null && $fbaPts === null)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
                exit;
            }
            try {
                // Garante o perfil: quem nunca abriu o Games ainda não tem linha.
                $pdo->prepare("
                    INSERT IGNORE INTO games_usuarios (id, nome, email, league)
                    SELECT id, name, email, COALESCE(league,'ROOKIE') FROM users WHERE id = ?
                ")->execute([$alvoId]);

                $sets = [];
                $vals = [];
                if ($pontos !== null) { $sets[] = 'pontos = ?';     $vals[] = max(0, $pontos); }
                if ($fbaPts !== null) { $sets[] = 'fba_points = ?'; $vals[] = max(0, $fbaPts); }
                $vals[] = $alvoId;
                $pdo->prepare('UPDATE games_usuarios SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
                echo json_encode(['success' => true]);
            } catch (Throwable $e) {
                error_log('[games_user_saldo] ' . $e->getMessage());
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Erro ao salvar o saldo.']);
            }
            exit;

        case 'league_invite':
            // Gera ou revoga o link de convite reutilizável da liga.
            require_once __DIR__ . '/../backend/nba_teams.php';
            $ligaConvite = strtoupper(trim((string)($data['league'] ?? 'ROOKIE')));
            $acaoConvite = (string)($data['acao'] ?? 'gerar');
            if ($ligaConvite !== 'ROOKIE') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Convite reutilizável só existe na ROOKIE.']);
                exit;
            }
            if (!in_array($ligaConvite, $apiAdminLeagues, true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Sem acesso a essa liga']);
                exit;
            }
            if (!in_array($acaoConvite, ['gerar', 'revogar'], true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Ação inválida']);
                exit;
            }
            try {
                ensureLeagueInviteColumn($pdo);
                // Gerar de novo invalida o link anterior — é justamente como se
                // revoga um link que vazou.
                $novoToken = $acaoConvite === 'gerar' ? bin2hex(random_bytes(16)) : null;
                $pdo->prepare("INSERT INTO league_settings (league, invite_token) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE invite_token = VALUES(invite_token)")
                    ->execute([$ligaConvite, $novoToken]);
                error_log(sprintf('[league_invite] %s %s por user_id=%d',
                    $ligaConvite, $acaoConvite, (int)$user['id']));
                echo json_encode(['success' => true, 'league' => $ligaConvite, 'token' => $novoToken]);
            } catch (Throwable $e) {
                error_log('[league_invite] ' . $e->getMessage());
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Erro ao salvar o convite.']);
            }
            exit;

        case 'games_zerar':
            // Zera moedas OU FBA Points de todo mundo de uma vez. Desde que o
            // reset automático do dia 1º saiu, este é o ÚNICO jeito de zerar —
            // o saldo acumula sozinho pra sempre. Um campo por vez, de
            // propósito: zerar os dois juntos raramente é o que se quer e não
            // dá pra desfazer.
            ensureGamesSchema($pdo);
            if (!hasGamesAdminAccess($pdo, (int)$user['id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Sem acesso ao admin do Games']);
                exit;
            }
            $campo = (string)($data['campo'] ?? '');
            if (!in_array($campo, ['pontos', 'fba_points'], true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Campo inválido']);
                exit;
            }
            try {
                $stmt = $pdo->prepare("UPDATE games_usuarios SET {$campo} = 0 WHERE {$campo} <> 0");
                $stmt->execute();
                $afetados = $stmt->rowCount();
                error_log(sprintf('[games_zerar] %s zerado por user_id=%d (%d usuários)',
                    $campo, (int)$user['id'], $afetados));
                echo json_encode(['success' => true, 'afetados' => $afetados]);
            } catch (Throwable $e) {
                error_log('[games_zerar] ' . $e->getMessage());
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Erro ao zerar.']);
            }
            exit;

        case 'games_admin_toggle':
            // Liga/desliga o "Admin Games" de um usuário — só admin geral mexe.
            ensureGamesSchema($pdo);
            if (!$isGlobalAdminApi) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas admin geral']);
                exit;
            }
            $alvoId = (int)($data['user_id'] ?? 0);
            $ligar  = !empty($data['enabled']) ? 1 : 0;
            if ($alvoId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'user_id inválido']);
                exit;
            }
            try {
                $pdo->prepare("
                    INSERT IGNORE INTO games_usuarios (id, nome, email, league)
                    SELECT id, name, email, COALESCE(league,'ROOKIE') FROM users WHERE id = ?
                ")->execute([$alvoId]);
                $pdo->prepare('UPDATE games_usuarios SET is_admin = ? WHERE id = ?')->execute([$ligar, $alvoId]);
                echo json_encode(['success' => true, 'enabled' => (bool)$ligar]);
            } catch (Throwable $e) {
                error_log('[games_admin_toggle] ' . $e->getMessage());
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Erro ao alterar o acesso.']);
            }
            exit;

        case 'draft_class_bank':
            $pdo->exec("CREATE TABLE IF NOT EXISTS draft_class_templates (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS draft_class_template_players (id INT AUTO_INCREMENT PRIMARY KEY, template_id INT NOT NULL, name VARCHAR(120) NOT NULL, position VARCHAR(20) NOT NULL, ovr INT NOT NULL, age INT NOT NULL, INDEX idx_dctp_tpl (template_id), CONSTRAINT fk_dctp_tpl2 FOREIGN KEY (template_id) REFERENCES draft_class_templates(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            try { $pdo->exec("ALTER TABLE draft_class_template_players ADD COLUMN pick_hint INT NULL"); } catch (Exception $e) {}
            $body = $data ?? [];
            $subAction = $body['sub'] ?? '';
            // Ordem é opcional em todo lugar — sem valor definido, pick_hint fica NULL
            // (mesmo critério de "sem ordem definida" usado no board real de disponíveis).
            $readPickHint = function ($p) {
                $v = $p['pick_hint'] ?? null;
                return ($v !== null && $v !== '') ? (int)$v : null;
            };
            if ($subAction === 'save') {
                $tplName = trim($body['name'] ?? '');
                $players = $body['players'] ?? [];
                if (!$tplName) { echo json_encode(['success' => false, 'error' => 'Nome obrigatório']); break; }
                // A classe nasce dona de uma liga. Sem isto ela cai no limbo do
                // "sem liga" e ninguém consegue sortear com ela até alguém
                // atribuir na mão, no controle de drafts.
                $ligaNova = strtoupper(trim((string)($body['league'] ?? $_GET['league'] ?? '')));
                if ($ligaNova !== '' && !in_array($ligaNova, $validLeagues, true)) {
                    echo json_encode(['success' => false, 'error' => 'Liga inválida.']); break;
                }
                if ($ligaNova !== '') requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $ligaNova);

                $pdo->beginTransaction();
                try {
                    $s = $pdo->prepare("INSERT INTO draft_class_templates (name, league, created_by) VALUES (?, ?, ?)");
                    $s->execute([$tplName, $ligaNova !== '' ? $ligaNova : null, (int)$user['id']]);
                    $tplId = (int)$pdo->lastInsertId();
                    $sp = $pdo->prepare("INSERT INTO draft_class_template_players (template_id, name, position, ovr, age, pick_hint) VALUES (?,?,?,?,?,?)");
                    foreach ($players as $p) { $sp->execute([$tplId, trim($p['name']), strtoupper(trim($p['position'])), (int)$p['ovr'], (int)$p['age'], $readPickHint($p)]); }
                    $pdo->commit();
                    echo json_encode(['success' => true, 'template_id' => $tplId, 'message' => 'Classe salva com sucesso!']);
                } catch (Exception $e) { $pdo->rollBack(); echo json_encode(['success' => false, 'error' => 'Erro interno do servidor.']); }
            } elseif ($subAction === 'rename') {
                $tplId = (int)($body['template_id'] ?? 0); $tplName = trim($body['name'] ?? '');
                if (!$tplId || !$tplName) { echo json_encode(['success' => false, 'error' => 'Dados inválidos']); break; }
                $pdo->prepare("UPDATE draft_class_templates SET name=? WHERE id=?")->execute([$tplName, $tplId]);
                echo json_encode(['success' => true]);
            } elseif ($subAction === 'add_player') {
                $tplId = (int)($body['template_id'] ?? 0); $p = $body['player'] ?? [];
                if (!$tplId || empty($p['name'])) { echo json_encode(['success' => false, 'error' => 'Dados inválidos']); break; }
                $sp = $pdo->prepare("INSERT INTO draft_class_template_players (template_id, name, position, ovr, age, pick_hint) VALUES (?,?,?,?,?,?)");
                $sp->execute([$tplId, trim($p['name']), strtoupper(trim($p['position'])), (int)$p['ovr'], (int)$p['age'], $readPickHint($p)]);
                echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
            } elseif ($subAction === 'update_player') {
                $pid = (int)($body['player_id'] ?? 0); $p = $body['player'] ?? [];
                if (!$pid || empty($p['name'])) { echo json_encode(['success' => false, 'error' => 'Dados inválidos']); break; }
                $pdo->prepare("UPDATE draft_class_template_players SET name=?,position=?,ovr=?,age=?,pick_hint=? WHERE id=?")->execute([trim($p['name']), strtoupper(trim($p['position'])), (int)$p['ovr'], (int)$p['age'], $readPickHint($p), $pid]);
                echo json_encode(['success' => true]);
            } elseif ($subAction === 'delete_player') {
                $pid = (int)($body['player_id'] ?? 0);
                if (!$pid) { echo json_encode(['success' => false, 'error' => 'player_id obrigatório']); break; }
                $pdo->prepare("DELETE FROM draft_class_template_players WHERE id=?")->execute([$pid]);
                echo json_encode(['success' => true]);
            } elseif ($subAction === 'replace_players') {
                $tplId = (int)($body['template_id'] ?? 0); $players = $body['players'] ?? [];
                if (!$tplId) { echo json_encode(['success' => false, 'error' => 'template_id obrigatório']); break; }
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("DELETE FROM draft_class_template_players WHERE template_id=?")->execute([$tplId]);
                    $sp = $pdo->prepare("INSERT INTO draft_class_template_players (template_id, name, position, ovr, age, pick_hint) VALUES (?,?,?,?,?,?)");
                    foreach ($players as $p) { $sp->execute([$tplId, trim($p['name']), strtoupper(trim($p['position'])), (int)$p['ovr'], (int)$p['age'], $readPickHint($p)]); }
                    $pdo->commit();
                    echo json_encode(['success' => true, 'inserted' => count($players)]);
                } catch (Exception $e) { $pdo->rollBack(); echo json_encode(['success' => false, 'error' => 'Erro interno do servidor.']); }
            } elseif ($subAction === 'delete') {
                $tplId = (int)($body['template_id'] ?? 0);
                if (!$tplId) { echo json_encode(['success' => false, 'error' => 'template_id obrigatório']); break; }
                $pdo->prepare("DELETE FROM draft_class_templates WHERE id=?")->execute([$tplId]);
                echo json_encode(['success' => true, 'message' => 'Classe excluída.']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Ação desconhecida']);
            }
            break;

        case 'hall_of_fame':
            ensureHallOfFameTable($pdo);
            $isActive = (int)($data['is_active'] ?? 0) === 1;
            $titles = isset($data['titles']) ? (int)$data['titles'] : 0;

            if ($titles < 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Titulos invalidos']);
                exit;
            }

            $league = $data['league'] ?? null;
            $teamId = isset($data['team_id']) ? (int)$data['team_id'] : null;
            $teamName = trim((string)($data['team_name'] ?? ''));
            $gmName = trim((string)($data['gm_name'] ?? ''));

            if ($isActive) {
                if (!$teamId) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Time obrigatorio']);
                    exit;
                }
                $stmtTeam = $pdo->prepare('SELECT t.league, t.city, t.name, u.name AS owner_name FROM teams t JOIN users u ON t.user_id = u.id WHERE t.id = ?');
                $stmtTeam->execute([$teamId]);
                $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
                if (!$team) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Time nao encontrado']);
                    exit;
                }
                requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $team['league'] ?? null);
                $teamCurrentLeague = $team['league'] ?? null;
                // Respeita a liga escolhida no formulário (pra permitir cadastrar título
                // histórico de uma liga diferente da atual do time); só usa a liga atual
                // do time como fallback se nenhuma foi selecionada.
                $league = $league ?: $teamCurrentLeague;
                if ($league && $league !== $teamCurrentLeague) {
                    // Registrando sob uma liga diferente da atual do time: precisa de
                    // permissão também sobre essa liga de destino.
                    requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $league);
                }
                $teamName = trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? ''));
                $gmName = $team['owner_name'] ?? '';
                // Se a liga escolhida não é a atual do time, o registro não pode ficar
                // "ativo" de verdade (o time não está mais lá) -- vira histórico.
                if ($teamCurrentLeague !== null && $league !== $teamCurrentLeague) {
                    $isActive = false;
                }
            } else {
                if ($gmName === '') {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Nome do GM obrigatorio']);
                    exit;
                }
                if ($league) {
                    requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $league);
                }
            }

            // Se já existe um registro pra esse time+liga (ativo ou não), soma os títulos
            // em vez de criar outra linha (evita duplicar/misturar o mesmo time+liga).
            $existingHofId = null;
            if ($teamId) {
                $stmtHofExisting = $pdo->prepare('SELECT id FROM hall_of_fame WHERE team_id = ? AND league = ? LIMIT 1');
                $stmtHofExisting->execute([$teamId, $league]);
                $existingHofId = $stmtHofExisting->fetchColumn() ?: null;
            }

            if ($existingHofId) {
                $pdo->prepare('UPDATE hall_of_fame SET titles = titles + ?, team_name = ?, gm_name = ?, is_active = ? WHERE id = ?')
                    ->execute([$titles, $teamName ?: null, $gmName ?: null, $isActive ? 1 : 0, (int)$existingHofId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO hall_of_fame (is_active, league, team_id, team_name, gm_name, titles) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $isActive ? 1 : 0,
                    $league ?: null,
                    $teamId,
                    $teamName ?: null,
                    $gmName ?: null,
                    $titles
                ]);
            }

            echo json_encode(['success' => true]);
            break;

        case 'player':
            // Adicionar novo jogador
            $teamId = $data['team_id'] ?? null;
            $name = $data['name'] ?? null;
            $age = $data['age'] ?? null;
            $position = $data['position'] ?? null;
            $secondaryPosition = $data['secondary_position'] ?? null;
            $role = $data['role'] ?? 'Banco';
            $ovr = $data['ovr'] ?? 50;

            if (!$teamId || !$name || !$age || !$position) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Dados obrigatórios ausentes']);
                exit;
            }

            $stmtNewPlayerTeamScope = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
            $stmtNewPlayerTeamScope->execute([$teamId]);
            $newPlayerTeamLeague = $stmtNewPlayerTeamScope->fetchColumn();
            if ($newPlayerTeamLeague === false) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $newPlayerTeamLeague);

            $name = trim((string)$name);
            if ($name === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Nome do jogador obrigatório']);
                exit;
            }

            try {
                $stmtCheck = $pdo->prepare('SELECT id FROM players WHERE team_id = ? AND name = ? LIMIT 1');
                $stmtCheck->execute([$teamId, $name]);
                if ($stmtCheck->fetch()) {
                    http_response_code(409);
                    echo json_encode(['success' => false, 'error' => 'Jogador já existe nesse time.']);
                    exit;
                }
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Erro ao validar jogador']);
                exit;
            }

            $columns = ['team_id', 'name', 'age', 'position', 'role', 'ovr'];
            $values = [$teamId, $name, $age, $position, $role, $ovr];

            if (columnExists($pdo, 'players', 'secondary_position')) {
                $secondary = trim((string)$secondaryPosition);
                if ($secondary !== '') {
                    $columns[] = 'secondary_position';
                    $values[] = $secondary;
                }
            }

            if (columnExists($pdo, 'players', 'seasons_in_league')) {
                $columns[] = 'seasons_in_league';
                $values[] = 0;
            }

            if (columnExists($pdo, 'players', 'available_for_trade')) {
                $columns[] = 'available_for_trade';
                $values[] = 0;
            }

            // Jogador cadastrado direto (sem passar por draft nenhum): a lealdade
            // não tem como ser calculada automaticamente, então depende do check
            // "Leal" do formulário — padrão é não.
            ensurePlayerRestrictionColumns($pdo);
            if (columnExists($pdo, 'players', 'loyal_override')) {
                $columns[] = 'loyal_override';
                $loyalOnAdd = $data['loyal_override'] ?? null;
                $values[] = ($loyalOnAdd === 1 || $loyalOnAdd === '1' || $loyalOnAdd === true) ? 1 : 0;
            }

            $columnList = implode(', ', array_map(static fn($col) => "`{$col}`", $columns));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));

            try {
                $stmt = $pdo->prepare("INSERT INTO players ({$columnList}) VALUES ({$placeholders})");
                $stmt->execute($values);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Erro ao adicionar jogador']);
                exit;
            }

            $newPlayerId = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'player_id' => $newPlayerId]);
            break;

        case 'pick':
            // Adicionar novo pick
            $teamId = $data['team_id'] ?? null;
            $originalTeamId = $data['original_team_id'] ?? null;
            $seasonYear = $data['season_year'] ?? null;
            $round = $data['round'] ?? null;
            $notes = $data['notes'] ?? null;

            if (!$teamId || !$originalTeamId || !$seasonYear || !$round) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Dados obrigatórios ausentes']);
                exit;
            }

            $stmtPickTeamScope = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
            $stmtPickTeamScope->execute([(int)$teamId]);
            $pickTeamLeague = $stmtPickTeamScope->fetchColumn();
            if ($pickTeamLeague === false) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Time de destino inválido']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $pickTeamLeague);

            $stmtPickOrigTeamScope = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
            $stmtPickOrigTeamScope->execute([(int)$originalTeamId]);
            $pickOrigTeamLeague = $stmtPickOrigTeamScope->fetchColumn();
            if ($pickOrigTeamLeague === false) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Time original inválido']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $pickOrigTeamLeague);

            $stmtExisting = $pdo->prepare('
                SELECT id FROM picks WHERE original_team_id = ? AND season_year = ? AND round = ?
            ');
            $stmtExisting->execute([$originalTeamId, $seasonYear, $round]);
            $existingId = $stmtExisting->fetchColumn();

            if ($existingId) {
                $stmtExistingPickScope = $pdo->prepare('SELECT t.league FROM picks p JOIN teams t ON t.id = p.team_id WHERE p.id = ?');
                $stmtExistingPickScope->execute([(int)$existingId]);
                $existingPickLeague = $stmtExistingPickScope->fetchColumn();
                if ($existingPickLeague !== false) {
                    requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $existingPickLeague);
                }
                $stmt = $pdo->prepare('
                    UPDATE picks
                    SET team_id = ?, original_team_id = ?, season_year = ?, round = ?, notes = ?, auto_generated = 0, last_owner_team_id = ?
                    WHERE id = ?
                ');
                $stmt->execute([$teamId, $originalTeamId, $seasonYear, $round, $notes, $teamId, $existingId]);
                $newPickId = $existingId;
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO picks (team_id, original_team_id, season_year, round, auto_generated, last_owner_team_id, notes)
                    VALUES (?, ?, ?, ?, 0, ?, ?)
                ');
                $stmt->execute([$teamId, $originalTeamId, $seasonYear, $round, $teamId, $notes]);
                $newPickId = $pdo->lastInsertId();
            }

            echo json_encode(['success' => true, 'pick_id' => $newPickId]);
            break;

        case 'coins':
            // Adicionar ou remover moedas de um time
            $teamId = $data['team_id'] ?? null;
            $amount = $data['amount'] ?? null;
            $reason = $data['reason'] ?? 'Ajuste administrativo';
            $operation = $data['operation'] ?? 'add'; // add ou remove

            if (!$teamId || $amount === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Team ID e quantidade são obrigatórios']);
                exit;
            }

            $amount = (int)$amount;
            if ($amount <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Quantidade deve ser maior que zero']);
                exit;
            }

            // Buscar time
            $stmtTeam = $pdo->prepare('SELECT id, city, name, league, COALESCE(moedas, 0) as moedas FROM teams WHERE id = ?');
            $stmtTeam->execute([$teamId]);
            $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);

            if (!$team) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $team['league'] ?? null);

            $currentCoins = (int)$team['moedas'];
            
            if ($operation === 'remove') {
                if ($currentCoins < $amount) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Time não tem moedas suficientes']);
                    exit;
                }
                $newBalance = $currentCoins - $amount;
                $logAmount = -$amount;
                $logType = 'admin_remove';
            } else {
                $newBalance = $currentCoins + $amount;
                $logAmount = $amount;
                $logType = 'admin_add';
            }

            // Garantir colunas que podem estar ausentes em instalações antigas
            try {
                $pdo->exec("ALTER TABLE team_coins_log ADD COLUMN IF NOT EXISTS balance_after INT NOT NULL DEFAULT 0");
                $pdo->exec("ALTER TABLE team_coins_log ADD COLUMN IF NOT EXISTS type VARCHAR(50) NULL");
            } catch (Exception $ignored) {}

            try {
                $pdo->beginTransaction();

                // Atualizar moedas do time
                $stmtUpdate = $pdo->prepare('UPDATE teams SET moedas = ? WHERE id = ?');
                $stmtUpdate->execute([$newBalance, $teamId]);

                // Registrar no log
                $stmtLog = $pdo->prepare('
                    INSERT INTO team_coins_log (team_id, amount, balance_after, reason, type)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $stmtLog->execute([$teamId, $logAmount, $newBalance, $reason, $logType]);

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'message' => sprintf(
                        '%s %d moedas %s %s %s. Novo saldo: %d',
                        $operation === 'add' ? 'Adicionadas' : 'Removidas',
                        $amount,
                        $operation === 'add' ? 'para' : 'de',
                        $team['city'],
                        $team['name'],
                        $newBalance
                    ),
                    'new_balance' => $newBalance
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Erro ao atualizar moedas']);
            }
            break;

        case 'tapas':
            // Definir quantidade de tapas de um time
            $teamId = $data['team_id'] ?? null;
            $amount = $data['amount'] ?? null;
            $operation = $data['operation'] ?? 'set'; // set | add | remove

            if (!$teamId || $amount === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Team ID e quantidade são obrigatórios']);
                exit;
            }

            $amount = (int)$amount;
            if ($amount < 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Quantidade deve ser maior ou igual a zero']);
                exit;
            }

            $stmtTeam = $pdo->prepare('SELECT id, user_id, city, name, COALESCE(tapas, 0) as tapas, COALESCE(tapas_used, 0) as tapas_used FROM teams WHERE id = ?');
            $stmtTeam->execute([$teamId]);
            $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);

            if (!$team) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
                exit;
            }

            $currentTapas = (int)($team['tapas'] ?? 0);
            $currentUsed = (int)($team['tapas_used'] ?? 0);
            if ($operation === 'add') {
                $newBalance = $currentTapas + $amount;
            } elseif ($operation === 'remove') {
                $newBalance = max(0, $currentTapas - $amount);
            } else {
                $newBalance = $amount;
            }

            $removed = 0;
            if ($currentTapas > 0 && $newBalance < $currentTapas) {
                $removed = $currentTapas - $newBalance;
            }

            try {
                if ($removed > 0) {
                    $stmtUpdate = $pdo->prepare('UPDATE teams SET tapas = ?, tapas_used = tapas_used + ? WHERE id = ?');
                    $stmtUpdate->execute([$newBalance, $removed, $teamId]);
                    $currentUsed += $removed;

                    // O sync com o antigo banco do games saiu na fusão: os tapas
                    // agora vivem só aqui, sem contraparte do outro lado.
                } else {
                    $stmtUpdate = $pdo->prepare('UPDATE teams SET tapas = ? WHERE id = ?');
                    $stmtUpdate->execute([$newBalance, $teamId]);
                }

                echo json_encode([
                    'success' => true,
                    'message' => sprintf('Tapas atualizados para %s %s: %d', $team['city'], $team['name'], $newBalance),
                    'new_balance' => $newBalance,
                    'tapas_used' => $currentUsed
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Erro ao atualizar tapas']);
            }
            break;

        case 'coins_bulk':
            // Adicionar moedas em massa para todos os times de uma liga
            $league = $data['league'] ?? null;
            $amount = $data['amount'] ?? null;
            $reason = $data['reason'] ?? 'Distribuição de moedas';

            if (!$league || $amount === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga e quantidade são obrigatórios']);
                exit;
            }

            if (!in_array(strtoupper($league), $validLeagues, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga inválida']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $league);

            $amount = (int)$amount;
            if ($amount <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Quantidade deve ser maior que zero']);
                exit;
            }

            // Garantir colunas que podem estar ausentes em instalações antigas
            try {
                $pdo->exec("ALTER TABLE team_coins_log ADD COLUMN IF NOT EXISTS balance_after INT NOT NULL DEFAULT 0");
                $pdo->exec("ALTER TABLE team_coins_log ADD COLUMN IF NOT EXISTS type VARCHAR(50) NULL");
            } catch (Exception $ignored) {}

            try {
                $pdo->beginTransaction();

                // Buscar todos os times da liga
                $stmtTeams = $pdo->prepare('SELECT id, COALESCE(moedas, 0) as moedas FROM teams WHERE league = ?');
                $stmtTeams->execute([strtoupper($league)]);
                $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

                $count = 0;
                foreach ($teams as $team) {
                    $newBalance = (int)$team['moedas'] + $amount;
                    
                    // Atualizar moedas do time
                    $stmtUpdate = $pdo->prepare('UPDATE teams SET moedas = ? WHERE id = ?');
                    $stmtUpdate->execute([$newBalance, $team['id']]);

                    // Registrar no log
                    $stmtLog = $pdo->prepare('
                        INSERT INTO team_coins_log (team_id, amount, balance_after, reason, type)
                        VALUES (?, ?, ?, ?, ?)
                    ');
                    $stmtLog->execute([$team['id'], $amount, $newBalance, $reason, 'admin_bulk']);
                    $count++;
                }

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'message' => sprintf('Adicionadas %d moedas para %d times da liga %s', $amount, $count, strtoupper($league)),
                    'teams_updated' => $count
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Erro ao distribuir moedas']);
            }
            break;

        case 'coins_by_standings':
            // Distribui moedas automaticamente pela classificação da temporada.
            // Fórmula: moedas = base + (rank-1) * passo. Por padrão o melhor colocado
            // (rank 1) recebe menos e vai crescendo (catch-up): base=2, passo=2 →
            // 1º=2, 2º=4, 3º=6...  'direction' pode inverter (best_most).
            $league = strtoupper((string)($data['league'] ?? ''));
            $base   = (int)($data['base'] ?? 2);
            $step   = (int)($data['step'] ?? 2);
            $direction = ($data['direction'] ?? 'worst_most'); // worst_most | best_most
            $reason = trim((string)($data['reason'] ?? '')) ?: 'Moedas por classificação';
            $apply  = !empty($data['apply']);

            if (!in_array($league, $validLeagues, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Liga inválida']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $league);
            if ($base < 0 || $step < 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Base e passo devem ser >= 0']);
                exit;
            }

            // Temporada com classificação mais recente da liga (ou a informada).
            $seasonId = (int)($data['season_id'] ?? 0);
            if ($seasonId <= 0) {
                $stmtS = $pdo->prepare('SELECT ss.season_id
                    FROM season_standings ss JOIN teams t ON t.id = ss.team_id
                    WHERE t.league = ? ORDER BY ss.season_id DESC LIMIT 1');
                $stmtS->execute([$league]);
                $seasonId = (int)($stmtS->fetchColumn() ?: 0);
            }
            if ($seasonId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Nenhuma classificação registrada para esta liga ainda.']);
                exit;
            }

            // Ranking da liga: melhor -> pior (vitórias, saldo, e posição como desempate).
            $stmtRank = $pdo->prepare("
                SELECT t.id AS team_id, CONCAT(t.city,' ',t.name) AS team_name,
                       COALESCE(t.moedas,0) AS moedas,
                       ss.wins, (ss.points_for - ss.points_against) AS pdiff, ss.position
                FROM season_standings ss
                JOIN teams t ON t.id = ss.team_id
                WHERE ss.season_id = ? AND t.league = ?
                ORDER BY ss.wins DESC, pdiff DESC, ss.position ASC");
            $stmtRank->execute([$seasonId, $league]);
            $ranked = $stmtRank->fetchAll(PDO::FETCH_ASSOC);
            if (!$ranked) {
                echo json_encode(['success' => false, 'error' => 'Sem times classificados nesta temporada para a liga.']);
                exit;
            }

            $n = count($ranked);
            $dist = [];
            foreach ($ranked as $i => $r) {
                // rank 1 = melhor. worst_most: pior recebe mais; best_most: melhor recebe mais.
                $stepsFromBase = ($direction === 'best_most') ? ($n - 1 - $i) : $i;
                $amount = $base + $stepsFromBase * $step;
                $dist[] = [
                    'rank'        => $i + 1,
                    'team_id'     => (int)$r['team_id'],
                    'team_name'   => $r['team_name'],
                    'current'     => (int)$r['moedas'],
                    'amount'      => $amount,
                    'new_balance' => (int)$r['moedas'] + $amount,
                ];
            }

            if (!$apply) {
                echo json_encode(['success' => true, 'preview' => true, 'season_id' => $seasonId, 'distribution' => $dist]);
                exit;
            }

            try {
                $pdo->exec("ALTER TABLE team_coins_log ADD COLUMN IF NOT EXISTS balance_after INT NOT NULL DEFAULT 0");
                $pdo->exec("ALTER TABLE team_coins_log ADD COLUMN IF NOT EXISTS type VARCHAR(50) NULL");
            } catch (Exception $ignored) {}

            try {
                $pdo->beginTransaction();
                $stmtUp  = $pdo->prepare('UPDATE teams SET moedas = ? WHERE id = ?');
                $stmtLog = $pdo->prepare('INSERT INTO team_coins_log (team_id, amount, balance_after, reason, admin_id, type)
                                          VALUES (?, ?, ?, ?, ?, ?)');
                $adminId = (int)($user['id'] ?? 0) ?: null;
                $count = 0;
                foreach ($dist as $d) {
                    if ($d['amount'] === 0) continue;
                    $stmtUp->execute([$d['new_balance'], $d['team_id']]);
                    $stmtLog->execute([$d['team_id'], $d['amount'], $d['new_balance'], $reason, $adminId, 'standings']);
                    $count++;
                }
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => sprintf('Distribuídas moedas por classificação para %d times da %s.', $count, $league), 'distribution' => $dist]);
            } catch (Exception $e) {
                $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Erro ao distribuir moedas por classificação']);
            }
            break;

        case 'update_user':
            // Gestão de usuários é exclusiva do Admin Geral (não interfere no admin de liga)
            if (!$isGlobalAdminApi) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Apenas admin geral']); exit; }
            $targetId = (int)($data['user_id'] ?? 0);
            if (!$targetId) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'user_id inválido']); exit; }

            $name  = trim((string)($data['name'] ?? ''));
            $email = trim((string)($data['email'] ?? ''));
            if ($name)  $pdo->prepare("UPDATE users SET name = ? WHERE id = ?")->execute([$name, $targetId]);
            if ($email) $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$email, $targetId]);

            // Telefone: guarda só dígitos com DDI, que é a forma que o bot usa
            // pra marcar a pessoa no grupo. Campo vazio limpa o cadastro — é a
            // única forma de tirar um número errado, então não pode ser
            // confundido com "não veio no payload".
            if (array_key_exists('phone', $data)) {
                $bruto = trim((string)$data['phone']);
                if ($bruto === '') {
                    $pdo->prepare("UPDATE users SET phone = NULL WHERE id = ?")->execute([$targetId]);
                } else {
                    $normalizado = normalizeBrazilianPhone($bruto);
                    if ($normalizado === null) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'error' => 'Telefone inválido. Use DDD + número, ex: (11) 98765-4321.']);
                        exit;
                    }
                    $pdo->prepare("UPDATE users SET phone = ? WHERE id = ?")->execute([$normalizado, $targetId]);
                }
            }

            // Liga do usuário e liga do time são campos independentes no modal de
            // Gestão (podem divergir por bug de dados antigo) — cada um só é
            // gravado se veio preenchido, na tabela correspondente. Quando o
            // admin usa o campo único (mesma liga nos dois lados), o front manda
            // os dois parâmetros com o mesmo valor, então os dois acabam mudando
            // juntos mesmo assim.
            $userLeague = isset($data['user_league']) ? strtoupper(trim((string)$data['user_league'])) : '';
            if ($userLeague !== '' && in_array($userLeague, $validLeagues, true)) {
                $pdo->prepare("UPDATE users SET league = ? WHERE id = ?")
                    ->execute([$userLeague, $targetId]);
            }

            // Trocar a liga do time dispara o trigger de congelamento do Hall da Fama.
            $teamLeague = isset($data['team_league']) ? strtoupper(trim((string)$data['team_league'])) : '';
            if ($teamLeague !== '' && in_array($teamLeague, $validLeagues, true) && !empty($data['team_id'])) {
                $pdo->prepare("UPDATE teams SET league = ? WHERE id = ? AND user_id = ?")
                    ->execute([$teamLeague, (int)$data['team_id'], $targetId]);
            }

            $teamName = trim((string)($data['team_name'] ?? ''));
            if ($teamName !== '' && !empty($data['team_id'])) {
                $pdo->prepare("UPDATE teams SET name = ? WHERE id = ? AND user_id = ?")
                    ->execute([$teamName, (int)$data['team_id'], $targetId]);
            }

            $teamCity = trim((string)($data['team_city'] ?? ''));
            if ($teamCity !== '' && !empty($data['team_id'])) {
                $pdo->prepare("UPDATE teams SET city = ? WHERE id = ? AND user_id = ?")
                    ->execute([$teamCity, (int)$data['team_id'], $targetId]);
            }

            if (isset($data['team_photo']) && $data['team_photo'] !== '' && !empty($data['team_id'])) {
                $rawPhoto = trim((string)$data['team_photo']);
                if (str_starts_with($rawPhoto, 'data:image/')) {
                    try {
                        $commaPos = strpos($rawPhoto, ',');
                        $meta     = substr($rawPhoto, 0, $commaPos);
                        $b64      = substr($rawPhoto, $commaPos + 1);
                        $ext = 'png';
                        if (preg_match('/data:image\/(jpeg|jpg)/i', $meta)) $ext = 'jpg';
                        elseif (preg_match('/data:image\/webp/i', $meta)) $ext = 'webp';
                        $binary = base64_decode($b64);
                        if ($binary === false) throw new Exception('decode fail');
                        $dirFs = __DIR__ . '/../img/teams';
                        if (!is_dir($dirFs)) @mkdir($dirFs, 0775, true);
                        $filename = 'team-' . (int)$data['team_id'] . '-' . time() . '.' . $ext;
                        if (file_put_contents($dirFs . '/' . $filename, $binary) === false) throw new Exception('write fail');
                        $rawPhoto = '/img/teams/' . $filename;
                    } catch (Exception $e) {
                        $rawPhoto = '';
                    }
                }
                if ($rawPhoto !== '') {
                    $pdo->prepare("UPDATE teams SET photo_url = ? WHERE id = ? AND user_id = ?")
                        ->execute([$rawPhoto, (int)$data['team_id'], $targetId]);
                }
            }

            echo json_encode(['success' => true]);
            break;

        case 'set_user_league_admin':
            if (!$isGlobalAdminApi) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Apenas admin global']); exit; }
            $targetId = (int)($data['user_id'] ?? 0);
            $leagues  = array_values(array_filter(array_map('strtoupper', (array)($data['leagues'] ?? [])), fn($l) => in_array($l, $validLeagues, true)));
            if (!$targetId) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'user_id inválido']); exit; }

            $pdo->prepare("DELETE FROM league_admins WHERE user_id = ?")->execute([$targetId]);
            foreach ($leagues as $lg) {
                $pdo->prepare("INSERT IGNORE INTO league_admins (user_id, league) VALUES (?, ?)")->execute([$targetId, $lg]);
            }
            echo json_encode(['success' => true, 'leagues' => $leagues]);
            break;

        case 'set_global_admin':
            // Admin GERAL do sistema (users.user_type). Não mexe em league_admins.
            if (!$isGlobalAdminApi) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Apenas admin global']); exit; }
            $targetId = (int)($data['user_id'] ?? 0);
            if (!$targetId) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'user_id inválido']); exit; }
            $enable = !empty($data['is_admin']);
            // Impede o admin de remover o próprio acesso global (evita se trancar de fora)
            if (!$enable && $targetId === (int)($user['id'] ?? 0)) {
                http_response_code(400); echo json_encode(['success' => false, 'error' => 'Você não pode remover o próprio admin geral.']); exit;
            }
            $pdo->prepare("UPDATE users SET user_type = ? WHERE id = ?")->execute([$enable ? 'admin' : 'jogador', $targetId]);
            echo json_encode(['success' => true, 'user_type' => $enable ? 'admin' : 'jogador']);
            break;

        case 'create_gm':
            // Cria um GM novo direto pela liga: usuário + time, com senha padrão,
            // e manda um e-mail de boas-vindas com o link de login. Admin geral só.
            if (!$isGlobalAdminApi) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Apenas admin geral']); exit; }

            $gmName = trim((string)($data['name'] ?? ''));
            $gmEmail = strtolower(trim((string)($data['email'] ?? '')));
            $gmLeague = strtoupper(trim((string)($data['league'] ?? '')));
            $teamName = trim((string)($data['team_name'] ?? ''));
            $teamCity = trim((string)($data['team_city'] ?? ''));
            $nbaTeamId = (int)($data['nba_team_id'] ?? 0);
            $isRookieLeague = $gmLeague === 'ROOKIE';

            if ($gmName === '' || $gmEmail === '' || (!$isRookieLeague && ($teamName === '' || $teamCity === ''))) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Nome do GM, e-mail, nome do time e cidade são obrigatórios.']);
                exit;
            }
            if (!filter_var($gmEmail, FILTER_VALIDATE_EMAIL)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'E-mail inválido.']);
                exit;
            }
            if (!in_array($gmLeague, $validLeagues, true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Liga inválida.']);
                exit;
            }

            // ROOKIE não tem time fictício — é sempre um time real da NBA,
            // igual ao cadastro por lista de espera (api/register.php).
            $nbaTeam = null;
            if ($isRookieLeague) {
                require_once dirname(__DIR__) . '/backend/nba_teams.php';
                $nbaTeam = $nbaTeamId ? nbaTeamById($nbaTeamId) : null;
                if (!$nbaTeam) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'error' => 'Escolha um time da NBA válido.']);
                    exit;
                }
                ensureNbaTeamColumn($pdo);
                $stmtTaken = $pdo->prepare('SELECT id FROM teams WHERE nba_team_id = ? LIMIT 1');
                $stmtTaken->execute([$nbaTeamId]);
                if ($stmtTaken->fetch()) {
                    http_response_code(409);
                    echo json_encode(['success' => false, 'error' => 'Esse time da NBA já foi escolhido por outro GM.']);
                    exit;
                }
            }

            $stmtEmailChk = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmtEmailChk->execute([$gmEmail]);
            if ($stmtEmailChk->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'Já existe uma conta com esse e-mail.']);
                exit;
            }

            $defaultPassword = 'fbabrasil123';
            $hash = password_hash($defaultPassword, PASSWORD_BCRYPT);
            $verificationToken = bin2hex(random_bytes(16));

            try {
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO users (name, email, password_hash, user_type, league, verification_token, approved) VALUES (?, ?, ?, 'jogador', ?, ?, 1)")
                    ->execute([$gmName, $gmEmail, $hash, $gmLeague, $verificationToken]);
                $newUserId = (int)$pdo->lastInsertId();

                if ($isRookieLeague) {
                    $pdo->prepare("INSERT INTO teams (user_id, league, conference, name, city, mascot, photo_url, nba_team_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([
                            $newUserId, $gmLeague, $nbaTeam['conference'], $nbaTeam['name'], $nbaTeam['city'], '',
                            nbaTeamLogoUrl($nbaTeam['id']), $nbaTeam['id'],
                        ]);
                } else {
                    $pdo->prepare("INSERT INTO teams (user_id, league, name, city, mascot) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$newUserId, $gmLeague, $teamName, $teamCity, $teamName]);
                }
                $newTeamId = (int)$pdo->lastInsertId();

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[create_gm] ' . $e->getMessage());
                $msg = ($isRookieLeague && str_contains($e->getMessage(), 'uniq_teams_nba_team_id'))
                    ? 'Esse time da NBA acabou de ser escolhido por outro GM.'
                    : 'Erro ao criar o GM.';
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => $msg]);
                exit;
            }

            $emailSent = false;
            try {
                $emailSent = sendGmWelcomeEmail($gmEmail, $gmName, $defaultPassword, $gmLeague);
            } catch (Exception $e) {
                error_log('[create_gm] envio de e-mail falhou: ' . $e->getMessage());
            }

            echo json_encode(['success' => true, 'user_id' => $newUserId, 'team_id' => $newTeamId, 'email_sent' => $emailSent]);
            break;

        case 'reset_user_password':
            if (!$isGlobalAdminApi) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Apenas admin global']); exit; }
            $targetId = (int)($data['user_id'] ?? 0);
            if (!$targetId) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'user_id inválido']); exit; }

            $newPassword = bin2hex(random_bytes(5));
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $targetId]);
            echo json_encode(['success' => true, 'new_password' => $newPassword]);
            break;

        case 'toggle_maintenance':
            // Só admin GERAL pode ligar/desligar — admin de liga nem passa pela checagem de bloqueio.
            if (!$isGlobalAdminApi) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Apenas admin geral']); exit; }
            ensureMaintenanceModeTable($pdo);
            $enable = !empty($data['enabled']);
            $msg = trim((string)($data['message'] ?? ''));
            if ($enable) {
                $pdo->prepare("UPDATE maintenance_mode SET enabled = 1, message = ?, enabled_by = ?, enabled_at = NOW() WHERE id = 1")
                    ->execute([$msg !== '' ? $msg : null, $user['id']]);
            } else {
                $pdo->prepare("UPDATE maintenance_mode SET enabled = 0 WHERE id = 1")->execute();
            }
            echo json_encode(['success' => true, 'enabled' => $enable]);
            break;

        case 'set_team_avisos':
            $teamId     = (int)($data['team_id'] ?? 0);
            $target     = (int)($data['count']   ?? 0);
            $league     = $data['league'] ?? null;
            if (!$teamId || !$league || $target < 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
                break;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $league);
            // IDs atuais não revertidos, ordem crescente (mais antigos primeiro)
            $stmtCur = $pdo->prepare("SELECT id FROM team_punishments WHERE team_id = ? AND type = 'AVISO_TRADE' AND reverted_at IS NULL ORDER BY id ASC");
            $stmtCur->execute([$teamId]);
            $currentIds = $stmtCur->fetchAll(PDO::FETCH_COLUMN);
            $current = count($currentIds);

            if ($target > $current) {
                $ins = $pdo->prepare("INSERT INTO team_punishments (team_id, league, type, motive, punishment_label, effect_type, notes, season_scope, created_by) VALUES (?, ?, 'AVISO_TRADE', 'Ajuste manual pelo admin', 'Aviso de trade (SERASA)', 'AVISO_TRADE', 'Ajuste manual pelo admin', 'current', ?)");
                for ($i = 0; $i < $target - $current; $i++) {
                    $ins->execute([$teamId, $league, $user['id']]);
                }
            } elseif ($target < $current) {
                // Reverte os mais recentes (mantém os mais antigos)
                $toRevert = array_slice($currentIds, $target);
                $rev = $pdo->prepare("UPDATE team_punishments SET reverted_at = NOW(), reverted_by = ? WHERE id = ?");
                foreach ($toRevert as $pid) { $rev->execute([$user['id'], $pid]); }
            }

            $stmtNew = $pdo->prepare("SELECT COUNT(*) FROM team_punishments WHERE team_id = ? AND type = 'AVISO_TRADE' AND reverted_at IS NULL");
            $stmtNew->execute([$teamId]);
            echo json_encode(['success' => true, 'count' => (int)$stmtNew->fetchColumn()]);
            break;

        case 'check_overdue_trades':
            $league = $data['league'] ?? null;
            if (!$league) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Liga obrigatória']); break; }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $league);

            // Trades pendentes há mais de 24h sem aviso já registrado para esse trade
            $stmtOverdue = $pdo->prepare("
                SELECT t.id, t.to_team_id, t.league
                FROM trades t
                WHERE t.league = ?
                  AND t.status = 'pending'
                  AND t.created_at < NOW() - INTERVAL 24 HOUR
                  AND NOT EXISTS (
                      SELECT 1 FROM team_punishments tp
                      WHERE tp.team_id = t.to_team_id
                        AND tp.type = 'AVISO_TRADE'
                        AND tp.notes LIKE CONCAT('%Trade #', t.id, '%')
                  )
            ");
            $stmtOverdue->execute([$league]);
            $overdueTrades = $stmtOverdue->fetchAll(PDO::FETCH_ASSOC);

            $generated = 0;
            $stmtIns = $pdo->prepare("
                INSERT INTO team_punishments (team_id, league, type, motive, punishment_label, effect_type, notes, season_scope, created_by)
                VALUES (?, ?, 'AVISO_TRADE', ?, 'Aviso de trade (SERASA)', 'AVISO_TRADE', ?, 'current', ?)
            ");
            foreach ($overdueTrades as $tr) {
                $desc = "Trade #{$tr['id']} pendente há mais de 24h sem resposta";
                $stmtIns->execute([$tr['to_team_id'], $tr['league'], $desc, $desc, $user['id']]);
                $generated++;
            }

            echo json_encode(['success' => true, 'avisos_gerados' => $generated]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    exit;
}

// DELETE - Deletar dados
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    
    switch ($action) {
        case 'hall_of_fame':
            $body = json_decode(file_get_contents('php://input'), true);
            $id = isset($body['id']) ? (int)$body['id'] : (int)$id;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ID obrigatorio']);
                exit;
            }
            ensureHallOfFameTable($pdo);
            $stmtHofDelRow = $pdo->prepare('SELECT league FROM hall_of_fame WHERE id = ?');
            $stmtHofDelRow->execute([$id]);
            $hofDelRow = $stmtHofDelRow->fetch(PDO::FETCH_ASSOC);
            if (!$hofDelRow) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Registro não encontrado']);
                exit;
            }
            // Escopa pela liga do registro; registros históricos sem liga definida
            // ficam restritos ao admin global.
            if (!empty($hofDelRow['league'])) {
                requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $hofDelRow['league']);
            } elseif (!$isGlobalAdminApi) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Você não tem permissão para administrar esta liga.']);
                exit;
            }
            $stmt = $pdo->prepare('DELETE FROM hall_of_fame WHERE id = ?');
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;

        case 'player':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Player ID obrigatório']);
                exit;
            }

            $stmtDelPlayerScope = $pdo->prepare('SELECT t.league FROM players p JOIN teams t ON t.id = p.team_id WHERE p.id = ?');
            $stmtDelPlayerScope->execute([$id]);
            $delPlayerLeague = $stmtDelPlayerScope->fetchColumn();
            if ($delPlayerLeague === false) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Jogador não encontrado']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $delPlayerLeague);

            // Snapshot player in trade history before permanent deletion
            try {
                $stmtPr = $pdo->prepare("SELECT id, name, position, age, ovr FROM players WHERE id = ?");
                $stmtPr->execute([$id]);
                $pr = $stmtPr->fetch(PDO::FETCH_ASSOC);
                if ($pr) {
                    $pdo->prepare(
                        "UPDATE trade_items
                         SET player_name = COALESCE(player_name, ?),
                             player_position = COALESCE(player_position, ?),
                             player_age = COALESCE(player_age, ?),
                             player_ovr = COALESCE(player_ovr, ?),
                             player_id = NULL
                         WHERE player_id = ?"
                    )->execute([$pr['name'], $pr['position'], $pr['age'], $pr['ovr'], $id]);
                    $pdo->prepare(
                        "UPDATE multi_trade_items
                         SET player_name = COALESCE(player_name, ?),
                             player_position = COALESCE(player_position, ?),
                             player_age = COALESCE(player_age, ?),
                             player_ovr = COALESCE(player_ovr, ?),
                             player_id = NULL
                         WHERE player_id = ?"
                    )->execute([$pr['name'], $pr['position'], $pr['age'], $pr['ovr'], $id]);
                    try {
                        $pdo->prepare(
                            "UPDATE leilao_jogadores SET temp_name = COALESCE(temp_name, ?) WHERE player_id = ?"
                        )->execute([$pr['name'], $id]);
                    } catch (Exception $leilaoSnap) {}
                }
            } catch (Exception $snapshotErr) {
                error_log('[admin delete player] snapshot failed: ' . $snapshotErr->getMessage());
            }

            $stmt = $pdo->prepare('DELETE FROM players WHERE id = ?');
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Jogador deletado']);
            break;

        case 'pick':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Pick ID obrigatório']);
                exit;
            }

            $stmtDelPickScope = $pdo->prepare('SELECT t.league FROM picks p JOIN teams t ON t.id = p.team_id WHERE p.id = ?');
            $stmtDelPickScope->execute([$id]);
            $delPickLeague = $stmtDelPickScope->fetchColumn();
            if ($delPickLeague === false) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Pick não encontrada']);
                exit;
            }
            requireLeagueScope($isGlobalAdminApi, $apiAdminLeagues, $delPickLeague);

            $stmt = $pdo->prepare('DELETE FROM picks WHERE id = ?');
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Pick deletado']);
            break;

        case 'user':
            // Gestão de usuários é exclusiva do Admin Geral (não interfere no admin de liga)
            if (!$isGlobalAdminApi) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas admin geral']);
                exit;
            }
            $targetId = (int)$id;
            if (!$targetId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'user_id inválido']);
                exit;
            }

            $stmtU = $pdo->prepare("SELECT id, league FROM users WHERE id = ?");
            $stmtU->execute([$targetId]);
            $targetUser = $stmtU->fetch(PDO::FETCH_ASSOC);
            if (!$targetUser) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
                exit;
            }

            // Só permite apagar usuário que não tem time — evita apagar dono de
            // time (e o time junto) por engano.
            $stmtTeamChk = $pdo->prepare("SELECT id FROM teams WHERE user_id = ? LIMIT 1");
            $stmtTeamChk->execute([$targetId]);
            if ($stmtTeamChk->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Esse usuário tem time — não pode ser apagado por aqui']);
                exit;
            }

            try {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM league_admins WHERE user_id = ?")->execute([$targetId]);
                try {
                    $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id = ?")->execute([$targetId]);
                } catch (Exception $e) {}
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$targetId]);
                $pdo->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Erro ao apagar usuário']);
            }
            break;

        case 'team_and_owner':
            // Apaga um time inteiro e o usuário/GM dono junto — caminho que
            // 'user' recusa de propósito (comentário logo acima). Só admin geral.
            if (!$isGlobalAdminApi) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Apenas admin geral']);
                exit;
            }
            $teamId = (int)($_GET['team_id'] ?? 0);
            if (!$teamId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'team_id inválido']);
                exit;
            }

            $stmtTeamRow = $pdo->prepare("SELECT id, user_id, league FROM teams WHERE id = ?");
            $stmtTeamRow->execute([$teamId]);
            $teamRow = $stmtTeamRow->fetch(PDO::FETCH_ASSOC);
            if (!$teamRow) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
                exit;
            }
            $ownerId = (int)$teamRow['user_id'];

            try {
                $pdo->beginTransaction();

                // Jogadores do time: preserva nome/posição/idade/OVR no histórico de
                // trocas e no leilão antes de apagar (mesmo padrão já usado em
                // DELETE admin.php?action=player, só que em lote pro time inteiro).
                $stmtPlayers = $pdo->prepare("SELECT id, name, position, age, ovr FROM players WHERE team_id = ?");
                $stmtPlayers->execute([$teamId]);
                $teamPlayers = $stmtPlayers->fetchAll(PDO::FETCH_ASSOC);
                foreach ($teamPlayers as $pr) {
                    try {
                        $pdo->prepare(
                            "UPDATE trade_items
                             SET player_name = COALESCE(player_name, ?), player_position = COALESCE(player_position, ?),
                                 player_age = COALESCE(player_age, ?), player_ovr = COALESCE(player_ovr, ?), player_id = NULL
                             WHERE player_id = ?"
                        )->execute([$pr['name'], $pr['position'], $pr['age'], $pr['ovr'], $pr['id']]);
                        $pdo->prepare(
                            "UPDATE multi_trade_items
                             SET player_name = COALESCE(player_name, ?), player_position = COALESCE(player_position, ?),
                                 player_age = COALESCE(player_age, ?), player_ovr = COALESCE(player_ovr, ?), player_id = NULL
                             WHERE player_id = ?"
                        )->execute([$pr['name'], $pr['position'], $pr['age'], $pr['ovr'], $pr['id']]);
                    } catch (Exception $e) {}
                    try {
                        $pdo->prepare("UPDATE leilao_jogadores SET temp_name = COALESCE(temp_name, ?) WHERE player_id = ?")
                            ->execute([$pr['name'], $pr['id']]);
                    } catch (Exception $e) {}
                }
                $pdo->prepare("DELETE FROM players WHERE team_id = ?")->execute([$teamId]);

                // Picks: só as que o time TEM hoje (dono atual) somem com ele. Uma pick
                // que ele mandou pra outro time em troca (original_team_id = este time,
                // mas quem tem hoje é outro) precisa ficar — só que original_team_id tem
                // FK com ON DELETE CASCADE pra teams (confirmado testando localmente: sem
                // isso, apagar o time apagava essas picks também, por baixo dos panos).
                // Reatribui a "origem" pro dono atual antes de apagar o time, pra a FK não
                // cascatear em cima delas.
                $pdo->prepare("UPDATE picks SET original_team_id = team_id WHERE original_team_id = ? AND team_id != ?")
                    ->execute([$teamId, $teamId]);
                $pdo->prepare("DELETE FROM picks WHERE team_id = ?")->execute([$teamId]);

                // Trocas 1x1 e itens associados
                try {
                    $pdo->prepare("DELETE FROM trade_items WHERE trade_id IN (SELECT id FROM trades WHERE from_team_id = ? OR to_team_id = ?)")
                        ->execute([$teamId, $teamId]);
                    $pdo->prepare("DELETE FROM trades WHERE from_team_id = ? OR to_team_id = ?")->execute([$teamId, $teamId]);
                } catch (Exception $e) {}

                // Trocas multi-times e itens associados
                try {
                    $pdo->prepare("DELETE FROM multi_trade_items WHERE from_team_id = ? OR to_team_id = ?")->execute([$teamId, $teamId]);
                    $pdo->prepare("DELETE FROM multi_trade_teams WHERE team_id = ?")->execute([$teamId]);
                    $pdo->prepare("DELETE FROM multi_trades WHERE created_by_team_id = ?")->execute([$teamId]);
                } catch (Exception $e) {}

                try { $pdo->prepare("DELETE FROM season_standings WHERE team_id = ?")->execute([$teamId]); } catch (Exception $e) {}
                try { $pdo->prepare("DELETE FROM team_ranking_points WHERE team_id = ?")->execute([$teamId]); } catch (Exception $e) {}
                try { $pdo->prepare("DELETE FROM team_tactics WHERE team_id = ?")->execute([$teamId]); } catch (Exception $e) {}
                try {
                    $pdo->prepare("DELETE FROM initdraft_order WHERE team_id = ? OR original_team_id = ?")->execute([$teamId, $teamId]);
                    $pdo->prepare("UPDATE initdraft_order SET traded_from_team_id = NULL WHERE traded_from_team_id = ?")->execute([$teamId]);
                } catch (Exception $e) {}
                try { $pdo->prepare("UPDATE draft_pool SET drafted_by_team_id = NULL WHERE drafted_by_team_id = ?")->execute([$teamId]); } catch (Exception $e) {}
                try {
                    $pdo->prepare("DELETE fao FROM free_agent_offers fao WHERE fao.team_id = ?")->execute([$teamId]);
                    $pdo->prepare("UPDATE free_agents SET original_team_id = NULL WHERE original_team_id = ?")->execute([$teamId]);
                } catch (Exception $e) {}

                $pdo->prepare("DELETE FROM league_admins WHERE user_id = ?")->execute([$ownerId]);
                try {
                    $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id = ?")->execute([$ownerId]);
                } catch (Exception $e) {}

                $pdo->prepare("DELETE FROM teams WHERE id = ?")->execute([$teamId]);
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$ownerId]);

                $pdo->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[team_and_owner] ' . $e->getMessage());
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Erro ao apagar o time.']);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não permitido']);
