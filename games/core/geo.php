<?php
/**
 * ── FBA GEO: AS REGRAS ───────────────────────────────────────────────
 *
 * O GeoGuessr da liga. Aparece a foto de um lugar e você diz onde é — ou o
 * país, na múltipla escolha, ou o ponto exato, clicando no mapa.
 *
 * Dois modos, e eles medem coisas diferentes:
 *
 *   PAÍS   — sequência: acertou, vem outra; errou, acabou. O ranking é a
 *            maior sequência que alguém já fez. É o modo de "máximo de
 *            acertos" que o Marcos pediu, e o formato que faz a pessoa
 *            jogar de novo — perder dói mais quando a conta era alta.
 *   MAPA   — cinco rodadas, e cada uma paga por PROXIMIDADE: 5.000 pontos
 *            no ponto exato, caindo com a distância. Aqui não existe errar,
 *            existe errar por quanto.
 *
 * ── A PARTIDA MORA NO BANCO, NÃO NA SESSÃO ───────────────────────────
 *
 * O FBA Draft ensinou isso do jeito difícil: a trava que vive na sessão é a
 * trava que some. Aqui cada rodada nasce no banco com a resposta certa
 * guardada, e o palpite é conferido contra ela — não contra nada que o
 * navegador mande. Recarregar a página, abrir em duas abas ou mexer no
 * console não muda uma vírgula do que o servidor já decidiu.
 *
 * ── DE ONDE VÊM AS FOTOS ─────────────────────────────────────────────
 *
 * Wikidata + Wikimedia Commons, de graça e sem chave (@see
 * geo_lugares_cli.php). O crédito do autor e a licença aparecem assim que a
 * rodada termina — é exigência das licenças CC, e é justo com quem fotografou.
 */

require_once __DIR__ . '/../../backend/db.php';

/** Quantas rodadas tem uma partida no mapa. No país, vai até errar. */
const GEO_RODADAS_MAPA = 5;

/** Quantas opções de país aparecem na múltipla escolha. */
const GEO_OPCOES = 6;

/**
 * Quanto paga, e o teto do dia.
 *
 * O jogo é livre e as fotos são de graça, então o que segura a economia é o
 * teto: passadas essas partidas no dia, dá pra continuar jogando — e
 * entrando no ranking —, mas sem moeda. Sem isso, quem tem a tarde livre
 * imprime dinheiro à base de clique.
 */
const GEO_MOEDA_ACERTO   = 3;     // por acerto no modo país
const GEO_MOEDA_MAPA_MIL = 1;     // por 1.000 pontos no modo mapa
const GEO_PREMIADAS_DIA  = 3;

/** Os lugares do baralho. Carregado uma vez por request. */
function geoLugares(): array
{
    static $l = null;
    if ($l !== null) return $l;
    $arq = __DIR__ . '/../data/geo_lugares.php';
    $l = is_file($arq) ? (array)require $arq : [];
    return $l;
}

function geoTabelas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS geo_partidas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        modo ENUM('pais','mapa') NOT NULL,
        status ENUM('jogando','fim') NOT NULL DEFAULT 'jogando',
        acertos INT NOT NULL DEFAULT 0,
        pontos INT NOT NULL DEFAULT 0,
        moedas INT NOT NULL DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        fim_em DATETIME NULL,
        INDEX idx_geo_user (user_id, modo),
        INDEX idx_geo_rank (modo, acertos),
        INDEX idx_geo_dia (user_id, criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS geo_rodadas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        partida_id INT NOT NULL,
        n INT NOT NULL,
        lugar INT NOT NULL,
        opcoes TEXT NULL,
        palpite VARCHAR(80) NULL,
        palpite_lat DECIMAL(9,5) NULL,
        palpite_lon DECIMAL(9,5) NULL,
        acertou TINYINT(1) NULL,
        km INT NULL,
        pontos INT NOT NULL DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_geo_rodada (partida_id, n)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $feito = true;
}

