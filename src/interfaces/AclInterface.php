<?php

declare(strict_types=1);

namespace orange\acl\interfaces;

use orange\acl\interfaces\RoleEntityInterface;
use orange\acl\interfaces\UserEntityInterface;
use orange\acl\interfaces\PermissionEntityInterface;

interface AclInterface
{
    public function createUser(string $username, string $email, string $password, array $fields = []): UserEntityInterface;
    public function getUser(int $userId): UserEntityInterface;

    public function createRole(string $name, string $description): RoleEntityInterface;
    public function getRole(string|int $arg): RoleEntityInterface;

    public function createPermission(string $key, string $description, string $group): PermissionEntityInterface;
    public function getPermission(string|int $arg): PermissionEntityInterface;
}
