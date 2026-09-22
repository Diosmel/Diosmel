<?php
// Script puntual de migración ya ejecutado. Se conserva como referencia,
// pero ahora exige una sesión administrativa: nunca debe poder ejecutarlo
// alguien que llegue a la URL por casualidad.
require __DIR__ . '/includes/bootstrap.php';
require_admin();
$data = database_read();

$input = [
    ['name' => 'ᴹᵉDracarys†', 'food' => 1200000, 'wood' => 1200000, 'stone' => 1200000, 'gold' => 0],
    ['name' => 'ᴹᵉDaesey', 'food' => 1200800, 'wood' => 1200800, 'stone' => 1027000, 'gold' => 608300],
    ['name' => 'ᴹᵉVannhatz', 'food' => 1223544, 'wood' => 1001490, 'stone' => 874474, 'gold' => 761347],
    ['name' => 'ᴹᵉPullN', 'food' => 820000, 'wood' => 820000, 'stone' => 820000, 'gold' => 820000],
    ['name' => 'ᴹᵉKratos', 'food' => 1210950, 'wood' => 1210950, 'stone' => 780000, 'gold' => 468000],
    ['name' => 'ᴹᵉ HadesWar', 'food' => 1210950, 'wood' => 1210950, 'stone' => 780000, 'gold' => 468000],
    ['name' => 'ᴹᵉMeGapbIX', 'food' => 1400000, 'wood' => 1400000, 'stone' => 1400000, 'gold' => 0],
    ['name' => 'ᴹᵉSELVA', 'food' => 123000, 'wood' => 0, 'stone' => 0, 'gold' => 0],
    ['name' => 'ᴹᵉRawemoon', 'food' => 1202620, 'wood' => 1215625, 'stone' => 1001674, 'gold' => 608936],
    ['name' => 'ᴹᵉPLNick', 'food' => 1200070, 'wood' => 1201300, 'stone' => 999930, 'gold' => 600240],
    ['name' => 'ᴹᵉAstrielle', 'food' => 1215000, 'wood' => 1215000, 'stone' => 1053000, 'gold' => 607500],
];

// Calculate current totals
$totals = [];
foreach ($data['contributions'] as $record) {
    $pid = $record['player_id'];
    if (!isset($totals[$pid])) {
        $totals[$pid] = ['food'=>0,'wood'=>0,'stone'=>0,'gold'=>0];
    }
    foreach (['food','wood','stone','gold'] as $res) {
        $totals[$pid][$res] += (int) $record[$res];
    }
}

echo "Players before: " . count($data['players']) . "\n";
echo "Contributions before: " . count($data['contributions']) . "\n";

foreach ($input as $row) {
    $player = ensure_player_in_data($data, '', $row['name']);
    $pid = $player['id'];
    $current = $totals[$pid] ?? ['food'=>0,'wood'=>0,'stone'=>0,'gold'=>0];
    
    $diff = [
        'food' => max(0, $row['food'] - $current['food']),
        'wood' => max(0, $row['wood'] - $current['wood']),
        'stone' => max(0, $row['stone'] - $current['stone']),
        'gold' => max(0, $row['gold'] - $current['gold']),
    ];
    
    if ($diff['food'] > 0 || $diff['wood'] > 0 || $diff['stone'] > 0 || $diff['gold'] > 0) {
        $data['contributions'][] = [
            'id' => new_id('con'),
            'player_id' => $pid,
            'food' => $diff['food'],
            'wood' => $diff['wood'],
            'stone' => $diff['stone'],
            'gold' => $diff['gold'],
            'note' => 'Actualización de saldo',
            'created_at' => gmdate('c'),
            'updated_at' => null,
        ];
        echo "Added contribution for {$row['name']}: " . json_encode($diff) . "\n";
    } else {
        echo "No contribution needed for {$row['name']}\n";
    }
}

echo "Players after: " . count($data['players']) . "\n";
echo "Contributions after: " . count($data['contributions']) . "\n";

database_write_unlocked($data);
echo "Terminado.";
