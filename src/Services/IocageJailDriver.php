<?php

namespace Monsefrachid\MysqlReplication\Services;

use Monsefrachid\MysqlReplication\Support\ShellRunner;
use Monsefrachid\MysqlReplication\Contracts\JailDriverInterface;
use RuntimeException;

/**
 * Class IocageJailDriver
 *
 * Handles jail operations using iocage.
 */
class IocageJailDriver implements JailDriverInterface
{
    /**
     * @var ShellRunner
     */
    private ShellRunner $shell;

    /**
     * Constructor
     *
     * @param ShellRunner $shell
     */
    public function __construct(ShellRunner $shell)
    {
        $this->shell = $shell;
    }

    /**
     * {@inheritdoc}
     */
    public function jailExists(string $jailName): bool
    {
        $output = $this->shell->shell(
            "sudo iocage list -H -q | awk '{print \$1}' | grep -w ^{$jailName}$"
        );

        return is_string($output) && trim($output) === $jailName;
    }

    /**
     * {@inheritdoc}
     */
    public function destroyJail(string $jailName): void
    {
        $this->shell->run(
            "sudo iocage destroy -f --recursive {$jailName}",
            "Force destroy existing jail '{$jailName}'"
        );
    }

    /**
     * {@inheritdoc}
     */
    public function assertJailRootExists(string $jailName): void
    {
        $rootPath = "/tank/iocage/jails/{$jailName}/root";

        // 🛡 Skip check if dry-run is enabled
        if ($this->shell->isDryRun()) {
            echo "⚠️ [DRY-RUN] Skipping jail root existence check: {$rootPath}\n";
            return;
        }

        if (!is_dir($rootPath)) {
            throw new RuntimeException("❌ Jail root '{$rootPath}' does not exist after snapshot transfer.");
        }
    }
}
