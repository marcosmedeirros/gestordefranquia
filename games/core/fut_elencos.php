<?php
/**
 * OS ELENCOS DO JOGO — os reais e os genéricos.
 *
 * Os clubes grandes vão ter elenco de verdade (a lista que o dono do jogo está
 * montando). Os outros — o Trem, o Velo Clube, os 60 e poucos que só existem
 * pra o estadual acontecer — ganham elenco gerado aqui.
 *
 * ── A REGRA QUE SEGURA TUDO: O MESMO CLUBE DÁ SEMPRE O MESMO ELENCO ──
 *
 * Um elenco sorteado na hora de mostrar a tela seria um desastre silencioso: o
 * técnico escala o camisa 9, atualiza a página e o camisa 9 não existe mais; o
 * artilheiro do Paranaense troca de nome entre uma rodada e outra. Por isso a
 * geração é SEMEADA PELO NOME DO CLUBE (crc32) — mesma entrada, mesma saída,
 * hoje e daqui a seis meses, sem precisar gravar nada no banco.
 *
 * Isso também quer dizer que dá pra trocar um elenco genérico por um real
 * depois, sem migração: basta o clube passar a ter lista própria, e a função
 * para de gerar pra ele.
 *
 * ── E QUANDO O ELENCO É REAL ──
 *
 * futElencoDoClube() procura primeiro o arquivo de elenco real; só cai na
 * geração se não achar. Assim a lista que chegar entra sem mexer em nada.
 *
 * Jogador genérico é personagem fictício. Coincidência com gente de verdade é
 * do acaso — não há nome real nesta geração, e nenhum dado de pessoa real.
 */

require_once __DIR__ . '/fut_clubes_br.php';

/**
 * As posições: quantos titulares e quantos no total.
 *
 * OS TITULARES SÃO A PARTE QUE IMPORTA. A primeira versão disto era só o total
 * por posição, e o elenco saía preenchido na ordem em que as posições estavam
 * escritas — o que dava a todo clube do jogo três goleiros ótimos e um ataque
 * sofrível, porque goleiro vinha primeiro na lista e atacante por último. Com a
 * cota de titular explícita, o melhor de cada posição disputa a mesma faixa de
 * overall, e o ataque deixa de ser o refugo do elenco.
 *
 * Os 11 titulares formam um 4-2-3-1 folgado, que é o desenho mais comum hoje e
 * cobre bem tanto o 4-4-2 quanto o 4-3-3 na hora de escalar.
 */
const FUT_POSICOES = [
    'GOL' => ['titulares' => 1, 'total' => 3],
    'LAT' => ['titulares' => 2, 'total' => 4],
    'ZAG' => ['titulares' => 2, 'total' => 4],
    'VOL' => ['titulares' => 2, 'total' => 4],
    'MEI' => ['titulares' => 2, 'total' => 4],
    'PON' => ['titulares' => 1, 'total' => 3],
    'ATA' => ['titulares' => 1, 'total' => 3],
];   // 11 titulares, 25 no elenco

/**
 * O material dos nomes.
 *
 * Três formas convivem porque é assim que a lista de um clube brasileiro se
 * parece: nome e sobrenome (Rafael Moreira), nome só (Gustavo) e apelido
 * (Juninho, Cacá). Sortear sempre "nome + sobrenome" daria uma tabela que soa
 * estrangeira sem que ninguém saiba dizer por quê.
 */
const FUT_NOMES = [
    'Alan','Alef','André','Bruno','Caio','Carlos','Cauã','Danilo','Davi','Denis',
    'Diego','Douglas','Eduardo','Emerson','Everton','Fabrício','Felipe','Fernando',
    'Gabriel','Guilherme','Gustavo','Heitor','Henrique','Igor','Ítalo','Jean',
    'João','Jonas','Kauã','Leandro','Lucas','Luiz','Marcelo','Mateus','Matheus',
    'Murilo','Nathan','Otávio','Pablo','Patrick','Paulo','Pedro','Rafael','Renan',
    'Ricardo','Roberto','Rodrigo','Samuel','Thiago','Vinícius','Wesley','Yuri',
];

