<?php

function removeDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $files = glob($path . '/*');
    foreach ($files as $file) {
        is_dir($file) ? removeDirectory($file) : unlink($file);
    }
    rmdir($path);
}

removeDirectory(__DIR__ . '/cache/proxies');
if (!mkdir(__DIR__ . '/cache/proxies', 0777, true) && !is_dir(__DIR__ . '/cache/proxies')) {
    throw new \RuntimeException(sprintf('Directory "%s" was not created', __DIR__ . '/cache/proxies'));
}

return require_once __DIR__ . '/vendor/autoload.php';
