<?php
/**
 * TIRA AS CORES DE CADA CLUBE DO ESCUDO DELE.
 *
 *   php games/core/fut_importar_cores_cli.php [--gravar] [--so=Porto]
 *
 * Grava games/core/fut_cores.php: nome do clube => [cor principal, segunda].
 *
 * ── POR QUE EXTRAIR, E NÃO DIGITAR ───────────────────────────────────
 *
 * São 212 clubes. Uma lista escrita à mão seria 212 chances de errar a cor de
 * um clube que o dono do jogo conhece de cor — e errar a cor do Porto é pior
 * do que não ter cor nenhuma. O escudo JÁ É a fonte certa: ele está no jogo,
 * veio conferido, e as cores dele são as cores do clube por definição.
 *
 * ── POR QUE UMA VEZ SÓ, E NÃO NA HORA DE MOSTRAR ─────────────────────
 *
 * Extrair cor no navegador exigiria carregar 20 escudos por tela e passar cada
 * um por canvas — e canvas com imagem de outro domínio contamina a tela
 * (@see api/foto-proxy.php). Extrair no servidor a cada acesso seria baixar a
 * mesma imagem de novo pra sempre. Aqui roda na mão, o resultado é um arquivo
 * PHP, e a tela só lê.
 *
 * ── COMO A COR É ESCOLHIDA ───────────────────────────────────────────
 *
 * Contagem de pixels agrupados por matiz, jogando fora o que não é cor: o
 * transparente, o quase-branco e o quase-preto. Esse descarte é o que faz a
 * coisa funcionar — quase todo escudo tem fundo branco e contorno preto, e sem
 * ele 90% dos clubes do mundo seriam cinza.
 *
 * O branco e o preto voltam SÓ quando não sobra mais nada: o Real Madrid e a
 * Juventus são brancos e pretos de verdade, e devolver cinza pra eles seria
 * tão errado quanto devolver cinza pro Porto.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!function_exists('imagecreatefromstring')) {
    fwrite(STDERR, "precisa da extensao GD\n");
    exit(1);
}

require_once __DIR__ . '/fut_europa.php';

$gravar = in_array('--gravar', $argv, true);
$so = '';
foreach ($argv as $a) if (str_starts_with($a, '--so=')) $so = substr($a, 5);

/** Baixa (ou lê do disco) o escudo. O cache evita rebaixar 212 imagens a cada volta. */
function coresBaixar(string $escudo): ?string
{
    if ($escudo === '') return null;

    if (!str_starts_with($escudo, 'http')) {
        /* DUAS FORMAS, e as duas existem no catálogo: '../img/escudos/x.png',
           que é relativo a games/ (de onde a tela pede), e '/games/img/...',
           que é caminho do site. As duas apontam pro mesmo arquivo — aqui elas
           viram caminho de disco. */
        $rel = preg_replace('#^(\.\./|/games/)#', '', $escudo);
        $caminho = __DIR__ . '/../' . ltrim((string)$rel, '/');
        return is_file($caminho) ? (file_get_contents($caminho) ?: null) : null;
    }

    $cache = sys_get_temp_dir() . '/fut_escudo_' . md5($escudo) . '.img';
    if (is_file($cache)) return file_get_contents($cache) ?: null;

    $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'Mozilla/5.0']]);
    $bin = @file_get_contents($escudo, false, $ctx);
    if ($bin === false || strlen($bin) < 200) return null;
    @file_put_contents($cache, $bin);
    return $bin;
}

/** Clareia ou escurece até a cor render num fundo escuro, sem mexer no matiz. */
function coresAjustar(array $rgb, float $alvoMin = 0.42, float $alvoMax = 0.72): string
{
    [$h, $s, $l] = coresParaHsl($rgb);

    /* SATURAÇÃO TEM PISO. Escudo com azul acinzentado devolve uma cor que, no
       fundo escuro do jogo, some — vira o mesmo cinza da borda. Puxar pra 0,45
       mantém o matiz e devolve a cor. */
    if ($s > 0.06) $s = max(0.45, min(0.9, $s));

    $l = max($alvoMin, min($alvoMax, $l));
    return coresParaHex(coresDeHsl($h, $s, $l));
}

