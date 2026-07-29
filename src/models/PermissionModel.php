<?php

declare(strict_types=1);

namespace orange\acl\models;

use PDO;
use Throwable;
use orange\acl\dtos\AclTables;
use orange\acl\dtos\CreatePermissionDto;
use orange\acl\dtos\DeletePermissionDto;
use orange\acl\dtos\UpdatePermissionDto;
use orange\acl\entities\PermissionEntity;
use orange\acl\exceptions\RecordNotFoundException;
use orange\acl\interfaces\PermissionModelInterface;
use orange\acl\interfaces\PermissionEntityInterface;

class PermissionModel extends AclModel implements PermissionModelInterface
{
    protected string $tableJoin;

    protected string $tablename = AclTables::PERMISSIONS;

    /**
     * One Dto per operation. The table they write is fixed - see {@see AclTables}
     * for what overriding it takes.
     */
    protected array $dtos = [
        'create' => CreatePermissionDto::class,
        'update' => UpdatePermissionDto::class,
        'delete' => DeletePermissionDto::class,
    ];

    /**
     * @var array<string, string>
     */
    protected array $uniqueColumns = ['key' => 'Key'];

    public function __construct(protected array $aclConfig, PDO $pdo)
    {
        $this->entityClass = $this->aclConfig['PermissionEntityClass'] ?? PermissionEntity::class;

        $this->tableJoin = AclTables::ROLE_PERMISSION;

        parent::__construct($this->aclConfig, $pdo);

        $this->sql->throwExceptions(true);
    }

    public function create(array $columns): PermissionEntityInterface
    {
        // throws on failure and returns only the validated, whitelisted columns
        // keyed by database column name - nothing else reaches the insert
        $columns = $this->validateFields('create', $columns);

        $this->ensureUnique($columns, $this->uniqueColumns);

        $pid = $this->sql->insert()->into($this->tablename)->values($columns)->execute()->lastInsertId();

        return $this->read((int)$pid);
    }

    public function update(array $columns): bool
    {
        // hold the Dto rather than just its columns - the primary belongs in the
        // WHERE, so it is read from here and dropped from the SET
        $dto = $this->requireDto('update', $columns);

        $id = (int)$dto->primaryValue();
        $columns = $dto->asColumns(withoutPrimary: true);

        $this->ensureUnique($columns, $this->uniqueColumns, $id);

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
