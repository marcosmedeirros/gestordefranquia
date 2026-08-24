<?php
/**
 * Os parabéns do dia: às 9h, quem faz aniversário leva parabéns no grupo.
 *
 * Agendar na Hostinger:
 *   0 12 * * *  /usr/bin/php <caminho>/cron/whatsapp-aniversario.php
 *
 * O 12 é porque o servidor da Hostinger está em UTC — 12:00 lá são 9:00 em
 * Brasília. Mas o script NÃO depende disso: ele confere o horário de
 * Brasília por conta própria e sai calado antes das 9h. Se um dia a
 * hospedagem mudar o fuso da máquina, o pior que acontece é o parabéns sair
 * mais tarde, e não no horário errado.
 *
 * Quem quiser tolerância a falha pode agendar de hora em hora ("0 * * * *"):
 * aí, se a execução das 9h se perder, a das 10h manda. Rodar de novo no
 * mesmo dia não repete nada — a marca do dia fica em app_flags.
 *
 * A lógica está em backend/whatsapp_aniversario.php.
 *
 * Pra testar fora de hora: php cron/whatsapp-aniversario.php --agora
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/whatsapp_aniversario.php';

$r = enviarAniversariosDoDia(db(), in_array('--agora', $argv ?? [], true));

if ($r['enviado']) {
    echo "aniversário: {$r['quantos']} — " . implode(', ', $r['nomes']) . "\n";
    exit(0);
}

// Os dois casos normais saem calados: rodando de hora em hora, encher o log
// com "ninguém hoje" 340 vezes por ano esconde o dia em que der problema
// de verdade.
if (in_array($r['motivo'], ['fora_de_hora', 'ja_foi_hoje', 'ninguem_hoje'], true)) exit(0);

fwrite(STDERR, "aniversário não enviado: {$r['motivo']}\n");
exit(1);
