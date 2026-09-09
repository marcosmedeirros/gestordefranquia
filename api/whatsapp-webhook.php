<?php
/**
 * Recebe as mensagens do grupo pela Evolution e responde aos comandos.
 *
 * Por que aqui e não na máquina local: a Evolution consegue fazer chamada de
 * SAÍDA, mesmo estando num PC doméstico. Então ela mesma avisa a Hostinger
 * quando chega mensagem — sem túnel, sem porta aberta, sem IP fixo.
 *
 * E a resposta não precisa de canal novo: ela entra na whatsapp_fila e o worker
 * que já roda (bot/whatsapp-local.php) entrega. Um endpoint, nada mais.
 *
 * A janela de 08:45–18:00 NÃO vale para comando: quem perguntou está esperando
 * resposta, e responder não é notificação não solicitada. Ver o tipo 'comando'
 * em whatsappProcessarFila().
 *
 * Configurar na Evolution (uma vez):
 *   POST /webhook/set/fba
 *   { "webhook": { "enabled": true,
 *                  "url": "https://fbabrasil.com.br/api/whatsapp-webhook.php",
 *                  "headers": { "x-fba-token": "<bot_token>" },
 *                  "events": ["MESSAGES_UPSERT"] } }
 */
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/whatsapp.php';
require_once __DIR__ . '/whatsapp-comandos.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
ensureWhatsAppTables($pdo);

// ── Autenticação: mesmo token do worker ─────────────────────────────────
// Preferência pelo header: token na query fica gravado no banco da Evolution e
// em qualquer log de acesso pelo caminho. Aceito na URL só como alternativa,
// pra não depender de o webhook da Evolution suportar headers.
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$enviado = stripos($auth, 'Bearer ') === 0
    ? trim(substr($auth, 7))
    : (string)($_SERVER['HTTP_X_FBA_TOKEN'] ?? $_GET['token'] ?? '');
$esperado = (string)($pdo->query("SELECT bot_token FROM whatsapp_config WHERE id = 1")->fetchColumn() ?: '');
if ($esperado === '' || $enviado === '' || !hash_equals($esperado, $enviado)) {
    http_response_code(401);
    echo json_encode(['erro' => 'Token inválido']);
    exit;
}

$bruto = file_get_contents('php://input');
$evento = json_decode($bruto, true);
if (!is_array($evento)) {
    // Sempre 200 pra Evolution: erro daqui não pode fazer ela ficar
    // reenviando o mesmo evento em looping.
    echo json_encode(['ok' => true, 'ignorado' => 'payload nao-json']);
    exit;
}

/**
 * Texto da mensagem, seja qual for o formato que a Evolution mandar.
 * Mensagem simples vem em conversation; com citação ou formatação, em
 * extendedTextMessage.
 */
function wcTextoDaMensagem(array $msg): string
{
    foreach ([
        $msg['conversation'] ?? null,
        $msg['extendedTextMessage']['text'] ?? null,
        $msg['imageMessage']['caption'] ?? null,
        $msg['videoMessage']['caption'] ?? null,
    ] as $t) {
        if (is_string($t) && trim($t) !== '') return trim($t);
    }
    return '';
}

/**
 * Quem mandou a mensagem, com o número de telefone quando ele existir.
 *
 * Em grupo o remoteJid é o grupo e a pessoa vem no participant. O problema é
 * que o WhatsApp migrou para LID: em vez de 5511999999999@s.whatsapp.net, o
 * participant passa a vir como 123456789012345@lid, um identificador interno
 * que NÃO é o telefone. Quem lê só o participant acha que tem o número, faz a
 * busca no cadastro e não encontra ninguém — que foi exatamente o que
 * aconteceu com o /meuelenco.
 *
 * O número de verdade, quando o LID está em uso, vem num campo paralelo. O
 * nome dele mudou entre versões do Baileys e da Evolution, então tento todos
 * os conhecidos e fico com o primeiro que for um JID de telefone.
 *
 * Devolve o JID escolhido, ou o LID se não houver telefone nenhum — melhor um
 * identificador estável do que string vazia, porque o voto do quiz precisa
 * distinguir uma pessoa da outra mesmo sem saber quem é.
 */
