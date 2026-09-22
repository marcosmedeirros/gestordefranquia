<?php
/**
 * AS COMPETIÇÕES DA TEMPORADA — do Paranaense à Libertadores.
 *
 * O fut_motor.php sabe fazer uma partida e uma tabela de pontos corridos. Este
 * arquivo é a camada de cima: quem joga o quê, em que formato, e quem se
 * classifica pra onde no ano seguinte.
 *
 * ── O ESCOPO É A AMÉRICA DO SUL ──────────────────────────────────────
 *
 * Brasileirão A e B, os quatro estaduais grandes, Copa do Brasil, Copa do
 * Nordeste, Copa Verde, Supercopa, Libertadores, Sul-Americana e Recopa.
 * Europa fica pra depois — o jogo estreia com um continente inteiro funcionando
 * em vez de dois pela metade.
 *
 * ── UMA HONESTIDADE SOBRE OS OUTROS PAÍSES ───────────────────────────
 *
 * O jogo simula rodada a rodada só o futebol brasileiro. Argentina, Uruguai,
 * Chile e companhia não têm liga simulada: os representantes deles na
 * Libertadores e na Sul-Americana são sorteados entre os clubes do país com
 * peso pela força (futRepresentantes). River e Boca aparecem quase sempre, um
 * clube médio aparece de vez em quando — que é o que a classificação daquelas
 * ligas produziria, sem o custo de simular nove campeonatos que ninguém vai
 * abrir pra ver. Se um dia a carreira passar a permitir dirigir fora do Brasil,
 * é esta função que vira liga de verdade.
 */

require_once __DIR__ . '/fut_motor.php';
require_once __DIR__ . '/fut_clubes_br.php';
require_once __DIR__ . '/fut_elencos.php';

/**
 * As ligas sul-americanas do catálogo do Copero e quantas vagas cada país leva.
 *
 * As cotas são as da CONMEBOL: Brasil e Argentina com mais, os demais com as
 * vagas fixas. O Brasil não está aqui porque as vagas dele saem da tabela
 * simulada, e não de sorteio.
 */
const FUT_PAISES_CONMEBOL = [
    'AR1' => ['nome' => 'Argentina', 'liberta' => 6, 'sula' => 6],
    'UY1' => ['nome' => 'Uruguai',   'liberta' => 4, 'sula' => 4],
    'CL1' => ['nome' => 'Chile',     'liberta' => 4, 'sula' => 4],
    'CO1' => ['nome' => 'Colômbia',  'liberta' => 4, 'sula' => 4],
    'EC1' => ['nome' => 'Equador',   'liberta' => 4, 'sula' => 4],
    'PY1' => ['nome' => 'Paraguai',  'liberta' => 4, 'sula' => 4],
    'PE1' => ['nome' => 'Peru',      'liberta' => 4, 'sula' => 4],
    'BO1' => ['nome' => 'Bolívia',   'liberta' => 4, 'sula' => 4],
    'VE1' => ['nome' => 'Venezuela', 'liberta' => 4, 'sula' => 4],
];

/** Quantas vagas o Brasil leva, e por onde. */
const FUT_VAGAS_BR = [
    'liberta_tabela' => 6,    // do 1º ao 6º do Brasileirão
    'sula_tabela'    => 6,    // do 7º ao 12º
];

// ─────────────────────────────────────────────────────────────────────
//  As peças que o motor ainda não tinha: mata-mata e fase de grupos
// ─────────────────────────────────────────────────────────────────────

/**
 * A DECISÃO POR PÊNALTIS.
 *
 * Quase uma moeda. A força pesa, mas MUITO menos que numa partida — é o que
 * todo mundo que assiste futebol sabe e é o que faz o mata-mata ser mais cruel
 * que os pontos corridos. O favorito sai com algo perto de 55%, não 75%.
 */
function futPenaltis(int $forcaA, int $forcaB): bool
{
    $vantagem = ($forcaA - $forcaB) * 0.004;        // 30 de diferença => 62%
    $chance = max(0.30, min(0.70, 0.5 + $vantagem));
    return (mt_rand() / mt_getrandmax()) < $chance;
}

/**
 * UM CONFRONTO ELIMINATÓRIO. Devolve quem passa.
 *
 * Ida e volta soma os dois placares; empate no agregado vai pros pênaltis. NÃO
 * TEM GOL FORA — a CONMEBOL e a UEFA abandonaram o critério, e manter o velho
 * daria um desempate que ninguém mais reconhece.
 *
 * @return array ['vencedor','perdedor','jogos'=>[...], 'penaltis'=>bool]
 */
