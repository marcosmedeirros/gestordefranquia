<?php
/**
 * OS GRUPOS DA LOTERIA DO DRAFT, num lugar só.
 *
 * Quem fica de fora do playoff entra na loteria dividido em quatro grupos,
 * e cada grupo tem um número de bolinhas. É daqui que saem as chances de
 * Top 3 e Top 5 que a tela mostra e que o bot responde.
 *
 * Vive fora do api/draft.php porque agora tem dois leitores: a tela da
 * loteria e o /loteria do WhatsApp. Com a regra escrita nos dois, bastaria
 * um ajuste num deles pra liga ver uma chance no site e outra no grupo — e
 * a pergunta "então qual é a minha chance?" não teria resposta.
 */

/** Quantos de cada conferência entram no playoff. O resto vai pra loteria. */
const LOTERIA_PLAYOFF_POR_CONF = 8;

/**
 * Os quatro grupos, com as bolinhas e as chances anunciadas.
 *
 * As porcentagens são POR TIME e por FAIXA: `top3` é a chance daquele time
 * terminar entre as três primeiras escolhas, `top5` entre as cinco. São
 * duas leituras da mesma bolinha, não duas chances que se somam — quem cai
 * no Top 3 está dentro do Top 5 também.
 */
const LOTERIA_GRUPOS_META = [
    1 => ['label' => '3 piores recordes',              'top3' => 16, 'top5' => 28, 'balls' => 2],
    2 => ['label' => 'Fora do play-in (4º–10º)',       'top3' => 24, 'top5' => 39, 'balls' => 3],
    3 => ['label' => 'Eliminados no play-in (9º/10º)', 'top3' => 16, 'top5' => 28, 'balls' => 2],
    4 => ['label' => 'Derrotados no 7x8',              'top3' => 8,  'top5' => 15, 'balls' => 1],
];

/**
 * A LOTERIA DE UMA LIGA EM TEXTO, pro grupo do WhatsApp.
 *
 * Mostra a ordem já confirmada quando ela existe — depois do sorteio é isso
 * que a liga quer ver. Antes, mostra quem entra e com que chance, agrupado:
 * a porcentagem é do GRUPO, então repetir o mesmo par de números em dezesseis
 * linhas seria dizer a mesma coisa dezesseis vezes.
 */