const FUT_SOBRENOMES = [
    'Almeida','Alves','Barbosa','Barros','Bastos','Batista','Bernardo','Braga',
    'Camargo','Cardoso','Carvalho','Castro','Correia','Costa','Cunha','Dias',
    'Duarte','Farias','Fernandes','Ferreira','Fonseca','Freitas','Gomes','Gonçalves',
    'Guedes','Lima','Lopes','Machado','Macedo','Martins','Medeiros','Melo','Mendes',
    'Moraes','Moreira','Nascimento','Neves','Nogueira','Oliveira','Pacheco','Peixoto',
    'Pereira','Pinheiro','Pires','Ramos','Rocha','Rodrigues','Sales','Santana',
    'Santos','Silva','Siqueira','Soares','Souza','Teixeira','Vieira','Xavier',
];

const FUT_APELIDOS = [
    'Biel','Binho','Cacá','Caco','Cafu','Dedé','Didi','Dinho','Douglinhas','Fabinho',
    'Gabigol','Gegê','Guga','Jajá','Juninho','Kaká','Léo','Lelê','Lipe','Luizão',
    'Marquinhos','Matheuzinho','Nenê','Neto','Nino','Paulinho','Pedrinho','Rafinha',
    'Ramon','Ronaldinho','Serginho','Tatá','Tiquinho','Vitinho','Wendel','Zé',
];

/**
 * Um sorteio que DEPENDE SÓ DA SEMENTE, e não do estado global do PHP.
 *
 * Usar mt_rand() com mt_srand() estragaria o sorteio de todo o resto do jogo —
 * semear o gerador global pra montar um elenco faria as partidas daquela
 * requisição saírem todas iguais. Este aqui carrega o próprio estado.
 */
function futSorteio(int &$semente, int $min, int $max): int
{
    // Xorshift de 32 bits: barato, sem estado global, e bom o bastante pra nome
    // e idade de jogador genérico — não é criptografia nem sorteio de loteria.
    $semente ^= ($semente << 13) & 0xFFFFFFFF;
    $semente ^= ($semente >> 17);
    $semente ^= ($semente << 5) & 0xFFFFFFFF;
    $semente &= 0xFFFFFFFF;
    return $min + ($semente % max(1, $max - $min + 1));
}

/** Um nome que ainda não saiu neste elenco. */
function futNomeGenerico(int &$semente, array &$usados): string
{
    for ($tentativa = 0; $tentativa < 40; $tentativa++) {
        $forma = futSorteio($semente, 1, 10);
        if ($forma <= 5) {          // metade: nome + sobrenome
            $nome = FUT_NOMES[futSorteio($semente, 0, count(FUT_NOMES) - 1)]
                  . ' ' . FUT_SOBRENOMES[futSorteio($semente, 0, count(FUT_SOBRENOMES) - 1)];
        } elseif ($forma <= 8) {    // três décimos: apelido
            $nome = FUT_APELIDOS[futSorteio($semente, 0, count(FUT_APELIDOS) - 1)];
        } else {                    // o resto: nome sozinho
            $nome = FUT_NOMES[futSorteio($semente, 0, count(FUT_NOMES) - 1)];
        }
        if (!isset($usados[$nome])) { $usados[$nome] = true; return $nome; }
    }
    // Estourou as tentativas (elenco cheio de colisão): desempata com numeral.
    $n = count($usados) + 1;
    $nome = FUT_NOMES[futSorteio($semente, 0, count(FUT_NOMES) - 1)] . ' ' . $n;
    $usados[$nome] = true;
    return $nome;
}

/**
 * O overall de um jogador, dado a força do clube e o posto dele no elenco.
 *
 * O ELENCO TEM HIERARQUIA, e não uma nuvem de notas em volta da média: os onze
 * titulares ficam perto da força do clube, os reservas caem, e um ou dois
 * garotos ficam bem abaixo. É isso que faz perder um titular doer e faz o
 * banco parecer banco.
 */
