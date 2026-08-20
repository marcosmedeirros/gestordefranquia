<?php
/**
 * O leilão no WhatsApp: a proposta vai pro dono, ele responde /aceitar ou
 * /recusar, e a liga acompanha pelo Chat Off.
 *
 * Por que uma fila e não um disparo direto: seis propostas chegando de uma
 * vez viram uma parede de texto no celular, e o dono responde "/aceitar" sem
 * saber a qual. Aqui sai UMA por vez, com um código curto, e a próxima só
 * anda quando a atual é respondida — ou quando o tempo dela vence.
 *
 * O relógio do leilão não espera ninguém: os 20 minutos são a janela de
 * PROPOSTAS e correm sozinhos (quem barra proposta atrasada é o data_fim, em
 * enviarProposta). Esta fila é só o ritmo da entrega — depois do prazo ela
 * continua entregando o que já entrou, porque o dono ainda precisa escolher
 * entre o que recebeu.
 *
 * Fora da janela de horário do bot: mensagem de leilão passa. Leilão dura 20
 * minutos e é aberto com o dono ativo — guardar pra amanhã não serviria a
 * ninguém. Ver whatsappFiltroForaDaJanela().
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp.php';

/** Quanto tempo uma proposta fica com a vez antes de passar pra próxima. */
const LEILAO_BOT_VEZ_SEG = 240;

/** Tipo na whatsapp_fila — é o que faz a mensagem furar a janela de horário. */
const LEILAO_BOT_TIPO = 'leilao';

