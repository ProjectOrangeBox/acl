<?php

declare(strict_types=1);

namespace orange\acl\models;

use PDO;
use Throwable;
use orange\model\Model;
use orange\acl\entities\PermissionEntity;
use orange\validate\interfaces\ValidateInterface;
use orange\acl\exceptions\RecordNotFoundException;
use orange\acl\interfaces\PermissionModelInterface;
use orange\acl\interfaces\PermissionEntityInterface;

class PermissionModel extends Model implements PermissionModelInterface
{
    // the full merged acl config - Model's own constructor slims $this->config
    // down to the sql-related keys
    protected array $aclConfig = [];

    protected string $tableJoin;

    protected array $rules = [
        'id' => ['isRequired|integer', 'Id'],
        'key' => ['isRequired|minLength[4]|maxLength[255]|isUnique[%s,key,id,pdo]', 'Key'],
        'description' => ['isRequired|minLength[4]|maxLength[512]', 'Description'],
        'group' => ['isRequired|minLength[4]|maxLength[128]', 'Group'],
        'is_active' => ['ifEmpty[1]|isOneOf[0,1]', 'Is Active'],
    ];
    protected array $ruleSets = [
        'create' => ['key', 'description', 'group', 'is_active'],
        'update' => ['id', 'key', 'description', 'group', 'is_active'],
        'delete' => ['id'],
    ];

    public function __construct(array $config, PDO $pdo, ValidateInterface $validateService)
    {
        $this->aclConfig = $config;

        $this->entityClass = $config['PermissionEntityClass'] ?? PermissionEntity::class;

        $config['tablename'] = $this->tablename = $config['permission table'];

        $this->rules['key'][0] = sprintf($this->rules['key'][0], $this->tablename);

        $this->tableJoin = $config['role permission table'];

        $validateService->throwExceptionOnFailure(true);

        parent::__construct($config, $pdo, $validateService);

        $this->sql->throwExceptions(true);
    }

    public function create(array $columns): PermissionEntityInterface
    {
        // validateFields() throws on failure and returns only the validated,
        // whitelisted columns - nothing else reaches the insert
        $columns = (array)$this->validateFields('create', $columns);

        $pid = $this->sql->insert()->into($this->tablename)->values($columns)->execute()->lastInsertId();

        return $this->read((int)$pid);
    }

    public function update(array $columns): bool
    {
        // throws an exception on failure; returns the validated whitelist
        $columns = (array)$this->validateFields('update', $columns);

        // the primary key targets the WHERE - it is never a SET column
        $id = (int)$columns['id'];
        unset($columns['id']);

        $this->sql->update($this->tablename)->set($columns)->where('id', '=', $id)->execute();

        return $this->sql->rowCount() > 0;
    }

    /**
     * Hard delete - removes the permission and every reference to it in the
     * role join table, atomically.
     */
    public function delete(int $id): bool
    {
        // throws exception
        $this->validateFields('delete', ['id' => $id]);

        $this->pdo->beginTransaction();

        try {
            $this->sql->delete()->from($this->tablename)->where('id', '=', $id)->execute();
            $this->sql->delete()->from($this->tableJoin)->where('permission_id', '=', $id)->execute();

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return true;
    }

    public function deactivate(int $id): bool
    {
        $this->sql->update($this->tablename)->set(['is_active' => 0])->where('id', '=', $id)->execute();

        return $this->sql->rowCount() > 0;
    }

    public function activate(int $id): bool
    {
        $this->sql->update($this->tablename)->set(['is_active' => 1])->where('id', '=', $id)->execute();

        return $this->sql->rowCount() > 0;
    }

    public function read(string|int $key): PermissionEntityInterface
    {
        $column = (is_string($key)) ? 'key' : 'id';

        // rowCount() after a SELECT isn't reliable across PDO drivers (e.g. always 0 on
        // sqlite) - check the fetched row itself instead
        $permissionEntity = $this->sql->setFetchMode($this->entityClass, [$this->aclConfig, $this])->select()->from($this->tablename)->where($column, '=', $key)->execute()->row();

        if ($permissionEntity === false) {
            throw new RecordNotFoundException('Permission Record ' . $key);
        }

        return $permissionEntity;
    }

    public function readAll(): array
    {
        // the fetch mode set by read() persists on the Sql instance - reset
        // it so readAll() always returns plain rows, not entities
        return $this->sql->setFetchMode(PDO::FETCH_ASSOC)->select()->from($this->tablename)->execute()->rows();
    }
}
