<?php
/**
 * O BOLÃO DOS GAMES — aposta de tudo ou nada em FBA Points.
 *
 * Ideia do Marcos (17/11/2026): a pessoa crava QUEM CAI e QUEM É CAMPEÃO no
 * Brasileirão, aposta FBA Points e recebe o DOBRO se acertar o cartão inteiro.
 * Errou um item, perde tudo. Não tem meio-termo, não tem prêmio parcial — é o
 * que faz a aposta ter graça e é o que o texto da tela repete até cansar,
 * porque ninguém pode descobrir isso depois de perder 5 mil pontos.
 *
 * ── POR QUE "EDIÇÃO" E NÃO UM BOLÃO FIXO ──────────────────────────────
 *
 * O primeiro é o do Brasileirão, mas a aba se chama Bolão e vai sobreviver a
 * ele. Então a pergunta (quem cai, quem é campeão, quais são os candidatos,
 * quanto paga) é LINHA DE BANCO, não código: o próximo bolão é um INSERT, e
 * não um deploy. A edição ativa é a última não resolvida.
 *
 * ── O DINHEIRO SAI NA HORA DA APOSTA ──────────────────────────────────
 *
 * O débito acontece quando o palpite é registrado, não quando o resultado
 * sai. Sem isso a pessoa apostaria 5 mil, gastaria tudo na loja no dia
 * seguinte e a cobrança cairia num saldo que não existe mais. O débito é
 * conferido DENTRO do UPDATE (`WHERE fba_points >= ?`), como na loja: dois
 * cliques ao mesmo tempo não conseguem gastar o mesmo ponto duas vezes.
 *
 * Trocar o palpite enquanto está aberto mexe só na DIFERENÇA — quem baixa de
 * 5 mil pra 2 mil recebe 3 mil de volta na hora, e quem sobe paga a diferença.
 *
 * @see games.php — a aba, onde isto é desenhado
 */

require_once __DIR__ . '/db.php';

/** Teto de uma aposta, em FBA Points. Fica na edição; isto é só o padrão. */
const BOLAO_APOSTA_MAX = 5000;

/** Quanto o acerto cravado paga, multiplicando o que foi apostado. */
const BOLAO_MULTIPLICADOR = 2;

function bolaoGarantirTabelas(PDO $pdo): void
{
    static $ok = false;
    if ($ok) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bolao_edicoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(120) NOT NULL,
            subtitulo VARCHAR(255) NULL,
            aberto TINYINT(1) NOT NULL DEFAULT 1,
            fecha_em DATETIME NULL,
            aposta_max INT NOT NULL DEFAULT 5000,
            multiplicador TINYINT NOT NULL DEFAULT 2,
            quantos_caem TINYINT NOT NULL DEFAULT 2,
            candidatos_queda TEXT NOT NULL,
            candidatos_titulo TEXT NOT NULL,
            resultado_queda TEXT NULL,
            resultado_campeao VARCHAR(60) NULL,
            resolvido_em DATETIME NULL,
            resolvido_por INT NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS bolao_apostas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            edicao_id INT NOT NULL,
            user_id INT NOT NULL,
            palpite_queda TEXT NOT NULL,
            palpite_campeao VARCHAR(60) NOT NULL,
            valor INT NOT NULL,
            status ENUM('aberta','ganhou','perdeu') NOT NULL DEFAULT 'aberta',
            premio INT NOT NULL DEFAULT 0,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            /* UMA APOSTA POR PESSOA POR EDIÇÃO. Várias apostas viram hedge:
               quem tem pontos cobre as cinco combinações e sempre leva o
               dobro de uma delas. O cartão único é o que mantém a aposta
               sendo uma decisão. */
            UNIQUE KEY uniq_aposta (edicao_id, user_id),
            INDEX idx_edicao (edicao_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ok = true;
    } catch (Throwable $e) {
        error_log('[bolao] tabelas: ' . $e->getMessage());
    }
}

/**
 * O ESCUDO DE CADA CLUBE, emprestado do Copero.
 *
 * O catálogo do jogo de carreira já tem URL de escudo calibrada pros clubes
 * grandes (@see games/core/copero_clubes.php) — não faz sentido um segundo
 * cadastro aqui. Quem não está lá cai no arquivo local do jogo de carreira, e
 * quem não tem nenhum dos dois volta vazio: a tela desenha o monograma, igual
 * ao resto do projeto.
 *
 * Nome de clube é referência factual; o site não hospeda escudo de terceiro
 * nem é afiliado a nenhum deles.
 */
