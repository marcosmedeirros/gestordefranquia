<?php
/**
 * API do Leilao de Jogadores
 * Sistema de trocas via leilao
 */

require_once __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/push.php';
require_once __DIR__ . '/../backend/salary_cap.php'; // conta do cap na proposta
require_once __DIR__ . '/../backend/leilao_bot.php'; // proposta pelo WhatsApp
require_once __DIR__ . '/../backend/leilao_decisao.php'; // aceitar/recusar — o bot usa as mesmas

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Nao autorizado']);
    exit;
}

$user_id = $_SESSION['user_id'];
$team_id = $_SESSION['team_id'] ?? null;
$league_id = $_SESSION['current_league_id'] ?? null;

$pdo = db();
// Admin global (user_type='admin') OU admin da liga via league_admins — mesmo critério
// usado na página leilao.php, em api/market.php e api/draft.php.
$is_admin = hasAdminAccess($pdo, (int)$user_id);
ensureTempPlayerColumns($pdo);
ensureAuctionTableCompat($pdo);
ensureProposalPicksTable($pdo);
ensureProposalObsColumn($pdo);
ensurePersonalizedProposalSupport($pdo);
ensureLeilaoMensagensTable($pdo);

function teamColumnExists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM teams LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch();
}

function tableColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch();
}

