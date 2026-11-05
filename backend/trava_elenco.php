<?php
/**
 * TRAVA DE TROCA POR ELENCO DESATUALIZADO (pedido do Marcos, 05/11/2026).
 *
 * "Se o time não está atualizado, a pessoa não pode fazer troca." Dois
 * cenários, uma chave por liga (DESLIGADA por padrão — o admin liga quando
 * quiser, liga a liga):
 *
 *   ANTES / DURANTE O DRAFT  → o time precisa ter ESTATÍSTICAS lançadas de pelo
 *                              menos 6 jogadores, na última temporada jogada
 *                              (a mesma que a tela de lançamento usa como
 *                              alvo — statsTemporadaAlvo).
 *
 *   DEPOIS DA PROGRESSION    → quando a progression sai (de 2 a 3 horas depois
 *                              do fim do draft), o admin clica "Saiu a
 *                              progression". A partir daí o time precisa ter
 *                              atualizado as LETRAS de pelo menos 6 jogadores
 *                              para voltar a trocar.
 *
 * Entre o fim do draft e o clique do admin nada muda: vale a regra de antes.
 * Quando a temporada seguinte nasce, o estado da progression perde a validade
 * sozinho (ele guarda o id da temporada) e volta a valer a das estatísticas.
 *
 * COMO SE SABE QUE UMA LETRA FOI "ATUALIZADA". As letras são gravadas por sete
 * caminhos (dono, CSV de terceiro, CSV do admin, edição de admin...), e
 * carimbar a data em cada um seria esquecer um deles. Em vez disso o clique do
 * admin tira uma FOTO: um hash das letras e do OVR de cada jogador da liga. O
 * jogador conta como atualizado quando o hash de agora é diferente do da foto —
 * não importa por onde a mudança entrou. Consequência assumida: jogador cuja
 * letra a progression não mexeu não tem o que atualizar e não conta (mas são
 * necessários só 6, e a progression mexe na enorme maioria).
 *
 * UMA FALHA AQUI NUNCA BLOQUEIA TROCA. Qualquer erro de banco devolve
 * "liberado" e vai pro log: a trava existe pra cobrar quem está parado, e não
 * pode virar a razão de a liga inteira parar de negociar.
 */

require_once __DIR__ . '/helpers.php';

const TRAVA_ELENCO_MIN_PADRAO = 6;

/** Hash das letras + OVR de um jogador (alias `p`). Mesmo texto no retrato e na comparação. */
const TRAVA_ELENCO_HASH_SQL = "MD5(CONCAT_WS('|', COALESCE(p.ovr,''), COALESCE(p.skill_in,''), COALESCE(p.skill_mid,''),
        COALESCE(p.skill_3pt,''), COALESCE(p.skill_post_d,''), COALESCE(p.skill_per_d,''), COALESCE(p.skill_play,''),
        COALESCE(p.skill_reb,''), COALESCE(p.skill_athl,''), COALESCE(p.skill_iq,''), COALESCE(p.skill_pot,'')))";

