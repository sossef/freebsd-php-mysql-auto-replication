<?php

namespace Monsefrachid\MysqlReplication\Support;

/**
 * Trait LoggerTrait
 *
 * Provides shortcut logging methods via the Logger singleton,
 * eliminating repetitive Logger::get()->log...() calls.
 *
 * Must be used *after* Logger::load(...) has been called at least once.
 */
trait LoggerTrait
{
    /**
     * Log a plain message to both console and file.
     *
     * @param string $message
     */
    protected function log(string $message): void
    {
        Logger::get()->log($message);
    }

    /**
     * Log a step header with formatting ([STEP]).
     *
     * @param string $message
     */
    protected function logStep(string $message): void
    {
        Logger::get()->logStep($message);
    }

    /**
     * Log a shell command being executed ([CMD]).
     *
     * @param string $message
     */
    protected function logCmd(string $message): void
    {
        Logger::get()->logCmd($message);
    }

    public function logWarning(string $message): void
    {
        Logger::get()->logWarning($message);
    }

    /**
     * Logs a success message.
     *
     * @param string $message
     */
    public function logSuccess(string $message): void
    {
        Logger::get()->logSuccess($message);
    }

    /**
     * Logs an error message.
     *
     * @param string $message
     */
    public function logError(string $message): void
    {
        Logger::get()->logError($message);
    }

    /**
     * Log a dry-run skip message ([DRY-RUN]).
     *
     * @param string $message
     */
    protected function logDry(string $message): void
    {
        Logger::get()->logDry($message);
    }

    /**
     * Rename the current log file to include the final snapshot name.
     *
     * Useful when logging starts before the snapshot is known.
     * This renames the temporary log file (e.g. replication_...) to something like:
     * snapshot_20250624_120301.log
     *
     * @param string $newName The snapshot name to use in the new filename.
     */
    protected function renameLogFile(string $newName): void
    {
        Logger::get()->renameLogFile($newName);
    }
}