function futOverallDoJogador(int $forcaClube, int $posto, int &$semente): int
{
    /* O degrau por posto: titular perde pouco, o fim do elenco perde muito.
       Não é linear de propósito — do 1º pro 11º a queda é suave, do 11º pro
       25º ela acelera. */
    $degrau = $posto <= 11 ? $posto * 0.4 : 4.4 + ($posto - 11) * 1.1;

    $base = $forcaClube - $degrau;
    $ruido = futSorteio($semente, -4, 4);   // ninguém é exatamente a média
    return (int)max(25, min(99, round($base + $ruido)));
}

/**
 * A idade. Elenco de verdade tem pirâmide: muito jogador entre 23 e 29, alguns
 * garotos e um ou dois veteranos.
 */
function futIdadeDoJogador(int &$semente): int
{
    $d = futSorteio($semente, 1, 100);
    if ($d <= 15) return futSorteio($semente, 17, 21);   // a base
    if ($d <= 75) return futSorteio($semente, 22, 29);   // o miolo
    if ($d <= 93) return futSorteio($semente, 30, 34);   // a experiência
    return futSorteio($semente, 35, 39);                 // o veterano
}

/** Embaralha uma lista sem tocar no sorteio global (Fisher-Yates semeado). */
function futEmbaralhar(array $lista, int &$semente): array
{
    for ($i = count($lista) - 1; $i > 0; $i--) {
        $j = futSorteio($semente, 0, $i);
        [$lista[$i], $lista[$j]] = [$lista[$j], $lista[$i]];
    }
    return $lista;
}

/**
 * O elenco genérico de um clube — 25 jogadores, sempre os mesmos.
 *
 * O POSTO NÃO SEGUE A ORDEM DAS POSIÇÕES. Os 11 postos de titular são
 * embaralhados e repartidos entre as cotas de cada posição, e os 14 de reserva
 * idem. Se fossem distribuídos na ordem em que as posições estão escritas, o
 * goleiro levaria sempre o posto 1 e o atacante sempre o 25 — que foi
 * exatamente o defeito da primeira versão.
 *
 * @return array lista de ['nome','pos','ovr','idade','num']
 */
function futElencoGenerico(string $nomeClube, int $forcaClube): array
{
    $semente = crc32($nomeClube) & 0xFFFFFFFF;
    if ($semente === 0) $semente = 1;   // xorshift travaria em zero pra sempre

    $titulares = futEmbaralhar(range(1, 11), $semente);
    $reservas  = futEmbaralhar(range(12, 25), $semente);

    $usados = [];
    $elenco = [];

    foreach (FUT_POSICOES as $pos => $cota) {
        for ($i = 0; $i < $cota['total']; $i++) {
            $posto = $i < $cota['titulares'] ? array_pop($titulares) : array_pop($reservas);
            $elenco[] = [
                'nome'  => futNomeGenerico($semente, $usados),
                'pos'   => $pos,
                'ovr'   => futOverallDoJogador($forcaClube, (int)$posto, $semente),
                'idade' => futIdadeDoJogador($semente),
                'num'   => 0,   // preenchido abaixo, depois de ordenar
            ];
        }
    }

    /* A numeração sai NO FIM porque ela depende do overall: o melhor goleiro é
       o 1, o melhor atacante é o 9. Numerar antes daria um camisa 10 reserva. */
    $ordem = array_keys(FUT_POSICOES);
    usort($elenco, function ($a, $b) use ($ordem) {
        return [array_search($a['pos'], $ordem, true), -$a['ovr']]
           <=> [array_search($b['pos'], $ordem, true), -$b['ovr']];
    });

    $numero = 1;
    foreach ($elenco as &$j) $j['num'] = $numero++;
    unset($j);

    /* ── O ACERTO FINAL: a força do elenco TEM que bater com a do catálogo ──
       O sorteio deixa a média dos titulares a alguns pontos do alvo, e com
       elenco determinístico esse desvio é PERMANENTE — não é ruído que some na
       média de muitos jogos, é um clube que vale 88 pra sempre porque o
       catálogo diz 92. Rodando 300 temporadas assim, Flamengo e Palmeiras,
       ambos 92, ganhavam 33% e 18% dos Brasileirões.
       Deslocar o elenco inteiro pelo delta corrige o nível sem achatar a
       hierarquia interna: o craque continua craque, o banco continua banco. */
    $delta = $forcaClube - futForcaDoElenco($elenco);
    if ($delta !== 0) {
        foreach ($elenco as &$j) $j['ovr'] = (int)max(25, min(99, $j['ovr'] + $delta));
        unset($j);
    }

    return $elenco;
}
/**
 * O elenco de um clube: o real se existir, o genérico se não.
 *
 * O arquivo real vive em games/data/elencos/<clube>.php e devolve a mesma
 * lista. Enquanto ele não existir pra um clube, o genérico cobre — o jogo nunca
 * fica com um clube sem time.
 */
