<?php
/**
 * Proteção de pick — só ELITE, só 1ª rodada.
 *
 * O acordo é: "te dou minha pick de 2031, protegida em top 5". Se ela cair
 * entre 1 e 5 no draft, NÃO passa — fica com o dono original, e quem ia
 * receber leva a pick própria do ano seguinte, sem proteção. Se cair de 6 pra
 * baixo, passa normal e acabou.
 *
 * Daí saem as três regras que a liga definiu:
 *
 *   1. Só dá pra proteger se você tiver sua própria pick do ano seguinte —
 *      é ela que paga a dívida se a protegida não passar. Sem ela, a
 *      proteção seria uma promessa sem lastro.
 *
 *   2. Trocou uma pick protegida, a sua do ano seguinte fica travada pra
 *      troca até o draft resolver. Mesmo motivo: ela pode ter dono.
 *
 *   3. Só 1ª rodada. Proteger 2ª rodada não muda nada de relevante e dobra
 *      os casos.
 *
 * A trava do item 2 é DERIVADA, não um sinalizador gravado: pergunta-se ao
 * banco se existe protegida pendente daquele time. Sinalizador desses
 * dessincroniza na primeira reversão de troca que alguém fizer na mão.
 */

/**
 * As proteções que existem, e até que posição cada uma cobre.
 *
 * Loteria é 14 por decisão da liga — o número da NBA, mesmo a ELITE tendo 32
 * times (aqui o playoff é top 8 de cada conferência, então "fora do playoff"
 * seriam 16). Se um dia mudar, muda aqui e em lugar nenhum mais.
 */
const PICK_PROTECOES = [
    'top3'    => ['rotulo' => 'Top 3',   'ate' => 3],
    'top5'    => ['rotulo' => 'Top 5',   'ate' => 5],
    'top10'   => ['rotulo' => 'Top 10',  'ate' => 10],
    'lottery' => ['rotulo' => 'Loteria', 'ate' => 14],
];

/** Só a ELITE usa proteção de pick. */
function protecaoLigaUsa(?string $liga): bool
{
    return strtoupper(trim((string)$liga)) === 'ELITE';
}

/** O código é uma proteção conhecida? '' e null não são. */
function protecaoValida(?string $cod): bool
{
    return $cod !== null && isset(PICK_PROTECOES[$cod]);
}

/** "Top 5", "Loteria"… ou '' se não for proteção. */
function protecaoRotulo(?string $cod): string
{
    return protecaoValida($cod) ? PICK_PROTECOES[$cod]['rotulo'] : '';
}

/** Até que posição a proteção cobre (0 = não é proteção). */
function protecaoAte(?string $cod): int
{
    return protecaoValida($cod) ? (int)PICK_PROTECOES[$cod]['ate'] : 0;
}

/**
 * O selo da condição de uma pick, pronto pra imprimir.
 *
 * Uma pick pode carregar proteção (só ELITE), swap, ou ser o lastro de uma
 * protegida. As três coisas aparecem em seis telas diferentes; o HTML mora
 * aqui pra não virar seis marcações parecidas que divergem na primeira
 * mudança — foi assim que o rótulo do jogador na trade perdeu a idade de um
 * lado só.
 *
 * A classe .pick-cond está em css/styles.css.
 *
 * $comSwap = false pras telas que já desenham o swap do seu jeito, com o nome
 * do parceiro junto — ali só falta a proteção.
 *
 * Espera a pick com: protection, swap_type e (opcional) protecao_travada.
 */
