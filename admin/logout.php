<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';

if (!is_post()) {
    redirect('admin/');
}

csrf_verify();

$a = current_admin();
if ($a) {
    admin_log('admin_logout', 'admin_user', (int) $a['id'], 'Signed out');
}

logout_admin();
flash('Signed out of the admin panel.', 'success');
redirect('admin/login.php');
