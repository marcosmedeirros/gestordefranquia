<?php
// Check accounts first
$acc = new PDO('sqlite:C:/Users/marco/OneDrive/Documentos/fbagame/storage/accounts.sqlite');
$saves = $acc->query('SELECT * FROM saves')->fetchAll(PDO::FETCH_ASSOC);
echo "SAVES na conta:\n";
foreach($saves as $s) {
    echo "  id={$s['id']} slot={$s['slot']} name={$s['name']} team={$s['team_abbr']} era={$s['era']}\n";
}

// Check save_1.sqlite (the actual game file)
$f = 'C:/Users/marco/OneDrive/Documentos/fbagame/storage/saves/save_1.sqlite';
if(!file_exists($f)) { echo "$f NAO EXISTE\n"; exit; }

$db = new PDO('sqlite:'.$f);
$tables = array_column($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC), 'name');
echo "\nsave_1.sqlite tables: ".implode(', ',$tables)."\n";

// Try to get meta values
try {
    $gmt = $db->query("SELECT v FROM meta WHERE k='gm_team'")->fetchColumn();
    $gmn = $db->query("SELECT v FROM meta WHERE k='gm_name'")->fetchColumn();
    $sty = $db->query("SELECT v FROM meta WHERE k='coach_style'")->fetchColumn();
    echo "  gm_team=$gmt  gm_name=$gmn  style=$sty\n";
} catch(Exception $e) {
    echo "  Meta error: ".$e->getMessage()."\n";
}

if(in_array('coaches',$tables)){
    $rows = $db->query('SELECT * FROM coaches')->fetchAll(PDO::FETCH_ASSOC);
    echo '  coaches rows: '.count($rows)."\n";
    foreach($rows as $r) { echo '    '.print_r($r,true); }
} else {
    echo "  coaches table: NOT FOUND — migration needed\n";
}
