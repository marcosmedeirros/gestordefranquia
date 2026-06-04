<?php
/**
 * retrospectiva-snapshot.php
 * Consolida todos os dados do ano em retrospectiva_anual.
 * Seguro rodar mais de uma vez (usa ON DUPLICATE KEY UPDATE).
 *
 * Crontab sugerido (1 de dezembro às 02:00 BRT = 05:00 UTC):
 *   0 5 1 12 *  /usr/local/bin/php /home/.../cron/retrospectiva-snapshot.php
 *
 * Via URL:
 *   https://games.fbabrasil.com.br/cron/retrospectiva-snapshot.php?key=SEU_CRON_SECRET
 *   Adicione &ano=2025 para especificar o ano.
 */

define('CRON_SECRET', 'fba_reset_2025_@xK9!mQ');

$via_cli  = (PHP_SAPI === 'cli');
$via_http = isset($_GET['key']) && hash_equals(CRON_SECRET, $_GET['key']);

if (!$via_cli && !$via_http) { http_response_code(403); exit('Acesso negado.'); }

date_default_timezone_set('America/Sao_Paulo');
$log_file = __DIR__ . '/../logs/retrospectiva-snapshot.log';

function logR(string $msg): void {
    global $log_file;
    $linha = "[" . date('Y-m-d H:i:s') . "] {$msg}" . PHP_EOL;
    @file_put_contents($log_file, $linha, FILE_APPEND | LOCK_EX);
    if (PHP_SAPI === 'cli') echo $linha;
}

// Conexão
try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=u289267434_gamesfba;charset=utf8mb4",
        'u289267434_gamesfba', 'Gamesfba@123',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) { logR("ERRO conexão: " . $e->getMessage()); exit(1); }

$ano = (int)($_GET['ano'] ?? date('Y'));
logR("Iniciando snapshot {$ano}...");

$usuarios    = $pdo->query("SELECT id FROM usuarios")->fetchAll(PDO::FETCH_COLUMN);
$processados = 0; $erros = 0;

