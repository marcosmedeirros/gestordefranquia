<?php
/**
 * ── DESAFIOS DA TEMPORADA ────────────────────────────────────────────
 *
 * A meta da diretoria é uma só, vale o ano inteiro e só se descobre se foi
 * cumprida em dezembro. No meio do caminho não havia nada pequeno pra
 * perseguir — nenhum motivo pra escalar o garoto de 19 em vez do titular, ou
 * pra tentar o terceiro gol num jogo já ganho.
 *
 * O desafio é isso: três objetivos curtos por temporada, com prêmio na hora
 * em que o último número bate. Eles não substituem a meta — a meta ainda
 * demite. Eles dão o que fazer entre uma rodada e outra.
 *
 * ── POR QUE TRÊS, E SORTEADOS ────────────────────────────────────────
 *
 * Seis de uma vez vira lista de tarefas e ninguém olha. Três cabem na tela e
 * o técnico escolhe quais perseguir — às vezes dois deles pedem coisas
 * opostas (rodar os garotos e segurar a invencibilidade), e aí há decisão.
 *
 * O SORTEIO É PRESO AO CLUBE E À TEMPORADA (mt_srand com crc32), e não ao
 * relógio: abrir a tela de novo tem que mostrar os mesmos três. Sorteio solto
 * trocaria os desafios a cada F5, e nenhum deles valeria nada.
 *
 * ── O PRÊMIO PAGA UMA VEZ ────────────────────────────────────────────
 *
 * Quem bate o alvo recebe e fica marcado como feito. O progresso é RECALCULADO
 * do estado toda vez (não é contador guardado), então save antigo, venda de
 * jogador e virada de ano nunca deixam um número inventado na tela — mas o
 * "feito" é gravado, senão o prêmio sairia de novo a cada conferência.
 */

/** Quantos desafios ficam valendo por temporada. */
const FUT_DESAFIOS_POR_ANO = 3;

/**
 * O CATÁLOGO.
 *
 * Cada um sabe se medir sozinho a partir do estado — é isso que permite
 * recalcular em vez de guardar contador. 'alvo' é o número a bater, 'premio'
 * é o que cai quando bate.
 *
 * Os tipos de prêmio:
 *   dinheiro   entra no caixa (milhões)
 *   reputacao  sobe a reputação do técnico, que é quem define os convites
 *   moral      sobe a moral de TODO o elenco
 */
function futDesafiosCatalogo(): array
{
    return [
        'invencivel' => [
            'texto'  => 'Ficar %d jogos seguidos sem perder',
            'alvo'   => 6,
            'premio' => ['tipo' => 'dinheiro', 'valor' => 10],
            'conta'  => 'futDesafioSequenciaSemPerder',
        ],
        'artilheiro' => [
            'texto'  => 'Ter um jogador com %d gols na temporada',
            'alvo'   => 12,
            'premio' => ['tipo' => 'dinheiro', 'valor' => 8],
            'conta'  => 'futDesafioMaiorArtilheiro',
        ],
        'muralha' => [
            'texto'  => 'Terminar %d partidas sem sofrer gol',
            'alvo'   => 6,
            'premio' => ['tipo' => 'dinheiro', 'valor' => 8],
            'conta'  => 'futDesafioJogosSemSofrer',
        ],
        'atropelo' => [
            'texto'  => 'Vencer %d partidas por 3 gols ou mais de diferença',
            'alvo'   => 3,
            'premio' => ['tipo' => 'reputacao', 'valor' => 5],
            'conta'  => 'futDesafioGoleadas',
        ],
        'crias' => [
            'texto'  => 'Dar %d jogos a jogadores de até 21 anos',
            'alvo'   => 15,
            'premio' => ['tipo' => 'reputacao', 'valor' => 6],
            'conta'  => 'futDesafioJogosDosGarotos',
        ],
        'negociante' => [
            'texto'  => 'Vender um jogador por %d milhões ou mais',
            'alvo'   => 20,
            'premio' => ['tipo' => 'moral', 'valor' => 6],
            'conta'  => 'futDesafioMaiorVenda',
        ],
    ];
}

