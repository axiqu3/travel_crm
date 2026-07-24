<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
check_auth();
$enquiry_is_admin_page = false;
require __DIR__ . '/../../includes/enquiry_ajax_add_handler.php';

