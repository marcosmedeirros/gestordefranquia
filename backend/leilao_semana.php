<?php
/**
 * O LEILÃO DO JOGO DA SEMANA.
 *
 * Os dois times que mais pagam têm o jogo deles escolhido como o jogo da
 * semana — o que vai pra live. Cada time dá um lance em FBA Points, e os
 * dois maiores ficam no pódio: é o confronto entre eles que acontece.
 *
 * ── O dinheiro ─────────────────────────────────────────────────────────
 *
 * Os pontos saem do saldo na hora do lance, mas só ficam retidos de quem
 * está no TOP 2. Caiu pra terceiro, o valor volta inteiro e na hora.
 *
 * Reter de todo mundo seria emprestar dinheiro pra liga sem prazo de volta;
 * não reter de ninguém deixaria dar lance de 50 mil sem ter, e o leilão
 * viraria um blefe. Reter só de quem está ganhando é o que faz o lance ser
 * um compromisso de verdade e ainda devolve pra quem perdeu.
 *
 * ── Um leilão por temporada ────────────────────────────────────────────
 *
 * A identidade do leilão é (liga, temporada). Quando a liga vira a
 * temporada, o season_number muda e nasce um leilão novo, vazio, sozinho —
 * sem ninguém precisar apertar "resetar" e sem risco de alguém esquecer.
 * O leilão antigo fica gravado como estava.
 */

require_once __DIR__ . '/db.php';

/**
 * O lance mínimo quando ninguém deu lance ainda.
 *
 * 150 e não um valor simbólico: o jogo da semana é a vitrine da liga, e um
 * leilão que abre em 5 seria decidido por quem clicou primeiro, não por
 * quem quer estar lá.
 */
const LEILAO_SEMANA_MINIMO = 150;

/**
 * Quanto é preciso passar do segundo colocado pra tomar a vaga.
 *
 * Continua 5, e de propósito: o piso alto já filtra quem entra, e um passo
 * grande transformaria a disputa em saltos de centenas — dois times
 * interessados gastariam muito mais do que a vaga vale só pra revezar.
 */
const LEILAO_SEMANA_PASSO = 5;

/** Quantos ficam no pódio — é um jogo, então são dois times. */
const LEILAO_SEMANA_VAGAS = 2;

