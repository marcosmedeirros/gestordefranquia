<?php
/**
 * Motor de cálculo do Salary Cap — exclusivo da liga ELITE.
 *
 * Nada é armazenado: salário, bônus e cap flex são sempre calculados na hora a
 * partir de ovr, draft_round/draft_pick_position, seasons_in_league e
 * season_awards. Isso satisfaz o próprio requisito da regra ("recalcular sempre
 * que o OVR mudar ou houver troca") de graça — não existe estado pra ficar
 * desatualizado.
 *
 * Fonte: regulamento + especificação técnica de Salary Cap (FBA Elite), com duas
 * correções do responsável pela liga sobre o texto original dos PDFs:
 *   1. Rookie scale usa os valores abaixo (substituem os do PDF).
 *   2. Bônus de prêmio vale só pela temporada seguinte ao prêmio, não é permanente.
 */

require_once __DIR__ . '/helpers.php'; // markLoyaltyEligibility (Bônus de Lealdade)

/**
 * `players.contract_salary`: o que o time se comprometeu a pagar por ele.
 *
 * Nasce do lance vencedor no waiver e na Free Agency da ELITE. Vale 0 (ou
 * NULL) pra quem nunca foi arrematado — e aí o salário sai da tabela por OVR,
 * como sempre foi.
 *
 * A coluna nasce aqui e não numa migração porque toda tela de cap passa por
 * este arquivo: sem ela, o cálculo quebraria em base que ainda não migrou.
 * Roda uma vez por request.
 */
function capGarantirColunaContrato(PDO $pdo): void
{
    static $pronto = false;
    if ($pronto || $pdo->inTransaction()) return;
    $pronto = true;
    try {
        if (!$pdo->query("SHOW COLUMNS FROM players LIKE 'contract_salary'")->fetch()) {
            $pdo->exec("ALTER TABLE players ADD COLUMN contract_salary INT NULL");
        }
    } catch (Throwable $e) {
        error_log('[cap] coluna contract_salary: ' . $e->getMessage());
    }
}

/**
 * O TETO DO LANCE NA FREE AGENCY: a média salarial da liga.
 *
 * Regra da liga (30/08/2026): o lance máximo é a folha somada dividida pelo
 * número de jogadores. Com 450 jogadores e 3.436M, dá 7,64 — teto de 7M.
 *
 * Arredonda pra BAIXO porque é um teto: 7,64 vira 7, e ninguém oferece mais
 * do que a média. O número acompanha a liga sozinho, sem ninguém ter que
 * recalcular quando a folha muda.
 *
 * Cacheado por request: esta conta percorre o elenco de todos os times, e a
 * tela da Free Agency a consulta uma vez por jogador da lista.
 */
function capMediaSalarialDaLiga(PDO $pdo, string $league): int
{
    static $cache = [];
    $league = strtoupper(trim($league));
    if (isset($cache[$league])) return $cache[$league];

    $soma = 0; $jogadores = 0;
    try {
        $st = $pdo->prepare('SELECT id FROM teams WHERE league = ?');
        $st->execute([$league]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            $resumo = getTeamCapSummary($pdo, (int)$tid);
            $soma      += (int)$resumo['payroll'];
            $jogadores += count($resumo['roster']);
        }
    } catch (Throwable $e) {
        error_log('[capMediaSalarialDaLiga] ' . $e->getMessage());
        return $cache[$league] = 0;
    }
    if ($jogadores <= 0) return $cache[$league] = 0;

    return $cache[$league] = max(1, (int)floor($soma / $jogadores));
}

const CAP_BASE_MILLIONS = 205;
const CAP_FLOOR_MILLIONS = 170;
// Quantos jogadores do elenco podem gerar Cap Flex ao mesmo tempo.
const CAP_FLEX_MAX_PLAYERS = 2;
// Bônus de Lealdade: jogador leal (nunca trocado, OVR>=90, draftado pelo draft
// da própria temporada — mesma régua da RISE/NEXT) soma este valor no Cap
// Máximo, limitado a este nº de jogadores por time.
const CAP_LOYALTY_MAX_PLAYERS = 2;
const CAP_LOYALTY_BONUS_MILLIONS = 8;
// Numa troca, o time sem espaco no teto so pode receber ate esta % do que envia.
const CAP_TRADE_MATCH_PCT = 120;

// Quanto uma pick pesa no casamento salarial da troca. Vale pros DOIS lados:
// quem manda a pick conta como enviado, quem recebe conta como recebido.
//
// A pick nao entra na folha do elenco — ela nao e jogador, so vira salario no
// ano seguinte, quando o calouro assina. Este numero existe so pra troca, pra
// que picks tenham peso no envia/recebe em vez de serem moeda de graca.
//
// Regra da liga (definida em 14/08/2026). O simulador (trade-simulator.php) e
// o front das trocas (js/trades.js) leem esses valores daqui — nao repita o
// numero em lugar nenhum.
const CAP_PICK_TRADE_VALUE = [1 => 5, 2 => 2];

/**
 * Peso da pick no casamento salarial.
 *
 * Com a POSIÇÃO conhecida — depois da loteria, na classe de picks que o draft
 * aberto distribui — vale a rookie scale daquela escolha, que é o salário que
 * o calouro vai mesmo assinar: 18M da 1ª à 3ª, 14M até a 8ª, e por aí. Sem
 * posição a pick ainda é uma aposta, e aí continua valendo o número plano de
 * CAP_PICK_TRADE_VALUE.
 *
 * Antes toda pick de 1ª rodada pesava 5M, tivesse ela saído Escolha 1 ou
 * Escolha 30 — a primeira escolha do draft entrava numa troca pesando menos
 * de um terço do que o time ia pagar por ela.
 */
