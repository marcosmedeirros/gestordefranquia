<?php
/**
 * Recálculo automático do CAP de liga (soma de OVR top-8, ou folha salarial
 * pra ligas em modo 'salary' como a ELITE hoje).
 *
 * Regra: a cada 2 temporadas (temporadas 1, 3, 5, ...), assim que o último
 * time da liga registra o elenco da temporada (player_season_log), o sistema
 * soma o CAP de todos os times, tira a média, e define:
 *   cap_max = média + margem
 *   cap_min = média - margem
 * Ver backend/checklist_temporada.php (mesma query de "times atualizados")
 * e backend/salary_cap.php (payroll do modo salário).
 *
 * Best-effort: qualquer falha aqui é silenciosa e nunca derruba o fluxo de
 * quem chamou (ex: salvar o snapshot do elenco).
 */

require_once __DIR__ . '/helpers.php';

const LEAGUE_CAP_DEFAULT_OVR_MARGIN     = 18; // pontos de OVR pra cima/baixo da média (faixa pedida: 15 a 20)
const LEAGUE_CAP_DEFAULT_SALARY_MARGIN  = 12; // % pra cima/baixo da folha média (ELITE, modo salário)

function ensureLeagueCapAutoTables(PDO $pdo): void
{
    try {
        if ($pdo->query("SHOW COLUMNS FROM league_settings LIKE 'cap_auto_last_season'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE league_settings ADD COLUMN cap_auto_last_season INT NULL");
        }
        if ($pdo->query("SHOW COLUMNS FROM league_settings LIKE 'cap_auto_margin'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE league_settings ADD COLUMN cap_auto_margin INT NOT NULL DEFAULT " . LEAGUE_CAP_DEFAULT_OVR_MARGIN);
        }
        if ($pdo->query("SHOW COLUMNS FROM league_settings LIKE 'cap_auto_margin_pct'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE league_settings ADD COLUMN cap_auto_margin_pct INT NOT NULL DEFAULT " . LEAGUE_CAP_DEFAULT_SALARY_MARGIN);
        }
    } catch (Throwable $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS league_cap_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            league VARCHAR(20) NOT NULL,
            season_number INT NOT NULL,
            cap_mode VARCHAR(10) NOT NULL,
            avg_value INT NOT NULL,
            margin INT NOT NULL,
            cap_min INT NOT NULL,
            cap_max INT NOT NULL,
            teams_total INT NOT NULL,
            teams_above INT NOT NULL,
            teams_below INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
}

/**
 * Quantos times da liga já registraram o elenco (player_season_log) nesta
 * temporada — mesma query usada em backend/checklist_temporada.php pro item
 * "Times atualizados".
 */
function leagueRosterUpdateStatus(PDO $pdo, string $league, int $seasonId): array
{
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE league = ?");
    $stmtTotal->execute([$league]);
    $total = (int)$stmtTotal->fetchColumn();

    $stmtDone = $pdo->prepare("
        SELECT COUNT(DISTINCT psl.team_id)
        FROM player_season_log psl
        JOIN teams t ON t.id = psl.team_id
        WHERE psl.season_id = ? AND t.league = ?
    ");
    $stmtDone->execute([$seasonId, $league]);
    $done = (int)$stmtDone->fetchColumn();

    return ['total' => $total, 'done' => $done, 'complete' => $total > 0 && $done >= $total];
}

/**
 * Calcula e grava o novo cap_min/cap_max da liga com base na média do CAP
 * de todos os times (soma de OVR top-8, ou folha salarial em modo 'salary').
 * Sempre roda de fato (não checa "é hora de recalcular" — isso é feito por
 * quem chama, ver maybeAutoRecalcularCapDaLiga). Retorna o resumo do
 * cálculo, ou null se não havia times/dados suficientes.
 */
function recalcularCapDaLiga(PDO $pdo, string $league, int $seasonNumber): ?array
{
    ensureLeagueCapAutoTables($pdo);

    $stmtCfg = $pdo->prepare("SELECT cap_mode, cap_auto_margin, cap_auto_margin_pct FROM league_settings WHERE league = ?");
    $stmtCfg->execute([$league]);
    $cfg = $stmtCfg->fetch(PDO::FETCH_ASSOC) ?: [];
    $capMode = $cfg['cap_mode'] ?? 'ovr_sum';
    $ovrMargin = (int)($cfg['cap_auto_margin'] ?? LEAGUE_CAP_DEFAULT_OVR_MARGIN);
    $salaryMarginPct = (int)($cfg['cap_auto_margin_pct'] ?? LEAGUE_CAP_DEFAULT_SALARY_MARGIN);

    $stmtTeams = $pdo->prepare("SELECT id FROM teams WHERE league = ?");
    $stmtTeams->execute([$league]);
    $teamIds = array_map('intval', $stmtTeams->fetchAll(PDO::FETCH_COLUMN));
    if (!$teamIds) return null;

    $values = [];
    if ($capMode === 'salary') {
        require_once __DIR__ . '/salary_cap.php';
        foreach ($teamIds as $tid) {
            $summary = getTeamCapSummary($pdo, $tid);
            if ($summary) $values[$tid] = (int)($summary['payroll'] ?? 0);
        }
    } else {
        foreach ($teamIds as $tid) {
            $values[$tid] = topEightCap($pdo, $tid);
        }
    }
    if (!$values) return null;

    $avg = array_sum($values) / count($values);
    if ($capMode === 'salary') {
        $margin = (int)round($avg * ($salaryMarginPct / 100));
        $marginRecord = $salaryMarginPct; // guarda o % usado, pra ficar claro no histórico
    } else {
        $margin = $ovrMargin;
        $marginRecord = $ovrMargin;
    }

    $newMax = (int)round($avg) + $margin;
    $newMin = max(0, (int)round($avg) - $margin);

    $acima = 0; $abaixo = 0;
    foreach ($values as $v) {
        if ($v > $newMax) $acima++;
        elseif ($v < $newMin) $abaixo++;
    }

    $pdo->prepare("UPDATE league_settings SET cap_min = ?, cap_max = ?, cap_auto_last_season = ? WHERE league = ?")
        ->execute([$newMin, $newMax, $seasonNumber, $league]);

    $pdo->prepare("INSERT INTO league_cap_history
        (league, season_number, cap_mode, avg_value, margin, cap_min, cap_max, teams_total, teams_above, teams_below)
        VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$league, $seasonNumber, $capMode, (int)round($avg), $marginRecord, $newMin, $newMax, count($values), $acima, $abaixo]);

    $resumo = [
        'league' => $league, 'season_number' => $seasonNumber, 'cap_mode' => $capMode,
        'avg' => (int)round($avg), 'margin' => $marginRecord, 'cap_min' => $newMin, 'cap_max' => $newMax,
        'teams_total' => count($values), 'teams_above' => $acima, 'teams_below' => $abaixo,
    ];
    notificarRecalculoCapDaLiga($pdo, $resumo);

    return $resumo;
}

/** Avisa por push todo mundo da liga que o CAP mudou. Best-effort. */
function notificarRecalculoCapDaLiga(PDO $pdo, array $resumo): void
{
    $pushFile = __DIR__ . '/push.php';
    if (!file_exists($pushFile)) return;
    require_once $pushFile;

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE league = ?");
        $stmt->execute([$resumo['league']]);
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log('notificarRecalculoCapDaLiga (usuários): ' . $e->getMessage());
        return;
    }

    $unidade = $resumo['cap_mode'] === 'salary' ? 'M' : '';
    $payload = [
        'title' => "📊 Novo CAP da {$resumo['league']}",
        'body'  => "Temporada {$resumo['season_number']}: CAP agora é {$resumo['cap_min']}{$unidade}–{$resumo['cap_max']}{$unidade} (média {$resumo['avg']}{$unidade} entre os times).",
        'url'   => '/dashboard.php',
    ];
    foreach ($userIds as $uid) {
        try {
            sendPushToUser($pdo, (int)$uid, $payload);
        } catch (Throwable $e) {
            error_log('notificarRecalculoCapDaLiga (push user_id=' . $uid . '): ' . $e->getMessage());
        }
    }
}