function futElencoDoClube(string $nomeClube, int $forcaClube): array
{
    $slug = futSlugDoClube($nomeClube);
    $real = __DIR__ . '/../data/elencos/' . $slug . '.php';
    if (is_file($real)) {
        $lista = require $real;
        if (is_array($lista) && $lista !== []) return $lista;
    }
    return futElencoGenerico($nomeClube, $forcaClube);
}

/** O nome do clube virado nome de arquivo: "Atlético-MG" => "atletico-mg". */
function futSlugDoClube(string $nome): string
{
    $s = strtr($nome, [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','Á'=>'a','À'=>'a','Â'=>'a','Ã'=>'a',
        'é'=>'e','ê'=>'e','É'=>'e','Ê'=>'e','í'=>'i','Í'=>'i',
        'ó'=>'o','ô'=>'o','õ'=>'o','Ó'=>'o','Ô'=>'o','Õ'=>'o',
        'ú'=>'u','Ú'=>'u','ç'=>'c','Ç'=>'c',
    ]);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

/**
 * A força de jogo de um time a partir do elenco — a média dos 11 melhores que
 * formam uma escalação possível.
 *
 * É ISSO QUE LIGA O ELENCO AO MOTOR: sem esta função o elenco seria enfeite, e
 * vender o artilheiro não mudaria nenhum resultado. Com ela, o time que perde
 * os titulares fica pior na tabela, que é o ponto do jogo inteiro.
 *
 * Ela respeita a cota de titular de cada posição em vez de pegar os 11 maiores
 * overalls — um elenco com quatro goleiros excelentes não pode escalar os
 * quatro, e a média dos "11 melhores" crus mandaria três goleiros a campo.
 */
function futForcaDoElenco(array $elenco): int
{
    if ($elenco === []) return 40;

    $por = [];
    foreach ($elenco as $j) $por[$j['pos']][] = (int)$j['ovr'];
    foreach ($por as &$lista) rsort($lista);
    unset($lista);

    $escalados = [];
    $sobra = [];
    foreach (FUT_POSICOES as $pos => $cota) {
        $disponiveis = $por[$pos] ?? [];
        // Até a cota de titular entram; o excedente vira banco — menos o do
        // goleiro, que não tem como jogar na linha.
        $escalados = array_merge($escalados, array_slice($disponiveis, 0, $cota['titulares']));
        if ($pos !== 'GOL') {
            $sobra = array_merge($sobra, array_slice($disponiveis, $cota['titulares']));
        }
    }

    // Elenco incompleto (lista real curta, jogador vendido): completa com o
    // melhor do banco em vez de devolver uma média de menos de 11.
    rsort($sobra);
    $escalados = array_merge($escalados, array_slice($sobra, 0, max(0, 11 - count($escalados))));

    return (int)round(array_sum($escalados) / max(1, count($escalados)));
}
