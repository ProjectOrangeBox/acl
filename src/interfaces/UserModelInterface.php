<?php

declare(strict_types=1);

namespace orange\acl\interfaces;

use orange\acl\interfaces\RoleEntityInterface;
use orange\acl\interfaces\UserEntityInterface;

interface UserModelInterface
{
    public function create(array $columns): UserEntityInterface;
    public function read(int $userId): UserEntityInterface;
    public function readAll(): array;
    public function update(array $columns): bool;
    public function updatePassword(int $id, string $password): bool;
    public function delete(int $id): bool;

    public function deactivate(int $id): bool;
    public function activate(int $id): bool;

    public function relink(int $userId, array $roleIds): bool;
    public function addRole(int $userId, string|int|RoleEntityInterface $arg): bool;
    public function removeRole(int $userId, string|int|RoleEntityInterface $arg): bool;
    public function removeAllRoles(int $userId): bool;

    public function getRolesPermissions(int $userId): array;
    public function getMeta(int $userId): array;
}
