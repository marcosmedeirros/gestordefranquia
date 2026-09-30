<?php
/**
 * CORRIGIR UM DRAFT QUE JÁ ACONTECEU.
 *
 * Draft errado não se conserta preenchendo vaga: conserta-se tirando o jogador
 * de quem levou por engano e pondo em quem devia ter levado. As duas ações que
 * existiam — reverter uma escolha e preencher uma vazia — davam pra fazer isso
 * em dois passos, mas só na ordem certa: preencher antes de reverter punha o
 * mesmo jogador em duas picks, e a tela não impedia.
 *
 * Aqui é um passo só, numa transação, e o elenco acompanha: quem sai da pick
 * sai do time, quem entra entra.
 */

require_once __DIR__ . '/db.php';

/**
 * Põe um jogador numa pick, tirando-o de onde estiver.
 *
 * @return array{success:bool,error:?string,message:?string,liberou:?array,saiu:?array}
 */
function draftMoverJogadorParaPick(PDO $pdo, int $destinoId, int $playerId, int $adminId = 0): array
{
    $erro = fn(string $m) => ['success' => false, 'error' => $m,
                              'message' => null, 'liberou' => null, 'saiu' => null];

    // ── O destino ────────────────────────────────────────────────────────
    $st = $pdo->prepare('SELECT o.*, ds.season_id, s.season_number
                           FROM draft_order o
                           JOIN draft_sessions ds ON ds.id = o.draft_session_id
                           JOIN seasons s ON s.id = ds.season_id
                          WHERE o.id = ?');
    $st->execute([$destinoId]);
    $destino = $st->fetch(PDO::FETCH_ASSOC);
    if (!$destino) return $erro('Escolha de destino não encontrada.');

    // ── O jogador ────────────────────────────────────────────────────────
    $st = $pdo->prepare('SELECT * FROM draft_pool WHERE id = ?');
    $st->execute([$playerId]);
    $jogador = $st->fetch(PDO::FETCH_ASSOC);
    if (!$jogador) return $erro('Jogador não encontrado no pool.');

    if ((int)$jogador['season_id'] !== (int)$destino['season_id']) {
        return $erro('Esse jogador é de outra temporada de draft.');
    }
    if ((int)$destino['picked_player_id'] === $playerId) {
        return $erro('Esse jogador já está nesta escolha.');
    }

    // ── De onde ele sai (se estiver em alguma pick desta sessão) ─────────
    $st = $pdo->prepare('SELECT o.*, CONCAT(t.city, " ", t.name) AS time_nome
                           FROM draft_order o LEFT JOIN teams t ON t.id = o.team_id
                          WHERE o.draft_session_id = ? AND o.picked_player_id = ?
                          LIMIT 1');
    $st->execute([(int)$destino['draft_session_id'], $playerId]);
    $origem = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    // ── Quem estava no destino e vai sair ────────────────────────────────
    $ocupante = null;
    if (!empty($destino['picked_player_id'])) {
        $st = $pdo->prepare('SELECT * FROM draft_pool WHERE id = ?');
        $st->execute([(int)$destino['picked_player_id']]);
        $ocupante = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $pdo->beginTransaction();
    try {
        $seasonNumber = (int)$destino['season_number'];

        /* O JOGADOR MUDA DE TIME DE VERDADE, e não só no quadro do draft.
           Sai do elenco de quem tinha e entra no de quem passou a ter, com as
           marcas do draft junto — `draft_round`, `draft_pick_position` e
           `drafted_season_number`. Esses três não são enfeite: é por eles que
           o resto do sistema sabe que aquele jogador é um novato daquela
           escolha, e é como make_pick, fill_past_pick e o auto-pick gravam.
           Sem eles o jogador entrava no time como se tivesse vindo de outro
           lugar qualquer. */
        $tirarDoElenco = function (int $teamId, string $nome, int $round, int $pos) use ($pdo, $seasonNumber) {
            // Pela marca do draft primeiro: é o que distingue esta linha de um
            // homônimo que o time tenha por trade ou free agency.
            $st = $pdo->prepare('DELETE FROM players
                                  WHERE team_id = ? AND draft_round = ? AND draft_pick_position = ?
                                    AND (drafted_season_number = ? OR drafted_season_number IS NULL)
                                  LIMIT 1');
            $st->execute([$teamId, $round, $pos, $seasonNumber]);
            if ($st->rowCount() > 0) return true;

            // Elenco antigo, gravado antes de essas colunas existirem: casa
            // pelo nome, como o revert_pick também faz.
            $st = $pdo->prepare('DELETE FROM players WHERE team_id = ? AND name = ? LIMIT 1');
            $st->execute([$teamId, $nome]);
            return $st->rowCount() > 0;
        };

        $porNoElenco = function (int $teamId, array $j, int $round, int $pos) use ($pdo, $seasonNumber) {
            $st = $pdo->prepare('SELECT id FROM players WHERE team_id = ? AND name = ? LIMIT 1');
            $st->execute([$teamId, $j['name']]);
            if ($st->fetchColumn()) return;   // já está lá
            $pdo->prepare('INSERT INTO players
                (team_id, drafted_by_team_id, drafted_season_number, draft_round, draft_pick_position,
                 name, position, age, ovr, role, available_for_trade)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "Banco", 0)')
                ->execute([$teamId, $teamId, $seasonNumber, $round, $pos,
                           $j['name'], $j['position'], (int)$j['age'], (int)$j['ovr']]);
        };

        // 1. Esvazia a pick de origem, se havia uma.
        if ($origem) {
            $pdo->prepare('UPDATE draft_order SET picked_player_id = NULL, picked_at = NULL WHERE id = ?')
                ->execute([(int)$origem['id']]);
            $tirarDoElenco((int)$origem['team_id'], (string)$jogador['name'],
                          (int)$origem['round'], (int)$origem['pick_position']);
        }

        // 2. Devolve o ocupante do destino ao pool.
        if ($ocupante) {
            $pdo->prepare('UPDATE draft_pool
                              SET draft_status = "available", drafted_by_team_id = NULL, draft_order = NULL
                            WHERE id = ?')->execute([(int)$ocupante['id']]);
            $tirarDoElenco((int)$destino['team_id'], (string)$ocupante['name'],
                          (int)$destino['round'], (int)$destino['pick_position']);
        }

        // 3. Põe o jogador no destino.
        $st = $pdo->prepare('SELECT COUNT(*) FROM draft_order WHERE draft_session_id = ? AND round = ?');
        $st->execute([(int)$destino['draft_session_id'], (int)$destino['round']]);
        $tamanhoRodada = (int)$st->fetchColumn();
        $numeroPick = (((int)$destino['round'] - 1) * $tamanhoRodada) + (int)$destino['pick_position'];

        $pdo->prepare('UPDATE draft_order SET picked_player_id = ?, picked_at = NOW() WHERE id = ?')
            ->execute([$playerId, $destinoId]);
        $pdo->prepare('UPDATE draft_pool
                          SET draft_status = "drafted", drafted_by_team_id = ?, draft_order = ?
                        WHERE id = ?')
            ->execute([(int)$destino['team_id'], $numeroPick, $playerId]);
        $porNoElenco((int)$destino['team_id'], $jogador,
                     (int)$destino['round'], (int)$destino['pick_position']);

        $pdo->commit();

        $msg = $jogador['name'] . ' agora é a escolha #' . $destino['pick_position']
             . ' da ' . $destino['round'] . 'ª rodada.';
        if ($origem)   $msg .= ' A escolha #' . $origem['pick_position'] . ' do '
                             . trim((string)$origem['time_nome']) . ' ficou em aberto.';
        if ($ocupante) $msg .= ' ' . $ocupante['name'] . ' voltou pro pool.';

        return [
            'success' => true, 'error' => null, 'message' => $msg,
            'liberou' => $origem ? ['pick_id' => (int)$origem['id'],
                                    'round' => (int)$origem['round'],
                                    'pick_position' => (int)$origem['pick_position'],
                                    'time' => trim((string)$origem['time_nome'])] : null,
            'saiu'    => $ocupante ? ['id' => (int)$ocupante['id'], 'nome' => $ocupante['name']] : null,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[draft_edicao] mover: ' . $e->getMessage());
        return $erro('Não consegui mover o jogador. Nada foi alterado.');
    }
}

/**
 * Os drafts da SPRINT ATIVA de uma liga, do mais recente pro mais antigo.
 *
 * Duas restrições, e as duas evitam editar o draft errado:
 *
 *  - só da liga pedida — o admin trabalha dentro da aba de uma;
 *  - só da sprint ativa — sprint encerrada é história, e os drafts dela ainda
 *    existem no banco. Listar tudo trazia dezenove drafts na NEXT, quase todos
 *    de ciclos que acabaram, e o de agora se perdia no meio deles.
 */
function draftListaDaLiga(PDO $pdo, string $league, int $limite = 40): array
{
    $league = strtoupper($league);

    /* A sprint ativa. Se nenhuma estiver marcada como tal — instalação antiga,
       ou a sprint recém-criada ainda sem status — vale a mais recente, que é o
       que o resto do sistema também assume. */
    $st = $pdo->prepare("SELECT id FROM sprints
                          WHERE league = ? AND status = 'active'
                       ORDER BY sprint_number DESC, id DESC LIMIT 1");
    $st->execute([$league]);
    $sprintId = (int)($st->fetchColumn() ?: 0);
    if ($sprintId <= 0) {
        $st = $pdo->prepare('SELECT id FROM sprints WHERE league = ? ORDER BY sprint_number DESC, id DESC LIMIT 1');
        $st->execute([$league]);
        $sprintId = (int)($st->fetchColumn() ?: 0);
    }
    if ($sprintId <= 0) return [];

    $st = $pdo->prepare('SELECT ds.id, ds.status, ds.season_id, ds.completed_at,
                                s.season_number, s.year, s.sprint_id,
                                sp.sprint_number,
                                (SELECT COUNT(*) FROM draft_order o WHERE o.draft_session_id = ds.id) AS vagas,
                                (SELECT COUNT(*) FROM draft_order o
                                  WHERE o.draft_session_id = ds.id AND o.picked_player_id IS NOT NULL) AS feitas
                           FROM draft_sessions ds
                           JOIN seasons s ON s.id = ds.season_id
                      LEFT JOIN sprints sp ON sp.id = s.sprint_id
                          WHERE s.league = ? AND s.sprint_id = ?
                       ORDER BY ds.id DESC
                          LIMIT ' . (int)$limite);
    $st->execute([$league, $sprintId]);
    $out = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($out as &$d) {
        $d['id']      = (int)$d['id'];
        $d['vagas']   = (int)$d['vagas'];
        $d['feitas']  = (int)$d['feitas'];
        $d['abertas'] = $d['vagas'] - $d['feitas'];
    }
    return $out;
}

/**
 * A ordem completa de um draft, com jogador e time de cada escolha.
 *
 * Confere a liga: o id da sessão vem da tela, e sem esta checagem um admin de
 * liga poderia abrir (e editar) o draft de outra trocando o número na URL.
 */
function draftOrdemCompleta(PDO $pdo, int $sessionId, string $league): array
{
    $st = $pdo->prepare('SELECT s.league FROM draft_sessions ds
                           JOIN seasons s ON s.id = ds.season_id WHERE ds.id = ?');
    $st->execute([$sessionId]);
    $daSessao = (string)($st->fetchColumn() ?: '');
    if (strtoupper($daSessao) !== strtoupper($league)) return [];

    $st = $pdo->prepare('SELECT o.id, o.round, o.pick_position, o.team_id, o.original_team_id,
                                o.picked_player_id,
                                CONCAT(t.city, " ", t.name) AS time,
                                CONCAT(ot.city, " ", ot.name) AS time_origem,
                                dp.name AS jogador, dp.position AS posicao, dp.ovr
                           FROM draft_order o
                      LEFT JOIN teams t  ON t.id  = o.team_id
                      LEFT JOIN teams ot ON ot.id = o.original_team_id
                      LEFT JOIN draft_pool dp ON dp.id = o.picked_player_id
                          WHERE o.draft_session_id = ?
                       ORDER BY o.round, o.pick_position');
    $st->execute([$sessionId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Todos os jogadores do pool daquele draft — os livres E os já escolhidos.
 *
 * Os escolhidos vêm junto de propósito: a correção quase sempre é mover
 * alguém que outro time levou. Uma lista só com os livres não resolveria o
 * caso que fez esta tela existir.
 */
function draftPoolCompleto(PDO $pdo, int $sessionId): array
{
    $st = $pdo->prepare('SELECT dp.id, dp.name, dp.position, dp.age, dp.ovr, dp.draft_status,
                                dp.drafted_by_team_id,
                                CONCAT(t.city, " ", t.name) AS time_atual
                           FROM draft_pool dp
                      LEFT JOIN teams t ON t.id = dp.drafted_by_team_id
                          WHERE dp.season_id = (SELECT season_id FROM draft_sessions WHERE id = ?)
                       ORDER BY dp.draft_status ASC, dp.ovr DESC, dp.name ASC');
    $st->execute([$sessionId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * CRIA UM JOGADOR NOVO NO POOL DAQUELE DRAFT.
 *
 * O corretor de draft só sabia mexer em quem já estava no pool, e o caso que
 * falta é o oposto: a pick ficou em aberto porque o jogador escolhido NUNCA
 * foi cadastrado. Sem isto, o admin tinha que sair da tela, ir cadastrar o
 * jogador em outro lugar e voltar — e, no meio do caminho, perder qual pick
 * estava corrigindo.
 *
 * O JOGADOR NASCE DISPONÍVEL, e não já colocado na pick. Quem chama decide o
 * que fazer com ele em seguida (@see draftMoverJogadorParaPick): assim criar
 * por engano não estraga uma escolha, só deixa um nome sobrando no pool.
 *
 * A TEMPORADA VEM DA SESSÃO, nunca do formulário. O pool é por temporada, e um
 * season_id vindo da tela poria o jogador no draft do ano errado — onde ele
 * apareceria pra ser escolhido num draft que já acabou.
 *
 * @return array{success:bool,error:?string,player:?array}
 */
function draftCriarJogadorNoPool(PDO $pdo, int $sessionId, array $dados): array
{
    $erro = fn(string $m) => ['success' => false, 'error' => $m, 'player' => null];

    /* O BANCO PODE RECUSAR, e recusa virando excecao: sem este try o admin
       levava uma tela de erro do PHP no lugar de uma mensagem. O resto deste
       arquivo ja trata assim. */
    try {
        $st = $pdo->prepare('SELECT season_id FROM draft_sessions WHERE id = ?');
        $st->execute([$sessionId]);
        $seasonId = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        error_log('[draft] criar jogador, sessao: ' . $e->getMessage());
        return $erro('Nao deu pra ler o draft agora.');
    }
    if (!$seasonId) return $erro('Draft não encontrado.');

    $nome = trim((string)($dados['name'] ?? ''));
    if ($nome === '') return $erro('O nome é obrigatório.');
    /* Cadastro à mão segue a mesma régua do CSV: quem digita "L. James" na
       pressa cria exatamente o problema que o arquivo criava, e sai igual
       de caro depois. @see backend/draft_class_csv.php */
    require_once __DIR__ . '/draft_class_csv.php';
    if (draftNomeCortado($nome)) {
        return $erro('Escreva o nome inteiro — "LeBron James", não "L. James".');
    }
    if (mb_strlen($nome) > 120) $nome = mb_substr($nome, 0, 120);

    $posicoes = ['PG', 'SG', 'SF', 'PF', 'C'];
    $pos = strtoupper(trim((string)($dados['position'] ?? '')));
    if (!in_array($pos, $posicoes, true)) return $erro('Posição inválida.');

    $pos2 = strtoupper(trim((string)($dados['secondary_position'] ?? '')));
    if ($pos2 === '' || $pos2 === $pos || !in_array($pos2, $posicoes, true)) $pos2 = null;

    $idade = (int)($dados['age'] ?? 0);
    $ovr   = (int)($dados['ovr'] ?? 0);
    if ($idade < 16 || $idade > 45) return $erro('Idade fora da faixa (16 a 45).');
    if ($ovr < 1 || $ovr > 99)      return $erro('Overall fora da faixa (1 a 99).');

    /* NOME REPETIDO NO MESMO POOL É QUASE SEMPRE CLIQUE DUPLO. Dois jogadores
       com o mesmo nome no mesmo draft deixam a lista impossível de usar — a
       tela mostra nome, posição e overall, e nada distingue os dois. */
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM draft_pool WHERE season_id = ? AND name = ?');
        $st->execute([$seasonId, $nome]);
        if ((int)$st->fetchColumn() > 0) return $erro('Já existe um "' . $nome . '" no pool deste draft.');

        $st = $pdo->prepare('INSERT INTO draft_pool (season_id, name, position, secondary_position,
                                                     age, ovr, draft_status)
                             VALUES (?, ?, ?, ?, ?, ?, "available")');
        $st->execute([$seasonId, $nome, $pos, $pos2, $idade, $ovr]);
        $novoId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('[draft] criar jogador: ' . $e->getMessage());
        return $erro('Nao deu pra criar o jogador agora.');
    }

    return ['success' => true, 'error' => null, 'player' => [
        'id' => $novoId, 'name' => $nome, 'position' => $pos,
        'secondary_position' => $pos2, 'age' => $idade, 'ovr' => $ovr,
        'draft_status' => 'available', 'time_atual' => null,
    ]];
}
