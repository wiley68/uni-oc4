<?php

declare(strict_types=1);

namespace MtUniCredit\Build;

use RuntimeException;
use ZipArchive;

/** Build tooling only. No module PHP or deployment configuration is executed. */
final class DistributionPackage
{
    public const FILENAME = 'mt_uni_credit.ocmod.zip';
    private const MANIFEST = 'scripts/package-files.json';
    private const SECRET = 'secrets/smartucf-key.php';
    private const REQUIRED = [
        '.htaccess', 'install.json', 'config/environment.php', 'config/index.php',
        'secrets/.htaccess', 'secrets/index.php', self::SECRET, 'keys/.htaccess', 'keys/index.php',
        'admin/controller/module/mt_uni_credit.php', 'admin/controller/payment/mt_uni_credit.php',
        'admin/model/module/mt_uni_credit.php', 'catalog/controller/payment/mt_uni_credit.php',
        'catalog/model/payment/mt_uni_credit.php', 'system/library/extension_root.php',
        'system/library/module_deployment_environment.php', 'system/library/mtls_private_key_passphrase_provider.php',
    ];

    public function __construct(private string $sourceRoot)
    {
        $root = realpath($sourceRoot);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Source directory does not exist.');
        }
        $this->sourceRoot = $root;
    }

    /** Paths require explicit manifest approval; this additionally limits their scope. */
    public static function isRuntimePath(string $path): bool
    {
        if (in_array($path, ['.htaccess', 'install.json', 'config/environment.php', 'config/index.php',
            'keys/.htaccess', 'keys/index.php', 'secrets/.htaccess', 'secrets/index.php', self::SECRET], true)) {
            return true;
        }
        if (!preg_match('~\A(?:admin|catalog|system)/[A-Za-z0-9_./-]+\z~D', $path)
            || preg_match('~(?:^|/)\.|(?:^|/)(?:tests?|docs|dist|vendor|cache|logs?|tmp|secrets|credentials)(?:/|$)|(?:\.local\.|\.bak\.|\.tmp\.)~i', $path)
            || str_contains($path, '..') || str_contains($path, '//')) {
            return false;
        }
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if (str_starts_with($path, 'system/')) {
            return $extension === 'php';
        }
        return in_array($extension, ['php', 'twig', 'js', 'css', 'png', 'svg', 'jpg', 'jpeg', 'gif',
            'webp', 'ico', 'woff', 'woff2', 'ttf', 'otf'], true)
            || in_array($path, ['catalog/view/fonts/roboto-condensed/LICENSE.txt',
                'catalog/view/fonts/roboto-condensed/OFL.txt'], true);
    }

    /** @return list<string> Complete, audited inventory including the one local secret. */
    public function sourceFiles(): array
    {
        $manifest = json_decode($this->readSource(self::MANIFEST), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || !array_is_list($manifest) || $manifest === []) {
            throw new RuntimeException('Package source manifest must be a nonempty list.');
        }
        foreach ($manifest as $path) {
            if (!is_string($path) || !self::isRuntimePath($path)) {
                throw new RuntimeException('Package source manifest contains a forbidden path.');
            }
            if (preg_match('~\A(?:admin|catalog)/language/(?:bg|bulgaria)/~', $path)) {
                throw new RuntimeException('Bulgarian aliases must be generated from bg-bg, not maintained in the source manifest.');
            }
        }
        $sorted = $manifest;
        sort($sorted, SORT_STRING);
        if ($manifest !== $sorted || count(array_unique($manifest)) !== count($manifest)) {
            throw new RuntimeException('Package source manifest must be sorted and unique.');
        }
        foreach (self::REQUIRED as $path) {
            if (!in_array($path, $manifest, true)) {
                throw new RuntimeException('Required runtime file missing from source manifest: ' . $path);
            }
        }

        $tracked = array_filter(explode("\0", $this->git(['ls-files', '--cached', '-z'])));
        // Approved manifest removals may still be unstaged in the working tree.
        $deleted = array_filter(explode("\0", $this->git(['ls-files', '--deleted', '-z'])));
        foreach ($tracked as $path) {
            if ($path === self::SECRET || str_starts_with($path, 'dist/')) {
                throw new RuntimeException('Deployment secret and dist artifacts must remain untracked.');
            }
            if (in_array($path, $deleted, true)) {
                continue;
            }
            if ((self::isRuntimePath($path) || preg_match('~\A(?:admin|catalog|system)/~', $path))
                && !in_array($path, $manifest, true)) {
                throw new RuntimeException('Tracked runtime file missing from audited manifest: ' . $path);
            }
        }
        foreach ($manifest as $path) {
            // Explicit manifest approval also permits reviewed, unstaged new runtime files.
            // Check every input now, including parent symlinks and unreadable files.
            $this->readSource($path);
        }
        $this->git(['check-ignore', '-q', '--', self::SECRET]);
        $this->git(['check-ignore', '-q', '--', 'dist/' . self::FILENAME]);

        $composer = json_decode($this->readSource('composer.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (array_keys($composer['require'] ?? []) as $dependency) {
            if ($dependency !== 'php' && !str_starts_with($dependency, 'ext-')) {
                throw new RuntimeException('New Composer runtime dependencies require a packaging audit.');
            }
        }
        if (!empty($composer['autoload']) || !empty($composer['include-path'])) {
            throw new RuntimeException('New Composer runtime autoloading requires a packaging audit.');
        }
        $metadata = json_decode($this->readSource('install.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['name', 'version', 'author', 'link'] as $key) {
            if (!is_string($metadata[$key] ?? null) || $metadata[$key] === '') {
                throw new RuntimeException('OpenCart install.json metadata is incomplete.');
            }
        }
        if (($metadata['code'] ?? null) !== 'mt_uni_credit') {
            throw new RuntimeException('OpenCart extension code must match the mandatory installer filename.');
        }
        foreach (['secrets/.htaccess', 'keys/.htaccess'] as $path) {
            $protection = $this->readSource($path);
            if (!preg_match('/^\h*Require\h+all\h+denied\h*$/mi', $protection)
                || !preg_match('/^\h*Deny\h+from\h+all\h*$/mi', $protection)) {
                throw new RuntimeException('Required Apache protection is missing: ' . $path);
            }
        }
        return $manifest;
    }

    /** @return array<string, string> Sorted ZIP path => authoritative source path, including generated aliases. */
    public function packageFiles(): array
    {
        $files = [];
        foreach ($this->sourceFiles() as $path) {
            $files[$path] = $path;
            if (preg_match('~\A((?:admin|catalog)/language/)bg-bg/(.+)\z~D', $path, $matches)) {
                foreach (['bg', 'bulgaria'] as $alias) {
                    $files[$matches[1] . $alias . '/' . $matches[2]] = $path;
                }
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    public function build(): string
    {
        $this->requireZip();
        $files = $this->packageFiles();
        $dist = $this->sourceRoot . '/dist';
        $this->assertNoSymlinks('dist');
        if (!is_dir($dist)) {
            if (!mkdir($dist, 02775) || !chmod($dist, 02775)) {
                throw new RuntimeException('Cannot create shared dist directory.');
            }
        }
        $this->assertNoSymlinks('dist/.htaccess');
        $this->publishShared($this->readSource('secrets/.htaccess'), 'dist/.htaccess');
        $stage = $dist . '/.build-' . bin2hex(random_bytes(16));
        if (!mkdir($stage, 0700)) {
            throw new RuntimeException('Cannot create private staging directory.');
        }
        $output = $dist . '/' . self::FILENAME;
        $temporaryZip = $stage . '/' . self::FILENAME;
        try {
            $tree = $stage . '/runtime';
            foreach ($files as $path => $source) {
                $target = $tree . '/' . $path;
                $this->mkdir(dirname($target));
                $this->writePrivate($target, $this->readSource($source));
            }
            $timestamp = time();
            $this->writeArchive($temporaryZip, $tree, array_keys($files), $timestamp);
            $this->verify($temporaryZip);
            $this->assertNoSymlinks('dist/' . self::FILENAME);
            $bytes = file_get_contents($temporaryZip);
            if ($bytes === false) {
                throw new RuntimeException('Cannot read verified installer.');
            }
            $this->verifyBuildTimestamps($bytes, count($files), $timestamp);
            $this->publishShared($bytes, 'dist/' . self::FILENAME);
            return $output;
        } finally {
            $this->removeStage($stage);
        }
    }

    /** @param list<string> $paths */
    private function writeArchive(string $archive, string $tree, array $paths, int $timestamp): void
    {
        $timezone = getenv('TZ', true);
        if (!putenv('TZ=UTC')) {
            throw new RuntimeException('Cannot set ZIP build timezone.');
        }
        try {
            // libzip writes timezone-less DOS fields using the process timezone.
            $zip = new ZipArchive();
            if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create installer ZIP.');
            }
            try {
                foreach ($paths as $path) {
                    if (!$zip->addFile($tree . '/' . $path, $path)
                        || !$zip->setMtimeName($path, $timestamp)
                        || !$zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, 0100644 << 16)
                        || !$zip->setCompressionName($path, ZipArchive::CM_DEFLATE, 9)) {
                        throw new RuntimeException('Cannot write installer ZIP entry.');
                    }
                }
            } finally {
                $closed = $zip->close();
            }
            if (!$closed) {
                throw new RuntimeException('Cannot finalize private installer ZIP.');
            }
        } finally {
            try {
                unset($zip);
            } finally {
                putenv($timezone === false ? 'TZ' : 'TZ=' . $timezone);
            }
        }
    }

    private function verifyBuildTimestamps(string $bytes, int $entries, int $timestamp): void
    {
        [$year, $month, $day, $hour, $minute, $second] = array_map('intval', explode('-', gmdate('Y-n-j-G-i-s', $timestamp)));
        if ($year < 1980 || $year > 2107) {
            throw new RuntimeException('Build clock is outside the ZIP timestamp range (1980–2107).');
        }
        $expected = pack('vv', ($hour << 11) | ($minute << 5) | intdiv($second, 2),
            (($year - 1980) << 9) | ($month << 5) | $day);

        // This is the uncommented archive just written by the builder and structurally checked by libzip.
        $end = strlen($bytes) - 22;
        if ($end < 0 || substr($bytes, $end, 4) !== "PK\x05\x06") {
            throw new RuntimeException('Cannot locate staged installer central directory.');
        }
        $offset = unpack('V', $bytes, $end + 16)[1];
        if ($offset === 0xffffffff) {
            $locator = substr($bytes, $end - 20, 20);
            if (!str_starts_with($locator, "PK\x06\x07")) {
                throw new RuntimeException('Cannot locate staged installer ZIP64 central directory.');
            }
            $record = substr($bytes, unpack('P', $locator, 8)[1], 56);
            if (strlen($record) !== 56 || !str_starts_with($record, "PK\x06\x06")) {
                throw new RuntimeException('Invalid staged installer ZIP64 directory record.');
            }
            $offset = unpack('P', $record, 48)[1];
        }
        for ($index = 0; $index < $entries; ++$index) {
            $header = substr($bytes, $offset, 46);
            if (strlen($header) !== 46 || !str_starts_with($header, "PK\x01\x02")) {
                throw new RuntimeException('Invalid staged installer central directory entry.');
            }
            $lengths = unpack('vname/vextra/vcomment', $header, 28);
            $local = unpack('V', $header, 42)[1];
            if ($local === 0xffffffff) {
                $extra = substr($bytes, $offset + 46 + $lengths['name'], $lengths['extra']);
                $local = $this->zip64LocalOffset($header, $extra);
            }
            if (substr($header, 12, 4) !== $expected || substr($bytes, $local, 4) !== "PK\x03\x04"
                || substr($bytes, $local + 10, 4) !== $expected) {
                throw new RuntimeException('Staged installer entry timestamp does not match the UTC build time.');
            }
            $offset += 46 + array_sum($lengths);
        }
    }

    private function zip64LocalOffset(string $header, string $extra): int
    {
        $sizes = unpack('Vcompressed/Vuncompressed', $header, 20);
        // The ZIP64 field contains only values that overflowed their central header slots.
        $skip = 8 * count(array_keys($sizes, 0xffffffff, true));
        for ($offset = 0, $length = strlen($extra); $offset + 4 <= $length;) {
            $field = unpack('vid/vsize', $extra, $offset);
            $offset += 4;
            if ($field['id'] === 1 && $field['size'] >= $skip + 8 && $offset + $field['size'] <= $length) {
                return unpack('P', $extra, $offset + $skip)[1];
            }
            $offset += $field['size'];
        }
        throw new RuntimeException('Cannot locate staged installer ZIP64 local header.');
    }

    /** Independently opens a ZIP and compares every entry to the audited current source. */
    public function verify(string $archive): int
    {
        $this->requireZip();
        if (basename($archive) !== self::FILENAME) {
            throw new RuntimeException('Installer filename must be exactly ' . self::FILENAME . '.');
        }
        $files = $this->packageFiles();
        $expected = array_keys($files);
        $zip = new ZipArchive();
        if (is_link($archive) || !is_file($archive)
            || $zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Installer ZIP is missing, unreadable or malformed.');
        }
        try {
            $seen = [];
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $path = $zip->getNameIndex($index);
                if (!is_string($path) || !in_array($path, $expected, true) || isset($seen[$path])) {
                    throw new RuntimeException('Installer contains an unexpected, unsafe or duplicate entry.');
                }
                if (!$zip->getExternalAttributesIndex($index, $system, $attributes)
                    || (($attributes >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('Installer contains invalid attributes or a symlink.');
                }
                $source = $this->readSource($files[$path]);
                $stat = $zip->statIndex($index);
                if ($stat === false || $stat['size'] !== strlen($source) || ($stat['encryption_method'] ?? 0) !== 0) {
                    throw new RuntimeException('Installer source parity failed: ' . $path);
                }
                $bytes = $zip->getFromIndex($index);
                // Boolean comparison only: contents, expected values and hashes never enter diagnostics.
                if ($bytes === false || $bytes !== $source) {
                    throw new RuntimeException('Installer source parity failed: ' . $path);
                }
                $seen[$path] = true;
            }
            $actual = array_keys($seen);
            sort($actual, SORT_STRING);
            if ($actual !== $expected) {
                throw new RuntimeException('Installer is incomplete: inventory differs from source manifest.');
            }
            return count($seen);
        } finally {
            $zip->close();
        }
    }

    private function requireZip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ext-zip is required for packaging and verification.');
        }
    }

    /** Reject symlinks in every component, including the secrets directory itself. */
    private function assertNoSymlinks(string $relative): void
    {
        $path = $this->sourceRoot;
        foreach (explode('/', $relative) as $segment) {
            $path .= '/' . $segment;
            clearstatcache(true, $path);
            if (is_link($path)) {
                throw new RuntimeException('Build input/output path must not contain symlinks: ' . $relative);
            }
        }
    }

    private function readSource(string $relative): string
    {
        $this->assertNoSymlinks($relative);
        $path = $this->sourceRoot . '/' . $relative;
        $before = @lstat($path);
        if ($before === false || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0444) === 0 || !is_readable($path)) {
            throw new RuntimeException('Required source file is missing, unreadable or not regular: ' . $relative);
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot safely open source file: ' . $relative);
        }
        try {
            $opened = fstat($handle);
            if ($opened === false || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']
                || ($opened['mode'] & 0170000) !== 0100000) {
                throw new RuntimeException('Source file changed while opening: ' . $relative);
            }
            $bytes = stream_get_contents($handle);
            $this->assertNoSymlinks($relative);
            if ($bytes === false || strlen($bytes) !== $opened['size']) {
                throw new RuntimeException('Cannot safely read complete source file: ' . $relative);
            }
            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        $process = proc_open(array_merge(['git', '-C', $this->sourceRoot], $arguments),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot run packaging Git safety check.');
        }
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $output === false) {
            throw new RuntimeException('Packaging Git inventory/ignore check failed.');
        }
        return $output;
    }

    private function mkdir(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true)) {
            throw new RuntimeException('Cannot create build directory.');
        }
    }

    private function writePrivate(string $path, string $bytes): void
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot safely copy build input.');
        }
        try {
            if (!@chmod($path, 0600) || fwrite($handle, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Cannot safely copy complete build input.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** Preserve existing inode ownership, mode and ACL. New files inherit dist's default ACL.
     * Publication follows verification. Readers should take a shared lock during publication.
     */
    private function publishShared(string $bytes, string $relative): void
    {
        $this->assertNoSymlinks($relative);
        $path = $this->sourceRoot . '/' . $relative;
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before !== false && (($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1)) {
            throw new RuntimeException('Shared publication target must be a regular file without hardlinks.');
        }
        $handle = @fopen($path, $before === false ? 'x+b' : 'r+b');
        if ($handle === false) {
            throw new RuntimeException('Cannot open shared publication target.');
        }
        $previous = null;
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock shared publication target.');
            }
            $opened = fstat($handle);
            if ($opened === false || ($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1
                || ($before !== false && ($opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']))) {
                throw new RuntimeException('Shared publication target changed while opening.');
            }
            if ($before === false && !chmod($path, 0664)) {
                throw new RuntimeException('Cannot apply shared access to new published file.');
            }
            if ($before !== false && (($opened['mode'] & 0664) !== 0664)) {
                throw new RuntimeException('Existing published file must permit shared developer/web access.');
            }
            $previous = stream_get_contents($handle);
            if ($previous === false) {
                throw new RuntimeException('Cannot preserve previous published bytes.');
            }
            rewind($handle);
            if (fwrite($handle, $bytes) !== strlen($bytes) || !ftruncate($handle, strlen($bytes)) || !fflush($handle)) {
                throw new RuntimeException('Cannot publish complete verified installer.');
            }
        } catch (\Throwable $exception) {
            if (is_string($previous)) {
                rewind($handle);
                fwrite($handle, $previous);
                ftruncate($handle, strlen($previous));
                fflush($handle);
            }
            throw $exception;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Remove only the unique private directory created by this build. */
    private function removeStage(string $stage): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $removed = $entry->isDir() && !$entry->isLink()
                ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            if (!$removed) {
                throw new RuntimeException('Cannot clean private package staging directory.');
            }
        }
        if (!@rmdir($stage)) {
            throw new RuntimeException('Cannot clean private package staging directory.');
        }
    }
}
