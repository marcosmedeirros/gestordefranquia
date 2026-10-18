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
 * Quanto ele PRODUZIU na última temporada fechada. 0 a 100, ou null.
 *
 * A produção por jogo vira um número só, e esse número vira posição na
 * liga. A escala é relativa de propósito: 18 pontos por jogo é campanha
 * excelente numa liga e média em outra, e o power ranking compara times da
 * mesma liga entre si.
 *
 * JOGO POUCO NÃO É CAMPANHA. Quem entrou em menos de um terço dos jogos
 * tem amostra pequena demais pra virar nota — três partidas boas não fazem
 * temporada —, e aí o sinal some em vez de mentir.
 */
function peNotaDoDesempenho(array $p, array $ctx): ?float
{
    $jogos = (int)($p['pe_games'] ?? 0);
    if ($jogos <= 0 || $jogos < (int)($ctx['jogosMinimos'] ?? 0)) return null;

    $prod = peProducao($p);
    if ($prod === null) return null;

    $lista = $ctx['producoes'] ?? [];
    if (count($lista) < 5) return null;     // liga sem amostra pra comparar

    /* POSIÇÃO NA LISTA, e não regra de três entre o pior e o melhor: um
       único jogador absurdo esticaria a escala e espremeria todo mundo no
       pé dela. O percentil não se importa com o tamanho do maior. */
    $abaixo = 0;
    foreach ($lista as $x) if ($x < $prod) $abaixo++;
    return max(0.0, min(100.0, ($abaixo / count($lista)) * 100));
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
    /* O OVR na escala de 0 a 100. O piso é 60 e não 0: ninguém na liga joga
       abaixo disso, e esticar a régua até zero faria a diferença entre um 78
       e um 92 — que é enorme em quadra — virar meio palmo de barra. */
    $ovr = (int)($p['ovr'] ?? 0);
    $sinais = ['ovr' => [max(0.0, min(100.0, ($ovr - 60) / 39 * 100)), 62.0]];

    $letras = peNotaDasLetras($p);
    if ($letras !== null) $sinais['letras'] = [$letras, 23.0];

    $desemp = peNotaDoDesempenho($p, $ctx);
    if ($desemp !== null) $sinais['desempenho'] = [$desemp, 15.0];

    $soma = 0.0; $pesos = 0.0;
    foreach ($sinais as [$nota, $peso]) { $soma += $nota * $peso; $pesos += $peso; }
    return $pesos > 0 ? $soma / $pesos : 0.0;
}

/**
 * O CONTEXTO DA LIGA, montado uma vez e passado adiante.
 *
 * As produções de toda a rotação da liga entram aqui pra que o percentil de
 * cada jogador saia sem uma volta ao banco por pessoa.
 */
function peContextoDaLiga(array $elencos): array
{
    $producoes = [];
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
            if ((int)($p['pe_games'] ?? 0) < max(1, $minimo)) continue;
            $prod = peProducao($p);
            if ($prod !== null) $producoes[] = $prod;
        }
    }
    sort($producoes);
    return ['producoes' => $producoes, 'jogosMinimos' => max(1, $minimo)];
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
        $st = $pdo->prepare("
            SELECT ps.games AS pe_games, ps.pts_pg AS pe_pts, ps.reb_pg AS pe_reb,
                   ps.ast_pg AS pe_ast, ps.stl_pg AS pe_stl, ps.blk_pg AS pe_blk
              FROM players p
              JOIN teams t ON t.id = p.team_id
              JOIN player_season_stats ps
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
