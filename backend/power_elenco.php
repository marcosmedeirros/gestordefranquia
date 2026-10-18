<?php
/**
 * ── QUANTO VALE UM JOGADOR, ALÉM DO OVR ──────────────────────────────
 *
 * Pedido do Marcos (18/10/2026): "os jogadores devem ser avaliados além do
 * ovr o seu desempenho e letras, se o desempenho vai bem, se as letras pra
 * posição são boas, se o time é coerente".
 *
 * O OVR é um número só e esconde três coisas diferentes:
 *
 *   • um pivô 85 com rebote C- é pior pivô que um 83 com rebote A;
 *   • um 88 que fez 9 pontos por jogo rendeu menos que um 84 que fez 22;
 *   • cinco craques que não se encaixam perdem pra cinco bons que se
 *     encaixam.
 *
 * Então a nota de um jogador aqui é o OVR com DUAS CORREÇÕES — as letras na
 * posição dele e o que ele produziu em quadra — e o time ganha uma terceira
 * medida, a coerência do conjunto.
 *
 * ── A REGRA QUE GOVERNA TUDO: DADO QUE FALTA NÃO PUNE ────────────────
 *
 * Letras em branco e temporada sem estatística são comuns — na NEXT e na
 * RISE só um quarto da rotação tem ficha de jogo lançada. Se ausência
 * virasse zero, o power ranking mediria quem preencheu cadastro, não quem
 * tem time. Então cada sinal que falta simplesmente SAI DA MÉDIA, e os
 * pesos se redistribuem entre os que sobraram: quem só tem OVR é julgado
 * pelo OVR, sem bônus nem castigo.
 *
 * @see api/whatsapp-comandos.php  wcForcaDoTime, wcComponentesDoPower
 * @see backend/sistema_proficiencia.php  as letras e a escala delas
 */

require_once __DIR__ . '/sistema_proficiencia.php';

/**
 * O QUE SE COBRA DE CADA POSIÇÃO, em peso sobre as skills do elenco.
 *
 * Não é opinião solta: é a mesma lógica que o ajuste de posição do módulo de
 * sistemas já aplica — de um armador não se cobra rebote, de um pivô não se
 * cobra bola de três. A diferença é que lá a pergunta é "ele rende neste
 * ESTILO?" e aqui é "ele é bom NA FUNÇÃO DELE?".
 *
 * Os pesos somam 100 em cada posição pra que as cinco notas sejam
 * comparáveis entre si — um PG 80 e um C 80 querem dizer a mesma coisa.
 *
 * `skill_pot` (potencial) FICA DE FORA de propósito: ele diz o que o jogador
 * pode vir a ser, e power ranking é sobre agora. Entra na conversa de
 * projeção, não nesta.
 */
const PE_PERFIL_POSICAO = [
    'PG' => ['skill_play' => 30, 'skill_iq' => 20, 'skill_3pt' => 15,
             'skill_per_d' => 12, 'skill_mid' => 12, 'skill_athl' => 11],
    'SG' => ['skill_3pt' => 28, 'skill_mid' => 20, 'skill_per_d' => 16,
             'skill_athl' => 14, 'skill_play' => 12, 'skill_iq' => 10],
    'SF' => ['skill_mid' => 20, 'skill_3pt' => 20, 'skill_per_d' => 18,
             'skill_athl' => 16, 'skill_iq' => 13, 'skill_reb' => 13],
    'PF' => ['skill_in' => 22, 'skill_reb' => 22, 'skill_post_d' => 18,
             'skill_athl' => 14, 'skill_mid' => 12, 'skill_iq' => 12],
    'C'  => ['skill_in' => 24, 'skill_reb' => 24, 'skill_post_d' => 24,
             'skill_athl' => 14, 'skill_iq' => 14],
];

/**
 * As letras dele valem quanto NA POSIÇÃO DELE? 0 a 100, ou null sem ficha.
 *
 * Usa a posição principal; quem não tem posição conhecida cai no perfil de
 * ala, que é o do meio e o que menos erra.
 */
