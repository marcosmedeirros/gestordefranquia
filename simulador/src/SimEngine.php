<?php
require_once __DIR__ . '/Database.php';

/**
 * Motor de simulação de jogo, baseado em posses.
 * Produz placar final, box score por jogador e play-by-play (simcast).
 */
class SimEngine
{
    // distribuição de minutos da rotação de 8 (Regra dos 8 Maiores) — soma 240 (5x48)
    private const MIN_DIST = [38, 36, 34, 32, 30, 26, 24, 20];

    public static function simulate(int $homeId, int $awayId): array
    {
        $home = self::loadTeam($homeId);
        $away = self::loadTeam($awayId);

        $box = self::emptyBox($home, $away);
        $score = [$homeId => 0, $awayId => 0];
        $pbp = [];

        $ot = 0;
        $period = 0;     // 1..4 = quartos, 5+ = prorrogações
        while (true) {
            $period++;
            if ($period > 4) $ot = $period - 4;
            $evs = self::playPeriod($home, $away, $box, $score, $period);
            foreach ($evs as $ev) $pbp[] = $ev;

            if ($period < 4) continue;             // ainda faltam quartos
            if ($score[$homeId] !== $score[$awayId]) break; // decidido
            if ($period - 4 >= 4) {                 // segurança: máx 4 prorrogações
                $score[$homeId] += random_int(0, 1) === 0 ? 1 : 0;
                if ($score[$homeId] === $score[$awayId]) $score[$homeId]++;
                break;
            }
        }

        $injuries = self::computeInjuries($home, $away, $score, $pbp);

        return [
            'home_pts' => $score[$homeId],
            'away_pts' => $score[$awayId],
            'ot' => $ot,
            'box' => array_values($box),
            'pbp' => $pbp,
            'injuries' => $injuries,
        ];
    }

    /** Box score zerado para os jogadores em quadra dos dois times. */
    private static function emptyBox(array $home, array $away): array
    {
        $box = [];
        foreach ([$home, $away] as $team) {
            foreach ($team['players'] as $p) {
                $box[$p['id']] = self::emptyLine($p['id'], $team['id'], $p['min']);
            }
        }
        return $box;
    }

    /**
     * Simula UM período (12 min de quarto ou 5 min de prorrogação), devolvendo os eventos
     * já anotados com quarto/relógio/placar. Usado tanto na simulação completa quanto ao vivo.
     */
    private static function playPeriod(array &$home, array &$away, array &$box, array &$score, int $period): array
    {
        $homeId = $home['id']; $awayId = $away['id'];
        $isOT = $period > 4;
        $qLabel = $isOT ? ('PR' . ($period - 4)) : ('Q' . $period);
        $clock = $isOT ? 300 : 720;
        $offenseIsHome = (bool) random_int(0, 1);
        $out = [];
        while ($clock > 0) {
            $off = $offenseIsHome ? $home : $away;
            $def = $offenseIsHome ? $away : $home;
            $offId = $off['id'];

            $len = random_int(10, 20) + ($off['mods']['pace'] ?? 0);
            $len = max(7, $len);
            $clock -= $len;
            if ($clock < 0) $clock = 0;

            $events = self::possession($off, $def, $box, $score, $offId, $period);
            foreach ($events as $ev) {
                $ev['q'] = $qLabel;
                $ev['clock'] = self::fmtClock($clock);
                $ev['home_pts'] = $score[$homeId];
                $ev['away_pts'] = $score[$awayId];
                $out[] = $ev;
            }
            $offenseIsHome = !$offenseIsHome;
        }
        return $out;
    }

