<?php
/**
 * A GRADE FIXA DAS LIVES, do calendário oficial da FBA.
 *
 * Cria as lives da semana como evento REPETIDO — uma linha por horário, com
 * repete='semanal', e não 52 linhas por ano. Mudar o horário de uma delas é
 * uma edição no calendário, não cinquenta, e é a mesma regra que o resto do
 * calendário já usa.
 *
 * É idempotente: reconhece a live pelo trio (liga, dia da semana, hora) e
 * não cria de novo o que já existe. Rodar duas vezes não duplica nada.
 *
 * Uso: php backend/semear_lives.php           (só relata)
 *      php backend/semear_lives.php --gravar  (cria)
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/calendario.php';

/**
 * A grade: [liga, dia da semana (0=dom), hora, título, observação].
 *
 * O sábado da ROOKIE tem dois horários possíveis no calendário oficial
 * ("11h OU 14h"). Fica marcado às 11h com a observação, porque marcar os
 * dois criaria duas lives por sábado — e escalar gente pras duas quando só
 * uma acontece é pior que o horário aparecer e ser ajustado na semana.
 */
function livesDaGrade(): array
{
    return [
        ['NEXT',   1, '19:30', 'Regular NEXT',    null],
        ['NEXT',   2, '19:30', 'Playoffs NEXT',   null],
        ['ELITE',  3, '19:00', 'Regular ELITE',   null],
        ['ELITE',  4, '19:00', 'Regular ELITE',   null],
        ['RISE',   5, '14:00', 'Regular RISE',    null],
        ['RISE',   5, '19:00', 'Playoffs RISE',   null],
        ['ROOKIE', 6, '11:00', 'ROOKIE',          'Pode ser às 11h ou às 14h — confirmar na semana.'],
    ];
}

/**
 * Quando cada liga começa a ter live.
 *
 * A ELITE já joga na semana do dia 24; as outras três só a partir do dia 1º
 * de setembro. Sem isso todas nasceriam na mesma semana, e o calendário
 * mostraria live de NEXT numa semana em que a NEXT não joga — o que faria a
 * escala pedir gente pra uma live que não existe.
 *
 * A data é o INÍCIO da série. A repetição semanal segue dali pra frente, e
 * o dia da semana de cada uma manda: a primeira ocorrência é o primeiro dia
 * certo IGUAL OU DEPOIS desta data.
 */
function inicioDaLiga(string $liga): string
{
    return match (strtoupper($liga)) {
        'ELITE' => '2026-08-24',
        default => '2026-09-01',
    };
}

$gravar = in_array('--gravar', $argv ?? [], true);
$pdo = db();
ensureCalendarioTables($pdo);

$tz = new DateTimeZone('America/Sao_Paulo');

/**
 * A primeira ocorrência: o primeiro $dia da semana IGUAL OU DEPOIS do
 * início da liga.
 *
 * Não é "o próximo a partir de hoje": a ELITE começa no dia 24 e a quarta
 * dessa semana é dia 26 — usar hoje empurraria pra semana seguinte e a
 * primeira live sumiria do calendário.
 */
$primeira = function (string $liga, int $dia) use ($tz): string {
    $d = new DateTimeImmutable(inicioDaLiga($liga), $tz);
    $passos = ($dia - (int)$d->format('w') + 7) % 7;
    return $d->modify("+{$passos} days")->format('Y-m-d');
};

$existe = $pdo->prepare("SELECT id, titulo, inicio FROM calendario_eventos
                          WHERE league = ? AND tipo = 'live' AND repete = 'semanal'
                            AND DAYOFWEEK(inicio) = ? AND TIME(inicio) = ?");
$criar = $pdo->prepare("INSERT INTO calendario_eventos
                        (league, tipo, titulo, inicio, descricao, repete, criado_por)
                        VALUES (?, 'live', ?, ?, ?, 'semanal', NULL)");

$novos = 0; $tinha = 0;

foreach (livesDaGrade() as [$liga, $dia, $hora, $titulo, $obs]) {
    // DAYOFWEEK do MySQL é 1=domingo; o nosso $dia é 0=domingo.
    $existe->execute([$liga, $dia + 1, $hora . ':00']);
    $ja = $existe->fetch(PDO::FETCH_ASSOC);

    $quando = $primeira($liga, $dia) . ' ' . $hora . ':00';
    $rot = str_pad($liga, 7) . ' ' . ['dom','seg','ter','qua','qui','sex','sáb'][$dia] . ' ' . $hora
         . '  ' . str_pad($titulo, 15) . ' a partir de ' . date('d/m', strtotime($quando));

    if ($ja) { $tinha++; echo "  ja existe  $rot  (#{$ja['id']})\n"; continue; }
    $novos++;
    echo "  CRIAR      $rot\n";
    if ($gravar) $criar->execute([$liga, $titulo, $quando, $obs]);
}

echo "\nja existiam: $tinha   " . ($gravar ? "criados: $novos" : "criaria: $novos") . "\n";
echo $gravar ? ">>> GRAVADO\n" : "(simulação — use --gravar)\n";
