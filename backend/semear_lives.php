<?php
/**
 * A AGENDA FIXA DA FBA, das artes oficiais de cada liga.
 *
 * Não são só as lives: cada liga tem um ciclo semanal inteiro — jogo da
 * regular, jogo dos offs, draft, período de trocas, free agency e o bloqueio
 * do painel de diretrizes. Tudo isso vira evento repetido no calendário.
 *
 * Cria como evento REPETIDO — uma linha por horário, com repete='semanal', e
 * não 52 linhas por ano. Mudar o horário de um deles é uma edição, não
 * cinquenta, e é a mesma regra que o resto do calendário já usa.
 *
 * É idempotente: reconhece o evento pelo quarteto (liga, dia da semana, hora,
 * tipo) e não cria de novo o que já existe. Rodar duas vezes não duplica
 * nada. Além de criar, também CONSERTA o que existe mas divergiu da grade —
 * ver semearGrade().
 *
 * ── Por que existe um arquivo pra isso ───────────────────────────────────
 *
 * Evento é DADO, não código. O deploy leva o código pra hospedagem, mas a
 * agenda são linhas no banco — e o banco de lá não vem junto.
 *
 * A lógica está separada da linha de comando de propósito: o botão do admin
 * (escalalive.php) chama a MESMA função, então os dois caminhos não podem
 * divergir com o tempo.
 *
 * Uso: php backend/semear_lives.php           (só relata)
 *      php backend/semear_lives.php --gravar  (aplica)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/calendario.php';

/**
 * A agenda: [liga, dia da semana (0=dom), hora ou null, tipo, título, obs].
 *
 * Hora null é evento de dia inteiro — as artes só dão horário no que tem
 * prazo (as 22h do fim das trocas e do bloqueio) e nas lives. Chutar uma
 * hora pro resto encheria o calendário de horário inventado.
 *
 * A ordem das linhas de cada liga é a ordem do ciclo dela, começando pelo
 * primeiro dia da arte. Isso importa: é a primeira linha que ancora o ciclo
 * (ver inicioDoCiclo).
 *
 * O sábado da ROOKIE tem dois horários possíveis no calendário oficial
 * ("11h OU 14h"). Fica às 11h com a observação, porque marcar os dois criaria
 * duas lives por sábado — e escalar gente pras duas quando só uma acontece é
 * pior que o horário ser ajustado na semana.
 */
function agendaDaGrade(): array
{
    return [
        // ── ELITE: quarta a terça ──────────────────────────────────────
        ['ELITE',  3, '19:00', 'live',     'Regular ELITE',   null],
        ['ELITE',  4, '19:00', 'live',     'Playoffs ELITE',  null],
        ['ELITE',  5, null,    'draft',    'Draft + Progression', null],
        ['ELITE',  0, '22:00', 'dl_fecha', 'Fim do período de trocas', null],
        ['ELITE',  1, null,    'fa_abre',  'Free Agency',     null],
        ['ELITE',  2, '22:00', 'outro',    'Bloqueio do painel de diretrizes', null],

        // ── NEXT: segunda a domingo ────────────────────────────────────
        ['NEXT',   1, '19:30', 'live',     'Regular NEXT',    null],
        ['NEXT',   2, '19:30', 'live',     'Playoffs NEXT',   null],
        ['NEXT',   3, null,    'draft',    'Draft + Progression', null],
        ['NEXT',   5, '22:00', 'dl_fecha', 'Fim do período de trocas', null],
        ['NEXT',   6, null,    'fa_abre',  'Free Agency',     null],
        ['NEXT',   0, '22:00', 'outro',    'Bloqueio do painel de diretrizes', null],

        // ── RISE: sexta a quinta ───────────────────────────────────────
        // A arte mostra os offs no sábado, mas a grade em uso tem os dois
        // jogos na sexta (14h e 19h) — confirmado que a sexta é a certa.
        ['RISE',   5, '14:00', 'live',     'Regular RISE',    null],
        ['RISE',   5, '19:00', 'live',     'Playoffs RISE',   null],
        ['RISE',   0, null,    'draft',    'Draft + Progression', null],
        ['RISE',   2, '22:00', 'dl_fecha', 'Fim do período de trocas', null],
        ['RISE',   3, null,    'fa_abre',  'Free Agency',     null],
        ['RISE',   4, '22:00', 'outro',    'Bloqueio do painel de diretrizes', null],

        // ── ROOKIE: sábado a sexta ─────────────────────────────────────
        ['ROOKIE', 6, '11:00', 'live',     'ROOKIE',          'Regular + Offs, tudo na mesma live. Pode ser às 11h ou às 14h — confirmar na semana.'],
        ['ROOKIE', 0, null,    'draft',    'Draft + Progression', null],
        ['ROOKIE', 1, null,    'dl_abre',  'Período de trocas — dia 1', null],
        ['ROOKIE', 2, null,    'dl_abre',  'Período de trocas — dia 2', null],
        ['ROOKIE', 3, '22:00', 'dl_fecha', 'Fim do período de trocas', null],
        ['ROOKIE', 4, null,    'fa_abre',  'Free Agency',     null],
        ['ROOKIE', 5, '22:00', 'outro',    'Bloqueio do painel de diretrizes', null],
    ];
}

