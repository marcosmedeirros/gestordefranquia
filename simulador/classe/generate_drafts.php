<?php
/**
 * Gerador de classes de draft reais para o NBA Sim game
 * Lê os XLS do Basketball-Reference → converte stats → gera data/eras/draftANO.php
 *
 * Uso: php generate_drafts.php
 */

$classDir  = __DIR__ . '/';
$outputDir = dirname(__DIR__) . '/data/eras/';

if (!is_dir($outputDir)) { die("Diretório de output não encontrado: $outputDir\n"); }

// ── 1. Escanear todos os XLS e agrupar por ano ──────────────────────────────
$files = glob($classDir . '*.xls');
sort($files);

$byYear = []; // int(ano) => ['file'=>string, 'data'=>array, 'picks'=>int]

foreach ($files as $path) {
    $raw = file_get_contents($path);
    if (stripos($raw, '<table') === false) continue;
    $data = parseBBRefHTML($raw);
    if (empty($data)) continue;

    $year = detectYear($data[0]['Player'] ?? '');
    if (!$year) continue;

    // para cada ano, fica com o arquivo de maior cobertura de stats
    $goodPicks = count(array_filter($data, fn($r) => (float)($r['PTS'] ?? 0) > 0));
    $prev = $byYear[$year]['goodPicks'] ?? -1;
    if ($goodPicks > $prev) {
        $byYear[$year] = ['file' => basename($path), 'data' => $data, 'goodPicks' => $goodPicks];
    }
}

ksort($byYear);
echo "=== ANOS IDENTIFICADOS: " . count($byYear) . " ===\n";

// ── 2. Para cada ano, gerar o arquivo PHP ───────────────────────────────────
$generated = [];

foreach ($byYear as $year => $info) {
    $players = [];
    $count   = 0;

    foreach ($info['data'] as $p) {
        if ($count >= 60) break; // máximo 60 picks (2 rodadas)
        $pk = (int)($p['Pk'] ?? 0);
        if ($pk < 1) continue;

        $name = sanitizeName($p['Player'] ?? '');
        if (!$name) continue;

        $r = deriveRatings($p, $year, $pk);

        $players[] = [
            'name'      => $name,
            'pos'       => $r['pos'],
            'age'       => $r['age'],
            'ht'        => $r['ht'],
            'ovr'       => $r['ovr'],
            'potential' => $r['potential'],
            'ins'       => $r['ins'],
            'mid'       => $r['mid'],
            'thr'       => $r['thr'],
            'pmk'       => $r['pmk'],
            'reb'       => $r['reb'],
            'def'       => $r['def'],
            'ath'       => $r['ath'],
        ];
        $count++;
    }

    $php     = buildPhpFile($year, $players, $info['file']);
    $outPath = $outputDir . "draft{$year}.php";
    file_put_contents($outPath, $php);
    $generated[] = $year;

    // mostrar primeiros 5 de cada classe
    echo "\n  draft{$year}.php  ({$count} jogadores) | fonte: {$info['file']}\n";
    foreach (array_slice($players, 0, 5) as $pl) {
        printf("    %-26s %-3s age=%d  ovr=%-2d pot=%-2d  ins=%-2d mid=%-2d thr=%-2d pmk=%-2d reb=%-2d def=%-2d ath=%-2d\n",
            $pl['name'], $pl['pos'], $pl['age'], $pl['ovr'], $pl['potential'],
            $pl['ins'], $pl['mid'], $pl['thr'], $pl['pmk'], $pl['reb'], $pl['def'], $pl['ath']);
    }
}

echo "\n=== GERADOS: " . count($generated) . " arquivos ===\n";
echo "  Anos: " . implode(', ', $generated) . "\n";

$missing = [];
for ($y = 1980; $y <= 2025; $y++) {
    if (!in_array($y, $generated)) $missing[] = $y;
}
echo "  Faltando ainda: " . (empty($missing) ? 'Nenhum!' : implode(', ', $missing)) . "\n";

// ══════════════════════════════════════════════════════════════════════════════
// FUNÇÕES
// ══════════════════════════════════════════════════════════════════════════════

