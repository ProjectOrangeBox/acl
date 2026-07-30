<?php

declare(strict_types=1);

namespace orange\acl\interfaces;

interface ModelInterface
{
    /**
     * @param array<string, mixed> $columns
     */
    public function create(array $columns): int;
    /**
     * @return array<array-key, mixed>
     */
    public function read(int $id): array;
    /**
     * @param array<string, mixed> $columns
     */
    public function update(array $columns): bool;
    public function delete(int $id): bool;
}
