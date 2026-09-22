<?php
/**
 * O MERCADO — quanto vale um jogador, quanto ele ganha, e quem compra quem.
 *
 * É a metade do jogo que não é partida. No Brasfoot você passa mais tempo
 * montando o elenco do que vendo o placar, e é aqui que isso acontece: preço,
 * salário, contrato, proposta, e os outros clubes se mexendo sozinhos.
 *
 * ── A IDEIA CENTRAL: PREÇO NÃO É SÓ OVR ──────────────────────────────
 *
 * Um zagueiro de 78 com 33 anos e um de 78 com 21 têm a mesma força em campo e
 * preços que não se parecem. Se o valor saísse só do OVR, o jogo perderia a
 * decisão mais interessante que ele tem pra oferecer — vender o veterano
 * enquanto ainda vale alguma coisa, ou segurar o garoto que vai dobrar de
 * preço. Por isso o valor é OVR e IDADE, e a idade pesa muito.
 *
 * Valores em milhões, na mesma unidade em toda parte. Não é moeda de país
 * nenhum de propósito — é "milhões" e pronto, como no Brasfoot.
 */

require_once __DIR__ . '/fut_elencos.php';

/**
 * As âncoras de preço por OVR, em milhões.
 *
 * ESTES NÚMEROS SÃO A RÉGUA DO JOGO INTEIRO, e foram escolhidos pra que o
 * mercado brasileiro faça sentido: um titular de Série A (78) custa uns 12
 * milhões, uma estrela (88) passa de 80, e um jogador de Série C (55) sai por
 * quase nada. Entre as âncoras o valor cresce em exponencial, e não em linha
 * reta — a diferença entre 85 e 90 tem que ser muito maior que entre 60 e 65,
 * senão comprar craque fica barato demais e o jogo acaba na primeira janela.
 */
const FUT_VALOR_ANCORAS = [
    50 => 0.3,   55 => 0.8,   60 => 2,     65 => 5,
    70 => 9,     75 => 16,    78 => 25,    80 => 34,
    83 => 52,    85 => 70,    88 => 110,   90 => 150,
    93 => 230,   95 => 300,   99 => 450,
];

/**
 * O multiplicador por idade.
 *
 * O PICO NÃO É O PICO DE FUTEBOL, é o de MERCADO — que vem antes. Um jogador
 * de 23 anos com 80 de OVR vale mais que um de 29 com os mesmos 80, porque
 * quem compra está comprando os anos que vêm pela frente. Depois dos 32 o
 * valor despenca, e é isso que faz "vender a tempo" ser uma decisão de
 * verdade em vez de detalhe.
 */
const FUT_VALOR_IDADE = [
    16 => 1.15, 17 => 1.25, 18 => 1.35, 19 => 1.45, 20 => 1.50,
    21 => 1.50, 22 => 1.48, 23 => 1.45, 24 => 1.40, 25 => 1.32,
    26 => 1.22, 27 => 1.12, 28 => 1.00, 29 => 0.88, 30 => 0.74,
    31 => 0.60, 32 => 0.46, 33 => 0.34, 34 => 0.24, 35 => 0.16,
    36 => 0.11, 37 => 0.07, 38 => 0.05, 39 => 0.03,
];

/** Quanto do valor de mercado o jogador ganha por ano, em salário. */
const FUT_SALARIO_PCT = 0.11;

/** Piso e teto de salário anual, em milhões. */
const FUT_SALARIO_MINIMO = 0.03;   // clube de estadual paga muito pouco: 25 x 0,03 = 0,75 de folha
const FUT_SALARIO_MAXIMO = 40.0;

/**
 * O valor de mercado de um jogador, em milhões.
 *
 * Interpola entre as âncoras em escala logarítmica: entre 80 (34) e 83 (52) o
 * 81 e o 82 saem por 39 e 45, mantendo o crescimento suave. Interpolar em
 * linha reta criaria degraus visíveis justamente na faixa onde o jogador passa
 * mais tempo negociando.
 */
