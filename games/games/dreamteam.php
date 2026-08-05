<?php
/**
 * dreamteam.php — Dream Team em Duelo (Starting5x5)
 *
 * Dois jogadores montam, cada um, um time de 5 lendas dentro de um teto
 * salarial de OVR combinado (mesma escala/pool do Build-A-Player: notas em
 * build_notas, 69 lendas curadas). Cada um aposta a mesma quantia (definida
 * por quem cria o duelo, até 100 moedas) e, quando os dois confirmam o time,
 * os dois times simulam UM confronto direto — vencedor leva as duas apostas.
 *
 * Não existe motor de "simular um jogo" pronto no Build-A-Player (esse só
 * simula temporada inteira, agregada). Aqui o confronto é novo: diferença de
 * OVR médio vira chance de vitória (mesmo espírito de buildSimularPlayoffs em
 * build_liga.php — força → chance → random_int), e o placar/destaques saem
 * de cima do resultado já decidido, não o contrário.
 *
 * Fluxo (sala com código, não fila global como o Poker):
 *   1. Cria duelo (aposta 1-100 moedas, debita na hora) → recebe um código.
 *   2. Compartilha o código; o adversário entra (debita a mesma aposta).
 *   3. Os dois montam o time (5 lendas, soma de OVR ≤ teto) e confirmam —
 *      pode ser em qualquer ordem, simultâneo ou não.
 *   4. Quando os dois confirmam, simula na hora: quem confirmou por último
 *      dispara o cálculo (trava por FOR UPDATE, ninguém simula 2x).
 *   5. Vencedor recebe as duas apostas. Placar + destaques ficam salvos.
 *
 * De propósito, não está no catálogo de games.php ainda — só quem tem o
 * link direto (games/games/index.php?game=dreamteam) chega aqui.
 *
 * Incluído por games/games/index.php — $pdo e $_SESSION já disponíveis.
 */

require_once __DIR__ . '/../core/build_notas.php';
require_once __DIR__ . '/../core/build_lendas.php';

$user_id = (int)$_SESSION['user_id'];

const DT_CAP_OVR = 380;
const DT_TIME_SIZE = 5;
const DT_APOSTA_MIN = 1;
const DT_APOSTA_MAX = 100;
// Contra a máquina o teto é menor: ela sempre monta o time ótimo (ver
// dtMontarTimeCpu), então o risco por partida é maior que jogador-vs-jogador.
const DT_APOSTA_MAX_CPU = 50;
const DT_AGUARDANDO_TIMEOUT_H = 24;
const DT_MONTANDO_TIMEOUT_MIN = 15;

