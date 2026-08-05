<?php
/**
 * Regras de xadrez do lado do SERVIDOR — o suficiente para conferir se uma posição é mesmo
 * xeque-mate ou afogamento (stalemate).
 *
 * Existe porque o fim de partida do xadrez chegava como `game_over=true` vindo do navegador, e o
 * servidor pagava o prêmio (2x a aposta) confiando nisso. Qualquer jogador podia, na sua vez,
 * declarar vitória e levar a aposta do adversário. Agora o servidor recebe a FEN resultante e
 * decide sozinho se a partida acabou.
 *
 * Não é uma engine completa: gera lances pseudo-legais, descarta os que deixam o próprio rei em
 * xeque e responde "existe algum lance legal?". Isso basta para mate/afogamento. Roque é
 * ignorado de propósito — quando o roque é legal, o rei também tem o lance simples de uma casa
 * para a mesma direção, então ele nunca é o único lance disponível.
 */

/** Converte a FEN em ['board'=>8x8, 'turn'=>'w'|'b', 'ep'=>casa ou null]. Null se a FEN for inválida. */
function xadrezParseFen(string $fen): ?array
{
    $partes = preg_split('/\s+/', trim($fen));
    if (!$partes || count($partes) < 2) return null;

    $linhas = explode('/', $partes[0]);
    if (count($linhas) !== 8) return null;

    $board = [];
    foreach ($linhas as $r => $linha) {
        $f = 0;
        $len = strlen($linha);
        for ($i = 0; $i < $len; $i++) {
            $c = $linha[$i];
            if (ctype_digit($c)) {
                $n = (int)$c;
                if ($n < 1 || $n > 8) return null;
                for ($k = 0; $k < $n; $k++) {
                    if ($f > 7) return null;
                    $board[$r][$f++] = null;
                }
            } elseif (strpbrk($c, 'prnbqkPRNBQK') !== false) {
                if ($f > 7) return null;
                $board[$r][$f++] = $c;
            } else {
                return null;
            }
        }
        if ($f !== 8) return null;
    }

    $turn = (($partes[1] ?? 'w') === 'b') ? 'b' : 'w';

    $ep = null;
    $epStr = $partes[3] ?? '-';
    if ($epStr !== '-' && preg_match('/^[a-h][1-8]$/', $epStr)) {
        $ep = [8 - (int)$epStr[1], ord($epStr[0]) - 97];   // [rank, file] com rank 0 = 8ª fileira
    }

    return ['board' => $board, 'turn' => $turn, 'ep' => $ep];
}

/** 'w' para peça maiúscula, 'b' para minúscula, null se vazio. */
function xadrezCor(?string $peca): ?string
{
    if ($peca === null) return null;
    return ctype_upper($peca) ? 'w' : 'b';
}

