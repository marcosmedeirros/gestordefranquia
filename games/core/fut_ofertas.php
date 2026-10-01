<?php
/**
 * ── PROPOSTAS PELOS SEUS JOGADORES ───────────────────────────────────
 *
 * Entre uma partida e outra o mundo não queria nada de você. Comprar e vender
 * era sempre iniciativa sua, e um elenco bom só ficava bom — ninguém batia na
 * porta. Isso tira do jogo a decisão mais característica de ser técnico: o
 * telefone toca, o clube grande quer o seu melhor jogador, e você tem três
 * rodadas pra responder sabendo que a diretoria está olhando o caixa.
 *
 * ── POR QUE A PROPOSTA TEM PRAZO ─────────────────────────────────────
 *
 * Sem prazo ela viraria um botão de "vender por um preço melhor", parado na
 * tela até o dia em que desse jeito. O prazo é o que transforma em decisão:
 * aceitar agora, com o time montado em cima dele, ou deixar passar.
 *
 * ── POR QUE RECUSAR CUSTA ────────────────────────────────────────────
 *
 * Recusar sempre seria grátis, e aí não há escolha nenhuma — é só clicar não.
 * Quando a oferta passa muito do que o jogador vale, ele queria ir, e segurá-lo
 * custa moral. É o preço de manter o elenco: não dinheiro, vontade.
 *
 * ── O COMPRADOR EXISTE DE VERDADE ────────────────────────────────────
 *
 * Quem leva o jogador o registra em `entradas`, igual às transferências da
 * máquina (@see futTransferenciasDaIA): ele passa a aparecer no elenco daquele
 * clube, e você pode enfrentá-lo depois. Vender pra um clube que não fica com
 * ninguém seria apagar o jogador do mundo.
 */

/** Quantas propostas podem estar na mesa ao mesmo tempo. */
const FUT_OFERTAS_MAX = 2;

/** A chance, em %, de alguém bater na porta depois de uma rodada. */
const FUT_OFERTA_CHANCE = 22;

/** Quantas rodadas a proposta fica de pé antes de o clube desistir. */
const FUT_OFERTA_VALIDADE = 3;

/** Acima de quantas vezes o valor de mercado o jogador se sente indo embora. */
const FUT_OFERTA_TENTADORA = 1.30;

/** Quanta moral o jogador perde quando é segurado contra a vontade. */
const FUT_OFERTA_MORAL_SEGURADO = 12;

/**
 * O QUANTO CADA JOGADOR ATRAI OLHARES.
 *
 * O quadrado do que ele tem acima de 55 — assim o craque atrai muito mais que
 * o mediano, e não só um pouco mais. Quem está em boa fase atrai mais ainda:
 * é o que faz a temporada do jogador virar notícia fora do clube.
 */
function futOfertaPeso(array $j, array $stats): int
{
    $base = max(1, (int)$j['ovr'] - 55);
    $peso = $base * $base;

    $st = $stats[$j['nome']] ?? null;
    $jogos = (int)($st['jogos'] ?? 0);
    if ($jogos >= 3) {
        $media = (float)($st['soma_notas'] ?? 0) / $jogos;
        if ($media >= 7.0) $peso = (int)round($peso * 1.6);
    }
    return max(1, $peso);
}

/** Sorteia um item de uma lista [item, peso]. */
function futOfertaSortear(array $pesados): ?array
{
    $total = array_sum(array_column($pesados, 1));
    if ($total <= 0) return null;
    $corte = mt_rand(1, $total);
    foreach ($pesados as [$item, $peso]) {
        $corte -= $peso;
        if ($corte <= 0) return $item;
    }
    return $pesados[count($pesados) - 1][0] ?? null;
}

/** As propostas que o prazo venceu somem — e viram linha na caixa de entrada. */
function futOfertasExpirar(array $estado): array
{
    $rodada = (int)($estado['rodada'] ?? 0);
    $vivas = [];
    foreach ($estado['ofertas'] ?? [] as $o) {
        if ((int)$o['expira'] > $rodada) { $vivas[] = $o; continue; }
        $estado['mensagens'][] = sprintf('O %s desistiu de %s — a proposta venceu.',
            $o['clube'], $o['jogador']);
    }
    $estado['ofertas'] = $vivas;
    return $estado;
}