function wcRemetenteDaMensagem(array $m): string
{
    $candidatos = [
        $m['key']['participantPn']  ?? null,
        $m['key']['participantAlt'] ?? null,
        $m['key']['senderPn']       ?? null,
        $m['participantPn']         ?? null,
        $m['participantAlt']        ?? null,
        $m['key']['participant']    ?? null,
        $m['participant']           ?? null,
    ];

    $reserva = '';
    foreach ($candidatos as $c) {
        $c = trim((string)$c);
        if ($c === '') continue;
        // @s.whatsapp.net é telefone; @lid é o id interno.
        if (str_contains($c, '@s.whatsapp.net') || !str_contains($c, '@')) return $c;
        if ($reserva === '') $reserva = $c;
    }
    return $reserva;
}

/* ══════════════════════════════════════════════════════════════════════════
   FALAR COM O BOT SEM DIGITAR COMANDO.

   Até aqui o bot só existia depois de uma barra. Isso o deixava com cara de
   máquina de consulta: ninguém "conversa" digitando /duvida, e a pergunta
   seguinte — "e o segundo?" — não chegava nele.

   Agora ele também atende quando o MARCAM e quando RESPONDEM uma mensagem
   dele. As duas coisas são o jeito natural de chamar alguém num grupo.
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * O número do próprio bot, aprendido sozinho e guardado.
 *
 * Não existe em configuração nenhuma, e pedir pro admin digitar seria mais um
 * campo pra ficar errado depois de uma troca de número. Mas ele passa por aqui
 * o tempo todo: `sender` no topo do evento, e o `participant` de qualquer
 * mensagem que o próprio bot mandou (fromMe). Aprende na primeira e guarda.
 */
function wcNumeroDoBot(PDO $pdo, array $evento, array $mensagens): string
{
    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        if (!$pdo->query("SHOW COLUMNS FROM whatsapp_config LIKE 'numero_bot'")->fetch()) {
            $pdo->exec("ALTER TABLE whatsapp_config ADD COLUMN numero_bot VARCHAR(40) NULL");
        }
        $guardado = (string)($pdo->query("SELECT numero_bot FROM whatsapp_config WHERE id = 1")->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $guardado = '';
    }

    $so = fn($v) => preg_replace('/\D+/', '', explode('@', (string)$v)[0] ?? '');

    // O que veio agora vale mais que o guardado: número trocado se corrige
    // sozinho na primeira mensagem depois da troca.
    $achado = $so($evento['sender'] ?? '');
    if ($achado === '' || strlen($achado) < 8) {
        foreach ($mensagens as $m) {
            if (empty($m['key']['fromMe'])) continue;
            foreach (['participantPn', 'participant'] as $k) {
                $c = $so($m['key'][$k] ?? '');
                if (strlen($c) >= 8) { $achado = $c; break 2; }
            }
        }
    }

    if ($achado !== '' && $achado !== $guardado) {
        try {
            $pdo->prepare("UPDATE whatsapp_config SET numero_bot = ? WHERE id = 1")->execute([$achado]);
        } catch (Throwable $e) {
            error_log('[whatsapp] guardar numero do bot: ' . $e->getMessage());
        }
        return $cache = $achado;
    }
    return $cache = ($achado !== '' ? $achado : $guardado);
}

/** O bot foi marcado nesta mensagem? */
function wcMarcaramOBot(array $m, string $texto, string $numeroBot): bool
{
    if ($numeroBot === '') return false;

    // No texto a menção chega como "@5511999999999" — é assim que o WhatsApp
    // a escreve, e é o mesmo formato que o /duvida já resolve pros GMs.
    if (str_contains($texto, '@' . $numeroBot)) return true;

    // E na lista formal de marcados, que é o que a etiqueta azul usa.
    $ctx = $m['message']['extendedTextMessage']['contextInfo'] ?? [];
    foreach ((array)($ctx['mentionedJid'] ?? []) as $jid) {
        if (preg_replace('/\D+/', '', explode('@', (string)$jid)[0] ?? '') === $numeroBot) return true;
    }
    return false;
}

/**
 * A mensagem é uma resposta a algo que o BOT disse?
 *
 * `contextInfo.participant` é o autor da mensagem citada. Quando ela é do bot,
 * responder a ela é falar com ele — do mesmo jeito que responder alguém no
 * grupo é falar com essa pessoa.
 */
