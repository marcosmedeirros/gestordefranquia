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

        // Onde o bot aceita /comando. Separado do grupo_principal de propósito:
        // "receber aviso automático" e "poder consultar" são coisas diferentes.
        // Os Chat Off de cada liga consultam, mas não recebem notificação.
        //
        // A liga é opcional e serve de contexto: no Chat Off da NEXT, um
        // /classificacao sem argumento já responde a NEXT.
        //
        // Os ids ficam só aqui, nunca no código — são identificadores de grupos
        // privados e este repositório não é o lugar deles.
        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_grupos_comando (
            jid VARCHAR(80) NOT NULL PRIMARY KEY,
            nome VARCHAR(120) NULL,
            liga ENUM('ELITE','NEXT','RISE','ROOKIE') NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Fila: toda mensagem passa por aqui antes de sair. Assim uma queda da
        // instância vira atraso, não aviso perdido.
        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_fila (
            id INT AUTO_INCREMENT PRIMARY KEY,
            destino VARCHAR(80) NOT NULL,
            eh_grupo TINYINT(1) NOT NULL DEFAULT 0,
            texto TEXT NOT NULL,
            tipo VARCHAR(30) NULL,
            user_id INT NULL,
            mencoes TEXT NULL,
            tentativas INT NOT NULL DEFAULT 0,
            proxima_tentativa DATETIME NULL,
            enviado_em DATETIME NULL,
            ultimo_erro VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wf_pendente (enviado_em, tentativas, proxima_tentativa)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // CREATE TABLE IF NOT EXISTS não altera tabela que já existe — quem já
        // tem a fila criada precisa do ALTER.
        if ($pdo->query("SHOW COLUMNS FROM whatsapp_fila LIKE 'mencoes'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE whatsapp_fila ADD COLUMN mencoes TEXT NULL AFTER user_id");
        }
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

    // Quem decide é whatsappNumeroUsavel(), a MESMA função que pinta o selo na
    // Gestão. Antes esta função tinha regra própria — só conferia tamanho — e
    // as duas discordavam: o admin via vermelho ("o WhatsApp não reconhece") e
    // a mensagem era enfileirada assim mesmo, pra falhar calada oito vezes ao
    // longo de dez horas.
    //
    // A regra antiga também grudava um 55 em tudo que não começasse com 55:
    // um português (351916047829) virava 55351916047829, quatorze dígitos, e
    // era descartado. GM de Portugal nunca recebia mensagem, e a tela de
    // ajustes dizia que ele não tinha telefone válido.
    return whatsappNumeroUsavel($digitos)['ok'] ? $digitos : null;
}

/**
 * Coloca uma mensagem na fila. Não envia aqui — quem envia é o
 * whatsappProcessarFila(), chamado logo em seguida e pelo cron.
 */
/**
 * Grupos onde o bot aceita /comando, indexados pelo jid.
 *
 * O grupo_principal entra sempre: ele já recebe os avisos automáticos, seria
 * esquisito não responder a uma pergunta ali. Os demais vêm da tabela.
 *
 * Retorna [jid => ['nome' => ..., 'liga' => ...|null]].
 */
function whatsappGruposDeComando(PDO $pdo): array
{
    $out = [];
    try {
        $principal = trim((string)($pdo->query("SELECT grupo_principal FROM whatsapp_config WHERE id = 1")->fetchColumn() ?: ''));
        if ($principal !== '') {
            $out[$principal] = ['nome' => 'Principal', 'liga' => null];
        }
        foreach ($pdo->query("SELECT jid, nome, liga FROM whatsapp_grupos_comando WHERE ativo = 1") as $r) {
            $jid = trim((string)$r['jid']);
            if ($jid === '') continue;
            // A tabela ganha do padrão: se alguém cadastrou o principal aqui
            // com uma liga, é essa liga que vale.
            $out[$jid] = ['nome' => $r['nome'] ?: $jid, 'liga' => $r['liga'] ?: null];
        }
    } catch (Throwable $e) {
        error_log('[whatsapp] grupos de comando: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Anota todo grupo de onde chega mensagem, mesmo os que o bot não atende.
 *
 * Serve pra uma coisa só: cadastrar grupo sem ter que descobrir o JID. O
 * identificador é um número de 18 dígitos que não aparece em lugar nenhum da
 * interface do WhatsApp, então "cadastre o Chat Off da NEXT" sem isto exige
 * ler log da Evolution. Com isto, basta alguém falar no grupo uma vez e ele
 * aparece na tela pra ser habilitado com um clique.
 *
 * Guarda também quem falou e o começo da mensagem — o JID sozinho não diz
 * qual grupo é, e o "Victor: e aí, tem jogo hoje?" diz na hora.
 *
 * Nada aqui pode derrubar o webhook: é anotação, não é o trabalho dele.
 */
function whatsappAnotarGrupoVisto(PDO $pdo, string $jid, array $mensagem = []): void
{
    if (!str_ends_with($jid, '@g.us')) return;   // conversa privada não entra

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_grupos_vistos (
            jid VARCHAR(120) PRIMARY KEY,
            ultimo_autor VARCHAR(120) NULL,
            ultima_mensagem VARCHAR(160) NULL,
            mensagens INT NOT NULL DEFAULT 0,
            visto_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            primeiro_em TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $autor = trim((string)($mensagem['pushName'] ?? ''));
        // wcTextoDaMensagem é declarada dentro do próprio webhook, que é quem
        // chama isto aqui. Chamado de outro lugar, o resumo só não vem.
        $texto = function_exists('wcTextoDaMensagem')
            ? trim((string)wcTextoDaMensagem($mensagem['message'] ?? []))
            : '';

        $pdo->prepare("INSERT INTO whatsapp_grupos_vistos
                         (jid, ultimo_autor, ultima_mensagem, mensagens, primeiro_em)
                       VALUES (?,?,?,1,NOW())
                       ON DUPLICATE KEY UPDATE
                         ultimo_autor = VALUES(ultimo_autor),
                         ultima_mensagem = VALUES(ultima_mensagem),
                         mensagens = mensagens + 1")
            ->execute([$jid, mb_substr($autor, 0, 120) ?: null, mb_substr($texto, 0, 160) ?: null]);
    } catch (Throwable $e) {
        error_log('[whatsapp] anotar grupo visto: ' . $e->getMessage());
    }
}

function whatsappEnfileirar(PDO $pdo, string $destino, string $texto, bool $ehGrupo = false, ?string $tipo = null, ?int $userId = null, ?array $mencoes = null): bool
{
    if ($destino === '' || trim($texto) === '') return false;
    // Só "ligada": quem envia é o worker local, o site apenas enfileira.
    if (!whatsappAtivo($pdo)) return false;

    // Menção só marca de verdade se o número TAMBÉM aparecer como @numero no
    // texto — é assim que o WhatsApp resolve a etiqueta. Guardo os dois juntos
    // pra não existir mensagem com um sem o outro.
    $json = $mencoes ? json_encode(array_values(array_filter($mencoes))) : null;

    try {
        $pdo->prepare("INSERT INTO whatsapp_fila (destino, eh_grupo, texto, tipo, user_id, mencoes) VALUES (?,?,?,?,?,?)")
            ->execute([$destino, $ehGrupo ? 1 : 0, $texto, $tipo, $userId, $json]);
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
    //
    // A exceção é 'comando': alguém digitou /cap no grupo e está olhando pro
    // celular esperando. A janela existe pra não despejar aviso no grupo fora
    // de hora, não pra ignorar quem perguntou.
    $foraDaJanela = !whatsappDentroDaJanela();
    if ($foraDaJanela) $out['fora_da_janela'] = true;
    $filtroTipo = $foraDaJanela ? " AND tipo = 'comando'" : '';

    try {
        $stmt = $pdo->prepare("SELECT id, destino, texto, tentativas FROM whatsapp_fila
                               WHERE enviado_em IS NULL
                                 AND tentativas < " . WHATSAPP_MAX_TENTATIVAS . "
                                 AND (proxima_tentativa IS NULL OR proxima_tentativa <= NOW())
                                 {$filtroTipo}
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
