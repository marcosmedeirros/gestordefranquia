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
 * ── Por que existe um arquivo pra isso ───────────────────────────────────
 *
 * Evento é DADO, não código. O deploy leva o código pra hospedagem, mas as
 * sete lives são linhas no banco — e o banco de lá não vem junto. Sem isto,
 * a produção ficaria com o calendário vazio de lives e a escala não teria
 * em que se pendurar.
 *
 * A lógica está separada da linha de comando de propósito: o botão do admin
 * (escalalive.php) chama a MESMA função, então os dois caminhos não podem
 * divergir com o tempo.
 *
 * Uso: php backend/semear_lives.php           (só relata)
 *      php backend/semear_lives.php --gravar  (cria)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/calendario.php';

/**
 * A grade: [liga, dia da semana (0=dom), hora, título, observação, início].
 *
 * O sábado da ROOKIE tem dois horários possíveis no calendário oficial
 * ("11h OU 14h"). Fica marcado às 11h com a observação, porque marcar os
 * dois criaria duas lives por sábado — e escalar gente pras duas quando só
 * uma acontece é pior que o horário aparecer e ser ajustado na semana.
 *
 * O último campo é a data em que AQUELA live começa; null usa a da liga.
 * A Regular NEXT precisou dele: a NEXT começa em 01/09, uma terça, e a
 * primeira segunda depois disso é 07/09 — a live do dia 31/08 ficava de
 * fora. Um início por liga não dava conta de uma liga que estreia no meio
 * da semana.
 */
function livesDaGrade(): array
{
    return [
        ['NEXT',   1, '19:30', 'Regular NEXT',    null, '2026-08-31'],
        ['NEXT',   2, '19:30', 'Playoffs NEXT',   null, null],
        ['ELITE',  3, '19:00', 'Regular ELITE',   null, null],
        ['ELITE',  4, '19:00', 'Playoffs ELITE',  null, null],
        ['RISE',   5, '14:00', 'Regular RISE',    null, null],
        ['RISE',   5, '19:00', 'Playoffs RISE',   null, null],
        ['ROOKIE', 6, '11:00', 'ROOKIE',          'Pode ser às 11h ou às 14h — confirmar na semana.', null],
    ];
}

/**
 * Quando cada liga começa a ter live.
 *
 * A ELITE já joga na semana do dia 24; as outras três só a partir do dia 1º
 * de setembro. Sem isso todas nasceriam na mesma semana, e o calendário
 * mostraria live de NEXT numa semana em que a NEXT não joga — o que faria a
 * escala pedir gente pra uma live que não existe.
 */
function inicioDaLiga(string $liga): string
{
    return match (strtoupper($liga)) {
        'ELITE' => '2026-08-24',
        default => '2026-09-01',
    };
}

/**
 * Cria o que falta. Devolve o relatório, e não imprime nada — quem imprime
 * é quem chamou, que pode ser a linha de comando ou uma tela.
 *
 * @return array{criados:string[], existiam:string[]}
 */
