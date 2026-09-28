<?php
/**
 * IMPORTADOR DOS ELENCOS QUE JÁ VÊM COM OVR.
 *
 * O outro importador (fut_importar.php) recebe a fonte do Brasileirão e
 * CALCULA o overall a partir do valor de mercado. Aqui o overall já vem
 * pronto: são os elencos da CONMEBOL, gerados em cima da força de cada clube,
 * e reconverter isso só afastaria o elenco da força que ele devia ter.
 *
 * ── O FORMATO QUE ELE CHEGA ──────────────────────────────────────────
 *
 * O texto vem copiado de uma tabela, e na cópia as colunas grudam:
 *
 *   ⚪🔴 River PlatePosNomeIdadeOVRTipoGOLFranco Armani3985TitularZAG...
 *
 * Dá pra ler mesmo assim, e é o que esta função faz, porque pedir que o texto
 * seja reescrito à mão a cada país é onde o erro entra. "PosNomeIdadeOVRTipo"
 * abre um clube; depois dele vem uma sequência de POSIÇÃO + nome + dois
 * dígitos de idade + dois de overall + Titular/Reserva.
 *
 * Também aceita uma linha por jogador separada por "|", que é como o arquivo
 * fica quando alguém edita à mão depois.
 *
 * ── AS POSIÇÕES ──────────────────────────────────────────────────────
 *
 * A planilha usa dez posições e o jogo tem sete. O mapa é o mesmo do
 * importador do Brasileirão, pra um lateral não virar coisa diferente
 * dependendo de qual arquivo entrou.
 */

require_once __DIR__ . '/fut_elencos.php';
require_once __DIR__ . '/fut_clubes_br.php';
require_once __DIR__ . '/copero_clubes.php';

/** Da planilha para as sete posições do jogo. */
const FUT_OVR_POSICOES = [
    'GOL' => 'GOL',
    'ZAG' => 'ZAG',
    'LE'  => 'LAT',
    'LD'  => 'LAT',
    'VOL' => 'VOL',
    'MC'  => 'MEI',
    'MEI' => 'MEI',
    'PE'  => 'PON',
    'PD'  => 'PON',
    'ATA' => 'ATA',
];

/** A marca que separa um clube do outro no texto colado. */
const FUT_OVR_CABECALHO = 'PosNomeIdadeOVR';

/**
 * O MESMO CLUBE COM DOIS NOMES.
 *
 * A fonte escreve o nome completo ("Junior Barranquilla") e o catálogo do jogo
 * guarda o curto ("Junior"), que é como aparece na tabela. Sem casar os dois,
 * o clube entraria como time novo e sem força — ou seja, fora da Libertadores.
 *
 * Só entra aqui o que o casamento automático não resolve: exato primeiro,
 * depois este mapa, e por fim "um começa com o outro".
 */
const FUT_OVR_APELIDOS = [
    'Junior Barranquilla'    => 'Junior',
    'Defensor Sporting'      => 'Defensor',
    'Montevideo City Torque' => 'Montevideo City',
];

/**
 * O nome do clube como o catálogo o conhece, ou null se ele não existe lá.
 *
 * Devolver null é importante: elenco de clube que não está no catálogo não
 * serve pra nada, e gravar o arquivo faria parecer que serviu.
 */
function futOvrClubeDoCatalogo(string $nome): ?string
{
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        foreach (COPERO_CLUBES as $c) $mapa[$c[0]] = true;
        // O Brasil pequeno mora no catálogo próprio, não no do Copero.
        foreach (FUT_CLUBES_BR_EXTRA as $c) $mapa[$c[0]] = true;
    }

    $nome = trim($nome);
    if (isset($mapa[$nome])) return $nome;
    if (isset(FUT_OVR_APELIDOS[$nome]) && isset($mapa[FUT_OVR_APELIDOS[$nome]])) {
        return FUT_OVR_APELIDOS[$nome];
    }

    // "Junior Barranquilla" x "Junior": um é começo do outro. Exige 5 letras
    // pra não casar "Cerro" com "Cerro Largo" pelo lado errado.
    $n = mb_strtolower($nome);
    foreach (array_keys($mapa) as $cat) {
        $c = mb_strtolower($cat);
        if (mb_strlen($c) < 5 || mb_strlen($n) < 5) continue;
        if (str_starts_with($n, $c . ' ') || str_starts_with($c, $n . ' ')) return $cat;
    }
    return null;
}

/**
 * Lê o texto e devolve [clube => lista de jogadores].
 *
 * @return array<string, array<int, array{nome:string,pos:string,idade:int,ovr:int,titular:bool}>>
 */
