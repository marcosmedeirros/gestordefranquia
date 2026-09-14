<?php
require_once dirname(__DIR__) . '/helpers.php';

if (!function_exists('renderStandings')) {
    /**
     * Tabela de classificação (estilo em assets/css/pages/standings.css).
     * Por conferência ($zones) marca as faixas da regra do jogo, a mesma de
     * League::startPlayIn e League::finalSeeds: do 1º ao 6º vão direto aos
     * playoffs, do 7º ao 10º jogam o play-in e do 11º para baixo estão fora.
     * Sem $zones (tabela da liga toda) mostra a conferência de cada time.
     * $compact tira as colunas de aproveitamento e sequência.
     */
    function renderStandings(array $teams, bool $compact = false, ?int $gmId = null, bool $zones = true): void
    {
        $gmId = $gmId ?? (int) League::gmTeam();
        $topDiff = isset($teams[0]) ? ((int) $teams[0]['wins'] - (int) $teams[0]['losses']) : 0;

        echo '<div class="table-wrap"><table class="tbl st-table' . ($zones ? ' st-zones' : '') . '">';
        echo '<thead><tr><th class="c">#</th><th>Time</th>';
        if (!$zones) echo '<th class="hide-sm">Conf.</th>';
        echo '<th class="num">V</th><th class="num">D</th>';
        if (!$compact) echo '<th class="num hide-sm" title="Aproveitamento">%</th>';
        echo '<th class="num" title="Jogos atrás do líder">JA</th>';
        if (!$compact) echo '<th class="c hide-sm" title="Sequência atual">Seq.</th>';
        echo '</tr></thead><tbody>';

        foreach ($teams as $t) {
            $seed = (int) $t['seed'];
            $w    = (int) $t['wins'];
            $l    = (int) $t['losses'];
            $gb   = ($topDiff - ($w - $l)) / 2;
            $stk  = (int) ($t['streak'] ?? 0);

            $cls = [];
            if ($gmId && (int) $t['id'] === $gmId) $cls[] = 'mine';
            if ($zones) {
                if ($seed <= 6) $cls[] = 'z-po';
                elseif ($seed <= 10) $cls[] = 'z-pi';
                if ($seed === 6 || $seed === 10) $cls[] = 'cut';
            }

            echo '<tr' . ($cls ? ' class="' . implode(' ', $cls) . '"' : '') . '>';
            echo '<td class="rank">' . $seed . '</td>';
            echo '<td><a class="who" href="' . url('team', ['id' => $t['id']]) . '">'
                . team_logo((string) $t['abbr'], (string) ($t['primary_color'] ?? '#333'), 'sm')
                . '<span class="w-txt"><b><span class="st-full">' . e(teamFull($t)) . '</span>'
                . '<span class="st-abbr">' . e((string) $t['abbr']) . '</span></b></span></a></td>';
            if (!$zones) echo '<td class="hide-sm dim">' . ($t['conf'] === 'E' ? 'Leste' : 'Oeste') . '</td>';
            echo '<td class="num">' . $w . '</td><td class="num">' . $l . '</td>';
            if (!$compact) echo '<td class="num hide-sm">' . ($w + $l ? number_format((float) $t['pct'] * 100, 1) : '—') . '</td>';
            echo '<td class="num">' . ($gb <= 0 ? '—' : number_format($gb, 1)) . '</td>';
            if (!$compact) {
                echo '<td class="c hide-sm">' . ($stk > 0 ? '<span class="pos">V' . $stk . '</span>'
                    : ($stk < 0 ? '<span class="neg">D' . abs($stk) . '</span>' : '<span class="dim">—</span>')) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}
