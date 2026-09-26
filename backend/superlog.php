<?php
/**
 * SUPERLOG — toda escrita no banco, gravada em ARQUIVO, por 7 dias.
 *
 * Em 26/09/2026 o banco de produção foi apagado junto com um site removido no
 * hPanel, e ~34h de trocas tiveram que ser reconstruídas a partir do que cada
 * GM lembrava no WhatsApp. Foi um dia inteiro de trabalho.
 *
 * POR QUE ARQUIVO E NÃO UMA TABELA. Uma tabela de auditoria teria sido apagada
 * junto com o resto — ela mora no mesmo banco que sumiu. O que sobreviveu ao
 * desastre foi o filesystem (uploads/, img/, os .sqlite do simulador). Então o
 * log tem que estar fora do banco pra servir de rede de proteção, e fora do
 * public_html pra ninguém baixar pela web.
 *
 * O QUE ELE GUARDA: o SQL e os parâmetros de cada INSERT/UPDATE/DELETE, com
 * quem estava logado, qual script rodou e quando. Não é backup e não desfaz
 * nada sozinho — é a memória do que aconteceu, pra reconstruir sabendo o que
 * fazer em vez de perguntar a trinta pessoas.
 *
 * O QUE ELE NÃO GUARDA: SELECT (só ruído), as tabelas de sessão e de fila
 * (SUPERLOG_IGNORAR), e nada de senha — os campos sensíveis saem mascarados.
 *
 * TETO DE ESPAÇO: esta hospedagem já derrubou o site por quota de disco cheia
 * (26/09/2026, antes do incidente do banco). Por isso o arquivo do dia para de
 * crescer em SUPERLOG_MAX_MB e os arquivos com mais de SUPERLOG_DIAS dias são
 * apagados a cada dia novo.
 */

const SUPERLOG_DIAS    = 7;      // quantos dias de histórico ficam de pé
const SUPERLOG_MAX_MB  = 40;     // teto por arquivo diário
const SUPERLOG_MAX_VAL = 300;    // corte de cada parâmetro, em caracteres

/** Tabelas que não valem uma linha de log: giram muito e não reconstroem nada. */
const SUPERLOG_IGNORAR = [
    'sessions', 'php_sessions', 'cache', 'whatsapp_fila', 'whatsapp_log',
    'superlog', 'revisao_log', 'bot_heartbeat', 'user_sessions', 'login_attempts',
];

/** Campos cujo valor nunca entra no arquivo. */
const SUPERLOG_SEGREDO = ['password', 'senha', 'password_hash', 'token', 'api_key',
                          'access_token', 'bot_token', 'secret'];

/**
 * A PASTA. Fora do public_html, porque o que está lá dentro a web serve.
 * Se por algum motivo não der pra criar, o superlog se cala — nunca derruba
 * uma requisição por causa de log.
 */
function superlogPasta(): ?string
{
    static $pasta = null;
    static $tentou = false;
    if ($tentou) return $pasta;
    $tentou = true;

    $candidatos = [];
    $home = getenv('HOME') ?: null;
    if ($home) $candidatos[] = rtrim($home, '/') . '/superlog';
    $candidatos[] = dirname(__DIR__, 2) . '/superlog';   // irmão do public_html
    $candidatos[] = sys_get_temp_dir() . '/fba-superlog';

    foreach ($candidatos as $c) {
        if (is_dir($c) && is_writable($c)) return $pasta = $c;
        if (@mkdir($c, 0770, true) && is_writable($c)) return $pasta = $c;
    }
    return $pasta = null;
}

/** Apaga o que passou de SUPERLOG_DIAS. Roda uma vez por dia, no primeiro log. */
function superlogLimpar(string $pasta): void
{
    $marca = $pasta . '/.limpeza-' . date('Y-m-d');
    if (file_exists($marca)) return;
    @touch($marca);

    $corte = time() - (SUPERLOG_DIAS * 86400);
    foreach ((glob($pasta . '/*.jsonl') ?: []) as $f) {
        if (@filemtime($f) < $corte) @unlink($f);
    }
    foreach ((glob($pasta . '/.limpeza-*') ?: []) as $f) {
        if (@filemtime($f) < $corte) @unlink($f);
    }
}

/** A tabela que a query mexe — é por ela que se decide logar ou não. */
function superlogTabela(string $sql): string
{
    if (preg_match('/^\s*(?:INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+`?([a-z0-9_]+)`?/i',
                   $sql, $m)) {
        return strtolower($m[1]);
    }
    return '';
}

function superlogEhEscrita(string $sql): bool
{
    return (bool)preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql);
}

