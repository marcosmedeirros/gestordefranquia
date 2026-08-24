<?php
/**
 * A ESCALA DAS LIVES.
 *
 * Todo domingo o bot abre a chamada no grupo de lives, uma por liga. Quem
 * quer participar responde dizendo as funções que topa — dá pra dizer mais
 * de uma. Na segunda o admin monta a escala da semana em cima das lives que
 * JÁ estão no calendário, e quem é escalado recebe aviso e passa a ver a
 * live no próprio calendário.
 *
 * ── Por que a escala se pendura no CALENDÁRIO ────────────────────────────
 *
 * As lives já vivem lá, como evento do tipo 'live', com repetição semanal.
 * Criar uma segunda lista de lives só pra escala seria duas verdades sobre
 * o mesmo jogo: mudar o horário no calendário e a escala continuar apontando
 * pro antigo.
 *
 * Só que evento que repete NÃO tem uma linha por semana — a repetição é uma
 * regra. Então a escala é gravada por (evento, DATA da ocorrência), e não só
 * por evento: sem a data, escalar a live de segunda escalaria todas as
 * segundas até o fim do ano.
 */

require_once __DIR__ . '/calendario.php';

/** As funções que se pode topar numa live. */
function escalaFuncoes(): array
{
    return [
        'comentarista' => ['rotulo' => 'Comentarista', 'icone' => 'bi-mic-fill',        'cor' => '#3b82f6'],
        'narrador'     => ['rotulo' => 'Narrador',     'icone' => 'bi-megaphone-fill',  'cor' => '#f59e0b'],
        'operacional'  => ['rotulo' => 'Operacional',  'icone' => 'bi-sliders',         'cor' => '#a855f7'],
        'transmissao'  => ['rotulo' => 'Transmissão',  'icone' => 'bi-broadcast',       'cor' => '#22c55e'],
    ];
}

function escalaFuncaoValida(?string $f): bool
{
    return $f !== null && isset(escalaFuncoes()[$f]);
}

/**
 * O domingo que abre a semana de uma data.
 *
 * A semana da escala começa no DOMINGO porque é quando a chamada abre. Usar
 * segunda (o padrão ISO) deixaria a chamada de domingo caindo na semana
 * anterior, e a lista nasceria vazia toda vez.
 */
function escalaSemanaDe(?string $data = null): string
{
    $tz = new DateTimeZone('America/Sao_Paulo');
    $d = $data ? new DateTimeImmutable($data, $tz) : new DateTimeImmutable('now', $tz);
    // 'w' é 0 no domingo — subtrair ele já leva ao domingo da semana.
    return $d->modify('-' . (int)$d->format('w') . ' days')->format('Y-m-d');
}

