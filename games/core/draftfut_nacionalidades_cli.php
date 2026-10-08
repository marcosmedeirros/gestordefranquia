<?php
/**
 * IMPORTA NACIONALIDADE E FOTO DOS JOGADORES DO DRAFT — roda na mão.
 *
 * O FBA Draft precisa de nacionalidade pra ter a química do EA FC, e nenhum
 * elenco do projeto tem esse campo. A TheSportsDB tem, e o projeto já fala
 * com ela pra buscar escudo (games/core/copero_escudos.php) — então é a mesma
 * porta, com a mesma chave pública. De quebra vem `strCutout`, a foto
 * recortada em PNG com fundo transparente: o que a carta precisa.
 *
 *   php games/core/draftfut_nacionalidades_cli.php            (todos)
 *   php games/core/draftfut_nacionalidades_cli.php --n=20     (os 20 primeiros)
 *
 * ── SÓ OS CLUBES QUE ENTRAM NO BARALHO ───────────────────────────────
 *
 * A primeira versão varreu os 268 clubes com elenco real, levou rate limit da
 * API no meio e gravou clube vazio como se fosse clube sem jogador. Agora a
 * lista sai do próprio baralho do draft: depois do ajuste de OVR por liga
 * (@see draftFutOvrAjustado), quem fica acima de DFUT_OVR_MIN são ~90 clubes
 * das ligas grandes — que é justamente onde a API tem os dados melhores.
 *
 * ── O QUE A API DÁ, E O QUE ELA NÃO DÁ ───────────────────────────────
 *
 * A chave pública devolve no máximo DEZ jogadores por clube, e às vezes gente
 * que não é do clube (o auxiliar técnico, um ex-jogador). Então a cobertura é
 * parcial por construção, e o filtro é o nosso elenco: só entra quem tem o
 * nome no elenco local daquele clube. Nome que não casar fica SEM nação, e a
 * química trata isso — jogador sem nação não faz elo de nação com ninguém.
 * Melhor isso do que chutar o país pela liga, que erraria justamente no
 * estrangeiro, que é o jogador que o elo de nação existe pra encontrar.
 *
 * ── CASAR POR NOME É O PONTO FRÁGIL, E ELE É DECLARADO ───────────────
 *
 * Os elencos locais têm o nome como a liga escreve ("Arrascaeta"); a API tem o
 * nome completo ("Giorgian de Arrascaeta"). O casamento é por nome
 * normalizado: exato primeiro, e depois contenção — mas só com CINCO letras
 * ou mais e só quando UM único jogador da API casa. Com três letras "Rui"
 * casava com meio elenco; exigindo unicidade, homônimo não passa calado.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/copero_clubes.php';
require_once __DIR__ . '/fut_elencos.php';
require_once __DIR__ . '/draftfut_cartas.php';

const API = 'https://www.thesportsdb.com/api/v1/json/3/';
$DESTINO = __DIR__ . '/../data/draftfut_jogadores.php';

$limite = 0;
foreach ($argv as $a) if (preg_match('/^--n=(\d+)$/', $a, $m)) $limite = (int)$m[1];

/**
 * --refaz: volta em clube já visitado, em vez de pular.
 *
 * Precisou existir quando a chave de comparação foi consertada: a primeira
 * rodada casou nome por iconv('ASCII//TRANSLIT'), que separa o acento numa
 * marca ASCII, e todo nome acentuado passou batido. Os clubes já estavam
 * gravados, então sem isto o conserto não alcançaria ninguém.
 *
 * O que já foi achado NÃO é jogado fora — o resultado novo entra por cima por
 * jogador, e quem não casar de novo continua como está.
 */
$refaz = in_array('--refaz', $argv, true);

