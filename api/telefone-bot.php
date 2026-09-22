<?php
/**
 * Salvar ou confirmar o número do popup de boas-vindas.
 *
 * Separado de api/user.php de propósito: lá o telefone anda junto com nome,
 * foto, cor e atalhos, e um popup que só quer o número não deveria ter que
 * mandar o perfil inteiro — nem correr o risco de apagar um campo que não
 * conhece. @see backend/telefone_bot.php
 */
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/backend/helpers.php';
require_once dirname(__DIR__) . '/backend/telefone_bot.php';

requireAuth();
$user = getUserSession();
$pdo  = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não suportado']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$acao   = (string)($body['acao'] ?? 'salvar');
$userId = (int)$user['id'];

if ($acao === 'depois') {
    // Adiar não carimba nada: o popup volta no próximo acesso, porque o
    // problema continua lá. O que ele faz é não travar quem entrou com pressa.
    echo json_encode(['success' => true, 'adiado' => true]);
    exit;
}

if ($acao === 'confirmar') {
    /* "É esse mesmo" só vale se o número realmente serve. Carimbar um número
       que o WhatsApp não acha faria a pessoa sair da lista de pendências
       seguindo sem receber nada — o silêncio que este popup existe pra
       evitar. */
    $st = $pdo->prepare('SELECT phone FROM users WHERE id = ?');
    $st->execute([$userId]);
    $numero = whatsappNumeroUsavel($st->fetchColumn() ?: null);
    if (!$numero['ok']) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => $numero['motivo'] ?: 'Número inválido.',
                          'sugestao' => $numero['sugestao'] ?? null]);
        exit;
    }
    telefoneBotConfirmar($pdo, $userId);
    echo json_encode(['success' => true]);
    exit;
}

// ── salvar ───────────────────────────────────────────────────────────
$phoneRaw = trim((string)($body['phone'] ?? ''));
if ($phoneRaw === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Digite o número com DDD.']);
    exit;
}

$phone = normalizeBrazilianPhone($phoneRaw);
if (!$phone) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Número inválido. Use DDD + número, só dígitos.']);
    exit;
}

// A mesma conferência que o bot faz na hora de mandar: se não passa aqui,
// não adianta salvar — a pessoa sairia do popup achando que resolveu.
$numero = whatsappNumeroUsavel($phone);
if (!$numero['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $numero['motivo'] ?: 'Número inválido.',
                      'sugestao' => $numero['sugestao'] ?? null]);
    exit;
}

try {
    $pdo->prepare('UPDATE users SET phone = ? WHERE id = ?')->execute([$phone, $userId]);
    telefoneBotConfirmar($pdo, $userId);
    $_SESSION['user_phone'] = $phone;
    echo json_encode(['success' => true, 'phone' => $phone, 'phone_display' => formatBrazilianPhone($phone)]);
} catch (Throwable $e) {
    error_log('[telefone-bot] salvar: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao salvar.']);
}
