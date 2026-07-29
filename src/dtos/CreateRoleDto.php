<?php

declare(strict_types=1);

namespace orange\acl\dtos;

use orange\dto\Dto;
use orange\dto\attributes\Column;
use orange\dto\attributes\Table;
use orange\dto\attributes\Label;
use orange\dto\attributes\filters\DefaultTo;
use orange\dto\attributes\filters\ToInteger;
use orange\dto\attributes\filters\Trim;
use orange\dto\attributes\validations\BetweenLength;
use orange\dto\attributes\validations\InList;
use orange\dto\attributes\validations\IsRequired;

/**
 * A new role.
 *
 * Every column names its table, as all of them now do - see {@see AclTables} for
 * why that is a constant rather than configuration, and what overriding it takes.
 *
 * Uniqueness of the name is not expressible as an attribute (a Dto has no
 * database handle) and lives in RoleModel, backed by the table's UNIQUE index.
 */
class CreateRoleDto extends Dto
{
    #[Trim]
    #[IsRequired]
    #[BetweenLength(4, 128)]
    #[Label('Name')]
    #[Column('name')]
    #[Table(AclTables::ROLES)]
    public protected(set) string $name;

    #[Trim]
    #[IsRequired]
    #[BetweenLength(4, 512)]
    #[Label('Description')]
    #[Column('description')]
    #[Table(AclTables::ROLES)]
    public protected(set) string $description;

    // unlike a user, a role is active unless the caller says otherwise
    #[DefaultTo(1)]
    #[ToInteger]
    #[InList([0, 1])]
    #[Label('Is Active')]
    #[Column('is_active')]
    #[Table(AclTables::ROLES)]
    public protected(set) int $is_active;
}
