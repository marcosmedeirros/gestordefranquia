<?php
/**
 * Enriquecimento da hoopgrid_players com o dataset Basketball-Reference
 * (github.com/sumitrodatta/bball-reference-datasets, temporadas até 2025-26).
 *
 * Produz: premios completos (MVP, DPOY, ROY, SIXTHMAN, MIP, CLUTCH, ALLSTAR,
 * ALL_NBA, ALL_DEFENSE, ALL_ROOKIE, CHAMPION, FINALS_MVP, SCORING, HOF),
 * times (franquia atual), eras, titulos, médias de carreira, bio.
 *
 * Uso: php enriquecer.php <dir-csvs> <db> > relatorio.txt
 * Gera: atualizar-hoopgrid.sql no diretório dos CSVs.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
ini_set('memory_limit', '1024M');

$dir = rtrim($argv[1] ?? __DIR__, '/\\');
$db  = $argv[2] ?? 'fba_enrich';

$pdo = new PDO("mysql:host=localhost;dbname={$db};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// ── Normalização de nomes ────────────────────────────────
function norm(string $s): string {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [
            'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','ā'=>'a','ă'=>'a','ą'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','ē'=>'e','ė'=>'e','ę'=>'e','ě'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ī'=>'i','į'=>'i',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ō'=>'o','ő'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ū'=>'u','ů'=>'u','ű'=>'u','ų'=>'u',
            'ç'=>'c','ć'=>'c','č'=>'c','ĉ'=>'c',
            'ñ'=>'n','ń'=>'n','ň'=>'n','ņ'=>'n',
            'š'=>'s','ś'=>'s','ş'=>'s','ș'=>'s',
            'ž'=>'z','ź'=>'z','ż'=>'z',
            'ý'=>'y','ÿ'=>'y','ł'=>'l','ľ'=>'l','ļ'=>'l','ĺ'=>'l',
            ' đ'=>'d','ď'=>'d','đ'=>'d','ģ'=>'g','ğ'=>'g','ķ'=>'k','ř'=>'r','ŕ'=>'r',
            'ť'=>'t','ţ'=>'t','ț'=>'t','ẞ'=>'ss','ß'=>'ss','æ'=>'ae','œ'=>'oe','ð'=>'d','þ'=>'th',
        ];
    }
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, $mapa);
    $s = strtr($s, ['ı'=>'i']); // turco: Aşık -> asik
    // remove marcas combinantes (İ minúsculo vira i + ponto combinante)
    $s = preg_replace('/\p{Mn}/u', '', $s);
    $s = preg_replace('/[.\'\x{2019}"`-]/u', '', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    // sufixos geracionais no fim
    $s = preg_replace('/\s+(jr|sr|ii|iii|iv|v)$/', '', $s);
    return trim($s);
}

function transliterar(string $s): string {
    // pra nomes NOVOS inseridos na base (a base atual é ASCII)
    static $m = null;
    if ($m === null) {
        $m = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','ā'=>'a','ă'=>'a','ą'=>'a','Á'=>'A','Ā'=>'A',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','ē'=>'e','ė'=>'e','ę'=>'e','ě'=>'e','É'=>'E',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ī'=>'i','į'=>'i','Í'=>'I',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ō'=>'o','Ó'=>'O','Ö'=>'O',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ū'=>'u','ů'=>'u','Ú'=>'U','Ū'=>'U',
            'ç'=>'c','ć'=>'c','č'=>'c','Ç'=>'C','Ć'=>'C','Č'=>'C',
            'ñ'=>'n','ń'=>'n','ň'=>'n','ņ'=>'n','Ñ'=>'N',
            'š'=>'s','ś'=>'s','ş'=>'s','ș'=>'s','Š'=>'S','Ś'=>'S',
            'ž'=>'z','ź'=>'z','ż'=>'z','Ž'=>'Z','Ż'=>'Z',
            'ý'=>'y','ł'=>'l','Ł'=>'L','đ'=>'dj','Đ'=>'Dj','ģ'=>'g','Ģ'=>'G','ķ'=>'k','ř'=>'r','ť'=>'t','ț'=>'t','ß'=>'ss','ď'=>'d'];
    }
    return strtr($s, $m);
}

function lerCsv(string $arquivo): array {
    $fh = fopen($arquivo, 'r');
    if (!$fh) { fwrite(STDERR, "nao abriu $arquivo\n"); exit(1); }
    $header = fgetcsv($fh);
    // BOM
    if (isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    $linhas = [];
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) === 1 && $r[0] === null) continue;
        $row = [];
        foreach ($header as $i => $h) $row[$h] = $r[$i] ?? '';
        $linhas[] = $row;
    }
    fclose($fh);
    return $linhas;
}

// ── Franquias: código BBRef -> código atual usado no jogo ─
$FRANQUIA = [
    'ATL'=>'ATL','BOS'=>'BOS','CHI'=>'CHI','CLE'=>'CLE','DAL'=>'DAL','DEN'=>'DEN','DET'=>'DET',
    'GSW'=>'GSW','HOU'=>'HOU','IND'=>'IND','LAC'=>'LAC','LAL'=>'LAL','MEM'=>'MEM','MIA'=>'MIA',
    'MIL'=>'MIL','MIN'=>'MIN','NOP'=>'NOP','NYK'=>'NYK','OKC'=>'OKC','ORL'=>'ORL','PHI'=>'PHI',
    'POR'=>'POR','SAC'=>'SAC','SAS'=>'SAS','TOR'=>'TOR','UTA'=>'UTA','WAS'=>'WAS',
    'BRK'=>'BKN','BKN'=>'BKN','PHO'=>'PHX','PHX'=>'PHX','CHO'=>'CHA','CHA'=>'CHA','CHH'=>'CHA',
    'NJN'=>'BKN','NYN'=>'BKN','NOH'=>'NOP','NOK'=>'NOP','NOJ'=>'UTA','SEA'=>'OKC','VAN'=>'MEM',
    'WSB'=>'WAS','BAL'=>'WAS','CHZ'=>'WAS','CHP'=>'WAS','SDC'=>'LAC','BUF'=>'LAC',
    'KCK'=>'SAC','KCO'=>'SAC','CIN'=>'SAC','ROC'=>'SAC','SDR'=>'HOU','MNL'=>'LAL',
    'PHW'=>'GSW','SFW'=>'GSW','FTW'=>'DET','TRI'=>'ATL','MLH'=>'ATL','STL'=>'ATL','SYR'=>'PHI',
];

// ── Campeões NBA/BAA por temporada (ano de encerramento => código BBRef) ─
$CAMPEOES = [
    1947=>'PHW',1948=>'BLB',1949=>'MNL',1950=>'MNL',1951=>'ROC',1952=>'MNL',1953=>'MNL',1954=>'MNL',
    1955=>'SYR',1956=>'PHW',1957=>'BOS',1958=>'STL',1959=>'BOS',1960=>'BOS',1961=>'BOS',1962=>'BOS',
    1963=>'BOS',1964=>'BOS',1965=>'BOS',1966=>'BOS',1967=>'PHI',1968=>'BOS',1969=>'BOS',1970=>'NYK',
    1971=>'MIL',1972=>'LAL',1973=>'NYK',1974=>'BOS',1975=>'GSW',1976=>'BOS',1977=>'POR',1978=>'WSB',
    1979=>'SEA',1980=>'LAL',1981=>'BOS',1982=>'LAL',1983=>'PHI',1984=>'BOS',1985=>'LAL',1986=>'BOS',
    1987=>'LAL',1988=>'LAL',1989=>'DET',1990=>'DET',1991=>'CHI',1992=>'CHI',1993=>'CHI',1994=>'HOU',
    1995=>'HOU',1996=>'CHI',1997=>'CHI',1998=>'CHI',1999=>'SAS',2000=>'LAL',2001=>'LAL',2002=>'LAL',
    2003=>'SAS',2004=>'DET',2005=>'SAS',2006=>'MIA',2007=>'SAS',2008=>'BOS',2009=>'LAL',2010=>'LAL',
    2011=>'DAL',2012=>'MIA',2013=>'MIA',2014=>'SAS',2015=>'GSW',2016=>'CLE',2017=>'GSW',2018=>'GSW',
    2019=>'TOR',2020=>'LAL',2021=>'MIL',2022=>'GSW',2023=>'DEN',2024=>'BOS',2025=>'OKC',2026=>'NYK',
];

// ── Finals MVPs (ano => nome como no dataset) ────────────
$FINALS_MVP = [
    1969=>'Jerry West',1970=>'Willis Reed',1971=>'Kareem Abdul-Jabbar',1972=>'Wilt Chamberlain',
    1973=>'Willis Reed',1974=>'John Havlicek',1975=>'Rick Barry',1976=>'Jo Jo White',
    1977=>'Bill Walton',1978=>'Wes Unseld',1979=>'Dennis Johnson',1980=>'Magic Johnson',
    1981=>'Cedric Maxwell',1982=>'Magic Johnson',1983=>'Moses Malone',1984=>'Larry Bird',
    1985=>'Kareem Abdul-Jabbar',1986=>'Larry Bird',1987=>'Magic Johnson',1988=>'James Worthy',
    1989=>'Joe Dumars',1990=>'Isiah Thomas',1991=>'Michael Jordan',1992=>'Michael Jordan',
    1993=>'Michael Jordan',1994=>'Hakeem Olajuwon',1995=>'Hakeem Olajuwon',1996=>'Michael Jordan',
    1997=>'Michael Jordan',1998=>'Michael Jordan',1999=>'Tim Duncan',2000=>"Shaquille O'Neal",
    2001=>"Shaquille O'Neal",2002=>"Shaquille O'Neal",2003=>'Tim Duncan',2004=>'Chauncey Billups',
    2005=>'Tim Duncan',2006=>'Dwyane Wade',2007=>'Tony Parker',2008=>'Paul Pierce',
    2009=>'Kobe Bryant',2010=>'Kobe Bryant',2011=>'Dirk Nowitzki',2012=>'LeBron James',
    2013=>'LeBron James',2014=>'Kawhi Leonard',2015=>'Andre Iguodala',2016=>'LeBron James',
    2017=>'Kevin Durant',2018=>'Kevin Durant',2019=>'Kawhi Leonard',2020=>'LeBron James',
    2021=>'Giannis Antetokounmpo',2022=>'Stephen Curry',2023=>'Nikola Jokić',2024=>'Jaylen Brown',
    2025=>'Shai Gilgeous-Alexander',2026=>'Jalen Brunson',
];

// ── Carrega CSVs ─────────────────────────────────────────
echo "Lendo CSVs...\n";
$csvShares   = lerCsv("$dir/Player Award Shares.csv");
$csvEos      = lerCsv("$dir/End of Season Teams.csv");
$csvAllstar  = lerCsv("$dir/All-Star Selections.csv");
$csvSeasons  = lerCsv("$dir/Player Season Info.csv");
$csvCareer   = lerCsv("$dir/Player Career Info.csv");
$csvPergame  = lerCsv("$dir/Player Per Game.csv");
printf("shares=%d eos=%d allstar=%d seasons=%d career=%d pergame=%d\n",
    count($csvShares), count($csvEos), count($csvAllstar), count($csvSeasons), count($csvCareer), count($csvPergame));

$LIGAS_OK = ['NBA'=>1, 'BAA'=>1];

// ── Perfis por player_id do dataset ──────────────────────
$P = []; // pid => perfil
function &perfil(array &$P, string $pid, string $nome) {
    if (!isset($P[$pid])) {
        $P[$pid] = ['nome'=>$nome,'premios'=>[],'times'=>[],'anos'=>[],'titulos'=>0,
                    'g'=>0,'pts'=>0.0,'reb'=>0.0,'ast'=>0.0,
                    'pos'=>null,'ht'=>null,'wt'=>null,'nasc'=>null,'de'=>null,'ate'=>null,'hof'=>false];
    }
    return $P[$pid];
}

// carreira (base de todos os jogadores)
foreach ($csvCareer as $r) {
    $p = &perfil($P, $r['player_id'], $r['player']);
    $p['de']   = (int)$r['from']; $p['ate'] = (int)$r['to'];
    $p['hof']  = strtoupper($r['hof']) === 'TRUE';
    $p['pos']  = $r['pos'] ?: null;
    $p['ht']   = is_numeric($r['ht_in_in']) ? (int)$r['ht_in_in'] : null;
    $p['wt']   = is_numeric($r['wt']) ? (int)$r['wt'] : null;
    $p['nasc'] = $r['birth_date'] ?: null;
    unset($p);
}

// temporadas -> times + anos + índice campeão
$porTimeTemporada = []; // "season|team" => [pid,...]
foreach ($csvSeasons as $r) {
    if (!isset($LIGAS_OK[$r['lg']])) continue;
    $team = $r['team'];
    if (preg_match('/^(TOT|\dTM)$/', $team)) continue;
    $p = &perfil($P, $r['player_id'], $r['player']);
    $ano = (int)$r['season'];
    $p['anos'][$ano] = 1;
    if (isset($GLOBALS['FRANQUIA'][$team])) $p['times'][$GLOBALS['FRANQUIA'][$team]] = 1;
    $porTimeTemporada["$ano|$team"][] = $r['player_id'];
    unset($p);
}

// prêmios de votação
$MAPA_SHARE = ['nba mvp'=>'MVP','nba dpoy'=>'DPOY','nba roy'=>'ROY','baa roy'=>'ROY',
               'nba smoy'=>'SIXTHMAN','nba mip'=>'MIP','nba clutch_poy'=>'CLUTCH'];
foreach ($csvShares as $r) {
    if (strtoupper($r['winner']) !== 'TRUE') continue;
    $k = $MAPA_SHARE[strtolower($r['award'])] ?? null;
    if (!$k) continue;
    $p = &perfil($P, $r['player_id'], $r['player']);
    $p['premios'][$k] = 1; unset($p);
}

// All-NBA / All-Defense / All-Rookie
foreach ($csvEos as $r) {
    if (($r['lg'] ?? '') !== 'NBA') continue;
    $t = $r['type'];
    $k = null;
    if (stripos($t, 'Defens') !== false)      $k = 'ALL_DEFENSE';
    elseif (stripos($t, 'Rookie') !== false)  $k = 'ALL_ROOKIE';
    elseif (stripos($t, 'NBA') !== false)     $k = 'ALL_NBA';
    if (!$k) continue;
    $p = &perfil($P, $r['player_id'], $r['player']);
    $p['premios'][$k] = 1; unset($p);
}

// All-Star
foreach ($csvAllstar as $r) {
    if (($r['lg'] ?? 'NBA') !== 'NBA') continue;
    if (!isset($r['player_id']) || $r['player_id'] === '') continue;
    $p = &perfil($P, $r['player_id'], $r['player']);
    $p['premios']['ALLSTAR'] = 1; unset($p);
}

// campeões + títulos
foreach ($CAMPEOES as $ano => $code) {
    foreach ($porTimeTemporada["$ano|$code"] ?? [] as $pid) {
        $P[$pid]['premios']['CHAMPION'] = 1;
        $P[$pid]['titulos']++;
    }
    if (empty($porTimeTemporada["$ano|$code"])) echo "AVISO: campeao $ano $code sem elenco no dataset\n";
}

// médias de carreira + artilheiros (linha combinada por temporada)
$porJogadorTemporada = []; // pid => season => row (a de mais jogos)
foreach ($csvPergame as $r) {
    if (!isset($LIGAS_OK[$r['lg']])) continue;
    $pid = $r['player_id']; $ano = (int)$r['season'];
    $g = (int)$r['g'];
    if (!isset($porJogadorTemporada[$pid][$ano]) || $g > (int)$porJogadorTemporada[$pid][$ano]['g']) {
        $porJogadorTemporada[$pid][$ano] = $r;
    }
}
$maxJogosTemporada = [];
foreach ($porJogadorTemporada as $pid => $anos) {
    foreach ($anos as $ano => $r) {
        $g = (int)$r['g'];
        if ($g > ($maxJogosTemporada[$ano] ?? 0)) $maxJogosTemporada[$ano] = $g;
    }
}
$lideresPts = []; // ano => [pid, valor]
foreach ($porJogadorTemporada as $pid => $anos) {
    foreach ($anos as $ano => $r) {
        $g = (int)$r['g']; if (!$g) continue;
        $ppg = (float)$r['pts_per_game'];
        $p = &perfil($P, $pid, $r['player']);
        $p['g'] += $g; $p['pts'] += $ppg*$g;
        $p['reb'] += (float)($r['trb_per_game'] ?? 0)*$g;
        $p['ast'] += (float)($r['ast_per_game'] ?? 0)*$g;
        unset($p);
        // artilheiro: até 1969 por total de pontos; depois por média com corte de jogos
        $valor = $ano <= 1969 ? $ppg*$g : $ppg;
        $qualifica = $ano <= 1969 ? true : ($g >= 0.70 * ($maxJogosTemporada[$ano] ?? 82));
        if ($qualifica && (!isset($lideresPts[$ano]) || $valor > $lideresPts[$ano][1])) {
            $lideresPts[$ano] = [$pid, $valor];
        }
    }
}
foreach ($lideresPts as $ano => [$pid, $v]) $P[$pid]['premios']['SCORING'] = 1;

// Finals MVP e HOF
$porNomeNorm = [];
foreach ($P as $pid => $p) $porNomeNorm[norm($p['nome'])][] = $pid;
foreach ($FINALS_MVP as $ano => $nome) {
    $pids = $porNomeNorm[norm($nome)] ?? [];
    if (!$pids) { echo "AVISO: Finals MVP '$nome' nao achado\n"; continue; }
    // se ambíguo, escolhe quem jogou nesse ano
    $alvo = $pids[0];
    if (count($pids) > 1) foreach ($pids as $c) if (isset($P[$c]['anos'][$ano])) { $alvo = $c; break; }
    $P[$alvo]['premios']['FINALS_MVP'] = 1;
}
foreach ($P as $pid => $p) if ($p['hof']) $P[$pid]['premios']['HOF'] = 1;

echo "perfis no dataset: " . count($P) . "\n";

// ── Casa com a base atual ────────────────────────────────
$rows = $pdo->query("SELECT id, nome, premios, times, eras, titulos, pts_medio, reb_medio, ast_medio,
                            posicao, altura, peso, nascimento, draft_ano
                     FROM hoopgrid_players")->fetchAll();
$dbPorNorm = [];
foreach ($rows as $r) $dbPorNorm[norm($r['nome'])][] = $r;

$ORDEM_PREMIOS = ['MVP','FINALS_MVP','CHAMPION','DPOY','ROY','SIXTHMAN','MIP','CLUTCH','SCORING',
                  'ALLSTAR','ALL_NBA','ALL_DEFENSE','ALL_ROOKIE','HOF'];
function premiosOrdenados(array $set, array $ordem): array {
    $out = [];
    foreach ($ordem as $k) if (isset($set[$k])) $out[] = $k;
    foreach ($set as $k => $_) if (!in_array($k, $ordem, true)) $out[] = $k;
    return $out;
}
function posLegivel(?string $pos): ?string {
    if (!$pos) return null;
    $mapa = ['C'=>'Center','F'=>'Forward','G'=>'Guard'];
    $partes = array_map(fn($x) => $mapa[trim($x)] ?? null, explode('-', $pos));
    $partes = array_filter($partes);
    return $partes ? implode('-', array_slice($partes, 0, 2)) : null;
}
function alturaPes(?int $in): ?string {
    if (!$in) return null;
    return intdiv($in, 12) . '-' . ($in % 12);
}

$sql = ["-- Enriquecimento da hoopgrid_players (dataset Basketball-Reference ate 2025-26)",
        "-- Gerado automaticamente; seguro rodar uma vez (UPDATEs por id, INSERT IGNORE por nome).",
        "SET NAMES utf8mb4;"];
$casados = 0; $semMatch = []; $ambiguos = 0; $novos = 0;

// Índices de fallback: sem espaços; sobrenome+ano de nascimento; apelidos.
$porNomeColado = [];   // "billyraybates" => [pid]
$porSobrenomeNasc = [];// "bates|1956" => [pid]
$APELIDOS = ['dave'=>'david','mike'=>'michael','chris'=>'christopher','rob'=>'robert','bob'=>'robert',
    'jim'=>'james','jimmy'=>'james','joe'=>'joseph','tom'=>'thomas','tony'=>'anthony','steve'=>'stephen',
    'bill'=>'william','will'=>'william','billy'=>'william','rich'=>'richard','dick'=>'richard',
    'ken'=>'kenneth','kenny'=>'kenneth','dan'=>'daniel','danny'=>'daniel','greg'=>'gregory',
    'jeff'=>'jeffrey','matt'=>'matthew','nick'=>'nicholas','alex'=>'alexander','ed'=>'edward',
    'eddie'=>'edward','ted'=>'theodore','pat'=>'patrick','sam'=>'samuel','ron'=>'ronald',
    'don'=>'donald','tim'=>'timothy','herb'=>'herbert','vince'=>'vincent','vinnie'=>'vincent',
    'larry'=>'lawrence','gene'=>'eugene','fred'=>'frederick','andy'=>'andrew','drew'=>'andrew',
    'zach'=>'zachary','josh'=>'joshua','jake'=>'jacob','max'=>'maxwell','ray'=>'raymond'];
function normApelido(string $n, array $APELIDOS): string {
    $partes = explode(' ', $n);
    if (isset($APELIDOS[$partes[0]])) $partes[0] = $APELIDOS[$partes[0]];
    return implode(' ', $partes);
}
foreach ($P as $pid => $p) {
    $n = norm($p['nome']);
    $porNomeColado[str_replace(' ', '', $n)][] = $pid;
    $partes = explode(' ', $n);
    $sobrenome = end($partes);
    $anoN = $p['nasc'] ? (int)substr($p['nasc'], 0, 4) : 0;
    if ($sobrenome && $anoN) $porSobrenomeNasc["$sobrenome|$anoN"][] = $pid;
    $ap = normApelido($n, $APELIDOS);
    if ($ap !== $n) $porNomeNorm[$ap][] = $pid;
}

// Apelidos consagrados: nome na base -> nome no Basketball-Reference
$ALIAS = [
    'nate archibald'   => 'tiny archibald',
    'lafayette lever'  => 'fat lever',
    'flip murray'      => 'ronald murray',
    'pooh jeter'       => 'eugene jeter',
    'luca vildoza'     => 'luka vildoza',
    'egor demin'       => 'egor dyomin',
];

$usadosPid = [];
foreach ($rows as $r) {
    $chave = norm($r['nome']);
    if (isset($ALIAS[$chave])) $chave = $ALIAS[$chave];
    $pids = $porNomeNorm[$chave] ?? [];
    if (!$pids) $pids = $porNomeNorm[normApelido($chave, $APELIDOS)] ?? [];
    if (!$pids) {
        // nome colado (Billyray x Billy Ray, Jojo x Jo Jo)
        $c = $porNomeColado[str_replace(' ', '', $chave)] ?? [];
        if (count($c) === 1) $pids = $c;
    }
    if (!$pids && $r['nascimento']) {
        // sobrenome + ano de nascimento, exigindo a mesma inicial do primeiro nome
        $partes = explode(' ', $chave);
        $sobrenome = end($partes);
        $c = $porSobrenomeNasc[$sobrenome . '|' . (int)substr($r['nascimento'],0,4)] ?? [];
        $c = array_values(array_filter($c, fn($pid) => norm($GLOBALS['P'][$pid]['nome'])[0] === $chave[0]));
        if (count($c) === 1) $pids = $c;
    }
    if (!$pids) { $semMatch[] = $r['nome']; continue; }

    $pid = $pids[0];
    if (count($pids) > 1) {
        $ambiguos++;
        $anoNasc = $r['nascimento'] ? (int)substr($r['nascimento'], 0, 4) : null;
        $melhor = null;
        foreach ($pids as $c) {
            if ($anoNasc && $P[$c]['nasc'] && (int)substr($P[$c]['nasc'],0,4) === $anoNasc) { $melhor = $c; break; }
        }
        if ($melhor === null && $r['draft_ano']) {
            foreach ($pids as $c) if ($P[$c]['de'] && abs($P[$c]['de'] - (int)$r['draft_ano']) <= 1) { $melhor = $c; break; }
        }
        if ($melhor === null) { // mais jogos de carreira
            usort($pids, fn($a,$b) => $P[$b]['g'] <=> $P[$a]['g']);
            $melhor = $pids[0];
        }
        $pid = $melhor;
    }
    $usadosPid[$pid] = 1;
    $p = $P[$pid];
    $casados++;

    // premios: SUBSTITUI pelo computado. O dataset cobre todas as categorias
    // usadas, então a lista antiga (vocabulário legado tipo CHAMP/ALLNBA2 e
    // erros manuais) sai inteira — união só perpetuava lixo.
    $premios = premiosOrdenados($p['premios'], $ORDEM_PREMIOS);

    // times: uniao
    $timesSet = $p['times'];
    foreach ((json_decode($r['times'], true) ?: []) as $t) {
        $t = strtoupper($t);
        if (isset($GLOBALS['FRANQUIA'][$t])) $timesSet[$GLOBALS['FRANQUIA'][$t]] = 1;
    }
    $times = array_keys($timesSet); sort($times);

    // eras: da carreira toda
    $erasSet = [];
    foreach (array_keys($p['anos']) as $ano) {
        $dec = intdiv($ano, 10) * 10;
        $erasSet[substr((string)$dec, 2) . 's'] = 1;
    }
    foreach ((json_decode($r['eras'], true) ?: []) as $e) $erasSet[$e] = 1;
    $eras = array_values(array_intersect(['40s','50s','60s','70s','80s','90s','00s','10s','20s'], array_keys($erasSet)));

    $titulos = max((int)$r['titulos'], $p['titulos']);

    $sets = [
        'premios = ' . $pdo->quote(json_encode($premios, JSON_UNESCAPED_UNICODE)),
        'times = '   . $pdo->quote(json_encode($times)),
        'eras = '    . $pdo->quote(json_encode($eras)),
        'titulos = ' . $titulos,
        'premios_checado = 1',
    ];
    if ($r['pts_medio'] === null && $p['g'] > 0) {
        $sets[] = sprintf('pts_medio = %.1f', $p['pts']/$p['g']);
        $sets[] = sprintf('reb_medio = %.1f', $p['reb']/$p['g']);
        $sets[] = sprintf('ast_medio = %.1f', $p['ast']/$p['g']);
    }
    if ($r['posicao'] === null && posLegivel($p['pos']))  $sets[] = 'posicao = ' . $pdo->quote(posLegivel($p['pos']));
    if ($r['altura'] === null && alturaPes($p['ht']))     $sets[] = 'altura = '  . $pdo->quote(alturaPes($p['ht']));
    if ($r['peso'] === null && $p['wt'])                  $sets[] = 'peso = '    . (int)$p['wt'];
    if ($r['nascimento'] === null && $p['nasc'])          $sets[] = 'nascimento = ' . $pdo->quote($p['nasc']);

    // Chave por NOME (uk_nome é único): funciona mesmo se os ids de produção
    // divergirem dos locais (ex.: seed automático criado antes do import).
    $sql[] = 'UPDATE hoopgrid_players SET ' . implode(', ', $sets) . ' WHERE nome = ' . $pdo->quote($r['nome']) . ';';
}

// ── Jogadores novos (carreira encostando em 2024+) ───────
foreach ($P as $pid => $p) {
    if (isset($usadosPid[$pid])) continue;
    if (($p['ate'] ?? 0) < 2024) continue;          // só a era atual
    if (!$p['anos']) continue;                       // sem temporada NBA/BAA
    $nomeNovo = transliterar($p['nome']);
    if (isset($dbPorNorm[norm($nomeNovo)])) continue;

    $premios = premiosOrdenados($p['premios'], $ORDEM_PREMIOS);
    $times = array_keys($p['times']); sort($times);
    $erasSet = [];
    foreach (array_keys($p['anos']) as $ano) $erasSet[substr((string)(intdiv($ano,10)*10), 2) . 's'] = 1;
    $eras = array_values(array_intersect(['80s','90s','00s','10s','20s'], array_keys($erasSet)));

    $cols = "nome, times, pais, premios, eras, ativo, titulos, premios_checado, bio_checado";
    $vals = [
        $pdo->quote($nomeNovo),
        $pdo->quote(json_encode($times)),
        $pdo->quote('USA'), // dataset nao traz pais — revisar depois em dadosjogadores.php
        $pdo->quote(json_encode($premios, JSON_UNESCAPED_UNICODE)),
        $pdo->quote(json_encode($eras)),
        '1', (string)$p['titulos'], '1', '0',
    ];
    if ($p['g'] > 0) {
        $cols .= ", pts_medio, reb_medio, ast_medio";
        $vals[] = sprintf('%.1f', $p['pts']/$p['g']);
        $vals[] = sprintf('%.1f', $p['reb']/$p['g']);
        $vals[] = sprintf('%.1f', $p['ast']/$p['g']);
    }
    if (posLegivel($p['pos'])) { $cols .= ", posicao"; $vals[] = $pdo->quote(posLegivel($p['pos'])); }
    if (alturaPes($p['ht']))   { $cols .= ", altura";  $vals[] = $pdo->quote(alturaPes($p['ht'])); }
    if ($p['wt'])              { $cols .= ", peso";    $vals[] = (int)$p['wt']; }
    if ($p['nasc'])            { $cols .= ", nascimento"; $vals[] = $pdo->quote($p['nasc']); }

    $sql[] = "INSERT IGNORE INTO hoopgrid_players ($cols) VALUES (" . implode(', ', $vals) . ");";
    $novos++;
}

file_put_contents("$dir/atualizar-hoopgrid.sql", implode("\n", $sql) . "\n");
printf("\ncasados=%d ambiguos=%d sem_match=%d novos=%d\n", $casados, $ambiguos, count($semMatch), $novos);
echo "sem match (ate 40): " . implode('; ', array_slice($semMatch, 0, 40)) . "\n";
echo "SQL: $dir/atualizar-hoopgrid.sql (" . (count($sql)-3) . " comandos)\n";
