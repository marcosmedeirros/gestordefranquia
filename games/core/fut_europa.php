<?php
/**
 * A EUROPA NO JOGO DE CARREIRA — seis ligas, seis copas e duas continentais.
 *
 * O jogo era só o Brasil: estadual, Brasileirão, Copa do Brasil, copa
 * regional. Este arquivo é o outro lado do mundo, e ele se encaixa sem
 * duplicar o motor — o clube europeu tem `div` e `forca` na MESMA régua do
 * brasileiro (@see fut_clubes_eu.php), então a partida, a tabela, o mercado e
 * a evolução não sabem nem precisam saber de que continente o clube é.
 *
 * ── O QUE MUDA DE VERDADE ────────────────────────────────────────────
 *
 * O FORMATO DO ANO. No Brasil o ano abre com o estadual e a copa regional; na
 * Europa não existe nada disso. Lá são três coisas: a liga nacional inteira,
 * a copa nacional em mata-mata, e a continental pra quem se classificou.
 *
 * QUEM JOGA A CONTINENTAL. No Brasil a vaga sai da tabela do ano anterior, que
 * a carreira não guarda pros outros clubes. Aqui a régua é a mesma que a meta
 * da temporada já usa: a POSIÇÃO DO CLUBE NA FILA DA FORÇA da liga dele. O
 * quarto mais forte da Premier entra na Champions; o décimo não. É uma
 * aproximação, e é honesta — o clube que investe no elenco sobe na fila e
 * passa a se classificar, que é exatamente o que o técnico está tentando fazer.
 */

require_once __DIR__ . '/fut_clubes_eu.php';
require_once __DIR__ . '/fut_clubes_br.php';
require_once __DIR__ . '/fut_elencos.php';

/**
 * AS SEIS LIGAS: a copa nacional de cada uma e quantas vagas ela dá.
 *
 * As vagas seguem o coeficiente de verdade — Inglaterra, Espanha, Itália e
 * Alemanha com quatro na Champions, Portugal com duas. Não é enfeite: é o que
 * faz terminar em quinto no Porto valer menos do que terminar em quinto no
 * Liverpool, e é a diferença entre dirigir numa liga grande e numa pequena.
 */
const FUT_LIGAS_EU = [
    'EN1' => ['rebaixa' => 3, 'nome' => 'Premier League', 'curto' => 'Premier League', 'pais' => 'ENG',
              'paisNome' => 'Inglaterra', 'copa' => 'FA Cup',
              'champions' => 4, 'europa' => 2],
    'ES1' => ['rebaixa' => 3, 'nome' => 'La Liga',        'curto' => 'La Liga',        'pais' => 'ESP',
              'paisNome' => 'Espanha',    'copa' => 'Copa del Rey',
              'champions' => 4, 'europa' => 2],
    'IT1' => ['rebaixa' => 3, 'nome' => 'Serie A',        'curto' => 'Serie A',        'pais' => 'ITA',
              'paisNome' => 'Itália',     'copa' => 'Coppa Italia',
              'champions' => 4, 'europa' => 2],
    'DE1' => ['rebaixa' => 2, 'nome' => 'Bundesliga',     'curto' => 'Bundesliga',     'pais' => 'GER',
              'paisNome' => 'Alemanha',   'copa' => 'DFB-Pokal',
              'champions' => 4, 'europa' => 2],
    'FR1' => ['rebaixa' => 2, 'nome' => 'Ligue 1',        'curto' => 'Ligue 1',        'pais' => 'FRA',
              'paisNome' => 'França',     'copa' => 'Copa da França',
              'champions' => 3, 'europa' => 2],
    'PT1' => ['rebaixa' => 2, 'nome' => 'Liga Portugal',  'curto' => 'Liga Portugal',  'pais' => 'POR',
              'paisNome' => 'Portugal',   'copa' => 'Taça de Portugal',
              'champions' => 2, 'europa' => 2],
];

/**
 * `rebaixa` é quantos caem no fim do ano. Não é um número redondo por liga
 * porque não é redondo na vida: Inglaterra, Espanha e Itália rebaixam três
 * direto; Alemanha, França e Portugal rebaixam dois e mandam o antepenúltimo
 * pra um playoff que o jogo não disputa — então aqui eles rebaixam dois.
 *
 * A tela usa isso pra pintar a zona vermelha da classificação. Sem ele, a
 * tabela europeia herdava a regra brasileira e marcava quatro.
 */

/** As duas continentais, da maior pra menor. */
const FUT_CONTINENTAIS_EU = ['Champions League', 'Liga Europa'];

