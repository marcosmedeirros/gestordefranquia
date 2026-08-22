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

    // Marcação depois do escape: os asteriscos e underscores do editor
    // sobrevivem ao htmlspecialchars, e o que eles viram é tag nossa.
    $seguro = preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/su', '<strong>$1</strong>', $seguro);
    $seguro = preg_replace('/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/su',   '<em>$1</em>',        $seguro);

    $paragrafos = preg_split('/\R{2,}/u', $seguro);
    $saida = [];
    foreach ($paragrafos as $p) {
        $p = trim($p);
        if ($p === '') continue;
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
