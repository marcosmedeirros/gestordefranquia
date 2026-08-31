<?php
/**
 * API do controle de drafts — uma roleta e um bolo de classes por liga.
 *
 * O circuito de uma temporada:
 *
 *   1. sortear     classe do bolo da liga → grava em draft_class_sorteios
 *   2. aplicar     jogadores da classe → draft_pool da sessão
 *   3. loteria     (já existe, em api/draft.php) → define a ordem
 *   4. abrir       draft_sessions.status = 'in_progress'
 *
 * Cada passo é conferido antes do seguinte, e `estado` devolve exatamente onde
 * a liga parou — a tela não adivinha nada.
 *
 * O sorteio é do SERVIDOR, sempre. Se o giro fosse do cliente daria pra ficar
 * regirando até cair a classe boa, e aí a roleta é enfeite. Mesmo motivo do
 * Build-A-Player.
 */
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/backend/helpers.php';
require_once dirname(__DIR__) . '/backend/draft_swaps.php';

$user = getUserSession();
if (!$user) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso negado']);
    exit;
}

$pdo = db();
$ehAdminGlobal = ($user['user_type'] ?? 'jogador') === 'admin';
$minhasLigas   = $ehAdminGlobal ? ['ELITE','NEXT','RISE','ROOKIE'] : getAdminLeagues($pdo, (int)$user['id']);
if (!$ehAdminGlobal && empty($minhasLigas)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso negado']);
    exit;
}

const CD_LIGAS = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

/**
 * OVR e idade de calouro entrando no draft.
 *
 * Quem define o que o jogador é de verdade é o 2K — aqui o draft só precisa
 * do nome, da posição e da ordem. Todo mundo entra igual, e a tela do draft
 * nem mostra esses dois números.
 *
 * Eles existem porque a pick vira uma linha em `players`, que tem as colunas.
 * Um dia isso pode virar configuração de liga; hoje é constante, e mudar aqui
 * muda em todo lugar.
 */
const CD_OVR_CALOURO   = 60;
const CD_IDADE_CALOURO = 18;