function getLeagueNameById(PDO $pdo, ?int $league_id): ?string
{
    if (!$league_id) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT name FROM leagues WHERE id = ? LIMIT 1');
        $stmt->execute([$league_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && !empty($row['name']) ? (string)$row['name'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Data de corte da sprint em andamento. leilao_jogadores não tem season_id nem
 * sprint_id, então o critério é a data de início da sprint ativa da liga:
 * leilão criado antes disso é de um ciclo já encerrado e não deve mais aparecer.
 * Devolve null quando a liga não tem sprint ativa (aí não filtra por data).
 */
function corteDaSprintDoLeilao(PDO $pdo, ?int $league_id): ?string
{
    static $cache = [];
    if (!$league_id) return null;
    if (array_key_exists($league_id, $cache)) return $cache[$league_id];

    $nome = getLeagueNameById($pdo, $league_id);
    $corte = null;
    if ($nome) {
        try {
            $stmt = $pdo->prepare("SELECT start_date FROM sprints
                                   WHERE league = ? AND status = 'active'
                                   ORDER BY id DESC LIMIT 1");
            $stmt->execute([strtoupper(trim($nome))]);
            $d = $stmt->fetchColumn();
            $corte = $d ? $d . ' 00:00:00' : null;
        } catch (Throwable $e) {
            $corte = null;
        }
    }
    $cache[$league_id] = $corte;
    return $corte;
}

function getCurrentSeasonYear(PDO $pdo, ?int $league_id): ?int
{
    $leagueName = getLeagueNameById($pdo, $league_id);
    if (!$leagueName) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT year FROM seasons WHERE league = ? AND status != 'completed' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$leagueName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['year'])) {
            return null;
        }
        return (int)$row['year'];
    } catch (Throwable $e) {
        return null;
    }
}

// Também entra aqui quando o time já está na sessão mas a liga não: todo o
// escopo do leilão (liga + sprint) depende de $league_id, e sem ele as listas
// sairiam vazias.
if (!$team_id || !$league_id) {
    $select = ['id'];
    $hasLeagueId = teamColumnExists($pdo, 'league_id');
    $hasLeagueName = teamColumnExists($pdo, 'league');
    if ($hasLeagueId) {
        $select[] = 'league_id';
    }
    if ($hasLeagueName) {
        $select[] = 'league';
    }
    $stmt = $pdo->prepare("SELECT " . implode(', ', $select) . " FROM teams WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $teamRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($teamRow) {
        if (!$team_id) $team_id = (int) $teamRow['id'];
        if (!$league_id) {
            if ($hasLeagueId && !empty($teamRow['league_id'])) {
                $league_id = (int) $teamRow['league_id'];
            } elseif ($hasLeagueName && !empty($teamRow['league'])) {
                $stmt = $pdo->prepare("SELECT id FROM leagues WHERE name = ? LIMIT 1");
                $stmt->execute([$teamRow['league']]);
                $leagueRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($leagueRow && !empty($leagueRow['id'])) {
                    $league_id = (int) $leagueRow['id'];
                }
            }
        }
    }
}

function playerOvrColumn(PDO $pdo): string
{
    $stmt = $pdo->query("SHOW COLUMNS FROM players LIKE 'ovr'");
    return $stmt && $stmt->rowCount() > 0 ? 'ovr' : 'overall';
}

function ensureTempPlayerColumns(PDO $pdo): void
{
    try {
        $pdo->exec("
            ALTER TABLE leilao_jogadores 
            ADD COLUMN IF NOT EXISTS temp_name VARCHAR(120) NULL,
            ADD COLUMN IF NOT EXISTS temp_position VARCHAR(10) NULL,
            ADD COLUMN IF NOT EXISTS temp_age INT NULL,
            ADD COLUMN IF NOT EXISTS temp_ovr INT NULL,
            ADD COLUMN IF NOT EXISTS is_temp_player TINYINT(1) DEFAULT 0
        ");
    } catch (Throwable $e) {
        // Ignorar falhas de ALTER para compatibilidade
    }
}

function ensureAuctionTableCompat(PDO $pdo): void
{
    try {
        // Permitir player_id e team_id nulos para jogadores criados sem time
        $pdo->exec("ALTER TABLE leilao_jogadores MODIFY COLUMN player_id INT NULL");
    } catch (Throwable $e) { /* ignore */ }
    try {
        $pdo->exec("ALTER TABLE leilao_jogadores MODIFY COLUMN team_id INT NULL");
    } catch (Throwable $e) { /* ignore */ }
    try {
        // Incluir status 'pendente' e definir default como 'pendente'
        $pdo->exec("ALTER TABLE leilao_jogadores MODIFY COLUMN status ENUM('pendente','ativo','finalizado','cancelado') DEFAULT 'pendente'");
    } catch (Throwable $e) { /* ignore */ }
}

function ensureProposalPicksTable(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS leilao_proposta_picks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            proposta_id INT NOT NULL,
            pick_id INT NOT NULL,
            INDEX idx_proposta_pick (proposta_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ignore */ }
}

function ensureProposalObsColumn(PDO $pdo): void
{
    try {
        if (!tableColumnExists($pdo, 'leilao_propostas', 'obs')) {
            $pdo->exec('ALTER TABLE leilao_propostas ADD COLUMN obs TEXT NULL');
        }
    } catch (Throwable $e) { /* ignore */ }
}

function ensurePersonalizedProposalSupport(PDO $pdo): void {
    try {
        if (!tableColumnExists($pdo, 'leilao_propostas', 'is_personalized')) {
            $pdo->exec('ALTER TABLE leilao_propostas ADD COLUMN is_personalized TINYINT(1) DEFAULT 0');
        }
    } catch (Throwable $e) {}
    try {
        if (!tableColumnExists($pdo, 'leilao_proposta_picks', 'swap_type')) {
            $pdo->exec('ALTER TABLE leilao_proposta_picks ADD COLUMN swap_type VARCHAR(10) NULL');
        }
    } catch (Throwable $e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS leilao_proposta_extra_players (
            id INT AUTO_INCREMENT PRIMARY KEY,
            proposta_id INT NOT NULL,
            player_id INT NOT NULL,
            INDEX idx_ep (proposta_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS leilao_proposta_extra_picks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            proposta_id INT NOT NULL,
            pick_id INT NOT NULL,
            swap_type VARCHAR(10) NULL,
            INDEX idx_epk (proposta_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
    try {
        if (!tableColumnExists($pdo, 'leilao_proposta_extra_picks', 'swap_type')) {
            $pdo->exec('ALTER TABLE leilao_proposta_extra_picks ADD COLUMN swap_type VARCHAR(10) NULL');
        }
    } catch (Throwable $e) {}
}

function ensureLeilaoMensagensTable(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS leilao_mensagens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            leilao_id INT NOT NULL,
            team_id INT NOT NULL,
            tipo ENUM('text','proposal') NOT NULL DEFAULT 'text',
            texto TEXT NULL,
            proposta_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_leilao_mensagens (leilao_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ignore */ }
}

function criarJogadorParaLeilao(PDO $pdo, array $new_player, int $user_id, ?int $league_id): array
{
    $name = trim((string)($new_player['name'] ?? ''));
    $position = trim((string)($new_player['position'] ?? ''));
    $age = (int)($new_player['age'] ?? 0);
    $ovr = (int)($new_player['ovr'] ?? 0);

    if (!$name || !$position || !$age || !$ovr) {
        throw new InvalidArgumentException('Dados do novo jogador incompletos');
    }

    return [
        'player_id' => null,
        'team_id' => null,
        'name' => $name,
        'position' => $position,
        'age' => $age,
        'ovr' => $ovr
    ];
}

// GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'listar_ativos':
            listarLeiloesAtivos($pdo, $league_id, $team_id);
            break;
        case 'listar_admin':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            $filterLeagueId = isset($_GET['league_id']) ? (int)$_GET['league_id'] : null;
            if (!$filterLeagueId && !empty($_GET['league'])) {
                $stmtLg = $pdo->prepare("SELECT id FROM leagues WHERE name = ? LIMIT 1");
                $stmtLg->execute([strtoupper($_GET['league'])]);
                $filterLeagueId = (int)($stmtLg->fetchColumn() ?: 0) ?: null;
            }
            listarLeiloesAdmin($pdo, $filterLeagueId);
            break;
        case 'slots_leilao':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            slotsDeLeilao($pdo, strtoupper(trim((string)($_GET['league'] ?? ''))));
            break;
        case 'listar_temp':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            $tempLeagueId = isset($_GET['league_id']) ? (int)$_GET['league_id'] : null;
            listarLeiloesTemporarios($pdo, $tempLeagueId ?: null);
            break;
        case 'minhas_propostas':
            minhasPropostas($pdo, $team_id, $league_id);
            break;
        case 'propostas_recebidas':
            propostasRecebidas($pdo, $team_id, $league_id);
            break;
        case 'ver_propostas':
            $leilao_id = $_GET['leilao_id'] ?? 0;
            verPropostas($pdo, $leilao_id, $team_id, $is_admin);
            break;
        case 'ver_propostas_enviadas':
            $leilao_id = $_GET['leilao_id'] ?? 0;
            verPropostasEnviadas($pdo, $leilao_id, $team_id);
            break;
        case 'historico':
            // Só o admin escolhe a liga; para o GM é sempre a dele — antes, um
            // league_id na URL (ou a ausência dele) trazia leilão de outra liga.
            $league_id_param = $league_id;
            if ($is_admin && !empty($_GET['league_id'])) {
                $league_id_param = (int)$_GET['league_id'];
            }
            historicoLeiloes($pdo, $league_id_param);
            break;
        case 'cap_leilao':
            capDoLeilao($pdo, (int)($_GET['leilao_id'] ?? 0), $team_id);
            break;
        case 'minhas_picks':
            minhasPicks($pdo, $team_id, $league_id);
            break;
        case 'seller_items':
            $seller_team_id = intval($_GET['seller_team_id'] ?? 0);
            sellerItems($pdo, $seller_team_id, $league_id);
            break;
        case 'league_teams':
            leagueTeams($pdo, $league_id, $user_id, $is_admin, $_GET['league'] ?? null);
            break;
        case 'listar_mensagens':
            $leilao_id = $_GET['leilao_id'] ?? 0;
            listarMensagensLeilao($pdo, $leilao_id, $team_id, $is_admin);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Acao nao reconhecida']);
    }
    exit;
}

/** Lista os times da liga atual (para o cadastro manual de leilão). */
function leagueTeams(PDO $pdo, ?int $league_id, $user_id = null, bool $is_admin = false, ?string $leagueParam = null): void {
    // Admin gerencia várias ligas: pode pedir a liga explicitamente.
    $valid = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
    if ($is_admin && $leagueParam && in_array(strtoupper($leagueParam), $valid, true)) {
        $name = strtoupper($leagueParam);
        $stmt = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(city,''),' ',COALESCE(name,''))) AS team_name
                               FROM teams WHERE league = ? ORDER BY city, name");
        $stmt->execute([$name]);
        echo json_encode(['success' => true, 'teams' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        return;
    }
    $name = getLeagueNameById($pdo, $league_id);
    if (!$name && $user_id) {
        // fallback: liga do próprio time do usuário logado
        $st = $pdo->prepare("SELECT league FROM teams WHERE user_id = ? LIMIT 1");
        $st->execute([$user_id]);
        $name = $st->fetchColumn() ?: null;
    }
    if (!$name) {
        echo json_encode(['success' => false, 'error' => 'Liga não definida']);
        return;
    }
    $stmt = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(city,''),' ',COALESCE(name,''))) AS team_name
                           FROM teams WHERE league = ? ORDER BY city, name");
    $stmt->execute([$name]);
    echo json_encode(['success' => true, 'teams' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $action = $body['action'] ?? '';
    
    switch ($action) {
        case 'slot_leilao_mexer':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            mexerSlotDeLeilao($pdo, (int)($body['user_id'] ?? 0),
                              (string)($body['op'] ?? ''), (int)$user_id);
            exit;
        case 'cadastrar':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            // O leilão pode ser cadastrado em qualquer liga escolhida no dropdown do painel;
            // um admin de liga (não-global) só pode cadastrar nas ligas que ele administra.
            // A tela do admin manda o NOME da liga (é a aba em que ele está),
            // não o id de um dropdown — o dropdown saiu justamente porque era
            // o passo em que se abria leilão na liga errada.
            if (empty($body['league_id']) && !empty($body['league'])) {
                $stmtLgN = $pdo->prepare("SELECT id FROM leagues WHERE name = ? LIMIT 1");
                $stmtLgN->execute([strtoupper(trim($body['league']))]);
                $body['league_id'] = (int)($stmtLgN->fetchColumn() ?: 0) ?: null;
            }
            $cadastrarLeagueName = getLeagueNameById($pdo, isset($body['league_id']) ? (int)$body['league_id'] : null);
            if ($cadastrarLeagueName && !in_array(strtoupper($cadastrarLeagueName), getAdminLeagues($pdo, (int)$user_id), true)) {
                echo json_encode(['success' => false, 'error' => 'Você não administra essa liga']);
                exit;
            }
            cadastrarLeilao($pdo, $body, $user_id);
            break;
        case 'iniciar_leilao':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            iniciarLeilao($pdo, $body);
            break;
        case 'remover_temp':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            removerTempLeilao($pdo, $body);
            break;
        case 'criar_jogador':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            try {
                $created = criarJogadorParaLeilao($pdo, $body['new_player'] ?? [], $user_id, $body['league_id'] ?? null);
                echo json_encode(['success' => true] + $created);
            } catch (Throwable $e) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Erro interno do servidor.']);
            }
            break;
        case 'excluir_leilao':
        case 'excluir_cancelado':   // nome antigo, mantido pra não quebrar chamada velha
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            excluirLeilaoCancelado($pdo, $body);
            break;
        case 'cancelar':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            cancelarLeilao($pdo, $body);
            break;
        case 'reverter':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            reverterLeilao($pdo, $body);
            break;
        case 'cadastrar_manual':
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            cadastrarLeilaoManual($pdo, $body, $league_id);
            break;
        case 'enviar_proposta':
            enviarProposta($pdo, $body, $team_id, $league_id);
            break;
        case 'aceitar_proposta':
            aceitarProposta($pdo, $body, $team_id, $is_admin);
            break;
        case 'fechar_leilao':
            fecharLeilao($pdo, $body, $team_id, $is_admin);
            break;
        case 'admin_fechar_leilao':
            adminFecharLeilao($pdo, $body, $is_admin);
            break;
        case 'admin_encerrar_sem_troca':
            adminEncerrarLeilaoSemTroca($pdo, $body, $is_admin);
            break;
        case 'recusar_proposta':
            recusarProposta($pdo, $body, $team_id, $is_admin);
            break;
        case 'enviar_mensagem':
            enviarMensagemLeilao($pdo, $body, $team_id);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Acao nao reconhecida']);
    }
    exit;
}

// ========== FUNCOES GET ==========

function listarLeiloesAtivos($pdo, $league_id, $team_id = null) {
    // Escopo de liga é obrigatório aqui — sem liga resolvida, não mostra nada
    // (evita vazar leilões de outras ligas pro time logado).
    if (!$league_id) {
        echo json_encode(['success' => true, 'leiloes' => []]);
        return;
    }

    // Sem cron no projeto: a cada listagem fechamos os leiloes cujos 20min ja
    // acabaram e que tem uma proposta escolhida (mesmo padrao do waiver).
    resolverLeiloesExpirados($pdo);

    $ovrColumn = playerOvrColumn($pdo);
    // O prazo de 20min só fecha a janela de novas propostas (ver enviarProposta()).
    // Um leilão expirado continua aparecendo pro PRÓPRIO vendedor (pra ele poder
    // aceitar uma proposta recebida ou decidir cancelar) — sem isso, um leilão sem
    // decisão do vendedor dentro dos 20min sumia da lista e ficava "preso": nem
    // ativo visível, nem finalizado, nem no histórico.
    $sql = "SELECT l.*,
                   COALESCE(l.temp_name, p.name) as player_name,
                   COALESCE(l.temp_position, p.position) as position,
                   COALESCE(l.temp_age, p.age) as age,
                   COALESCE(l.temp_ovr, p.{$ovrColumn}) as ovr,
                   t.name as team_name,
                   lg.name as league_name,
                   (SELECT COUNT(*) FROM leilao_propostas WHERE leilao_id = l.id) as total_propostas,
                   UNIX_TIMESTAMP(l.data_fim) as data_fim_ts,
                   (l.data_fim IS NOT NULL AND l.data_fim <= NOW()) as expirado
            FROM leilao_jogadores l
            LEFT JOIN players p ON l.player_id = p.id
            LEFT JOIN teams t ON l.team_id = t.id
            LEFT JOIN leagues lg ON l.league_id = lg.id
            WHERE l.status = 'ativo' AND (l.data_fim IS NULL OR l.data_fim > NOW() OR l.team_id = ?)";
    $params = [$team_id ?: 0];

    if ($league_id) {
        $sql .= " AND l.league_id = ?";
        $params[] = $league_id;
    }
    if ($corte = corteDaSprintDoLeilao($pdo, $league_id)) {
        $sql .= " AND l.created_at >= ?";
        $params[] = $corte;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $leiloes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'leiloes' => $leiloes]);
}

/**
 * OS SLOTS DE LEILÃO COMPRADOS NA LOJA, POR GM DA LIGA.
 *
 * O slot é vendido a 500 moedas e não some em contador nenhum: não existe teto
 * de leilão no sistema, então "ter um slot" sempre significou, na prática, o
 * GM ter comprado o direito de pedir um leilão e o admin precisar abrir esse
 * leilão pra ele. Isso vivia só na tabela da loja, sem tela — o admin não
 * tinha como saber quem comprou nem marcar que já atendeu.
 *
 * A PENDÊNCIA É A LINHA SEM `atendido_em`. Aqui um slot pendente é um pedido
 * em aberto; marcado como usado, sai da lista e vira histórico.
 *
 * Devolve os GMs da liga que já compraram algum slot, com o saldo em aberto e
 * o total histórico — quem nunca comprou não aparece, senão a lista viraria a
 * liga inteira com zero em todo mundo.
 */
function slotsDeLeilao(PDO $pdo, string $league): void
{
    $validas = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
    if (!in_array($league, $validas, true)) {
        echo json_encode(['success' => false, 'error' => 'Liga inválida']);
        return;
    }
    require_once __DIR__ . '/../backend/loja.php';
    lojaGarantirTabela($pdo);

    $st = $pdo->prepare("
        SELECT u.id AS user_id, u.name AS gm,
               t.id AS team_id, CONCAT(t.city,' ',t.name) AS time,
               COUNT(*) AS total,
               SUM(i.atendido_em IS NULL) AS pendentes,
               MAX(i.comprado_em) AS ultima_compra
          FROM loja_inventario i
          JOIN users u ON u.id = i.id_usuario
          JOIN teams t ON t.user_id = u.id
         WHERE i.item_key = 'slot_leilao' AND t.league = ?
      GROUP BY u.id, u.name, t.id, time
      ORDER BY pendentes DESC, ultima_compra DESC");
    $st->execute([$league]);
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($linhas as &$l) {
        $l['user_id']   = (int)$l['user_id'];
        $l['team_id']   = (int)$l['team_id'];
        $l['total']     = (int)$l['total'];
        $l['pendentes'] = (int)$l['pendentes'];
    }
    unset($l);

    echo json_encode(['success' => true, 'slots' => $linhas]);
}

/**
 * Mexe no saldo de slots de um GM: marcar um como usado, dar um, tirar um.
 *
 * Nada é apagado. "usar" e "tirar" preenchem `atendido_em` — a diferença fica
 * na `obs`, que é o que permite saber depois se o slot foi atendido de verdade
 * ou cancelado pelo admin. Um slot pago que somisse da tabela seria uma compra
 * de 500 moedas sem rastro nenhum.
 */
function mexerSlotDeLeilao(PDO $pdo, int $userId, string $op, int $adminId): void
{
    require_once __DIR__ . '/../backend/loja.php';
    lojaGarantirTabela($pdo);

    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => 'GM inválido']);
        return;
    }

    if ($op === 'dar') {
        // Slot de cortesia: preço zero é o que o distingue de uma compra no
        // extrato, e `usado_em` já vem preenchido porque ele nasce valendo.
        $pdo->prepare("INSERT INTO loja_inventario
                          (id_usuario, item_key, preco_pago, usado_em, obs)
                       VALUES (?, 'slot_leilao', 0, NOW(), 'Concedido pelo admin')")
            ->execute([$userId]);
        echo json_encode(['success' => true, 'message' => 'Slot concedido.']);
        return;
    }

    if ($op !== 'usar' && $op !== 'tirar') {
        echo json_encode(['success' => false, 'error' => 'Operação inválida']);
        return;
    }

    // O mais ANTIGO em aberto: quem comprou primeiro é atendido primeiro.
    $st = $pdo->prepare("SELECT id FROM loja_inventario
                          WHERE id_usuario = ? AND item_key = 'slot_leilao' AND atendido_em IS NULL
                       ORDER BY comprado_em ASC, id ASC LIMIT 1");
    $st->execute([$userId]);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Esse GM não tem slot em aberto.']);
        return;
    }

    $obs = $op === 'usar' ? 'Leilão aberto pelo admin' : 'Removido pelo admin';
    $pdo->prepare("UPDATE loja_inventario
                      SET atendido_em = NOW(), atendido_por = ?, obs = ?
                    WHERE id = ?")->execute([$adminId ?: null, $obs, $id]);

    echo json_encode(['success' => true,
        'message' => $op === 'usar' ? 'Slot marcado como usado.' : 'Slot removido.']);
}

function listarLeiloesAdmin($pdo, ?int $league_id = null) {
    $ovrColumn = playerOvrColumn($pdo);
    $sql = "SELECT l.*,
                   COALESCE(l.temp_name, p.name) as player_name,
                   COALESCE(l.temp_position, p.position) as position,
                   COALESCE(l.temp_age, p.age) as age,
                   COALESCE(l.temp_ovr, p.{$ovrColumn}) as ovr,
                   t.name as team_name,
                   lg.name as league_name,
                   (SELECT COUNT(*) FROM leilao_propostas WHERE leilao_id = l.id) as total_propostas,
                   UNIX_TIMESTAMP(l.data_fim) as data_fim_ts,
                   (l.data_fim IS NOT NULL AND l.data_fim <= NOW()) as expirado
            FROM leilao_jogadores l
            LEFT JOIN players p ON l.player_id = p.id
            LEFT JOIN teams t ON l.team_id = t.id
            LEFT JOIN leagues lg ON l.league_id = lg.id";
    $params = [];
    $where = [];
    if ($league_id) {
        $where[] = 'l.league_id = ?';
        $params[] = $league_id;
    }
    if ($corte = corteDaSprintDoLeilao($pdo, $league_id)) {
        $where[] = 'l.created_at >= ?';
        $params[] = $corte;
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= " ORDER BY l.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $leiloes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // O desfecho vai junto, pra lista de finalizados já mostrar a troca.
    foreach ($leiloes as &$l) {
        $l['troca'] = $l['status'] === 'finalizado'
            ? resumoDaTrocaDoLeilao($pdo, (int)$l['id'], (string)($l['player_name'] ?? '—'))
            : null;
    }
    unset($l);
    echo json_encode(['success' => true, 'leiloes' => $leiloes]);
}

function listarLeiloesTemporarios($pdo, ?int $league_id = null) {
    $ovrColumn = playerOvrColumn($pdo);
    $sql = "SELECT l.*, 
                   COALESCE(l.temp_name, p.name) as player_name, 
                   COALESCE(l.temp_position, p.position) as position, 
                   COALESCE(l.temp_age, p.age) as age, 
                   COALESCE(l.temp_ovr, p.{$ovrColumn}) as ovr,
                   t.name as team_name,
                   lg.name as league_name,
                   (SELECT COUNT(*) FROM leilao_propostas WHERE leilao_id = l.id) as total_propostas
            FROM leilao_jogadores l
            LEFT JOIN players p ON l.player_id = p.id
            LEFT JOIN teams t ON l.team_id = t.id
        LEFT JOIN leagues lg ON l.league_id = lg.id
            WHERE (l.is_temp_player = 1 OR l.temp_name IS NOT NULL)";
    $params = [];
    if ($league_id) {
        $sql .= ' AND l.league_id = ?';
        $params[] = $league_id;
        if ($corte = corteDaSprintDoLeilao($pdo, $league_id)) {
            $sql .= ' AND l.created_at >= ?';
            $params[] = $corte;
        }
    } else {
        // Sem liga escolhida, o corte é o de cada uma: o admin vê todas as
        // ligas de uma vez, e cada sprint começou num dia diferente.
        $sql .= " AND (l.league_id IS NULL OR EXISTS (
                        SELECT 1 FROM leagues lgx
                        JOIN sprints spx ON spx.league = lgx.name AND spx.status = 'active'
                        WHERE lgx.id = l.league_id AND l.created_at >= spx.start_date))";
    }
    $sql .= ' ORDER BY l.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $leiloes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'leiloes' => $leiloes]);
}

function minhasPropostas($pdo, $team_id, ?int $league_id = null) {
    if (!$team_id) {
        echo json_encode(['success' => true, 'propostas' => []]);
        return;
    }
    $corte = corteDaSprintDoLeilao($pdo, $league_id);
    $filtroSprint = $corte ? ' AND l.created_at >= ?' : '';

    $sql = "SELECT lp.*, 
                   l.player_id,
                   COALESCE(l.temp_name, p.name) as player_name,
                   t.name as team_name,
                   GROUP_CONCAT(po.name SEPARATOR ', ') as jogadores_oferecidos
            FROM leilao_propostas lp
            JOIN leilao_jogadores l ON lp.leilao_id = l.id
            LEFT JOIN players p ON l.player_id = p.id
            LEFT JOIN teams t ON l.team_id = t.id
            LEFT JOIN leilao_proposta_jogadores lpj ON lp.id = lpj.proposta_id
            LEFT JOIN players po ON lpj.player_id = po.id
            WHERE lp.team_id = ?{$filtroSprint}
            GROUP BY lp.id
            ORDER BY lp.created_at DESC";

    $params = [$team_id];
    if ($corte) $params[] = $corte;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $propostas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'propostas' => $propostas]);
}

