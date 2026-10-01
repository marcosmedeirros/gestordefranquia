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
 * A coluna que marca o que já foi pro Gameplay.
 *
 * Nasceu depois da tabela, então entra por ALTER — e o erro de "já existe" é
 * o caso normal, não uma falha.
 */
function faGarantirColunaAnuncio(PDO $pdo): void
{
    static $ok = false;
    if ($ok) return;
    try {
        if (!$pdo->query("SHOW COLUMNS FROM fa_requests LIKE 'anunciado_em'")->fetch()) {
            $pdo->exec("ALTER TABLE fa_requests ADD COLUMN anunciado_em DATETIME NULL");
            /* O PASSADO JÁ NASCE ANUNCIADO.

               Sem esta linha, o primeiro "Resolver FA" depois do deploy acharia
               novecentos pedidos resolvidos e sem carimbo, e despejaria meses de
               histórico no Gameplay — testando local, a lista já veio com um
               pedido de agosto junto. O carimbo vai com o resolved_at, que é
               quando aquilo de fato aconteceu. */
            $pdo->exec("UPDATE fa_requests SET anunciado_em = resolved_at
                         WHERE resolved_at IS NOT NULL AND anunciado_em IS NULL");
        }
        $ok = true;
    } catch (Throwable $e) {
        error_log('[fa/anuncio] coluna: ' . $e->getMessage());
    }
}

/**
 * TUDO QUE JÁ FOI RESOLVIDO E AINDA NÃO FOI ANUNCIADO.
 *
 * O anúncio saía só com o que AQUELE clique resolveu, e por isso saiu errado
 * na RISE: o admin aprovou sete propostas uma a uma e depois apertou
 * "Resolver FA" — que só encontrou o Markkanen ainda aberto. A mensagem no
 * Gameplay listou o Markkanen e mais nada, como se a FA inteira tivesse dado
 * em nada.
 *
 * Aprovar um a um é o caminho normal do admin e nunca vai anunciar sozinho
 * (seriam sete mensagens no grupo). Então quem anuncia tem que olhar pro que
 * ficou pendente de aviso, e não pro que acabou de acontecer.
 *
 * @return array{contratados:array[], sem_vencedor:string[], ids:int[]}
 */
function faPendenteDeAnuncio(PDO $pdo, string $league): array
{
    faGarantirColunaAnuncio($pdo);

    $st = $pdo->prepare("
        SELECT r.id, r.player_name, r.position, r.ovr, r.status,
               TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS time,
               (SELECT o.amount FROM fa_request_offers o
                 WHERE o.request_id = r.id AND o.status = 'accepted'
              ORDER BY o.amount DESC LIMIT 1) AS valor
          FROM fa_requests r
     LEFT JOIN teams t ON t.id = r.winner_team_id
         WHERE r.league = ? AND r.resolved_at IS NOT NULL AND r.anunciado_em IS NULL
      ORDER BY r.status, (SELECT o.amount FROM fa_request_offers o
                           WHERE o.request_id = r.id AND o.status = 'accepted'
                        ORDER BY o.amount DESC LIMIT 1) DESC, r.id");
    $st->execute([$league]);

    $ehElite = strtoupper($league) === 'ELITE';
    $contratados = []; $sem = []; $ids = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ids[] = (int)$r['id'];
        if ($r['status'] === 'assigned') {
            $contratados[] = [
                'jogador' => $r['player_name'], 'posicao' => $r['position'],
                'ovr' => (int)$r['ovr'], 'time' => $r['time'],
                'valor' => (int)$r['valor'], 'elite' => $ehElite,
            ];
        } else {
            $sem[] = $r['player_name'];
        }
    }
    return ['contratados' => $contratados, 'sem_vencedor' => $sem, 'ids' => $ids];
}

/** Carimba o que acabou de ir pro grupo, pra não sair duas vezes. */
function faMarcarAnunciados(PDO $pdo, array $ids): void
{
    if (!$ids) return;
    faGarantirColunaAnuncio($pdo);
    try {
        $pdo->exec("UPDATE fa_requests SET anunciado_em = NOW() WHERE id IN ("
                   . implode(',', array_map('intval', $ids)) . ")");
    } catch (Throwable $e) {
        error_log('[fa/anuncio] marcar: ' . $e->getMessage());
    }
}
/**
 * O VALOR ESCRITO, do jeito que a liga lê.
 *
 * Na ELITE o lance é salário em milhões; nas outras é moeda. O mesmo número
 * quer dizer coisas diferentes, e escrever "50" seco deixaria a conta ambígua
 * em metade das ligas.
 */
function faValorEscrito(int $valor, bool $ehElite): string
{
    if ($ehElite) return "{$valor}M";
    return $valor === 1 ? '1 moeda' : "{$valor} moedas";
}

/**
 * O AVISO DE UMA CONTRATAÇÃO SÓ, na hora em que o admin aprova.
 *
 * Uma linha e pronto: isto sai no meio da conversa do Gameplay, uma vez por
 * aprovação, e um bloco de cinco linhas por jogador viraria parede. Quem tem
 * lugar pra detalhe é o resumo do fim (faTextoDaResolucao).
 */
function faTextoDaContratacao(string $league, array $d): string
{
    $valor = faValorEscrito((int)$d['valor'], strtoupper($league) === 'ELITE');
    return "🆓 *FREE AGENCY · {$league}*\n"
         . "✅ *{$d['jogador']}* ({$d['posicao']}, {$d['ovr']}) → *{$d['time']}* · {$valor}";
}

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
            $valor = faValorEscrito((int)$d['valor'], !empty($d['elite']));
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

