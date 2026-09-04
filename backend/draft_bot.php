<?php
/**
 * O /draft do bot: a ordem do draft e o que já foi escolhido.
 *
 * Existe porque durante o draft a pergunta que se repete no grupo é sempre a
 * mesma — "de quem é a vez?" e "quem já saiu?" — e a resposta estava só no
 * site. Quem está no celular no meio do trabalho não abre o painel.
 *
 * A ordem vem de draft_order, a mesma tabela que a tela usa. Nada aqui
 * recalcula nem sorteia: se a mensagem discordar da tela, é a mensagem que
 * está errada.
 */

require_once __DIR__ . '/db.php';

/** Quantas escolhas cabem numa mensagem antes de virar parede de texto. */
const DRAFT_BOT_MAX_LINHAS = 32;

/**
 * A sprint em andamento da liga. Sem ela não há draft "de agora": a liga
 * recomeça a cada sprint, e a sessão de uma encerrada é de outra era.
 */
function draftBotSprintAtiva(PDO $pdo, string $liga): ?int
{
    try {
        $st = $pdo->prepare("SELECT id FROM sprints WHERE league = ? AND status = 'active'
                          ORDER BY sprint_number DESC, id DESC LIMIT 1");
        $st->execute([$liga]);
        $id = $st->fetchColumn();
        return $id ? (int)$id : null;
    } catch (Throwable $e) {
        error_log('[draftBotSprintAtiva] ' . $e->getMessage());
        return null;
    }
}

/**
 * "LeBron James" vira "L. James", pra caber na linha.
 *
 * O sufixo anda junto do sobrenome: pegar só o último pedaço transformava
 * "Kenyon Martin Jr." em "K. Jr.", que não é o nome de ninguém.
 */
function draftBotNomeCurto(string $nome): string
{
    $p = preg_split('/\s+/u', trim($nome), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($p) < 2) return $nome;

    $sufixos = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v'];
    $ultimo = array_pop($p);
    $sobrenome = $ultimo;
    if (in_array(mb_strtolower($ultimo), $sufixos, true) && count($p) >= 2) {
        $sobrenome = array_pop($p) . ' ' . $ultimo;
    }
    return mb_strtoupper(mb_substr($p[0], 0, 1)) . '. ' . $sobrenome;
}

/**
 * O texto do /draft.
 *
 * @param string      $liga  ELITE, NEXT, RISE ou ROOKIE
 * @param int|null    $round rodada pedida; null mostra a que está em jogo
 */
function draftBotTexto(PDO $pdo, string $liga, ?int $round = null): string
{
    $liga = strtoupper(trim($liga));
    if (!in_array($liga, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
        return 'Liga não reconhecida. Use ELITE, NEXT, RISE ou ROOKIE.';
    }

    $sprint = draftBotSprintAtiva($pdo, $liga);
    if ($sprint === null) return "A *{$liga}* não tem uma sprint em andamento.";

    try {
        $st = $pdo->prepare("SELECT ds.id, ds.status, ds.current_round, ds.current_pick,
                                    ds.round2_mock_deadline, s.season_number
                               FROM draft_sessions ds
                               JOIN seasons s ON s.id = ds.season_id
                              WHERE ds.league = ? AND s.sprint_id = ?
                           ORDER BY s.season_number DESC, ds.id DESC LIMIT 1");
        $st->execute([$liga, $sprint]);
        $sessao = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[draftBotTexto/sessao] ' . $e->getMessage());
        return 'Não consegui ler o draft agora.';
    }
    if (!$sessao) return "A *{$liga}* ainda não tem draft montado.";

    $sid = (int)$sessao['id'];
    $statusSessao = (string)$sessao['status'];

    // Sem ordem sorteada não há o que listar — e é a loteria que falta.
    try {
        $temOrdem = (int)$pdo->query("SELECT COUNT(*) FROM draft_order WHERE draft_session_id = $sid")->fetchColumn();
    } catch (Throwable $e) { $temOrdem = 0; }
    if (!$temOrdem) {
        return "🏀 *Draft {$liga}* · Temporada " . (int)$sessao['season_number'] . "\n\n"
             . "_A ordem ainda não foi sorteada._ Use */loteria {$liga}* pra ver as chances.";
    }

    // A rodada mostrada: a pedida, ou a que está em jogo.
    $rodada = $round !== null ? max(1, $round) : max(1, (int)$sessao['current_round']);

    try {
        $st = $pdo->prepare("SELECT o.pick_position, o.round, o.picked_player_id,
                                    t.name AS time_nome, ot.name AS origem_nome,
                                    o.team_id, o.original_team_id,
                                    dp.name AS jogador, dp.position AS pos, dp.ovr
                               FROM draft_order o
                               JOIN teams t ON t.id = o.team_id
                          LEFT JOIN teams ot ON ot.id = o.original_team_id
                          LEFT JOIN draft_pool dp ON dp.id = o.picked_player_id
                              WHERE o.draft_session_id = ? AND o.round = ?
                           ORDER BY o.pick_position ASC");
        $st->execute([$sid, $rodada]);
        $vagas = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[draftBotTexto/ordem] ' . $e->getMessage());
        return 'Não consegui ler a ordem do draft agora.';
    }
    if (!$vagas) return "A *{$liga}* não tem {$rodada}ª rodada montada.";

    // Quantas vagas tem a 1ª rodada: é o que converte a posição da 2ª (que
    // recomeça do 1) na numeração corrida, que é como a liga fala.
    try {
        $vagasR1 = (int)$pdo->query("SELECT COUNT(*) FROM draft_order
                                      WHERE draft_session_id = $sid AND round = 1")->fetchColumn();
    } catch (Throwable $e) { $vagasR1 = 0; }

    $rotuloStatus = [
        'setup'       => 'ainda não começou',
        'in_progress' => 'em andamento',
        'completed'   => 'encerrado',
    ][$statusSessao] ?? $statusSessao;

    $txt  = "🏀 *Draft {$liga}* · Temporada " . (int)$sessao['season_number'] . "\n";
    $txt .= "_{$rodada}ª rodada · {$rotuloStatus}_\n";

    $feitas = count(array_filter($vagas, fn($v) => $v['picked_player_id'] !== null));
    $txt .= "\n*{$feitas} de " . count($vagas) . "* escolhas feitas\n";

    /* A 2ª RODADA NÃO TEM VEZ DE NINGUÉM.
       Todas as vagas abrem juntas por 20 minutos e são resolvidas no fim do
       prazo. Dizer "vez do fulano" ali seria descrever uma regra que não
       existe, e é justamente onde a liga mais se confunde. */
    if ($statusSessao === 'in_progress' && $rodada >= 2 && !empty($sessao['round2_mock_deadline'])) {
        $faltam = strtotime((string)$sessao['round2_mock_deadline']) - time();
        $txt .= $faltam > 0
            ? '⏱ Todas abertas ao mesmo tempo — fecham em *' . max(1, (int)ceil($faltam / 60)) . " min*\n"
            : "⏱ Prazo encerrado — resolvendo\n";
    } elseif ($statusSessao === 'in_progress' && $rodada === (int)$sessao['current_round']) {
        foreach ($vagas as $v) {
            if ((int)$v['pick_position'] === (int)$sessao['current_pick']) {
                $txt .= '🎯 Vez do *' . $v['time_nome'] . "*\n";
                break;
            }
        }
    }

    $txt .= "\n";

    // A lista. Numa rodada longa, corta no meio e diz o que ficou de fora.
    $mostrar = $vagas;
    $cortou  = 0;
    if (count($vagas) > DRAFT_BOT_MAX_LINHAS) {
        $mostrar = array_slice($vagas, 0, DRAFT_BOT_MAX_LINHAS);
        $cortou  = count($vagas) - DRAFT_BOT_MAX_LINHAS;
    }

    foreach ($mostrar as $v) {
        $numero = $rodada > 1 ? $vagasR1 + (int)$v['pick_position'] : (int)$v['pick_position'];
        $linha  = '*' . $numero . '.* ' . $v['time_nome'];

        // "via" só quando a vaga mudou de dono: repetir o nome não informa.
        if ((int)$v['original_team_id'] !== (int)$v['team_id'] && $v['origem_nome']) {
            $linha .= ' _(via ' . $v['origem_nome'] . ')_';
        }

        if ($v['picked_player_id'] !== null && $v['jogador']) {
            // Posição na frente do nome, e sem OVR: todo calouro entra com 60,
            // então o número não separava ninguém — só ocupava a linha.
            $linha .= ' → ' . ($v['pos'] ? $v['pos'] . ' ' : '')
                   . draftBotNomeCurto((string)$v['jogador']);
        } elseif ($statusSessao === 'in_progress'
                  && $rodada === (int)$sessao['current_round']
                  && (int)$v['pick_position'] === (int)$sessao['current_pick']) {
            $linha .= ' — _escolhendo_';
        }

        $txt .= $linha . "\n";
    }
    if ($cortou > 0) $txt .= "_+{$cortou} escolhas — veja no site_\n";

    // A outra rodada existe? Então diz como chegar nela.
    $outra = $rodada === 1 ? 2 : 1;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM draft_order WHERE draft_session_id = ? AND round = ?");
        $st->execute([$sid, $outra]);
        if ((int)$st->fetchColumn() > 0) {
            $txt .= "\n_Use */draft {$liga} {$outra}* pra ver a {$outra}ª rodada._";
        }
    } catch (Throwable $e) { /* o rodapé é conforto */ }

    return $txt;
}

/**
 * ATÉ QUE ESCOLHA O DRAFT VIRA NOTÍCIA NO GRUPO.
 *
 * As primeiras picks são o que a liga inteira acompanha; da décima em diante o
 * interesse cai e o grupo vira mural de log. Dez é o corte pedido, e é o
 * bastante pra cobrir a loteria inteira do topo.
 */
const DRAFT_BOT_ANUNCIA_ATE = 10;

/**
 * Anuncia no Chat Off da liga que uma escolha foi feita.
 *
 * Só a 1ª rodada e só até a décima escolha. A mensagem é curta de propósito:
 * numa sequência de dez, cada uma precisa ser lida de relance.
 *
 * Nunca lança e nunca bloqueia: o draft não pode parar porque o bot está
 * desligado ou o grupo não foi configurado.
 */
function draftBotAnunciarEscolha(PDO $pdo, int $sessionId, int $round, int $pickPosition): void
{
    if ($round !== 1 || $pickPosition < 1 || $pickPosition > DRAFT_BOT_ANUNCIA_ATE) return;

    try {
        require_once __DIR__ . '/whatsapp.php';
        require_once __DIR__ . '/leilao_bot.php';   // leilaoBotGrupoDaLiga()

        /* Lê a escolha do banco em vez de receber os nomes por parâmetro: quem
           chama já gravou, e assim a mensagem sai do mesmo lugar que a tela
           mostra — se divergirem, é a mensagem que está errada. */
        // Sem posição nem overall: o anúncio conta QUEM foi escolhido e em
        // que número, e a ficha do jogador é justamente o que a liga não quer
        // ver vazando no grupo no meio do draft.
        $st = $pdo->prepare('SELECT CONCAT(t.city, " ", t.name) AS time, t.league,
                                    dp.name AS jogador
                               FROM draft_order o
                               JOIN teams t ON t.id = o.team_id
                               JOIN draft_pool dp ON dp.id = o.picked_player_id
                              WHERE o.draft_session_id = ? AND o.round = ? AND o.pick_position = ?
                              LIMIT 1');
        $st->execute([$sessionId, $round, $pickPosition]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if (!$e) return;

        $grupo = botGrupoDaCerimonia($pdo, (string)$e["league"]);
        if (!$grupo) return;

        // "01" e não "1": numa sequência de dez, a largura fixa alinha a
        // leitura e deixa claro que é o número da escolha, não uma contagem.
        $num = str_pad((string)$pickPosition, 2, '0', STR_PAD_LEFT);

        $txt = "🏀 *DRAFT · {$e['league']}*\n\n"
             . "*{$e['time']}* escolheu *{$e['jogador']}* na *{$num}*";

        whatsappEnfileirar($pdo, $grupo, $txt, true, 'draft');
    } catch (Throwable $ex) {
        error_log('[draft_bot] anunciar escolha: ' . $ex->getMessage());
    }
}
