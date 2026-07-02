<?php
/**
 * PlayerFace — atribuição determinística de faces genéricas.
 *
 * Cada jogador sem foto real recebe uma face da pasta faces/
 * A escolha é feita por:
 *   1. Categoria de etnia inferida pelo nome/sobrenome
 *   2. Índice numérico determinístico (player_id * 31 + 7) % pool_size + 1
 *      → Mesmo jogador sempre recebe a mesma face, sem randomness.
 *
 * URL do endpoint: /face.php?id=42&name=Stephen+Curry&pos=PG
 */
class PlayerFace
{
    /**
     * Tamanho de cada pool de faces (pasta faces/).
     * Atualizar se adicionar mais imagens.
     */
    const POOLS = [
        'African'          => 721,
        'Asian'            => 434,
        'Caucasian'        => 945,
        'Central European' => 896,
        'EECA'             => 511,
        'ItalMed'          => 203,
        'MENA'             => 588,
        'MESA'             => 574,
        'SAMed'            => 490,
        'Scandinavian'     => 553,
        'Seasian'          => 217,
        'South American'   => 343,
        'SpanMed'          => 469,
        'YugoGreek'        => 350,
    ];

    /**
     * Retorna o nome do arquivo (ex: "African42.png").
     */
    public static function filename(int $playerId, string $name, string $pos = ''): string
    {
        $cat  = self::category($name, $pos);
        $pool = self::POOLS[$cat];
        $num  = ($playerId * 31 + 7) % $pool + 1;
        return $cat . $num . '.png';
    }

    /**
     * Retorna a URL relativa para o endpoint de faces.
     * Uso: <img src="<?= PlayerFace::url($p['id'], $p['name'], $p['pos']) ?>">
     */
    public static function url(int $playerId, string $name, string $pos = ''): string
    {
        $base = defined('APP_BASE') ? APP_BASE : '';
        return $base . '/face.php?id=' . $playerId
            . '&name=' . rawurlencode($name)
            . '&pos='  . rawurlencode($pos);
    }

