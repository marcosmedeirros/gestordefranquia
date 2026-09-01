<?php
/**
 * Tática do time — tela única do GM (sem envio, sem prazo).
 *
 * O time mantém 3 táticas nomeadas em paralelo (slots internos: regular,
 * playoffs, outra — rotulados na tela como Tática 1/2/3); a marcada como
 * `is_active` é a oficial. Ativar uma tática espelha os dados nas tabelas
 * antigas (team_directives/directive_player_minutes), pois não temos certeza
 * se algo fora deste repositório ainda lê essas tabelas — o espelho é
 * best-effort e nunca deve travar a ação principal do GM.
 *
 * GET  action=get             → as 3 táticas + elenco + janela de edição
 * POST action=save            → grava uma das 3 táticas
 * POST action=set_active      → marca uma tática como a ativa (espelha)
 * POST action=preview_minutes → recalcula a prévia de minutos sem salvar
 *
 * Admin (hasAdminAccess):
 * GET  action=admin_window     → janela de edição da liga
 * POST action=admin_window     → ajusta corte diário / abre-fecha manual
 * GET  action=admin_overview   → tática ativa de cada time da liga, ao vivo
 */
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';  // GLEAGUE_VAGAS
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo  = db();
$user = getUserSession();

/** Ordem natural do quinteto: armador a pivô. */
const TATICA_ORDEM_POS = ['PG' => 1, 'SG' => 2, 'SF' => 3, 'PF' => 4, 'C' => 5];

/** Os três slots internos de tática, rotulados como Tática 1/2/3 na tela. */
const TATICA_SLOTS = ['regular' => 'Tática 1', 'playoffs' => 'Tática 2', 'outra' => 'Tática 3'];

const TATICA_LIGAS = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

function tacticaSlot($v): string {
    $v = strtolower(trim((string)$v));
    return isset(TATICA_SLOTS[$v]) ? $v : 'regular';
}

/* O tamanho do giro. Num lugar só, porque o mesmo par vale na validação do
   save e na prévia — e num deles ele já esteve diferente do outro. */
const ROTACAO_MIN = 8;
const ROTACAO_MAX = 15;

/* Os starter_* continuam na lista de propósito: a tela não os manda mais
   (quem entra em quadra é decisão do jogo no automático), mas as táticas já
   salvas têm quinteto gravado, e tirar o campo daqui apagaria esse histórico
   no primeiro save de quem nem mexeu nisso. */
const TATICA_CAMPOS = [
    'starter_1_id','starter_2_id','starter_3_id','starter_4_id','starter_5_id',
    'bench_1_id','bench_2_id','bench_3_id','gleague_1_id','gleague_2_id',
    'pace','offensive_rebound','offensive_aggression','defensive_focus',
    'defensive_rebound','game_style','offense_style',
    'rotation_players','veteran_focus','technical_model','playbook','notes',
];

/**
 * Quinteto sugerido: melhor OVR de cada posição, preferindo quem já está
 * marcado como Titular no elenco. Posição vazia é preenchida pelo melhor
 * disponível, para o time nunca sair com menos de cinco.
 */
function sugerirQuinteto(array $jogadores): array {
    $usados = [];
    $quinteto = [];

    foreach (array_keys(TATICA_ORDEM_POS) as $pos) {
        $candidatos = array_filter($jogadores, function ($p) use ($pos, $usados) {
            if (in_array((int)$p['id'], $usados, true)) return false;
            return strtoupper((string)$p['position']) === $pos
                || strtoupper((string)$p['secondary_position']) === $pos;
        });
        usort($candidatos, function ($a, $b) use ($pos) {
            $ta = ($a['role'] === 'Titular') ? 1 : 0;
            $tb = ($b['role'] === 'Titular') ? 1 : 0;
            if ($ta !== $tb) return $tb <=> $ta;
            $pa = strtoupper((string)$a['position']) === $pos ? 1 : 0;
            $pb = strtoupper((string)$b['position']) === $pos ? 1 : 0;
            if ($pa !== $pb) return $pb <=> $pa;
            return (int)$b['ovr'] <=> (int)$a['ovr'];
        });
        $escolhido = $candidatos ? reset($candidatos) : null;
        if ($escolhido) {
            $quinteto[] = (int)$escolhido['id'];
            $usados[] = (int)$escolhido['id'];
        } else {
            $quinteto[] = null;
        }
    }

    $restantes = array_values(array_filter($jogadores, fn($p) => !in_array((int)$p['id'], $usados, true)));
    usort($restantes, fn($a, $b) => (int)$b['ovr'] <=> (int)$a['ovr']);
    foreach ($quinteto as $i => $v) {
        if ($v === null && $restantes) {
            $p = array_shift($restantes);
            $quinteto[$i] = (int)$p['id'];
            $usados[] = (int)$p['id'];
        }
    }
    return $quinteto;
}

/**
 * Distribui exatamente 240 minutos (5 em quadra x 48) entre o quinteto e os
 * melhores reservas até fechar o tamanho de rotação escolhido pelo GM. Quem
 * fica de fora da rotação, ou foi mandado para a G-League nesta tática, não
 * recebe minuto nenhum.
 */
