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
    $item = $ordem[$posicao - 1] ?? null;
    if ($item === null) return null;

    $teamId = is_array($item)
        ? (int)($item['team_id'] ?? $item['id'] ?? 0)
        : (int)$item;
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

        $time = loteriaBotTimeDaPosicao($pdo, $t['ordem'], $posicao);
        if ($time === null) return;

        $grupo = leilaoBotGrupoDaLiga($pdo, $t['liga']);
        if (!$grupo) return;

        $total = count($t['ordem']);

        /* A ÚLTIMA ESCOLHA MERECE OUTRO TEXTO. Anunciar "escolha 30 de 30"
           igual às outras deixaria o fim da cerimônia passar batido, e é o
           momento que a liga inteira estava esperando. */
        $ehPrimeira = $posicao === 1;
        $ehUltima   = $posicao === $total;

        if ($ehPrimeira) {
            $txt = "🎲 *LOTERIA · {$t['liga']}*\n\n"
                 . "🥇 A *primeira escolha* do draft é do *{$time}*!";
        } elseif ($ehUltima) {
            $txt = "🎲 *LOTERIA · {$t['liga']}*\n\n"
                 . "Escolha *#{$posicao}* — *{$time}*\n\n"
                 . '_Ordem completa. A loteria acabou._';
        } else {
            $txt = "🎲 *LOTERIA · {$t['liga']}*\n\n"
                 . "Escolha *#{$posicao}* de {$total} — *{$time}*";
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

        $time = loteriaBotTimeDaPosicao($pdo, $t['ordem'], $posicao);
        if ($time === null) return;

        $total = count($t['ordem']);
        $titulo = $posicao === 1 ? '🥇 A primeira escolha saiu!' : '🎲 Loteria · ' . $t['liga'];
        $corpo  = $posicao === 1
            ? "{$time} escolhe primeiro no draft."
            : ($posicao === $total
                ? "Escolha #{$posicao} — {$time}. A loteria acabou."
                : "Escolha #{$posicao} de {$total} — {$time}");

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