/** O país do jogo => o país como a API escreve, pra conferir o clube achado. */
const DFUT_API_PAIS = [
    'BRA' => 'Brazil',    'ENG' => 'England',  'ESP' => 'Spain',
    'ITA' => 'Italy',     'GER' => 'Germany',  'FRA' => 'France',
    'POR' => 'Portugal',  'NED' => 'Netherlands', 'TUR' => 'Turkey',
    'BEL' => 'Belgium',   'ARG' => 'Argentina',   'MEX' => 'Mexico',
    'USA' => 'USA',       'SAU' => 'Saudi Arabia', 'RUS' => 'Russia',
    'SCO' => 'Scotland',  'AUT' => 'Austria',  'GRE' => 'Greece',
];

/**
 * Pega uma URL, com paciência.
 *
 * A chave pública estrangula quem insiste: a primeira versão deste script
 * levou rate limit e passou a receber vazio de TODO clube. Por isso a espera
 * entre tentativas cresce, e o passo entre clubes é folgado — demorar quatro
 * minutos é mais rápido do que ser bloqueado e ter que voltar amanhã.
 */
function pega(string $url): ?array
{
    for ($i = 0; $i < 4; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
                                CURLOPT_USERAGENT => 'Mozilla/5.0 (FBA-Draft)']);
        $r   = curl_exec($ch);
        $cod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($r !== false && $cod === 200) {
            $j = json_decode((string)$r, true);
            if (is_array($j)) return $j;
        }
        sleep(2 + $i * 2);
    }
    return null;
}

/* A chave de comparação mora em draftfut_cartas.php — a daqui usava
   iconv('ASCII//TRANSLIT'), que neste PHP separa o acento numa marca ASCII em
   vez de tirar, e aí nome acentuado não casava com nada. */
function chave(string $n): string { return draftFutChaveNome($n); }

/**
 * Os nomes de clube que a API escreve de outro jeito.
 *
 * A busca por nome falha em sigla de estado ("Atlético-MG"), em patrocinador
 * no nome ("RB Bragantino") e em nome traduzido ("Bayern de Munique"). Só
 * entra aqui o que a busca não acha sozinha.
 */
const DFUT_BUSCA_CLUBE = [
    'Atlético-MG'        => 'Atletico Mineiro',
    'Athletico-PR'       => 'Athletico Paranaense',
    'RB Bragantino'      => 'Red Bull Bragantino',
    'Bayern de Munique'  => 'Bayern Munich',
    'Borussia M.gladbach' => 'Borussia Monchengladbach',
    'Internacional'      => 'Internacional',
    'Vasco da Gama'      => 'Vasco da Gama',
    'Manchester City'    => 'Manchester City',
    'Inter'              => 'Inter Milan',
    'Milan'              => 'AC Milan',
    'Roma'               => 'AS Roma',
    'Marselha'           => 'Marseille',
    'Mônaco'             => 'AS Monaco',
    'Copenhague'         => 'FC Copenhagen',
    'Colônia'            => 'FC Koln',
    'Sevilha'            => 'Sevilla',
    'Betis'              => 'Real Betis',
    'Atlético de Madrid' => 'Atletico Madrid',
    'Galatasaray'        => 'Galatasaray',
    'Fenerbahçe'         => 'Fenerbahce',
    'Beşiktaş'           => 'Besiktas',
    'Juventus'           => 'Juventus',
    'Benfica'            => 'Benfica',
    'Sporting'           => 'Sporting Lisbon',
    'Porto'              => 'FC Porto',
    'Ajax'               => 'Ajax',
    'PSV'                => 'PSV Eindhoven',
    'Feyenoord'          => 'Feyenoord',
    'Paris Saint-Germain' => 'Paris SG',
    'Boca Juniors'       => 'Boca Juniors',
    'River Plate'        => 'River Plate',
];

/* ── Os clubes que realmente alimentam o baralho ─────────────────────── */
require_once __DIR__ . '/draftfut.php';

$doBaralho = [];
foreach (draftFutBaralho() as $c) {
    if ($c['ovr'] < DFUT_OVR_MIN) continue;
    $doBaralho[$c['clube']] = true;
}

$clubes = [];
foreach (COPERO_CLUBES as [$nome, $liga, $forca, $escudo]) {
    if (!isset($doBaralho[$nome])) continue;
    $clubes[] = [$nome, $liga, (int)$forca];
}
if ($limite) $clubes = array_slice($clubes, 0, $limite);

