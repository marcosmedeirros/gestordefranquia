<?php
/**
 * Sistema de Waiver (12h) — exclusivo da liga ELITE (slide 21 do FBA Elite 15).
 *
 * Fluxo: jogador dispensado da ELITE fica isolado no waiver por 12h. Durante a
 * janela, outros times podem reivindicar. Ao vencer:
 *   - com reivindicações → vai pro time com MAIOR espaço no cap (desempate:
 *     quem reivindicou primeiro);
 *   - sem reivindicações → cai no free agency.
 * NEXT/RISE não usam waiver: a dispensa cai direto no free agency (fluxo antigo).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/salary_cap.php';
require_once __DIR__ . '/push.php';

const WAIVER_HOURS = 12;

function ensureWaiverTables(PDO $pdo): void
{
    // Todo DDL — inclusive CREATE TABLE IF NOT EXISTS numa tabela que já existe
    // — faz COMMIT implícito no MySQL. Chamada de dentro de uma transação, esta
    // função encerrava a transação e o commit() seguinte morria com "There is
    // no active transaction" (era o que derrubava a dispensa de jogador).
    // Roda uma vez por request e nunca com transação aberta.
    static $pronto = false;
    if ($pronto || $pdo->inTransaction()) {
        return;
    }
    $pronto = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS waiver_retention (
        id INT AUTO_INCREMENT PRIMARY KEY,
        player_id INT NULL,
        team_id INT NOT NULL,
        league VARCHAR(20) NOT NULL,
        name VARCHAR(120) NOT NULL,
        age INT NULL,
        position VARCHAR(20) NULL,
        secondary_position VARCHAR(20) NULL,
        ovr INT NOT NULL DEFAULT 0,
        seasons_in_league INT NOT NULL DEFAULT 0,
        drafted_by_team_id INT NULL,
        draft_round INT NULL,
        draft_pick_position INT NULL,
        role VARCHAR(30) NULL,
        waived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        status ENUM('open','claimed','cleared') NOT NULL DEFAULT 'open',
        claimed_by_team_id INT NULL,
        resolved_at DATETIME NULL,
        INDEX idx_wr_status (status, league)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS waiver_claims (
        id INT AUTO_INCREMENT PRIMARY KEY,
        retention_id INT NOT NULL,
        team_id INT NOT NULL,
        bid_space INT NULL,
        claimed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_claim (retention_id, team_id),
        INDEX idx_wc_ret (retention_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // bid_space = espaço no cap do time no momento do lance (o "lance"). Coluna nova em bases antigas.
    // "ADD COLUMN IF NOT EXISTS" só existe em MySQL 8.0.29+/MariaDB 10.0.2+; em versões mais
    // antigas isso é um erro de sintaxe (silenciosamente ignorado pelo catch), deixando a coluna
    // faltando para sempre e quebrando (sem JSON válido) toda leitura de waiver_claims depois.
    // Por isso checamos a existência via SHOW COLUMNS (waiverColExists), que funciona em qualquer versão.
    if (!waiverColExists($pdo, 'waiver_claims', 'bid_space')) {
        try { $pdo->exec("ALTER TABLE waiver_claims ADD COLUMN bid_space INT NULL"); }
        catch (Throwable $e) { error_log('ensureWaiverTables bid_space: ' . $e->getMessage()); }
    }
}

function waiverColExists(PDO $pdo, string $table, string $col): bool
{
    try { return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch(); }
    catch (Exception $e) { return false; }
}

/** Coloca o jogador dispensado no waiver por 12h. $p = linha completa de players. Retorna o id. */
function enterWaiver(PDO $pdo, array $p, string $league): int
{
    ensureWaiverTables($pdo);
    $stmt = $pdo->prepare("INSERT INTO waiver_retention
        (player_id, team_id, league, name, age, position, secondary_position, ovr, seasons_in_league,
         drafted_by_team_id, draft_round, draft_pick_position, role, expires_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL " . WAIVER_HOURS . " HOUR))");
    $stmt->execute([
        $p['id'] ?? null, (int)$p['team_id'], $league, $p['name'], $p['age'] ?? null,
        $p['position'] ?? null, $p['secondary_position'] ?? null, (int)($p['ovr'] ?? 0),
        (int)($p['seasons_in_league'] ?? 0), $p['drafted_by_team_id'] ?? null,
        $p['draft_round'] ?? null, $p['draft_pick_position'] ?? null, $p['role'] ?? 'Titular',
    ]);
    $id = (int)$pdo->lastInsertId();
    notificarEntradaNoWaiver($pdo, $p, $league);
    return $id;
}

