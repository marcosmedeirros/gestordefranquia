<?php
/**
 * ── FBA SHOWDOWN: A PARTIDA ──────────────────────────────────────────
 *
 * Uma partida é uma linha de psd_partidas, e o estado inteiro (o mesmo do
 * motor) mora em JSON nela. Os dois navegadores perguntam pelo estado a
 * cada ~1,5s. Toda escrita abre transação e TRAVA a linha (FOR UPDATE):
 * dois cliques ao mesmo tempo — os dois GMs, ou duas abas — não pisam um
 * no outro.
 *
 * ── OS DOIS ESCOLHEM ESCONDIDO ───────────────────────────────────────
 *
 * É o que mais muda em relação ao Lendas. Lá existia "a vez"; aqui cada
 * lado guarda a escolha na sua coluna e o turno SÓ RESOLVE quando as duas
 * estão lá. Enquanto falta uma, nenhum dos dois sabe o que o outro
 * escolheu — e é daí que vem a leitura, que é o jogo.
 *
 * Quem dispara a resolução é quem perguntar pelo estado depois da segunda
 * escolha entrar. Não precisa de cron: o adversário que está esperando é o
 * primeiro a perguntar.
 *
 * ── O RELÓGIO ────────────────────────────────────────────────────────
 *
 * Cada turno tem PSD_SEG_TURNO. Estourou, a máquina escolhe pelo ausente e
 * conta um estouro; no terceiro, derrota por abandono. Sem isso, quem está
 * perdendo fecha a aba e prende a aposta do outro pra sempre.
 *
 * ── A APOSTA ─────────────────────────────────────────────────────────
 *
 * Igual aos outros jogos: cada um paga a entrada ao entrar, o prêmio é a
 * soma das duas e o vencedor leva. Desistir ANTES de o adversário chegar
 * devolve; depois que a partida começou, o dinheiro está em jogo.
 *
 * A moeda é games_usuarios.pontos, a mesma do STOP e do Lendas.
 *
 * @see games/core/psd_motor.php — as regras da partida
 */

require_once __DIR__ . '/psd_ia.php';

/** Segundos por turno. */
const PSD_SEG_TURNO = 60;
/** Estouros de relógio antes de perder por abandono. */
const PSD_ESTOUROS_MAX = 3;
/** Partida esperando adversário há mais que isto deixa de valer. */
const PSD_ESPERA_MAX_MIN = 10;
/** Teto da aposta, o mesmo do STOP. */
const PSD_APOSTA_MAX = 5000;

function psdAgora(): string { return date('Y-m-d H:i:s'); }
function psdPrazo(): string { return date('Y-m-d H:i:s', time() + PSD_SEG_TURNO); }

// ─────────────────────────────── tabelas ───────────────────────────────

