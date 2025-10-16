<?php
declare(strict_types=1);

namespace App\Support;

class Container
{
    private array $bindings = [];
    private array $instances = [];

    public function set(string $id, callable $factory): void
    {
        $this->bindings[$id] = $factory;
    }

    /**
     * @throws \ReflectionException
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) { return $this->instances[$id]; }

        if (!isset($this->bindings[$id])) {
            $ref = new \ReflectionClass($id);
            $ctor = $ref->getConstructor();
            if (!$ctor || !$ctor->getParameters()) return $this->instances[$id] = new $id();
            $deps = [];
            foreach ($ctor->getParameters() as $p) {
                $type = $p->getType();
                if (!$type || $type->isBuiltin()) {
                    throw new \RuntimeException("Cannot resolve $id dependency {$p->getName()}");
                }
                $deps[] = $this->get($type->getName());
            }
            return $this->instances[$id] = $ref->newInstanceArgs($deps);
        }
        return $this->instances[$id] = ($this->bindings[$id])($this);
    }
}
