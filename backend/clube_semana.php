<?php
/**
 * ── DA SEMANA: O ÁLBUM E O FILME QUE A LIGA ESCOLHE JUNTO ────────────
 *
 * A ideia é da Agata (07/10/2026): uma aba com o álbum da semana e o filme
 * da semana. Dez opções tiradas das listas de melhores, a liga vota, e quem
 * vence vira o programa da semana — todo mundo tem sete dias pra ouvir e
 * ver, e deixar a opinião registrada.
 *
 * ── O RELÓGIO É O DOMINGO, E NINGUÉM PRECISA APERTAR NADA ────────────
 *
 * "Todo domingo a gente abre o ranking." Então o ciclo é esse:
 *
 *   · a votação da semana N abre no domingo da semana N-1 e fica aberta a
 *     semana inteira — mais gente vota, e votar também é parte da graça;
 *   · no domingo seguinte ela se decide sozinha, o vencedor vira "o da
 *     semana", e a votação da próxima abre no mesmo instante.
 *
 * Sempre há uma escolha em cartaz e uma votação aberta. A virada é
 * PREGUIÇOSA, no primeiro acesso depois do domingo — mesmo desenho do
 * leilão do jogo da semana, e pelo mesmo motivo: cron pra isso é mais uma
 * coisa pra esquecer de agendar.
 *
 * Na primeira semana de vida não existe "em cartaz" (ninguém votou ainda):
 * a tela mostra só as votações e diz que o primeiro vencedor sai domingo.
 * É melhor do que estrear com um sorteio que ninguém escolheu.
 *
 * ── DE ONDE SAEM AS OPÇÕES ───────────────────────────────────────────
 *
 * De listas fixas aqui embaixo, montadas do cânone (Rolling Stone, IMDb,
 * e uma dose proposital de Brasil). Dez por semana, sem repetir o que já
 * concorreu — e quando o estoque de inéditos acabar, volta a concorrer
 * quem nunca venceu. Vencedor não concorre de novo nunca: o clube é pra
 * conhecer coisa nova, não pra reeleger o óbvio.
 */

require_once __DIR__ . '/db.php';

