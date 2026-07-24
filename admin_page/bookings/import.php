<?php
// Admin Entry point for Bulk Import Center
require_once(__DIR__ . "/../../includes/db.php");
require_once(__DIR__ . "/../../includes/auth.php");

// Restrict to admins only
check_auth('admin');

$is_admin_user = true;

// Load shared logic
require_once(__DIR__ . "/../../includes/bulk_import_handler.php");
