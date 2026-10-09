<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

/** Runs the real canonical resolver in a separate package root; no runtime override seam. */
final class IsolatedCpRuntime
{
    public static function run(string $code, string $url = 'https://uni.avalonbg.com'): mixed
    {
        $root = sys_get_temp_dir() . '/mtuc-origin-' . bin2hex(random_bytes(12));
        mkdir($root . '/system/library', 0700, true);
        mkdir($root . '/config', 0700);
        mkdir($root . '/secrets', 0700);
        file_put_contents($root . '/secrets/smartucf-key.php', "<?php return ['passphrase' => 'synthetic-origin-fixture'];");
        foreach (glob(dirname(__DIR__, 2) . '/system/library/*.php') as $file) {
            copy($file, $root . '/system/library/' . basename($file));
        }
        file_put_contents($root . '/config/environment.php', '<?php return ' . var_export(['control_panel_url' => $url], true) . ';');
        $bootstrap = '<?php use Opencart\\System\\Library\\Extension\\MtUniCredit as U; ';
        $bootstrap .= 'spl_autoload_register(static function ($class) { $prefix = "Opencart\\\\System\\\\Library\\\\Extension\\\\MtUniCredit\\\\"; if (!str_starts_with($class, $prefix)) return; $name = substr($class, strlen($prefix)); ';
        $bootstrap .= '$dir = ' . var_export($root . '/system/library/', true) . '; ';
        $bootstrap .= 'foreach (["cp_exception.php", "persistence_exception.php"] as $file) require_once $dir . $file; $file = $dir . strtolower(preg_replace("~([a-z])([A-Z]|[0-9])~", "$1_$2", $name)) . ".php"; if (is_file($file)) require_once $file; }); ';
        $process = proc_open([PHP_BINARY], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        try {
            if (!is_resource($process)) {
                throw new \RuntimeException('Cannot start isolated CP fixture.');
            }
            fwrite($pipes[0], $bootstrap . $code);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            if ($status !== 0) {
                throw new \RuntimeException('Isolated CP fixture failed: ' . $stderr . $stdout);
            }
            return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    }
}
