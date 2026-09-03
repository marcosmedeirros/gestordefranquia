<?php
/**
 * O GUIA ÚNICO: os três editais organizados por ASSUNTO, e não por liga.
 *
 * Hoje cada liga tem o seu PDF, e a mesma regra vive escrita três vezes. Quem
 * quer saber como funciona o leilão abre o edital da própria liga, acha o
 * artigo 43, e não tem como saber se na liga do lado é igual. Pior: quando as
 * três dizem coisas diferentes, ninguém percebe — não há onde comparar.
 *
 * Aqui a pergunta vira o eixo. Cada assunto reúne o que as três dizem, lado a
 * lado, e a divergência aparece sozinha.
 *
 * O TEXTO DOS ARTIGOS NÃO É COPIADO PRA CÁ. Ele é lido dos PDFs a cada carga,
 * pelo mesmo edital_texto.php que o bot usa. Transcrever criaria uma quarta
 * versão da regra, que envelheceria calada no dia em que um PDF fosse trocado
 * — que é exatamente o problema que esta página existe pra resolver.
 */

require_once __DIR__ . '/edital_texto.php';

const EDITAL_LIGAS = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

/**
 * Ligas que NÃO usam moedas.
 *
 * A ELITE deixou de ter moedas — o card de moedas dela saiu do admin pelo
 * mesmo motivo. Mas o edital dela ainda traz os artigos antigos, com direito a
 * tabela de distribuição, e o guia os exibia como regra em vigor.
 *
 * Enquanto o PDF não for reeditado, a lista é o que separa o que vale do que
 * é texto encalhado.
 *
 * @see EDITAL_ASSUNTOS_POR_LIGA — onde ela é aplicada.
 */
const EDITAL_LIGAS_SEM_MOEDA = ['ELITE'];

/**
 * Os assuntos do guia.
 *
 * `busca` é o que encontra os artigos daquele assunto nos editais. `resumo` é
 * a explicação em português de gente — escrita a partir do que os editais e o
 * código dizem, e é a única parte desta página redigida à mão.
 *
 * Onde os editais são OMISSOS o resumo diz isso, em vez de preencher com o que
 * costuma ser praticado. Guia de liga que inventa regra vira discussão no
 * grupo, e a pessoa age achando que estava amparada.
 */
