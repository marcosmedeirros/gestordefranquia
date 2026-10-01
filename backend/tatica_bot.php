<?php
/**
 * /tatica <time> e /minhatatica — a tática escalada, no grupo.
 *
 * ── NASCE DESLIGADO ──────────────────────────────────────────────────
 *
 * TB_LIGADO é false e os dois comandos se comportam como comando que não
 * existe: o roteador devolve null e o bot fica calado, sem "comando
 * desconhecido", sem pista de que a coisa está lá. É de propósito — a liga
 * ainda vai decidir se quer isso, e um comando que responde "em breve" já é
 * um comando no ar. Ligar é trocar o false por true aqui e pôr as duas
 * linhas na wcAjuda().
 *
 * ── A TRAVA É A JANELA, NÃO UM HORÁRIO ───────────────────────────────
 *
 * Enquanto a edição está ABERTA, ninguém vê tática de ninguém. O motivo não
 * é privacidade, é competição: a tática do adversário na mão enquanto ainda
 * dá tempo de montar a sua em cima dela é vantagem que não se desfaz, e o GM
 * que abre o WhatsApp por último ganha de graça. Fechada a edição, ninguém
 * muda mais nada e aí a informação é igual pra todo mundo.
 *
 * A janela é a MESMA que trava a tela do GM (tactic_edit_windows.manual_closed,
 * por liga) — não é um segundo conceito de "fechado" vivendo em paralelo. Se o
 * admin reabrir a edição, estes comandos fecham sozinhos no mesmo instante.
 * @see backend/tatica_janela.php
 *
 * ── A LIGA QUE MANDA É A DO TIME ─────────────────────────────────────
 *
 * Não a do grupo. A ELITE pode estar fechada e a NEXT aberta, e perguntar num
 * grupo pelo time de outra liga tem que respeitar a janela de quem é dono da
 * tática, não a de quem perguntou.
 */

require_once __DIR__ . '/tatica_janela.php';

/** A chave geral. @see o cabeçalho deste arquivo. */
if (!defined('TB_LIGADO')) define('TB_LIGADO', false);

function tbLigado(): bool { return TB_LIGADO; }

/**
 * Os rótulos dos três slots.
 *
 * Cópia curta de TATICA_SLOTS (api/tactics.php): aquele arquivo é um endpoint
 * que responde e dá exit assim que é incluído, então não dá pra requerer só
 * pela constante. São três strings que não mudam desde que a tela virou
 * "Tática 1/2/3".
 */
const TB_SLOTS = ['regular' => 'Tática 1', 'playoffs' => 'Tática 2', 'outra' => 'Tática 3'];

/** Quanto das observações do GM cabe na mensagem antes de virar parede. */
const TB_NOTAS_MAX = 400;

/**
 * A TÁTICA QUE VALE para o time.
 *
 * Mesma ordem de desempate da tela e do admin (@see taticaEmVigor): a marcada
 * como ativa; sem nenhuma ativa, a do slot regular. Repetida aqui pelo mesmo
 * motivo do TB_SLOTS.
 */
