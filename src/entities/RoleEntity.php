<?php

declare(strict_types=1);

namespace orange\acl\entities;

use orange\acl\interfaces\PermissionEntityInterface;
use orange\acl\interfaces\RoleEntityInterface;
use orange\acl\interfaces\RoleModelInterface;

class RoleEntity implements RoleEntityInterface
{
    protected RoleModelInterface $roleModel;
    protected array $config = [];

    public readonly int $id;
    // short name of role
    public string $name;
    // description of role
    public string $description;
    // migration which added the role
    public readonly ?string $migration;
    // if the role is active or not
    public readonly int $is_active;

    public function __construct(array $config, RoleModelInterface $roleModel)
    {
        $this->config = $config;
        $this->roleModel = $roleModel;
    }

    public function update(): bool
    {
        // get the public columns from the entity
        $columns = get_object_vars(...)->__invoke($this);

        return $this->roleModel->update($columns);
    }

    public function deactivate(): bool
    {
        return $this->roleModel->deactivate($this->id);
    }

    public function activate(): bool
    {
        return $this->roleModel->activate($this->id);
    }

    public function addPermission(int|string|PermissionEntityInterface $arg): bool
    {
        return $this->roleModel->addPermission($this->id, $arg);
    }

    public function removePermission(int|string|PermissionEntityInterface $arg): bool
    {
        return $this->roleModel->removePermission($this->id, $arg);
    }

    public function removeAllPermissions(): bool
    {
        return $this->roleModel->removeAllPermissions($this->id);
    }
}
