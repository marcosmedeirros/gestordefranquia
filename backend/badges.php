<?php
/**
 * BADGES: O TIER E O AVISO NO GRUPO.
 *
 * O SISTEMA NÃO SABE O TIER, e não tem como saber: badge é coisa do 2K, e lá
 * o mesmo "Clamps" sai Silver num jogador e Hall of Fame noutro. Quem sabe é
 * quem acabou de aplicar no jogo — por isso o tier é escolhido na hora em que
 * a organização dá o OK no pedido (@see api/tapas.php, admin_approve), e não
 * no pedido do GM.
 *
 * Isto mora fora da api porque o aviso é texto, e texto se confere: api/
 * tapas.php morre com 401 em linha de comando, então um teste que quisesse
 * ver a mensagem sem mandar pro grupo não teria como chegar nela.
 */

/**
 * As cinco escadas do 2K.
 *
 * As chaves são as mesmas de legends-draft-badges.php e de js/admin.js
 * (TAPAS_TIERS), pra não existirem dois vocabulários de tier no projeto.
 */
function badgeTiers(): array
{
    return [
        'bronze' => ['nome' => 'Bronze',       'emoji' => '🥉'],
        'silver' => ['nome' => 'Silver',       'emoji' => '🥈'],
        'gold'   => ['nome' => 'Gold',         'emoji' => '🥇'],
        'hof'    => ['nome' => 'Hall of Fame', 'emoji' => '🏆'],
        'legend' => ['nome' => 'Legend',       'emoji' => '👑'],
    ];
}

/**
 * A chave do tier, ou '' se não for um dos cinco.
 *
 * Aceita o que a liga fala em português e o que o 2K escreve em inglês: a
 * tela manda a chave certa, mas um dia isto vai receber "ouro" por outro
 * caminho — do bot, de um script — e entender não custa nada.
 */
function badgeTierNormalizar(?string $tier): string
{
    $t = mb_strtolower(trim((string)$tier));
    $apelidos = [
        'prata' => 'silver', 'ouro' => 'gold', 'hall da fama' => 'hof',
        'hall of fame' => 'hof', 'lenda' => 'legend',
    ];
    $t = $apelidos[$t] ?? $t;
    return isset(badgeTiers()[$t]) ? $t : '';
}

/** "🥇 Gold" — vazio quando não é um dos cinco (badge aplicada antes desta tela). */
function badgeTierRotulo(?string $tier): string
{
    $t = badgeTierNormalizar($tier);
    if ($t === '') return '';
    $d = badgeTiers()[$t];
    return $d['emoji'] . ' ' . $d['nome'];
}

/**
 * O TEXTO DO AVISO, que antes a organização escrevia à mão no grupo.
 *
 * Separado do envio de propósito: assim dá pra ler a mensagem num script sem
 * mandar nada pra ninguém.
 */
function badgeTextoDoAviso(PDO $pdo, array $req, string $badgeName, string $tier): string
{
    require_once __DIR__ . '/whatsapp.php';

    $st = $pdo->prepare('SELECT name, league FROM teams WHERE id = ? LIMIT 1');
    $st->execute([(int)($req['team_id'] ?? 0)]);
    $time = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $stP = $pdo->prepare('SELECT name, ovr, age FROM players WHERE id = ? LIMIT 1');
    $stP->execute([(int)($req['player_id'] ?? 0)]);
    $jog = $stP->fetch(PDO::FETCH_ASSOC) ?: [];

    /* A LIGA SAI DO TIME, e não do pedido: badge pedida antes de o GM mudar de
       liga levaria a cor errada. É a mesma regra de tapasLigaDoPedido(). */
    $liga = (string)($time['league'] ?? $req['league'] ?? '');
    $nome = trim((string)($jog['name'] ?? $req['player_name'] ?? ''));

    /* SÓ O APELIDO DO TIME, sem a cidade: no Gameplay da liga todo mundo
       chama de "Biebers", não de "Austin Biebers". */
    $doTime = trim((string)($time['name'] ?? ''));

    /* "(83/23y)" — o OVR e a idade, que é o que se pergunta sobre o jogador
       que acabou de ganhar badge. Sem idade cadastrada fica só o OVR. */
    $ovr   = (int)($jog['ovr'] ?? 0);
    $idade = (int)($jog['age'] ?? 0);
    $ficha = $ovr > 0 ? ($idade > 0 ? $ovr . '/' . $idade . 'y' : (string)$ovr)
                      : ($idade > 0 ? $idade . 'y' : '');

    $rotuloTier = badgeTierRotulo($tier);

    /* A COR DA LIGA ABRE E FECHA A PRIMEIRA LINHA. Faz o papel que a tag
       [FBA NEXT] fazia no The Pathetic: no Gameplay da liga o nome dela é
       redundante, mas a bolinha ainda diz de bate-olho que é da FBA. */
    $emoji = whatsappEmojiDaLiga($liga) ?? '🏅';

    return $emoji . ' ' . trim($badgeName)
         . ($rotuloTier !== '' ? '  ·  *' . $rotuloTier . '*' : '')
         . ' ' . $emoji . "\n\n"
         . '*' . $nome . '*'
         . ($ficha !== '' ? ' (' . $ficha . ')' : '')
         . ($doTime !== '' ? ' - ' . $doTime : '');
}

