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
        /* Numa revanche, quem é chamado — ele entra sem código, mas a aposta
           dele só sai quando abrir o jogo. */
        convidado INT NULL,
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
    try {
        if (!$pdo->query("SHOW COLUMNS FROM draftfut_duelos LIKE 'convidado'")->fetch()) {
            $pdo->exec('ALTER TABLE draftfut_duelos ADD COLUMN convidado INT NULL');
            $pdo->exec('ALTER TABLE draftfut_duelos ADD INDEX idx_dfd_convidado (convidado)');
        }
    } catch (PDOException $e) {
        error_log('[draftfut-duelo] migração convidado: ' . $e->getMessage());
    }

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
 * REVANCHE: um duelo novo com a MESMA aposta e os MESMOS dois.
 *
 * Pedido do Marcos (08/10/2026): "se for confronto contra um time ao vivo, de
 * a opção de revanche, pra recriar os mesmos valores e tudo".
 *
 * SEM CÓDIGO, SEM LINK: "se os dois que acabaram de se enfrentar clicarem,
 * cria a partida dnv, sem precisar de link" (Marcos, 08/10/2026). Então esta
 * função faz as duas pontas. O primeiro a clicar abre o duelo com o outro já
 * marcado como convidado; o segundo clique cai aqui de novo, encontra esse
 * duelo esperando por ele e simplesmente ENTRA. Dar "não deu pra abrir" pro
 * segundo seria o pior dos mundos: os dois querem jogar e ninguém joga.
 *
 * A aposta de cada um sai no clique de cada um — a de quem pediu na hora, a
 * do outro quando ele entra. Descontar moeda de quem não clicou em nada é
 * cobrar sem pedir, então até o segundo clique o duelo fica 'aguardando' e
 * aparece na tela dele como convite.
 *
 * @return array|null o duelo (novo ou o que ele acabou de entrar), ou null
 *         quando não deu (sem saldo, duelo em andamento, ou o anterior não
 *         era um duelo entre os dois).
 */
