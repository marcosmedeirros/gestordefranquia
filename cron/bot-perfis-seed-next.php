<?php
/**
 * ── OS GMs DA NEXT, PRA PRIMEIRA CARGA ────────────────────────────────
 *
 * O último dos quatro (ELITE, RISE, ROOKIE e este), lendo o chat da NEXT:
 * 413 mil linhas de 01/02 a 09/11/2026 — o maior dos quatro grupos.
 *
 * Os 30 times foram casados, nenhum ficou de fora. O casamento saiu do cruzamento
 * entre os comandos "meus" (/meutime, /meuelenco, /meucap) e a resposta do bot,
 * que traz time + nome do GM: o apelido do WhatsApp quase nunca bate com o
 * cadastro, mas o bot já respondeu pra cada um deles em algum momento.
 *
 * Idempotente: só sobrescreve o que ainda tem `fonte = 'seed'`.
 *
 * Uso:  php cron/bot-perfis-seed-next.php
 */

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/bot_papo.php';

$pdo = db();
papoGarantirTabelas($pdo);

$folclore = [
    ['NEXT', 'lendas com nome de GM', 'Cada time tem uma lenda batizada com trocadilho entre o GM e um astro da NBA: Larry Birdias, Tracy McMansur, Shaquille A.Nalfa, Grills Webber, Big Duncelipão, Kareem Abdrigo-Jabbar, Phaul Phierce, Moses Vilone, Dwight Hogwarts, Charloel Barkneto. Em 11/08 o grupo passou a manhã julgando os nomes feios e chamou isso de CPI DOS NOMES. Big Duncelipão é o campeão de zoeira.'],
    ['NEXT', 'Pacome Dadiet', 'O jogador de banco que o Alfa Jr tentou vender a offseason inteira sem ninguém querer. Virou comando de troça: meia liga digita /jogador pacome só pela piada. Daí saiu o derivado "é pavê ou pacome", com o grupo respondendo só "Pavê?" pra irritar o Alfa.'],
    ['NEXT', 'travar o draft e o Vampetaço', 'Quem não picka no tempo "trava o draft". O mantra é "FBA é compromisso", e quem desaparece leva o selo de GM omisso. O protesto oficial é o Vampetaço no PV: todos mandam a figurinha do Vampeta no privado de quem está atrasando.'],
    ['NEXT', 'regras com nome de GM', 'Toda brecha descoberta vira regra nova batizada com o nome do culpado: Horse rule, GB rule, Stepien rule, alfalipão rule, PH rule, Portela rule. E a resposta padrão pra qualquer dúvida é "é só ler o edital".'],
    ['NEXT', 'apelidos deturpados dos times', 'Ninguém chama time pelo nome oficial. Portland Prost é Portland Próstata, México City Swisheros é Swingueros, Phoenix Bottlers é Garrafinhas, New Mexico Ancients é DeficiAncients e Texas Kubanacans é Texas Dickriders.'],
    ['NEXT', 'a NEXT é degrau', 'A liga gira em volta de subir pra ELITE ou cair pra RISE. "Futuro eliter" é elogio-zoeira, quem veio da ELITE ouve "aqui não é a Elite", e o próprio grupo se chama de hospício quando a confusão aumenta.'],
];

