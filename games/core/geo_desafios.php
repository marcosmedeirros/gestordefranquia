<?php
/**
 * ── OS DESAFIOS DO FBA GEO ───────────────────────────────────────────
 *
 * Duas famílias, com papéis diferentes de propósito:
 *
 *   MISSÕES DO DIA → moedas. Três por dia, iguais pra liga inteira, trocam
 *   à meia-noite. São metas que acontecem ENQUANTO se joga: a pessoa abre o
 *   jogo pra jogar e esbarra nelas.
 *
 *   CONQUISTAS → FBA Points. Permanentes e pagas UMA VEZ NA VIDA. São feitos
 *   difíceis de verdade, do tipo que se conta no grupo.
 *
 * ── POR QUE NENHUMA DELAS É "JOGUE N PARTIDAS" ───────────────────────
 *
 * Missão de repetição premia quem tem a tarde livre, não quem joga bem — e
 * o jogo já tem teto de moedas por dia justamente pra que ficar clicando não
 * pague. Toda missão aqui é sobre ACERTAR: chegar perto, emendar sequência,
 * conhecer lugar. É o mesmo motivo de não existir missão de "entre no jogo".
 *
 * ── POR QUE AS CONQUISTAS PAGAM POUCO EM FBA POINT ───────────────────
 *
 * Na loja, 10 moedas viram 1 FBA Point, e acertar uma aposta paga 75. FBA
 * Point é a moeda séria da liga — compra badge e slot. Uma conquista de Geo
 * pagando 25 vale 250 moedas: muito pra um jogo de adivinhar foto, pouco
 * perto de uma aposta certa. E como cada uma só paga uma vez, não existe
 * fábrica: o total que o Geo pode injetar na liga é fechado e conhecido —
 * a soma do catálogo, por pessoa, pra sempre.
 *
 * ── O QUE SEGURA O PAGAMENTO DOBRADO ─────────────────────────────────
 *
 * Um índice único em (user_id, chave, dia). O dia da conquista é fixo
 * ('0000-00-00'), então a segunda vez que ela for concluída esbarra no
 * índice e não paga. Nada de conferir antes e gravar depois: entre uma
 * coisa e outra cabem dois cliques.
 *
 * @see games/core/geo.php  quem chama, no fim de cada rodada
 */

require_once __DIR__ . '/geo.php';

/** O dia que as conquistas usam: elas não são do dia, são da vida. */
const GEO_DIA_SEMPRE = '0000-00-00';

/**
 * AS MISSÕES. Três são sorteadas por dia, iguais pra todo mundo.
 *
 * `teste` recebe o contexto da jogada e devolve true quando bateu.
 * @see geoContexto  o que vem dentro de $c
 */
function geoMissoes(): array
{
    return [
        'seq3'    => ['t' => 'Acerte 3 países seguidos',              'moedas' => 100,
                      'teste' => fn($c) => $c['modo'] === 'pais' && $c['seq'] >= 3],
        'seq5'    => ['t' => 'Acerte 5 países seguidos',              'moedas' => 200,
                      'teste' => fn($c) => $c['modo'] === 'pais' && $c['seq'] >= 5],
        'paises3' => ['t' => 'Acerte 3 países diferentes na mesma série', 'moedas' => 120,
                      'teste' => fn($c) => $c['modo'] === 'pais' && $c['paises'] >= 3],
        'perto500'=> ['t' => 'Chegue a menos de 500 km de um lugar',  'moedas' => 120,
                      'teste' => fn($c) => $c['km'] !== null && $c['km'] < 500],
        'perto100'=> ['t' => 'Chegue a menos de 100 km de um lugar',  'moedas' => 200,
                      'teste' => fn($c) => $c['km'] !== null && $c['km'] < 100],
        'rodada4k'=> ['t' => 'Faça 4.000 pontos numa rodada do mapa', 'moedas' => 150,
                      'teste' => fn($c) => $c['pts_rodada'] >= 4000],
        'partida10k'=>['t' => 'Termine uma partida de mapa com 10.000 pontos', 'moedas' => 150,
                      'teste' => fn($c) => $c['fim'] && $c['modo'] === 'mapa' && $c['pts_partida'] >= 10000],
        'mapa5'   => ['t' => 'Complete as 5 rodadas de uma partida no mapa', 'moedas' => 80,
                      'teste' => fn($c) => $c['fim'] && $c['modo'] === 'mapa' && $c['rodadas'] >= GEO_RODADAS_MAPA],
    ];
}