/**
 * Ponto de entrada chamado depois de qualquer "salvar elenco da temporada".
 * Só recalcula quando: a temporada é ímpar (1, 3, 5... — o "a cada 2
 * temporadas"), ainda não recalculou pra essa temporada, e TODOS os times da
 * liga já registraram o elenco. Nunca lança exceção pro chamador.
 */
function maybeAutoRecalcularCapDaLiga(PDO $pdo, string $league, int $seasonId, int $seasonNumber): ?array
{
    try {
        if ($seasonNumber < 1 || $seasonNumber % 2 === 0) return null; // só em temporadas ímpares

        ensureLeagueCapAutoTables($pdo);
        $stmt = $pdo->prepare("SELECT cap_auto_last_season FROM league_settings WHERE league = ?");
        $stmt->execute([$league]);
        $lastSeason = $stmt->fetchColumn();
        if ($lastSeason !== false && $lastSeason !== null && (int)$lastSeason >= $seasonNumber) return null;

        $status = leagueRosterUpdateStatus($pdo, $league, $seasonId);
        if (!$status['complete']) return null;

        return recalcularCapDaLiga($pdo, $league, $seasonNumber);
    } catch (Throwable $e) {
        error_log('maybeAutoRecalcularCapDaLiga: ' . $e->getMessage());
        return null;
    }
}