    /** Sorteio de lesões ao fim do jogo (minutos, idade e estamina). Anota no pbp. */
    private static function computeInjuries(array $home, array $away, array $score, array &$pbp): array
    {
        $injuries = [];
        foreach ([$home, $away] as $team) {
            foreach ($team['players'] as $p) {
                $sta = (int) ($p['sta'] ?? 75);
                $age = (int) ($p['age'] ?? 25);
                $prob = 0.011 * ($p['min'] / 30)
                    * (1 + max(0, $age - 30) * 0.05)
                    * (1 + (85 - $sta) / 100);
                if (self::chance(self::clamp($prob, 0.0, 0.06))) {
                    $games = self::injuryDuration();
                    $injuries[] = ['player_id' => (int) $p['id'], 'games' => $games, 'name' => $p['name']];
                    $pbp[] = ['t' => 'injury', 'team' => (int) $team['id'], 'q' => 'FIM', 'clock' => '',
                        'home_pts' => $score[$home['id']] ?? 0, 'away_pts' => $score[$away['id']] ?? 0,
                        'text' => "⚠️ {$p['name']} deixou o jogo lesionado (fora ~{$games} jogos)"];
                }
            }
        }
        return $injuries;
    }

    // ===================== SIMULAÇÃO AO VIVO (controle do GM) =====================

    /** Inicializa o estado de um jogo ao vivo (período 0, placar zerado). */
    public static function liveInit(int $homeId, int $awayId, string $gmSide): array
    {
        $home = self::loadTeam($homeId);
        $away = self::loadTeam($awayId);
        $box = self::emptyBox($home, $away);
        return [
            'home_id' => $homeId, 'away_id' => $awayId,
            'gm_side' => $gmSide,                 // 'home' ou 'away'
            'period' => 0, 'ot' => 0, 'done' => false,
            'score' => ['home' => 0, 'away' => 0],
            'box' => $box,
            'home' => $home, 'away' => $away,
            'timeouts' => ['home' => 7, 'away' => 7],
            'pbp' => [],
        ];
    }

    /**
     * Avança UM período no jogo ao vivo aplicando os controles do GM ($controls):
     *  - off / def: esquemas escolhidos para o time do GM neste período
     *  - double_team: id do adversário a sofrer marcação dupla
     *  - timeout: usa um tempo (alívio de cansaço + leve bônus de eficiência)
     * Retorna os eventos do período; atualiza $state por referência.
     */
    public static function liveAdvance(array &$state, array $controls): array
    {
        if ($state['done']) return [];
        $gm = $state['gm_side'];                 // 'home'|'away'
        $opp = $gm === 'home' ? 'away' : 'home';

        // reidrata times a partir do estado
        $home = $state['home']; $away = $state['away'];
        $teams = ['home' => &$home, 'away' => &$away];

        // limpa efeitos ao vivo do período anterior
        foreach (['home', 'away'] as $s) {
            $teams[$s]['double_team'] = 0;
            $teams[$s]['fatigue_relief'] = 0.0;
            $teams[$s]['timeout_bonus'] = 0.0;
        }

        // aplica esquemas escolhidos pelo GM
        $off = $controls['off'] ?? null;
        $def = $controls['def'] ?? null;
        if ($off || $def) {
            $teams[$gm]['mods'] = self::schemeMods(
                $off ?: $teams[$gm]['scheme_off'],
                $def ?: $teams[$gm]['scheme_def']
            );
            if ($off) $teams[$gm]['scheme_off'] = $off;
            if ($def) $teams[$gm]['scheme_def'] = $def;
        }

        // marcação dupla na estrela adversária (defesa do GM = afeta o ataque do oponente)
        $dt = (int) ($controls['double_team'] ?? 0);
        if ($dt) $teams[$opp]['double_team'] = $dt;

        // timeout: alívio de cansaço + leve bônus de eficiência neste período
        $usedTimeout = false;
        if (!empty($controls['timeout']) && $state['timeouts'][$gm] > 0) {
            $teams[$gm]['fatigue_relief'] = 1.0;
            $teams[$gm]['timeout_bonus'] = 0.02;
            $state['timeouts'][$gm]--;
            $usedTimeout = true;
        }

        $period = $state['period'] + 1;
        $state['period'] = $period;
        if ($period > 4) $state['ot'] = $period - 4;

        $score = [$home['id'] => $state['score']['home'], $away['id'] => $state['score']['away']];
        $box = $state['box'];

        $evs = [];
        if ($usedTimeout) {
            $evs[] = ['t' => 'timeout', 'team' => $teams[$gm]['id'],
                'q' => $period > 4 ? ('PR' . ($period - 4)) : ('Q' . $period), 'clock' => self::fmtClock($period > 4 ? 300 : 720),
                'home_pts' => $score[$home['id']], 'away_pts' => $score[$away['id']],
                'text' => '⏱️ Tempo técnico! O treinador reorganiza a equipe.'];
        }
        $evs = array_merge($evs, self::playPeriod($home, $away, $box, $score, $period));

        // persiste de volta no estado
        $state['score'] = ['home' => $score[$home['id']], 'away' => $score[$away['id']]];
        $state['box'] = $box;
        $state['home'] = $home; $state['away'] = $away;
        foreach ($evs as $ev) $state['pbp'][] = $ev;

        // fim de jogo?
        $tied = $score[$home['id']] === $score[$away['id']];
        if ($period >= 4 && (!$tied || $period - 4 >= 4)) {
            if ($tied) { // trava de segurança
                $score[$home['id']]++;
                $state['score']['home'] = $score[$home['id']];
            }
            $state['done'] = true;
        }
        return $evs;
    }

