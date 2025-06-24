<?php

namespace Monsefrachid\MysqlReplication\Support;

/**
 * Logger class to capture both console output and write it to a log file.
 * Usage:
 *     Logger::load('/path/to/logs', 'snapshot_20250624');
 *     Logger::get()->log("Message");
 */
class Logger
{
    /**
     * Singleton instance.
     */
    private static ?Logger $instance = null;

    /**
     * Full path to the log file.
     */
    private string $logFilePath;

    /**
     * Whether logging is enabled.
     */
    private bool $enabled = false;

    /**
     * Private constructor to enforce singleton usage.
     *
     * @param string $directory     Directory to store the log file.
     * @param string $snapshotName  Snapshot name to use in the log filename.
     */
    private function __construct(string $directory, string $snapshotName)
    {
        $timestamp = date('Ymd_His'); // Format like 20250624_153045
        $filename = "{$snapshotName}_{$timestamp}.log";

        // Ensure trailing slash and construct full path
        $this->logFilePath = rtrim($directory, '/') . '/' . $filename;

        // Enable logging
        $this->enabled = true;
    }

    /**
     * Initializes the logger.
     *
     * @param string $directory     Where to store the log file.
     * @param string $snapshotName  Used to form the log file name.
     */
    public static function load(string $directory, string $snapshotName): void
    {
        if (self::$instance === null) {
            self::$instance = new self($directory, $snapshotName);
        }
    }

    /**
     * Retrieves the singleton logger instance.
     *
     * @return Logger
     */
    public static function get(): Logger
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Logger not initialized. Call Logger::load() first.');
        }

        return self::$instance;
    }

    /**
     * Writes raw text to the log file (without echoing).
     *
     * @param string $message
     */
    public function write(string $message): void
    {
        if (!$this->enabled) {
            return;
        }

        file_put_contents($this->logFilePath, $message, FILE_APPEND);
    }

    /**
     * Writes a log message to both the file and the console.
     *
     * @param string $message
     */
    public function log(string $message): void
    {
        $this->write($message);
        echo $message;
    }

    /**
     * Logs a structured STEP message (⚙️).
     *
     * @param string $message
     */
    public function logStep(string $message): void
    {
        $this->log("\n⚙️ [STEP] {$message}\n");
    }

    /**
     * Logs a command being run (➡️).
     *
     * @param string $message
     */
    public function logCmd(string $message): void
    {
        $this->log("➡️ [CMD] {$message}\n");
    }

    /**
     * Logs a dry-run skip message (🔇).
     *
     * @param string $message
     */
    public function logDry(string $message): void
    {
        $this->log("🔇 [DRY-RUN] {$message}\n");
    }
}
