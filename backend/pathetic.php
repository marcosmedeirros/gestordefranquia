<?php
/**
 * THE PATHETIC — o jornal da FBA
 *
 * Antes isto era uma caixa de texto: o editor colava HTML e a página inteira
 * virava aquele HTML. Funcionava pra publicar uma matéria; não funcionava pra
 * ter um JORNAL, porque não existia "uma notícia" — existia uma página só, que
 * o próximo texto apagava.
 *
 * Aqui cada notícia é uma linha: título, grau, foto, quem assina e o texto. O
 * grau é o que decide o tamanho dela na página, e é a mesma ideia da primeira
 * página de um jornal de banca — a manchete ocupa a largura toda, os destaques
 * dividem a linha de baixo, e as notícias entram na grade.
 *
 * Este arquivo é só o modelo: schema, leitura, escrita e as regras que valem
 * nas três telas (jornal, landing e redação). Nenhuma delas repete consulta.
 */

require_once __DIR__ . '/db.php';

/** Os três graus, do mais alto pro mais baixo. A ordem É a hierarquia. */
const PATHETIC_GRAUS = ['manchete', 'destaque', 'noticia'];

/** Como cada grau se chama na tela e o que ele significa pra quem publica. */
const PATHETIC_GRAU_INFO = [
    'manchete' => [
        'rotulo' => 'Manchete',
        'nota'   => 'Abre o jornal, largura toda, foto grande. Só a mais recente fica no topo.',
        'cor'    => '#fc0025',
    ],
    'destaque' => [
        'rotulo' => 'Destaque',
        'nota'   => 'Meia largura, logo abaixo da manchete. Cabem duas lado a lado.',
        'cor'    => '#f59e0b',
    ],
    'noticia'  => [
        'rotulo' => 'Notícia',
        'nota'   => 'Entra na grade, card normal.',
        'cor'    => '#64748b',
    ],
];

/**
 * Os graus que o bot anuncia no grupo.
 *
 * Notícia comum não avisa: o grupo viraria um feed, e feed a pessoa silencia.
 * O que sobe pro WhatsApp é o que faria alguém parar o que está fazendo.
 */
const PATHETIC_GRAUS_QUE_AVISAM = ['manchete', 'destaque'];

/** Onde a foto enviada é guardada, relativo à raiz do site. */
const PATHETIC_DIR_FOTOS = 'uploads/pathetic';

/** O que o upload aceita. A chave é o MIME de verdade, lido do arquivo. */
const PATHETIC_MIMES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

/** Teto do upload, em bytes. Foto de jornal não precisa de mais que isso. */
const PATHETIC_MAX_FOTO = 6 * 1024 * 1024;

/**
 * Cria a tabela se ainda não existe, e migra a que já existe.
 *
 * Roda uma vez por requisição (o static). O ALTER de coluna vem do mesmo
 * padrão do resto do projeto: instalação antiga não tem as colunas novas, e
 * CREATE TABLE IF NOT EXISTS não as acrescenta.
 */
