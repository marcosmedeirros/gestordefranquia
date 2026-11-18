<?php
/**
 * ── POKÉMON PVP: AS CARTAS ───────────────────────────────────────────
 *
 * Tudo que é do GM e sobrevive à partida: a COLEÇÃO, o TIME escalado, a
 * LOJA e os PACOTES.
 *
 * ── A RARIDADE SAI DO BICHO, NÃO DE UMA LISTA ────────────────────────
 *
 * Raridade e preço vêm da SOMA DOS BASE STATS. Uma lista escrita à mão
 * envelheceria no dia em que a dex crescesse — e ela já cresceu de 403
 * pra 569 espécies nesta mesma semana. Com a conta, espécie nova entra
 * no lugar certo sozinha.
 *
 * Os cortes dão a pirâmide que um jogo de carta precisa, e caem onde a
 * intuição espera: Charizard rara, Dragonite épica, Mewtwo lendária,
 * Arceus mítica.
 *
 *   Comum     52%      Rara 26%      Épica 17%     Lendária 4%   Mítica 0,4%
 *
 * ── UMA MOEDA SÓ ─────────────────────────────────────────────────────
 *
 * Carta avulsa e pacote saem os dois em MOEDAS (pedido do Marcos,
 * 18/11/2026). O pacote nasceu custando FBA Points, e isso dividia o
 * jogo em duas economias que não conversavam: quem tinha moeda não
 * abria pacote e quem tinha FBA Point não comprava carta. Com uma moeda
 * só, tudo que você ganha jogando vale pra qualquer coisa aqui dentro.
 *
 * O teto de 3 pacotes por dia continua sendo o freio de verdade — sem
 * ele, quem tem saldo grande compraria a dex inteira numa tarde.
 *
 * ── REPETIDA DEVOLVE 25% ─────────────────────────────────────────────
 *
 * O pedido original era devolver o valor cheio, e não dá: o pacote
 * custa menos que a carta que pode sair dele, então devolver o valor
 * cheio de uma lendária repetida pagaria 4.000 moedas por um pacote de
 * 120. Vira máquina de imprimir dinheiro, e a aposta do PVP junto. 25%
 * é o bastante pra repetida não ser lixo.
 *
 * @see games/core/psd_motor.php — as regras da partida
 */

require_once __DIR__ . '/psd_motor.php';

/** Os cortes de soma de base stats que separam as raridades. */
const PSD_CORTES_BST = [500, 555, 600, 680];

/**
 * ── PREÇO EM MOEDAS, E A RÉGUA É A MEDIANA ───────────────────────────
 *
 * A primeira tabela foi calibrada pela MÉDIA do saldo da liga (1.645) e
 * ficou cara demais. A média mente: ela é puxada por quatro GMs ricos.
 * Olhando a distribuição de verdade (138 contas):
 *
 *     p25: 18      MEDIANA: 285      p75: 1.857      p90: 4.910
 *
 * Metade da liga tem menos de 285 moedas. Com comum a 150 e rara a 600,
 * metade do pessoal só conseguia comprar uma carta comum e parava — e os
 * números do primeiro dia confirmaram: 23 pessoas abriram 54 pacotes e só
 * 3 compraram carta avulsa.
 *
 * A régua nova, lida de baixo pra cima:
 *   COMUM     impulso — quase todo mundo compra sem pensar
 *   RARA      uma decisão de quem jogou um pouco
 *   ÉPICA     um objetivo de dias
 *   LENDÁRIA  objetivo de quem junta (p90 chega)
 *   MÍTICA    só os mais ricos da liga, e tem que ser assim
 *
 * ── O PISO SUBIU PRA 1.000 (18/11/2026, decisão do Marcos) ───────────
 *
 * A primeira tabela saiu barata demais: 55 pacotes foram abertos em um
 * único dia, e a coleção ia virar coisa de uma tarde. Ele mandou pôr o
 * mais barato em 1.000 e montar a escada daí pra cima.
 *
 * A escada conversa com o que a liga já cobra em moedas, que é a única
 * régua externa que existe:
 *
 *   slot de leilão 500 · dispensa extra 1.500 · badge 3.500
 *   City Edition 5.000 · slot de G-League 15.000
 *
 * LENDÁRIA = 15.000, o mesmo que o item mais caro da liga inteira.
 * MÍTICA  = 30.000, acima do maior saldo que existe hoje (20.476) e 14%
 * de toda a moeda da liga: é pra ser objetivo de temporada, não compra.
 *
 * ── O QUE ISSO CUSTA, PRA FICAR REGISTRADO ───────────────────────────
 *
 * A mediana do saldo é 285. Com o piso em 1.000, metade da liga não
 * compra NADA até jogar mais — e era esse o ponto. Se um dia parecer
 * duro demais, o número pra mexer é este, não a curva.
 */