function coresParaHsl(array $rgb): array
{
    [$r, $g, $b] = array_map(fn($v) => $v / 255, $rgb);
    $max = max($r, $g, $b); $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max === $min) return [0.0, 0.0, $l];
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    $h = match (true) {
        $max === $r => (($g - $b) / $d + ($g < $b ? 6 : 0)) / 6,
        $max === $g => (($b - $r) / $d + 2) / 6,
        default     => (($r - $g) / $d + 4) / 6,
    };
    return [$h, $s, $l];
}

function coresDeHsl(float $h, float $s, float $l): array
{
    if ($s == 0.0) { $v = (int)round($l * 255); return [$v, $v, $v]; }
    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
    $p = 2 * $l - $q;
    $canal = function (float $t) use ($p, $q): int {
        if ($t < 0) $t += 1;
        if ($t > 1) $t -= 1;
        if ($t < 1 / 6) return (int)round(($p + ($q - $p) * 6 * $t) * 255);
        if ($t < 1 / 2) return (int)round($q * 255);
        if ($t < 2 / 3) return (int)round(($p + ($q - $p) * (2 / 3 - $t) * 6) * 255);
        return (int)round($p * 255);
    };
    return [$canal($h + 1 / 3), $canal($h), $canal($h - 1 / 3)];
}

function coresParaHex(array $rgb): string
{
    return sprintf('#%02x%02x%02x', ...array_map(fn($v) => max(0, min(255, (int)$v)), $rgb));
}

/**
 * As duas cores dominantes de uma imagem.
 *
 * @return array{0:string,1:string}|null
 */
function coresDoEscudo(string $bin): ?array
{
    $im = @imagecreatefromstring($bin);
    if (!$im) return null;

    $w = imagesx($im); $h = imagesy($im);
    $passo = max(1, (int)floor(min($w, $h) / 70));   // ~70x70 amostras basta

    $baldes = [];        // matiz (24 fatias) + claro/escuro => [n, soma rgb]
    $neutros = [];       // branco e preto, guardados pra quando não sobrar cor
    for ($y = 0; $y < $h; $y += $passo) {
        for ($x = 0; $x < $w; $x += $passo) {
            $c = imagecolorat($im, $x, $y);
            $a = ($c >> 24) & 0x7F;
            if ($a > 60) continue;                    // transparente não conta
            $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;

            [$hh, $ss, $ll] = coresParaHsl([$r, $g, $b]);
            $ehNeutro = $ss < 0.16 || $ll > 0.93 || $ll < 0.07;
            $chave = $ehNeutro
                ? ($ll > 0.5 ? 'branco' : 'preto')
                : ((int)floor($hh * 24) . '|' . ($ll > 0.5 ? 'c' : 'e'));

            $alvo = $ehNeutro ? 'neutros' : 'baldes';
            ${$alvo}[$chave] ??= [0, 0, 0, 0];
            ${$alvo}[$chave][0]++;
            ${$alvo}[$chave][1] += $r; ${$alvo}[$chave][2] += $g; ${$alvo}[$chave][3] += $b;
        }
    }
    imagedestroy($im);

    uasort($baldes, fn($a, $b) => $b[0] <=> $a[0]);
    uasort($neutros, fn($a, $b) => $b[0] <=> $a[0]);

    $media = fn(array $b) => [$b[1] / $b[0], $b[2] / $b[0], $b[3] / $b[0]];
    $lista = array_map($media, array_slice(array_values($baldes), 0, 2));

    /* ── O CLUBE PRETO-E-BRANCO ───────────────────────────────────────
       Descartar o neutro é o que faz a extração funcionar, e é também a
       armadilha dela: o escudo do Corinthians e o da Juventus são pretos e
       brancos, e o pouco de cor que sobra neles é ANTISSERRILHADO — pixel de
       borda, cinza puxando pro azul ou pro vermelho. Sem esta trava a Juventus
       saía azul-aço e o Corinthians vermelho.

       Doze por cento é onde a conta separa os dois casos: o Real Madrid, que é
       branco com ouro de verdade, passa folgado; a Juventus não chega perto. */
    $nCor = array_sum(array_column($baldes, 0));
    $nNeutro = array_sum(array_column($neutros, 0));
    if ($nCor + $nNeutro === 0) return null;

    if (!$lista || $nCor / ($nCor + $nNeutro) < 0.12) {
        $claro = $neutros['branco'] ?? null;
        $escuro = $neutros['preto'] ?? null;
        return [
            $claro  ? coresAjustar($media($claro), 0.74, 0.88)  : '#c9ccd4',
            $escuro ? coresAjustar($media($escuro), 0.14, 0.26) : '#3a3d46',
        ];
    }

    $principal = coresAjustar($lista[0]);
    $segunda = isset($lista[1]) ? coresAjustar($lista[1], 0.28, 0.5) : null;

    /* Duas cores quase iguais não são duas cores: o escudo do clube é
       monocromático, e a segunda passa a ser a versão escura da primeira. */
    if ($segunda === null || abs(hexdec(substr($principal, 1)) - hexdec(substr($segunda, 1))) < 0x101010) {
        [$hh, $ss, $ll] = coresParaHsl($lista[0]);
        $segunda = coresParaHex(coresDeHsl($hh, min(0.9, $ss + 0.05), 0.22));
    }

    return [$principal, $segunda];
}

