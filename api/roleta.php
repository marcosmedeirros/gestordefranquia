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
$user_id = (int)$_SESSION['user_id'];
if (!hasAdminAccess($pdo, $user_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
    exit;
}

function ensureRoletasTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS roletas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titulo VARCHAR(160) NOT NULL,
        tipo ENUM('gms','times','personalizado') NOT NULL DEFAULT 'gms',
        notificar_saida TINYINT(1) NOT NULL DEFAULT 1,
        criado_por INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
            sendPushToUser($pdo, (int)$uid, $payload);
        } catch (Throwable $e) {
            error_log('notificarSaidaRoletaGenerica (push user_id=' . $uid . '): ' . $e->getMessage());
        }
    }
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'listar';

    if ($action === 'listar') {
        $stmt = $pdo->query("
            SELECT r.id, r.titulo, r.tipo, r.notificar_saida, r.created_at,
                   COUNT(rp.id) AS total,
                   SUM(rp.pick_number IS NOT NULL) AS sorteados
            FROM roletas r
            LEFT JOIN roleta_participantes rp ON rp.roleta_id = r.id
            GROUP BY r.id
            ORDER BY r.created_at DESC
        ");
        $roletas = array_map(function ($r) {
            $total = (int)$r['total'];
            $sorteados = (int)$r['sorteados'];
            return [
                'id' => (int)$r['id'],
                'titulo' => $r['titulo'],
                'tipo' => $r['tipo'],
                'notificar_saida' => (int)$r['notificar_saida'] === 1,
                'total' => $total,
                'sorteados' => $sorteados,
                'concluido' => $total > 0 && $sorteados === $total,
                'bloqueada' => $sorteados > 0,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode(['success' => true, 'roletas' => $roletas]);
        exit;
    }

    if ($action === 'estado') {
        $id = (int)($_GET['id'] ?? 0);
        $estado = $id ? estadoRoleta($pdo, $id) : null;
        if (!$estado) {
            echo json_encode(['success' => false, 'error' => 'Roleta não encontrada']);
            exit;
        }
        echo json_encode(['success' => true] + $estado);
        exit;
    }

    if ($action === 'buscar_participantes') {
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
            $stmt = $pdo->prepare("INSERT INTO roletas (titulo, tipo, notificar_saida, criado_por) VALUES (?,?,?,?)");
            $stmt->execute([$titulo, $tipo, $notificar, $user_id]);
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

    if ($action === 'editar') {
        $id = (int)($body['id'] ?? 0);
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
        $pdo->prepare("UPDATE roleta_participantes SET pick_number = NULL, eliminated_at = NULL WHERE roleta_id = ?")->execute([$id]);
        echo json_encode(['success' => true] + estadoRoleta($pdo, $id));
        exit;
    }

    if ($action === 'excluir') {
        $id = (int)($body['id'] ?? 0);
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