/**
 * OS CLUBES EUROPEUS, no mesmo formato dos brasileiros.
 *
 * Os campos `uf` e `regiao` vêm vazios de propósito em vez de ausentes: o
 * calendário, a meta e a tela leem `$c['uf'] ?? ''` em vários pontos, e um
 * clube sem a chave passaria por lugares que esperam a forma completa.
 *
 * @return array nome => ['nome','div','uf','regiao','pais','forca','escudo']
 */
function futClubesDaEuropa(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $out = [];
    foreach (FUT_CLUBES_EU as [$nome, $div, $pais, $forca, $escudo]) {
        $out[$nome] = ['nome' => $nome, 'div' => $div, 'uf' => '', 'regiao' => '',
                       'pais' => $pais, 'forca' => $forca, 'escudo' => $escudo];
    }

    /* A FORÇA DO ARQUIVO MANDA, como no Brasil. O número gravado no catálogo
       saiu do elenco na importação; se o elenco mudar — e ele muda, a próxima
       base de jogadores é outro ano —, a força acompanha sem precisar gerar o
       catálogo de novo. */
    foreach ($out as $nome => $c) {
        $real = futForcaDoElencoReal($nome);
        if ($real !== null) $out[$nome]['forca'] = $real;
        $out[$nome]['escudo'] = futEscudoDoClube($nome, (string)$c['escudo']);
    }

    return $cache = $out;
}

/**
 * TODOS OS CLUBES DO JOGO: o Brasil e a Europa numa lista só.
 *
 * É esta que a carreira usa, e não futClubesDoBrasil(): o técnico pode estar
 * dirigindo o Porto, e a tela dele precisa achar o clube dele em algum lugar.
 * A simulação do ano brasileiro (@see fut_competicoes.php) continua chamando a
 * lista brasileira, porque lá a Europa não entra mesmo.
 */
function futClubesDoJogo(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    return $cache = futClubesDoBrasil() + futClubesDaEuropa();
}

/**
 * OS CLUBES DE UMA DIVISÃO, seja ela brasileira ou europeia.
 *
 * futClubesDaDivisao() olha só o Brasil, e é ela que a simulação do ano
 * brasileiro usa. A carreira precisa da outra: o técnico do Porto pede a
 * tabela de 'PT1' e tem que receber os dezoito portugueses.
 */
function futClubesDaDivisaoDoJogo(string $div): array
{
    return array_filter(futClubesDoJogo(), fn($c) => $c['div'] === $div);
}

/** O clube é europeu? */
function futEhClubeEuropeu(string $nome): bool
{
    return isset(futClubesDaEuropa()[$nome]);
}

/** Os clubes de uma liga europeia. */
function futClubesDaLigaEu(string $div): array
{
    return array_filter(futClubesDaEuropa(), fn($c) => $c['div'] === $div);
}

/**
 * A POSIÇÃO DO CLUBE NA FILA DA FORÇA da liga dele — 1 é o mais forte.
 *
 * É a régua da vaga continental, e a mesma ideia que futMetaDaTemporada já usa
 * pra cobrar o técnico. Ordenar por força e desempatar pelo nome mantém a fila
 * igual a cada chamada, o que importa: a vaga não pode mudar entre uma tela e
 * outra.
 */
function futPostoNaLiga(string $div, string $clube): int
{
    /* A fila é consultada uma vez por clube a cada sorteio continental — sem
       este cache, seriam 114 ordenações pra montar um calendário. */
    static $filas = [];
    if (!isset($filas[$div])) {
        $liga = futClubesDaLigaEu($div);
        uasort($liga, fn($a, $b) => [-$a['forca'], $a['nome']] <=> [-$b['forca'], $b['nome']]);
        $filas[$div] = array_flip(array_keys($liga));
    }
    if (!isset($filas[$div][$clube])) return count($filas[$div]);
    return $filas[$div][$clube] + 1;
}

/**
 * A continental que o clube disputa este ano, ou '' se nenhuma.
 *
 * NÃO DEPENDE DO ANO PASSADO, e isso é uma escolha. O save da carreira só
 * guarda a campanha do clube do JOGADOR, então uma regra como "o campeão da
 * Liga Europa entra na Champions" valeria pra um clube e não valeria pros
 * outros 113 — e a vaga dos outros é justamente o que precisa ser estável pra
 * o sorteio não mudar de uma tela pra outra. A fila da força vale igual pra
 * todo mundo.
 */
function futContinentalDoClube(array $clube): string
{
    $div = (string)($clube['div'] ?? '');
    $meta = FUT_LIGAS_EU[$div] ?? null;
    if (!$meta) return '';

    $posto = futPostoNaLiga($div, (string)($clube['nome'] ?? ''));
    if ($posto <= $meta['champions']) return 'Champions League';
    if ($posto <= $meta['champions'] + $meta['europa']) return 'Liga Europa';
    return '';
}

