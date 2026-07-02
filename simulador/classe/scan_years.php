<?php
/**
 * Identifica o ano de cada arquivo XLS do Basketball-Reference
 * pelo pick #1 e total de picks, depois lista anos faltando (1980–2025)
 */

$dir = __DIR__ . '/';
$files = glob($dir . '*.xls');
sort($files);

$found = []; // ano => [arquivo, pick1_name, pick1_team, total]

foreach ($files as $path) {
    $fname = basename($path);
    $raw = file_get_contents($path);
    if (stripos($raw, '<table') === false && stripos($raw, '<html') === false) {
        echo "BINARIO: $fname\n";
        continue;
    }
    $data = parseBBRefHTML($raw);
    if (empty($data)) { echo "SEM_DADOS: $fname\n"; continue; }

    $first = $data[0];
    $name  = $first['Player'] ?? '?';
    $team  = $first['Tm'] ?? '?';
    $year  = detectYear($name, $team);

    echo sprintf("%-40s pick1=%-28s team=%-4s ano=%s picks=%d\n",
        $fname, $name, $team, $year ?? '????', count($data));

    if ($year) {
        // guarda o primeiro arquivo encontrado para cada ano
        if (!isset($found[$year])) {
            $found[$year] = ['file'=>$fname, 'pick1'=>$name, 'team'=>$team, 'total'=>count($data)];
        }
    }
}

echo "\n=== ANOS ENCONTRADOS (" . count($found) . ") ===\n";
ksort($found);
foreach ($found as $yr => $d) {
    echo "  $yr → #{$d['pick1']} ({$d['team']}) | {$d['total']} picks | {$d['file']}\n";
}

echo "\n=== ANOS FALTANDO (1980–2025) ===\n";
$missing = [];
for ($y = 1980; $y <= 2025; $y++) {
    if (!isset($found[$y])) $missing[] = $y;
}
echo "  " . (empty($missing) ? 'Nenhum! Cobertura completa.' : implode(', ', $missing)) . "\n";

// ─────────────────────────────────────────────────────────────
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
        $name = ($pidx !== false) ? ($cells[$pidx] ?? '') : '';
        if (empty($name) || $name === 'Player') continue;
        $row_data = [];
        foreach ($headers as $i => $h) $row_data[$h] = $cells[$i] ?? '';
        $data[] = $row_data;
    }
    return $data;
}

// Mapeia pick #1 de cada draft a partir de 1980 → ano
function detectYear(string $name, string $team): ?int
{
    static $map = [
        // 1980–1989
        'Joe Barry Carroll'      => 1980,
        'Mark Aguirre'           => 1981,
        'James Worthy'           => 1982,
        'Ralph Sampson'          => 1983,
        'Hakeem Olajuwon'        => 1984,
        'Patrick Ewing'          => 1985,
        'Brad Daugherty'         => 1986,
        'David Robinson'         => 1987,
        'Danny Manning'          => 1988,
        'Pervis Ellison'         => 1989,
        // 1990–1999
        'Derrick Coleman'        => 1990,
        'Larry Johnson'          => 1991,
        'Shaquille O\'Neal'      => 1992,
        "Shaquille O'Neal"       => 1992,
        'Chris Webber'           => 1993,
        'Glenn Robinson'         => 1994,
        'Joe Smith'              => 1995,
        'Allen Iverson'          => 1996,
        'Tim Duncan'             => 1997,
        'Michael Olowokandi'     => 1998,
        'Elton Brand'            => 1999,
        // 2000–2009
        'Kenyon Martin'          => 2000,
        'Kwame Brown'            => 2001,
        'Yao Ming'               => 2002,
        'LeBron James'           => 2003,
        'Dwight Howard'          => 2004,
        'Andrew Bogut'           => 2005,
        'Andrea Bargnani'        => 2006,
        'Greg Oden'              => 2007,
        'Derrick Rose'           => 2008,
        'Blake Griffin'          => 2009,
        // 2010–2019
        'John Wall'              => 2010,
        'Kyrie Irving'           => 2011,
        'Anthony Davis'          => 2012,
        'Anthony Bennett'        => 2013,
        'Andrew Wiggins'         => 2014,
        'Karl-Anthony Towns'     => 2015,
        'Ben Simmons'            => 2016,
        'Markelle Fultz'         => 2017,
        'Deandre Ayton'          => 2018,
        'Zion Williamson'        => 2019,
        // 2020–2025
        'Anthony Edwards'        => 2020,
        'Cade Cunningham'        => 2021,
        'Paolo Banchero'         => 2022,
        'Victor Wembanyama'      => 2023,
        'Zaccharie Risacher'     => 2024,
        'Cooper Flagg'           => 2025,
    ];
    return $map[$name] ?? null;
}
