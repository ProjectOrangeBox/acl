<?php

declare(strict_types=1);

namespace orange\acl\dtos;

use orange\dto\Dto;
use orange\dto\attributes\Column;
use orange\dto\attributes\FieldName;
use orange\dto\attributes\IsPrimary;
use orange\dto\attributes\Label;
use orange\dto\attributes\Table;
use orange\dto\attributes\filters\DefaultTo;
use orange\dto\attributes\filters\ToInteger;
use orange\dto\attributes\filters\ToLower;
use orange\dto\attributes\filters\Trim;
use orange\dto\attributes\validations\BetweenLength;
use orange\dto\attributes\validations\InList;
use orange\dto\attributes\validations\Integer;
use orange\dto\attributes\validations\IsRequired;

/**
 * Everything an update accepts, across the user row and its meta row.
 *
 * No password: changing one is its own operation with its own re-authentication
 * story, so it goes through updatePassword() and {@see UserPasswordDto} instead
 * of riding along in a general field update.
 *
 * An update sends the whole record rather than only the keys the caller
 * happened to include - a field left out takes its declared default. That is
 * the shape UserEntity::update() has always sent, and it makes what an update
 * writes a property of the Dto rather than of the caller's array.
 */
class UpdateUserDto extends Dto
{
    #[IsRequired]
    #[ToInteger]
    #[Integer]
    #[IsPrimary]
    #[Label('Id')]
    #[Column('id')]
    #[Table(AclTables::USERS)]
    public protected(set) int $id;

    /**
     * The meta row shares the user row's primary key, so this reads the same
     * 'id' input as above and gives the meta table a key of its own to be
     * updated by - each table carries its own #[IsPrimary].
     */
    #[FieldName('id')]
    #[IsRequired]
    #[ToInteger]
    #[Integer]
    #[IsPrimary]
    #[Label('Id')]
    #[Column('id')]
    #[Table(AclTables::META)]
    public protected(set) int $metaId;

    #[Trim]
    #[IsRequired]
    #[BetweenLength(4, 64)]
    #[Label('User Name')]
    #[Column('username')]
    #[Table(AclTables::USERS)]
    public protected(set) string $username;

    #[Trim]
    #[ToLower]
    #[IsRequired]
    #[BetweenLength(4, 255)]
    #[Label('Email')]
    #[Column('email')]
    #[Table(AclTables::USERS)]
    public protected(set) string $email;

    #[DefaultTo(0)]
    #[ToInteger]
    #[InList([0, 1])]
    #[Label('Is Active')]
    #[Column('is_active')]
    #[Table(AclTables::USERS)]
    public protected(set) int $is_active;

    /* the meta half */

    #[Trim]
    #[Label('Dashboard Url')]
    #[Column('dashboard_url')]
    #[Table(AclTables::META)]
    public protected(set) string $dashboard_url = '';

    #[Trim]
    #[Label('Phone')]
    #[Column('phone')]
    #[Table(AclTables::META)]
    public protected(set) string $phone = '';

    #[Trim]
    #[Label('Ext')]
    #[Column('ext')]
    #[Table(AclTables::META)]
    public protected(set) string $ext = '';
}