/**
 * OS ADVERSÁRIOS DE UMA CONTINENTAL, tirados da Europa inteira.
 *
 * Cada competição sorteia entre quem teria vaga NELA: a Champions pega os
 * quatro primeiros de cada liga grande, a Liga Europa pega a faixa de baixo.
 * É o que faz a Champions parecer uma Champions — sem isso o Arsenal cairia
 * num grupo com o Farense.
 *
 * @return array nomes de clubes, do mais fraco ao mais forte
 */
function futAdversariosContinentais(string $comp, array $clube, int $quantos, int $semente): array
{
    $todos = futClubesDaEuropa();
    unset($todos[(string)$clube['nome']]);

    $elite = [];
    foreach ($todos as $c) {
        if (futContinentalDoClube($c) !== $comp) continue;
        $elite[] = $c;
    }
    // Rede de segurança: se a faixa não tiver gente suficiente, vale a Europa.
    if (count($elite) < $quantos) $elite = array_values($todos);

    mt_srand($semente);
    shuffle($elite);
    mt_srand();

    $escolhidos = array_slice($elite, 0, $quantos);
    usort($escolhidos, fn($a, $b) => $a['forca'] <=> $b['forca']);
    return array_column($escolhidos, 'nome');
}

/**
 * MONTA O ANO EUROPEU do clube do jogador.
 *
 * A liga ocupa o ano inteiro, e a copa nacional e a continental são encaixadas
 * no meio dela. Encaixar em vez de empilhar no fim é a mesma razão que já valia
 * pra Copa do Brasil: seis mata-matas seguidos em maio não é um calendário, é
 * uma fila.
 *
 * @return array lista de ['comp','adversario','casa','fase',...]
 */
function futCalendarioEuropeu(array $estado, array $clubes, array $eu): array
{
    $meu = (string)$eu['nome'];
    $div = (string)$eu['div'];
    $meta = FUT_LIGAS_EU[$div] ?? null;
    if (!$meta) return [];

    // ── A liga nacional: turno e returno, o calendário inteiro ───────
    $liga = [];
    foreach (futCarreiraCalendarioDaLiga($div) as $n => $jogos) {
        foreach ($jogos as [$casa, $fora]) {
            if ($casa !== $meu && $fora !== $meu) continue;
            $liga[] = ['comp' => $meta['nome'], 'adversario' => $casa === $meu ? $fora : $casa,
                       'casa' => $casa === $meu, 'fase' => 'Rodada ' . ($n + 1),
                       'liga_rodada' => $n + 1];
        }
    }

    $encaixar = [];

    /* ── A copa nacional ──────────────────────────────────────────────
       Quatro fases e adversário do próprio país, como é lá — a FA Cup é entre
       ingleses. O jogo entra nas oitavas e não nas trintas-e-duas-avos porque
       as rodadas de baixo são contra clubes de divisão que o jogo não tem: o
       técnico jogaria seis partidas contra adversário sem elenco nenhum. */
    $doPais = array_values(array_diff(array_keys(futClubesDaLigaEu($div)), [$meu]));
    shuffle($doPais);
    foreach (['Oitavas', 'Quartas', 'Semifinal', 'Final'] as $i => $fase) {
        if (!isset($doPais[$i])) break;
        $encaixar[] = ['comp' => $meta['copa'], 'adversario' => $doPais[$i],
                       'casa' => $i % 2 === 0, 'fase' => $fase, 'copa_fase' => $i];
    }

    // ── A continental, pra quem tem vaga ─────────────────────────────
    $comp = futContinentalDoClube($eu);
    if ($comp !== '') {
        $semente = crc32($meu . '|' . $comp . '|t' . ($estado['temporada'] ?? 0));

        /* A FASE DE GRUPOS é de quatro clubes, ida e volta: seis jogos, que é
           o que futCarreiraTabelaDeGrupo sabe virar classificação. */
        $grupo = futAdversariosContinentais($comp, $eu, 3, $semente);
        foreach ($grupo as $adv) {
            $encaixar[] = ['comp' => $comp, 'adversario' => $adv, 'casa' => true,
                           'fase' => 'Fase de grupos'];
            $encaixar[] = ['comp' => $comp, 'adversario' => $adv, 'casa' => false,
                           'fase' => 'Fase de grupos'];
        }

        /* O MATA-MATA É SORTEADO À PARTE e com clubes de fora do grupo: quem
           passou não reencontra nas oitavas quem acabou de enfrentar duas
           vezes. Por isso o sorteio pede mais nomes do que as quatro fases
           precisam — os do grupo saem depois. */
        $mata = futAdversariosContinentais($comp, $eu, 4 + count($grupo), $semente + 7);
        $mata = array_values(array_diff($mata, $grupo));
        foreach (['Oitavas', 'Quartas', 'Semifinal', 'Final'] as $i => $fase) {
            if (!isset($mata[$i])) break;
            $encaixar[] = ['comp' => $comp, 'adversario' => $mata[$i],
                           'casa' => $i % 2 === 0, 'fase' => $fase, 'copa_fase' => $i];
        }
    }

    return futIntercalarNaLiga($liga, $encaixar);
}

