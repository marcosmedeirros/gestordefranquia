<?php
/**
 * API da atualização de elenco por terceiros.
 *
 *   GET  ?acao=elegiveis            → times da minha liga que posso atualizar
 *   GET  ?acao=elenco&time=ID       → o elenco, pra montar o modelo de CSV
 *   POST acao=salvar                → grava, paga e tranca o time
 *   GET  ?acao=ranking[&liga=X]     → quem mais atualizou
 *   GET  ?acao=historico            → últimos envios (admin)
 *   POST acao=reverter              → desfaz e estorna (admin)
 *
 * A regra de quem pode o quê mora em backend/atualizacoes.php; aqui é só a
 * porta HTTP.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/atualizacoes.php';

$user = getUserSession();
if (!$user) { http_response_code(401); echo json_encode(['erro' => 'Sessão expirada.']); exit; }

$pdo = db();
ensureAtualizacaoTables($pdo);

$uid = (int)$user['id'];

// A ação pode vir de três lugares, e o corpo JSON é um deles: a página manda
// `salvar` com Content-Type: application/json, e nesse caso o PHP não
// preenche $_POST — ler só dele fazia todo salvamento cair em "ação
// desconhecida". O corpo é lido uma vez e reaproveitado embaixo.
$corpoBruto = file_get_contents('php://input');
$corpoJson  = ($corpoBruto !== '' && str_starts_with(ltrim($corpoBruto), '{'))
            ? (json_decode($corpoBruto, true) ?: [])
            : [];
$acao = $_GET['acao'] ?? $_POST['acao'] ?? ($corpoJson['acao'] ?? '');

// A liga do usuário sai do TIME dele, não da sessão: é o time que define
// em qual liga ele joga, e é essa a liga que ele pode atualizar.
$stMeu = $pdo->prepare("SELECT id, league FROM teams WHERE user_id = ? LIMIT 1");
$stMeu->execute([$uid]);
$meuTime = $stMeu->fetch(PDO::FETCH_ASSOC);
$minhaLiga = strtoupper((string)($meuTime['league'] ?? ($user['league'] ?? '')));
$souAdmin = ($user['user_type'] ?? 'jogador') === 'admin';

// ── Times que dá pra atualizar ───────────────────────────────────────
if ($acao === 'elegiveis') {
    if ($minhaLiga === '') { echo json_encode(['ok' => true, 'times' => []]); exit; }

    // O que cada time da liga já recebeu, numa consulta só: perguntar time a
    // time seria uma ida ao banco por linha da lista.
    $feitos = atualizacaoTiposFeitosDaLiga($pdo, $minhaLiga);

    $st = $pdo->prepare("
        SELECT t.id, t.city, t.name, u.name AS dono,
               t.atualizado_terceiro_em,
               (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id) AS jogadores,
               (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id
                  AND (p.skill_in IS NULL OR p.skill_in = '')) AS sem_skills
        FROM teams t LEFT JOIN users u ON u.id = t.user_id
        WHERE t.league = ? AND t.id <> ?
        ORDER BY sem_skills DESC, t.city, t.name");
    $st->execute([$minhaLiga, (int)($meuTime['id'] ?? 0)]);

    $times = [];
    foreach ($st as $t) {
        $feito  = $feitos[(int)$t['id']] ?? ['skills' => false, 'stats' => false];
        $faltam = array_keys(array_filter($feito, fn($f) => !$f));
        $times[] = [
            'id'         => (int)$t['id'],
            'nome'       => trim(($t['city'] ?? '') . ' ' . ($t['name'] ?? '')),
            'dono'       => $t['dono'] ?: 'sem dono',
            'jogadores'  => (int)$t['jogadores'],
            'sem_skills' => (int)$t['sem_skills'],
            // Travado só quando não falta nada: skills feitas e stats não é
            // time trancado, é time pela metade.
            'travado'    => $faltam === [],
            'faltam'     => $faltam,
        ];
    }
    echo json_encode(['ok' => true, 'liga' => $minhaLiga, 'times' => $times,
                      'resumo' => atualizacaoResumoDoUsuario($pdo, $uid),
                      'premio' => ATUALIZACAO_MOEDAS]);
    exit;
}

// ── O elenco de um time ──────────────────────────────────────────────
if ($acao === 'elenco') {
    $teamId = (int)($_GET['time'] ?? 0);
    $check = atualizacaoPodeAtualizar($pdo, $uid, $teamId, $minhaLiga);
    if (!$check['pode']) { http_response_code(403); echo json_encode(['erro' => $check['motivo']]); exit; }

    $colsSkill = implode(', ', array_keys(ATUALIZACAO_SKILLS));
    $st = $pdo->prepare("SELECT id, name, position, ovr, age, {$colsSkill}
                         FROM players WHERE team_id = ? ORDER BY ovr DESC, name");
    $st->execute([$teamId]);
    $jogadores = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true,
        'time' => ['id' => $teamId,
                   'nome' => trim(($check['time']['city'] ?? '') . ' ' . ($check['time']['name'] ?? '')),
                   'dono' => $check['time']['dono'] ?: 'sem dono'],
        'jogadores' => $jogadores,
        'skills' => ATUALIZACAO_SKILLS,
        'notas'  => ATUALIZACAO_NOTAS,
        'stats'  => array_map(fn($r) => $r['rot'], ATUALIZACAO_STATS),
        'premio' => ATUALIZACAO_MOEDAS,
    ]);
    exit;
}

// ── Ranking ──────────────────────────────────────────────────────────
if ($acao === 'ranking') {
    $liga = strtoupper(trim((string)($_GET['liga'] ?? $minhaLiga)));
    // Só a própria liga, salvo admin: o ranking é da comunidade dela.
    if (!$souAdmin) $liga = $minhaLiga;
    echo json_encode(['ok' => true, 'liga' => $liga, 'ranking' => atualizacaoRanking($pdo, $liga)]);
    exit;
}

// ── Gravar ───────────────────────────────────────────────────────────
if ($acao === 'salvar') {
    $corpo = $corpoJson;
    $teamId = (int)($corpo['time'] ?? 0);
    $skills = is_array($corpo['skills'] ?? null) ? $corpo['skills'] : [];
    $stats  = is_array($corpo['stats']  ?? null) ? $corpo['stats']  : [];

    $check = atualizacaoPodeAtualizar($pdo, $uid, $teamId, $minhaLiga);
    if (!$check['pode']) { http_response_code(403); echo json_encode(['erro' => $check['motivo']]); exit; }
    if (!$skills && !$stats) {
        http_response_code(400); echo json_encode(['erro' => 'Nada pra salvar.']); exit;
    }

    // Só jogadores DESTE time entram. Sem isto, um id trocado no CSV
    // escreveria no elenco de outro clube.
    $st = $pdo->prepare("SELECT id FROM players WHERE team_id = ?");
    $st->execute([$teamId]);
    $doTime = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    // Valida tudo ANTES de gravar qualquer coisa: meia atualização é pior
    // que nenhuma, porque ninguém sabe onde parou.
    $okSkills = []; $okStats = [];
    foreach ($skills as $linha) {
        $pid = (int)($linha['id'] ?? 0);
        if (!in_array($pid, $doTime, true)) continue;
        [$valido, $vals, $erro] = atualizacaoValidarSkills($linha);
        if (!$valido) { http_response_code(400); echo json_encode(['erro' => "Jogador {$pid}: {$erro}"]); exit; }
        if ($vals) $okSkills[$pid] = $vals;
    }
    foreach ($stats as $linha) {
        $pid = (int)($linha['id'] ?? 0);
        if (!in_array($pid, $doTime, true)) continue;
        [$valido, $vals, $erro] = atualizacaoValidarStats($linha);
        if (!$valido) continue;          // linha vazia é ignorada, não é erro
        $okStats[$pid] = $vals;
    }
    if (!$okSkills && !$okStats) {
        http_response_code(400); echo json_encode(['erro' => 'Nenhuma linha válida no CSV.']); exit;
    }

    // O que já foi feito neste time sai fora — a trava é por tipo. Quem manda
    // um CSV com os dois num time que já tem skills recebe só pelas stats, em
    // vez de levar um "já foi atualizado" e não conseguir completar nada.
    $faltam = $check['faltam'] ?? ['skills', 'stats'];
    $jaTinhaSkills = $okSkills && !in_array('skills', $faltam, true);
    $jaTinhaStats  = $okStats  && !in_array('stats',  $faltam, true);
    if ($jaTinhaSkills) $okSkills = [];
    if ($jaTinhaStats)  $okStats  = [];
    if (!$okSkills && !$okStats) {
        http_response_code(409);
        echo json_encode(['erro' => 'Isso já foi atualizado por outra pessoa neste time.']);
        exit;
    }

    /* A temporada do lancamento nao e a "aberta": a que acabou de nascer esta
       no draft e nao teve jogo. Quem decide e statsTemporadaAlvo(), pelo marco
       da classificacao — a mesma regra do CSV e da foto. */
    $temporada = null;
    try {
        require_once __DIR__ . '/../backend/stats_temporada.php';
        $temporada = statsTemporadaAlvo($pdo, $minhaLiga)['alvo'] ?: null;
    } catch (Throwable $e) {}
    if ($okStats && !$temporada) {
        http_response_code(400);
        echo json_encode(['erro' => 'A liga não tem temporada aberta — só dá pra enviar as skills agora.']);
        exit;
    }

    $foto = atualizacaoFoto($pdo, $teamId);
    $registros = [];
    $moedasTotal = 0;

    // ANTES da transação, sempre: as tabelas do Games nascem no primeiro
    // acesso a uma página de jogo e ninguém garante que isso já aconteceu.
    // E DDL dentro de transação faz commit implícito no MySQL — se rodasse lá
    // dentro, um erro depois já teria gravado metade e o rollBack estouraria
    // com "no active transaction". Aqui fora, o rollback continua valendo.
    ensureGamesSchema($pdo);

    $pdo->beginTransaction();
    try {
        if ($okSkills) {
            $sets = [];
            foreach (array_keys(ATUALIZACAO_SKILLS) as $c) $sets[] = "{$c} = :{$c}";
            // OVR e idade ficam de fora: o CSV é baixado num momento e enviado
            // em outro, e gravá-los aqui devolvia o número velho do arquivo por
            // cima do que o dono já tinha corrigido. Só as notas são atualizadas.
            $up = $pdo->prepare("UPDATE players SET " . implode(', ', $sets) . " WHERE id = :id AND team_id = :tid");
            foreach ($okSkills as $pid => $vals) {
                $atual = null;
                foreach ($foto['skills'] as $f) if ((int)$f['id'] === $pid) { $atual = $f; break; }
                $p = ['id' => $pid, 'tid' => $teamId];
                foreach (array_keys(ATUALIZACAO_SKILLS) as $c) {
                    $p[$c] = $vals[$c] ?? ($atual[$c] ?? null);
                }
                $up->execute($p);
            }
            $registros[] = ['tipo' => 'skills', 'n' => count($okSkills),
                            'moedas' => ATUALIZACAO_MOEDAS['skills'],
                            'csv' => (string)($corpo['csv_skills'] ?? '')];
            $moedasTotal += ATUALIZACAO_MOEDAS['skills'];
        }

        if ($okStats) {
            // Mesmas colunas do insert que o dono usa em api/player_stats.php,
            // `league` inclusive — sem ela a linha nasce sem liga e some das
            // consultas que filtram por liga.
            //
            // source é ENUM('foto','manual','clonado'): 'manual' é o valor
            // certo — é número real digitado, igual ao caminho do dono. Quem
            // fez e quando fica em atualizacoes_terceiros, que é onde a tela
            // de reverter procura.
            $cols = array_keys(ATUALIZACAO_STATS);
            $ins = $pdo->prepare("INSERT INTO player_season_stats
                (player_id, season_id, season_number, league, team_id, " . implode(', ', $cols) . ", source)
                VALUES (:pid, :sid, :snum, :liga, :tid, :" . implode(', :', $cols) . ", 'manual')
                ON DUPLICATE KEY UPDATE " .
                implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $cols)) .
                ", source = VALUES(source), team_id = VALUES(team_id)");
            foreach ($okStats as $pid => $vals) {
                $ins->execute(array_merge($vals, [
                    'pid' => $pid, 'tid' => $teamId, 'liga' => $minhaLiga,
                    'sid' => (int)$temporada['id'], 'snum' => (int)$temporada['season_number'],
                ]));
            }
            $registros[] = ['tipo' => 'stats', 'n' => count($okStats),
                            'moedas' => ATUALIZACAO_MOEDAS['stats'],
                            'csv' => (string)($corpo['csv_stats'] ?? '')];
            $moedasTotal += ATUALIZACAO_MOEDAS['stats'];
        }

        $reg = $pdo->prepare("INSERT INTO atualizacoes_terceiros
            (team_id, league, user_id, tipo, jogadores, moedas, antes, csv)
            VALUES (?,?,?,?,?,?,?,?)");
        $fotoJson = json_encode($foto, JSON_UNESCAPED_UNICODE);
        foreach ($registros as $r) {
            $reg->execute([$teamId, $minhaLiga, $uid, $r['tipo'], $r['n'], $r['moedas'],
                           $fotoJson, mb_substr($r['csv'], 0, 200000)]);
        }

        // Tranca o time pra terceiros. O dono segue livre: a página dele
        // não passa por aqui.
        $pdo->prepare("UPDATE teams SET atualizado_terceiro_por = ?, atualizado_terceiro_em = NOW()
                       WHERE id = ?")->execute([$uid, $teamId]);

        // Paga. games_usuarios pode não ter linha ainda — o INSERT IGNORE
        // resolve, senão o UPDATE some sem avisar.
        $pdo->prepare("INSERT IGNORE INTO games_usuarios (id, pontos) VALUES (?, 0)")->execute([$uid]);
        $pdo->prepare("UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?")
            ->execute([$moedasTotal, $uid]);

        $pdo->commit();
    } catch (Throwable $e) {
        // inTransaction() antes de desfazer: se algum DDL tiver feito commit
        // implícito, rollBack() estoura e o cliente recebe um fatal em vez do
        // JSON de erro. Melhor logar o problema real.
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[atualizacoes] salvar: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['erro' => 'Não deu pra salvar. Nada foi gravado.']);
        exit;
    }

    // Diz o que foi ignorado: recebeu 80 em vez de 180 e não saber por quê é
    // o tipo de silêncio que vira reclamação.
    $ignorados = [];
    if ($jaTinhaSkills) $ignorados[] = 'skills';
    if ($jaTinhaStats)  $ignorados[] = 'stats';

    echo json_encode(['ok' => true, 'moedas' => $moedasTotal,
        'skills' => count($okSkills), 'stats' => count($okStats),
        'ignorados' => $ignorados,
        'faltam' => atualizacaoTiposQueFaltam($pdo, $teamId)]);
    exit;
}

