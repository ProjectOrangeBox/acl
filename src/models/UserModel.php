<?php

declare(strict_types=1);

namespace orange\acl\models;

use PDO;
use Throwable;
use orange\model\Model;
use orange\acl\entities\UserEntity;
use orange\acl\interfaces\AclInterface;
use orange\acl\interfaces\UserModelInterface;
use orange\acl\interfaces\RoleEntityInterface;
use orange\acl\interfaces\UserEntityInterface;
use orange\framework\traits\ConfigurationTrait;
use orange\validate\exceptions\ValidationFailed;
use orange\validate\interfaces\ValidateInterface;
use orange\acl\exceptions\RecordNotFoundException;

class UserModel extends Model implements UserModelInterface
{
    use ConfigurationTrait;

    // wired by Acl::__construct so string role arguments can be resolved
    public AclInterface $acl;

    // the full merged acl config - Model's own constructor slims $this->config
    // down to the sql-related keys, discarding the acl table names and the
    // guest/admin ids the entities need
    protected array $aclConfig = [];

    protected UserMetaModel $userMetaModel;
    protected string $tableJoin;

    protected array $rules = [
        'id' => ['isRequired|integer', 'Id'],
        'username' => ['isRequired|minLength[4]|maxLength[64]|isUnique[%s,username,id,pdo]', 'User Name'],
        'email' => ['isRequired|minLength[4]|maxLength[255]|isUnique[%s,email,id,pdo]', 'Email'],
        'password' => ['isRequired|minLength[4]|maxLength[255]', 'Password'],
        'is_active' => ['ifEmpty[0]|isOneOf[0,1]', 'Is Active'],
    ];
    protected array $ruleSets = [
        // meta columns (dashboard_url, phone, ext) are validated by
        // UserMetaModel's own rules, not here
        'create' => ['username', 'email', 'password', 'is_active'],
        'update' => ['id', 'username', 'email', 'is_active'],
        'delete' => ['id'],
        // updatePassword() runs the same password policy as create
        'password' => ['password'],
    ];

    public function __construct(array $config, PDO $pdo, ValidateInterface $validateService)
    {
        $this->aclConfig = $config;

        $this->entityClass = $config['UserEntityClass'] ?? UserEntity::class;

        $config['tablename'] = $this->tablename = $config['user table'];

        $this->rules['username'][0] = sprintf($this->rules['username'][0], $this->tablename);
        $this->rules['email'][0] = sprintf($this->rules['email'][0], $this->tablename);

        $this->tableJoin = $this->aclConfig['user role table'];

        // I manage the meta model 100%
        $this->userMetaModel = new UserMetaModel(['tablename' => $this->aclConfig['user meta table']], $pdo, $validateService);

        $validateService->throwExceptionOnFailure(true);

        parent::__construct($config, $pdo, $validateService);

        $this->sql->throwExceptions(true);
    }

