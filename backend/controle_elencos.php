<?php
/**
 * CONTROLE DE ELENCOS — o admin lançando letras e estatísticas por CSV.
 *
 * Existia o caminho do dono (atualizar-elenco.php) e o de terceiros
 * (atualizar-time.php, que paga moedas e tranca o time). Faltava o de quem
 * administra: abrir a liga inteira numa tela, ver quem está atrasado e lançar
 * um time — ou todos de uma vez — a partir de um CSV.
 *
 * O QUE É REAPROVEITADO, e não reescrito: as colunas, as notas aceitas, os
 * tetos de estatística e os validadores moram em backend/atualizacoes.php; a
 * "foto do antes" também. Assim um envio do admin fica no mesmo histórico dos
 * terceiros e o botão de reverter que já existe desfaz os dois.
 *
 * O QUE MUDA pro admin, e por quê:
 *   - Não paga moeda. É trabalho de administração, não serviço prestado.
 *   - Não tranca o time pra terceiros. A trava existe pra impedir que a mesma
 *     pessoa receba duas vezes pelo mesmo time — o admin não recebe nada.
 *     Por isso o registro leva origem = 'admin' e a trava só conta 'terceiro'.
 *   - OVR e idade continuam fora, igual aos terceiros: foi o CSV velho
 *     gravando OVR que fez o elenco "voltar pro over anterior".
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/atualizacoes.php';
require_once __DIR__ . '/stats_temporada.php';
require_once __DIR__ . '/snapshot_temporada.php';

/** Sem isto um envio do admin trancaria o time pra terceiros. */
function ceGarantirOrigem(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;
    ensureAtualizacaoTables($pdo);
    try {
        if (!$pdo->query("SHOW COLUMNS FROM atualizacoes_terceiros LIKE 'origem'")->fetch()) {
            $pdo->exec("ALTER TABLE atualizacoes_terceiros
                        ADD COLUMN origem VARCHAR(10) NOT NULL DEFAULT 'terceiro'");
        }
    } catch (Throwable $e) {
        error_log('[controle_elencos] coluna origem: ' . $e->getMessage());
    }
}

/**
 * Os times da liga, com o que falta em cada um.
 *
 * Uma consulta só pra liga inteira: o card de cada time precisa de quatro
 * números, e perguntar time a time seria trinta idas ao banco pra desenhar a
 * tela.
 */
function ceTimesDaLiga(PDO $pdo, string $liga): array
{
    ceGarantirOrigem($pdo);
    ensureRosterTouchColumn($pdo);
    $alvo = statsTemporadaAlvo($pdo, $liga)['alvo'] ?? null;

    $st = $pdo->prepare("
        SELECT t.id, t.city, t.name, t.photo_url, u.name AS gm,
               (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id) AS jogadores,
               (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id
                   AND (p.skill_in IS NULL OR p.skill_in = '')) AS sem_letras,
               (SELECT COUNT(*) FROM player_season_stats s
                 WHERE s.team_id = t.id AND s.season_id = ?) AS com_stats,
               (SELECT MAX(a.criado_em) FROM atualizacoes_terceiros a
                 WHERE a.team_id = t.id AND a.tipo = 'skills' AND a.revertido_em IS NULL) AS csv_letras_em,
               (SELECT MAX(a.criado_em) FROM atualizacoes_terceiros a
                 WHERE a.team_id = t.id AND a.tipo = 'stats' AND a.revertido_em IS NULL) AS csv_stats_em,
               t.roster_updated_at
          FROM teams t
     LEFT JOIN users u ON u.id = t.user_id
         WHERE t.league = ?
      ORDER BY t.city, t.name");
    $st->execute([(int)($alvo['id'] ?? 0), $liga]);

    $times = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $times[] = [
            'id'         => (int)$t['id'],
            'nome'       => trim(($t['city'] ?? '') . ' ' . ($t['name'] ?? '')),
            'logo'       => getTeamPhoto($t['photo_url'] ?? null),
            'gm'         => $t['gm'] ?: 'sem GM',
            'jogadores'  => (int)$t['jogadores'],
            'sem_letras' => (int)$t['sem_letras'],
            'com_stats'  => (int)$t['com_stats'],
            // A data que aparece no card: o último envio por CSV daquele tipo,
            // e na falta dele a última mexida no elenco. Sem data nenhuma, o
            // time nunca foi tocado — e é esse que o admin está procurando.
            'letras_em'  => $t['csv_letras_em'] ?: $t['roster_updated_at'],
            'stats_em'   => $t['csv_stats_em'],
        ];
    }

    return [
        'times'     => $times,
        'temporada' => $alvo ? ['id' => (int)$alvo['id'], 'numero' => (int)$alvo['season_number'],
                                'ano' => (int)($alvo['year'] ?? 0)] : null,
    ];
}

/**
 * Os jogadores de um time — ou da liga inteira, quando $teamId é null — com as
 * letras de agora e as estatísticas já lançadas na temporada-alvo. É o que o
 * modal mostra como "valor atual" ao lado do que veio no CSV.
 */
function ceJogadores(PDO $pdo, string $liga, ?int $teamId): array
{
    $alvo = statsTemporadaAlvo($pdo, $liga)['alvo'] ?? null;
    $colsSkill = implode(', ', array_map(fn($c) => "p.{$c}", array_keys(ATUALIZACAO_SKILLS)));
    $colsStat  = implode(', ', array_map(fn($c) => "s.{$c}", array_keys(ATUALIZACAO_STATS)));

    $sql = "SELECT p.id, p.name, p.position, p.ovr, p.team_id,
                   TRIM(CONCAT(COALESCE(t.city,''),' ',t.name)) AS time,
                   {$colsSkill}, {$colsStat}, s.id AS tem_stats
              FROM players p
              JOIN teams t ON t.id = p.team_id
         LEFT JOIN player_season_stats s ON s.player_id = p.id AND s.season_id = ?
             WHERE t.league = ?";
    $args = [(int)($alvo['id'] ?? 0), $liga];
    if ($teamId) { $sql .= ' AND t.id = ?'; $args[] = $teamId; }
    $sql .= ' ORDER BY t.city, t.name, p.ovr DESC, p.name';

    $st = $pdo->prepare($sql);
    $st->execute($args);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $j = ['id' => (int)$r['id'], 'name' => $r['name'], 'position' => $r['position'],
              'ovr' => (int)$r['ovr'], 'team_id' => (int)$r['team_id'], 'time' => $r['time']];
        foreach (array_keys(ATUALIZACAO_SKILLS) as $c) $j[$c] = (string)($r[$c] ?? '');
        foreach (array_keys(ATUALIZACAO_STATS) as $c) {
            // Sem linha lançada, o campo vem vazio e não zero: "não lançado" e
            // "lançado como zero" são coisas diferentes na revisão.
            $j[$c] = $r['tem_stats'] ? (float)$r[$c] : '';
        }
        $out[] = $j;
    }
    return $out;
}