/**
 * Talvez um clube bata na porta.
 *
 * Só na fase de temporada: no mercado o jogador já tem a aba de negociação
 * inteira à disposição, e proposta com prazo de rodada não faz sentido quando
 * não há rodada correndo.
 */
function futOfertasGerar(array $estado): array
{
    if (($estado['fase'] ?? '') !== 'temporada') return $estado;
    if (count($estado['ofertas'] ?? []) >= FUT_OFERTAS_MAX) return $estado;
    if (count($estado['elenco'] ?? []) <= FUT_ELENCO_MINIMO) return $estado;
    if (mt_rand(1, 100) > FUT_OFERTA_CHANCE) return $estado;

    /* QUEM JÁ TEM PROPOSTA NÃO RECEBE OUTRA. Duas pelo mesmo jogador fariam
       aceitar uma e a outra ficar de pé por um jogador que não é mais seu. */
    $comProposta = array_column($estado['ofertas'] ?? [], 'jogador');

    $candidatos = [];
    foreach ($estado['elenco'] as $j) {
        if (in_array($j['nome'], $comProposta, true)) continue;
        // Emprestado tem dono: o passe não é seu pra vender.
        if (!empty($j['emprestado_de'])) continue;
        $candidatos[] = [$j, futOfertaPeso($j, $estado['stats'] ?? [])];
    }
    if (!$candidatos) return $estado;

    $alvo = futOfertaSortear($candidatos);
    if (!$alvo) return $estado;

    /* O COMPRADOR TEM QUE CABER NO JOGADOR. Clube de força 45 não vem buscar
       um de 80 — e o de 80 não se interessa por quem não melhora o time dele.
       A janela é larga pra cima de propósito: é o clube grande levando o
       destaque do pequeno, que é a história que a proposta conta. */
    $ovr = (int)$alvo['ovr'];
    $meu = (string)($estado['clube'] ?? '');
    $possiveis = [];
    foreach (futClubesDoJogo() as $c) {
        if ($c['nome'] === $meu) continue;
        $forca = (int)$c['forca'];
        if ($forca < $ovr - 2) continue;
        if (count(futElencoNoJogo($estado, $c['nome'], $forca)) >= FUT_ELENCO_MAXIMO) continue;
        $possiveis[] = [$c, max(1, $forca - $ovr + 3)];
    }
    if (!$possiveis) return $estado;

    $comprador = futOfertaSortear($possiveis);
    if (!$comprador) return $estado;

    $valor = futValorDeMercado($ovr, (int)$alvo['idade']);
    $proposta = round($valor * (mt_rand(85, 145) / 100), 2);

    $estado['ofertas'][] = [
        'clube'   => $comprador['nome'],
        'jogador' => $alvo['nome'],
        'pos'     => (string)($alvo['pos'] ?? ''),
        'ovr'     => $ovr,
        'valor'   => $proposta,
        'mercado' => $valor,
        'expira'  => (int)($estado['rodada'] ?? 0) + FUT_OFERTA_VALIDADE,
    ];
    $estado['mensagens'][] = sprintf('O %s ofereceu %s por %s.',
        $comprador['nome'], futDinheiro($proposta), $alvo['nome']);

    return $estado;
}

/** O que acontece no mercado depois de cada partida. */
function futOfertasDaRodada(array $estado): array
{
    return futOfertasGerar(futOfertasExpirar($estado));
}

/** A proposta daquele índice, ou null. */
function futOfertaPorIndice(array $estado, int $i): ?array
{
    return ($estado['ofertas'] ?? [])[$i] ?? null;
}

/**
 * ACEITA: o dinheiro entra, o jogador sai, e o comprador fica com ele.
 *
 * @return array ['ok'=>bool, 'motivo'=>string, 'estado'=>array]
 */