    /** Converte o estado ao vivo finalizado no formato de resultado usado pela League. */
    public static function liveResult(array $state): array
    {
        $pbp = $state['pbp'];
        $injuries = self::computeInjuries($state['home'], $state['away'],
            [$state['home']['id'] => $state['score']['home'], $state['away']['id'] => $state['score']['away']], $pbp);
        return [
            'home_pts' => $state['score']['home'],
            'away_pts' => $state['score']['away'],
            'ot' => $state['ot'],
            'box' => array_values($state['box']),
            'pbp' => $pbp,
            'injuries' => $injuries,
        ];
    }

    private static function injuryDuration(): int
    {
        $r = mt_rand() / mt_getrandmax();
        if ($r < 0.55) return random_int(3, 6);    // leve
        if ($r < 0.85) return random_int(7, 12);   // média
        return random_int(13, 20);                 // grave
    }

    private static function possession(array $off, array $def, array &$box, array &$score, int $offId, int $period = 1): array
    {
        $events = [];
        $defRating = $def['def_rating'];
        $om = $off['mods']; $dm = $def['mods'];
        $om['eff_bonus'] += (float) ($off['timeout_bonus'] ?? 0.0);
        $offChem = $off['chem'];

        // escolhe o jogador que finaliza a posse (usage)
        $actor = self::weightedPick($off['players'], 'usage');
        // marcação dupla (ao vivo): tira a bola da estrela adversária em parte das posses
        $dt = (int) ($def['double_team'] ?? 0);
        if ($dt && (int) $actor['id'] === $dt && self::chance(0.55)) {
            $alt = self::weightedPick($off['players'], 'usage', $dt);
            if ($alt) $actor = $alt;
        }
        $b = &$box[$actor['id']];

        // cansaço: nos minutos finais (Q4/PR) jogadores de baixa estamina caem de rendimento.
        // O timeout (ao vivo) dá alívio de cansaço para o time atacante naquele período.
        $relief = (float) ($off['fatigue_relief'] ?? 0.0);
        $fatigue = max(0, $period - 3) * (1 - ($actor['sta'] ?? 75) / 100) * 0.06 * (1 - $relief);
        // ajuste de moral no rendimento ofensivo do finalizador
        $morAdj = ($actor['mor_adj'] ?? 0);
        // marcação dupla na estrela: ela rende menos quando ainda finaliza
        if ($dt && (int) $actor['id'] === $dt) $morAdj -= 8;

        // turnover?
        $toProb = 0.125 + (0.5 - $actor['pmk'] / 200) * 0.10 + ($defRating - 75) / 100 * 0.06
                + $fatigue * 0.4 + $dm['def_assist'] * -0.3;
        if (self::chance(self::clamp($toProb, 0.06, 0.24))) {
            $b['tov']++;
            // metade vira roubo de bola
            if (self::chance(0.5)) {
                $stealer = self::weightedPick($def['players'], 'def');
                $box[$stealer['id']]['stl']++;
                $events[] = ['t' => 'turnover', 'team' => $offId, 'text' =>
                    "{$actor['name']} perde a bola — roubada de {$stealer['name']}"];
            } else {
                $events[] = ['t' => 'turnover', 'team' => $offId, 'text' => "Turnover de {$actor['name']}"];
            }
            return $events;
        }

        // tipo de arremesso (esquema ofensivo influencia a escolha por 3pts)
        $threeTend = self::clamp(($actor['thr'] - 45) / 100, 0.05, 0.62) * $om['three_mult'];
        $isThree = self::chance(self::clamp($threeTend * 0.75, 0.02, 0.8));

        // probabilidade de cesta
        if ($isThree) {
            $makeP = 0.355 + ($actor['thr'] + $morAdj - 75) / 100 * 0.45 - ($defRating - 75) / 100 * 0.07;
            $makeP += $om['eff_bonus'] + $offChem + $dm['def_3pt_allow'] - $fatigue;
            $makeP = self::clamp($makeP, 0.20, 0.52);
            $b['tpa']++; $b['fga']++;
        } else {
            $skill = max($actor['ins'], $actor['mid']) + $morAdj;
            $makeP = 0.49 + ($skill - 75) / 100 * 0.45 - ($defRating - 75) / 100 * 0.08;
            $makeP += $om['eff_bonus'] + $om['inside_bonus'] + $offChem + $dm['def_2pt'] - $fatigue;
            $makeP = self::clamp($makeP, 0.32, 0.68);
            $b['fga']++;
        }

        // falta de arremesso?
        $foulProb = $isThree ? 0.05 : (0.10 + $actor['ath'] / 100 * 0.06);
        $fouled = self::chance($foulProb);

        if (self::chance($makeP)) {
            // CESTA
            $pts = $isThree ? 3 : 2;
            $b['pts'] += $pts; $b['fgm']++;
            if ($isThree) $b['tpm']++;
            $score[$offId] += $pts;

            // assistência? (esquema P&R aumenta; defesa "Switch All" reduz)
            $astP = ($isThree ? 0.80 : 0.55) + $om['assist_bonus'] + $dm['def_assist'];
            $astP = self::clamp($astP, 0.2, 0.95);
            $assistText = '';
            if (self::chance($astP)) {
                $passer = self::weightedPick($off['players'], 'pmk', $actor['id']);
                if ($passer) {
                    $box[$passer['id']]['ast']++;
                    $assistText = " (assist. {$passer['name']})";
                }
            }
            $shotName = $isThree ? 'cesta de 3!' : 'cesta de 2';
            $events[] = ['t' => 'made', 'team' => $offId, 'pts' => $pts, 'text' =>
                "{$actor['name']} {$shotName}{$assistText}"];

            // and-1
            if ($fouled && self::chance(0.6)) {
                $b['fta']++;
                if (self::chance(self::ftPct($actor))) { $b['ftm']++; $b['pts']++; $score[$offId]++;
                    $events[] = ['t' => 'ft', 'team' => $offId, 'pts' => 1, 'text' => "{$actor['name']} converte o lance livre adicional (and-1)"];
                } else {
                    $events[] = ['t' => 'ftmiss', 'team' => $offId, 'text' => "{$actor['name']} perde o lance livre adicional"];
                }
            }
            return $events;
        }

        // ERROU — bloqueio?
        if (self::chance(0.055 + ($defRating - 75) / 100 * 0.04)) {
            $blocker = self::weightedPick($def['players'], 'def');
            $box[$blocker['id']]['blk']++;
            $events[] = ['t' => 'block', 'team' => $offId, 'text' =>
                "{$actor['name']} tem o arremesso bloqueado por {$blocker['name']}!"];
            // rebote após bloqueio
            $events = array_merge($events, self::rebound($off, $def, $box, $offId));
            return $events;
        }

        // falta no arremesso errado -> lances livres
        if ($fouled) {
            $shots = $isThree ? 3 : 2;
            $made = 0;
            for ($i = 0; $i < $shots; $i++) {
                $b['fta']++;
                if (self::chance(self::ftPct($actor))) { $b['ftm']++; $made++; $b['pts']++; $score[$offId]++; }
            }
            $events[] = ['t' => 'ft', 'team' => $offId, 'pts' => $made, 'text' =>
                "{$actor['name']} sofre falta e converte {$made}/{$shots} lances livres"];
            if ($made < $shots) {
                $events = array_merge($events, self::rebound($off, $def, $box, $offId, true));
            }
            return $events;
        }

        // arremesso errado normal
        $events[] = ['t' => 'miss', 'team' => $offId, 'text' =>
            "{$actor['name']} erra " . ($isThree ? 'o arremesso de 3' : 'o arremesso')];
        $events = array_merge($events, self::rebound($off, $def, $box, $offId));
        return $events;
    }