/** Álbuns: [título, artista, ano]. O cânone com sotaque. */
const CLUBE_ALBUNS = [
    ['Abbey Road', 'The Beatles', 1969],
    ['The Dark Side of the Moon', 'Pink Floyd', 1973],
    ['Thriller', 'Michael Jackson', 1982],
    ['Clube da Esquina', 'Milton Nascimento e Lô Borges', 1972],
    ['Acabou Chorare', 'Novos Baianos', 1972],
    ['Construção', 'Chico Buarque', 1971],
    ['Elis & Tom', 'Elis Regina e Tom Jobim', 1974],
    ['Chega de Saudade', 'João Gilberto', 1959],
    ['Tropicália ou Panis et Circencis', 'Vários', 1968],
    ['Secos & Molhados', 'Secos & Molhados', 1973],
    ['Expresso 2222', 'Gilberto Gil', 1972],
    ['Transa', 'Caetano Veloso', 1972],
    ['Racional Pt. 1', 'Tim Maia', 1975],
    ['Sobrevivendo no Inferno', 'Racionais MCs', 1997],
    ['Da Lama ao Caos', 'Chico Science & Nação Zumbi', 1994],
    ['O Dia em que a Terra Parou', 'Raul Seixas', 1977],
    ['Cabeça Dinossauro', 'Titãs', 1986],
    ['Dois', 'Legião Urbana', 1986],
    ['Nevermind', 'Nirvana', 1991],
    ['OK Computer', 'Radiohead', 1997],
    ['Kid A', 'Radiohead', 2000],
    ['Pet Sounds', 'The Beach Boys', 1966],
    ['What\'s Going On', 'Marvin Gaye', 1971],
    ['Songs in the Key of Life', 'Stevie Wonder', 1976],
    ['Rumours', 'Fleetwood Mac', 1977],
    ['Back in Black', 'AC/DC', 1980],
    ['The Chronic', 'Dr. Dre', 1992],
    ['Illmatic', 'Nas', 1994],
    ['To Pimp a Butterfly', 'Kendrick Lamar', 2015],
    ['good kid, m.A.A.d city', 'Kendrick Lamar', 2012],
    ['My Beautiful Dark Twisted Fantasy', 'Kanye West', 2010],
    ['Blonde', 'Frank Ocean', 2016],
    ['Lemonade', 'Beyoncé', 2016],
    ['21', 'Adele', 2011],
    ['Random Access Memories', 'Daft Punk', 2013],
    ['Discovery', 'Daft Punk', 2001],
    ['Is This It', 'The Strokes', 2001],
    ['AM', 'Arctic Monkeys', 2013],
    ['In Rainbows', 'Radiohead', 2007],
    ['Led Zeppelin IV', 'Led Zeppelin', 1971],
    ['A Night at the Opera', 'Queen', 1975],
    ['The Wall', 'Pink Floyd', 1979],
    ['Appetite for Destruction', 'Guns N\' Roses', 1987],
    ['Master of Puppets', 'Metallica', 1986],
    ['Paranoid', 'Black Sabbath', 1970],
    ['London Calling', 'The Clash', 1979],
    ['Purple Rain', 'Prince', 1984],
    ['Like a Prayer', 'Madonna', 1989],
    ['The Miseducation of Lauryn Hill', 'Lauryn Hill', 1998],
    ['Baduizm', 'Erykah Badu', 1997],
    ['Voodoo', 'D\'Angelo', 2000],
    ['Channel Orange', 'Frank Ocean', 2012],
    ['Currents', 'Tame Impala', 2015],
    ['Un Verano Sin Ti', 'Bad Bunny', 2022],
    ['SOUR', 'Olivia Rodrigo', 2021],
    ['1989', 'Taylor Swift', 2014],
    ['folklore', 'Taylor Swift', 2020],
    ['After Hours', 'The Weeknd', 2020],
    ['Astroworld', 'Travis Scott', 2018],
    ['Damn', 'Kendrick Lamar', 2017],
    ['Getz/Gilberto', 'Stan Getz e João Gilberto', 1964],
    ['Kind of Blue', 'Miles Davis', 1959],
    ['A Tábua de Esmeralda', 'Jorge Ben Jor', 1974],
    ['África Brasil', 'Jorge Ben Jor', 1976],
    ['Vento de Maio', 'Elis Regina', 1978],
    ['As Quatro Estações', 'Legião Urbana', 1989],
    ['Bloco do Eu Sozinho', 'Los Hermanos', 2001],
    ['Ventura', 'Los Hermanos', 2003],
    ['Ideologia', 'Cazuza', 1988],
    ['Nós', 'O Terno', 2016],
];

