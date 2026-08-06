<?php
/**
 * Build-A-Player — a temporada do jogador criado.
 *
 * Depois de montar o build, o jogador é sorteado pra um time da NBA e joga
 * uma temporada com o quinteto titular dele. O resultado depende de duas
 * coisas: a força do elenco que caiu e a qualidade do build. Cair no melhor
 * time da liga com um build ruim rende tanto quanto cair num time fraco com
 * um build ótimo — é isso que faz o sorteio importar.
 *
 * A força de cada time é atribuída à mão (0–100), como as notas das lendas:
 * não existe rating de elenco na base, e derivar de estatística daria errado.
 */

require_once __DIR__ . '/build_notas.php';
require_once dirname(__DIR__, 2) . '/backend/nba_teams.php';

/**
 * Força do elenco de cada time, SEM contar o jogador criado.
 * Referência: 50 é um time de meio de tabela; 70+ é candidato a título.
 */
function buildForcaDosTimes(): array
{
    return [
        'BOS' => 78, 'OKC' => 80, 'DEN' => 74, 'MIN' => 72, 'NYK' => 70,
        'CLE' => 71, 'MIL' => 68, 'PHI' => 67, 'DAL' => 69, 'LAC' => 63,
        'PHX' => 64, 'IND' => 65, 'ORL' => 62, 'LAL' => 66, 'GSW' => 63,
        'MIA' => 61, 'SAC' => 60, 'NOP' => 59, 'HOU' => 62, 'ATL' => 55,
        'CHI' => 50, 'MEM' => 58, 'SAS' => 54, 'TOR' => 48, 'BKN' => 45,
        'UTA' => 44, 'POR' => 42, 'CHA' => 41, 'WAS' => 38, 'DET' => 46,
    ];
}

/** Sorteia um time pro jogador criado. Devolve sigla, nome, logo e força. */
function buildSortearTime(): array
{
    $forcas = buildForcaDosTimes();
    $times  = array_values(array_filter(nbaTeams(), fn($t) => isset($forcas[$t['abbr']])));
    $t = $times[random_int(0, count($times) - 1)];

    return [
        'abbr'  => $t['abbr'],
        'nome'  => $t['city'] . ' ' . $t['name'],
        'logo'  => nbaTeamLogoUrl((int)$t['id']),
        'forca' => $forcas[$t['abbr']],
        'conf'  => $t['conference'],
    ];
}

/**
 * Simula a temporada do jogador criado.
 *
 * O build entra como o quinto titular, então o time melhora conforme o OVR
 * dele — mas o elenco continua sendo a maior parte da conta. A aleatoriedade
 * é real (random_int), não decorativa: o mesmo build no mesmo time pode dar
 * 48 ou 56 vitórias.
 */
function buildSimularTemporada(array $notas, int $ovr, array $time, string $grupo): array
{
    // O build entra como DIFERENÇA em relação ao mediano DO TIPO DELE, não
    // como parcela: um build mediano não muda nada, um ótimo carrega e um ruim
    // afunda. Somar o OVR direto na média deixava até o pior elenco da liga
    // nos playoffs — a escala dos dois números não é a mesma.
    $mediano  = buildPerfilDoGrupo($grupo)['mediano'];
    $forcaTime = $time['forca'] + (($ovr - $mediano) * 1.2);

    // 41 vitórias é o meio da tabela; cada ponto de força vale ~0,85 vitória.
    $esperado = 41 + (($forcaTime - 58) * 0.85);
    $esperado = max(12, min(70, $esperado));

    // Ruído da temporada: lesão, sorte de calendário, elenco que não engrena.
    $vitorias = (int)round($esperado + (random_int(-600, 600) / 100));
    $vitorias = max(9, min(73, $vitorias));
    $derrotas = 82 - $vitorias;

    $seed = buildSeedDaConferencia($vitorias);
    $playoff = buildSimularPlayoffs($vitorias, $forcaTime, $seed);

    return [
        'vitorias'   => $vitorias,
        'derrotas'   => $derrotas,
        'seed'       => $seed,
        'forca_time' => round($forcaTime, 1),
        'playoff'    => $playoff,
        'premios'    => buildPremiosIndividuais($notas, $ovr, $vitorias, $playoff, $mediano),
    ];
}

