<?php
/**
 * AS TRAVAS ANTITANKING DA LOTERIA — Art. 25 do edital.
 *
 * Até agora o sorteio só respeitava o PISO (os três piores não caem além da
 * pick 12). As travas de cima, que são o freio do tanking, não existiam no
 * código: quem caísse na sorte levava a pick 1 duas edições seguidas e
 * ninguém percebia.
 *
 * São três, e valem juntas:
 *
 *   I. SEM Nº 1 SEGUIDO. Ninguém fica com a 1ª escolha em duas edições
 *      consecutivas. A pick 1 passa pro próximo mais bem posicionado no
 *      sorteio e o impedido assume a posição imediatamente seguinte.
 *
 *   II. LIMITE DE TOP 3. Três drafts seguidos no top 3 e o terceiro sai:
 *       assume a 4ª.
 *
 *   III. LIMITE DE TOP 5. Mesma ideia, três seguidos no top 5, e o terceiro
 *        assume a 6ª.
 *
 * QUANDO AS DUAS DE CIMA DISPARAM JUNTAS vale a mais funda: quem foi top 3
 * três vezes também foi top 5 três vezes, e mandar pra 4ª deixaria a trava do
 * top 5 sem efeito. Sair do top 5 já é sair do top 3.
 *
 * "COM ESCOLHA PRÓPRIA" é o que separa o tanking da negociação. Se o time
 * vendeu a vaga dele, quem escolhe é outro — ele não recebeu nada e a trava
 * não pega. O caso real: na temporada 17 da NEXT a vaga do Vancouver Evergreen
 * saiu como pick 1, mas quem escolheu foi o New Mexico. Contar aquilo como
 * "Evergreen teve a primeira escolha" puniria o Evergreen por uma pick que ele
 * não usou, e puniria o comprador tirando dele o que comprou.
 *
 * A contagem é dentro da EDIÇÃO (sprint): reset de sprint zera o histórico,
 * porque a liga recomeça.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/** Até onde cada trava conta e pra onde ela empurra. */
const LOTERIA_TRAVAS = [
    // [faixa, drafts seguidos, posição de destino (1-based)]
    'top3' => ['faixa' => 3, 'seguidos' => 3, 'destino' => 4],
    'top5' => ['faixa' => 5, 'seguidos' => 3, 'destino' => 6],
];

/**
 * Em quantos drafts SEGUIDOS (contando do mais recente pra trás) o time
 * apareceu no top N com escolha própria.
 *
 * Para de contar no primeiro draft em que ele não apareceu — é "seguidos", e
 * não "quantas vezes ao todo".
 *
 * @return array<int,int> team_id => quantos drafts seguidos
 */
function loteriaSeguidosNoTop(PDO $pdo, string $league, int $faixa, ?int $ignorarSessao = null): array
{
    $league = strtoupper(trim($league));
    try {
        $seasonIds = seasonIdsDaSprintAtual($pdo, $league);
        $ph = implode(',', array_fill(0, count($seasonIds), '?'));

        /* As sessões da sprint, da mais recente pra trás. Só as que chegaram a
           rodar: sessão em `setup` não teve draft nenhum e não pode quebrar a
           sequência de ninguém. */
        $sql = "SELECT ds.id, s.season_number
                  FROM draft_sessions ds
                  JOIN seasons s ON s.id = ds.season_id
                 WHERE ds.league = ? AND ds.season_id IN ({$ph})
                   AND ds.status IN ('in_progress','completed')";
        $par = array_merge([$league], $seasonIds);
        if ($ignorarSessao) { $sql .= ' AND ds.id <> ?'; $par[] = $ignorarSessao; }
        $sql .= ' ORDER BY s.season_number DESC';

        $st = $pdo->prepare($sql);
        $st->execute($par);
        $sessoes = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$sessoes) return [];

        /* Quem esteve no top N de cada sessão, COM A PRÓPRIA VAGA:
           original_team_id = team_id. */
        $porSessao = [];
        $stTop = $pdo->prepare("SELECT DISTINCT team_id
                                  FROM draft_order
                                 WHERE draft_session_id = ? AND round = 1
                                   AND pick_position <= ? AND team_id = original_team_id");
        foreach ($sessoes as $s) {
            $stTop->execute([(int)$s['id'], $faixa]);
            $porSessao[] = array_map('intval', $stTop->fetchAll(PDO::FETCH_COLUMN));
        }

        // Conta a sequência a partir do draft mais recente.
        $seguidos = [];
        foreach ($porSessao[0] ?? [] as $tid) $seguidos[$tid] = 0;
        foreach ($seguidos as $tid => $_) {
            foreach ($porSessao as $lista) {
                if (!in_array($tid, $lista, true)) break;
                $seguidos[$tid]++;
            }
        }
        return array_filter($seguidos);
    } catch (Throwable $e) {
        error_log('[loteria-travas] seguidos no top: ' . $e->getMessage());
        return [];
    }
}

