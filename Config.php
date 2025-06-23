<?php

use Dotenv\Dotenv;

class Config
{
    protected static bool $loaded = false;

    public static function load(string $envPath = __DIR__): void
    {
        if (!self::$loaded) {
            $dotenv = Dotenv::createImmutable($envPath);
            $dotenv->load();
            self::$loaded = true;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $default;
    }
}
