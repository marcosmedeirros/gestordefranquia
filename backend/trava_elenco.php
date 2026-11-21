<?php
/**
 * ACOMPANHAMENTO DA ATUALIZAÇÃO DE ELENCO (estatísticas e letras).
 *
 * ISTO NÃO BARRA TROCA NENHUMA. A trava que existia aqui — "sem estatísticas e
 * sem letras atualizadas, sem trocar" — foi TIRADA em 21/11/2026, a pedido do
 * Marcos: qualquer um pode trocar, atualizado ou não. O que sobrou é a CONTA,
 * que diz quem está em dia e quem está devendo:
 *
 *   • o /atualizados do WhatsApp (@see wcAtualizados)
 *   • a lista do painel do admin (@see travaElencoTimesDaLiga)
 *
 * Os nomes das funções seguem com o prefixo `travaElenco` porque é por onde o
 * resto do sistema as chama; nenhuma delas bloqueia coisa alguma.
 *
 * OS DOIS PONTOS DE UM TIME:
 *
 *   ESTATÍSTICAS → lançadas para pelo menos 6 jogadores, na última temporada
 *                  jogada (a mesma que a tela de lançamento usa como alvo —
 *                  statsTemporadaAlvo).
 *
 *   LETRAS       → quando a progression sai, o admin clica "Saiu a
 *                  progression"; a partir daí conta quantos jogadores tiveram
 *                  a letra mexida depois daquele clique.
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
 * UMA FALHA AQUI NUNCA ATRAPALHA NADA: qualquer erro de banco devolve a conta
 * vazia e vai pro log.
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
        /* `ativa` era a chave da trava de troca, que não existe mais
           (21/11/2026). Fica na criação só porque as ligas antigas têm a
           coluna NOT NULL; ninguém lê. */
        ativa                TINYINT(1)  NOT NULL DEFAULT 0,
        min_jogadores        TINYINT     NOT NULL DEFAULT 6,
        progressao_em        DATETIME    NULL,
        progressao_season_id INT         NULL,
        progressao_por       INT         NULL,
        atualizado_por       INT         NULL,
        atualizado_em        TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    /* QUEM marcou a progression fica numa coluna só dela: `atualizado_por` é
       de qualquer mexida na linha, então ele não responde "quem clicou em
       Saiu a progression". */
    try {
        if ($pdo->query("SHOW COLUMNS FROM elenco_trava_liga LIKE 'progressao_por'")->rowCount() === 0) {
            $pdo->exec('ALTER TABLE elenco_trava_liga ADD COLUMN progressao_por INT NULL AFTER progressao_season_id');
        }
    } catch (Throwable $e) { error_log('[trava-elenco] coluna progressao_por: ' . $e->getMessage()); }

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
    $padrao = ['league' => $liga, 'min_jogadores' => TRAVA_ELENCO_MIN_PADRAO,
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

/**
 * O admin clicou "Saiu a progression": tira a foto das letras da liga e passa a
 * contar quem atualizou as suas.
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
        /* ── UMA VEZ POR TEMPORADA, E QUEM CHEGA DEPOIS NÃO REFAZ ────────
         *
         * O segundo clique era DESTRUTIVO sem parecer: ele apagava a foto e
         * tirava outra AGORA, com as letras já atualizadas dentro. Todo time
         * que tinha feito a lição voltava a dever, porque passava a ser
         * comparado consigo mesmo. Com dois admins no painel ao mesmo tempo,
         * bastava os dois clicarem.
         *
         * A TRAVA É O PRÓPRIO UPDATE, não uma leitura antes dele: ler e
         * depois escrever deixa a fresta dos dois cliques simultâneos
         * passarem pela mesma leitura. Quem conseguir mudar a linha ganha o
         * direito de tirar a foto; o outro recebe 'ok' => false e nada é
         * apagado. Refazer de propósito existe — é o botão Desfazer, que
         * limpa a marca e libera um clique novo.
         */
        $pdo->prepare('INSERT IGNORE INTO elenco_trava_liga (league) VALUES (?)')->execute([$liga]);
        $claim = $pdo->prepare('UPDATE elenco_trava_liga
                                   SET progressao_em = NOW(), progressao_season_id = ?, progressao_por = ?
                                 WHERE league = ?
                                   AND (progressao_season_id IS NULL OR progressao_season_id <> ?)');
        $claim->execute([$seasonId, $adminId ?: null, $liga, $seasonId]);

        if ($claim->rowCount() === 0) {
            if ($minha) $pdo->rollBack();
            $quem = travaElencoQuemMarcou($pdo, $liga);
            return ['ok' => false, 'ja_estava' => true,
                    'erro' => 'A progression desta temporada já foi marcada'
                            . ($quem['nome'] !== '' ? ' por ' . $quem['nome'] : '')
                            . ($quem['em'] !== '' ? ' em ' . $quem['em'] : '')
                            . '. Pra refazer, use "Desfazer" antes.'];
        }

        $pdo->prepare('DELETE FROM elenco_progressao_base WHERE season_id = ?')->execute([$seasonId]);
        $ins = $pdo->prepare("INSERT INTO elenco_progressao_base (season_id, player_id, hash)
                              SELECT ?, p.id, " . TRAVA_ELENCO_HASH_SQL . "
                                FROM players p JOIN teams t ON t.id = p.team_id
                               WHERE t.league = ?");
        $ins->execute([$seasonId, $liga]);
        $n = $ins->rowCount();
        if ($minha) $pdo->commit();
        return ['ok' => true, 'jogadores' => $n];
    } catch (Throwable $e) {
        if ($minha && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[trava-elenco] progressão: ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Não deu pra registrar a progression: ' . $e->getMessage()];
    }
}

/** Desfaz o clique: as letras saem da conta até a próxima progression. */
function travaElencoDesfazerProgressao(PDO $pdo, string $liga): void
{
    $liga = strtoupper(trim($liga));
    travaElencoGarantirTabelas($pdo);
    $cfg = travaElencoConfig($pdo, $liga);
    if (!empty($cfg['progressao_season_id'])) {
        $pdo->prepare('DELETE FROM elenco_progressao_base WHERE season_id = ?')->execute([(int)$cfg['progressao_season_id']]);
    }
    // Limpa QUEM marcou junto: sem isso o painel seguiria dizendo que
    // alguém marcou uma progression que não existe mais.
    $pdo->prepare('UPDATE elenco_trava_liga
                      SET progressao_em = NULL, progressao_season_id = NULL, progressao_por = NULL
                    WHERE league = ?')
        ->execute([$liga]);
}

/**
 * QUEM MARCOU A PROGRESSION, E QUANDO — pro painel dizer em vez de o admin
 * descobrir clicando.
 *
 * @return array{nome:string,em:string}
 */
function travaElencoQuemMarcou(PDO $pdo, string $liga): array
{
    try {
        $st = $pdo->prepare("SELECT c.progressao_em, COALESCE(u.name, g.nome, '') AS nome
                               FROM elenco_trava_liga c
                          LEFT JOIN users u ON u.id = c.progressao_por
                          LEFT JOIN games_usuarios g ON g.id = c.progressao_por
                              WHERE c.league = ? LIMIT 1");
        $st->execute([strtoupper(trim($liga))]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'nome' => trim((string)($r['nome'] ?? '')),
            'em'   => !empty($r['progressao_em']) ? date('d/m \à\s H:i', strtotime((string)$r['progressao_em'])) : '',
        ];
    } catch (Throwable $e) {
        return ['nome' => '', 'em' => ''];
    }
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
 * Uma conta só, usada pelo painel e pelo /atualizados — duas contagens de
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
 * O TIME DEVE OS DOIS (decisão do Marcos, 16/11/2026): quem lança as
 * estatísticas e não mexe nas letras está devendo as letras do mesmo jeito.
 *
 * `cobra` é o que torna o ponto exigível: sem temporada alvo não há
 * estatística a cobrar, e sem a foto da progression não há como saber quem
 * mexeu nas letras. Ponto que não se cobra sai como 'ok' — não se cobra
 * ninguém pelo que ainda não foi pedido.
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
 * OS DOIS PONTOS DE UMA VEZ: estatística E letra, time por time.
 *
 * A pergunta do grupo é "quem está devendo?" — e quem lançou as estatísticas
 * e não mexeu nas letras deve as letras do mesmo jeito. (Pedido do Marcos,
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
 * Informação, e nada mais: ninguém fica sem trocar por aparecer aqui.
 *
 * @return list<array{id:int,nome:string,modo:?string,tem:int,precisa:int,em_falta:bool}>
 */
function travaElencoTimesDaLiga(PDO $pdo, string $liga): array
{
    $cfg = travaElencoConfig($pdo, $liga);

    $st = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(city,''),' ',COALESCE(name,''))) AS nome
                           FROM teams WHERE league = ? AND user_id IS NOT NULL ORDER BY nome");
    $st->execute([strtoupper(trim($liga))]);

    /* O contexto é da liga: resolvido uma vez, e não 32. */
    $ctx = travaElencoContexto($pdo, $liga, $cfg);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $p = travaElencoPontos($pdo, (int)$t['id'], $ctx);
        $modo = !$p['stats']['ok'] ? 'estatisticas' : (!$p['letras']['ok'] ? 'letras' : null);
        $out[] = [
            'id' => (int)$t['id'], 'nome' => (string)$t['nome'],
            'modo' => $modo,
            // `tem` aponta pro ponto de `modo` — compatibilidade com quem
            // desenhava "x/6" quando a conta era de um ponto só.
            'tem' => $modo === 'letras' ? $p['letras']['tem'] : $p['stats']['tem'],
            'precisa' => (int)$ctx['precisa'],
            'em_falta' => !$p['stats']['ok'] || !$p['letras']['ok'],
            'stats' => $p['stats'], 'letras' => $p['letras'],
        ];
    }
    return $out;
}
