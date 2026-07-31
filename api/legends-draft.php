<?php
/**
 * Draft de Lendas — depois que a Roleta dos 32 termina, a ordem de pick vira
 * a ordem do draft (quem tem a escolha 1 é o primeiro a escolher). Cada GM
 * escolhe 1 jogador (nome + posição digitados à mão, sem base de dados —
 * a elegibilidade do jogador é regra de confiança, ver rules abaixo),
 * salvo sempre com OVR 80 e 19 anos. Depois que os 32 escolherem, cada GM
 * customiza as badges do próprio jogador com um orçamento de 30 tokens.
 */

require_once __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}
$user_id = (int)$_SESSION['user_id'];
$is_admin = hasAdminAccess($pdo, $user_id);

/** Custo acumulado (em tokens) pra deixar uma badge num tier, a partir de 0. */
const TIER_CUSTO = [
    'bronze' => 1,
    'silver' => 2,
    'gold'   => 3,
    'hof'    => 5,
    'legend' => 8,
];
const TOKENS_ORCAMENTO = 30;

/** Catálogo fixo das badges (NBA 2K26), por categoria. */
const BADGES_CATALOGO = [
    ['key' => 'aerial_wizard',        'label' => 'Aerial Wizard',        'categoria' => 'Inside Scoring'],
    ['key' => 'float_game',           'label' => 'Float Game',           'categoria' => 'Inside Scoring'],
    ['key' => 'hook_specialist',      'label' => 'Hook Specialist',      'categoria' => 'Inside Scoring'],
    ['key' => 'layup_mixmaster',      'label' => 'Layup Mixmaster',      'categoria' => 'Inside Scoring'],
    ['key' => 'paint_prodigy',        'label' => 'Paint Prodigy',        'categoria' => 'Inside Scoring'],
    ['key' => 'physical_finisher',    'label' => 'Physical Finisher',    'categoria' => 'Inside Scoring'],
    ['key' => 'post_fade_phenom',     'label' => 'Post Fade Phenom',     'categoria' => 'Inside Scoring'],
    ['key' => 'post_powerhouse',      'label' => 'Post Powerhouse',      'categoria' => 'Inside Scoring'],
    ['key' => 'post_up_poet',         'label' => 'Post-Up Poet',         'categoria' => 'Inside Scoring'],
    ['key' => 'posterizer',           'label' => 'Posterizer',           'categoria' => 'Inside Scoring'],
    ['key' => 'rise_up',              'label' => 'Rise Up',              'categoria' => 'Inside Scoring'],

    ['key' => 'deadeye',              'label' => 'Deadeye',              'categoria' => 'Outside Scoring'],
    ['key' => 'limitless_range',      'label' => 'Limitless Range',      'categoria' => 'Outside Scoring'],
    ['key' => 'mini_marksman',        'label' => 'Mini Marksman',        'categoria' => 'Outside Scoring'],
    ['key' => 'set_shot_specialist',  'label' => 'Set Shot Specialist',  'categoria' => 'Outside Scoring'],
    ['key' => 'shifty_shooter',       'label' => 'Shifty Shooter',       'categoria' => 'Outside Scoring'],

    ['key' => 'boxout_beast',         'label' => 'Boxout Beast',         'categoria' => 'Rebounding'],
    ['key' => 'rebound_chaser',       'label' => 'Rebound Chaser',       'categoria' => 'Rebounding'],

    ['key' => 'ankle_assassin',       'label' => 'Ankle Assassin',       'categoria' => 'Playmaking'],
    ['key' => 'bail_out',             'label' => 'Bail Out',             'categoria' => 'Playmaking'],
    ['key' => 'break_starter',        'label' => 'Break Starter',        'categoria' => 'Playmaking'],
    ['key' => 'dimer',                'label' => 'Dimer',                'categoria' => 'Playmaking'],
    ['key' => 'handles_for_days',     'label' => 'Handles for Days',     'categoria' => 'Playmaking'],
    ['key' => 'lightning_launch',     'label' => 'Lightning Launch',     'categoria' => 'Playmaking'],
    ['key' => 'strong_handle',        'label' => 'Strong Handle',        'categoria' => 'Playmaking'],
    ['key' => 'unpluckable',          'label' => 'Unpluckable',          'categoria' => 'Playmaking'],
    ['key' => 'versatile_visionary',  'label' => 'Versatile Visionary',  'categoria' => 'Playmaking'],

    ['key' => 'challenger',           'label' => 'Challenger',           'categoria' => 'Defesa'],
    ['key' => 'glove',                'label' => 'Glove',                'categoria' => 'Defesa'],
    ['key' => 'high_flying_denier',   'label' => 'High-Flying Denier',   'categoria' => 'Defesa'],
    ['key' => 'immovable_enforcer',   'label' => 'Immovable Enforcer',   'categoria' => 'Defesa'],
    ['key' => 'interceptor',          'label' => 'Interceptor',          'categoria' => 'Defesa'],
    ['key' => 'off_ball_pest',        'label' => 'Off-Ball Pest',        'categoria' => 'Defesa'],
    ['key' => 'on_ball_menace',       'label' => 'On-Ball Menace',       'categoria' => 'Defesa'],
    ['key' => 'paint_patroller',      'label' => 'Paint Patroller',      'categoria' => 'Defesa'],
    ['key' => 'pick_dodger',          'label' => 'Pick Dodger',          'categoria' => 'Defesa'],
    ['key' => 'post_lockdown',        'label' => 'Post Lockdown',        'categoria' => 'Defesa'],

    ['key' => 'brick_wall',           'label' => 'Brick Wall',           'categoria' => 'Ataque Geral'],
    ['key' => 'slippery_off_ball',    'label' => 'Slippery Off-Ball',    'categoria' => 'Ataque Geral'],

    ['key' => 'pogo_stick',           'label' => 'Pogo Stick',           'categoria' => 'All Around'],
];

function ensureLegendsDraftTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS legends_draft_picks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pick_number INT NOT NULL,
        team_id INT NULL,
        user_id INT NULL,
        gm_name VARCHAR(180) NOT NULL,
        player_name VARCHAR(150) NULL,
        player_position VARCHAR(10) NULL,
        ovr INT NULL,
        age INT NULL,
        picked_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ldp_pick (pick_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS legends_draft_badges (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pick_id INT NOT NULL,
        badge_key VARCHAR(60) NOT NULL,
        tier ENUM('bronze','silver','gold','hof','legend') NOT NULL,
        tokens_cost INT NOT NULL,
        UNIQUE KEY uniq_ldb_pick_badge (pick_id, badge_key),
        CONSTRAINT fk_ldb_pick FOREIGN KEY (pick_id) REFERENCES legends_draft_picks(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Linha única (id=1) que guarda se o admin já "fechou" o draft — enquanto
    // não fecha, o quadro completo continua visível mesmo com os 32 escolhidos.
    $pdo->exec("CREATE TABLE IF NOT EXISTS legends_draft_status (
        id INT PRIMARY KEY DEFAULT 1,
        finalizado_em TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO legends_draft_status (id, finalizado_em) VALUES (1, NULL)");
}
ensureLegendsDraftTables($pdo);

/**
 * Semeia legends_draft_picks a partir do roleta_times (só quando ele já
 * terminou e ainda não semeamos). O casamento de user_id é o mesmo já usado
 * pra notificação da roleta: team_name -> teams.city+name -> teams.user_id.
 */
function garantirSeedDoDraft(PDO $pdo): void
{
    try {
        $totalRoleta = (int)$pdo->query("SELECT COUNT(*) FROM roleta_times")->fetchColumn();
    } catch (Throwable $e) {
        return; // roleta_times ainda nem existe
    }
    if ($totalRoleta === 0) return;

    $faltam = (int)$pdo->query("SELECT COUNT(*) FROM roleta_times WHERE pick_number IS NULL")->fetchColumn();
    if ($faltam > 0) return; // roleta ainda não terminou

    $jaTem = (int)$pdo->query("SELECT COUNT(*) FROM legends_draft_picks")->fetchColumn();
    if ($jaTem >= $totalRoleta) return; // já semeado por completo

    // INSERT IGNORE + UNIQUE KEY(pick_number): idempotente mesmo se dois
    // acessos caírem aqui ao mesmo tempo, ou se uma tentativa anterior tiver
    // semeado só parte das linhas — só completa o que falta.
    try {
        $stmt = $pdo->query("
            SELECT rt.pick_number, rt.gm_name, rt.team_name, t.id AS team_id, t.user_id
            FROM roleta_times rt
            LEFT JOIN teams t ON CONCAT(t.city,' ',t.name) = rt.team_name
            ORDER BY rt.pick_number ASC
        ");
        $ins = $pdo->prepare("INSERT IGNORE INTO legends_draft_picks (pick_number, team_id, user_id, gm_name) VALUES (?,?,?,?)");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ins->execute([(int)$r['pick_number'], $r['team_id'] ? (int)$r['team_id'] : null, $r['user_id'] ? (int)$r['user_id'] : null, $r['gm_name']]);
        }
    } catch (Throwable $e) {
        error_log('legends-draft seed: ' . $e->getMessage());
    }
}
garantirSeedDoDraft($pdo);

function estadoDraft(PDO $pdo, int $sessionUserId, bool $isAdmin): array
{
    $finalizadoEm = $pdo->query("SELECT finalizado_em FROM legends_draft_status WHERE id = 1")->fetchColumn();

    $picks = $pdo->query("
        SELECT ldp.id, ldp.pick_number, ldp.team_id, ldp.user_id, ldp.gm_name,
               ldp.player_name, ldp.player_position, ldp.ovr, ldp.age, ldp.picked_at,
               t.photo_url
        FROM legends_draft_picks ldp
        LEFT JOIN teams t ON t.id = ldp.team_id
        ORDER BY ldp.pick_number ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $vezPickNumber = null;
    $meuPick = null;
    foreach ($picks as $p) {
        if ($p['player_name'] === null && $vezPickNumber === null) $vezPickNumber = (int)$p['pick_number'];
        if ((int)($p['user_id'] ?? 0) === $sessionUserId) $meuPick = $p;
    }

    $draftCompleto = count($picks) > 0 && $vezPickNumber === null;

    $badgesDoMeuPick = [];
    $tokensUsados = 0;
    if ($meuPick) {
        $stmtB = $pdo->prepare("SELECT badge_key, tier, tokens_cost FROM legends_draft_badges WHERE pick_id = ?");
        $stmtB->execute([$meuPick['id']]);
        foreach ($stmtB->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $badgesDoMeuPick[$b['badge_key']] = $b['tier'];
            $tokensUsados += (int)$b['tokens_cost'];
        }
    }

    return [
        'picks' => $picks,
        'total' => count($picks),
        'vez_pick_number' => $vezPickNumber,
        'draft_completo' => $draftCompleto,
        'meu_pick' => $meuPick,
        'minhas_badges' => $badgesDoMeuPick,
        'tokens_usados' => $tokensUsados,
        'tokens_orcamento' => TOKENS_ORCAMENTO,
        'catalogo_badges' => BADGES_CATALOGO,
        'tier_custo' => TIER_CUSTO,
        'finalizado' => $finalizadoEm !== false && $finalizadoEm !== null,
        'is_admin' => $isAdmin,
    ];
}

/**
 * Avisa todo mundo que participa do draft ($gmName escolheu $playerName) e,
 * separadamente, manda um aviso só pra quem é a vez agora — mesmo padrão
 * best-effort de notificarSaidaRoleta() em api/roleta-times.php (nunca
 * quebra o fluxo da escolha por causa de notificação).
 */
function notificarEscolhaLendas(PDO $pdo, string $gmName, string $playerName): void
{
    $pushFile = dirname(__DIR__) . '/backend/push.php';
    if (!file_exists($pushFile)) return;
    require_once $pushFile;

    try {
        $userIds = $pdo->query("SELECT DISTINCT user_id FROM legends_draft_picks WHERE user_id IS NOT NULL")
            ->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log('notificarEscolhaLendas (participantes): ' . $e->getMessage());
        return;
    }

    $payloadEscolha = [
        'title' => 'Draft de Lendas ⭐',
        'body'  => "{$gmName} escolheu {$playerName}!",
        'url'   => '/legends-draft.php',
    ];
    foreach ($userIds as $uid) {
        try {
            sendPushToUser($pdo, (int)$uid, $payloadEscolha);
        } catch (Throwable $e) {
            error_log('notificarEscolhaLendas (push user_id=' . $uid . '): ' . $e->getMessage());
        }
    }

    // Aviso pessoal pra quem é a vez agora (a próxima pick ainda sem jogador).
    try {
        $stmtProx = $pdo->query("SELECT user_id FROM legends_draft_picks WHERE player_name IS NULL ORDER BY pick_number ASC LIMIT 1");
        $proximoUserId = $stmtProx->fetchColumn();
    } catch (Throwable $e) {
        $proximoUserId = false;
    }
    if ($proximoUserId) {
        try {
            sendPushToUser($pdo, (int)$proximoUserId, [
                'title' => 'Draft de Lendas ⭐',
                'body'  => 'É a sua vez de escolher!',
                'url'   => '/legends-draft.php',
            ]);
        } catch (Throwable $e) {
            error_log('notificarEscolhaLendas (push vez user_id=' . $proximoUserId . '): ' . $e->getMessage());
        }
    }
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode(['success' => true] + estadoDraft($pdo, $user_id, $is_admin));
    exit;
}

if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'escolher') {
        $nome = trim((string)($body['player_name'] ?? ''));
        $pos  = strtoupper(trim((string)($body['player_position'] ?? '')));
        if ($nome === '') {
            echo json_encode(['success' => false, 'error' => 'Digite o nome do jogador.']);
            exit;
        }
        if (!in_array($pos, ['PG', 'SG', 'SF', 'PF', 'C'], true)) {
            echo json_encode(['success' => false, 'error' => 'Posição inválida.']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->query("SELECT id, user_id, gm_name, player_name FROM legends_draft_picks WHERE player_name IS NULL ORDER BY pick_number ASC LIMIT 1 FOR UPDATE");
            $atual = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$atual) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'O draft já terminou.']);
                exit;
            }
            if (!$is_admin && (int)($atual['user_id'] ?? 0) !== $user_id) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Ainda não é a sua vez.']);
                exit;
            }

            $pdo->prepare("UPDATE legends_draft_picks SET player_name = ?, player_position = ?, ovr = 80, age = 19, picked_at = NOW() WHERE id = ?")
                ->execute([$nome, $pos, $atual['id']]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('legends-draft escolher: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erro ao registrar a escolha.']);
            exit;
        }

        notificarEscolhaLendas($pdo, $atual['gm_name'], $nome);

        echo json_encode(['success' => true] + estadoDraft($pdo, $user_id, $is_admin));
        exit;
    }

    if ($action === 'finalizar') {
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Apenas administradores podem finalizar o draft.']);
            exit;
        }
        $temPendente = (bool)$pdo->query("SELECT 1 FROM legends_draft_picks WHERE player_name IS NULL LIMIT 1")->fetchColumn();
        if ($temPendente) {
            echo json_encode(['success' => false, 'error' => 'Ainda tem escolha pendente — todo mundo precisa escolher antes de finalizar.']);
            exit;
        }
        $pdo->exec("UPDATE legends_draft_status SET finalizado_em = NOW() WHERE id = 1");
        echo json_encode(['success' => true] + estadoDraft($pdo, $user_id, $is_admin));
        exit;
    }

    if ($action === 'salvar_badges') {
        $badgesEnviadas = is_array($body['badges'] ?? null) ? $body['badges'] : [];
        $catalogoKeys = array_column(BADGES_CATALOGO, 'key');

        $stmt = $pdo->prepare("SELECT id, user_id, player_name FROM legends_draft_picks WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $meuPick = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$meuPick) {
            echo json_encode(['success' => false, 'error' => 'Você não tem um jogador neste draft.']);
            exit;
        }
        if ($meuPick['player_name'] === null) {
            echo json_encode(['success' => false, 'error' => 'Você ainda não escolheu seu jogador.']);
            exit;
        }

        $totalTokens = 0;
        $validas = [];
        foreach ($badgesEnviadas as $b) {
            $key = (string)($b['badge_key'] ?? '');
            $tier = (string)($b['tier'] ?? '');
            if (!in_array($key, $catalogoKeys, true)) continue;
            if (!isset(TIER_CUSTO[$tier])) continue;
            $custo = TIER_CUSTO[$tier];
            $totalTokens += $custo;
            $validas[] = [$key, $tier, $custo];
        }

        if ($totalTokens > TOKENS_ORCAMENTO) {
            echo json_encode(['success' => false, 'error' => "Isso custa {$totalTokens} tokens, mas o orçamento é de " . TOKENS_ORCAMENTO . '.']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM legends_draft_badges WHERE pick_id = ?")->execute([$meuPick['id']]);
            $ins = $pdo->prepare("INSERT INTO legends_draft_badges (pick_id, badge_key, tier, tokens_cost) VALUES (?,?,?,?)");
            foreach ($validas as [$key, $tier, $custo]) {
                $ins->execute([$meuPick['id'], $key, $tier, $custo]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('legends-draft salvar_badges: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar as badges.']);
            exit;
        }

        echo json_encode(['success' => true] + estadoDraft($pdo, $user_id, $is_admin));
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não suportado']);
