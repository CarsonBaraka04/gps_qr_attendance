<?php
require_once __DIR__ . '/includes/bootstrap.php';

/* Logging out changes state, so it must be a POST request. */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(base_url('dashboard.php'));
}

csrf_verify();
logout_user();
redirect(base_url('index.php'));
