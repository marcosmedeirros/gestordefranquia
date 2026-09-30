<?php
/**
 * O CLUBE FBA NO WHATSAPP.
 *
 * O Clube mora em /clube.php e é onde a liga marca o que assiste. Este arquivo
 * é a ponte pro grupo: os mesmos números, em texto curto, pra quem está no
 * celular e não vai abrir o site pra conferir uma nota.
 *
 * ── POR QUE FICA AQUI E NÃO NO whatsapp-comandos.php ────────────────
 *
 * Aquele arquivo já passou de cinco mil linhas, e o Clube vai ganhar módulos
 * (livros, filmes, música). Cada módulo novo é um punhado de comandos; juntos
 * lá dentro eles virariam mais mil linhas no meio da liga de basquete, que não
 * tem nada a ver. Aqui o roteador só chama, como já faz com as estatísticas.
 *
 * ── O QUE CADA COMANDO RESPONDE ──────────────────────────────────────
 *
 *   /clube          — quais comandos existem (é o índice, e o convite)
 *   /minhasseries   — o MEU top e os meus números, pelo telefone
 *   /topseries      — as mais bem avaliadas PELA LIGA
 *   /seriesmomento  — o que a galera está assistindo AGORA
 *   /serie nome     — a ficha de uma série com o que a liga disse dela
 *   /seriesde nome  — o diário de alguém
 *
 * Nenhum deles fala com o TMDB: o bot responde do catálogo que já está no
 * banco. Uma busca que sai pra internet no meio do grupo deixaria o comando
 * pendurado dois segundos, e o bot tem freio de doze por minuto.
 *
 * @see backend/series.php   as regras e as consultas
 * @see clube.php            a tela
 */

require_once __DIR__ . '/series.php';

/* NENHUM COMANDO MANDA LINK (decisão dele, 30/09/2026).

   Cada resposta fechava com o endereço do Clube. No grupo, sete comandos
   repetindo a mesma URL viram assinatura de propaganda: a linha some da
   leitura e ainda faz o WhatsApp abrir a pré-visualização do site embaixo
   de toda resposta, empurrando a conversa pra cima.

   Quem está no grupo já sabe onde o Clube fica — quem não sabe pergunta, e
   aí o link vem de uma pessoa, que é quando ele é lido. A divulgação leva o
   endereço; o bot responde o que perguntaram e cala. */

/**
 * Quantos itens cabem numa lista do WhatsApp.
 *
 * Cinco e não dez: a mensagem do bot concorre com a conversa do grupo, e lista
 * de dez linhas some no scroll antes de alguém ler a terceira. Quem quiser as
 * dez abre o site — e é justamente o que o link no rodapé serve pra fazer.
 */
const CB_QUANTOS = 5;

/** O ano da série, do jeito curto que cabe ao lado do título. */
function cbAno(array $s): string
{
    $a = seriesAnos($s['ano_inicio'] ? (int)$s['ano_inicio'] : null,
                    $s['ano_fim'] ? (int)$s['ano_fim'] : null, !empty($s['em_exibicao']));
    return $a !== '' ? " _({$a})_" : '';
}

/** Nota com vírgula, ou travessão quando não tem. */
function cbNota($n): string
{
    return $n === null ? '—' : number_format((float)$n, 1, ',', '');
}

/**
 * O ÍNDICE — e o único texto que explica o que o Clube é.
 *
 * Quem digita /clube no grupo quase sempre não sabe do que se trata: o comando
 * corre mais que o anúncio. Por isso ele abre dizendo o que é, e só depois
 * lista. Um menu seco de seis linhas responderia "quais comandos" pra quem
 * ainda está perguntando "o que é isso".
 */
function cbMenu(): string
{
    return "*Clube FBA*\n"
         . "_O que a liga anda vendo, lendo e ouvindo. O primeiro módulo é Séries._\n\n"
         . "*Séries*\n"
         . "/minhasseries — seu top e seus números\n"
         . "/topseries — as mais bem avaliadas pela liga\n"
         . "/seriesmomento — o que a galera está assistindo agora\n"
         . "/seriesfila — as que mais gente quer ver\n"
         . "/serie _nome_ — a ficha, com o que a liga disse\n"
         . "/seriesde _nome do GM_ — o diário de alguém";
}