function propostasRecebidas($pdo, $team_id, ?int $league_id = null) {
    if (!$team_id) {
        echo json_encode(['success' => true, 'leiloes' => []]);
        return;
    }
    $corte = corteDaSprintDoLeilao($pdo, $league_id);

    $ovrColumn = playerOvrColumn($pdo);
    $sql = "SELECT l.id, l.status,
                   COALESCE(l.temp_name, p.name) as player_name,
                   COALESCE(l.temp_position, p.position) as position,
                   COALESCE(l.temp_ovr, p.{$ovrColumn}) as ovr
            FROM leilao_jogadores l
            LEFT JOIN players p ON l.player_id = p.id
            WHERE l.team_id = ? AND l.status = 'ativo'" . ($corte ? ' AND l.created_at >= ?' : '');

    $params = [$team_id];
    if ($corte) $params[] = $corte;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $leiloes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ovrCol = playerOvrColumn($pdo);
    foreach ($leiloes as &$leilao) {
        $stmtP = $pdo->prepare("SELECT lp.*, t.name as team_name
                                FROM leilao_propostas lp
                                JOIN teams t ON lp.team_id = t.id
                                WHERE lp.leilao_id = ?
                                ORDER BY FIELD(lp.status,'pendente','recusada'), lp.created_at DESC");
        $stmtP->execute([$leilao['id']]);
        $propostas = $stmtP->fetchAll(PDO::FETCH_ASSOC);

        foreach ($propostas as &$proposta) {
            $stmtJ = $pdo->prepare("SELECT p.id, p.name, p.position, p.age, p.{$ovrCol} as ovr
                                    FROM players p
                                    JOIN leilao_proposta_jogadores lpj ON p.id = lpj.player_id
                                    WHERE lpj.proposta_id = ?");
            $stmtJ->execute([$proposta['id']]);
            $proposta['jogadores'] = $stmtJ->fetchAll(PDO::FETCH_ASSOC);

            $stmtPk = $pdo->prepare("SELECT pk.id, pk.season_year, pk.round, lpp.swap_type,
                                           CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')) AS original_team_name
                                    FROM leilao_proposta_picks lpp
                                    JOIN picks pk ON pk.id = lpp.pick_id
                                    LEFT JOIN teams t ON t.id = pk.original_team_id
                                    WHERE lpp.proposta_id = ?");
            $stmtPk->execute([$proposta['id']]);
            $proposta['picks'] = $stmtPk->fetchAll(PDO::FETCH_ASSOC);

            $stmtEJ = $pdo->prepare("SELECT pl.id, pl.name, pl.position, pl.age, pl.{$ovrCol} as ovr
                                     FROM leilao_proposta_extra_players ep
                                     JOIN players pl ON pl.id = ep.player_id
                                     WHERE ep.proposta_id = ?");
            $stmtEJ->execute([$proposta['id']]);
            $proposta['extra_jogadores'] = $stmtEJ->fetchAll(PDO::FETCH_ASSOC);

            $stmtEP = $pdo->prepare("SELECT pk.id, pk.season_year, pk.round, ep.swap_type,
                                           CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')) AS original_team_name
                                    FROM leilao_proposta_extra_picks ep
                                    JOIN picks pk ON pk.id = ep.pick_id
                                    LEFT JOIN teams t ON t.id = pk.original_team_id
                                    WHERE ep.proposta_id = ?");
            $stmtEP->execute([$proposta['id']]);
            $proposta['extra_picks'] = $stmtEP->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($proposta);
        $leilao['propostas'] = $propostas;
    }
    unset($leilao);

    $leiloes = array_values(array_filter($leiloes, fn($l) => !empty($l['propostas'])));
    echo json_encode(['success' => true, 'leiloes' => $leiloes]);
}

function verPropostas($pdo, $leilao_id, $team_id, $is_admin) {
    $stmt = $pdo->prepare("SELECT team_id, status FROM leilao_jogadores WHERE id = ?");
    $stmt->execute([$leilao_id]);
    $leilao = $stmt->fetch();

    $isOwner   = $leilao && $leilao['team_id'] == $team_id;
    $isFinished = $leilao && $leilao['status'] === 'finalizado';

    // Qualquer um pode ver a proposta aceita de leilões finalizados
    if (!$leilao || (!$is_admin && !$isOwner && !$isFinished)) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado']);
        return;
    }

    // Para não-donos em leilão finalizado: exibe só a proposta aceita
    $onlyAccepted = $isFinished && !$is_admin && !$isOwner;

    $sql = "SELECT lp.*, t.name as team_name
            FROM leilao_propostas lp
            JOIN teams t ON lp.team_id = t.id
            WHERE lp.leilao_id = ?" . ($onlyAccepted ? " AND lp.status = 'aceita'" : '') . "
            ORDER BY FIELD(lp.status,'pendente','aceita','recusada'), lp.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$leilao_id]);
    $propostas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Para cada proposta, buscar os jogadores oferecidos
    $ovrCol = playerOvrColumn($pdo);
    foreach ($propostas as &$proposta) {
        $stmt2 = $pdo->prepare("SELECT p.id, p.name, p.position, p.age, p.{$ovrCol} as ovr FROM players p
                                JOIN leilao_proposta_jogadores lpj ON p.id = lpj.player_id
                                WHERE lpj.proposta_id = ?");
        $stmt2->execute([$proposta['id']]);
        $proposta['jogadores'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        // picks oferecidas (com swap_type)
        $stmt3 = $pdo->prepare("SELECT pk.id, pk.season_year, pk.round, lpp.swap_type,
                                       CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')) AS original_team_name
                                FROM leilao_proposta_picks lpp
                                JOIN picks pk ON pk.id = lpp.pick_id
                                LEFT JOIN teams t ON t.id = pk.original_team_id
                                WHERE lpp.proposta_id = ?");
        $stmt3->execute([$proposta['id']]);
        $proposta['picks'] = $stmt3->fetchAll(PDO::FETCH_ASSOC);
        // itens extras (oferta personalizada)
        $stmt4 = $pdo->prepare("SELECT pl.id, pl.name, pl.position, pl.age, pl.{$ovrCol} as ovr
                                FROM leilao_proposta_extra_players ep
                                JOIN players pl ON pl.id = ep.player_id
                                WHERE ep.proposta_id = ?");
        $stmt4->execute([$proposta['id']]);
        $proposta['extra_jogadores'] = $stmt4->fetchAll(PDO::FETCH_ASSOC);
        $stmt5 = $pdo->prepare("SELECT pk.id, pk.season_year, pk.round, ep.swap_type,
                                       CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')) AS original_team_name
                                FROM leilao_proposta_extra_picks ep
                                JOIN picks pk ON pk.id = ep.pick_id
                                LEFT JOIN teams t ON t.id = pk.original_team_id
                                WHERE ep.proposta_id = ?");
        $stmt5->execute([$proposta['id']]);
        $proposta['extra_picks'] = $stmt5->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['success' => true, 'propostas' => $propostas]);
}

function verPropostasEnviadas($pdo, $leilao_id, $team_id) {
    if (!$leilao_id) {
        echo json_encode(['success' => true, 'propostas' => []]);
        return;
    }

    $sql = "SELECT lp.*, t.name as team_name
            FROM leilao_propostas lp
            JOIN teams t ON lp.team_id = t.id
            WHERE lp.leilao_id = ?
            ORDER BY lp.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$leilao_id]);
    $propostas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ovrCol2 = playerOvrColumn($pdo);
    foreach ($propostas as &$proposta) {
        $stmt2 = $pdo->prepare("SELECT p.id, p.name, p.position, p.age, p.{$ovrCol2} as ovr FROM players p
                                JOIN leilao_proposta_jogadores lpj ON p.id = lpj.player_id
                                WHERE lpj.proposta_id = ?");
        $stmt2->execute([$proposta['id']]);
        $proposta['jogadores'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $stmt3 = $pdo->prepare("SELECT pk.id, pk.season_year, pk.round, lpp.swap_type,
                                       CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')) AS original_team_name
                                FROM leilao_proposta_picks lpp
                                JOIN picks pk ON pk.id = lpp.pick_id
                                LEFT JOIN teams t ON t.id = pk.original_team_id
                                WHERE lpp.proposta_id = ?");
        $stmt3->execute([$proposta['id']]);
        $proposta['picks'] = $stmt3->fetchAll(PDO::FETCH_ASSOC);

        $stmt4 = $pdo->prepare("SELECT pl.id, pl.name, pl.position, pl.age, pl.{$ovrCol2} as ovr
                                FROM leilao_proposta_extra_players ep
                                JOIN players pl ON pl.id = ep.player_id
                                WHERE ep.proposta_id = ?");
        $stmt4->execute([$proposta['id']]);
        $proposta['extra_jogadores'] = $stmt4->fetchAll(PDO::FETCH_ASSOC);

        $stmt5 = $pdo->prepare("SELECT pk.id, pk.season_year, pk.round, ep.swap_type,
                                       CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')) AS original_team_name
                                FROM leilao_proposta_extra_picks ep
                                JOIN picks pk ON pk.id = ep.pick_id
                                LEFT JOIN teams t ON t.id = pk.original_team_id
                                WHERE ep.proposta_id = ?");
        $stmt5->execute([$proposta['id']]);
        $proposta['extra_picks'] = $stmt5->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['success' => true, 'propostas' => $propostas]);
}

function historicoLeiloes($pdo, $league_id) {
    // Sem liga resolvida não mostra nada: antes, um GET sem league_id trazia o
    // histórico de todas as ligas de uma vez.
    if (!$league_id) {
        echo json_encode(['success' => true, 'leiloes' => []]);
        return;
    }
    $params = [];
    $where = "l.status = 'finalizado' AND l.league_id = ?";
    $params[] = $league_id;
    if ($corte = corteDaSprintDoLeilao($pdo, (int)$league_id)) {
        $where .= " AND l.created_at >= ?";
        $params[] = $corte;
    }
    $ovrColumn = playerOvrColumn($pdo);
    $sql = "SELECT l.id, l.data_fim,
                   COALESCE(l.temp_name, p.name) AS player_name,
                   COALESCE(l.temp_position, p.position) AS position,
                   COALESCE(l.temp_ovr, p.{$ovrColumn}) AS ovr,
                   COALESCE(t.name, 'Sem time') AS team_name,
                   tw.city AS winner_city, tw.name AS winner_name,
                   (SELECT COUNT(*) FROM leilao_propostas lpc WHERE lpc.leilao_id = l.id) AS total_propostas
            FROM leilao_jogadores l
            LEFT JOIN players p ON l.player_id = p.id
            LEFT JOIN teams t ON l.team_id = t.id
            LEFT JOIN leilao_propostas lp ON lp.id = l.proposta_aceita_id
            LEFT JOIN teams tw ON lp.team_id = tw.id
            WHERE {$where}
            ORDER BY l.data_fim DESC
            LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $payload = array_map(static function ($row) {
        $winner = null;
        if (!empty($row['winner_name'])) {
            $winner = trim(($row['winner_city'] ?? '') . ' ' . $row['winner_name']);
        }
        return [
            'id'               => (int)$row['id'],
            'player_name'      => $row['player_name'],
            'position'         => $row['position'] ?? '',
            'ovr'              => $row['ovr'] ? (int)$row['ovr'] : null,
            'team_name'        => $row['team_name'],
            'winner_team_name' => $winner,
            'data_fim'         => $row['data_fim'],
            'total_propostas'  => (int)$row['total_propostas'],
        ];
    }, $items);

    echo json_encode(['success' => true, 'leiloes' => $payload]);
}

/**
 * Timeline cronológica de um leilão: mensagens de texto + propostas embutidas
 * (cada proposta enviada gera uma mensagem tipo='proposal' espelhada em leilao_mensagens).
 * Mesma regra de acesso do ver_propostas: leilão finalizado + não-dono/admin só vê a proposta aceita.
 */
function listarMensagensLeilao(PDO $pdo, $leilao_id, ?int $team_id, bool $is_admin): void {
    if (!$leilao_id) {
        echo json_encode(['success' => false, 'error' => 'ID do leilão não informado']);
        return;
    }

    $stmt = $pdo->prepare("SELECT team_id, status, data_fim, UNIX_TIMESTAMP(data_fim) AS data_fim_ts
                           FROM leilao_jogadores WHERE id = ?");
    $stmt->execute([$leilao_id]);
    $leilao = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$leilao) {
        echo json_encode(['success' => false, 'error' => 'Leilão não encontrado']);
        return;
    }

    $isOwner = !empty($leilao['team_id']) && $leilao['team_id'] == $team_id;
    $isFinished = $leilao['status'] === 'finalizado';
    $onlyAccepted = $isFinished && !$is_admin && !$isOwner;

    $stmt = $pdo->prepare("SELECT lm.*, t.name as team_name
                           FROM leilao_mensagens lm
                           JOIN teams t ON lm.team_id = t.id
                           WHERE lm.leilao_id = ?
                           ORDER BY lm.created_at ASC, lm.id ASC");
    $stmt->execute([$leilao_id]);
    $mensagens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ovrCol = playerOvrColumn($pdo);
    $result = [];
    foreach ($mensagens as $m) {
        if ($m['tipo'] === 'proposal') {
            if (empty($m['proposta_id'])) {
                continue;
            }
            $stmtP = $pdo->prepare("SELECT * FROM leilao_propostas WHERE id = ?");
            $stmtP->execute([$m['proposta_id']]);
            $proposta = $stmtP->fetch(PDO::FETCH_ASSOC);
            if (!$proposta) {
                continue;
            }
            if ($onlyAccepted && $proposta['status'] !== 'aceita') {
                continue;
            }

            $stmt2 = $pdo->prepare("SELECT p.id, p.name, p.position, p.age, p.{$ovrCol} as ovr FROM players p
                                    JOIN leilao_proposta_jogadores lpj ON p.id = lpj.player_id
                                    WHERE lpj.proposta_id = ?");
            $stmt2->execute([$proposta['id']]);
            $proposta['jogadores'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            $stmt3 = $pdo->prepare("SELECT pk.id, pk.season_year, pk.round, lpp.swap_type,
                                           CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')) AS original_team_name
                                    FROM leilao_proposta_picks lpp
                                    JOIN picks pk ON pk.id = lpp.pick_id
                                    LEFT JOIN teams t ON t.id = pk.original_team_id
                                    WHERE lpp.proposta_id = ?");
            $stmt3->execute([$proposta['id']]);
            $proposta['picks'] = $stmt3->fetchAll(PDO::FETCH_ASSOC);

            $stmt4 = $pdo->prepare("SELECT pl.id, pl.name, pl.position, pl.age, pl.{$ovrCol} as ovr
                                    FROM leilao_proposta_extra_players ep
                                    JOIN players pl ON pl.id = ep.player_id
                                    WHERE ep.proposta_id = ?");
            $stmt4->execute([$proposta['id']]);
            $proposta['extra_jogadores'] = $stmt4->fetchAll(PDO::FETCH_ASSOC);

            $stmt5 = $pdo->prepare("SELECT pk.id, pk.season_year, pk.round, ep.swap_type,
                                           CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')) AS original_team_name
                                    FROM leilao_proposta_extra_picks ep
                                    JOIN picks pk ON pk.id = ep.pick_id
                                    LEFT JOIN teams t ON t.id = pk.original_team_id
                                    WHERE ep.proposta_id = ?");
            $stmt5->execute([$proposta['id']]);
            $proposta['extra_picks'] = $stmt5->fetchAll(PDO::FETCH_ASSOC);

            $m['proposta'] = $proposta;
        }
        $result[] = $m;
    }

    // O prazo vai junto para a conversa poder mostrar o mesmo cronometro de
    // 20min que aparece no card do leilao.
    echo json_encode([
        'success'     => true,
        'messages'    => $result,
        'status'      => $leilao['status'],
        'data_fim'    => $leilao['data_fim'],
        'data_fim_ts' => $leilao['data_fim_ts'] !== null ? (int)$leilao['data_fim_ts'] : null,
        'team_id'     => $leilao['team_id'] !== null ? (int)$leilao['team_id'] : null,
    ]);
}

// ========== FUNCOES POST ==========

function minhasPicks(PDO $pdo, ?int $team_id, ?int $league_id): void {
    if (!$team_id) {
        echo json_encode(['success' => true, 'picks' => []]);
        return;
    }
    $params = [$team_id];
    $minSeasonYear = getCurrentSeasonYear($pdo, $league_id);
    $seasonFilter = '';
    if ($minSeasonYear) {
        $seasonFilter = ' AND p.season_year >= ?';
        $params[] = $minSeasonYear;
    }
    $sql = "SELECT p.id, p.season_year, p.round, p.notes,
                   CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')) AS original_team_name
            FROM picks p
            LEFT JOIN teams t ON p.original_team_id = t.id
            WHERE p.team_id = ?{$seasonFilter}
            ORDER BY p.season_year DESC, p.round ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'picks' => $rows]);
}

function sellerItems(PDO $pdo, int $seller_team_id, ?int $league_id): void {
    if (!$seller_team_id) {
        echo json_encode(['success' => false, 'error' => 'seller_team_id obrigatório']);
        return;
    }
    $ovrColumn = playerOvrColumn($pdo);
    $stmt = $pdo->prepare("SELECT id, name, position, age, {$ovrColumn} as ovr, COALESCE(role,'') as role FROM players WHERE team_id = ? ORDER BY {$ovrColumn} DESC");
    $stmt->execute([$seller_team_id]);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $params = [$seller_team_id];
    $minSeasonYear = getCurrentSeasonYear($pdo, $league_id);
    $seasonFilter = '';
    if ($minSeasonYear) {
        $seasonFilter = ' AND p.season_year >= ?';
        $params[] = $minSeasonYear;
    }
    $stmt = $pdo->prepare("SELECT p.id, p.season_year, p.round,
                                   CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')) AS original_team_name
                            FROM picks p
                            LEFT JOIN teams t ON p.original_team_id = t.id
                            WHERE p.team_id = ?{$seasonFilter}
                            ORDER BY p.season_year DESC, p.round ASC");
    $stmt->execute($params);
    $picks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'players' => $players, 'picks' => $picks]);
}

/**
 * Nome do jogador em leilão — o real ou o temporário cadastrado na hora.
 * Nunca lança: só serve pra montar texto de notificação.
 */
function nomeDoJogadorDoLeilao(PDO $pdo, int $leilao_id): string
{
    try {
        $st = $pdo->prepare("SELECT l.temp_name, p.name
                             FROM leilao_jogadores l
                             LEFT JOIN players p ON p.id = l.player_id
                             WHERE l.id = ? LIMIT 1");
        $st->execute([$leilao_id]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return (string)($r['name'] ?: $r['temp_name'] ?: 'Jogador');
    } catch (Throwable $e) {
        return 'Jogador';
    }
}

/** Avisa a liga que um leilão entrou no ar (menos o time que colocou o jogador). */
function notificarLeilaoAberto(PDO $pdo, int $leilao_id): void
{
    try {
        $st = $pdo->prepare("SELECT league_id, team_id FROM leilao_jogadores WHERE id = ? LIMIT 1");
        $st->execute([$leilao_id]);
        $l = $st->fetch(PDO::FETCH_ASSOC);
        if (!$l) return;

        $liga = getLeagueNameById($pdo, $l['league_id'] !== null ? (int)$l['league_id'] : null);
        if (!$liga) return;

        $exceto = [];
        if (!empty($l['team_id'])) {
            $su = $pdo->prepare("SELECT user_id FROM teams WHERE id = ? LIMIT 1");
            $su->execute([(int)$l['team_id']]);
            if ($uid = (int)($su->fetchColumn() ?: 0)) $exceto[] = $uid;
        }

        sendPushToLeague($pdo, $liga, [
            'title' => '🔨 Novo leilão na ' . $liga,
            'body'  => nomeDoJogadorDoLeilao($pdo, $leilao_id) . ' está no leilão. Você tem 20 min pra mandar sua oferta!',
            'url'   => '/leilao.php',
        ], 'leilao', $exceto);
    } catch (Throwable $e) {
        error_log('notificarLeilaoAberto #' . $leilao_id . ': ' . $e->getMessage());
    }
}

function cadastrarLeilao($pdo, $body, $user_id) {
    $player_id = $body['player_id'] ?? null;
    $team_id = $body['team_id'] ?? null;
    $league_id = $body['league_id'] ?? null;
    $data_inicio = $body['data_inicio'] ?? null;
    $data_fim = $body['data_fim'] ?? null;
    $new_player = $body['new_player'] ?? null;
    $status = isset($body['status']) && $body['status'] === 'pendente' ? 'pendente' : 'ativo';

    if ((!$player_id && !$new_player) || !$league_id) {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        return;
    }

    $tempPlayer = null;

    if ($new_player) {
        try {
            $tempPlayer = criarJogadorParaLeilao($pdo, $new_player, $user_id, $league_id);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Erro interno do servidor.']);
            return;
        }
    }

    if ($player_id && !$team_id) {
        $stmt = $pdo->prepare("SELECT team_id FROM players WHERE id = ?");
        $stmt->execute([$player_id]);
        $playerRow = $stmt->fetch();
        if (!$playerRow) {
            echo json_encode(['success' => false, 'error' => 'Jogador nao encontrado']);
            return;
        }
        $team_id = $playerRow['team_id'];
    }
    
    // Verificar se jogador ja esta em leilao ativo
    $stmt = $pdo->prepare("SELECT id FROM leilao_jogadores WHERE player_id = ? AND status = 'ativo'");
    $stmt->execute([$player_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Jogador ja esta em leilao ativo']);
        return;
    }
    
    $data_inicio = $data_inicio ?: date('Y-m-d H:i:s');
    // Para pendente, mantenha data_fim nula para iniciar depois
    $data_fim = $status === 'ativo' ? ($data_fim ?: date('Y-m-d H:i:s', time() + (20 * 60))) : null;
    $stmt = $pdo->prepare("INSERT INTO leilao_jogadores (player_id, team_id, league_id, data_inicio, data_fim, status, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$player_id, $team_id, $league_id, $data_inicio, $data_fim, $status]);
    $leilaoId = $pdo->lastInsertId();

    if ($tempPlayer) {
        try {
            $stmtTemp = $pdo->prepare("UPDATE leilao_jogadores SET temp_name = ?, temp_position = ?, temp_age = ?, temp_ovr = ?, is_temp_player = 1 WHERE id = ?");
            $stmtTemp->execute([$tempPlayer['name'], $tempPlayer['position'], $tempPlayer['age'], $tempPlayer['ovr'], $leilaoId]);
        } catch (Throwable $e) {
            // ignora caso as colunas temporárias não existam por algum motivo
        }
    }

    // Pendente ainda não está no ar — quem avisa a liga é o iniciarLeilao().
    if ($status === 'ativo') {
        notificarLeilaoAberto($pdo, (int)$leilaoId);
    }

    echo json_encode(['success' => true, 'leilao_id' => $leilaoId]);
}

function iniciarLeilao($pdo, $body) {
    if (!hasAdminAccess($pdo, (int)($_SESSION['user_id'] ?? 0))) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado']);
        return;
    }
    $leilao_id = $body['leilao_id'] ?? null;
    if (!$leilao_id) {
        echo json_encode(['success' => false, 'error' => 'ID do leilao nao informado']);
        return;
    }
    $stmt = $pdo->prepare("UPDATE leilao_jogadores SET status = 'ativo', data_inicio = NOW(), data_fim = DATE_ADD(NOW(), INTERVAL 20 MINUTE) WHERE id = ?");
    $stmt->execute([$leilao_id]);
    notificarLeilaoAberto($pdo, (int)$leilao_id);
    echo json_encode(['success' => true]);
}

function removerTempLeilao($pdo, $body) {
    if (!hasAdminAccess($pdo, (int)($_SESSION['user_id'] ?? 0))) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado']);
        return;
    }
    $leilao_id = $body['leilao_id'] ?? null;
    if (!$leilao_id) {
        echo json_encode(['success' => false, 'error' => 'ID do leilao nao informado']);
        return;
    }
    // Remove somente registros pendentes e criados como temporarios
    $stmt = $pdo->prepare("DELETE FROM leilao_jogadores WHERE id = ? AND status = 'pendente' AND (is_temp_player = 1 OR temp_name IS NOT NULL)");
    $stmt->execute([$leilao_id]);
    echo json_encode(['success' => true]);
}

function cancelarLeilao($pdo, $body) {
    $leilao_id = $body['leilao_id'] ?? null;
    
    if (!$leilao_id) {
        echo json_encode(['success' => false, 'error' => 'ID do leilao nao informado']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE leilao_jogadores SET status = 'cancelado' WHERE id = ?");
    // O leilão acabou: o que estava na fila do WhatsApp não tem mais
    // decisão a pedir.
    leilaoBotEncerrarLeilao($pdo, (int)$leilao_id);
    $stmt->execute([$leilao_id]);
    
    // Atualizar todas as propostas para recusadas
    $stmt = $pdo->prepare("UPDATE leilao_propostas SET status = 'recusada' WHERE leilao_id = ?");
    $stmt->execute([$leilao_id]);
    
    echo json_encode(['success' => true]);
}

/**
 * Apaga de vez um leilão encerrado, com tudo o que pendurou nele.
 *
 * Vale pra cancelado (leilão que não aconteceu) e pra finalizado — o admin
 * pediu os dois, pra lista não virar arquivo morto. O que a troca já moveu
 * no elenco NÃO volta: apagar aqui some com o registro do leilão, não
 * desfaz a negociação. Leilão ativo nunca sai por aqui: pra isso existe o
 * cancelar, que avisa quem tinha proposta.
 *
 * As tabelas filhas saem na mão porque nem toda base tem FK com CASCADE —
 * as mais antigas foram criadas sem.
 */
/**
 * Os números que a tela precisa pra fazer a conta do cap enquanto o GM
 * monta a proposta, sem uma ida ao servidor por clique.
 *
 * Manda o salário do jogador leiloado, o espaço de quem está montando e o
 * preço de cada jogador dos dois elencos. Fora da ELITE devolve aplica=false
 * e a tela nem mostra a conta — lá o leilão não tem trava de cap.
 */
function capDoLeilao(PDO $pdo, int $leilao_id, $team_id): void
{
    if (!$leilao_id || !$team_id) {
        echo json_encode(['success' => true, 'aplica' => false]);
        return;
    }
    $ovrCol = playerOvrColumn($pdo);
    $st = $pdo->prepare("SELECT l.team_id, l.player_id, l.temp_ovr,
                                COALESCE(l.temp_name, p.name) AS nome,
                                COALESCE(l.temp_position, p.position) AS posicao,
                                COALESCE(l.temp_age, p.age) AS idade,
                                COALESCE(l.temp_ovr, p.{$ovrCol}) AS ovr
                         FROM leilao_jogadores l
                         LEFT JOIN players p ON p.id = l.player_id
                         WHERE l.id = ?");
    $st->execute([$leilao_id]);
    $leilao = $st->fetch(PDO::FETCH_ASSOC);
    if (!$leilao) {
        echo json_encode(['success' => true, 'aplica' => false]);
        return;
    }

    $base = leilaoImpactoNoCap($pdo, (int)$team_id, $leilao, [], []);
    $ficha = ['nome' => $leilao['nome'] ?? null, 'posicao' => $leilao['posicao'] ?? null,
              'idade' => $leilao['idade'] !== null ? (int)$leilao['idade'] : null,
              'ovr'   => $leilao['ovr'] !== null ? (int)$leilao['ovr'] : null];
    if (!$base['aplica']) {
        echo json_encode(['success' => true, 'aplica' => false, 'alvo' => $ficha]);
        return;
    }

    $st = $pdo->prepare("SELECT league FROM teams WHERE id = ?");
    $st->execute([$team_id]);
    $liga = (string)$st->fetchColumn();

    echo json_encode([
        'success'          => true,
        'aplica'           => true,
        'alvo'             => $ficha,
        'espaco'           => $base['espaco'],
        'salario_do_alvo'  => $base['recebe'],
        'meus_salarios'    => capSalariosDoTime($pdo, (int)$team_id, $liga),
        'salarios_vendedor'=> !empty($leilao['team_id'])
                                ? capSalariosDoTime($pdo, (int)$leilao['team_id'], $liga)
                                : (object)[],
    ]);
}

/**
 * O que uma proposta de leilão faz com o cap de quem propõe.
 *
 * Diferente da trade: aqui NÃO vale a regra dos 120%. O leilão é disputa
 * aberta, não troca casada — o único limite é o teto. Quem recebe 20M e
 * manda 8M sobe 12M de folha, e isso precisa caber no espaço que ele tem.
 * Para ficar dentro, ou manda mais salário, ou some do leilão.
 *
 * Fora da ELITE não há conta a fazer: lá o cap é soma de OVR e a liga
 * deixa estourar no leilão de propósito.
 *
 * Devolve ['aplica' => bool, 'recebe' => int, 'envia' => int,
 *          'delta' => int, 'espaco' => int, 'cabe' => bool].
 */
function leilaoImpactoNoCap(PDO $pdo, int $team_id, array $leilao, array $player_ids, array $extra_player_ids): array
{
    $vazio = ['aplica' => false, 'recebe' => 0, 'envia' => 0, 'delta' => 0, 'espaco' => 0, 'cabe' => true];

    $st = $pdo->prepare("SELECT league FROM teams WHERE id = ?");
    $st->execute([$team_id]);
    $liga = (string)($st->fetchColumn() ?: '');
    if ($liga === '' || !capLigaUsaSalario($pdo, $liga)) return $vazio;

    // O que entra: o jogador leiloado + o que o comprador pediu de extra.
    $recebe = 0;
    $sellerTeamId = (int)($leilao['team_id'] ?? 0);
    $salariosDoVendedor = $sellerTeamId ? capSalariosDoTime($pdo, $sellerTeamId, $liga) : [];

    $playerId = (int)($leilao['player_id'] ?? 0);
    if ($playerId && isset($salariosDoVendedor[$playerId])) {
        $recebe += (int)$salariosDoVendedor[$playerId];
    } else {
        // Jogador avulso (cadastrado só pro leilão): não está em elenco
        // nenhum, então o salário é o da tabela por OVR.
        $recebe += capOvrSalary((int)($leilao['temp_ovr'] ?? 0));
    }
    foreach ($extra_player_ids as $pid) {
        $recebe += (int)($salariosDoVendedor[(int)$pid] ?? 0);
    }

    // O que sai: os jogadores que o comprador oferece.
    $envia = 0;
    if ($player_ids) {
        $meus = capSalariosDoTime($pdo, $team_id, $liga);
        foreach ($player_ids as $pid) $envia += (int)($meus[(int)$pid] ?? 0);
    }

    $espaco = 0;
    try { $espaco = (int)getTeamCapSummary($pdo, $team_id)['space']; } catch (Throwable $e) {}

    $delta = $recebe - $envia;
    return [
        'aplica' => true,
        'recebe' => $recebe,
        'envia'  => $envia,
        'delta'  => $delta,
        'espaco' => $espaco,
        // Delta <= 0 sempre cabe: quem manda mais salário do que recebe
        // está abrindo espaço, não gastando.
        'cabe'   => $delta <= max(0, $espaco),
    ];
}

/**
 * O desfecho de um leilão finalizado, em uma linha: quem levou e o que deu.
 *
 * Vai junto da listagem do admin (poucos itens, 20 no máximo) pra que a
 * lista já mostre o que aconteceu, em vez de virar um clique por leilão.
 * Devolve null quando o leilão fechou sem troca.
 */
function resumoDaTrocaDoLeilao(PDO $pdo, int $leilao_id, string $jogadorLeiloado): ?array
{
    $ovrCol = playerOvrColumn($pdo);
    $st = $pdo->prepare("SELECT lp.id, lp.obs, CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')) AS time_nome
                         FROM leilao_propostas lp
                         JOIN teams t ON t.id = lp.team_id
                         WHERE lp.leilao_id = ? AND lp.status = 'aceita' LIMIT 1");
    $st->execute([$leilao_id]);
    $prop = $st->fetch(PDO::FETCH_ASSOC);
    if (!$prop) return null;

    $itens = [];
    $st = $pdo->prepare("SELECT p.name, p.position, p.{$ovrCol} AS ovr
                         FROM leilao_proposta_jogadores lpj JOIN players p ON p.id = lpj.player_id
                         WHERE lpj.proposta_id = ?");
    $st->execute([(int)$prop['id']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $j) {
        $itens[] = trim($j['name'] . ' (' . ($j['position'] ?: '?') . ' · ' . ($j['ovr'] ?: '?') . ' OVR)');
    }
    $st = $pdo->prepare("SELECT pk.season_year, pk.round, lpp.swap_type
                         FROM leilao_proposta_picks lpp JOIN picks pk ON pk.id = lpp.pick_id
                         WHERE lpp.proposta_id = ? ORDER BY pk.season_year, pk.round");
    $st->execute([(int)$prop['id']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $pk) {
        $itens[] = $pk['round'] . 'ª rodada ' . $pk['season_year'] . ($pk['swap_type'] ? ' (' . $pk['swap_type'] . ')' : '');
    }

    // O que o vencedor pediu de brinde do vendedor entra como "e ainda levou".
    $extras = [];
    $st = $pdo->prepare("SELECT pl.name, pl.position, pl.{$ovrCol} AS ovr
                         FROM leilao_proposta_extra_players ep JOIN players pl ON pl.id = ep.player_id
                         WHERE ep.proposta_id = ?");
    $st->execute([(int)$prop['id']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $j) {
        $extras[] = trim($j['name'] . ' (' . ($j['position'] ?: '?') . ' · ' . ($j['ovr'] ?: '?') . ' OVR)');
    }
    $st = $pdo->prepare("SELECT pk.season_year, pk.round, ep.swap_type
                         FROM leilao_proposta_extra_picks ep JOIN picks pk ON pk.id = ep.pick_id
                         WHERE ep.proposta_id = ? ORDER BY pk.season_year, pk.round");
    $st->execute([(int)$prop['id']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $pk) {
        $extras[] = $pk['round'] . 'ª rodada ' . $pk['season_year'] . ($pk['swap_type'] ? ' (' . $pk['swap_type'] . ')' : '');
    }

    $texto = trim($prop['time_nome']) . ' levou ' . $jogadorLeiloado
           . ($extras ? ' + ' . implode(' + ', $extras) : '')
           . ' por ' . ($itens ? implode(' + ', $itens) : 'nada (proposta sem itens)');

    return [
        'time'    => trim($prop['time_nome']),
        'deu'     => $itens,
        'levou'   => array_merge([$jogadorLeiloado], $extras),
        'obs'     => $prop['obs'] ?: null,
        'texto'   => $texto,
    ];
}

function excluirLeilaoCancelado($pdo, $body) {
    $leilao_id = (int)($body['leilao_id'] ?? 0);
    if (!$leilao_id) {
        echo json_encode(['success' => false, 'error' => 'ID do leilao nao informado']);
        return;
    }

    $stmt = $pdo->prepare("SELECT status FROM leilao_jogadores WHERE id = ?");
    $stmt->execute([$leilao_id]);
    $status = $stmt->fetchColumn();
    if ($status === false) {
        echo json_encode(['success' => false, 'error' => 'Leilao nao encontrado']);
        return;
    }
    if (!in_array($status, ['cancelado', 'finalizado'], true)) {
        echo json_encode(['success' => false, 'error' => 'So da pra excluir leilao encerrado (cancelado ou finalizado).']);
        return;
    }

    try {
        $pdo->beginTransaction();
        // Filhas das propostas primeiro, depois as propostas, depois o leilão.
        foreach (['leilao_proposta_jogadores', 'leilao_proposta_picks',
                  'leilao_proposta_extra_players', 'leilao_proposta_extra_picks'] as $tab) {
            try {
                $pdo->prepare("DELETE f FROM {$tab} f
                               JOIN leilao_propostas lp ON lp.id = f.proposta_id
                               WHERE lp.leilao_id = ?")->execute([$leilao_id]);
            } catch (Throwable $e) { /* tabela ausente em base antiga */ }
        }
        try { $pdo->prepare("DELETE FROM leilao_mensagens WHERE leilao_id = ?")->execute([$leilao_id]); } catch (Throwable $e) {}
        $pdo->prepare("DELETE FROM leilao_propostas WHERE leilao_id = ?")->execute([$leilao_id]);
        $pdo->prepare("DELETE FROM leilao_jogadores WHERE id = ?")->execute([$leilao_id]);
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('excluirLeilaoCancelado #' . $leilao_id . ': ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Erro ao excluir o leilao.']);
    }
}

function enviarProposta($pdo, $body, $team_id, $league_id) {
    if (!$team_id) {
        echo json_encode(['success' => false, 'error' => 'Voce precisa ter um time para enviar propostas']);
        return;
    }
    
    $leilao_id = $body['leilao_id'] ?? null;
    $player_ids = $body['player_ids'] ?? [];
    $pick_ids = $body['pick_ids'] ?? [];
    $notas = $body['notas'] ?? '';
    $obs = $body['obs'] ?? '';
    $pick_swaps = $body['pick_swaps'] ?? [];
    $extra_player_ids = $body['extra_player_ids'] ?? [];
    $extra_pick_ids = $body['extra_pick_ids'] ?? [];
    $extra_pick_swaps = $body['extra_pick_swaps'] ?? [];
    $is_personalized = !empty($extra_player_ids) || !empty($extra_pick_ids);

    if (!$leilao_id || (empty($player_ids) && empty($pick_ids) && trim($notas) === '')) {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        return;
    }
    
    // Verificar se leilao existe e esta ativo
    $stmt = $pdo->prepare("SELECT * FROM leilao_jogadores WHERE id = ? AND status = 'ativo'");
    $stmt->execute([$leilao_id]);
    $leilao = $stmt->fetch();
    
    if (!$leilao) {
        echo json_encode(['success' => false, 'error' => 'Leilao nao encontrado ou nao esta ativo']);
        return;
    }

    if (!empty($leilao['data_fim']) && strtotime($leilao['data_fim']) <= time()) {
        echo json_encode(['success' => false, 'error' => 'Leilao encerrado']);
        return;
    }
    
    // Nao pode enviar proposta para proprio jogador
    if ($leilao['team_id'] == $team_id) {
        echo json_encode(['success' => false, 'error' => 'Voce nao pode enviar proposta para seu proprio jogador']);
        return;
    }
    
    // Se o leilão não tem time (jogador criado), não aceitar picks
    if (!empty($pick_ids) && empty($leilao['team_id'])) {
        echo json_encode(['success' => false, 'error' => 'Este leilao nao aceita picks (jogador sem time).']);
        return;
    }
    
    if (!empty($player_ids)) {
        $placeholders = implode(',', array_fill(0, count($player_ids), '?'));
        $stmt = $pdo->prepare("SELECT id FROM players WHERE id IN ($placeholders) AND team_id = ?");
        $params = array_merge($player_ids, [$team_id]);
        $stmt->execute($params);
        $jogadores_validos = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($jogadores_validos) !== count($player_ids)) {
            echo json_encode(['success' => false, 'error' => 'Alguns jogadores selecionados nao pertencem ao seu time']);
            return;
        }
    }

    if (!empty($pick_ids)) {
        $placeholders = implode(',', array_fill(0, count($pick_ids), '?'));
        $minSeasonYear = getCurrentSeasonYear($pdo, $league_id);
        $seasonFilter = '';
        $params = array_merge($pick_ids, [$team_id]);
        if ($minSeasonYear) {
            $seasonFilter = ' AND season_year >= ?';
            $params[] = $minSeasonYear;
        }
        $stmt = $pdo->prepare("SELECT id FROM picks WHERE id IN ($placeholders) AND team_id = ?{$seasonFilter}");
        $stmt->execute($params);
        $picks_validas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($picks_validas) !== count($pick_ids)) {
            echo json_encode(['success' => false, 'error' => 'Algumas picks nao pertencem ao seu time ou sao de anos anteriores']);
            return;
        }
    }
    
    // Validar regras de SWAP: apenas round 1, e vendedor deve ter pick do mesmo ano
    if (!empty($pick_swaps) && !empty($leilao['team_id'])) {
        foreach ($pick_swaps as $pickId => $swapType) {
            if (!in_array($swapType, ['SB', 'SW'])) continue;
            $stmtPk = $pdo->prepare("SELECT round, season_year FROM picks WHERE id = ?");
            $stmtPk->execute([$pickId]);
            $pkRow = $stmtPk->fetch(PDO::FETCH_ASSOC);
            if (!$pkRow || (int)$pkRow['round'] !== 1) {
                echo json_encode(['success' => false, 'error' => 'Picks de 2ª rodada não podem ser SWAP']);
                return;
            }
            $stmtSeller = $pdo->prepare("SELECT id FROM picks WHERE team_id = ? AND round = 1 AND season_year = ?");
            $stmtSeller->execute([$leilao['team_id'], $pkRow['season_year']]);
            if (!$stmtSeller->fetch()) {
                echo json_encode(['success' => false, 'error' => 'SWAP inválido: vendedor não tem pick de 1ª rodada de ' . $pkRow['season_year']]);
                return;
            }
        }
    }

    // Validar SWAP para picks extras do vendedor: round 1, comprador deve ter pick do mesmo ano
    if (!empty($extra_pick_swaps) && !empty($leilao['team_id'])) {
        foreach ($extra_pick_swaps as $pickId => $swapType) {
            if (!in_array($swapType, ['SB', 'SW'])) continue;
            $stmtPk = $pdo->prepare("SELECT round, season_year FROM picks WHERE id = ?");
            $stmtPk->execute([$pickId]);
            $pkRow = $stmtPk->fetch(PDO::FETCH_ASSOC);
            if (!$pkRow || (int)$pkRow['round'] !== 1) {
                echo json_encode(['success' => false, 'error' => 'Picks extras de 2ª rodada não podem ser SWAP']);
                return;
            }
            $stmtBuyer = $pdo->prepare("SELECT id FROM picks WHERE team_id = ? AND round = 1 AND season_year = ?");
            $stmtBuyer->execute([$team_id, $pkRow['season_year']]);
            if (!$stmtBuyer->fetch()) {
                echo json_encode(['success' => false, 'error' => 'SWAP inválido: você não tem pick de 1ª rodada de ' . $pkRow['season_year']]);
                return;
            }
        }
    }

    // Validar itens extras pertencem ao vendedor
    if ($is_personalized && empty($leilao['team_id'])) {
        echo json_encode(['success' => false, 'error' => 'Este leilão não aceita oferta personalizada (jogador sem time vendedor).']);
        return;
    }
    if (!empty($extra_player_ids) && !empty($leilao['team_id'])) {
        $placeholders = implode(',', array_fill(0, count($extra_player_ids), '?'));
        $stmt = $pdo->prepare("SELECT id FROM players WHERE id IN ($placeholders) AND team_id = ?");
        $stmt->execute(array_merge($extra_player_ids, [$leilao['team_id']]));
        if ($stmt->rowCount() !== count($extra_player_ids)) {
            echo json_encode(['success' => false, 'error' => 'Alguns jogadores extras não pertencem ao vendedor']);
            return;
        }
    }
    if (!empty($extra_pick_ids) && !empty($leilao['team_id'])) {
        $placeholders = implode(',', array_fill(0, count($extra_pick_ids), '?'));
        $stmt = $pdo->prepare("SELECT id FROM picks WHERE id IN ($placeholders) AND team_id = ?");
        $stmt->execute(array_merge($extra_pick_ids, [$leilao['team_id']]));
        if ($stmt->rowCount() !== count($extra_pick_ids)) {
            echo json_encode(['success' => false, 'error' => 'Algumas picks extras não pertencem ao vendedor']);
            return;
        }
    }

    // Cap: no leilão a única trava é o teto (sem a regra dos 120% da trade).
    $impacto = leilaoImpactoNoCap($pdo, (int)$team_id, $leilao, $player_ids, $extra_player_ids);
    if ($impacto['aplica'] && !$impacto['cabe']) {
        $falta = $impacto['delta'] - max(0, $impacto['espaco']);
        echo json_encode(['success' => false,
            'error' => "Essa proposta te deixa {$falta}M acima do teto: você recebe {$impacto['recebe']}M, "
                     . "manda {$impacto['envia']}M e tem {$impacto['espaco']}M de espaço. "
                     . 'Inclua mais salário na oferta pra fechar a conta.',
            'cap' => $impacto]);
        return;
    }

    $pdo->beginTransaction();

    try {
        // Criar proposta
        $stmt = $pdo->prepare("INSERT INTO leilao_propostas (leilao_id, team_id, notas, obs, is_personalized, status, created_at) VALUES (?, ?, ?, ?, ?, 'pendente', NOW())");
        $stmt->execute([$leilao_id, $team_id, $notas, $obs, $is_personalized ? 1 : 0]);
        $proposta_id = $pdo->lastInsertId();

        // Adicionar jogadores da proposta
        if (!empty($player_ids)) {
            $stmt = $pdo->prepare("INSERT INTO leilao_proposta_jogadores (proposta_id, player_id) VALUES (?, ?)");
            foreach ($player_ids as $pid) {
                $stmt->execute([$proposta_id, $pid]);
            }
        }
        // Adicionar picks da proposta (com swap_type)
        if (!empty($pick_ids)) {
            $stmt = $pdo->prepare("INSERT INTO leilao_proposta_picks (proposta_id, pick_id, swap_type) VALUES (?, ?, ?)");
            foreach ($pick_ids as $pkid) {
                $swapVal = isset($pick_swaps[$pkid]) && in_array($pick_swaps[$pkid], ['SB','SW']) ? $pick_swaps[$pkid] : null;
                $stmt->execute([$proposta_id, $pkid, $swapVal]);
            }
        }
        // Itens extras (oferta personalizada)
        if ($is_personalized) {
            if (!empty($extra_player_ids)) {
                $stmt = $pdo->prepare("INSERT INTO leilao_proposta_extra_players (proposta_id, player_id) VALUES (?, ?)");
                foreach ($extra_player_ids as $pid) { $stmt->execute([$proposta_id, $pid]); }
            }
            if (!empty($extra_pick_ids)) {
                $stmt = $pdo->prepare("INSERT INTO leilao_proposta_extra_picks (proposta_id, pick_id, swap_type) VALUES (?, ?, ?)");
                foreach ($extra_pick_ids as $pkid) {
                    $sw = isset($extra_pick_swaps[$pkid]) && in_array($extra_pick_swaps[$pkid], ['SB','SW']) ? $extra_pick_swaps[$pkid] : null;
                    $stmt->execute([$proposta_id, $pkid, $sw]);
                }
            }
        }

        // Espelha a proposta na timeline de chat do leilão (best-effort: não deve derrubar a proposta em si)
        try {
            $stmt = $pdo->prepare("INSERT INTO leilao_mensagens (leilao_id, team_id, tipo, proposta_id) VALUES (?, ?, 'proposal', ?)");
            $stmt->execute([$leilao_id, $team_id, $proposta_id]);
        } catch (Throwable $e) { /* ignore — a proposta em si já foi salva */ }

        $pdo->commit();

        // Fora da transação: avisa o dono do jogador leiloado que chegou oferta.
        try {
            if (!empty($leilao['team_id'])) {
                $stNome = $pdo->prepare("SELECT CONCAT(city,' ',name) FROM teams WHERE id = ? LIMIT 1");
                $stNome->execute([$team_id]);
                $quem = (string)($stNome->fetchColumn() ?: 'Um time');
                sendPushToTeam($pdo, (int)$leilao['team_id'], [
                    'title' => '💰 Proposta no seu leilão',
                    'body'  => "{$quem} fez uma oferta por " . nomeDoJogadorDoLeilao($pdo, (int)$leilao_id) . '.',
                    'url'   => '/leilao.php',
                ], 'leilao');
            }
        } catch (Throwable $e) {
            error_log('push proposta leilao #' . $leilao_id . ': ' . $e->getMessage());
        }

        // Depois do commit (que já aconteceu acima, antes do push): o
        // WhatsApp não pode segurar a transação, e uma falha lá não pode
        // desfazer a proposta que já foi gravada.
        leilaoBotAoCriarProposta($pdo, (int)$leilao_id, (int)$proposta_id);

        echo json_encode(['success' => true, 'proposta_id' => $proposta_id]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Erro ao salvar proposta']);
    }
}


/**
 * Fecha o leilao: executa de fato a troca da proposta escolhida (status
 * 'aceita') e marca o leilao como finalizado. Chamado pelo botao do vendedor/
 * admin e por resolverLeiloesExpirados() quando os 20min acabam.
 */
function fecharLeilao($pdo, $body, $team_id, $is_admin) {
    $leilao_id = (int)($body['leilao_id'] ?? 0);
    if (!$leilao_id) {
        echo json_encode(['success' => false, 'error' => 'ID do leilao nao informado']);
        return;
    }

    $stmt = $pdo->prepare("SELECT id, team_id, status FROM leilao_jogadores WHERE id = ?");
    $stmt->execute([$leilao_id]);
    $leilao = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$leilao) {
        echo json_encode(['success' => false, 'error' => 'Leilao nao encontrado']);
        return;
    }
    if ($leilao['status'] === 'finalizado') {
        echo json_encode(['success' => false, 'error' => 'Este leilao ja foi fechado']);
        return;
    }
    if (!$is_admin && (empty($leilao['team_id']) || $leilao['team_id'] != $team_id)) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado']);
        return;
    }

    $erro = _fecharLeilaoComEscolhida($pdo, $leilao_id);
    if ($erro !== null) {
        echo json_encode(['success' => false, 'error' => $erro]);
        return;
    }
    echo json_encode(['success' => true, 'message' => 'Leilao fechado e troca executada.']);
}

/**
 * Fechamento manual do admin: escolhe o time vencedor (via proposta_id), o
 * subconjunto de itens dessa proposta que de fato foram enviados, e opcionalmente
 * itens extras do time vendedor. Usado no popup que aparece após o leilão expirar.
 */
function adminFecharLeilao($pdo, $body, bool $is_admin): void {
    if (!$is_admin) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado']);
        return;
    }

    $leilao_id = (int)($body['leilao_id'] ?? 0);
    $proposta_id = (int)($body['proposta_id'] ?? 0);
    $player_ids = array_map('intval', $body['player_ids'] ?? []);
    $pick_ids = array_map('intval', $body['pick_ids'] ?? []);
    $extra_player_ids = array_map('intval', $body['extra_player_ids'] ?? []);
    $extra_pick_ids = array_map('intval', $body['extra_pick_ids'] ?? []);

    if (!$leilao_id || !$proposta_id) {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        return;
    }

    $stmt = $pdo->prepare("SELECT id, team_id, status FROM leilao_jogadores WHERE id = ?");
    $stmt->execute([$leilao_id]);
    $leilao = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$leilao) {
        echo json_encode(['success' => false, 'error' => 'Leilao nao encontrado']);
        return;
    }
    if ($leilao['status'] === 'finalizado') {
        echo json_encode(['success' => false, 'error' => 'Este leilao ja foi fechado']);
        return;
    }

    $stmt = $pdo->prepare("SELECT lp.*, l.player_id, l.team_id as leilao_team_id, l.id as leilao_id,
                                  l.is_temp_player, l.temp_name, l.temp_position, l.temp_age, l.temp_ovr
                           FROM leilao_propostas lp
                           JOIN leilao_jogadores l ON lp.leilao_id = l.id
                           WHERE lp.id = ? AND lp.leilao_id = ?");
    $stmt->execute([$proposta_id, $leilao_id]);
    $proposta = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$proposta) {
        echo json_encode(['success' => false, 'error' => 'Proposta nao encontrada para este leilao']);
        return;
    }

    // Os jogadores/picks marcados precisam ser um subconjunto do que essa proposta realmente ofereceu
    $stmt = $pdo->prepare("SELECT player_id FROM leilao_proposta_jogadores WHERE proposta_id = ?");
    $stmt->execute([$proposta_id]);
    $ofertados_validos = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (array_diff($player_ids, $ofertados_validos)) {
        echo json_encode(['success' => false, 'error' => 'Jogador selecionado nao faz parte da proposta']);
        return;
    }
    $stmt = $pdo->prepare("SELECT pick_id FROM leilao_proposta_picks WHERE proposta_id = ?");
    $stmt->execute([$proposta_id]);
    $picks_validas = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (array_diff($pick_ids, $picks_validas)) {
        echo json_encode(['success' => false, 'error' => 'Pick selecionada nao faz parte da proposta']);
        return;
    }

    // Itens extras precisam pertencer de fato ao time vendedor
    if (!empty($extra_player_ids)) {
        $placeholders = implode(',', array_fill(0, count($extra_player_ids), '?'));
        $stmt = $pdo->prepare("SELECT id FROM players WHERE id IN ($placeholders) AND team_id = ?");
        $stmt->execute(array_merge($extra_player_ids, [$leilao['team_id']]));
        if (count($stmt->fetchAll(PDO::FETCH_COLUMN)) !== count($extra_player_ids)) {
            echo json_encode(['success' => false, 'error' => 'Jogador extra nao pertence ao time vendedor']);
            return;
        }
    }
    if (!empty($extra_pick_ids)) {
        $placeholders = implode(',', array_fill(0, count($extra_pick_ids), '?'));
        $stmt = $pdo->prepare("SELECT id FROM picks WHERE id IN ($placeholders) AND team_id = ?");
        $stmt->execute(array_merge($extra_pick_ids, [$leilao['team_id']]));
        if (count($stmt->fetchAll(PDO::FETCH_COLUMN)) !== count($extra_pick_ids)) {
            echo json_encode(['success' => false, 'error' => 'Pick extra nao pertence ao time vendedor']);
            return;
        }
    }

    $overrideItems = [
        'jogadores' => $player_ids,
        'picks' => $pick_ids,
        'extra_jogadores' => $extra_player_ids,
        'extra_picks' => $extra_pick_ids,
    ];

    $pdo->beginTransaction();
    try {
        _executarTrocaLeilao($pdo, $proposta, $overrideItems);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Leilao fechado e troca executada.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('adminFecharLeilao #' . $leilao_id . ': ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Erro ao executar a troca do leilao.']);
    }
}

/**
 * Encerra o leilão sem executar nenhuma troca (nenhuma proposta foi aceita, ou o
 * admin decidiu não fechar mesmo havendo propostas).
 */
function adminEncerrarLeilaoSemTroca($pdo, $body, bool $is_admin): void {
    if (!$is_admin) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado']);
        return;
    }

    $leilao_id = (int)($body['leilao_id'] ?? 0);
    if (!$leilao_id) {
        echo json_encode(['success' => false, 'error' => 'ID do leilao nao informado']);
        return;
    }

    $stmt = $pdo->prepare("SELECT id, status FROM leilao_jogadores WHERE id = ?");
    $stmt->execute([$leilao_id]);
    $leilao = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$leilao) {
        echo json_encode(['success' => false, 'error' => 'Leilao nao encontrado']);
        return;
    }
    if ($leilao['status'] === 'finalizado') {
        echo json_encode(['success' => false, 'error' => 'Este leilao ja foi fechado']);
        return;
    }

    $pdo->prepare("UPDATE leilao_propostas SET status = 'recusada' WHERE leilao_id = ?")->execute([$leilao_id]);
    $pdo->prepare("UPDATE leilao_jogadores SET status = 'finalizado', proposta_aceita_id = NULL WHERE id = ?")
        ->execute([$leilao_id]);
    // O leilão acabou: o que estava na fila do WhatsApp não tem mais
    // decisão a pedir.
    leilaoBotEncerrarLeilao($pdo, (int)$leilao_id);

    echo json_encode(['success' => true, 'message' => 'Leilao encerrado sem troca.']);
}

/**
 * Executa a troca da proposta escolhida de um leilao. Devolve null em caso de
 * sucesso ou a mensagem de erro. Nao imprime nada — quem chama decide.
 */
function _fecharLeilaoComEscolhida($pdo, int $leilao_id): ?string
{
    $stmt = $pdo->prepare("SELECT lp.*, l.player_id, l.team_id as leilao_team_id, l.id as leilao_id,
                                  l.is_temp_player, l.temp_name, l.temp_position, l.temp_age, l.temp_ovr
                           FROM leilao_propostas lp
                           JOIN leilao_jogadores l ON lp.leilao_id = l.id
                           WHERE lp.leilao_id = ? AND lp.status = 'aceita'
                           ORDER BY lp.id DESC LIMIT 1");
    $stmt->execute([$leilao_id]);
    $escolhida = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$escolhida) {
        return 'Escolha uma proposta antes de fechar o leilao.';
    }

    $nomeJogador = nomeDoJogadorDoLeilao($pdo, $leilao_id);

    $pdo->beginTransaction();
    try {
        _executarTrocaLeilao($pdo, $escolhida);
        $pdo->commit();

        // Depois do commit: vencedor e vendedor sabem que a troca saiu.
        try {
            sendPushToTeam($pdo, (int)$escolhida['team_id'], [
                'title' => '🏆 Leilão vencido!',
                'body'  => "Sua proposta por {$nomeJogador} foi a escolhida. Ele já está no seu elenco.",
                'url'   => '/leilao.php',
            ], 'leilao');
            if (!empty($escolhida['leilao_team_id'])) {
                sendPushToTeam($pdo, (int)$escolhida['leilao_team_id'], [
                    'title' => '✅ Leilão fechado',
                    'body'  => "A troca de {$nomeJogador} foi executada.",
                    'url'   => '/leilao.php',
                ], 'leilao');
            }
        } catch (Throwable $e) {
            error_log('push fecha leilao #' . $leilao_id . ': ' . $e->getMessage());
        }

        return null;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('_fecharLeilaoComEscolhida #' . $leilao_id . ': ' . $e->getMessage());
        return 'Erro ao executar a troca do leilao.';
    }
}

/**
 * Rede de seguranca (o projeto nao tem cron para leilao): fecha sozinho os
 * leiloes cujos 20min acabaram E que ja tem uma proposta escolhida. Sem escolha,
 * o leilao continua aberto esperando a decisao do vendedor.
 */
function resolverLeiloesExpirados(PDO $pdo): void
{
    try {
        $stmt = $pdo->query("SELECT l.id
                             FROM leilao_jogadores l
                             WHERE l.status = 'ativo'
                               AND l.data_fim IS NOT NULL AND l.data_fim <= NOW()
                               AND EXISTS (SELECT 1 FROM leilao_propostas p
                                           WHERE p.leilao_id = l.id AND p.status = 'aceita')");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            _fecharLeilaoComEscolhida($pdo, (int)$id);
        }
    } catch (Throwable $e) {
        // Best-effort: roda a cada listagem, nao pode derrubar a pagina.
        error_log('resolverLeiloesExpirados: ' . $e->getMessage());
    }
}

/**
 * Núcleo da execução de uma troca de leilão (jogador leiloado -> vencedor,
 * ofertas do vencedor -> vendedor, itens extras -> vencedor).
 * NÃO abre/fecha transação nem imprime — o chamador cuida disso.
 * $proposta deve conter os campos do JOIN leilao_propostas + leilao_jogadores
 * (id, team_id, leilao_id, player_id, leilao_team_id, is_temp_player, temp_*, is_personalized).
 *
 * $overrideItems, quando informado (fechamento manual pelo admin), substitui a
 * leitura das tabelas leilao_proposta_* pelas listas explícitas escolhidas:
 * ['jogadores' => [ids], 'picks' => [ids], 'extra_jogadores' => [ids], 'extra_picks' => [ids]].
 */
function _executarTrocaLeilao($pdo, $proposta, ?array $overrideItems = null) {
    $proposta_id = $proposta['id'];

    // Marcar proposta como aceita
    $pdo->prepare("UPDATE leilao_propostas SET status = 'aceita' WHERE id = ?")->execute([$proposta_id]);
    // Marcar outras propostas como recusadas
    $pdo->prepare("UPDATE leilao_propostas SET status = 'recusada' WHERE leilao_id = ? AND id != ?")
        ->execute([$proposta['leilao_id'], $proposta_id]);
    // Finalizar leilao
    $pdo->prepare("UPDATE leilao_jogadores SET status = 'finalizado', proposta_aceita_id = ? WHERE id = ?")
        ->execute([$proposta_id, $proposta['leilao_id']]);
    // O leilão acabou: o que estava na fila do WhatsApp não tem mais
    // decisão a pedir.
    leilaoBotEncerrarLeilao($pdo, (int)$proposta['leilao_id']);

    if ($overrideItems !== null) {
        $jogadores_oferecidos = array_map('intval', $overrideItems['jogadores'] ?? []);
        $picks_oferecidas = array_map('intval', $overrideItems['picks'] ?? []);
    } else {
        // Buscar jogadores/picks oferecidos na proposta
        $stmt = $pdo->prepare("SELECT player_id FROM leilao_proposta_jogadores WHERE proposta_id = ?");
        $stmt->execute([$proposta_id]);
        $jogadores_oferecidos = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare("SELECT pick_id FROM leilao_proposta_picks WHERE proposta_id = ?");
        $stmt->execute([$proposta_id]);
        $picks_oferecidas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $winnerTeamId = $proposta['team_id'];
    // Quem MUDA de time chega no banco. Sem isso o jogador carregava o papel
    // do time anterior e um titular arrematado virava o sexto do quinteto do
    // vencedor. Mesma regra da trade, do draft, da FA e do waiver.
    $transferStmt = $pdo->prepare("UPDATE players SET team_id = ?, role = 'Banco' WHERE id = ?");

    // Se for jogador criado especificamente para o leilao, criar no time vencedor agora
    if (empty($proposta['player_id']) && !empty($proposta['is_temp_player'])) {
        $ovrColumn = playerOvrColumn($pdo);
        // role explícito: a coluna tem DEFAULT 'Titular', então omitir aqui
        // fazia o jogador do leilão nascer titular.
        $stmtCreate = $pdo->prepare("INSERT INTO players (team_id, name, age, position, {$ovrColumn}, role) VALUES (?, ?, ?, ?, ?, 'Banco')");
        $stmtCreate->execute([
            $winnerTeamId,
            $proposta['temp_name'],
            $proposta['temp_age'],
            $proposta['temp_position'],
            $proposta['temp_ovr']
        ]);
        $proposta['player_id'] = $pdo->lastInsertId();
        $pdo->prepare("UPDATE leilao_jogadores SET player_id = ?, team_id = ? WHERE id = ?")
            ->execute([$proposta['player_id'], $winnerTeamId, $proposta['leilao_id']]);
    }

    // Transferir jogador do leilao para o time vencedor (se existir player_id)
    if (!empty($proposta['player_id'])) {
        $transferStmt->execute([$winnerTeamId, $proposta['player_id']]);
    }

    // Transferir jogadores/picks oferecidos para o time do leilao (vendedor)
    if (!empty($proposta['leilao_team_id'])) {
        foreach ($jogadores_oferecidos as $player_id) {
            $transferStmt->execute([$proposta['leilao_team_id'], $player_id]);
        }
        if (!empty($picks_oferecidas)) {
            $placeholders = implode(',', array_fill(0, count($picks_oferecidas), '?'));
            $params = array_merge([$proposta['leilao_team_id']], $picks_oferecidas);
            $pdo->prepare("UPDATE picks SET team_id = ? WHERE id IN ($placeholders)")->execute($params);
        }
    }

    // Itens extras do vendedor -> vencedor (oferta personalizada, ou escolha manual do admin)
    if ($overrideItems !== null) {
        $extra_jogadores = array_map('intval', $overrideItems['extra_jogadores'] ?? []);
        $extra_picks = array_map('intval', $overrideItems['extra_picks'] ?? []);
        foreach ($extra_jogadores as $pid) {
            $transferStmt->execute([$winnerTeamId, $pid]);
        }
        if (!empty($extra_picks)) {
            $placeholders = implode(',', array_fill(0, count($extra_picks), '?'));
            $pdo->prepare("UPDATE picks SET team_id = ? WHERE id IN ($placeholders)")
                ->execute(array_merge([$winnerTeamId], $extra_picks));
        }
    } elseif (!empty($proposta['is_personalized'])) {
        $stmt = $pdo->prepare("SELECT player_id FROM leilao_proposta_extra_players WHERE proposta_id = ?");
        $stmt->execute([$proposta_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $transferStmt->execute([$winnerTeamId, $pid]);
        }
        $stmt = $pdo->prepare("SELECT pick_id FROM leilao_proposta_extra_picks WHERE proposta_id = ?");
        $stmt->execute([$proposta_id]);
        $extra_picks = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($extra_picks)) {
            $placeholders = implode(',', array_fill(0, count($extra_picks), '?'));
            $pdo->prepare("UPDATE picks SET team_id = ? WHERE id IN ($placeholders)")
                ->execute(array_merge([$winnerTeamId], $extra_picks));
        }
    }
}

/**
 * Cadastro manual de um leilão já ocorrido (admin): registra o jogador leiloado,
 * o time vendedor, o time vencedor e o que o vencedor enviou (jogadores/picks),
 * e executa a transferência como uma troca — reusando a mesma engine do aceite.
 */
function cadastrarLeilaoManual($pdo, $body, $league_id) {
    $sellerTeamId   = (int)($body['seller_team_id'] ?? 0);
    $winnerTeamId   = (int)($body['winner_team_id'] ?? 0);
    $playerId       = (int)($body['auctioned_player_id'] ?? 0);
    $offeredPlayers = array_values(array_unique(array_map('intval', $body['offered_player_ids'] ?? [])));
    $offeredPicks   = array_values(array_unique(array_map('intval', $body['offered_pick_ids'] ?? [])));
    // O vendedor também pode ter jogado coisa junto com o jogador leiloado —
    // é a "oferta personalizada" do fluxo normal, e no WhatsApp acontece
    // bastante. Vai do vendedor pro vencedor, junto com o leiloado.
    $extraPlayers   = array_values(array_unique(array_map('intval', $body['extra_player_ids'] ?? [])));
    $extraPicks     = array_values(array_unique(array_map('intval', $body['extra_pick_ids'] ?? [])));
    $obs            = trim((string)($body['obs'] ?? ''));

    if (!$sellerTeamId || !$winnerTeamId || !$playerId) {
        echo json_encode(['success' => false, 'error' => 'Informe o time vendedor, o jogador leiloado e o time vencedor.']);
        return;
    }
    if ($sellerTeamId === $winnerTeamId) {
        echo json_encode(['success' => false, 'error' => 'O vendedor e o vencedor não podem ser o mesmo time.']);
        return;
    }
    $offeredPlayers = array_values(array_filter($offeredPlayers));
    $offeredPicks   = array_values(array_filter($offeredPicks));
    // O leiloado já vai sozinho: listá-lo de novo como extra tentaria
    // transferir o mesmo jogador duas vezes.
    $extraPlayers = array_values(array_filter($extraPlayers, fn($p) => $p && $p !== $playerId));
    $extraPicks   = array_values(array_filter($extraPicks));
    if (!$offeredPlayers && !$offeredPicks) {
        echo json_encode(['success' => false, 'error' => 'O vencedor precisa enviar ao menos um jogador ou pick.']);
        return;
    }

    // Liga: derivada do time vendedor; o vencedor tem que ser da mesma liga.
    $sellerLeagueStmt = $pdo->prepare("SELECT league FROM teams WHERE id = ?");
    $sellerLeagueStmt->execute([$sellerTeamId]);
    $leagueName = $sellerLeagueStmt->fetchColumn();
    if (!$leagueName) {
        echo json_encode(['success' => false, 'error' => 'Time vendedor inválido.']);
        return;
    }
    $chkTeam = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE id = ? AND league = ?");
    $chkTeam->execute([$winnerTeamId, $leagueName]);
    if (!$chkTeam->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'Vendedor e vencedor precisam ser da mesma liga.']);
        return;
    }

    // Jogador leiloado precisa ser do vendedor.
    $chkPlayer = $pdo->prepare("SELECT COUNT(*) FROM players WHERE id = ? AND team_id = ?");
    $chkPlayer->execute([$playerId, $sellerTeamId]);
    if (!$chkPlayer->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'O jogador leiloado não pertence ao time vendedor.']);
        return;
    }

    // Jogadores/picks enviados precisam ser do vencedor.
    if ($offeredPlayers) {
        $ph = implode(',', array_fill(0, count($offeredPlayers), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE id IN ($ph) AND team_id = ?");
        $stmt->execute(array_merge($offeredPlayers, [$winnerTeamId]));
        if ((int)$stmt->fetchColumn() !== count($offeredPlayers)) {
            echo json_encode(['success' => false, 'error' => 'Algum jogador enviado não pertence ao time vencedor.']);
            return;
        }
    }
    if ($offeredPicks) {
        $ph = implode(',', array_fill(0, count($offeredPicks), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM picks WHERE id IN ($ph) AND team_id = ?");
        $stmt->execute(array_merge($offeredPicks, [$winnerTeamId]));
        if ((int)$stmt->fetchColumn() !== count($offeredPicks)) {
            echo json_encode(['success' => false, 'error' => 'Alguma pick enviada não pertence ao time vencedor.']);
            return;
        }
    }

    // Itens extras precisam ser do vendedor — é ele quem os manda junto.
    if ($extraPlayers) {
        $ph = implode(',', array_fill(0, count($extraPlayers), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE id IN ($ph) AND team_id = ?");
        $stmt->execute(array_merge($extraPlayers, [$sellerTeamId]));
        if ((int)$stmt->fetchColumn() !== count($extraPlayers)) {
            echo json_encode(['success' => false, 'error' => 'Algum jogador extra não pertence ao time vendedor.']);
            return;
        }
    }
    if ($extraPicks) {
        $ph = implode(',', array_fill(0, count($extraPicks), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM picks WHERE id IN ($ph) AND team_id = ?");
        $stmt->execute(array_merge($extraPicks, [$sellerTeamId]));
        if ((int)$stmt->fetchColumn() !== count($extraPicks)) {
            echo json_encode(['success' => false, 'error' => 'Alguma pick extra não pertence ao time vendedor.']);
            return;
        }
    }

    // league_id p/ o registro (resolve pelo nome se a sessão não trouxe).
    $leagueIdResolved = $league_id;
    if (!$leagueIdResolved) {
        $lst = $pdo->prepare("SELECT id FROM leagues WHERE name = ? LIMIT 1");
        $lst->execute([$leagueName]);
        $leagueIdResolved = $lst->fetchColumn() ?: null;
    }

    $pdo->beginTransaction();
    try {
        // Leilão finalizado manualmente
        $stmt = $pdo->prepare("INSERT INTO leilao_jogadores (player_id, team_id, league_id, data_inicio, data_fim, status)
                               VALUES (?, ?, ?, NOW(), NOW(), 'pendente')");
        $stmt->execute([$playerId, $sellerTeamId, $leagueIdResolved]);
        $leilaoId = (int)$pdo->lastInsertId();

        // Proposta vencedora
        $notaTxt = $obs !== '' ? $obs : 'Cadastro manual de leilão';
        $personalizada = ($extraPlayers || $extraPicks) ? 1 : 0;
        $stmt = $pdo->prepare("INSERT INTO leilao_propostas (leilao_id, team_id, obs, status, is_personalized)
                               VALUES (?, ?, ?, 'pendente', ?)");
        $stmt->execute([$leilaoId, $winnerTeamId, $notaTxt, $personalizada]);
        $propostaId = (int)$pdo->lastInsertId();

        // Itens enviados pelo vencedor
        if ($offeredPlayers) {
            $ins = $pdo->prepare("INSERT INTO leilao_proposta_jogadores (proposta_id, player_id) VALUES (?, ?)");
            foreach ($offeredPlayers as $pid) { $ins->execute([$propostaId, $pid]); }
        }
        if ($offeredPicks) {
            $ins = $pdo->prepare("INSERT INTO leilao_proposta_picks (proposta_id, pick_id) VALUES (?, ?)");
            foreach ($offeredPicks as $pid) { $ins->execute([$propostaId, $pid]); }
        }

        // Itens que o vendedor mandou junto com o leiloado
        if ($extraPlayers) {
            $ins = $pdo->prepare("INSERT INTO leilao_proposta_extra_players (proposta_id, player_id) VALUES (?, ?)");
            foreach ($extraPlayers as $pid) { $ins->execute([$propostaId, $pid]); }
        }
        if ($extraPicks) {
            $ins = $pdo->prepare("INSERT INTO leilao_proposta_extra_picks (proposta_id, pick_id, swap_type) VALUES (?, ?, NULL)");
            foreach ($extraPicks as $pid) { $ins->execute([$propostaId, $pid]); }
        }

        // Executa a troca com a mesma engine do aceite normal
        $proposta = [
            'id'              => $propostaId,
            'team_id'         => $winnerTeamId,
            'leilao_id'       => $leilaoId,
            'player_id'       => $playerId,
            'leilao_team_id'  => $sellerTeamId,
            'is_temp_player'  => 0,
            'is_personalized' => $personalizada,
        ];
        _executarTrocaLeilao($pdo, $proposta);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Leilão cadastrado e troca executada com sucesso.', 'leilao_id' => $leilaoId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Erro ao cadastrar o leilão.']);
    }
}

function reverterLeilao($pdo, $body) {
    $leilao_id = $body['leilao_id'] ?? null;
    if (!$leilao_id) {
        echo json_encode(['success' => false, 'error' => 'ID do leilão não informado']);
        return;
    }

    $stmt = $pdo->prepare("SELECT l.*, lp.team_id as winner_team_id
                           FROM leilao_jogadores l
                           LEFT JOIN leilao_propostas lp ON lp.id = l.proposta_aceita_id
                           WHERE l.id = ?");
    $stmt->execute([$leilao_id]);
    $leilao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$leilao) {
        echo json_encode(['success' => false, 'error' => 'Leilão não encontrado']);
        return;
    }
    if ($leilao['status'] !== 'finalizado') {
        echo json_encode(['success' => false, 'error' => 'Apenas leilões finalizados podem ser revertidos']);
        return;
    }

    $proposta_id = $leilao['proposta_aceita_id'];
    $origin_team_id = $leilao['team_id'];
    $winner_team_id = $leilao['winner_team_id'];
    $player_id = $leilao['player_id'];

    $pdo->beginTransaction();
    try {
        // Retornar jogador do leilão ao time de origem.
        //
        // Também volta pro banco, e não pro papel que tinha antes: ninguém
        // guardou qual era. Voltar como titular seria um chute que estoura o
        // quinteto quando o time já preencheu a vaga que ele deixou — e é
        // justamente isso que um time faz quando perde um titular.
        if ($player_id && $origin_team_id) {
            $pdo->prepare("UPDATE players SET team_id = ?, role = 'Banco' WHERE id = ?")->execute([$origin_team_id, $player_id]);
        }

        if ($proposta_id) {
            // Retornar jogadores oferecidos ao time vencedor
            $stmt2 = $pdo->prepare("SELECT player_id FROM leilao_proposta_jogadores WHERE proposta_id = ?");
            $stmt2->execute([$proposta_id]);
            $jogadores_oferecidos = $stmt2->fetchAll(PDO::FETCH_COLUMN);
            if ($winner_team_id && !empty($jogadores_oferecidos)) {
                $upd = $pdo->prepare("UPDATE players SET team_id = ?, role = 'Banco' WHERE id = ?");
                foreach ($jogadores_oferecidos as $pid) {
                    $upd->execute([$winner_team_id, $pid]);
                }
            }

            // Retornar picks oferecidas ao time vencedor
            $stmt3 = $pdo->prepare("SELECT pick_id FROM leilao_proposta_picks WHERE proposta_id = ?");
            $stmt3->execute([$proposta_id]);
            $picks_oferecidas = $stmt3->fetchAll(PDO::FETCH_COLUMN);
            if ($winner_team_id && !empty($picks_oferecidas)) {
                $placeholders = implode(',', array_fill(0, count($picks_oferecidas), '?'));
                $params = array_merge([$winner_team_id], $picks_oferecidas);
                $pdo->prepare("UPDATE picks SET team_id = ? WHERE id IN ($placeholders)")->execute($params);
            }

            // Revert itens extras (personalized): foram do vendedor (origin) → vencedor. Revertemos: vencedor → origin.
            if ($origin_team_id) {
                $stmtPers = $pdo->prepare("SELECT is_personalized FROM leilao_propostas WHERE id = ?");
                $stmtPers->execute([$proposta_id]);
                $persRow = $stmtPers->fetch(PDO::FETCH_ASSOC);
                if (!empty($persRow['is_personalized'])) {
                    $stmtEP = $pdo->prepare("SELECT player_id FROM leilao_proposta_extra_players WHERE proposta_id = ?");
                    $stmtEP->execute([$proposta_id]);
                    $updP = $pdo->prepare("UPDATE players SET team_id = ?, role = 'Banco' WHERE id = ?");
                    foreach ($stmtEP->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                        $updP->execute([$origin_team_id, $pid]);
                    }
                    $stmtEPk = $pdo->prepare("SELECT pick_id FROM leilao_proposta_extra_picks WHERE proposta_id = ?");
                    $stmtEPk->execute([$proposta_id]);
                    $extraPicksRev = $stmtEPk->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($extraPicksRev)) {
                        $placeholders = implode(',', array_fill(0, count($extraPicksRev), '?'));
                        $pdo->prepare("UPDATE picks SET team_id = ? WHERE id IN ($placeholders)")
                            ->execute(array_merge([$origin_team_id], $extraPicksRev));
                    }
                }
            }

            // Marcar proposta como revertida (usa 'recusada' para compatibilidade)
            $pdo->prepare("UPDATE leilao_propostas SET status = 'recusada' WHERE id = ?")->execute([$proposta_id]);
        }

        // Reverter status do leilão para cancelado e limpar proposta aceita
        $pdo->prepare("UPDATE leilao_jogadores SET status = 'cancelado', proposta_aceita_id = NULL WHERE id = ?")->execute([$leilao_id]);
        // O leilão acabou: o que estava na fila do WhatsApp não tem mais
        // decisão a pedir.
        leilaoBotEncerrarLeilao($pdo, (int)$leilao_id);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Leilão revertido com sucesso']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Erro ao reverter']);
    }
}


function enviarMensagemLeilao(PDO $pdo, $body, ?int $team_id): void {
    if (!$team_id) {
        echo json_encode(['success' => false, 'error' => 'Você precisa ter um time para enviar mensagens']);
        return;
    }
    $leilao_id = (int)($body['leilao_id'] ?? 0);
    $texto = trim((string)($body['texto'] ?? ''));

    if (!$leilao_id || $texto === '') {
        echo json_encode(['success' => false, 'error' => 'Mensagem vazia']);
        return;
    }
    if (mb_strlen($texto) > 1000) {
        echo json_encode(['success' => false, 'error' => 'Mensagem muito longa (máx. 1000 caracteres)']);
        return;
    }

    $stmt = $pdo->prepare("SELECT id, status, data_fim FROM leilao_jogadores WHERE id = ?");
    $stmt->execute([$leilao_id]);
    $leilao = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$leilao) {
        echo json_encode(['success' => false, 'error' => 'Leilão não encontrado']);
        return;
    }
    if ($leilao['status'] !== 'ativo' || (!empty($leilao['data_fim']) && strtotime($leilao['data_fim']) <= time())) {
        echo json_encode(['success' => false, 'error' => 'Leilao encerrado']);
        return;
    }

    $ins = $pdo->prepare("INSERT INTO leilao_mensagens (leilao_id, team_id, tipo, texto) VALUES (?, ?, 'text', ?)");
    $ins->execute([$leilao_id, $team_id, $texto]);

    echo json_encode(['success' => true]);
}

/** Invólucros do endpoint: a regra mora em backend/leilao_decisao.php. */
function aceitarProposta($pdo, $body, $team_id, $is_admin) {
    echo json_encode(leilaoDecidirAceitar($pdo, (array)$body, $team_id, (bool)$is_admin));
}

function recusarProposta($pdo, $body, $team_id, $is_admin) {
    echo json_encode(leilaoDecidirRecusar($pdo, (array)$body, $team_id, (bool)$is_admin));
}
