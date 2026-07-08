<?php
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");
check_auth('admin');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id > 0) {
    mysqli_query($db, "DELETE FROM enquiries WHERE id = $id");
}

header("Location: list.php?deleted=1");
exit;
?>
