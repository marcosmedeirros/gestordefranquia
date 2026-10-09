<?php
/**
 * ── FBA HOOPS: DE ONDE VÊM OS JOGADORES ──────────────────────────────
 *
 * Duas fontes, ambas abertas e sem chave (testadas em 08/10/2026):
 *
 *   ESPN       — NBA e WNBA. O elenco ATUAL vem do roster de cada time
 *                (altura, peso, posição, foto) e a estatística da ÚLTIMA
 *                TEMPORADA COMPLETA vem do "byathlete", que entrega a liga
 *                inteira paginada. As duas se casam pelo id da ESPN.
 *
 *   EuroLeague — EuroLeague (E) e EuroCup (U), pelo feed oficial que o site
 *                deles usa. "people" dá o elenco da temporada com altura em
 *                cm, país, posição e foto; "statistics" dá a média por jogo.
 *
 * O jogo NUNCA chama nada disto: quem chama é o importador de linha de
 * comando (@see hoops_importar_cli.php), que grava o resultado em
 * games/data/. A ESPN não é API oficial — se mudar de formato um dia, o que
 * para é a atualização das cartas, não o jogo.
 *
 * ── POR QUE A NBL FICOU DE FORA ──────────────────────────────────────
 *
 * A ESPN tem o elenco da NBL, mas sem altura, sem foto e sem estatística
 * agregada (o byathlete e o stats por atleta devolvem 404). Só daria pra
 * montar somando box score jogo a jogo, e carta sem foto e com altura
 * inventada é carta pior que nenhuma. Fica pra uma segunda leva.
 *
 * ── O FORMATO QUE SAI DAQUI ──────────────────────────────────────────
 *
 * Toda fonte devolve a mesma linha, pra quem calcula nota não precisar
 * saber de onde veio:
 *
 *   id, nome, liga, time, time_sigla, time_logo, pais, idade, altura (cm),
 *   peso (kg), pos_fonte (o que a fonte diz: PG, G, Guard, G-F...), foto,
 *   e `st` — médias por jogo: gp, min, pts, fgm, fga, tpm, tpa, ftm, fta,
 *   reb, ast, tov, stl, blk.
 *
 * Jogador sem estatística (calouro, quem voltou de lesão longa) NÃO entra:
 * a nota sai do que ele jogou, e sem jogo não há de onde tirar.
 */

/** A temporada de ESTATÍSTICA de cada liga — a última inteira. */
const HOOPS_TEMPORADA_STATS = [
    'NBA'        => 2026,      // 2025-26
    'WNBA'       => 2026,      // 2026 (a temporada é no meio do ano)
    'EuroLeague' => 'E2025',   // 2025-26
    'EuroCup'    => 'U2025',
];

/** A temporada do ELENCO da Europa — a que está começando agora. */
const HOOPS_TEMPORADA_ELENCO_EU = ['EuroLeague' => 'E2026', 'EuroCup' => 'U2026'];

const HOOPS_ESPN_SITE   = 'https://site.api.espn.com/apis/site/v2/sports/basketball/';
const HOOPS_ESPN_COMMON = 'https://site.web.api.espn.com/apis/common/v3/sports/basketball/';
const HOOPS_EL_FEED     = 'https://feeds.incrowdsports.com/provider/euroleague-feeds/';

/** GET de JSON com três tentativas. Devolve null se não deu. */
function hoopsBuscarJson(string $url): ?array
{
    for ($t = 1; $t <= 3; $t++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_ENCODING       => '',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (FBA Hoops importador)',
        ]);
        $corpo  = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($corpo !== false && $codigo === 200) {
            $json = json_decode($corpo, true);
            if (is_array($json)) return $json;
        }
        usleep(700000 * $t);
    }
    return null;
}

// ═══════════════════════════════ ESPN ═══════════════════════════════

/**
 * ── DUAS TEMPORADAS, E NÃO UMA ───────────────────────────────────────
 *
 * Com uma temporada só, a carta castigava quem teve um ano ruim ou se
 * machucou: a Sabrina Ionescu de 2026 (14,6 pontos em 31 jogos) saía 74, e
 * a Collier, com 16 jogos, 78 — "surreal", nas palavras do Victor. A carta
 * agora é a média das duas últimas, com a mais recente valendo mais
 * (HOOPS_PESO_ANTERIOR por jogo da anterior, contra 1 da atual).
 *
 * É média POR JOGO ponderada pelos jogos, e o `gp` que sai é o somado (com
 * o mesmo peso) — é ele que decide o quanto a amostra "encolhe" pra média
 * da liga lá em hoops_notas.php.
 */
