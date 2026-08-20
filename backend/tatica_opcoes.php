<?php
/**
 * Os rótulos de cada opção de tática, do jeito que aparecem no jogo.
 *
 * Mora aqui porque DOIS lugares precisam: a tela do GM, que monta os
 * selects, e o admin, que mostra a tática de cada time. Enquanto este mapa
 * viveu só dentro do tatica.php, o admin exibia o valor cru do banco
 * ("pace_space", "crash_glass") — e qualquer opção nova entraria bonita de
 * um lado e crua do outro.
 *
 * A chave é o que vai pro banco; o valor é o que a pessoa lê.
 */
return [
    'pace' => ['no_preference' => 'Sem preferência', 'patient' => 'Patient Offense',
               'average' => 'Average Tempo', 'shoot_at_will' => 'Shoot at Will'],
    'game_style' => ['balanced' => 'Balanced', 'triangle' => 'Triangle', 'grit_grind' => 'Grit & Grind',
                     'pace_space' => 'Pace & Space', 'perimeter_centric' => 'Perimeter Centric',
                     'post_centric' => 'Post Centric', 'seven_seconds' => 'Seven Seconds', 'defense' => 'Defense'],
    'offense_style' => ['no_preference' => 'Sem preferência', 'pick_roll' => 'Pick & Roll',
                        'neutral' => 'Neutro', 'play_through_star' => 'Play Through Star',
                        'get_to_basket' => 'Get to The Basket', 'get_shooters_open' => 'Get Shooters Open',
                        'feed_post' => 'Feed The Post'],
    'offensive_rebound' => ['no_preference' => 'Sem preferência', 'crash_glass' => 'Crash Offensive Glass',
                            'some_crash' => 'Some Crash, Others Get Back', 'limit_transition' => 'Limit Transition'],
    'defensive_rebound' => ['no_preference' => 'Sem preferência', 'crash_glass' => 'Crash Defensive Glass',
                            'some_crash' => 'Some Crash Others Run', 'run_transition' => 'Run in Transition'],
    'offensive_aggression' => ['no_preference' => 'Sem preferência', 'physical' => 'Play Physical Defense',
                               'conservative' => 'Conservative Defense', 'neutral' => 'Neutral Defensive Aggression'],
    'defensive_focus' => ['no_preference' => 'Sem preferência', 'neutral' => 'Neutral Defensive Focus',
                          'protect_paint' => 'Protect the Paint', 'limit_perimeter' => 'Limit Perimeter Shots'],
    // Os modelos técnicos vêm do catálogo (backend/modelos_tecnicos.php),
    // onde cada um tem card, foto e atributos. Duas listas seria garantir
    // que uma delas envelhece.
    'technical_model' => (function () {
        require_once __DIR__ . '/modelos_tecnicos.php';
        $out = [];
        foreach (modelosTecnicos() as $chave => $m) $out[$chave] = $m[0];
        return $out;
    })(),
];
