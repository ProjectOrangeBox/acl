<?php

declare(strict_types=1);

namespace orange\acl\interfaces;

use orange\acl\interfaces\RoleEntityInterface;
use orange\acl\interfaces\UserEntityInterface;

interface UserModelInterface
{
    /**
     * @param array<string, mixed> $columns
     */
    public function create(array $columns): UserEntityInterface;
    /**
     */
    public function read(int $userId): UserEntityInterface;
    /**
     * @return array<array-key, mixed>
     */
    public function readAll(): array;
    /**
     * @param array<string, mixed> $columns
     */
    public function update(array $columns): bool;
    public function updatePassword(int $id, string $password): bool;
    public function delete(int $id): bool;

    public function deactivate(int $id): bool;
    public function activate(int $id): bool;

    /**
     * @param array<array-key, int|string> $roleIds
     */
    public function relink(int $userId, array $roleIds): bool;
    public function addRole(int $userId, string|int|RoleEntityInterface $arg): bool;
    public function removeRole(int $userId, string|int|RoleEntityInterface $arg): bool;
    public function removeAllRoles(int $userId): bool;

    /**
     * @return array<array-key, mixed>
     */
    public function getRolesPermissions(int $userId): array;
    /**
     * @return array<string, mixed>
     */
    public function getMeta(int $userId): array;
}
