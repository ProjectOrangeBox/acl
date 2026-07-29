<?php

declare(strict_types=1);

namespace orange\acl\interfaces;

use orange\acl\interfaces\RoleEntityInterface;

interface UserEntityInterface
{
    /**
     * Account state, readable but not writable from outside the entity - the
     * session-aware {@see \orange\acl\User} helper reads these to decide whether
     * a stored session still names a usable account. Changing them goes through
     * activate()/deactivate() and the model's soft delete, never a direct write.
     */
    public int $is_active { get; }
    public int $is_deleted { get; }

    public function update(): bool;
    public function updatePassword(string $newPassword): bool;
    public function deactivate(): bool;
    public function activate(): bool;
    public function addRole(string|int|RoleEntityInterface $arg): bool;
    public function removeRole(string|int|RoleEntityInterface $arg): bool;
    public function removeAllRoles(): bool;

    /* access */
    public function can(string $permission): bool;
    public function hasRole(int|string $role): bool;
    public function hasRoles(array $roles): bool;
    public function hasOneRoleOf(array $roles): bool;
    public function hasPermissions(array $permissions): bool;
    public function hasOnePermissionOf(array $permissions): bool;
    public function hasPermission(string $permission): bool;
    public function cannot(string $permission): bool;

    public function __set(string $name, mixed $value): void;
    public function __get(string $name): mixed;
    public function loggedIn(): bool;
    public function isAdmin(): bool;
    public function isGuest(): bool;
}
