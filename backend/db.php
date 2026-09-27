<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

// Define timezone padrão para todo o sistema: São Paulo/Brasília
date_default_timezone_set('America/Sao_Paulo');

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = loadConfig();
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['db']['host'], $config['db']['name'], $config['db']['charset']);

    /* SUPERLOG: a conexão anota em arquivo cada INSERT/UPDATE/DELETE que passa
       por ela (backend/superlog.php). Em arquivo, e não numa tabela, porque em
       26/09/2026 o banco inteiro foi apagado — uma tabela de auditoria teria
       ido junto. Se o arquivo não puder ser escrito, o superlog se cala e nada
       aqui muda. */
    require_once __DIR__ . '/superlog.php';

    /* O MYSQL DESTA HOSPEDAGEM PISCA, E QUEM VÊ É O GM.
       Em 27/09/2026, 12:41:54, o serviço reiniciou: o socket sumiu por cinco
       segundos e voltou às 12:42. Nesses cinco segundos toda página do site
       morreu com "SQLSTATE[HY000] [2002] No such file or directory" — stack
       trace na cara de quem estava fazendo a FA, com o caminho do servidor
       junto.

       Três tentativas com meio segundo entre elas cobrem uma piscada dessas
       sem que ninguém perceba. Não cobrem banco fora de verdade, e nem devem:
       aí a página cai de uma vez, com a mensagem de baixo. */
    $pdo = null;
    $ultimoErro = null;
    for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
        try {
            $pdo = new SuperlogPDO($dsn, $config['db']['user'], $config['db']['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STATEMENT_CLASS => [SuperlogStatement::class, []],
            ]);
            if ($tentativa > 1) error_log("[db] conectou na tentativa {$tentativa}");
            break;
        } catch (PDOException $e) {
            $ultimoErro = $e;
            if ($tentativa < 3) usleep(500000);
        }
    }

    if (!$pdo instanceof PDO) {
        error_log('[db] sem banco apos 3 tentativas: ' . ($ultimoErro ? $ultimoErro->getMessage() : '?'));
        dbForaDoAr();
    }

    // Definir timezone no MySQL também
    $pdo->exec("SET time_zone = '-03:00'");

    /* O BANCO DESTA HOSPEDAGEM FECHA A CONEXÃO EM 20 SEGUNDOS PARADA.
       (wait_timeout = 20, conferido em produção em 20/09/2026.)

       Vinte segundos é menos do que qualquer espera por serviço de fora. O bot
       pergunta ao Gemini, o modelo demora 30s, e quando a resposta volta a
       conexão já morreu: gravar a conversa falha, pôr a resposta na fila
       falha, e o GM no grupo simplesmente não recebe nada — sem erro, sem
       pista. Foi o que tirou o /duvida e o @ do ar naquele dia.

       A sessão pode esticar o próprio limite, e é o que se faz aqui. Cada
       requisição PHP fecha a conexão ao terminar, então isto não deixa
       conexão ociosa pendurada — só impede que ela morra no meio do trabalho. */
    try { $pdo->exec('SET SESSION wait_timeout = 300, SESSION interactive_timeout = 300'); }
    catch (Throwable $e) { error_log('[db] wait_timeout: ' . $e->getMessage()); }

    ensureSchema($pdo, $config['db']['name']);
    checkMaintenanceGate($pdo);

    // FECHAMENTO AGENDADO: taticas, trades e free agency fecham sozinhos na
    // hora que o admin marcou. Fica aqui, e nao num cron, porque cron nesta
    // hospedagem ja falhou em silencio antes — e fechamento que depende de
    // cron que ninguem agendou e fechamento que nao acontece. O custo e um
    // SELECT numa tabela de quatro linhas, uma vez por requisicao.
    require_once __DIR__ . '/agendamento_fechamento.php';
    try { agendaAplicarPendentes($pdo); } catch (Throwable $e) {
        error_log('[agenda] falhou: ' . $e->getMessage());
    }

    return $pdo;
}

/**
 * O banco não respondeu: encerra a requisição com cara de gente.
 *
 * O que havia antes era o fatal cru do PHP — stack trace, o caminho
 * /home/u289267434/... e nenhuma indicação do que fazer. Quem viu foi um GM no
 * meio da free agency, no celular.
 *
 * 503 e não 500: é indisponibilidade temporária, e é o que os robôs devem
 * entender. Na linha de comando não engole nada — script que roda sem banco
 * tem que estourar, senão a falha vira silêncio no cron.
 */
