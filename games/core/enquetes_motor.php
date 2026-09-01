<?php
/**
 * ENQUETES — cada um monta a sua aposta e banca ela.
 *
 * Quem cria é a CASA daquela enquete: define as alternativas e as odds, e
 * responde com o próprio saldo. Se a alternativa que venceu tinha apostas,
 * ele paga o lucro de quem acertou; tudo que foi apostado nas outras é dele.
 * A casa da liga fica com 5% do que ele lucrar — e nada quando ele perde.
 *
 * ── POR QUE ISSO NÃO QUEBRA A ECONOMIA ──────────────────────────────────
 *
 * Um criador que não tem como pagar é o único jeito de este sistema imprimir
 * moeda. Três travas impedem isso, e todas moram aqui e não na tela:
 *
 *   1. RETENÇÃO. A cada aposta o motor recalcula quanto o criador perde no
 *      pior resultado possível, e trava esse valor no saldo dele. Moeda
 *      retida não gasta em nada.
 *   2. RECUSA. A aposta que faria a retenção passar do saldo do criador não
 *      entra. É por isso que a conta fecha em qualquer cenário: o pior caso
 *      já está separado antes de o resultado existir.
 *   3. TETOS. Odd entre 1,10 e 10,00, um máximo por pessoa e um total da
 *      enquete que o criador escolhe — e que o motor confere contra o saldo
 *      dele na hora de criar.
 *
 * ── A CONTA ─────────────────────────────────────────────────────────────
 *
 * Odd é o retorno cheio: quem aposta 100 numa odd 2,5 recebe 250 de volta, e
 * o LUCRO dele (o que sai do criador) é 150 — a aposta em si é dinheiro que
 * já estava com a casa.
 *
 *   Se a alternativa X vencer:
 *     criador recebe   = tudo que foi apostado nas OUTRAS alternativas
 *     criador paga     = soma, em X, de aposta × (odd − 1)
 *     resultado        = recebe − paga
 *
 *   Exposição = o pior desses resultados entre todas as alternativas. É o que
 *   fica retido (só quando é prejuízo; lucro não precisa de garantia).
 *
 * ── AS ODDS QUE SE MEXEM ────────────────────────────────────────────────
 *
 * A odd de cada alternativa cai conforme entra dinheiro nela e sobe nas
 * outras — como numa casa de verdade, pra desestimular que todo mundo empurre
 * o mesmo lado. Mas ela é TRAVADA no instante da aposta: o que a pessoa viu é
 * o que ela recebe. O ajuste protege o criador do que ainda vai entrar, não
 * do que já entrou; quem protege o que já entrou é a retenção.
 */

const ENQ_TAXA_CASA      = 5;      // % sobre o LUCRO do criador, só quando ele ganha
const ENQ_ODD_MIN        = 1.10;   // abaixo disso é tomar dinheiro de quem aposta
const ENQ_ODD_MAX        = 10.00;  // acima disso uma aposta pequena quebra o criador
const ENQ_ALT_MIN        = 2;
/**
 * Quatro, não seis.
 *
 * Com 5 ou mais alternativas iguais, a PRIMEIRA aposta já joga todas as
 * outras em 10.00 — colam no teto e param de responder ao dinheiro que
 * entra depois. Com 4, o pior caso chega a 8.89 e a mesa continua viva.
 */
const ENQ_ALT_MAX        = 4;
const ENQ_APOSTA_MIN     = 10;
const ENQ_DIAS_MAX       = 30;     // enquete não segura moeda retida pra sempre
/**
 * O quanto o fluxo mexe na odd (0 = odd fixa, 1 = mexe muito).
 *
 * 0.45 veio de medir 400 sessões de aposta em cada tamanho de mesa, do fluxo
 * espalhado ao "todo mundo numa opção só". Com ele a odd muda no máximo uns
 * 18% de uma aposta pra outra: dá pra ver o mercado se mexendo sem que a
 * odd vire outra coisa enquanto a pessoa decide.
 */
const ENQ_SENSIBILIDADE  = 0.45;
/**
 * O lastro: a partir de quantas moedas o fluxo merece ser levado a sério.
 *
 * Sem isso o motor lê só PROPORÇÃO, e a primeira aposta da enquete — de
 * dez moedas ou de dez mil — leva a odd direto pro extremo, porque com uma
 * aposta só a proporção é 100%. Com o lastro, o fluxo pesa
 * total/(total+lastro): dez moedas quase não mexem, mil mexem de verdade.
 */