function editalAssuntos(): array
{
    return [
        'cap' => [
            'titulo' => 'Salary cap',
            'icone'  => 'bi-graph-up',
            'busca'  => '/salary cap|teto salarial|folha salarial|espaço salarial/iu',
            'resumo' => 'O teto de folha da franquia. Toda contratação — trade, free agency ou leilão — '
                      . 'é conferida contra ele antes de valer. O cap muda de liga pra liga e é reajustado '
                      . 'a cada duas temporadas na ROOKIE.',
        ],
        'elenco' => [
            'titulo' => 'Elenco e roster',
            'icone'  => 'bi-people-fill',
            'busca'  => '/\b1[35] atletas\b|mínimo estatutário|composição do elenco|roster/iu',
            'resumo' => 'Quantos atletas o time precisa ter e quantos pode ter. O piso existe pra ninguém '
                      . 'entrar na temporada com meio time, e o teto pra ninguém estocar jogador.',
        ],
        'trades' => [
            'titulo' => 'Trocas',
            'icone'  => 'bi-arrow-left-right',
            'busca'  => '/permuta|troca de atletas|trade\b/iu',
            'resumo' => 'Como propor, o que pode entrar na negociação e o que trava uma troca. '
                      . 'Toda trade passa pelo administrativo antes de valer.',
        ],
        'free-agency' => [
            'titulo' => 'Free agency e waivers',
            'icone'  => 'bi-person-plus-fill',
            'busca'  => '/free agency|agente livre|waiver|dispensa/iu',
            'resumo' => 'A janela de contratar quem está sem time e de dispensar quem está no seu. '
                      . 'Tem limite de movimentações por temporada.',
        ],
        'leilao' => [
            'titulo' => 'Leilão',
            'icone'  => 'bi-hammer',
            'busca'  => '/leilão|lance|arremat|adjudicad/iu',
            'resumo' => 'O jogador vai a lance e fica com quem oferecer mais. A oferta é vinculante: '
                      . 'feita, não volta atrás, e o valor sai do saldo se você arrematar.',
        ],
        'moedas' => [
            'titulo' => 'Moedas',
            'icone'  => 'bi-coin',
            'busca'  => '/moedas? virtuais|aporte de moedas|distribuição de moedas/iu',
            'resumo' => 'A pecúnia que banca o leilão e a free agency na NEXT, RISE e ROOKIE. '
                      . 'É distribuída pela classificação — quem terminou pior recebe mais, do 2º ao '
                      . 'último — e zera a cada temporada, sem acumular. '
                      . 'A ELITE não usa moedas: lá a contratação é por salário, dentro do cap. '
                      . 'O edital da ELITE ainda traz os artigos antigos de moeda, com tabela de '
                      . 'distribuição e tudo: eles não aparecem abaixo porque não valem mais, e '
                      . 'o PDF é que precisa ser reeditado.',
        ],
        'draft' => [
            'titulo' => 'Draft e picks',
            'icone'  => 'bi-trophy-fill',
            'busca'  => '/\bdraft\b|escolha de primeira rodada|loteria/iu',
            'resumo' => 'O recrutamento de novatos, a loteria que define a ordem e as picks como ativo '
                      . 'de troca.',
        ],
        'gleague' => [
            'titulo' => 'G-League',
            'icone'  => 'bi-arrow-down-up',
            'busca'  => '/g-?league/iu',
            'resumo' => 'Onde encostar jovem que não vai jogar. Quantas vagas você tem depende do '
                      . 'tamanho do seu elenco.',
        ],
        'progressao' => [
            'titulo' => 'Progressão e regressão',
            'icone'  => 'bi-bar-chart-line-fill',
            'busca'  => '/progression|progressão|regressão|declínio técnico|evolução técnica/iu',
            'resumo' => 'Como os atletas evoluem e decaem entre temporadas. É o 2K que processa, '
                      . 'com índices fixados no edital.',
        ],
        'punicoes' => [
            'titulo' => 'Punições',
            'icone'  => 'bi-exclamation-triangle-fill',
            'busca'  => '/punição|advertência|suspensão|exclusão sumária|sanção|sanções|penalidade/iu',
            'resumo' => 'O que acontece quando alguém descumpre o edital. A escala é progressiva: '
                      . 'advertência, suspensão temporária e exclusão.',
        ],
        'ranking' => [
            'titulo' => 'Ranking e pontuação',
            'icone'  => 'bi-list-ol',
            'busca'  => '/ranking geral|pontuação acumulativa|desempate|standings/iu',
            'resumo' => 'Como se soma ponto ao longo da edição e quem leva o título no fim.',
        ],
        'pagamento' => [
            'titulo' => 'Pagamento e reembolso',
            'icone'  => 'bi-cash-coin',
            'busca'  => '/reembolso|aporte financeiro|taxa de inscrição|premiação em/iu',
            'resumo' => 'O que se paga pra participar, o que dá direito a devolução e o que não dá.',
        ],
    ];
}

/**
 * Os artigos de um assunto, em todas as ligas que têm edital.
 *
 * @return array<string, list<array{num:int,capitulo:string,texto:string}>>
 */
function editalPorAssunto(PDO $pdo, string $chave): array
{
    $assuntos = editalAssuntos();
    if (!isset($assuntos[$chave])) return [];
    $re = $assuntos[$chave]['busca'];

    $out = [];
    foreach (EDITAL_LIGAS as $liga) {
        // Artigo de moeda de liga que não usa moeda é texto encalhado no PDF:
        // mostrar seria dizer que vale.
        if ($chave === 'moedas' && in_array($liga, EDITAL_LIGAS_SEM_MOEDA, true)) continue;

        $txt = editalTexto($pdo, $liga);
        if ($txt === null) continue;
        $achados = [];
        foreach (editalArtigos($txt) as $a) {
            if (preg_match($re, $a['texto'])) $achados[] = $a;
        }
        if ($achados) $out[$liga] = $achados;
    }
    return $out;
}