/** AS CONQUISTAS. Uma vez na vida, em FBA Points. */
function geoConquistas(): array
{
    return [
        'seq10'   => ['t' => 'Dez seguidos',      'd' => 'Acerte 10 países em sequência',
                      'pts' => 25, 'teste' => fn($c) => $c['modo'] === 'pais' && $c['seq'] >= 10],
        'seq15'   => ['t' => 'Quinze seguidos',   'd' => 'Acerte 15 países em sequência',
                      'pts' => 50, 'teste' => fn($c) => $c['modo'] === 'pais' && $c['seq'] >= 15],
        'mosca'   => ['t' => 'Na mosca',          'd' => 'Crave um lugar a menos de 10 km',
                      'pts' => 30, 'teste' => fn($c) => $c['km'] !== null && $c['km'] < 10],
        'rodada49'=> ['t' => 'Quase perfeito',    'd' => 'Faça 4.900 pontos numa única rodada',
                      'pts' => 20, 'teste' => fn($c) => $c['pts_rodada'] >= 4900],
        'partida20k'=>['t' => 'Volta ao mundo',   'd' => 'Termine uma partida de mapa com 20.000 pontos',
                      'pts' => 30, 'teste' => fn($c) => $c['fim'] && $c['modo'] === 'mapa' && $c['pts_partida'] >= 20000],
        'paises8' => ['t' => 'Poliglota',         'd' => 'Acerte 8 países diferentes na mesma série',
                      'pts' => 25, 'teste' => fn($c) => $c['modo'] === 'pais' && $c['paises'] >= 8],
    ];
}

/**
 * As três missões de hoje.
 *
 * Sorteadas PELA DATA, e não guardadas numa tabela: a mesma data dá sempre
 * as mesmas três, pra todo mundo, sem precisar de um cron que vire o dia
 * nem de uma linha por pessoa. Se o processo cair às 23h59, à meia-noite as
 * missões trocam do mesmo jeito.
 */
function geoMissoesDoDia(?string $dia = null): array
{
    $dia = $dia ?: date('Y-m-d');
    $chaves = array_keys(geoMissoes());
    sort($chaves);                       // ordem estável, não a do arquivo
    mt_srand(crc32('geo-missao-' . $dia));
    $sorteadas = [];
    while (count($sorteadas) < 3 && count($sorteadas) < count($chaves)) {
        $c = $chaves[mt_rand(0, count($chaves) - 1)];
        if (!in_array($c, $sorteadas, true)) $sorteadas[] = $c;
    }
    mt_srand();                          // devolve o acaso pro resto do request
    return $sorteadas;
}