function patheticGarantirTabela(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pathetic_noticias (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            titulo       VARCHAR(180) NOT NULL,
            chapeu       VARCHAR(60)  NULL,
            grau         VARCHAR(12)  NOT NULL DEFAULT 'noticia',
            resumo       VARCHAR(400) NULL,
            texto        MEDIUMTEXT   NULL,
            foto         VARCHAR(500) NULL,
            foto_credito VARCHAR(120) NULL,
            autor_id     INT          NULL,
            autor_nome   VARCHAR(80)  NOT NULL,
            publicada    TINYINT(1)   NOT NULL DEFAULT 0,
            publicada_em DATETIME     NULL,
            avisou_whats TINYINT(1)   NOT NULL DEFAULT 0,
            criada_em    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            editada_em   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_pathetic_capa (publicada, publicada_em),
            KEY idx_pathetic_grau (grau)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('[pathetic] criar tabela: ' . $e->getMessage());
    }

    // Migração de coluna, uma a uma: banco antigo não tem as que nasceram
    // depois, e um ALTER com todas falha inteiro se UMA já existir.
    $colunas = [
        'chapeu'       => "ALTER TABLE pathetic_noticias ADD COLUMN chapeu VARCHAR(60) NULL AFTER titulo",
        'foto_credito' => "ALTER TABLE pathetic_noticias ADD COLUMN foto_credito VARCHAR(120) NULL AFTER foto",
        'avisou_whats' => "ALTER TABLE pathetic_noticias ADD COLUMN avisou_whats TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($colunas as $col => $sql) {
        try {
            $tem = $pdo->query("SHOW COLUMNS FROM pathetic_noticias LIKE " . $pdo->quote($col))->fetch();
            if (!$tem) $pdo->exec($sql);
        } catch (Throwable $e) {
            error_log('[pathetic] migrar ' . $col . ': ' . $e->getMessage());
        }
    }
}

/** O grau chega do formulário; só os três do catálogo entram. */
function patheticGrauValido(?string $g): string
{
    $g = strtolower(trim((string)$g));
    return in_array($g, PATHETIC_GRAUS, true) ? $g : 'noticia';
}

/**
 * As notícias publicadas, da mais nova pra mais velha.
 *
 * Ordena por publicada_em e não por id: uma matéria pode ser escrita hoje e
 * publicada amanhã, e é a data de publicação que o leitor vê. O COALESCE
 * segura a linha antiga que ainda não tem a data preenchida.
 */
