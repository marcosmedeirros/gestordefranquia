<?php
/**
 * O QUE CADA PUNIÇÃO FAZ — e o quadro de infrações de cada edital.
 *
 * Antes, a punição era só um registro: o admin anotava "perdeu a pick" e ia
 * tirar a pick na mão. Pior, o que o sistema *achava* que aplicava não batia
 * com o rótulo — "Trades bloqueadas por uma temporada" prendia o ciclo
 * inteiro (duas), e "próxima temporada" prendia dois ciclos (quatro).
 *
 * Aqui ficam as duas metades que faltavam:
 *
 *   EFEITOS   o que o sistema sabe fazer sozinho, com a duração em cima da
 *             régua certa. Isto mora em código porque cada efeito tem uma
 *             implementação — não adianta o admin cadastrar um efeito novo
 *             pela tela se não existe quem o cumpra.
 *
 *   QUADRO    as infrações do edital de cada liga, com a escala por
 *             ocorrência. Isto NASCE aqui e vive no banco: o edital é do
 *             admin, muda de edição pra edição, e transcrever pra cá criaria
 *             uma segunda versão da regra que envelhece calada — o mesmo erro
 *             que backend/edital_guia.php existe pra não cometer.
 *
 * As duas escalas do edital (ELITE/NEXT, art. "Resumo das Punições"; ROOKIE,
 * Cap. XIII art. 47) contam reincidência DENTRO DO CICLO de duas temporadas,
 * não pela vida toda. Está escrito nos dois: "conforme a reincidência dentro
 * do mesmo ciclo de 2 (duas) temporadas".
 */

require_once __DIR__ . '/db.php';

/* ─────────────────────────────────────────────────────────────────────
   OS EFEITOS
   ───────────────────────────────────────────────────────────────────── */

/**
 * Os efeitos `aplica` que o CAMINHO AVULSO sabe cumprir.
 *
 * A punição avulsa (o caso omisso, fora do quadro) tem uma implementação mais
 * curta que a do quadro: ela mexe nas colunas de ban em `teams`, marca pick e
 * gasta o ciclo de trocas — e nada além disso. Oferecer ali um efeito que só
 * o quadro executa (perder FBA Points, recuar no draft) devolveria um rótulo
 * que não faz nada, que é justamente o problema que a lista digitada à mão
 * criou. Efeito `registra` não entra aqui porque registro é honesto por
 * definição: a tela diz "quem executa é você".
 *
 * @see api/punicoes.php, ação 'add'
 */
const PUNICAO_AVULSA_APLICA = [
    'BAN_TRADES',
    'BAN_TRADES_PICKS',
    'BAN_FREE_AGENCY',
    'ROTACAO_AUTOMATICA',
    'PERDA_PICK_1R',
    'PERDA_PICK_ESPECIFICA',
    'CICLO_SEM_TROCA',
];

/**
 * `aplica`  o sistema cumpre sozinho — bloqueia, tira, pula.
 * `registra` fica no histórico e aparece na tag do time, mas quem executa é
 *            gente (suspender do WhatsApp, excluir da liga, cobrar multa).
 *            Marcar como registro não é desistir: é não mentir que o sistema
 *            fez o que não fez.
 * `duracao`  se a pena corre no tempo (ban) ou acontece de uma vez (perda).
 * `valor`    o número que a pena pede: quantas trades, quantos minutos,
 *            quantas posições.
 */