/**
 * AS MAIS BEM AVALIADAS PELA LIGA.
 *
 * A nota daqui e não a do IMDb — a do IMDb qualquer um vê sozinho. O que só
 * existe por causa da liga é saber que a galera daqui deu 9 numa que o mundo
 * deu 6, e é isso que vira conversa no grupo.
 */
function cbTopSeries(PDO $pdo): string
{
    $lista = seriesRankingDaLiga($pdo, 'nota', CB_QUANTOS);
    if (!$lista) {
        return "*Top séries da FBA*\n\n"
             . "Ainda não tem série com " . SERIES_RANKING_MIN . " notas da liga — "
             . "uma nota só não é média de ninguém.\n\n"
             . "_Dê as suas no Clube FBA._";
    }

    /* SEM O IMDB AQUI (decisão dele, 30/09/2026). Esta lista é a nota da
       liga, e a do IMDb ao lado convidava a comparar as duas — o que muda o
       assunto: a conversa passa a ser "o mundo deu menos" em vez de "a
       galera daqui gostou". Na ficha (/serie) as três notas continuam juntas,
       porque lá comparar é exatamente o ponto.

       Na fila (/seriesfila) ela FICA: ninguém daqui assistiu ainda, então a
       do IMDb é a única nota que existe pra dizer se vale a pena. */
    $txt = "*Top séries da FBA*\n_Nota da liga, com " . SERIES_RANKING_MIN . " avaliações ou mais_\n\n";
    foreach ($lista as $i => $s) {
        $txt .= ($i + 1) . "º *" . $s['titulo'] . "*" . cbAno($s) . "\n"
              . "   FBA " . cbNota($s['nota_fba']) . " _(" . (int)$s['votos_fba'] . " nota"
              . ((int)$s['votos_fba'] === 1 ? '' : 's') . ")_\n";
    }
    return rtrim($txt);
}

/**
 * O QUE A LIGA ESTÁ ASSISTINDO AGORA.
 *
 * Diferente do "mais assistidas": aquilo é acervo, isto é o assunto da semana.
 * É o comando que faz alguém dizer "tô vendo também" no grupo.
 *
 * QUANDO NINGUÉM ESTÁ NO MEIO DE NADA, cai pras que mais gente quer ver — que
 * é a pergunta seguinte, e é melhor que um "ninguém está assistindo" seco.
 */
function cbSeriesMomento(PDO $pdo): string
{
    $lista = seriesRankingDaLiga($pdo, 'vendo', CB_QUANTOS);
    if ($lista) {
        $txt = "*No momento na FBA*\n_O que a liga está assistindo agora_\n\n";
        foreach ($lista as $s) {
            $txt .= "▶ *" . $s['titulo'] . "*" . cbAno($s) . "\n"
                  . "   " . (int)$s['valor'] . ((int)$s['valor'] === 1 ? ' pessoa' : ' pessoas')
                  . ($s['nota_fba'] !== null ? " • FBA " . cbNota($s['nota_fba']) : '') . "\n";
        }
        return rtrim($txt);
    }

    $fila = seriesRankingDaLiga($pdo, 'querem', CB_QUANTOS);
    if (!$fila) {
        return "*No momento na FBA*\n\nNinguém marcou nada ainda. _Seja o primeiro no Clube FBA._";
    }
    return "*No momento na FBA*\n_Ninguém está no meio de nenhuma. Na fila:_\n\n"
         . rtrim(cbLinhasDaFila($fila));
}

/** As que mais gente quer ver — a fila da liga. */
function cbSeriesFila(PDO $pdo): string
{
    $fila = seriesRankingDaLiga($pdo, 'querem', CB_QUANTOS);
    if (!$fila) {
        return "*A fila da FBA*\n\nNinguém pôs nada na fila ainda. _Comece no Clube FBA._";
    }
    return "*A fila da FBA*\n_As que mais gente quer ver_\n\n"
         . rtrim(cbLinhasDaFila($fila));
}

