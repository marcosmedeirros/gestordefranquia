<?php
/**
 * QUANTO O JOGADOR RENDE EM CADA SISTEMA — as estrelas por estilo de jogo.
 *
 * O 2K tem isso (System Proficiency: quem encaixa no sistema do time ganha
 * bônus), mas NUNCA publicou como calcula. Procurei: não há fórmula da 2K em
 * lugar nenhum, e o que circula por aí é palpite escrito com cara de
 * especificação. Então os pesos abaixo são DECISÃO DA LIGA, não descoberta —
 * e é melhor assim, porque dá pra discutir e ajustar.
 *
 * A conta é uma média ponderada das skills que o sistema exige, na mesma
 * escala de letras que já está no elenco (A+ … F).
 *
 * DUAS COISAS QUE A MEDIÇÃO MOSTROU, e que estão embutidas aqui:
 *
 *   A RÉGUA NÃO É "85+ = CINCO ESTRELAS". Nos 159 titulares da ELITE, esse
 *   corte dava cinco estrelas a 40 deles — um quarto da liga. Cinco estrelas
 *   é exceção, então o corte saiu dos percentis reais (ver SISTEMA_ESTRELAS).
 *
 *   JOGADOR BOM É BOM EM QUASE TUDO. As skills andam junto com o overall, e
 *   um craque varia pouco entre os oito sistemas — Julius Erving fica entre
 *   87 e 94. Por isso a nota crua responde "quanto ele rende aqui", e quem
 *   responde "ele é ESPECIALISTA nisto?" é o `destaque`: a distância entre o
 *   sistema e a média do próprio jogador. Grant Hill, que vai de 70 a 94, é
 *   especialista; Erving é coringa. São perguntas diferentes e a tela mostra
 *   as duas.
 */

/**
 * As letras do elenco são FAIXAS de atributo do 2K. Uso o meio de cada faixa.
 * '-' e vazio não viram zero: viram ausência, e atributo ausente não pesa —
 * senão quem tem a ficha incompleta parece ruim em vez de desconhecido.
 */
const SISTEMA_NOTA_LETRA = [
    'A+' => 97, 'A' => 92, 'A-' => 87,
    'B+' => 82, 'B' => 77, 'B-' => 72,
    'C+' => 67, 'C' => 62, 'C-' => 57,
    'D+' => 52, 'D' => 47, 'D-' => 42,
    'F'  => 35,
];

/**
 * O que cada sistema pede, em peso. As chaves são as colunas `skill_*`.
 *
 * `Balanced` é de propósito o mais espalhado: ele não pede nada em especial,
 * então premia quem faz tudo razoavelmente — e é por isso que craque
 * desequilibrado rende MENOS nele do que no sistema que explora a força dele.
 */
const SISTEMA_PESOS = [
    'pace_space'        => ['3pt' => 40, 'athl' => 25, 'play' => 20, 'iq' => 15],
    'seven_seconds'     => ['athl' => 40, 'in' => 20, 'play' => 20, '3pt' => 20],
    'perimeter_centric' => ['3pt' => 35, 'play' => 25, 'mid' => 20, 'athl' => 20],
    'post_centric'      => ['in' => 40, 'reb' => 25, 'post_d' => 20, 'mid' => 15],
    'triangle'          => ['mid' => 30, 'in' => 25, 'iq' => 25, 'play' => 20],
    'grit_grind'        => ['per_d' => 30, 'post_d' => 25, 'reb' => 25, 'athl' => 20],
    'defense'           => ['per_d' => 30, 'post_d' => 30, 'reb' => 25, 'iq' => 15],
    'balanced'          => ['in' => 15, 'mid' => 15, '3pt' => 15, 'play' => 15,
                            'reb' => 10, 'per_d' => 10, 'post_d' => 10, 'iq' => 10],
];

/**
 * Os cortes, tirados dos percentis das notas reais da liga — e não de números
 * redondos. Aproximadamente: 10% chegam a cinco estrelas, 20% a quatro.
 */
const SISTEMA_ESTRELAS = [
    ['min' => 90, 'estrelas' => 5, 'rotulo' => 'Perfeito'],
    ['min' => 82, 'estrelas' => 4, 'rotulo' => 'Muito bom'],
    ['min' => 73, 'estrelas' => 3, 'rotulo' => 'Serve'],
    ['min' => 64, 'estrelas' => 2, 'rotulo' => 'Limitado'],
    ['min' => 0,  'estrelas' => 1, 'rotulo' => 'Fora do sistema'],
];