// ── Admin: histórico e reversão ──────────────────────────────────────
if ($acao === 'historico') {
    if (!$souAdmin) { http_response_code(403); echo json_encode(['erro' => 'Só admin.']); exit; }
    $st = $pdo->query("SELECT a.id, a.team_id, a.league, a.tipo, a.jogadores, a.moedas,
                              a.criado_em, a.revertido_em,
                              TRIM(CONCAT(COALESCE(t.city,''),' ',t.name)) AS time,
                              u.name AS gm
                       FROM atualizacoes_terceiros a
                       LEFT JOIN teams t ON t.id = a.team_id
                       LEFT JOIN users u ON u.id = a.user_id
                       ORDER BY a.id DESC LIMIT 100");
    echo json_encode(['ok' => true, 'itens' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($acao === 'reverter') {
    if (!$souAdmin) { http_response_code(403); echo json_encode(['erro' => 'Só admin.']); exit; }
    $id = (int)($_POST['id'] ?? 0);

    $st = $pdo->prepare("SELECT * FROM atualizacoes_terceiros WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $reg = $st->fetch(PDO::FETCH_ASSOC);
    if (!$reg)                       { http_response_code(404); echo json_encode(['erro' => 'Registro não encontrado.']); exit; }
    if (!empty($reg['revertido_em'])) { http_response_code(409); echo json_encode(['erro' => 'Esse envio já foi revertido.']); exit; }

    $foto = json_decode((string)$reg['antes'], true) ?: [];

    ensureGamesSchema($pdo);   // fora da transação — ver o comentário no salvar
    $pdo->beginTransaction();
    try {
        // Devolve os valores que estavam lá antes.
        if ($reg['tipo'] === 'skills' && !empty($foto['skills'])) {
            $sets = [];
            foreach (array_keys(ATUALIZACAO_SKILLS) as $c) $sets[] = "{$c} = :{$c}";
            // Também sem OVR e idade: o envio não mexe mais neles, então
            // devolver os da foto seria justamente o rollback que a gente
            // acabou de tirar do caminho — o valor da foto pode ser mais
            // velho que o que o dono ajustou depois.
            $up = $pdo->prepare("UPDATE players SET " . implode(', ', $sets) . " WHERE id = :id");
            foreach ($foto['skills'] as $f) {
                $p = ['id' => (int)$f['id']];
                foreach (array_keys(ATUALIZACAO_SKILLS) as $c) $p[$c] = $f[$c] ?? null;
                $up->execute($p);
            }
        }
        if ($reg['tipo'] === 'stats') {
            // Sem foto de stats significa que não havia linha: apaga as que
            // o envio criou, em vez de deixar número que ninguém pôs.
            $antesPorJogador = [];
            foreach ($foto['stats'] ?? [] as $f) $antesPorJogador[(int)$f['player_id']] = $f;

            $st2 = $pdo->prepare("SELECT player_id FROM player_season_stats WHERE team_id = ?");
            $st2->execute([(int)$reg['team_id']]);
            foreach ($st2->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $pid = (int)$pid;
                if (isset($antesPorJogador[$pid])) {
                    $f = $antesPorJogador[$pid];
                    $sets = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys(ATUALIZACAO_STATS)));
                    $up = $pdo->prepare("UPDATE player_season_stats SET {$sets} WHERE id = :id");
                    $p = ['id' => (int)$f['id']];
                    foreach (array_keys(ATUALIZACAO_STATS) as $c) $p[$c] = $f[$c] ?? 0;
                    $up->execute($p);
                } else {
                    $pdo->prepare("DELETE FROM player_season_stats WHERE team_id = ? AND player_id = ?")
                        ->execute([(int)$reg['team_id'], $pid]);
                }
            }
        }

        // Estorna. Nunca deixa o saldo negativo: se a pessoa já gastou, o
        // que dá pra tirar é o que tem — cobrar dívida de moeda de jogo
        // seria pior que perder as moedas.
        $pdo->prepare("UPDATE games_usuarios SET pontos = GREATEST(0, pontos - ?) WHERE id = ?")
            ->execute([(int)$reg['moedas'], (int)$reg['user_id']]);

        $pdo->prepare("UPDATE atualizacoes_terceiros SET revertido_em = NOW(), revertido_por = ? WHERE id = ?")
            ->execute([$uid, $id]);

        // Destrava o time só se não sobrou nenhum envio válido dele.
        $resta = $pdo->prepare("SELECT COUNT(*) FROM atualizacoes_terceiros
                                WHERE team_id = ? AND revertido_em IS NULL");
        $resta->execute([(int)$reg['team_id']]);
        if ((int)$resta->fetchColumn() === 0) {
            $pdo->prepare("UPDATE teams SET atualizado_terceiro_por = NULL, atualizado_terceiro_em = NULL
                           WHERE id = ?")->execute([(int)$reg['team_id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[atualizacoes] reverter: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['erro' => 'Não deu pra reverter.']);
        exit;
    }

    echo json_encode(['ok' => true, 'moedas_estornadas' => (int)$reg['moedas']]);
    exit;
}

http_response_code(400);
echo json_encode(['erro' => 'Ação desconhecida']);
