<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
try {
    $report = (new Smbind\Publisher(Smbind\db()))->publish();
    fwrite(STDOUT, "Published {$report} DNS zones successfully.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Publication failed: {$error->getMessage()}\n");
    exit(1);
}
