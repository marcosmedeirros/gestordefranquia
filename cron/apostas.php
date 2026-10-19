<?php
/**
 * O PAGAMENTO DAS APOSTAS. Só isso.
 *
 * Agendar na Hostinger UMA entrada:
 *
 *   (barra)10 * *  /opt/alt/php83/usr/bin/php <caminho>/cron/apostas.php
 *
 * É "a cada 10 minutos, todo dia" — escrita por extenso porque a barra
 * seguida de asterisco fecharia este bloco de comentário.
 *
 * ── O ROBÔ NÃO CRIA MAIS APOSTA ──────────────────────────────────────
 *
 * Decisão do Marcos (19/10/2026): "tirar a criação das apostas
 * automática, deixar apenas o pagamento nas que tu realmente consegue
 * fazer".
 *
 * Os números davam razão a ele: das 160 apostas que nasceram sozinhas,
 * 29 foram CANCELADAS — 18%, e em alguns tipos um quarto. Aposta que o
 * admin apaga é aposta que não devia ter existido, e cada uma dessas
 * passou pelo grupo com @todos antes de ser apagada.
 *
 * Pagar é outra história: ali o robô não inventa nada, ele lê o
 * resultado que alguém já registrou e credita. O que ele não consegue
 * casar vai pra fila e espera mão humana — nunca paga no chute, porque
 * pagar errado mexe no saldo de todo mundo.
 *
 * Criar virou trabalho de gente: o formulário do admin, ou o botão
 * "Criar apostas do dia", que monta o catálogo inteiro de uma vez e
 * continua lá pra quem quiser.
 *
 * ── RODAR DUAS VEZES NÃO DOBRA NADA ──────────────────────────────────
 *
 * O pagamento confere o status com FOR UPDATE antes de creditar. Dá pra
 * executar à mão a qualquer momento sem medo:
 *
 *   php cron/apostas.php --simular     descreve, não paga
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/apostas_auto.php';
require_once __DIR__ . '/../backend/whatsapp.php';

$argvv   = $argv ?? [];
$simular = in_array('--simular', $argvv, true);
$aplicar = !$simular;

/* O argumento `pagar` continua aceito e ignorado: a entrada antiga do
   painel passa ele, e quebrar o cron por causa de uma palavra a mais
   seria trocar um problema por outro. */

$pdo = db();
$log = function (string $t) { echo '[' . date('H:i:s') . "] {$t}\n"; };

/* `criar` não existe mais. Quem agendou a entrada das 12:05 precisa
   apagá-la no painel — enquanto ela existir, roda isto aqui e sai. */
if (in_array('criar', $argvv, true)) {
    $log('o robô não cria mais aposta — só paga. Apague a entrada "criar" do painel.');
    exit(0);
}

/* ── PAGAR ──────────────────────────────────────────────────────────── */

/* NADA DE CRIAR AQUI. Este trecho abria o jogo da semana atrasado e
   publicava os rascunhos que ninguém revisou depois de 3h — as duas
   coisas são criação, e criação virou trabalho de gente. */

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

if ($aplicar && apostasAutoAvisarPagas($pdo, $r['pagas'])) {
    $log('grupo avisado das pagas, com @todos');
}

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