function tbTaticaDoTime(PDO $pdo, int $teamId): ?array
{
    $st = $pdo->prepare("SELECT * FROM team_tactics WHERE team_id = ?
                         ORDER BY is_active DESC, (slot = 'regular') DESC LIMIT 1");
    $st->execute([$teamId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** O rótulo legível de uma opção; null quando não há escolha pra mostrar. */
function tbRotulo(string $campo, $valor): ?string
{
    static $mapa = null;
    if ($mapa === null) $mapa = require __DIR__ . '/tatica_opcoes.php';

    $v = trim((string)$valor);
    // "Sem preferência" é o padrão de quem não escolheu — ocupa linha e não
    // diz nada. Fora da mensagem.
    if ($v === '' || $v === 'no_preference') return null;
    return $mapa[$campo][$v] ?? $v;
}

/** nome e posição de cada id pedido, numa consulta só. */
function tbJogadores(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];

    $st = $pdo->prepare('SELECT id, name, position FROM players WHERE id IN ('
                        . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $j) {
        $out[(int)$j['id']] = ['nome' => $j['name'], 'pos' => (string)$j['position']];
    }
    return $out;
}

/** Uma lista de camisas, pulando vaga vazia e jogador que saiu do elenco. */
function tbLista(array $elenco, array $ids): array
{
    $linhas = [];
    foreach ($ids as $id) {
        $j = $elenco[(int)$id] ?? null;
        if (!$j) continue;
        $linhas[] = '• ' . ($j['pos'] !== '' ? $j['pos'] . ' ' : '') . $j['nome'];
    }
    return $linhas;
}

/** O texto da tática, já sabendo que a janela está fechada. */
function tbTexto(PDO $pdo, array $time): string
{
    $nome = trim(($time['city'] ?? '') . ' ' . ($time['name'] ?? ''));
    $t = tbTaticaDoTime($pdo, (int)$time['id']);
    if (!$t) return '*' . $nome . "* ainda não montou tática nenhuma.";

    $elenco = tbJogadores($pdo, [
        $t['starter_1_id'], $t['starter_2_id'], $t['starter_3_id'], $t['starter_4_id'], $t['starter_5_id'],
        $t['bench_1_id'], $t['bench_2_id'], $t['bench_3_id'], $t['gleague_1_id'], $t['gleague_2_id'],
    ]);

    $slot = TB_SLOTS[$t['slot'] ?? 'regular'] ?? 'Tática';
    $txt = '*Tática — ' . $nome . "*\n"
         . '_' . $slot . ($t['is_active'] ? ' · ativa' : ' · nenhuma marcada como ativa') . "_\n";

    $quinteto = tbLista($elenco, [$t['starter_1_id'], $t['starter_2_id'], $t['starter_3_id'],
                                  $t['starter_4_id'], $t['starter_5_id']]);
    if ($quinteto) $txt .= "\n*Quinteto*\n" . implode("\n", $quinteto) . "\n";

    $banco = tbLista($elenco, [$t['bench_1_id'], $t['bench_2_id'], $t['bench_3_id']]);
    if ($banco) $txt .= "\n*Banco*\n" . implode("\n", $banco) . "\n";

    $gleague = tbLista($elenco, [$t['gleague_1_id'], $t['gleague_2_id']]);
    if ($gleague) $txt .= "\n*G-League*\n" . implode("\n", $gleague) . "\n";

    // A linha solta: giro, veteranos, técnico e playbook, só o que foi preenchido.
    $soltos = [];
    if ((int)$t['rotation_players'] > 0) $soltos[] = '*Giro:* ' . (int)$t['rotation_players'] . ' jogadores';
    if ($t['veteran_focus'] !== null && $t['veteran_focus'] !== '') $soltos[] = '*Veteranos:* ' . (int)$t['veteran_focus'];
    if (trim((string)$t['technical_model']) !== '') $soltos[] = '*Técnico:* ' . trim((string)$t['technical_model']);
    if (trim((string)$t['playbook']) !== '') $soltos[] = '*Playbook:* ' . trim((string)$t['playbook']);
    if ($soltos) $txt .= "\n" . implode("\n", $soltos) . "\n";

    $bloco = function (string $titulo, array $pares) use (&$txt) {
        $linhas = [];
        foreach ($pares as $rot => $valor) if ($valor !== null) $linhas[] = $rot . ': ' . $valor;
        if ($linhas) $txt .= "\n*" . $titulo . "*\n" . implode("\n", $linhas) . "\n";
    };

    $bloco('Ataque', [
        'Ritmo'      => tbRotulo('pace', $t['pace']),
        'Estilo'     => tbRotulo('game_style', $t['game_style']),
        'Jogada'     => tbRotulo('offense_style', $t['offense_style']),
        'Rebote of.' => tbRotulo('offensive_rebound', $t['offensive_rebound']),
    ]);
    $bloco('Defesa', [
        'Foco'       => tbRotulo('defensive_focus', $t['defensive_focus']),
        'Agressão'   => tbRotulo('offensive_aggression', $t['offensive_aggression']),
        'Rebote def.' => tbRotulo('defensive_rebound', $t['defensive_rebound']),
    ]);

    /* AS OBSERVAÇÕES ENTRAM. É onde o GM escreve mudança de posição à mão
       ("Dirk PF", "Terry PG/SG"), e isso é metade da tática — deixar de fora
       mostraria um quinteto que não é o que vai pra quadra. Cortadas quando
       passam do tamanho, porque campo livre não tem teto. */
    $notas = trim((string)$t['notes']);
    if ($notas !== '') {
        if (mb_strlen($notas) > TB_NOTAS_MAX) $notas = mb_substr($notas, 0, TB_NOTAS_MAX) . '…';
        $txt .= "\n*Observações do GM*\n" . $notas . "\n";
    }

    return rtrim($txt);
}

/**
 * A resposta dos dois comandos: a trava primeiro, o texto depois.
 *
 * A liga é a do TIME, não a do grupo — @see o cabeçalho.
 */
function tbResponder(PDO $pdo, array $time): string
{
    $liga = (string)($time['league'] ?? '');
    taticaGarantirTabelaJanela($pdo);
    $janela = taticaJanela($pdo, $liga);

    if ($janela['open']) {
        return '🔒 A edição de tática da ' . ($liga !== '' ? $liga : 'liga') . " ainda está aberta.\n"
             . 'Enquanto dá pra mexer, ninguém vê a tática de ninguém. Quando o admin fechar, '
             . 'este comando passa a mostrar.';
    }

    return tbTexto($pdo, $time);
}
