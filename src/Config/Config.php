<?php

namespace Ado\Config;

class Config
{
    private static string $path = '';
    private array $data = [];

    public function __construct()
    {
        self::$path = $_SERVER['HOME'] . '/.ado/config.json';
        $this->load();
    }

    private function load(): void
    {
        if (file_exists(self::$path)) {
            $this->data = json_decode(file_get_contents(self::$path), true) ?? [];
        }
    }

    public function save(): void
    {
        $dir = dirname(self::$path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents(self::$path, json_encode($this->data, JSON_PRETTY_PRINT) . PHP_EOL);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function all(): array
    {
        return $this->data;
    }

    public function isConfigured(): bool
    {
        return !empty($this->data['org']) && !empty($this->data['pat']);
    }

    public static function configPath(): string
    {
        return self::$path ?: ($_SERVER['HOME'] . '/.ado/config.json');
    }
}
