<?php
/**
 * A ESCALAÇÃO — o esquema tático e os onze que entram em campo.
 *
 * Até aqui o time era só "a média dos melhores do elenco". Isso bastava pra
 * rodar uma tabela, mas não é jogar: o que faz o gênero funcionar é escolher
 * quem entra, em que posição, e pagar o preço quando a escolha é ruim.
 *
 * ── O QUE ESTE ARQUIVO DECIDE ────────────────────────────────────────
 *
 * Um esquema é uma lista de VAGAS com posição e um lugar no campo. As
 * coordenadas não são enfeite pra tela: é a mesma lista que desenha o campo e
 * que decide quem joga onde, então não existe o clássico bug de a tela mostrar
 * um 4-3-3 enquanto o motor calcula um 4-4-2.
 *
 * Escalar fora da posição é PERMITIDO e CUSTA CARO. Um zagueiro improvisado na
 * ponta joga muito abaixo do OVR dele. Sem essa conta, o jogador escalaria
 * simplesmente os 11 maiores OVRs e o esquema viraria decoração.
 */

require_once __DIR__ . '/fut_elencos.php';

/**
 * Os esquemas disponíveis.
 *
 * Cada vaga é ['POS', x, y] em porcentagem do campo: x de 0 (esquerda) a 100
 * (direita), y de 0 (linha de frente) a 100 (nosso gol). É o mesmo sistema de
 * coordenadas que a tela usa pra posicionar as camisas.
 */
const FUT_ESQUEMAS = [
    '4-4-2' => [
        'nome' => '4-4-2', 'desc' => 'O clássico. Equilíbrio entre meio e ataque.',
        'vagas' => [
            ['GOL', 50, 93],
            ['LAT', 12, 72], ['ZAG', 36, 78], ['ZAG', 64, 78], ['LAT', 88, 72],
            ['PON', 12, 46], ['VOL', 36, 52], ['MEI', 64, 48], ['PON', 88, 46],
            ['ATA', 36, 18], ['ATA', 64, 18],
        ],
    ],
    '4-3-3' => [
        'nome' => '4-3-3', 'desc' => 'Ataque aberto pelos lados. Pede pontas de verdade.',
        'vagas' => [
            ['GOL', 50, 93],
            ['LAT', 12, 72], ['ZAG', 36, 78], ['ZAG', 64, 78], ['LAT', 88, 72],
            ['VOL', 50, 58], ['MEI', 28, 46], ['MEI', 72, 46],
            ['PON', 14, 20], ['ATA', 50, 14], ['PON', 86, 20],
        ],
    ],
    '4-2-3-1' => [
        'nome' => '4-2-3-1', 'desc' => 'Dois volantes e um meia armador. O mais usado hoje.',
        'vagas' => [
            ['GOL', 50, 93],
            ['LAT', 12, 72], ['ZAG', 36, 78], ['ZAG', 64, 78], ['LAT', 88, 72],
            ['VOL', 36, 58], ['VOL', 64, 58],
            ['PON', 14, 34], ['MEI', 50, 34], ['PON', 86, 34],
            ['ATA', 50, 12],
        ],
    ],
    '3-5-2' => [
        'nome' => '3-5-2', 'desc' => 'Três zagueiros e alas subindo. Meio de campo povoado.',
        'vagas' => [
            ['GOL', 50, 93],
            ['ZAG', 26, 78], ['ZAG', 50, 80], ['ZAG', 74, 78],
            ['LAT', 8, 50], ['VOL', 34, 56], ['MEI', 50, 46], ['VOL', 66, 56], ['LAT', 92, 50],
            ['ATA', 36, 16], ['ATA', 64, 16],
        ],
    ],
    '5-3-2' => [
        'nome' => '5-3-2', 'desc' => 'Retranca. Segura o jogo e aposta no contra-ataque.',
        'vagas' => [
            ['GOL', 50, 93],
            ['LAT', 10, 68], ['ZAG', 30, 80], ['ZAG', 50, 82], ['ZAG', 70, 80], ['LAT', 90, 68],
            ['VOL', 32, 54], ['VOL', 68, 54], ['MEI', 50, 40],
            ['ATA', 38, 16], ['ATA', 62, 16],
        ],
    ],
    '3-4-3' => [
        'nome' => '3-4-3', 'desc' => 'Tudo pra frente. Ganha ou perde de goleada.',
        'vagas' => [
            ['GOL', 50, 93],
            ['ZAG', 26, 78], ['ZAG', 50, 80], ['ZAG', 74, 78],
            ['LAT', 10, 52], ['VOL', 38, 54], ['MEI', 62, 50], ['LAT', 90, 52],
            ['PON', 16, 18], ['ATA', 50, 12], ['PON', 84, 18],
        ],
    ],
];

