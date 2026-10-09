<?php
/**
 * ── O DA SEMANA DO CLUBE: ÁLBUM, FILME E LIVRO ───────────────────────
 *
 * Desenho fechado com o Marcos em 07/10/2026, na segunda versão (a
 * primeira, do mesmo dia, votava a semana inteira e tinha uma aba própria
 * "Da Semana" — durou uma tarde):
 *
 *   · cada mídia mora NA PRÓPRIA ABA: o álbum na Música, o filme na
 *     Filmes, o livro no Clube do Livro;
 *   · a enquete é TODA SEXTA, das 9h às 20h. Às 20h ela fecha sozinha e
 *     o mais votado vira o da semana (era segunda até 08/10/2026, quando o
 *     Marcos pediu tudo na sexta);
 *   · no livro a sexta tem duas etapas: de manhã (9h–13h) vota-se o
 *     GÊNERO, à tarde (13h–20h) os livros do gênero vencedor;
 *   · o escolhido ganha uma timeline: cada pessoa deixa nota e
 *     comentário, e a média fica na obra pra sempre;
 *   · cada aba tem o ranking — top 5 melhores e os 2 piores — das obras
 *     já escolhidas, pela média da liga.
 *
 * ── O RELÓGIO É PREGUIÇOSO, COMO TUDO AQUI ───────────────────────────
 *
 * Nenhum cron: as viradas acontecem no primeiro acesso depois da hora,
 * igual ao leilão do jogo da semana. A enquete só NASCE dentro da janela
 * de sexta — se ninguém abrir o Clube entre 9h e 20h de uma sexta, aquela
 * semana fica sem escolha nova, o que é honesto: não havia ninguém pra
 * votar. O cartaz vigente segue sendo o último escolhido.
 *
 * ── DE ONDE SAEM AS OPÇÕES ───────────────────────────────────────────
 *
 * Listas fixas aqui embaixo (cânone + Brasil). Dez por enquete, inéditas
 * primeiro; quando as inéditas acabam, volta quem nunca venceu. Vencedor
 * não concorre de novo — o clube é pra conhecer coisa nova. Os livros são
 * por gênero, e a etapa da manhã decide de qual estante a da tarde tira.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/clube_fontes.php';

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

/**
 * Livros por gênero: a etapa da manhã escolhe a estante, a da tarde o
 * livro. As chaves são as opções da enquete de gênero — mexeu numa, mexeu
 * nas duas.
 */
const CLUBE_LIVROS = [
    'Romance' => [
        ['Orgulho e Preconceito', 'Jane Austen'],
        ['O Morro dos Ventos Uivantes', 'Emily Brontë'],
        ['Como Eu Era Antes de Você', 'Jojo Moyes'],
        ['A Culpa é das Estrelas', 'John Green'],
        ['Normal People', 'Sally Rooney'],
        ['Eleanor & Park', 'Rainbow Rowell'],
        ['Red, White & Royal Blue', 'Casey McQuiston'],
        ['É Assim que Acaba', 'Colleen Hoover'],
    ],
    'Fantasia' => [
        ['O Hobbit', 'J.R.R. Tolkien'],
        ['O Nome do Vento', 'Patrick Rothfuss'],
        ['Harry Potter e a Pedra Filosofal', 'J.K. Rowling'],
        ['A Guerra dos Tronos', 'George R.R. Martin'],
        ['Percy Jackson e o Ladrão de Raios', 'Rick Riordan'],
        ['Trono de Vidro', 'Sarah J. Maas'],
        ['O Oceano no Fim do Caminho', 'Neil Gaiman'],
        ['Mistborn: O Império Final', 'Brandon Sanderson'],
        ['A Cor da Magia', 'Terry Pratchett'],
    ],
    'Ficção científica' => [
        ['Duna', 'Frank Herbert'],
        ['Fundação', 'Isaac Asimov'],
        ['1984', 'George Orwell'],
        ['Admirável Mundo Novo', 'Aldous Huxley'],
        ['O Guia do Mochileiro das Galáxias', 'Douglas Adams'],
        ['Neuromancer', 'William Gibson'],
        ['O Problema dos Três Corpos', 'Cixin Liu'],
        ['Perdido em Marte', 'Andy Weir'],
        ['Jogo do Exterminador', 'Orson Scott Card'],
    ],
    'Mistério e suspense' => [
        ['E Não Sobrou Nenhum', 'Agatha Christie'],
        ['O Assassinato de Roger Ackroyd', 'Agatha Christie'],
        ['Garota Exemplar', 'Gillian Flynn'],
        ['A Garota no Trem', 'Paula Hawkins'],
        ['O Código Da Vinci', 'Dan Brown'],
        ['A Paciente Silenciosa', 'Alex Michaelides'],
        ['Entre Facas e Segredos: O Mistério de Sherlock', 'Arthur Conan Doyle'],
        ['O Homem de Giz', 'C.J. Tudor'],
    ],
    'Terror' => [
        ['O Iluminado', 'Stephen King'],
        ['It: A Coisa', 'Stephen King'],
        ['Drácula', 'Bram Stoker'],
        ['Frankenstein', 'Mary Shelley'],
        ['O Exorcista', 'William Peter Blatty'],
        ['A Assombração da Casa da Colina', 'Shirley Jackson'],
        ['Coraline', 'Neil Gaiman'],
        ['Misery', 'Stephen King'],
    ],
    'Drama' => [
        ['A Menina que Roubava Livros', 'Markus Zusak'],
        ['O Caçador de Pipas', 'Khaled Hosseini'],
        ['Um Homem Chamado Ove', 'Fredrik Backman'],
        ['As Vantagens de Ser Invisível', 'Stephen Chbosky'],
        ['Flores para Algernon', 'Daniel Keyes'],
        ['O Sol é Para Todos', 'Harper Lee'],
        ['Pequenas Grandes Mentiras', 'Liane Moriarty'],
        ['Torto Arado', 'Itamar Vieira Junior'],
    ],
    'Clássicos' => [
        ['Dom Casmurro', 'Machado de Assis'],
        ['Memórias Póstumas de Brás Cubas', 'Machado de Assis'],
        ['Grande Sertão: Veredas', 'João Guimarães Rosa'],
        ['Vidas Secas', 'Graciliano Ramos'],
        ['O Pequeno Príncipe', 'Antoine de Saint-Exupéry'],
        ['Crime e Castigo', 'Fiódor Dostoiévski'],
        ['O Grande Gatsby', 'F. Scott Fitzgerald'],
        ['Cem Anos de Solidão', 'Gabriel García Márquez'],
        ['A Hora da Estrela', 'Clarice Lispector'],
    ],
    'Biografia e não-ficção' => [
        ['Sapiens', 'Yuval Noah Harari'],
        ['Em Busca de Sentido', 'Viktor Frankl'],
        ['A Loja de Tudo', 'Brad Stone'],
        ['Steve Jobs', 'Walter Isaacson'],
        ['Eu Sou Malala', 'Malala Yousafzai'],
        ['Shoe Dog', 'Phil Knight'],
        ['O Diário de Anne Frank', 'Anne Frank'],
        ['Quarto de Despejo', 'Carolina Maria de Jesus'],
    ],
    'Quadrinhos e mangás' => [
        ['Watchmen', 'Alan Moore'],
        ['Batman: O Cavaleiro das Trevas', 'Frank Miller'],
        ['Sandman: Prelúdios e Noturnos', 'Neil Gaiman'],
        ['Maus', 'Art Spiegelman'],
        ['Persépolis', 'Marjane Satrapi'],
        ['Akira Vol. 1', 'Katsuhiro Otomo'],
        ['Berserk Vol. 1', 'Kentaro Miura'],
        ['Turma da Mônica: Laços', 'Vitor e Lu Cafaggi'],
    ],
    'Desenvolvimento pessoal' => [
        ['Hábitos Atômicos', 'James Clear'],
        ['O Poder do Hábito', 'Charles Duhigg'],
        ['Mindset', 'Carol S. Dweck'],
        ['Essencialismo', 'Greg McKeown'],
        ['Rápido e Devagar', 'Daniel Kahneman'],
        ['A Sutil Arte de Ligar o F*da-se', 'Mark Manson'],
        ['Pai Rico, Pai Pobre', 'Robert Kiyosaki'],
        ['Comece pelo Porquê', 'Simon Sinek'],
    ],
];

