<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $days = isset($_POST['days']) ? intval($_POST['days']) : 0;
    
    $config_file = __DIR__ . '/auto_delete_config.json';
    $config = [];
    if (file_exists($config_file)) {
        $config = json_decode(file_get_contents($config_file), true) ?: [];
    }
    
    $config['threshold_days'] = $days;
    
    if (file_put_contents($config_file, json_encode($config))) {
        echo json_encode(['success' => true, 'message' => 'Auto-delete settings updated.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save configuration.']);
    }
    exit;
}
echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
?>
