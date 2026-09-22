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
 * Chile e companhia não têm liga simulada: a classificação de cada uma é
 * resolvida em uma linha por futVagasDoPais() — a força do clube mais um susto
 * de fim de temporada. O topo vai à Libertadores, a faixa seguinte à
 * Sul-Americana. River e Boca estão quase sempre lá, e de vez em quando fazem
 * um ano ruim e caem pra Sula, sem o custo de simular nove campeonatos que
 * ninguém vai abrir pra ver. Se um dia a carreira deixar dirigir fora do
 * Brasil, é essa função que vira liga de verdade.
 */

require_once __DIR__ . '/fut_motor.php';
require_once __DIR__ . '/fut_clubes_br.php';
require_once __DIR__ . '/fut_elencos.php';

/**
 * As vagas de cada país da CONMEBOL.
 *
 * ── AS COTAS SOMAM 32, E ISSO NÃO É DETALHE ──────────────────────────
 *
 * A fase de grupos tem 32 clubes. Na primeira versão as cotas somavam 45 e a
 * competição cortava os 32 MAIS FORTES — o que parecia razoável e destruía a
 * ideia de vaga por país: Bolívar e Caracas, que na vida real jogam a
 * Libertadores todo ano, simplesmente nunca entravam, porque um clube de 62
 * perde a vaga pro décimo argentino de 70. Medindo 400 temporadas, Bolívia e
 * Venezuela não apareciam uma vez sequer.
 *
 * Com as cotas somando exatamente 32, quem define quem entra é a CONMEBOL, e
 * não a força — que é como o torneio funciona. O boliviano entra, e entra
 * fraco, e é despachado na fase de grupos: isso é a Libertadores.
 */
const FUT_PAISES_CONMEBOL = [
    'AR1' => ['nome' => 'Argentina', 'liberta' => 5, 'sula' => 5],
    'UY1' => ['nome' => 'Uruguai',   'liberta' => 3, 'sula' => 3],
    'CL1' => ['nome' => 'Chile',     'liberta' => 3, 'sula' => 3],
    'CO1' => ['nome' => 'Colômbia',  'liberta' => 3, 'sula' => 3],
    'EC1' => ['nome' => 'Equador',   'liberta' => 3, 'sula' => 3],
    'PY1' => ['nome' => 'Paraguai',  'liberta' => 2, 'sula' => 3],
    'PE1' => ['nome' => 'Peru',      'liberta' => 2, 'sula' => 2],
    'BO1' => ['nome' => 'Bolívia',   'liberta' => 2, 'sula' => 2],
    'VE1' => ['nome' => 'Venezuela', 'liberta' => 2, 'sula' => 2],
];   // 25 estrangeiros + 7 do Brasil = 32 na Libertadores
     // 26 estrangeiros + 6 do Brasil = 32 na Sul-Americana

