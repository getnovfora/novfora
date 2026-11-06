<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Usage: php release-manifest.php <staged-release-dir>
// Read the release's literal default version, never the builder's APP_VERSION environment.
$stage = $argv[1] ?? '';
$config = $stage.'/config/app.php';
if (! is_dir($stage) || ! is_file($config)) {
    fwrite(STDERR, "FATAL: staged release or config/app.php is missing\n");
    exit(1);
}

$source = file_get_contents($config);
if ($source === false || preg_match("/'version'\\s*=>\\s*env\\(\\s*'APP_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $source, $matches) !== 1
    || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $matches[1]) !== 1) {
    fwrite(STDERR, "FATAL: cannot read a literal semver default from config/app.php\n");
    exit(1);
}

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);

foreach ($iterator as $entry) {
    $path = $entry->getPathname();
    $relative = str_replace('\\', '/', substr($path, strlen(rtrim($stage, '/\\')) + 1));

    if ($entry->isLink()) {
        fwrite(STDERR, "FATAL: release contains symlink: {$relative}\n");
        exit(1);
    }
    if ($relative === 'modules/novfora/populate' || str_starts_with($relative, 'modules/novfora/populate/')) {
        fwrite(STDERR, "FATAL: private Populate module is staged: {$relative}\n");
        exit(1);
    }
    if ($entry->isDir()) {
        continue;
    }
    if (! $entry->isFile()) {
        fwrite(STDERR, "FATAL: release contains non-regular file: {$relative}\n");
        exit(1);
    }
    if ($relative === 'release-manifest.json') {
        continue;
    }

    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        fwrite(STDERR, "FATAL: cannot hash release file: {$relative}\n");
        exit(1);
    }
    $files[$relative] = $hash;
}

ksort($files, SORT_STRING);
$manifest = [
    'format' => 1,
    'version' => $matches[1],
    'php' => '>=8.3',
    'files' => $files,
];
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
if (file_put_contents($stage.'/release-manifest.json', $json) !== strlen($json)) {
    fwrite(STDERR, "FATAL: cannot write release-manifest.json\n");
    exit(1);
}

fwrite(STDOUT, 'manifest: '.$matches[1].', '.count($files)." files\n");