function patheticPublicadas(PDO $pdo, int $limite = 60): array
{
    patheticGarantirTabela($pdo);
    try {
        $st = $pdo->prepare("SELECT * FROM pathetic_noticias
                             WHERE publicada = 1
                             ORDER BY COALESCE(publicada_em, criada_em) DESC, id DESC
                             LIMIT " . max(1, min(200, $limite)));
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[pathetic] listar: ' . $e->getMessage());
        return [];
    }
}

/**
 * Procura no jornal: título, chapéu, linha fina e o TEXTO da matéria.
 *
 * O texto entra na busca de propósito — quem procura "cláusula" quer a
 * matéria em que a palavra apareceu, e ela quase nunca está no título. Ordena
 * dando peso a onde a palavra caiu: título vale mais que corpo, porque uma
 * matéria SOBRE a coisa é melhor resposta que uma que a menciona de passagem.
 */
function patheticBuscar(PDO $pdo, string $termo, int $limite = 60): array
{
    patheticGarantirTabela($pdo);
    $termo = trim($termo);
    if ($termo === '') return patheticPublicadas($pdo, $limite);

    // LIKE com os curingas escapados: um "%" digitado na caixa procura o
    // caractere "%", e não "qualquer coisa".
    $alvo = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $termo) . '%';

    try {
        $st = $pdo->prepare("SELECT * FROM pathetic_noticias
                             WHERE publicada = 1
                               AND (titulo LIKE :a OR chapeu LIKE :a OR resumo LIKE :a OR texto LIKE :a)
                             ORDER BY
                               (titulo LIKE :a) DESC,
                               (chapeu LIKE :a OR resumo LIKE :a) DESC,
                               COALESCE(publicada_em, criada_em) DESC, id DESC
                             LIMIT " . max(1, min(200, $limite)));
        $st->execute([':a' => $alvo]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[pathetic] buscar: ' . $e->getMessage());
        return [];
    }
}

/** Uma notícia pelo id. Só devolve não-publicada pra quem edita. */
function patheticUma(PDO $pdo, int $id, bool $incluirRascunho = false): ?array
{
    patheticGarantirTabela($pdo);
    try {
        $sql = "SELECT * FROM pathetic_noticias WHERE id = ?";
        if (!$incluirRascunho) $sql .= " AND publicada = 1";
        $st = $pdo->prepare($sql . " LIMIT 1");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('[pathetic] uma: ' . $e->getMessage());
        return null;
    }
}

/**
 * Separa a lista publicada nos três andares da primeira página.
 *
 * Só UMA manchete fica em cima — a mais recente. As outras manchetes descem
 * pra faixa de destaque em vez de sumirem: elas foram manchete no dia delas, e
 * empilhar cinco manchetes de largura total transforma a capa numa fila.
 */
function patheticCapa(array $noticias): array
{
    $manchete = null;
    $destaques = [];
    $resto = [];

    foreach ($noticias as $n) {
        $g = $n['grau'] ?? 'noticia';
        if ($g === 'manchete' && $manchete === null) { $manchete = $n; continue; }
        if ($g === 'manchete' || $g === 'destaque')  { $destaques[] = $n; continue; }
        $resto[] = $n;
    }

    return ['manchete' => $manchete, 'destaques' => $destaques, 'noticias' => $resto];
}

/**
 * Salva a foto que veio do formulário e devolve o caminho pra gravar.
 *
 * Devolve [caminho, erro]. O caminho é relativo à raiz do site porque é assim
 * que ele vai pro src da <img> — guardar o caminho absoluto do disco quebraria
 * no dia em que o site mudar de pasta.
 *
 * O MIME sai do CONTEÚDO do arquivo (finfo), nunca do que o navegador diz: o
 * campo type do $_FILES é escrito pelo cliente e um .php renomeado pra .jpg
 * chega com type image/jpeg se quem enviou quiser.
 */
function patheticSalvarFoto(array $arquivo, int $idNoticia): array
{
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];

    if (($arquivo['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $motivo = [
            UPLOAD_ERR_INI_SIZE   => 'A foto passou do limite do servidor.',
            UPLOAD_ERR_FORM_SIZE  => 'A foto passou do limite do formulário.',
            UPLOAD_ERR_PARTIAL    => 'O envio da foto foi interrompido.',
            UPLOAD_ERR_NO_TMP_DIR => 'O servidor está sem pasta temporária.',
            UPLOAD_ERR_CANT_WRITE => 'O servidor não conseguiu gravar a foto.',
        ][$arquivo['error']] ?? 'Falha ao enviar a foto.';
        return [null, $motivo];
    }

    $tmp = (string)($arquivo['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return [null, 'Arquivo inválido.'];

    $tamanho = (int)($arquivo['size'] ?? 0);
    if ($tamanho <= 0)                    return [null, 'A foto chegou vazia.'];
    if ($tamanho > PATHETIC_MAX_FOTO)     return [null, 'A foto passa de ' . (PATHETIC_MAX_FOTO / 1024 / 1024) . ' MB.'];

    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = (string)finfo_file($fi, $tmp); finfo_close($fi); }
    }
    // Segunda opinião, e não a primeira: getimagesize também lê o conteúdo, e
    // serve de rede pra servidor sem a extensão fileinfo.
    if ($mime === '') {
        $info = @getimagesize($tmp);
        $mime = $info && !empty($info['mime']) ? (string)$info['mime'] : '';
    }
    if (!isset(PATHETIC_MIMES[$mime])) return [null, 'Formato não aceito. Use JPG, PNG, WEBP ou GIF.'];

    $raiz = dirname(__DIR__);
    $dir  = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, PATHETIC_DIR_FOTOS);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return [null, 'Não foi possível criar a pasta das fotos.'];
    }

    // O nome sai do id da notícia mais um sufixo aleatório: o id evita duas
    // notícias brigarem pelo mesmo arquivo, e o sufixo evita que o navegador
    // mostre a foto ANTIGA em cache quando a foto é trocada.
    $ext  = PATHETIC_MIMES[$mime];
    $nome = $idNoticia . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $alvo = $dir . DIRECTORY_SEPARATOR . $nome;

    if (!move_uploaded_file($tmp, $alvo)) return [null, 'Não foi possível salvar a foto.'];
    @chmod($alvo, 0644);

    return [PATHETIC_DIR_FOTOS . '/' . $nome, null];
}

