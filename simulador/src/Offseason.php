<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/League.php';
require_once __DIR__ . '/Installer.php';

/**
 * Motor de entressafra: prêmios, histórico, progressão/declínio, aposentadorias,
 * draft (com OVR/potencial ocultos), trocas + adequação de Cap, e virada de temporada.
 */
class Offseason
{
    private static array $fn = ['Marcus','Tyrell','Jordan','Quentin','Malik','Devon','Trey','Isaiah','Jaylen','Cole',
        'Brandon','Xavier','Donte','Keon','Tariq','Elijah','Damon','Caleb','Drew','Nate','Theo','Reggie','Andre',
        'Marcel','Khalil','Terrence','Jaden','Bryce','Carson','Hunter','Mason','Gabe','Owen','Dorian','Zaire','Amari'];
    private static array $ln = ['Walker','Carter','Brooks','Hayes','Spencer','Coleman','Reed','Foster','Bryant','Mills',
        'Watson','Greene','Newton','Crawford','Daniels','Webb','Lawson','Pierce','Hubbard','Sampson','Ferguson','Ellis',
        'Barton','Holmes','Dawson','Ramsey','Mosley','Tate','Vaughn','Banks','Cross','Knight','Okafor','Mensah','Adebayo'];

    // ============ 1) FIM DA TEMPORADA: prêmios + histórico ============
    public static function finalizeSeason(int $championId, int $runnerUpId): void
    {
        $season = League::season();
        $db = Database::conn();

        $fmvp = self::finalsMVP($championId);
        $db->prepare("INSERT INTO champions(season,team_id,runnerup_id,fmvp_id) VALUES(?,?,?,?)")
           ->execute([$season, $championId, $runnerUpId, $fmvp]);
        $db->prepare("UPDATE teams SET titles = titles + 1 WHERE id = ?")->execute([$championId]);

        $stats = self::regularSeasonAggregate();
        self::computeAwards($season, $stats, $championId, $fmvp);
        self::archivePlayerSeasons($season);
        self::archiveSeasonHistory($season, $championId, $runnerUpId);

        $ct = League::team($championId);
        League::addHeadline($season, 0, 'champion',
            "🏆 {$ct['city']} {$ct['name']} é campeão da NBA na temporada {$season}!", $championId);
    }