/**
 * O quanto o jogador perde de OVR quando escalado fora da posição dele.
 *
 * A REGRA QUE FAZ O ESQUEMA IMPORTAR. Posições vizinhas custam pouco — um
 * lateral vira ala sem drama. Posições distantes custam muito, e goleiro é
 * caso à parte: goleiro na linha, ou jogador de linha no gol, é desastre. Sem
 * este custo o jogador escalaria os 11 maiores OVRs em qualquer vaga, e todo
 * time do jogo jogaria igual.
 */
const FUT_AFINIDADE = [
    'GOL' => ['GOL' => 0,  'ZAG' => 30, 'LAT' => 34, 'VOL' => 36, 'MEI' => 38, 'PON' => 40, 'ATA' => 40],
    'ZAG' => ['GOL' => 30, 'ZAG' => 0,  'LAT' => 4,  'VOL' => 5,  'MEI' => 11, 'PON' => 14, 'ATA' => 15],
    'LAT' => ['GOL' => 34, 'ZAG' => 4,  'LAT' => 0,  'VOL' => 5,  'MEI' => 8,  'PON' => 5,  'ATA' => 13],
    'VOL' => ['GOL' => 36, 'ZAG' => 5,  'LAT' => 5,  'VOL' => 0,  'MEI' => 3,  'PON' => 8,  'ATA' => 11],
    'MEI' => ['GOL' => 38, 'ZAG' => 11, 'LAT' => 8,  'VOL' => 3,  'MEI' => 0,  'PON' => 4,  'ATA' => 6],
    'PON' => ['GOL' => 40, 'ZAG' => 14, 'LAT' => 5,  'VOL' => 8,  'MEI' => 4,  'PON' => 0,  'ATA' => 4],
    'ATA' => ['GOL' => 40, 'ZAG' => 15, 'LAT' => 13, 'VOL' => 11, 'MEI' => 6,  'PON' => 4,  'ATA' => 0],
];

/** O OVR efetivo de um jogador numa vaga — o dele menos o custo de improviso. */
function futOvrNaVaga(array $jogador, string $vaga): int
{
    $perda = FUT_AFINIDADE[$vaga][$jogador['pos']] ?? 12;
    return (int)max(20, (int)$jogador['ovr'] - $perda);
}

/**
 * ESCALA O TIME SOZINHO: preenche cada vaga com o melhor disponível.
 *
 * Vai por VAGA MAIS EXIGENTE primeiro — goleiro antes de tudo, porque a perda
 * de improvisar no gol é enorme e deixar essa vaga por último faria o
 * escalador automático pôr um atacante no gol quando os goleiros acabassem.
 *
 * @param array $elenco jogadores disponíveis
 * @param array $fora   nomes que não podem jogar (suspensos, machucados)
 * @return array [indice da vaga => jogador]
 */
function futEscalarAutomatico(array $elenco, string $esquema, array $fora = []): array
{
    $vagas = FUT_ESQUEMAS[$esquema]['vagas'] ?? FUT_ESQUEMAS['4-4-2']['vagas'];
    $disponiveis = array_values(array_filter($elenco, fn($j) => !in_array($j['nome'], $fora, true)));

    /* A ordem de preenchimento: primeiro as vagas onde improvisar dói mais.
       O número é a maior perda possível daquela vaga — goleiro lidera de longe. */
    $ordem = array_keys($vagas);
    usort($ordem, function ($a, $b) use ($vagas) {
        $custoA = max(array_column(FUT_AFINIDADE, $vagas[$a][0]) ?: [0]);
        $custoB = max(array_column(FUT_AFINIDADE, $vagas[$b][0]) ?: [0]);
        return $custoB <=> $custoA;
    });

    $escalados = [];
    $usados = [];
    foreach ($ordem as $iv) {
        $pos = $vagas[$iv][0];
        $melhor = null;
        $melhorNota = -1;
        foreach ($disponiveis as $j) {
            if (isset($usados[$j['nome']])) continue;
            $nota = futOvrNaVaga($j, $pos);
            if ($nota > $melhorNota) { $melhorNota = $nota; $melhor = $j; }
        }
        if ($melhor === null) continue;   // elenco curto: a vaga fica vazia
        $escalados[$iv] = $melhor;
        $usados[$melhor['nome']] = true;
    }

    ksort($escalados);
    return $escalados;
}

