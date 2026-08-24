<?php
/**
 * PUBLICAÇÃO NO X (Twitter) — trades bombásticas e notícias do The Pathetic.
 *
 * O desenho é o mesmo do WhatsApp e por um motivo já testado: nada que
 * acontece aqui pode derrubar a ação que gerou o post. Trade fechada com o X
 * fora do ar continua sendo trade fechada; o post entra na fila e sai depois.
 *
 * O que muda em relação ao WhatsApp, e muda bastante:
 *
 * 1. NÃO TEM JANELA DE HORÁRIO. O grupo é de gente real que dorme; a
 *    timeline não acorda ninguém. Trade fechada às 3h sai às 3h.
 *
 * 2. TEM COTA MENSAL DE VERDADE. O tier gratuito da API do X dá 500 posts
 *    por mês pela conta. Estourar não dá erro bonito: a API passa a recusar
 *    e o mês inteiro fica travado. Por isso a cota é contada aqui dentro, com
 *    folga, e a fila para sozinha antes de bater no teto.
 *
 * 3. TEM ESPAÇAMENTO. Uma multi-trade pode gerar quatro posts no mesmo
 *    segundo; conta que dispara em rajada é exatamente o que o antispam do X
 *    procura. A fila solta um post por vez, respeitando X_ESPACO_MIN.
 *
 * 4. O TOKEN EXPIRA. OAuth 2.0: o access token vale ~2h e é renovado pelo
 *    refresh token, que a cada renovação também troca. Se a gravação do
 *    refresh novo falhar, a conexão morre e só reconectando na mão — por
 *    isso o token novo é gravado ANTES de qualquer post ser tentado.
 *
 * A conta é a mesma que você usa no dia a dia: o OAuth autoriza o app a
 * postar em nome dela, não toma posse dela. Você continua tuitando normal,
 * pelo app do celular, sem nada aqui atrapalhar.
 *
 * Configuração e conexão: /x-conectar.php (só admin).
 */

require_once __DIR__ . '/helpers.php';

/** Teto de caracteres de um post. O X corta em 280 pra conta comum. */
const X_LIMITE_TEXTO = 280;

/**
 * OVR mínimo pra uma trade virar post.
 *
 * O WhatsApp usa 82 e o grupo aguenta: é gente da liga querendo saber de
 * tudo. A timeline é pública e a régua tem que ser mais alta — "bombástica"
 * é 86+, que dá um punhado de posts por temporada em vez de um por dia.
 */
const X_OVR_MIN_TRADE = 86;

/**
 * Teto mensal auto-imposto, abaixo dos 500 do plano gratuito.
 *
 * A folga não é frescura: o contador daqui conta o que ESTE app postou, e
 * qualquer post que você fizer pelo app do celular na mesma conta não passa
 * por aqui. Sem folga, o mês fecharia no meio de uma sequência de trades.
 */
const X_TETO_MES = 440;

/** Espera mínima entre dois posts, em segundos. Antirrajada. */
const X_ESPACO_MIN = 90;

/** Quantas tentativas antes de desistir de um post. */
const X_MAX_TENTATIVAS = 6;

/** Espera (minutos) antes de cada nova tentativa. Aguenta ~9h de X fora. */
const X_BACKOFF_MIN = [2, 5, 15, 60, 180, 360];

/** Onde o OAuth volta. Precisa bater EXATO com o cadastrado no portal do X. */
const X_CALLBACK = 'https://fbabrasil.com.br/x-conectar.php';

/** Renova o token quando faltar menos que isso (segundos) pra expirar. */
const X_RENOVAR_ANTES = 600;

/* ────────────────────────────────────────────────────────────────────────
 * TABELAS
 * ──────────────────────────────────────────────────────────────────────── */

/**
 * Cria as duas tabelas se faltarem — uma vez por request.
 *
 * O static não é economia à toa: TODA leitura chama isto (foi assim que a
 * loja derrubou a /games inteira em produção, por só criar a tabela no
 * caminho de escrita), e um CREATE IF NOT EXISTS por chamada seria ida ao
 * banco de graça numa página que já faz dezenas.
 */
