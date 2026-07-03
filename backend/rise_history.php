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

    // ── Rivalidades e Domínio Total (head-to-head) ────────────────
    $rivalidadeMap = [];
    $dominioMap = [];
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
        }
        usort($rivalidadeMap, fn($a, $b) => $b['count'] <=> $a['count']);
        usort($dominioMap, fn($a, $b) => $b['count'] <=> $a['count']);
    }

    // ── Mais Aparições em Playoff (histórico) ──────────────────────
    $aparicoesMap = [];
    $sdRows = riseHistoryReadCsv("$dir/seeds.csv");
    $sdIdx = riseHistoryHeaderIndex($sdRows, 'Time');
    if ($sdIdx >= 0) {
        foreach (array_slice($sdRows, $sdIdx + 1) as $row) {
            $name = trim($row[0] ?? '');
            if ($name === '') continue;
            $aparicoesMap[] = ['name' => $name, 'count' => (int)($row[9] ?? 0)];
        }
        usort($aparicoesMap, fn($a, $b) => $b['count'] <=> $a['count']);
    }

    return [
        'titulos'      => $titulosMap,
        'dinastia'     => $dinastiaMap,
        'rivalidades'  => $rivalidadeMap,
        'dominio'      => $dominioMap,
        'aparicoes'    => $aparicoesMap,
        'eterno_vice'  => $eternoViceMap,
    ];
}
