<?php
/**
 * A CONTA DA FORÇA DE UM TIME, num lugar só.
 *
 * A fórmula do power ranking nasceu dentro do mundo-fba.php e foi copiada
 * pro bot (wcForcaDoTime, em api/whatsapp-comandos.php) — o comentário de lá
 * já admite a cópia e avisa que "se a de lá mudar, esta tem que mudar
 * junto". Agora a projeção da ordem do draft precisava da MESMA régua, e uma
 * terceira cópia seria a garantia de que um dia as três discordariam sobre
 * quem é o time mais fraco da liga.
 *
 * Então a conta mora aqui. O mundo-fba passa a chamá-la; o bot segue com a
 * dele por enquanto, porque lá o banco também pesa e mexer no bot não era o
 * assunto — mas quando for, é pra cá que ele vem.
 */

/**
 * A força do time a partir do quinteto: média e teto de OVR, com bônus de
 * juventude, castigo de idade e o peso de quem acabou de decidir o título.
 *
 * @param float $mediaOvr  média de OVR do top 5
 * @param int   $tetoOvr   maior OVR do top 5
 * @param float $mediaIdade média de idade do top 5
 * @param int   $quantos   quantos titulares o time tem de verdade (< 5 pesa contra)
 */
function fbaPowerScore(float $mediaOvr, int $tetoOvr, float $mediaIdade, int $quantos,
                       bool $ehCampeao = false, bool $ehVice = false): float
{
    $juventude = $mediaIdade > 0 ? max(0, 30 - $mediaIdade) : 0;
    $castigo   = $mediaIdade > 32 ? ($mediaIdade - 32) * 1.8 : 0;

    $score = ($mediaOvr * 1.6) + ($tetoOvr * 0.6) + ($juventude * 0.8) - $castigo;

    // Quem tem franquia de verdade (89+) leva um empurrão: numa liga de
    // médias parecidas, ter o melhor jogador da sala é o desempate.
    if ($tetoOvr >= 89) $score += 2.0;

    // Elenco incompleto não é elenco forte.
    if ($quantos < 5) $score -= (5 - $quantos) * 1.2;

    if ($ehCampeao) $score += 5.0;
    if ($ehVice)    $score += 3.0;

    return round($score, 1);
}

/**
 * O top 5 de um elenco, já resumido em média/teto/idade.
 *
 * @param array $jogadores linhas com 'ovr' e 'age'
 */
function fbaPowerTop5(array $jogadores): array
{
    $lista = array_values(array_filter($jogadores));
    if (!$lista) return ['quantos' => 0, 'media_ovr' => 0.0, 'media_idade' => 0.0, 'teto_ovr' => 0];

    usort($lista, static fn($a, $b) => (int)($b['ovr'] ?? 0) - (int)($a['ovr'] ?? 0));
    $top = array_slice($lista, 0, 5);

    $somaOvr = 0; $somaIdade = 0; $teto = 0;
    foreach ($top as $p) {
        $ovr = (int)($p['ovr'] ?? 0);
        $somaOvr   += $ovr;
        $somaIdade += (int)($p['age'] ?? 0);
        if ($ovr > $teto) $teto = $ovr;
    }
    $n = count($top);
    return [
        'quantos'     => $n,
        'media_ovr'   => $n ? round($somaOvr / $n, 1) : 0.0,
        'media_idade' => $n ? round($somaIdade / $n, 1) : 0.0,
        'teto_ovr'    => $teto,
    ];
}

/**
 * O power ranking da liga, do mais forte pro mais fraco.
 *
 * Mesma régua da página do Mundo FBA: só quem está marcado como Titular
 * conta, e o quinteto é o top 5 de OVR entre eles.
 *
 * @return array<int,array> cada item com team_id, nome, dono, score e a
 *                          posição (1 = mais forte)
 */
function fbaPowerRanking(PDO $pdo, string $league): array
{
    $st = $pdo->prepare("
        SELECT t.id, t.city, t.name, t.photo_url AS team_photo, t.team_tag,
               COALESCE(t.conference, '') AS conference,
               u.name AS owner_name
        FROM teams t
        LEFT JOIN users u ON u.id = t.user_id
        WHERE t.league = ?
        ORDER BY t.name ASC");
    $st->execute([$league]);
    $times = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$times) return [];

    // Campeão e vice da última temporada fechada — o mesmo bônus que a
    // página aplica.
    $campeao = $vice = 0;
    try {
        // season_history, e do sprint ATIVO — é onde o mundo-fba busca. A
        // tabela guarda a história inteira da liga, então sem o recorte do
        // sprint o bônus iria pro campeão de uma era que já acabou.
        $s = $pdo->prepare("SELECT champion_team_id, runner_up_team_id
                            FROM season_history
                            WHERE league = ?
                              AND season_id IN (SELECT id FROM seasons WHERE sprint_id =
                                    (SELECT id FROM sprints WHERE league = ? AND status = 'active'
                                     ORDER BY id DESC LIMIT 1))
                            ORDER BY year DESC, sprint_number DESC, season_number DESC, id DESC
                            LIMIT 1");
        $s->execute([$league, $league]);
        if ($r = $s->fetch(PDO::FETCH_ASSOC)) {
            $campeao = (int)($r['champion_team_id'] ?? 0);
            $vice    = (int)($r['runner_up_team_id'] ?? 0);
        }
    } catch (Throwable $e) {
        // Liga sem temporada fechada: ninguém leva bônus, e o ranking segue.
    }

    // O quinteto é UM POR POSIÇÃO, e não os cinco melhores de OVR: é assim
    // que o Mundo FBA monta, e a projeção do draft tem que dar a mesma ordem
    // que a liga vê na página. Com os cinco melhores, um time de três alas
    // fortes e pivô fraco subiria aqui e não lá.
    $elenco = $pdo->prepare("SELECT position, ovr, age FROM players
                             WHERE team_id = ? AND role = 'Titular'
                             ORDER BY FIELD(position,'PG','SG','SF','PF','C') LIMIT 10");

    $saida = [];
    foreach ($times as $t) {
        $elenco->execute([(int)$t['id']]);
        $porPos = ['PG' => null, 'SG' => null, 'SF' => null, 'PF' => null, 'C' => null];
        foreach ($elenco->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $pos = (string)($p['position'] ?? '');
            if (array_key_exists($pos, $porPos) && $porPos[$pos] === null) $porPos[$pos] = $p;
        }
        $top = fbaPowerTop5($porPos);
        $saida[] = [
            'team_id'     => (int)$t['id'],
            'team_name'   => trim(($t['city'] ?? '') . ' ' . ($t['name'] ?? '')),
            'team_photo'  => $t['team_photo'] ?? null,
            'owner_name'  => $t['owner_name'] ?? null,
            'conference'  => $t['conference'] ?? '',
            'media_ovr'   => $top['media_ovr'],
            'teto_ovr'    => $top['teto_ovr'],
            'media_idade' => $top['media_idade'],
            'titulares'   => $top['quantos'],
            'score'       => fbaPowerScore((float)$top['media_ovr'], (int)$top['teto_ovr'],
                                           (float)$top['media_idade'], (int)$top['quantos'],
                                           $campeao === (int)$t['id'], $vice === (int)$t['id']),
        ];
    }

    usort($saida, static fn($a, $b) => $b['score'] <=> $a['score']);
    foreach ($saida as $i => &$r) $r['posicao'] = $i + 1;
    unset($r);

    return $saida;
}
