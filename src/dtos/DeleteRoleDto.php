<?php

declare(strict_types=1);

namespace orange\acl\dtos;

use orange\dto\Dto;
use orange\dto\attributes\Column;
use orange\dto\attributes\Table;
use orange\dto\attributes\IsPrimary;
use orange\dto\attributes\Label;
use orange\dto\attributes\filters\ToInteger;
use orange\dto\attributes\validations\Integer;
use orange\dto\attributes\validations\IsRequired;

/**
 * The key, and nothing else - a delete needs no other field.
 */
class DeleteRoleDto extends Dto
{
    #[IsRequired]
    #[ToInteger]
    #[Integer]
    #[IsPrimary]
    #[Label('Id')]
    #[Column('id')]
    #[Table(AclTables::ROLES)]
    public protected(set) int $id;
}
