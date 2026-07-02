<?php
require_once __DIR__ . '/Database.php';

/**
 * Cria o schema e popula o banco: times, elencos (núcleo real + role players),
 * estatísticas zeradas e o calendário da temporada.
 */
class Installer
{
    // Pools para gerar role players de preenchimento
    private static array $firstNames = ['Marcus','Tyrell','Jordan','Quentin','Malik','Devon','Trey','Isaiah','Jaylen','Cole',
        'Brandon','Xavier','Donte','Keon','Jamarcus','Tariq','Elijah','Damon','Caleb','Drew','Nate','Theo','Reggie','Vince',
        'Andre','Marcel','Darnell','Khalil','Terrence','Jaden','Bryce','Carson','Hunter','Mason',' Collins','Gabe','Owen'];
    private static array $lastNames = ['Walker','Carter','Brooks','Hayes','Spencer','Coleman','Reed','Foster','Bryant','Mills',
        'Watson','Greene','Newton','Crawford','Daniels','Webb','Lawson','Pierce','Hubbard','Sampson','Ferguson','Ellis',
        'Barton','Holmes','Dawson','Ramsey','Mosley','Tate','Vaughn','Singleton','Banks','Cross','Knight','Porter','Rivers'];

    public static function run(string $eraKey = 'modern'): array
    {
        Database::createSchema();
        $db = Database::conn();

        // resolve a era escolhida
        $eras = require dirname(__DIR__) . '/data/eras.php';
        $era = $eras[$eraKey] ?? $eras['modern'] ?? reset($eras);
        $eraKey = $eras[$eraKey] ?? false ? $eraKey : 'modern';
        $playersFile = dirname(__DIR__) . '/data/' . ($era['file'] ?? 'players.php');
        if (!is_file($playersFile)) $playersFile = dirname(__DIR__) . '/data/players.php';

        $teams = require dirname(__DIR__) . '/data/teams.php';
        $playerData = require $playersFile;

        // conjunto de times ATIVOS no início da era (padrão: todas as 30 franquias)
        $activeSet = $era['teams'] ?? array_column($teams, 'abbr');
        $activeSet = array_flip($activeSet);

        $teamIds = [];
        $activeIds = [];
        $insTeam = $db->prepare("INSERT INTO teams(abbr,city,name,conf,`div`,primary_color,secondary_color,scheme_off,scheme_def,active)
            VALUES(?,?,?,?,?,?,?,?,?,?)");
        foreach ($teams as $t) {
            $isActive = isset($activeSet[$t['abbr']]) ? 1 : 0;
            $insTeam->execute([$t['abbr'],$t['city'],$t['name'],$t['conf'],$t['div'],$t['primary'],$t['secondary'],
                'Pace and Space','Man-to-Man',$isActive]);
            $id = (int) $db->lastInsertId();
            $teamIds[$t['abbr']] = $id;
            if ($isActive) $activeIds[] = $id;
        }

        // Mapa nome → NBA ID para fotos
        $nbaIds = require dirname(__DIR__) . '/data/nba_ids.php';

        $insPlayer = $db->prepare("INSERT INTO players(team_id,name,pos,age,ht,ovr,ins,mid,thr,pmk,reb,def,ath,sta,potential,seasons_pro,morale,is_starter,rotation,nba_id,salary,contract_years)
            VALUES(:team_id,:name,:pos,:age,:ht,:ovr,:ins,:mid,:thr,:pmk,:reb,:def,:ath,:sta,:potential,:seasons_pro,:morale,:is_starter,:rotation,:nba_id,:salary,:contract_years)");
        $insStats = $db->prepare("INSERT INTO season_stats(player_id) VALUES(?)");

        $playerCount = 0;
        foreach ($teams as $t) {
            $abbr = $t['abbr'];
            if (!isset($activeSet[$abbr])) continue; // times inativos entram por expansão
            $roster = $playerData[$abbr] ?? [];
            $roster = self::fillRoster($roster);
            // ordena por overall desc para definir titulares e rotação
            usort($roster, fn($a,$b) => $b['ovr'] <=> $a['ovr']);
            $i = 0;
            foreach ($roster as $p) {
                $sta = $p['sta'] ?? self::staminaFor((int) $p['age'], (int) $p['ath']);
                $nbaId = $nbaIds[$p['name']] ?? 0;
                $insPlayer->execute([
                    ':team_id'=>$teamIds[$abbr],
                    ':name'=>$p['name'], ':pos'=>$p['pos'], ':age'=>$p['age'], ':ht'=>$p['ht'],
                    ':ovr'=>$p['ovr'], ':ins'=>$p['ins'], ':mid'=>$p['mid'], ':thr'=>$p['thr'],
                    ':pmk'=>$p['pmk'], ':reb'=>$p['reb'], ':def'=>$p['def'], ':ath'=>$p['ath'], ':sta'=>$sta,
                    ':potential'=>self::potentialFor((int) $p['age'], (int) $p['ovr']),
                    ':seasons_pro'=>max(1, (int) $p['age'] - 19),
                    ':morale'=>random_int(68, 82),
                    ':is_starter'=>($i < 5 ? 1 : 0),
                    ':rotation'=>($i < 8 ? 1 : 0),
                    ':nba_id'=>$nbaId,
                    ':salary'=>Database::salaryForOvr((int) $p['ovr'], (int) $p['age']),
                    ':contract_years'=>Database::contractYearsFor((int) $p['ovr']),
                ]);
                $insStats->execute([(int) $db->lastInsertId()]);
                $playerCount++;
                $i++;
            }
        }

        $games = self::buildSchedule($activeIds);
        $insGame = $db->prepare("INSERT INTO games(day,stage,home_id,away_id) VALUES(?, 'regular', ?, ?)");
        $db->beginTransaction();
        foreach ($games as $g) {
            $insGame->execute([$g['day'], $g['home'], $g['away']]);
        }
        $db->commit();

        Database::setMeta('season', '1');
        Database::setMeta('current_day', '1');
        Database::setMeta('total_days', (string) max(array_column($games, 'day')));
        Database::setMeta('phase', 'regular'); // regular | playoffs | offseason
        Database::setMeta('created_at', date('c'));
        Database::setMeta('era', $eraKey);
        Database::setMeta('era_name', $era['name'] ?? 'Era Atual');
        Database::setMeta('era_start', (string) ($era['start_year'] ?? 2026));
        // cronograma de expansão (ano-calendário => [abbrs que entram])
        Database::setMeta('expansions', json_encode($era['expansions'] ?? []));
        // classes de draft reais por ano-calendário (ex.: 2003 => arquivo do Draft do LeBron)
        Database::setMeta('draft_scripts', json_encode($era['draftclasses'] ?? []));

        return [
            'teams' => count($teams),
            'players' => $playerCount,
            'games' => count($games),
            'days' => max(array_column($games, 'day')),
        ];
    }

    /** Completa um elenco até 13 jogadores garantindo cobertura de posições. */
    private static function fillRoster(array $roster): array
    {
        $target = 13;
        $posNeeded = ['PG','SG','SF','PF','C'];
        // garante ao menos 2 por posição quando possível
        $count = [];
        foreach ($posNeeded as $p) $count[$p] = 0;
        foreach ($roster as $r) { $count[$r['pos']] = ($count[$r['pos']] ?? 0) + 1; }

        while (count($roster) < $target) {
            // escolhe posição mais carente
            asort($count);
            $pos = array_key_first($count);
            $roster[] = self::makeRolePlayer($pos);
            $count[$pos]++;
        }
        return $roster;
    }

    private static function makeRolePlayer(string $pos): array
    {
        $name = trim(self::$firstNames[array_rand(self::$firstNames)]) . ' ' . self::$lastNames[array_rand(self::$lastNames)];
        $base = random_int(62, 76);
        $ht = match ($pos) {
            'PG' => random_int(183, 193),
            'SG' => random_int(193, 198),
            'SF' => random_int(198, 206),
            'PF' => random_int(203, 211),
            'C'  => random_int(208, 216),
            default => 198,
        };
        $bigMan = in_array($pos, ['PF','C']);
        $guard  = in_array($pos, ['PG','SG']);
        $j = fn($lo,$hi) => random_int($lo, $hi);
        $age = $j(19,34); $ath = $j(66,86);
        return [
            'name'=>$name, 'pos'=>$pos, 'age'=>$age, 'ht'=>$ht, 'ovr'=>$base,
            'ins'=>$bigMan ? $base + $j(0,6) : $base - $j(0,8),
            'mid'=>$base - $j(2,8),
            'thr'=>$guard ? $base - $j(0,8) : $base - $j(6,18),
            'pmk'=>$pos==='PG' ? $base + $j(0,6) : $base - $j(6,16),
            'reb'=>$bigMan ? $base + $j(2,10) : $base - $j(4,14),
            'def'=>$base - $j(0,8),
            'ath'=>$ath,
            'sta'=>self::staminaFor($age, $ath),
        ];
    }

    /** Estamina (resistência) derivada da idade e atletismo. */
    private static function staminaFor(int $age, int $ath): int
    {
        $sta = 88 - max(0, $age - 29) * 2 + (int) round(($ath - 75) * 0.2);
        return max(60, min(99, $sta));
    }

    /** Potencial (teto oculto de OVR). Jovens têm folga maior para crescer. */
    public static function potentialFor(int $age, int $ovr): int
    {
        if ($age <= 21)      $head = random_int(2, 12);
        elseif ($age <= 24)  $head = random_int(1, 7);
        elseif ($age <= 28)  $head = random_int(0, 3);
        else                 $head = 0;
        return min(99, $ovr + $head);
    }

    /**
     * Modo Potencial Aleatório: redistribui potenciais de todos os jogadores do save.
     * Chamado após Installer::run() quando potential_type === 'aleatorio'.
     * Jovens têm grande variância (podem ser surpresas ou busts); veteranos ficam no teto.
     */
    public static function randomizePotentials(): void
    {
        $db = Database::conn();
        $players = $db->query("SELECT id, ovr, age FROM players")->fetchAll();
        $upd = $db->prepare("UPDATE players SET potential=? WHERE id=?");
        foreach ($players as $p) {
            $age = (int) $p['age'];
            $ovr = (int) $p['ovr'];
            if ($age <= 22)      $pot = min(99, $ovr + random_int(8, 28));
            elseif ($age <= 25)  $pot = min(99, $ovr + random_int(2, 14));
            elseif ($age <= 28)  $pot = min(99, $ovr + random_int(-4, 6));
            elseif ($age <= 32)  $pot = min(99, $ovr + random_int(-4, 2));
            else                 $pot = $ovr; // veteranos já são o que são
            $upd->execute([$pot, $p['id']]);
        }
    }

    /**
     * Calendário pelo método do círculo: cada time joga 1x por dia.
     * Gera TARGET_DAYS rodadas (82), alternando mando a cada ciclo => 82 jogos/time.
     */
    private const TARGET_DAYS = 82;

    private static function buildSchedule(array $teamIds): array
    {
        $arr = $teamIds;
        $n = count($arr);
        if ($n % 2 !== 0) { $arr[] = 0; $n++; } // bye fictício se ímpar
        $half = $n / 2;
        $games = [];

        $list = $arr;
        for ($day = 1; $day <= self::TARGET_DAYS; $day++) {
            for ($i = 0; $i < $half; $i++) {
                $a = $list[$i];
                $b = $list[$n - 1 - $i];
                if ($a === 0 || $b === 0) continue;
                // alterna o mando a cada dia para equilibrar casa/fora
                if (($day + $i) % 2 === 0) {
                    $games[] = ['day'=>$day, 'home'=>$a, 'away'=>$b];
                } else {
                    $games[] = ['day'=>$day, 'home'=>$b, 'away'=>$a];
                }
            }
            // rotação do método do círculo (primeiro fixo)
            $fixed = array_shift($list);
            $last = array_pop($list);
            array_unshift($list, $last);
            array_unshift($list, $fixed);
        }

        return $games;
    }

    public static function targetDays(): int { return self::TARGET_DAYS; }

    /** Gera um agente livre (role player) para preencher elenco na offseason. */
    public static function makeFreeAgent(string $pos): array
    {
        $p = self::makeRolePlayer($pos);
        $p['potential'] = self::potentialFor((int) $p['age'], (int) $p['ovr']);
        return $p;
    }

    /** Recria o calendário da temporada (usado na virada de temporada). */
    public static function newSeasonSchedule(): int
    {
        $db = Database::conn();
        $ids = array_map('intval', array_column($db->query("SELECT id FROM teams WHERE active=1 ORDER BY id")->fetchAll(), 'id'));
        $games = self::buildSchedule($ids);
        $ins = $db->prepare("INSERT INTO games(day,stage,home_id,away_id) VALUES(?, 'regular', ?, ?)");
        $db->beginTransaction();
        foreach ($games as $g) { $ins->execute([$g['day'], $g['home'], $g['away']]); }
        $db->commit();
        return self::TARGET_DAYS;
    }
}
