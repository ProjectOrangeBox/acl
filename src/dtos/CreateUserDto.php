<?php

declare(strict_types=1);

namespace orange\acl\dtos;

use orange\dto\Dto;
use orange\dto\attributes\Column;
use orange\dto\attributes\Label;
use orange\dto\attributes\Table;
use orange\dto\attributes\filters\DefaultTo;
use orange\dto\attributes\filters\ToInteger;
use orange\dto\attributes\filters\ToLower;
use orange\dto\attributes\filters\Trim;
use orange\dto\attributes\validations\BetweenLength;
use orange\dto\attributes\validations\InList;
use orange\dto\attributes\validations\IsRequired;

/**
 * Everything createUser() accepts, across both tables it writes.
 *
 * The user columns are tagged #[Table(AclTables::USERS)] and the meta columns
 * #[Table(AclTables::META)], so UserModel and UserMetaModel each take their own
 * share of one validated result - which is why there is no second Dto for the
 * meta half and no merging of two error sets. A field failing on either side
 * fails the whole create, reported together.
 *
 * Note the table names are attribute literals, so a deployment that renames the
 * ACL tables in config must also supply Dto subclasses restating them; Acl's
 * constructor checks the two agree and says so plainly if they do not.
 *
 * Lengths use BetweenLength rather than a MinLength + MaxLength pair simply
 * because every bound here has both ends - one rule reads better than two and
 * gives one message instead of two. The three are equivalent: all of orange/dto's
 * bounded rules are inclusive.
 *
 * There is no is_deleted here, and no id - a create assigns both.
 */
class CreateUserDto extends Dto
{
    #[Trim]
    #[IsRequired]
    #[BetweenLength(4, 64)]
    #[Label('User Name')]
    #[Column('username')]
    #[Table(AclTables::USERS)]
    public protected(set) string $username;

    // stored trimmed and lowercased so a credential lookup never depends on the
    // database collation - orange/auth normalizes the submitted login the same
    // way before looking it up
    #[Trim]
    #[ToLower]
    #[IsRequired]
    #[BetweenLength(4, 255)]
    #[Label('Email')]
    #[Column('email')]
    #[Table(AclTables::USERS)]
    public protected(set) string $email;

    /**
     * The plaintext. UserModel hashes it after validation, so the rules judge
     * what the user actually typed rather than a fixed-length hash.
     *
     * 72 is bcrypt's own limit: it reads no more than that and silently ignores
     * the rest, so a longer password would let any string sharing its first 72
     * bytes authenticate. BetweenLength measures bytes (strlen), which is the
     * right unit for a byte limit.
     */
    #[IsRequired]
    #[BetweenLength(10, 72)]
    #[Label('Password')]
    #[Column('password')]
    #[Table(AclTables::USERS)]
    public protected(set) string $password;

    // a new account is inactive unless the caller says otherwise
    #[DefaultTo(0)]
    #[ToInteger]
    #[InList([0, 1])]
    #[Label('Is Active')]
    #[Column('is_active')]
    #[Table(AclTables::USERS)]
    public protected(set) int $is_active;

    /* the meta half - all optional, all free text */

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