function psdTabelas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS psd_partidas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo CHAR(6) NULL,
        modo VARCHAR(8) NOT NULL DEFAULT 'codigo',
        a_uid INT NOT NULL,
        b_uid INT NULL,
        a_nome VARCHAR(80) NOT NULL DEFAULT '',
        b_nome VARCHAR(80) NOT NULL DEFAULT '',
        aposta INT NOT NULL DEFAULT 0,
        premio INT NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT 'aguardando',
        estado MEDIUMTEXT NULL,
        escolha_a VARCHAR(60) NULL,
        escolha_b VARCHAR(60) NULL,
        estouros_a TINYINT NOT NULL DEFAULT 0,
        estouros_b TINYINT NOT NULL DEFAULT 0,
        seq INT NOT NULL DEFAULT 0,
        prazo DATETIME NULL,
        vencedor_uid INT NULL,
        motivo VARCHAR(20) NULL,
        pago TINYINT(1) NOT NULL DEFAULT 0,
        criado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_psd_codigo (codigo),
        KEY idx_psd_a (a_uid), KEY idx_psd_b (b_uid), KEY idx_psd_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS psd_ranking (
        id_usuario INT NOT NULL,
        temporada CHAR(7) NOT NULL,
        pontos INT NOT NULL DEFAULT 0,
        vitorias INT NOT NULL DEFAULT 0,
        derrotas INT NOT NULL DEFAULT 0,
        PRIMARY KEY (id_usuario, temporada)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ─────────────────────────────── moeda ───────────────────────────────

function psdSaldo(PDO $pdo, int $uid): int
{
    $st = $pdo->prepare('SELECT COALESCE(pontos,0) FROM games_usuarios WHERE id = ?');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

function psdCreditar(PDO $pdo, int $uid, int $moedas): void
{
    if ($moedas > 0) {
        $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')->execute([$moedas, $uid]);
    }
}

/**
 * Cobra a entrada com a linha do GM travada. Devolve false se não dá.
 * Tem que rodar DENTRO de uma transação já aberta.
 */
function psdCobrar(PDO $pdo, int $uid, int $quanto): bool
{
    if ($quanto <= 0) return true;
    $st = $pdo->prepare('SELECT COALESCE(pontos,0) FROM games_usuarios WHERE id = ? FOR UPDATE');
    $st->execute([$uid]);
    if ((int)$st->fetchColumn() < $quanto) return false;
    $pdo->prepare('UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ?')->execute([$quanto, $uid]);
    return true;
}

// ─────────────────────────────── achar a partida ───────────────────────────────

/**
 * A partida em que o GM está agora (esperando ou jogando), se houver.
 *
 * $incluirFim traz também a que ACABOU de terminar. Só a tela usa isso:
 * sem ele, a partida some no instante do nocaute e ninguém vê quem ganhou
 * nem quanto levou. Quem vai ABRIR outra partida pergunta sem ele — senão
 * o resultado na tela viraria "você já está numa partida" por cinco
 * minutos.
 */
function psdMinha(PDO $pdo, int $uid, bool $incluirFim = false): ?array
{
    psdTabelas($pdo);
    $fim = $incluirFim
        ? " OR (status = 'fim' AND atualizado > NOW() - INTERVAL 5 MINUTE)"
        : '';
    $st = $pdo->prepare("SELECT * FROM psd_partidas
        WHERE (a_uid = ? OR b_uid = ?)
          AND (status = 'jogando'
               OR (status = 'aguardando' AND criado > NOW() - INTERVAL " . PSD_ESPERA_MAX_MIN . " MINUTE)
               $fim)
        ORDER BY id DESC LIMIT 1");
    $st->execute([$uid, $uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function psdPorId(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM psd_partidas WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function psdCodigoNovo(PDO $pdo): string
{
    /* Sem vogal e sem 0/O/1/I: o código é ditado no grupo, e ninguém
       quer explicar se é o "o" ou o zero. */
    $letras = 'BCDFGHJKLMNPQRSTVWXYZ23456789';
    for ($t = 0; $t < 40; $t++) {
        $c = '';
        for ($i = 0; $i < 6; $i++) $c .= $letras[random_int(0, strlen($letras) - 1)];
        $st = $pdo->prepare('SELECT 1 FROM psd_partidas WHERE codigo = ?');
        $st->execute([$c]);
        if (!$st->fetchColumn()) return $c;
    }
    throw new RuntimeException('não consegui gerar código');
}

/** Qual lado o GM é nesta partida. */
function psdMeuLado(array $p, int $uid): ?string
{
    if ((int)$p['a_uid'] === $uid) return 'a';
    if ((int)$p['b_uid'] === $uid) return 'b';
    return null;
}

// ─────────────────────────────── abrir e entrar ───────────────────────────────

/**
 * Abre uma partida. $modo é 'codigo' (espera alguém com o código),
 * 'fila' (espera qualquer um com a mesma aposta) ou 'treino' (começa já,
 * contra a máquina, sem dinheiro).
 *
 * @return array{ok:bool, erro?:string, id?:int}
 */
function psdAbrir(PDO $pdo, int $uid, string $nome, string $modo, int $aposta): array
{
    psdTabelas($pdo);

    if (psdMinha($pdo, $uid)) return ['ok' => false, 'erro' => 'Você já está numa partida.'];

    $aposta = ($modo === 'treino') ? 0 : max(0, min(PSD_APOSTA_MAX, $aposta));

    $pdo->beginTransaction();
    try {
        if (!psdCobrar($pdo, $uid, $aposta)) {
            $pdo->rollBack();
            return ['ok' => false, 'erro' => 'Você não tem ' . number_format($aposta, 0, ',', '.') . ' moedas.'];
        }

        if ($modo === 'fila') {
            /* Procura alguém já esperando na fila com a MESMA aposta. Sem
               isso teria que existir uma regra de "quem paga a diferença",
               e não existe: a entrada é igual pros dois. */
            $st = $pdo->prepare("SELECT * FROM psd_partidas
                WHERE status = 'aguardando' AND modo = 'fila' AND aposta = ? AND a_uid <> ?
                  AND criado > NOW() - INTERVAL " . PSD_ESPERA_MAX_MIN . " MINUTE
                ORDER BY id ASC LIMIT 1 FOR UPDATE");
            $st->execute([$aposta, $uid]);
            $sala = $st->fetch(PDO::FETCH_ASSOC);
            if ($sala) {
                psdComecar($pdo, (int)$sala['id'], $uid, $nome);
                $pdo->commit();
                return ['ok' => true, 'id' => (int)$sala['id']];
            }
        }

        $codigo = ($modo === 'codigo') ? psdCodigoNovo($pdo) : null;
        $pdo->prepare('INSERT INTO psd_partidas (codigo, modo, a_uid, a_nome, aposta) VALUES (?,?,?,?,?)')
            ->execute([$codigo, $modo, $uid, mb_substr($nome, 0, 80), $aposta]);
        $id = (int)$pdo->lastInsertId();

        if ($modo === 'treino') psdComecar($pdo, $id, 0, 'Máquina');

        $pdo->commit();
        return ['ok' => true, 'id' => $id];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'erro' => 'Não consegui abrir a partida.'];
    }
}

/** Entra numa partida pelo código, pagando a mesma entrada. */
function psdEntrar(PDO $pdo, int $uid, string $nome, string $codigo): array
{
    psdTabelas($pdo);

    if (psdMinha($pdo, $uid)) return ['ok' => false, 'erro' => 'Você já está numa partida.'];

    $codigo = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $codigo));
    if (strlen($codigo) !== 6) return ['ok' => false, 'erro' => 'O código tem 6 letras.'];

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM psd_partidas WHERE codigo = ? FOR UPDATE");
        $st->execute([$codigo]);
        $p = $st->fetch(PDO::FETCH_ASSOC);

        if (!$p)                              { $pdo->rollBack(); return ['ok' => false, 'erro' => 'Código não encontrado.']; }
        if ($p['status'] !== 'aguardando')    { $pdo->rollBack(); return ['ok' => false, 'erro' => 'Essa partida já começou.']; }
        if ((int)$p['a_uid'] === $uid)        { $pdo->rollBack(); return ['ok' => false, 'erro' => 'Essa partida é sua.']; }

        $aposta = (int)$p['aposta'];
        if (!psdCobrar($pdo, $uid, $aposta)) {
            $pdo->rollBack();
            return ['ok' => false, 'erro' => 'A entrada é ' . number_format($aposta, 0, ',', '.') . ' moedas e você não tem.'];
        }

        psdComecar($pdo, (int)$p['id'], $uid, $nome);
        $pdo->commit();
        return ['ok' => true, 'id' => (int)$p['id']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'erro' => 'Não consegui entrar.'];
    }
}

/**
 * Começa: sorteia os dois times e liga o relógio.
 * Roda dentro da transação de quem chamou.
 */
function psdComecar(PDO $pdo, int $id, int $bUid, string $bNome): void
{
    $p = psdPorId($pdo, $id);
    if (!$p) return;

    /* A semente sai do id da partida e do relógio: duas partidas abertas no
       mesmo segundo não podem sortear o mesmo time. */
    $semente = ($id * 2654435761 + (int)(microtime(true) * 1000)) & 0x7FFFFFFF;

    $e = psdNovaPartida($semente, (string)$p['a_nome'], $bNome);
    $premio = (int)$p['aposta'] * 2;

    $pdo->prepare("UPDATE psd_partidas
                      SET b_uid = ?, b_nome = ?, status = 'jogando', estado = ?,
                          premio = ?, prazo = ?, seq = 1
                    WHERE id = ?")
        ->execute([$bUid ?: null, mb_substr($bNome, 0, 80), json_encode($e), $premio, psdPrazo(), $id]);
}

/** Desiste de esperar. Só devolve enquanto ninguém entrou. */
function psdDesistirDaEspera(PDO $pdo, int $uid): array
{
    psdTabelas($pdo);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM psd_partidas WHERE a_uid = ? AND status = 'aguardando' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $st->execute([$uid]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) { $pdo->rollBack(); return ['ok' => false, 'erro' => 'Nada pra cancelar.']; }

        psdCreditar($pdo, $uid, (int)$p['aposta']);
        $pdo->prepare("UPDATE psd_partidas SET status = 'cancelada' WHERE id = ?")->execute([(int)$p['id']]);
        $pdo->commit();
        return ['ok' => true, 'devolvido' => (int)$p['aposta']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'erro' => 'Não consegui cancelar.'];
    }
}

// ─────────────────────────────── o turno ───────────────────────────────

/**
 * Este lado ainda deve uma escolha?
 *
 * No turno normal, os dois devem. No turno de REPOSIÇÃO, só quem perdeu o
 * bicho — o outro não escolhe nada, e o motor não deixa ele agir.
 */
function psdFaltaEscolha(array $e, array $p, string $lado): bool
{
    if (!empty($e['fim'])) return false;

    $reposicao = $e['lados']['a']['precisa_trocar'] || $e['lados']['b']['precisa_trocar'];
    if ($reposicao && !$e['lados'][$lado]['precisa_trocar']) return false;

    return empty($p['escolha_' . $lado]);
}

/** Guarda a escolha. Não resolve nada: quem resolve é psdVer(). */
function psdEscolher(PDO $pdo, int $uid, array $escolha): array
{
    psdTabelas($pdo);
    $pdo->beginTransaction();
    try {
        $p = psdMinha($pdo, $uid);
        if (!$p) { $pdo->rollBack(); return ['ok' => false, 'erro' => 'Você não está numa partida.']; }

        $st = $pdo->prepare('SELECT * FROM psd_partidas WHERE id = ? FOR UPDATE');
        $st->execute([(int)$p['id']]);
        $p = $st->fetch(PDO::FETCH_ASSOC);

        $lado = psdMeuLado($p, $uid);
        if (!$lado || $p['status'] !== 'jogando') { $pdo->rollBack(); return ['ok' => false, 'erro' => 'A partida não está em andamento.']; }

        $e = json_decode((string)$p['estado'], true);
        if (!$e) { $pdo->rollBack(); return ['ok' => false, 'erro' => 'Estado perdido.']; }

        if (!psdFaltaEscolha($e, $p, $lado)) { $pdo->rollBack(); return ['ok' => true, 'ja' => true]; }

        $tipo = ($escolha['tipo'] ?? '') === 'trocar' ? 'trocar' : 'golpe';
        $i    = max(0, min(5, (int)($escolha['i'] ?? 0)));

        // Confere do lado de cá antes de aceitar: a tela pode estar velha.
        $meu = $e['lados'][$lado];
        if ($tipo === 'trocar') {
            $vivos = [];
            foreach ($meu['time'] as $k => $b) if ($b['hp'] > 0) $vivos[] = $k;
            if (!in_array($i, $vivos, true) || $i === $meu['ativo']) {
                $pdo->rollBack(); return ['ok' => false, 'erro' => 'Não dá pra trocar por esse.'];
            }
        } else {
            if ($e['lados'][$lado]['precisa_trocar']) { $pdo->rollBack(); return ['ok' => false, 'erro' => 'Você precisa pôr outro em quadra.']; }
            if ($i > 3) { $pdo->rollBack(); return ['ok' => false, 'erro' => 'Golpe inválido.']; }
        }

        $pdo->prepare("UPDATE psd_partidas SET escolha_$lado = ? WHERE id = ?")
            ->execute([json_encode(['tipo' => $tipo, 'i' => $i]), (int)$p['id']]);

        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'erro' => 'Não consegui registrar a jogada.'];
    }
}

/**
 * ── RESOLVER SE DER ──────────────────────────────────────────────────
 *
 * Roda a cada leitura do estado, dentro da transação com a linha travada.
 * Resolve o turno quando:
 *   · as duas escolhas estão lá; ou
 *   · o prazo estourou — e aí a máquina escolhe pelo ausente e ele leva
 *     um estouro. No terceiro, perde por abandono.
 *
 * No TREINO o lado B é sempre a máquina, então ele nunca falta.
 */
function psdResolverSeDer(PDO $pdo, array &$p): bool
{
    if ($p['status'] !== 'jogando') return false;

    $e = json_decode((string)$p['estado'], true);
    if (!$e || !empty($e['fim'])) return false;

    $treino  = ((int)$p['b_uid']) === 0 || $p['b_uid'] === null;
    $prazoVenceu = strtotime((string)$p['prazo']) <= time();

    // No treino a máquina escolhe na hora, sem relógio.
    if ($treino && psdFaltaEscolha($e, $p, 'b')) {
        $p['escolha_b'] = json_encode(psdIA($e, 'b'));
    }

    $faltaA = psdFaltaEscolha($e, $p, 'a');
    $faltaB = psdFaltaEscolha($e, $p, 'b');

    if (($faltaA || $faltaB) && !$prazoVenceu) return false;

    $estourou = [];
    if ($faltaA && $prazoVenceu) { $p['escolha_a'] = json_encode(psdIA($e, 'a')); $estourou[] = 'a'; }
    if ($faltaB && $prazoVenceu) { $p['escolha_b'] = json_encode(psdIA($e, 'b')); $estourou[] = 'b'; }

    $ea = json_decode((string)($p['escolha_a'] ?: '{}'), true) ?: ['tipo' => 'nada'];
    $eb = json_decode((string)($p['escolha_b'] ?: '{}'), true) ?: ['tipo' => 'nada'];

    psdResolverTurno($e, $ea, $eb);

    foreach ($estourou as $l) {
        $p['estouros_' . $l] = (int)$p['estouros_' . $l] + 1;
        if ((int)$p['estouros_' . $l] >= PSD_ESTOUROS_MAX && empty($e['fim'])) {
            $e['fim']    = psdOutro($l);
            $e['motivo'] = 'abandono';
            $e['eventos'][] = ['t' => 'texto', 'm' => $e['lados'][$l]['nome'] . ' sumiu da partida.'];
        }
    }

    $p['estado']    = json_encode($e);
    $p['escolha_a'] = null;
    $p['escolha_b'] = null;
    $p['seq']       = (int)$p['seq'] + 1;
    $p['prazo']     = psdPrazo();

    $pdo->prepare("UPDATE psd_partidas
                      SET estado = ?, escolha_a = NULL, escolha_b = NULL,
                          seq = ?, prazo = ?, estouros_a = ?, estouros_b = ?
                    WHERE id = ?")
        ->execute([$p['estado'], $p['seq'], $p['prazo'],
                   (int)$p['estouros_a'], (int)$p['estouros_b'], (int)$p['id']]);

    if (!empty($e['fim'])) psdEncerrar($pdo, $p, $e);
    return true;
}

/**
 * Fecha a partida e paga.
 *
 * O prêmio é a soma das duas entradas e vai inteiro pro vencedor. Empate
 * — que só acontece em nocaute duplo — devolve a entrada pra cada um: não
 * há vencedor pra levar, e ficar com o dinheiro seria a casa ganhando.
 *
 * O TREINO não paga nada, nem conta ranking (mesma regra do Lendas).
 */
function psdEncerrar(PDO $pdo, array &$p, array $e): void
{
    if ((int)$p['pago'] === 1) return;

    $treino = ((int)$p['b_uid']) === 0 || $p['b_uid'] === null;
    $fim    = $e['fim'];

    $vencedorUid = null;
    if ($fim === 'a') $vencedorUid = (int)$p['a_uid'];
    if ($fim === 'b') $vencedorUid = (int)$p['b_uid'];

    $premio = (int)$p['premio'];

    if (!$treino && $premio > 0) {
        if ($fim === 'empate' || !$vencedorUid) {
            psdCreditar($pdo, (int)$p['a_uid'], (int)$p['aposta']);
            psdCreditar($pdo, (int)$p['b_uid'], (int)$p['aposta']);
        } else {
            psdCreditar($pdo, $vencedorUid, $premio);
        }
    }

    if (!$treino) {
        $temporada = date('Y-m');
        $marca = $pdo->prepare("INSERT INTO psd_ranking (id_usuario, temporada, pontos, vitorias, derrotas)
                                VALUES (?,?,?,?,?)
                                ON DUPLICATE KEY UPDATE pontos = GREATEST(0, pontos + VALUES(pontos)),
                                    vitorias = vitorias + VALUES(vitorias), derrotas = derrotas + VALUES(derrotas)");
        foreach (['a', 'b'] as $l) {
            $uid = (int)$p[$l . '_uid'];
            if (!$uid) continue;
            $ganhou = ($fim === $l);
            $marca->execute([$uid, $temporada, $ganhou ? 25 : -15, $ganhou ? 1 : 0, $ganhou ? 0 : 1]);
        }
    }

    $pdo->prepare("UPDATE psd_partidas SET status = 'fim', vencedor_uid = ?, motivo = ?, pago = 1 WHERE id = ?")
        ->execute([$vencedorUid, (string)($e['motivo'] ?? 'nocaute'), (int)$p['id']]);

    $p['status'] = 'fim';
    $p['pago']   = 1;
}

/**
 * O que a tela pergunta a cada 1,5s. Resolve o turno se for a hora, e
 * devolve o estado JÁ FILTRADO pelo lado de quem perguntou.
 */
function psdVer(PDO $pdo, int $uid, ?int $id = null): array
{
    psdTabelas($pdo);

    $pdo->beginTransaction();
    try {
        $p = $id ? psdPorId($pdo, $id) : psdMinha($pdo, $uid, true);
        if (!$p || psdMeuLado($p, $uid) === null) {
            $pdo->commit();
            return ['em' => 'nada', 'saldo' => psdSaldo($pdo, $uid)];
        }

        $st = $pdo->prepare('SELECT * FROM psd_partidas WHERE id = ? FOR UPDATE');
        $st->execute([(int)$p['id']]);
        $p = $st->fetch(PDO::FETCH_ASSOC);

        $lado = psdMeuLado($p, $uid);

        if ($p['status'] === 'aguardando') {
            $pdo->commit();
            return [
                'em'      => 'esperando',
                'id'      => (int)$p['id'],
                'codigo'  => $p['codigo'],
                'modo'    => $p['modo'],
                'aposta'  => (int)$p['aposta'],
                'saldo'   => psdSaldo($pdo, $uid),
            ];
        }

        psdResolverSeDer($pdo, $p);

        $e = json_decode((string)$p['estado'], true) ?: [];
        $vista = psdEstadoPara($e, $lado);

        $vista['em']      = 'jogando';
        $vista['id']      = (int)$p['id'];
        $vista['seq']     = (int)$p['seq'];
        $vista['aposta']  = (int)$p['aposta'];
        $vista['premio']  = (int)$p['premio'];
        $vista['treino']  = ((int)$p['b_uid']) === 0 || $p['b_uid'] === null;
        $vista['saldo']   = psdSaldo($pdo, $uid);
        $vista['esperando_outro'] = !psdFaltaEscolha($e, $p, $lado) && empty($e['fim']);
        $vista['falta_eu']        = psdFaltaEscolha($e, $p, $lado);
        $vista['segundos']        = max(0, strtotime((string)$p['prazo']) - time());
        $vista['acabou']          = $p['status'] === 'fim';

        $pdo->commit();
        return $vista;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['em' => 'erro', 'erro' => 'Não consegui ler a partida.'];
    }
}

/** Desiste no meio: conta como derrota e o outro leva o prêmio. */
function psdDesistir(PDO $pdo, int $uid): array
{
    psdTabelas($pdo);
    $pdo->beginTransaction();
    try {
        $p = psdMinha($pdo, $uid);
        if (!$p) { $pdo->rollBack(); return ['ok' => false, 'erro' => 'Você não está numa partida.']; }

        $st = $pdo->prepare('SELECT * FROM psd_partidas WHERE id = ? FOR UPDATE');
        $st->execute([(int)$p['id']]);
        $p = $st->fetch(PDO::FETCH_ASSOC);

        $lado = psdMeuLado($p, $uid);
        if (!$lado || $p['status'] !== 'jogando') { $pdo->rollBack(); return ['ok' => false, 'erro' => 'A partida não está em andamento.']; }

        $e = json_decode((string)$p['estado'], true);
        $e['fim']    = psdOutro($lado);
        $e['motivo'] = 'desistencia';
        $e['eventos'] = [['t' => 'texto', 'm' => $e['lados'][$lado]['nome'] . ' desistiu.']];

        $p['estado'] = json_encode($e);
        $p['seq']    = (int)$p['seq'] + 1;
        $pdo->prepare('UPDATE psd_partidas SET estado = ?, seq = ? WHERE id = ?')
            ->execute([$p['estado'], $p['seq'], (int)$p['id']]);

        psdEncerrar($pdo, $p, $e);
        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'erro' => 'Não consegui desistir.'];
    }
}

// ─────────────────────────────── ranking ───────────────────────────────

function psdRanking(PDO $pdo, int $quantos = 20): array
{
    psdTabelas($pdo);
    $st = $pdo->prepare("SELECT r.*, u.nome
        FROM psd_ranking r JOIN games_usuarios u ON u.id = r.id_usuario
        WHERE r.temporada = ? ORDER BY r.pontos DESC, r.vitorias DESC LIMIT " . (int)$quantos);
    $st->execute([date('Y-m')]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
