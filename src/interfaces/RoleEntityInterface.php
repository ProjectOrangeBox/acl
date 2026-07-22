<?php

declare(strict_types=1);

namespace orange\acl\interfaces;

use orange\acl\interfaces\RoleModelInterface;
use orange\acl\interfaces\PermissionEntityInterface;

interface RoleEntityInterface
{
    public function update(): bool;
    public function deactive(): bool;
    public function active(): bool;
    public function addPermission(int|string|PermissionEntityInterface $arg): bool;
    public function removePermission(int|string|PermissionEntityInterface $arg): bool;
    public function removeAllPermissions(): bool;
}