/** Avisa a liga que abriu um waiver — menos o time que dispensou. */
function notificarEntradaNoWaiver(PDO $pdo, array $p, string $league): void
{
    try {
        $st = $pdo->prepare("SELECT user_id FROM teams WHERE id = ? LIMIT 1");
        $st->execute([(int)$p['team_id']]);
        $donoAntigo = (int)($st->fetchColumn() ?: 0);

        $ovr  = (int)($p['ovr'] ?? 0);
        $pos  = trim((string)($p['position'] ?? ''));
        $ficha = trim(($pos !== '' ? $pos . ' · ' : '') . ($ovr ? $ovr . ' OVR' : ''));

        sendPushToLeague($pdo, $league, [
            'title' => '⏳ Jogador no Waiver',
            'body'  => trim($p['name'] . ($ficha !== '' ? " ({$ficha})" : '')) . ' está disponível por ' . WAIVER_HOURS . 'h. Dê seu lance!',
            'url'   => '/free-agency.php',
        ], 'waiver', $donoAntigo ? [$donoAntigo] : []);
    } catch (Throwable $e) {
        error_log('notificarEntradaNoWaiver: ' . $e->getMessage());
    }
}

/** Temporada ativa da liga (id/ano) para preencher o free_agents ao liberar. */
function waiverActiveSeason(PDO $pdo, string $league): array
{
    $st = $pdo->prepare("SELECT id, year FROM seasons WHERE league = ? AND (status IS NULL OR status NOT IN ('completed')) ORDER BY created_at DESC LIMIT 1");
    $st->execute([$league]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        $st2 = $pdo->prepare("SELECT id, year FROM seasons WHERE league = ? ORDER BY season_number DESC LIMIT 1");
        $st2->execute([$league]);
        $r = $st2->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    return ['id' => $r['id'] ?? null, 'year' => $r['year'] ?? null];
}

/** Manda um waiver não reivindicado para o free agency (espelha a dispensa antiga). */
function waiverToFreeAgency(PDO $pdo, array $w): void
{
    $tn = $pdo->prepare("SELECT CONCAT(city,' ',name) FROM teams WHERE id = ?");
    $tn->execute([(int)$w['team_id']]);
    $teamName = $tn->fetchColumn() ?: null;

    $cols = ['name', 'age', 'position', 'secondary_position', 'overall', 'league', 'original_team_id', 'original_team_name'];
    $vals = [$w['name'], $w['age'], $w['position'], $w['secondary_position'], (int)$w['ovr'], $w['league'], (int)$w['team_id'], $teamName];

    if (waiverColExists($pdo, 'free_agents', 'waived_at')) { $cols[] = 'waived_at'; $vals[] = date('Y-m-d H:i:s'); }
    if (waiverColExists($pdo, 'free_agents', 'season_id') || waiverColExists($pdo, 'free_agents', 'season_year')) {
        $s = waiverActiveSeason($pdo, $w['league']);
        if (waiverColExists($pdo, 'free_agents', 'season_id'))   { $cols[] = 'season_id';   $vals[] = $s['id']; }
        if (waiverColExists($pdo, 'free_agents', 'season_year')) { $cols[] = 'season_year'; $vals[] = $s['year']; }
    }
    $ph = implode(', ', array_fill(0, count($cols), '?'));
    $pdo->prepare("INSERT INTO free_agents (" . implode(', ', $cols) . ") VALUES ($ph)")->execute($vals);
}

/** Recria o jogador reivindicado no elenco do time vencedor. */
function waiverRecreatePlayer(PDO $pdo, array $w, int $teamId): void
{
    $pdo->prepare("INSERT INTO players
        (team_id, name, age, position, secondary_position, ovr, seasons_in_league,
         drafted_by_team_id, draft_round, draft_pick_position, role)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute([
        $teamId, $w['name'], $w['age'], $w['position'], $w['secondary_position'], (int)$w['ovr'],
        (int)$w['seasons_in_league'], $w['drafted_by_team_id'], $w['draft_round'], $w['draft_pick_position'],
        $w['role'] ?: 'Titular',
    ]);
}

/**
 * Avisa quem participou do waiver como ele terminou: o vencedor ganha o jogador,
 * quem perdeu fica sabendo, e sem lance nenhum a liga inteira sabe que caiu na FA.
 */
function notificarResultadoDoWaiver(PDO $pdo, array $w, array $claims, int $winner): void
{
    try {
        $nome = (string)$w['name'];

        if (!$claims) {
            sendPushToLeague($pdo, (string)$w['league'], [
                'title' => '🆓 Waiver encerrado',
                'body'  => "Ninguém reivindicou {$nome} — ele caiu no Free Agency.",
                'url'   => '/free-agency.php',
            ], 'waiver');
            return;
        }

        sendPushToTeam($pdo, $winner, [
            'title' => '✅ Waiver vencido!',
            'body'  => "{$nome} é seu. Ele já está no seu elenco.",
            'url'   => '/my-roster.php',
        ], 'waiver');

        foreach ($claims as $c) {
            $tid = (int)$c['team_id'];
            if ($tid === $winner) continue;
            sendPushToTeam($pdo, $tid, [
                'title' => '❌ Waiver perdido',
                'body'  => "{$nome} foi para outro time — o lance vencedor tinha mais espaço no cap.",
                'url'   => '/free-agency.php',
            ], 'waiver');
        }
    } catch (Throwable $e) {
        error_log('notificarResultadoDoWaiver: ' . $e->getMessage());
    }
}

/**
 * Resolve todos os waivers vencidos (status open, expires_at <= agora).
 * Chamado pelo agendador (cron), pelo admin e, como rede de segurança, ao abrir a aba.
 * Retorna ['resolved'=>, 'claimed'=>, 'cleared'=>].
 */
function resolveExpiredWaivers(PDO $pdo): array
{
    $out = ['resolved' => 0, 'claimed' => 0, 'cleared' => 0];

    try {
        ensureWaiverTables($pdo);
        $rows = $pdo->query("SELECT * FROM waiver_retention WHERE status = 'open' AND expires_at <= NOW() ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Rede de segurança: isso roda a cada acesso a /api/waivers.php (via GET), então um
        // erro aqui não pode derrubar a página inteira — o cron (api/waiver-cron.php) é quem
        // resolve de verdade; aqui é só best-effort.
        error_log('resolveExpiredWaivers select: ' . $e->getMessage());
        return $out;
    }

    foreach ($rows as $w) {
        $wid = (int)$w['id'];
        try {
            // Vencedor = maior lance (espaço no cap no momento do lance); empate = quem deu o lance primeiro.
            $cs = $pdo->prepare("SELECT team_id, bid_space, claimed_at FROM waiver_claims WHERE retention_id = ? ORDER BY bid_space DESC, claimed_at ASC, id ASC");
            $cs->execute([$wid]);
            $claims = $cs->fetchAll(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();
            $winner = 0;
            if ($claims) {
                $winner = (int)$claims[0]['team_id'];
                waiverRecreatePlayer($pdo, $w, $winner);
                $pdo->prepare("UPDATE waiver_retention SET status='claimed', claimed_by_team_id=?, resolved_at=NOW() WHERE id=?")->execute([$winner, $wid]);
                $out['claimed']++;
            } else {
                waiverToFreeAgency($pdo, $w);
                $pdo->prepare("UPDATE waiver_retention SET status='cleared', resolved_at=NOW() WHERE id=?")->execute([$wid]);
                $out['cleared']++;
            }
            $pdo->commit();
            $out['resolved']++;

            // Só depois do commit — push nunca dentro de transação.
            notificarResultadoDoWaiver($pdo, $w, $claims, $winner);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('resolveExpiredWaivers resolve #' . $wid . ': ' . $e->getMessage());
        }
    }
    return $out;
}
