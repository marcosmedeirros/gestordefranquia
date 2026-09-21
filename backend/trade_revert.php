<?php
/**
 * DESFAZER UMA TROCA DESFAZ O QUE VEIO DEPOIS DELA.
 *
 * Reverter uma troca era uma operação de uma linha só: os ativos voltavam pros
 * donos e pronto. Só que ativo trocado não fica parado. Se o jogador que voltou
 * já tinha sido negociado numa troca posterior, ele não está mais onde deveria
 * — e o código antigo fazia a única coisa que dava pra fazer sozinho: pulava o
 * ativo e avisava "foi negociado novamente, não revertido". A troca terminava
 * desfeita pela metade, e o resto ficava pro admin resolver na mão, sem lista
 * do que faltava.
 *
 * A regra da liga é outra: desfeita a troca, cai tudo que ela alimentou. Se o
 * jogador 1 saiu na troca A e foi pra troca B, a B cai junto — senão a A não
 * tem como voltar ao estado anterior.
 *
 * QUEM PAGA A CONTA: só a troca que o admin mandou desfazer. As arrastadas são
 * devolvidas ao saldo dos times, sempre. Os times delas não fizeram nada —
 * negociaram de boa fé um ativo que estava lá — e cobrar deles o consumo de uma
 * troca que o sistema desfez seria punir quem não errou.
 *
 * A CASCATA É CALCULADA ANTES DE QUALQUER ESCRITA (trRevPlano), pra tela poder
 * mostrar o tamanho do estrago e perguntar. Uma reversão que arrasta seis
 * trocas de quatro times não pode acontecer no clique de confirmar uma.
 *
 * Cobre os dois tipos: a troca de dois times (trades) e a múltipla
 * (multi_trades). Em qualquer uma delas, um movimento é sempre a mesma coisa:
 * um ativo, de onde saiu, pra onde foi, e quando.
 */

require_once __DIR__ . '/helpers.php';

/** Quantas trocas a cascata pode arrastar antes de desistir e pedir a mão. */
const TR_REV_MAX_CASCATA = 12;

/**
 * A liga tem trocas múltiplas ligadas?
 *
 * Checagem própria, e não a `tableExists()` do api/admin.php: aquela mora
 * dentro do endpoint, e este arquivo é chamado também pelo bot e pela linha de
 * comando, onde ela não existe.
 */
function trRevTemMulti(PDO $pdo): bool
{
    static $tem = null;
    if ($tem !== null) return $tem;
    try {
        $tem = (bool)$pdo->query("SHOW TABLES LIKE 'multi_trade_items'")->fetch();
    } catch (Throwable $e) {
        $tem = false;
    }
    return $tem;
}

/**
 * Os movimentos de uma troca, no mesmo formato pros dois tipos.
 *
 * @return array<int, array{player_id:?int, pick_id:?int, origem:int, destino:int}>
 */