    /** Resolve o rebote. Em rebote ofensivo, registra mas a posse encerra (simplificação). */
    private static function rebound(array $off, array $def, array &$box, int $offId, bool $ftMiss = false): array
    {
        $oRebProb = $ftMiss ? 0.14 : 0.26;
        if (self::chance($oRebProb)) {
            $reb = self::weightedPick($off['players'], 'reb');
            $box[$reb['id']]['reb']++;
            return [['t' => 'oreb', 'team' => $offId, 'text' => "Rebote ofensivo de {$reb['name']}"]];
        }
        $reb = self::weightedPick($def['players'], 'reb');
        $box[$reb['id']]['reb']++;
        return [['t' => 'dreb', 'team' => $def['id'], 'text' => "Rebote defensivo de {$reb['name']}"]];
    }

    // ---------- helpers ----------

    private static function ftPct(array $p): float
    {
        return self::clamp(0.62 + ($p['mid'] - 60) / 200, 0.5, 0.92);
    }

    private static function weightedPick(array $players, string $attr, ?int $exclude = null): ?array
    {
        $pool = [];
        $total = 0.0;
        foreach ($players as $p) {
            if ($exclude !== null && $p['id'] === $exclude) continue;
            $w = match ($attr) {
                'usage' => $p['usage'],
                'pmk' => pow($p['pmk'], 2.0),
                'reb' => pow($p['reb'], 2.2),
                'def' => pow($p['def'] * 0.6 + $p['ath'] * 0.4, 2.0),
                default => $p['ovr'],
            };
            $w = max(0.01, $w);
            $pool[] = [$p, $w];
            $total += $w;
        }
        if (!$pool) return null;
        $r = mt_rand() / mt_getrandmax() * $total;
        foreach ($pool as [$p, $w]) {
            $r -= $w;
            if ($r <= 0) return $p;
        }
        return $pool[array_key_last($pool)][0];
    }

