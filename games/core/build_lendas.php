<?php
/**
 * Build-A-Player — elenco de lendas com nota curada à mão.
 *
 * A derivação por estatística funciona para Passe, Tamanho e Defesa, mas erra
 * feio em Velocidade e Impulsão: a base não tem nada atlético, então sobra
 * posição e era. Para as lendas mais conhecidas isso não serve — todo mundo
 * sabe que Iverson era mais rápido que Stockton.
 *
 * Então estas notas são atribuídas manualmente, uma a uma. Elas entram no
 * jogo com ajustado_manual = 1, o que faz a re-sincronização automática
 * respeitá-las: quem está aqui nunca é sobrescrito pela fórmula.
 *
 * Escala (índice em BUILD_LETRAS):
 *   0=F  1=D  2=D+  3=C  4=C+  5=B-  6=B  7=B+  8=A-  9=A  10=A+  11=S
 *
 * S é reservado ao traço que DEFINE o jogador na história — o arremesso do
 * Curry, o passe do Magic, a finalização do Shaq. Se todo mundo tem S em
 * algo, S deixa de significar alguma coisa.
 *
 * Ordem das notas: arremesso, finalização, passe, handles, defesa de
 * perímetro, velocidade, impulsão, tamanho, QI, clutch.
 */

// No topo, não dentro da função de gravar: quem só quer LISTAR o elenco
// (a tela do admin) também precisa de buildAtributos() e buildValorDaLetra().
require_once __DIR__ . '/build_notas.php';

/**
 * @return array<int, array{0:string,1:string,2:string,3:array<int,int>}>
 *         [nome, sigla do time, GUARD|BIG, [10 níveis]]
 */
