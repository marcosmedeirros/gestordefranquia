<?php
require_once dirname(__DIR__) . '/helpers.php';

if (!function_exists('renderStandings')) {
    function renderStandings(array $teams, bool $compact = false): void
    {
        $topDiff = isset($teams[0]) ? ($teams[0]['wins'] - $teams[0]['losses']) : 0;
        echo '<table class="standings-table" style="border-spacing:0">';
        echo '<thead><tr>
                <th style="width:32px">#</th>
                <th>Time</th>
                <th class="num">V</th>
                <th class="num">D</th>
                <th class="num">%</th>';
        if (!$compact) echo '<th class="num">JA</th>';
        echo '</tr></thead><tbody>';

        foreach ($teams as $t) {
            $gb       = (($topDiff) - ($t['wins'] - $t['losses'])) / 2;
            $playoff  = $t['seed'] <= 8 ? 'po' : ($t['seed'] <= 10 ? 'pi' : '');
            $seedCls  = $t['seed'] <= 8 ? 'po' : ($t['seed'] <= 10 ? 'pi' : '');
            $pct      = (float) $t['pct'];
            $barW     = (int) round($pct * 100);

            echo '<tr class="' . $playoff . '">';

            // Seed badge
            echo '<td><span class="seed-badge ' . $seedCls . '">' . $t['seed'] . '</span></td>';

            // Team name + logo
            echo '<td>
                    <div class="st-team-cell">
                      ' . team_logo($t['abbr'], $t['primary_color'], 'sm') . '
                      <span class="st-team-name">
                        <a href="' . url('team', ['id' => $t['id']]) . '">' . e($t['city'] . ' ' . $t['name']) . '</a>
                      </span>
                    </div>
                  </td>';

            echo '<td class="num">' . $t['wins'] . '</td>';
            echo '<td class="num">' . $t['losses'] . '</td>';
            echo '<td class="num"><span style="font-variant-numeric:tabular-nums">' . number_format($pct, 3) . '</span>
                    <span class="win-bar"><span style="width:' . $barW . '%"></span></span>
                  </td>';

            if (!$compact) {
                echo '<td class="num">' . ($gb <= 0 ? '—' : number_format($gb, 1)) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