/**
 * ESPALHA os jogos de copa entre as rodadas da liga.
 *
 * Mantém a ORDEM em que as copas foram montadas — a fase de grupos antes das
 * oitavas, a semifinal antes da final —, e só distribui o espaçamento. Sem
 * isso o mata-mata sairia antes do grupo que classificou pra ele.
 */
function futIntercalarNaLiga(array $liga, array $copas): array
{
    if (!$copas) return $liga;
    if (!$liga) return $copas;

    $passo = max(1, (int)floor(count($liga) / (count($copas) + 1)));
    $out = [];
    $c = 0;
    foreach ($liga as $i => $j) {
        $out[] = $j;
        if ($c < count($copas) && ($i + 1) % $passo === 0) $out[] = $copas[$c++];
    }
    while ($c < count($copas)) $out[] = $copas[$c++];
    return $out;
}

/**
 * AS COPAS EM QUE PERDER ELIMINA — a lista inteira do jogo, Brasil e Europa.
 *
 * Uma lista só, e não uma por continente, porque quem pergunta
 * (@see futCarreiraEhMataMata) não tem o clube na mão: tem o nome da
 * competição e mais nada.
 */
function futCopasDeMataMata(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $out = ['Copa do Brasil', 'Libertadores', 'Sul-Americana'];
    foreach (FUT_LIGAS_EU as $m) $out[] = $m['copa'];
    return $cache = array_merge($out, FUT_CONTINENTAIS_EU);
}

/**
 * AS FAIXAS PINTADAS DA CLASSIFICAÇÃO: quantos sobem e quantos caem.
 *
 * Uma função pros dois mundos porque a tabela é a mesma tela. No Brasil,
 * "verde" é acesso à divisão de cima e a Série D não tem queda; na Europa não
 * existe divisão de cima no jogo — o verde lá é a vaga continental, que é o
 * prêmio de terminar em cima, e é o que a diretoria cobra.
 *
 * @return array{0:int,1:int} [quantos no verde, quantos no vermelho]
 */
function futZonasDaTabela(string $div): array
{
    if (isset(FUT_LIGAS_EU[$div])) {
        $m = FUT_LIGAS_EU[$div];
        return [$m['champions'] + $m['europa'], $m['rebaixa']];
    }
    return [$div === 'BR1' ? 0 : 4, $div === 'BR4' ? 0 : 4];
}

/** O nome da liga nacional europeia, ou '' se a divisão não for de lá. */
function futNomeDaLigaEu(string $div): string
{
    return FUT_LIGAS_EU[$div]['nome'] ?? '';
}

/** O nome curto da liga, pra linha de clube na tela. */
function futRotuloDaLigaEu(string $div): string
{
    return FUT_LIGAS_EU[$div]['curto'] ?? '';
}

/**
 * "CAMPEÃO D_ ..." — o artigo que vem antes do nome da competição.
 *
 * A tela decidia isso com `stripos($comp, 'copa') === 0 ? 'a' : 'o'`, o que
 * funcionava enquanto tudo era brasileiro: Copa do Brasil era feminina e o
 * resto masculino. Com a Europa a regra vira o contrário — a Liga Portugal, a
 * Bundesliga, a Serie A, a Champions e a Taça são todas femininas, e sobrou
 * pouca coisa no masculino. Inverter a lista é o que deixa de sair "Campeão do
 * Liga Portugal" na tela.
 */
function futArtigoDaCompeticao(string $comp): string
{
    foreach (['Brasileirão', 'Campeonato', 'DFB-Pokal'] as $masculino) {
        if (stripos($comp, $masculino) === 0) return 'o';
    }
    return 'a';
}

/** O país de um clube, que é como a tela de escolha agrupa a lista. */
function futPaisDoClube(array $clube): string
{
    return FUT_LIGAS_EU[(string)($clube['div'] ?? '')]['paisNome'] ?? 'Brasil';
}
