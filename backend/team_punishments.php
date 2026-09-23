<?php
/**
 * Reset em massa de punições e avisos (FBA SERASA) de uma liga — usado no
 * fim de sprint (automático, via api/seasons.php) e no botão manual do
 * admin (api/punicoes.php). Fica fora de api/punicoes.php de propósito:
 * aquele arquivo roda como endpoint completo (auth + exit no topo), então
 * não dá pra incluir como biblioteca sem disparar o gate dele.
 *
 * "Avisos"/FBA SERASA não é uma tabela separada — é o mesmo team_punishments
 * com type='AVISO_TRADE'. Zerar = apagar de vez todo o histórico de
 * punições/avisos da liga (ativos e já revertidos) — não é um revert em
 * massa, é limpeza mesmo, sem deixar rastro pra trás. Não mexe em picks
 * perdidas por punição: no fim de sprint o pool de picks inteiro da liga
 * já é recriado do zero.
 */

/**
 * Os tipos que moram em team_punishments mas NÃO são punição.
 *
 * Sobrou um só: o AVISO_TRADE, o FBA SERASA. Ele é automático — o sistema
 * anota sozinho quem deixou proposta sem resposta —, sai às centenas e tem
 * contador próprio na tela de times. Somar isso ao contador de punições
 * faria todo mundo parecer fichado.
 *
 * O AVISO_FORMAL SAIU DESTA LISTA (23/09/2026). Ele estava aqui pelo mesmo
 * raciocínio — "recado é alerta, não pena" —, mas o efeito na prática era
 * outro: advertência formal é o 1º degrau do quadro do edital, e ficando
 * fora do contador, do feed e do histórico do time ela não aparecia em
 * lugar nenhum do app. Advertência que ninguém vê não adverte. A lista de
 * punições mostra o rótulo de cada uma, então quem lê continua distinguindo
 * uma advertência de uma pick perdida — o que faltava era a advertência
 * existir na tela.
 */
const PUNICAO_TIPOS_DE_AVISO = ['AVISO_TRADE'];

/**
 * O pedaço de WHERE que tira os avisos da conta. O alias é o da tabela na
 * query ('tp.' na maioria; vazio quando o SELECT é direto em team_punishments).
 *
 * Uma exceção de propósito: o painel do admin (api/punicoes.php) continua
 * listando o aviso formal. É lá que ele é aplicado e revertido — some da
 * lista e vira punição fantasma, ativa e sem como desfazer.
 */
function sqlSoPunicoes(string $alias = 'tp'): string
{
    $col   = ($alias === '' ? '' : $alias . '.') . 'type';
    $lista = "'" . implode("','", PUNICAO_TIPOS_DE_AVISO) . "'";
    return " AND {$col} NOT IN ({$lista})";
}

function resetPunicoesEAvisosDaLiga(PDO $pdo, string $league, ?int $triggeredBy = null): array
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS team_punishments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            team_id INT NOT NULL,
            league VARCHAR(20) NULL,
            type VARCHAR(50) NOT NULL,
            motive VARCHAR(120) NULL,
            punishment_label VARCHAR(120) NULL,
            effect_type VARCHAR(50) NULL,
            notes TEXT NULL,
            pick_id INT NULL,
            season_scope VARCHAR(20) NULL,
            ban_until_cycle INT NULL,
            removed_pick_season_year INT NULL,
            removed_pick_round INT NULL,
            removed_pick_original_team_id INT NULL,
            removed_pick_last_owner_team_id INT NULL,
            reverted_at DATETIME NULL,
            reverted_by INT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_punishments_team (team_id),
            INDEX idx_punishments_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Throwable $e) {}

    $stmtCount = $pdo->prepare("
        SELECT COUNT(*) FROM team_punishments tp
        INNER JOIN teams t ON t.id = tp.team_id
        WHERE t.league = ?
    ");
    $stmtCount->execute([$league]);
    $total = (int)$stmtCount->fetchColumn();

    if ($total > 0) {
        $pdo->prepare("
            DELETE tp FROM team_punishments tp
            INNER JOIN teams t ON t.id = tp.team_id
            WHERE t.league = ?
        ")->execute([$league]);
        error_log("[resetPunicoesEAvisosDaLiga] liga={$league} apagados={$total} por user_id=" . ($triggeredBy ?? 'sistema'));
    }

    // Limpa os banimentos vigentes (trades/picks/FA/rotação automática) —
    // best-effort: instalação nova pode ainda não ter essas colunas.
    try {
        $pdo->prepare("UPDATE teams SET
                ban_trades_until_cycle = NULL, ban_trades_picks_until_cycle = NULL,
                ban_fa_until_cycle = NULL, auto_rotation_until_cycle = NULL
            WHERE league = ?")->execute([$league]);
    } catch (Throwable $e) {}

    return ['apagados' => $total];
}
