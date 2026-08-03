<?php
/**
 * Envio de WhatsApp pela Evolution API.
 *
 * O app nunca fala WhatsApp direto: ele faz POST HTTP numa instância da
 * Evolution API (que roda fora daqui — Hostinger compartilhado não sustenta
 * processo persistente). Se a instância estiver fora do ar, o aviso entra na
 * fila e o cron reenvia; nada aqui pode derrubar a ação que disparou o aviso.
 *
 * Configuração: tabela whatsapp_config (linha única) + o id do grupo por liga
 * em league_settings.whatsapp_group_id. Tudo editável no Admin.
 *
 * Aviso: a Evolution API conversa com o WhatsApp como se fosse um celular
 * pareado. Isso contraria os termos de uso da Meta e o número PODE ser banido
 * — use sempre um número dedicado da liga, nunca o pessoal de alguém.
 */

require_once __DIR__ . '/helpers.php';

/** Quantas tentativas antes de desistir de uma mensagem na fila. */
const WHATSAPP_MAX_TENTATIVAS = 8;

/**
 * Espera (em minutos) antes de cada nova tentativa.
 *
 * Sem isso as tentativas eram gastas pela ATIVIDADE do site, não pelo tempo:
 * numa noite movimentada, cada aviso novo reprocessava a fila e as 5 chances
 * queimavam em poucos minutos — a instância voltava meia hora depois e as
 * mensagens já tinham sido descartadas. Com o backoff, a fila aguenta ~9h
 * de instância fora.
 */
const WHATSAPP_BACKOFF_MIN = [1, 2, 5, 15, 30, 60, 120, 240];

function ensureWhatsAppTables(PDO $pdo): void
{
    static $pronto = false;
    if ($pronto || $pdo->inTransaction()) return;
    $pronto = true;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_config (
            id TINYINT PRIMARY KEY DEFAULT 1,
            base_url VARCHAR(255) NULL,
            instancia VARCHAR(120) NULL,
            api_key VARCHAR(255) NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 0,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT IGNORE INTO whatsapp_config (id, ativo) VALUES (1, 0)");

        // Fila: toda mensagem passa por aqui antes de sair. Assim uma queda da
        // instância vira atraso, não aviso perdido.
        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_fila (
            id INT AUTO_INCREMENT PRIMARY KEY,
            destino VARCHAR(80) NOT NULL,
            eh_grupo TINYINT(1) NOT NULL DEFAULT 0,
            texto TEXT NOT NULL,
            tipo VARCHAR(30) NULL,
            user_id INT NULL,
            tentativas INT NOT NULL DEFAULT 0,
            proxima_tentativa DATETIME NULL,
            enviado_em DATETIME NULL,
            ultimo_erro VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wf_pendente (enviado_em, tentativas, proxima_tentativa)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if ($pdo->query("SHOW COLUMNS FROM whatsapp_fila LIKE 'proxima_tentativa'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE whatsapp_fila ADD COLUMN proxima_tentativa DATETIME NULL AFTER tentativas");
        }

        if ($pdo->query("SHOW COLUMNS FROM league_settings LIKE 'whatsapp_group_id'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE league_settings ADD COLUMN whatsapp_group_id VARCHAR(80) NULL");
        }
        // Opt-in separado do push: WhatsApp é bem mais invasivo, então quem
        // quer push não passa a receber no zap automaticamente.
        if ($pdo->query("SHOW COLUMNS FROM users LIKE 'whatsapp_optin'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp_optin TINYINT(1) NOT NULL DEFAULT 0 AFTER notif_off");
        }
    } catch (Throwable $e) {
        error_log('[whatsapp] ensureTables: ' . $e->getMessage());
    }
}

/** Config da instância, ou null quando ainda não foi configurada/ativada. */
function whatsappConfig(PDO $pdo): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;

    ensureWhatsAppTables($pdo);
    try {
        $c = $pdo->query("SELECT base_url, instancia, api_key, ativo FROM whatsapp_config WHERE id = 1")
                 ->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $cache = null;
    }
    if (!$c || empty($c['ativo']) || empty($c['base_url']) || empty($c['instancia']) || empty($c['api_key'])) {
        return $cache = null;
    }
    $c['base_url'] = rtrim((string)$c['base_url'], '/');
    return $cache = $c;
}

