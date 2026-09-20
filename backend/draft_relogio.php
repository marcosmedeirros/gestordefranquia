<?php
/**
 * O RELÓGIO DO DRAFT — o que faz o draft andar sozinho.
 *
 * O gargalo era humano: o draft abria, e a liga ficava esperando o GM da vez
 * lembrar de escolher. O admin marcava um horário na mão pra ligar o relógio,
 * e quem não estava olhando a tela perdia a vez sem saber que era a vez dele.
 *
 * A regra, agora, é sozinha do começo ao fim:
 *
 *   1. O draft abre e a liga tem 16 HORAS pra escolher com calma, sem relógio.
 *   2. Faltando 4 HORAS o bot avisa no Gameplay que o relógio vem, e a que
 *      horas ele começa.
 *   3. Na hora, o relógio abre e o bot chama o time da vez, marcando o GM.
 *   4. Dali em diante cada time tem 3 MINUTOS. Não escolheu, entra o primeiro
 *      da ordem da classe que ainda estiver disponível — e o bot já chama o
 *      próximo. É uma mensagem por pick, sempre que a vez vira.
 *   5. Acabada a 1ª rodada, a 2ª abre com os 20 MINUTOS de mock de sempre, e o
 *      bot avisa que é hora de montar a lista no app.
 *
 * Os tempos são fixos de propósito: o botão "iniciar agora" resolve o caso da
 * pressa, e um campo por liga seria mais uma tela pra alguém deixar um número
 * estranho e ninguém perceber até o dia do draft.
 *
 * QUEM CHAMA: o cron (cron/draft-autopick.php) e o endereço que o worker do
 * WhatsApp bate a cada poucos segundos. O cron sozinho não bastaria — ele roda
 * de cinco em cinco minutos, e um prazo de três não sobrevive a isso.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/draft_autopick.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/leilao_bot.php';   // botGrupoDaCerimonia()

/** Quanto tempo a liga tem antes de o relógio começar a correr. */
const DRAFT_RELOGIO_ESPERA_HORAS = 16;

/** Com quanto tempo de antecedência o bot avisa que o relógio vem. */
const DRAFT_RELOGIO_AVISO_HORAS = 4;

/** Tipo das mensagens na fila do WhatsApp — serve pro relatório e pro filtro. */
const DRAFT_RELOGIO_TIPO = 'draft';

function draftRelogioColunas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;

    draftAutopickColunas($pdo);

    /* As marcas de "já falei isso". Sem elas o tick, que roda a cada poucos
       segundos, repetiria o mesmo aviso até o prazo virar. `vez_anunciada`
       guarda a pick que já foi chamada: é o que transforma "uma mensagem por
       tick" em "uma mensagem por pick". */
    foreach ([
        'clock_auto_definido_em' => 'DATETIME NULL',
        'clock_manual_off'       => 'DATETIME NULL',
        'clock_aviso_em'         => 'DATETIME NULL',
        'clock_abertura_em'      => 'DATETIME NULL',
        'vez_anunciada'          => 'VARCHAR(20) NULL',
        'round2_anuncio_em'      => 'DATETIME NULL',
    ] as $col => $tipo) {
        try { $pdo->exec("ALTER TABLE draft_sessions ADD COLUMN IF NOT EXISTS {$col} {$tipo}"); }
        catch (Throwable $e) { error_log('[draft-relogio] coluna ' . $col . ': ' . $e->getMessage()); }
    }
}

/** O Gameplay da liga, que é onde o draft acontece pro grupo. */
function draftRelogioGrupo(PDO $pdo, string $liga): ?string
{
    try {
        return botGrupoDaCerimonia($pdo, $liga);
    } catch (Throwable $e) {
        error_log('[draft-relogio] grupo: ' . $e->getMessage());
        return null;
    }
}

/** Manda pro Gameplay da liga. Sem grupo configurado, não faz nada. */
function draftRelogioFalar(PDO $pdo, string $liga, string $texto, ?array $mencoes = null): void
{
    $grupo = draftRelogioGrupo($pdo, $liga);
    if (!$grupo) return;
    whatsappEnfileirar($pdo, $grupo, $texto, true, DRAFT_RELOGIO_TIPO, null, $mencoes);
}

/** "14:30" a partir de um timestamp, no fuso da liga. */
function draftRelogioHora(int $ts): string
{
    return (new DateTimeImmutable('@' . $ts))
        ->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('H:i');
}

/**
 * De quem é a vez agora, com o telefone do GM pra marcação.
 *
 * @return array{pick_id:int,round:int,pick:int,team_id:int,time:string,numero:?string}|null
 */
