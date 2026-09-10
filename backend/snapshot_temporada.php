<?php
/**
 * CONGELA O ELENCO DE UM TIME NA TEMPORADA.
 *
 * Grava OVR, idade, posição e as dez letras de cada jogador em
 * player_season_log — é o que alimenta a comparação por temporada no perfil
 * do jogador e a contagem de "times atualizados" do checklist.
 *
 * Morava inteiro dentro de api/player_stats.php, no botão do GM. Saiu pra cá
 * quando o controle de elencos do admin passou a precisar da mesma coisa:
 * duas cópias da regra de snapshot seriam duas histórias diferentes do mesmo
 * jogador no dia em que uma delas mudasse.
 *
 * Rodar de novo na mesma temporada atualiza a linha em vez de duplicar.
 *
 * @param array $season ['id', 'season_number', 'year']
 * @return array{ok:bool, saved:int}
 */
function snapshotTemporadaDoTime(PDO $pdo, int $teamId, array $season, string $league): array
{
    $stmtP = $pdo->prepare("SELECT id, name, ovr, age, position,
                                   skill_in, skill_mid, skill_3pt, skill_post_d, skill_per_d,
                                   skill_play, skill_reb, skill_athl, skill_iq, skill_pot
                            FROM players WHERE team_id = ?");
    $stmtP->execute([$teamId]);
    $jogadores = $stmtP->fetchAll(PDO::FETCH_ASSOC);
    if (!$jogadores) return ['ok' => true, 'saved' => 0];

    $stmtTime = $pdo->prepare("SELECT CONCAT(city,' ',name) AS nome FROM teams WHERE id = ?");
    $stmtTime->execute([$teamId]);
    $nomeTime = $stmtTime->fetchColumn() ?: '';

    $stmtSel = $pdo->prepare('SELECT id FROM player_season_log WHERE player_id = ? AND season_id = ? LIMIT 1');
    $campos = 'ovr=?, age=?, position=?, team_id=?, team_name=?, league=?, season_number=?, year=?,
               skill_in=?, skill_mid=?, skill_3pt=?, skill_post_d=?, skill_per_d=?,
               skill_play=?, skill_reb=?, skill_athl=?, skill_iq=?, skill_pot=?';
    $stmtUpd = $pdo->prepare("UPDATE player_season_log SET {$campos} WHERE id = ?");
    $stmtIns = $pdo->prepare("INSERT INTO player_season_log
        (player_id, player_name, season_id, ovr, age, position, team_id, team_name, league,
         season_number, year, skill_in, skill_mid, skill_3pt, skill_post_d, skill_per_d,
         skill_play, skill_reb, skill_athl, skill_iq, skill_pot)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $ok = 0;
    $pdo->beginTransaction();
    try {
        foreach ($jogadores as $p) {
            $skills = [$p['skill_in'], $p['skill_mid'], $p['skill_3pt'], $p['skill_post_d'],
                       $p['skill_per_d'], $p['skill_play'], $p['skill_reb'], $p['skill_athl'],
                       $p['skill_iq'], $p['skill_pot']];
            $stmtSel->execute([(int)$p['id'], (int)$season['id']]);
            $existente = $stmtSel->fetchColumn();

            $comuns = [(int)$p['ovr'], (int)$p['age'], $p['position'], $teamId, $nomeTime, $league,
                       (int)$season['season_number'], (int)($season['year'] ?? 0)];

            if ($existente) {
                $stmtUpd->execute(array_merge($comuns, $skills, [(int)$existente]));
            } else {
                $stmtIns->execute(array_merge(
                    [(int)$p['id'], $p['name'], (int)$season['id']],
                    $comuns, $skills
                ));
            }
            $ok++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[snapshot] time ' . $teamId . ': ' . $e->getMessage());
        return ['ok' => false, 'saved' => 0];
    }
    return ['ok' => true, 'saved' => $ok];
}
