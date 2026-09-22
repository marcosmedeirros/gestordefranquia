<?php
/**
 * "CONFIRMA TEU NÚMERO" — quem acabou de pegar um time precisa ver isto.
 *
 * O bot é como a liga avisa: proposta de troca, vez no draft, leilão abrindo,
 * cobrança de elenco desatualizado. Tudo isso passa pelo WhatsApp, e nada
 * disso chega em quem está com o número errado ou sem número. A pessoa não
 * descobre que está fora — ela só para de receber, e o resto da liga acha que
 * ela sumiu.
 *
 * QUANDO PERGUNTAR. Não é "toda vez que o telefone está vazio": é quando a
 * pessoa ASSUMIU UM TIME depois da última vez que confirmou. Quem chega novo
 * e quem sobe de liga caem os dois aí, e os dois já ficam registrados em
 * `team_gm_historico` ("GM novo", "promovido da ROOKIE") — então o gatilho sai
 * de um fato que o sistema já grava, e não de uma flag nova que alguém teria
 * que lembrar de ligar em cada lugar que troca o dono de um time.
 *
 * E TAMBÉM quando o número simplesmente não serve. São cinco GMs hoje: quatro
 * sem nada e um com um celular que o WhatsApp não acha. Esperar esses cinco
 * mudarem de time pra perguntar seria deixá-los fora do bot por temporadas.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function telefoneBotGarantirColuna(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        if ($pdo->query("SHOW COLUMNS FROM users LIKE 'phone_confirmado_em'")->rowCount() === 0) {
            $pdo->exec('ALTER TABLE users ADD COLUMN phone_confirmado_em DATETIME NULL DEFAULT NULL');
            /* QUEM JÁ ESTÁ COM O NÚMERO BOM NÃO É INCOMODADO.
               Sem esta primeira carimbada, os 121 GMs veriam o popup de uma
               vez — inclusive os 116 cujo número funciona. Ficam de fora só
               os que o bot não alcança, que são justamente quem precisa
               resolver. */
            $st = $pdo->query('SELECT u.id, u.phone FROM users u JOIN teams t ON t.user_id = u.id');
            $up = $pdo->prepare('UPDATE users SET phone_confirmado_em = NOW() WHERE id = ?');
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                if (whatsappNumeroUsavel($u['phone'])['ok']) $up->execute([(int)$u['id']]);
            }
        }
        $ok = true;
    } catch (Throwable $e) {
        error_log('[telefone-bot] coluna: ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

/**
 * Esta pessoa precisa confirmar o número agora?
 *
 * @return array|null null quando está tudo certo. Senão:
 *   ['motivo' => 'novo'|'numero', 'titulo', 'texto', 'phone', 'sugestao']
 */
function telefoneBotPendencia(PDO $pdo, ?array $user): ?array
{
    if (!$user || empty($user['id'])) return null;
    if (!telefoneBotGarantirColuna($pdo)) return null;

    try {
        $st = $pdo->prepare('SELECT u.phone, u.phone_confirmado_em,
                                    (SELECT COUNT(*) FROM teams t WHERE t.user_id = u.id) AS tem_time,
                                    (SELECT MAX(h.criado_em) FROM team_gm_historico h
                                      WHERE h.user_id_novo = u.id) AS assumiu_em
                               FROM users u WHERE u.id = ?');
        $st->execute([(int)$user['id']]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[telefone-bot] pendencia: ' . $e->getMessage());
        return null;
    }
    // Sem time não há bot pra receber nada: quem está na fila de aprovação ou
    // sem cadeira não é interrompido por isto.
    if (!$u || (int)$u['tem_time'] === 0) return null;

    $numero = whatsappNumeroUsavel($u['phone'] ?? null);
    $confirmadoEm = $u['phone_confirmado_em'] ?? null;
    $assumiuEm    = $u['assumiu_em'] ?? null;

    // Pegou um time depois da última confirmação (ou nunca confirmou).
    $assumiuDepois = $assumiuEm !== null
        && ($confirmadoEm === null || strtotime((string)$assumiuEm) > strtotime((string)$confirmadoEm));

    /* DOIS MOTIVOS, E BASTA UM. Ou a pessoa acabou de pegar um time — e aí a
       pergunta é "esse número ainda é o teu?" —, ou o número não serve pro
       bot, e aí não importa há quanto tempo ela confirmou: enquanto estiver
       quebrado, ela não recebe nada e precisa saber.

       Tinha uma terceira condição aqui que anulava a segunda ("já confirmou
       uma vez, não pergunta mais"). Ela derrubava justamente o caso que mais
       importa: quem confirmou e depois ficou com o número errado saía da fila
       de pendências e seguia sem receber, em silêncio. */
    if (!$assumiuDepois && $numero['ok']) return null;

    $temNumero = trim((string)($u['phone'] ?? '')) !== '';
    return [
        'motivo'   => $assumiuDepois ? 'novo' : 'numero',
        'titulo'   => $assumiuDepois ? 'Bem-vindo! Confirma teu WhatsApp?' : 'Teu WhatsApp não está funcionando',
        'texto'    => $assumiuDepois
            ? 'É por ele que chegam as propostas de troca, a tua vez no draft e os avisos da liga. '
            . ($temNumero ? 'Confere se o número abaixo é o teu.' : 'Falta cadastrar o teu número.')
            : ($numero['motivo'] ?: 'O número cadastrado não é válido.')
              . ' Enquanto isso, o bot não consegue te avisar de nada.',
        'phone'    => $u['phone'] ?? '',
        'sugestao' => $numero['sugestao'] ?? null,
    ];
}

/** "Esse é o meu número mesmo" — para de perguntar. */
function telefoneBotConfirmar(PDO $pdo, int $userId): bool
{
    if ($userId <= 0 || !telefoneBotGarantirColuna($pdo)) return false;
    try {
        $pdo->prepare('UPDATE users SET phone_confirmado_em = NOW() WHERE id = ?')->execute([$userId]);
        return true;
    } catch (Throwable $e) {
        error_log('[telefone-bot] confirmar: ' . $e->getMessage());
        return false;
    }
}
