<?php

declare(strict_types=1);

namespace orange\acl\interfaces;

use orange\acl\interfaces\PermissionEntityInterface;

interface PermissionModelInterface
{
    /**
     * @param array<string, mixed> $columns
     */
    public function create(array $columns): PermissionEntityInterface;
    /**
     */
    public function read(string|int $key): PermissionEntityInterface;
    /**
     * @return array<array-key, mixed>
     */
    public function readAll(): array;
    /**
     * @param array<string, mixed> $columns
     */
    public function update(array $columns): bool;
    public function delete(int $id): bool;

    public function deactivate(int $id): bool;
    public function activate(int $id): bool;
}
