<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "DNS publication plan is only available in the CLI.\n");
    exit(2);
}

try {
    $plan = (new Smbind\Publisher(Smbind\db()))->plan();
    fwrite(STDOUT, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Unable to inspect pending DNS changes: " . $error->getMessage() . "\n");
    exit(1);
}
