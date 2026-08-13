<?php
/**
 * O quiz do dia: às 10:30 abre a pergunta no grupo, e depois apura sozinho.
 *
 * Agendar na Hostinger DUAS entradas, do mesmo jeito do abraço:
 *
 *   30 13 * * *    /usr/bin/php <caminho>/cron/quiz.php    abre a pergunta
 *   (barra)5 13 *  /usr/bin/php <caminho>/cron/quiz.php    apura e paga
 *
 * A segunda é "a cada 5 minutos, na hora 13" — escrita por extenso porque a
 * barra seguida de asterisco fecharia este bloco de comentário.
 *
 * As horas são UTC, que é o fuso do servidor da Hostinger: 13:30 lá são 10:30
 * em Brasília. A rodada fica aberta dez minutos (BOT_QUIZ_MINUTOS), então
 * fecha 10:40 — e a execução das 13:40 UTC é quem apura.
 *
 * São duas porque este mesmo script faz as duas coisas: abre e, dez minutos
 * depois, fecha a rodada e distribui as moedas. Uma execução só por dia
 * deixaria o resultado sair na manhã seguinte, junto com a pergunta nova.
 *
 * A segunda ser de 5 em 5 minutos, e não num horário fixo, é proteção: o prazo
 * é gravado a partir do instante em que a ABERTURA roda, então um atraso dela
 * empurra o fechamento junto, e aí qualquer execução da hora pega.
 *
 * Mexeu em BOT_QUIZ_HORA ou BOT_QUIZ_MINUTOS (backend/quiz.php)? As entradas
 * têm que andar junto — a de apuração precisa cobrir o horário do fechamento.
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

// O horário e a duração moram no backend/quiz.php, junto de quem grava o
// prazo da rodada e de quem anuncia no grupo. Uma cópia aqui já teria
// virado mentira na primeira vez que o horário mudasse.

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
if (!$agora && date('H:i') < BOT_QUIZ_HORA) {
    echo "ainda não são " . BOT_QUIZ_HORA . " em Brasília (agora: " . date('H:i') . ")"
       . ($fechadas ? " — {$fechadas} rodada(s) apurada(s)" : '') . "\n";
    exit(0);
}

// O grupo do quiz é escolhido na tela; sem escolha, cai no principal. Manter
// num lugar só evita a pergunta cair em três grupos e virar três placares.
$grupo = quizGrupoDoQuiz($pdo);
if ($grupo === '') {
    fwrite(STDERR, "sem grupo configurado pro quiz nem grupo principal\n");
    exit(1);
}

// Bot desligado: sai antes de queimar a pergunta. quizAbrir() marca a pergunta
// como usada e cria a rodada; descobrir só na hora de enfileirar deixaria uma
// rodada de pé que ninguém viu no grupo e que seria "apurada" dez minutos
// depois, anunciando o resultado de uma pergunta que nunca saiu.
if (!whatsappAtivo($pdo)) {
    fwrite(STDERR, "o bot está desligado — nada foi aberto\n");
    exit(1);
}

// A marca do dia impede que a execução seguinte abra uma segunda pergunta
// depois de a primeira já ter aberto e a rodada ter sido apurada.
//
// Do mesmo jeito do abraço, e pela mesma razão: a marca é gravada ANTES de
// enfileirar, e quem detecta o "já foi hoje" é a chave primária estourando.
// Conferir com SELECT antes deixaria brecha pra duas execuções simultâneas
// mandarem as duas — e elas existem, porque as entradas do agendador se
// sobrepõem no minuto da abertura.
//
// app_flags é (flag, applied_at) e já existe desde as migrações — daí a marca
// carregar a data no nome, em vez de ser uma linha só com o valor mudando.
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