function dbForaDoAr(): void
{
    if (PHP_SAPI === 'cli') {
        throw new RuntimeException('Banco indisponível (3 tentativas).');
    }

    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 30');
    }

    $uri  = (string)($_SERVER['REQUEST_URI'] ?? '');
    $ehApi = str_contains($uri, '/api/')
        || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

    if ($ehApi) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Banco de dados indisponível no momento. Tente de novo em instantes.'],
            JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>FBA — um instante</title><style>'
       . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
       . 'background:#0f1115;color:#e8eaf0;font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;padding:24px}'
       . 'main{max-width:30rem;text-align:center}h1{font-size:1.5rem;margin:0 0 .5rem}'
       . 'p{margin:0 0 1.5rem;color:#a7adbb}'
       . 'a{display:inline-block;padding:.7rem 1.4rem;border-radius:.5rem;background:#3b6ef5;color:#fff;'
       . 'text-decoration:none;font-weight:600}</style></head><body><main>'
       . '<h1>O banco piscou</h1>'
       . '<p>O servidor da liga ficou fora do ar por alguns segundos. '
       . 'Não foi nada que você fez, e nada do que estava fazendo se perdeu.</p>'
       . '<a href="' . htmlspecialchars($uri !== '' ? $uri : '/', ENT_QUOTES) . '">Tentar de novo</a>'
       . '</main></body></html>';
    exit;
}

/**
 * A conexão, viva — reabrindo se ela tiver morrido no caminho.
 *
 * O cinto sobre o wait_timeout esticado lá em cima: sobra pra espera que passar
 * dos cinco minutos e pra queda por outro motivo (o servidor derrubou, a rede
 * piscou). Quem chama TEM que usar o retorno, porque uma conexão nova é um
 * objeto novo — `dbRevive($pdo)` sem o `$pdo =` na frente não conserta nada.
 *
 * Não reabriu? Devolve a morta, e quem chamou trata o erro da escrita como
 * trataria antes — nunca é pior do que era.
 */
function dbRevive(PDO $pdo): PDO
{
    try {
        $pdo->query('SELECT 1');
        return $pdo;
    } catch (Throwable $e) {
        error_log('[db] conexão caiu, reabrindo: ' . $e->getMessage());
    }

    try {
        $c   = loadConfig()['db'];
        // A conexão reaberta também anota: um log com buraco toda vez que a
        // conexão cai não serviria pra reconstruir nada.
        require_once __DIR__ . '/superlog.php';
        $novo = new SuperlogPDO(
            sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['host'], $c['name'], $c['charset']),
            $c['user'], $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_STATEMENT_CLASS => [SuperlogStatement::class, []]]
        );
        $novo->exec("SET time_zone = '-03:00'");
        try { $novo->exec('SET SESSION wait_timeout = 300, SESSION interactive_timeout = 300'); }
        catch (Throwable $e) {}
        return $novo;
    } catch (Throwable $e) {
        error_log('[db] reabrir falhou: ' . $e->getMessage());
        return $pdo;
    }
}

// dbGames() foi removida na fusão: o games passou a viver no mesmo banco do
// site, então não existe mais uma segunda conexão.

function ensureSchema(PDO $pdo, string $dbName): void
{
    // Carrega e executa migrações automáticas — mas só de verdade a cada X segundos.
    // Rodar a lista inteira (dezenas de SHOW/ALTER) em toda requisição deixava até o
    // login lento (~1.5s só nisso), o que sob carga real pode até estourar timeout
    // no cliente. As migrações são idempotentes, então pular a maioria das chamadas
    // é seguro — o próximo request após o throttle aplica qualquer migração nova.
    $marker = __DIR__ . '/.migrations_last_run';
    $throttleSeconds = 60;
    if (is_file($marker) && (time() - (int)@file_get_contents($marker)) < $throttleSeconds) {
        return;
    }

    require_once __DIR__ . '/migrations.php';

    try {
        runMigrations();
    } catch (Exception $e) {
        error_log('Erro ao executar migrações: ' . $e->getMessage());
    }

    @file_put_contents($marker, (string)time());
}