function futConfronto(array $a, array $b, bool $idaVolta = true): array
{
    $jogos = [];

    if (!$idaVolta) {
        // Jogo único, campo neutro: é a final de Libertadores e a Supercopa.
        $p = futPlacar($a['forca'], $b['forca'], false);
        $jogos[] = ['casa' => $a['nome'], 'fora' => $b['nome'], 'gc' => $p['casa'], 'gf' => $p['fora']];
        if ($p['casa'] !== $p['fora']) {
            $venceA = $p['casa'] > $p['fora'];
            return ['vencedor' => $venceA ? $a : $b, 'perdedor' => $venceA ? $b : $a,
                    'jogos' => $jogos, 'penaltis' => false];
        }
        $venceA = futPenaltis($a['forca'], $b['forca']);
        return ['vencedor' => $venceA ? $a : $b, 'perdedor' => $venceA ? $b : $a,
                'jogos' => $jogos, 'penaltis' => true];
    }

    /* Ida na casa do PIOR ranqueado e volta na do melhor — é assim que os
       torneios mandam, e dá ao favorito a vantagem de decidir em casa. */
    $pior  = $a['forca'] <= $b['forca'] ? $a : $b;
    $melhor = $pior === $a ? $b : $a;

    $ida   = futPlacar($pior['forca'], $melhor['forca']);
    $volta = futPlacar($melhor['forca'], $pior['forca']);
    $jogos[] = ['casa' => $pior['nome'],   'fora' => $melhor['nome'], 'gc' => $ida['casa'],   'gf' => $ida['fora']];
    $jogos[] = ['casa' => $melhor['nome'], 'fora' => $pior['nome'],   'gc' => $volta['casa'], 'gf' => $volta['fora']];

    $totalPior   = $ida['casa'] + $volta['fora'];
    $totalMelhor = $ida['fora'] + $volta['casa'];

    if ($totalPior !== $totalMelhor) {
        $vencePior = $totalPior > $totalMelhor;
        return ['vencedor' => $vencePior ? $pior : $melhor,
                'perdedor' => $vencePior ? $melhor : $pior,
                'jogos' => $jogos, 'penaltis' => false];
    }
    $vencePior = futPenaltis($pior['forca'], $melhor['forca']);
    return ['vencedor' => $vencePior ? $pior : $melhor,
            'perdedor' => $vencePior ? $melhor : $pior,
            'jogos' => $jogos, 'penaltis' => true];
}

/**
 * UM MATA-MATA INTEIRO, das oitavas à final.
 *
 * O chaveamento é por força: o 1º pega o último, o 2º pega o penúltimo. É o
 * critério de quem vem de fase de grupos, e recompensa quem foi bem antes.
 *
 * @param array $clubes lista já ordenada por mérito (melhor primeiro)
 * @return array ['campeao','vice','fases'=>[nome => confrontos]]
 */
function futMataMata(array $clubes, bool $idaVolta = true, bool $finalUnica = true): array
{
    $vivos = array_values($clubes);
    $fases = [];

    while (count($vivos) > 1) {
        $n = count($vivos);
        $nomeFase = match ($n) {
            2 => 'Final', 4 => 'Semifinal', 8 => 'Quartas',
            16 => 'Oitavas', 32 => 'Segunda fase', default => $n . ' clubes',
        };

        // Na final, o "ida e volta" pode virar jogo único (Libertadores).
        $volta = ($n === 2 && $finalUnica) ? false : $idaVolta;

        $passam = [];
        $confrontos = [];
        for ($i = 0; $i < $n / 2; $i++) {
            $r = futConfronto($vivos[$i], $vivos[$n - 1 - $i], $volta);
            $confrontos[] = $r;
            $passam[] = $r['vencedor'];
        }
        $fases[$nomeFase] = $confrontos;

        if ($n === 2) {
            return ['campeao' => $passam[0],
                    'vice'    => $confrontos[0]['perdedor'],
                    'fases'   => $fases];
        }

        /* Reordena por força pra próxima fase seguir o mesmo critério de
           chaveamento — sem isso, as quartas virariam sorteio puro. */
        usort($passam, fn($x, $y) => $y['forca'] <=> $x['forca']);
        $vivos = $passam;
    }

    return ['campeao' => $vivos[0] ?? null, 'vice' => null, 'fases' => $fases];
}

/**
 * A FASE DE GRUPOS: divide em grupos de 4, todos contra todos ida e volta, e
 * devolve quem avança.
 *
 * A distribuição é em serpentina por potes — os quatro mais fortes vão pra
 * grupos diferentes. Sortear direto juntaria Palmeiras, River e Peñarol no
 * mesmo grupo de vez em quando, que é o tipo de coisa que o sorteio por potes
 * existe justamente pra impedir.
 *
 * @return array ['grupos'=>[letra=>tabela], 'classificados'=>[...], 'terceiros'=>[...]]
 */