/** A casa (r,f) está sendo atacada por alguma peça da cor $porCor? */
function xadrezCasaAtacada(array $b, int $r, int $f, string $porCor): bool
{
    // Peões: um peão branco em (r+1, f±1) ataca (r,f); preto vem de (r-1, f±1).
    $dr = ($porCor === 'w') ? 1 : -1;
    foreach ([-1, 1] as $df) {
        $rr = $r + $dr; $ff = $f + $df;
        if ($rr >= 0 && $rr < 8 && $ff >= 0 && $ff < 8) {
            $p = $b[$rr][$ff] ?? null;
            if ($p !== null && xadrezCor($p) === $porCor && strtolower($p) === 'p') return true;
        }
    }

    // Cavalos
    foreach ([[-2,-1],[-2,1],[-1,-2],[-1,2],[1,-2],[1,2],[2,-1],[2,1]] as [$dr2, $df2]) {
        $rr = $r + $dr2; $ff = $f + $df2;
        if ($rr < 0 || $rr > 7 || $ff < 0 || $ff > 7) continue;
        $p = $b[$rr][$ff] ?? null;
        if ($p !== null && xadrezCor($p) === $porCor && strtolower($p) === 'n') return true;
    }

    // Rei adversário (casas vizinhas)
    foreach ([[-1,-1],[-1,0],[-1,1],[0,-1],[0,1],[1,-1],[1,0],[1,1]] as [$dr3, $df3]) {
        $rr = $r + $dr3; $ff = $f + $df3;
        if ($rr < 0 || $rr > 7 || $ff < 0 || $ff > 7) continue;
        $p = $b[$rr][$ff] ?? null;
        if ($p !== null && xadrezCor($p) === $porCor && strtolower($p) === 'k') return true;
    }

    // Deslizantes: torre/dama nas retas, bispo/dama nas diagonais
    $direcoes = [
        'rq' => [[-1,0],[1,0],[0,-1],[0,1]],
        'bq' => [[-1,-1],[-1,1],[1,-1],[1,1]],
    ];
    foreach ($direcoes as $tipos => $dirs) {
        foreach ($dirs as [$dr4, $df4]) {
            $rr = $r + $dr4; $ff = $f + $df4;
            while ($rr >= 0 && $rr < 8 && $ff >= 0 && $ff < 8) {
                $p = $b[$rr][$ff] ?? null;
                if ($p !== null) {
                    if (xadrezCor($p) === $porCor && strpos($tipos, strtolower($p)) !== false) return true;
                    break;   // peça bloqueia a linha
                }
                $rr += $dr4; $ff += $df4;
            }
        }
    }

    return false;
}

/** Posição do rei da cor informada, ou null se não houver (FEN corrompida). */
function xadrezAcharRei(array $b, string $cor): ?array
{
    $alvo = ($cor === 'w') ? 'K' : 'k';
    for ($r = 0; $r < 8; $r++) {
        for ($f = 0; $f < 8; $f++) {
            if (($b[$r][$f] ?? null) === $alvo) return [$r, $f];
        }
    }
    return null;
}

/** O rei da cor informada está em xeque? */
function xadrezEmXeque(array $b, string $cor): bool
{
    $rei = xadrezAcharRei($b, $cor);
    if (!$rei) return false;
    return xadrezCasaAtacada($b, $rei[0], $rei[1], $cor === 'w' ? 'b' : 'w');
}