function futValorDeMercado(int $ovr, int $idade): float
{
    $ovr = max(30, min(99, $ovr));

    $chaves = array_keys(FUT_VALOR_ANCORAS);
    $menor = $chaves[0];
    $maior = end($chaves);

    if ($ovr <= $menor) {
        // Abaixo da primeira âncora o valor cai rápido, mas nunca chega a zero:
        // jogador de graça quebraria a lógica de proposta lá embaixo.
        $base = FUT_VALOR_ANCORAS[$menor] * pow(0.88, $menor - $ovr);
    } elseif ($ovr >= $maior) {
        $base = FUT_VALOR_ANCORAS[$maior];
    } else {
        $abaixo = $menor;
        $acima = $maior;
        foreach ($chaves as $k) {
            if ($k <= $ovr) $abaixo = $k;
            if ($k >= $ovr) { $acima = $k; break; }
        }
        if ($abaixo === $acima) {
            $base = FUT_VALOR_ANCORAS[$abaixo];
        } else {
            // Interpolação no log: o crescimento entre âncoras é proporcional,
            // não aditivo.
            $t = ($ovr - $abaixo) / ($acima - $abaixo);
            $base = exp(
                log(FUT_VALOR_ANCORAS[$abaixo]) * (1 - $t) +
                log(FUT_VALOR_ANCORAS[$acima]) * $t
            );
        }
    }

    $fator = FUT_VALOR_IDADE[$idade] ?? ($idade < 16 ? 1.15 : 0.02);
    return round($base * $fator, 2);
}

/** O salário anual de um jogador, em milhões. */
function futSalarioDe(int $ovr, int $idade): float
{
    $valor = futValorDeMercado($ovr, $idade);
    /* O salário sai do valor, mas ACHATADO pela raiz: se fosse uma % direta, o
       craque de 300 milhões custaria 33 por ano e nenhum clube do jogo poderia
       pagá-lo — nem o que acabou de comprá-lo. A raiz aproxima os extremos, que
       é o que acontece de verdade: o salário varia muito menos que o preço. */
    $bruto = FUT_SALARIO_PCT * pow($valor, 0.78);
    return round(max(FUT_SALARIO_MINIMO, min(FUT_SALARIO_MAXIMO, $bruto)), 2);
}

/**
 * Quanto a divisão multiplica o dinheiro do clube.
 *
 * A queda de uma divisão pra outra é BRUTAL de propósito: cair da Série A pra
 * B corta a receita a um terço, e é isso que faz o rebaixamento doer de
 * verdade no jogo, em vez de ser só uma tabela diferente no ano seguinte.
 */
const FUT_MULT_DIVISAO = ['BR1' => 1.0, 'BR2' => 0.32, 'BR3' => 0.11];
const FUT_MULT_SEM_DIVISAO = 0.15;   // sem isto a receita nao cobria nem a folha minima

/**
 * A RECEITA ANUAL do clube, em milhões: bilheteria, patrocínio e cotas.
 *
 * É a peça que faltava na primeira versão da economia, e a falta dela
 * quebrava tudo: só existia um caixa inicial, então o Palmeiras começava com
 * 114 e tinha 108 de folha — gastava o ano inteiro de dinheiro em salários e
 * nunca mais comprava ninguém. Com receita, o clube ganha por temporada,
 * paga a folha e o que sobra é o que ele tem pra investir.
 *
 * A calibragem é pra que um grande consiga comprar um reforço do próprio
 * nível por ano, e um clube médio consiga um do nível dele. Se sobrasse muito
 * mais, o jogador montaria o elenco dos sonhos na segunda temporada.
 */
function futReceitaAnual(int $forca, string $div): float
{
    $mult = FUT_MULT_DIVISAO[$div] ?? FUT_MULT_SEM_DIVISAO;
    // A força entra ao cubo pela mesma razão que no valor: separa de verdade.
    $base = pow(max(30, $forca) / 70, 3.4) * 100;
    return round($base * $mult, 2);
}

/**
 * O CAIXA de um clube no começo da temporada, em milhões.
 *
 * É o que ele tem no banco hoje, não o que fatura no ano — por isso é uma
 * fração da receita. Começar com a receita inteira em caixa daria ao jogador
 * uma janela de transferências absurda logo na estreia.
 */
function futCaixaInicial(int $forca, string $div): float
{
    return round(futReceitaAnual($forca, $div) * 0.35, 2);
}

/** A folha salarial anual de um elenco, em milhões. */
function futFolhaDoElenco(array $elenco): float
{
    $t = 0.0;
    foreach ($elenco as $j) $t += futSalarioDe((int)$j['ovr'], (int)$j['idade']);
    return round($t, 2);
}

/**
 * O que sobra no ano: receita menos folha. Negativo é clube no vermelho.
 *
 * Premiação de competição entra por fora (ver fut_carreira.php) — aqui é só o
 * que o clube ganha e gasta só por existir.
 */
function futSaldoAnual(int $forca, string $div, array $elenco): float
{
    return round(futReceitaAnual($forca, $div) - futFolhaDoElenco($elenco), 2);
}

