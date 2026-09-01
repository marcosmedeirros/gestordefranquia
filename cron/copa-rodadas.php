<?php
/**
 * Cron da Copa: vira a rodada quando o tempo dela acaba.
 *
 * Cada rodada dura `minutos_rodada` (30 por padrão) a partir do momento em
 * que a votação abre. Vencido o prazo, este cron apura os votos, paga quem
 * acertou, abre a rodada seguinte e conta as duas coisas no grupo: o
 * resultado do que fechou e os confrontos do que começou.
 *
 * Antes disso a copa só andava quando alguém apertava o botão no admin — uma
 * rodada que vencesse à noite ficava parada até de manhã.
 *
 * Roda de minuto em minuto, porque uma rodada de 30min conferida de hora em
 * hora atrasaria até 60. O trabalho é uma consulta por índice quando não há
 * nada vencido, que é o caso quase sempre.
 *
 * Agendar na Hostinger:
 *   * * * * *  /usr/bin/php /home/u289267434/domains/fbabrasil.com.br/public_html/cron/copa-rodadas.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../games/core/copa_motor.php';
require_once __DIR__ . '/../backend/whatsapp.php';

date_default_timezone_set('America/Sao_Paulo');

$pdo = db();
$viradas = copaVirarRodadasVencidas($pdo);

if (!$viradas) {
    exit;   // o caso normal: nenhuma rodada venceu neste minuto
}

/* O grupo é lido uma vez só, e a falta dele não impede a virada — que já
   aconteceu e já pagou. Sem grupo, o cron só registra no log. */
$grupo = '';
try {
    $grupo = trim((string)($pdo->query(
        "SELECT grupo_principal FROM whatsapp_config WHERE id=1")->fetchColumn() ?: ''));
} catch (Throwable $e) {
    error_log('[copa-cron] grupo: ' . $e->getMessage());
}

foreach ($viradas as $v) {
    if (!empty($v['erro'])) {
        error_log(sprintf('[copa-cron] copa #%d (%s) nao virou: %s',
            $v['torneio_id'], $v['titulo'], $v['erro']));
        echo sprintf("[%s] copa #%d ERRO: %s\n", date('Y-m-d H:i:s'), $v['torneio_id'], $v['erro']);
        continue;
    }

    /* Duas mensagens, não uma: o resultado é o fim de uma história e os
       confrontos novos são o começo de outra. Emendadas num bloco só, a
       segunda metade se perde na rolagem do grupo. */
    $mensagens = array_filter([
        $v['resultado'] ?: '',
        $v['proxima'] ?: '',
    ], fn($t) => trim($t) !== '');

    $enviadas = 0;
    if ($grupo !== '' && function_exists('whatsappEnfileirar')) {
        foreach ($mensagens as $texto) {
            try {
                // `true` fura a janela de horário: a copa anda no tempo dela,
                // e um resultado que só chega às 8h45 do dia seguinte não é
                // resultado, é histórico.
                if (whatsappEnfileirar($pdo, $grupo, $texto, true, 'copa')) $enviadas++;
            } catch (Throwable $e) {
                error_log('[copa-cron] enfileirar: ' . $e->getMessage());
            }
        }
    }

    echo sprintf("[%s] copa #%d (%s) virou%s — %d mensagem(ns) na fila\n",
        date('Y-m-d H:i:s'), $v['torneio_id'], $v['titulo'],
        $v['campeao'] ? ' e TERMINOU, campeao: ' . $v['campeao'] : '',
        $enviadas);
}