const PSD_RARIDADES = [
    'comum'    => ['nome' => 'Comum',    'cor' => '#8d97ab', 'preco' =>  1000, 'devolve' =>  250],
    'rara'     => ['nome' => 'Rara',     'cor' => '#4aa3d4', 'preco' =>  2500, 'devolve' =>  625],
    'epica'    => ['nome' => 'Épica',    'cor' => '#a855f7', 'preco' =>  6000, 'devolve' => 1500],
    'lendaria' => ['nome' => 'Lendária', 'cor' => '#f5c542', 'preco' => 15000, 'devolve' => 3750],
    'mitica'   => ['nome' => 'Mítica',   'cor' => '#ef4444', 'preco' => 30000, 'devolve' => 7500],
];

/**
 * ── OS PACOTES ───────────────────────────────────────────────────────
 *
 * As chances são por CARTA. O pedido foi que bom viesse "bem raro, bem
 * mesmo", e é o que estes números fazem:
 *
 *   BÁSICO   lendária em 0,87% dos pacotes. Abrindo os 3 todo dia, uma
 *            a cada 38 dias.
 *   PREMIUM  custa o triplo e garante uma épica, mas a lendária continua
 *            sendo sorte: 5,6% por pacote.
 *
 * Mítica (Arceus e Eternatus) é 0,02% por carta no básico: a liga
 * inteira abrindo todo dia vê uma a cada mês e meio. É pra ser assim.
 */
const PSD_PACOTES = [
    'basico'  => ['nome' => 'Pacote Básico',  'preco' => 1500, 'cartas' => 3, 'garante' => 'rara',
                  'chances' => ['comum' => 7800, 'rara' => 1850, 'epica' => 320, 'lendaria' => 28, 'mitica' => 2]],
    'premium' => ['nome' => 'Pacote Premium', 'preco' => 4000, 'cartas' => 3, 'garante' => 'epica',
                  'chances' => ['comum' => 5200, 'rara' => 3300, 'epica' => 1310, 'lendaria' => 175, 'mitica' => 15]],
];

/** Pacotes por dia, somando os dois tipos. */
const PSD_PACOTES_DIA = 3;

/**
 * As seis que todo GM ganha ao entrar.
 *
 * Iguais pra todo mundo, de propósito: ninguém começa com sorte melhor
 * que a do vizinho. São de Kanto porque é o que qualquer um reconhece, e
 * nenhuma delas é forte o bastante pra o jogo acabar aí.
 */
const PSD_INICIAIS = ['venusaur', 'charizard', 'blastoise', 'raichu', 'pidgeot', 'machamp'];

/** Quantos entram em quadra. */
const PSD_TIME_TAM = 6;

// ─────────────────────────────── raridade e preço ───────────────────────────────

function psdRaridade(string $especie): string
{
    $dex = psdDex();
    if (!isset($dex[$especie])) return 'comum';

    $bst = array_sum($dex[$especie]['bs']);
    $chaves = array_keys(PSD_RARIDADES);
    $i = 0;
    foreach (PSD_CORTES_BST as $k => $corte) {
        if ($bst > $corte) $i = $k + 1;
    }
    return $chaves[$i];
}

function psdPrecoCarta(string $especie): int
{
    return PSD_RARIDADES[psdRaridade($especie)]['preco'];
}

function psdDevolucao(string $especie): int
{
    return PSD_RARIDADES[psdRaridade($especie)]['devolve'];
}

