<?php
/**
 * ── O QUE O BOT SABE SOBRE CADA UM, NA PRIMEIRA CARGA ─────────────────
 *
 * Cria as tabelas do papo e enche com o que saiu de uma leitura dos chats da
 * ELITE (22/09 a 09/11/2026, 79 mil mensagens) e do Chat-Off Geral (349 mil).
 *
 * Roda quantas vezes quiser: é ON DUPLICATE KEY UPDATE, e só mexe no que tem
 * `fonte = 'seed'` — perfil corrigido na mão pelo dono (`fonte = 'admin'`) ou
 * reescrito pelo cron (`fonte = 'cron'`) fica como está. Esta carga é o chão,
 * não o teto.
 *
 * O RESUMO É CURTO DE PROPÓSITO. Ele vai inteiro dentro do prompt a cada
 * menção: dossiê de dez linhas por pessoa viraria conta alta e resposta lenta,
 * e o modelo não precisa saber tudo — precisa saber com quem está falando.
 *
 * Uso:  php cron/bot-perfis-seed.php
 */

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/bot_papo.php';

$pdo = db();
papoGarantirTabelas($pdo);

/* ── A LÍNGUA DA CASA ──────────────────────────────────────────────────
   Gíria e piada que a liga inteira entende. Sem isto o bot responde certo e
   soa como alguém que entrou no grupo ontem. */
$folclore = [
    ['FBA', 'assalto', 'Troca muito vantajosa. É ELOGIO e não acusação — o pessoal usa até sobre si mesmo ("toda troca minha é assalto"). Chamar alguém de assaltante ali é reconhecer que ele negocia bem.'],
    ['FBA', 'kitar', 'Sair da liga. Virou verbo por causa do Kevyn, que saiu ao vivo em 24/09/2026 quando refizeram a loteria e ele caiu da pick 5 pra 9. O Eloy herdou a vaga dele. "Se vai sair, vai de Kevyn" é piada corrente.'],
    ['FBA', 'vou sair do fantasy', 'Meme, nunca ameaça. O Eduardo copia o mesmo texto desde junho e o Leonardo faz a variante dele. Ninguém nunca saiu depois de mandar isso. Pode zoar quando aparecer.'],
    ['FBA', 'tank', 'Perder de propósito pra pegar pick melhor. Metade da liga faz e a outra metade reclama. Time brigando pra CAIR no ranking é cena normal e rende piada.'],
    ['FBA', 'campeão atual', 'Quem ganhou a edição não deixa ninguém esquecer e usa o título como argumento em qualquer discussão. Zoar campeão é osso duro — ele sempre tem a resposta pronta.'],
    ['FBA', 'operacional', 'Quem mexe no save e faz a liga rodar, de graça. Assunto sensível: metade defende com unhas e dentes, a outra metade culpa por tudo. Não entre nessa briga.'],
    ['FBA', 'legend / lenda', 'Jogador histórico, o ativo mais caro da liga. Quem tem, segura. Quem não tem, reclama.'],
    ['FBA', 'pv', 'Privado. "chama no pv", "responde o pv" — metade das negociações acontece lá e a outra metade vira cobrança pública de quem não respondeu.'],
    ['ELITE', 'panela', 'Acusação recorrente: grupo de GMs que só troca entre si. Quem reclama disso acha que nunca recebe proposta boa.'],
];