/**
 * Número no formato que a Evolution espera: só dígitos, com DDI.
 * Retorna null quando o telefone cadastrado não dá pra aproveitar.
 */
function whatsappNumero(?string $telefone): ?string
{
    $normalizado = normalizeBrazilianPhone((string)$telefone);
    if (!$normalizado) return null;
    $digitos = preg_replace('/\D+/', '', $normalizado);
    if (strlen($digitos) < 10) return null;
    // normalizeBrazilianPhone já devolve com 55 na frente nos casos comuns;
    // se vier sem, assume Brasil (é o público da liga inteira).
    if (!str_starts_with($digitos, '55')) $digitos = '55' . $digitos;

    // Com o 55 na frente, número brasileiro tem 12 dígitos (fixo/antigo) ou
    // 13 (celular com o nono). Fora disso é lixo de digitação — quem escreve
    // "011 51 99420-0231" cai em 16 dígitos. Barrar aqui evita enfileirar uma
    // mensagem que não tem como ser entregue e ainda gastar as oito
    // tentativas dela ao longo de dez horas.
    $tam = strlen($digitos);
    if ($tam < 12 || $tam > 13) return null;

    return $digitos;
}

/**
 * Coloca uma mensagem na fila. Não envia aqui — quem envia é o
 * whatsappProcessarFila(), chamado logo em seguida e pelo cron.
 */
function whatsappEnfileirar(PDO $pdo, string $destino, string $texto, bool $ehGrupo = false, ?string $tipo = null, ?int $userId = null): bool
{
    if ($destino === '' || trim($texto) === '') return false;
    if (!whatsappConfig($pdo)) return false;

    try {
        $pdo->prepare("INSERT INTO whatsapp_fila (destino, eh_grupo, texto, tipo, user_id) VALUES (?,?,?,?,?)")
            ->execute([$destino, $ehGrupo ? 1 : 0, $texto, $tipo, $userId]);
        return true;
    } catch (Throwable $e) {
        error_log('[whatsapp] enfileirar: ' . $e->getMessage());
        return false;
    }
}

/** POST na Evolution API. Retorna [ok, mensagemDeErro]. */
function whatsappPostar(array $cfg, string $destino, string $texto): array
{
    $url = $cfg['base_url'] . '/message/sendText/' . rawurlencode($cfg['instancia']);
    $body = json_encode(['number' => $destino, 'text' => $texto], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    if ($ch === false) return [false, 'curl_init falhou'];
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $cfg['api_key']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 12,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false)  return [false, 'curl: ' . $err];
    if ($code >= 400)     return [false, 'http ' . $code . ': ' . mb_substr((string)$resp, 0, 150)];
    return [true, null];
}

/**
 * Envia o que está pendente na fila.
 *
 * $limite segura o custo quando roda no fim de um request (o caminho normal);
 * o cron chama com um limite maior. Mensagem que estourou as tentativas para
 * de ser reprocessada, mas fica na tabela pra dar pra investigar.
 */