/** Filmes: [título, diretor, ano]. Cânone + os que rendem conversa. */
const CLUBE_FILMES = [
    ['O Poderoso Chefão', 'Francis Ford Coppola', 1972],
    ['Cidade de Deus', 'Fernando Meirelles', 2002],
    ['Central do Brasil', 'Walter Salles', 1998],
    ['Tropa de Elite', 'José Padilha', 2007],
    ['O Auto da Compadecida', 'Guel Arraes', 2000],
    ['Bacurau', 'Kleber Mendonça Filho', 2019],
    ['Ainda Estou Aqui', 'Walter Salles', 2024],
    ['Que Horas Ela Volta?', 'Anna Muylaert', 2015],
    ['Pulp Fiction', 'Quentin Tarantino', 1994],
    ['Clube da Luta', 'David Fincher', 1999],
    ['Interestelar', 'Christopher Nolan', 2014],
    ['A Origem', 'Christopher Nolan', 2010],
    ['Batman: O Cavaleiro das Trevas', 'Christopher Nolan', 2008],
    ['Parasita', 'Bong Joon-ho', 2019],
    ['Oldboy', 'Park Chan-wook', 2003],
    ['A Viagem de Chihiro', 'Hayao Miyazaki', 2001],
    ['Meu Amigo Totoro', 'Hayao Miyazaki', 1988],
    ['Akira', 'Katsuhiro Otomo', 1988],
    ['Matrix', 'Lana e Lilly Wachowski', 1999],
    ['De Volta para o Futuro', 'Robert Zemeckis', 1985],
    ['Forrest Gump', 'Robert Zemeckis', 1994],
    ['Um Sonho de Liberdade', 'Frank Darabont', 1994],
    ['O Silêncio dos Inocentes', 'Jonathan Demme', 1991],
    ['Seven', 'David Fincher', 1995],
    ['A Rede Social', 'David Fincher', 2010],
    ['Os Bons Companheiros', 'Martin Scorsese', 1990],
    ['Taxi Driver', 'Martin Scorsese', 1976],
    ['O Lobo de Wall Street', 'Martin Scorsese', 2013],
    ['Laranja Mecânica', 'Stanley Kubrick', 1971],
    ['O Iluminado', 'Stanley Kubrick', 1980],
    ['2001: Uma Odisseia no Espaço', 'Stanley Kubrick', 1968],
    ['Blade Runner', 'Ridley Scott', 1982],
    ['Alien', 'Ridley Scott', 1979],
    ['O Exterminador do Futuro 2', 'James Cameron', 1991],
    ['Titanic', 'James Cameron', 1997],
    ['E.T.', 'Steven Spielberg', 1982],
    ['Jurassic Park', 'Steven Spielberg', 1993],
    ['A Lista de Schindler', 'Steven Spielberg', 1993],
    ['Indiana Jones e os Caçadores da Arca Perdida', 'Steven Spielberg', 1981],
    ['Star Wars: O Império Contra-Ataca', 'Irvin Kershner', 1980],
    ['O Senhor dos Anéis: A Sociedade do Anel', 'Peter Jackson', 2001],
    ['Mad Max: Estrada da Fúria', 'George Miller', 2015],
    ['Gladiador', 'Ridley Scott', 2000],
    ['Whiplash', 'Damien Chazelle', 2014],
    ['La La Land', 'Damien Chazelle', 2016],
    ['Her', 'Spike Jonze', 2013],
    ['Brilho Eterno de uma Mente sem Lembranças', 'Michel Gondry', 2004],
    ['O Grande Hotel Budapeste', 'Wes Anderson', 2014],
    ['O Fabuloso Destino de Amélie Poulain', 'Jean-Pierre Jeunet', 2001],
    ['A Vida é Bela', 'Roberto Benigni', 1997],
    ['Coringa', 'Todd Phillips', 2019],
    ['Corra!', 'Jordan Peele', 2017],
    ['Hereditário', 'Ari Aster', 2018],
    ['O Labirinto do Fauno', 'Guillermo del Toro', 2006],
    ['Homem-Aranha no Aranhaverso', 'Ramsey, Persichetti e Rothman', 2018],
    ['Toy Story', 'John Lasseter', 1995],
    ['Divertida Mente', 'Pete Docter', 2015],
    ['Up: Altas Aventuras', 'Pete Docter', 2009],
    ['O Rei Leão', 'Allers e Minkoff', 1994],
    ['Shrek', 'Adamson e Jenson', 2001],
    ['Kill Bill: Volume 1', 'Quentin Tarantino', 2003],
    ['Django Livre', 'Quentin Tarantino', 2012],
    ['Bastardos Inglórios', 'Quentin Tarantino', 2009],
    ['Crepúsculo dos Deuses', 'Billy Wilder', 1950],
    ['Psicose', 'Alfred Hitchcock', 1960],
    ['Janela Indiscreta', 'Alfred Hitchcock', 1954],
    ['Duna: Parte 2', 'Denis Villeneuve', 2024],
    ['A Chegada', 'Denis Villeneuve', 2016],
    ['Oppenheimer', 'Christopher Nolan', 2023],
    ['Tudo em Todo Lugar ao Mesmo Tempo', 'Daniels', 2022],
];

