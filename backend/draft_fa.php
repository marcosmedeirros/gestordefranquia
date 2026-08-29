<?php
/**
 * Quem não foi escolhido no draft vira free agent.
 *
 * Antes o pool simplesmente parava: os não escolhidos ficavam `available` em
 * draft_pool para sempre, numa tabela que ninguém mais lê depois que o draft
 * fecha. Sessenta jogadores por classe desapareciam da liga sem nunca terem
 * chance de assinar com alguém.
 *
 * Agora eles seguem o mesmo caminho de quem é dispensado: 24 horas nas
 * DISPENSAS aceitando lance e, se ninguém quiser, free agency. Não é um
 * destino novo — é a mesma esteira, entrando pela porta de cima.
 *
 * draftSobrasParaFreeAgency() continua aqui porque manda direto pra free
 * agency, sem passar pelo waiver: é o que o admin usa pra reenviar sobra de
 * draft antigo, quando a janela de lance já não faz sentido.
 */

/** A coluna de overall muda de nome entre bases; descobre qual existe. */
function draftFaColunaOvr(PDO $pdo): string
{
    foreach (['overall', 'ovr'] as $c) {
        try {
            if ($pdo->query("SHOW COLUMNS FROM free_agents LIKE " . $pdo->quote($c))->fetch()) return $c;
        } catch (Throwable $e) { /* tenta a próxima */ }
    }
    return 'overall';
}

function draftFaTemColuna(PDO $pdo, string $tabela, string $coluna): bool
{
    static $cache = [];
    $k = "$tabela.$coluna";
    if (!array_key_exists($k, $cache)) {
        try {
            $cache[$k] = (bool)$pdo->query("SHOW COLUMNS FROM `$tabela` LIKE " . $pdo->quote($coluna))->fetch();
        } catch (Throwable $e) { $cache[$k] = false; }
    }
    return $cache[$k];
}

/**
 * Manda pra Free Agency todo mundo que sobrou no pool desta sessão.
 *
 * IDEMPOTENTE: cada jogador levado guarda em draft_pool.fa_id o id do free
 * agent que virou, e quem já tem fa_id não é reenviado. Sem isso, encerrar o
 * draft duas vezes (ou o mesmo encerramento passando por dois caminhos, que é
 * o que acontece hoje) duplicaria a classe inteira na FA.
 *
 * @return array{enviados:int, ja_estavam:int, liga:string}
 */
function draftSobrasParaFreeAgency(PDO $pdo, int $draftSessionId): array
{
    $saida = ['enviados' => 0, 'ja_estavam' => 0, 'liga' => ''];

    try {
        $st = $pdo->prepare('SELECT ds.league, ds.season_id FROM draft_sessions ds WHERE ds.id = ?');
        $st->execute([$draftSessionId]);
        $sessao = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sessao) return $saida;

        $liga = (string)$sessao['league'];
        $seasonId = (int)$sessao['season_id'];
        $saida['liga'] = $liga;
        if ($liga === '' || $seasonId <= 0) return $saida;

        // A marca de "já foi pra FA". Nasce aqui porque o pool é anterior a
        // esta regra e as bases existentes não têm a coluna.
        if (!draftFaTemColuna($pdo, 'draft_pool', 'fa_id')) {
            try { $pdo->exec('ALTER TABLE draft_pool ADD COLUMN fa_id INT NULL'); } catch (Throwable $e) { /* corrida */ }
        }

        /*
         * O season_id que vai na FA é o da TEMPORADA CORRENTE, não o da sessão
         * de draft.
         *
         * A tela de Free Agency lista quem tem `season_id` igual ao da
         * temporada que está rolando. Gravando a temporada da sessão, o
         * calouro entrava no banco e não aparecia pra ninguém — foi o que
         * aconteceu no primeiro teste: 55 na tabela, zero na tela.
         *
         * A regra é copiada de resolveCurrentSeason() em api/free-agency.php,
         * de propósito: as duas pontas precisam concordar sobre qual é a
         * temporada, ou o jogador some de novo.
         */
        $seasonDaFa = $seasonId;
        try {
            $q = $pdo->prepare("SELECT id FROM seasons WHERE league = ? AND status <> 'completed'
                                 ORDER BY year DESC, id DESC LIMIT 1");
            $q->execute([$liga]);
            $corrente = $q->fetchColumn();
            if ($corrente) $seasonDaFa = (int)$corrente;
        } catch (Throwable $e) { /* fica com a da sessão */ }

        $st = $pdo->prepare("SELECT id, name, age, position, secondary_position, ovr
                               FROM draft_pool
                              WHERE season_id = ? AND draft_status = 'available'");
        $st->execute([$seasonId]);
        $sobras = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$sobras) return $saida;

        $jaFoi = $pdo->prepare('SELECT fa_id FROM draft_pool WHERE id = ?');
        $marcar = $pdo->prepare('UPDATE draft_pool SET fa_id = ? WHERE id = ?');

        $colOvr = draftFaColunaOvr($pdo);
        foreach ($sobras as $j) {
            $jaFoi->execute([(int)$j['id']]);
            if ($jaFoi->fetchColumn()) { $saida['ja_estavam']++; continue; }

            $cols = ['name', 'age', 'position', $colOvr];
            $vals = [(string)$j['name'], (int)$j['age'], (string)$j['position'], (int)$j['ovr']];

            if (draftFaTemColuna($pdo, 'free_agents', 'secondary_position')) {
                $cols[] = 'secondary_position';
                $vals[] = $j['secondary_position'] ?: null;
            }
            if (draftFaTemColuna($pdo, 'free_agents', 'league')) {
                $cols[] = 'league';
                $vals[] = $liga;
            }
            if (draftFaTemColuna($pdo, 'free_agents', 'season_id')) {
                $cols[] = 'season_id';
                $vals[] = $seasonDaFa;
            }
            // Calouro não vem de time nenhum: original_team_* ficam nulos, e é
            // por eles que a tela sabe não escrever "dispensado por".
            if (draftFaTemColuna($pdo, 'free_agents', 'status')) {
                $cols[] = 'status';
                $vals[] = 'available';
            }

            $ph = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare('INSERT INTO free_agents (' . implode(',', $cols) . ") VALUES ($ph)")->execute($vals);

            $marcar->execute([(int)$pdo->lastInsertId(), (int)$j['id']]);
            $saida['enviados']++;
        }
    } catch (Throwable $e) {
        // Nunca derruba o encerramento do draft: o draft fechar é o que
        // importa, e a sobra pode ser reenviada depois — a função é idempotente.
        error_log('[draftSobrasParaFreeAgency] ' . $e->getMessage());
    }

    return $saida;
}

