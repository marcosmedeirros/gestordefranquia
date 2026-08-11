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

/** Enche a fila uma única vez, quando a tabela ainda está vazia. */
function quizSemear(PDO $pdo): void
{
    try {
        if ((int)$pdo->query("SELECT COUNT(*) FROM quiz_perguntas")->fetchColumn() > 0) return;

        $st = $pdo->prepare("INSERT INTO quiz_perguntas (pergunta, opcoes, ordem) VALUES (?,?,?)");
        foreach (QUIZ_BANCO as $i => [$pergunta, $opcoes]) {
            $st->execute([$pergunta, json_encode($opcoes, JSON_UNESCAPED_UNICODE), $i]);
        }
    } catch (Throwable $e) {
        error_log('[quiz] semear: ' . $e->getMessage());
    }
}
