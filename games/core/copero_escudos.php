<?php
/**
 * Preenche o campo `escudo` do catálogo, buscando na thesportsdb.
 *
 * Roda à mão (php games/core/copero_escudos.php) e reescreve
 * copero_clubes.php com as URLs. Não roda sozinho e não roda em requisição:
 * são dezenas de chamadas externas, e isso não é trabalho de página.
 *
 * Busca por LIGA, não por clube: uma chamada traz o elenco inteiro da liga
 * com escudo, enquanto clube a clube seriam 206 idas à rede — e a busca por
 * nome erra mais, porque "Nacional" existe em cinco países.
 *
 * O que não achar fica vazio, e o jogo mostra monograma. Rodar de novo só
 * tenta os que faltam.
 */

require_once __DIR__ . '/copero_clubes.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Só linha de comando.\n");
}

/** O nome da liga na thesportsdb, que nem sempre é o nome que usamos. */
const COPERO_LIGA_API = [
    'BR1' => 'Brazilian Serie A',        'BR2' => 'Brazilian Serie B',
    'BR3' => 'Brazilian Serie C',        'AR1' => 'Argentinian Primera Division',
    'AR2' => 'Argentinian Primera B Nacional',
    'UY1' => 'Uruguayan Primera Division', 'CL1' => 'Chilean Primera Division',
    'CO1' => 'Colombian Primera A',
    'EN1' => 'English Premier League',   'EN2' => 'English League Championship',
    'EN3' => 'English League 1',         'ES1' => 'Spanish La Liga',
    'ES2' => 'Spanish La Liga 2',        'IT1' => 'Italian Serie A',
    'IT2' => 'Italian Serie B',          'DE1' => 'German Bundesliga',
    'DE2' => 'German 2 Bundesliga',      'FR1' => 'French Ligue 1',
    'FR2' => 'French Ligue 2',           'PT1' => 'Portuguese Primeira Liga',
    'NL1' => 'Dutch Eredivisie',         'BE1' => 'Belgian First Division A',
    'TR1' => 'Turkish Super Lig',        'RU1' => 'Russian Football Premier League',
    'SC1' => 'Scottish Premier League',  'GR1' => 'Greek Superleague Greece',
    'US1' => 'American Major League Soccer', 'MX1' => 'Mexican Primera League',
    'SA1' => 'Saudi Pro League',         'JP1' => 'Japanese J League',
    'KR1' => 'South Korean K League 1',  'EG1' => 'Egyptian Premier League',
    'MA1' => 'Moroccan Botola Pro',      'ZA1' => 'South African Premier Division',
    'AU1' => 'Australian A-League',
];

function coperoBuscar(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'copero/1.0',
    ]);
    // A chave gratuita barra com 429 quando a gente acelera. Espera e
    // tenta de novo em vez de desistir calada — foi o 429 que fez a
    // primeira rodada achar 30 de 206 e parecer erro de casamento de nome.
    for ($tentativa = 1; $tentativa <= 4; $tentativa++) {
        $r = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch) === 0 && $http === 200) {
            curl_close($ch);
            return json_decode((string)$r, true) ?: null;
        }
        if ($http !== 429) break;
        sleep(6 * $tentativa);
    }
    curl_close($ch);
    return null;
}

/**
 * Casa o nome do nosso catálogo com o da API.
 *
 * Nome não bate letra por letra ("Atlético-MG" vs "Atletico Mineiro"), então
 * a comparação é sem acento, sem pontuação e sem as palavras que todo clube
 * tem — sem isso "Athletic Club" casaria com meio mundo.
 */
/**
 * O nome que a API usa, quando é diferente do nosso.
 *
 * O catálogo guarda o nome em português, que é o que aparece na tela. A
 * busca precisa do nome que a thesportsdb usa. Traduzir aqui evita
 * estragar o catálogo pra agradar a API.
 */
const COPERO_NOME_API = [
    'Atlético-MG'       => 'Atletico Mineiro',
    'Athletico-PR'      => 'Athletico Paranaense',
    'San Lorenzo'       => 'San Lorenzo de Almagro',
    'Estudiantes'       => 'Estudiantes de La Plata',
    'Nacional'          => 'Club Nacional de Football',
    'Colo-Colo'         => 'Colo Colo',
    'Junior'            => 'Atletico Junior',
    'Inter de Milão'    => 'Inter Milan',
    'Bayern de Munique' => 'Bayern Munich',
    'Hertha Berlim'     => 'Hertha Berlin',
    'PSG'               => 'Paris Saint-Germain',
    'Saint-Étienne'     => 'St Etienne',
    'Zenit'             => 'Zenit St Petersburg',
    'CSKA Moscou'       => 'CSKA Moscow',
    'Spartak Moscou'    => 'Spartak Moscow',
    'AEK Atenas'        => 'AEK Athens',
    'Al Ittihad'        => 'Al-Ittihad',
    'Urawa Reds'        => 'Urawa Red Diamonds',
];

