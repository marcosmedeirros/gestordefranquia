<?php
/**
 * ── LENDAS DA QUADRA: O DUELO ONLINE ─────────────────────────────────
 *
 * Uma partida é uma linha de lendas_partidas, e o estado inteiro (o mesmo
 * do motor) mora em JSON nela. Os dois navegadores perguntam pelo estado a
 * cada ~1,5s; quem tem a vez manda jogadas. Toda jogada abre transação e
 * TRAVA a linha (FOR UPDATE): dois cliques ao mesmo tempo — os dois GMs,
 * ou duas abas — não pisam um no outro.
 *
 * ── COMO DOIS GMs SE ENCONTRAM ───────────────────────────────────────
 *
 *   POR CÓDIGO — um cria, recebe um código de 6 letras (e um link), manda
 *   pra quem quiser; o outro entra com ele. Serve pra desafiar alguém
 *   específico, qualquer que seja o baralho dos dois.
 *
 *   FILA — "procurar adversário". Junta quem tem baralho de FORÇA
 *   parecida (@see lendasPoder): no teste de máquina contra máquina, um
 *   baralho de dez pacotes ganhava 94% das vezes do inicial, e um novato
 *   que só cruzasse com veteranos desistiria do jogo. A janela de força
 *   alarga com a espera, pra ninguém ficar parado na fila pra sempre.
 *
 * ── O RELÓGIO ────────────────────────────────────────────────────────
 *
 * Cada turno tem LENDAS_SEGUNDOS_TURNO. Estourou: o turno passa sozinho e
 * conta um estouro; no terceiro, derrota por abandono. Quem confere o
 * relógio é quem perguntar pelo estado depois do prazo — não precisa de
 * cron: o adversário que está esperando é o primeiro a perguntar.
 */

require_once __DIR__ . '/lendas_conta.php';

const LENDAS_SEGUNDOS_TURNO = 75;
const LENDAS_ESTOUROS_MAX = 3;
/** Partida esperando adversário há mais que isto deixa de valer. */
const LENDAS_ESPERA_MAX_MIN = 10;

function lendasAgora(): string { return date('Y-m-d H:i:s'); }
function lendasPrazo(): string { return date('Y-m-d H:i:s', time() + LENDAS_SEGUNDOS_TURNO); }

