<?php
/**
 * API do Hub de Roletas — admin cria quantas roletas quiser (GMs, Times ou
 * lista personalizada), cada uma com seu próprio sorteio de ordem inversa:
 * quem sai primeiro fica com a pior posição, até sobrar só 1.
 *
 * Uma roleta é editável (título, participantes, notificação) só até o
 * primeiro giro; depois disso trava — só dá pra continuar girando.
 *
 * Tudo aqui é admin (global). A "Roleta dos 32 Times" (roleta-times.php) é
 * uma página separada e pré-existente — o hub só aponta um card fixo pra
 * ela, não migra os dados dela pra este schema.
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
$user_id  = (int)$_SESSION['user_id'];
$is_admin = hasAdminAccess($pdo, $user_id);

// Ver uma roleta é livre pra quem está na liga dela — é assim que o card do
// dashboard leva o GM até o sorteio. Criar, editar, girar, reiniciar e excluir
// continuam restritos, e agora ao admin DAQUELA liga (não a qualquer admin).
$minhasLigasAdmin = $is_admin ? array_map('strtoupper', getAdminLeagues($pdo, $user_id)) : [];

function ensureRoletasTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS roletas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titulo VARCHAR(160) NOT NULL,
        tipo ENUM('gms','times','personalizado') NOT NULL DEFAULT 'gms',
        league VARCHAR(20) NULL,
        notificar_saida TINYINT(1) NOT NULL DEFAULT 1,
        criado_por INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Bancos que já tinham a tabela antes da liga existir.
    if ($pdo->query("SHOW COLUMNS FROM roletas LIKE 'league'")->rowCount() === 0) {
        $pdo->exec("ALTER TABLE roletas ADD COLUMN league VARCHAR(20) NULL AFTER tipo");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS roleta_participantes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        roleta_id INT NOT NULL,
        ordem INT NOT NULL,
        team_id INT NULL,
        user_id INT NULL,
        nome_display VARCHAR(180) NOT NULL,
        pick_number INT NULL,
        eliminated_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_roleta_ordem (roleta_id, ordem),
        INDEX idx_roleta_pick (roleta_id, pick_number),
        CONSTRAINT fk_rp_roleta FOREIGN KEY (roleta_id) REFERENCES roletas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
ensureRoletasTables($pdo);

/** Liga da roleta (null = roleta antiga, sem liga definida). */
function ligaDaRoleta(PDO $pdo, int $roletaId): ?string
{
    $stmt = $pdo->prepare("SELECT league FROM roletas WHERE id = ? LIMIT 1");
    $stmt->execute([$roletaId]);
    $l = $stmt->fetchColumn();
    return $l ? strtoupper((string)$l) : null;
}

/**
 * Portaria das ações de escrita: girar, editar, reiniciar, excluir.
 *
 * Só o admin da liga da roleta passa. Roleta sem liga (as antigas) aceita
 * qualquer admin — senão elas ficariam inacessíveis pra sempre.
 */
function exigirAdminDaRoleta(PDO $pdo, int $userId, array $minhasLigas, ?string $liga): void
{
    if (!$minhasLigas) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
        exit;
    }
    if ($liga !== null && !in_array($liga, $minhasLigas, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Só o admin da ' . $liga . ' pode mexer nesta roleta.']);
        exit;
    }
}

