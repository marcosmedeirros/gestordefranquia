<?php
/**
 * O TEXTO DO ANÚNCIO DA FREE AGENCY.
 *
 * Fica fora da api/free-agency.php porque aquele arquivo autentica ao ser
 * incluído: importá-lo pra conferir uma mensagem devolve "Nao autorizado" e
 * morre. Aqui não há HTTP nem sessão — entra o resultado da resolução e sai
 * texto —, e a mensagem que a liga inteira lê passa a ser conferível sem
 * disparar nada no Gameplay.
 *
 * \@see api/free-agency.php  faResolverLiga (quem produz o resultado) e
 *                          faAnunciarResolucao (quem envia)
 */

require_once __DIR__ . '/helpers.php';   // ELENCO_MAX, usado nos motivos

/**
 * O TEXTO DO ANÚNCIO DA FREE AGENCY.
 *
 * Separado de quem envia de propósito: aqui não há grupo, fila nem WhatsApp —
 * entra o resultado e sai texto. Dá pra conferir como a mensagem vai ficar sem
 * disparar nada no Gameplay, que é o único jeito de mexer nela com segurança.
 */
function faTextoDaResolucao(string $league, array $res): string
{
    $detalhes  = $res['detalhes'] ?? [];
    $sem       = $res['sem_vencedor'] ?? [];
    $recusados = $res['recusados_det'] ?? [];

    $txt = "🆓 *FREE AGENCY · {$league} — RESOLVIDA*";

    if ($detalhes) {
        $txt .= "\n\n✅ *Contratações*";
        foreach ($detalhes as $d) {
            $valor = !empty($d['elite'])
                ? "{$d['valor']}M"
                : ((int)$d['valor'] === 1 ? '1 moeda' : "{$d['valor']} moedas");
            $txt .= "\n• {$d['jogador']} ({$d['posicao']}, {$d['ovr']}) → *{$d['time']}* · {$valor}";
        }
    }

    /* OS LANCES RECUSADOS, COM O MOTIVO.
     *
     * Sem esta parte o grupo lia "Sem lance válido: Lauri Markkanen" e entendia
     * que ninguém tinha dado lance nele — quando dois times deram, e os dois
     * estavam com o elenco cheio. Quem deu o lance ficava sem saber por que
     * perdeu, e vinha perguntar no privado.
     *
     * Só entram as propostas que ESBARRARAM em alguma regra (cap, elenco cheio,
     * moedas, limite de contratações). Quem perdeu no valor não aparece: perder
     * no lance é o jogo funcionando, e listar o segundo colocado de cada disputa
     * dobraria a mensagem.
     */
    if ($recusados) {
        $txt .= "\n\n⚠️ *Lances que não puderam ser aceitos*";
        /* O motivo já começa com o nome do time ("Fulano está com o elenco
           cheio"), então a linha não repete o time antes dele. */
        foreach (array_slice($recusados, 0, FA_ANUNCIO_MAX_RECUSAS) as $r) {
            $txt .= "\n• {$r['jogador']}: {$r['motivo']}";
        }
        if (count($recusados) > FA_ANUNCIO_MAX_RECUSAS) {
            $quantos = count($recusados) - FA_ANUNCIO_MAX_RECUSAS;
            $txt .= "\n_e mais {$quantos} " . ($quantos === 1 ? 'lance' : 'lances') . " na mesma situação._";
        }
    }

    if ($sem) {
        /* "Ninguém levou" e não "sem lance válido": pode ter havido lance, e o
           motivo está logo acima. */
        $txt .= "\n\n❌ *Ninguém levou:* " . implode(', ', $sem);
    }

    return $txt;
}

/**
 * Quantos lances recusados cabem no anúncio.
 *
 * Doze: numa resolução grande a lista de recusas pode passar de vinte, e uma
 * mensagem de trinta linhas no Gameplay ninguém lê até o fim — o que
 * interessa (as contratações) ficaria soterrado. O resto vira uma linha de
 * resumo, e o admin tem a lista inteira na tela de quem resolveu.
 */
const FA_ANUNCIO_MAX_RECUSAS = 12;

