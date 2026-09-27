<?php

return [
    'iq_by_raw_score' => [
        0 => 38, 1 => 40, 2 => 43, 3 => 45, 4 => 47, 5 => 48,
        6 => 52, 7 => 55, 8 => 57, 9 => 60, 10 => 63, 11 => 67,
        12 => 70, 13 => 72, 14 => 75, 15 => 78, 16 => 81, 17 => 85,
        18 => 88, 19 => 91, 20 => 94, 21 => 96, 22 => 100, 23 => 103,
        24 => 106, 25 => 109, 26 => 113, 27 => 116, 28 => 119, 29 => 121,
        30 => 124, 31 => 128, 32 => 131, 33 => 133, 34 => 137, 35 => 140,
        36 => 142, 37 => 145, 38 => 149, 39 => 152, 40 => 155, 41 => 157,
        42 => 161, 43 => 165, 44 => 167, 45 => 169, 46 => 173, 47 => 176,
        48 => 179, 49 => 183, 50 => 183,
    ],
    'iq_categories' => [
        ['maximum' => 69, 'label' => 'Mentally Retardation'],
        ['maximum' => 79, 'label' => 'Borderline Defective'],
        ['maximum' => 89, 'label' => 'Low Average'],
        ['maximum' => 109, 'label' => 'Average'],
        ['maximum' => 119, 'label' => 'High Average'],
        ['maximum' => 139, 'label' => 'Superior'],
        ['maximum' => 169, 'label' => 'Very Superior'],
        ['maximum' => null, 'label' => 'Genius'],
    ],
    'sections' => [
        ['title' => 'Tes 1 · Deret', 'count' => 13, 'options' => 6, 'choices' => 1, 'example' => 2, 'pages' => [3, 4, 5]],
        ['title' => 'Tes 2 · Klasifikasi', 'count' => 14, 'options' => 5, 'choices' => 2, 'example' => 6, 'pages' => [7, 8]],
        ['title' => 'Tes 3 · Matriks', 'count' => 13, 'source_numbers' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13], 'options' => 6, 'choices' => 1, 'example' => 9, 'pages' => [10, 14, 11]],
        ['title' => 'Tes 4 · Kondisi', 'count' => 10, 'options' => 5, 'choices' => 1, 'example' => 12, 'pages' => [13]],
    ],
];
