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
function wcNumeroDoBot(PDO $pdo, array $evento, array $mensagens): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        foreach (['numero_bot', 'lid_bot'] as $col) {
            if (!$pdo->query("SHOW COLUMNS FROM whatsapp_config LIKE '{$col}'")->fetch()) {
                $pdo->exec("ALTER TABLE whatsapp_config ADD COLUMN {$col} VARCHAR(40) NULL");
            }
        }
        $g = $pdo->query("SELECT numero_bot, lid_bot FROM whatsapp_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $g = [];
    }
    $numero = (string)($g['numero_bot'] ?? '');
    $lid    = (string)($g['lid_bot'] ?? '');

    /* O LID TAMBÉM, e não só o telefone.
       O WhatsApp migrou pra LID — um id interno que NÃO é o número — e este
       projeto já apanhou disso no /meuelenco. Numa menção, o que vai no texto
       e no mentionedJid pode ser o LID; comparar só com o telefone erra 100%
       das vezes nesse caso, e sem sintoma nenhum. */
    $achaNo = function ($v) use (&$numero, &$lid) {
        $v = (string)$v;
        $d = preg_replace('/\D+/', '', explode('@', $v)[0] ?? '');
        if (strlen($d) < 8) return;
        if (str_contains($v, '@lid'))                 { $lid = $d; return; }
        if (str_contains($v, '@s.whatsapp.net'))      { $numero = $d; return; }
        if ($numero === '') $numero = $d;
    };

    $achaNo($evento['sender'] ?? '');
    foreach ($mensagens as $m) {
        if (empty($m['key']['fromMe'])) continue;
        foreach (['participantPn', 'participantAlt', 'participant', 'senderPn'] as $k) {
            $achaNo($m['key'][$k] ?? '');
        }
    }

    if ($numero !== (string)($g['numero_bot'] ?? '') || $lid !== (string)($g['lid_bot'] ?? '')) {
        try {
            $pdo->prepare("UPDATE whatsapp_config SET numero_bot = ?, lid_bot = ? WHERE id = 1")
                ->execute([$numero ?: null, $lid ?: null]);
        } catch (Throwable $e) {
            error_log('[whatsapp] guardar identidade do bot: ' . $e->getMessage());
        }
    }
    // Os dois vão juntos: quem compara tem que aceitar qualquer um dos dois.
    return $cache = array_values(array_filter([$numero, $lid], fn($x) => $x !== ''));
}

/**
 * Todo contextInfo que a Evolution possa ter posto na mensagem.
 *
 * O campo muda de lugar conforme o tipo: texto puro com citação vai em
 * extendedTextMessage, legenda de imagem em imageMessage, e algumas versões
 * repetem no topo. Procurar num lugar só é como o primeiro teste falhou.
 */
function wcContextos(array $m): array
{
    $msg = $m['message'] ?? [];
    $ctx = [];
    foreach (['extendedTextMessage', 'imageMessage', 'videoMessage', 'documentMessage',
              'audioMessage', 'stickerMessage'] as $k) {
        if (isset($msg[$k]['contextInfo'])) $ctx[] = (array)$msg[$k]['contextInfo'];
    }
    if (isset($msg['contextInfo'])) $ctx[] = (array)$msg['contextInfo'];
    if (isset($m['contextInfo']))   $ctx[] = (array)$m['contextInfo'];
    return $ctx;
}

/** Só os dígitos de um JID: "5511999@s.whatsapp.net" -> "5511999". */
function wcDigitos($v): string
{
    return preg_replace('/\D+/', '', explode('@', (string)$v)[0] ?? '');
}

/**
 * Dois identificadores são a mesma pessoa?
 *
 * Compara inteiro e, não batendo, pelos últimos 8 dígitos — a mesma rede de
 * segurança que o /meucap usa pra cadastro sem DDI ou sem o 9. Aqui ela vale
 * dobrado: o número que o WhatsApp escreve numa menção nem sempre é o mesmo
 * que ele manda no `sender`.
 */
function wcMesmoNumero(string $a, string $b): bool
{
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    return strlen($a) >= 8 && strlen($b) >= 8 && substr($a, -8) === substr($b, -8);
}

