<?php

declare(strict_types=1);

namespace orange\acl\models;

use PDO;
use Throwable;
use orange\model\Model;
use orange\acl\entities\RoleEntity;
use orange\acl\interfaces\AclInterface;
use orange\acl\interfaces\RoleModelInterface;
use orange\acl\interfaces\RoleEntityInterface;
use orange\framework\traits\ConfigurationTrait;
use orange\validate\interfaces\ValidateInterface;
use orange\acl\exceptions\RecordNotFoundException;
use orange\acl\interfaces\PermissionEntityInterface;

class RoleModel extends Model implements RoleModelInterface
{
    use ConfigurationTrait;

    // wired by Acl::__construct so string permission arguments can be resolved
    public AclInterface $acl;

    // the full merged acl config - Model's own constructor slims $this->config
    // down to the sql-related keys, discarding the acl table names and the
    // guest/admin ids the entities need
    protected array $aclConfig = [];

    protected string $tableJoin;

    protected array $rules = [
        'id' => ['isRequired|integer', 'Id'],
        'name' => ['isRequired|minLength[4]|maxLength[128]|isUnique[%s,name,id,pdo]', 'Name'],
        'description' => ['isRequired|minLength[4]|maxLength[512]', 'Description'],
        'is_active' => ['ifEmpty[1]|isOneOf[0,1]', 'Is Active'],
    ];
    protected array $ruleSets = [
        'create' => ['name', 'description', 'is_active'],
        'update' => ['id', 'name', 'description', 'is_active'],
        'delete' => ['id'],
    ];

    public function __construct(array $config, PDO $pdo, ValidateInterface $validateService)
    {
        $this->aclConfig = $config;

        $this->entityClass = $config['RoleEntityClass'] ?? RoleEntity::class;

        $config['tablename'] = $this->tablename = $this->aclConfig['role table'];

        $this->rules['name'][0] = sprintf($this->rules['name'][0], $this->tablename);

        $this->tableJoin = $this->aclConfig['role permission table'];

        $validateService->throwExceptionOnFailure(true);

        parent::__construct($config, $pdo, $validateService);

        $this->sql->throwExceptions(true);
    }

    public function create(array $columns): RoleEntityInterface
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
            $this->sql->delete()->from($this->aclConfig['user role table'])->where('role_id', '=', $id)->execute();

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
