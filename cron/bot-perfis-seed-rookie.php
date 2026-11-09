<?php
/**
 * ── OS GMs DA ROOKIE, PRA PRIMEIRA CARGA ──────────────────────────────
 *
 * Irmão de cron/bot-perfis-seed.php (ELITE) e -rise.php, lendo o chat da
 * ROOKIE: 408 mil linhas de 31/07 a 09/11/2026.
 *
 * Idempotente: só sobrescreve o que ainda tem `fonte = 'seed'`.
 *
 * DOIS GMs FICARAM DE FORA, e por motivo honesto:
 *   133 Charlotte Hornets  — o GM entrou no grupo e nunca escreveu nada.
 *   139 Milwaukee Bucks    — duas pessoas diferentes aparecem no export sob o
 *                            mesmo contato "Felipe - Rookie". Sem separar as
 *                            duas, o bot zoaria a pessoa errada.
 * Os dois seguem sem perfil: o bot trata como alguém do grupo que ele não
 * reconhece, que é melhor que tratar como outra pessoa.
 *
 * Uso:  php cron/bot-perfis-seed-rookie.php
 */

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/bot_papo.php';

$pdo = db();
papoGarantirTabelas($pdo);

$folclore = [
    ['ROOKIE', 'mentor', 'Todo GM da ROOKIE tem um mentor das ligas de cima. Quando alguém faz bobagem a culpa é do mentor; quando acerta, é "mentorado brabo". Acusar mentor de ter preferido é esporte.'],
    ['ROOKIE', 'edital impresso', 'O Wes perguntou se podia imprimir o edital e imprimiu de verdade. Virou a piada mãe da liga: quem discute regra ouve que ele vai "sacar do edital impresso". O Don admitiu que tentou imprimir também e estava sem tinta.'],
    ['ROOKIE', 'descer o cap', 'Aqui o cap é soma de OVR e a deadline fecha quarta às 22h. A liga passa a semana pedindo ajuda pra descer o cap, e quem não desce leva leilão compulsório e perde 5 posições no draft. É a treta semanal.'],
    ['ROOKIE', 'melhor liga', 'O grupo se autoproclama a melhor liga da FBA e repete isso toda hora. Ao mesmo tempo admitem que ali são 30 novatos e que por isso tudo desanda mais rápido que nas outras.'],
    ['ROOKIE', 'power ranking', 'O raul - pacers escreve o power ranking e as matérias do The Pathetic sobre a ROOKIE. Sair mal colocado rende treta de dias.'],
    ['ROOKIE', 'panela', 'A acusação favorita do grupo. A "panela Thunder" do Jefferson é a mais citada, e o Seika responde perguntando onde é que assina pra entrar em uma.'],
];

