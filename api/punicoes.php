<?php
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

$user = getUserSession();
$pdo = db();
// Admin geral ou admin de liga — este só mexe nas ligas que administra.
if (!$user || empty($user['id']) || !hasAdminAccess($pdo, (int)$user['id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso negado']);
    exit;
}
$punLigasPermitidas = getAdminLeagues($pdo, (int)$user['id']);

function punBarrarLiga(?string $liga): void
{
    global $punLigasPermitidas;
    if (!in_array(strtoupper((string)$liga), $punLigasPermitidas, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Você não administra essa liga.']);
        exit;
    }
}

function punLigaDoTime(PDO $pdo, int $teamId): ?string
{
    $s = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
    $s->execute([$teamId]);
    $l = $s->fetchColumn();
    return $l === false ? null : (string)$l;
}
$method = $_SERVER['REQUEST_METHOD'];

function columnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

function ensurePunishmentsTable(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS team_punishments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            team_id INT NOT NULL,
            league VARCHAR(20) NULL,
            type VARCHAR(50) NOT NULL,
            motive VARCHAR(120) NULL,
            punishment_label VARCHAR(120) NULL,
            effect_type VARCHAR(50) NULL,
            notes TEXT NULL,
            pick_id INT NULL,
            season_scope VARCHAR(20) NULL,
            ban_until_cycle INT NULL,
            removed_pick_season_year INT NULL,
            removed_pick_round INT NULL,
            removed_pick_original_team_id INT NULL,
            removed_pick_last_owner_team_id INT NULL,
            reverted_at DATETIME NULL,
            reverted_by INT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_punishments_team (team_id),
            INDEX idx_punishments_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Exception $e) {
        // ignore
    }
}

function ensurePunishmentsColumns(PDO $pdo): void
{
    $columns = [
        'motive' => "ALTER TABLE team_punishments ADD COLUMN motive VARCHAR(120) NULL",
        'punishment_label' => "ALTER TABLE team_punishments ADD COLUMN punishment_label VARCHAR(120) NULL",
        'effect_type' => "ALTER TABLE team_punishments ADD COLUMN effect_type VARCHAR(50) NULL",
        'ban_until_cycle' => "ALTER TABLE team_punishments ADD COLUMN ban_until_cycle INT NULL",
        'removed_pick_season_year' => "ALTER TABLE team_punishments ADD COLUMN removed_pick_season_year INT NULL",
        'removed_pick_round' => "ALTER TABLE team_punishments ADD COLUMN removed_pick_round INT NULL",
        'removed_pick_original_team_id' => "ALTER TABLE team_punishments ADD COLUMN removed_pick_original_team_id INT NULL",
        'removed_pick_last_owner_team_id' => "ALTER TABLE team_punishments ADD COLUMN removed_pick_last_owner_team_id INT NULL",
        'reverted_at' => "ALTER TABLE team_punishments ADD COLUMN reverted_at DATETIME NULL",
        'reverted_by' => "ALTER TABLE team_punishments ADD COLUMN reverted_by INT NULL"
    ];

    foreach ($columns as $column => $statement) {
        if (!columnExists($pdo, 'team_punishments', $column)) {
            try { $pdo->exec($statement); } catch (Exception $e) {}
        }
    }
}

function ensurePunishmentsCatalog(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS punishment_motives (
            id INT AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(120) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        /* `punishment_types` não é mais criada nem semeada aqui: a lista de
           consequências passou a sair de PUNICAO_EFEITOS (ver a ação
           'catalog'). A tabela continua no banco com o que os admins
           digitaram, só não é lida por ninguém — apagar linha de histórico
           não é trabalho de um deploy. */
    } catch (Exception $e) {
        return;
    }

    $defaultMotives = [
        'Numero minimo de jogadores',
        'Numero maximo de jogadores',
        'Diretrizes erradas'
    ];

    foreach ($defaultMotives as $motive) {
        try {
            $stmt = $pdo->prepare('INSERT IGNORE INTO punishment_motives (label) VALUES (?)');
            $stmt->execute([$motive]);
        } catch (Exception $e) {}
    }
}

function ensureTeamPunishmentColumns(PDO $pdo): void
{
    try {
        if (!columnExists($pdo, 'teams', 'ban_trades_until_cycle')) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN ban_trades_until_cycle INT NULL AFTER trades_cycle");
        }
        if (!columnExists($pdo, 'teams', 'ban_trades_picks_until_cycle')) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN ban_trades_picks_until_cycle INT NULL AFTER ban_trades_until_cycle");
        }
        if (!columnExists($pdo, 'teams', 'ban_fa_until_cycle')) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN ban_fa_until_cycle INT NULL AFTER ban_trades_picks_until_cycle");
        }
            if (!columnExists($pdo, 'teams', 'auto_rotation_until_cycle')) {
                $pdo->exec("ALTER TABLE teams ADD COLUMN auto_rotation_until_cycle INT NULL AFTER ban_fa_until_cycle");
            }
    } catch (Exception $e) {
        // ignore
    }
}