fwrite(STDOUT, count($clubes) . " clubes no baralho do draft\n");

/* Retoma de onde parou: a API é lenta e o processo pode cair no meio. */
$dados = is_file($DESTINO) ? (array)require $DESTINO : [];

function grava(string $destino, array $dados): void
{
    file_put_contents($destino,
        "<?php\n/* Gerado por games/core/draftfut_nacionalidades_cli.php — NÃO EDITE À MÃO. */\nreturn "
        . var_export($dados, true) . ";\n");
}

$achados = 0; $semTime = 0; $i = 0;
foreach ($clubes as [$nome, $liga, $forca]) {
    $i++;
    if (!$refaz && isset($dados[$nome])) { $achados += count($dados[$nome]); continue; }

    $busca = DFUT_BUSCA_CLUBE[$nome] ?? $nome;
    $t = pega(API . 'searchteams.php?t=' . rawurlencode($busca));
    if ($t === null) {
        /* Rede fora ou rate limit: NÃO grava nada, pra a próxima rodada
           tentar de novo em vez de achar que o clube não tem jogador. */
        fwrite(STDOUT, "[$i/" . count($clubes) . "] $nome — API não respondeu, fica pra depois\n");
        sleep(5);
        continue;
    }

    /* CONFERE O PAÍS, como o buscador de escudos já faz: "Nacional" existe em
       cinco países e "Rapid" em dois. Clube de país errado é descartado. */
    $paisEsperado = DFUT_API_PAIS[draftFutPais($liga)] ?? '';
    $id = null;
    foreach ($t['teams'] ?? [] as $cand) {
        $pais = trim((string)($cand['strCountry'] ?? ''));
        if ($paisEsperado !== '' && $pais !== $paisEsperado) continue;
        $id = (string)$cand['idTeam'];
        break;
    }
    if (!$id) { $semTime++; $dados[$nome] = []; sleep(1); continue; }

    sleep(1);
    $p = pega(API . 'lookup_all_players.php?id=' . $id);
    if ($p === null) {
        fwrite(STDOUT, "[$i/" . count($clubes) . "] $nome — elenco não veio, fica pra depois\n");
        sleep(5);
        continue;
    }

    $porChave = [];
    foreach ($p['player'] ?? [] as $x) {
        $nac = trim((string)($x['strNationality'] ?? ''));
        if ($nac === '') continue;
        $porChave[chave((string)$x['strPlayer'])] = [
            'nac'  => $nac,
            'foto' => (string)($x['strCutout'] ?? ''),
        ];
    }

    $doClube = [];
    foreach (futElencoDoClube($nome, $forca) as $j) {
        $k = chave((string)$j['nome']);
        if ($k === '') continue;

        $achou = $porChave[$k] ?? null;
        if (!$achou && mb_strlen($k) >= 5) {
            /* Contenção, e só quando é ÚNICA: "Arrascaeta" dentro de
               "Giorgian de Arrascaeta" passa; um sobrenome que casa com dois
               jogadores da API não passa. */
            $cands = [];
            foreach ($porChave as $kk => $v) {
                if (str_contains($kk, $k) || str_contains($k, $kk)) $cands[] = $v;
            }
            if (count($cands) === 1) $achou = $cands[0];
        }
        if ($achou) $doClube[(string)$j['nome']] = $achou;
    }

    /* Mescla: o que ja tinha fica, e o novo entra por cima por jogador. */
    $dados[$nome] = $doClube + ($dados[$nome] ?? []);
    $achados += count($doClube);
    grava($DESTINO, $dados);
    fwrite(STDOUT, "[$i/" . count($clubes) . "] $nome — " . count($doClube) . " casados ($achados no total)\n");

    sleep(1);
}

grava($DESTINO, $dados);
fwrite(STDOUT, "pronto: " . count($dados) . " clubes, $achados jogadores com nação, $semTime sem clube na API\n");
