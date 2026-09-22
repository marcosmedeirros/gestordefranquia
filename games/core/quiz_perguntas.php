<?php
/**
 * Banco inicial do Quiz do Dia.
 *
 * Todas são perguntas de OPINIÃO, de propósito: o prêmio vai pra quem votar
 * com a maioria, então pergunta com resposta certa viraria só uma prova de
 * quem sabe mais — e o jogo é adivinhar a turma, não o almanaque.
 *
 * Semeado uma vez só, quando a tabela está vazia. Depois disso quem manda é
 * o games/admin/quiz-admin.php: reescrever daqui apagaria o que foi
 * cadastrado à mão.
 */

const QUIZ_BANCO = [
    ['Quem é o maior ídolo da história do Lakers?',
     ['Kobe Bryant', 'Magic Johnson', 'LeBron James', 'Kareem Abdul-Jabbar', 'Shaquille O\'Neal']],

    ['E o maior ídolo do Bulls?',
     ['Michael Jordan', 'Scottie Pippen', 'Derrick Rose', 'Dennis Rodman', 'Jimmy Butler']],

    ['Maior ídolo da história do Celtics?',
     ['Larry Bird', 'Bill Russell', 'Paul Pierce', 'Kevin Garnett', 'Jayson Tatum']],

    ['Maior ídolo da história do Warriors?',
     ['Stephen Curry', 'Klay Thompson', 'Wilt Chamberlain', 'Draymond Green', 'Rick Barry']],

    ['Maior ídolo da história do Spurs?',
     ['Tim Duncan', 'Manu Ginóbili', 'Tony Parker', 'David Robinson', 'Kawhi Leonard']],

    ['Maior ídolo da história do Heat?',
     ['Dwyane Wade', 'LeBron James', 'Alonzo Mourning', 'Udonis Haslem', 'Jimmy Butler']],

    ['Maior ídolo da história do Mavericks?',
     ['Dirk Nowitzki', 'Luka Dončić', 'Jason Kidd', 'Steve Nash', 'Michael Finley']],

    ['No fim das contas, quem é o GOAT?',
     ['Michael Jordan', 'LeBron James', 'Kareem Abdul-Jabbar', 'Bill Russell', 'Kobe Bryant']],

    ['Melhor armador de todos os tempos?',
     ['Magic Johnson', 'Stephen Curry', 'Oscar Robertson', 'John Stockton', 'Isiah Thomas']],

    ['Melhor pivô de todos os tempos?',
     ['Kareem Abdul-Jabbar', 'Shaquille O\'Neal', 'Hakeem Olajuwon', 'Wilt Chamberlain', 'Nikola Jokić']],

    ['Melhor ala-armador depois do Jordan?',
     ['Kobe Bryant', 'Dwyane Wade', 'James Harden', 'Tracy McGrady', 'Devin Booker']],

    ['Melhor ala-pivô de todos os tempos?',
     ['Tim Duncan', 'Karl Malone', 'Dirk Nowitzki', 'Kevin Garnett', 'Giannis Antetokounmpo']],

    ['Melhor arremessador que já existiu?',
     ['Stephen Curry', 'Ray Allen', 'Reggie Miller', 'Klay Thompson', 'Larry Bird']],

    ['Melhor defensor da história?',
     ['Bill Russell', 'Hakeem Olajuwon', 'Dennis Rodman', 'Kawhi Leonard', 'Ben Wallace']],

    ['Quem você quer com a bola faltando 5 segundos?',
     ['Michael Jordan', 'Kobe Bryant', 'Damian Lillard', 'LeBron James', 'Stephen Curry']],

    ['Melhor jogador da NBA hoje?',
     ['Nikola Jokić', 'Shai Gilgeous-Alexander', 'Luka Dončić', 'Giannis Antetokounmpo', 'Jayson Tatum']],

    ['Qual dinastia foi a mais dominante?',
     ['Bulls dos anos 90', 'Warriors de 2015-19', 'Lakers de Shaq e Kobe', 'Celtics dos anos 60', 'Spurs de Duncan']],

    ['Melhor classe de draft da história?',
     ['1984', '1996', '2003', '1985', '2018']],

    ['Maior "e se" da história da NBA?',
     ['Se Len Bias não tivesse morrido', 'Se Derrick Rose não se lesionasse',
      'Se Yao Ming ficasse saudável', 'Se Grant Hill ficasse saudável', 'Se Jordan não parasse em 93']],

    ['Qual virada de final dói mais até hoje?',
     ['Warriors perdendo de 3-1', 'Mavericks 2006', 'Kings 2002', 'Suns 2021', 'Cavs 2015']],

    ['Melhor apelido do basquete?',
     ['Black Mamba', 'The Answer', 'King James', 'The Greek Freak', 'The Joker']],

    ['Uniforme mais bonito da NBA?',
     ['Bulls vermelha', 'Lakers roxo e dourado', 'Raptors do dinossauro', 'Nuggets arco-íris', 'Heat Vice']],

    ['Melhor jogador brasileiro da história?',
     ['Oscar Schmidt', 'Nenê', 'Anderson Varejão', 'Leandrinho Barbosa', 'Marcelinho Machado']],

    ['O que ganha mais jogo no fim das contas?',
     ['Defesa', 'Um superstar', 'Um técnico genial', 'Elenco profundo', 'Sorte nos playoffs']],

    ['Qual a posição mais importante no basquete de hoje?',
     ['Armador', 'Pivô', 'Ala', 'Ala-armador', 'Ala-pivô']],

    ['Melhor troca já feita na NBA?',
     ['Kareem pro Lakers', 'Pau Gasol pro Lakers', 'Garnett pro Celtics',
      'Kawhi pro Raptors', 'Harden pro Rockets']],

    ['Melhor treinador de todos os tempos?',
     ['Phil Jackson', 'Gregg Popovich', 'Red Auerbach', 'Pat Riley', 'Erik Spoelstra']],

    ['Melhor dupla que já jogou junto?',
     ['Shaq e Kobe', 'Jordan e Pippen', 'Curry e Klay', 'LeBron e Wade', 'Stockton e Malone']],

    ['Melhor final que você já assistiu?',
     ['2016', '2013', '2010', '1998', '2021']],

    ['Melhor jogada isolada da história?',
     ['Bloqueio do LeBron em 2016', 'The Shot do Jordan', '81 pontos do Kobe',
      'Flu Game', 'Sacada do Curry contra o OKC']],

    ['Quem tem o melhor jogo aéreo da história?',
     ['Vince Carter', 'Michael Jordan', 'Dominique Wilkins', 'Zach LaVine', 'Ja Morant']],

    ['Qual estatística mais engana?',
     ['Pontos por jogo', 'Rebotes', '+/-', 'Eficiência', 'Assistências']],

    ['O que você faria com a primeira escolha do draft?',
     ['Pegar o melhor disponível', 'Trocar por mais picks', 'Trocar por um veterano pronto',
      'Escolher pela posição que falta', 'Ir no que a torcida quer']],

    ['Qual liga da FBA é a mais difícil de vencer?',
     ['ELITE', 'NEXT', 'RISE', 'ROOKIE', 'Todas dão o mesmo trabalho']],

    ['O que mais decide um título na FBA?',
     ['Draft bem feito', 'Trocas agressivas', 'Paciência pra montar',
      'Free agency', 'Sorte no chaveamento']],

    ['Qual a melhor parte da FBA?',
     ['O draft', 'As trocas', 'Os playoffs', 'A resenha no grupo', 'Ver o ranking subir']],

    ['Qual erro TODO GM já cometeu?',
     ['Se apaixonar por um jogador', 'Torrar picks futuras', 'Segurar veterano tempo demais',
      'Ignorar o CAP', 'Copiar o time do vizinho']],

    ['Time é montado como?',
     ['Pelo draft, com paciência', 'Comprando estrela pronta', 'Trocando sem parar',
      'Juntando bons role players', 'Do jeito que der']],

    ['O que você prefere numa temporada?',
     ['Título e nada mais', 'Campanha dominante', 'Ver a base crescer',
      'Uma zebra nos playoffs', 'Um MVP no elenco']],

    ['Qual desses vale mais numa troca?',
     ['Uma pick de loteria', 'Um jovem com potencial', 'Um All-Star de 30 anos',
      'Espaço no CAP', 'Dois titulares medianos']],
];