/** A partida em andamento deste GM, ou null. */
function geoPartidaAberta(PDO $pdo, int $uid): ?array
{
    geoTabelas($pdo);
    $st = $pdo->prepare("SELECT * FROM geo_partidas WHERE user_id = ? AND status = 'jogando'
                       ORDER BY id DESC LIMIT 1");
    $st->execute([$uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Abre uma partida. Fecha a anterior, se tiver ficado pendurada. */
function geoAbrirPartida(PDO $pdo, int $uid, string $modo): ?array
{
    geoTabelas($pdo);
    $modo = $modo === 'mapa' ? 'mapa' : 'pais';
    $pdo->prepare("UPDATE geo_partidas SET status = 'fim', fim_em = NOW()
                    WHERE user_id = ? AND status = 'jogando'")->execute([$uid]);
    $pdo->prepare('INSERT INTO geo_partidas (user_id, modo) VALUES (?,?)')->execute([$uid, $modo]);
    $id = (int)$pdo->lastInsertId();
    geoSortearRodada($pdo, $id, 1, $modo);
    $st = $pdo->prepare('SELECT * FROM geo_partidas WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Sorteia a próxima rodada e grava.
 *
 * O LUGAR NÃO REPETE DENTRO DA PARTIDA, e as opções erradas saem de perto:
 * país do mesmo continente quando dá, porque "Chile ou Noruega?" não é
 * pergunta — a graça é hesitar entre dois lugares parecidos.
 */
function geoSortearRodada(PDO $pdo, int $partidaId, int $n, string $modo): void
{
    $lugares = geoLugares();
    if (!$lugares) return;

    $st = $pdo->prepare('SELECT lugar FROM geo_rodadas WHERE partida_id = ?');
    $st->execute([$partidaId]);
    $usados = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

    $tentativas = 0;
    do { $i = array_rand($lugares); $tentativas++; }
    while (isset($usados[$i]) && $tentativas < 60);

    $opcoes = null;
    if ($modo === 'pais') {
        $certo = (string)$lugares[$i]['pais'];
        $outros = [];
        foreach ($lugares as $l) {
            $p = (string)$l['pais'];
            if ($p !== $certo) $outros[$p] = true;
        }
        $outros = array_keys($outros);
        shuffle($outros);
        $opcoes = array_slice($outros, 0, GEO_OPCOES - 1);
        $opcoes[] = $certo;
        shuffle($opcoes);
        $opcoes = json_encode($opcoes, JSON_UNESCAPED_UNICODE);
    }

    $pdo->prepare('INSERT IGNORE INTO geo_rodadas (partida_id, n, lugar, opcoes) VALUES (?,?,?,?)')
        ->execute([$partidaId, $n, (int)$i, $opcoes]);
}

/** A rodada que está em pé (sem palpite), ou null. */
function geoRodadaAtual(PDO $pdo, int $partidaId): ?array
{
    $st = $pdo->prepare('SELECT * FROM geo_rodadas WHERE partida_id = ? AND acertou IS NULL AND km IS NULL
                       ORDER BY n ASC LIMIT 1');
    $st->execute([$partidaId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $lugares = geoLugares();
    $r['dados'] = $lugares[(int)$r['lugar']] ?? null;
    return $r['dados'] ? $r : null;
}

/** A distância entre dois pontos da Terra, em quilômetros. */
function geoDistanciaKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Os pontos de um palpite no mapa.
 *
 * 5.000 no alvo e decaimento exponencial: 4.000 a 150 km, 2.500 a 600 km,
 * 1.000 a 1.800 km, quase nada do outro lado do mundo. A curva é a mesma
 * ideia do GeoGuessr — acertar a cidade vale muito mais que acertar o
 * continente, senão todo mundo clica no meio do país e pronto.
 */
function geoPontosDoKm(float $km): int
{
    return (int)round(5000 * exp(-$km / 2000));
}

/** Registra o palpite e devolve a rodada resolvida. */
function geoResponder(PDO $pdo, array $partida, array $rodada, array $palpite): array
{
    $lugar = $rodada['dados'];
    $saida = ['acertou' => false, 'km' => null, 'pontos' => 0, 'lugar' => $lugar];

    if ($partida['modo'] === 'pais') {
        $saida['acertou'] = isset($palpite['pais'])
            && mb_strtolower(trim((string)$palpite['pais'])) === mb_strtolower((string)$lugar['pais']);
        $saida['pontos'] = $saida['acertou'] ? 1 : 0;
        $pdo->prepare('UPDATE geo_rodadas SET palpite = ?, acertou = ?, pontos = ? WHERE id = ?')
            ->execute([mb_substr((string)($palpite['pais'] ?? ''), 0, 80),
                       $saida['acertou'] ? 1 : 0, $saida['pontos'], (int)$rodada['id']]);
    } else {
        $lat = (float)($palpite['lat'] ?? 0);
        $lon = (float)($palpite['lon'] ?? 0);
        $km  = geoDistanciaKm($lat, $lon, (float)$lugar['lat'], (float)$lugar['lon']);
        $saida['km'] = (int)round($km);
        $saida['pontos'] = geoPontosDoKm($km);
        $saida['acertou'] = $km <= 50;      // "acertou" no mapa é chegar perto
        $pdo->prepare('UPDATE geo_rodadas SET palpite_lat = ?, palpite_lon = ?, km = ?, pontos = ?, acertou = ?
                        WHERE id = ?')
            ->execute([$lat, $lon, $saida['km'], $saida['pontos'], $saida['acertou'] ? 1 : 0, (int)$rodada['id']]);
    }

    /* O placar da partida vem SEMPRE da soma das rodadas no banco: somar na
       mão abriria espaço pra uma rodada contar duas vezes. */
    $st = $pdo->prepare('SELECT COALESCE(SUM(acertou),0) a, COALESCE(SUM(pontos),0) p, COUNT(*) n
                           FROM geo_rodadas WHERE partida_id = ?');
    $st->execute([(int)$partida['id']]);
    $soma = $st->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare('UPDATE geo_partidas SET acertos = ?, pontos = ? WHERE id = ?')
        ->execute([(int)$soma['a'], (int)$soma['p'], (int)$partida['id']]);

    /* Acabou? No país, ao errar. No mapa, na quinta rodada. */
    $fim = $partida['modo'] === 'pais' ? !$saida['acertou'] : (int)$soma['n'] >= GEO_RODADAS_MAPA;
    if ($fim) {
        $moedas = geoPagar($pdo, (int)$partida['user_id'], (string)$partida['modo'],
                           (int)$soma['a'], (int)$soma['p']);
        $pdo->prepare("UPDATE geo_partidas SET status = 'fim', fim_em = NOW(), moedas = ? WHERE id = ?")
            ->execute([$moedas, (int)$partida['id']]);
        $saida['fim'] = true;
        $saida['moedas'] = $moedas;
    } else {
        geoSortearRodada($pdo, (int)$partida['id'], (int)$soma['n'] + 1, (string)$partida['modo']);
        $saida['fim'] = false;
    }
    $saida['acertos_total'] = (int)$soma['a'];
    $saida['pontos_total']  = (int)$soma['p'];
    return $saida;
}

/**
 * Paga o que a partida valeu, respeitando o teto do dia.
 *
 * @return int quanto entrou na conta (zero quando o teto já passou)
 */
function geoPagar(PDO $pdo, int $uid, string $modo, int $acertos, int $pontos): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM geo_partidas
                          WHERE user_id = ? AND status = 'fim' AND moedas > 0
                            AND DATE(criado_em) = CURDATE()");
    $st->execute([$uid]);
    if ((int)$st->fetchColumn() >= GEO_PREMIADAS_DIA) return 0;

    $moedas = $modo === 'pais'
        ? $acertos * GEO_MOEDA_ACERTO
        : (int)floor($pontos / 1000) * GEO_MOEDA_MAPA_MIL;
    if ($moedas <= 0) return 0;

    try {
        $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')
            ->execute([$moedas, $uid]);
    } catch (Throwable $e) {
        error_log('[geo] pagar: ' . $e->getMessage());
        return 0;
    }
    return $moedas;
}

/**
 * O ranking de um modo: o melhor resultado de cada GM, e não a soma — senão
 * quem joga mais ganha de quem joga melhor, que é o oposto da graça.
 */
function geoRanking(PDO $pdo, string $modo, int $limite = 10): array
{
    geoTabelas($pdo);
    $campo = $modo === 'mapa' ? 'pontos' : 'acertos';
    $st = $pdo->prepare("SELECT p.user_id, u.name AS gm, MAX(p.{$campo}) AS melhor, COUNT(*) AS partidas
                           FROM geo_partidas p LEFT JOIN users u ON u.id = p.user_id
                          WHERE p.modo = ? AND p.status = 'fim'
                       GROUP BY p.user_id, u.name
                       ORDER BY melhor DESC, partidas ASC
                          LIMIT " . max(1, min(50, $limite)));
    $st->execute([$modo]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** O melhor que este GM já fez em cada modo. */
function geoMeuRecorde(PDO $pdo, int $uid): array
{
    geoTabelas($pdo);
    $st = $pdo->prepare("SELECT modo, MAX(acertos) a, MAX(pontos) p, COUNT(*) n
                           FROM geo_partidas WHERE user_id = ? AND status = 'fim' GROUP BY modo");
    $st->execute([$uid]);
    $out = ['pais' => ['a' => 0, 'p' => 0, 'n' => 0], 'mapa' => ['a' => 0, 'p' => 0, 'n' => 0]];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['modo']] = ['a' => (int)$r['a'], 'p' => (int)$r['p'], 'n' => (int)$r['n']];
    }
    return $out;
}

/** Quantas partidas premiadas ainda cabem hoje. */
function geoPremiadasRestantes(PDO $pdo, int $uid): int
{
    geoTabelas($pdo);
    $st = $pdo->prepare("SELECT COUNT(*) FROM geo_partidas
                          WHERE user_id = ? AND status = 'fim' AND moedas > 0
                            AND DATE(criado_em) = CURDATE()");
    $st->execute([$uid]);
    return max(0, GEO_PREMIADAS_DIA - (int)$st->fetchColumn());
}

/** A URL da foto no Commons, no tamanho pedido. */
function geoFoto(string $arquivo, int $largura = 1100): string
{
    return 'https://commons.wikimedia.org/wiki/Special:FilePath/'
         . rawurlencode($arquivo) . '?width=' . $largura;
}

/* ════════════════════════════════════════════════════════════════════
   ── O STREET VIEW: ANDAR NO LUGAR EM VEZ DE OLHAR A FOTO ────────────
   ════════════════════════════════════════════════════════════════════

   Pedido do Marcos (14/10/2026): "não tem como colocar o google maps pra
   andar no lugar de ser só uma foto". Tem, e sem romper a regra do grátis —
   mas por um caminho só.

   O QUE É PAGO E O QUE NÃO É. O Street View que a maioria dos clones de
   GeoGuessr usa é o da Maps JavaScript API, cobrado por panorama carregado
   desde que o crédito mensal do Google acabou (março de 2025). A MAPS EMBED
   API faz o mesmo panorama, navegável, dentro de um <iframe>: "o uso do Maps
   Embed está disponível sem custos financeiros", sem limite de QPS nem de
   chamadas por dia. É essa que o jogo usa.

   O QUE ELA EXIGE: uma chave. "Para usar a API Maps Embed, é necessário
   incluir uma chave de API em todas as solicitações" — e chave é da conta
   do Google Cloud do Marcos, não minha. Sem chave configurada, nada quebra:
   geoRua() devolve null e a rodada segue com a foto do Commons, como hoje.

   A CHAVE VAI APARECER NO HTML, e isso é normal numa chave de Embed: quem
   protege não é o segredo, é a restrição por referenciador (HTTP referrer)
   no console do Google — só fbabrasil.com.br pode usá-la.

   @see geo_rua_cli.php  quem marca os lugares que têm panorama
*/

/** A chave da Maps Embed API, ou string vazia se ninguém configurou. */
function geoChaveMaps(): string
{
    static $k = null;
    if ($k !== null) return $k;
    $k = trim((string)getenv('GOOGLE_MAPS_KEY'));
    if ($k === '') {
        try {
            require_once __DIR__ . '/../../backend/helpers.php';
            $cfg = loadConfig();
            $k = trim((string)($cfg['google']['maps_key'] ?? ''));
        } catch (Throwable $e) {
            $k = '';
        }
    }
    return $k;
}

/**
 * O panorama navegável deste lugar, ou null quando não dá.
 *
 * NÃO BASTA TER CHAVE: o lugar precisa estar marcado como coberto (`rua`).
 * O Street View não cobre o mundo todo — e uma rodada que abre numa tela
 * cinza dizendo "sem imagens aqui" é pior que a foto. Quem marca é o
 * geo_rua_cli.php, consultando o metadata do Google (que também é de graça)
 * uma vez, fora do jogo; em partida ninguém pergunta nada a ninguém.
 */
function geoRua(array $lugar): ?string
{
    if (empty($lugar['rua'])) return null;
    $chave = geoChaveMaps();
    if ($chave === '') return null;

    return 'https://www.google.com/maps/embed/v1/streetview?' . http_build_query([
        'key'      => $chave,
        'location' => $lugar['lat'] . ',' . $lugar['lon'],
        'heading'  => (int)($lugar['rua_dir'] ?? 0),
        'pitch'    => 0,
        'fov'      => 90,
        'language' => 'pt-BR',
    ]);
}
