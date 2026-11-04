<?php
/**
 * O PALPITE DA EDIÇÃO — quem sobe, quem cai, quem fica no pódio.
 *
 * Um popup que aparece pra cada GM até ele responder ou dizer "agora não" —
 * e depois disso nunca mais. Pedido do Marcos (03/11/2026): cada um escolhe
 * os 4 times que acha que vão SUBIR (menos na ELITE, que não tem pra onde
 * subir) e os 4 que vão CAIR (menos na ROOKIE, que não tem pra onde cair); na
 * ELITE o bloco de cima vira o TOP 3.
 *
 * ── O QUE CADA LIGA PERGUNTA ─────────────────────────────────────────
 *
 *   ELITE    top 3 (3 times)  + cai (4)
 *   NEXT     sobe (4)         + cai (4)
 *   RISE     sobe (4)         + cai (4)
 *   ROOKIE   sobe (4)
 *
 * Os times são os DA LIGA DO GM: o palpite é sobre a própria liga, e é nela
 * que quem sobe ou cai deixa uma vaga. O próprio time dele pode ser escolhido
 * — apostar que o seu sobe é a coisa mais natural do mundo.
 *
 * Um time não pode estar em dois blocos ao mesmo tempo (subir E cair, ou
 * pódio E cair): a tela não deixa e o servidor confere de novo.
 *
 * ── O VOTO É SECRETO, DE VERDADE ─────────────────────────────────────
 *
 * Pedido do Marcos (03/11/2026): "coloque que o voto é secreto". Escrever isso
 * no popup sem que fosse verdade seria pior que não escrever, então o banco não
 * guarda a escolha de ninguém: só um CONTADOR por (liga, grupo, time), somado a
 * cada voto. Quem respondeu fica em previsao_edicao_status — que diz QUE a
 * pessoa votou, nunca em quem. Nem o admin consegue descobrir o voto de alguém,
 * porque ele não existe mais depois de somado.
 *
 * A tabela de votos também está em SUPERLOG_IGNORAR (backend/superlog.php): o
 * log de auditoria grava o SQL e quem estava logado, e com ele o voto de cada
 * um ficaria num arquivo por 7 dias.
 *
 * ── "APARECE APENAS 1X" ──────────────────────────────────────────────
 *
 * Duas saídas encerram o popup pra sempre: responder, ou "Agora não". Fechar a
 * aba sem tocar em nada NÃO conta — quem não viu o popup de verdade não perdeu
 * a vez. O estado mora em previsao_edicao_status, uma linha por usuário.
 */

/**
 * CHAVE GERAL DO PALPITE. Desligada em 04/11/2026, a pedido do Marcos ("pode
 * tirar a enquete de lá"): o resultado já saiu e o popup não deve mais aparecer.
 *
 * Desligar não apaga nada: os votos e quem respondeu continuam nas tabelas, o
 * `?action=resultado` do admin segue funcionando, e religar é só trocar para
 * true. O que a chave fecha é a PERGUNTA — o popup (previsaoPendente) e a
 * gravação de voto/pulo na API.
 */
const PREVISAO_ATIVA = false;

/** O que cada liga pergunta: bloco => quantos times. A ordem é a ordem na tela. */
const PREVISAO_BLOCOS = [
    'ELITE'  => ['top3' => 3, 'cai' => 4],
    'NEXT'   => ['sobe' => 4, 'cai' => 4],
    'RISE'   => ['sobe' => 4, 'cai' => 4],
    'ROOKIE' => ['sobe' => 4],
];

