<?php
/**
 * Server error page.
 *
 * Deliberately standalone — it is rendered when something has already gone
 * wrong, quite possibly the database, so it must not depend on bootstrap,
 * settings, or any include that could fail a second time.
 */
declare(strict_types=1);

if (!headers_sent()) {
    http_response_code(500);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Something went wrong</title>
<style>
  *,*::before,*::after{box-sizing:border-box}
  html{overflow-x:clip}
  body{margin:0;min-height:100vh;display:grid;place-items:center;
       padding:clamp(16px,5vw,48px);max-width:100%;overflow-x:clip;
       background:#FDFBF7;color:#2E2A26;
       font:16px/1.65 Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
  .box{width:min(520px,100%);text-align:center}
  .art{width:88px;height:88px;margin:0 auto 24px;border-radius:50%;
       background:#F5EEE4;display:grid;place-items:center;color:#B4552F}
  h1{font-family:"Iowan Old Style",Georgia,serif;font-weight:600;letter-spacing:-.015em;
     font-size:clamp(1.5rem,1.2rem+1.5vw,2rem);margin:0 0 12px}
  p{color:#5D554C;margin:0 0 24px}
  .row{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
  a{display:inline-flex;align-items:center;justify-content:center;min-height:46px;
    padding:11px 24px;border-radius:999px;font-weight:600;font-size:.9375rem;text-decoration:none}
  .primary{background:#B4552F;color:#fff}
  .primary:hover{background:#8E3F1F}
  .soft{background:#F5EEE4;color:#2E2A26}
  .soft:hover{background:#EADFD0}
</style>
</head>
<body>
  <div class="box">
    <div class="art">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
        <circle cx="12" cy="12" r="8.5"/>
        <path d="M6.2 6.9c2.4 1.6 4.4 4.3 5.3 7.4M9.2 4.2c2.7 2 4.8 5.2 5.7 8.9M14.6 4.6c1.9 1.9 3.3 4.4 3.9 7.1"/>
      </svg>
    </div>
    <h1>We dropped a stitch</h1>
    <p>Something went wrong at our end. It has been logged and we&rsquo;re on it.
       Please try again in a moment.</p>
    <div class="row">
      <a class="primary" href="/">Back to home</a>
      <a class="soft" href="/contact.php">Contact us</a>
    </div>
  </div>
</body>
</html>
