<?php
/**
 * COPA DO MUNDO DE ALGUMA COISA — o motor.
 *
 * Um torneio de mata-mata em que quem decide é o voto da galera. O admin
 * geral cria a copa (do que ele quiser: doces, jogadores, desenhos), põe os
 * competidores, sorteia o chaveamento e abre a votação. Cada confronto é
 * decidido no voto; quem tem mais votos avança, até sobrar um.
 *
 * ── Por que o placar aparece durante a votação ──────────────────────────
 *
 * Mostrar o parcial influencia o voto — e é isso mesmo. A graça de uma copa
 * popular é ver a zebra se formando e correr pra defender o seu. Esconder o
 * placar transformaria em enquete de pesquisa, que é outra coisa.
 *
 * ── A pontuação ────────────────────────────────────────────────────────
 *
 * Acertar quem avança vale 1 FBA Point. Acertar de novo, na sequência, vale
 * 2; depois 3, e por aí. Errar zera e recomeça em 1.
 *
 * A escada existe porque um voto isolado não é aposta nenhuma — todo mundo
 * acerta o favorito da primeira rodada. O que vale é sustentar a leitura por
 * várias rodadas, e o preço de errar cresce junto: quem está em 5 pensa duas
 * vezes antes de votar no coração.
 */

require_once __DIR__ . '/../../backend/db.php';

/**
 * Os atalhos de tamanho na criação.
 *
 * São SÓ ATALHOS PRA CONTAR: qualquer número de 2 a 64 monta chaveamento, e
 * o que não for potência de 2 vira bye. Nenhum deles trava coisa alguma.
 *
 * A lista era 8, 16, 32, 48 e 64 — cinco botões grandes, cada um escrito
 * "N competidores", e isso passava a ideia de que os cinco eram as opções
 * possíveis. Agora vai de 4 a 64 passando pelos quebrados, justamente pra a
 * tela mostrar que 10, 24 ou 40 são tão válidos quanto 32.
 */
const COPA_TAMANHOS = [4, 6, 8, 10, 12, 16, 20, 24, 32, 40, 48, 64];

/** Teto de competidores. Acima disso o chaveamento não cabe em tela nenhuma. */
const COPA_MAX = 64;