/** Existe pelo menos UM lance legal para quem está na vez? */
function xadrezTemLanceLegal(array $estado): bool
{
    $b = $estado['board'];
    $cor = $estado['turn'];
    $inimigo = ($cor === 'w') ? 'b' : 'w';

    // Simula o lance e verifica se o próprio rei fica seguro.
    $legal = function (array $b, int $r1, int $f1, int $r2, int $f2, ?array $capturaEp = null) use ($cor): bool {
        $b[$r2][$f2] = $b[$r1][$f1];
        $b[$r1][$f1] = null;
        if ($capturaEp !== null) $b[$capturaEp[0]][$capturaEp[1]] = null;
        return !xadrezEmXeque($b, $cor);
    };

    for ($r = 0; $r < 8; $r++) {
        for ($f = 0; $f < 8; $f++) {
            $p = $b[$r][$f] ?? null;
            if ($p === null || xadrezCor($p) !== $cor) continue;
            $tipo = strtolower($p);

            if ($tipo === 'p') {
                $dr = ($cor === 'w') ? -1 : 1;             // brancas sobem (rank 0 = 8ª fileira)
                $inicial = ($cor === 'w') ? 6 : 1;
                // Avanço simples
                $rr = $r + $dr;
                if ($rr >= 0 && $rr < 8 && ($b[$rr][$f] ?? null) === null) {
                    if ($legal($b, $r, $f, $rr, $f)) return true;
                    // Avanço duplo
                    $rr2 = $r + 2 * $dr;
                    if ($r === $inicial && ($b[$rr2][$f] ?? null) === null && $legal($b, $r, $f, $rr2, $f)) return true;
                }
                // Capturas (inclusive en passant)
                foreach ([-1, 1] as $df) {
                    $ff = $f + $df;
                    if ($rr < 0 || $rr > 7 || $ff < 0 || $ff > 7) continue;
                    $alvo = $b[$rr][$ff] ?? null;
                    if ($alvo !== null && xadrezCor($alvo) === $inimigo && $legal($b, $r, $f, $rr, $ff)) return true;
                    if ($alvo === null && $estado['ep'] !== null
                        && $estado['ep'][0] === $rr && $estado['ep'][1] === $ff
                        && $legal($b, $r, $f, $rr, $ff, [$r, $ff])) return true;
                }
                continue;
            }

            if ($tipo === 'n' || $tipo === 'k') {
                $saltos = ($tipo === 'n')
                    ? [[-2,-1],[-2,1],[-1,-2],[-1,2],[1,-2],[1,2],[2,-1],[2,1]]
                    : [[-1,-1],[-1,0],[-1,1],[0,-1],[0,1],[1,-1],[1,0],[1,1]];
                foreach ($saltos as [$dr5, $df5]) {
                    $rr = $r + $dr5; $ff = $f + $df5;
                    if ($rr < 0 || $rr > 7 || $ff < 0 || $ff > 7) continue;
                    $alvo = $b[$rr][$ff] ?? null;
                    if ($alvo !== null && xadrezCor($alvo) === $cor) continue;   // casa própria
                    if ($legal($b, $r, $f, $rr, $ff)) return true;
                }
                continue;
            }

            // Deslizantes
            $dirs = [];
            if ($tipo === 'r' || $tipo === 'q') $dirs = array_merge($dirs, [[-1,0],[1,0],[0,-1],[0,1]]);
            if ($tipo === 'b' || $tipo === 'q') $dirs = array_merge($dirs, [[-1,-1],[-1,1],[1,-1],[1,1]]);
            foreach ($dirs as [$dr6, $df6]) {
                $rr = $r + $dr6; $ff = $f + $df6;
                while ($rr >= 0 && $rr < 8 && $ff >= 0 && $ff < 8) {
                    $alvo = $b[$rr][$ff] ?? null;
                    if ($alvo !== null && xadrezCor($alvo) === $cor) break;
                    if ($legal($b, $r, $f, $rr, $ff)) return true;
                    if ($alvo !== null) break;   // capturou: para por aqui
                    $rr += $dr6; $ff += $df6;
                }
            }
        }
    }

    return false;
}

/**
 * Analisa a FEN e devolve como a partida terminou, segundo o servidor:
 *   ['fim' => bool, 'empate' => bool, 'motivo' => 'mate'|'afogamento'|'material'|null]
 * Devolve fim=false se a FEN for inválida — nesse caso nada é pago.
 */
function xadrezAnalisarFim(string $fen): array
{
    $estado = xadrezParseFen($fen);
    if ($estado === null) return ['fim' => false, 'empate' => false, 'motivo' => null];

    if (!xadrezTemLanceLegal($estado)) {
        if (xadrezEmXeque($estado['board'], $estado['turn'])) {
            return ['fim' => true, 'empate' => false, 'motivo' => 'mate'];
        }
        return ['fim' => true, 'empate' => true, 'motivo' => 'afogamento'];
    }

    // Material insuficiente (rei vs rei, rei+bispo, rei+cavalo) — empate técnico.
    $pecas = [];
    foreach ($estado['board'] as $linha) {
        foreach ($linha as $p) {
            if ($p !== null && strtolower($p) !== 'k') $pecas[] = strtolower($p);
        }
    }
    if (count($pecas) === 0 || (count($pecas) === 1 && in_array($pecas[0], ['b', 'n'], true))) {
        return ['fim' => true, 'empate' => true, 'motivo' => 'material'];
    }

    return ['fim' => false, 'empate' => false, 'motivo' => null];
}