const HOOPS_PESO_ANTERIOR = 0.6;

function hoopsJuntarTemporadas(array $atual, array $anterior): array
{
    $saida = $atual;
    foreach ($atual as $id => $a) {
        $b = $anterior[$id] ?? null;
        if (!$b || $b['gp'] <= 0 || $b['min'] <= 0) continue;
        $wa = $a['gp'];
        $wb = $b['gp'] * HOOPS_PESO_ANTERIOR;
        foreach ($a as $k => $v) {
            $saida[$id][$k] = $k === 'gp' ? $wa + $wb : ($v * $wa + $b[$k] * $wb) / max(1e-9, $wa + $wb);
        }
    }
    return $saida;
}

/** As médias por jogo de uma temporada inteira da liga, por id da ESPN. */
function hoopsEspnStats(string $slug, int $temporada): array
{
    $stats = [];
    for ($pagina = 1; $pagina <= 20; $pagina++) {
        $url = HOOPS_ESPN_COMMON . "{$slug}/statistics/byathlete?region=us&lang=en&contentorigin=espn"
             . "&isqualified=false&limit=100&page={$pagina}&season={$temporada}&seasontype=2";
        $d = hoopsBuscarJson($url);
        if (!$d || empty($d['athletes'])) break;

        // A posição de cada número muda de liga pra liga — o cabeçalho
        // `categories` é quem diz qual é qual, então o índice sai dele.
        $indice = [];
        foreach ($d['categories'] as $c) {
            foreach ($c['names'] as $i => $n) $indice[$c['name']][$n] = $i;
        }
        foreach ($d['athletes'] as $a) {
            $v = [];
            foreach ($a['categories'] as $c) $v[$c['name']] = $c['values'];
            $pega = fn(string $cat, string $nome) => (float)($v[$cat][$indice[$cat][$nome] ?? -1] ?? 0);
            $stats[(string)$a['athlete']['id']] = [
                'gp'  => $pega('general', 'gamesPlayed'),
                'min' => $pega('general', 'avgMinutes'),
                'pts' => $pega('offensive', 'avgPoints'),
                'fgm' => $pega('offensive', 'avgFieldGoalsMade'),
                'fga' => $pega('offensive', 'avgFieldGoalsAttempted'),
                'tpm' => $pega('offensive', 'avgThreePointFieldGoalsMade'),
                'tpa' => $pega('offensive', 'avgThreePointFieldGoalsAttempted'),
                'ftm' => $pega('offensive', 'avgFreeThrowsMade'),
                'fta' => $pega('offensive', 'avgFreeThrowsAttempted'),
                'reb' => $pega('general', 'avgRebounds'),
                'ast' => $pega('offensive', 'avgAssists'),
                'tov' => $pega('offensive', 'avgTurnovers'),
                'stl' => $pega('defensive', 'avgSteals'),
                'blk' => $pega('defensive', 'avgBlocks'),
            ];
        }
        if ($pagina >= (int)($d['pagination']['pages'] ?? 1)) break;
    }
    return $stats;
}

/**
 * NBA ou WNBA inteira: elenco atual casado com a estatística das duas
 * últimas temporadas.
 *
 * @param string $liga 'NBA' ou 'WNBA'
 * @return array{jogadores: array, times: array, sem_stats: int}
 */
