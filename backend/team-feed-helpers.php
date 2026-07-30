<?php
/**
 * Funções compartilhadas do Feed do Time (team-history.php) e do Timeline
 * global (timeline.php) — extraídas de api/team-feed.php pra poder ser
 * usadas por mais de um endpoint sem duplicar a lógica de leitura/upload.
 *
 * getTeamTimeline()/getTeamPosts() aceitam $teamId nulo pra modo global
 * (todos os times, com filtro opcional de liga) — usado pelo timeline.php;
 * chamadas existentes com $teamId inteiro continuam idênticas.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function ensureTeamFeedTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_posts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        team_id INT NOT NULL,
        author_user_id INT NOT NULL,
        texto TEXT NULL,
        photo_url VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        deleted_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_team_posts_team (team_id, created_at),
        INDEX idx_team_posts_deleted (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS team_post_likes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        post_id INT NOT NULL,
        user_id INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_post_user (post_id, user_id),
        INDEX idx_tpl_post (post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS team_stories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        team_id INT NOT NULL,
        author_user_id INT NOT NULL,
        photo_url VARCHAR(255) NOT NULL,
        texto VARCHAR(280) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expira_em DATETIME NOT NULL,
        deleted_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_stories_team_active (team_id, expira_em),
        INDEX idx_stories_expira (expira_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS team_story_views (
        id INT AUTO_INCREMENT PRIMARY KEY,
        story_id INT NOT NULL,
        user_id INT NOT NULL,
        viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_story_user (story_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Time-a-time (não usuário-a-time) — usado só pelo Timeline global. */
function ensureTimelineTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_follows (
        id INT AUTO_INCREMENT PRIMARY KEY,
        follower_team_id INT NOT NULL,
        followed_team_id INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_follow (follower_team_id, followed_team_id),
        INDEX idx_follows_follower (follower_team_id),
        INDEX idx_follows_followed (followed_team_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function isTeamGmOrAdmin(PDO $pdo, array $user, int $teamId): bool
{
    if (hasAdminAccess($pdo, (int)$user['id'])) return true;
    $stmt = $pdo->prepare("SELECT 1 FROM teams WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$teamId, (int)$user['id']]);
    return (bool)$stmt->fetch();
}

/**
 * Timeline automática: uma query pequena por tabela-fonte (todas já
 * indexadas por team_id), normalizada em PHP e ordenada por data — mais
 * simples e seguro que um UNION gigante entre tabelas bem diferentes.
 *
 * $teamId nulo = modo global (todos os times, filtro opcional de $league).
 * Cada evento sai com team_id; no modo global também traz team_name/
 * team_photo/team_league via um lookup em lote (nada de N+1 por time).
 */
function getTeamTimeline(PDO $pdo, ?int $teamId, int $limit = 30, ?string $before = null, ?string $league = null): array
{
    $eventos = [];
    $global = $teamId === null;
    // MariaDB rejeita LIMIT vinculado como parâmetro de prepared statement
    // (chega como string, ex. LIMIT '20', e o parser não aceita) — $limit já
    // é garantidamente int pela assinatura da função, então interpola direto
    // em vez de passar por "?"/$params.
    $limitSql = (int)$limit;

    // Trades 1-para-1 aceitas
    if (!$global) {
        $sql = "
            SELECT t.id, t.from_team_id AS team_id, COALESCE(t.updated_at, t.created_at) AS data_evt,
                   GROUP_CONCAT(CONCAT(ti.player_name, IF(ti.from_team=1,' (enviado)',' (recebido)')) SEPARATOR ', ') AS itens
            FROM trades t
            JOIN trade_items ti ON ti.trade_id = t.id
            WHERE t.status = 'accepted' AND (t.from_team_id = ? OR t.to_team_id = ?)
            GROUP BY t.id" . ($before ? " HAVING data_evt < ?" : "") . "
            ORDER BY data_evt DESC LIMIT {$limitSql}
        ";
        $params = $before ? [$teamId, $teamId, $before] : [$teamId, $teamId];
    } else {
        $sql = "
            SELECT t.id, t.from_team_id AS team_id, COALESCE(t.updated_at, t.created_at) AS data_evt,
                   GROUP_CONCAT(ti.player_name SEPARATOR ', ') AS itens
            FROM trades t
            JOIN trade_items ti ON ti.trade_id = t.id" .
            ($league ? " JOIN teams tl ON tl.id = t.from_team_id" : "") . "
            WHERE t.status = 'accepted'" . ($league ? " AND tl.league = ?" : "") . "
            GROUP BY t.id" . ($before ? " HAVING data_evt < ?" : "") . "
            ORDER BY data_evt DESC LIMIT {$limitSql}
        ";
        $params = array_values(array_filter([$league, $before], fn($v) => $v !== null));
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $eventos[] = ['tipo' => 'trade', 'icone' => '🔄', 'texto' => 'Trade concluída: ' . ($r['itens'] ?: '—'), 'data' => $r['data_evt'], 'team_id' => (int)$r['team_id']];
    }

    // Trades multi-time aceitas
    if (!$global) {
        $sql = "
            SELECT mt.id, mt.updated_at AS data_evt,
                   GROUP_CONCAT(DISTINCT CONCAT(mti.player_name, IF(mti.from_team_id=?,' (enviado)',' (recebido)')) SEPARATOR ', ') AS itens
            FROM multi_trades mt
            JOIN multi_trade_teams mtt ON mtt.trade_id = mt.id AND mtt.team_id = ?
            LEFT JOIN multi_trade_items mti ON mti.trade_id = mt.id AND (mti.from_team_id = ? OR mti.to_team_id = ?) AND mti.player_name IS NOT NULL
            WHERE mt.status = 'accepted'" . ($before ? " AND mt.updated_at < ?" : "") . "
            GROUP BY mt.id
            ORDER BY data_evt DESC LIMIT {$limitSql}
        ";
        $params = $before ? [$teamId, $teamId, $teamId, $teamId, $before] : [$teamId, $teamId, $teamId, $teamId];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $eventos[] = ['tipo' => 'trade', 'icone' => '🔄', 'texto' => 'Trade multi-time concluída' . ($r['itens'] ? ': ' . $r['itens'] : ''), 'data' => $r['data_evt'], 'team_id' => $teamId];
        }
    } else {
        $sql = "
            SELECT mt.id, mt.created_by_team_id AS team_id, mt.updated_at AS data_evt,
                   GROUP_CONCAT(DISTINCT mti.player_name SEPARATOR ', ') AS itens
            FROM multi_trades mt
            LEFT JOIN multi_trade_items mti ON mti.trade_id = mt.id AND mti.player_name IS NOT NULL" .
            ($league ? " JOIN teams tl ON tl.id = mt.created_by_team_id" : "") . "
            WHERE mt.status = 'accepted'" . ($league ? " AND tl.league = ?" : "") . ($before ? " AND mt.updated_at < ?" : "") . "
            GROUP BY mt.id
            ORDER BY data_evt DESC LIMIT {$limitSql}
        ";
        $params = array_values(array_filter([$league, $before], fn($v) => $v !== null));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $eventos[] = ['tipo' => 'trade', 'icone' => '🔄', 'texto' => 'Trade multi-time concluída' . ($r['itens'] ? ': ' . $r['itens'] : ''), 'data' => $r['data_evt'], 'team_id' => (int)$r['team_id']];
        }
    }

    // Punições
    if (!$global) {
        $sql = "SELECT id, team_id, motive, punishment_label, type, created_at, reverted_at FROM team_punishments WHERE team_id = ?" . ($before ? " AND created_at < ?" : "") . " ORDER BY created_at DESC LIMIT {$limitSql}";
        $params = $before ? [$teamId, $before] : [$teamId];
    } else {
        $sql = "SELECT tp.id, tp.team_id, tp.motive, tp.punishment_label, tp.type, tp.created_at, tp.reverted_at
                FROM team_punishments tp" . ($league ? " JOIN teams tl ON tl.id = tp.team_id" : "") . "
                WHERE 1=1" . ($league ? " AND tl.league = ?" : "") . ($before ? " AND tp.created_at < ?" : "") . "
                ORDER BY tp.created_at DESC LIMIT {$limitSql}";
        $params = array_values(array_filter([$league, $before], fn($v) => $v !== null));
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $label = $r['punishment_label'] ?: ($r['motive'] ?: $r['type']);
        $eventos[] = ['tipo' => 'punicao', 'icone' => '⚠️', 'texto' => 'Punição: ' . $label, 'data' => $r['created_at'], 'team_id' => (int)$r['team_id']];
    }

    // Prêmios da temporada
    if (!$global) {
        $sql = "SELECT id, team_id, award_type, player_name, created_at FROM season_awards WHERE team_id = ?" . ($before ? " AND created_at < ?" : "") . " ORDER BY created_at DESC LIMIT {$limitSql}";
        $params = $before ? [$teamId, $before] : [$teamId];
    } else {
        $sql = "SELECT sa.id, sa.team_id, sa.award_type, sa.player_name, sa.created_at
                FROM season_awards sa" . ($league ? " JOIN teams tl ON tl.id = sa.team_id" : "") . "
                WHERE sa.team_id IS NOT NULL" . ($league ? " AND tl.league = ?" : "") . ($before ? " AND sa.created_at < ?" : "") . "
                ORDER BY sa.created_at DESC LIMIT {$limitSql}";
        $params = array_values(array_filter([$league, $before], fn($v) => $v !== null));
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $eventos[] = ['tipo' => 'premio', 'icone' => '🏆', 'texto' => $r['player_name'] . ' ganhou ' . $r['award_type'], 'data' => $r['created_at'], 'team_id' => (int)$r['team_id']];
    }

    // Playoffs (título/vice/eliminação) — não usar hall_of_fame aqui, que é só
    // um contador agregado e duplicaria os mesmos títulos.
    $labels = [
        'champion' => '🏆 Conquistou o título da temporada!',
        'runner_up' => '🥈 Foi vice-campeão da temporada.',
        'conference_final' => 'Chegou à final de conferência.',
        'second_round' => 'Chegou à segunda rodada dos playoffs.',
        'first_round' => 'Se classificou pros playoffs.',
    ];
    if (!$global) {
        $sql = "SELECT id, team_id, position, created_at FROM playoff_results WHERE team_id = ?" . ($before ? " AND created_at < ?" : "") . " ORDER BY created_at DESC LIMIT {$limitSql}";
        $params = $before ? [$teamId, $before] : [$teamId];
    } else {
        $sql = "SELECT pr.id, pr.team_id, pr.position, pr.created_at
                FROM playoff_results pr" . ($league ? " JOIN teams tl ON tl.id = pr.team_id" : "") . "
                WHERE 1=1" . ($league ? " AND tl.league = ?" : "") . ($before ? " AND pr.created_at < ?" : "") . "
                ORDER BY pr.created_at DESC LIMIT {$limitSql}";
        $params = array_values(array_filter([$league, $before], fn($v) => $v !== null));
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $eventos[] = ['tipo' => 'playoff', 'icone' => '🏀', 'texto' => $labels[$r['position']] ?? $r['position'], 'data' => $r['created_at'], 'team_id' => (int)$r['team_id']];
    }

    // Picks de draft (draft de temporada + draft inicial), nome via lookup em lote
    foreach ([['draft_order', 'draft_pool'], ['initdraft_order', 'initdraft_pool']] as [$tabOrder, $tabPool]) {
        if (!$global) {
            $sql = "SELECT id, team_id, round, pick_position, picked_player_id, picked_at FROM {$tabOrder} WHERE team_id = ? AND picked_player_id IS NOT NULL" . ($before ? " AND picked_at < ?" : "") . " ORDER BY picked_at DESC LIMIT {$limitSql}";
            $params = $before ? [$teamId, $before] : [$teamId];
        } else {
            $sql = "SELECT o.id, o.team_id, o.round, o.pick_position, o.picked_player_id, o.picked_at
                    FROM {$tabOrder} o" . ($league ? " JOIN teams tl ON tl.id = o.team_id" : "") . "
                    WHERE o.picked_player_id IS NOT NULL" . ($league ? " AND tl.league = ?" : "") . ($before ? " AND o.picked_at < ?" : "") . "
                    ORDER BY o.picked_at DESC LIMIT {$limitSql}";
            $params = array_values(array_filter([$league, $before], fn($v) => $v !== null));
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $picks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$picks) continue;

        $poolIds = array_map(fn($p) => (int)$p['picked_player_id'], $picks);
        $ph = implode(',', array_fill(0, count($poolIds), '?'));
        $stmtP = $pdo->prepare("SELECT id, name, position FROM {$tabPool} WHERE id IN ($ph)");
        $stmtP->execute($poolIds);
        $poolMap = [];
        foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $p) $poolMap[(int)$p['id']] = $p;

        foreach ($picks as $r) {
            $jogador = $poolMap[(int)$r['picked_player_id']] ?? null;
            $nome = $jogador ? $jogador['name'] . ' (' . $jogador['position'] . ')' : 'jogador';
            $eventos[] = ['tipo' => 'draft', 'icone' => '🎓', 'texto' => "Selecionou {$nome} — Rodada {$r['round']}, Pick {$r['pick_position']}", 'data' => $r['picked_at'], 'team_id' => (int)$r['team_id']];
        }
    }

    // strtotime(null) dispara aviso "Passing null..." no PHP 8.1+ (e alguns
    // eventos antigos podem ter data nula, ex. picked_at não preenchido) —
    // usa uma data bem antiga como fallback pra não travar a ordenação nem
    // vazar aviso de PHP na resposta JSON.
    usort($eventos, fn($a, $b) => strtotime((string)($b['data'] ?? '1970-01-01')) <=> strtotime((string)($a['data'] ?? '1970-01-01')));

    // Cada fonte já trouxe até $limit linhas mais recentes (e mais antigas que
    // $before quando presente) — a junção pode passar de $limit, então corta
    // aqui; a próxima página usa a data do último item devolvido como "before".
    $eventos = array_slice($eventos, 0, $limit);

    if ($global && $eventos) {
        $teamIds = array_values(array_unique(array_column($eventos, 'team_id')));
        $ph = implode(',', array_fill(0, count($teamIds), '?'));
        $stmtT = $pdo->prepare("SELECT id, city, name, photo_url, league FROM teams WHERE id IN ($ph)");
        $stmtT->execute($teamIds);
        $teamMap = [];
        foreach ($stmtT->fetchAll(PDO::FETCH_ASSOC) as $t) $teamMap[(int)$t['id']] = $t;
        foreach ($eventos as &$ev) {
            $t = $teamMap[$ev['team_id']] ?? null;
            $ev['team_name'] = $t ? trim($t['city'] . ' ' . $t['name']) : null;
            $ev['team_photo'] = $t['photo_url'] ?? null;
            $ev['team_league'] = $t['league'] ?? null;
        }
        unset($ev);
    }

    return $eventos;
}