function xGarantirTabelas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS x_config (
            id            TINYINT      NOT NULL PRIMARY KEY DEFAULT 1,
            client_id     VARCHAR(120) NULL,
            client_secret VARCHAR(190) NULL,
            access_token  TEXT         NULL,
            refresh_token TEXT         NULL,
            expira_em     DATETIME     NULL,
            conta         VARCHAR(80)  NULL,
            ativo         TINYINT(1)   NOT NULL DEFAULT 0,
            postar_trade  TINYINT(1)   NOT NULL DEFAULT 1,
            postar_news   TINYINT(1)   NOT NULL DEFAULT 1,
            mes_ref       CHAR(7)      NULL,
            postados_mes  INT          NOT NULL DEFAULT 0,
            ultimo_post   DATETIME     NULL,
            pkce          VARCHAR(140) NULL,
            atualizado_em DATETIME     NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("INSERT IGNORE INTO x_config (id) VALUES (1)");

        // A chave única (tipo, ref) é o que impede post repetido: editar uma
        // notícia já publicada, ou republicar uma que foi tirada do ar, tenta
        // enfileirar de novo e o INSERT IGNORE simplesmente não faz nada.
        $pdo->exec("CREATE TABLE IF NOT EXISTS x_fila (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            texto             VARCHAR(400) NOT NULL,
            tipo              VARCHAR(24)  NOT NULL,
            ref               VARCHAR(64)  NOT NULL,
            tentativas        TINYINT      NOT NULL DEFAULT 0,
            proxima_tentativa DATETIME     NULL,
            postado_em        DATETIME     NULL,
            tweet_id          VARCHAR(30)  NULL,
            ultimo_erro       VARCHAR(255) NULL,
            criado_em         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_x_ref (tipo, ref),
            KEY idx_x_pend (postado_em, tentativas)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[x] garantir tabelas: ' . $e->getMessage());
    }
}

