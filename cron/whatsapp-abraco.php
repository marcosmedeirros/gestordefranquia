<?php
/**
 * O abraço do dia: às 15h, um GM sorteado leva um abraço no grupo principal.
 *
 * Agendar na Hostinger:
 *   0 18 * * *  /usr/bin/php <caminho>/cron/whatsapp-abraco.php
 *
 * O 18 é porque o servidor da Hostinger está em UTC — 18:00 lá são 15:00 em
 * Brasília. Mas o script NÃO depende disso: ele confere o horário de Brasília
 * por conta própria e sai calado antes das 15h. Se um dia a hospedagem mudar o
 * fuso da máquina, o pior que acontece é o abraço sair mais tarde, não no
 * horário errado.
 *
 * Quem quiser tolerância a falha pode agendar de hora em hora ("0 * * * *"):
 * aí, se a execução das 15h se perder, a das 16h manda.
 *
 * Se rodar mais de uma vez no mesmo dia, o segundo disparo não faz nada — a
 * marca do dia fica em app_flags. Ninguém leva dois abraços.
 *
 * A lógica está em backend/whatsapp_abraco.php, compartilhada com o botão
 * "Disparar abraço" da aba Gestão.
 *
 * Pra testar fora de hora: php cron/whatsapp-abraco.php --agora
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/whatsapp_abraco.php';

$r = enviarAbracoDoDia(db(), in_array('--agora', $argv ?? [], true));

if ($r['enviado']) {
    echo "abraço: {$r['nome']} ({$r['time']})" . ($r['com_mencao'] ? ' — com menção' : ' — sem telefone, nome puro') . "\n";
    exit(0);
}

// Fora de hora é o caso normal rodando de hora em hora: sai calado pra não
// encher o log. Os outros motivos são problema e merecem aparecer.
if ($r['motivo'] === 'fora_de_hora' || $r['motivo'] === 'ja_foi_hoje') exit(0);

fwrite(STDERR, "abraço não enviado: {$r['motivo']}\n");
exit(1);