$insF = $pdo->prepare("INSERT INTO bot_folclore (liga, assunto, fato, fonte) VALUES (?, ?, ?, 'seed')
                       ON DUPLICATE KEY UPDATE fato = IF(fonte = 'seed', VALUES(fato), fato),
                                               atualizado_em = CURRENT_TIMESTAMP");
foreach ($folclore as $f) $insF->execute($f);
echo "folclore: " . count($folclore) . " linhas\n";

/* ── AS PESSOAS ────────────────────────────────────────────────────────
   [team_id, apelido no WhatsApp, resumo, do que dá pra pegar no pé]

   O team_id é a âncora porque é o único identificador que não muda: apelido no
   WhatsApp a pessoa troca, nome de franquia ela rebatiza no meio da edição
   (o Buffalo Blues virou Dallas Blues em outubro e voltou). */
$perfis = [
    // ── os que mais falam ──
    [33, 'Kleberson', 'O que mais fala na liga inteira (43 mil mensagens). Metralha frases curtas, zero acento, ri "kkkkkkkkkkkkkkkk" longo. Vive trocando — diz que é viciado em proposta. Conhece o edital de cor e usa em discussão. Já trabalhou no operacional.', 'A liga toda chama ele de ladrão por causa das trocas. Ele detesta e não cala nunca.'],
    [81, 'Maia', 'O debatedor técnico da liga: fala em Per 36, eficiência, tendency, e exige que se avalie jogador por produção e não por nome. Escreve com pontuação, frase inteira. Usa 👍 irônico pra encerrar discussão. Todo mundo pede opinião de troca pra ele.', 'Fica puto quando avaliam jogador por OVR e nome. Caiu na Stepien Rule e chamou o edital de inacreditável.'],
    [60, 'Romeno', 'Zoeiro, chama todo mundo de "paizão", 🤣 em vez de 😂. Acompanha o mercado da liga inteira e sabe quem tem o quê. Manda proposta em tudo que acha que faz sentido. Aparece de madrugada, 1h da manhã.', 'Manda proposta pra liga inteira e critica a dos outros em público. Tem fama de superestimar o que é dele.'],
    [2, 'Bruno', 'Admin e GM. Escreve por extenso, ri com "hahahaha" (só ele), bordão é "Na moral". Manda áudio o tempo todo. Guarda a história da liga e dá boa ideia de sistema. Só existe das 9h às 12h — some depois das 18h.', 'A liga inteira diz que ele demora pra executar a parte de admin. Zoeira eterna com o Anderson.'],
    [48, 'Pedrão', 'Acentua tudo, não abrevia, quase não xinga. Pergunta muito e grita pouco. Referência em classe de draft e em quem vai subir de over. Bordão: "Busco". Acha que a liga é competição, não resenha.', 'Vive de vitrine: sonda, negocia e não fecha troca nenhuma — ele mesmo admitiu.'],
    [6, 'Lennon', 'Admin e dono do time mais forte da liga. Não acentua nada. É o único que escreve parágrafo de verdade. Fez 56 trocas numa edição e exige oferta formal ("chega com oferra"). Quem a liga consulta sobre progression.', 'Tem o melhor time e é admin — a liga brinca que a adm favorece ele. Detesta quem reclama no grupo em vez do pv.'],
    [76, 'Anderson', 'Gaúcho, "bahh" e "pqp". Figurinha em vez de kkk. Fala de rotação, badge e diretriz. Reporta bug e pede feature direto pro dono do app.', 'O mais travado da liga: já postou que recusou 139 trocas. Ele mesmo diz que o melhor jeito de pegá-lo é domingo 22h, desesperado.'],
    [42, 'Rick', 'Vive no chat geral, visita o da liga. Só aparece de tarde e de noite. Telegráfico, manda "." sozinho. Especialista em ciclo longo: tankar, juntar pick, atacar leilão. Chama os outros de covarde quando não cobrem lance.', 'Defendeu publicamente que assaltar é qualidade e não demérito. Nunca ganha leilão e reclama disso.'],

    // ── presença constante ──
    [74, 'Kenderson', 'O único que escreve com acento e pontuação completos — e o único que faz textão. Maior usuário do bot da liga. Se apaixona por jogador e faz campanha pública pra conseguir a troca. Fala em número.', 'A novela da pick 9 que ele entregou pro Eloy e quis de volta. Vive dizendo que é 120% Houston Parfums.'],
    [4, 'Agata', 'Admin e GM. Minúscula, abreviadíssima, nunca escreve textão. Cobra diretriz, prazo e organiza live. Pede fundamentação quando discordam dela.', 'Defende o operacional com unhas e dentes. Quem culpa o operacional perde ela na hora.'],
    [20, 'Lucas Fernandes', 'Admin. O mais matinal da liga — 8h às 11h. Sem acento. Usa "…." de quatro pontos pra ironizar. É quem escreve regra e punição. Acha que todo mundo tenta tirar vantagem na negociação.', 'Já anunciou que ia sair da adm e continua lá. Odeia ser acionado como admin fora de hora.'],
    [82, 'Simão', 'O 2º que mais fala da comunidade inteira, mas some no grupo da liga. Vive de madrugada, 22h às 2h. Traz stat avançada e dado de fora. Tem regra do edital batizada com o nome dele.', 'É o maior inimigo do leilão: acha que não deveria existir. Cobra mudança de regra de madrugada e em caixa alta.'],
    [47, 'Eloy', 'Chegou na ELITE em setembro vindo de divisão inferior e não deixa esquecer. Humor absurdista, usa "pprt". Admite que fica de ghost até ter pick alta.', 'Herdou a vaga do Kevyn. A rivalidade diária com o Kenderson pela pick 9.'],
    [67, 'Gabriel - Shuffles', 'Abrevia tudo até a consoante. Abre frase com "Pior que…" e chama todo mundo de "amigo" — inclusive quando está mandando desaforo. Fala pouco e sente muito.', 'Acha que a loteria devia ser par ou ímpar. Reclama que tem panela que não troca com ele nem com arma apontada.'],
    [7, 'Kanada', 'Só existe das 17h às 21h — trabalha de dia. Nunca escreve textão. Joga comparativo no grupo pra provocar. Acha que a FBA é pra levar na brincadeira.', 'É o mais mal-afamado em preço da liga: pede valor de lenda em role player. Ele ri disso junto.'],
    [69, 'Fava', 'O mais telegráfico de todos — abrevia até a consoante. Chama todo mundo de "meu docinho de coco", "bbzao". Conversa por figurinha. É quem se oferece pra operar leilão.', 'Caça ativo barato e assume que é oportunista. Já ofereceu pix pra alguém conferir a progression dele.'],

    // ── participação média ──
    [86, 'Erick', 'Horário comercial puro. Minúsculas, ri só com "kkkk", CAIXA ALTA quando empolga. Anuncia o que está disponível pra troca no grupo. Separa negócio de pessoa e fala isso em voz alta.', 'Usa "assaltar" o tempo todo. Fica bravo quando subestimam o valor dos jogadores dele.'],
    [56, 'Eduardo', 'Mensagem de um nome de jogador só. Maior usuário de figurinha da liga. Ri de tudo. Sabe o histórico real da NBA de cor. Tem fama de negociador honesto e o grupo confirma.', 'Criou a "fava rule" e o jogador "almirante". É o autor do meme de sair do fantasy. Odeia quem volta atrás em trade.'],
    [72, 'Murilo - NY Mafia', 'Alonga vogal pra enfatizar ("lotaaaado"). O grupo chama ele de Murilove. Posta #EMBRACEMAFIA. Sabe de cor quem trocou o que com quem. Abre enquete antes de leiloar — o mais processual da liga.', 'Deu o maior overpay da liga num leilão e o cara recusou. Ele não superou.'],
    [54, 'Vini - Macaws', 'Um dos maiores falantes da comunidade. Maior usuário de áudio da liga. "tu" o tempo todo, bordão "emociona", compara tudo com futebol. Defende que competir tenha que ser recompensado.', 'Vive saindo do grupo e sendo readicionado. Já saiu duas vezes e não explicou nenhuma.'],
    [71, 'Guilherme - Crabs', 'O único que escreve com maiúscula e pontuação normais. Responde em rajada curta: "Verdade", "Sim", "Manda". Usa "pica" como elogio. Manda áudio e critica áudio dos outros.', 'Acha que trocar é a parte mais legal e vive reclamando que não consegue fechar nada. Caiu na Stepien.'],
    [64, 'Careca - Catrinas', 'Veterano. Ironia de advogado: "Estou acionando meus advogados", "Quer moleza mastiga água, comissário". Chama o Maia de Mamaia e o Kleberson de klebinho. Fala em range de pick e asset negociável.', 'Tanka de novo toda edição e a liga cobra. Acusa o Maia de mudar o critério de avaliação conforme convém.'],
    [43, 'Leonardo elite', 'Começa cedo e some depois das 19h — trabalha. Reage em uma palavra: "Justo", "F", ".". Sarcasmo é o registro padrão. Bordão de oferta: "Busco".', 'É o crítico mais vocal da adm e do tank alheio. Chama o operacional de vagabundo e diz que a adm passa pano.'],
    [58, 'Caio - Sunsets', 'Bicampeão e ex-admin. Só aparece no fim da tarde, 17h. A palavra dele é "justo". Quase não manda figurinha, manda print. Cobra cap e odds da organização.', 'Diz que é o único da liga que faz troca justa enquanto todo mundo assalta. Já largou a FBA e voltou.'],

    // ── os quietos ──
    [77, 'Lazaro - Celestials', 'Metade das mensagens dele são comando de bot. Nunca escreve mais que uma linha. Entra, consulta o time, atira uma proposta e some. Diz ter 13 títulos e lembra disso toda hora.', 'É o campeão que carteira o título em qualquer discussão. A liga diz que ele é o maior assaltante — ele responde que é sempre assaltado.'],
    [8, 'Zaza', 'Escreve "nn" no lugar de "não" — marca registrada. Vive no chat geral, visita o da liga. Ri em CAPS, fecha acordo com "fecho em 👊🏻". Gosta de pick de segunda rodada.', 'Já ganhou o título e desligou: diz que só volta quando vier uma pick boa. Só troca com quem procura ele.'],
    [9, 'Renan', 'Chegou em outubro ocupando a vaga do DVD. Ex-comissário, diz ter 7 títulos. Único que usa maiúscula em toda mensagem. Bordão: "Pergunta sincera" antes de provocar e "Simples." pra encerrar. Admite que não sabe muito de basquete.', 'Rebatizou o time em homenagem ao Sport. Ele e o Maia não se dão nem um pouco.'],
    [88, 'Vini Lobo', 'Economia máxima e emoji como pontuação — 🌚 é a marca dele. "Blz", "Suave", "Pse". Chama o Maia de chefinha. Resolve tudo no pv.', 'A liga diz que ele supervaloriza o que é ruim e vende barato o que é bom. Já trocou jogador com over errado.'],
    [63, 'Thales - Dope', 'O português mais formal da liga: maiúscula, acento, ponto final. Humor seco e construído. Organiza outras cinco ligas de fantasy. É o maior defensor público do app. Fiel ao planejamento dele.', 'A piada do hambúrguer como suborno pra quem mexe no save. Jogo duro com quem insiste e dó de recusar quem é gente boa.'],
    [78, 'Matheus - Devills', 'Some por dez dias e volta em rajada. 💔 é a assinatura dele. Ri KKKKKKKKKK altíssimo. O mais forte em NBA real da liga — rotação, minutagem, técnico. Diz que não abre o grupo porque tem mil mensagens.', 'A liga chama ele de planta por sumir. Ele responde todas as vezes. Fica emocionado quando o bot manda abraço.'],
    [61, 'Caio Gomes', 'Quase não usa o grupo da liga — vive no geral, de madrugada, narrando jogo real. Sentença de uma palavra: "Bizarro", "Baller", "Amasso". Nunca escreve mais que uma linha.', 'Tentou fazer um leilão, desistiu no meio e cancelou. Diz que é um mal necessário.'],
];

$insP = $pdo->prepare("INSERT INTO bot_personalidades (user_id, liga, apelido_zap, time_nome, resumo, zoeira, fonte)
                       VALUES (?, 'ELITE', ?, ?, ?, ?, 'seed')
                       ON DUPLICATE KEY UPDATE
                           user_id   = VALUES(user_id),
                           time_nome = VALUES(time_nome),
                           resumo    = IF(fonte = 'seed', VALUES(resumo), resumo),
                           zoeira    = IF(fonte = 'seed', VALUES(zoeira), zoeira),
                           atualizado_em = CURRENT_TIMESTAMP");

$busca = $pdo->prepare("SELECT t.user_id, TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) nome
                          FROM teams t WHERE t.id = ? AND t.league = 'ELITE'");

$ok = 0; $semTime = [];
foreach ($perfis as [$teamId, $apelido, $resumo, $zoeira]) {
    $busca->execute([$teamId]);
    $t = $busca->fetch(PDO::FETCH_ASSOC);
    if (!$t) { $semTime[] = "$apelido (time $teamId)"; continue; }
    $insP->execute([$t['user_id'] ?: null, $apelido, $t['nome'], $resumo, $zoeira]);
    $ok++;
}

echo "perfis: {$ok} de " . count($perfis) . "\n";
if ($semTime) echo "SEM TIME NA ELITE (confira): " . implode(', ', $semTime) . "\n";

$n = (int)$pdo->query("SELECT COUNT(*) FROM bot_personalidades WHERE liga='ELITE' AND ativo=1")->fetchColumn();
$semUser = (int)$pdo->query("SELECT COUNT(*) FROM bot_personalidades WHERE liga='ELITE' AND user_id IS NULL")->fetchColumn();
echo "na tabela: {$n} ativos, {$semUser} sem user_id\n";
