<?php

declare(strict_types=1);

namespace orange\acl\interfaces;

interface PermissionEntityInterface
{
    // readable only - the id comes from the database row, never from a caller
    public int $id { get; }

    public function update(): bool;
    public function deactivate(): bool;
    public function activate(): bool;
}
