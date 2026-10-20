<?php
/**
 * ── O RELÓGIO DO FANTASY NBA ─────────────────────────────────────────
 *
 * Um comando só, chamado de hora em hora. Ele descobre sozinho o que fazer
 * pela hora do dia, em vez de pedir quatro linhas no agendador da Hostinger —
 * entrada de cron é coisa que se esquece de criar, e o jogo depende desta.
 *
 *   0 * * * *  /opt/alt/php83/usr/bin/php ~/domains/fbabrasil.com.br/public_html/cron/fantasy_nba.php
 *
 * O QUE ELE FAZ, EM ORDEM:
 *
 *   1. ABRE O DIA — importa os jogos de hoje e de amanhã. Amanhã junto porque
 *      quem escala à noite quer já montar o time do dia seguinte.
 *   2. PUXA OS BOXSCORES dos jogos que terminaram e ainda não foram lidos.
 *   3. APURA as rodadas cujos jogos acabaram todos, paga as moedas e fecha.
 *   4. REPRECIFICA o mercado uma vez por dia, de madrugada.
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
foreach ([0, 1] as $somar) {
    $dia = $agora->modify("+{$somar} day")->format('Y-m-d');
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
        : "rodada {$r['dia']} não apurou: {$res['erro']}");
}

/* ── 4) O MERCADO ───────────────────────────────────────────────────── */
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