function protecaoSelos(array $pick, bool $comSwap = true): string
{
    $out = '';

    $prot = $pick['protection'] ?? null;
    if (protecaoValida($prot)) {
        $resolvido = $pick['protection_resultado'] ?? null;
        // Depois do draft o selo diz o que aconteceu — "Protegida Top 5" numa
        // pick já resolvida faria pensar que ainda vale.
        $txt = $resolvido === 'passou' ? 'Passou (era ' . protecaoRotulo($prot) . ')'
             : ($resolvido === 'rolou' ? 'Não passou (' . protecaoRotulo($prot) . ')'
             : 'Protegida ' . protecaoRotulo($prot));
        $out .= '<span class="pick-cond prot" title="Se cair na faixa protegida, a pick não passa e quem receberia leva a do ano seguinte.">'
              . htmlspecialchars($txt) . '</span>';
    }

    $swap = $comSwap ? strtoupper(trim((string)($pick['swap_type'] ?? ''))) : '';
    if ($swap === 'SB' || $swap === 'SW') {
        $out .= '<span class="pick-cond swap" title="'
              . ($swap === 'SB' ? 'Swap: fica com a MELHOR das duas vagas.' : 'Swap: fica com a PIOR das duas vagas.')
              . '">Swap ' . $swap . '</span>';
    }

    $travada = trim((string)($pick['protecao_travada'] ?? ''));
    if ($travada !== '') {
        $out .= '<span class="pick-cond lastro" title="' . htmlspecialchars($travada)
              . '">Pendurada</span>';
    }

    return $out;
}

