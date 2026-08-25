<?php
/**
 * AS SEQUÊNCIAS DIÁRIAS — quantos dias seguidos alguém vem acertando.
 *
 * Os jogos diários já guardavam um `streak_count` cada um, mas não em todos
 * (o Quem Sou Eu e o Quiz não têm) e cada um atualizando do seu jeito. Aqui
 * a sequência é DERIVADA das datas de acerto, com a mesma definição pros
 * cinco: dias de calendário consecutivos em que a pessoa acertou.
 *
 * Derivar em vez de ler a coluna tem um motivo prático: um `streak_count`
 * gravado errado uma vez fica errado pra sempre, e ninguém percebe. A conta
 * a partir das datas sempre reflete o que realmente aconteceu.
 *
 * ── Como a conta funciona ───────────────────────────────────────────────
 *
 * O truque é numerar os dias de acerto por pessoa e subtrair o número da
 * data. Dias consecutivos dão sempre a MESMA data ao subtrair, então cada
 * "ilha" de dias seguidos vira um grupo, e o tamanho do grupo é a sequência:
 *
 *   acertou em   10/03  11/03  12/03   ...   15/03  16/03
 *   número (n)      1      2      3            4      5
 *   data - n     09/03  09/03  09/03         11/03  11/03
 *                └──── sequência de 3 ────┘  └── de 2 ──┘
 */

/**
 * Os cinco jogos diários, e o que conta como acerto em cada um.
 *
 * Cada entrada devolve (id_usuario, dia) só das partidas ACERTADAS — o
 * resto da conta é igual pra todos.
 */
function seqJogos(): array
{
    return [
        'termo' => [
            'nome'  => 'Termo',
            'icone' => 'bi-fonts',
            'cor'   => '#22c55e',
            'sql'   => "SELECT id_usuario, data_jogo AS dia
                          FROM termo_historico WHERE ganhou = 1",
        ],
        'dueto' => [
            'nome'  => 'Termo Dueto',
            'icone' => 'bi-fonts',
            'cor'   => '#14b8a6',
            // Acerto no dueto é fechar AS DUAS palavras. Uma só é meio
            // acerto, e meio acerto não sustenta sequência.
            'sql'   => "SELECT id_usuario, data_jogo AS dia
                          FROM termo_dueto_historico
                         WHERE (COALESCE(ganhou_1,0) + COALESCE(ganhou_2,0)) >= 2",
        ],
        'memoria' => [
            'nome'  => 'Memória',
            'icone' => 'bi-grid-3x3-gap-fill',
            'cor'   => '#a855f7',
            // A Memória não tem "ganhou": quem conclui recebe pontos, e é
            // isso que a retrospectiva também usa como acerto.
            'sql'   => "SELECT id_usuario, data_jogo AS dia
                          FROM memoria_historico WHERE pontos_ganhos > 0",
        ],
        'quemsoueu' => [
            'nome'  => 'Quem Sou Eu?',
            'icone' => 'bi-question-circle',
            'cor'   => '#3b82f6',
            'sql'   => "SELECT id_usuario, data_jogo AS dia
                          FROM quemsoueu_partidas WHERE resolvido = 1",
        ],
        'quiz' => [
            'nome'  => 'Quiz do Dia',
            'icone' => 'bi-chat-square-quote',
            'cor'   => '#eab308',
            // Vencer o Quiz é votar com a maioria. A fonte é `vencedoras`
            // (as opções que ganharam o dia) e não o `pago` do voto: pago é
            // consequência do pagamento, e um pagamento que falhou não
            // significa que a pessoa errou.
            'sql'   => "SELECT v.id_usuario, p.data_uso AS dia
                          FROM quiz_votos v
                          JOIN quiz_perguntas p ON p.id = v.pergunta_id
                         WHERE p.data_uso IS NOT NULL
                           AND p.vencedoras IS NOT NULL
                           AND JSON_CONTAINS(p.vencedoras, CAST(v.opcao AS CHAR))",
        ],
    ];
}