/* [team_id, apelido no WhatsApp, resumo, do que dá pra pegar no pé] */
$perfis = [
    [5,   'Pimentel', 'Textão com reticências no meio e no fim de tudo. Acento certo, ri com kkk e também hahahaha, emoji 😂 na veia. Chama o grupo de "Senhores" e fala como locutor. Assunto dele é cap fino: pede um 81 emprestado só pra subir 1 ponto.', 'Inventou um dono fictício chamado Shrek pro time e escrevia a novela da franquia até o grupo implorar pra parar. O time também virou Texas Dickriders.'],
    [11,  'Mides', 'Escreve minúsculo e sem acento ("nao", "voce"). Quase não usa kkk — quando ri, solta um bloco de 100 K de uma vez. Chama todos de "amigo" em tom de falso carinho e provoca elogiando. Fala de valor de pick, loteria e cap. Aparece de manhã e no começo da tarde.', 'Ninguém chama ele só de Mides: é "Lucas Mides", nome e sobrenome. Subiu no draft pra tirar o Mo Bamba do FDW e vive acusado de travar o relógio.'],
    [13,  'Eduardo Pastor', 'Põe espaço antes do interrogação em quase toda pergunta. Abre frase com "Pior que..." e usa "slk", "pprt", "paizão", "meu rei". Risada curta, kkkk. Fala de draft, ordem de pick e tank, e vasculha time alheio com /time antes de propor. Manhã e hora do almoço.', 'É o AFK oficial da liga: não responde no privado nem pra fechar leilão. O Rodrigo já gritou "RESPONDE PASTOR" no grupo.'],
    [16,  'Leonardo - Berserkers', 'Fala pouquíssimo (126 mensagens), responde com figurinha e com número seco no leilão. Sem vírgula, acento pela metade, Kkkkkkkk com K maiúsculo. Monta elenco por letra e defesa, não por OVR, e diz que o time dele é amaldiçoado em tela. Fim de tarde.', 'Virou o "Hipotético Berserkers Playoffs": o time se enfia nos playoffs e estraga a pick de quem contava com ela. Ele mesmo admite que só aparece nas horas boas.'],
    [19,  'PH', 'Não usa acento nenhum e quase não usa emoji. Bordão é "busco X": busco progression, busco troca marota. Diz "oxi", "ue", "bizarro". É o dono do /opta na liga. Fala de troca, pick e idade x OVR. Online do meio-dia à noite.', 'Tem bom time e não converte playoff, e as picks dele caem sempre mal. O grupo diz que ele não tira o cavalo da boca quando negocia.'],
    [26,  'Swish', 'Escreve em parágrafo, com acento e argumento, e fecha quase toda frase com kkk pra amortecer o que disse. Chama todo mundo de "camarada". Um terço das mensagens dele é figurinha — e ele mesmo fabrica. Fala de regra, diretriz e pontuação. Tarde e noite.', 'É o SwishTV, o canal de análise da liga. E trocaria a mãe pelo Shawn Marion: disse isso com essas palavras, mais de uma vez.'],
    [31,  'Felipão', 'Tudo minúsculo e quase sem acento. A assinatura é "nn" em vez de não, e ri "kakakakaka". Usa /duvida pro grupo validar as trocas dele. Fala de leilão, progression e picks. Vive online de noite e madrugada.', 'Gastou 7 picks no Ben Simmons e a liga nunca deixou esquecer. A lenda dele saiu "Big Duncelipão" e virou o nome mais zoado da NEXT.'],
    [34,  'Queijo', 'Telegráfico, manda 3 ou 4 mensagens seguidas, sem acento. A risada é sempre KKKKKKKKK com nove K em caixa alta, nunca varia o tamanho. Chama todos de "amigo". Fala de draft, valor de pick e de como o 2K traduz jogador. Horário comercial.', 'O apelido é piada eterna ("cabeças de queijo", "tim dunqueijo") e dizem que ele é sortudo demais. O Alfa já falou que tem trauma de jogar contra ele.'],
    [37,  'Elyan', 'Fala pouco, frase curta, acento certo, quase nunca kkkk — marca presença mandando um ponto solitário. Abre negociação com "Manda o X" e "Busco X". Desconfia da loteria nova e adora debate de GOAT da NBA real. Manhã e fim de tarde.', 'Batizou a lenda de "David The Elymiral RobisAn" e até hoje ninguém escreve o nome certo. O FDW vive dizendo que ele não sabe usar o Almirante.'],
    [44,  'Paulinho', 'O mais telegráfico da liga: quase nada passa de 60 caracteres. Rei da figurinha. Grita "SAFADO!!!" em caixa alta e usa "busco X" com complemento absurdo, mais hashtag própria tipo #FearTheHerd. Posta card temático do time. Online o dia todo.', 'Tem mais punição do que ponto na liga, e ele mesmo conta isso. Trata troca como assalto: já disse que foi na terra do outro e assaltou.'],
    [50,  'Thales - Biebers', 'Tudo minúsculo, zero acento, abreviação máxima (n, q, mt, tlg, dms). Fecha discussão com "mas é isso". Ri KKKKKKK em caixa alta. Não confia em bot pra preço: manda ver as stats. Monta elenco de veterano de propósito. Fim de tarde.', 'Chamam ele de GM omisso desde o primeiro mês e ninguém acha ele na hora da pick. O elenco de aposentados rendeu o apelido de vovô.'],
    [51,  'João Next', 'Curto, abrevia e usa 🤤 como assinatura. Diz "Po", "amigo", "calma", "oxe", e chama pick de "frp". Negocia na brincadeira e no atrito, e fica obstinado por um jogador até conseguir. Fala de loteria, regra anti-tank e preço de pick.', 'Era o sortudo da loteria, pegava pick 1 toda hora, até o Rodrigo decretar que ele perdeu os poderes da lotérica. E pagou um monte de first no Kirilenko.'],
    [53,  'Victor - Dobby’s', 'Põe espaço antes do interrogação em 8 de cada 10 perguntas. Usa CAPS LOCK pra indignação e solta paredão de KKKK. Assunto dele é legalidade de trade, edital e punição, e cobrar a administração. Tarde e noite.', 'É o Dobbynho, denunciante profissional: ameaça denunciar tudo e depois jura que não vai. Dizem que ele negocia até a casa.'],
    [55,  'Bruno Moreira', 'Escreve certinho, pontuação completa e exclamação dupla no parabéns. Fecha com "Dito isso" e "Seguimos", e chama de "meu bom". Posta trade block em formato de classificado: "sai barato, venham buscar". Fala muito de regra e de cap. De noite.', 'Virou "Bruno Tudoverde". Ninguém nunca conseguiu tirar o Luka dele, nem um reserva — a liga trata isso como pão-durismo.'],
    [57,  'Daniel', 'Chegou em 05/11/2026, veio da ELITE e assumiu o time já rebatizado de Penitents. Escreve bonito: maiúscula, acento, frase curta. Não usa kkkk, responde com figurinha. Provoca em uma linha: "Nojeira", "Guloso".', 'Novato de poucos dias, o grupo ainda está descobrindo quem ele é. Chegou peitando quem cobra atividade dos outros.'],
    [59,  'Luís - Nu', 'Entrou em 24/09/2026 herdando o time do Eloy e mandou só 21 mensagens. Minúsculo e cortado (agr, pq, tbm, blz), ri KKKKKKK. Descobriu que subiu no meio de uma viagem. Pede desculpa quando atrasa a pick. Começo da tarde.', 'O Eloy se declara pai do time e já cobrou 30% do título. Mal chegou e travou o draft uma vez.'],
    [62,  'Dias - California', 'Português inteiro, com acento e frase redigida; humor por ironia seca e CAIXA ALTA no meio da frase. Diz "que resenha", "aí é foda", "esculacho". É o adm-executor: a fila de pedido de troca e leilão vai nele. Manhã até a tarde.', 'O grupo chama ele de "Super Dias", o que sempre leva vantagem e nunca é punido. Trocou a lenda dele pela do Alfa e rendeu semanas de treta.'],
    [70,  'Mansur', 'Minúsculo, acento quando quer, risada KKKKKKKKKKKKKK longa. Bordões: "busco X" e "manda o X pra cá", e chama de "amigos". Fala de classe de draft, cap e edital. Tim Duncan e Spurs é religião pra ele. Fim de tarde até a noite.', 'Fama de proposta bizarra e de trocar o time inteiro a cada temporada: o PH já pediu permissão oficial pra não responder proposta dele.'],
    [79,  'Remerson Barboza', 'O mais caprichado do grupo: acento, vírgula, "porque" inteiro, inicial maiúscula. Risada contida e emoji afetivo (🥹 ❤ 👏). É o narrador dos jogos da NEXT e trata o bot com cerimônia, até perguntando se ele está bem.', 'É o "Remershow", e todo mundo lembra que ele veio da ELITE: "aqui não é a Elite" virou resposta pronta pra ele. Também acusado de overpay.'],
    [83,  'Neto', 'Frase completa e educada, com acento, kkkkkk de seis K e aperto de mão pra desejar bom confronto. Quase não usa o bot: posta o elenco inteiro na mão, formatado. Fala de diretriz, rotação e montagem posição por posição. Tarde e noite.', 'Rei do relógio estourado: já teve draft em que pediram pra alguém ligar pra ele. E não solta o Scottie Pippen por menos de 12 picks.'],
    [84,  'João Mamute', 'Chegou em 24/09/2026 e já virou personagem. Curto e abreviado (mt, n, q, fzr, cmg), quase não ri por escrito — responde com figurinha. Bordão é "Busco" solto, sozinho na linha. Fala de leilão e de buscar pivô. Fim de tarde à noite.', 'Batizou um jogador do próprio time de "Air Moschen", com o próprio sobrenome, e pediram pra ele mudar. Caiu no conto do Jokic mesmo avisado.'],
    [85,  'Rodrigo', 'Risada em paredão: KKKK de 15 a 170 K, em caixa alta, só dele na liga. Usa acento, abrevia "qndo", "agr", "mlk", e solta "pqp", "q isso", "justo". Opera o draft e abre o relógio. Acumulador doentio de first round pick. Manhã até fim de tarde.', 'É o campeão que dorme na hora de pickar: já travou o draft na pick 4. Chamam ele de "Rodrigo montanhas" e dizem que dava pra entregar o título logo.'],
    [89,  'Grilo', 'O mais correto da liga: acento sempre, ponto final, vírgula de vocativo. Quase não ri (cinco k no máximo). Bordões: "thank you fans", "momento pede calma", "Justo.", "parabéns aos envolvidos". Fala de regra, punição e ADM mais que todo mundo. O dia inteiro.', 'Virou "garrafas", por causa do Bottlers, e é a fábrica de apelidos da liga. Fama de pavio curto e de provocar de propósito.'],
    [90,  'Diguinho - Mesa', 'Metade das mensagens tem menos de 15 caracteres. Sem acento, Kkkkkkk com K maiúsculo, e o único que usa "hahahaha". Chama de "amigo" e responde só "Exato". Português de Portugal vaza no texto ("Tou", "ofereco"). Assunto número um é o relógio do draft. De noite.', 'É o português da liga, já chamado de "Anta Lusitana", e também o mais novo — dizem que ainda está formando a moleira. Vive cobrando leilão e relógio.'],
    [91,  'Vilão', 'Frase inteira, com acento e pontuação; trata de "mano", "nego", "fofo", "pai". Risada curta, kkkkk. Escreve anúncio em formato de classificado, com emoji em cada linha. É o colunista da liga e manda o grupo ler a matéria dele. Manhã e tarde.', 'Assumiu o papel de vilão e faz questão de ser odiado. Demora pra responder e pra pickar, e virou piada o tanto de gente que ofereceu o Andre Miller pra ele.'],
    [93,  'Alfa Jr', 'O mais falante da liga, três vezes o segundo colocado: mensagem curtíssima em rajada. Abrevia tudo (q, n, ta, oq, aq, msm) e quase não acentua. Ri só kkkk. Vive no bot, /meuelenco é tique nervoso. Assunto é pick, draft e progression. Quase o dia todo.', 'Chamam ele de "Alfinha" e dizem que ele fala sozinho no grupo e depois se responde. Só fala Spurs, e a lenda dele é Shaquille A.Nalfa.'],
    [96,  'Bê Pötter', 'Zero acento, erro de digitação em quase toda frase, e ri de três jeitos: kkkk, kakakaka e variantes tortas. Bordões: "poh", "ent", "meudeus", "joga lesão pai". Guardião do app, vive mandando "manda no app". Assunto é troca e lesão. Manhã à noite.', 'O time dele virou "Portland Próstata" e a enfermaria é piada fixa: disseram que o Prost entrou em recuperação judicial depois de três lesões.'],
    [98,  'Manoel', 'Escreve direito, com acento e pontuação, e a risada cresce conforme o deboche. Chama de "pai", "irmão", "meu rei". Fala do time na primeira pessoa do plural, como instituição. É o leiloeiro: abre leilão com anúncio próprio e @todos. De manhã.', 'Sumir é a marca dele: o Grilo diz que ele consegue a proeza de sumir em 3 minutos, e já pediram pra ele descansar em paz.'],
    [99,  'Victor FDW', 'A risada mais exagerada da base, K em caixa alta chegando a 100 seguidos. Ponto final em frase de uma palavra. Usa 👏 só como aplauso sarcástico e posta muito print. Maior usuário do bot, viciado em per36. Autodeprecia o próprio elenco. Manhã e tarde.', 'É o spammer de proposta da liga: mandou 27 numa janela e teve 16 recusadas. O Swish já disse que proposta com Wildcats é rejeitada automaticamente.'],
    [100, 'Michel', 'O mais telegráfico: nada passa de 150 caracteres e a maioria tem menos de 20. Corta vogais (n, q, vc, dps, qnd, msm), ri kkkk em rajada de 20 K e usa 💔 pra lamento. Manda figurinha sem parar. Fala de comparação de jogador, cap e stats. À tarde.', 'O apelido dele é robô, de tanto mandar a mesma proposta todo dia. E ninguém tira o Stephon Castle dele: só sai por legend.'],
];