const CLUBE_SEMANA_OPCOES = 10;
const CLUBE_SEMANA_ABRE   = 9;    // sexta, 9h: a enquete nasce
const CLUBE_SEMANA_VIRA   = 13;   // sexta, 13h: no livro, o gênero fecha
const CLUBE_SEMANA_FECHA  = 20;   // sexta, 20h: tudo fecha e o da semana sai

/** O rótulo de cada tipo, do jeito que a tela fala. A `midia` é a aba dele. */
/**
 * OS TIPOS QUE NASCEM SOZINHOS NA SEXTA.
 *
 * O livro saiu daqui em 09/10/2026. A primeira rodada automática dele
 * sorteou uma estante de Fantasia do Open Library e trouxe, entre outras
 * coisas, o quinto livro de uma saga — "mas teve alguns q é tipo o livro 5
 * da saga", a Agata. Livro não é álbum: ninguém entra numa série pelo meio,
 * e um mês inteiro de leitura não se joga num sorteio.
 *
 * Então a decisão dela, e o Marcos confirmou: "deixa sem votação de livro,
 * a gente vai escolher aqui e bota a votação lá". A enquete do livro passa
 * a ser criada à mão, com a lista que elas escolheram (@see a aba Livro no
 * clube.php). Álbum e filme continuam automáticos — ali o sorteio acerta,
 * porque obra solta não tem ordem de leitura.
 */
const CLUBE_SEMANA_AUTO = ['album', 'filme'];

const CLUBE_SEMANA_TIPOS = [
    'album' => ['rot' => 'Álbum da semana', 'ico' => 'vinyl-fill', 'verbo' => 'ouvir',    'midia' => 'musica'],
    'filme' => ['rot' => 'Filme da semana', 'ico' => 'film',       'verbo' => 'assistir', 'midia' => 'filmes'],
    'livro' => ['rot' => 'Livro do mês',    'ico' => 'book-half',  'verbo' => 'ler',      'midia' => 'livro'],
];

