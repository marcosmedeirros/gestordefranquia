<?php
/**
 * Parser de XLS do Basketball-Reference → ratings 2K-style
 * BBRef exporta HTML disfarçado de .xls
 */

$dir = __DIR__ . '/';

// lista todos os .xls no diretório e processa
$files = glob($dir . 'sportsref_download*.xls');
sort($files);

foreach ($files as $path) {
    $fname = basename($path);
    $raw = file_get_contents($path);

    // BBRef XLS são HTML — detecta pelo conteúdo
    $isHtml = (stripos($raw, '<html') !== false || stripos($raw, '<table') !== false);

    if (!$isHtml) {
        echo "BINARIO (nao suportado): $fname\n\n";
        continue;
    }

    $data = parseBBRefHTML($raw);

    if (empty($data)) {
        echo "SEM DADOS: $fname\n\n";
        continue;
    }

    $first = $data[0];
    $player1 = $first['Player'] ?? '?';
    $team1   = $first['Tm']     ?? '?';
    echo "=== $fname ===\n";
    echo "  Pick #1: $player1 ($team1) | Total picks: " . count($data) . "\n";
    foreach ($data as $d) {
        printf("  #%-3s %-25s %-4s PTS=%-5s AST=%-5s REB=%-5s BPM=%-6s WS=%-6s FG%%=%-5s 3P%%=%-5s FT%%=%-5s\n",
            $d['Pk']  ?? '',
            $d['Player'] ?? '',
            $d['Tm'] ?? '',
            $d['PTS'] ?? '',
            $d['AST'] ?? '',
            $d['TRB'] ?? '',
            $d['BPM'] ?? '',
            $d['WS']  ?? '',
            $d['FG%'] ?? '',
            $d['3P%'] ?? '',
            $d['FT%'] ?? ''
        );
    }
    echo "\n";
}

// ──────────────────────────────────────────────
function parseBBRefHTML(string $html): array
{
    // Remove comentários HTML (BBRef esconde thead/cabeçalhos em comments)
    $html = preg_replace('/<!--.*?-->/s', '', $html);

    preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $html, $rows);

    $headers = [];
    $data    = [];

    foreach ($rows[1] as $row) {
        preg_match_all('/<t[hd][^>]*>(.*?)<\/t[hd]>/si', $row, $cells);
        $cells = array_map(fn($c) => trim(strip_tags($c)), $cells[1]);
        $cells = array_values($cells);

        if (count($cells) < 3) continue;

        // linha de cabeçalho: primeiro campo é "Rk" ou "Pk" ou vazio
        if (empty($headers)) {
            if (in_array(strtolower($cells[0] ?? ''), ['rk','']) &&
                in_array(strtolower($cells[1] ?? ''), ['pk','rnd','round'])) {
                $headers = $cells;
                continue;
            }
            // alternativa: procura "Player" nos campos
            if (in_array('Player', $cells)) {
                $headers = $cells;
                continue;
            }
            continue;
        }

        // pula linhas de cabeçalho repetido
        if (($cells[0] ?? '') === 'Rk' || ($cells[1] ?? '') === 'Pk') continue;
        // pula linhas vazias ou só com marcadores
        $name = $cells[array_search('Player', $headers)] ?? '';
        if (empty($name) || $name === 'Player') continue;

        $row_data = [];
        foreach ($headers as $i => $h) {
            $row_data[$h] = $cells[$i] ?? '';
        }
        $data[] = $row_data;
    }

    return $data;
}
