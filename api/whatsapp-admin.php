<?php
/**
 * Configuração da integração de WhatsApp (Evolution API) — só admin geral.
 *
 * Guarda a instância (URL, nome, chave) e o id do grupo de cada liga, e expõe
 * um teste de envio pra conferir que a instância responde antes de ligar.
 */

require_once __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/whatsapp.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$user = getUserSession();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}
// Integração vale pra plataforma inteira, então é do admin geral — admin de
// liga configura só o grupo da liga dele (ver 'salvar_grupo').
$ehAdminGeral = ($user['user_type'] ?? '') === 'admin';
$minhasLigas  = array_map('strtoupper', getAdminLeagues($pdo, (int)$user['id']));

ensureWhatsAppTables($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$LIGAS  = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

if ($method === 'GET') {
    if (!$minhasLigas) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
        exit;
    }

    $cfg = $pdo->query("SELECT base_url, instancia, api_key, ativo, bot_token, bot_visto_em FROM whatsapp_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $grupos = [];
    foreach ($pdo->query("SELECT league, COALESCE(whatsapp_group_id, '') AS grupo FROM league_settings")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $grupos[strtoupper($r['league'])] = $r['grupo'];
    }

    $fila = $pdo->query("SELECT
            SUM(enviado_em IS NULL AND tentativas < " . WHATSAPP_MAX_TENTATIVAS . ") AS pendentes,
            SUM(enviado_em IS NOT NULL) AS enviadas,
            SUM(enviado_em IS NULL AND tentativas >= " . WHATSAPP_MAX_TENTATIVAS . ") AS desistidas
        FROM whatsapp_fila")->fetch(PDO::FETCH_ASSOC) ?: [];

    $ultimoErro = $pdo->query("SELECT ultimo_erro FROM whatsapp_fila WHERE ultimo_erro IS NOT NULL ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null;

    echo json_encode([
        'success' => true,
        'config'  => [
            'base_url'  => $cfg['base_url'] ?? '',
            'instancia' => $cfg['instancia'] ?? '',
            // A chave nunca volta em claro — só diz se existe.
            'tem_api_key' => !empty($cfg['api_key']),
            'ativo'     => !empty($cfg['ativo']),
            // Token do worker local (bot/whatsapp-local.php). Volta em claro
            // porque é ele que precisa ser copiado pra máquina que roda a
            // Evolution — e só admin geral chega aqui.
            'bot_token'    => $ehAdminGeral ? ($cfg['bot_token'] ?? '') : '',
            'bot_visto_em' => $cfg['bot_visto_em'] ?? null,
        ],
        'grupos'       => $grupos,
        'ligas'        => $LIGAS,
        'minhas_ligas' => $minhasLigas,
        'admin_geral'  => $ehAdminGeral,
        'fila'         => [
            'pendentes'  => (int)($fila['pendentes'] ?? 0),
            'enviadas'   => (int)($fila['enviadas'] ?? 0),
            'desistidas' => (int)($fila['desistidas'] ?? 0),
            'ultimo_erro'=> $ultimoErro,
        ],
    ]);
    exit;
}

if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'salvar_config') {
        if (!$ehAdminGeral) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Apenas admin geral configura a instância.']);
            exit;
        }
        $baseUrl   = trim((string)($body['base_url'] ?? ''));
        $instancia = trim((string)($body['instancia'] ?? ''));
        $ativo     = !empty($body['ativo']) ? 1 : 0;

        $sets   = ['base_url = ?', 'instancia = ?', 'ativo = ?'];
        $params = [$baseUrl ?: null, $instancia ?: null, $ativo];
        // Chave em branco = manter a atual (ela nunca volta pro front).
        if (array_key_exists('api_key', $body) && trim((string)$body['api_key']) !== '') {
            $sets[]   = 'api_key = ?';
            $params[] = trim((string)$body['api_key']);
        }
        $pdo->prepare('UPDATE whatsapp_config SET ' . implode(', ', $sets) . ' WHERE id = 1')->execute($params);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'salvar_grupo') {
        $liga  = strtoupper(trim((string)($body['league'] ?? '')));
        $grupo = trim((string)($body['group_id'] ?? ''));
        if (!in_array($liga, $LIGAS, true)) {
            echo json_encode(['success' => false, 'error' => 'Liga inválida.']);
            exit;
        }
        if (!in_array($liga, $minhasLigas, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Você não administra a ' . $liga . '.']);
            exit;
        }
        $pdo->prepare("UPDATE league_settings SET whatsapp_group_id = ? WHERE league = ?")
            ->execute([$grupo !== '' ? $grupo : null, $liga]);
        echo json_encode(['success' => true]);
        exit;
    }

    // Envia uma mensagem de teste direto (sem fila) pra dar erro na hora.
    if ($action === 'testar') {
        if (!$minhasLigas) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
            exit;
        }
        $cfg = whatsappConfig($pdo);
        if (!$cfg) {
            echo json_encode(['success' => false, 'error' => 'Integração não está configurada ou está desativada.']);
            exit;
        }
        $destino = trim((string)($body['destino'] ?? ''));
        if ($destino === '') {
            echo json_encode(['success' => false, 'error' => 'Informe o número ou o id do grupo.']);
            exit;
        }
        // Número solto vira formato da Evolution; id de grupo (@g.us) passa direto.
        if (!str_contains($destino, '@')) {
            $destino = whatsappNumero($destino) ?? $destino;
        }

        [$ok, $erro] = whatsappPostar($cfg, $destino, '✅ Teste do FBA Manager — a integração de WhatsApp está funcionando.');
        echo json_encode($ok
            ? ['success' => true, 'message' => 'Mensagem enviada.']
            : ['success' => false, 'error' => $erro]);
        exit;
    }

    if ($action === 'esvaziar_fila') {
        if (!$minhasLigas) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
            exit;
        }
        $r = whatsappProcessarFila($pdo, 100);
        echo json_encode(['success' => true] + $r);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método inválido']);