// ═════════════════════════════════════════════════════════════════════
$clubes = futClubesDoJogo();
if ($so !== '') $clubes = array_filter($clubes, fn($c) => $c['nome'] === $so);

$cores = [];
$semCor = [];
$n = 0;
foreach ($clubes as $nome => $c) {
    $n++;
    $bin = coresBaixar((string)($c['escudo'] ?? ''));
    $par = $bin ? coresDoEscudo($bin) : null;
    if (!$par) { $semCor[] = $nome; continue; }
    $cores[$nome] = $par;
    if ($so !== '' || $n % 25 === 0) fwrite(STDERR, "  $n/" . count($clubes) . "\n");
}

ksort($cores);
$linhas = [];
foreach ($cores as $nome => [$a, $b]) {
    $linhas[] = sprintf("    %-28s => ['%s', '%s'],", var_export($nome, true), $a, $b);
}

$php = <<<'CAB'
<?php
/**
 * AS CORES DE CADA CLUBE — a primeira e a segunda, em hex.
 *
 * GERADO por games/core/fut_importar_cores_cli.php, que as tira do ESCUDO de
 * cada clube. Não edite à mão: a próxima extração apaga a mudança. Se a cor de
 * um clube saiu errada, o caminho é trocar o escudo dele — a cor vem de lá.
 *
 * A tela usa a primeira como sotaque (o clube que você dirige pinta a página)
 * e a segunda como fundo do cabeçalho. As duas já vêm com o claro ajustado pra
 * render no fundo escuro do jogo: quem tem escudo branco não devolve branco
 * puro, e quem tem azul-marinho não devolve algo que some.
 *
 * Clube sem escudo não está aqui, e a tela cai no verde padrão.
 */

const FUT_CORES_CLUBE = [

CAB;
$php .= implode("\n", $linhas) . "\n];\n";

if ($gravar) file_put_contents(__DIR__ . '/fut_cores.php', $php);

echo "\n" . count($cores) . ' clubes com cor' . ($gravar ? ' GRAVADOS' : ' (use --gravar)') . "\n";
if ($semCor) echo 'sem cor (' . count($semCor) . '): ' . implode(', ', array_slice($semCor, 0, 60)) . "\n";
if ($so !== '') foreach ($cores as $nome => $p) echo "$nome: {$p[0]} / {$p[1]}\n";