/** As linhas da fila, que saem em dois comandos e precisam sair iguais. */
function cbLinhasDaFila(array $fila): string
{
    $txt = '';
    foreach ($fila as $s) {
        $txt .= "• *" . $s['titulo'] . "*" . cbAno($s) . "\n"
              . "   " . (int)$s['valor'] . ((int)$s['valor'] === 1 ? ' quer' : ' querem') . " ver"
              . ($s['nota_imdb'] !== null ? " • IMDb " . cbNota($s['nota_imdb']) : '') . "\n";
    }
    return $txt;
}

/**
 * O MEU DIÁRIO, pelo telefone de quem mandou.
 *
 * Sem nome e sem argumento: quem pergunta "minhas séries" no grupo não vai
 * digitar o próprio nome. O telefone já identifica — é o mesmo caminho do
 * /meutime e do /meucap.
 */
function cbMinhasSeries(PDO $pdo, int $userId, string $nome): string
{
    $p = seriesPerfil($pdo, $userId);
    $total = (int)$p['assistidas'] + (int)$p['assistindo'] + (int)$p['quero'];

    if ($total === 0) {
        return "*Suas séries — {$nome}*\n\n"
             . "Você ainda não marcou nada no Clube FBA.";
    }

    $txt = "*Suas séries — {$nome}*\n"
         . "_" . (int)$p['assistidas'] . " assistidas • " . (int)$p['assistindo'] . " assistindo • "
         . (int)$p['quero'] . " na fila_"
         . ($p['nota_media'] !== null ? "\n_Sua nota média: " . cbNota($p['nota_media']) . "_" : '')
         . "\n\n";

    if (!$p['top']) {
        $txt .= "Você ainda não montou seu Top " . SERIES_TOP . ".\n"
              . "_Abra uma que já assistiu e toque em Top " . SERIES_TOP . "._\n";
    } else {
        $txt .= "*Seu Top " . SERIES_TOP . "*\n";
        foreach ($p['top'] as $s) {
            $txt .= (int)$s['favorita'] . "º " . $s['titulo']
                  . ($s['minha_nota'] ? " — *" . (int)$s['minha_nota'] . "*" : '') . "\n";
        }
    }
    return rtrim($txt);
}

/**
 * O DIÁRIO DE OUTRA PESSOA, pelo nome.
 *
 * Espelha a aba Pessoas do site. No grupo ele serve pra outra coisa também:
 * cutucar. "Olha o que o fulano anda vendo" é o que faz a lista circular.
 *
 * SÓ QUEM JÁ MARCOU ALGUMA COISA entra na busca — a mesma regra do site, e
 * pelo mesmo motivo: um perfil vazio não é resposta pra ninguém.
 */
function cbSeriesDe(PDO $pdo, string $termo): string
{
    $termo = trim($termo);
    if ($termo === '') {
        return "Diz de quem: */seriesde fulano*";
    }

    $gente = seriesPessoas($pdo, $termo, 6);
    if (!$gente) {
        return "Não achei ninguém com \"{$termo}\" que tenha marcado série.";
    }
    if (count($gente) > 1) {
        $nomes = implode(', ', array_column($gente, 'nome'));
        return "Achei mais de um: {$nomes}.\nSeja mais específico.";
    }

    $g = $gente[0];
    $p = seriesPerfil($pdo, (int)$g['id']);

    $txt = "*Séries de {$g['nome']}*"
         . ($g['league'] ? " _({$g['league']})_" : '') . "\n"
         . "_" . (int)$p['assistidas'] . " assistidas • " . (int)$p['assistindo'] . " assistindo • "
         . (int)$p['quero'] . " na fila_"
         . ($p['nota_media'] !== null ? "\n_Nota média: " . cbNota($p['nota_media']) . "_" : '')
         . "\n\n";

    if (!$p['top']) {
        $txt .= "_Ainda não montou o top._\n";
    } else {
        $txt .= "*Top " . SERIES_TOP . "*\n";
        foreach ($p['top'] as $s) {
            $txt .= (int)$s['favorita'] . "º " . $s['titulo']
                  . ($s['minha_nota'] ? " — *" . (int)$s['minha_nota'] . "*" : '') . "\n";
        }
    }
    return rtrim($txt);
}

