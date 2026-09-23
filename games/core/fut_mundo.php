<?php
/**
 * O MUNDO SE MEXENDO SOZINHO — transferências, técnicos e propostas.
 *
 * Um jogo de carreira em que só o jogador se move é um jogo morto: você volta
 * na segunda temporada e o Flamengo tem exatamente o mesmo elenco, o mesmo
 * técnico e os mesmos problemas. Aqui os outros clubes compram e vendem entre
 * si, demitem e contratam técnicos, e ligam pra você quando sua reputação
 * sobe.
 *
 * ── COMO O ELENCO DOS OUTROS MUDA SEM INCHAR O SAVE ──────────────────
 *
 * O elenco de cada clube continua sendo gerado a partir do nome, sempre igual.
 * O que o save guarda são as DIFERENÇAS: 'saidas' (quem deixou o clube) e
 * 'entradas' (quem chegou). Uma transferência entre dois clubes da máquina é
 * uma linha em cada lista, e não dois elencos inteiros gravados.
 */

require_once __DIR__ . '/fut_mercado.php';
require_once __DIR__ . '/fut_evolucao.php';

/** Quantas transferências entre clubes da máquina acontecem por temporada. */
const FUT_TRANSFERENCIAS_POR_ANO = 14;

/**
 * O ELENCO DE UM CLUBE, com tudo que já aconteceu no jogo aplicado.
 *
 * Esta função é o único lugar que sabe juntar o elenco gerado com as saídas e
 * as entradas. Antes dela a conta estava repetida em quatro pontos (o mercado,
 * a força do time, a compra e a partida), e bastava esquecer um pra o jogador
 * comprado continuar jogando pelo clube antigo em uma das telas.
 */
function futElencoNoJogo(array $estado, string $clube, int $forcaCatalogo): array
{
    if ($clube === ($estado['clube'] ?? '')) return $estado['elenco'] ?? [];

    $elenco = futElencoDoClube($clube, $forcaCatalogo);

    $foram = $estado['saidas'][$clube] ?? [];
    if ($foram) {
        $elenco = array_values(array_filter($elenco, fn($j) => !in_array($j['nome'], $foram, true)));
    }
    foreach ($estado['entradas'][$clube] ?? [] as $j) $elenco[] = $j;

    return $elenco;
}

/**
 * AS TRANSFERÊNCIAS DA TEMPORADA entre os clubes da máquina.
 *
 * Clube grande compra de clube pequeno, como na vida. O critério é simples de
 * propósito: um comprador sorteado entre os fortes, um alvo que caiba no nível
 * dele, e pronto. Simular negociação de verdade entre 98 clubes custaria muito
 * e ninguém veria a diferença — o que o jogador percebe é que o elenco dos
 * outros muda de um ano pro outro.
 *
 * @return array ['estado'=>array, 'noticias'=>array]
 */
function futTransferenciasDaIA(array $estado, array $clubes): array
{
    $meu = $estado['clube'] ?? '';
    $lista = array_values(array_filter($clubes, fn($c) => $c['nome'] !== $meu));
    if (count($lista) < 2) return ['estado' => $estado, 'noticias' => []];

    // Os compradores são os mais fortes: são eles que têm dinheiro.
    $compradores = $lista;
    usort($compradores, fn($a, $b) => $b['forca'] <=> $a['forca']);
    $compradores = array_slice($compradores, 0, max(6, (int)(count($lista) * 0.4)));

    $noticias = [];
    for ($i = 0; $i < FUT_TRANSFERENCIAS_POR_ANO; $i++) {
        $comprador = $compradores[array_rand($compradores)];
        $vendedor = $lista[array_rand($lista)];
        if ($vendedor['nome'] === $comprador['nome']) continue;

        $elencoV = futElencoNoJogo($estado, $vendedor['nome'], (int)$vendedor['forca']);
        if (count($elencoV) <= FUT_ELENCO_MINIMO) continue;

        $elencoC = futElencoNoJogo($estado, $comprador['nome'], (int)$comprador['forca']);
        if (count($elencoC) >= FUT_ELENCO_MAXIMO) continue;

        /* O alvo tem que ser melhor que o que o comprador já tem, senão a
           transferência não faz sentido nenhuma — e o comprador não paga por
           quem está muito acima do nível dele. */
        $candidatos = array_values(array_filter($elencoV, function ($j) use ($comprador) {
            $o = (int)$j['ovr'];
            return $o >= (int)$comprador['forca'] - 4 && $o <= (int)$comprador['forca'] + 6;
        }));
        if (!$candidatos) continue;

        $alvo = $candidatos[array_rand($candidatos)];

        $estado['saidas'][$vendedor['nome']][] = $alvo['nome'];
        $estado['entradas'][$comprador['nome']][] = $alvo;

        $noticias[] = sprintf('%s (%d) saiu do %s para o %s.',
            $alvo['nome'], (int)$alvo['ovr'], $vendedor['nome'], $comprador['nome']);
    }

    return ['estado' => $estado, 'noticias' => $noticias];
}

/**
 * Nomes de técnico pra povoar os outros clubes.
 *
 * São personagens fictícios, como os jogadores genéricos. O sobrenome sozinho
 * é a forma como técnico é chamado no Brasil — "o Abel", "o Tite" —, então a
 * lista é curta de propósito.
 */