function bolaoEscudo(string $clube): string
{
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        $cat = __DIR__ . '/../games/core/copero_clubes.php';
        if (is_file($cat)) {
            try {
                require_once $cat;
                if (defined('COPERO_CLUBES')) {
                    foreach (COPERO_CLUBES as $c) {
                        $url = trim((string)($c[3] ?? ''));
                        if ($url !== '') $mapa[mb_strtolower(trim((string)$c[0]))] = $url;
                    }
                }
            } catch (Throwable $e) { error_log('[bolao] escudos: ' . $e->getMessage()); }
        }
    }

    $k = mb_strtolower(trim($clube));
    if (isset($mapa[$k])) return $mapa[$k];

    /* O arquivo local do jogo de carreira, servido a partir da raiz do site
       (no catálogo o caminho é relativo a /games/). */
    $arq = __DIR__ . '/../games/img/escudos/' . bolaoSlug($clube) . '.png';
    if (is_file($arq)) return '/games/img/escudos/' . bolaoSlug($clube) . '.png';

    return '';
}

/** "Grêmio" => "gremio". O mesmo nome de arquivo que o jogo de carreira usa. */
function bolaoSlug(string $nome): string
{
    $s = mb_strtolower(trim($nome));
    $de = ['á','à','â','ã','ä','é','ê','ë','í','ï','ó','ô','õ','ö','ú','ü','ç'];
    $pa = ['a','a','a','a','a','e','e','e','i','i','o','o','o','o','u','u','c'];
    $s = str_replace($de, $pa, $s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string)$s, '-');
}

/**
 * A edição que está valendo: a última ainda não resolvida.
 *
 * Resolvida fica no banco pra sempre (é o histórico de quem acertou), mas sai
 * da frente — a aba passa a mostrar a próxima, ou o aviso de que não tem
 * bolão rolando.
 */
