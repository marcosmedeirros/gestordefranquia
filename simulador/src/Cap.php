<?php
require_once __DIR__ . '/Database.php';

/**
 * SALARY CAP — as regras da FBA ELITE dentro do simulador.
 *
 * O salário de todo jogador vem do OVR (tabela da ELITE), o calouro entra pela
 * rookie scale no primeiro ano, prêmio individual vira bônus na temporada
 * seguinte, e quem foi draftado pelo próprio time e virou 85+ soma Cap Flex no
 * teto. A folha é a soma dos salários; o time precisa ficar entre o piso e o
 * teto até a Trade Deadline. Troca segue a regra dos 120% (pick vale 5M/2M).
 *
 * Teto e piso são CALIBRADOS por save (ver calibrate): na ELITE o teto é 150M
 * para uma folha média de 126M; o simulador usa elencos reais, bem mais
 * concentrados em poucas estrelas, então a folha média muda por era. A
 * proporção é mantida para o teto apertar do mesmo jeito.
 *
 * Nada aqui usa teto rígido/imposto de luxo — isso saiu com o sistema antigo.
 */
class Cap
{
    public const M = 1000000;                 // salários gravados em dólares; regras em milhões
    public const TRADE_MATCH_PCT = 120;       // nenhum lado recebe mais de 120% do que envia
    public const PICK_VALUE = [1 => 5, 2 => 2]; // peso da pick (M) no casamento salarial
    public const ROSTER_MIN = 13;
    public const ROSTER_MAX = 15;
    public const FLEX_MAX_PLAYERS = 2;
    public const VETERAN_MIN = 2;             // OVR 77 ou menos
    public const DEADLINE_PCT = 0.60;         // a deadline cai em 60% da temporada regular
    // proporções da ELITE (teto 150 / piso 90 sobre folha média 126) suavizadas para o sim,
    // cujos elencos reais deixam mais times longe da média.
    public const CAP_RATIO = 1.35;
    public const FLOOR_RATIO = 0.70;

    /* ─── tabelas (as mesmas do app da FBA) ─────────────────────────────── */

    public static function ovrTable(): array
    {
        return [99 => 60, 98 => 56, 97 => 52, 96 => 48, 95 => 44, 94 => 40, 93 => 36,
                92 => 32, 91 => 29, 90 => 26, 89 => 23, 88 => 20, 87 => 18, 86 => 16,
                85 => 14, 84 => 12, 83 => 10, 82 => 8, 81 => 6, 80 => 5, 79 => 4, 78 => 3];
    }

    /** Salário pela tabela de OVR, em milhões. */
    public static function ovrSalary(int $ovr): int
    {
        if ($ovr >= 99) return 60;
        if ($ovr <= 77) return self::VETERAN_MIN;
        return self::ovrTable()[$ovr] ?? self::VETERAN_MIN;
    }

    /** Rookie scale (milhões): 1ª rodada pela posição, 2ª rodada sempre 2M. */
    public static function rookieScale(int $round, int $pos): int
    {
        if ($round >= 2 || $pos <= 0) return 2;
        if ($pos <= 3)  return 18;
        if ($pos <= 8)  return 14;
        if ($pos <= 12) return 12;
        if ($pos <= 16) return 8;
        if ($pos <= 22) return 5;
        return 3;
    }

    /** Bônus por prêmio (milhões), válido só na temporada seguinte. */
    public static function awardBonusTable(): array
    {
        return ['MVP' => 5, 'DPOY' => 3, 'ROY' => 2, 'Finals MVP' => 3, 'MIP' => 2, '6º Homem' => 2,
                'All-NBA 1' => 3, 'All-NBA 2' => 2, 'All-NBA 3' => 1];
    }

    /** Cap Flex de um jogador: só no time que o draftou, e só de 85 pra cima. */
    public static function flexOf(array $p): int
    {
        $by = $p['drafted_by'] ?? null;
        if ($by === null || (int) $by === 0 || (int) $by !== (int) ($p['team_id'] ?? 0)) return 0;
        $ovr = (int) $p['ovr'];
        if ($ovr >= 93) return 8;
        if ($ovr >= 90) return 5;
        if ($ovr >= 85) return 3;
        return 0;
    }

    /**
     * Salário do jogador em milhões: rookie scale no primeiro ano (seasons_pro 0
     * e pick registrada), senão a tabela por OVR; mais o bônus de prêmio.
     */
    public static function playerSalaryM(array $p): int
    {
        $base = self::ovrSalary((int) $p['ovr']);
        if ((int) ($p['seasons_pro'] ?? 1) === 0 && (int) ($p['draft_round'] ?? 0) > 0) {
            $base = self::rookieScale((int) $p['draft_round'], (int) ($p['draft_pos'] ?? 0));
        }
        return $base + (int) ($p['award_bonus'] ?? 0);
    }

