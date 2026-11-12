<?php

return [
    'gravity' => 9.8,
    'fluids' => [
        'agua' => ['nombre' => 'Agua', 'densidad' => 1000, 'color' => '#6495ed'],
        'aceite' => ['nombre' => 'Aceite', 'densidad' => 850, 'color' => '#e5b94c'],
        'mercurio' => ['nombre' => 'Mercurio', 'densidad' => 13546, 'color' => '#9aa0a6'],
        'alcohol' => ['nombre' => 'Alcohol', 'densidad' => 789, 'color' => '#c5d4e2'],
        'glicerina' => ['nombre' => 'Glicerina', 'densidad' => 1260, 'color' => '#d69213'],
        'parafina' => ['nombre' => 'Parafina', 'densidad' => 810, 'color' => '#b1a996'],
        'gasolina' => ['nombre' => 'Gasolina', 'densidad' => 720, 'color' => '#fcd572'],
    ],
    // El volumen de cada sólido se calcula a partir de la masa elegida: V = m / densidad.
    'objects' => [
        'madera' => ['nombre' => 'Madera', 'densidad' => 600, 'color' => '#9b6849'],
        'hielo' => ['nombre' => 'Hielo', 'densidad' => 917, 'color' => '#dcecf5'],
        'plastico' => ['nombre' => 'Plástico', 'densidad' => 1100, 'color' => '#e16d55'],
        'aluminio' => ['nombre' => 'Aluminio', 'densidad' => 2700, 'color' => '#aab5bd'],
    ],
    'initial_state' => [
        'activity' => 'arquimedes',
        'arquimedes_fluido1' => 'agua',
        'arquimedes_fluido2' => 'aceite',
        'objeto1' => 'madera',
        'objeto2' => 'aluminio',
        'masa1' => 1,
        'masa2' => 1,
        'hidrostatica_fluido1' => 'agua',
        'hidrostatica_fluido2' => 'aceite',
        'altura1' => 1,
        'altura2' => 1,
    ],
];
