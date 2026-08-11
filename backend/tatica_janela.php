<?php
/**
 * A janela de edição de tática: aberta ou fechada, e nada mais.
 *
 * Mora aqui porque TRÊS lugares precisavam da resposta — a API de táticas, o
 * painel de controle do admin e o dashboard — e cada um tinha a sua cópia da
 * regra. Quando a janela virou liga/desliga, só uma das cópias foi
 * atualizada: o painel continuou fechando sozinho às 17h e o dashboard
 * avisava "reabre às 17:00" numa liga que estava aberta.
 *
 * Regra atual, igual à da Free Agency: aberta = dá pra editar.
 *
 * As colunas daily_cutoff_time e manual_open_until continuam na tabela sem
 * uso — derrubá-las exigiria migração e elas não atrapalham onde estão.
 */

if (!function_exists('taticaGarantirTabelaJanela')) {
    function taticaGarantirTabelaJanela(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tactic_edit_windows (
            league VARCHAR(20) PRIMARY KEY,
            daily_cutoff_time TIME NOT NULL DEFAULT '17:00:00',
            manual_open_until DATETIME NULL,
            manual_closed TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('taticaJanela')) {
    /**
     * @return array{open: bool, reason: ?string}  Liga sem linha nasce aberta.
     */
    function taticaJanela(PDO $pdo, string $league): array
    {
        $st = $pdo->prepare('SELECT manual_closed FROM tactic_edit_windows WHERE league = ?');
        $st->execute([$league]);
        $linha = $st->fetch(PDO::FETCH_ASSOC);

        if ($linha === false) {
            $pdo->prepare('INSERT IGNORE INTO tactic_edit_windows (league) VALUES (?)')->execute([$league]);
            $linha = ['manual_closed' => 0];
        }

        $aberta = empty($linha['manual_closed']);
        return ['open' => $aberta, 'reason' => $aberta ? null : 'fechada pelo admin'];
    }
}