/** Quantas vagas o Brasil leva, e por onde. */
const FUT_VAGAS_BR = [
    'liberta_tabela' => 6,    // do 1º ao 6º do Brasileirão, mais o da Copa
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
 * O "campeonato nacional" de um país que não tem liga simulada, resolvido em
 * uma linha: a força de cada clube mais um susto de fim de temporada.
 *
 * ── POR QUE NÃO É SORTEIO POR PESO ───────────────────────────────────
 *
 * A primeira versão sorteava com peso pela força, e o resultado saiu ao
 * contrário do esperado: medindo 400 temporadas, Olimpia (69) disputava
 * continental em 110% delas e o Boca Juniors (87) em 72%. Dois defeitos
 * somados: o sorteio por peso espalhava demais num país com 24 clubes e 12
 * vagas, enquanto um país com 10 clubes e 8 vagas mandava quase todo mundo
 * todo ano — ou seja, quem definia a presença era o tamanho do catálogo do
 * país, não a qualidade do clube. E o 110% denunciava o segundo: o mesmo clube
 * entrava na Libertadores E na Sul-Americana no mesmo ano, porque as duas
 * chamavam esta função em sorteios separados.
 *
 * Agora é uma classificação só: força mais um ruído de alguns pontos, ordena, e
 * o topo vai pra Libertadores, a faixa seguinte pra Sul-Americana. River quase
 * sempre está lá; de vez em quando faz um ano ruim e cai pra Sula — que é
 * exatamente o que acontece de verdade.
 *
 * @return array ['liberta' => [...], 'sula' => [...]]
 */
function futVagasDoPais(string $liga, int $vagasLiberta, int $vagasSula): array
{
    $doPais = [];
    foreach (COPERO_CLUBES as $c) {
        if ($c[1] !== $liga) continue;
        $doPais[] = ['nome' => $c[0], 'forca' => (int)$c[2], 'uf' => '', 'div' => $c[1],
                     'escudo' => $c[3] ?? ''];
    }
    if ($doPais === []) return ['liberta' => [], 'sula' => []];

    /* O ruído é a temporada que não foi simulada. Seis pontos de desvio deixam
       o grande quase sempre à frente sem tornar a tabela congelada — com zero,
       os mesmos quatro clubes iriam à Libertadores todo ano pra sempre. */
    foreach ($doPais as &$c) {
        $c['nota'] = $c['forca'] + (futSorteioNormal() * 6);
    }
    unset($c);
    usort($doPais, fn($a, $b) => $b['nota'] <=> $a['nota']);

    return [
        'liberta' => array_slice($doPais, 0, $vagasLiberta),
        'sula'    => array_slice($doPais, $vagasLiberta, $vagasSula),
    ];
}

/** Um número normal (média 0, desvio 1) pelo método de Box-Muller. */
function futSorteioNormal(): float
{
    $u = max(1e-9, mt_rand() / mt_getrandmax());
    $v = mt_rand() / mt_getrandmax();
    return sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
}

/**
 * As vagas de TODOS os países da CONMEBOL numa tacada.
 *
 * É uma chamada só por temporada de propósito: se a Libertadores e a
 * Sul-Americana perguntassem cada uma por conta, o mesmo clube apareceria nas
 * duas — era o bug que a medição de 400 temporadas expôs.
 */
function futVagasConmebol(): array
{
    $out = ['liberta' => [], 'sula' => []];
    foreach (FUT_PAISES_CONMEBOL as $liga => $info) {
        $v = futVagasDoPais($liga, $info['liberta'], $info['sula']);
        $out['liberta'] = array_merge($out['liberta'], $v['liberta']);
        $out['sula']    = array_merge($out['sula'], $v['sula']);
    }
    return $out;
}
/**
 * A LIBERTADORES (ou a Sul-Americana): fase de grupos e mata-mata, com final
 * em jogo único.
 *
 * @param array $brasileiros os clubes do Brasil que se classificaram
 */
function futSimularContinental(string $qual, array $brasileiros, array $estrangeiros = []): array
{
    /* SEM CORTE POR FORÇA: as cotas já somam 32, e cortar por força aqui
       reintroduziria o defeito que elas existem pra evitar. A ordenação é só
       pra distribuição em potes lá na fase de grupos. */
    $times = array_merge($brasileiros, $estrangeiros);
    usort($times, fn($a, $b) => $b['forca'] <=> $a['forca']);

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
    /* O BRASIL MANDA SEMPRE 7 À LIBERTADORES E 6 À SUL-AMERICANA, e esse
       "sempre" é o que faz as cotas fecharem os 32 de cada torneio. A primeira
       versão perdia uma vaga quando o campeão da Copa do Brasil já estava no
       G6: ninguém descia pra ocupar o lugar, o torneio ficava com 31 e a fase
       de grupos — que monta grupos de 4 — descartava mais três, rodando com 28.
       Acontecia em 4 de cada 10 temporadas.
       Agora a vaga do campeão da Copa é extra de verdade: se ele já estava no
       G6, quem sobe é o 7º, e a Sul-Americana repõe com o próximo da fila. */
    $ordem = array_keys($t['brasileirao']['tabela']);
    $clubes = $t['brasileirao']['clubes'];
    $campeaoCopa = $t['copa_brasil']['campeao'] ?? null;

    $liberta = array_slice($ordem, 0, FUT_VAGAS_BR['liberta_tabela']);   // o G6
    if ($campeaoCopa && !in_array($campeaoCopa['nome'], $liberta, true)) {
        $liberta[] = $campeaoCopa['nome'];
    } else {
        // Campeão da Copa já classificado: a vaga dele desce pro próximo.
        foreach ($ordem as $nome) {
            if (!in_array($nome, $liberta, true)) { $liberta[] = $nome; break; }
        }
    }
    // A Sul-Americana leva os 6 seguintes que sobraram da tabela.
    $sula = [];
    foreach ($ordem as $nome) {
        if (count($sula) >= FUT_VAGAS_BR['sula_tabela']) break;
        if (!in_array($nome, $liberta, true)) $sula[] = $nome;
    }

    $paraLiberta = array_map(fn($n) => $clubes[$n], $liberta);
    $paraSula    = array_map(fn($n) => $clubes[$n], $sula);
    $conmebol = futVagasConmebol();
    $t['liberta'] = futSimularContinental('liberta', $paraLiberta, $conmebol['liberta']);
    $t['sula']    = futSimularContinental('sula', $paraSula, $conmebol['sula']);

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
