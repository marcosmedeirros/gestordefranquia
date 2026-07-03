<?php
// Estatísticas históricas da liga RISE, extraídas manualmente de vídeos de simulação
// e mantidas como CSV em data/rise-history/. Dados imutáveis (temporadas já encerradas).

function riseHistoryReadCsv(string $path): array {
    $rows = [];
    if (!is_readable($path)) return $rows;
    if (($h = fopen($path, 'r')) !== false) {
        while (($row = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
            $rows[] = $row;
        }
        fclose($h);
    }
    return $rows;
}

function riseHistoryHeaderIndex(array $rows, string $firstColMatch): int {
    foreach ($rows as $i => $row) {
        if (isset($row[0]) && trim($row[0]) === $firstColMatch) return $i;
    }
    return -1;
}

// Quebra a coluna "Detalhes" (ex: "T1 1ª Rodada (Oeste): Time A 4-2 Time B | T2 ...")
// em séries individuais [winner, loserScore, loser].
function riseHistoryParseSeriesDetails(string $detalhes): array {
    $series = [];
    foreach (explode('|', $detalhes) as $seg) {
        $seg = trim($seg);
        if ($seg === '') continue;
        $colonPos = strpos($seg, ':');
        if ($colonPos === false) continue;
        $rest = trim(substr($seg, $colonPos + 1));
        if (!preg_match('/^(.+?)\s+4-(\d+)\s+(.+)$/u', $rest, $m)) continue;
        $series[] = ['winner' => trim($m[1]), 'loserScore' => (int)$m[2], 'loser' => trim($m[3])];
    }
    return $series;
}

function loadRiseHistoryStats(): array {
    $dir = __DIR__ . '/../data/rise-history';

    // ── Ranking de Títulos + Eterno Vice ──────────────────────────
    $titulosMap = [];
    $eternoViceMap = [];
    $rtRows = riseHistoryReadCsv("$dir/ranking-titulos.csv");
    $rtIdx = riseHistoryHeaderIndex($rtRows, 'Time');
    if ($rtIdx >= 0) {
        foreach (array_slice($rtRows, $rtIdx + 1) as $row) {
            $name = trim($row[0] ?? '');
            if ($name === '') continue;
            $titulos = (int)($row[1] ?? 0);
            $vices   = (int)($row[2] ?? 0);
            $titulosMap[] = ['name' => $name, 'count' => $titulos];
            if ($titulos === 0 && $vices > 0) {
                $eternoViceMap[] = ['name' => $name, 'count' => $vices];
            }
        }
        usort($titulosMap, fn($a, $b) => $b['count'] <=> $a['count']);
        usort($eternoViceMap, fn($a, $b) => $b['count'] <=> $a['count']);
    }

    // ── Maior Dinastia (sequência de títulos consecutivos) ────────
    $dinastiaMap = [];
    $fnRows = riseHistoryReadCsv("$dir/finais.csv");
    $fnIdx = riseHistoryHeaderIndex($fnRows, 'Temporada');
    if ($fnIdx >= 0) {
        $streakMax = [];
        $curTeam = null;
        $cur = 0;
        foreach (array_slice($fnRows, $fnIdx + 1) as $row) {
            $team = trim($row[1] ?? '');
            if ($team === '') continue;
            $cur = ($team === $curTeam) ? $cur + 1 : 1;
            $curTeam = $team;
            if (!isset($streakMax[$team]) || $cur > $streakMax[$team]) $streakMax[$team] = $cur;
        }
        foreach ($streakMax as $name => $streak) {
            $dinastiaMap[] = ['name' => $name, 'count' => $streak];
        }
        usort($dinastiaMap, fn($a, $b) => $b['count'] <=> $a['count']);
    }

    // ── Rivalidades, Domínio Total, Sweeps e Jogos de Jogo 7 (head-to-head) ─
    $rivalidadeMap = [];
    $dominioMap = [];
    $sweepsDadosCount = [];
    $sweepsSofridosCount = [];
    $jogo7Count = [];
    $hthRows = riseHistoryReadCsv("$dir/head-to-head.csv");
    $hthIdx = riseHistoryHeaderIndex($hthRows, 'Confronto');
    if ($hthIdx >= 0) {
        foreach (array_slice($hthRows, $hthIdx + 1) as $row) {
            $confronto = trim($row[0] ?? '');
            if ($confronto === '') continue;
            $parts = explode(' x ', $confronto, 2);
            if (count($parts) < 2) continue;
            [$a, $b] = $parts;
            preg_match('/:\s*(\d+)/', $row[1] ?? '', $m1);
            preg_match('/:\s*(\d+)/', $row[2] ?? '', $m2);
            $w1 = isset($m1[1]) ? (int)$m1[1] : 0;
            $w2 = isset($m2[1]) ? (int)$m2[1] : 0;
            $total = (int)($row[3] ?? ($w1 + $w2));

            $rivalidadeMap[] = [
                'a' => $a, 'b' => $b, 'a_long' => $a, 'b_long' => $b,
                'count' => $total, 'name' => "$a x $b",
            ];

            $diff = abs($w1 - $w2);
            if ($total >= 3 && $diff > 0) {
                $winner = $w1 >= $w2 ? $a : $b;
                $loser  = $w1 >= $w2 ? $b : $a;
                $dominioMap[] = [
                    'a' => $winner, 'b' => $loser, 'a_long' => $winner, 'b_long' => $loser,
                    'count' => $diff, 'name' => "$winner x $loser",
                ];
            }

            foreach (riseHistoryParseSeriesDetails($row[4] ?? '') as $s) {
                if ($s['loserScore'] === 0) {
                    $sweepsDadosCount[$s['winner']] = ($sweepsDadosCount[$s['winner']] ?? 0) + 1;
                    $sweepsSofridosCount[$s['loser']] = ($sweepsSofridosCount[$s['loser']] ?? 0) + 1;
                } elseif ($s['loserScore'] === 3) {
                    $jogo7Count[$s['winner']] = ($jogo7Count[$s['winner']] ?? 0) + 1;
                    $jogo7Count[$s['loser']] = ($jogo7Count[$s['loser']] ?? 0) + 1;
                }
            }
        }
        usort($rivalidadeMap, fn($a, $b) => $b['count'] <=> $a['count']);
        usort($dominioMap, fn($a, $b) => $b['count'] <=> $a['count']);
    }
    $sweepsDadosMap = [];
    foreach ($sweepsDadosCount as $name => $count) $sweepsDadosMap[] = ['name' => $name, 'count' => $count];
    usort($sweepsDadosMap, fn($a, $b) => $b['count'] <=> $a['count']);
    $sweepsSofridosMap = [];
    foreach ($sweepsSofridosCount as $name => $count) $sweepsSofridosMap[] = ['name' => $name, 'count' => $count];
    usort($sweepsSofridosMap, fn($a, $b) => $b['count'] <=> $a['count']);
    $jogo7Map = [];
    foreach ($jogo7Count as $name => $count) $jogo7Map[] = ['name' => $name, 'count' => $count];
    usort($jogo7Map, fn($a, $b) => $b['count'] <=> $a['count']);

    // ── Margem nas Finais (dominantes x equilibradas) ─────────────
    $finaisMargemMap = [];
    if ($fnIdx >= 0) {
        foreach (array_slice($fnRows, $fnIdx + 1) as $row) {
            $campeao = trim($row[1] ?? '');
            $vice    = trim($row[2] ?? '');
            if ($campeao === '' || $vice === '') continue;
            if (!preg_match('/4-(\d+)/', $row[3] ?? '', $m)) continue;
            $margem = 4 - (int)$m[1];
            $finaisMargemMap[] = [
                'a' => $campeao, 'b' => $vice, 'a_long' => $campeao, 'b_long' => $vice,
                'count' => $margem, 'name' => "$campeao x $vice",
            ];
        }
        usort($finaisMargemMap, fn($a, $b) => $b['count'] <=> $a['count']);
    }

    // ── Mais Aparições em Playoff + Seed Médio Histórico ───────────
    $aparicoesMap = [];
    $seedMedioMap = [];
    $sdRows = riseHistoryReadCsv("$dir/seeds.csv");
    $sdIdx = riseHistoryHeaderIndex($sdRows, 'Time');
    if ($sdIdx >= 0) {
        foreach (array_slice($sdRows, $sdIdx + 1) as $row) {
            $name = trim($row[0] ?? '');
            if ($name === '') continue;
            $aparicoes = (int)($row[9] ?? 0);
            $aparicoesMap[] = ['name' => $name, 'count' => $aparicoes];

            $weighted = 0;
            $total = 0;
            for ($seed = 1; $seed <= 8; $seed++) {
                $c = (int)($row[$seed] ?? 0);
                $weighted += $seed * $c;
                $total += $c;
            }
            if ($total > 0) {
                $seedMedioMap[] = ['name' => $name, 'count' => round($weighted / $total, 2)];
            }
        }
        usort($aparicoesMap, fn($a, $b) => $b['count'] <=> $a['count']);
        usort($seedMedioMap, fn($a, $b) => $a['count'] <=> $b['count']);
    }

    return [
        'titulos'          => $titulosMap,
        'dinastia'         => $dinastiaMap,
        'rivalidades'      => $rivalidadeMap,
        'dominio'          => $dominioMap,
        'aparicoes'        => $aparicoesMap,
        'eterno_vice'      => $eternoViceMap,
        'sweeps_dados'     => $sweepsDadosMap,
        'sweeps_sofridos'  => $sweepsSofridosMap,
        'jogo7'            => $jogo7Map,
        'finais_margem'    => $finaisMargemMap,
        'seed_medio'       => $seedMedioMap,
    ];
}