function travaElencoGarantirTabelas(PDO $pdo): void
{
    static $ok = false;
    if ($ok) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS elenco_trava_liga (
        league               VARCHAR(10) NOT NULL PRIMARY KEY,
        ativa                TINYINT(1)  NOT NULL DEFAULT 0,
        min_jogadores        TINYINT     NOT NULL DEFAULT 6,
        progressao_em        DATETIME    NULL,
        progressao_season_id INT         NULL,
        atualizado_por       INT         NULL,
        atualizado_em        TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // A foto das letras no momento do clique. Uma por (temporada, jogador).
    $pdo->exec("CREATE TABLE IF NOT EXISTS elenco_progressao_base (
        season_id INT      NOT NULL,
        player_id INT      NOT NULL,
        hash      CHAR(32) NOT NULL,
        PRIMARY KEY (season_id, player_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ok = true;
}

/** A configuração da liga (com os padrões quando ainda não existe linha). */
function travaElencoConfig(PDO $pdo, string $liga): array
{
    $liga = strtoupper(trim($liga));
    $padrao = ['league' => $liga, 'ativa' => 0, 'min_jogadores' => TRAVA_ELENCO_MIN_PADRAO,
               'progressao_em' => null, 'progressao_season_id' => null];
    try {
        travaElencoGarantirTabelas($pdo);
        $st = $pdo->prepare('SELECT * FROM elenco_trava_liga WHERE league = ?');
        $st->execute([$liga]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? array_merge($padrao, $r) : $padrao;
    } catch (Throwable $e) {
        error_log('[trava-elenco] config: ' . $e->getMessage());
        return $padrao;
    }
}

function travaElencoDefinirAtiva(PDO $pdo, string $liga, bool $ativa, int $adminId): void
{
    travaElencoGarantirTabelas($pdo);
    $pdo->prepare("INSERT INTO elenco_trava_liga (league, ativa, atualizado_por) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE ativa = VALUES(ativa), atualizado_por = VALUES(atualizado_por)")
        ->execute([strtoupper(trim($liga)), $ativa ? 1 : 0, $adminId ?: null]);
}

/**
 * O admin clicou "Saiu a progression": tira a foto das letras da liga e passa a
 * cobrar a atualização delas.
 *
 * @return array{ok:bool,erro?:string,jogadores?:int}
 */
function travaElencoLiberarProgressao(PDO $pdo, string $liga, int $adminId): array
{
    $liga = strtoupper(trim($liga));
    travaElencoGarantirTabelas($pdo);
    $temp = temporadaAtivaDaLiga($pdo, $liga);
    if (!$temp) return ['ok' => false, 'erro' => "A {$liga} não tem temporada ativa."];
    $seasonId = (int)$temp['id'];

    // Só abre transação se quem chamou ainda não tem uma (e só então fecha).
    $minha = !$pdo->inTransaction();
    if ($minha) $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM elenco_progressao_base WHERE season_id = ?')->execute([$seasonId]);
        $ins = $pdo->prepare("INSERT INTO elenco_progressao_base (season_id, player_id, hash)
                              SELECT ?, p.id, " . TRAVA_ELENCO_HASH_SQL . "
                                FROM players p JOIN teams t ON t.id = p.team_id
                               WHERE t.league = ?");
        $ins->execute([$seasonId, $liga]);
        $n = $ins->rowCount();
        $pdo->prepare("INSERT INTO elenco_trava_liga (league, progressao_em, progressao_season_id, atualizado_por)
                       VALUES (?, NOW(), ?, ?)
                       ON DUPLICATE KEY UPDATE progressao_em = NOW(), progressao_season_id = VALUES(progressao_season_id),
                                               atualizado_por = VALUES(atualizado_por)")
            ->execute([$liga, $seasonId, $adminId ?: null]);
        if ($minha) $pdo->commit();
        return ['ok' => true, 'jogadores' => $n];
    } catch (Throwable $e) {
        if ($minha && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[trava-elenco] progressão: ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Não deu pra registrar a progression: ' . $e->getMessage()];
    }
}

/** Desfaz o clique: a liga volta à regra das estatísticas. */
function travaElencoDesfazerProgressao(PDO $pdo, string $liga): void
{
    $liga = strtoupper(trim($liga));
    travaElencoGarantirTabelas($pdo);
    $cfg = travaElencoConfig($pdo, $liga);
    if (!empty($cfg['progressao_season_id'])) {
        $pdo->prepare('DELETE FROM elenco_progressao_base WHERE season_id = ?')->execute([(int)$cfg['progressao_season_id']]);
    }
    $pdo->prepare('UPDATE elenco_trava_liga SET progressao_em = NULL, progressao_season_id = NULL WHERE league = ?')
        ->execute([$liga]);
}

/** A liga está na fase "depois da progression" (e esse clique é desta temporada)? */
function travaElencoEmProgressao(array $cfg, ?array $temporadaAtiva): bool
{
    return !empty($cfg['progressao_em']) && $temporadaAtiva
        && (int)$cfg['progressao_season_id'] === (int)$temporadaAtiva['id'];
}

/**
 * Como está um time diante da trava.
 *
 * @return array{ativa:bool,bloqueado:bool,modo:?string,tem:int,precisa:int,mensagem:?string,temporada?:?int}
 */
function travaElencoEstadoDoTime(PDO $pdo, int $teamId, string $liga, ?array $cfg = null): array
{
    $liga = strtoupper(trim($liga));
    $cfg = $cfg ?? travaElencoConfig($pdo, $liga);
    $base = ['ativa' => !empty($cfg['ativa']), 'bloqueado' => false, 'modo' => null,
             'tem' => 0, 'precisa' => (int)$cfg['min_jogadores'], 'mensagem' => null];
    if (!$base['ativa'] || $teamId <= 0) return $base;

    $temp = temporadaAtivaDaLiga($pdo, $liga);
    $precisa = $base['precisa'];

    if (travaElencoEmProgressao($cfg, $temp)) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM players p
                               JOIN elenco_progressao_base b ON b.player_id = p.id AND b.season_id = ?
                              WHERE p.team_id = ? AND " . TRAVA_ELENCO_HASH_SQL . " <> b.hash");
        $st->execute([(int)$cfg['progressao_season_id'], $teamId]);
        $tem = (int)$st->fetchColumn();
        $base['modo'] = 'letras';
        $base['tem'] = $tem;
        $base['bloqueado'] = $tem < $precisa;
        return $base;
    }

    require_once __DIR__ . '/stats_temporada.php';
    $alvo = statsTemporadaAlvo($pdo, $liga)['alvo'] ?? null;
    if (!$alvo) return $base;   // sem temporada pra cobrar: não há o que exigir

    $st = $pdo->prepare("SELECT COUNT(DISTINCT player_id) FROM player_season_stats
                          WHERE team_id = ? AND season_id = ? AND games > 0");
    $st->execute([$teamId, (int)$alvo['id']]);
    $tem = (int)$st->fetchColumn();
    $base['modo'] = 'estatisticas';
    $base['tem'] = $tem;
    $base['temporada'] = (int)($alvo['season_number'] ?? 0) ?: null;
    $base['bloqueado'] = $tem < $precisa;
    return $base;
}

/** A frase que o GM lê quando a troca é barrada. */
function travaElencoMensagem(string $nomeTime, array $e): string
{
    $falta = max(0, (int)$e['precisa'] - (int)$e['tem']);
    if (($e['modo'] ?? '') === 'letras') {
        return "{$nomeTime} ainda não atualizou as letras depois da progression: "
             . "{$e['tem']} de {$e['precisa']} jogadores atualizados (faltam {$falta}). "
             . 'Trocas só liberam depois disso.';
    }
    $t = !empty($e['temporada']) ? " da T{$e['temporada']}" : '';
    return "{$nomeTime} ainda não lançou as estatísticas{$t}: "
         . "{$e['tem']} de {$e['precisa']} jogadores com estatística (faltam {$falta}). "
         . 'Trocas só liberam depois disso.';
}

/**
 * O ponto de entrada das trocas: algum dos times está barrado?
 * Devolve a mensagem de erro, ou null se estiver liberado.
 */
function travaElencoBloqueio(PDO $pdo, array $teamIds, ?string $liga): ?string
{
    if (!$liga) return null;
    try {
        $cfg = travaElencoConfig($pdo, $liga);
        if (empty($cfg['ativa'])) return null;
        foreach ($teamIds as $tid) {
            $tid = (int)$tid;
            if ($tid <= 0) continue;
            $e = travaElencoEstadoDoTime($pdo, $tid, $liga, $cfg);
            if (!$e['bloqueado']) continue;
            $st = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(city,''),' ',COALESCE(name,''))) FROM teams WHERE id = ?");
            $st->execute([$tid]);
            return travaElencoMensagem((string)($st->fetchColumn() ?: 'Um dos times'), $e);
        }
    } catch (Throwable $ex) {
        error_log('[trava-elenco] bloqueio: ' . $ex->getMessage());
    }
    return null;
}

/**
 * Os times da liga com o estado de cada um — a lista do painel do admin.
 *
 * @return list<array{id:int,nome:string,modo:?string,tem:int,precisa:int,bloqueado:bool}>
 */
function travaElencoTimesDaLiga(PDO $pdo, string $liga): array
{
    $cfg = travaElencoConfig($pdo, $liga);
    // O painel mostra o estado MESMO com a chave desligada ("quem ficaria
    // travado se eu ligasse agora") — por isso avalia como se estivesse ligada.
    $cfgAvaliar = $cfg; $cfgAvaliar['ativa'] = 1;

    $st = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(city,''),' ',COALESCE(name,''))) AS nome
                           FROM teams WHERE league = ? AND user_id IS NOT NULL ORDER BY nome");
    $st->execute([strtoupper(trim($liga))]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $e = travaElencoEstadoDoTime($pdo, (int)$t['id'], $liga, $cfgAvaliar);
        $out[] = ['id' => (int)$t['id'], 'nome' => (string)$t['nome'], 'modo' => $e['modo'],
                  'tem' => $e['tem'], 'precisa' => $e['precisa'], 'bloqueado' => $e['bloqueado']];
    }
    return $out;
}