    public function create(array $columns): UserEntityInterface
    {
        // normalize before validating so isUnique checks the stored form
        $columns = $this->normalizeEmail($columns);

        // validate the user and meta halves against their own rules; each
        // validateFields() returns ONLY its validated, whitelisted columns -
        // anything else the caller passed never reaches an insert
        [$userColumns, $metaColumns] = $this->validateBoth('create', $columns);

        // hash AFTER validation so the password rules judge the plaintext,
        // not a fixed-length hash
        $userColumns['password'] = $this->passwordHash($userColumns['password']);

        // the user row and its meta row live or die together
        $this->pdo->beginTransaction();

        try {
            $userId = (int)$this->sql->insert()->into($this->tablename)->values($userColumns)->execute()->lastInsertId();

            // the meta row shares the user row's primary key
            $metaColumns['id'] = $userId;

            $this->userMetaModel->create($metaColumns);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $this->read($userId);
    }

    /**
     * This will not update the password
     * Please use updatePassword()
     */
    public function update(array $columns): bool
    {
        // normalize before validating so isUnique checks the stored form
        $columns = $this->normalizeEmail($columns);

        [$userColumns, $metaColumns] = $this->validateBoth('update', $columns);

        // the primary key targets the WHERE - it is never a SET column
        $id = (int)$userColumns['id'];
        unset($userColumns['id']);

        $this->pdo->beginTransaction();

        try {
            $userChanged = false;

            // an empty SET isn't valid SQL - skip when only the id was sent
            if ($userColumns !== []) {
                $this->sql->update($this->tablename)->set($userColumns)->where('id', '=', $id)->execute();

                // capture this statement's count before the meta update overwrites it
                $userChanged = $this->sql->rowCount() > 0;
            }

            $metaChanged = $this->userMetaModel->update($metaColumns);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $userChanged || $metaChanged;
    }

    public function updatePassword(int $id, string $password): bool
    {
        // the password policy applies to changes exactly as it does to
        // create - validate the plaintext (throws on failure), then hash
        $fields = (array)$this->validateFields('password', ['password' => $password]);

        $this->sql->update($this->tablename)->set(['password' => $this->passwordHash($fields['password'])])->where('id', '=', $id)->execute();

        return $this->sql->rowCount() > 0;
    }

    /**
     * Soft delete - flags the row is_deleted; the meta row is left in place
     * so an undelete restores the complete user.
     */
    public function delete(int $id): bool
    {
        // throws an exception
        $this->validateFields('delete', ['id' => $id]);

        $this->sql->update($this->tablename)->set(['is_deleted' => 1])->where('id', '=', $id)->execute();

        return $this->sql->rowCount() > 0;
    }

    public function read(int $userId): UserEntityInterface
    {
        // rowCount() after a SELECT isn't reliable across PDO drivers (e.g. always 0 on
        // sqlite) - check the fetched row itself instead
        // the entity gets the FULL acl config - it reads 'guest user' and
        // 'admin role' for loggedIn()/isGuest()/isAdmin()
        $userEntity = $this->sql->setFetchMode($this->entityClass, [$this->aclConfig, $this])->select()->from($this->tablename)->where('id', '=', $userId)->execute()->row();

        if ($userEntity === false) {
            throw new RecordNotFoundException('User Record ' . $userId);
        }

        // without meta - this is lazy loaded with the permission only before being used
        return $userEntity;
    }

    public function readAll(): array
    {
        // the fetch mode set by read() persists on the Sql instance - reset
        // it so readAll() always returns plain rows, not entities
        return $this->sql->setFetchMode(PDO::FETCH_ASSOC)->select()->from($this->tablename)->execute()->rows();
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

    public function addRole(int $userId, string|int|RoleEntityInterface $arg): bool
    {
        $this->sql->insert()->into($this->tableJoin)->values(['role_id' => $this->resolveRoleId($arg), 'user_id' => $userId])->execute();

        return $this->sql->rowCount() > 0;
    }

    public function removeRole(int $userId, string|int|RoleEntityInterface $arg): bool
    {
        $this->sql->delete($this->tableJoin)->whereEqual('role_id', $this->resolveRoleId($arg))->and()->where('user_id', '=', $userId)->execute();

        return $this->sql->rowCount() > 0;
    }

    public function removeAllRoles(int $userId): bool
    {
        $this->sql->delete($this->tableJoin)->where('user_id', '=', $userId)->execute();

        return $this->sql->rowCount() > 0;
    }

    /**
     * Replace the user's roles with exactly $roleIds - atomically; a failure
     * rolls the whole relink back and rethrows.
     */
    public function relink(int $userId, array $roleIds): bool
    {
        $this->pdo->beginTransaction();

        try {
            $this->removeAllRoles($userId);

            foreach ($roleIds as $roleId) {
                $this->addRole($userId, $roleId);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return true;
    }

    /**
     * The user's active roles and the permissions those roles grant.
     *
     * Two separate queries on purpose: a role with no (active) permissions
     * must still appear in 'roles', which a single joined query can't deliver
     * without LEFT JOIN + ON-clause gymnastics.
     */
    public function getRolesPermissions(int $userId): array
    {
        $userRoleTable = $this->aclConfig['user role table'];
        $roleTable = $this->aclConfig['role table'];
        $rolePermissionTable = $this->aclConfig['role permission table'];
        $permissionTable = $this->aclConfig['permission table'];

        $roles = $this->sql
            ->select([$roleTable . '.id', $roleTable . '.name'])
            ->from($userRoleTable)
            ->innerJoin($roleTable, $roleTable . '.id', $userRoleTable . '.role_id')
            ->whereEqual($userRoleTable . '.user_id', $userId)
            ->and()->where($roleTable . '.is_active', '=', 1)
            ->execute()->keyPair();

        $permissions = $this->sql
            ->select([$permissionTable . '.id', $permissionTable . '.key'])
            ->from($userRoleTable)
            ->innerJoin($roleTable, $roleTable . '.id', $userRoleTable . '.role_id')
            ->innerJoin($rolePermissionTable, $rolePermissionTable . '.role_id', $roleTable . '.id')
            ->innerJoin($permissionTable, $permissionTable . '.id', $rolePermissionTable . '.permission_id')
            ->whereEqual($userRoleTable . '.user_id', $userId)
            ->and()->where($roleTable . '.is_active', '=', 1)
            ->and()->where($permissionTable . '.is_active', '=', 1)
            ->execute()->keyPair();

        /* everybody */
        $roles[$this->aclConfig['everyone role']] = 'Everyone';

        return ['roles' => $roles, 'permissions' => $permissions];
    }

    public function getMeta(int $userId): array
    {
        return $this->userMetaModel->read($userId);
    }

    /**
     * Emails are stored trimmed + lowercase, matching orange/auth's
     * normalized-login default - so credential lookups never depend on the
     * database collation.
     */
    protected function normalizeEmail(array $columns): array
    {
        if (isset($columns['email']) && is_string($columns['email'])) {
            $columns['email'] = mb_strtolower(trim($columns['email']));
        }

        return $columns;
    }

    /**
     * Hashes a plaintext password - always; anything that must store a
     * pre-computed hash (a migration import, say) belongs in SQL, not here.
     */
    protected function passwordHash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Resolves a role name, entity, or id to the role id.
     */
    protected function resolveRoleId(string|int|RoleEntityInterface $arg): int
    {
        if (is_string($arg)) {
            return (int)$this->acl->getRole($arg)->id;
        }

        if ($arg instanceof RoleEntityInterface) {
            return (int)$arg->id;
        }

        return $arg;
    }

    /**
     * Validates $columns against this model's AND the meta model's rule set,
     * collecting failures from both into a single ValidationFailed so the
     * caller sees every error at once. Returns [userColumns, metaColumns] -
     * each already filtered down to its own validated whitelist.
     */
    protected function validateBoth(string $set, array $columns): array
    {
        $userColumns = [];
        $metaColumns = [];

        // setup a validation failed exception as a collector
        $errors = new ValidationFailed();

        try {
            $metaColumns = (array)$this->userMetaModel->validateFields($set, $columns);
        } catch (ValidationFailed $vf) {
            $errors->merge($vf);
        }

        try {
            $userColumns = (array)$this->validateFields($set, $columns);
        } catch (ValidationFailed $vf) {
            $errors->merge($vf);
        }

        // if it has errors then "throw" it
        if ($errors->hasErrors()) {
            throw $errors;
        }

        return [$userColumns, $metaColumns];
    }
}
