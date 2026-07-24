<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
check_auth('admin');
$enquiry_is_admin_page = true;
require __DIR__ . '/../../includes/enquiry_list_page.php';

