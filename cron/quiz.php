<?php
/**
 * O quiz do dia: às 10:30 abre a pergunta no grupo, e depois apura sozinho.
 *
 * Agendar na Hostinger de meia em meia hora — no cron, "a cada 30 minutos"
 * (o padrão com barra; escrito por extenso aqui porque a sequência fecharia
 * este bloco de comentário):
 *   /usr/bin/php <caminho>/cron/quiz.php
 *
 * Uma execução só por dia não serviria: ela abre às 10:30, mas quem FECHA a
 * rodada e distribui as moedas também é este script, três horas depois. Rodando
 * de meia em meia hora, ele faz as duas coisas e ainda sobrevive a uma execução
 * perdida — a seguinte cobre.
 *
 * O horário é conferido em Brasília por conta própria, e não no fuso da
 * máquina: a Hostinger roda em UTC, e depender disso é dar de presente ao
 * futuro um quiz saindo às 7h da manhã.
 *
 * Rodar duas vezes no mesmo dia não abre duas rodadas — quizAbrir() desiste se
 * já houver uma aberta no grupo.
 *
 * Pra testar fora de hora: php cron/quiz.php --agora
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/quiz.php';
require_once __DIR__ . '/../backend/whatsapp.php';

/** A partir de que hora o quiz do dia pode abrir. */
const QUIZ_HORA = '10:30';

$agora  = in_array('--agora', $argv ?? [], true);
$pdo    = db();
$hoje   = date('Y-m-d');

// ── 1. Apura o que já venceu ─────────────────────────────────────────────
// Vem antes de abrir: se por algum motivo a rodada de ontem ainda estiver de
// pé, ela fecha e paga antes de a de hoje entrar no lugar.
$fechadas = 0;
foreach (quizFecharVencidas($pdo) as [$grupo, $texto]) {
    whatsappEnfileirar($pdo, $grupo, $texto, true, 'quiz');
    $fechadas++;
    echo "apurada a rodada de {$grupo}\n";
}

// ── 2. Abre a do dia ─────────────────────────────────────────────────────
if (!$agora && date('H:i') < QUIZ_HORA) {
    echo "ainda não são " . QUIZ_HORA . " em Brasília (agora: " . date('H:i') . ")"
       . ($fechadas ? " — {$fechadas} rodada(s) apurada(s)" : '') . "\n";
    exit(0);
}

// O grupo do quiz é o principal — o mesmo do abraço. Manter num lugar só
// evita a pergunta cair em três grupos e virar três placares diferentes.
$grupo = trim((string)($pdo->query("SELECT grupo_principal FROM whatsapp_config WHERE id = 1")->fetchColumn() ?: ''));
if ($grupo === '') {
    fwrite(STDERR, "sem grupo principal configurado\n");
    exit(1);
}

// A marca do dia impede que a execução das 11h abra a segunda pergunta depois
// de a das 10:30 já ter aberto e a rodada ter sido fechada no meio do dia.
$marca = 'quiz_aberto_em';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_flags (
        nome VARCHAR(60) PRIMARY KEY, valor VARCHAR(60) NULL,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $pdo->prepare("SELECT valor FROM app_flags WHERE nome = ?");
    $st->execute([$marca]);
    if (!$agora && $st->fetchColumn() === $hoje) {
        echo "o quiz de hoje já foi aberto\n";
        exit(0);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "app_flags: " . $e->getMessage() . "\n");
}

// O grupo do config é só o PADRÃO: a pergunta pode ter escolhido outro, e
// quem sabe pra onde vai é quizAbrir().
$aberta = quizAbrir($pdo, $grupo);
if ($aberta === null) {
    echo "nada a abrir (rodada em aberto ou banco de perguntas vazio)\n";
    exit(0);
}
$grupo = $aberta['grupo'];
$texto = $aberta['texto'];

if (!whatsappEnfileirar($pdo, $grupo, $texto, true, 'quiz')) {
    fwrite(STDERR, "não deu pra enfileirar — o bot está desligado?\n");
    exit(1);
}

$pdo->prepare("INSERT INTO app_flags (nome, valor) VALUES (?,?)
               ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([$marca, $hoje]);

echo "quiz do dia aberto no grupo principal\n";
exit(0);