/**
 * Grava o que o admin revisou. Tudo ou nada: numa liga inteira, parar no
 * vigésimo time deixaria dezenove gravados e onze não, e ninguém saberia
 * quais sem abrir time por time.
 *
 * $linhas: [['id' => playerId, <colunas>...], ...]. O time de cada jogador sai
 * do BANCO, não do CSV — a coluna "time" do arquivo é só pra quem lê.
 *
 * @return array{ok:bool, erro:?string, times:array, ignorados:int, vazios:int}
 */
function ceGravar(PDO $pdo, int $adminId, string $liga, string $tipo, array $linhas): array
{
    $falha = fn(string $e) => ['ok' => false, 'erro' => $e, 'times' => [], 'ignorados' => 0, 'vazios' => 0];
    if (!in_array($tipo, ['letras', 'stats'], true)) return $falha('Tipo inválido.');
    if (!$linhas) return $falha('Nada pra gravar.');

    // DDL antes da transação: ALTER TABLE faz commit implícito no MySQL, e
    // dentro dela um erro depois deixaria metade gravada.
    ceGarantirOrigem($pdo);
    ensureRosterTouchColumn($pdo);

    // Quem é da liga, e de qual time. Jogador de fora é ignorado, não erro:
    // numa liga inteira, uma linha de um cara que acabou de ser trocado não
    // pode derrubar o envio de todo mundo.
    $st = $pdo->prepare('SELECT p.id, p.name, p.team_id FROM players p JOIN teams t ON t.id = p.team_id WHERE t.league = ?');
    $st->execute([$liga]);
    $daLiga = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $daLiga[(int)$r['id']] = $r;

    $porTime = [];
    $ignorados = 0; $vazios = 0;
    foreach ($linhas as $linha) {
        $pid = (int)($linha['id'] ?? 0);
        if (!isset($daLiga[$pid])) { $ignorados++; continue; }
        $tid = (int)$daLiga[$pid]['team_id'];

        if ($tipo === 'letras') {
            [$valido, $vals, $erro] = atualizacaoValidarSkills($linha);
            if (!$valido) return $falha($daLiga[$pid]['name'] . ': ' . $erro);
            if (!$vals) { $vazios++; continue; }
        } else {
            [$valido, $vals, $erro] = atualizacaoValidarStats($linha);
            if (!$valido) {
                // Linha em branco não é erro — é jogador que não jogou. Número
                // fora da faixa é: recusar é o que impede o armador com 8 tocos.
                if ($erro === 'linha sem jogos e sem pontos') { $vazios++; continue; }
                return $falha($daLiga[$pid]['name'] . ': ' . $erro);
            }
        }
        $porTime[$tid][$pid] = $vals;
    }
    if (!$porTime) return $falha('Nenhuma linha com dado pra gravar.');

    $temporada = null;
    if ($tipo === 'stats') {
        $temporada = statsTemporadaAlvo($pdo, $liga)['alvo'] ?? null;
        if (!$temporada) return $falha('A liga não tem temporada pra receber estatística.');
    }

    $fotos = [];
    foreach (array_keys($porTime) as $tid) $fotos[$tid] = atualizacaoFoto($pdo, $tid);

    $tipoRegistro = $tipo === 'letras' ? 'skills' : 'stats';
    $resumo = [];

    $pdo->beginTransaction();
    try {
        if ($tipo === 'letras') {
            $sets = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys(ATUALIZACAO_SKILLS)));
            $up = $pdo->prepare("UPDATE players SET {$sets} WHERE id = :id AND team_id = :tid");
        } else {
            $cols = array_keys(ATUALIZACAO_STATS);
            $up = $pdo->prepare("INSERT INTO player_season_stats
                (player_id, season_id, season_number, league, team_id, " . implode(', ', $cols) . ", source)
                VALUES (:pid, :sid, :snum, :liga, :tid, :" . implode(', :', $cols) . ", 'manual')
                ON DUPLICATE KEY UPDATE " .
                implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $cols)) .
                ", source = VALUES(source), team_id = VALUES(team_id)");
        }
        $reg = $pdo->prepare("INSERT INTO atualizacoes_terceiros
            (team_id, league, user_id, tipo, jogadores, moedas, antes, csv, origem)
            VALUES (?,?,?,?,?,0,?,?,'admin')");

        foreach ($porTime as $tid => $jogadores) {
            foreach ($jogadores as $pid => $vals) {
                if ($tipo === 'letras') {
                    // Letra em branco no CSV mantém a que já está lá.
                    $atual = null;
                    foreach ($fotos[$tid]['skills'] as $f) if ((int)$f['id'] === $pid) { $atual = $f; break; }
                    $p = ['id' => $pid, 'tid' => $tid];
                    foreach (array_keys(ATUALIZACAO_SKILLS) as $c) $p[$c] = $vals[$c] ?? ($atual[$c] ?? null);
                    $up->execute($p);
                } else {
                    $up->execute(array_merge($vals, [
                        'pid' => $pid, 'tid' => $tid, 'liga' => $liga,
                        'sid' => (int)$temporada['id'], 'snum' => (int)$temporada['season_number'],
                    ]));
                }
            }
            $reg->execute([$tid, $liga, $adminId, $tipoRegistro, count($jogadores),
                           json_encode($fotos[$tid], JSON_UNESCAPED_UNICODE),
                           mb_substr(json_encode($jogadores, JSON_UNESCAPED_UNICODE), 0, 200000)]);
            $resumo[$tid] = count($jogadores);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[controle_elencos] gravar: ' . $e->getMessage());
        return $falha('Não deu pra gravar. Nada foi salvo.');
    }

    // Depois do commit: marcar o elenco e congelar a temporada abrem transação
    // e DDL próprios. Falhar aqui não desfaz o que já foi gravado — é carimbo.
    $temporadaAtiva = $tipo === 'letras' ? temporadaAtivaDaLiga($pdo, $liga) : null;
    foreach (array_keys($resumo) as $tid) {
        marcarElencoAtualizado($pdo, $tid);
        if ($temporadaAtiva) snapshotTemporadaDoTime($pdo, $tid, $temporadaAtiva, $liga);
    }

    $nomes = [];
    $st = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(city,''),' ',name)) FROM teams WHERE id = ?");
    foreach ($resumo as $tid => $n) {
        $st->execute([$tid]);
        $nomes[] = ['id' => $tid, 'nome' => (string)$st->fetchColumn(), 'jogadores' => $n];
    }

    return ['ok' => true, 'erro' => null, 'times' => $nomes, 'ignorados' => $ignorados, 'vazios' => $vazios];
}