function futFaseDeGrupos(array $clubes, int $porGrupo = 4, int $avancam = 2): array
{
    usort($clubes, fn($a, $b) => $b['forca'] <=> $a['forca']);
    $nGrupos = (int)floor(count($clubes) / $porGrupo);
    if ($nGrupos < 1) return ['grupos' => [], 'classificados' => $clubes, 'terceiros' => []];

    // Serpentina por potes: pote 1 na ordem, pote 2 ao contrário, e assim vai.
    $grupos = array_fill(0, $nGrupos, []);
    $usados = array_slice($clubes, 0, $nGrupos * $porGrupo);
    foreach (array_chunk($usados, $nGrupos) as $pote => $lista) {
        if ($pote % 2 === 1) $lista = array_reverse($lista);
        foreach ($lista as $i => $c) $grupos[$i][] = $c;
    }

    $tabelas = [];
    $classificados = [];
    $terceiros = [];

    foreach ($grupos as $i => $doGrupo) {
        $letra = chr(65 + $i);
        $porNome = [];
        foreach ($doGrupo as $c) $porNome[$c['nome']] = $c;

        $ids = array_keys($porNome);
        $resultados = [];
        foreach (futCalendario($ids) as $jogos) {
            foreach ($jogos as [$casa, $fora]) {
                $p = futPlacar($porNome[$casa]['forca'], $porNome[$fora]['forca']);
                $resultados[] = ['casa' => $casa, 'fora' => $fora, 'gc' => $p['casa'], 'gf' => $p['fora']];
            }
        }
        $tabela = futClassificacao($ids, $resultados);
        $tabelas[$letra] = $tabela;

        $pos = 0;
        foreach ($tabela as $nome => $linha) {
            $pos++;
            if ($pos <= $avancam)        $classificados[] = $porNome[$nome];
            elseif ($pos === $avancam + 1) $terceiros[] = $porNome[$nome];
        }
    }

    usort($classificados, fn($a, $b) => $b['forca'] <=> $a['forca']);
    return ['grupos' => $tabelas, 'classificados' => $classificados, 'terceiros' => $terceiros];
}

// ─────────────────────────────────────────────────────────────────────
//  As competições
// ─────────────────────────────────────────────────────────────────────

/** Um clube do catálogo no formato que as funções daqui esperam. */
function futTime(array $clube): array
{
    /* A FORÇA VEM DO ELENCO, não do número solto do catálogo. É o que faz
       vender o artilheiro doer na tabela — sem isso o elenco é enfeite. O
       número do catálogo continua servindo de base pra gerar o elenco. */
    $elenco = futElencoDoClube($clube['nome'], (int)$clube['forca']);
    return [
        'nome'   => $clube['nome'],
        'forca'  => futForcaDoElenco($elenco),
        'uf'     => $clube['uf'] ?? '',
        'div'    => $clube['div'] ?? '',
        'escudo' => $clube['escudo'] ?? '',
    ];
}

/** Converte uma lista do catálogo em times com força de elenco. */
function futTimes(array $clubes): array
{
    return array_map('futTime', array_values($clubes));
}

/**
 * UM ESTADUAL: turno único de pontos corridos e um mata-mata entre os quatro
 * primeiros.
 *
 * Turno único porque o estadual acontece no começo do ano, em poucas semanas —
 * turno e returno com 18 clubes tomaria metade do calendário e empurraria o
 * Brasileirão pra fora do ano.
 */
function futSimularEstadual(string $uf): array
{
    $times = futTimes(futClubesDoEstado($uf));
    if (count($times) < 4) return ['campeao' => $times[0] ?? null, 'tabela' => [], 'fases' => []];

    $porNome = [];
    foreach ($times as $t) $porNome[$t['nome']] = $t;
    $ids = array_keys($porNome);

    // Só o turno: futCalendario devolve turno e returno, e a metade basta.
    $rodadas = futCalendario($ids);
    $rodadas = array_slice($rodadas, 0, (int)ceil(count($rodadas) / 2));

    $resultados = [];
    foreach ($rodadas as $jogos) {
        foreach ($jogos as [$casa, $fora]) {
            $p = futPlacar($porNome[$casa]['forca'], $porNome[$fora]['forca']);
            $resultados[] = ['casa' => $casa, 'fora' => $fora, 'gc' => $p['casa'], 'gf' => $p['fora']];
        }
    }
    $tabela = futClassificacao($ids, $resultados);

    $top4 = [];
    foreach (array_slice(array_keys($tabela), 0, 4) as $nome) $top4[] = $porNome[$nome];

    $mm = futMataMata($top4, true, false);   // semi e final em ida e volta
    return ['nome' => FUT_ESTADUAIS[$uf] ?? $uf, 'campeao' => $mm['campeao'],
            'vice' => $mm['vice'], 'tabela' => $tabela, 'fases' => $mm['fases']];
}

