<?php
/**
 * OS SLOTS DE TELA DA LIVE.
 *
 * Oito vagas por liga, em cada live da temporada regular: quem compra tem o
 * time na tela durante a transmissão. Quem chegar primeiro leva — não há
 * reserva, fila nem sorteio.
 *
 * A venda abre UMA HORA antes da live e fecha quando a live começa ou quando
 * os oito acabam, o que vier primeiro. É curta de propósito: o valor da vaga
 * vem de ela ser disputada na hora, e uma janela de dias viraria só mais um
 * item de catálogo que quem tem pontos compra sem pensar.
 *
 * Só a REGULAR. A live de playoffs tem outra dinâmica e não entrou; a fase
 * sai do título do evento, pela mesma leitura que a escala usa.
 */

require_once __DIR__ . '/calendario.php';
require_once __DIR__ . '/escala_live.php';

const SLOTS_TELA_TOTAL = 8;
const SLOTS_TELA_PRECO = 50;
/** Quantos minutos antes da live a venda abre. */
const SLOTS_TELA_ANTECEDENCIA = 60;

function slotsTelaGarantirTabela(PDO $pdo): void
{
    static $ok = false;
    if ($ok) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS slots_tela (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        league      VARCHAR(10) NOT NULL,
        data_live   DATE NOT NULL,
        inicio_live DATETIME NOT NULL,
        team_id     INT NOT NULL,
        id_usuario  INT NOT NULL,
        preco       SMALLINT NOT NULL DEFAULT 0,
        comprado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        -- Um time por live: o UNIQUE é o que garante isso mesmo com dois
        -- cliques ao mesmo tempo, que a contagem sozinha não pega.
        UNIQUE KEY uk_slot_time (league, data_live, team_id),
        KEY idx_slot_live (league, data_live)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ok = true;
}

/**
 * A próxima live da temporada REGULAR desta liga.
 *
 * Procura numa janela de oito dias porque a live é semanal: oito cobre a
 * semana inteira mais o dia de hoje, e devolve a próxima ocorrência mesmo
 * quando a série começou meses atrás.
 *
 * @return array{inicio:string,data:string,titulo:string}|null
 */
function slotsTelaProximaRegular(PDO $pdo, string $liga, ?string $agora = null): ?array
{
    $liga = strtoupper(trim($liga));
    if ($liga === '') return null;

    $tz  = new DateTimeZone('America/Sao_Paulo');
    $ref = $agora ? new DateTimeImmutable($agora, $tz) : new DateTimeImmutable('now', $tz);

    // Começa a busca UMA HORA ATRÁS, e não em "agora": a live que começou às
    // 19h ainda é a live de hoje às 19h20. Sem isso o card do dashboard
    // trocava pra semana que vem no minuto em que a transmissão começava.
    $de  = $ref->modify('-3 hours')->format('Y-m-d H:i:s');
    // Catorze dias, e não sete: a live é semanal, mas uma série marcada pra
    // começar só na semana que vem existe e não pode ser lida como "a liga
    // não tem live".
    $ate = $ref->modify('+14 days')->format('Y-m-d H:i:s');

    foreach (calendarioEventos($pdo, [$liga], $de, $ate) as $ev) {
        if (($ev['tipo'] ?? '') !== 'live') continue;
        // Regular, ou live SEM fase declarada. A da ROOKIE se chama só
        // "ROOKIE" — não é playoffs, é a live da liga, e excluí-la deixaria a
        // ROOKIE sem slot nenhum pra sempre. Playoffs é o único caso que sai.
        if (escalaFaseDaLive($ev['titulo'] ?? '') === 'playoffs') continue;
        return [
            'inicio' => (string)$ev['inicio'],
            'data'   => substr((string)$ev['inicio'], 0, 10),
            'titulo' => (string)($ev['titulo'] ?? ''),
        ];
    }
    return null;
}

/** Quem já comprou slot nesta live, na ordem em que compraram. */
function slotsTelaDaLive(PDO $pdo, string $liga, string $dataLive): array
{
    slotsTelaGarantirTabela($pdo);
    try {
        $st = $pdo->prepare("SELECT s.team_id, s.comprado_em,
                                    t.name AS time_nome, t.city AS time_cidade,
                                    t.photo_url AS logo, u.name AS dono
                               FROM slots_tela s
                          LEFT JOIN teams t ON t.id = s.team_id
                          LEFT JOIN users u ON u.id = t.user_id
                              WHERE s.league = ? AND s.data_live = ?
                           ORDER BY s.comprado_em ASC, s.id ASC");
        $st->execute([strtoupper(trim($liga)), $dataLive]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[slots-tela] da live: ' . $e->getMessage());
        return [];
    }
}

/**
 * O estado da venda pra uma liga: se está aberta, quanto falta, quem já levou.
 *
 * @return array{live:?array,aberta:bool,motivo:string,abre_em:?string,vendidos:int,
 *               restam:int,lista:array,meu:bool,preco:int,total:int}
 */
function slotsTelaEstado(PDO $pdo, string $liga, int $teamId = 0, ?string $agora = null): array
{
    $tz  = new DateTimeZone('America/Sao_Paulo');
    $ref = $agora ? new DateTimeImmutable($agora, $tz) : new DateTimeImmutable('now', $tz);

    $base = ['live' => null, 'aberta' => false, 'motivo' => 'sem_live', 'abre_em' => null,
             'vendidos' => 0, 'restam' => SLOTS_TELA_TOTAL, 'lista' => [], 'meu' => false,
             'preco' => SLOTS_TELA_PRECO, 'total' => SLOTS_TELA_TOTAL];

    $live = slotsTelaProximaRegular($pdo, $liga, $ref->format('Y-m-d H:i:s'));
    if (!$live) return $base;

    $inicio = new DateTimeImmutable($live['inicio'], $tz);
    $abre   = $inicio->modify('-' . SLOTS_TELA_ANTECEDENCIA . ' minutes');

    $lista    = slotsTelaDaLive($pdo, $liga, $live['data']);
    $vendidos = count($lista);
    $meu      = $teamId > 0 && in_array($teamId, array_map(fn($x) => (int)$x['team_id'], $lista), true);

    $motivo = 'ok';
    if ($ref < $abre)                        $motivo = 'cedo';
    elseif ($ref >= $inicio)                 $motivo = 'comecou';
    elseif ($vendidos >= SLOTS_TELA_TOTAL)   $motivo = 'esgotado';
    elseif ($meu)                            $motivo = 'ja_tenho';

    return [
        'live'     => $live + ['abre' => $abre->format('Y-m-d H:i:s')],
        'aberta'   => $motivo === 'ok',
        'motivo'   => $motivo,
        'abre_em'  => $abre->format('Y-m-d H:i:s'),
        'vendidos' => $vendidos,
        'restam'   => max(0, SLOTS_TELA_TOTAL - $vendidos),
        'lista'    => $lista,
        'meu'      => $meu,
        'preco'    => SLOTS_TELA_PRECO,
        'total'    => SLOTS_TELA_TOTAL,
    ];
}

/**
 * Compra um slot. Tudo dentro de uma transação.
 *
 * A conferência de vaga e o débito acontecem com a linha do GM travada, pelo
 * mesmo motivo da loja: duas abas clicando junto passariam pelas duas
 * contagens antes de qualquer INSERT existir, e os dois veriam "ainda tem
 * vaga". Com oito lugares e quem-chega-primeiro, isso não é detalhe — é a
 * regra inteira.
 */
function slotsTelaComprar(PDO $pdo, int $userId, int $teamId, string $liga): array
{
    slotsTelaGarantirTabela($pdo);
    $liga = strtoupper(trim($liga));
    $falha = fn(string $e) => ['ok' => false, 'erro' => $e];

    // O time é MESMO de quem está comprando, e da liga da live.
    $st = $pdo->prepare("SELECT t.id, t.league, t.user_id, t.name, t.city
                           FROM teams t WHERE t.id = ?");
    $st->execute([$teamId]);
    $time = $st->fetch(PDO::FETCH_ASSOC);
    if (!$time)                                          return $falha('Time não encontrado.');
    if ((int)$time['user_id'] !== $userId)               return $falha('Esse time não é seu.');
    if (strtoupper((string)$time['league']) !== $liga)   return $falha('O slot é da live da sua liga.');

    $estado = slotsTelaEstado($pdo, $liga, $teamId);
    if (!$estado['live']) return $falha('A ' . $liga . ' não tem live da regular marcada.');
    switch ($estado['motivo']) {
        case 'cedo':     return $falha('A venda abre uma hora antes da live.');
        case 'comecou':  return $falha('A live já começou — a venda fechou.');
        case 'esgotado': return $falha('Os ' . SLOTS_TELA_TOTAL . ' slots desta live já foram.');
        case 'ja_tenho': return $falha('Seu time já está na tela desta live.');
    }

    try {
        $pdo->beginTransaction();

        $lock = $pdo->prepare("SELECT fba_points FROM games_usuarios WHERE id = ? FOR UPDATE");
        $lock->execute([$userId]);
        if ($lock->fetchColumn() === false) {
            $pdo->rollBack();
            return $falha('Perfil de games não encontrado.');
        }

        // Conta DENTRO da transação: entre a tela carregar e o clique, os
        // últimos slots podem ter ido.
        $sc = $pdo->prepare("SELECT COUNT(*) FROM slots_tela WHERE league = ? AND data_live = ?");
        $sc->execute([$liga, $estado['live']['data']]);
        if ((int)$sc->fetchColumn() >= SLOTS_TELA_TOTAL) {
            $pdo->rollBack();
            return $falha('Os ' . SLOTS_TELA_TOTAL . ' slots desta live já foram.');
        }

        $up = $pdo->prepare("UPDATE games_usuarios SET fba_points = fba_points - ?
                              WHERE id = ? AND fba_points >= ?");
        $up->execute([SLOTS_TELA_PRECO, $userId, SLOTS_TELA_PRECO]);
        if ($up->rowCount() === 0) {
            $pdo->rollBack();
            return $falha('FBA Points insuficientes. O slot custa ' . SLOTS_TELA_PRECO . '.');
        }

        $pdo->prepare("INSERT INTO slots_tela (league, data_live, inicio_live, team_id, id_usuario, preco)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$liga, $estado['live']['data'], $estado['live']['inicio'],
                       $teamId, $userId, SLOTS_TELA_PRECO]);

        $pdo->commit();
        return ['ok' => true, 'restam' => max(0, SLOTS_TELA_TOTAL - $estado['vendidos'] - 1)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // O UNIQUE é a última linha de defesa contra o duplo clique: o
        // segundo INSERT do mesmo time bate aqui, e "já está na tela" é a
        // resposta certa — não um erro de sistema.
        if (str_contains($e->getMessage(), 'uk_slot_time') || str_contains($e->getMessage(), 'Duplicate')) {
            return $falha('Seu time já está na tela desta live.');
        }
        error_log('[slots-tela] comprar: ' . $e->getMessage());
        return $falha('Não deu pra comprar o slot agora.');
    }
}

/**
 * A lista dos times da tela em texto puro, um por linha.
 *
 * É o que vai pro botão de copiar — quem opera a live cola isso no roteiro
 * ou na cena do OBS. Nome completo, com cidade, porque é assim que o time
 * aparece na transmissão; e na ORDEM DA COMPRA, que é a ordem em que eles
 * ganharam a vaga.
 *
 * Numa função só porque a loja e o dashboard copiam a mesma coisa: com o
 * texto montado em cada tela, um dia uma copiaria com cidade e a outra sem.
 */
function slotsTelaTextoCopiar(array $lista): string
{
    $linhas = [];
    foreach ($lista as $s) {
        $nome = trim(($s['time_cidade'] ?? '') . ' ' . ($s['time_nome'] ?? ''));
        if ($nome !== '') $linhas[] = $nome;
    }
    return implode("\n", $linhas);
}

/** "18:00", pra escrever o horário sem repetir formatação por aí. */
function slotsTelaHora(string $dataHora): string
{
    try {
        return (new DateTimeImmutable($dataHora, new DateTimeZone('America/Sao_Paulo')))->format('H:i');
    } catch (Throwable $e) {
        return '';
    }
}
