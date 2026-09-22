<?php
/**
 * YAN RULE / STEPIEN RULE — dois anos seguidos sem escolha de 1ª rodada.
 *
 * ROOKIE, art. 31: "Não é permitida nenhuma troca que deixe a franquia sem
 * nenhuma escolha de 1ª rodada (própria ou de terceiros) por 2 (dois) anos
 * seguidos."
 *
 * DUAS COISAS QUE O TEXTO DIZ E QUE É FÁCIL ERRAR:
 *
 *   PRÓPRIA OU DE TERCEIROS. O que conta é TER uma escolha naquele ano, não
 *   ter a sua. Quem vendeu a própria de 2028 mas comprou a de outro time no
 *   mesmo ano está em dia. A conferência que já existia no MCP olha só a
 *   própria — serve pra dizer "o patrimônio saiu de casa", que é outra
 *   pergunta, e por isso não foi reaproveitada aqui.
 *
 *   O BURACO É ENTRE ANOS, NÃO NO FIM DA LISTA. Com picks em 2027, 2028 e
 *   2031, os anos vazios são 2029 e 2030 — dois seguidos, proibido. Bastaria
 *   uma em 2029 ou 2030. Já não ter nada depois do último ano gerado não é
 *   violação: aqueles anos ainda não existem pra ninguém.
 *
 * Só a 1ª rodada entra na conta. A 2ª não muda nada, e o edital não a cita.
 *
 * A regra vale SÓ NA ROOKIE por enquanto — é a única cujo edital a escreve
 * nesses termos. A ELITE tem a sua (art. 27), com outro desenho, e entra aqui
 * no dia em que for pedida.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/picks_usadas.php';

function stepienLigaUsa(?string $liga): bool
{
    return strtoupper(trim((string)$liga)) === 'ROOKIE';
}

/**
 * Os anos que entram na conta: aqueles em que a liga tem picks de 1ª rodada.
 *
 * Sai do que existe, e não de um intervalo fixo, porque a geração de picks é
 * uma janela rolante — inventar anos além dela acusaria todo mundo de violar
 * a regra no futuro que ainda não foi criado.
 */