/**
 * Apaga a foto de uma notícia do disco.
 *
 * Só toca no que está DENTRO da pasta de fotos: se o campo guarda uma URL de
 * fora (o editor pode colar uma), não há nada nosso pra apagar; e o realpath
 * fecha a porta pra um caminho com ../ chegar aqui e apagar outra coisa.
 */
function patheticApagarFoto(?string $caminho): void
{
    $caminho = trim((string)$caminho);
    if ($caminho === '' || preg_match('#^https?://#i', $caminho)) return;

    $raiz = dirname(__DIR__);
    $dir  = realpath($raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, PATHETIC_DIR_FOTOS));
    $alvo = realpath($raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $caminho));
    if (!$dir || !$alvo) return;
    if (strpos($alvo, $dir . DIRECTORY_SEPARATOR) !== 0) return;

    @unlink($alvo);
}

/** O src pronto pra <img>: URL de fora passa direto, caminho nosso ganha a barra. */
function patheticSrcFoto(?string $foto): string
{
    $foto = trim((string)$foto);
    if ($foto === '') return '';
    if (preg_match('#^(https?:)?//#i', $foto)) return $foto;
    return '/' . ltrim($foto, '/');
}

/**
 * "há 3 min", "ontem", "12 de março".
 *
 * Jornal não mostra timestamp: mostra o quanto a notícia é fresca, porque é
 * isso que decide se você para pra ler.
 */
function patheticQuando(?string $data): string
{
    if (!$data) return '';
    $t = strtotime($data);
    if (!$t) return '';

    $seg = time() - $t;
    if ($seg < 0)      return 'agora';
    if ($seg < 60)     return 'agora';
    if ($seg < 3600)   { $m = (int)floor($seg / 60);   return 'há ' . $m . ' min'; }
    if ($seg < 86400)  { $h = (int)floor($seg / 3600); return 'há ' . $h . ($h === 1 ? ' hora' : ' horas'); }
    if ($seg < 172800) return 'ontem';
    if ($seg < 604800) { $d = (int)floor($seg / 86400); return 'há ' . $d . ' dias'; }

    $meses = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    return (int)date('j', $t) . ' de ' . $meses[(int)date('n', $t)]
         . (date('Y', $t) !== date('Y') ? ' de ' . date('Y', $t) : '');
}

/**
 * O texto da notícia, limpo, pronto pra sair na página.
 *
 * O editor escreve em texto puro, com linha em branco separando parágrafo — é
 * o que qualquer pessoa faz sem pensar. Aceitar HTML aqui seria abrir XSS pra
 * quem edita o jornal contra quem lê, e o ganho seria poder pôr negrito.
 *
 * O que ele ganha em troca: *negrito* e _itálico_, marcados como no WhatsApp,
 * que é onde essa gente escreve o dia inteiro.
 */
function patheticTextoHtml(?string $texto): string
{
    $texto = trim((string)$texto);
    if ($texto === '') return '';

    $seguro = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');

    // CORTA EM PARÁGRAFOS PRIMEIRO, marca depois.
    //
    // Era o contrário, e o /s fazia o ponto casar com quebra de linha: um
    // asterisco solto num parágrafo se emparelhava com outro três parágrafos
    // abaixo, e o <strong> nascia atravessando os </p><p> do meio — HTML com
    // tags cruzadas, que cada navegador conserta do seu jeito.
    //
    // Marcando DENTRO de cada parágrafo, o /s some junto: negrito não
    // atravessa linha em branco porque linha em branco é onde o parágrafo
    // acaba.
    $paragrafos = preg_split('/\R{2,}/u', $seguro);
    $saida = [];
    foreach ($paragrafos as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $p = preg_replace('/(?<![\w*])\*(?=\S)([^*\r\n]+?)(?<=\S)\*(?![\w*])/u', '<strong>$1</strong>', $p);
        $p = preg_replace('/(?<![\w_])_(?=\S)([^_\r\n]+?)(?<=\S)_(?![\w_])/u',    '<em>$1</em>',        $p);
        $saida[] = '<p>' . nl2br($p, false) . '</p>';
    }
    return implode("\n", $saida);
}