    private static function loadTeam(int $teamId): array
    {
        $db = Database::conn();
        $t = $db->prepare("SELECT * FROM teams WHERE id = ?");
        $t->execute([$teamId]);
        $team = $t->fetch() ?: [];

        // jogadores DISPONÍVEIS (sem lesão, não poupados e ativos), melhores 8 = rotação
        $st = $db->prepare("SELECT * FROM players WHERE team_id = ? AND injury_games = 0 AND rest_games = 0 AND retired = 0
                            ORDER BY is_starter DESC, ovr DESC");
        $st->execute([$teamId]);
        $players = array_slice($st->fetchAll(), 0, 8);
        if (count($players) < 5) { // fallback de emergência (muitas lesões): inclui qualquer ativo
            $st = $db->prepare("SELECT * FROM players WHERE team_id = ? AND retired = 0 ORDER BY ovr DESC LIMIT 8");
            $st->execute([$teamId]);
            $players = $st->fetchAll();
        }

        // minutagem manual (GM) tem prioridade; senão usa a distribuição padrão
        $customSum = 0;
        foreach ($players as $p) { $customSum += (int) ($p['min_target'] ?? 0); }
        $minutes = self::MIN_DIST;
        if ($customSum > 0) {
            $scale = 240 / $customSum;
            $minutes = [];
            foreach ($players as $p) {
                $mt = (int) ($p['min_target'] ?? 0);
                $minutes[] = $mt > 0 ? round($mt * $scale, 1) : 0;
            }
        }

        $defSum = 0; $n = 0;
        $i = 0;
        foreach ($players as &$p) {
            $p['min'] = $minutes[$i] ?? 8;
            // ajuste de moral: insatisfeito rende menos, satisfeito um pouco mais
            $p['mor_adj'] = (($p['morale'] ?? 75) - 75) * 0.15;
            $offSkill = ($p['ovr'] * 0.6 + max($p['ins'], $p['mid'], $p['thr']) * 0.4) + $p['mor_adj'];
            $p['usage'] = $p['min'] * (0.4 + ($offSkill - 60) / 40);
            $defSum += $p['def']; $n++;
            $i++;
        }
        unset($p);

        $mods = self::schemeMods($team['scheme_off'] ?? 'Pace and Space', $team['scheme_def'] ?? 'Man-to-Man');
        // química: 70 = neutro; cada ponto acima/abaixo dá pequeno bônus/penalidade
        $chem = (($team['chemistry'] ?? 70) - 70) / 100 * 0.05; // ~±0.015

        return [
            'id' => $teamId,
            'players' => $players,
            'def_rating' => $n ? $defSum / $n : 75,
            'scheme_off' => $team['scheme_off'] ?? 'Pace and Space',
            'scheme_def' => $team['scheme_def'] ?? 'Man-to-Man',
            'mods' => $mods,
            'chem' => $chem,
        ];
    }

    /** Modificadores táticos dos esquemas ofensivo/defensivo. */
    private static function schemeMods(string $off, string $def): array
    {
        $m = [
            'three_mult' => 1.0, 'eff_bonus' => 0.0, 'assist_bonus' => 0.0, 'pace' => 0,
            'reb_bonus' => 0.0, 'inside_bonus' => 0.0,
            'def_2pt' => 0.0, 'def_3pt_allow' => 0.0, 'def_assist' => 0.0,
        ];
        switch ($off) {
            case 'Pace and Space':           $m['three_mult'] = 1.35; $m['pace'] = -2; break;
            case 'Pick and Roll Offense':    $m['assist_bonus'] = 0.06; $m['eff_bonus'] = 0.012; break;
            case 'Post Play / Grit and Grind': $m['three_mult'] = 0.6; $m['inside_bonus'] = 0.025; $m['pace'] = 3; $m['reb_bonus'] = 0.04; break;
        }
        switch ($def) {
            case '2-3 Zone':   $m['def_2pt'] = -0.045; $m['def_3pt_allow'] = 0.030; break;
            case 'Switch All': $m['def_assist'] = -0.06; $m['def_2pt'] = 0.010; break;
            // Man-to-Man = neutro
        }
        return $m;
    }

    private static function emptyLine(int $pid, int $tid, float $min): array
    {
        return ['player_id'=>$pid,'team_id'=>$tid,'min'=>$min,'pts'=>0,'reb'=>0,'ast'=>0,
            'stl'=>0,'blk'=>0,'tov'=>0,'fgm'=>0,'fga'=>0,'tpm'=>0,'tpa'=>0,'ftm'=>0,'fta'=>0];
    }

    private static function chance(float $p): bool
    {
        return (mt_rand() / mt_getrandmax()) < $p;
    }

    private static function clamp(float $v, float $lo, float $hi): float
    {
        return max($lo, min($hi, $v));
    }

    private static function fmtClock(int $secs): string
    {
        $m = intdiv($secs, 60);
        $s = $secs % 60;
        return sprintf('%d:%02d', $m, $s);
    }

    private static function lastQuarterNum(array $pbp): int
    {
        for ($i = count($pbp) - 1; $i >= 0; $i--) {
            if (preg_match('/^Q(\d)/', $pbp[$i]['q'], $m)) return (int) $m[1];
        }
        return 1;
    }
}