function stepienAnosDaLiga(PDO $pdo, string $league): array
{
    static $cache = [];
    $league = strtoupper(trim($league));
    if (isset($cache[$league])) return $cache[$league];

    try {
        $st = $pdo->prepare("SELECT DISTINCT CAST(p.season_year AS UNSIGNED) AS ano
                               FROM picks p
                               JOIN teams t ON t.id = p.original_team_id
                              WHERE t.league = ? AND p.round = '1'
                           ORDER BY ano ASC");
        $st->execute([$league]);
        $anos = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        /* ANO JÁ DRAFTADO SAI DA CONTA. Não ter pick num draft que já
           aconteceu não é ficar sem escolha no futuro — é ter escolhido. Sem
           este corte, o ano corrente logo depois do draft entraria como vazio
           e acusaria de violação quem apenas usou a própria pick.
           O sinal é a pick marcada como usada, que é o mesmo que a Trade
           Machine usa e que já resolve swap. */
        $usadas = picksJaUsadas($pdo);
        if ($usadas) {
            $ph = implode(',', array_fill(0, count($usadas), '?'));
            $st = $pdo->prepare("SELECT DISTINCT CAST(p.season_year AS UNSIGNED) AS ano
                                   FROM picks p JOIN teams t ON t.id = p.original_team_id
                                  WHERE t.league = ? AND p.round = '1' AND p.id IN ({$ph})");
            $st->execute(array_merge([$league], array_keys($usadas)));
            $draftados = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $anos = array_values(array_diff($anos, $draftados));
        }
    } catch (Throwable $e) {
        error_log('[stepien] anos: ' . $e->getMessage());
        $anos = [];
    }
    return $cache[$league] = $anos;
}

/**
 * Em que anos o time tem escolha de 1ª rodada, depois de aplicar a troca.
 *
 * @param array $saem  ids de picks que ele entrega
 * @param array $entram ids de picks que ele recebe
 * @return array<int,bool> ano => true
 */
function stepienAnosComPick(PDO $pdo, int $teamId, array $saem = [], array $entram = []): array
{
    $saem   = array_flip(array_map('intval', $saem));
    $entram = array_values(array_unique(array_map('intval', $entram)));

    $anos = [];
    try {
        $usadas = picksJaUsadas($pdo);

        $st = $pdo->prepare("SELECT id, CAST(season_year AS UNSIGNED) AS ano
                               FROM picks WHERE team_id = ? AND round = '1'");
        $st->execute([$teamId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $id = (int)$p['id'];
            if (isset($saem[$id])) continue;          // vai embora nesta troca
            if (isset($usadas[$id])) continue;        // já escolheu com ela
            $anos[(int)$p['ano']] = true;
        }

        // As que chegam, mesmo que hoje sejam de outro time.
        if ($entram) {
            $ph = implode(',', array_fill(0, count($entram), '?'));
            $st = $pdo->prepare("SELECT id, CAST(season_year AS UNSIGNED) AS ano
                                   FROM picks WHERE id IN ({$ph}) AND round = '1'");
            $st->execute($entram);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                if (isset($usadas[(int)$p['id']])) continue;
                $anos[(int)$p['ano']] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('[stepien] anos com pick: ' . $e->getMessage());
    }
    return $anos;
}

/**
 * O primeiro par de anos seguidos sem escolha, ou null se está tudo certo.
 *
 * @return array|null ['de' => ano, 'ate' => ano]
 */
function stepienBuraco(array $anosComPick, array $anosDaLiga): ?array
{
    if (count($anosDaLiga) < 2) return null;

    /* A JANELA INTEIRA CONTA, inclusive o fim dela.
       A primeira versão parava no último ano em que o time tinha alguma
       coisa, pra não acusar quem só não tem a pick mais distante. Mas isso
       abria o buraco maior de todos: quem vendesse TODAS as futuras passava
       limpo, que é exatamente o que a regra existe pra impedir. Como as picks
       são geradas para a liga toda de uma vez, ficar sem dois anos seguidos
       no fim da janela é ficar sem dois anos seguidos. */
    $vazios = 0;
    $inicio = null;
    foreach ($anosDaLiga as $ano) {
        if (isset($anosComPick[$ano])) { $vazios = 0; $inicio = null; continue; }
        if ($inicio === null) $inicio = $ano;
        $vazios++;
        if ($vazios >= 2) return ['de' => $inicio, 'ate' => $ano];
    }
    return null;
}

/**
 * Esta troca deixa o time sem 1ª rodada por dois anos seguidos?
 *
 * @return string|null a explicação pra tela, ou null quando pode
 */
function stepienConferir(PDO $pdo, ?string $league, int $teamId, array $saem, array $entram, string $nomeDoTime = ''): ?string
{
    if (!stepienLigaUsa($league) || $teamId <= 0) return null;
    if (!$saem) return null;   // só recebendo pick, ninguém fica sem nada

    $anosDaLiga = stepienAnosDaLiga($pdo, (string)$league);
    if (!$anosDaLiga) return null;

    $depois  = stepienAnosComPick($pdo, $teamId, $saem, $entram);
    $buraco  = stepienBuraco($depois, $anosDaLiga);
    if (!$buraco) return null;

    /* Se o buraco JÁ EXISTIA antes da troca, ela não é a culpada. Barrar aqui
       deixaria o time preso: sem poder negociar nada até consertar sozinho
       uma situação que uma troca anterior (ou uma punição) criou. A regra é
       sobre a transação — "não é permitida nenhuma troca QUE DEIXE" —, então
       o que conta é a troca ter causado o buraco. */
    $antes = stepienAnosComPick($pdo, $teamId);
    if (stepienBuraco($antes, $anosDaLiga)) return null;

    $quem = $nomeDoTime !== '' ? $nomeDoTime : 'O time';
    return "{$quem} ficaria sem escolha de 1ª rodada em {$buraco['de']} e {$buraco['ate']} — "
         . 'dois anos seguidos, o que a Yan Rule (Stepien) não permite. '
         . "Inclua uma pick de 1ª rodada de {$buraco['de']} ou {$buraco['ate']} na troca.";
}
