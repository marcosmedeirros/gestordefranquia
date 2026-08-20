<?php
/**
 * Quantas vezes o time trocou de modelo técnico na edição.
 *
 * A regra da liga: são oito modelos por edição — o primeiro mais sete
 * trocas. O que conta NÃO é mexer no select: o GM pode abrir a tática,
 * trocar de coach e voltar atrás dez vezes enquanto a janela está aberta,
 * e nada disso é decisão. A decisão é o que está lá QUANDO A JANELA FECHA.
 *
 * Por isso a contagem acontece no fechamento: compara o modelo de agora com
 * o último que foi registrado. Igual, não conta. Diferente, é uma troca.
 *
 * O log guarda cada registro em vez de só um número porque o admin precisa
 * poder responder "quando ele trocou, e do quê pra quê" — um contador
 * sozinho não responde isso.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/modelos_tecnicos.php';

/** As ligas que usam modelo técnico. RISE e ROOKIE não têm. */
function modeloTecnicoLigas(): array
{
    return ['ELITE', 'NEXT'];
}

function modeloTecnicoLigaUsa(?string $league): bool
{
    return in_array(strtoupper(trim((string)$league)), modeloTecnicoLigas(), true);
}

function modeloTecnicoGarantirTabela(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS team_technical_model_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            team_id INT NOT NULL,
            league VARCHAR(20) NULL,
            modelo VARCHAR(60) NOT NULL,
            modelo_anterior VARCHAR(60) NULL,
            registrado_em DATETIME NOT NULL,
            KEY idx_time (team_id, id),
            KEY idx_liga (league)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Throwable $e) {
        error_log('modeloTecnicoGarantirTabela: ' . $e->getMessage());
    }
    $feito = true;
}

/** O último modelo registrado do time, ou null se ele nunca fechou janela. */
function modeloTecnicoUltimo(PDO $pdo, int $teamId): ?string
{
    modeloTecnicoGarantirTabela($pdo);
    try {
        $st = $pdo->prepare("SELECT modelo FROM team_technical_model_log
                             WHERE team_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$teamId]);
        $v = $st->fetchColumn();
        return $v !== false ? (string)$v : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * O placar do time: quantos modelos já usou e quantos ainda pode usar.
 *
 * "usados" conta registros, não trocas: o primeiro modelo também ocupa uma
 * das oito vagas — é o que "começamos com 1 e podemos mudar 7 vezes" quer
 * dizer.
 */
function modeloTecnicoPlacar(PDO $pdo, int $teamId): array
{
    modeloTecnicoGarantirTabela($pdo);
    $usados = 0;
    $historico = [];
    try {
        $st = $pdo->prepare("SELECT modelo, modelo_anterior, registrado_em
                             FROM team_technical_model_log WHERE team_id = ? ORDER BY id");
        $st->execute([$teamId]);
        $historico = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $usados = count($historico);
    } catch (Throwable $e) {}

    return [
        'usados'    => $usados,
        'limite'    => MODELO_TECNICO_LIMITE,
        'restam'    => max(0, MODELO_TECNICO_LIMITE - $usados),
        'trocas'    => max(0, $usados - 1),   // o primeiro não é troca
        'historico' => $historico,
    ];
}

/**
 * Registra o modelo de um time — chamado quando a janela FECHA.
 *
 * Devolve true se contou uma entrada nova. Igual ao anterior não conta, que
 * é o pedido: "se fechou com o mesmo, não teve mudança".
 *
 * Time sem modelo escolhido também não gera registro: não dá pra gastar uma
 * das oito vagas com uma escolha que não foi feita.
 */
function modeloTecnicoRegistrar(PDO $pdo, int $teamId, ?string $league = null): bool
{
    modeloTecnicoGarantirTabela($pdo);
    if (!modeloTecnicoLigaUsa($league)) return false;

    try {
        $st = $pdo->prepare("SELECT technical_model FROM team_tactics
                             WHERE team_id = ? AND is_active = 1 LIMIT 1");
        $st->execute([$teamId]);
        $atual = trim((string)($st->fetchColumn() ?: ''));
        if ($atual === '') return false;

        $anterior = modeloTecnicoUltimo($pdo, $teamId);
        if ($anterior !== null && $anterior === $atual) return false;

        $pdo->prepare("INSERT INTO team_technical_model_log
                       (team_id, league, modelo, modelo_anterior, registrado_em)
                       VALUES (?, ?, ?, ?, NOW())")
            ->execute([$teamId, strtoupper((string)$league), $atual, $anterior]);
        return true;
    } catch (Throwable $e) {
        error_log('modeloTecnicoRegistrar #' . $teamId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Passa por todos os times da liga no fechamento da janela.
 *
 * Devolve ['registrados' => int, 'times' => [...]] pra o admin ver quem
 * trocou naquele ciclo.
 */
function modeloTecnicoRegistrarLiga(PDO $pdo, string $league): array
{
    if (!modeloTecnicoLigaUsa($league)) return ['registrados' => 0, 'times' => []];
    modeloTecnicoGarantirTabela($pdo);

    $times = [];
    try {
        $st = $pdo->prepare("SELECT id, CONCAT(city,' ',name) AS nome FROM teams WHERE league = ?");
        $st->execute([strtoupper($league)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            if (modeloTecnicoRegistrar($pdo, (int)$t['id'], $league)) $times[] = $t['nome'];
        }
    } catch (Throwable $e) {
        error_log('modeloTecnicoRegistrarLiga ' . $league . ': ' . $e->getMessage());
    }
    return ['registrados' => count($times), 'times' => $times];
}
