<?php
/**
 * ── FBA DRAFT: O FUT DRAFT DA LIGA ───────────────────────────────────
 *
 * Ideia do Marcos (07/10/2026), e a referência é o FUT Draft do FIFA: você
 * escolhe uma formação, e aí o jogo te oferece cinco cartas por vaga — você
 * fica com uma e a vaga seguinte abre. No fim tem um time de onze, uma
 * química, e uma partida simulada minuto a minuto pra ver no que deu.
 *
 * ── O QUE JÁ EXISTIA E FOI REAPROVEITADO ─────────────────────────────
 *
 * Quase tudo. A liga já tinha 321 elencos reais em games/data/elencos
 * (nome, posição, OVR, idade) e 498 clubes calibrados com liga, força e
 * escudo no catálogo do Copero. Então este arquivo não inventa jogador
 * nenhum: ele sorteia de gente que já estava lá, com o escudo e a liga que
 * já estavam lá. É o que faz o jogo nascer com o mundo inteiro em vez de uma
 * tabela vazia.
 *
 * ── A QUÍMICA É O JOGO ───────────────────────────────────────────────
 *
 * Sem química, escolher é só pegar o OVR maior — e aí não há escolha, há
 * aritmética. A química paga por clube e por liga em comum com os VIZINHOS
 * de posição, não com o time inteiro: é isso que faz valer a pena pegar um
 * lateral pior do mesmo clube do zagueiro, que é exatamente a decisão que o
 * FUT Draft cobra.
 *
 * E ela MEXE NA FORÇA de verdade (@see draftFutForcaDoTime). Química que só
 * pinta uma barrinha é enfeite; aqui o time sem liga nenhuma em comum entra
 * em campo mais fraco, e quem montou um bloco inteiro do mesmo campeonato
 * sente a diferença no placar.
 */

require_once __DIR__ . '/copero_clubes.php';
require_once __DIR__ . '/fut_elencos.php';
require_once __DIR__ . '/draftfut_lendas.php';
require_once __DIR__ . '/draftfut_cartas.php';

/** Quantas cartas o jogo oferece por vaga. Cinco, como no FUT. */
const DFUT_OPCOES = 5;

/** Quantos esquemas aparecem na abertura, sorteados de DFUT_FORMACOES. */
const DFUT_FORMACOES_NA_MESA = 5;

/** Quantas vagas um time tem, em campo e no banco. */
const DFUT_VAGAS  = 11;
const DFUT_BANCO  = 8;
const DFUT_TOTAL  = DFUT_VAGAS + DFUT_BANCO;

/**
 * A faixa de OVR do baralho inteiro. Pedido do Marcos: 75 a 92.
 *
 * Abaixo de 75 o draft vinha cheio de reserva de clube pequeno, e a escolha
 * entre cinco desconhecidos não é escolha. O piso corta 4.510 das 5.509
 * cartas e deixa 999 — de 20 ligas e 118 clubes, com pelo menos 91 cartas em
 * cada posição, que é de sobra pra dezenove vagas sem repetir ninguém.
 */
const DFUT_OVR_MIN = 75;

/**
 * A chance de uma das cinco cartas da vaga ser lenda.
 *
 * Lenda que sai toda vaga não é lenda. Com 12%, um draft de dezenove vagas
 * mostra duas ou três ao longo da sessão — o bastante pra valer a pena
 * olhar as cinco cartas toda vez, e pouco o bastante pra a dourada ainda
 * levantar a sobrancelha.
 */
const DFUT_CHANCE_LENDA = 12;

/**
 * O BANCO NÃO TEM POSIÇÃO — pedido do Marcos (08/10/2026): "no banco os
 * pacotes nao tem posicao certa, pode vir qualquer posicao e misturado, so no
 * time titular que é seguindo a posicao".
 *
 * Antes cada vaga do banco era de um setor (um goleiro, um zagueiro, um
 * lateral...), e isso tirava a graça do pacote: abrir a vaga 12 era saber de
 * antemão que vinha zagueiro. Agora as oito vagas sorteiam de TODO o baralho,
 * misturado, e quem decide pra que serve cada reserva é quem monta o time.
 *
 * A constante fica, vazia, porque é ela que define que o banco é livre — e
 * porque tem gente perguntando a posição de uma vaga de banco.
 * @see draftFutPosDaVaga
 */
const DFUT_BANCO_POS = [];

/**
 * ONDE CADA VAGA FICA NO GRAMADO, em % da largura e da altura.
 *
 * O gol do time fica embaixo, como no FUT: o goleiro em y=88 e o ataque em
 * y=12. As coordenadas são da formação, não da posição — duas formações
 * podem ter um volante e ele não fica no mesmo lugar nas duas.
 */