function sugerirMinutos(array $jogadores, array $quinteto, int $rotationPlayers = 10, array $gleagueIds = []): array {
    $elegiveis = array_values(array_filter($jogadores, fn($p) =>
        ($p['role'] ?? '') !== 'G-League' && !in_array((int)$p['id'], $gleagueIds, true)
    ));
    if (!$elegiveis) return [];

    $pesos = [];
    foreach ($elegiveis as $p) {
        $id  = (int)$p['id'];
        $ovr = max(40, (int)$p['ovr']);
        $mult = in_array($id, $quinteto, true) ? 2.0
              : (($p['role'] ?? '') === 'Titular' ? 1.4
              : (($p['role'] ?? '') === 'Banco' ? 1.0 : 0.55));
        $pesos[$id] = pow($ovr / 50, 2) * $mult;
    }

    // Mantém só os N de maior peso — titulares quase sempre entram por peso,
    // mas garantimos a entrada deles mesmo em elencos muito desequilibrados.
    // O piso é o da liga, mas nunca acima do elenco disponível: um time com
    // 7 aptos joga com 7, e não fica sem minutos por causa do mínimo.
    $rotationPlayers = min(count($pesos), max(ROTACAO_MIN, $rotationPlayers));
    arsort($pesos);
    $ranked = array_keys($pesos);
    $titularesNoRanking = array_values(array_intersect($quinteto, $ranked));
    $resto = array_values(array_diff($ranked, $titularesNoRanking));
    $selecionados = array_slice(array_merge($titularesNoRanking, $resto), 0, $rotationPlayers);

    $pesosSelecionados = array_intersect_key($pesos, array_flip($selecionados));
    $soma = array_sum($pesosSelecionados);
    if ($soma <= 0) return [];

    $min = [];
    foreach ($pesosSelecionados as $id => $peso) {
        $min[$id] = max(0, min(42, (int)round($peso / $soma * 240)));
    }

    $diff = 240 - array_sum($min);
    if ($diff !== 0) {
        $ordem = array_keys($min);
        usort($ordem, function ($a, $b) use ($min, $quinteto) {
            $qa = in_array($a, $quinteto, true) ? 1 : 0;
            $qb = in_array($b, $quinteto, true) ? 1 : 0;
            if ($qa !== $qb) return $qb <=> $qa;
            return $min[$b] <=> $min[$a];
        });
        foreach ($ordem as $id) {
            if ($diff === 0) break;
            $passo = $diff > 0 ? 1 : -1;
            $novo = $min[$id] + $passo;
            if ($novo < 0 || $novo > 42) continue;
            $min[$id] = $novo;
            $diff -= $passo;
        }
    }
    return $min;
}

/** Estado da janela de edição de uma liga, já resolvido (aberta ou não, e por quê). */
require_once __DIR__ . '/../backend/tatica_janela.php';

/** Fachada fina: a regra mora em backend/tatica_janela.php, usada também
 *  pelo painel de controle do admin. */
function getEditWindow(PDO $pdo, string $league): array {
    taticaGarantirTabelaJanela($pdo);
    return taticaJanela($pdo, $league);
}

