<?php
/**
 * ── O DUELO POR CÓDIGO DO FBA DRAFT ──────────────────────────────────
 *
 * Pedido do Marcos (08/10/2026): "quando é duelo entre players, a ideia é que
 * seja o mesmo sistema do build ou o de stating, de criar a partida com x
 * valor, envia o codigo, entra o adversario".
 *
 * É o padrão que o Dream Team já usa nesta casa (@see games/games/dreamteam.php):
 * código de seis letras, aposta escolhida por quem cria, adversário entra
 * digitando o código. Seguir o mesmo formato não é preguiça — é o que faz um
 * GM que já jogou o outro saber o que esperar.
 *
 * ── CADA UM MONTA NO SEU TEMPO ───────────────────────────────────────
 *
 * Decisão dele: criou, mandou o código e já pode montar o draft sem esperar;
 * o adversário entra quando quiser e monta o dele. Quando os dois terminam, a
 * partida roda sozinha. Isso obriga o draft a ser GUARDADO NO BANCO a cada
 * escolha, e não só na sessão — quem fecha o navegador no meio volta onde
 * parou, e é isso que permite duelar com alguém que está dormindo.
 *
 * ── A APOSTA SAI NA ENTRADA ──────────────────────────────────────────
 *
 * Quem cria paga ao criar; quem entra paga ao entrar. O vencedor leva as
 * duas, o empate devolve a de cada um. Cobrar só no fim deixaria o perdedor
 * sem saldo e a dívida sem cobrança.
 *
 * O ESQUEMA É SORTEADO POR DUELO, não por jogador: os dois recebem a mesma
 * mão de cinco (@see draftFutFormacoesSorteadas) a partir da semente do
 * duelo. Mãos diferentes fariam a reclamação certa de "ele pegou melhores".
 */

require_once __DIR__ . '/draftfut.php';

/** Quanto se pode apostar num duelo. Fora disso, não abre. */
const DFD_APOSTAS = [25, 50, 100, 250, 500];

