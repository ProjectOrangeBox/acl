<?php

declare(strict_types=1);

namespace orange\acl\interfaces;

use orange\acl\interfaces\PermissionModelInterface;

interface PermissionEntityInterface
{
    public function update(): bool;
    public function deactive(): bool;
    public function active(): bool;
}
