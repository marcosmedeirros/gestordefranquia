<?php
/**
 * NÚCLEO DO MCP DA FBA — o que o Claude usa pra enxergar e mexer na liga.
 *
 * Por que existe: as perguntas que chegam no WhatsApp do dono ("essa troca é
 * justa?", "ele tem espaço no cap?", "quem ainda tem moeda?") sempre acabavam
 * numa consulta no banco feita na unha, por SSH. Aqui essas consultas viram
 * ferramentas nomeadas, e o assistente chama a ferramenta em vez de pedir SQL.
 *
 * Este arquivo é a parte chata: autenticação, log e as buscas que toda
 * ferramenta precisa (achar o time pelo apelido que o GM usou). As ferramentas
 * em si estão em backend/mcp_tools.php, e o transporte em api/mcp.php.
 *
 * Segurança: token no cabeçalho, NUNCA sessão — quem chama é um programa. O
 * token nasce sozinho na primeira execução e vive no banco, então não passa
 * pelo git. Toda chamada fica registrada em `mcp_log`, inclusive as que falham,
 * porque um endereço na internet que altera o banco de produção precisa de um
 * rastro que não dependa de eu lembrar de olhar.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

const MCP_VERSAO     = '0.1.0';
const MCP_LIGAS      = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
const MCP_LOG_LIMITE = 2000;   // caracteres guardados do resultado

function mcpGarantirTabelas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_config (
        chave VARCHAR(60) PRIMARY KEY,
        valor TEXT NOT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ferramenta VARCHAR(60) NOT NULL,
        argumentos TEXT NULL,
        escreveu TINYINT(1) NOT NULL DEFAULT 0,
        ok TINYINT(1) NOT NULL DEFAULT 1,
        resultado TEXT NULL,
        ip VARCHAR(45) NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_quando (criado_em),
        INDEX idx_ferramenta (ferramenta)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * O token do MCP, criado na primeira vez que alguém pergunta por ele.
 *
 * Fica no banco (e não em config.local.php) porque quem precisa lê-lo sou eu,
 * por SSH, uma vez só — e assim ele não corre o risco de entrar num commit.
 */
function mcpToken(PDO $pdo): string
{
    mcpGarantirTabelas($pdo);
    $st = $pdo->prepare("SELECT valor FROM mcp_config WHERE chave = 'token'");
    $st->execute();
    $token = (string)$st->fetchColumn();
    if ($token !== '') return $token;

    $token = bin2hex(random_bytes(24));
    $pdo->prepare("INSERT INTO mcp_config (chave, valor) VALUES ('token', ?)
                   ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([$token]);
    return $token;
}

/**
 * O token que veio na requisição.
 *
 * O cabeçalho Authorization é o caminho normal, mas em Apache com PHP-CGI ele
 * não chega em $_SERVER — some antes. Por isso as três tentativas: $_SERVER,
 * a cópia que o rewrite deixa em REDIRECT_, e getallheaders(), que enxerga o
 * cabeçalho cru. `?token=` fica por último, pro teste rápido com curl; em
 * endereço público ele vaza no log do servidor, então não é o jeito de usar.
 */
function mcpTokenRecebido(): string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() ?: [] as $nome => $valor) {
            if (strcasecmp($nome, 'Authorization') === 0) { $auth = (string)$valor; break; }
        }
    }
    if (stripos($auth, 'Bearer ') === 0) return trim(substr($auth, 7));
    return (string)($_GET['token'] ?? '');
}

function mcpAutenticado(PDO $pdo): bool
{
    $enviado = mcpTokenRecebido();
    if ($enviado === '') return false;
    return hash_equals(mcpToken($pdo), $enviado);
}