/** Cabeça de chave aproximada pela quantidade de vitórias. */
function buildSeedDaConferencia(int $vitorias): int
{
    if ($vitorias >= 60) return 1;
    if ($vitorias >= 55) return 2;
    if ($vitorias >= 50) return 3;
    if ($vitorias >= 47) return 4;
    if ($vitorias >= 44) return 5;
    if ($vitorias >= 41) return 6;
    if ($vitorias >= 38) return 7;
    if ($vitorias >= 35) return 8;
    return 0; // fora dos playoffs
}

/**
 * Playoffs: quatro rodadas, cada uma com chance ligada à força do time e à
 * cabeça de chave. Time fraco que entra em 8º raramente passa da primeira.
 */
function buildSimularPlayoffs(int $vitorias, float $forcaTime, int $seed): array
{
    if ($seed === 0) {
        return ['chegou' => 'fora', 'label' => 'Fora dos playoffs', 'titulo' => false];
    }

    $rodadas = [
        1 => ['label' => '1ª rodada',        'proximo' => 'Semifinal de conferência'],
        2 => ['label' => 'Semifinal de conf.','proximo' => 'Final de conferência'],
        3 => ['label' => 'Final de conf.',   'proximo' => 'Finais da NBA'],
        4 => ['label' => 'Finais da NBA',    'proximo' => 'Campeão'],
    ];

    // Chance por rodada: cresce com a força do time, cai conforme a chave e a
    // fase. O melhor time da liga ganha o título em ~1 a cada 4 temporadas —
    // não sempre, que é o que torna o anel uma notícia.
    $venceu = 0;
    for ($r = 1; $r <= 4; $r++) {
        $chance = 46 + (($forcaTime - 58) * 1.6) - (($seed - 1) * 4.5) - (($r - 1) * 6);
        $chance = max(3, min(88, $chance));
        if (random_int(1, 100) > $chance) break;
        $venceu = $r;
    }

    if ($venceu === 4) {
        return ['chegou' => 'campeao', 'label' => '🏆 Campeão da NBA', 'titulo' => true];
    }
    if ($venceu === 0) {
        return ['chegou' => 'primeira', 'label' => 'Eliminado na 1ª rodada', 'titulo' => false];
    }
    return [
        'chegou' => 'r' . $venceu,
        'label'  => 'Eliminado na ' . mb_strtolower($rodadas[$venceu + 1]['label']),
        'titulo' => false,
    ];
}

/**
 * MVP e DPOY. Nenhum dos dois é dado: o MVP exige build de elite E time
 * ganhando, e o DPOY exige defesa e tamanho de verdade. É o que faz a
 * temporada perfeita valer alguma coisa.
 */
function buildPremiosIndividuais(array $notas, int $ovr, int $vitorias, array $playoff, int $mediano): array
{
    $nivel = fn($a) => (int)($notas[$a]['nivel'] ?? 0);

    // MVP: ataque de elite E time vencendo. Abaixo de 50 vitórias, esquece.
    // Os dois precisam acontecer juntos — build ótimo em time ruim não leva.
    $pontosMvp = ($ovr - $mediano - 2) * 4
               + ($vitorias - 52) * 1.5
               + ($nivel('jump_shot') + $nivel('finishing') + $nivel('passing') + $nivel('iq') - 34) * 0.8;
    $mvp = $vitorias >= 50 && random_int(1, 100) <= max(0, min(72, $pontosMvp));

    // DPOY: só sai com defesa e tamanho de verdade. Um build todo montado no
    // ataque — que é o que a maioria faz — não chega nem perto.
    $pontosDpoy = (($nivel('perimeter_d') + $nivel('size') + $nivel('bounce')) - 24) * 5
                + ($vitorias - 45) * 0.5 - 10;
    $dpoy = random_int(1, 100) <= max(0, min(70, $pontosDpoy));

    $lista = [];
    if ($mvp)  $lista[] = ['key' => 'MVP',  'label' => '🏅 MVP da temporada'];
    if ($dpoy) $lista[] = ['key' => 'DPOY', 'label' => '🛡️ Defensor do Ano'];
    if (!empty($playoff['titulo']) && $mvp) $lista[] = ['key' => 'FMVP', 'label' => '👑 MVP das Finais'];

    return $lista;
}

