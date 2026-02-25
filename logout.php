<?php
declare(strict_types=1);

require __DIR__ . '/includes/session.php';
start_secure_session();
require __DIR__ . '/includes/admin_auth.php';
admin_logout();
header('Location: /login.php', true, 302);
exit;