/** A linha de configuração, ou null se o banco não colaborar. */
function xConfig(PDO $pdo, bool $recarregar = false): ?array
{
    static $cache = null;
    if ($cache !== null && !$recarregar) return $cache;
    try {
        xGarantirTabelas($pdo);
        $r = $pdo->query("SELECT * FROM x_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $cache = $r ?: null;
    } catch (Throwable $e) {
        error_log('[x] config: ' . $e->getMessage());
        $cache = null;
    }
    return $cache;
}

/**
 * Dá pra postar agora?
 *
 * Ligado, conectado e com credencial. Sem isso, enfileirar seria juntar
 * mensagem que nunca sai — e nesse caso é melhor nem enfileirar, porque a
 * fila cheia de coisa velha vira enxurrada no dia que a conexão voltar.
 */
function xAtivo(PDO $pdo): bool
{
    $c = xConfig($pdo);
    return $c && !empty($c['ativo']) && !empty($c['refresh_token']) && !empty($c['client_id']);
}

/* ────────────────────────────────────────────────────────────────────────
 * COTA
 * ──────────────────────────────────────────────────────────────────────── */

/**
 * Quanto já foi postado neste mês e quanto ainda cabe.
 *
 * Vira o mês sozinho: se mes_ref é de outro mês, o contador zera na hora da
 * leitura. Não depende de cron nenhum rodar no dia 1º.
 */
function xCota(PDO $pdo): array
{
    $c = xConfig($pdo);
    $mes = date('Y-m');
    $usados = ($c && $c['mes_ref'] === $mes) ? (int)$c['postados_mes'] : 0;
    return [
        'mes'     => $mes,
        'usados'  => $usados,
        'teto'    => X_TETO_MES,
        'restam'  => max(0, X_TETO_MES - $usados),
        'estourou'=> $usados >= X_TETO_MES,
    ];
}

/** Soma 1 na cota do mês, virando o mês se for o caso. */
function xContarPost(PDO $pdo): void
{
    $mes = date('Y-m');
    try {
        // Um UPDATE só, decidindo no próprio SQL se soma ou reinicia: com duas
        // instruções, dois posts no mesmo instante na virada do mês podiam
        // zerar o contador um do outro.
        $pdo->prepare("UPDATE x_config
                          SET postados_mes = IF(mes_ref = ?, postados_mes + 1, 1),
                              mes_ref      = ?,
                              ultimo_post  = NOW()
                        WHERE id = 1")->execute([$mes, $mes]);
        xConfig($pdo, true);
    } catch (Throwable $e) {
        error_log('[x] contar post: ' . $e->getMessage());
    }
}

/* ────────────────────────────────────────────────────────────────────────
 * OAUTH 2.0 (PKCE)
 * ──────────────────────────────────────────────────────────────────────── */

/** base64url sem padding — o que o PKCE pede. */
function xB64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/**
 * Monta a URL de autorização e guarda o verifier pra volta.
 *
 * O verifier vai pro banco e não pra sessão porque a volta do X pode cair
 * noutro processo (e, em produção, noutra máquina do balanceador).
 */
function xUrlAutorizacao(PDO $pdo): ?string
{
    $c = xConfig($pdo, true);
    if (!$c || empty($c['client_id'])) return null;

    $verifier  = xB64url(random_bytes(48));
    $challenge = xB64url(hash('sha256', $verifier, true));
    $state     = xB64url(random_bytes(12));

    try {
        $pdo->prepare("UPDATE x_config SET pkce = ? WHERE id = 1")
            ->execute([$state . ':' . $verifier]);
    } catch (Throwable $e) {
        error_log('[x] guardar pkce: ' . $e->getMessage());
        return null;
    }

    // offline.access é o que devolve refresh_token. Sem ele a conexão morre
    // em 2 horas e você reconecta na mão toda vez.
    return 'https://x.com/i/oauth2/authorize?' . http_build_query([
        'response_type'         => 'code',
        'client_id'             => $c['client_id'],
        'redirect_uri'          => X_CALLBACK,
        'scope'                 => 'tweet.read tweet.write users.read offline.access',
        'state'                 => $state,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    ]);
}

/** POST no /oauth2/token. Devolve [dados, erro]. */
function xTrocarToken(array $c, array $campos): array
{
    $ch = curl_init('https://api.x.com/2/oauth2/token');
    if ($ch === false) return [null, 'curl_init falhou'];

    $opts = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($campos),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ];
    // App confidencial manda a credencial por Basic; app público (sem secret)
    // manda só o client_id no corpo. Os dois formatos existem e o X aceita o
    // que combinar com o tipo escolhido no portal.
    if (!empty($c['client_secret'])) {
        $opts[CURLOPT_USERPWD] = $c['client_id'] . ':' . $c['client_secret'];
    }
    curl_setopt_array($ch, $opts);

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return [null, 'curl: ' . $err];
    $j = json_decode((string)$resp, true);
    if ($code >= 400 || !is_array($j)) {
        return [null, 'http ' . $code . ': ' . mb_substr((string)$resp, 0, 180)];
    }
    return [$j, null];
}

/** Grava o par de tokens. Devolve false se não conseguiu — e aí não se posta. */
function xGravarTokens(PDO $pdo, array $j): bool
{
    if (empty($j['access_token'])) return false;
    try {
        $pdo->prepare("UPDATE x_config
                          SET access_token = ?, refresh_token = COALESCE(?, refresh_token),
                              expira_em = DATE_ADD(NOW(), INTERVAL ? SECOND),
                              atualizado_em = NOW()
                        WHERE id = 1")
            ->execute([$j['access_token'], $j['refresh_token'] ?? null, (int)($j['expires_in'] ?? 7200)]);
        xConfig($pdo, true);
        return true;
    } catch (Throwable $e) {
        error_log('[x] gravar tokens: ' . $e->getMessage());
        return false;
    }
}

/**
 * Fecha a conexão: troca o code pelo token e guarda quem é a conta.
 * Devolve [ok, mensagem].
 */
function xConcluirConexao(PDO $pdo, string $code, string $state): array
{
    $c = xConfig($pdo, true);
    if (!$c) return [false, 'Sem configuração.'];

    [$st, $verifier] = array_pad(explode(':', (string)$c['pkce'], 2), 2, '');
    if ($st === '' || !hash_equals($st, $state)) {
        return [false, 'A volta do X não bateu com o pedido (state). Tente conectar de novo.'];
    }

    [$j, $erro] = xTrocarToken($c, [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => X_CALLBACK,
        'code_verifier' => $verifier,
        'client_id'     => $c['client_id'],
    ]);
    if (!$j) return [false, 'O X recusou a troca: ' . $erro];
    if (!xGravarTokens($pdo, $j)) return [false, 'Não consegui gravar o token no banco.'];

    // O verifier é de uso único: deixar guardado é credencial parada à toa.
    try { $pdo->prepare("UPDATE x_config SET pkce = NULL, ativo = 1 WHERE id = 1")->execute(); } catch (Throwable $e) {}

    $conta = xQuemSou($pdo);
    if ($conta) {
        try { $pdo->prepare("UPDATE x_config SET conta = ? WHERE id = 1")->execute([$conta]); } catch (Throwable $e) {}
    }
    xConfig($pdo, true);
    return [true, 'Conectado' . ($conta ? ' como @' . $conta : '') . '.'];
}

/**
 * Um access token válido, renovando se estiver perto de vencer.
 *
 * O refresh token do X é de uso único: cada renovação devolve um novo e
 * invalida o anterior. Se a gravação falhar depois de o X já ter girado o
 * par, a conexão morre — por isso o post só acontece DEPOIS de o token novo
 * estar gravado, nunca com o token em memória e o banco desatualizado.
 */
function xToken(PDO $pdo): ?string
{
    $c = xConfig($pdo, true);
    if (!$c || empty($c['refresh_token'])) return null;

    $venceEm = $c['expira_em'] ? strtotime($c['expira_em']) - time() : -1;
    if (!empty($c['access_token']) && $venceEm > X_RENOVAR_ANTES) {
        return (string)$c['access_token'];
    }

    [$j, $erro] = xTrocarToken($c, [
        'grant_type'    => 'refresh_token',
        'refresh_token' => $c['refresh_token'],
        'client_id'     => $c['client_id'],
    ]);
    if (!$j) {
        error_log('[x] renovar token: ' . $erro);
        return null;
    }
    if (!xGravarTokens($pdo, $j)) return null;
    return (string)$j['access_token'];
}

/** O @ da conta conectada, pela /2/users/me. */
function xQuemSou(PDO $pdo): ?string
{
    $tok = xToken($pdo);
    if (!$tok) return null;

    $ch = curl_init('https://api.x.com/2/users/me');
    if ($ch === false) return null;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tok],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string)$resp, true);
    return $j['data']['username'] ?? null;
}