/**
 * O BRASILEIRÃO de uma divisão: pontos corridos, turno e returno.
 *
 * Devolve também quem sobe e quem cai, que é o que liga uma temporada na outra.
 */
function futSimularBrasileirao(string $div): array
{
    $times = futTimes(futClubesDaDivisao($div));
    $porNome = [];
    foreach ($times as $t) $porNome[$t['nome']] = $t;

    $liga = futSimularLiga(array_combine(array_keys($porNome), array_values($porNome)));
    $ordem = array_keys($liga['tabela']);

    return [
        'nome'      => $div === 'BR1' ? 'Brasileirão Série A' : 'Brasileirão Série B',
        'div'       => $div,
        'campeao'   => $porNome[$ordem[0]] ?? null,
        'tabela'    => $liga['tabela'],
        'rodadas'   => $liga['rodadas'],
        'rebaixados'=> array_slice($ordem, -4),          // os quatro últimos
        'acesso'    => $div === 'BR2' ? array_slice($ordem, 0, 4) : [],
        'clubes'    => $porNome,
    ];
}

/**
 * A COPA DO BRASIL: mata-mata puro, com os clubes das três divisões.
 *
 * Entram 64 na vida real; aqui entram os que o catálogo tem, cortados na
 * potência de dois mais próxima — um chaveamento com 57 clubes teria folgas
 * espalhadas que confundem mais do que ajudam.
 */
function futSimularCopaDoBrasil(): array
{
    $todos = futClubesDoBrasil();
    $times = futTimes($todos);
    usort($times, fn($a, $b) => $b['forca'] <=> $a['forca']);

    $n = 1;
    while ($n * 2 <= count($times)) $n *= 2;
    $times = array_slice($times, 0, $n);

    $mm = futMataMata($times, true, false);   // final em ida e volta, como é
    return ['nome' => 'Copa do Brasil'] + $mm;
}

/** Uma copa regional (Nordeste ou Verde): grupos e depois mata-mata. */
function futSimularRegional(string $regiao): array
{
    $times = futTimes(futClubesDaRegiao($regiao));
    usort($times, fn($a, $b) => $b['forca'] <=> $a['forca']);
    $times = array_slice($times, 0, 16);

    $g = futFaseDeGrupos($times, 4, 2);
    $mm = futMataMata($g['classificados'], true, false);
    return ['nome' => FUT_REGIONAIS[$regiao] ?? $regiao, 'grupos' => $g['grupos']] + $mm;
}

/**
 * Os representantes de um país que não tem liga simulada.
 *
 * Sorteio com peso pela força: os grandes aparecem quase sempre, o clube médio
 * de vez em quando. Ver a explicação no topo do arquivo sobre por que não
 * simulamos as nove ligas.
 */
function futRepresentantes(string $liga, int $quantos, array $jaEscolhidos = []): array
{
    $doPais = [];
    foreach (COPERO_CLUBES as $c) {
        if ($c[1] !== $liga) continue;
        if (in_array($c[0], $jaEscolhidos, true)) continue;
        $doPais[] = ['nome' => $c[0], 'forca' => (int)$c[2], 'uf' => '', 'div' => $c[1],
                     'escudo' => $c[3] ?? ''];
    }
    if ($doPais === []) return [];

    $escolhidos = [];
    for ($i = 0; $i < $quantos && $doPais !== []; $i++) {
        // O peso é a força ao cubo: separa bem o grande do médio sem excluir
        // ninguém — uma liga onde só os quatro mesmos clubes vão à Libertadores
        // todo ano não tem graça nenhuma.
        $pesos = array_map(fn($c) => pow($c['forca'], 3), $doPais);
        $total = array_sum($pesos);
        $alvo = (mt_rand() / mt_getrandmax()) * $total;
        $acum = 0;
        foreach ($doPais as $k => $c) {
            $acum += $pesos[$k];
            if ($acum >= $alvo) { $escolhidos[] = $c; unset($doPais[$k]); $doPais = array_values($doPais); break; }
        }
    }
    return $escolhidos;
}

/**
 * A LIBERTADORES (ou a Sul-Americana): fase de grupos e mata-mata, com final
 * em jogo único.
 *
 * @param array $brasileiros os clubes do Brasil que se classificaram
 */