function hoopsEspnLiga(string $liga): array
{
    $slug = strtolower($liga);

    // 1) A estatística: a última temporada e a anterior, juntas.
    $temporada = HOOPS_TEMPORADA_STATS[$liga];
    $stats = hoopsJuntarTemporadas(hoopsEspnStats($slug, $temporada), hoopsEspnStats($slug, $temporada - 1));

    // 2) Os times e o elenco atual de cada um.
    $times = [];
    $jogadores = [];
    $semStats = 0;
    $lista = hoopsBuscarJson(HOOPS_ESPN_SITE . "{$slug}/teams");
    foreach ($lista['sports'][0]['leagues'][0]['teams'] ?? [] as $t) {
        $t = $t['team'];
        $sigla = $t['abbreviation'];
        $times[$sigla] = [
            'sigla' => $sigla,
            'nome'  => $t['displayName'],
            'curto' => $t['shortDisplayName'] ?? $t['name'],
            'cor'   => '#' . ($t['color'] ?? '444444'),
            'cor2'  => '#' . ($t['alternateColor'] ?? 'aaaaaa'),
            'logo'  => $t['logos'][0]['href'] ?? '',
            'liga'  => $liga,
        ];

        $roster = hoopsBuscarJson(HOOPS_ESPN_SITE . "{$slug}/teams/{$t['id']}/roster");
        foreach ($roster['athletes'] ?? [] as $a) {
            $id = (string)$a['id'];
            if (!isset($stats[$id])) { $semStats++; continue; }
            $jogadores[] = [
                'id'        => $slug . '-' . $id,
                'nome'      => $a['displayName'],
                'liga'      => $liga,
                'time'      => $t['displayName'],
                'time_sigla'=> $sigla,
                'time_logo' => $times[$sigla]['logo'],
                'pais'      => $a['birthPlace']['country'] ?? ($a['citizenship'] ?? ''),
                'idade'     => (int)($a['age'] ?? 0),
                'altura'    => (int)round(((float)($a['height'] ?? 0)) * 2.54),
                'peso'      => (int)round(((float)($a['weight'] ?? 0)) * 0.4536),
                'pos_fonte' => $a['position']['abbreviation'] ?? '',
                'foto'      => $a['headshot']['href'] ?? '',
                'st'        => $stats[$id],
            ];
        }
        usleep(150000);
    }

    return ['jogadores' => $jogadores, 'times' => $times, 'sem_stats' => $semStats];
}

// ═══════════════════════════ EUROLEAGUE ═════════════════════════════