/** Quem ficou com a pick 1 do draft mais recente da sprint, com vaga própria. */
function loteriaCampeaoDaPick1(PDO $pdo, string $league, ?int $ignorarSessao = null): ?int
{
    $seguidos = loteriaSeguidosNoTop($pdo, $league, 1, $ignorarSessao);
    // `loteriaSeguidosNoTop` com faixa 1 já devolve só quem pegou a pick 1 no
    // último draft; o valor é quantas vezes seguidas, e aqui basta o quem.
    return $seguidos ? (int)array_key_first($seguidos) : null;
}

/**
 * Aplica as travas na ordem sorteada.
 *
 * Trabalha sobre uma lista de team_ids em que o índice 0 é a pick 1. Devolve a
 * lista nova e o que mudou, em texto — a tela da loteria mostra esses avisos
 * junto com os do piso, que é como o GM entende por que caiu.
 *
 * @param array $ordem       team_ids, índice 0 = pick 1
 * @param array $donoDaVaga  team_id de origem => team_id de quem escolhe.
 *                           Vaga negociada não sofre trava (ver o cabeçalho).
 * @return array{ordem: int[], avisos: string[]}
 */
function loteriaAplicarTravas(PDO $pdo, string $league, array $ordem, array $donoDaVaga = [],
                              array $nomes = [], ?int $ignorarSessao = null): array
{
    $avisos = [];
    if (count($ordem) < 2) return ['ordem' => $ordem, 'avisos' => $avisos];

    $nome = fn(int $tid) => $nomes[$tid] ?? ("Time #{$tid}");
    /* A vaga é dele mesmo? Sem entrada no mapa, assume que sim — é o caso
       normal, e a trava não pode deixar de valer por falta de informação. */
    $ehPropria = fn(int $tid) => !isset($donoDaVaga[$tid]) || (int)$donoDaVaga[$tid] === $tid;

    /** Tira o time da posição atual e encaixa em $destino (0-based). */
    $mover = function (array $lista, int $de, int $para): array {
        $tid = $lista[$de];
        array_splice($lista, $de, 1);
        array_splice($lista, min($para, count($lista)), 0, [$tid]);
        return $lista;
    };

    // ── I. Sem nº 1 seguido ──────────────────────────────────────────
    $campeao = loteriaCampeaoDaPick1($pdo, $league, $ignorarSessao);
    if ($campeao !== null && (int)$ordem[0] === $campeao && $ehPropria($campeao)) {
        $ordem = $mover($ordem, 0, 1);
        $avisos[] = $nome($campeao) . ' não pode ficar com a 1ª escolha duas edições seguidas (Art. 25, I): '
                  . 'a pick 1 passou para ' . $nome((int)$ordem[0]) . ' e ele assumiu a 2ª.';
    }

    // ── II e III. Top 3 e Top 5 ──────────────────────────────────────
    /* Da faixa mais funda pra mais rasa: quem viola as duas termina na 6ª, e
       não na 4ª. Aplicar o top 3 primeiro empurraria pra 4ª, e o top 5 ainda
       o acharia dentro da faixa — duas mexidas pro mesmo time, na mesma
       loteria, cada uma dizendo uma coisa. */
    foreach (['top5', 'top3'] as $qual) {
        $regra = LOTERIA_TRAVAS[$qual];
        $seguidos = loteriaSeguidosNoTop($pdo, $league, $regra['faixa'], $ignorarSessao);
        if (!$seguidos) continue;

        // Só uma correção por faixa por loteria: mexer em dois ao mesmo tempo
        // embaralha a ordem de um jeito que ninguém consegue explicar depois.
        for ($i = 0; $i < min($regra['faixa'], count($ordem)); $i++) {
            $tid = (int)$ordem[$i];
            if (($seguidos[$tid] ?? 0) < $regra['seguidos'] - 1) continue;
            if (!$ehPropria($tid)) continue;

            $destino = $regra['destino'] - 1;          // 1-based -> 0-based
            if ($i >= $destino) continue;               // já está fora da faixa

            $ordem = $mover($ordem, $i, $destino);
            $avisos[] = sprintf(
                '%s já esteve no top %d nos %d drafts anteriores (Art. 25, II): saiu da %dª e assumiu a %dª. '
                . 'Os times entre a %dª e a %dª subiram uma posição.',
                $nome($tid), $regra['faixa'], $regra['seguidos'] - 1,
                $i + 1, $regra['destino'], $i + 2, $regra['destino']
            );
            break;
        }
    }

    return ['ordem' => array_values($ordem), 'avisos' => $avisos];
}