/* [team_id, apelido no WhatsApp, resumo, do que dá pra pegar no pé] */
$perfis = [
    [113, 'Matheus - Rookie Bulls', 'Entrou em outubro assumindo o Bulls e quase não aparece: menos de dez mensagens no período todo. Escreve curto, sem acento, fim de tarde. Quando fala é pra brincar com o próprio nome ou com futebol.', 'Pegou o Bulls das mãos do Leancon e virou o GM mais calado da liga. Dá pra cobrar presença que ele responde.'],
    [114, 'Rodrigo - Denver', 'Chama o grupo de "amigos" e dá bom dia e boa noite nominalmente. Vive de /time nuggets e /irregulares. Acentua tudo, ri pouco. Aparece em blocos no meio da tarde e depois das 22h.', 'O mais difícil de fechar troca: pede pivô, ninguém responde, e quando respondem ele não fecha. O Leancon só chama ele de ruanzinho.'],
    [115, 'EZIAMAKA IKEJIAKU JUNIOR', 'Nick em CAIXA ALTA e o nome mais famoso da liga. Texto curto e direto, ri kkk e kakakaka, abrevia "msm" e "agr". Quando fala sério é pra cobrar o administrativo: acha que a ROOKIE é cheia de novato e precisa de mão firme. Pico 20h-21h.', 'O grupo jura que ele ganhou votação só por ter o nome mais insano da FBA. E o miguel zoa que o Heat nunca encaixa com ele.'],
    [116, 'Gabriel - Magic', 'Educado, usa reticências e acento, ri kkk baixinho. Dispara /meutblock toda hora pra anunciar quem está disponível. Torcedor de Bulls na vida real, fala muito de NBA antiga. Noturno, pico 23h, negocia depois da meia-noite.', 'Spamma /meutblock como quem reza. E ele mesmo admitiu que caiu no conto do Gasol: o grupo nunca deixou esquecer.'],
    [117, 'Lucas Don', 'Todo mundo chama de Don. Acentua, escreve médio e cita artigo do edital de cabeça, dizendo que discute "pelo personagem". Ri KKKK gigante — uma vez mandou um K de mil e seiscentos caracteres. Pico 21h-23h.', 'O advogado do edital. "Toda troca é overpay" é bordão dele. Deixa o cap pra última hora e o grupo avisa toda semana que ele vai tomar punição.'],
    [118, 'felipe - 76ers', 'Fala mais que respira: pico 19h-23h e vira a madrugada. Abrevia tudo (n, q, agr, mto), ri kkk, usa "pqp" e "pior que". Assunto é sempre o próprio elenco e quem vai subir. Quase não acentua.', 'O 76ers foi eleito o time mais maluco da história da ROOKIE: troca todo mundo e cai no playoff sem ninguém acima de 80.'],
    [119, 'Raul Marx - GSW', 'Escreve como escrivão do século 19: "Sr. Nome, poderíeis por obséquio". Foi o cobrador oficial do draft, marcava todos com "faz a boa, mano" e postava meta em porcentagem. Acentua tudo, ri kkkkkk comprido. Aparecia 11h-12h.', 'Sumiu depois do draft: 275 mensagens em agosto e meia dúzia em novembro. E o Warriors caminhando pra terceira punição.'],
    [120, 'Lênin Rookie', 'Chegou em outubro no Spurs e só existe de madrugada: pico 00h-02h. Acentua, escreve curto e seco, quase não ri. Assunto preferido é NBA histórica e discussão de GOAT. Usa "po" como vírgula.', 'Virou o juiz de GOAT da madrugada: puxa debate de Jordan e Shaq às 2h e sempre quer punição exemplar pros outros.'],
    [121, 'henrique - wolves', 'Manda textão em tom de discurso: começa com "Vejam bem", trata o grupo como "companheiros" e emenda 500 caracteres. Fora isso é /confronto e fileira de 😂. Horário comercial, pico 12h-16h. Foi campeão em outubro.', 'O grupo decretou que ele é o "mr resenha" e todo textão dele é chamado de aula. O Wes já responde com "já deu henrique".'],
    [122, 'Felipe - Rookie', 'O Coala. Grita em CAIXA ALTA, ri KKKKKKKK gigante, chama todos de "família" e não acentua. No draft inicial virou lenda mandando áudio fingindo ser gente famosa pra cobrar os GMs. Pico 11h e 19h-22h.', 'Até hoje presenteia GM com áudio do coala knicks. A fama é de só aparecer na hora ruim e deixar proposta sem resposta.'],
    [123, 'Kaio - Lakers', 'Criatura da madrugada: pico 23h-01h, ainda falando 1h da manhã. Frase curtíssima, acentua, ri kkkk curto, solta "Ss" e "Oxe". Gosta de enquete e de comparar jogador antigo.', '"Lakers no relógio" virou bordão do grupo por causa dele. E o Leancon jura que ele não sabe escrever o nome dos próprios jogadores.'],
    [124, 'miguel - blazers', 'Admin e o segundo que mais fala. Não acentua, escreve "achoq", "mto", "n" e "q", e abre com "rapaziada". Horário comercial, pico 11h e 14h-17h. Fala de cap, draft e punição com cara de quem leu tudo.', 'O "achoq" é marca registrada. O Leancon chama ele de adm desocupado e o grupo diz que na hora H ele pipocou na troca.'],
    [125, 'Jefferson - rookie', 'Textão analítico de 400 caracteres sobre draft, pick e evolução de jogador: é quem mais explica regra sem ser admin. Acentua, ri KKKKK longo, chama de "rapaziada" e de "padrinho". Pico 20h-23h.', 'A "panela Thunder" é dele, e ele mesmo escreve "bem vindo à panela Thunder, já somos 4". Vive anunciando mais um mentorado.'],
    [126, 'Pedro - Clippers', 'Fala pouquíssimo e sempre curto, sem um kkk em 135 mensagens. Chama todo mundo de "mafia" e manda /power. Assunto é se livrar de jogador e cap no limite. Aparece de noite, 21h-23h.', 'Clippers no limite do cap desde a primeira temporada, e o grupo brinca que ninguém acredita que ele torce pro Clippers.'],
    [127, 'Vinicius Lira', 'Chegou em 24/10 no Dallas. Só madrugada: pico 23h-00h. Escreve curto, acentua pouco, solta "Uou". Oferece Vince Carter e Baron Davis em literalmente toda proposta que faz.', 'Vince Carter e Baron Davis por qualquer coisa: ofereceu a mesma dupla pro Nets, pro Celtics e pro Grizzlies na mesma semana.'],
    [128, 'Átila Mansur', 'Pegou o Rockets em outubro e fala muito pouco. Frases curtas com três exclamações, chama de "irmão" e de "ídolo", usa /jogador e /irregulares. Aparece de manhã e no fim da tarde.', 'Entrou no Rockets, deu salve pra todo mundo e praticamente desapareceu do chat.'],
    [129, 'João Seika - rookie', 'Não usa acento nenhum: "entao", "nao", "oque", "voces". Ri kkkk e KAKAKAKA. É quem atualiza os stats e as grades de toda a liga e cobra os outros a auditarem. Multicampeão e fala como veterano. Pico 10h-11h.', 'Tricampeão e o grupo não perdoa: dizem que ele trocou o LeBron só pra provar que não era o LeBron que ganhava por ele.'],
    [130, 'raul - pacers', 'Autor do power ranking e das matérias do The Pathetic sobre a liga. Bordão é "pdp", usou 69 vezes, sempre com "vlw". Ri KKKKKKKK longo, acentua, escreve médio. Pico 10h-11h e volta 20h-22h.', 'O "super Pacers" dele ficou fora do play-in com Arenas, Bosh e Howard e zoaram por meses. Agora que é campeão, já cobra camiseta.'],
    [131, 'Vinícius Camilo - Nets', '"Mano" é o vocativo dele. Manda textão de 300 caracteres defendendo competitividade, quase sempre abrindo com "A questão é". Acentua, ri KKKKKKK. Horário comercial, pico 11h-14h.', 'Chamam o Nets de time que ficou loteria com Kobe e Yao. E quando ele abre textão sobre a liga alguém já avisa que vem sermão.'],
    [132, 'Kahuê - Atlanta', 'Escreve curto, acentua, ri kkkkk. Vive de /time e /power consultando o elenco dos outros. Quando alonga é pra citar artigo do edital ou escrever nota em estilo de jornal sobre os rivais. Pico 20h-22h.', 'O Hawks travou pra trocar depois de um leilão e o grupo pergunta até hoje se ele já pode negociar. Também some e não responde mensagem.'],
    [134, 'Guilherme - Celtics', 'Educado e direto, acentua, ri kkk curtinho e agradece sempre. Faz pergunta de regra sem vergonha nenhuma e chega a pedir pra abrir leilão só pra encaixar no cap. Fala muito de jogador antigo. Pico 22h-23h.', 'O Celtics é o eterno candidato a top 5: já perguntaram no grupo se ele pode pegar pick alta pela oitava vez seguida.'],
    [135, 'Luan - Utah', 'Fala pouco e curto, abrevia e quase não acentua. Mistura FBA com cinema e piada solta. Horário comercial, pico 11h-12h, e desaparece por dias. /time jazz e /cap jazz são metade do repertório.', 'Pediu pro grupo ajudar a descer 5 de cap, mandou jogador bom pra encaixar e sumiu. A fama de mão de vaca no leilão vem de agosto.'],
    [136, 'Jp - cavs', 'Não acentua ("nao", "voce", "mto"), ri KKKKKKK e usa "sla", "nn" e "pprt". Torcedor de Cavs na vida real há mais de 20 anos e mistura isso com o time da liga. Quando discute é sobre punição e leilão. Pico 19h-22h.', 'Herdou o Cavs abandonado e perdeu o Curry no leilão por não ter 12h a mais. O grupo chama de "coitado do JP" até hoje.'],
    [137, 'Tales - suns', 'Mestre dos comandos: /confronto, /picks, /jogador e /comparar sem parar, e controla melhor que o edital quem pode ou não ser top 5. Acentua, ri Kkkkkkkk e usa 😂. Noturno, pico 20h-23h. Campeão recente.', 'Pegou o Durant, subiu e não jogou com ele: essa o Fabro não deixa morrer. E agora cobram suspense em cada anúncio dele.'],
    [138, 'Tiago - wizards', 'Mensagem curtíssima, 22 caracteres de média, sem acento, cheia de /picks e /time pra espiar os outros. Ri kkkkkkk e KAKAKA. Gosta de empilhar dez peças numa proposta só. Noturno, pico 19h-23h.', 'É o mão de vaca oficial da liga: o Kaio e o Eziamaka chamam ele de muquirana no leilão. E já tomou punição com o Wizards.'],
    [140, 'Wes rookie', 'Ri em KKKKKKKK gigantesco — chegou a mandar um de 236 caracteres. Não acentua, usa "oque", "tmb" e "pqp". Discute regra de cap e free agency citando artigo. Vira a noite: pico 23h-00h.', 'Perguntou se podia imprimir o edital e imprimiu. Até hoje cobram a foto do edital impresso e ele ameaça sacar dele.'],
    [142, 'Kleber', 'O cara da madrugada: pico à 00h. Escreve com acento, pontuação e letra maiúscula, quase não usa kkk, solta "hahaha". Textão sobre draft, troca e regra. Bordões: "pqp", "daí" e "Enfim".', 'A primeira punição dele virou saga, ele mesmo diz que "jamais será esquecida". O grupo apostava que ia tomar punição até a temporada 8.'],
    [143, 'Gabriel Rookie', 'Chegou em 25/10 no Kings. Não acentua ("irmao", "nao"), ri KKKKKKKKK em caixa alta e chama todos de "irmão" e "mano". Fala mais de NBA real e de futebol que da própria liga. Pico 23h e meio da manhã.', 'Chegou por último e o grupo ainda testa ele: zoam que fala mais de futebol e de NBA real do que do próprio Kings.'],
];

