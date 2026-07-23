<?php

declare(strict_types=1);

namespace orange\acl\models;

use PDO;
use orange\model\Model;
use orange\acl\interfaces\ModelInterface;
use orange\validate\interfaces\ValidateInterface;

/**
 * Add all of the extra fluff data a user might have
 * that doesn't effect application operations to this class/model/table
 */
class UserMetaModel extends Model implements ModelInterface
{
    protected array $rules = [
        'id' => ['isRequired|integer', 'Id'],
        'phone' => ['allowEmpty'],
        'ext' => ['allowEmpty'],
        'dashboard_url' => ['allowEmpty'],
    ];
    protected array $ruleSets = [
        // unlike update/delete, create() runs before the row (and its id) exists -
        // the id is only known/set by the caller after the insert, so it can't be
        // required here
        'create' => ['dashboard_url', 'phone', 'ext'],
        'update' => ['id', 'dashboard_url', 'phone', 'ext'],
        'delete' => ['id'],
    ];

    public function __construct(array $config, PDO $pdo, ValidateInterface $validateService)
    {
        $this->tablename = $config['tablename'];

        $validateService->throwExceptionOnFailure(true);

        parent::__construct($config, $pdo, $validateService);

        $this->sql->throwExceptions(true);
    }

    public function create(array $columns): int
    {
        // throws on failure; returns only the validated whitelisted columns
        $fields = (array)$this->validateFields('create', $columns);

        // the row shares its primary key with the user row - assigned by the
        // caller (UserModel) after the user insert, so it rides along outside
        // the create ruleset
        $fields['id'] = (int)$columns['id'];

        return (int)$this->sql->insert()->into($this->tablename)->values($fields)->execute()->lastInsertId();
    }

    public function update(array $columns): bool
    {
        // throws on failure; returns only the validated whitelisted columns
        $fields = (array)$this->validateFields('update', $columns);

        // the primary key targets the WHERE - it is never a SET column
        $id = (int)$fields['id'];
        unset($fields['id']);

        // no meta fields provided (the caller only had user columns) -
        // nothing to update, and an empty SET isn't valid SQL
        if ($fields === []) {
            return false;
        }

        $this->sql->update($this->tablename)->set($fields)->where('id', '=', $id)->execute();

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
        $row = $this->sql->select()->from($this->tablename)->where('id', '=', $id)->execute()->row();

        return is_array($row) ? $row : [];
    }
}