/**
 * O resumo que aparece no card, feito do texto quando não há um escrito.
 *
 * Corta na palavra, nunca no meio dela: "o técnico admiti…" é pior que uma
 * frase mais curta que termina inteira.
 */
function patheticResumo(array $n, int $max = 180): string
{
    $r = trim((string)($n['resumo'] ?? ''));
    if ($r === '') {
        $r = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($n['texto'] ?? ''))));
    }
    if ($r === '') return '';
    if (mb_strlen($r) <= $max) return $r;
    $corte = mb_substr($r, 0, $max - 1);
    $espaco = mb_strrpos($corte, ' ');
    if ($espaco !== false && $espaco > $max * 0.6) $corte = mb_substr($corte, 0, $espaco);
    return rtrim($corte, " ,.;:—-") . '…';
}

/** Quantos minutos de leitura. 200 palavras por minuto, o padrão do ramo. */
function patheticMinutos(?string $texto): int
{
    $palavras = str_word_count(strip_tags((string)$texto), 0, 'áàâãéêíóôõúüçÁÀÂÃÉÊÍÓÔÕÚÜÇ');
    return max(1, (int)round($palavras / 200));
}

// ═══════════════════════════════════════════════════════════════════════
// CURTIDA E COMENTÁRIO
//
// Só quem está logado curte ou comenta. As duas páginas do jornal são
// abertas — /thepathetic.php e /site/pathetic.php —, e comentário anônimo
// numa liga de quarenta pessoas não é participação, é porta pra spam. Quem
// não está logado vê tudo e vê o convite pra entrar.
//
// A curtida é uma linha por pessoa por notícia, com a chave primária fazendo
// o trabalho: clicar de novo apaga, e não existe "curtir duas vezes".
// ═══════════════════════════════════════════════════════════════════════

/** O comentário mais longo que cabe. Acima disso é texto, não comentário. */
const PATHETIC_MAX_COMENTARIO = 1200;

/** Quantos comentários uma pessoa pode deixar por hora, no jornal inteiro. */
const PATHETIC_COMENTARIOS_POR_HORA = 15;

