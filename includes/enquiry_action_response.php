<?php

function enquiry_action_fail($message, $status = 422, $enquiry_id = 0) {
    global $enquiry_response_json;
    if (!empty($enquiry_response_json)) {
        enquiry_json(false, $message, [], $status);
    }
    $target = 'view.php' . ($enquiry_id > 0 ? '?id=' . (int) $enquiry_id . '&' : '?') . 'error=' . rawurlencode($message);
    enquiry_redirect_path($target);
}

function enquiry_action_success($message, $key, $enquiry_id, $extra = []) {
    global $enquiry_response_json;
    if (!empty($enquiry_response_json)) {
        enquiry_json(true, $message, $extra);
    }
    enquiry_redirect_path('view.php?id=' . (int) $enquiry_id . '&success=' . rawurlencode($key));
}