function futOvrLerTexto(string $texto): array
{
    $texto = str_replace(["\r\n", "\r"], "\n", $texto);

    /* Tira as linhas de comentário, menos a que abre um clube: elas explicam o
       arquivo e não são elenco de ninguém, mas "# CLUBE:" é justamente o que
       separa um elenco do outro no formato de uma linha por jogador. */
    $texto = preg_replace('/^\s*#(?!\s*CLUBE:).*$/mu', '', $texto);
    $texto = preg_replace('/^\s*#\s*CLUBE:\s*/mu', '', $texto);

    $posicoes = implode('|', array_keys(FUT_OVR_POSICOES));
    $clubes = [];

    /* O texto colado: quebra nos cabeçalhos e o que sobra antes de cada um é o
       nome do clube. O primeiro pedaço não tem clube nenhum na frente. */
    $partes = preg_split('/' . FUT_OVR_CABECALHO . '(?:Tipo)?/u', $texto);
    if (count($partes) > 1) {
        for ($i = 1; $i < count($partes); $i++) {
            // O nome do clube é o fim do pedaço anterior, sem emoji e sem o
            // rabo do último jogador ("...Reserva🟡⚫ Peñarol").
            $antes = (string)$partes[$i - 1];
            $nome  = futOvrNomeDoClube($antes);
            if ($nome === '') continue;

            $corpo = (string)$partes[$i];
            preg_match_all(
                '/(' . $posicoes . ')([^\d]+?)(\d{2})(\d{2})(Titular|Reserva)?/u',
                $corpo, $ms, PREG_SET_ORDER);

            $lista = [];
            foreach ($ms as $m) {
                $jog = futOvrJogador($m[1], $m[2], (int)$m[3], (int)$m[4], ($m[5] ?? 'Titular') !== 'Reserva');
                if ($jog) $lista[] = $jog;
            }
            if ($lista) $clubes[$nome] = $lista;
        }
        return $clubes;
    }

    /* Uma linha por jogador, separada por "|", com "# CLUBE:" abrindo cada um.
       Os comentários já saíram acima, então o marcador vem de novo aqui. */
    $atual = '';
    foreach (explode("\n", $texto) as $linha) {
        $linha = trim($linha);
        if ($linha === '') continue;
        if (!str_contains($linha, '|')) { $atual = $linha; continue; }

        $c = array_map('trim', explode('|', $linha));
        if (count($c) < 4 || $atual === '') continue;
        $jog = futOvrJogador($c[0], $c[1], (int)$c[2], (int)$c[3], ($c[4] ?? 'Titular') !== 'Reserva');
        if ($jog) $clubes[$atual][] = $jog;
    }
    return $clubes;
}

/** Um jogador, já com a posição traduzida. Null quando a posição não existe. */
function futOvrJogador(string $pos, string $nome, int $idade, int $ovr, bool $titular): ?array
{
    $pos  = strtoupper(trim($pos));
    $nome = trim($nome);
    if ($nome === '' || !isset(FUT_OVR_POSICOES[$pos])) return null;
    if ($idade < 15 || $idade > 45 || $ovr < 20 || $ovr > 99) return null;

    return [
        'nome'    => $nome,
        'pos'     => FUT_OVR_POSICOES[$pos],
        'idade'   => $idade,
        'ovr'     => $ovr,
        'titular' => $titular,
    ];
}

/**
 * O nome do clube no fim de um pedaço de texto colado.
 *
 * Vem assim: "...Ramón Angulo2164Reserva🇺🇾 URUGUAI — 12 Clubes🟡⚫ Peñarol".
 * Tira o que é jogador, tira emoji e tira a linha de país, e o que sobra é o
 * nome. Sem isso o clube nasceria chamado "Reserva🟡⚫ Peñarol".
 */
function futOvrNomeDoClube(string $trecho): string
{
    // Só o rabo interessa; o resto é o elenco do clube anterior.
    $t = mb_substr($trecho, -160);

    /* A faixa que ele anota junto do nome — "Olimpia (Titulares: 65-72 |
       Reservas: 57-63)" — sai ANTES de tudo. Ela é só a régua que ele usou
       pra gerar, e o "Reservas:" de dentro dela seria confundido com o
       "Reserva" que fecha um jogador: o clube nascia chamado "s: 57-63)". */
    $t = preg_replace('/\([^)]*\)/u', ' ', $t);

    /* Corta tudo até onde o jogador anterior termina. Nas tabelas com a coluna
       Tipo isso é o último "Titular"/"Reserva"; nas sem ela, é o último par
       idade+overall, os quatro dígitos grudados. Sem este segundo caso o nome
       do clube saía com o elenco inteiro do anterior colado na frente. */
    if (preg_match('/.*(?:Titular|Reserva)(.*)$/su', $t, $m))      $t = $m[1];
    elseif (preg_match('/.*\d{4}(?!\d)(.*)$/su', $t, $m))          $t = $m[1];
    // Cabeçalho de país ("🇺🇾 URUGUAI — 12 Clubes") não é clube.
    $t = preg_replace('/.*\d+\s*Clubes?/u', '', $t);
    // Fora emoji, bandeira e pontuação solta.
    $t = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', ' ', $t);
    $t = preg_replace('/\s+/u', ' ', $t);

    return trim($t, " \t—-–·|");
}

