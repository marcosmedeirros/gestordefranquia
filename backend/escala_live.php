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

/**
 * A SEMANA VIGENTE DE UMA LIGA — que não é a mesma pra todas.
 *
 * A semana do calendário vira no domingo, e isso deixava a primeira live a
 * um dia de distância: a NEXT joga na segunda, então a chamada abria no
 * domingo pra uma live no dia seguinte. Um corte fixo mais cedo resolveria
 * pra NEXT e atrasaria a ROOKIE, que só joga no sábado.
 *
 * Então o corte é de cada liga: assim que a ÚLTIMA live da liga na semana
 * termina, aquela semana acabou pra ela e a chamada já é da seguinte. A
 * ELITE joga quarta e quinta — na quinta à noite ela vira, e sobram cinco
 * dias pra montar a próxima. A NEXT vira na terça, a ROOKIE no sábado. Cada
 * uma no seu ritmo, sem uma atrapalhar a outra.
 *
 * O fim da live: o do evento, quando existe. Sem ele, o início mais quatro
 * horas — resetar às 19h, com a live no ar, faria quem abrisse a página
 * durante a transmissão ver a semana seguinte.
 *
 * Liga sem live na semana não vira: não há o que ter terminado, e avançar
 * sozinha faria a chamada pular uma semana em que ninguém jogou.
 */