const CLUBE_SEMANA_OPCOES = 10;

/** O rótulo de cada tipo, do jeito que a tela fala. */
const CLUBE_SEMANA_TIPOS = [
    'album' => ['rot' => 'Álbum da semana', 'ico' => 'vinyl-fill',  'verbo' => 'ouvir',   'por' => 'de'],
    'filme' => ['rot' => 'Filme da semana', 'ico' => 'film',        'verbo' => 'assistir', 'por' => 'de'],
];

function clubeSemanaTabelas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS clube_semana_ciclos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo ENUM('album','filme') NOT NULL,
        semana DATE NOT NULL,
        status ENUM('votacao','definido') NOT NULL DEFAULT 'votacao',
        vencedor_opcao_id INT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_tipo_semana (tipo, semana)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS clube_semana_opcoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ciclo_id INT NOT NULL,
        titulo VARCHAR(160) NOT NULL,
        autor VARCHAR(160) NOT NULL,
        ano SMALLINT NULL,
        KEY idx_ciclo (ciclo_id)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS clube_semana_votos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ciclo_id INT NOT NULL,
        opcao_id INT NOT NULL,
        user_id INT NOT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_um_voto (ciclo_id, user_id)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS clube_semana_opinioes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ciclo_id INT NOT NULL,
        user_id INT NOT NULL,
        nota TINYINT NULL,
        texto TEXT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NULL,
        UNIQUE KEY uq_uma_opiniao (ciclo_id, user_id)
    ) DEFAULT CHARSET=utf8mb4");
    $feito = true;
}

/** O domingo da semana corrente (+n semanas). Semana aqui começa no domingo. */
function clubeSemanaDomingo(int $mais = 0): string
{
    $hoje = new DateTime('today', new DateTimeZone('America/Sao_Paulo'));
    $dow = (int)$hoje->format('w');            // 0 = domingo
    $hoje->modify("-{$dow} day");
    if ($mais !== 0) $hoje->modify(($mais > 0 ? '+' : '') . ($mais * 7) . ' day');
    return $hoje->format('Y-m-d');
}

/**
 * Sorteia as opções de um ciclo novo: inéditas primeiro, e quando o estoque
 * de inéditas acabar, volta quem concorreu e não venceu. Vencedor nunca.
 */
