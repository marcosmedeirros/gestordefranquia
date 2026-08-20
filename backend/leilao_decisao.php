<?php
/**
 * A decisão sobre uma proposta de leilão: escolher ou recusar.
 *
 * Fica fora de api/leilao.php porque aquele arquivo é endpoint completo —
 * confere sessão e dá exit no topo, então incluí-lo a partir do bot mataria
 * o processo. E a regra precisa ser a mesma nos dois lugares: quem decide
 * pelo WhatsApp não pode escapar de nenhuma validação que o site aplica.
 *
 * As duas devolvem array em vez de imprimir. Quem chama escolhe o que fazer
 * com a resposta: o endpoint ecoa como JSON, o bot transforma em conversa.
 *
 * $team_id null com $is_admin true é o caso do bot: lá quem confere se a
 * proposta é mesmo daquele GM é o telefone, antes de chegar aqui.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/leilao_bot.php';

/**
 * Escolhe uma proposta — e é escolha PROVISÓRIA.
 *
 * Nada muda de time agora: o dono pode trocar de ideia quantas vezes quiser
 * enquanto o leilão estiver aberto, e a escolha anterior volta pra
 * 'recusada'. A troca de verdade só acontece em fecharLeilao(), no fim do
 * prazo ou no botão.
 */
function leilaoDecidirAceitar(PDO $pdo, array $body, $team_id, bool $is_admin): array
{
    $proposta_id = $body['proposta_id'] ?? null;
    if (!$proposta_id) {
        return ['success' => false, 'error' => 'ID da proposta nao informado'];
    }

    $stmt = $pdo->prepare("SELECT lp.*, l.player_id, l.team_id as leilao_team_id, l.id as leilao_id, l.data_fim,
                                  l.status as leilao_status,
                                  l.is_temp_player, l.temp_name, l.temp_position, l.temp_age, l.temp_ovr
                           FROM leilao_propostas lp
                           JOIN leilao_jogadores l ON lp.leilao_id = l.id
                           WHERE lp.id = ?");
    $stmt->execute([$proposta_id]);
    $proposta = $stmt->fetch();

    if (!$proposta) {
        return ['success' => false, 'error' => 'Proposta nao encontrada'];
    }

    // Depois de finalizado a troca ja foi executada — nao da para trocar de
    // vencedor sem desfazer o que ja mudou de time.
    if (($proposta['leilao_status'] ?? '') === 'finalizado') {
        return ['success' => false, 'error' => 'Este leilao ja foi fechado — nao da para escolher outra proposta.'];
    }

    if (!$is_admin) {
        if (!empty($proposta['leilao_team_id']) && $proposta['leilao_team_id'] != $team_id) {
            return ['success' => false, 'error' => 'Acesso negado'];
        }
        if (empty($proposta['leilao_team_id'])) {
            return ['success' => false, 'error' => 'Somente admin pode aceitar este leilao sem time de origem'];
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE leilao_propostas SET status = 'recusada'
                       WHERE leilao_id = ? AND status = 'aceita' AND id <> ?")
            ->execute([$proposta['leilao_id'], $proposta_id]);
        $pdo->prepare("UPDATE leilao_propostas SET status = 'aceita' WHERE id = ?")
            ->execute([$proposta_id]);
        $pdo->commit();

        // Sai da fila do WhatsApp: já foi decidida, não há o que perguntar.
        leilaoBotDescartarProposta($pdo, (int)$proposta_id);

        return [
            'success' => true,
            'message' => 'Proposta escolhida. A troca é executada quando o leilão fechar.',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('leilaoDecidirAceitar: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Erro ao escolher a proposta'];
    }
}

/**
 * Recusa uma proposta — menos a que está escolhida.
 *
 * A escolhida só sai do posto quando OUTRA é aceita no lugar. Sem essa
 * trava dava pra recusar a própria escolha e o leilão chegava no fim do
 * prazo sem vencedor nenhum, com propostas boas paradas na mesa.
 */
function leilaoDecidirRecusar(PDO $pdo, array $body, $team_id, bool $is_admin): array
{
    $proposta_id = $body['proposta_id'] ?? null;
    if (!$proposta_id) {
        return ['success' => false, 'error' => 'ID da proposta nao informado'];
    }

    $stmt = $pdo->prepare("SELECT lp.*, l.team_id as leilao_team_id
                           FROM leilao_propostas lp
                           JOIN leilao_jogadores l ON lp.leilao_id = l.id
                           WHERE lp.id = ?");
    $stmt->execute([$proposta_id]);
    $proposta = $stmt->fetch();

    if (!$proposta) {
        return ['success' => false, 'error' => 'Proposta nao encontrada'];
    }

    if (!$is_admin && $proposta['leilao_team_id'] != $team_id) {
        return ['success' => false, 'error' => 'Acesso negado'];
    }

    if (($proposta['status'] ?? '') === 'aceita') {
        return ['success' => false,
                'error' => 'Essa e a proposta escolhida. Pra trocar, aceite outra — ela vira a escolhida e esta volta pra recusada.'];
    }

    $pdo->prepare("UPDATE leilao_propostas SET status = 'recusada' WHERE id = ?")->execute([$proposta_id]);

    // Recusada não pode chegar no WhatsApp depois pedindo decisão.
    leilaoBotDescartarProposta($pdo, (int)$proposta_id);

    return ['success' => true];
}