function patheticGarantirSocial(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;

    try {
        // A chave primária composta É a regra de negócio: uma curtida por
        // pessoa por notícia, sem precisar de checagem antes de inserir.
        $pdo->exec("CREATE TABLE IF NOT EXISTS pathetic_curtidas (
            noticia_id INT NOT NULL,
            id_usuario INT NOT NULL,
            criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (noticia_id, id_usuario),
            KEY idx_curtida_noticia (noticia_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS pathetic_comentarios (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            noticia_id INT NOT NULL,
            id_usuario INT NOT NULL,
            autor_nome VARCHAR(80) NOT NULL,
            texto      VARCHAR(1200) NOT NULL,
            criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_coment_noticia (noticia_id, criado_em),
            KEY idx_coment_autor (id_usuario, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('[pathetic] tabelas sociais: ' . $e->getMessage());
    }
}

/**
 * Curtidas e comentários de uma lista de notícias, de uma vez.
 *
 * Uma consulta pra todas em vez de duas por card: a capa mostra até vinte
 * notícias, e a versão ingênua fazia quarenta idas ao banco pra desenhar uma
 * página que já estava pronta.
 */
function patheticContagens(PDO $pdo, array $ids, int $idUsuario = 0): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    patheticGarantirSocial($pdo);

    $vagas = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    foreach ($ids as $id) $out[$id] = ['curtidas' => 0, 'comentarios' => 0, 'euCurti' => false];

    try {
        $st = $pdo->prepare("SELECT noticia_id, COUNT(*) n FROM pathetic_curtidas
                             WHERE noticia_id IN ({$vagas}) GROUP BY noticia_id");
        $st->execute($ids);
        foreach ($st as $r) $out[(int)$r['noticia_id']]['curtidas'] = (int)$r['n'];

        $st = $pdo->prepare("SELECT noticia_id, COUNT(*) n FROM pathetic_comentarios
                             WHERE noticia_id IN ({$vagas}) GROUP BY noticia_id");
        $st->execute($ids);
        foreach ($st as $r) $out[(int)$r['noticia_id']]['comentarios'] = (int)$r['n'];

        if ($idUsuario > 0) {
            $st = $pdo->prepare("SELECT noticia_id FROM pathetic_curtidas
                                 WHERE id_usuario = ? AND noticia_id IN ({$vagas})");
            $st->execute(array_merge([$idUsuario], $ids));
            foreach ($st as $r) $out[(int)$r['noticia_id']]['euCurti'] = true;
        }
    } catch (Throwable $e) {
        error_log('[pathetic] contagens: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Curte ou descurte. O mesmo clique faz as duas coisas.
 *
 * Devolve o estado depois: ['curtiu' => bool, 'total' => int].
 */
function patheticAlternarCurtida(PDO $pdo, int $noticiaId, int $idUsuario): array
{
    patheticGarantirSocial($pdo);
    $curtiu = false;
    try {
        // DELETE primeiro: se apagou, era pra descurtir e acabou. Se não
        // apagou nada, não havia curtida e o INSERT é o que a pessoa queria.
        // Duas consultas, nenhuma corrida — a chave primária arbitra.
        $del = $pdo->prepare("DELETE FROM pathetic_curtidas WHERE noticia_id = ? AND id_usuario = ?");
        $del->execute([$noticiaId, $idUsuario]);
        if ($del->rowCount() === 0) {
            $pdo->prepare("INSERT IGNORE INTO pathetic_curtidas (noticia_id, id_usuario) VALUES (?,?)")
                ->execute([$noticiaId, $idUsuario]);
            $curtiu = true;
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM pathetic_curtidas WHERE noticia_id = ?");
        $st->execute([$noticiaId]);
        return ['curtiu' => $curtiu, 'total' => (int)$st->fetchColumn()];
    } catch (Throwable $e) {
        error_log('[pathetic] curtir: ' . $e->getMessage());
        return ['curtiu' => false, 'total' => 0, 'erro' => true];
    }
}

/** Os comentários de uma notícia, do mais antigo pro mais novo. */
function patheticComentarios(PDO $pdo, int $noticiaId): array
{
    patheticGarantirSocial($pdo);
    try {
        // Do mais ANTIGO pro mais novo: comentário é conversa, e conversa se
        // lê na ordem em que aconteceu.
        $st = $pdo->prepare("SELECT * FROM pathetic_comentarios WHERE noticia_id = ?
                             ORDER BY criado_em ASC, id ASC LIMIT 300");
        $st->execute([$noticiaId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[pathetic] comentarios: ' . $e->getMessage());
        return [];
    }
}

/**
 * Grava um comentário. Devolve o erro pra tela, ou null se deu certo.
 *
 * O texto é guardado CRU e escapado na hora de mostrar — guardar escapado
 * quebra a contagem de caracteres e vira & a m p ; na segunda edição.
 */
function patheticComentar(PDO $pdo, int $noticiaId, int $idUsuario, string $nome, string $texto): ?string
{
    patheticGarantirSocial($pdo);

    $texto = trim(preg_replace('/\R{3,}/u', "\n\n", $texto));
    if ($texto === '') return 'Escreva alguma coisa antes de enviar.';
    if (mb_strlen($texto) > PATHETIC_MAX_COMENTARIO)
        return 'O comentário passa de ' . PATHETIC_MAX_COMENTARIO . ' caracteres.';

    // A notícia tem que existir E estar publicada: comentar num rascunho
    // seria comentar no que ninguém pode ler.
    if (!patheticUma($pdo, $noticiaId)) return 'Essa notícia não está no ar.';

    try {
        // Freio de ritmo, o mesmo espírito do auditor das carreiras: quinze
        // comentários por hora é muito mais do que uma pessoa escreve e
        // pouco o bastante pra um script não encher o jornal.
        $st = $pdo->prepare("SELECT COUNT(*) FROM pathetic_comentarios
                             WHERE id_usuario = ? AND criado_em > (NOW() - INTERVAL 1 HOUR)");
        $st->execute([$idUsuario]);
        if ((int)$st->fetchColumn() >= PATHETIC_COMENTARIOS_POR_HORA)
            return 'Você comentou bastante na última hora. Volte daqui a pouco.';

        // Mesmo texto, mesma notícia, mesma pessoa, nos últimos dois minutos:
        // é duplo clique ou F5 na tela de sucesso, não um segundo comentário.
        $st = $pdo->prepare("SELECT COUNT(*) FROM pathetic_comentarios
                             WHERE noticia_id = ? AND id_usuario = ? AND texto = ?
                               AND criado_em > (NOW() - INTERVAL 2 MINUTE)");
        $st->execute([$noticiaId, $idUsuario, $texto]);
        if ((int)$st->fetchColumn() > 0) return null;

        $pdo->prepare("INSERT INTO pathetic_comentarios (noticia_id, id_usuario, autor_nome, texto)
                       VALUES (?,?,?,?)")
            ->execute([$noticiaId, $idUsuario, mb_substr(trim($nome) ?: 'Anônimo', 0, 80), $texto]);
        return null;
    } catch (Throwable $e) {
        error_log('[pathetic] comentar: ' . $e->getMessage());
        return 'Não foi possível enviar o comentário.';
    }
}

/**
 * Apaga um comentário.
 *
 * Quem apaga: o dono do comentário, ou quem edita o jornal. O dono porque
 * escreveu e se arrependeu; o editor porque é dele a responsabilidade sobre o
 * que aparece na página.
 */
function patheticApagarComentario(PDO $pdo, int $comentarioId, int $idUsuario, bool $ehEditor): bool
{
    patheticGarantirSocial($pdo);
    try {
        if ($ehEditor) {
            $st = $pdo->prepare("DELETE FROM pathetic_comentarios WHERE id = ?");
            $st->execute([$comentarioId]);
        } else {
            $st = $pdo->prepare("DELETE FROM pathetic_comentarios WHERE id = ? AND id_usuario = ?");
            $st->execute([$comentarioId, $idUsuario]);
        }
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('[pathetic] apagar comentario: ' . $e->getMessage());
        return false;
    }
}

/** Os comentários mais recentes do jornal inteiro — a fila da moderação. */
function patheticComentariosRecentes(PDO $pdo, int $limite = 60): array
{
    patheticGarantirSocial($pdo);
    try {
        $st = $pdo->prepare("SELECT c.*, n.titulo, n.publicada
                             FROM pathetic_comentarios c
                             LEFT JOIN pathetic_noticias n ON n.id = c.noticia_id
                             ORDER BY c.criado_em DESC, c.id DESC
                             LIMIT " . max(1, min(200, $limite)));
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[pathetic] comentarios recentes: ' . $e->getMessage());
        return [];
    }
}

/**
 * O que dizer pro editor sobre o aviso no grupo.
 *
 * patheticAvisarGrupo devolve quatro coisas e a tela tratava duas. Publicar
 * com o WhatsApp desligado mostrava só "Notícia publicada." — o editor
 * concluía que o grupo tinha sido avisado, e não tinha. Pior: não há cron de
 * retry, então o aviso simplesmente não acontecia nunca.
 *
 * Recebe $tipo por referência pra pintar o aviso de amarelo quando o envio
 * não saiu: verde dizendo "publicada" ao lado de um envio que falhou é a
 * mesma mentira de antes, só que colorida.
 */
function patheticTextoDoAviso(?string $r, string &$tipo): string
{
    if ($r === 'ok')     return ' O grupo foi avisado.';
    if ($r === null)     return '';   // grau que não avisa: nada a dizer
    $tipo = 'warning';
    if ($r === 'desligado') return ' O grupo NÃO foi avisado: o WhatsApp está desligado. Ligue e use "avisar o grupo" na lista.';
    if ($r === 'sem-grupo') return ' O grupo NÃO foi avisado: não há grupo principal configurado.';
    return ' O aviso no grupo falhou — veja o log.';
}

/**
 * Avisa o grupo que saiu notícia de grau alto.
 *
 * Uma vez por notícia, e é a coluna avisou_whats que garante isso — editar a
 * matéria depois de publicada não manda de novo. Republicar uma notícia
 * despublicada também não: o grupo já foi avisado uma vez, e a segunda seria
 * ruído sobre a mesma coisa.
 *
 * Nunca deixa uma falha do WhatsApp derrubar a publicação: publicar é o que a
 * pessoa pediu; avisar é consequência. Devolve o que aconteceu, pra tela poder
 * contar.
 */
function patheticAvisarGrupo(PDO $pdo, array $noticia): ?string
{
    if (!in_array($noticia['grau'] ?? '', PATHETIC_GRAUS_QUE_AVISAM, true)) return null;
    if (!empty($noticia['avisou_whats'])) return null;
    if (empty($noticia['publicada'])) return null;

    try {
        require_once __DIR__ . '/whatsapp.php';
        if (!function_exists('whatsappParaGrupoPrincipal')) return null;

        // As MESMAS duas condições que whatsappParaGrupoPrincipal checa antes
        // de enfileirar. Ela não devolve nada, então daqui não havia como
        // saber que a mensagem não saiu — e o resultado era o pior dos dois
        // mundos: WhatsApp desligado na hora de publicar, a notícia marcada
        // como avisada, e o grupo nunca sabendo dela nem depois que voltasse.
        // Sem marcar, o próximo save tenta de novo.
        if (!whatsappAtivo($pdo)) return 'desligado';
        $grupo = trim((string)($pdo->query("SELECT grupo_principal FROM whatsapp_config WHERE id = 1")->fetchColumn() ?: ''));
        if ($grupo === '') return 'sem-grupo';

        $rotulo = strtoupper(PATHETIC_GRAU_INFO[$noticia['grau']]['rotulo'] ?? 'NOTÍCIA');
        $linhas = ['[THE PATHETIC] 📰 *' . $rotulo . '*', ''];

        $chapeu = trim((string)($noticia['chapeu'] ?? ''));
        if ($chapeu !== '') $linhas[] = strtoupper($chapeu);

        $linhas[] = '*' . trim((string)$noticia['titulo']) . '*';

        $resumo = patheticResumo($noticia, 200);
        if ($resumo !== '') { $linhas[] = ''; $linhas[] = $resumo; }

        $linhas[] = '';
        $linhas[] = 'Por ' . trim((string)($noticia['autor_nome'] ?? 'Redação'));
        $linhas[] = 'https://fbabrasil.com.br/thepathetic.php?n=' . (int)$noticia['id'];

        whatsappParaGrupoPrincipal($pdo, implode("\n", $linhas), 'pathetic');

        $pdo->prepare("UPDATE pathetic_noticias SET avisou_whats = 1 WHERE id = ?")
            ->execute([(int)$noticia['id']]);

        return 'ok';
    } catch (Throwable $e) {
        error_log('[pathetic] avisar grupo: ' . $e->getMessage());
        return 'falhou';
    }
}