function clubeSemanaSortear(PDO $pdo, string $tipo): array
{
    $seed = $tipo === 'album' ? CLUBE_ALBUNS : CLUBE_FILMES;

    $st = $pdo->prepare("SELECT o.titulo,
                                MAX(o.id = c.vencedor_opcao_id) venceu
                           FROM clube_semana_opcoes o
                           JOIN clube_semana_ciclos c ON c.id = o.ciclo_id
                          WHERE c.tipo = ? GROUP BY o.titulo");
    $st->execute([$tipo]);
    $ja = [];
    foreach ($st as $r) $ja[mb_strtolower($r['titulo'])] = (bool)$r['venceu'];

    $ineditas = []; $repescagem = [];
    foreach ($seed as $s) {
        $k = mb_strtolower($s[0]);
        if (!isset($ja[$k]))      $ineditas[] = $s;
        elseif (!$ja[$k])         $repescagem[] = $s;
    }
    shuffle($ineditas);
    shuffle($repescagem);
    return array_slice(array_merge($ineditas, $repescagem), 0, CLUBE_SEMANA_OPCOES);
}

/**
 * Garante o relógio: decide o ciclo cuja semana chegou e abre a votação da
 * semana seguinte. Chamada em todo carregamento da aba; barata quando não
 * há nada a fazer.
 */
function clubeSemanaGirar(PDO $pdo): void
{
    clubeSemanaTabelas($pdo);
    $semanaQueVem = clubeSemanaDomingo(1);

    foreach (array_keys(CLUBE_SEMANA_TIPOS) as $tipo) {
        try {
            /* 1. Votação vencida se decide: todo ciclo de semana <= atual que
               ainda está em votação virou "da semana" (ou já passou). */
            $st = $pdo->prepare("SELECT id FROM clube_semana_ciclos
                                  WHERE tipo = ? AND status = 'votacao' AND semana <= ?");
            $st->execute([$tipo, clubeSemanaDomingo(0)]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cicloId) {
                clubeSemanaDecidir($pdo, (int)$cicloId);
            }

            /* 2. A votação da semana que vem existe. */
            $st = $pdo->prepare("SELECT id FROM clube_semana_ciclos WHERE tipo = ? AND semana = ?");
            $st->execute([$tipo, $semanaQueVem]);
            if (!$st->fetchColumn()) {
                $opcoes = clubeSemanaSortear($pdo, $tipo);
                if (!$opcoes) continue;        // estoque zerado: nada a abrir
                $pdo->prepare("INSERT INTO clube_semana_ciclos (tipo, semana) VALUES (?, ?)")
                    ->execute([$tipo, $semanaQueVem]);
                $cicloId = (int)$pdo->lastInsertId();
                $ins = $pdo->prepare("INSERT INTO clube_semana_opcoes (ciclo_id, titulo, autor, ano)
                                      VALUES (?,?,?,?)");
                foreach ($opcoes as $o) $ins->execute([$cicloId, $o[0], $o[1], $o[2]]);
            }
        } catch (Throwable $e) {
            error_log('[clube-semana] girar ' . $tipo . ': ' . $e->getMessage());
        }
    }
}

/** Fecha uma votação: mais votos vence; empate decide pelo que entrou antes. */
function clubeSemanaDecidir(PDO $pdo, int $cicloId): void
{
    $st = $pdo->prepare("SELECT o.id FROM clube_semana_opcoes o
                     LEFT JOIN clube_semana_votos v ON v.opcao_id = o.id
                         WHERE o.ciclo_id = ?
                      GROUP BY o.id ORDER BY COUNT(v.id) DESC, o.id ASC LIMIT 1");
    $st->execute([$cicloId]);
    $vencedora = $st->fetchColumn();
    if ($vencedora === false) return;
    $pdo->prepare("UPDATE clube_semana_ciclos SET status = 'definido', vencedor_opcao_id = ?
                    WHERE id = ? AND status = 'votacao'")->execute([(int)$vencedora, $cicloId]);
}

/**
 * Tudo que a aba precisa, por tipo: o que está em cartaz e a votação aberta.
 */
function clubeSemanaEstado(PDO $pdo, int $userId): array
{
    clubeSemanaGirar($pdo);
    $out = [];
    foreach (CLUBE_SEMANA_TIPOS as $tipo => $info) {
        $out[$tipo] = [
            'info'    => $info,
            'cartaz'  => clubeSemanaCartaz($pdo, $tipo, $userId),
            'votacao' => clubeSemanaVotacao($pdo, $tipo, $userId),
        ];
    }
    return $out;
}

/** O escolhido da semana corrente, com as opiniões — ou null na 1ª semana. */
function clubeSemanaCartaz(PDO $pdo, string $tipo, int $userId): ?array
{
    $st = $pdo->prepare("SELECT c.id ciclo, c.semana, o.titulo, o.autor, o.ano
                           FROM clube_semana_ciclos c
                           JOIN clube_semana_opcoes o ON o.id = c.vencedor_opcao_id
                          WHERE c.tipo = ? AND c.status = 'definido'
                       ORDER BY c.semana DESC LIMIT 1");
    $st->execute([$tipo]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) return null;

    $st = $pdo->prepare("SELECT op.nota, op.texto, op.criado_em, u.name, u.photo_url, op.user_id
                           FROM clube_semana_opinioes op JOIN users u ON u.id = op.user_id
                          WHERE op.ciclo_id = ? AND (op.texto IS NOT NULL AND op.texto <> '' OR op.nota IS NOT NULL)
                       ORDER BY op.id DESC LIMIT 60");
    $st->execute([(int)$c['ciclo']]);
    $c['opinioes'] = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare('SELECT nota, texto FROM clube_semana_opinioes WHERE ciclo_id = ? AND user_id = ?');
    $st->execute([(int)$c['ciclo'], $userId]);
    $c['minha'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    $notas = array_filter(array_column($c['opinioes'], 'nota'), fn($n) => $n !== null);
    $c['media'] = $notas ? round(array_sum($notas) / count($notas), 1) : null;
    return $c;
}

/** A votação aberta do tipo, com contagem e o meu voto. */
function clubeSemanaVotacao(PDO $pdo, string $tipo, int $userId): ?array
{
    $st = $pdo->prepare("SELECT id, semana FROM clube_semana_ciclos
                          WHERE tipo = ? AND status = 'votacao'
                       ORDER BY semana ASC LIMIT 1");
    $st->execute([$tipo]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) return null;

    $st = $pdo->prepare("SELECT o.id, o.titulo, o.autor, o.ano, COUNT(vt.id) votos
                           FROM clube_semana_opcoes o
                       LEFT JOIN clube_semana_votos vt ON vt.opcao_id = o.id
                          WHERE o.ciclo_id = ? GROUP BY o.id ORDER BY o.id");
    $st->execute([(int)$v['id']]);
    $v['opcoes'] = $st->fetchAll(PDO::FETCH_ASSOC);
    $v['total'] = array_sum(array_column($v['opcoes'], 'votos'));

    $st = $pdo->prepare('SELECT opcao_id FROM clube_semana_votos WHERE ciclo_id = ? AND user_id = ?');
    $st->execute([(int)$v['id'], $userId]);
    $v['meu'] = (int)($st->fetchColumn() ?: 0);
    return $v;
}

/** Vota (ou troca o voto) numa votação aberta. */
function clubeSemanaVotar(PDO $pdo, int $userId, int $cicloId, int $opcaoId): bool
{
    clubeSemanaTabelas($pdo);
    try {
        $st = $pdo->prepare("SELECT 1 FROM clube_semana_ciclos c
                              JOIN clube_semana_opcoes o ON o.ciclo_id = c.id AND o.id = ?
                             WHERE c.id = ? AND c.status = 'votacao'");
        $st->execute([$opcaoId, $cicloId]);
        if (!$st->fetchColumn()) return false;
        $pdo->prepare("INSERT INTO clube_semana_votos (ciclo_id, opcao_id, user_id) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE opcao_id = VALUES(opcao_id), criado_em = NOW()")
            ->execute([$cicloId, $opcaoId, $userId]);
        return true;
    } catch (Throwable $e) {
        error_log('[clube-semana] votar: ' . $e->getMessage());
        return false;
    }
}

/** Registra (ou atualiza) a opinião sobre um escolhido. */
function clubeSemanaOpinar(PDO $pdo, int $userId, int $cicloId, ?int $nota, string $texto): bool
{
    clubeSemanaTabelas($pdo);
    $texto = trim(mb_substr($texto, 0, 1200));
    if ($nota !== null) $nota = max(0, min(10, $nota));
    if ($texto === '' && $nota === null) return false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM clube_semana_ciclos WHERE id = ? AND status = 'definido'");
        $st->execute([$cicloId]);
        if (!$st->fetchColumn()) return false;
        $pdo->prepare("INSERT INTO clube_semana_opinioes (ciclo_id, user_id, nota, texto)
                       VALUES (?,?,?,?)
                       ON DUPLICATE KEY UPDATE nota = VALUES(nota), texto = VALUES(texto),
                                               atualizado_em = NOW()")
            ->execute([$cicloId, $userId, $nota, $texto !== '' ? $texto : null]);
        return true;
    } catch (Throwable $e) {
        error_log('[clube-semana] opinar: ' . $e->getMessage());
        return false;
    }
}
