<?php

declare(strict_types=1);

namespace orange\acl\entities;

use orange\acl\interfaces\PermissionEntityInterface;
use orange\acl\interfaces\PermissionModelInterface;

class PermissionEntity implements PermissionEntityInterface
{
    public protected(set) int $id;
    // unique key identifying the permission
    public string $key;
    // human readable description
    public string $description;
    // grouping for the permission
    public string $group;
    // migration which added the permission
    public protected(set) ?string $migration = null;
    // if the permission is active or not
    public protected(set) int $is_active;

    public function __construct(protected array $config, protected PermissionModelInterface $permissionModel)
    {
    }

    public function update(): bool
    {
        // get the public columns from the entity
        $columns = get_object_vars(...)->__invoke($this);

        return $this->permissionModel->update($columns);
    }

    public function deactivate(): bool
    {
        return $this->permissionModel->deactivate($this->id);
    }

    public function activate(): bool
    {
        return $this->permissionModel->activate($this->id);
    }
}
