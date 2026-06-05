<?php

// SPDX-License-Identifier: Apache-2.0
//
// Portable recursive directory zipper (uses the php-zip extension the bundle already requires, so no `zip`
// CLI is needed on the build machine). Writes forward-slash entry names so the archive extracts correctly
// on the Linux shared hosts that are the deployment target. Usage: php zip-dir.php <srcDir> <outZip>

if ($argc < 3) {
    fwrite(STDERR, "usage: php zip-dir.php <srcDir> <outZip>\n");
    exit(2);
}

$src = rtrim($argv[1], '/\\');
$out = $argv[2];

if (! is_dir($src)) {
    fwrite(STDERR, "source is not a directory: {$src}\n");
    exit(1);
}

@unlink($out);

$zip = new ZipArchive;
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "could not open zip for writing: {$out}\n");
    exit(1);
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);

$files = 0;
foreach ($it as $item) {
    $path = $item->getPathname();
    $rel = ltrim(str_replace('\\', '/', substr($path, strlen($src))), '/');
    if ($rel === '') {
        continue;
    }
    if ($item->isDir()) {
        $zip->addEmptyDir($rel);
    } else {
        $zip->addFile($path, $rel);
        $files++;
    }
}

$zip->close();

fwrite(STDOUT, "zipped {$files} files -> {$out}\n");