/**
 * A FICHA DE UMA SÉRIE, com o que a liga disse dela.
 *
 * É o comando mais conversa dos seis: as três notas lado a lado e, embaixo,
 * quem daqui viu e o que escreveu. Discordar de uma nota no grupo é o uso.
 *
 * PROCURA SÓ NO CATÁLOGO LOCAL. A busca do site vai ao TMDB quando não acha,
 * mas ali quem espera é uma pessoa olhando a tela; aqui é o grupo inteiro
 * esperando o bot, que tem freio de doze respostas por minuto.
 *
 * O TÍTULO EXATO GANHA DE TUDO. Sem isso, "/serie lei & ordem" respondia
 * "Lei & Ordem: Unidade de Vítimas Especiais", que tinha mais gente da liga
 * marcada — quem digitou o nome inteiro e certo não pode receber o spin-off.
 *
 * Depois dele, QUEM A LIGA JÁ MARCOU vem primeiro no desempate. "/serie the office" com
 * cinco resultados deve trazer a que alguém daqui assiste, e não a homônima
 * turca de 2019 que entrou no catálogo por popularidade.
 */
function cbSerie(PDO $pdo, string $termo): string
{
    $termo = trim($termo);
    if (mb_strlen($termo) < 2) {
        return "Diz qual: */serie breaking bad*";
    }

    seriesGarantirTabelas($pdo);
    $st = $pdo->prepare(
        "SELECT s.*, (SELECT COUNT(*) FROM series_usuario u WHERE u.serie_id = s.id) AS pessoas
           FROM series s
          WHERE s.titulo LIKE :t OR s.titulo_original LIKE :t
       ORDER BY (s.titulo = :exato OR s.titulo_original = :exato) DESC,
                pessoas DESC,
                (s.titulo LIKE :ini OR s.titulo_original LIKE :ini) DESC,
                s.popularidade DESC
          LIMIT 1");
    $st->execute([':t' => '%' . $termo . '%', ':ini' => $termo . '%', ':exato' => $termo]);
    $s = $st->fetch(PDO::FETCH_ASSOC);

    if (!$s) {
        return "Não achei \"{$termo}\" no catálogo.\n\n"
             . "_A busca do Clube FBA vai além dele e traz do TMDB._";
    }

    $txt = "*" . $s['titulo'] . "*" . cbAno($s) . "\n";
    if (!empty($s['generos'])) $txt .= "_" . $s['generos'] . "_\n";
    $txt .= "\nIMDb *" . cbNota($s['nota_imdb']) . "*"
          . "  •  FBA *" . cbNota($s['nota_fba']) . "*"
          . " _(" . (int)$s['votos_fba'] . " nota" . ((int)$s['votos_fba'] === 1 ? '' : 's') . ")_\n";

    $quem = seriesQuemMarcou($pdo, (int)$s['id']);
    if (!$quem) {
        $txt .= "\n_Ninguém da liga marcou essa ainda._\n";
    } else {
        $txt .= "\n*O que a liga diz*\n";
        foreach (array_slice($quem, 0, CB_QUANTOS) as $q) {
            $txt .= "• " . $q['nome']
                  . ' ' . (['quero' => 'quer ver', 'assistindo' => 'está vendo',
                            'assistida' => 'assistiu'][$q['estado']] ?? '')
                  . ($q['nota'] !== null ? " — *" . (int)$q['nota'] . "*" : '') . "\n";
            if (!empty($q['comentario'])) $txt .= "   _\"" . $q['comentario'] . "\"_\n";
        }
        if (count($quem) > CB_QUANTOS) {
            $txt .= "_e mais " . (count($quem) - CB_QUANTOS) . "._\n";
        }
    }
    return rtrim($txt);
}