/** Mascara valor de campo sensível e corta o que for grande demais. */
function superlogLimpaParams(string $sql, array $params): array
{
    $temSegredo = false;
    foreach (SUPERLOG_SEGREDO as $s) {
        if (stripos($sql, $s) !== false) { $temSegredo = true; break; }
    }
    $out = [];
    foreach ($params as $k => $v) {
        if (is_object($v) || is_resource($v)) { $out[$k] = '(objeto)'; continue; }
        if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE); }
        $v = is_null($v) ? null : (string)$v;
        if ($v !== null && $temSegredo && strlen($v) > 12) $v = '(mascarado)';
        if ($v !== null && strlen($v) > SUPERLOG_MAX_VAL) {
            $v = substr($v, 0, SUPERLOG_MAX_VAL) . '…(+' . (strlen($v) - SUPERLOG_MAX_VAL) . ')';
        }
        $out[$k] = $v;
    }
    return $out;
}

/** Quem está mexendo — pra saber de quem cobrar quando algo sai errado. */
function superlogQuem(): array
{
    $u = null;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $u = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
    }
    return [
        'u' => $u ? (int)$u : null,
        's' => basename($_SERVER['SCRIPT_NAME'] ?? (PHP_SAPI === 'cli' ? 'cli' : '?')),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ];
}

/** O registro em si. Silencioso por projeto: log não pode quebrar o app. */
function superlogRegistrar(string $sql, array $params = []): void
{
    try {
        if (!superlogEhEscrita($sql)) return;
        $tabela = superlogTabela($sql);
        if ($tabela !== '' && in_array($tabela, SUPERLOG_IGNORAR, true)) return;

        $pasta = superlogPasta();
        if ($pasta === null) return;
        superlogLimpar($pasta);

        $arquivo = $pasta . '/' . date('Y-m-d') . '.jsonl';
        if (is_file($arquivo) && filesize($arquivo) > SUPERLOG_MAX_MB * 1024 * 1024) return;

        $quem = superlogQuem();
        $linha = json_encode([
            't'   => date('H:i:s'),
            'tb'  => $tabela,
            'u'   => $quem['u'],
            's'   => $quem['s'],
            'sql' => preg_replace('/\s+/', ' ', trim($sql)),
            'p'   => superlogLimpaParams($sql, $params),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($linha !== false) {
            @file_put_contents($arquivo, $linha . "\n", FILE_APPEND | LOCK_EX);
        }
    } catch (Throwable $e) {
        // de propósito: o superlog nunca derruba uma requisição
    }
}

/**
 * O statement que se anota ao ser executado.
 *
 * bindValue/bindParam entram no mesmo saco de execute([...]): parte do app usa
 * um jeito, parte usa o outro, e um log que só vê metade não reconstrói nada.
 */
class SuperlogStatement extends PDOStatement
{
    private array $ligados = [];

    protected function __construct() {}

    #[\ReturnTypeWillChange]
    public function bindValue($param, $value, $type = PDO::PARAM_STR): bool
    {
        $this->ligados[$param] = $value;
        return parent::bindValue($param, $value, $type);
    }

    #[\ReturnTypeWillChange]
    public function bindParam($param, &$var, $type = PDO::PARAM_STR, $maxLength = 0, $driverOptions = null): bool
    {
        $this->ligados[$param] = $var;
        return parent::bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    #[\ReturnTypeWillChange]
    public function execute($params = null): bool
    {
        $ok = parent::execute($params);
        superlogRegistrar($this->queryString, is_array($params) ? $params : $this->ligados);
        return $ok;
    }
}

/** A conexão que também anota o que passa por exec(). */
class SuperlogPDO extends PDO
{
    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        $r = parent::exec($statement);
        superlogRegistrar((string)$statement);
        return $r;
    }
}

/**
 * Lê o log de volta, do mais novo pro mais velho. É o que uma tela de
 * recuperação usaria — e o que eu uso no SSH quando alguém pergunta "quem
 * mexeu no meu elenco".
 */
function superlogLer(int $limite = 200, ?string $filtroTabela = null, ?string $dia = null): array
{
    $pasta = superlogPasta();
    if ($pasta === null) return [];
    $arquivos = $dia ? [$pasta . '/' . $dia . '.jsonl'] : (glob($pasta . '/*.jsonl') ?: []);
    rsort($arquivos);

    $out = [];
    foreach ($arquivos as $f) {
        if (!is_file($f)) continue;
        $linhas = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        for ($i = count($linhas) - 1; $i >= 0; $i--) {
            $r = json_decode($linhas[$i], true);
            if (!is_array($r)) continue;
            if ($filtroTabela && ($r['tb'] ?? '') !== $filtroTabela) continue;
            $r['dia'] = basename($f, '.jsonl');
            $out[] = $r;
            if (count($out) >= $limite) return $out;
        }
    }
    return $out;
}

/** Quanto espaço o superlog está ocupando, pra ninguém ser pego de surpresa. */
function superlogEspaco(): array
{
    $pasta = superlogPasta();
    if ($pasta === null) return ['pasta' => null, 'bytes' => 0, 'arquivos' => []];
    $arquivos = [];
    $total = 0;
    foreach ((glob($pasta . '/*.jsonl') ?: []) as $f) {
        $tam = (int)@filesize($f);
        $total += $tam;
        $arquivos[basename($f, '.jsonl')] = $tam;
    }
    krsort($arquivos);
    return ['pasta' => $pasta, 'bytes' => $total, 'arquivos' => $arquivos];
}
