<?php
/**
 * ── OS GMs DA RISE, PRA PRIMEIRA CARGA ────────────────────────────────
 *
 * Mesma ideia de cron/bot-perfis-seed.php (que é a ELITE), lendo o chat da
 * RISE: 282 mil linhas de 27/07 a 09/11/2026.
 *
 * Idempotente e conservador: só sobrescreve o que ainda tem `fonte = 'seed'`.
 * Perfil corrigido na mão pelo dono ou reescrito pelo cron fica de pé.
 *
 * Uso:  php cron/bot-perfis-seed-rise.php
 */

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/bot_papo.php';

$pdo = db();
papoGarantirTabelas($pdo);

/* A língua da RISE. Cada liga tem a sua — "busco" abre negociação lá do mesmo
   jeito que "assalto" é elogio na ELITE. */
$folclore = [
    ['RISE', 'busco', 'Na RISE tudo começa com "busco": busco regular, busco progression, busco draft, busco leilão, busco pivô. É assim que se abre troca e assim que se cobra o próximo passo da liga.'],
    ['RISE', 'bagre', 'Jogador ruim. O Ramon canonizou a palavra: manda-se bagre no trade block, leva-se bagre de troco. "Escolham seu bagre e sejam felizes".'],
    ['RISE', 'Davi Brito', 'Jogador do banco de dados que virou piada fixa. Todo mundo roda /jogador Davi Brito e inventa que ele evoluiu, bustou ou treinou muito na offseason.'],
    ['RISE', 'The Pathetic', 'O jornal da liga, do Gustavo Pathetic, com a MANCHETE escrita pelo DreKhaos. Vive abandonado, vive prometendo voltar, e a liga vive cobrando. Quem escreve nele é "funcionário do The Pathetic".'],
    ['RISE', 'OTC e aquece', 'No draft a liga marca o atrasado com OTC, AQUECE, "já aquece" e até BUSCA-SE. O Victor Aburachid e a Ana Luiza são os alvos clássicos.'],
    ['RISE', 'Rivergems', 'O Cincinatti Rivergems troca de GM quase toda semana. O grupo decretou que virou "regime comunista, onde todo mundo é GM".'],
];

