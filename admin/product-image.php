<?php
/**
 * Delete one product photo.
 *
 * Uses a GET link for convenience inside the image grid, so the CSRF token is
 * carried in the query string and checked here explicitly.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
require_once YN_ROOT . '/includes/upload.php';

$admin = admin_guard();

$imageId   = get_int('delete');
$productId = get_int('product');
$token     = get_str('t', 100);

if (!hash_equals(csrf_token(), $token)) {
    flash('That link has expired. Please try again.', 'error');
    redirect('admin/product-edit.php?id=' . $productId);
}

$img = $imageId > 0
    ? db_row('SELECT * FROM product_images WHERE id = ? LIMIT 1', [$imageId])
    : null;

if ($img) {
    upload_delete((string) $img['path']);
    db_exec('DELETE FROM product_images WHERE id = ?', [$imageId]);
    admin_log('product_image_delete', 'product', (int) $img['product_id'], (string) $img['path']);
    flash('Photo deleted.', 'success');
    $productId = (int) $img['product_id'];
}

redirect('admin/product-edit.php?id=' . $productId);