/** Liga do GM logado — usada pra decidir o que ele enxerga. */
function ligaDoGm(PDO $pdo, int $userId): ?string
{
    try {
        $stmt = $pdo->prepare("SELECT league FROM teams WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $l = $stmt->fetchColumn();
        if ($l) return strtoupper((string)$l);
        $stmt = $pdo->prepare("SELECT league FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $l = $stmt->fetchColumn();
        return $l ? strtoupper((string)$l) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Uma roleta trava (título/participantes/notificação) assim que o 1º giro acontece. */
function roletaBloqueada(PDO $pdo, int $roletaId): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM roleta_participantes WHERE roleta_id = ? AND pick_number IS NOT NULL LIMIT 1");
    $stmt->execute([$roletaId]);
    return (bool)$stmt->fetchColumn();
}

function estadoRoleta(PDO $pdo, int $roletaId): ?array
{
    $stmt = $pdo->prepare("SELECT id, titulo, tipo, notificar_saida FROM roletas WHERE id = ?");
    $stmt->execute([$roletaId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;

    $stmtP = $pdo->prepare("
        SELECT rp.id, rp.ordem, rp.team_id, rp.user_id, rp.nome_display, rp.pick_number, rp.eliminated_at,
               t.photo_url
        FROM roleta_participantes rp
        LEFT JOIN teams t ON t.id = rp.team_id
        WHERE rp.roleta_id = ?
        ORDER BY rp.ordem ASC
    ");
    $stmtP->execute([$roletaId]);
    $linhas = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    $naUrna = [];
    $sorteados = [];
    foreach ($linhas as $l) {
        $l['pick_number'] = $l['pick_number'] !== null ? (int)$l['pick_number'] : null;
        if ($l['pick_number'] === null) {
            $naUrna[] = $l;
        } else {
            $sorteados[] = $l;
        }
    }
    // Histórico com o mais recente primeiro (menor pick_number = saiu por último).
    usort($sorteados, fn($a, $b) => $a['pick_number'] <=> $b['pick_number']);

    return [
        'id' => (int)$r['id'],
        'titulo' => $r['titulo'],
        'tipo' => $r['tipo'],
        'notificar_saida' => (int)$r['notificar_saida'] === 1,
        'na_urna' => $naUrna,
        'sorteados' => $sorteados,
        'total' => count($linhas),
        'concluido' => count($linhas) > 0 && count($naUrna) === 0,
        'bloqueada' => count($sorteados) > 0,
    ];
}

/** Avisa todo mundo que já passou por essa roleta (best-effort, nunca derruba o giro). */
function notificarSaidaRoletaGenerica(PDO $pdo, int $roletaId, string $titulo, string $nomeEliminado, int $pick): void
{
    $pushFile = dirname(__DIR__) . '/backend/push.php';
    if (!file_exists($pushFile)) return;

    try {
        $stmt = $pdo->prepare("SELECT DISTINCT user_id FROM roleta_participantes WHERE roleta_id = ? AND user_id IS NOT NULL");
        $stmt->execute([$roletaId]);
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log('notificarSaidaRoletaGenerica (buscar): ' . $e->getMessage());
        return;
    }
    if (!$userIds) return;

    require_once $pushFile;
    $payload = [
        'title' => "🎲 {$titulo}",
        'body'  => "{$nomeEliminado} saiu — escolha {$pick} definida!",
        'url'   => '/roleta-editar.php?id=' . $roletaId,
    ];
    foreach ($userIds as $uid) {
        try {
            sendPushToUser($pdo, (int)$uid, $payload, 'eventos');
        } catch (Throwable $e) {
            error_log('notificarSaidaRoletaGenerica (push user_id=' . $uid . '): ' . $e->getMessage());
        }
    }
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'listar';

    if ($action === 'listar') {
        // Admin vê as roletas das ligas que administra (mais as antigas sem
        // liga); GM comum vê só as da própria liga.
        $ligasVisiveis = $minhasLigasAdmin ?: array_filter([ligaDoGm($pdo, $user_id)]);
        $where = '';
        $params = [];
        if ($ligasVisiveis) {
            $ph = implode(',', array_fill(0, count($ligasVisiveis), '?'));
            $where = "WHERE (r.league IS NULL OR r.league IN ($ph))";
            $params = $ligasVisiveis;
        } else {
            $where = "WHERE r.league IS NULL";
        }

        $stmt = $pdo->prepare("
            SELECT r.id, r.titulo, r.tipo, r.league, r.notificar_saida, r.created_at,
                   COUNT(rp.id) AS total,
                   SUM(rp.pick_number IS NOT NULL) AS sorteados
            FROM roletas r
            LEFT JOIN roleta_participantes rp ON rp.roleta_id = r.id
            $where
            GROUP BY r.id
            ORDER BY r.created_at DESC
        ");
        $stmt->execute($params);
        $roletas = array_map(function ($r) use ($minhasLigasAdmin) {
            $total = (int)$r['total'];
            $sorteados = (int)$r['sorteados'];
            $liga = $r['league'] ? strtoupper((string)$r['league']) : null;
            return [
                'id' => (int)$r['id'],
                'titulo' => $r['titulo'],
                'tipo' => $r['tipo'],
                'league' => $liga,
                'notificar_saida' => (int)$r['notificar_saida'] === 1,
                'total' => $total,
                'sorteados' => $sorteados,
                'concluido' => $total > 0 && $sorteados === $total,
                'bloqueada' => $sorteados > 0,
                // O front usa isso pra esconder girar/editar/excluir de quem só assiste.
                'pode_girar' => $minhasLigasAdmin && ($liga === null || in_array($liga, $minhasLigasAdmin, true)),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode([
            'success'   => true,
            'roletas'   => $roletas,
            'is_admin'  => (bool)$minhasLigasAdmin,
            'minhas_ligas' => $minhasLigasAdmin,
        ]);
        exit;
    }

    if ($action === 'estado') {
        $id = (int)($_GET['id'] ?? 0);
        $estado = $id ? estadoRoleta($pdo, $id) : null;
        if (!$estado) {
            echo json_encode(['success' => false, 'error' => 'Roleta não encontrada']);
            exit;
        }
        $ligaRoleta = ligaDaRoleta($pdo, $id);
        // GM de outra liga não abre a roleta.
        if (!$minhasLigasAdmin && $ligaRoleta !== null && ligaDoGm($pdo, $user_id) !== $ligaRoleta) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Esta roleta é da liga ' . $ligaRoleta . '.']);
            exit;
        }
        $podeGirar = $minhasLigasAdmin && ($ligaRoleta === null || in_array($ligaRoleta, $minhasLigasAdmin, true));
        echo json_encode([
            'success'      => true,
            'league'       => $ligaRoleta,
            'pode_girar'   => $podeGirar,
            'minhas_ligas' => $podeGirar ? $minhasLigasAdmin : [],
        ] + $estado);
        exit;
    }

    if ($action === 'buscar_participantes') {
        if (!$minhasLigasAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
            exit;
        }
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            echo json_encode(['success' => true, 'resultados' => []]);
            exit;
        }
        $excluirTeamIds = array_filter(array_map('intval', explode(',', (string)($_GET['excluir_team_ids'] ?? ''))));
        $like = '%' . $q . '%';
        $sql = "SELECT t.id AS team_id, t.user_id, t.league, t.photo_url,
                       CONCAT(t.city,' ',t.name) AS time_label, u.name AS gm_label
                FROM teams t
                JOIN users u ON u.id = t.user_id
                WHERE (CONCAT(t.city,' ',t.name) LIKE ? OR u.name LIKE ?)";
        $params = [$like, $like];
        if ($excluirTeamIds) {
            $ph = implode(',', array_fill(0, count($excluirTeamIds), '?'));
            $sql .= " AND t.id NOT IN ($ph)";
            $params = array_merge($params, $excluirTeamIds);
        }
        $sql .= " ORDER BY u.name ASC LIMIT 15";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $resultados = array_map(fn($r) => [
            'team_id' => (int)$r['team_id'],
            'user_id' => (int)$r['user_id'],
            'league' => $r['league'],
            'photo_url' => $r['photo_url'],
            'time_label' => $r['time_label'],
            'gm_label' => $r['gm_label'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode(['success' => true, 'resultados' => $resultados]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
    exit;
}

if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'criar') {
        $titulo = trim((string)($body['titulo'] ?? ''));
        $tipo = in_array($body['tipo'] ?? '', ['gms', 'times', 'personalizado'], true) ? $body['tipo'] : 'gms';
        $notificar = !empty($body['notificar_saida']) ? 1 : 0;
        $participantes = is_array($body['participantes'] ?? null) ? $body['participantes'] : [];

        // Liga da roleta: o que o admin escolheu, ou — se ele administra só
        // uma — a dele. É o que faz a roleta aparecer no dashboard certo.
        $liga = strtoupper(trim((string)($body['league'] ?? '')));
        if ($liga === '' && count($minhasLigasAdmin) === 1) $liga = $minhasLigasAdmin[0];
        if ($liga === '') {
            echo json_encode(['success' => false, 'error' => 'Escolha a liga da roleta.']);
            exit;
        }
        exigirAdminDaRoleta($pdo, $user_id, $minhasLigasAdmin, $liga);

        if ($titulo === '') {
            echo json_encode(['success' => false, 'error' => 'Título obrigatório']);
            exit;
        }
        if (count($participantes) < 2) {
            echo json_encode(['success' => false, 'error' => 'Adicione pelo menos 2 participantes']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO roletas (titulo, tipo, league, notificar_saida, criado_por) VALUES (?,?,?,?,?)");
            $stmt->execute([$titulo, $tipo, $liga, $notificar, $user_id]);
            $roletaId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare("INSERT INTO roleta_participantes (roleta_id, ordem, team_id, user_id, nome_display) VALUES (?,?,?,?,?)");
            $ordem = 0;
            foreach ($participantes as $p) {
                $ordem++;
                if ($tipo === 'personalizado') {
                    $nome = trim((string)($p['nome_display'] ?? ''));
                    if ($nome === '') { $ordem--; continue; }
                    $ins->execute([$roletaId, $ordem, null, null, $nome]);
                } else {
                    $teamId = (int)($p['team_id'] ?? 0);
                    $userId = (int)($p['user_id'] ?? 0);
                    if (!$teamId || !$userId) { $ordem--; continue; }
                    $nome = $tipo === 'times' ? (string)($p['time_label'] ?? '') : (string)($p['gm_label'] ?? '');
                    if ($nome === '') { $ordem--; continue; }
                    $ins->execute([$roletaId, $ordem, $teamId, $userId, $nome]);
                }
            }
            if ($ordem < 2) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Adicione pelo menos 2 participantes válidos']);
                exit;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('roleta criar: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erro ao criar a roleta.']);
            exit;
        }

        echo json_encode(['success' => true] + estadoRoleta($pdo, $roletaId));
        exit;
    }

    // Trocar a liga é uma ação à parte: continua valendo depois do 1º giro,
    // porque só muda quem enxerga a roleta — não mexe no sorteio em si.
    if ($action === 'definir_liga') {
        $id = (int)($body['id'] ?? 0);
        $nova = strtoupper(trim((string)($body['league'] ?? '')));
        if (!$id || $nova === '') {
            echo json_encode(['success' => false, 'error' => 'Roleta e liga são obrigatórias.']);
            exit;
        }
        // Precisa ser admin da liga atual E da liga de destino: senão daria
        // pra empurrar uma roleta pra uma liga que não é sua.
        exigirAdminDaRoleta($pdo, $user_id, $minhasLigasAdmin, ligaDaRoleta($pdo, $id));
        exigirAdminDaRoleta($pdo, $user_id, $minhasLigasAdmin, $nova);

        $pdo->prepare("UPDATE roletas SET league = ? WHERE id = ?")->execute([$nova, $id]);
        // O draft gerado por esta roleta acompanha a mudança.
        try {
            $pdo->prepare("UPDATE drafts_aleatorios SET league = ? WHERE roleta_id = ?")->execute([$nova, $id]);
        } catch (Throwable $e) { /* banco sem drafts_aleatorios */ }

        echo json_encode(['success' => true, 'league' => $nova]);
        exit;
    }

    if ($action === 'editar') {
        $id = (int)($body['id'] ?? 0);
        if ($id) exigirAdminDaRoleta($pdo, $user_id, $minhasLigasAdmin, ligaDaRoleta($pdo, $id));
        if (!$id || roletaBloqueada($pdo, $id)) {
            echo json_encode(['success' => false, 'error' => 'Esta roleta já teve o primeiro sorteio e não pode mais ser editada.']);
            exit;
        }

        $sets = [];
        $params = [];
        if (isset($body['titulo'])) {
            $titulo = trim((string)$body['titulo']);
            if ($titulo === '') {
                echo json_encode(['success' => false, 'error' => 'Título obrigatório']);
                exit;
            }
            $sets[] = 'titulo = ?';
            $params[] = $titulo;
        }
        if (array_key_exists('notificar_saida', $body)) {
            $sets[] = 'notificar_saida = ?';
            $params[] = !empty($body['notificar_saida']) ? 1 : 0;
        }
        if ($sets) {
            $params[] = $id;
            $pdo->prepare('UPDATE roletas SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        }

        if (!empty($body['remover_ids']) && is_array($body['remover_ids'])) {
            $ids = array_filter(array_map('intval', $body['remover_ids']));
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("DELETE FROM roleta_participantes WHERE roleta_id = ? AND id IN ($ph) AND pick_number IS NULL")
                    ->execute(array_merge([$id], $ids));
            }
        }

        if (!empty($body['adicionar']) && is_array($body['adicionar'])) {
            $stmtEstado = $pdo->prepare("SELECT tipo FROM roletas WHERE id = ?");
            $stmtEstado->execute([$id]);
            $tipo = $stmtEstado->fetchColumn();

            $stmtOrdem = $pdo->prepare("SELECT COALESCE(MAX(ordem),0) FROM roleta_participantes WHERE roleta_id = ?");
            $stmtOrdem->execute([$id]);
            $ordem = (int)$stmtOrdem->fetchColumn();

            $ins = $pdo->prepare("INSERT INTO roleta_participantes (roleta_id, ordem, team_id, user_id, nome_display) VALUES (?,?,?,?,?)");
            foreach ($body['adicionar'] as $p) {
                $ordem++;
                if ($tipo === 'personalizado') {
                    $nome = trim((string)($p['nome_display'] ?? ''));
                    if ($nome === '') { $ordem--; continue; }
                    $ins->execute([$id, $ordem, null, null, $nome]);
                } else {
                    $teamId = (int)($p['team_id'] ?? 0);
                    $userId = (int)($p['user_id'] ?? 0);
                    if (!$teamId || !$userId) { $ordem--; continue; }
                    $nome = $tipo === 'times' ? (string)($p['time_label'] ?? '') : (string)($p['gm_label'] ?? '');
                    if ($nome === '') { $ordem--; continue; }
                    $ins->execute([$id, $ordem, $teamId, $userId, $nome]);
                }
            }
        }

        echo json_encode(['success' => true] + estadoRoleta($pdo, $id));
        exit;
    }

    if ($action === 'girar') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'id obrigatório']);
            exit;
        }
        // Girar é o ponto que decide a ordem do draft: só o admin da liga.
        exigirAdminDaRoleta($pdo, $user_id, $minhasLigasAdmin, ligaDaRoleta($pdo, $id));

        $pdo->beginTransaction();
        try {
            $stmtR = $pdo->prepare("SELECT titulo, notificar_saida FROM roletas WHERE id = ? FOR UPDATE");
            $stmtR->execute([$id]);
            $roletaRow = $stmtR->fetch(PDO::FETCH_ASSOC);
            if (!$roletaRow) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Roleta não encontrada']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT id, nome_display FROM roleta_participantes WHERE roleta_id = ? AND pick_number IS NULL FOR UPDATE");
            $stmt->execute([$id]);
            $urna = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$urna) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'O sorteio já terminou.']);
                exit;
            }
            $escolhido = $urna[random_int(0, count($urna) - 1)];
            $pick = count($urna);

            $pdo->prepare("UPDATE roleta_participantes SET pick_number = ?, eliminated_at = NOW() WHERE id = ?")
                ->execute([$pick, $escolhido['id']]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('roleta girar: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erro ao girar a roleta.']);
            exit;
        }

        if (!empty($roletaRow['notificar_saida'])) {
            notificarSaidaRoletaGenerica($pdo, $id, $roletaRow['titulo'], $escolhido['nome_display'], $pick);
        }

        echo json_encode([
            'success' => true,
            'sorteado_id' => (int)$escolhido['id'],
            'nome_display' => $escolhido['nome_display'],
            'pick' => $pick,
        ] + estadoRoleta($pdo, $id));
        exit;
    }

    if ($action === 'reiniciar') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'id obrigatório']);
            exit;
        }
        exigirAdminDaRoleta($pdo, $user_id, $minhasLigasAdmin, ligaDaRoleta($pdo, $id));
        $pdo->prepare("UPDATE roleta_participantes SET pick_number = NULL, eliminated_at = NULL WHERE roleta_id = ?")->execute([$id]);
        echo json_encode(['success' => true] + estadoRoleta($pdo, $id));
        exit;
    }

    if ($action === 'excluir') {
        $id = (int)($body['id'] ?? 0);
        if ($id) exigirAdminDaRoleta($pdo, $user_id, $minhasLigasAdmin, ligaDaRoleta($pdo, $id));
        if (!$id || roletaBloqueada($pdo, $id)) {
            echo json_encode(['success' => false, 'error' => 'Esta roleta já teve o primeiro sorteio e não pode mais ser excluída.']);
            exit;
        }
        $pdo->prepare("DELETE FROM roletas WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não suportado']);