function escalaGarantirTabelas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;

    try {
        // Quem topa o quê, na semana. A chave única é (semana, liga, pessoa,
        // função): responder duas vezes não vira dois votos, e a pessoa pode
        // topar quantas funções quiser — uma linha por função.
        $pdo->exec("CREATE TABLE IF NOT EXISTS escala_disponibilidade (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            semana      DATE        NOT NULL,
            league      VARCHAR(10) NOT NULL,
            id_usuario  INT         NOT NULL,
            funcao      VARCHAR(20) NOT NULL,
            origem      VARCHAR(10) NOT NULL DEFAULT 'bot',
            criado_em   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_disp (semana, league, id_usuario, funcao),
            KEY idx_semana (semana, league)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // A escala. (evento, data, função, pessoa) é único: a mesma pessoa não
        // é escalada duas vezes pra mesma função da mesma live, e mais de uma
        // pessoa pode dividir a função (dois comentaristas, por exemplo).
        $pdo->exec("CREATE TABLE IF NOT EXISTS escala_lives (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            evento_id    INT         NOT NULL,
            data         DATE        NOT NULL,
            league       VARCHAR(10) NOT NULL,
            funcao       VARCHAR(20) NOT NULL,
            id_usuario   INT         NOT NULL,
            criado_por   INT         NULL,
            criado_em    TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
            avisado_em   DATETIME    NULL,
            UNIQUE KEY uk_escala (evento_id, data, funcao, id_usuario),
            KEY idx_data (data),
            KEY idx_pessoa (id_usuario, data)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[escala] tabelas: ' . $e->getMessage());
    }
}

/* ────────────────────────────────────────────────────────────────────────
 * A CHAMADA — quem topa o quê
 * ──────────────────────────────────────────────────────────────────────── */

/**
 * Registra as funções que a pessoa topa. Substitui o que ela tinha dito.
 *
 * Substitui, e não soma: quem responde de novo está corrigindo, não
 * acrescentando. Somar deixaria impossível tirar uma função depois de
 * mandar por engano — e "manda de novo" é o que todo mundo tenta primeiro.
 *
 * Lista vazia é uma resposta válida: é como se sai da semana.
 *
 * @param string[] $funcoes chaves de escalaFuncoes()
 * @return array{ok:bool, erro:?string, funcoes:string[]}
 */
function escalaResponder(PDO $pdo, int $userId, string $liga, array $funcoes, string $origem = 'bot', ?string $semana = null): array
{
    escalaGarantirTabelas($pdo);
    $liga = strtoupper(trim($liga));
    if (!in_array($liga, CALENDARIO_LIGAS, true)) {
        return ['ok' => false, 'erro' => 'Liga inválida.', 'funcoes' => []];
    }

    $validas = array_values(array_unique(array_filter(
        array_map(fn($f) => strtolower(trim((string)$f)), $funcoes),
        'escalaFuncaoValida'
    )));
    $semana = $semana ?: escalaSemanaDe();

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM escala_disponibilidade
                        WHERE semana = ? AND league = ? AND id_usuario = ?")
            ->execute([$semana, $liga, $userId]);

        $ins = $pdo->prepare("INSERT INTO escala_disponibilidade
                              (semana, league, id_usuario, funcao, origem) VALUES (?,?,?,?,?)");
        foreach ($validas as $f) $ins->execute([$semana, $liga, $userId, $f, $origem]);
        $pdo->commit();
        return ['ok' => true, 'erro' => null, 'funcoes' => $validas];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[escala] responder: ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Não deu pra registrar agora.', 'funcoes' => []];
    }
}

/**
 * SOMA uma função. É o que os comandos do grupo usam.
 *
 * Somar, e não substituir: no grupo cada função é um comando próprio
 * (/comentarista, /narrador…), e a pessoa manda um de cada vez. Se o
 * segundo apagasse o primeiro, quem quer as duas coisas nunca conseguiria
 * — e não teria como perceber que perdeu a primeira.
 *
 * A tela é o contrário e está certo assim: lá as caixas vão todas juntas
 * num envio só, então lá o certo é substituir.
 *
 * @return array{ok:bool, novo:bool, erro:?string, todas:string[]}
 */
function escalaAdicionar(PDO $pdo, int $userId, string $liga, string $funcao, ?string $semana = null): array
{
    escalaGarantirTabelas($pdo);
    $liga = strtoupper(trim($liga));
    $funcao = strtolower(trim($funcao));
    if (!in_array($liga, CALENDARIO_LIGAS, true)) return ['ok' => false, 'novo' => false, 'erro' => 'Liga inválida.', 'todas' => []];
    if (!escalaFuncaoValida($funcao))            return ['ok' => false, 'novo' => false, 'erro' => 'Função inválida.', 'todas' => []];

    $semana = $semana ?: escalaSemanaDe();
    try {
        $st = $pdo->prepare("INSERT IGNORE INTO escala_disponibilidade
                             (semana, league, id_usuario, funcao, origem) VALUES (?,?,?,?,'bot')");
        $st->execute([$semana, $liga, $userId, $funcao]);
        $novo = $st->rowCount() > 0;

        $q = $pdo->prepare("SELECT funcao FROM escala_disponibilidade
                             WHERE semana=? AND league=? AND id_usuario=? ORDER BY funcao");
        $q->execute([$semana, $liga, $userId]);
        return ['ok' => true, 'novo' => $novo, 'erro' => null, 'todas' => $q->fetchAll(PDO::FETCH_COLUMN)];
    } catch (Throwable $e) {
        error_log('[escala] adicionar: ' . $e->getMessage());
        return ['ok' => false, 'novo' => false, 'erro' => 'Não deu pra registrar agora.', 'todas' => []];
    }
}

/**
 * Tira a pessoa da chamada — e chama o próximo da fila pro que ela deixou.
 *
 * Quem sai depois de escalado deixa um buraco, e buraco em escala só é
 * descoberto na hora da live. Então aqui: sai da disponibilidade, sai das
 * escalações da semana, e cada vaga aberta é oferecida a quem se ofereceu
 * PRA AQUELA função e ainda não está nela.
 *
 * Quem entra é avisado como qualquer escalado. Quando não há ninguém, a
 * vaga fica vazia e a resposta diz isso — vaga vazia anunciada é melhor
 * que vaga vazia descoberta.
 *
 * @return array{ok:bool, tirou:int, vagas:int, substituidos:array, orfas:array}
 */
function escalaSair(PDO $pdo, int $userId, string $liga, ?string $semana = null): array
{
    escalaGarantirTabelas($pdo);
    $liga = strtoupper(trim($liga));
    $semana = $semana ?: escalaSemanaDe();
    $fim = (new DateTimeImmutable($semana))->modify('+6 days')->format('Y-m-d');
    $out = ['ok' => true, 'tirou' => 0, 'vagas' => 0, 'substituidos' => [], 'orfas' => []];

    try {
        $st = $pdo->prepare("DELETE FROM escala_disponibilidade
                              WHERE semana=? AND league=? AND id_usuario=?");
        $st->execute([$semana, $liga, $userId]);
        $out['tirou'] = $st->rowCount();

        // As escalações que ela larga.
        $q = $pdo->prepare("SELECT id, evento_id, data, funcao FROM escala_lives
                             WHERE id_usuario=? AND league=? AND data BETWEEN ? AND ?");
        $q->execute([$userId, $liga, $semana, $fim]);
        $largou = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out['vagas'] = count($largou);
        if (!$largou) return $out;

        $pdo->prepare("DELETE FROM escala_lives WHERE id_usuario=? AND league=? AND data BETWEEN ? AND ?")
            ->execute([$userId, $liga, $semana, $fim]);

        $disp = escalaDisponiveis($pdo, $liga, $semana);

        foreach ($largou as $v) {
            // Quem já está nessa mesma vaga não serve de substituto.
            $jaNessa = $pdo->prepare("SELECT id_usuario FROM escala_lives
                                       WHERE evento_id=? AND data=? AND funcao=?");
            $jaNessa->execute([$v['evento_id'], $v['data'], $v['funcao']]);
            $ocupados = array_map('intval', $jaNessa->fetchAll(PDO::FETCH_COLUMN));

            // Nem quem já está em OUTRA função da MESMA live: uma pessoa não
            // narra e opera ao mesmo tempo, e escalar assim criaria um
            // problema no lugar de resolver um.
            $naLive = $pdo->prepare("SELECT id_usuario FROM escala_lives WHERE evento_id=? AND data=?");
            $naLive->execute([$v['evento_id'], $v['data']]);
            $ocupados = array_merge($ocupados, array_map('intval', $naLive->fetchAll(PDO::FETCH_COLUMN)));

            $fila = array_values(array_filter(
                $disp[$v['funcao']] ?? [],
                fn($g) => (int)$g['id'] !== $userId && !in_array((int)$g['id'], $ocupados, true)
            ));

            if (!$fila) {
                $out['orfas'][] = ['data' => $v['data'], 'funcao' => $v['funcao'], 'evento_id' => (int)$v['evento_id']];
                continue;
            }

            // O primeiro da fila é o primeiro por NOME (é a ordem que
            // escalaDisponiveis devolve). Sortear pareceria mais justo, mas
            // ninguém consegue conferir um sorteio — e ordem que dá pra
            // conferir é o que evita a conversa de "por que ele e não eu".
            $novo = $fila[0];
            [$ok] = [true];
            $ins = $pdo->prepare("INSERT IGNORE INTO escala_lives
                                  (evento_id, data, league, funcao, id_usuario, criado_por)
                                  VALUES (?,?,?,?,?,NULL)");
            $ins->execute([$v['evento_id'], $v['data'], $liga, $v['funcao'], (int)$novo['id']]);
            if ($ins->rowCount() === 0) {
                $out['orfas'][] = ['data' => $v['data'], 'funcao' => $v['funcao'], 'evento_id' => (int)$v['evento_id']];
                continue;
            }

            escalaAvisar($pdo, (int)$v['evento_id'], $v['data'], $liga, $v['funcao'], (int)$novo['id']);
            $out['substituidos'][] = ['nome' => $novo['nome'], 'data' => $v['data'], 'funcao' => $v['funcao']];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('[escala] sair: ' . $e->getMessage());
        return ['ok' => false, 'tirou' => 0, 'vagas' => 0, 'substituidos' => [], 'orfas' => []];
    }
}

/**
 * Quem se ofereceu na semana, agrupado por função.
 *
 * @return array<string, array<int, array{id:int,nome:string,foto:?string}>>
 */
function escalaDisponiveis(PDO $pdo, string $liga, ?string $semana = null): array
{
    escalaGarantirTabelas($pdo);
    $semana = $semana ?: escalaSemanaDe();
    $out = array_fill_keys(array_keys(escalaFuncoes()), []);

    try {
        $st = $pdo->prepare("SELECT d.funcao, u.id, u.name AS nome, u.photo_url AS foto
                               FROM escala_disponibilidade d
                               JOIN users u ON u.id = d.id_usuario
                              WHERE d.semana = ? AND d.league = ?
                              ORDER BY u.name ASC");
        $st->execute([$semana, strtoupper($liga)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($out[$r['funcao']])) continue;
            $out[$r['funcao']][] = ['id' => (int)$r['id'], 'nome' => $r['nome'], 'foto' => $r['foto']];
        }
    } catch (Throwable $e) {
        error_log('[escala] disponiveis: ' . $e->getMessage());
    }
    return $out;
}

/* ────────────────────────────────────────────────────────────────────────
 * A ESCALA
 * ──────────────────────────────────────────────────────────────────────── */

/** As lives da semana, já expandidas — uma entrada por ocorrência. */
function escalaLivesDaSemana(PDO $pdo, array $ligas, ?string $semana = null): array
{
    $semana = $semana ?: escalaSemanaDe();
    $ate = (new DateTimeImmutable($semana))->modify('+6 days')->format('Y-m-d');

    $todos = calendarioEventos($pdo, $ligas, $semana . ' 00:00:00', $ate . ' 23:59:59');
    $lives = array_values(array_filter($todos, fn($e) => ($e['tipo'] ?? '') === 'live'));
    // A data da ocorrência é a chave junto do evento: sem ela, escalar a live
    // de segunda escalaria todas as segundas até o fim do ano.
    foreach ($lives as &$l) $l['data'] = substr((string)$l['inicio'], 0, 10);
    return $lives;
}

/**
 * Quem está escalado, indexado por "eventoId|data|funcao".
 *
 * @return array<string, array<int, array{id:int,nome:string,escala_id:int}>>
 */
function escalaDaSemana(PDO $pdo, array $ligas, ?string $semana = null): array
{
    escalaGarantirTabelas($pdo);
    $semana = $semana ?: escalaSemanaDe();
    $ate = (new DateTimeImmutable($semana))->modify('+6 days')->format('Y-m-d');

    $out = [];
    try {
        $ph = implode(',', array_fill(0, max(1, count($ligas)), '?'));
        $st = $pdo->prepare("SELECT e.id AS escala_id, e.evento_id, e.data, e.funcao,
                                    u.id, u.name AS nome
                               FROM escala_lives e
                               JOIN users u ON u.id = e.id_usuario
                              WHERE e.data BETWEEN ? AND ?
                                AND e.league IN ($ph)
                              ORDER BY u.name ASC");
        $st->execute(array_merge([$semana, $ate], array_map('strtoupper', $ligas ?: ['ELITE'])));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['evento_id'] . '|' . $r['data'] . '|' . $r['funcao'];
            $out[$k][] = ['id' => (int)$r['id'], 'nome' => $r['nome'], 'escala_id' => (int)$r['escala_id']];
        }
    } catch (Throwable $e) {
        error_log('[escala] da semana: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Escala alguém. Devolve [ok, mensagem].
 *
 * O aviso é mandado AQUI e não numa varredura depois: a pessoa tem que
 * saber na hora, e uma varredura só avisaria no próximo cron — se houvesse.
 */
function escalaEscalar(PDO $pdo, int $eventoId, string $data, string $liga, string $funcao, int $userId, int $porQuem): array
{
    escalaGarantirTabelas($pdo);
    if (!escalaFuncaoValida($funcao)) return [false, 'Função inválida.'];
    $liga = strtoupper($liga);
    if (!in_array($liga, CALENDARIO_LIGAS, true)) return [false, 'Liga inválida.'];

    try {
        $st = $pdo->prepare("INSERT IGNORE INTO escala_lives
                             (evento_id, data, league, funcao, id_usuario, criado_por)
                             VALUES (?,?,?,?,?,?)");
        $st->execute([$eventoId, $data, $liga, $funcao, $userId, $porQuem]);
        if ($st->rowCount() === 0) return [false, 'Essa pessoa já está nessa função.'];
    } catch (Throwable $e) {
        error_log('[escala] escalar: ' . $e->getMessage());
        return [false, 'Não deu pra escalar agora.'];
    }

    escalaAvisar($pdo, $eventoId, $data, $liga, $funcao, $userId);
    return [true, 'Escalado e avisado.'];
}

/** Tira alguém da escala. O aviso de saída não é mandado — ver o comentário. */
function escalaTirar(PDO $pdo, int $escalaId, array $ligasDoAdmin): array
{
    escalaGarantirTabelas($pdo);
    try {
        // A liga entra no WHERE pra admin de liga não mexer na escala de outra.
        $ph = implode(',', array_fill(0, max(1, count($ligasDoAdmin)), '?'));
        $st = $pdo->prepare("DELETE FROM escala_lives WHERE id = ? AND league IN ($ph)");
        $st->execute(array_merge([$escalaId], array_map('strtoupper', $ligasDoAdmin ?: ['-'])));
        return $st->rowCount() > 0 ? [true, 'Tirado da escala.'] : [false, 'Não achei essa escalação.'];
    } catch (Throwable $e) {
        error_log('[escala] tirar: ' . $e->getMessage());
        return [false, 'Não deu pra tirar agora.'];
    }
}

/**
 * Avisa quem foi escalado, pelos dois canais.
 *
 * Nunca deixa uma falha de aviso derrubar a escalação: escalar é o que o
 * admin pediu; avisar é consequência. Se o aviso falhar, a escala continua
 * de pé e aparece no calendário da pessoa do mesmo jeito.
 */
function escalaAvisar(PDO $pdo, int $eventoId, string $data, string $liga, string $funcao, int $userId): void
{
    try {
        $rot = escalaFuncoes()[$funcao]['rotulo'] ?? $funcao;

        $st = $pdo->prepare("SELECT titulo, inicio FROM calendario_eventos WHERE id = ?");
        $st->execute([$eventoId]);
        $ev = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $hora = $ev ? substr((string)$ev['inicio'], 11, 5) : '';
        $quando = date('d/m', strtotime($data)) . ($hora ? " às {$hora}" : '');
        $titulo = trim((string)($ev['titulo'] ?? 'Live da ' . $liga));

        $texto = "Você foi escalado como *{$rot}* na live \"{$titulo}\" ({$liga}), {$quando}.";

        try {
            require_once __DIR__ . '/push.php';
            if (function_exists('sendPushToUser')) {
                sendPushToUser($pdo, $userId, [
                    'title' => 'Você foi escalado',
                    'body'  => "{$rot} · {$titulo} · {$quando}",
                    'url'   => '/calendario.php',
                ], 'escala');
            }
        } catch (Throwable $e) { error_log('[escala] push: ' . $e->getMessage()); }

        try {
            require_once __DIR__ . '/whatsapp.php';
            if (function_exists('whatsappParaUsuario')) {
                whatsappParaUsuario($pdo, $userId, $texto, 'escala');
            }
        } catch (Throwable $e) { error_log('[escala] whatsapp: ' . $e->getMessage()); }

        $pdo->prepare("UPDATE escala_lives SET avisado_em = NOW()
                        WHERE evento_id = ? AND data = ? AND funcao = ? AND id_usuario = ?")
            ->execute([$eventoId, $data, $funcao, $userId]);
    } catch (Throwable $e) {
        error_log('[escala] avisar: ' . $e->getMessage());
    }
}

/* ────────────────────────────────────────────────────────────────────────
 * OS TEXTOS DO GRUPO
 * ──────────────────────────────────────────────────────────────────────── */

/**
 * A chamada da semana, de UMA liga.
 *
 * Uma mensagem por liga e não uma só com tudo: quem joga na RISE não tem o
 * que fazer com a lista da ELITE, e uma mensagem com as quatro ligas vira
 * um paredão que ninguém lê até o fim.
 *
 * A lista das lives entra junto porque é ela que faz a pessoa decidir: dá
 * pra topar sabendo que é quarta às 19h, e não topar às cegas.
 */
function escalaTextoChamada(PDO $pdo, string $liga, ?string $semana = null): string
{
    $liga = strtoupper($liga);
    $semana = $semana ?: escalaSemanaDe();
    $fim = (new DateTimeImmutable($semana))->modify('+6 days');
    $DIAS = ['dom','seg','ter','qua','qui','sex','sáb'];

    $l = ['🎙️ *ESCALA DAS LIVES — ' . $liga . '*',
          '_semana de ' . date('d/m', strtotime($semana)) . ' a ' . $fim->format('d/m') . '_', ''];

    $lives = escalaLivesDaSemana($pdo, [$liga], $semana);
    if ($lives) {
        foreach ($lives as $lv) {
            $d = (int)date('w', strtotime($lv['data']));
            $hora = empty($lv['dia_inteiro']) ? ' ' . substr((string)$lv['inicio'], 11, 5) : '';
            $l[] = '• ' . $DIAS[$d] . ' ' . date('d/m', strtotime($lv['data'])) . $hora . ' — ' . $lv['titulo'];
        }
        $l[] = '';
    }

    $l[] = 'Quem topa participar, manda o comando da função:';
    foreach (escalaFuncoes() as $k => $f) $l[] = '/' . $k . '  — ' . $f['rotulo'];
    $l[] = '';
    $l[] = 'Pode mandar mais de um. */sair* tira você da semana, */verescala* mostra como está.';
    return implode("\n", $l);
}

/** Quem se ofereceu e quem já está escalado, pro grupo. */
function escalaTextoVer(PDO $pdo, string $liga, ?string $semana = null): string
{
    $liga = strtoupper($liga);
    $semana = $semana ?: escalaSemanaDe();
    $fim = (new DateTimeImmutable($semana))->modify('+6 days');
    $DIAS = ['dom','seg','ter','qua','qui','sex','sáb'];

    $l = ['🎙️ *ESCALA — ' . $liga . '*',
          '_semana de ' . date('d/m', strtotime($semana)) . ' a ' . $fim->format('d/m') . '_', ''];

    $disp = escalaDisponiveis($pdo, $liga, $semana);
    $temAlguem = false;
    $l[] = '*Se ofereceram*';
    foreach (escalaFuncoes() as $k => $f) {
        $nomes = array_column($disp[$k] ?? [], 'nome');
        if ($nomes) $temAlguem = true;
        $l[] = $f['rotulo'] . ': ' . ($nomes ? implode(', ', $nomes) : '_ninguém_');
    }
    if (!$temAlguem) {
        $l[] = '';
        $l[] = 'Ninguém ainda. Manda /comentarista, /narrador, /operacional ou /transmissao.';
    }

    // A escala só entra quando já existe: mostrar quatro vagas vazias por
    // live antes de o admin montar não informa nada e triplica a mensagem.
    $lives = escalaLivesDaSemana($pdo, [$liga], $semana);
    $esc = escalaDaSemana($pdo, [$liga], $semana);
    if ($esc) {
        $l[] = '';
        $l[] = '*Escalados*';
        foreach ($lives as $lv) {
            $linhas = [];
            foreach (escalaFuncoes() as $k => $f) {
                $n = array_column($esc[$lv['id'] . '|' . $lv['data'] . '|' . $k] ?? [], 'nome');
                if ($n) $linhas[] = $f['rotulo'] . ': ' . implode(', ', $n);
            }
            if (!$linhas) continue;
            $d = (int)date('w', strtotime($lv['data']));
            $hora = empty($lv['dia_inteiro']) ? ' ' . substr((string)$lv['inicio'], 11, 5) : '';
            $l[] = '';
            $l[] = '*' . $DIAS[$d] . ' ' . date('d/m', strtotime($lv['data'])) . $hora . '* — ' . $lv['titulo'];
            foreach ($linhas as $x) $l[] = '  ' . $x;
        }
    }
    return implode("\n", $l);
}

/**
 * A escala de TODOS os eventos de uma janela, pro calendário.
 *
 * Uma consulta pra janela inteira, e não uma por live: num mês cheio,
 * perguntar por evento seria trinta idas ao banco pra desenhar trinta
 * nomes.
 *
 * @return array<string, array<int, array{funcao:string,rotulo:string,nome:string,id:int}>>
 *         indexado por "eventoId|data"
 */
function escalaDosEventos(PDO $pdo, array $ligas, string $de, string $ate): array
{
    escalaGarantirTabelas($pdo);
    $ligas = array_values(array_intersect(array_map('strtoupper', $ligas), CALENDARIO_LIGAS));
    if (!$ligas) return [];

    $F = escalaFuncoes();
    $out = [];
    try {
        $ph = implode(',', array_fill(0, count($ligas), '?'));
        $st = $pdo->prepare("SELECT e.evento_id, e.data, e.funcao, u.id, u.name AS nome
                               FROM escala_lives e
                               JOIN users u ON u.id = e.id_usuario
                              WHERE e.data BETWEEN ? AND ? AND e.league IN ($ph)
                              ORDER BY e.funcao ASC, u.name ASC");
        $st->execute(array_merge([substr($de, 0, 10), substr($ate, 0, 10)], $ligas));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['evento_id'] . '|' . $r['data']][] = [
                'funcao' => $r['funcao'],
                'rotulo' => $F[$r['funcao']]['rotulo'] ?? $r['funcao'],
                'nome'   => $r['nome'],
                'id'     => (int)$r['id'],
            ];
        }
    } catch (Throwable $e) {
        error_log('[escala] dos eventos: ' . $e->getMessage());
    }
    return $out;
}

/**
 * As escalações de UMA pessoa numa janela — é o que o calendário dela usa.
 *
 * @return array<string, string[]> "eventoId|data" => rótulos das funções
 */
function escalaDaPessoa(PDO $pdo, int $userId, string $de, string $ate): array
{
    escalaGarantirTabelas($pdo);
    $out = [];
    try {
        $st = $pdo->prepare("SELECT evento_id, data, funcao FROM escala_lives
                              WHERE id_usuario = ? AND data BETWEEN ? AND ?");
        $st->execute([$userId, substr($de, 0, 10), substr($ate, 0, 10)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['evento_id'] . '|' . $r['data']][] =
                escalaFuncoes()[$r['funcao']]['rotulo'] ?? $r['funcao'];
        }
    } catch (Throwable $e) {
        error_log('[escala] da pessoa: ' . $e->getMessage());
    }
    return $out;
}
