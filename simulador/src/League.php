<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SimEngine.php';

/**
 * Regras da liga: classificação, avanço de dias, estatísticas, playoffs.
 */
class League
{
    public static function phase(): string { return Database::meta('phase', 'regular'); }
    public static function currentDay(): int { return (int) Database::meta('current_day', 1); }
    public static function totalDays(): int { return (int) Database::meta('total_days', 0); }
    public static function season(): int { return (int) Database::meta('season', 1); }

    public static function team(int $id): ?array
    {
        $st = Database::conn()->prepare("SELECT * FROM teams WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function teamByAbbr(string $abbr): ?array
    {
        $st = Database::conn()->prepare("SELECT * FROM teams WHERE abbr = ?");
        $st->execute([$abbr]);
        return $st->fetch() ?: null;
    }

    public static function allTeams(): array
    {
        return Database::conn()->query("SELECT * FROM teams WHERE active=1 ORDER BY conf, `div`, name")->fetchAll();
    }

    /** Classificação por conferência (E/W) ou geral (null). */
    public static function standings(?string $conf = null): array
    {
        $sql = "SELECT * FROM teams WHERE active = 1";
        $params = [];
        if ($conf) { $sql .= " AND conf = ?"; $params[] = $conf; }
        $st = Database::conn()->prepare($sql);
        $st->execute($params);
        $teams = $st->fetchAll();
        usort($teams, function ($a, $b) {
            $pa = self::pct($a); $pb = self::pct($b);
            if ($pa === $pb) return $b['wins'] <=> $a['wins'];
            return $pb <=> $pa;
        });
        $seed = 1;
        foreach ($teams as &$t) {
            $t['pct'] = self::pct($t);
            $t['seed'] = $seed++;
        }
        return $teams;
    }

    private static function pct(array $t): float
    {
        $g = $t['wins'] + $t['losses'];
        return $g ? round($t['wins'] / $g, 3) : 0.0;
    }

    public static function roster(int $teamId): array
    {
        $st = Database::conn()->prepare(
            "SELECT p.*, s.gp, s.pts AS s_pts, s.reb AS s_reb, s.ast AS s_ast
             FROM players p LEFT JOIN season_stats s ON s.player_id = p.id
             WHERE p.team_id = ? ORDER BY p.is_starter DESC, p.ovr DESC");
        $st->execute([$teamId]);
        return $st->fetchAll();
    }

    /** Busca jogadores por nome (parcial) em todos os times. */
    public static function searchPlayers(string $q): array
    {
        $st = Database::conn()->prepare(
            "SELECT p.*, t.abbr, t.primary_color, t.secondary_color,
                    COALESCE(s.gp,0) AS gp,
                    COALESCE(s.pts,0) AS s_pts, COALESCE(s.reb,0) AS s_reb,
                    COALESCE(s.ast,0) AS s_ast
             FROM players p
             JOIN teams t ON t.id = p.team_id
             LEFT JOIN season_stats s ON s.player_id = p.id
             WHERE p.retired = 0 AND p.name LIKE ?
             ORDER BY p.ovr DESC LIMIT 40");
        $st->execute(['%' . $q . '%']);
        return $st->fetchAll();
    }

    /** Elenco com TODAS as estatísticas da temporada (pts, reb, ast, stl, blk, fgm, fga, tpm, tpa, ftm, fta). */
    public static function rosterFull(int $teamId): array
    {
        $st = Database::conn()->prepare(
            "SELECT p.*,
                    COALESCE(s.gp,0)  AS gp,
                    COALESCE(s.pts,0) AS s_pts,
                    COALESCE(s.reb,0) AS s_reb,
                    COALESCE(s.ast,0) AS s_ast,
                    COALESCE(s.stl,0) AS s_stl,
                    COALESCE(s.blk,0) AS s_blk,
                    COALESCE(s.tov,0) AS s_tov,
                    COALESCE(s.fgm,0) AS s_fgm,
                    COALESCE(s.fga,0) AS s_fga,
                    COALESCE(s.tpm,0) AS s_tpm,
                    COALESCE(s.tpa,0) AS s_tpa,
                    COALESCE(s.ftm,0) AS s_ftm,
                    COALESCE(s.fta,0) AS s_fta
             FROM players p LEFT JOIN season_stats s ON s.player_id = p.id
             WHERE p.team_id = ? AND p.retired = 0
             ORDER BY p.is_starter DESC, p.ovr DESC");
        $st->execute([$teamId]);
        return $st->fetchAll();
    }

    public static function player(int $id): ?array
    {
        $st = Database::conn()->prepare(
            "SELECT p.*, t.abbr, t.city, t.name AS team_name,
                    t.primary_color, t.secondary_color, s.*
             FROM players p JOIN teams t ON t.id = p.team_id
             LEFT JOIN season_stats s ON s.player_id = p.id WHERE p.id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Corrida pelos prêmios individuais durante a temporada regular.
     * Retorna top-5 candidatos para MVP, DPOY e ROY.
     */
    public static function awardRace(): array
    {
        $db = Database::conn();
        // Precisa de ao menos 5 jogos para entrar na corrida
        $mvp = $db->query(
            "SELECT p.id, p.name, t.abbr,
                    s.gp,
                    ROUND(s.pts  * 1.0 / MAX(s.gp,1), 1) AS ppg,
                    ROUND(s.reb  * 1.0 / MAX(s.gp,1), 1) AS rpg,
                    ROUND(s.ast  * 1.0 / MAX(s.gp,1), 1) AS apg,
                    ROUND((s.pts * 1.0 / MAX(s.gp,1))
                        + (s.reb * 1.0 / MAX(s.gp,1)) * 0.70
                        + (s.ast * 1.0 / MAX(s.gp,1)) * 0.80, 2) AS score
             FROM season_stats s
             JOIN players p ON p.id = s.player_id
             JOIN teams t ON t.id = p.team_id
             WHERE s.gp >= 5 AND p.team_id IS NOT NULL
             ORDER BY score DESC LIMIT 5"
        )->fetchAll();

        $dpoy = $db->query(
            "SELECT p.id, p.name, t.abbr,
                    s.gp,
                    ROUND((s.blk + s.stl) * 1.0 / MAX(s.gp,1), 2) AS score,
                    ROUND(s.blk * 1.0 / MAX(s.gp,1), 1) AS bpg,
                    ROUND(s.stl * 1.0 / MAX(s.gp,1), 1) AS spg
             FROM season_stats s
             JOIN players p ON p.id = s.player_id
             JOIN teams t ON t.id = p.team_id
             WHERE s.gp >= 5 AND p.team_id IS NOT NULL
             ORDER BY score DESC LIMIT 5"
        )->fetchAll();

        $roy = $db->query(
            "SELECT p.id, p.name, t.abbr,
                    s.gp,
                    ROUND(s.pts * 1.0 / MAX(s.gp,1), 1) AS ppg,
                    ROUND(s.pts  * 1.0 / MAX(s.gp,1)
                        + s.reb  * 1.0 / MAX(s.gp,1) * 0.60
                        + s.ast  * 1.0 / MAX(s.gp,1) * 0.70, 2) AS score
             FROM season_stats s
             JOIN players p ON p.id = s.player_id
             JOIN teams t ON t.id = p.team_id
             WHERE s.gp >= 3 AND p.team_id IS NOT NULL AND p.seasons_pro = 0
             ORDER BY score DESC LIMIT 5"
        )->fetchAll();

        return ['mvp' => $mvp, 'dpoy' => $dpoy, 'roy' => $roy];
    }

    public static function gamesByDay(int $day): array
    {
        $st = Database::conn()->prepare(
            "SELECT g.*, ht.abbr AS home_abbr, ht.city AS home_city, ht.name AS home_name,
                    at.abbr AS away_abbr, at.city AS away_city, at.name AS away_name,
                    ht.primary_color AS home_color, at.primary_color AS away_color
             FROM games g
             JOIN teams ht ON ht.id = g.home_id
             JOIN teams at ON at.id = g.away_id
             WHERE g.day = ? ORDER BY g.id");
        $st->execute([$day]);
        return $st->fetchAll();
    }

    public static function game(int $id): ?array
    {
        $st = Database::conn()->prepare(
            "SELECT g.*, ht.abbr AS home_abbr, ht.city AS home_city, ht.name AS home_name,
                    at.abbr AS away_abbr, at.city AS away_city, at.name AS away_name,
                    ht.primary_color AS home_color, at.primary_color AS away_color
             FROM games g
             JOIN teams ht ON ht.id = g.home_id
             JOIN teams at ON at.id = g.away_id
             WHERE g.id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function boxScore(int $gameId): array
    {
        $st = Database::conn()->prepare(
            "SELECT b.*, p.name, p.pos, p.team_id
             FROM box_scores b JOIN players p ON p.id = b.player_id
             WHERE b.game_id = ? ORDER BY b.pts DESC");
        $st->execute([$gameId]);
        return $st->fetchAll();
    }

    /** Simula um jogo, persiste resultado, box score e estatísticas. */
    public static function simulateGame(int $gameId): array
    {
        $db = Database::conn();
        $g = self::game($gameId);
        if (!$g || $g['played']) return $g ?: [];

        $result = SimEngine::simulate((int) $g['home_id'], (int) $g['away_id']);
        self::persistResult($g, $result);
        return self::game($gameId);
    }

    /**
     * Persiste o resultado de um jogo (placar, box score, estatísticas, lesões, vitórias/derrotas,
     * sequência/química, atualização de série de playoffs e manchetes). Usado pela simulação
     * automática e pela finalização de um jogo comandado ao vivo.
     */
    public static function persistResult(array $g, array $result): void
    {
        $db = Database::conn();
        $gameId = (int) $g['id'];

        $db->beginTransaction();

        $upd = $db->prepare("UPDATE games SET home_pts=?, away_pts=?, played=1, ot=?, pbp=? WHERE id=?");
        $upd->execute([$result['home_pts'], $result['away_pts'], $result['ot'],
            json_encode($result['pbp'], JSON_UNESCAPED_UNICODE), $gameId]);

        $insBox = $db->prepare("INSERT INTO box_scores
            (game_id,team_id,player_id,min,pts,reb,ast,stl,blk,tov,fgm,fga,tpm,tpa,ftm,fta)
            VALUES (:g,:t,:p,:min,:pts,:reb,:ast,:stl,:blk,:tov,:fgm,:fga,:tpm,:tpa,:ftm,:fta)");
        $updStat = $db->prepare("UPDATE season_stats SET
            gp=gp+1, min=min+:min, pts=pts+:pts, reb=reb+:reb, ast=ast+:ast, stl=stl+:stl,
            blk=blk+:blk, tov=tov+:tov, fgm=fgm+:fgm, fga=fga+:fga, tpm=tpm+:tpm, tpa=tpa+:tpa,
            ftm=ftm+:ftm, fta=fta+:fta WHERE player_id=:p");

        foreach ($result['box'] as $line) {
            if ($line['min'] <= 0) continue;
            $insBox->execute([
                ':g'=>$gameId, ':t'=>$line['team_id'], ':p'=>$line['player_id'], ':min'=>$line['min'],
                ':pts'=>$line['pts'], ':reb'=>$line['reb'], ':ast'=>$line['ast'], ':stl'=>$line['stl'],
                ':blk'=>$line['blk'], ':tov'=>$line['tov'], ':fgm'=>$line['fgm'], ':fga'=>$line['fga'],
                ':tpm'=>$line['tpm'], ':tpa'=>$line['tpa'], ':ftm'=>$line['ftm'], ':fta'=>$line['fta'],
            ]);
            $updStat->execute([
                ':min'=>$line['min'], ':pts'=>$line['pts'], ':reb'=>$line['reb'], ':ast'=>$line['ast'],
                ':stl'=>$line['stl'], ':blk'=>$line['blk'], ':tov'=>$line['tov'], ':fgm'=>$line['fgm'],
                ':fga'=>$line['fga'], ':tpm'=>$line['tpm'], ':tpa'=>$line['tpa'], ':ftm'=>$line['ftm'],
                ':fta'=>$line['fta'], ':p'=>$line['player_id'],
            ]);
        }

        // --- lesões: primeiro reduz lesões vigentes dos times em quadra, depois aplica novas ---
        $db->prepare("UPDATE players SET injury_games = injury_games - 1
                      WHERE team_id IN (?,?) AND injury_games > 0")->execute([$g['home_id'], $g['away_id']]);
        // jogadores poupados voltam: consome 1 jogo de descanso
        $db->prepare("UPDATE players SET rest_games = rest_games - 1
                      WHERE team_id IN (?,?) AND rest_games > 0")->execute([$g['home_id'], $g['away_id']]);
        $db->prepare("UPDATE players SET injury_desc = NULL
                      WHERE team_id IN (?,?) AND injury_games = 0")->execute([$g['home_id'], $g['away_id']]);
        if (!empty($result['injuries'])) {
            $setInj = $db->prepare("UPDATE players SET injury_games=?, injury_desc=? WHERE id=?");
            foreach ($result['injuries'] as $inj) {
                $setInj->execute([$inj['games'], 'Lesionado', $inj['player_id']]);
            }
        }

        // atualiza vitórias/derrotas, sequência e química (apenas temporada regular afeta a tabela)
        $homeWon = $result['home_pts'] > $result['away_pts'];
        $winId = $homeWon ? (int) $g['home_id'] : (int) $g['away_id'];
        $loseId = $homeWon ? (int) $g['away_id'] : (int) $g['home_id'];
        if ($g['stage'] === 'regular') {
            $db->prepare("UPDATE teams SET wins=wins+1 WHERE id=?")->execute([$winId]);
            $db->prepare("UPDATE teams SET losses=losses+1 WHERE id=?")->execute([$loseId]);
        }
        // sequência: positivo = vitórias seguidas, negativo = derrotas
        self::updateStreakChem($winId, true);
        self::updateStreakChem($loseId, false);

        $db->commit();

        // se for jogo de playoffs, atualiza a série
        if ($g['stage'] === 'playoffs' && $g['series_id']) {
            self::updateSeries((int) $g['series_id'], (int) ($homeWon ? $g['home_id'] : $g['away_id']));
        }

        self::generateGameHeadlines($g, $result, $winId, $loseId);

        if (!empty($result['injuries'])) {
            foreach ($result['injuries'] as $inj) {
                self::announceInjury((int) $inj['player_id'], (int) $inj['games']);
            }
        }
    }

    /** Avisa o GM na caixa de entrada quando um jogador DO SEU TIME se lesiona. */
    private static function announceInjury(int $playerId, int $games): void
    {
        $gm = self::gmTeam();
        if (!$gm) return;
        $p = self::player($playerId);
        if (!$p || (int) ($p['team_id'] ?? 0) !== $gm) return;
        self::inboxAdd('injury', 'Dept. Médico', $p['name'] . ' lesionado',
            "{$p['name']} ({$p['pos']}) sofreu uma lesão e ficará fora por aproximadamente {$games} jogo(s). Ajuste a rotação na Escalação.",
            url('lineup'), '🏥', true, $playerId);
    }

    /**
     * Finaliza um jogo comandado ao vivo: persiste o resultado vindo do SimEngine e
     * devolve o jogo atualizado. Protege contra dupla contagem (jogo já jogado).
     */
    public static function finishLiveGame(int $gameId, array $result): ?array
    {
        $g = self::game($gameId);
        if (!$g || $g['played']) return $g;
        self::persistResult($g, $result);
        return self::game($gameId);
    }

    public static function addHeadline(int $season, int $day, string $type, string $text, ?int $teamId = null): void
    {
        Database::conn()->prepare("INSERT INTO headlines(season,day,type,team_id,text) VALUES(?,?,?,?,?)")
            ->execute([$season, $day, $type, $teamId, $text]);
    }

    private static function generateGameHeadlines(array $g, array $result, int $winId, int $loseId): void
    {
        $season = self::season();
        $day = (int) $g['day'];
        // maior pontuador do jogo
        $top = null;
        foreach ($result['box'] as $line) {
            if ($top === null || $line['pts'] > $top['pts']) $top = $line;
        }
        if ($top && $top['pts'] >= 40) {
            $pl = self::player((int) $top['player_id']);
            if ($pl) {
                self::addHeadline($season, $day, 'big_game',
                    "🔥 {$pl['name']} ({$pl['abbr']}) explode com {$top['pts']} pontos!", (int) $pl['team_id']);
            }
        }
        // goleada
        $margin = abs((int) $result['home_pts'] - (int) $result['away_pts']);
        if ($margin >= 28) {
            $w = self::team($winId);
            self::addHeadline($season, $day, 'blowout',
                "💥 {$w['city']} {$w['name']} atropela por {$margin} pontos de diferença.", $winId);
        }
        // sequência de vitórias
        $wt = self::team($winId);
        $streak = (int) ($wt['streak'] ?? 0);
        if ($streak == 8 || ($streak >= 10 && $streak % 3 == 0)) {
            self::addHeadline($season, $day, 'streak',
                "📈 {$wt['city']} {$wt['name']} emenda {$streak} vitórias seguidas!", $winId);
        }
        // lesão de jogador importante
        foreach (($result['injuries'] ?? []) as $inj) {
            $pl = self::player((int) $inj['player_id']);
            if ($pl && (int) $pl['ovr'] >= 82 && $inj['games'] >= 8) {
                self::addHeadline($season, $day, 'injury',
                    "🩹 Baixa importante: {$pl['name']} ({$pl['abbr']}) fora por ~{$inj['games']} jogos.", (int) $pl['team_id']);
            }
        }
    }

    public static function headlines(int $limit = 12, ?int $season = null): array
    {
        $limit = max(1, (int) $limit);
        if ($season !== null) {
            $st = Database::conn()->prepare("SELECT * FROM headlines WHERE season=? ORDER BY id DESC LIMIT $limit");
            $st->execute([$season]);
        } else {
            $st = Database::conn()->prepare("SELECT * FROM headlines ORDER BY id DESC LIMIT $limit");
            $st->execute();
        }
        return $st->fetchAll();
    }

    /** Atualiza a sequência (streak) e ajusta levemente a química do time. */
    private static function updateStreakChem(int $teamId, bool $won): void
    {
        $db = Database::conn();
        $t = $db->prepare("SELECT streak, chemistry FROM teams WHERE id=?");
        $t->execute([$teamId]);
        $row = $t->fetch();
        $streak = (int) ($row['streak'] ?? 0);
        $chem = (int) ($row['chemistry'] ?? 70);
        if ($won) {
            $streak = $streak >= 0 ? $streak + 1 : 1;
            if ($streak >= 3) $chem = min(99, $chem + 1);
        } else {
            $streak = $streak <= 0 ? $streak - 1 : -1;
            if ($streak <= -3) $chem = max(50, $chem - 1);
        }
        $db->prepare("UPDATE teams SET streak=?, chemistry=? WHERE id=?")->execute([$streak, $chem, $teamId]);
    }

    /** O jogo agendado para o time do GM num dia (jogado ou não), ou null. */
    public static function gmGameOnDay(int $day): ?array
    {
        $gm = self::gmTeam();
        if (!$gm) return null;
        foreach (self::gamesByDay($day) as $g) {
            if ((int) $g['home_id'] === $gm || (int) $g['away_id'] === $gm) return $g;
        }
        return null;
    }

    /**
     * Ação central única do GM conforme a fase atual — o botão "Avançar" estilo
     * FM. Usada pela home E pela tela de resumo (recap) para ficarem sempre
     * em sincronia: qualquer uma delas sempre aponta para o próximo passo certo.
     */
    public static function nextAction(): ?array
    {
        $phase = self::phase();
        $day = self::currentDay();
        $gmId = self::gmTeam();
        $gmToday = null;
        if ($gmId && in_array($phase, ['regular', 'playin', 'playoffs'], true)) {
            $gg = self::gmGameOnDay($day);
            if ($gg && !$gg['played']) $gmToday = $gg;
        }

        if ($gmToday) {
            return [
                'href' => url('game', ['id' => $gmToday['id'], 'live' => 1]),
                'label' => '🎮 Comandar meu jogo · ' . $gmToday['away_abbr'] . ' @ ' . $gmToday['home_abbr'],
                'note' => 'Seu jogo de hoje — ' . self::dateLabel($day),
                'alt' => ['href' => url('home', ['action' => 'sim-game-ai', 'id' => $gmToday['id']]),
                          'label' => 'ou simular sem comandar', 'confirm' => 'Simular sua partida sem comandar?'],
            ];
        }
        if (in_array($phase, ['regular', 'playin', 'playoffs'], true)) {
            return [
                'href' => url('home', ['action' => 'advance', 'back' => url('home')]),
                'label' => '▶ Avançar para a próxima data',
                'note' => ($phase === 'playoffs' ? 'Playoffs' : ($phase === 'playin' ? 'Play-In' : 'Temporada Regular')) . ' — ' . self::dateLabel($day),
            ];
        }
        if ($phase === 'preseason') {
            return [
                'href' => url('home', ['action' => 'preseason-advance']),
                'label' => '▶ Avançar dia · ' . self::preseasonDay() . '/' . self::PRESEASON_DAYS . ' da pré-temporada',
                'note' => 'Trocas, free agency e acontecimentos antes do início da temporada',
            ];
        }
        if ($phase === 'lottery') {
            return ['href' => url('lottery'), 'label' => '🎰 Ir para a Loteria do Draft', 'note' => 'Sorteio das posições do Draft'];
        }
        if ($phase === 'draft') {
            return ['href' => url('draftroom'), 'label' => '🎓 Ir para a Sala do Draft', 'note' => 'Faça suas escolhas'];
        }
        if ($phase === 'freeagency') {
            return ['href' => url('freeagency'), 'label' => '🖊️ Ir para a Free Agency', 'note' => 'Contrate agentes livres'];
        }
        if ($phase === 'offseason') {
            return ['href' => url('home', ['action' => 'next-season']), 'label' => '🏁 Iniciar próxima temporada',
                    'note' => 'Entressafra: progressão, loteria, draft e free agency',
                    'confirm' => 'Rodar a entressafra e iniciar a próxima temporada?'];
        }
        return null;
    }

    /** Marca d'água (maior id da inbox) para depois capturar exatamente o que aconteceu num avanço. */
    public static function inboxWatermark(): int
    {
        try { return (int) Database::conn()->query("SELECT COALESCE(MAX(id),0) FROM inbox")->fetchColumn(); }
        catch (Throwable $e) { return 0; }
    }

    /** Mensagens criadas depois de uma marca d'água (usado pela tela de resumo/recap). */
    public static function inboxSince(int $watermark): array
    {
        try {
            $st = Database::conn()->prepare("SELECT * FROM inbox WHERE id > ? ORDER BY id ASC");
            $st->execute([$watermark]);
            return $st->fetchAll();
        } catch (Throwable $e) { return []; }
    }

    /**
     * Avança um dia simulando os jogos pendentes. No modo GM (e com $autoGm = false),
     * se o time do GM tem jogo ainda não jogado no dia atual, os DEMAIS jogos do dia são
     * simulados e o avanço PARA — o GM precisa comandar (ou simular) a própria partida
     * antes de seguir o calendário. Com $autoGm = true, simula tudo (usado em "Simular temporada").
     */
    public static function advanceDay(bool $autoGm = false): array
    {
        $phase = self::phase();
        if ($phase === 'playin') return self::advancePlayInDay($autoGm);
        if ($phase === 'playoffs') return self::advancePlayoffDay($autoGm);
        if ($phase === 'offseason') return ['msg' => 'Temporada encerrada. Inicie a próxima temporada.'];
        if ($phase === 'lottery') return ['msg' => 'Loteria do Draft — faça o sorteio das posições antes do Draft.'];
        if ($phase === 'draft') return ['msg' => 'Draft em andamento — faça suas escolhas na sala do Draft.'];
        if ($phase === 'freeagency') return ['msg' => 'Free Agency em andamento — contrate agentes livres antes de iniciar a temporada.'];

        $day = self::currentDay();
        $gm = self::gmTeam();

        // pode surgir uma decisão de inbox para o GM
        if ($gm && !$autoGm) self::maybeGenerateDecision();

        // jogo do GM ainda não jogado hoje?
        $pendingId = 0;
        if ($gm && !$autoGm) {
            $g = self::gmGameOnDay($day);
            if ($g && !$g['played']) $pendingId = (int) $g['id'];
        }

        // simula os jogos pendentes do dia (exceto o do GM, se ele for comandar)
        $played = 0;
        foreach (self::gamesByDay($day) as $g) {
            if ($g['played']) continue;
            if ($pendingId && (int) $g['id'] === $pendingId) continue;
            self::simulateGame((int) $g['id']); $played++;
        }

        // segura o calendário no jogo do GM
        if ($pendingId) {
            return ['msg' => 'Você tem jogo hoje! Comande ou simule a sua partida para seguir.',
                'gm_game_pending' => true, 'game_id' => $pendingId, 'played' => $played];
        }

        if ($day >= self::totalDays()) {
            self::startPlayIn();
            return ['msg' => "Temporada regular encerrada! Torneio Play-In iniciado.", 'played' => $played, 'phase' => 'playin'];
        }
        Database::setMeta('current_day', $day + 1);
        return ['msg' => "Dia $day simulado ($played jogos).", 'played' => $played, 'day' => $day + 1];
    }

    // ===================== CALENDÁRIO (datas) =====================

    /** Data (timestamp) de um dia da temporada: distribui ~82 jogos de fins de outubro a meados de abril. */
    public static function dateForDay(int $day, ?int $season = null): int
    {
        $season = $season ?? self::season();
        $total = max(1, self::totalDays() ?: Installer::targetDays());
        // início ~ 22 de outubro; janela de ~174 dias para a temporada regular.
        // era_start = ano da primavera/Draft da temporada 1 (ex.: 2026 = 2025-26); Out = era_start-1.
        $eraStart = (int) Database::meta('era_start', 2026);
        $startYear = ($eraStart - 1) + ($season - 1);
        $start = mktime(0, 0, 0, 10, 22, $startYear);
        $span = 174;
        $offset = (int) round(($day - 1) / max(1, $total - 1) * $span);
        return strtotime("+{$offset} days", $start);
    }

    /** Data formatada em pt-BR (ex.: "Sáb, 25 Out"). */
    public static function dateLabel(int $day, ?int $season = null): string
    {
        static $dow = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
        static $mon = ['', 'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        $ts = self::dateForDay($day, $season);
        return $dow[(int) date('w', $ts)] . ', ' . (int) date('j', $ts) . ' ' . $mon[(int) date('n', $ts)];
    }

    public static function simulateToEnd(): array
    {
        $count = 0;
        while (self::phase() === 'regular') { self::advanceDay(true); $count++; if ($count > 200) break; }
        return ['msg' => "Temporada regular simulada.", 'phase' => self::phase()];
    }

    // ===================== PLAY-IN (7º ao 10º) =====================

    public static function startPlayIn(): void
    {
        Database::setMeta('phase', 'playin');
        Database::setMeta('playin_stage', '1');
        $day = self::currentDay() + 1;
        $map = [];
        foreach (['E', 'W'] as $conf) {
            $s = self::standings($conf);
            $A = self::createPlayInGame($day, $s[6]['id'], $s[7]['id']); // 7 vs 8 (vencedor = seed 7)
            $B = self::createPlayInGame($day, $s[8]['id'], $s[9]['id']); // 9 vs 10 (perdedor eliminado)
            $map[$conf] = ['A' => $A, 'B' => $B, 'C' => null];
        }
        Database::setMeta('playin_map', json_encode($map));
        Database::setMeta('current_day', $day);
    }

    private static function createPlayInGame(int $day, int $home, int $away): int
    {
        $ins = Database::conn()->prepare("INSERT INTO games(day,stage,home_id,away_id) VALUES(?,'playin',?,?)");
        $ins->execute([$day, $home, $away]);
        return (int) Database::conn()->lastInsertId();
    }

    private static function gameWinnerLoser(int $gameId): array
    {
        $g = self::game($gameId);
        $homeWon = $g['home_pts'] > $g['away_pts'];
        return [
            'winner' => (int) ($homeWon ? $g['home_id'] : $g['away_id']),
            'loser'  => (int) ($homeWon ? $g['away_id'] : $g['home_id']),
        ];
    }

    public static function advancePlayInDay(bool $autoGm = false): array
    {
        $day = self::currentDay();
        // trava no jogo do GM (play-in) para que ele comande
        $pendingId = 0;
        if (!$autoGm) { $g = self::gmGameOnDay($day); if ($g && !$g['played']) $pendingId = (int) $g['id']; }
        foreach (self::gamesByDay($day) as $g) {
            if ($g['played'] || (int) $g['id'] === $pendingId) continue;
            self::simulateGame((int) $g['id']);
        }
        if ($pendingId) return ['msg' => 'Você tem jogo de Play-In hoje! Comande ou simule a partida.',
            'gm_game_pending' => true, 'game_id' => $pendingId, 'phase' => 'playin'];
        $stage = (int) Database::meta('playin_stage', 1);
        $map = json_decode(Database::meta('playin_map', '{}'), true);

        if ($stage === 1) {
            // cria o jogo C de cada conferência: perdedor(A) vs vencedor(B) -> vale o seed 8
            $newDay = $day + 1;
            foreach (['E', 'W'] as $conf) {
                $a = self::gameWinnerLoser($map[$conf]['A']);
                $b = self::gameWinnerLoser($map[$conf]['B']);
                $map[$conf]['C'] = self::createPlayInGame($newDay, $a['loser'], $b['winner']);
            }
            Database::setMeta('playin_map', json_encode($map));
            Database::setMeta('playin_stage', '2');
            Database::setMeta('current_day', $newDay);
            return ['msg' => 'Play-In: jogos decisivos pelo 8º lugar definidos.', 'phase' => 'playin'];
        }

        // stage 2 concluído -> define seeds 7 e 8 e inicia os playoffs
        $playinSeeds = [];
        foreach (['E', 'W'] as $conf) {
            $a = self::gameWinnerLoser($map[$conf]['A']);
            $c = self::gameWinnerLoser($map[$conf]['C']);
            $playinSeeds[$conf] = [7 => $a['winner'], 8 => $c['winner']];
        }
        Database::setMeta('playin_seeds', json_encode($playinSeeds));
        self::startPlayoffs();
        return ['msg' => 'Play-In encerrado! Playoffs iniciados.', 'phase' => 'playoffs'];
    }

    /** Sementes finais 1–8 de uma conferência (1–6 da tabela + 7/8 do play-in, se houver). */
    private static function finalSeeds(string $conf): array
    {
        $stand = self::standings($conf);
        $playin = json_decode(Database::meta('playin_seeds', '{}'), true);
        $seeds = [];
        for ($i = 0; $i < 6; $i++) { $stand[$i]['seed'] = $i + 1; $seeds[] = $stand[$i]; }
        if (isset($playin[$conf])) {
            $t7 = self::team($playin[$conf][7]); $t7['seed'] = 7; $seeds[] = $t7;
            $t8 = self::team($playin[$conf][8]); $t8['seed'] = 8; $seeds[] = $t8;
        } else { // fallback sem play-in
            $stand[6]['seed'] = 7; $seeds[] = $stand[6];
            $stand[7]['seed'] = 8; $seeds[] = $stand[7];
        }
        return $seeds;
    }

    // ===================== PLAYOFFS =====================

    public static function startPlayoffs(): void
    {
        Database::setMeta('phase', 'playoffs');
        Database::setMeta('playoff_round', '1');
        foreach (['E', 'W'] as $conf) {
            $seeds = self::finalSeeds($conf); // índices 0..7 = seeds 1..8
            // 1x8, 2x7, 3x6, 4x5
            $pairs = [[0, 7], [3, 4], [2, 5], [1, 6]];
            foreach ($pairs as $pair) {
                self::createSeries(1, $conf, $seeds[$pair[0]], $seeds[$pair[1]]);
            }
        }
        self::scheduleSeriesGames(1);
    }

    private static function createSeries(int $round, string $conf, array $high, array $low): int
    {
        $st = Database::conn()->prepare(
            "INSERT INTO playoff_series(round,conf,high_seed_id,low_seed_id,high_seed,low_seed,label)
             VALUES(?,?,?,?,?,?,?)");
        $label = "{$high['abbr']} vs {$low['abbr']}";
        $st->execute([$round, $conf, $high['id'], $low['id'], $high['seed'], $low['seed'], $label]);
        return (int) Database::conn()->lastInsertId();
    }

    /** Cria os jogos do próximo "dia de playoffs": um jogo por série ainda viva. */
    private static function scheduleSeriesGames(int $round): void
    {
        $db = Database::conn();
        $series = $db->prepare("SELECT * FROM playoff_series WHERE round = ? AND winner_id IS NULL");
        $series->execute([$round]);
        $list = $series->fetchAll();
        $day = self::currentDay() + 1;
        $ins = $db->prepare("INSERT INTO games(day,stage,home_id,away_id,series_id) VALUES(?,'playoffs',?,?,?)");
        foreach ($list as $s) {
            $gameNum = $s['high_wins'] + $s['low_wins'] + 1;
            // mando: jogos 1,2,5,7 com o melhor seed em casa (2-2-1-1-1)
            $highHome = in_array($gameNum, [1, 2, 5, 7]);
            $home = $highHome ? $s['high_seed_id'] : $s['low_seed_id'];
            $away = $highHome ? $s['low_seed_id'] : $s['high_seed_id'];
            $ins->execute([$day, $home, $away, $s['id']]);
        }
        Database::setMeta('current_day', $day);
    }

    private static function updateSeries(int $seriesId, int $winnerTeamId): void
    {
        $db = Database::conn();
        $s = $db->prepare("SELECT * FROM playoff_series WHERE id = ?");
        $s->execute([$seriesId]);
        $series = $s->fetch();
        if (!$series || $series['winner_id']) return;

        if ($winnerTeamId == $series['high_seed_id']) {
            $db->prepare("UPDATE playoff_series SET high_wins=high_wins+1 WHERE id=?")->execute([$seriesId]);
            $series['high_wins']++;
        } else {
            $db->prepare("UPDATE playoff_series SET low_wins=low_wins+1 WHERE id=?")->execute([$seriesId]);
            $series['low_wins']++;
        }
        if ($series['high_wins'] >= 4 || $series['low_wins'] >= 4) {
            $w = $series['high_wins'] >= 4 ? $series['high_seed_id'] : $series['low_seed_id'];
            $db->prepare("UPDATE playoff_series SET winner_id=? WHERE id=?")->execute([$w, $seriesId]);
        }
    }

    /** Avança playoffs: simula a leva de jogos atual; cria jogos/rodadas seguintes. */
    public static function advancePlayoffDay(bool $autoGm = false): array
    {
        $db = Database::conn();
        $round = (int) Database::meta('playoff_round', 1);
        // simula jogos pendentes do dia atual (deixando o jogo do GM para ele comandar)
        $day = self::currentDay();
        $pendingId = 0;
        if (!$autoGm) { $gg = self::gmGameOnDay($day); if ($gg && !$gg['played']) $pendingId = (int) $gg['id']; }
        foreach (self::gamesByDay($day) as $g) {
            if ($g['played'] || (int) $g['id'] === $pendingId) continue;
            self::simulateGame((int) $g['id']);
        }
        if ($pendingId) return ['msg' => 'Você tem jogo de playoffs hoje! Comande ou simule a partida.',
            'gm_game_pending' => true, 'game_id' => $pendingId, 'phase' => 'playoffs'];

        // séries ainda vivas nesta rodada?
        $alive = $db->prepare("SELECT COUNT(*) c FROM playoff_series WHERE round=? AND winner_id IS NULL");
        $alive->execute([$round]);
        if ((int) $alive->fetch()['c'] > 0) {
            self::scheduleSeriesGames($round);
            return ['msg' => "Playoffs: rodada $round em andamento.", 'phase' => 'playoffs'];
        }

        // rodada concluída -> próxima rodada ou campeão
        return self::buildNextRound($round);
    }

    private static function buildNextRound(int $round): array
    {
        $db = Database::conn();
        if ($round >= 4) {
            // campeão definido
            $fin = $db->query("SELECT * FROM playoff_series WHERE round=4")->fetch();
            $champId = (int) $fin['winner_id'];
            $runnerId = ($champId === (int) $fin['high_seed_id']) ? (int) $fin['low_seed_id'] : (int) $fin['high_seed_id'];
            $team = self::team($champId);
            Database::setMeta('phase', 'offseason');
            Database::setMeta('champion_id', $champId);
            require_once __DIR__ . '/Offseason.php';
            Offseason::finalizeSeason($champId, $runnerId);
            return ['msg' => 'Temporada encerrada! Campeão coroado, prêmios entregues.', 'champion' => $team, 'phase' => 'offseason'];
        }

        $winners = $db->prepare("SELECT * FROM playoff_series WHERE round=? AND winner_id IS NOT NULL ORDER BY conf, high_seed");
        $winners->execute([$round]);
        $done = $winners->fetchAll();

        $newRound = $round + 1;
        if ($newRound < 4) {
            // emparelha vencedores dentro da conferência
            $byConf = ['E' => [], 'W' => []];
            foreach ($done as $s) {
                $w = self::team((int) $s['winner_id']);
                $w['seed'] = ($s['winner_id'] == $s['high_seed_id']) ? $s['high_seed'] : $s['low_seed'];
                $byConf[$s['conf']][] = $w;
            }
            foreach (['E', 'W'] as $conf) {
                $teams = $byConf[$conf];
                usort($teams, fn($a, $b) => $a['seed'] <=> $b['seed']);
                for ($i = 0, $j = count($teams) - 1; $i < $j; $i++, $j--) {
                    self::createSeries($newRound, $conf, $teams[$i], $teams[$j]);
                }
            }
        } else {
            // final: campeão do Leste vs campeão do Oeste
            $champs = [];
            foreach ($done as $s) {
                $w = self::team((int) $s['winner_id']);
                $w['seed'] = ($s['winner_id'] == $s['high_seed_id']) ? $s['high_seed'] : $s['low_seed'];
                $champs[$s['conf']] = $w;
            }
            $e = $champs['E']; $w = $champs['W'];
            // melhor seed (em caso de empate, Leste manda) recebe mando
            self::createSeries(4, 'F', $e, $w);
        }

        Database::setMeta('playoff_round', $newRound);
        self::scheduleSeriesGames($newRound);
        $names = [2 => 'Semifinais de Conferência', 3 => 'Finais de Conferência', 4 => 'Finais da Liga'];
        return ['msg' => ($names[$newRound] ?? "Rodada $newRound") . ' definidas!', 'phase' => 'playoffs'];
    }

    public static function playoffBracket(): array
    {
        return Database::conn()->query(
            "SELECT ps.*, ht.abbr AS high_abbr, lt.abbr AS low_abbr,
                    ht.city AS high_city, lt.city AS low_city
             FROM playoff_series ps
             JOIN teams ht ON ht.id = ps.high_seed_id
             JOIN teams lt ON lt.id = ps.low_seed_id
             ORDER BY ps.round, ps.conf, ps.high_seed")->fetchAll();
    }

    // ===================== LÍDERES / ESTATÍSTICAS =====================

    public static function leaders(string $stat = 'pts', int $limit = 10): array
    {
        $allowed = ['pts', 'reb', 'ast', 'stl', 'blk'];
        if (!in_array($stat, $allowed)) $stat = 'pts';
        $limit = max(1, (int) $limit);
        $st = Database::conn()->prepare(
            "SELECT p.id, p.name, p.pos, t.abbr, s.gp,
                    ROUND(s.$stat * 1.0 / NULLIF(s.gp,0), 1) AS avg,
                    ROUND(s.pts * 1.0 / NULLIF(s.gp,0), 1) AS ppg
             FROM season_stats s JOIN players p ON p.id=s.player_id JOIN teams t ON t.id=p.team_id
             WHERE s.gp >= 1 ORDER BY avg DESC LIMIT $limit");
        $st->execute();
        return $st->fetchAll();
    }

    public static function playerGameLog(int $playerId, int $limit = 20): array
    {
        $limit = max(1, (int) $limit);
        $st = Database::conn()->prepare(
            "SELECT b.*, g.day, g.home_id, g.away_id, g.home_pts, g.away_pts,
                    ht.abbr AS home_abbr, at.abbr AS away_abbr
             FROM box_scores b JOIN games g ON g.id=b.game_id
             JOIN teams ht ON ht.id=g.home_id JOIN teams at ON at.id=g.away_id
             WHERE b.player_id=? ORDER BY g.day DESC LIMIT $limit");
        $st->execute([$playerId]);
        return $st->fetchAll();
    }

    // ===================== FORÇA DO ELENCO + FOLHA SALARIAL =====================

    public const STRENGTH_TOP = 8; // nível do elenco = soma dos 8 maiores OVRs

    /** Força do elenco = soma dos 8 maiores OVRs (indicador de talento, não financeiro). */
    public static function teamStrength(int $teamId): int
    {
        $top = self::STRENGTH_TOP;
        $st = Database::conn()->prepare(
            "SELECT ovr FROM players WHERE team_id = ? AND retired = 0 ORDER BY ovr DESC LIMIT $top");
        $st->execute([$teamId]);
        return (int) array_sum(array_column($st->fetchAll(), 'ovr'));
    }

    /** Média da força dos elencos ativos (para metas/expectativa). */
    public static function avgStrength(): int
    {
        $teams = self::allTeams();
        if (!$teams) return 0;
        $sum = 0;
        foreach ($teams as $t) $sum += self::teamStrength((int) $t['id']);
        return (int) round($sum / count($teams));
    }

    /** Folha salarial de um time = soma dos salários do elenco. */
    public static function teamPayroll(int $teamId): int
    {
        $st = Database::conn()->prepare(
            "SELECT COALESCE(SUM(salary),0) FROM players WHERE team_id = ? AND retired = 0");
        $st->execute([$teamId]);
        return (int) $st->fetchColumn();
    }

    /** Status financeiro de uma folha: 'ok' | 'tax' | 'apron'. */
    public static function payrollStatus(int $payroll): string
    {
        if ($payroll >= Database::APRON)    return 'apron';
        if ($payroll >= Database::TAX_LINE) return 'tax';
        return 'ok';
    }

    /** Imposto de luxo devido (simples: 1.5x o valor acima da linha). */
    public static function luxuryTax(int $payroll): int
    {
        return $payroll > Database::TAX_LINE ? (int) (($payroll - Database::TAX_LINE) * 1.5) : 0;
    }

    /** Espaço de teto disponível (pode ser negativo se acima do teto). */
    public static function capSpace(int $teamId): int
    {
        return Database::SALARY_CAP - self::teamPayroll($teamId);
    }

    /** Tabela de folha salarial da liga (todas as equipes, ordenadas). */
    public static function payrollTable(): array
    {
        $teams = self::allTeams();
        $rows = [];
        foreach ($teams as $t) {
            $pay = self::teamPayroll((int) $t['id']);
            $rows[] = [
                'id' => $t['id'], 'abbr' => $t['abbr'], 'city' => $t['city'], 'name' => $t['name'],
                'conf' => $t['conf'], 'color' => $t['primary_color'],
                'payroll' => $pay, 'status' => self::payrollStatus($pay),
                'tax' => self::luxuryTax($pay), 'space' => Database::SALARY_CAP - $pay,
            ];
        }
        usort($rows, fn($a, $b) => $b['payroll'] <=> $a['payroll']);
        return [
            'teams' => $rows,
            'cap' => Database::SALARY_CAP,
            'tax_line' => Database::TAX_LINE,
            'apron' => Database::APRON,
            'season' => self::season(),
        ];
    }

    // ===================== JOGADOR — CARREIRA E PRÊMIOS =====================

    /** Histórico de temporadas de um jogador (tabela player_seasons). */
    public static function playerCareer(int $playerId): array
    {
        $st = Database::conn()->prepare(
            "SELECT ps.*, t.abbr, t.city, t.name AS team_name
             FROM player_seasons ps LEFT JOIN teams t ON t.id = ps.team_id
             WHERE ps.player_id = ? ORDER BY ps.season ASC");
        $st->execute([$playerId]);
        return $st->fetchAll();
    }

    /** Prêmios individuais conquistados por um jogador. */
    public static function playerAwardsWon(int $playerId): array
    {
        $st = Database::conn()->prepare(
            "SELECT a.type, a.season, a.value, t.abbr
             FROM awards a LEFT JOIN teams t ON t.id = a.team_id
             WHERE a.player_id = ? ORDER BY a.season ASC");
        $st->execute([$playerId]);
        return $st->fetchAll();
    }

    // ===================== PRÊMIOS / HISTÓRICO / TRANSAÇÕES =====================

    public static function awards(int $season): array
    {
        $st = Database::conn()->prepare(
            "SELECT a.*, p.name AS player_name, p.pos, t.abbr
             FROM awards a LEFT JOIN players p ON p.id = a.player_id
             LEFT JOIN teams t ON t.id = a.team_id
             WHERE a.season = ? ORDER BY a.id");
        $st->execute([$season]);
        return $st->fetchAll();
    }

    public static function awardSeasons(): array
    {
        return array_map('intval', array_column(
            Database::conn()->query("SELECT DISTINCT season FROM awards ORDER BY season DESC")->fetchAll(), 'season'));
    }

    public static function champions(): array
    {
        return Database::conn()->query(
            "SELECT c.*, ct.city AS champ_city, ct.name AS champ_name, ct.abbr AS champ_abbr,
                    ct.primary_color AS champ_color,
                    rt.abbr AS run_abbr, p.name AS fmvp_name
             FROM champions c
             JOIN teams ct ON ct.id = c.team_id
             LEFT JOIN teams rt ON rt.id = c.runnerup_id
             LEFT JOIN players p ON p.id = c.fmvp_id
             ORDER BY c.season DESC")->fetchAll();
    }

    public static function titlesRanking(): array
    {
        return Database::conn()->query(
            "SELECT abbr, city, name, titles, primary_color FROM teams WHERE titles > 0
             ORDER BY titles DESC, city")->fetchAll();
    }

    public static function transactions(?int $season = null, int $limit = 100): array
    {
        $limit = max(1, (int) $limit);
        if ($season !== null) {
            $st = Database::conn()->prepare("SELECT * FROM transactions WHERE season=? ORDER BY id DESC LIMIT $limit");
            $st->execute([$season]);
        } else {
            $st = Database::conn()->prepare("SELECT * FROM transactions ORDER BY id DESC LIMIT $limit");
            $st->execute();
        }
        return $st->fetchAll();
    }

    public static function draftResults(int $season): array
    {
        $st = Database::conn()->prepare(
            "SELECT dp.*, t.abbr AS team_abbr, t.city AS team_city
             FROM draft_prospects dp LEFT JOIN teams t ON t.id = dp.picked_by
             WHERE dp.season = ? AND dp.drafted = 1 ORDER BY dp.pick_no");
        $st->execute([$season]);
        return $st->fetchAll();
    }

    public static function draftSeasons(): array
    {
        return array_map('intval', array_column(
            Database::conn()->query("SELECT DISTINCT season FROM draft_prospects WHERE drafted=1 ORDER BY season DESC")->fetchAll(), 'season'));
    }

    public static function injuredPlayers(): array
    {
        return Database::conn()->query(
            "SELECT p.id, p.name, p.pos, p.ovr, p.injury_games, t.abbr
             FROM players p JOIN teams t ON t.id = p.team_id
             WHERE p.injury_games > 0 AND p.retired = 0
             ORDER BY p.injury_games DESC, p.ovr DESC")->fetchAll();
    }

    /** Recordistas históricos por média (pts) numa única temporada. */
    public static function seasonRecords(string $stat = 'pts', int $limit = 10): array
    {
        $allowed = ['pts','reb','ast','stl','blk'];
        if (!in_array($stat, $allowed)) $stat = 'pts';
        $limit = max(1, (int) $limit);
        return Database::conn()->query(
            "SELECT name, season, team_id, age, ovr, gp,
                    ROUND($stat * 1.0 / NULLIF(gp,0), 1) AS avg
             FROM player_seasons WHERE gp >= 20
             ORDER BY avg DESC LIMIT $limit")->fetchAll();
    }

    public static function nextSeason(): array
    {
        require_once __DIR__ . '/Offseason.php';
        // Com franquia controlada: passa pela Loteria (com odds e sorteio animado) antes do Draft.
        if (self::gmTeam()) return Offseason::beginLottery();
        return Offseason::run();
    }

    // ===================== POWER RANKINGS =====================

    public static function powerRankings(): array
    {
        $teams = self::allTeams();
        $margins = [];
        $rows = Database::conn()->query(
            "SELECT home_id,away_id,home_pts,away_pts FROM games WHERE played=1 AND stage='regular'")->fetchAll();
        foreach ($rows as $r) {
            $margins[$r['home_id']][0] = ($margins[$r['home_id']][0] ?? 0) + ($r['home_pts'] - $r['away_pts']);
            $margins[$r['home_id']][1] = ($margins[$r['home_id']][1] ?? 0) + 1;
            $margins[$r['away_id']][0] = ($margins[$r['away_id']][0] ?? 0) + ($r['away_pts'] - $r['home_pts']);
            $margins[$r['away_id']][1] = ($margins[$r['away_id']][1] ?? 0) + 1;
        }
        $avgStr = self::avgStrength();
        $strengths = [];
        foreach ($teams as $t) $strengths[$t['id']] = self::teamStrength((int) $t['id']);

        foreach ($teams as &$t) {
            $g = $t['wins'] + $t['losses'];
            $pct = $g ? $t['wins'] / $g : 0;
            $m = $margins[$t['id']] ?? [0, 0];
            $avgM = $m[1] ? $m[0] / $m[1] : 0;
            $t['pct'] = $pct;
            $t['avg_margin'] = round($avgM, 1);
            $t['power'] = $pct * 100 + $avgM * 2 + ($t['streak'] ?? 0) * 1.0
                + (($strengths[$t['id']] ?? $avgStr) - $avgStr) * 0.15;
        }
        unset($t);
        usort($teams, fn($a, $b) => $b['power'] <=> $a['power']);
        $rank = 1;
        foreach ($teams as &$t) { $t['rank'] = $rank++; }
        unset($t);
        return $teams;
    }

    // ===================== METAS DA DIRETORIA =====================

    public static function boardGoal(): ?array
    {
        $gm = self::gmTeam();
        if (!$gm) return null;
        $j = Database::meta('gm_goal');
        $goal = $j ? json_decode($j, true) : null;
        if (!$goal || (int) ($goal['season'] ?? 0) !== self::season()) {
            $goal = self::makeBoardGoal($gm);
            Database::setMeta('gm_goal', json_encode($goal));
        }
        return $goal;
    }

    private static function makeBoardGoal(int $teamId): array
    {
        $str = self::teamStrength($teamId);
        $d = $str - self::avgStrength();
        $season = self::season();
        if ($d >= 8)  return ['season' => $season, 'type' => 'champion', 'desc' => 'Vencer o título da NBA'];
        if ($d >= 0)  return ['season' => $season, 'type' => 'finals', 'desc' => 'Chegar às Finais da NBA'];
        if ($d >= -8) return ['season' => $season, 'type' => 'playoffs', 'desc' => 'Classificar para os playoffs (top 8)'];
        return ['season' => $season, 'type' => 'wins', 'target' => 30, 'desc' => 'Vencer pelo menos 30 jogos'];
    }

    private static function reachedRound(int $teamId, int $round): bool
    {
        $r = Database::conn()->prepare(
            "SELECT COUNT(*) c FROM playoff_series WHERE round=? AND (high_seed_id=? OR low_seed_id=?)");
        $r->execute([$round, $teamId, $teamId]);
        return (int) $r->fetch()['c'] > 0;
    }

    public static function boardGoalProgress(): ?array
    {
        $goal = self::boardGoal();
        if (!$goal) return null;
        $gm = self::gmTeam();
        $t = self::team($gm);
        $phase = self::phase();
        $seasonOver = ($phase === 'offseason' || $phase === 'draft');
        $seed = null;
        foreach (self::standings($t['conf']) as $s) { if ((int) $s['id'] === $gm) { $seed = $s['seed']; break; } }

        $status = 'andamento'; $detail = '';
        switch ($goal['type']) {
            case 'wins':
                $detail = "{$t['wins']} de {$goal['target']} vitórias";
                if ($t['wins'] >= $goal['target']) $status = 'cumprida';
                elseif ($seasonOver) $status = 'falhou';
                break;
            case 'playoffs':
                $made = self::reachedRound($gm, 1);
                $detail = $made ? 'Classificado aos playoffs!' : ('Seed #' . ($seed ?? '?') . ' na conferência');
                if ($made) $status = 'cumprida'; elseif ($seasonOver) $status = 'falhou';
                break;
            case 'finals':
                $made = self::reachedRound($gm, 4);
                $detail = $made ? 'Chegou às Finais!' : ('Seed #' . ($seed ?? '?') . ' — rumo às Finais');
                if ($made) $status = 'cumprida'; elseif ($seasonOver) $status = 'falhou';
                break;
            case 'champion':
                $champ = (int) Database::meta('champion_id', 0) === $gm;
                $detail = $champ ? '🏆 CAMPEÃO!' : ('Seed #' . ($seed ?? '?') . ' — rumo ao título');
                if ($champ) $status = 'cumprida'; elseif ($seasonOver) $status = 'falhou';
                break;
        }
        return ['desc' => $goal['desc'], 'detail' => $detail, 'status' => $status];
    }

    // ===================== MODO GM =====================

    public const SCHEMES_OFF = ['Pace and Space', 'Pick and Roll Offense', 'Post Play / Grit and Grind'];
    public const SCHEMES_DEF = ['Man-to-Man', '2-3 Zone', 'Switch All'];

    public static function gmTeam(): ?int
    {
        $v = Database::meta('gm_team');
        return $v ? (int) $v : null;
    }

    public static function setGmTeam(int $teamId): void { Database::setMeta('gm_team', $teamId); }

    public static function isGm(int $teamId): bool { return self::gmTeam() === $teamId; }

    public static function setScheme(int $teamId, string $off, string $def): bool
    {
        if (!in_array($off, self::SCHEMES_OFF) || !in_array($def, self::SCHEMES_DEF)) return false;
        Database::conn()->prepare("UPDATE teams SET scheme_off=?, scheme_def=? WHERE id=?")
            ->execute([$off, $def, $teamId]);
        return true;
    }

    /**
     * Define rotação/minutagem. $minutes = [player_id => minutos]; quem tiver >0 entra na rotação.
     * $starters = lista de player_ids titulares.
     */
    public static function setRotation(int $teamId, array $minutes, array $starters): array
    {
        $db = Database::conn();
        $roster = $db->prepare("SELECT id FROM players WHERE team_id=? AND retired=0");
        $roster->execute([$teamId]);
        $ids = array_map('intval', array_column($roster->fetchAll(), 'id'));
        $starters = array_map('intval', $starters);

        $upd = $db->prepare("UPDATE players SET is_starter=?, rotation=?, min_target=? WHERE id=? AND team_id=?");
        $inRot = 0; $totalMin = 0;
        foreach ($ids as $pid) {
            $min = max(0, min(48, (int) ($minutes[$pid] ?? 0)));
            $rot = $min > 0 ? 1 : 0;
            $start = in_array($pid, $starters) ? 1 : 0;
            if ($rot) { $inRot++; $totalMin += $min; }
            $upd->execute([$start, $rot, $min, $pid, $teamId]);
        }
        $warn = [];
        if ($inRot < 5) $warn[] = "Rotação com menos de 5 jogadores.";
        if ($inRot > 8) $warn[] = "Rotação com mais de 8 (a Regra dos 8 Maiores recomenda no máximo 8).";
        if (count($starters) !== 5) $warn[] = "Selecione exatamente 5 titulares (selecionados: " . count($starters) . ").";
        return ['ok' => empty($warn), 'warnings' => $warn, 'rotation' => $inRot, 'total_min' => $totalMin];
    }

    /** Quão carente um time está numa posição (para a IA valorizar trocas). */
    private static function positionalNeed(int $teamId, string $pos): int
    {
        $st = Database::conn()->prepare(
            "SELECT COUNT(*) c FROM players WHERE team_id=? AND retired=0 AND pos=?");
        $st->execute([$teamId, $pos]);
        $c = (int) $st->fetch()['c'];
        return $c <= 1 ? 4 : ($c === 2 ? 2 : ($c === 3 ? 1 : 0));
    }

    /** Valor de um jogador para um time (OVR + juventude + necessidade posicional). */
    private static function playerValue(array $p, int $forTeam): float
    {
        $age = (int) $p['age'];
        $youth = $age <= 22 ? 4 : ($age <= 25 ? 2 : ($age >= 33 ? -4 : ($age >= 30 ? -1.5 : 0)));
        $pot = max(0, (int) ($p['potential'] ?? 0) - (int) $p['ovr']) * 0.3;
        return (int) $p['ovr'] + $youth + $pot + self::positionalNeed($forTeam, $p['pos']);
    }

    private static function playersByIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::conn()->prepare("SELECT * FROM players WHERE id IN ($in) AND retired=0");
        $st->execute($ids);
        return $st->fetchAll();
    }

    /**
     * Avalia uma troca proposta pelo GM. $giveIds (do GM -> IA), $getIds (da IA -> GM).
     * A IA aceita se o que recebe vale tanto quanto o que entrega (na ótica dela).
     */
    public static function evaluateTrade(int $gmTeam, array $giveIds, int $aiTeam, array $getIds, array $givePickIds = [], array $getPickIds = []): array
    {
        $give = self::playersByIds($giveIds); // vão para a IA
        $get  = self::playersByIds($getIds);  // saem da IA
        $givePicks = self::picksByIds($givePickIds); // picks do GM -> IA
        $getPicks  = self::picksByIds($getPickIds);  // picks da IA -> GM
        if (!$give && !$givePicks && !$get && !$getPicks) return ['accept' => false, 'reason' => 'Selecione jogadores ou picks dos dois lados.'];
        foreach ($give as $p) if ((int) $p['team_id'] !== $gmTeam) return ['accept' => false, 'reason' => 'Jogador inválido no seu lado.'];
        foreach ($get as $p)  if ((int) $p['team_id'] !== $aiTeam) return ['accept' => false, 'reason' => 'Jogador inválido no lado adversário.'];
        foreach ($givePicks as $pk) if ((int) $pk['owner_team_id'] !== $gmTeam) return ['accept' => false, 'reason' => 'Pick inválida no seu lado.'];
        foreach ($getPicks as $pk)  if ((int) $pk['owner_team_id'] !== $aiTeam) return ['accept' => false, 'reason' => 'Pick inválida no lado adversário.'];

        // ótica da IA: recebe $give (+ picks), entrega $get (+ picks)
        $recv = 0; foreach ($give as $p) $recv += self::playerValue($p, $aiTeam);
        foreach ($givePicks as $pk) $recv += self::pickValue($pk);
        $send = 0; foreach ($get as $p)  $send += self::playerValue($p, $aiTeam);
        foreach ($getPicks as $pk) $send += self::pickValue($pk);

        // ── Salários (NBA-style simples): folha resultante + teto rígido ──
        $gmSalOut = 0; foreach ($give as $p) $gmSalOut += (int) $p['salary']; // sai do GM
        $gmSalIn  = 0; foreach ($get  as $p) $gmSalIn  += (int) $p['salary']; // entra no GM
        $gmPayrollAfter = self::teamPayroll($gmTeam) - $gmSalOut + $gmSalIn;
        $aiPayrollAfter = self::teamPayroll($aiTeam) - $gmSalIn + $gmSalOut;
        // a IA reluta em absorver salário líquido (pensa no teto)
        $aiSalaryAdded = $gmSalOut - $gmSalIn; // salário que a IA passa a pagar (líquido)
        if ($aiSalaryAdded > 0) $recv -= $aiSalaryAdded / 8000000;

        $aiCount = (int) Database::conn()->query("SELECT COUNT(*) c FROM players WHERE team_id=$aiTeam AND retired=0")->fetch()['c'];
        $aiAfter = $aiCount - count($get) + count($give);
        $gmCount = (int) Database::conn()->query("SELECT COUNT(*) c FROM players WHERE team_id=$gmTeam AND retired=0")->fetch()['c'];
        $gmAfter = $gmCount - count($give) + count($get);

        if ($aiAfter < 9)  return ['accept' => false, 'reason' => 'O time adversário ficaria com elenco curto demais.'];
        if ($aiAfter > 16) return ['accept' => false, 'reason' => 'O time adversário ficaria com elenco grande demais.'];
        if ($gmAfter < 9)  return ['accept' => false, 'reason' => 'Seu time ficaria com menos de 9 jogadores.'];
        if ($gmAfter > 16) return ['accept' => false, 'reason' => 'Seu time ficaria com elenco grande demais.'];

        // Teto rígido (apron): nenhuma das folhas pode ultrapassá-lo após a troca.
        $sal = ['gm_after' => $gmPayrollAfter, 'ai_after' => $aiPayrollAfter, 'gm_out' => $gmSalOut, 'gm_in' => $gmSalIn];
        if ($gmPayrollAfter > Database::APRON)
            return ['accept' => false, 'reason' => 'Sua folha ($' . number_format($gmPayrollAfter/1e6,1) . 'M) ultrapassaria o teto rígido de $' . number_format(Database::APRON/1e6,0) . 'M.', 'salary' => $sal];
        if ($aiPayrollAfter > Database::APRON)
            return ['accept' => false, 'reason' => 'O adversário ultrapassaria o teto rígido — a IA não pode absorver esse salário.', 'salary' => $sal];

        $diff = $recv - $send;
        $accept = $diff >= -1.5; // pequena tolerância
        if ($accept) {
            $reason = $diff >= 4 ? 'Ótimo negócio para nós, aceito!' : 'Negócio equilibrado, fechado.';
            return ['accept' => true, 'reason' => $reason, 'recv' => round($recv, 1), 'send' => round($send, 1), 'salary' => $sal];
        }

        // Gerar contraproposta: IA sugere trocar alguns jogadores para equilibrar
        $counterGet  = $getIds;  // o que o GM pede (mantém)
        $counterGive = $giveIds; // o que o GM oferece (IA pede mais)
        $deficit = abs($diff);

        // IA pede um jogador a mais do GM (o melhor disponível não incluído)
        $aiRoster = Database::conn()->prepare("SELECT * FROM players WHERE team_id=? AND retired=0 ORDER BY ovr DESC");
        $aiRoster->execute([$gmTeam]);
        foreach ($aiRoster->fetchAll() as $candidate) {
            if (in_array((int)$candidate['id'], $giveIds)) continue;
            $val = self::playerValue($candidate, $aiTeam);
            $counterGive[] = (int)$candidate['id'];
            $deficit -= $val;
            if ($deficit <= 1.5) break;
        }

        $reason = $diff >= -5 ? 'Precisamos de um pouco mais nesse pacote.' : 'Você está pedindo muito mais do que oferece.';
        return [
            'accept'      => false,
            'reason'      => $reason,
            'recv'        => round($recv, 1),
            'send'        => round($send, 1),
            'salary'      => $sal,
            'counter'     => $counterGet,  // jogadores da IA que o GM vai receber
            'counter_give'=> $counterGive, // o que a IA pede do GM
        ];
    }

    public static function executeProposedTrade(int $gmTeam, array $giveIds, int $aiTeam, array $getIds, array $givePickIds = [], array $getPickIds = []): array
    {
        $eval = self::evaluateTrade($gmTeam, $giveIds, $aiTeam, $getIds, $givePickIds, $getPickIds);
        if (!$eval['accept']) return $eval;

        $db = Database::conn();
        $season = self::season();
        $give = self::playersByIds($giveIds);
        $get  = self::playersByIds($getIds);
        $givePicks = self::picksByIds($givePickIds);
        $getPicks  = self::picksByIds($getPickIds);
        $db->beginTransaction();
        $mv = $db->prepare("UPDATE players SET team_id=?, morale=62, rotation=0, is_starter=0, min_target=0 WHERE id=?");
        foreach ($give as $p) $mv->execute([$aiTeam, $p['id']]);
        foreach ($get as $p)  $mv->execute([$gmTeam, $p['id']]);
        foreach ($givePicks as $pk) self::transferPick((int) $pk['id'], $aiTeam);
        foreach ($getPicks as $pk)  self::transferPick((int) $pk['id'], $gmTeam);
        $ta = self::team($gmTeam); $tb = self::team($aiTeam);
        $giveLabels = array_merge(array_column($give, 'name'), array_map([self::class, 'pickLabel'], $givePicks));
        $getLabels  = array_merge(array_column($get, 'name'), array_map([self::class, 'pickLabel'], $getPicks));
        $desc = "{$ta['abbr']} envia " . (implode(', ', $giveLabels) ?: 'nada')
            . " para {$tb['abbr']} e recebe " . (implode(', ', $getLabels) ?: 'nada');
        $db->prepare("INSERT INTO transactions(season,day,type,description) VALUES(?,?, 'troca', ?)")
            ->execute([$season, self::currentDay(), $desc]);
        $db->commit();
        $eval['executed'] = true;
        $eval['desc'] = $desc;
        return $eval;
    }

    // ===================== JOGO AO VIVO (comando do GM) =====================

    /** Devolve o jogo se ele envolve o time do GM e ainda não foi jogado; senão null. */
    public static function gmLiveGame(int $gameId): ?array
    {
        $gm = self::gmTeam();
        if (!$gm) return null;
        $g = self::game($gameId);
        if (!$g || $g['played']) return null;
        if ((int) $g['home_id'] !== $gm && (int) $g['away_id'] !== $gm) return null;
        return $g;
    }

    public static function liveState(): ?array
    {
        $j = Database::meta('live_game');
        return $j ? json_decode($j, true) : null;
    }

    /** Inicia o jogo ao vivo — ou RETOMA se já houver um em andamento para este jogo. */
    public static function liveStart(int $gameId): array
    {
        $g = self::gmLiveGame($gameId);
        if (!$g) return ['error' => 'Este jogo não pode ser comandado ao vivo.'];
        $gm = self::gmTeam();
        $gmSide = ((int) $g['home_id'] === $gm) ? 'home' : 'away';
        $state = self::liveState();
        $resume = ($state && (int) ($state['game_id'] ?? 0) === $gameId && empty($state['done']));
        if (!$resume) {
            $state = SimEngine::liveInit((int) $g['home_id'], (int) $g['away_id'], $gmSide);
            $state['game_id'] = $gameId;
            Database::setMeta('live_game', json_encode($state, JSON_UNESCAPED_UNICODE));
        }
        return ['ok' => true, 'gm_side' => $gmSide, 'resumed' => $resume,
            'score' => $state['score'], 'period' => (int) $state['period'], 'timeouts' => $state['timeouts']];
    }

    /** Avança um período no jogo ao vivo aplicando os controles do GM. */
    public static function liveStep(array $controls): array
    {
        $state = self::liveState();
        if (!$state) return ['error' => 'Nenhum jogo ao vivo em andamento.'];
        if ($state['done']) return ['done' => true, 'events' => []];

        $events = SimEngine::liveAdvance($state, $controls);

        if ($state['done']) {
            $result = SimEngine::liveResult($state);
            self::finishLiveGame((int) $state['game_id'], $result);
            Database::conn()->prepare("DELETE FROM meta WHERE k='live_game'")->execute();
        } else {
            Database::setMeta('live_game', json_encode($state, JSON_UNESCAPED_UNICODE));
        }

        return [
            'events' => $events,
            'period' => $state['period'],
            'done' => $state['done'],
            'score' => $state['score'],
            'timeouts' => $state['timeouts'],
            'gm_side' => $state['gm_side'],
            'game_id' => $state['game_id'],
        ];
    }

    // ===================== INBOX / PRESSÃO DA DIRETORIA =====================

    /**
     * Confiança da diretoria (0–100) derivada do desempenho frente à meta e à expectativa
     * de vitórias (proporcional ao Cap do time). Abaixo de ~35 = "cadeira quente".
     */
    public static function ownerConfidence(): ?array
    {
        $gm = self::gmTeam();
        if (!$gm) return null;
        $t = self::team($gm);
        $games = (int) $t['wins'] + (int) $t['losses'];
        $conf = 60;
        if ($games > 0) {
            $pct = $t['wins'] / $games;
            // expectativa proporcional à força do elenco (acima da média => espera ganhar mais)
            $str = self::teamStrength($gm);
            $expPct = self::clampF(0.5 + ($str - self::avgStrength()) * 0.012, 0.32, 0.74);
            $conf = (int) round(50 + ($pct - $expPct) * 140);
        }
        $conf = max(2, min(99, $conf + ((int) ($t['streak'] ?? 0)) * 2));
        $label = $conf >= 70 ? 'Sólida' : ($conf >= 45 ? 'Estável' : ($conf >= 30 ? 'Sob pressão' : 'Cadeira quente'));
        return ['value' => $conf, 'label' => $label];
    }

    private static function clampF(float $v, float $lo, float $hi): float { return max($lo, min($hi, $v)); }

    /** Mensagens da diretoria/imprensa para o GM (derivadas do estado atual). */
    public static function gmInbox(int $limit = 6): array
    {
        $gm = self::gmTeam();
        if (!$gm) return [];
        $t = self::team($gm);
        $msgs = [];

        // meta da diretoria
        $goal = self::boardGoalProgress();
        if ($goal) {
            $icon = $goal['status'] === 'cumprida' ? '✅' : ($goal['status'] === 'falhou' ? '⚠️' : '🎯');
            $msgs[] = ['icon' => $icon, 'from' => 'Diretoria',
                'text' => "Meta: {$goal['desc']}. {$goal['detail']}."];
        }

        // confiança/pressão
        $oc = self::ownerConfidence();
        if ($oc && $oc['value'] < 35) {
            $msgs[] = ['icon' => '🔥', 'from' => 'Dono',
                'text' => "A diretoria está insatisfeita com a campanha. Precisamos de resultados (confiança {$oc['value']}%)."];
        } elseif ($oc && $oc['value'] >= 75) {
            $msgs[] = ['icon' => '👏', 'from' => 'Dono',
                'text' => "Excelente trabalho até aqui — a torcida está empolgada (confiança {$oc['value']}%)."];
        }

        // lesões de jogadores importantes do meu time
        $inj = Database::conn()->prepare(
            "SELECT name, ovr, injury_games FROM players WHERE team_id=? AND retired=0 AND injury_games>0
             ORDER BY ovr DESC LIMIT 2");
        $inj->execute([$gm]);
        foreach ($inj->fetchAll() as $p) {
            $msgs[] = ['icon' => '🩹', 'from' => 'Dep. Médico',
                'text' => "{$p['name']} (OVR {$p['ovr']}) segue no departamento médico (~{$p['injury_games']} jogos)."];
        }

        // sequência atual
        $streak = (int) ($t['streak'] ?? 0);
        if ($streak >= 4) $msgs[] = ['icon' => '📈', 'from' => 'Imprensa', 'text' => "Sua equipe vive boa fase: {$streak} vitórias seguidas."];
        elseif ($streak <= -4) $msgs[] = ['icon' => '📉', 'from' => 'Imprensa', 'text' => "Momento ruim: " . abs($streak) . " derrotas seguidas. A pressão aumenta."];

        // química
        $chem = (int) ($t['chemistry'] ?? 70);
        if ($chem >= 85) $msgs[] = ['icon' => '🤝', 'from' => 'Comissão técnica', 'text' => "O elenco está muito entrosado (química {$chem})."];
        elseif ($chem <= 58) $msgs[] = ['icon' => '🧩', 'from' => 'Comissão técnica', 'text' => "A química do elenco está baixa ({$chem}). Vitórias ajudam a recuperar."];

        return array_slice($msgs, 0, max(1, $limit));
    }

    // ===================== CALENDÁRIO DO TIME / SCOUTING =====================

    /** Todos os jogos de um time (passados e futuros), com adversário e resultado pela ótica do time. */
    public static function teamSchedule(int $teamId): array
    {
        $st = Database::conn()->prepare(
            "SELECT g.*, ht.abbr AS home_abbr, ht.city AS home_city, ht.name AS home_name, ht.primary_color AS home_color,
                    at.abbr AS away_abbr, at.city AS away_city, at.name AS away_name, at.primary_color AS away_color
             FROM games g JOIN teams ht ON ht.id=g.home_id JOIN teams at ON at.id=g.away_id
             WHERE g.home_id=? OR g.away_id=? ORDER BY g.day, g.id");
        $st->execute([$teamId, $teamId]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $isHome = (int) $r['home_id'] === $teamId;
            $r['is_home'] = $isHome;
            $r['opp_id'] = $isHome ? (int) $r['away_id'] : (int) $r['home_id'];
            $r['opp_abbr'] = $isHome ? $r['away_abbr'] : $r['home_abbr'];
            $r['opp_city'] = $isHome ? $r['away_city'] : $r['home_city'];
            $r['opp_name'] = $isHome ? $r['away_name'] : $r['home_name'];
            $r['opp_color'] = $isHome ? $r['away_color'] : $r['home_color'];
            if ($r['played']) {
                $my = $isHome ? (int) $r['home_pts'] : (int) $r['away_pts'];
                $op = $isHome ? (int) $r['away_pts'] : (int) $r['home_pts'];
                $r['my_pts'] = $my; $r['op_pts'] = $op; $r['win'] = $my > $op;
            }
        }
        return $rows;
    }

    /** Próximos jogos de um time (não jogados), a partir do dia atual. */
    public static function upcomingGames(int $teamId, int $limit = 8): array
    {
        $up = array_values(array_filter(self::teamSchedule($teamId), fn($g) => !$g['played']));
        return array_slice($up, 0, max(1, $limit));
    }

    /** Médias de atributos da rotação (8 maiores OVRs) de um time. */
    public static function teamProfile(int $teamId): array
    {
        $st = Database::conn()->prepare(
            "SELECT thr,ins,reb,def,pmk,ath,ovr FROM players WHERE team_id=? AND retired=0 ORDER BY ovr DESC LIMIT 8");
        $st->execute([$teamId]);
        $ps = $st->fetchAll();
        $n = max(1, count($ps));
        $sum = ['thr'=>0,'ins'=>0,'reb'=>0,'def'=>0,'pmk'=>0,'ath'=>0,'ovr'=>0];
        foreach ($ps as $p) foreach ($sum as $k => $v) $sum[$k] += (int) $p[$k];
        foreach ($sum as $k => $v) $sum[$k] = $v / $n;
        return $sum;
    }

    /** Médias da liga (perfil de rotação) — cacheado por requisição. */
    public static function leagueProfileAvg(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $keys = ['thr','ins','reb','def','pmk','ath','ovr'];
        $acc = array_fill_keys($keys, 0.0);
        $teams = self::allTeams();
        foreach ($teams as $t) { $p = self::teamProfile((int) $t['id']); foreach ($keys as $k) $acc[$k] += $p[$k]; }
        $n = max(1, count($teams));
        foreach ($keys as $k) $acc[$k] /= $n;
        return $cache = $acc;
    }

    /** Relatório de scouting de um time: força/fraqueza, estrelas, forma, plano e (opcional) H2H. */
    public static function scoutReport(int $teamId, ?int $vsTeamId = null): array
    {
        $t = self::team($teamId);
        $prof = self::teamProfile($teamId);
        $avg = self::leagueProfileAvg();
        $labels = ['thr'=>'Arremesso de 3','ins'=>'Jogo interior','reb'=>'Rebotes','def'=>'Defesa','pmk'=>'Armação/passe','ath'=>'Atletismo/ritmo'];
        $strengths = []; $weak = [];
        foreach ($labels as $k => $lab) {
            $d = $prof[$k] - $avg[$k];
            if ($d >= 2.5) $strengths[] = $lab;
            elseif ($d <= -2.5) $weak[] = $lab;
        }
        $st = Database::conn()->prepare(
            "SELECT id,name,pos,ovr,injury_games FROM players WHERE team_id=? AND retired=0 ORDER BY ovr DESC LIMIT 5");
        $st->execute([$teamId]);
        $players = $st->fetchAll();
        $star = $players[0] ?? null;

        // forma recente (últimos 5 resultados)
        $played = array_values(array_filter(self::teamSchedule($teamId), fn($g) => $g['played']));
        $form = array_map(fn($g) => $g['win'] ? 'V' : 'D', array_slice($played, -5));

        // plano sugerido
        $tips = [];
        if (in_array('Arremesso de 3', $strengths)) $tips[] = '🎯 Bom de 3 — pressione o perímetro e evite a Zona 2-3 (cede 3pts).';
        if (in_array('Jogo interior', $strengths)) $tips[] = '🛡️ Forte no garrafão — a Zona 2-3 ajuda a fechar a pintura.';
        if ($star && (int) $star['ovr'] >= 88) $tips[] = "👁️ Marcação dupla em {$star['name']} (OVR {$star['ovr']}) pode travar o ataque deles.";
        if (in_array('Rebotes', $weak)) $tips[] = '💪 Fracos no rebote — ataque o garrafão e busque rebote ofensivo.';
        if (in_array('Defesa', $weak)) $tips[] = '⚡ Defesa fraca — acelere (Pace and Space) e force muitos arremessos.';
        if (in_array('Armação/passe', $weak)) $tips[] = '🪤 Pouca criação — Switch All corta as assistências deles.';
        if (!$tips) $tips[] = 'Equipe equilibrada — jogue seu padrão e explore o cansaço no 4º quarto.';

        $report = [
            'team' => $t, 'profile' => $prof, 'avg' => $avg,
            'strengths' => $strengths, 'weaknesses' => $weak,
            'players' => $players, 'star' => $star, 'form' => $form,
            'scheme_off' => $t['scheme_off'], 'scheme_def' => $t['scheme_def'],
            'tips' => $tips, 'record' => $t['wins'] . '-' . $t['losses'], 'streak' => (int) $t['streak'],
        ];
        // confronto direto na temporada (se for contra um time específico)
        if ($vsTeamId) $report['h2h'] = self::headToHead($vsTeamId, $teamId);
        return $report;
    }

    /** Confronto direto na temporada atual: vitórias de $teamId vs $oppId + resultados. */
    public static function headToHead(int $teamId, int $oppId): array
    {
        $st = Database::conn()->prepare(
            "SELECT home_id, away_id, home_pts, away_pts, day FROM games
             WHERE played=1 AND ((home_id=? AND away_id=?) OR (home_id=? AND away_id=?))
             ORDER BY day");
        $st->execute([$teamId, $oppId, $oppId, $teamId]);
        $w = 0; $l = 0; $games = [];
        foreach ($st->fetchAll() as $g) {
            $home = (int) $g['home_id'] === $teamId;
            $my = $home ? (int) $g['home_pts'] : (int) $g['away_pts'];
            $op = $home ? (int) $g['away_pts'] : (int) $g['home_pts'];
            if ($my > $op) $w++; else $l++;
            $games[] = ['day' => (int) $g['day'], 'win' => $my > $op, 'my' => $my, 'op' => $op];
        }
        return ['wins' => $w, 'losses' => $l, 'games' => $games];
    }

    // ===================== HISTÓRICO DE TEMPORADAS / FRANQUIA =====================

    /** Rótulo do resultado de playoffs a partir do exit_round arquivado. */
    public static function exitLabel(int $exit, int $made = 1): string
    {
        if ($exit >= 5) return '🏆 Campeão';
        return match ($exit) {
            4 => 'Vice (Finais)',
            3 => 'Final de Conferência',
            2 => 'Semifinal de Conferência',
            1 => '1ª Rodada',
            default => $made ? 'Play-In' : 'Fora dos playoffs',
        };
    }

    /** Trajetória de um time temporada a temporada (mais recente primeiro). */
    public static function teamSeasonHistory(int $teamId): array
    {
        $st = Database::conn()->prepare(
            "SELECT * FROM season_history WHERE team_id=? ORDER BY season DESC");
        $st->execute([$teamId]);
        return $st->fetchAll();
    }

    /** Resumo histórico da franquia (títulos, finais, presenças em playoff, melhor campanha). */
    public static function franchiseSummary(int $teamId): array
    {
        $rows = array_reverse(self::teamSeasonHistory($teamId)); // ordem crescente
        $titles = $finals = $playoffs = $seasons = 0;
        $bestWins = -1; $bestSeason = null;
        foreach ($rows as $r) {
            $seasons++;
            if ((int) $r['champion']) $titles++;
            if ((int) $r['exit_round'] >= 4) $finals++;
            if ((int) $r['made_playoffs']) $playoffs++;
            if ((int) $r['wins'] > $bestWins) { $bestWins = (int) $r['wins']; $bestSeason = $r; }
        }
        return ['seasons' => $seasons, 'titles' => $titles, 'finals' => $finals,
            'playoffs' => $playoffs, 'best' => $bestSeason];
    }

    // ===================== DECISÕES DO GM (inbox com escolhas) =====================

    public static function pendingDecisions(): array
    {
        return Database::conn()->query(
            "SELECT * FROM decisions WHERE status='pending' ORDER BY id DESC")->fetchAll();
    }

    public static function decision(int $id): ?array
    {
        $st = Database::conn()->prepare("SELECT * FROM decisions WHERE id=?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    private static function hasPendingFor(int $playerId): bool
    {
        $st = Database::conn()->prepare(
            "SELECT 1 FROM decisions WHERE status='pending' AND payload LIKE ?");
        $st->execute(['%"player_id":' . $playerId . '%']);
        return (bool) $st->fetch();
    }

    private static function addDecision(string $type, string $title, string $body, array $options, array $payload): void
    {
        $db = Database::conn();
        $db->prepare(
            "INSERT INTO decisions(season,day,type,title,body,options,payload,status)
             VALUES(?,?,?,?,?,?,?, 'pending')")
            ->execute([self::season(), self::currentDay(), $type, $title, $body,
                json_encode($options, JSON_UNESCAPED_UNICODE), json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        $decId = (int) $db->lastInsertId();
        $icon = $type === 'trade_demand' ? '🗣️' : '🛌';
        $sender = $type === 'trade_demand' ? 'Vestiário' : 'Comissão Técnica';
        self::inboxAdd('decision', $sender, $title, $body, '', $icon, true, $decId);
    }

    /** Cria na caixa de entrada o espelho de qualquer decisão pendente que ainda não tenha um. */
    private static function backfillDecisionInbox(): void
    {
        try {
            $db = Database::conn();
            $rows = $db->query(
                "SELECT * FROM decisions WHERE status='pending'
                 AND id NOT IN (SELECT ref_id FROM inbox WHERE kind='decision')")->fetchAll();
            foreach ($rows as $d) {
                $icon = $d['type'] === 'trade_demand' ? '🗣️' : '🛌';
                $sender = $d['type'] === 'trade_demand' ? 'Vestiário' : 'Comissão Técnica';
                self::inboxAdd('decision', $sender, $d['title'], $d['body'], '', $icon, true, (int) $d['id']);
            }
        } catch (Throwable $e) { /* save antigo sem tabela inbox */ }
    }

    /** Pode gerar uma decisão para o GM ao avançar um dia (cadência baixa). */
    public static function maybeGenerateDecision(): void
    {
        $gm = self::gmTeam();
        if (!$gm) return;
        $pending = Database::conn()->query("SELECT COUNT(*) c FROM decisions WHERE status='pending'")->fetch();
        if ((int) $pending['c'] >= 2) return;
        if ((mt_rand() / mt_getrandmax()) > 0.16) return; // ~16% de chance por dia

        $db = Database::conn();
        // 1) Pedido de troca: jogador insatisfeito (moral baixa)
        $st = $db->prepare("SELECT * FROM players WHERE team_id=? AND retired=0 AND morale < 55 ORDER BY ovr DESC");
        $st->execute([$gm]);
        foreach ($st->fetchAll() as $p) {
            if (self::hasPendingFor((int) $p['id'])) continue;
            self::addDecision('trade_demand',
                "🗣️ {$p['name']} está insatisfeito",
                "{$p['name']} ({$p['pos']}, OVR {$p['ovr']}) pediu mais protagonismo. Como você responde?",
                [
                    'promise' => 'Prometer mais minutos (+moral)',
                    'block'   => 'Colocar na vitrine de trocas',
                    'ignore'  => 'Ignorar o pedido (−moral)',
                ],
                ['player_id' => (int) $p['id'], 'name' => $p['name']]);
            return;
        }

        // 2) Load management: veterano estrela com muitos jogos
        $st = $db->prepare("SELECT p.* FROM players p JOIN season_stats s ON s.player_id=p.id
            WHERE p.team_id=? AND p.retired=0 AND p.age>=32 AND p.ovr>=84 AND s.gp>=10 ORDER BY p.ovr DESC");
        $st->execute([$gm]);
        foreach ($st->fetchAll() as $p) {
            if (self::hasPendingFor((int) $p['id'])) continue;
            self::addDecision('rest_star',
                "🛌 Poupar {$p['name']}?",
                "A comissão técnica sugere poupar {$p['name']} ({$p['age']} anos, OVR {$p['ovr']}) por 2 jogos para evitar lesão e desgaste.",
                [
                    'rest' => 'Poupar 2 jogos (−risco de lesão)',
                    'play' => 'Manter em quadra (+moral)',
                ],
                ['player_id' => (int) $p['id'], 'name' => $p['name']]);
            return;
        }
    }

    /** Resolve uma decisão aplicando os efeitos. */
    public static function resolveDecision(int $id, string $choice): array
    {
        $d = self::decision($id);
        if (!$d || $d['status'] !== 'pending') return ['error' => 'Decisão indisponível.'];
        $opts = json_decode($d['options'], true) ?: [];
        if (!isset($opts[$choice])) return ['error' => 'Escolha inválida.'];
        $payload = json_decode($d['payload'], true) ?: [];
        $pid = (int) ($payload['player_id'] ?? 0);
        $db = Database::conn();
        $msg = '';

        if ($d['type'] === 'trade_demand' && $pid) {
            if ($choice === 'promise') {
                $db->prepare("UPDATE players SET morale = MIN(99, morale + 14) WHERE id=?")->execute([$pid]);
                $msg = "Você conversou com {$payload['name']} — moral recuperada.";
            } elseif ($choice === 'block') {
                $db->prepare("UPDATE players SET morale = MIN(99, morale + 5) WHERE id=?")->execute([$pid]);
                $db->prepare("INSERT INTO transactions(season,day,type,description) VALUES(?,?, 'vitrine', ?)")
                   ->execute([self::season(), self::currentDay(), "{$payload['name']} foi colocado na vitrine de trocas."]);
                $msg = "{$payload['name']} entrou na vitrine de trocas — negocie na Central de Trocas.";
            } else { // ignore
                $db->prepare("UPDATE players SET morale = MAX(30, morale - 10) WHERE id=?")->execute([$pid]);
                $msg = "Você ignorou o pedido de {$payload['name']} — moral em queda.";
            }
        } elseif ($d['type'] === 'rest_star' && $pid) {
            if ($choice === 'rest') {
                $db->prepare("UPDATE players SET rest_games = 2 WHERE id=?")->execute([$pid]);
                $msg = "{$payload['name']} será poupado nos próximos 2 jogos.";
            } else {
                $db->prepare("UPDATE players SET morale = MIN(99, morale + 6) WHERE id=?")->execute([$pid]);
                $msg = "{$payload['name']} segue em quadra — moral em alta.";
            }
        }

        $db->prepare("UPDATE decisions SET status='resolved', choice=? WHERE id=?")->execute([$choice, $id]);
        $db->prepare("UPDATE inbox SET kind='decision_done', is_read=1 WHERE kind='decision' AND ref_id=?")->execute([$id]);
        return ['ok' => true, 'msg' => $msg];
    }

    // ===================== DRAFT PICKS =====================

    public const PICK_WINDOW = 5; // anos de picks mantidos no horizonte

    /** Garante que existam picks (R1+R2 de cada time) para cada ano da janela a partir de $fromYear. */
    public static function ensurePicksWindow(int $fromYear, int $years = self::PICK_WINDOW): void
    {
        $db = Database::conn();
        $teams = $db->query("SELECT id FROM teams WHERE active=1")->fetchAll();
        $has = $db->prepare("SELECT COUNT(*) c FROM draft_picks WHERE year=?");
        $ins = $db->prepare("INSERT INTO draft_picks(year,round,original_team_id,owner_team_id,used) VALUES(?,?,?,?,0)");
        for ($y = $fromYear; $y < $fromYear + $years; $y++) {
            $has->execute([$y]);
            if ((int) $has->fetch()['c'] > 0) continue;
            $db->beginTransaction();
            foreach ($teams as $t) {
                $ins->execute([$y, 1, (int) $t['id'], (int) $t['id']]);
                $ins->execute([$y, 2, (int) $t['id'], (int) $t['id']]);
            }
            $db->commit();
        }
    }

    /** Cria picks (R1+R2) de UM time para a janela a partir de $fromYear (usado em expansão). */
    public static function createPicksForTeam(int $teamId, int $fromYear, int $years = self::PICK_WINDOW): void
    {
        $db = Database::conn();
        $has = $db->prepare("SELECT COUNT(*) c FROM draft_picks WHERE owner_team_id=? AND year=?");
        $ins = $db->prepare("INSERT INTO draft_picks(year,round,original_team_id,owner_team_id,used) VALUES(?,?,?,?,0)");
        for ($y = $fromYear; $y < $fromYear + $years; $y++) {
            $has->execute([$teamId, $y]);
            if ((int) $has->fetch()['c'] > 0) continue;
            $ins->execute([$y, 1, $teamId, $teamId]);
            $ins->execute([$y, 2, $teamId, $teamId]);
        }
    }

    /** Picks que um time POSSUI atualmente (não usadas), ordenadas por ano/rodada. */
    public static function teamPicks(int $teamId): array
    {
        $st = Database::conn()->prepare(
            "SELECT dp.*, ot.abbr AS orig_abbr FROM draft_picks dp
             JOIN teams ot ON ot.id = dp.original_team_id
             WHERE dp.owner_team_id=? AND dp.used=0 ORDER BY dp.year, dp.round, dp.original_team_id");
        $st->execute([$teamId]);
        return $st->fetchAll();
    }

    public static function picksByIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::conn()->prepare(
            "SELECT dp.*, ot.abbr AS orig_abbr FROM draft_picks dp
             JOIN teams ot ON ot.id = dp.original_team_id WHERE dp.id IN ($in) AND dp.used=0");
        $st->execute($ids);
        return $st->fetchAll();
    }

    /** Rótulo curto de uma pick: "R1 2027 (via SAS)". */
    public static function pickLabel(array $p): string
    {
        $via = ((int) $p['original_team_id'] !== (int) $p['owner_team_id']) ? ' (via ' . $p['orig_abbr'] . ')' : '';
        return 'R' . (int) $p['round'] . ' ' . self::draftYearLabel((int) $p['year']) . $via;
    }

    /** Ano-calendário do draft conforme a era do save. */
    public static function draftYearLabel(int $year): string
    {
        return (string) ((int) Database::meta('era_start', 2026) + ($year - 1));
    }

    /** Valor de uma pick para a IA: R1 vale mais; pick de time fraco vale mais (slot melhor). */
    public static function pickValue(array $p): float
    {
        $base = (int) $p['round'] === 1 ? 11.0 : 3.5;
        $orig = self::team((int) $p['original_team_id']);
        $g = (int) $orig['wins'] + (int) $orig['losses'];
        $pct = $g ? $orig['wins'] / $g : 0.5;
        $swing = ((int) $p['round'] === 1 ? 16 : 5) * (0.5 - $pct); // time ruim => pick melhor => +valor
        return max(1.0, $base + $swing);
    }

    public static function transferPick(int $pickId, int $newOwner): void
    {
        Database::conn()->prepare("UPDATE draft_picks SET owner_team_id=? WHERE id=?")->execute([$newOwner, $pickId]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // TÉCNICO (COACH)
    // ═══════════════════════════════════════════════════════════════════

    /** Retorna o técnico do GM, criando um padrão se não existir. */
    public static function gmCoach(): ?array
    {
        $gmId = self::gmTeam();
        if (!$gmId) return null;
        try {
            $db = Database::conn();
            $st = $db->prepare("SELECT * FROM coaches WHERE team_id=? LIMIT 1");
            $st->execute([$gmId]);
            $coach = $st->fetch();
            if ($coach) return $coach;

            // Auto-cria técnico padrão para saves antigos sem técnico
            $name  = Database::meta('gm_name', 'Técnico') ?: 'Técnico';
            $style = Database::meta('coach_style', 'equilibrado') ?: 'equilibrado';
            $attrs = Database::coachAttrsForStyle($style);
            $db->prepare(
                "INSERT INTO coaches(team_id,name,style,ofensivo,defensivo,desenvolvimento,gestao,intensidade)
                 VALUES(?,?,?,?,?,?,?,?)"
            )->execute([$gmId, $name, $style,
                        $attrs['ofensivo'], $attrs['defensivo'], $attrs['desenvolvimento'],
                        $attrs['gestao'], $attrs['intensidade']]);
            $st->execute([$gmId]);
            return $st->fetch() ?: null;
        } catch (Throwable $e) { return null; }
    }

    /** Atualiza atributos do técnico. */
    public static function saveCoach(int $coachId, string $name, array $attrs): void
    {
        Database::conn()->prepare(
            "UPDATE coaches SET name=?,ofensivo=?,defensivo=?,desenvolvimento=?,gestao=?,intensidade=? WHERE id=?"
        )->execute([
            $name,
            max(0, min(99, (int)($attrs['ofensivo'] ?? 70))),
            max(0, min(99, (int)($attrs['defensivo'] ?? 70))),
            max(0, min(99, (int)($attrs['desenvolvimento'] ?? 70))),
            max(0, min(99, (int)($attrs['gestao'] ?? 70))),
            max(0, min(99, (int)($attrs['intensidade'] ?? 70))),
            $coachId,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  CAIXA DE ENTRADA (mensagens estilo FM)
    // ═══════════════════════════════════════════════════════════════════

    /** Insere uma mensagem na caixa de entrada. */
    public static function inboxAdd(string $kind, string $sender, string $title, string $body = '', string $link = '', string $icon = '📬', bool $urgent = false, int $refId = 0): void
    {
        try {
            Database::conn()->prepare(
                "INSERT INTO inbox(season,day,kind,icon,sender,title,body,link,ref_id,is_read,urgent,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?,0,?,?)"
            )->execute([self::season(), self::currentDay(), $kind, $icon, $sender, $title, $body, $link, $refId, $urgent ? 1 : 0, date('c')]);
        } catch (Throwable $e) { /* tabela pode não existir em save muito antigo */ }
    }

    /** Lista mensagens (mais recentes primeiro). */
    public static function inboxList(int $limit = 60): array
    {
        try {
            self::backfillDecisionInbox();
            $limit = max(1, (int) $limit);
            return Database::conn()->query("SELECT * FROM inbox ORDER BY id DESC LIMIT $limit")->fetchAll();
        } catch (Throwable $e) { return []; }
    }

    /** Quantidade de mensagens não lidas. */
    public static function inboxUnread(): int
    {
        try {
            self::backfillDecisionInbox();
            return (int) Database::conn()->query("SELECT COUNT(*) FROM inbox WHERE is_read=0")->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /** Marca todas como lidas. */
    public static function inboxMarkRead(): void
    {
        try { Database::conn()->exec("UPDATE inbox SET is_read=1 WHERE is_read=0"); } catch (Throwable $e) {}
    }

    // ═══════════════════════════════════════════════════════════════════
    //  PRÉ-TEMPORADA (janela estilo 2K: trocas + free agency + eventos)
    // ═══════════════════════════════════════════════════════════════════

    public const PRESEASON_DAYS = 13;

    public static function preseasonDay(): int { return (int) Database::meta('preseason_day', 1); }

    /** Abre a janela de pré-temporada (chamado na criação do save GM). */
    public static function startPreseason(): void
    {
        require_once __DIR__ . '/Offseason.php';
        Database::setMeta('phase', 'preseason');
        Database::setMeta('preseason_day', '1');
        // semeia a caixa de entrada com a chegada do GM
        $gm = self::gmTeam() ? self::team(self::gmTeam()) : null;
        $name = $gm ? ($gm['city'] . ' ' . $gm['name']) : 'sua franquia';
        self::inboxAdd('board', 'Diretoria', 'Bem-vindo, Gerente Geral!',
            "A diretoria do $name confia em você. Use a janela de pré-temporada (" . self::PRESEASON_DAYS . " dias) para ajustar o elenco via trocas e free agency antes do início da temporada.",
            url('preseason'), '🤝', true);
        // garante pool de agentes livres disponível durante a janela
        try { Offseason::buildFreeAgentPool(self::season()); } catch (Throwable $e) {}
        // pedidos de renovação dos titulares com contrato curto
        self::generateReSignRequests();
    }

    /** Demanda de renovação de um jogador: anos + salário pedidos (determinístico). */
    public static function resignDemand(array $p): array
    {
        $age = (int) $p['age'];
        $ovr = (int) $p['ovr'];
        $years = $age <= 27 ? 4 : ($age <= 30 ? 3 : 2);
        // pretensão = valor de mercado com pequeno prêmio para estrelas
        $base = Database::salaryForOvr($ovr, $age);
        $premium = $ovr >= 88 ? 1.10 : ($ovr >= 82 ? 1.05 : 1.0);
        $salary = (int) (round($base * $premium / 100000) * 100000);
        return ['years' => $years, 'salary' => $salary];
    }

    /**
     * Gera pedidos de renovação (acionáveis) para jogadores do GM com contrato no fim.
     * $threshold=1 (pré-temporada, último ano) ou 0 (entressafra, já expirou após o decremento).
     * O salário pedido reflete o OVR ATUAL → sobe se o jogador evoluiu, cai se declinou.
     */
    private static function generateReSignRequests(int $threshold = 1): void
    {
        $gm = self::gmTeam();
        if (!$gm) return;
        $db = Database::conn();
        $rows = $db->prepare(
            "SELECT id,name,pos,age,ovr,salary,contract_years FROM players
             WHERE team_id=? AND retired=0 AND contract_years<=? AND ovr>=74 ORDER BY ovr DESC LIMIT 5");
        $rows->execute([$gm, $threshold]);
        $exists = $db->prepare("SELECT 1 FROM inbox WHERE kind IN ('resign','resign_done') AND ref_id=? AND season=?");
        foreach ($rows->fetchAll() as $p) {
            // não duplica pedido já criado nesta temporada para o jogador
            $exists->execute([(int) $p['id'], self::season()]);
            if ($exists->fetch()) continue;
            $d = self::resignDemand($p);
            $old = (int) $p['salary'];
            $deltaPct = $old > 0 ? (int) round(($d['salary'] - $old) / $old * 100) : 0;
            $trend = $deltaPct > 0 ? "📈 +{$deltaPct}% vs. o salário atual"
                   : ($deltaPct < 0 ? "📉 {$deltaPct}% vs. o salário atual" : "mesmo patamar salarial");
            $body = "Meu cliente {$p['name']} ({$p['pos']}, OVR {$p['ovr']}, {$p['age']} anos) está com o contrato no fim. "
                . "Hoje ganha $" . number_format($old/1e6,1) . "M; pede $" . number_format($d['salary']/1e6,1)
                . "M/ano por {$d['years']} anos ({$trend}). Renova?";
            self::inboxAdd('resign', 'Agente de ' . $p['name'], $p['name'] . ' pede renovação',
                $body, '', '✍️', true, (int) $p['id']);
        }
    }

    /** Gera pedidos de renovação na entressafra (jogadores já expirados, contract_years<=0). */
    public static function generateOffseasonReSignRequests(): void { self::generateReSignRequests(0); }

    /**
     * Resolve uma negociação de renovação (acionada pela caixa de entrada).
     * $accept=true renova nos termos pedidos (Bird: pode passar do teto, com aviso de luxo).
     */
    public static function resignPlayer(int $playerId, bool $accept): array
    {
        $gm = self::gmTeam();
        if (!$gm) return ['error' => 'Sem franquia.'];
        $db = Database::conn();
        $st = $db->prepare("SELECT * FROM players WHERE id=? AND team_id=? AND retired=0");
        $st->execute([$playerId, $gm]);
        $p = $st->fetch();
        if (!$p) return ['error' => 'Jogador indisponível para renovação.'];

        // resolve a(s) mensagem(ns) de renovação desse jogador (some os botões)
        $db->prepare("UPDATE inbox SET kind='resign_done', is_read=1 WHERE kind='resign' AND ref_id=?")->execute([$playerId]);

        if (!$accept) {
            // recusa: joga o último ano, moral cai
            $db->prepare("UPDATE players SET morale=MAX(30, morale-10) WHERE id=?")->execute([$playerId]);
            self::inboxAdd('agent', 'Agente de ' . $p['name'], 'Renovação recusada',
                "Você recusou a renovação de {$p['name']}. Ele cumprirá o último ano de contrato, mas ficou descontente.",
                url('lineup'), '😤', false);
            return ['ok' => true, 'msg' => '❌ Renovação de ' . $p['name'] . ' recusada.'];
        }

        $d = self::resignDemand($p);
        $db->prepare("UPDATE players SET salary=?, contract_years=?, morale=MIN(95, morale+8) WHERE id=?")
           ->execute([$d['salary'], $d['years'], $playerId]);
        $db->prepare("INSERT INTO transactions(season,day,type,description) VALUES(?,?,'renovação',?)")
           ->execute([self::season(), self::currentDay(),
                      "Renovação: {$p['name']} assina por \$" . number_format($d['salary']/1e6,1) . "M/ano por {$d['years']} anos."]);
        $payroll = self::teamPayroll($gm);
        $msg = '✅ ' . $p['name'] . ' renovado: $' . number_format($d['salary']/1e6,1) . 'M/ano · ' . $d['years'] . ' anos.';
        if ($payroll > Database::TAX_LINE) $msg .= ' ⚠️ Folha no imposto de luxo.';
        self::inboxAdd('agent', 'Agente de ' . $p['name'], 'Renovação fechada!',
            "{$p['name']} renovou com o time por {$d['years']} anos. Obrigado pela confiança!", url('cap'), '🤝', false);
        return ['ok' => true, 'msg' => $msg];
    }

    /**
     * Avança um dia da pré-temporada: gera eventos da liga na caixa de entrada.
     * Ao passar do último dia, inicia a temporada regular.
     */
    public static function advancePreseasonDay(): array
    {
        require_once __DIR__ . '/Offseason.php';
        $day = self::preseasonDay();
        if ($day >= self::PRESEASON_DAYS) {
            // encerra a janela → temporada regular
            Database::setMeta('phase', 'regular');
            Database::conn()->prepare("DELETE FROM meta WHERE k='preseason_day'")->execute();
            self::inboxAdd('league', 'Liga', 'A temporada começou!',
                'A pré-temporada acabou. Boa sorte na sua campanha!', url('home'), '🏀', true);
            return ['phase' => 'regular', 'done' => true];
        }
        $day++;
        Database::setMeta('preseason_day', (string) $day);

        // ── Eventos da liga (IA) neste dia ──
        $events = 0;
        // 1) Uma troca de IA (de vez em quando)
        if (self::chanceF(0.55)) {
            try {
                $t = Offseason::aiPreseasonTrade();
                if ($t) {
                    self::inboxAdd('trade', 'Rumores da Liga', $t['headline'], $t['detail'], '', '🔄', false);
                    $events++;
                }
            } catch (Throwable $e) {}
        }
        // 2) Uma assinatura de agente livre pela liga
        if (self::chanceF(0.6)) {
            try {
                $s = Offseason::aiPreseasonSigning();
                if ($s) { self::inboxAdd('signing', 'Free Agency', $s['headline'], $s['detail'], url('freeagency'), '✍️', false); $events++; }
            } catch (Throwable $e) {}
        }
        // 3) Rumor/manchete leve
        if ($events === 0 || self::chanceF(0.4)) {
            self::inboxAdd('news', 'Imprensa', self::randomPreseasonRumor(), '', '📰', false);
        }

        return ['phase' => 'preseason', 'day' => $day, 'total' => self::PRESEASON_DAYS];
    }

    private static function chanceF(float $p): bool { return (mt_rand() / mt_getrandmax()) < $p; }

    private static function randomPreseasonRumor(): string
    {
        $teams = self::allTeams();
        $t = $teams[array_rand($teams)];
        $rumors = [
            "Olheiros elogiam a pré-temporada do {$t['city']} {$t['name']}.",
            "{$t['city']} {$t['name']} avalia reforços antes do início da temporada.",
            "Veteranos do {$t['name']} aparecem em melhor forma física.",
            "Comissão técnica do {$t['city']} testa novo esquema na pré-temporada.",
            "Torcida do {$t['name']} projeta grandes expectativas para a temporada.",
        ];
        return $rumors[array_rand($rumors)];
    }
}