/**
 * Posts manuais. $teamId nulo = modo global (todos os times, filtro
 * opcional de $league) — sempre traz identidade do time (útil no modo
 * global; ignorada pelas telas já existentes que são team-scoped).
 */
function getTeamPosts(PDO $pdo, ?int $teamId, int $userId, int $limit = 20, ?string $before = null, ?string $league = null): array
{
    $sql = "SELECT tp.id, tp.team_id, tp.author_user_id, tp.texto, tp.photo_url, tp.created_at,
                   u.name AS author_name, u.photo_url AS author_photo,
                   tm.city AS team_city, tm.name AS team_name, tm.photo_url AS team_photo, tm.league AS team_league,
                   (SELECT COUNT(*) FROM team_post_likes WHERE post_id = tp.id) AS like_count,
                   EXISTS(SELECT 1 FROM team_post_likes WHERE post_id = tp.id AND user_id = ?) AS liked_by_me
            FROM team_posts tp
            JOIN users u ON u.id = tp.author_user_id
            JOIN teams tm ON tm.id = tp.team_id
            WHERE tp.deleted_at IS NULL";
    $params = [$userId];
    if ($teamId !== null) { $sql .= " AND tp.team_id = ?"; $params[] = $teamId; }
    if ($league) { $sql .= " AND tm.league = ?"; $params[] = $league; }
    if ($before) { $sql .= " AND tp.created_at < ?"; $params[] = $before; }
    // MariaDB rejeita LIMIT vinculado como parâmetro de prepared statement
    // (chega como string) — $limit já é int pela assinatura da função.
    $sql .= " ORDER BY tp.created_at DESC LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map(function ($r) {
        $r['like_count'] = (int)$r['like_count'];
        $r['liked_by_me'] = (bool)$r['liked_by_me'];
        $r['team_name'] = trim($r['team_city'] . ' ' . $r['team_name']);
        unset($r['team_city']);
        return $r;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getActiveStories(PDO $pdo, int $teamId, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT ts.id, ts.team_id, ts.author_user_id, ts.photo_url, ts.texto, ts.created_at, ts.expira_em,
               EXISTS(SELECT 1 FROM team_story_views WHERE story_id = ts.id AND user_id = ?) AS vista_por_mim
        FROM team_stories ts
        WHERE ts.team_id = ? AND ts.expira_em > NOW() AND ts.deleted_at IS NULL
        ORDER BY ts.created_at ASC
    ");
    $stmt->execute([$userId, $teamId]);
    return array_map(function ($r) {
        $r['vista_por_mim'] = (bool)$r['vista_por_mim'];
        return $r;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Stories ativas de TODOS os times (Timeline global) — uma entrada por time
 * com pelo menos 1 story no ar, cada uma trazendo a lista de stories daquele
 * time (o visualizador cicla por elas). Times com story não vista aparecem
 * primeiro, depois por mais recente — igual o padrão de qualquer rede social.
 */
function getActiveStoriesGlobal(PDO $pdo, int $userId, ?string $league = null, int $limit = 30): array
{
    $sql = "
        SELECT ts.id, ts.team_id, ts.photo_url, ts.texto, ts.created_at,
               EXISTS(SELECT 1 FROM team_story_views WHERE story_id = ts.id AND user_id = ?) AS vista_por_mim,
               tm.city AS team_city, tm.name AS team_name, tm.photo_url AS team_photo, tm.league AS team_league
        FROM team_stories ts
        JOIN teams tm ON tm.id = ts.team_id
        WHERE ts.expira_em > NOW() AND ts.deleted_at IS NULL" . ($league ? " AND tm.league = ?" : "") . "
        ORDER BY ts.team_id, ts.created_at ASC
    ";
    $params = $league ? [$userId, $league] : [$userId];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $porTime = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tid = (int)$r['team_id'];
        if (!isset($porTime[$tid])) {
            $porTime[$tid] = [
                'team_id' => $tid,
                'team_name' => trim($r['team_city'] . ' ' . $r['team_name']),
                'team_photo' => $r['team_photo'],
                'team_league' => $r['team_league'],
                'tem_nao_vista' => false,
                'ultima_em' => $r['created_at'],
                'stories' => [],
            ];
        }
        $vista = (bool)$r['vista_por_mim'];
        if (!$vista) $porTime[$tid]['tem_nao_vista'] = true;
        $porTime[$tid]['ultima_em'] = $r['created_at'];
        $porTime[$tid]['stories'][] = [
            'id' => (int)$r['id'],
            'photo_url' => $r['photo_url'],
            'texto' => $r['texto'],
            'created_at' => $r['created_at'],
            'vista_por_mim' => $vista,
        ];
    }

    $grupos = array_values($porTime);
    usort($grupos, function ($a, $b) {
        if ($a['tem_nao_vista'] !== $b['tem_nao_vista']) return $a['tem_nao_vista'] ? -1 : 1;
        return strtotime((string)$b['ultima_em']) <=> strtotime((string)$a['ultima_em']);
    });
    return array_slice($grupos, 0, $limit);
}

/** Decodifica uma foto em data-URL, valida (tamanho + MIME real) e salva em $subdir. Devolve o caminho público ou null. */
function salvarFotoFeed(string $dataUrl, string $subdir, int $teamId): ?string
{
    if (!str_starts_with($dataUrl, 'data:image/')) return null;
    $commaPos = strpos($dataUrl, ',');
    if ($commaPos === false) jsonResponse(422, ['error' => 'Imagem inválida.']);
    $base64 = substr($dataUrl, $commaPos + 1);
    $binary = base64_decode($base64, true);
    if ($binary === false) jsonResponse(422, ['error' => 'Imagem inválida.']);
    if (strlen($binary) > 5 * 1024 * 1024) jsonResponse(422, ['error' => 'Imagem maior que 5MB.']);

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->buffer($binary);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$realMime])) jsonResponse(422, ['error' => 'Formato de imagem não suportado.']);
    $ext = $allowed[$realMime];

    $dirFs = __DIR__ . '/../img/' . $subdir;
    if (!is_dir($dirFs)) { @mkdir($dirFs, 0775, true); }
    $filename = $subdir . '-' . $teamId . '-' . time() . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (file_put_contents($dirFs . '/' . $filename, $binary) === false) {
        jsonResponse(500, ['error' => 'Falha ao salvar a imagem.']);
    }
    return '/img/' . $subdir . '/' . $filename;
}
