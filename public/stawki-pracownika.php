<?php 
$id = $_GET['id'] ?? '';
header('Location: hr/workers/rates.php' . ($id ? '?id=' . $id : '')); 
exit; 
?>