/**
 * Encerra a sessão e manda as sobras pra FA, nesta ordem.
 *
 * Existe pra que os cinco caminhos que encerram um draft (prazo da 2ª rodada,
 * última vaga preenchida, botão do admin, e os dois automáticos) não precisem
 * lembrar da segunda metade. Antes cada um só fazia o UPDATE.
 */
/** Quanto tempo a sobra do draft fica aceitando lance antes de virar free agent. */
const DRAFT_SOBRA_WAIVER_HORAS = 24;

/**
 * A sobra do draft passa pelas DISPENSAS antes da free agency.
 *
 * Antes ela caía direto na free agency. O caminho certo é o mesmo do jogador
 * dispensado: 24 horas no waiver, onde a liga dá lance e o maior espaço de
 * cap leva; quem ninguém quiser, aí sim vira free agent — e isso já acontece
 * sozinho, porque resolveExpiredWaivers() manda pra free agency todo waiver
 * que vence sem lance. Não precisou de rota nova: só entrar pela porta certa.
 *
 * `team_id = 0` porque calouro não vem de time nenhum. É o mesmo que a free
 * agency já fazia com `original_team_*` nulo, e é por ele que a tela sabe não
 * escrever "dispensado por".
 *
 * Idempotente pela marca `draft_pool.waiver_id`, igual a `fa_id` fazia: rodar
 * duas vezes não duplica ninguém.
 */
function draftSobrasParaWaiver(PDO $pdo, int $draftSessionId): array
{
    $saida = ['enviados' => 0, 'ja_estavam' => 0, 'liga' => ''];

    try {
        require_once __DIR__ . '/waivers.php';

        $st = $pdo->prepare('SELECT league, season_id FROM draft_sessions WHERE id = ?');
        $st->execute([$draftSessionId]);
        $sessao = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sessao) return $saida;

        $liga = (string)$sessao['league'];
        $seasonId = (int)$sessao['season_id'];
        $saida['liga'] = $liga;
        if ($liga === '' || $seasonId <= 0) return $saida;

        if (!draftFaTemColuna($pdo, 'draft_pool', 'waiver_id')) {
            try { $pdo->exec('ALTER TABLE draft_pool ADD COLUMN waiver_id INT NULL'); } catch (Throwable $e) { /* corrida */ }
        }

        $st = $pdo->prepare("SELECT id, name, age, position, secondary_position, ovr
                               FROM draft_pool
                              WHERE season_id = ? AND draft_status = 'available'
                                AND (waiver_id IS NULL)");
        $st->execute([$seasonId]);
        $sobras = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$sobras) return $saida;

        $marcar = $pdo->prepare('UPDATE draft_pool SET waiver_id = ? WHERE id = ?');
        foreach ($sobras as $j) {
            $wid = enterWaiver($pdo, [
                'id'                 => null,   // não existe em `players`: nunca teve time
                'team_id'            => 0,
                'name'               => (string)$j['name'],
                'age'                => (int)$j['age'],
                'position'           => (string)$j['position'],
                'secondary_position' => $j['secondary_position'] ?: null,
                'ovr'                => (int)$j['ovr'],
                'seasons_in_league'  => 0,
                'role'               => 'Banco',
            ], $liga, DRAFT_SOBRA_WAIVER_HORAS);
            $marcar->execute([$wid, (int)$j['id']]);
            $saida['enviados']++;
        }
    } catch (Throwable $e) {
        // Nunca derruba o encerramento do draft — a sobra pode ser reenviada
        // depois, que a função é idempotente.
        error_log('[draftSobrasParaWaiver] ' . $e->getMessage());
    }

    return $saida;
}

function draftEncerrarSessao(PDO $pdo, int $draftSessionId): array
{
    $pdo->prepare('UPDATE draft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')
        ->execute([$draftSessionId]);
    return draftSobrasParaWaiver($pdo, $draftSessionId);
}
