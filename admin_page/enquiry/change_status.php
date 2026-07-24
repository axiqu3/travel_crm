<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
check_auth('admin');
require __DIR__ . '/../../includes/enquiry_change_status_handler.php';