const PUNICAO_EFEITOS = [
    'ADVERTENCIA' => [
        'label' => 'Advertência formal',
        'modo' => 'registra', 'duracao' => 'evento', 'valor' => null,
        'tag' => 'Advertido',
    ],
    'BAN_TRADES' => [
        'label' => 'Proibido de fazer trocas',
        'modo' => 'aplica', 'duracao' => 'periodo', 'valor' => null,
        'tag' => 'Sem trocas',
    ],
    'BAN_TRADES_PICKS' => [
        'label' => 'Proibido de negociar picks',
        'modo' => 'aplica', 'duracao' => 'periodo', 'valor' => null,
        'tag' => 'Sem trocar picks',
    ],
    'BAN_FREE_AGENCY' => [
        'label' => 'Proibido de usar a Free Agency',
        'modo' => 'aplica', 'duracao' => 'periodo', 'valor' => null,
        'tag' => 'Sem FA',
    ],
    'PERDA_TRADES' => [
        'label' => 'Perde trocas do ciclo',
        'modo' => 'aplica', 'duracao' => 'periodo', 'valor' => 'quantas trocas',
        'tag' => 'Trocas cortadas',
    ],
    /* CICLO SEM TROCA: gasta de uma vez TODAS as trocas do ciclo.
       Não é o BAN_TRADES com outro nome. O ban é uma coluna à parte
       (teams.ban_trades_until_cycle) que precisa ser posta e tirada na data
       certa; este consome o saldo do ciclo — põe trades_used no máximo da
       liga —, então acaba sozinho quando o ciclo vira e o contador zera
       (trades.php já faz isso na virada). E aparece onde o GM olha: o
       contador de trocas dele fica em 10/10, não num aviso escondido. */
    'CICLO_SEM_TROCA' => [
        'label' => 'Ciclo sem troca',
        'modo' => 'aplica', 'duracao' => 'periodo', 'valor' => null,
        'tag' => 'Ciclo sem troca',
    ],
    'PERDA_PICK_1R' => [
        'label' => 'Perde a própria pick de 1ª rodada',
        'modo' => 'aplica', 'duracao' => 'evento', 'valor' => null,
        'tag' => 'Sem pick de 1ª',
    ],
    'PERDA_PICK_ESPECIFICA' => [
        'label' => 'Perde uma pick escolhida',
        'modo' => 'aplica', 'duracao' => 'evento', 'valor' => null,
        'tag' => 'Pick perdida',
    ],
    'RECUO_PICKS' => [
        'label' => 'Recua posições no draft',
        'modo' => 'aplica', 'duracao' => 'evento', 'valor' => 'quantas posições',
        'tag' => 'Recuo no draft',
    ],
    'ROTACAO_AUTOMATICA' => [
        'label' => 'Rotação automática (CPU escala)',
        'modo' => 'aplica', 'duracao' => 'periodo', 'valor' => null,
        'tag' => 'Rotação automática',
    ],
    'TETO_MINUTOS' => [
        'label' => 'Teto de minutos',
        'modo' => 'registra', 'duracao' => 'periodo', 'valor' => 'minutos',
        'tag' => 'Teto de minutos',
    ],
    'BAN_LEILAO' => [
        'label' => 'Proibido de usar o leilão',
        'modo' => 'aplica', 'duracao' => 'periodo', 'valor' => null,
        'tag' => 'Sem leilão',
    ],
    'LEILAO_COMPULSORIO' => [
        'label' => 'Leilão compulsório de um atleta',
        'modo' => 'registra', 'duracao' => 'evento', 'valor' => 'entre os N maiores OVR',
        'tag' => 'Leilão compulsório',
    ],
    'ANULACAO_TRADE' => [
        'label' => 'Troca anulada',
        'modo' => 'registra', 'duracao' => 'evento', 'valor' => null,
        'tag' => 'Troca anulada',
    ],
    'PERDA_MOEDAS' => [
        'label' => 'Perde os FBA Points',
        'modo' => 'aplica', 'duracao' => 'evento', 'valor' => null,
        'tag' => 'Sem FBA Points',
    ],
    'MULTA_MOEDAS' => [
        'label' => 'Multa em FBA Points',
        'modo' => 'aplica', 'duracao' => 'evento', 'valor' => 'quantos pontos',
        'tag' => 'Multado',
    ],
    'REDUCAO_ODDS' => [
        'label' => 'Menos chances na loteria',
        'modo' => 'registra', 'duracao' => 'evento', 'valor' => null,
        'tag' => 'Odds reduzidas',
    ],
    'SUSPENSAO_CANAIS' => [
        'label' => 'Suspenso dos canais (24h)',
        'modo' => 'registra', 'duracao' => 'evento', 'valor' => null,
        'tag' => 'Suspenso',
    ],
    'EXCLUSAO_LIGA' => [
        'label' => 'Exclusão da liga',
        'modo' => 'registra', 'duracao' => 'evento', 'valor' => null,
        'tag' => 'Excluído',
    ],
];

/** Os efeitos que correm no tempo — os únicos que uma tag "ativa" mostra. */
function punicaoEfeitosDePeriodo(): array
{
    return array_keys(array_filter(PUNICAO_EFEITOS, fn($e) => $e['duracao'] === 'periodo'));
}

