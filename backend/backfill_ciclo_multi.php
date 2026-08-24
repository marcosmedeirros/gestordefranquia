<?php
/**
 * PREENCHE multi_trades.cycle, que nasceu vazio.
 *
 * A coluna existe desde sempre e nunca foi gravada: as 214 trocas múltiplas
 * do banco estão todas com NULL. Enquanto estiverem, qualquer conta que
 * filtre por ciclo simplesmente não as enxerga — foi isso que deixou o
 * contador oficial (teams.trades_used, que zera a cada ciclo) discordar da
 * estatística do bot.
 *
 * De onde sai o ciclo: da DATA. As trocas de dois times gravam o ciclo
 * certinho, e os ciclos de cada liga não se sobrepõem no tempo — a última
 * troca do ciclo 2 da ELITE é de 28/03 02:16 e a primeira do 3 é de 28/03
 * 02:26. Então o ciclo de uma múltipla é o do maior ciclo daquela liga cujo
 * início já tinha passado quando ela foi criada.
 *
 * É idempotente: só toca em linha com cycle NULL. Rodar duas vezes não muda
 * nada na segunda.
 *
 * Uso: php backend/backfill_ciclo_multi.php          (só relata)
 *      php backend/backfill_ciclo_multi.php --gravar (aplica)
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

$gravar = in_array('--gravar', $argv ?? [], true);
$pdo = db();

// O calendário dos ciclos, tirado das trocas de dois times.
$faixas = $pdo->query("
    SELECT league, cycle, MIN(created_at) AS inicio
      FROM trades
     WHERE cycle IS NOT NULL AND created_at IS NOT NULL
     GROUP BY league, cycle
     ORDER BY league, cycle
")->fetchAll(PDO::FETCH_ASSOC);

$porLiga = [];
foreach ($faixas as $f) $porLiga[$f['league']][] = ['cycle' => (int)$f['cycle'], 'inicio' => $f['inicio']];

$pendentes = $pdo->query("
    SELECT id, league, created_at FROM multi_trades WHERE cycle IS NULL ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

echo "múltiplas sem ciclo: " . count($pendentes) . "\n\n";

$up = $pdo->prepare("UPDATE multi_trades SET cycle = ? WHERE id = ? AND cycle IS NULL");
$resumo = [];
$semFaixa = 0;

foreach ($pendentes as $m) {
    $lista = $porLiga[$m['league']] ?? [];
    if (!$lista || empty($m['created_at'])) { $semFaixa++; continue; }

    // O maior ciclo cujo início já tinha passado. Múltipla anterior à
    // primeira troca da liga cai no ciclo 1 — é onde ela estava.
    $achado = $lista[0]['cycle'];
    foreach ($lista as $c) {
        if ($m['created_at'] >= $c['inicio']) $achado = $c['cycle'];
    }

    $resumo[$m['league']][$achado] = ($resumo[$m['league']][$achado] ?? 0) + 1;
    if ($gravar) $up->execute([$achado, (int)$m['id']]);
}

foreach ($resumo as $liga => $ciclos) {
    ksort($ciclos);
    echo "$liga: ";
    foreach ($ciclos as $c => $n) echo "ciclo $c → $n   ";
    echo "\n";
}
if ($semFaixa) echo "\nsem calendário na liga (ficam NULL): $semFaixa\n";

echo "\n" . ($gravar ? ">>> GRAVADO" : "(simulação — use --gravar)") . "\n";