/**
 * QUANTO O CLUBE PEDE por um jogador dele.
 *
 * Sempre acima do valor de mercado, e mais caro quanto mais importante ele for
 * pro elenco: o craque tem sobretaxa, o reserva sai perto do preço de tabela.
 * É o que impede o jogador de montar um time de estrelas comprando todo mundo
 * pelo valor cheio — e é o que dá alguma graça a garimpar no banco dos outros.
 *
 * @param int $posto 1 = melhor do elenco; quanto maior, mais dispensável
 */
function futPrecoPedido(float $valor, int $posto): float
{
    if ($posto <= 1)      $mult = 2.10;   // o craque: o clube não quer vender
    elseif ($posto <= 3)  $mult = 1.75;
    elseif ($posto <= 6)  $mult = 1.45;
    elseif ($posto <= 11) $mult = 1.25;
    else                  $mult = 1.05;   // reserva: sai quase pelo preço

    return round($valor * $mult, 2);
}

/**
 * O posto de um jogador dentro do elenco (1 = melhor), por OVR.
 *
 * @return array nome => posto
 */
function futPostosDoElenco(array $elenco): array
{
    $ordem = $elenco;
    usort($ordem, fn($a, $b) => $b['ovr'] <=> $a['ovr']);
    $postos = [];
    foreach ($ordem as $i => $j) $postos[$j['nome']] = $i + 1;
    return $postos;
}

/**
 * O clube aceita a proposta?
 *
 * Três coisas decidem: se o dinheiro cobre o pedido, se o elenco pode perder o
 * jogador, e um empurrãozinho pra proposta bem acima do pedido. Um clube com
 * 14 jogadores não vende titular por nada — senão o jogador esvaziaria os
 * rivais e a liga viraria um time só.
 *
 * @return array ['aceita'=>bool, 'motivo'=>string]
 */
function futAvaliarProposta(float $oferta, float $pedido, int $tamanhoElenco, int $posto): array
{
    if ($tamanhoElenco <= 16 && $posto <= 11) {
        return ['aceita' => false, 'motivo' => 'O elenco está curto demais pra perder um titular.'];
    }
    if ($oferta >= $pedido * 1.5) {
        return ['aceita' => true, 'motivo' => 'Proposta irrecusável.'];
    }
    if ($oferta >= $pedido) {
        return ['aceita' => true, 'motivo' => 'Proposta aceita.'];
    }
    if ($oferta >= $pedido * 0.9) {
        /* A FAIXA DE NEGOCIAÇÃO: entre 90% e 100% do pedido o clube às vezes
           cede. Sem ela, o pedido viraria um preço de etiqueta e toda proposta
           seria "exatamente o pedido" — o jogador só olharia o número e
           clicaria, sem decisão nenhuma. */
        $chance = ($oferta - $pedido * 0.9) / ($pedido * 0.1);   // 0 a 1
        if ((mt_rand() / mt_getrandmax()) < $chance * 0.6) {
            return ['aceita' => true, 'motivo' => 'Depois de conversar, o clube aceitou.'];
        }
        return ['aceita' => false, 'motivo' => 'O clube recusou, mas ficou perto. Tente um pouco mais.'];
    }
    return ['aceita' => false, 'motivo' => 'Muito abaixo do que o clube pede.'];
}

/**
 * O jogador aceita assinar com este clube?
 *
 * Ele compara o que ganha hoje com o que ganharia, e olha o tamanho do clube.
 * Um 88 não desce pra Série C nem por salário alto, e é isso que impede o time
 * pequeno de comprar craque só porque teve um ano de sorte no caixa.
 */
function futJogadorAceita(int $ovr, float $salarioOferecido, float $salarioAtual, int $forcaClube): array
{
    $salarioJusto = $salarioOferecido >= $salarioAtual * 0.95;

    /* O DEGRAU DE AMBIÇÃO: o jogador não joga muito abaixo do nível dele. A
       régua é o OVR menos 12 — um 88 não assina com clube de força 70, mas um
       78 assina com um de 68 se o salário compensar. */
    $nivelMinimo = $ovr - 12;
    if ($forcaClube < $nivelMinimo) {
        // Dinheiro convence, mas só até certo ponto: tem que ser bem mais.
        if ($salarioOferecido < $salarioAtual * 1.6) {
            return ['aceita' => false, 'motivo' => 'Ele não vê o clube como um passo à frente na carreira.'];
        }
    }
    if (!$salarioJusto) {
        return ['aceita' => false, 'motivo' => 'O salário oferecido está abaixo do que ele ganha hoje.'];
    }
    return ['aceita' => true, 'motivo' => 'Ele topou.'];
}