/**
 * DOIS CLUBES, UM ARQUIVO SÓ.
 *
 * O elenco real é achado pelo slug do nome (@see futElencoDoClube), e o slug
 * come o acento: "Guaraní", do Paraguai, e "Guarani", de Campinas, viram os
 * dois `guarani`. Quem importasse por último ficaria com o arquivo — e o outro
 * clube passaria a jogar com o elenco alheio, sem nada indicando isso. Foi o
 * que aconteceu na primeira importação: o paraguaio ficou com o elenco de
 * Campinas e a força do arquivo virou a do paraguaio.
 *
 * Enquanto a busca for por nome não há como os dois terem elenco real. O
 * brasileiro fica com ele: este é um jogo de carreira no Brasil, onde o
 * Guarani disputa Série C, Paulista e Copa do Brasil, enquanto o paraguaio só
 * aparece se cair no grupo da Libertadores. O outro joga com elenco gerado a
 * partir da força dele, que é o que todo clube sem arquivo já faz.
 *
 * @return string|null o nome do clube que NÃO deve receber arquivo
 */
function futOvrClubeAtropelado(string $clube): ?string
{
    $slug = futSlugDoClube($clube);
    $brasileiros = [];
    foreach (FUT_CLUBES_BR_EXTRA as $c) $brasileiros[futSlugDoClube($c[0])] = $c[0];

    // Só é conflito quando os dois nomes existem e são DIFERENTES: Santos e
    // Paysandu aparecem nos dois catálogos, mas são o mesmo clube.
    if (!isset($brasileiros[$slug]) || $brasileiros[$slug] === $clube) return null;
    return $clube;   // o de fora perde o arquivo pro brasileiro de mesmo slug
}

/**
 * Grava o elenco de um clube em games/data/elencos/<slug>.php.
 *
 * @return array{slug:string, jogadores:int, forca:int}
 */
function futOvrGravar(string $clube, array $lista): array
{
    $linhas = [];
    $n = 0;
    foreach ($lista as $j) {
        $n++;
        $linhas[] = sprintf(
            "    ['nome' => %s, 'pos' => '%s', 'ovr' => %d, 'idade' => %d, 'num' => %d, "
            . "'potencial' => %d, 'energia' => 100, 'moral' => 75, 'lesao' => 0],",
            var_export($j['nome'], true), $j['pos'], $j['ovr'], $j['idade'], $n,
            futOvrPotencial($j['ovr'], $j['idade'])
        );
    }

    $slug = futSlugDoClube($clube);
    $php  = "<?php\n"
          . "/**\n"
          . " * ELENCO REAL — {$clube}.\n"
          . " *\n"
          . " * Gerado por games/core/fut_importar_ovr.php a partir de\n"
          . " * games/data/elencos_fonte/. NÃO EDITE À MÃO: mexa na fonte e rode o\n"
          . " * importador de novo, senão a próxima importação apaga a mudança.\n"
          . " *\n"
          . " * O overall veio pronto da fonte — foi gerado em cima da força do clube,\n"
          . " * e recalcular aqui só afastaria o elenco dela.\n"
          . " */\n\n"
          . "return [\n" . implode("\n", $linhas) . "\n];\n";

    file_put_contents(__DIR__ . '/../data/elencos/' . $slug . '.php', $php);

    return ['slug' => $slug, 'jogadores' => count($lista), 'forca' => futForcaDoElenco($lista)];
}

/**
 * NINGUÉM PASSA DE 89, E O TOPO É BRASILEIRO.
 *
 * O catálogo do Copero é de carreira mundial: nele o Flamengo é 92 e o
 * Manchester City é 99, e um clube de 92 tem titular de 92. O dono do jogo
 * pediu duas coisas que essa escala não dá — nenhum jogador de 90 e tantos, e
 * Flamengo e Palmeiras como os mais fortes.
 *
 * As duas se resolvem de uma vez comprimindo a escala: o topo sul-americano
 * (92) vira FUT_OVR_TETO_CLUBE, e o resto encolhe junto, na proporção. A
 * ORDEM não muda — Flamengo continua na frente do River, que continua na
 * frente do Boca — só a distância encolhe. Com clube em 85, o melhor titular
 * dele fica na casa dos 88, que é o teto que ele pediu.
 *
 * O piso fica onde está: comprimir embaixo empurraria o Guabirá pra cima, e
 * clube pequeno forte é justamente o que tira a graça da zebra.
 */
