<?php

declare(strict_types=1);

namespace orange\acl\models;

use PDO;
use Throwable;
use orange\acl\dtos\AclTables;
use orange\acl\dtos\CreateUserDto;
use orange\acl\dtos\DeleteUserDto;
use orange\acl\dtos\UpdateUserDto;
use orange\acl\dtos\UserPasswordDto;
use orange\acl\entities\UserEntity;
use orange\acl\interfaces\AclInterface;
use orange\acl\interfaces\UserModelInterface;
use orange\acl\interfaces\RoleEntityInterface;
use orange\acl\interfaces\UserEntityInterface;
use orange\framework\traits\ConfigurationTrait;
use orange\acl\exceptions\RecordNotFoundException;

class UserModel extends AclModel implements UserModelInterface
{
    use ConfigurationTrait;

    // wired by Acl::__construct so string role arguments can be resolved
    public AclInterface $acl;

    protected string $tablename = AclTables::USERS;

    protected UserMetaModel $userMetaModel;
    protected string $tableJoin = AclTables::USER_ROLE;
    protected string $metaTablename = AclTables::META;

    /**
     * One Dto per operation.
     *
     * The create and update Dtos span both tables this model writes - the user
     * row and its meta row - tagging each column #[Table] so either half can be
     * asked for separately. That is what replaced validating the two halves
     * against two rule sets and merging the failures by hand: one construction
     * validates everything and reports every failure together.
     *
     * Because those Dtos name their tables as attribute literals, renaming
     * 'user table' or 'user meta table' in config means supplying Dto subclasses
     * that restate them. Acl's constructor checks the two agree.
     */
    protected array $dtos = [
        'create' => CreateUserDto::class,
        'update' => UpdateUserDto::class,
        'delete' => DeleteUserDto::class,
        // updatePassword() holds the same policy as create
        'password' => UserPasswordDto::class,
    ];

    /**
     * The columns a user may not duplicate, and how to name them in an error.
     *
     * @var array<string, string>
     */
    protected array $uniqueColumns = ['username' => 'User Name', 'email' => 'Email'];

    /**
     * @param array<string, mixed> $aclConfig
     */
    public function __construct(protected array $aclConfig, PDO $pdo)
    {
        $this->entityClass = $this->aclConfig['UserEntityClass'] ?? UserEntity::class;

        // I manage the meta model 100% - including validating its columns, which
        // arrive already checked as part of this model's own Dto
        $this->userMetaModel = new UserMetaModel([], $pdo);

        parent::__construct($this->aclConfig, $pdo);

        $this->sql->throwExceptions(true);
    }

    /**
     * @param array<string, mixed> $columns
     */
    public function create(array $columns): UserEntityInterface
    {
        // One construction validates both halves, so a bad phone and a short
        // password are reported together rather than whichever was checked
        // first. The Dto also trims and lowercases the email on the way through,
        // which is why nothing normalizes it here any more.
        $dto = $this->requireDto('create', $columns);

        // each table's share of the same validated result - keyed by database
        // column name, so nothing the Dto didn't declare reaches an insert
        $userColumns = $dto->asColumns(tablename: $this->tablename);
        $metaColumns = $dto->asColumns(tablename: $this->metaTablename);

        $this->ensureUnique($userColumns, $this->uniqueColumns);

        // hash AFTER validation so the password rules judge the plaintext,
        // not a fixed-length hash
        $userColumns['password'] = $this->passwordHash($userColumns['password']);

        // the user row and its meta row live or die together
        $this->pdo->beginTransaction();

        try {
            $userId = (int)$this->sql->insert()->into($this->tablename)->values($userColumns)->execute()->lastInsertId();

            // the meta row shares the user row's primary key, which only exists
            // once the insert above has run
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
     *
     * @param array<string, mixed> $columns
     */
    public function update(array $columns): bool
    {
        $dto = $this->requireDto('update', $columns);

        // the primary key targets the WHERE - withoutPrimary keeps it out of the
        // SET, and each table is asked for its own key and columns
        $id = (int)$dto->primaryValue($this->tablename);
        $userColumns = $dto->asColumns(withoutPrimary: true, tablename: $this->tablename);
        $metaColumns = $dto->asColumns(withoutPrimary: true, tablename: $this->metaTablename);

        // the row keeps its own username and email; only another row holding
        // them is a conflict
        $this->ensureUnique($userColumns, $this->uniqueColumns, $id);

        // the meta row shares the user row's key
        $metaColumns['id'] = $id;

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
        $fields = $this->validateFields('password', ['password' => $password]);

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

    /**
     */
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

    /**
     * @return array<array-key, mixed>
     */
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
     *
     * @param array<array-key, int|string> $roleIds
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
     *
     * @return array<array-key, mixed>
     */
    public function getRolesPermissions(int $userId): array
    {
        $userRoleTable = AclTables::USER_ROLE;
        $roleTable = AclTables::ROLES;
        $rolePermissionTable = AclTables::ROLE_PERMISSION;
        $permissionTable = AclTables::PERMISSIONS;

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

    /**
     * @return array<string, mixed>
     */
    public function getMeta(int $userId): array
    {
        return $this->userMetaModel->read($userId);
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
}
