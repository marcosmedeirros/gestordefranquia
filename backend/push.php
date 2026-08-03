<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/whatsapp.php';

/**
 * Envia o aviso pro usuário — push do navegador e, pra quem ligou o opt-in,
 * WhatsApp também.
 *
 * $tipo é uma chave de getNotifCatalog(). Quando informado, respeita o que o GM
 * escolheu em Minha Conta (vale pros dois canais). Sem tipo (ou tipo
 * desconhecido) o envio é sempre feito — é o caso dos avisos internos pro admin.
 *
 * O nome continua sendPushToUser porque são ~15 pontos de chamada espalhados;
 * o WhatsApp entra aqui dentro pra todos ganharem de uma vez.
 */
function sendPushToUser(PDO $pdo, int $userId, array $data, ?string $tipo = null): void
{
    if (!userWantsNotif($pdo, $userId, $tipo)) return;

    // Antes do push: se o WhatsApp falhar, o push ainda sai (e vice-versa).
    try {
        whatsappParaUsuario($pdo, $userId, whatsappTextoDoAviso($data), $tipo);
    } catch (Throwable $e) {
        error_log('[whatsapp] aviso user_id=' . $userId . ': ' . $e->getMessage());
    }

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        error_log('[push] vendor/autoload.php não encontrado — rode: composer install');
        return;
    }

    $configFile = __DIR__ . '/vapid-config.php';
    if (!file_exists($configFile)) {
        error_log('[push] vapid-config.php não encontrado — rode: php setup-push.php');
        return;
    }

    require_once $autoload;
    $config = require $configFile;

    try {
        $stmt = $pdo->prepare('SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('[push] DB error: ' . $e->getMessage());
        return;
    }

    if (!$subscriptions) return;

    $auth = [
        'VAPID' => [
            'subject'    => $config['vapid_subject'] ?? 'mailto:admin@fbabrasil.com.br',
            'publicKey'  => $config['vapid_public_key'],
            'privateKey' => $config['vapid_private_key'],
        ],
    ];

    $webPush = new \Minishlink\WebPush\WebPush($auth);

    foreach ($subscriptions as $sub) {
        $subscription = \Minishlink\WebPush\Subscription::create([
            'endpoint' => $sub['endpoint'],
            'keys'     => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth']],
        ]);
        $webPush->queueNotification($subscription, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    foreach ($webPush->flush() as $report) {
        if (!$report->isSuccess()) {
            error_log('[push] falhou user_id=' . $userId . ' reason=' . $report->getReason());
            if ($report->isSubscriptionExpired()) {
                try {
                    $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')
                        ->execute([$report->getEndpoint()]);
                } catch (Exception $e) {}
            }
        }
    }
}

/**
 * Manda o mesmo aviso pra todos os GMs de uma liga.
 *
 * $exceto = user_ids que não devem receber (normalmente quem causou o evento).
 * Nunca lança: notificação não pode derrubar a ação que a disparou.
 */
function sendPushToLeague(PDO $pdo, string $league, array $data, ?string $tipo = null, array $exceto = []): void
{
    try {
        $st = $pdo->prepare("SELECT DISTINCT u.id
                             FROM teams t JOIN users u ON u.id = t.user_id
                             WHERE t.league = ? AND t.user_id IS NOT NULL");
        $st->execute([strtoupper($league)]);
        $userIds = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log('[push-liga] ' . $e->getMessage());
        return;
    }

    $exceto = array_map('intval', $exceto);
    foreach ($userIds as $uid) {
        if (in_array((int)$uid, $exceto, true)) continue;
        try {
            sendPushToUser($pdo, (int)$uid, $data, $tipo);
        } catch (Throwable $e) {
            error_log('[push-liga] user_id=' . $uid . ' ' . $e->getMessage());
        }
    }
}

/** Aviso pro dono de um time (nada acontece se o time não tiver GM). */
function sendPushToTeam(PDO $pdo, int $teamId, array $data, ?string $tipo = null): void
{
    try {
        $st = $pdo->prepare('SELECT user_id FROM teams WHERE id = ? LIMIT 1');
        $st->execute([$teamId]);
        $uid = (int)($st->fetchColumn() ?: 0);
        if ($uid) sendPushToUser($pdo, $uid, $data, $tipo);
    } catch (Throwable $e) {
        error_log('[push-time] team_id=' . $teamId . ' ' . $e->getMessage());
    }
}