/**
 * O GAMEPLAY DA LIGA — é lá que o aviso de badge tem que cair.
 *
 * Badge aplicada é assunto de jogo, e cada liga tem o seu Gameplay. Não serve
 * league_settings.whatsapp_group_id: aquele campo é o Chat Off (é o que o
 * leilão lê, @see leilaoBotGrupoDaLiga) e está vazio nas quatro ligas.
 *
 * A fonte é whatsapp_grupos_comando, onde a liga de cada grupo foi DECLARADA
 * por quem liberou o bot ali — informação dada, não adivinhada. O `LIKE
 * '%gameplay%'` é o que escolhe entre os grupos daquela liga (cada uma tem
 * Chat Off, Trade Block, ADM e Gameplay).
 *
 * Sem Gameplay registrado, devolve null e o aviso vai pro grupo principal —
 * ele leva a tag da liga, então não fica órfão.
 */
function badgeGrupoDoGameplay(PDO $pdo, string $liga): ?string
{
    $liga = strtoupper(trim($liga));
    if ($liga === '') return null;

    try {
        $st = $pdo->prepare("SELECT jid FROM whatsapp_grupos_comando
                              WHERE ativo = 1 AND UPPER(liga) = ? AND LOWER(nome) LIKE '%gameplay%'
                           ORDER BY criado_em ASC, jid ASC LIMIT 1");
        $st->execute([$liga]);
        $jid = trim((string)($st->fetchColumn() ?: ''));
        if ($jid !== '') return $jid;
    } catch (Throwable $e) { error_log('[badges/grupo] ' . $e->getMessage()); }

    /* PLANO B: o nome do grupo entre os que o bot já viu. Vale pro Gameplay
       que ainda não foi liberado pro bot — ele aparece em
       whatsapp_grupos_vistos do mesmo jeito. Grupo renomeado deixa de casar,
       e aí o certo é liberar o bot nele. */
    try {
        $st = $pdo->prepare("SELECT jid FROM whatsapp_grupos_vistos
                              WHERE LOWER(nome) LIKE '%gameplay%' AND LOWER(nome) LIKE ?
                           ORDER BY visto_em DESC LIMIT 1");
        $st->execute(['%' . mb_strtolower($liga) . '%']);
        $jid = trim((string)($st->fetchColumn() ?: ''));
        if ($jid !== '') return $jid;
    } catch (Throwable $e) { error_log('[badges/grupo visto] ' . $e->getMessage()); }

    return null;
}

/**
 * Manda o aviso pro Gameplay da liga.
 *
 * NUNCA DERRUBA A APROVAÇÃO: a badge já foi aplicada no jogo e já está gravada
 * no banco. Se o WhatsApp estiver fora do ar, o certo é o pedido continuar
 * aprovado e o aviso se perder — não o contrário.
 */
function badgeAnunciarNoGrupo(PDO $pdo, array $req, string $badgeName, string $tier): void
{
    try {
        require_once __DIR__ . '/whatsapp.php';
        if (!whatsappAtivo($pdo)) return;

        $texto = badgeTextoDoAviso($pdo, $req, $badgeName, $tier);

        /* A liga sai do TIME, igual ao texto — badge pedida antes de o GM
           mudar de liga iria parar no Gameplay errado. */
        $st = $pdo->prepare('SELECT league FROM teams WHERE id = ? LIMIT 1');
        $st->execute([(int)($req['team_id'] ?? 0)]);
        $liga = (string)($st->fetchColumn() ?: ($req['league'] ?? ''));

        $grupo = badgeGrupoDoGameplay($pdo, $liga);
        if ($grupo === null) { whatsappParaGrupoPrincipal($pdo, $texto, 'badge'); return; }

        whatsappEnfileirar($pdo, $grupo, $texto, true, 'badge');
        whatsappEsvaziarUmaVez($pdo);
    } catch (Throwable $e) {
        error_log('[badges/aviso] ' . $e->getMessage());
    }
}
