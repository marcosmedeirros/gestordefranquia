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
 *   { "url": "https://fbabrasil.com.br/api/whatsapp-webhook.php?token=<bot_token>",
 *     "events": ["MESSAGES_UPSERT"] }
 */
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/whatsapp.php';
require_once __DIR__ . '/whatsapp-comandos.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
ensureWhatsAppTables($pdo);

// ── Autenticação: mesmo token do worker ─────────────────────────────────
$enviado = (string)($_GET['token'] ?? '');
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

$grupoOficial = trim((string)($pdo->query("SELECT grupo_principal FROM whatsapp_config WHERE id = 1")->fetchColumn() ?: ''));
$respondidas = 0;

foreach ($mensagens as $m) {
    if (!is_array($m) || !isset($m['key'])) continue;

    // Mensagem do próprio bot: ignorar, senão ele responde a si mesmo.
    if (!empty($m['key']['fromMe'])) continue;

    $de = (string)($m['key']['remoteJid'] ?? '');
    // Só o grupo configurado. Sem isso, qualquer conversa privada que chegasse
    // na instância viraria consulta ao banco da liga.
    if ($grupoOficial === '' || $de !== $grupoOficial) continue;

    $texto = wcTextoDaMensagem($m['message'] ?? []);
    if ($texto === '' || $texto[0] !== '/') continue;

    // Só depois de saber que é comando: não vale poluir a tabela com toda
    // conversa do grupo.
    if (!wcMensagemInedita($pdo, (string)($m['key']['id'] ?? ''))) continue;

    // Freio contra enxurrada — alguém segurando o comando, ou a Evolution
    // despejando um backlog inteiro depois de ficar fora do ar.
    $recentes = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_fila
                                  WHERE tipo = 'comando' AND created_at > NOW() - INTERVAL 1 MINUTE")
                         ->fetchColumn();
    if ($recentes >= 12) {
        error_log('[whatsapp] limite de comandos por minuto atingido');
        break;
    }

    $resposta = wcResponderComando($pdo, $texto);
    if ($resposta === null) continue;   // comando desconhecido: silêncio

    whatsappEnfileirar($pdo, $de, $resposta, true, 'comando');
    $respondidas++;
}

echo json_encode(['ok' => true, 'respondidas' => $respondidas]);
