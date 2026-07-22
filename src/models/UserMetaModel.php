<?php

declare(strict_types=1);

namespace orange\acl\models;

use PDO;
use orange\model\Model;
use orange\acl\interfaces\ModelInterface;
use orange\validate\interfaces\ValidateInterface;
use orange\acl\exceptions\RecordNotFoundException;

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
        // throws an exception
        $this->validateFields('create', $columns);

        // the caller (UserModel) hands us a copy of its own full column set, not
        // just ours - only pass through columns this table actually has
        $columns = array_intersect_key($columns, array_flip(['id', 'dashboard_url', 'phone', 'ext']));

        return $this->sql->insert()->into($this->tablename)->values($columns)->execute()->lastInsertId();
    }

    public function update(array $columns): bool
    {
        // throws an exception
        $this->validateFields('update', $columns);

        // the caller (UserModel) hands us a copy of its own full column set, not
        // just ours - only pass through columns this table actually has
        $columns = array_intersect_key($columns, array_flip(['id', 'dashboard_url', 'phone', 'ext']));

        $this->sql->update($this->tablename)->set($columns)->where('id', '=', $columns['id'])->execute();

        return true;
    }

    public function delete(int $id): bool
    {
        // throws an exception
        $this->validateFields('delete', ['id' => $id]);

        $this->sql->update($this->tablename)->set(['is_deleted' => 0])->where('id', '=', $id)->execute();

        return true;
    }

    public function read(int $id): array
    {
        if ($this->sql->select()->from($this->tablename)->where('id', '=', $id)->execute()->rowCount() > 0) {
            $array = $this->sql->row();
        } else {
            throw new RecordNotFoundException('User Meta Record ' . $id);
        }

        return $array;
    }
}
