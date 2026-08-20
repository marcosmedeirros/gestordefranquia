<?php
require __DIR__ . '/backend/db.php';
require __DIR__ . '/api/whatsapp-comandos.php';
$pdo = db();
$r = wcResponderComando($pdo, "/" . ($argv[1] ?? 'comandos'), $argv[2] ?? 'ELITE', '', '');
echo $r === null ? "(nao reconhecido)\n" : $r . "\n";
