<?php

declare(strict_types=1);

namespace orange\acl;

use PDO;
use InvalidArgumentException;
use orange\acl\models\RoleModel;
use orange\acl\models\UserModel;
use orange\acl\dtos\AclTables;
use orange\framework\base\Singleton;
use orange\acl\models\PermissionModel;
use orange\acl\interfaces\AclInterface;
use orange\acl\interfaces\RoleModelInterface;
use orange\acl\interfaces\UserModelInterface;
use orange\acl\interfaces\RoleEntityInterface;
use orange\acl\interfaces\UserEntityInterface;
use orange\framework\traits\ConfigurationTrait;
use orange\acl\interfaces\PermissionModelInterface;
use orange\acl\interfaces\PermissionEntityInterface;

class Acl extends Singleton implements AclInterface
{
    use ConfigurationTrait;

    // we manage these
    public UserModel $userModel;
    public RoleModel $roleModel;
    public PermissionModel $permissionModel;

    protected function __construct(array $config, PDO $pdo)
    {
        $config = $this->mergeConfigWith($config);

        // this package's SQL reports failures by throwing - a silent false
        // from prepare()/execute() would otherwise surface as a confusing
        // fatal somewhere downstream
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Table names are no longer configurable - they are constants on
        // AclTables, because a Dto's #[Table] attribute cannot read config and a
        // rename that moved the models but not the Dtos was a half-supported
        // option. Refuse the old keys loudly: silently ignoring one would point
        // every query at a different table than the caller asked for, and the
        // first sign of that is rows written somewhere unexpected.
        //
        // This also retires the SQL-identifier check that used to guard these
        // values. There is no longer a caller-supplied identifier to interpolate.
        foreach (AclTables::removedConfigKeys() as $key) {
            if (isset($config[$key])) {
                throw new InvalidArgumentException('Config "' . $key . '" is no longer supported - ACL table names are constants on ' . AclTables::class . '. To use a different table, subclass the model with its own $tablename and register Dto subclasses restating their #[Table] attributes.');
            }
        }

        // a swapped-in model class must still honor its contract
        foreach (['userModel' => UserModelInterface::class, 'roleModel' => RoleModelInterface::class, 'permissionModel' => PermissionModelInterface::class] as $key => $interface) {
            if (!is_a((string)($config[$key] ?? ''), $interface, true)) {
                throw new InvalidArgumentException('Config "' . $key . '" must be a class implementing ' . $interface);
            }
        }

        $this->userModel = new $config['userModel']($config, $pdo);
        $this->roleModel = new $config['roleModel']($config, $pdo);
        $this->permissionModel = new $config['permissionModel']($config, $pdo);

        // the models resolve string role/permission arguments back through
        // this facade - without this wiring those lookups would fail on an
        // uninitialized property
        $this->userModel->acl = $this;
        $this->roleModel->acl = $this;
    }

    /**
     * get & create entities
     *
     * create will throw DtoValidationFailed on fail - carrying the offending
     * fields keyed by name, from the operation's Dto or from the model's
     * uniqueness check, which report identically
     */
    public function createUser(string $username, string $email, string $password, array $fields = []): UserEntityInterface
    {
        // the explicit arguments always win over anything riding in $fields
        $fields = [
            'username' => $username,
            'email' => $email,
            'password' => $password,
        ] + $fields;

        return $this->userModel->create($fields);
    }

    public function getUser(int $userId): UserEntityInterface
    {
        return $this->userModel->read($userId);
    }

    public function createRole(string $name, string $description): RoleEntityInterface
    {
        return $this->roleModel->create(['name' => $name, 'description' => $description]);
    }

    public function getRole(string|int $arg): RoleEntityInterface
    {
        return $this->roleModel->read($arg);
    }

    public function createPermission(string $key, string $description, string $group): PermissionEntityInterface
    {
        return $this->permissionModel->create(['key' => $key, 'description' => $description, 'group' => $group]);
    }

    public function getPermission(string|int $arg): PermissionEntityInterface
    {
        return $this->permissionModel->read($arg);
    }
}