function peNotaDasLetras(array $p): ?float
{
    $pos = strtoupper(trim((string)($p['position'] ?? '')));
    $perfil = PE_PERFIL_POSICAO[$pos] ?? PE_PERFIL_POSICAO['SF'];

    $soma = 0.0; $pesos = 0.0;
    foreach ($perfil as $col => $peso) {
        $n = sistemaNotaDaLetra($p[$col] ?? null);
        if ($n === null) continue;          // atributo ausente não pesa
        $soma  += $n * $peso;
        $pesos += $peso;
    }
    /* Menos da metade do perfil preenchido não é ficha, é rascunho: com duas
       letras de dez dá pra fazer qualquer jogador parecer o que se quiser. */
    if ($pesos < 50) return null;
    return max(0.0, min(100.0, $soma / $pesos));
}

/**
 * ── TUDO VIRA PERCENTIL, E ESSA É A PARTE QUE IMPORTA ────────────────
 *
 * A primeira versão misturava os três sinais crus, e eles NÃO ESTÃO NA
 * MESMA ESCALA. Medido na ELITE: o jogador mediano tem OVR 82 — que na
 * régua de 60 a 99 dá 56 — e letras 76. Somar os dois empurrava todo mundo
 * uns vinte pontos pra cima, e o efeito aparecia no fim da tabela: os três
 * piores times da liga saíam com "astros 100", porque o corte de 85+ tinha
 * sido calibrado pra OVR de verdade e o número inflado passava por cima
 * dele.
 *
 * Posição na lista resolve: o mediano fica em 50 nos três, custe o que
 * custar o formato de cada um. E é o jeito certo de comparar coisas de
 * naturezas diferentes — ninguém soma letra com ponto por jogo, mas dá pra
 * somar "melhor que 70% da liga" com "melhor que 70% da liga".
 *
 * Não é regra de três entre o pior e o melhor, de propósito: um jogador
 * absurdo esticaria a escala e espremeria todo mundo no pé dela.
 */
function pePercentil(?float $valor, array $lista): ?float
{
    if ($valor === null || count($lista) < 5) return null;
    $abaixo = 0;
    foreach ($lista as $x) if ($x < $valor) $abaixo++;
    return max(0.0, min(100.0, ($abaixo / count($lista)) * 100));
}

/**
 * Quanto ele PRODUZIU na última temporada fechada, em percentil da liga.
 *
 * JOGO POUCO NÃO É CAMPANHA. Quem entrou em menos de um terço dos jogos
 * tem amostra pequena demais pra virar nota — três partidas boas não fazem
 * temporada —, e aí o sinal some em vez de mentir.
 */
function peNotaDoDesempenho(array $p, array $ctx): ?float
{
    $jogos = (int)($p['pe_games'] ?? 0);
    if ($jogos <= 0 || $jogos < (int)($ctx['jogosMinimos'] ?? 0)) return null;
    return pePercentil(peProducao($p), $ctx['producoes'] ?? []);
}

/**
 * A produção por jogo em um número.
 *
 * Os pesos são os de sempre em basquete: assistência e roubo valem mais que
 * ponto porque são mais raros, e toco vale como roubo. Não é fórmula de
 * eficiência — é volume ponderado, que é o que a liga tem lançado.
 */
function peProducao(array $p): ?float
{
    $tem = false;
    $v = function (string $k) use ($p, &$tem): float {
        if (!isset($p[$k]) || $p[$k] === null || $p[$k] === '') return 0.0;
        $tem = true;
        return (float)$p[$k];
    };
    $prod = $v('pe_pts') + 1.2 * $v('pe_reb') + 1.5 * $v('pe_ast')
          + 2.0 * $v('pe_stl') + 2.0 * $v('pe_blk');
    return $tem ? $prod : null;
}

/**
 * A NOTA DO JOGADOR: o OVR corrigido pelas letras e pelo que ele produziu.
 *
 * O OVR manda porque é o número que a liga inteira usa e no qual confia; as
 * outras duas corrigem a margem. Um pivô 85 de rebote C- não vira 70 — vira
 * 81, que é o tamanho do problema.
 *
 * @return float 0 a 100
 */