/** Cria a tabela. O índice único é o que impede pagar duas vezes. */
function geoDesafiosTabela(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS geo_desafios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        chave VARCHAR(30) NOT NULL,
        tipo ENUM('missao','conquista') NOT NULL,
        dia DATE NOT NULL,
        moedas INT NOT NULL DEFAULT 0,
        fba_points INT NOT NULL DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_geo_desafio (user_id, chave, dia),
        INDEX idx_geo_desafio_user (user_id, tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $feito = true;
}

/**
 * O retrato da jogada que acabou de acontecer.
 *
 * Tudo vem do BANCO e não do que a tela mandou: sequência, distância e
 * pontos são conferidos contra as rodadas gravadas, que é onde a verdade
 * da partida mora.
 */
function geoContexto(PDO $pdo, array $partida, array $resolvida): array
{
    $st = $pdo->prepare('SELECT lugar, acertou, km, pontos FROM geo_rodadas
                          WHERE partida_id = ? ORDER BY n');
    $st->execute([(int)$partida['id']]);
    $rodadas = $st->fetchAll(PDO::FETCH_ASSOC);

    $lugares = geoLugares();
    $paises = [];
    foreach ($rodadas as $r) {
        if (!empty($r['acertou'])) {
            $p = $lugares[(int)$r['lugar']]['pais'] ?? '';
            if ($p !== '') $paises[$p] = true;
        }
    }

    return [
        'modo'        => (string)$partida['modo'],
        'seq'         => (int)array_sum(array_column($rodadas, 'acertou')),
        'paises'      => count($paises),
        'km'          => isset($resolvida['km']) && $resolvida['km'] !== null
                            ? (int)$resolvida['km'] : null,
        'pts_rodada'  => (int)($resolvida['pontos'] ?? 0),
        'pts_partida' => (int)array_sum(array_column($rodadas, 'pontos')),
        'rodadas'     => count($rodadas),
        'fim'         => !empty($resolvida['fim']),
    ];
}

/**
 * Confere tudo e paga o que bateu.
 *
 * Chamada depois de CADA rodada respondida. O pagamento é feito por fora do
 * teto diário das partidas: o teto existe pra que repetir partida não
 * imprima moeda, e missão não é repetição — são três por dia e acabou.
 *
 * @return array lista do que foi conquistado agora, pra tela comemorar
 */
function geoConferirDesafios(PDO $pdo, array $partida, array $resolvida): array
{
    geoDesafiosTabela($pdo);
    $uid = (int)$partida['user_id'];
    if (!$uid) return [];

    $c = geoContexto($pdo, $partida, $resolvida);
    $hoje = date('Y-m-d');
    $ganhos = [];

    $marca = $pdo->prepare('INSERT IGNORE INTO geo_desafios
                            (user_id, chave, tipo, dia, moedas, fba_points) VALUES (?,?,?,?,?,?)');

    foreach (geoMissoesDoDia($hoje) as $chave) {
        $m = geoMissoes()[$chave] ?? null;
        if (!$m || !($m['teste'])($c)) continue;
        $marca->execute([$uid, $chave, 'missao', $hoje, (int)$m['moedas'], 0]);
        if ($marca->rowCount() === 0) continue;       // já tinha ganhado hoje
        $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')
            ->execute([(int)$m['moedas'], $uid]);
        $ganhos[] = ['tipo' => 'missao', 'texto' => $m['t'], 'moedas' => (int)$m['moedas']];
    }

    foreach (geoConquistas() as $chave => $q) {
        if (!($q['teste'])($c)) continue;
        $marca->execute([$uid, $chave, 'conquista', GEO_DIA_SEMPRE, 0, (int)$q['pts']]);
        if ($marca->rowCount() === 0) continue;       // já é dele desde antes
        $pdo->prepare('UPDATE games_usuarios SET fba_points = COALESCE(fba_points,0) + ? WHERE id = ?')
            ->execute([(int)$q['pts'], $uid]);
        $ganhos[] = ['tipo' => 'conquista', 'texto' => $q['t'], 'pts' => (int)$q['pts']];
    }

    return $ganhos;
}

/** O que este GM já concluiu: as missões de hoje e as conquistas da vida. */
function geoMeusDesafios(PDO $pdo, int $uid): array
{
    geoDesafiosTabela($pdo);
    $st = $pdo->prepare("SELECT chave, tipo FROM geo_desafios
                          WHERE user_id = ? AND (dia = ? OR tipo = 'conquista')");
    $st->execute([$uid, date('Y-m-d')]);
    $feitos = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $feitos[$r['chave']] = true;
    return $feitos;
}