const FUT_OVR_TETO_CLUBE = 85;   // a força do Flamengo e do Palmeiras
const FUT_OVR_TETO_JOGADOR = 89; // ninguém passa disto, em clube nenhum
const FUT_OVR_PISO_ESCALA = 35;  // abaixo daqui nada encolhe

/**
 * O TETO DE QUEM JÁ PASSOU DOS 35.
 *
 * O elenco gerado tem curva de carreira (@see futCurvaDaIdade) e o real não:
 * o overall dele vem pronto da fonte. Sem um teto aqui, o deslocamento que
 * alinha o elenco à força do clube sobe o veterano junto com o resto — foi
 * assim que o Weverton, 38 anos, terminou com 85 no Palmeiras.
 *
 * Não é questão de parecer estranho. O valor de mercado despenca com a idade,
 * então um craque velho é o melhor do elenco custando quase nada, e o jogo
 * inteiro vira garimpar quarentões subvalorizados. É a mesma razão que fez a
 * curva existir no elenco gerado.
 */
const FUT_OVR_TETO_IDADE = [36 => 85, 37 => 84, 38 => 82, 39 => 80, 40 => 78];

function futOvrTetoDaIdade(int $idade): int
{
    if ($idade < 36) return FUT_OVR_TETO_JOGADOR;
    if ($idade >= 40) return FUT_OVR_TETO_IDADE[40];
    return FUT_OVR_TETO_IDADE[$idade];
}

function futOvrForcaAlvo(int $forcaCatalogo): int
{
    if ($forcaCatalogo <= FUT_OVR_PISO_ESCALA) return $forcaCatalogo;

    // O maior da América do Sul no catálogo — é ele que vira o teto.
    static $maior = null;
    if ($maior === null) {
        $maior = FUT_OVR_PISO_ESCALA + 1;
        foreach (COPERO_CLUBES as $c) {
            $liga = $c[1] ?? '';
            if (!preg_match('/^(BR|AR|UY|CL|CO|EC|PY|PE|BO|VE)\d/', $liga)) continue;
            if ((int)$c[2] > $maior) $maior = (int)$c[2];
        }
    }

    $fator = (FUT_OVR_TETO_CLUBE - FUT_OVR_PISO_ESCALA) / max(1, $maior - FUT_OVR_PISO_ESCALA);
    return (int)round(FUT_OVR_PISO_ESCALA + ($forcaCatalogo - FUT_OVR_PISO_ESCALA) * $fator);
}

/**
 * Desloca o elenco inteiro até ele bater a força-alvo, e corta em 89.
 *
 * É o mesmo deslocamento que o elenco gerado já sofre (@see futElencoGenerico):
 * soma o mesmo número em todo mundo, então a hierarquia de dentro do elenco
 * fica intacta — o craque continua sendo o craque, o banco continua banco.
 */
function futOvrAjustarParaForca(array $lista, int $alvo): array
{
    /* Mais voltas do que parece necessário porque os tetos travam gente no
       caminho: quando o veterano para no limite da idade, o elenco fica abaixo
       do alvo e a volta seguinte sobe o resto pra compensar. Sem isso o clube
       terminaria mais fraco do que o catálogo diz só por ter um quarentão. */
    for ($volta = 0; $volta < 12; $volta++) {
        $delta = $alvo - futForcaDoElenco($lista);
        if ($delta === 0) break;
        $mudou = false;
        foreach ($lista as &$j) {
            $teto = futOvrTetoDaIdade((int)$j['idade']);
            $novo = (int)max(25, min($teto, $j['ovr'] + $delta));
            if ($novo !== $j['ovr']) { $j['ovr'] = $novo; $mudou = true; }
        }
        unset($j);
        // Todo mundo no teto (ou no piso): insistir não muda mais nada.
        if (!$mudou) break;
    }
    return $lista;
}

/**
 * O potencial de quem já tem OVR.
 *
 * Quem é novo ainda cresce; quem passou dos 30 já chegou onde ia chegar. É a
 * mesma ideia da curva de idade do elenco gerado, só que aplicada ao teto em
 * vez do overall de hoje.
 */
function futOvrPotencial(int $ovr, int $idade): int
{
    if ($idade >= 30) return $ovr;
    $sobra = (int)round((30 - $idade) * 0.7);
    return (int)min(99, $ovr + min(8, $sobra));
}