function getTeamCurrentCycle(PDO $pdo, int $teamId): int
{
    if (!columnExists($pdo, 'teams', 'current_cycle')) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT current_cycle FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

ensurePunishmentsTable($pdo);
ensurePunishmentsColumns($pdo);
ensureTeamPunishmentColumns($pdo);
ensurePunishmentsCatalog($pdo);

// O motor: colunas de vigência, quadro do edital e a conversão das punições
// antigas (que gravavam o efeito em colunas de `teams`). @see backend/punicoes_regras.php
require_once dirname(__DIR__) . '/backend/punicoes_regras.php';
punicaoGarantirEsquema($pdo);
punicaoMigrarLegado($pdo);

/* O QUE A AVULSA ACEITA GRAVAR.
   É a mesma lista que a tela oferece (ver a ação 'catalog'), mais os nomes
   antigos que já existem em team_punishments — o revert de uma punição de
   2026 lê o effect_type dela, e tirar o nome daqui travaria o revert. */
$allowedTypes = array_values(array_unique(array_merge(
    PUNICAO_AVULSA_APLICA,
    array_keys(array_filter(PUNICAO_EFEITOS, fn($i) => $i['modo'] === 'registra')),
    ['AVISO_FORMAL'],
)));

/* A lista era escrita à mão aqui, e cinco nomes dela nunca existiram em
   PUNICAO_EFEITOS — REDISTRIBUICAO_MINUTOS, ANULACAO_FA, DROP_OBRIGATORIO,
   CORRECAO_ROSTER, INATIVIDADE_REGISTRADA: a API aceitava, e o efeito não
   tinha implementação nem rótulo em lugar nenhum. Derivar da fonte é o que
   impede a lista de descolar do motor outra vez. */

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'catalog') {
        try {
            $motives = $pdo->query('SELECT id, label FROM punishment_motives ORDER BY label ASC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $motives = [];
        }

        /* AS CONSEQUÊNCIAS SAEM DO CÓDIGO, NÃO DA TABELA `punishment_types`.
           Aquela tabela virou depósito do antigo "Cadastrar consequência":
           14 das 18 linhas eram rótulos digitados à mão — "Impedido de trocar
           pelo restante do ciclo (2 temporadas)", "Leilão top 3 + Ciclo Ban +
           Perda 1st + Limitação de minutagem" — e TODAS caíam em AVISO_FORMAL,
           porque o mapa nome→efeito do front adivinhava pelo texto e usava
           AVISO_FORMAL como padrão. O admin escolhia uma pena detalhada e o
           sistema gravava um aviso que não fazia nada. É por isso que "aviso
           formal não aparecia no app": ele era o depósito de tudo.

           Consequência só vale se existir código que a cumpra, e quem tem
           código é PUNICAO_EFEITOS. A lista agora é essa, filtrada pelo que o
           caminho avulso aceita. @see backend/punicoes_catalogo.php */
        $types = [];
        foreach (PUNICAO_EFEITOS as $nome => $info) {
            // Só o que a avulsa cumpre, mais tudo que ela apenas registra.
            if ($info['modo'] === 'aplica' && !in_array($nome, PUNICAO_AVULSA_APLICA, true)) continue;
            $types[] = [
                'id'             => $nome,
                'label'          => $info['label'],
                'effect_type'    => $nome,
                'requires_pick'  => $nome === 'PERDA_PICK_ESPECIFICA' ? 1 : 0,
                // Pena que corre no tempo precisa saber até quando; a que
                // acontece de uma vez, não. O "ciclo sem troca" já traz o
                // alcance no nome, então também não pergunta.
                'requires_scope' => ($info['duracao'] === 'periodo' && $nome !== 'CICLO_SEM_TROCA') ? 1 : 0,
                'modo'           => $info['modo'],
            ];
        }
        usort($types, fn($a, $b) => strcoll($a['label'], $b['label']));

        echo json_encode(['success' => true, 'motives' => $motives, 'types' => $types]);
        exit;
    }
    if ($action === 'leagues') {
        try {
            $stmt = $pdo->query("SELECT name FROM leagues ORDER BY FIELD(name,'ELITE','NEXT','RISE','ROOKIE')");
            $leagues = array_values(array_intersect($stmt->fetchAll(PDO::FETCH_COLUMN), $punLigasPermitidas));
            echo json_encode(['success' => true, 'leagues' => $leagues]);
        } catch (Exception $e) {
            echo json_encode(['success' => true, 'leagues' => []]);
        }
        exit;
    }

    if ($action === 'teams') {
        $league = strtoupper(trim($_GET['league'] ?? ''));
        if (!$league) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Liga inválida']);
            exit;
        }
        punBarrarLiga($league);
        $stmt = $pdo->prepare('SELECT id, city, name FROM teams WHERE league = ? ORDER BY city, name');
        $stmt->execute([$league]);
        echo json_encode(['success' => true, 'teams' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'punishments') {
        $teamId = (int)($_GET['team_id'] ?? 0);
        $league = strtoupper(trim($_GET['league'] ?? ''));

        if (!$teamId && !$league) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Informe time ou liga']);
            exit;
        }

        punBarrarLiga($teamId ? punLigaDoTime($pdo, $teamId) : $league);
        if ($teamId && $league) punBarrarLiga($league);
        $conditions = ["tp.type <> 'AVISO_TRADE'"];
        $params = [];
        if ($teamId) {
            $conditions[] = 'tp.team_id = ?';
            $params[] = $teamId;
        }
        if ($league) {
            $conditions[] = 'tp.league = ?';
            $params[] = $league;
        }

        $where = implode(' AND ', $conditions);
        $stmt = $pdo->prepare('
            SELECT tp.*, pk.season_year, pk.round,
                   t.city, t.name, t.league AS team_league
            FROM team_punishments tp
            LEFT JOIN picks pk ON pk.id = tp.pick_id
            JOIN teams t ON t.id = tp.team_id
            WHERE ' . $where . '
            ORDER BY tp.created_at DESC, tp.id DESC
        ');
        $stmt->execute($params);
        echo json_encode(['success' => true, 'punishments' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    /* O QUADRO DE INFRAÇÕES DA LIGA — o que a tela nova oferece pra escolher.
       Antes o admin escolhia "motivo" (texto livre) e "consequência" (lista
       solta), sem nada ligando os dois. O edital já liga: cada infração tem a
       sua escala. @see backend/punicoes_catalogo.php */
    if ($action === 'quadro') {
        $league = strtoupper(trim($_GET['league'] ?? ''));
        if (!in_array($league, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Liga inválida']);
            exit;
        }
        punBarrarLiga($league);
        $m = punicaoMomentoDaLiga($pdo, $league);
        $quadro = punicaoQuadroDaLiga($pdo, $league);
        foreach ($quadro as &$inf) {
            foreach ($inf['degraus'] as &$d) {
                $d['texto'] = punicaoEfeitosTexto($d['efeitos'], $m['temporada'], $m['ciclo']);
            }
        }
        echo json_encode([
            'success'  => true,
            'league'   => $league,
            'momento'  => $m,
            'quadro'   => $quadro,
            'efeitos'  => PUNICAO_EFEITOS,
            'duracoes' => PUNICAO_DURACOES,
        ]);
        exit;
    }

    /* A PRÉVIA: em que degrau este time está nesta infração, e o que a pena
       vai fazer. O admin confirma sabendo o resultado — não descobre depois. */
    if ($action === 'previa') {
        $teamId = (int)($_GET['team_id'] ?? 0);
        $infracaoId = (int)($_GET['infracao_id'] ?? 0);
        if (!$teamId || !$infracaoId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Informe time e infração']);
            exit;
        }
        $league = punLigaDoTime($pdo, $teamId);
        punBarrarLiga($league);
        $m = punicaoMomentoDaLiga($pdo, (string)$league);
        $degrau = punicaoProximoDegrau($pdo, $teamId, $infracaoId);

        /* Qual pick vai cair, com nome e ano — "perde a pick de 1ª" não diz
           qual, e o admin precisa saber antes de confirmar. */
        $pickAlvo = null;
        foreach ($degrau['efeitos'] as $e) {
            if (($e['efeito'] ?? '') !== 'PERDA_PICK_1R') continue;
            // Mesma escolha de punicaoPerderPick, incluindo pular a que já
            // foi usada no draft — a prévia tem que dizer a pick certa.
            require_once dirname(__DIR__) . '/backend/picks_usadas.php';
            $usadas = picksJaUsadas($pdo, true);
            $st = $pdo->prepare("SELECT id, season_year FROM picks
                                  WHERE team_id = ? AND original_team_id = ? AND round = '1' AND punicao_id IS NULL
                               ORDER BY CAST(season_year AS UNSIGNED) ASC, id ASC");
            $st->execute([$teamId, $teamId]);
            $p = null;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $cand) {
                if (isset($usadas[(int)$cand['id']])) continue;
                $p = $cand;
                break;
            }
            $pickAlvo = $p
                ? ['ano' => (int)$p['season_year'], 'aviso' => null]
                : ['ano' => null, 'aviso' => 'O time não tem a própria pick de 1ª rodada agora. '
                                           . 'A perda fica pendente e é cobrada quando ele voltar a ter.'];
        }

        echo json_encode([
            'success'    => true,
            'ocorrencia' => $degrau['ocorrencia'],
            'ja_teve'    => $degrau['ocorrencia'] - 1,
            'efeitos'    => $degrau['efeitos'],
            'texto'      => punicaoEfeitosTexto($degrau['efeitos'], $m['temporada'], $m['ciclo']),
            'fim_da_escala' => $degrau['fim_da_escala'],
            'pick_alvo'  => $pickAlvo,
            'momento'    => $m,
        ]);
        exit;
    }

    if ($action === 'picks') {
        $teamId = (int)($_GET['team_id'] ?? 0);
        if (!$teamId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Time inválido']);
            exit;
        }
        punBarrarLiga(punLigaDoTime($pdo, $teamId));
        $stmt = $pdo->prepare('SELECT id, season_year, round FROM picks WHERE team_id = ? ORDER BY season_year ASC, round ASC');
        $stmt->execute([$teamId]);
        echo json_encode(['success' => true, 'picks' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';
    /* APLICAR UM DEGRAU DO EDITAL.
       Uma infração pode trazer mais de uma pena ("anulação da troca + sem
       trocar por um ciclo") — o `add` antigo só sabia gravar uma. Aqui a
       punição é uma só, com a lista de efeitos junto, que é como o edital
       escreve e como o motor lê. */
    if ($action === 'aplicar') {
        $teamId     = (int)($body['team_id'] ?? 0);
        $infracaoId = (int)($body['infracao_id'] ?? 0);
        $notes      = trim((string)($body['notes'] ?? ''));
        /* JÁ CUMPRIDA: registra a punição sem cobrar nada do time. É pra
           quando o GM já pagou a pena fora do sistema — o admin aplicou na
           mão, ou a infração virou acordo no grupo. A infração continua
           contando pra reincidência; o que não acontece é a cobrança. */
        $jaCumprida = !empty($body['ja_cumprida']);
        if (!$teamId || !$infracaoId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Informe time e infração']);
            exit;
        }
        $league = punLigaDoTime($pdo, $teamId);
        punBarrarLiga($league);

        $st = $pdo->prepare('SELECT id, titulo, artigo FROM punicao_infracoes WHERE id = ? AND league = ?');
        $st->execute([$infracaoId, $league]);
        $infracao = $st->fetch(PDO::FETCH_ASSOC);
        if (!$infracao) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Infração não encontrada nesta liga']);
            exit;
        }

        $m = punicaoMomentoDaLiga($pdo, (string)$league);
        $degrau = punicaoProximoDegrau($pdo, $teamId, $infracaoId);

        /* O admin pode ajustar o degrau antes de confirmar — o edital prevê
           relevar a infração e o Tribunal pode agravar. O que ele mandar
           manda; a sugestão é ponto de partida, não camisa de força. */
        $efeitosBrutos = is_array($body['efeitos'] ?? null) && $body['efeitos']
            ? $body['efeitos'] : $degrau['efeitos'];
        if (!$efeitosBrutos) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Esta infração não tem consequência definida no quadro.']);
            exit;
        }

        $efeitos = [];
        foreach ($efeitosBrutos as $e) {
            $nome = strtoupper(trim((string)($e['efeito'] ?? '')));
            if (!isset(PUNICAO_EFEITOS[$nome])) continue;
            $ehPeriodo = PUNICAO_EFEITOS[$nome]['duracao'] === 'periodo';
            $v = $ehPeriodo
                ? punicaoVigenciaDe((string)($e['duracao'] ?? 'TEMPORADA'), $m['temporada'], $m['ciclo'])
                : ['vigencia' => 'EVENTO', 'desde' => null, 'ate' => null];
            $efeitos[] = [
                'efeito'   => $nome,
                'valor'    => isset($e['valor']) ? (int)$e['valor'] : null,
                'vigencia' => $v['vigencia'],
                'desde'    => $v['desde'],
                'ate'      => $v['ate'],
            ];
        }
        if (!$efeitos) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nenhum efeito válido']);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $rotulo = $infracao['titulo'] . ($infracao['artigo'] ? ' (' . $infracao['artigo'] . ')' : '');
            $pdo->prepare('INSERT INTO team_punishments
                    (team_id, league, type, effect_type, motive, punishment_label, notes,
                     infracao_id, ocorrencia, efeitos_json, vigencia, vigencia_desde, vigencia_ate,
                     ja_cumprida, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $teamId, $league, 'CATALOGO', $efeitos[0]['efeito'],
                    $rotulo, punicaoEfeitosTexto($efeitos, $m['temporada'], $m['ciclo']),
                    $notes ?: null, $infracaoId, (int)($body['ocorrencia'] ?? $degrau['ocorrencia']),
                    json_encode($efeitos, JSON_UNESCAPED_UNICODE),
                    $efeitos[0]['vigencia'], $efeitos[0]['desde'], $efeitos[0]['ate'],
                    $jaCumprida ? 1 : 0,
                    (int)$user['id'],
                ]);
            $punicaoId = (int)$pdo->lastInsertId();

            $avisos = [];
            /* Marcada como já cumprida, nada é cobrado: a pick não cai, o
               saldo não mexe. O que corre no tempo (ban, rotação) já fica
               fora sozinho — punicaoEfeitosAtivos ignora a linha. */
            foreach ($jaCumprida ? [] : $efeitos as $i => $e) {
                if ($e['efeito'] === 'PERDA_PICK_1R') {
                    $r = punicaoPerderPick($pdo, $teamId, $punicaoId);
                    if (!$r['ok']) $avisos[] = $r['motivo'];
                }
                /* PERDA_MOEDAS zera o saldo na hora; multa desconta. As duas
                   mexem em dado de verdade, então ficam dentro da transação.
                   O saldo mora em `teams.moedas`, não no usuário: quem paga a
                   multa é a franquia, e ela troca de dono. */
                if ($e['efeito'] === 'PERDA_MOEDAS') {
                    $pdo->prepare('UPDATE teams SET moedas = 0 WHERE id = ?')->execute([$teamId]);
                }
                if ($e['efeito'] === 'MULTA_MOEDAS' && (int)$e['valor'] > 0) {
                    $pdo->prepare('UPDATE teams SET moedas = GREATEST(0, COALESCE(moedas,0) - ?) WHERE id = ?')
                        ->execute([(int)$e['valor'], $teamId]);
                }
                /* CICLO SEM TROCA gasta o saldo de trocas do ciclo. Quantas
                   foram tiradas volta pro efeito, porque o revert precisa
                   devolver exatamente isso — o time podia já ter gasto
                   algumas antes, e devolver o limite daria troca de graça. */
                if ($e['efeito'] === 'CICLO_SEM_TROCA') {
                    $r = punicaoZerarTrocasDoCiclo($pdo, $teamId);
                    if (!$r['ok'] || $r['motivo']) $avisos[] = $r['motivo'];
                    $efeitos[$i]['valor'] = $r['tiradas'];
                    $regravar = true;
                }
            }
            /* Só reescreve quando algum efeito mudou de valor na aplicação —
               é uma volta ao banco por punição, e só esta pena precisa. */
            if (!empty($regravar)) {
                $pdo->prepare('UPDATE team_punishments SET efeitos_json = ? WHERE id = ?')
                    ->execute([json_encode($efeitos, JSON_UNESCAPED_UNICODE), $punicaoId]);
            }
            if ($jaCumprida) {
                $avisos[] = 'Registrada como já cumprida: nada foi cobrado do time.';
            }
            $pdo->commit();

            echo json_encode(['success' => true, 'punishment_id' => $punicaoId,
                              'ocorrencia' => (int)($body['ocorrencia'] ?? $degrau['ocorrencia']),
                              'avisos' => $avisos]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[punicoes] aplicar: ' . $e->getMessage());
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Erro ao aplicar a punição.']);
        }
        exit;
    }

    /* 'add_motive' e 'add_type' sairam em 23/09/2026 junto com os painéis
       de cadastro: consequência só existe se houver código que a cumpra, e
       motivo agora é a infração do quadro. @see backend/punicoes_catalogo.php */
    if (!in_array($action, ['add', 'revert', 'reset_league'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
        exit;
    }

    if ($action === 'reset_league') {
        $league = strtoupper(trim($body['league'] ?? ''));
        if (!in_array($league, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Liga inválida']);
            exit;
        }
        punBarrarLiga($league);
        require_once dirname(__DIR__) . '/backend/team_punishments.php';
        try {
            $result = resetPunicoesEAvisosDaLiga($pdo, $league, (int)$user['id']);
            echo json_encode(['success' => true] + $result);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Erro ao zerar punições e avisos.']);
        }
        exit;
    }


    if ($action === 'revert') {
        $punishmentId = (int)($body['punishment_id'] ?? 0);
        if (!$punishmentId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Punição inválida']);
            exit;
        }

        $stmtPun = $pdo->prepare('SELECT * FROM team_punishments WHERE id = ?');
        $stmtPun->execute([$punishmentId]);
        $pun = $stmtPun->fetch(PDO::FETCH_ASSOC);
        if (!$pun) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Punição não encontrada']);
            exit;
        }
        punBarrarLiga($pun['league'] ?: punLigaDoTime($pdo, (int)$pun['team_id']));
        if (!empty($pun['reverted_at'])) {
            echo json_encode(['success' => true]);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $teamId = (int)$pun['team_id'];
            $effectType = strtoupper($pun['effect_type'] ?? $pun['type']);

            if ($effectType === 'PERDA_PICK_1R' || $effectType === 'PERDA_PICK_ESPECIFICA') {
                /* Basta tirar o carimbo: a pick nunca saiu da tabela.
                   O caminho de antes RECRIAVA a linha apagada, e recriar não é
                   desfazer — a pick voltava sem swap, sem proteção e sem o
                   histórico de dono, porque o DELETE tinha levado tudo. */
                require_once dirname(__DIR__) . '/backend/punicoes_regras.php';
                $devolvidas = punicaoDevolverPicks($pdo, $punishmentId);

                /* Punição antiga, de quando a pick era mesmo apagada: aí não
                   há carimbo pra tirar e a linha precisa ser refeita. */
                $seasonYear = (int)($pun['removed_pick_season_year'] ?? 0);
                $round = (int)($pun['removed_pick_round'] ?? 0);
                $originalTeamId = (int)($pun['removed_pick_original_team_id'] ?? 0);
                if (!$devolvidas && $seasonYear && $round && $originalTeamId) {
                    $stmtExists = $pdo->prepare('SELECT id FROM picks WHERE original_team_id = ? AND season_year = ? AND round = ?');
                    $stmtExists->execute([$originalTeamId, $seasonYear, $round]);
                    if (!$stmtExists->fetchColumn()) {
                        $stmtInsert = $pdo->prepare('INSERT INTO picks (team_id, original_team_id, season_year, round, last_owner_team_id, notes) VALUES (?, ?, ?, ?, ?, ?)');
                        $stmtInsert->execute([
                            $teamId,
                            $originalTeamId,
                            $seasonYear,
                            (string)$round,
                            $pun['removed_pick_last_owner_team_id'] ? (int)$pun['removed_pick_last_owner_team_id'] : null,
                            'Reversão de punição'
                        ]);
                    }
                }
            }

            if ($effectType === 'BAN_TRADES') {
                $stmt = $pdo->prepare('UPDATE teams SET ban_trades_until_cycle = NULL WHERE id = ? AND ban_trades_until_cycle = ?');
                $stmt->execute([$teamId, $pun['ban_until_cycle']]);
            }

            /* CICLO SEM TROCA: devolve o que ELA tirou, lido do efeitos_json.
               Devolver o limite inteiro daria troca de graça pra quem já
               havia gastado algumas antes da punição. */
            if ($effectType === 'CICLO_SEM_TROCA') {
                require_once dirname(__DIR__) . '/backend/punicoes_regras.php';
                $tiradas = 0;
                foreach ((array)json_decode((string)($pun['efeitos_json'] ?? ''), true) as $e) {
                    if (strtoupper((string)($e['efeito'] ?? '')) === 'CICLO_SEM_TROCA') {
                        $tiradas = (int)($e['valor'] ?? 0);
                    }
                }
                punicaoDevolverTrocasDoCiclo($pdo, $teamId, $tiradas);
            }
            if ($effectType === 'BAN_TRADES_PICKS') {
                $stmt = $pdo->prepare('UPDATE teams SET ban_trades_picks_until_cycle = NULL WHERE id = ? AND ban_trades_picks_until_cycle = ?');
                $stmt->execute([$teamId, $pun['ban_until_cycle']]);
            }
            if ($effectType === 'BAN_FREE_AGENCY') {
                $stmt = $pdo->prepare('UPDATE teams SET ban_fa_until_cycle = NULL WHERE id = ? AND ban_fa_until_cycle = ?');
                $stmt->execute([$teamId, $pun['ban_until_cycle']]);
            }
            if ($effectType === 'ROTACAO_AUTOMATICA') {
                $stmt = $pdo->prepare('UPDATE teams SET auto_rotation_until_cycle = NULL WHERE id = ? AND auto_rotation_until_cycle = ?');
                $stmt->execute([$teamId, $pun['ban_until_cycle']]);
            }

            $stmtRev = $pdo->prepare('UPDATE team_punishments SET reverted_at = NOW(), reverted_by = ? WHERE id = ?');
            $stmtRev->execute([$user['id'], $punishmentId]);

            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Erro ao reverter punição']);
        }
        exit;
    }

    $teamId = (int)($body['team_id'] ?? 0);
    $type = strtoupper(trim($body['type'] ?? ''));
    $notes = trim($body['notes'] ?? '');
    $motive = trim($body['motive'] ?? '');
    $punishmentLabel = trim($body['punishment_label'] ?? '');
    $effectType = strtoupper(trim($body['effect_type'] ?? $type));
    $pickId = isset($body['pick_id']) ? (int)$body['pick_id'] : null;
    $seasonScope = strtolower(trim($body['season_scope'] ?? 'current'));
    $createdAt = trim($body['created_at'] ?? '');
    // Mesma regra do caminho do quadro: registra sem cobrar. @see 'aplicar'
    $jaCumprida = !empty($body['ja_cumprida']);

    if (!$teamId || !in_array($effectType, $allowedTypes, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }

    $stmtTeam = $pdo->prepare('SELECT id, league FROM teams WHERE id = ?');
    $stmtTeam->execute([$teamId]);
    $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
    if (!$team) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Time não encontrado']);
        exit;
    }

    $league = $team['league'] ?? null;
    punBarrarLiga($league);
    $currentCycle = getTeamCurrentCycle($pdo, $teamId);
    $banUntil = $currentCycle;
    if ($seasonScope === 'next' && $currentCycle > 0) {
        $banUntil = $currentCycle + 1;
    }
    $banUntilForRecord = in_array($effectType, ['BAN_TRADES', 'BAN_TRADES_PICKS', 'BAN_FREE_AGENCY', 'ROTACAO_AUTOMATICA'], true) ? $banUntil : null;

    try {
        $pdo->beginTransaction();

        /* A PICK NÃO É MAIS APAGADA.
           Antes daqui saía um DELETE, e o time continuava escolhendo: a ordem
           do draft é derivada de `picks`, e vaga sem pick correspondente fica
           com quem estava lá. A punição mais usada da liga não tirava ninguém
           do draft. Agora a pick fica marcada, aparece PUNIDO na ordem e o
           draft pula. @see backend/punicoes_regras.php
           A marcação precisa do id da punição, então acontece depois do
           INSERT — aqui só guardamos o que vai ser cobrado. */
        $removedPick = null;
        /* Já cumprida não perde pick nem leva ban: a pena foi paga fora do
           sistema, e cobrar de novo é cobrar duas vezes. A linha fica no
           histórico e conta pra reincidência. */
        $perderPick = !$jaCumprida && in_array($effectType, ['PERDA_PICK_1R', 'PERDA_PICK_ESPECIFICA'], true);
        if (!$jaCumprida && $effectType === 'PERDA_PICK_ESPECIFICA' && !$pickId) {
            throw new Exception('Selecione a pick para remover');
        }
        if ($perderPick) {
            $alvo = $pdo->prepare($effectType === 'PERDA_PICK_ESPECIFICA'
                ? 'SELECT season_year, round, original_team_id, last_owner_team_id FROM picks WHERE id = ? AND team_id = ?'
                : "SELECT season_year, round, original_team_id, last_owner_team_id FROM picks
                    WHERE team_id = ? AND original_team_id = ? AND round = '1' AND punicao_id IS NULL
                 ORDER BY CAST(season_year AS UNSIGNED) ASC, id ASC LIMIT 1");
            $alvo->execute($effectType === 'PERDA_PICK_ESPECIFICA' ? [$pickId, $teamId] : [$teamId, $teamId]);
            $removedPick = $alvo->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$jaCumprida) {
            if ($effectType === 'BAN_TRADES') {
                $stmt = $pdo->prepare('UPDATE teams SET ban_trades_until_cycle = ? WHERE id = ?');
                $stmt->execute([$banUntil, $teamId]);
            }
            if ($effectType === 'BAN_TRADES_PICKS') {
                $stmt = $pdo->prepare('UPDATE teams SET ban_trades_picks_until_cycle = ? WHERE id = ?');
                $stmt->execute([$banUntil, $teamId]);
            }
            if ($effectType === 'BAN_FREE_AGENCY') {
                $stmt = $pdo->prepare('UPDATE teams SET ban_fa_until_cycle = ? WHERE id = ?');
                $stmt->execute([$banUntil, $teamId]);
            }
            if ($effectType === 'ROTACAO_AUTOMATICA') {
                $stmt = $pdo->prepare('UPDATE teams SET auto_rotation_until_cycle = ? WHERE id = ?');
                $stmt->execute([$banUntil, $teamId]);
            }
            /* CICLO SEM TROCA: gasta o saldo de trocas do ciclo. Quantas
               foram tiradas vira o `valor` do efeito logo abaixo — é de lá
               que o revert lê pra devolver exatamente isso, e não o limite
               inteiro. Não dá pra gravar no INSERT: o efeitos_json é
               reescrito depois dele, e a gravação de lá é que vale. */
            if ($effectType === 'CICLO_SEM_TROCA') {
                require_once dirname(__DIR__) . '/backend/punicoes_regras.php';
                $trocasTiradas = punicaoZerarTrocasDoCiclo($pdo, $teamId)['tiradas'];
            }
        }

        // Registrar punição
    $columns = 'team_id, league, type, motive, punishment_label, effect_type, notes, pick_id, season_scope, ban_until_cycle, removed_pick_season_year, removed_pick_round, removed_pick_original_team_id, removed_pick_last_owner_team_id, ja_cumprida, created_by';
        $values = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
        $params = [
            $teamId,
            $league,
            $effectType,
            $motive ?: null,
            $punishmentLabel ?: null,
            $effectType,
            $notes ?: null,
            $pickId ?: null,
            $seasonScope,
            $banUntilForRecord,
            $removedPick['season_year'] ?? null,
            $removedPick['round'] ?? null,
            $removedPick['original_team_id'] ?? null,
            $removedPick['last_owner_team_id'] ?? null,
            $jaCumprida ? 1 : 0,
            $user['id']
        ];

        if ($createdAt !== '') {
            $columns .= ', created_at';
            $values .= ', ?';
            $params[] = $createdAt;
        }

        $stmtIns = $pdo->prepare('INSERT INTO team_punishments (' . $columns . ') VALUES (' . $values . ')');
        $stmtIns->execute($params);
        $punicaoId = (int)$pdo->lastInsertId();

        /* O EFEITO, GRAVADO NO FORMATO QUE O MOTOR LÊ.
           Sem isto a punição continuaria sendo só um registro: é `efeitos_json`
           que trades, free agency e draft consultam. A duração vem do que o
           admin escolheu; na falta, o rótulo antigo "temporada atual/próxima"
           vira TEMPORADA — que é o que ele sempre quis dizer, e não o ciclo
           que o sistema cumpria escondido. */
        require_once dirname(__DIR__) . '/backend/punicoes_regras.php';
        $momento = punicaoMomentoDaLiga($pdo, (string)$league);
        $duracao = strtoupper(trim((string)($body['duracao'] ?? '')));
        if (!isset(PUNICAO_DURACOES[$duracao])) {
            $duracao = $seasonScope === 'next' ? 'TEMPORADA_NEXT' : 'TEMPORADA';
        }
        $vig = punicaoVigenciaDe($duracao, $momento['temporada'], $momento['ciclo']);
        $ehPeriodo = (PUNICAO_EFEITOS[$effectType]['duracao'] ?? 'evento') === 'periodo';
        $efeitoUnico = [[
            'efeito'   => $effectType,
            // O "ciclo sem troca" põe aqui quantas trocas tirou; o resto usa
            // o valor que veio do corpo (multa, teto de minutos).
            'valor'    => $trocasTiradas ?? (isset($body['valor']) ? (int)$body['valor'] : null),
            'vigencia' => $ehPeriodo ? $vig['vigencia'] : 'EVENTO',
            'desde'    => $ehPeriodo ? $vig['desde'] : null,
            'ate'      => $ehPeriodo ? $vig['ate'] : null,
        ]];
        $pdo->prepare('UPDATE team_punishments SET efeitos_json = ?, vigencia = ?, vigencia_desde = ?, vigencia_ate = ? WHERE id = ?')
            ->execute([
                json_encode($efeitoUnico, JSON_UNESCAPED_UNICODE),
                $ehPeriodo ? $vig['vigencia'] : 'EVENTO',
                $ehPeriodo ? $vig['desde'] : null,
                $ehPeriodo ? $vig['ate'] : null,
                $punicaoId,
            ]);

        $avisoPick = null;
        if ($perderPick) {
            $r = punicaoPerderPick($pdo, $teamId, $punicaoId,
                                   $effectType === 'PERDA_PICK_ESPECIFICA' ? (int)$pickId : null);
            if (!$r['ok']) $avisoPick = $r['motivo'];   // pendente: cobrada quando ele voltar a ter
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'punishment_id' => $punicaoId, 'aviso' => $avisoPick]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Erro interno do servidor.']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não suportado']);
