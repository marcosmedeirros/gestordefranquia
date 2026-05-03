<?php
/**
 * Baixa o léxico pt-br do GitHub, filtra palavras de 5 letras e gera wordlist5.php.
 * Execute uma vez via SSH:
 *   php games/core/gerar-wordlist.php
 */

$url = 'https://raw.githubusercontent.com/fserb/pt-br/master/lexico';

echo "Baixando léxico...\n";
$raw = @file_get_contents($url);
if (!$raw) {
    die("Erro: não foi possível baixar o léxico de {$url}\n");
}
echo "Download OK (" . round(strlen($raw) / 1024) . " KB)\n";

function normaliza(string $w): string {
    $w = mb_strtoupper($w, 'UTF-8');
    return strtr($w, [
        'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A',
        'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
        'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
        'Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O',
        'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
        'Ç'=>'C',
    ]);
}

$lista = [];
foreach (explode("\n", $raw) as $linha) {
    $palavra = trim($linha);
    if (!$palavra) continue;
    $norm = normaliza($palavra);
    if (mb_strlen($norm) === 5 && preg_match('/^[A-Z]+$/', $norm)) {
        $lista[$norm] = true;
    }
}
$lista = array_keys($lista);
sort($lista);

$saida  = "<?php\n";
$saida .= "// Gerado automaticamente por gerar-wordlist.php — não edite manualmente.\n";
$saida .= "\$wordlist5 = [\n";
foreach (array_chunk($lista, 10) as $chunk) {
    $saida .= "    '" . implode("','", $chunk) . "',\n";
}
$saida .= "];\n";

$destino = __DIR__ . '/wordlist5.php';
file_put_contents($destino, $saida);
echo "Gerado: " . count($lista) . " palavras salvas em {$destino}\n";