/** Os nomes na ordem em que a tela mostra. */
function sistemaNomes(): array
{
    return [
        'balanced' => 'Balanced', 'triangle' => 'Triangle', 'grit_grind' => 'Grit & Grind',
        'pace_space' => 'Pace & Space', 'perimeter_centric' => 'Perimeter Centric',
        'post_centric' => 'Post Centric', 'seven_seconds' => 'Seven Seconds', 'defense' => 'Defense',
    ];
}

/** A letra virou número, ou null quando não há nota. */
function sistemaNotaDaLetra(?string $letra): ?int
{
    $l = strtoupper(trim((string)$letra));
    return SISTEMA_NOTA_LETRA[$l] ?? null;
}

/**
 * A FICHA VEM DE DOIS LUGARES, e ignorar o segundo custava 60 jogadores.
 *
 * As colunas `skill_*` são a fonte principal, mas `player_skill_grades` (um
 * JSON de notas em letra) é o que existe pra quem foi importado por outro
 * caminho — 60 dos 1.415 do elenco têm SÓ ele. Sem ler os dois, esses
 * apareceriam como "sem ficha técnica" tendo ficha completa.
 *
 * A mesma regra do player.php: a coluna manda quando preenchida, o JSON
 * cobre o resto. Note que no JSON o três pontos se chama `pt3`, e não `3pt`.
 */
function sistemaNormalizarSkills(array $p): array
{
    $doJson = [];
    if (!empty($p['player_skill_grades'])) {
        $d = json_decode((string)$p['player_skill_grades'], true);
        if (is_array($d)) $doJson = $d;
    }
    $noJson = ['3pt' => 'pt3'];   // só esta muda de nome

    $out = $p;
    foreach (['in','mid','3pt','play','post_d','per_d','reb','athl','iq'] as $k) {
        $col = 'skill_' . $k;
        $v = $p[$col] ?? null;
        if ($v === null || trim((string)$v) === '' || trim((string)$v) === '-') {
            $v = $doJson[$noJson[$k] ?? $k] ?? null;
        }
        $out[$col] = $v;
    }
    return $out;
}

/** O jogador tem ficha técnica preenchida? Sem ela não há o que calcular. */
function sistemaTemSkills(array $p): bool
{
    $p = sistemaNormalizarSkills($p);
    foreach (array_keys(SISTEMA_PESOS['balanced']) as $k) {
        if (sistemaNotaDaLetra($p['skill_' . $k] ?? null) !== null) return true;
    }
    return false;
}

/**
 * A nota do jogador num sistema, de 0 a 100 — ou null sem ficha.
 *
 * O peso de um atributo em branco sai da conta inteira (numerador E
 * denominador): um pivô sem 3PT preenchido não é um pivô com 3PT zero.
 */
function sistemaNotaDoJogador(array $p, string $sistema): ?float
{
    $pesos = SISTEMA_PESOS[$sistema] ?? null;
    if (!$pesos) return null;
    $p = sistemaNormalizarSkills($p);

    $soma = 0; $peso = 0;
    foreach ($pesos as $campo => $w) {
        $v = sistemaNotaDaLetra($p['skill_' . $campo] ?? null);
        if ($v === null) continue;
        $soma += $v * $w;
        $peso += $w;
    }
    return $peso > 0 ? round($soma / $peso, 1) : null;
}

/** Quantas estrelas aquela nota vale. @return array ['estrelas','rotulo'] */
function sistemaEstrelas(?float $nota): array
{
    if ($nota === null) return ['estrelas' => 0, 'rotulo' => 'Sem ficha técnica'];
    foreach (SISTEMA_ESTRELAS as $faixa) {
        if ($nota >= $faixa['min']) return ['estrelas' => $faixa['estrelas'], 'rotulo' => $faixa['rotulo']];
    }
    return ['estrelas' => 1, 'rotulo' => 'Fora do sistema'];
}

/** "★★★★☆" */
function sistemaEstrelasTexto(int $n, string $cheia = '★', string $vazia = '☆'): string
{
    $n = max(0, min(5, $n));
    return str_repeat($cheia, $n) . str_repeat($vazia, 5 - $n);
}

