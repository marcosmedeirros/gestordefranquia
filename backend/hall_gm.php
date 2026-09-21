<?php
/**
 * OS TÍTULOS SÃO DO GM, NÃO DA CADEIRA.
 *
 * O Hall da Fama credita título por (time, liga): quem for campeão soma +1 na
 * linha do time naquela liga (ver api/seasons.php, no registro da temporada).
 * Isso funciona enquanto ninguém troca de cadeira — e a FBA tem promoção e
 * rebaixamento, então gente troca de cadeira toda temporada.
 *
 * O que acontecia: o GM subia de liga, assumia outro time, e os títulos dele
 * ficavam na cadeira antiga — a cadeira que outra pessoa ia assumir. Quem
 * subiu perdia o que ganhou, e quem chegou herdava o que não fez. Aconteceu
 * com o Matheus Vicenzo (Sacramento Kings, ROOKIE → New York Storm Strikes,
 * RISE) em 21/09/2026, e foi corrigido na mão.
 *
 * A REGRA, agora: quando o GM muda de time, as linhas dele no Hall passam a
 * apontar pro time novo. O que NÃO muda:
 *
 *   - `league`, porque ela diz ONDE o título foi ganho. Dois títulos de ROOKIE
 *     continuam sendo dois títulos de ROOKIE depois que ele sobe pra RISE —
 *     reescrever isso apagaria a história que o Hall existe pra contar.
 *   - `titles`, obviamente: mudar de time não ganha nem perde troféu.
 *
 * E as linhas de OUTROS GMs no time antigo ficam onde estão: a cadeira pode ter
 * tido três donos, e só o que é do GM que está saindo vai com ele.
 */

require_once __DIR__ . '/helpers.php';

/** Compara nome de GM sem acento, sem caixa e sem espaço sobrando. */
function hallGmMesmoNome(?string $a, ?string $b): bool
{
    $limpa = static function (?string $s): string {
        $s = mb_strtolower(trim((string)$s));
        $s = strtr($s, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i',
                        'ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ü'=>'u','ç'=>'c']);
        return preg_replace('/\s+/', ' ', $s);
    };
    $x = $limpa($a); $y = $limpa($b);
    return $x !== '' && $x === $y;
}

/**
 * Leva os títulos do GM para a cadeira nova.
 *
 * @param int         $userId      quem mudou de time
 * @param string      $gmNome      o nome dele, pras linhas antigas sem user_id
 * @param int|null    $timeAntigo  a cadeira que ele deixou
 * @param int         $timeNovo    a cadeira que ele assumiu
 * @return array{movidas:int, somadas:int, linhas:array} o que foi mexido
 */
function hallSeguirGm(PDO $pdo, int $userId, string $gmNome, ?int $timeAntigo, int $timeNovo): array
{
    $out = ['movidas' => 0, 'somadas' => 0, 'linhas' => []];
    if (!$userId || !$timeNovo) return $out;

    try {
        ensureHallOfFameTable($pdo);

        $st = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(city,''),' ',COALESCE(name,''))) AS nome
                               FROM teams WHERE id = ?");
        $st->execute([$timeNovo]);
        $nomeTimeNovo = trim((string)$st->fetchColumn());
        if ($nomeTimeNovo === '') return $out;

        /* QUEM É DELE: a linha carimbada com o user_id, ou — pras antigas, que
           nasceram sem carimbo — a linha da cadeira que ele está deixando cujo
           gm_name é o nome dele. Sem a segunda condição, nada do passado seria
           encontrado, porque `user_id` só passou a ser preenchido agora. */
        $candidatas = [];
        $st = $pdo->prepare('SELECT * FROM hall_of_fame WHERE user_id = ?');
        $st->execute([$userId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) $candidatas[(int)$l['id']] = $l;

        if ($timeAntigo) {
            $st = $pdo->prepare('SELECT * FROM hall_of_fame WHERE team_id = ? AND (user_id IS NULL OR user_id = 0)');
            $st->execute([$timeAntigo]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
                if (hallGmMesmoNome($l['gm_name'] ?? '', $gmNome)) $candidatas[(int)$l['id']] = $l;
            }
        }
        if (!$candidatas) return $out;

        foreach ($candidatas as $linha) {
            $id = (int)$linha['id'];
            if ((int)$linha['team_id'] === $timeNovo) {
                // Já está na cadeira certa: só carimba o dono e segue.
                $pdo->prepare('UPDATE hall_of_fame SET user_id = ?, team_name = ? WHERE id = ?')
                    ->execute([$userId, $nomeTimeNovo, $id]);
                continue;
            }

            /* JÁ EXISTE LINHA DELE NO TIME NOVO PRA MESMA LIGA?
               Acontece quando ele volta pra uma cadeira onde já esteve. Duas
               linhas com o mesmo (time, liga) quebrariam o crédito do próximo
               título, que soma na primeira que encontrar — então junta. */
            $st = $pdo->prepare('SELECT id, titles, gm_name, user_id FROM hall_of_fame
                                  WHERE team_id = ? AND league = ? AND id <> ?');
            $st->execute([$timeNovo, $linha['league'], $id]);
            $irma = null;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if ((int)($c['user_id'] ?? 0) === $userId || hallGmMesmoNome($c['gm_name'] ?? '', $gmNome)) {
                    $irma = $c; break;
                }
            }

            if ($irma) {
                $pdo->prepare('UPDATE hall_of_fame SET titles = titles + ?, user_id = ?, team_name = ?, is_active = 1
                                WHERE id = ?')
                    ->execute([(int)$linha['titles'], $userId, $nomeTimeNovo, (int)$irma['id']]);
                $pdo->prepare('DELETE FROM hall_of_fame WHERE id = ?')->execute([$id]);
                $out['somadas']++;
                $out['linhas'][] = "{$linha['league']}: {$linha['titles']} título(s) somados à linha que já existia";
                continue;
            }

            // O caminho normal: a linha vai junto com ele, com a liga do
            // título intacta.
            $pdo->prepare('UPDATE hall_of_fame
                              SET team_id = ?, team_name = ?, user_id = ?, gm_name = ?
                            WHERE id = ?')
                ->execute([$timeNovo, $nomeTimeNovo, $userId, $gmNome !== '' ? $gmNome : ($linha['gm_name'] ?? ''), $id]);
            $out['movidas']++;
            $out['linhas'][] = "{$linha['league']}: {$linha['titles']} título(s) de {$linha['team_name']} → {$nomeTimeNovo}";
        }
    } catch (Throwable $e) {
        error_log('[hall-gm] seguir: ' . $e->getMessage());
    }

    return $out;
}