/**
 * O top de sequências de um jogo.
 *
 * Devolve, por pessoa, a MAIOR sequência que ela já emendou e se ela ainda
 * está viva. Viva = terminou hoje ou ontem; qualquer coisa antes disso já
 * foi quebrada, porque o jogo é diário.
 *
 * Ontem também conta porque quem ainda não jogou hoje não perdeu a
 * sequência — só não a renovou ainda. Marcar como quebrada às 00h01 seria
 * dar por perdida uma sequência que a pessoa ainda pode manter no mesmo dia.
 *
 * @return array<int,array{user_id:int,nome:string,foto:string,melhor:int,atual:int}>
 */
function seqTop(PDO $pdo, string $chave, int $limite = 5): array
{
    $jogos = seqJogos();
    if (!isset($jogos[$chave])) return [];

    $sql = "
        SELECT ilhas.id_usuario,
               MAX(ilhas.n)                                        AS melhor,
               COALESCE(MAX(CASE WHEN ilhas.fim >= CURDATE() - INTERVAL 1 DAY
                                 THEN ilhas.n END), 0)             AS atual
          FROM (
                SELECT numerado.id_usuario,
                       COUNT(*)        AS n,
                       MAX(numerado.dia) AS fim
                  FROM (
                        SELECT d.id_usuario,
                               d.dia,
                               -- A data menos a posição do dia: constante
                               -- dentro de uma sequência (ver o cabeçalho).
                               d.dia - INTERVAL ROW_NUMBER() OVER (
                                   PARTITION BY d.id_usuario ORDER BY d.dia
                               ) DAY AS ilha
                          FROM ( {$jogos[$chave]['sql']} ) d
                       ) numerado
                 GROUP BY numerado.id_usuario, numerado.ilha
               ) ilhas
         GROUP BY ilhas.id_usuario
         HAVING melhor > 1
         ORDER BY melhor DESC, atual DESC
         LIMIT " . max(1, $limite);

    try {
        $linhas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Tabela que ainda não existe (o jogo nunca foi jogado neste banco)
        // não é erro: é um card vazio. Vale pro ambiente novo e pro jogo que
        // acabou de entrar no ar.
        error_log('[sequencias] ' . $chave . ': ' . $e->getMessage());
        return [];
    }
    if (!$linhas) return [];

    // Os nomes numa consulta só. Uma por pessoa seriam 25 idas ao banco pra
    // desenhar cinco cards.
    $ids = array_map(fn($l) => (int)$l['id_usuario'], $linhas);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare("SELECT g.id, COALESCE(g.nome, u.name, 'GM') AS nome, u.photo_url
                            FROM games_usuarios g
                            LEFT JOIN users u ON u.id = g.id
                           WHERE g.id IN ($ph)");
    $st->execute($ids);
    $gente = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) $gente[(int)$g['id']] = $g;

    $out = [];
    foreach ($linhas as $l) {
        $id = (int)$l['id_usuario'];
        if (!isset($gente[$id])) continue;   // perfil apagado
        $out[] = [
            'user_id' => $id,
            'nome'    => $gente[$id]['nome'],
            'foto'    => function_exists('getUserPhoto')
                         ? getUserPhoto($gente[$id]['photo_url'])
                         : ($gente[$id]['photo_url'] ?: '/img/default-avatar.png'),
            'melhor'  => (int)$l['melhor'],
            'atual'   => (int)$l['atual'],
        ];
    }
    return $out;
}

/** Os cinco de uma vez, prontos pra tela. */
function seqTodos(PDO $pdo, int $limite = 5): array
{
    $out = [];
    foreach (seqJogos() as $k => $meta) {
        $out[$k] = ['meta' => $meta, 'top' => seqTop($pdo, $k, $limite)];
    }
    return $out;
}