/** A chave de comparação de um artigo: sem acento, sem pontuação, sem liga. */
function editalChaveDoArtigo(string $texto): string
{
    $s = mb_strtolower($texto, 'UTF-8');
    // O nome da liga sai: "a FBA Elite exige" e "a FBA Next exige" são a mesma
    // regra, e mantê-los faria toda regra comum parecer exclusiva.
    $s = preg_replace('/\b(elite|next|rise|rookie)\b/u', '', $s);
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = preg_replace('/[^a-z0-9 ]/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * OS ARTIGOS DE UM ASSUNTO, AGRUPADOS POR REGRA — não por liga.
 *
 * Medido: os editais são 85% o mesmo documento entre ELITE e NEXT, ~70% contra
 * a ROOKIE. Mostrar cada artigo três vezes, uma por liga, triplicava a página
 * com texto repetido e escondia o que interessa: as poucas linhas em que as
 * ligas discordam.
 *
 * Aqui cada regra aparece UMA vez, com as ligas que a compartilham. Quem tem
 * texto diferente vira grupo próprio, e é isso que salta aos olhos.
 *
 * @return list<array{ligas:list<string>,artigos:array<string,array>,texto:string,capitulo:string}>
 */
function editalAssuntoAgrupado(PDO $pdo, string $chave): array
{
    $porLiga = editalPorAssunto($pdo, $chave);
    if (!$porLiga) return [];

    $grupos = [];
    foreach ($porLiga as $liga => $artigos) {
        foreach ($artigos as $a) {
            $chaveArt = editalChaveDoArtigo($a['texto']);

            /* Procura um grupo já formado com texto praticamente igual. O corte
               de 90 é mais rígido que o da comparação geral (70) porque aqui o
               texto vai ser EXIBIDO como se valesse pras duas ligas: com um
               corte frouxo, uma diferença de valor — "13 atletas" contra "15" —
               ficaria escondida atrás da etiqueta da outra liga.

               O PRÉ-FILTRO É PELO TAMANHO, e não pelo começo do texto.
               similar_text é quadrático, e comparar cada artigo com todos os
               grupos já formados levava a página a 8,5 segundos. A tentação é
               exigir que os dois comecem igual — mas artigos 90% idênticos
               divergem justo no começo o tempo todo ("na FBA Elite..." contra
               "as equipes da..."), e esse filtro derrubou os blocos comuns de
               68 para 13: quase tudo virou exceção de uma liga só.

               O tamanho, ao contrário, é um limite EXATO. similar_text devolve
               2·iguais/(a+b), e iguais nunca passa do menor dos dois — então
               90% exige que o maior texto não passe de 1,22× o menor. Fora
               dessa faixa a conta cara não pode dar 90%, e pular é seguro. */
            $trecho = mb_substr($chaveArt, 0, 600);
            $tam = mb_strlen($trecho);
            $achou = null;
            foreach ($grupos as $i => $g) {
                $tamG = $g['tam'];
                if ($tam > $tamG * 1.23 || $tamG > $tam * 1.23) continue;
                similar_text($trecho, $g['trecho'], $p);
                if ($p >= 90) { $achou = $i; break; }
            }

            if ($achou === null) {
                $grupos[] = ['chave' => $chaveArt, 'trecho' => $trecho, 'tam' => $tam, 'ligas' => [$liga],
                             'artigos' => [$liga => $a], 'texto' => $a['texto'],
                             'capitulo' => $a['capitulo']];
            } else {
                $grupos[$achou]['ligas'][] = $liga;
                $grupos[$achou]['artigos'][$liga] = $a;
            }
        }
    }

    // Regra de todo mundo primeiro; a exceção de uma liga só fica no fim, que
    // é onde se procura o que difere.
    usort($grupos, fn($a, $b) => count($b['ligas']) <=> count($a['ligas']));
    return $grupos;
}

/** Quais ligas têm edital lido e quantos artigos cada uma tem. */
function editalCobertura(PDO $pdo): array
{
    $out = [];
    foreach (EDITAL_LIGAS as $liga) {
        $txt = editalTexto($pdo, $liga);
        // Liga que lê o edital de outra não pode aparecer como se tivesse o
        // seu: o número de artigos é o mesmo, e sem dizer de quem é o texto a
        // tela afirmaria um documento que não existe.
        $herda = (editalTextoProprio($pdo, $liga) === null && $txt !== null)
            ? (EDITAL_HERDA_DE[$liga] ?? null) : null;

        $out[$liga] = $txt === null
            ? ['ok' => false, 'artigos' => 0, 'chars' => 0, 'herda' => null]
            : ['ok' => true, 'artigos' => count(editalArtigos($txt)),
               'chars' => mb_strlen($txt), 'herda' => $herda];
    }
    return $out;
}

/**
 * As divergências que a leitura dos três editais revelou.
 *
 * Cada item aqui foi MEDIDO no texto, não suposto. É a lista de coisas que
 * precisam de decisão sua antes de este guia poder virar documento oficial —
 * enquanto uma delas estiver aberta, publicar como regra seria oficializar
 * uma contradição.
 */
function editalDivergencias(PDO $pdo): array
{
    $itens = [];

    // 1. Liga sem edital, ou lendo o de outra.
    $cob = editalCobertura($pdo);
    foreach (EDITAL_LIGAS as $liga) {
        if (empty($cob[$liga]['ok'])) {
            $itens[] = [
                'grave' => true,
                'titulo' => "A {$liga} não tem edital",
                'texto'  => "Nenhum PDF cadastrado e nenhuma liga de onde herdar. Os GMs da "
                          . "{$liga} não têm documento de regra pra consultar, e o bot não "
                          . 'consegue responder dúvida daquela liga.',
            ];
            continue;
        }
        if (!empty($cob[$liga]['herda'])) {
            $itens[] = [
                'grave' => false,
                'titulo' => "A {$liga} se rege pelo edital da {$cob[$liga]['herda']}",
                'texto'  => "A {$liga} não tem documento próprio: tela e bot respondem com o "
                          . "edital da {$cob[$liga]['herda']}, e dizem de quem é o texto. "
                          . 'Onde ele cita o nome da outra liga, a regra vale para as duas. '
                          . "No dia em que a {$liga} tiver o seu, basta subir o PDF — o "
                          . 'próprio passa a valer na hora.',
            ];
        }
    }

    // 2. Numeração repetida dentro do mesmo edital.
    foreach (EDITAL_LIGAS as $liga) {
        $txt = editalTexto($pdo, $liga);
        if ($txt === null) continue;

        $nums = array_column(editalArtigos($txt), 'num');
        $rep  = array_keys(array_filter(array_count_values($nums), fn($n) => $n > 1));
        if ($rep) {
            sort($rep);
            /* Deixou de ser bloqueio: os dois artigos são regra de verdade, em
               capítulos diferentes, e apagar um perderia a regra. O guia e o
               bot passaram a mostrar TODAS as ocorrências, com o capítulo de
               cada uma — o que sobra é a citação por número ser ambígua, e
               isso só o PDF reeditado resolve. */
            $itens[] = [
                'grave' => false,
                'titulo' => count($rep) === 1
                    ? "Número de artigo repetido no edital da {$liga}"
                    : "Números de artigo repetidos no edital da {$liga}",
                'texto'  => (count($rep) === 1
                              ? 'O número ' . $rep[0] . ' aparece'
                              : 'Os números ' . implode(', ', $rep) . ' aparecem')
                          . ' duas vezes, em capítulos diferentes e com conteúdos diferentes — '
                          . 'as duas regras valem. Aqui e no bot as duas aparecem, cada uma com '
                          . 'o seu capítulo. O que continua ambíguo é citar de viva voz: '
                          . '"Art. ' . $rep[0] . ' da ' . $liga . '" pode ser qualquer uma das '
                          . 'duas, e só a renumeração do PDF resolve.',
            ];
        }

        $caps = array_column(editalCapitulos($txt), 'titulo');
        if (count($caps) !== count(array_unique($caps))) {
            $itens[] = [
                'grave' => false,
                'titulo' => "Capítulos repetidos no edital da {$liga}",
                'texto'  => 'Há mais de um capítulo com o mesmo número romano.',
            ];
        }
    }

    // 3. Tabela de moedas menor que a liga.
    foreach (EDITAL_LIGAS as $liga) {
        // Liga sem moeda não tem tabela pra cobrir ninguém: a da ELITE parar
        // no 30º não e problema a resolver, é artigo que deixou de valer.
        if (in_array($liga, EDITAL_LIGAS_SEM_MOEDA, true)) continue;

        $txt = editalTexto($pdo, $liga);
        if ($txt === null) continue;
        if (!preg_match('/(\d{1,2})º colocado recebe/u', $txt, $m)) continue;

        $maiorPosicao = (int)$m[1];
        $st = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE league = ?');
        $st->execute([$liga]);
        $times = (int)$st->fetchColumn();

        if ($times > $maiorPosicao) {
            $itens[] = [
                'grave' => true,
                'titulo' => "A tabela de moedas da {$liga} não cobre a liga inteira",
                'texto'  => "O edital lista até o {$maiorPosicao}º colocado, mas a {$liga} tem {$times} times. "
                          . 'Os últimos colocados ficam sem valor definido.',
            ];
        }
    }

    return $itens;
}

/**
 * COMO SE USA CADA TELA DO SITE.
 *
 * A única parte deste guia que não vem dos editais — porque não está lá. O
 * edital diz que a franquia tem um teto salarial; não diz em que página se
 * confere o espaço que sobrou, nem que o número muda quando a proposta é
 * aceita. Isso hoje só existe na cabeça de quem administra, e é metade das
 * perguntas que chegam no grupo.
 *
 * Escrito a partir do que as telas fazem de fato. Onde a regra por trás é do
 * edital, o assunto correspondente do guia responde — aqui é a operação.
 */
function editalPaginas(): array
{
    return [
        'O seu time' => [
            ['/my-roster.php', 'Meu elenco',
             'Os seus jogadores, com overall, idade, salário e skills. É onde se pede badge e se '
             . 'acompanha o que a organização já atendeu.'],
            ['/cap.php', 'Cap',
             'A folha do time e quanto sobra até o teto. O número já conta o que está pendente de '
             . 'aprovação — não é só o que já foi assinado.'],
            ['/tatica.php', 'Tática',
             'Estilo de jogo, playbook e rotação. O time guarda três táticas e você escolhe qual '
             . 'está valendo; a rotação vai de 8 a 15 jogadores.'],
            ['/picks.php', 'Picks',
             'As escolhas de draft que o time tem, por ano, com as condições (proteção e swap) que '
             . 'vierem junto.'],
        ],
        'Movimentação' => [
            ['/mercado.php', 'Mercado',
             'Onde a troca é montada e proposta. O cálculo do cap dos dois lados aparece antes de '
             . 'enviar, e a organização aprova depois.'],
            ['/free-agency.php', 'Free agency',
             'Contratar quem está sem time, pagando em moedas. A proposta vale como lance: aceita, '
             . 'o valor sai do saldo.'],
            ['/leilao.php', 'Leilão',
             'O jogador vai a lance e fica com quem oferecer mais. A oferta é vinculante.'],
            ['/dispensas.php', 'Dispensas',
             'Mandar jogador embora, dentro do limite da temporada.'],
        ],
        'A liga' => [
            ['/tabela.php', 'Tabela',
             'A classificação por conferência, temporada a temporada.'],
            ['/rankings.php', 'Ranking',
             'A pontuação acumulada do ciclo — é ela que decide o campeão da edição e, também, '
             . 'quantas moedas cada time recebe na virada.'],
            ['/drafts.php', 'Draft',
             'A ordem das escolhas e o mock. Deixe a sua lista montada: se a sua vez chegar e você '
             . 'tiver lista, o sistema escolhe na hora por você.'],
            ['/lottery.php', 'Loteria',
             'O sorteio da ordem do draft e as chances de cada time.'],
            ['/history.php', 'História',
             'Campeões, prêmios e o que já aconteceu nas temporadas anteriores.'],
        ],
        'Fora de quadra' => [
            ['/games.php', 'Games',
             'Os jogos da FBA, a loja, o ranking dos jogos e os eventos. As moedas dos games são '
             . 'outras, separadas das moedas de free agency.'],
            ['/games.php?aba=eventos', 'Eventos',
             'Qualquer um cria um evento e banca as apostas dele. Quem cria declara o resultado; a '
             . 'organização só entra se houver problema.'],
            ['/ouvidoria.php', 'Ouvidoria',
             'O canal formal pra reclamação e pedido de revisão.'],
            ['/observador.php', 'Observador',
             'Ver outra liga por dentro, sem poder mexer em nada.'],
        ],
    ];
}

/**
 * O QUE É REGRA COMUM E O QUE É DE UMA LIGA SÓ.
 *
 * Medido comparando artigo a artigo, ignorando o nome da liga no texto: os
 * editais são quase o mesmo documento — 85% entre ELITE e NEXT, ~70% contra a
 * ROOKIE. O que sobra é pouco e quase todo do mesmo tipo: o rol de GMs, as
 * classes de draft da edição, e um punhado de regras próprias.
 *
 * É esta conta que diz o tamanho do documento único: quase tudo entra uma vez
 * só, e as poucas exceções viram "na ELITE, ...".
 *
 * CUSTA CARO. É O(n²) sobre 259 artigos, alguns segundos — por isso a página
 * só chama quando pedida, e não a cada carga.
 *
 * @return array{ligas:array<string,array{total:int,exclusivos:list<array>}>,semelhanca:array}
 */
function editalComparacao(PDO $pdo): array
{
    $normalizar = function (string $s): string {
        $s = mb_strtolower($s, 'UTF-8');
        // O nome da liga sai: "a FBA Elite exige" e "a FBA Next exige" são a
        // mesma regra, e mantê-los faria todo artigo parecer exclusivo.
        $s = preg_replace('/\b(elite|next|rise|rookie)\b/u', '', $s);
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = preg_replace('/[^a-z0-9 ]/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    };

    $arts = [];
    foreach (EDITAL_LIGAS as $L) {
        $t = editalTexto($pdo, $L);
        if ($t === null) continue;
        foreach (editalArtigos($t) as $a) {
            $arts[$L][] = ['num' => $a['num'], 'capitulo' => $a['capitulo'],
                           'texto' => $a['texto'], 'chave' => $normalizar($a['texto'])];
        }
    }

    // Corta em 600 caracteres: similar_text é quadrático no tamanho do texto,
    // e o começo do artigo já separa um do outro.
    $maisParecido = function (string $x, array $lista): float {
        $melhor = 0.0;
        foreach ($lista as $y) {
            similar_text(mb_substr($x, 0, 600), mb_substr($y['chave'], 0, 600), $p);
            if ($p > $melhor) $melhor = $p;
            if ($melhor >= 99) break;
        }
        return $melhor;
    };

    $out = ['ligas' => [], 'semelhanca' => []];
    foreach ($arts as $L => $lista) {
        $outras = [];
        foreach ($arts as $O => $l2) if ($O !== $L) $outras = array_merge($outras, $l2);

        $exclusivos = [];
        foreach ($lista as $a) {
            // 70 é onde "mesma regra escrita diferente" ainda casa e "regra
            // diferente" já não casa — conferido nos três editais.
            if ($maisParecido($a['chave'], $outras) < 70) $exclusivos[] = $a;
        }
        $out['ligas'][$L] = ['total' => count($lista), 'exclusivos' => $exclusivos];
    }

    foreach ($arts as $A => $la) {
        foreach ($arts as $B => $lb) {
            if ($A === $B) continue;
            $iguais = 0;
            foreach ($la as $a) if ($maisParecido($a['chave'], $lb) >= 95) $iguais++;
            $out['semelhanca'][$A][$B] = $la ? (int)round($iguais / count($la) * 100) : 0;
        }
    }
    return $out;
}
