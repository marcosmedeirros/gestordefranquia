<?php
/**
 * As apostas do dia: cria às 11h e paga conforme o card Pontuação é preenchido.
 *
 * Agendar na Hostinger DUAS entradas:
 *
 *   0 14 * * *     /opt/alt/php83/usr/bin/php <caminho>/cron/apostas.php criar
 *   (barra)10 * *  /opt/alt/php83/usr/bin/php <caminho>/cron/apostas.php pagar
 *
 * A segunda é "a cada 10 minutos, todo dia" — escrita por extenso porque a
 * barra seguida de asterisco fecharia este bloco de comentário.
 *
 * As horas são UTC, que é o fuso do servidor: 14:00 lá são 11:00 em Brasília.
 *
 * ── POR QUE DUAS ─────────────────────────────────────────────────────
 *
 * Criar é uma vez por dia, num horário combinado com a liga. Pagar não tem
 * horário: depende de quando alguém preenche o card Pontuação, e isso acontece
 * depois da simulação, que atrasa. De dez em dez minutos o pagamento sai quase
 * junto com o resultado, sem ninguém precisar lembrar de clicar.
 *
 * O "pagar" também abre os rascunhos que ninguém revisou (carência de 3h), pra
 * que um dia em que o dono da liga não apareceu não fique sem aposta.
 *
 * ── RODAR DUAS VEZES NÃO DOBRA NADA ──────────────────────────────────
 *
 * A criação tem trava por (liga, temporada, tipo) e o pagamento confere o
 * status com FOR UPDATE antes de creditar. Dá pra executar à mão a qualquer
 * momento sem medo — e é assim que se testa:
 *
 *   php cron/apostas.php criar --simular        descreve, não grava
 *   php cron/apostas.php pagar --simular        descreve, não paga
 *   php cron/apostas.php criar --dia=2026-10-08
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/apostas_auto.php';
require_once __DIR__ . '/../backend/whatsapp.php';

$argvv   = $argv ?? [];
$acao    = in_array('pagar', $argvv, true) ? 'pagar' : 'criar';
$simular = in_array('--simular', $argvv, true);
$aplicar = !$simular;

$dia = date('Y-m-d');
foreach ($argvv as $a) {
    if (preg_match('/^--dia=(\d{4}-\d{2}-\d{2})$/', $a, $m)) $dia = $m[1];
}

$pdo = db();
$log = function (string $t) { echo '[' . date('H:i:s') . "] {$t}\n"; };

if ($acao === 'criar') {
    $r = apostasAutoCriar($pdo, $dia, $aplicar);
    $log(($simular ? 'SIMULANDO ' : '') . "criação de {$dia}");

    $abertas = $rascunhos = 0;
    foreach ($r['criadas'] as $c) {
        $log(sprintf('  %-7s %-34s %s', $c['liga'], $c['nome'], implode(' | ', $c['opcoes'])));
        /* O catálogo marca as de palpite como revisar; aqui só se conta. */
        in_array($c['tipo'], ['mvp', 'mip', 'dpoy', 'roy', '6th_man'], true) ? $rascunhos++ : $abertas++;
    }
    foreach ($r['puladas'] as $p) $log("  (pulada) {$p}");
    foreach ($r['erros'] as $e)   $log("  ERRO {$e}");
    $log("total: " . count($r['criadas']) . " criadas ({$abertas} abertas, {$rascunhos} em rascunho)");

    /* AVISA O DONO DA LIGA, porque rascunho que ninguém vê não é revisado.
       Só quando há rascunho: mensagem diária sem motivo vira ruído e deixa de
       ser lida, que é o jeito de um aviso parar de funcionar. */
    if ($aplicar && $rascunhos > 0) {
        $dono = apostasAutoNumeroDoDono($pdo);
        if ($dono !== '' && function_exists('whatsappEnfileirar')) {
            $txt = "🎲 *Apostas do dia criadas*\n\n"
                 . "{$abertas} já abertas e *{$rascunhos} esperando seus palpites* "
                 . "(MVP, MIP, DPOY, ROY, 6º homem).\n\n"
                 . "Deixei uma sugestão em cada uma, mas a sua lista acerta o dobro — "
                 . "vale trocar os nomes.\n"
                 . "Se eu não ouvir nada, abro com a sugestão em 3h.\n\n"
                 . "👉 admin-apostas.php";
            whatsappEnfileirar($pdo, $dono, $txt, false, 'apostas');
            $log('aviso enfileirado pro dono da liga');
        } else {
            $log('sem número do dono configurado: aviso não enviado');
        }
    }
    exit(0);
}

/* ── PAGAR ──────────────────────────────────────────────────────────── */
$pend = apostasAutoPublicarPendentes($pdo, 180, $aplicar);
foreach ($pend as $p) $log("  publicado rascunho #{$p['id']} {$p['liga']} {$p['nome']}");

$r = apostasAutoResolver($pdo, $aplicar);
foreach ($r['pagas'] as $x) {
    $q = $x['quantos'] === null ? 'simulado' : "{$x['quantos']} pessoas";
    $log(sprintf('  PAGA #%-4s %-34s %s (%s)', $x['evt']['id'], $x['evt']['nome'], $q, $x['motivo']));
}
foreach ($r['fila'] as $x) {
    $log(sprintf('  FILA #%-4s %-34s %s', $x['evt']['id'], $x['evt']['nome'], $x['motivo']));
}
$log('total: ' . count($r['pagas']) . ' pagas, ' . count($r['esperando'])
   . ' esperando resultado, ' . count($r['fila']) . ' na fila');

/* A FILA É AVISADA UMA VEZ POR APOSTA, não a cada dez minutos: a mesma
   pendência repetida seis vezes por hora é o que faz alguém silenciar o bot. */
if ($aplicar && $r['fila']) {
    $novas = $pdo->query("SELECT COUNT(*) FROM apostas_auto_fila
                           WHERE resolvido_em IS NULL AND avisado_em IS NULL")->fetchColumn();
    if ((int)$novas > 0) {
        $dono = apostasAutoNumeroDoDono($pdo);
        if ($dono !== '' && function_exists('whatsappEnfileirar')) {
            whatsappEnfileirar($pdo, $dono,
                "⚠️ *Apostas que eu não soube pagar*\n\n{$novas} aposta(s) com resultado na mão "
                . "mas sem casar com as opções. Não paguei nenhuma — pagar errado mexe no saldo "
                . "de todo mundo.\n\n👉 admin-apostas.php", false, 'apostas');
            $log("aviso de fila enfileirado ({$novas} pendências novas)");
        }
        $pdo->exec("UPDATE apostas_auto_fila SET avisado_em = NOW()
                     WHERE resolvido_em IS NULL AND avisado_em IS NULL");
    }
}
exit(0);
