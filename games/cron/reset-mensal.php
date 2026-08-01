<?php
/**
 * CRON: reset-mensal.php
 * Zera pontos (moedas) e fba_points de todos os usuários.
 * Deve ser executado todo dia 01 de cada mês às 00:00:01 BRT (= 03:00:01 UTC).
 *
 * Configuração no cPanel / Hostinger:
 *   1 3 1 * *   /usr/local/bin/php /home/u289267434/domains/games.fbabrasil.com.br/public_html/cron/reset-mensal.php
 *
 * Ou via URL protegida por chave (ex.: para cron externo / EasyCron):
 *   https://games.fbabrasil.com.br/cron/reset-mensal.php?key=SEU_CRON_SECRET
 */

// A chave sai do código-fonte: vem do ambiente. Sem chave configurada, só
// roda por CLI — que é como o cron da Hostinger chama de qualquer forma.
$cron_secret = (string) (getenv('FBA_GAMES_CRON_SECRET') ?: '');

$via_cli  = (PHP_SAPI === 'cli');
$via_http = $cron_secret !== ''
    && isset($_GET['key'])
    && hash_equals($cron_secret, (string) $_GET['key']);

if (!$via_cli && !$via_http) {
    http_response_code(403);
    exit('Acesso negado.');
}

// ── Fuso horário de Brasília ───────────────────────────────────────────────────
date_default_timezone_set('America/Sao_Paulo');

$agora = date('Y-m-d H:i:s');
$log_file = __DIR__ . '/../logs/reset-mensal.log';

function log_msg(string $msg): void {
    global $log_file, $agora;
    $linha = "[{$agora}] {$msg}" . PHP_EOL;
    file_put_contents($log_file, $linha, FILE_APPEND | LOCK_EX);
    if (PHP_SAPI === 'cli') echo $linha;
}

// ── Conexão ────────────────────────────────────────────────────────────────────
// Depois da fusão o games vive no banco do site — sem credencial própria aqui.
require_once __DIR__ . '/../../backend/db.php';

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    log_msg("ERRO conexão: " . $e->getMessage());
    exit(1);
}

// ── Verificação: só roda no dia 01 ────────────────────────────────────────────
// (Proteção extra caso o cron dispare em dia errado)
if ((int)date('j') !== 1) {
    log_msg("Ignorado: hoje não é dia 01 (dia=" . date('j') . ").");
    exit(0);
}

// ── Acumula na retrospectiva ANTES do reset ────────────────────────────────────
try {
    $ano = (int)date('Y');
    $pdo->exec("
        INSERT INTO retrospectiva_anual (user_id, ano, total_fba_points, total_moedas)
        SELECT id, {$ano}, COALESCE(fba_points, 0), COALESCE(pontos, 0)
        FROM games_usuarios
        ON DUPLICATE KEY UPDATE
            total_fba_points = total_fba_points + VALUES(total_fba_points),
            total_moedas     = total_moedas     + VALUES(total_moedas)
    ");
    log_msg("RETROSPECTIVA: pontos/moedas do mês acumulados para o ano {$ano}.");
} catch (PDOException $e) {
    log_msg("AVISO retrospectiva (crie as tabelas primeiro): " . $e->getMessage());
}

// ── Reset ──────────────────────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();

    // Conta usuários antes do reset para o log
    $total = (int)$pdo->query("SELECT COUNT(*) FROM games_usuarios")->fetchColumn();

    // Zera moedas (pontos) e FBA Points
    $afetados = $pdo->exec("UPDATE games_usuarios SET pontos = 0, fba_points = 0");

    $pdo->commit();

    log_msg("RESET MENSAL OK — {$afetados}/{$total} usuários zerados (pontos + fba_points).");

    if ($via_http) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok'       => true,
            'reset_em' => $agora,
            'usuarios' => $afetados,
        ]);
    }

} catch (PDOException $e) {
    $pdo->rollBack();
    log_msg("ERRO reset: " . $e->getMessage());
    if ($via_http) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit(1);
}
