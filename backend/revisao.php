<?php
/**
 * CONFERÊNCIA DE OFF-SEASON — o GM arruma o próprio time e confirma.
 *
 * Nasceu em 26/09/2026, depois que o banco caiu e ~34h de trocas voltaram a
 * ser reconstruídas a partir do que cada GM lembrava no WhatsApp. O gargalo
 * não foi aplicar: foi descobrir o que estava errado. Aqui cada GM olha o
 * próprio elenco e as próprias picks, corrige o que estiver fora do lugar e
 * marca "meu time está OK" — e o admin vê num painel quem já passou.
 *
 * Só NEXT por enquanto: a ELITE já foi conferida à mão.
 */

require_once __DIR__ . '/db.php';

/** As ligas que têm conferência aberta. A tela troca entre elas por aba. */
const REVISAO_LIGAS = ['NEXT', 'ELITE'];

/**
 * A liga que esta requisição está conferindo.
 *
 * Nasceu como constante fixa em 'NEXT' e virou isto quando a ELITE também
 * precisou da tela. Fica num estático em vez de sair passando a liga por
 * quinze assinaturas — cada requisição olha uma liga só.
 */
function revisaoLiga(?string $nova = null): string
{
    static $liga = 'NEXT';
    if ($nova !== null) {
        $up = strtoupper(trim($nova));
        if (in_array($up, REVISAO_LIGAS, true)) $liga = $up;
    }
    return $liga;
}

/** O cap da ELITE é folha salarial; o das outras, soma de OVR. */
function revisaoUsaSalario(PDO $pdo, ?string $liga = null): bool
{
    $liga = $liga ?: revisaoLiga();
    static $cache = [];
    if (isset($cache[$liga])) return $cache[$liga];
    $st = $pdo->prepare("SELECT cap_mode FROM league_settings WHERE league = ?");
    $st->execute([$liga]);
    return $cache[$liga] = (strtolower((string)$st->fetchColumn()) === 'salary');
}

/**
 * JANELA ABERTA — qualquer GM da liga mexe em qualquer time.
 *
 * Decidido pelo dono em 26/09/2026: enquanto os elencos e as picks não
 * estiverem todos de pé, prender cada um ao próprio time só atrasa, porque
 * boa parte das correções é "esse jogador é meu mas está no time do fulano".
 * Todo movimento fica no revisao_log com o nome de quem fez.
 *
 * Pra fechar a janela quando terminar: trocar para false. A tela volta a
 * deixar cada GM só no próprio time e o admin continua com tudo.
 */
const REVISAO_ABERTA = true;