function dfdRevanche(PDO $pdo, array $anterior, int $uid): ?array
{
    if ($anterior['status'] !== 'concluido') return null;
    if ($anterior['id_desafiado'] === null) return null;
    if ((int)$anterior['id_criador'] !== $uid && (int)$anterior['id_desafiado'] !== $uid) return null;
    if (dfdMeuDuelo($pdo, $uid)) return null;         // um duelo por vez

    $aposta = (int)$anterior['aposta'];
    if (dfMoedas($pdo, $uid) < $aposta) return null;

    $outro = (int)$anterior['id_criador'] === $uid
        ? (int)$anterior['id_desafiado']
        : (int)$anterior['id_criador'];

    /* ── O OUTRO JÁ PEDIU? ENTÃO É SÓ ENTRAR ─────────────────────────
       Este é o segundo dos dois cliques. O duelo existe, está esperando por
       mim, e o que falta é a minha aposta. */
    $st = $pdo->prepare("SELECT * FROM draftfut_duelos
                          WHERE status = 'aguardando' AND id_desafiado IS NULL
                            AND id_criador = ? AND convidado = ?
                       ORDER BY id DESC LIMIT 1");
    $st->execute([$outro, $uid]);
    if ($pendente = $st->fetch(PDO::FETCH_ASSOC)) {
        return dfdEntrarNoConvite($pdo, $pendente, $uid);
    }

    /* O outro não pode estar metido noutro duelo, senão a revanche nasceria
       presa esperando alguém que já está jogando. */
    if (dfdMeuDuelo($pdo, $outro)) return null;

    $pdo->beginTransaction();
    try {
        $codigo = dfdGerarCodigo($pdo);
        $pdo->prepare('INSERT INTO draftfut_duelos (codigo, id_criador, aposta, semente, convidado)
                       VALUES (?,?,?,?,?)')
            ->execute([$codigo, $uid, $aposta, random_int(1, 2000000000), $outro]);
        dfMoedasMexer($pdo, $uid, -$aposta);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[draftfut-duelo] revanche: ' . $e->getMessage());
        return null;
    }
    return dfdPorCodigo($pdo, $codigo);
}

/**
 * ENTRAR NUM CONVITE DE REVANCHE.
 *
 * A tranca é a de sempre: quem conseguir mudar a linha é quem paga. Dois
 * cliques ao mesmo tempo (o botão e a página recarregando, por exemplo) não
 * podem virar duas cobranças.
 */
function dfdEntrarNoConvite(PDO $pdo, array $convite, int $uid): ?array
{
    $aposta = (int)$convite['aposta'];
    if (dfMoedas($pdo, $uid) < $aposta) return null;
    if (dfdMeuDuelo($pdo, $uid)) return null;

    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare("UPDATE draftfut_duelos
                                SET id_desafiado = ?, status = 'montando', entrou_em = NOW()
                              WHERE id = ? AND id_desafiado IS NULL AND convidado = ?");
        $up->execute([$uid, (int)$convite['id'], $uid]);
        if ($up->rowCount() !== 1) { $pdo->rollBack(); return null; }
        dfMoedasMexer($pdo, $uid, -$aposta);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[draftfut-duelo] entrar no convite: ' . $e->getMessage());
        return null;
    }
    return dfdPorCodigo($pdo, (string)$convite['codigo']);
}

/** O convite de revanche aberto pra este GM, se houver. */
function dfdConvitePendente(PDO $pdo, int $uid): ?array
{
    dfdTabelas($pdo);
    $st = $pdo->prepare("SELECT * FROM draftfut_duelos
                          WHERE status = 'aguardando' AND id_desafiado IS NULL AND convidado = ?
                       ORDER BY id DESC LIMIT 1");
    $st->execute([$uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * O NOME DA FRANQUIA DO GM, pro time do draft nascer com ele.
 *
 * Pedido do Marcos (08/10/2026): "o nome do time é pra pegar o nome do time no
 * jogo mesmo, tipo Las Vegas Coyotes". Antes a pessoa digitava, e digitar o
 * nome do próprio time a cada draft é trabalho sem graça — e abria espaço pra
 * um time chamado "asdf" aparecer no ranking da liga.
 *
 * Cidade mais nome, que é como a liga escreve. Quem não tem franquia — ROOKIE
 * antes do sorteio de marca — usa o próprio nome, porque um time sem nome
 * nenhum no ranking é pior que um time com o nome do dono.
 */
function dfdNomeDoTime(PDO $pdo, int $uid, string $fallback = ''): string
{
    try {
        $st = $pdo->prepare('SELECT city, name FROM teams WHERE user_id = ? LIMIT 1');
        $st->execute([$uid]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if ($t) {
            $nome = trim(trim((string)$t['city']) . ' ' . trim((string)$t['name']));
            if ($nome !== '') return mb_substr($nome, 0, 60);
        }
    } catch (Throwable $e) {
        error_log('[draftfut-duelo] nome do time: ' . $e->getMessage());
    }
    $f = trim($fallback);
    return $f !== '' ? mb_substr('Time de ' . $f, 0, 60) : 'Time sem nome';
}

/**
 * Os times mais FORTES já montados, de qualquer modo.
 *
 * Duas fontes porque um time montado num duelo nunca entra em
 * draftfut_times: ele vive na linha do duelo. Juntar é o que responde "qual
 * foi o melhor draft que alguém fez", que é a pergunta.
 */
function dfdTopForca(PDO $pdo, int $limite = 5): array
{
    dfdTabelas($pdo);
    $linhas = [];

    foreach ($pdo->query("SELECT t.nome, t.forca, t.quimica, t.formacao, t.id_usuario uid, 'bot' modo
                            FROM draftfut_times t ORDER BY t.forca DESC LIMIT 40") as $r) {
        $linhas[] = $r;
    }
    foreach ($pdo->query("SELECT nome_criador nome, forca_criador forca, quimica_criador quimica,
                                 id_criador uid, 'duelo' modo
                            FROM draftfut_duelos
                           WHERE status = 'concluido' AND forca_criador IS NOT NULL
                        ORDER BY forca_criador DESC LIMIT 40") as $r) { $linhas[] = $r + ['formacao' => '']; }
    foreach ($pdo->query("SELECT nome_desafiado nome, forca_desafiado forca, quimica_desafiado quimica,
                                 id_desafiado uid, 'duelo' modo
                            FROM draftfut_duelos
                           WHERE status = 'concluido' AND forca_desafiado IS NOT NULL
                        ORDER BY forca_desafiado DESC LIMIT 40") as $r) { $linhas[] = $r + ['formacao' => '']; }

    usort($linhas, fn($a, $b) => [(int)$b['forca'], (int)$b['quimica']] <=> [(int)$a['forca'], (int)$a['quimica']]);
    return array_slice($linhas, 0, $limite);
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