/** "VILLAR, RAFA" → "Rafa Villar". */
function hoopsNomeEuro(string $bruto): string
{
    $partes = array_map('trim', explode(',', $bruto, 2));
    $nome = count($partes) === 2 ? $partes[1] . ' ' . $partes[0] : $partes[0];
    return mb_convert_case(mb_strtolower($nome, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

/**
 * EuroLeague ou EuroCup: o elenco da temporada que começa casado com a
 * estatística da temporada passada.
 *
 * Quem está no elenco novo mas não jogou a competição no ano passado (veio
 * de outra liga) tenta a estatística da temporada nova — mesmo com três ou
 * quatro jogos, porque a nota encolhe amostra pequena em direção à média
 * (@see hoopsNotasCalcular) e não deixa quatro jogos bons virarem estrela.
 *
 * @param string $liga 'EuroLeague' ou 'EuroCup'
 */
function hoopsEuroLiga(string $liga): array
{
    $comp   = $liga === 'EuroLeague' ? 'E' : 'U';
    $sAntes = HOOPS_TEMPORADA_STATS[$liga];
    $sAgora = HOOPS_TEMPORADA_ELENCO_EU[$liga];

    $lerStats = function (string $temporada) use ($comp): array {
        $url = HOOPS_EL_FEED . "v3/competitions/{$comp}/statistics/players/traditional"
             . "?seasonMode=Single&limit=1000&seasonCode={$temporada}&statisticMode=PerGame&phaseTypeCode=RS";
        $saida = [];
        foreach (hoopsBuscarJson($url)['players'] ?? [] as $p) {
            $saida[(string)$p['player']['code']] = [
                'gp'  => (float)$p['gamesPlayed'],
                'min' => (float)$p['minutesPlayed'],
                'pts' => (float)$p['pointsScored'],
                'fgm' => (float)$p['twoPointersMade'] + (float)$p['threePointersMade'],
                'fga' => (float)$p['twoPointersAttempted'] + (float)$p['threePointersAttempted'],
                'tpm' => (float)$p['threePointersMade'],
                'tpa' => (float)$p['threePointersAttempted'],
                'ftm' => (float)$p['freeThrowsMade'],
                'fta' => (float)$p['freeThrowsAttempted'],
                'reb' => (float)$p['totalRebounds'],
                'ast' => (float)$p['assists'],
                'tov' => (float)$p['turnovers'],
                'stl' => (float)$p['steals'],
                'blk' => (float)$p['blocks'],
            ];
        }
        return $saida;
    };
    $statsAntes = $lerStats($sAntes);
    $statsAgora = $lerStats($sAgora);

    $times = [];
    $jogadores = [];
    $semStats = 0;
    $pessoas = hoopsBuscarJson(HOOPS_EL_FEED . "v2/competitions/{$comp}/seasons/{$sAgora}/people?personType=J&limit=1000");
    foreach ($pessoas['data'] ?? [] as $p) {
        if (($p['type'] ?? '') !== 'J' || empty($p['active'])) continue;
        $codigo = (string)$p['person']['code'];
        $st = $statsAntes[$codigo] ?? $statsAgora[$codigo] ?? null;
        if (!$st || $st['min'] <= 0) { $semStats++; continue; }

        $clube = $p['club'];
        $sigla = $clube['code'];
        $times[$sigla] ??= [
            'sigla' => $sigla,
            'nome'  => $clube['name'],
            'curto' => $clube['editorialName'] ?? $clube['abbreviatedName'] ?? $clube['name'],
            'cor'   => '#444444',
            'cor2'  => '#aaaaaa',
            'logo'  => $clube['images']['crest'] ?? '',
            'liga'  => $liga,
        ];
        $nasc = $p['person']['birthDate'] ?? null;
        $jogadores[] = [
            'id'        => strtolower($comp) . 'l-' . $codigo,
            'nome'      => hoopsNomeEuro($p['person']['name']),
            'liga'      => $liga,
            'time'      => $clube['name'],
            'time_sigla'=> $sigla,
            'time_logo' => $times[$sigla]['logo'],
            'pais'      => $p['person']['country']['name'] ?? '',
            'idade'     => $nasc ? (int)date_diff(date_create($nasc), date_create('today'))->y : 0,
            'altura'    => (int)($p['person']['height'] ?? 0),
            'peso'      => (int)($p['person']['weight'] ?? 0),
            'pos_fonte' => (string)($p['positionName'] ?? ''),
            'foto'      => $p['images']['headshot'] ?? ($p['images']['action'] ?? ''),
            'st'        => $st,
        ];
    }

    return ['jogadores' => $jogadores, 'times' => $times, 'sem_stats' => $semStats];
}

// ═══════════════════════════════ NBB ════════════════════════════════

/**
 * ── O NBB, PELO SITE DA LNB ──────────────────────────────────────────
 *
 * Nenhuma API aberta tem o NBB (ESPN, TheSportsDB e afins voltaram vazios).
 * Quem tem é a própria liga: lnb.com.br/nbb/estatisticas/ entrega a
 * temporada inteira em tabela — médias por jogo de 312 jogadores — e
 * lnb.com.br/atletas/<slug>/ tem a ficha (posição, altura, nascimento,
 * naturalidade) e a foto recortada.
 *
 * Duas tabelas bastam: "arremessos" (convertidos e tentados de 3, de 2 e
 * lance livre) e "eficiência" (rebote, assistência, roubo, toco e erro).
 *
 * TODOS os 312 entram — mas só pra régua das notas, marcados `so_regua`.
 * Carta, só os da lista em games/data/hoops_nbb.php.
 *
 * O "season[]" é um número interno da LNB, lido do filtro da página:
 * 97 = 2025/26, 88 = 2024/25, 106 = 2026/27 (a que está começando).
 */
const HOOPS_NBB_SEASON = 97;
const HOOPS_LNB = 'https://lnb.com.br/';

function hoopsBuscarHtml(string $url): ?string
{
    for ($t = 1; $t <= 3; $t++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_ENCODING => '', CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (FBA Hoops importador)',
        ]);
        $corpo = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($corpo !== false && $codigo === 200) return $corpo;
        usleep(700000 * $t);
    }
    return null;
}

/**
 * Uma tabela de estatística do NBB: slug do atleta → [time, números...].
 * Os números vêm do data-sort-value de cada célula, na ordem da tabela.
 */
function hoopsNbbTabela(string $pagina): array
{
    $url = HOOPS_LNB . "nbb/estatisticas/{$pagina}/?aggr=avg&type=athletes&suffered_rule=0"
         . '&season%5B%5D=' . HOOPS_NBB_SEASON . '&phase%5B%5D=1';
    $html = hoopsBuscarHtml($url) ?? '';
    $saida = [];
    preg_match_all('#<tr[^>]*>(.*?)</tr>#s', $html, $linhas);
    foreach ($linhas[1] as $l) {
        if (!preg_match('#lnb\.com\.br/atletas/([^/"]+)/?"[^>]*>([^<]+)</a>#', $l, $a)) continue;
        preg_match('#lnb\.com\.br/equipes/([^/"]+)/?"[^>]*>([^<]+)</a>#', $l, $t);
        preg_match_all('#data-sort-value="([^"]*)"#', $l, $n);
        $saida[$a[1]] = [
            'apelido'    => trim(preg_replace('/#\d+/', '', html_entity_decode($a[2], ENT_QUOTES, 'UTF-8'))),
            'time_slug'  => $t[1] ?? '',
            'time'       => trim(html_entity_decode($t[2] ?? '', ENT_QUOTES, 'UTF-8')),
            'n'          => array_map('floatval', $n[1]),
        ];
    }
    return $saida;
}

/** A foto vem com o nome do arquivo em UTF-8 cru ("Cabeção..."): codifica os bytes. */
function hoopsUrlSegura(string $url): string
{
    return preg_replace_callback('/[^\x21-\x7E]/', fn($m) => rawurlencode($m[0]), html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
}

/** A ficha de um atleta: posição, altura, peso, nascimento, país e foto. */
function hoopsNbbFicha(string $slug): array
{
    $html = hoopsBuscarHtml(HOOPS_LNB . "atletas/{$slug}/") ?? '';
    $campo = function (string $rotulo) use ($html): string {
        return preg_match('#<td>\s*' . preg_quote($rotulo, '#') . '\s*</td>\s*<td>\s*(.*?)\s*</td>#su', $html, $m)
            ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) : '';
    };
    $altura = 0; $peso = 0;
    if (preg_match('#([\d.,]+)\s*/\s*(\d+)#', $campo('Altura / Peso'), $m)) {
        $altura = (int)round((float)str_replace(',', '.', $m[1]) * 100);
        $peso = (int)$m[2];
    }
    $idade = 0;
    if (preg_match('#(\d{2})/(\d{2})/(\d{4})#', $campo('Data de Nascimento'), $m)) {
        $idade = date_diff(date_create("{$m[3]}-{$m[2]}-{$m[1]}"), date_create('today'))->y;
    }
    $nat = $campo('Naturalidade');
    // "Franca (SP)" é brasileiro; "Indiana (EUA)" é americano.
    $pais = preg_match('#\(([^)]+)\)#', $nat, $m) ? trim($m[1]) : 'Brasil';
    $ufs = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
    if (in_array(strtoupper($pais), $ufs, true)) $pais = 'Brasil';
    if (in_array(strtoupper($pais), ['USA', 'EUA'], true)) $pais = 'EUA';
    $foto = preg_match('#photo_athlete_blue[^>]*>\s*<img src="([^"]+)"#', $html, $m) ? hoopsUrlSegura($m[1]) : '';

    // A LNB escreve a posição por extenso, às vezes dupla ("Ala/Armador").
    $pos = mb_strtolower($campo('Posição'), 'UTF-8');
    $temArm = str_contains($pos, 'armador');
    $temAla = str_contains($pos, 'ala');
    $temPiv = str_contains($pos, 'piv');
    $posFonte = $temPiv && $temAla ? 'F-C' : ($temPiv ? 'C' : ($temArm && $temAla ? 'G-F' : ($temArm ? 'G' : 'F')));

    return ['altura' => $altura, 'peso' => $peso, 'idade' => $idade, 'pais' => $pais, 'foto' => $foto,
            'pos_fonte' => $posFonte, 'nome_completo' => $campo('Nome')];
}

/**
 * ── AS FOTOS DO NBB MORAM AQUI ───────────────────────────────────────
 *
 * Linkar a foto direto da LNB falhou no teste: o site fica atrás do
 * Cloudflare, que recusa uma rajada de pedidos vindos do mesmo lugar — e a
 * cena 3D do título pede as doze fotos de uma vez. Uma ou outra carta subia
 * ao palco sem rosto, a cada vez uma diferente.
 *
 * São só vinte fotos, então a importação baixa cada uma, reduz pra 300px e
 * guarda em games/img/hoops/nbb/. A carta passa a usar o arquivo daqui:
 * não depende da LNB estar no ar e, sendo do mesmo domínio, o canvas da
 * cena 3D pode desenhá-la. Se o download falhar, fica o link de lá.
 */
function hoopsNbbGuardarFoto(string $slug, string $url): string
{
    if ($url === '') return '';
    $pasta = __DIR__ . '/../img/hoops/nbb';
    $arquivo = "{$pasta}/{$slug}.png";
    $publico = "/games/img/hoops/nbb/{$slug}.png";
    if (is_file($arquivo) && filesize($arquivo) > 0) return $publico;

    $bytes = hoopsBuscarHtml($url);
    $img = $bytes !== null && function_exists('imagecreatefromstring') ? @imagecreatefromstring($bytes) : false;
    if (!$img) return $url;
    if (!is_dir($pasta)) mkdir($pasta, 0775, true);

    $lado = 300;
    $alt = (int)round(imagesy($img) * $lado / max(1, imagesx($img)));
    $nova = imagecreatetruecolor($lado, $alt);
    imagealphablending($nova, false);
    imagesavealpha($nova, true);
    imagefill($nova, 0, 0, imagecolorallocatealpha($nova, 0, 0, 0, 127));
    imagecopyresampled($nova, $img, 0, 0, 0, 0, $lado, $alt, imagesx($img), imagesy($img));
    $ok = imagepng($nova, $arquivo, 9);
    return $ok ? $publico : $url;
}

/** Os escudos, lidos do menu de equipes do próprio site. */
function hoopsNbbEscudos(): array
{
    $html = hoopsBuscarHtml(HOOPS_LNB . 'nbb/') ?? '';
    preg_match_all('#href="https://lnb\.com\.br/equipes/([^/"]+)/?"[^>]*>\s*<img[^>]+src="([^"]+)"#', $html, $m, PREG_SET_ORDER);
    $escudos = [];
    foreach ($m as [, $slug, $src]) $escudos[$slug] ??= hoopsUrlSegura($src);
    return $escudos;
}

/**
 * O NBB inteiro: os 312 da temporada como régua, e a ficha completa só de
 * quem está em games/data/hoops_nbb.php.
 */
function hoopsNbbLiga(): array
{
    $arr = hoopsNbbTabela('arremessos'); // JO Min PTS 3PC 3PT 3P% 2PC 2PT 2P% LLC LLT LL% EN
    $efi = hoopsNbbTabela('eficiencia'); // JO Min EF PTS RT AS BR TO ER
    $lista = require __DIR__ . '/../data/hoops_nbb.php';
    $escolhidos = array_column($lista, null, 'slug');
    $escudos = hoopsNbbEscudos();

    $times = [];
    $jogadores = [];
    $semFicha = 0;
    foreach ($arr as $slug => $a) {
        if (!isset($efi[$slug]) || count($a['n']) < 11 || count($efi[$slug]['n']) < 9) continue;
        [$jo, $min, $pts, $tpm, $tpa, , $p2m, $p2a, , $ftm, $fta] = $a['n'];
        [, , , , $reb, $ast, $stl, $blk, $tov] = $efi[$slug]['n'];
        if ($jo <= 0 || $min <= 0) continue;

        $sigla = $a['time_slug'];
        $times[$sigla] ??= ['sigla' => $sigla, 'nome' => $a['time'], 'curto' => $a['time'],
                            'cor' => '#444444', 'cor2' => '#aaaaaa', 'logo' => $escudos[$sigla] ?? '', 'liga' => 'NBB'];

        $j = [
            'id' => 'nbb-' . $slug, 'nome' => $a['apelido'], 'liga' => 'NBB',
            'time' => $a['time'], 'time_sigla' => $sigla, 'time_logo' => $times[$sigla]['logo'],
            'pais' => '', 'idade' => 0, 'altura' => 0, 'peso' => 0, 'pos_fonte' => 'F', 'foto' => '',
            'st' => ['gp' => $jo, 'min' => $min, 'pts' => $pts, 'fgm' => $tpm + $p2m, 'fga' => $tpa + $p2a,
                     'tpm' => $tpm, 'tpa' => $tpa, 'ftm' => $ftm, 'fta' => $fta,
                     'reb' => $reb, 'ast' => $ast, 'tov' => $tov, 'stl' => $stl, 'blk' => $blk],
        ];
        if (isset($escolhidos[$slug])) {
            $f = hoopsNbbFicha($slug);
            if ($f['foto'] === '' && $f['altura'] === 0) $semFicha++;
            $f['foto'] = hoopsNbbGuardarFoto($slug, $f['foto']);
            $j = array_merge($j, array_intersect_key($f, array_flip(['altura', 'peso', 'idade', 'pais', 'foto', 'pos_fonte'])));
            $j['nome'] = $escolhidos[$slug]['nome'];
            usleep(200000);
        } else {
            $j['so_regua'] = true; // entra na conta das notas, não vira carta
        }
        $jogadores[] = $j;
    }
    $faltando = array_diff(array_keys($escolhidos), array_keys($arr));
    foreach ($faltando as $s) fwrite(STDERR, "AVISO NBB: \"{$s}\" não está nas estatísticas da temporada.\n");

    return ['jogadores' => $jogadores, 'times' => $times, 'sem_stats' => $semFicha];
}
