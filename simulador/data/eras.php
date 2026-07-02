<?php
/**
 * Registro de ERAS jogáveis (estilo NBA 2K Eras).
 *
 * draftclasses: ano-calendário => arquivo PHP com a classe real daquele draft.
 * Cada era lista TODOS os drafts reais disponíveis a partir do seu start_year,
 * de modo que quando o jogo alcança aquele ano ele usa os jogadores reais.
 *
 * Cobertura completa: 1980–2025 (46 classes reais derivadas do Basketball-Reference).
 */

// Helper: gera mapa de drafts reais para um intervalo de anos.
// Guardado com function_exists pois este arquivo é carregado via `require`
// (não require_once — ele RETORNA o array) em mais de um ponto do fluxo,
// e definir a função duas vezes causaria "Cannot redeclare".
if (!function_exists('draftsFrom')) {
    function draftsFrom(int $from, int $to = 2025): array {
        $map = [];
        for ($y = $from; $y <= $to; $y++) {
            $file = "eras/draft{$y}.php";
            $map[$y] = $file;
        }
        return $map;
    }
}

return [

    // ─────────────────────────────────────────────────────────────────────────
    'modern' => [
        'name'         => 'Era Atual',
        'desc'         => 'Elencos da temporada 2025-26 da NBA',
        'file'         => 'players.php',
        'start_year'   => 2026,
        'draftclasses' => draftsFrom(2026), // futuro: classes geradas automaticamente
    ],

    // ─────────────────────────────────────────────────────────────────────────
    'era2009' => [
        'name'         => 'Era Curry (2008-09)',
        'desc'         => 'Kobe/LeBron/Wade no auge — Curry, Harden e Westbrook entram pelo Draft',
        'file'         => 'eras/era2009.php',
        'start_year'   => 2009,
        'draftclasses' => draftsFrom(2009),
    ],

    // ─────────────────────────────────────────────────────────────────────────
    'era2016' => [
        'name'         => 'Era 2015-16',
        'desc'         => 'Warriors 73-9, MVP unânime do Curry; Cavs campeões',
        'file'         => 'eras/era2016.php',
        'start_year'   => 2016,
        'draftclasses' => draftsFrom(2016),
    ],

    // ─────────────────────────────────────────────────────────────────────────
    'era2003' => [
        'name'         => 'Era 2002-03 (Draft do LeBron)',
        'desc'         => 'Última dança do Jordan, Spurs de Duncan; Draft real de 2003',
        'file'         => 'eras/era2003.php',
        'start_year'   => 2003,
        // 29 times em 2002-03 (Charlotte Bobcats só em 2004-05)
        'teams'        => [
            'BOS','BKN','NYK','PHI','TOR',
            'CHI','CLE','DET','IND','MIL',
            'ATL','MIA','ORL','WAS',
            'DAL','HOU','MEM','NOP','SAS',
            'DEN','MIN','OKC','POR','UTA',
            'GSW','LAC','LAL','PHX','SAC',
        ],
        'expansions'   => [2005 => ['CHA']],
        'draftclasses' => draftsFrom(2003),
    ],

    // ─────────────────────────────────────────────────────────────────────────
    'era1997' => [
        'name'         => 'Era Jordan (1996-97)',
        'desc'         => 'Bulls do Jordan, Jazz de Malone/Stockton, Sonics de Payton',
        'file'         => 'eras/era1997.php',
        'start_year'   => 1997,
        // 29 times em 1996-97 (New Orleans só em 2002-03)
        'teams'        => [
            'BOS','BKN','NYK','PHI','TOR',
            'CHI','CLE','DET','IND','MIL',
            'ATL','CHA','MIA','ORL','WAS',
            'DAL','HOU','MEM','SAS',
            'DEN','MIN','OKC','POR','UTA',
            'GSW','LAC','LAL','PHX','SAC',
        ],
        'expansions'   => [2003 => ['NOP']],
        'draftclasses' => draftsFrom(1997),
    ],

    // ─────────────────────────────────────────────────────────────────────────
    'era1987' => [
        'name'         => 'Era Magic & Bird (1986-87)',
        'desc'         => 'Showtime Lakers x Celtics, Jordan 37 ppg',
        'file'         => 'eras/era1987.php',
        'start_year'   => 1987,
        // 23 times em 1986-87; expansões nos anos reais
        'teams'        => [
            'BOS','BKN','NYK','PHI',
            'CHI','CLE','DET','IND','MIL',
            'ATL','WAS',
            'DAL','HOU','SAS',
            'DEN','UTA','POR','OKC',
            'GSW','LAC','LAL','PHX','SAC',
        ],
        'expansions'   => [
            1989 => ['CHA','MIA'],
            1990 => ['MIN','ORL'],
            1996 => ['TOR','MEM'],
            2003 => ['NOP'],
        ],
        'draftclasses' => draftsFrom(1987),
    ],

    // ─────────────────────────────────────────────────────────────────────────
    'era1980' => [
        'name'         => 'Era Bird x Magic (1979-80)',
        'desc'         => 'Início da rivalidade Bird x Magic, ABA recém fundida',
        'file'         => 'eras/era1980.php',
        'start_year'   => 1980,
        'teams'        => [
            'BOS','BKN','NYK','PHI',
            'CHI','CLE','DET','IND','MIL',
            'ATL','WAS',
            'DAL','HOU','SAS',
            'DEN','UTA','POR','OKC',
            'GSW','LAC','LAL','PHX','SAC',
        ],
        'expansions'   => [
            1989 => ['CHA','MIA'],
            1990 => ['MIN','ORL'],
            1996 => ['TOR','MEM'],
            2003 => ['NOP'],
        ],
        'draftclasses' => draftsFrom(1980),
    ],

];
