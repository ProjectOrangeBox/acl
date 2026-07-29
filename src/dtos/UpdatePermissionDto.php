<?php

declare(strict_types=1);

namespace orange\acl\dtos;

use orange\dto\Dto;
use orange\dto\attributes\Column;
use orange\dto\attributes\Table;
use orange\dto\attributes\IsPrimary;
use orange\dto\attributes\Label;
use orange\dto\attributes\filters\DefaultTo;
use orange\dto\attributes\filters\ToInteger;
use orange\dto\attributes\filters\Trim;
use orange\dto\attributes\validations\BetweenLength;
use orange\dto\attributes\validations\InList;
use orange\dto\attributes\validations\Integer;
use orange\dto\attributes\validations\IsRequired;

/**
 * An existing permission. The key targets the WHERE and is dropped from the SET
 * by asking for the columns withoutPrimary.
 */
class UpdatePermissionDto extends Dto
{
    #[IsRequired]
    #[ToInteger]
    #[Integer]
    #[IsPrimary]
    #[Label('Id')]
    #[Column('id')]
    #[Table(AclTables::PERMISSIONS)]
    public protected(set) int $id;

    #[Trim]
    #[IsRequired]
    #[BetweenLength(4, 255)]
    #[Label('Key')]
    #[Column('key')]
    #[Table(AclTables::PERMISSIONS)]
    public protected(set) string $key;

    #[Trim]
    #[IsRequired]
    #[BetweenLength(4, 512)]
    #[Label('Description')]
    #[Column('description')]
    #[Table(AclTables::PERMISSIONS)]
    public protected(set) string $description;

    #[Trim]
    #[IsRequired]
    #[BetweenLength(4, 128)]
    #[Label('Group')]
    #[Column('group')]
    #[Table(AclTables::PERMISSIONS)]
    public protected(set) string $group;

    #[DefaultTo(1)]
    #[ToInteger]
    #[InList([0, 1])]
    #[Label('Is Active')]
    #[Column('is_active')]
    #[Table(AclTables::PERMISSIONS)]
    public protected(set) int $is_active;
}