function cdErro(int $http, string $msg): void {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

/** A liga da requisição, já conferida contra as permissões de quem pediu. */
function cdLiga(array $minhasLigas): string {
    $liga = strtoupper(trim((string)($_GET['league'] ?? $_POST['league'] ?? '')));
    if ($liga === '') {
        $corpo = json_decode(file_get_contents('php://input'), true) ?: [];
        $liga = strtoupper(trim((string)($corpo['league'] ?? '')));
    }
    if (!in_array($liga, CD_LIGAS, true)) cdErro(400, 'Liga inválida.');
    if (!in_array($liga, $minhasLigas, true)) cdErro(403, 'Você não administra a ' . $liga . '.');
    return $liga;
}

function cdCorpo(): array {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

/**
 * Garante o schema. api/admin.php cria as tabelas de classe sob demanda e o
 * controle pode ser a primeira tela aberta numa instalação nova — sem isto a
 * página quebraria em vez de aparecer vazia.
 */
function cdGarantirSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS draft_class_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        league ENUM('ELITE','NEXT','RISE','ROOKIE') NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$pdo->query("SHOW COLUMNS FROM draft_class_templates LIKE 'league'")->fetch()) {
        $pdo->exec("ALTER TABLE draft_class_templates ADD COLUMN league ENUM('ELITE','NEXT','RISE','ROOKIE') NULL AFTER name");
    }
    /*
     * UMA CLASSE PODE SER DE VÁRIAS LIGAS.
     *
     * A coluna `league` guarda uma só, e é isso que ela sempre fez. Só que a
     * mesma classe de calouros serve pra mais de uma liga ao mesmo tempo —
     * atribuir a ELITE não deveria tirá-la da NEXT. Com uma coluna, a única
     * saída era duplicar a classe, e aí as duas cópias desandavam sozinhas.
     *
     * A tabela manda a partir de agora. A coluna fica: ela ainda é escrita
     * (com a primeira liga atribuída) porque outras telas a leem, e apagá-la
     * daqui quebraria o que não faz parte deste ajuste.
     */
    $pdo->exec("CREATE TABLE IF NOT EXISTS draft_class_template_leagues (
        template_id INT NOT NULL,
        league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (template_id, league),
        KEY idx_dctl_liga (league),
        CONSTRAINT fk_dctl_tpl FOREIGN KEY (template_id)
            REFERENCES draft_class_templates(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* O que já estava na coluna entra na tabela: sem isto as classes
       existentes sumiriam do bolo da liga delas no primeiro carregamento. */
    try {
        $pdo->exec("INSERT IGNORE INTO draft_class_template_leagues (template_id, league)
                    SELECT id, league FROM draft_class_templates WHERE league IS NOT NULL");
    } catch (Throwable $e) { error_log('[cd/migra ligas] ' . $e->getMessage()); }

    $pdo->exec("CREATE TABLE IF NOT EXISTS draft_class_template_players (
        id INT AUTO_INCREMENT PRIMARY KEY,
        template_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        position VARCHAR(20) NOT NULL,
        ovr INT NOT NULL,
        age INT NOT NULL,
        pick_hint INT NULL,
        INDEX idx_dctp_tpl (template_id),
        CONSTRAINT fk_dctp_tpl_cd FOREIGN KEY (template_id)
            REFERENCES draft_class_templates(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Mesmo caso do `escolhida` abaixo: a tabela já existia antes de pick_hint
    // entrar no CREATE, e CREATE IF NOT EXISTS não mexe em tabela pronta. Sem
    // este ALTER a coluna só nasce em banco novo — e como as consultas de
    // aplicar a classe e de importar CSV leem pick_hint, num banco antigo elas
    // quebravam com "Unknown column".
    if (!$pdo->query("SHOW COLUMNS FROM draft_class_template_players LIKE 'pick_hint'")->fetch()) {
        $pdo->exec("ALTER TABLE draft_class_template_players ADD COLUMN pick_hint INT NULL");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS draft_class_sorteios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
        template_id INT NOT NULL,
        season_id INT NULL,
        season_year INT NULL,
        sorteado_por INT NULL,
        sorteado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        escolhida TINYINT(1) NOT NULL DEFAULT 0,
        pool_aplicado_em DATETIME NULL,
        UNIQUE KEY uk_dcs_template (template_id),
        INDEX idx_dcs_liga (league),
        CONSTRAINT fk_dcs_tpl_cd FOREIGN KEY (template_id)
            REFERENCES draft_class_templates(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // A tabela já existe em produção, e o CREATE acima não mexe em tabela feita:
    // sem este ALTER a coluna nova só apareceria num banco criado do zero.
    if (!$pdo->query("SHOW COLUMNS FROM draft_class_sorteios LIKE 'escolhida'")->fetch()) {
        $pdo->exec("ALTER TABLE draft_class_sorteios ADD COLUMN escolhida TINYINT(1) NOT NULL DEFAULT 0");
    }
}

/**
 * A classe pertence a esta liga?
 *
 * Agora é pergunta de lista, não de coluna: a mesma classe pode ser de várias
 * ligas ao mesmo tempo. A coluna `league` entra como reserva pra base que
 * ainda não passou pela migração da tabela.
 */
function cdClasseEhDaLiga(PDO $pdo, int $tplId, string $liga): bool
{
    try {
        $st = $pdo->prepare("SELECT 1 FROM draft_class_template_leagues WHERE template_id = ? AND league = ?");
        $st->execute([$tplId, $liga]);
        if ($st->fetchColumn()) return true;
    } catch (Throwable $e) { error_log('[cdClasseEhDaLiga] ' . $e->getMessage()); }

    $st = $pdo->prepare("SELECT league FROM draft_class_templates WHERE id = ?");
    $st->execute([$tplId]);
    return ($st->fetchColumn() ?: null) === $liga;
}

/** A temporada corrente da liga e o ano dela. */
function cdTemporada(PDO $pdo, string $liga): ?array {
    $st = $pdo->prepare("SELECT s.id, s.season_number, s.year, s.status, sp.start_year
                         FROM seasons s LEFT JOIN sprints sp ON sp.id = s.sprint_id
                         WHERE s.league = ? AND (s.status IS NULL OR s.status <> 'completed')
                         ORDER BY s.season_number DESC, s.id DESC LIMIT 1");
    $st->execute([$liga]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return null;
    $s['ano'] = (!empty($s['start_year']) && isset($s['season_number']))
        ? (int)$s['start_year'] + (int)$s['season_number'] - 1
        : (int)($s['year'] ?? 0);
    return $s;
}

/** A sessão de draft da temporada, se já existir. */
/**
 * Joga os jogadores de uma classe no pool da temporada.
 *
 * Vive numa função porque agora tem DOIS chamadores: o sorteio (que já
 * deixa tudo pronto de uma vez) e o botão "aplicar classe", que segue
 * existindo pras temporadas sorteadas antes desta mudança.
 *
 * Não abre transação — quem chama já está dentro de uma.
 *
 * @return int quantos entraram
 */
function cdAplicarPool(PDO $pdo, int $templateId, int $seasonId): int {
    $st = $pdo->prepare("SELECT name, position, pick_hint
                         FROM draft_class_template_players WHERE template_id = ?
                         ORDER BY COALESCE(pick_hint, 999999) ASC, ovr DESC");
    $st->execute([$templateId]);
    $jogadores = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$jogadores) return 0;

    $ins = $pdo->prepare("INSERT INTO draft_pool (season_id, name, position, age, ovr, pick_hint, draft_status)
                          VALUES (?,?,?,?,?,?,'available')");
    foreach ($jogadores as $j) {
        // Todo calouro entra igual: 60 de OVR e 18 anos. O que ele é de
        // verdade fica por conta do 2K, e a tela do draft nem mostra esses
        // números — o que importa aqui é nome, posição e ordem.
        $ins->execute([$seasonId, $j['name'], strtoupper((string)$j['position']),
                       CD_IDADE_CALOURO, CD_OVR_CALOURO,
                       $j['pick_hint'] !== null ? (int)$j['pick_hint'] : null]);
    }
    return count($jogadores);
}

/**
 * Garante a sessão de draft da temporada, criando-a em 'setup' se faltar.
 *
 * @return int o id da sessão
 */
function cdGarantirSessao(PDO $pdo, string $liga, int $seasonId): int {
    $st = $pdo->prepare("SELECT id FROM draft_sessions WHERE season_id = ? LIMIT 1");
    $st->execute([$seasonId]);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id > 0) return $id;

    $pdo->prepare("INSERT INTO draft_sessions (season_id, league, status, total_rounds)
                   VALUES (?,?, 'setup', 2)")->execute([$seasonId, $liga]);
    return (int)$pdo->lastInsertId();
}

function cdSessao(PDO $pdo, string $liga, int $seasonId): ?array {
    $st = $pdo->prepare("SELECT * FROM draft_sessions WHERE league = ? AND season_id = ?
                         ORDER BY id DESC LIMIT 1");
    $st->execute([$liga, $seasonId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * O estado do circuito da liga: o que já foi feito, o que falta, e o motivo
 * de cada passo que ainda não dá pra rodar.
 */
function cdEstado(PDO $pdo, string $liga): array {
    cdGarantirSchema($pdo);

    $temp = cdTemporada($pdo, $liga);
    $sessao = $temp ? cdSessao($pdo, $liga, (int)$temp['id']) : null;

    // Classes da liga, separadas entre disponíveis e já usadas.
    $st = $pdo->prepare("SELECT t.id, t.name, t.created_at,
                                (SELECT COUNT(*) FROM draft_class_template_players p WHERE p.template_id = t.id) AS jogadores,
                                s.id AS sorteio_id, s.season_year AS usada_em, s.sorteado_em
                         FROM draft_class_templates t
                         LEFT JOIN draft_class_sorteios s ON s.template_id = t.id
                         WHERE EXISTS (SELECT 1 FROM draft_class_template_leagues l
                                        WHERE l.template_id = t.id AND l.league = ?)
                         ORDER BY t.name");
    $st->execute([$liga]);
    $disponiveis = []; $usadas = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $c['jogadores'] = (int)$c['jogadores'];
        // Classe sem jogador não entra na roleta (ver o HAVING do sorteio).
        // Contá-la como "no bolo" faria a tela prometer um número de classes
        // maior do que o sorteio consegue entregar.
        $c['sorteavel'] = $c['jogadores'] > 0;
        if ($c['sorteio_id']) $usadas[] = $c; else $disponiveis[] = $c;
    }
    $sorteaveis = array_values(array_filter($disponiveis, fn($c) => $c['sorteavel']));

    // Classes ainda sem liga: existiam antes desta tela e precisam ser
    // atribuídas por alguém — o app não adivinha de qual liga é "1994".
    /* "Sem liga" agora é "sem NENHUMA liga na tabela" — uma classe que já é
       da NEXT continua aparecendo aqui pra ELITE, que é o ponto: dar pra
       atribuir a mesma classe a mais de uma liga. */
    $st = $pdo->prepare("SELECT t.id, t.name,
                                (SELECT COUNT(*) FROM draft_class_template_players p WHERE p.template_id = t.id) AS jogadores,
                                (SELECT GROUP_CONCAT(o.league ORDER BY o.league SEPARATOR ', ')
                                   FROM draft_class_template_leagues o WHERE o.template_id = t.id) AS ligas
                         FROM draft_class_templates t
                        WHERE NOT EXISTS (SELECT 1 FROM draft_class_template_leagues l
                                           WHERE l.template_id = t.id AND l.league = ?)
                        ORDER BY t.name");
    $st->execute([$liga]);
    $semLiga = $st->fetchAll(PDO::FETCH_ASSOC);

    // O sorteio desta temporada, se houve.
    $sorteioAtual = null;
    if ($temp) {
        $st = $pdo->prepare("SELECT s.*, t.name AS classe_nome,
                                    (SELECT COUNT(*) FROM draft_class_template_players p WHERE p.template_id = s.template_id) AS jogadores
                             FROM draft_class_sorteios s JOIN draft_class_templates t ON t.id = s.template_id
                             WHERE s.league = ? AND s.season_id = ? ORDER BY s.id DESC LIMIT 1");
        $st->execute([$liga, (int)$temp['id']]);
        $sorteioAtual = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $noPool = 0;
    if ($temp) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM draft_pool WHERE season_id = ?");
        $st->execute([(int)$temp['id']]);
        $noPool = (int)$st->fetchColumn();
    }

    $naOrdem = 0;
    if ($sessao) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM draft_order WHERE draft_session_id = ?");
        $st->execute([(int)$sessao['id']]);
        $naOrdem = (int)$st->fetchColumn();
    }

    // A loteria nasce da classificação. Sem campanha registrada não há de onde
    // tirar quem entra no sorteio — e é o passo que trava hoje.
    $comCampanha = 0;
    if ($temp) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM season_standings WHERE season_id = ?");
        $st->execute([(int)$temp['id']]);
        $comCampanha = (int)$st->fetchColumn();
    }

    $passos = [
        [
            'chave' => 'classe',
            'titulo' => 'Classe sorteada',
            'feito' => (bool)$sorteioAtual,
            'detalhe' => $sorteioAtual
                ? $sorteioAtual['classe_nome'] . ' · ' . (int)$sorteioAtual['jogadores'] . ' jogadores'
                : count($sorteaveis) . ' classe(s) no bolo',
            'bloqueio' => $temp
                ? (count($sorteaveis) === 0 && !$sorteioAtual
                    ? (count($disponiveis) > 0
                        ? 'As classes desta liga estão sem jogadores cadastrados.'
                        : 'Nenhuma classe disponível nesta liga. Cadastre uma abaixo.')
                    : null)
                : 'A liga não tem temporada em aberto.',
        ],
        [
            'chave' => 'pool',
            'titulo' => 'Jogadores no draft',
            'feito' => $noPool > 0,
            'detalhe' => $noPool > 0 ? $noPool . ' jogador(es) no pool' : 'pool vazio',
            'bloqueio' => !$sorteioAtual ? 'Sorteie a classe primeiro.' : null,
        ],
        [
            'chave' => 'loteria',
            'titulo' => 'Loteria e ordem',
            'feito' => $naOrdem > 0,
            'detalhe' => $naOrdem > 0 ? $naOrdem . ' escolha(s) na ordem' : 'ordem não gerada',
            'bloqueio' => $comCampanha === 0
                ? 'A temporada não tem classificação registrada — a loteria sai da campanha.'
                : (!$sessao ? 'Sem sessão de draft criada para a temporada.' : null),
        ],
        [
            'chave' => 'aberto',
            'titulo' => 'Draft aberto',
            'feito' => $sessao && $sessao['status'] === 'in_progress',
            'detalhe' => $sessao ? 'status: ' . $sessao['status'] : 'sem sessão',
            'bloqueio' => $naOrdem === 0 ? 'Gere a ordem antes de abrir.' : null,
        ],
    ];

    return [
        'league' => $liga,
        'temporada' => $temp ? [
            'id' => (int)$temp['id'],
            'numero' => (int)$temp['season_number'],
            'ano' => (int)$temp['ano'],
            'status' => $temp['status'],
        ] : null,
        'sessao' => $sessao ? [
            'id' => (int)$sessao['id'],
            'status' => $sessao['status'],
            'total_rounds' => (int)$sessao['total_rounds'],
        ] : null,
        'sorteio' => $sorteioAtual,
        'classes_disponiveis' => $disponiveis,
        'classes_sorteaveis' => count($sorteaveis),
        'classes_usadas' => $usadas,
        'classes_sem_liga' => $semLiga,
        'com_campanha' => $comCampanha,
        'passos' => $passos,
    ];
}

// ── Rotas ────────────────────────────────────────────────────────────────
$acao = $_GET['action'] ?? $_POST['action'] ?? (cdCorpo()['action'] ?? 'estado');

try {
    if ($acao === 'estado') {
        $liga = cdLiga($minhasLigas);
        echo json_encode(['success' => true, 'estado' => cdEstado($pdo, $liga)]);
        exit;
    }

    if ($acao === 'minhas_ligas') {
        echo json_encode(['success' => true, 'ligas' => array_values($minhasLigas)]);
        exit;
    }

    /** Atribui uma classe órfã a uma liga. */
    if ($acao === 'atribuir_liga') {
        cdGarantirSchema($pdo);
        $liga = cdLiga($minhasLigas);
        $tplId = (int)(cdCorpo()['template_id'] ?? 0);
        if (!$tplId) cdErro(400, 'template_id obrigatório.');

        $st = $pdo->prepare("SELECT league FROM draft_class_templates WHERE id = ?");
        $st->execute([$tplId]);
        $atual = $st->fetch(PDO::FETCH_ASSOC);
        if (!$atual) cdErro(404, 'Classe não encontrada.');

        /*
         * ATRIBUIR SOMA, NÃO SUBSTITUI.
         *
         * Antes isto recusava classe que já tinha dono: "essa classe já
         * pertence à ELITE". A mesma classe de calouros serve pra mais de uma
         * liga, e a única saída era duplicá-la — duas cópias que desandam
         * sozinhas na primeira edição.
         *
         * Agora a liga entra na lista. Já estar lá não é erro: o admin clicou
         * duas vezes, e o resultado que ele quer já é o que está no banco.
         */
        $ja = $pdo->prepare("SELECT 1 FROM draft_class_template_leagues WHERE template_id = ? AND league = ?");
        $ja->execute([$tplId, $liga]);
        if ($ja->fetchColumn()) {
            echo json_encode(['success' => true, 'message' => 'Essa classe já era da ' . $liga . '.']);
            exit;
        }

        $pdo->prepare("INSERT IGNORE INTO draft_class_template_leagues (template_id, league) VALUES (?, ?)")
            ->execute([$tplId, $liga]);
        // A coluna guarda a PRIMEIRA liga, pras telas que ainda a leem.
        if (empty($atual['league'])) {
            $pdo->prepare("UPDATE draft_class_templates SET league = ? WHERE id = ?")->execute([$liga, $tplId]);
        }

        $n = $pdo->prepare("SELECT COUNT(*) FROM draft_class_template_leagues WHERE template_id = ?");
        $n->execute([$tplId]);
        $total = (int)$n->fetchColumn();
        echo json_encode(['success' => true,
            'message' => 'Classe atribuída à ' . $liga . '.' . ($total > 1 ? " Agora ela é de {$total} ligas." : '')]);
        exit;
    }

    /**
     * Traz TODAS as classes sem liga pro bolo desta liga.
     *
     * Quem cadastrou 25 classes de uma vez não vai clicar 25 vezes. O WHERE
     * exige league IS NULL, então classe que já é de alguém não vem junto nem
     * por engano.
     */
    if ($acao === 'atribuir_liga_todas') {
        cdGarantirSchema($pdo);
        $liga = cdLiga($minhasLigas);
        // Todas as que ainda NÃO são desta liga — inclusive as que já são de
        // outra, que agora podem ser das duas.
        $st = $pdo->prepare("INSERT IGNORE INTO draft_class_template_leagues (template_id, league)
                             SELECT t.id, ? FROM draft_class_templates t
                              WHERE NOT EXISTS (SELECT 1 FROM draft_class_template_leagues l
                                                 WHERE l.template_id = t.id AND l.league = ?)");
        $st->execute([$liga, $liga]);
        $n = $st->rowCount();
        // A coluna acompanha só quem não tinha nenhuma.
        $pdo->prepare("UPDATE draft_class_templates SET league = ? WHERE league IS NULL")->execute([$liga]);
        echo json_encode(['success' => true, 'movidas' => $n,
            'message' => $n > 0 ? $n . ' classe(s) agora são da ' . $liga . '.' : 'Não havia classe sem liga.']);
        exit;
    }

    /** Cria uma classe vazia já no bolo da liga. */
    if ($acao === 'criar_classe') {
        cdGarantirSchema($pdo);
        $liga = cdLiga($minhasLigas);
        $nome = trim((string)(cdCorpo()['name'] ?? ''));
        if ($nome === '') cdErro(400, 'Dê um nome à classe.');
        if (mb_strlen($nome) > 120) cdErro(400, 'Nome longo demais (máximo 120).');

        $st = $pdo->prepare("INSERT INTO draft_class_templates (name, league, created_by) VALUES (?,?,?)");
        $st->execute([$nome, $liga, (int)$user['id']]);
        echo json_encode(['success' => true, 'template_id' => (int)$pdo->lastInsertId(),
            'message' => 'Classe criada. Agora importe os jogadores.']);
        exit;
    }

    /** Troca a lista de jogadores de uma classe pela que veio. */
    if ($acao === 'importar_jogadores') {
        cdGarantirSchema($pdo);
        $liga = cdLiga($minhasLigas);
        $corpo = cdCorpo();
        $tplId = (int)($corpo['template_id'] ?? 0);
        $jogadores = is_array($corpo['players'] ?? null) ? $corpo['players'] : [];
        if (!$tplId) cdErro(400, 'Classe não informada.');
        if (!$jogadores) cdErro(400, 'Nenhum jogador no arquivo.');

        $st = $pdo->prepare("SELECT t.league, s.id AS sorteio_id
                             FROM draft_class_templates t
                             LEFT JOIN draft_class_sorteios s ON s.template_id = t.id
                             WHERE t.id = ?");
        $st->execute([$tplId]);
        $classe = $st->fetch(PDO::FETCH_ASSOC);
        if (!$classe) cdErro(404, 'Classe não encontrada.');
        if (!cdClasseEhDaLiga($pdo, $tplId, $liga)) cdErro(403, "Essa classe não é da " . $liga . ".");
        // Classe já sorteada é história. Trocar os jogadores dela agora faria a
        // lista não bater mais com o draft que saiu dela.
        if (!empty($classe['sorteio_id'])) cdErro(409, 'Essa classe já foi sorteada — a lista dela não muda mais.');

        $limpos = [];
        foreach ($jogadores as $j) {
            $nome = trim((string)($j['name'] ?? ''));
            if ($nome === '') continue;
            $pos = strtoupper(trim((string)($j['position'] ?? '')));
            if (!in_array($pos, ['PG','SG','SF','PF','C'], true)) $pos = 'PG';
            $ordem = ($j['pick_hint'] ?? '') !== '' ? (int)$j['pick_hint'] : null;
            // OVR e idade não vêm do arquivo: são o 2K que define, e aqui todo
            // calouro é igual. Se o CSV trouxer essas colunas, elas são
            // ignoradas — guardar um número que nunca vai ser usado só criaria
            // a dúvida de por que a tela mostra um e o jogo outro.
            $limpos[] = [mb_substr($nome, 0, 120), $pos, CD_OVR_CALOURO, CD_IDADE_CALOURO, $ordem];
        }
        if (!$limpos) cdErro(400, 'Nenhuma linha aproveitável — confira se o arquivo tem a coluna de nome.');

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM draft_class_template_players WHERE template_id = ?")->execute([$tplId]);
            $ins = $pdo->prepare("INSERT INTO draft_class_template_players
                                  (template_id, name, position, ovr, age, pick_hint) VALUES (?,?,?,?,?,?)");
            foreach ($limpos as $j) $ins->execute([$tplId, $j[0], $j[1], $j[2], $j[3], $j[4]]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        echo json_encode(['success' => true, 'inseridos' => count($limpos),
            'message' => count($limpos) . ' jogador(es) na classe.']);
        exit;
    }

    /** Apaga uma classe que ainda não foi sorteada. */
    if ($acao === 'excluir_classe') {
        cdGarantirSchema($pdo);
        $liga = cdLiga($minhasLigas);
        $tplId = (int)(cdCorpo()['template_id'] ?? 0);
        if (!$tplId) cdErro(400, 'Classe não informada.');

        $st = $pdo->prepare("SELECT t.league, s.id AS sorteio_id
                             FROM draft_class_templates t
                             LEFT JOIN draft_class_sorteios s ON s.template_id = t.id
                             WHERE t.id = ?");
        $st->execute([$tplId]);
        $classe = $st->fetch(PDO::FETCH_ASSOC);
        if (!$classe) cdErro(404, 'Classe não encontrada.');
        if (!cdClasseEhDaLiga($pdo, $tplId, $liga)) cdErro(403, "Essa classe não é da " . $liga . ".");
        if (!empty($classe['sorteio_id'])) cdErro(409, 'Classe já sorteada não pode ser apagada — é o registro do que aconteceu.');

        $pdo->prepare("DELETE FROM draft_class_templates WHERE id = ?")->execute([$tplId]);
        echo json_encode(['success' => true, 'message' => 'Classe apagada.']);
        exit;
    }

    /** Os jogadores de uma classe, pra conferir na tela. */
    if ($acao === 'jogadores_da_classe') {
        cdGarantirSchema($pdo);
        $liga = cdLiga($minhasLigas);
        $tplId = (int)($_GET['template_id'] ?? 0);
        if (!$tplId) cdErro(400, 'Classe não informada.');
        $st = $pdo->prepare("SELECT league FROM draft_class_templates WHERE id = ?");
        $st->execute([$tplId]);
        if ($st->fetchColumn() !== $liga) cdErro(403, 'Essa classe não é da ' . $liga . '.');

        $st = $pdo->prepare("SELECT name, position, ovr, age, pick_hint
                             FROM draft_class_template_players WHERE template_id = ?
                             ORDER BY COALESCE(pick_hint, 999999), ovr DESC");
        $st->execute([$tplId]);
        echo json_encode(['success' => true, 'players' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    /**
     * Gira a roleta. O resultado sai daqui, não do navegador.
     */
    /**
     * Define a classe da temporada: 'sortear' tira na roleta, 'escolher_classe'
     * usa a que o admin apontou.
     *
     * O caminho é o mesmo de propósito. A parte que importa — só uma classe por
     * temporada, classe usada não volta pro bolo, tudo dentro de uma transação —
     * vale igual nos dois casos, e duplicar isso em dois blocos era garantir que
     * um dia só um dos dois receberia a próxima correção.
     */
    if ($acao === 'sortear' || $acao === 'escolher_classe') {
        cdGarantirSchema($pdo);
        $liga = cdLiga($minhasLigas);
        $escolhendo = $acao === 'escolher_classe';
        $alvo = (int)($_POST['template_id'] ?? cdCorpo()['template_id'] ?? 0);
        if ($escolhendo && $alvo <= 0) cdErro(422, 'Escolha uma classe.');

        $temp = cdTemporada($pdo, $liga);
        if (!$temp) cdErro(409, 'A ' . $liga . ' não tem temporada em aberto.');

        $pdo->beginTransaction();
        try {
            // Confere de novo DENTRO da transação: entre a tela carregar e o
            // clique, outro admin pode ter sorteado.
            $st = $pdo->prepare("SELECT COUNT(*) FROM draft_class_sorteios WHERE league = ? AND season_id = ?");
            $st->execute([$liga, (int)$temp['id']]);
            if ((int)$st->fetchColumn() > 0) {
                $pdo->rollBack();
                cdErro(409, 'Esta temporada já tem classe definida.');
            }

            // Candidatas: da liga, com jogadores, e nunca sorteadas.
            $st = $pdo->prepare("SELECT t.id, t.name,
                                        (SELECT COUNT(*) FROM draft_class_template_players p WHERE p.template_id = t.id) AS jogadores
                                 FROM draft_class_templates t
                                 LEFT JOIN draft_class_sorteios s ON s.template_id = t.id
                                 WHERE EXISTS (SELECT 1 FROM draft_class_template_leagues l
                                                WHERE l.template_id = t.id AND l.league = ?)
                                   AND s.id IS NULL
                                 HAVING jogadores > 0
                                 ORDER BY t.id");
            $st->execute([$liga]);
            $candidatas = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$candidatas) {
                $pdo->rollBack();
                cdErro(409, 'Não há classe disponível com jogadores cadastrados na ' . $liga . '.');
            }

            if ($escolhendo) {
                // Tem que ser uma das CANDIDATAS, e não um id qualquer: as regras
                // de quem pode entrar (é da liga, tem jogador, nunca foi usada)
                // são as mesmas — escolher não é atalho pra furar nenhuma delas.
                $escolhida = null;
                foreach ($candidatas as $c) if ((int)$c['id'] === $alvo) { $escolhida = $c; break; }
                if (!$escolhida) {
                    $pdo->rollBack();
                    cdErro(422, 'Essa classe não está disponível pra ' . $liga . ' — pode já ter sido usada ou estar sem jogadores.');
                }
            } else {
                $escolhida = $candidatas[random_int(0, count($candidatas) - 1)];
            }

            $ins = $pdo->prepare("INSERT INTO draft_class_sorteios
                (league, template_id, season_id, season_year, sorteado_por, escolhida)
                VALUES (?,?,?,?,?,?)");
            $ins->execute([$liga, (int)$escolhida['id'], (int)$temp['id'], (int)$temp['ano'],
                           (int)$user['id'], $escolhendo ? 1 : 0]);

            // Definida a classe, o draft já nasce montado: os jogadores entram
            // no pool e a sessão é criada em 'setup'. Antes isso eram dois
            // cliques separados depois do sorteio, e enquanto eles não vinham
            // a página de drafts não tinha o que mostrar — nem o mock, que é
            // justamente o que a liga quer ver antes da loteria. Só a ORDEM
            // continua pra depois: ela sai da campanha, não da classe.
            $noPool = 0;
            $st = $pdo->prepare("SELECT COUNT(*) FROM draft_pool WHERE season_id = ?");
            $st->execute([(int)$temp['id']]);
            if ((int)$st->fetchColumn() === 0) {
                $noPool = cdAplicarPool($pdo, (int)$escolhida['id'], (int)$temp['id']);
                if ($noPool > 0) {
                    $pdo->prepare("UPDATE draft_class_sorteios SET pool_aplicado_em = NOW()
                                   WHERE league = ? AND season_id = ?")
                        ->execute([$liga, (int)$temp['id']]);
                }
            }
            $sessaoId = cdGarantirSessao($pdo, $liga, (int)$temp['id']);
            $pdo->commit();

            echo json_encode(['success' => true,
                'escolhida_a_dedo' => $escolhendo,
                'no_pool' => $noPool,
                'sessao_id' => $sessaoId,
                'sorteada' => ['id' => (int)$escolhida['id'], 'name' => $escolhida['name'],
                               'jogadores' => (int)$escolhida['jogadores']],
                // A tela usa a lista pra girar a animação passando por todas
                // antes de parar na sorteada — o resultado já veio decidido.
                'candidatas' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], $candidatas),
                'message' => ($escolhendo ? 'Classe definida: ' : 'Classe sorteada: ') . $escolhida['name']
                             . ($noPool > 0 ? ' — ' . $noPool . ' jogador(es) já no draft.' : '')]);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Passo 2: joga os jogadores da classe sorteada no draft_pool da temporada.
     */
    if ($acao === 'aplicar_classe') {
        cdGarantirSchema($pdo);
        $liga = cdLiga($minhasLigas);
        $temp = cdTemporada($pdo, $liga);
        if (!$temp) cdErro(409, 'A ' . $liga . ' não tem temporada em aberto.');

        $st = $pdo->prepare("SELECT * FROM draft_class_sorteios WHERE league = ? AND season_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$liga, (int)$temp['id']]);
        $sorteio = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sorteio) cdErro(409, 'Sorteie a classe antes de aplicar.');

        // Pool já preenchido: não duplica. Aplicar duas vezes criaria dois de
        // cada jogador, e ninguém percebe até o draft rodar.
        $st = $pdo->prepare("SELECT COUNT(*) FROM draft_pool WHERE season_id = ?");
        $st->execute([(int)$temp['id']]);
        if ((int)$st->fetchColumn() > 0) {
            cdErro(409, 'O draft desta temporada já tem jogadores. Limpe o pool antes de aplicar de novo.');
        }

        $pdo->beginTransaction();
        try {
            $inseridos = cdAplicarPool($pdo, (int)$sorteio['template_id'], (int)$temp['id']);
            if ($inseridos === 0) {
                $pdo->rollBack();
                cdErro(409, 'A classe sorteada não tem jogadores cadastrados.');
            }
            $pdo->prepare("UPDATE draft_class_sorteios SET pool_aplicado_em = NOW() WHERE id = ?")
                ->execute([(int)$sorteio['id']]);
            // A sessão nasce junto: sem ela a loteria fica bloqueada e a página
            // de drafts não tem o que abrir.
            cdGarantirSessao($pdo, $liga, (int)$temp['id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        echo json_encode(['success' => true, 'inseridos' => $inseridos,
            'message' => $inseridos . ' jogador(es) entraram no draft.']);
        exit;
    }

    /** Desfaz o passo 2 — o pool volta a ficar vazio pra ser aplicado de novo. */
    if ($acao === 'limpar_pool') {
        cdGarantirSchema($pdo);
        $liga = cdLiga($minhasLigas);
        $temp = cdTemporada($pdo, $liga);
        if (!$temp) cdErro(409, 'A ' . $liga . ' não tem temporada em aberto.');

        $sessao = cdSessao($pdo, $liga, (int)$temp['id']);
        // Draft rodando: apagar o pool no meio tiraria jogador da mesa de
        // quem está escolhendo agora.
        if ($sessao && $sessao['status'] === 'in_progress') {
            cdErro(409, 'O draft está em andamento. Feche-o antes de limpar o pool.');
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM draft_pool WHERE season_id = ? AND draft_status <> 'available'");
        $st->execute([(int)$temp['id']]);
        if ((int)$st->fetchColumn() > 0) {
            cdErro(409, 'Já há jogadores escolhidos neste draft. Limpar o pool apagaria as escolhas.');
        }

        $st = $pdo->prepare("DELETE FROM draft_pool WHERE season_id = ?");
        $st->execute([(int)$temp['id']]);
        echo json_encode(['success' => true, 'message' => $st->rowCount() . ' jogador(es) removidos do pool.']);
        exit;
    }

    /** Passo 4: abre o draft pra galera escolher. */
    if ($acao === 'abrir_draft') {
        cdGarantirSchema($pdo);
        $liga = cdLiga($minhasLigas);
        $temp = cdTemporada($pdo, $liga);
        if (!$temp) cdErro(409, 'A ' . $liga . ' não tem temporada em aberto.');

        $sessao = cdSessao($pdo, $liga, (int)$temp['id']);
        if (!$sessao) cdErro(409, 'Não há sessão de draft para esta temporada.');
        if ($sessao['status'] === 'in_progress') cdErro(409, 'O draft já está aberto.');
        if ($sessao['status'] === 'completed') cdErro(409, 'Este draft já foi encerrado.');

        $st = $pdo->prepare("SELECT COUNT(*) FROM draft_order WHERE draft_session_id = ?");
        $st->execute([(int)$sessao['id']]);
        if ((int)$st->fetchColumn() === 0) cdErro(409, 'A ordem do draft ainda não foi gerada.');

        $st = $pdo->prepare("SELECT COUNT(*) FROM draft_pool WHERE season_id = ?");
        $st->execute([(int)$temp['id']]);
        if ((int)$st->fetchColumn() === 0) cdErro(409, 'O draft não tem jogadores. Aplique a classe antes.');

        // Última conferência antes de alguém escolher: dono de pick trocada e
        // swap valendo na ordem. Se nada mudou desde a geração, não mexe em
        // nada — mas é o último momento em que dá pra corrigir sem atrapalhar
        // quem já está escolhendo.
        $ajustes = draftSincronizarOrdem($pdo, (int)$sessao['id']);

        $pdo->prepare("UPDATE draft_sessions SET status = 'in_progress', started_at = COALESCE(started_at, NOW()),
                       current_pick_started_at = NOW() WHERE id = ?")->execute([(int)$sessao['id']]);

        $extra = '';
        if ($ajustes['donos'] > 0) $extra .= ' ' . $ajustes['donos'] . ' vaga(s) ajustada(s) por troca de pick.';
        if ($ajustes['swaps'] > 0) $extra .= ' ' . $ajustes['swaps'] . ' vaga(s) trocada(s) por swap.';
        echo json_encode(['success' => true, 'message' => 'Draft aberto.' . $extra]);
        exit;
    }

    cdErro(400, 'Ação desconhecida.');
} catch (Throwable $e) {
    error_log('[controledrafts] ' . $acao . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno. O admin foi avisado no log.']);
}