function buildLendasCuradas(): array
{
    return [
        // ── GUARDS E ALAS ───────────────────────────────────────────────────
        //                                        arr fin pas han def vel imp tam  qi clu
        ['Michael Jordan',     'CHI', 'GUARD', [   9, 10,  7,  9, 10,  9, 10,  4, 10, 11]],
        ['LeBron James',       'CLE', 'GUARD', [   7, 11, 10,  9,  8,  9, 10,  8, 11,  9]],
        ['Kobe Bryant',        'LAL', 'GUARD', [   9,  9,  6,  8,  9,  8,  9,  4,  9, 10]],
        ['Stephen Curry',      'GSW', 'GUARD', [  11,  6,  8, 10,  3,  8,  3,  1,  9, 10]],
        ['Magic Johnson',      'LAL', 'GUARD', [   5,  8, 11,  9,  6,  7,  6,  8, 11, 10]],
        ['Larry Bird',         'BOS', 'GUARD', [  10,  8, 10,  6,  6,  3,  4,  7, 11, 10]],
        ['Kevin Durant',       'OKC', 'GUARD', [  11,  9,  7,  8,  7,  7,  8,  9,  9,  9]],
        ['Allen Iverson',      'PHI', 'GUARD', [   6,  8,  8, 11,  7, 11,  7,  1,  6,  8]],
        ['Dwyane Wade',        'MIA', 'GUARD', [   4, 10,  8,  8,  8,  9,  9,  3,  8,  9]],
        ['Jerry West',         'LAL', 'GUARD', [  10,  8,  8,  8,  9,  7,  6,  4,  9, 11]],
        ['Oscar Robertson',    'CIN', 'GUARD', [   8,  9, 10,  9,  7,  7,  6,  6, 10,  8]],
        ['John Stockton',      'UTA', 'GUARD', [   7,  6, 11, 10,  9,  7,  2,  2, 10,  7]],
        ['Steve Nash',         'PHX', 'GUARD', [   9,  6, 10, 10,  2,  7,  1,  2, 10,  8]],
        ['Ray Allen',          'BOS', 'GUARD', [  10,  5,  4,  6,  5,  7,  6,  4,  7,  9]],
        ['Reggie Miller',      'IND', 'GUARD', [  10,  4,  4,  5,  5,  6,  4,  4,  8, 10]],
        ['Scottie Pippen',     'CHI', 'GUARD', [   5,  8,  9,  8, 10,  8,  8,  7,  9,  7]],
        ['Clyde Drexler',      'POR', 'GUARD', [   6,  9,  8,  8,  7,  9, 10,  4,  7,  7]],
        ['Tracy McGrady',      'ORL', 'GUARD', [   8,  9,  8,  9,  6,  8,  9,  6,  7,  6]],
        ['Vince Carter',       'TOR', 'GUARD', [   8,  8,  5,  7,  5,  8, 11,  5,  6,  6]],
        ['James Harden',       'HOU', 'GUARD', [   9,  8, 10, 10,  3,  7,  5,  4,  9,  6]],
        ['Russell Westbrook',  'OKC', 'GUARD', [   3,  9,  9,  8,  7, 10, 10,  4,  6,  5]],
        ['Kawhi Leonard',      'SAS', 'GUARD', [   8,  8,  6,  7, 11,  7,  8,  7,  9, 10]],
        ['Gary Payton',        'SEA', 'GUARD', [   6,  7,  9,  9, 11,  8,  6,  4,  9,  7]],
        ['Jason Kidd',         'NJN', 'GUARD', [   3,  6, 10,  9,  9,  8,  5,  5, 10,  7]],
        ['Manu Ginobili',      'SAS', 'GUARD', [   8,  8,  8,  9,  7,  7,  7,  4,  9,  9]],
        ['Paul Pierce',        'BOS', 'GUARD', [   8,  8,  6,  7,  6,  5,  5,  6,  8,  9]],
        ['Klay Thompson',      'GSW', 'GUARD', [  10,  6,  3,  5,  8,  6,  5,  5,  7,  8]],
        ['Isiah Thomas',       'DET', 'GUARD', [   6,  8, 10, 10,  7,  9,  6,  2,  9,  9]],
        ['Chris Paul',         'LAC', 'GUARD', [   7,  6, 10, 10,  9,  7,  3,  2, 11,  7]],
        ['Dominique Wilkins',  'ATL', 'GUARD', [   6,  9,  4,  6,  4,  8, 10,  5,  5,  6]],
        ['Pete Maravich',      'NOP', 'GUARD', [   9,  7, 10, 11,  3,  7,  5,  4,  8,  6]],
        ['Damian Lillard',     'POR', 'GUARD', [  10,  7,  8,  9,  3,  7,  5,  2,  8, 10]],
        ['Luka Doncic',        'DAL', 'GUARD', [   9,  8, 10,  9,  3,  4,  3,  7, 10,  9]],
        ['Jimmy Butler',       'MIA', 'GUARD', [   6,  8,  7,  7,  9,  6,  6,  6,  8, 10]],
        ['George Gervin',      'SAS', 'GUARD', [   9,  9,  4,  7,  4,  7,  6,  5,  7,  7]],
        ['Elgin Baylor',       'LAL', 'GUARD', [   7,  9,  7,  7,  6,  8,  9,  6,  8,  8]],
        ['Julius Erving',      'PHI', 'GUARD', [   6, 10,  7,  8,  7,  9, 10,  7,  8,  8]],
        ['Kyrie Irving',       'CLE', 'GUARD', [   9,  9,  7, 11,  3,  7,  5,  3,  7,  9]],
        ['Devin Booker',       'PHX', 'GUARD', [   9,  7,  7,  7,  4,  6,  5,  4,  8,  8]],
        ['Paul George',        'IND', 'GUARD', [   8,  7,  6,  6,  9,  7,  7,  7,  7,  5]],

        // ── BIGS ────────────────────────────────────────────────────────────
        //                                        arr fin pas han def vel imp tam  qi clu
        ['Kareem Abdul-Jabbar','LAL', 'BIG',   [   8, 11,  7,  4,  6,  5,  7, 10, 10,  9]],
        ['Wilt Chamberlain',  'PHI', 'BIG',   [   3, 10,  6,  3,  7,  7, 10, 11,  7,  6]],
        ['Bill Russell',      'BOS', 'BIG',   [   2,  6,  7,  3,  9,  7,  9,  9, 11, 11]],
        ['Shaquille O\'Neal', 'LAL', 'BIG',   [   1, 11,  4,  3,  3,  4,  8, 11,  6,  8]],
        ['Tim Duncan',        'SAS', 'BIG',   [   6,  9,  7,  4,  7,  4,  6,  9, 11, 10]],
        ['Hakeem Olajuwon',   'HOU', 'BIG',   [   5, 10,  6,  6,  9,  7,  9,  8, 10,  9]],
        ['Karl Malone',       'UTA', 'BIG',   [   5,  9,  6,  4,  6,  6,  7,  8,  7,  4]],
        ['Charles Barkley',   'PHX', 'BIG',   [   6,  9,  7,  7,  6,  7,  8,  7,  8,  7]],
        ['Kevin Garnett',     'MIN', 'BIG',   [   7,  8,  8,  6,  9,  7,  8,  8, 10,  7]],
        ['Dirk Nowitzki',     'DAL', 'BIG',   [  10,  8,  6,  5,  4,  3,  4,  9,  9, 10]],
        ['David Robinson',    'SAS', 'BIG',   [   6,  9,  6,  4,  8,  8,  9,  9,  8,  6]],
        ['Moses Malone',      'HOU', 'BIG',   [   4,  9,  3,  3,  6,  5,  7,  8,  7,  7]],
        ['Dennis Rodman',     'CHI', 'BIG',   [   1,  3,  3,  3,  9,  7,  9,  6,  8,  6]],
        ['Dikembe Mutombo',   'DEN', 'BIG',   [   0,  5,  1,  1,  7,  3,  8,  9,  6,  3]],
        ['Ben Wallace',       'DET', 'BIG',   [   0,  3,  2,  1,  8,  6,  9,  7,  7,  5]],
        ['Patrick Ewing',     'NYK', 'BIG',   [   6,  8,  4,  4,  8,  5,  7,  9,  7,  6]],
        ['Kevin McHale',      'BOS', 'BIG',   [   6, 10,  5,  4,  7,  4,  6,  8,  8,  8]],
        ['Giannis Antetokounmpo','MIL','BIG', [   3, 11,  7,  7,  9,  9, 10, 10,  7,  8]],
        ['Nikola Jokic',      'DEN', 'BIG',   [   8,  9, 11,  7,  4,  2,  2,  9, 11,  9]],
        ['Anthony Davis',     'LAL', 'BIG',   [   6,  9,  5,  5,  8,  7,  9,  9,  7,  7]],
        ['Pau Gasol',         'LAL', 'BIG',   [   6,  8,  7,  4,  6,  3,  5,  8,  9,  7]],
        ['Chris Webber',      'SAC', 'BIG',   [   6,  8,  8,  6,  6,  6,  8,  8,  8,  4]],
        ['Bob Pettit',        'ATL', 'BIG',   [   6,  9,  4,  4,  6,  5,  7,  7,  8,  8]],
        ['Willis Reed',       'NYK', 'BIG',   [   5,  8,  4,  3,  7,  5,  6,  8,  8,  9]],
        ['Alonzo Mourning',   'MIA', 'BIG',   [   3,  8,  3,  3,  8,  5,  8,  8,  7,  7]],
        ['Yao Ming',          'HOU', 'BIG',   [   6,  9,  4,  3,  6,  2,  3, 11,  8,  6]],
        ['Bill Walton',       'POR', 'BIG',   [   4,  8,  8,  4,  8,  5,  6,  9, 10,  8]],
        ['Artis Gilmore',     'CHI', 'BIG',   [   2,  9,  3,  2,  7,  4,  7, 10,  6,  5]],
        ['Elvin Hayes',       'WAS', 'BIG',   [   5,  8,  3,  3,  7,  5,  7,  8,  6,  6]],
        ['Draymond Green',    'GSW', 'BIG',   [   3,  5,  9,  6, 10,  7,  6,  5, 11,  7]],
    ];
}

