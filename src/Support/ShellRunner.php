<?php

namespace Monsefrachid\MysqlReplication\Support;

use Monsefrachid\MysqlReplication\Support\LoggerTrait;
use RuntimeException;

/**
 * Executes system shell commands with optional dry-run mode.
 *
 * This class is used across the application to run shell commands,
 * ensuring consistent logging, error handling, and optional simulation.
 */
class ShellRunner
{
    // Inject logging helpers from LoggerTrait
    use LoggerTrait;

    /**
     * Whether to simulate command execution without actually running them.
     *
     * @var bool
     */
    private bool $dryRun;

     /**
     * Constructor.
     *
     * @param bool $dryRun If true, all commands will be logged but not executed.
     */
    public function __construct(bool $dryRun = false)
    {
        $this->dryRun = $dryRun;
    }

    /**
     * Run a shell command with output capture, logging, and error handling.
     *
     * @param string      $cmd     The shell command to run.
     * @param string|null $desc    Optional step description to print before execution.
     * @param string|null $onError Optional custom error message if command fails.
     *
     * @return array Output lines from the command.
     *
     * @throws RuntimeException If the command fails and dry-run is disabled.
     */
    public function run(string $cmd, ?string $desc = null, ?string $onError = null): array
    {
        // Log the step description if provided
        if ($desc) {
            $this->logStep($desc);
        }

        // Log the actual shell command being run        
        $this->logCmd($cmd);

        // If dry-run mode is enabled, skip execution and return simulated output
        if ($this->dryRun) {
            $this->logDryRun("Skipping exec: {$cmd}\n");
            return ["[DRY-RUN] Command not executed."];
        }

        // Execute the shell command and capture output and exit code
        exec($cmd, $output, $exitCode);

        // If the command failed (non-zero exit), throw an exception with a helpful message
        if ($exitCode !== 0) {
            $message = $onError ?: "Command failed: {$cmd}";
            $this->logError($message);
            throw new RuntimeException("❌ [ERROR] {$message}");
        }

        // Return the output lines from the command
        return $output;
    }

    /**
     * Execute a shell command and return the raw output as a string.
     *
     * @param string      $cmd  The command to run.
     * @param string|null $desc Optional description to display before execution.
     *
     * @return string|null Output string, or a dry-run placeholder.
     */
    public function shell(string $cmd, ?string $desc = null): ?string
    {
        // Log the step description if provided
        if ($desc) {
            $this->logStep($desc);
        }

        // Log the shell command being executed
        $this->logCmd($cmd);

        // If dry-run mode is enabled, skip actual execution and return a placeholder message
        if ($this->dryRun) {
            $this->logDryRun("Skipping shell_exec: {$cmd}\n");
            return "[DRY-RUN] (shell)";
        }

        // Execute the command using shell_exec and return the raw output
        // If execution fails (returns null), return an empty string
        return shell_exec($cmd) ?? ''; 
    }

    /**
     * Check whether dry-run mode is active.
     *
     * @return bool True if dry-run mode is enabled, false otherwise.
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }
}