function peNotaDoJogador(array $p, array $ctx): float
{
    $ovr = (float)($p['ovr'] ?? 0);
    /* Sem a lista da liga (contexto vazio), o OVR cai na régua fixa de 60 a
       99. Não é tão bom quanto o percentil, mas é o que dá pra fazer com um
       elenco só na mão — e continua respondendo na mesma direção. */
    $pOvr = pePercentil($ovr, $ctx['ovrs'] ?? [])
         ?? max(0.0, min(100.0, ($ovr - 60) / 39 * 100));
    $sinais = [[$pOvr, 62.0]];

    $letras = peNotaDasLetras($p);
    if ($letras !== null) {
        $pLetras = pePercentil($letras, $ctx['letras'] ?? []);
        if ($pLetras !== null) $sinais[] = [$pLetras, 23.0];
    }

    $desemp = peNotaDoDesempenho($p, $ctx);
    if ($desemp !== null) $sinais[] = [$desemp, 15.0];

    $soma = 0.0; $pesos = 0.0;
    foreach ($sinais as [$nota, $peso]) { $soma += $nota * $peso; $pesos += $peso; }
    return $pesos > 0 ? $soma / $pesos : 0.0;
}

/**
 * O percentil de volta em OVR, pra conta de fora continuar lendo OVR.
 *
 * O resto do power ranking fala em OVR — "teto 94", "três de 85+" — e esses
 * cortes só querem dizer alguma coisa na escala que a liga usa. Então a nota
 * de 0 a 100 volta pro intervalo REAL de OVR da liga: o mediano sai no OVR
 * mediano, e o corte de 85+ continua separando o que separava antes.
 */
function peEquivalenteOvr(float $nota, array $ctx): float
{
    $ovrs = $ctx['ovrs'] ?? [];
    if (count($ovrs) < 5) return 60 + ($nota / 100) * 39;
    $min = (float)$ovrs[0];
    $max = (float)$ovrs[count($ovrs) - 1];
    if ($max <= $min) return $min;
    return $min + ($nota / 100) * ($max - $min);
}

/**
 * O CONTEXTO DA LIGA, montado uma vez e passado adiante.
 *
 * As produções de toda a rotação da liga entram aqui pra que o percentil de
 * cada jogador saia sem uma volta ao banco por pessoa.
 */
function peContextoDaLiga(array $elencos): array
{
    $producoes = []; $ovrs = []; $letras = [];
    $jogosMax = 0;
    foreach ($elencos as $elenco) {
        foreach ($elenco as $p) {
            $jogosMax = max($jogosMax, (int)($p['pe_games'] ?? 0));
        }
    }
    /* O corte de amostra sai do PRÓPRIO calendário da liga: um terço dos
       jogos de quem mais jogou. Fixar em "20 jogos" quebraria numa liga de
       temporada curta. */
    $minimo = (int)floor($jogosMax / 3);

    foreach ($elencos as $elenco) {
        foreach ($elenco as $p) {
            /* SÓ A ROTAÇÃO entra na régua. Com o elenco inteiro, o 15º homem
               de cada time empurraria a mediana pra baixo e todo titular
               pareceria acima da média — a comparação que interessa é entre
               quem joga. Quando o `role` não veio na consulta, entra todo
               mundo, que é o comportamento de quem só tem um elenco na mão. */
            $r = trim((string)($p['role'] ?? ''));
            if ($r !== '' && $r !== 'Titular' && $r !== 'Banco') continue;

            $ovr = (int)($p['ovr'] ?? 0);
            if ($ovr > 0) $ovrs[] = $ovr;

            $l = peNotaDasLetras($p);
            if ($l !== null) $letras[] = $l;

            if ((int)($p['pe_games'] ?? 0) < max(1, $minimo)) continue;
            $prod = peProducao($p);
            if ($prod !== null) $producoes[] = $prod;
        }
    }
    sort($producoes); sort($ovrs); sort($letras);
    return ['producoes' => $producoes, 'ovrs' => $ovrs, 'letras' => $letras,
            'jogosMinimos' => max(1, $minimo)];
}

