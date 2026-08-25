<?php
/**
 * O SITEMAP, gerado do banco.
 *
 * O sitemap.xml antigo era um arquivo fixo com três endereços. Só que o
 * conteúdo que mais rende busca é o que MUDA: cada matéria do The Pathetic
 * é uma página com título, texto e foto próprios — e nenhuma delas estava
 * listada. O Google acha o que está linkado, mas demora mais e às vezes não
 * volta; o sitemap é o atalho.
 *
 * Fica em .php e não em .xml porque precisa consultar o banco. O
 * /sitemap.xml continua respondendo: o .htaccess reescreve pra cá, então
 * quem já tinha o endereço antigo (inclusive o Google) não perde nada.
 */

require_once __DIR__ . '/backend/db.php';

header('Content-Type: application/xml; charset=utf-8');

$base = 'https://fbabrasil.com.br';

/** Escapa pro XML. & solto num <loc> invalida o arquivo inteiro. */
$x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

/** A data no formato que o sitemap espera (W3C). */
$dia = function ($v) {
    $t = $v ? strtotime((string)$v) : false;
    return $t ? date('Y-m-d', $t) : date('Y-m-d');
};

// As páginas fixas. A home é a mais importante e muda todo dia (os números
// das ligas saem do banco); as outras duas mudam quando há novidade.
$urls = [
    ['loc' => $base . '/',                     'freq' => 'daily',  'pri' => '1.0'],
    ['loc' => $base . '/site/pathetic.php',    'freq' => 'daily',  'pri' => '0.8'],
    ['loc' => $base . '/site/gamesfba.php',    'freq' => 'weekly', 'pri' => '0.6'],
];

// Cada matéria publicada do The Pathetic.
try {
    $pdo = db();
    $st = $pdo->query("SELECT id, COALESCE(editada_em, publicada_em, criada_em) AS quando
                         FROM pathetic_noticias
                        WHERE publicada = 1
                        ORDER BY id DESC
                        LIMIT 500");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) {
        $urls[] = [
            'loc'  => $base . '/site/pathetic.php?n=' . (int)$n['id'],
            'freq' => 'monthly',
            'pri'  => '0.7',
            'mod'  => $n['quando'],
        ];
    }
} catch (Throwable $e) {
    // Banco fora do ar não pode derrubar o sitemap: as páginas fixas saem
    // do mesmo jeito, e um sitemap menor é melhor que um erro 500 — que o
    // Google registra como "sitemap quebrado".
    error_log('[sitemap] ' . $e->getMessage());
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n";
    echo '    <loc>' . $x($u['loc']) . "</loc>\n";
    if (!empty($u['mod'])) echo '    <lastmod>' . $x($dia($u['mod'])) . "</lastmod>\n";
    echo '    <changefreq>' . $u['freq'] . "</changefreq>\n";
    echo '    <priority>' . $u['pri'] . "</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>' . "\n";