function leilaoSemanaTabela(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;

    // Um lance por time em cada leilão: aumentar a oferta é atualizar a
    // linha, não empilhar outra. Sem a chave única, dois cliques rápidos
    // criariam dois lances do mesmo time e o pódio teria o time duas vezes.
    $pdo->exec("CREATE TABLE IF NOT EXISTS leilao_semana_lances (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        league     VARCHAR(10) NOT NULL,
        temporada  INT NOT NULL,
        team_id    INT NOT NULL,
        user_id    INT NOT NULL,
        valor      INT NOT NULL,
        retido     INT NOT NULL DEFAULT 0,
        criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_lance (league, temporada, team_id),
        KEY idx_leilao (league, temporada, valor)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * A temporada corrente de uma liga — é o que separa um leilão do outro.
 *
 * Sem temporada aberta devolve 0, e aí o leilão daquela liga fica
 * indisponível em vez de se misturar com o da temporada passada.
 */
function leilaoSemanaTemporada(PDO $pdo, string $liga): int
{
    $st = $pdo->prepare("SELECT season_number FROM seasons
                          WHERE league = ? AND status NOT IN ('finalizado','completed')
                          ORDER BY season_number DESC LIMIT 1");
    $st->execute([strtoupper($liga)]);
    return (int)$st->fetchColumn();
}

/**
 * Os lances de um leilão, do maior pro menor.
 *
 * O desempate é por QUEM CHEGOU ANTES: dois lances de 100, quem ofereceu
 * primeiro fica na frente. Desempatar pelo id (ou por nada) faria a ordem
 * mudar sozinha quando alguém editasse a oferta sem mudar o valor.
 */
function leilaoSemanaLances(PDO $pdo, string $liga, int $temporada): array
{
    leilaoSemanaTabela($pdo);
    if ($temporada <= 0) return [];

    $st = $pdo->prepare("SELECT l.*, TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS time_nome,
                                t.photo_url AS logo
                           FROM leilao_semana_lances l
                           JOIN teams t ON t.id = l.team_id
                          WHERE l.league = ? AND l.temporada = ?
                          ORDER BY l.valor DESC, l.criado_em ASC, l.id ASC");
    $st->execute([strtoupper($liga), $temporada]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Quanto custa entrar no pódio agora.
 *
 * É o lance da ÚLTIMA vaga mais o passo — não o do líder. Pra entrar num
 * pódio de dois basta passar o segundo; exigir passar o primeiro tornaria
 * a segunda vaga inalcançável enquanto o líder estivesse muito na frente.
 */
function leilaoSemanaMinimo(array $lances, int $ignorarTeam = 0): int
{
    // Ninguém deu lance: vale o piso.
    if (!$lances) return LEILAO_SEMANA_MINIMO;

    // Com UM lance só, o pódio ainda tem vaga — mas o mínimo continua sendo
    // aquele lance mais o passo. Antes eu devolvia o piso aqui, e o segundo
    // time podia repetir os mesmos 150: dois lances iguais, e o desempate
    // caindo em quem clicou primeiro, que é exatamente o que o leilão
    // deveria evitar.
    $ultimoDoPodio = $lances[min(count($lances), LEILAO_SEMANA_VAGAS) - 1]['valor'] ?? 0;
    $minimo = max(LEILAO_SEMANA_MINIMO, (int)$ultimoDoPodio + LEILAO_SEMANA_PASSO);

    // O passo pode cair em cima de um lance que já existe — com 160, 155 e
    // 150 na mesa, o segundo do pódio mais cinco dá justamente os 160 do
    // líder. Anunciar esse valor seria mandar a pessoa num lance que a
    // própria trava de empate recusaria, então sobe até um degrau livre.
    while (leilaoSemanaValorOcupado($lances, $minimo, $ignorarTeam) !== null) {
        $minimo += LEILAO_SEMANA_PASSO;
    }

    return $minimo;
}

/** Esse valor já é o lance de alguém? */
function leilaoSemanaValorOcupado(array $lances, int $valor, int $ignorarTeam = 0): ?string
{
    foreach ($lances as $l) {
        if ((int)$l['team_id'] === $ignorarTeam) continue;
        if ((int)$l['valor'] === $valor) return (string)$l['time_nome'];
    }
    return null;
}

/**
 * Acerta o dinheiro depois de qualquer mexida no leilão.
 *
 * Retém de quem está no pódio e devolve a quem não está. Roda por completo
 * a cada lance, em vez de tentar adivinhar quem mudou de posição: a conta é
 * curta (são poucos lances) e assim não existe estado intermediário errado
 * — quem entrou paga e quem saiu recebe no mesmo instante.
 *
 * @return array{devolvidos:int}
 */
function leilaoSemanaAcertarContas(PDO $pdo, string $liga, int $temporada): array
{
    $lances = leilaoSemanaLances($pdo, $liga, $temporada);
    $devolvidos = 0;

    $creditar = $pdo->prepare("UPDATE games_usuarios SET fba_points = COALESCE(fba_points,0) + ? WHERE id = ?");
    $debitar  = $pdo->prepare("UPDATE games_usuarios SET fba_points = COALESCE(fba_points,0) - ? WHERE id = ?");
    $marcar   = $pdo->prepare("UPDATE leilao_semana_lances SET retido = ? WHERE id = ?");

    foreach ($lances as $i => $l) {
        $deveRetter = $i < LEILAO_SEMANA_VAGAS ? (int)$l['valor'] : 0;
        $retidoAgora = (int)$l['retido'];
        if ($deveRetter === $retidoAgora) continue;

        $dif = $deveRetter - $retidoAgora;
        if ($dif > 0) {
            $debitar->execute([$dif, (int)$l['user_id']]);
        } else {
            $creditar->execute([-$dif, (int)$l['user_id']]);
            $devolvidos++;
        }
        $marcar->execute([$deveRetter, (int)$l['id']]);
    }
    return ['devolvidos' => $devolvidos];
}

/**
 * Dá ou aumenta o lance de um time.
 *
 * @return array{ok:bool, erro:?string, minimo:int}
 */
function leilaoSemanaOfertar(PDO $pdo, int $userId, int $teamId, string $liga, int $valor): array
{
    leilaoSemanaTabela($pdo);
    $liga = strtoupper(trim($liga));
    $temporada = leilaoSemanaTemporada($pdo, $liga);

    $falha = fn(string $e, int $m = 0) => ['ok' => false, 'erro' => $e, 'minimo' => $m];

    if ($temporada <= 0) return $falha('A ' . $liga . ' não tem temporada em andamento.');

    // O time tem que ser MESMO de quem está pedindo, e da liga do leilão.
    $st = $pdo->prepare("SELECT id, league, user_id FROM teams WHERE id = ?");
    $st->execute([$teamId]);
    $time = $st->fetch(PDO::FETCH_ASSOC);
    if (!$time)                                    return $falha('Time não encontrado.');
    if ((int)$time['user_id'] !== $userId)         return $falha('Esse time não é seu.');
    if (strtoupper((string)$time['league']) !== $liga) return $falha('Você só dá lance na sua liga.');

    $lances = leilaoSemanaLances($pdo, $liga, $temporada);
    $minimo = leilaoSemanaMinimo($lances, $teamId);

    // O meu lance atual não conta contra mim: se eu JÁ estou no pódio, o
    // mínimo pra me manter é o que os outros pedem, não o meu próprio valor
    // mais cinco — senão eu teria que superar a mim mesmo pra continuar.
    $meuAtual = 0;
    foreach ($lances as $l) if ((int)$l['team_id'] === $teamId) { $meuAtual = (int)$l['valor']; break; }

    if ($valor < $minimo) {
        return $falha("O lance mínimo agora é {$minimo} FBA Points.", $minimo);
    }
    if ($valor <= $meuAtual) {
        return $falha("Você já ofereceu {$meuAtual}. O novo lance precisa ser maior.", $minimo);
    }

    // Dois times com o mesmo valor deixariam a vaga sendo decidida por quem
    // clicou primeiro. O leilão é de lance, não de reflexo: cada valor é de
    // um time só, e quem chega depois sobe.
    $dono = leilaoSemanaValorOcupado($lances, $valor, $teamId);
    if ($dono !== null) {
        $acima = $valor + LEILAO_SEMANA_PASSO;
        return $falha("{$dono} já ofereceu {$valor}. Dois lances não podem empatar — tente {$acima}.", max($minimo, $acima));
    }

    // Só o que FALTA sai do bolso: quem já tem 100 retidos e vai pra 150
    // paga 50. Cobrar os 150 de novo tiraria dinheiro que a liga já segura.
    //
    // Quem debita de fato é o acerto de contas lá embaixo; esta conta existe
    // pra RECUSAR antes de gravar quando o saldo não cobre — deixar o acerto
    // debitar sem checar deixaria o saldo negativo.
    $aPagar = $valor - leilaoSemanaRetidoDoTime($lances, $teamId);

    try {
        $pdo->beginTransaction();

        $saldo = (int)$pdo->query("SELECT COALESCE(fba_points,0) FROM games_usuarios WHERE id = " . (int)$userId)
                          ->fetchColumn();
        if ($saldo < $aPagar) {
            $pdo->rollBack();
            return $falha('Você tem ' . $saldo . ' FBA Points — faltam ' . ($aPagar - $saldo) . '.', $minimo);
        }

        $pdo->prepare("INSERT INTO leilao_semana_lances (league, temporada, team_id, user_id, valor, retido)
                       VALUES (?,?,?,?,?,0)
                       ON DUPLICATE KEY UPDATE valor = VALUES(valor), user_id = VALUES(user_id)")
            ->execute([$liga, $temporada, $teamId, $userId, $valor]);

        // O acerto de contas é quem debita e devolve — inclusive o meu
        // próprio lance, que aqui ainda está com `retido` antigo.
        leilaoSemanaAcertarContas($pdo, $liga, $temporada);

        $pdo->commit();
        return ['ok' => true, 'erro' => null, 'minimo' => leilaoSemanaMinimo(leilaoSemanaLances($pdo, $liga, $temporada))];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[leilao-semana] ofertar: ' . $e->getMessage());
        return $falha('Não deu pra registrar o lance agora.', $minimo);
    }
}

/**
 * O jogo da semana, pro grupo — é o /jogosemana.
 *
 * Usa o nome CURTO do time ("Coyotes", e não "Utah Coyotes"): no grupo todo
 * mundo chama assim, e o nome completo faria a linha do confronto quebrar
 * em duas no celular.
 */
function leilaoSemanaTexto(PDO $pdo, string $liga): string
{
    $liga = strtoupper(trim($liga));
    $temporada = leilaoSemanaTemporada($pdo, $liga);

    if ($temporada <= 0) return "A *{$liga}* não tem temporada em andamento.";

    $lances = leilaoSemanaLances($pdo, $liga, $temporada);
    $l = ['🏀 *JOGO DA SEMANA — ' . $liga . '*', ''];

    if (!$lances) {
        $l[] = 'Ninguém deu lance ainda.';
        $l[] = '';
        $l[] = 'O primeiro lance define o jogo. Mínimo: *'
             . LEILAO_SEMANA_MINIMO . '* FBA Points, na loja do /games.';
        return implode("\n", $l);
    }

    // O nome curto vem do teams.name, sem a cidade.
    $curto = function ($teamId) use ($pdo) {
        $st = $pdo->prepare("SELECT name FROM teams WHERE id = ?");
        $st->execute([$teamId]);
        return (string)($st->fetchColumn() ?: '?');
    };

    $a = $lances[0];
    $b = $lances[1] ?? null;

    $l[] = 'Jogo atualmente: *' . $curto((int)$a['team_id'])
         . ' × ' . ($b ? $curto((int)$b['team_id']) : '(vaga aberta)') . '*';
    $l[] = '';
    $l[] = 'Lance atual: *' . number_format((int)$a['valor'], 0, ',', '.') . '*'
         . ($b ? ' e *' . number_format((int)$b['valor'], 0, ',', '.') . '*' : '');

    // O que ENTRAR custa, e não só o que já foi dado: é a informação que faz
    // alguém abrir a loja. Sem ela, o time que quer entrar tem que adivinhar.
    $l[] = 'Pra tomar a vaga: *' . number_format(leilaoSemanaMinimo($lances), 0, ',', '.')
         . '* FBA Points';

    if (count($lances) > LEILAO_SEMANA_VAGAS) {
        $fila = array_slice($lances, LEILAO_SEMANA_VAGAS);
        $l[] = '';
        $l[] = '_Na fila: ' . implode(', ', array_map(
            fn($f) => $curto((int)$f['team_id']) . ' (' . number_format((int)$f['valor'], 0, ',', '.') . ')',
            array_slice($fila, 0, 4)
        )) . (count($fila) > 4 ? ' e mais ' . (count($fila) - 4) : '') . '_';
    }

    return implode("\n", $l);
}

/** Quanto já está retido de um time, pra cobrar só a diferença. */
function leilaoSemanaRetidoDoTime(array $lances, int $teamId): int
{
    foreach ($lances as $l) if ((int)$l['team_id'] === $teamId) return (int)$l['retido'];
    return 0;
}

/**
 * O leilão de uma liga, pronto pra tela.
 *
 * @return array{liga:string, temporada:int, podio:array, fila:array, minimo:int, meu:?array}
 */
function leilaoSemanaDaLiga(PDO $pdo, string $liga, int $userId = 0): array
{
    $liga = strtoupper(trim($liga));
    $temporada = leilaoSemanaTemporada($pdo, $liga);
    $lances = leilaoSemanaLances($pdo, $liga, $temporada);

    $meu = null;
    foreach ($lances as $i => $l) {
        if ($userId > 0 && (int)$l['user_id'] === $userId) {
            $meu = $l + ['posicao' => $i + 1, 'no_podio' => $i < LEILAO_SEMANA_VAGAS];
            break;
        }
    }

    return [
        'liga'      => $liga,
        'temporada' => $temporada,
        'podio'     => array_slice($lances, 0, LEILAO_SEMANA_VAGAS),
        'fila'      => array_slice($lances, LEILAO_SEMANA_VAGAS),
        // Pra quem já tem lance, o mínimo mostrado tem que ser um valor que o
        // próprio time consiga dar: ignorar o meu valor no cálculo evita que
        // eu tenha que passar de mim mesmo, mas o campo ainda não pode sugerir
        // o número que eu já ofereci.
        'minimo'    => $meu
            ? max(leilaoSemanaMinimo($lances, (int)$meu['team_id']), (int)$meu['valor'] + LEILAO_SEMANA_PASSO)
            : leilaoSemanaMinimo($lances),
        'meu'       => $meu,
    ];
}