/**
 * O mesmo contexto, montado direto do banco a partir do nome da liga.
 *
 * Existe pro /confronto: lá só dois elencos estão na mão, e um percentil
 * tirado de trinta jogadores diria outra coisa que o /power — os dois
 * comandos têm que dar a MESMA força pro mesmo time, e isso só vale se a
 * régua for a mesma liga nos dois.
 */
function peContextoPorLiga(PDO $pdo, string $liga): array
{
    static $cache = [];
    $liga = strtoupper(trim($liga));
    if (isset($cache[$liga])) return $cache[$liga];

    try {
        /* OVR e letras vêm junto: as três réguas do percentil saem da mesma
           rotação, e uma lista pela metade daria percentil de mentira. */
        $st = $pdo->prepare("
            SELECT p.ovr, p.position, p.role, " . sistemaColunasSql('p') . ",
                   ps.games AS pe_games, ps.pts_pg AS pe_pts, ps.reb_pg AS pe_reb,
                   ps.ast_pg AS pe_ast, ps.stl_pg AS pe_stl, ps.blk_pg AS pe_blk
              FROM players p
              JOIN teams t ON t.id = p.team_id
         LEFT JOIN player_season_stats ps
                ON ps.player_id = p.id AND ps.season_id = (
                     SELECT s.id FROM seasons s
                       JOIN sprints sp ON sp.id = s.sprint_id AND sp.status = 'active'
                      WHERE s.league = t.league AND s.status = 'completed'
                   ORDER BY s.start_date DESC LIMIT 1)
             WHERE t.league = ? AND p.role IN ('Titular','Banco')");
        $st->execute([$liga]);
        $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[power_elenco] contexto da liga: ' . $e->getMessage());
        $linhas = [];
    }

    return $cache[$liga] = peContextoDaLiga([$linhas]);
}

/**
 * ── O TIME É COERENTE? ───────────────────────────────────────────────
 *
 * Duas perguntas, porque são dois jeitos de um quinteto não fazer sentido.
 *
 * 1. ELES JOGAM O MESMO JOGO? A nota do melhor sistema do time responde
 *    isso: cinco que puxam pro mesmo estilo chegam a 90, cinco craques sem
 *    nada em comum ficam em 70. @see sistemaPerfilDoTime
 *
 * 2. ELES COBREM A QUADRA? Três alas e nenhum armador é um problema que
 *    nenhuma média de OVR mostra. Conta a posição principal e a secundária,
 *    porque jogador de duas posições resolve buraco de verdade.
 *
 * @return array{nota: float, txt: string}
 */
function peCoerencia(array $cinco): array
{
    $cinco = array_values(array_filter($cinco));
    if (!$cinco) return ['nota' => 50.0, 'txt' => 'sem quinteto'];

    /* O ENCAIXE. Sem ninguém com ficha técnica a pergunta fica sem resposta,
       e sem resposta vale a média — não zero. */
    $perfil = sistemaPerfilDoTime($cinco);
    $melhor = null;
    foreach ($perfil['sistemas'] as $s) {
        if ($s['nota'] !== null) { $melhor = $s; break; }
    }
    $encaixe = $melhor ? max(0.0, min(100.0, (float)$melhor['nota'])) : 50.0;

    /* A COBERTURA. */
    $vagas = ['PG' => false, 'SG' => false, 'SF' => false, 'PF' => false, 'C' => false];
    foreach ($cinco as $p) {
        foreach ([$p['position'] ?? '', $p['secondary_position'] ?? ''] as $pos) {
            $pos = strtoupper(trim((string)$pos));
            if (isset($vagas[$pos])) $vagas[$pos] = true;
        }
    }
    $cobertas = count(array_filter($vagas));
    $cobertura = ($cobertas / 5) * 100;

    $faltam = array_keys(array_filter($vagas, fn($v) => !$v));
    $txt = $melhor ? mb_strtolower((string)$melhor['nome']) : 'sem ficha técnica';
    $txt .= $faltam ? ', sem ' . implode('/', $faltam) : ', quadra coberta';

    return ['nota' => round($encaixe * 0.65 + $cobertura * 0.35, 1), 'txt' => $txt];
}