    /** Agrega estatísticas da TEMPORADA REGULAR (exclui playoffs) por jogador. */
    private static function regularSeasonAggregate(): array
    {
        $rows = Database::conn()->query(
            "SELECT p.id, p.name, p.team_id, p.pos, p.age, p.ovr, p.seasons_pro, p.def AS defrtg,
                    t.wins AS twins, t.abbr,
                    COUNT(*) AS gp, SUM(b.pts) AS pts, SUM(b.reb) AS reb, SUM(b.ast) AS ast,
                    SUM(b.stl) AS stl, SUM(b.blk) AS blk
             FROM box_scores b
             JOIN games g ON g.id = b.game_id AND g.stage = 'regular'
             JOIN players p ON p.id = b.player_id
             JOIN teams t ON t.id = p.team_id
             GROUP BY p.id
             HAVING gp >= 40")->fetchAll();
        foreach ($rows as &$r) {
            $gp = max(1, (int) $r['gp']);
            $r['ppg'] = $r['pts'] / $gp; $r['rpg'] = $r['reb'] / $gp; $r['apg'] = $r['ast'] / $gp;
            $r['spg'] = $r['stl'] / $gp; $r['bpg'] = $r['blk'] / $gp;
            $r['mvp_score'] = $r['ppg'] + $r['rpg'] * 0.6 + $r['apg'] * 0.6
                + $r['spg'] * 1.6 + $r['bpg'] * 1.6 + $r['twins'] * 0.28;
            $r['dpoy_score'] = $r['defrtg'] * 0.5 + $r['bpg'] * 6 + $r['spg'] * 6 + $r['rpg'] * 0.6 + $r['twins'] * 0.18;
        }
        unset($r);
        return $rows;
    }

    private static function computeAwards(int $season, array $stats, int $championId, ?int $fmvp): void
    {
        if (!$stats) return;
        $db = Database::conn();
        $ins = $db->prepare("INSERT INTO awards(season,type,player_id,team_id,value) VALUES(?,?,?,?,?)");

        // MVP
        $byMvp = $stats;
        usort($byMvp, fn($a, $b) => $b['mvp_score'] <=> $a['mvp_score']);
        $mvp = $byMvp[0];
        $ins->execute([$season, 'MVP', $mvp['id'], $mvp['team_id'],
            sprintf('%.1f pts / %.1f reb / %.1f ast', $mvp['ppg'], $mvp['rpg'], $mvp['apg'])]);

        // DPOY
        $byDef = $stats;
        usort($byDef, fn($a, $b) => $b['dpoy_score'] <=> $a['dpoy_score']);
        $dpoy = $byDef[0];
        $ins->execute([$season, 'DPOY', $dpoy['id'], $dpoy['team_id'],
            sprintf('%.1f toco / %.1f roubo', $dpoy['bpg'], $dpoy['spg'])]);

        // ROY (calouros: seasons_pro = 0)
        $roy = null;
        $rookies = array_values(array_filter($stats, fn($r) => (int) $r['seasons_pro'] === 0));
        if ($rookies) {
            usort($rookies, fn($a, $b) => $b['mvp_score'] <=> $a['mvp_score']);
            $roy = $rookies[0];
            $ins->execute([$season, 'ROY', $roy['id'], $roy['team_id'],
                sprintf('%.1f pts / %.1f reb / %.1f ast', $roy['ppg'], $roy['rpg'], $roy['apg'])]);
        }

        // All-NBA (3 quintetos por pontuação MVP)
        for ($i = 0; $i < 15 && $i < count($byMvp); $i++) {
            $team = intdiv($i, 5) + 1;
            $p = $byMvp[$i];
            $ins->execute([$season, "All-NBA $team", $p['id'], $p['team_id'],
                sprintf('%.1f / %.1f / %.1f', $p['ppg'], $p['rpg'], $p['apg'])]);
        }

        // Finals MVP
        $fmvpName = null;
        if ($fmvp) {
            $pl = League::player($fmvp);
            $fmvpName = $pl ? $pl['name'] : null;
            $ins->execute([$season, 'Finals MVP', $fmvp, $championId, $fmvpName ?? '']);
        }

        // ── ANÚNCIO DOS VENCEDORES (caixa de entrada + manchetes) ──
        $lines = [];
        $add = function (string $label, string $player, string $val = '') use ($season, &$lines) {
            $lines[] = "$label: $player" . ($val ? " · $val" : '');
            League::addHeadline($season, 0, 'award', "$label da Temporada $season: $player" . ($val ? " ($val)" : '') . ".");
        };
        $add('🏅 MVP', $mvp['name'], sprintf('%.1f pts, %.1f reb, %.1f ast', $mvp['ppg'], $mvp['rpg'], $mvp['apg']));
        $add('🛡️ Defensor do Ano (DPOY)', $dpoy['name'], sprintf('%.1f toco, %.1f roubo', $dpoy['bpg'], $dpoy['spg']));
        if ($roy)      $add('🌟 Novato do Ano (ROY)', $roy['name'], sprintf('%.1f pts', $roy['ppg']));
        if ($fmvpName) $add('🏆 MVP das Finais', $fmvpName);
        League::inboxAdd('award', 'Liga NBA', "🏆 Premiações da Temporada $season",
            "Os grandes vencedores da temporada foram anunciados:\n\n" . implode("\n", $lines),
            League::gmTeam() ? 'index.php?p=history' : '', '🏆', true);
    }

    private static function finalsMVP(int $championId): ?int
    {
        $row = Database::conn()->prepare(
            "SELECT b.player_id, SUM(b.pts + b.reb + b.ast) AS impact
             FROM box_scores b
             JOIN games g ON g.id = b.game_id
             JOIN playoff_series ps ON ps.id = g.series_id AND ps.round = 4
             WHERE b.team_id = ?
             GROUP BY b.player_id ORDER BY impact DESC LIMIT 1");
        $row->execute([$championId]);
        $r = $row->fetch();
        return $r ? (int) $r['player_id'] : null;
    }

    /** Arquiva a campanha de cada time na temporada (record, seed, resultado nos playoffs). */
    private static function archiveSeasonHistory(int $season, int $championId, int $runnerUpId): void
    {
        $db = Database::conn();
        $ins = $db->prepare("INSERT INTO season_history(season,team_id,wins,losses,seed,made_playoffs,exit_round,champion)
            VALUES(?,?,?,?,?,?,?,?)");
        $seedMap = [];
        foreach (['E', 'W'] as $conf) {
            foreach (League::standings($conf) as $s) $seedMap[(int) $s['id']] = (int) $s['seed'];
        }
        $maxRound = $db->prepare("SELECT MAX(round) m FROM playoff_series WHERE high_seed_id=? OR low_seed_id=?");
        $db->beginTransaction();
        foreach (League::allTeams() as $t) {
            $tid = (int) $t['id'];
            $maxRound->execute([$tid, $tid]);
            $mr = (int) ($maxRound->fetch()['m'] ?? 0);
            $made = $mr > 0 ? 1 : 0;
            $exit = ($tid === $championId) ? 5 : $mr; // 5 = campeão; senão = rodada em que caiu
            $ins->execute([$season, $tid, (int) $t['wins'], (int) $t['losses'], $seedMap[$tid] ?? 0,
                $made, $exit, $tid === $championId ? 1 : 0]);
        }
        $db->commit();
    }

    private static function archivePlayerSeasons(int $season): void
    {
        $db = Database::conn();
        $rows = $db->query(
            "SELECT s.player_id, p.team_id, p.name, p.age, p.ovr,
                    s.gp, s.pts, s.reb, s.ast, s.stl, s.blk
             FROM season_stats s JOIN players p ON p.id = s.player_id
             WHERE s.gp > 0 AND p.retired = 0")->fetchAll();
        $ins = $db->prepare("INSERT INTO player_seasons(season,player_id,team_id,name,age,ovr,gp,pts,reb,ast,stl,blk)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
        $db->beginTransaction();
        foreach ($rows as $r) {
            $ins->execute([$season, $r['player_id'], $r['team_id'], $r['name'], $r['age'], $r['ovr'],
                $r['gp'], $r['pts'], $r['reb'], $r['ast'], $r['stl'], $r['blk']]);
        }
        $db->commit();
    }

    // ============ 2) VIRADA DE TEMPORADA ============
    public static function run(): array
    {
        $log = [];
        $oldSeason = League::season();
        $newSeason = $oldSeason + 1;

        $log['progression'] = self::progressAndDecline();
        $log['retirements'] = self::retirements($newSeason);
        $draft = self::runDraft($newSeason);
        $log['draft'] = $draft['picks'];
        $log['trades'] = self::offseasonTrades($newSeason);
        self::buildFreeAgentPool($newSeason);
        $log['fa'] = self::aiSignFreeAgents($newSeason, true);
        Database::conn()->exec("DELETE FROM players WHERE team_id IS NULL AND retired = 0");
        self::refillRosters();
        self::recomputeRotations();
        self::resetForNewSeason($newSeason);

        return [
            'season' => $newSeason,
            'retired' => count($log['retirements']),
            'drafted' => count($log['draft']),
            'trades' => count($log['trades']),
            'fa' => $log['fa'],
            'msg' => "Temporada $newSeason iniciada!",
        ];
    }

    /** Progressão dos jovens, estabilidade no auge, declínio dos veteranos. */
    private static function progressAndDecline(): array
    {
        $db = Database::conn();
        $players = $db->query("SELECT p.*, s.gp, s.min FROM players p
            LEFT JOIN season_stats s ON s.player_id = p.id WHERE p.retired = 0")->fetchAll();
        $upd = $db->prepare("UPDATE players SET age=?, seasons_pro=seasons_pro+1, ovr=?,
            ins=?, mid=?, thr=?, pmk=?, reb=?, def=?, ath=?, sta=?, morale=?, potential=? WHERE id=?");
        $db->beginTransaction();
        $changed = 0;
        foreach ($players as $p) {
            $age = (int) $p['age'] + 1;
            $gp = max(1, (int) ($p['gp'] ?? 1));
            $mpg = ($p['min'] ?? 0) / $gp;
            $ovr = (int) $p['ovr'];
            $pot = (int) $p['potential'];
            $delta = 0;

            if ($age <= 24) { // jovem promessa
                $room = max(0, $pot - $ovr);
                $base = random_int(0, 2) + ($mpg >= 26 ? 1 : 0) + ($mpg >= 32 ? 1 : 0);
                $delta = min($room, $base);
                if ($room > 0 && $delta === 0 && self::chance(0.4)) $delta = 1;
            } elseif ($age <= 31) { // auge
                $delta = self::chance(0.3) ? random_int(-1, 1) : 0;
            } else { // declínio
                $sev = 1 + intdiv($age - 32, 2);
                $delta = -random_int(1, min(4, $sev + 1));
            }

            $ovr2 = max(40, min(99, $ovr + $delta));
            $adj = fn($v) => max(25, min(99, (int) $v + $delta));
            $sta = max(55, min(99, 88 - max(0, $age - 29) * 2 + (int) round(((int)$p['ath'] - 75) * 0.2)));
            $morale = (int) round(((int) $p['morale'] + 75) / 2); // tende a normalizar
            $pot2 = max($ovr2, $pot); // potencial nunca abaixo do ovr atual

            $upd->execute([$age, $ovr2, $adj($p['ins']), $adj($p['mid']), $adj($p['thr']),
                $adj($p['pmk']), $adj($p['reb']), $adj($p['def']), $adj($p['ath']), $sta, $morale, $pot2, $p['id']]);
            if ($delta !== 0) $changed++;
        }
        $db->commit();
        return ['changed' => $changed];
    }

    private static function retirements(int $season): array
    {
        $db = Database::conn();
        $players = $db->query("SELECT id,name,age,ovr,team_id FROM players WHERE retired = 0")->fetchAll();
        $retire = $db->prepare("UPDATE players SET retired=1, team_id=NULL, rotation=0, is_starter=0 WHERE id=?");
        $log = $db->prepare("INSERT INTO transactions(season,day,type,description) VALUES(?,?, 'retire', ?)");
        $retired = [];
        foreach ($players as $p) {
            $age = (int) $p['age']; $ovr = (int) $p['ovr'];
            $should = ($age >= 41) || ($age >= 38 && $ovr < 76) || ($age >= 36 && $ovr < 72)
                || ($age >= 37 && self::chance(0.4));
            if ($should) {
                $retire->execute([$p['id']]);
                $log->execute([$season, 0, "{$p['name']} (OVR {$ovr}, {$age} anos) anunciou aposentadoria."]);
                $retired[] = $p;
            }
        }
        return $retired;
    }

    // ============ DRAFT (névoa de guerra) ============
    public static function generateDraftClass(int $season, int $size = 70): void
    {
        // Classe de draft real do ano (ex.: Draft de 2003 com o LeBron)?
        $scripts = json_decode(Database::meta('draft_scripts', '') ?: '[]', true) ?: [];
        $used = json_decode(Database::meta('draft_scripts_used', '') ?: '[]', true) ?: [];
        $calYear = (int) Database::meta('era_start', 2026) + (League::season() - 1); // ano do draft deste offseason
        if (isset($scripts[$calYear]) && !in_array($calYear, $used)) {
            $file = dirname(__DIR__) . '/data/' . $scripts[$calYear];
            if (is_file($file)) {
                $list = require $file;
                self::insertScriptedProspects($season, $list);
                $rest = max(0, $size - count($list));
                if ($rest > 0) self::generateRandomProspects($season, $rest);
                $used[] = $calYear;
                Database::setMeta('draft_scripts_used', json_encode($used));
                return;
            }
        }
        self::generateRandomProspects($season, $size);
    }

    /** Insere uma classe de draft real/roteirizada (prospectos com OVR e potencial definidos). */
    private static function insertScriptedProspects(int $season, array $list): void
    {
        $db = Database::conn();
        $ins = $db->prepare("INSERT INTO draft_prospects
            (season,name,pos,age,ht,ovr,potential,ins,mid,thr,pmk,reb,def,ath,sta)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $db->beginTransaction();
        foreach ($list as $p) {
            $ins->execute([$season, $p['name'], $p['pos'], $p['age'] ?? 19, $p['ht'] ?? 198,
                $p['ovr'], $p['potential'], $p['ins'] ?? $p['ovr'], $p['mid'] ?? $p['ovr'], $p['thr'] ?? ($p['ovr'] - 6),
                $p['pmk'] ?? ($p['ovr'] - 6), $p['reb'] ?? $p['ovr'], $p['def'] ?? $p['ovr'], $p['ath'] ?? 80,
                $p['sta'] ?? random_int(82, 92)]);
        }
        $db->commit();
    }

    /** Geração aleatória de prospectos (névoa de guerra). */
    private static function generateRandomProspects(int $season, int $size = 70): void
    {
        $db = Database::conn();
        $ins = $db->prepare("INSERT INTO draft_prospects
            (season,name,pos,age,ht,ovr,potential,ins,mid,thr,pmk,reb,def,ath,sta)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $positions = ['PG','SG','SF','PF','C'];
        $db->beginTransaction();
        for ($i = 0; $i < $size; $i++) {
            $pos = $positions[array_rand($positions)];
            $age = random_int(19, 22);
            $true = random_int(68, 80) - ($i > 30 ? random_int(0, 6) : 0); // classe afunila
            // potencial oculto: pode ser bust (~true) ou estrela (até 95)
            $ceil = $true + (self::chance(0.18) ? random_int(8, 17) : random_int(0, 7));
            $potential = min(95, max($true, $ceil));
            $bigMan = in_array($pos, ['PF','C']); $guard = in_array($pos, ['PG','SG']);
            $ht = match ($pos) { 'PG'=>random_int(183,193),'SG'=>random_int(193,198),'SF'=>random_int(198,206),'PF'=>random_int(203,211),'C'=>random_int(208,217),default=>198 };
            $j = fn($lo,$hi) => random_int($lo,$hi);
            $name = self::$fn[array_rand(self::$fn)] . ' ' . self::$ln[array_rand(self::$ln)];
            $ins->execute([$season, $name, $pos, $age, $ht, $true, $potential,
                $bigMan ? $true + $j(0,5) : $true - $j(0,8),
                $true - $j(2,8),
                $guard ? $true - $j(0,8) : $true - $j(6,16),
                $pos==='PG' ? $true + $j(0,6) : $true - $j(6,14),
                $bigMan ? $true + $j(2,9) : $true - $j(4,12),
                $true - $j(0,8),
                $j(70,90),
                random_int(80, 93),
            ]);
        }
        $db->commit();
    }

    /**
     * Monta a ORDEM de escolhas (60 picks) do draft do ano $draftYear, resolvendo o DONO de cada pick.
     * 1ª rodada com LOTERIA (sorteio ponderado entre as 14 piores campanhas); 2ª rodada por campanha.
     * Cada entrada: ['owner'=>id, 'orig'=>id, 'round'=>1|2, 'pick_id'=>id].
     */
    private static function buildPickOrder(int $draftYear, ?array $r1OrigOrder = null): array
    {
        $db = Database::conn();
        $teams = $db->query("SELECT id, wins, losses FROM teams WHERE active=1")->fetchAll();
        usort($teams, function ($a, $b) {
            $pa = ($a['wins'] + $a['losses']) ? $a['wins'] / ($a['wins'] + $a['losses']) : 0;
            $pb = ($b['wins'] + $b['losses']) ? $b['wins'] / ($b['wins'] + $b['losses']) : 0;
            return $pa <=> $pb; // pior campanha primeiro
        });
        $recordOrder = array_map(fn($t) => (int) $t['id'], $teams); // pior..melhor
        if ($r1OrigOrder === null) { // sorteio interno (modo automático sem reveal)
            $lottery = self::weightedLottery(array_slice($recordOrder, 0, 14));
            $r1OrigOrder = array_merge($lottery, array_slice($recordOrder, 14));
        }
        $r2OrigOrder = $recordOrder; // 2ª rodada sem loteria

        $order = [];
        $find = $db->prepare("SELECT * FROM draft_picks WHERE year=? AND round=? AND original_team_id=? AND used=0");
        foreach ([[1, $r1OrigOrder], [2, $r2OrigOrder]] as [$round, $origs]) {
            foreach ($origs as $origId) {
                $find->execute([$draftYear, $round, $origId]);
                $row = $find->fetch();
                if (!$row) continue;
                $order[] = ['owner' => (int) $row['owner_team_id'], 'orig' => (int) $origId,
                    'round' => $round, 'pick_id' => (int) $row['id']];
            }
        }
        return $order;
    }

    /** Sorteio ponderado (loteria): os piores têm mais chance de subir, mas sem garantia. */
    private static function weightedLottery(array $teamIds): array
    {
        $pool = [];
        $n = count($teamIds);
        foreach ($teamIds as $i => $id) $pool[(int) $id] = pow($n - $i, 1.6); // i=0 (pior) => maior peso
        $result = [];
        while (!empty($pool)) {
            $total = array_sum($pool);
            $r = mt_rand() / mt_getrandmax() * $total;
            $chosen = array_key_last($pool);
            foreach ($pool as $id => $w) { $r -= $w; if ($r <= 0) { $chosen = $id; break; } }
            $result[] = (int) $chosen;
            unset($pool[$chosen]);
        }
        return $result;
    }

    /** Marca as picks do ano como usadas e estende a janela de picks (+5 anos a partir do próximo). */
    private static function finishDraftPicks(): void
    {
        $year = (int) Database::meta('draft_year', League::season());
        Database::conn()->prepare("UPDATE draft_picks SET used=1 WHERE year=? AND used=0")->execute([$year]);
        League::ensurePicksWindow($year + 1, League::PICK_WINDOW);
    }

    /** Cria o jogador a partir de um prospecto, marca como draftado e consome a pick. */
    private static function createPlayerFromProspect(array $pr, int $teamId, int $pickNo, int $season, int $pickId = 0): int
    {
        $db = Database::conn();
        $insP = $db->prepare("INSERT INTO players
            (team_id,name,pos,age,ht,ovr,ins,mid,thr,pmk,reb,def,ath,sta,potential,seasons_pro,morale,is_starter,rotation,salary,contract_years)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,75,0,0,?,4)");
        $insP->execute([$teamId, $pr['name'], $pr['pos'], $pr['age'], $pr['ht'], $pr['ovr'],
            $pr['ins'], $pr['mid'], $pr['thr'], $pr['pmk'], $pr['reb'], $pr['def'], $pr['ath'], $pr['sta'], $pr['potential'],
            Database::rookieSalary($pickNo)]);
        $pid = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO season_stats(player_id) VALUES(?)")->execute([$pid]);
        $db->prepare("UPDATE draft_prospects SET drafted=1, picked_by=?, pick_no=? WHERE id=?")
            ->execute([$teamId, $pickNo, $pr['id']]);
        if ($pickId) $db->prepare("UPDATE draft_picks SET used=1, pick_no=? WHERE id=?")->execute([$pickNo, $pickId]);
        $tm = League::team($teamId);
        $db->prepare("INSERT INTO transactions(season,day,type,description) VALUES(?,0,'draft',?)")
            ->execute([$season, "Pick #$pickNo: {$tm['abbr']} seleciona {$pr['name']} ({$pr['pos']}, OVR {$pr['ovr']})"]);
        if ($pickNo === 1) {
            League::addHeadline($season, 0, 'draft', "🎓 Pick #1 do Draft: {$tm['abbr']} seleciona {$pr['name']} ({$pr['pos']}).", $teamId);
        }
        return $pid;
    }

    /** Melhor prospecto disponível pela ótica da IA (com ruído -> reaches/quedas). */
    private static function bestAvailableForCpu(int $season): ?array
    {
        $rows = Database::conn()->prepare("SELECT * FROM draft_prospects WHERE season=? AND drafted=0");
        $rows->execute([$season]);
        $prospects = $rows->fetchAll();
        if (!$prospects) return null;
        $best = null; $bestVal = -INF;
        foreach ($prospects as $pr) {
            $val = $pr['ovr'] * 0.62 + $pr['potential'] * 0.38 + random_int(-4, 4);
            if ($val > $bestVal) { $bestVal = $val; $best = $pr; }
        }
        return $best;
    }

    /** IA escolhe pela pick (jogador vai para o DONO da pick). */
    private static function cpuPickForEntry(array $entry, int $pickNo, int $season): void
    {
        $pr = self::bestAvailableForCpu($season);
        if ($pr) self::createPlayerFromProspect($pr, (int) $entry['owner'], $pickNo, $season, (int) $entry['pick_id']);
    }

    /** Draft 100% automático (sem franquia controlada). */
    private static function runDraft(int $season): array
    {
        $draftYear = $season - 1; // run() passa newSeason; o draft usa as picks do ano que terminou
        self::generateDraftClass($season);
        League::ensurePicksWindow($draftYear, 1);
        Database::setMeta('draft_year', (string) $draftYear);
        $order = self::buildPickOrder($draftYear);
        $picks = [];
        $pickNo = 1;
        foreach ($order as $entry) {
            self::cpuPickForEntry($entry, $pickNo, $season);
            $picks[] = ['pick' => $pickNo, 'team' => League::team((int) $entry['owner'])['abbr']];
            $pickNo++;
        }
        self::finishDraftPicks();
        return ['picks' => $picks];
    }

    // ============ LOTERIA (sorteio das posições do draft) ============

    /** Calcula odds (prob. do 1º pick) e SORTEIA a ordem da 1ª rodada (loteria). */
    public static function computeLottery(): array
    {
        $teams = Database::conn()->query("SELECT id, wins, losses FROM teams WHERE active=1")->fetchAll();
        usort($teams, function ($a, $b) {
            $pa = ($a['wins'] + $a['losses']) ? $a['wins'] / ($a['wins'] + $a['losses']) : 0;
            $pb = ($b['wins'] + $b['losses']) ? $b['wins'] / ($b['wins'] + $b['losses']) : 0;
            return $pa <=> $pb;
        });
        $recordOrder = array_map(fn($t) => (int) $t['id'], $teams);
        $lotteryIds = array_slice($recordOrder, 0, 14);
        $n = count($lotteryIds);
        $weights = []; $tot = 0;
        foreach ($lotteryIds as $i => $id) { $w = pow($n - $i, 1.6); $weights[$id] = $w; $tot += $w; }
        $odds = [];
        foreach ($weights as $id => $w) $odds[$id] = round($w / $tot * 100, 1);
        $drawn = self::weightedLottery($lotteryIds);
        $r1 = array_merge($drawn, array_slice($recordOrder, 14));
        return ['r1' => $r1, 'odds' => $odds, 'lottery_ids' => $lotteryIds];
    }

    /** Abre a fase de Loteria (modo GM): roda a entressafra pré-draft e sorteia a ordem. */
    public static function beginLottery(): array
    {
        $cur = League::season();
        $newSeason = $cur + 1;
        self::progressAndDecline();
        self::retirements($newSeason);
        self::processContractsOffseason(); // decrementa, IA renova, GM recebe pedidos
        self::generateDraftClass($newSeason);
        League::ensurePicksWindow($cur, 1);
        $lot = self::computeLottery();
        Database::setMeta('phase', 'lottery');
        Database::setMeta('draft_season', (string) $newSeason);
        Database::setMeta('draft_year', (string) $cur);
        Database::setMeta('lottery_r1', json_encode($lot['r1']));
        Database::setMeta('lottery_odds', json_encode($lot['odds']));
        return ['phase' => 'lottery'];
    }

    /** Dados da loteria para a tela (odds + ordem sorteada da 1ª rodada). */
    public static function lotteryState(): array
    {
        $r1 = json_decode(Database::meta('lottery_r1', '[]'), true);
        $odds = json_decode(Database::meta('lottery_odds', '{}'), true);
        return ['r1' => $r1, 'odds' => $odds, 'season' => (int) Database::meta('draft_season', League::season() + 1)];
    }

    /** Finaliza a loteria e inicia o Draft usando a ordem já sorteada. */
    public static function startDraftFromLottery(): array
    {
        if (League::phase() !== 'lottery') return ['error' => 'Nenhuma loteria em andamento.'];
        $cur = (int) Database::meta('draft_year', League::season());
        $r1 = json_decode(Database::meta('lottery_r1', '[]'), true);
        $order = self::buildPickOrder($cur, $r1 ?: null);
        Database::setMeta('phase', 'draft');
        Database::setMeta('draft_order', json_encode($order));
        Database::setMeta('draft_pick', '0');
        return self::autoPickUntilUserOrEnd();
    }

    public static function autoPickUntilUserOrEnd(): array
    {
        $order = json_decode(Database::meta('draft_order', '[]'), true);
        $pick = (int) Database::meta('draft_pick', 0);
        $season = (int) Database::meta('draft_season', League::season() + 1);
        $gm = League::gmTeam();
        $n = count($order);
        while ($pick < $n) {
            $entry = $order[$pick];
            if ((int) $entry['owner'] === $gm) {
                Database::setMeta('draft_pick', (string) $pick);
                return ['phase' => 'draft', 'on_clock' => true, 'pick' => $pick + 1];
            }
            self::cpuPickForEntry($entry, $pick + 1, $season);
            $pick++;
        }
        Database::setMeta('draft_pick', (string) $pick);
        self::finishDraftPicks();
        self::beginFreeAgency($season); // após o draft, abre a janela de Free Agency
        return ['phase' => 'freeagency', 'done' => true];
    }

    public static function userPick(int $prospectId): array
    {
        $order = json_decode(Database::meta('draft_order', '[]'), true);
        $pick = (int) Database::meta('draft_pick', 0);
        $season = (int) Database::meta('draft_season', League::season() + 1);
        $gm = League::gmTeam();
        if ($pick >= count($order) || (int) $order[$pick]['owner'] !== $gm) {
            return ['error' => 'Não é a sua vez de escolher.'];
        }
        $pr = Database::conn()->prepare("SELECT * FROM draft_prospects WHERE id=? AND season=? AND drafted=0");
        $pr->execute([$prospectId, $season]);
        $row = $pr->fetch();
        if (!$row) return ['error' => 'Prospecto indisponível.'];
        self::createPlayerFromProspect($row, $gm, $pick + 1, $season, (int) $order[$pick]['pick_id']);
        Database::setMeta('draft_pick', (string) ($pick + 1));
        return self::autoPickUntilUserOrEnd();
    }

    /** Estado atual do draft para a UI. */
    public static function draftState(): array
    {
        $order = json_decode(Database::meta('draft_order', '[]'), true);
        $pick = (int) Database::meta('draft_pick', 0);
        $season = (int) Database::meta('draft_season', 0);
        $gm = League::gmTeam();
        $entry = ($pick < count($order)) ? $order[$pick] : null;
        $onClock = $entry ? (int) $entry['owner'] : 0;
        return ['order' => $order, 'pick' => $pick, 'season' => $season,
            'on_clock' => $onClock, 'is_user' => $onClock === $gm, 'total' => count($order),
            'orig' => $entry['orig'] ?? 0, 'round' => $entry['round'] ?? 0];
    }

    public static function availableProspects(int $season): array
    {
        // board de consenso por OVR (o potencial fica oculto — névoa de guerra)
        $st = Database::conn()->prepare("SELECT * FROM draft_prospects WHERE season=? AND drafted=0 ORDER BY ovr DESC, id");
        $st->execute([$season]);
        return $st->fetchAll();
    }

    public static function recentPicks(int $season, int $limit = 12): array
    {
        $limit = max(1, (int) $limit);
        $st = Database::conn()->prepare(
            "SELECT dp.*, t.abbr AS team_abbr FROM draft_prospects dp
             LEFT JOIN teams t ON t.id = dp.picked_by
             WHERE dp.season=? AND dp.drafted=1 ORDER BY dp.pick_no DESC LIMIT $limit");
        $st->execute([$season]);
        return $st->fetchAll();
    }

    // ============ TROCAS + ADEQUAÇÃO DE CAP ============
    private static function offseasonTrades(int $season): array
    {
        $trades = [];
        // 1) Trocas de IA (jogadores de OVR parecido entre dois times)
        $target = random_int(3, 6);
        $attempts = 0;
        while (count($trades) < $target && $attempts++ < 60) {
            $t = self::randomFairTrade($season);
            if ($t) $trades[] = $t;
        }
        // 1b) Trocas envolvendo PICKS: um contender troca pick futura por reforço de um time em reconstrução
        $pickTrades = random_int(1, 3);
        $attempts = 0;
        $done = 0;
        while ($done < $pickTrades && $attempts++ < 40) {
            if (self::aiPickTrade($season)) $done++;
        }
        return $trades;
    }

    /** Troca de IA com pick: contender manda uma R1 futura + coadjuvante por um reforço de um time fraco. */
    private static function aiPickTrade(int $season): bool
    {
        $db = Database::conn();
        // contender = boa campanha; rebuilder = campanha ruim
        $teams = $db->query("SELECT id, wins, losses FROM teams WHERE active=1")->fetchAll();
        usort($teams, function ($a, $b) {
            $pa = ($a['wins'] + $a['losses']) ? $a['wins'] / ($a['wins'] + $a['losses']) : 0;
            $pb = ($b['wins'] + $b['losses']) ? $b['wins'] / ($b['wins'] + $b['losses']) : 0;
            return $pb <=> $pa;
        });
        $contenders = array_slice($teams, 0, 10);
        $rebuilders = array_slice($teams, -10);
        $cont = (int) $contenders[array_rand($contenders)]['id'];
        $reb  = (int) $rebuilders[array_rand($rebuilders)]['id'];
        if ($cont === $reb) return false;

        // pick R1 futura do contender (a mais distante, que dói menos)
        $pk = $db->prepare("SELECT * FROM draft_picks WHERE owner_team_id=? AND round=1 AND used=0 ORDER BY year DESC LIMIT 1");
        $pk->execute([$cont]);
        $pick = $pk->fetch();
        if (!$pick) return false;

        // reforço: um bom jogador do rebuilder (fora do top-1)
        $tgt = $db->prepare("SELECT * FROM players WHERE team_id=? AND retired=0 ORDER BY ovr DESC LIMIT 4");
        $tgt->execute([$reb]);
        $cands = array_slice($tgt->fetchAll(), 1); // pula o craque
        if (!$cands) return false;
        $target = $cands[array_rand($cands)];

        // contrapartida do contender: um coadjuvante
        $fillerPiece = self::pickTradePiece($cont);
        if (!$fillerPiece) return false;

        // executa: target -> contender; filler + pick -> rebuilder
        $db->prepare("UPDATE players SET team_id=?, morale=62 WHERE id=?")->execute([$cont, $target['id']]);
        $db->prepare("UPDATE players SET team_id=?, morale=62 WHERE id=?")->execute([$reb, $fillerPiece['id']]);
        League::transferPick((int) $pick['id'], $reb);
        $tc = League::team($cont); $tr = League::team($reb);
        $desc = "{$tc['abbr']} adquire {$target['name']} (OVR {$target['ovr']}) do {$tr['abbr']} por {$fillerPiece['name']} + pick R1 "
            . League::draftYearLabel((int) $pick['year']);
        $db->prepare("INSERT INTO transactions(season,day,type,description) VALUES(?,0,'troca',?)")
           ->execute([$season, $desc]);
        return true;
    }

    private static function randomFairTrade(int $season): ?array
    {
        $db = Database::conn();
        $teams = $db->query("SELECT id FROM teams WHERE active=1 ORDER BY id")->fetchAll();
        $a = (int) $teams[array_rand($teams)]['id'];
        $b = (int) $teams[array_rand($teams)]['id'];
        if ($a === $b) return null;
        $pa = self::pickTradePiece($a);
        $pb = self::pickTradePiece($b);
        if (!$pa || !$pb) return null;
        if (abs($pa['ovr'] - $pb['ovr']) > 6) return null; // troca justa
        return self::executeTrade($season, $a, $pa, $b, $pb, 'troca');
    }

    private static function pickTradePiece(int $teamId): ?array
    {
        // pega um jogador fora do top-3 do time para não desmontar o núcleo
        $rows = Database::conn()->prepare(
            "SELECT id,name,ovr FROM players WHERE team_id=? AND retired=0 ORDER BY ovr DESC");
        $rows->execute([$teamId]);
        $list = $rows->fetchAll();
        if (count($list) < 6) return null;
        $cand = array_slice($list, 3); // do 4º em diante
        return $cand[array_rand($cand)];
    }

    /** Uma troca de IA na pré-temporada (entre times que NÃO o do GM). Retorna headline/detail. */
    public static function aiPreseasonTrade(): ?array
    {
        $db = Database::conn();
        $gm = League::gmTeam();
        $teams = $db->query("SELECT id FROM teams WHERE active=1" . ($gm ? " AND id<>$gm" : ""))->fetchAll();
        if (count($teams) < 2) return null;
        $a = (int) $teams[array_rand($teams)]['id'];
        $b = (int) $teams[array_rand($teams)]['id'];
        if ($a === $b) return null;
        $pa = self::pickTradePiece($a);
        $pb = self::pickTradePiece($b);
        if (!$pa || !$pb || abs((int)$pa['ovr'] - (int)$pb['ovr']) > 6) return null;
        $t = self::executeTrade(League::season(), $a, $pa, $b, $pb, 'troca');
        if (!$t) return null;
        return ['headline' => "{$t['a']} e {$t['b']} acertam troca",
                'detail'   => "{$t['a']} envia {$t['pa']} e recebe {$t['pb']} do {$t['b']}."];
    }

    /** Uma assinatura de free agency por um time de IA na pré-temporada. */
    public static function aiPreseasonSigning(): ?array
    {
        $db = Database::conn();
        $gm = League::gmTeam();
        $faRows = $db->query("SELECT id,name,pos,ovr FROM players WHERE team_id IS NULL AND retired=0 ORDER BY ovr DESC LIMIT 10")->fetchAll();
        if (!$faRows) return null;
        $pick = $faRows[array_rand($faRows)];
        $teams = $db->query("SELECT id FROM teams WHERE active=1" . ($gm ? " AND id<>$gm" : ""))->fetchAll();
        if (!$teams) return null;
        $tid = (int) $teams[array_rand($teams)]['id'];
        $cnt = (int) $db->query("SELECT COUNT(*) FROM players WHERE team_id=$tid AND retired=0")->fetchColumn();
        if ($cnt >= 15) return null;
        $res = self::signFreeAgent($tid, (int) $pick['id'], League::season(), false);
        if (empty($res['ok'])) return null;
        $tm = League::team($tid);
        return ['headline' => "{$tm['abbr']} assina {$pick['name']}",
                'detail'   => "{$tm['city']} {$tm['name']} contrata {$pick['name']} ({$pick['pos']}, OVR {$pick['ovr']}) na free agency."];
    }

    private static function executeTrade(int $season, int $teamA, array $pa, int $teamB, array $pb, string $type): ?array
    {
        $db = Database::conn();
        $db->prepare("UPDATE players SET team_id=?, morale=62 WHERE id=?")->execute([$teamB, $pa['id']]);
        $db->prepare("UPDATE players SET team_id=?, morale=62 WHERE id=?")->execute([$teamA, $pb['id']]);
        $ta = League::team($teamA); $tb = League::team($teamB);
        $desc = "{$ta['abbr']} troca {$pa['name']} (OVR {$pa['ovr']}) por {$pb['name']} (OVR {$pb['ovr']}) do {$tb['abbr']}";
        $db->prepare("INSERT INTO transactions(season,day,type,description) VALUES(?,0,?,?)")
           ->execute([$season, $type, $desc]);
        return ['a' => $ta['abbr'], 'b' => $tb['abbr'], 'pa' => $pa['name'], 'pb' => $pb['name'], 'type' => $type];
    }

    // ============ FREE AGENCY (agência livre) ============

    /**
     * Monta o pool de agentes livres: jogadores sem time (team_id NULL), mistura de
     * coadjuvantes e alguns veteranos úteis. Limpa sobras de pools anteriores.
     */
    public static function buildFreeAgentPool(int $season, int $size = 0): void
    {
        $db = Database::conn();
        // Sobras da free agency anterior (sintéticas OU jogadores reais dispensados que
        // ninguém assinou) se aposentam em vez de serem apagadas — preserva o histórico
        // de carreira de jogadores reais (ex.: não-renovados pelo GM, cortes da IA).
        $db->exec("UPDATE players SET retired=1 WHERE team_id IS NULL AND retired = 0");
        $size = $size ?: random_int(24, 32);
        $insP = $db->prepare("INSERT INTO players
            (team_id,name,pos,age,ht,ovr,ins,mid,thr,pmk,reb,def,ath,sta,potential,seasons_pro,morale,is_starter,rotation,salary,contract_years)
            VALUES(NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,70,0,0,?,0)");
        $positions = ['PG','SG','SF','PF','C'];
        $db->beginTransaction();
        for ($i = 0; $i < $size; $i++) {
            $pos = $positions[array_rand($positions)];
            $fa = Installer::makeFreeAgent($pos);
            $ovr = (int) $fa['ovr'];
            // ~25% são veteranos com OVR um pouco melhor (alvos de disputa)
            if (self::chance(0.25)) $ovr = min(86, $ovr + random_int(4, 10));
            $age = random_int(23, 35);
            // salário = pretensão de mercado pelo OVR/idade; contract_years=0 (ainda livre)
            $insP->execute([$fa['name'], $fa['pos'], $age, $fa['ht'], $ovr,
                $fa['ins'], $fa['mid'], $fa['thr'], $fa['pmk'], $fa['reb'], $fa['def'], $fa['ath'], $fa['sta'],
                $fa['potential'], max(1, $age - 19), Database::salaryForOvr($ovr, $age)]);
        }
        $db->commit();
    }

    /** Lista de agentes livres disponíveis, ordenados por OVR. */
    public static function freeAgents(int $limit = 60): array
    {
        $limit = max(1, (int) $limit);
        return Database::conn()->query(
            "SELECT * FROM players WHERE team_id IS NULL AND retired = 0
             ORDER BY ovr DESC, potential DESC LIMIT $limit")->fetchAll();
    }

    /** Assina um agente livre para um time (usado por GM e IA).
     *  $enforceCap=true (GM): bloqueia se a folha ultrapassar o teto rígido (apron).
     *  $enforceCap=false (IA): preenche elenco livremente (exceção de mínimo). */
    public static function signFreeAgent(int $teamId, int $faId, int $season, bool $enforceCap = true): array
    {
        $db = Database::conn();
        $fa = $db->prepare("SELECT * FROM players WHERE id=? AND team_id IS NULL AND retired=0");
        $fa->execute([$faId]);
        $p = $fa->fetch();
        if (!$p) return ['error' => 'Agente livre indisponível.'];
        $cnt = (int) $db->query("SELECT COUNT(*) c FROM players WHERE team_id=$teamId AND retired=0")->fetch()['c'];
        if ($cnt >= 15) return ['error' => 'Elenco cheio (máximo de 15 jogadores).'];
        // ao assinar: define salário pela pretensão de mercado e um contrato de 1–4 anos
        $signSalary = (int) ($p['salary'] ?: Database::salaryForOvr((int)$p['ovr'], (int)$p['age']));
        $signYears  = Database::contractYearsFor((int) $p['ovr']);

        // Consciência de teto salarial (só para o GM): não pode ultrapassar o teto rígido.
        $payrollNow = League::teamPayroll($teamId);
        if ($enforceCap && $payrollNow + $signSalary > Database::APRON) {
            return ['error' => 'Sem espaço salarial: a contratação ($' . number_format($signSalary/1e6,1)
                . 'M) ultrapassaria o teto rígido de $' . number_format(Database::APRON/1e6,0) . 'M. Libere salário antes.'];
        }

        $db->prepare("UPDATE players SET team_id=?, morale=70, rotation=0, is_starter=0, min_target=0, salary=?, contract_years=? WHERE id=?")
           ->execute([$teamId, $signSalary, $signYears, $p['id']]);
        if (!$db->query("SELECT 1 FROM season_stats WHERE player_id={$p['id']}")->fetch()) {
            $db->prepare("INSERT INTO season_stats(player_id) VALUES(?)")->execute([$p['id']]);
        }
        $tm = League::team($teamId);
        $db->prepare("INSERT INTO transactions(season,day,type,description) VALUES(?,0,'free agency',?)")
           ->execute([$season, "{$tm['abbr']} contrata o agente livre {$p['name']} ({$p['pos']}, OVR {$p['ovr']})"]);

        $msg = '✅ ' . $p['name'] . ' assinado por $' . number_format($signSalary/1e6,1) . 'M/ano · ' . $signYears . ' ano' . ($signYears>1?'s':'') . '.';
        if ($payrollNow + $signSalary > Database::TAX_LINE) $msg .= ' ⚠️ Folha entra no imposto de luxo.';
        return ['ok' => true, 'player' => $p, 'msg' => $msg, 'salary' => $signSalary];
    }

    /** Melhor agente livre para um time, priorizando a posição mais carente. */
    private static function bestFaForTeam(int $teamId): ?array
    {
        $db = Database::conn();
        $posCount = [];
        foreach (['PG','SG','SF','PF','C'] as $pp) {
            $posCount[$pp] = (int) $db->query("SELECT COUNT(*) c FROM players WHERE team_id=$teamId AND retired=0 AND pos='$pp'")->fetch()['c'];
        }
        asort($posCount);
        $need = array_key_first($posCount);
        $r = $db->prepare("SELECT * FROM players WHERE team_id IS NULL AND retired=0 AND pos=? ORDER BY ovr DESC LIMIT 1");
        $r->execute([$need]);
        $fa = $r->fetch();
        if (!$fa) $fa = $db->query("SELECT * FROM players WHERE team_id IS NULL AND retired=0 ORDER BY ovr DESC LIMIT 1")->fetch();
        return $fa ?: null;
    }

    /**
     * IA assina agentes livres para completar elencos. Se $fillAll for falso, não mexe
     * no time do GM e deixa parte do pool para a disputa (preenche até 11–13).
     */
    private static function aiSignFreeAgents(int $season, bool $fillAll): int
    {
        $db = Database::conn();
        $gm = League::gmTeam();
        $teams = $db->query("SELECT id FROM teams WHERE active=1")->fetchAll();
        $signed = 0;
        foreach ($teams as $t) {
            $tid = (int) $t['id'];
            if (!$fillAll && $tid === $gm) continue;
            $target = $fillAll ? 13 : random_int(11, 13);
            $guard = 0;
            while ($guard++ < 25) {
                $cnt = (int) $db->query("SELECT COUNT(*) c FROM players WHERE team_id=$tid AND retired=0")->fetch()['c'];
                if ($cnt >= $target) break;
                $fa = self::bestFaForTeam($tid);
                if (!$fa) break;
                self::signFreeAgent($tid, (int) $fa['id'], $season, false); // IA preenche livre
                $signed++;
            }
        }
        return $signed;
    }

    /**
     * IA faz assinaturas de "upgrade": dispensa o pior jogador do banco (fora do
     * núcleo) por um agente livre nitidamente melhor. Sem isso, a Free Agency é
     * inútil para a IA sempre que o draft já deixa os elencos cheios (13-15) —
     * o que é o caso normal, já que 60 picks para 30 times enchem 2 vagas/time.
     */
    private static function aiUpgradeSignings(int $season): int
    {
        $db = Database::conn();
        $gm = League::gmTeam();
        $teams = $db->query("SELECT id FROM teams WHERE active=1")->fetchAll();
        $signed = 0;
        foreach ($teams as $t) {
            $tid = (int) $t['id'];
            if ($tid === $gm) continue;
            if (!self::chance(0.45)) continue; // nem todo time mexe todo ano

            $roster = $db->query("SELECT id,ovr FROM players WHERE team_id=$tid AND retired=0 ORDER BY ovr DESC")->fetchAll();
            if (count($roster) < 8) continue; // elenco curto — não corta ninguém
            $weak = end($roster); // pior OVR do elenco
            if (!$weak) continue;

            $fa = self::bestFaForTeam($tid);
            if (!$fa) continue;
            if ((int) $fa['ovr'] < (int) $weak['ovr'] + 4) continue; // só troca se for upgrade real

            // libera o pior jogador (vira agente livre disponível) e assina o reforço
            $db->prepare("UPDATE players SET team_id=NULL, is_starter=0, rotation=0, min_target=0 WHERE id=?")->execute([(int) $weak['id']]);
            $r = self::signFreeAgent($tid, (int) $fa['id'], $season, false);
            if (isset($r['ok'])) $signed++;
        }
        return $signed;
    }

    /** Abre a janela de Free Agency (modo GM): trocas de offseason + pool + IA (preenchimento + upgrades). */
    public static function beginFreeAgency(int $season): array
    {
        self::offseasonTrades($season);
        self::buildFreeAgentPool($season);
        self::aiSignFreeAgents($season, false); // IA pega parte do pool, deixa o resto para o GM
        self::aiUpgradeSignings($season);        // IA melhora elenco trocando banco fraco por FA melhor
        Database::setMeta('phase', 'freeagency');
        Database::setMeta('fa_season', (string) $season);
        return ['phase' => 'freeagency', 'season' => $season];
    }

    /** Encerra a Free Agency: IA completa elencos, limpa sobras e inicia a temporada. */
    public static function finishFreeAgency(): array
    {
        $season = (int) Database::meta('fa_season', League::season() + 1);
        self::aiSignFreeAgents($season, true);
        Database::conn()->exec("DELETE FROM players WHERE team_id IS NULL AND retired = 0"); // dispensa sobras
        self::refillRosters();
        self::recomputeRotations();
        self::resetForNewSeason($season);
        Database::conn()->prepare("DELETE FROM meta WHERE k='fa_season'")->execute();
        return ['phase' => 'regular', 'season' => $season];
    }

    // ============ MANUTENÇÃO DE ELENCOS / RESET ============
    private static function refillRosters(): void
    {
        $db = Database::conn();
        $teams = $db->query("SELECT id FROM teams WHERE active=1")->fetchAll();
        $insP = $db->prepare("INSERT INTO players
            (team_id,name,pos,age,ht,ovr,ins,mid,thr,pmk,reb,def,ath,sta,potential,seasons_pro,morale,is_starter,rotation,salary,contract_years)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,75,0,0,?,?)");
        $insStat = $db->prepare("INSERT INTO season_stats(player_id) VALUES(?)");
        foreach ($teams as $t) {
            $cnt = (int) $db->query("SELECT COUNT(*) c FROM players WHERE team_id={$t['id']} AND retired=0")->fetch()['c'];
            // garante cobertura de posições e mínimo de 13
            $posCount = [];
            foreach (['PG','SG','SF','PF','C'] as $pp) {
                $posCount[$pp] = (int) $db->query("SELECT COUNT(*) c FROM players WHERE team_id={$t['id']} AND retired=0 AND pos='$pp'")->fetch()['c'];
            }
            while ($cnt < 13) {
                asort($posCount);
                $pos = array_key_first($posCount);
                $fa = Installer::makeFreeAgent($pos);
                $insP->execute([$t['id'], $fa['name'], $fa['pos'], $fa['age'], $fa['ht'], $fa['ovr'],
                    $fa['ins'], $fa['mid'], $fa['thr'], $fa['pmk'], $fa['reb'], $fa['def'], $fa['ath'], $fa['sta'],
                    $fa['potential'], max(1, (int)$fa['age'] - 19),
                    Database::salaryForOvr((int)$fa['ovr'], (int)$fa['age']), Database::contractYearsFor((int)$fa['ovr'])]);
                $insStat->execute([(int) $db->lastInsertId()]);
                $posCount[$pos]++; $cnt++;
            }
        }
    }

    private static function recomputeRotations(): void
    {
        $db = Database::conn();
        $teams = $db->query("SELECT id FROM teams WHERE active=1")->fetchAll();
        foreach ($teams as $t) {
            $players = $db->query("SELECT id FROM players WHERE team_id={$t['id']} AND retired=0 ORDER BY ovr DESC")->fetchAll();
            $i = 0;
            $upd = $db->prepare("UPDATE players SET is_starter=?, rotation=? WHERE id=?");
            foreach ($players as $p) {
                $upd->execute([$i < 5 ? 1 : 0, $i < 8 ? 1 : 0, $p['id']]);
                $i++;
            }
        }
    }

    /** Ativa times de expansão cujo ano de entrada chegou e monta seus elencos. */
    private static function runExpansions(int $newSeason): array
    {
        $exp = json_decode(Database::meta('expansions', '') ?: '[]', true) ?: [];
        if (!$exp) return [];
        $calYear = (int) Database::meta('era_start', 2026) + ($newSeason - 1);
        if (!isset($exp[$calYear])) return [];
        $db = Database::conn();
        $entered = [];
        foreach ($exp[$calYear] as $abbr) {
            $t = $db->prepare("SELECT * FROM teams WHERE abbr=? AND active=0");
            $t->execute([$abbr]);
            $team = $t->fetch();
            if (!$team) continue;
            $tid = (int) $team['id'];
            $db->prepare("UPDATE teams SET active=1, wins=0, losses=0, streak=0, chemistry=70 WHERE id=?")->execute([$tid]);
            self::fillExpansionRoster($tid);
            League::createPicksForTeam($tid, $newSeason, League::PICK_WINDOW);
            $db->prepare("INSERT INTO transactions(season,day,type,description) VALUES(?,0,'expansão',?)")
               ->execute([$newSeason, "{$team['city']} {$team['name']} entra na liga (expansão de {$calYear})."]);
            League::addHeadline($newSeason, 0, 'expansion',
                "🆕 Expansão: {$team['city']} {$team['name']} entra na liga!", $tid);
            $entered[] = $abbr;
        }
        return $entered;
    }

    /** Monta um elenco de expansão (jovens/medianos) para um time recém-ativado. */
    private static function fillExpansionRoster(int $teamId): void
    {
        $db = Database::conn();
        $insP = $db->prepare("INSERT INTO players
            (team_id,name,pos,age,ht,ovr,ins,mid,thr,pmk,reb,def,ath,sta,potential,seasons_pro,morale,is_starter,rotation,salary,contract_years)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,72,?,?,?,?)");
        $insStat = $db->prepare("INSERT INTO season_stats(player_id) VALUES(?)");
        $slots = ['PG','SG','SF','PF','C','PG','SG','SF','PF','C','SG','SF','C']; // 13
        $i = 0;
        foreach ($slots as $pos) {
            $fa = Installer::makeFreeAgent($pos);
            $ovr = max(64, min(78, (int) $fa['ovr'] - random_int(0, 4))); // elenco fraco de expansão
            $age = random_int(20, 28);
            $insP->execute([$teamId, $fa['name'], $pos, $age, $fa['ht'], $ovr,
                $fa['ins'], $fa['mid'], $fa['thr'], $fa['pmk'], $fa['reb'], $fa['def'], $fa['ath'], $fa['sta'],
                Installer::potentialFor($age, $ovr), max(1, $age - 19), $i < 5 ? 1 : 0, $i < 8 ? 1 : 0,
                Database::salaryForOvr($ovr, $age), Database::contractYearsFor($ovr)]);
            $insStat->execute([(int) $db->lastInsertId()]);
            $i++;
        }
    }

    private static function resetForNewSeason(int $newSeason): void
    {
        $db = Database::conn();
        self::runExpansions($newSeason); // ativa times de expansão deste ano (antes de recriar stats/calendário)
        $db->exec("DELETE FROM box_scores");
        $db->exec("DELETE FROM games");
        $db->exec("DELETE FROM playoff_series");
        $db->exec("DELETE FROM season_stats");
        // recria season_stats para todos os jogadores ativos
        $db->exec("INSERT INTO season_stats(player_id) SELECT id FROM players WHERE retired=0");
        $db->exec("UPDATE teams SET wins=0, losses=0, streak=0");
        $db->exec("UPDATE players SET injury_games=0, injury_desc=NULL WHERE retired=0");

        // ── Contratos ──
        $gm = League::gmTeam();
        if ($gm) {
            // Decremento + renovação da IA já ocorreram no início da entressafra (beginLottery).
            // Aqui: jogadores do GM que expiraram e NÃO foram renovados deixam o time.
            $expired = $db->query("SELECT id,name,pos FROM players WHERE retired=0 AND team_id=$gm AND contract_years<=0")->fetchAll();
            foreach ($expired as $exp) {
                $db->prepare("UPDATE players SET team_id=NULL, is_starter=0, rotation=0, min_target=0 WHERE id=?")->execute([(int)$exp['id']]);
                League::inboxAdd('news', 'Imprensa', $exp['name'] . ' deixou o time',
                    "Sem acordo de renovação, {$exp['name']} ({$exp['pos']}) saiu como agente livre.", '', '👋', false);
            }
            if ($expired) self::refillRosters(); // repõe as vagas abertas pelas saídas
        } else {
            // Modo sem GM: decrementa e renova tudo automaticamente.
            $db->exec("UPDATE players SET contract_years = contract_years - 1 WHERE retired=0 AND team_id IS NOT NULL AND contract_years > 0");
            foreach ($db->query("SELECT id, ovr, age FROM players WHERE retired=0 AND team_id IS NOT NULL AND contract_years <= 0")->fetchAll() as $exp) {
                $db->prepare("UPDATE players SET salary=?, contract_years=? WHERE id=?")
                   ->execute([Database::salaryForOvr((int)$exp['ovr'], (int)$exp['age']),
                              Database::contractYearsFor((int)$exp['ovr']), (int)$exp['id']]);
            }
        }

        // Apara elencos ao máximo da liga (15) — o draft adiciona calouros e as
        // renovações mantêm veteranos; sem isso os elencos incham a cada temporada.
        self::trimRosters(15);

        foreach (['playin_seeds','playin_map','playin_stage','champion_id','playoff_round'] as $k) {
            $db->prepare("DELETE FROM meta WHERE k=?")->execute([$k]);
        }
        Installer::newSeasonSchedule();
        Database::setMeta('season', (string) $newSeason);
        Database::setMeta('current_day', '1');
        Database::setMeta('total_days', (string) Installer::targetDays());
        Database::setMeta('phase', 'regular');
    }

    private static function chance(float $p): bool { return (mt_rand() / mt_getrandmax()) < $p; }

    /**
     * Apara cada elenco ativo ao máximo da liga (15), dispensando os piores
     * (menor OVR). Calouros (seasons_pro=0) são protegidos com um bônus no
     * ordenamento para não serem cortados logo após o draft.
     */
    private static function trimRosters(int $max = 15): void
    {
        $db = Database::conn();
        foreach ($db->query("SELECT id FROM teams WHERE active=1")->fetchAll() as $t) {
            $tid = (int) $t['id'];
            $players = $db->query(
                "SELECT id FROM players WHERE team_id=$tid AND retired=0
                 ORDER BY (CASE WHEN seasons_pro=0 THEN ovr+15 ELSE ovr END) DESC")->fetchAll(PDO::FETCH_COLUMN);
            if (count($players) <= $max) continue;
            $cut = array_slice($players, $max);
            foreach ($cut as $pid) {
                // dispensado da liga (veterano de fim de carreira / preenchimento)
                $db->prepare("UPDATE players SET retired=1, team_id=NULL, is_starter=0, rotation=0, min_target=0 WHERE id=?")->execute([(int) $pid]);
            }
        }
    }

    /**
     * Processamento de contratos no INÍCIO da entressafra GM (após a progressão):
     * decrementa um ano de todos; a IA renova automaticamente os expirados; os
     * jogadores do GM que expiraram geram pedidos de renovação na caixa (o GM
     * negocia durante a janela; quem não for renovado vira agente livre no reset).
     */
    private static function processContractsOffseason(): void
    {
        $db = Database::conn();
        $gm = League::gmTeam();
        $db->exec("UPDATE players SET contract_years = contract_years - 1 WHERE retired=0 AND team_id IS NOT NULL AND contract_years > 0");
        foreach ($db->query("SELECT id, ovr, age, team_id FROM players WHERE retired=0 AND team_id IS NOT NULL AND contract_years <= 0")->fetchAll() as $exp) {
            if ($gm && (int) $exp['team_id'] === $gm) continue; // jogador do GM: negocia
            $db->prepare("UPDATE players SET salary=?, contract_years=? WHERE id=?")
               ->execute([Database::salaryForOvr((int)$exp['ovr'], (int)$exp['age']),
                          Database::contractYearsFor((int)$exp['ovr']), (int)$exp['id']]);
        }
        League::generateOffseasonReSignRequests();
    }
}