function loteriaTexto(PDO $pdo, string $liga): string
{
    $liga = strtoupper(trim($liga));
    if (!in_array($liga, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
        return 'Liga não reconhecida. Use ELITE, NEXT, RISE ou ROOKIE.';
    }

    // A sessão de draft mais recente da liga.
    $st = $pdo->prepare("SELECT ds.id, ds.status, s.season_number
                           FROM draft_sessions ds
                           JOIN seasons s ON s.id = ds.season_id
                          WHERE ds.league = ?
                       ORDER BY ds.id DESC LIMIT 1");
    $st->execute([$liga]);
    $sessao = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sessao) return "A *{$liga}* ainda não tem draft montado.";

    // Já confirmada? Então a loteria acabou, e o que vale é a ordem.
    $st = $pdo->prepare("SELECT do.pick_position, t.name AS time_nome
                           FROM draft_order do
                           JOIN teams t ON t.id = do.team_id
                          WHERE do.draft_session_id = ? AND do.round = 1
                       ORDER BY do.pick_position ASC");
    $st->execute([(int)$sessao['id']]);
    $ordem = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($ordem) {
        $l = ["🎲 *LOTERIA — {$liga}*", "_Ordem do draft · Temporada " . (int)$sessao['season_number'] . "_", ''];
        foreach ($ordem as $o) {
            $l[] = str_pad((string)(int)$o['pick_position'], 2, ' ', STR_PAD_LEFT) . '. ' . $o['time_nome'];
        }
        return implode("\n", $l);
    }

    // Ainda não sorteou: mostra quem entra e com que chance.
    $st = $pdo->prepare("SELECT s.id, s.season_number FROM seasons s
                          WHERE s.league = ?
                            AND EXISTS (SELECT 1 FROM season_standings ss WHERE ss.season_id = s.id)
                       ORDER BY s.season_number DESC, s.id DESC LIMIT 1");
    $st->execute([$liga]);
    $temp = $st->fetch(PDO::FETCH_ASSOC);
    if (!$temp) return "A *{$liga}* ainda não tem classificação lançada — sem ela não dá pra montar a loteria.";

    $st = $pdo->prepare("SELECT ss.team_id, ss.position, COALESCE(ss.conference, t.conference) AS conference,
                                ss.wins, ss.points_for, ss.points_against, ss.overall_position,
                                t.name AS team_name
                           FROM season_standings ss
                           JOIN teams t ON t.id = ss.team_id
                          WHERE ss.season_id = ?");
    $st->execute([(int)$temp['id']]);
    $standings = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$standings) return "A *{$liga}* não tem posições registradas.";

    $g = loteriaMontarGrupos($standings);
    if (!$g['elegiveis']) return "Ninguém ficou fora do playoff na *{$liga}*.";

    $l = ["🎲 *LOTERIA — {$liga}*",
          '_Campanha da temporada ' . (int)$temp['season_number'] . ' · ainda não sorteada_', ''];

    foreach (LOTERIA_GRUPOS_META as $num => $meta) {
        $doGrupo = array_values(array_filter($g['elegiveis'], fn($t) => ($g['grupo_de'][$t] ?? 0) === $num));
        if (!$doGrupo) continue;
        $l[] = '*' . $meta['label'] . '*  _(' . $meta['balls'] . ' bolinha'
             . ($meta['balls'] === 1 ? '' : 's') . ')_';
        $l[] = 'Top 3: *' . $meta['top3'] . '%*  ·  Top 5: *' . $meta['top5'] . '%*';
        foreach ($doGrupo as $t) $l[] = '· ' . ($g['nomes'][$t] ?? ('#' . $t));
        $l[] = '';
    }

    // A dúvida que sempre aparece: por que dois números.
    $l[] = '_Top 3 é a chance de ficar entre as três primeiras escolhas; Top 5, entre as cinco. '
         . 'É a mesma bolinha medida em duas faixas — quem cai no Top 3 já está no Top 5._';
    return implode("\n", $l);
}

/**
 * Divide a classificação em quem foi pro playoff e quem entra na loteria,
 * e distribui os elegíveis nos quatro grupos.
 *
 * @param array $standings linhas de season_standings já com team_id,
 *        position, conference, wins, points_for, points_against,
 *        overall_position e team_name
 * @return array{elegiveis:array, playoff:array, grupo_de:array, bolinhas:array}
 */
function loteriaMontarGrupos(array $standings): array
{
    $byConf = [];
    foreach ($standings as $row) {
        $conf = $row['conference'] ?: 'UNICA';
        $byConf[$conf][] = $row;
    }
    foreach ($byConf as &$list) {
        usort($list, fn($a, $b) => (int)$a['position'] <=> (int)$b['position']);
    }
    unset($list);

    $pos = $wins = $pdiff = $overall = $nomes = $confDe = [];
    $elegiveis = $playoff = [];

    foreach ($byConf as $conf => $list) {
        $corte = min(LOTERIA_PLAYOFF_POR_CONF, count($list));
        foreach ($list as $i => $row) {
            $tid = (int)$row['team_id'];
            $nomes[$tid]   = $row['team_name'] ?? ('Time #' . $tid);
            $confDe[$tid]  = $conf === 'UNICA' ? '' : $conf;
            $pos[$tid]     = (int)$row['position'];
            $wins[$tid]    = isset($row['wins']) ? (int)$row['wins'] : 0;
            $pdiff[$tid]   = (int)($row['points_for'] ?? 0) - (int)($row['points_against'] ?? 0);
            $overall[$tid] = ($row['overall_position'] ?? null) !== null ? (int)$row['overall_position'] : null;
            if ($i < $corte) $playoff[] = $row; else $elegiveis[] = $row;
        }
    }

    $ids = array_map(fn($r) => (int)$r['team_id'], $elegiveis);

    /* "Pior campanha": a ordem geral declarada no registro da temporada
       manda, porque é uma lista única e não empata os dois lados. Sem ela,
       vale o critério antigo — vitórias, saldo, posição —, que hoje é frágil:
       vitórias não são mais cadastradas e ficam todas em zero. */
    $piorPrimeiro = function ($a, $b) use ($wins, $pdiff, $pos, $overall) {
        $oa = $overall[$a] ?? null;
        $ob = $overall[$b] ?? null;
        if ($oa !== null && $ob !== null && $oa !== $ob) return $ob <=> $oa;
        if ($wins[$a] !== $wins[$b])   return $wins[$a] <=> $wins[$b];
        if ($pdiff[$a] !== $pdiff[$b]) return $pdiff[$a] <=> $pdiff[$b];
        return $pos[$b] <=> $pos[$a];
    };

    // G3 é quem parou no play-in: 9º e 10º de cada conferência.
    $g3 = array_values(array_filter($ids, fn($t) => $pos[$t] === 9 || $pos[$t] === 10));
    // O resto vira G1 (3 piores), G4 (2 menos ruins) e G2 (o meio).
    $resto = array_values(array_filter($ids, fn($t) => !in_array($t, $g3, true)));
    usort($resto, $piorPrimeiro);
    $n  = count($resto);
    $g1 = array_slice($resto, 0, min(3, $n));
    $g4 = $n > 3 ? array_slice($resto, -min(2, $n - 3)) : [];
    $g2 = array_slice($resto, count($g1), $n - count($g1) - count($g4));

    $grupoDe = [];
    foreach ([1 => $g1, 2 => $g2, 3 => $g3, 4 => $g4] as $g => $lista) {
        foreach ($lista as $t) $grupoDe[$t] = $g;
    }

    $bolinhas = [];
    foreach ($ids as $t) {
        $g = $grupoDe[$t] ?? 2;
        $bolinhas[$t] = LOTERIA_GRUPOS_META[$g]['balls'];
    }

    return [
        'elegiveis'    => $ids,
        'playoff'      => array_map(fn($r) => (int)$r['team_id'], $playoff),
        'grupo_de'     => $grupoDe,
        'bolinhas'     => $bolinhas,
        'nomes'        => $nomes,
        'conferencias' => $confDe,
        'posicoes'     => $pos,
        'ordem_geral'  => $overall,
        'pior_primeiro'=> $piorPrimeiro,
    ];
}
