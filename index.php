<?php
/**
 * Hosting-friendly entrypoint.
 *
 * If you upload the whole project to your domain root (public_html),
 * this file makes `domain.com/` work by delegating to `public/index.php`.
 *
 * It also exposes a small hint for assets so CSS/JS paths stay correct.
 */

// When running from the project root, public assets live under /public.
$appPublicPrefix = '/public';

require __DIR__ . '/public/index.php';