/**
 * LOTE 2 — a fila do primeiro banco acabou em 19/09/2026.
 *
 * Mesma regra do lote acima: opinião, cinco alternativas, nenhuma "certa".
 * A diferença é o que a primeira leva ensinou — as perguntas sobre a FBA
 * foram as que mais moveram o grupo (30 a 47 votos, contra 23 na pior das de
 * almanaque), então aqui elas são um quarto do lote em vez de um punhado no
 * fim.
 *
 * As cinco opções existem pra DIVIDIR. Alternativa que ninguém marca é espaço
 * desperdiçado num jogo em que o prêmio vai pra quem acerta a maioria: se uma
 * delas é obviamente a resposta, a pergunta acabou antes de começar.
 */
const QUIZ_BANCO_2 = [
    // ── Ídolos que ficaram de fora do primeiro lote ────────────────────
    ['Maior ídolo da história do Knicks?',
     ['Patrick Ewing', 'Walt Frazier', 'Willis Reed', 'Carmelo Anthony', 'Jalen Brunson']],

    ['Maior ídolo da história do Sixers?',
     ['Allen Iverson', 'Julius Erving', 'Wilt Chamberlain', 'Moses Malone', 'Joel Embiid']],

    ['Maior ídolo da história do Suns?',
     ['Steve Nash', 'Charles Barkley', 'Devin Booker', 'Amar\'e Stoudemire', 'Kevin Johnson']],

    ['Maior ídolo da história do Rockets?',
     ['Hakeem Olajuwon', 'James Harden', 'Yao Ming', 'Clyde Drexler', 'Tracy McGrady']],

    ['Maior ídolo da história do Bucks?',
     ['Giannis Antetokounmpo', 'Kareem Abdul-Jabbar', 'Oscar Robertson', 'Ray Allen', 'Sidney Moncrief']],

    ['Maior ídolo da história do Raptors?',
     ['Vince Carter', 'Kyle Lowry', 'DeMar DeRozan', 'Kawhi Leonard', 'Chris Bosh']],

    // ── Os debates que nunca acabam ────────────────────────────────────
    ['Melhor sexto homem da história?',
     ['Manu Ginóbili', 'Jamal Crawford', 'Lou Williams', 'John Havlicek', 'Kevin McHale']],

    ['Melhor temporada de calouro que você já viu?',
     ['Michael Jordan', 'LeBron James', 'Tim Duncan', 'Larry Bird', 'Victor Wembanyama']],

    ['Melhor europeu da história?',
     ['Dirk Nowitzki', 'Nikola Jokić', 'Giannis Antetokounmpo', 'Luka Dončić', 'Pau Gasol']],

    ['Maior bust da história do draft?',
     ['Anthony Bennett', 'Greg Oden', 'Darko Miličić', 'Kwame Brown', 'Sam Bowie']],

    ['Maior roubo da história do draft?',
     ['Nikola Jokić (41ª)', 'Manu Ginóbili (57ª)', 'Draymond Green (35ª)',
      'Isaiah Thomas (60ª)', 'Marc Gasol (48ª)']],

    ['Melhor jogador que nunca foi campeão?',
     ['Charles Barkley', 'Karl Malone', 'Allen Iverson', 'Steve Nash', 'Patrick Ewing']],

    ['Quem é o jogador mais subestimado da história?',
     ['Kevin Garnett', 'Chris Paul', 'Dwight Howard', 'Tracy McGrady', 'Scottie Pippen']],

    ['E o mais superestimado?',
     ['Carmelo Anthony', 'Russell Westbrook', 'Blake Griffin', 'Vince Carter', 'Nenhum, o hype é justo']],

    // ── A liga hoje ────────────────────────────────────────────────────
    ['Quem leva o próximo MVP?',
     ['Nikola Jokić', 'Shai Gilgeous-Alexander', 'Luka Dončić', 'Victor Wembanyama', 'Giannis Antetokounmpo']],

    ['Melhor jogador com menos de 25 anos?',
     ['Victor Wembanyama', 'Anthony Edwards', 'Paolo Banchero', 'Chet Holmgren', 'Cade Cunningham']],

    ['Melhor dupla da NBA hoje?',
     ['Jokić e Murray', 'Curry e Green', 'Tatum e Brown', 'SGA e Holmgren', 'Dončić e Irving']],

    ['Qual franquia está melhor montada pros próximos cinco anos?',
     ['Thunder', 'Spurs', 'Rockets', 'Magic', 'Celtics']],

    ['Melhor defensor da liga hoje?',
     ['Victor Wembanyama', 'Rudy Gobert', 'Bam Adebayo', 'Draymond Green', 'Jrue Holiday']],

    ['Qual time é a maior decepção dos últimos anos?',
     ['Suns', 'Clippers', 'Sixers', 'Lakers', 'Bucks']],

    // ── E se ───────────────────────────────────────────────────────────
    ['Começando uma franquia do zero hoje, quem você escolhe?',
     ['Victor Wembanyama', 'Nikola Jokić', 'Luka Dončić', 'Anthony Edwards', 'Shai Gilgeous-Alexander']],

    ['Um jogo pela vida: quem toma o último arremesso?',
     ['Michael Jordan', 'Stephen Curry', 'Kobe Bryant', 'Larry Bird', 'Damian Lillard']],

    ['Qual era do basquete você escolheria pra jogar?',
     ['Anos 80', 'Anos 90', 'Anos 2000', 'Anos 2010', 'Hoje']],

    ['Se desse pra mudar uma regra da NBA, qual seria?',
     ['Acabar com o load management', 'Voltar a deixar o jogo mais físico',
      'Mudar o formato do play-in', 'Linha de 4 pontos', 'Não mudaria nada']],

    ['O que você prefere numa carreira?',
     ['Um anel e nada mais', 'Cinco anéis de coadjuvante', 'MVP sem título',
      'Ser o maior pontuador da história', 'Uma franquia inteira na sua mão']],

    ['Qual lesão mais mudou a história da NBA?',
     ['Derrick Rose', 'Kevin Durant em 2019', 'Klay Thompson', 'Greg Oden', 'Yao Ming']],

    ['Melhor time que NÃO foi campeão?',
     ['Suns de 2005', 'Kings de 2002', 'Sixers de 2001', 'Jazz de 1997', 'Blazers de 1992']],

    // ── Resenha ────────────────────────────────────────────────────────
    ['Melhor tênis de basquete de todos os tempos?',
     ['Air Jordan 1', 'Air Jordan 11', 'Kobe 4', 'Curry 1', 'LeBron 8']],

    ['Melhor documentário de basquete?',
     ['The Last Dance', 'Hoop Dreams', 'Winning Time', 'The Redeem Team', 'Nunca vi nenhum']],

    ['Melhor NBA 2K de todos os tempos?',
     ['2K11', '2K14', '2K16', '2K20', 'O mais novo sempre']],

    ['Uniforme retrô mais bonito?',
     ['Sonics anos 90', 'Raptors roxo', 'Grizzlies de Vancouver', 'Nuggets arco-íris', 'Hornets teal']],

    ['O que faz um jogo ser inesquecível?',
     ['Uma virada absurda', 'Um duelo individual', 'Uma cesta no estouro',
      'Uma zebra nos playoffs', 'O clima da arena']],

    // ── A FBA (foi o que mais moveu o grupo no primeiro lote) ──────────
    ['O que você mais gosta de fazer na FBA?',
     ['Montar o elenco', 'Negociar trocas', 'Acompanhar as simulações',
      'Analisar os rivais', 'Resenhar no grupo']],

    ['Qual a fase mais divertida da temporada?',
     ['A offseason', 'O draft', 'A trade deadline', 'Os playoffs', 'A loteria']],

    ['O que dá mais raiva na FBA?',
     ['Proposta ignorada', 'Perder no detalhe', 'Ver o rival roubar na trade',
      'Simulação não colaborar', 'Ficar sem pick']],

    ['Que tipo de GM você é?',
     ['Paciente, monta pelo draft', 'Agressivo, troca toda semana',
      'Colecionador de picks', 'Vai de estrela pronta', 'Depende do dia']],

    ['O que ganha mais na FBA?',
     ['Um elenco todo em 80', 'Dois superastros', 'Um astro e bons role players',
      'Profundidade pra rodar', 'Sorte na simulação']],

    ['Qual liga da FBA você queria disputar?',
     ['ELITE, pelo nível', 'NEXT, pelo equilíbrio', 'RISE, pela disputa',
      'ROOKIE, pra construir do zero', 'A minha mesmo, tô bem']],

    ['O que você mais quer ver no app da FBA?',
     ['Mais estatísticas', 'Mais coisa no bot', 'Mais jogos no Games',
      'Rankings históricos', 'Tá bom do jeito que tá']],

    ['Qual foi a melhor troca da história da FBA?',
     ['A que me fez campeão', 'A que o rival se arrependeu',
      'A que ninguém entendeu na hora', 'A que envolveu meio elenco',
      'Nenhuma, a liga ainda vai ver a melhor']],
];