/**
 * AS PROPOSTAS QUE CHEGAM PELOS SEUS JOGADORES.
 *
 * Os outros clubes não ficam parados esperando você agir. A cada janela, os
 * que têm dinheiro olham pro seu elenco e sondam quem cabe no bolso deles — é
 * o que faz segurar um craque ser uma escolha (e custar), em vez de um estado
 * natural das coisas.
 *
 * @param array $meuElenco jogadores com nome, ovr, idade
 * @param array $clubes    os outros clubes, com nome, forca, caixa
 * @return array lista de ['jogador','clube','oferta','salario']
 */
function futPropostasRecebidas(array $meuElenco, array $clubes, int $quantas = 3): array
{
    if ($meuElenco === [] || $clubes === []) return [];

    $propostas = [];
    $tentativas = 0;
    while (count($propostas) < $quantas && $tentativas < $quantas * 8) {
        $tentativas++;
        $j = $meuElenco[array_rand($meuElenco)];
        $c = $clubes[array_rand($clubes)];

        $valor = futValorDeMercado((int)$j['ovr'], (int)$j['idade']);
        $caixa = (float)($c['caixa'] ?? futCaixaInicial((int)$c['forca'], $c['div'] ?? ''));

        // O clube só sonda quem ele consegue pagar e quem é melhor do que ele
        // já tem — ninguém compra reserva por preço de titular.
        if ($valor > $caixa * 0.8) continue;
        if ((int)$j['ovr'] < (int)$c['forca'] - 6) continue;

        // Já sondaram este jogador nesta janela? Uma proposta por vez.
        foreach ($propostas as $p) if ($p['jogador'] === $j['nome']) continue 2;

        $oferta = round($valor * (0.85 + (mt_rand(0, 60) / 100)), 2);   // 85% a 145%
        $propostas[] = [
            'jogador' => $j['nome'],
            'ovr'     => (int)$j['ovr'],
            'idade'   => (int)$j['idade'],
            'clube'   => $c['nome'],
            'oferta'  => $oferta,
            'valor'   => $valor,
            'salario' => futSalarioDe((int)$j['ovr'], (int)$j['idade']),
        ];
    }
    return $propostas;
}

/**
 * OS JOGADORES DISPONÍVEIS NO MERCADO.
 *
 * Varre os elencos dos outros clubes e devolve quem dá pra comprar, com preço
 * pedido. Não é "todo mundo do mundo": entram os que o clube toparia negociar
 * e que estão numa faixa de preço que o jogador pode sonhar em pagar, senão a
 * lista viraria um catálogo de 2.000 nomes que ninguém lê.
 *
 * AS SAÍDAS PRECISAM ENTRAR AQUI. O elenco de cada clube é gerado na hora a
 * partir do nome, sempre igual — então, sem descontar quem já foi negociado,
 * o jogador que você acabou de comprar continua na vitrine do clube antigo. A
 * compra até seria recusada depois (futCarreiraComprar confere), mas a lista
 * estaria mentindo, e oferecer o que não existe é pior que não oferecer.
 *
 * @param array $clubes lista de ['nome','forca','div']
 * @param array $saidas [clube => [nomes que já deixaram o clube]]
 */
function futMercadoDisponivel(array $clubes, float $meuCaixa, int $limite = 60, array $saidas = []): array
{
    $lista = [];
    foreach ($clubes as $c) {
        $elenco = futElencoDoClube($c['nome'], (int)$c['forca']);
        if (!empty($saidas[$c['nome']])) {
            $foram = $saidas[$c['nome']];
            $elenco = array_values(array_filter($elenco, fn($j) => !in_array($j['nome'], $foram, true)));
        }
        if ($elenco === []) continue;
        $postos = futPostosDoElenco($elenco);

        foreach ($elenco as $j) {
            $valor = futValorDeMercado((int)$j['ovr'], (int)$j['idade']);
            $posto = $postos[$j['nome']] ?? 25;
            $pedido = futPrecoPedido($valor, $posto);

            // Fora do alcance do caixa não entra na lista: mostrar o que não
            // dá pra comprar só faz o jogador rolar a tela à toa.
            if ($pedido > $meuCaixa * 2.5) continue;

            $lista[] = [
                'nome'    => $j['nome'],
                'pos'     => $j['pos'],
                'ovr'     => (int)$j['ovr'],
                'idade'   => (int)$j['idade'],
                'clube'   => $c['nome'],
                'valor'   => $valor,
                'pedido'  => $pedido,
                'salario' => futSalarioDe((int)$j['ovr'], (int)$j['idade']),
                'posto'   => $posto,
                'elenco'  => count($elenco),
                'forca_clube' => (int)$c['forca'],
            ];
        }
    }

    // Os melhores primeiro: é o que o jogador quer ver ao abrir o mercado.
    usort($lista, fn($a, $b) => $b['ovr'] <=> $a['ovr']);
    return array_slice($lista, 0, $limite);
}
