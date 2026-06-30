<?php 
$id = $_GET['id'] ?? '';
header('Location: hr/workers/edit.php' . ($id ? '?id=' . $id : '')); 
exit; 
?>