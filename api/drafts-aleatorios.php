<?php
/**
 * Drafts Aleatórios — a ordem sorteada numa roleta (api/roleta.php) vira a
 * ordem de um draft. É o mesmo fluxo do Draft de Lendas, só que sem badges,
 * sem OVR/idade fixos e sem depender da Roleta dos 32: qualquer roleta que
 * terminou pode virar um draft, e dá pra ter vários rodando ao mesmo tempo.
 *
 * Quem escolhe: qualquer pessoa logada, igual ao legends-draft — na prática um
 * GM às vezes manda a escolha pra outra pessoa cadastrar por ele.
 * Só admin cria, finaliza e exclui.
 */

require_once __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/nba_teams.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}
$user_id  = (int)$_SESSION['user_id'];
$is_admin = hasAdminAccess($pdo, $user_id);
$minhasLigasAdmin = $is_admin ? array_map('strtoupper', getAdminLeagues($pdo, $user_id)) : [];

/**
 * Ligas do usuário logado — do time E do cadastro. Normalmente são a mesma,
 * mas se estiverem dessincronizadas (bug de dados: teams.league != users.league)
 * aceita qualquer uma das duas, pra não travar o acesso de quem já está na
 * liga certa por um lado só.
 */
function ligasDoUsuarioDraft(PDO $pdo, int $userId): array
{
    $ligas = [];
    try {
        $stmt = $pdo->prepare("SELECT league FROM teams WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $l = $stmt->fetchColumn();
        if ($l) $ligas[] = strtoupper((string)$l);
    } catch (Throwable $e) {}
    try {
        $stmt = $pdo->prepare("SELECT league FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $l = $stmt->fetchColumn();
        if ($l) $ligas[] = strtoupper((string)$l);
    } catch (Throwable $e) {}
    return array_values(array_unique($ligas));
}

function ensureDraftsAleatoriosTables(PDO $pdo): void
{
    // roleta_id é UNIQUE: cada roleta vira no máximo um draft, então clicar
    // duas vezes em "criar draft" não gera duplicata.
    $pdo->exec("CREATE TABLE IF NOT EXISTS drafts_aleatorios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        roleta_id INT NULL,
        titulo VARCHAR(180) NOT NULL,
        league VARCHAR(40) NULL,
        criado_por INT NULL,
        finalizado_em TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_da_roleta (roleta_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($pdo->query("SHOW COLUMNS FROM drafts_aleatorios LIKE 'league'")->rowCount() === 0) {
        $pdo->exec("ALTER TABLE drafts_aleatorios ADD COLUMN league VARCHAR(40) NULL AFTER titulo");
    }
    // 'texto_livre' (padrão, nome de jogador digitado) ou 'time_nba' (sorteio de
    // marca da ROOKIE: escolhe de um catálogo fechado, sem repetir).
    if ($pdo->query("SHOW COLUMNS FROM drafts_aleatorios LIKE 'modo'")->rowCount() === 0) {
        $pdo->exec("ALTER TABLE drafts_aleatorios ADD COLUMN modo VARCHAR(20) NOT NULL DEFAULT 'texto_livre' AFTER league");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS draft_aleatorio_picks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        draft_id INT NOT NULL,
        pick_number INT NOT NULL,
        team_id INT NULL,
        user_id INT NULL,
        nome_display VARCHAR(180) NOT NULL,
        player_name VARCHAR(150) NULL,
        player_position VARCHAR(10) NULL,
        skipped TINYINT(1) NOT NULL DEFAULT 0,
        picked_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_dap_pick (draft_id, pick_number),
        CONSTRAINT fk_dap_draft FOREIGN KEY (draft_id) REFERENCES drafts_aleatorios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Time NBA escolhido nesta pick, quando modo='time_nba' — separado de
    // player_name (que continua guardando o nome pra exibição, ex: "Los
    // Angeles Lakers") pra dar pra consultar/validar por id sem parsear texto.
    if ($pdo->query("SHOW COLUMNS FROM draft_aleatorio_picks LIKE 'nba_team_id'")->rowCount() === 0) {
        $pdo->exec("ALTER TABLE draft_aleatorio_picks ADD COLUMN nba_team_id INT NULL AFTER player_position");
    }
}
ensureDraftsAleatoriosTables($pdo);

function draftFinalizado(PDO $pdo, int $draftId): bool
{
    $stmt = $pdo->prepare("SELECT finalizado_em FROM drafts_aleatorios WHERE id = ?");
    $stmt->execute([$draftId]);
    $v = $stmt->fetchColumn();
    return $v !== false && $v !== null;
}

function estadoDraftAleatorio(PDO $pdo, int $draftId, int $sessionUserId, bool $isAdmin): ?array
{
    $stmt = $pdo->prepare("SELECT id, roleta_id, titulo, league, modo, finalizado_em, created_at FROM drafts_aleatorios WHERE id = ?");
    $stmt->execute([$draftId]);
    $d = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$d) return null;

    $stmtP = $pdo->prepare("
        SELECT dap.id, dap.pick_number, dap.team_id, dap.user_id, dap.nome_display, dap.created_at,
               dap.player_name, dap.player_position, dap.nba_team_id, dap.skipped, dap.picked_at,
               t.photo_url
        FROM draft_aleatorio_picks dap
        LEFT JOIN teams t ON t.id = dap.team_id
        WHERE dap.draft_id = ?
        ORDER BY dap.pick_number ASC
    ");
    $stmtP->execute([$draftId]);
    $picks = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    // Tempo que cada escolha levou: picked_at desta pick menos o picked_at da
    // anterior (a vez começa quando a anterior sai). A pick 1 conta a partir
    // da criação do draft. Preenchimentos fora de ordem podem dar diferença
    // negativa — aí não mostra nada em vez de mostrar um número mentiroso.
    $anterior = null;
    foreach ($picks as $i => $p) {
        $picks[$i]['tempo_seg'] = null;
        if ($p['picked_at'] !== null) {
            $base = $anterior ?? $p['created_at'];
            if ($base !== null) {
                $delta = strtotime($p['picked_at']) - strtotime($base);
                if ($delta >= 0) $picks[$i]['tempo_seg'] = $delta;
            }
            $anterior = $p['picked_at'];
        }
    }

    $vezPickNumber = null;
    $feitas = 0;
    foreach ($picks as $p) {
        if ($p['player_name'] === null && !$p['skipped'] && $vezPickNumber === null) {
            $vezPickNumber = (int)$p['pick_number'];
        }
        if ($p['player_name'] !== null) $feitas++;
    }

    return [
        'id'              => (int)$d['id'],
        'roleta_id'       => $d['roleta_id'] !== null ? (int)$d['roleta_id'] : null,
        'titulo'          => $d['titulo'],
        'league'          => $d['league'],
        'modo'            => $d['modo'] ?? 'texto_livre',
        'picks'           => $picks,
        'total'           => count($picks),
        'feitas'          => $feitas,
        'vez_pick_number' => $vezPickNumber,
        'draft_completo'  => count($picks) > 0 && $vezPickNumber === null,
        'finalizado'      => $d['finalizado_em'] !== null,
        'is_admin'        => $isAdmin,
        'session_user_id' => $sessionUserId,
    ];
}

/** Descobre a liga do draft: pelos times sorteados na roleta ou, na falta deles, pela liga de quem criou. */
function detectarLigaDraftAleatorio(PDO $pdo, int $roletaId, int $criadoPor): ?string
{
    // A liga escolhida na roleta manda: é o que o admin definiu de propósito.
    try {
        $stmt = $pdo->prepare("SELECT league FROM roletas WHERE id = ? LIMIT 1");
        $stmt->execute([$roletaId]);
        $league = $stmt->fetchColumn();
        if ($league) return strtoupper((string)$league);
    } catch (Throwable $e) {}

    try {
        $stmt = $pdo->prepare("SELECT t.league FROM roleta_participantes rp
                               JOIN teams t ON t.id = rp.team_id
                               WHERE rp.roleta_id = ? AND t.league IS NOT NULL AND t.league <> ''
                               LIMIT 1");
        $stmt->execute([$roletaId]);
        $league = $stmt->fetchColumn();
        if ($league) return (string)$league;
    } catch (Throwable $e) {}

    try {
        $stmt = $pdo->prepare("SELECT league FROM users WHERE id = ?");
        $stmt->execute([$criadoPor]);
        $league = $stmt->fetchColumn();
        if ($league) return (string)$league;
    } catch (Throwable $e) {}

    return null;
}

/**
 * Avisa a liga do draft a cada escolha. Só manda pra gente da mesma liga do
 * draft (nunca vaza pra outras ligas). Drafts antigos sem liga detectada
 * caem no comportamento anterior: só avisa quem já está nas picks.
 * Best-effort: nunca derruba a escolha.
 */
function notificarEscolhaDraftAleatorio(PDO $pdo, int $draftId, string $titulo, string $nome, ?string $playerName, ?string $league = null): void
{
    $pushFile = dirname(__DIR__) . '/backend/push.php';
    if (!file_exists($pushFile)) return;
    require_once $pushFile;

    try {
        if ($league) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE league = ?");
            $stmt->execute([$league]);
        } else {
            $stmt = $pdo->prepare("SELECT DISTINCT user_id FROM draft_aleatorio_picks WHERE draft_id = ? AND user_id IS NOT NULL");
            $stmt->execute([$draftId]);
        }
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log('notificarEscolhaDraftAleatorio (participantes): ' . $e->getMessage());
        return;
    }

    $url = '/draft-aleatorio.php?id=' . $draftId;
    $payload = [
        'title' => $titulo,
        'body'  => $playerName !== null ? "{$nome} escolheu {$playerName}!" : "A escolha de {$nome} foi pulada.",
        'url'   => $url,
    ];
    foreach ($userIds as $uid) {
        try {
            sendPushToUser($pdo, (int)$uid, $payload, 'eventos');
        } catch (Throwable $e) {
            error_log('notificarEscolhaDraftAleatorio (push user_id=' . $uid . '): ' . $e->getMessage());
        }
    }

    try {
        $stmtProx = $pdo->prepare("SELECT user_id FROM draft_aleatorio_picks
                                   WHERE draft_id = ? AND player_name IS NULL AND skipped = 0
                                   ORDER BY pick_number ASC LIMIT 1");
        $stmtProx->execute([$draftId]);
        $proximo = $stmtProx->fetchColumn();
    } catch (Throwable $e) {
        $proximo = false;
    }
    if ($proximo) {
        try {
            sendPushToUser($pdo, (int)$proximo, ['title' => $titulo, 'body' => 'É a sua vez de escolher!', 'url' => $url], 'eventos');
        } catch (Throwable $e) {
            error_log('notificarEscolhaDraftAleatorio (push vez): ' . $e->getMessage());
        }
    }
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'estado';

    if ($action === 'listar') {
        // Cada um vê os drafts da sua liga: as que administra MAIS a(s) do
        // próprio time/cadastro (um admin de outra liga que também é GM não
        // pode ficar sem ver os drafts da liga onde ele mesmo joga).
        // Drafts antigos sem liga continuam aparecendo pra todo mundo.
        $ligasVisiveis = array_values(array_unique(array_merge($minhasLigasAdmin, ligasDoUsuarioDraft($pdo, $user_id))));
        $where = "WHERE d.league IS NULL";
        $params = [];
        if ($ligasVisiveis) {
            $ph = implode(',', array_fill(0, count($ligasVisiveis), '?'));
            $where = "WHERE (d.league IS NULL OR UPPER(d.league) IN ($ph))";
            $params = $ligasVisiveis;
        }

        $stmt = $pdo->prepare("
            SELECT d.id, d.roleta_id, d.titulo, d.league, d.modo, d.finalizado_em, d.created_at,
                   COUNT(dap.id) AS total,
                   SUM(dap.player_name IS NOT NULL) AS feitas,
                   SUM(dap.skipped = 1 AND dap.player_name IS NULL) AS puladas
            FROM drafts_aleatorios d
            LEFT JOIN draft_aleatorio_picks dap ON dap.draft_id = d.id
            $where
            GROUP BY d.id
            ORDER BY d.created_at DESC
        ");
        $stmt->execute($params);
        $drafts = array_map(function ($r) {
            $total  = (int)$r['total'];
            $feitas = (int)$r['feitas'];
            $pulad  = (int)$r['puladas'];
            return [
                'id'         => (int)$r['id'],
                'roleta_id'  => $r['roleta_id'] !== null ? (int)$r['roleta_id'] : null,
                'titulo'     => $r['titulo'],
                'league'     => $r['league'] ? strtoupper((string)$r['league']) : null,
                'modo'       => $r['modo'] ?? 'texto_livre',
                'total'      => $total,
                'feitas'     => $feitas,
                'puladas'    => $pulad,
                'completo'   => $total > 0 && ($feitas + $pulad) === $total,
                'finalizado' => $r['finalizado_em'] !== null,
                'created_at' => $r['created_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode(['success' => true, 'drafts' => $drafts, 'is_admin' => $is_admin]);
        exit;
    }

    // Roletas concluídas que ainda não viraram draft — alimenta o "criar draft"
    // da central. Uma roleta está concluída quando ninguém ficou sem pick.
    if ($action === 'roletas_disponiveis') {
        try {
            // Só roletas das ligas que o admin administra (as antigas, sem
            // liga, continuam disponíveis pra não ficarem órfãs).
            $filtro = '';
            $params = [];
            if ($minhasLigasAdmin) {
                $ph = implode(',', array_fill(0, count($minhasLigasAdmin), '?'));
                $filtro = " AND (r.league IS NULL OR UPPER(r.league) IN ($ph))";
                $params = $minhasLigasAdmin;
            }
            $stmt = $pdo->prepare("
                SELECT r.id, r.titulo, r.tipo, r.league, COUNT(rp.id) AS total,
                       SUM(rp.pick_number IS NULL) AS na_urna
                FROM roletas r
                JOIN roleta_participantes rp ON rp.roleta_id = r.id
                WHERE r.id NOT IN (SELECT roleta_id FROM drafts_aleatorios WHERE roleta_id IS NOT NULL)
                $filtro
                GROUP BY r.id
                HAVING total > 0 AND na_urna = 0
                ORDER BY r.created_at DESC
            ");
            $stmt->execute($params);
            $roletas = array_map(fn($r) => [
                'id'     => (int)$r['id'],
                'titulo' => $r['titulo'],
                'tipo'   => $r['tipo'],
                'league' => $r['league'] ? strtoupper((string)$r['league']) : null,
                'total'  => (int)$r['total'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            $roletas = []; // roletas ainda não existe neste banco
        }
        echo json_encode(['success' => true, 'roletas' => $roletas]);
        exit;
    }

    // Usado por rookie-sorteio.php: acha sozinho o draft de marca (modo
    // time_nba) onde o usuário logado tem uma pick pendente, sem precisar
    // saber o id de antemão. Sem draft ativo, devolve quem mais tá esperando
    // o sorteio começar — é a lista que a tela de espera mostra.
    if ($action === 'meu_draft_marca') {
        $stmt = $pdo->prepare("
            SELECT da.id FROM draft_aleatorio_picks dap
            JOIN drafts_aleatorios da ON da.id = dap.draft_id
            WHERE da.modo = 'time_nba' AND UPPER(da.league) = 'ROOKIE' AND da.finalizado_em IS NULL
              AND dap.user_id = ?
            ORDER BY da.id DESC LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $meuDraftId = (int)($stmt->fetchColumn() ?: 0);

        if (!$meuDraftId) {
            $stmtEsperando = $pdo->prepare("
                SELECT id, name, photo_url FROM users
                WHERE league = 'ROOKIE' AND user_type != 'admin' AND id NOT IN (SELECT user_id FROM teams)
                ORDER BY id ASC
            ");
            $stmtEsperando->execute();
            echo json_encode(['success' => true, 'draft_id' => null, 'esperando' => $stmtEsperando->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        echo json_encode(['success' => true, 'draft_id' => $meuDraftId] + estadoDraftAleatorio($pdo, $meuDraftId, $user_id, $is_admin));
        exit;
    }

    if ($action === 'estado') {
        $id = (int)($_GET['id'] ?? 0);
        $estado = $id ? estadoDraftAleatorio($pdo, $id, $user_id, $is_admin) : null;
        if (!$estado) {
            echo json_encode(['success' => false, 'error' => 'Draft não encontrado']);
            exit;
        }
        // Mesmo escopo do listar: draft de outra liga não abre. Drafts antigos
        // sem liga continuam livres pra não sumirem de quem já os usava.
        $ligaDraft = !empty($estado['league']) ? strtoupper((string)$estado['league']) : null;
        if ($ligaDraft !== null) {
            $permitidas = array_values(array_unique(array_merge($minhasLigasAdmin, ligasDoUsuarioDraft($pdo, $user_id))));
            if (!in_array($ligaDraft, $permitidas, true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Este draft é da liga ' . $ligaDraft . '.']);
                exit;
            }
        }
        echo json_encode(['success' => true, 'minhas_ligas' => $minhasLigasAdmin] + $estado);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
    exit;
}

// ── POST ─────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'criar_de_roleta') {
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Apenas administradores podem criar drafts.']);
            exit;
        }
        $roletaId = (int)($body['roleta_id'] ?? 0);
        if (!$roletaId) {
            echo json_encode(['success' => false, 'error' => 'Roleta obrigatória.']);
            exit;
        }

        $stmtR = $pdo->prepare("SELECT id, titulo FROM roletas WHERE id = ?");
        $stmtR->execute([$roletaId]);
        $roleta = $stmtR->fetch(PDO::FETCH_ASSOC);
        if (!$roleta) {
            echo json_encode(['success' => false, 'error' => 'Roleta não encontrada.']);
            exit;
        }

        $stmtF = $pdo->prepare("SELECT COUNT(*) FROM roleta_participantes WHERE roleta_id = ? AND pick_number IS NULL");
        $stmtF->execute([$roletaId]);
        if ((int)$stmtF->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'Essa roleta ainda não terminou de sortear.']);
            exit;
        }

        $stmtJa = $pdo->prepare("SELECT id FROM drafts_aleatorios WHERE roleta_id = ?");
        $stmtJa->execute([$roletaId]);
        $jaExiste = (int)($stmtJa->fetchColumn() ?: 0);
        if ($jaExiste) {
            echo json_encode(['success' => true, 'id' => $jaExiste, 'ja_existia' => true]);
            exit;
        }

        $titulo = trim((string)($body['titulo'] ?? '')) ?: (string)$roleta['titulo'];
        $league = detectarLigaDraftAleatorio($pdo, $roletaId, $user_id);
        $modo = ($body['modo'] ?? '') === 'time_nba' ? 'time_nba' : 'texto_livre';

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO drafts_aleatorios (roleta_id, titulo, league, modo, criado_por) VALUES (?,?,?,?,?)")
                ->execute([$roletaId, mb_substr($titulo, 0, 180), $league, $modo, $user_id]);
            $draftId = (int)$pdo->lastInsertId();

            // pick_number da roleta já é a ordem do draft: quem sobrou por
            // último na urna recebe 1 e escolhe primeiro.
            $stmtP = $pdo->prepare("SELECT pick_number, team_id, user_id, nome_display
                                    FROM roleta_participantes
                                    WHERE roleta_id = ? AND pick_number IS NOT NULL
                                    ORDER BY pick_number ASC");
            $stmtP->execute([$roletaId]);
            $ins = $pdo->prepare("INSERT INTO draft_aleatorio_picks (draft_id, pick_number, team_id, user_id, nome_display) VALUES (?,?,?,?,?)");
            $n = 0;
            foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $n++;
                $ins->execute([
                    $draftId,
                    (int)$p['pick_number'],
                    $p['team_id'] !== null ? (int)$p['team_id'] : null,
                    $p['user_id'] !== null ? (int)$p['user_id'] : null,
                    $p['nome_display'],
                ]);
            }
            if ($n < 2) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'A roleta não tem participantes sorteados suficientes.']);
                exit;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('drafts-aleatorios criar_de_roleta: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erro ao criar o draft.']);
            exit;
        }

        echo json_encode(['success' => true, 'id' => $draftId]);
        exit;
    }

    $draftId = (int)($body['id'] ?? 0);
    if (!$draftId) {
        echo json_encode(['success' => false, 'error' => 'Draft obrigatório.']);
        exit;
    }

    if ($action === 'escolher') {
        $stmtModo = $pdo->prepare("SELECT modo FROM drafts_aleatorios WHERE id = ?");
        $stmtModo->execute([$draftId]);
        $modo = $stmtModo->fetchColumn() ?: 'texto_livre';

        if (draftFinalizado($pdo, $draftId)) {
            echo json_encode(['success' => false, 'error' => 'Este draft já foi finalizado.']);
            exit;
        }

        if ($modo === 'time_nba') {
            // Sorteio de marca da ROOKIE: escolhe de um catálogo fechado (30
            // times reais), não digita nome — e cada time só pode sair uma vez.
            $nbaTeamId = (int)($body['nba_team_id'] ?? 0);
            $nbaTeam = $nbaTeamId ? nbaTeamById($nbaTeamId) : null;
            if (!$nbaTeam) {
                echo json_encode(['success' => false, 'error' => 'Escolha um time da NBA válido.']);
                exit;
            }
            ensureNbaTeamColumn($pdo);
            $stmtTaken = $pdo->prepare('SELECT id FROM teams WHERE nba_team_id = ? LIMIT 1');
            $stmtTaken->execute([$nbaTeamId]);
            if ($stmtTaken->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Esse time da NBA já foi escolhido. Escolha outro.']);
                exit;
            }
            $nome = $nbaTeam['city'] . ' ' . $nbaTeam['name'];
        } else {
            $nome = trim((string)($body['player_name'] ?? ''));
            // Posição saiu do formulário — só o nome é digitado. Continua aceita se
            // vier (drafts antigos gravaram), mas nunca mais é exigida.
            if ($nome === '') {
                echo json_encode(['success' => false, 'error' => 'Digite o nome do jogador.']);
                exit;
            }
        }
        $pos = null;
        if ($modo !== 'time_nba') {
            $pos = strtoupper(trim((string)($body['player_position'] ?? '')));
            if (!in_array($pos, ['PG', 'SG', 'SF', 'PF', 'C'], true)) $pos = null;
        }
        $pickAlvo = isset($body['pick_number']) ? (int)$body['pick_number'] : null;

        $pdo->beginTransaction();
        try {
            if ($pickAlvo !== null && $pickAlvo > 0) {
                // Preenchendo uma escolha específica (tipicamente uma pulada) —
                // não precisa ser a da vez atual.
                $stmt = $pdo->prepare("SELECT id, nome_display, user_id FROM draft_aleatorio_picks
                                       WHERE draft_id = ? AND pick_number = ? AND player_name IS NULL FOR UPDATE");
                $stmt->execute([$draftId, $pickAlvo]);
            } else {
                $stmt = $pdo->prepare("SELECT id, nome_display, user_id FROM draft_aleatorio_picks
                                       WHERE draft_id = ? AND player_name IS NULL AND skipped = 0
                                       ORDER BY pick_number ASC LIMIT 1 FOR UPDATE");
                $stmt->execute([$draftId]);
            }
            $atual = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$atual) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => $pickAlvo !== null ? 'Essa escolha já foi preenchida.' : 'O draft já terminou.']);
                exit;
            }

            $pdo->prepare("UPDATE draft_aleatorio_picks
                           SET player_name = ?, player_position = ?, nba_team_id = ?, skipped = 0, picked_at = NOW()
                           WHERE id = ?")
                ->execute([$nome, $pos, $modo === 'time_nba' ? $nbaTeamId : null, $atual['id']]);

            // Modo time_nba: a pick É a marca da franquia — cria o time de
            // verdade pro dono desta pick agora, igual o cadastro fazia antes
            // de a escolha de marca virar um passo separado.
            if ($modo === 'time_nba' && $atual['user_id']) {
                $pdo->prepare('INSERT INTO teams (user_id, league, conference, name, city, mascot, photo_url, nba_team_id) VALUES (?, "ROOKIE", ?, ?, ?, ?, ?, ?)')
                    ->execute([
                        (int)$atual['user_id'], $nbaTeam['conference'], $nbaTeam['name'], $nbaTeam['city'], '',
                        nbaTeamLogoUrl($nbaTeam['id']), $nbaTeam['id'],
                    ]);
            }

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ((int)$e->getCode() === 23000 || str_contains($e->getMessage(), 'uniq_teams_nba_team_id')) {
                echo json_encode(['success' => false, 'error' => 'Esse time da NBA acabou de ser escolhido por outro GM. Escolha outro.']);
                exit;
            }
            error_log('drafts-aleatorios escolher: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erro ao registrar a escolha.']);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('drafts-aleatorios escolher: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erro ao registrar a escolha.']);
            exit;
        }

        $estado = estadoDraftAleatorio($pdo, $draftId, $user_id, $is_admin);
        notificarEscolhaDraftAleatorio($pdo, $draftId, $estado['titulo'] ?? 'Draft', $atual['nome_display'], $nome, $estado['league'] ?? null);
        echo json_encode(['success' => true] + $estado);
        exit;
    }

    if ($action === 'pular') {
        if (draftFinalizado($pdo, $draftId)) {
            echo json_encode(['success' => false, 'error' => 'Este draft já foi finalizado.']);
            exit;
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT id, nome_display FROM draft_aleatorio_picks
                                   WHERE draft_id = ? AND player_name IS NULL AND skipped = 0
                                   ORDER BY pick_number ASC LIMIT 1 FOR UPDATE");
            $stmt->execute([$draftId]);
            $atual = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$atual) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'O draft já terminou.']);
                exit;
            }
            $pdo->prepare("UPDATE draft_aleatorio_picks SET skipped = 1, picked_at = NOW() WHERE id = ?")
                ->execute([$atual['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('drafts-aleatorios pular: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erro ao pular a escolha.']);
            exit;
        }

        $estado = estadoDraftAleatorio($pdo, $draftId, $user_id, $is_admin);
        notificarEscolhaDraftAleatorio($pdo, $draftId, $estado['titulo'] ?? 'Draft', $atual['nome_display'], null, $estado['league'] ?? null);
        echo json_encode(['success' => true] + $estado);
        exit;
    }

    if ($action === 'desfazer') {
        // Desfaz uma escolha já feita: a pick volta pro estado "pulada", de onde
        // dá pra preencher de novo ou devolver pra fila.
        $pickAlvo = (int)($body['pick_number'] ?? 0);
        if ($pickAlvo <= 0) {
            echo json_encode(['success' => false, 'error' => 'Pick inválida.']);
            exit;
        }
        if (draftFinalizado($pdo, $draftId)) {
            echo json_encode(['success' => false, 'error' => 'O draft já foi finalizado — não dá mais pra desfazer escolhas.']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT id, player_name, user_id, nba_team_id FROM draft_aleatorio_picks WHERE draft_id = ? AND pick_number = ? FOR UPDATE");
            $stmt->execute([$draftId, $pickAlvo]);
            $atual = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$atual || $atual['player_name'] === null) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Essa escolha ainda não foi feita.']);
                exit;
            }
            // Modo time_nba: a pick tinha criado o time de verdade do GM — desfazer
            // a escolha também apaga esse time, senão ele fica "dono" de uma marca
            // que a tela diz que ainda não foi escolhida.
            if ($atual['nba_team_id'] && $atual['user_id']) {
                $pdo->prepare('DELETE FROM teams WHERE user_id = ? AND nba_team_id = ?')
                    ->execute([(int)$atual['user_id'], (int)$atual['nba_team_id']]);
            }
            $pdo->prepare("UPDATE draft_aleatorio_picks
                           SET player_name = NULL, player_position = NULL, nba_team_id = NULL, skipped = 1, picked_at = NOW()
                           WHERE id = ?")
                ->execute([$atual['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('drafts-aleatorios desfazer: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erro ao desfazer a escolha.']);
            exit;
        }

        echo json_encode(['success' => true] + estadoDraftAleatorio($pdo, $draftId, $user_id, $is_admin));
        exit;
    }

    if ($action === 'despular') {
        $pickAlvo = (int)($body['pick_number'] ?? 0);
        if ($pickAlvo <= 0) {
            echo json_encode(['success' => false, 'error' => 'Pick inválida.']);
            exit;
        }
        if (draftFinalizado($pdo, $draftId)) {
            echo json_encode(['success' => false, 'error' => 'O draft já foi finalizado.']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT id, skipped, player_name FROM draft_aleatorio_picks WHERE draft_id = ? AND pick_number = ? FOR UPDATE");
            $stmt->execute([$draftId, $pickAlvo]);
            $atual = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$atual || !$atual['skipped'] || $atual['player_name'] !== null) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Essa pick não está pulada.']);
                exit;
            }
            $pdo->prepare("UPDATE draft_aleatorio_picks SET skipped = 0 WHERE id = ?")->execute([$atual['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('drafts-aleatorios despular: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erro ao tirar do pulado.']);
            exit;
        }

        echo json_encode(['success' => true] + estadoDraftAleatorio($pdo, $draftId, $user_id, $is_admin));
        exit;
    }

    // Liga do draft — define quem enxerga ele no painel e na central.
    if ($action === 'definir_liga') {
        $nova = strtoupper(trim((string)($body['league'] ?? '')));
        if (!$minhasLigasAdmin || ($nova !== '' && !in_array($nova, $minhasLigasAdmin, true))) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Você não administra essa liga.']);
            exit;
        }
        // Também precisa administrar a liga em que o draft está hoje.
        $stAtual = $pdo->prepare("SELECT league FROM drafts_aleatorios WHERE id = ? LIMIT 1");
        $stAtual->execute([$draftId]);
        $atual = $stAtual->fetchColumn();
        if ($atual && !in_array(strtoupper((string)$atual), $minhasLigasAdmin, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Só o admin da ' . strtoupper((string)$atual) . ' pode mover este draft.']);
            exit;
        }
        if ($nova === '') {
            echo json_encode(['success' => false, 'error' => 'Escolha a liga.']);
            exit;
        }
        $pdo->prepare("UPDATE drafts_aleatorios SET league = ? WHERE id = ?")->execute([$nova, $draftId]);
        echo json_encode(['success' => true, 'minhas_ligas' => $minhasLigasAdmin]
            + estadoDraftAleatorio($pdo, $draftId, $user_id, $is_admin));
        exit;
    }

    if ($action === 'renomear') {
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Apenas administradores podem renomear.']);
            exit;
        }
        $titulo = trim((string)($body['titulo'] ?? ''));
        if ($titulo === '') {
            echo json_encode(['success' => false, 'error' => 'Título obrigatório.']);
            exit;
        }
        $pdo->prepare("UPDATE drafts_aleatorios SET titulo = ? WHERE id = ?")
            ->execute([mb_substr($titulo, 0, 180), $draftId]);
        echo json_encode(['success' => true] + estadoDraftAleatorio($pdo, $draftId, $user_id, $is_admin));
        exit;
    }

    if ($action === 'renomear_gm') {
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Apenas administradores podem editar o nome do GM.']);
            exit;
        }
        $pickNumber = (int)($body['pick_number'] ?? 0);
        $nome = trim((string)($body['nome'] ?? ''));
        if (!$pickNumber) {
            echo json_encode(['success' => false, 'error' => 'Escolha inválida.']);
            exit;
        }
        if ($nome === '') {
            echo json_encode(['success' => false, 'error' => 'Nome obrigatório.']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE draft_aleatorio_picks SET nome_display = ? WHERE draft_id = ? AND pick_number = ?");
        $stmt->execute([mb_substr($nome, 0, 180), $draftId, $pickNumber]);
        if ($stmt->rowCount() === 0) {
            echo json_encode(['success' => false, 'error' => 'Escolha não encontrada.']);
            exit;
        }
        echo json_encode(['success' => true] + estadoDraftAleatorio($pdo, $draftId, $user_id, $is_admin));
        exit;
    }

    if ($action === 'finalizar') {
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Apenas administradores podem finalizar o draft.']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT 1 FROM draft_aleatorio_picks WHERE draft_id = ? AND player_name IS NULL AND skipped = 0 LIMIT 1");
        $stmt->execute([$draftId]);
        if ($stmt->fetchColumn()) {
            echo json_encode(['success' => false, 'error' => 'Ainda tem escolha pendente — todo mundo precisa escolher (ou ser pulado) antes de finalizar.']);
            exit;
        }
        $pdo->prepare("UPDATE drafts_aleatorios SET finalizado_em = NOW() WHERE id = ?")->execute([$draftId]);
        echo json_encode(['success' => true] + estadoDraftAleatorio($pdo, $draftId, $user_id, $is_admin));
        exit;
    }

    if ($action === 'reabrir') {
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Apenas administradores podem reabrir o draft.']);
            exit;
        }
        $pdo->prepare("UPDATE drafts_aleatorios SET finalizado_em = NULL WHERE id = ?")->execute([$draftId]);
        echo json_encode(['success' => true] + estadoDraftAleatorio($pdo, $draftId, $user_id, $is_admin));
        exit;
    }

    /**
     * Duplica o draft levando SÓ a ordem e os participantes — as escolhas já
     * feitas (e as puladas) não vêm junto. Serve pra refazer o mesmo draft sem
     * recadastrar a ordem inteira.
     *
     * roleta_id fica NULL na cópia de propósito: a coluna é UNIQUE (uma roleta
     * gera no máximo um draft), então herdar o vínculo faria o INSERT colidir.
     */
    if ($action === 'duplicar') {
        if (!$minhasLigasAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Apenas administradores podem duplicar o draft.']);
            exit;
        }
        $stmtOrig = $pdo->prepare("SELECT titulo, league FROM drafts_aleatorios WHERE id = ?");
        $stmtOrig->execute([$draftId]);
        $orig = $stmtOrig->fetch(PDO::FETCH_ASSOC);
        if (!$orig) {
            echo json_encode(['success' => false, 'error' => 'Draft não encontrado.']);
            exit;
        }
        $ligaOrigem = $orig['league'] ? strtoupper((string)$orig['league']) : null;
        if ($ligaOrigem !== null && !in_array($ligaOrigem, $minhasLigasAdmin, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Só o admin da ' . $ligaOrigem . ' pode duplicar este draft.']);
            exit;
        }

        $titulo = trim((string)($body['titulo'] ?? ''));
        if ($titulo === '') $titulo = $orig['titulo'] . ' (cópia)';

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO drafts_aleatorios (roleta_id, titulo, league, criado_por) VALUES (NULL, ?, ?, ?)")
                ->execute([mb_substr($titulo, 0, 180), $orig['league'], $user_id]);
            $novoId = (int)$pdo->lastInsertId();

            // player_name/player_position/skipped/picked_at ficam de fora: são as escolhas.
            $pdo->prepare("INSERT INTO draft_aleatorio_picks (draft_id, pick_number, team_id, user_id, nome_display)
                           SELECT ?, pick_number, team_id, user_id, nome_display
                           FROM draft_aleatorio_picks WHERE draft_id = ? ORDER BY pick_number ASC")
                ->execute([$novoId, $draftId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('draft duplicar #' . $draftId . ': ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erro ao duplicar o draft.']);
            exit;
        }

        echo json_encode(['success' => true, 'id' => $novoId]);
        exit;
    }

    if ($action === 'excluir') {
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Apenas administradores podem excluir o draft.']);
            exit;
        }
        // As picks caem junto pela FK ON DELETE CASCADE. A roleta de origem
        // continua intacta — dá pra gerar o draft de novo depois.
        $pdo->prepare("DELETE FROM drafts_aleatorios WHERE id = ?")->execute([$draftId]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não suportado']);
