<?php

declare(strict_types=1);

namespace orange\acl\models;

use PDO;
use orange\acl\dtos\AclTables;
use orange\acl\dtos\DeleteUserDto;
use orange\acl\interfaces\ModelInterface;

/**
 * The extra per-user data that doesn't affect how the application behaves -
 * phone, extension, dashboard url - kept in its own table so the user row stays
 * the credential record.
 *
 * This model no longer validates its own create and update. The columns it
 * writes are declared on the same Dto as the user columns, tagged
 * #[Table('orange_user_meta')], so UserModel validates the whole form once and
 * hands each table its share. That is what lets a bad phone number and a short
 * password be reported together instead of one rule set at a time - and it is
 * why create() and update() here take columns that are already checked.
 *
 * UserModel owns this model entirely; nothing else is expected to call it.
 *
 * @method static static getInstance(array $config, PDO $pdo)
 * @method static static newInstance(array $config, PDO $pdo)
 */
class UserMetaModel extends AclModel implements ModelInterface
{
    protected string $tablename = AclTables::META;

    /**
     * Only delete stands on its own - it takes an id and nothing else, so it can
     * check that id without the rest of the form.
     */
    protected array $dtos = [
        'delete' => DeleteUserDto::class,
    ];

    public function __construct(array $config, PDO $pdo)
    {
        parent::__construct($config, $pdo);

        $this->sql->throwExceptions(true);
    }

    /**
     * @param array<string, mixed> $columns Validated meta columns, including the
     *        'id' the user row was just assigned
     */
    public function create(array $columns): int
    {
        // the row shares its primary key with the user row, so the caller sets
        // it from the insert it just did
        return (int)$this->sql->insert()->into($this->tablename)->values($columns)->execute()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $columns Validated meta columns, including 'id'
     */
    public function update(array $columns): bool
    {
        // the primary key targets the WHERE - it is never a SET column
        $id = (int)$columns['id'];
        unset($columns['id']);

        // no meta columns to write - nothing to do, and an empty SET isn't
        // valid SQL
        if ($columns === []) {
            return false;
        }

        $this->sql->update($this->tablename)->set($columns)->where('id', '=', $id)->execute();

        return $this->sql->rowCount() > 0;
    }

    /**
     * Hard delete - the meta table carries no soft-delete flag; the user
     * row's own is_deleted covers the pair.
     */
    public function delete(int $id): bool
    {
        // throws an exception
        $this->validateFields('delete', ['id' => $id]);

        $this->sql->delete()->from($this->tablename)->where('id', '=', $id)->execute();

        return $this->sql->rowCount() > 0;
    }

    /**
     * A user without a meta row (seeded outside createUser(), say) is not an
     * error - meta is optional by design, so absence reads as [].
     */
    public function read(int $id): array
    {
        // rowCount() after a SELECT isn't reliable across PDO drivers - check
        // the fetched row itself instead
        $row = $this->sql->setFetchMode(PDO::FETCH_ASSOC)->select()->from($this->tablename)->where('id', '=', $id)->execute()->row();

        return is_array($row) ? $row : [];
    }
}