/* [team_id, apelido no WhatsApp, resumo, do que dá pra pegar no pé] */
$perfis = [
    [104, 'Portela', 'Fala mais que a liga toda junta: 29 mil mensagens, quase 3x o segundo. Mensagem de três palavras, CAPSLOCK, "pqp", "mds", "baita", "coé". Vive no grupo das 7h à 1h, spamma /meutime e manda proposta pra todo mundo todo dia.', 'A mensagem mais burra do Portela é sempre a próxima, como diz o Dre. Leva "CALA A BOCA PORTELA" e seguem pedindo que ele continue com as ofertas.'],
    [22,  'Gustavo Pathetic', 'Todo mundo chama de Gugu. O bordão é "leigo": xinga de leigo quem discorda, e fecha frase com "Emociona." e "Dá não". Dono do The Pathetic, o jornal da liga. Pico das 15h às 22h, quase nunca ri de kkk, prefere CAPSLOCK curto.', 'É o juiz autoproclamado do que é leigo na RISE. E cobram dele a volta do The Pathetic, que ele mesmo admite estar abandonado.'],
    [17,  'Matheus onibus', 'Strawhats de One Piece. Escreve mais comprido e mais correto que a média, usa "qual ?" solto e cobra a liga: /irregulares, /trocas, cadê o cap. Mais ativo 18h-21h.', 'É o símbolo do tank eterno da RISE: "intankável, o ônibus é ônibus". Vive disputando com o Leancon quem é o pior da liga.'],
    [87,  'Nebacetin', 'Paisley é tudo pra ele: digitou /time paisley 66 vezes. Frase curta, pergunta com ??? e ????, ri 🤣 e kkkkkkk, e grita em CAPSLOCK quando falta coisa no app ("CADÊ A TRADE NO APP"). Aparece tarde, 18h-00h.', 'Tem fama de ganhar toda troca e ainda pedir uma first de brinde. Montou time pra ser campeão, não foi, e o grupo não deixa esquecer.'],
    [32,  'DreKhaos', 'Viciado em /power e /powerc, e é ele quem escreve a MANCHETE do The Pathetic. Assina com 💔, responde "ata", "obg", "sim", "pqp". Manhã é dele: 9h-11h é quando mais fala. Chama todo mundo de amigo.', 'Se diz leigo mas é o melhor funcionário do The Pathetic. Avisa sozinho que o DeathSails vai cair no limbo do tank eterno.'],
    [10,  'Noah - Blue Diamonds', '"Meo deos do ceu" é a assinatura. Fala em CAPSLOCK o tempo todo, usa 😭 e 🫵, e briga com o bot. Vive de noite, 21h-00h. Manda proposta em rajada, às vezes 20 pelo mesmo jogador.', 'A liga jura que o Noah está sempre no tank e que "deixaram ele ser campeão". Leva "lixoso" e "CALA A BOCA" sem reclamar.'],
    [21,  'Davi - Rise', 'Skywalkers com legend Anakin Skywalker, e roda /jogador anakin skywalker de vez em quando. Ri KKKKKKKKKKKKKK longuíssimo, quase não acentua, usa ❤ e 💔. Aparece 18h-22h e comenta tudo do leilão.', 'Chamam ele de leigo direto. E o grupo lembra que ele não aguentou nem uma season de tank e já saiu dando esperança.'],
    [112, 'Michel', 'É "o robô" da liga, apelido oficial, e metade do grupo confunde com o FBA Bot. Ri com kkkkkkkkkkkkkkkkkkkkk de vinte letras, solta "eita rapaz", "meu deus" e "dar não". Fala mais de manhã, 10h-12h.', 'O apelido robô deu tanta confusão que pediram pra ele registrar "Michel Robô" no app. E ele mesmo decretou que Wasburn é franquia zicada.'],
    [12,  'Matheus - Cachorro', 'O "Limão" da liga, porque o time é Tucson Lemmons. Dispara "pqp", "que isso", "bizarro", "perfeito", "poise", "tlgd", e ri kkkkkkkkkkkkkkkkkkkkkkk. Horário comercial, 9h-16h. É o que mais marca gente com OTC.', 'Virou Limão e Limonada, e o próprio bot já chamou o time dele de Tucson Limão. Zoam que ele é o xerife do OTC.'],
    [102, 'Guilherme Fabro - rookie', 'Subiu da ROOKIE e batizou o time com a cidade onde mora: Charlotte Black Gold, preto e dourado. Ri kkkk em quase toda mensagem, escreve comprido e nunca usa CAPSLOCK. Pede "busco prog", "busco loteria". Pico 11h-12h e 20h-22h.', 'Negociar com o Fabro é pior que tomar veneno sabendo que é veneno, nas palavras do Nicholas. Mesmo assim admitem que ele melhorou a liga.'],
    [105, 'matheus - kings', 'O Matheus sem apelido, e a liga já pediu que ele arranje um. Bicampeão da ROOKIE, fala de diretriz, legend e IDV. Escreve curtinho e sem acento: "po", "eh", "ata", "sim sim", "pprt". Mais ativo 8h-11h e 15h-16h.', 'É o único Matheus sem apelido e cobram um dele. E o Portela não perde a chance de lembrar que o Kings é a pior administração da NBA.'],
    [23,  'Vinicius - Mindflayers', 'Mindflayers desde sempre e Jokic é o assunto dele. Escreve minúsculo e direto, solta "oxe", "aff", "que isso" e "nossa senhora que desgraça". Cobra os outros a atualizar o time. Vive 12h-20h, raramente de manhã.', 'Diz que tudo que monta fica timaço e ninguém compra. E reclamou alto que o Leancon levou até o bagre do fundo do banco dele.'],
    [107, 'Leancon', 'Ri 🤣 e kkkkkkkkkk em tudo e corrige a própria mensagem com asterisco ("negocio *"). Solta "oxi", "calma", "bora". Tarde e noite, 15h-18h. Tem veia de cronista: inventa nome comprido pros outros GMs só pra zoar.', 'O Portela diz que ele e o ônibus disputam quem é o pior da RISE. E acusam ele de garimpar bagre do fundo do banco alheio.'],
    [15,  'Ramon', 'Fala "bagre" sem parar, é a palavra dele. Ri kkkk curtinho, acentua tudo, quase nunca usa emoji. Comenta jogo e scout, reclama do comissário e cobra leilão. Mais ativo às 18h e das 20h às 22h.', 'É o dono da palavra bagre na RISE. Quando sobra moeda no leilão vira "o Ramon milionário", e cobram dele as picks que bustou.'],
    [28,  'Patrício', 'Escreve com pontuação completa e ponto final em tudo: "Sim." "Opa." "Beleza." "Brutal." Chama todo mundo de camarada e paizão, agradece sempre. Manhã e começo de tarde, 8h-13h. Quando fala sério é sobre regra e organização da liga.', 'O ônibus decretou que dos GMs ativos o pior pra negociar é o Patrício. E o Vinicius já disse na cara que ele parece velho chato.'],
    [111, 'Nicholas - Rise', 'Blitz é a vida dele: solta /meucap, /meutblock, /minhaspicks e /elencojovem em sequência. Ri kkkkk curtinho, usa 🥹 e 🫡, pede desculpa e agradece. Noite pura, 20h-23h. Vive falando de first e second.', 'O Dre diz que ele é pior que todo mundo com second. E fala tanto de first que as picks do Blitz já viraram piada.'],
    [25,  'Cavalo', 'Antonio Carlos virou Cavalo, e o Portela já chamou ele de "Ac Horse". Escreve com reticências..., solta "Jesus...", "emociona.", "lindo", "coisa linda", "paizão". Faz questão de ser o mais velho da turma. Noite longa, 19h-01h.', 'Metade do grupo diz que é burro que se acha cavalo. E acusam ele de se sabotar de propósito pra cair no tank.'],
    [110, 'Victor Aburachid', 'Fala pouco e nunca ri: zero kkk em 97 mensagens. Escreve certinho, só abrevia "nao" e "q", e chama todo mundo de "amigos". Legend dele é Taylor Swift. Aparece 18h-19h. A frase dele é "minha tática é não saber o q to fazendo".', 'É o rei do OTC: a liga inteira já marcou ele com AQUECE e BUSCA-SE no meio do draft. E por meses ninguém na RISE sabia quem era o Athens.'],
    [109, 'Ana Luiza Figueiredo', 'Shai é a franquia dela e o grupo todo sabe. Escreve minúsculo, ri kkkkk curto, usa 🫶 e manda "ok!". Roda /irregulares pra fiscalizar elenco dos outros. Aparece 17h-20h e avisa quando está viajando.', 'Já avisou que quer travar o draft e trava: "destravo em 5 a 6 horas úteis". E o Davi jura que ela é a pessoa que mais odeia ele no mundo.'],
    [36,  'Thiago Gonzalez', 'Mensagem seca, nunca ri: zero kkk em 130 mensagens. Responde em instantes quando é a vez dele e ainda assim demora. Só quer que o draft ande: "liberem o relógio", "já começa o draft de uma vez". Meio-dia e 20h.', 'Vive pondo o elenco à venda: "alguém tira o Lamelo do meu time, tá barato". E o Headliners só aparece em notícia de lesão.'],
    [38,  'Edu Marangoni', 'Começa tudo com "Amigos" ou "rapaziada" e pede "Perdão a demora" toda vez. Responde "Feito", "Show", "Brigadão". Ri com KKKKKKKKKKKKKKKKK todo em caps. Viaja a trabalho e pede pra ser marcado quando for a vez dele. 9h e 23h.', 'O "Perdão a demora rapaziada" virou bordão: ele sempre lembra do pick depois da hora. Mas jura que ainda tem gasolina no tanque.'],
    [108, 'Jeanderson', 'Chegou dizendo que a memória é péssima mas tem tudo planilhado. Cola link do próprio X toda semana, faz face de jogador no 2K e sonha com uma liga de NBB. Educado, chama de "pessoal" e "galera". Aparece 10h-11h e 16h-17h.', 'Ele mesmo admite: "no fantasy da FBA sou um dos piores". E já ameaçou demitir o GM do Combo Guards, que é ele mesmo.'],
    [92,  'Diniz - Fresno Diniz', 'O EmoS é emo de verdade: "pra tá pior que nós é só desgraça", "não sobra nada", "sem palavras". Minúsculo, sem acento, "pprt" é o bordão. Fala pouco mas certeiro, mais no fim da tarde, por volta das 18h.', 'Fez do Fresno a franquia mais azarada da liga e ri disso junto. O Dirk dele foi embora e ele nunca superou.'],
    [49,  'Lucas - Vision', 'Historiador da liga: lembra NEXT antiga, FMVP e campeão de temporada passada. Ri kkkkkkkkkk solto. Aparece de madrugada, 23h-01h, e some quando tem prova. Diz que é inimigo do prêmio individual.', 'Tem a impressão de estar em décimo há seis temporadas e fala isso sozinho. E pegou FMVP com um cara fazendo 10 pontos por jogo.'],
    [14,  'Arthur - rookie', 'Entrou em setembro trazido pelo Cavalo e já queria chocar o mercado. Minúsculo e direto, "busco" é o verbo dele. Usa /opta spirit e /opta detroit. Mais ativo às 10h e às 18h.', 'Chegou anunciando que ia chocar o mercado e no mês seguinte põe o time todo à venda. E decretou que só tem dodói na RISE.'],
    [30,  'Vitor O - rookie', 'Novato na RISE, entrou em outubro vindo da ROOKIE. Fala manso, cheio de "oiiii" e "vou ficar quieto agora". Pergunta aos adms quais são as punições dele. Buffalo é o assunto. Mais 10h-11h e 18h.', 'Chegou prometendo ficar dois anos só admirando e dois dias depois já queria trocar legend e IDV. Diz que subiu porque panelaram ele na ROOKIE.'],
    [18,  'Lucas V. rookie', 'Divide a cabeça entre o Seattle da RISE e o Imortals da ROOKIE, e vive pedindo /picks seattle. Ri kkkkkkkkkkk longo, agradece com "valeu", manda bom dia. Manhã, 8h-9h, e um pouco de tarde.', 'O Leancon batizou ele de "Lucas Vinicius Seattle Imortals Wales da Silva". E o Davi jura que nunca viu esse Wales em canto nenhum.'],
    [27,  'Maia', 'Mamaia pra todo mundo. Fala pouco, pouco mais de 300 mensagens, mas em frase de efeito: "Gunslingers voltou. Temam." Comenta badge, diretriz e IDV. Horário diurno, 10h-16h, e quase nada à noite.', 'O Cachorro diz que confia cegamente no mamaia. Prometeram temer o Gunslingers e ninguém temeu.'],
    [106, 'Durval', '"Boa tarde" é como ele entra no grupo. Minúsculo e sem acento: "pprt", "mds", "ent", "coisa linda". Só aparece em horário de trabalho, 11h-21h, e avisa que "clt é foda". Fala de lesão e de cap que não chega nem no mínimo.', 'O Cavalo resumiu: apareceu e sumiu. E o grupo decretou que o Boston Beans está tankando sem nem ter pick.'],
];