function copaTabelas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS copa_torneios (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        titulo       VARCHAR(120) NOT NULL,
        tamanho      INT NOT NULL,
        rodadas      INT NOT NULL,
        rodada_atual INT NOT NULL DEFAULT 1,
        votacao      TINYINT(1) NOT NULL DEFAULT 0,
        status       VARCHAR(12) NOT NULL DEFAULT 'ativo',
        campeao_id   INT NULL,
        criado_por   INT NULL,
        criado_em    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // A posição é o lugar no chaveamento depois do sorteio (1..P). É ela que
    // define quem encara quem — guardar só a lista embaralhada obrigaria a
    // reconstruir o par toda vez, e o sorteio precisa ser um fato gravado.
    $pdo->exec("CREATE TABLE IF NOT EXISTS copa_competidores (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        torneio_id INT NOT NULL,
        nome       VARCHAR(80) NOT NULL,
        foto       VARCHAR(400) NULL,
        posicao    INT NOT NULL,
        KEY idx_torneio (torneio_id, posicao)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // A foto entrou depois. Copa antiga fica com NULL e desenha só o nome,
    // que é exatamente como ela era.
    try {
        $pdo->exec("ALTER TABLE copa_competidores ADD COLUMN foto VARCHAR(400) NULL AFTER nome");
    } catch (Throwable $e) {
        // Já existe. Caminho normal em toda execução menos a primeira.
    }

    // b_id NULL é o bye: passou sem jogar. Vira uma linha de confronto assim
    // mesmo pra rodada 1 ter sempre P/2 linhas — o desenho do chaveamento
    // conta com isso, e um bye que não existisse abriria um buraco na coluna.
    $pdo->exec("CREATE TABLE IF NOT EXISTS copa_confrontos (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        torneio_id  INT NOT NULL,
        rodada      INT NOT NULL,
        ordem       INT NOT NULL,
        a_id        INT NULL,
        b_id        INT NULL,
        vencedor_id INT NULL,
        no_sorteio  TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uk_conf (torneio_id, rodada, ordem),
        KEY idx_rodada (torneio_id, rodada)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS copa_votos (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        confronto_id INT NOT NULL,
        user_id      INT NOT NULL,
        escolha_id   INT NOT NULL,
        criado_em    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_voto (confronto_id, user_id),
        KEY idx_conf (confronto_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // A sequência mora aqui e não é recalculada dos votos: o valor pago
    // depende de QUANDO o acerto aconteceu, e um recálculo posterior pagaria
    // diferente se a ordem das rodadas mudasse.
    $pdo->exec("CREATE TABLE IF NOT EXISTS copa_sequencias (
        torneio_id INT NOT NULL,
        user_id    INT NOT NULL,
        sequencia  INT NOT NULL DEFAULT 0,
        melhor     INT NOT NULL DEFAULT 0,
        acertos    INT NOT NULL DEFAULT 0,
        erros      INT NOT NULL DEFAULT 0,
        pontos     INT NOT NULL DEFAULT 0,
        PRIMARY KEY (torneio_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Quantos slots o chaveamento tem: a potência de 2 igual ou acima de N.
 *
 * 48 competidores viram um chaveamento de 64 com 16 vagas vazias — é assim
 * que "uns passam sem confronto e outros com" acontece, sem regra especial
 * pra cada tamanho.
 */
function copaSlots(int $n): int
{
    $p = 2;
    while ($p < $n) $p *= 2;
    return max(2, $p);
}

/** Quantas rodadas até o campeão. 64 slots = 6 rodadas. */
function copaRodadas(int $slots): int
{
    return (int)round(log($slots, 2));
}

/**
 * Esta rodada paga FBA Points?
 *
 * Só das OITAVAS em diante. Numa copa de 64, os 32 avos e os 16 avos ficam
 * de fora; numa de 16, a primeira rodada JÁ é as oitavas e tudo paga.
 *
 * O motivo é a conta: 32 confrontos numa rodada só pagariam mais que a copa
 * inteira das fases decisivas somadas, e o palpite ali é barato — quase todo
 * favorito de primeira rodada passa. A escada existe pra premiar quem
 * sustenta a leitura quando ela fica difícil, e é das oitavas em diante que
 * ela fica.
 *
 * "Faltam 3" é as oitavas: 3 rodadas depois dela vêm quartas, semi e final.
 */
function copaRodadaPaga(int $rodada, int $rodadas): bool
{
    return ($rodadas - $rodada) <= 3;
}

/** O nome da rodada, contando de trás pra frente. */
function copaNomeRodada(int $rodada, int $rodadas): string
{
    $faltam = $rodadas - $rodada;      // 0 = final
    return match ($faltam) {
        0 => 'Final',
        1 => 'Semifinal',
        2 => 'Quartas de final',
        3 => 'Oitavas de final',
        4 => '16 avos de final',
        5 => '32 avos de final',
        default => 'Rodada ' . $rodada,
    };
}

/**
 * A ORDEM DOS BYES no chaveamento.
 *
 * Os byes não podem cair em qualquer lugar: dois byes no mesmo confronto
 * dariam um "jogo" sem ninguém, e byes amontoados de um lado fariam metade
 * da chave chegar às quartas sem votar em nada.
 *
 * Então eles são espalhados pelos confrontos da primeira rodada em passos
 * regulares — no chaveamento de 64 com 16 byes, um a cada dois confrontos.
 * Dentro dessa regra, QUEM ganha o bye é sorteado junto com o resto.
 *
 * @return int[] índices (0-based) dos confrontos da rodada 1 que têm bye
 */
function copaConfrontosComBye(int $slots, int $byes): array
{
    $confrontos = intdiv($slots, 2);
    if ($byes <= 0) return [];
    if ($byes >= $confrontos) return range(0, $confrontos - 1);

    $out = [];
    // Distribui em passo fracionário e arredonda: dá o espalhamento mais
    // regular possível quando os byes não dividem os confrontos por igual.
    for ($i = 0; $i < $byes; $i++) {
        $out[] = (int)floor($i * $confrontos / $byes);
    }
    return array_values(array_unique($out));
}

/**
 * A URL da foto, se ela for utilizável. Devolve null pra qualquer outra
 * coisa — inclusive pra string vazia e pra texto que não é URL.
 *
 * Só http, https e data: entram. `javascript:` num src não executa em
 * navegador nenhum hoje, mas essa URL vai parar num atributo HTML montado
 * por mim, e filtrar na entrada é mais barato que confiar no escape de cada
 * lugar que a use depois.
 */
function copaFotoValida(?string $u): ?string
{
    $u = trim((string)$u);
    if ($u === '' || mb_strlen($u) > 400) return null;

    // Caminho do próprio site ("/uploads/copa/x.png") — é o que o upload
    // devolve, e foi o que faltou na primeira versão: a copa era criada e
    // TODAS as fotos enviadas eram descartadas em silêncio.
    //
    // "//outro.site/x.jpg" fica de fora: duas barras é URL de outro host
    // disfarçada de caminho local.
    if ($u[0] === '/' && ($u[1] ?? '') !== '/') return $u;

    return preg_match('~^(https?://|data:image/)~i', $u) ? $u : null;
}

/**
 * Cria a copa: sorteia as posições e monta a rodada 1.
 *
 * O sorteio é feito e GRAVADO aqui, de uma vez. Sortear na hora de exibir
 * daria um chaveamento diferente a cada F5 — e o sorteio é o momento que a
 * galera assiste, então ele precisa ser um fato, não uma animação.
 *
 * @param string[] $nomes
 * @return array{ok:bool, erro:?string, id:?int}
 */
function copaCriar(PDO $pdo, string $titulo, array $nomes, int $criadoPor): array
{
    copaTabelas($pdo);

    $titulo = trim($titulo);
    if ($titulo === '') return ['ok' => false, 'erro' => 'Dê um nome pra copa.', 'id' => null];
    if (mb_strlen($titulo) > 120) $titulo = mb_substr($titulo, 0, 120);

    // Limpa, tira vazios e repetidos. Nome repetido no chaveamento deixa o
    // voto ambíguo — não dá pra saber em qual dos dois a pessoa votou.
    //
    // Cada linha pode ser "Nome" ou "Nome | url-da-foto". O separador é a
    // barra vertical e não a vírgula porque vírgula já separa competidores
    // quando alguém cola uma lista pronta.
    $limpos = [];
    $vistos = [];
    foreach ($nomes as $bruta) {
        $bruta = (string)$bruta;
        $foto  = null;

        if (strpos($bruta, '|') !== false) {
            [$bruta, $u] = array_map('trim', explode('|', $bruta, 2));
            $foto = copaFotoValida($u);
        }

        $n = trim(preg_replace('/\s+/u', ' ', $bruta));
        if ($n === '') continue;
        if (mb_strlen($n) > 80) $n = mb_substr($n, 0, 80);

        $chave = mb_strtolower($n);
        if (isset($vistos[$chave])) continue;
        $vistos[$chave] = true;

        $limpos[] = ['nome' => $n, 'foto' => $foto];
    }

    $n = count($limpos);
    if ($n < 2)          return ['ok' => false, 'erro' => 'Precisa de pelo menos 2 competidores.', 'id' => null];
    if ($n > COPA_MAX)   return ['ok' => false, 'erro' => 'No máximo ' . COPA_MAX . ' competidores.', 'id' => null];

    $slots   = copaSlots($n);
    $rodadas = copaRodadas($slots);
    $byes    = $slots - $n;

    try {
        $pdo->beginTransaction();

        $pdo->prepare("INSERT INTO copa_torneios (titulo, tamanho, rodadas, criado_por)
                       VALUES (?,?,?,?)")->execute([$titulo, $n, $rodadas, $criadoPor]);
        $id = (int)$pdo->lastInsertId();

        // O SORTEIO. Embaralha os nomes e distribui nas posições.
        shuffle($limpos);

        $comBye = copaConfrontosComBye($slots, $byes);
        $comBye = array_flip($comBye);

        $insComp = $pdo->prepare("INSERT INTO copa_competidores (torneio_id, nome, foto, posicao) VALUES (?,?,?,?)");
        $insConf = $pdo->prepare("INSERT INTO copa_confrontos (torneio_id, rodada, ordem, a_id, b_id, vencedor_id)
                                  VALUES (?,1,?,?,?,?)");

        $i = 0;   // próximo nome do monte embaralhado
        $pos = 1; // posição no chaveamento
        for ($c = 0; $c < intdiv($slots, 2); $c++) {
            // O A existe sempre; o B só quando o confronto não é bye.
            $a = $limpos[$i++];
            $insComp->execute([$id, $a['nome'], $a['foto'], $pos++]);
            $aId = (int)$pdo->lastInsertId();

            $bId = null;
            if (!isset($comBye[$c])) {
                $b = $limpos[$i++];
                $insComp->execute([$id, $b['nome'], $b['foto'], $pos++]);
                $bId = (int)$pdo->lastInsertId();
            } else {
                $pos++;   // a vaga vazia consome a posição, pro desenho bater
            }

            // Bye já nasce decidido: sem adversário, não há o que votar.
            $insConf->execute([$id, $c, $aId, $bId, $bId === null ? $aId : null]);
        }

        $pdo->commit();
        return ['ok' => true, 'erro' => null, 'id' => $id];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[copa] criar: ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Não deu pra criar agora.', 'id' => null];
    }
}

/** O torneio, ou null. */
function copaTorneio(PDO $pdo, int $id): ?array
{
    copaTabelas($pdo);
    $st = $pdo->prepare("SELECT * FROM copa_torneios WHERE id=?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** A copa mais recente que ainda está rolando — é a que a página abre. */
function copaAtual(PDO $pdo): ?array
{
    copaTabelas($pdo);
    $r = $pdo->query("SELECT * FROM copa_torneios ORDER BY (status='ativo') DESC, id DESC LIMIT 1")
             ->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Todas as copas, pro seletor. */
function copaLista(PDO $pdo, int $limite = 30): array
{
    copaTabelas($pdo);
    return $pdo->query("SELECT id, titulo, status, rodada_atual, rodadas, tamanho
                          FROM copa_torneios ORDER BY id DESC LIMIT " . (int)$limite)
               ->fetchAll(PDO::FETCH_ASSOC);
}

/** id => nome dos competidores. */
function copaCompetidores(PDO $pdo, int $torneioId): array
{
    $st = $pdo->prepare("SELECT id, nome, foto, posicao FROM copa_competidores WHERE torneio_id=? ORDER BY posicao");
    $st->execute([$torneioId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = $r;
    return $out;
}

/**
 * O chaveamento inteiro, rodada a rodada, com a contagem de votos e o que
 * a pessoa votou.
 *
 * Vem tudo numa consulta só por tabela — o desenho precisa das seis rodadas
 * ao mesmo tempo, e uma consulta por confronto seriam 63 idas ao banco numa
 * copa de 64.
 */
function copaChave(PDO $pdo, int $torneioId, int $userId = 0): array
{
    copaTabelas($pdo);

    $st = $pdo->prepare("SELECT * FROM copa_confrontos WHERE torneio_id=? ORDER BY rodada, ordem");
    $st->execute([$torneioId]);
    $confrontos = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$confrontos) return [];

    // Votos por confronto e por escolha.
    $vt = $pdo->prepare("SELECT v.confronto_id, v.escolha_id, COUNT(*) n
                           FROM copa_votos v
                           JOIN copa_confrontos c ON c.id = v.confronto_id
                          WHERE c.torneio_id = ?
                          GROUP BY v.confronto_id, v.escolha_id");
    $vt->execute([$torneioId]);
    $votos = [];
    foreach ($vt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $votos[(int)$r['confronto_id']][(int)$r['escolha_id']] = (int)$r['n'];
    }

    $meu = [];
    if ($userId > 0) {
        $mv = $pdo->prepare("SELECT v.confronto_id, v.escolha_id
                               FROM copa_votos v
                               JOIN copa_confrontos c ON c.id = v.confronto_id
                              WHERE c.torneio_id = ? AND v.user_id = ?");
        $mv->execute([$torneioId, $userId]);
        foreach ($mv->fetchAll(PDO::FETCH_ASSOC) as $r) $meu[(int)$r['confronto_id']] = (int)$r['escolha_id'];
    }

    $out = [];
    foreach ($confrontos as $c) {
        $id = (int)$c['id'];
        $c['meu_voto'] = $meu[$id] ?? null;

        /* O PLACAR SÓ EXISTE DEPOIS QUE O CONFRONTO ACABA.
           Enquanto não há vencedor, os votos saem daqui ZERADOS e com
           'placar_oculto' ligado — e é aqui, na fonte, porque a chave
           alimenta a página e o bot: escondido só numa das duas, bastava
           pedir /vercopa pra ver o que a página não mostrava.

           Ver o parcial estragava a votação de duas maneiras. A primeira o
           código já admitia no comentário do copaVotar: com o placar ao
           vivo, dava pra votar cedo e trocar pro líder — e a solução foi
           proibir a troca, que trata o sintoma. A segunda continuava de pé:
           quem vota depois vê a manada e acompanha, então a copa deixa de
           medir quem lê melhor e passa a medir quem chega por último.

           Quem já votou continua vendo o PRÓPRIO voto (meu_voto acima): isso
           não conta nada sobre os outros, e sem ele a pessoa não lembra se
           chegou a votar naquele confronto. */
        $c['placar_oculto'] = empty($c['vencedor_id']);
        $c['votos_a'] = $c['placar_oculto'] ? 0 : ($votos[$id][(int)$c['a_id']] ?? 0);
        $c['votos_b'] = ($c['placar_oculto'] || !$c['b_id'])
            ? 0
            : ($votos[$id][(int)$c['b_id']] ?? 0);

        $out[(int)$c['rodada']][] = $c;
    }
    return $out;
}

/**
 * Registra o voto. UMA VEZ, e sem volta.
 *
 * Trocar o voto era permitido, e isso quebrava o jogo: o placar aparece ao
 * vivo, então bastava votar cedo, olhar quem estava ganhando e trocar pro
 * líder antes de fechar. Quem fizesse isso acertava tudo sempre, e a
 * sequência — que existe pra medir leitura — passaria a medir paciência.
 *
 * @return array{ok:bool, erro:?string}
 */
function copaVotar(PDO $pdo, int $torneioId, int $confrontoId, int $escolhaId, int $userId): array
{
    copaTabelas($pdo);
    $t = copaTorneio($pdo, $torneioId);
    if (!$t)                          return ['ok' => false, 'erro' => 'Copa não encontrada.'];
    if ($t['status'] !== 'ativo')     return ['ok' => false, 'erro' => 'Esta copa já acabou.'];
    if (empty($t['votacao']))         return ['ok' => false, 'erro' => 'A votação está fechada.'];

    $st = $pdo->prepare("SELECT * FROM copa_confrontos WHERE id=? AND torneio_id=?");
    $st->execute([$confrontoId, $torneioId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c)                                        return ['ok' => false, 'erro' => 'Confronto não encontrado.'];
    if ((int)$c['rodada'] !== (int)$t['rodada_atual']) return ['ok' => false, 'erro' => 'Essa rodada não está em votação.'];
    if ($c['vencedor_id'])                          return ['ok' => false, 'erro' => 'Esse confronto já foi decidido.'];
    if ($escolhaId !== (int)$c['a_id'] && $escolhaId !== (int)$c['b_id']) {
        return ['ok' => false, 'erro' => 'Esse competidor não está nesse confronto.'];
    }

    try {
        // INSERT IGNORE e conferência do rowCount: a chave única (confronto,
        // pessoa) é quem garante o voto único, e não uma leitura antes do
        // insert — entre ler e gravar cabe um segundo clique.
        $st = $pdo->prepare("INSERT IGNORE INTO copa_votos (confronto_id, user_id, escolha_id)
                             VALUES (?,?,?)");
        $st->execute([$confrontoId, $userId, $escolhaId]);
        if ($st->rowCount() === 0) {
            return ['ok' => false, 'erro' => 'Você já votou neste confronto — o voto não muda.'];
        }
        return ['ok' => true, 'erro' => null];
    } catch (Throwable $e) {
        error_log('[copa] votar: ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Não deu pra registrar o voto.'];
    }
}

/** Abre ou fecha a votação da rodada em curso. */
function copaVotacao(PDO $pdo, int $torneioId, bool $aberta): void
{
    copaTabelas($pdo);
    $pdo->prepare("UPDATE copa_torneios SET votacao=? WHERE id=?")
        ->execute([$aberta ? 1 : 0, $torneioId]);
}

/**
 * Fecha a rodada: apura os votos, paga quem acertou e monta a rodada
 * seguinte. É a única função que mexe em FBA Points.
 *
 * @return array{ok:bool, erro:?string, decididos:int, sorteados:int, pagos:int, campeao:?string}
 */
function copaFecharRodada(PDO $pdo, int $torneioId): array
{
    copaTabelas($pdo);
    $vazio = ['ok' => false, 'erro' => null, 'decididos' => 0, 'sorteados' => 0, 'pagos' => 0, 'campeao' => null];

    $t = copaTorneio($pdo, $torneioId);
    if (!$t)                      return ['erro' => 'Copa não encontrada.'] + $vazio;
    if ($t['status'] !== 'ativo') return ['erro' => 'Esta copa já acabou.'] + $vazio;

    $rodada  = (int)$t['rodada_atual'];
    $rodadas = (int)$t['rodadas'];

    $st = $pdo->prepare("SELECT * FROM copa_confrontos WHERE torneio_id=? AND rodada=? ORDER BY ordem");
    $st->execute([$torneioId, $rodada]);
    $confrontos = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$confrontos) return ['erro' => 'Essa rodada não tem confronto.'] + $vazio;

    $comps = copaCompetidores($pdo, $torneioId);

    try {
        $pdo->beginTransaction();

        $decididos = $sorteados = 0;
        $vencedores = [];

        foreach ($confrontos as $c) {
            $cid = (int)$c['id'];

            if ($c['vencedor_id']) {                 // bye, ou já decidido antes
                $vencedores[(int)$c['ordem']] = (int)$c['vencedor_id'];
                continue;
            }

            $vt = $pdo->prepare("SELECT escolha_id, COUNT(*) n FROM copa_votos
                                  WHERE confronto_id=? GROUP BY escolha_id");
            $vt->execute([$cid]);
            $cont = [];
            foreach ($vt->fetchAll(PDO::FETCH_ASSOC) as $r) $cont[(int)$r['escolha_id']] = (int)$r['n'];

            $a = (int)$c['a_id'];
            $b = (int)$c['b_id'];
            $na = $cont[$a] ?? 0;
            $nb = $cont[$b] ?? 0;

            // Empate (inclusive 0 a 0) vai pro sorteio, e fica marcado como
            // tal. A alternativa era travar a copa esperando o desempate —
            // e uma copa parada porque ninguém votou num confronto de
            // primeira rodada é pior que uma moeda ao ar assumida.
            if ($na === $nb) {
                $venc = random_int(0, 1) ? $a : $b;
                $sorteados++;
                $pdo->prepare("UPDATE copa_confrontos SET vencedor_id=?, no_sorteio=1 WHERE id=?")
                    ->execute([$venc, $cid]);
            } else {
                $venc = $na > $nb ? $a : $b;
                $decididos++;
                $pdo->prepare("UPDATE copa_confrontos SET vencedor_id=? WHERE id=?")
                    ->execute([$venc, $cid]);
            }
            $vencedores[(int)$c['ordem']] = $venc;
        }

        // ── Quem acertou, e quanto vale ────────────────────────────────
        //
        // Rodada anterior às oitavas não paga NEM mexe no sequência. Se ela
        // contasse pro sequência sem pagar, quem votou nos 32 avos chegaria às
        // oitavas já no sequência 3 — e a escada, que existe pra medir leitura
        // nas fases difíceis, viraria prêmio por ter votado cedo.
        $pagos = copaRodadaPaga($rodada, $rodadas)
            ? copaPagarRodada($pdo, $torneioId, $rodada)
            : 0;

        // ── A rodada seguinte, ou o campeão ────────────────────────────
        $campeao = null;
        if ($rodada >= $rodadas) {
            $vencId = reset($vencedores);
            $campeao = $comps[$vencId]['nome'] ?? null;
            $pdo->prepare("UPDATE copa_torneios SET status='encerrado', votacao=0, campeao_id=? WHERE id=?")
                ->execute([$vencId, $torneioId]);
        } else {
            ksort($vencedores);
            $vs = array_values($vencedores);
            $ins = $pdo->prepare("INSERT INTO copa_confrontos (torneio_id, rodada, ordem, a_id, b_id)
                                  VALUES (?,?,?,?,?)
                                  ON DUPLICATE KEY UPDATE a_id=VALUES(a_id), b_id=VALUES(b_id)");
            for ($i = 0; $i < count($vs); $i += 2) {
                $ins->execute([$torneioId, $rodada + 1, intdiv($i, 2), $vs[$i], $vs[$i + 1] ?? null]);
            }
            // A votação nasce FECHADA na rodada nova: quem fecha a rodada
            // costuma querer mostrar o resultado antes de abrir a próxima.
            $pdo->prepare("UPDATE copa_torneios SET rodada_atual=?, votacao=0 WHERE id=?")
                ->execute([$rodada + 1, $torneioId]);
        }

        $pdo->commit();
        return ['ok' => true, 'erro' => null, 'decididos' => $decididos,
                'sorteados' => $sorteados, 'pagos' => $pagos, 'campeao' => $campeao];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[copa] fechar: ' . $e->getMessage());
        return ['erro' => 'Não deu pra fechar: ' . $e->getMessage()] + $vazio;
    }
}

/**
 * Paga a rodada e atualiza as sequências.
 *
 * CADA VOTO CERTO vale o sequência em que a pessoa está. Acertar 28 de 32 no
 * sequência 1 paga 28; os mesmos 28 no sequência 3 pagariam 84.
 *
 * O sequência sobe por RODADA, e não por confronto. Por confronto, os 32 jogos
 * da primeira rodada já pagariam 1+2+3+…+32 = 528 pontos a quem acertasse
 * tudo — mais que a loja inteira, numa rodada só. Por rodada, a escada mede
 * o que ela deveria medir: sustentar a leitura ao longo da copa.
 *
 * Sobe quem acerta a MAIORIA dos próprios palpites. Exigir 32 em 32 faria a
 * escada nunca sair de 1; e quem vota em dois confrontos e acerta os dois
 * sobe igual a quem vota em trinta e dois — de propósito, porque a escada é
 * sobre constância, e o volume já é pago no ponto por voto.
 *
 * @return int quantas pessoas receberam
 */
function copaPagarRodada(PDO $pdo, int $torneioId, int $rodada): int
{
    // Voto a voto da rodada, com o vencedor já gravado ao lado.
    $st = $pdo->prepare("SELECT v.user_id, v.escolha_id, c.vencedor_id
                           FROM copa_votos v
                           JOIN copa_confrontos c ON c.id = v.confronto_id
                          WHERE c.torneio_id = ? AND c.rodada = ?");
    $st->execute([$torneioId, $rodada]);

    $porPessoa = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $u = (int)$r['user_id'];
        if (!isset($porPessoa[$u])) $porPessoa[$u] = ['certos' => 0, 'total' => 0];
        $porPessoa[$u]['total']++;
        if ((int)$r['escolha_id'] === (int)$r['vencedor_id']) $porPessoa[$u]['certos']++;
    }
    if (!$porPessoa) return 0;

    $lerSeq = $pdo->prepare("SELECT sequencia, melhor, acertos, erros, pontos
                               FROM copa_sequencias WHERE torneio_id=? AND user_id=?");
    $gravar = $pdo->prepare("INSERT INTO copa_sequencias
                             (torneio_id, user_id, sequencia, melhor, acertos, erros, pontos)
                             VALUES (?,?,?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE
                               sequencia=VALUES(sequencia), melhor=VALUES(melhor),
                               acertos=VALUES(acertos), erros=VALUES(erros), pontos=VALUES(pontos)");
    $pagar = $pdo->prepare("UPDATE games_usuarios SET fba_points = COALESCE(fba_points,0) + ? WHERE id = ?");

    $pagos = 0;
    foreach ($porPessoa as $uid => $d) {
        $lerSeq->execute([$torneioId, $uid]);
        $s = $lerSeq->fetch(PDO::FETCH_ASSOC)
             ?: ['sequencia' => 0, 'melhor' => 0, 'acertos' => 0, 'erros' => 0, 'pontos' => 0];

        // O sequência desta rodada é o que a pessoa já tinha mais um, quando ela
        // acerta a maioria. Quem errou a maioria cai pro sequência 1 — e não
        // pro zero: ela ainda acertou alguns palpites, e não pagar nada por
        // eles faria a rodada ruim valer o mesmo que não votar.
        $acertouMaioria = $d['certos'] * 2 > $d['total'];
        $seq   = $acertouMaioria ? (int)$s['sequencia'] + 1 : 0;
        $multiplicador = max(1, $seq);
        $ganho  = $d['certos'] * $multiplicador;

        if ($ganho > 0) {
            $pagar->execute([$ganho, $uid]);
            $pagos++;
        }

        $gravar->execute([
            $torneioId, $uid, $seq,
            max((int)$s['melhor'], $seq),
            (int)$s['acertos'] + $d['certos'],
            (int)$s['erros'] + ($d['total'] - $d['certos']),
            (int)$s['pontos'] + $ganho,
        ]);
    }
    return $pagos;
}

/* ────────────────────────────────────────────────────────────────────────
 * OS TEXTOS DO GRUPO
 * ──────────────────────────────────────────────────────────────────────── */

/**
 * Uma linha de confronto: quem ganhou, o placar e quem caiu.
 *
 * O perdedor aparece SEMPRE, e com o número de votos dele. "Coxinha
 * avançou" não conta a história; "Coxinha 12 x 3 Pastel" conta — dá pra ver
 * atropelo, jogo apertado e zebra sem precisar abrir o site.
 */
function copaLinhaConfronto(array $c, array $comps): string
{
    $nome = fn($id) => $comps[$id]['nome'] ?? '—';
    $a = (int)$c['a_id'];
    $b = (int)$c['b_id'];
    $v = (int)$c['vencedor_id'];

    if (!$b) return '⏩ *' . $nome($a) . '* passou sem confronto';

    $va = (int)($c['votos_a'] ?? 0);
    $vb = (int)($c['votos_b'] ?? 0);

    // O vencedor sempre à esquerda: o olho lê a coluna da esquerda pra
    // saber quem passou, e alternar a posição obrigaria a ler tudo.
    if ($v === $b) { [$a, $b] = [$b, $a]; [$va, $vb] = [$vb, $va]; }

    $marca = !empty($c['no_sorteio']) ? '🎲' : '✅';
    $obs   = !empty($c['no_sorteio']) ? ' _(empate, no sorteio)_' : '';

    return $marca . ' *' . $nome($a) . "* {$va} x {$vb} " . $nome($b) . $obs;
}

/**
 * O resultado de uma rodada, pro grupo. É o que sai quando o admin apura.
 *
 * @return string vazio se a rodada não existe
 */
function copaTextoResultado(PDO $pdo, int $torneioId, int $rodada): string
{
    copaTabelas($pdo);
    $t = copaTorneio($pdo, $torneioId);
    if (!$t) return '';

    $chave = copaChave($pdo, $torneioId);
    if (empty($chave[$rodada])) return '';
    $comps = copaCompetidores($pdo, $torneioId);

    $l = ['🏆 *' . mb_strtoupper($t['titulo']) . '*',
          '_' . copaNomeRodada($rodada, (int)$t['rodadas']) . ' — resultado_', ''];

    foreach ($chave[$rodada] as $c) $l[] = copaLinhaConfronto($c, $comps);

    $l[] = '';
    // Sem esta linha, quem acertou tudo numa rodada que não paga vai
    // procurar os pontos que não vieram e achar que quebrou.
    if (!copaRodadaPaga($rodada, (int)$t['rodadas'])) {
        $l[] = '_(Esta fase não paga FBA Points — eles começam nas oitavas.)_';
        $l[] = '';
    }
    if ($t['status'] !== 'ativo' && $t['campeao_id']) {
        $l[] = '🥇 *CAMPEÃO: ' . ($comps[(int)$t['campeao_id']]['nome'] ?? '?') . '*';
    } else {
        $l[] = 'Agora: *' . copaNomeRodada((int)$t['rodada_atual'], (int)$t['rodadas']) . '*';
        // A votação nasce fechada na rodada nova — dizer "vote agora" aqui
        // mandaria a galera pra uma tela que ainda não aceita voto.
        $l[] = empty($t['votacao'])
            ? '_Aguardando a votação abrir._'
            : '_Votação aberta!_';
    }
    return implode("\n", $l);
}

/**
 * Como a copa está agora — é o /vercopa.
 *
 * Mostra a rodada em andamento com o parcial, e não a rodada passada: quem
 * pergunta "como tá?" quer saber em que pé está o que ainda dá pra mudar.
 */
function copaTextoAgora(PDO $pdo, ?int $torneioId = null): string
{
    copaTabelas($pdo);
    $t = $torneioId ? copaTorneio($pdo, $torneioId) : copaAtual($pdo);
    if (!$t) {
        // Distingue "não existe copa nenhuma" de "esse número não existe".
        // A mesma frase pros dois faria quem digitou o número errado achar
        // que a copa inteira sumiu.
        return $torneioId
            ? "Não achei a copa #{$torneioId}. Manda */vercopa* pra ver a atual."
            : 'Nenhuma copa criada ainda.';
    }

    $tid   = (int)$t['id'];
    $comps = copaCompetidores($pdo, $tid);
    $chave = copaChave($pdo, $tid);
    $rod   = (int)$t['rodada_atual'];

    $l = ['🏆 *' . mb_strtoupper($t['titulo']) . '*'];

    if ($t['status'] !== 'ativo' && $t['campeao_id']) {
        $l[] = '';
        $l[] = '🥇 *CAMPEÃO: ' . ($comps[(int)$t['campeao_id']]['nome'] ?? '?') . '*';
        $l[] = '';
        $l[] = '_' . copaNomeRodada((int)$t['rodadas'], (int)$t['rodadas']) . '_';
        foreach ($chave[(int)$t['rodadas']] ?? [] as $c) $l[] = copaLinhaConfronto($c, $comps);
        return implode("\n", $l);
    }

    $l[] = '_' . copaNomeRodada($rod, (int)$t['rodadas'])
         . (empty($t['votacao']) ? ' — votação fechada_' : ' — votação ABERTA_');
    $l[] = '';

    $abertos = 0;
    foreach ($chave[$rod] ?? [] as $c) {
        if ($c['vencedor_id']) { $l[] = copaLinhaConfronto($c, $comps); continue; }
        $abertos++;
        $na = $comps[(int)$c['a_id']]['nome'] ?? '—';
        $nb = $comps[(int)$c['b_id']]['nome'] ?? '—';
        // SEM PLACAR enquanto o confronto está de pé. Antes saía o parcial
        // com o líder em negrito, e era o mesmo problema da página: quem
        // pedia /vercopa antes de votar já sabia pra onde a manada estava
        // indo. Agora os dois nomes saem iguais, e quem passou aparece só na
        // apuração.
        $l[] = "▪️ {$na} x {$nb}";
    }

    $l[] = '';
    if ($abertos) {
        $l[] = !empty($t['votacao'])
            ? "_{$abertos} confronto(s) em aberto. Vote no site!_"
            : '_Aguardando o próximo passo da organização._';
        $l[] = '_Os votos aparecem quando a rodada for apurada._';
    } else {
        $l[] = '_Aguardando o próximo passo da organização._';
    }

    return implode("\n", $l);
}

/** O ranking da copa, pro placar de quem está indo bem. */
function copaRanking(PDO $pdo, int $torneioId, int $limite = 20): array
{
    copaTabelas($pdo);
    $st = $pdo->prepare("SELECT s.*, COALESCE(g.nome, u.name, 'GM') AS nome
                           FROM copa_sequencias s
                           LEFT JOIN games_usuarios g ON g.id = s.user_id
                           LEFT JOIN users u          ON u.id = s.user_id
                          WHERE s.torneio_id = ?
                          ORDER BY s.pontos DESC, s.sequencia DESC, s.acertos DESC
                          LIMIT " . (int)$limite);
    $st->execute([$torneioId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Quantas pessoas votaram na rodada — pro admin saber se já dá pra fechar. */
function copaQuantosVotaram(PDO $pdo, int $torneioId, int $rodada): int
{
    $st = $pdo->prepare("SELECT COUNT(DISTINCT v.user_id)
                           FROM copa_votos v
                           JOIN copa_confrontos c ON c.id = v.confronto_id
                          WHERE c.torneio_id = ? AND c.rodada = ?");
    $st->execute([$torneioId, $rodada]);
    return (int)$st->fetchColumn();
}

/** Apaga a copa inteira. Só pro admin desfazer um sorteio que saiu errado. */
function copaApagar(PDO $pdo, int $torneioId): void
{
    copaTabelas($pdo);
    $pdo->prepare("DELETE v FROM copa_votos v JOIN copa_confrontos c ON c.id=v.confronto_id
                    WHERE c.torneio_id=?")->execute([$torneioId]);
    foreach (['copa_confrontos', 'copa_competidores', 'copa_sequencias'] as $tb) {
        $pdo->prepare("DELETE FROM {$tb} WHERE torneio_id=?")->execute([$torneioId]);
    }
    $pdo->prepare("DELETE FROM copa_torneios WHERE id=?")->execute([$torneioId]);
}