function futOfertaAceitar(array $estado, int $i): array
{
    $o = futOfertaPorIndice($estado, $i);
    if (!$o) return ['ok' => false, 'motivo' => 'Essa proposta não está mais na mesa.', 'estado' => $estado];

    if (count($estado['elenco'] ?? []) <= FUT_ELENCO_MINIMO) {
        return ['ok' => false, 'estado' => $estado,
                'motivo' => 'Seu elenco ficaria abaixo do mínimo de ' . FUT_ELENCO_MINIMO . '.'];
    }

    $saiu = null;
    foreach ($estado['elenco'] as $k => $j) {
        if ($j['nome'] !== $o['jogador']) continue;
        $saiu = $j;
        unset($estado['elenco'][$k]);
        break;
    }
    if (!$saiu) {
        // O jogador saiu por outro caminho (venda na aba mercado) e a proposta
        // ficou órfã. Tira ela da mesa em vez de reclamar de um dado quebrado.
        $estado = futOfertaTirarDaMesa($estado, $i);
        return ['ok' => false, 'motivo' => $o['jogador'] . ' não está mais no seu elenco.', 'estado' => $estado];
    }

    $estado['elenco'] = array_values($estado['elenco']);
    $estado['escalacao'] = [];     // vendeu alguém: a escalação velha não vale
    $estado['caixa'] = round((float)$estado['caixa'] + (float)$o['valor'], 2);

    // O comprador fica com ele de verdade — @see o cabeçalho deste arquivo.
    $estado['entradas'][$o['clube']][] = $saiu;
    $estado = futDesafioRegistrarVenda($estado, (float)$o['valor']);

    $estado['mensagens'][] = sprintf('%s foi vendido ao %s por %s.',
        $o['jogador'], $o['clube'], futDinheiro((float)$o['valor']));
    $estado['imprensa'] = array_slice(array_merge([[
        'texto' => sprintf('%s deixa o %s rumo ao %s por %s.',
            $o['jogador'], (string)$estado['clube'], $o['clube'], futDinheiro((float)$o['valor'])),
        'tom'   => 'ruim',
        'jogo'  => count($estado['resultados'] ?? []),
    ]], $estado['imprensa'] ?? []), 0, 12);

    $estado = futOfertaTirarDaMesa($estado, $i);
    return ['ok' => true, 'motivo' => sprintf('%s vendido por %s.', $o['jogador'], futDinheiro((float)$o['valor'])),
            'estado' => $estado];
}

/**
 * RECUSA: a proposta sai da mesa — e se era boa demais, custa moral.
 *
 * @return array ['ok'=>bool, 'motivo'=>string, 'estado'=>array]
 */
function futOfertaRecusar(array $estado, int $i): array
{
    $o = futOfertaPorIndice($estado, $i);
    if (!$o) return ['ok' => false, 'motivo' => 'Essa proposta não está mais na mesa.', 'estado' => $estado];

    $motivo = sprintf('Proposta do %s por %s recusada.', $o['clube'], $o['jogador']);
    $mercado = (float)($o['mercado'] ?? 0);

    if ($mercado > 0 && (float)$o['valor'] >= $mercado * FUT_OFERTA_TENTADORA) {
        foreach ($estado['elenco'] as $k => $j) {
            if ($j['nome'] !== $o['jogador']) continue;
            $estado['elenco'][$k]['moral'] = max(FUT_MORAL_MINIMA,
                (int)($j['moral'] ?? FUT_MORAL_NORMAL) - FUT_OFERTA_MORAL_SEGURADO);
            $motivo .= ' Ele queria ir, e não gostou de ficar.';
            $estado['mensagens'][] = sprintf('%s não gostou de ser segurado contra a vontade.', $o['jogador']);
            break;
        }
    }

    $estado = futOfertaTirarDaMesa($estado, $i);
    return ['ok' => true, 'motivo' => $motivo, 'estado' => $estado];
}

/** Tira uma proposta da mesa sem deixar buraco no índice. */
function futOfertaTirarDaMesa(array $estado, int $i): array
{
    $lista = $estado['ofertas'] ?? [];
    unset($lista[$i]);
    $estado['ofertas'] = array_values($lista);
    return $estado;
}
