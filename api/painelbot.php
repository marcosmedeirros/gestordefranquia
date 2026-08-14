<?php
/**
 * API do Painel do Bot — a caixa de entrada do WhatsApp da liga.
 *
 * Junta duas fontes que nunca se encontraram:
 *   ENTRADA  whatsapp_conversas, preenchida pelo webhook (se a captura
 *            estiver ligada).
 *   SAÍDA    whatsapp_fila, que sempre guardou tudo que o bot mandou.
 *
 * Elas não viram uma tabela só de propósito. A fila é o que o worker
 * consome; mexer nela pra virar arquivo de conversa misturaria "o que
 * ainda tem que sair" com "o que já foi dito", e a primeira coisa a
 * quebrar seria o envio.
 *
 * ACESSO: admin global apenas. Isto expõe conversa de grupo inteiro —
 * admin de liga não basta.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/whatsapp.php';

$user = getUserSession();
if (!$user) { http_response_code(401); echo json_encode(['erro' => 'Sessão expirada.']); exit; }

$pdo = db();
if (($user['user_type'] ?? 'jogador') !== 'admin') {
    http_response_code(403);
    echo json_encode(['erro' => 'Só administrador geral.']);
    exit;
}

ensureWhatsAppTables($pdo);
$acao = $_GET['acao'] ?? $_POST['acao'] ?? 'chats';

/**
 * Nome legível de um chat, na melhor fonte que existir.
 *
 * A ordem importa e já errou uma vez: o fallback pro `ultimo_autor` valia
 * pra qualquer chat, e num grupo o último autor é uma PESSOA — o The
 * Pathetic aparecia com o nome de quem tinha falado por último. Agora esse
 * fallback só vale em conversa privada, onde o último autor é mesmo o dono
 * do número.
 *
 * Pra grupo só entram nomes de grupo: o cadastro de comandos e o que o
 * worker sincroniza da Evolution.
 */
function pbNomes(PDO $pdo): array
{
    $nomes = [];
    try {
        foreach ($pdo->query("SELECT jid, nome FROM whatsapp_grupos_comando WHERE nome <> ''") as $r) {
            $nomes[$r['jid']] = $r['nome'];
        }
    } catch (Throwable $e) {}

    try {
        foreach ($pdo->query("SELECT jid, nome, ultimo_autor FROM whatsapp_grupos_vistos") as $r) {
            if (isset($nomes[$r['jid']])) continue;
            $ehGrupo = str_ends_with($r['jid'], '@g.us');
            // Nome de grupo veio da Evolution (worker). Pra grupo, é a única
            // fonte aceita além do cadastro.
            if (!empty($r['nome']))            { $nomes[$r['jid']] = $r['nome']; continue; }
            if (!$ehGrupo && $r['ultimo_autor']) $nomes[$r['jid']] = $r['ultimo_autor'];
        }
    } catch (Throwable $e) {}
    return $nomes;
}

/** Rótulo de grupo sem nome conhecido: honesto, e nunca o nome de alguém. */
function pbRotuloGrupo(string $jid): string
{
    $n = explode('@', $jid)[0];
    return 'Grupo ···' . substr($n, -4);
}

/** JID → telefone legível, quando for conversa privada. */
function pbTelefone(string $jid): string
{
    if (!str_contains($jid, '@s.whatsapp.net')) return '';
    $n = preg_replace('/\D/', '', explode('@', $jid)[0]);
    if (strlen($n) < 12) return $n;
    return '+' . substr($n, 0, 2) . ' ' . substr($n, 2, 2) . ' ' . substr($n, 4);
}

// ── Lista de conversas ───────────────────────────────────────────────
if ($acao === 'chats') {
    $nomes = pbNomes($pdo);

    // Uma linha por chat, com a última fala de qualquer um dos dois lados.
    // O UNION junta entrada e saída antes de agrupar — agrupar cada tabela
    // separado daria duas linhas pro mesmo chat.
    $sql = "
        SELECT jid, eh_grupo, MAX(quando) AS quando, COUNT(*) AS total,
               SUBSTRING_INDEX(GROUP_CONCAT(texto ORDER BY quando DESC SEPARATOR '\\x1f'), '\\x1f', 1) AS ultima,
               SUBSTRING_INDEX(GROUP_CONCAT(direcao ORDER BY quando DESC SEPARATOR '\\x1f'), '\\x1f', 1) AS ultima_direcao
        FROM (
            SELECT jid, eh_grupo, criado_em AS quando,
                   COALESCE(texto, CONCAT('[', tipo_midia, ']')) AS texto, 'in' AS direcao
            FROM whatsapp_conversas
            UNION ALL
            SELECT destino AS jid, eh_grupo, COALESCE(enviado_em, created_at) AS quando,
                   texto, 'out' AS direcao
            FROM whatsapp_fila
        ) t
        GROUP BY jid, eh_grupo
        ORDER BY quando DESC
        LIMIT 200";
    $chats = [];
    foreach ($pdo->query($sql) as $r) {
        $jid = $r['jid'];
        $chats[] = [
            'jid'      => $jid,
            'grupo'    => (int)$r['eh_grupo'] === 1,
            'nome'     => $nomes[$jid] ?? (str_ends_with($jid, '@g.us') ? pbRotuloGrupo($jid) : (pbTelefone($jid) ?: $jid)),
            'ultima'   => mb_substr((string)$r['ultima'], 0, 90),
            'direcao'  => $r['ultima_direcao'],
            'quando'   => $r['quando'],
            'total'    => (int)$r['total'],
        ];
    }
    echo json_encode(['ok' => true, 'chats' => $chats, 'captura' => whatsappCaptura($pdo)]);
    exit;
}