function parseBBRefHTML(string $html): array
{
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $html, $rows);

    $headers = [];
    $data    = [];

    foreach ($rows[1] as $row) {
        preg_match_all('/<t[hd][^>]*>(.*?)<\/t[hd]>/si', $row, $cells);
        $cells = array_map(fn($c) => trim(strip_tags($c)), $cells[1]);
        $cells = array_values($cells);
        if (count($cells) < 3) continue;

        if (empty($headers)) {
            if (in_array('Player', $cells)) { $headers = $cells; continue; }
            if (isset($cells[1]) && strtolower($cells[1]) === 'pk') { $headers = $cells; continue; }
            continue;
        }
        if (($cells[0] ?? '') === 'Rk') continue;

        $pidx = array_search('Player', $headers);
        if ($pidx === false) continue;
        $name = $cells[$pidx] ?? '';
        if ($name === '' || $name === 'Player') continue;

        $row_data = [];
        foreach ($headers as $i => $h) { $row_data[$h] = $cells[$i] ?? ''; }
        $data[] = $row_data;
    }
    return $data;
}

function detectYear(string $playerName): ?int
{
    static $map = [
        'Joe Barry Carroll'=>1980,'Mark Aguirre'=>1981,'James Worthy'=>1982,
        'Ralph Sampson'=>1983,'Hakeem Olajuwon'=>1984,'Patrick Ewing'=>1985,
        'Brad Daugherty'=>1986,'David Robinson'=>1987,'Danny Manning'=>1988,
        'Pervis Ellison'=>1989,'Derrick Coleman'=>1990,'Larry Johnson'=>1991,
        "Shaquille O'Neal"=>1992,'Chris Webber'=>1993,'Glenn Robinson'=>1994,
        'Joe Smith'=>1995,'Allen Iverson'=>1996,'Tim Duncan'=>1997,
        'Michael Olowokandi'=>1998,'Elton Brand'=>1999,'Kenyon Martin'=>2000,
        'Kwame Brown'=>2001,'Yao Ming'=>2002,'LeBron James'=>2003,
        'Dwight Howard'=>2004,'Andrew Bogut'=>2005,'Andrea Bargnani'=>2006,
        'Greg Oden'=>2007,'Derrick Rose'=>2008,'Blake Griffin'=>2009,
        'John Wall'=>2010,'Kyrie Irving'=>2011,'Anthony Davis'=>2012,
        'Anthony Bennett'=>2013,'Andrew Wiggins'=>2014,'Karl-Anthony Towns'=>2015,
        'Ben Simmons'=>2016,'Markelle Fultz'=>2017,'Deandre Ayton'=>2018,
        'Zion Williamson'=>2019,'Anthony Edwards'=>2020,'Cade Cunningham'=>2021,
        'Paolo Banchero'=>2022,'Victor Wembanyama'=>2023,'Zaccharie Risacher'=>2024,
        'Cooper Flagg'=>2025,
    ];
    return $map[$playerName] ?? null;
}

