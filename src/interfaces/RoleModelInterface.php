<?php

declare(strict_types=1);

namespace orange\acl\interfaces;

use orange\acl\interfaces\RoleEntityInterface;
use orange\acl\interfaces\PermissionEntityInterface;

interface RoleModelInterface
{
    public function create(array $columns): RoleEntityInterface;
    public function read(string|int $key): RoleEntityInterface;
    public function readAll(): array;
    public function update(array $columns): bool;
    public function delete(int $id): bool;

    public function deactivate(int $id): bool;
    public function activate(int $id): bool;

    public function relink(int $roleId, array $permissionIds): bool;
    public function addPermission(int $roleId, int|string|PermissionEntityInterface $arg): bool;
    public function removePermission(int $roleId, int|string|PermissionEntityInterface $arg): bool;
    public function removeAllPermissions(int $roleId): bool;
}
