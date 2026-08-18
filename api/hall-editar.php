<?php
/**
 * Hall da Fama aberto: qualquer GM logado pode incluir, corrigir e apagar.
 *
 * Endpoint separado do api/admin.php de propósito. Aquele é a superfície
 * inteira de administração, escopada por liga; abrir "o Hall" por lá seria
 * abrir uma porta que dá em muito mais coisa que o Hall. Aqui só existe
 * hall_of_fame, e é só o que este arquivo sabe fazer.
 *
 * Toda alteração fica registrada em hall_of_fame_log com quem fez e o que
 * havia antes. Edição aberta sem rastro é irreversível na primeira vez que
 * alguém apagar a linha errada — com o log, o conteúdo apagado ainda está lá.
 */

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/backend/helpers.php';

requireAuth();

$pdo  = db();
$user = getUserSession();

ensureHallOfFameTable($pdo);

/** O diário de bordo do Hall. Nasce junto com o primeiro uso do editor. */
function hofLogGarantirTabela(PDO $pdo): void
{
    static $ok = false;
    if ($ok) return;
    $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS hall_of_fame_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hof_id INT NULL,
            acao ENUM('criou','editou','apagou') NOT NULL,
            user_id INT NULL,
            user_nome VARCHAR(255) NULL,
            antes TEXT NULL,
            depois TEXT NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_hof_log_data (criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[hall-editar] log: ' . $e->getMessage());
    }
}