function draftRelogioVezDe(PDO $pdo, array $sessao): ?array
{
    $st = $pdo->prepare("SELECT o.id, o.round, o.pick_position, o.team_id,
                                TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS time,
                                u.phone
                           FROM draft_order o
                      LEFT JOIN teams t ON t.id = o.team_id
                      LEFT JOIN users u ON u.id = t.user_id
                          WHERE o.draft_session_id = ? AND o.picked_player_id IS NULL
                       ORDER BY o.round ASC, o.pick_position ASC LIMIT 1");
    $st->execute([(int)$sessao['id']]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) return null;

    return [
        'pick_id' => (int)$v['id'],
        'round'   => (int)$v['round'],
        'pick'    => (int)$v['pick_position'],
        'team_id' => (int)$v['team_id'],
        'time'    => trim((string)$v['time']) ?: 'time',
        'numero'  => whatsappNumero($v['phone'] ?? null),
    ];
}

/**
 * Chama o time da vez no grupo, uma vez só por pick.
 *
 * A marca fica em `vez_anunciada` como "rodada-pick": a mesma pick chamada
 * duas vezes é o que aconteceria sem isso, porque o tick roda de segundos em
 * segundos.
 */
function draftRelogioAnunciarVez(PDO $pdo, array $sessao, ?array $vez = null): void
{
    $vez = $vez ?? draftRelogioVezDe($pdo, $sessao);
    if (!$vez || $vez['round'] !== 1) return;

    $marca = $vez['round'] . '-' . $vez['pick'];
    if ((string)($sessao['vez_anunciada'] ?? '') === $marca) return;

    $pdo->prepare('UPDATE draft_sessions SET vez_anunciada = ? WHERE id = ?')
        ->execute([$marca, (int)$sessao['id']]);

    /* UMA LINHA, e só. O aviso sai a cada pick — são trinta por rodada — e o
       texto explicando as regras, repetido trinta vezes, vira parede no grupo.
       Quem precisa da regra já ouviu na abertura; quem está na vez precisa
       saber que é a vez dele, e o resto é ruído. */
    $quem = $vez['numero'] ? ' @' . $vez['numero'] : '';
    $txt  = "⏱️ *Pick {$vez['pick']} · {$vez['time']}*{$quem}";

    draftRelogioFalar($pdo, (string)$sessao['league'], $txt, $vez['numero'] ? [$vez['numero']] : null);
}

/**
 * Uma passada do relógio numa sessão.
 *
 * Cada etapa é independente e idempotente: se o tick morrer no meio, o
 * próximo continua de onde parou em vez de repetir o que já saiu.
 */
function draftRelogioSessao(PDO $pdo, int $sessionId): void
{
    draftRelogioColunas($pdo);

    $st = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ? AND status = "in_progress"');
    $st->execute([$sessionId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return;

    $liga  = (string)$s['league'];
    $agora = time();

    /* 1. O RELÓGIO NASCE SOZINHO, 16 horas depois da abertura.
          Só quando ninguém marcou nada: hora escolhida na mão (ou o botão
          "iniciar agora") manda, e este passo não encosta nela. */
    if (empty($s['round1_clock_start_at']) && empty($s['clock_manual_off']) && !empty($s['started_at'])) {
        /* O PISO DAS 4 HORAS. Um draft que já estava aberto há mais de 16
           horas quando esta regra nasceu tem "started_at + 16h" no passado, e
           sem o piso o relógio abriria no mesmo segundo: a liga levaria o
           anúncio de abertura e o autopick juntos, sem nunca ter ouvido o
           aviso. Foi o que aconteceu na RISE e na ROOKIE em 20/09/2026.

           Ninguém perde a vez por uma regra que entrou em vigor enquanto o
           draft corria: o relógio nunca começa a menos de um aviso de
           distância. */
        $quando = max(
            strtotime((string)$s['started_at']) + DRAFT_RELOGIO_ESPERA_HORAS * 3600,
            $agora + DRAFT_RELOGIO_AVISO_HORAS * 3600
        );
        $pdo->prepare('UPDATE draft_sessions SET round1_clock_start_at = ?, clock_auto_definido_em = NOW()
                        WHERE id = ? AND round1_clock_start_at IS NULL')
            ->execute([date('Y-m-d H:i:s', $quando), $sessionId]);
        $s['round1_clock_start_at'] = date('Y-m-d H:i:s', $quando);
    }
    if (empty($s['round1_clock_start_at'])) return;

    $inicio = strtotime((string)$s['round1_clock_start_at']);
    $round  = (int)$s['current_round'];

    /* 2. O AVISO DAS 4 HORAS. Só faz sentido antes de o relógio abrir — um
          draft que já começou não precisa saber que ia começar. */
    if ($round === 1 && empty($s['clock_aviso_em'])
        && $agora < $inicio && ($inicio - $agora) <= DRAFT_RELOGIO_AVISO_HORAS * 3600) {
        $pdo->prepare('UPDATE draft_sessions SET clock_aviso_em = NOW()
                        WHERE id = ? AND clock_aviso_em IS NULL')->execute([$sessionId]);
        if ($pdo->query('SELECT ROW_COUNT()')->fetchColumn() > 0) {
            $faltam = max(1, (int)round(($inicio - $agora) / 3600));
            draftRelogioFalar($pdo, $liga,
                "⏳ *O relógio do draft começa em {$faltam} horas* — às *"
                . draftRelogioHora($inicio) . "*.\n\n"
                . "Daí em diante cada time tem *3 minutos* pra escolher. "
                . "Quem não escolher leva o primeiro da ordem que estiver livre.\n\n"
                . "_Dá tempo de montar sua lista no app agora._");
        }
    }

    /* 3. A ABERTURA. Uma vez só, e já chamando o primeiro da fila. */
    if ($round === 1 && $agora >= $inicio && empty($s['clock_abertura_em'])) {
        $pdo->prepare('UPDATE draft_sessions SET clock_abertura_em = NOW()
                        WHERE id = ? AND clock_abertura_em IS NULL')->execute([$sessionId]);
        if ($pdo->query('SELECT ROW_COUNT()')->fetchColumn() > 0) {
            draftRelogioFalar($pdo, $liga,
                "🚨 *O relógio do draft começou.*\n\n"
                . "A partir de agora cada time tem *3 minutos* pra escolher. "
                . "Passou, entra o primeiro da ordem que estiver livre e a vez passa adiante.");
        }
        $s['vez_anunciada'] = null;   // a próxima chamada é a primeira de verdade
    }

    /* 4. O AUTOPICK. A regra inteira mora no módulo; aqui só se pergunta se é
          hora. Ele encadeia: várias picks podem sair nesta mesma passada. */
    if ($agora >= $inicio) {
        try {
            draftAutopickSessao($pdo, $sessionId);
        } catch (Throwable $e) {
            error_log('[draft-relogio] autopick: ' . $e->getMessage());
        }
        // Reler: o autopick pode ter mudado a rodada, a pick e até encerrado.
        $st->execute([$sessionId]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) return;
        $round = (int)$s['current_round'];
    }

    /* 5. DE QUEM É A VEZ. Depois do autopick, porque quem escolheu agora já
          saiu da frente — chamar antes marcaria o time errado. */
    if ($round === 1 && $agora >= $inicio) {
        draftRelogioAnunciarVez($pdo, $s);
    }

    /* 6. A 2ª RODADA. O prazo de 20 minutos é gravado pelo próprio fluxo do
          draft (api/draft.php); aqui só se avisa o grupo, uma vez. */
    if ($round >= 2 && empty($s['round2_anuncio_em'])) {
        $pdo->prepare('UPDATE draft_sessions SET round2_anuncio_em = NOW()
                        WHERE id = ? AND round2_anuncio_em IS NULL')->execute([$sessionId]);
        if ($pdo->query('SELECT ROW_COUNT()')->fetchColumn() > 0) {
            $prazo = !empty($s['round2_mock_deadline'])
                ? ' (até ' . draftRelogioHora(strtotime((string)$s['round2_mock_deadline'])) . ')' : '';
            /* Sem ameaça no fim: a 2ª rodada é OPCIONAL. Quem monta o mock
               leva o jogador; quem não monta fica com a vaga em aberto, e o
               sistema não escolhe por ninguém aqui. Dizer o contrário era
               mentir pro grupo — e a mentira ainda cobrava uma pressa que a
               regra não pede. */
            draftRelogioFalar($pdo, $liga,
                "✅ *Acabou a 1ª rodada.*\n\n"
                . "A 2ª rodada tem *20 minutos*{$prazo} — *faça seu mock no app*.\n\n"
                . "_É opcional: só escolhe quem montar a lista._");
        }
    }
}

/**
 * Uma passada em todas as sessões abertas.
 *
 * @return int quantas sessões foram visitadas
 */
function draftRelogioTick(PDO $pdo): int
{
    try {
        draftRelogioColunas($pdo);
        $ids = $pdo->query('SELECT id FROM draft_sessions WHERE status = "in_progress"')
                   ->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        error_log('[draft-relogio] tick: ' . $e->getMessage());
        return 0;
    }

    foreach ($ids as $id) {
        try {
            draftRelogioSessao($pdo, (int)$id);
        } catch (Throwable $e) {
            error_log('[draft-relogio] sessão ' . $id . ': ' . $e->getMessage());
        }
    }
    return count($ids);
}

/**
 * O mesmo tick, mas no máximo uma vez a cada X segundos.
 *
 * É esta que o endereço do worker do WhatsApp chama: ele bate aqui a cada
 * poucos segundos, e o relógio não precisa de tanta pressa — precisa de
 * precisão de minuto, que o cron de cinco em cinco não dá.
 */
function draftRelogioTickThrottled(PDO $pdo, int $segundos = 30): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS draft_relogio_tick (
            id TINYINT PRIMARY KEY,
            rodou_em DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // O UPDATE condicional é o que garante UM tick por janela mesmo com
        // dois processos batendo junto: quem não mudou linha, não roda.
        $pdo->exec("INSERT IGNORE INTO draft_relogio_tick (id, rodou_em) VALUES (1, '2000-01-01 00:00:00')");
        $up = $pdo->prepare("UPDATE draft_relogio_tick SET rodou_em = NOW()
                              WHERE id = 1 AND rodou_em < DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $up->execute([max(5, $segundos)]);
        if ($up->rowCount() === 0) return;
    } catch (Throwable $e) {
        error_log('[draft-relogio] throttle: ' . $e->getMessage());
        return;
    }

    draftRelogioTick($pdo);
}