/** A carta como a tela mostra: nome, tipos, nível, raridade e preço. */
function psdCarta(string $especie): ?array
{
    $dex = psdDex();
    if (!isset($dex[$especie])) return null;
    $p = $dex[$especie];
    $r = psdRaridade($especie);

    return [
        'esp'      => $especie,
        'num'      => $p['num'],
        'nome'     => $p['nome'],
        'tipos'    => $p['tipos'],
        'nivel'    => $p['nivel'],
        'bst'      => array_sum($p['bs']),
        'bs'       => $p['bs'],
        'raridade' => $r,
        'rotulo'   => PSD_RARIDADES[$r]['nome'],
        'cor'      => PSD_RARIDADES[$r]['cor'],
        'preco'    => PSD_RARIDADES[$r]['preco'],
        'devolve'  => PSD_RARIDADES[$r]['devolve'],
    ];
}

/** Todas as espécies de uma raridade. Usado pelo sorteio do pacote. */
function psdEspeciesDaRaridade(string $raridade): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (array_keys(psdDex()) as $id) $cache[psdRaridade($id)][] = $id;
    }
    return $cache[$raridade] ?? [];
}

// ─────────────────────────────── tabelas ───────────────────────────────

function psdCartasTabelas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS psd_colecao (
        id_usuario INT NOT NULL,
        especie VARCHAR(40) NOT NULL,
        origem VARCHAR(10) NOT NULL DEFAULT 'pacote',
        obtida DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_usuario, especie),
        KEY idx_psdcol_u (id_usuario)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS psd_time (
        id_usuario INT PRIMARY KEY,
        especies TEXT NOT NULL,
        atualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS psd_compras (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        tipo VARCHAR(8) NOT NULL,
        item VARCHAR(40) NOT NULL,
        preco INT NOT NULL,
        moeda VARCHAR(6) NOT NULL,
        cartas TEXT NULL,
        devolvido INT NOT NULL DEFAULT 0,
        criado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_psdc_u (id_usuario), KEY idx_psdc_dia (id_usuario, criado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ─────────────────────────────── a conta ───────────────────────────────

/**
 * Garante que o GM tem as iniciais e um time escalado.
 *
 * Roda em toda entrada na página. O INSERT IGNORE é o que deixa isso ser
 * barato e seguro: quem já tem não ganha de novo, e duas abas abrindo ao
 * mesmo tempo não dobram o presente.
 */
function psdGarantirConta(PDO $pdo, int $uid): void
{
    psdCartasTabelas($pdo);

    $st = $pdo->prepare('SELECT COUNT(*) FROM psd_colecao WHERE id_usuario = ?');
    $st->execute([$uid]);
    if ((int)$st->fetchColumn() > 0) return;

    $ins = $pdo->prepare("INSERT IGNORE INTO psd_colecao (id_usuario, especie, origem) VALUES (?,?,'inicial')");
    $dex = psdDex();
    $deu = [];
    foreach (PSD_INICIAIS as $esp) {
        if (!isset($dex[$esp])) continue;   // a dex mudou e a inicial saiu: pula em silêncio
        $ins->execute([$uid, $esp]);
        $deu[] = $esp;
    }

    if ($deu) {
        $pdo->prepare('INSERT IGNORE INTO psd_time (id_usuario, especies) VALUES (?,?)')
            ->execute([$uid, json_encode($deu)]);
    }
}

function psdColecao(PDO $pdo, int $uid): array
{
    psdCartasTabelas($pdo);
    $st = $pdo->prepare('SELECT especie FROM psd_colecao WHERE id_usuario = ?');
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function psdTemCarta(PDO $pdo, int $uid, string $especie): bool
{
    $st = $pdo->prepare('SELECT 1 FROM psd_colecao WHERE id_usuario = ? AND especie = ?');
    $st->execute([$uid, $especie]);
    return (bool)$st->fetchColumn();
}

// ─────────────────────────────── o time ───────────────────────────────

/**
 * O time escalado. Se o GM nunca escalou, ou se escalou e depois a
 * espécie saiu da dex, completa com o que ele tem — a partida nunca pode
 * travar por causa de uma escalação velha.
 */
function psdTime(PDO $pdo, int $uid): array
{
    psdCartasTabelas($pdo);

    $st = $pdo->prepare('SELECT especies FROM psd_time WHERE id_usuario = ?');
    $st->execute([$uid]);
    $time = json_decode((string)$st->fetchColumn(), true) ?: [];

    $colecao = psdColecao($pdo, $uid);
    $dex = psdDex();

    // Fica só o que ele ainda tem e ainda existe.
    $time = array_values(array_filter($time, fn($e) => isset($dex[$e]) && in_array($e, $colecao, true)));

    // Completa com o resto da coleção até fechar o time.
    foreach ($colecao as $e) {
        if (count($time) >= PSD_TIME_TAM) break;
        if (!isset($dex[$e]) || in_array($e, $time, true)) continue;
        $time[] = $e;
    }

    return array_slice($time, 0, PSD_TIME_TAM);
}

function psdSalvarTime(PDO $pdo, int $uid, array $especies): array
{
    psdCartasTabelas($pdo);

    $colecao = psdColecao($pdo, $uid);
    $dex = psdDex();

    $limpo = [];
    foreach ($especies as $e) {
        $e = (string)$e;
        if (!isset($dex[$e]))            continue;
        if (!in_array($e, $colecao, true)) continue;
        if (in_array($e, $limpo, true))    continue;   // sem repetir
        $limpo[] = $e;
        if (count($limpo) >= PSD_TIME_TAM) break;
    }

    if (count($limpo) < PSD_TIME_TAM) {
        return ['ok' => false, 'erro' => 'O time precisa de ' . PSD_TIME_TAM . ' Pokémon diferentes.'];
    }

    $pdo->prepare('INSERT INTO psd_time (id_usuario, especies) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE especies = VALUES(especies)')
        ->execute([$uid, json_encode($limpo)]);

    return ['ok' => true, 'time' => $limpo];
}

// ─────────────────────────────── a loja ───────────────────────────────

/**
 * As duas moedas do GM. Toda ação que gasta devolve isto junto — sem
 * isso a tela mostra o saldo velho depois da compra, e o GM fica sem
 * saber se pagou.
 */
function psdBolso(PDO $pdo, int $uid): array
{
    $st = $pdo->prepare('SELECT COALESCE(pontos,0) m, COALESCE(fba_points,0) f FROM games_usuarios WHERE id = ?');
    $st->execute([$uid]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['m' => 0, 'f' => 0];
    return ['moedas' => (int)$r['m'], 'fbap' => (int)$r['f']];
}

/** Cobra moedas com a linha travada. Dentro de transação já aberta. */
function psdCobrarMoedas(PDO $pdo, int $uid, int $quanto): bool
{
    if ($quanto <= 0) return true;
    $st = $pdo->prepare('SELECT COALESCE(pontos,0) FROM games_usuarios WHERE id = ? FOR UPDATE');
    $st->execute([$uid]);
    if ((int)$st->fetchColumn() < $quanto) return false;
    $pdo->prepare('UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ?')->execute([$quanto, $uid]);
    return true;
}

/** Cobra FBA Points com a linha travada. Dentro de transação já aberta. */
function psdCobrarFbaPoints(PDO $pdo, int $uid, int $quanto): bool
{
    if ($quanto <= 0) return true;
    $st = $pdo->prepare('SELECT COALESCE(fba_points,0) FROM games_usuarios WHERE id = ? FOR UPDATE');
    $st->execute([$uid]);
    if ((int)$st->fetchColumn() < $quanto) return false;
    $pdo->prepare('UPDATE games_usuarios SET fba_points = fba_points - ? WHERE id = ?')->execute([$quanto, $uid]);
    return true;
}

/** Compra uma carta escolhida, em moedas. */
function psdComprarCarta(PDO $pdo, int $uid, string $especie): array
{
    psdCartasTabelas($pdo);

    $carta = psdCarta($especie);
    if (!$carta) return ['ok' => false, 'erro' => 'Esse Pokémon não existe.'];

    $pdo->beginTransaction();
    try {
        if (psdTemCarta($pdo, $uid, $especie)) {
            $pdo->rollBack();
            return ['ok' => false, 'erro' => 'Você já tem ' . $carta['nome'] . '.'];
        }

        if (!psdCobrarMoedas($pdo, $uid, $carta['preco'])) {
            $pdo->rollBack();
            return ['ok' => false, 'erro' => $carta['nome'] . ' custa ' . number_format($carta['preco'], 0, ',', '.') . ' moedas e você não tem.'];
        }

        $pdo->prepare("INSERT INTO psd_colecao (id_usuario, especie, origem) VALUES (?,?,'loja')")
            ->execute([$uid, $especie]);
        $pdo->prepare("INSERT INTO psd_compras (id_usuario, tipo, item, preco, moeda) VALUES (?,'carta',?,?,'moeda')")
            ->execute([$uid, $especie, $carta['preco']]);

        $pdo->commit();
        return ['ok' => true, 'carta' => $carta, 'bolso' => psdBolso($pdo, $uid)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'erro' => 'Não consegui concluir a compra.'];
    }
}

/** Quantos pacotes o GM já abriu hoje. */
function psdPacotesHoje(PDO $pdo, int $uid): int
{
    psdCartasTabelas($pdo);
    $st = $pdo->prepare("SELECT COUNT(*) FROM psd_compras
        WHERE id_usuario = ? AND tipo = 'pacote' AND DATE(criado) = CURDATE()");
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

/**
 * Sorteia a raridade de uma carta pelas chances do pacote.
 * As chances estão em DÉCIMOS DE MILÉSIMO (10000 = 100%) porque mítica é
 * 0,02% e com porcentagem inteira ela viraria zero ou 1%.
 */
function psdSortearRaridade(array $chances): string
{
    $total = array_sum($chances);
    $n = random_int(1, $total);
    foreach ($chances as $raridade => $peso) {
        $n -= $peso;
        if ($n <= 0) return $raridade;
    }
    return 'comum';
}

/**
 * ── ABRIR UM PACOTE ──────────────────────────────────────────────────
 *
 * Cobra em FBA Points, sorteia as cartas, guarda as novas na coleção e
 * devolve em moedas as repetidas. Tudo numa transação com a linha do GM
 * travada: duas abas abrindo ao mesmo tempo não passam do saldo nem do
 * teto de 3 por dia.
 *
 * A GARANTIA do pacote vale pra UMA carta: se nenhuma das três saiu na
 * raridade garantida ou acima, a última é forçada pra lá. Sem isso o
 * Premium podia sair com três comuns, e aí ninguém compraria de novo.
 */
function psdAbrirPacote(PDO $pdo, int $uid, string $tipo): array
{
    psdCartasTabelas($pdo);

    $pac = PSD_PACOTES[$tipo] ?? null;
    if (!$pac) return ['ok' => false, 'erro' => 'Pacote inválido.'];

    $pdo->beginTransaction();
    try {
        /* ── TRAVA O GM ANTES DE CONTAR ───────────────────────────────
         *
         * O teto de 3 por dia era contado com um SELECT COUNT ... FOR
         * UPDATE, e isso não segura nada quando a conta dá ZERO: não há
         * linha pra travar. Dois cliques no mesmo instante liam 0 os
         * dois e abriam 4, 6 pacotes.
         *
         * A linha do GM em games_usuarios existe sempre, então é ela que
         * serializa — o segundo clique espera o primeiro gravar a compra
         * e só então conta. */
        $pdo->prepare('SELECT id FROM games_usuarios WHERE id = ? FOR UPDATE')->execute([$uid]);

        $st = $pdo->prepare("SELECT COUNT(*) FROM psd_compras
            WHERE id_usuario = ? AND tipo = 'pacote' AND DATE(criado) = CURDATE()");
        $st->execute([$uid]);
        if ((int)$st->fetchColumn() >= PSD_PACOTES_DIA) {
            $pdo->rollBack();
            return ['ok' => false, 'erro' => 'Você já abriu os ' . PSD_PACOTES_DIA . ' pacotes de hoje. Volta amanhã.'];
        }

        if (!psdCobrarMoedas($pdo, $uid, $pac['preco'])) {
            $pdo->rollBack();
            return ['ok' => false, 'erro' => $pac['nome'] . ' custa ' . number_format($pac['preco'], 0, ',', '.') . ' moedas e você não tem.'];
        }

        $ordem = array_keys(PSD_RARIDADES);
        $minimo = array_search($pac['garante'], $ordem, true);

        $raridades = [];
        for ($i = 0; $i < $pac['cartas']; $i++) $raridades[] = psdSortearRaridade($pac['chances']);

        // A garantia: se nenhuma chegou lá, a última sobe.
        $atingiu = false;
        foreach ($raridades as $r) if (array_search($r, $ordem, true) >= $minimo) $atingiu = true;
        if (!$atingiu) $raridades[count($raridades) - 1] = $pac['garante'];

        $jaTem = psdColecao($pdo, $uid);
        $insCarta = $pdo->prepare("INSERT IGNORE INTO psd_colecao (id_usuario, especie, origem) VALUES (?,?,'pacote')");

        $saiu = [];
        $devolvido = 0;

        foreach ($raridades as $r) {
            $pool = psdEspeciesDaRaridade($r);
            if (!$pool) $pool = psdEspeciesDaRaridade('comum');
            $esp = $pool[random_int(0, count($pool) - 1)];

            $carta = psdCarta($esp);
            $repetida = in_array($esp, $jaTem, true);

            if ($repetida) {
                $devolvido += $carta['devolve'];
            } else {
                $insCarta->execute([$uid, $esp]);
                $jaTem[] = $esp;
            }

            $saiu[] = $carta + ['repetida' => $repetida];
        }

        if ($devolvido > 0) {
            $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')->execute([$devolvido, $uid]);
        }

        $pdo->prepare("INSERT INTO psd_compras (id_usuario, tipo, item, preco, moeda, cartas, devolvido)
                       VALUES (?,'pacote',?,?,'moeda',?,?)")
            ->execute([$uid, $tipo, $pac['preco'], json_encode(array_column($saiu, 'esp')), $devolvido]);

        $pdo->commit();

        return ['ok' => true, 'cartas' => $saiu, 'devolvido' => $devolvido,
                'restam' => PSD_PACOTES_DIA - psdPacotesHoje($pdo, $uid),
                'bolso'  => psdBolso($pdo, $uid)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'erro' => 'Não consegui abrir o pacote.'];
    }
}

// ─────────────────────────────── ranking ───────────────────────────────

/** Quem tem mais Pokémon. As iniciais contam pra todo mundo igual. */
function psdRankingColecao(PDO $pdo, int $quantos = 20): array
{
    psdCartasTabelas($pdo);
    $st = $pdo->query("SELECT c.id_usuario, u.nome, COUNT(*) AS total
        FROM psd_colecao c JOIN games_usuarios u ON u.id = c.id_usuario
        GROUP BY c.id_usuario, u.nome
        ORDER BY total DESC, u.nome ASC LIMIT " . (int)$quantos);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Quem mais venceu. Só PVP: o treino nunca entrou no psd_ranking, então
 * a conta já sai limpa daqui — não há o que filtrar.
 */
function psdRankingVitorias(PDO $pdo, int $quantos = 20): array
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS psd_ranking (
        id_usuario INT NOT NULL, temporada CHAR(7) NOT NULL,
        pontos INT NOT NULL DEFAULT 0, vitorias INT NOT NULL DEFAULT 0, derrotas INT NOT NULL DEFAULT 0,
        PRIMARY KEY (id_usuario, temporada)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $st = $pdo->query("SELECT r.id_usuario, u.nome,
            SUM(r.vitorias) AS vitorias, SUM(r.derrotas) AS derrotas
        FROM psd_ranking r JOIN games_usuarios u ON u.id = r.id_usuario
        GROUP BY r.id_usuario, u.nome
        HAVING vitorias + derrotas > 0
        ORDER BY vitorias DESC, derrotas ASC LIMIT " . (int)$quantos);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