const ENQ_LASTRO         = 600;

function enqTabelas(PDO $pdo): void
{
    static $ok = false;
    if ($ok) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS enquetes (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        criador_id    INT NOT NULL,
        titulo        VARCHAR(160) NOT NULL,
        descricao     VARCHAR(400) NULL,
        status        ENUM('aberta','fechada','paga','cancelada') NOT NULL DEFAULT 'aberta',
        max_por_pessoa INT NOT NULL DEFAULT 200,
        max_total     INT NOT NULL DEFAULT 1000,
        retido        INT NOT NULL DEFAULT 0,
        vencedora_id  INT NULL,
        resultado_por INT NULL,
        criado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        fecha_em      DATETIME NULL,
        pago_em       DATETIME NULL,
        KEY idx_enq_status (status),
        KEY idx_enq_criador (criador_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* A categoria entrou depois. Aposta antiga fica sem, e cai no grupo
       "Outras" — que é exatamente onde ela estava antes de haver grupos. */
    try {
        $pdo->exec("ALTER TABLE enquetes ADD COLUMN categoria VARCHAR(40) NULL AFTER descricao");
    } catch (Throwable $e) { /* já existe */ }

    $pdo->exec("CREATE TABLE IF NOT EXISTS enquete_alternativas (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        enquete_id  INT NOT NULL,
        texto       VARCHAR(120) NOT NULL,
        odd_inicial DECIMAL(5,2) NOT NULL,
        ordem       TINYINT NOT NULL DEFAULT 0,
        KEY idx_ea_enq (enquete_id),
        CONSTRAINT fk_ea_enq FOREIGN KEY (enquete_id) REFERENCES enquetes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* A odd fica GRAVADA na aposta. Ela muda a cada aposta nova, e sem
       guardar aqui não haveria como pagar o que a pessoa viu na tela. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS enquete_apostas (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        enquete_id   INT NOT NULL,
        alternativa_id INT NOT NULL,
        id_usuario   INT NOT NULL,
        valor        INT NOT NULL,
        odd          DECIMAL(5,2) NOT NULL,
        pago         TINYINT(1) NOT NULL DEFAULT 0,
        criado_em    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ap_enq (enquete_id),
        KEY idx_ap_user (id_usuario),
        CONSTRAINT fk_ap_enq FOREIGN KEY (enquete_id) REFERENCES enquetes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* Tudo que mexeu em moeda por causa de enquete. É o que permite auditar
       um resultado contestado e reverter sem adivinhar. */
    $pdo->exec("CREATE TABLE IF NOT EXISTS enquete_extrato (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        enquete_id INT NOT NULL,
        id_usuario INT NOT NULL,
        valor      INT NOT NULL,
        motivo     VARCHAR(60) NOT NULL,
        criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ex_enq (enquete_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ok = true;
}

/** Saldo de moedas de uma conta do FBA Games. */
function enqSaldo(PDO $pdo, int $uid): int
{
    $st = $pdo->prepare("SELECT COALESCE(pontos,0) FROM games_usuarios WHERE id = ?");
    $st->execute([$uid]);
    return (int)($st->fetchColumn() ?: 0);
}

/**
 * Quanto o criador tem preso em enquetes abertas.
 *
 * Ele pode bancar várias ao mesmo tempo, e cada uma segura o próprio pior
 * caso. O que ele tem livre pra bancar mais uma é o saldo menos isto.
 */
function enqRetidoTotal(PDO $pdo, int $uid, int $ignorarEnquete = 0): int
{
    $sql = "SELECT COALESCE(SUM(retido),0) FROM enquetes
             WHERE criador_id = ? AND status IN ('aberta','fechada')";
    $args = [$uid];
    if ($ignorarEnquete > 0) { $sql .= " AND id <> ?"; $args[] = $ignorarEnquete; }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return (int)$st->fetchColumn();
}

/** O que sobra pro criador bancar uma enquete nova. */
function enqSaldoLivre(PDO $pdo, int $uid, int $ignorarEnquete = 0): int
{
    return max(0, enqSaldo($pdo, $uid) - enqRetidoTotal($pdo, $uid, $ignorarEnquete));
}

/**
 * As apostas de uma enquete, somadas por alternativa.
 *
 * @return array{total:int, porAlt:array<int,int>, lucroPorAlt:array<int,int>}
 *   `lucroPorAlt` é o que o criador PAGA se aquela alternativa vencer.
 */
function enqSomas(PDO $pdo, int $enqId): array
{
    $st = $pdo->prepare("SELECT alternativa_id, valor, odd FROM enquete_apostas WHERE enquete_id = ?");
    $st->execute([$enqId]);
    $total = 0; $porAlt = []; $lucro = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $alt = (int)$a['alternativa_id'];
        $v   = (int)$a['valor'];
        $total += $v;
        $porAlt[$alt] = ($porAlt[$alt] ?? 0) + $v;
        // Lucro do apostador = o que o criador tira do bolso dele.
        $lucro[$alt] = ($lucro[$alt] ?? 0) + (int)round($v * ((float)$a['odd'] - 1));
    }
    return ['total' => $total, 'porAlt' => $porAlt, 'lucroPorAlt' => $lucro];
}

/**
 * O pior resultado possível pro criador, em moedas.
 *
 * Zero quer dizer que ele não perde em nenhum cenário — acontece quando o
 * dinheiro está espalhado o bastante entre as alternativas.
 */
function enqExposicao(array $somas, array $altIds): int
{
    $pior = 0;
    foreach ($altIds as $id) {
        $paga   = $somas['lucroPorAlt'][$id] ?? 0;
        $recebe = $somas['total'] - ($somas['porAlt'][$id] ?? 0);
        $resultado = $recebe - $paga;          // negativo = prejuízo
        if ($resultado < 0) $pior = max($pior, -$resultado);
    }
    return $pior;
}

/**
 * A odd de cada alternativa AGORA, já com o efeito do dinheiro que entrou.
 *
 * A conta é feita em probabilidade, não na odd direto: a odd inicial vira uma
 * probabilidade implícita (1/odd), ela é puxada na direção de quanto daquela
 * alternativa já foi apostado, e o resultado volta pra odd. Fazer isso na odd
 * crua dava salto feio nos extremos.
 *
 * @return array<int,float> alternativa_id => odd
 */
function enqOddsAtuais(array $alternativas, array $somas): array
{
    $probIni = [];
    foreach ($alternativas as $a) {
        $odd = max(ENQ_ODD_MIN, (float)$a['odd_inicial']);
        $probIni[(int)$a['id']] = 1 / $odd;
    }
    $somaIni = array_sum($probIni) ?: 1;

    /*
     * MESA GRANDE CHACOALHA MAIS — então a sensibilidade encolhe junto.
     *
     * Com a mesma sensibilidade, o salto da odd entre duas apostas era 31%
     * numa mesa de 2 opções e 43% numa de 4: quanto mais alternativas, menos
     * dinheiro cada uma segura e mais o mercado balança. Dividindo por
     * nAlt/2, as três mesas passam a se mover igual (17,6% / 16,7% / 18,2%).
     */
    $nAlt = max(2, count($alternativas));
    $sens = ENQ_SENSIBILIDADE * (2 / $nAlt);

    $total = $somas['total'];
    $out = [];
    foreach ($alternativas as $a) {
        $id = (int)$a['id'];
        $base = $probIni[$id] / $somaIni;                    // normalizada
        // Sem aposta nenhuma a odd é a que o criador escreveu.
        if ($total <= 0) { $out[$id] = round(1 / ($base * $somaIni), 2); continue; }

        $fluxo = ($somas['porAlt'][$id] ?? 0) / $total;      // 0..1
        // Quanto vale a opinião desse fluxo: pouco dinheiro, pouco peso.
        $peso  = $total / ($total + ENQ_LASTRO);
        $nova  = $base + $sens * $peso * ($fluxo - $base);
        $nova  = max(0.02, min(0.95, $nova));
        // De volta pra odd, mantendo a margem que o criador embutiu.
        $odd = (1 / $nova) * (1 / $somaIni);
        $out[$id] = round(max(ENQ_ODD_MIN, min(ENQ_ODD_MAX, $odd)), 2);
    }
    return $out;
}

/** Anota no extrato e mexe no saldo. Valor negativo é débito. */
function enqMoeda(PDO $pdo, int $enqId, int $uid, int $valor, string $motivo): void
{
    if ($valor !== 0) {
        $pdo->prepare("UPDATE games_usuarios SET pontos = COALESCE(pontos,0) + ? WHERE id = ?")
            ->execute([$valor, $uid]);
    }
    $pdo->prepare("INSERT INTO enquete_extrato (enquete_id, id_usuario, valor, motivo) VALUES (?,?,?,?)")
        ->execute([$enqId, $uid, $valor, mb_substr($motivo, 0, 60)]);
}

/**
 * Cria a enquete e confere a garantia inicial.
 *
 * O `max_total` é a promessa do criador: até esse valor de apostas ele
 * aguenta. O motor confere contra o saldo LIVRE dele — o que já está preso em
 * outras enquetes dele não pode ser contado duas vezes.
 */
function enqCriar(PDO $pdo, int $uid, array $dados): array
{
    enqTabelas($pdo);
    $erro = fn(string $m) => ['ok' => false, 'erro' => $m];

    $titulo = mb_substr(trim((string)($dados['titulo'] ?? '')), 0, 160);
    if ($titulo === '') return $erro('Escreva a pergunta da enquete.');

    $alts = array_values(array_filter(
        (array)($dados['alternativas'] ?? []),
        fn($a) => trim((string)($a['texto'] ?? '')) !== ''
    ));
    if (count($alts) < ENQ_ALT_MIN) return $erro('A enquete precisa de pelo menos ' . ENQ_ALT_MIN . ' alternativas.');
    if (count($alts) > ENQ_ALT_MAX) return $erro('No máximo ' . ENQ_ALT_MAX . ' alternativas.');

    foreach ($alts as $a) {
        $odd = (float)($a['odd'] ?? 0);
        if ($odd < ENQ_ODD_MIN || $odd > ENQ_ODD_MAX) {
            return $erro('As odds precisam ficar entre ' . number_format(ENQ_ODD_MIN, 2, ',', '')
                       . ' e ' . number_format(ENQ_ODD_MAX, 2, ',', '') . '.');
        }
    }

    $maxPessoa = max(ENQ_APOSTA_MIN, (int)($dados['max_por_pessoa'] ?? 0));
    $maxTotal  = max($maxPessoa, (int)($dados['max_total'] ?? 0));
    $dias      = max(1, min(ENQ_DIAS_MAX, (int)($dados['dias'] ?? 7)));

    /*
     * A GARANTIA DE CRIAR.
     *
     * O pior caso de uma enquete é todo o dinheiro cair na alternativa de
     * maior odd: o criador paga max_total × (odd − 1) e não recebe nada das
     * outras. É essa conta que ele precisa ter no bolso ANTES de abrir, senão
     * a enquete nasce com uma promessa que o saldo não cobre.
     */
    $maiorOdd = max(array_map(fn($a) => (float)$a['odd'], $alts));
    $piorCaso = (int)ceil($maxTotal * ($maiorOdd - 1));
    $livre = enqSaldoLivre($pdo, $uid);
    if ($piorCaso > $livre) {
        return $erro('Pra bancar ' . $maxTotal . ' moedas em apostas com odd de até '
            . number_format($maiorOdd, 2, ',', '') . ', você precisa de ' . $piorCaso
            . ' moedas livres. Você tem ' . $livre . '.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO enquetes
                        (criador_id, titulo, descricao, categoria, max_por_pessoa, max_total, fecha_em)
                       VALUES (?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY))")
            ->execute([$uid, $titulo, mb_substr(trim((string)($dados['descricao'] ?? '')), 0, 400) ?: null,
                       mb_substr(trim((string)($dados['categoria'] ?? '')), 0, 40) ?: null,
                       $maxPessoa, $maxTotal, $dias]);
        $id = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare("INSERT INTO enquete_alternativas (enquete_id, texto, odd_inicial, ordem) VALUES (?,?,?,?)");
        foreach ($alts as $i => $a) {
            $ins->execute([$id, mb_substr(trim((string)$a['texto']), 0, 120), (float)$a['odd'], $i]);
        }
        $pdo->commit();
        return ['ok' => true, 'id' => $id, 'pior_caso' => $piorCaso];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[enquetes] criar: ' . $e->getMessage());
        return $erro('Não deu pra criar a enquete.');
    }
}

/**
 * Registra uma aposta — e é aqui que a economia é protegida.
 *
 * A ordem importa: a odd é travada, a exposição é recalculada COM a aposta
 * dentro, e só então se pergunta se o criador aguenta. Aposta que ele não
 * cobre é recusada; não existe "aceita pela metade".
 */
function enqApostar(PDO $pdo, int $uid, int $enqId, int $altId, int $valor): array
{
    enqTabelas($pdo);
    $erro = fn(string $m) => ['ok' => false, 'erro' => $m];

    if ($valor < ENQ_APOSTA_MIN) return $erro('A aposta mínima é de ' . ENQ_APOSTA_MIN . ' moedas.');

    $st = $pdo->prepare("SELECT * FROM enquetes WHERE id = ?");
    $st->execute([$enqId]);
    $enq = $st->fetch(PDO::FETCH_ASSOC);
    if (!$enq)                            return $erro('Enquete não encontrada.');
    if ($enq['status'] !== 'aberta')      return $erro('Esta enquete não está mais recebendo apostas.');
    if ((int)$enq['criador_id'] === $uid) return $erro('Você banca esta enquete — não dá pra apostar nela.');
    if (!empty($enq['fecha_em']) && strtotime($enq['fecha_em']) < time()) {
        return $erro('O prazo desta enquete já passou.');
    }

    $sa = $pdo->prepare("SELECT * FROM enquete_alternativas WHERE enquete_id = ? ORDER BY ordem, id");
    $sa->execute([$enqId]);
    $alts = $sa->fetchAll(PDO::FETCH_ASSOC);
    $altIds = array_map(fn($a) => (int)$a['id'], $alts);
    if (!in_array($altId, $altIds, true)) return $erro('Alternativa inválida.');

    if (enqSaldo($pdo, $uid) < $valor) return $erro('Você não tem essa quantidade de moedas.');

    $somas = enqSomas($pdo, $enqId);
    if ($somas['total'] + $valor > (int)$enq['max_total']) {
        return $erro('Esta enquete aceita no máximo ' . (int)$enq['max_total']
                   . ' moedas no total, e já tem ' . $somas['total'] . '.');
    }

    $sm = $pdo->prepare("SELECT COALESCE(SUM(valor),0) FROM enquete_apostas WHERE enquete_id = ? AND id_usuario = ?");
    $sm->execute([$enqId, $uid]);
    $meu = (int)$sm->fetchColumn();
    if ($meu + $valor > (int)$enq['max_por_pessoa']) {
        return $erro('O limite por pessoa nesta enquete é ' . (int)$enq['max_por_pessoa']
                   . ' moedas, e você já apostou ' . $meu . '.');
    }

    // A odd que a pessoa está vendo agora — é ela que vale, e é ela que fica
    // gravada na aposta.
    $odds = enqOddsAtuais($alts, $somas);
    $odd  = $odds[$altId] ?? null;
    if (!$odd) return $erro('Não deu pra calcular a odd agora. Tente de novo.');

    /* A EXPOSIÇÃO COM ESTA APOSTA DENTRO.
       Simula antes de gravar: se o criador não cobre o pior caso que resulta
       daqui, a aposta não entra. */
    $simulado = $somas;
    $simulado['total'] += $valor;
    $simulado['porAlt'][$altId] = ($simulado['porAlt'][$altId] ?? 0) + $valor;
    $simulado['lucroPorAlt'][$altId] = ($simulado['lucroPorAlt'][$altId] ?? 0) + (int)round($valor * ($odd - 1));
    $novaExposicao = enqExposicao($simulado, $altIds);

    $criador = (int)$enq['criador_id'];
    // Sem contar o que ESTA enquete já retém: ele será substituído pelo novo.
    $livreDoCriador = enqSaldoLivre($pdo, $criador, $enqId);
    if ($novaExposicao > $livreDoCriador) {
        return $erro('Quem banca esta enquete não tem saldo pra cobrir uma aposta desse tamanho agora. '
                   . 'Tente um valor menor.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO enquete_apostas (enquete_id, alternativa_id, id_usuario, valor, odd)
                       VALUES (?,?,?,?,?)")->execute([$enqId, $altId, $uid, $valor, $odd]);
        enqMoeda($pdo, $enqId, $uid, -$valor, 'aposta');
        $pdo->prepare("UPDATE enquetes SET retido = ? WHERE id = ?")->execute([$novaExposicao, $enqId]);
        $pdo->commit();
        return ['ok' => true, 'odd' => $odd, 'retorno' => (int)round($valor * $odd), 'retido' => $novaExposicao];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[enquetes] apostar: ' . $e->getMessage());
        return $erro('Não deu pra registrar a aposta.');
    }
}

/**
 * Fecha a enquete com um resultado e paga todo mundo.
 *
 * A conta, em ordem:
 *   1. quem acertou recebe aposta × odd (a aposta de volta mais o lucro);
 *   2. o criador recebe tudo que foi apostado nas outras alternativas;
 *   3. o criador paga o lucro de quem acertou;
 *   4. se sobrou lucro pro criador, a casa fica com 5% DELE — nunca do bolo,
 *      e nada quando o criador perde.
 *
 * A moeda dos apostadores já saiu do saldo na hora da aposta, então aqui só
 * entra: ninguém é debitado duas vezes.
 */
function enqFechar(PDO $pdo, int $uid, int $enqId, int $altVencedora, bool $ehAdmin = false): array
{
    enqTabelas($pdo);
    $erro = fn(string $m) => ['ok' => false, 'erro' => $m];

    $st = $pdo->prepare("SELECT * FROM enquetes WHERE id = ?");
    $st->execute([$enqId]);
    $enq = $st->fetch(PDO::FETCH_ASSOC);
    if (!$enq)                       return $erro('Enquete não encontrada.');
    if ($enq['status'] === 'paga')   return $erro('Esta enquete já foi paga.');
    if ($enq['status'] === 'cancelada') return $erro('Esta enquete foi cancelada.');
    /*
     * DECLARAR O RESULTADO É SÓ DO DONO — o admin geral também não pode.
     *
     * Quem banca responde pelo resultado com o próprio saldo; um terceiro
     * declarando decide o destino de moeda alheia, e o dono descobre depois.
     * O $ehAdmin fica no parâmetro porque enqCancelar ainda precisa dele: o
     * "reverter pagamento" do admin passa por lá, e é conserto de erro, não
     * decisão sobre quem ganhou.
     */
    if ((int)$enq['criador_id'] !== $uid) {
        return $erro('Só quem banca a aposta declara o resultado.');
    }

    $sa = $pdo->prepare("SELECT * FROM enquete_alternativas WHERE enquete_id = ? ORDER BY ordem, id");
    $sa->execute([$enqId]);
    $alts = $sa->fetchAll(PDO::FETCH_ASSOC);
    if (!in_array($altVencedora, array_map(fn($a) => (int)$a['id'], $alts), true)) {
        return $erro('Escolha uma das alternativas da enquete.');
    }

    $sap = $pdo->prepare("SELECT * FROM enquete_apostas WHERE enquete_id = ?");
    $sap->execute([$enqId]);
    $apostas = $sap->fetchAll(PDO::FETCH_ASSOC);

    $criador = (int)$enq['criador_id'];
    $pdo->beginTransaction();
    try {
        $pagouAosGanhadores = 0;   // só o LUCRO, o que sai do criador
        $devolvido = 0;            // as apostas dos ganhadores, que voltam
        $doCriador = 0;            // o que os perdedores deixaram

        foreach ($apostas as $a) {
            $valor = (int)$a['valor'];
            if ((int)$a['alternativa_id'] === $altVencedora) {
                $retorno = (int)round($valor * (float)$a['odd']);
                enqMoeda($pdo, $enqId, (int)$a['id_usuario'], $retorno, 'ganhou');
                $devolvido += $valor;
                $pagouAosGanhadores += ($retorno - $valor);
            } else {
                $doCriador += $valor;
            }
            $pdo->prepare("UPDATE enquete_apostas SET pago = 1 WHERE id = ?")->execute([(int)$a['id']]);
        }

        // O resultado do criador nesta enquete.
        $liquido = $doCriador - $pagouAosGanhadores;
        $taxa = 0;
        if ($liquido > 0) {
            // A casa só entra quando ele ganha, e sobre o ganho.
            $taxa = (int)floor($liquido * ENQ_TAXA_CASA / 100);
            $liquido -= $taxa;
        }
        if ($liquido !== 0) {
            enqMoeda($pdo, $enqId, $criador, $liquido, $liquido > 0 ? 'lucro da banca' : 'prejuízo da banca');
        }
        if ($taxa > 0) {
            // A taxa não vai pra ninguém: sai de circulação, que é o único
            // dreno de moeda que este sistema tem.
            $pdo->prepare("INSERT INTO enquete_extrato (enquete_id, id_usuario, valor, motivo) VALUES (?,0,?,?)")
                ->execute([$enqId, -$taxa, 'taxa da casa (' . ENQ_TAXA_CASA . '%)']);
        }

        $pdo->prepare("UPDATE enquetes SET status = 'paga', vencedora_id = ?, resultado_por = ?,
                              retido = 0, pago_em = NOW() WHERE id = ?")
            ->execute([$altVencedora, $uid, $enqId]);
        $pdo->commit();

        return ['ok' => true, 'pago_aos_ganhadores' => $pagouAosGanhadores + $devolvido,
                'lucro_do_criador' => $liquido, 'taxa' => $taxa];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[enquetes] fechar: ' . $e->getMessage());
        return $erro('Não deu pra fechar a enquete.');
    }
}

/**
 * Cancela e devolve tudo.
 *
 * Serve pro evento que não aconteceu e pro resultado contestado: como cada
 * aposta está no extrato, devolver é somar de volta exatamente o que saiu.
 * A retenção do criador cai junto — a moeda dele volta a ser dele.
 */
function enqCancelar(PDO $pdo, int $uid, int $enqId, bool $ehAdmin = false): array
{
    enqTabelas($pdo);
    $erro = fn(string $m) => ['ok' => false, 'erro' => $m];

    $st = $pdo->prepare("SELECT * FROM enquetes WHERE id = ?");
    $st->execute([$enqId]);
    $enq = $st->fetch(PDO::FETCH_ASSOC);
    if (!$enq)                          return $erro('Enquete não encontrada.');
    if ($enq['status'] === 'cancelada') return $erro('Esta enquete já foi cancelada.');
    if ((int)$enq['criador_id'] !== $uid && !$ehAdmin) {
        return $erro('Só quem banca a enquete pode cancelar.');
    }
    // Depois de paga, só admin desfaz — é a correção de um resultado errado.
    if ($enq['status'] === 'paga' && !$ehAdmin) {
        return $erro('Esta enquete já foi paga. Fale com o admin pra reverter.');
    }

    $pdo->beginTransaction();
    try {
        if ($enq['status'] === 'paga') {
            /* REVERTER UMA ENQUETE PAGA.
               Desfaz pelo extrato, e não recalculando: o extrato é o que de
               fato aconteceu, inclusive se alguma regra mudou no meio. */
            $ex = $pdo->prepare("SELECT id_usuario, valor FROM enquete_extrato
                                  WHERE enquete_id = ? AND motivo <> 'aposta'");
            $ex->execute([$enqId]);
            foreach ($ex->fetchAll(PDO::FETCH_ASSOC) as $l) {
                if ((int)$l['id_usuario'] > 0) {
                    enqMoeda($pdo, $enqId, (int)$l['id_usuario'], -(int)$l['valor'], 'estorno do pagamento');
                }
            }
        }

        // As apostas voltam pra quem apostou.
        $sap = $pdo->prepare("SELECT id_usuario, valor FROM enquete_apostas WHERE enquete_id = ?");
        $sap->execute([$enqId]);
        foreach ($sap->fetchAll(PDO::FETCH_ASSOC) as $a) {
            enqMoeda($pdo, $enqId, (int)$a['id_usuario'], (int)$a['valor'], 'aposta devolvida');
        }

        $pdo->prepare("UPDATE enquetes SET status = 'cancelada', retido = 0, vencedora_id = NULL WHERE id = ?")
            ->execute([$enqId]);
        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[enquetes] cancelar: ' . $e->getMessage());
        return $erro('Não deu pra cancelar.');
    }
}
