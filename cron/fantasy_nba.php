<?php
/**
 * ── O RELÓGIO DO FANTASY NBA ─────────────────────────────────────────
 *
 * Um comando só, chamado de hora em hora. Ele descobre sozinho o que fazer
 * pela hora do dia, em vez de pedir quatro linhas no agendador da Hostinger —
 * entrada de cron é coisa que se esquece de criar, e o jogo depende desta.
 *
 *   0 * * * * /usr/bin/php /home/u289267434/domains/fbabrasil.com.br/public_html/cron/fantasy_nba.php
 *
 * É o que está agendado no hPanel desde 20/10/2026. O /usr/bin/php é o 8.2
 * da hospedagem e serve: este arquivo e o backend usam str_contains, que é
 * 8.0 pra cima.
 *
 * O QUE ELE FAZ, EM ORDEM:
 *
 *   1. ABRE OS DIAS — ontem, hoje, amanhã e qualquer dia com jogo pendente.
 *      Amanhã porque quem escala à noite já quer montar o time do dia
 *      seguinte; ONTEM porque jogo das 22:30 termina depois da meia-noite, e
 *      sem reler aquele dia o placar nunca fecharia.
 *   2. PUXA OS BOXSCORES dos jogos que terminaram e ainda não foram lidos.
 *   3. APURA as rodadas cujos jogos acabaram todos, paga as moedas, MOVE O
 *      PREÇO de quem jogou (@see fnbaValorizar) e fecha.
 *   4. FECHA A SEMANA quando o domingo passa, pagando os FBA Points.
 *   5. REPRECIFICA o mercado uma vez por dia, de madrugada.
 *
 * Tudo é idempotente: rodar duas vezes seguidas não paga ninguém duas vezes
 * (fnbaApurar sai na hora se a rodada já está apurada) nem duplica linha
 * (todos os INSERT são ON DUPLICATE KEY UPDATE). É o que permite chamar de
 * hora em hora sem medo e rodar na mão quando precisar.
 *
 * @see backend/fantasy_nba.php  a conta
 */

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/fantasy_nba.php';

$pdo = db();
fnbaTabelas($pdo);

$tz    = new DateTimeZone(FNBA_FUSO);
$agora = new DateTimeImmutable('now', $tz);
$hora  = (int)$agora->format('G');
$log   = fn(string $m) => print('[' . date('H:i:s') . "] {$m}" . PHP_EOL);

$log('fantasy-nba: começando');

/* ── 1) OS DIAS ─────────────────────────────────────────────────────── */
/* ONTEM ENTRA JUNTO, E NÃO É DETALHE. O último jogo de um dia começa 22:30 e
   acaba perto de uma da manhã — já no dia seguinte. Importando só hoje e
   amanhã, o placar do dia anterior nunca era relido, o estado dele ficava
   'in' pra sempre e a rodada JAMAIS seria apurada: o passo 3 exige que todos
   os jogos estejam 'post'. */
$dias = [];
foreach ([-1, 0, 1] as $somar) $dias[] = $agora->modify("{$somar} day")->format('Y-m-d');

/* E qualquer outro dia que tenha jogo pendente — cobre adiamento, jogo que a
   ESPN demora a fechar e o cron que ficou fora do ar o dia inteiro. */
foreach ($pdo->query("SELECT DISTINCT DATE(g.inicio) AS d FROM fnba_jogos g
                       WHERE g.estado <> 'post' AND g.inicio < NOW()
                         AND g.inicio > NOW() - INTERVAL 7 DAY")->fetchAll(PDO::FETCH_COLUMN) as $d) {
    $dias[] = (string)$d;
}

foreach (array_unique($dias) as $dia) {
    $r = fnbaImportarDia($pdo, $dia);
    $log($r ? "dia {$dia}: rodada #{$r['rodada_id']}, {$r['jogos']} jogos, fecha {$r['fecha_em']}"
            : "dia {$dia}: sem jogos na NBA");
}

/* ── 2) OS BOXSCORES ────────────────────────────────────────────────── */
/* Só jogo que a ESPN já marcou como 'post' E que ainda não tem linha em
   fnba_stats. Sem o segundo filtro, todo dia reprocessaria a temporada
   inteira — e o limite de tempo da hospedagem chegaria antes do fim. */
$st = $pdo->query("SELECT g.id FROM fnba_jogos g
                    WHERE g.estado = 'post'
                      AND NOT EXISTS (SELECT 1 FROM fnba_stats s WHERE s.jogo_id = g.id)
                 ORDER BY g.inicio DESC LIMIT 20");
$pendentes = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
$linhas = 0;
foreach ($pendentes as $gid) {
    $n = fnbaImportarBoxscore($pdo, (int)$gid);
    $linhas += $n;
    if ($n) $log("boxscore do jogo {$gid}: {$n} linhas");
    usleep(200000);
}
if (!$pendentes) $log('nenhum boxscore novo');

/* ── 3) A APURAÇÃO ──────────────────────────────────────────────────── */
/* A rodada fecha quando o último jogo dela termina. Os sete dias de janela
   cobrem a rodada que ficou pra trás por causa de jogo adiado. */
$st = $pdo->query("SELECT r.id, r.dia FROM fnba_rodadas r
                    WHERE r.status <> 'apurada'
                      AND r.dia >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                      AND NOT EXISTS (SELECT 1 FROM fnba_jogos g
                                       WHERE g.rodada_id = r.id AND g.estado <> 'post')
                      AND EXISTS (SELECT 1 FROM fnba_jogos g WHERE g.rodada_id = r.id)
                 ORDER BY r.dia");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $res = fnbaApurar($pdo, (int)$r['id']);
    $log($res['ok']
        ? "rodada {$r['dia']} apurada: {$res['cartolas']} cartolas, {$res['moedas']} moedas pagas"
          . " · mercado: {$res['subiu']} subiram, {$res['caiu']} caíram"
          . ($res['estreantes'] ? ", {$res['estreantes']} ainda sem histórico" : "")
        : "rodada {$r['dia']} não apurou: {$res['erro']}");
}

/* ── 4) A SEMANA ────────────────────────────────────────────────────── */
/* A semana vai de segunda a domingo e fecha quando o domingo ACABA, ou seja,
   na primeira passada depois da virada. Ela paga em FBA Points, que é a
   moeda séria da liga — por isso só fecha com todas as rodadas apuradas:
   publicar campeão que ainda pode mudar é pior que publicar tarde.
   @see FNBA_PREMIO_SEMANA */
fnbaSemanaDe($pdo);                       // garante a linha da semana corrente
foreach (fnbaSemanasPendentes($pdo) as $sem) {
    $r = fnbaApurarSemana($pdo, (int)$sem['id']);
    $log($r['ok']
        ? "semana {$sem['inicio']}–{$sem['fim']}: " . (($r['ja'] ?? false)
            ? 'já estava paga'
            : "{$r['cartolas']} cartolas, {$r['pontos']} FBA Points pagos")
        : "semana {$sem['inicio']}: não fechou ({$r['erro']})");
}

/* ── 5) O MERCADO ───────────────────────────────────────────────────── */
/* Uma vez por dia, de madrugada: elenco muda com troca e corte, e o preço
   precisa acompanhar. De dia seria pior que inútil — mudar o preço no meio
   da escalação de alguém faria o time caber e deixar de caber sozinho. */
if ($hora === 6) {
    $log('reprecificando o mercado…');
    fnbaImportarTimes($pdo);
    $r = fnbaImportarJogadores($pdo);
    $log("mercado: {$r['jogadores']} jogadores, base {$r['temporada_base']}");
}

$log('fantasy-nba: fim');
