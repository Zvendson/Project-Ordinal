<?php

/** Reads an administrator password from standard input and saves only its hash in private configuration. */

declare(strict_types=1);

const MIN_PASSWORD_BYTES = 12;
const MAX_PASSWORD_BYTES = 72;

$password = rtrim((string) stream_get_contents(STDIN, MAX_PASSWORD_BYTES + 3), "\r\n");
if (strlen($password) < MIN_PASSWORD_BYTES || strlen($password) > MAX_PASSWORD_BYTES) {
    fwrite(STDERR, "Provide a password between 12 and 72 bytes through standard input.\n");
    exit(1);
}
$directory = dirname(__DIR__) . '/.ordinal';
if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
    fwrite(STDERR, "Could not create private administrator configuration.\n");
    exit(1);
}
$path = $directory . '/admin.php';
$hash = password_hash($password, PASSWORD_DEFAULT);
$temporary = tempnam($directory, 'admin-');
if ($temporary === false) { fwrite(STDERR, "Could not prepare administrator configuration.\n"); exit(1); }
try {
    if (file_put_contents($temporary, "<?php\n\ndeclare(strict_types=1);\n\n/** Contains only the local administrator password hash. */\nreturn ['passwordHash' => " . var_export($hash, true) . "];\n") === false || !rename($temporary, $path)) {
        fwrite(STDERR, "Could not save administrator configuration.\n");
        exit(1);
    }
    chmod($path, 0600);
    fwrite(STDOUT, "Administrator password configured. Keep .ordinal/admin.php private and outside Git.\n");
} finally { if (is_file($temporary)) { unlink($temporary); } }