const FUT_NOMES_TECNICO = [
    'Abelardo', 'Bacchi', 'Brandão', 'Caetano', 'Coelho', 'Dorival', 'Fabiano',
    'Ferreira', 'Gilmar', 'Guerra', 'Jair', 'Lisca', 'Macedo', 'Maurício',
    'Moacir', 'Odair', 'Paiva', 'Pintado', 'Quadros', 'Rogério', 'Sampaio',
    'Sérgio', 'Tadeu', 'Umberto', 'Valente', 'Vagner', 'Zanotti',
];

/** O técnico de um clube, estável enquanto ele não for demitido. */
function futTecnicoDoClube(string $clube, int $temporada, array $trocas = []): string
{
    $geracao = (int)($trocas[$clube] ?? 0);
    $i = crc32('tec|' . $clube . '|' . $geracao) % count(FUT_NOMES_TECNICO);
    return FUT_NOMES_TECNICO[$i];
}

/**
 * A DANÇA DOS TÉCNICOS no fim da temporada.
 *
 * Alguns clubes trocam de comando todo ano — é o esporte mais demitido do
 * mundo. Isso não muda a força de ninguém: serve pra o mundo parecer vivo e
 * pra as vagas existirem quando o jogador estiver procurando clube.
 *
 * @return array ['estado'=>array, 'noticias'=>array]
 */
function futDancaDosTecnicos(array $estado, array $clubes): array
{
    $meu = $estado['clube'] ?? '';
    $noticias = [];
    $quantos = max(3, (int)round(count($clubes) * 0.22));

    $nomes = array_values(array_filter(array_column($clubes, 'nome'), fn($n) => $n !== $meu));
    if (!$nomes) return ['estado' => $estado, 'noticias' => []];
    shuffle($nomes);

    foreach (array_slice($nomes, 0, $quantos) as $n) {
        $antes = futTecnicoDoClube($n, (int)$estado['temporada'], $estado['trocas_tecnico'] ?? []);
        $estado['trocas_tecnico'][$n] = (int)($estado['trocas_tecnico'][$n] ?? 0) + 1;
        $depois = futTecnicoDoClube($n, (int)$estado['temporada'], $estado['trocas_tecnico']);
        $noticias[] = sprintf('%s demitiu %s e contratou %s.', $n, $antes, $depois);
    }
    return ['estado' => $estado, 'noticias' => $noticias];
}

/**
 * AS PROPOSTAS DE EMPREGO que chegam pro técnico no fim do ano.
 *
 * É a escada da carreira: cumpriu meta e ganhou reputação, clube maior liga.
 * Só aparecem clubes ACIMA do atual — receber convite pra descender seria só
 * ruído — e nunca mais do que três, pra a escolha ser uma decisão e não uma
 * lista pra rolar.
 *
 * @return array lista de clubes
 */
function futPropostasDeEmprego(array $estado, array $clubes, int $quantas = 3): array
{
    $rep = (int)($estado['tecnico']['reputacao'] ?? 0);
    $meuNome = $estado['clube'] ?? '';
    $meu = $clubes[$meuNome] ?? null;
    $minhaForca = (int)($meu['forca'] ?? 50);

    /* O teto do que a reputação abre. É a mesma régua de futClubesParaComecar,
       e por isso um técnico campeão consegue o que quiser: reputação 80 abre o
       Palmeiras. */
    $teto = match (true) {
        $rep >= 80 => 100,
        $rep >= 60 => 86,
        $rep >= 45 => 80,
        $rep >= 30 => 72,
        $rep >= 18 => 64,
        default    => 0,     // sem currículo, ninguém liga
    };
    if ($teto <= 0) return [];

    $candidatos = [];
    foreach ($clubes as $c) {
        if ($c['nome'] === $meuNome) continue;
        if ((int)$c['forca'] > $teto) continue;
        if ((int)$c['forca'] <= $minhaForca + 1) continue;   // só convite pra subir
        $candidatos[] = $c;
    }
    if (!$candidatos) return [];

    usort($candidatos, fn($a, $b) => $b['forca'] <=> $a['forca']);
    /* Os melhores que a reputação alcança, com um pouco de variedade: pegamos
       os 10 primeiros e sorteamos entre eles, senão o convite seria sempre do
       mesmo clube. */
    $topo = array_slice($candidatos, 0, 10);
    shuffle($topo);
    return array_slice($topo, 0, $quantas);
}

/**
 * O CLUBE DEMITE O TÉCNICO NO MEIO DA TEMPORADA?
 *
 * Só quando a campanha está muito abaixo do combinado e já passou tempo
 * suficiente pra isso significar alguma coisa. Demitir na quinta rodada por
 * causa de duas derrotas seria injusto e deixaria o jogador sem chance de
 * reagir — que é o oposto do que a pressão deve fazer no jogo.
 */
function futPressaoNoCargo(array $estado, ?int $posicao, int $jogosNacional): array
{
    $meta = (int)($estado['meta']['alvo'] ?? 99);
    if ($posicao === null || $jogosNacional < 15) {
        return ['risco' => 'tranquilo', 'texto' => 'A diretoria está tranquila.'];
    }

    $distancia = $posicao - $meta;
    if ($distancia <= 0)  return ['risco' => 'tranquilo', 'texto' => 'Dentro da meta. A diretoria está satisfeita.'];
    if ($distancia <= 3)  return ['risco' => 'atencao',  'texto' => 'Um pouco abaixo da meta. A diretoria está de olho.'];
    if ($distancia <= 7)  return ['risco' => 'perigo',   'texto' => 'Longe da meta. Sua cadeira está balançando.'];
    return ['risco' => 'critico', 'texto' => 'Muito longe da meta. A demissão está em cima da mesa.'];
}
