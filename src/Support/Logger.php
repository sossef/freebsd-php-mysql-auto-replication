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
     * Log file name
     */
    private string $logFileName;

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
        $timestamp = date('YmdHis'); // Format like 20250624153045
        $this->logFileName = "{$snapshotName}_{$timestamp}.log";

        // Ensure trailing slash and construct full path
        $this->logFilePath = rtrim($directory, '/') . '/' . $this->logFileName;

        // Enable logging
        $this->enabled = true;
    }

    /**
     * Initializes the singleton Logger instance.  
     *
     * @param string      $directory  The directory where the log file should be stored.
     * @param string|null $name       Optional name for the log file (e.g., snapshot name).
     */
    public static function load(string $directory, ?string $name = null): void
    {
        // Only initialize once — subsequent calls are ignored
        if (self::$instance === null) {
            // Use default name if none provided
            $name = $name ?? 'tmp';

            // Create the singleton instance
            self::$instance = new self($directory, $name);
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
     * Renames the current log file using the final snapshot name.
     *
     * This method should be called after the actual snapshot name is known,
     * typically right after prepareSnapshot(). It updates the filename to match
     * the naming convention: {snapshotName}_{timestamp}.log.
     *
     * @param string $newName The final snapshot name to be used in the new filename.
     */
    public function renameLogFile(string $newName): void
    {
        // Generate a new timestamped filename using the provided snapshot name
        $timestamp = date('YmdHis');
        $this->logFileName = "{$newName}_{$timestamp}.log";

        // Determine the full path to the new log file
        $newPath = dirname($this->logFilePath) . '/' . $this->logFileName;

        // Rename the current file only if it exists
        if (file_exists($this->logFilePath)) {
            rename($this->logFilePath, $newPath);

            // Update the internal file path so future writes go to the renamed file
            $this->logFilePath = $newPath;
        }
    }

    /**
     * Returns the full path or name of the current log file in use.
     *
     * @return string The log file name.
     */
    public function getLogFileName(): string
    {
        return $this->logFileName;
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
    public function log(string $message, $toConsole = true): void
    {
        $this->write($message);
        if ($toConsole) echo $message;
    }

    /**
     * Logs a structured STEP message.
     *
     * @param string $message
     */
    public function logStep(string $message): void
    {
        $this->log("\n⚙️ [STEP] {$message}\n");
    }

    /**
     * Logs a command being run.
     *
     * @param string $message
     */
    public function logCmd(string $message): void
    {
        $this->log("➡️ [CMD] {$message}\n");
    }

    /**
     * Logs a warning message.
     *
     * @param string $message
     */
    public function logWarning(string $message): void
    {
        $this->log("⚠️ [WARNING] {$message}\n");
    }

    /**
     * Logs a success message.
     *
     * @param string $message
     */
    public function logSuccess(string $message): void
    {
        $this->log("✅ [SUCCESS] {$message}\n");
    }

    /**
     * Logs info message.
     *
     * @param string $message
     */
    public function logInfo(string $message): void
    {
        $this->log("💡 [INFO] {$message}\n");
    }

    /**
     * Logs an error message.
     *
     * @param string $message
     */
    public function logError(string $message): void
    {
        $this->log("❌ [ERROR] {$message}\n");
    }

    /**
     * Logs a dry-run skip message (🔇).
     *
     * @param string $message
     */
    public function logDryRun(string $message): void
    {
        $this->log("🔇 [DRY-RUN] {$message}\n");
    }
}
