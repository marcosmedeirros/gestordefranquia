<?php
/**
 * buildplayer.php — Build-A-Player
 *
 * Dá nome e camisa ao jogador, escolhe Guard ou Big, gira as roletas e leva UM
 * atributo de cada lenda sorteada pro build. Dez giros, dez slots.
 *
 * No fim o build é comparado com as LENDAS da NBA — não com os builds dos
 * outros jogadores. A pergunta é "esse cara seria o quê na história da liga?",
 * e chegar em 1º significa ter montado algo melhor que LeBron: precisa de nota
 * de elite em dez slots seguidos, o que é quase impossível de propósito.
 * Depois disso ele é sorteado pra um time e joga uma temporada de verdade.
 *
 * O sorteio é do SERVIDOR, sempre. Se o giro fosse do cliente dava pra ficar
 * regirando até cair a lenda certa — e o build não valeria nada.
 *
 * Incluído por games/games/index.php — $pdo e $_SESSION já disponíveis.
 */

require_once __DIR__ . '/../core/build_notas.php';
require_once __DIR__ . '/../core/build_liga.php';

$user_id = (int)$_SESSION['user_id'];
$hoje    = date('Y-m-d');

/** Quantas vezes dá pra recusar a lenda sorteada numa mesma partida. */
const BP_MAX_REROLLS = 2;

buildGarantirTabelaNotas($pdo);