function capValorDaPickNaTroca(int $round, ?int $pickPosition = null): int
{
    if ($pickPosition !== null && $pickPosition > 0) {
        // A posição gravada é a de DENTRO da rodada; a rookie scale de 2ª
        // rodada é plana, então só a 1ª precisa do número.
        return capRookieScaleValue($round, $pickPosition);
    }
    return CAP_PICK_TRADE_VALUE[$round] ?? 0;
}

/**
 * A liga usa folha em dinheiro? Consulta uma vez por liga por request — as
 * telas que montam texto de elenco perguntam isso a cada time.
 */
function capLigaUsaSalario(PDO $pdo, string $league): bool
{
    static $cache = [];
    $league = strtoupper(trim($league));
    if ($league === '') return false;
    if (!array_key_exists($league, $cache)) {
        try {
            $st = $pdo->prepare('SELECT cap_mode FROM league_settings WHERE league = ?');
            $st->execute([$league]);
            $cache[$league] = ($st->fetchColumn() ?: 'ovr_sum') === 'salary';
        } catch (Throwable $e) {
            $cache[$league] = false;
        }
    }
    return $cache[$league];
}

/**
 * Mapa [id do jogador => salário] pra quem monta lista de elenco: /time no bot,
 * copiar time, copiar elencos da liga. Fora do modo salário devolve [] — aí
 * quem chama simplesmente não escreve salário nenhum, em vez de escrever 0M.
 *
 * Passa pelo getTeamCapSummary de propósito: é o mesmo número da folha do card
 * e do modal, incluindo rookie scale, lenda e bônus de prêmio.
 */
function capSalariosDoTime(PDO $pdo, int $teamId, string $league): array
{
    // Só ELITE, igual capMarcarDraftInicial(). Nas outras o limite é soma de OVR
    // e não existe salário nenhum pra mostrar — nem 0M, que seria mentira.
    if (strtoupper(trim($league)) !== 'ELITE') return [];
    if (!capLigaUsaSalario($pdo, $league)) return [];
    try {
        $mapa = [];
        foreach (getTeamCapSummary($pdo, $teamId)['roster'] as $r) {
            $mapa[(int)$r['id']] = (int)$r['total_salary'];
        }
        return $mapa;
    } catch (Throwable $e) {
        error_log('[capSalariosDoTime] ' . $e->getMessage());
        return [];
    }
}

/**
 * Base (Cap Máximo antes de Cap Flex/Bônus de Lealdade) e Piso da folha
 * salarial, configurados pelo admin em Central da Liga (campos "CAP Mínimo"
 * e "CAP Máximo" — os mesmos que as outras ligas usam pro modo ovr_sum).
 * Sem nada configurado (0 ou ausente), cai nos valores padrão do documento
 * FBA Elite 15 — mantém o comportamento de sempre pra quem nunca mexeu.
 */
