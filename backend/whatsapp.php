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

/**
 * Janela de envio (horário de Brasília). Fora dela nada sai.
 *
 * O grupo é de gente real: aviso de trade às 3 da manhã acorda todo mundo.
 * A mensagem não se perde — fica na fila e sai na abertura da janela.
 */
const WHATSAPP_JANELA_INICIO = '08:45';
const WHATSAPP_JANELA_FIM    = '18:00';

/**
 * Estamos dentro da janela de envio?
 *
 * Fuso fixo de propósito: o horário combinado é o de Brasília, e o servidor
 * não necessariamente está nele.
 */
function whatsappDentroDaJanela(?DateTimeImmutable $agora = null): bool
{
    $tz = new DateTimeZone('America/Sao_Paulo');
    $agora = $agora ? $agora->setTimezone($tz) : new DateTimeImmutable('now', $tz);
    $hhmm = $agora->format('H:i');
    return $hhmm >= WHATSAPP_JANELA_INICIO && $hhmm < WHATSAPP_JANELA_FIM;
}

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

        // Grupo único que recebe trades e as novas versões do The Pathetic.
        // É um só pra liga inteira, com a tag da liga no texto — diferente do
        // whatsapp_group_id por liga, que continua existindo pra outros usos.
        $cols = $pdo->query("SHOW COLUMNS FROM whatsapp_config")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('grupo_principal', $cols, true)) {
            $pdo->exec("ALTER TABLE whatsapp_config ADD COLUMN grupo_principal VARCHAR(80) NULL AFTER api_key");
        }

        // Token do worker local (ver api/whatsapp-bot.php). A Evolution roda na
        // máquina do Marcos, atrás de IP residencial — a Hostinger não alcança
        // ela. Então é o worker de lá que puxa a fila daqui, autenticado por
        // este token. Gerado sozinho na primeira execução.
        if (!in_array('bot_token', $cols, true)) {
            $pdo->exec("ALTER TABLE whatsapp_config ADD COLUMN bot_token VARCHAR(64) NULL AFTER grupo_principal");
            $pdo->exec("ALTER TABLE whatsapp_config ADD COLUMN bot_visto_em DATETIME NULL AFTER bot_token");
        }
        $pdo->exec("UPDATE whatsapp_config SET bot_token = SHA2(CONCAT(RAND(), UUID(), NOW()), 256)
                    WHERE id = 1 AND (bot_token IS NULL OR bot_token = '')");

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
        $c = $pdo->query("SELECT base_url, instancia, api_key, grupo_principal, ativo FROM whatsapp_config WHERE id = 1")
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
 * A integração está ligada?
 *
 * Diferente de whatsappConfig(), que exige as credenciais da Evolution: ali é
 * o que o SITE precisa pra enviar direto. Só que quem envia agora é o worker
 * da máquina local (bot/whatsapp-local.php) — o site apenas enfileira.
 * Exigir base_url/api_key aqui faria a fila nem receber a mensagem, e o worker
 * nunca teria o que buscar.
 */
function whatsappAtivo(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) return $cache;
    ensureWhatsAppTables($pdo);
    try {
        return $cache = (bool)$pdo->query("SELECT ativo FROM whatsapp_config WHERE id = 1")->fetchColumn();
    } catch (Throwable $e) {
        return $cache = false;
    }
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
    // Só "ligada": quem envia é o worker local, o site apenas enfileira.
    if (!whatsappAtivo($pdo)) return false;

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
    $out = ['enviadas' => 0, 'falhas' => 0, 'fora_da_janela' => false];
    $cfg = whatsappConfig($pdo);
    if (!$cfg) return $out;

    // Único ponto por onde todo envio passa — o imediato (via shutdown) e o do
    // cron. Barrando aqui, a mensagem gerada de madrugada fica na fila com as
    // tentativas intactas e sai quando a janela abre, sem queimar o backoff.
    if (!whatsappDentroDaJanela()) {
        $out['fora_da_janela'] = true;
        return $out;
    }

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
    if (!whatsappAtivo($pdo)) return;
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

/**
 * Manda no grupo principal — o mesmo pra liga inteira, com a tag da liga no
 * texto. É por onde saem as trades grandes e as novas versões do The Pathetic.
 */
function whatsappParaGrupoPrincipal(PDO $pdo, string $texto, ?string $tipo = null): void
{
    // whatsappAtivo em vez de whatsappConfig: enfileirar não depende das
    // credenciais da Evolution, que só o worker local usa.
    if (!whatsappAtivo($pdo)) return;

    $grupo = trim((string)($pdo->query("SELECT grupo_principal FROM whatsapp_config WHERE id = 1")->fetchColumn() ?: ''));
    if ($grupo === '') return;

    whatsappEnfileirar($pdo, $grupo, $texto, true, $tipo);
    whatsappEsvaziarUmaVez($pdo);
}

/** Tag da liga pro começo da mensagem: [FBA NEXT], [FBA ELITE]... */
function whatsappTagDaLiga(?string $league): string
{
    $l = strtoupper(trim((string)$league));
    return in_array($l, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true) ? '[FBA ' . $l . ']' : '[FBA]';
}

/** Manda no grupo da liga (nada acontece se a liga não tiver grupo configurado). */
function whatsappParaGrupo(PDO $pdo, string $league, string $texto, ?string $tipo = null): void
{
    if (!whatsappAtivo($pdo)) return;
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
