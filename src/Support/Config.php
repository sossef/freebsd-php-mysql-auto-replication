<?php

namespace Monsefrachid\MysqlReplication\Support;

use Dotenv\Dotenv;

/**
 * Simple configuration loader using PHP dotenv.
 *
 * Loads environment variables from a `.env` file once per request
 * and provides access through a static `get()` method.
 */
class Config
{
     // Indicates whether the environment has already been loaded
    protected static bool $loaded = false;

    /**
     * Loads environment variables from the specified path (default is current directory).
     * Ensures the `.env` file is only loaded once per execution.
     *
     * @param string $envPath Directory containing the .env file.
     */
    public static function load(string $envPath = __DIR__): void
    {
        if (!self::$loaded) {
            // Initialize dotenv and load environment variables into $_ENV
            $dotenv = Dotenv::createImmutable($envPath);
            $dotenv->load();
            self::$loaded = true;
        }
    }

     /**
     * Retrieves the value of an environment variable.
     *
     * @param string $key     The name of the environment variable.
     * @param mixed  $default The fallback value if the key is not set.
     * @return mixed The value of the environment variable or the default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $default;
    }
}