/**
 * Grava as lendas curadas: cria quem faltar em hoopgrid_players e escreve as
 * notas com ajustado_manual = 1.
 *
 * O casamento é pelo NOME. Se a lenda já existe na base (veio do seeder da
 * NBA), reaproveita a linha em vez de duplicar — inclusive porque nome é
 * UNIQUE lá.
 */
function buildAplicarLendasCuradas(PDO $pdo): array
{
    buildGarantirTabelaNotas($pdo);

    $attrs = array_keys(buildAtributos());
    $cols  = implode(', ', array_map(fn($a) => "`$a`", $attrs));
    $ph    = implode(', ', array_fill(0, count($attrs), '?'));
    $upd   = implode(', ', array_map(fn($a) => "`$a` = VALUES(`$a`)", $attrs));

    $buscar  = $pdo->prepare("SELECT id FROM hoopgrid_players WHERE nome = ? LIMIT 1");
    $criar   = $pdo->prepare("INSERT INTO hoopgrid_players (nome, time_atual, ativo) VALUES (?, ?, 0)");
    $gravar  = $pdo->prepare("INSERT INTO build_notas (player_id, posicao_grupo, $cols, ovr, ajustado_manual)
                              VALUES (?, ?, $ph, ?, 1)
                              ON DUPLICATE KEY UPDATE posicao_grupo = VALUES(posicao_grupo), $upd,
                                                      ovr = VALUES(ovr), ajustado_manual = 1");

    $criados = 0;
    $notas   = 0;

    foreach (buildLendasCuradas() as [$nome, $time, $grupo, $niveis]) {
        if (count($niveis) !== count($attrs)) {
            error_log("[build_lendas] {$nome}: esperava " . count($attrs) . " notas, veio " . count($niveis));
            continue;
        }

        $buscar->execute([$nome]);
        $playerId = (int)($buscar->fetchColumn() ?: 0);
        if (!$playerId) {
            $criar->execute([$nome, $time]);
            $playerId = (int)$pdo->lastInsertId();
            $criados++;
        }

        $soma = 0;
        foreach ($niveis as $n) $soma += buildValorDaLetra((int)$n);
        $ovr = (int)round($soma / count($niveis));

        $gravar->execute(array_merge([$playerId, $grupo], array_map('intval', $niveis), [$ovr]));
        $notas++;
    }

    return ['lendas' => count(buildLendasCuradas()), 'criados' => $criados, 'notas' => $notas];
}