function whatsappProcessarFila(PDO $pdo, int $limite = 10): array
{
    $out = ['enviadas' => 0, 'falhas' => 0];
    $cfg = whatsappConfig($pdo);
    if (!$cfg) return $out;

    try {
        $stmt = $pdo->prepare("SELECT id, destino, texto, tentativas FROM whatsapp_fila
                               WHERE enviado_em IS NULL
                                 AND tentativas < " . WHATSAPP_MAX_TENTATIVAS . "
                                 AND (proxima_tentativa IS NULL OR proxima_tentativa <= NOW())
                               ORDER BY id ASC LIMIT " . max(1, (int)$limite));
        $stmt->execute();
        $pendentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[whatsapp] ler fila: ' . $e->getMessage());
        return $out;
    }

    foreach ($pendentes as $m) {
        [$ok, $erro] = whatsappPostar($cfg, (string)$m['destino'], (string)$m['texto']);
        try {
            if ($ok) {
                $pdo->prepare("UPDATE whatsapp_fila SET enviado_em = NOW(), tentativas = tentativas + 1, ultimo_erro = NULL WHERE id = ?")
                    ->execute([(int)$m['id']]);
                $out['enviadas']++;
            } else {
                $espera = WHATSAPP_BACKOFF_MIN[min((int)$m['tentativas'], count(WHATSAPP_BACKOFF_MIN) - 1)];
                $pdo->prepare("UPDATE whatsapp_fila
                               SET tentativas = tentativas + 1,
                                   proxima_tentativa = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                                   ultimo_erro = ?
                               WHERE id = ?")
                    ->execute([$espera, mb_substr((string)$erro, 0, 255), (int)$m['id']]);
                $out['falhas']++;
            }
        } catch (Throwable $e) {
            error_log('[whatsapp] atualizar fila: ' . $e->getMessage());
        }
    }
    return $out;
}

/**
 * Manda pro GM, respeitando o opt-in dele e a preferência do tipo de aviso.
 * É chamada de dentro do sendPushToUser — o mesmo aviso vai pros dois canais
 * pra quem escolheu receber nos dois.
 */
function whatsappParaUsuario(PDO $pdo, int $userId, string $texto, ?string $tipo = null): void
{
    if (!whatsappConfig($pdo)) return;
    if (!userWantsNotif($pdo, $userId, $tipo)) return;

    try {
        $st = $pdo->prepare("SELECT phone, whatsapp_optin FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return; // banco sem a coluna ainda
    }
    if (!$u || empty($u['whatsapp_optin'])) return;

    $numero = whatsappNumero($u['phone'] ?? null);
    if (!$numero) return;

    whatsappEnfileirar($pdo, $numero, $texto, false, $tipo, $userId);
    whatsappEsvaziarUmaVez($pdo);
}

/** Manda no grupo da liga (nada acontece se a liga não tiver grupo configurado). */
function whatsappParaGrupo(PDO $pdo, string $league, string $texto, ?string $tipo = null): void
{
    if (!whatsappConfig($pdo)) return;
    try {
        $st = $pdo->prepare("SELECT whatsapp_group_id FROM league_settings WHERE league = ? LIMIT 1");
        $st->execute([strtoupper($league)]);
        $grupo = trim((string)($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return;
    }
    if ($grupo === '') return;

    whatsappEnfileirar($pdo, $grupo, $texto, true, $tipo);
    whatsappEsvaziarUmaVez($pdo);
}

/**
 * Esvazia a fila uma única vez por request.
 *
 * Sem a trava, um aviso pra liga inteira (30+ GMs) tentaria esvaziar a fila a
 * cada usuário e o request ficaria eterno. Com ela, o request manda o que der
 * numa passada só e o cron cobre o resto.
 */
function whatsappEsvaziarUmaVez(PDO $pdo, int $limite = 40): void
{
    static $jaRodou = false;
    if ($jaRodou) return;
    $jaRodou = true;

    // register_shutdown_function: o envio acontece DEPOIS da resposta ir pro
    // navegador, então a lentidão da Evolution não atrasa quem está usando o site.
    register_shutdown_function(function () use ($pdo, $limite) {
        try {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            whatsappProcessarFila($pdo, $limite);
        } catch (Throwable $e) {
            error_log('[whatsapp] esvaziar: ' . $e->getMessage());
        }
    });
}

/** Junta título e corpo do aviso num texto de WhatsApp. */
function whatsappTextoDoAviso(array $data): string
{
    $titulo = trim((string)($data['title'] ?? ''));
    $corpo  = trim((string)($data['body'] ?? ''));
    $url    = trim((string)($data['url'] ?? ''));

    $linhas = [];
    if ($titulo !== '') $linhas[] = '*' . $titulo . '*';
    if ($corpo !== '')  $linhas[] = $corpo;
    if ($url !== '' && str_starts_with($url, '/')) {
        $linhas[] = 'https://fbabrasil.com.br' . $url;
    }
    return implode("\n", $linhas);
}
