<?php
/**
 * Cron do auto-pick do Mock Draft. Roda a cada minuto.
 *
 * A regra inteira mora em backend/draft_autopick.php, compartilhada com o
 * check_autopick de api/draft-mock.php. Antes cada um tinha a sua cópia, e as
 * duas já tinham divergido no prazo de espera.
 *
 * Aqui é só o laço pelas sessões abertas: quem decide o que escolher, e se é
 * hora de escolher, é o módulo.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/draft_autopick.php';

$pdo = db();
draftAutopickColunas($pdo);

$sessoes = $pdo->query('SELECT id FROM draft_sessions WHERE status = "in_progress"');
$ids = $sessoes ? $sessoes->fetchAll(PDO::FETCH_COLUMN) : [];

$total = 0;
foreach ($ids as $id) {
    // A cascata acontece DENTRO daqui: numa rodada em que todo mundo deixou
    // lista, o draft resolve inteiro nesta passada em vez de uma pick por
    // minuto.
    $feitas = draftAutopickSessao($pdo, (int)$id);
    $total += count($feitas);
    foreach ($feitas as $f) {
        error_log(sprintf('[autopick] sessao %d · R%d pick %d · time %d · %s (%s)',
            $id, $f['round'], $f['pick'], $f['team_id'], $f['player'], $f['motivo']));
    }
}

echo date('Y-m-d H:i:s') . " — {$total} escolha(s) em " . count($ids) . " sessão(ões)\n";
