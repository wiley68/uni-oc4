<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Build\DistributionPackage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/scripts/DistributionPackage.php';

/** All destructive cases use a private Git fixture and a synthetic deployment key. */
final class DistributionPackageTest extends TestCase
{
    private string $root;
    private DistributionPackage $packager;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mtuc-package-test-' . bin2hex(random_bytes(12));
        mkdir($this->root, 0700);
        $source = dirname(__DIR__);
        $files = json_decode((string) file_get_contents($source . '/scripts/package-files.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (array_merge($files, ['.gitignore', 'composer.json', 'scripts/package-files.json',
            'scripts/DistributionPackage.php', 'scripts/build-package.php', 'scripts/verify-package.php']) as $path) {
            $target = $this->root . '/' . $path;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            if ($path === 'secrets/smartucf-key.php') {
                file_put_contents($target, "<?php return ['passphrase' => 'synthetic-packaging-fixture'];\r\n");
            } else {
                copy($source . '/' . $path, $target);
            }
        }
        $this->runFixtureCommand(['git', 'init', '-q', $this->root]);
        $this->runFixtureCommand(['git', '-C', $this->root, 'add', '.']);
        $this->packager = new DistributionPackage($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testBuildHasExactFilenameLayoutAndIndependentlyUnpacks(): void
    {
        $path = $this->packager->build();
        self::assertSame($this->root . '/dist/mt_uni_credit.ocmod.zip', $path);
        $manifest = $this->packager->packageFiles();
        self::assertSame(count($manifest), $this->packager->verify($path));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true);
        self::assertSame(count($manifest), $zip->numFiles);
        self::assertNotFalse($zip->locateName('install.json'));
        self::assertFalse($zip->locateName('mt_uni_credit/install.json'));
        self::assertFalse($zip->locateName('upload/install.json'));
        foreach (['admin/controller/module/mt_uni_credit.php', 'catalog/controller/payment/mt_uni_credit.php',
            'catalog/controller/api/order_bank_status.php', 'catalog/view/javascript/mt_uni_credit_product.js',
            'catalog/view/template/payment/mt_uni_credit.twig', 'catalog/language/bg-bg/payment/mt_uni_credit.php',
            'catalog/view/fonts/roboto-condensed/OFL.txt', 'keys/.htaccess', 'secrets/.htaccess'] as $required) {
            self::assertNotFalse($zip->locateName($required), $required);
        }
        mkdir($this->root . '/unpacked', 0700);
        self::assertTrue($zip->extractTo($this->root . '/unpacked'));
        $zip->close();
        foreach ($manifest as $relative => $source) {
            self::assertTrue(file_get_contents($this->root . '/' . $source)
                === file_get_contents($this->root . '/unpacked/' . $relative), 'Unpacked source parity: ' . $relative);
        }
        foreach (['config/environment.php', 'secrets/smartucf-key.php', 'secrets/.htaccess'] as $relative) {
            self::assertTrue(file_get_contents($this->root . '/' . $relative)
                === file_get_contents($this->root . '/unpacked/' . $relative), 'Deployment source parity: ' . $relative);
        }
        self::assertTrue(file_get_contents($this->root . '/dist/.htaccess')
            === file_get_contents($this->root . '/secrets/.htaccess'));
        self::assertSame(0664, fileperms($path) & 0777);
        self::assertSame([], glob($this->root . '/dist/.build-*'));
        $this->runFixtureCommand(['git', '-C', $this->root, 'check-ignore', '-q', 'dist/mt_uni_credit.ocmod.zip']);
        $this->runFixtureCommand(['git', '-C', $this->root, 'check-ignore', '-q', 'secrets/smartucf-key.php']);
        self::assertSame('', $this->runFixtureCommand(['git', '-C', $this->root, 'ls-files', 'dist/', 'secrets/smartucf-key.php']));
    }

    public function testBuildIsRepeatableAndDoesNotMutateInputs(): void
    {
        $before = $this->fingerprints();
        $path = $this->packager->build();
        $first = hash_file('sha256', $path);
        foreach ($this->packager->sourceFiles() as $relative) {
            touch($this->root . '/' . $relative, 1750000001);
            chmod($this->root . '/' . $relative, 0640);
        }
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');
        try {
            $this->packager->build();
            self::assertTrue($first === hash_file('sha256', $path), 'ZIP bytes repeat despite source mtimes/modes/timezone');
        } finally {
            date_default_timezone_set($timezone);
        }
        self::assertTrue($before === $this->fingerprints(), 'Runtime, environment, secret and Composer inputs stay unchanged');
    }

    public function testRepeatedBuildPreservesSharedDirectoryFilesOwnershipAndAcl(): void
    {
        $archive = $this->packager->build();
        $paths = [$this->root . '/dist', $this->root . '/dist/.htaccess', $archive];
        chmod($paths[0], 02775);
        chmod($paths[1], 0664);
        chmod($paths[2], 0664);
        $before = [];
        foreach ($paths as $path) {
            clearstatcache(true, $path);
            $stat = stat($path);
            $before[$path] = [$stat['ino'], $stat['uid'], $stat['gid'], $stat['mode']];
        }
        $this->packager->build();
        foreach ($paths as $path) {
            clearstatcache(true, $path);
            $stat = stat($path);
            self::assertSame($before[$path], [$stat['ino'], $stat['uid'], $stat['gid'], $stat['mode']], $path);
        }
        if (is_executable('/usr/bin/getfacl') && is_executable('/usr/bin/setfacl')) {
            $uid = (string) posix_geteuid();
            $this->runFixtureCommand(['/usr/bin/setfacl', '-m', 'u:' . $uid . ':rw', $archive]);
            $acl = $this->runFixtureCommand(['/usr/bin/getfacl', '-cp', $archive]);
            $this->packager->build();
            self::assertSame($acl, $this->runFixtureCommand(['/usr/bin/getfacl', '-cp', $archive]));
        }
    }

    public function testUnrelatedLocalFilesNeverEnterInstaller(): void
    {
        foreach (['secrets/another-key.php', 'keys/private.pem', 'config/environment.dist.php',
            'admin/controller/local-credentials.php', 'catalog/view/javascript/local.js', 'system/library/local.php',
            'tests/private.php', 'docs/operator.md', '.github/workflow.yml', '.vscode/settings.json',
            'vendor/autoload.php', 'logs/runtime.log', 'dist/old.ocmod.zip'] as $path) {
            if (!is_dir(dirname($this->root . '/' . $path))) {
                mkdir(dirname($this->root . '/' . $path), 0700, true);
            }
            file_put_contents($this->root . '/' . $path, 'synthetic-excluded-local-file');
        }
        $archive = $this->packager->build();
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            self::assertArrayHasKey($zip->getNameIndex($i), $this->packager->packageFiles());
        }
        foreach (['composer.json', 'composer.lock', 'vendor/autoload.php', 'scripts/package-files.json',
            'scripts/build-package.php', 'secrets/another-key.php', 'keys/private.pem', 'config/environment.dist.php',
            'admin/controller/local-credentials.php', 'tests/private.php', 'dist/old.ocmod.zip', '.git/config'] as $path) {
            self::assertFalse($zip->locateName($path), $path);
        }
        $zip->close();
    }

    public function testBulgarianVariantsHaveIdenticalFileSetsAndBytesAndEnglishIsPreserved(): void
    {
        $before = $this->fingerprints();
        $archive = $this->packager->build();
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        try {
            foreach (['admin' => 2, 'catalog' => 4] as $area => $count) {
                $variants = ['bg-bg' => [], 'bg' => [], 'bulgaria' => []];
                for ($index = 0; $index < $zip->numFiles; ++$index) {
                    $path = $zip->getNameIndex($index);
                    if (preg_match('~\A' . $area . '/language/(bg-bg|bg|bulgaria)/(.+)\z~D', $path, $matches)) {
                        $variants[$matches[1]][$matches[2]] = $zip->getFromIndex($index);
                    }
                }
                foreach ($variants as &$files) {
                    ksort($files, SORT_STRING);
                }
                unset($files);
                self::assertCount($count, $variants['bg-bg']);
                foreach (['bg', 'bulgaria'] as $alias) {
                    self::assertSame(array_keys($variants['bg-bg']), array_keys($variants[$alias]), $area . '/' . $alias);
                    self::assertSame($variants['bg-bg'], $variants[$alias], $area . '/' . $alias . ' byte parity');
                    self::assertDirectoryDoesNotExist($this->root . '/' . $area . '/language/' . $alias);
                }
                foreach ($variants['bg-bg'] as $relative => $bytes) {
                    self::assertSame(file_get_contents($this->root . '/' . $area . '/language/bg-bg/' . $relative), $bytes);
                }
            }
            $englishCount = 0;
            foreach ($this->packager->sourceFiles() as $path) {
                if (preg_match('~\A(?:admin|catalog)/language/en-gb/~', $path)) {
                    ++$englishCount;
                    self::assertSame(file_get_contents($this->root . '/' . $path), $zip->getFromName($path), $path);
                }
            }
            self::assertSame(6, $englishCount);
        } finally {
            $zip->close();
        }
        self::assertTrue($before === $this->fingerprints(), 'Build preserves all source bytes, including English');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidBulgarianAliasCases(): iterable
    {
        foreach (['admin', 'catalog'] as $area) {
            foreach (['bg', 'bulgaria'] as $alias) {
                foreach (['missing-directory', 'missing-file', 'changed-file', 'extra-file', 'renamed-file'] as $case) {
                    yield $area . '/' . $alias . '/' . $case => [$area, $alias, $case];
                }
            }
        }
    }

    #[DataProvider('invalidBulgarianAliasCases')]
    public function testVerifierRejectsMissingOrDivergentBulgarianAliases(string $area, string $alias, string $case): void
    {
        $archive = $this->packager->build();
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        $prefix = $area . '/language/' . $alias . '/';
        $path = $prefix . 'payment/mt_uni_credit.php';
        if ($case === 'missing-directory') {
            for ($index = $zip->numFiles - 1; $index >= 0; --$index) {
                if (str_starts_with($zip->getNameIndex($index), $prefix)) {
                    self::assertTrue($zip->deleteIndex($index));
                }
            }
        } elseif ($case === 'missing-file') {
            self::assertTrue($zip->deleteName($path));
        } elseif ($case === 'changed-file') {
            $bytes = $zip->getFromName($path);
            self::assertIsString($bytes);
            // Change one byte without changing size: size-only verification must not pass.
            $bytes[0] = $bytes[0] === '<' ? '!' : '<';
            self::assertTrue($zip->addFromString($path, $bytes));
        } elseif ($case === 'extra-file') {
            self::assertTrue($zip->addFromString($prefix . 'payment/unexpected.php', '<?php // divergent translation'));
        } else {
            self::assertTrue($zip->renameName($path, $prefix . 'payment/renamed.php'));
        }
        self::assertTrue($zip->close());
        $this->expectException(\RuntimeException::class);
        $this->packager->verify($archive);
    }

    public function testNewCanonicalBulgarianFilesAutomaticallyGetBothAliases(): void
    {
        $manifest = $this->packager->sourceFiles();
        foreach (['admin', 'catalog'] as $area) {
            $path = $area . '/language/bg-bg/module/nested/future.php';
            mkdir(dirname($this->root . '/' . $path), 0700, true);
            file_put_contents($this->root . '/' . $path, "<?php // Bulgarian fixture\r\n");
            $this->runFixtureCommand(['git', '-C', $this->root, 'add', $path]);
            $manifest[] = $path;
        }
        sort($manifest, SORT_STRING);
        file_put_contents($this->root . '/scripts/package-files.json', json_encode($manifest));
        $archive = $this->packager->build();
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        try {
            foreach (['admin', 'catalog'] as $area) {
                foreach (['bg-bg', 'bg', 'bulgaria'] as $code) {
                    self::assertSame("<?php // Bulgarian fixture\r\n", $zip->getFromName($area . '/language/' . $code . '/module/nested/future.php'));
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeSourceCases(): iterable
    {
        foreach (['missing-key', 'unreadable-key', 'symlink-key', 'directory-key', 'symlink-secret-directory',
            'missing-environment', 'missing-runtime', 'missing-protection', 'ineffective-protection',
            'new-tracked-runtime', 'manifest-omission', 'manifest-forbidden-file', 'runtime-dependency',
            'runtime-autoload', 'wrong-extension-code', 'ignored-dist-disabled', 'ignored-key-disabled',
            'symlink-dist', 'symlink-output', 'symlink-dist-protection'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('unsafeSourceCases')]
    public function testUnsafeBuildInputsFailWithoutLeakingOrMutatingSecret(string $case): void
    {
        $key = $this->root . '/secrets/smartucf-key.php';
        switch ($case) {
            case 'missing-key': unlink($key); break;
            case 'unreadable-key': chmod($key, 0000); break;
            case 'symlink-key':
                rename($key, $this->root . '/synthetic-key.php');
                symlink($this->root . '/synthetic-key.php', $key);
                break;
            case 'directory-key': unlink($key); mkdir($key); break;
            case 'symlink-secret-directory':
                rename($this->root . '/secrets', $this->root . '/local-secrets');
                symlink($this->root . '/local-secrets', $this->root . '/secrets');
                break;
            case 'missing-environment': unlink($this->root . '/config/environment.php'); break;
            case 'missing-runtime': unlink($this->root . '/catalog/controller/api/order_bank_status.php'); break;
            case 'missing-protection': unlink($this->root . '/secrets/.htaccess'); break;
            case 'ineffective-protection': file_put_contents($this->root . '/secrets/.htaccess', '# no protection'); break;
            case 'new-tracked-runtime':
                file_put_contents($this->root . '/system/library/new_runtime.php', '<?php');
                $this->runFixtureCommand(['git', '-C', $this->root, 'add', 'system/library/new_runtime.php']);
                break;
            case 'manifest-omission':
            case 'manifest-forbidden-file':
                $manifest = $this->packager->sourceFiles();
                if ($case === 'manifest-omission') {
                    $manifest = array_values(array_diff($manifest, ['catalog/controller/api/order_bank_status.php']));
                } else {
                    $manifest[] = 'secrets/another-key.php';
                }
                sort($manifest, SORT_STRING);
                file_put_contents($this->root . '/scripts/package-files.json', json_encode($manifest));
                break;
            case 'runtime-dependency':
            case 'runtime-autoload':
                $composer = json_decode((string) file_get_contents($this->root . '/composer.json'), true);
                if ($case === 'runtime-dependency') {
                    $composer['require']['example/runtime'] = '^1';
                } else {
                    $composer['autoload']['files'] = ['bootstrap.php'];
                }
                file_put_contents($this->root . '/composer.json', json_encode($composer));
                break;
            case 'wrong-extension-code':
                $metadata = json_decode((string) file_get_contents($this->root . '/install.json'), true);
                $metadata['code'] = 'wrong_code';
                file_put_contents($this->root . '/install.json', json_encode($metadata));
                break;
            case 'ignored-dist-disabled':
            case 'ignored-key-disabled':
                $ignore = (string) file_get_contents($this->root . '/.gitignore');
                $rule = $case === 'ignored-dist-disabled' ? '/dist/' : '/secrets/smartucf-key.php';
                file_put_contents($this->root . '/.gitignore', str_replace($rule, '', $ignore));
                break;
            case 'symlink-dist': symlink($this->root . '/config', $this->root . '/dist'); break;
            case 'symlink-output':
            case 'symlink-dist-protection':
                mkdir($this->root . '/dist');
                $filename = $case === 'symlink-output' ? DistributionPackage::FILENAME : '.htaccess';
                symlink($key, $this->root . '/dist/' . $filename);
                break;
        }
        $this->expectException(\RuntimeException::class);
        try {
            $this->packager->build();
        } finally {
            self::assertSame([], glob($this->root . '/dist/.build-*'));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidArchiveCases(): iterable
    {
        foreach (['missing-runtime', 'missing-environment', 'changed-environment', 'missing-key', 'changed-key',
            'missing-protection', 'changed-runtime', 'tests', 'git', 'dist', 'temporary', 'other-secret',
            'vendor', 'wrapper', 'traversal', 'absolute', 'symlink', 'wrong-filename', 'malformed'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidArchiveCases')]
    public function testMalformedIncompleteOrNonRuntimeArchivesAreRejected(string $case): void
    {
        $archive = $this->packager->build();
        if ($case === 'wrong-filename') {
            $renamed = $this->root . '/dist/mt_uni_credit-2.0.3.ocmod.zip';
            rename($archive, $renamed);
            $archive = $renamed;
        } elseif ($case === 'malformed') {
            file_put_contents($archive, 'not a ZIP');
        } else {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($archive) === true);
            $removed = [
                'missing-runtime' => 'catalog/controller/api/order_bank_status.php',
                'missing-environment' => 'config/environment.php', 'missing-key' => 'secrets/smartucf-key.php',
                'missing-protection' => 'secrets/.htaccess',
            ];
            $changed = ['changed-environment' => 'config/environment.php',
                'changed-key' => 'secrets/smartucf-key.php', 'changed-runtime' => 'system/library/extension_root.php'];
            $added = ['tests' => 'tests/test.php', 'git' => '.git/config', 'dist' => 'dist/previous.zip',
                'temporary' => '.build-temp/private.php', 'other-secret' => 'secrets/another-key.php',
                'vendor' => 'vendor/composer/autoload_psr4.php', 'wrapper' => 'mt_uni_credit/install.json',
                'traversal' => '../private.php', 'absolute' => '/private.php'];
            if (isset($removed[$case])) {
                self::assertTrue($zip->deleteName($removed[$case]));
            } elseif (isset($changed[$case])) {
                self::assertTrue($zip->addFromString($changed[$case], 'synthetic-tampered-content'));
            } elseif (isset($added[$case])) {
                self::assertTrue($zip->addFromString($added[$case], 'synthetic-excluded-content'));
            } elseif ($case === 'symlink') {
                self::assertTrue($zip->setExternalAttributesName('secrets/smartucf-key.php', \ZipArchive::OPSYS_UNIX, 0120777 << 16));
            }
            self::assertTrue($zip->close());
        }
        $this->expectException(\RuntimeException::class);
        $this->packager->verify($archive);
    }

    public function testCliBuildAndStandaloneVerifierReturnNonzeroOnFailure(): void
    {
        $build = $this->runFixtureCommand([PHP_BINARY, $this->root . '/scripts/build-package.php']);
        self::assertStringContainsString('/dist/mt_uni_credit.ocmod.zip', $build);
        $archive = $this->root . '/dist/' . DistributionPackage::FILENAME;
        $verified = $this->runFixtureCommand([PHP_BINARY, $this->root . '/scripts/verify-package.php', $archive]);
        self::assertStringContainsString('source parity verified', $verified);
        file_put_contents($archive, 'malformed');
        $this->runFixtureCommand([PHP_BINARY, $this->root . '/scripts/verify-package.php', $archive], 1);
        unlink($this->root . '/secrets/smartucf-key.php');
        $this->runFixtureCommand([PHP_BINARY, $this->root . '/scripts/build-package.php'], 1);
    }

    public function testFailedBuildPreservesPreviousInstallerAndCleansStage(): void
    {
        $archive = $this->packager->build();
        $hash = hash_file('sha256', $archive);
        $key = $this->root . '/secrets/smartucf-key.php';
        chmod($key, 0000);
        try {
            $this->packager->build();
            self::fail('Unreadable deployment input must fail the rebuild');
        } catch (\RuntimeException) {
            self::assertTrue($hash === hash_file('sha256', $archive), 'Previous installer remains intact');
        } finally {
            chmod($key, 0600);
        }
        // Publishing over a directory fails after staging and verification.
        rename($archive, $this->root . '/previous.zip');
        mkdir($archive);
        try {
            $this->packager->build();
            self::fail('Build should reject a directory output target');
        } catch (\RuntimeException) {
            self::assertSame([], glob($this->root . '/dist/.build-*'));
            self::assertTrue($hash === hash_file('sha256', $this->root . '/previous.zip'));
        }
    }

    /** @return array<string, string> Never emitted as assertion diagnostics. */
    private function fingerprints(): array
    {
        $hashes = [];
        foreach (array_merge($this->packager->sourceFiles(), ['composer.json']) as $path) {
            $hashes[$path] = hash_file('sha256', $this->root . '/' . $path);
        }
        return $hashes;
    }

    /** @param list<string> $command */
    private function runFixtureCommand(array $command, int $expectedStatus = 0): string
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame($expectedStatus, proc_close($process), 'Fixture command exit status');
        self::assertFalse(str_contains((string) $stdout . $stderr, 'synthetic-packaging-fixture'), 'Secret never enters CLI output');
        return (string) $stdout;
    }

    private function removeTree(string $root): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($root);
    }
}