/* ── As contas de cada desafio ─────────────────────────────────────── */

/** A maior sequência sem derrota da temporada (não a atual: a maior). */
function futDesafioSequenciaSemPerder(array $estado): int
{
    $maior = 0; $atual = 0;
    foreach ($estado['resultados'] ?? [] as $r) {
        if ((int)$r['meus'] < (int)$r['deles']) { $atual = 0; continue; }
        $atual++;
        if ($atual > $maior) $maior = $atual;
    }
    return $maior;
}

/** Quantos gols tem o maior goleador do elenco nesta temporada. */
function futDesafioMaiorArtilheiro(array $estado): int
{
    $maior = 0;
    foreach ($estado['stats'] ?? [] as $st) $maior = max($maior, (int)($st['gols'] ?? 0));
    return $maior;
}

/** Partidas em que o adversário não marcou. */
function futDesafioJogosSemSofrer(array $estado): int
{
    $n = 0;
    foreach ($estado['resultados'] ?? [] as $r) if ((int)$r['deles'] === 0) $n++;
    return $n;
}

/** Vitórias por três ou mais de diferença. */
function futDesafioGoleadas(array $estado): int
{
    $n = 0;
    foreach ($estado['resultados'] ?? [] as $r) if ((int)$r['meus'] - (int)$r['deles'] >= 3) $n++;
    return $n;
}

/**
 * Jogos dados a quem tem até 21 anos.
 *
 * A idade é a de HOJE, do elenco — quem saiu do clube não conta mais. É o
 * mesmo critério do destaque do treino: o desafio é sobre formar os seus.
 */
function futDesafioJogosDosGarotos(array $estado): int
{
    $jovens = [];
    foreach ($estado['elenco'] ?? [] as $j) {
        if ((int)$j['idade'] <= 21) $jovens[$j['nome']] = true;
    }
    $n = 0;
    foreach ($estado['stats'] ?? [] as $nome => $st) {
        if (isset($jovens[$nome])) $n += (int)($st['jogos'] ?? 0);
    }
    return $n;
}

/**
 * A maior venda da temporada, em milhões.
 *
 * Esta é a única que não se recalcula do estado: jogador vendido some do save,
 * e o valor da venda não fica em lugar nenhum. Quem vende grava o número em
 * `marcos`. @see futOfertaAceitar
 */
function futDesafioMaiorVenda(array $estado): int
{
    return (int)floor((float)($estado['marcos']['maior_venda'] ?? 0));
}

/** Registra uma venda, pra o desafio do negociante ter o que medir. */
function futDesafioRegistrarVenda(array $estado, float $valor): array
{
    $estado['marcos']['maior_venda'] = max((float)($estado['marcos']['maior_venda'] ?? 0), $valor);
    return $estado;
}

/* ── O sorteio e a conferência ─────────────────────────────────────── */

/**
 * Os três da temporada, sorteados de um jeito que não muda a cada F5.
 *
 * Save antigo (e o primeiro ano de todo mundo) cai aqui sem nada gravado e
 * ganha os três na hora — por isso o sorteio tem que ser determinístico: ele
 * roda de novo em TODA leitura até o primeiro save acontecer.
 */
function futDesafiosSortear(array $estado): array
{
    $chaves = array_keys(futDesafiosCatalogo());
    mt_srand(crc32(($estado['clube'] ?? '') . '|desafios|' . (int)($estado['temporada'] ?? 1)));
    shuffle($chaves);
    mt_srand();

    $out = [];
    foreach (array_slice($chaves, 0, FUT_DESAFIOS_POR_ANO) as $id) {
        $out[] = ['id' => $id, 'feito' => false];
    }
    return $out;
}

/**
 * OS DESAFIOS COM TUDO QUE A TELA PRECISA: texto, alvo, progresso e prêmio.
 *
 * Só leitura — não paga nem grava. Quem paga é futDesafiosConferir.
 */