function wcResponderamAoBot(array $m, string $numeroBot): bool
{
    if ($numeroBot === '') return false;
    $ctx = $m['message']['extendedTextMessage']['contextInfo'] ?? [];
    foreach (['participantPn', 'participant'] as $k) {
        $c = preg_replace('/\D+/', '', explode('@', (string)($ctx[$k] ?? ''))[0] ?? '');
        if ($c !== '' && $c === $numeroBot) return true;
    }
    return false;
}

/**
 * A Evolution reentrega o evento quando o webhook demora ou devolve erro — e
 * também manda messages.upsert mais de uma vez em alguns casos. Sem trava, o
 * mesmo /cap seria respondido duas, três vezes. Guardo o id da mensagem: quem
 * já passou por aqui não passa de novo.
 */
$pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_msgs_vistas (
    msg_id VARCHAR(96) NOT NULL PRIMARY KEY,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function wcMensagemInedita(PDO $pdo, string $msgId): bool
{
    if ($msgId === '') return true;   // sem id não dá pra deduplicar
    try {
        $st = $pdo->prepare("INSERT IGNORE INTO whatsapp_msgs_vistas (msg_id) VALUES (?)");
        $st->execute([$msgId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('[whatsapp] dedupe: ' . $e->getMessage());
        return true;
    }
}

// A tabela só serve pra deduplicar o que chegou agora; nada aqui vale pra
// ontem. Limpo de vez em quando pra não crescer sem fim.
if (random_int(1, 50) === 1) {
    $pdo->exec("DELETE FROM whatsapp_msgs_vistas WHERE criado_em < NOW() - INTERVAL 2 DAY");
}

$dados = $evento['data'] ?? [];
// A Evolution manda ora um objeto, ora uma lista de mensagens.
$mensagens = isset($dados['key']) ? [$dados] : (is_array($dados) ? $dados : []);

$gruposPermitidos = whatsappGruposDeComando($pdo);
$respondidas = 0;

/* ANTES DO LAÇO, e não dentro dele.
   O número do bot é aprendido, entre outras fontes, das mensagens que o
   PRÓPRIO bot mandou (fromMe) — e essas o laço descarta na primeira linha.
   Aprendendo aqui, um evento só de fromMe (que é o mais comum logo depois de
   ele responder) também ensina. */
$numeroBot = wcNumeroDoBot($pdo, $evento, $mensagens);

foreach ($mensagens as $m) {
    if (!is_array($m) || !isset($m['key'])) continue;

    // Mensagem do próprio bot: ignorar, senão ele responde a si mesmo.
    if (!empty($m['key']['fromMe'])) continue;

    $de = (string)($m['key']['remoteJid'] ?? '');

    // Anota o grupo antes de decidir se atende. O id de grupo do WhatsApp é
    // um número de 18 dígitos que ninguém tem como digitar de cabeça, e é
    // justamente ele que o cadastro pede — sem essa anotação, habilitar um
    // grupo novo virava caça ao JID no log da Evolution.
    whatsappAnotarGrupoVisto($pdo, $de, $m);

    // Arquiva pro Painel do Bot ANTES do filtro de comando: o painel mostra
    // a conversa, não só o que virou `/comando`. Não grava nada enquanto a
    // captura estiver desligada, que é como ela nasce.
    whatsappGravarConversa($pdo, $de, $m, wcRemetenteDaMensagem($m));

    $texto = wcTextoDaMensagem($m['message'] ?? []);
    if ($texto === '') continue;

    /* TRÊS JEITOS DE FALAR COM ELE: a barra de sempre, marcar o bot, e
       responder uma mensagem dele. Os dois últimos entram como /duvida —
       é o comando que aceita pergunta em português.

       O gatilho fica registrado porque muda o que se entende do número de
       uso: "o bot foi usado 300 vezes" é outra coisa quando metade vem de
       conversa e não de comando. */
    $gatilho = 'comando';

    if ($texto[0] !== '/') {
        if (wcMarcaramOBot($m, $texto, $numeroBot)) {
            $gatilho = 'mencao';
        } elseif (wcResponderamAoBot($m, $numeroBot)) {
            $gatilho = 'resposta';
        } else {
            continue;   // conversa do grupo que não é com ele
        }

        // A menção sai do texto: "@5511... quem lidera?" vira "quem lidera?".
        // Deixá-la faria o modelo tentar descobrir de quem é aquele número.
        $limpo = trim(preg_replace('/@' . preg_quote($numeroBot, '/') . '\b/', '', $texto));
        if ($limpo === '') {
            // Marcaram o bot e não perguntaram nada: cai no /duvida sem argumento,
            // que é justamente a lista de exemplos do que dá pra perguntar.
            $texto = '/duvida';
        } else {
            $texto = '/duvida ' . $limpo;
        }
    }

    // Só os grupos cadastrados. Sem isso, qualquer conversa privada que
    // chegasse na instância viraria consulta ao banco da liga.
    //
    // A exceção é /quizaqui, e ela existe por necessidade: o comando serve pra
    // CADASTRAR o grupo, então exigir que ele já esteja cadastrado seria pedir
    // a chave que está trancada dentro. Ele não lê nada do banco da liga — só
    // confere se quem digitou é admin e grava o destino do quiz.
    $ehQuizAqui = strtolower(ltrim(explode(' ', trim($texto))[0], '/')) === 'quizaqui';
    if (!isset($gruposPermitidos[$de]) && !$ehQuizAqui) continue;

    // Freio contra enxurrada — alguém segurando o comando, ou a Evolution
    // despejando um backlog inteiro depois de ficar fora do ar.
    //
    // Conta só ESTE grupo. Era global, e doze comandos num grupo calavam o bot
    // em todos os outros.
    //
    // E vem ANTES de marcar a mensagem como vista. Na ordem antiga o comando
    // barrado já tinha sido registrado, então um reenvio da Evolution o
    // ignorava por "já visto": quem digitou /time lakers não era respondido
    // naquele momento nem nunca, e o único rastro era uma linha no log.
    $stFreio = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_fila
                              WHERE tipo = 'comando' AND destino = ?
                                AND created_at > NOW() - INTERVAL 1 MINUTE");
    $stFreio->execute([$de]);
    if ((int)$stFreio->fetchColumn() >= 12) {
        error_log('[whatsapp] limite de comandos por minuto atingido no grupo ' . $de);
        continue;   // outros grupos seguem atendidos
    }

    // Só depois de saber que é comando E que passou do freio: não vale poluir
    // a tabela com toda conversa do grupo, nem queimar o id de quem foi barrado.
    if (!wcMensagemInedita($pdo, (string)($m['key']['id'] ?? ''))) continue;

    // Quem falou. Sem isso não existe placar — só resposta solta.
    $deQuem = wcRemetenteDaMensagem($m);

    // Quando só vem LID, nenhum comando que depende de saber quem é a pessoa
    // vai funcionar, e o sintoma ("não achei seu cadastro") aponta pro lugar
    // errado — o dono do número vai conferir o cadastro dele, que está certo.
    // Uma linha no log é o que separa "o telefone está errado" de "a Evolution
    // parou de mandar o telefone".
    if (str_contains($deQuem, '@lid')) {
        error_log('[whatsapp] remetente veio só como LID (' . $deQuem . ') no grupo ' . $de
                . ' — sem telefone, os comandos "meus" não acham o cadastro');
    }

    // A liga do grupo vira contexto: no Chat Off da NEXT, /classificacao sem
    // argumento responde a NEXT em vez de assumir ELITE.
    $resposta = wcResponderComando($pdo, $texto, $gruposPermitidos[$de]['liga'] ?? null, $deQuem, $de, $gatilho);
    if ($resposta === null) continue;   // comando desconhecido: silêncio
    if ($resposta === '') continue;     // atendido em silêncio (voto de quiz)

    /* QUEM PEDIU E O QUÊ ficam gravados junto da resposta.
       Sem isso a fila só dizia em que grupo o bot falou: não dava pra montar
       um ranking de quem mais usa, e o comando tinha que ser adivinhado pelo
       formato do texto da resposta. $deQuem já estava aqui, calculado pros
       comandos "meus" — só não era guardado. */
    whatsappEnfileirar($pdo, $de, $resposta, true, 'comando', null, null,
                       $deQuem, wcNomeDoComando($texto));
    $respondidas++;
}

echo json_encode(['ok' => true, 'respondidas' => $respondidas]);
