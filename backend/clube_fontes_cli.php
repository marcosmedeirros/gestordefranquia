<?php
/**
 * ENCHE O POOL DO CLUBE — roda na mão, ou no cron.
 *
 *   php backend/clube_fontes_cli.php              (uma passada em tudo)
 *   php backend/clube_fontes_cli.php --voltas=5   (insiste mais)
 *   php backend/clube_fontes_cli.php --so=album
 *   php backend/clube_fontes_cli.php --limpar     (tira o que o filtro de
 *                                                  hoje não deixaria entrar)
 *
 * O abastecimento também acontece sozinho quando a enquete nasce e o pool
 * está magro (@see clubeFonteSortear), mas ali ele custa a espera de quem
 * abriu a página — são chamadas de rede dentro do request. Rodando por fora,
 * o GM da sexta encontra tudo pronto.
 *
 * Nada aqui apaga: é INSERT IGNORE em cima de uma chave única, então rodar
 * duas vezes não duplica nem estraga o que já está lá.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/clube_semana.php';

$voltas = 3;
$so = '';
$limpar = in_array('--limpar', $argv, true);
foreach ($argv as $a) {
    if (preg_match('/^--voltas=(\d+)$/', $a, $m)) $voltas = max(1, min(30, (int)$m[1]));
    if (preg_match('/^--so=(album|filme|livro)$/', $a, $m)) $so = $m[1];
}

$pdo = db();
clubeFonteTabela($pdo);

/* ── FAXINA ───────────────────────────────────────────────────────────
   Os filtros melhoram depois que o pool já está cheio — foi assim com o
   "(Remixes)", que passava pela borda da palavra, e com um Dostoiévski em
   cirílico que ninguém reconheceria na enquete. Sem isto, apertar o filtro
   só valeria pro que entrasse dali pra frente, e o lixo velho ficaria
   sorteável pra sempre. */
if ($limpar) {
    $fora = [];
    foreach ($pdo->query('SELECT id, tipo, titulo FROM clube_semana_pool') as $r) {
        $t = (string)$r['titulo'];
        $ruim = !clubeFonteAlfabetoOk($t)
             || ($r['tipo'] === 'album' && clubeFonteAlbumRuim($t, 'album'));
        if ($ruim) $fora[] = ['id' => (int)$r['id'], 'tipo' => (string)$r['tipo'], 'titulo' => $t];
    }
    if (!$fora) { echo "faxina: nada a tirar\n\n"; }
    else {
        foreach (array_slice($fora, 0, 12) as $x) printf("  tiro %-6s %s\n", $x['tipo'], mb_substr($x['titulo'], 0, 52));
        if (count($fora) > 12) printf("  ... e mais %d\n", count($fora) - 12);
        $pdo->exec('DELETE FROM clube_semana_pool WHERE id IN ('
                 . implode(',', array_column($fora, 'id')) . ')');
        printf("faxina: %d obras fora\n\n", count($fora));
    }
}

function conta(PDO $pdo, string $tipo, string $genero = ''): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM clube_semana_pool WHERE tipo = ? AND genero = ?');
    $st->execute([$tipo, $genero]);
    return (int)$st->fetchColumn();
}

$antes = (int)$pdo->query('SELECT COUNT(*) FROM clube_semana_pool')->fetchColumn();
printf("pool antes: %d obras\n\n", $antes);

/* ── Álbuns e filmes: pool único por tipo ────────────────────────────── */
foreach (['album', 'filme'] as $tipo) {
    if ($so !== '' && $so !== $tipo) continue;
    $de = conta($pdo, $tipo);
    for ($v = 0; $v < $voltas; $v++) {
        clubeFonteAbastecer($pdo, $tipo, '', true);
        /* Respira entre as voltas: as duas fontes são gratuitas e públicas,
           e bater nelas em rajada é o jeito certo de ser bloqueado. */
        usleep(400000);
    }
    printf("%-7s %d -> %d\n", $tipo, $de, conta($pdo, $tipo));
}

/* ── Livros: um pool por gênero, porque a enquete sorteia dentro dele ── */
if ($so === '' || $so === 'livro') {
    foreach (array_keys(CLUBE_LIVROS) as $g) {
        $de = conta($pdo, 'livro', $g);
        for ($v = 0; $v < $voltas; $v++) {
            clubeFonteAbastecer($pdo, 'livro', $g, true);
            usleep(400000);
        }
        printf("livro   %-26s %d -> %d\n", $g, $de, conta($pdo, 'livro', $g));
    }
}

$depois = (int)$pdo->query('SELECT COUNT(*) FROM clube_semana_pool')->fetchColumn();
printf("\npool depois: %d obras (+%d)\n", $depois, $depois - $antes);

/* Um retrato do que entrou, pra conferir que não veio lixo. */
echo "\namostra:\n";
foreach ($pdo->query("SELECT tipo, genero, titulo, autor, ano FROM clube_semana_pool
                       ORDER BY RAND() LIMIT 8") as $r) {
    printf("  %-6s %-22s %-38s %-22s %s\n", $r['tipo'], mb_substr((string)$r['genero'], 0, 21),
        mb_substr((string)$r['titulo'], 0, 37), mb_substr((string)$r['autor'], 0, 21), $r['ano']);
}
