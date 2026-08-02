<?php
/**
 * API da Roleta dos 32 Times — sorteio da ordem inversa de escolha.
 *
 * Cada giro tira um time da urna: o primeiro a sair fica com a escolha 32, o
 * seguinte com a 31, e assim por diante até sobrar ninguém (escolha 1).
 *
 * Leitura é pública (a página pode ser vista por qualquer um, sem login).
 * Girar e reiniciar são restritos a admin.
 */

require_once __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

/** Os 32 participantes, na ordem da lista original. */
const ROLETA_TIMES = [
    ['Athens Olympics',         'Kleberson Barreto Costa'],
    ['Dallas Blues',            'Mateus Maia'],
    ['Miami Sunsets',           'Caio Esteves Motta'],
    ['Oregon Puddles',          'Daniel Victor Dias'],
    ['New York Mafia',          'Murilo Toledo'],
    ['Los Angeles Celestials',  'Lázaro Costa'],
    ['San Jose Carpinteros',    'Lennon Herman'],
    ['St. Louis Archers',       'Eduardo Antunes'],
    ['Washington Peacemakers',  'Victor Hugo Simoes'],
    ['México City Catrinas',    'Jose Vinicius Leal Cortez'],
    ['Houston Parfums',         'Kenderson Fellipe'],
    ['Los Angeles Souks',       'Henrick Taufner'],
    ['Anchorage Envood',        'Caio Capobiango Cardoso Fonseca (Zaza)'],
    ['Calgary Mooses',          'Kanada'],
    ['Buffalo Blackouts',       'Pedro Fava'],
    ['Kentucky Cavalinhos',     'Leonardo Cardoso'],
    ['San Francisco JoyBoys',   'Yan Simão'],
    ['Oakland Blue Foxes',      'Bruno Coelho'],
    ['Milwaukee Beezz',         'Vinicius Rocha'],
    ['New Jersey Reapers',      'Lucas Fernandes Rodrigues'],
    ['Orlando Black Lions',     'Anderson Silva'],
    ['San Antonio Vultures',    'Kevyn Martins'],
    ['Pittsburgh Phantoms',     'Ágata Máximo'],
    ['Chicago Dope',            'Thales Victor Freitas Coelho Gonzalez'],
    ['Kansas City Swifties',    'Caio Gomes'],
    ['Hawaii Heatwave',         'Guilherme Faleiro'],
    ['Philadelphia Devils',     'Matheus Sampaio'],
    ['Louisville Shuffle',      'Gabriel Jardim'],
    ['New York Alley Dogs',     'Erick Vasconcelos'],
    ['San Diego Empire',        'Vinicius Ferreira'],
    ['Utah Coyotes',            'Marcos Medeiros'],
    ['Anaheim Wyverns',         'Pedro Afonso Braga Nogueira'],
];

function ensureRoletaTimesTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS roleta_times (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ordem INT NOT NULL,
        team_name VARCHAR(120) NOT NULL,
        gm_name VARCHAR(180) NOT NULL,
        pick_number INT NULL,
        eliminated_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ordem (ordem),
        INDEX idx_pick (pick_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Semeia a lista só na primeira vez.
    if ((int)$pdo->query("SELECT COUNT(*) FROM roleta_times")->fetchColumn() === 0) {
        $ins = $pdo->prepare("INSERT INTO roleta_times (ordem, team_name, gm_name) VALUES (?,?,?)");
        foreach (ROLETA_TIMES as $i => $t) {
            $ins->execute([$i + 1, $t[0], $t[1]]);
        }
    }

    // team_id resolvido uma vez (por CONCAT(city,name), o mesmo casamento de
    // sempre) e guardado — sem isso, renomear um time em Gestão quebra
    // silenciosamente tanto o escudo quanto a notificação daquele time na
    // roleta, porque o casamento por string parava de bater. Uma vez
    // resolvido, team_id nunca é sobrescrito (não desfaz o vínculo se o nome
    // mudar de novo depois).
    try {
        $hasTeamId = $pdo->query("SHOW COLUMNS FROM roleta_times LIKE 'team_id'")->fetch();
        if (!$hasTeamId) {
            $pdo->exec("ALTER TABLE roleta_times ADD COLUMN team_id INT NULL AFTER team_name");
        }
        $pdo->exec("
            UPDATE roleta_times rt
            JOIN teams t ON CONCAT(t.city, ' ', t.name) = rt.team_name
            SET rt.team_id = t.id
            WHERE rt.team_id IS NULL
        ");
    } catch (Throwable $e) {
        error_log('ensureRoletaTimesTable (team_id): ' . $e->getMessage());
    }
}
ensureRoletaTimesTable($pdo);

/**
 * Avisa os 32 GMs da roleta (não a liga ELITE inteira — os 32 times se
 * misturam entre ligas) que alguém acabou de sair. Casamento é pelo
 * team_name da roleta -> teams.city+name -> teams.user_id, o mesmo usado
 * pra buscar o escudo em estadoRoleta(). Quem não bate (nome sem time
 * correspondente) simplesmente não recebe — best-effort, nunca quebra o
 * giro por causa de notificação.
 */
function notificarSaidaRoleta(PDO $pdo, string $gmName, int $pick): void
{
    $pushFile = dirname(__DIR__) . '/backend/push.php';
    if (!file_exists($pushFile)) return;

    try {
        $stmt = $pdo->query("
            SELECT DISTINCT t.user_id
            FROM roleta_times rt
            JOIN teams t ON t.id = rt.team_id
            WHERE t.user_id IS NOT NULL
        ");
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$userIds) {
            error_log('notificarSaidaRoleta: nenhum GM casado via team_id pra "' . $gmName . '" — verifique roleta_times.team_id');
        }
    } catch (Throwable $e) {
        error_log('notificarSaidaRoleta (buscar GMs): ' . $e->getMessage());
        return;
    }
    if (!$userIds) return;

    require_once $pushFile;
    $payload = [
        'title' => 'Roleta dos 32 🎲',
        'body'  => "{$gmName} saiu — escolha {$pick} definida!",
        'url'   => '/roleta-times.php',
    ];
    foreach ($userIds as $uid) {
        try {
            sendPushToUser($pdo, (int)$uid, $payload, 'eventos');
        } catch (Throwable $e) {
            error_log('notificarSaidaRoleta (push user_id=' . $uid . '): ' . $e->getMessage());
        }
    }
}

/** Só admin gira/reinicia; ver é livre. */
function exigirAdmin(PDO $pdo): void
{
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid || !hasAdminAccess($pdo, (int)$uid)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Apenas administradores podem girar a roleta.']);
        exit;
    }
}

/**
 * Estado atual. O escudo vem da tabela teams quando o nome bate — é só
 * enfeite, um time sem correspondência aparece com o escudo padrão.
 */
function estadoRoleta(PDO $pdo): array
{
    $sql = "SELECT rt.id, rt.ordem, rt.team_name, rt.gm_name, rt.pick_number, rt.eliminated_at,
                   t.photo_url
            FROM roleta_times rt
            LEFT JOIN teams t ON t.id = rt.team_id
            ORDER BY rt.ordem ASC";
    $linhas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $naUrna = [];
    $sorteados = [];
    foreach ($linhas as $l) {
        $l['pick_number'] = $l['pick_number'] !== null ? (int)$l['pick_number'] : null;
        if ($l['pick_number'] === null) {
            $naUrna[] = $l;
        } else {
            $sorteados[] = $l;
        }
    }
    // Histórico com o mais recente primeiro (menor pick_number = saiu por último).
    usort($sorteados, fn($a, $b) => $a['pick_number'] <=> $b['pick_number']);

    return [
        'na_urna'    => $naUrna,
        'sorteados'  => $sorteados,
        'total'      => count($linhas),
        'concluido'  => count($naUrna) === 0,
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode(['success' => true] + estadoRoleta($pdo));
    exit;
}

if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'girar') {
        exigirAdmin($pdo);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->query("SELECT id, team_name, gm_name FROM roleta_times WHERE pick_number IS NULL FOR UPDATE");
            $urna = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$urna) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'O sorteio já terminou.']);
                exit;
            }

            // Quem sai agora leva a pior escolha ainda disponível: com 32 na
            // urna vale a 32, com 31 vale a 31, e assim por diante.
            $escolhido = $urna[random_int(0, count($urna) - 1)];
            $pick = count($urna);

            $pdo->prepare("UPDATE roleta_times SET pick_number = ?, eliminated_at = NOW() WHERE id = ?")
                ->execute([$pick, $escolhido['id']]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('roleta-times girar: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Erro ao girar a roleta.']);
            exit;
        }

        // Fora da transação (já commitada) — notificação é best-effort e não
        // pode atrasar/travar a resposta do giro em si.
        notificarSaidaRoleta($pdo, $escolhido['gm_name'], $pick);

        echo json_encode([
            'success'      => true,
            'sorteado_id'  => (int)$escolhido['id'],
            'time'         => $escolhido['team_name'],
            'gm_name'      => $escolhido['gm_name'],
            'pick'         => $pick,
        ] + estadoRoleta($pdo));
        exit;
    }

    if ($action === 'reiniciar') {
        exigirAdmin($pdo);
        $pdo->exec("UPDATE roleta_times SET pick_number = NULL, eliminated_at = NULL");
        echo json_encode(['success' => true] + estadoRoleta($pdo));
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não suportado']);
