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
 * Quantos jogadores do time têm ESTATÍSTICA lançada na temporada.
 *
 * Uma conta só, usada pela trava e pelo /atualizados — duas contagens de
 * "quem lançou stats" acabariam discordando no dia em que uma mudasse.
 */
function travaElencoContaStats(PDO $pdo, int $teamId, int $seasonId): int
{
    if ($teamId <= 0 || $seasonId <= 0) return 0;
    $st = $pdo->prepare("SELECT COUNT(DISTINCT player_id) FROM player_season_stats
                          WHERE team_id = ? AND season_id = ? AND games > 0");
    $st->execute([$teamId, $seasonId]);
    return (int)$st->fetchColumn();
}

/**
 * Quantos jogadores do time tiveram LETRA (ou OVR) mexida desde a foto da
 * progression. Sem foto daquela temporada a resposta é 0 — e quem chama
 * precisa saber distinguir isso de "ninguém atualizou" (@see travaElencoDosDoisPontos).
 */
function travaElencoContaLetras(PDO $pdo, int $teamId, int $seasonId): int
{
    if ($teamId <= 0 || $seasonId <= 0) return 0;
    $st = $pdo->prepare("SELECT COUNT(*) FROM players p
                           JOIN elenco_progressao_base b ON b.player_id = p.id AND b.season_id = ?
                          WHERE p.team_id = ? AND " . TRAVA_ELENCO_HASH_SQL . " <> b.hash");
    $st->execute([$seasonId, $teamId]);
    return (int)$st->fetchColumn();
}

/**
 * O que a liga cobra agora, resolvido UMA vez.
 *
 * Quem é a temporada alvo das estatísticas e se existe foto de progression
 * valendo são perguntas da LIGA, não do time — e eram refeitas a cada time na
 * lista de 32. Aqui elas saem uma vez e viajam no contexto.
 *
 * @return array{precisa:int,season_stats:int,season_letras:int,temporada:?int}
 */
function travaElencoContexto(PDO $pdo, string $liga, ?array $cfg = null): array
{
    $liga = strtoupper(trim($liga));
    $cfg = $cfg ?? travaElencoConfig($pdo, $liga);

    require_once __DIR__ . '/stats_temporada.php';
    $alvo = statsTemporadaAlvo($pdo, $liga)['alvo'] ?? null;

    /* A foto vale pra temporada em que foi tirada. Virou a temporada, ela
       perde a validade sozinha e as letras saem da conta até o admin clicar
       "Saiu a progression" de novo. */
    $temp = temporadaAtivaDaLiga($pdo, $liga);

    return [
        'precisa'      => (int)($cfg['min_jogadores'] ?: TRAVA_ELENCO_MIN_PADRAO),
        'season_stats' => (int)($alvo['id'] ?? 0),
        'season_letras'=> travaElencoEmProgressao($cfg, $temp) ? (int)$cfg['progressao_season_id'] : 0,
        'temporada'    => (int)($alvo['season_number'] ?? 0) ?: null,
    ];
}

/**
 * OS DOIS PONTOS DE UM TIME: estatística e letra.
 *
 * PRA TROCAR, O TIME PRECISA DOS DOIS (decisão do Marcos, 16/11/2026). Antes
 * a trava cobrava um ou outro conforme o momento da temporada, e quem lançava
 * as estatísticas e não mexia nas letras seguia trocando devendo metade.
 *
 * `cobra` é o que torna o ponto exigível: sem temporada alvo não há
 * estatística a cobrar, e sem a foto da progression não há como saber quem
 * mexeu nas letras. Ponto que não se cobra sai como 'ok' — não se barra
 * ninguém por não ter feito o que ainda não foi pedido.
 *
 * @return array{stats:array{tem:int,ok:bool,cobra:bool,temporada:?int},
 *               letras:array{tem:int,ok:bool,cobra:bool}}
 */
function travaElencoPontos(PDO $pdo, int $teamId, array $ctx): array
{
    $precisa = (int)$ctx['precisa'];
    $cobraS  = (int)$ctx['season_stats']  > 0;
    $cobraL  = (int)$ctx['season_letras'] > 0;

    $temS = $cobraS ? travaElencoContaStats($pdo, $teamId, (int)$ctx['season_stats'])   : 0;
    $temL = $cobraL ? travaElencoContaLetras($pdo, $teamId, (int)$ctx['season_letras']) : 0;

    return [
        'stats'  => ['tem' => $temS, 'ok' => !$cobraS || $temS >= $precisa,
                     'cobra' => $cobraS, 'temporada' => $ctx['temporada'] ?? null],
        'letras' => ['tem' => $temL, 'ok' => !$cobraL || $temL >= $precisa, 'cobra' => $cobraL],
    ];
}

/**
 * Como está um time diante da trava.
 *
 * @return array{ativa:bool,bloqueado:bool,modo:?string,tem:int,precisa:int,mensagem:?string,
 *               temporada:?int,stats:array,letras:array}
 */
function travaElencoEstadoDoTime(PDO $pdo, int $teamId, string $liga, ?array $cfg = null): array
{
    $liga = strtoupper(trim($liga));
    $cfg = $cfg ?? travaElencoConfig($pdo, $liga);
    $base = ['ativa' => !empty($cfg['ativa']), 'bloqueado' => false, 'modo' => null,
             'tem' => 0, 'precisa' => (int)$cfg['min_jogadores'], 'mensagem' => null,
             'temporada' => null,
             'stats' => ['tem' => 0, 'ok' => true, 'cobra' => false, 'temporada' => null],
             'letras' => ['tem' => 0, 'ok' => true, 'cobra' => false]];
    if (!$base['ativa'] || $teamId <= 0) return $base;

    $ctx = travaElencoContexto($pdo, $liga, $cfg);
    $p   = travaElencoPontos($pdo, $teamId, $ctx);

    $base['precisa']   = $ctx['precisa'];
    $base['stats']     = $p['stats'];
    $base['letras']    = $p['letras'];
    $base['temporada'] = $p['stats']['temporada'];
    $base['bloqueado'] = !$p['stats']['ok'] || !$p['letras']['ok'];

    /* `modo`/`tem` existem desde quando a trava cobrava UM ponto só, e quem
       lê (pendências, simulador, painel) ainda conta com eles. Apontam pro
       primeiro ponto em falta — a estatística na frente, porque é o trabalho
       maior; quem quer o quadro inteiro lê `stats` e `letras`. */
    if (!$p['stats']['ok']) {
        $base['modo'] = 'estatisticas';
        $base['tem']  = $p['stats']['tem'];
    } elseif (!$p['letras']['ok']) {
        $base['modo'] = 'letras';
        $base['tem']  = $p['letras']['tem'];
    }
    return $base;
}

/** A frase que o GM lê quando a troca é barrada — diz os DOIS pontos em falta. */
function travaElencoMensagem(string $nomeTime, array $e): string
{
    $precisa = (int)$e['precisa'];
    $faltas  = [];

    if (empty($e['stats']['ok'])) {
        $tem = (int)($e['stats']['tem'] ?? 0);
        $t   = !empty($e['stats']['temporada']) ? " da T{$e['stats']['temporada']}" : '';
        $faltas[] = "lançar as estatísticas{$t} ({$tem} de {$precisa}, faltam " . max(0, $precisa - $tem) . ')';
    }
    if (empty($e['letras']['ok'])) {
        $tem = (int)($e['letras']['tem'] ?? 0);
        $faltas[] = "atualizar as letras depois da progression ({$tem} de {$precisa}, faltam "
                  . max(0, $precisa - $tem) . ')';
    }

    /* Nenhum dos dois nomeado: estado antigo ou inesperado. Melhor uma frase
       genérica do que "{$nomeTime} ainda precisa ." */
    if (!$faltas) return "{$nomeTime} ainda não atualizou o elenco. Trocas só liberam depois disso.";

    return "{$nomeTime} ainda precisa " . implode(' e ', $faltas) . '. Trocas só liberam depois disso.';
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
 * OS DOIS PONTOS DE UMA VEZ: estatística E letra, time por time.
 *
 * A TRAVA COBRA UM DOS DOIS, conforme o momento da temporada (@see
 * travaElencoEstadoDoTime) — é ela que decide quem pode trocar, e isso não
 * muda. Mas a pergunta do grupo é outra: "quem está devendo?". Quem lançou as
 * estatísticas e não mexeu nas letras deve as letras do mesmo jeito, e mostrar
 * só o que a trava cobra hoje esconde metade da dívida. (Pedido do Marcos,
 * 16/11/2026, para o /atualizados.)
 *
 * AS LETRAS SÓ EXISTEM DEPOIS DA FOTO. Sem o clique de "Saiu a progression"
 * nesta temporada não há com o que comparar, e aí `letras.disponivel` é false
 * — que é diferente de "ninguém atualizou". Quem mostra a lista tem que dizer
 * qual dos dois casos é, senão a liga inteira aparece devendo letra que ainda
 * nem foi distribuída.
 *
 * @return list<array{id:int,nome:string,precisa:int,
 *                    stats:array{tem:int,ok:bool,temporada:?int},
 *                    letras:array{tem:int,ok:bool,disponivel:bool}}>
 */
function travaElencoDosDoisPontos(PDO $pdo, string $liga): array
{
    $liga = strtoupper(trim($liga));
    $ctx  = travaElencoContexto($pdo, $liga);

    $st = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(city,''),' ',COALESCE(name,''))) AS nome
                           FROM teams WHERE league = ? AND user_id IS NOT NULL ORDER BY nome");
    $st->execute([$liga]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $p = travaElencoPontos($pdo, (int)$t['id'], $ctx);
        $out[] = [
            'id' => (int)$t['id'], 'nome' => (string)$t['nome'], 'precisa' => (int)$ctx['precisa'],
            'stats'  => $p['stats'],
            /* `disponivel` é o nome que o /atualizados usa pro mesmo que
               `cobra`: se dá pra medir este ponto hoje. */
            'letras' => $p['letras'] + ['disponivel' => $p['letras']['cobra']],
        ];
    }
    return $out;
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

    /* O contexto é da liga: resolvido uma vez, e não 32. */
    $ctx = travaElencoContexto($pdo, $liga, $cfgAvaliar);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $p = travaElencoPontos($pdo, (int)$t['id'], $ctx);
        $modo = !$p['stats']['ok'] ? 'estatisticas' : (!$p['letras']['ok'] ? 'letras' : null);
        $out[] = [
            'id' => (int)$t['id'], 'nome' => (string)$t['nome'],
            'modo' => $modo,
            // `tem` aponta pro ponto de `modo` — compatibilidade com quem
            // desenhava "x/6" antes de a trava cobrar os dois.
            'tem' => $modo === 'letras' ? $p['letras']['tem'] : $p['stats']['tem'],
            'precisa' => (int)$ctx['precisa'],
            'bloqueado' => !$p['stats']['ok'] || !$p['letras']['ok'],
            'stats' => $p['stats'], 'letras' => $p['letras'],
        ];
    }
    return $out;
}