/**
 * Peso de cada atributo no OVR, por tipo de build.
 *
 * Antes os dez atributos pesavam igual, e isso punia o BIG duas vezes: além de
 * ele não ter onde buscar nota boa em Handles/Arremesso, essas notas ruins
 * entravam no OVR com o mesmo peso das boas. Medindo o elenco de lendas, o BIG
 * fica 28 pontos atrás em Handles, 21 em Jump Shot e 15 em Passe — e 26 à
 * frente em Tamanho. Um pivô não deveria ser avaliado pelo drible.
 *
 * A soma dos pesos é normalizada em bpCalcularOvrExato, então dá pra ajustar um
 * atributo aqui sem precisar rebalancear os outros.
 */
function buildPesosDoGrupo(string $grupo): array
{
    if ($grupo === 'BIG') {
        return [
            'jump_shot'  => 0.55, 'finishing' => 1.35, 'passing' => 0.70,
            'handles'    => 0.45, 'perimeter_d' => 1.00, 'speed'  => 0.70,
            'bounce'     => 1.25, 'size'      => 1.55, 'iq'      => 1.20,
            'clutch'     => 1.25,
        ];
    }
    return [
        'jump_shot'  => 1.35, 'finishing' => 1.05, 'passing' => 1.25,
        'handles'    => 1.35, 'perimeter_d' => 0.95, 'speed'  => 1.20,
        'bounce'     => 0.80, 'size'      => 0.55, 'iq'      => 1.15,
        'clutch'     => 1.10,
    ];
}

/**
 * Régua de cada tipo de build: OVR mediano e a curva OVR → top 100.
 *
 * Por que uma curva, e não comparar o OVR do build com o das lendas?
 *
 * Porque as duas coisas não são comparáveis. O OVR de uma lenda é a média das
 * dez notas REAIS dela — pontos fracos inclusos. O build pega a melhor nota de
 * cada lenda que aparece, então é uma colagem sem defeito nenhum. Comparando
 * um com o outro, quase 30% das partidas terminavam em 1º, à frente do LeBron.
 * Não é "quase impossível", é o padrão.
 *
 * E por que uma régua por tipo? Porque BIG e GUARD não jogam o mesmo jogo. O
 * build precisa preencher os dez slots, e nenhum pivô da história tem nota
 * alta em Handles ou Arremesso — um BIG sai com OVR ~81 onde um GUARD sai com
 * ~88. Com régua única, 85% dos bigs ficavam fora do top 100 sem ter feito
 * nada de errado. Cada um é medido contra os seus.
 *
 * Os números vêm de 3.000 partidas simuladas por tipo.
 */
function buildPerfilDoGrupo(string $grupo): array
{
    // Curvas recalibradas em 20.000 partidas por grupo, já com os pesos de
    // buildPesosDoGrupo(). Cada posição sai do MESMO percentil nos dois grupos
    // (1º = melhor 0,3% dos builds, 5º ≈ 1,2%, 10º ≈ 2,4%), então BIG e GUARD
    // passam a ter exatamente a mesma chance de pódio.
    //
    // Antes não tinham: medindo as curvas antigas, o GUARD chegava ao top 10 em
    // 1,68% das partidas e o BIG em 0,62% — quase três vezes mais difícil. E o
    // 1º lugar do BIG exigia OVR 90 sendo que o teto alcançável era ~89,5, ou
    // seja, as 500 moedas do topo eram literalmente inatingíveis pra ele.
    if ($grupo === 'BIG') {
        return [
            'mediano' => 81, // mediana medida: 80.8 (teto 92.1)
            'curva'   => [
                [88.0, 1], [87.2, 3], [86.5, 6], [85.8, 12],
                [85.0, 20], [84.1, 30], [83.1, 42], [82.0, 55],
                [80.8, 68], [79.5, 80], [77.9, 90], [69.7, 100],
            ],
        ];
    }
    return [
        'mediano' => 85, // mediana medida: 85.3 (teto 94.4)
        'curva'   => [
            [92.0, 1], [91.4, 3], [90.8, 6], [90.1, 12],
            [89.3, 20], [88.6, 30], [87.5, 42], [86.5, 55],
            [85.3, 68], [84.0, 80], [82.3, 90], [74.2, 100],
        ],
    ];
}