function ensurePickProtectionSchema(PDO $pdo): void
{
    static $ok = false;
    if ($ok) return;
    try {
        $cols = array_column($pdo->query("SHOW COLUMNS FROM picks")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        if (!in_array('protection', $cols, true)) {
            $pdo->exec("ALTER TABLE picks ADD COLUMN protection VARCHAR(12) NULL AFTER swap_locked");
        }
        // Quando o draft decidiu. Enquanto for NULL a proteção está pendente —
        // e é isso que trava a pick do ano seguinte.
        if (!in_array('protection_resolvida_em', $cols, true)) {
            $pdo->exec("ALTER TABLE picks ADD COLUMN protection_resolvida_em DATETIME NULL AFTER protection");
        }
        // 'passou' ou 'rolou'. Guardado pra tela e pra histórico: sem isso,
        // depois do draft ninguém sabe dizer se a pick foi entregue ou não.
        if (!in_array('protection_resultado', $cols, true)) {
            $pdo->exec("ALTER TABLE picks ADD COLUMN protection_resultado VARCHAR(10) NULL AFTER protection_resolvida_em");
        }
        $ok = true;
    } catch (Throwable $e) {
        error_log('[pick_protection] schema: ' . $e->getMessage());
    }
}

/**
 * A pick própria do time para o ano seguinte ao desta — a que serve de lastro.
 *
 * "Própria" é literal: original_team_id E team_id do time. Pick de outro time
 * que ele comprou não serve, porque o acordo é sobre a campanha DELE.
 */
function protecaoPickDeLastro(PDO $pdo, int $timeId, int $anoDaProtegida): ?array
{
    try {
        $st = $pdo->prepare("SELECT id, season_year, round, team_id, original_team_id, protection
                             FROM picks
                             WHERE original_team_id = ? AND team_id = ?
                               AND round = '1' AND season_year = ?
                             LIMIT 1");
        $st->execute([$timeId, $timeId, $anoDaProtegida + 1]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('[pick_protection] lastro: ' . $e->getMessage());
        return null;
    }
}

/**
 * As picks protegidas de um time que ainda não foram resolvidas pelo draft.
 *
 * Só conta a que JÁ SAIU (team_id != original_team_id): proteção posta numa
 * pick que o time ainda tem não deve nada a ninguém.
 */
function protecaoPendentesDoTime(PDO $pdo, int $timeId): array
{
    try {
        $st = $pdo->prepare("SELECT id, season_year, protection, team_id
                             FROM picks
                             WHERE original_team_id = ? AND round = '1'
                               AND protection IS NOT NULL
                               AND protection_resolvida_em IS NULL
                               AND team_id <> original_team_id");
        $st->execute([$timeId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[pick_protection] pendentes: ' . $e->getMessage());
        return [];
    }
}

/**
 * Esta pick está travada por servir de lastro a uma protegida pendente?
 *
 * Devolve o motivo em texto de tela, ou '' se não está travada.
 */
function protecaoMotivoDaTrava(PDO $pdo, array $pick): string
{
    if ((string)$pick['round'] !== '1') return '';
    $dono = (int)($pick['team_id'] ?? 0);
    $origem = (int)($pick['original_team_id'] ?? 0);
    // Só a pick própria serve de lastro, então só ela pode estar travada.
    if ($dono === 0 || $dono !== $origem) return '';

    foreach (protecaoPendentesDoTime($pdo, $origem) as $p) {
        if ((int)$p['season_year'] + 1 === (int)$pick['season_year']) {
            return 'Travada: paga a pick de ' . (int)$p['season_year']
                 . ' protegida em ' . protecaoRotulo($p['protection'])
                 . ' se ela não passar no draft.';
        }
    }
    return '';
}

/**
 * Todas as picks travadas de uma liga, em um mapa [pick_id => motivo].
 *
 * Existe pra tela de troca não perguntar pick a pick — a lista de picks
 * disponíveis tem dezenas de linhas.
 */
function protecaoTravadasDaLiga(PDO $pdo, string $liga): array
{
    if (!protecaoLigaUsa($liga)) return [];
    ensurePickProtectionSchema($pdo);
    $mapa = [];
    try {
        // Uma consulta: cada protegida pendente trava a pick própria do ano
        // seguinte do mesmo time de origem.
        $st = $pdo->prepare("
            SELECT lastro.id, prot.season_year AS ano_protegido, prot.protection
            FROM picks prot
            JOIN teams t ON t.id = prot.original_team_id AND t.league = ?
            JOIN picks lastro
              ON lastro.original_team_id = prot.original_team_id
             AND lastro.team_id = lastro.original_team_id
             AND lastro.round = '1'
             AND lastro.season_year = prot.season_year + 1
            WHERE prot.round = '1'
              AND prot.protection IS NOT NULL
              AND prot.protection_resolvida_em IS NULL
              AND prot.team_id <> prot.original_team_id");
        $st->execute([strtoupper($liga)]);
        foreach ($st as $r) {
            $mapa[(int)$r['id']] = 'Travada: paga a pick de ' . (int)$r['ano_protegido']
                                 . ' protegida em ' . protecaoRotulo($r['protection'])
                                 . ' se ela não passar no draft.';
        }
    } catch (Throwable $e) {
        error_log('[pick_protection] travadas da liga: ' . $e->getMessage());
    }
    return $mapa;
}

/**
 * Dá pra colocar esta proteção nesta pick?
 *
 * Devolve ['pode' => bool, 'motivo' => string]. O motivo é texto de tela.
 */
function protecaoPodeProteger(PDO $pdo, array $pick, ?string $cod, ?string $liga): array
{
    if (!protecaoValida($cod)) {
        return ['pode' => false, 'motivo' => 'Proteção desconhecida.'];
    }
    if (!protecaoLigaUsa($liga)) {
        return ['pode' => false, 'motivo' => 'Proteção de pick é só na ELITE.'];
    }
    if ((string)$pick['round'] !== '1') {
        return ['pode' => false, 'motivo' => 'Só pick de 1ª rodada pode ser protegida.'];
    }

    $origem = (int)($pick['original_team_id'] ?? 0);
    if ((int)($pick['team_id'] ?? 0) !== $origem) {
        return ['pode' => false,
                'motivo' => 'Só dá pra proteger a sua própria pick — essa veio de outro time.'];
    }

    // A própria pick já ser lastro de outra proteção impede: ela pode acabar
    // indo pro credor daquele acordo, e prometer a mesma pick duas vezes é
    // exatamente o que a trava existe pra evitar.
    $travada = protecaoMotivoDaTrava($pdo, $pick);
    if ($travada !== '') {
        return ['pode' => false, 'motivo' => $travada];
    }

    $lastro = protecaoPickDeLastro($pdo, $origem, (int)$pick['season_year']);
    if (!$lastro) {
        return ['pode' => false,
                'motivo' => 'Você precisa ter a sua pick de 1ª rodada de '
                          . ((int)$pick['season_year'] + 1) . ' pra poder proteger essa.'];
    }
    // O lastro não pode já estar comprometido com outra protegida.
    if (protecaoMotivoDaTrava($pdo, $lastro) !== '') {
        return ['pode' => false,
                'motivo' => 'Sua pick de ' . ((int)$pick['season_year'] + 1)
                          . ' já está comprometida com outra proteção.'];
    }

    return ['pode' => true, 'motivo' => ''];
}

/**
 * Anota uma lista de picks DE UM TIME com o que a tela precisa saber:
 * `protecao_travada` (motivo, ou '') e `pode_proteger` (bool).
 *
 * Trabalha em memória a partir da própria lista — uma consulta só, a do mapa
 * de travas. Perguntar pick a pick custaria três idas ao banco por linha, e a
 * lista tem dezenas.
 *
 * Existe pra tela de troca e o simulador não terem cada um a sua versão da
 * regra: as duas chamam isto.
 *
 * Espera em cada pick: id, round, team_id, original_team_id, season_year.
 */
function protecaoAnotarPicks(PDO $pdo, array $picks, ?string $liga): array
{
    if (!protecaoLigaUsa($liga)) {
        foreach ($picks as &$p) { $p['protecao_travada'] = ''; $p['pode_proteger'] = false; }
        unset($p);
        return $picks;
    }
    ensurePickProtectionSchema($pdo);
    $travadas = protecaoTravadasDaLiga($pdo, (string)$liga);

    // As picks próprias de 1ª rodada que o time tem, por ano — é entre elas
    // que se procura o lastro.
    $propriasPorAno = [];
    foreach ($picks as $p) {
        if ((string)($p['round'] ?? '') !== '1') continue;
        if ((int)($p['team_id'] ?? 0) !== (int)($p['original_team_id'] ?? -1)) continue;
        $propriasPorAno[(int)$p['season_year']] = (int)$p['id'];
    }

    foreach ($picks as &$p) {
        $id = (int)($p['id'] ?? 0);
        $p['protecao_travada'] = $travadas[$id] ?? '';

        $ehPropriaPrimeira = (string)($p['round'] ?? '') === '1'
            && (int)($p['team_id'] ?? 0) === (int)($p['original_team_id'] ?? -1);
        $lastroId = $propriasPorAno[(int)($p['season_year'] ?? 0) + 1] ?? 0;

        $p['pode_proteger'] = $ehPropriaPrimeira
            && $lastroId > 0                       // tem a do ano seguinte
            && $p['protecao_travada'] === ''       // e ela mesma não é lastro
            && !isset($travadas[$lastroId]);       // nem o lastro está comprometido
    }
    unset($p);
    return $picks;
}

/**
 * Tudo que a proteção tem a dizer sobre uma pick numa troca.
 *
 * Devolve o erro em texto de tela, ou '' se está tudo certo. Ponto único
 * porque as picks são validadas em quatro lugares (oferta, pedido, e os dois
 * da multi-trade) e a regra tem que ser a mesma nos quatro.
 */
function protecaoValidarNaTroca(PDO $pdo, array $pick, ?string $protecaoPedida, ?string $liga): string
{
    ensurePickProtectionSchema($pdo);

    // Vale mesmo fora da ELITE: se uma pick ficou travada e a liga mudou de
    // modo depois, soltar a trava calada quebraria o acordo de quem confiou.
    $travada = protecaoMotivoDaTrava($pdo, $pick);
    if ($travada !== '') return $travada;

    if ($protecaoPedida === null || $protecaoPedida === '') return '';

    $check = protecaoPodeProteger($pdo, $pick, $protecaoPedida, $liga);
    return $check['pode'] ? '' : $check['motivo'];
}

/**
 * O draft decidiu: para cada pick protegida daquele ano, ela passa ou rola.
 *
 * Roda depois da ordem estar montada, e uma vez só por pick — o carimbo em
 * protection_resolvida_em é o que garante isso. Rodar de novo não mexe em
 * nada já resolvido.
 *
 * Quem não passa: a pick volta pro dono original e a do ano seguinte vai pro
 * credor, SEM proteção. Não rola de novo — a dívida termina ali, e é por isso
 * que o lastro é uma pick só.
 *
 * Devolve a lista do que aconteceu, pra tela poder contar.
 */
function protecaoResolverNoDraft(PDO $pdo, int $draftSessionId): array
{
    ensurePickProtectionSchema($pdo);
    $feitos = [];

    try {
        $st = $pdo->prepare('SELECT id, season_id, league FROM draft_sessions WHERE id = ?');
        $st->execute([$draftSessionId]);
        $sessao = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sessao || !protecaoLigaUsa($sessao['league'] ?? null)) return [];

        require_once __DIR__ . '/draft_swaps.php';
        $ano = draftAnoDaTemporada($pdo, (int)$sessao['season_id']);
        if ($ano <= 0) return [];

        // Onde caiu a vaga de cada time de origem na 1ª rodada. É a posição
        // da VAGA (original_team_id) que manda: a proteção é sobre a campanha
        // do time de origem, não sobre quem escolhe ali depois das trocas.
        $st = $pdo->prepare("SELECT original_team_id, pick_position FROM draft_order
                             WHERE draft_session_id = ? AND round = 1");
        $st->execute([$draftSessionId]);
        $posicao = [];
        foreach ($st as $r) $posicao[(int)$r['original_team_id']] = (int)$r['pick_position'];
        if (!$posicao) return [];

        $st = $pdo->prepare("SELECT id, original_team_id, team_id, season_year, protection
                             FROM picks
                             WHERE season_year = ? AND round = '1'
                               AND protection IS NOT NULL
                               AND protection_resolvida_em IS NULL");
        $st->execute([$ano]);
        $protegidas = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$protegidas) return [];

        foreach ($protegidas as $p) {
            $origem = (int)$p['original_team_id'];
            $credor = (int)$p['team_id'];
            $pos = $posicao[$origem] ?? 0;
            if ($pos <= 0) continue;              // time fora deste draft

            // Proteção em pick que nunca saiu do dono não decide nada; só
            // limpa, senão fica travando o ano seguinte pra sempre.
            if ($credor === $origem) {
                $pdo->prepare("UPDATE picks SET protection = NULL WHERE id = ?")->execute([(int)$p['id']]);
                continue;
            }

            $passou = $pos > protecaoAte($p['protection']);

            if ($passou) {
                // Cai fora da faixa: a troca vale como combinada, nada muda de
                // dono agora — o credor já é o dono da pick.
                $pdo->prepare("UPDATE picks SET protection_resolvida_em = NOW(),
                                                protection_resultado = 'passou' WHERE id = ?")
                    ->execute([(int)$p['id']]);
                $feitos[] = ['pick' => (int)$p['id'], 'ano' => $ano, 'posicao' => $pos,
                             'protecao' => $p['protection'], 'resultado' => 'passou',
                             'de' => $origem, 'para' => $credor, 'lastro' => null];
                continue;
            }

            // Caiu na faixa: não passa. Volta pro dono e o credor leva a do
            // ano seguinte, limpa de proteção.
            $lastro = protecaoPickDeLastro($pdo, $origem, $ano);
            $pdo->prepare("UPDATE picks SET team_id = ?, last_owner_team_id = ?,
                                            protection_resolvida_em = NOW(),
                                            protection_resultado = 'rolou' WHERE id = ?")
                ->execute([$origem, $credor, (int)$p['id']]);

            if ($lastro) {
                $pdo->prepare("UPDATE picks SET team_id = ?, last_owner_team_id = ?,
                                                auto_generated = 0, protection = NULL WHERE id = ?")
                    ->execute([$credor, $origem, (int)$lastro['id']]);
            }
            // Sem lastro a dívida não tem como ser paga — não inventa pick. O
            // registro sai com lastro null e a tela mostra pra alguém resolver.
            $feitos[] = ['pick' => (int)$p['id'], 'ano' => $ano, 'posicao' => $pos,
                         'protecao' => $p['protection'], 'resultado' => 'rolou',
                         'de' => $origem, 'para' => $credor,
                         'lastro' => $lastro ? (int)$lastro['id'] : null];
        }
    } catch (Throwable $e) {
        error_log('[pick_protection] resolver: ' . $e->getMessage());
    }

    return $feitos;
}