/**
 * As EXCEÇÕES: eventos de data marcada, que não repetem.
 *
 * A agenda semanal vale a partir do ciclo de cada liga, mas a virada de
 * temporada tem datas próprias que não se encaixam nela. Aqui elas ficam
 * como evento avulso (repete='nao'), lado a lado com o repetido — quem olha
 * o calendário vê os dois, e o repetido não precisa de exceção embutida.
 *
 * NEXT, RISE e ROOKIE fecham as trocas no sábado 29/08, e só a partir da
 * semana seguinte seguem o dia da própria agenda (sexta, terça e quarta).
 *
 * Formato: [liga, 'YYYY-MM-DD HH:MM', tipo, título, observação].
 */
function agendaAvulsa(): array
{
    $obs = 'Fechamento único desta virada — a partir da semana seguinte '
         . 'vale o dia da agenda da liga.';
    return [
        ['NEXT',   '2026-08-29 22:00', 'dl_fecha', 'Fim do período de trocas', $obs],
        ['RISE',   '2026-08-29 22:00', 'dl_fecha', 'Fim do período de trocas', $obs],
        ['ROOKIE', '2026-08-29 22:00', 'dl_fecha', 'Fim do período de trocas', $obs],
    ];
}

/**
 * O dia em que o ciclo de cada liga começa — a ÂNCORA da agenda.
 *
 * Não é uma data comum pras quatro, e não pode ser. O ciclo da RISE começa
 * na sexta; o "fim das trocas" dela é na terça, que vem DEPOIS. Ancorando
 * numa segunda comum, essa terça cairia três dias antes do primeiro jogo —
 * fechando um período de trocas que nem tinha aberto.
 *
 * Então cada liga ancora no primeiro dia da própria arte, e todo evento dela
 * é o primeiro dia certo IGUAL OU DEPOIS dessa âncora.
 */
function inicioDoCiclo(string $liga): string
{
    return match (strtoupper($liga)) {
        'ELITE'  => '2026-08-26',   // quarta — o jogo da regular
        'NEXT'   => '2026-08-31',   // segunda
        'RISE'   => '2026-09-04',   // sexta
        'ROOKIE' => '2026-09-05',   // sábado
        default  => '2026-08-31',
    };
}

/**
 * Cria o que falta e conserta o que divergiu. Devolve o relatório e não
 * imprime nada — quem imprime é quem chamou, que pode ser a linha de comando
 * ou uma tela.
 *
 * O que ele conserta num evento que já existe:
 *
 *  - o COMEÇO, quando a grade manda começar antes do que está gravado. Só
 *    pra trás: empurrar pra frente apagaria ocorrências passadas, e
 *    possivelmente gente já escalada nelas.
 *  - o TÍTULO, quando mudou. Não é cosmético — a fase (regular/offs) sai do
 *    título, então "Regular ELITE" numa live de playoffs faz a escala
 *    oferecer gente errada pra ela.
 *
 * @return array{criados:string[], existiam:string[], ajustados:string[]}
 */
