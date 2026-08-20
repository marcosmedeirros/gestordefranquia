<?php
/**
 * Os modelos técnicos da edição — os "coaches" que cada time escolhe.
 *
 * Cada um tem um card com os atributos que o admin usa na simulação. Os
 * números NÃO são calculados pelo site: vêm dos cards da liga e são só
 * exibidos aqui, pra o GM escolher sabendo o que está pegando.
 *
 * A chave é o que vai gravado em team_tactics.technical_model. Mudar uma
 * chave quebra a escolha de quem já salvou — para renomear, troque o
 * 'nome' e deixe a chave em paz.
 */

/** Quantos modelos o time usa numa edição: o primeiro mais as trocas. */
const MODELO_TECNICO_LIMITE = 8;

/**
 * [chave => [nome, arquivo da foto, atributos, sistema]]
 *
 * Os atributos, na ordem dos cards:
 *   HD  Help Defense              · quanto ajuda na marcação
 *   RPF Run Plays Frequency       · com que frequência chama jogada
 *   ZU  Zone Usage                · quanto usa zona
 *   BU  Bench Utilization         · quanto roda o banco
 *   LPF Lineup Performance Factor · quanto o quinteto pesa no resultado
 */
function modelosTecnicos(): array
{
    return [
        'Gregg Popovich' => ['Gregg Popovich', 'gregg-popovich.jpg',
            ['hd' => 50, 'rpf' => 95, 'zu' => 3,  'bu' => 95, 'lpf' => 24], 'Defense'],
        'Joe Mazzulla' => ['Joe Mazzulla', 'joe-mazzulla.jpg',
            ['hd' => 50, 'rpf' => 50, 'zu' => 2,  'bu' => 40, 'lpf' => 80], 'Perimeter Centric'],
        'The Special One' => ['The Special One', 'the-special-one.jpg',
            ['hd' => 15, 'rpf' => 95, 'zu' => 5,  'bu' => 30, 'lpf' => 85], 'Pace & Space'],
        'Ted Lasso' => ['Ted Lasso', 'ted-lasso.jpg',
            ['hd' => 65, 'rpf' => 65, 'zu' => 10, 'bu' => 80, 'lpf' => 15], 'Balanced'],
        'Pofexo' => ['Pofexo', 'pofexo.jpg',
            ['hd' => 70, 'rpf' => 70, 'zu' => 5,  'bu' => 25, 'lpf' => 15], 'Grit and Grind'],
        'Phil Jackson' => ['Phil Jackson', 'phil-jackson.jpg',
            ['hd' => 65, 'rpf' => 80, 'zu' => 10, 'bu' => 35, 'lpf' => 18], 'Triangle'],
        'Tite' => ['Tite', 'tite.jpg',
            ['hd' => 70, 'rpf' => 95, 'zu' => 10, 'bu' => 85, 'lpf' => 40], 'Balanced'],
        'Mike Brown' => ['Mike Brown', 'mike-brown.jpg',
            ['hd' => 50, 'rpf' => 50, 'zu' => 5,  'bu' => 40, 'lpf' => 50], 'Balanced'],
        'Nick Nurse' => ['Nick Nurse', 'nick-nurse.jpg',
            ['hd' => 50, 'rpf' => 35, 'zu' => 0,  'bu' => 50, 'lpf' => 0],  'Defense'],
        'Lionel Hollins' => ['Lionel Hollins', 'lionel-hollins.jpg',
            ['hd' => 50, 'rpf' => 75, 'zu' => 3,  'bu' => 50, 'lpf' => 0],  'Grit and Grind'],
        'Rick Carlisle' => ['Rick Carlisle', 'rick-carlisle.jpg',
            ['hd' => 50, 'rpf' => 90, 'zu' => 10, 'bu' => 65, 'lpf' => 96], 'Post Centric'],
        'Chacho Coudet' => ['Chacho Coudet', 'chacho-coudet.jpg',
            ['hd' => 25, 'rpf' => 92, 'zu' => 12, 'bu' => 38, 'lpf' => 25], 'Balanced'],
        "Mike D'Antoni" => ["Mike D'Antoni", 'mike-dantoni.jpg',
            ['hd' => 50, 'rpf' => 70, 'zu' => 5,  'bu' => 65, 'lpf' => 83], '7 Seconds or Less'],
        'Butch Carter' => ['Butch Carter', 'butch-carter.jpg',
            ['hd' => 50, 'rpf' => 80, 'zu' => 20, 'bu' => 35, 'lpf' => 80], 'Pace & Space'],
    ];
}

/** O que a sigla quer dizer, pra legenda do card. */
function modeloTecnicoAtributos(): array
{
    return [
        'hd'  => ['HD',  'Help Defense'],
        'rpf' => ['RPF', 'Run Plays Frequency'],
        'zu'  => ['ZU',  'Zone Usage'],
        'bu'  => ['BU',  'Bench Utilization'],
        'lpf' => ['LPF', 'Lineup Performance Factor'],
    ];
}

/** Um modelo pela chave, ou null. */
function modeloTecnico(?string $chave): ?array
{
    if (!$chave) return null;
    $todos = modelosTecnicos();
    if (!isset($todos[$chave])) return null;
    [$nome, $foto, $attrs, $sistema] = $todos[$chave];
    return ['chave' => $chave, 'nome' => $nome, 'foto' => '/img/coaches/' . $foto,
            'attrs' => $attrs, 'sistema' => $sistema];
}

/** Todos, prontos pro front — é o que alimenta o modal. */
function modelosTecnicosParaJson(): array
{
    $out = [];
    foreach (array_keys(modelosTecnicos()) as $chave) $out[] = modeloTecnico($chave);
    return $out;
}
