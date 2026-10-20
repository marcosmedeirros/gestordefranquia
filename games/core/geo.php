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
const GEO_OPCOES = 8;

/**
 * ── O SORTEIO LEMBRA DAS PARTIDAS ANTERIORES ────────────────────────
 *
 * O lugar nunca repetia DENTRO da partida, mas entre uma partida e a
 * seguinte o baralho era embaralhado do zero — e GM que joga três
 * seguidas via o mesmo lugar de novo. Relatado pela liga em 19/10/2026.
 *
 * Não era azar, era a conta: no modo país o que se reconhece é o PAÍS, e
 * países jogáveis são 78 (os 552 lugares com panorama se espremem neles).
 * Cinco rodadas por partida, duas partidas seguidas, dez sorteios em 78
 * países: pelo paradoxo do aniversário, ~44% de chance de repetir. Ou
 * seja, quase uma em cada duas sessões mostrava país repetido.
 *
 * As duas memórias têm tamanhos diferentes porque os baralhos têm:
 * 60 lugares de 552 ainda deixam 492 na mesa; 25 países de 78 deixam 53.
 * Guardar mais que isso começaria a estreitar o jogo em vez de variá-lo.
 */
const GEO_MEMORIA_LUGARES = 60;
const GEO_MEMORIA_PAISES  = 25;

/**
 * Quanto paga, e o teto do dia.
 *
 * O jogo é livre e as fotos são de graça, então o que segura a economia é o
 * teto: passadas essas partidas no dia, dá pra continuar jogando — e
 * entrando no ranking —, mas sem moeda. Sem isso, quem tem a tarde livre
 * imprime dinheiro à base de clique.
 */
const GEO_MOEDA_ACERTO   = 50;    // por acerto no modo país
/* OS DOIS MODOS TEM QUE PAGAR PARECIDO, senao um deles vira enfeite. A
   regua: no pais, 50 por acerto; no mapa, 1 a cada 100 pontos — ou seja,
   50 numa rodada cravada no alvo. Partida perfeita paga 250 nos dois. */
const GEO_MOEDA_MAPA_CEM = 1;     // por 100 pontos no modo mapa
const GEO_PREMIADAS_DIA  = 3;

/**
 * ── O RELÓGIO DA RODADA ──────────────────────────────────────────────
 *
 * Dois minutos pra decidir (pedido do Marcos, 14/10/2026). O relógio é do
 * SERVIDOR: a rodada nasce com hora no banco e o navegador só desenha a
 * contagem. Relógio que vive no JavaScript é relógio que o console para.
 *
 * Estourou, a rodada se resolve sozinha no próximo carregamento — e por
 * isso quem fecha a aba no meio não volta depois pra responder com calma.
 */
const GEO_SEGUNDOS = 120;

/** Os lugares do baralho. Carregado uma vez por request. */
function geoLugares(): array
{
    static $l = null;
    if ($l !== null) return $l;
    $arq = __DIR__ . '/../data/geo_lugares.php';
    $l = is_file($arq) ? (array)require $arq : [];
    return $l;
}

/**
 * ── SÓ LUGAR ONDE DÁ PRA ANDAR ──────────────────────────────────────
 *
 * Pedido do Marcos (17/10/2026): "no geo não quero nenhum lugar com
 * foto, somente street view pra andar".
 *
 * A foto parada e o panorama são dois jogos diferentes. Na foto você
 * olha o que o fotógrafo escolheu mostrar e adivinha; no panorama você
 * vira a cabeça, anda até a esquina, lê a placa, vê de que lado os
 * carros andam. O segundo é o GeoGuessr, e é o que a liga quer jogar —
 * a foto era o que dava pra fazer enquanto o Street View não entrava.
 *
 * AS CHAVES SÃO AS DO BARALHO INTEIRO. `geo_rodadas.lugar` guarda o
 * índice em geoLugares(), e array_filter preserva chave: filtrar aqui
 * não desloca índice nenhum, e as rodadas antigas continuam achando o
 * lugar delas — inclusive as que foram foto, que seguem desenhando a
 * foto. Trocar o baralho não reescreve o passado.
 */