function mcpRegistrar(PDO $pdo, string $ferramenta, array $args, bool $escreveu, bool $ok, string $resultado): void
{
    try {
        mcpGarantirTabelas($pdo);
        $pdo->prepare("INSERT INTO mcp_log (ferramenta, argumentos, escreveu, ok, resultado, ip)
                       VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([
                $ferramenta,
                json_encode($args, JSON_UNESCAPED_UNICODE),
                $escreveu ? 1 : 0,
                $ok ? 1 : 0,
                mb_substr($resultado, 0, MCP_LOG_LIMITE),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
    } catch (Throwable $e) {
        error_log('[mcp] log: ' . $e->getMessage());
    }
}

/** Erro que vira mensagem pro assistente, e não stack trace. */
class McpErro extends RuntimeException {}

function mcpLiga(?string $liga): ?string
{
    if ($liga === null || trim($liga) === '') return null;
    $l = strtoupper(trim($liga));
    if (!in_array($l, MCP_LIGAS, true)) {
        throw new McpErro('Liga inválida: ' . $liga . '. Use ELITE, NEXT, RISE ou ROOKIE.');
    }
    return $l;
}

/**
 * Acha o time pelo que o GM chamou: id, "Paisley", "minnesota", "Virgínia
 * Tigers". Acento e caixa não contam.
 *
 * Nome repetido em ligas diferentes é comum (Atlanta na NEXT e na RISE), então
 * ambíguo não escolhe sozinho: devolve a lista pro assistente perguntar qual é.
 */
function mcpAcharTime(PDO $pdo, string $busca, ?string $liga = null): array
{
    $busca = trim($busca);
    if ($busca === '') throw new McpErro('Diga o time.');

    $where = []; $params = [];
    if (ctype_digit($busca)) {
        $where[] = 't.id = ?'; $params[] = (int)$busca;
    } else {
        $where[] = "(CONCAT(t.city,' ',t.name) LIKE ? OR t.name LIKE ? OR t.city LIKE ? OR t.mascot LIKE ?)";
        $curinga = '%' . $busca . '%';
        array_push($params, $curinga, $curinga, $curinga, $curinga);
    }
    if ($liga) { $where[] = 't.league = ?'; $params[] = $liga; }

    $sql = "SELECT t.id, t.league, t.city, t.name, CONCAT(t.city,' ',t.name) AS nome,
                   t.conference, COALESCE(t.moedas,0) AS moedas, t.user_id,
                   u.name AS gm
              FROM teams t LEFT JOIN users u ON u.id = t.user_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY t.league, t.city LIMIT 12";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $achados = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$achados) throw new McpErro('Não achei time com "' . $busca . '"' . ($liga ? ' na ' . $liga : '') . '.');
    if (count($achados) > 1) {
        $lista = array_map(fn($t) => "#{$t['id']} {$t['nome']} ({$t['league']})", $achados);
        throw new McpErro('"' . $busca . '" serve pra mais de um time: ' . implode(', ', $lista)
                        . '. Repita com o id ou com a liga.');
    }
    return $achados[0];
}

/** Idem, pro jogador. */
function mcpAcharJogador(PDO $pdo, string $busca, ?string $liga = null): array
{
    $busca = trim($busca);
    if ($busca === '') throw new McpErro('Diga o jogador.');

    $where = []; $params = [];
    if (ctype_digit($busca)) { $where[] = 'p.id = ?'; $params[] = (int)$busca; }
    else { $where[] = 'p.name LIKE ?'; $params[] = '%' . $busca . '%'; }
    if ($liga) { $where[] = 't.league = ?'; $params[] = $liga; }

    $st = $pdo->prepare("SELECT p.id, p.name, p.position, p.ovr, p.age, p.team_id,
                                CONCAT(t.city,' ',t.name) AS time, t.league
                           FROM players p LEFT JOIN teams t ON t.id = p.team_id
                          WHERE " . implode(' AND ', $where) . "
                       ORDER BY p.ovr DESC LIMIT 12");
    $st->execute($params);
    $achados = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$achados) throw new McpErro('Não achei jogador com "' . $busca . '".');
    if (count($achados) > 1) {
        $lista = array_map(fn($p) => "#{$p['id']} {$p['name']} ({$p['ovr']} ovr, {$p['time']})", $achados);
        throw new McpErro('"' . $busca . '" serve pra mais de um: ' . implode(', ', $lista) . '. Repita com o id.');
    }
    return $achados[0];
}

/** A temporada corrente da liga, e a última com classificação lançada. */
function mcpTemporadas(PDO $pdo, string $liga): array
{
    $st = $pdo->prepare("SELECT id, season_number, year, status FROM seasons
                          WHERE league = ? ORDER BY season_number DESC, id DESC LIMIT 1");
    $st->execute([$liga]);
    $atual = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    $st = $pdo->prepare("SELECT s.id, s.season_number, s.year FROM seasons s
                          WHERE s.league = ?
                            AND EXISTS (SELECT 1 FROM season_standings ss WHERE ss.season_id = s.id)
                       ORDER BY s.season_number DESC, s.id DESC LIMIT 1");
    $st->execute([$liga]);
    $classificada = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    return ['atual' => $atual, 'ultima_classificada' => $classificada];
}