/**
 * Os oito sistemas de um jogador, do melhor pro pior.
 *
 * `destaque` é a nota menos a média do próprio jogador: positivo quer dizer
 * "este sistema tira mais dele do que a média". É o que separa o especialista
 * do coringa — ver o cabeçalho do arquivo.
 *
 * @return array<string,array> chave do sistema => [nome, nota, estrelas, rotulo, destaque]
 */
function sistemaPerfilDoJogador(array $p): array
{
    if (!sistemaTemSkills($p)) return [];

    $nomes = sistemaNomes();
    $notas = [];
    foreach (array_keys($nomes) as $chave) {
        $n = sistemaNotaDoJogador($p, $chave);
        if ($n !== null) $notas[$chave] = $n;
    }
    if (!$notas) return [];

    $media = array_sum($notas) / count($notas);

    $saida = [];
    foreach ($notas as $chave => $nota) {
        $e = sistemaEstrelas($nota);
        $saida[$chave] = [
            'nome'     => $nomes[$chave],
            'nota'     => $nota,
            'estrelas' => $e['estrelas'],
            'rotulo'   => $e['rotulo'],
            'destaque' => round($nota - $media, 1),
        ];
    }
    uasort($saida, fn($a, $b) => $b['nota'] <=> $a['nota']);
    return $saida;
}

/** Só o melhor sistema do jogador — é o que o /jogador do bot mostra. */
function sistemaMelhorDoJogador(array $p): ?array
{
    $perfil = sistemaPerfilDoJogador($p);
    if (!$perfil) return null;
    $chave = array_key_first($perfil);
    return ['chave' => $chave] + $perfil[$chave];
}

/**
 * A nota do TIME num sistema: a média dos titulares.
 *
 * Só titulares, porque é quem joga o sistema. Quem está sem ficha técnica não
 * entra na média nem como zero — entra na contagem de `sem_ficha`, pra tela
 * poder dizer que a média está incompleta em vez de mostrar um número que
 * finge saber.
 *
 * @param array $titulares linhas de `players` com as colunas skill_*
 */
function sistemaPerfilDoTime(array $titulares): array
{
    $nomes = sistemaNomes();
    $comFicha = array_values(array_filter($titulares, 'sistemaTemSkills'));
    $semFicha = count($titulares) - count($comFicha);

    $saida = [];
    foreach ($nomes as $chave => $nome) {
        $notas = [];
        foreach ($comFicha as $p) {
            $n = sistemaNotaDoJogador($p, $chave);
            if ($n !== null) $notas[] = $n;
        }
        $media = $notas ? round(array_sum($notas) / count($notas), 1) : null;
        $e = sistemaEstrelas($media);
        $saida[$chave] = [
            'nome'      => $nome,
            'nota'      => $media,
            'estrelas'  => $e['estrelas'],
            'rotulo'    => $e['rotulo'],
            'jogadores' => count($notas),
        ];
    }
    uasort($saida, fn($a, $b) => ($b['nota'] ?? -1) <=> ($a['nota'] ?? -1));
    return ['sistemas' => $saida, 'sem_ficha' => $semFicha, 'com_ficha' => count($comFicha)];
}

/** As colunas que as consultas precisam trazer pra tudo isto funcionar. */
function sistemaColunasSql(string $alias = 'p'): string
{
    $a = $alias !== '' ? $alias . '.' : '';
    return "{$a}skill_in, {$a}skill_mid, {$a}skill_3pt, {$a}skill_post_d, {$a}skill_per_d, "
         . "{$a}skill_play, {$a}skill_reb, {$a}skill_athl, {$a}skill_iq, {$a}player_skill_grades";
}

/**
 * Por que este jogador não tem nota — pra tela dizer algo melhor que um traço.
 *
 * O calouro recém-draftado é o caso comum: ele entra no elenco antes de
 * alguém preencher a ficha, e ficar com "sem ficha técnica" do lado do nome
 * parece defeito do sistema quando é só a vez dele ainda não ter chegado.
 * São 36 dos 392 calouros — os outros 91% já têm ficha e calculam normal.
 *
 * @return array ['tag' => 'ROOKIE'|'—', 'texto' => explicação]
 */
function sistemaSemFichaMotivo(array $p): array
{
    $ehCalouro = ($p['drafted_season_number'] ?? null) !== null;
    return $ehCalouro
        ? ['tag' => 'ROOKIE', 'texto' => 'Calouro — a ficha técnica ainda não foi preenchida.']
        : ['tag' => '—',      'texto' => 'Sem ficha técnica cadastrada.'];
}