function leilaoBotGarantirTabela(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS leilao_bot_fila (
            id INT AUTO_INCREMENT PRIMARY KEY,
            leilao_id INT NOT NULL,
            proposta_id INT NOT NULL,
            dono_user_id INT NOT NULL,
            codigo VARCHAR(8) NOT NULL,
            status ENUM('aguardando','na_vez','respondida','descartada') NOT NULL DEFAULT 'aguardando',
            enviada_em DATETIME NULL,
            respondida_em DATETIME NULL,
            rodadas INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_proposta (proposta_id),
            KEY idx_dono (dono_user_id, status),
            KEY idx_codigo (codigo, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Throwable $e) {
        error_log('leilaoBotGarantirTabela: ' . $e->getMessage());
    }
    $feito = true;
}

/**
 * Um código curto e sem ambiguidade pro GM digitar.
 *
 * Sem vogais e sem 0/O/1/I: o código é lido do celular e digitado à mão, e
 * "L0" contra "LO" seria erro garantido. Sem vogal também não sai palavra
 * feia por acaso.
 */
function leilaoBotNovoCodigo(PDO $pdo): string
{
    $letras = 'BCDFGHJKMNPQRSTVWXZ';
    $numeros = '23456789';
    for ($tentativa = 0; $tentativa < 40; $tentativa++) {
        $c = $letras[random_int(0, strlen($letras) - 1)]
           . $numeros[random_int(0, strlen($numeros) - 1)]
           . $numeros[random_int(0, strlen($numeros) - 1)];
        $st = $pdo->prepare("SELECT 1 FROM leilao_bot_fila
                             WHERE codigo = ? AND status IN ('aguardando','na_vez') LIMIT 1");
        $st->execute([$c]);
        if (!$st->fetchColumn()) return $c;
    }
    // Improvável, mas melhor um código feio que uma colisão silenciosa.
    return 'X' . random_int(10, 99);
}

/** O dono do jogador leiloado — quem decide. Null em leilão sem vendedor. */
function leilaoBotDonoDoLeilao(PDO $pdo, int $leilao_id): ?array
{
    $st = $pdo->prepare("SELECT l.id, l.team_id, l.league_id, l.data_fim,
                                COALESCE(l.temp_name, p.name) AS jogador,
                                lg.name AS liga,
                                u.id AS user_id, u.name AS gm, u.phone
                         FROM leilao_jogadores l
                         LEFT JOIN players p ON p.id = l.player_id
                         LEFT JOIN leagues lg ON lg.id = l.league_id
                         LEFT JOIN teams t ON t.id = l.team_id
                         LEFT JOIN users u ON u.id = t.user_id
                         WHERE l.id = ?");
    $st->execute([$leilao_id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return ($r && !empty($r['user_id'])) ? $r : null;
}

/** O que a proposta oferece, em texto — serve pro dono e pro grupo. */
function leilaoBotItensDaProposta(PDO $pdo, int $proposta_id): array
{
    $ovrCol = 'ovr';
    try {
        $ovrCol = $pdo->query("SHOW COLUMNS FROM players LIKE 'ovr'")->fetch() ? 'ovr' : 'overall';
    } catch (Throwable $e) {}

    $itens = [];
    $st = $pdo->prepare("SELECT p.name, p.position, p.{$ovrCol} AS ovr
                         FROM leilao_proposta_jogadores lpj JOIN players p ON p.id = lpj.player_id
                         WHERE lpj.proposta_id = ? ORDER BY p.{$ovrCol} DESC");
    $st->execute([$proposta_id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $j) {
        $itens[] = $j['name'] . ' (' . ($j['position'] ?: '?') . ' · ' . ($j['ovr'] ?: '?') . ')';
    }
    $st = $pdo->prepare("SELECT pk.season_year, pk.round, lpp.swap_type
                         FROM leilao_proposta_picks lpp JOIN picks pk ON pk.id = lpp.pick_id
                         WHERE lpp.proposta_id = ? ORDER BY pk.season_year, pk.round");
    $st->execute([$proposta_id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $pk) {
        $itens[] = $pk['round'] . 'ª de ' . $pk['season_year'] . ($pk['swap_type'] ? ' (' . $pk['swap_type'] . ')' : '');
    }

    $extras = [];
    $st = $pdo->prepare("SELECT pl.name, pl.position, pl.{$ovrCol} AS ovr
                         FROM leilao_proposta_extra_players ep JOIN players pl ON pl.id = ep.player_id
                         WHERE ep.proposta_id = ?");
    $st->execute([$proposta_id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $j) {
        $extras[] = $j['name'] . ' (' . ($j['position'] ?: '?') . ' · ' . ($j['ovr'] ?: '?') . ')';
    }
    $st = $pdo->prepare("SELECT pk.season_year, pk.round, ep.swap_type
                         FROM leilao_proposta_extra_picks ep JOIN picks pk ON pk.id = ep.pick_id
                         WHERE ep.proposta_id = ? ORDER BY pk.season_year, pk.round");
    $st->execute([$proposta_id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $pk) {
        $extras[] = $pk['round'] . 'ª de ' . $pk['season_year'] . ($pk['swap_type'] ? ' (' . $pk['swap_type'] . ')' : '');
    }

    $st = $pdo->prepare("SELECT lp.obs, CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')) AS time_nome
                         FROM leilao_propostas lp JOIN teams t ON t.id = lp.team_id WHERE lp.id = ?");
    $st->execute([$proposta_id]);
    $cab = $st->fetch(PDO::FETCH_ASSOC) ?: ['obs' => null, 'time_nome' => '?'];

    return ['time' => trim($cab['time_nome']), 'obs' => $cab['obs'] ?: null,
            'oferece' => $itens, 'pede_extra' => $extras];
}

/** Quantos minutos ainda restam de janela de propostas. Negativo = acabou. */
function leilaoBotMinutosRestantes(?string $dataFim): ?int
{
    if (!$dataFim) return null;
    return (int)floor((strtotime($dataFim) - time()) / 60);
}

/**
 * Põe a proposta na fila do dono e anuncia no Chat Off da liga.
 *
 * Chamado logo depois que a proposta é gravada. Não manda nada pro dono
 * agora: quem entrega é o despachante, que respeita a vez de cada uma.
 */
function leilaoBotAoCriarProposta(PDO $pdo, int $leilao_id, int $proposta_id): void
{
    try {
        leilaoBotGarantirTabela($pdo);
        $dono = leilaoBotDonoDoLeilao($pdo, $leilao_id);
        $itens = leilaoBotItensDaProposta($pdo, $proposta_id);

        if ($dono) {
            $pdo->prepare("INSERT IGNORE INTO leilao_bot_fila
                           (leilao_id, proposta_id, dono_user_id, codigo)
                           VALUES (?, ?, ?, ?)")
                ->execute([$leilao_id, $proposta_id, (int)$dono['user_id'], leilaoBotNovoCodigo($pdo)]);
        }

        leilaoBotAnunciarNoGrupo($pdo, $leilao_id, $itens, $dono);
    } catch (Throwable $e) {
        // O leilão não pode falhar porque o WhatsApp falhou.
        error_log('leilaoBotAoCriarProposta #' . $proposta_id . ': ' . $e->getMessage());
    }
}

/**
 * O JID do Chat Off de uma liga.
 *
 * Primeiro o que o admin configurou em league_settings — é a fonte oficial.
 * Se estiver vazio, procura pelo nome entre os grupos que o bot já viu: os
 * Chat Off se chamam "CHAT OFF | Geral", "CHAT OFF | Next", etc., e exigir
 * que alguém cole um JID à mão só pra ligar o leilão seria um passo a mais
 * pra esquecer.
 *
 * A busca por nome é o plano B, não o principal: um grupo renomeado deixa
 * de casar, e aí o jeito é preencher o campo na Central da Liga.
 */
function leilaoBotGrupoDaLiga(PDO $pdo, string $liga): ?string
{
    $liga = strtoupper(trim($liga));
    try {
        $st = $pdo->prepare("SELECT whatsapp_group_id FROM league_settings WHERE league = ?");
        $st->execute([$liga]);
        $jid = trim((string)($st->fetchColumn() ?: ''));
        if ($jid !== '') return $jid;
    } catch (Throwable $e) {}

    // "CHAT OFF | Geral" é o da ELITE — o nome não repete a liga.
    $apelido = $liga === 'ELITE' ? 'geral' : mb_strtolower($liga);
    try {
        $st = $pdo->prepare("SELECT jid FROM whatsapp_grupos_vistos
                             WHERE LOWER(nome) LIKE '%chat off%'
                               AND LOWER(nome) LIKE ?
                             ORDER BY visto_em DESC LIMIT 1");
        $st->execute(['%' . $apelido . '%']);
        $jid = trim((string)($st->fetchColumn() ?: ''));
        return $jid !== '' ? $jid : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** A proposta aparece no Chat Off da liga — a liga inteira acompanha o leilão. */
function leilaoBotAnunciarNoGrupo(PDO $pdo, int $leilao_id, array $itens, ?array $dono): void
{
    $liga = $dono['liga'] ?? null;
    if (!$liga) {
        $st = $pdo->prepare("SELECT lg.name FROM leilao_jogadores l
                             JOIN leagues lg ON lg.id = l.league_id WHERE l.id = ?");
        $st->execute([$leilao_id]);
        $liga = $st->fetchColumn() ?: null;
    }
    if (!$liga) return;

    $grupo = leilaoBotGrupoDaLiga($pdo, $liga);
    if (!$grupo) return;

    $jogador = $dono['jogador'] ?? '';
    if ($jogador === '') {
        $st = $pdo->prepare("SELECT COALESCE(l.temp_name, p.name) FROM leilao_jogadores l
                             LEFT JOIN players p ON p.id = l.player_id WHERE l.id = ?");
        $st->execute([$leilao_id]);
        $jogador = (string)($st->fetchColumn() ?: 'jogador');
    }

    $faltam = leilaoBotMinutosRestantes($dono['data_fim'] ?? null);
    $txt = "🔨 *Proposta no leilão de {$jogador}*\n"
         . "_{$itens['time']}_ oferece: " . (($itens['oferece']) ? implode(' + ', $itens['oferece']) : 'nada')
         . ($itens['pede_extra'] ? "\ne pede junto: " . implode(' + ', $itens['pede_extra']) : '')
         . ($faltam !== null && $faltam >= 0 ? "\n⏱ {$faltam} min de propostas" : '');

    whatsappEnfileirar($pdo, (string)$grupo, $txt, true, LEILAO_BOT_TIPO);
}

/**
 * Entrega a próxima proposta de cada dono que está sem nenhuma na mão.
 *
 * Roda no mesmo pulso do worker (a cada poucos segundos, em
 * api/whatsapp-bot.php), então não precisa de cron novo.
 *
 * A vez vence em LEILAO_BOT_VEZ_SEG: dono que não respondeu não segura a
 * fila, e a proposta dele volta pro fim — ninguém perde a chance, só perde a
 * ordem.
 */
function leilaoBotDespachar(PDO $pdo): int
{
    try {
        leilaoBotGarantirTabela($pdo);

        // Quem estourou o tempo da vez volta pra fila.
        $pdo->prepare("UPDATE leilao_bot_fila
                       SET status = 'aguardando', enviada_em = NULL, rodadas = rodadas + 1
                       WHERE status = 'na_vez'
                         AND enviada_em IS NOT NULL
                         AND enviada_em < DATE_SUB(NOW(), INTERVAL " . LEILAO_BOT_VEZ_SEG . " SECOND)")
            ->execute();

        // Proposta decidida no app enquanto esperava não é entregue.
        $pdo->prepare("UPDATE leilao_bot_fila f
                       JOIN leilao_propostas lp ON lp.id = f.proposta_id
                       SET f.status = 'descartada'
                       WHERE f.status IN ('aguardando','na_vez') AND lp.status <> 'pendente'")
            ->execute();

        // Donos sem nenhuma proposta na mão agora.
        $livres = $pdo->query("
            SELECT f.dono_user_id, MIN(f.id) AS proximo
            FROM leilao_bot_fila f
            WHERE f.status = 'aguardando'
              AND NOT EXISTS (SELECT 1 FROM leilao_bot_fila g
                              WHERE g.dono_user_id = f.dono_user_id AND g.status = 'na_vez')
            GROUP BY f.dono_user_id")->fetchAll(PDO::FETCH_ASSOC);

        $enviadas = 0;
        foreach ($livres as $l) {
            if (leilaoBotEntregar($pdo, (int)$l['proximo'])) $enviadas++;
        }
        return $enviadas;
    } catch (Throwable $e) {
        error_log('leilaoBotDespachar: ' . $e->getMessage());
        return 0;
    }
}

/** Monta e enfileira a mensagem de uma linha da fila. */
function leilaoBotEntregar(PDO $pdo, int $filaId): bool
{
    $st = $pdo->prepare("SELECT f.*, u.phone, u.name AS gm
                         FROM leilao_bot_fila f JOIN users u ON u.id = f.dono_user_id
                         WHERE f.id = ?");
    $st->execute([$filaId]);
    $linha = $st->fetch(PDO::FETCH_ASSOC);
    if (!$linha) return false;

    $numero = whatsappNumero($linha['phone'] ?? null);
    if (!$numero) {
        // Sem telefone não há como entregar — sai da fila pra não travar as
        // outras propostas do mesmo dono pra sempre.
        $pdo->prepare("UPDATE leilao_bot_fila SET status = 'descartada' WHERE id = ?")->execute([$filaId]);
        return false;
    }

    $dono  = leilaoBotDonoDoLeilao($pdo, (int)$linha['leilao_id']);
    $itens = leilaoBotItensDaProposta($pdo, (int)$linha['proposta_id']);

    $restantes = (int)$pdo->query("SELECT COUNT(*) FROM leilao_bot_fila
                                   WHERE dono_user_id = " . (int)$linha['dono_user_id'] . "
                                     AND status = 'aguardando' AND id <> " . $filaId)->fetchColumn();

    $jogador = $dono['jogador'] ?? 'seu jogador';
    $faltam  = leilaoBotMinutosRestantes($dono['data_fim'] ?? null);
    $cod     = $linha['codigo'];

    $txt = "🔨 *Proposta por {$jogador}*\n\n"
         . "*{$itens['time']}* oferece:\n"
         . ($itens['oferece'] ? '• ' . implode("\n• ", $itens['oferece']) : '• nada')
         . ($itens['pede_extra'] ? "\n\nE quer levar junto:\n• " . implode("\n• ", $itens['pede_extra']) : '')
         . ($itens['obs'] ? "\n\n_\"{$itens['obs']}\"_" : '')
         . "\n\nResponda *`/aceitar {$cod}`* ou *`/recusar {$cod}`*";

    if ($faltam !== null) {
        $txt .= $faltam >= 0
            ? "\n⏱ Ainda entram propostas por {$faltam} min."
            : "\n⏱ A janela de propostas fechou — decida entre as que chegaram.";
    }
    // O número na frente muda a decisão: com mais três na fila, ele pensa
    // duas vezes antes de fechar na primeira.
    $txt .= $restantes > 0
        ? "\n📥 Mais {$restantes} proposta" . ($restantes > 1 ? 's' : '') . " esperando."
        : "\n📥 É a única na fila por enquanto.";

    // Aceitar é escolha provisória (ver aceitarProposta): se ele achar que
    // acabou, desliga o celular e perde a proposta melhor que vem depois.
    $txt .= "\n_Aceitar escolhe por enquanto — se vier melhor, você troca._";

    $ok = whatsappEnfileirar($pdo, $numero, $txt, false, LEILAO_BOT_TIPO, (int)$linha['dono_user_id']);
    if ($ok) {
        $pdo->prepare("UPDATE leilao_bot_fila SET status = 'na_vez', enviada_em = NOW() WHERE id = ?")
            ->execute([$filaId]);
    }
    return $ok;
}

/**
 * Tira a proposta da fila quando ela foi decidida fora do WhatsApp.
 *
 * Sem isto, o dono aceitava no app e o bot mandava a mesma proposta minutos
 * depois pedindo uma decisão que já tinha sido tomada.
 */
function leilaoBotDescartarProposta(PDO $pdo, int $proposta_id): void
{
    try {
        leilaoBotGarantirTabela($pdo);
        $pdo->prepare("UPDATE leilao_bot_fila SET status = 'descartada'
                       WHERE proposta_id = ? AND status IN ('aguardando','na_vez')")
            ->execute([$proposta_id]);
    } catch (Throwable $e) {
        error_log('leilaoBotDescartarProposta: ' . $e->getMessage());
    }
}

/** Fecha a fila inteira de um leilão — ele acabou, não há mais o que decidir. */
function leilaoBotEncerrarLeilao(PDO $pdo, int $leilao_id): void
{
    try {
        leilaoBotGarantirTabela($pdo);
        $pdo->prepare("UPDATE leilao_bot_fila SET status = 'descartada'
                       WHERE leilao_id = ? AND status IN ('aguardando','na_vez')")
            ->execute([$leilao_id]);
    } catch (Throwable $e) {
        error_log('leilaoBotEncerrarLeilao: ' . $e->getMessage());
    }
}
