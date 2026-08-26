<?php
/**
 * AS APOSTAS DOS GAMES, num lugar só.
 *
 * A conta das porcentagens já existia duas vezes dentro do games.php — uma
 * pra desenhar a página e outra pra responder o clique — e o comentário de
 * lá já avisava o motivo de elas terem que ser iguais: "a soma daria 100 ao
 * carregar e 101 depois de clicar, que é o tipo de coisa que parece bug do
 * clique". O bot precisava da mesma conta, e uma terceira cópia era a
 * garantia de que um dia o grupo veria uma porcentagem e o site outra.
 */

/**
 * As porcentagens de um evento, somando exatamente 100.
 *
 * Arredondar cada opção por conta própria não fecha: 26 palpites divididos
 * em 14/6/3/2/1 dá 54+23+12+8+4 = 101%, e ninguém lê isso como
 * arredondamento — lê como conta errada. O método é o do maior resto: todo
 * mundo leva a parte inteira, e o que sobrou vai pra quem ficou com a maior
 * fração pendurada.
 *
 * Sem ninguém tendo palpitado, TUDO é zero — e não um por cento cada, que é
 * no que a divisão por zero viraria se alguém "protegesse" o denominador
 * com um max(1, ...).
 *
 * @param array<int|string,int> $porOpcao chave => quantos palpites
 * @return array<int|string,int> a mesma chave => porcentagem inteira
 */
function apostasPercentuais(array $porOpcao): array
{
    $total = array_sum(array_map('intval', $porOpcao));
    $pct = []; $restos = []; $soma = 0;

    foreach ($porOpcao as $k => $n) {
        if ($total <= 0) { $pct[$k] = 0; continue; }
        $exato   = (int)$n * 100 / $total;
        $pct[$k] = (int)floor($exato);
        $soma   += $pct[$k];
        $restos[$k] = $exato - $pct[$k];
    }

    if ($total > 0) {
        arsort($restos);                       // maior fração pendurada primeiro
        foreach (array_keys($restos) as $k) {
            if ($soma >= 100) break;
            $pct[$k]++; $soma++;
        }
    }
    return $pct;
}

/**
 * Quantos palpitaram em cada opção de um evento.
 *
 * @return array<int,int> opcao_id => palpites
 */