function trRevItens(PDO $pdo, string $tipo, int $id): array
{
    if ($tipo === 'multi') {
        // A múltipla já guarda origem e destino em cada item — ela nasceu
        // depois, e com a lição aprendida.
        $st = $pdo->prepare('SELECT player_id, pick_id, from_team_id AS origem, to_team_id AS destino
                               FROM multi_trade_items WHERE trade_id = ?');
        $st->execute([$id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /* Na troca de dois times, o item só diz de que LADO ele saiu (`from_team`);
       quem são os dois lados está na troca. */
    $st = $pdo->prepare("SELECT ti.player_id, ti.pick_id,
                                CASE WHEN ti.from_team THEN t.from_team_id ELSE t.to_team_id END AS origem,
                                CASE WHEN ti.from_team THEN t.to_team_id   ELSE t.from_team_id END AS destino
                           FROM trade_items ti JOIN trades t ON t.id = ti.trade_id
                          WHERE ti.trade_id = ?");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Dados básicos da troca, nos dois tipos. */
function trRevCabecalho(PDO $pdo, string $tipo, int $id): ?array
{
    if ($tipo === 'multi') {
        $st = $pdo->prepare("SELECT mt.id, mt.status, mt.created_at,
                                    COALESCE(mt.updated_at, mt.created_at) AS quando,
                                    COALESCE(mt.league, ct.league) AS league
                               FROM multi_trades mt
                               JOIN teams ct ON ct.id = mt.created_by_team_id
                              WHERE mt.id = ?");
    } else {
        $st = $pdo->prepare("SELECT id, status, created_at,
                                    COALESCE(resolved_at, updated_at, created_at) AS quando,
                                    league, from_team_id, to_team_id
                               FROM trades WHERE id = ?");
    }
    try {
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['tipo'] = $tipo;
        return $r;
    } catch (Throwable $e) {
        error_log('[trade-revert] cabecalho: ' . $e->getMessage());
        return null;
    }
}

/**
 * O QUE ESTAVA NA TROCA, pelo nome.
 *
 * Jogador pelo nome de hoje, com o nome gravado na época como reserva (jogador
 * apagado do elenco ainda precisa aparecer na lista); pick por ano e rodada.
 */
function trRevAtivosLegiveis(PDO $pdo, string $tipo, int $id, int $limite = 4): array
{
    $tabela = $tipo === 'multi' ? 'multi_trade_items' : 'trade_items';
    $nomes = [];

    try {
        $st = $pdo->prepare("SELECT i.player_id, i.player_name, i.pick_id,
                                    p.name AS nome_hoje, pk.season_year, pk.round
                               FROM {$tabela} i
                          LEFT JOIN players p ON p.id = i.player_id
                          LEFT JOIN picks pk ON pk.id = i.pick_id
                              WHERE i.trade_id = ?
                           ORDER BY i.id");
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) {
            if (!empty($it['player_id'])) {
                $n = trim((string)($it['nome_hoje'] ?? '')) ?: trim((string)($it['player_name'] ?? ''));
                if ($n !== '') $nomes[] = $n;
            } elseif (!empty($it['pick_id']) && !empty($it['season_year'])) {
                $nomes[] = $it['season_year'] . ' R' . $it['round'];
            }
        }
    } catch (Throwable $e) {
        error_log('[trade-revert] ativos: ' . $e->getMessage());
    }

    $nomes = array_values(array_unique($nomes));
    if (count($nomes) > $limite) {
        $sobra = count($nomes) - $limite;
        $nomes = array_slice($nomes, 0, $limite);
        $nomes[] = "+{$sobra}";
    }
    return $nomes;
}

/**
 * Como a troca é chamada nas listas e no grupo.
 *
 * Os TIMES e o QUE ESTAVA NELA — nunca o número dela. "Troca #8667" não diz
 * nada a quem está lendo no grupo nem ao admin que precisa reconhecer a
 * negociação que vai cair junto; "Catrinas ↔ Swaneys — Kawhi Leonard, 2030 R1"
 * é a mesma troca, do jeito que a liga se lembra dela.
 */
function trRevTimesRotulo(PDO $pdo, string $tipo, int $id): string
{
    if ($tipo === 'multi') {
        try {
            $st = $pdo->prepare("SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ' ↔ ')
                                   FROM multi_trade_teams mtt JOIN teams t ON t.id = mtt.team_id
                                  WHERE mtt.trade_id = ?");
            $st->execute([$id]);
            $nomes = (string)$st->fetchColumn();
        } catch (Throwable $e) { $nomes = ''; }
        return $nomes !== '' ? $nomes : 'troca múltipla';
    }

    $st = $pdo->prepare("SELECT a.name AS a, b.name AS b FROM trades t
                           JOIN teams a ON a.id = t.from_team_id
                           JOIN teams b ON b.id = t.to_team_id
                          WHERE t.id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return !empty($r['a']) ? "{$r['a']} ↔ {$r['b']}" : 'troca';
}

function trRevRotulo(PDO $pdo, string $tipo, int $id): string
{
    $ativos = trRevAtivosLegiveis($pdo, $tipo, $id);
    return trRevTimesRotulo($pdo, $tipo, $id) . ($ativos ? ' — ' . implode(', ', $ativos) : '');
}

/**
 * O PLANO: que trocas caem junto, e em que ordem.
 *
 * Fecho transitivo, e é assim porque a cascata não para no primeiro nível: a
 * troca B que devolve o jogador 1 também devolve o jogador 2, e se o 2 andou
 * numa troca C, a C entra também. Repete até o conjunto parar de crescer.
 *
 * A ordem de execução é a inversa da cronológica — a mais nova primeiro. É a
 * única que funciona: desfazer a antiga antes da nova tentaria tirar o ativo de
 * um time que já não o tem.
 *
 * NÃO ESCREVE NADA. Quem escreve é trRevExecutar, com este plano na mão.
 */
function trRevPlano(PDO $pdo, string $tipo, int $id): array
{
    $out = ['ok' => false, 'erro' => null, 'base' => null, 'arrastadas' => [], 'ativos' => 0];

    $base = trRevCabecalho($pdo, $tipo, $id);
    if (!$base)                         { $out['erro'] = 'Troca não encontrada.'; return $out; }
    if ($base['status'] !== 'accepted') { $out['erro'] = 'Só dá pra reverter troca aceita.'; return $out; }

    $out['base'] = ['tipo' => $tipo, 'id' => $id, 'rotulo' => trRevRotulo($pdo, $tipo, $id),
                    'quando' => $base['quando']];

    $temMulti = trRevTemMulti($pdo);

    // Chaves dos ativos: "p:123" pra jogador, "k:456" pra pick.
    $ativos = [];
    foreach (trRevItens($pdo, $tipo, $id) as $it) {
        if (!empty($it['player_id'])) $ativos['p:' . (int)$it['player_id']] = true;
        if (!empty($it['pick_id']))   $ativos['k:' . (int)$it['pick_id']]   = true;
    }
    $out['ativos'] = count($ativos);
    if (!$ativos) { $out['ok'] = true; return $out; }   // troca sem itens: nada arrasta

    $vistas = [$tipo . ':' . $id => $base];
    $novas  = true;

    while ($novas) {
        $novas = false;

        $players = [];
        $picks = [];
        foreach (array_keys($ativos) as $k) {
            [$t, $v] = explode(':', $k, 2);
            if ($t === 'p') $players[] = (int)$v; else $picks[] = (int)$v;
        }

        $candidatas = [];

        // Trocas de dois times que mexeram em algum desses ativos DEPOIS da base.
        if ($players || $picks) {
            $cond = [];
            $par  = [];
            if ($players) { $cond[] = 'ti.player_id IN (' . implode(',', array_fill(0, count($players), '?')) . ')'; $par = array_merge($par, $players); }
            if ($picks)   { $cond[] = 'ti.pick_id IN ('   . implode(',', array_fill(0, count($picks),   '?')) . ')'; $par = array_merge($par, $picks); }

            $sql = "SELECT DISTINCT t.id, COALESCE(t.resolved_at, t.updated_at, t.created_at) AS quando
                      FROM trades t JOIN trade_items ti ON ti.trade_id = t.id
                     WHERE t.status = 'accepted'
                       AND COALESCE(t.resolved_at, t.updated_at, t.created_at) > ?
                       AND (" . implode(' OR ', $cond) . ')';
            $st = $pdo->prepare($sql);
            $st->execute(array_merge([$base['quando']], $par));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $candidatas[] = ['tipo' => 'trade', 'id' => (int)$r['id'], 'quando' => $r['quando']];
            }

            if ($temMulti) {
                $sql = "SELECT DISTINCT mt.id, COALESCE(mt.updated_at, mt.created_at) AS quando
                          FROM multi_trades mt JOIN multi_trade_items mi ON mi.trade_id = mt.id
                         WHERE mt.status = 'accepted'
                           AND COALESCE(mt.updated_at, mt.created_at) > ?
                           AND (" . str_replace('ti.', 'mi.', implode(' OR ', $cond)) . ')';
                $st = $pdo->prepare($sql);
                $st->execute(array_merge([$base['quando']], $par));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $candidatas[] = ['tipo' => 'multi', 'id' => (int)$r['id'], 'quando' => $r['quando']];
                }
            }
        }

        foreach ($candidatas as $c) {
            $chave = $c['tipo'] . ':' . $c['id'];
            if (isset($vistas[$chave])) continue;
            $vistas[$chave] = $c;
            $novas = true;

            // Os ativos dela entram no conjunto: eles também voltam, e podem
            // ter andado depois.
            foreach (trRevItens($pdo, $c['tipo'], $c['id']) as $it) {
                if (!empty($it['player_id'])) $ativos['p:' . (int)$it['player_id']] = true;
                if (!empty($it['pick_id']))   $ativos['k:' . (int)$it['pick_id']]   = true;
            }
        }

        if (count($vistas) > TR_REV_MAX_CASCATA) {
            $out['erro'] = 'Essa reversão arrastaria mais de ' . TR_REV_MAX_CASCATA
                         . ' trocas. Desfaça as mais recentes primeiro, uma a uma.';
            return $out;
        }
    }

    // Tira a base e ordena da mais nova pra mais antiga.
    unset($vistas[$tipo . ':' . $id]);
    $arrastadas = array_values($vistas);
    usort($arrastadas, fn($a, $b) => strcmp((string)$b['quando'], (string)$a['quando']));

    foreach ($arrastadas as $a) {
        $out['arrastadas'][] = [
            'tipo'   => $a['tipo'],
            'id'     => (int)$a['id'],
            'quando' => $a['quando'],
            'rotulo' => trRevRotulo($pdo, $a['tipo'], (int)$a['id']),
        ];
    }
    $out['ativos'] = count($ativos);
    $out['ok'] = true;
    return $out;
}

/**
 * Desfaz UMA troca. Devolve ['ok'=>bool, 'jogadores'=>[], 'picks'=>[], 'erros'=>[]].
 *
 * O ativo só volta se estiver onde esta troca o deixou. Se estiver em outro
 * lugar, não é caso de forçar: é sinal de que algo fora da cascata mexeu nele
 * (dispensa, free agency, draft), e aí quem decide é gente.
 */
function trRevUma(PDO $pdo, string $tipo, int $id): array
{
    $r = ['ok' => true, 'jogadores' => [], 'picks' => [], 'erros' => []];

    foreach (trRevItens($pdo, $tipo, $id) as $it) {
        $origem  = (int)$it['origem'];
        $destino = (int)$it['destino'];

        if (!empty($it['player_id'])) {
            $st = $pdo->prepare('SELECT team_id, name FROM players WHERE id = ?');
            $st->execute([(int)$it['player_id']]);
            $p = $st->fetch(PDO::FETCH_ASSOC);

            if (!$p) {
                $r['erros'][] = "Jogador #{$it['player_id']} não existe mais (dispensado?)";
                $r['ok'] = false;
            } elseif ((int)$p['team_id'] === $destino) {
                // Volta pro banco: trocas antigas deixaram titulares
                // espalhados, e devolver um titular a quem já recompôs o
                // quinteto deixaria o time com seis.
                $pdo->prepare("UPDATE players SET team_id = ?, role = 'Banco' WHERE id = ?")
                    ->execute([$origem, (int)$it['player_id']]);
                $r['jogadores'][] = $p['name'];
            } elseif ((int)$p['team_id'] === $origem) {
                $r['jogadores'][] = $p['name'] . ' (já estava lá)';
            } else {
                $r['erros'][] = "{$p['name']} não está no time desta troca — saiu por outro caminho";
                $r['ok'] = false;
            }
        }

        if (!empty($it['pick_id'])) {
            $st = $pdo->prepare('SELECT team_id, season_year, round FROM picks WHERE id = ?');
            $st->execute([(int)$it['pick_id']]);
            $pk = $st->fetch(PDO::FETCH_ASSOC);

            if (!$pk) {
                $r['erros'][] = "Pick #{$it['pick_id']} não existe mais";
                $r['ok'] = false;
            } elseif ((int)$pk['team_id'] === $destino) {
                $pdo->prepare('UPDATE picks SET team_id = ?, last_owner_team_id = NULL,
                                      swap_type = NULL, swap_locked = 0, swap_pair_pick_id = NULL
                                WHERE id = ?')
                    ->execute([$origem, (int)$it['pick_id']]);
                $r['picks'][] = "{$pk['season_year']} R{$pk['round']}";
            } elseif ((int)$pk['team_id'] === $origem) {
                $pdo->prepare('UPDATE picks SET swap_type = NULL, swap_locked = 0, swap_pair_pick_id = NULL WHERE id = ?')
                    ->execute([(int)$it['pick_id']]);
                $r['picks'][] = "{$pk['season_year']} R{$pk['round']} (já estava lá)";
            } else {
                $r['erros'][] = "Pick {$pk['season_year']} R{$pk['round']} não está no time desta troca";
                $r['ok'] = false;
            }
        }
    }

    return $r;
}

/** Os times de uma troca, pra devolver o saldo. */
function trRevTimes(PDO $pdo, string $tipo, int $id): array
{
    if ($tipo === 'multi') {
        $st = $pdo->prepare('SELECT team_id FROM multi_trade_teams WHERE trade_id = ?');
        $st->execute([$id]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    $st = $pdo->prepare('SELECT from_team_id, to_team_id FROM trades WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return array_values(array_filter([(int)($r['from_team_id'] ?? 0), (int)($r['to_team_id'] ?? 0)]));
}

/** Devolve uma troca ao saldo dos times dela. */
function trRevDevolverSaldo(PDO $pdo, string $tipo, int $id): void
{
    $st = $pdo->prepare('UPDATE teams SET trades_used = GREATEST(COALESCE(trades_used,0) - 1, 0) WHERE id = ?');
    foreach (trRevTimes($pdo, $tipo, $id) as $tid) $st->execute([$tid]);
}

/** Marca a troca como cancelada, com o registro do que foi feito. */
function trRevMarcarCancelada(PDO $pdo, string $tipo, int $id, string $log): void
{
    $tabela = $tipo === 'multi' ? 'multi_trades' : 'trades';
    $pdo->prepare("UPDATE {$tabela} SET status = 'cancelled',
                          notes = CONCAT(IFNULL(notes, ''), '\n', ?) WHERE id = ?")
        ->execute([$log, $id]);
}

/**
 * A LIGA PRECISA SABER — e não só os dois times.
 *
 * Trade com gente boa é assunto de todo mundo: o grupo comenta, monta tabela na
 * cabeça, planeja em cima dela. Quando ela cai, o silêncio é pior que o
 * anúncio — alguém negocia semanas depois contando com um elenco que não
 * existe. E as arrastadas vão na mesma mensagem, porque quem lê "a troca do X
 * caiu" pergunta na hora o que aconteceu com as outras.
 *
 * Mesmo corte do anúncio de trade (82+): movimento de banco não vira recado no
 * grupo. Se nenhuma das trocas desfeitas tem jogador nessa faixa, não sai nada.
 */
function trRevAvisarGrupo(PDO $pdo, array $resultado): void
{
    if (empty($resultado['ok']) || empty($resultado['feitas'])) return;

    require_once __DIR__ . '/whatsapp.php';
    require_once __DIR__ . '/leilao_bot.php';   // botGrupoDaCerimonia()

    $base = $resultado['plano']['base'] ?? null;
    if (!$base) return;

    $cab = trRevCabecalho($pdo, (string)$base['tipo'], (int)$base['id']);
    $liga = strtoupper((string)($cab['league'] ?? ''));
    if ($liga === '') return;

    // Os nomes que valem anúncio, de todas as trocas que caíram.
    $destaques = [];
    foreach ($resultado['feitas'] as $f) {
        foreach (trRevItens($pdo, (string)$f['tipo'], (int)$f['id']) as $it) {
            if (empty($it['player_id'])) continue;
            $st = $pdo->prepare('SELECT name, ovr FROM players WHERE id = ?');
            $st->execute([(int)$it['player_id']]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if ($p && (int)$p['ovr'] >= WHATSAPP_OVR_MIN_ANUNCIO) {
                $destaques[$p['name']] = (int)$p['ovr'];
            }
        }
    }
    if (!$destaques) return;

    arsort($destaques);

    /* O QUE ESTAVA NA TROCA, e não o número dela. Ninguém no grupo reconhece
       "Troca #8667"; todo mundo reconhece "Catrinas ↔ Swaneys — Kawhi". */
    $ativosBase = trRevAtivosLegiveis($pdo, (string)$base['tipo'], (int)$base['id'], 6);

    $txt = "↩️ *TROCA DESFEITA*\n\n"
         . '*' . trRevTimesRotulo($pdo, (string)$base['tipo'], (int)$base['id']) . "*\n"
         . ($ativosBase ? 'Voltaram pros times de origem: ' . implode(', ', $ativosBase) . "\n" : '');

    $arrastadas = array_values(array_filter($resultado['feitas'], fn($f) => empty($f['base'])
        && !((string)$f['tipo'] === (string)$base['tipo'] && (int)$f['id'] === (int)$base['id'])));

    if ($arrastadas) {
        $txt .= "\n*Como consequência, também caíram:*\n";
        foreach ($arrastadas as $a) $txt .= '• ' . $a['rotulo'] . "\n";
        $txt .= "\n_Essas não contam no saldo de trocas de ninguém — os times delas não erraram._";
    }

    $grupo = botGrupoDaCerimonia($pdo, $liga);
    if ($grupo) whatsappEnfileirar($pdo, $grupo, $txt, true, 'trade');
}

/**
 * Executa o plano inteiro, numa transação só.
 *
 * TUDO OU NADA de propósito: reverter três de quatro trocas deixa a liga num
 * estado que ninguém sabe descrever — pior do que não ter revertido. Qualquer
 * ativo que não puder voltar derruba a operação inteira e a lista do que
 * impediu vai pro admin.
 *
 * @param bool $devolverBase a troca que o admin mandou desfazer conta ou não.
 *                           As arrastadas são SEMPRE devolvidas.
 */
function trRevExecutar(PDO $pdo, string $tipo, int $id, bool $devolverBase, ?string $quemAdmin = null): array
{
    $plano = trRevPlano($pdo, $tipo, $id);
    if (!$plano['ok']) return ['ok' => false, 'erro' => $plano['erro'], 'plano' => $plano];

    $minhaTransacao = !$pdo->inTransaction();
    if ($minhaTransacao) $pdo->beginTransaction();

    try {
        $feitas = [];
        $erros  = [];

        // As arrastadas primeiro, da mais nova pra mais antiga; a base por
        // último, que é a ordem em que os ativos conseguem voltar.
        $ordem = $plano['arrastadas'];
        $ordem[] = ['tipo' => $tipo, 'id' => $id, 'rotulo' => $plano['base']['rotulo'], 'base' => true];

        foreach ($ordem as $t) {
            $r = trRevUma($pdo, $t['tipo'], (int)$t['id']);
            if (!$r['ok']) {
                foreach ($r['erros'] as $e) $erros[] = $t['rotulo'] . ': ' . $e;
                continue;
            }

            $ehBase = !empty($t['base']);
            $devolve = $ehBase ? $devolverBase : true;

            $log = '[Admin] ' . ($ehBase ? 'Trade revertida' : 'Revertida em cascata (arrastada pela '
                                         . $plano['base']['rotulo'] . ')')
                 . ' em ' . date('Y-m-d H:i:s') . ($quemAdmin ? ' por ' . $quemAdmin : '');
            if ($r['jogadores']) $log .= "\nJogadores: " . implode(', ', $r['jogadores']);
            if ($r['picks'])     $log .= "\nPicks: " . implode(', ', $r['picks']);
            $log .= $devolve ? "\nA troca NÃO contou: devolvida ao saldo dos times."
                             : "\nA troca CONTOU: o saldo dos times não mudou.";

            if ($devolve) trRevDevolverSaldo($pdo, $t['tipo'], (int)$t['id']);
            trRevMarcarCancelada($pdo, $t['tipo'], (int)$t['id'], $log);

            $feitas[] = ['tipo' => $t['tipo'], 'id' => (int)$t['id'], 'rotulo' => $t['rotulo'],
                         'jogadores' => count($r['jogadores']), 'picks' => count($r['picks']),
                         'devolvida' => $devolve];
        }

        if ($erros) {
            if ($minhaTransacao) $pdo->rollBack();
            return ['ok' => false, 'erro' => 'Nada foi revertido — tem ativo que não pode voltar.',
                    'bloqueios' => $erros, 'plano' => $plano];
        }

        if ($minhaTransacao) $pdo->commit();
        return ['ok' => true, 'feitas' => $feitas, 'plano' => $plano];

    } catch (Throwable $e) {
        if ($minhaTransacao && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[trade-revert] executar: ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Erro ao reverter: ' . $e->getMessage(), 'plano' => $plano];
    }
}
