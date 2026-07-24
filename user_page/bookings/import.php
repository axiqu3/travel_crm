<?php
// Agent Entry point for Bulk Import Center
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");

// General authentication check (admin or agent)
check_auth();

$is_admin_user = false;

// Load shared logic
require_once(__DIR__ . "/../../includes/bulk_import_handler.php");