function futSimularContinental(string $qual, array $brasileiros): array
{
    $cota = $qual === 'liberta' ? 'liberta' : 'sula';
    $times = $brasileiros;

    foreach (FUT_PAISES_CONMEBOL as $liga => $info) {
        foreach (futRepresentantes($liga, $info[$cota]) as $c) $times[] = $c;
    }

    usort($times, fn($a, $b) => $b['forca'] <=> $a['forca']);
    $times = array_slice($times, 0, 32);

    $g = futFaseDeGrupos($times, 4, 2);
    $mm = futMataMata($g['classificados'], true, true);   // final em jogo único

    return ['nome' => $qual === 'liberta' ? 'Libertadores' : 'Sul-Americana',
            'grupos' => $g['grupos']] + $mm;
}

/**
 * A TEMPORADA INTEIRA, na ordem em que acontece.
 *
 * É a função que os testes usam pra rodar muitos anos seguidos e conferir se os
 * campeões fazem sentido. A Supercopa e a Recopa dependem dos campeões do ano
 * ANTERIOR — por isso entram por parâmetro em vez de sair daqui.
 *
 * @param array $anoPassado ['brasileirao','copa_brasil','liberta','sula'] do
 *                          ano anterior, ou [] na primeira temporada
 */
function futSimularTemporada(array $anoPassado = []): array
{
    $t = [];

    // ── Começo do ano: os estaduais ──────────────────────────────────
    foreach (array_keys(FUT_ESTADUAIS) as $uf) {
        $t['estaduais'][$uf] = futSimularEstadual($uf);
    }

    // ── As decisões que herdam do ano passado ────────────────────────
    if (isset($anoPassado['brasileirao'], $anoPassado['copa_brasil'])) {
        $t['supercopa'] = ['nome' => 'Supercopa do Brasil']
            + futConfronto($anoPassado['brasileirao'], $anoPassado['copa_brasil'], false);
    }
    if (isset($anoPassado['liberta'], $anoPassado['sula'])) {
        $t['recopa'] = ['nome' => 'Recopa Sul-Americana']
            + futConfronto($anoPassado['liberta'], $anoPassado['sula'], true);
    }

    // ── As copas regionais, que correm junto com o começo do ano ─────
    foreach (array_keys(FUT_REGIONAIS) as $r) {
        $t['regionais'][$r] = futSimularRegional($r);
    }

    // ── O miolo do ano ───────────────────────────────────────────────
    $t['brasileirao'] = futSimularBrasileirao('BR1');
    $t['serie_b']     = futSimularBrasileirao('BR2');
    $t['copa_brasil'] = futSimularCopaDoBrasil();

    // ── As vagas continentais saem da tabela do Brasileirão ──────────
    $ordem = array_keys($t['brasileirao']['tabela']);
    $clubes = $t['brasileirao']['clubes'];
    $paraLiberta = [];
    $paraSula = [];
    foreach (array_slice($ordem, 0, FUT_VAGAS_BR['liberta_tabela']) as $nome) $paraLiberta[] = $clubes[$nome];
    foreach (array_slice($ordem, FUT_VAGAS_BR['liberta_tabela'], FUT_VAGAS_BR['sula_tabela']) as $nome) $paraSula[] = $clubes[$nome];

    /* O campeão da Copa do Brasil leva vaga na Libertadores. Se ele já estava
       entre os seis primeiros, a vaga sobra e desce pro próximo da tabela —
       senão o Brasil mandaria menos clubes do que tem direito. */
    $campeaoCopa = $t['copa_brasil']['campeao'] ?? null;
    if ($campeaoCopa) {
        $jaTem = in_array($campeaoCopa['nome'], array_column($paraLiberta, 'nome'), true);
        if (!$jaTem) {
            $paraLiberta[] = $campeaoCopa;
            $paraSula = array_filter($paraSula, fn($c) => $c['nome'] !== $campeaoCopa['nome']);
            $paraSula = array_values($paraSula);
        }
    }

    $t['liberta'] = futSimularContinental('liberta', $paraLiberta);
    $t['sula']    = futSimularContinental('sula', $paraSula);

    return $t;
}

/** Os campeões da temporada, pra alimentar a temporada seguinte. */
function futCampeoesDaTemporada(array $t): array
{
    return [
        'brasileirao' => $t['brasileirao']['campeao'] ?? null,
        'copa_brasil' => $t['copa_brasil']['campeao'] ?? null,
        'liberta'     => $t['liberta']['campeao'] ?? null,
        'sula'        => $t['sula']['campeao'] ?? null,
    ];
}
