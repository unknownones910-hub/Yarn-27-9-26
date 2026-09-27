<?php
/**
 * Sign out.
 *
 * POST only. A GET logout can be triggered by any <img src="/logout.php"> on
 * a page the customer happens to visit, which is a real (if mild) CSRF.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!is_post()) {
    redirect('account/');
}

csrf_verify();

$everywhere = post_str('everywhere') === '1';
$id         = customer_id();

if ($everywhere && $id > 0) {
    remember_revoke_all($id);
}

logout_customer();

// logout_customer() destroyed the session, so start a fresh one to carry
// the confirmation message.
session_start();
session_regenerate_id(true);
flash($everywhere ? 'Signed out on all devices.' : 'You are signed out.', 'success');

redirect('');