function previsaoGarantirTabelas(PDO $pdo): void
{
    static $ok = false;
    if ($ok) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS previsao_edicao_status (
        user_id    INT NOT NULL PRIMARY KEY,
        league     VARCHAR(10) NOT NULL,
        status     ENUM('respondeu','pulou') NOT NULL,
        criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // SÓ O TOTAL: sem user_id e sem data, de propósito. Uma linha por voto, com
    // id em sequência, deixaria reconstruir quem votou em quê pela ordem.
    $pdo->exec("CREATE TABLE IF NOT EXISTS previsao_edicao_votos (
        league   VARCHAR(10) NOT NULL,
        bloco    ENUM('sobe','cai','top3') NOT NULL,
        team_id  INT NOT NULL,
        votos    INT NOT NULL DEFAULT 0,
        PRIMARY KEY (league, bloco, team_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ok = true;
}

/**
 * Os times de uma liga, pra tela escolher.
 *
 * @return list<array{id:int,nome:string,escudo:string}>
 */
function previsaoTimesDaLiga(PDO $pdo, string $liga): array
{
    $st = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(city,''),' ',COALESCE(name,''))) AS nome, photo_url
                           FROM teams WHERE league = ? ORDER BY city, name");
    $st->execute([$liga]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $out[] = ['id' => (int)$t['id'], 'nome' => (string)$t['nome'],
                  'escudo' => getTeamPhoto($t['photo_url'] ?? null)];
    }
    return $out;
}

/**
 * Esse usuário ainda deve ver o popup? Devolve a pergunta pronta ou null.
 *
 * @return ?array{liga:string,blocos:array<string,int>,times:list<array>}
 */
function previsaoPendente(PDO $pdo, int $userId, ?string $liga): ?array
{
    if (!PREVISAO_ATIVA) return null;
    $liga = strtoupper(trim((string)$liga));
    if ($userId <= 0 || !isset(PREVISAO_BLOCOS[$liga])) return null;
    try {
        previsaoGarantirTabelas($pdo);
        $st = $pdo->prepare('SELECT 1 FROM previsao_edicao_status WHERE user_id = ?');
        $st->execute([$userId]);
        if ($st->fetchColumn()) return null;
    } catch (Throwable $e) {
        // Sem a tabela não há como lembrar quem respondeu, e perguntar a todo
        // carregamento seria pior que não perguntar.
        error_log('[previsao] pendente: ' . $e->getMessage());
        return null;
    }
    $times = previsaoTimesDaLiga($pdo, $liga);
    if (count($times) < 4) return null;
    return ['liga' => $liga, 'blocos' => PREVISAO_BLOCOS[$liga], 'times' => $times];
}

/**
 * Grava a resposta de um usuário. Confere tudo de novo — a tela é conveniência.
 *
 * @param array<string,list<int>> $picks bloco => ids dos times
 * @return array{ok:bool,erro?:string}
 */
function previsaoSalvar(PDO $pdo, int $userId, string $liga, array $picks): array
{
    $liga = strtoupper(trim($liga));
    if (!isset(PREVISAO_BLOCOS[$liga])) return ['ok' => false, 'erro' => 'Sua liga não participa deste palpite.'];
    previsaoGarantirTabelas($pdo);

    $st = $pdo->prepare('SELECT 1 FROM previsao_edicao_status WHERE user_id = ?');
    $st->execute([$userId]);
    if ($st->fetchColumn()) return ['ok' => false, 'erro' => 'Você já respondeu este palpite.'];

    $validos = array_column(previsaoTimesDaLiga($pdo, $liga), 'id');
    $regras  = PREVISAO_BLOCOS[$liga];

    $linhas = []; $vistos = [];
    foreach ($regras as $bloco => $quantos) {
        $ids = array_values(array_unique(array_map('intval', (array)($picks[$bloco] ?? []))));
        if (count($ids) !== $quantos) {
            $rotulo = ['sobe' => 'sobem', 'cai' => 'caem', 'top3' => 'ficam no top 3'][$bloco];
            return ['ok' => false, 'erro' => "Escolha exatamente {$quantos} times que {$rotulo}."];
        }
        foreach ($ids as $id) {
            if (!in_array($id, $validos, true)) return ['ok' => false, 'erro' => 'Tem time que não é da sua liga.'];
            if (isset($vistos[$id])) return ['ok' => false, 'erro' => 'Um time não pode estar em dois grupos ao mesmo tempo.'];
            $vistos[$id] = true;
            $linhas[] = [$bloco, $id];
        }
    }
    // Bloco que a liga não pergunta não entra, mesmo que alguém mande.

    $pdo->beginTransaction();
    try {
        // Soma no contador; a escolha em si não é gravada em lugar nenhum.
        $ins = $pdo->prepare('INSERT INTO previsao_edicao_votos (league, bloco, team_id, votos) VALUES (?,?,?,1)
                               ON DUPLICATE KEY UPDATE votos = votos + 1');
        foreach ($linhas as [$bloco, $id]) $ins->execute([$liga, $bloco, $id]);
        $pdo->prepare("INSERT INTO previsao_edicao_status (user_id, league, status) VALUES (?,?, 'respondeu')")
            ->execute([$userId, $liga]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[previsao] salvar: ' . $e->getMessage());
        // Chave duplicada = duas abas respondendo juntas: a segunda já era.
        return ['ok' => false, 'erro' => 'Você já respondeu este palpite.'];
    }
    return ['ok' => true];
}

/** "Agora não": encerra o popup sem palpite. */
function previsaoPular(PDO $pdo, int $userId, string $liga): array
{
    $liga = strtoupper(trim($liga));
    previsaoGarantirTabelas($pdo);
    $pdo->prepare("INSERT IGNORE INTO previsao_edicao_status (user_id, league, status) VALUES (?,?, 'pulou')")
        ->execute([$userId, $liga]);
    return ['ok' => true];
}

/**
 * O resultado, pro admin: quantos votos cada time levou em cada bloco.
 *
 * @return array{respostas:int,puladas:int,por_liga:array}
 */
function previsaoResultado(PDO $pdo): array
{
    previsaoGarantirTabelas($pdo);
    $r = ['respostas' => 0, 'puladas' => 0, 'por_liga' => []];
    foreach ($pdo->query("SELECT status, COUNT(*) n FROM previsao_edicao_status GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $l) {
        if ($l['status'] === 'respondeu') $r['respostas'] = (int)$l['n']; else $r['puladas'] = (int)$l['n'];
    }
    $st = $pdo->query("SELECT v.league, v.bloco, v.team_id, TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS time, v.votos
                         FROM previsao_edicao_votos v JOIN teams t ON t.id = v.team_id
                     ORDER BY v.league, v.bloco, v.votos DESC, time");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $r['por_liga'][$l['league']][$l['bloco']][] = ['time' => $l['time'], 'votos' => (int)$l['votos']];
    }
    return $r;
}
