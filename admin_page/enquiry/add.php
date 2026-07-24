<?php
$query = ['action' => 'add'];
if (($_GET['return_to'] ?? '') === 'dashboard') {
    $query['return_to'] = 'dashboard';
}
header('Location: list.php?' . http_build_query($query));
exit;
