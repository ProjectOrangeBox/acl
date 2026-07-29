<?php

declare(strict_types=1);

namespace orange\acl\dtos;

/**
 * The tables this package reads and writes, named once.
 *
 * These used to be configuration - six 'user table' style keys that could be set
 * to anything. They are constants now because the configuration could never be
 * honoured all the way down: a Dto declares which table a column belongs to with
 * #[Table(...)], and an attribute takes a constant expression, so it cannot read
 * a config value. A renamed table therefore agreed with the models and disagreed
 * with the Dtos, and the mismatch surfaced as a confusing "has no columns for
 * table" on the first write. Half-configurable is worse than not configurable:
 * it reads as a supported option and isn't one.
 *
 * Overriding a table name now means subclassing, which keeps the model and the
 * Dto in step because both are stated in the same place:
 *
 *     class MyUserDto extends CreateUserDto {
 *         #[IsRequired] #[BetweenLength(4, 64)]
 *         #[Column('username')] #[Table('my_users')]
 *         public protected(set) string $username;
 *         // ... restate the rest
 *     }
 *
 *     class MyUserModel extends UserModel {
 *         protected string $tablename = 'my_users';
 *         protected array $dtos = ['create' => MyUserDto::class, ...];
 *     }
 *
 * and pointing 'userModel' at the subclass. More work than a config key - and
 * honest about the fact that the two halves have to agree.
 */
final class AclTables
{
    public const string USERS = 'orange_users';
    public const string META = 'orange_user_meta';
    public const string ROLES = 'orange_roles';
    public const string PERMISSIONS = 'orange_permissions';

    /* join tables - no Dto writes these, the models link rows directly */
    public const string USER_ROLE = 'orange_user_role';
    public const string ROLE_PERMISSION = 'orange_role_permission';

    /**
     * Config keys this package used to accept, and no longer does.
     *
     * Acl's constructor rejects them by name rather than ignoring them: an
     * upgrade that silently stopped honouring a table name would point every
     * query at the wrong table, and the first sign of it would be data written
     * somewhere unexpected.
     *
     * @return array<int, string>
     */
    public static function removedConfigKeys(): array
    {
        return [
            'user table',
            'user meta table',
            'role table',
            'permission table',
            'user role table',
            'role permission table',
        ];
    }
}