function hofRegistrar(PDO $pdo, array $user, string $acao, ?int $hofId, ?array $antes, ?array $depois): void
{
    hofLogGarantirTabela($pdo);
    try {
        $st = $pdo->prepare("INSERT INTO hall_of_fame_log
            (hof_id, acao, user_id, user_nome, antes, depois) VALUES (?,?,?,?,?,?)");
        $st->execute([
            $hofId, $acao, (int)($user['id'] ?? 0), (string)($user['name'] ?? ''),
            $antes  !== null ? json_encode($antes,  JSON_UNESCAPED_UNICODE) : null,
            $depois !== null ? json_encode($depois, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        error_log('[hall-editar] registrar: ' . $e->getMessage());
    }
}

function hofErro(int $codigo, string $msg): void
{
    http_response_code($codigo);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function hofLinha(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT id, is_active, league, team_id, team_name, gm_name, titles
                         FROM hall_of_fame WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Lê e valida o que veio do formulário.
 *
 * Campo em branco não é "não mexeu": no editor a pessoa vê o valor e apaga
 * porque quer apagar. Só os campos AUSENTES do payload ficam como estavam.
 */
function hofCampos(array $d): array
{
    $out = [];

    if (array_key_exists('gm_name', $d)) {
        $nome = trim((string)$d['gm_name']);
        if ($nome === '')           hofErro(400, 'O nome do GM é obrigatório.');
        if (mb_strlen($nome) > 255) hofErro(400, 'Nome do GM longo demais.');
        $out['gm_name'] = $nome;
    }

    if (array_key_exists('team_name', $d)) {
        $time = trim((string)$d['team_name']);
        if (mb_strlen($time) > 255) hofErro(400, 'Nome do time longo demais.');
        $out['team_name'] = $time !== '' ? $time : null;
    }

    if (array_key_exists('league', $d)) {
        $liga = strtoupper(trim((string)$d['league']));
        // Vazio = título histórico, de antes das divisões — é um valor válido,
        // e é como os campeões antigos estão gravados.
        if ($liga !== '' && !in_array($liga, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
            hofErro(400, 'Liga inválida.');
        }
        $out['league'] = $liga !== '' ? $liga : null;
    }

    if (array_key_exists('titles', $d)) {
        if (!is_numeric($d['titles'])) hofErro(400, 'Títulos precisa ser um número.');
        $n = (int)$d['titles'];
        // O teto não é burocracia: é o que separa um erro de digitação de uma
        // linha que empurra todo mundo pra fora do pódio.
        if ($n < 1 || $n > 99) hofErro(400, 'Títulos precisa ser de 1 a 99.');
        $out['titles'] = $n;
    }

    return $out;
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// O corpo vem como JSON; $_POST não é preenchido pra application/json.
$corpo = [];
if ($metodo !== 'GET') {
    $raw = file_get_contents('php://input');
    $corpo = $raw ? (json_decode($raw, true) ?: []) : [];
    if (!$corpo && !empty($_POST)) $corpo = $_POST;
}

try {
    if ($metodo === 'GET') {
        // As linhas cruas, não os grupos: quem edita mexe numa linha por vez,
        // e o agrupamento junta ligas diferentes do mesmo GM numa coisa só.
        $linhas = $pdo->query("SELECT id, is_active, league, team_id, team_name, gm_name, titles
                               FROM hall_of_fame
                               ORDER BY (league IS NULL), FIELD(league,'ELITE','NEXT','RISE','ROOKIE'),
                                        titles DESC, gm_name ASC")->fetchAll(PDO::FETCH_ASSOC);

        hofLogGarantirTabela($pdo);
        $historico = [];
        try {
            $historico = $pdo->query("SELECT acao, user_nome, antes, depois, criado_em
                                      FROM hall_of_fame_log ORDER BY id DESC LIMIT 15")
                             ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        echo json_encode(['success' => true, 'linhas' => $linhas, 'historico' => $historico],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo === 'POST') {
        $campos = hofCampos($corpo);
        if (empty($campos['gm_name'])) hofErro(400, 'O nome do GM é obrigatório.');

        // Entra sempre como histórico. "Ativo" amarra a linha a um time vivo, e
        // é a tela de admin que sabe fazer esse vínculo — aqui isso viraria um
        // seletor de time que ninguém pediu.
        $st = $pdo->prepare("INSERT INTO hall_of_fame (is_active, league, team_name, gm_name, titles)
                             VALUES (0, ?, ?, ?, ?)");
        $st->execute([
            $campos['league']    ?? null,
            $campos['team_name'] ?? null,
            $campos['gm_name'],
            $campos['titles']    ?? 1,
        ]);
        $id = (int)$pdo->lastInsertId();
        hofRegistrar($pdo, $user, 'criou', $id, null, hofLinha($pdo, $id));

        echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo === 'PUT' || $metodo === 'PATCH') {
        $id = (int)($corpo['id'] ?? 0);
        if ($id <= 0) hofErro(400, 'ID obrigatório.');
        $antes = hofLinha($pdo, $id);
        if (!$antes) hofErro(404, 'Esse registro não existe mais.');

        $campos = hofCampos($corpo);
        if (!$campos) hofErro(400, 'Nada pra salvar.');

        $sets = [];
        $vals = [];
        foreach ($campos as $col => $v) { $sets[] = "{$col} = ?"; $vals[] = $v; }
        $vals[] = $id;
        $pdo->prepare("UPDATE hall_of_fame SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);

        hofRegistrar($pdo, $user, 'editou', $id, $antes, hofLinha($pdo, $id));
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo === 'DELETE') {
        $id = (int)($corpo['id'] ?? ($_GET['id'] ?? 0));
        if ($id <= 0) hofErro(400, 'ID obrigatório.');
        $antes = hofLinha($pdo, $id);
        if (!$antes) hofErro(404, 'Esse registro não existe mais.');

        $pdo->prepare("DELETE FROM hall_of_fame WHERE id = ?")->execute([$id]);
        // O conteúdo apagado vai inteiro pro log: é o que permite refazer.
        hofRegistrar($pdo, $user, 'apagou', $id, $antes, null);

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    hofErro(405, 'Método não suportado.');
} catch (Throwable $e) {
    error_log('[hall-editar] ' . $e->getMessage());
    hofErro(500, 'Erro ao salvar. Tente de novo.');
}