/** As duas tabelas nascem sozinhas — não existe migration pra isto ainda. */
function revisaoGarantirTabelas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS revisao_ok (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        team_id       INT NOT NULL,
        user_id       INT NULL,
        confirmado_em DATETIME NOT NULL,
        UNIQUE KEY uniq_team (team_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS revisao_log (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        team_id   INT NOT NULL,
        user_id   INT NULL,
        acao      VARCHAR(30) NOT NULL,
        detalhe   VARCHAR(400) NOT NULL,
        criado_em DATETIME NOT NULL,
        KEY idx_team (team_id),
        KEY idx_criado (criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function revisaoNomeTime(PDO $pdo, int $teamId): string
{
    static $cache = [];
    if (isset($cache[$teamId])) return $cache[$teamId];
    $st = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(city,''),' ',name)) n FROM teams WHERE id = ?");
    $st->execute([$teamId]);
    return $cache[$teamId] = (string)($st->fetchColumn() ?: "#$teamId");
}

/** Toda mexida registra quem fez, e derruba o "OK" dos times envolvidos. */
function revisaoRegistrar(PDO $pdo, int $teamId, ?int $userId, string $acao, string $detalhe,
                          array $tambemDesmarca = []): void
{
    revisaoGarantirTabelas($pdo);
    $st = $pdo->prepare("INSERT INTO revisao_log (team_id, user_id, acao, detalhe, criado_em)
                         VALUES (?,?,?,?,NOW())");
    $st->execute([$teamId, $userId, $acao, mb_substr($detalhe, 0, 400)]);

    /* Confirmar é o último passo: se o elenco mudou depois, a conferência
       daquele time deixa de valer — inclusive a do time do outro lado. */
    $ids = array_values(array_unique(array_filter(array_merge([$teamId], $tambemDesmarca))));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM revisao_ok WHERE team_id IN ($in)")->execute($ids);
    }
}

/** Os times da liga, pro select de destino. */
function revisaoTimes(PDO $pdo): array
{
    $st = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(city,''),' ',name)) nome
                           FROM teams WHERE league = ?
                       ORDER BY TRIM(CONCAT(COALESCE(city,''),' ',name))");
    $st->execute([revisaoLiga()]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function revisaoElenco(PDO $pdo, int $teamId): array
{
    $st = $pdo->prepare("SELECT id, name, ovr, age, position, secondary_position, role, was_traded
                           FROM players WHERE team_id = ?
                       ORDER BY FIELD(role,'Titular','Banco','Outro','G-League'), ovr DESC, name");
    $st->execute([$teamId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * O ANO DA TEMPORADA EM CURSO. Serve pra cortar as picks antigas: pick de
 * draft que já passou só polui a tela de conferência.
 *
 * Mesma conta de trades.php — start_year do sprint mais o número da
 * temporada. Quando não dá pra calcular, devolve 0 e a tela mostra tudo,
 * que é melhor que esconder pick de verdade por causa de config faltando.
 */
function revisaoAnoAtual(PDO $pdo): int
{
    static $ano = null;
    if ($ano !== null) return $ano;
    try {
        $st = $pdo->prepare("SELECT s.season_number, s.year, sp.start_year
                               FROM seasons s
                          LEFT JOIN sprints sp ON sp.id = s.sprint_id
                              WHERE s.league = ?
                                AND (s.status IS NULL OR s.status NOT IN ('completed'))
                           ORDER BY s.created_at DESC LIMIT 1");
        $st->execute([revisaoLiga()]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r && isset($r['start_year'], $r['season_number'])) {
            return $ano = (int)$r['start_year'] + (int)$r['season_number'] - 1;
        }
        if ($r && !empty($r['year'])) return $ano = (int)$r['year'];
    } catch (Throwable $e) {
        error_log('[revisaoAnoAtual] ' . $e->getMessage());
    }
    return $ano = 0;
}

function revisaoPicks(PDO $pdo, int $teamId): array
{
    $ano = revisaoAnoAtual($pdo);
    $filtro = $ano > 0 ? ' AND p.season_year >= ' . $ano : '';
    $st = $pdo->prepare("SELECT p.id, p.season_year, p.round, p.swap_type,
                                p.original_team_id,
                                TRIM(CONCAT(COALESCE(o.city,''),' ',o.name)) origem
                           FROM picks p
                           JOIN teams o ON o.id = p.original_team_id
                          WHERE p.team_id = ?$filtro
                       ORDER BY p.season_year, p.round,
                                TRIM(CONCAT(COALESCE(o.city,''),' ',o.name))");
    $st->execute([$teamId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Tudo de uma vez: os times da liga, cada um com elenco, picks e situação. */
function revisaoTudo(PDO $pdo): array
{
    $faixa = revisaoFaixaCap($pdo);
    $st = $pdo->prepare("SELECT t.id, TRIM(CONCAT(COALESCE(t.city,''),' ',t.name)) nome,
                                u.name AS gm,
                                (SELECT confirmado_em FROM revisao_ok WHERE team_id = t.id) ok_em
                           FROM teams t
                      LEFT JOIN users u ON u.id = t.user_id
                          WHERE t.league = ?
                       ORDER BY TRIM(CONCAT(COALESCE(t.city,''),' ',t.name))");
    $st->execute([revisaoLiga()]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $id = (int)$t['id'];
        $p  = revisaoPendencias($pdo, $id);
        $t['elenco']     = revisaoElenco($pdo, $id);
        $t['picks']      = revisaoPicks($pdo, $id);
        $t['qtd']        = $p['qtd'];
        $t['cap']        = $p['cap'];
        $t['pendencias'] = $p['itens'];
        $out[] = $t;
    }
    return ['cap_min' => $faixa['min'], 'cap_max' => $faixa['max'],
            'liga' => revisaoLiga(), 'ligas' => REVISAO_LIGAS,
            'unidade' => revisaoUsaSalario($pdo) ? 'M' : '',
            'ano' => revisaoAnoAtual($pdo), 'times' => $out];
}

/**
 * O CAP DO TIME, na régua da liga dele.
 *
 * ELITE: folha salarial já com o Cap Flex descontado — a mesma conta que a
 * tela de Salário Cap mostra, tirada de getTeamCapSummary().
 * Nas outras: soma do OVR dos CAP_TOP_N melhores, não do elenco todo. Essa
 * parte está repetida aqui de propósito, porque helpers.php nem sempre está
 * carregado na API.
 */
function revisaoCap(PDO $pdo, int $teamId): int
{
    if (revisaoUsaSalario($pdo)) {
        try {
            require_once __DIR__ . '/salary_cap.php';
            $s = getTeamCapSummary($pdo, $teamId);
            return (int)($s['payroll_after_flex'] ?? $s['payroll'] ?? 0);
        } catch (Throwable $e) {
            error_log('[revisaoCap salario] ' . $e->getMessage());
            return 0;
        }
    }
    $n = defined('CAP_TOP_N') ? (int)CAP_TOP_N : 10;
    $st = $pdo->prepare("SELECT COALESCE(SUM(ovr),0) FROM
                          (SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT $n) x");
    $st->execute([$teamId]);
    return (int)$st->fetchColumn();
}

function revisaoFaixaCap(PDO $pdo): array
{
    $st = $pdo->prepare("SELECT cap_min, cap_max FROM league_settings WHERE league = ?");
    $st->execute([revisaoLiga()]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['min' => (int)($r['cap_min'] ?? 0), 'max' => (int)($r['cap_max'] ?? 0)];
}

/** O que impede o time de estar regular, em texto curto pro GM ler. */
function revisaoPendencias(PDO $pdo, int $teamId): array
{
    $faixa = revisaoFaixaCap($pdo);
    $cap   = revisaoCap($pdo, $teamId);
    $st    = $pdo->prepare("SELECT COUNT(*) FROM players WHERE team_id = ?");
    $st->execute([$teamId]);
    $qtd = (int)$st->fetchColumn();

    $lista = [];
    if ($qtd > 15) $lista[] = ($qtd - 15) . ' jogador' . ($qtd - 15 > 1 ? 'es' : '') . ' a mais (máximo 15)';
    if ($qtd < 13) $lista[] = 'falta' . (13 - $qtd > 1 ? 'm' : '') . ' ' . (13 - $qtd)
                            . ' jogador' . (13 - $qtd > 1 ? 'es' : '') . ' (mínimo 13)';
    // Na ELITE o número é dinheiro e precisa do M; na NEXT é soma de OVR e não tem unidade.
    $u = revisaoUsaSalario($pdo) ? 'M' : '';
    if ($faixa['max'] && $cap > $faixa['max']) $lista[] = ($cap - $faixa['max']) . $u . ' de cap acima do teto de ' . $faixa['max'] . $u;
    if ($faixa['min'] && $cap < $faixa['min']) $lista[] = ($faixa['min'] - $cap) . $u . ' de cap abaixo do piso de ' . $faixa['min'] . $u;
    return ['qtd' => $qtd, 'cap' => $cap, 'faixa' => $faixa, 'itens' => $lista];
}

function revisaoEstaOk(PDO $pdo, int $teamId): ?string
{
    revisaoGarantirTabelas($pdo);
    $st = $pdo->prepare("SELECT confirmado_em FROM revisao_ok WHERE team_id = ?");
    $st->execute([$teamId]);
    $v = $st->fetchColumn();
    return $v ? (string)$v : null;
}

/** O painel do admin: todo mundo da liga, quem confirmou e quem está torto. */
function revisaoPainel(PDO $pdo): array
{
    revisaoGarantirTabelas($pdo);
    $faixa = revisaoFaixaCap($pdo);
    $st = $pdo->prepare("SELECT t.id, TRIM(CONCAT(COALESCE(t.city,''),' ',t.name)) nome,
                                (SELECT COUNT(*) FROM players WHERE team_id = t.id) qtd,
                                (SELECT confirmado_em FROM revisao_ok WHERE team_id = t.id) ok_em,
                                u.name AS gm
                           FROM teams t
                      LEFT JOIN users u ON u.id = t.user_id
                          WHERE t.league = ?
                       ORDER BY TRIM(CONCAT(COALESCE(t.city,''),' ',t.name))");
    $st->execute([revisaoLiga()]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $cap = revisaoCap($pdo, (int)$t['id']);
        $probl = [];
        if ((int)$t['qtd'] > 15) $probl[] = 'elenco ' . $t['qtd'];
        if ((int)$t['qtd'] < 13) $probl[] = 'elenco ' . $t['qtd'];
        if ($faixa['max'] && $cap > $faixa['max']) $probl[] = 'cap ' . $cap;
        if ($faixa['min'] && $cap < $faixa['min']) $probl[] = 'cap ' . $cap;
        $t['cap'] = $cap;
        $t['problemas'] = $probl;
        $out[] = $t;
    }
    return $out;
}
