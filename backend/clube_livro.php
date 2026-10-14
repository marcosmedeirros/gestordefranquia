<?php
/**
 * ── CLUBE DO LIVRO: A ABA QUE SÓ EXISTE PRA QUEM PEDIU ───────────────
 *
 * Também da Agata (07/10/2026): "teria que ser uma aba que só aparece para
 * quem clicou lá que queria". Então a aba não está na barra de ninguém por
 * padrão — existe um convite na aba Da Semana, e quem aceita passa a ver o
 * Clube do Livro como aba sua. Sair é um link discreto lá dentro, e a aba
 * some de novo.
 *
 * O ritmo é MENSAL ("como é mix mensal vai ter mais tempo"): um livro por
 * vez, resenhas de quem leu, e enquetes pra decidir o rumo — a primeira já
 * nasce com o clube, perguntando os gêneros que a galera curte, porque foi
 * exatamente o que eles pediram: "enquete de gênero, aí depois vem os
 * livros".
 *
 * Quem manda no clube (define o livro, cria e fecha enquete) é o admin do
 * site. As enquetes são genéricas de propósito: a de gênero é a primeira,
 * a dos livros candidatos vem depois pelo mesmo formulário, sem código novo.
 */

require_once __DIR__ . '/db.php';

function clubeLivroTabelas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS clube_livro_membros (
        user_id INT PRIMARY KEY,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS clube_livro_enquetes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pergunta VARCHAR(200) NOT NULL,
        multi TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('aberta','fechada') NOT NULL DEFAULT 'aberta',
        criado_por INT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS clube_livro_enquete_opcoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        enquete_id INT NOT NULL,
        texto VARCHAR(140) NOT NULL,
        KEY idx_enq (enquete_id)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS clube_livro_enquete_votos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        enquete_id INT NOT NULL,
        opcao_id INT NOT NULL,
        user_id INT NOT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_voto (enquete_id, opcao_id, user_id)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS clube_livro_livros (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titulo VARCHAR(200) NOT NULL,
        autor VARCHAR(160) NOT NULL,
        descricao TEXT NULL,
        status ENUM('atual','passado') NOT NULL DEFAULT 'atual',
        criado_por INT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS clube_livro_resenhas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        livro_id INT NOT NULL,
        user_id INT NOT NULL,
        nota TINYINT NULL,
        texto TEXT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NULL,
        UNIQUE KEY uq_resenha (livro_id, user_id)
    ) DEFAULT CHARSET=utf8mb4");

    /* ── ONDE A PESQUISA APARECE ─────────────────────────────────────
       A enquete nasceu dentro do Clube do Livro e por isso mora nestas
       tabelas. Com a capa do Clube (14/10/2026) ela passou a servir à liga
       toda — "um popup que abre pro admin criar as pesquisas" —, e o que
       separa uma coisa da outra é esta coluna: 'livro' só aparece na aba de
       quem entrou no clube do livro, 'clube' aparece na capa pra todo mundo.

       O padrão é 'livro' porque é o que as enquetes antigas são; mudar o
       default mudaria o passado. */
    try {
        $tem = $pdo->query("SELECT COUNT(*) FROM information_schema.columns
                             WHERE table_schema = DATABASE()
                               AND table_name = 'clube_livro_enquetes'
                               AND column_name = 'escopo'")->fetchColumn();
        if (!$tem) {
            $pdo->exec("ALTER TABLE clube_livro_enquetes
                        ADD COLUMN escopo ENUM('livro','clube') NOT NULL DEFAULT 'livro'");
        }
    } catch (Throwable $e) {
        error_log('[clube-livro] migrar escopo: ' . $e->getMessage());
    }

    /* A ENQUETE DE GÊNERO NASCE COM O CLUBE, uma vez só. É a primeira coisa
       que a Agata pediu — sem ela a aba estrearia vazia, e ninguém volta a
       uma aba que estreou vazia. */
    try {
        if (!(int)$pdo->query('SELECT COUNT(*) FROM clube_livro_enquetes')->fetchColumn()) {
            $pdo->prepare("INSERT INTO clube_livro_enquetes (pergunta, multi) VALUES (?, 1)")
                ->execute(['Que gêneros você curte ler? (marque quantos quiser)']);
            $eid = (int)$pdo->lastInsertId();
            $op = $pdo->prepare('INSERT INTO clube_livro_enquete_opcoes (enquete_id, texto) VALUES (?,?)');
            foreach (['Romance', 'Fantasia', 'Ficção científica', 'Mistério e suspense',
                      'Terror', 'Drama', 'Clássicos', 'Biografia e não-ficção',
                      'Quadrinhos e mangás', 'Desenvolvimento pessoal'] as $g) {
                $op->execute([$eid, $g]);
            }
        }
    } catch (Throwable $e) {
        error_log('[clube-livro] semear enquete: ' . $e->getMessage());
    }
    $feito = true;
}

function clubeLivroEhMembro(PDO $pdo, int $userId): bool
{
    clubeLivroTabelas($pdo);
    $st = $pdo->prepare('SELECT 1 FROM clube_livro_membros WHERE user_id = ?');
    $st->execute([$userId]);
    return (bool)$st->fetchColumn();
}

function clubeLivroEntrar(PDO $pdo, int $userId): void
{
    clubeLivroTabelas($pdo);
    $pdo->prepare('INSERT IGNORE INTO clube_livro_membros (user_id) VALUES (?)')->execute([$userId]);
}

function clubeLivroSair(PDO $pdo, int $userId): void
{
    clubeLivroTabelas($pdo);
    /* Sai da lista, mas resenhas e votos ficam: opinião dada é história do
       clube, não posse de quem saiu. Voltar recupera tudo como estava. */
    $pdo->prepare('DELETE FROM clube_livro_membros WHERE user_id = ?')->execute([$userId]);
}

function clubeLivroMembros(PDO $pdo): array
{
    clubeLivroTabelas($pdo);
    return $pdo->query("SELECT u.id, u.name, u.photo_url
                          FROM clube_livro_membros m JOIN users u ON u.id = m.user_id
                      ORDER BY m.criado_em ASC")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * As enquetes (abertas primeiro), com contagem e os meus votos.
 *
 * @param string $escopo 'livro' (a aba do clube do livro) ou 'clube' (a capa).
 *               @see a coluna `escopo` em clubeLivroTabelas
 */
function clubeLivroEnquetes(PDO $pdo, int $userId, string $escopo = 'livro'): array
{
    clubeLivroTabelas($pdo);
    $escopo = $escopo === 'clube' ? 'clube' : 'livro';

    /* ── A ENQUETE FECHADA SÓ SAI DA TELA QUANDO JÁ TEM LIVRO ─────────
       Pedido do Marcos (08/10/2026): "APOS ESCOLHER O LIVRO ou musica, fecha
       as enquetes, nao mostra mais elas, so o do livro atual". A aba virava
       um empilhado de votações velhas em cima do que importa, que é o livro
       de agora — e some mesmo, assim que o livro existe.

       Eu tinha lido isso como "nunca mostrar enquete fechada", e é mais
       largo do que o pedido. A diferença apareceu no dia seguinte, pela
       Agata: "sumiu a enquete dos gêneros q o pessoal mais gosta". E tinha
       sumido: alguém encerrou a enquete de gênero — 16 pessoas já tinham
       votado, a última às 18h42 — e, SEM NENHUM LIVRO ESCOLHIDO AINDA, ela
       saiu da tela levando junto o resultado. Que é exatamente o que o clube
       estava construindo pra poder escolher o livro.

       Então o gatilho volta a ser o do pedido: existe livro atual, a votação
       velha sai da frente dele. Não existe, ela fica — encerrada, sem botão,
       mostrando no que o pessoal votou. Encerrar passa a querer dizer
       "congelei o resultado", e não "apaguei a enquete da tela".

       ── E ISSO É REGRA DO LIVRO, NÃO DA CAPA. Na capa não existe "o livro
       de agora" pra tirar a votação velha da frente; lá o que manda é o
       escopo, e a pesquisa encerrada fica mostrando o resultado até alguém
       criar outra. */
    $temLivro = $escopo === 'livro' && (bool)$pdo->query("SELECT 1 FROM clube_livro_livros
                                    WHERE status = 'atual' LIMIT 1")->fetchColumn();
    $onde = $temLivro ? "AND status = 'aberta'" : '';
    $st = $pdo->prepare("SELECT id, pergunta, multi, status FROM clube_livro_enquetes
                          WHERE escopo = ? {$onde}
                          ORDER BY (status = 'aberta') DESC, id DESC LIMIT 8");
    $st->execute([$escopo]);
    $enquetes = $st->fetchAll(PDO::FETCH_ASSOC);
    $ops = $pdo->prepare("SELECT o.id, o.texto, COUNT(v.id) votos
                            FROM clube_livro_enquete_opcoes o
                        LEFT JOIN clube_livro_enquete_votos v ON v.opcao_id = o.id
                           WHERE o.enquete_id = ? GROUP BY o.id ORDER BY o.id");
    $meus = $pdo->prepare('SELECT opcao_id FROM clube_livro_enquete_votos
                            WHERE enquete_id = ? AND user_id = ?');
    foreach ($enquetes as &$e) {
        $ops->execute([(int)$e['id']]);
        $e['opcoes'] = $ops->fetchAll(PDO::FETCH_ASSOC);
        $e['total'] = array_sum(array_column($e['opcoes'], 'votos'));
        $meus->execute([(int)$e['id'], $userId]);
        $e['meus'] = array_map('intval', $meus->fetchAll(PDO::FETCH_COLUMN));
        $st = $pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM clube_livro_enquete_votos WHERE enquete_id = ?');
        $st->execute([(int)$e['id']]);
        $e['pessoas'] = (int)$st->fetchColumn();
    }
    return $enquetes;
}

/**
 * Vota. Na enquete multi o voto é um liga-desliga por opção; na simples,
 * votar numa opção solta a anterior — trocar de ideia não pode exigir
 * procedimento.
 */
function clubeLivroVotar(PDO $pdo, int $userId, int $enqueteId, int $opcaoId): bool
{
    clubeLivroTabelas($pdo);
    try {
        $st = $pdo->prepare("SELECT e.multi FROM clube_livro_enquetes e
                              JOIN clube_livro_enquete_opcoes o ON o.enquete_id = e.id AND o.id = ?
                             WHERE e.id = ? AND e.status = 'aberta'");
        $st->execute([$opcaoId, $enqueteId]);
        $multi = $st->fetchColumn();
        if ($multi === false) return false;

        if ((int)$multi === 1) {
            $del = $pdo->prepare('DELETE FROM clube_livro_enquete_votos
                                   WHERE enquete_id = ? AND opcao_id = ? AND user_id = ?');
            $del->execute([$enqueteId, $opcaoId, $userId]);
            if ($del->rowCount() > 0) return true;   // tinha: era um desliga
            $pdo->prepare('INSERT IGNORE INTO clube_livro_enquete_votos (enquete_id, opcao_id, user_id)
                           VALUES (?,?,?)')->execute([$enqueteId, $opcaoId, $userId]);
            return true;
        }
        $pdo->prepare('DELETE FROM clube_livro_enquete_votos WHERE enquete_id = ? AND user_id = ?')
            ->execute([$enqueteId, $userId]);
        $pdo->prepare('INSERT INTO clube_livro_enquete_votos (enquete_id, opcao_id, user_id)
                       VALUES (?,?,?)')->execute([$enqueteId, $opcaoId, $userId]);
        return true;
    } catch (Throwable $e) {
        error_log('[clube-livro] votar: ' . $e->getMessage());
        return false;
    }
}

function clubeLivroCriarEnquete(PDO $pdo, int $userId, string $pergunta, array $opcoes,
                                bool $multi, string $escopo = 'livro'): bool
{
    clubeLivroTabelas($pdo);
    $pergunta = trim(mb_substr($pergunta, 0, 200));
    $opcoes = array_values(array_filter(array_map(fn($o) => trim(mb_substr((string)$o, 0, 140)), $opcoes)));
    if ($pergunta === '' || count($opcoes) < 2) return false;
    $escopo = $escopo === 'clube' ? 'clube' : 'livro';
    try {
        $pdo->prepare('INSERT INTO clube_livro_enquetes (pergunta, multi, escopo, criado_por)
                       VALUES (?,?,?,?)')
            ->execute([$pergunta, $multi ? 1 : 0, $escopo, $userId]);
        $eid = (int)$pdo->lastInsertId();
        $op = $pdo->prepare('INSERT INTO clube_livro_enquete_opcoes (enquete_id, texto) VALUES (?,?)');
        foreach (array_slice($opcoes, 0, 20) as $o) $op->execute([$eid, $o]);
        return true;
    } catch (Throwable $e) {
        error_log('[clube-livro] criar enquete: ' . $e->getMessage());
        return false;
    }
}

function clubeLivroFecharEnquete(PDO $pdo, int $enqueteId): void
{
    clubeLivroTabelas($pdo);
    $pdo->prepare("UPDATE clube_livro_enquetes SET status = 'fechada' WHERE id = ?")
        ->execute([$enqueteId]);
}

/**
 * Reabre uma enquete encerrada.
 *
 * Fechar era o único caminho de mão única da aba: quem clicasse sem querer —
 * ou quem fechasse e depois visse que faltava gente votar — não tinha como
 * voltar pela tela. Os votos nunca foram apagados, só a votação é que parava;
 * reabrir devolve o botão e continua de onde estava, sem ninguém perder o que
 * já tinha marcado. @see clubeLivroFecharEnquete
 */
function clubeLivroReabrirEnquete(PDO $pdo, int $enqueteId): void
{
    clubeLivroTabelas($pdo);
    $pdo->prepare("UPDATE clube_livro_enquetes SET status = 'aberta' WHERE id = ?")
        ->execute([$enqueteId]);
}

/**
 * ONDE UMA PESQUISA MORA — 'livro', 'clube', ou null se ela não existe.
 *
 * É o que decide QUEM pode votar nela. A tela do livro tranca tudo atrás de
 * "sou membro ou sou admin", e com razão: lá dentro é o clube deles. A
 * pesquisa da capa é da liga inteira, e usar a mesma tranca faria a caixa
 * aparecer pra todo mundo e só funcionar pros dezessete do livro.
 */
function clubeLivroEnqueteEscopo(PDO $pdo, int $enqueteId): ?string
{
    clubeLivroTabelas($pdo);
    $st = $pdo->prepare('SELECT escopo FROM clube_livro_enquetes WHERE id = ?');
    $st->execute([$enqueteId]);
    $e = $st->fetchColumn();
    return $e === false ? null : (string)$e;
}

/**
 * TODAS AS PESQUISAS, pro painel do admin — as duas casas na mesma lista.
 *
 * Sem filtro de livro atual e sem esconder encerrada: aqui é onde se
 * administra, e administrar uma coisa que a tela esconde é adivinhação.
 */
function clubeLivroPesquisasDoAdmin(PDO $pdo, int $limite = 30): array
{
    clubeLivroTabelas($pdo);
    $st = $pdo->query("SELECT e.id, e.pergunta, e.multi, e.status, e.escopo, e.criado_em,
                              (SELECT COUNT(DISTINCT v.user_id) FROM clube_livro_enquete_votos v
                                WHERE v.enquete_id = e.id) AS pessoas
                         FROM clube_livro_enquetes e
                     ORDER BY (e.status = 'aberta') DESC, e.id DESC
                        LIMIT " . max(1, min(100, $limite)));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** O livro em leitura, com resenhas, média e a minha. */
function clubeLivroAtual(PDO $pdo, int $userId): ?array
{
    clubeLivroTabelas($pdo);
    $l = $pdo->query("SELECT id, titulo, autor, descricao, criado_em
                        FROM clube_livro_livros WHERE status = 'atual'
                    ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$l) return null;

    $st = $pdo->prepare("SELECT r.nota, r.texto, r.criado_em, r.user_id, u.name, u.photo_url
                           FROM clube_livro_resenhas r JOIN users u ON u.id = r.user_id
                          WHERE r.livro_id = ? AND (r.texto IS NOT NULL AND r.texto <> '' OR r.nota IS NOT NULL)
                       ORDER BY r.id DESC LIMIT 60");
    $st->execute([(int)$l['id']]);
    $l['resenhas'] = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare('SELECT nota, texto FROM clube_livro_resenhas WHERE livro_id = ? AND user_id = ?');
    $st->execute([(int)$l['id'], $userId]);
    $l['minha'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    $notas = array_filter(array_column($l['resenhas'], 'nota'), fn($n) => $n !== null);
    $l['media'] = $notas ? round(array_sum($notas) / count($notas), 1) : null;
    return $l;
}

/** Os livros que o clube já leu, com a média das resenhas. */
function clubeLivroPassados(PDO $pdo): array
{
    clubeLivroTabelas($pdo);
    return $pdo->query("SELECT l.id, l.titulo, l.autor,
                               ROUND(AVG(r.nota), 1) media, COUNT(r.id) resenhas
                          FROM clube_livro_livros l
                      LEFT JOIN clube_livro_resenhas r ON r.livro_id = l.id
                         WHERE l.status = 'passado'
                      GROUP BY l.id ORDER BY l.id DESC LIMIT 24")->fetchAll(PDO::FETCH_ASSOC);
}

/** Define o livro do mês; o anterior vai pra estante dos lidos. */
function clubeLivroDefinir(PDO $pdo, int $userId, string $titulo, string $autor, string $descricao): bool
{
    clubeLivroTabelas($pdo);
    $titulo = trim(mb_substr($titulo, 0, 200));
    $autor = trim(mb_substr($autor, 0, 160));
    if ($titulo === '' || $autor === '') return false;
    try {
        $pdo->prepare("UPDATE clube_livro_livros SET status = 'passado' WHERE status = 'atual'")->execute();
        $pdo->prepare('INSERT INTO clube_livro_livros (titulo, autor, descricao, criado_por)
                       VALUES (?,?,?,?)')
            ->execute([$titulo, $autor, trim(mb_substr($descricao, 0, 2000)) ?: null, $userId]);
        return true;
    } catch (Throwable $e) {
        error_log('[clube-livro] definir: ' . $e->getMessage());
        return false;
    }
}

/** Registra (ou atualiza) a resenha de um membro. */
function clubeLivroResenhar(PDO $pdo, int $userId, int $livroId, ?int $nota, string $texto): bool
{
    clubeLivroTabelas($pdo);
    $texto = trim(mb_substr($texto, 0, 3000));
    if ($nota !== null) $nota = max(0, min(10, $nota));
    if ($texto === '' && $nota === null) return false;
    try {
        $st = $pdo->prepare('SELECT 1 FROM clube_livro_livros WHERE id = ?');
        $st->execute([$livroId]);
        if (!$st->fetchColumn()) return false;
        $pdo->prepare('INSERT INTO clube_livro_resenhas (livro_id, user_id, nota, texto)
                       VALUES (?,?,?,?)
                       ON DUPLICATE KEY UPDATE nota = VALUES(nota), texto = VALUES(texto),
                                               atualizado_em = NOW()')
            ->execute([$livroId, $userId, $nota, $texto !== '' ? $texto : null]);
        return true;
    } catch (Throwable $e) {
        error_log('[clube-livro] resenhar: ' . $e->getMessage());
        return false;
    }
}