function bolaoEdicaoAtiva(PDO $pdo): ?array
{
    bolaoGarantirTabelas($pdo);
    try {
        $st = $pdo->query("SELECT * FROM bolao_edicoes WHERE resolvido_em IS NULL
                        ORDER BY id DESC LIMIT 1");
        $ed = $st->fetch(PDO::FETCH_ASSOC);
        return $ed ? bolaoHidratar($ed) : null;
    } catch (Throwable $e) {
        error_log('[bolao] edicao: ' . $e->getMessage());
        return null;
    }
}

/** A última edição já resolvida — o card de "como terminou". */
function bolaoEdicaoResolvida(PDO $pdo): ?array
{
    bolaoGarantirTabelas($pdo);
    try {
        $st = $pdo->query("SELECT * FROM bolao_edicoes WHERE resolvido_em IS NOT NULL
                        ORDER BY resolvido_em DESC LIMIT 1");
        $ed = $st->fetch(PDO::FETCH_ASSOC);
        return $ed ? bolaoHidratar($ed) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Abre os JSON da linha e calcula o que a tela precisa saber. */
function bolaoHidratar(array $ed): array
{
    $ed['candidatos_queda']  = json_decode((string)$ed['candidatos_queda'], true) ?: [];
    $ed['candidatos_titulo'] = json_decode((string)$ed['candidatos_titulo'], true) ?: [];
    $ed['resultado_queda']   = json_decode((string)($ed['resultado_queda'] ?? ''), true) ?: [];

    $agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $prazoVenceu = !empty($ed['fecha_em'])
        && $agora > new DateTimeImmutable((string)$ed['fecha_em'], new DateTimeZone('America/Sao_Paulo'));

    $ed['aberto_agora'] = !empty($ed['aberto']) && empty($ed['resolvido_em']) && !$prazoVenceu;
    $ed['prazo_venceu'] = $prazoVenceu;
    return $ed;
}

/** A aposta da pessoa nesta edição, ou null. */
function bolaoMinhaAposta(PDO $pdo, int $edicaoId, int $userId): ?array
{
    if ($edicaoId <= 0 || $userId <= 0) return null;
    try {
        $st = $pdo->prepare('SELECT * FROM bolao_apostas WHERE edicao_id = ? AND user_id = ? LIMIT 1');
        $st->execute([$edicaoId, $userId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) return null;
        $a['palpite_queda'] = json_decode((string)$a['palpite_queda'], true) ?: [];
        return $a;
    } catch (Throwable $e) {
        error_log('[bolao] minha aposta: ' . $e->getMessage());
        return null;
    }
}

/**
 * REGISTRA OU TROCA O CARTÃO.
 *
 * Tudo numa transação, porque são três escritas que não podem ficar pela
 * metade: o acerto do saldo, a aposta e (quando é troca) o que já estava lá.
 *
 * @param list<string> $queda  os clubes que a pessoa acha que caem
 * @return array{ok:bool,erro?:string,saldo?:int,trocou?:bool}
 */
function bolaoApostar(PDO $pdo, int $edicaoId, int $userId, array $queda, string $campeao, int $valor): array
{
    bolaoGarantirTabelas($pdo);

    try {
        $st = $pdo->prepare('SELECT * FROM bolao_edicoes WHERE id = ? LIMIT 1');
        $st->execute([$edicaoId]);
        $ed = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[bolao] apostar/edicao: ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Não deu pra ler o bolão agora.'];
    }
    if (!$ed) return ['ok' => false, 'erro' => 'Esse bolão não existe mais.'];

    $ed = bolaoHidratar($ed);
    if (!$ed['aberto_agora']) {
        return ['ok' => false, 'erro' => $ed['prazo_venceu']
            ? 'O prazo deste bolão já fechou.'
            : 'Este bolão está fechado pra apostas.'];
    }

    /* ── O CARTÃO ────────────────────────────────────────────────────── */
    $quantos = max(1, (int)$ed['quantos_caem']);
    $queda = array_values(array_unique(array_map(fn($c) => trim((string)$c), $queda)));
    $queda = array_values(array_filter($queda, fn($c) => $c !== ''));

    if (count($queda) !== $quantos) {
        return ['ok' => false, 'erro' => "Escolha exatamente {$quantos} time(s) pra cair."];
    }
    foreach ($queda as $c) {
        if (!in_array($c, $ed['candidatos_queda'], true)) {
            return ['ok' => false, 'erro' => 'Time fora da lista do bolão: ' . $c];
        }
    }
    $campeao = trim($campeao);
    if (!in_array($campeao, $ed['candidatos_titulo'], true)) {
        return ['ok' => false, 'erro' => 'Escolha o campeão entre as opções do bolão.'];
    }

    /* ── O VALOR ─────────────────────────────────────────────────────── */
    $max = (int)$ed['aposta_max'] ?: BOLAO_APOSTA_MAX;
    if ($valor < 1)    return ['ok' => false, 'erro' => 'Aposte pelo menos 1 FBA Point.'];
    if ($valor > $max) return ['ok' => false, 'erro' => 'O teto é ' . number_format($max, 0, ',', '.') . ' FBA Points.'];

    $jaTinha = bolaoMinhaAposta($pdo, $edicaoId, $userId);
    if ($jaTinha && ($jaTinha['status'] ?? 'aberta') !== 'aberta') {
        return ['ok' => false, 'erro' => 'Esta aposta já foi resolvida.'];
    }
    $antes = $jaTinha ? (int)$jaTinha['valor'] : 0;
    $delta = $valor - $antes;   // > 0 cobra a diferença, < 0 devolve

    try {
        $pdo->beginTransaction();

        if ($delta > 0) {
            /* O SALDO É CONFERIDO DENTRO DO UPDATE. Ler o saldo antes e
               descontar depois deixa a brecha de dois cliques simultâneos
               passarem pela mesma leitura. @see backend/loja.php */
            $up = $pdo->prepare('UPDATE games_usuarios SET fba_points = fba_points - ?
                                  WHERE id = ? AND fba_points >= ?');
            $up->execute([$delta, $userId, $delta]);
            if ($up->rowCount() === 0) {
                $pdo->rollBack();
                return ['ok' => false, 'erro' => 'Você não tem FBA Points suficientes pra essa aposta.'];
            }
        } elseif ($delta < 0) {
            $pdo->prepare('UPDATE games_usuarios SET fba_points = fba_points + ? WHERE id = ?')
                ->execute([-$delta, $userId]);
        }

        $pdo->prepare("INSERT INTO bolao_apostas (edicao_id, user_id, palpite_queda, palpite_campeao, valor)
                       VALUES (?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE palpite_queda = VALUES(palpite_queda),
                                               palpite_campeao = VALUES(palpite_campeao),
                                               valor = VALUES(valor)")
            ->execute([$edicaoId, $userId, json_encode($queda, JSON_UNESCAPED_UNICODE), $campeao, $valor]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[bolao] apostar: ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Não deu pra registrar a aposta. Tenta de novo.'];
    }

    return ['ok' => true, 'saldo' => bolaoSaldo($pdo, $userId), 'trocou' => (bool)$jaTinha];
}

/** Desiste enquanto está aberto: devolve tudo e apaga o cartão. */
function bolaoCancelar(PDO $pdo, int $edicaoId, int $userId): array
{
    $ed = null;
    try {
        $st = $pdo->prepare('SELECT * FROM bolao_edicoes WHERE id = ? LIMIT 1');
        $st->execute([$edicaoId]);
        $ed = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    if (!$ed) return ['ok' => false, 'erro' => 'Esse bolão não existe mais.'];

    $ed = bolaoHidratar($ed);
    if (!$ed['aberto_agora']) return ['ok' => false, 'erro' => 'O prazo fechou — não dá mais pra desistir.'];

    $a = bolaoMinhaAposta($pdo, $edicaoId, $userId);
    if (!$a) return ['ok' => false, 'erro' => 'Você não tem aposta neste bolão.'];
    if (($a['status'] ?? 'aberta') !== 'aberta') return ['ok' => false, 'erro' => 'Esta aposta já foi resolvida.'];

    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE games_usuarios SET fba_points = fba_points + ? WHERE id = ?')
            ->execute([(int)$a['valor'], $userId]);
        $pdo->prepare('DELETE FROM bolao_apostas WHERE id = ?')->execute([(int)$a['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[bolao] cancelar: ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Não deu pra desistir agora.'];
    }
    return ['ok' => true, 'saldo' => bolaoSaldo($pdo, $userId)];
}

/** O saldo de FBA Points de alguém. */
function bolaoSaldo(PDO $pdo, int $userId): int
{
    try {
        $st = $pdo->prepare('SELECT COALESCE(fba_points,0) FROM games_usuarios WHERE id = ?');
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/**
 * LANÇA O RESULTADO E PAGA.
 *
 * Acertar é acertar TUDO: os times que caíram (em qualquer ordem) e o
 * campeão. Quem cravou recebe o valor apostado vezes o multiplicador — o
 * que ele apostou já saiu do saldo na hora da aposta, então receber o dobro
 * é sair com o lucro de uma aposta.
 *
 * Roda uma vez só: a edição fica marcada como resolvida e a função recusa a
 * segunda chamada, pra não pagar duas vezes se alguém clicar duas vezes.
 *
 * @return array{ok:bool,erro?:string,ganhadores?:int,pago?:int,perdedores?:int}
 */
function bolaoResolver(PDO $pdo, int $edicaoId, array $queda, string $campeao, int $adminId = 0): array
{
    bolaoGarantirTabelas($pdo);

    try {
        $st = $pdo->prepare('SELECT * FROM bolao_edicoes WHERE id = ? LIMIT 1');
        $st->execute([$edicaoId]);
        $ed = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['ok' => false, 'erro' => 'Não deu pra ler o bolão.'];
    }
    if (!$ed) return ['ok' => false, 'erro' => 'Esse bolão não existe.'];
    if (!empty($ed['resolvido_em'])) return ['ok' => false, 'erro' => 'Este bolão já foi resolvido.'];

    $ed = bolaoHidratar($ed);
    $quantos = max(1, (int)$ed['quantos_caem']);

    $queda = array_values(array_unique(array_map(fn($c) => trim((string)$c), $queda)));
    if (count($queda) !== $quantos) return ['ok' => false, 'erro' => "Informe exatamente {$quantos} rebaixado(s)."];
    foreach ($queda as $c) {
        if (!in_array($c, $ed['candidatos_queda'], true)) return ['ok' => false, 'erro' => 'Time fora da lista: ' . $c];
    }
    $campeao = trim($campeao);
    if (!in_array($campeao, $ed['candidatos_titulo'], true)) return ['ok' => false, 'erro' => 'Campeão fora da lista.'];

    $mult = max(1, (int)$ed['multiplicador']);
    $certo = $queda; sort($certo);

    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare("SELECT * FROM bolao_apostas WHERE edicao_id = ? AND status = 'aberta' FOR UPDATE");
        $st->execute([$edicaoId]);
        $apostas = $st->fetchAll(PDO::FETCH_ASSOC);

        $marca = $pdo->prepare('UPDATE bolao_apostas SET status = ?, premio = ? WHERE id = ?');
        $paga  = $pdo->prepare('UPDATE games_usuarios SET fba_points = COALESCE(fba_points,0) + ? WHERE id = ?');

        $ganhadores = 0; $pago = 0; $perdedores = 0;
        foreach ($apostas as $a) {
            $meu = json_decode((string)$a['palpite_queda'], true) ?: [];
            sort($meu);
            $acertou = ($meu === $certo) && (trim((string)$a['palpite_campeao']) === $campeao);

            if ($acertou) {
                $premio = (int)$a['valor'] * $mult;
                $marca->execute(['ganhou', $premio, (int)$a['id']]);
                $paga->execute([$premio, (int)$a['user_id']]);
                $ganhadores++; $pago += $premio;
            } else {
                $marca->execute(['perdeu', 0, (int)$a['id']]);
                $perdedores++;
            }
        }

        $pdo->prepare('UPDATE bolao_edicoes SET resultado_queda = ?, resultado_campeao = ?,
                              resolvido_em = NOW(), resolvido_por = ?, aberto = 0 WHERE id = ?')
            ->execute([json_encode($queda, JSON_UNESCAPED_UNICODE), $campeao, $adminId ?: null, $edicaoId]);

        $pdo->commit();
        return ['ok' => true, 'ganhadores' => $ganhadores, 'pago' => $pago, 'perdedores' => $perdedores];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[bolao] resolver: ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Não deu pra resolver o bolão: ' . $e->getMessage()];
    }
}

/**
 * O retrato da edição: quantos apostaram, quanto está na mesa e como os
 * palpites se dividiram.
 *
 * A DIVISÃO SÓ APARECE DEPOIS DE APOSTAR (quem decide isso é a tela): ver a
 * manada antes de cravar transforma um palpite em enquete, que é justamente
 * o que o bolão não é.
 */
function bolaoResumo(PDO $pdo, int $edicaoId): array
{
    $out = ['apostadores' => 0, 'total' => 0, 'queda' => [], 'campeao' => []];
    try {
        $st = $pdo->prepare("SELECT palpite_queda, palpite_campeao, valor
                               FROM bolao_apostas WHERE edicao_id = ?");
        $st->execute([$edicaoId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $out['apostadores']++;
            $out['total'] += (int)$a['valor'];
            foreach ((json_decode((string)$a['palpite_queda'], true) ?: []) as $c) {
                $out['queda'][$c] = ($out['queda'][$c] ?? 0) + 1;
            }
            $c = trim((string)$a['palpite_campeao']);
            if ($c !== '') $out['campeao'][$c] = ($out['campeao'][$c] ?? 0) + 1;
        }
        arsort($out['queda']);
        arsort($out['campeao']);
    } catch (Throwable $e) {
        error_log('[bolao] resumo: ' . $e->getMessage());
    }
    return $out;
}

/** Quem apostou, pro card do admin e pro histórico depois de resolvido. */
function bolaoApostasDaEdicao(PDO $pdo, int $edicaoId, int $limite = 200): array
{
    try {
        $st = $pdo->prepare("SELECT a.*, COALESCE(g.nome, u.name) AS nome
                               FROM bolao_apostas a
                          LEFT JOIN games_usuarios g ON g.id = a.user_id
                          LEFT JOIN users u ON u.id = a.user_id
                              WHERE a.edicao_id = ?
                           ORDER BY a.valor DESC, a.id ASC
                              LIMIT {$limite}");
        $st->execute([$edicaoId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $a['palpite_queda'] = json_decode((string)$a['palpite_queda'], true) ?: [];
            $out[] = $a;
        }
        return $out;
    } catch (Throwable $e) {
        error_log('[bolao] lista: ' . $e->getMessage());
        return [];
    }
}

/**
 * A PRIMEIRA EDIÇÃO, a do Brasileirão 2026, criada sozinha no primeiro acesso.
 *
 * Os cinco candidatos à queda e os dois ao título são os que o Marcos passou
 * em 17/11/2026 — é a situação da tabela àquela altura, e não um palpite do
 * sistema. Quando o bolão seguinte vier, é outra linha; esta função só cria
 * se não existir NENHUMA edição, então ela nunca ressuscita nada.
 */
function bolaoSemearBrasileirao(PDO $pdo): void
{
    bolaoGarantirTabelas($pdo);
    try {
        if ((int)$pdo->query('SELECT COUNT(*) FROM bolao_edicoes')->fetchColumn() > 0) return;

        $pdo->prepare("INSERT INTO bolao_edicoes
                (titulo, subtitulo, aberto, aposta_max, multiplicador, quantos_caem,
                 candidatos_queda, candidatos_titulo)
                VALUES (?,?,1,?,?,2,?,?)")
            ->execute([
                'Bolão do Brasileirão',
                'Crave os 2 rebaixados e o campeão. Acertou tudo, leva o dobro.',
                BOLAO_APOSTA_MAX,
                BOLAO_MULTIPLICADOR,
                json_encode(['Grêmio', 'Internacional', 'Corinthians', 'Mirassol', 'Vitória', 'Coritiba'], JSON_UNESCAPED_UNICODE),
                json_encode(['Flamengo', 'Palmeiras'], JSON_UNESCAPED_UNICODE),
            ]);
    } catch (Throwable $e) {
        error_log('[bolao] semear: ' . $e->getMessage());
    }
}
