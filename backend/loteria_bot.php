<?php
/**
 * A LOTERIA NO GRUPO, ESCOLHA POR ESCOLHA.
 *
 * A cerimônia acontece na tela de quem conduz e, desde a transmissão, também
 * na tela de quem abriu a página. Quem não estava com o site aberto na hora
 * não fica sabendo de nada — e é a maioria: a liga vive no WhatsApp.
 *
 * Cada pick revelada vira uma mensagem no grupo daquela liga, no momento em
 * que é revelada — venha da cerimônia ao vivo ou do ajuste manual. Os dois
 * caminhos passam pelo mesmo `lottery_revelar`, e o texto é o mesmo: pra quem
 * lê no grupo, o fato é um só — a escolha saiu.
 */

require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/push.php';       // sendPushToLeague()
require_once __DIR__ . '/leilao_bot.php';   // leilaoBotGrupoDaLiga()

/**
 * A ordem no ar de uma sessão, e a liga dela.
 *
 * @return array{liga:string,ordem:array}|null
 */
function loteriaBotTransmissao(PDO $pdo, int $sessionId): ?array
{
    try {
        $st = $pdo->prepare('SELECT league, ordem FROM lottery_broadcast WHERE draft_session_id = ?');
        $st->execute([$sessionId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $ordem = json_decode((string)$r['ordem'], true);
        if (!is_array($ordem) || !$ordem) return null;
        return ['liga' => (string)$r['league'], 'ordem' => $ordem];
    } catch (Throwable $e) {
        error_log('[loteria_bot] transmissao: ' . $e->getMessage());
        return null;
    }
}

/**
 * O time de uma posição da ordem.
 *
 * A ordem vem do sorteio como lista de ids na sequência das escolhas, então a
 * posição 1 é o índice 0. Alguns fluxos gravam objetos em vez de ids crus —
 * daí o `is_array`.
 */
function loteriaBotTimeDaPosicao(PDO $pdo, array $ordem, int $posicao): ?string
{
    $d = loteriaBotDadosDaPosicao($pdo, $ordem, $posicao);
    return $d['nome'] ?? null;
}

/**
 * Quem escolhe naquela posição e, se a pick foi trocada, de quem ela veio.
 *
 * A ordem transmitida já vem resolvida: `team_id` é QUEM ESCOLHE, e o campo
 * `origin_name` guarda o time da campanha quando os dois diferem. É a mesma
 * informação que a urna e o quadro da revelação mostram — sem ela, o grupo
 * leria o nome de quem leva a pick sem entender por que ele apareceu ali.
 *
 * @return array{nome:?string,via:?string}
 */
function loteriaBotDadosDaPosicao(PDO $pdo, array $ordem, int $posicao): array
{
    $vazio = ['nome' => null, 'via' => null];
    $item = $ordem[$posicao - 1] ?? null;
    if ($item === null) return $vazio;

    // Formato antigo: a ordem era só uma lista de ids.
    if (!is_array($item)) {
        $nome = loteriaBotNomeDoTime($pdo, (int)$item);
        return ['nome' => $nome, 'via' => null];
    }

    $teamId = (int)($item['team_id'] ?? $item['id'] ?? 0);
    $nome = trim((string)($item['team_name'] ?? '')) ?: loteriaBotNomeDoTime($pdo, $teamId);
    if ($nome === null || $nome === '') return $vazio;

    $via = null;
    if (!empty($item['is_swap'])) {
        $via = trim((string)($item['origin_name'] ?? ''));
        if ($via === '' && !empty($item['origin_team_id'])) {
            $via = loteriaBotNomeDoTime($pdo, (int)$item['origin_team_id']);
        }
        if ($via === '') $via = null;
    }
    return ['nome' => $nome, 'via' => $via];
}

/** O nome completo de um time, ou null. */
function loteriaBotNomeDoTime(PDO $pdo, int $teamId): ?string
{
    if ($teamId <= 0) return null;
    try {
        $st = $pdo->prepare('SELECT CONCAT(city, " ", name) FROM teams WHERE id = ?');
        $st->execute([$teamId]);
        $nome = trim((string)($st->fetchColumn() ?: ''));
        return $nome !== '' ? $nome : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Anuncia uma escolha revelada.
 *
 * Nunca lança e nunca bloqueia: a cerimônia não pode parar porque o bot está
 * desligado ou o grupo não foi configurado. `whatsappEnfileirar` já devolve
 * false em silêncio quando o bot está off.
 *
 * O TEXTO É O MESMO venha do sorteio ao vivo ou do ajuste manual. Pra quem lê
 * no grupo, as duas coisas são o mesmo fato — a escolha saiu. Distinguir só
 * exporia um detalhe de bastidor que não muda nada pra liga.
 */
function loteriaBotAnunciarEscolha(PDO $pdo, int $sessionId, int $posicao): void
{
    try {
        $t = loteriaBotTransmissao($pdo, $sessionId);
        if (!$t) return;

        $d = loteriaBotDadosDaPosicao($pdo, $t['ordem'], $posicao);
        $time = $d['nome'];
        if ($time === null) return;

        $grupo = leilaoBotGrupoDaLiga($pdo, $t['liga']);
        if (!$grupo) return;

        $total = count($t['ordem']);
        // Pick trocada: quem le precisa saber de onde ela veio, senao o nome
        // aparece numa posicao que a campanha dele nao explica.
        $via = $d['via'] ? "\n_via {$d['via']}_" : '';

        /* A ÚLTIMA ESCOLHA MERECE OUTRO TEXTO. Anunciar "escolha 30 de 30"
           igual às outras deixaria o fim da cerimônia passar batido, e é o
           momento que a liga inteira estava esperando. */
        $ehPrimeira = $posicao === 1;
        $ehUltima   = $posicao === $total;

        if ($ehPrimeira) {
            $txt = "🎲 *LOTERIA · {$t['liga']}*\n\n"
                 . "🥇 A *primeira escolha* do draft é do *{$time}*!" . $via;
        } elseif ($ehUltima) {
            $txt = "🎲 *LOTERIA · {$t['liga']}*\n\n"
                 . "Escolha *#{$posicao}* — *{$time}*{$via}\n\n"
                 . '_Ordem completa. A loteria acabou._';
        } else {
            $txt = "🎲 *LOTERIA · {$t['liga']}*\n\n"
                 . "Escolha *#{$posicao}* de {$total} — *{$time}*" . $via;
        }

        whatsappEnfileirar($pdo, $grupo, $txt, true, 'loteria');
    } catch (Throwable $e) {
        // Avisar é acessório: a loteria vale com ou sem mensagem no grupo.
        error_log('[loteria_bot] anunciar: ' . $e->getMessage());
    }
}

/**
 * O mesmo aviso, por push, pra quem tem time na liga.
 *
 * Separado do WhatsApp de propósito: são canais com falhas independentes — o
 * bot pode estar desligado e o push funcionando, ou o contrário. Um try/catch
 * só faria a falha de um levar o outro junto.
 *
 * O texto é mais curto que o do grupo: push é notificação de celular, lida de
 * relance na tela bloqueada, e não tem onde caber parágrafo.
 */
function loteriaPushEscolha(PDO $pdo, int $sessionId, int $posicao): void
{
    try {
        $t = loteriaBotTransmissao($pdo, $sessionId);
        if (!$t) return;

        $d = loteriaBotDadosDaPosicao($pdo, $t['ordem'], $posicao);
        $time = $d['nome'];
        if ($time === null) return;

        $total = count($t['ordem']);
        // No push o "via" entra entre parênteses, na mesma linha: não há espaço
        // pra segunda linha numa notificação de celular.
        $via = $d['via'] ? " (via {$d['via']})" : '';

        $titulo = $posicao === 1 ? '🥇 A primeira escolha saiu!' : '🎲 Loteria · ' . $t['liga'];
        $corpo  = $posicao === 1
            ? "{$time} escolhe primeiro no draft{$via}."
            : ($posicao === $total
                ? "Escolha #{$posicao} — {$time}{$via}. A loteria acabou."
                : "Escolha #{$posicao} de {$total} — {$time}{$via}");

        sendPushToLeague($pdo, $t['liga'], [
            'title'      => $titulo,
            'body'       => $corpo,
            'url'        => '/lottery.php?liga=' . urlencode($t['liga']),
            // Uma chave por escolha: sem isso o celular junta os avisos e
            // sobrescreve o anterior, e a liga só veria a última pick.
            'primaryKey' => 'loteria_' . $sessionId . '_' . $posicao,
        ], 'loteria');
    } catch (Throwable $e) {
        error_log('[loteria_bot] push: ' . $e->getMessage());
    }
}