const DFUT_CAMPO = [
    '4-3-3'   => [[50,88],[14,70],[36,72],[64,72],[86,70],[50,52],[28,42],[72,42],[16,18],[50,12],[84,18]],
    '4-3-2-1' => [[50,88],[14,70],[36,72],[64,72],[86,70],[26,48],[50,46],[74,48],[30,24],[70,24],[50,10]],
    '4-4-2'   => [[50,88],[14,70],[36,72],[64,72],[86,70],[14,44],[38,46],[62,46],[86,44],[36,16],[64,16]],
    '3-5-2'   => [[50,88],[26,72],[50,70],[74,72],[10,50],[30,52],[50,36],[70,52],[90,50],[36,14],[64,14]],
    '5-2-1-2' => [[50,88],[10,64],[30,72],[50,70],[70,72],[90,64],[36,50],[64,50],[50,32],[36,12],[64,12]],
    '4-2-3-1' => [[50,88],[14,70],[36,72],[64,72],[86,70],[36,52],[64,52],[14,30],[50,30],[86,30],[50,10]],
    '4-1-4-1' => [[50,88],[14,70],[36,72],[64,72],[86,70],[50,56],[14,38],[38,36],[62,36],[86,38],[50,12]],
    '4-4-1-1' => [[50,88],[14,70],[36,72],[64,72],[86,70],[14,46],[38,48],[62,48],[86,46],[50,28],[50,10]],
    '3-4-3'   => [[50,88],[26,72],[50,70],[74,72],[10,48],[38,50],[62,50],[90,48],[16,18],[50,12],[84,18]],
    '3-4-1-2' => [[50,88],[26,72],[50,70],[74,72],[10,50],[38,52],[62,52],[90,50],[50,32],[36,12],[64,12]],
    '5-3-2'   => [[50,88],[10,64],[30,72],[50,70],[70,72],[90,64],[50,50],[28,40],[72,40],[36,14],[64,14]],
    '5-4-1'   => [[50,88],[10,64],[30,72],[50,70],[70,72],[90,64],[14,42],[38,44],[62,44],[86,42],[50,12]],
    '4-2-2-2' => [[50,88],[14,70],[36,72],[64,72],[86,70],[36,54],[64,54],[18,32],[82,32],[38,12],[62,12]],
];

/** O nome curto pra caber na carta do campo: "C. Ronaldo" em vez do inteiro. */
function draftFutNomeCurto(string $nome): string
{
    $p = preg_split('/\s+/', trim($nome));
    if (count($p) < 2) return $nome;

    /* A PARTÍCULA VAI JUNTO COM O SOBRENOME. Pegando só a última palavra,
       "Kevin De Bruyne" virava "K. Bruyne" — que não é como ninguém o
       chama. Quando a penúltima é partícula, ela entra no sobrenome. */
    $particulas = ['de', 'da', 'do', 'dos', 'das', 'van', 'von', 'del', 'della',
                   'di', 'du', 'el', 'al', 'la', 'le', 'ten', 'ter', 'mc', 'san'];
    $ultimo = array_pop($p);
    while ($p && in_array(mb_strtolower(end($p)), $particulas, true)) {
        $ultimo = array_pop($p) . ' ' . $ultimo;
    }
    if (!$p) return $ultimo;

    // Sobrenome longo já identifica sozinho; curto ganha a inicial na frente.
    return mb_strlen($ultimo) > 11 ? $ultimo : mb_substr($p[0], 0, 1) . '. ' . $ultimo;
}

/**
 * O NOME DE CAMISA — o que cabe na carta pequena do campinho.
 *
 * `draftFutNomeCurto` devolve "V. van Dijk", que em 56 pixels sai cortado:
 * "V. VAN DI...". Aqui é só o sobrenome, com a partícula junto — o nome das
 * costas, que é também o que o FIFA escreve na carta.
 *
 * A LISTA DE EXCEÇÕES É CURTA E DECLARADA: cinco nomes em que o sobrenome não
 * é como a pessoa é chamada. Ninguém conhece Ronaldinho por "Gaúcho" nem Xavi
 * por "Hernández", e errar isso numa carta de lenda é errar na carta que mais
 * aparece. Fora desses cinco, a regra do sobrenome acerta.
 */
const DFUT_NOME_CAMISA = [
    'Ronaldo Fenômeno'      => 'Ronaldo',
    'Ronaldinho Gaúcho'     => 'Ronaldinho',
    'Carlos Alberto Torres' => 'C. Alberto',
    'Roberto Carlos'        => 'R. Carlos',
    'Xavi Hernández'        => 'Xavi',
];

function draftFutNomeCamisa(string $nome): string
{
    if (isset(DFUT_NOME_CAMISA[$nome])) return DFUT_NOME_CAMISA[$nome];

    $curto = draftFutNomeCurto($nome);
    // Tira a inicial que o nome curto põe na frente: "V. van Dijk" => "van Dijk".
    return preg_replace('/^\p{L}\.\s+/u', '', $curto);
}

/**
 * A posição que uma vaga pede. VAZIO no banco, que aceita qualquer uma.
 *
 * Quem sorteia lê isto: string vazia quer dizer "tanto faz, sorteie do
 * baralho inteiro" (@see draftFutOpcoes). A química não passa por aqui pro
 * banco, porque só os onze de campo pontuam.
 */
function draftFutPosDaVaga(string $formacao, int $i): string
{
    if ($i < DFUT_VAGAS) return DFUT_FORMACOES[$formacao][$i][1] ?? 'MEI';
    return DFUT_BANCO_POS[$i - DFUT_VAGAS] ?? '';
}

/** O rótulo de qualquer vaga. No banco vazio não há o que prometer. */
function draftFutRotuloDaVaga(string $formacao, int $i): string
{
    if ($i < DFUT_VAGAS) return DFUT_FORMACOES[$formacao][$i][0] ?? '';
    return DFUT_BANCO_POS[$i - DFUT_VAGAS] ?? 'LIVRE';
}