function bpGarantirTabelas(PDO $pdo): void
{
    static $pronto = false;
    if ($pronto) return;
    $pronto = true;

    // Cache da régua do top 100 (ver buildCurvaViva). Criada AQUI, e não lá
    // dentro: buildPosicaoHistorica é chamada no meio da transação que fecha o
    // build, e no MySQL um CREATE TABLE dá commit implícito — o commit seguinte
    // então falhava com "no active transaction" e a tela mostrava "Erro interno"
    // mesmo com o build já gravado (aparecia certo ao recarregar).
    $pdo->exec("CREATE TABLE IF NOT EXISTS build_curva_cache (
        grupo VARCHAR(10) NOT NULL PRIMARY KEY,
        digital CHAR(32) NOT NULL,
        curva TEXT NOT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS build_partidas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        data_jogo DATE NOT NULL,
        grupo ENUM('GUARD','BIG') NOT NULL,
        slots TEXT NOT NULL,
        usados TEXT NOT NULL,
        atual_player_id INT NULL,
        ovr INT NULL,
        posicao_rank INT NULL,
        moedas INT NOT NULL DEFAULT 0,
        concluido_em DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_bp_usuario (id_usuario, id),
        INDEX idx_bp_rank (ovr)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Colunas novas. Vão uma a uma porque a tabela já existe em produção —
    // e nunca dentro de transação: DDL no MySQL comita sozinho no meio.
    $cols = $pdo->query("SHOW COLUMNS FROM build_partidas")->fetchAll(PDO::FETCH_COLUMN);
    $add = [
        'nome_jogador' => "ADD COLUMN nome_jogador VARCHAR(40) NULL AFTER grupo",
        'camisa'       => "ADD COLUMN camisa VARCHAR(3) NULL AFTER nome_jogador",
        'rerolls'      => "ADD COLUMN rerolls TINYINT NOT NULL DEFAULT 0 AFTER atual_player_id",
        'temporada'    => "ADD COLUMN temporada TEXT NULL AFTER moedas",
        // Build que nasce dentro de um confronto. NULL = build solo, que é
        // como todo build existente fica — o modo antigo não muda em nada.
        'duelo_id'     => "ADD COLUMN duelo_id INT NULL AFTER id_usuario, ADD INDEX idx_bp_duelo (duelo_id)",
    ];
    foreach ($add as $col => $sql) {
        if (!in_array($col, $cols, true)) $pdo->exec("ALTER TABLE build_partidas {$sql}");
    }

    // O Build deixou de ser jogo diário: dá pra montar quantos builds quiser.
    // A chave única por (usuário, dia) é o que travava isso — a segunda
    // partida do dia batia em "Duplicate entry" e nem começava.
    $temUk = $pdo->query("SHOW INDEX FROM build_partidas WHERE Key_name = 'uk_bp_dia'")->fetch();
    if ($temUk) $pdo->exec("ALTER TABLE build_partidas DROP INDEX uk_bp_dia");

    $temIdx = $pdo->query("SHOW INDEX FROM build_partidas WHERE Key_name = 'idx_bp_usuario'")->fetch();
    if (!$temIdx) $pdo->exec("ALTER TABLE build_partidas ADD INDEX idx_bp_usuario (id_usuario, id)");
    // ── Confronto ──────────────────────────────────────────────────────────
    // Os dois montam ao mesmo tempo, cada um vendo o outro preencher. Quando
    // os dois fecham, cada build sorteia um time e os times se enfrentam.
    $pdo->exec("CREATE TABLE IF NOT EXISTS build_duelos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(8) NOT NULL,
        id_criador INT NOT NULL,
        id_desafiado INT NULL,
        aposta INT NOT NULL,
        modo VARCHAR(10) NOT NULL DEFAULT 'amigo',
        status VARCHAR(20) NOT NULL DEFAULT 'aguardando',
        partida_criador INT NULL,
        partida_desafiado INT NULL,
        resultado TEXT NULL,
        id_vencedor INT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        entrou_em DATETIME NULL,
        concluido_em DATETIME NULL,
        UNIQUE KEY uk_bd_codigo (codigo),
        INDEX idx_bd_criador (id_criador),
        INDEX idx_bd_desafiado (id_desafiado),
        INDEX idx_bd_modo_status (modo, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Revanche: cada lado marca a sua, e revanche_duelo_id aponta pro
    // confronto que nasceu — é o que impede a mesma partida de gerar duas.
    $colsD = $pdo->query("SHOW COLUMNS FROM build_duelos")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('revanche_criador', $colsD, true)) {
        $pdo->exec("ALTER TABLE build_duelos
            ADD COLUMN revanche_criador TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN revanche_desafiado TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN revanche_duelo_id INT NULL");
    }
}
bpGarantirTabelas($pdo);

/** Aposta que o confronto aceita, e a fixa do modo aleatório. */
const BP_APOSTA_MIN = 5;
const BP_APOSTA_MAX = 500;
const BP_APOSTA_ALEATORIA = 25;

/** Código curto e legível pra mandar no grupo. Sem 0/O e 1/I. */
function bpGerarCodigo(PDO $pdo): string
{
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($tentativa = 0; $tentativa < 30; $tentativa++) {
        $c = '';
        for ($i = 0; $i < 5; $i++) $c .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        $st = $pdo->prepare("SELECT 1 FROM build_duelos WHERE codigo = ? LIMIT 1");
        $st->execute([$c]);
        if (!$st->fetchColumn()) return $c;
    }
    throw new RuntimeException('não consegui gerar código de duelo');
}

/** O duelo aberto da pessoa, se houver. Um por vez, dos dois lados. */
function bpDueloAtivo(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare("SELECT * FROM build_duelos
                         WHERE (id_criador = ? OR id_desafiado = ?)
                           AND status IN ('aguardando','montando')
                         ORDER BY id DESC LIMIT 1");
    $st->execute([$userId, $userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** O último duelo concluído, pra tela de resultado não sumir no F5. */
function bpDueloConcluido(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare("SELECT * FROM build_duelos
                         WHERE (id_criador = ? OR id_desafiado = ?) AND status = 'concluido'
                         ORDER BY id DESC LIMIT 1");
    $st->execute([$userId, $userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Devolve o build de um lado do duelo, já decodificado. */
function bpPartidaDoDuelo(PDO $pdo, ?int $partidaId): ?array
{
    if (!$partidaId) return null;
    $st = $pdo->prepare("SELECT * FROM build_partidas WHERE id = ?");
    $st->execute([$partidaId]);
    $p = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($p) {
        $p['slots']  = json_decode((string)$p['slots'], true) ?: [];
        $p['usados'] = json_decode((string)$p['usados'], true) ?: [];
    }
    return $p;
}

/**
 * Fecha o duelo: cada lado sorteia um time e os dois se enfrentam.
 *
 * Roda uma vez só — o WHERE status='montando' é o que garante isso. Dois
 * cliques simultâneos no último slot faziam os dois lados tentar fechar, e
 * sem essa trava o prêmio saía duas vezes.
 *
 * Precisa ser chamada DENTRO da transação de quem fechou o último slot.
 */
function bpFecharDuelo(PDO $pdo, array $duelo): ?array
{
    $pc = bpPartidaDoDuelo($pdo, (int)$duelo['partida_criador']);
    $pd = bpPartidaDoDuelo($pdo, (int)$duelo['partida_desafiado']);
    if (!$pc || !$pd || !$pc['concluido_em'] || !$pd['concluido_em']) return null;

    $lado = function (array $p): array {
        return [
            'partida_id' => (int)$p['id'],
            'usuario_id' => (int)$p['id_usuario'],
            'nome'       => (string)($p['nome_jogador'] ?: 'Jogador'),
            'camisa'     => (string)($p['camisa'] ?? ''),
            'grupo'      => (string)$p['grupo'],
            'ovr'        => (int)$p['ovr'],
            'slots'      => $p['slots'],
            'time'       => buildSortearTime(),
        ];
    };
    $a = $lado($pc);
    $b = $lado($pd);

    $jogo = buildSimularConfronto($a, $b);
    $a['linha'] = buildLinhaDoJogo($a['slots'], $a['ovr'], $a['grupo'], $jogo['pontos_a']);
    $b['linha'] = buildLinhaDoJogo($b['slots'], $b['ovr'], $b['grupo'], $jogo['pontos_b']);

    $vencedorId = $jogo['vencedor'] === 'a' ? $a['usuario_id'] : $b['usuario_id'];
    $premio = (int)$duelo['aposta'] * 2;

    $resultado = ['jogo' => $jogo, 'a' => $a, 'b' => $b, 'premio' => $premio];

    $st = $pdo->prepare("UPDATE build_duelos
                         SET status='concluido', resultado=?, id_vencedor=?, concluido_em=NOW()
                         WHERE id = ? AND status = 'montando'");
    $st->execute([json_encode($resultado, JSON_UNESCAPED_UNICODE), $vencedorId, (int)$duelo['id']]);
    if ($st->rowCount() === 0) return null;   // outro request já fechou

    $pdo->prepare("UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?")
        ->execute([$premio, $vencedorId]);

    return $resultado;
}

/** Cor da letra pelo nível: vermelho no fundo da escala, roxo no S. */
function bpCor(int $nivel): string
{
    static $cores = ['#ef4444','#ef4444','#f97316','#f59e0b','#f59e0b','#eab308',
                     '#84cc16','#22c55e','#22c55e','#06b6d4','#3b82f6','#a855f7'];
    return $cores[$nivel] ?? '#ef4444';
}

/**
 * A partida que está na tela: a em andamento, se houver, senão a última que
 * o jogador fechou (é ela que a tela final mostra até ele começar outra).
 */
function bpPartida(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare("SELECT * FROM build_partidas WHERE id_usuario=?
                         ORDER BY (concluido_em IS NULL) DESC, id DESC LIMIT 1");
    $st->execute([$userId]);
    $p = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($p) {
        $p['slots']     = json_decode((string)$p['slots'], true) ?: [];
        $p['usados']    = json_decode((string)$p['usados'], true) ?: [];
        $p['temporada'] = json_decode((string)($p['temporada'] ?? ''), true) ?: null;
    }
    return $p;
}

/**
 * Lenda com nota, do grupo pedido, que ainda não saiu nesta partida.
 * $extraFora tira também a lenda recusada num reroll, pra não sortear a mesma.
 */
function bpSortearLenda(PDO $pdo, string $grupo, array $usados, ?int $extraFora = null): ?array
{
    $bloqueados = array_map('intval', $usados);
    if ($extraFora) $bloqueados[] = $extraFora;
    $bloqueados = array_values(array_unique($bloqueados));

    $fora = '';
    $params = [$grupo];
    if ($bloqueados) {
        $fora = ' AND n.player_id NOT IN (' . implode(',', array_fill(0, count($bloqueados), '?')) . ')';
        $params = array_merge($params, $bloqueados);
    }

    $st = $pdo->prepare("SELECT n.*, p.nome, p.time_atual, p.times, p.altura, p.peso
                         FROM build_notas n
                         INNER JOIN hoopgrid_players p ON p.id = n.player_id
                         WHERE n.posicao_grupo = ?{$fora}
                         ORDER BY RAND() LIMIT 1");
    $st->execute($params);
    $l = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    // Pool menor que os 10 slots: repete lenda em vez de deixar a partida
    // sem saída. Travar aqui deixaria o build pela metade pra sempre — sem
    // décimo slot não tem OVR, e sem OVR a partida nunca fecha.
    if (!$l && $bloqueados) {
        $st2 = $pdo->prepare("SELECT n.*, p.nome, p.time_atual, p.times, p.altura, p.peso
                              FROM build_notas n
                              INNER JOIN hoopgrid_players p ON p.id = n.player_id
                              WHERE n.posicao_grupo = ? ORDER BY RAND() LIMIT 1");
        $st2->execute([$grupo]);
        $l = $st2->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$l) return null;

    // Time exibido na roleta: o atual, ou o primeiro da carreira.
    $time = $l['time_atual'] ?: '';
    if ($time === '' && !empty($l['times'])) {
        $lista = json_decode((string)$l['times'], true);
        if (is_array($lista) && $lista) $time = (string)$lista[0];
    }
    $l['time_exibido'] = $time ?: 'NBA';
    $l['time_logo']    = bpLogoDoTime($l['time_exibido']);
    return $l;
}

/**
 * Logo do time pela sigla, direto do CDN da NBA — mesmo padrão já usado no
 * Box NBA e no cadastro da ROOKIE. Devolve null quando a sigla não é de um
 * time atual (franquia extinta, ou lenda sem time definido), e aí a roleta
 * cai de volta na sigla em texto.
 */
function bpLogoDoTime(string $sigla): ?string
{
    static $porSigla = null;
    if ($porSigla === null) {
        $porSigla = [];
        foreach (nbaTeams() as $t) $porSigla[$t['abbr']] = (int)$t['id'];
    }
    $sigla = strtoupper(trim($sigla));
    return isset($porSigla[$sigla]) ? nbaTeamLogoUrl($porSigla[$sigla]) : null;
}

/**
 * OVR do build: média dos valores das dez letras escolhidas.
 * A versão exata (sem arredondar) alimenta a curva histórica — é o que faz
 * dois builds parecidos não caírem na mesma posição do top 100.
 */
/**
 * OVR ponderado pela posição: um pivô não é avaliado pelo drible nem um armador
 * pelo tamanho (ver buildPesosDoGrupo). A soma dos pesos é normalizada aqui, então
 * mexer num peso não obriga a rebalancear os outros.
 */
function bpCalcularOvrExato(array $slots, string $grupo = 'GUARD'): float
{
    $pesos = buildPesosDoGrupo($grupo);
    $num = 0.0;
    $den = 0.0;
    foreach ($pesos as $a => $peso) {
        $num += buildValorDaLetra((int)($slots[$a]['nivel'] ?? 0)) * $peso;
        $den += $peso;
    }
    return $den > 0 ? $num / $den : 0.0;
}

function bpCalcularOvr(array $slots, string $grupo = 'GUARD'): int
{
    return (int)round(bpCalcularOvrExato($slots, $grupo));
}

/** Nome e camisa como o jogador digitou — limpos, com limite e sem vazio. */
function bpLimparIdentidade(string $nome, string $camisa): array
{
    $nome = trim(preg_replace('/\s+/u', ' ', strip_tags($nome)));
    if (function_exists('mb_substr')) $nome = mb_substr($nome, 0, 24);
    if ($nome === '') $nome = 'Sem Nome';

    $camisa = preg_replace('/\D/', '', $camisa);
    $camisa = $camisa === '' ? (string)random_int(0, 99) : (string)min(99, (int)$camisa);

    return [$nome, $camisa];
}

// ── AÇÕES (AJAX) ────────────────────────────────────────────────────────────
if (($_POST['acao'] ?? '') !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $acao = $_POST['acao'];
    $attrs = array_keys(buildAtributos());

    try {
        // ── CONFRONTO ──────────────────────────────────────────────────────
        // Ficam antes das ações de build porque nenhuma delas depende de haver
        // partida aberta: criar sala, entrar e espiar o outro acontecem fora
        // do build.

        /** Debita a aposta com trava. Devolve mensagem de erro ou null. */
        $cobrarAposta = function (int $valor) use ($pdo, $user_id): ?string {
            $st = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ? FOR UPDATE');
            $st->execute([$user_id]);
            if ((int)$st->fetchColumn() < $valor) return 'Saldo insuficiente.';
            $pdo->prepare('UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ?')
                ->execute([$valor, $user_id]);
            return null;
        };

        if ($acao === 'duelo_criar' || $acao === 'duelo_aleatorio') {
            if (bpDueloAtivo($pdo, $user_id)) {
                echo json_encode(['ok' => false, 'msg' => 'Você já está num confronto.']); exit;
            }
            $aleatorio = $acao === 'duelo_aleatorio';
            $aposta = $aleatorio ? BP_APOSTA_ALEATORIA : (int)($_POST['aposta'] ?? 0);
            if ($aposta < BP_APOSTA_MIN || $aposta > BP_APOSTA_MAX) {
                echo json_encode(['ok' => false, 'msg' => 'A aposta vai de ' . BP_APOSTA_MIN
                    . ' a ' . BP_APOSTA_MAX . ' moedas.']); exit;
            }

            $pdo->beginTransaction();
            try {
                // No aleatório, procura alguém já esperando ANTES de cobrar: o
                // FOR UPDATE é o que impede uma terceira pessoa de entrar na
                // mesma sala enquanto esta transação decide.
                $sala = null;
                if ($aleatorio) {
                    $st = $pdo->prepare("SELECT * FROM build_duelos
                                         WHERE status='aguardando' AND modo='aleatorio' AND id_criador <> ?
                                         ORDER BY criado_em ASC LIMIT 1 FOR UPDATE");
                    $st->execute([$user_id]);
                    $sala = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                    if ($sala) $aposta = (int)$sala['aposta'];
                }

                if ($erro = $cobrarAposta($aposta)) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => $erro]); exit;
                }

                if ($sala) {
                    $pdo->prepare("UPDATE build_duelos SET id_desafiado=?, status='montando', entrou_em=NOW()
                                   WHERE id=? AND status='aguardando'")
                        ->execute([$user_id, (int)$sala['id']]);
                    $codigo = $sala['codigo'];
                } else {
                    $codigo = bpGerarCodigo($pdo);
                    $pdo->prepare("INSERT INTO build_duelos (codigo, id_criador, aposta, modo, status)
                                   VALUES (?,?,?,?, 'aguardando')")
                        ->execute([$codigo, $user_id, $aposta, $aleatorio ? 'aleatorio' : 'amigo']);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true, 'codigo' => $codigo]);
            exit;
        }

        if ($acao === 'duelo_entrar') {
            if (bpDueloAtivo($pdo, $user_id)) {
                echo json_encode(['ok' => false, 'msg' => 'Você já está num confronto.']); exit;
            }
            $codigo = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)($_POST['codigo'] ?? '')));
            if ($codigo === '') { echo json_encode(['ok' => false, 'msg' => 'Informe o código.']); exit; }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare("SELECT * FROM build_duelos WHERE codigo=? AND status='aguardando' FOR UPDATE");
                $st->execute([$codigo]);
                $d = $st->fetch(PDO::FETCH_ASSOC);
                if (!$d) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Código não encontrado ou o confronto já começou.']); exit;
                }
                if ((int)$d['id_criador'] === $user_id) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Esse confronto é seu.']); exit;
                }
                if ($erro = $cobrarAposta((int)$d['aposta'])) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => $erro]); exit;
                }
                $pdo->prepare("UPDATE build_duelos SET id_desafiado=?, status='montando', entrou_em=NOW() WHERE id=?")
                    ->execute([$user_id, (int)$d['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($acao === 'duelo_cancelar') {
            $d = bpDueloAtivo($pdo, $user_id);
            if (!$d) { echo json_encode(['ok' => false, 'msg' => 'Nenhum confronto aberto.']); exit; }
            if ($d['status'] !== 'aguardando' || (int)$d['id_criador'] !== $user_id) {
                echo json_encode(['ok' => false, 'msg' => 'Só dá pra cancelar enquanto ninguém entrou.']); exit;
            }
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare("UPDATE build_duelos SET status='cancelado' WHERE id=? AND status='aguardando'");
                $st->execute([(int)$d['id']]);
                // Devolve a aposta só se a linha realmente mudou: sem isso, um
                // duplo clique devolveria duas vezes.
                if ($st->rowCount() > 0) {
                    $pdo->prepare('UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?')
                        ->execute([(int)$d['aposta'], $user_id]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        // O que o outro lado está fazendo. É daqui que sai o "o outro vai
        // vendo": devolve os slots já preenchidos do adversário.
        if ($acao === 'duelo_estado') {
            $d = bpDueloAtivo($pdo, $user_id) ?: bpDueloConcluido($pdo, $user_id);
            if (!$d) { echo json_encode(['ok' => true, 'duelo' => null]); exit; }

            $souCriador = (int)$d['id_criador'] === $user_id;
            $meuP   = bpPartidaDoDuelo($pdo, (int)($souCriador ? $d['partida_criador'] : $d['partida_desafiado']));
            $delaP  = bpPartidaDoDuelo($pdo, (int)($souCriador ? $d['partida_desafiado'] : $d['partida_criador']));

            $resumo = function (?array $p): array {
                if (!$p) return ['comecou' => false, 'preenchidos' => 0, 'slots' => [], 'fechou' => false];
                $slots = array_filter($p['slots'], fn($x) => $x !== null);
                return [
                    'comecou'     => true,
                    'nome'        => (string)($p['nome_jogador'] ?: 'Jogador'),
                    'grupo'       => (string)$p['grupo'],
                    'preenchidos' => count($slots),
                    'slots'       => $slots,
                    'fechou'      => $p['concluido_em'] !== null,
                ];
            };

            $outroId = $souCriador ? $d['id_desafiado'] : $d['id_criador'];
            $nomeOutro = null;
            if ($outroId) {
                $st = $pdo->prepare("SELECT nome FROM games_usuarios WHERE id = ?");
                $st->execute([(int)$outroId]);
                $nomeOutro = $st->fetchColumn() ?: null;
            }

            echo json_encode(['ok' => true, 'duelo' => [
                'codigo'    => $d['codigo'],
                'status'    => $d['status'],
                'aposta'    => (int)$d['aposta'],
                'modo'      => $d['modo'],
                'sou_criador' => $souCriador,
                'adversario'  => $nomeOutro,
                'eu'          => $resumo($meuP),
                'ele'         => $resumo($delaP),
                'resultado'   => $d['resultado'] ? json_decode((string)$d['resultado'], true) : null,
                'venci'       => $d['id_vencedor'] ? ((int)$d['id_vencedor'] === $user_id) : null,
                'pedi_revanche'  => (int)($souCriador ? ($d['revanche_criador'] ?? 0) : ($d['revanche_desafiado'] ?? 0)) === 1,
                'ele_quer_revanche' => (int)($souCriador ? ($d['revanche_desafiado'] ?? 0) : ($d['revanche_criador'] ?? 0)) === 1,
            ]]);
            exit;
        }

        if ($acao === 'duelo_revanche') {
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare("SELECT * FROM build_duelos
                                     WHERE status='concluido' AND (id_criador=? OR id_desafiado=?)
                                     ORDER BY id DESC LIMIT 1 FOR UPDATE");
                $st->execute([$user_id, $user_id]);
                $d = $st->fetch(PDO::FETCH_ASSOC);
                if (!$d) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'Nenhum confronto pra revanche.']); exit;
                }
                if (!empty($d['revanche_duelo_id'])) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => true, 'ja_nasceu' => true]); exit;
                }

                $souCriador = (int)$d['id_criador'] === $user_id;
                $col = $souCriador ? 'revanche_criador' : 'revanche_desafiado';
                $pdo->prepare("UPDATE build_duelos SET {$col} = 1 WHERE id = ?")->execute([(int)$d['id']]);

                $outroQuer = (int)($souCriador ? $d['revanche_desafiado'] : $d['revanche_criador']) === 1;
                if (!$outroQuer) {
                    $pdo->commit();
                    echo json_encode(['ok' => true, 'esperando' => true]);
                    exit;
                }

                // Os dois querem. Cobra os dois AGORA — marcar intenção é de
                // graça justamente pra ninguém ficar com moeda presa esperando
                // uma resposta que talvez não venha.
                $aposta = (int)$d['aposta'];
                foreach ([(int)$d['id_criador'], (int)$d['id_desafiado']] as $uid) {
                    $stS = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ? FOR UPDATE');
                    $stS->execute([$uid]);
                    if ((int)$stS->fetchColumn() < $aposta) {
                        $pdo->rollBack();
                        echo json_encode(['ok' => false,
                            'msg' => 'Um dos dois não tem saldo pra repetir a aposta.']); exit;
                    }
                }
                $pdo->prepare('UPDATE games_usuarios SET pontos = pontos - ? WHERE id IN (?, ?)')
                    ->execute([$aposta, (int)$d['id_criador'], (int)$d['id_desafiado']]);

                // Nasce já em 'montando': os dois lados são conhecidos, não há
                // sala de espera nenhuma pra atravessar.
                $codigo = bpGerarCodigo($pdo);
                $pdo->prepare("INSERT INTO build_duelos (codigo, id_criador, id_desafiado, aposta, modo, status, entrou_em)
                               VALUES (?,?,?,?,?, 'montando', NOW())")
                    ->execute([$codigo, (int)$d['id_criador'], (int)$d['id_desafiado'], $aposta, $d['modo']]);
                $novoId = (int)$pdo->lastInsertId();

                // O antigo sai da tela de resultado e aponta pro novo.
                $pdo->prepare("UPDATE build_duelos SET status='arquivado', revanche_duelo_id=? WHERE id=?")
                    ->execute([$novoId, (int)$d['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true, 'comecou' => true]);
            exit;
        }

        if ($acao === 'duelo_sair_resultado') {
            // Some da tela, mas o duelo continua no histórico.
            $pdo->prepare("UPDATE build_duelos SET status='arquivado'
                           WHERE status='concluido' AND (id_criador=? OR id_desafiado=?)")
                ->execute([$user_id, $user_id]);
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($acao === 'comecar') {
            $grupo = ($_POST['grupo'] ?? '') === 'BIG' ? 'BIG' : 'GUARD';
            // Só barra se já existe uma partida ABERTA — dá pra montar quantos
            // builds quiser, mas um de cada vez.
            $st = $pdo->prepare("SELECT 1 FROM build_partidas WHERE id_usuario=? AND concluido_em IS NULL LIMIT 1");
            $st->execute([$user_id]);
            if ($st->fetchColumn()) {
                echo json_encode(['ok' => false, 'msg' => 'Você já tem um build em andamento — termine ou recomece ele.']);
                exit;
            }
            // Sem lenda no grupo não dá pra montar nada — melhor avisar antes
            // do que abrir uma partida que não fecha.
            $st = $pdo->prepare("SELECT COUNT(*) FROM build_notas WHERE posicao_grupo = ?");
            $st->execute([$grupo]);
            if ((int)$st->fetchColumn() === 0) {
                echo json_encode(['ok' => false, 'msg' => 'Ainda não há lendas cadastradas nesse tipo de build.']);
                exit;
            }
            [$nome, $camisa] = bpLimparIdentidade((string)($_POST['nome'] ?? ''), (string)($_POST['camisa'] ?? ''));

            $vazios = [];
            foreach ($attrs as $a) $vazios[$a] = null;

            // Se a pessoa está num confronto em montagem, este build é o dela
            // no duelo. O motor de girar/escolher é o mesmo — muda só quem
            // paga no fim.
            $duelo = bpDueloAtivo($pdo, $user_id);
            $emDuelo = $duelo && $duelo['status'] === 'montando';
            $souCriador = $emDuelo && (int)$duelo['id_criador'] === $user_id;
            if ($emDuelo) {
                $jaTem = $souCriador ? $duelo['partida_criador'] : $duelo['partida_desafiado'];
                if ($jaTem) {
                    echo json_encode(['ok' => false, 'msg' => 'Você já montou seu build neste confronto.']);
                    exit;
                }
            }

            $pdo->prepare("INSERT INTO build_partidas
                           (id_usuario, duelo_id, data_jogo, grupo, nome_jogador, camisa, slots, usados)
                           VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$user_id, $emDuelo ? (int)$duelo['id'] : null, $hoje, $grupo,
                           $nome, $camisa, json_encode($vazios), json_encode([])]);

            if ($emDuelo) {
                $col = $souCriador ? 'partida_criador' : 'partida_desafiado';
                $pdo->prepare("UPDATE build_duelos SET {$col} = ? WHERE id = ?")
                    ->execute([(int)$pdo->lastInsertId(), (int)$duelo['id']]);
            }

            echo json_encode(['ok' => true]);
            exit;
        }

        $partida = bpPartida($pdo, $user_id);
        if (!$partida) { echo json_encode(['ok' => false, 'msg' => 'Comece uma partida primeiro.']); exit; }

        // Recomeçar do zero: joga fora o build em andamento e volta pra escolha
        // de tipo. Uma partida FECHADA nunca é apagada — ela já entrou no
        // ranking e pode ter pago moedas; pra fazer outro build é só começar
        // um novo, que agora não tem limite.
        if ($acao === 'recomecar') {
            if ($partida['concluido_em']) {
                echo json_encode(['ok' => false, 'msg' => 'Esse build já está fechado. Comece um novo.']);
                exit;
            }
            $pdo->prepare("DELETE FROM build_partidas WHERE id = ? AND id_usuario = ?")
                ->execute([(int)$partida['id'], $user_id]);
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($partida['concluido_em']) { echo json_encode(['ok' => false, 'msg' => 'Esta partida já terminou.']); exit; }

        // Girar e regirar caem no mesmo lugar: a diferença é que o regirar
        // exige uma lenda na mesa (a que está sendo recusada) e gasta uma das
        // duas fichas da partida. Duas é o limite justamente pra não virar
        // "gira até vir o S" — aí o build não teria mérito nenhum.
        if ($acao === 'girar' || $acao === 'regirar') {
            $ehReroll = $acao === 'regirar';
            $atual = (int)($partida['atual_player_id'] ?? 0);
            $usados = (int)$partida['rerolls'];

            if ($ehReroll) {
                if (!$atual) { echo json_encode(['ok' => false, 'msg' => 'Não há lenda na mesa pra trocar.']); exit; }
                if ($usados >= BP_MAX_REROLLS) {
                    echo json_encode(['ok' => false, 'msg' => 'Suas ' . BP_MAX_REROLLS . ' trocas já acabaram.']);
                    exit;
                }
            } elseif ($atual) {
                echo json_encode(['ok' => false, 'msg' => 'Escolha um atributo da lenda atual antes de girar.']);
                exit;
            }

            $lenda = bpSortearLenda($pdo, $partida['grupo'], $partida['usados'], $ehReroll ? $atual : null);
            if (!$lenda) { echo json_encode(['ok' => false, 'msg' => 'Acabaram as lendas disponíveis.']); exit; }

            $rerolls = $ehReroll ? $usados + 1 : $usados;
            $pdo->prepare("UPDATE build_partidas SET atual_player_id=?, rerolls=? WHERE id=?")
                ->execute([(int)$lenda['player_id'], $rerolls, (int)$partida['id']]);

            $notas = [];
            foreach ($attrs as $a) {
                $notas[$a] = ['nivel' => (int)$lenda[$a], 'letra' => BUILD_LETRAS[(int)$lenda[$a]]];
            }
            echo json_encode([
                'ok' => true,
                'trocas_restantes' => BP_MAX_REROLLS - $rerolls,
                'lenda' => [
                    'id'     => (int)$lenda['player_id'],
                    'nome'   => $lenda['nome'],
                    'time'   => $lenda['time_exibido'],
                    'logo'   => $lenda['time_logo'],
                    'altura' => $lenda['altura'],
                    'peso'   => $lenda['peso'],
                    'notas'  => $notas,
                ],
            ]);
            exit;
        }

        if ($acao === 'escolher') {
            $attr = (string)($_POST['atributo'] ?? '');
            if (!in_array($attr, $attrs, true)) { echo json_encode(['ok' => false, 'msg' => 'Atributo inválido.']); exit; }

            // Trava a partida e RELÊ o estado: sem isso, dois cliques simultâneos no último slot
            // liam os dois "slot vazio", ambos fechavam o build e o prêmio era pago em dobro.
            $pdo->beginTransaction();
            $stLock = $pdo->prepare("SELECT * FROM build_partidas WHERE id = ? AND id_usuario = ? FOR UPDATE");
            $stLock->execute([(int)$partida['id'], $user_id]);
            $fresca = $stLock->fetch(PDO::FETCH_ASSOC);
            if (!$fresca || $fresca['concluido_em'] !== null) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'msg' => 'Esse build já foi finalizado.']);
                exit;
            }
            $partida['slots']           = json_decode((string)$fresca['slots'], true) ?: $partida['slots'];
            $partida['usados']          = json_decode((string)$fresca['usados'], true) ?: [];
            $partida['atual_player_id'] = $fresca['atual_player_id'];
            $partida['grupo']           = $fresca['grupo'];

            if (empty($partida['atual_player_id'])) { $pdo->rollBack(); echo json_encode(['ok' => false, 'msg' => 'Gire primeiro.']); exit; }
            if ($partida['slots'][$attr] !== null) { $pdo->rollBack(); echo json_encode(['ok' => false, 'msg' => 'Esse slot já está preenchido.']); exit; }

            $st = $pdo->prepare("SELECT n.*, p.nome FROM build_notas n
                                 INNER JOIN hoopgrid_players p ON p.id = n.player_id
                                 WHERE n.player_id = ? LIMIT 1");
            $st->execute([(int)$partida['atual_player_id']]);
            $lenda = $st->fetch(PDO::FETCH_ASSOC);
            if (!$lenda) { $pdo->rollBack(); echo json_encode(['ok' => false, 'msg' => 'Lenda não encontrada.']); exit; }

            $slots = $partida['slots'];
            $slots[$attr] = [
                'nivel' => (int)$lenda[$attr],
                'letra' => BUILD_LETRAS[(int)$lenda[$attr]],
                'de'    => $lenda['nome'],
            ];
            $usados = $partida['usados'];
            $usados[] = (int)$partida['atual_player_id'];

            $faltam = count(array_filter($slots, fn($s) => $s === null));
            $terminou = $faltam === 0;

            $grupoBuild = (string)$partida['grupo'];
            $ovr = $terminou ? bpCalcularOvr($slots, $grupoBuild) : null;
            $pdo->prepare("UPDATE build_partidas SET slots=?, usados=?, atual_player_id=NULL, ovr=?, concluido_em=? WHERE id=?")
                ->execute([
                    json_encode($slots), json_encode($usados), $ovr,
                    $terminou ? date('Y-m-d H:i:s') : null, (int)$partida['id'],
                ]);

            $resposta = ['ok' => true, 'slots' => $slots, 'faltam' => $faltam, 'terminou' => $terminou];

            // Build de confronto tem outro desfecho: não simula temporada nem
            // paga pelo ranking histórico. Quem paga é o pote, e só quando os
            // DOIS fecharem — senão o primeiro a terminar levava sozinho.
            if ($terminou && !empty($fresca['duelo_id'])) {
                $stD = $pdo->prepare("SELECT * FROM build_duelos WHERE id = ? FOR UPDATE");
                $stD->execute([(int)$fresca['duelo_id']]);
                $duelo = $stD->fetch(PDO::FETCH_ASSOC);

                $resultado = $duelo ? bpFecharDuelo($pdo, $duelo) : null;
                $pdo->commit();
                echo json_encode($resposta + [
                    'ovr' => $ovr,
                    'duelo' => true,
                    'esperando' => $resultado === null,
                ]);
                exit;
            }

            if ($terminou) {
                // Fecha a temporada aqui, no servidor, e grava. Se o sorteio do
                // time e a simulação ficassem pro carregamento da tela final,
                // bastava dar F5 pra jogar de novo até sair campeão.
                $grupo  = $grupoBuild;
                $hist   = buildPosicaoHistorica(bpCalcularOvrExato($slots, $grupo), $grupo, $pdo);
                $time   = buildSortearTime();
                $season = buildSimularTemporada($slots, (int)$ovr, $time, $grupo);
                $season['time'] = $time;
                $season['historico'] = $hist;
                $season['liga'] = buildPremiacaoDaLiga(
                    $pdo, $time, $season['premios'], $season['playoff'],
                    (string)($partida['nome_jogador'] ?: 'Seu jogador')
                );

                // Jogadas são ilimitadas e todo build fechado paga pelo ranking
                // histórico — não tem mais trava de "só o primeiro do dia".
                $moedas = buildMoedasDaPosicaoHistorica($hist);

                $pdo->prepare("UPDATE build_partidas SET posicao_rank=?, moedas=?, temporada=? WHERE id=?")
                    ->execute([$hist['no_top'] ? $hist['posicao'] : 0, $moedas, json_encode($season), (int)$partida['id']]);
                if ($moedas > 0) {
                    $pdo->prepare("UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?")
                        ->execute([$moedas, $user_id]);
                }
                $resposta += [
                    'ovr'     => $ovr,
                    'posicao' => $hist['no_top'] ? $hist['posicao'] : null,
                    'moedas'  => $moedas,
                ];
            }

            $pdo->commit();
            echo json_encode($resposta);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'Ação desconhecida.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[buildplayer] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erro interno. Tente de novo.']);
    }
    exit;
}

// ── TELA ────────────────────────────────────────────────────────────────────
$partida   = bpPartida($pdo, $user_id);
$ATRIBUTOS = buildAtributos();
$temNotas  = (int)$pdo->query("SELECT COUNT(*) FROM build_notas")->fetchColumn();
$stPontos  = $pdo->prepare("SELECT pontos FROM games_usuarios WHERE id = ?");
$stPontos->execute([$user_id]);
$pontosUsuario = (int)($stPontos->fetchColumn() ?: 0);

// "Montar outro build": o último build está fechado e o jogador pediu a tela
// inicial de volta. Nada é apagado — aquele build continua no ranking, só sai
// da tela. Um build EM ANDAMENTO ignora o pedido: pra largar ele existe o
// "Recomeçar do zero", que avisa o que vai perder.
if (isset($_GET['novo']) && $partida && $partida['concluido_em']) {
    $partida = null;
}
$temporada = $partida['temporada'] ?? null;

// Veio direto do décimo slot: revela o time e a campanha em etapas. Um F5
// depois disso mostra tudo de uma vez — o resultado já está gravado, então
// repetir a animação só atrasaria quem só quer reler.
$revelar = isset($_GET['revelar']) && $partida && $partida['concluido_em'] && $temporada;

// Nome e camisa da última vez, pra não ter que digitar de novo a cada build.
// Vem do banco (e não do navegador) pra seguir o jogador entre celular e PC.
$ultimaIdentidade = ['nome' => '', 'camisa' => ''];
$stId = $pdo->prepare("SELECT nome_jogador, camisa FROM build_partidas
                       WHERE id_usuario = ? AND nome_jogador IS NOT NULL
                       ORDER BY id DESC LIMIT 1");
$stId->execute([$user_id]);
if ($linhaId = $stId->fetch(PDO::FETCH_ASSOC)) {
    $ultimaIdentidade = ['nome' => (string)$linhaId['nome_jogador'], 'camisa' => (string)$linhaId['camisa']];
}

$preenchidos = 0;
if ($partida) {
    foreach ($ATRIBUTOS as $c => $_) if (!empty($partida['slots'][$c])) $preenchidos++;
}
$trocasRestantes = $partida ? max(0, BP_MAX_REROLLS - (int)$partida['rerolls']) : BP_MAX_REROLLS;

// Confronto: o aberto manda na tela; sem ele, um concluído ainda não visto
// vira a tela de resultado. Os dois são NULL na esmagadora maioria das
// visitas — o modo solo nem sabe que isto existe.
$duelo    = bpDueloAtivo($pdo, $user_id);
$dueloFim = $duelo ? null : bpDueloConcluido($pdo, $user_id);
$dueloRes = $dueloFim && $dueloFim['resultado'] ? json_decode((string)$dueloFim['resultado'], true) : null;
$souCriador = $duelo ? ((int)$duelo['id_criador'] === $user_id) : ($dueloFim ? ((int)$dueloFim['id_criador'] === $user_id) : false);

// Sala de espera e resultado do confronto falam só do duelo.
$telaDeDuelo = (bool)$dueloRes || ($duelo && $duelo['status'] === 'aguardando');

// Lenda que já foi sorteada e está esperando escolha.
//
// Sem isto, recarregar a página no meio de um giro deixava o jogador TRAVADO:
// o servidor lembrava da lenda (atual_player_id) e recusava um novo giro, mas
// a tela vinha vazia — sem nome e sem os atributos pra clicar. Então a lenda
// pendente é renderizada já no carregamento, não só na resposta do giro.
$lendaPendente = null;
if ($partida && !$partida['concluido_em'] && !empty($partida['atual_player_id'])) {
    $st = $pdo->prepare("SELECT n.*, p.nome, p.time_atual, p.times
                         FROM build_notas n
                         INNER JOIN hoopgrid_players p ON p.id = n.player_id
                         WHERE n.player_id = ? LIMIT 1");
    $st->execute([(int)$partida['atual_player_id']]);
    $lp = $st->fetch(PDO::FETCH_ASSOC);
    if ($lp) {
        $timeLp = $lp['time_atual'] ?: '';
        if ($timeLp === '' && !empty($lp['times'])) {
            $lista = json_decode((string)$lp['times'], true);
            if (is_array($lista) && $lista) $timeLp = (string)$lista[0];
        }
        $notasLp = [];
        foreach (array_keys($ATRIBUTOS) as $a) {
            $notasLp[$a] = ['nivel' => (int)$lp[$a], 'letra' => BUILD_LETRAS[(int)$lp[$a]]];
        }
        $lendaPendente = [
            'nome'  => $lp['nome'],
            'time'  => $timeLp ?: 'NBA',
            'logo'  => bpLogoDoTime($timeLp ?: 'NBA'),
            'notas' => $notasLp,
        ];
    }
}

// Ranking dos melhores builds da liga. Não vale mais moeda nenhuma — é só
// vitrine, pra ver quem montou o quê.
$topGeral = $pdo->query("SELECT b.ovr, b.grupo, b.nome_jogador, u.nome
                         FROM build_partidas b
                         INNER JOIN games_usuarios u ON u.id = b.id_usuario
                         WHERE b.concluido_em IS NOT NULL
                         ORDER BY b.ovr DESC, b.id ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// Texto do "copiar build" — montado no PHP pra sair igualzinho ao que está
// na tela, inclusive a temporada.
$textoCopiar = '';
if ($partida && $partida['concluido_em']) {
    $l = [];
    $l[] = '🏗️ ' . $partida['nome_jogador'] . ' #' . $partida['camisa']
         . ' · ' . $partida['grupo'] . ' · OVR ' . (int)$partida['ovr'];
    if ($temporada) {
        $h = $temporada['historico'];
        $l[] = $h['no_top']
            ? '#' . $h['posicao'] . ' no top 100 da história da NBA · ' . $h['tier']
            : $h['tier'];
    }
    $l[] = '';
    foreach ($ATRIBUTOS as $chave => $info) {
        $s = $partida['slots'][$chave] ?? null;
        // str_pad conta BYTES: com "Finalização" e "Impulsão" a coluna saía
        // torta, porque cada acento come dois bytes. O preenchimento tem que
        // ser pelo número de caracteres.
        $rotulo = $info['label'] . str_repeat(' ', max(1, 22 - mb_strlen($info['label'])));
        $letra  = ($s['letra'] ?? '-');
        $letra .= str_repeat(' ', max(1, 4 - mb_strlen($letra)));
        $l[] = $rotulo . $letra . '(' . ($s['de'] ?? '-') . ')';
    }
    if ($temporada) {
        $l[] = '';
        $l[] = '🏀 ' . $temporada['time']['nome'] . ' — '
             . $temporada['vitorias'] . '-' . $temporada['derrotas']
             . ((int)$temporada['seed'] > 0 ? ' · ' . (int)$temporada['seed'] . 'º da conferência' : '');
        $l[] = $temporada['playoff']['label'];
        foreach ($temporada['premios'] as $p) $l[] = $p['label'];

        if (!empty($temporada['liga'])) {
            $lg = $temporada['liga'];
            $l[] = '';
            $l[] = '🏆 Campeão: ' . $lg['campeao']['nome'] . ($lg['campeao']['seu'] ? ' (o meu time!)' : '');
            $l[] = '🏅 MVP: '     . $lg['mvp']['nome']     . ($lg['mvp']['seu'] ? ' (eu!)' : '');
            $l[] = '🛡️ DPOY: '    . $lg['dpoy']['nome']    . ($lg['dpoy']['seu'] ? ' (eu!)' : '');
        }
    }
    $l[] = '';
    $l[] = 'Build-A-Player · FBA Brasil';
    $textoCopiar = implode("\n", $l);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>Build-A-Player</title>
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

/* TOP BAR — a mesma dos outros jogos */
.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.topbar-left{display:flex;align-items:center;gap:10px}
.back-btn{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;border:1px solid var(--border);background:transparent;color:var(--text2);text-decoration:none;font-size:14px;transition:.2s;flex-shrink:0}
.back-btn:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.game-title{font-size:15px;font-weight:800;color:var(--text)}
.game-title span{color:var(--red)}
.daily-badge{display:inline-flex;align-items:center;gap:4px;font-size:8px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;padding:2px 8px;border-radius:999px;background:var(--red-soft);border:1px solid var(--red-glow);color:var(--red);margin-left:6px}
.topbar-right{display:flex;align-items:center;gap:6px}
.chip{display:flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;background:var(--panel2);border:1px solid var(--border);font-size:11px;font-weight:700;color:var(--text);white-space:nowrap}

.main{max-width:980px;margin:0 auto;padding:16px 12px 60px}
/* Duas colunas no desktop: roleta de um lado, build e ranking do outro.
   Vira uma coluna só no celular. */
.colunas{display:grid;grid-template-columns:1.15fr .85fr;gap:14px;align-items:start}
@media(max-width:820px){.colunas{grid-template-columns:1fr}}

/* NÃO usar a classe .card aqui: o Bootstrap define .card com a cor de texto
   dele (escura no tema claro) e ela desce por herança pra tudo dentro —
   era o que deixava os nomes dos atributos pretos no fundo preto. */
.bpcard{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:16px;margin-bottom:14px;color:var(--text)}
.bpcard-title{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;gap:8px}

/* ── IDENTIDADE + ESCOLHA DO TIPO ── */
.intro{text-align:center;padding:8px 0 18px}
.intro h1{font-size:22px;font-weight:900;letter-spacing:-.4px;margin-bottom:8px;color:var(--text)}
.intro p{font-size:13px;color:var(--text2);line-height:1.55;max-width:440px;margin:0 auto}
.ident{display:grid;grid-template-columns:1fr 92px;gap:10px;margin-bottom:14px}
.campo label{display:block;font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-bottom:5px}
.campo input{width:100%;background:var(--panel2);border:1.5px solid var(--border);border-radius:10px;padding:11px 12px;font-family:var(--font);font-size:14px;font-weight:700;color:var(--text);outline:none;transition:.15s}
.campo input:focus{border-color:var(--red);background:var(--red-soft)}
.campo input::placeholder{color:var(--text3);font-weight:500}
.campo input.camisa{text-align:center;font-size:18px;font-weight:900;font-variant-numeric:tabular-nums}
.tipos{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.tipo{background:var(--panel2);border:1.5px solid var(--border);border-radius:var(--radius);padding:22px 14px;text-align:center;cursor:pointer;transition:.2s}
.tipo:hover{border-color:var(--red);background:var(--red-soft);transform:translateY(-2px)}
.tipo i{font-size:26px;color:var(--red);display:block;margin-bottom:8px}
.tipo b{display:block;font-size:17px;font-weight:900;letter-spacing:.5px;color:var(--text)}
.tipo span{font-size:10.5px;color:var(--text2);letter-spacing:.4px}

/* ── ROLETAS ── */
.reels{display:grid;grid-template-columns:1fr 1.5fr;gap:8px;margin-bottom:12px}
.reel{background:var(--panel2);border:1px solid var(--border2);border-radius:12px;padding:14px 10px;text-align:center;min-height:88px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;overflow:hidden;transition:.2s}
.reel-label{font-size:8px;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--text3)}
.reel-val{font-size:16px;font-weight:900;line-height:1.15;color:var(--text)}
.reel-logo{height:0;overflow:hidden;transition:height .2s}
.reel-logo.on{height:42px}
.reel-logo img{height:42px;width:42px;object-fit:contain;display:block;margin:0 auto}
.reel.spin .reel-val{animation:reelRoll .5s cubic-bezier(.4,0,.6,1) infinite}
@keyframes reelRoll{0%{transform:translateY(-16px);opacity:0}30%{opacity:1}70%{opacity:1}100%{transform:translateY(16px);opacity:0}}
.reel.hit{border-color:var(--red);box-shadow:0 0 0 3px var(--red-soft)}

.spin-btn{width:100%;background:var(--red);color:#fff;border:0;border-radius:12px;padding:15px;font-family:var(--font);font-size:15px;font-weight:800;letter-spacing:.3px;cursor:pointer;transition:.15s}
.spin-btn:hover:not(:disabled){filter:brightness(1.12)}
.spin-btn:active:not(:disabled){transform:scale(.985)}
.spin-btn:disabled{background:var(--panel3);color:var(--text3);cursor:not-allowed}
.hint{font-size:11.5px;color:var(--text2);text-align:center;margin-top:10px;min-height:16px}
.reroll-btn{width:100%;margin-top:9px;background:transparent;border:1.5px solid var(--border2);color:var(--text2);border-radius:11px;padding:11px;font-family:var(--font);font-size:12.5px;font-weight:700;cursor:pointer;transition:.15s;display:flex;align-items:center;justify-content:center;gap:7px}
.reroll-btn:hover:not(:disabled){border-color:var(--blue);color:var(--blue);background:var(--blue-soft)}
.reroll-btn:disabled{opacity:.3;cursor:not-allowed}
.reroll-btn b{color:var(--blue);font-weight:900}
.reset-btn{display:block;margin:10px auto 0;background:transparent;border:1px solid var(--border);color:var(--text3);border-radius:9px;padding:6px 14px;font-family:var(--font);font-size:11px;font-weight:600;cursor:pointer;transition:.15s}
.reset-btn:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}

/* ── NOTAS DA LENDA SORTEADA ── */
.notas{display:grid;grid-template-columns:repeat(2,1fr);gap:7px;margin-top:14px}
.nota{background:var(--panel2);border:1px solid var(--border);border-radius:10px;padding:10px 11px;display:flex;align-items:center;justify-content:space-between;gap:8px;cursor:pointer;transition:.15s}
.nota:hover:not(.off){border-color:var(--red);background:var(--red-soft);transform:translateY(-1px)}
.nota.off{opacity:.28;cursor:not-allowed}
.nota-nome{font-size:11px;font-weight:600;line-height:1.25;color:var(--text)}
.nota-letra{font-size:17px;font-weight:900;flex-shrink:0}

/* ── SLOTS DO BUILD ── */
.slots{display:flex;flex-direction:column;gap:5px}
.slot{display:flex;align-items:center;gap:9px;background:var(--panel2);border:1px solid var(--border);border-radius:9px;padding:8px 11px;transition:.25s}
.slot.novo{border-color:var(--green);background:var(--green-soft)}
.slot-icon{width:20px;font-size:12px;color:var(--text3);flex-shrink:0;text-align:center}
.slot-txt{flex:1;min-width:0}
.slot-nome{font-size:11.5px;font-weight:600;color:var(--text);display:block}
.slot-de{font-size:9.5px;color:var(--text2);display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.slot-letra{font-size:15px;font-weight:900;flex-shrink:0;color:var(--text3)}
.grupo-sep{font-size:8px;font-weight:700;letter-spacing:1.1px;color:var(--text3);margin:9px 0 1px;padding-left:2px}

.ovr-box{text-align:center;padding:14px 0 2px;margin-top:10px;border-top:1px solid var(--border)}
.ovr-num{font-size:38px;font-weight:900;line-height:1;font-variant-numeric:tabular-nums;color:var(--text)}
.ovr-cap{font-size:9px;font-weight:700;letter-spacing:1.3px;color:var(--text3)}

/* ── TELA FINAL: A CAMISA ── */
.jersey{display:flex;align-items:center;gap:16px;padding:4px 0 16px;border-bottom:1px solid var(--border);margin-bottom:16px}
.jersey-num{width:78px;height:78px;flex-shrink:0;border-radius:16px;background:var(--red-soft);border:2px solid var(--red);color:var(--red);display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:900;font-variant-numeric:tabular-nums;line-height:1}
.jersey-txt{flex:1;min-width:0}
.jersey-nome{font-size:21px;font-weight:900;letter-spacing:-.4px;color:var(--text);line-height:1.15;word-break:break-word}
.jersey-tag{display:inline-block;font-size:9px;font-weight:700;letter-spacing:1px;color:var(--text2);border:1px solid var(--border2);border-radius:999px;padding:2px 9px;margin-top:6px}
.jersey-ovr{text-align:right;flex-shrink:0}
.jersey-ovr b{display:block;font-size:34px;font-weight:900;color:var(--amber);line-height:1;font-variant-numeric:tabular-nums}
.jersey-ovr span{font-size:8px;font-weight:700;letter-spacing:1.3px;color:var(--text3)}

/* ── POSIÇÃO NA HISTÓRIA ── */
.hist{text-align:center;background:var(--panel2);border:1px solid var(--border2);border-radius:12px;padding:16px 14px}
.hist-num{font-size:44px;font-weight:900;line-height:1;color:var(--red);font-variant-numeric:tabular-nums}
.hist-cap{font-size:9.5px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text2);margin-top:4px}
.hist-tier{display:inline-block;margin-top:11px;font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:var(--amber);background:var(--amber-soft);border:1px solid var(--amber);border-radius:999px;padding:5px 14px}
.hist-barra{height:6px;border-radius:999px;background:var(--panel3);overflow:hidden;margin-top:13px}
.hist-barra>div{height:100%;background:linear-gradient(90deg,var(--amber),var(--red))}

/* ── TEMPORADA ── */
.time-linha{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.time-linha img{width:50px;height:50px;object-fit:contain;flex-shrink:0}
.time-linha b{font-size:15px;font-weight:800;color:var(--text);display:block;line-height:1.2}
.time-linha span{font-size:10px;color:var(--text3);letter-spacing:.5px}
.recorde{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;text-align:center}
.recorde>div{background:var(--panel2);border:1px solid var(--border);border-radius:10px;padding:11px 6px}
.recorde b{display:block;font-size:20px;font-weight:900;line-height:1;font-variant-numeric:tabular-nums;color:var(--text)}
.recorde span{font-size:8.5px;font-weight:700;letter-spacing:.9px;color:var(--text2)}
.playoff{margin-top:10px;text-align:center;border-radius:10px;padding:11px;font-size:13px;font-weight:800;background:var(--panel2);border:1px solid var(--border);color:var(--text)}
.playoff.campeao{background:var(--amber-soft);border-color:var(--amber);color:var(--amber)}
.playoff.fora{color:var(--text2)}
.premios{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px;justify-content:center}
.premio{background:var(--amber-soft);border:1px solid var(--amber);color:var(--amber);border-radius:999px;padding:7px 14px;font-size:11.5px;font-weight:800}
.sem-premio{font-size:11px;color:var(--text2);text-align:center;margin-top:10px}

/* ── COMO TERMINOU A LIGA ── */
.liga{margin-top:14px;padding-top:14px;border-top:1px solid var(--border)}
.liga-cap{font-size:9px;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--text2);margin-bottom:9px}
/* min-height alinha as três linhas: só a do campeão tem logo, e sem isso
   ela ficava mais alta que as outras duas. */
.liga-item{display:flex;align-items:center;gap:9px;padding:8px 11px;min-height:42px;background:var(--panel2);border:1px solid var(--border);border-radius:9px;margin-bottom:6px}
.liga-item:last-child{margin-bottom:0}
.liga-item.seu{border-color:var(--amber);background:var(--amber-soft)}
.liga-item img{width:24px;height:24px;object-fit:contain;flex-shrink:0}
.liga-rot{font-size:10.5px;color:var(--text2);flex-shrink:0;white-space:nowrap}
.liga-item b{font-size:12.5px;font-weight:700;color:var(--text);margin-left:auto;text-align:right;line-height:1.25}
.liga-item.seu b{color:var(--amber)}

.fim-moedas{display:flex;align-items:center;justify-content:center;gap:7px;background:var(--amber-soft);border:1px solid var(--amber);color:var(--amber);border-radius:12px;padding:12px;font-size:13.5px;font-weight:800;margin-top:14px}
.fim-nada{font-size:11.5px;color:var(--text2);text-align:center;margin-top:14px;line-height:1.5}
.acoes-fim{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:12px}
@media(max-width:420px){.acoes-fim{grid-template-columns:1fr}}
.copiar-btn{width:100%;background:var(--panel2);border:1.5px solid var(--border2);color:var(--text);border-radius:11px;padding:12px;font-family:var(--font);font-size:13px;font-weight:800;cursor:pointer;transition:.15s;display:flex;align-items:center;justify-content:center;gap:8px}
.copiar-btn:hover{border-color:var(--green);color:var(--green);background:var(--green-soft)}
.novo-btn{width:100%;background:var(--red);border:1.5px solid var(--red);color:#fff;border-radius:11px;padding:12px;font-size:13px;font-weight:800;text-decoration:none;transition:.15s;display:flex;align-items:center;justify-content:center;gap:8px}
.novo-btn:hover{filter:brightness(1.12);color:#fff}

/* ── RANKING ── */
.rank{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);font-size:12px}
.rank:last-child{border-bottom:0}
.rank-pos{width:24px;font-weight:900;color:var(--text3);flex-shrink:0;font-size:11px}
.rank.top1 .rank-pos{color:#ffd700}
.rank.top2 .rank-pos{color:#c0c8d4}
.rank.top3 .rank-pos{color:#cd7f32}
.rank-nome{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600;color:var(--text)}
.rank-nome small{display:block;font-size:9px;color:var(--text3);font-weight:500}
.rank-tag{font-size:8px;font-weight:700;letter-spacing:.6px;color:var(--text3);border:1px solid var(--border);border-radius:999px;padding:1px 7px;flex-shrink:0}
.rank-ovr{font-weight:900;color:var(--amber);flex-shrink:0;font-variant-numeric:tabular-nums}

.aviso{background:var(--amber-soft);border:1px solid var(--amber);border-radius:var(--radius);padding:14px 16px;font-size:12.5px;color:var(--amber);line-height:1.55}
.aviso a{color:var(--amber);font-weight:700}
.vazio{font-size:12px;color:var(--text3);text-align:center;padding:14px 0}
.progresso{height:4px;border-radius:999px;background:var(--panel3);overflow:hidden;margin-bottom:14px}
.progresso>div{height:100%;background:var(--red);transition:width .35s ease}

@media(max-width:420px){
  .reels{grid-template-columns:1fr 1.3fr}
  .reel-val{font-size:14px}
  .notas{grid-template-columns:1fr}
  .jersey-num{width:62px;height:62px;font-size:26px}
  .jersey-nome{font-size:18px}
}

/* ── CONFRONTO ─────────────────────────────────────────────────────────
   Mesma linguagem do resto: painel, vermelho da marca, número em mono. */
.bp-btn{width:100%;background:var(--red);color:#fff;border:0;border-radius:12px;padding:15px;font-family:var(--font);font-size:15px;font-weight:800;letter-spacing:.3px;cursor:pointer;transition:.15s}
.bp-btn:hover{filter:brightness(1.12)}
.bp-btn2{width:100%;background:transparent;border:1.5px solid var(--border2);color:var(--text2);border-radius:11px;padding:11px;font-family:var(--font);font-size:12.5px;font-weight:700;cursor:pointer;transition:.15s}
.bp-btn2:hover{border-color:var(--red);color:var(--red)}
.duelo-exp{font-size:12.5px;color:var(--text2);line-height:1.5;margin-bottom:12px}
.duelo-ops{display:flex;flex-direction:column;gap:8px}
.duelo-linha{display:flex;gap:8px}
.duelo-linha input{flex:1;min-width:0;background:var(--panel2);border:1.5px solid var(--border);border-radius:11px;padding:11px 12px;font-family:var(--font);font-size:13px;font-weight:700;color:var(--text);outline:none}
.duelo-linha input:focus{border-color:var(--red)}
.duelo-linha .bp-btn2{width:auto;flex:none;padding:11px 16px}
.duelo-codigo{font-family:var(--num);font-size:34px;font-weight:900;letter-spacing:6px;color:var(--red);margin:6px 0 14px}
.bp-spinner{width:32px;height:32px;border:3px solid var(--border);border-top-color:var(--red);border-radius:50%;margin:18px auto 14px;animation:bp-spin 1s linear infinite}
@keyframes bp-spin{to{transform:rotate(360deg)}}
.duelo-aposta{font-size:12px;color:var(--text2);margin:12px 0}

.duelo-placar{display:flex;align-items:center;gap:10px}
.dp-lado{flex:1;text-align:center;padding:12px 6px;border-radius:12px;border:1.5px solid var(--border);background:var(--panel2)}
.dp-lado.venceu{border-color:var(--green);background:color-mix(in srgb,var(--green) 10%,var(--panel2))}
.dp-lado img{width:40px;height:40px;object-fit:contain;display:block;margin:0 auto 6px}
.dp-lado b{display:block;font-family:var(--num);font-size:30px;font-weight:900;line-height:1;font-variant-numeric:tabular-nums}
.dp-lado span{display:block;font-size:10px;font-weight:800;letter-spacing:1px;color:var(--text2);margin-top:5px}
.dp-vs{flex:none;font-size:11px;font-weight:800;color:var(--text3);letter-spacing:1px;text-align:center;width:64px}
.dp-ot{color:var(--amber);font-size:9.5px;line-height:1.3;display:block}

.duelo-build{padding:11px 0;border-top:1px solid var(--border)}
.duelo-build:first-of-type{border-top:none}
.db-cab{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.db-rot{font-size:9px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--text3)}
.db-nome{font-size:14px;font-weight:800;color:var(--text)}
.db-tag{font-size:9px;font-weight:800;letter-spacing:.6px;padding:2px 7px;border-radius:20px;background:var(--panel3);color:var(--text2)}
.db-ovr{margin-left:auto;font-family:var(--num);font-size:20px;font-weight:900;color:var(--red)}
.db-linha{display:flex;gap:12px;align-items:baseline;margin-top:5px;font-size:11.5px;color:var(--text2)}
.db-linha b{font-family:var(--num);font-size:14px;font-weight:800;color:var(--text)}
.db-time{margin-left:auto;font-size:10.5px;color:var(--text3)}

/* Painel do adversário durante a montagem */
.adv{display:flex;align-items:center;gap:10px;padding:11px 13px;background:var(--panel2);border:1px solid var(--border);border-radius:12px;margin-bottom:12px}
.adv-txt{flex:1;min-width:0;font-size:12px;color:var(--text2);line-height:1.35}
.adv-txt b{color:var(--text);font-weight:800}
.adv-prog{flex:none;font-family:var(--num);font-size:16px;font-weight:900;color:var(--red);font-variant-numeric:tabular-nums}
.adv-barra{height:4px;background:var(--panel3);border-radius:99px;overflow:hidden;margin-top:6px}
.adv-barra i{display:block;height:100%;background:var(--red);border-radius:99px;transition:width .4s}
</style>
</head>
<body<?= $duelo ? ' data-duelo-status="' . htmlspecialchars($duelo['status']) . '" data-duelo-esperando="1"'
        : ($dueloRes ? ' data-duelo-status="concluido" data-duelo-esperando="1"' : '') ?>>

<div class="topbar">
  <div class="topbar-left">
    <a href="/games.php" class="back-btn" title="Voltar"><i class="bi bi-arrow-left"></i></a>
    <span class="game-title">Build-A-<span>Player</span><span class="daily-badge"><i class="bi bi-infinity"></i>Livre</span></span>
  </div>
  <div class="topbar-right">
    <?php if ($partida && !$partida['concluido_em']): ?>
    <div class="chip"><i class="bi bi-grid-3x3-gap"></i><span id="chipSlots"><?= $preenchidos ?>/10</span></div>
    <?php endif; ?>
    <div class="chip" style="color:var(--amber)"><img src="../moeda.png" style="width:16px;height:16px;object-fit:contain;vertical-align:middle"><?= $pontosUsuario ?></div>
  </div>
</div>

<div class="main">

<?php if ($duelo && $duelo['status'] === 'montando'): ?>
  <!-- "O outro vai vendo": o JS atualiza este bloco a cada 4s. O conteúdo
       inicial é um placeholder de propósito — quem preenche é a primeira
       espiada, pra não existir o mesmo texto renderizado em dois lugares. -->
  <div class="adv" id="bpAdversario">
    <div class="adv-txt">Carregando o adversário…</div>
    <div class="adv-prog">0/10</div>
  </div>
<?php endif; ?>

<?php if (!$temNotas): ?>
  <div class="aviso">
    <b>Nenhuma lenda cadastrada ainda.</b><br>
    Aplique o elenco em <a href="/games/admin/build-lendas.php">Lendas do Build-A-Player</a> pra liberar o jogo.
  </div>

<?php elseif ($dueloRes): ?>
  <?php
    // O resultado é sempre mostrado do SEU ponto de vista: você à esquerda,
    // independentemente de quem criou a sala.
    $meu   = $souCriador ? $dueloRes['a'] : $dueloRes['b'];
    $dele  = $souCriador ? $dueloRes['b'] : $dueloRes['a'];
    $meuPts  = $souCriador ? $dueloRes['jogo']['pontos_a'] : $dueloRes['jogo']['pontos_b'];
    $delePts = $souCriador ? $dueloRes['jogo']['pontos_b'] : $dueloRes['jogo']['pontos_a'];
    $venci = (int)$dueloFim['id_vencedor'] === $user_id;
  ?>
  <div class="intro">
    <h1><?= $venci ? '🏆 Você venceu' : 'Você perdeu' ?></h1>
    <p><?= $venci
        ? 'O pote inteiro é seu: <b>+' . (int)$dueloRes['premio'] . ' moedas</b>.'
        : 'Ficou com o outro dessa vez. Foram ' . (int)$dueloFim['aposta'] . ' moedas.' ?></p>
  </div>

  <div class="bpcard">
    <div class="bpcard-title">O jogo</div>
    <div class="duelo-placar">
      <div class="dp-lado<?= $venci ? ' venceu' : '' ?>">
        <img src="<?= htmlspecialchars($meu['time']['logo']) ?>" alt="" onerror="this.style.display='none'">
        <b><?= (int)$meuPts ?></b>
        <span><?= htmlspecialchars($meu['time']['abbr']) ?></span>
      </div>
      <div class="dp-vs">
        <?= $dueloRes['jogo']['prorrogacoes'] > 0
            ? '<span class="dp-ot">' . (int)$dueloRes['jogo']['prorrogacoes'] . 'ª prorrogação</span>'
            : 'VS' ?>
      </div>
      <div class="dp-lado<?= $venci ? '' : ' venceu' ?>">
        <img src="<?= htmlspecialchars($dele['time']['logo']) ?>" alt="" onerror="this.style.display='none'">
        <b><?= (int)$delePts ?></b>
        <span><?= htmlspecialchars($dele['time']['abbr']) ?></span>
      </div>
    </div>
  </div>

  <div class="bpcard">
    <div class="bpcard-title">As duas builds</div>
    <?php foreach ([[$meu, $meuPts, 'Seu build'], [$dele, $delePts, 'O dele']] as [$b, $pts, $rot]): ?>
      <div class="duelo-build">
        <div class="db-cab">
          <span class="db-rot"><?= $rot ?></span>
          <span class="db-nome"><?= htmlspecialchars($b['nome']) ?></span>
          <span class="db-tag"><?= htmlspecialchars($b['grupo']) ?></span>
          <span class="db-ovr"><?= (int)$b['ovr'] ?></span>
        </div>
        <div class="db-linha">
          <span><b><?= (int)$b['linha']['pts'] ?></b> pts</span>
          <span><b><?= (int)$b['linha']['reb'] ?></b> reb</span>
          <span><b><?= (int)$b['linha']['ast'] ?></b> ast</span>
          <span class="db-time"><?= htmlspecialchars($b['time']['nome']) ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php
    $pediRevanche = (int)($souCriador ? $dueloFim['revanche_criador'] : $dueloFim['revanche_desafiado']) === 1;
    $eleQuer      = (int)($souCriador ? $dueloFim['revanche_desafiado'] : $dueloFim['revanche_criador']) === 1;
  ?>
  <button class="bp-btn" onclick="bpRevanche()" <?= $pediRevanche ? 'disabled' : '' ?>>
    <?= $pediRevanche ? 'Revanche pedida — esperando ele' : ($eleQuer ? 'Ele quer revanche. Topa?' : 'Pedir revanche') ?>
  </button>
  <button class="bp-btn2" style="margin-top:8px" onclick="bpSairResultado()">Fechar</button>

<?php elseif ($duelo && $duelo['status'] === 'aguardando'): ?>
  <div class="intro">
    <h1>Esperando alguém</h1>
    <p><?= $duelo['modo'] === 'aleatorio'
        ? 'Procurando adversário. Assim que alguém entrar, os dois começam a montar.'
        : 'Mande o código pra quem você quer desafiar.' ?></p>
  </div>
  <div class="bpcard centro">
    <?php if ($duelo['modo'] !== 'aleatorio'): ?>
      <div class="bpcard-title">Código do confronto</div>
      <div class="duelo-codigo" id="bpCodigo"><?= htmlspecialchars($duelo['codigo']) ?></div>
      <?php // A chamada vai junto do link: quem recebe no WhatsApp vê quanto
            // vale a partida antes de clicar, em vez de uma URL solta.
            $chamada = 'CONFRONTO DE BUILD NO VALOR DE ' . (int)$duelo['aposta']
                     . ' MOEDA' . ((int)$duelo['aposta'] === 1 ? '' : 'S'); ?>
      <button class="bp-btn" id="bpBtnLink"
              onclick="bpCopiarLink('<?= htmlspecialchars($duelo['codigo']) ?>', this, '<?= htmlspecialchars($chamada) ?>')">Copiar link do convite</button>
      <button class="bp-btn2" style="margin-top:8px" onclick="bpCopiarCodigo(this)">Copiar só o código</button>
    <?php endif; ?>
    <p class="duelo-aposta">Aposta: <b><?= (int)$duelo['aposta'] ?></b> moedas · quem vencer leva <b><?= (int)$duelo['aposta'] * 2 ?></b></p>
    <div class="bp-spinner"></div>
    <p class="duelo-aposta"><?= $duelo['modo'] === 'aleatorio'
        ? 'Assim que outra pessoa entrar no modo aleatório, a tela avança sozinha.'
        : 'Assim que alguém entrar com o código, a tela avança sozinha.' ?></p>
    <button class="bp-btn2" onclick="bpCancelarDuelo()">Cancelar e receber de volta</button>
  </div>
<?php elseif (!$partida): ?>
  <div class="intro">
    <h1>🏗️ Monte sua lenda</h1>
    <p>Dê nome e camisa ao seu jogador, gire a roleta e leve <b>um</b> atributo de cada lenda sorteada. No fim ele entra pra história da NBA — e joga uma temporada de verdade.</p>
  </div>
  <div class="bpcard">
    <div class="bpcard-title">Quem é o seu jogador?</div>
    <div class="ident">
      <div class="campo">
        <label for="bpNome">Nome</label>
        <input type="text" id="bpNome" maxlength="24" placeholder="Ex: Marcos Silva" autocomplete="off"
               value="<?= htmlspecialchars($ultimaIdentidade['nome']) ?>">
      </div>
      <div class="campo">
        <label for="bpCamisa">Camisa</label>
        <input type="number" id="bpCamisa" class="camisa" min="0" max="99" placeholder="23" inputmode="numeric"
               value="<?= htmlspecialchars($ultimaIdentidade['camisa']) ?>">
      </div>
    </div>
    <div class="bpcard-title" style="margin-top:4px">Escolha o tipo de build</div>
    <div class="tipos">
      <div class="tipo" onclick="bpComecar('GUARD')">
        <i class="bi bi-lightning-charge-fill"></i>
        <b>GUARD</b><span>PG · SG · SF</span>
      </div>
      <div class="tipo" onclick="bpComecar('BIG')">
        <i class="bi bi-shield-fill"></i>
        <b>BIG</b><span>PF · C</span>
      </div>
    </div>  </div>

  <?php if (!$duelo): ?>
  <div class="bpcard">
    <div class="bpcard-title">Ou desafie alguém</div>
    <p class="duelo-exp">Os dois montam ao mesmo tempo, cada um vendo o outro preencher. No fim cada build sorteia um time e os dois se enfrentam — quem ganhar leva o pote.</p>
    <div class="duelo-ops">
      <button class="bp-btn2" onclick="bpDueloAleatorio()">Adversário aleatório · <?= BP_APOSTA_ALEATORIA ?> moedas</button>
      <div class="duelo-linha">
        <input type="number" id="bpAposta" min="<?= BP_APOSTA_MIN ?>" max="<?= BP_APOSTA_MAX ?>"
               placeholder="<?= BP_APOSTA_MIN ?>–<?= BP_APOSTA_MAX ?>" inputmode="numeric">
        <button class="bp-btn2" onclick="bpCriarDuelo()">Criar com link</button>
      </div>
      <div class="duelo-linha">
        <input type="text" id="bpCodigoEntrar" maxlength="8" placeholder="Código do amigo"
               autocomplete="off" style="text-transform:uppercase">
        <button class="bp-btn2" onclick="bpEntrarDuelo()">Entrar</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

<?php elseif ($partida['concluido_em']): ?>
  <?php
    $hist = $temporada['historico'] ?? null;
    // Régua da posição: quanto mais perto do topo, mais cheia a barra.
    $pctHist = ($hist && $hist['no_top']) ? max(2, 100 - (($hist['posicao'] - 1) / 99 * 100)) : 0;
  ?>
  <!-- O veredito (posição na história, moedas, copiar) só entra depois que a
       temporada rodou. Mostrar isso de cara entregava o final antes do filme. -->
  <div class="bpcard" id="bpResumo"<?= $revelar ? ' hidden' : '' ?>>
    <div class="jersey">
      <div class="jersey-num"><?= htmlspecialchars($partida['camisa'] ?? '0') ?></div>
      <div class="jersey-txt">
        <div class="jersey-nome"><?= htmlspecialchars($partida['nome_jogador'] ?? 'Sem Nome') ?></div>
        <span class="jersey-tag"><?= htmlspecialchars($partida['grupo']) ?></span>
      </div>
      <div class="jersey-ovr"><b><?= (int)$partida['ovr'] ?></b><span>OVERALL</span></div>
    </div>

    <?php if ($hist): ?>
    <div class="hist">
      <?php if ($hist['no_top']): ?>
        <div class="hist-num">#<?= (int)$hist['posicao'] ?></div>
        <div class="hist-cap">no top 100 da história da NBA</div>
        <div class="hist-tier"><?= htmlspecialchars($hist['tier']) ?></div>
        <div class="hist-barra"><div style="width:<?= round($pctHist) ?>%"></div></div>
      <?php else: ?>
        <div class="hist-num" style="color:var(--text2);font-size:28px">fora do top 100</div>
        <div class="hist-cap" style="margin-top:8px">não entrou na lista dos 100 maiores</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ((int)$partida['moedas'] > 0): ?>
    <div class="fim-moedas"><i class="bi bi-coin"></i>+<?= (int)$partida['moedas'] ?> moedas</div>
    <?php else: ?>
    <div class="fim-nada">Só entrar no <b>top 10 da história</b> paga moedas — e chegar em 1º é quase impossível de propósito.</div>
    <?php endif; ?>

    <div class="acoes-fim">
      <button type="button" class="copiar-btn" onclick="bpCopiar(this)"><i class="bi bi-clipboard"></i> Copiar build</button>
      <a href="?game=buildplayer&amp;novo=1" class="novo-btn"><i class="bi bi-arrow-repeat"></i> Jogar novamente</a>
    </div>
  </div>

  <?php if ($temporada): $po = $temporada['playoff']; ?>
  <div class="bpcard">
    <div class="bpcard-title"><span><i class="bi bi-calendar-check"></i> A temporada dele</span></div>

    <!-- Chegou agora do último slot: o time e a campanha aparecem em etapas,
         não de uma vez. O resultado já está decidido e gravado no servidor —
         isto aqui é só a revelação. Quem recarrega a página vê tudo direto. -->
    <?php if ($revelar): ?>
    <div id="bpEtapaTime">
      <div class="reel" id="reelSorteio" style="margin-bottom:12px">
        <div class="reel-label">Seu time</div>
        <div class="reel-val" id="valSorteio">— — —</div>
      </div>
      <button type="button" class="spin-btn" id="btnSorteio" onclick="bpRevelarTime()">
        <i class="bi bi-dice-3-fill"></i> SORTEAR MEU TIME
      </button>
    </div>
    <?php endif; ?>

    <div id="bpBlocoTime"<?= $revelar ? ' hidden' : '' ?>>
    <div class="time-linha">
      <?php if (!empty($temporada['time']['logo'])): ?>
      <img src="<?= htmlspecialchars($temporada['time']['logo']) ?>" alt="">
      <?php endif; ?>
      <div>
        <b><?= htmlspecialchars($temporada['time']['nome']) ?></b>
        <span>sorteado pro quinteto titular</span>
      </div>
    </div>

    <?php if ($revelar): ?>
    <button type="button" class="spin-btn" id="btnSimular" onclick="bpRevelarTemporada()">
      <i class="bi bi-play-circle-fill"></i> SIMULAR A TEMPORADA
    </button>
    <?php endif; ?>
    </div>

    <div id="bpBlocoTemporada"<?= $revelar ? ' hidden' : '' ?>>
    <div class="recorde">
      <div><b style="color:var(--green)"><?= (int)$temporada['vitorias'] ?></b><span>VITÓRIAS</span></div>
      <div><b style="color:var(--red)"><?= (int)$temporada['derrotas'] ?></b><span>DERROTAS</span></div>
      <div><b><?= $temporada['seed'] ? (int)$temporada['seed'] . 'º' : '—' ?></b><span>NA CONF.</span></div>
    </div>
    <div class="playoff <?= !empty($po['titulo']) ? 'campeao' : ($po['chegou'] === 'fora' ? 'fora' : '') ?>">
      <?= htmlspecialchars($po['label']) ?>
    </div>
    <?php if ($temporada['premios']): ?>
    <div class="premios">
      <?php foreach ($temporada['premios'] as $p): ?>
      <span class="premio"><?= htmlspecialchars($p['label']) ?></span>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="sem-premio">Nenhum prêmio individual nesta temporada.</div>
    <?php endif; ?>

    <?php if (!empty($temporada['liga'])): $lg = $temporada['liga']; ?>
    <div class="liga">
      <div class="liga-cap">Como terminou a liga</div>
      <div class="liga-item <?= $lg['campeao']['seu'] ? 'seu' : '' ?>">
        <?php if (!empty($lg['campeao']['logo'])): ?>
        <img src="<?= htmlspecialchars($lg['campeao']['logo']) ?>" alt="">
        <?php endif; ?>
        <span class="liga-rot">🏆 Campeão</span>
        <b><?= htmlspecialchars($lg['campeao']['nome']) ?><?= $lg['campeao']['seu'] ? ' — o seu time!' : '' ?></b>
      </div>
      <div class="liga-item <?= $lg['mvp']['seu'] ? 'seu' : '' ?>">
        <span class="liga-rot">🏅 MVP</span>
        <b><?= htmlspecialchars($lg['mvp']['nome']) ?><?= $lg['mvp']['seu'] ? ' — você!' : '' ?></b>
      </div>
      <div class="liga-item <?= $lg['dpoy']['seu'] ? 'seu' : '' ?>">
        <span class="liga-rot">🛡️ DPOY</span>
        <b><?= htmlspecialchars($lg['dpoy']['nome']) ?><?= $lg['dpoy']['seu'] ? ' — você!' : '' ?></b>
      </div>
    </div>
    <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

<?php else: ?>
  <div class="progresso"><div id="barra" style="width:<?= $preenchidos * 10 ?>%"></div></div>
<?php endif; ?>

<?php if ($temNotas && $partida && !$partida['concluido_em'] && !$telaDeDuelo): ?>
<div class="colunas">
  <div>
    <div class="bpcard">
      <div class="reels">
        <div class="reel <?= $lendaPendente ? 'hit' : '' ?>" id="reelTime">
          <div class="reel-label">Time</div>
          <div class="reel-logo <?= ($lendaPendente && $lendaPendente['logo']) ? 'on' : '' ?>" id="logoTime"><?php
            if ($lendaPendente && $lendaPendente['logo']): ?><img src="<?= htmlspecialchars($lendaPendente['logo']) ?>" alt=""><?php endif; ?></div>
          <div class="reel-val" id="valTime"><?= $lendaPendente ? htmlspecialchars($lendaPendente['time']) : '—' ?></div>
        </div>
        <div class="reel <?= $lendaPendente ? 'hit' : '' ?>" id="reelLenda">
          <div class="reel-label">Lenda</div>
          <div class="reel-val" id="valLenda"><?= $lendaPendente ? htmlspecialchars($lendaPendente['nome']) : '—' ?></div>
        </div>
      </div>
      <button class="spin-btn" id="btnSpin" onclick="bpGirar()" <?= $lendaPendente ? 'disabled' : '' ?>><i class="bi bi-dice-3-fill"></i> GIRAR</button>
      <!-- Não gostou da lenda? Troca. Duas vezes por partida, e só isso: com
           trocas infinitas dava pra caçar o S em todo slot. -->
      <button type="button" class="reroll-btn" id="btnReroll" onclick="bpRegirar()"
              <?= (!$lendaPendente || $trocasRestantes === 0) ? 'disabled' : '' ?>>
        <i class="bi bi-arrow-repeat"></i> Não gostei, trocar lenda
        <b id="trocas">(<?= $trocasRestantes ?>)</b>
      </button>
      <div class="hint" id="hint"><?= $lendaPendente ? 'Escolha <b>um</b> atributo pra levar.' : 'Gire pra sortear uma lenda.' ?></div>
      <button type="button" class="reset-btn" onclick="bpRecomecar()"><i class="bi bi-arrow-counterclockwise"></i> Recomeçar do zero</button>
      <div class="notas" id="notas"><?php
        if ($lendaPendente):
          foreach ($lendaPendente['notas'] as $chave => $n):
            $ocupado = !empty($partida['slots'][$chave]);
      ?><div class="nota <?= $ocupado ? 'off' : '' ?>"<?= $ocupado ? '' : ' onclick="bpEscolher(\'' . $chave . '\')"' ?>>
          <span class="nota-nome"><?= htmlspecialchars($ATRIBUTOS[$chave]['label']) ?></span>
          <span class="nota-letra" style="color:<?= bpCor((int)$n['nivel']) ?>"><?= $n['letra'] ?></span>
        </div><?php endforeach; endif; ?></div>
    </div>
  </div>
  <div>
<?php endif; ?>

<?php if ($temNotas && $partida && !$telaDeDuelo): ?>
  <div class="bpcard">
    <div class="bpcard-title"><span>Seu build</span><span id="faltam" style="color:var(--red)"></span></div>
    <div class="slots">
      <?php $grupoAtual = ''; foreach ($ATRIBUTOS as $chave => $info):
        if ($info['grupo'] !== $grupoAtual): $grupoAtual = $info['grupo']; ?>
        <div class="grupo-sep"><?= htmlspecialchars($grupoAtual) ?></div>
      <?php endif;
        $s = $partida['slots'][$chave] ?? null; ?>
      <div class="slot" data-attr="<?= $chave ?>">
        <span class="slot-icon"<?= $s ? ' style="color:var(--green)"' : '' ?>><i class="bi bi-<?= $s ? 'check-circle-fill' : 'circle' ?>"></i></span>
        <span class="slot-txt">
          <span class="slot-nome"><?= htmlspecialchars($info['label']) ?></span>
          <span class="slot-de"><?= $s ? htmlspecialchars($s['de']) : 'vazio' ?></span>
        </span>
        <!-- A letra sai colorida também aqui, não só na roleta: no resumo
             final ela ficava com a cor cinza padrão e não dava pra ver de
             relance se o build era bom. -->
        <span class="slot-letra"<?= $s ? ' style="color:' . bpCor((int)$s['nivel']) . '"' : '' ?>><?= $s ? htmlspecialchars($s['letra']) : '–' ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="ovr-box">
      <div class="ovr-num" id="ovrNum"><?= $partida['ovr'] ? (int)$partida['ovr'] : '–' ?></div>
      <div class="ovr-cap">OVERALL</div>
    </div>
  </div>
<?php endif; ?>

<?php if ($temNotas && !$telaDeDuelo): ?>
  <div class="bpcard">
    <div class="bpcard-title"><span><i class="bi bi-trophy-fill"></i> Melhores builds da liga</span><span style="color:var(--text3);font-weight:500;letter-spacing:0;text-transform:none">só vitrine</span></div>
    <?php if (!$topGeral): ?>
      <div class="vazio">Ninguém montou um build ainda. Seja o primeiro.</div>
    <?php else: foreach ($topGeral as $i => $t): ?>
      <div class="rank <?= $i < 3 ? 'top' . ($i + 1) : '' ?>">
        <span class="rank-pos"><?= $i + 1 ?>º</span>
        <span class="rank-nome">
          <?= htmlspecialchars($t['nome_jogador'] ?: $t['nome']) ?>
          <small>por <?= htmlspecialchars($t['nome']) ?></small>
        </span>
        <span class="rank-tag"><?= htmlspecialchars($t['grupo']) ?></span>
        <span class="rank-ovr"><?= (int)$t['ovr'] ?></span>
      </div>
    <?php endforeach; endif; ?>
  </div>
<?php endif; ?>

<?php if ($temNotas && $partida && !$partida['concluido_em'] && !$telaDeDuelo): ?>
  </div>
</div>
<?php endif; ?>

</div>

<script>
const BP_LABELS = <?= json_encode(array_map(fn($a) => $a['label'], $ATRIBUTOS)) ?>;
// Cor por nível: vermelho no fundo da escala, roxo no S.
const BP_CORES = ['#ef4444','#ef4444','#f97316','#f59e0b','#f59e0b','#eab308','#84cc16','#22c55e','#22c55e','#06b6d4','#3b82f6','#a855f7'];
const BP_TEXTO = <?= json_encode($textoCopiar) ?>;
let bpTravado = false;

async function bpPost(dados) {
  const res = await fetch(window.location.href, { method: 'POST', body: new URLSearchParams(dados) });
  return res.json();
}

async function bpComecar(grupo) {
  if (bpTravado) return;
  bpTravado = true;
  const nome   = (document.getElementById('bpNome')?.value || '').trim();
  const camisa = (document.getElementById('bpCamisa')?.value || '').trim();
  const r = await bpPost({ acao: 'comecar', grupo, nome, camisa });
  if (!r.ok) { alert(r.msg); bpTravado = false; return; }
  window.location.href = '?game=buildplayer';
}

/** Joga fora o build em andamento e volta pra escolha de tipo. */
async function bpRecomecar() {
  if (bpTravado) return;
  if (!confirm('Recomeçar do zero?\n\nTudo que você já escolheu neste build é apagado e você escolhe o tipo de novo. ')) return;
  bpTravado = true;
  const r = await bpPost({ acao: 'recomecar' });
  if (!r.ok) { alert(r.msg); bpTravado = false; return; }
  window.location.href = '?game=buildplayer&novo=1';
}

/**
 * Revelação do time sorteado. O time JÁ foi decidido no servidor quando o
 * décimo slot fechou — isto aqui só segura o resultado por um instante pra
 * ele não aparecer de graça junto com o resto.
 */
function bpRevelarTime() {
  const btn = document.getElementById('btnSorteio');
  if (btn.disabled) return;
  btn.disabled = true;

  const reel = document.getElementById('reelSorteio');
  const val  = document.getElementById('valSorteio');
  const siglas = ['CHI','LAL','BOS','GSW','MIA','SAS','NYK','PHX','DET','HOU','DEN','MIL','PHI','OKC'];
  reel.classList.add('spin');
  let i = 0;
  const rolando = setInterval(() => { val.textContent = siglas[i++ % siglas.length]; }, 80);

  setTimeout(() => {
    clearInterval(rolando);
    reel.classList.remove('spin');
    document.getElementById('bpEtapaTime').hidden = true;
    document.getElementById('bpBlocoTime').hidden = false;
  }, 1600);
}

/** Revela a campanha, os playoffs e os prêmios. */
function bpRevelarTemporada() {
  const btn = document.getElementById('btnSimular');
  if (btn.disabled) return;
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> SIMULANDO 82 JOGOS...';

  setTimeout(() => {
    btn.hidden = true;
    document.getElementById('bpBlocoTemporada').hidden = false;

    // Só agora entra o veredito: OVR, posição na história e moedas. Ele fica
    // acima da temporada na página, então o scroll sobe pra ele.
    setTimeout(() => {
      const resumo = document.getElementById('bpResumo');
      if (resumo) {
        resumo.hidden = false;
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }
    }, 1200);
  }, 1500);
}

/** Copia o build inteiro — atributos, temporada e prêmios — como texto. */
async function bpCopiar(btn) {
  const original = btn.innerHTML;
  try {
    await navigator.clipboard.writeText(BP_TEXTO);
  } catch (e) {
    // Sem permissão de clipboard (http, navegador antigo): cai no textarea.
    const ta = document.createElement('textarea');
    ta.value = BP_TEXTO;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    ta.remove();
  }
  btn.innerHTML = '<i class="bi bi-check-lg"></i> Copiado!';
  setTimeout(() => { btn.innerHTML = original; }, 1800);
}

/** Roda nomes falsos nos reels enquanto o servidor não responde. */
function bpRolar(ligar) {
  document.getElementById('reelTime')?.classList.toggle('spin', ligar);
  document.getElementById('reelLenda')?.classList.toggle('spin', ligar);
  if (!ligar) return;
  const times = ['CHI','LAL','BOS','GSW','MIA','SAS','NYK','PHX','DET','HOU'];
  const nomes = ['. . .','? ? ?','— — —'];
  let i = 0;
  const t = setInterval(() => {
    if (!document.getElementById('reelTime')?.classList.contains('spin')) { clearInterval(t); return; }
    document.getElementById('valTime').textContent  = times[i % times.length];
    document.getElementById('valLenda').textContent = nomes[i % nomes.length];
    i++;
  }, 90);
}

// Sem confirmação no trocar: o contador de trocas já está no botão, e uma
// caixa de diálogo no meio de um giro corta o ritmo do jogo.
const bpGirar   = () => bpSortear('girar');
const bpRegirar = () => bpSortear('regirar');

async function bpSortear(acao) {
  if (bpTravado) return;
  bpTravado = true;
  const btn = document.getElementById('btnSpin');
  const btnR = document.getElementById('btnReroll');
  btn.disabled = true;
  btnR.disabled = true;
  document.getElementById('notas').innerHTML = '';
  document.getElementById('reelTime').classList.remove('hit');
  document.getElementById('reelLenda').classList.remove('hit');
  document.getElementById('logoTime').classList.remove('on');
  bpRolar(true);

  const r = await bpPost({ acao });
  // Segura a rolagem um pouco pra dar peso ao sorteio.
  await new Promise(res => setTimeout(res, 750));
  bpRolar(false);

  if (!r.ok) {
    // Recarrega em vez de deixar os reels com o texto falso da animação: o
    // servidor é quem sabe se já tem lenda esperando escolha, e a página
    // sabe desenhar isso. Antes ficava "— — —" e o jogador travava.
    document.getElementById('hint').textContent = r.msg + ' Atualizando...';
    setTimeout(() => location.reload(), 900);
    return;
  }

  // Logo quando a sigla é de um time atual da NBA; senão fica só o texto.
  const boxLogo = document.getElementById('logoTime');
  if (r.lenda.logo) {
    boxLogo.innerHTML = `<img src="${r.lenda.logo}" alt="${r.lenda.time}" onerror="this.parentElement.classList.remove('on')">`;
    boxLogo.classList.add('on');
  } else {
    boxLogo.innerHTML = '';
    boxLogo.classList.remove('on');
  }
  document.getElementById('valTime').textContent  = r.lenda.time;
  document.getElementById('valLenda').textContent = r.lenda.nome;
  document.getElementById('reelTime').classList.add('hit');
  document.getElementById('reelLenda').classList.add('hit');
  document.getElementById('hint').innerHTML = 'Escolha <b>um</b> atributo pra levar.';

  document.getElementById('trocas').textContent = `(${r.trocas_restantes})`;
  btnR.disabled = r.trocas_restantes <= 0;

  const ocupados = [...document.querySelectorAll('.slot')]
    .filter(s => s.querySelector('.slot-de').textContent !== 'vazio')
    .map(s => s.dataset.attr);

  document.getElementById('notas').innerHTML = Object.entries(r.lenda.notas).map(([chave, n]) => {
    const off = ocupados.includes(chave);
    return `<div class="nota ${off ? 'off' : ''}" ${off ? '' : `onclick="bpEscolher('${chave}')"`}>
      <span class="nota-nome">${BP_LABELS[chave]}</span>
      <span class="nota-letra" style="color:${BP_CORES[n.nivel]}">${n.letra}</span>
    </div>`;
  }).join('');

  bpTravado = false;
}

async function bpEscolher(attr) {
  if (bpTravado) return;
  bpTravado = true;
  const r = await bpPost({ acao: 'escolher', atributo: attr });
  if (!r.ok) { alert(r.msg); bpTravado = false; return; }

  const slot = document.querySelector(`.slot[data-attr="${attr}"]`);
  const dado = r.slots[attr];
  slot.classList.add('novo');
  slot.querySelector('.slot-icon').innerHTML = '<i class="bi bi-check-circle-fill"></i>';
  slot.querySelector('.slot-icon').style.color = 'var(--green)';
  slot.querySelector('.slot-de').textContent = dado.de;
  const letra = slot.querySelector('.slot-letra');
  letra.textContent = dado.letra;
  letra.style.color = BP_CORES[dado.nivel];
  setTimeout(() => slot.classList.remove('novo'), 900);

  const feitos = 10 - r.faltam;
  document.getElementById('barra').style.width = (feitos * 10) + '%';
  document.getElementById('chipSlots').textContent = feitos + '/10';
  document.getElementById('faltam').textContent = r.faltam ? `faltam ${r.faltam}` : '';
  document.getElementById('notas').innerHTML = '';
  document.getElementById('reelTime').classList.remove('hit');
  document.getElementById('reelLenda').classList.remove('hit');
  document.getElementById('btnReroll').disabled = true;
  document.getElementById('hint').textContent = r.terminou ? 'Build completo! Sorteando o time...' : 'Gire de novo pra próxima lenda.';
  document.getElementById('btnSpin').disabled = false;

  // Build fechado: vai pra tela final REVELANDO, e sem o "novo" na URL.
  //
  // location.reload() aqui era um bug: quem chegou pelo "Jogar novamente"
  // estava em ?novo=1, o reload carregava a mesma URL, e a tela entendia que
  // o jogador queria começar outro build — jogava fora o resultado e voltava
  // pro começo sem nunca mostrar o time, a temporada nem o OVR.
  if (r.terminou) {
    setTimeout(() => { window.location.href = '?game=buildplayer&revelar=1'; }, 700);
    return;
  }
  bpTravado = false;
}

document.addEventListener('DOMContentLoaded', () => {
  const vazios = [...document.querySelectorAll('.slot-de')].filter(e => e.textContent === 'vazio').length;
  const el = document.getElementById('faltam');
  if (el) el.textContent = vazios ? `faltam ${vazios}` : '';
});

// ── CONFRONTO ──────────────────────────────────────────────────────────
// Todas as ações recarregam a página em vez de remontar a tela por JS: o
// estado do duelo mora no servidor e a tela inteira depende dele, então
// recarregar é mais barato e não tem como divergir.

async function bpCriarDuelo() {
  if (bpTravado) return;
  const aposta = parseInt(document.getElementById('bpAposta')?.value || '0', 10);
  if (!aposta) { alert('Diga quanto quer apostar.'); return; }
  bpTravado = true;
  const r = await bpPost({ acao: 'duelo_criar', aposta });
  if (!r.ok) { alert(r.msg); bpTravado = false; return; }
  window.location.href = '?game=buildplayer';
}

async function bpDueloAleatorio() {
  if (bpTravado) return;
  bpTravado = true;
  const r = await bpPost({ acao: 'duelo_aleatorio' });
  if (!r.ok) { alert(r.msg); bpTravado = false; return; }
  window.location.href = '?game=buildplayer';
}

async function bpEntrarDuelo() {
  if (bpTravado) return;
  const codigo = (document.getElementById('bpCodigoEntrar')?.value || '').trim();
  if (!codigo) { alert('Digite o código.'); return; }
  bpTravado = true;
  const r = await bpPost({ acao: 'duelo_entrar', codigo });
  if (!r.ok) { alert(r.msg); bpTravado = false; return; }
  window.location.href = '?game=buildplayer';
}

async function bpCancelarDuelo() {
  if (bpTravado) return;
  if (!confirm('Cancelar o confronto? A aposta volta pra sua conta.')) return;
  bpTravado = true;
  const r = await bpPost({ acao: 'duelo_cancelar' });
  if (!r.ok) { alert(r.msg); bpTravado = false; return; }
  window.location.href = '?game=buildplayer';
}

async function bpRevanche() {
  if (bpTravado) return;
  bpTravado = true;
  const r = await bpPost({ acao: 'duelo_revanche' });
  if (!r.ok) { alert(r.msg); bpTravado = false; return; }
  if (r.esperando) alert('Revanche pedida. Assim que ele topar, o confronto começa.');
  window.location.href = '?game=buildplayer';
}

async function bpSairResultado() {
  if (bpTravado) return;
  bpTravado = true;
  await bpPost({ acao: 'duelo_sair_resultado' });
  window.location.href = '?game=buildplayer';
}

/** Link do convite: mesma forma do 5x5 (?game=...&codigo=XXXX). */
function bpLinkConvite(codigo) {
  const url = new URL(window.location.href);
  url.search = '';
  url.searchParams.set('game', 'buildplayer');
  url.searchParams.set('codigo', codigo);
  return url.toString();
}

/**
 * Copia o convite com a chamada em cima do link. O clipboard falha em
 * contexto não-seguro (http), então cai num campo temporário em vez de
 * deixar o clique sem efeito nenhum.
 */
async function bpCopiarLink(codigo, botao, chamada) {
  const texto = (chamada ? chamada + ' - ' : '') + bpLinkConvite(codigo);
  try {
    await navigator.clipboard.writeText(texto);
  } catch (e) {
    const tmp = document.createElement('textarea');
    tmp.value = texto;
    tmp.style.position = 'fixed';
    tmp.style.opacity = '0';
    document.body.appendChild(tmp);
    tmp.select();
    try { document.execCommand('copy'); } catch (e2) { /* pior caso, copia na mão */ }
    document.body.removeChild(tmp);
  }
  const antes = botao.textContent;
  botao.textContent = 'Link copiado!';
  setTimeout(() => { if (botao.isConnected) botao.textContent = antes; }, 2000);
}

// Veio de um link de convite? Preenche o código e leva o olho até lá. Não
// entra sozinho — entrar debita a aposta, e isso é decisão de quem clicou.
(function () {
  const codigo = new URLSearchParams(location.search).get('codigo');
  const campo = document.getElementById('bpCodigoEntrar');
  if (!codigo || !campo) return;
  campo.value = codigo.toUpperCase();
  campo.scrollIntoView({ block: 'center' });
  campo.focus();
})();

function bpCopiarCodigo(botao) {
  const codigo = document.getElementById('bpCodigo')?.textContent.trim() || '';
  navigator.clipboard.writeText(codigo).then(() => {
    const antes = botao.textContent;
    botao.textContent = 'Copiado!';
    setTimeout(() => { botao.textContent = antes; }, 1600);
  }).catch(() => alert(codigo));
}

// ── O outro vai vendo ──────────────────────────────────────────────────
// Enquanto o confronto está aberto, pergunta ao servidor o que o adversário
// já preencheu. Recarrega sozinho quando o estado muda de fase (alguém
// entrou na sala, ou o duelo fechou) — é o que faz a tela virar sem
// ninguém apertar nada.
(function () {
  const painel = document.getElementById('bpAdversario');
  const esperando = document.body.dataset.dueloEsperando === '1';
  if (!painel && !esperando) return;

  let faseAnterior = document.body.dataset.dueloStatus || '';

  async function espiar() {
    let d;
    try {
      const r = await bpPost({ acao: 'duelo_estado' });
      d = r && r.duelo;
    } catch (e) { return; }
    if (!d) return;

    // Mudou de fase: a tela inteira é outra, então recarrega.
    if (d.status !== faseAnterior) { window.location.href = '?game=buildplayer'; return; }

    if (painel) {
      const ele = d.ele || {};
      const n = ele.preenchidos || 0;
      painel.querySelector('.adv-prog').textContent = n + '/10';
      painel.querySelector('.adv-barra i').style.width = (n * 10) + '%';
      const nome = ele.nome ? ele.nome : (d.adversario || 'O adversário');
      painel.querySelector('.adv-txt').innerHTML = ele.fechou
        ? '<b>' + nome + '</b> já fechou o build. Falta você.'
        : (ele.comecou
            ? '<b>' + nome + '</b> está montando — ' + n + ' de 10 slots.'
            : '<b>' + (d.adversario || 'O adversário') + '</b> ainda não começou.')
        + '<div class="adv-barra"><i style="width:' + (n * 10) + '%"></i></div>';
    }
  }

  espiar();
  setInterval(espiar, 4000);
})();
</script>
</body>
</html>