function capBaseEFloorDaLiga(PDO $pdo, string $league): array
{
    try {
        $stmt = $pdo->prepare('SELECT cap_min, cap_max FROM league_settings WHERE league = ?');
        $stmt->execute([strtoupper(trim($league))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $base  = (int)($row['cap_max'] ?? 0);
        $floor = (int)($row['cap_min'] ?? 0);
        return [
            'base'  => $base > 0 ? $base : CAP_BASE_MILLIONS,
            'floor' => $floor > 0 ? $floor : CAP_FLOOR_MILLIONS,
        ];
    } catch (Throwable $e) {
        return ['base' => CAP_BASE_MILLIONS, 'floor' => CAP_FLOOR_MILLIONS];
    }
}

/**
 * Temporada a partir da qual o Cap Flex passa a valer.
 *
 * A primeira temporada da edição começa logo depois do Draft Inicial, quando
 * ninguém "desenvolveu" ninguém ainda — o Cap Flex existe justamente pra
 * franquia segurar a estrela que ela mesma formou, então não faz sentido no
 * ano 1. Como todo jogador do draft inicial fica com drafted_by_team_id igual
 * ao próprio time, sem essa trava metade da liga ganharia +16M de teto de
 * graça já na estreia.
 *
 * Fica em league_settings pra Administração poder ligar quando quiser, sem
 * depender de deploy.
 */
function capFlexLiberado(PDO $pdo, string $league, ?int $temporadaAtual): bool
{
    // columnExists() só existe dentro dos endpoints de api/, não em helpers.php —
    // por isso a checagem da coluna é feita direto aqui.
    try {
        $temColuna = $pdo->query("SHOW COLUMNS FROM league_settings LIKE 'cap_flex_a_partir_da_temporada'")->fetch();
        if (!$temColuna) {
            $pdo->exec("ALTER TABLE league_settings ADD COLUMN cap_flex_a_partir_da_temporada INT NULL");
        }
        $st = $pdo->prepare("SELECT cap_flex_a_partir_da_temporada FROM league_settings WHERE league = ?");
        $st->execute([strtoupper(trim($league))]);
        $desde = $st->fetchColumn();
    } catch (Throwable $e) {
        return true; // falha de schema não pode desligar uma regra da liga
    }

    // Sem configuração: Cap Flex ligado (comportamento histórico das ligas que já rodam).
    if ($desde === null || $desde === false || $desde === '') return true;
    if ($temporadaAtual === null) return true;
    return $temporadaAtual >= (int)$desde;
}

/** Tabela de salário por OVR (em milhões). OVR 77 ou menos cai no "veteran minimum". */
function capOvrSalaryTable(): array
{
    return [
        99 => 60, 98 => 56, 97 => 52, 96 => 48, 95 => 44, 94 => 40, 93 => 36,
        92 => 32, 91 => 29, 90 => 26, 89 => 23, 88 => 20, 87 => 18, 86 => 16,
        85 => 14, 84 => 12, 83 => 10, 82 => 8, 81 => 6, 80 => 5, 79 => 4, 78 => 3,
    ];
}

const CAP_VETERAN_MINIMUM_MILLIONS = 2;

function capOvrSalary(int $ovr): int
{
    if ($ovr >= 99) return 60;
    if ($ovr <= 77) return CAP_VETERAN_MINIMUM_MILLIONS;
    $table = capOvrSalaryTable();
    return $table[$ovr] ?? CAP_VETERAN_MINIMUM_MILLIONS;
}

/**
 * Rookie scale (valores corrigidos — substituem o PDF original).
 * 1ª rodada por posição do pick; 2ª rodada é sempre 2M, independente da posição.
 */
function capRookieScaleValue(int $draftRound, ?int $draftPickPosition): int
{
    if ($draftRound >= 2) return 2;
    if ($draftPickPosition === null) return 2; // sem posição registrada: cai no piso, nunca superestima
    if ($draftPickPosition <= 3) return 18;
    if ($draftPickPosition <= 8) return 14;
    if ($draftPickPosition <= 12) return 12;
    if ($draftPickPosition <= 16) return 8;
    if ($draftPickPosition <= 22) return 5;
    return 3; // 23-30
}

/**
 * O jogador é calouro NESTA temporada? Só nesse caso vale a rookie scale
 * ("VALE SÓ NO ANO 1" no regulamento).
 *
 * DUAS COISAS IMPORTANTES AQUI:
 *
 * 1. O gatilho era `seasons_in_league === 0`, mas essa coluna nasce 0 e NUNCA é
 *    incrementada em lugar nenhum do sistema — nenhuma virada de temporada mexe
 *    nela. Resultado: todo jogador com draft_round ficava eternamente na rookie
 *    scale.
 *
 * 2. O DRAFT INICIAL NÃO É DRAFT DE CALOURO. Ele é um draft de várias rodadas
 *    só pra distribuir os elencos no começo da edição, e grava draft_round /
 *    draft_pick_position em todo mundo (o "Voltar Pick" depende dessas colunas,
 *    por isso elas continuam sendo gravadas). Juntando com o item 1, qualquer
 *    jogador pego da 2ª rodada em diante caía no `return 2` do
 *    capRookieScaleValue — era o "86 aparecendo com 2M". Esses jogadores são
 *    atletas normais e seguem a tabela por OVR.
 *
 * O que separa um do outro é o drafted_season_number: o draft anual
 * (api/draft.php) sempre grava a temporada do draft, o Draft Inicial nunca.
 * Sem essa informação, o jogador é tratado como atleta normal — que é a regra
 * geral do regulamento ("O salário de todo jogador é definido exclusivamente
 * pelo overall").
 */
function capEhCalouroNaTemporadaAtual(array $player, int|array|null $temporadaAtual): bool
{
    if (($player['draft_round'] ?? null) === null) return false;
    $draftadoEm = $player['drafted_season_number'] ?? null;
    if ($draftadoEm === null || $temporadaAtual === null) return false;  // Draft Inicial cai aqui
    // Aceita uma lista porque quem carimba e quem pergunta não resolvem a
    // temporada do mesmo jeito — ver capTemporadasDeCalouro().
    $validas = is_array($temporadaAtual) ? $temporadaAtual : [$temporadaAtual];
    return in_array((int)$draftadoEm, array_map('intval', $validas), true);
}

/**
 * As temporadas que contam como "temporada de estreia" numa liga.
 *
 * São DUAS RESOLUÇÕES que precisavam bater e não batiam sozinhas:
 *
 *   quem CARIMBA   api/draft.php grava drafted_season_number com o
 *                  season_number da SESSÃO DE DRAFT, na hora da escolha.
 *   quem PERGUNTA  o cap usa temporadaAtivaDaLiga() — a última temporada
 *                  não concluída da liga.
 *
 * Enquanto as duas dão o mesmo número tudo funciona. Quando a sessão de
 * draft está numa temporada e a "ativa" é outra, o número não bate, o
 * calouro deixa de ser calouro e cai na tabela de OVR: a escolha 1 aparece
 * com 48M em vez dos 18M da rookie scale. A pick já pesava certo na troca —
 * quem não pegava era o jogador escolhido com ela.
 *
 * Aqui as duas entram na conta. A do draft ABERTO é a que carimbou quem
 * acabou de ser escolhido; a ativa cobre o resto do ano. Draft de sprint
 * encerrado ou de temporada já concluída fica de fora — senão um calouro
 * antigo continuaria barato pra sempre.
 *
 * Ao avançar a temporada, a antiga vira 'completed' e o draft dela sai
 * desta lista: o carimbo velho deixa de bater e o jogador volta pro OVR,
 * que é a regra ("vale só no ano 1").
 */
function capTemporadasDeCalouro(PDO $pdo, string $liga): array
{
    static $cache = [];
    $liga = strtoupper(trim($liga));
    if (isset($cache[$liga])) return $cache[$liga];

    $nums = [];
    $temp = temporadaAtivaDaLiga($pdo, $liga);
    $ativa = $temp ? (int)$temp['season_number'] : null;
    if ($ativa) $nums[] = $ativa;

    /*
     * O DRAFT ACONTECE NO FIM DA TEMPORADA, E O CALOURO ESTREIA NA SEGUINTE.
     *
     * A sessão 74 da ELITE é da temporada 1 e carimbou 44 calouros com
     * `drafted_season_number = 1` — mas quando ela fechou a liga já estava na
     * temporada 2. A regra é "vale na PRIMEIRA temporada profissional dele",
     * e essa é a 2, não a 1.
     *
     * Por isso o carimbo válido é o da temporada ativa OU o da anterior: um
     * é a liga que draftou e jogou no mesmo ano, o outro é o draft de fim de
     * ano que alimenta o ano seguinte. Os dois casos existem na base.
     *
     * A versão anterior exigia sessão 'setup'/'in_progress' e temporada não
     * concluída. Só que os dois viram falso no instante em que o draft
     * termina: os 44 calouros perdiam a rookie scale no mesmo minuto em que
     * eram escolhidos — a escolha 10 caía de 12M pro salário por OVR.
     *
     * O sprint continua filtrando, e é ele que impede o zumbi: `season_number`
     * se repete a cada sprint, então sem esse filtro um "1" de sprint antigo
     * daria rookie scale a jogador de outra era.
     */
    if ($ativa) {
        try {
            $st = $pdo->prepare("SELECT DISTINCT se.season_number
                                   FROM draft_sessions ds
                                   JOIN seasons se ON se.id = ds.season_id
                              LEFT JOIN sprints spr ON spr.id = se.sprint_id
                                  WHERE ds.league = ?
                                    AND se.season_number IN (?, ?)
                                    AND (spr.id IS NULL OR spr.status IS NULL OR spr.status = 'active')");
            $st->execute([$liga, $ativa, $ativa - 1]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) $nums[] = (int)$n;
        } catch (Throwable $e) {
            error_log('[capTemporadasDeCalouro] ' . $e->getMessage());
        }
    }

    return $cache[$liga] = array_values(array_unique(array_filter($nums)));
}

/**
 * Salário base do jogador: rookie scale só na temporada de estreia dele,
 * tabela de OVR em qualquer outro caso.
 */
/** Piso do contrato de lenda. Vale mais que a tabela por OVR até o 94 (que dá exatamente 40M). */
const CAP_LENDA_MINIMO_MILLIONS = 40;

function getPlayerBaseSalary(array $player, int|array|null $temporadaAtual = null): int
{
    $ovr = (int)($player['ovr'] ?? 0);

    /*
     * CONTRATO ASSINADO MANDA — foi o que o time apostou por ele.
     *
     * Waiver e Free Agency da ELITE são leilão de salário: quem dá 65M num
     * jogador está comprometendo 65M do próprio teto, e é isso que ele passa
     * a custar. Antes o lance decidia só quem levava, e o jogador entrava no
     * cap pelo OVR — um 71 arrematado por 65M ocupava 2M, e o lance não
     * custava nada a quem venceu.
     *
     * Vale ATÉ A VIRADA DA TEMPORADA (decisão da liga, 30/08/2026): o campo é
     * zerado no avanço, e daí em diante ele volta pra tabela por OVR.
     *
     * Fica acima da lenda e da rookie scale de propósito: as duas são réguas
     * automáticas, e o contrato é um número que alguém escolheu pagar.
     */
    $contrato = isset($player['contract_salary']) ? (int)$player['contract_salary'] : 0;
    if ($contrato > 0) return $contrato;

    // Lenda da franquia: contrato de no mínimo 40M, acima de qualquer outra
    // régua — inclusive da rookie scale. Passando de 94 de OVR a tabela normal
    // já paga mais que isso e volta a valer sozinha (95 = 44M), então um max()
    // resolve os dois casos sem if de faixa.
    if (!empty($player['is_lenda'])) {
        return max(CAP_LENDA_MINIMO_MILLIONS, capOvrSalary($ovr));
    }

    if (capEhCalouroNaTemporadaAtual($player, $temporadaAtual)) {
        $pick = isset($player['draft_pick_position']) ? (int)$player['draft_pick_position'] : null;
        // Calouro sem posição de pick registrada não vira "mínimo do veterano":
        // sem essa informação a régua por OVR é mais justa do que fixar 2M num
        // jogador de 86. O piso da rookie scale (2M) só vale pra 2ª rodada, que
        // é identificada pelo próprio draft_round.
        if ($pick === null && (int)$player['draft_round'] < 2) {
            return capOvrSalary($ovr);
        }
        return capRookieScaleValue((int)$player['draft_round'], $pick);
    }

    /*
     * O PISO DO DRAFT INICIAL SAIU. Valia só o primeiro ano.
     *
     * Era um piso de cap pra quem veio do Draft Inicial com 78- e até 23
     * anos: 16M, 10M ou 6M conforme a rodada, mesmo que a tabela por OVR
     * cobrasse menos. Foi decisão da liga em 30/08/2026 encerrar a regra —
     * esses jogadores passam a custar o que o OVR deles diz, como todo mundo.
     *
     * Na ELITE eram 34 jogadores, e a folha da liga cai 178M com a mudança.
     */
    return capOvrSalary($ovr);
}

/**
 * Cap Flex: só se aplica enquanto o jogador está no time que o draftou
 * (drafted_by_team_id == team_id) e o OVR está nas faixas elegíveis.
 * Aumenta o Cap Máximo da franquia — não o salário do jogador.
 */
function getPlayerCapFlex(array $player): int
{
    $teamId = (int)($player['team_id'] ?? 0);
    $draftedBy = $player['drafted_by_team_id'] ?? null;
    if ($draftedBy === null || (int)$draftedBy !== $teamId) {
        return 0;
    }
    $ovr = (int)($player['ovr'] ?? 0);
    if ($ovr >= 93) return 8;
    if ($ovr >= 90) return 5;
    if ($ovr >= 85) return 3;
    return 0;
}

/**
 * Tabela de bônus por prêmio individual (em milhões), conforme o deck FBA Elite 15.
 * Todos são lidos de season_awards por award_type e valem só pela temporada
 * seguinte ao prêmio (bônus temporário — ver getAwardBonusesByPlayerName).
 * Cadastro: prêmios simples no "Registro de Pontuação"; Finals MVP / All-NBA /
 * All-Defensive na tela de prêmios estendidos (admin).
 */
function capAwardBonusTable(): array
{
    return [
        'mvp' => 5,
        'dpoy' => 3,
        'roy' => 2,
        'mip' => 2,
        '6th_man' => 2,
        'finals_mvp' => 3,
        // All-NBA (1º/2º/3º time) e All-Defensive (1º/2º time). Cada time premia 5 jogadores.
        'all_nba_1' => 3, 'all_nba_2' => 2, 'all_nba_3' => 1,
        'all_def_1' => 2, 'all_def_2' => 1,
    ];
}

/**
 * Bônus de prêmio: vale só pela temporada seguinte à que o prêmio foi registrado,
 * depois some. Como nada é armazenado, isso é automático — a cada chamada,
 * olhamos só os prêmios da temporada imediatamente anterior à ativa.
 * Retorna um mapa "nome do jogador em minúsculas" => soma de bônus (milhões).
 */
function getAwardBonusesByPlayerName(PDO $pdo, string $league): array
{
    $bonuses = [];
    try {
        /*
         * A temporada corrente é a do SPRINT ATIVO. Sem esse recorte, uma
         * temporada velha de sprint anterior que ficou sem status 'completed'
         * ganhava o ORDER BY e o motor lia os prêmios da liga errada no tempo.
         * Cada sprint recomeça a numeração, então season_number sozinho se
         * repete e não identifica temporada nenhuma.
         */
        $stmtCurrent = $pdo->prepare("
            SELECT s.season_number, s.sprint_id
            FROM seasons s
            LEFT JOIN sprints sp ON sp.id = s.sprint_id
            WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ('completed'))
            ORDER BY COALESCE(sp.sprint_number, 0) DESC, s.season_number DESC, s.created_at DESC
            LIMIT 1
        ");
        $stmtCurrent->execute([$league]);
        $atual = $stmtCurrent->fetch(PDO::FETCH_ASSOC);
        if (!$atual) {
            return $bonuses;
        }
        $priorSeasonNumber = (int)$atual['season_number'] - 1;
        if ($priorSeasonNumber < 1) {
            return $bonuses;
        }

        // <=> compara com NULL sem tropeçar: liga sem sprint continua achando
        // a anterior dela, em vez de nunca casar.
        $stmtPriorSeason = $pdo->prepare("SELECT id FROM seasons
                                           WHERE league = ? AND season_number = ? AND sprint_id <=> ?
                                           ORDER BY created_at DESC LIMIT 1");
        $stmtPriorSeason->execute([$league, $priorSeasonNumber, $atual['sprint_id']]);
        $priorSeasonId = $stmtPriorSeason->fetchColumn();
        if (!$priorSeasonId) {
            return $bonuses;
        }

        /*
         * O PRÊMIO É DO JOGADOR, NÃO DO TIME.
         *
         * Aqui havia um AND team_id = ?, o time onde ele ganhou. Trocar um
         * All-NBA depois da premiação fazia o bônus sumir: o time novo não
         * achava o prêmio (registrado com o time antigo) e o jogador passava a
         * custar o salário-base. Era uma brecha de cap — bastava trocar o
         * premiado pro custo evaporar.
         *
         * O mapa é por nome e só é aplicado aos jogadores DO time consultado,
         * então ler os prêmios da liga inteira não vaza bônus pra ninguém.
         */
        $bonusTable = capAwardBonusTable();
        $stmtAwards = $pdo->prepare("SELECT award_type, player_name FROM season_awards WHERE season_id = ?");
        $stmtAwards->execute([(int)$priorSeasonId]);
        foreach ($stmtAwards->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bonus = $bonusTable[$row['award_type']] ?? 0;
            if ($bonus <= 0) continue;
            $key = mb_strtolower(trim((string)$row['player_name']));
            if ($key === '') continue;
            $bonuses[$key] = ($bonuses[$key] ?? 0) + $bonus;
        }
    } catch (Exception $e) {
        return $bonuses;
    }
    return $bonuses;
}

/**
 * Resumo completo de cap de um time ELITE: folha salarial, cap flex, cap máximo,
 * espaço disponível, status, e o detalhamento por jogador.
 */
function getTeamCapSummary(PDO $pdo, int $teamId): array
{
    $stmtTeam = $pdo->prepare("SELECT id, league FROM teams WHERE id = ?");
    $stmtTeam->execute([$teamId]);
    $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
    $league = $team['league'] ?? 'ELITE';

    $awardBonuses = getAwardBonusesByPlayerName($pdo, $league);

    // Temporada ativa da liga: define quem é calouro (rookie scale) e se o Cap
    // Flex já vale (ver capFlexLiberado).
    $temporada = temporadaAtivaDaLiga($pdo, $league);
    $numTemporada = $temporada ? (int)$temporada['season_number'] : null;
    $flexLiberado = capFlexLiberado($pdo, $league, $numTemporada);
    $temporadasCalouro = capTemporadasDeCalouro($pdo, (string)$league);

    capGarantirColunaContrato($pdo);
    $stmtPlayers = $pdo->prepare("
        SELECT id, name, team_id, ovr, age, seasons_in_league, drafted_by_team_id, drafted_season_number,
               draft_round, draft_pick_position,
               COALESCE(is_lenda, 0) as is_lenda,
               COALESCE(was_traded, 0) as was_traded,
               COALESCE(contract_salary, 0) AS contract_salary
        FROM players WHERE team_id = ? ORDER BY ovr DESC
    ");
    $stmtPlayers->execute([$teamId]);
    $players = $stmtPlayers->fetchAll(PDO::FETCH_ASSOC);
    markLoyaltyEligibility($pdo, $players); // preenche is_loyal / cap_bonus_eligible
    // capMarcarDraftInicial saiu junto com o piso do Draft Inicial: nada mais
    // lê `initdraft_round` no cálculo.

    $payroll = 0;
    $roster = [];
    foreach ($players as $p) {
        // A rookie scale usa a LISTA de temporadas de estreia, não só a
        // ativa: o carimbo do calouro vem da sessão de draft, que nem sempre
        // é a mesma temporada. O $numTemporada segue valendo pro Cap Flex,
        // que é outra pergunta.
        $baseSalary = getPlayerBaseSalary($p, $temporadasCalouro);
        $isRookieScale = capEhCalouroNaTemporadaAtual($p, $temporadasCalouro);
        $bonus = $awardBonuses[mb_strtolower(trim((string)$p['name']))] ?? 0;
        $flex = $flexLiberado ? getPlayerCapFlex($p) : 0;

        $payroll += $baseSalary + $bonus;

        $roster[] = [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'ovr' => (int)$p['ovr'],
            'base_salary' => $baseSalary,
            // A lenda ignora a rookie scale: o piso de 40M vale por cima dela.
            'is_rookie_scale' => $isRookieScale && empty($p['is_lenda']),
            'is_lenda' => !empty($p['is_lenda']),
            'award_bonus' => $bonus,
            'total_salary' => $baseSalary + $bonus,
            'cap_flex_eligible' => $flex > 0,
            'cap_flex_value' => $flex,
            'cap_flex_counted' => false,
            'is_on_draft_team' => $p['drafted_by_team_id'] !== null && (int)$p['drafted_by_team_id'] === (int)$p['team_id'],
            // Leal e Lenda coexistem, mas os benefícios de cap NÃO se somam: com a
            // tag de lenda o jogador já vale no mínimo 40M, então o +8M da lealdade
            // é anulado. A tag "Leal" continua aparecendo — só o bônus some.
            'is_loyal' => !empty($p['is_loyal']),
            'loyalty_bonus_eligible' => !empty($p['cap_bonus_eligible']) && empty($p['is_lenda']),
            'loyalty_bonus_counted' => false,
        ];
    }

    // O Cap Flex vale para no máximo CAP_FLEX_MAX_PLAYERS jogadores. Quando o
    // elenco tem mais elegíveis, contam os de maior flex (desempate pelo OVR).
    $elegiveis = [];
    foreach ($roster as $i => $r) {
        if ($r['cap_flex_value'] > 0) $elegiveis[$i] = $r;
    }
    uasort($elegiveis, function ($a, $b) {
        return ($b['cap_flex_value'] <=> $a['cap_flex_value']) ?: ($b['ovr'] <=> $a['ovr']);
    });

    $capFlexTotal = 0;
    $contados = 0;
    foreach ($elegiveis as $i => $r) {
        if ($contados >= CAP_FLEX_MAX_PLAYERS) break;
        $roster[$i]['cap_flex_counted'] = true;
        $capFlexTotal += $r['cap_flex_value'];
        $contados++;
    }

    // Bônus de Lealdade: até CAP_LOYALTY_MAX_PLAYERS jogadores elegíveis somam
    // CAP_LOYALTY_BONUS_MILLIONS cada no Cap Máximo (desempate pelo OVR).
    //
    // Nota de regra: o deck da FBA Elite 15 fala em teto de 205M indo até 221M (só o
    // Cap Flex). Dois motivos pra o número de hoje não bater com o do documento, e
    // nenhum é bug:
    //
    //   1. A base deixou de ser fixa — vem do "CAP Máximo" que o admin configura
    //      na Central da Liga (capBaseEFloorDaLiga). O deck virou o padrão de quem
    //      nunca preencheu o campo.
    //   2. O Bônus de Lealdade é regra da liga, acrescentada depois do deck: com
    //      dois leais no elenco, o teto sobe mais 16M por cima de tudo.
    $loyalElegiveis = [];
    foreach ($roster as $i => $r) {
        if (!empty($r['loyalty_bonus_eligible'])) $loyalElegiveis[$i] = $r;
    }
    uasort($loyalElegiveis, fn($a, $b) => $b['ovr'] <=> $a['ovr']);

    $capLoyaltyTotal = 0;
    $loyalContados = 0;
    foreach ($loyalElegiveis as $i => $r) {
        if ($loyalContados >= CAP_LOYALTY_MAX_PLAYERS) break;
        $roster[$i]['loyalty_bonus_counted'] = true;
        $capLoyaltyTotal += CAP_LOYALTY_BONUS_MILLIONS;
        $loyalContados++;
    }

    $baseFloor = capBaseEFloorDaLiga($pdo, (string)$league);
    $capMax = $baseFloor['base'] + $capFlexTotal + $capLoyaltyTotal;
    $space = $capMax - $payroll;
    $status = 'dentro_do_cap';
    if ($payroll > $capMax) {
        $status = 'over_the_cap';
    } elseif ($payroll < $baseFloor['floor']) {
        $status = 'abaixo_do_piso';
    }

    return [
        'team_id' => $teamId,
        'league' => $league,
        'cap_base' => $baseFloor['base'],
        'cap_floor' => $baseFloor['floor'],
        'cap_flex_total' => $capFlexTotal,
        'cap_flex_max_players' => CAP_FLEX_MAX_PLAYERS,
        'cap_flex_used_slots' => $contados,
        'cap_flex_eligible_count' => count($elegiveis),
        'cap_loyalty_total' => $capLoyaltyTotal,
        'cap_loyalty_max_players' => CAP_LOYALTY_MAX_PLAYERS,
        'cap_loyalty_used_slots' => $loyalContados,
        'cap_loyalty_eligible_count' => count($loyalElegiveis),
        'cap_loyalty_bonus_millions' => CAP_LOYALTY_BONUS_MILLIONS,
        'cap_max' => $capMax,
        'payroll' => $payroll,
        'space' => $space,
        'status' => $status,
        'roster' => $roster,
    ];
}

/**
 * Escreve um valor de cap do jeito que a liga lê: "16M" na ELITE, "9 de OVR"
 * onde o cap é soma. Colado ("9OVR") ninguém entende na primeira passada.
 */
function capValorEscrito(int $valor, string $unidade): string
{
    return $unidade === 'M' ? $valor . 'M' : $valor . ' de OVR';
}

/**
 * O espaço em forma de frase — negativo não é espaço, é dívida.
 */
function capEspacoEscrito(int $espaco, string $unidade): string
{
    return $espaco < 0
        ? 'seu elenco está ' . capValorEscrito(abs($espaco), $unidade) . ' acima do teto'
        : 'você tem ' . capValorEscrito($espaco, $unidade) . ' de espaço';
}

/**
 * Quanto um jogador de tal OVR custaria a este time, e se ele cabe.
 *
 * É a mesma pergunta na Free Agency e no waiver, e a resposta muda por liga:
 * na ELITE o cap é folha salarial, e o custo é o salário da tabela por OVR;
 * nas outras o cap é a soma de OVR dos CAP_TOP_N melhores, e o custo é o quanto
 * essa soma sobe ao encaixar o jogador — zero, se ele não entra no top.
 *
 * Devolve sempre as duas pontas (custo e espaço) na mesma unidade, pra tela
 * poder dizer "custa 16M, você tem 22M" sem saber de qual liga se trata.
 *
 * ovr 0 (ou time inexistente) devolve cabe=true: sem OVR não há o que julgar,
 * e travar o jogador por falta de informação seria pior que deixar passar —
 * quem confere de verdade é o admin na hora de aprovar.
 */
function capCabeNoTime(PDO $pdo, int $teamId, int $ovr): array
{
    $vazio = ['custo' => 0, 'espaco' => 0, 'cabe' => true, 'unidade' => 'M', 'modo' => 'salary'];
    if ($teamId <= 0 || $ovr <= 0) return $vazio;

    $st = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
    $st->execute([$teamId]);
    $league = (string)($st->fetchColumn() ?: '');
    if ($league === '') return $vazio;

    if (capLigaUsaSalario($pdo, $league)) {
        try {
            $resumo = getTeamCapSummary($pdo, $teamId);
        } catch (Throwable $e) {
            return $vazio;
        }
        // Sem lenda e sem rookie scale: quem chega de fora entra pela tabela.
        $custo  = capOvrSalary($ovr);
        $espaco = (int)$resumo['space'];
        // max(0, ...): time estourado tem espaço negativo, e comparar contra
        // negativo diria que nem um jogador de custo zero cabe.
        return ['custo' => $custo, 'espaco' => $espaco, 'cabe' => $custo <= max(0, $espaco),
                'unidade' => 'M', 'modo' => 'salary'];
    }

    // Modo soma de OVR: o custo é o que a soma do top sobe com ele dentro.
    require_once __DIR__ . '/helpers.php';
    $st = $pdo->prepare('SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC');
    $st->execute([$teamId]);
    $ovrs = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    $somaAtual = array_sum(array_slice($ovrs, 0, CAP_TOP_N));
    $comEle = $ovrs;
    $comEle[] = $ovr;
    rsort($comEle);
    $somaNova = array_sum(array_slice($comEle, 0, CAP_TOP_N));

    $capMax = capBaseEFloorDaLiga($pdo, $league)['base'];
    $custo  = $somaNova - $somaAtual;
    $espaco = $capMax - $somaAtual;
    // Custo zero é o reserva que não entra no top: não mexe no cap, então
    // entra mesmo com o time acima do teto.
    return ['custo' => $custo, 'espaco' => $espaco, 'cabe' => $custo <= max(0, $espaco),
            'unidade' => 'OVR', 'modo' => 'ovr_sum'];
}

/**
 * Sugestões práticas de como o time pode se ajustar ao cap, conforme o status.
 * Retorna lista de ['type' => ok|danger|warn|info|tip, 'text' => '...'].
 */
function getCapSuggestions(array $summary): array
{
    $out = [];
    $payroll = (int)$summary['payroll'];
    $capMax = (int)$summary['cap_max'];
    $floor = (int)$summary['cap_floor'];
    $space = (int)$summary['space'];
    $roster = $summary['roster'] ?? [];

    $sorted = $roster;
    usort($sorted, fn($a, $b) => (int)$b['total_salary'] <=> (int)$a['total_salary']);

    if ($summary['status'] === 'over_the_cap') {
        $excess = $payroll - $capMax;
        $out[] = ['type' => 'danger', 'text' => "Você está {$excess}M acima do teto ({$capMax}M). É preciso reduzir esse valor em salário até a Trade Deadline."];

        // Menor jogador que sozinho cobre o excesso.
        $single = null;
        foreach (array_reverse($sorted) as $p) {
            if ((int)$p['total_salary'] >= $excess) { $single = $p; break; }
        }
        if ($single) {
            $out[] = ['type' => 'info', 'text' => "Negociar {$single['name']} ({$single['total_salary']}M) já resolveria sozinho — troque por picks ou por um jogador mais barato."];
        }

        $top = array_slice($sorted, 0, 3);
        if ($top) {
            $names = implode(', ', array_map(fn($p) => "{$p['name']} ({$p['total_salary']}M)", $top));
            $out[] = ['type' => 'info', 'text' => "Maiores salários do elenco: {$names}."];
        }
        $out[] = ['type' => 'tip', 'text' => "Numa troca, você pode receber no máximo 120% do salário que enviar — mande mais salário do que recebe para abrir espaço."];
    } elseif ($summary['status'] === 'abaixo_do_piso') {
        $need = $floor - $payroll;
        $out[] = ['type' => 'warn', 'text' => "Você está {$need}M abaixo do piso ({$floor}M). Após a Trade Deadline, todo time precisa alcançar o piso salarial."];
        $out[] = ['type' => 'info', 'text' => "Para subir a folha: contrate um agente livre, faça uma troca recebendo mais salário do que envia, ou suba o OVR de jogadores do elenco."];
        $out[] = ['type' => 'tip', 'text' => "Jogadores de 77 OVR ou menos custam só 2M (mínimo de veterano) — para somar folha, priorize subir OVR ou trazer nomes mais caros."];
    } else {
        $out[] = ['type' => 'ok', 'text' => "Dentro do teto, com {$space}M de espaço disponível."];
        if ($space > 0) {
            $out[] = ['type' => 'tip', 'text' => "Você pode absorver até {$space}M em salário numa troca sem estourar o teto."];
        }
        $out[] = ['type' => 'tip', 'text' => "Mantenha no elenco os jogadores que você mesmo draftou (85+ OVR) — eles somam Cap Flex e aumentam o seu teto."];
    }

    return $out;
}

/**
 * Casamento salarial de troca (regra dos 120%).
 *
 * Vale para TODA troca, tendo o time espaço no cap ou não: nenhum lado pode
 * receber mais de 120% do que envia. Quem manda 10M recebe no máximo 12M.
 * (Receber menos do que envia nunca é problema — a trava só pega quem recebe
 * a mais.)
 *
 * @param int $payrollAtual folha do time antes da troca (só informativo aqui)
 * @param int $capMax       teto do time (só informativo aqui)
 * @param int $enviado      soma dos salários que saem
 * @param int $recebido     soma dos salários que entram
 */
/**
 * A faixa de envio que fecha os DOIS lados de uma vez, dado o que se recebe.
 *
 * Existe porque a mensagem de erro dizia só qual lado estourou e por quanto —
 * e isso levava a piorar a troca. O caso real (15/08/2026): o GM enviava 17M
 * pra receber 21M, faltava 1M, e a tela dizia "o limite é 20M", que é sobre o
 * que ele RECEBE. Ele então engordou o próprio lado até 21M e a violação
 * pulou pro outro time, porque o limite do outro é sobre o que ELE envia.
 * Ficou preso entre duas mensagens que se contradiziam.
 *
 * A faixa resolve porque satisfaz as duas restrições ao mesmo tempo:
 *
 *     recebido <= 1,2 x enviado     e     enviado <= 1,2 x recebido
 *
 * Um número só, que não tem como empurrar o problema pro outro lado.
 *
 * Devolve ['min' => int, 'max' => int]. Com recebido 0 a faixa é [0, 0]: dar
 * jogador de graça não passa na regra, e isso é de propósito.
 */
function tradeFaixaDeEnvio(int $recebido): array
{
    if ($recebido <= 0) return ['min' => 0, 'max' => 0];
    $pct = CAP_TRADE_MATCH_PCT / 100;
    return [
        'min' => (int)ceil($recebido / $pct),
        'max' => (int)floor($recebido * $pct),
    ];
}

function checkTradeSalaryMatch(int $payrollAtual, int $capMax, int $enviado, int $recebido): array
{
    $folhaDepois = $payrollAtual - $enviado + $recebido;
    $limite = (int)floor($enviado * (CAP_TRADE_MATCH_PCT / 100));

    $base = [
        'enviado'        => $enviado,
        'recebido'       => $recebido,
        'folha_depois'   => $folhaDepois,
        'cap_max'        => $capMax,
        'limite_receber' => $limite,
        'pct'            => CAP_TRADE_MATCH_PCT,
        'aplica'         => true,
    ];

    if ($recebido <= $limite) {
        return $base + [
            'ok'      => true,
            'motivo'  => "Recebe {$recebido}M enviando {$enviado}M — dentro do limite de {$limite}M (" . CAP_TRADE_MATCH_PCT . "% de {$enviado}M).",
            'excesso' => 0,
        ];
    }

    // Estourou: diz de quanto é o excesso e o que faria a troca passar.
    $excesso = $recebido - $limite;
    $envioMinimo = (int)ceil($recebido / (CAP_TRADE_MATCH_PCT / 100));
    return $base + [
        'ok'            => false,
        'excesso'       => $excesso,
        'envio_minimo'  => $envioMinimo,
        'falta_enviar'  => max(0, $envioMinimo - $enviado),
        'motivo'        => "Recebe {$recebido}M enviando {$enviado}M — o limite é {$limite}M (" . CAP_TRADE_MATCH_PCT . "%). Excesso de {$excesso}M.",
    ];
}
