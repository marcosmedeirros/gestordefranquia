<?php
function sendPushToUser(PDO $pdo, int $userId, array $data): void
{
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