// ── Derivar ratings 2K-style a partir de stats de carreira da BBRef ─────────
function deriveRatings(array $p, int $draftYear, int $pk): array
{
    $pts  = (float)($p['PTS']   ?? 0);
    $ast  = (float)($p['AST']   ?? 0);
    $reb  = (float)($p['TRB']   ?? 0);
    $fg   = (float)($p['FG%']   ?? 0);
    $tp   = (float)($p['3P%']   ?? 0);
    $ft   = (float)($p['FT%']   ?? 0);
    $bpm  = (float)($p['BPM']   ?? -5);
    $ws   = (float)($p['WS']    ?? 0);
    $ws48 = (float)($p['WS/48'] ?? 0);
    $g    = (int)  ($p['G']     ?? 0);

    $hasSufficientData = ($g >= 82 && $pts > 0);
    $hasAnyData        = ($pts > 0 || $g > 0);

    // ── Posição (inferida dos stats de carreira) ─────────────────────────────
    if ($ast >= 6.5 || ($ast >= 5.0 && $reb < 5.0)) {
        $pos = 'PG';
    } elseif ($ast >= 4.0 && $reb < 4.5 && $pts >= 13) {
        $pos = 'SG';
    } elseif ($reb >= 9.5 && ($tp < 0.01 || $tp < 0.22)) {
        $pos = 'C';
    } elseif ($reb >= 7.0 && $tp < 0.30 && $pts < 22) {
        $pos = 'PF';
    } elseif ($reb >= 8.0) {
        $pos = 'PF';
    } elseif ($pts >= 18 && $reb < 5.5 && $ast < 4.0) {
        $pos = ($tp >= 0.34) ? 'SG' : 'SF';
    } else {
        // padrão: distribui equilibradamente por pick number
        $pos = ['SF','SG','SF','PF','PG'][$pk % 5];
    }

    $bigMan = in_array($pos, ['C', 'PF']);
    $guard  = in_array($pos, ['PG', 'SG']);

    // ── OVR (peak de carreira) ────────────────────────────────────────────────
    if ($hasSufficientData) {
        // jogador com carreira suficiente para avaliar pelos stats reais
        if ($ws >= 20) {
            $wsScore = min(25.0, $ws * 0.175);
        } else {
            // carreira curta (ex.: 2022-25): WS/48 como proxy
            $wsScore = min(20.0, $ws48 * 55.0 + $ws * 0.12);
        }
        $bpmScore  = max(-8.0, min(10.0, $bpm * 2.0));
        $ptsScore  = min(10.0, $pts * 0.38);
        $peakOvr   = clamp((int) round(62 + $wsScore + $bpmScore + $ptsScore), 60, 99);
    } elseif ($hasAnyData) {
        // poucos jogos mas tem algum dado (ex.: rookies 2024-25)
        $bpmScore  = max(-5.0, min(8.0, $bpm * 1.8));
        $ptsScore  = min(8.0, $pts * 0.32);
        $ws48Boost = ($ws48 > 0.13) ? ($ws48 - 0.10) * 45.0 : 0;
        $pickAdj   = max(0.0, (28 - $pk) * 0.3);
        $peakOvr   = clamp((int) round(62 + $bpmScore + $ptsScore + $ws48Boost + $pickAdj), 60, 92);
    } else {
        // sem stats NBA (nunca jogou ou sem dados ainda)
        $peakOvr = clamp((int) round(76 - ($pk - 1) * 0.5), 60, 78);
    }

    // OVR na entrada do draft (menor que o peak, cresce ao longo da carreira)
    $pickLift  = max(0.0, (30 - $pk) * 0.25);
    $rawStart  = 62 + ($peakOvr - 62) * 0.38 + $pickLift;
    $startOvr  = clamp((int) round($rawStart), 60, 96);

    // Potencial = peak OVR com leve margem (impede que seja menor que ovr)
    $potential = clamp($peakOvr + deterministic($pk, 0, 2), $startOvr, 99);

    // ── Atributos ─────────────────────────────────────────────────────────────
    if ($hasAnyData) {
        // INS: pontuação no garrafão (FG% + pontos + bônus de pivô)
        $ins = clamp(
            (int) round(50 + $pts * 0.58 + ($fg > 0 ? ($fg - 0.44) * 90 : 0) + ($bigMan ? 10 : ($guard ? -8 : 0))),
            38, 95
        );

        // MID: arremesso de meia distância (FG% + FT% como proxy de mecânica)
        $mid = clamp(
            (int) round(20 + $pts * 0.38 + ($fg > 0 ? $fg * 52 : 24) + ($ft > 0 ? $ft * 18 : 12)),
            38, 93
        );

        // THR: arremesso de 3 (baseado em 3P%)
        if ($tp > 0.01) {
            $thr = clamp((int) round($tp * 148 + 16), 30, 95);
        } elseif ($tp == 0 && !$bigMan) {
            $thr = deterministic($pk, 46, 62); // sem dado = arremessador mediano
        } else {
            $thr = deterministic($pk, 30, 45); // pivô sem 3s
        }

        // PMK: criação de jogadas (assists por jogo)
        $pmkBase = $ast * 7.8 + 34;
        $pmkBase += ($pos === 'PG') ? 8 : (($pos === 'SG') ? 3 : 0);
        $pmk = clamp((int) round($pmkBase), 35, 95);

        // REB: rebote
        $rebBase = $reb * 6.8 + 28 + ($bigMan ? 8 : 0);
        $rebR = clamp((int) round($rebBase), 28, 95);

        // DEF: defesa (BPM como proxy + bônus para pivôs)
        $def = clamp((int) round(64 + $bpm * 2.3 + ($bigMan ? 6 : 0)), 40, 95);

        // ATH: atletismo (derivado do pick + juventude + BPM como indicador de burst)
        $athBase = 80 - ($pk - 1) * 0.42 + ($bpm > 2 ? 3 : 0) + ($bigMan ? 2 : 0);
        $ath = clamp((int) round($athBase), 55, 94);

    } else {
        // sem stats: atributos baseados na posição e pick
        [$ins, $mid, $thr, $pmk, $rebR, $def, $ath] = noStatsAttributes($pos, $pk, $startOvr);
    }

    // ── Idade na entrada do draft ─────────────────────────────────────────────
    // antes de 2005, havia jogadores de high school (18-19 anos)
    // após 2005 (NBA age rule) mínimo é 19 para maioria
    if ($draftYear >= 2006) {
        if ($pk <= 3)       $age = 19 + deterministic($pk, 0, 1);
        elseif ($pk <= 10)  $age = 19 + deterministic($pk, 0, 2);
        elseif ($pk <= 25)  $age = 20 + deterministic($pk, 0, 2);
        else                $age = 21 + deterministic($pk, 0, 2);
    } else {
        if ($pk <= 5)       $age = 18 + deterministic($pk, 0, 2);
        elseif ($pk <= 15)  $age = 19 + deterministic($pk, 0, 2);
        elseif ($pk <= 30)  $age = 21 + deterministic($pk, 0, 2);
        else                $age = 22 + deterministic($pk, 0, 1);
    }

    // ── Altura por posição (determinística, variação pelo pick) ──────────────
    $htBase = match($pos) {
        'PG' => 188, 'SG' => 195, 'SF' => 203, 'PF' => 208, 'C' => 213, default => 200
    };
    $ht = $htBase + (($pk % 7) - 3); // ±3 cm de variação

    return [
        'pos'       => $pos,
        'age'       => $age,
        'ht'        => $ht,
        'ovr'       => $startOvr,
        'potential' => $potential,
        'ins'       => $ins,
        'mid'       => $mid,
        'thr'       => $thr,
        'pmk'       => $pmk,
        'reb'       => $rebR,
        'def'       => $def,
        'ath'       => $ath,
    ];
}

