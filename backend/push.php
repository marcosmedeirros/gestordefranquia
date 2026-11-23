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

    sendPushRaw($pdo, $userId, $data);
}

/**
 * Manda o push de verdade, sem checar preferência nem WhatsApp — usada pelo
 * sendPushToUser() acima e pelo botão de teste em Minha Conta (api/push-test.php),
 * que precisa saber na hora se o navio saiu do porto ou não.
 *
 * Devolve quantas inscrições existiam e quantas o serviço de push aceitou.
 * "Aceitou" não é "o usuário viu": o navegador ainda decide se mostra o toast.
 * Mas é o sinal mais forte que dá pra ter sem estar olhando o celular da pessoa.
 */
function sendPushRaw(PDO $pdo, int $userId, array $data): array
{
    $vazio = ['total' => 0, 'sent' => 0, 'failed' => 0];

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        error_log('[push] vendor/autoload.php não encontrado — rode: composer install');
        return $vazio;
    }

    $configFile = __DIR__ . '/vapid-config.php';
    if (!file_exists($configFile)) {
        error_log('[push] vapid-config.php não encontrado — rode: php setup-push.php');
        return $vazio;
    }

    require_once $autoload;
    $config = require $configFile;

    try {
        $stmt = $pdo->prepare('SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('[push] DB error: ' . $e->getMessage());
        return $vazio;
    }

    if (!$subscriptions) return $vazio;

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

    $sent = 0;
    $failed = 0;
    foreach ($webPush->flush() as $report) {
        if ($report->isSuccess()) {
            $sent++;
            continue;
        }
        $failed++;
        error_log('[push] falhou user_id=' . $userId . ' reason=' . $report->getReason());
        if ($report->isSubscriptionExpired() || pushChaveNaoConfere($report->getReason())) {
            // A inscrição morreu do lado do navegador (desinstalou, trocou de
            // conta, etc). Limpa aqui — é o mesmo motivo de "diz que tá
            // ativado mas não chega nada": lixo acumulado que ninguém nota.
            try {
                $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')
                    ->execute([$report->getEndpoint()]);
            } catch (Exception $e) {}
        }
    }

    return ['total' => count($subscriptions), 'sent' => $sent, 'failed' => $failed];
}

/**
 * A INSCRIÇÃO FOI FEITA COM OUTRA CHAVE VAPID — e não tem volta.
 *
 * O navegador guarda, na inscrição, a chave pública com que ela nasceu. Quando
 * a chave do servidor é regerada (`setup-push.php`), a privada antiga some e
 * tudo que foi inscrito antes passa a ser impossível de assinar. O serviço de
 * push recusa pra sempre, cada um com o seu texto:
 *
 *   FCM   403 "the VAPID credentials in the authorization header do not
 *              correspond to the credentials used to create the subscriptions"
 *   Apple 400 {"reason":"VapidPkHashMismatch"}
 *
 * `isSubscriptionExpired()` não cobre isso — ele só olha 404 e 410 —, então
 * essas linhas ficavam no banco pra sempre. A chave da FBA foi regerada em
 * 17/05/2026 e as 52 inscrições anteriores seguiram sendo tentadas por seis
 * meses: ruído no log de erro e, pior, tempo gasto em toda notificação de liga.
 * Foi o que travou a loteria da NEXT em 23/11/2026 (@see sendPushToLeague).
 *
 * Apagar é o certo: a pessoa se reinscreve sozinha na próxima visita — o
 * js/pwa.js compara a chave do servidor com a da inscrição e refaz quando
 * diferem.
 */
function pushChaveNaoConfere(?string $motivo): bool
{
    if (!$motivo) return false;
    return str_contains($motivo, 'VapidPkHashMismatch')
        || str_contains($motivo, 'VAPID credentials in the authorization header');
}

/**
 * O MESMO AVISO PRA VÁRIOS GMs, NUM DISPARO SÓ.
 *
 * Mandar de um em um custava uma ida e volta HTTPS por inscrição, em fila. Com
 * ~30 GMs na liga e 5 inscrições guardadas por pessoa em média — endpoint que o
 * navegador trocou e ninguém limpou —, cada aviso de liga levava uns 18
 * segundos antes de a resposta sair.
 *
 * Na loteria da NEXT de 23/11/2026 isso derrubou a cerimônia: cada "revelar"
 * ficava preso nesses 18 segundos, as chamadas empilharam e as que estouraram o
 * tempo do servidor voltaram sem resposta. A tela disse "erro no servidor", e
 * seis das catorze picks não entraram no draft nem saíram no grupo.
 *
 * Aqui todas as inscrições entram na MESMA fila do WebPush e saem num flush só,
 * que o cliente dispara em paralelo. Trinta GMs passam a custar o tempo de um.
 *
 * Não checa preferência: quem chama já filtrou.
 */