/** Cria (uma vez, por liga) um prazo perene que só existe para ancorar o espelho nas tabelas antigas. */
function getOrCreatePerpetualDeadline(PDO $pdo, string $league): ?int {
    try {
        $stmt = $pdo->prepare("SELECT id FROM directive_deadlines WHERE league = ? AND description = 'Tática contínua (sem prazo)' LIMIT 1");
        $stmt->execute([$league]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
        $pdo->prepare("INSERT INTO directive_deadlines (league, deadline_date, description, phase, is_active) VALUES (?, '2099-12-31 23:59:59', 'Tática contínua (sem prazo)', 'regular', 1)")
            ->execute([$league]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('[tactics.php mirror] deadline perene: ' . $e->getMessage());
        return null;
    }
}

/**
 * Espelha a tática ativa do time em team_directives/directive_player_minutes,
 * para o caso de algo fora deste repositório ainda ler essas tabelas. Nunca
 * deve interromper o fluxo principal — qualquer falha aqui é só logada.
 */
function mirrorActiveTactic(PDO $pdo, int $teamId, string $league): void {
    try {
        $stmt = $pdo->prepare('SELECT * FROM team_tactics WHERE team_id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$teamId]);
        $ativa = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ativa) return;

        $deadlineId = getOrCreatePerpetualDeadline($pdo, $league);
        if (!$deadlineId) return;

        $campos = ['starter_1_id','starter_2_id','starter_3_id','starter_4_id','starter_5_id',
                   'bench_1_id','bench_2_id','bench_3_id','pace','offensive_rebound','offensive_aggression',
                   'defensive_focus','defensive_rebound','game_style','offense_style',
                   'rotation_players','veteran_focus','gleague_1_id','gleague_2_id','technical_model','playbook','notes'];
        $valores = ['team_id' => $teamId, 'deadline_id' => $deadlineId, 'rotation_style' => 'auto'];
        foreach ($campos as $c) $valores[$c] = $ativa[$c] ?? null;

        $cols = array_keys($valores);
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $upd = implode(',', array_map(fn($c) => "$c = VALUES($c)", array_diff($cols, ['team_id', 'deadline_id'])));
        $pdo->prepare('INSERT INTO team_directives (' . implode(',', $cols) . ") VALUES ($ph) ON DUPLICATE KEY UPDATE $upd")
            ->execute(array_values($valores));

        $stmtId = $pdo->prepare('SELECT id FROM team_directives WHERE team_id = ? AND deadline_id = ?');
        $stmtId->execute([$teamId, $deadlineId]);
        $directiveId = (int)$stmtId->fetchColumn();
        if (!$directiveId) return;

        $stmtP = $pdo->prepare('SELECT id, ovr, role FROM players WHERE team_id = ?');
        $stmtP->execute([$teamId]);
        $jogadores = $stmtP->fetchAll(PDO::FETCH_ASSOC);

        $starters = array_values(array_filter([
            (int)($ativa['starter_1_id'] ?? 0) ?: null, (int)($ativa['starter_2_id'] ?? 0) ?: null,
            (int)($ativa['starter_3_id'] ?? 0) ?: null, (int)($ativa['starter_4_id'] ?? 0) ?: null,
            (int)($ativa['starter_5_id'] ?? 0) ?: null,
        ]));
        $gleagueIds = array_values(array_filter([(int)($ativa['gleague_1_id'] ?? 0) ?: null, (int)($ativa['gleague_2_id'] ?? 0) ?: null]));
        /* Antes só calculava com os 5 titulares escolhidos. Como a tela não
           pede mais quinteto, essa condição zerava os minutos de todo mundo:
           agora o cálculo roda sempre, e a distribuição sai do OVR e da
           função no elenco. $starters segue entrando por causa das táticas
           antigas, que ainda têm quinteto salvo. */
        $minutos = sugerirMinutos($jogadores, $starters,
            (int)($ativa['rotation_players'] ?? 10), $gleagueIds);

        $pdo->prepare('DELETE FROM directive_player_minutes WHERE directive_id = ?')->execute([$directiveId]);
        if ($minutos) {
            $stmtIns = $pdo->prepare('INSERT INTO directive_player_minutes (directive_id, player_id, minutes_per_game) VALUES (?, ?, ?)');
            foreach ($minutos as $pid => $m) $stmtIns->execute([$directiveId, $pid, $m]);
        }
    } catch (Throwable $e) {
        error_log('[tactics.php mirror] ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────
// GET
// ─────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'get';

    if ($action === 'admin_window' || $action === 'admin_overview') {
        if (!hasAdminAccess($pdo, $user['id'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Sem permissão.']);
            exit;
        }
        $league = strtoupper((string)($_GET['league'] ?? ''));
        if (!in_array($league, TATICA_LIGAS, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Liga inválida.']);
            exit;
        }

        if ($action === 'admin_window') {
            echo json_encode(['success' => true, 'window' => getEditWindow($pdo, $league)]);
            exit;
        }

        $stmtTeams = $pdo->prepare('SELECT id, city, name FROM teams WHERE league = ? ORDER BY city, name');
        $stmtTeams->execute([$league]);
        $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

        // Campos de configuração que o admin precisa ver pra reproduzir a
        // tática dentro do jogo. Minutos por jogador ficaram de fora: não são
        // mais usados. A ordem aqui é a ordem que aparece na tela.
        $OPCOES_TATICA = require __DIR__ . '/../backend/tatica_opcoes.php';

        // A ORDEM É A DO 2K, na tela de Sim Points of Emphasis:
        //
        //   Offensive Focus · Offensive Tempo · Offensive Rebounding ·
        //   Defensive Focus · Defensive Aggression · Defensive Rebounding
        //
        // Quem usa esta tela está com o jogo aberto do lado, copiando campo a
        // campo. Ordem diferente obriga a procurar cada linha na outra tela —
        // e é aí que se copia o valor errado.
        //
        // "Agressividade Ofensiva" era erro de rótulo: o valor que esse campo
        // carrega é "Play Physical Defense", e no jogo ele se chama Defensive
        // Aggression. O nome da coluna no banco (offensive_aggression) segue
        // errado, mas renomear coluna é outra conversa — o que a pessoa lê já
        // está certo.
        $camposConfig = [
            'game_style'           => 'Estilo de Jogo',
            'offense_style'        => 'Estilo Ofensivo',
            'pace'                 => 'Ritmo',
            'offensive_rebound'    => 'Rebote Ofensivo',
            'defensive_focus'      => 'Foco Defensivo',
            'offensive_aggression' => 'Agressividade Defensiva',
            'defensive_rebound'    => 'Rebote Defensivo',
            'rotation_style'       => 'Estilo de Rotação',
            'rotation_players'     => 'Jogadores na Rotação',
            'veteran_focus'        => 'Foco em Veteranos',
            'technical_model'      => 'Modelo Técnico',
            'playbook'             => 'Playbook',
            'notes'                => 'Observações',
        ];

        // Modelo técnico e playbook são de ELITE e NEXT. Na RISE e na ROOKIE
        // não existem, e mostrar o campo vazio no resumo do admin faria
        // parecer que o GM deixou de preencher.
        if (!in_array(strtoupper($league), ['ELITE', 'NEXT'], true)) {
            unset($camposConfig['technical_model'], $camposConfig['playbook']);
        }

        // O retrato dos modelos técnicos da liga: quem definiu, quem não, e
        // quantas das oito vagas cada um já gastou. Só os DADOS — o que fazer
        // com quem não definiu é decisão do admin, não do sistema.
        $modelos = null;
        if (in_array(strtoupper($league), ['ELITE', 'NEXT'], true)) {
            require_once __DIR__ . '/../backend/modelo_tecnico_trocas.php';
            require_once __DIR__ . '/../backend/modelos_tecnicos.php';
            // O limite e da liga que esta na tela, nao a constante global.
            $modelos = ['limite' => modeloTecnicoLimiteDaLiga($league), 'times' => []];
            foreach ($teams as $t) {
                $st = $pdo->prepare("SELECT technical_model FROM team_tactics
                                     WHERE team_id = ? AND is_active = 1 LIMIT 1");
                $st->execute([(int)$t['id']]);
                $atual = trim((string)($st->fetchColumn() ?: ''));
                $placar = modeloTecnicoPlacar($pdo, (int)$t['id']);
                $ficha = modeloTecnico($atual ?: null);
                $modelos['times'][] = [
                    'team_id'   => (int)$t['id'],
                    'nome'      => trim($t['city'] . ' ' . $t['name']),
                    'modelo'    => $atual ?: null,
                    'foto'      => $ficha['foto'] ?? null,
                    'sistema'   => $ficha['sistema'] ?? null,
                    'usados'    => $placar['usados'],
                    'restam'    => $placar['restam'],
                    'historico' => array_map(fn($h) => $h['modelo'], $placar['historico']),
                ];
            }
        }

        // ── FASE DE PLAYOFFS ────────────────────────────────────────────
        //
        // A liga está nos playoffs quando a temporada aberta já tem
        // classificação registrada: é o primeiro salvamento do card de
        // Pontuação que grava season_standings. Ao avançar a temporada nasce
        // uma temporada nova, sem classificação, e a tela volta sozinha ao
        // normal — não há nada pra ligar ou desligar na mão.
        $noOffs = [];        // team_id => seed dentro da conferência
        $faseOffs = false;
        try {
            $stTemp = $pdo->prepare("SELECT id FROM seasons
                                      WHERE league = ? AND (status IS NULL OR status <> 'completed')
                                   ORDER BY id DESC LIMIT 1");
            $stTemp->execute([$league]);
            $tempAberta = (int)($stTemp->fetchColumn() ?: 0);
            if ($tempAberta) {
                $stCls = $pdo->prepare("SELECT ss.team_id, ss.position,
                                               COALESCE(ss.conference, t.conference) AS conf
                                          FROM season_standings ss
                                          JOIN teams t ON t.id = ss.team_id
                                         WHERE ss.season_id = ?
                                      ORDER BY ss.position ASC");
                $stCls->execute([$tempAberta]);
                $classificacao = $stCls->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if ($classificacao) {
                    $faseOffs = true;
                    // Top 8 de CADA conferência, a mesma régua da loteria.
                    $porConf = [];
                    foreach ($classificacao as $r) {
                        $porConf[$r['conf'] ?: 'UNICA'][] = $r;
                    }
                    foreach ($porConf as $lista) {
                        usort($lista, fn($a, $b) => (int)$a['position'] <=> (int)$b['position']);
                        foreach (array_slice($lista, 0, 8) as $i => $r) {
                            $noOffs[(int)$r['team_id']] = $i + 1;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Sem classificação legível a tela é a de sempre — nada quebra.
            error_log('[tactics] fase de offs: ' . $e->getMessage());
        }

        $overview = [];
        foreach ($teams as $t) {
            $stmtA = $pdo->prepare("
                SELECT tt.*,
                       s1.name AS s1, s2.name AS s2, s3.name AS s3, s4.name AS s4, s5.name AS s5,
                       b1.name AS b1, b2.name AS b2, b3.name AS b3,
                       g1.name AS g1, g2.name AS g2
                FROM team_tactics tt
                LEFT JOIN players s1 ON s1.id = tt.starter_1_id
                LEFT JOIN players s2 ON s2.id = tt.starter_2_id
                LEFT JOIN players s3 ON s3.id = tt.starter_3_id
                LEFT JOIN players s4 ON s4.id = tt.starter_4_id
                LEFT JOIN players s5 ON s5.id = tt.starter_5_id
                LEFT JOIN players b1 ON b1.id = tt.bench_1_id
                LEFT JOIN players b2 ON b2.id = tt.bench_2_id
                LEFT JOIN players b3 ON b3.id = tt.bench_3_id
                LEFT JOIN players g1 ON g1.id = tt.gleague_1_id
                LEFT JOIN players g2 ON g2.id = tt.gleague_2_id
                WHERE tt.team_id = ? AND tt.is_active = ?
            ");
            $stmtA->execute([(int)$t['id'], 1]);
            $ativa = $stmtA->fetch(PDO::FETCH_ASSOC) ?: null;

            // Nenhuma marcada: cai no 'regular', que é exatamente o que a tela
            // do GM faz. Sem isto o admin dizia "nenhuma tática ativa" pro time
            // que o proprio dono ve como ativa — as duas telas liam a mesma
            // tabela e respondiam coisas diferentes.
            if (!$ativa) {
                $stmtA->execute([(int)$t['id'], 0]);
                foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $linha) {
                    if ($linha['slot'] === 'regular') { $ativa = $linha; break; }
                }
            }

            $tatica = null;
            if ($ativa) {
                // Contra QUAL retrato comparar depende da fase.
                //
                // Fora dos playoffs: o da virada de temporada — o vermelho diz
                // "mexeu desde que a temporada começou".
                //
                // Nos playoffs: o do fim da regular, tirado quando o admin
                // salvou a classificação. Aí o vermelho passa a dizer "mudou a
                // tática PRA os playoffs", que é a pergunta que interessa com a
                // série prestes a começar. Sem isso as duas táticas se
                // misturavam num diff só.
                //
                // Na fase de playoffs NÃO há queda pro snapshot antigo: se o
                // retrato do fim da regular não existe (time que só criou a
                // tática depois), não dá pra dizer o que mudou pros offs, e
                // comparar com a virada de temporada acenderia vermelho por
                // uma diferença de outro assunto.
                $antes = [];
                $bruto = $faseOffs
                    ? ($ativa['snapshot_offs_json'] ?? null)
                    : ($ativa['snapshot_json'] ?? null);
                if (!empty($bruto)) {
                    $decodificado = json_decode((string)$bruto, true);
                    if (is_array($decodificado)) $antes = $decodificado;
                }

                $config = [];
                foreach ($camposConfig as $campo => $rotulo) {
                    $valor = $ativa[$campo] ?? null;
                    $config[] = [
                        'campo'   => $campo,
                        'rotulo'  => $rotulo,
                        // O rótulo, não o valor cru: o admin mostrava
                        // "pace_space" onde o jogo escreve "Pace & Space".
                        // Opção sem tradução cai no valor original em vez de
                        // sumir — melhor mostrar feio que esconder.
                        'valor'   => ($valor === null || $valor === '')
                            ? null
                            : (string)($OPCOES_TATICA[$campo][(string)$valor] ?? $valor),
                        // Só marca como alterado quando existe snapshot: sem ele
                        // não dá pra saber o que mudou, e pintar tudo de vermelho
                        // seria pior que não pintar nada.
                        'mudou'   => $antes
                            ? ((string)($antes[$campo] ?? '') !== (string)($valor ?? ''))
                            : false,
                    ];
                }

                $elencoCampos = [
                    'starter_1_id' => $ativa['s1'], 'starter_2_id' => $ativa['s2'],
                    'starter_3_id' => $ativa['s3'], 'starter_4_id' => $ativa['s4'],
                    'starter_5_id' => $ativa['s5'],
                ];
                $titulares = [];
                foreach ($elencoCampos as $campo => $nome) {
                    if (!$nome) continue;
                    $titulares[] = [
                        'nome'  => $nome,
                        'mudou' => $antes ? ((string)($antes[$campo] ?? '') !== (string)($ativa[$campo] ?? '')) : false,
                    ];
                }

                $tatica = [
                    'slot_label'    => TATICA_SLOTS[$ativa['slot']] ?? $ativa['slot'],
                    'titulares'     => $titulares,
                    'banco'         => array_values(array_filter([$ativa['b1'], $ativa['b2'], $ativa['b3']])),
                    'gleague'       => array_values(array_filter([$ativa['g1'], $ativa['g2']])),
                    'config'        => $config,
                    'updated_at'    => $ativa['updated_at'],
                    'feito_no_jogo' => (int)($ativa['feito_no_jogo'] ?? 0) === 1,
                    'tem_snapshot'  => !empty($antes),
                ];
            }

            $overview[] = [
                'team' => ['id' => (int)$t['id'], 'name' => trim($t['city'] . ' ' . $t['name'])],
                'active_tactic' => $tatica,
                // Fora da fase de playoffs os dois saem nulos, e a tela trata
                // todo mundo igual — que é como era antes.
                'nos_offs' => $faseOffs ? isset($noOffs[(int)$t['id']]) : null,
                'seed'     => $noOffs[(int)$t['id']] ?? null,
            ];
        }

        // ── A ORDEM DA LISTA ────────────────────────────────────────────
        //
        // Alfabética pelo nome COMPLETO — que é o que aparece na tela. A
        // consulta ordena por (city, name), e isso não é a mesma coisa:
        // basta um time com a cidade em branco pra ele saltar pro topo, o
        // que fazia a lista parecer fora de ordem sem motivo aparente.
        //
        // E quem já está marcado como "feito no jogo" vai pro FIM. Esta tela
        // é uma fila de trabalho: o admin desce copiando tática por tática, e
        // o que ele já fez só atrapalha a busca do próximo. Empurrar pro fim
        // deixa no topo exatamente o que falta.
        // Acento comparado byte a byte cai DEPOIS de todo o ASCII, então
        // "México City" iria parar no fim da lista, atrás de Washington.
        // Tirar o acento antes de comparar põe o M de México junto dos
        // outros M, que é onde quem procura o time vai olhar.
        $ordenavel = fn(string $s) => strtolower(strtr($s, [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
            'í'=>'i','î'=>'i','ì'=>'i','ï'=>'i',
            'ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o',
            'ú'=>'u','û'=>'u','ù'=>'u','ü'=>'u',
            'ç'=>'c','ñ'=>'n',
            'Á'=>'a','À'=>'a','Ã'=>'a','Â'=>'a','Ä'=>'a',
            'É'=>'e','Ê'=>'e','È'=>'e','Ë'=>'e',
            'Í'=>'i','Î'=>'i','Ì'=>'i','Ï'=>'i',
            'Ó'=>'o','Ô'=>'o','Õ'=>'o','Ò'=>'o','Ö'=>'o',
            'Ú'=>'u','Û'=>'u','Ù'=>'u','Ü'=>'u',
            'Ç'=>'c','Ñ'=>'n',
        ]));

        // E, na fase de playoffs, quem ESTÁ nos playoffs vem antes de tudo.
        // Com a série pra começar, a tática dos 16 eliminados é ruído: eles
        // vão pro fim da lista e a tela os deixa em cinza. Fora dessa fase a
        // regra some sozinha, porque nos_offs vem nulo pra todo mundo.
        usort($overview, function ($a, $b) use ($ordenavel) {
            if ($a['nos_offs'] !== null && $a['nos_offs'] !== $b['nos_offs']) {
                return $a['nos_offs'] ? -1 : 1;
            }
            $fa = !empty($a['active_tactic']['feito_no_jogo']);
            $fb = !empty($b['active_tactic']['feito_no_jogo']);
            if ($fa !== $fb) return $fa ? 1 : -1;
            // strnatcmp e não strcmp: sem ele, um time com número no nome
            // ordenaria "10" antes de "2".
            return strnatcmp($ordenavel($a['team']['name']), $ordenavel($b['team']['name']));
        });

        echo json_encode([
            'success'   => true,
            'league'    => $league,
            'fase_offs' => $faseOffs,
            'teams'     => $overview,
            'modelos'   => $modelos,
        ]);
        exit;
    }

    $stmtTeam = $pdo->prepare('SELECT id, city, name, league FROM teams WHERE user_id = ? LIMIT 1');
    $stmtTeam->execute([$user['id']]);
    $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
    if (!$team) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Você não tem time nesta liga.']);
        exit;
    }
    $teamId = (int)$team['id'];

    $stmtP = $pdo->prepare("SELECT id, name, position, secondary_position, ovr, age, role
                            FROM players WHERE team_id = ? ORDER BY ovr DESC, name");
    $stmtP->execute([$teamId]);
    $jogadores = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    $playerCount = count($jogadores);
    // Duas vagas, sempre. Antes o número saía do tamanho do elenco (15+ dava
    // duas, 14 dava uma, menos que isso nenhuma) — a regra caiu e agora é
    // padrão da liga: quem tem elenco pode mandar dois.
    // O slot comprado na loja soma aqui: quem pagou leva a vaga a mais.
    $gleagueExtra = 0;
    try {
        $stGl = $pdo->prepare("SELECT COALESCE(gleague_extra, 0) FROM teams WHERE id = ?");
        $stGl->execute([$teamId]);
        $gleagueExtra = (int)$stGl->fetchColumn();
    } catch (Throwable $e) { /* coluna nova; sem ela vale o padrao */ }
    $gleagueSlots = ($team['league'] === 'ELITE') ? GLEAGUE_VAGAS + $gleagueExtra : 0;

    $stmtT = $pdo->prepare('SELECT * FROM team_tactics WHERE team_id = ?');
    $stmtT->execute([$teamId]);
    $bySlot = [];
    foreach ($stmtT->fetchAll(PDO::FETCH_ASSOC) as $r) $bySlot[$r['slot']] = $r;

    // Sem nenhum rascunho ainda: aproveita o perfil tático que o time já tinha
    // salvo do antigo sistema de diretrizes, pra quem já usava não recomeçar do zero.
    if (!$bySlot) {
        $stmtPerf = $pdo->prepare('SELECT directive_profile FROM teams WHERE id = ?');
        $stmtPerf->execute([$teamId]);
        $perfil = json_decode((string)$stmtPerf->fetchColumn(), true);
        if (is_array($perfil) && $perfil) {
            $base = ['team_id' => $teamId, 'slot' => 'regular', 'is_active' => 1];
            foreach (TATICA_CAMPOS as $campo) $base[$campo] = $perfil[$campo] ?? null;
            $bySlot['regular'] = $base;
        }
    }

    $quintetoSugerido = sugerirQuinteto($jogadores);
    $activeSlot = null;
    $tactics = [];
    foreach (array_keys(TATICA_SLOTS) as $slotKey) {
        $row = $bySlot[$slotKey] ?? null;
        if ($row && !empty($row['is_active'])) $activeSlot = $slotKey;

        $starters = $row ? array_values(array_filter([
            (int)($row['starter_1_id'] ?? 0) ?: null, (int)($row['starter_2_id'] ?? 0) ?: null,
            (int)($row['starter_3_id'] ?? 0) ?: null, (int)($row['starter_4_id'] ?? 0) ?: null,
            (int)($row['starter_5_id'] ?? 0) ?: null,
        ])) : [];
        $gleagueIds = $row ? array_values(array_filter([(int)($row['gleague_1_id'] ?? 0) ?: null, (int)($row['gleague_2_id'] ?? 0) ?: null])) : [];
        // Sem a trava dos 5: a prévia vale mesmo sem quinteto escolhido.
        $preview = sugerirMinutos($jogadores, $starters,
            (int)($row['rotation_players'] ?? 10), $gleagueIds);

        $tactics[$slotKey] = [
            'label' => TATICA_SLOTS[$slotKey],
            'saved' => (bool)$row,
            'is_active' => (bool)($row['is_active'] ?? false),
            'updated_at' => $row['updated_at'] ?? null,
            'data' => $row ? array_intersect_key($row, array_flip(TATICA_CAMPOS)) : array_fill_keys(TATICA_CAMPOS, null),
            'preview_minutes' => $preview,
        ];
    }
    if ($activeSlot === null) $activeSlot = 'regular';

    echo json_encode([
        'success' => true,
        'team' => ['id' => $teamId, 'name' => trim($team['city'] . ' ' . $team['name']), 'league' => $team['league']],
        'players' => $jogadores,
        'gleague_slots' => $gleagueSlots,
        'edit_window' => getEditWindow($pdo, $team['league']),
        'active_slot' => $activeSlot,
        'slots' => TATICA_SLOTS,
        'tactics' => $tactics,
        'suggested_starters' => $quintetoSugerido,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// POST
// ─────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';

    // Marca/desmarca "feito no jogo" — some sozinho a cada virada de temporada.
    if ($action === 'admin_feito_no_jogo') {
        if (!hasAdminAccess($pdo, $user['id'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Sem permissão.']);
            exit;
        }
        // $body já veio lido no topo do bloco POST.
        $teamId = (int)($body['team_id'] ?? 0);
        $feito  = !empty($body['feito']) ? 1 : 0;
        if ($teamId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Time inválido.']);
            exit;
        }
        $pdo->prepare("UPDATE team_tactics SET feito_no_jogo = ? WHERE team_id = ? AND is_active = 1")
            ->execute([$feito, $teamId]);
        echo json_encode(['success' => true, 'feito' => (bool)$feito]);
        exit;
    }

    if ($action === 'admin_window') {
        if (!hasAdminAccess($pdo, $user['id'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Sem permissão.']);
            exit;
        }
        $league = strtoupper((string)($body['league'] ?? ''));
        if (!in_array($league, TATICA_LIGAS, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Liga inválida.']);
            exit;
        }

        $estadoAntes = getEditWindow($pdo, $league)['open'];

        // Um botão só: aberta = true ou false. Qualquer outro campo é ignorado.
        if (!array_key_exists('aberta', $body)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Informe aberta: true ou false.']);
            exit;
        }
        $fechada = empty($body['aberta']) ? 1 : 0;
        // manual_open_until zerado junto: se sobrasse preenchido de antes,
        // continuaria sem efeito, mas ficaria lixo confuso na tabela.
        $pdo->prepare('UPDATE tactic_edit_windows SET manual_closed = ?, manual_open_until = NULL WHERE league = ?')
            ->execute([$fechada, $league]);
        $janela = getEditWindow($pdo, $league);

        // Virou a janela (abriu OU fechou) → zera o "Feito no jogo" de todos os
        // times da liga. Cada ciclo de janela é uma rodada nova de aplicar as
        // táticas dentro do jogo, então o que estava marcado no ciclo anterior
        // não vale mais. Mexer só no horário de corte não zera nada.
        if ($janela['open'] !== $estadoAntes) {
            $pdo->prepare("
                UPDATE team_tactics tt
                JOIN teams t ON t.id = tt.team_id
                SET tt.feito_no_jogo = 0
                WHERE t.league = ? AND tt.feito_no_jogo = 1
            ")->execute([$league]);
        }

        // FECHOU: e a hora de anotar o modelo tecnico de cada time. So no
        // fechamento — enquanto a janela esta aberta o GM pode trocar e
        // voltar atras a vontade, e nada disso e decisao. Fechar com o mesmo
        // de antes nao conta; fechar com outro gasta uma das oito vagas.
        $modelosRegistrados = null;
        if ($janela['open'] !== $estadoAntes && !$janela['open']) {
            require_once __DIR__ . '/../backend/modelo_tecnico_trocas.php';
            $modelosRegistrados = modeloTecnicoRegistrarLiga($pdo, $league);
        }

        // Só avisa quando a janela realmente virou.
        $virou = $janela['open'] !== $estadoAntes;
        require_once __DIR__ . '/../backend/push.php';

        // A resposta sai primeiro; o push vira trabalho de bastidor. Era este
        // envio que fazia o botão de tática demorar enquanto os de trade e FA
        // respondiam na hora.
        responderEDepoisNotificar(
            ['success' => true, 'window' => $janela],
            function () use ($pdo, $league, $janela, $virou) {
                if (!$virou) return;
                sendPushToLeague($pdo, $league, $janela['open']
                    ? ['title' => '📋 Tática liberada na ' . $league,
                       'body'  => 'A edição da tática está aberta. Vale até o admin fechar.',
                       'url'   => '/tatica.php']
                    : ['title' => '🔒 Tática fechada na ' . $league,
                       'body'  => 'A edição da tática foi fechada. O que estava salvo é o que vale.',
                       'url'   => '/tatica.php'],
                    'tatica');
            }
        );
        exit;
    }

    $stmtTeam = $pdo->prepare('SELECT id, city, name, league FROM teams WHERE user_id = ? LIMIT 1');
    $stmtTeam->execute([$user['id']]);
    $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
    if (!$team) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Você não tem time nesta liga.']);
        exit;
    }
    $teamId = (int)$team['id'];

    $stmtE = $pdo->prepare('SELECT id, ovr, role FROM players WHERE team_id = ?');
    $stmtE->execute([$teamId]);
    $jogadoresElenco = $stmtE->fetchAll(PDO::FETCH_ASSOC);
    $doElenco = array_map(fn($p) => (int)$p['id'], $jogadoresElenco);

    if ($action === 'preview_minutes') {
        $starters = array_values(array_filter([
            (int)($body['starter_1_id'] ?? 0) ?: null, (int)($body['starter_2_id'] ?? 0) ?: null,
            (int)($body['starter_3_id'] ?? 0) ?: null, (int)($body['starter_4_id'] ?? 0) ?: null,
            (int)($body['starter_5_id'] ?? 0) ?: null,
        ]));
        $gleagueIds = array_values(array_filter([(int)($body['gleague_1_id'] ?? 0) ?: null, (int)($body['gleague_2_id'] ?? 0) ?: null]));
        $rotationPlayers = max(ROTACAO_MIN, min(ROTACAO_MAX, (int)($body['rotation_players'] ?? 10) ?: 10));
        $preview = sugerirMinutos($jogadoresElenco, $starters, $rotationPlayers, $gleagueIds);
        echo json_encode(['success' => true, 'preview_minutes' => $preview]);
        exit;
    }

    $window = getEditWindow($pdo, $team['league']);
    if (!$window['open'] && in_array($action, ['save', 'set_active'], true)) {
        http_response_code(423);
        echo json_encode(['success' => false, 'error' => 'Edição de tática fechada no momento.', 'edit_window' => $window]);
        exit;
    }

    if ($action === 'save') {
        $slot = tacticaSlot($body['slot'] ?? 'regular');
        $valores = ['team_id' => $teamId, 'slot' => $slot];
        foreach (TATICA_CAMPOS as $campo) {
            $v = $body[$campo] ?? null;
            if (substr($campo, -3) === '_id') {
                $v = (int)$v;
                $valores[$campo] = ($v > 0 && in_array($v, $doElenco, true)) ? $v : null;
            } elseif ($campo === 'rotation_players') {
                // O giro tem chão e teto: menos de 8 não é rotação, é elenco
                // curto, e acima de 15 sobra gente sem minuto. O 0–99 de antes
                // aceitava valores que a simulação não sabia usar.
                $valores[$campo] = ($v === null || $v === '')
                    ? null : max(ROTACAO_MIN, min(ROTACAO_MAX, (int)$v));
            } elseif ($campo === 'veteran_focus') {
                $valores[$campo] = ($v === null || $v === '') ? null : max(0, min(99, (int)$v));
            } else {
                $valores[$campo] = ($v === null || $v === '') ? null : mb_substr((string)$v, 0, 5000);
            }
        }

        /*
         * O MODELO TEM QUE SER UM DOS QUE A LIGA OFERECE.
         *
         * Sem isto a lista curta da NEXT seria só enfeite: o campo chega como
         * texto no corpo do POST, e qualquer chave do catálogo (ou qualquer
         * string) passava direto pro banco. Quem quisesse o Phil Jackson na
         * NEXT conseguiria com uma requisição.
         *
         * Recusa em silêncio, gravando null: o valor não veio da tela, veio
         * de fora dela. Vazio continua sendo vazio — tirar o técnico é uma
         * escolha legítima.
         */
        if (!empty($valores['technical_model'])) {
            require_once __DIR__ . '/../backend/modelos_tecnicos.php';
            $stLiga = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
            $stLiga->execute([$teamId]);
            $permitidos = modelosTecnicosDaLiga((string)$stLiga->fetchColumn());
            if (!isset($permitidos[$valores['technical_model']])) {
                $valores['technical_model'] = null;
            }
        }

        $cols = array_keys($valores);
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $upd = implode(',', array_map(fn($c) => "$c = VALUES($c)", array_diff($cols, ['team_id', 'slot'])));

        try {
            $sql = 'INSERT INTO team_tactics (' . implode(',', $cols) . ") VALUES ($ph) ON DUPLICATE KEY UPDATE $upd";
            $pdo->prepare($sql)->execute(array_values($valores));
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar a tática.']);
            exit;
        }

        // Time sem nenhuma tática marcada fica com esta. Antes o is_active só
        // era gravado por "ativar" explícito, entao quem salvou e nunca clicou
        // ali ficava com tudo em 0 — e o admin nao via tatica nenhuma.
        $stmtNenhuma = $pdo->prepare('SELECT COUNT(*) FROM team_tactics WHERE team_id = ? AND is_active = 1');
        $stmtNenhuma->execute([$teamId]);
        if ((int)$stmtNenhuma->fetchColumn() === 0) {
            $pdo->prepare('UPDATE team_tactics SET is_active = 1 WHERE team_id = ? AND slot = ?')
                ->execute([$teamId, $slot]);
        }

        // Se essa é a tática ativa, o que mudou já é oficial — atualiza o espelho.
        $stmtActive = $pdo->prepare('SELECT is_active FROM team_tactics WHERE team_id = ? AND slot = ?');
        $stmtActive->execute([$teamId, $slot]);
        if ((int)$stmtActive->fetchColumn() === 1) {
            mirrorActiveTactic($pdo, $teamId, $team['league']);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'set_active') {
        $slot = tacticaSlot($body['slot'] ?? '');
        $stmtChk = $pdo->prepare('SELECT 1 FROM team_tactics WHERE team_id = ? AND slot = ?');
        $stmtChk->execute([$teamId, $slot]);
        if (!$stmtChk->fetchColumn()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Essa tática ainda não tem nada salvo.']);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE team_tactics SET is_active = 0 WHERE team_id = ?')->execute([$teamId]);
            $pdo->prepare('UPDATE team_tactics SET is_active = 1 WHERE team_id = ? AND slot = ?')->execute([$teamId, $slot]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao ativar a tática.']);
            exit;
        }

        mirrorActiveTactic($pdo, $teamId, $team['league']);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não permitido']);
