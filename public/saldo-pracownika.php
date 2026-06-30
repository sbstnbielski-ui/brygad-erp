<?php 
$id = $_GET['worker_id'] ?? $_GET['id'] ?? $_SESSION['worker_id'] ?? '';
header('Location: hr/workers/balance.php' . ($id ? '?worker_id=' . (int)$id : '')); 
exit; 
?>