/**
 * Enche a fila com um lote, uma vez só.
 *
 * O semeador antigo só rodava com a tabela VAZIA — escrito assim pra não
 * ressuscitar o que o admin apagasse pela tela. Só que isso também impedia
 * lote novo de entrar: as perguntas do lote 2 ficariam no arquivo pra sempre,
 * com a fila zerada.
 *
 * Agora cada lote tem a sua marca em `app_flags` e entra uma vez. O que o
 * admin apagar continua apagado: a flag já está lá e o lote não volta.
 *
 * `ordem` continua a fila. O lote novo começa depois do último para estrear
 * na sequência, e não embaralhado no meio do que já passou.
 */
function quizSemearLote(PDO $pdo, string $flag, array $banco): int
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_flags (
            flag VARCHAR(100) PRIMARY KEY,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $st = $pdo->prepare("SELECT 1 FROM app_flags WHERE flag = ?");
        $st->execute([$flag]);
        if ($st->fetchColumn()) return 0;

        $base = (int)$pdo->query("SELECT COALESCE(MAX(ordem), -1) + 1 FROM quiz_perguntas")->fetchColumn();
        $ins  = $pdo->prepare("INSERT INTO quiz_perguntas (pergunta, opcoes, ordem) VALUES (?,?,?)");
        foreach ($banco as $i => [$pergunta, $opcoes]) {
            $ins->execute([$pergunta, json_encode($opcoes, JSON_UNESCAPED_UNICODE), $base + $i]);
        }
        $pdo->prepare("INSERT IGNORE INTO app_flags (flag) VALUES (?)")->execute([$flag]);
        return count($banco);
    } catch (Throwable $e) {
        error_log('[quiz] semear ' . $flag . ': ' . $e->getMessage());
        return 0;
    }
}

/** Enche a fila uma única vez, quando a tabela ainda está vazia. */
function quizSemear(PDO $pdo): void
{
    try {
        if ((int)$pdo->query("SELECT COUNT(*) FROM quiz_perguntas")->fetchColumn() === 0) {
            $st = $pdo->prepare("INSERT INTO quiz_perguntas (pergunta, opcoes, ordem) VALUES (?,?,?)");
            foreach (QUIZ_BANCO as $i => [$pergunta, $opcoes]) {
                $st->execute([$pergunta, json_encode($opcoes, JSON_UNESCAPED_UNICODE), $i]);
            }
        }
        // Os lotes seguintes entram por marca, e não por "a tabela está vazia".
        quizSemearLote($pdo, 'quiz_lote2_2026_09', QUIZ_BANCO_2);
    } catch (Throwable $e) {
        error_log('[quiz] semear: ' . $e->getMessage());
    }
}
