<?php
/**
 * ── LENDAS DA QUADRA: A CONTA DO GM ──────────────────────────────────
 *
 * Tudo que é do jogador e sobrevive à partida: a COLEÇÃO de cartas, o
 * BARALHO montado com ela, os PACOTES comprados na loja, os PRÊMIOS das
 * partidas online, o RANKING da temporada e os DESAFIOS cumpridos.
 *
 * O Treino não passa por nada disto além de ler o baralho: ele nunca dá
 * moeda, ranking ou desafio (pedido do Victor, 09/10/2026).
 */

require_once __DIR__ . '/lendas_motor.php';

const LENDAS_TAM_BARALHO = 30;
/** Cópias permitidas no baralho: herói é único; efeito, até duas. */
const LENDAS_COPIAS = ['heroi' => 1, 'ferreiro' => 2, 'vilao' => 2];

/**
 * ── OS PACOTES ───────────────────────────────────────────────────────
 *
 * Cada carta do pacote sorteia primeiro a CLASSE (60% herói, 20% ferreiro,
 * 20% vilão), depois a RARIDADE pelas chances do pacote, depois a carta.
 * O básico garante uma rara; o premium, uma épica.
 *
 * Carta repetida que não cabe mais no baralho (herói que você já tem,
 * efeito além de duas cópias) VIRA MOEDA na hora — nenhuma compra é
 * desperdiçada, e ninguém precisa entender sistema de "pó".
 */
const LENDAS_PACOTES = [
    'basico'  => ['nome' => 'Pacote Básico',  'preco' => 300,  'cartas' => 5, 'garante' => 'rara',
                  'chances' => ['comum' => 700, 'rara' => 250, 'epica' => 45, 'lendaria' => 5]],
    'premium' => ['nome' => 'Pacote Premium', 'preco' => 1200, 'cartas' => 5, 'garante' => 'epica',
                  'chances' => ['comum' => 300, 'rara' => 450, 'epica' => 200, 'lendaria' => 50]],
];
const LENDAS_REEMBOLSO = ['comum' => 15, 'rara' => 40, 'epica' => 120, 'lendaria' => 400];

/** Prêmios do online (só online — o Treino nunca paga). */
const LENDAS_PREMIO_VITORIA = 500;
const LENDAS_PREMIO_DERROTA = 50;
const LENDAS_PREMIADAS_POR_DIA = 3;
/** Turnos de cada lado pra partida pagar — desistência no 2º turno não rende nada. */
const LENDAS_TURNOS_MIN_PREMIO = 6;
const LENDAS_RANK_VITORIA = 25;
const LENDAS_RANK_DERROTA = 15;

/**
 * ── OS DESAFIOS ──────────────────────────────────────────────────────
 * Cada um paga UMA vez, na primeira vez que é cumprido. Só no online.
 */
const LENDAS_DESAFIOS = [
    'primeira_vitoria' => ['nome' => 'Primeira vitória',              'moedas' => 300,  'desc' => 'Vença uma partida online.'],
    'sem_baixas'       => ['nome' => 'Sem baixas',                    'moedas' => 600,  'desc' => 'Vença sem perder nenhum herói.'],
    'critico_final'    => ['nome' => 'Golpe de misericórdia',         'moedas' => 400,  'desc' => 'Derrube a Fortaleza com um acerto crítico (d20).'],
    'so_comuns'        => ['nome' => 'Raiz',                          'moedas' => 800,  'desc' => 'Vença com um baralho só de cartas comuns.'],
    'tres_lendarias'   => ['nome' => 'Panteão',                       'moedas' => 700,  'desc' => 'Tenha 3 heróis lendários em quadra ao mesmo tempo.'],
    'vitoria_rapida'   => ['nome' => 'Blitz',                         'moedas' => 600,  'desc' => 'Vença em até 8 turnos seus.'],
    'dez_partidas'     => ['nome' => 'Veterano',                      'moedas' => 300,  'desc' => 'Jogue 10 partidas online.'],
    'dez_vitorias'     => ['nome' => 'Dinastia',                      'moedas' => 1000, 'desc' => 'Vença 10 partidas online.'],
    'primeiro_premium' => ['nome' => 'Investidor',                    'moedas' => 200,  'desc' => 'Abra o seu primeiro Pacote Premium.'],
    'time_completo'    => ['nome' => 'Franquia completa',             'moedas' => 1500, 'desc' => 'Tenha na coleção todos os heróis de um time da NBA.'],
];