/**
 * Quem levou o quê na liga inteira, não só o jogador criado.
 *
 * Sem isto a temporada ficava pela metade: "eliminado na semifinal" sem dizer
 * quem foi campeão, e "nenhum prêmio individual" sem dizer quem ganhou. O
 * jogador quer saber pra quem perdeu.
 *
 * Os outros nomes saem do próprio elenco de lendas — é o mundo do jogo, os
 * mesmos caras que aparecem na roleta.
 */
function buildPremiacaoDaLiga(PDO $pdo, array $time, array $premios, array $playoff, string $nomeJogador): array
{
    $ganhou = array_column($premios, 'key');
    $foiCampeao = !empty($playoff['titulo']);

    // Campeão: o time do jogador quando ele levou o título, senão outro time
    // sorteado com peso na força — o pior elenco da liga raramente é campeão.
    if ($foiCampeao) {
        $campeao = ['nome' => $time['nome'], 'logo' => $time['logo'], 'seu' => true];
    } else {
        $forcas = buildForcaDosTimes();
        $candidatos = array_values(array_filter(nbaTeams(),
            fn($t) => isset($forcas[$t['abbr']]) && $t['abbr'] !== $time['abbr']));
        $pesos = array_map(fn($t) => (int)pow(max(1, $forcas[$t['abbr']] - 30), 2.2), $candidatos);
        $escolhido = $candidatos[buildSortearComPeso($pesos)];
        $campeao = [
            'nome' => $escolhido['city'] . ' ' . $escolhido['name'],
            'logo' => nbaTeamLogoUrl((int)$escolhido['id']),
            'seu'  => false,
        ];
    }

    $lendas = $pdo->query("SELECT p.nome, n.ovr, n.perimeter_d, n.size, n.bounce
                           FROM build_notas n
                           INNER JOIN hoopgrid_players p ON p.id = n.player_id")->fetchAll(PDO::FETCH_ASSOC);

    // MVP pesa o OVR; DPOY pesa defesa, tamanho e impulsão. Elevado ao cubo
    // pra que os melhores dominem de verdade — MVP não é sorteio de rifa.
    $mvp = in_array('MVP', $ganhou, true)
        ? ['nome' => $nomeJogador, 'seu' => true]
        : ['nome' => buildLendaComPeso($lendas, fn($l) => pow(max(1, (int)$l['ovr'] - 60), 3)), 'seu' => false];

    $dpoy = in_array('DPOY', $ganhou, true)
        ? ['nome' => $nomeJogador, 'seu' => true]
        : ['nome' => buildLendaComPeso($lendas,
            fn($l) => pow(max(1, (int)$l['perimeter_d'] + (int)$l['size'] + (int)$l['bounce'] - 8), 3)), 'seu' => false];

    return ['campeao' => $campeao, 'mvp' => $mvp, 'dpoy' => $dpoy];
}

/** Índice sorteado numa lista de pesos. */
function buildSortearComPeso(array $pesos): int
{
    $total = array_sum($pesos);
    if ($total <= 0) return 0;
    $r = random_int(1, $total);
    foreach ($pesos as $i => $p) {
        $r -= $p;
        if ($r <= 0) return $i;
    }
    return count($pesos) - 1;
}

/** Nome de uma lenda sorteada com o peso que a função der. */
function buildLendaComPeso(array $lendas, callable $peso): string
{
    if (!$lendas) return 'um veterano da liga';
    $pesos = array_map(fn($l) => (int)$peso($l), $lendas);
    return (string)$lendas[buildSortearComPeso($pesos)]['nome'];
}

/**
 * Percentil (de cima pra baixo) que cada posição do top 100 deve representar.
 *
 * É a régua de raridade do ranking. Fixar ISSO, em vez de fixar valores de OVR,
 * é o que torna o ranking imune a mudança na base de lendas.
 *
 * O topo é deliberadamente brutal: o 1º lugar é o melhor 0,02% dos builds, ou
 * seja 1 a cada 5.000 partidas. É pra ser quase inalcançável mesmo — antes eram
 * 0,25% (1 em 400) e "o maior de todos os tempos" aparecia direto, o que tirava
 * o sentido do título. O pódio inteiro ficou raro na mesma proporção; da metade
 * da tabela pra baixo quase nada muda, porque ali o ranking só serve de régua.
 */
const BUILD_PERCENTIL_POSICAO = [
    [0.02, 1], [0.05, 3], [0.12, 5], [0.35, 10],
    [1.5, 20], [4.0, 30], [12.0, 50], [40.0, 75], [100.0, 100],
];

/**
 * Curva OVR → posição calculada a partir das notas que estão valendo AGORA.
 *
 * Por que não uma curva fixa: os níveis de cada lenda saem de um percentil dentro
 * do hoopgrid_players (ver buildAplicarCurva). Quando a base cresce, os mesmos
 * craques sobem de nível e o OVR de um build médio sobe junto — foi o que
 * aconteceu quando o sync automático passou a rodar sobre a base expandida: o
 * OVR estourou os 88 da régua antiga e TODO build virava "o maior de todos os
 * tempos". Aqui a régua se recalibra sozinha sempre que as notas mudam.
 *
 * O resultado fica em cache: recalcular custa uma simulação de milhares de
 * builds, e a impressão digital das notas diz quando refazer.
 */
function buildCurvaViva(PDO $pdo, string $grupo): array
{
    static $memoria = [];
    if (isset($memoria[$grupo])) return $memoria[$grupo];

    $fallback = buildPerfilDoGrupo($grupo)['curva'];
    try {
        $st = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(ovr),0) s, COALESCE(MAX(player_id),0) m
                             FROM build_notas WHERE posicao_grupo = ?");
        $st->execute([$grupo]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        // Poucas lendas: a amostra não representa nada, melhor a régua fixa.
        if (!$f || (int)$f['c'] < 12) return $memoria[$grupo] = $fallback;
        $digital = md5($grupo . '|' . $f['c'] . '|' . $f['s'] . '|' . $f['m']);

        $pdo->exec("CREATE TABLE IF NOT EXISTS build_curva_cache (
            grupo VARCHAR(10) NOT NULL PRIMARY KEY,
            digital CHAR(32) NOT NULL,
            curva TEXT NOT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $st = $pdo->prepare("SELECT curva FROM build_curva_cache WHERE grupo = ? AND digital = ?");
        $st->execute([$grupo, $digital]);
        $cache = $st->fetchColumn();
        if ($cache) {
            $curva = json_decode((string)$cache, true);
            if (is_array($curva) && count($curva) >= 2) return $memoria[$grupo] = $curva;
        }

        $curva = buildCalibrarCurva($pdo, $grupo);
        if (!$curva) return $memoria[$grupo] = $fallback;

        $pdo->prepare("INSERT INTO build_curva_cache (grupo, digital, curva) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE digital = VALUES(digital), curva = VALUES(curva), criado_em = NOW()")
            ->execute([$grupo, $digital, json_encode($curva)]);
        return $memoria[$grupo] = $curva;
    } catch (Throwable $e) {
        error_log('[build] curva viva (' . $grupo . '): ' . $e->getMessage());
        return $memoria[$grupo] = $fallback;
    }
}

/**
 * Simula milhares de builds com as notas atuais e transforma a distribuição de
 * OVR na curva OVR → posição, ancorada em BUILD_PERCENTIL_POSICAO.
 *
 * A simulação imita o que o jogador faz: a cada lenda sorteada, pega o atributo
 * que mais soma no OVR dele. Medir com escolha aleatória daria uma régua fácil
 * demais — ninguém joga jogando fora a melhor nota da tela.
 */
function buildCalibrarCurva(PDO $pdo, string $grupo): ?array
{
    $st = $pdo->prepare("SELECT * FROM build_notas WHERE posicao_grupo = ?");
    $st->execute([$grupo]);
    $lendas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($lendas) < 12) return null;

    $pesos = buildPesosDoGrupo($grupo);
    $attrs = array_keys($pesos);
    $somaPesos = array_sum($pesos);
    if ($somaPesos <= 0) return null;

    // 40 mil: o 1º lugar é o melhor 0,02%, então com amostra pequena esse corte
    // cairia em cima do próprio máximo sorteado e viraria ruído. Aqui ele é a
    // média dos ~8 melhores. Custa ~0,4s e roda uma vez só (o resultado fica em
    // cache até as notas mudarem).
    $amostras = [];
    $totalLendas = count($lendas);
    for ($p = 0; $p < 40000; $p++) {
        $niveis = array_fill_keys($attrs, 0);
        $vazios = $attrs;
        $usados = [];
        while ($vazios) {
            // Lenda ainda não usada nesta partida, como bpSortearLenda faz.
            $tentativas = 0;
            do {
                $l = $lendas[random_int(0, $totalLendas - 1)];
                $tentativas++;
            } while (isset($usados[(int)$l['player_id']]) && $tentativas < 40);
            $usados[(int)$l['player_id']] = true;

            $melhor = null;
            $melhorValor = -1.0;
            foreach ($vazios as $a) {
                $v = buildValorDaLetra((int)$l[$a]) * $pesos[$a];
                if ($v > $melhorValor) { $melhorValor = $v; $melhor = $a; }
            }
            if ($melhor === null) break;
            $niveis[$melhor] = (int)$l[$melhor];
            $vazios = array_values(array_diff($vazios, [$melhor]));
        }
        $num = 0.0;
        foreach ($attrs as $a) $num += buildValorDaLetra($niveis[$a]) * $pesos[$a];
        $amostras[] = $num / $somaPesos;
    }

    rsort($amostras); // melhor primeiro
    $n = count($amostras);
    $curva = [];
    foreach (BUILD_PERCENTIL_POSICAO as [$pct, $posicao]) {
        $i = (int)round($n * $pct / 100) - 1;
        $i = max(0, min($n - 1, $i));
        $curva[] = [round($amostras[$i], 2), $posicao];
    }

    // A curva é lida do topo pra baixo assumindo OVR estritamente decrescente;
    // amostra pequena pode empatar dois pontos e travar a interpolação.
    for ($i = 1; $i < count($curva); $i++) {
        if ($curva[$i][0] >= $curva[$i - 1][0]) $curva[$i][0] = $curva[$i - 1][0] - 0.1;
    }
    return $curva;
}

/**
 * Posição do build no top 100 histórico da NBA.
 *
 * Recebe o OVR exato (sem arredondar) pra que dois builds próximos não empatem
 * na mesma posição — a interpolação entre os pontos da curva dá o número fino.
 */
function buildPosicaoHistorica(float $ovrExato, string $grupo, ?PDO $pdo = null): array
{
    $curva = $pdo ? buildCurvaViva($pdo, $grupo) : buildPerfilDoGrupo($grupo)['curva'];
    $pos = null;

    if ($ovrExato >= $curva[0][0]) {
        $pos = 1;
    } else {
        for ($i = 0; $i < count($curva) - 1; $i++) {
            [$ovrAlto, $posAlta] = $curva[$i];
            [$ovrBaixo, $posBaixa] = $curva[$i + 1];
            if ($ovrExato >= $ovrBaixo) {
                $t = ($ovrExato - $ovrBaixo) / ($ovrAlto - $ovrBaixo);
                $pos = (int)round($posBaixa - $t * ($posBaixa - $posAlta));
                break;
            }
        }
    }

    // Abaixo do fim da curva o build simplesmente não entra na lista. Mostrar
    // "#137" seria mentira: a lista tem 100 nomes. E a posição vai a ZERO, não
    // a 1 — um clamp em 1 aqui fazia o pior build possível voltar como "#1".
    $noTop = $pos !== null;
    $pos = $noTop ? max(1, min(100, (int)$pos)) : 0;

    return [
        'posicao' => $pos,
        'no_top'  => $noTop,
        'de'      => 100,
        'tier'    => buildTierHistorico($pos),
    ];
}

/** O que a posição significa em palavras — o número sozinho não diz nada. */
function buildTierHistorico(int $pos): string
{
    if ($pos === 0)  return 'Ficou de fora do top 100';
    if ($pos === 1)  return 'O MAIOR DE TODOS OS TEMPOS';
    if ($pos <= 5)   return 'Conversa de GOAT';
    if ($pos <= 15)  return 'Top 15 histórico';
    if ($pos <= 30)  return 'Hall da Fama garantido';
    if ($pos <= 50)  return 'Hall da Fama';
    if ($pos <= 75)  return 'Vários All-Stars na carreira';
    return 'Entrou pra lista';
}

/** Moedas pela posição HISTÓRICA — não pelo ranking entre jogadores. */
function buildMoedasDaPosicaoHistorica(array $hist): int
{
    if (!$hist['no_top']) return 0;
    $pos = (int)$hist['posicao'];
    if ($pos <= 1)  return 500;
    if ($pos <= 5)  return 100;
    if ($pos <= 10) return 50;
    return 0;
}