function dfdTabelas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS draftfut_duelos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(8) NOT NULL,
        id_criador INT NOT NULL,
        id_desafiado INT NULL,
        aposta INT NOT NULL,
        semente INT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'aguardando',
        nome_criador VARCHAR(60) NULL,
        nome_desafiado VARCHAR(60) NULL,
        draft_criador TEXT NULL,
        draft_desafiado TEXT NULL,
        pronto_criador TINYINT(1) NOT NULL DEFAULT 0,
        pronto_desafiado TINYINT(1) NOT NULL DEFAULT 0,
        /* QUEM JÁ VIU O RESULTADO, por lado. A primeira versão guardava isso
           na sessão de quem disparou a partida, e aí o outro GM nunca via o
           placar: pra ele o duelo simplesmente sumia da tela. Marca por lado
           é a única que funciona pros dois. */
        visto_criador TINYINT(1) NOT NULL DEFAULT 0,
        visto_desafiado TINYINT(1) NOT NULL DEFAULT 0,
        forca_criador INT NULL,
        forca_desafiado INT NULL,
        quimica_criador INT NULL,
        quimica_desafiado INT NULL,
        gols_criador INT NULL,
        gols_desafiado INT NULL,
        resultado TEXT NULL,
        id_vencedor INT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        entrou_em DATETIME NULL,
        concluido_em DATETIME NULL,
        UNIQUE KEY uk_dfd_codigo (codigo),
        INDEX idx_dfd_criador (id_criador),
        INDEX idx_dfd_desafiado (id_desafiado),
        INDEX idx_dfd_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    /* Migração aditiva: duelo que já existia ganha as colunas sem perder nada. */
    try {
        if (!$pdo->query("SHOW COLUMNS FROM draftfut_duelos LIKE 'visto_criador'")->fetch()) {
            $pdo->exec("ALTER TABLE draftfut_duelos
                ADD COLUMN visto_criador TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN visto_desafiado TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (PDOException $e) {
        error_log('[draftfut-duelo] migração visto: ' . $e->getMessage());
    }
    $feito = true;
}

/**
 * O duelo TERMINADO que este GM ainda não fechou.
 *
 * É o que faz os dois verem o placar: quem disparou a partida e quem estava
 * fora da tela quando ela rodou. Some quando a pessoa clica em fechar.
 */
function dfdResultadoPendente(PDO $pdo, int $uid): ?array
{
    dfdTabelas($pdo);
    $st = $pdo->prepare("SELECT * FROM draftfut_duelos
                          WHERE status = 'concluido'
                            AND ((id_criador = ? AND visto_criador = 0)
                              OR (id_desafiado = ? AND visto_desafiado = 0))
                       ORDER BY id DESC LIMIT 1");
    $st->execute([$uid, $uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Marca que este GM já viu o resultado. */
function dfdMarcarVisto(PDO $pdo, int $dueloId, string $lado): void
{
    $pdo->prepare("UPDATE draftfut_duelos SET visto_{$lado} = 1 WHERE id = ?")->execute([$dueloId]);
}

/**
 * Um código de seis letras, sem as que se confundem.
 *
 * Sem I, L, O, 0 e 1: o código é ditado no grupo, e "I" contra "1" vira um GM
 * reclamando que o código não funciona.
 */
function dfdGerarCodigo(PDO $pdo): string
{
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    for ($t = 0; $t < 20; $t++) {
        $cod = '';
        for ($i = 0; $i < 6; $i++) $cod .= $chars[random_int(0, strlen($chars) - 1)];
        $st = $pdo->prepare('SELECT 1 FROM draftfut_duelos WHERE codigo = ?');
        $st->execute([$cod]);
        if (!$st->fetchColumn()) return $cod;
    }
    throw new RuntimeException('Não consegui gerar um código livre. Tente de novo.');
}

/** O duelo em que o GM está metido agora, se houver. */
function dfdMeuDuelo(PDO $pdo, int $uid): ?array
{
    dfdTabelas($pdo);
    $st = $pdo->prepare("SELECT * FROM draftfut_duelos
                          WHERE status <> 'concluido' AND (id_criador = ? OR id_desafiado = ?)
                       ORDER BY id DESC LIMIT 1");
    $st->execute([$uid, $uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function dfdPorCodigo(PDO $pdo, string $codigo): ?array
{
    dfdTabelas($pdo);
    $st = $pdo->prepare('SELECT * FROM draftfut_duelos WHERE codigo = ? LIMIT 1');
    $st->execute([strtoupper(trim($codigo))]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Sou o criador deste duelo? (A outra ponta é sempre o desafiado.) */
function dfdSouCriador(array $duelo, int $uid): bool
{
    return (int)$duelo['id_criador'] === $uid;
}

/** O lado do GM num duelo: 'criador' ou 'desafiado'. */
function dfdLado(array $duelo, int $uid): string
{
    return dfdSouCriador($duelo, $uid) ? 'criador' : 'desafiado';
}

/**
 * Guarda o draft em andamento de um lado.
 *
 * Toda escolha passa por aqui. É o que faz "monta no seu tempo" existir: sem
 * isso, fechar o navegador no meio do draft perderia o time e o duelo
 * travaria esperando alguém que não tem mais como terminar.
 */
function dfdSalvarDraft(PDO $pdo, int $dueloId, string $lado, array $draft): void
{
    $col = $lado === 'criador' ? 'draft_criador' : 'draft_desafiado';
    $pdo->prepare("UPDATE draftfut_duelos SET $col = ? WHERE id = ?")
        ->execute([json_encode($draft, JSON_UNESCAPED_UNICODE), $dueloId]);
}

/** O draft guardado de um lado, ou null. */
function dfdLerDraft(array $duelo, string $lado): ?array
{
    $j = (string)($duelo[$lado === 'criador' ? 'draft_criador' : 'draft_desafiado'] ?? '');
    if ($j === '') return null;
    $d = json_decode($j, true);
    return is_array($d) ? $d : null;
}

/**
 * Fecha o lado de um GM: grava força, química e nome, e marca pronto.
 *
 * Não roda a partida — quem roda é dfdRodarSeDerPra(), e só quando os DOIS
 * estão prontos. Separar as duas coisas é o que evita a partida nascer com um
 * time pela metade porque alguém clicou duas vezes.
 */
function dfdFinalizarLado(PDO $pdo, array $duelo, int $uid, string $nome, array $draft): void
{
    $lado  = dfdLado($duelo, $uid);
    $forca = draftFutForcaDoTime($draft['formacao'], $draft['time']);
    $quim  = draftFutQuimica($draft['formacao'], $draft['time'])['total'];
    $pdo->prepare("UPDATE draftfut_duelos
                      SET nome_{$lado} = ?, draft_{$lado} = ?, forca_{$lado} = ?,
                          quimica_{$lado} = ?, pronto_{$lado} = 1
                    WHERE id = ?")
        ->execute([$nome, json_encode($draft, JSON_UNESCAPED_UNICODE), $forca, $quim, (int)$duelo['id']]);
}

/**
 * Roda a partida se os dois lados estiverem prontos. Devolve o duelo atual.
 *
 * A SEMENTE É A DO DUELO, gravada quando ele nasceu: os dois veem a MESMA
 * partida, lance por lance. Sortear na hora faria cada um ver uma narração
 * diferente do mesmo jogo, e aí não há como discutir o resultado.
 *
 * O pagamento acontece aqui, uma vez, dentro de transação — e a transição de
 * status é a tranca: só paga quem conseguiu mudar 'montando' pra 'concluido'.
 */
function dfdRodarSeDerPra(PDO $pdo, array $duelo): array
{
    if ($duelo['status'] === 'concluido') return $duelo;
    if (!(int)$duelo['pronto_criador'] || !(int)$duelo['pronto_desafiado']) return $duelo;

    $dc = dfdLerDraft($duelo, 'criador');
    $dd = dfdLerDraft($duelo, 'desafiado');
    if (!$dc || !$dd) return $duelo;

    $semente = (int)$duelo['semente'];
    $casa = ['nome' => (string)($duelo['nome_criador'] ?: 'Criador'),
             'formacao' => $dc['formacao'], 'time' => $dc['time'],
             'forca' => (int)$duelo['forca_criador']];
    $fora = ['nome' => (string)($duelo['nome_desafiado'] ?: 'Desafiado'),
             'formacao' => $dd['formacao'], 'time' => $dd['time'],
             'forca' => (int)$duelo['forca_desafiado']];
    /* Mando zero: entre dois GMs não há casa. @see draftFutPartida */
    $p = draftFutPartida($casa, $fora, $semente, 0);

    [$gc, $gf] = $p['placar'];
    $venc = $gc > $gf ? (int)$duelo['id_criador'] : ($gf > $gc ? (int)$duelo['id_desafiado'] : null);

    $pdo->beginTransaction();
    try {
        /* A TRANCA: quem muda o status é quem paga. Dois acessos ao mesmo
           tempo entram aqui juntos, e só um sai com linha afetada. */
        $up = $pdo->prepare("UPDATE draftfut_duelos
                                SET status = 'concluido', gols_criador = ?, gols_desafiado = ?,
                                    resultado = ?, id_vencedor = ?, concluido_em = NOW()
                              WHERE id = ? AND status <> 'concluido'");
        $up->execute([$gc, $gf, json_encode($p, JSON_UNESCAPED_UNICODE), $venc, (int)$duelo['id']]);
        if ($up->rowCount() === 1) {
            $aposta = (int)$duelo['aposta'];
            if ($venc !== null) {
                /* O vencedor leva as duas apostas: a dele volta e a do outro
                   entra. Como as duas saíram na entrada, o crédito é 2x. */
                dfMoedasMexer($pdo, $venc, $aposta * 2);
            } else {
                dfMoedasMexer($pdo, (int)$duelo['id_criador'], $aposta);
                dfMoedasMexer($pdo, (int)$duelo['id_desafiado'], $aposta);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[draftfut-duelo] rodar: ' . $e->getMessage());
        return $duelo;
    }

    $st = $pdo->prepare('SELECT * FROM draftfut_duelos WHERE id = ?');
    $st->execute([(int)$duelo['id']]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: $duelo;
}

/**
 * Desiste de um duelo que ainda não começou de verdade.
 *
 * Só o criador, e só enquanto ninguém entrou — depois que o adversário pagou
 * a aposta, sair viraria um jeito de dar calote. A aposta volta.
 */
function dfdCancelar(PDO $pdo, array $duelo, int $uid): bool
{
    if (!dfdSouCriador($duelo, $uid)) return false;
    if ($duelo['status'] !== 'aguardando' || $duelo['id_desafiado'] !== null) return false;
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare("DELETE FROM draftfut_duelos WHERE id = ? AND status = 'aguardando' AND id_desafiado IS NULL");
        $up->execute([(int)$duelo['id']]);
        if ($up->rowCount() === 1) dfMoedasMexer($pdo, $uid, (int)$duelo['aposta']);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[draftfut-duelo] cancelar: ' . $e->getMessage());
        return false;
    }
}

/**
 * DESISTIR DO DUELO: W.O., e o adversário leva as duas apostas.
 *
 * Sem esta regra, desistir no meio seria um jeito de segurar a aposta do
 * outro pra sempre — ele ficaria esperando um time que nunca vem. Por isso o
 * duelo encerra na hora, com placar de W.O. (3x0, como manda o costume) e o
 * pote inteiro pra quem ficou.
 *
 * Quem já fechou o próprio time não desiste: a partida sai sozinha quando o
 * outro terminar, e deixar sair seria desfazer um time já entregue.
 */
function dfdDesistir(PDO $pdo, array $duelo, int $uid): bool
{
    if ($duelo['status'] === 'concluido') return false;
    if ($duelo['id_desafiado'] === null) return false;
    $lado = dfdLado($duelo, $uid);
    if ((int)$duelo['pronto_' . $lado]) return false;

    $outro = $lado === 'criador' ? (int)$duelo['id_desafiado'] : (int)$duelo['id_criador'];
    $gc = $lado === 'criador' ? 0 : 3;   // o criador é sempre a casa no placar
    $gf = $lado === 'criador' ? 3 : 0;

    $pdo->beginTransaction();
    try {
        /* A TRANCA É A MESMA DO PAGAMENTO: só paga quem conseguiu mudar o
           status. Desistir e o adversário fechar o time no mesmo instante
           entram aqui juntos, e um dos dois sai sem efeito. */
        $up = $pdo->prepare("UPDATE draftfut_duelos
                                SET status = 'concluido', gols_criador = ?, gols_desafiado = ?,
                                    id_vencedor = ?, concluido_em = NOW(),
                                    visto_{$lado} = 0
                              WHERE id = ? AND status <> 'concluido'");
        $up->execute([$gc, $gf, $outro, (int)$duelo['id']]);
        if ($up->rowCount() !== 1) { $pdo->rollBack(); return false; }
        dfMoedasMexer($pdo, $outro, (int)$duelo['aposta'] * 2);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[draftfut-duelo] desistir: ' . $e->getMessage());
        return false;
    }
}

/**
 * O ranking de vitórias, somando duelo E partida contra o bot.
 *
 * Duas fontes porque são duas histórias do mesmo GM: `draftfut_times` conta o
 * que cada time salvo fez contra a máquina, e `draftfut_duelos` conta o que
 * ele fez contra gente. Somar é o que responde "quem ganha mais no draft".
 */
function dfdRankingVitorias(PDO $pdo, int $limite = 15): array
{
    dfdTabelas($pdo);
    $linhas = [];

    $q = $pdo->query("SELECT t.id_usuario uid, SUM(t.vitorias) v, SUM(t.empates) e, SUM(t.derrotas) d
                        FROM draftfut_times t GROUP BY t.id_usuario");
    foreach ($q as $r) {
        $linhas[(int)$r['uid']] = ['v' => (int)$r['v'], 'e' => (int)$r['e'], 'd' => (int)$r['d'], 'duelos' => 0];
    }

    $q = $pdo->query("SELECT id_criador, id_desafiado, id_vencedor, gols_criador, gols_desafiado
                        FROM draftfut_duelos WHERE status = 'concluido'");
    foreach ($q as $r) {
        foreach ([(int)$r['id_criador'], (int)$r['id_desafiado']] as $uid) {
            if (!$uid) continue;
            $linhas[$uid] ??= ['v' => 0, 'e' => 0, 'd' => 0, 'duelos' => 0];
            $linhas[$uid]['duelos']++;
            if ($r['id_vencedor'] === null)            $linhas[$uid]['e']++;
            elseif ((int)$r['id_vencedor'] === $uid)   $linhas[$uid]['v']++;
            else                                       $linhas[$uid]['d']++;
        }
    }
    if (!$linhas) return [];

    $ids = implode(',', array_map('intval', array_keys($linhas)));
    $nomes = [];
    foreach ($pdo->query("SELECT u.id, u.name, u.photo_url FROM users u WHERE u.id IN ($ids)") as $u) {
        $nomes[(int)$u['id']] = $u;
    }

    $saida = [];
    foreach ($linhas as $uid => $l) {
        if (!isset($nomes[$uid])) continue;          // GM apagado não entra no ranking
        $jogos = $l['v'] + $l['e'] + $l['d'];
        if (!$jogos) continue;
        $saida[] = $l + [
            'uid'   => $uid,
            'gm'    => (string)$nomes[$uid]['name'],
            'foto'  => (string)($nomes[$uid]['photo_url'] ?? ''),
            'jogos' => $jogos,
        ];
    }
    usort($saida, fn($a, $b) => [$b['v'], $b['e'], -$b['jogos']] <=> [$a['v'], $a['e'], -$a['jogos']]);
    return array_slice($saida, 0, $limite);
}

/**
 * Os maiores confrontos: os duelos de força somada mais alta.
 *
 * "Maior" aqui é o peso dos dois times juntos, não o placar — um 1x0 entre
 * dois times de 90 é um jogão, e um 5x4 entre dois de 76 é só bagunça.
 */
function dfdMaioresConfrontos(PDO $pdo, int $limite = 8): array
{
    dfdTabelas($pdo);
    $st = $pdo->prepare("SELECT d.*, (COALESCE(d.forca_criador,0) + COALESCE(d.forca_desafiado,0)) peso
                           FROM draftfut_duelos d
                          WHERE d.status = 'concluido'
                       ORDER BY peso DESC, d.concluido_em DESC
                          LIMIT ?");
    $st->bindValue(1, $limite, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