function semearLives(PDO $pdo, bool $gravar): array
{
    ensureCalendarioTables($pdo);
    $tz = new DateTimeZone('America/Sao_Paulo');

    // A primeira ocorrência é o primeiro dia certo IGUAL OU DEPOIS do início
    // da liga. Não é "o próximo a partir de hoje": a ELITE começa no dia 24 e
    // a quarta dessa semana é 26 — usar hoje empurraria pra semana seguinte e
    // a primeira live sumiria do calendário.
    $primeira = function (string $liga, int $dia, ?string $inicio) use ($tz): string {
        $d = new DateTimeImmutable($inicio ?: inicioDaLiga($liga), $tz);
        $passos = ($dia - (int)$d->format('w') + 7) % 7;
        return $d->modify("+{$passos} days")->format('Y-m-d');
    };

    $existe = $pdo->prepare("SELECT id, inicio, titulo FROM calendario_eventos
                              WHERE league = ? AND tipo = 'live' AND repete = 'semanal'
                                AND DAYOFWEEK(inicio) = ? AND TIME(inicio) = ?");
    // Corrigir o título de uma live que já existe. A live é reconhecida pelo
    // trio (liga, dia, hora) e não pelo nome, então trocar "Regular ELITE"
    // por "Playoffs ELITE" na grade não chegava sozinho ao banco. E o título
    // não é decoração: é dele que sai a FASE, então um nome errado põe gente
    // que só topa offs numa live de regular.
    $renomear = $pdo->prepare("UPDATE calendario_eventos SET titulo = ? WHERE id = ?");
    // Adiantar o começo de uma live que já existe. Só pra trás: a Regular
    // NEXT foi criada começando em 07/09 e o certo era 31/08, e sem isto o
    // semeador diria "já existe" pra sempre sem corrigir nada. Empurrar pra
    // FRENTE seria outra coisa — apagaria ocorrências que já aconteceram, e
    // possivelmente gente já escalada nelas.
    $adiantar = $pdo->prepare("UPDATE calendario_eventos SET inicio = ? WHERE id = ?");
    $criar = $pdo->prepare("INSERT INTO calendario_eventos
                            (league, tipo, titulo, inicio, descricao, repete, criado_por)
                            VALUES (?, 'live', ?, ?, ?, 'semanal', NULL)");

    $out = ['criados' => [], 'existiam' => [], 'ajustados' => []];
    $DIAS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

    foreach (livesDaGrade() as [$liga, $dia, $hora, $titulo, $obs, $inicio]) {
        // DAYOFWEEK do MySQL é 1=domingo; o nosso $dia é 0=domingo.
        $existe->execute([$liga, $dia + 1, $hora . ':00']);
        $quando = $primeira($liga, $dia, $inicio) . ' ' . $hora . ':00';
        $rot = $liga . ' · ' . $DIAS[$dia] . ' ' . $hora . ' · ' . $titulo
             . ' (a partir de ' . date('d/m', strtotime($quando)) . ')';

        if ($ja = $existe->fetch(PDO::FETCH_ASSOC)) {
            $mudou = [];
            if (strtotime($quando) < strtotime((string)$ja['inicio'])) {
                $mudou[] = 'começava em ' . date('d/m', strtotime((string)$ja['inicio']));
                if ($gravar) $adiantar->execute([$quando, $ja['id']]);
            }
            if (trim((string)$ja['titulo']) !== $titulo) {
                $mudou[] = 'chamava "' . $ja['titulo'] . '"';
                if ($gravar) $renomear->execute([$titulo, $ja['id']]);
            }
            if ($mudou) $out['ajustados'][] = $rot . ' — ' . implode(', ', $mudou);
            else        $out['existiam'][]  = $rot;
            continue;
        }
        $out['criados'][] = $rot;
        if ($gravar) $criar->execute([$liga, $titulo, $quando, $obs]);
    }
    return $out;
}

// ── Daqui pra baixo, só a linha de comando ──────────────────────────────
// O `return` e não `exit` porque a tela dá require neste arquivo pra usar
// as funções acima: um exit aqui mataria a página inteira.
if (PHP_SAPI !== 'cli') return;

$gravar = in_array('--gravar', $argv ?? [], true);
$r = semearLives(db(), $gravar);

foreach ($r['existiam']  as $x) echo "  ja existe  {$x}\n";
foreach ($r['ajustados'] as $x) echo '  ' . ($gravar ? 'ADIANTADO' : 'ADIANTARIA') . " {$x}\n";
foreach ($r['criados']   as $x) echo '  ' . ($gravar ? 'CRIADO   ' : 'CRIARIA  ') . " {$x}\n";
echo "\nja existiam: " . count($r['existiam'])
   . '   ' . ($gravar ? 'adiantados: ' : 'adiantaria: ') . count($r['ajustados'])
   . '   ' . ($gravar ? 'criados: ' : 'criaria: ') . count($r['criados']) . "\n";
echo $gravar ? ">>> GRAVADO\n" : "(simulação — use --gravar)\n";