function futCarreiraDesafios(array $estado): array
{
    $cat = futDesafiosCatalogo();
    $guardados = $estado['desafios'] ?? [];
    if (!$guardados) $guardados = futDesafiosSortear($estado);

    $out = [];
    foreach ($guardados as $d) {
        $def = $cat[$d['id']] ?? null;
        if (!$def) continue;          // catálogo mudou: desafio velho some em silêncio
        $progresso = (int)call_user_func($def['conta'], $estado);
        $out[] = [
            'id'        => $d['id'],
            'texto'     => sprintf($def['texto'], $def['alvo']),
            'alvo'      => (int)$def['alvo'],
            'progresso' => min((int)$def['alvo'], $progresso),
            'premio'    => $def['premio'],
            'feito'     => !empty($d['feito']),
            'batido'    => $progresso >= (int)$def['alvo'],
        ];
    }
    return $out;
}

/** A frase do prêmio, do jeito que a tela mostra. */
function futDesafioTextoDoPremio(array $premio): string
{
    $v = (int)$premio['valor'];
    switch ($premio['tipo']) {
        case 'dinheiro':  return '+' . futDinheiro($v) . ' no caixa';
        case 'reputacao': return '+' . $v . ' de reputação';
        case 'moral':     return '+' . $v . ' de moral no elenco';
    }
    return '';
}

/**
 * CONFERE E PAGA o que acabou de ser batido.
 *
 * Roda depois de cada partida e no fecho da temporada. Paga uma vez por
 * desafio — o 'feito' gravado é o que garante isso.
 *
 * @return array ['estado'=>array, 'pagos'=>array]
 */
function futDesafiosConferir(array $estado): array
{
    $lista = futCarreiraDesafios($estado);
    /* LISTA VAZIA TAMBÉM PRECISA SORTEAR, e `??` não serve aqui: ele só pega
       null, e `desafios` fica como [] tanto no save novo quanto na virada do
       ano. Com [] passando direto, o `feito` não tinha onde ser gravado — o
       prêmio era pago e, como nada ficava marcado, pagava outra vez na rodada
       seguinte, e na outra, pra sempre. */
    $guardados = $estado['desafios'] ?? [];
    if (!$guardados) $guardados = futDesafiosSortear($estado);
    $pagos = [];

    foreach ($lista as $i => $d) {
        if ($d['feito'] || !$d['batido']) continue;

        $premio = $d['premio'];
        switch ($premio['tipo']) {
            case 'dinheiro':
                $estado['caixa'] = round((float)$estado['caixa'] + (int)$premio['valor'], 2);
                break;
            case 'reputacao':
                $estado['tecnico']['reputacao'] = max(0, min(100,
                    (int)($estado['tecnico']['reputacao'] ?? 10) + (int)$premio['valor']));
                break;
            case 'moral':
                foreach ($estado['elenco'] ?? [] as $k => $j) {
                    $estado['elenco'][$k]['moral'] = min(100,
                        (int)($j['moral'] ?? FUT_MORAL_NORMAL) + (int)$premio['valor']);
                }
                break;
        }

        if (isset($guardados[$i])) $guardados[$i]['feito'] = true;
        $pagos[] = $d;

        $estado['mensagens'][] = sprintf('Desafio cumprido: %s. %s.',
            $d['texto'], futDesafioTextoDoPremio($premio));
        $estado['imprensa'] = array_slice(array_merge([[
            'texto' => sprintf('%s cumpriu o desafio da diretoria: %s.',
                (string)($estado['clube'] ?? ''), mb_strtolower($d['texto'])),
            'tom'   => 'boa',
            'jogo'  => count($estado['resultados'] ?? []),
        ]], $estado['imprensa'] ?? []), 0, 12);
    }

    $estado['desafios'] = $guardados;
    return ['estado' => $estado, 'pagos' => $pagos];
}