$insF = $pdo->prepare("INSERT INTO bot_folclore (liga, assunto, fato, fonte) VALUES (?, ?, ?, 'seed')
                       ON DUPLICATE KEY UPDATE fato = IF(fonte = 'seed', VALUES(fato), fato),
                                               atualizado_em = CURRENT_TIMESTAMP");
foreach ($folclore as $f) $insF->execute($f);

$insP = $pdo->prepare("INSERT INTO bot_personalidades (user_id, liga, apelido_zap, time_nome, resumo, zoeira, fonte)
                       VALUES (?, 'ROOKIE', ?, ?, ?, ?, 'seed')
                       ON DUPLICATE KEY UPDATE
                           user_id   = VALUES(user_id), time_nome = VALUES(time_nome),
                           resumo    = IF(fonte = 'seed', VALUES(resumo), resumo),
                           zoeira    = IF(fonte = 'seed', VALUES(zoeira), zoeira),
                           atualizado_em = CURRENT_TIMESTAMP");
$busca = $pdo->prepare("SELECT t.user_id, TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) nome
                          FROM teams t WHERE t.id = ? AND t.league = 'ROOKIE'");

$ok = 0; $erro = [];
foreach ($perfis as [$teamId, $apelido, $resumo, $zoeira]) {
    $busca->execute([$teamId]);
    $t = $busca->fetch(PDO::FETCH_ASSOC);
    if (!$t) { $erro[] = "$apelido (time $teamId)"; continue; }
    $insP->execute([$t['user_id'] ?: null, $apelido, $t['nome'], $resumo, $zoeira]);
    $ok++;
}

echo "ROOKIE — folclore: " . count($folclore) . " | perfis: {$ok} de " . count($perfis) . "\n";
if ($erro) echo "  NAO ACHOU O TIME: " . implode(', ', $erro) . "\n";
echo "  sem perfil (vao cair no generico): Charlotte Hornets e Milwaukee Bucks\n";
