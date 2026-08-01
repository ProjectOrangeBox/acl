<?php

declare(strict_types=1);

namespace orange\acl\interfaces;

use orange\acl\interfaces\RoleEntityInterface;

interface UserEntityInterface
{
    /**
     * Identity. UserEntity has always declared these as real properties; they
     * were simply missing from this interface, so anything typed against the
     * interface - which is what a consumer should type against - could not read
     * an id or a username without PHPStan calling it an undefined property.
     *
     * id is read-only from outside: it is the database's to assign, and the
     * entity declares it protected(set).
     */
    public int $id { get; }
    public string $username {
        get;
        set;
    }
    public string $email {
        get;
        set;
    }

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
    /**
     * @param array<array-key, string> $roles
     */
    public function hasRoles(array $roles): bool;
    /**
     * @param array<array-key, string> $roles
     */
    public function hasOneRoleOf(array $roles): bool;
    /**
     * @param array<array-key, string> $permissions
     */
    public function hasPermissions(array $permissions): bool;
    /**
     * @param array<array-key, string> $permissions
     */
    public function hasOnePermissionOf(array $permissions): bool;
    public function hasPermission(string $permission): bool;
    public function cannot(string $permission): bool;

    public function __set(string $name, mixed $value): void;
    public function __get(string $name): mixed;
    public function loggedIn(): bool;
    public function isAdmin(): bool;
    public function isGuest(): bool;
}
