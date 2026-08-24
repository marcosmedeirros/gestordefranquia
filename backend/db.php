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
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Definir timezone no MySQL também
    $pdo->exec("SET time_zone = '-03:00'");

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