foreach ($usuarios as $uid) {
    $uid = (int)$uid;
    try {

        // ── Acessos ────────────────────────────────────────────────────────
        $acessos = q1($pdo, "SELECT COUNT(*) FROM usuario_acessos
            WHERE user_id = $uid AND YEAR(data_acesso) = $ano");

        // ── Figurinhas ─────────────────────────────────────────────────────
        $figurinhas = q1($pdo, "SELECT COALESCE(SUM(quantity),0)
            FROM album_fba_collection WHERE user_id = $uid");

        // ── Pinguim (dino_historico — usa created_at) ──────────────────────
        $dino = qrow($pdo, "SELECT COUNT(*) AS p,
            COALESCE(MAX(pontuacao_final),0) AS r,
            COALESCE(SUM(pontuacao_final),0) AS t
            FROM dino_historico
            WHERE id_usuario = $uid AND YEAR(created_at) = $ano");

        // ── Flappy ─────────────────────────────────────────────────────────
        $flappy = qrow($pdo, "SELECT COUNT(*) AS p,
            COALESCE(MAX(pontuacao),0) AS r,
            COALESCE(SUM(pontuacao),0) AS t
            FROM flappy_historico
            WHERE id_usuario = $uid AND YEAR(data_jogo) = $ano");

        // ── Termo ──────────────────────────────────────────────────────────
        $termo = qrow($pdo, "SELECT COUNT(*) AS p,
            COALESCE(SUM(ganhou),0) AS a,
            COALESCE(MAX(streak_count),0) AS s
            FROM termo_historico
            WHERE id_usuario = $uid AND YEAR(data_jogo) = $ano");

        // ── Termo Dueto ────────────────────────────────────────────────────
        $dueto = qrow($pdo, "SELECT COUNT(*) AS p,
            SUM(CASE WHEN (ganhou_1+ganhou_2) >= 2 THEN 1 ELSE 0 END) AS a
            FROM termo_dueto_historico
            WHERE id_usuario = $uid AND YEAR(data_jogo) = $ano");

        // ── Memória (acerto = pontos_ganhos > 0) ───────────────────────────
        $mem = qrow($pdo, "SELECT COUNT(*) AS p,
            SUM(CASE WHEN pontos_ganhos > 0 THEN 1 ELSE 0 END) AS a,
            COALESCE(MAX(streak_count),0) AS s
            FROM memoria_historico
            WHERE id_usuario = $uid AND YEAR(data_jogo) = $ano");

        // ── Conexo ─────────────────────────────────────────────────────────
        $conexo = qrow($pdo, "SELECT COUNT(*) AS p,
            COALESCE(SUM(concluido),0) AS a
            FROM conexoes_historico
            WHERE id_usuario = $uid AND YEAR(data_jogo) = $ano");

        // ── Box NBA ────────────────────────────────────────────────────────
        $boxnba = qrow($pdo, "SELECT COUNT(*) AS p,
            COALESCE(SUM(concluido),0) AS a,
            COALESCE(MAX(streak_count),0) AS s
            FROM boxnba_historico
            WHERE id_usuario = $uid AND YEAR(data_jogo) = $ano");

        // ── Grade (HoopGrid) ───────────────────────────────────────────────
        $grade = qrow($pdo, "SELECT COUNT(*) AS p,
            COALESCE(SUM(concluido),0) AS a
            FROM grade_historico
            WHERE id_usuario = $uid AND YEAR(data_jogo) = $ano");

        // ── Bomba ──────────────────────────────────────────────────────────
        $bomba = qrow($pdo, "SELECT COUNT(*) AS p,
            SUM(CASE WHEN status != 'perdeu' THEN 1 ELSE 0 END) AS a,
            COALESCE(SUM(diamantes_achados),0) AS d,
            COALESCE(MAX(streak_count),0) AS s
            FROM bomba_historico
            WHERE id_usuario = $uid AND YEAR(data_jogo) = $ano");

        // ── Quem Sou Eu NBA ────────────────────────────────────────────────
        $qsb = qrow($pdo, "SELECT COUNT(*) AS p,
            COALESCE(SUM(resolvido),0) AS a
            FROM quemsoueu_basquete
            WHERE id_usuario = $uid AND YEAR(data_jogo) = $ano");

        // ── Quem Sou Eu Futebol ────────────────────────────────────────────
        $qsf = qrow($pdo, "SELECT COUNT(*) AS p,
            COALESCE(SUM(resolvido),0) AS a
            FROM quemsoueu_futebol
            WHERE id_usuario = $uid AND YEAR(data_jogo) = $ano");

        // ── Apostas ────────────────────────────────────────────────────────
        $ap = qrow($pdo, "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN e.status = 'encerrada' AND e.vencedor_opcao_id = p.opcao_id
                    THEN 1 ELSE 0 END) AS acertadas,
                SUM(CASE WHEN e.status = 'encerrada' AND e.vencedor_opcao_id != p.opcao_id
                    THEN 1 ELSE 0 END) AS erradas,
                COALESCE(SUM(p.valor), 0) AS apostado,
                COALESCE(SUM(CASE WHEN e.status = 'encerrada' AND e.vencedor_opcao_id = p.opcao_id
                    THEN p.valor * p.odd_registrada ELSE 0 END), 0) AS ganhos
            FROM palpites p
            JOIN opcoes o ON p.opcao_id = o.id
            JOIN eventos e ON o.evento_id = e.id
            WHERE p.id_usuario = $uid AND YEAR(p.data_palpite) = $ano
        ");

        // ── Tigrinho ───────────────────────────────────────────────────────
        $tig = qrow($pdo, "SELECT COUNT(*) AS spins,
            COALESCE(SUM(aposta),0) AS apostado,
            COALESCE(SUM(premio),0) AS ganhos
            FROM tigrinho_historico
            WHERE id_usuario = $uid AND YEAR(created_at) = $ano");

        // ── Upsert ─────────────────────────────────────────────────────────
        $pdo->prepare("
            INSERT INTO retrospectiva_anual
                (user_id, ano,
                 total_acessos, total_figurinhas,
                 pinguim_recorde, pinguim_total_pts, pinguim_partidas,
                 flappy_recorde, flappy_total_pts, flappy_partidas,
                 termo_partidas, termo_acertos, termo_streak_max,
                 termo_dueto_partidas, termo_dueto_acertos,
                 memoria_partidas, memoria_acertos, memoria_streak_max,
                 conexo_partidas, conexo_acertos,
                 boxnba_partidas, boxnba_acertos, boxnba_streak_max,
                 grade_partidas, grade_acertos,
                 bomba_partidas, bomba_acertos, bomba_diamantes, bomba_streak_max,
                 qse_nba_partidas, qse_nba_acertos,
                 qse_fut_partidas, qse_fut_acertos,
                 apostas_total, apostas_acertadas, apostas_erradas,
                 apostas_total_apostado, apostas_total_ganhos,
                 tigrinho_spins, tigrinho_apostado, tigrinho_ganhos,
                 snapshot_feito)
            VALUES (?,?, ?,?, ?,?,?, ?,?,?, ?,?,?, ?,?, ?,?,?, ?,?, ?,?,?, ?,?,
                    ?,?,?,?, ?,?, ?,?, ?,?,?, ?,?, ?,?,?, 1)
            ON DUPLICATE KEY UPDATE
                total_acessos          = VALUES(total_acessos),
                total_figurinhas       = VALUES(total_figurinhas),
                pinguim_recorde        = VALUES(pinguim_recorde),
                pinguim_total_pts      = VALUES(pinguim_total_pts),
                pinguim_partidas       = VALUES(pinguim_partidas),
                flappy_recorde         = VALUES(flappy_recorde),
                flappy_total_pts       = VALUES(flappy_total_pts),
                flappy_partidas        = VALUES(flappy_partidas),
                termo_partidas         = VALUES(termo_partidas),
                termo_acertos          = VALUES(termo_acertos),
                termo_streak_max       = VALUES(termo_streak_max),
                termo_dueto_partidas   = VALUES(termo_dueto_partidas),
                termo_dueto_acertos    = VALUES(termo_dueto_acertos),
                memoria_partidas       = VALUES(memoria_partidas),
                memoria_acertos        = VALUES(memoria_acertos),
                memoria_streak_max     = VALUES(memoria_streak_max),
                conexo_partidas        = VALUES(conexo_partidas),
                conexo_acertos         = VALUES(conexo_acertos),
                boxnba_partidas        = VALUES(boxnba_partidas),
                boxnba_acertos         = VALUES(boxnba_acertos),
                boxnba_streak_max      = VALUES(boxnba_streak_max),
                grade_partidas         = VALUES(grade_partidas),
                grade_acertos          = VALUES(grade_acertos),
                bomba_partidas         = VALUES(bomba_partidas),
                bomba_acertos          = VALUES(bomba_acertos),
                bomba_diamantes        = VALUES(bomba_diamantes),
                bomba_streak_max       = VALUES(bomba_streak_max),
                qse_nba_partidas       = VALUES(qse_nba_partidas),
                qse_nba_acertos        = VALUES(qse_nba_acertos),
                qse_fut_partidas       = VALUES(qse_fut_partidas),
                qse_fut_acertos        = VALUES(qse_fut_acertos),
                apostas_total          = VALUES(apostas_total),
                apostas_acertadas      = VALUES(apostas_acertadas),
                apostas_erradas        = VALUES(apostas_erradas),
                apostas_total_apostado = VALUES(apostas_total_apostado),
                apostas_total_ganhos   = VALUES(apostas_total_ganhos),
                tigrinho_spins         = VALUES(tigrinho_spins),
                tigrinho_apostado      = VALUES(tigrinho_apostado),
                tigrinho_ganhos        = VALUES(tigrinho_ganhos),
                snapshot_feito         = 1
        ")->execute([
            $uid, $ano,
            $acessos, $figurinhas,
            $dino['r'],  $dino['t'],  $dino['p'],
            $flappy['r'],$flappy['t'],$flappy['p'],
            $termo['p'],  $termo['a'],  $termo['s'],
            $dueto['p'],  $dueto['a'],
            $mem['p'],    $mem['a'],    $mem['s'],
            $conexo['p'], $conexo['a'],
            $boxnba['p'], $boxnba['a'], $boxnba['s'],
            $grade['p'],  $grade['a'],
            $bomba['p'],  $bomba['a'],  $bomba['d'],  $bomba['s'],
            $qsb['p'],    $qsb['a'],
            $qsf['p'],    $qsf['a'],
            $ap['total'],  $ap['acertadas'], $ap['erradas'],
            $ap['apostado'], $ap['ganhos'],
            $tig['spins'], $tig['apostado'], $tig['ganhos'],
        ]);

        $processados++;
    } catch (PDOException $e) {
        logR("ERRO uid={$uid}: " . $e->getMessage());
        $erros++;
    }
}

logR("Snapshot {$ano} concluído — {$processados} ok / {$erros} erros de " . count($usuarios) . " usuários.");

if ($via_http) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'ano' => $ano, 'processados' => $processados, 'erros' => $erros]);
}

// ── Helpers ────────────────────────────────────────────────────────────────────
function q1(PDO $pdo, string $sql): int {
    try { return (int)$pdo->query($sql)->fetchColumn(); } catch (PDOException $e) { return 0; }
}
function qrow(PDO $pdo, string $sql): array {
    try {
        $r = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        return $r ?: [];
    } catch (PDOException $e) { return []; }
}