// ── Uma conversa ─────────────────────────────────────────────────────
if ($acao === 'mensagens') {
    $jid = trim((string)($_GET['jid'] ?? ''));
    if ($jid === '') { http_response_code(400); echo json_encode(['erro' => 'jid obrigatório']); exit; }
    $limite = max(20, min(300, (int)($_GET['limite'] ?? 120)));

    // As duas pontas na mesma linha do tempo. `pendente` é o que ainda não
    // saiu da fila — o painel mostra em cinza, como o WhatsApp faz.
    $st = $pdo->prepare("
        SELECT * FROM (
            SELECT criado_em AS quando, COALESCE(texto, CONCAT('[', tipo_midia, ']')) AS texto,
                   'in' AS direcao, autor_nome, autor_jid, NULL AS erro, 1 AS entregue, id
            FROM whatsapp_conversas WHERE jid = ?
            UNION ALL
            SELECT COALESCE(enviado_em, created_at) AS quando, texto,
                   'out' AS direcao, NULL, NULL, ultimo_erro,
                   CASE WHEN enviado_em IS NULL THEN 0 ELSE 1 END, id
            FROM whatsapp_fila WHERE destino = ?
        ) t ORDER BY quando DESC, id DESC LIMIT {$limite}");
    $st->execute([$jid, $jid]);
    $msgs = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));

    $nomes = pbNomes($pdo);
    echo json_encode([
        'ok' => true,
        'jid' => $jid,
        'nome' => $nomes[$jid] ?? (str_ends_with($jid, '@g.us') ? pbRotuloGrupo($jid) : (pbTelefone($jid) ?: $jid)),
        'grupo' => str_ends_with($jid, '@g.us'),
        'mensagens' => array_map(fn($m) => [
            'quando'   => $m['quando'],
            'texto'    => (string)$m['texto'],
            'direcao'  => $m['direcao'],
            'autor'    => $m['autor_nome'] ?: null,
            'entregue' => (int)$m['entregue'] === 1,
            'erro'     => $m['erro'] ?: null,
        ], $msgs),
    ]);
    exit;
}

// ── Enviar ───────────────────────────────────────────────────────────
if ($acao === 'enviar') {
    $jid   = trim((string)($_POST['jid'] ?? ''));
    $texto = trim((string)($_POST['texto'] ?? ''));
    if ($jid === '' || $texto === '') {
        http_response_code(400); echo json_encode(['erro' => 'jid e texto obrigatórios']); exit;
    }
    if (mb_strlen($texto) > 4000) {
        http_response_code(400); echo json_encode(['erro' => 'mensagem longa demais']); exit;
    }

    // tipo 'manual' de propósito: é o único, junto de 'comando', que o
    // worker entrega fora da janela de 08:45–18:00. Quem digitou está
    // olhando pra tela agora e espera que saia agora.
    $ok = whatsappEnfileirar($pdo, $jid, $texto, str_ends_with($jid, '@g.us'), 'manual', (int)$user['id']);
    if (!$ok) {
        http_response_code(409);
        echo json_encode(['erro' => 'O bot está desligado na Central da Liga — a mensagem não foi enfileirada.']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── Ligar/desligar o arquivo ─────────────────────────────────────────
if ($acao === 'captura') {
    $modo = strtolower(trim((string)($_POST['modo'] ?? '')));
    if (!in_array($modo, ['tudo', 'grupos', 'pv', 'off'], true)) {
        http_response_code(400); echo json_encode(['erro' => 'modo inválido']); exit;
    }
    $jids = array_slice(array_values(array_filter(array_map('trim',
        explode(',', (string)($_POST['jids'] ?? ''))))), 0, 60);

    $pdo->prepare("UPDATE whatsapp_config SET captura = ?, captura_jids = ? WHERE id = 1")
        ->execute([$modo, implode(',', $jids)]);
    echo json_encode(['ok' => true, 'captura' => ['modo' => $modo, 'jids' => $jids]]);
    exit;
}

// ── Apagar o arquivo de uma conversa ─────────────────────────────────
//
// Existe porque um arquivo sem botão de apagar é um arquivo que ninguém
// controla. Só o que ENTROU: a fila é registro de envio e continua.
if ($acao === 'apagar') {
    $jid = trim((string)($_POST['jid'] ?? ''));
    if ($jid === '') { http_response_code(400); echo json_encode(['erro' => 'jid obrigatório']); exit; }
    $st = $pdo->prepare("DELETE FROM whatsapp_conversas WHERE jid = ?");
    $st->execute([$jid]);
    echo json_encode(['ok' => true, 'apagadas' => $st->rowCount()]);
    exit;
}

http_response_code(400);
echo json_encode(['erro' => 'ação desconhecida']);