/** O bot foi marcado nesta mensagem? */
function wcMarcaramOBot(array $m, string $texto, array $ids): bool
{
    if (!$ids) return false;

    // No texto a menção chega como "@5511999999999" — ou como o LID, que é
    // outro número. Comparo cada @número do texto com TODAS as identidades
    // conhecidas do bot, em vez de procurar uma string exata.
    if (preg_match_all('/@(\d{8,20})/', $texto, $ms)) {
        foreach ($ms[1] as $n) foreach ($ids as $id) if (wcMesmoNumero($n, $id)) return true;
    }

    // E na lista formal de marcados, que é o que a etiqueta azul usa.
    foreach (wcContextos($m) as $ctx) {
        foreach ((array)($ctx['mentionedJid'] ?? []) as $jid) {
            foreach ($ids as $id) if (wcMesmoNumero(wcDigitos($jid), $id)) return true;
        }
    }
    return false;
}

/**
 * REGISTRA O QUE VEIO NUMA MENÇÃO QUE NÃO FOI RECONHECIDA.
 *
 * O primeiro teste no grupo não funcionou e o log não tinha nada — não dava
 * pra saber se o webhook nem chegou, se o número não bateu, ou se a Evolution
 * põe a menção em outro lugar. Sem o payload na mão isso vira adivinhação.
 *
 * Só os campos de MENÇÃO, nunca o texto: o conteúdo da conversa do grupo não
 * é assunto do log de erro. E só quando há um "@" na mensagem, senão isto
 * escreveria uma linha por conversa do grupo inteiro.
 */
function wcLogMencaoNaoReconhecida(array $m, string $texto, array $ids, string $grupo, bool $registrado): void
{
    if (!str_contains($texto, '@')) return;

    $arrobas = [];
    if (preg_match_all('/@(\d{6,20})/', $texto, $ms)) $arrobas = $ms[1];

    $marcados = [];
    foreach (wcContextos($m) as $ctx) {
        foreach ((array)($ctx['mentionedJid'] ?? []) as $jid) $marcados[] = (string)$jid;
        foreach (['participant', 'participantPn'] as $k) {
            if (!empty($ctx[$k])) $marcados[] = 'citou:' . $ctx[$k];
        }
    }

    error_log('[whatsapp/mencao] nao reconhecida'
            . ' | grupo=' . $grupo . ($registrado ? ' (cadastrado)' : ' (NAO CADASTRADO)')
            . ' | bot=' . (implode('+', $ids) ?: 'DESCONHECIDO')
            . ' | @ no texto=' . (implode(',', $arrobas) ?: '-')
            . ' | mentionedJid=' . (implode(',', array_slice($marcados, 0, 6)) ?: '-'));
}

/**
 * A mensagem é uma resposta a algo que o BOT disse?
 *
 * `contextInfo.participant` é o autor da mensagem citada. Quando ela é do bot,
 * responder a ela é falar com ele — do mesmo jeito que responder alguém no
 * grupo é falar com essa pessoa.
 */
function wcResponderamAoBot(array $m, array $ids): bool
{
    if (!$ids) return false;
    foreach (wcContextos($m) as $ctx) {
        foreach (['participantPn', 'participantAlt', 'participant', 'remoteJid'] as $k) {
            foreach ($ids as $id) if (wcMesmoNumero(wcDigitos($ctx[$k] ?? ''), $id)) return true;
        }
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
$idsDoBot = wcNumeroDoBot($pdo, $evento, $mensagens);

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
        if (wcMarcaramOBot($m, $texto, $idsDoBot)) {
            $gatilho = 'mencao';
        } elseif (wcResponderamAoBot($m, $idsDoBot)) {
            $gatilho = 'resposta';
        } else {
            // Tinha "@" e não era ele? Deixa o rastro pra saber por quê —
            // menção que não é reconhecida é indistinguível de webhook que
            // não chegou, e as duas se consertam de jeitos diferentes.
            wcLogMencaoNaoReconhecida($m, $texto, $idsDoBot, $de, isset($gruposPermitidos[$de]));
            continue;   // conversa do grupo que não é com ele
        }

        /* A menção sai do texto: "@5511... quem lidera?" vira "quem lidera?".
           Deixá-la faria o modelo tentar descobrir de quem é aquele número.

           Tiro QUALQUER @número que seja ele, e não a string exata do número
           guardado: o WhatsApp escreve a menção com ou sem o 9, e um casamento
           literal deixaria o número na frente da pergunta. */
        $limpo = trim(preg_replace_callback('/@(\d{8,20})/',
            function ($mm) use ($idsDoBot) {
                foreach ($idsDoBot as $id) if (wcMesmoNumero($mm[1], $id)) return '';
                return $mm[0];
            }, $texto));
        $limpo = trim(preg_replace('/\s+/', ' ', $limpo));
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