function escalaSemanaAtualDaLiga(PDO $pdo, string $liga, ?string $agora = null): string
{
    $liga = strtoupper(trim($liga));
    $tz   = new DateTimeZone('America/Sao_Paulo');
    $ag   = $agora ? new DateTimeImmutable($agora, $tz) : new DateTimeImmutable('now', $tz);

    // Uma consulta por liga por request: isto é chamado de vários pontos
    // (a tela, cada comando do bot) e o resultado não muda no meio.
    static $cache = [];
    $chave = $liga . '|' . $ag->format('Y-m-d H');
    if (isset($cache[$chave])) return $cache[$chave];

    $semana = escalaSemanaDe($ag->format('Y-m-d'));

    // Dois saltos bastam — a semana seguinte nunca terminou ainda. O limite
    // existe pra uma grade estranha não virar laço infinito.
    for ($i = 0; $i < 2; $i++) {
        $lives = escalaLivesDaSemana($pdo, [$liga], $semana);
        if (!$lives) break;

        $ultimoFim = null;
        foreach ($lives as $lv) {
            $fim = !empty($lv['fim'])
                ? new DateTimeImmutable((string)$lv['fim'], $tz)
                : (new DateTimeImmutable((string)$lv['inicio'], $tz))->modify('+4 hours');
            if ($ultimoFim === null || $fim > $ultimoFim) $ultimoFim = $fim;
        }
        if ($ultimoFim === null || $ag <= $ultimoFim) break;

        $semana = (new DateTimeImmutable($semana, $tz))->modify('+7 days')->format('Y-m-d');
    }

    return $cache[$chave] = $semana;
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
            fase        VARCHAR(10) NOT NULL DEFAULT 'todas',
            origem      VARCHAR(10) NOT NULL DEFAULT 'bot',
            criado_em   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_disp (semana, league, id_usuario, funcao),
            KEY idx_semana (semana, league)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // A fase entrou depois. Quem já tinha a tabela precisa da coluna, e
        // o DEFAULT 'todas' faz as linhas antigas continuarem valendo pra
        // tudo — que é exatamente o que elas queriam dizer quando não havia
        // como escolher.
        try {
            $pdo->exec("ALTER TABLE escala_disponibilidade
                        ADD COLUMN fase VARCHAR(10) NOT NULL DEFAULT 'todas' AFTER funcao");
        } catch (Throwable $e) {
            // Já existe. É o caminho normal em toda execução menos a primeira.
        }

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
 * FASE — regular ou playoffs
 *
 * A NEXT tem live de regular na segunda e de playoffs na terça; a RISE tem
 * as duas na sexta. Tem gente que topa uma e não a outra, e até aqui a
 * disponibilidade era só por liga — quem só queria o playoffs da NEXT
 * entrava pras duas ou pra nenhuma.
 * ──────────────────────────────────────────────────────────────────────── */

const ESCALA_FASES = ['todas', 'regular', 'playoffs'];

/**
 * A fase de uma live, lida do título.
 *
 * Sai do TÍTULO e não de uma coluna nova em calendario_eventos porque a
 * live é um evento comum do calendário — a fase é uma leitura da escala
 * sobre o evento, não um dado que o calendário deva carregar pra todo tipo
 * de evento que existe.
 *
 * Devolve null pra live sem fase declarada (a da ROOKIE se chama só
 * "ROOKIE"). Null quer dizer "serve pra todo mundo": restringir uma live
 * que não é nem uma coisa nem outra esconderia gente sem motivo.
 */
function escalaFaseDaLive(?string $titulo): ?string
{
    $t = mb_strtolower(trim((string)$titulo));
    if (str_contains($t, 'playoff')) return 'playoffs';
    if (str_contains($t, 'regular')) return 'regular';
    return null;
}

/** Normaliza o que a pessoa escreveu. Devolve null se não for uma fase. */
function escalaFaseNormalizar(string $txt): ?string
{
    $t = mb_strtolower(trim($txt));
    if ($t === '') return null;
    // "offs" é como se fala na liga, e é a forma que vai nos exemplos. As
    // outras ficam aceitas porque não custa nada — quem digitar "playoffs"
    // não pode ser recusado por escrever por extenso.
    if (in_array($t, ['offs', 'off', 'playoff', 'playoffs', 'playoffis', 'mata-mata', 'matamata'], true)) return 'playoffs';
    if (in_array($t, ['regular', 'regulares', 'temporada', 'tempregular'], true))          return 'regular';
    if (in_array($t, ['todas', 'todos', 'tudo', 'ambas'], true))                           return 'todas';
    return null;
}

/**
 * Essa disponibilidade serve pra essa live?
 *
 * "todas" serve pra tudo, e live sem fase aceita todo mundo — as duas
 * pontas ficam permissivas de propósito. O filtro só exclui o caso claro:
 * quem disse "só playoffs" numa live de regular, e vice-versa.
 */
function escalaFaseServe(?string $faseDaPessoa, ?string $faseDaLive): bool
{
    $p = $faseDaPessoa ?: 'todas';
    if ($p === 'todas' || $faseDaLive === null) return true;
    return $p === $faseDaLive;
}

/** Como a fase aparece na tela e no grupo. Vazio pra "todas". */
function escalaFaseRotulo(?string $fase): string
{
    return match ($fase) {
        'playoffs' => 'só offs',
        'regular'  => 'só regular',
        default    => '',
    };
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
    $semana = $semana ?: escalaSemanaAtualDaLiga($pdo, $liga);

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
function escalaAdicionar(PDO $pdo, int $userId, string $liga, string $funcao, ?string $semana = null, string $fase = 'todas'): array
{
    escalaGarantirTabelas($pdo);
    $liga = strtoupper(trim($liga));
    $funcao = strtolower(trim($funcao));
    if (!in_array($liga, CALENDARIO_LIGAS, true)) return ['ok' => false, 'novo' => false, 'erro' => 'Liga inválida.', 'todas' => []];
    if (!escalaFuncaoValida($funcao))            return ['ok' => false, 'novo' => false, 'erro' => 'Função inválida.', 'todas' => []];
    if (!in_array($fase, ESCALA_FASES, true)) $fase = 'todas';

    $semana = $semana ?: escalaSemanaAtualDaLiga($pdo, $liga);
    try {
        // ON DUPLICATE e não INSERT IGNORE: quem já estava e manda de novo
        // com outra fase está CORRIGINDO. Com IGNORE, "/narrador next offs"
        // depois de "/narrador next" não mudaria nada, e a pessoa ficaria
        // achando que restringiu quando não restringiu.
        $st = $pdo->prepare("INSERT INTO escala_disponibilidade
                             (semana, league, id_usuario, funcao, fase, origem) VALUES (?,?,?,?,?,'bot')
                             ON DUPLICATE KEY UPDATE fase = VALUES(fase)");
        $st->execute([$semana, $liga, $userId, $funcao, $fase]);
        // rowCount do MySQL neste INSERT: 1 = inseriu, 2 = atualizou a fase,
        // 0 = mandou igual ao que já estava. Os três casos merecem resposta
        // diferente — "já estava" depois de trocar a fase seria mentira.
        $n = $st->rowCount();

        $q = $pdo->prepare("SELECT funcao FROM escala_disponibilidade
                             WHERE semana=? AND league=? AND id_usuario=? ORDER BY funcao");
        $q->execute([$semana, $liga, $userId]);
        return ['ok' => true, 'novo' => $n === 1, 'mudou' => $n === 2,
                'erro' => null, 'todas' => $q->fetchAll(PDO::FETCH_COLUMN)];
    } catch (Throwable $e) {
        error_log('[escala] adicionar: ' . $e->getMessage());
        return ['ok' => false, 'novo' => false, 'mudou' => false, 'erro' => 'Não deu pra registrar agora.', 'todas' => []];
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
    $semana = $semana ?: escalaSemanaAtualDaLiga($pdo, $liga);
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
    $semana = $semana ?: escalaSemanaAtualDaLiga($pdo, $liga);
    $out = array_fill_keys(array_keys(escalaFuncoes()), []);

    try {
        $st = $pdo->prepare("SELECT d.funcao, d.fase, u.id, u.name AS nome, u.photo_url AS foto
                               FROM escala_disponibilidade d
                               JOIN users u ON u.id = d.id_usuario
                              WHERE d.semana = ? AND d.league = ?
                              ORDER BY u.name ASC");
        $st->execute([$semana, strtoupper($liga)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($out[$r['funcao']])) continue;
            $out[$r['funcao']][] = [
                'id'   => (int)$r['id'],
                'nome' => $r['nome'],
                'foto' => $r['foto'],
                'fase' => $r['fase'] ?: 'todas',
            ];
        }
    } catch (Throwable $e) {
        error_log('[escala] disponiveis: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Todo mundo da liga, tenha se oferecido ou não.
 *
 * A escala nasceu presa à enquete: só entrava no seletor quem tinha
 * respondido /comentarista e afins naquela semana. Na prática o admin monta
 * a escala quando dá — às vezes antes de alguém responder, às vezes
 * combinando por fora — e ficava travado num "ninguém se ofereceu" que não
 * tinha como destravar pela tela.
 *
 * A enquete continua valendo: quem respondeu aparece em primeiro no
 * seletor, separado dos demais. O que muda é que ela deixou de ser a única
 * porta.
 */
/**
 * Tira alguém de UMA função da lista de disponíveis.
 *
 * Diferente do escalaSair(), que é o /sair do bot e limpa a pessoa de todas
 * as funções de uma vez. Aqui o admin está corrigindo uma linha — quem se
 * ofereceu pra narrar e operar e só vai operar continua na outra.
 *
 * As escalações já feitas NÃO são mexidas: a escala tem o próprio botão de
 * tirar, e apagar live escalada como efeito colateral de uma correção na
 * lista é o tipo de coisa que se descobre tarde. O retorno diz quantas
 * ficaram, pro aviso poder avisar.
 */
function escalaTirarDisponibilidade(PDO $pdo, int $userId, string $liga, string $funcao, ?string $semana = null): array
{
    escalaGarantirTabelas($pdo);
    $liga   = strtoupper(trim($liga));
    $funcao = strtolower(trim($funcao));
    if (!escalaFuncaoValida($funcao)) return [false, 'Função inválida.', 0];

    $semana = $semana ?: escalaSemanaAtualDaLiga($pdo, $liga);
    try {
        $st = $pdo->prepare("DELETE FROM escala_disponibilidade
                              WHERE semana=? AND league=? AND id_usuario=? AND funcao=?");
        $st->execute([$semana, $liga, $userId, $funcao]);
        if ($st->rowCount() === 0) return [false, 'Essa pessoa não estava nessa função.', 0];

        $fim = (new DateTimeImmutable($semana))->modify('+6 days')->format('Y-m-d');
        $q = $pdo->prepare("SELECT COUNT(*) FROM escala_lives
                             WHERE id_usuario=? AND league=? AND funcao=? AND data BETWEEN ? AND ?");
        $q->execute([$userId, $liga, $funcao, $semana, $fim]);
        return [true, 'Tirado da lista.', (int)$q->fetchColumn()];
    } catch (Throwable $e) {
        error_log('[escala] tirar disponibilidade: ' . $e->getMessage());
        return [false, 'Não deu pra tirar agora.', 0];
    }
}

function escalaGenteDaLiga(PDO $pdo, string $liga): array
{
    try {
        $st = $pdo->prepare("SELECT id, name AS nome, photo_url AS foto
                               FROM users
                              WHERE league = ? AND approved = 1
                              ORDER BY name ASC");
        $st->execute([strtoupper($liga)]);
        return array_map(
            fn($r) => ['id' => (int)$r['id'], 'nome' => $r['nome'], 'foto' => $r['foto']],
            $st->fetchAll(PDO::FETCH_ASSOC)
        );
    } catch (Throwable $e) {
        error_log('[escala] gente da liga: ' . $e->getMessage());
        return [];
    }
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
    $semana = $semana ?: escalaSemanaAtualDaLiga($pdo, $liga);
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
    $l[] = 'Pode mandar mais de um, e não tem limite de vagas por função.';

    // A dica da fase só aparece quando a liga TEM as duas na semana. Numa
    // semana só de regular, ensinar a dizer "offs" convida a pessoa a se
    // inscrever pra uma live que não existe.
    $fases = array_unique(array_filter(array_map(
        fn($lv) => escalaFaseDaLive($lv['titulo'] ?? ''), $lives
    )));
    if (count($fases) > 1) {
        $l[] = 'Só quer uma parte? Põe *offs* ou *regular* no fim: '
             . '_/narrador ' . strtolower($liga) . ' offs_';
    }
    $l[] = '';
    $l[] = '*/sair* tira você da semana, */verescala* mostra como está.';
    return implode("\n", $l);
}

/**
 * /live — o manual da escala, no grupo.
 *
 * Existe porque a chamada (/escala) é uma mensagem do momento: ela some
 * na rolagem e quem chega depois não acha. Este é o texto que se fixa no
 * grupo e responde "como eu entro nisso?" sem ninguém precisar explicar de
 * novo.
 *
 * Fala das funções pelo que a pessoa FAZ, e não pelo nome do cargo:
 * "operacional" não diz nada pra quem nunca participou de uma live.
 *
 * Não recebe PDO: é texto fixo. Se um dia depender das lives da semana,
 * vira outro comando — este tem que responder igual em qualquer dia.
 */
function escalaTextoAjuda(): string
{
    $l = [];
    $l[] = '🎙️ *COMO ENTRAR NAS LIVES*';
    $l[] = '';
    $l[] = 'Manda o comando da função que você topa. Só isso — o bot anota e '
         . 'a organização monta a escala em cima de quem se ofereceu.';
    $l[] = '';
    $l[] = '*As quatro funções*';
    $l[] = '/comentarista — comenta o jogo ao vivo';
    $l[] = '/narrador — narra a partida';
    $l[] = '/operacional — cuida do jogo rodando e dos replays';
    $l[] = '/transmissao — sobe a live e cuida do stream';
    $l[] = '';
    $l[] = '*Pode mandar mais de um.* Se você topa narrar e comentar, manda '
         . 'os dois. E *não tem limite de vagas*: cabe mais de um comentarista '
         . 'na mesma live.';
    $l[] = '';
    $l[] = '*Só quer uma parte da semana?*';
    $l[] = 'Põe *offs* ou *regular* no fim do comando:';
    $l[] = '  _/narrador next_ — a NEXT toda';
    $l[] = '  _/narrador next offs_ — só os playoffs';
    $l[] = '  _/narrador next regular_ — só a temporada regular';
    $l[] = 'Sem dizer nada, vale pras duas.';
    $l[] = '';
    $l[] = '*Outra liga?* Põe o nome: _/comentarista rise_. Sem o nome, vale '
         . 'a liga do seu time.';
    $l[] = '';
    $l[] = '*Os outros comandos*';
    $l[] = '/verescala — quem se ofereceu e quem já está escalado';
    $l[] = '/sair — tira você da escala da semana (e o bot chama o próximo da fila)';
    $l[] = '/escala — abre a chamada da semana no grupo';
    $l[] = '/live — este texto aqui';
    $l[] = '';
    $l[] = '_O bot confirma cada comando numa linha. Pra ver a lista inteira '
         . 'de quem se ofereceu, manda /verescala._';
    $l[] = '';
    $l[] = '_Quem for escalado recebe aviso, e a live entra no calendário do '
         . 'site._';
    $l[] = '';
    // Sem esta linha, quem manda o comando na sexta e não se vê no
    // /verescala da semana que está acabando acha que o comando falhou.
    $l[] = '_A chamada reabre assim que a última live da sua liga termina — '
         . 'aí o que você mandar já vale pra semana seguinte._';
    return implode("\n", $l);
}

/** Quem se ofereceu e quem já está escalado, pro grupo. */
function escalaTextoVer(PDO $pdo, string $liga, ?string $semana = null): string
{
    $liga = strtoupper($liga);
    $semana = $semana ?: escalaSemanaAtualDaLiga($pdo, $liga);
    $fim = (new DateTimeImmutable($semana))->modify('+6 days');
    $DIAS = ['dom','seg','ter','qua','qui','sex','sáb'];

    $l = ['🎙️ *ESCALA — ' . $liga . '*',
          '_semana de ' . date('d/m', strtotime($semana)) . ' a ' . $fim->format('d/m') . '_', ''];

    $disp = escalaDisponiveis($pdo, $liga, $semana);
    $temAlguem = false;
    $l[] = '*Se ofereceram*';
    foreach (escalaFuncoes() as $k => $f) {
        // Quem restringiu a fase vem com a marca no nome. Sem isso a lista
        // diria que a pessoa topa a semana toda quando ela topou metade, e
        // o admin escalaria pra live errada.
        $nomes = array_map(function ($g) {
            $rot = escalaFaseRotulo($g['fase'] ?? 'todas');
            return $g['nome'] . ($rot ? " _({$rot})_" : '');
        }, $disp[$k] ?? []);
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
        $st = $pdo->prepare("SELECT e.evento_id, e.data, e.funcao, u.id, u.name AS nome,
                                    u.photo_url AS foto
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
                // A foto vem resolvida daqui e não montada no JS: getUserPhoto
                // é quem sabe o que fazer com foto vazia, e ter essa regra em
                // dois lugares é como elas passam a divergir.
                'foto'   => function_exists('getUserPhoto') ? getUserPhoto($r['foto']) : ($r['foto'] ?: ''),
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
