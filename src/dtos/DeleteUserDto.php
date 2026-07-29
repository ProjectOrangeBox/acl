<?php

declare(strict_types=1);

namespace orange\acl\dtos;

use orange\dto\Dto;
use orange\dto\attributes\Column;
use orange\dto\attributes\FieldName;
use orange\dto\attributes\IsPrimary;
use orange\dto\attributes\Label;
use orange\dto\attributes\Table;
use orange\dto\attributes\filters\ToInteger;
use orange\dto\attributes\validations\Integer;
use orange\dto\attributes\validations\IsRequired;

/**
 * The key, and nothing else - a delete needs no other field.
 *
 * Tagged for both tables so either model can validate through it: UserModel
 * flags is_deleted on the user row, UserMetaModel hard-deletes the meta row.
 */
class DeleteUserDto extends Dto
{
    #[IsRequired]
    #[ToInteger]
    #[Integer]
    #[IsPrimary]
    #[Label('Id')]
    #[Column('id')]
    #[Table(AclTables::USERS)]
    public protected(set) int $id;

    #[FieldName('id')]
    #[IsRequired]
    #[ToInteger]
    #[Integer]
    #[IsPrimary]
    #[Label('Id')]
    #[Column('id')]
    #[Table(AclTables::META)]
    public protected(set) int $metaId;
}