function sendPushRawMany(PDO $pdo, array $userIds, array $data): array
{
    $vazio = ['total' => 0, 'sent' => 0, 'failed' => 0];

    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds) return $vazio;

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        error_log('[push] vendor/autoload.php não encontrado — rode: composer install');
        return $vazio;
    }

    $configFile = __DIR__ . '/vapid-config.php';
    if (!file_exists($configFile)) {
        error_log('[push] vapid-config.php não encontrado — rode: php setup-push.php');
        return $vazio;
    }

    require_once $autoload;
    $config = require $configFile;

    try {
        $marcas = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare("SELECT user_id, endpoint, p256dh, auth
                                 FROM push_subscriptions WHERE user_id IN ($marcas)");
        $stmt->execute($userIds);
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('[push] DB error: ' . $e->getMessage());
        return $vazio;
    }

    if (!$subscriptions) return $vazio;

    $webPush = new \Minishlink\WebPush\WebPush([
        'VAPID' => [
            'subject'    => $config['vapid_subject'] ?? 'mailto:admin@fbabrasil.com.br',
            'publicKey'  => $config['vapid_public_key'],
            'privateKey' => $config['vapid_private_key'],
        ],
    ]);

    // Pra dizer de quem era a inscrição quando o relatório voltar: ele devolve
    // o endpoint, não o dono.
    $donoDo  = [];
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
    foreach ($subscriptions as $sub) {
        $donoDo[$sub['endpoint']] = (int)$sub['user_id'];
        $webPush->queueNotification(
            \Minishlink\WebPush\Subscription::create([
                'endpoint' => $sub['endpoint'],
                'keys'     => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth']],
            ]),
            $payload
        );
    }

    $sent = 0;
    $failed = 0;
    $mortas = [];
    foreach ($webPush->flush() as $report) {
        if ($report->isSuccess()) {
            $sent++;
            continue;
        }
        $failed++;
        $endpoint = $report->getEndpoint();
        error_log('[push] falhou user_id=' . ($donoDo[$endpoint] ?? '?') . ' reason=' . $report->getReason());
        if ($report->isSubscriptionExpired() || pushChaveNaoConfere($report->getReason())) {
            $mortas[] = $endpoint;
        }
    }

    foreach ($mortas as $endpoint) {
        try {
            $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')->execute([$endpoint]);
        } catch (Exception $e) {}
    }

    return ['total' => count($subscriptions), 'sent' => $sent, 'failed' => $failed];
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
    $querem = [];
    foreach ($userIds as $uid) {
        $uid = (int)$uid;
        if (in_array($uid, $exceto, true)) continue;
        try {
            // A preferência é por pessoa e vale pros dois canais — é o mesmo
            // teste que sendPushToUser faz antes de qualquer coisa.
            if (!userWantsNotif($pdo, $uid, $tipo)) continue;
            $querem[] = $uid;
            // O WhatsApp depende de número e opt-in, então continua um a um;
            // mas ele só ENFILEIRA, e o envio já sai depois da resposta.
            whatsappParaUsuario($pdo, $uid, whatsappTextoDoAviso($data), $tipo);
        } catch (Throwable $e) {
            error_log('[push-liga] user_id=' . $uid . ' ' . $e->getMessage());
        }
    }

    if (!$querem) return;

    /* O PUSH SAI DEPOIS DA RESPOSTA, como o WhatsApp já fazia.
       Mesmo em paralelo, o flush depende do serviço de push do outro lado — e
       a ação que disparou o aviso não pode esperar por ele. A loteria é o caso
       extremo: quem conduz revela uma pick a cada poucos segundos, e cada
       resposta que demora é uma pick que pode não entrar. */
    register_shutdown_function(function () use ($pdo, $querem, $data) {
        try {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            sendPushRawMany($pdo, $querem, $data);
        } catch (Throwable $e) {
            error_log('[push-liga] envio: ' . $e->getMessage());
        }
    });
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

/**
 * Entrega a resposta ao navegador e SÓ DEPOIS manda as notificações.
 *
 * Enviar push é uma chamada de rede por inscrito. Numa liga com trinta GMs,
 * o botão do admin ficava segurando o clique até a última terminar — foi
 * por isso que o toggle de tática parecia travado enquanto os de trade e
 * free agency, que não notificavam nada, respondiam na hora.
 *
 * Com PHP-FPM o fastcgi_finish_request devolve a conexão e o processo segue
 * trabalhando. Sem ele não dá pra devolver a conexão, então o melhor que
 * existe é empurrar o buffer e seguir mesmo se o navegador desistir — o
 * clique continua parecendo lento, mas nada se perde.
 *
 * Falha de push nunca vira erro de API: a ação do admin já foi gravada e já
 * foi confirmada na tela. Só vai pro log.
 */
function responderEDepoisNotificar(array $resposta, callable $notificar): void
{
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resposta);

    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @flush();
    }

    try {
        $notificar();
    } catch (Throwable $e) {
        error_log('[push] notificacao em segundo plano: ' . $e->getMessage());
    }
}