function punicaoEfeitoInfo(string $efeito): ?array
{
    return PUNICAO_EFEITOS[strtoupper(trim($efeito))] ?? null;
}

/* ─────────────────────────────────────────────────────────────────────
   AS DURAÇÕES
   ───────────────────────────────────────────────────────────────────── */

/**
 * Por quanto tempo a pena corre.
 *
 * Esta é a parte que estava errada. O sistema só sabia contar em ciclo, então
 * "uma temporada" prendia duas e "a próxima" prendia quatro. Agora temporada é
 * temporada e ciclo é ciclo — e o ciclo é o mesmo do reset de trades
 * (current_cycle = ceil(season_number / 2)), que é como o edital fala.
 */
const PUNICAO_DURACOES = [
    'TEMPORADA'      => 'Esta temporada',
    'TEMPORADA_NEXT' => 'A próxima temporada',
    'CICLO'          => 'Este ciclo (2 temporadas)',
    'CICLO_NEXT'     => 'O próximo ciclo (2 temporadas)',
    'PERMANENTE'     => 'Sem prazo (até ser revertida)',
];

/* ─────────────────────────────────────────────────────────────────────
   O QUADRO DE INFRAÇÕES — a semente, tirada dos editais
   ───────────────────────────────────────────────────────────────────── */

/**
 * ELITE e NEXT têm o MESMO quadro; só mudam os números dos artigos (a NEXT
 * está um atrás a partir do OVR Cap). Por isso o artigo é uma coluna do banco
 * e não parte do código: quando um edital for reeditado, o admin corrige na
 * tela sem precisar de deploy.
 *
 * `degraus` é [ocorrência => [efeitos]]. Ocorrência 0 significa "vale já na
 * primeira e não escala" — o edital diz isso em várias: "já na 1ª ocorrência",
 * "de forma irrecorrível".
 */