$insF = $pdo->prepare("INSERT INTO bot_folclore (liga, assunto, fato, fonte) VALUES (?, ?, ?, 'seed')
                       ON DUPLICATE KEY UPDATE fato = IF(fonte = 'seed', VALUES(fato), fato),
                                               atualizado_em = CURRENT_TIMESTAMP");
foreach ($folclore as $f) $insF->execute($f);

$insP = $pdo->prepare("INSERT INTO bot_personalidades (user_id, liga, apelido_zap, time_nome, resumo, zoeira, fonte)
                       VALUES (?, 'NEXT', ?, ?, ?, ?, 'seed')
                       ON DUPLICATE KEY UPDATE
                           user_id   = VALUES(user_id), time_nome = VALUES(time_nome),
                           resumo    = IF(fonte = 'seed', VALUES(resumo), resumo),
                           zoeira    = IF(fonte = 'seed', VALUES(zoeira), zoeira),
                           atualizado_em = CURRENT_TIMESTAMP");
$busca = $pdo->prepare("SELECT t.user_id, TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) nome
                          FROM teams t WHERE t.id = ? AND t.league = 'NEXT'");

$ok = 0; $erro = [];
foreach ($perfis as [$teamId, $apelido, $resumo, $zoeira]) {
    $busca->execute([$teamId]);
    $t = $busca->fetch(PDO::FETCH_ASSOC);
    if (!$t) { $erro[] = "$apelido (time $teamId)"; continue; }
    $insP->execute([$t['user_id'] ?: null, $apelido, $t['nome'], $resumo, $zoeira]);
    $ok++;
}

echo "NEXT — folclore: " . count($folclore) . " | perfis: {$ok} de " . count($perfis) . "\n";
if ($erro) echo "  NAO ACHOU O TIME: " . implode(', ', $erro) . "\n";
echo "  sem GM no cadastro: "
   . (int)$pdo->query("SELECT COUNT(*) FROM bot_personalidades WHERE liga='NEXT' AND user_id IS NULL")->fetchColumn() . "\n";