function apostasPalpitesPorOpcao(PDO $pdo, int $eventoId): array
{
    $st = $pdo->prepare("SELECT p.opcao_id, COUNT(*) AS n
                           FROM palpites p JOIN opcoes o ON p.opcao_id = o.id
                          WHERE o.evento_id = ?
                          GROUP BY p.opcao_id");
    $st->execute([$eventoId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) {
        $out[(int)$l['opcao_id']] = (int)$l['n'];
    }
    return $out;
}

/**
 * As apostas ABERTAS, com a parcial de cada uma.
 *
 * "Aberta" é status 'aberta' E prazo no futuro: evento cujo prazo passou
 * mas que o admin ainda não encerrou não aceita palpite novo, então
 * mostrá-lo como aberto seria convidar pra uma votação que não conta.
 *
 * @return array<int,array> cada evento com opcoes[], total e o líder
 */
function apostasAbertas(PDO $pdo, int $limite = 20): array
{
    $agora = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');

    $st = $pdo->prepare("SELECT id, nome, data_limite FROM eventos
                          WHERE status = 'aberta' AND data_limite > ?
                          ORDER BY data_limite ASC LIMIT " . max(1, $limite));
    $st->execute([$agora]);
    $eventos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($eventos as &$ev) {
        $stO = $pdo->prepare("SELECT id, descricao FROM opcoes WHERE evento_id = ? ORDER BY id ASC");
        $stO->execute([(int)$ev['id']]);
        $opcoes = $stO->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $porOpcao = apostasPalpitesPorOpcao($pdo, (int)$ev['id']);
        // TODA opção entra na conta, inclusive a que ninguém escolheu: sem
        // isto a opção zerada sumiria da lista, e quem lê no grupo não veria
        // que ela existe.
        $contagem = [];
        foreach ($opcoes as $o) $contagem[(int)$o['id']] = $porOpcao[(int)$o['id']] ?? 0;
        $pct = apostasPercentuais($contagem);

        $ev['total']  = array_sum($contagem);
        $ev['opcoes'] = [];
        foreach ($opcoes as $o) {
            $id = (int)$o['id'];
            $ev['opcoes'][] = [
                'id'        => $id,
                'descricao' => (string)$o['descricao'],
                'palpites'  => $contagem[$id],
                'pct'       => $pct[$id] ?? 0,
            ];
        }

        // O líder sai daqui, e não de quem tem o maior pct: com empate real
        // duas opções têm a mesma porcentagem, e destacar só a primeira da
        // lista faria parecer que uma está na frente. Sem palpite nenhum não
        // há líder — zero a zero não tem favorito.
        $maior = 0;
        foreach ($ev['opcoes'] as $o) $maior = max($maior, $o['palpites']);
        $ev['lider'] = $maior > 0 ? $maior : null;
    }
    unset($ev);

    return $eventos;
}

/**
 * A parcial das apostas abertas, pro grupo — o /apostas do bot.
 *
 * Mostra a distribuição, não o placar final: enquanto o prazo não fecha, o
 * que está escrito aqui é o que a liga ACHA, e muda a cada palpite novo.
 */
function apostasTextoParcial(PDO $pdo): string
{
    $eventos = apostasAbertas($pdo);
    if (!$eventos) {
        return "🎯 *APOSTAS*\n\nNenhuma aposta aberta agora.\n\n"
             . "_Quando abrir, o palpite é na aba Apostas do /games._";
    }

    $l = ['🎯 *APOSTAS — PARCIAL*', ''];

    foreach ($eventos as $i => $ev) {
        if ($i > 0) $l[] = '';
        $l[] = '*' . $ev['nome'] . '*';

        if ((int)$ev['total'] === 0) {
            $l[] = '_ninguém palpitou ainda_';
        } else {
            foreach ($ev['opcoes'] as $op) {
                // A mais votada em NEGRITO. No empate as duas ficam em
                // negrito, porque as duas estão na frente — destacar só a
                // primeira da lista inventaria uma liderança que não existe.
                $ehLider = $ev['lider'] !== null && (int)$op['palpites'] === (int)$ev['lider'];
                $linha = $op['descricao'] . ' — ' . $op['pct'] . '%';
                $l[] = $ehLider ? '*' . $linha . '*' : $linha;
            }
            $l[] = '_' . $ev['total'] . ' palpite' . ($ev['total'] === 1 ? '' : 's') . '_';
        }

        // O prazo é o que decide se vale correr pra palpitar.
        $prazo = apostasPrazoEmTexto((string)$ev['data_limite']);
        if ($prazo !== '') $l[] = '_' . $prazo . '_';
    }

    $l[] = '';
    $l[] = '_Palpite na aba Apostas do /games._';
    return implode("\n", $l);
}

/**
 * O resultado das últimas apostas pagas — o /apostasresultado do bot.
 */
function apostasTextoResultados(PDO $pdo, int $limite = 10): string
{
    $eventos = apostasPagasRecentes($pdo, $limite);
    if (!$eventos) {
        return "🏁 *RESULTADOS DAS APOSTAS*\n\nNenhuma aposta paga ainda.";
    }

    $l = ['🏁 *RESULTADOS DAS APOSTAS*', ''];
    foreach ($eventos as $i => $ev) {
        if ($i > 0) $l[] = '';
        $l[] = '*' . $ev['nome'] . '*';
        $l[] = '✅ ' . ($ev['vencedor'] ?? '—') . ' — ' . $ev['pct_vencedor'] . '%'
             . ($ev['zebra'] ? ' 🐴' : '');
        $l[] = '_' . $ev['acertos'] . ' de ' . $ev['total']
             . ' acertaram_';
    }

    // A zebra só ganha legenda se apareceu alguma — explicar um símbolo que
    // não está na lista é ruído.
    foreach ($eventos as $ev) {
        if ($ev['zebra']) { $l[] = ''; $l[] = '🐴 _deu contra a maioria_'; break; }
    }
    return implode("\n", $l);
}

/**
 * "faltam 3h", "falta 1 dia", "encerrando" — o prazo do jeito que se fala.
 *
 * É o mesmo texto que o /apostas já usava antes e que o site mostra: dizer
 * "faltam 3h" responde o que uma data não responde — se dá pra deixar pra
 * depois. E o verbo concorda com o número: "faltam 1 dia" não existe.
 */
function apostasPrazoEmTexto(string $dataLimite): string
{
    try {
        $tz    = new DateTimeZone('America/Sao_Paulo');
        $fim   = new DateTime($dataLimite, $tz);
        $agora = new DateTime('now', $tz);
        if ($fim <= $agora) return 'encerrando';

        $d = $agora->diff($fim);
        if ($d->days > 0) return $d->days === 1 ? 'falta 1 dia' : 'faltam ' . $d->days . ' dias';
        if ($d->h > 0)    return 'faltam ' . $d->h . 'h';
        if ($d->i > 0)    return 'faltam ' . $d->i . ' min';
        return 'encerrando';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * As últimas apostas PAGAS: o que era, quem ganhou e quantos acertaram.
 *
 * Ordena por id decrescente porque a tabela não guarda quando o evento foi
 * encerrado — só o prazo, que é outra coisa: um evento de prazo antigo pode
 * ter sido pago hoje, e ordenar pelo prazo colocaria ele no fim da lista.
 *
 * @return array<int,array>
 */
function apostasPagasRecentes(PDO $pdo, int $limite = 10): array
{
    $limite = max(1, min(30, $limite));
    $st = $pdo->prepare("SELECT e.id, e.nome, e.data_limite, e.vencedor_opcao_id,
                                o.descricao AS vencedor
                           FROM eventos e
                      LEFT JOIN opcoes o ON o.id = e.vencedor_opcao_id
                          WHERE e.status = 'encerrada' AND e.vencedor_opcao_id IS NOT NULL
                          ORDER BY e.id DESC LIMIT " . $limite);
    $st->execute();
    $eventos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($eventos as &$ev) {
        $stO = $pdo->prepare("SELECT id FROM opcoes WHERE evento_id = ? ORDER BY id ASC");
        $stO->execute([(int)$ev['id']]);
        $ids = array_map('intval', array_column($stO->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));

        $porOpcao = apostasPalpitesPorOpcao($pdo, (int)$ev['id']);
        $contagem = [];
        foreach ($ids as $id) $contagem[$id] = $porOpcao[$id] ?? 0;
        $pct = apostasPercentuais($contagem);

        $venc = (int)$ev['vencedor_opcao_id'];
        $ev['total']            = array_sum($contagem);
        $ev['acertos']          = $contagem[$venc] ?? 0;
        $ev['pct_vencedor']     = $pct[$venc] ?? 0;
        // Quem tinha mais votos era o vencedor? É o que diz se a liga leu
        // certo — e é a graça de olhar o resultado depois.
        $ev['zebra']            = $ev['total'] > 0 && $ev['acertos'] < max($contagem);
    }
    unset($ev);

    return $eventos;
}
