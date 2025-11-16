<?php
    /**
     *  A registry for all the services used in the SDK
     */
    namespace LCMS\Core;

    use LCMS\Util\Singleton;

    class ServiceRegistry
    {
        use Singleton;

        private array $services = [];

        protected function add(string $interface, object $implementation): void
        {
            $this->services[$interface] = $implementation;
        }

        protected function get(string $interface): ?object
        {
            return $this->services[$interface] ?? null;
        }

        protected function has(string $interface): bool
        {
            return isset($this->services[$interface]);
        }
    }
?>