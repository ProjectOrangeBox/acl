<?php

declare(strict_types=1);

namespace orange\acl\interfaces;

interface PermissionEntityInterface
{
    public function update(): bool;
    public function deactivate(): bool;
    public function activate(): bool;
}
