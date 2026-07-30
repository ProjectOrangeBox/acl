<?php

declare(strict_types=1);

namespace orange\acl\models;

use PDO;
use Throwable;
use orange\acl\dtos\AclTables;
use orange\acl\dtos\CreateRoleDto;
use orange\acl\dtos\DeleteRoleDto;
use orange\acl\dtos\UpdateRoleDto;
use orange\acl\entities\RoleEntity;
use orange\acl\interfaces\AclInterface;
use orange\acl\interfaces\RoleModelInterface;
use orange\acl\interfaces\RoleEntityInterface;
use orange\framework\traits\ConfigurationTrait;
use orange\acl\exceptions\RecordNotFoundException;
use orange\acl\interfaces\PermissionEntityInterface;

class RoleModel extends AclModel implements RoleModelInterface
{
    use ConfigurationTrait;

    // wired by Acl::__construct so string permission arguments can be resolved
    public AclInterface $acl;

    protected string $tableJoin;

    protected string $tablename = AclTables::ROLES;

    /**
     * One Dto per operation, each carrying its own rules, filters and labels.
     *
     * The table these write is fixed - see {@see AclTables}. A deployment that
     * needs a different one subclasses this model with its own $tablename and
     * registers Dto subclasses here, so the two cannot drift apart.
     */
    protected array $dtos = [
        'create' => CreateRoleDto::class,
        'update' => UpdateRoleDto::class,
        'delete' => DeleteRoleDto::class,
    ];

    /**
     * The columns a role may not duplicate, and how to name them in an error.
     *
     * @var array<string, string>
     */
    protected array $uniqueColumns = ['name' => 'Name'];

    /**
     * @param array<string, mixed> $aclConfig
     */
    public function __construct(protected array $aclConfig, PDO $pdo)
    {
        $this->entityClass = $this->aclConfig['RoleEntityClass'] ?? RoleEntity::class;

        $this->tableJoin = AclTables::ROLE_PERMISSION;

        parent::__construct($this->aclConfig, $pdo);

        $this->sql->throwExceptions(true);
    }

    /**
     * @param array<string, mixed> $columns
     */
    public function create(array $columns): RoleEntityInterface
    {
        // throws on failure and returns only the validated, whitelisted columns
        // keyed by database column name - nothing else reaches the insert
        $columns = $this->validateFields('create', $columns);

        $this->ensureUnique($columns, $this->uniqueColumns);

        $pid = $this->sql->insert()->into($this->tablename)->values($columns)->execute()->lastInsertId();

        return $this->read((int)$pid);
    }

    /**
     * @param array<string, mixed> $columns
     */
    public function update(array $columns): bool
    {
        // hold the Dto rather than just its columns - the primary belongs in the
        // WHERE, so it is read from here and dropped from the SET
        $dto = $this->requireDto('update', $columns);

        $id = (int)$dto->primaryValue();
        $columns = $dto->asColumns(withoutPrimary: true);

        // the row keeps its own name; only another row holding it is a conflict
        $this->ensureUnique($columns, $this->uniqueColumns, $id);

        $this->sql->update($this->tablename)->set($columns)->where('id', '=', $id)->execute();

        return $this->sql->rowCount() > 0;
    }

    /**
     * Hard delete - removes the role and every reference to it in the
     * permission and user join tables, atomically.
     */
    public function delete(int $id): bool
    {
        // throws an exception
        $this->validateFields('delete', ['id' => $id]);


        $this->pdo->beginTransaction();

        try {
            $this->sql->delete()->from($this->tablename)->where('id', '=', $id)->execute();
            $this->sql->delete()->from($this->tableJoin)->where('role_id', '=', $id)->execute();
            $this->sql->delete()->from(AclTables::USER_ROLE)->where('role_id', '=', $id)->execute();

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

    /**
     */
    public function read(string|int $key): RoleEntityInterface
    {
        $column = (is_string($key)) ? 'name' : 'id';

        // rowCount() after a SELECT isn't reliable across PDO drivers (e.g. always 0 on
        // sqlite) - check the fetched row itself instead
        $roleEntity = $this->sql->setFetchMode($this->entityClass, [$this->aclConfig, $this])->select()->from($this->tablename)->where($column, '=', $key)->execute()->row();

        if ($roleEntity === false) {
            throw new RecordNotFoundException('Role Record ' . $key);
        }

        return $roleEntity;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function readAll(): array
    {
        // the fetch mode set by read() persists on the Sql instance - reset
        // it so readAll() always returns plain rows, not entities
        return $this->sql->setFetchMode(PDO::FETCH_ASSOC)->select()->from($this->tablename)->execute()->rows();
    }

    public function addPermission(int $roleId, int|string|PermissionEntityInterface $arg): bool
    {
        $this->sql->insert()->into($this->tableJoin)->values(['role_id' => $roleId, 'permission_id' => $this->resolvePermissionId($arg)])->execute();

        return $this->sql->rowCount() > 0;
    }

    public function removePermission(int $roleId, int|string|PermissionEntityInterface $arg): bool
    {
        $this->sql->delete($this->tableJoin)->where('role_id', '=', $roleId)->and()->where('permission_id', '=', $this->resolvePermissionId($arg))->execute();

        return $this->sql->rowCount() > 0;
    }

    public function removeAllPermissions(int $roleId): bool
    {
        $this->sql->delete()->from($this->tableJoin)->where('role_id', '=', $roleId)->execute();

        return $this->sql->rowCount() > 0;
    }

    /**
     * Replace the role's permissions with exactly $permissionIds - atomically;
     * a failure rolls the whole relink back and rethrows.
     *
     * @param array<array-key, int|string> $permissionIds
     */
    public function relink(int $roleId, array $permissionIds): bool
    {
        $this->pdo->beginTransaction();

        try {
            $this->removeAllPermissions($roleId);

            foreach ($permissionIds as $permissionId) {
                $this->addPermission($roleId, $permissionId);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return true;
    }

    /**
     * Resolves a permission key, entity, or id to the permission id.
     */
    protected function resolvePermissionId(int|string|PermissionEntityInterface $arg): int
    {
        if (is_string($arg)) {
            return (int)$this->acl->getPermission($arg)->id;
        }

        if ($arg instanceof PermissionEntityInterface) {
            return (int)$arg->id;
        }

        return $arg;
    }
}