function clubeSemanaTabelas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS clube_semana_ciclos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo ENUM('album','filme','livro') NOT NULL,
        semana DATE NOT NULL,
        status ENUM('genero','votacao','definido') NOT NULL DEFAULT 'votacao',
        genero_escolhido VARCHAR(60) NULL,
        vencedor_opcao_id INT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_tipo_semana (tipo, semana)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS clube_semana_opcoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ciclo_id INT NOT NULL,
        etapa ENUM('genero','obra') NOT NULL DEFAULT 'obra',
        titulo VARCHAR(160) NOT NULL,
        autor VARCHAR(160) NOT NULL,
        ano SMALLINT NULL,
        KEY idx_ciclo (ciclo_id)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS clube_semana_votos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ciclo_id INT NOT NULL,
        etapa ENUM('genero','obra') NOT NULL DEFAULT 'obra',
        opcao_id INT NOT NULL,
        user_id INT NOT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_voto (ciclo_id, etapa, user_id)
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

    /* A PRIMEIRA VERSÃO (manhã de 07/10) criou as tabelas sem livro, sem
       etapa e com a semana ancorada no domingo. Os ajustes abaixo levam um
       banco daquela manhã pra cá; num banco novo não fazem nada. Cada um é
       guardado por consulta ao information_schema porque MODIFY de enum
       reconstrói a tabela — rodar todo request seria pagar essa conta à toa. */
    try {
        $col = fn(string $t, string $c) => $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = '{$t}' AND column_name = '{$c}'"
        )->fetchColumn();

        if (strpos((string)$col('clube_semana_ciclos', 'tipo'), 'livro') === false) {
            $pdo->exec("ALTER TABLE clube_semana_ciclos
                        MODIFY tipo ENUM('album','filme','livro') NOT NULL");
        }
        if (strpos((string)$col('clube_semana_ciclos', 'status'), 'genero') === false) {
            $pdo->exec("ALTER TABLE clube_semana_ciclos
                        MODIFY status ENUM('genero','votacao','definido') NOT NULL DEFAULT 'votacao'");
        }
        if ($col('clube_semana_ciclos', 'genero_escolhido') === false) {
            $pdo->exec("ALTER TABLE clube_semana_ciclos ADD COLUMN genero_escolhido VARCHAR(60) NULL");
        }
        /* JANELAS PRÓPRIAS, pra rodada avulsa. Normalmente ficam nulas e o
           ciclo obedece o relógio da âncora (9h / 13h / 20h); preenchidas,
           elas mandam. Nasceram na estreia do clube (07/10/2026): a primeira
           segunda do mês já tinha passado, e sem isto o livro só estrearia
           em novembro. @see clubeSemanaAbrirAvulsa */
        if ($col('clube_semana_ciclos', 'vira_em') === false) {
            $pdo->exec("ALTER TABLE clube_semana_ciclos
                        ADD COLUMN vira_em DATETIME NULL, ADD COLUMN fecha_em DATETIME NULL");
        }
        if ($col('clube_semana_opcoes', 'etapa') === false) {
            $pdo->exec("ALTER TABLE clube_semana_opcoes
                        ADD COLUMN etapa ENUM('genero','obra') NOT NULL DEFAULT 'obra' AFTER ciclo_id");
        }
        if ($col('clube_semana_votos', 'etapa') === false) {
            $pdo->exec("ALTER TABLE clube_semana_votos
                        ADD COLUMN etapa ENUM('genero','obra') NOT NULL DEFAULT 'obra' AFTER ciclo_id");
            $pdo->exec("ALTER TABLE clube_semana_votos DROP INDEX uq_um_voto");
            $pdo->exec("ALTER TABLE clube_semana_votos ADD UNIQUE KEY uq_voto (ciclo_id, etapa, user_id)");
        }
    } catch (Throwable $e) {
        error_log('[clube-semana] migrar: ' . $e->getMessage());
    }
    $feito = true;
}

/**
 * A SEXTA-FEIRA do ciclo corrente. A semana do clube vai de sexta a quinta.
 *
 * Era segunda até 08/10/2026, quando o Marcos pediu tudo na sexta — álbum,
 * filme e livro. A semana anda junto com a votação: o escolhido na sexta vale
 * até a sexta seguinte, senão o "da semana" trocaria no meio do fim de semana.
 */
function clubeSemanaSexta(): string
{
    /* Nasce do MESMO relógio que as janelas comparam: se viesse de
       `new DateTime('today')`, o relógio congelado dos testes moveria as
       janelas mas não a semana, e nenhuma sexta-feira seria simulável. */
    $hoje = new DateTime('@' . clubeSemanaAgora());
    $hoje->setTimezone(new DateTimeZone('America/Sao_Paulo'));
    $hoje->setTime(0, 0);
    /* Volta até a última sexta. N é 1 na segunda e 5 na sexta; o +7 antes do
       resto evita o negativo de segunda a quinta. */
    $hoje->modify('-' . (((int)$hoje->format('N')) - 5 + 7) % 7 . ' day');
    return $hoje->format('Y-m-d');
}

/** Agora, em segundos — separado pra os testes poderem congelar o relógio. */
function clubeSemanaAgora(): int
{
    return isset($GLOBALS['CLUBE_SEMANA_AGORA']) ? (int)$GLOBALS['CLUBE_SEMANA_AGORA'] : time();
}

/**
 * A âncora do ciclo de um tipo: a sexta da semana pra álbum e filme, e a
 * PRIMEIRA SEXTA DO MÊS pro livro — o livro é mensal (pedido do Marcos,
 * 07/10/2026): ninguém lê um livro em sete dias, e o clube viraria fila de
 * livro não lido. A mecânica da sexta não muda, só a frequência: gênero
 * de manhã, livros à tarde, 20h fecha — uma vez por mês.
 *
 * Devolve a última âncora que JÁ CHEGOU: nos primeiros dias de um mês cuja
 * primeira sexta ainda não chegou, o ciclo vigente ainda é o do mês passado.
 */
function clubeSemanaAncora(string $tipo): string
{
    if ($tipo !== 'livro') return clubeSemanaSexta();
    $hoje = new DateTime('@' . clubeSemanaAgora());
    $hoje->setTimezone(new DateTimeZone('America/Sao_Paulo'));
    $hoje->setTime(0, 0);
    $m = (clone $hoje)->modify('first friday of this month');
    if ($m > $hoje) $m = (clone $hoje)->modify('first friday of last month');
    return $m->format('Y-m-d');
}

/** O nome do mês de uma data, pro cartaz do livro falar "outubro". */
function clubeSemanaMes(string $ymd): string
{
    $meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
              'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    return $meses[(int)date('n', strtotime($ymd))] ?? '';
}

/**
 * Sorteia as opções de álbum/filme: inéditas primeiro, depois quem nunca
 * venceu. Vencedor não volta nunca.
 *
 * ── PRIMEIRO AS APIS, DEPOIS A LISTA À MÃO ───────────────────────────
 *
 * O catálogo escrito aqui tem 70 álbuns e 70 filmes, e uma enquete por
 * semana sorteando dez esgota isso em pouco mais de um ano. As fontes
 * externas (@see clube_fontes.php) resolvem o volume; a lista continua
 * valendo por dois motivos que não são nostalgia:
 *
 *   · ela é a SEMENTE dos álbuns — são os artistas dela que o Deezer é
 *     perguntado sobre, porque pedir por gênero devolve o chart brasileiro
 *     para qualquer gênero;
 *   · ela é a REDE. API fora do ar, cota estourada, resposta estranha: o
 *     sorteio cai aqui e a sexta acontece. Enquete que não nasce é semana
 *     perdida, e isso ninguém recupera depois.
 */
function clubeSemanaSortear(PDO $pdo, string $tipo): array
{
    $daApi = clubeFonteSortear($pdo, $tipo, '', CLUBE_SEMANA_OPCOES);
    if (count($daApi) >= CLUBE_SEMANA_OPCOES) return $daApi;

    $seed = $tipo === 'album' ? CLUBE_ALBUNS : CLUBE_FILMES;
    $st = $pdo->prepare("SELECT o.titulo, MAX(o.id = c.vencedor_opcao_id) venceu
                           FROM clube_semana_opcoes o
                           JOIN clube_semana_ciclos c ON c.id = o.ciclo_id
                          WHERE c.tipo = ? GROUP BY o.titulo");
    $st->execute([$tipo]);
    $ja = [];
    foreach ($st as $r) $ja[mb_strtolower($r['titulo'])] = (bool)$r['venceu'];

    $ineditas = []; $repescagem = [];
    foreach ($seed as $s) {
        $k = mb_strtolower($s[0]);
        if (!isset($ja[$k]))  $ineditas[] = $s;
        elseif (!$ja[$k])     $repescagem[] = $s;
    }
    shuffle($ineditas);
    shuffle($repescagem);
    return array_slice(array_merge($ineditas, $repescagem), 0, CLUBE_SEMANA_OPCOES);
}

/**
 * Os livros de um gênero que ainda não venceram, embaralhados.
 *
 * Aqui o aperto era maior que no álbum: a estante à mão tem oito ou nove
 * livros por gênero, e o sorteio pede dez — ou seja, o gênero inteiro
 * aparecia toda vez, e a "enquete" era a estante inteira sem escolha
 * nenhuma. Com o Open Library o gênero passa a ter centenas.
 */
function clubeSemanaLivrosDoGenero(PDO $pdo, string $genero): array
{
    $daApi = clubeFonteSortear($pdo, 'livro', $genero, CLUBE_SEMANA_OPCOES);
    if (count($daApi) >= CLUBE_SEMANA_OPCOES) return $daApi;

    $estante = CLUBE_LIVROS[$genero] ?? [];
    $st = $pdo->prepare("SELECT o.titulo FROM clube_semana_opcoes o
                           JOIN clube_semana_ciclos c ON c.id = o.ciclo_id
                                AND c.vencedor_opcao_id = o.id
                          WHERE c.tipo = 'livro'");
    $st->execute();
    $vencidos = array_map('mb_strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
    $pool = array_values(array_filter($estante,
        fn($l) => !in_array(mb_strtolower($l[0]), $vencidos, true)));
    if (!$pool) $pool = $estante;   // estante inteira já lida: melhor repetir que travar
    shuffle($pool);
    return array_slice($pool, 0, CLUBE_SEMANA_OPCOES);
}

/**
 * O relógio: cria a enquete de sexta dentro da janela, vira o gênero do
 * livro às 13h e fecha tudo às 20h. Preguiçoso — roda no acesso.
 */
function clubeSemanaGirar(PDO $pdo): void
{
    clubeSemanaTabelas($pdo);
    $agora = clubeSemanaAgora();

    foreach (array_keys(CLUBE_SEMANA_TIPOS) as $tipo) {
        /* Cada tipo tem a sua âncora: a sexta da semana, ou a primeira
           sexta do mês no livro. As janelas do dia nascem dela — numa sexta
           que não é a âncora do livro, a janela dele já passou e nada
           nasce, que é exatamente o mensal funcionando. */
        $ancora = clubeSemanaAncora($tipo);
        $abre  = strtotime($ancora . ' ' . CLUBE_SEMANA_ABRE . ':00:00');
        $vira  = strtotime($ancora . ' ' . CLUBE_SEMANA_VIRA . ':00:00');
        $fecha = strtotime($ancora . ' ' . CLUBE_SEMANA_FECHA . ':00:00');
        try {
            /* 1. Enquete atrasada de OUTRO ciclo fecha, aconteça o que
               acontecer: o escolhido não pode ficar preso no limbo.

               A condição era `semana < ancora`, usando a âncora como atalho
               pra "já passou". O atalho quebrou na mudança de segunda pra
               sexta (08/10/2026): as três enquetes abertas na segunda 05/10
               não eram menores que a âncora daquela quinta, 02/10, e ficariam
               presas até a sexta seguinte. Agora o que decide é o RELÓGIO
               DELAS — a janela própria, se tiver, senão as 20h do dia da
               âncora —, que é o que a regra sempre quis dizer. Assim também
               uma rodada avulsa com janela no futuro não é fechada cedo. */
            $st = $pdo->prepare("SELECT id, status, semana, fecha_em FROM clube_semana_ciclos
                                  WHERE tipo = ? AND status <> 'definido' AND semana <> ?");
            $st->execute([$tipo, $ancora]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $velho) {
                $fechaDele = $velho['fecha_em']
                    ? strtotime((string)$velho['fecha_em'])
                    : strtotime($velho['semana'] . ' ' . CLUBE_SEMANA_FECHA . ':00:00');
                if ($agora < $fechaDele) continue;
                if ($velho['status'] === 'genero') clubeSemanaVirarGenero($pdo, (int)$velho['id']);
                clubeSemanaDecidir($pdo, (int)$velho['id']);
            }

            /* 2. A enquete desta sexta nasce SÓ dentro da janela: antes das
               9h não existe, e depois das 20h já era — semana sem ninguém na
               janela é semana sem escolha nova, o que é só a verdade. */
            $st = $pdo->prepare("SELECT id, status, vira_em, fecha_em FROM clube_semana_ciclos
                                  WHERE tipo = ? AND semana = ?");
            $st->execute([$tipo, $ancora]);
            $ciclo = $st->fetch(PDO::FETCH_ASSOC);

            /* Ciclo com janela própria manda no relógio da âncora: é o que
               faz a rodada avulsa não ser fechada no mesmo instante em que
               nasce, já que as horas da âncora dela ficaram no passado. */
            if ($ciclo && $ciclo['fecha_em']) {
                $vira  = $ciclo['vira_em'] ? strtotime((string)$ciclo['vira_em']) : $vira;
                $fecha = strtotime((string)$ciclo['fecha_em']);
            }

            /* SÓ O QUE NASCE SOZINHO. O livro não entra: a lista dele é
               escolhida fora e a enquete é criada à mão (@see
               CLUBE_SEMANA_AUTO). O resto do relógio continua valendo pra
               ele — uma rodada criada à mão fecha na hora marcada e vira o
               livro do mês igual às outras. */
            if (!$ciclo && $agora >= $abre && $agora < $fecha
                    && in_array($tipo, CLUBE_SEMANA_AUTO, true)) {
                if ($tipo === 'livro') {
                    /* No livro a manhã é do gênero. Quem só chegar à tarde
                       ainda abre a enquete, mas ela nasce virando na hora. */
                    $pdo->prepare("INSERT INTO clube_semana_ciclos (tipo, semana, status)
                                   VALUES ('livro', ?, 'genero')")->execute([$ancora]);
                    $cid = (int)$pdo->lastInsertId();
                    $op = $pdo->prepare("INSERT INTO clube_semana_opcoes (ciclo_id, etapa, titulo, autor)
                                         VALUES (?, 'genero', ?, '')");
                    foreach (array_keys(CLUBE_LIVROS) as $g) $op->execute([$cid, $g]);
                    $ciclo = ['id' => $cid, 'status' => 'genero'];
                } else {
                    $opcoes = clubeSemanaSortear($pdo, $tipo);
                    if ($opcoes) {
                        $pdo->prepare("INSERT INTO clube_semana_ciclos (tipo, semana, status)
                                       VALUES (?, ?, 'votacao')")->execute([$tipo, $ancora]);
                        $cid = (int)$pdo->lastInsertId();
                        $op = $pdo->prepare("INSERT INTO clube_semana_opcoes (ciclo_id, etapa, titulo, autor, ano)
                                             VALUES (?, 'obra', ?, ?, ?)");
                        foreach ($opcoes as $o) $op->execute([$cid, $o[0], $o[1], $o[2]]);
                        $ciclo = ['id' => $cid, 'status' => 'votacao'];
                    }
                }
            }

            if (!$ciclo) continue;

            /* 3. As viradas do dia: gênero fecha às 13h, tudo fecha às 20h. */
            if ($ciclo['status'] === 'genero' && $agora >= $vira) {
                clubeSemanaVirarGenero($pdo, (int)$ciclo['id']);
                $ciclo['status'] = 'votacao';
            }
            if ($ciclo['status'] !== 'definido' && $agora >= $fecha) {
                if ($ciclo['status'] === 'genero') clubeSemanaVirarGenero($pdo, (int)$ciclo['id']);
                clubeSemanaDecidir($pdo, (int)$ciclo['id']);
            }
        } catch (Throwable $e) {
            error_log('[clube-semana] girar ' . $tipo . ': ' . $e->getMessage());
        }
    }
}

/**
 * Abre uma rodada FORA do calendário, com janelas próprias.
 *
 * Existe pra estreia e pra exceção combinada — na estreia do clube
 * (07/10/2026) a primeira segunda do mês já tinha passado, e sem isto o
 * livro só sairia em novembro. Não é um bypass do rito: a rodada ocupa a
 * âncora vigente, então a próxima nasce no dia certo, sozinha, como sempre.
 *
 * ── ANTECIPAR A RODADA QUE AINDA VEM ─────────────────────────────────
 *
 * Com `$ancora`, a rodada nasce já na sexta que vem em vez da âncora
 * vigente, e é isso que faz "começar agora" ser ANTECIPAR e não DUPLICAR:
 * ocupando a âncora de sexta, o `clubeSemanaGirar` das 9h encontra a rodada
 * de pé e não cria outra. Com a âncora vigente aconteceria o contrário —
 * uma rodada hoje e outra amanhã, a liga votando em duas listas.
 *
 * Pedido do Marcos (08/10/2026): "que comece a partir de agora as votações
 * e nao as 9h de amanha".
 *
 * @param string  $vira   quando a etapa do gênero fecha (só o livro usa)
 * @param string  $fecha  quando a rodada fecha e o escolhido sai
 * @param ?string $ancora a sexta que a rodada ocupa; null usa a vigente
 * @return bool false quando já existe rodada nessa âncora — não se abre
 *         duas, senão a liga vota em duas listas ao mesmo tempo.
 */
function clubeSemanaAbrirAvulsa(PDO $pdo, string $tipo, string $vira, string $fecha,
                                ?string $ancora = null): bool
{
    clubeSemanaTabelas($pdo);
    if (!isset(CLUBE_SEMANA_TIPOS[$tipo])) return false;
    $ancora = $ancora ?: clubeSemanaAncora($tipo);
    try {
        $st = $pdo->prepare('SELECT id FROM clube_semana_ciclos WHERE tipo = ? AND semana = ?');
        $st->execute([$tipo, $ancora]);
        if ($st->fetchColumn()) return false;

        $status = $tipo === 'livro' ? 'genero' : 'votacao';
        $pdo->prepare("INSERT INTO clube_semana_ciclos (tipo, semana, status, vira_em, fecha_em)
                       VALUES (?,?,?,?,?)")
            ->execute([$tipo, $ancora, $status, $vira, $fecha]);
        $cid = (int)$pdo->lastInsertId();

        if ($tipo === 'livro') {
            $op = $pdo->prepare("INSERT INTO clube_semana_opcoes (ciclo_id, etapa, titulo, autor)
                                 VALUES (?, 'genero', ?, '')");
            foreach (array_keys(CLUBE_LIVROS) as $g) $op->execute([$cid, $g]);
        } else {
            $op = $pdo->prepare("INSERT INTO clube_semana_opcoes (ciclo_id, etapa, titulo, autor, ano)
                                 VALUES (?, 'obra', ?, ?, ?)");
            foreach (clubeSemanaSortear($pdo, $tipo) as $o) $op->execute([$cid, $o[0], $o[1], $o[2]]);
        }
        return true;
    } catch (Throwable $e) {
        error_log('[clube-semana] avulsa ' . $tipo . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * ABRE A VOTAÇÃO DO LIVRO COM UMA LISTA ESCOLHIDA À MÃO.
 *
 * O livro saiu do sorteio automático (@see CLUBE_SEMANA_AUTO): quem monta
 * a lista é o clube, e esta função é por onde ela entra. Sem etapa de
 * gênero — o gênero já foi decidido na conversa que escolheu os títulos, e
 * repetir a pergunta aqui seria pedir à liga que referendasse o que já
 * está feito.
 *
 * A âncora é a vigente, como em qualquer rodada do livro: é ela que faz a
 * próxima nascer no mês certo.
 *
 * @param array $obras cada uma [titulo, autor, ano]
 * @param int   $horas quanto tempo a votação fica aberta
 * @return bool false quando já existe rodada no mês ou faltam opções
 */
function clubeSemanaAbrirVotacaoDeLivro(PDO $pdo, array $obras, int $horas = 24): bool
{
    clubeSemanaTabelas($pdo);
    $obras = array_values(array_filter($obras, fn($o) => trim((string)($o[0] ?? '')) !== ''));
    if (count($obras) < 2) return false;

    $ancora = clubeSemanaAncora('livro');
    try {
        $st = $pdo->prepare('SELECT id FROM clube_semana_ciclos WHERE tipo = "livro" AND semana = ?');
        $st->execute([$ancora]);
        if ($st->fetchColumn()) return false;

        $fecha = date('Y-m-d H:i:s', clubeSemanaAgora() + $horas * 3600);
        /* Nasce em `votacao`, e não em `genero`: a etapa da manhã não existe
           quando a lista já vem pronta. */
        $pdo->prepare("INSERT INTO clube_semana_ciclos (tipo, semana, status, fecha_em)
                       VALUES ('livro', ?, 'votacao', ?)")->execute([$ancora, $fecha]);
        $cid = (int)$pdo->lastInsertId();
        $op = $pdo->prepare("INSERT INTO clube_semana_opcoes (ciclo_id, etapa, titulo, autor, ano)
                             VALUES (?, 'obra', ?, ?, ?)");
        foreach ($obras as $o) {
            $op->execute([$cid, (string)$o[0], (string)($o[1] ?? ''),
                          isset($o[2]) && $o[2] !== '' ? (int)$o[2] : null]);
        }
        return true;
    } catch (Throwable $e) {
        error_log('[clube-semana] votacao de livro: ' . $e->getMessage());
        return false;
    }
}

/** Fecha a etapa do gênero: o mais votado define a estante da tarde. */
function clubeSemanaVirarGenero(PDO $pdo, int $cicloId): void
{
    $st = $pdo->prepare("SELECT o.titulo FROM clube_semana_opcoes o
                     LEFT JOIN clube_semana_votos v ON v.opcao_id = o.id AND v.etapa = 'genero'
                         WHERE o.ciclo_id = ? AND o.etapa = 'genero'
                      GROUP BY o.id ORDER BY COUNT(v.id) DESC, o.id ASC LIMIT 1");
    $st->execute([$cicloId]);
    $genero = (string)($st->fetchColumn() ?: '');
    if ($genero === '') return;

    $livros = clubeSemanaLivrosDoGenero($pdo, $genero);
    $op = $pdo->prepare("INSERT INTO clube_semana_opcoes (ciclo_id, etapa, titulo, autor)
                         VALUES (?, 'obra', ?, ?)");
    foreach ($livros as $l) $op->execute([$cicloId, $l[0], $l[1]]);
    $pdo->prepare("UPDATE clube_semana_ciclos SET status = 'votacao', genero_escolhido = ?
                    WHERE id = ? AND status = 'genero'")->execute([$genero, $cicloId]);
}

/** Fecha a enquete: mais votos vence; empate fica com quem entrou antes. */
function clubeSemanaDecidir(PDO $pdo, int $cicloId): void
{
    $st = $pdo->prepare("SELECT o.id FROM clube_semana_opcoes o
                     LEFT JOIN clube_semana_votos v ON v.opcao_id = o.id AND v.etapa = 'obra'
                         WHERE o.ciclo_id = ? AND o.etapa = 'obra'
                      GROUP BY o.id ORDER BY COUNT(v.id) DESC, o.id ASC LIMIT 1");
    $st->execute([$cicloId]);
    $vencedora = $st->fetchColumn();
    if ($vencedora === false) {
        /* Livro cuja tarde nunca chegou a existir: fecha vazio mesmo, sem
           vencedor — semana sem livro, em vez de um livro que ninguém votou. */
        $pdo->prepare("UPDATE clube_semana_ciclos SET status = 'definido'
                        WHERE id = ? AND status <> 'definido'")->execute([$cicloId]);
        return;
    }
    $pdo->prepare("UPDATE clube_semana_ciclos SET status = 'definido', vencedor_opcao_id = ?
                    WHERE id = ? AND status <> 'definido'")->execute([(int)$vencedora, $cicloId]);
}

/** Tudo que a aba de um tipo precisa: cartaz, enquete do dia e ranking. */
function clubeSemanaEstadoDoTipo(PDO $pdo, string $tipo, int $userId): array
{
    clubeSemanaGirar($pdo);
    return [
        'info'    => CLUBE_SEMANA_TIPOS[$tipo],
        'cartaz'  => clubeSemanaCartaz($pdo, $tipo, $userId),
        'enquete' => clubeSemanaEnquete($pdo, $tipo, $userId),
        'ranking' => clubeSemanaRanking($pdo, $tipo),
    ];
}

/** O escolhido mais recente, com a timeline de opiniões e a média. */
function clubeSemanaCartaz(PDO $pdo, string $tipo, int $userId): ?array
{
    $st = $pdo->prepare("SELECT c.id ciclo, c.semana, c.genero_escolhido, o.titulo, o.autor, o.ano
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

/**
 * A enquete de hoje, se estiver de pé. `etapa` diz o que se vota agora:
 * no livro de manhã é o gênero; no resto (e no livro à tarde), a obra.
 */
function clubeSemanaEnquete(PDO $pdo, string $tipo, int $userId): ?array
{
    $st = $pdo->prepare("SELECT id, semana, status, genero_escolhido, vira_em, fecha_em
                           FROM clube_semana_ciclos
                          WHERE tipo = ? AND semana = ? AND status <> 'definido'");
    $st->execute([$tipo, clubeSemanaAncora($tipo)]);
    $v = $st->fetch(PDO::FETCH_ASSOC);

    /* A RODADA ANTECIPADA AINDA NÃO É DA ÂNCORA DE HOJE — ela ocupa a sexta
       que vem. Procurar só pela âncora a deixaria invisível justamente nas
       horas que ela ganhou: quem abrisse o Clube não veria enquete nenhuma
       até as 9h, que é a hora que ela existe pra adiantar. Quem manda aqui é
       o relógio DELA: janela própria aberta, rodada de pé. */
    if (!$v) {
        $st = $pdo->prepare("SELECT id, semana, status, genero_escolhido, vira_em, fecha_em
                               FROM clube_semana_ciclos
                              WHERE tipo = ? AND status <> 'definido'
                                AND fecha_em IS NOT NULL AND fecha_em > FROM_UNIXTIME(?)
                           ORDER BY semana ASC LIMIT 1");
        $st->execute([$tipo, clubeSemanaAgora()]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
    }
    if (!$v) return null;

    $v['etapa'] = $v['status'] === 'genero' ? 'genero' : 'obra';
    /* As HORAS REAIS desta rodada, pra tela não prometer 13h/20h numa rodada
       avulsa que fecha noutra hora. */
    $v['hora_vira']  = $v['vira_em']  ? date('H\hi', strtotime((string)$v['vira_em']))  : CLUBE_SEMANA_VIRA . 'h';
    $v['hora_fecha'] = $v['fecha_em'] ? date('H\hi', strtotime((string)$v['fecha_em'])) : CLUBE_SEMANA_FECHA . 'h';
    foreach (['hora_vira', 'hora_fecha'] as $k) $v[$k] = str_replace('h00', 'h', $v[$k]);

    /* QUE DIA ELA FECHA. A tela dizia "fecha hoje às 20h" sempre, o que era
       verdade enquanto toda rodada nascia e morria na mesma sexta. Com a
       rodada antecipada deixou de ser: ela abre na quinta e fecha na sexta,
       e "hoje" mandaria a liga votar com pressa de um prazo que não é o
       dela — ou pior, desistir achando que já passou. */
    $v['dia_fecha'] = 'hoje';
    if ($v['fecha_em']) {
        $hoje  = date('Y-m-d', clubeSemanaAgora());
        $dia   = date('Y-m-d', strtotime((string)$v['fecha_em']));
        if ($dia !== $hoje) {
            $amanha = date('Y-m-d', strtotime($hoje . ' +1 day'));
            $semana = ['Sun' => 'domingo', 'Mon' => 'segunda', 'Tue' => 'terça', 'Wed' => 'quarta',
                       'Thu' => 'quinta', 'Fri' => 'sexta', 'Sat' => 'sábado'];
            $v['dia_fecha'] = $dia === $amanha
                ? 'amanhã'
                : ($semana[date('D', strtotime($dia))] ?? date('d/m', strtotime($dia)));
        }
    }
    $st = $pdo->prepare("SELECT o.id, o.titulo, o.autor, o.ano, COUNT(vt.id) votos
                           FROM clube_semana_opcoes o
                       LEFT JOIN clube_semana_votos vt ON vt.opcao_id = o.id AND vt.etapa = o.etapa
                          WHERE o.ciclo_id = ? AND o.etapa = ?
                       GROUP BY o.id ORDER BY o.id");
    $st->execute([(int)$v['id'], $v['etapa']]);
    $v['opcoes'] = $st->fetchAll(PDO::FETCH_ASSOC);
    $v['total'] = array_sum(array_column($v['opcoes'], 'votos'));

    $st = $pdo->prepare('SELECT opcao_id FROM clube_semana_votos
                          WHERE ciclo_id = ? AND etapa = ? AND user_id = ?');
    $st->execute([(int)$v['id'], $v['etapa'], $userId]);
    $v['meu'] = (int)($st->fetchColumn() ?: 0);
    return $v;
}

/**
 * O ranking da aba: top 5 pela média, e os 2 piores. Só conta obra que já
 * recebeu nota, e os piores não repetem quem está no topo — com pouca
 * amostra seria a mesma lista duas vezes.
 */
function clubeSemanaRanking(PDO $pdo, string $tipo): array
{
    $st = $pdo->prepare("SELECT o.titulo, o.autor, ROUND(AVG(op.nota), 1) media, COUNT(op.nota) notas
                           FROM clube_semana_ciclos c
                           JOIN clube_semana_opcoes o ON o.id = c.vencedor_opcao_id
                           JOIN clube_semana_opinioes op ON op.ciclo_id = c.id AND op.nota IS NOT NULL
                          WHERE c.tipo = ? AND c.status = 'definido'
                       GROUP BY c.id ORDER BY media DESC, notas DESC");
    $st->execute([$tipo]);
    $todos = $st->fetchAll(PDO::FETCH_ASSOC);

    $top = array_slice($todos, 0, 5);
    $resto = array_slice($todos, 5);
    $piores = array_slice(array_reverse($resto), 0, 2);
    return ['top' => $top, 'piores' => $piores];
}

/** Vota (ou troca o voto) na etapa aberta da enquete do dia. */
function clubeSemanaVotar(PDO $pdo, int $userId, int $cicloId, int $opcaoId): ?string
{
    clubeSemanaTabelas($pdo);
    try {
        $st = $pdo->prepare("SELECT c.tipo, c.status, o.etapa FROM clube_semana_ciclos c
                              JOIN clube_semana_opcoes o ON o.ciclo_id = c.id AND o.id = ?
                             WHERE c.id = ? AND c.status <> 'definido'");
        $st->execute([$opcaoId, $cicloId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        /* A opção tem que ser da etapa ABERTA: voto em gênero depois das 13h
           (ou em livro antes delas) é formulário velho na tela — ignora. */
        $etapaAberta = $r['status'] === 'genero' ? 'genero' : 'obra';
        if ($r['etapa'] !== $etapaAberta) return null;

        $pdo->prepare("INSERT INTO clube_semana_votos (ciclo_id, etapa, opcao_id, user_id)
                       VALUES (?,?,?,?)
                       ON DUPLICATE KEY UPDATE opcao_id = VALUES(opcao_id), criado_em = NOW()")
            ->execute([$cicloId, $etapaAberta, $opcaoId, $userId]);
        return (string)$r['tipo'];
    } catch (Throwable $e) {
        error_log('[clube-semana] votar: ' . $e->getMessage());
        return null;
    }
}

/** Registra (ou atualiza) nota e comentário na timeline de um escolhido. */
function clubeSemanaOpinar(PDO $pdo, int $userId, int $cicloId, ?int $nota, string $texto): ?string
{
    clubeSemanaTabelas($pdo);
    $texto = trim(mb_substr($texto, 0, 1200));
    if ($nota !== null) $nota = max(0, min(10, $nota));
    if ($texto === '' && $nota === null) return null;
    try {
        $st = $pdo->prepare("SELECT tipo FROM clube_semana_ciclos WHERE id = ? AND status = 'definido'");
        $st->execute([$cicloId]);
        $tipo = $st->fetchColumn();
        if ($tipo === false) return null;
        $pdo->prepare("INSERT INTO clube_semana_opinioes (ciclo_id, user_id, nota, texto)
                       VALUES (?,?,?,?)
                       ON DUPLICATE KEY UPDATE nota = VALUES(nota), texto = VALUES(texto),
                                               atualizado_em = NOW()")
            ->execute([$cicloId, $userId, $nota, $texto !== '' ? $texto : null]);
        return (string)$tipo;
    } catch (Throwable $e) {
        error_log('[clube-semana] opinar: ' . $e->getMessage());
        return null;
    }
}
