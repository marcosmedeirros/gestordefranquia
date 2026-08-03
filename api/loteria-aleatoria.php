<?php
/**
 * API das Loterias Aleatórias — o admin monta quantas loterias quiser (GMs,
 * times ou lista personalizada) e dá uma chance (%) pra cada participante.
 * Cada sorteio define a próxima escolha, sorteando entre quem ainda não saiu
 * com peso proporcional à chance de cada um.
 *
 * Diferença pra roleta: lá todo mundo tem o mesmo peso e quem sai primeiro
 * fica com a PIOR posição. Aqui o peso é a % informada e quem sai primeiro
 * fica com a escolha 1 — é uma loteria de verdade.
 *
 * Ver é livre pra quem é da liga (é o que o card do painel usa); criar,
 * editar, sortear, reiniciar e excluir são do admin DAQUELA liga.
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
$minhasLigasAdmin = $is_admin ? array_map('strtoupper', getAdminLeagues($pdo, $user_id)) : [];

function ensureLoteriasTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS loterias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titulo VARCHAR(160) NOT NULL,
        tipo ENUM('gms','times','personalizado') NOT NULL DEFAULT 'times',
        league VARCHAR(20) NULL,
        notificar_saida TINYINT(1) NOT NULL DEFAULT 1,
        criado_por INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // chance guarda a % informada pelo admin (ex: 16.500). O sorteio usa ela
    // como peso, então não precisa somar exatamente 100 — é normalizado na hora.
    $pdo->exec("CREATE TABLE IF NOT EXISTS loteria_participantes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        loteria_id INT NOT NULL,
        ordem INT NOT NULL,
        team_id INT NULL,
        user_id INT NULL,
        nome_display VARCHAR(180) NOT NULL,
        chance DECIMAL(7,3) NOT NULL DEFAULT 0,
        pick_number INT NULL,
        sorteado_em TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_lot_ordem (loteria_id, ordem),
        INDEX idx_lot_pick (loteria_id, pick_number),
        CONSTRAINT fk_lp_loteria FOREIGN KEY (loteria_id) REFERENCES loterias(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
ensureLoteriasTables($pdo);

/** Liga da loteria (null = loteria antiga, sem liga definida). */
function ligaDaLoteria(PDO $pdo, int $id): ?string
{
    $stmt = $pdo->prepare("SELECT league FROM loterias WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $l = $stmt->fetchColumn();
    return $l ? strtoupper((string)$l) : null;
}

/** Portaria das ações de escrita — só o admin da liga da loteria passa. */
function exigirAdminDaLoteria(PDO $pdo, array $minhasLigas, ?string $liga): void
{
    if (!$minhasLigas) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
        exit;
    }
    if ($liga !== null && !in_array($liga, $minhasLigas, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Só o admin da ' . $liga . ' pode mexer nesta loteria.']);
        exit;
    }
}

/**
 * Ligas do GM logado — do time E do cadastro. Normalmente são a mesma, mas se
 * estiverem dessincronizadas aceita qualquer uma das duas, pra não travar quem
 * já está na liga certa por um lado só (mesma regra da roleta).
 */
function ligasDoGmLoteria(PDO $pdo, int $userId): array
{
    $ligas = [];
    foreach ([['teams', 'user_id'], ['users', 'id']] as [$tabela, $col]) {
        try {
            $stmt = $pdo->prepare("SELECT league FROM {$tabela} WHERE {$col} = ? LIMIT 1");
            $stmt->execute([$userId]);
            $l = $stmt->fetchColumn();
            if ($l) $ligas[] = strtoupper((string)$l);
        } catch (Throwable $e) {}
    }
    return array_values(array_unique($ligas));
}

/** Trava (título/participantes/chances) assim que o 1º sorteio acontece. */
function loteriaBloqueada(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM loteria_participantes WHERE loteria_id = ? AND pick_number IS NOT NULL LIMIT 1");
    $stmt->execute([$id]);
    return (bool)$stmt->fetchColumn();
}

function estadoLoteria(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT id, titulo, tipo, league, notificar_saida FROM loterias WHERE id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;

    $stmtP = $pdo->prepare("
        SELECT lp.id, lp.ordem, lp.team_id, lp.user_id, lp.nome_display, lp.chance,
               lp.pick_number, lp.sorteado_em, t.photo_url
        FROM loteria_participantes lp
        LEFT JOIN teams t ON t.id = lp.team_id
        WHERE lp.loteria_id = ?
        ORDER BY lp.ordem ASC
    ");
    $stmtP->execute([$id]);
    $linhas = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    $naUrna = [];
    $sorteados = [];
    $somaRestante = 0.0;
    foreach ($linhas as $l) {
        $l['chance']      = (float)$l['chance'];
        $l['pick_number'] = $l['pick_number'] !== null ? (int)$l['pick_number'] : null;
        if ($l['pick_number'] === null) {
            $somaRestante += $l['chance'];
            $naUrna[] = $l;
        } else {
            $sorteados[] = $l;
        }
    }

    // Chance REAL de cada um levar a próxima escolha: a % informada
    // renormalizada sobre quem ainda está na urna. É esse número que a tela
    // mostra, senão as porcentagens ficariam mentindo depois do 1º sorteio.
    foreach ($naUrna as &$p) {
        $p['chance_atual'] = $somaRestante > 0 ? round(($p['chance'] / $somaRestante) * 100, 2) : 0;
    }
    unset($p);
    usort($naUrna, fn($a, $b) => $b['chance'] <=> $a['chance']);
    usort($sorteados, fn($a, $b) => $a['pick_number'] <=> $b['pick_number']);

    return [
        'id'              => (int)$r['id'],
        'titulo'          => $r['titulo'],
        'tipo'            => $r['tipo'],
        'league'          => $r['league'] ? strtoupper((string)$r['league']) : null,
        'notificar_saida' => (int)$r['notificar_saida'] === 1,
        'na_urna'         => $naUrna,
        'sorteados'       => $sorteados,
        'total'           => count($linhas),
        'concluido'       => count($linhas) > 0 && count($naUrna) === 0,
        'bloqueada'       => count($sorteados) > 0,
    ];
}

/**
 * Sorteia um participante entre os da urna, com peso = chance.
 *
 * Trabalha em milésimos de ponto percentual pra não depender de float, e usa
 * random_int (fonte criptográfica) em vez de rand/mt_rand — numa loteria que
 * define ordem de pick, previsibilidade não é aceitável.
 * Quem tem chance 0 só entra em jogo se TODO mundo na urna estiver zerado.
 */
function sortearComPeso(array $candidatos): array
{
    $pesos = [];
    $total = 0;
    foreach ($candidatos as $c) {
        $p = (int)round(((float)$c['chance']) * 1000);
        if ($p < 0) $p = 0;
        $pesos[] = $p;
        $total += $p;
    }
    if ($total <= 0) {
        // Ninguém tem chance definida: cai no sorteio uniforme.
        return $candidatos[random_int(0, count($candidatos) - 1)];
    }

    $bilhete = random_int(1, $total);
    $acum = 0;
    foreach ($candidatos as $i => $c) {
        $acum += $pesos[$i];
        if ($bilhete <= $acum) return $c;
    }
    return $candidatos[count($candidatos) - 1]; // inalcançável na prática
}

/** Avisa os participantes a cada escolha definida (best-effort). */
function notificarSorteioLoteria(PDO $pdo, int $loteriaId, string $titulo, string $nome, int $pick): void
{
    $pushFile = dirname(__DIR__) . '/backend/push.php';
    if (!file_exists($pushFile)) return;
    try {
        $stmt = $pdo->prepare("SELECT DISTINCT user_id FROM loteria_participantes WHERE loteria_id = ? AND user_id IS NOT NULL");
        $stmt->execute([$loteriaId]);
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$userIds) return;

        require_once $pushFile;
        $payload = [
            'title' => "🎰 {$titulo}",
            'body'  => "Escolha {$pick}: {$nome}!",
            'url'   => '/loteria-aleatoria.php?id=' . $loteriaId,
        ];
        foreach ($userIds as $uid) {
            try { sendPushToUser($pdo, (int)$uid, $payload, 'eventos'); }
            catch (Throwable $e) { error_log('notificarSorteioLoteria (user ' . $uid . '): ' . $e->getMessage()); }
        }
    } catch (Throwable $e) {
        error_log('notificarSorteioLoteria: ' . $e->getMessage());
    }
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'listar';

    if ($action === 'listar') {
        $ligasVisiveis = $minhasLigasAdmin ?: ligasDoGmLoteria($pdo, $user_id);
        $where = "WHERE l.league IS NULL";
        $params = [];
        if ($ligasVisiveis) {
            $ph = implode(',', array_fill(0, count($ligasVisiveis), '?'));
            $where = "WHERE (l.league IS NULL OR UPPER(l.league) IN ($ph))";
            $params = $ligasVisiveis;
        }

        $stmt = $pdo->prepare("
            SELECT l.id, l.titulo, l.tipo, l.league, l.notificar_saida, l.created_at,
                   COUNT(lp.id) AS total,
                   SUM(lp.pick_number IS NOT NULL) AS sorteados
            FROM loterias l
            LEFT JOIN loteria_participantes lp ON lp.loteria_id = l.id
            $where
            GROUP BY l.id
            ORDER BY l.created_at DESC
        ");
        $stmt->execute($params);
        $loterias = array_map(function ($r) use ($minhasLigasAdmin) {
            $total = (int)$r['total'];
            $sorteados = (int)$r['sorteados'];
            $liga = $r['league'] ? strtoupper((string)$r['league']) : null;
            return [
                'id'              => (int)$r['id'],
                'titulo'          => $r['titulo'],
                'tipo'            => $r['tipo'],
                'league'          => $liga,
                'notificar_saida' => (int)$r['notificar_saida'] === 1,
                'total'           => $total,
                'sorteados'       => $sorteados,
                'concluido'       => $total > 0 && $sorteados === $total,
                'bloqueada'       => $sorteados > 0,
                'pode_sortear'    => (bool)$minhasLigasAdmin && ($liga === null || in_array($liga, $minhasLigasAdmin, true)),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode([
            'success'      => true,
            'loterias'     => $loterias,
            'is_admin'     => (bool)$minhasLigasAdmin,
            'minhas_ligas' => $minhasLigasAdmin,
        ]);
        exit;
    }

    if ($action === 'estado') {
        $id = (int)($_GET['id'] ?? 0);
        $estado = $id ? estadoLoteria($pdo, $id) : null;
        if (!$estado) {
            echo json_encode(['success' => false, 'error' => 'Loteria não encontrada']);
            exit;
        }
        $liga = $estado['league'];
        if (!$minhasLigasAdmin && $liga !== null && !in_array($liga, ligasDoGmLoteria($pdo, $user_id), true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Esta loteria é da liga ' . $liga . '.']);
            exit;
        }
        $podeSortear = (bool)$minhasLigasAdmin && ($liga === null || in_array($liga, $minhasLigasAdmin, true));
        echo json_encode([
            'success'      => true,
            'pode_sortear' => $podeSortear,
            'minhas_ligas' => $podeSortear ? $minhasLigasAdmin : [],
        ] + $estado);
        exit;
    }

    // Autocomplete de participantes — mesmo formato do da roleta.
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
        $excluir = array_filter(array_map('intval', explode(',', (string)($_GET['excluir_team_ids'] ?? ''))));
        $like = '%' . $q . '%';
        $sql = "SELECT t.id AS team_id, t.user_id, t.league, t.photo_url,
                       CONCAT(t.city,' ',t.name) AS time_label, u.name AS gm_label
                FROM teams t
                JOIN users u ON u.id = t.user_id
                WHERE (CONCAT(t.city,' ',t.name) LIKE ? OR u.name LIKE ?)";
        $params = [$like, $like];
        if ($excluir) {
            $ph = implode(',', array_fill(0, count($excluir), '?'));
            $sql .= " AND t.id NOT IN ($ph)";
            $params = array_merge($params, $excluir);
        }
        $sql .= " ORDER BY u.name ASC LIMIT 15";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'resultados' => array_map(fn($r) => [
            'team_id'    => (int)$r['team_id'],
            'user_id'    => (int)$r['user_id'],
            'league'     => $r['league'],
            'photo_url'  => $r['photo_url'],
            'time_label' => $r['time_label'],
            'gm_label'   => $r['gm_label'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC))]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
    exit;
}

// ── POST ─────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'criar') {
        $titulo = trim((string)($body['titulo'] ?? ''));
        $tipo = in_array($body['tipo'] ?? '', ['gms', 'times', 'personalizado'], true) ? $body['tipo'] : 'times';
        $notificar = !empty($body['notificar_saida']) ? 1 : 0;
        $participantes = is_array($body['participantes'] ?? null) ? $body['participantes'] : [];

        $liga = strtoupper(trim((string)($body['league'] ?? '')));
        if ($liga === '' && count($minhasLigasAdmin) === 1) $liga = $minhasLigasAdmin[0];
        if ($liga === '') {
            echo json_encode(['success' => false, 'error' => 'Escolha a liga da loteria.']);
            exit;
        }
        exigirAdminDaLoteria($pdo, $minhasLigasAdmin, $liga);

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
            $pdo->prepare("INSERT INTO loterias (titulo, tipo, league, notificar_saida, criado_por) VALUES (?,?,?,?,?)")
                ->execute([mb_substr($titulo, 0, 160), $tipo, $liga, $notificar, $user_id]);
            $loteriaId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare("INSERT INTO loteria_participantes (loteria_id, ordem, team_id, user_id, nome_display, chance) VALUES (?,?,?,?,?,?)");
            $ordem = 0;
            foreach ($participantes as $p) {
                $chance = max(0, min(100, (float)($p['chance'] ?? 0)));
                if ($tipo === 'personalizado') {
                    $nome = trim((string)($p['nome_display'] ?? ''));
                    if ($nome === '') continue;
                    $ins->execute([$loteriaId, ++$ordem, null, null, $nome, $chance]);
                } else {
                    $teamId = (int)($p['team_id'] ?? 0);
                    $userId = (int)($p['user_id'] ?? 0);
                    if (!$teamId || !$userId) continue;
                    $nome = $tipo === 'times' ? (string)($p['time_label'] ?? '') : (string)($p['gm_label'] ?? '');
                    if ($nome === '') continue;
                    $ins->execute([$loteriaId, ++$ordem, $teamId, $userId, $nome, $chance]);
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
            error_log('loteria criar: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erro ao criar a loteria.']);
            exit;
        }

        echo json_encode(['success' => true] + estadoLoteria($pdo, $loteriaId));
        exit;
    }

    if ($action === 'definir_liga') {
        $id = (int)($body['id'] ?? 0);
        $nova = strtoupper(trim((string)($body['league'] ?? '')));
        if (!$id || $nova === '') {
            echo json_encode(['success' => false, 'error' => 'Loteria e liga são obrigatórias.']);
            exit;
        }
        exigirAdminDaLoteria($pdo, $minhasLigasAdmin, ligaDaLoteria($pdo, $id));
        exigirAdminDaLoteria($pdo, $minhasLigasAdmin, $nova);
        $pdo->prepare("UPDATE loterias SET league = ? WHERE id = ?")->execute([$nova, $id]);
        echo json_encode(['success' => true, 'league' => $nova]);
        exit;
    }

    if ($action === 'editar') {
        $id = (int)($body['id'] ?? 0);
        if ($id) exigirAdminDaLoteria($pdo, $minhasLigasAdmin, ligaDaLoteria($pdo, $id));
        if (!$id || loteriaBloqueada($pdo, $id)) {
            echo json_encode(['success' => false, 'error' => 'Esta loteria já teve o 1º sorteio e não pode mais ser editada.']);
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
            $params[] = mb_substr($titulo, 0, 160);
        }
        if (array_key_exists('notificar_saida', $body)) {
            $sets[] = 'notificar_saida = ?';
            $params[] = !empty($body['notificar_saida']) ? 1 : 0;
        }
        if ($sets) {
            $params[] = $id;
            $pdo->prepare('UPDATE loterias SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        }

        // Chances: mapa participante_id => %. Só aceita quem é desta loteria.
        if (!empty($body['chances']) && is_array($body['chances'])) {
            $upd = $pdo->prepare("UPDATE loteria_participantes SET chance = ? WHERE id = ? AND loteria_id = ?");
            foreach ($body['chances'] as $pid => $valor) {
                $upd->execute([max(0, min(100, (float)$valor)), (int)$pid, $id]);
            }
        }

        if (!empty($body['remover_ids']) && is_array($body['remover_ids'])) {
            $ids = array_filter(array_map('intval', $body['remover_ids']));
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("DELETE FROM loteria_participantes WHERE loteria_id = ? AND id IN ($ph) AND pick_number IS NULL")
                    ->execute(array_merge([$id], $ids));
            }
        }

        if (!empty($body['adicionar']) && is_array($body['adicionar'])) {
            $stmtTipo = $pdo->prepare("SELECT tipo FROM loterias WHERE id = ?");
            $stmtTipo->execute([$id]);
            $tipo = (string)$stmtTipo->fetchColumn();
            $stmtMax = $pdo->prepare("SELECT COALESCE(MAX(ordem), 0) FROM loteria_participantes WHERE loteria_id = ?");
            $stmtMax->execute([$id]);
            $ordem = (int)$stmtMax->fetchColumn();
            $ins = $pdo->prepare("INSERT INTO loteria_participantes (loteria_id, ordem, team_id, user_id, nome_display, chance) VALUES (?,?,?,?,?,?)");
            foreach ($body['adicionar'] as $p) {
                $chance = max(0, min(100, (float)($p['chance'] ?? 0)));
                if ($tipo === 'personalizado') {
                    $nome = trim((string)($p['nome_display'] ?? ''));
                    if ($nome === '') continue;
                    $ins->execute([$id, ++$ordem, null, null, $nome, $chance]);
                } else {
                    $teamId = (int)($p['team_id'] ?? 0);
                    $userId = (int)($p['user_id'] ?? 0);
                    if (!$teamId || !$userId) continue;
                    $nome = $tipo === 'times' ? (string)($p['time_label'] ?? '') : (string)($p['gm_label'] ?? '');
                    if ($nome === '') continue;
                    $ins->execute([$id, ++$ordem, $teamId, $userId, $nome, $chance]);
                }
            }
        }

        echo json_encode(['success' => true] + estadoLoteria($pdo, $id));
        exit;
    }

    if ($action === 'sortear') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'id obrigatório']);
            exit;
        }
        exigirAdminDaLoteria($pdo, $minhasLigasAdmin, ligaDaLoteria($pdo, $id));

        $pdo->beginTransaction();
        try {
            // FOR UPDATE: dois cliques simultâneos no "Sortear" não podem
            // definir a mesma escolha duas vezes.
            $stmt = $pdo->prepare("SELECT id, nome_display, chance FROM loteria_participantes
                                   WHERE loteria_id = ? AND pick_number IS NULL
                                   ORDER BY ordem ASC FOR UPDATE");
            $stmt->execute([$id]);
            $urna = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$urna) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'A loteria já está completa.']);
                exit;
            }

            $stmtPick = $pdo->prepare("SELECT COALESCE(MAX(pick_number), 0) FROM loteria_participantes WHERE loteria_id = ?");
            $stmtPick->execute([$id]);
            $pick = (int)$stmtPick->fetchColumn() + 1;

            $ganhador = sortearComPeso($urna);
            $pdo->prepare("UPDATE loteria_participantes SET pick_number = ?, sorteado_em = NOW() WHERE id = ?")
                ->execute([$pick, (int)$ganhador['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('loteria sortear #' . $id . ': ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erro ao sortear.']);
            exit;
        }

        $estado = estadoLoteria($pdo, $id);
        if (!empty($estado['notificar_saida'])) {
            notificarSorteioLoteria($pdo, $id, (string)$estado['titulo'], (string)$ganhador['nome_display'], $pick);
        }

        echo json_encode([
            'success'      => true,
            'sorteado_id'  => (int)$ganhador['id'],
            'pick_number'  => $pick,
        ] + $estado);
        exit;
    }

    if ($action === 'reiniciar') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'id obrigatório']);
            exit;
        }
        exigirAdminDaLoteria($pdo, $minhasLigasAdmin, ligaDaLoteria($pdo, $id));
        $pdo->prepare("UPDATE loteria_participantes SET pick_number = NULL, sorteado_em = NULL WHERE loteria_id = ?")->execute([$id]);
        echo json_encode(['success' => true] + estadoLoteria($pdo, $id));
        exit;
    }

    /**
     * Duplica a loteria levando participantes E chances — só o sorteio fica
     * pra trás. É o caso comum: mesmas odds, temporada nova.
     */
    if ($action === 'duplicar') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'id obrigatório']);
            exit;
        }
        exigirAdminDaLoteria($pdo, $minhasLigasAdmin, ligaDaLoteria($pdo, $id));

        $stmtOrig = $pdo->prepare("SELECT titulo, tipo, league, notificar_saida FROM loterias WHERE id = ?");
        $stmtOrig->execute([$id]);
        $orig = $stmtOrig->fetch(PDO::FETCH_ASSOC);
        if (!$orig) {
            echo json_encode(['success' => false, 'error' => 'Loteria não encontrada']);
            exit;
        }

        $titulo = trim((string)($body['titulo'] ?? ''));
        if ($titulo === '') $titulo = $orig['titulo'] . ' (cópia)';

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO loterias (titulo, tipo, league, notificar_saida, criado_por) VALUES (?,?,?,?,?)")
                ->execute([mb_substr($titulo, 0, 160), $orig['tipo'], $orig['league'], (int)$orig['notificar_saida'], $user_id]);
            $novoId = (int)$pdo->lastInsertId();

            // chance vem junto; pick_number/sorteado_em não — esses são o sorteio.
            $pdo->prepare("INSERT INTO loteria_participantes (loteria_id, ordem, team_id, user_id, nome_display, chance)
                           SELECT ?, ordem, team_id, user_id, nome_display, chance
                           FROM loteria_participantes WHERE loteria_id = ? ORDER BY ordem ASC")
                ->execute([$novoId, $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('loteria duplicar #' . $id . ': ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erro ao duplicar a loteria.']);
            exit;
        }

        echo json_encode(['success' => true, 'id' => $novoId]);
        exit;
    }

    if ($action === 'excluir') {
        $id = (int)($body['id'] ?? 0);
        if ($id) exigirAdminDaLoteria($pdo, $minhasLigasAdmin, ligaDaLoteria($pdo, $id));
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'id obrigatório']);
            exit;
        }
        $pdo->prepare("DELETE FROM loterias WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método inválido']);
