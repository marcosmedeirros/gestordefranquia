<?php
/**
 * Agendador de fases da liga. O admin cadastra eventos (tipo + liga + data/hora)
 * e o sistema executa os que venceram: fechar/abrir trades e fechar a Free
 * Agency com resolução automática das ofertas. Segue o mesmo padrão do resto
 * do projeto (sem depender de cron externo): a resolução também roda de forma
 * preguiçosa sempre que a tela do agendador é aberta.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fa_resolve.php'; // define resolveFreeAgencyForLeague / openFreeAgencyForLeague

const SCHED_TYPES = ['trades_close', 'trades_open', 'fa_close', 'fa_open'];

function ensureScheduledEventsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS scheduled_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        league VARCHAR(20) NOT NULL,
        type VARCHAR(30) NOT NULL,
        run_at DATETIME NOT NULL,
        status ENUM('pending','done','failed','canceled') NOT NULL DEFAULT 'pending',
        result VARCHAR(255) NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ran_at DATETIME NULL,
        INDEX idx_se_pending (status, run_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Liga/desliga trades da liga em league_settings. */
function schedSetTradesEnabled(PDO $pdo, string $league, int $enabled): string
{
    $st = $pdo->prepare("SELECT id FROM league_settings WHERE league = ?");
    $st->execute([$league]);
    if ($st->fetchColumn()) {
        $pdo->prepare("UPDATE league_settings SET trades_enabled = ? WHERE league = ?")->execute([$enabled, $league]);
    } else {
        $pdo->prepare("INSERT INTO league_settings (league, trades_enabled) VALUES (?, ?)")->execute([$league, $enabled]);
    }
    return $enabled ? 'Trades reabertas' : 'Trades fechadas';
}

/** Executa os eventos pendentes já vencidos. Retorna resumo. */
function runDueScheduledEvents(PDO $pdo): array
{
    ensureScheduledEventsTable($pdo);
    $out = ['ran' => 0, 'done' => 0, 'failed' => 0, 'events' => []];
    $rows = $pdo->query("SELECT * FROM scheduled_events WHERE status = 'pending' AND run_at <= NOW() ORDER BY run_at ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $ev) {
        $id = (int)$ev['id'];
        $league = strtoupper($ev['league']);
        $type = $ev['type'];
        $out['ran']++;
        try {
            switch ($type) {
                case 'trades_close': $result = schedSetTradesEnabled($pdo, $league, 0); break;
                case 'trades_open':  $result = schedSetTradesEnabled($pdo, $league, 1); break;
                case 'fa_close':
                    $r = resolveFreeAgencyForLeague($pdo, $league);
                    $result = 'FA fechada — ' . (int)($r['assigned'] ?? 0) . ' contratados, ' . (int)($r['no_winner'] ?? 0) . ' sem vencedor';
                    break;
                case 'fa_open':
                    openFreeAgencyForLeague($pdo, $league);
                    $result = 'FA reaberta';
                    break;
                default: throw new Exception('Tipo desconhecido: ' . $type);
            }
            $pdo->prepare("UPDATE scheduled_events SET status='done', result=?, ran_at=NOW() WHERE id=?")
                ->execute([mb_substr($result, 0, 255), $id]);
            $out['done']++;
            $out['events'][] = ['id' => $id, 'type' => $type, 'league' => $league, 'result' => $result];
        } catch (Exception $e) {
            $pdo->prepare("UPDATE scheduled_events SET status='failed', result=?, ran_at=NOW() WHERE id=?")
                ->execute([mb_substr($e->getMessage(), 0, 255), $id]);
            $out['failed']++;
            $out['events'][] = ['id' => $id, 'type' => $type, 'league' => $league, 'error' => $e->getMessage()];
        }
    }
    return $out;
}
