<?php
/**
 * ── FBA SHOWDOWN: BAIXAR OS SPRITES ──────────────────────────────────
 *
 * Traz pro servidor os bonecos das 403 espécies da dex:
 *
 *   games/img/psd/frente/{num}.gif   animado, de frente  (o adversário)
 *   games/img/psd/costas/{num}.gif   animado, de costas  (o seu)
 *   games/img/psd/icone/{num}.png    parado, pequenininho (a fileira de troca)
 *
 * ── POR QUE BAIXAR, E NÃO APONTAR PRO GITHUB ─────────────────────────
 *
 * Dá pra usar a URL do espelho direto na tag <img> e não gastar um byte de
 * disco. Mas aí CADA partida de CADA GM vira dezenas de requisições pro
 * raw.githubusercontent, que não é CDN de imagem e limita por IP: no dia em
 * que ele cortar, o jogo fica sem boneco e ninguém entende por quê.
 *
 * São ~56 MB. Ficam no servidor e FORA DO GIT (ver .gitignore): binário
 * derivado não vai pra repositório — quem precisar, roda este CLI.
 *
 * ── COMO RODAR ───────────────────────────────────────────────────────
 *
 *   php games/core/psd_sprites_cli.php          (pula o que já tem)
 *   php games/core/psd_sprites_cli.php --forca  (rebaixa tudo)
 *
 * NA HOSPEDAGEM, e não na máquina do Marcos: o PHP dele não tem o pacote
 * de certificados e toda chamada HTTPS morre com "HTTP 0".
 */

require_once __DIR__ . '/psd_motor.php';

$opts  = getopt('', ['forca', 'so::']);
$FORCA = isset($opts['forca']);
$SO    = isset($opts['so']) ? max(1, (int)$opts['so']) : 0;   // só os N primeiros, pra testar

$BASE = 'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon';
$DEST = dirname(__DIR__) . '/img/psd';

$pastas = ['frente' => "$BASE/other/showdown/%d.gif",
           'costas' => "$BASE/other/showdown/back/%d.gif",
           'icone'  => "$BASE/%d.png"];

foreach (array_keys($pastas) as $p) {
    if (!is_dir("$DEST/$p") && !mkdir("$DEST/$p", 0775, true)) {
        fwrite(STDERR, "não consegui criar $DEST/$p\n");
        exit(1);
    }
}

function psdBaixar(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'fba-showdown-sprites/1.0',
    ]);
    $corpo = curl_exec($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($http === 200 && $corpo !== false && strlen($corpo) > 100) ? $corpo : null;
}

$dex = psdDex();
if ($SO) $dex = array_slice($dex, 0, $SO, true);

$baixados = 0; $pulados = 0; $bytes = 0;
$faltando = [];

$i = 0;
$total = count($dex);
foreach ($dex as $id => $esp) {
    $i++;
    $num = $esp['num'];
    $faltou = [];

    foreach ($pastas as $pasta => $molde) {
        $ext     = $pasta === 'icone' ? 'png' : 'gif';
        $arquivo = "$DEST/$pasta/$num.$ext";

        if (!$FORCA && is_file($arquivo) && filesize($arquivo) > 100) { $pulados++; continue; }

        $corpo = psdBaixar(sprintf($molde, $num));
        if ($corpo === null) { $faltou[] = $pasta; continue; }

        file_put_contents($arquivo, $corpo);
        $baixados++;
        $bytes += strlen($corpo);
        usleep(40000);   // 25 por segundo: não é corrida, e o espelho é de graça
    }

    if ($faltou) $faltando[$esp['nome'] . " (#$num)"] = $faltou;

    if ($i % 25 === 0 || $i === $total) {
        printf("  %3d/%d  baixados %d · pulados %d · %s MB\n",
            $i, $total, $baixados, $pulados, number_format($bytes / 1048576, 1));
    }
}

echo "\n";
printf("pronto: %d arquivos novos, %s MB, %d já estavam lá.\n",
    $baixados, number_format($bytes / 1048576, 1), $pulados);

if ($faltando) {
    echo "\nSEM SPRITE (a tela cai no desenho oficial parado):\n";
    foreach ($faltando as $nome => $quais) echo "   $nome — falta " . implode(', ', $quais) . "\n";
}
