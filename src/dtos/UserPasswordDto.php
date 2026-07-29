<?php

declare(strict_types=1);

namespace orange\acl\dtos;

use orange\dto\Dto;
use orange\dto\attributes\Column;
use orange\dto\attributes\Label;
use orange\dto\attributes\Table;
use orange\dto\attributes\validations\BetweenLength;
use orange\dto\attributes\validations\IsRequired;

/**
 * A password change on its own, holding the same policy a create does - a
 * change must not be a way around the rules an account was created under.
 *
 * The plaintext: UserModel hashes after validation. See CreateUserDto::$password
 * for why the ceiling is 72 bytes.
 */
class UserPasswordDto extends Dto
{
    #[IsRequired]
    #[BetweenLength(10, 72)]
    #[Label('Password')]
    #[Column('password')]
    #[Table(AclTables::USERS)]
    public protected(set) string $password;
}
