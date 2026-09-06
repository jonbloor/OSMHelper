<?php
declare(strict_types=1);
$envPath = __DIR__ . '/.env'; // will run from app dir via rename
// Actually invoked as: php /home/osmhelper/app/ensure-session-secret.php with cwd app
$envPath = getcwd() . '/.env';
$example = getcwd() . '/.env.example';
if (!file_exists($envPath) && file_exists($example)) {
    copy($example, $envPath);
}
$t = file_exists($envPath) ? (string) file_get_contents($envPath) : '';
$needs = $t === '' || !preg_match('/^SESSION_SECRET=.+$/m', $t) || preg_match('/^SESSION_SECRET=\s*$/m', $t);
if (!$needs) {
    echo "SESSION_SECRET already set\n";
    exit(0);
}
$s = bin2hex(random_bytes(32));
if (preg_match('/^SESSION_SECRET=/m', $t)) {
    $t = preg_replace('/^SESSION_SECRET=.*$/m', 'SESSION_SECRET=' . $s, $t);
} else {
    $t = rtrim($t) . "\nSESSION_SECRET=" . $s . "\n";
}
file_put_contents($envPath, $t);
echo "SESSION_SECRET set\n";
