<?php
namespace App;

class Container
{
    private static ?self $instance = null;
    private array $factories = [];
    private array $services = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->services[$id]);
    }

    public function get(string $id): object
    {
        if (!isset($this->services[$id])) {
            if (!isset($this->factories[$id])) {
                throw new \RuntimeException("Service not registered: {$id}");
            }
            $this->services[$id] = ($this->factories[$id])($this);
        }
        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || isset($this->services[$id]);
    }

    public function reset(): void
    {
        $this->services = [];
        $this->factories = [];
        self::$instance = null;
    }
}