$insF = $pdo->prepare("INSERT INTO bot_folclore (liga, assunto, fato, fonte) VALUES (?, ?, ?, 'seed')
                       ON DUPLICATE KEY UPDATE fato = IF(fonte = 'seed', VALUES(fato), fato),
                                               atualizado_em = CURRENT_TIMESTAMP");
foreach ($folclore as $f) $insF->execute($f);

$insP = $pdo->prepare("INSERT INTO bot_personalidades (user_id, liga, apelido_zap, time_nome, resumo, zoeira, fonte)
                       VALUES (?, 'RISE', ?, ?, ?, ?, 'seed')
                       ON DUPLICATE KEY UPDATE
                           user_id   = VALUES(user_id), time_nome = VALUES(time_nome),
                           resumo    = IF(fonte = 'seed', VALUES(resumo), resumo),
                           zoeira    = IF(fonte = 'seed', VALUES(zoeira), zoeira),
                           atualizado_em = CURRENT_TIMESTAMP");
$busca = $pdo->prepare("SELECT t.user_id, TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) nome
                          FROM teams t WHERE t.id = ? AND t.league = 'RISE'");

$ok = 0; $erro = [];
foreach ($perfis as [$teamId, $apelido, $resumo, $zoeira]) {
    $busca->execute([$teamId]);
    $t = $busca->fetch(PDO::FETCH_ASSOC);
    if (!$t) { $erro[] = "$apelido (time $teamId)"; continue; }
    $insP->execute([$t['user_id'] ?: null, $apelido, $t['nome'], $resumo, $zoeira]);
    $ok++;
}

echo "RISE — folclore: " . count($folclore) . " | perfis: {$ok} de " . count($perfis) . "\n";
if ($erro) echo "  NAO ACHOU O TIME: " . implode(', ', $erro) . "\n";
echo "  sem GM no cadastro da RISE (nao vai ser reconhecido): "
   . (int)$pdo->query("SELECT COUNT(*) FROM bot_personalidades WHERE liga='RISE' AND user_id IS NULL")->fetchColumn() . "\n";