function dtGarantirTabelas(PDO $pdo): void
{
    static $pronto = false;
    if ($pronto || $pdo->inTransaction()) return;
    $pronto = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS dreamteam_duelos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(8) NOT NULL,
        id_criador INT NOT NULL,
        id_desafiado INT NULL,
        aposta INT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'aguardando',
        time_criador TEXT NULL,
        time_desafiado TEXT NULL,
        pronto_criador TINYINT(1) NOT NULL DEFAULT 0,
        pronto_desafiado TINYINT(1) NOT NULL DEFAULT 0,
        ovr_criador INT NULL,
        ovr_desafiado INT NULL,
        resultado TEXT NULL,
        id_vencedor INT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        entrou_em DATETIME NULL,
        concluido_em DATETIME NULL,
        UNIQUE KEY uk_dt_codigo (codigo),
        INDEX idx_dt_criador (id_criador),
        INDEX idx_dt_desafiado (id_desafiado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
dtGarantirTabelas($pdo);
buildGarantirTabelaNotas($pdo);
// Garante as 69 lendas mesmo se ninguém nunca abriu o Build-A-Player (que é
// quem normalmente popula isso, só pelo admin). Só roda o seed se a tabela
// estiver vazia — upsert de 69 linhas em toda requisição seria desperdício.
if ((int)$pdo->query('SELECT COUNT(*) FROM build_notas')->fetchColumn() === 0) {
    buildAplicarLendasCuradas($pdo);
}

function dtGerarCodigo(PDO $pdo): string
{
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // sem 0/O/1/I/L — confunde na hora de digitar
    for ($tentativa = 0; $tentativa < 20; $tentativa++) {
        $codigo = '';
        for ($i = 0; $i < 6; $i++) $codigo .= $chars[random_int(0, strlen($chars) - 1)];
        $st = $pdo->prepare('SELECT 1 FROM dreamteam_duelos WHERE codigo = ?');
        $st->execute([$codigo]);
        if (!$st->fetchColumn()) return $codigo;
    }
    throw new Exception('Não foi possível gerar um código único. Tente de novo.');
}

/** Todas as lendas do pool (id, nome, time, grupo, ovr) — pra tela de montar o time. */
function dtLendas(PDO $pdo): array
{
    $st = $pdo->query("
        SELECT n.player_id AS id, n.posicao_grupo AS grupo, n.ovr, p.nome, p.time_atual AS time
        FROM build_notas n
        INNER JOIN hoopgrid_players p ON p.id = n.player_id
        ORDER BY n.ovr DESC, p.nome ASC
    ");
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Time da máquina: o melhor time matematicamente possível dentro do teto —
 * mochila (0/1 knapsack) de exatamente 5 itens maximizando a soma de OVR sem
 * estourar DT_CAP_OVR. É o adversário mais difícil que dá pra montar sem
 * trapacear o próprio teto salarial. Embaralha antes pra variar quais lendas
 * empatadas em OVR saem, sem perder força nenhuma — o resultado ótimo é o
 * mesmo, só a combinação exata entre empates muda a cada partida.
 */
function dtMontarTimeCpu(PDO $pdo): array
{
    $lendas = dtLendas($pdo);
    shuffle($lendas);
    $n = count($lendas);

    // dp[k][s] = dá pra montar k lendas somando s OVR (s vai de 0 até o teto).
    // escolhas[i][k][s] = a lenda i foi usada pra alcançar dp[k][s] — é o que
    // permite reconstruir QUAIS lendas entram, não só a soma máxima.
    $dp = array_fill(0, DT_TIME_SIZE + 1, array_fill(0, DT_CAP_OVR + 1, false));
    $dp[0][0] = true;
    $escolhas = [];

    for ($i = 0; $i < $n; $i++) {
        $ovr = (int)$lendas[$i]['ovr'];
        if ($ovr > DT_CAP_OVR) continue;
        $escolhas[$i] = [];
        for ($k = DT_TIME_SIZE - 1; $k >= 0; $k--) {
            for ($s = DT_CAP_OVR - $ovr; $s >= 0; $s--) {
                if ($dp[$k][$s] && !$dp[$k + 1][$s + $ovr]) {
                    $dp[$k + 1][$s + $ovr] = true;
                    $escolhas[$i][($k + 1) . '_' . ($s + $ovr)] = true;
                }
            }
        }
    }

    $melhorSoma = 0;
    for ($s = DT_CAP_OVR; $s >= 0; $s--) {
        if ($dp[DT_TIME_SIZE][$s]) { $melhorSoma = $s; break; }
    }

    $ids = [];
    $k = DT_TIME_SIZE;
    $s = $melhorSoma;
    for ($i = $n - 1; $i >= 0 && $k > 0; $i--) {
        if (!empty($escolhas[$i]["{$k}_{$s}"])) {
            $ids[] = (int)$lendas[$i]['id'];
            $s -= (int)$lendas[$i]['ovr'];
            $k--;
        }
    }
    return $ids;
}

/** Carrega as 5 lendas de um time (com os 10 atributos, pra calcular destaques). */
function dtCarregarTime(PDO $pdo, array $ids): array
{
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("
        SELECT n.*, p.nome, p.time_atual
        FROM build_notas n
        INNER JOIN hoopgrid_players p ON p.id = n.player_id
        WHERE n.player_id IN ($ph)
    ");
    $st->execute($ids);
    $porId = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $porId[(int)$row['player_id']] = $row;
    $out = [];
    foreach ($ids as $id) if (isset($porId[$id])) $out[] = $porId[$id];
    return $out;
}

/**
 * Frases de destaque por atributo mais forte do jogador — usadas nos
 * "destaques" do confronto (ver dtGerarDestaques).
 */
function dtFraseAtributo(string $attr): string
{
    $frases = [
        'jump_shot'   => 'castigando o arco com o arremesso',
        'finishing'   => 'não perdoando nada perto da cesta',
        'passing'     => 'abrindo o jogo com passes',
        'handles'     => 'quebrando a marcação no drible',
        'perimeter_d' => 'sufocando o adversário na defesa',
        'speed'       => 'atropelando em transição',
        'bounce'      => 'voando pra cima em cada jogada',
        'size'        => 'dominando embaixo da cesta',
        'iq'          => 'lendo o jogo como ninguém',
        'clutch'      => 'aparecendo nos momentos decisivos',
    ];
    return $frases[$attr] ?? 'brilhando em quadra';
}

/** As 2 estrelas (maior OVR) de cada time, com pontuação e frase de destaque. */
function dtGerarDestaques(array $timeA, array $timeB, string $vencedorLado): array
{
    $atributos = array_keys(buildAtributos());
    $destaques = [];
    foreach ([['time' => $timeA, 'lado' => 'a'], ['time' => $timeB, 'lado' => 'b']] as $grupo) {
        $ordenado = $grupo['time'];
        usort($ordenado, fn($x, $y) => (int)$y['ovr'] - (int)$x['ovr']);
        foreach (array_slice($ordenado, 0, 2) as $jogador) {
            $melhor = null;
            $maior = -1;
            foreach ($atributos as $a) {
                if ((int)$jogador[$a] > $maior) { $maior = (int)$jogador[$a]; $melhor = $a; }
            }
            $ganhouTime = $grupo['lado'] === $vencedorLado;
            $pontos = (int)round(((int)$jogador['ovr'] - 55) * 0.55) + random_int(6, 14) + ($ganhouTime ? random_int(0, 4) : 0);
            $destaques[] = [
                'lado'   => $grupo['lado'],
                'nome'   => $jogador['nome'],
                'pontos' => max(8, $pontos),
                'frase'  => dtFraseAtributo((string)$melhor),
            ];
        }
    }
    usort($destaques, fn($x, $y) => $y['pontos'] - $x['pontos']);
    return $destaques;
}

/**
 * O confronto: diferença de OVR médio decide a CHANCE de vitória (não o
 * placar direto) — o placar sai depois, moldado pro vencedor já sorteado
 * ficar coerente com o resultado. Sem isso, dois placares aleatórios
 * independentes podiam contradizer quem "realmente" ganhou.
 */
function dtCalcularResultado(array $timeA, array $timeB): array
{
    $ovrA = array_sum(array_column($timeA, 'ovr'));
    $ovrB = array_sum(array_column($timeB, 'ovr'));
    $diffMedio = ($ovrA - $ovrB) / DT_TIME_SIZE;

    $chanceA = 50 + ($diffMedio * 3.5);
    $chanceA = max(8, min(92, $chanceA));

    $aGanha = random_int(1, 1000) <= (int)round($chanceA * 10);

    $baseA = 100 + random_int(-10, 10);
    $baseB = 100 + random_int(-10, 10);
    $margem = min(32, random_int(3, 9) + (int)round(abs($diffMedio) * 1.1));

    $topo = max($baseA, $baseB);
    $fundo = min($baseA, $baseB);
    if ($aGanha) {
        $placarA = $topo + $margem;
        $placarB = $fundo;
    } else {
        $placarB = $topo + $margem;
        $placarA = $fundo;
    }

    $vencedorLado = $aGanha ? 'a' : 'b';

    return [
        'ovr_a'     => $ovrA,
        'ovr_b'     => $ovrB,
        'chance_a'  => round($chanceA, 1),
        'placar_a'  => $placarA,
        'placar_b'  => $placarB,
        'vencedor'  => $vencedorLado,
        'destaques' => dtGerarDestaques($timeA, $timeB, $vencedorLado),
    ];
}

/**
 * O duelo pra MOSTRAR na tela: o em andamento (aguardando/montando), ou —
 * na falta de um — o último concluído, só pra tela de resultado não sumir
 * assim que a simulação termina. NÃO usar pra decidir se pode criar/entrar
 * num duelo novo — pra isso é dtDueloEmAndamento() (abaixo), que exclui
 * 'simulado': senão, uma vez que a pessoa jogasse um duelo ela nunca mais
 * conseguiria abrir outro.
 */
function dtDueloAtivo(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare("
        SELECT * FROM dreamteam_duelos
        WHERE (id_criador = ? OR id_desafiado = ?)
          AND status IN ('aguardando', 'montando', 'simulado')
        ORDER BY (status IN ('aguardando', 'montando')) DESC, id DESC
        LIMIT 1
    ");
    $st->execute([$userId, $userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Só o que realmente trava criar/entrar num duelo novo — 'simulado' não conta. */
function dtDueloEmAndamento(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare("
        SELECT * FROM dreamteam_duelos
        WHERE (id_criador = ? OR id_desafiado = ?)
          AND status IN ('aguardando', 'montando')
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([$userId, $userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Devolve as apostas de duelos abandonados — sem isso a moeda ficava presa pra sempre. */
function dtExpirarAntigos(PDO $pdo): void
{
    $st = $pdo->prepare("SELECT id, id_criador, aposta FROM dreamteam_duelos WHERE status = 'aguardando' AND criado_em < DATE_SUB(NOW(), INTERVAL ? HOUR)");
    $st->execute([DT_AGUARDANDO_TIMEOUT_H]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')->execute([(int)$d['aposta'], (int)$d['id_criador']]);
            $pdo->prepare("UPDATE dreamteam_duelos SET status = 'expirado' WHERE id = ?")->execute([$d['id']]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }
    }

    $st = $pdo->prepare("SELECT id, id_criador, id_desafiado, aposta FROM dreamteam_duelos WHERE status = 'montando' AND entrou_em < DATE_SUB(NOW(), INTERVAL ? MINUTE)");
    $st->execute([DT_MONTANDO_TIMEOUT_MIN]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')->execute([(int)$d['aposta'], (int)$d['id_criador']]);
            $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')->execute([(int)$d['aposta'], (int)$d['id_desafiado']]);
            $pdo->prepare("UPDATE dreamteam_duelos SET status = 'expirado' WHERE id = ?")->execute([$d['id']]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }
    }
}

/** Monta o JSON de estado que o client usa pra decidir qual tela mostrar. */
function dtSerializar(PDO $pdo, ?array $duelo, int $userId): ?array
{
    if (!$duelo) return null;
    $souCriador = (int)$duelo['id_criador'] === $userId;

    $oponenteNome = null;
    if ($duelo['id_desafiado'] !== null) {
        $oponenteId = $souCriador ? (int)$duelo['id_desafiado'] : (int)$duelo['id_criador'];
        if ($oponenteId === 0) {
            $oponenteNome = 'Máquina 🤖';
        } else {
            $st = $pdo->prepare('SELECT nome FROM games_usuarios WHERE id = ?');
            $st->execute([$oponenteId]);
            $oponenteNome = $st->fetchColumn() ?: 'Oponente';
        }
    }

    $out = [
        'id'               => (int)$duelo['id'],
        'codigo'           => $duelo['codigo'],
        'status'           => $duelo['status'],
        'aposta'           => (int)$duelo['aposta'],
        'sou_criador'      => $souCriador,
        'oponente_nome'    => $oponenteNome,
        'meu_pronto'       => (bool)($souCriador ? $duelo['pronto_criador'] : $duelo['pronto_desafiado']),
        'oponente_pronto'  => (bool)($souCriador ? $duelo['pronto_desafiado'] : $duelo['pronto_criador']),
    ];

    if ($duelo['status'] === 'simulado') {
        $resultado = json_decode((string)$duelo['resultado'], true) ?: [];
        $meuLado = $souCriador ? 'a' : 'b';
        $idsMeuTime = json_decode((string)($souCriador ? $duelo['time_criador'] : $duelo['time_desafiado']), true) ?: [];
        $idsTimeOponente = json_decode((string)($souCriador ? $duelo['time_desafiado'] : $duelo['time_criador']), true) ?: [];
        $out['resultado'] = $resultado;
        $out['meu_lado'] = $meuLado;
        $out['eu_venci'] = ((int)$duelo['id_vencedor'] === $userId);
        $out['meu_time'] = dtCarregarTime($pdo, $idsMeuTime);
        $out['time_oponente'] = dtCarregarTime($pdo, $idsTimeOponente);
    }

    return $out;
}

// ── AÇÕES ────────────────────────────────────────────────────────────────────
if (($_POST['acao'] ?? '') !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $acao = $_POST['acao'];
    dtExpirarAntigos($pdo);

    try {
        if ($acao === 'criar') {
            if (dtDueloEmAndamento($pdo, $user_id)) {
                echo json_encode(['ok' => false, 'msg' => 'Você já tem um duelo em andamento.']);
                exit;
            }
            $aposta = (int)($_POST['aposta'] ?? 0);
            if ($aposta < DT_APOSTA_MIN || $aposta > DT_APOSTA_MAX) {
                echo json_encode(['ok' => false, 'msg' => 'A aposta deve ser entre ' . DT_APOSTA_MIN . ' e ' . DT_APOSTA_MAX . ' moedas.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ? FOR UPDATE');
                $st->execute([$user_id]);
                $saldo = (int)$st->fetchColumn();
                if ($saldo < $aposta) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Saldo insuficiente.']);
                    exit;
                }
                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ?')->execute([$aposta, $user_id]);

                $codigo = dtGerarCodigo($pdo);
                $pdo->prepare("INSERT INTO dreamteam_duelos (codigo, id_criador, aposta, status) VALUES (?, ?, ?, 'aguardando')")
                    ->execute([$codigo, $user_id, $aposta]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        // Contra a máquina: sem sala de espera — o time da CPU (o melhor
        // possível dentro do teto, ver dtMontarTimeCpu) já nasce pronto, então
        // o duelo entra direto em 'montando' e o usuário já cai na tela de
        // montar o próprio time. id_desafiado=0 marca "é a máquina" (nenhum
        // usuário de verdade tem id 0) — se ela vencer, o pagamento pra id=0
        // não afeta ninguém (a aposta só some, igual perder pra "a casa").
        if ($acao === 'criar_vs_cpu') {
            if (dtDueloEmAndamento($pdo, $user_id)) {
                echo json_encode(['ok' => false, 'msg' => 'Você já tem um duelo em andamento.']);
                exit;
            }
            $aposta = (int)($_POST['aposta'] ?? 0);
            if ($aposta < DT_APOSTA_MIN || $aposta > DT_APOSTA_MAX_CPU) {
                echo json_encode(['ok' => false, 'msg' => 'Contra a máquina a aposta deve ser entre ' . DT_APOSTA_MIN . ' e ' . DT_APOSTA_MAX_CPU . ' moedas.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ? FOR UPDATE');
                $st->execute([$user_id]);
                $saldo = (int)$st->fetchColumn();
                if ($saldo < $aposta) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Saldo insuficiente.']);
                    exit;
                }
                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ?')->execute([$aposta, $user_id]);

                $idsCpu = dtMontarTimeCpu($pdo);
                $timeCpu = dtCarregarTime($pdo, $idsCpu);
                $ovrCpu = array_sum(array_column($timeCpu, 'ovr'));

                $codigo = dtGerarCodigo($pdo);
                $pdo->prepare("INSERT INTO dreamteam_duelos
                        (codigo, id_criador, id_desafiado, aposta, status, time_desafiado, ovr_desafiado, pronto_desafiado, entrou_em)
                        VALUES (?, ?, 0, ?, 'montando', ?, ?, 1, NOW())")
                    ->execute([$codigo, $user_id, $aposta, json_encode($idsCpu), $ovrCpu]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($acao === 'entrar') {
            if (dtDueloEmAndamento($pdo, $user_id)) {
                echo json_encode(['ok' => false, 'msg' => 'Você já tem um duelo em andamento.']);
                exit;
            }
            $codigo = strtoupper(trim((string)($_POST['codigo'] ?? '')));
            if ($codigo === '') {
                echo json_encode(['ok' => false, 'msg' => 'Digite um código.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT * FROM dreamteam_duelos WHERE codigo = ? FOR UPDATE');
                $st->execute([$codigo]);
                $duelo = $st->fetch(PDO::FETCH_ASSOC);
                if (!$duelo) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Código não encontrado.']);
                    exit;
                }
                if ($duelo['status'] !== 'aguardando') {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Esse duelo não está mais disponível.']);
                    exit;
                }
                if ((int)$duelo['id_criador'] === $user_id) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Você não pode entrar no seu próprio duelo.']);
                    exit;
                }

                $aposta = (int)$duelo['aposta'];
                $stS = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ? FOR UPDATE');
                $stS->execute([$user_id]);
                $saldo = (int)$stS->fetchColumn();
                if ($saldo < $aposta) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => "Saldo insuficiente pra cobrir a aposta ({$aposta} moedas)."]);
                    exit;
                }

                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ?')->execute([$aposta, $user_id]);
                $pdo->prepare("UPDATE dreamteam_duelos SET id_desafiado = ?, status = 'montando', entrou_em = NOW() WHERE id = ?")
                    ->execute([$user_id, $duelo['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($acao === 'cancelar') {
            $duelo = dtDueloAtivo($pdo, $user_id);
            if (!$duelo) { echo json_encode(['ok' => false, 'msg' => 'Nenhum duelo pra cancelar.']); exit; }
            if ((int)$duelo['id_criador'] !== $user_id) { echo json_encode(['ok' => false, 'msg' => 'Só quem criou pode cancelar.']); exit; }
            if ($duelo['status'] !== 'aguardando') { echo json_encode(['ok' => false, 'msg' => 'Só dá pra cancelar antes de alguém entrar.']); exit; }

            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')->execute([(int)$duelo['aposta'], $user_id]);
                $pdo->prepare("UPDATE dreamteam_duelos SET status = 'cancelado' WHERE id = ?")->execute([$duelo['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($acao === 'montar_time') {
            $duelo = dtDueloAtivo($pdo, $user_id);
            if (!$duelo || $duelo['status'] !== 'montando') {
                echo json_encode(['ok' => false, 'msg' => 'Esse duelo não está na fase de montar time.']);
                exit;
            }
            $lado = ((int)$duelo['id_criador'] === $user_id) ? 'criador' : 'desafiado';
            $jaPronto = $lado === 'criador' ? $duelo['pronto_criador'] : $duelo['pronto_desafiado'];
            if ($jaPronto) { echo json_encode(['ok' => false, 'msg' => 'Você já confirmou seu time.']); exit; }

            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['jogadores'] ?? ''))))));
            if (count($ids) !== DT_TIME_SIZE) {
                echo json_encode(['ok' => false, 'msg' => 'Escolha exatamente ' . DT_TIME_SIZE . ' lendas.']);
                exit;
            }

            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT player_id, ovr FROM build_notas WHERE player_id IN ($ph)");
            $st->execute($ids);
            $achados = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $achados[(int)$row['player_id']] = (int)$row['ovr'];
            if (count($achados) !== DT_TIME_SIZE) {
                echo json_encode(['ok' => false, 'msg' => 'Algum jogador escolhido não existe.']);
                exit;
            }
            $somaOvr = array_sum($achados);
            if ($somaOvr > DT_CAP_OVR) {
                echo json_encode(['ok' => false, 'msg' => "Time estourou o teto salarial ({$somaOvr}/" . DT_CAP_OVR . ').']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT * FROM dreamteam_duelos WHERE id = ? FOR UPDATE');
                $st->execute([$duelo['id']]);
                $atual = $st->fetch(PDO::FETCH_ASSOC);
                if (!$atual || $atual['status'] !== 'montando') {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Duelo não está mais disponível.']);
                    exit;
                }

                $colTime = $lado === 'criador' ? 'time_criador' : 'time_desafiado';
                $colOvr = $lado === 'criador' ? 'ovr_criador' : 'ovr_desafiado';
                $colPronto = $lado === 'criador' ? 'pronto_criador' : 'pronto_desafiado';
                $pdo->prepare("UPDATE dreamteam_duelos SET {$colTime} = ?, {$colOvr} = ?, {$colPronto} = 1 WHERE id = ?")
                    ->execute([json_encode($ids), $somaOvr, $duelo['id']]);

                $outroPronto = (bool)($lado === 'criador' ? $atual['pronto_desafiado'] : $atual['pronto_criador']);
                if ($outroPronto) {
                    $idsCriador = $lado === 'criador' ? $ids : (json_decode((string)$atual['time_criador'], true) ?: []);
                    $idsDesafiado = $lado === 'desafiado' ? $ids : (json_decode((string)$atual['time_desafiado'], true) ?: []);

                    $timeCriador = dtCarregarTime($pdo, $idsCriador);
                    $timeDesafiado = dtCarregarTime($pdo, $idsDesafiado);
                    $resultado = dtCalcularResultado($timeCriador, $timeDesafiado);

                    $vencedorId = $resultado['vencedor'] === 'a' ? (int)$atual['id_criador'] : (int)$atual['id_desafiado'];
                    $premio = (int)$atual['aposta'] * 2;

                    $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')->execute([$premio, $vencedorId]);
                    $pdo->prepare("UPDATE dreamteam_duelos SET status = 'simulado', resultado = ?, id_vencedor = ?, concluido_em = NOW() WHERE id = ?")
                        ->execute([json_encode($resultado), $vencedorId, $duelo['id']]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($acao === 'estado') {
            $duelo = dtDueloAtivo($pdo, $user_id);
            $st = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ?');
            $st->execute([$user_id]);
            echo json_encode(['ok' => true, 'duelo' => dtSerializar($pdo, $duelo, $user_id), 'pontos' => (int)($st->fetchColumn() ?: 0)]);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'Ação inválida.']);
    } catch (Throwable $e) {
        error_log('[dreamteam] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erro interno. Tente de novo.']);
    }
    exit;
}

// ── TELA ────────────────────────────────────────────────────────────────────
dtExpirarAntigos($pdo);
$duelo = dtDueloAtivo($pdo, $user_id);
$estadoInicial = dtSerializar($pdo, $duelo, $user_id);
$lendas = dtLendas($pdo);
$stPontos = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ?');
$stPontos->execute([$user_id]);
$meuSaldo = (int)($stPontos->fetchColumn() ?: 0);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>Starting5x5 — Dream Team em Duelo</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#07070a;--panel:#101013;--panel2:#16161a;--panel3:#1c1c21;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.14);
  --red:#fc0025;--red-soft:rgba(252,0,37,.12);--red-glow:rgba(252,0,37,.25);
  --text:#f0f0f3;--text2:#868690;--text3:#3c3c44;
  --green:#22c55e;--green-soft:rgba(34,197,94,.12);
  --amber:#f59e0b;--amber-soft:rgba(245,158,11,.12);
  --blue:#3b82f6;--blue-soft:rgba(59,130,246,.12);
  --radius:14px;--font:'Poppins',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased;overflow-x:hidden}

.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.topbar-left{display:flex;align-items:center;gap:10px}
.back-btn{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;border:1px solid var(--border);background:transparent;color:var(--text2);text-decoration:none;font-size:14px;transition:.2s;flex-shrink:0}
.back-btn:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.game-title{font-size:15px;font-weight:800;color:var(--text)}
.game-title span{color:var(--red)}
.topbar-right{display:flex;align-items:center;gap:6px}
.chip{display:flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;background:var(--panel2);border:1px solid var(--border);font-size:11px;font-weight:700;color:var(--text);white-space:nowrap}

.main{max-width:640px;margin:0 auto;padding:16px 12px 60px}
.dtcard{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:18px;margin-bottom:14px;color:var(--text)}
.dtcard-title{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-bottom:12px}
.dtcard-sub{font-size:12.5px;color:var(--text2);line-height:1.55;margin-bottom:14px}

.dt-tabs{display:flex;gap:8px;margin-bottom:16px}
.dt-tab{flex:1;text-align:center;padding:10px;border-radius:10px;background:var(--panel2);border:1.5px solid var(--border);cursor:pointer;font-size:12.5px;font-weight:700;color:var(--text2);transition:.15s}
.dt-tab.active{border-color:var(--red);background:var(--red-soft);color:var(--red)}

.field label{display:block;font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-bottom:6px}
.field input{width:100%;background:var(--panel2);border:1.5px solid var(--border);border-radius:10px;padding:11px 12px;font-family:var(--font);font-size:14px;font-weight:700;color:var(--text);outline:none;transition:.15s}
.field input:focus{border-color:var(--red);background:var(--red-soft)}
.field-hint{font-size:11px;color:var(--text3);margin-top:6px}

.btn-dt{width:100%;padding:13px;border-radius:11px;border:none;background:var(--red);color:#fff;font-family:var(--font);font-size:14px;font-weight:800;cursor:pointer;transition:.15s;margin-top:14px}
.btn-dt:hover:not(:disabled){filter:brightness(1.1)}
.btn-dt:disabled{opacity:.5;cursor:not-allowed}
.btn-dt-ghost{width:100%;padding:11px;border-radius:11px;border:1px solid var(--border2);background:transparent;color:var(--text2);font-family:var(--font);font-size:12.5px;font-weight:700;cursor:pointer;margin-top:8px}
.btn-dt-ghost:hover{border-color:var(--red);color:var(--red)}

.dt-codigo-box{text-align:center;padding:22px;background:var(--panel2);border:1.5px dashed var(--border2);border-radius:12px;margin-bottom:14px}
.dt-codigo-valor{font-size:34px;font-weight:900;letter-spacing:6px;color:var(--red);font-variant-numeric:tabular-nums}
.dt-codigo-label{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-top:6px}

.dt-spinner{width:32px;height:32px;border:3px solid var(--border);border-top-color:var(--red);border-radius:50%;margin:0 auto 14px;animation:dt-spin 1s linear infinite}
@keyframes dt-spin{to{transform:rotate(360deg)}}

.dt-vs{display:flex;align-items:center;justify-content:center;gap:14px;margin-bottom:14px}
.dt-vs-lado{flex:1;text-align:center;padding:14px 10px;border-radius:12px;background:var(--panel2);border:1.5px solid var(--border)}
.dt-vs-lado.pronto{border-color:var(--green);background:var(--green-soft)}
.dt-vs-nome{font-size:12.5px;font-weight:700;margin-bottom:4px}
.dt-vs-status{font-size:10.5px;color:var(--text2)}
.dt-vs-lado.pronto .dt-vs-status{color:var(--green)}
.dt-vs-x{font-size:16px;font-weight:900;color:var(--text3)}

.dt-cap-bar{display:flex;align-items:center;justify-content:space-between;background:var(--panel2);border:1px solid var(--border);border-radius:11px;padding:12px 14px;margin-bottom:12px;position:sticky;top:56px;z-index:10}
.dt-cap-label{font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text2)}
.dt-cap-valor{font-size:18px;font-weight:900;font-variant-numeric:tabular-nums}
.dt-cap-valor.over{color:var(--red)}
.dt-cap-valor.ok{color:var(--green)}

.dt-slots{display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap}
.dt-slot{flex:1;min-width:56px;height:46px;border-radius:9px;background:var(--panel2);border:1.5px dashed var(--border2);display:flex;align-items:center;justify-content:center;font-size:10px;color:var(--text3);text-align:center;padding:2px;position:relative;cursor:pointer}
.dt-slot.filled{border-style:solid;border-color:var(--red);background:var(--red-soft);color:var(--text);font-weight:700;font-size:9.5px;flex-direction:column;gap:1px}
.dt-slot .dt-slot-x{position:absolute;top:-6px;right:-6px;width:16px;height:16px;border-radius:50%;background:var(--red);color:#fff;font-size:9px;display:flex;align-items:center;justify-content:center}

.dt-search{width:100%;background:var(--panel2);border:1.5px solid var(--border);border-radius:10px;padding:11px 12px;font-family:var(--font);font-size:13px;color:var(--text);outline:none;margin-bottom:10px}
.dt-search:focus{border-color:var(--red)}
.dt-lendas-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;max-height:360px;overflow-y:auto}
.dt-lenda{background:var(--panel2);border:1.5px solid var(--border);border-radius:10px;padding:9px 10px;cursor:pointer;transition:.12s}
.dt-lenda:hover{border-color:var(--border2)}
.dt-lenda.selecionada{border-color:var(--red);background:var(--red-soft)}
.dt-lenda.desabilitada{opacity:.35;cursor:not-allowed}
.dt-lenda-nome{font-size:12px;font-weight:700;color:var(--text)}
.dt-lenda-meta{font-size:10px;color:var(--text2);margin-top:2px;display:flex;justify-content:space-between}
.dt-lenda-ovr{font-weight:800;color:var(--amber)}

.dt-placar{display:flex;align-items:center;justify-content:center;gap:18px;margin-bottom:16px}
.dt-placar-lado{flex:1;text-align:center}
.dt-placar-nome{font-size:11.5px;font-weight:700;color:var(--text2);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px}
.dt-placar-num{font-size:40px;font-weight:900;font-variant-numeric:tabular-nums}
.dt-placar-num.vencedor{color:var(--green)}
.dt-placar-x{font-size:16px;color:var(--text3);font-weight:700}

.dt-resultado-msg{text-align:center;padding:14px;border-radius:11px;margin-bottom:16px;font-size:14px;font-weight:800}
.dt-resultado-msg.venceu{background:var(--green-soft);color:var(--green);border:1px solid rgba(34,197,94,.35)}
.dt-resultado-msg.perdeu{background:var(--red-soft);color:var(--red);border:1px solid var(--border-red,rgba(252,0,37,.3))}

.dt-destaque{display:flex;align-items:center;gap:10px;padding:9px 10px;background:var(--panel2);border:1px solid var(--border);border-radius:10px;margin-bottom:6px}
.dt-destaque-pts{font-size:15px;font-weight:900;color:var(--amber);min-width:34px;text-align:center}
.dt-destaque-txt{font-size:12px;color:var(--text)}
.dt-destaque-txt b{color:var(--text)}

.dt-empty{text-align:center;padding:20px;color:var(--text2);font-size:12.5px}
.dt-lista-espera{display:flex;flex-direction:column;gap:6px;margin-top:10px}
.dt-lista-item{display:flex;align-items:center;gap:8px;padding:8px 10px;background:var(--panel2);border:1px solid var(--border);border-radius:9px;font-size:12px}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <a href="/games.php" class="back-btn" title="Voltar"><i class="bi bi-arrow-left"></i></a>
    <span class="game-title">Starting<span>5x5</span></span>
  </div>
  <div class="topbar-right">
    <div class="chip" style="color:var(--amber)"><img src="../moeda.png" style="width:16px;height:16px;object-fit:contain;vertical-align:middle"><span id="chipSaldo"><?= $meuSaldo ?></span></div>
  </div>
</div>

<div class="main" id="dtMain">
  <div class="dtcard"><div class="dt-spinner"></div><p class="dt-empty">Carregando...</p></div>
</div>

<script>
const LENDAS = <?= json_encode($lendas, JSON_UNESCAPED_UNICODE) ?>;
const CAP_OVR = <?= DT_CAP_OVR ?>;
const TIME_SIZE = <?= DT_TIME_SIZE ?>;
const MEU_USER_ID = <?= $user_id ?>;
let ESTADO_INICIAL = <?= json_encode($estadoInicial) ?>;
let selecionados = [];
let travado = false; // trava o poll enquanto o usuário está mexendo na tela de montar time

function esc(s) {
  const d = document.createElement('div');
  d.textContent = s ?? '';
  return d.innerHTML;
}

async function dtPost(acao, params = {}) {
  const body = new URLSearchParams({ acao, ...params });
  const res = await fetch(window.location.href, { method: 'POST', body });
  return res.json();
}

// ── Tela: criar ou entrar ───────────────────────────────────────────────────
function renderCriarEntrar() {
  document.getElementById('dtMain').innerHTML = `
    <div class="dtcard">
      <div class="dtcard-title"><i class="bi bi-trophy me-1"></i>Dream Team em Duelo</div>
      <p class="dtcard-sub">Monte um time de 5 lendas dentro do teto salarial de ${CAP_OVR} OVR combinado, aposte moedas e desafie um amigo pra um confronto direto. Vencedor leva as duas apostas.</p>
      <div class="dt-tabs">
        <div class="dt-tab active" id="dtTabCriar" onclick="dtTrocarTab('criar')">Criar duelo</div>
        <div class="dt-tab" id="dtTabEntrar" onclick="dtTrocarTab('entrar')">Entrar com código</div>
        <div class="dt-tab" id="dtTabCpu" onclick="dtTrocarTab('cpu')">Vs. Máquina 🤖</div>
      </div>
      <div id="dtTabConteudo"></div>
    </div>`;
  dtTrocarTab('criar');
}

function dtTrocarTab(tab) {
  document.getElementById('dtTabCriar').classList.toggle('active', tab === 'criar');
  document.getElementById('dtTabEntrar').classList.toggle('active', tab === 'entrar');
  document.getElementById('dtTabCpu').classList.toggle('active', tab === 'cpu');
  const c = document.getElementById('dtTabConteudo');
  if (tab === 'criar') {
    c.innerHTML = `
      <div class="field">
        <label>Aposta (1 a 100 moedas)</label>
        <input type="number" id="dtAposta" min="1" max="100" value="20">
        <p class="field-hint">Debitada na hora — devolvida se ninguém entrar em 24h.</p>
      </div>
      <button class="btn-dt" id="dtBtnCriar" onclick="dtCriarDuelo()"><i class="bi bi-plus-circle me-2"></i>Criar duelo</button>`;
  } else if (tab === 'entrar') {
    c.innerHTML = `
      <div class="field">
        <label>Código do duelo</label>
        <input type="text" id="dtCodigo" maxlength="6" placeholder="Ex: A7K2QX" style="text-transform:uppercase;letter-spacing:3px;text-align:center;font-size:18px">
      </div>
      <button class="btn-dt" id="dtBtnEntrar" onclick="dtEntrarDuelo()"><i class="bi bi-box-arrow-in-right me-2"></i>Entrar no duelo</button>`;
  } else {
    c.innerHTML = `
      <div class="field">
        <label>Aposta (1 a 50 moedas)</label>
        <input type="number" id="dtApostaCpu" min="1" max="50" value="20">
        <p class="field-hint">A máquina monta o melhor time possível dentro do teto salarial — é para valer, por isso a aposta é menor. Vence e leva o dobro; perde e fica sem nada. Sem sala de espera, o confronto acontece assim que você confirmar seu time.</p>
      </div>
      <button class="btn-dt" id="dtBtnCpu" onclick="dtCriarVsCpu()"><i class="bi bi-cpu me-2"></i>Desafiar a máquina</button>`;
  }
}

async function dtCriarDuelo() {
  const btn = document.getElementById('dtBtnCriar');
  const aposta = parseInt(document.getElementById('dtAposta').value, 10);
  btn.disabled = true;
  const r = await dtPost('criar', { aposta });
  if (!r.ok) { alert(r.msg); btn.disabled = false; return; }
  await atualizar();
}

async function dtEntrarDuelo() {
  const btn = document.getElementById('dtBtnEntrar');
  const codigo = document.getElementById('dtCodigo').value.trim().toUpperCase();
  if (!codigo) return;
  btn.disabled = true;
  const r = await dtPost('entrar', { codigo });
  if (!r.ok) { alert(r.msg); btn.disabled = false; return; }
  await atualizar();
}

async function dtCriarVsCpu() {
  const btn = document.getElementById('dtBtnCpu');
  const aposta = parseInt(document.getElementById('dtApostaCpu').value, 10);
  btn.disabled = true;
  const r = await dtPost('criar_vs_cpu', { aposta });
  if (!r.ok) { alert(r.msg); btn.disabled = false; return; }
  await atualizar();
}

// ── Tela: aguardando oponente ────────────────────────────────────────────────
function renderAguardando(duelo) {
  document.getElementById('dtMain').innerHTML = `
    <div class="dtcard">
      <div class="dtcard-title"><i class="bi bi-hourglass-split me-1"></i>Aguardando oponente</div>
      <div class="dt-codigo-box">
        <div class="dt-codigo-valor">${esc(duelo.codigo)}</div>
        <div class="dt-codigo-label">Compartilhe esse código</div>
      </div>
      <p class="dtcard-sub" style="text-align:center;margin-bottom:4px">Aposta: <strong>${duelo.aposta} moedas</strong></p>
      <div class="dt-spinner"></div>
      <p class="dt-empty">Assim que alguém entrar com o código, a tela avança sozinha.</p>
      <button class="btn-dt-ghost" onclick="dtCancelar()"><i class="bi bi-x-circle me-1"></i>Cancelar e receber a aposta de volta</button>
    </div>`;
}

async function dtCancelar() {
  if (!confirm('Cancelar o duelo e receber a aposta de volta?')) return;
  const r = await dtPost('cancelar');
  if (!r.ok) { alert(r.msg); return; }
  await atualizar();
}

// ── Tela: montar time ────────────────────────────────────────────────────────
function dtSomaOvr() { return selecionados.reduce((s, l) => s + Number(l.ovr), 0); }

function renderMontarTime(duelo) {
  travado = true;
  selecionados = [];
  document.getElementById('dtMain').innerHTML = `
    <div class="dtcard" style="margin-bottom:8px">
      <div class="dtcard-title" style="margin-bottom:6px">Vs. ${esc(duelo.oponente_nome)}</div>
      <p class="dtcard-sub" style="margin-bottom:0">Aposta: <strong>${duelo.aposta} moedas</strong> · Monte seu time dentro do teto salarial.</p>
    </div>
    <div class="dt-cap-bar">
      <div>
        <div class="dt-cap-label">Teto salarial</div>
        <div class="dt-cap-valor ok" id="dtCapValor">0 / ${CAP_OVR}</div>
      </div>
      <div class="dt-slots" id="dtSlots" style="flex:1;margin-left:16px;margin-bottom:0"></div>
    </div>
    <div class="dtcard">
      <input type="text" class="dt-search" id="dtBusca" placeholder="Buscar lenda por nome..." oninput="renderLendasGrid()">
      <div class="dt-lendas-grid" id="dtLendasGrid"></div>
      <button class="btn-dt" id="dtBtnConfirmar" onclick="dtConfirmarTime(${duelo.id})" disabled><i class="bi bi-check-circle me-2"></i>Confirmar time</button>
    </div>`;
  renderSlots();
  renderLendasGrid();
}

function renderSlots() {
  const wrap = document.getElementById('dtSlots');
  const cells = [];
  for (let i = 0; i < TIME_SIZE; i++) {
    const l = selecionados[i];
    cells.push(l
      ? `<div class="dt-slot filled" onclick="dtRemover(${l.id})"><span>${esc(l.nome.split(' ').slice(-1)[0])}</span><span>${l.ovr}</span><span class="dt-slot-x"><i class="bi bi-x"></i></span></div>`
      : `<div class="dt-slot">vazio</div>`);
  }
  wrap.innerHTML = cells.join('');

  const soma = dtSomaOvr();
  const capEl = document.getElementById('dtCapValor');
  capEl.textContent = `${soma} / ${CAP_OVR}`;
  capEl.className = 'dt-cap-valor ' + (soma > CAP_OVR ? 'over' : 'ok');
  document.getElementById('dtBtnConfirmar').disabled = selecionados.length !== TIME_SIZE || soma > CAP_OVR;
}

function renderLendasGrid() {
  const grid = document.getElementById('dtLendasGrid');
  const q = (document.getElementById('dtBusca')?.value || '').trim().toLowerCase();
  const idsSelecionados = new Set(selecionados.map(l => l.id));
  const lista = LENDAS.filter(l => !q || l.nome.toLowerCase().includes(q));
  if (!lista.length) { grid.innerHTML = '<div class="dt-empty" style="grid-column:1/-1">Nenhuma lenda encontrada.</div>'; return; }
  grid.innerHTML = lista.map(l => {
    const sel = idsSelecionados.has(l.id);
    const cheio = selecionados.length >= TIME_SIZE && !sel;
    return `<div class="dt-lenda ${sel ? 'selecionada' : ''} ${cheio ? 'desabilitada' : ''}" onclick="${cheio ? '' : `dtToggleLenda(${l.id})`}">
      <div class="dt-lenda-nome">${esc(l.nome)}</div>
      <div class="dt-lenda-meta"><span>${esc(l.time || '')} · ${l.grupo}</span><span class="dt-lenda-ovr">${l.ovr}</span></div>
    </div>`;
  }).join('');
}

function dtToggleLenda(id) {
  const idx = selecionados.findIndex(l => l.id === id);
  if (idx >= 0) { selecionados.splice(idx, 1); }
  else {
    if (selecionados.length >= TIME_SIZE) return;
    const l = LENDAS.find(x => x.id === id);
    if (l) selecionados.push(l);
  }
  renderSlots();
  renderLendasGrid();
}
function dtRemover(id) { dtToggleLenda(id); }

async function dtConfirmarTime(dueloId) {
  const btn = document.getElementById('dtBtnConfirmar');
  btn.disabled = true;
  const jogadores = selecionados.map(l => l.id).join(',');
  const r = await dtPost('montar_time', { jogadores });
  if (!r.ok) { alert(r.msg); btn.disabled = false; return; }
  travado = false;
  await atualizar();
}

// ── Tela: aguardando oponente montar o time ─────────────────────────────────
function renderAguardandoOponenteMontar(duelo) {
  document.getElementById('dtMain').innerHTML = `
    <div class="dtcard">
      <div class="dtcard-title"><i class="bi bi-hourglass-split me-1"></i>Time confirmado</div>
      <div class="dt-vs">
        <div class="dt-vs-lado pronto"><div class="dt-vs-nome">Você</div><div class="dt-vs-status"><i class="bi bi-check-circle-fill"></i> Pronto</div></div>
        <div class="dt-vs-x">VS</div>
        <div class="dt-vs-lado ${duelo.oponente_pronto ? 'pronto' : ''}"><div class="dt-vs-nome">${esc(duelo.oponente_nome)}</div><div class="dt-vs-status">${duelo.oponente_pronto ? '<i class="bi bi-check-circle-fill"></i> Pronto' : 'Montando o time...'}</div></div>
      </div>
      <div class="dt-spinner"></div>
      <p class="dt-empty">Assim que os dois estiverem prontos, o confronto acontece na hora.</p>
    </div>`;
}

// ── Tela: resultado ──────────────────────────────────────────────────────────
function renderResultado(duelo) {
  const r = duelo.resultado;
  const meuPlacar = duelo.meu_lado === 'a' ? r.placar_a : r.placar_b;
  const oponentePlacar = duelo.meu_lado === 'a' ? r.placar_b : r.placar_a;
  document.getElementById('dtMain').innerHTML = `
    <div class="dtcard">
      <div class="dt-resultado-msg ${duelo.eu_venci ? 'venceu' : 'perdeu'}">
        ${duelo.eu_venci ? `🏆 Você venceu! +${duelo.aposta * 2} moedas` : `Você perdeu essa. -${duelo.aposta} moedas`}
      </div>
      <div class="dt-placar">
        <div class="dt-placar-lado">
          <div class="dt-placar-nome">Você</div>
          <div class="dt-placar-num ${duelo.eu_venci ? 'vencedor' : ''}">${meuPlacar}</div>
        </div>
        <div class="dt-placar-x">×</div>
        <div class="dt-placar-lado">
          <div class="dt-placar-nome">${esc(duelo.oponente_nome)}</div>
          <div class="dt-placar-num ${!duelo.eu_venci ? 'vencedor' : ''}">${oponentePlacar}</div>
        </div>
      </div>
      <div class="dtcard-title">Destaques do confronto</div>
      ${r.destaques.map(d => {
        const souEu = d.lado === duelo.meu_lado;
        return `<div class="dt-destaque">
          <div class="dt-destaque-pts">${d.pontos}</div>
          <div class="dt-destaque-txt"><b>${esc(d.nome)}</b> (${souEu ? 'seu time' : esc(duelo.oponente_nome)}) — ${esc(d.frase)}.</div>
        </div>`;
      }).join('')}
    </div>
    <button class="btn-dt" onclick="dtNovoDuelo(${duelo.id})"><i class="bi bi-arrow-repeat me-2"></i>Jogar de novo</button>`;
}

// Duelo já visto e dispensado pelo "Jogar de novo" — o servidor continua
// devolvendo ele como "o mais recente" até um duelo de verdade ser criado,
// então o poll ignora essa mesma id pra não puxar a tela de resultado de
// volta sozinha alguns segundos depois.
let dueloDispensadoId = null;

function dtNovoDuelo(dueloId) {
  dueloDispensadoId = dueloId;
  ESTADO_INICIAL = null;
  renderCriarEntrar();
}

// ── Orquestração ─────────────────────────────────────────────────────────────
function renderTela(duelo) {
  if (!duelo) { renderCriarEntrar(); return; }
  if (duelo.status === 'simulado' && duelo.id === dueloDispensadoId) { renderCriarEntrar(); return; }
  if (duelo.status === 'aguardando') { renderAguardando(duelo); return; }
  if (duelo.status === 'montando') {
    if (duelo.meu_pronto) { renderAguardandoOponenteMontar(duelo); return; }
    renderMontarTime(duelo);
    return;
  }
  if (duelo.status === 'simulado') { renderResultado(duelo); return; }
  renderCriarEntrar();
}

async function atualizar() {
  if (travado) return;
  try {
    const r = await dtPost('estado');
    if (!r.ok) return;
    document.getElementById('chipSaldo').textContent = r.pontos;
    renderTela(r.duelo);
  } catch (e) { /* silencioso — próximo poll tenta de novo */ }
}

renderTela(ESTADO_INICIAL);
setInterval(atualizar, 4000);
</script>

</body>
</html>