function geoLugaresJogaveis(): array
{
    static $j = null;
    if ($j !== null) return $j;
    $j = array_filter(geoLugares(), fn($l) => !empty($l['rua_pano']));
    /* Sem nenhum panorama — chave do Maps fora do ar, arquivo de lugares
       não marcado — o jogo volta pro baralho inteiro em vez de não ter
       rodada nenhuma. Tela com foto é pior que o combinado; tela vazia é
       pior que as duas. */
    if (!$j) $j = geoLugares();
    return $j;
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
        teste TINYINT(1) NOT NULL DEFAULT 0,
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
    /* A COLUNA `teste` É NOVA, e a tabela já existe em produção desde
       antes dela: o CREATE acima só vale pra banco limpo. Sem este
       ALTER o jogo quebraria no primeiro INSERT. */
    try {
        if (!$pdo->query("SHOW COLUMNS FROM geo_partidas LIKE 'teste'")->fetch()) {
            $pdo->exec("ALTER TABLE geo_partidas ADD COLUMN teste TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (Throwable $e) {
        error_log('[geo] coluna teste: ' . $e->getMessage());
    }
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
function geoAbrirPartida(PDO $pdo, int $uid, string $modo, bool $teste = false): ?array
{
    geoTabelas($pdo);
    $modo = $modo === 'mapa' ? 'mapa' : 'pais';
    /* A ANTERIOR SE FECHA PAGANDO. Começar outra partida é uma forma de
       desistir, e desistir com acerto no bolso tem que valer o acerto. */
    if ($pendurada = geoPartidaAberta($pdo, $uid)) geoEncerrar($pdo, $pendurada);
    $pdo->prepare('INSERT INTO geo_partidas (user_id, modo, teste) VALUES (?,?,?)')
        ->execute([$uid, $modo, $teste ? 1 : 0]);
    $id = (int)$pdo->lastInsertId();
    geoSortearRodada($pdo, $id, 1, $modo);
    $st = $pdo->prepare('SELECT * FROM geo_partidas WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * OS LUGARES QUE ESTE GM VIU POR ÚLTIMO, do mais recente pro mais antigo.
 *
 * Olha por JOGADOR, e não por partida: é o jogador que reclama de ver o
 * mesmo lugar duas vezes seguidas, e pra ele a partida nova não é um
 * começo limpo. Partida de teste entra junto — quem testou acabou de ver
 * o lugar do mesmo jeito.
 *
 * @return int[] índices em geoLugares()
 */
function geoRodadasRecentes(PDO $pdo, int $partidaId, int $quantas): array
{
    $st = $pdo->prepare('SELECT user_id FROM geo_partidas WHERE id = ?');
    $st->execute([$partidaId]);
    $uid = (int)$st->fetchColumn();
    if ($uid <= 0) return [];

    $st = $pdo->prepare('SELECT r.lugar FROM geo_rodadas r
                           JOIN geo_partidas p ON p.id = r.partida_id
                          WHERE p.user_id = ?
                       ORDER BY r.id DESC LIMIT ' . max(1, $quantas));
    $st->execute([$uid]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Sorteia a próxima rodada e grava.
 *
 * O LUGAR NÃO REPETE, nem dentro da partida nem nas anteriores deste GM
 * (@see GEO_MEMORIA_LUGARES), e as opções erradas saem de perto:
 * país do mesmo continente quando dá, porque "Chile ou Noruega?" não é
 * pergunta — a graça é hesitar entre dois lugares parecidos.
 */
function geoSortearRodada(PDO $pdo, int $partidaId, int $n, string $modo): void
{
    /* O SORTEIO SAI DO BARALHO JOGÁVEL; as opções erradas, do baralho
       inteiro. Se os dois saíssem do mesmo lugar, os 60 países que não
       têm panorama sumiriam também da lista de alternativas — e o jogo
       ficaria mais estreito do que precisa. Errar entre oito países do
       mundo todo é a graça; a cobertura do Google é limitação de quem
       fotografa, não da pergunta. @see geoLugaresJogaveis */
    $lugares  = geoLugares();
    $jogaveis = geoLugaresJogaveis();
    if (!$jogaveis) return;

    /* O QUE ESTE GM VIU POR ÚLTIMO — nesta partida e nas anteriores. As
       rodadas desta partida são as de maior id, então entram sempre: a
       regra velha ("não repete dentro da partida") continua valendo, e
       agora vale pra trás também. @see GEO_MEMORIA_LUGARES */
    $recentes = geoRodadasRecentes($pdo, $partidaId, GEO_MEMORIA_LUGARES);
    $usados = array_flip(array_slice($recentes, 0, GEO_MEMORIA_LUGARES));

    $paisesUsados = [];
    if ($modo === 'pais') {
        foreach (array_slice($recentes, 0, GEO_MEMORIA_PAISES) as $k) {
            if (isset($lugares[$k])) $paisesUsados[(string)$lugares[$k]['pais']] = true;
        }
    }

    /* SORTEIA ENTRE OS QUE SOBRARAM, em vez de tentar de novo até dar
       certo. O laço de 60 tentativas desistia calado quando o baralho
       estava apertado — e desistir calado é justamente repetir. */
    $podem = [];
    foreach ($jogaveis as $k => $l) {
        if (isset($usados[$k])) continue;
        if ($paisesUsados && isset($paisesUsados[(string)$l['pais']])) continue;
        $podem[$k] = true;
    }
    // Baralho apertado: solta o país primeiro, o lugar só em último caso.
    if (!$podem) {
        foreach ($jogaveis as $k => $l) if (!isset($usados[$k])) $podem[$k] = true;
    }
    if (!$podem) $podem = $jogaveis;
    $i = array_rand($podem);

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

/**
 * FECHA A PARTIDA E PAGA O QUE ELA VALEU.
 *
 * Existe porque fechar partida acontecia em três lugares — o fim natural, o
 * botão de encerrar e o começo de uma partida nova — e só o primeiro pagava.
 * Quem acertasse sete seguidos e clicasse em "Encerrar" via o recorde entrar
 * no ranking e as 350 moedas sumirem: o trabalho contava pra fama e não pro
 * bolso. Pior que perder, isso ENSINAVA a errar de propósito, porque errar
 * era o único jeito de receber.
 *
 * O pagamento só sai se o UPDATE pegou a partida ainda aberta. É o que
 * impede o duplo clique de pagar duas vezes: a segunda chamada não acha
 * mais nada em 'jogando' e sai com zero.
 *
 * @return int quanto entrou na conta
 */
function geoEncerrar(PDO $pdo, array $partida): int
{
    $st = $pdo->prepare("UPDATE geo_partidas SET status = 'fim', fim_em = NOW()
                          WHERE id = ? AND status = 'jogando'");
    $st->execute([(int)$partida['id']]);
    if ($st->rowCount() === 0) return 0;      // já estava fechada

    $s = $pdo->prepare('SELECT COALESCE(SUM(acertou),0) a, COALESCE(SUM(pontos),0) p
                          FROM geo_rodadas WHERE partida_id = ?');
    $s->execute([(int)$partida['id']]);
    $soma = $s->fetch(PDO::FETCH_ASSOC);

    /* PARTIDA DE TESTE NÃO PAGA. Ela roda igual em tudo o mais — sorteio,
       relógio, conferência — pra o que se está testando ser o jogo de
       verdade, e não uma imitação dele. O que ela não faz é mexer no
       bolso de ninguém. @see geoAbrirPartida */
    $moedas = empty($partida['teste'])
        ? geoPagar($pdo, (int)$partida['user_id'], (string)$partida['modo'],
                   (int)$soma['a'], (int)$soma['p'])
        : 0;
    $pdo->prepare('UPDATE geo_partidas SET acertos = ?, pontos = ?, moedas = ? WHERE id = ?')
        ->execute([(int)$soma['a'], (int)$soma['p'], $moedas, (int)$partida['id']]);
    return $moedas;
}

/**
 * Abre a próxima rodada da partida, se for a hora.
 *
 * É chamada pelo botão "próxima" e nada mais. As duas conferências não são
 * zelo demais: sem a primeira, dois cliques (ou dois toques do celular que
 * viraram dois envios) abririam duas rodadas e a partida pularia um lugar;
 * sem a segunda, chamar a ação de fora com a rodada ainda aberta na tela
 * trocaria a pergunta por outra até cair numa fácil.
 */
function geoProxima(PDO $pdo, array $partida): void
{
    if ($partida['status'] !== 'jogando') return;
    if (geoRodadaAtual($pdo, (int)$partida['id'])) return;   // ainda tem rodada em pé

    $st = $pdo->prepare('SELECT COUNT(*) FROM geo_rodadas WHERE partida_id = ?');
    $st->execute([(int)$partida['id']]);
    $feitas = (int)$st->fetchColumn();
    if ($partida['modo'] === 'mapa' && $feitas >= GEO_RODADAS_MAPA) return;

    geoSortearRodada($pdo, (int)$partida['id'], $feitas + 1, (string)$partida['modo']);
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

/** Quantos segundos ainda restam nesta rodada (zero quando acabou). */
function geoRestam(array $rodada): int
{
    $nasceu = strtotime((string)($rodada['criado_em'] ?? 'now')) ?: time();
    return max(0, GEO_SEGUNDOS - (time() - $nasceu));
}

/** A rodada já passou do tempo? */
function geoVencida(array $rodada): bool
{
    return geoRestam($rodada) <= 0;
}

/**
 * Os países que o baralho usa: código ISO, nome e coordenada.
 *
 * É o que liga o palpite ao mapa clicável — o contorno do Natural Earth é
 * identificado pelo ISO de três letras, e o baralho fala em nome.
 */
function geoPaises(): array
{
    static $p = null;
    if ($p !== null) return $p;
    $arq = __DIR__ . '/../data/geo_paises.php';
    $p = is_file($arq) ? (array)require $arq : [];
    return $p;
}

/** O código ISO de um país pelo nome, ou string vazia. */
function geoIsoDoPais(string $nome): string
{
    static $de = null;
    if ($de === null) {
        $de = [];
        foreach (geoPaises() as $iso => $x) $de[mb_strtolower((string)$x['nome'])] = $iso;
    }
    return $de[mb_strtolower(trim($nome))] ?? '';
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
    /* O id da rodada vai junto porque a tela de resultado mostra a foto de
       novo, e ela é servida pelo id — o nome do arquivo no Commons é o nome
       do lugar. @see games/games/geo_foto.php */
    $saida = ['acertou' => false, 'km' => null, 'pontos' => 0, 'lugar' => $lugar,
              'rodada_id' => (int)$rodada['id']];

    /* ── PASSOU DO TEMPO, NÃO VALE ───────────────────────────────────
       Conferido AQUI e não na tela: o relógio que o navegador desenha é
       enfeite, e quem chega com a resposta depois da hora chega depois da
       hora — inclusive quem recarregou a página no minuto seguinte. A
       rodada é fechada sem palpite: zero ponto, e no modo país a série
       acaba, que é o que aconteceria se tivesse errado. */
    if (geoVencida($rodada)) {
        $pdo->prepare('UPDATE geo_rodadas SET acertou = 0, pontos = 0 WHERE id = ?')
            ->execute([(int)$rodada['id']]);
        $saida['estourou'] = true;

    } elseif ($partida['modo'] === 'pais') {
        /* ── O PALPITE TEM QUE SER UMA DAS OPÇÕES ────────────────────
           A conferência era só "bate com o certo?", e um palpite digitado
           à mão valia mesmo sem estar na tela. Na prática ninguém ganharia
           nada com isso — a resposta continuaria sendo adivinhada, e cada
           rodada aceita um envio só —, mas pergunta de múltipla escolha que
           aceita resposta de fora não é múltipla escolha. */
        $dadas = (array)json_decode((string)($rodada['opcoes'] ?? '[]'), true);
        $dito  = mb_strtolower(trim((string)($palpite['pais'] ?? '')));
        $vale  = false;
        foreach ($dadas as $o) if (mb_strtolower((string)$o) === $dito) { $vale = true; break; }

        $saida['acertou'] = $vale && $dito === mb_strtolower((string)$lugar['pais']);
        $saida['pontos'] = $saida['acertou'] ? 1 : 0;
        $pdo->prepare('UPDATE geo_rodadas SET palpite = ?, acertou = ?, pontos = ? WHERE id = ?')
            ->execute([mb_substr((string)($palpite['pais'] ?? ''), 0, 80),
                       $saida['acertou'] ? 1 : 0, $saida['pontos'], (int)$rodada['id']]);
    } else {
        /* Coordenada dentro do planeta. Quem mandar 9999 não ganha nada com
           isso — longe é sempre pior —, mas o histórico fica com um número
           que não existe, e a conta de distância passa a devolver valores
           sem sentido geográfico. */
        $lat = max(-90.0,  min(90.0,  (float)($palpite['lat'] ?? 0)));
        $lon = max(-180.0, min(180.0, (float)($palpite['lon'] ?? 0)));
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
        /* Pelo mesmo caminho do botão de encerrar: um lugar só que fecha e
           paga, pra os dois nunca divergirem de novo. @see geoEncerrar */
        $saida['fim'] = true;
        $saida['moedas'] = geoEncerrar($pdo, $partida);
    } else {
        /* A PRÓXIMA NÃO NASCE AQUI. Nascia, e o resultado dividia a tela com
           uma rodada nova cujo relógio já estava correndo — a pessoa perdia
           tempo lendo o que acabou de acontecer. Agora o resultado fica
           sozinho e quem abre a próxima é o botão. @see geoProxima */
        $saida['fim'] = false;
    }
    $saida['acertos_total'] = (int)$soma['a'];
    $saida['feitas']        = (int)$soma['n'];   // quantas rodadas já foram

    /* OS DESAFIOS SÃO CONFERIDOS AQUI, depois de a rodada estar gravada: a
       sequência e os pontos que eles olham saem do banco, não do palpite.
       @see games/core/geo_desafios.php */
    require_once __DIR__ . '/geo_desafios.php';
    /* Nem missão nem conquista: o índice único (user_id, chave, dia)
       existe pra pagar uma vez só, então marcar no teste queimaria o
       prêmio da partida de verdade. */
    $saida['ganhos'] = empty($partida['teste'])
        ? geoConferirDesafios($pdo, $partida, $saida)
        : [];
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
        : (int)floor($pontos / 100) * GEO_MOEDA_MAPA_CEM;
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
                          WHERE p.modo = ? AND p.status = 'fim' AND p.teste = 0
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
                           FROM geo_partidas
                          WHERE user_id = ? AND status = 'fim' AND teste = 0
                       GROUP BY modo");
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

    /* ── O PANORAMA EXATO, E NÃO "O MAIS PRÓXIMO" ────────────────────
       Passando só a coordenada, quem escolhe o panorama é o Google — e ele
       escolhe entre tudo que existe ali, inclusive foto esférica enviada por
       gente comum. No primeiro teste, na Torre Eiffel, ele abriu numa foto
       de um visitante em vez do Street View da rua. Dá pra jogar, mas é
       loteria: a foto do vizinho pode estar escura, torta ou dentro de um
       restaurante. O marcador de cobertura guarda o ID do panorama que ELE
       viu, e é esse que abre aqui — o mesmo que foi conferido, sempre.
       A coordenada fica junto como reserva: se o panorama for aposentado,
       o Google cai no mais próximo em vez de mostrar tela cinza. */
    /* ── A COORDENADA NÃO VAI NO ENDEREÇO ────────────────────────────
       O `location` era a reserva pro caso de o panorama ser aposentado —
       e era também a resposta da rodada escrita no código-fonte da página:
       bastava abrir o inspetor, copiar "location=14.59,-91.61" e colar no
       Google Maps. Com o ID do panorama a cena abre igual e o ID não diz
       nada a ninguém. Sem ID — o que só acontece em lugar não conferido
       pelo marcador —, não há rodada de rua: geoRua já devolveu null. */
    $p = ['key' => $chave];
    if (empty($lugar['rua_pano'])) return null;
    $p['pano']     = (string)$lugar['rua_pano'];
    $p['heading']  = (int)($lugar['rua_dir'] ?? 0);
    $p['pitch']    = 0;
    $p['fov']      = 90;
    $p['language'] = 'pt-BR';
    return 'https://www.google.com/maps/embed/v1/streetview?' . http_build_query($p);
}