/** Corta a conexão sem apagar a credencial do app. */
function xDesconectar(PDO $pdo): void
{
    try {
        $pdo->prepare("UPDATE x_config SET access_token = NULL, refresh_token = NULL,
                              expira_em = NULL, conta = NULL, ativo = 0, pkce = NULL WHERE id = 1")
            ->execute();
        xConfig($pdo, true);
    } catch (Throwable $e) {
        error_log('[x] desconectar: ' . $e->getMessage());
    }
}

/* ────────────────────────────────────────────────────────────────────────
 * FILA
 * ──────────────────────────────────────────────────────────────────────── */

/**
 * Bota um post na fila. Devolve false quando não entrou.
 *
 * $ref é a identidade do post ("trade:184", "news:57"): é ela que garante o
 * post único. Sem ela, salvar de novo uma notícia já publicada mandaria a
 * mesma manchete pra timeline outra vez.
 */
function xEnfileirar(PDO $pdo, string $texto, string $tipo, string $ref): bool
{
    $texto = trim($texto);
    if ($texto === '' || $ref === '') return false;
    if (!xAtivo($pdo)) return false;

    try {
        $st = $pdo->prepare("INSERT IGNORE INTO x_fila (texto, tipo, ref) VALUES (?,?,?)");
        $st->execute([xCortar($texto), $tipo, $ref]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('[x] enfileirar: ' . $e->getMessage());
        return false;
    }
}

/**
 * Corta pra caber em 280 sem picotar palavra nem link.
 *
 * A última linha costuma ser a URL, e URL cortada é link quebrado na
 * timeline — pior que post sem link. Então quando não cabe tudo, o que
 * encolhe é o miolo; a última linha vai inteira.
 */
function xCortar(string $texto, int $max = X_LIMITE_TEXTO): string
{
    if (mb_strlen($texto) <= $max) return $texto;

    $linhas = explode("\n", $texto);
    $ultima = array_pop($linhas);
    // Só protege a última linha se ela for mesmo um link curto; se for texto
    // longo, proteger não ajudaria e ainda comeria o post inteiro.
    if (preg_match('~^https?://\S+$~', trim($ultima)) && mb_strlen($ultima) < $max - 40) {
        $sobra = $max - mb_strlen($ultima) - 2;
        $miolo = mb_substr(implode("\n", $linhas), 0, $sobra);
        $miolo = preg_replace('~\s+\S*$~u', '', $miolo);
        return rtrim($miolo) . "\n" . trim($ultima);
    }

    $corte = mb_substr($texto, 0, $max - 1);
    return rtrim(preg_replace('~\s+\S*$~u', '', $corte)) . '…';
}

/** POST no /2/tweets. Devolve [idDoTweet, erro]. */
function xPostar(string $token, string $texto): array
{
    $ch = curl_init('https://api.x.com/2/tweets');
    if ($ch === false) return [null, 'curl_init falhou'];
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['text' => $texto], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return [null, 'curl: ' . $err];
    $j = json_decode((string)$resp, true);
    if ($code >= 400 || empty($j['data']['id'])) {
        return [null, 'http ' . $code . ': ' . mb_substr((string)$resp, 0, 180)];
    }
    return [(string)$j['data']['id'], null];
}

/**
 * Solta o que está pendente.
 *
 * Um post por chamada é de propósito: o cron roda a cada 5 minutos e o
 * espaçamento sai de graça disso. Uma multi-trade que gera quatro posts leva
 * vinte minutos pra sair inteira, e é exatamente esse o comportamento que se
 * quer de uma conta que não é robô de spam.
 */
function xProcessarFila(PDO $pdo, int $limite = 1): array
{
    $out = ['postados' => 0, 'falhas' => 0, 'motivo' => null];

    if (!xAtivo($pdo)) { $out['motivo'] = 'desligado'; return $out; }

    $cota = xCota($pdo);
    if ($cota['estourou']) { $out['motivo'] = 'cota'; return $out; }

    $c = xConfig($pdo);
    if (!empty($c['ultimo_post']) && (time() - strtotime($c['ultimo_post'])) < X_ESPACO_MIN) {
        $out['motivo'] = 'espacamento';
        return $out;
    }

    $limite = max(1, min((int)$limite, $cota['restam']));

    try {
        $st = $pdo->prepare("SELECT id, texto, tentativas FROM x_fila
                              WHERE postado_em IS NULL
                                AND tentativas < " . X_MAX_TENTATIVAS . "
                                AND (proxima_tentativa IS NULL OR proxima_tentativa <= NOW())
                              ORDER BY id ASC LIMIT " . $limite);
        $st->execute();
        $pendentes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[x] ler fila: ' . $e->getMessage());
        return $out;
    }

    if (!$pendentes) { $out['motivo'] = 'vazia'; return $out; }

    // O token é pego UMA vez, antes do laço, e já gravado: se a renovação
    // falhar, ninguém tenta postar com token morto e queimar tentativa.
    $tok = xToken($pdo);
    if (!$tok) { $out['motivo'] = 'sem-token'; return $out; }

    foreach ($pendentes as $p) {
        [$id, $erro] = xPostar($tok, (string)$p['texto']);
        try {
            if ($id) {
                $pdo->prepare("UPDATE x_fila SET postado_em = NOW(), tweet_id = ?, ultimo_erro = NULL,
                                   tentativas = tentativas + 1 WHERE id = ?")
                    ->execute([$id, (int)$p['id']]);
                xContarPost($pdo);
                $out['postados']++;
            } else {
                $espera = X_BACKOFF_MIN[min((int)$p['tentativas'], count(X_BACKOFF_MIN) - 1)];
                $pdo->prepare("UPDATE x_fila SET tentativas = tentativas + 1,
                                   proxima_tentativa = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                                   ultimo_erro = ? WHERE id = ?")
                    ->execute([$espera, mb_substr((string)$erro, 0, 255), (int)$p['id']]);
                $out['falhas']++;
            }
        } catch (Throwable $e) {
            error_log('[x] atualizar fila: ' . $e->getMessage());
        }
    }
    return $out;
}

/** Os últimos posts, saídos ou não — é o que a tela do admin mostra. */
function xUltimos(PDO $pdo, int $limite = 25): array
{
    try {
        xGarantirTabelas($pdo);
        $st = $pdo->prepare("SELECT * FROM x_fila ORDER BY id DESC LIMIT " . max(1, (int)$limite));
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[x] ultimos: ' . $e->getMessage());
        return [];
    }
}

/* ────────────────────────────────────────────────────────────────────────
 * OS TEXTOS
 * ──────────────────────────────────────────────────────────────────────── */

/**
 * O post de uma trade.
 *
 * Recebe blocos prontos — [['time' => 'Lakers', 'recebe' => ['Doncic (89)']]] —
 * e não o payload cru, porque trade de dois times e multi-trade têm formatos
 * diferentes lá no api/trades.php. Um formato só aqui dentro evita a versão
 * que já aconteceu no WhatsApp: dois montadores paralelos, e a idade do
 * jogador sumindo de um lado só.
 *
 * Enxuto de propósito. O texto do WhatsApp lista pick por pick e passa de 500
 * caracteres; aqui cabem 280, e o que importa na timeline é quem levou quem.
 * O detalhe está no site.
 */
function xTextoTrade(array $blocos, string $liga): string
{
    $linhas = ['🚨 BOMBA NA ' . strtoupper($liga), ''];
    foreach ($blocos as $b) {
        $itens = array_values(array_filter((array)($b['recebe'] ?? [])));
        if (!$itens) continue;
        // Três itens por time e o resto vira "+2": uma trade de cinco jogadores
        // por lado estouraria o post, e aí o corte comeria a última linha — que
        // é justamente o link.
        if (count($itens) > 3) {
            $sobra = count($itens) - 3;
            $itens = array_slice($itens, 0, 3);
            $itens[] = '+' . $sobra;
        }
        $linhas[] = $b['time'] . ' recebe: ' . implode(', ', $itens);
    }
    $linhas[] = '';
    $linhas[] = 'https://fbabrasil.com.br/trades.php';
    return implode("\n", $linhas);
}

/** Os itens de um bloco: "Doncic (89)", "Tatum (91)", "2 picks". */
function xItens(array $jogadores, array $picks = []): array
{
    $it = [];
    foreach ($jogadores as $p) {
        $nome = trim((string)($p['name'] ?? ''));
        if ($nome === '') continue;
        $ovr = (int)($p['ovr'] ?? 0);
        $it[] = $ovr ? $nome . ' (' . $ovr . ')' : $nome;
    }
    $n = count($picks);
    if ($n) $it[] = $n . ' pick' . ($n > 1 ? 's' : '');
    return $it;
}

/** O maior OVR que apareceu na trade — é ele que decide se é bombástica. */
function xMaiorOvr(array ...$listas): int
{
    $max = 0;
    foreach ($listas as $l) {
        foreach ($l as $p) $max = max($max, (int)($p['ovr'] ?? 0));
    }
    return $max;
}

/**
 * A trade vai pra timeline? Enfileira se for o caso.
 *
 * O ponto único de decisão de propósito: a trade de dois times e a
 * multi-trade chamam daqui, e a régua fica num lugar só. Nada aqui pode
 * estourar pra fora — quem chamou acabou de fechar uma trade e não pode
 * perdê-la porque o X está fora do ar.
 */
function xTradeParaTimeline(PDO $pdo, array $blocos, string $liga, int $tradeId, int $maiorOvr): void
{
    try {
        if ($maiorOvr < X_OVR_MIN_TRADE) return;
        $c = xConfig($pdo);
        if (!$c || empty($c['postar_trade'])) return;
        xEnfileirar($pdo, xTextoTrade($blocos, $liga), 'trade', 'trade:' . $tradeId);
    } catch (Throwable $e) {
        error_log('[x] trade: ' . $e->getMessage());
    }
}

/** A notícia vai pra timeline? Mesma ideia, mesma proteção. */
function xNoticiaParaTimeline(PDO $pdo, array $noticia): void
{
    try {
        $c = xConfig($pdo);
        if (!$c || empty($c['postar_news'])) return;
        xEnfileirar($pdo, xTextoNoticia($noticia), 'news', 'news:' . (int)$noticia['id']);
    } catch (Throwable $e) {
        error_log('[x] noticia: ' . $e->getMessage());
    }
}

/** O post de uma notícia do The Pathetic. */
function xTextoNoticia(array $noticia): string
{
    $chapeu = trim((string)($noticia['chapeu'] ?? ''));

    // O filtro é por null e NÃO por vazio: a linha em branco também é '' e um
    // array_filter comum comia as duas, colando cabeçalho, manchete e link
    // num bloco só. O que some aqui é só o chapéu quando a matéria não tem.
    $linhas = [
        '📰 THE PATHETIC',
        '',
        $chapeu !== '' ? strtoupper($chapeu) : null,
        trim((string)$noticia['titulo']),
        '',
        'https://fbabrasil.com.br/thepathetic.php?n=' . (int)$noticia['id'],
    ];
    return implode("\n", array_filter($linhas, fn($l) => $l !== null));
}