    /** De onde vem o salário, pra tela: 'rookie' | 'ovr' (+ bônus separado). */
    public static function salarySource(array $p): string
    {
        return ((int) ($p['seasons_pro'] ?? 1) === 0 && (int) ($p['draft_round'] ?? 0) > 0) ? 'rookie' : 'ovr';
    }

    /** Regrava players.salary de todo mundo (ou de um time) a partir do OVR atual. */
    public static function refreshSalaries(?int $teamId = null): void
    {
        $db = Database::conn();
        $sql = "SELECT id, ovr, seasons_pro, draft_round, draft_pos, award_bonus FROM players WHERE retired=0";
        $params = [];
        if ($teamId !== null) { $sql .= " AND team_id=?"; $params[] = $teamId; }
        $st = $db->prepare($sql);
        $st->execute($params);
        $upd = $db->prepare("UPDATE players SET salary=? WHERE id=?");
        foreach ($st->fetchAll() as $p) {
            $upd->execute([self::playerSalaryM($p) * self::M, (int) $p['id']]);
        }
    }

    /* ─── teto e piso do save ───────────────────────────────────────────── */

    /**
     * Define teto e piso a partir da folha média da liga (só na instalação, ou
     * uma vez em save antigo). Arredonda a 5M. Nunca deixa o teto abaixo de
     * 60M nem o piso abaixo de 30M.
     */
    public static function calibrate(): array
    {
        $db = Database::conn();
        $rows = $db->query("SELECT COALESCE(SUM(p.salary),0) AS pay FROM teams t
                            LEFT JOIN players p ON p.team_id=t.id AND p.retired=0
                            WHERE t.active=1 GROUP BY t.id")->fetchAll();
        $avg = $rows ? array_sum(array_column($rows, 'pay')) / count($rows) / self::M : 100;
        $capM   = max(60, (int) (round($avg * self::CAP_RATIO / 5) * 5), self::starRoomNeedM());
        $floorM = max(30, (int) (round($avg * self::FLOOR_RATIO / 5) * 5));
        Database::setMeta('cap_max_m', (string) $capM);
        Database::setMeta('cap_star_room', (string) (int) Database::meta('season', 1));
        Database::setMeta('cap_floor_m', (string) $floorM);
        Database::setMeta('cap_avg_m', (string) round($avg));
        return ['cap' => $capM, 'floor' => $floorM, 'avg' => round($avg)];
    }

    /** Teto base do save, em dólares (sem Cap Flex). */
    public static function max(): int
    {
        $v = (int) Database::meta('cap_max_m', 0);
        if ($v <= 0) $v = self::calibrate()['cap'];
        elseif (!Database::meta('cap_star_room')) $v = self::ensureStarRoom(); // save de antes da regra: uma vez
        return $v * self::M;
    }

    /**
     * Teto mínimo (em milhões) para caber o maior salário da liga com um elenco mínimo
     * e mais uma vaga de salário mínimo. A tabela de salário por OVR é a mesma em toda
     * era, mas o teto é calibrado pela folha média, e nas eras de folha baixa o craque
     * sozinho mais 12 mínimos passava do teto (Jordan em 1997: 60M + 24M contra 60M) —
     * o time travava e a única saída era perder o craque.
     */
    private static function starRoomNeedM(): int
    {
        $maxSal = (int) Database::conn()->query("SELECT COALESCE(MAX(salary), 0) FROM players WHERE retired=0 AND team_id IS NOT NULL")->fetchColumn();
        $minM = self::ovrSalary(62);
        return (int) (ceil((ceil($maxSal / self::M) + self::ROSTER_MIN * $minM) / 5) * 5);
    }

    /** Sobe o teto do save se ele não cabe mais o maior salário (nunca desce). Devolve o teto em milhões. */
    public static function ensureStarRoom(): int
    {
        $cur = (int) Database::meta('cap_max_m', 0);
        $need = self::starRoomNeedM();
        if ($need > $cur) Database::setMeta('cap_max_m', (string) $need);
        Database::setMeta('cap_star_room', (string) (int) Database::meta('season', 1));
        return max($need, $cur);
    }

    /** Piso da folha, em dólares. */
    public static function floor(): int
    {
        $v = (int) Database::meta('cap_floor_m', 0);
        if ($v <= 0) $v = self::calibrate()['floor'];
        return $v * self::M;
    }

    /* ─── resumo por time ───────────────────────────────────────────────── */

    /**
     * Folha, Cap Flex, teto do time, piso, espaço e status ('ok' | 'over' | 'under'),
     * mais o elenco linha a linha com a origem de cada salário.
     */
    public static function summary(int $teamId): array
    {
        $st = Database::conn()->prepare(
            "SELECT id, name, pos, age, ovr, potential, salary, contract_years, seasons_pro, draft_round, draft_pos,
                    drafted_by, award_bonus, team_id, injury_games
             FROM players WHERE team_id=? AND retired=0 ORDER BY salary DESC, ovr DESC");
        $st->execute([$teamId]);
        $roster = $st->fetchAll();

        $payroll = 0;
        $flexCands = [];
        foreach ($roster as $i => &$p) {
            $payroll += (int) $p['salary'];
            $p['salary_m'] = (int) round($p['salary'] / self::M);
            $p['source'] = self::salarySource($p);
            $p['bonus_m'] = (int) ($p['award_bonus'] ?? 0);
            $p['flex'] = self::flexOf($p);
            $p['flex_counted'] = false;
            if ($p['flex'] > 0) $flexCands[$i] = $p['flex'] * 100 + (int) $p['ovr'];
        }
        unset($p);
        arsort($flexCands);
        $flexTotal = 0; $n = 0;
        foreach ($flexCands as $i => $_) {
            if ($n >= self::FLEX_MAX_PLAYERS) break;
            $roster[$i]['flex_counted'] = true;
            $flexTotal += $roster[$i]['flex'];
            $n++;
        }

        $base = self::max();
        $capMax = $base + $flexTotal * self::M;
        $floor = self::floor();
        $status = 'ok';
        if ($payroll > $capMax) $status = 'over';
        elseif ($payroll < $floor) $status = 'under';

        return [
            'team_id' => $teamId, 'payroll' => $payroll, 'cap_base' => $base,
            'flex_total' => $flexTotal, 'cap_max' => $capMax, 'floor' => $floor,
            'space' => $capMax - $payroll, 'status' => $status,
            'excess' => max(0, $payroll - $capMax), 'deficit' => max(0, $floor - $payroll),
            'count' => count($roster), 'roster' => $roster,
        ];
    }

    /** Folha (dólares) de um time. */
    public static function payroll(int $teamId): int
    {
        $st = Database::conn()->prepare("SELECT COALESCE(SUM(salary),0) FROM players WHERE team_id=? AND retired=0");
        $st->execute([$teamId]);
        return (int) $st->fetchColumn();
    }

    /** O time consegue absorver este salário (dólares) sem estourar o teto? */
    public static function fits(int $teamId, int $salary): bool
    {
        $s = self::summary($teamId);
        return $salary <= max(0, $s['space']);
    }

    /** Tabela da liga: folha, teto, espaço e status de cada time, ordenada por folha. */
    public static function leagueTable(): array
    {
        $rows = [];
        foreach (League::allTeams() as $t) {
            $s = self::summary((int) $t['id']);
            $rows[] = ['id' => (int) $t['id'], 'abbr' => $t['abbr'], 'city' => $t['city'], 'name' => $t['name'],
                'conf' => $t['conf'], 'color' => $t['primary_color'], 'payroll' => $s['payroll'],
                'cap_max' => $s['cap_max'], 'flex' => $s['flex_total'], 'space' => $s['space'],
                'status' => $s['status'], 'count' => $s['count']];
        }
        usort($rows, fn($a, $b) => $b['payroll'] <=> $a['payroll']);
        return $rows;
    }

    /* ─── trade deadline ────────────────────────────────────────────────── */

    public static function deadlineDay(): int
    {
        $total = League::totalDays() ?: 82;
        return max(2, (int) round($total * self::DEADLINE_PCT));
    }

    /** Trocas abertas? Pré-temporada, free agency e temporada regular até a deadline. */
    public static function tradesOpen(): bool
    {
        $phase = League::phase();
        if (in_array($phase, ['preseason', 'freeagency'], true)) return true;
        if ($phase === 'regular') return League::currentDay() <= self::deadlineDay();
        return false;
    }

    /** Assinar agente livre é permitido? (não nos playoffs nem na entressafra fechada) */
    public static function signingOpen(): bool
    {
        return in_array(League::phase(), ['preseason', 'freeagency', 'regular'], true);
    }

    /* ─── casamento salarial da troca (regra dos 120%) ──────────────────── */

    /**
     * Checa uma troca entre A e B. $playersX/$picksX = o que X ENVIA.
     * Regras: cada lado recebe no máximo 120% do que envia (pick conta nos dois
     * lados); time acima do teto não pode sair da troca com folha maior; time
     * dentro do teto não pode estourá-lo; elencos entre 13 e 15.
     */
    public static function tradeCheck(int $teamA, array $playersA, array $picksA, int $teamB, array $playersB, array $picksB): array
    {
        $salA = 0; foreach ($playersA as $p) $salA += (int) $p['salary'];
        $salB = 0; foreach ($playersB as $p) $salB += (int) $p['salary'];
        $pkA = 0; foreach ($picksA as $pk) $pkA += (self::PICK_VALUE[(int) $pk['round']] ?? 0) * self::M;
        $pkB = 0; foreach ($picksB as $pk) $pkB += (self::PICK_VALUE[(int) $pk['round']] ?? 0) * self::M;
        $sendA = $salA + $pkA; $sendB = $salB + $pkB;
        $pct = self::TRADE_MATCH_PCT / 100;

        $sA = self::summary($teamA); $sB = self::summary($teamB);
        $afterA = $sA['payroll'] - $salA + $salB;
        $afterB = $sB['payroll'] - $salB + $salA;
        $cntA = $sA['count'] - count($playersA) + count($playersB);
        $cntB = $sB['count'] - count($playersB) + count($playersA);

        $tA = League::team($teamA); $tB = League::team($teamB);
        $errors = [];
        $limA = (int) floor($sendA * $pct); // o máximo que A pode receber
        $limB = (int) floor($sendB * $pct);
        if ($sendB > $limA) {
            $errors[] = "{$tA['abbr']} recebe " . self::m($sendB) . " enviando " . self::m($sendA)
                . " — o limite é " . self::m($limA) . " (120%). Precisa enviar pelo menos " . self::m((int) ceil($sendB / $pct)) . ".";
        }
        if ($sendA > $limB) {
            $errors[] = "{$tB['abbr']} recebe " . self::m($sendA) . " enviando " . self::m($sendB)
                . " — o limite é " . self::m($limB) . " (120%).";
        }
        if ($afterA > $sA['cap_max'] && $afterA > $sA['payroll']) {
            $errors[] = "{$tA['abbr']} sairia da troca com folha de " . self::m($afterA) . ", acima do teto de " . self::m($sA['cap_max']) . ".";
        }
        if ($afterB > $sB['cap_max'] && $afterB > $sB['payroll']) {
            $errors[] = "{$tB['abbr']} sairia da troca com folha de " . self::m($afterB) . ", acima do teto de " . self::m($sB['cap_max']) . ".";
        }
        if ($cntA < self::ROSTER_MIN || $cntA > self::ROSTER_MAX) $errors[] = "{$tA['abbr']} ficaria com {$cntA} jogadores (o elenco é de " . self::ROSTER_MIN . " a " . self::ROSTER_MAX . ").";
        if ($cntB < self::ROSTER_MIN || $cntB > self::ROSTER_MAX) $errors[] = "{$tB['abbr']} ficaria com {$cntB} jogadores (o elenco é de " . self::ROSTER_MIN . " a " . self::ROSTER_MAX . ").";

        return [
            'ok' => !$errors, 'errors' => $errors,
            'a' => ['send' => $sendA, 'recv' => $sendB, 'limit' => $limA, 'before' => $sA['payroll'], 'after' => $afterA, 'cap' => $sA['cap_max'], 'count' => $cntA],
            'b' => ['send' => $sendB, 'recv' => $sendA, 'limit' => $limB, 'before' => $sB['payroll'], 'after' => $afterB, 'cap' => $sB['cap_max'], 'count' => $cntB],
        ];
    }

    /** "$12.0M" a partir de dólares. */
    public static function m(int $v): string
    {
        return '$' . number_format($v / self::M, 1) . 'M';
    }

    /* ─── dispensa ──────────────────────────────────────────────────────── */

    /**
     * Dispensa um jogador: vira agente livre (qualquer time com espaço pode
     * assinar). O salário sai da folha na hora. Elenco não fica abaixo de 13.
     */
    public static function release(int $teamId, int $playerId, string $by = 'ia'): array
    {
        $db = Database::conn();
        $st = $db->prepare("SELECT * FROM players WHERE id=? AND team_id=? AND retired=0");
        $st->execute([$playerId, $teamId]);
        $p = $st->fetch();
        if (!$p) return ['error' => 'Jogador não está no elenco.'];
        $cnt = (int) $db->query("SELECT COUNT(*) FROM players WHERE team_id=$teamId AND retired=0")->fetchColumn();
        $db->prepare("UPDATE players SET team_id=NULL, is_starter=0, rotation=0, min_target=0, morale=60 WHERE id=?")->execute([$playerId]);
        $t = League::team($teamId);
        $desc = "{$t['abbr']} dispensa {$p['name']} ({$p['pos']}, OVR {$p['ovr']}, " . self::m((int) $p['salary']) . ")";
        // Elenco no mínimo: a liga completa com um jogador de salário mínimo (2M),
        // igual faz com a IA — senão time com 13 e acima do teto ficaria sem saída.
        $filled = false;
        if ($cnt - 1 < self::ROSTER_MIN) { self::fillRosterMin($teamId); $filled = true; $desc .= ' — elenco completado com um jogador de salário mínimo'; }
        $db->prepare("INSERT INTO transactions(season,day,type,description) VALUES(?,?,'dispensa',?)")
           ->execute([League::season(), League::currentDay(), $desc]);
        if ($by === 'gm') {
            $db->prepare("UPDATE teams SET chemistry = MAX(50, chemistry - 2) WHERE id=?")->execute([$teamId]);
            League::inboxAdd('news', 'Imprensa', "{$p['name']} dispensado",
                "Você dispensou {$p['name']} ({$p['pos']}, OVR {$p['ovr']}). Ele entra na lista de agentes livres e outro time pode assiná-lo."
                . ($filled ? ' Como o elenco estava no mínimo, a liga completou a vaga com um jogador de salário mínimo (2M).' : ''), url('cap'), '🚪', false);
        }
        return ['ok' => true, 'player' => $p, 'desc' => $desc, 'filled' => $filled];
    }

    /** Completa o elenco até 13 com jogadores de salário mínimo. */
    public static function fillRosterMin(int $teamId): void
    {
        require_once __DIR__ . '/Installer.php';
        $db = Database::conn();
        $insP = $db->prepare("INSERT INTO players
            (team_id,name,pos,age,ht,ovr,ins,mid,thr,pmk,reb,def,ath,sta,potential,seasons_pro,morale,is_starter,rotation,salary,contract_years)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,72,0,0,?,?)");
        $insStat = $db->prepare("INSERT INTO season_stats(player_id) VALUES(?)");
        $cnt = (int) $db->query("SELECT COUNT(*) FROM players WHERE team_id=$teamId AND retired=0")->fetchColumn();
        while ($cnt < self::ROSTER_MIN) {
            $posCount = [];
            foreach (['PG','SG','SF','PF','C'] as $pp) {
                $posCount[$pp] = (int) $db->query("SELECT COUNT(*) FROM players WHERE team_id=$teamId AND retired=0 AND pos='$pp'")->fetchColumn();
            }
            asort($posCount);
            $fa = Installer::makeFreeAgent(array_key_first($posCount));
            $ovr = min(76, (int) $fa['ovr']); // mínimo de veterano
            $insP->execute([$teamId, $fa['name'], $fa['pos'], $fa['age'], $fa['ht'], $ovr,
                $fa['ins'], $fa['mid'], $fa['thr'], $fa['pmk'], $fa['reb'], $fa['def'], $fa['ath'], $fa['sta'],
                $fa['potential'], max(1, (int) $fa['age'] - 19), self::ovrSalary($ovr) * self::M, Database::contractYearsFor($ovr)]);
            $insStat->execute([(int) $db->lastInsertId()]);
            $cnt++;
        }
    }

    /* ─── a IA se adequando ao teto ─────────────────────────────────────── */

    /**
     * Cada time SEM GM fora do teto/piso faz movimentos: acima do teto tenta
     * uma troca que reduza a folha (dentro dos 120%) e, se não achar, dispensa
     * quem custa mais e vale menos; abaixo do piso assina o melhor agente livre
     * que cabe. $force = até ficar regular (deadline); senão $movesPerTeam por passe.
     * Devolve os eventos (já registrados em transações; os relevantes vão pra caixa).
     */
    public static function aiEnforce(bool $force = false, int $movesPerTeam = 1): array
    {
        $events = [];
        $gm = League::gmTeam();
        foreach (League::allTeams() as $t) {
            $tid = (int) $t['id'];
            if ($tid === $gm) continue;
            $s = self::summary($tid);
            $moves = 0;
            while ($s['status'] === 'over' && ($force || $moves < $movesPerTeam) && $moves < 14) {
                $ev = self::aiDumpTrade($tid, $s) ?? self::aiWaive($tid, $s);
                if (!$ev) break;
                $events[] = $ev; $moves++;
                $s = self::summary($tid);
            }
            if ($s['status'] === 'under' && ($force || $moves < $movesPerTeam)) {
                $ev = self::aiAbsorb($tid, $s);
                if ($ev) $events[] = $ev;
            }
        }
        foreach ($events as $ev) {
            if (!empty($ev['inbox'])) {
                League::inboxAdd('league', 'Liga', $ev['title'], $ev['detail'], url('cap'), $ev['icon'] ?? '💸', false);
            }
            if (!empty($ev['headline'])) League::addHeadline(League::season(), League::currentDay(), 'cap', $ev['headline'], $ev['team_id'] ?? null);
        }
        return $events;
    }

    /** Valor esportivo simples (pra IA decidir quem sai): OVR, juventude e teto de potencial. */
    private static function value(array $p): float
    {
        $age = (int) $p['age'];
        $youth = $age <= 22 ? 4 : ($age <= 25 ? 2 : ($age >= 33 ? -4 : ($age >= 30 ? -1.5 : 0)));
        $pot = max(0, (int) ($p['potential'] ?? 0) - (int) $p['ovr']) * 0.3;
        return (int) $p['ovr'] + $youth + $pot;
    }

    /** Calouro na rookie scale custa mais do que o OVR diz — a IA não o larga por isso. */
    private static function isRookie(array $p): bool
    {
        return (int) ($p['seasons_pro'] ?? 1) === 0;
    }

    /** Troca de redução de folha: 1x1 ou 2x1 com um time de IA que tenha espaço. */
    private static function aiDumpTrade(int $tid, array $s): ?array
    {
        $db = Database::conn();
        $gm = League::gmTeam();
        $roster = $s['roster'];
        usort($roster, fn($a, $b) => (int) $b['ovr'] <=> (int) $a['ovr']);
        $core = array_map('intval', array_slice(array_column($roster, 'id'), 0, 2)); // os 2 melhores não saem em dump
        $cands = array_values(array_filter($roster, fn($p) => !in_array((int) $p['id'], $core, true) && (int) $p['salary'] >= 3 * self::M && !self::isRookie($p)));
        // quem custa mais e vale menos sai primeiro
        usort($cands, fn($a, $b) => ($b['salary'] / self::M - self::value($b) * 0.4) <=> ($a['salary'] / self::M - self::value($a) * 0.4));
        $cands = array_slice($cands, 0, 4);
        if (!$cands) return null;

        $partners = array_values(array_filter(League::allTeams(), fn($t) => (int) $t['id'] !== $tid && (int) $t['id'] !== $gm));
        shuffle($partners);
        $partners = array_slice($partners, 0, 12);
        $pct = self::TRADE_MATCH_PCT / 100;

        // pacotes: cada candidato sozinho, e pares (2x1)
        $packages = [];
        foreach ($cands as $c) $packages[] = [$c];
        for ($i = 0; $i < count($cands); $i++) for ($j = $i + 1; $j < count($cands); $j++) $packages[] = [$cands[$i], $cands[$j]];

        foreach ($packages as $pack) {
            $out = array_sum(array_map(fn($p) => (int) $p['salary'], $pack));
            $outVal = array_sum(array_map(fn($p) => self::value($p), $pack));
            foreach ($partners as $pt) {
                $pid = (int) $pt['id'];
                $sp = self::summary($pid);
                if ($sp['status'] === 'over') continue;
                $cntAfter = $sp['count'] - 1 + count($pack);
                if ($cntAfter > self::ROSTER_MAX || $s['count'] - count($pack) + 1 < self::ROSTER_MIN) continue;
                // Q: salário entre out/1.2 e out (pra A reduzir), e B continua no teto
                $lo = (int) ceil($out / $pct);
                foreach ($sp['roster'] as $q) {
                    $qs = (int) $q['salary'];
                    if ($qs < $lo || $qs >= $out) continue;
                    if ($sp['payroll'] - $qs + $out > $sp['cap_max']) continue;
                    // o parceiro precisa ganhar (ou empatar) em valor esportivo
                    if ($outVal < self::value($q) - 0.5) continue;
                    // executa
                    $mv = $db->prepare("UPDATE players SET team_id=?, morale=62, rotation=0, is_starter=0, min_target=0 WHERE id=?");
                    foreach ($pack as $p) $mv->execute([$pid, (int) $p['id']]);
                    $mv->execute([$tid, (int) $q['id']]);
                    $ta = League::team($tid); $tb = League::team($pid);
                    $names = implode(' + ', array_map(fn($p) => "{$p['name']} (OVR {$p['ovr']}, " . self::m((int) $p['salary']) . ")", $pack));
                    $desc = "{$ta['abbr']} envia {$names} ao {$tb['abbr']} por {$q['name']} (OVR {$q['ovr']}, " . self::m($qs) . ") — corte de folha";
                    $db->prepare("INSERT INTO transactions(season,day,type,description) VALUES(?,?,'troca',?)")
                       ->execute([League::season(), League::currentDay(), $desc]);
                    return ['type' => 'trade', 'team_id' => $tid, 'inbox' => true, 'icon' => '🔄',
                        'title' => "{$ta['abbr']} corta " . self::m($out - $qs) . " da folha em troca com o {$tb['abbr']}",
                        'detail' => $desc . '.', 'headline' => "💸 {$ta['city']} {$ta['name']} troca {$names} para se adequar ao teto."];
                }
            }
        }
        return null;
    }

    /** Dispensa o jogador que mais alivia a folha fora do núcleo (top-2 por OVR). */
    private static function aiWaive(int $tid, array $s): ?array
    {
        $roster = $s['roster'];
        usort($roster, fn($a, $b) => (int) $b['ovr'] <=> (int) $a['ovr']);
        $core = array_map('intval', array_slice(array_column($roster, 'id'), 0, 2));
        $cands = array_values(array_filter($roster, fn($p) => !in_array((int) $p['id'], $core, true) && (int) $p['salary'] > self::VETERAN_MIN * self::M && !self::isRookie($p)));
        if (!$cands) { // só calouros fora do núcleo: aí o calouro mais caro sai
            $cands = array_values(array_filter($roster, fn($p) => !in_array((int) $p['id'], $core, true) && (int) $p['salary'] > self::VETERAN_MIN * self::M));
        }
        if (!$cands) {
            // só resta o núcleo caro: sai o segundo melhor (o time nunca larga a maior estrela)
            $cands = array_values(array_filter($roster, fn($p) => (int) $p['salary'] > self::VETERAN_MIN * self::M));
            if (count($cands) < 2) return null;
            $cands = [$cands[1]];
        }
        // maior salário por valor esportivo
        usort($cands, fn($a, $b) => ($b['salary'] / self::M / max(1, self::value($b) - 60)) <=> ($a['salary'] / self::M / max(1, self::value($a) - 60)));
        $p = $cands[0];
        $r = self::release($tid, (int) $p['id'], 'ia');
        if (empty($r['ok'])) return null;
        $t = League::team($tid);
        $big = (int) $p['ovr'] >= 80;
        return ['type' => 'waive', 'team_id' => $tid, 'inbox' => $big, 'icon' => '🚪',
            'title' => "{$t['abbr']} dispensa {$p['name']} para se adequar ao teto",
            'detail' => "{$t['city']} {$t['name']} estava acima do teto e liberou {$p['name']} ({$p['pos']}, OVR {$p['ovr']}, " . self::m((int) $p['salary']) . "). Ele está na lista de agentes livres.",
            'headline' => $big ? "🚪 {$t['abbr']} dispensa {$p['name']} (OVR {$p['ovr']}) por causa do teto salarial." : null];
    }

    /** Time abaixo do piso assina o agente livre mais caro que cabe. */
    private static function aiAbsorb(int $tid, array $s): ?array
    {
        require_once __DIR__ . '/Offseason.php';
        $db = Database::conn();
        $fas = $db->query("SELECT * FROM players WHERE team_id IS NULL AND retired=0 ORDER BY salary DESC, ovr DESC LIMIT 40")->fetchAll();
        if (!$fas) return null;
        $space = $s['space'];
        foreach ($fas as $fa) {
            if ((int) $fa['salary'] > $space) continue;
            if ($s['count'] >= self::ROSTER_MAX) {
                // abre vaga cortando o pior de salário mínimo, se o agente for melhor
                $worst = end($s['roster']);
                if (!$worst || (int) $worst['ovr'] >= (int) $fa['ovr']) return null;
                self::release($tid, (int) $worst['id'], 'ia');
            }
            $r = Offseason::signFreeAgent($tid, (int) $fa['id'], League::season(), false);
            if (empty($r['ok'])) continue;
            $t = League::team($tid);
            return ['type' => 'sign', 'team_id' => $tid, 'inbox' => (int) $fa['ovr'] >= 80, 'icon' => '✍️',
                'title' => "{$t['abbr']} assina {$fa['name']} para alcançar o piso salarial",
                'detail' => "{$t['city']} {$t['name']} estava abaixo do piso e contratou {$fa['name']} ({$fa['pos']}, OVR {$fa['ovr']}, " . self::m((int) $fa['salary']) . ").",
                'headline' => (int) $fa['ovr'] >= 80 ? "✍️ {$t['abbr']} assina {$fa['name']} (OVR {$fa['ovr']})." : null];
        }
        return null;
    }

    /* ─── o GM diante das regras ────────────────────────────────────────── */

    /** Situação do time do GM: status, quanto falta/sobra e a deadline. */
    public static function gmCompliance(): ?array
    {
        $gm = League::gmTeam();
        if (!$gm) return null;
        $s = self::summary($gm);
        $dl = self::deadlineDay();
        $phase = League::phase();
        $daysLeft = $phase === 'regular' ? $dl - League::currentDay() : null;
        return $s + ['deadline_day' => $dl, 'days_left' => $daysLeft, 'trades_open' => self::tradesOpen()];
    }

    /** Temporada até a qual o save antigo tem carência (0 = sem carência). */
    public static function graceSeason(): int
    {
        return (int) Database::meta('cap_grace_season', 0);
    }

    /** Ainda na carência: a liga não trava nem pune o GM. */
    public static function inGrace(): bool
    {
        $g = self::graceSeason();
        return $g > 0 && League::season() <= $g;
    }

    /**
     * GM que assume um elenco já acima do teto (save novo ou nova franquia depois
     * de demitido) ganha carência até o fim da temporada, em vez de encontrar o
     * calendário travado no primeiro dia e ter que cortar um titular sem jogar.
     * Devolve a temporada da carência, ou null quando a folha já cabe no teto.
     */
    public static function graceIfStartsOver(): ?int
    {
        $gm = League::gmTeam();
        if (!$gm) return null;
        $s = self::summary($gm);
        if ($s['payroll'] <= $s['cap_max']) return null;
        $grace = in_array(League::phase(), ['offseason', 'lottery', 'draft', 'freeagency'], true) ? League::season() + 1 : League::season();
        if (self::graceSeason() >= $grace) return $grace;
        Database::setMeta('cap_grace_season', (string) $grace);
        Database::setMeta('cap_grace_reason', 'inicio');
        League::inboxAdd('cap', 'Liga', "💰 Folha acima do teto: carência até o fim da temporada $grace",
            'O elenco que você recebeu custa ' . self::m($s['payroll']) . ', ' . self::m($s['payroll'] - $s['cap_max'])
            . ' acima do teto de ' . self::m($s['cap_max']) . ". Até o fim da temporada $grace a liga não trava o calendário nem pune pelo piso. "
            . 'Depois disso, a temporada só começa com a folha dentro do teto: troque ou dispense quem custa caro em Folha e teto.',
            url('cap'), '💰', true);
        return $grace;
    }

    /**
     * Mensagem de bloqueio quando o GM não pode avançar por causa da folha (null = pode).
     * Só o TETO trava o calendário. Ficar abaixo do piso não trava — custa uma
     * pick (ver floorPenalty), porque nem sempre há salário disponível pra contratar.
     */
    public static function gmBlockMessage(string $momento, bool $floorToo = false): ?string
    {
        if (self::inGrace()) return null;
        $c = self::gmCompliance();
        if (!$c) return null;
        if ($c['status'] === 'over') {
            return "Sua folha está " . self::m($c['excess']) . " ACIMA do teto (" . self::m($c['cap_max']) . "). "
                 . "A liga não deixa $momento com o time fora do teto: troque ou dispense jogadores em Folha e teto.";
        }
        if ($c['status'] === 'under' && $floorToo) {
            return "Sua folha está " . self::m($c['deficit']) . " ABAIXO do piso (" . self::m($c['floor']) . "). "
                 . "A liga não deixa $momento com o time abaixo do piso: contrate um agente livre ou receba salário numa troca.";
        }
        return null;
    }

    /**
     * Penalidade por passar a deadline abaixo do piso: o time perde a pick de 2ª
     * rodada do próximo draft (se ainda a tiver) e a diretoria registra a falha.
     * Devolve a mensagem aplicada, ou null se o time estava regular.
     */
    public static function floorPenalty(int $teamId): ?string
    {
        if (self::inGrace()) return null;
        $s = self::summary($teamId);
        if ($s['status'] !== 'under') return null;
        $db = Database::conn();
        $year = League::season() + 1;
        $st = $db->prepare("SELECT id, year FROM draft_picks WHERE owner_team_id=? AND original_team_id=? AND round=2 AND used=0 AND year>=? ORDER BY year LIMIT 1");
        $st->execute([$teamId, $teamId, $year]);
        $pk = $st->fetch();
        $t = League::team($teamId);
        $txt = "{$t['abbr']} passou a Trade Deadline " . self::m($s['deficit']) . " abaixo do piso salarial";
        if ($pk) {
            $db->prepare("DELETE FROM draft_picks WHERE id=?")->execute([(int) $pk['id']]);
            $txt .= " e perde a pick de 2ª rodada de " . League::draftYearLabel((int) $pk['year']);
        } else {
            $txt .= " (sem pick de 2ª rodada para perder)";
        }
        $db->prepare("INSERT INTO transactions(season,day,type,description) VALUES(?,?,'punição',?)")
           ->execute([League::season(), League::currentDay(), $txt]);
        $db->prepare("UPDATE teams SET chemistry = MAX(50, chemistry - 3) WHERE id=?")->execute([$teamId]);
        return $txt;
    }

    /** Sugestões práticas pro GM, conforme o status (mesma ideia do app da FBA). */
    public static function suggestions(array $s): array
    {
        $out = [];
        $sorted = $s['roster'];
        usort($sorted, fn($a, $b) => (int) $b['salary'] <=> (int) $a['salary']);
        if ($s['status'] === 'over') {
            $out[] = ['type' => 'danger', 'text' => 'Você está ' . self::m($s['excess']) . ' acima do teto de ' . self::m($s['cap_max']) . '. Precisa reduzir até a Trade Deadline (dia ' . self::deadlineDay() . ').'];
            $single = null;
            foreach (array_reverse($sorted) as $p) { if ((int) $p['salary'] >= $s['excess']) { $single = $p; break; } }
            if ($single) $out[] = ['type' => 'info', 'text' => "Negociar {$single['name']} (" . self::m((int) $single['salary']) . ") resolveria sozinho: troque por alguém mais barato ou dispense."];
            $out[] = ['type' => 'tip', 'text' => 'Numa troca cada lado recebe no máximo 120% do que envia, então a folha cai devagar (no máximo 1/6 do que você manda). Dispensar corta o salário inteiro na hora.'];
        } elseif ($s['status'] === 'under') {
            $out[] = ['type' => 'warn', 'text' => 'Você está ' . self::m($s['deficit']) . ' abaixo do piso de ' . self::m($s['floor']) . '. Quem passa a Trade Deadline abaixo do piso perde a pick de 2ª rodada do próximo draft.'];
            $out[] = ['type' => 'info', 'text' => 'Para subir a folha: contrate um agente livre ou receba mais salário do que envia numa troca (até 120%).'];
        } else {
            $out[] = ['type' => 'ok', 'text' => 'Dentro do teto, com ' . self::m($s['space']) . ' de espaço.'];
            if ($s['space'] > 0) $out[] = ['type' => 'tip', 'text' => 'Você pode absorver até ' . self::m($s['space']) . ' em salário numa troca ou contratação sem estourar o teto.'];
        }
        $out[] = ['type' => 'tip', 'text' => 'Jogador que você draftar e virar 85+ soma Cap Flex (+3M, +5M ou +8M no teto, até 2 jogadores) enquanto ficar no time.'];
        return $out;
    }
}
