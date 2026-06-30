<?php
/**
 * BRYGAD ERP - Czyszczenie OPcache
 * WGRAJ NA SERWER, ODWIEDŹ RAZ, POTEM USUŃ!
 */

// Reset OPcache
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache wyczyszczony.<br>";
} else {
    echo "OPcache nie jest aktywny.<br>";
}

// Sprawdź daty modyfikacji plików
$files = [
    'config/database.php',
    'hr/index.php',
    'hr/workers/balance.php',
    'hr/workers/report.php',
];

echo "<h3>Daty modyfikacji plikow na serwerze:</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Plik</th><th>Data modyfikacji</th><th>Rozmiar</th></tr>";

foreach ($files as $file) {
    $fullPath = __DIR__ . '/' . $file;
    if (file_exists($fullPath)) {
        $mtime = date('Y-m-d H:i:s', filemtime($fullPath));
        $size = filesize($fullPath) . ' B';
        echo "<tr><td>$file</td><td>$mtime</td><td>$size</td></tr>";
    } else {
        echo "<tr><td>$file</td><td colspan='2' style='color:red'>NIE ISTNIEJE!</td></tr>";
    }
}
echo "</table>";

// Pokaż fragment balance.php żeby potwierdzić wersję
$balancePath = __DIR__ . '/hr/workers/balance.php';
if (file_exists($balancePath)) {
    $content = file_get_contents($balancePath);
    
    echo "<h3>Diagnostyka balance.php:</h3>";
    echo "<ul>";
    echo "<li>Zawiera 'debugInfo': " . (strpos($content, 'debugInfo') !== false ? '<b style="color:red">TAK (stara wersja!)</b>' : '<b style="color:green">NIE (nowa wersja)</b>') . "</li>";
    echo "<li>Zawiera emoji oka: " . (strpos($content, '👁') !== false ? '<b style="color:red">TAK (stara wersja!)</b>' : '<b style="color:green">NIE (nowa wersja)</b>') . "</li>";
    echo "<li>Zawiera 'finanse.zaliczki.view': " . (strpos($content, 'finanse.zaliczki.view') !== false ? '<b style="color:green">TAK (klikalny opis)</b>' : '<b style="color:red">NIE (stara wersja!)</b>') . "</li>";
    echo "</ul>";
}

// Pokaż fragment hr/index.php
$indexPath = __DIR__ . '/hr/index.php';
if (file_exists($indexPath)) {
    $content = file_get_contents($indexPath);
    
    echo "<h3>Diagnostyka hr/index.php:</h3>";
    echo "<ul>";
    echo "<li>Zawiera 'sidebar-report-link': " . (strpos($content, 'sidebar-report-link') !== false ? '<b style="color:green">TAK (nowa wersja)</b>' : '<b style="color:red">NIE (stara wersja!)</b>') . "</li>";
    echo "<li>Zawiera 'Dodaj zaliczke': " . (strpos($content, 'Dodaj zaliczke') !== false ? '<b style="color:green">TAK (nowa wersja)</b>' : '<b style="color:red">NIE (stara wersja!)</b>') . "</li>";
    echo "</ul>";
}

echo "<br><p><b>USUN TEN PLIK PO UZYCIU!</b></p>";
echo "<p>Teraz odswież strone: <a href='/hr/index.php'>HR</a> | <a href='/hr/workers/balance.php?worker_id=11'>Saldo</a></p>";
?>

