<?php

require 'vendor/autoload.php';

const CSV_BEFORE = '11-02.csv';
const CSV_AFTER = '11-07.csv';

$csvHeader = function ($file) {
    $fp = fopen($file, 'r');
    $header = fgetcsv($fp, 0, ';');
    fclose($fp);
    return $header;
};

$csvLine = function ($line) {
    return json_decode($line, true);
};

$csvFile = function ($file) {
    $fp = fopen($file, 'r');
    $lines = [];
    while ($data = fgetcsv($fp, 0, ';')) {
        $lines[] = $data;
    }
    fclose($fp);

    $lines = array_filter($lines);
    array_shift($lines);

    $lines = array_map('json_encode', $lines);

    return $lines;
};

$oldHeader = $csvHeader(CSV_BEFORE);
$newHeader = $csvHeader(CSV_AFTER);

if ($oldHeader !== $newHeader) {
    exit("Header mismatch\n");
}

$oldLines = $csvFile(CSV_BEFORE);
$newLines = $csvFile(CSV_AFTER);

$differ = new Diff($oldLines, $newLines);
$renderer = new Diff_Renderer_Html_Array;
$diff = $differ->Render($renderer);

$deleted = $added = $changed = [];
foreach ($diff as $i => $set) {
    foreach ($set as $subset) {
        if ($subset['tag'] == 'insert') {
            $added = array_merge($added, $subset['changed']['lines']);
        }
        elseif ($subset['tag'] == 'delete') {
            $deleted = array_merge($deleted, $subset['base']['lines']);
        }
        elseif ($subset['tag'] == 'replace') {
            $changed[] = [
                'base' => $subset['base']['lines'],
                'changed' => $subset['changed']['lines'],
            ];
        }
    }
}

//header('Content-type: text/plain; charset=utf-8');
//print_r($deleted);
//print_r($added);
//print_r($changed);
//exit;

$toTable = function ($type, $first, array $line) {
    return "<tr><$type>" . $first . "</$type><$type>" . implode("</$type><$type>", $line) . "</$type></tr>";
};

$tableHeader = function ($title) use ($oldHeader) {
    return '<tr><th></th><th colspan="' . count($oldHeader) . '">' . $title . '</th></tr>';
};

?>
<style>
td, th {
    white-space: nowrap;
    text-align: left;
}
</style>
<?php

echo '<table border="1">';
echo $toTable('th', '', $oldHeader);
echo $tableHeader('deleted');
foreach ($deleted as $item) {
    echo $toTable('td', '', $csvLine($item));
}
echo $tableHeader('added');
foreach ($added as $item) {
    echo $toTable('td', '', $csvLine($item));
}
foreach ($changed as $item) {
    echo $tableHeader('changed');
    foreach ($item['base'] as $line) {
        echo $toTable('td', '-', $csvLine($line));
    }
    foreach ($item['changed'] as $line) {
        echo $toTable('td', '+', $csvLine($line));
    }
}
echo '</table>';
