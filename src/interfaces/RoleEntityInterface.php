<?php

declare(strict_types=1);

namespace orange\acl\interfaces;

use orange\acl\interfaces\PermissionEntityInterface;

interface RoleEntityInterface
{
    public function update(): bool;
    public function deactivate(): bool;
    public function activate(): bool;
    public function addPermission(int|string|PermissionEntityInterface $arg): bool;
    public function removePermission(int|string|PermissionEntityInterface $arg): bool;
    public function removeAllPermissions(): bool;
}