function coperoChave(string $nome): string
{
    $s = mb_strtolower(trim($nome));
    $s = strtr($s, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i',
                    'ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n']);
    $s = preg_replace('/\b(fc|cf|ac|sc|afc|cd|ca|club|clube|de|do|da|the)\b/', ' ', $s);
    $s = preg_replace('/[^a-z0-9]/', '', $s);
    return $s;
}

// A varredura por liga já rodou e encheu 187 dos 206. Sobrou só o que a
// API escreve com outro nome, e isso o passo clube a clube resolve.
$achados = [];

// A busca por liga vem cortada em 10 times pela chave gratuita, e algumas
// ligas nem respondem. Quem sobrou vai por NOME, um a um: é mais lento, mas
// é o que fecha o catálogo.
echo "\nCompletando clube a clube...\n";
$tentados = 0;
foreach (COPERO_CLUBES as [$nome, $liga, , $escudo]) {
    if ($escudo !== '' || isset($achados[coperoChave($nome)])) continue;
    $busca = COPERO_NOME_API[$nome] ?? $nome;
    $d = coperoBuscar('https://www.thesportsdb.com/api/v1/json/3/searchteams.php?t='
                      . rawurlencode($busca));
    $tentados++;
    sleep(2);   // ritmo que a chave gratuita aguenta
    foreach (($d['teams'] ?? []) as $t) {
        // Só futebol: "Vasco da Gama" e "Flamengo" existem em outros esportes,
        // e escudo de basquete no jogo de futebol é pior que buraco nenhum.
        if (($t['strSport'] ?? '') !== 'Soccer') continue;
        $badge = trim((string)($t['strBadge'] ?? ''));
        if ($badge === '') continue;

        // O nome devolvido tem que ser o que pedimos.
        $devolvido = coperoChave((string)($t['strTeam'] ?? ''));
        $serve = false;
        foreach ([coperoChave($busca), coperoChave($nome)] as $pedido) {
            if ($devolvido === $pedido
                || str_contains($devolvido, $pedido) || str_contains($pedido, $devolvido)) {
                $serve = true; break;
            }
        }
        if (!$serve) continue;

        $achados[coperoChave($nome)] = $badge;
        break;
    }
}
echo "  {$tentados} clube(s) buscados um a um\n";

echo "\nCasando com o catálogo...\n";
$novos = [];
$faltam = [];
foreach (COPERO_CLUBES as [$nome, $liga, $forca, $escudo]) {
    $url = $escudo ?: ($achados[coperoChave($nome)] ?? '');
    $novos[] = [$nome, $liga, $forca, $url];
    if ($url === '') $faltam[] = $nome;
}

// Reescreve só o bloco do array, preservando o resto do arquivo.
$f = __DIR__ . '/copero_clubes.php';
$src = file_get_contents($f);
$ini = strpos($src, 'const COPERO_CLUBES = [');
$fim = strpos($src, "\n];", $ini);
if ($ini === false || $fim === false) exit("Não achei o array no arquivo.\n");

$linhas = "const COPERO_CLUBES = [\n";
$ligaAtual = null;
foreach ($novos as [$nome, $liga, $forca, $url]) {
    if ($liga !== $ligaAtual) {
        $l = coperoLigaDoClube($liga);
        $linhas .= "\n    // " . $l['nome'] . "\n";
        $ligaAtual = $liga;
    }
    $linhas .= sprintf("    [%s, %s, %d, %s],\n",
        var_export($nome, true), var_export($liga, true), $forca, var_export($url, true));
}
$linhas .= "];";

file_put_contents($f, substr($src, 0, $ini) . $linhas . substr($src, $fim + 3));

printf("\nEscudos preenchidos: %d de %d\n", count($novos) - count($faltam), count($novos));
if ($faltam) {
    echo "Sem escudo (" . count($faltam) . "): " . implode(', ', array_slice($faltam, 0, 40))
       . (count($faltam) > 40 ? ', …' : '') . "\n";
}