function punicaoQuadroSemente(string $league): array
{
    $league = strtoupper(trim($league));

    // ── ELITE e NEXT: o "Resumo das Punições" do edital ──────────────
    $elite = [
        ['01', 'Conduta discriminatória ou desrespeito grave', 'Art. 9º, I, §4º', [
            1 => [['efeito' => 'ADVERTENCIA']],
            2 => [['efeito' => 'SUSPENSAO_CANAIS']],
            3 => [['efeito' => 'EXCLUSAO_LIGA']],
        ]],
        ['02', 'Acusação infundada contra a Administração', 'Art. 9º, I, §5º', [
            1 => [['efeito' => 'PERDA_PICK_1R']],
            2 => [['efeito' => 'EXCLUSAO_LIGA']],
        ]],
        ['03', 'Roster desatualizado no aplicativo', 'Art. 9º, III, §2º e §3º', [
            1 => [['efeito' => 'ADVERTENCIA'], ['efeito' => 'ROTACAO_AUTOMATICA', 'duracao' => 'TEMPORADA']],
            2 => [['efeito' => 'PERDA_PICK_1R'], ['efeito' => 'ROTACAO_AUTOMATICA', 'duracao' => 'TEMPORADA']],
            3 => [['efeito' => 'BAN_TRADES', 'duracao' => 'TEMPORADA_NEXT'], ['efeito' => 'ROTACAO_AUTOMATICA', 'duracao' => 'TEMPORADA']],
            4 => [['efeito' => 'EXCLUSAO_LIGA']],
        ]],
        ['04', 'Não responder proposta de troca em 24h', 'Art. 12, §1º', [
            1 => [['efeito' => 'ADVERTENCIA']],
            2 => [['efeito' => 'PERDA_PICK_1R']],
            3 => [['efeito' => 'PERDA_TRADES', 'valor' => 8, 'duracao' => 'CICLO']],
        ]],
        ['05', 'Abandono de projeto (2 ciclos sem movimentação)', 'Art. 12, §2º', [
            1 => [['efeito' => 'PERDA_PICK_1R']],
            2 => [['efeito' => 'EXCLUSAO_LIGA']],
        ]],
        ['06', 'Erro em trade', 'Art. 23', [
            0 => [['efeito' => 'ROTACAO_AUTOMATICA', 'duracao' => 'TEMPORADA'],
                  ['efeito' => 'BAN_TRADES', 'duracao' => 'TEMPORADA_NEXT']],
        ]],
        ['07', 'Gabriel Borges Rule (empréstimo de ativos)', 'Art. 25 e 26', [
            0 => [['efeito' => 'ANULACAO_TRADE'], ['efeito' => 'BAN_TRADES', 'duracao' => 'CICLO']],
        ]],
        ['08', 'Stepien Rule', 'Art. 27', [
            0 => [['efeito' => 'ANULACAO_TRADE'], ['efeito' => 'BAN_TRADES', 'duracao' => 'CICLO']],
        ]],
        ['09', 'Trade vetada pelo Tribunal', 'Art. 28', [
            0 => [['efeito' => 'ANULACAO_TRADE'], ['efeito' => 'PERDA_TRADES', 'valor' => 1, 'duracao' => 'CICLO']],
        ]],
        ['10', 'Excesso de OVR Cap', 'Art. 44', [
            1 => [['efeito' => 'LEILAO_COMPULSORIO', 'valor' => 5],
                  ['efeito' => 'TETO_MINUTOS', 'valor' => 20, 'duracao' => 'TEMPORADA'],
                  ['efeito' => 'PERDA_PICK_1R']],
            2 => [['efeito' => 'LEILAO_COMPULSORIO', 'valor' => 3],
                  ['efeito' => 'TETO_MINUTOS', 'valor' => 20, 'duracao' => 'TEMPORADA'],
                  ['efeito' => 'PERDA_PICK_1R'],
                  ['efeito' => 'BAN_TRADES', 'duracao' => 'CICLO']],
            3 => [['efeito' => 'EXCLUSAO_LIGA']],
        ]],
        ['11', 'OVR Cap abaixo do piso', 'Art. 45', [
            0 => [['efeito' => 'PERDA_PICK_1R']],
        ]],
        ['12', 'Recusar todas as ofertas do leilão', 'Art. 46', [
            0 => [['efeito' => 'TETO_MINUTOS', 'valor' => 12, 'duracao' => 'TEMPORADA_NEXT']],
        ]],
    ];

    // ── ROOKIE: o quadro geral do Capítulo XIII ──────────────────────
    $rookie = [
        ['01', 'Ausência de atualização pós-progression', 'Art. 14, §2º', [
            1 => [['efeito' => 'ADVERTENCIA']],
            2 => [['efeito' => 'RECUO_PICKS', 'valor' => 5]],
            3 => [['efeito' => 'PERDA_PICK_1R']],
            4 => [['efeito' => 'EXCLUSAO_LIGA']],
        ]],
        ['02', 'Excesso de OVR Cap na Deadline', 'Art. 26, I c/c §2º', [
            1 => [['efeito' => 'LEILAO_COMPULSORIO', 'valor' => 5],
                  ['efeito' => 'TETO_MINUTOS', 'valor' => 20, 'duracao' => 'TEMPORADA'],
                  ['efeito' => 'RECUO_PICKS', 'valor' => 5]],
            2 => [['efeito' => 'LEILAO_COMPULSORIO', 'valor' => 3],
                  ['efeito' => 'TETO_MINUTOS', 'valor' => 20, 'duracao' => 'TEMPORADA'],
                  ['efeito' => 'PERDA_PICK_1R'],
                  ['efeito' => 'BAN_TRADES', 'duracao' => 'CICLO']],
            3 => [['efeito' => 'EXCLUSAO_LIGA']],
        ]],
        ['03', 'OVR Cap abaixo do piso técnico', 'Art. 26, II c/c §2º', [
            0 => [['efeito' => 'PERDA_PICK_1R']],
        ]],
        ['04', 'Conluio para burlar o OVR Cap', 'Art. 30', [
            1 => [['efeito' => 'ANULACAO_TRADE'], ['efeito' => 'ADVERTENCIA']],
            2 => [['efeito' => 'BAN_TRADES', 'duracao' => 'CICLO']],
            3 => [['efeito' => 'EXCLUSAO_LIGA']],
        ]],
        ['05', 'Violação da Yan Rule / Stepien Rule', 'Art. 31', [
            0 => [['efeito' => 'ANULACAO_TRADE'], ['efeito' => 'BAN_TRADES', 'duracao' => 'CICLO']],
        ]],
        ['06', 'Violação da Gabriel Borges Rule', 'Art. 32', [
            1 => [['efeito' => 'ANULACAO_TRADE'], ['efeito' => 'BAN_TRADES', 'duracao' => 'TEMPORADA_NEXT']],
            2 => [['efeito' => 'ANULACAO_TRADE'], ['efeito' => 'BAN_TRADES', 'duracao' => 'CICLO']],
        ]],
        ['07', 'Ativo irregular em troca pós-deadline', 'Art. 33', [
            0 => [['efeito' => 'BAN_TRADES', 'duracao' => 'TEMPORADA_NEXT']],
        ]],
        ['08', 'Proteção indevida top-12/top-15', 'Art. 22, §2º', [
            1 => [['efeito' => 'ADVERTENCIA']],
            2 => [['efeito' => 'ANULACAO_TRADE']],
        ]],
        ['09', 'Tanking deliberado', 'Art. 23', [
            1 => [['efeito' => 'ADVERTENCIA'], ['efeito' => 'REDUCAO_ODDS']],
            2 => [['efeito' => 'RECUO_PICKS', 'valor' => 5]],
            3 => [['efeito' => 'PERDA_PICK_1R'], ['efeito' => 'MULTA_MOEDAS']],
            4 => [['efeito' => 'EXCLUSAO_LIGA']],
        ]],
        ['10', 'Leilão sem slot adquirido', 'Art. 40', [
            1 => [['efeito' => 'ADVERTENCIA']],
            2 => [['efeito' => 'BAN_LEILAO', 'duracao' => 'TEMPORADA']],
            3 => [['efeito' => 'BAN_LEILAO', 'duracao' => 'CICLO']],
        ]],
        ['11', 'Não responder proposta de troca', 'Art. 42', [
            1 => [['efeito' => 'ADVERTENCIA']],
            2 => [['efeito' => 'PERDA_MOEDAS']],
            3 => [['efeito' => 'PERDA_PICK_1R']],
            4 => [['efeito' => 'EXCLUSAO_LIGA']],
        ]],
        ['12', 'Abandono de franquia', 'Art. 43', [
            1 => [['efeito' => 'PERDA_PICK_1R'], ['efeito' => 'PERDA_MOEDAS']],
            2 => [['efeito' => 'EXCLUSAO_LIGA']],
        ]],
        ['13', 'Falta de atualização geral do app/roster', 'Art. 12', [
            1 => [['efeito' => 'RECUO_PICKS', 'valor' => 5]],
            2 => [['efeito' => 'PERDA_PICK_1R']],
            3 => [['efeito' => 'BAN_TRADES', 'duracao' => 'CICLO']],
            4 => [['efeito' => 'EXCLUSAO_LIGA']],
        ]],
        ['14', 'Conduta discriminatória, assédio ou desrespeito grave', 'Art. 9º', [
            1 => [['efeito' => 'ADVERTENCIA']],
            2 => [['efeito' => 'SUSPENSAO_CANAIS']],
            3 => [['efeito' => 'EXCLUSAO_LIGA']],
        ]],
        ['15', 'Intimidação ou zombaria a GMs novatos', 'Art. 10', [
            1 => [['efeito' => 'ADVERTENCIA']],
            2 => [['efeito' => 'SUSPENSAO_CANAIS']],
            3 => [['efeito' => 'EXCLUSAO_LIGA']],
        ]],
        ['16', 'Acusação infundada contra a Administração', 'Art. 11', [
            1 => [['efeito' => 'ADVERTENCIA']],
            2 => [['efeito' => 'PERDA_PICK_1R']],
            3 => [['efeito' => 'EXCLUSAO_LIGA']],
        ]],
    ];

    /* A RISE NÃO TEM EDITAL NO BANCO, e a NEXT usa o mesmo quadro da ELITE
       com os artigos deslocados. Ninguém fica sem quadro: a liga sem edital
       próprio começa com o da ELITE e o admin ajusta na tela — melhor um
       quadro para corrigir do que uma tela vazia que devolve o trabalho pra
       mão dele, que é de onde estamos saindo. */
    return match ($league) {
        'ROOKIE' => $rookie,
        default  => $elite,
    };
}