/** A partida em que o GM está (esperando ou jogando), se houver. */
function lendasOnMinha(PDO $pdo, int $uid): ?array
{
    lendasTabelas($pdo);
    $st = $pdo->prepare("SELECT * FROM lendas_partidas
        WHERE (a_uid = ? OR b_uid = ?) AND (status = 'jogando' OR (status = 'aguardando' AND criado > NOW() - INTERVAL " . LENDAS_ESPERA_MAX_MIN . " MINUTE))
        ORDER BY id DESC LIMIT 1");
    $st->execute([$uid, $uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** A última partida terminada do GM (pra mostrar o resultado a quem voltar). */
function lendasOnUltimaFim(PDO $pdo, int $uid, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM lendas_partidas WHERE id = ? AND (a_uid = ? OR b_uid = ?)");
    $st->execute([$id, $uid, $uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function lendasCodigoNovo(PDO $pdo): string
{
    $letras = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $c = '';
        for ($i = 0; $i < 6; $i++) $c .= $letras[random_int(0, strlen($letras) - 1)];
        $st = $pdo->prepare('SELECT 1 FROM lendas_partidas WHERE codigo = ?');
        $st->execute([$c]);
    } while ($st->fetchColumn());
    return $c;
}

/**
 * Cria uma partida esperando adversário (por código ou na fila) — ou, na
 * fila, já entra numa que alguém de força parecida abriu.
 *
 * @return array linha da partida
 */
function lendasOnProcurar(PDO $pdo, int $uid, string $nome, string $modo): array
{
    if ($ja = lendasOnMinha($pdo, $uid)) return $ja;
    $baralho = lendasBaralho($pdo, $uid);
    $poder = lendasPoder($baralho);

    if ($modo === 'fila') {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT * FROM lendas_partidas
            WHERE status = 'aguardando' AND modo = 'fila' AND a_uid <> ?
              AND criado > NOW() - INTERVAL " . LENDAS_ESPERA_MAX_MIN . " MINUTE
              AND ABS(poder_a - ?) <= 30 + TIMESTAMPDIFF(SECOND, criado, NOW()) / 2
            ORDER BY ABS(poder_a - ?) ASC, id ASC LIMIT 1 FOR UPDATE");
        $st->execute([$uid, $poder, $poder]);
        $linha = $st->fetch(PDO::FETCH_ASSOC);
        if ($linha) {
            $linha = lendasOnComecar($pdo, $linha, $uid, $nome, $baralho);
            $pdo->commit();
            return $linha;
        }
        $pdo->commit();
    }

    $espera = json_encode(['a' => ['nome' => $nome, 'baralho' => $baralho]]);
    $pdo->prepare('INSERT INTO lendas_partidas (codigo, modo, a_uid, poder_a, status, estado) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$modo === 'codigo' ? lendasCodigoNovo($pdo) : null, $modo, $uid, $poder, 'aguardando', $espera]);
    return lendasOnUltimaFim($pdo, $uid, (int)$pdo->lastInsertId());
}

/** Entra pela partida de um código. @return array|string linha ou erro */
function lendasOnEntrar(PDO $pdo, int $uid, string $nome, string $codigo)
{
    if (lendasOnMinha($pdo, $uid)) return 'Você já está numa partida.';
    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT * FROM lendas_partidas WHERE codigo = ? AND status = 'aguardando'
        AND criado > NOW() - INTERVAL " . LENDAS_ESPERA_MAX_MIN . " MINUTE FOR UPDATE");
    $st->execute([strtoupper(trim($codigo))]);
    $linha = $st->fetch(PDO::FETCH_ASSOC);
    if (!$linha) { $pdo->rollBack(); return 'Código não encontrado (ou a partida já começou).'; }
    if ((int)$linha['a_uid'] === $uid) { $pdo->rollBack(); return 'Esse código é seu — mande pra outro GM.'; }
    $linha = lendasOnComecar($pdo, $linha, $uid, $nome, lendasBaralho($pdo, $uid));
    $pdo->commit();
    return $linha;
}

/** Os dois lados chegaram: a partida nasce. Quem criou é o A e começa. */
function lendasOnComecar(PDO $pdo, array $linha, int $uidB, string $nomeB, array $baralhoB): array
{
    $espera = json_decode($linha['estado'], true);
    $e = lendasNovaPartida(
        ['uid' => (int)$linha['a_uid'], 'nome' => $espera['a']['nome'], 'baralho' => $espera['a']['baralho']],
        ['uid' => $uidB, 'nome' => $nomeB, 'baralho' => $baralhoB],
        random_int(1, 2000000000)
    );
    $pdo->prepare("UPDATE lendas_partidas SET b_uid = ?, status = 'jogando', estado = ?, prazo = ? WHERE id = ?")
        ->execute([$uidB, json_encode($e), lendasPrazo(), $linha['id']]);
    $linha['b_uid'] = $uidB; $linha['status'] = 'jogando'; $linha['estado'] = json_encode($e); $linha['prazo'] = lendasPrazo();
    return $linha;
}

function lendasOnCancelar(PDO $pdo, int $uid): void
{
    $pdo->prepare("UPDATE lendas_partidas SET status = 'cancelada' WHERE a_uid = ? AND status = 'aguardando'")->execute([$uid]);
}

/** O lado do GM nessa partida. */
function lendasOnLado(array $linha, int $uid): string
{
    return (int)$linha['a_uid'] === $uid ? 'A' : 'B';
}

/**
 * Abre a partida pra ler ou mexer: trava a linha, aplica o relógio, roda
 * $mexer (se houver), salva, e fecha a partida se ela acabou.
 *
 * @param callable|null $mexer fn(array &$e, string $lado): ?string  (erro ou null)
 * @return array{linha: array, e: array, lado: string, erro: ?string, final: ?array}
 */
function lendasOnMexer(PDO $pdo, int $uid, int $id, ?callable $mexer = null): array
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM lendas_partidas WHERE id = ? AND (a_uid = ? OR b_uid = ?) FOR UPDATE');
        $st->execute([$id, $uid, $uid]);
        $linha = $st->fetch(PDO::FETCH_ASSOC);
        if (!$linha || $linha['status'] !== 'jogando' && $linha['status'] !== 'fim') {
            $pdo->rollBack();
            return ['linha' => $linha, 'e' => null, 'lado' => $linha ? lendasOnLado($linha, $uid) : 'A', 'erro' => null, 'final' => null];
        }
        $e = json_decode($linha['estado'], true);
        $lado = lendasOnLado($linha, $uid);
        $mudou = false;
        $erro = null;

        if ($linha['status'] === 'jogando') {
            // O relógio: prazo vencido passa o turno e conta um estouro. Quem
            // recebe a vez ganha um prazo cheio, contado de agora.
            if (!$e['fim'] && $linha['prazo'] && strtotime($linha['prazo']) < time()) {
                $vez = $e['vez'];
                $e['jogadores'][$vez]['timeouts']++;
                lendasEvento($e, 'estouro', ['lado' => $vez, 'n' => $e['jogadores'][$vez]['timeouts']]);
                if ($e['jogadores'][$vez]['timeouts'] >= LENDAS_ESTOUROS_MAX) lendasEncerrar($e, lendasOutro($vez), 'abandono');
                else lendasAgir($e, $vez, ['tipo' => 'passar']);
                $linha['prazo'] = lendasPrazo();
                $mudou = true;
            }
            if ($mexer && !$e['fim']) {
                $vezAntes = $e['vez'];
                $erro = $mexer($e, $lado);
                if ($erro === null) {
                    $mudou = true;
                    if ($e['vez'] !== $vezAntes) $linha['prazo'] = lendasPrazo();
                }
            }
        }

        $final = null;
        if ($mudou) {
            if ($e['fim']) {
                $linha['status'] = 'fim';
                $linha['vencedor_uid'] = $e['vencedor'] === 'A' ? $linha['a_uid'] : $linha['b_uid'];
                $linha['motivo'] = $e['motivo'];
            }
            $pdo->prepare('UPDATE lendas_partidas SET estado = ?, prazo = ?, status = ?, vencedor_uid = ?, motivo = ? WHERE id = ?')
                ->execute([json_encode($e), $linha['prazo'], $linha['status'], $linha['vencedor_uid'], $linha['motivo'], $id]);
            if ($e['fim'] && !(int)$linha['premiado']) {
                $final = lendasFinalizar($pdo, $linha, $e);
                $pdo->prepare('UPDATE lendas_partidas SET premiado = 1, estado = ? WHERE id = ?')
                    ->execute([json_encode($e + ['resultado' => $final]), $id]);
                $e['resultado'] = $final;
                $linha['premiado'] = 1;
            }
        }
        $pdo->commit();
        return ['linha' => $linha, 'e' => $e, 'lado' => $lado, 'erro' => $erro, 'final' => $e['resultado'] ?? null];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[lendas] online: ' . $ex->getMessage());
        return ['linha' => null, 'e' => null, 'lado' => 'A', 'erro' => 'Falha no servidor. Tente de novo.', 'final' => null];
    }
}
