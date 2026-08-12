<?php
/**
 * O banco inicial de perguntas do quiz.
 *
 * Formato de cada linha:
 *   [tipo, categoria, pergunta, [op1, op2, op3, op4], correta|null, explicacao|null]
 *
 * tipo 'certa' tem resposta verificável e a `correta` aponta a opção (1 a 4).
 * tipo 'votos' é opinião: não existe errado, ganha quem votou na mais votada.
 *
 * Sobre as de 'certa': só entram fatos consolidados, de recorde fechado ou
 * história antiga. Nada de "quem lidera a temporada" — isso envelhece e o bot
 * passa a corrigir o grupo com informação velha, que é pior que não perguntar.
 *
 * Carregado por api/quiz-admin.php (botão "Popular banco"). Rodar duas vezes
 * não duplica: a checagem é pelo texto da pergunta.
 */

return [

// ══════════════════════════════════════════════════════════════════════
// RECORDES E MARCAS — resposta certa, fato fechado
// ══════════════════════════════════════════════════════════════════════
['certa','Recordes','Quem é o maior cestinha da história da NBA?',
 ['Kareem Abdul-Jabbar','LeBron James','Karl Malone','Michael Jordan'],2,
 'LeBron passou Kareem em fevereiro de 2023.'],

['certa','Recordes','Quem marcou 100 pontos numa única partida?',
 ['Wilt Chamberlain','Kobe Bryant','Elgin Baylor','David Thompson'],1,
 'Contra os Knicks, em 1962. Kobe chegou a 81, o segundo maior.'],

['certa','Recordes','Quem tem mais rebotes na história da NBA?',
 ['Bill Russell','Wilt Chamberlain','Kareem Abdul-Jabbar','Moses Malone'],2,
 'Wilt, com mais de 23 mil.'],

['certa','Recordes','Quem tem mais assistências na história da NBA?',
 ['Magic Johnson','Jason Kidd','John Stockton','Chris Paul'],3,
 'Stockton, com mais de 15 mil — e ninguém chegou perto.'],

['certa','Recordes','Quem tem mais roubos de bola na história?',
 ['Michael Jordan','Gary Payton','John Stockton','Scottie Pippen'],3,
 'Stockton lidera assistências e roubos ao mesmo tempo.'],

['certa','Recordes','Quem tem mais tocos na história da NBA?',
 ['Dikembe Mutombo','Hakeem Olajuwon','Mark Eaton','Kareem Abdul-Jabbar'],2,
 'Hakeem, com mais de 3.800.'],

['certa','Recordes','Quem acertou mais bolas de 3 na história?',
 ['Ray Allen','Reggie Miller','Stephen Curry','James Harden'],3,
 'Curry passou Ray Allen em 2021 e nunca mais foi alcançado.'],

['certa','Recordes','Quantos títulos Bill Russell ganhou como jogador?',
 ['8','9','11','13'],3,
 'Onze em treze temporadas, com o Celtics.'],

['certa','Recordes','Qual foi a melhor campanha de temporada regular da história?',
 ['72-10 do Bulls','73-9 do Warriors','69-13 do Lakers','70-12 do Bulls'],2,
 'Golden State em 2015-16 — e perdeu a final.'],

['certa','Recordes','Quem tem a maior sequência de jogos consecutivos disputados?',
 ['A.C. Green','Karl Malone','John Stockton','Randy Smith'],1,
 'A.C. Green, 1.192 jogos seguidos.'],

['certa','Recordes','Qual o recorde de assistências numa única partida?',
 ['25','27','30','32'],2,
 'Scott Skiles, 30 assistências em 1990.'],

['certa','Recordes','Quem tem mais jogos disputados na história da NBA?',
 ['Robert Parish','Kareem Abdul-Jabbar','Vince Carter','Dirk Nowitzki'],1,
 'Robert Parish, 1.611 jogos.'],

['certa','Recordes','Quantas temporadas Vince Carter jogou na NBA?',
 ['19','20','21','22'],4,
 'Vinte e duas — recorde de longevidade.'],

['certa','Recordes','Quem tem mais triplos-duplos na história?',
 ['Magic Johnson','Oscar Robertson','Russell Westbrook','Jason Kidd'],3,
 'Westbrook passou Oscar Robertson em 2021.'],

['certa','Recordes','Quem foi o primeiro a fazer média de triplo-duplo numa temporada?',
 ['Magic Johnson','Oscar Robertson','Wilt Chamberlain','Russell Westbrook'],2,
 'Oscar Robertson, em 1961-62. Westbrook repetiu 55 anos depois.'],

// ══════════════════════════════════════════════════════════════════════
// PRÊMIOS
// ══════════════════════════════════════════════════════════════════════
['certa','Prêmios','Quem ganhou mais MVPs de temporada regular?',
 ['Michael Jordan','Bill Russell','Kareem Abdul-Jabbar','LeBron James'],3,
 'Kareem, seis vezes.'],

['certa','Prêmios','Quem foi o único a ganhar o MVP por unanimidade?',
 ['Michael Jordan','LeBron James','Stephen Curry','Shaquille O\'Neal'],3,
 'Curry, na temporada de 73-9.'],

['certa','Prêmios','Quem ganhou o Defensor do Ano quatro vezes?',
 ['Ben Wallace','Dikembe Mutombo','Rudy Gobert','Ambos Ben Wallace e Mutombo'],4,
 'Os dois têm quatro cada. Gobert também chegou a quatro depois.'],

['certa','Prêmios','Quem foi MVP das Finais em três finais seguidas?',
 ['Michael Jordan','Shaquille O\'Neal','LeBron James','Kevin Durant'],2,
 'Shaq, de 2000 a 2002 com o Lakers.'],

['certa','Prêmios','Qual jogador ganhou MVP, Defensor do Ano e o título no mesmo ano?',
 ['Hakeem Olajuwon','Michael Jordan','Kevin Garnett','Giannis Antetokounmpo'],1,
 'Hakeem, em 1994. Feito raríssimo.'],

['certa','Prêmios','Quem ganhou o Novato do Ano em 2003-04?',
 ['Carmelo Anthony','LeBron James','Dwyane Wade','Chris Bosh'],2,
 'LeBron, na classe de 2003.'],

['certa','Prêmios','Quem ganhou o Sexto Homem do Ano três vezes?',
 ['Jamal Crawford','Lou Williams','Kevin McHale','Ricky Pierce'],1,
 'Jamal Crawford — Lou Williams também chegou a três depois.'],

['certa','Prêmios','Quem foi MVP da temporada aos 35 anos ou mais?',
 ['Michael Jordan','Karl Malone','Kareem Abdul-Jabbar','LeBron James'],2,
 'Karl Malone, em 1998-99, aos 35.'],

['certa','Prêmios','Quantos MVPs Michael Jordan ganhou?',
 ['4','5','6','7'],2,
 'Cinco MVPs de temporada e seis de Finais.'],

['certa','Prêmios','Quem ganhou MVP jogando por dois times diferentes?',
 ['Wilt Chamberlain','Moses Malone','Kareem Abdul-Jabbar','Todos eles'],4,
 'Os três trocaram de time entre MVPs.'],

// ══════════════════════════════════════════════════════════════════════
// DRAFT
// ══════════════════════════════════════════════════════════════════════
['certa','Draft','Quem foi a primeira escolha do draft de 2003?',
 ['Carmelo Anthony','Darko Miličić','LeBron James','Dwyane Wade'],3,
 'LeBron. Darko foi a segunda — o maior erro da história recente.'],

['certa','Draft','Em que posição Michael Jordan foi draftado?',
 ['1ª','2ª','3ª','5ª'],3,
 'Terceiro, atrás de Hakeem e Sam Bowie.'],

['certa','Draft','Em que posição Kobe Bryant foi draftado?',
 ['5ª','13ª','17ª','21ª'],2,
 'Décimo terceiro, pelo Charlotte, e trocado no mesmo dia pro Lakers.'],

['certa','Draft','Quem foi a primeira escolha do draft de 1997?',
 ['Tim Duncan','Chauncey Billups','Tracy McGrady','Keith Van Horn'],1,
 'Tim Duncan, pelo San Antonio.'],

['certa','Draft','Em que posição Giannis Antetokounmpo foi draftado?',
 ['9ª','15ª','21ª','30ª'],2,
 'Décimo quinto, em 2013.'],

['certa','Draft','Quem foi a primeira escolha do draft de 2009?',
 ['Blake Griffin','James Harden','Stephen Curry','Ricky Rubio'],1,
 'Blake Griffin. Curry foi a sétima.'],

['certa','Draft','Em que posição Nikola Jokić foi draftado?',
 ['24ª','31ª','41ª','55ª'],3,
 'Quadragésimo primeiro, na segunda rodada — e virou MVP.'],

['certa','Draft','Quem foi a primeira escolha do draft de 2014?',
 ['Andrew Wiggins','Jabari Parker','Joel Embiid','Dante Exum'],1,
 'Wiggins, pelo Cleveland, e trocado por Kevin Love.'],

['certa','Draft','Quem foi a primeira escolha do draft de 2018?',
 ['Deandre Ayton','Luka Dončić','Trae Young','Marvin Bagley III'],1,
 'Ayton. Luka foi a terceira, trocado com Trae Young.'],

['certa','Draft','Qual draft ficou conhecido como a classe de 1996?',
 ['Kobe, Iverson, Nash','LeBron, Melo, Wade','Duncan, McGrady','Magic, Bird'],1,
 'Iverson foi a primeira escolha; Kobe a 13ª e Nash a 15ª.'],

['certa','Draft','Em que posição Isiah Thomas (o Pequeno) foi draftado em 2011?',
 ['30ª','45ª','56ª','60ª — a última'],4,
 'Última escolha do draft, e virou All-Star.'],

// ══════════════════════════════════════════════════════════════════════
// TIMES E ELENCOS — "quem faltava nesse time"
// ══════════════════════════════════════════════════════════════════════
['certa','Elencos','No Lakers campeão de 2020, quem era a dupla principal?',
 ['LeBron e Davis','LeBron e Westbrook','Kobe e Gasol','Davis e Howard'],1,
 'LeBron e Anthony Davis, na bolha de Orlando.'],

['certa','Elencos','No Heat do "Big Three", quem completava LeBron e Wade?',
 ['Chris Bosh','Carmelo Anthony','Ray Allen','Shane Battier'],1,
 'Chris Bosh, de 2010 a 2014.'],

['certa','Elencos','No Celtics campeão de 2008, quem era o terceiro do trio?',
 ['Rajon Rondo','Ray Allen','Kendrick Perkins','Glen Davis'],2,
 'Garnett, Pierce e Ray Allen.'],

['certa','Elencos','No Warriors de 2017, quem se juntou a Curry, Klay e Draymond?',
 ['Kevin Durant','DeMarcus Cousins','Andre Iguodala','Andrew Wiggins'],1,
 'Durant chegou em 2016 e ganhou dois títulos seguidos.'],

['certa','Elencos','No Spurs campeão de 2014, quem formava o trio com Duncan e Parker?',
 ['Kawhi Leonard','Manu Ginóbili','Boris Diaw','Danny Green'],2,
 'Manu — o trio durou 2002 a 2018.'],

['certa','Elencos','No Bulls do segundo tricampeonato, quem era o terceiro atrás de Jordan e Pippen?',
 ['Toni Kukoč','Dennis Rodman','Steve Kerr','Ron Harper'],2,
 'Rodman chegou em 1995 e ficou nos três títulos.'],

['certa','Elencos','No Detroit campeão de 2004, quem foi o MVP das Finais?',
 ['Ben Wallace','Rasheed Wallace','Chauncey Billups','Richard Hamilton'],3,
 'Billups, contra o Lakers dos quatro astros.'],

['certa','Elencos','No Dallas campeão de 2011, quem era o astro?',
 ['Dirk Nowitzki','Jason Kidd','Jason Terry','Tyson Chandler'],1,
 'Dirk, contra o Heat do Big Three.'],

['certa','Elencos','No Cleveland campeão de 2016, quem deu o toco decisivo no jogo 7?',
 ['Tristan Thompson','LeBron James','Kevin Love','Kyrie Irving'],2,
 'O bloqueio em Iguodala, um dos lances mais lembrados da história.'],

['certa','Elencos','No Raptors campeão de 2019, quem era a estrela?',
 ['Kyle Lowry','Pascal Siakam','Kawhi Leonard','Marc Gasol'],3,
 'Kawhi, em sua única temporada em Toronto.'],

['certa','Elencos','No Lakers dos quatro astros em 2003-04, quem NÃO estava?',
 ['Karl Malone','Gary Payton','Shaquille O\'Neal','Ray Allen'],4,
 'Malone e Payton chegaram pra jogar com Shaq e Kobe. Perderam a final.'],

['certa','Elencos','No Phoenix do "sete segundos ou menos", quem era o armador?',
 ['Steve Nash','Jason Kidd','Kevin Johnson','Goran Dragić'],1,
 'Nash, com Mike D\'Antoni no comando.'],

['certa','Elencos','No Seattle SuperSonics de 1996, quem era a dupla?',
 ['Payton e Kemp','Payton e Durant','Kemp e Baker','Payton e Schrempf'],1,
 'Gary Payton e Shawn Kemp, finalistas contra o Bulls de 72-10.'],

['certa','Elencos','No Utah Jazz dos anos 90, quem formava a dupla com Stockton?',
 ['Karl Malone','Jeff Hornacek','Adrian Dantley','Mark Eaton'],1,
 'Stockton e Malone, o pick and roll mais famoso da história.'],

['certa','Elencos','No Rockets bicampeão de 1994-95, quem era o astro?',
 ['Clyde Drexler','Hakeem Olajuwon','Charles Barkley','Sam Cassell'],2,
 'Hakeem. Drexler chegou pro segundo título.'],

// ══════════════════════════════════════════════════════════════════════
// FINAIS E MOMENTOS
// ══════════════════════════════════════════════════════════════════════
['certa','Finais','Contra quem o Bulls venceu a final de 1998?',
 ['Utah Jazz','Seattle SuperSonics','Portland Trail Blazers','Phoenix Suns'],1,
 'O último título da era Jordan, com a cesta final sobre Bryon Russell.'],

['certa','Finais','Qual time virou uma final estando 3-1 atrás?',
 ['Cleveland em 2016','Miami em 2013','Boston em 2010','Golden State em 2016'],1,
 'Única virada de 3-1 numa final da NBA.'],

['certa','Finais','Em que ano o Warriors ganhou o primeiro título da era Curry?',
 ['2014','2015','2016','2017'],2,
 '2015, contra o Cleveland.'],

['certa','Finais','Quem venceu as Finais de 2021?',
 ['Phoenix Suns','Milwaukee Bucks','Brooklyn Nets','Atlanta Hawks'],2,
 'Milwaukee, com Giannis fazendo 50 no jogo do título.'],

['certa','Finais','Quantos pontos Giannis fez no jogo 6 do título de 2021?',
 ['41','45','50','55'],3,
 'Cinquenta pontos e 14 rebotes.'],

['certa','Finais','Quem ganhou a final de 2019?',
 ['Golden State','Toronto','Milwaukee','Portland'],2,
 'Primeiro título da história do Raptors.'],

['certa','Finais','Qual dupla decidiu a final de 2023?',
 ['Jokić e Murray','Butler e Adebayo','Tatum e Brown','Booker e Durant'],1,
 'Denver campeão, com Jokić MVP das Finais.'],

['certa','Finais','Em que ano o Boston Celtics voltou a ser campeão depois de 2008?',
 ['2022','2023','2024','2025'],3,
 '2024, contra o Dallas.'],

// ══════════════════════════════════════════════════════════════════════
// INTERNACIONAIS
// ══════════════════════════════════════════════════════════════════════
['certa','Mundo','De que país é Nikola Jokić?',
 ['Croácia','Sérvia','Eslovênia','Montenegro'],2,
 'Sérvio, de Sombor.'],

['certa','Mundo','De que país é Luka Dončić?',
 ['Sérvia','Croácia','Eslovênia','Grécia'],3,
 'Esloveno, de Ljubljana.'],

['certa','Mundo','De que país é Giannis Antetokounmpo?',
 ['Nigéria','Grécia','Camarões','Turquia'],2,
 'Grego, filho de nigerianos — daí o apelido Greek Freak.'],

['certa','Mundo','De que país é Joel Embiid?',
 ['Camarões','Senegal','Nigéria','República do Congo'],1,
 'Camaronês, e depois naturalizado americano e francês.'],

['certa','Mundo','Qual foi o primeiro europeu a ser MVP da NBA?',
 ['Dirk Nowitzki','Pau Gasol','Tony Parker','Nikola Jokić'],1,
 'Dirk, em 2007.'],

['certa','Mundo','Quem foi o primeiro brasileiro campeão da NBA?',
 ['Oscar Schmidt','Nenê','Leandrinho Barbosa','Anderson Varejão'],3,
 'Leandrinho, pelo Golden State em 2015.'],

['certa','Mundo','Qual brasileiro foi a escolha mais alta de draft da história?',
 ['Nenê','Leandrinho','Anderson Varejão','Tiago Splitter'],1,
 'Nenê, sétima escolha em 2002.'],

['certa','Mundo','De que país é Dirk Nowitzki?',
 ['Áustria','Alemanha','Suíça','Holanda'],2,
 'Alemão, de Würzburg, 21 temporadas no Dallas.'],

['certa','Mundo','De que país é Manu Ginóbili?',
 ['Espanha','Brasil','Argentina','Uruguai'],3,
 'Argentino, ouro olímpico em 2004.'],

['certa','Mundo','Qual país ganhou o ouro olímpico de basquete em 2004?',
 ['Estados Unidos','Argentina','Itália','Lituânia'],2,
 'A Argentina da geração dourada, com Ginóbili.'],

['certa','Mundo','De que país é Rudy Gobert?',
 ['Bélgica','França','Suíça','Canadá'],2,
 'Francês — quatro vezes Defensor do Ano.'],

['certa','Mundo','De que país é Victor Wembanyama?',
 ['França','Bélgica','Canadá','Alemanha'],1,
 'Francês, primeira escolha do draft de 2023.'],

// ══════════════════════════════════════════════════════════════════════
// APELIDOS E CURIOSIDADES
// ══════════════════════════════════════════════════════════════════════
['certa','Apelidos','Quem era chamado de "The Dream"?',
 ['Hakeem Olajuwon','Julius Erving','Clyde Drexler','David Robinson'],1,
 'Pelo Dream Shake, o drible de pivô mais copiado da história.'],

['certa','Apelidos','Quem era "The Admiral"?',
 ['Patrick Ewing','David Robinson','Alonzo Mourning','Dikembe Mutombo'],2,
 'David Robinson serviu na Marinha antes da NBA.'],

['certa','Apelidos','Quem era "The Mailman"?',
 ['Karl Malone','Moses Malone','Charles Barkley','Dominique Wilkins'],1,
 'Karl Malone — "porque ele sempre entrega".'],

['certa','Apelidos','Quem era "The Answer"?',
 ['Allen Iverson','Tracy McGrady','Vince Carter','Stephon Marbury'],1,
 'Iverson, 1,83m e primeira escolha do draft.'],

['certa','Apelidos','Quem era "The Glove"?',
 ['Gary Payton','Scottie Pippen','Dennis Rodman','Bruce Bowen'],1,
 'Gary Payton, o único armador Defensor do Ano.'],

['certa','Apelidos','Quem era "The Round Mound of Rebound"?',
 ['Shaquille O\'Neal','Charles Barkley','Wilt Chamberlain','Moses Malone'],2,
 'Barkley, 1,98m pegando rebote contra pivôs.'],

['certa','Apelidos','Quem era o "Human Highlight Film"?',
 ['Dominique Wilkins','Vince Carter','Julius Erving','Clyde Drexler'],1,
 'Dominique Wilkins, do Atlanta.'],

['certa','Apelidos','Quem é "The Greek Freak"?',
 ['Giannis Antetokounmpo','Nikola Jokić','Kristaps Porziņģis','Luka Dončić'],1,
 'Giannis.'],

['certa','Apelidos','Quem é chamado de "The Joker"?',
 ['Luka Dončić','Nikola Jokić','Nikola Vučević','Bogdan Bogdanović'],2,
 'Jokić — o apelido é um trocadilho com o sobrenome.'],

['certa','Apelidos','Quem era "The Big Fundamental"?',
 ['Tim Duncan','David Robinson','Kevin Garnett','Chris Webber'],1,
 'Apelido dado por Shaq — nada de firula, só o essencial.'],

// ══════════════════════════════════════════════════════════════════════
// FRANQUIAS
// ══════════════════════════════════════════════════════════════════════
['certa','Franquias','Qual franquia tem mais títulos da NBA?',
 ['Los Angeles Lakers','Boston Celtics','Chicago Bulls','Golden State Warriors'],2,
 'Boston, com 18 — Lakers empatou e depois foi ultrapassado de novo.'],

['certa','Franquias','Em que cidade o Lakers começou?',
 ['Los Angeles','Minneapolis','San Diego','Rochester'],2,
 'Minneapolis — daí o nome, terra dos lagos.'],

['certa','Franquias','Qual time nunca ganhou um título da NBA?',
 ['Phoenix Suns','Denver Nuggets','Toronto Raptors','Milwaukee Bucks'],1,
 'Phoenix chegou a três finais e perdeu todas.'],

['certa','Franquias','Que time era o Oklahoma City Thunder antes de mudar?',
 ['Seattle SuperSonics','Vancouver Grizzlies','New Orleans Hornets','Charlotte Bobcats'],1,
 'Seattle, até 2008.'],

['certa','Franquias','Qual franquia joga no Madison Square Garden?',
 ['Brooklyn Nets','New York Knicks','Philadelphia 76ers','Boston Celtics'],2,
 'Knicks — a arena é de 1968.'],

['certa','Franquias','Em que ano os Raptors e os Grizzlies entraram na NBA?',
 ['1988','1995','2004','1980'],2,
 '1995, a expansão canadense. O Grizzlies mudou pra Memphis em 2001.'],

// ══════════════════════════════════════════════════════════════════════
// A FBA — usa o que o pessoal vive
// ══════════════════════════════════════════════════════════════════════
['certa','FBA','Quantos jogadores contam pro CAP fora da ELITE?',
 ['Os 5 titulares','Os 8 melhores','Os 10 melhores','O elenco inteiro'],3,
 'A soma dos 10 maiores OVRs. Na ELITE o cap é folha em dinheiro.'],

['certa','FBA','O que significa SB numa pick com swap?',
 ['Fica com a melhor','Fica com a pior','Swap bloqueado','Segunda rodada'],1,
 'SB pega a melhor das duas vagas; SW fica com a pior.'],

['certa','FBA','Quais ligas da FBA têm swap de pick?',
 ['Todas','Só a ELITE','ELITE e NEXT','Nenhuma'],3,
 'RISE e ROOKIE não têm swap.'],

['certa','FBA','Em que rodada existe swap de pick na FBA?',
 ['Só na 1ª','Só na 2ª','Nas duas','Depende da liga'],1,
 'Só a primeira rodada.'],

['certa','FBA','Qual é o limite de casamento salarial numa trade da ELITE?',
 ['100%','110%','120%','125%'],3,
 'Quem recebe não pode passar de 120% do que envia.'],

// ══════════════════════════════════════════════════════════════════════
// QUEM FOI MELHOR — sem resposta certa, ganha a mais votada
// ══════════════════════════════════════════════════════════════════════
['votos','Quem foi melhor','Quem foi o maior de todos os tempos?',
 ['Michael Jordan','LeBron James','Kareem Abdul-Jabbar','Bill Russell'],null,null],

['votos','Quem foi melhor','Quem você quer com a bola no último arremesso?',
 ['Michael Jordan','Kobe Bryant','Larry Bird','Stephen Curry'],null,null],

['votos','Quem foi melhor','Melhor armador da história?',
 ['Magic Johnson','Stephen Curry','John Stockton','Oscar Robertson'],null,null],

['votos','Quem foi melhor','Melhor ala-armador depois do Jordan?',
 ['Kobe Bryant','Dwyane Wade','Tracy McGrady','James Harden'],null,null],

['votos','Quem foi melhor','Melhor pivô da história?',
 ['Kareem Abdul-Jabbar','Shaquille O\'Neal','Hakeem Olajuwon','Wilt Chamberlain'],null,null],

['votos','Quem foi melhor','Melhor defensor que já jogou?',
 ['Bill Russell','Dennis Rodman','Hakeem Olajuwon','Kawhi Leonard'],null,null],

['votos','Quem foi melhor','Quem foi o melhor ala-pivô?',
 ['Tim Duncan','Karl Malone','Dirk Nowitzki','Kevin Garnett'],null,null],

['votos','Quem foi melhor','Melhor jogador do Leste Europeu?',
 ['Nikola Jokić','Luka Dončić','Dražen Petrović','Toni Kukoč'],null,null],

['votos','Quem foi melhor','Melhor jogador europeu de todos os tempos?',
 ['Dirk Nowitzki','Nikola Jokić','Pau Gasol','Luka Dončić'],null,null],

['votos','Quem foi melhor','Melhor arremessador da história?',
 ['Stephen Curry','Ray Allen','Reggie Miller','Larry Bird'],null,null],

['votos','Quem foi melhor','Quem tinha o melhor primeiro passo?',
 ['Allen Iverson','Derrick Rose','Kyrie Irving','Ja Morant'],null,null],

['votos','Quem foi melhor','Melhor enterrada da história?',
 ['Vince Carter em Sydney','Jordan da linha do lance livre','Dr. J na baseline','LeBron sobre Terrence Ross'],null,null],

['votos','Quem foi melhor','Qual dinastia foi mais dominante?',
 ['Bulls dos anos 90','Celtics do Russell','Lakers do Showtime','Warriors de 2015-19'],null,null],

['votos','Quem foi melhor','Qual foi o melhor Big Three?',
 ['Celtics 2008','Heat 2012','Warriors 2017','Spurs 2005'],null,null],

['votos','Quem foi melhor','Melhor temporada individual da história?',
 ['Jordan 1987-88','Curry 2015-16','LeBron 2012-13','Wilt 1961-62'],null,null],

['votos','Quem foi melhor','Quem tem o melhor legado fora de quadra?',
 ['Bill Russell','Kareem Abdul-Jabbar','LeBron James','Magic Johnson'],null,null],

['votos','Quem foi melhor','Melhor sexto homem que já existiu?',
 ['Manu Ginóbili','Jamal Crawford','Lou Williams','John Havlicek'],null,null],

['votos','Quem foi melhor','Quem foi mais prejudicado por lesão?',
 ['Derrick Rose','Grant Hill','Brandon Roy','Bill Walton'],null,null],

['votos','Quem foi melhor','Melhor rivalidade individual da história?',
 ['Bird x Magic','Jordan x Isiah','LeBron x Curry','Shaq x Kobe'],null,null],

['votos','Quem foi melhor','Qual final foi a melhor de todas?',
 ['2016 Cleveland x Golden State','1998 Bulls x Jazz','2013 Heat x Spurs','2010 Lakers x Celtics'],null,null],

['votos','Quem foi melhor','Quem você escolheria pra começar uma franquia hoje?',
 ['Victor Wembanyama','Luka Dončić','Nikola Jokić','Anthony Edwards'],null,null],

['votos','Quem foi melhor','Melhor calouro da história?',
 ['Wilt Chamberlain','Michael Jordan','LeBron James','Larry Bird'],null,null],

['votos','Quem foi melhor','Quem tinha o melhor arremesso de meia distância?',
 ['Michael Jordan','Kobe Bryant','Dirk Nowitzki','Kevin Durant'],null,null],

['votos','Quem foi melhor','Melhor duelo de pivôs que você já viu?',
 ['Shaq x Hakeem','Wilt x Russell','Jokić x Embiid','Ewing x Mourning'],null,null],

['votos','Quem foi melhor','Quem envelheceu melhor como jogador?',
 ['LeBron James','Kareem Abdul-Jabbar','Tim Duncan','Karl Malone'],null,null],

['votos','Quem foi melhor','Quem era mais difícil de defender?',
 ['Shaquille O\'Neal','Michael Jordan','Kevin Durant','Nikola Jokić'],null,null],

['votos','Quem foi melhor','Melhor passador que não era armador?',
 ['Nikola Jokić','LeBron James','Larry Bird','Bill Walton'],null,null],

['votos','Quem foi melhor','Quem teve o melhor auge, mesmo que curto?',
 ['Derrick Rose','Tracy McGrady','Penny Hardaway','Brandon Roy'],null,null],

['votos','Quem foi melhor','Qual trade mudou mais a NBA?',
 ['Kobe pro Lakers','Garnett pro Celtics','Kawhi pro Raptors','Gasol pro Lakers'],null,null],

['votos','Quem foi melhor','Melhor treinador da história?',
 ['Phil Jackson','Gregg Popovich','Pat Riley','Red Auerbach'],null,null],

['votos','Quem foi melhor','Quem tinha a melhor mentalidade competitiva?',
 ['Michael Jordan','Kobe Bryant','Larry Bird','Kevin Garnett'],null,null],

['votos','Quem foi melhor','Melhor jogador que nunca foi campeão?',
 ['Charles Barkley','Karl Malone','Allen Iverson','Steve Nash'],null,null],

['votos','Quem foi melhor','Qual foi a maior zebra de playoff?',
 ['Warriors 2007 sobre Dallas','Knicks 1999 de 8º à final','Grizzlies 2011 sobre Spurs','Nuggets 1994 sobre Sonics'],null,null],

['votos','Quem foi melhor','Quem tinha o melhor handle?',
 ['Kyrie Irving','Allen Iverson','Chris Paul','Jamal Crawford'],null,null],

['votos','Quem foi melhor','Melhor camisa da NBA?',
 ['Bulls vermelha','Lakers roxo e ouro','Celtics verde','Raptors roxa dos anos 90'],null,null],

['votos','Quem foi melhor','Quem era mais divertido de assistir?',
 ['Allen Iverson','Vince Carter','Stephen Curry','Magic Johnson'],null,null],

['votos','Quem foi melhor','Melhor dupla que já jogou junta?',
 ['Stockton e Malone','Shaq e Kobe','Curry e Klay','Jordan e Pippen'],null,null],

['votos','Quem foi melhor','Quem foi o maior brasileiro da NBA?',
 ['Nenê','Leandrinho Barbosa','Anderson Varejão','Tiago Splitter'],null,null],

['votos','Quem foi melhor','Quem tinha o melhor gancho?',
 ['Kareem Abdul-Jabbar','Hakeem Olajuwon','Tim Duncan','George Mikan'],null,null],

['votos','Quem foi melhor','Melhor defensor de perímetro?',
 ['Kawhi Leonard','Scottie Pippen','Gary Payton','Bruce Bowen'],null,null],

// ══════════════════════════════════════════════════════════════════════
// QUEM FOI MELHOR — da FBA
// ══════════════════════════════════════════════════════════════════════
['votos','FBA','Qual liga da FBA é mais competitiva?',
 ['ELITE','NEXT','RISE','ROOKIE'],null,null],

['votos','FBA','O que mais decide um título na FBA?',
 ['Elenco montado','Draft bem feito','Trades no momento certo','Tática'],null,null],

['votos','FBA','Qual é o pior erro de um GM novato?',
 ['Gastar todas as picks','Estourar o cap','Não atualizar o elenco','Trocar jovem por veterano'],null,null],

['votos','FBA','O que vale mais numa trade?',
 ['Pick de 1ª rodada','Jovem de alto potencial','Estrela com 30 anos','Espaço no cap'],null,null],

['votos','FBA','Qual é a melhor sensação na FBA?',
 ['Ganhar o título','Acertar a pick 1','Roubar numa trade','Subir de liga'],null,null],

// ══════════════════════════════════════════════════════════════════════
// QUEM ERA O ARMADOR / QUEM JOGAVA ALI — o formato "complete o time"
// ══════════════════════════════════════════════════════════════════════
['certa','Elencos','Quem era o armador titular do Lakers campeão de 2010?',
 ['Derek Fisher','Steve Blake','Jordan Farmar','Ramon Sessions'],1,
 'Fisher esteve nos cinco títulos da era Kobe.'],

['certa','Elencos','Quem era o armador do Miami Heat campeão de 2012?',
 ['Mario Chalmers','Norris Cole','Dwyane Wade','Rajon Rondo'],1,
 'Chalmers, ao lado do Big Three.'],

['certa','Elencos','Quem era o pivô titular do Bulls do primeiro tricampeonato?',
 ['Bill Cartwright','Luc Longley','Horace Grant','Dennis Rodman'],1,
 'Cartwright entre 1991 e 1993; Longley veio no segundo trio.'],

['certa','Elencos','Quem era o armador do Spurs campeão de 2003?',
 ['Tony Parker','Avery Johnson','Manu Ginóbili','Steve Kerr'],1,
 'Parker já era titular no primeiro título dele.'],

['certa','Elencos','Quem era o pivô do Celtics campeão de 2008?',
 ['Kendrick Perkins','Kevin Garnett','Rasheed Wallace','Leon Powe'],1,
 'Perkins na vaga 5, com Garnett no ala-pivô.'],

['certa','Elencos','Quem era o ala-pivô do Warriors campeão de 2015?',
 ['Draymond Green','David Lee','Harrison Barnes','Andrew Bogut'],1,
 'Draymond assumiu a vaga naquele ano e mudou o time.'],

['certa','Elencos','Quem era o armador do Detroit campeão de 2004?',
 ['Chauncey Billups','Lindsey Hunter','Richard Hamilton','Mike James'],1,
 'Billups, o Mr. Big Shot.'],

['certa','Elencos','Quem era o ala-armador do Cleveland campeão de 2016?',
 ['J.R. Smith','Iman Shumpert','Kyrie Irving','Matthew Dellavedova'],1,
 'J.R. Smith abria o espaço pro LeBron.'],

['certa','Elencos','Quem era o pivô do Dallas campeão de 2011?',
 ['Tyson Chandler','Brendan Haywood','Dirk Nowitzki','Erick Dampier'],1,
 'Chandler foi a peça defensiva que faltava.'],

['certa','Elencos','Quem era o ala-armador do Bulls de 1996?',
 ['Michael Jordan','Ron Harper','Steve Kerr','Toni Kukoč'],1,
 'Jordan no 2, Pippen no 3 e Harper no 1.'],

['certa','Elencos','Quem era o armador do Milwaukee campeão de 2021?',
 ['Jrue Holiday','Khris Middleton','Donte DiVincenzo','George Hill'],1,
 'Jrue chegou em 2020 e virou a peça defensiva do título.'],

['certa','Elencos','Quem era o pivô do Denver campeão de 2023?',
 ['Nikola Jokić','Aaron Gordon','Jeff Green','DeAndre Jordan'],1,
 'Jokić, MVP das Finais.'],

['certa','Elencos','Quem era o ala-pivô do Lakers de 2001?',
 ['Horace Grant','Robert Horry','Rick Fox','Brian Shaw'],1,
 'Grant no 4, Shaq no 5.'],

['certa','Elencos','Quem era o armador do Phoenix Suns finalista de 2021?',
 ['Chris Paul','Devin Booker','Cameron Payne','Ricky Rubio'],1,
 'CP3 levou o Phoenix à primeira final desde 1993.'],

['certa','Elencos','Quem era o pivô do Knicks dos anos 90?',
 ['Patrick Ewing','Charles Oakley','Anthony Mason','Marcus Camby'],1,
 'Ewing, a cara da franquia por 15 anos.'],

// ══════════════════════════════════════════════════════════════════════
// MAIS RECORDES E NÚMEROS
// ══════════════════════════════════════════════════════════════════════
['certa','Recordes','Qual a maior média de pontos numa temporada?',
 ['37,1 do Jordan','50,4 do Wilt','36,9 do Baylor','35,4 do Curry'],2,
 'Wilt, 50,4 pontos por jogo em 1961-62.'],

['certa','Recordes','Quantos pontos Kobe fez no último jogo da carreira?',
 ['50','55','60','62'],3,
 'Sessenta pontos contra o Utah, em 2016.'],

['certa','Recordes','Qual o recorde de bolas de 3 numa única partida?',
 ['12','13','14','16'],3,
 'Klay Thompson, 14 contra o Chicago.'],

['certa','Recordes','Quantos pontos Klay Thompson fez num único quarto?',
 ['30','33','37','40'],2,
 'Trinta e sete pontos num quarto, em 2015.'],

['certa','Recordes','Qual a maior sequência de vitórias da história?',
 ['27 do Warriors','33 do Lakers','29 do Bucks','35 do Bulls'],2,
 'Lakers de 1971-72, 33 vitórias seguidas.'],

['certa','Recordes','Quem tem mais lances livres convertidos na história?',
 ['Karl Malone','Michael Jordan','Moses Malone','LeBron James'],1,
 'Karl Malone, mais de 9 mil.'],

['certa','Recordes','Qual foi a maior virada de placar num jogo da NBA?',
 ['25 pontos','29 pontos','31 pontos','35 pontos'],3,
 'Trinta e um pontos, feito pelos Jazz sobre os Nuggets em 1996.'],

['certa','Recordes','Quantas temporadas seguidas Kareem foi All-Star?',
 ['12','15','18','19'],4,
 'Dezenove — recorde absoluto.'],

// ══════════════════════════════════════════════════════════════════════
// MAIS DRAFT
// ══════════════════════════════════════════════════════════════════════
['certa','Draft','Quem foi a primeira escolha do draft de 2023?',
 ['Victor Wembanyama','Brandon Miller','Scoot Henderson','Amen Thompson'],1,
 'Wembanyama, pelo San Antonio.'],

['certa','Draft','Quem foi escolhido logo antes de Michael Jordan em 1984?',
 ['Hakeem Olajuwon','Sam Bowie','Charles Barkley','John Stockton'],2,
 'Sam Bowie, a escolha mais criticada da história.'],

['certa','Draft','Em que posição John Stockton foi draftado?',
 ['5ª','12ª','16ª','25ª'],3,
 'Décimo sexto, no mesmo draft de Jordan.'],

['certa','Draft','Quem foi a primeira escolha do draft de 2019?',
 ['Zion Williamson','Ja Morant','RJ Barrett','Darius Garland'],1,
 'Zion, pelo New Orleans.'],

['certa','Draft','Em que posição Manu Ginóbili foi draftado?',
 ['28ª','45ª','57ª','60ª'],3,
 'Quinquagésimo sétimo, em 1999 — o achado dos Spurs.'],

['certa','Draft','Quem foi a primeira escolha do draft de 2007?',
 ['Greg Oden','Kevin Durant','Al Horford','Mike Conley'],1,
 'Oden antes de Durant. As lesões decidiram o resto.'],

// ══════════════════════════════════════════════════════════════════════
// MAIS MUNDO
// ══════════════════════════════════════════════════════════════════════
['certa','Mundo','De que país é Pau Gasol?',
 ['Espanha','Argentina','França','Itália'],1,
 'Espanhol, dois títulos com o Lakers.'],

['certa','Mundo','De que país é Tony Parker?',
 ['França','Bélgica','Suíça','Canadá'],1,
 'Francês, quatro títulos com o San Antonio.'],

['certa','Mundo','De que país era Dražen Petrović?',
 ['Sérvia','Croácia','Eslovênia','Bósnia'],2,
 'Croata, morto num acidente em 1993, aos 28 anos.'],

['certa','Mundo','De que país é Shai Gilgeous-Alexander?',
 ['Estados Unidos','Canadá','Bahamas','Jamaica'],2,
 'Canadense, de Toronto.'],

['certa','Mundo','De que país é Kristaps Porziņģis?',
 ['Lituânia','Letônia','Estônia','Rússia'],2,
 'Letão.'],

['certa','Mundo','Qual país tem mais jogadores na NBA depois dos EUA?',
 ['França','Canadá','Sérvia','Austrália'],2,
 'O Canadá vem crescendo desde a era Raptors.'],

['certa','Mundo','De que país é Yao Ming?',
 ['Japão','China','Coreia do Sul','Taiwan'],2,
 'Chinês, primeira escolha do draft de 2002.'],

// ══════════════════════════════════════════════════════════════════════
// MAIS "QUEM FOI MELHOR"
// ══════════════════════════════════════════════════════════════════════
['votos','Quem foi melhor','Quem tem o melhor apelido da NBA?',
 ['The Dream','The Answer','Greek Freak','The Mailman'],null,null],

['votos','Quem foi melhor','Melhor jogador da década de 2010?',
 ['LeBron James','Stephen Curry','Kevin Durant','Kawhi Leonard'],null,null],

['votos','Quem foi melhor','Melhor jogador da década de 2000?',
 ['Kobe Bryant','Tim Duncan','Shaquille O\'Neal','Dirk Nowitzki'],null,null],

['votos','Quem foi melhor','Melhor jogador dos anos 90 depois do Jordan?',
 ['Hakeem Olajuwon','Karl Malone','David Robinson','Charles Barkley'],null,null],

['votos','Quem foi melhor','Quem é o melhor jogador em atividade hoje?',
 ['Nikola Jokić','Luka Dončić','Giannis Antetokounmpo','Shai Gilgeous-Alexander'],null,null],

['votos','Quem foi melhor','Qual seria o melhor quinteto de todos os tempos?',
 ['Magic, Jordan, LeBron, Duncan, Kareem','Curry, Kobe, Bird, Malone, Shaq','Stockton, Wade, Pippen, Garnett, Hakeem','Oscar, MJ, Bird, Duncan, Russell'],null,null],

['votos','Quem foi melhor','Quem tinha mais impacto sem a bola?',
 ['Ray Allen','Klay Thompson','Reggie Miller','Rip Hamilton'],null,null],

['votos','Quem foi melhor','Melhor pegador de rebote ofensivo?',
 ['Dennis Rodman','Moses Malone','Charles Barkley','Andre Drummond'],null,null],

['votos','Quem foi melhor','Quem foi o jogador mais subestimado da história?',
 ['Kevin Garnett','Scottie Pippen','Chris Paul','David Robinson'],null,null],

['votos','Quem foi melhor','Qual foi a melhor jogada de todos os tempos?',
 ['O toco do LeBron em 2016','O arremesso do Jordan em 98','O Ray Allen em 2013','O Kawhi contra o Philadelphia'],null,null],

['votos','Quem foi melhor','Melhor jogador que nunca foi MVP?',
 ['Reggie Miller','Scottie Pippen','John Stockton','Patrick Ewing'],null,null],

['votos','Quem foi melhor','Quem era mais assustador em quadra?',
 ['Shaquille O\'Neal','Dennis Rodman','Metta World Peace','Charles Oakley'],null,null],

['votos','Quem foi melhor','Melhor jogador de playoff da história?',
 ['Michael Jordan','LeBron James','Kawhi Leonard','Tim Duncan'],null,null],

['votos','Quem foi melhor','Qual foi a maior traição de agência livre?',
 ['Durant pro Warriors','LeBron pro Heat','Kawhi pro Clippers','Shaq pro Lakers'],null,null],

['votos','Quem foi melhor','Quem teve a carreira mais completa?',
 ['Kareem Abdul-Jabbar','LeBron James','Tim Duncan','Kobe Bryant'],null,null],

];
