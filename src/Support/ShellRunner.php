<?php

namespace Monsefrachid\MysqlReplication\Support;

use RuntimeException;

/**
 * Class ShellRunner
 *
 * Executes shell commands with optional dry-run support, output capture,
 * and error handling.
 */
class ShellRunner
{
    /**
     * Whether to simulate command execution without running them.
     *
     * @var bool
     */
    private bool $dryRun;

    /**
     * Constructor
     *
     * @param bool $dryRun If true, commands will be logged but not executed
     */
    public function __construct(bool $dryRun = false)
    {
        $this->dryRun = $dryRun;
    }

    /**
     * Run a shell command with logging and error handling.
     *
     * @param string      $cmd     The shell command to run
     * @param string|null $desc    Optional description to log before the command
     * @param string|null $onError Optional custom error message
     *
     * @return array Output lines from the command
     *
     * @throws RuntimeException If the command fails and dry-run is disabled
     */
    public function run(string $cmd, ?string $desc = null, ?string $onError = null): array
    {
        if ($desc) {
            echo "\n⚙️ [STEP] {$desc} \n";
        }

        echo "➡️ [CMD] {$cmd}\n";

        if ($this->dryRun) {
            echo "🔇 [DRY-RUN] Skipping execution.\n";
            return ["[DRY-RUN] Command not executed."];
        }

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $message = $onError ?: "Command failed: {$cmd}";
            throw new RuntimeException("❌ [ERROR] {$message}");
        }

        return $output;
    }

    /**
     * Run a shell and return raw output.
     *
     * @param string $cmd
     *
     * @return string|null Output or null if failed
     */
    public function shell(string $cmd, ?string $desc = null): ?string
    {
        if ($desc) {
            echo "\n⚙️ [STEP] {$desc} \n";
        }

        echo "➡️ [CMD] {$cmd}\n";

        if ($this->dryRun) {
            echo "🔇 [DRY-RUN] Skipping shell_exec: {$cmd}\n";
            return "[DRY-RUN] (shell)";
        }

        return shell_exec($cmd);
    }

    /**
     * Check if dry-run mode is enabled.
     *
     * @return bool True if dry-run mode is active, false otherwise
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }
}
