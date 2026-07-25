<?php

declare(strict_types=1);

namespace orange\acl\entities;

use Exception;
use orange\acl\interfaces\UserModelInterface;
use orange\acl\interfaces\RoleEntityInterface;
use orange\acl\interfaces\UserEntityInterface;

class UserEntity implements UserEntityInterface
{
    public protected(set) int $id;
    // users name
    public string $username;
    // users email
    public string $email;
    // if the user is active or not
    public protected(set) int $is_active;
    // soft delete user
    public protected(set) int $is_deleted;
    // users password hash - declared so the column has somewhere to land during
    // FETCH_CLASS hydration (an undeclared column would hit __set and throw).
    // deliberately not public: update() only ships public properties, and the
    // hash must never be readable from outside the entity hierarchy
    protected string $password;

    protected array $permissions = [];
    protected array $roles = [];
    protected array $meta = [];

    protected bool $lazyLoaded = false;

    // PDO assigns the row's columns before it calls the constructor, so __set
    // needs to tell "still hydrating, no model to load through yet" apart from
    // "ready to use"
    protected bool $hydrated = false;

    public function __construct(protected array $config, protected UserModelInterface $userModel)
    {
        $this->hydrated = true;
    }

    public function update(): bool
    {
        // combined meta & local properties
        // get the public columns from the entity
        $columns = get_object_vars(...)->__invoke($this) + $this->meta;

        return $this->userModel->update($columns);
    }

    public function updatePassword(string $newPassword): bool
    {
        return $this->userModel->updatePassword($this->id, $newPassword);
    }

    public function deactivate(): bool
    {
        return $this->userModel->deactivate($this->id);
    }

    public function activate(): bool
    {
        return $this->userModel->activate($this->id);
    }

    public function addRole(string|int|RoleEntityInterface $arg): bool
    {
        return $this->userModel->addRole($this->id, $arg);
    }

    public function removeRole(string|int|RoleEntityInterface $arg): bool
    {
        return $this->userModel->removeRole($this->id, $arg);
    }

    public function removeAllRoles(): bool
    {
        return $this->userModel->removeAllRoles($this->id);
    }

    /* access */
    public function can(string $permission): bool
    {
        $this->lazyLoad();

        return (in_array($permission, $this->permissions, true));
    }

    /**
     * By role id or role name - roles are held as [id => name].
     */
    public function hasRole(int|string $role): bool
    {
        $this->lazyLoad();

        if (is_int($role)) {
            return array_key_exists($role, $this->roles);
        }

        return in_array($role, $this->roles, true);
    }

    public function hasRoles(array $roles): bool
    {
        return array_all($roles, fn($r) => $this->hasRole($r));
    }

    public function hasOneRoleOf(array $roles): bool
    {
        return array_any($roles, fn($r) => $this->hasRole($r));
    }

    public function hasPermissions(array $permissions): bool
    {
        return array_all($permissions, fn($p) => !$this->cannot($p));
    }

    public function hasOnePermissionOf(array $permissions): bool
    {
        return array_any($permissions, fn($p) => $this->can($p));
    }

    public function hasPermission(string $permission): bool
    {
        return $this->can($permission);
    }

    public function cannot(string $permission): bool
    {
        return !$this->can($permission);
    }

    public function loggedIn(): bool
    {
        return $this->id != $this->config['guest user'];
    }

    public function isAdmin(): bool
    {
        return $this->hasRole((int)$this->config['admin role']);
    }

    public function isGuest(): bool
    {
        return $this->id == $this->config['guest user'];
    }

    // meta
    public function __set(string $name, mixed $value): void
    {
        // meta must be loaded before we can know whether $name is a meta
        // field - but during PDO's FETCH_CLASS property assignment the
        // constructor hasn't run yet, so there is no model to load through;
        // an unknown DB column then correctly reads as an unknown property
        if ($this->hydrated) {
            $this->lazyLoad();
        }

        if (array_key_exists($name, $this->meta)) {
            $this->meta[$name] = $value;
        } else {
            throw new Exception('Unknown property "' . self::class . '::$' . $name . '".');
        }
    }

    // meta and easier access to a few others
    public function __get(string $name): mixed
    {
        $this->lazyLoad();

        if (array_key_exists($name, $this->meta)) {
            $return = $this->meta[$name];
        } else {
            $return = match (strtolower($name)) {
                'loggedin' => $this->loggedIn(),
                'isadmin' => $this->isAdmin(),
                'isguest' => $this->isGuest(),
                'isactive' => $this->is_active == 1,
                default => throw new Exception('Undefined property "' . self::class . '::$' . $name . '".'),
            };
        }

        return $return;
    }

    // internal use
    protected function lazyLoad(): void
    {
        if (!$this->lazyLoaded) {
            $access = $this->userModel->getRolesPermissions($this->id);

            $this->permissions = $access['permissions'] ?? [];
            $this->roles = $access['roles'] ?? [];

            $this->meta = $this->userModel->getMeta($this->id);

            $this->lazyLoaded = true;
        }
    }
}
