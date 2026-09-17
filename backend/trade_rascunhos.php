<?php
/**
 * RASCUNHO DE TROCA: a mesa montada que ainda não foi proposta a ninguém.
 *
 * A Trade Machine era tudo ou nada — ou você enviava a proposta, ou perdia a
 * montagem ao fechar a aba. Quem monta três cenários pra decidir qual manda
 * tinha que anotar em papel. O rascunho fica no banco (atravessa a troca de
 * aparelho, como o rascunho do registro de pontuação) e NÃO notifica ninguém:
 * o outro GM não vê, não recebe WhatsApp, não existe proposta nenhuma.
 *
 * Guardo em duas tabelas de propósito. `dados` (JSON) é o que a Trade Machine
 * precisa pra remontar a mesa exatamente como estava — slots, de quem vem cada
 * item, proteção de pick. `trade_rascunho_itens` é a mesma informação
 * normalizada, e existe por um motivo só: descobrir por consulta quando um
 * ativo do rascunho não está mais onde estava. Aí o rascunho todo morre —
 * uma troca com um jogador que já foi embora não descreve mais nada.
 */

/** Quantos rascunhos cada time pode ter guardados. */
const TRADE_RASCUNHO_MAX = 5;

/** Garante as tabelas. DDL comita sozinho: nunca de dentro de transação. */
function rascunhoGarantirTabelas(PDO $pdo): void
{
    static $feito = false;
    if ($feito || $pdo->inTransaction()) return;
    $feito = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS trade_rascunhos (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            team_id    INT NOT NULL,
            league     VARCHAR(20) NOT NULL,
            dados      LONGTEXT NOT NULL,
            notes      TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_trade_rascunhos_team (team_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        /* Sem FK pra players/picks: o CASCADE apagaria só a LINHA do item, e o
           rascunho ficaria de pé descrevendo uma troca com um pedaço a menos.
           O que eu quero é o contrário — ativo fora do lugar mata o rascunho
           inteiro, e isso é rascunhosLimparFurados() que faz. */
        $pdo->exec("CREATE TABLE IF NOT EXISTS trade_rascunho_itens (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            rascunho_id  INT NOT NULL,
            from_team_id INT NOT NULL,
            to_team_id   INT NOT NULL,
            player_id    INT NULL,
            pick_id      INT NULL,
            CONSTRAINT fk_trade_rascunho_itens FOREIGN KEY (rascunho_id)
                REFERENCES trade_rascunhos(id) ON DELETE CASCADE,
            INDEX idx_trade_rascunho_itens_player (player_id),
            INDEX idx_trade_rascunho_itens_pick (pick_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[trade_rascunhos] tabelas: ' . $e->getMessage());
    }
}

/**
 * Apaga os rascunhos que já não descrevem nada real.
 *
 * Furado é o rascunho que tem QUALQUER item fora do lugar: jogador que saiu
 * do time que o oferecia (trade aceita, waiver, FA, admin), jogador que nem
 * existe mais (aposentado), pick que trocou de dono ou que já virou jogador
 * no draft. É a mesma ideia de cancelarTrocasImpossiveis() e
 * cancelarTrocasComPickUsada(), mas para rascunho não existe "cancelado":
 * ninguém foi avisado de nada, então ele simplesmente desaparece.
 *
 * @param int|null $teamId limita ao time (a faxina da tela dele); null = todos
 * @return int quantos rascunhos foram apagados
 */
function rascunhosLimparFurados(PDO $pdo, ?int $teamId = null): int
{
    rascunhoGarantirTabelas($pdo);
    if ($pdo->inTransaction()) return 0;

    try {
        $sql = "SELECT DISTINCT r.id
                  FROM trade_rascunhos r
                  JOIN trade_rascunho_itens i ON i.rascunho_id = r.id
                  LEFT JOIN players p ON p.id = i.player_id
                  LEFT JOIN picks   k ON k.id = i.pick_id
                 WHERE (i.player_id IS NOT NULL AND (p.id IS NULL OR p.team_id <> i.from_team_id))
                    OR (i.pick_id   IS NOT NULL AND (k.id IS NULL OR k.team_id <> i.from_team_id))";
        $params = [];
        if ($teamId) { $sql .= ' AND r.team_id = ?'; $params[] = $teamId; }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $furados = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        // Pick já gasta no draft continua na tabela `picks`, com o mesmo dono:
        // só a lista de usadas sabe que ela virou jogador.
        require_once __DIR__ . '/picks_usadas.php';
        $usadas = picksJaUsadas($pdo);
        if ($usadas) {
            $params2 = $teamId ? [$teamId] : [];
            $st2 = $pdo->prepare("SELECT r.id, i.pick_id FROM trade_rascunhos r
                                    JOIN trade_rascunho_itens i ON i.rascunho_id = r.id
                                   WHERE i.pick_id IS NOT NULL"
                                   . ($teamId ? ' AND r.team_id = ?' : ''));
            $st2->execute($params2);
            foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!empty($usadas[(int)$row['pick_id']])) $furados[] = (int)$row['id'];
            }
        }

        $furados = array_values(array_unique($furados));
        if (!$furados) return 0;

        $ph = implode(',', array_fill(0, count($furados), '?'));
        $pdo->prepare("DELETE FROM trade_rascunho_itens WHERE rascunho_id IN ($ph)")->execute($furados);
        $pdo->prepare("DELETE FROM trade_rascunhos WHERE id IN ($ph)")->execute($furados);
        return count($furados);
    } catch (Throwable $e) {
        error_log('[trade_rascunhos] limpar: ' . $e->getMessage());
        return 0;
    }
}

/**
 * O rótulo de uma pick na lista de rascunhos: ano, rodada e de quem é a vaga.
 *
 * "1ª rodada de 2031 (Suns)" diz o que importa pra reconhecer o rascunho. O
 * número corrido da escolha fica de fora de propósito: ele não está na tabela
 * `picks`, vem da ordem do draft, e uma consulta a mais aqui só pra escrever
 * "Escolha 18" não paga o preço.
 */
function rascunhoRotuloPick(array $pk): string
{
    $ano    = $pk['season_year'] ?? '?';
    $rodada = ((int)($pk['round'] ?? 1)) === 1 ? '1ª rodada' : '2ª rodada';
    $orig   = trim(($pk['orig_city'] ?? '') . ' ' . ($pk['orig_name'] ?? ''));
    return "{$rodada} de {$ano}" . ($orig !== '' ? " ({$orig})" : '');
}

/**
 * Os rascunhos de um time, já com o texto de quem recebe o quê.
 *
 * O resumo é montado AGORA, com o nome que o jogador tem hoje: guardar o
 * texto junto do rascunho faria a lista mostrar um nome antigo depois de uma
 * correção de cadastro.
 */
function rascunhosDoTime(PDO $pdo, int $teamId): array
{
    rascunhoGarantirTabelas($pdo);
    try {
        $st = $pdo->prepare('SELECT * FROM trade_rascunhos WHERE team_id = ? ORDER BY updated_at DESC, id DESC');
        $st->execute([$teamId]);
        $linhas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$linhas) return [];

        $ids = array_map(fn($l) => (int)$l['id'], $linhas);
        $ph  = implode(',', array_fill(0, count($ids), '?'));

        /* A pick NÃO guarda a posição: `picks` só tem ano, rodada e time de
           origem — o número ("Escolha 18") vive em draft_order e só existe
           depois da loteria. Aqui o rótulo é o que a tabela sabe dizer
           sozinha; o número aparece quando a mesa abre na Trade Machine, que
           é quem cruza com a ordem do draft. */
        $stIt = $pdo->prepare("SELECT i.*, p.name AS player_name, p.position, p.ovr,
                                      pk.round, pk.season_year,
                                      orig.city AS orig_city, orig.name AS orig_name
                                 FROM trade_rascunho_itens i
                                 LEFT JOIN players p  ON p.id = i.player_id
                                 LEFT JOIN picks   pk ON pk.id = i.pick_id
                                 LEFT JOIN teams   orig ON orig.id = pk.original_team_id
                                WHERE i.rascunho_id IN ($ph)
                                ORDER BY i.id ASC");
        $stIt->execute($ids);
        $itensPorRascunho = [];
        foreach ($stIt->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $itensPorRascunho[(int)$it['rascunho_id']][] = $it;
        }

        // Nome dos times envolvidos, numa consulta só.
        $stT = $pdo->query('SELECT id, city, name FROM teams');
        $nomes = [];
        foreach ($stT->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $nomes[(int)$t['id']] = trim(($t['city'] ?? '') . ' ' . ($t['name'] ?? ''));
        }

        $saida = [];
        foreach ($linhas as $l) {
            $rid   = (int)$l['id'];
            $itens = $itensPorRascunho[$rid] ?? [];

            // Agrupa por QUEM RECEBE — é assim que a Trade Machine mostra.
            $porDestino = [];
            foreach ($itens as $it) {
                $destino = (int)$it['to_team_id'];
                $porDestino[$destino][] = $it['player_id'] !== null
                    ? ($it['player_name'] ?: 'Jogador #' . (int)$it['player_id'])
                    : rascunhoRotuloPick($it);
            }
            $lados = [];
            foreach ($porDestino as $destino => $oQue) {
                $lados[] = [
                    'team_id' => $destino,
                    'team'    => $nomes[$destino] ?? ('Time #' . $destino),
                    'itens'   => $oQue,
                ];
            }

            $dados = json_decode((string)$l['dados'], true) ?: [];
            $timesIds = array_map(fn($s) => (int)($s['team_id'] ?? 0), $dados['slots'] ?? []);
            $times = array_values(array_filter(array_map(fn($id) => $nomes[$id] ?? null, $timesIds)));

            $saida[] = [
                'id'         => $rid,
                'times'      => $times,
                'lados'      => $lados,
                'notes'      => $l['notes'],
                'updated_at' => $l['updated_at'],
                'total_itens' => count($itens),
            ];
        }
        return $saida;
    } catch (Throwable $e) {
        error_log('[trade_rascunhos] listar: ' . $e->getMessage());
        return [];
    }
}

/** O JSON da mesa, pra Trade Machine remontar. */
function rascunhoDados(PDO $pdo, int $teamId, int $id): ?array
{
    rascunhoGarantirTabelas($pdo);
    try {
        $st = $pdo->prepare('SELECT dados, notes FROM trade_rascunhos WHERE id = ? AND team_id = ?');
        $st->execute([$id, $teamId]);
        $l = $st->fetch(PDO::FETCH_ASSOC);
        if (!$l) return null;
        $dados = json_decode((string)$l['dados'], true) ?: [];
        $dados['notes'] = $l['notes'];
        return $dados;
    } catch (Throwable $e) {
        error_log('[trade_rascunhos] ler: ' . $e->getMessage());
        return null;
    }
}

/**
 * Grava (ou regrava) um rascunho.
 *
 * `$slots` é [['key'=>'A','team_id'=>1], ...] na ordem dos painéis, e
 * `$itens` é [['para'=>'A','de'=>'B','tipo'=>'player','id'=>10, ...], ...] —
 * o mesmo vocabulário do estado da Trade Machine, pra ida e volta serem a
 * mesma coisa.
 *
 * @return array{0:bool,1:?int,2:string} [ok, id, erro]
 */
function rascunhoSalvar(PDO $pdo, int $teamId, string $league, ?int $id, array $slots, array $itens, string $notes): array
{
    rascunhoGarantirTabelas($pdo);

    // Só times de verdade, e o id do time por slot.
    $timePorSlot = [];
    foreach ($slots as $s) {
        $k = (string)($s['key'] ?? '');
        $t = (int)($s['team_id'] ?? 0);
        if ($k !== '' && $t > 0) $timePorSlot[$k] = $t;
    }
    if (count($timePorSlot) < 2) return [false, null, 'Escolha os dois times antes de salvar o rascunho.'];

    $limpos = [];
    foreach ($itens as $it) {
        $para = (string)($it['para'] ?? '');
        $de   = (string)($it['de'] ?? '');
        $pid  = (int)($it['id'] ?? 0);
        $tipo = ($it['tipo'] ?? '') === 'pick' ? 'pick' : 'player';
        if ($pid <= 0 || !isset($timePorSlot[$para]) || !isset($timePorSlot[$de])) continue;
        if ($timePorSlot[$para] === $timePorSlot[$de]) continue;
        $limpos[] = [
            'para' => $para, 'de' => $de, 'tipo' => $tipo, 'id' => $pid,
            'protection' => $it['protection'] ?? null,
            'swapRole'   => $it['swapRole'] ?? null,
        ];
    }
    if (!$limpos) return [false, null, 'Coloque pelo menos um jogador ou pick na mesa.'];

    /* O rascunho é do time que montou, e só vale com ativos dele ou dos
       outros times da mesa — é o mesmo desenho da proposta. O que eu barro
       aqui é ativo de time de OUTRA liga ou que não está no time indicado:
       salvar isso seria guardar um rascunho que a faxina apagaria no
       primeiro carregamento. */
    $idsJogador = array_values(array_unique(array_map(fn($i) => $i['id'], array_filter($limpos, fn($i) => $i['tipo'] === 'player'))));
    $idsPick    = array_values(array_unique(array_map(fn($i) => $i['id'], array_filter($limpos, fn($i) => $i['tipo'] === 'pick'))));
    $donoJogador = []; $donoPick = [];
    try {
        if ($idsJogador) {
            $ph = implode(',', array_fill(0, count($idsJogador), '?'));
            $st = $pdo->prepare("SELECT id, team_id FROM players WHERE id IN ($ph)");
            $st->execute($idsJogador);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $donoJogador[(int)$r['id']] = (int)$r['team_id'];
        }
        if ($idsPick) {
            $ph = implode(',', array_fill(0, count($idsPick), '?'));
            $st = $pdo->prepare("SELECT id, team_id FROM picks WHERE id IN ($ph)");
            $st->execute($idsPick);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $donoPick[(int)$r['id']] = (int)$r['team_id'];
        }
    } catch (Throwable $e) {
        error_log('[trade_rascunhos] donos: ' . $e->getMessage());
        return [false, null, 'Não deu pra conferir o elenco agora. Tente de novo.'];
    }
    foreach ($limpos as $it) {
        $dono = $it['tipo'] === 'player' ? ($donoJogador[$it['id']] ?? 0) : ($donoPick[$it['id']] ?? 0);
        if ($dono !== $timePorSlot[$it['de']]) {
            return [false, null, 'Algum jogador ou pick da mesa já não está no time que estava entregando. Recarregue a página.'];
        }
    }

    $dados = json_encode([
        'slots' => array_map(fn($k, $t) => ['key' => $k, 'team_id' => $t], array_keys($timePorSlot), array_values($timePorSlot)),
        'itens' => $limpos,
    ], JSON_UNESCAPED_UNICODE);

    try {
        $pdo->beginTransaction();

        if ($id) {
            $st = $pdo->prepare('SELECT id FROM trade_rascunhos WHERE id = ? AND team_id = ? FOR UPDATE');
            $st->execute([$id, $teamId]);
            if (!$st->fetchColumn()) { $pdo->rollBack(); return [false, null, 'Rascunho não encontrado.']; }
            $pdo->prepare('UPDATE trade_rascunhos SET dados = ?, notes = ?, league = ? WHERE id = ?')
                ->execute([$dados, $notes !== '' ? $notes : null, $league, $id]);
            $pdo->prepare('DELETE FROM trade_rascunho_itens WHERE rascunho_id = ?')->execute([$id]);
            $novoId = $id;
        } else {
            // O limite é por time, contado na hora de criar: cinco cenários
            // guardados já são mais do que alguém compara de cabeça.
            $st = $pdo->prepare('SELECT COUNT(*) FROM trade_rascunhos WHERE team_id = ?');
            $st->execute([$teamId]);
            if ((int)$st->fetchColumn() >= TRADE_RASCUNHO_MAX) {
                $pdo->rollBack();
                return [false, null, 'Você já tem ' . TRADE_RASCUNHO_MAX . ' rascunhos salvos. Apague um na aba Rascunhos pra guardar outro.'];
            }
            $pdo->prepare('INSERT INTO trade_rascunhos (team_id, league, dados, notes) VALUES (?, ?, ?, ?)')
                ->execute([$teamId, $league, $dados, $notes !== '' ? $notes : null]);
            $novoId = (int)$pdo->lastInsertId();
        }

        $stIns = $pdo->prepare('INSERT INTO trade_rascunho_itens
                                (rascunho_id, from_team_id, to_team_id, player_id, pick_id)
                                VALUES (?, ?, ?, ?, ?)');
        foreach ($limpos as $it) {
            $stIns->execute([
                $novoId, $timePorSlot[$it['de']], $timePorSlot[$it['para']],
                $it['tipo'] === 'player' ? $it['id'] : null,
                $it['tipo'] === 'pick'   ? $it['id'] : null,
            ]);
        }

        $pdo->commit();
        return [true, $novoId, ''];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[trade_rascunhos] salvar: ' . $e->getMessage());
        return [false, null, 'Erro ao salvar o rascunho.'];
    }
}

/** Apaga um rascunho do próprio time. */
function rascunhoApagar(PDO $pdo, int $teamId, int $id): bool
{
    rascunhoGarantirTabelas($pdo);
    try {
        $pdo->prepare('DELETE FROM trade_rascunho_itens WHERE rascunho_id IN
                       (SELECT id FROM trade_rascunhos WHERE id = ? AND team_id = ?)')
            ->execute([$id, $teamId]);
        $st = $pdo->prepare('DELETE FROM trade_rascunhos WHERE id = ? AND team_id = ?');
        $st->execute([$id, $teamId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('[trade_rascunhos] apagar: ' . $e->getMessage());
        return false;
    }
}

/** Quantos rascunhos o time tem — a aba na tela de trocas só existe se houver. */
function rascunhosQuantos(PDO $pdo, int $teamId): int
{
    rascunhoGarantirTabelas($pdo);
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM trade_rascunhos WHERE team_id = ?');
        $st->execute([$teamId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