function lendasTabelas(PDO $pdo): void
{
    static $pronto = false;
    if ($pronto) return;
    $pronto = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS lendas_colecao (
        id_usuario INT NOT NULL, carta VARCHAR(40) NOT NULL, qtd INT NOT NULL DEFAULT 1,
        PRIMARY KEY (id_usuario, carta)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lendas_baralho (
        id_usuario INT PRIMARY KEY, cartas TEXT NOT NULL,
        atualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lendas_compras (
        id INT AUTO_INCREMENT PRIMARY KEY, id_usuario INT NOT NULL, pacote VARCHAR(12) NOT NULL,
        cartas TEXT NOT NULL, preco INT NOT NULL, reembolso INT NOT NULL DEFAULT 0,
        criado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (id_usuario)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lendas_partidas (
        id INT AUTO_INCREMENT PRIMARY KEY, codigo CHAR(6) NULL, modo VARCHAR(8) NOT NULL,
        a_uid INT NOT NULL, b_uid INT NULL, poder_a INT NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT 'aguardando', estado MEDIUMTEXT NULL, prazo DATETIME NULL,
        vencedor_uid INT NULL, motivo VARCHAR(12) NULL, premiado TINYINT(1) NOT NULL DEFAULT 0,
        criado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, atualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY (codigo), INDEX (status, modo), INDEX (a_uid), INDEX (b_uid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lendas_premios (
        id INT AUTO_INCREMENT PRIMARY KEY, id_usuario INT NOT NULL, partida INT NOT NULL, oponente INT NOT NULL,
        venceu TINYINT(1) NOT NULL, moedas INT NOT NULL, criado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (id_usuario, criado)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lendas_ranking (
        id_usuario INT NOT NULL, temporada CHAR(7) NOT NULL, pontos INT NOT NULL DEFAULT 0,
        vitorias INT NOT NULL DEFAULT 0, derrotas INT NOT NULL DEFAULT 0,
        PRIMARY KEY (id_usuario, temporada), INDEX (temporada, pontos)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lendas_conquistas (
        id_usuario INT NOT NULL, chave VARCHAR(24) NOT NULL, moedas INT NOT NULL,
        criado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id_usuario, chave)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ─────────────────────────────── coleção ───────────────────────────────

/** Na primeira visita, a coleção nasce com as 30 cartas do baralho inicial. */
function lendasGarantirConta(PDO $pdo, int $uid): void
{
    lendasTabelas($pdo);
    $st = $pdo->prepare('SELECT 1 FROM lendas_colecao WHERE id_usuario = ? LIMIT 1');
    $st->execute([$uid]);
    if ($st->fetchColumn()) return;
    $inicial = lendasBaralhoInicial($uid);
    $ins = $pdo->prepare('INSERT INTO lendas_colecao (id_usuario, carta, qtd) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qtd = qtd');
    foreach (array_count_values($inicial) as $carta => $qtd) $ins->execute([$uid, $carta, $qtd]);
    $pdo->prepare('INSERT IGNORE INTO lendas_baralho (id_usuario, cartas) VALUES (?, ?)')->execute([$uid, json_encode($inicial)]);
}

/** @return array<string,int> carta → quantas o GM tem */
function lendasColecao(PDO $pdo, int $uid): array
{
    lendasGarantirConta($pdo, $uid);
    $st = $pdo->prepare('SELECT carta, qtd FROM lendas_colecao WHERE id_usuario = ?');
    $st->execute([$uid]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Por que um baralho não vale — ou null se vale. */
function lendasValidarBaralho(array $ids, array $colecao): ?string
{
    $cat = lendasCatalogo();
    if (count($ids) !== LENDAS_TAM_BARALHO) return 'O baralho precisa de exatamente ' . LENDAS_TAM_BARALHO . ' cartas.';
    foreach (array_count_values($ids) as $id => $q) {
        if (!isset($cat[$id])) return 'Carta desconhecida no baralho.';
        $c = $cat[$id];
        if ($q > LENDAS_COPIAS[$c['classe']]) return "{$c['nome']}: no máximo " . LENDAS_COPIAS[$c['classe']] . ' cópia(s).';
        if ($q > ($colecao[$id] ?? 0)) return "Você não tem cópias suficientes de {$c['nome']}.";
    }
    $herois = count(array_filter($ids, fn($id) => $cat[$id]['classe'] === 'heroi'));
    if ($herois < 10) return 'Ponha pelo menos 10 heróis — sem eles não há quem ataque.';
    return null;
}

/** O baralho salvo do GM (o inicial, se o salvo deixou de valer). */
function lendasBaralho(PDO $pdo, int $uid): array
{
    $col = lendasColecao($pdo, $uid);
    $st = $pdo->prepare('SELECT cartas FROM lendas_baralho WHERE id_usuario = ?');
    $st->execute([$uid]);
    $ids = json_decode((string)$st->fetchColumn(), true);
    if (is_array($ids) && lendasValidarBaralho($ids, $col) === null) return $ids;
    return lendasBaralhoInicial($uid);
}

function lendasSalvarBaralho(PDO $pdo, int $uid, array $ids): ?string
{
    $ids = array_values(array_map('strval', $ids));
    $erro = lendasValidarBaralho($ids, lendasColecao($pdo, $uid));
    if ($erro) return $erro;
    $pdo->prepare('INSERT INTO lendas_baralho (id_usuario, cartas) VALUES (?, ?) ON DUPLICATE KEY UPDATE cartas = VALUES(cartas)')
        ->execute([$uid, json_encode($ids)]);
    return null;
}

/**
 * A FORÇA de um baralho, pra fila juntar gente parecida: soma do custo
 * das cartas, pesada pela raridade. O inicial dá ~60; um baralho de quem
 * abriu dez pacotes, ~140.
 */
function lendasPoder(array $ids): int
{
    $cat = lendasCatalogo();
    $peso = ['comum' => 1.0, 'rara' => 1.5, 'epica' => 2.2, 'lendaria' => 3.2];
    $p = 0;
    foreach ($ids as $id) if (isset($cat[$id])) $p += $cat[$id]['custo'] * $peso[$cat[$id]['rar']];
    return (int)round($p);
}

// ─────────────────────────────── moedas ────────────────────────────────

function lendasSaldo(PDO $pdo, int $uid): int
{
    $st = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ?');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

function lendasCreditar(PDO $pdo, int $uid, int $moedas): void
{
    if ($moedas > 0) $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')->execute([$moedas, $uid]);
}

// ──────────────────────────────── loja ─────────────────────────────────

/**
 * Abre um pacote: cobra, sorteia, guarda na coleção e devolve em moeda o
 * que não cabe. Tudo numa transação, com a linha do GM travada — duas abas
 * comprando ao mesmo tempo não passam do saldo.
 *
 * @return array{cartas: array, reembolso: int, saldo: int, desafios: array}|string erro
 */
function lendasAbrirPacote(PDO $pdo, int $uid, string $tipo)
{
    $pac = LENDAS_PACOTES[$tipo] ?? null;
    if (!$pac) return 'Pacote inválido.';
    lendasGarantirConta($pdo, $uid);
    $cat = lendasCatalogo();
    $porClasseRar = [];
    foreach ($cat as $c) $porClasseRar[$c['classe']][$c['rar']][] = $c['id'];

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ? FOR UPDATE');
        $st->execute([$uid]);
        $saldo = (int)$st->fetchColumn();
        if ($saldo < $pac['preco']) { $pdo->rollBack(); return 'Moedas insuficientes.'; }
        $pdo->prepare('UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ?')->execute([$pac['preco'], $uid]);

        $ordem = ['comum', 'rara', 'epica', 'lendaria'];
        $sorteio = function (array $chances) use ($ordem) {
            $r = random_int(1, array_sum($chances));
            foreach ($ordem as $rar) { if ($r <= $chances[$rar]) return $rar; $r -= $chances[$rar]; }
            return 'comum';
        };
        $saem = [];
        for ($i = 0; $i < $pac['cartas']; $i++) {
            $classe = ['heroi', 'heroi', 'heroi', 'ferreiro', 'vilao'][random_int(0, 4)];
            $rar = $sorteio($pac['chances']);
            // A última carta cumpre a garantia, se ninguém cumpriu até ali.
            if ($i === $pac['cartas'] - 1) {
                $minimo = array_search($pac['garante'], $ordem, true);
                $melhor = max(array_map(fn($x) => array_search($x['rar'], $ordem, true), $saem ?: [['rar' => 'comum']]));
                if ($melhor < $minimo && array_search($rar, $ordem, true) < $minimo) $rar = $pac['garante'];
            }
            $lista = $porClasseRar[$classe][$rar] ?? $porClasseRar['heroi'][$rar];
            $saem[] = $cat[$lista[random_int(0, count($lista) - 1)]];
        }

        $col = lendasColecao($pdo, $uid);
        $reembolso = 0;
        $ins = $pdo->prepare('INSERT INTO lendas_colecao (id_usuario, carta, qtd) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE qtd = qtd + 1');
        $resultado = [];
        foreach ($saem as $c) {
            $tem = $col[$c['id']] ?? 0;
            if ($tem >= LENDAS_COPIAS[$c['classe']]) {
                $reembolso += LENDAS_REEMBOLSO[$c['rar']];
                $resultado[] = ['id' => $c['id'], 'repetida' => true, 'moedas' => LENDAS_REEMBOLSO[$c['rar']]];
            } else {
                $ins->execute([$uid, $c['id']]);
                $col[$c['id']] = $tem + 1;
                $resultado[] = ['id' => $c['id'], 'repetida' => false, 'nova' => $tem === 0];
            }
        }
        lendasCreditar($pdo, $uid, $reembolso);
        $pdo->prepare('INSERT INTO lendas_compras (id_usuario, pacote, cartas, preco, reembolso) VALUES (?, ?, ?, ?, ?)')
            ->execute([$uid, $tipo, json_encode(array_column($saem, 'id')), $pac['preco'], $reembolso]);

        $desafios = [];
        if ($tipo === 'premium') $desafios[] = 'primeiro_premium';
        if (lendasTemTimeCompleto($col)) $desafios[] = 'time_completo';
        $ganhos = lendasConcederDesafios($pdo, $uid, $desafios);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[lendas] pacote: ' . $e->getMessage());
        return 'Não deu pra abrir o pacote agora.';
    }
    return ['cartas' => $resultado, 'reembolso' => $reembolso, 'saldo' => lendasSaldo($pdo, $uid), 'desafios' => $ganhos];
}

/** Tem todos os heróis de algum time da NBA? */
function lendasTemTimeCompleto(array $col): bool
{
    $porTime = [];
    foreach (lendasCatalogo() as $c) if ($c['classe'] === 'heroi') $porTime[$c['time_sigla']][] = $c['id'];
    foreach ($porTime as $ids) {
        if (count($ids) >= 5 && !array_filter($ids, fn($id) => empty($col[$id]))) return true;
    }
    return false;
}

// ───────────────────────────── desafios ────────────────────────────────

/**
 * Dá os desafios que ainda não foram dados. O PRIMARY KEY (usuário, chave)
 * é a garantia de "só na primeira vez": o INSERT IGNORE só entra uma vez,
 * e só paga quem entrou.
 *
 * @return array<int,array{chave:string,nome:string,moedas:int}>
 */
function lendasConcederDesafios(PDO $pdo, int $uid, array $chaves): array
{
    $ganhos = [];
    foreach (array_unique($chaves) as $k) {
        $d = LENDAS_DESAFIOS[$k] ?? null;
        if (!$d) continue;
        $st = $pdo->prepare('INSERT IGNORE INTO lendas_conquistas (id_usuario, chave, moedas) VALUES (?, ?, ?)');
        $st->execute([$uid, $k, $d['moedas']]);
        if ($st->rowCount() === 1) {
            lendasCreditar($pdo, $uid, $d['moedas']);
            $ganhos[] = ['chave' => $k, 'nome' => $d['nome'], 'moedas' => $d['moedas']];
        }
    }
    return $ganhos;
}

function lendasDesafiosFeitos(PDO $pdo, int $uid): array
{
    lendasTabelas($pdo);
    $st = $pdo->prepare('SELECT chave, criado FROM lendas_conquistas WHERE id_usuario = ?');
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

// ─────────────────────── o fim de uma partida online ───────────────────

/**
 * Paga, ranqueia e confere desafios — UMA vez por partida (o `premiado`
 * da linha é a trava, marcado na mesma transação).
 *
 * As três travas contra combinar resultado com um amigo:
 *   · até LENDAS_PREMIADAS_POR_DIA partidas pagas por GM por dia;
 *   · contra o MESMO adversário, só a primeira vitória do dia paga;
 *   · a partida precisa ter LENDAS_TURNOS_MIN_PREMIO turnos de cada lado.
 * O ranking e os desafios contam sempre — só a moeda tem trava.
 *
 * @return array<int,array> por uid: moedas, motivo, desafios
 */
function lendasFinalizar(PDO $pdo, array $linha, array $e): array
{
    $uids = ['A' => (int)$linha['a_uid'], 'B' => (int)$linha['b_uid']];
    $saida = [];
    $temporada = date('Y-m');
    $cat = lendasCatalogo();
    $longaOBastante = $e['jogadores']['A']['turnos'] >= LENDAS_TURNOS_MIN_PREMIO && $e['jogadores']['B']['turnos'] >= LENDAS_TURNOS_MIN_PREMIO;

    // O golpe final foi um crítico na Fortaleza? (o último ataque antes do fim)
    $criticoFinal = false;
    if ($e['motivo'] === 'fortaleza') {
        foreach (array_reverse($e['eventos']) as $ev) {
            if ($ev['tipo'] === 'ataque') { $criticoFinal = $ev['alvo'] === 'fortaleza' && $ev['resultado'] === 'critico'; break; }
            if ($ev['tipo'] === 'efeito') break;
        }
    }

    foreach (['A', 'B'] as $lado) {
        $uid = $uids[$lado];
        $outro = $uids[lendasOutro($lado)];
        $venceu = $e['vencedor'] === $lado;
        $j = $e['jogadores'][$lado];

        // moedas
        $moedas = 0; $motivo = '';
        $hoje = $pdo->prepare('SELECT COUNT(*) FROM lendas_premios WHERE id_usuario = ? AND criado >= CURDATE()');
        $hoje->execute([$uid]);
        $mesmoRival = $pdo->prepare('SELECT COUNT(*) FROM lendas_premios WHERE id_usuario = ? AND oponente = ? AND venceu = 1 AND criado >= CURDATE()');
        $mesmoRival->execute([$uid, $outro]);
        if (!$longaOBastante) $motivo = 'Partida curta demais pra pagar (mínimo de ' . LENDAS_TURNOS_MIN_PREMIO . ' turnos de cada lado).';
        elseif ((int)$hoje->fetchColumn() >= LENDAS_PREMIADAS_POR_DIA) $motivo = 'Você já teve ' . LENDAS_PREMIADAS_POR_DIA . ' partidas premiadas hoje.';
        elseif ($venceu && (int)$mesmoRival->fetchColumn() > 0) $motivo = 'Contra o mesmo adversário, só a primeira vitória do dia paga.';
        else {
            $moedas = $venceu ? LENDAS_PREMIO_VITORIA : LENDAS_PREMIO_DERROTA;
            lendasCreditar($pdo, $uid, $moedas);
            $pdo->prepare('INSERT INTO lendas_premios (id_usuario, partida, oponente, venceu, moedas) VALUES (?, ?, ?, ?, ?)')
                ->execute([$uid, $linha['id'], $outro, $venceu ? 1 : 0, $moedas]);
        }

        // ranking (sempre) — nunca abaixo de zero, nem na primeira partida
        // da temporada (o GREATEST do UPDATE não vale pra linha que nasce).
        $delta = $venceu ? LENDAS_RANK_VITORIA : -LENDAS_RANK_DERROTA;
        $pdo->prepare('INSERT INTO lendas_ranking (id_usuario, temporada, pontos, vitorias, derrotas) VALUES (?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE pontos = GREATEST(0, pontos + ?), vitorias = vitorias + VALUES(vitorias), derrotas = derrotas + VALUES(derrotas)')
            ->execute([$uid, $temporada, max(0, $delta), $venceu ? 1 : 0, $venceu ? 0 : 1, $delta]);

        // desafios
        $tot = $pdo->prepare("SELECT COUNT(*) AS jogos, SUM(vencedor_uid = ?) AS vit FROM lendas_partidas
                              WHERE status = 'fim' AND (a_uid = ? OR b_uid = ?)");
        $tot->execute([$uid, $uid, $uid]);
        $t = $tot->fetch(PDO::FETCH_ASSOC);
        $jogos = (int)$t['jogos'] + 1; $vits = (int)$t['vit'] + ($venceu ? 1 : 0);   // + esta, que ainda não está marcada como fim
        $baralho = $j['baralho_original'] ?? [];
        /* Desafio de VITÓRIA exige vitória de verdade: Fortaleza no chão ou
           fim dos turnos. Por desistência ou abandono, não — senão dois
           amigos combinavam um "desisto" no 6º turno e colhiam Blitz, Raiz
           e Primeira vitória (1.700 moedas) sem jogar. No teste de
           08/10/2026 foi exatamente isso que aconteceu. */
        $ganhouJogando = $venceu && in_array($e['motivo'], ['fortaleza', 'tempo'], true);
        $chaves = [];
        if ($ganhouJogando) $chaves[] = 'primeira_vitoria';
        if ($ganhouJogando && empty($j['caidos'])) $chaves[] = 'sem_baixas';
        if ($ganhouJogando && $criticoFinal) $chaves[] = 'critico_final';
        if ($ganhouJogando && $baralho && !array_filter($baralho, fn($id) => ($cat[$id]['rar'] ?? '') !== 'comum')) $chaves[] = 'so_comuns';
        if (($j['max_lendarias'] ?? 0) >= 3) $chaves[] = 'tres_lendarias';
        if ($ganhouJogando && $j['turnos'] <= 8) $chaves[] = 'vitoria_rapida';
        if ($jogos >= 10) $chaves[] = 'dez_partidas';
        if ($vits >= 10 && $ganhouJogando) $chaves[] = 'dez_vitorias';
        $saida[$uid] = ['moedas' => $moedas, 'motivo' => $motivo, 'venceu' => $venceu, 'desafios' => lendasConcederDesafios($pdo, $uid, $chaves)];
    }
    return $saida;
}

// ─────────────────────────────── ranking ───────────────────────────────

function lendasRanking(PDO $pdo, int $uid): array
{
    lendasTabelas($pdo);
    $temporada = date('Y-m');
    $st = $pdo->prepare('SELECT r.id_usuario, r.pontos, r.vitorias, r.derrotas, u.nome
        FROM lendas_ranking r JOIN games_usuarios u ON u.id = r.id_usuario
        WHERE r.temporada = ? ORDER BY r.pontos DESC, r.vitorias DESC, r.id_usuario ASC LIMIT 20');
    $st->execute([$temporada]);
    $top = $st->fetchAll(PDO::FETCH_ASSOC);
    $eu = $pdo->prepare('SELECT pontos, vitorias, derrotas,
        (SELECT COUNT(*) + 1 FROM lendas_ranking x WHERE x.temporada = r.temporada AND x.pontos > r.pontos) AS pos
        FROM lendas_ranking r WHERE r.id_usuario = ? AND r.temporada = ?');
    $eu->execute([$uid, $temporada]);
    return ['temporada' => $temporada, 'top' => $top, 'eu' => $eu->fetch(PDO::FETCH_ASSOC) ?: null];
}
