<?php
/**
 * O MODO PROFESSOR — o privado do bot pra quem ensina.
 *
 * Nos grupos o bot aprende no meio da conversa, e isso tem limite: ensinar
 * vinte apelidos ou acertar o jeito de ele responder, no grupo, é poluir a
 * conversa de todo mundo. Pedido do dono da liga (15/09/2026): liberar o
 * privado pra dois contatos, que vão populando a memória e dizendo como ele
 * deve agir.
 *
 * SÓ ESTES DOIS, PELO ID DE USUÁRIO, e não por telefone escrito aqui: o número
 * sai do cadastro, então trocar de chip não pede mudança de código, e nenhum
 * telefone fica no repositório.
 *
 * Qualquer outro número no privado continua como antes: só /leilao.
 */

require_once __DIR__ . '/duvida_conversas.php';

/** users.id dos professores: Marcos Medeiros (1) e Kleberson Barreto Costa (35). */
const DUVIDA_PROFESSORES = [1, 35];

/** Mensagens por minuto que um professor manda antes do freio. */
const DUVIDA_PROFESSOR_FREIO = 6;

/** O tipo das respostas na fila — separa o privado dos professores do uso nos grupos. */
const DUVIDA_PROFESSOR_TIPO = 'professor';

/**
 * O professor dono deste número, ou null.
 *
 * A mesma regra de casar telefone do resto do bot (wcAcharPeloTelefone):
 * número inteiro primeiro e, sem ninguém, os últimos 8 dígitos. LID não traz
 * telefone nenhum — aí não há como saber quem é, e não se atende.
 */
function duvidaProfessorDoNumero(PDO $pdo, string $jid): ?array
{
    if ($jid === '' || str_contains($jid, '@lid')) return null;
    $digitos = preg_replace('/\D+/', '', explode('@', $jid)[0]);
    if (strlen($digitos) < 8) return null;

    $ids = implode(',', array_map('intval', DUVIDA_PROFESSORES));
    try {
        $users = $pdo->query("SELECT id, name, phone, league FROM users
                               WHERE id IN ({$ids}) AND phone IS NOT NULL AND phone <> ''")
                     ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[duvida/professor] cadastro: ' . $e->getMessage());
        return null;
    }

    $so = fn(array $u): string => preg_replace('/\D+/', '', (string)$u['phone']);
    $achado = null;
    foreach ($users as $u) {
        if ($so($u) === $digitos) { $achado = $u; break; }
    }
    if (!$achado) {
        $fim = substr($digitos, -8);
        foreach ($users as $u) {
            $d = $so($u);
            if (strlen($d) >= 8 && substr($d, -8) === $fim) { $achado = $u; break; }
        }
    }
    if (!$achado) return null;

    $nome = trim((string)$achado['name']);
    $liga = strtoupper((string)($achado['league'] ?? ''));
    return [
        'user_id'  => (int)$achado['id'],
        'nome'     => $nome,
        'primeiro' => preg_split('/\s+/', $nome)[0],
        'liga'     => in_array($liga, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true) ? $liga : 'ELITE',
    ];
}

/**
 * Responde uma mensagem do professor no privado.
 *
 * É o /duvida de sempre — as mesmas ferramentas, os mesmos dados — com três
 * diferenças: pode guardar ORIENTAÇÃO (como o bot deve responder, e não só
 * apelido), pode ver o que está guardado, e tem mais fôlego (mensagem maior e
 * mais rodadas), porque ensinar costuma vir em lote.
 *
 * O fio da conversa é o próprio privado: "e o outro apelido?" chega com a
 * troca anterior, igual acontece no grupo.
 */
function duvidaProfessorResponder(PDO $pdo, string $texto, array $prof, string $jid): string
{
    require_once __DIR__ . '/edital_ia.php';
    if (!editalIaLigada()) return '🎓 O modo professor precisa da IA ligada, e ela está desligada agora.';

    // Quem digitar /duvida por hábito não precisa: aqui tudo é conversa.
    $texto = trim(preg_replace('~^/duvida\b~iu', '', trim($texto)));
    if ($texto === '') return '';

    // Com time cadastrado, o "meu time" dele funciona como no grupo.
    $quem = function_exists('wcQuemPerguntou') ? wcQuemPerguntou($pdo, $jid, $prof['liga']) : null;
    $quem = array_merge(
        $quem ?? ['nome' => $prof['nome'], 'primeiro' => $prof['primeiro'], 'time' => '', 'liga' => $prof['liga'], 'team_id' => 0],
        ['professor' => true]
    );
    $liga = in_array($quem['liga'] ?? '', ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true) ? $quem['liga'] : $prof['liga'];

    $historico = duvidaConversaHistorico($pdo, $jid, $jid);
    $r = editalIaPerguntar($pdo, $liga, $texto, $quem, null, $historico);
    if (!$r['ok']) return '🎓 ' . $r['erro'];

    duvidaConversaGravar($pdo, $liga, $jid, $jid, $prof['nome'], $texto, $r['resposta'], DUVIDA_PROFESSOR_TIPO);
    return '🎓 ' . $r['resposta'];
}
