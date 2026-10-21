<?php

declare(strict_types=1);

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php scripts/build-release-manifest.php <release-root> <commit-sha> <tag>\n");
    exit(2);
}

[$script, $releaseRoot, $commit, $tag] = $argv;
$realRoot = realpath($releaseRoot);
if (! is_string($realRoot) || ! is_dir($realRoot)) {
    fwrite(STDERR, "Release root does not exist.\n");
    exit(2);
}
if (preg_match('/^[a-f0-9]{40}$/D', $commit) !== 1) {
    fwrite(STDERR, "Commit must be a full lowercase SHA-1.\n");
    exit(2);
}
if (preg_match('/^v((?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*))$/D', $tag, $matches) !== 1) {
    fwrite(STDERR, "Tag must use vX.Y.Z stable SemVer.\n");
    exit(2);
}

$version = $matches[1];
$versionFile = $realRoot.DIRECTORY_SEPARATOR.'VERSION';
if (! is_file($versionFile) || trim((string) file_get_contents($versionFile)) !== $version) {
    fwrite(STDERR, "VERSION does not match the release tag.\n");
    exit(2);
}

$requiredFiles = [
    '.env.example',
    'VERSION',
    'artisan',
    'composer.json',
    'composer.lock',
    'vendor/autoload.php',
    'public/build/manifest.json',
];
foreach ($requiredFiles as $requiredFile) {
    $requiredPath = $realRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $requiredFile);
    if (! is_file($requiredPath) || is_link($requiredPath)) {
        fwrite(STDERR, "Release package is incomplete: {$requiredFile} is missing.\n");
        exit(2);
    }
}

$isPortableSegment = static function (string $segment): bool {
    if ($segment === ''
        || $segment === '.'
        || $segment === '..'
        || strlen($segment) > 255
        || preg_match('/[\x00-\x1F\x7F<>:"|?*]/', $segment) === 1
        || str_ends_with($segment, ' ')
        || str_ends_with($segment, '.')) {
        return false;
    }

    return preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?$/iD', $segment) !== 1;
};

$isForbiddenRuntimePath = static function (string $relative): bool {
    $lower = strtolower($relative);

    if ($lower === '.env' || (str_starts_with($lower, '.env.') && $lower !== '.env.example')) {
        return true;
    }

    foreach ([
        '.git',
        '.github',
        '.npmrc',
        '.phpunit.cache',
        'auth.json',
        'bootstrap/cache',
        'dist',
        'node_modules',
        'phpunit.xml',
        'public/hot',
        'public/storage',
        'storage',
        'tests',
    ] as $forbidden) {
        if ($lower === $forbidden || str_starts_with($lower, $forbidden.'/')) {
            return true;
        }
    }

    return preg_match(
        '#^database/(?:[^/]+/)*[^/]*(?:\.sqlite[^/]*|\.db(?:-[^/]*)?)$#D',
        $lower,
    ) === 1;
};

$files = [];
$canonicalPaths = [];
$totalBytes = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($realRoot, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);
foreach ($iterator as $file) {
    if ($file->isLink()) {
        fwrite(STDERR, "Release package must not contain symbolic links.\n");
        exit(2);
    }

    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($realRoot) + 1));
    foreach (explode('/', $relative) as $segment) {
        if (! $isPortableSegment($segment)) {
            fwrite(STDERR, "Release contains a path that is unsafe on Windows: {$relative}.\n");
            exit(2);
        }
    }

    $canonicalPath = strtolower($relative);
    if (isset($canonicalPaths[$canonicalPath])) {
        fwrite(STDERR, "Release contains case-ambiguous paths: {$relative}.\n");
        exit(2);
    }
    $canonicalPaths[$canonicalPath] = true;
    // The updater also counts the ZIP root and the manifest created below.
    if (count($canonicalPaths) > 99_998) {
        fwrite(STDERR, "Release contains too many filesystem entries.\n");
        exit(2);
    }

    if ($isForbiddenRuntimePath($relative)) {
        fwrite(STDERR, "Release contains a forbidden runtime or development path: {$relative}.\n");
        exit(2);
    }

    if ($file->isDir()) {
        continue;
    }
    if (! $file->isFile()) {
        fwrite(STDERR, "Release contains an unsupported filesystem entry: {$relative}.\n");
        exit(2);
    }
    if ($relative === 'release-manifest.json') {
        continue;
    }

    $size = $file->getSize();
    if ($size < 0) {
        fwrite(STDERR, "Unable to determine the size of {$relative}.\n");
        exit(2);
    }
    $totalBytes += $size;
    if ($totalBytes > 943_718_400) {
        fwrite(STDERR, "Release expands beyond the updater safety limit.\n");
        exit(2);
    }

    $digest = hash_file('sha256', $file->getPathname());
    if (! is_string($digest)) {
        fwrite(STDERR, "Unable to hash {$relative}.\n");
        exit(2);
    }
    $files[$relative] = $digest;
}
ksort($files);

$manifest = [
    'schema' => 'siem-salabim-release/v1',
    'repository' => 'SundaXploit/siem_salabim',
    'version' => $version,
    'tag' => $tag,
    'commit' => $commit,
    'minimum_php' => '8.3.0',
    'required_extensions' => [
        'ctype',
        'curl',
        'dom',
        'fileinfo',
        'filter',
        'hash',
        'iconv',
        'json',
        'libxml',
        'mbstring',
        'openssl',
        'pcre',
        'pdo',
        'session',
        'tokenizer',
        'xml',
        'zip',
    ],
    'files' => $files,
];

$json = json_encode(
    $manifest,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;
if ($totalBytes + strlen($json) > 943_718_400) {
    fwrite(STDERR, "Release expands beyond the updater safety limit.\n");
    exit(2);
}
$manifestPath = $realRoot.DIRECTORY_SEPARATOR.'release-manifest.json';
if (file_put_contents($manifestPath, $json, LOCK_EX) !== strlen($json)) {
    fwrite(STDERR, "Unable to write release manifest.\n");
    exit(2);
}

fwrite(STDOUT, sprintf("Manifest contains %d files for %s.\n", count($files), $tag));
