<?php
/**
 * O quiz do dia: às 10:30 abre a pergunta no grupo, e depois apura sozinho.
 *
 * Agendar na Hostinger DUAS entradas, do mesmo jeito do abraço:
 *
 *   30 13 * * *   /usr/bin/php <caminho>/cron/quiz.php     abre a pergunta
 *   35 16 * * *   /usr/bin/php <caminho>/cron/quiz.php     apura e paga
 *
 * As horas são UTC, que é o fuso do servidor da Hostinger: 13:30 lá são 10:30
 * em Brasília, e 16:35 são 13:35 — cinco minutos depois de a rodada vencer,
 * porque ela fica aberta três horas (BOT_QUIZ_MINUTOS).
 *
 * São duas porque este mesmo script faz as duas coisas: abre de manhã e, três
 * horas depois, fecha a rodada e distribui as moedas. Uma execução só por dia
 * deixaria o resultado sair na manhã seguinte, junto com a pergunta nova.
 *
 * A ordem interna resolve as duas com o mesmo comando: ele primeiro apura o
 * que venceu, depois abre a do dia se ainda não abriu. Quem faz o quê é o
 * relógio, não o parâmetro.
 *
 * O script NÃO depende do fuso da máquina: ele confere o horário de Brasília
 * por conta própria e sai calado antes das 10:30. Se um dia a hospedagem
 * mudar o fuso, o pior que acontece é a pergunta sair mais tarde.
 *
 * Rodar duas vezes no mesmo dia não abre duas rodadas: a marca do dia em
 * app_flags barra a segunda, e quizAbrir() desiste se já houver rodada aberta.
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

// A marca do dia impede que a execução da tarde abra uma segunda pergunta
// depois de a da manhã já ter aberto e a rodada ter sido apurada.
//
// Do mesmo jeito do abraço, e pela mesma razão: a marca é gravada ANTES de
// enfileirar, e quem detecta o "já foi hoje" é a chave primária estourando.
// Conferir com SELECT antes deixaria brecha pra duas execuções simultâneas
// mandarem as duas.
//
// app_flags é (flag, applied_at) e já existe desde as migrações — daí a marca
// carregar a data no nome, em vez de ser uma linha só com o valor mudando.
// Bot desligado: sai antes de queimar a pergunta. quizAbrir() marca a pergunta
// como usada e cria a rodada; descobrir só na hora de enfileirar deixaria uma
// rodada de pé que ninguém viu no grupo e que ia ser "apurada" três horas
// depois, anunciando o resultado de uma pergunta que nunca saiu.
if (!whatsappAtivo($pdo)) {
    fwrite(STDERR, "o bot está desligado — nada foi aberto\n");
    exit(1);
}

$marca = 'quiz_do_dia_' . $hoje;
if (!$agora) {
    try {
        $pdo->prepare("INSERT INTO app_flags (flag, applied_at) VALUES (?, NOW())")->execute([$marca]);
    } catch (Throwable $e) {
        echo "o quiz de hoje já foi aberto\n";
        exit(0);
    }
}

// O grupo do config é só o PADRÃO: a pergunta pode ter escolhido outro, e
// quem sabe pra onde vai é quizAbrir().
$aberta = quizAbrir($pdo, $grupo);
if ($aberta === null) {
    // Nada foi ao ar, então a marca do dia não pode ficar de pé: senão um
    // banco de perguntas vazio às 10:30 cala o quiz até amanhã, mesmo depois
    // de alguém cadastrar pergunta no meio da manhã.
    if (!$agora) $pdo->prepare("DELETE FROM app_flags WHERE flag = ?")->execute([$marca]);
    echo "nada a abrir (rodada em aberto ou banco de perguntas vazio)\n";
    exit(0);
}
$grupo = $aberta['grupo'];
$texto = $aberta['texto'];

if (!whatsappEnfileirar($pdo, $grupo, $texto, true, 'quiz')) {
    if (!$agora) $pdo->prepare("DELETE FROM app_flags WHERE flag = ?")->execute([$marca]);
    fwrite(STDERR, "não deu pra enfileirar — o bot está desligado?\n");
    exit(1);
}

echo "quiz do dia aberto no grupo principal\n";
exit(0);