function semearGrade(PDO $pdo, bool $gravar): array
{
    ensureCalendarioTables($pdo);
    $tz = new DateTimeZone('America/Sao_Paulo');

    $primeira = function (string $liga, int $dia) use ($tz): string {
        $d = new DateTimeImmutable(inicioDoCiclo($liga), $tz);
        $passos = ($dia - (int)$d->format('w') + 7) % 7;
        return $d->modify("+{$passos} days")->format('Y-m-d');
    };

    // O tipo entra na chave junto do trio (liga, dia, hora): a ROOKIE tem
    // dois eventos de dia inteiro na semana, e todo evento de dia inteiro
    // grava 00:00 — sem o tipo, um seria confundido com o outro.
    $existe = $pdo->prepare("SELECT id, inicio, titulo FROM calendario_eventos
                              WHERE league = ? AND tipo = ? AND repete = 'semanal'
                                AND DAYOFWEEK(inicio) = ? AND TIME(inicio) = ?");
    $criar = $pdo->prepare("INSERT INTO calendario_eventos
                            (league, tipo, titulo, inicio, dia_inteiro, descricao, repete, criado_por)
                            VALUES (?,?,?,?,?,?, 'semanal', NULL)");
    $adiantar = $pdo->prepare("UPDATE calendario_eventos SET inicio = ? WHERE id = ?");
    $renomear = $pdo->prepare("UPDATE calendario_eventos SET titulo = ? WHERE id = ?");

    $out  = ['criados' => [], 'existiam' => [], 'ajustados' => []];
    $DIAS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

    foreach (agendaDaGrade() as [$liga, $dia, $hora, $tipo, $titulo, $obs]) {
        $diaInteiro = $hora === null;
        $hhmmss = ($hora ?: '00:00') . ':00';
        $quando = $primeira($liga, $dia) . ' ' . $hhmmss;

        $rot = $liga . ' · ' . $DIAS[$dia] . ($hora ? ' ' . $hora : ' (dia todo)')
             . ' · ' . $titulo . ' (a partir de ' . date('d/m', strtotime($quando)) . ')';

        // DAYOFWEEK do MySQL é 1=domingo; o nosso $dia é 0=domingo.
        $existe->execute([$liga, $tipo, $dia + 1, $hhmmss]);

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
        if ($gravar) $criar->execute([$liga, $tipo, $titulo, $quando, $diaInteiro ? 1 : 0, $obs]);
    }

    // ── As exceções de data marcada ────────────────────────────────────
    // Reconhecidas pelo instante exato, e não pelo dia da semana: são
    // avulsas, então a data É a identidade delas.
    $exExiste = $pdo->prepare("SELECT id FROM calendario_eventos
                                WHERE league = ? AND tipo = ? AND inicio = ? AND repete = 'nao'");
    foreach (agendaAvulsa() as [$liga, $quando, $tipo, $titulo, $obs]) {
        $inicio = $quando . ':00';
        $rot = $liga . ' · ' . date('d/m', strtotime($inicio)) . ' ' . substr($quando, 11)
             . ' · ' . $titulo . ' (só nesta data)';

        $exExiste->execute([$liga, $tipo, $inicio]);
        if ($exExiste->fetch()) { $out['existiam'][] = $rot; continue; }

        $out['criados'][] = $rot;
        if ($gravar) {
            // O 'nao' de repete vem do DEFAULT da coluna — o INSERT de cima
            // fixa 'semanal', então aqui precisa do próprio.
            $pdo->prepare("INSERT INTO calendario_eventos
                           (league, tipo, titulo, inicio, dia_inteiro, descricao, repete, criado_por)
                           VALUES (?,?,?,?,0,?, 'nao', NULL)")
                ->execute([$liga, $tipo, $titulo, $inicio, $obs]);
        }
    }
    return $out;
}

/**
 * O nome antigo, de quando isto só semeava as lives. Fica porque pode haver
 * chamada por aí — some quando eu tiver certeza que não há.
 */
function semearLives(PDO $pdo, bool $gravar): array
{
    return semearGrade($pdo, $gravar);
}

// ── Daqui pra baixo, só a linha de comando ──────────────────────────────
// O `return` e não `exit` porque a tela dá require neste arquivo pra usar as
// funções acima: um exit aqui mataria a página inteira.
if (PHP_SAPI !== 'cli') return;

$gravar = in_array('--gravar', $argv ?? [], true);
$r = semearGrade(db(), $gravar);

foreach ($r['existiam']  as $x) echo "  ja existe  {$x}\n";
foreach ($r['ajustados'] as $x) echo '  ' . ($gravar ? 'AJUSTADO ' : 'AJUSTARIA') . " {$x}\n";
foreach ($r['criados']   as $x) echo '  ' . ($gravar ? 'CRIADO   ' : 'CRIARIA  ') . " {$x}\n";
echo "\nja existiam: " . count($r['existiam'])
   . '   ' . ($gravar ? 'ajustados: ' : 'ajustaria: ') . count($r['ajustados'])
   . '   ' . ($gravar ? 'criados: ' : 'criaria: ') . count($r['criados']) . "\n";
echo $gravar ? ">>> GRAVADO\n" : "(simulação — use --gravar)\n";