/**
 * A FORÇA DO TIME EM CAMPO, a partir de quem foi escalado.
 *
 * Não é a média simples dos onze: o goleiro e a defesa pesam diferente do
 * ataque, e um time com onze atacantes não pode valer o mesmo que um time
 * equilibrado com a mesma média de OVR.
 *
 * Vaga vazia (elenco curto demais pro esquema) entra como 35 — ruim, mas não
 * zero: o time joga com menos e sofre, em vez de o cálculo explodir.
 */
function futForcaEscalada(array $escalados, string $esquema): int
{
    $vagas = FUT_ESQUEMAS[$esquema]['vagas'] ?? FUT_ESQUEMAS['4-4-2']['vagas'];
    if (!$vagas) return 40;

    $peso = ['GOL' => 1.25, 'ZAG' => 1.1, 'LAT' => 0.95, 'VOL' => 1.0, 'MEI' => 1.05, 'PON' => 0.95, 'ATA' => 1.1];
    $soma = 0.0;
    $pesos = 0.0;

    foreach ($vagas as $iv => $v) {
        $pos = $v[0];
        $p = $peso[$pos] ?? 1.0;
        $ovr = isset($escalados[$iv]) ? futOvrNaVaga($escalados[$iv], $pos) : 35;
        $soma += $ovr * $p;
        $pesos += $p;
    }
    return (int)round($soma / max(0.001, $pesos));
}

/**
 * Quantos jogadores estão fora de posição na escalação, e quanto isso custou.
 *
 * É o que a tela mostra como aviso — o jogador precisa VER que improvisou,
 * senão ele perde jogos sem entender a razão.
 */
function futAvisosDaEscalacao(array $escalados, string $esquema): array
{
    $vagas = FUT_ESQUEMAS[$esquema]['vagas'] ?? [];
    $avisos = [];
    foreach ($vagas as $iv => $v) {
        if (!isset($escalados[$iv])) { $avisos[] = ['vaga' => $v[0], 'texto' => 'Vaga de ' . $v[0] . ' vazia']; continue; }
        $j = $escalados[$iv];
        $perda = FUT_AFINIDADE[$v[0]][$j['pos']] ?? 12;
        if ($perda >= 8) {
            $avisos[] = ['vaga' => $v[0], 'jogador' => $j['nome'], 'perda' => $perda,
                         'texto' => $j['nome'] . ' (' . $j['pos'] . ') improvisado como ' . $v[0] . ': −' . $perda . ' de OVR'];
        }
    }
    return $avisos;
}

/**
 * Valida e normaliza uma escalação vinda da tela.
 *
 * NUNCA CONFIA NO QUE CHEGA: nome que não está no elenco, jogador repetido em
 * duas vagas ou suspenso escalado são todos recusados aqui. A tela já impede,
 * mas a tela é só a tela — quem garante a regra é este arquivo.
 *
 * @param array $mapa [indice da vaga => nome do jogador]
 * @return array ['ok'=>bool,'erro'=>string,'escalados'=>array]
 */
function futValidarEscalacao(array $mapa, array $elenco, string $esquema, array $fora = []): array
{
    $vagas = FUT_ESQUEMAS[$esquema]['vagas'] ?? null;
    if (!$vagas) return ['ok' => false, 'erro' => 'Esquema desconhecido.', 'escalados' => []];

    $porNome = [];
    foreach ($elenco as $j) $porNome[$j['nome']] = $j;

    $escalados = [];
    $vistos = [];
    foreach ($mapa as $iv => $nome) {
        $iv = (int)$iv;
        if (!isset($vagas[$iv])) continue;
        if ($nome === '' || !isset($porNome[$nome])) continue;
        if (isset($vistos[$nome])) {
            return ['ok' => false, 'erro' => $nome . ' está escalado em duas vagas.', 'escalados' => []];
        }
        if (in_array($nome, $fora, true)) {
            return ['ok' => false, 'erro' => $nome . ' não pode jogar esta partida.', 'escalados' => []];
        }
        $escalados[$iv] = $porNome[$nome];
        $vistos[$nome] = true;
    }

    if (count($escalados) < count($vagas)) {
        return ['ok' => false, 'erro' => 'Faltam jogadores: escale os ' . count($vagas) . '.', 'escalados' => $escalados];
    }
    ksort($escalados);
    return ['ok' => true, 'erro' => '', 'escalados' => $escalados];
}

/** Os reservas: quem está no elenco e não está em campo. */
function futReservas(array $elenco, array $escalados, array $fora = []): array
{
    $emCampo = [];
    foreach ($escalados as $j) $emCampo[$j['nome']] = true;
    return array_values(array_filter($elenco,
        fn($j) => !isset($emCampo[$j['nome']]) && !in_array($j['nome'], $fora, true)));
}
