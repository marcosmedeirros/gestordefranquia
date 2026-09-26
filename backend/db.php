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
    $pdo = new SuperlogPDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STATEMENT_CLASS => [SuperlogStatement::class, []],
    ]);

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