    /**
     * Retorna a URL apenas com o nome do arquivo diretamente (para img src
     * quando as faces estiverem dentro do webroot, ex: /faces/African42.png).
     * Aqui usamos o proxy /face.php para servir de fora do webroot.
     */
    public static function urlDirect(int $playerId, string $name, string $pos = ''): string
    {
        $base = defined('APP_BASE') ? APP_BASE : '';
        return $base . '/face.php?f=' . rawurlencode(self::filename($playerId, $name, $pos));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Detecção de categoria por sobrenome
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Infere a categoria de face a partir do nome completo.
     * Prioridade:
     *  1. Sobrenomes específicos de jogadores internacionais conhecidos
     *  2. Padrões de sufixo de sobrenome (heurística)
     *  3. Default → African (NBA é ~74% afro-americano)
     */
    public static function category(string $name, string $pos = ''): string
    {
        // Normalizar: trabalhar com sobrenome (última palavra)
        $parts    = preg_split('/\s+/', trim($name));
        $last     = strtolower(end($parts));
        $first    = strtolower($parts[0] ?? '');
        $fullLow  = strtolower($name);

        // ── 1. Jogadores internacionais conhecidos (primeiro + último nome) ──
        $knownMap = self::knownPlayers();
        foreach ($knownMap as $fragment => $cat) {
            if (str_contains($fullLow, $fragment)) {
                return $cat;
            }
        }

        // ── 2. Padrões de sufixo de sobrenome ──

        // Sérvio/Croata/Esloveno/Bósnio — -ić / -ic / -ović / -ovic
        if (preg_match('/i[cć]$/', $last)) {
            return 'YugoGreek';
        }

        // Russo/Ucraniano/Búlgaro — -ov / -ev / -enko / -sky / -ski / -uk
        if (preg_match('/(ov|ev|enko|sky|ski|chuk|yuk|iak|nko)$/', $last)) {
            return 'EECA';
        }

        // Lituano/Letão/Estônio — -as / -is / -us (terminações bálticas)
        if (preg_match('/(?:auskas|aitis|elis|ulis|onis|upis)$/', $last)) {
            return 'EECA';
        }

        // Alemão/Austríaco/Polonês/Checo — -mann / -berg / -stein / -ski / -ber
        if (preg_match('/(mann|berg|stein|burg|bach|wald|dorfer|hofer|maier|meier|meyer|mueller|müller|nowak|kowalski|wiśniewski|wozniak|jan[ks])$/', $last)) {
            return 'Central European';
        }

        // Francês — -eau / -ier / -ais / -et / -el
        if (preg_match('/(eau|ieux|ais|ier|iet|ail|ois|nard|bert|mont)$/', $last)
            && preg_match('/^(de|du|le|la|les|jean|pierre|marc|luc|paul|nicolas|antoine|baptiste|francois|émil|remi)/', $first)) {
            return 'SpanMed';
        }

        // Espanhol/Português (Brasil) — sobrenomes comuns
        if (preg_match('/(ão|ães|nha|lho|lhe|ndo|dro|rco|illo|illo|oza|uez|rez|ios|ero|ado|ado|elo|ela|eia|inha|arro|irra|erra|enho|inho|ona|ana|ita|ita|mbo|ngo)$/', $last)
            || preg_match('/^(da|de|dos|das|do|el|la|los|las|del|san|santo|santa|van|von|jr|sr)$/', $last)) {
            // se o sobrenome anterior também for latino, provavelmente é latino
            // mas apenas o sufixo não basta — verifica também o primeiro nome
            if (preg_match('/^(carlos|pablo|marco|rafael|gabriel|pedro|lucas|juan|jose|luis|miguel|diego|alvaro|andres|alejandro|sebastian|victor|hector|emilio|rodrigo|leandro|thiago|neymar|guga|reinaldo|nicolas)/', $first)) {
                return 'South American';
            }
        }

        // Sufixo espanhol claro
        if (preg_match('/(uez|rez|illo|arro|oya|aza|iez|osa|ega|era|eza|oja|aja|aza|encia|anda|unda|enta|inta|unta|osta|aste|este|uste|iste)$/', $last)) {
            return 'SpanMed';
        }

        // Grego — -opoulos / -akis / -adis / -ellis / -oglu
        if (preg_match('/(opoulos|akis|adis|ellis|oglu|idis|alis|antis|oulos|elos)$/', $last)) {
            return 'YugoGreek';
        }

        // Turco — -oglu / -oğlu / -ay / -er (combinado com nome turco)
        if (preg_match('/(oglu|oğlu|çelik|demir|yilmaz|kaya|kurt)/', $fullLow)) {
            return 'MENA';
        }

        // Italiano — -elli / -etti / -ini / -ino / -ano / -ato / -enzo / -ari
        if (preg_match('/(elli|etti|otti|ini|ino|ino|ano|ato|enzo|oni|ari|eri|ori|uri|aci|ucci|acci|occhi|occhio)$/', $last)) {
            return 'ItalMed';
        }

        // Árabe/Norte-africano — -al / -el- / Al- / -awi / -awi / -oud
        if (preg_match('/^al[-_]/', $last) || preg_match('/(awi|oud|ouri|iani|iani|ahli|aziz|allah|uddin|abad)$/', $last)) {
            return 'MENA';
        }

        // Nigeriano/Ghanense/Africano-anglicado — sufixos comuns
        if (preg_match('/(ola|wale|seun|tunde|dipo|bayo|kunle|kemi|dele|jide|femi|sola|rotimi|lanre|emeka|chuk|nna|ike|eze|obi|chi|onye|uko|eke|achebe|nze|ndi|ndu|oka|onu|ihe|ibe|ude|uga|ama|amara|anu|andu)$/', $last)) {
            return 'African';
        }

        // Escandinavian — -sen / -sson / -son / -strom / -berg /  -dal / -gren
        // (Berg e -son são comuns em toda Europa; só conta se tiver nome escandinavo)
        if (preg_match('/(ssen|sson|dahl|strom|ström|gren|lund|borg|kvist|qvist|hjälm|hjelm|skov|gaard|gård)$/', $last)) {
            return 'Scandinavian';
        }
        if (preg_match('/(sen|son)$/', $last)
            && preg_match('/^(lars|erik|ole|anders|niels|soren|bjorn|jan|kristian|thor|leif|svend|claus|jens|henrik|mikkel|rasmus|mads|filip|tobias|mathias|joachim|adam|kasper|christian|simon|jakob|lukas|viktor)/', $first)) {
            return 'Scandinavian';
        }

        // Leste/Sudeste Asiático — sobrenomes comuns (Yao, Lin, Wang, Guo, Yi, etc.)
        $asianLast = ['yao','lin','wang','guo','yi','sun','zhao','liu','chen','zhang','wu','wei','han','zhou','hu','xiao','yang','li','lu','zhu','deng','tang','pan','he','ma','xu','luo','ding','gao','liang','ye','fang','shao','peng','cui','song','xie','cheng','fan','cai','zhong','jiang','lü','fu','tian','bai','cao','shi','jin','yu','tao','tian','shen','gu','qian','wei','wen','meng','qin','yin','jia','lu','bei','xiong','zheng','kong','lian','tong','gang','qiang','xin','feng','da'];
        if (in_array($last, $asianLast)) {
            return 'Asian';
        }

        // Sul-Asiático / indiano — - Singh / -Sharma / -Kumar / -Patel / -Mehta
        if (preg_match('/(singh|sharma|kumar|patel|mehta|gupta|joshi|nair|rao|reddy|pillai|iyer|bose|sen|das|datta|mukherjee|chatterjee|banerjee|ghosh|mitra|bhat|roy|saha|shukla|mishra|pandey|trivedi|verma|tiwari|saxena|srivastava)$/', $last)) {
            return 'MESA';
        }

        // Sudeste Asiático — Filipinas, Indonésia, Malaisia
        $seasianLast = ['reyes','santos','garcia','dela','de','dela','bautista','villanueva','castro','mendoza','ramos','salazar','torres','diaz','cruz','aguilar','domingo','aquino','villareal','francisco','hernandez'];
        // só sudeste asiático se o primeiro nome for filipino/malaio
        if (in_array($last, $seasianLast)
            && preg_match('/^(mark|carl|jayson|kai|jun|ron|rey|joel|darius|romero|nico|julius|jericho|juancho|kiefer|terrence|kobe|jalen|brandon|rondae|jaylen)/', $first)) {
            return 'Seasian';
        }

        // ── 3. Default: African (maioria dos jogadores da NBA é afro-americano) ──
        return 'African';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Mapa de jogadores internacionais conhecidos (substring do nome em minúsculas)
    // Cobre os principais nomes internacionais que apareceriam nas draft classes
    // ─────────────────────────────────────────────────────────────────────────
    private static function knownPlayers(): array
    {
        return [
            // Europeus Ocidentais / Mediterrâneos
            'dirk nowitzki'    => 'Central European',
            'nowitzki'         => 'Central European',
            'detlef schrempf'  => 'Central European',
            'schrempf'         => 'Central European',
            'uwe blab'         => 'Central European',
            'peja stojakovic'  => 'YugoGreek',
            'stojakovic'       => 'YugoGreek',
            'vlade divac'      => 'YugoGreek',
            'divac'            => 'YugoGreek',
            'drazen petrovic'  => 'YugoGreek',
            'petrovic'         => 'YugoGreek',
            'toni kukoc'       => 'YugoGreek',
            'kukoc'            => 'YugoGreek',
            'dino radja'       => 'YugoGreek',
            'radja'            => 'YugoGreek',
            'nikola jokic'     => 'YugoGreek',
            'jokic'            => 'YugoGreek',
            'boban marjanovic' => 'YugoGreek',
            'marjanovic'       => 'YugoGreek',
            'bogdan bogdanovic' => 'YugoGreek',
            'bogdanovic'       => 'YugoGreek',
            'milos teodosic'   => 'YugoGreek',
            'teodosic'         => 'YugoGreek',
            'goran dragic'     => 'YugoGreek',
            'dragic'           => 'YugoGreek',
            'luka doncic'      => 'YugoGreek',
            'doncic'           => 'YugoGreek',
            'kristaps porzingis' => 'EECA',
            'porzingis'        => 'EECA',
            'arvydas sabonis'  => 'EECA',
            'sabonis'          => 'EECA',
            'andrei kirilenko' => 'EECA',
            'kirilenko'        => 'EECA',
            'alexander volkov' => 'EECA',
            'volkov'           => 'EECA',
            'sarunas marciulionis' => 'EECA',
            'marciulionis'     => 'EECA',
            'sharunas jasikevičius' => 'EECA',
            'jasikevic'        => 'EECA',
            'pau gasol'        => 'SpanMed',
            'marc gasol'       => 'SpanMed',
            'gasol'            => 'SpanMed',
            'ricky rubio'      => 'SpanMed',
            'rubio'            => 'SpanMed',
            'jose calderon'    => 'SpanMed',
            'calderon'         => 'SpanMed',
            'serge ibaka'      => 'African',
            'ibaka'            => 'African',
            'rajon rondo'      => 'African',
            'tony parker'      => 'African',  // pai afro-americano, criado na França
            'boris diaw'       => 'African',
            'diaw'             => 'African',
            'joakim noah'      => 'African',
            'noah'             => 'African',
            'rudy gobert'      => 'African',
            'gobert'           => 'African',
            'evan fournier'    => 'SpanMed',
            'fournier'         => 'SpanMed',
            'nicolas batum'    => 'African',
            'batum'            => 'African',
            'nando de colo'    => 'SpanMed',
            'frank ntilikina'  => 'African',
            'ntilikina'        => 'African',
            'willy hernangomez' => 'SpanMed',
            'hernangomez'      => 'SpanMed',
            'juancho hernangomez' => 'SpanMed',
            'andrea bargnani'  => 'ItalMed',
            'bargnani'         => 'ItalMed',
            'danilo gallinari' => 'ItalMed',
            'gallinari'        => 'ItalMed',
            'marco belinelli'  => 'ItalMed',
            'belinelli'        => 'ItalMed',
            'nicolo melli'     => 'ItalMed',
            'simone fontecchio' => 'ItalMed',
            'fontecchio'       => 'ItalMed',
            'jan vesely'       => 'Central European',
            'vesely'           => 'Central European',
            'tomas satoransky' => 'Central European',
            'satoransky'       => 'Central European',
            'radoslav nesterovic' => 'YugoGreek',
            'nesterovic'       => 'YugoGreek',
            'nemanja bjelica'  => 'YugoGreek',
            'bjelica'          => 'YugoGreek',
            'nikola vucevic'   => 'YugoGreek',
            'vucevic'          => 'YugoGreek',
            'nikola mirotic'   => 'YugoGreek',
            'mirotic'          => 'YugoGreek',
            'jusuf nurkic'     => 'YugoGreek',
            'nurkic'           => 'YugoGreek',
            'domantas sabonis' => 'EECA',
            // Escandinavos / Bálticos
            'jonas valanciunas' => 'EECA',
            'valanciunas'      => 'EECA',
            'zan tabak'        => 'YugoGreek',
            // Australianos (maioria caucasiana)
            'andrew bogut'     => 'Caucasian',
            'bogut'            => 'Caucasian',
            'joe ingles'       => 'Caucasian',
            'ingles'           => 'Caucasian',
            'ben simmons'      => 'African',
            'simmons'          => 'African',
            'thon maker'       => 'African',
            'maker'            => 'African',
            'patty mills'      => 'African',
            'kyrie irving'     => 'African',
            // Canadenses — maioria segue padrões americanos
            // Africanos/Nigerianos
            'hakeem olajuwon'  => 'African',
            'olajuwon'         => 'African',
            'hakeem'           => 'African',
            'dikembe mutombo'  => 'African',
            'mutombo'          => 'African',
            'manute bol'       => 'African',
            'bol bol'          => 'African',
            'luol deng'        => 'African',
            'deng'             => 'African',
            'bismack biyombo'  => 'African',
            'biyombo'          => 'African',
            'joel embiid'      => 'African',
            'embiid'           => 'African',
            'pascal siakam'    => 'African',
            'siakam'           => 'African',
            'giannis antetokounmpo' => 'African',
            'antetokounmpo'    => 'African',
            'thanasis antetokounmpo' => 'African',
            'kostas antetokounmpo'   => 'African',
            'alex antetokounmpo'     => 'African',
            'george acheampong' => 'African',
            // Asiáticos
            'yao ming'         => 'Asian',
            'yao'              => 'Asian',
            'yi jianlian'      => 'Asian',
            'jianlian'         => 'Asian',
            'wang zhizhi'      => 'Asian',
            'zhizhi'           => 'Asian',
            'mengke bateer'    => 'Asian',
            'bateer'           => 'Asian',
            'jeremy lin'       => 'Asian',
            'watanabe'         => 'Asian',
            'rui hachimura'    => 'Asian',
            'hachimura'        => 'Asian',
            'yuta watanabe'    => 'Asian',
            'kai sotto'        => 'Seasian',
            // Sul-americanos/latinos
            'manu ginobili'    => 'South American',
            'ginobili'         => 'South American',
            'luis scola'       => 'South American',
            'scola'            => 'South American',
            'anderson varejao' => 'South American',
            'varejao'          => 'South American',
            'leandro barbosa'  => 'South American',
            'barbosa'          => 'South American',
            'nene hilario'     => 'South American',
            'hilario'          => 'South American',
            'tiago splitter'   => 'South American',
            'splitter'         => 'South American',
            'marcelinho huertas' => 'South American',
            'huertas'          => 'South American',
            'wemby'            => 'African',
            'wembanyama'       => 'African',
            'victor wembanyama' => 'African',
        ];
    }
}