// ── Sem stats: gera atributos coerentes com posição e pick ───────────────────
function noStatsAttributes(string $pos, int $pk, int $ovr): array
{
    $big   = in_array($pos, ['C','PF']);
    $guard = in_array($pos, ['PG','SG']);
    $base  = $ovr;

    $ins = clamp($base + ($big ? 6 : ($guard ? -6 : 0)) + deterministic($pk, -4, 4), 40, 88);
    $mid = clamp($base - 2 + deterministic($pk, -4, 4), 38, 86);
    $thr = $big
        ? clamp(deterministic($pk, 30, 46), 30, 50)
        : clamp($base - 4 + deterministic($pk, -8, 8), 40, 88);
    $pmk = clamp($base + ($pos==='PG' ? 8 : ($pos==='SG' ? 2 : ($big ? -10 : 0))), 35, 90);
    $reb = clamp($base + ($big ? 10 : ($guard ? -12 : 0)), 28, 90);
    $def = clamp($base - 2 + ($big ? 4 : 0) + deterministic($pk, -4, 4), 40, 90);
    $ath = clamp(80 - ($pk-1)*0.42, 55, 90);

    return [(int)$ins, (int)$mid, (int)$thr, (int)$pmk, (int)$reb, (int)$def, (int)$ath];
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function clamp(int $v, int $lo, int $hi): int
{
    return max($lo, min($hi, $v));
}

/** Valor determinístico no range [lo, hi] baseado no pick — sem randomness. */
function deterministic(int $pk, int $lo, int $hi): int
{
    if ($lo >= $hi) return $lo;
    return $lo + (($pk * 31 + 7) % ($hi - $lo + 1));
}

function sanitizeName(string $n): string
{
    // remove caracteres de controle, mantém acentos e pontuação normal
    $n = preg_replace('/[\x00-\x1F\x7F]/u', '', $n);
    return trim($n);
}

// ── Gerar conteúdo do arquivo PHP ────────────────────────────────────────────
function buildPhpFile(int $year, array $players, string $sourceFile): string
{
    $pick1 = $players[0]['name'] ?? '?';
    $lines = ["<?php"];
    $lines[] = "/** Draft $year — {$pick1} foi o pick #1. Gerado de: $sourceFile */";
    $lines[] = "return [";
    foreach ($players as $p) {
        $lines[] = sprintf(
            " ['name'=>'%s','pos'=>'%s','age'=>%d,'ht'=>%d,'ovr'=>%d,'potential'=>%d,'ins'=>%d,'mid'=>%d,'thr'=>%d,'pmk'=>%d,'reb'=>%d,'def'=>%d,'ath'=>%d],",
            addslashes($p['name']), $p['pos'], $p['age'], $p['ht'],
            $p['ovr'], $p['potential'],
            $p['ins'], $p['mid'], $p['thr'], $p['pmk'], $p['reb'], $p['def'], $p['ath']
        );
    }
    $lines[] = "];";
    $lines[] = "";
    return implode("\n", $lines);
}