/**
 * AS FORMAÇÕES, com as vagas na ordem em que são preenchidas.
 *
 * Cada vaga é [rótulo, posição natural]. A posição natural é a do nosso
 * elenco (GOL/ZAG/LAT/VOL/MEI/PON/ATA) — é ela que diz quem pode ser
 * sorteado pra vaga e quem está fora de posição.
 *
 * A ordem é de trás pra frente, de propósito: começar pelo goleiro e subir
 * deixa a química se construir num bloco que já existe, em vez de o jogador
 * escolher um atacante solto e torcer pra o resto encaixar.
 */
const DFUT_FORMACOES = [
    '4-3-3' => [
        ['GOL', 'GOL'], ['LE', 'LAT'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'], ['LD', 'LAT'],
        ['VOL', 'VOL'], ['MEI', 'MEI'], ['MEI', 'MEI'],
        ['PE', 'PON'], ['CA', 'ATA'], ['PD', 'PON'],
    ],
    '4-3-2-1' => [
        ['GOL', 'GOL'], ['LE', 'LAT'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'], ['LD', 'LAT'],
        ['MEI', 'MEI'], ['MEI', 'MEI'], ['MEI', 'MEI'],
        ['SA', 'ATA'], ['SA', 'ATA'], ['CA', 'ATA'],
    ],
    '4-4-2' => [
        ['GOL', 'GOL'], ['LE', 'LAT'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'], ['LD', 'LAT'],
        ['ME', 'PON'], ['VOL', 'VOL'], ['MEI', 'MEI'], ['MD', 'PON'],
        ['CA', 'ATA'], ['CA', 'ATA'],
    ],
    '3-5-2' => [
        ['GOL', 'GOL'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'],
        ['ALE', 'LAT'], ['VOL', 'VOL'], ['MEI', 'MEI'], ['MEI', 'MEI'], ['ALD', 'LAT'],
        ['CA', 'ATA'], ['CA', 'ATA'],
    ],
    '5-2-1-2' => [
        ['GOL', 'GOL'], ['ALE', 'LAT'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'], ['ALD', 'LAT'],
        ['VOL', 'VOL'], ['VOL', 'VOL'], ['MEI', 'MEI'],
        ['CA', 'ATA'], ['CA', 'ATA'],
    ],

    /* ── DAQUI PRA BAIXO ENTRARAM EM 08/10/2026 ──────────────────────
       Pedido do Marcos: "coloca pra vir esquemas taticos aleatorios, pode ter
       varios, mas vem 5 ali no começo". Com cinco fixas, todo draft começava
       igual; com treze no baralho e cinco sorteadas, a abertura também é uma
       mão. Cada esquema novo precisa da linha dele em DFUT_CAMPO, senão o
       campinho nasce sem coordenada — e é por isso que há um teste que
       confere as duas listas uma contra a outra. */
    '4-2-3-1' => [
        ['GOL', 'GOL'], ['LE', 'LAT'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'], ['LD', 'LAT'],
        ['VOL', 'VOL'], ['VOL', 'VOL'],
        ['ME', 'PON'], ['MEI', 'MEI'], ['MD', 'PON'],
        ['CA', 'ATA'],
    ],
    '4-1-4-1' => [
        ['GOL', 'GOL'], ['LE', 'LAT'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'], ['LD', 'LAT'],
        ['VOL', 'VOL'],
        ['ME', 'PON'], ['MEI', 'MEI'], ['MEI', 'MEI'], ['MD', 'PON'],
        ['CA', 'ATA'],
    ],
    '4-4-1-1' => [
        ['GOL', 'GOL'], ['LE', 'LAT'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'], ['LD', 'LAT'],
        ['ME', 'PON'], ['VOL', 'VOL'], ['MEI', 'MEI'], ['MD', 'PON'],
        ['SA', 'ATA'], ['CA', 'ATA'],
    ],
    '3-4-3' => [
        ['GOL', 'GOL'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'],
        ['ALE', 'LAT'], ['VOL', 'VOL'], ['MEI', 'MEI'], ['ALD', 'LAT'],
        ['PE', 'PON'], ['CA', 'ATA'], ['PD', 'PON'],
    ],
    '3-4-1-2' => [
        ['GOL', 'GOL'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'],
        ['ALE', 'LAT'], ['VOL', 'VOL'], ['VOL', 'VOL'], ['ALD', 'LAT'],
        ['MEI', 'MEI'], ['CA', 'ATA'], ['CA', 'ATA'],
    ],
    '5-3-2' => [
        ['GOL', 'GOL'], ['ALE', 'LAT'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'], ['ALD', 'LAT'],
        ['VOL', 'VOL'], ['MEI', 'MEI'], ['MEI', 'MEI'],
        ['CA', 'ATA'], ['CA', 'ATA'],
    ],
    '5-4-1' => [
        ['GOL', 'GOL'], ['ALE', 'LAT'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'], ['ALD', 'LAT'],
        ['ME', 'PON'], ['VOL', 'VOL'], ['MEI', 'MEI'], ['MD', 'PON'],
        ['CA', 'ATA'],
    ],
    '4-2-2-2' => [
        ['GOL', 'GOL'], ['LE', 'LAT'], ['ZAG', 'ZAG'], ['ZAG', 'ZAG'], ['LD', 'LAT'],
        ['VOL', 'VOL'], ['VOL', 'VOL'],
        ['ME', 'PON'], ['MD', 'PON'],
        ['CA', 'ATA'], ['CA', 'ATA'],
    ],
];

/**
 * QUEM SERVE PRA CADA VAGA, e quanto custa jogar fora de posição.
 *
 * Um ponta pode fazer o lado do meio-campo e um volante cobre a zaga num
 * aperto — o que não pode é o goleiro virar atacante. O número é o desconto
 * no OVR quando a carta não é da posição natural, e ele existe pra a escolha
 * ter preço: encaixar o craque fora da posição deve ser uma decisão, não um
 * almoço grátis.
 */
const DFUT_COBRE = [
    'GOL' => ['GOL' => 0],
    'ZAG' => ['ZAG' => 0, 'LAT' => 4, 'VOL' => 5],
    'LAT' => ['LAT' => 0, 'ZAG' => 4, 'PON' => 5, 'VOL' => 6],
    'VOL' => ['VOL' => 0, 'MEI' => 3, 'ZAG' => 5],
    'MEI' => ['MEI' => 0, 'VOL' => 3, 'PON' => 4, 'ATA' => 6],
    'PON' => ['PON' => 0, 'MEI' => 4, 'ATA' => 4, 'LAT' => 6],
    'ATA' => ['ATA' => 0, 'PON' => 4, 'MEI' => 6],
];

/* ═══════════════════════ AS CARTAS ══════════════════════════════════ */

/**
 * Todas as cartas disponíveis: cada jogador de cada elenco real, com o clube
 * e a liga dele dentro.
 *
 * É CARO E VALE A PENA CACHEAR: são 321 arquivos de elenco, e o draft pede
 * carta dezenas de vezes numa sessão. Monta uma vez por request e guarda.
 */
function draftFutBaralho(): array
{
    static $cartas = null;
    if ($cartas !== null) return $cartas;

    /* SÓ ELENCO REAL ENTRA NO BARALHO, e isso não é preciosismo.
       `futElencoDoClube` cai num elenco GERADO quando o clube não tem lista
       própria — nomes inventados, com OVR tirado da força do clube. Como os
       clubes mais fortes geram os OVRs mais altos, o topo do baralho virava
       gente que não existe: na primeira rodada saíram "Davi Siqueira 93" e
       "Tatá 91", do Al Hilal, acima de qualquer craque de verdade. Num jogo
       de cartinhas isso é o fim da graça — a carta tem que ser reconhecida.
       São 321 clubes com elenco real, de sobra pra um draft de onze. */
    $extra  = draftFutExtras();
    $trocas = draftFutTransferencias();
    $locais = draftFutFotosLocais();

    /* Liga e escudo por clube, pra a carta de quem trocou nascer já com os do
       clube novo. Sai do mesmo catálogo, então não há segunda fonte. */
    $doClube = DFUT_CLUBE_EXTRA;
    foreach (COPERO_CLUBES as [$n, $l, $f, $e]) $doClube[$n] = ['liga' => $l, 'escudo' => $e];

    $cartas = []; $jaTem = [];
    foreach (COPERO_CLUBES as [$nome, $liga, $forca, $escudo]) {
        if (!is_file(__DIR__ . '/../data/elencos/' . futSlugDoClube($nome) . '.php')) continue;
        foreach (futElencoDoClube($nome, (int)$forca) as $j) {
            $pos = strtoupper(trim((string)($j['pos'] ?? '')));
            if (!isset(DFUT_COBRE[$pos])) continue;
            $nm = (string)$j['nome'];

            /* QUEM SAIU DO BARALHO SAI AQUI, antes de virar carta — pela
               chave do clube de ORIGEM, que é a do elenco. @see DFUT_FORA */
            if (isset(DFUT_FORA[$nome . '|' . $nm])) continue;

            $ex = $extra[$nome][$nm] ?? null;

            /* O OVR é calculado com a liga de ORIGEM, antes da troca: é ela
               que diz como aquele elenco foi gerado. @see draftFutTransferencias */
            $ovr = draftFutOvrAjustado((int)$j['ovr'], $liga);

            $clube = $nome; $lg = $liga; $esc = $escudo;
            $novo = (string)($trocas[$nome . '|' . $nm] ?? '');
            if ($novo !== '' && isset($doClube[$novo])) {
                $clube = $novo;
                $lg    = $doClube[$novo]['liga'];
                $esc   = $doClube[$novo]['escudo'];
            }

            /* O OVR À MÃO VEM DEPOIS DA TROCA, e por isso aceita as duas
               chaves: quem escreve a linha pensa no clube de hoje, e o elenco
               guarda o de ontem. @see DFUT_OVR_MAO */
            $ovr = (int)(DFUT_OVR_MAO[$nome . '|' . $nm]
                      ?? DFUT_OVR_MAO[$clube . '|' . $nm]
                      ?? $ovr);

            /* O MESMO JOGADOR SÓ ENTRA UMA VEZ. Se ele trocou pra um clube que
               também o lista no elenco, as duas linhas virariam duas cartas do
               mesmo homem — e o draft deixaria escalar os dois. */
            $id = $clube . '|' . $nm;
            if (isset($jaTem[$id])) continue;
            $jaTem[$id] = true;

            $cartas[] = [
                'nome'   => $nm,
                'pos'    => $pos,
                'ovr'    => $ovr,
                'idade'  => (int)($j['idade'] ?? 25),
                'clube'  => $clube,
                'liga'   => $lg,
                'escudo' => $esc,
                'nac'    => draftFutNacao($nome, $nm, $ex),
                /* A FOTO DA FONTE VEM PRIMEIRO; a que entrou à mão cobre o
                   buraco. A chave da local é CLUBE|NOME, e procura-se pelos
                   DOIS clubes — o de origem e o de destino —, porque o
                   arquivo pode ter sido gerado antes ou depois da
                   transferência. Procurar só por um fez o Vitinho ficar sem
                   cara: a foto dele foi gravada como "Al Ettifaq|Vitinho" e
                   a busca perguntava por "Botafogo|Vitinho". */
                'foto'   => (string)($ex['foto'] ?? '')
                            ?: (string)($locais[$nome . '|' . $nm] ?? $locais[$clube . '|' . $nm] ?? ''),
            ];
        }
    }
    return $cartas;
}

/**
 * Cinco cartas pra uma vaga, de dentro de uma faixa de OVR.
 *
 * A FAIXA É O QUE DÁ RITMO AO DRAFT. Sorteando do baralho inteiro, as cinco
 * cartas seriam quase sempre reservas de clube pequeno — a maioria do baralho
 * é isso — e o draft inteiro viraria um desfile de gente que ninguém conhece.
 * Com a faixa, o jogo oferece cinco cartas comparáveis entre si, e a escolha
 * passa a ser sobre química e posição, não sobre quem é o menos ruim.
 *
 * @param array $usados nomes que já estão no time; ninguém repete
 */
function draftFutOpcoes(string $posicaoVaga, array $usados, int $ovrMin, int $ovrMax): array
{
    /* ── ABRIU CA, VEM CENTROAVANTE ──────────────────────────────────
       Pedido do Marcos (07/10/2026): "se eu abri a posicao de CA, vem
       jogadores que fazem CA". Antes a vaga aceitava a vizinhança toda
       (DFUT_COBRE), e abrir um atacante devolvia meio-campistas — que já
       nasciam fora de posição e com química zero. A vaga agora sorteia só
       quem joga ali; DFUT_COBRE continua valendo pra quando o jogador é
       REMANEJADO depois, que é onde a penalidade faz sentido.

       NO BANCO É O CONTRÁRIO: $posicaoVaga vem VAZIA e o sorteio pega o
       baralho inteiro, misturado (pedido do Marcos, 08/10/2026). Vaga de
       banco com setor marcado entregava a surpresa antes de abrir o pacote —
       dava pra saber que a vaga 12 era zagueiro. */
    $fora  = array_flip($usados);
    $livre = $posicaoVaga === '';

    $ovrMin = max(DFUT_OVR_MIN, $ovrMin);

    $elegiveis = [];
    foreach (draftFutBaralho() as $c) {
        if (!$livre && $c['pos'] !== $posicaoVaga) continue;
        if (isset($fora[$c['nome']])) continue;
        if ($c['ovr'] < $ovrMin || $c['ovr'] > $ovrMax) continue;
        $elegiveis[] = $c;
    }
    /* Faixa vazia (acontece nas pontas, com posição rara): alarga o OVR até
       achar gente — nunca a posição —, em vez de devolver menos de cinco
       cartas e travar a vaga. */
    if (count($elegiveis) < DFUT_OPCOES) {
        foreach (draftFutBaralho() as $c) {
            if (!$livre && $c['pos'] !== $posicaoVaga) continue;
            if (isset($fora[$c['nome']])) continue;
            if ($c['ovr'] < DFUT_OVR_MIN) continue;
            $elegiveis[] = $c;
        }
    }
    if (!$elegiveis) return [];

    shuffle($elegiveis);
    $escolhidas = array_slice($elegiveis, 0, DFUT_OPCOES);

    /* A LENDA ENTRA NO LUGAR DA PIOR CARTA, e não como sexta opção: a vaga
       continua sendo uma escolha entre cinco, e a dourada chega ocupando o
       espaço de alguém — que é o que faz ela parecer sorte, e não bônus. */
    if (mt_rand(1, 100) <= DFUT_CHANCE_LENDA) {
        $lendas = [];
        foreach (draftFutLendas() as $l) {
            if (!$livre && $l['pos'] !== $posicaoVaga) continue;
            if (isset($fora[$l['nome']])) continue;
            $lendas[] = $l;
        }
        if ($lendas) {
            usort($escolhidas, fn($a, $b) => $a['ovr'] <=> $b['ovr']);
            $escolhidas[0] = $lendas[mt_rand(0, count($lendas) - 1)];
        }
    }

    /* ── AS COLEÇÕES ─────────────────────────────────────────────────
       São mais comuns que a lenda e mexem menos: pegam UMA das cartas comuns
       que sobraram e devolvem ela com outro desenho e um bônus de OVR. Só uma
       por sorteio, e nunca por cima de Ícone ou Herói — carta especial não
       vira outra carta especial.

       Ordem importa: o Futuro é mais raro, então tenta primeiro. Se tentasse
       depois, o Time da Semana já teria ocupado a vaga quase sempre. */
    $comuns = [];
    foreach ($escolhidas as $k => $c) {
        if (draftFutQuimicaDoTipo($c) === 'normal' && empty($c['tipo'])) $comuns[] = $k;
    }
    $promo = null;
    if ($comuns && mt_rand(1, 100) <= DFUT_TIPOS['futuro']['peso']) {
        $jovens = array_values(array_filter($comuns,
            fn($k) => (int)($escolhidas[$k]['idade'] ?? 99) <= DFUT_FUTURO_IDADE));
        if ($jovens) $promo = [$jovens[mt_rand(0, count($jovens) - 1)], 'futuro', DFUT_FUTURO_BONUS];
    }
    if ($promo === null && $comuns && mt_rand(1, 100) <= DFUT_TIPOS['totw']['peso']) {
        $promo = [$comuns[mt_rand(0, count($comuns) - 1)], 'totw', DFUT_TOTW_BONUS];
    }
    if ($promo !== null) {
        [$k, $tipo, $bonus] = $promo;
        $escolhidas[$k]['tipo'] = $tipo;
        $escolhidas[$k]['ovr'] += $bonus;
    }

    usort($escolhidas, fn($a, $b) => $b['ovr'] <=> $a['ovr']);
    return $escolhidas;
}

/**
 * A faixa de OVR da vaga N.
 *
 * Abre alto e vai descendo de leve: as primeiras vagas trazem nomes grandes,
 * e o fim do draft é onde se garimpa. É o mesmo arco do FUT, e serve pra a
 * sessão ter começo, meio e fim em vez de onze sorteios iguais.
 */
function draftFutFaixa(int $vaga): array
{
    /* Os onze de campo abrem em 89 e descem até ~80; o banco vem abaixo, que
       é o que faz o reserva parecer reserva. O piso nunca passa de
       DFUT_OVR_MIN, então nenhuma carta do draft é pior que 75. */
    $topo = $vaga < DFUT_VAGAS
        ? 89 - (int)floor($vaga * 0.8)
        : 82 - (int)floor(($vaga - DFUT_VAGAS) * 0.5);
    return [max(DFUT_OVR_MIN, $topo - 8), max(DFUT_OVR_MIN + 4, $topo)];
}

/* ═══════════════════════ A QUÍMICA ══════════════════════════════════ */

/**
 * ── A QUÍMICA DO EA FC 25, QUE É A QUE O MARCOS PEDIU ────────────────
 *
 * A primeira versão contava vizinhos de posição — quem jogava ao lado de
 * quem. Isso é a química do FIFA 22 pra trás; o EA FC acabou com os links
 * posicionais. Pesquisado e confirmado: hoje o que vale é QUANTOS no time
 * inteiro dividem clube, liga ou nação, não onde eles estão.
 *
 * Cada jogador vale de 0 a 3. O time vale de 0 a 33.
 *
 *   clube ....... 2 jogadores = 1 ponto · 4 = 2 · 7 = 3
 *   nação ....... 2 jogadores = 1 ponto · 5 = 2 · 8 = 3
 *   liga ........ 3 jogadores = 1 ponto · 5 = 2 · 8 = 3
 *
 * Os três somam, e o total de cada jogador para em 3.
 *
 * ── FORA DE POSIÇÃO ZERA, E NÃO CONTA PRA NINGUÉM ────────────────────
 *
 * Quem não está na posição preferida fica com 0 e SAI DA CONTAGEM dos
 * outros — é assim no jogo, e é o que dá peso à decisão de encaixar o craque
 * fora do lugar: não é só ele que perde, é o time que deixa de ter aquele
 * elo. Por isso a contagem é feita só sobre quem está em posição.
 *
 * ── LENDA TEM 3 SEMPRE, E PUXA OS OUTROS ─────────────────────────────
 *
 * Como os Ícones: na posição certa, a lenda tem química cheia independente
 * do resto, conta DOIS para o limiar de nação e um para toda liga. É o que
 * faz valer a pena montar em volta dela em vez de deixá-la isolada.
 */

/** Os limiares, na ordem [para 1 ponto, para 2, para 3]. */
const DFUT_LIMIAR_CLUBE = [2, 4, 7];
const DFUT_LIMIAR_NACAO = [2, 5, 8];
const DFUT_LIMIAR_LIGA  = [3, 5, 8];

/** Quantos pontos uma contagem rende, dados os limiares. */
function draftFutPontos(int $quantos, array $limiar): int
{
    if ($quantos >= $limiar[2]) return 3;
    if ($quantos >= $limiar[1]) return 2;
    if ($quantos >= $limiar[0]) return 1;
    return 0;
}

/** O país de uma liga ('BR1' => 'BRA'), pelo catálogo do Copero. */
function draftFutPais(string $liga): string
{
    return (string)(COPERO_LIGAS[$liga][0] ?? '');
}

/**
 * A química de um time: 0 a 33 no total, 0 a 3 por jogador.
 *
 * @return array ['total'=>int, 'jogadores'=>[i=>0..3], 'detalhe'=>[i=>[...]]]
 */
function draftFutQuimica(string $formacao, array $time): array
{
    /* ── 1. Quem está em posição? Só esses contam e só esses pontuam. ── */
    $validos = [];
    for ($i = 0; $i < DFUT_VAGAS; $i++) {
        $c = $time[$i] ?? null;
        if (!$c) continue;
        if ($c['pos'] !== draftFutPosDaVaga($formacao, $i)) continue;
        $validos[$i] = $c;
    }

    /* ── 2. As contagens, e aqui ÍCONE E HERÓI SE SEPARAM ────────────
       É a regra do EA FC, não enfeite: o Ícone é global — conta DOIS pro
       limiar da nação dele e UM pra toda liga, costurando um time de ligas
       misturadas. O Herói é de um campeonato — UM pra nação e DOIS pra liga
       dele, premiando quem monta em volta daquela liga. */
    $porClube = []; $porNacao = []; $porLiga = [];
    $iconesLiga = 0;
    foreach ($validos as $c) {
        $kind = draftFutQuimicaDoTipo($c);

        if ($c['clube'] !== '') $porClube[$c['clube']] = ($porClube[$c['clube']] ?? 0) + 1;

        $nac = (string)($c['nac'] ?? '');
        if ($nac !== '') {
            $porNacao[$nac] = ($porNacao[$nac] ?? 0) + ($kind === 'icone' ? 2 : 1);
        }

        if ($kind === 'icone') {
            $iconesLiga++;                  // vale um pra TODA liga
        } elseif ($c['liga'] !== '') {
            $porLiga[$c['liga']] = ($porLiga[$c['liga']] ?? 0) + ($kind === 'heroi' ? 2 : 1);
        }
    }

    /* ── 3. Os pontos de cada um. ── */
    $pontos = []; $detalhe = [];
    for ($i = 0; $i < DFUT_VAGAS; $i++) {
        $c = $time[$i] ?? null;
        if (!$c) { $pontos[$i] = 0; continue; }

        if (!isset($validos[$i])) {
            // Fora de posição: zero, e já saiu das contagens lá em cima.
            $pontos[$i] = 0;
            $detalhe[$i] = ['fora' => true, 'clube' => 0, 'nacao' => 0, 'liga' => 0];
            continue;
        }
        /* ÍCONE E HERÓI TÊM QUÍMICA CHEIA na posição certa, independente do
           resto do time — é o que faz valer a pena montar em volta deles. */
        $kind = draftFutQuimicaDoTipo($c);
        if ($kind !== 'normal') {
            $pontos[$i] = 3;
            $detalhe[$i] = ['especial' => $kind, 'clube' => 0, 'nacao' => 0, 'liga' => 0];
            continue;
        }

        $nac = (string)($c['nac'] ?? '');
        $pc = draftFutPontos($porClube[$c['clube']] ?? 0, DFUT_LIMIAR_CLUBE);
        $pn = $nac === '' ? 0 : draftFutPontos($porNacao[$nac] ?? 0, DFUT_LIMIAR_NACAO);
        $pl = draftFutPontos(($porLiga[$c['liga']] ?? 0) + $iconesLiga, DFUT_LIMIAR_LIGA);

        $pontos[$i] = min(3, $pc + $pn + $pl);
        $detalhe[$i] = ['clube' => $pc, 'nacao' => $pn, 'liga' => $pl];
    }

    return ['total' => array_sum($pontos), 'jogadores' => $pontos, 'detalhe' => $detalhe];
}

/**
 * A QUÍMICA QUE O RESERVA TERIA SE SUBISSE.
 *
 * Pedido do Marcos (08/10/2026): "nos bancarios, coloca a quimica que eles
 * ficariam se entrassem no time titular".
 *
 * O banco continua sem pontuar — isso é regra do EA FC e não mudou. O que
 * muda é que dá pra ver o que a troca renderia ANTES de fazer. Sem isso a
 * escolha era às cegas: subia o reserva, olhava o número, descia de novo.
 *
 * É o MELHOR caso, e de propósito. Pra cada titular já escolhido a função
 * simula a troca de verdade — o reserva entra naquela vaga, o titular desce
 * pro banco — e guarda a maior química que o reserva alcança. Melhor caso
 * porque é exatamente a pergunta que a pessoa está fazendo ("vale a pena
 * subir este?"), e porque entrar fora de posição zera: quando não há lugar
 * pra ele no esquema, a própria conta devolve 0 sem precisar de exceção.
 *
 * Só entra vaga JÁ PREENCHIDA. O jogo não deixa trocar com vaga vazia
 * (@see a ação `trocar` em games/games/draftfut.php), e prometer a química
 * de uma troca impossível seria pior que não dizer nada.
 *
 * @return array<int, array{q:int, vaga:int, naPos:bool}> por slot de banco
 */
function draftFutQuimicaDoBanco(string $formacao, array $time): array
{
    $prev = [];
    for ($b = DFUT_VAGAS; $b < DFUT_TOTAL; $b++) {
        if (empty($time[$b])) continue;
        $pos = (string)$time[$b]['pos'];

        $melhorQ = null; $melhorVaga = null; $melhorNaPos = false;
        for ($i = 0; $i < DFUT_VAGAS; $i++) {
            if (empty($time[$i])) continue;

            $sim = $time;
            $sim[$i] = $time[$b];
            $sim[$b] = $time[$i];
            $q = (int)(draftFutQuimica($formacao, $sim)['jogadores'][$i] ?? 0);
            $naPos = $pos === draftFutPosDaVaga($formacao, $i);

            /* EMPATE DESEMPATA PELA POSIÇÃO CERTA. Sem isso, um reserva que
               tira 0 em toda parte acabava apontando a primeira vaga testada
               — e o aviso dizia "entra como GOL no lugar do Courtois", que é
               uma troca que ninguém faria. Com o desempate, o 0 ou aponta a
               vaga de verdade dele ou admite que não existe uma. */
            if ($melhorQ === null
                || $q > $melhorQ
                || ($q === $melhorQ && $naPos && !$melhorNaPos)) {
                $melhorQ = $q; $melhorVaga = $i; $melhorNaPos = $naPos;
            }
        }
        if ($melhorQ !== null) {
            $prev[$b] = ['q' => $melhorQ, 'vaga' => $melhorVaga, 'naPos' => $melhorNaPos];
        }
    }
    return $prev;
}

/**
 * A força do time em campo: OVR ajustado pela química e pela posição.
 *
 * É AQUI QUE A QUÍMICA VIRA PLACAR. O time entra com a média dos OVRs, menos
 * o que se perde jogando fora de posição, mais ou menos até 8% conforme a
 * química — um time 100 de química joga acima do papel, um de 20 joga abaixo.
 * Sem este passo a química seria decoração.
 */
function draftFutForcaDoTime(string $formacao, array $time): int
{
    $vagas = DFUT_FORMACOES[$formacao] ?? [];
    $soma = 0; $n = 0;
    /* SÓ OS ONZE DE CAMPO. Antes o laço varria as 19 vagas, e como
       DFUT_FORMACOES só tem onze, cada reserva caía no `?? 10` e entrava com
       dez de desconto — o banco cheio derrubava a força do time. Pior: o
       adversário da máquina é montado só com onze (@see
       draftFutAdversarioDaMaquina), então quem tinha banco jogava com uma
       força artificialmente menor que a dele. No FUT o banco não joga. */
    for ($i = 0; $i < DFUT_VAGAS; $i++) {
        $c = $time[$i] ?? null;
        if (!$c) continue;
        $natural = $vagas[$i][1] ?? '';
        $soma += $c['ovr'] - (DFUT_COBRE[$natural][$c['pos']] ?? 10);
        $n++;
    }
    if (!$n) return 50;
    $base = $soma / $n;

    /* A QUÍMICA AGORA VAI DE 0 A 33, não mais de 0 a 100 — e esta conta tinha
       ficado pra trás na troca de régua: com (q-50)/50, a química só sabia
       punir, porque nem o time perfeito chegava a 50. O efeito pretendido
       sempre foi ±8% em volta do meio da escala, e é isso que está aqui. */
    $teto = DFUT_VAGAS * 3;
    $q = draftFutQuimica($formacao, $time)['total'];
    return (int)round($base * (1 + (($q / $teto) - 0.5) * 0.16));
}

/**
 * Os esquemas que aparecem na abertura de um draft.
 *
 * Sorteia DFUT_FORMACOES_NA_MESA do baralho de formações, SEMPRE A PARTIR DE
 * UMA SEMENTE: a tela é desenhada de novo a cada F5, e sorteando na hora a
 * lista mudaria embaixo da pessoa enquanto ela decide. A semente nasce com o
 * acesso e fica guardada na sessão, igual às cinco cartas de cada vaga.
 *
 * Devolve na ordem do catálogo, não na do sorteio: a pessoa lê cinco esquemas,
 * não um ranking, e ordem estável é mais fácil de comparar.
 */
function draftFutFormacoesSorteadas(int $semente): array
{
    $nomes = array_keys(DFUT_FORMACOES);
    mt_srand($semente);
    shuffle($nomes);
    $escolhidas = array_slice($nomes, 0, DFUT_FORMACOES_NA_MESA);
    mt_srand();   // devolve o gerador ao acaso, senão o resto do draft fica preso à semente

    /* Só esquema que tem coordenada no campinho — senão a vaga nasce sem
       lugar na tela. Isto é cinto de segurança: o teste já confere as duas
       listas, mas um esquema novo sem coordenada não pode chegar ao jogador. */
    $escolhidas = array_values(array_filter($escolhidas, fn($n) => isset(DFUT_CAMPO[$n])));
    if (!$escolhidas) $escolhidas = ['4-3-3'];

    return array_values(array_filter(array_keys(DFUT_FORMACOES),
        fn($n) => in_array($n, $escolhidas, true)));
}
/* ═════════════════════ AS MOEDAS DO DRAFT ═══════════════════════════
   Moravam em games/games/draftfut.php, que é a tela. Subiram pro core em
   08/10/2026 porque o duelo por código também cobra e paga, e duas cópias da
   mesma regra de saldo é como um lado passa a pagar diferente do outro. */

/** O saldo de moedas de um GM. */
function dfMoedas(PDO $pdo, int $uid): int
{
    $st = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ?');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

/** Mexe no saldo. Negativo cobra, positivo paga. Nunca deixa abaixo de zero. */
function dfMoedasMexer(PDO $pdo, int $uid, int $delta): void
{
    $pdo->prepare('UPDATE games_usuarios SET pontos = GREATEST(0, pontos + ?) WHERE id = ?')
        ->execute([$delta, $uid]);
}
