<?php

declare(strict_types=1);

namespace orange\acl;

use PDO;
use InvalidArgumentException;
use orange\acl\models\RoleModel;
use orange\acl\models\UserModel;
use orange\framework\base\Singleton;
use orange\acl\models\PermissionModel;
use orange\acl\interfaces\AclInterface;
use orange\acl\interfaces\RoleModelInterface;
use orange\acl\interfaces\UserModelInterface;
use orange\acl\interfaces\RoleEntityInterface;
use orange\acl\interfaces\UserEntityInterface;
use orange\framework\traits\ConfigurationTrait;
use orange\validate\interfaces\ValidateInterface;
use orange\acl\interfaces\PermissionModelInterface;
use orange\acl\interfaces\PermissionEntityInterface;

class Acl extends Singleton implements AclInterface
{
    use ConfigurationTrait;

    // we manage these
    public UserModel $userModel;
    public RoleModel $roleModel;
    public PermissionModel $permissionModel;

    protected function __construct(array $config, PDO $pdo, ValidateInterface $validateService)
    {
        $config = $this->mergeConfigWith($config);

        // this package's SQL reports failures by throwing - a silent false
        // from prepare()/execute() would otherwise surface as a confusing
        // fatal somewhere downstream
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // the table names end up inside SQL (identifiers can't be bound as
        // parameters) - verifying them here turns a typo'd or malicious
        // config value into an immediate, obvious throw, not a weird
        // mid-request query error
        foreach (['user table', 'role table', 'permission table', 'user role table', 'role permission table', 'user meta table'] as $key) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', (string)($config[$key] ?? ''))) {
                throw new InvalidArgumentException('Config "' . $key . '" is not a valid SQL identifier: "' . (string)($config[$key] ?? '') . '"');
            }
        }

        // a swapped-in model class must still honor its contract
        foreach (['userModel' => UserModelInterface::class, 'roleModel' => RoleModelInterface::class, 'permissionModel' => PermissionModelInterface::class] as $key => $interface) {
            if (!is_a((string)($config[$key] ?? ''), $interface, true)) {
                throw new InvalidArgumentException('Config "' . $key . '" must be a class implementing ' . $interface);
            }
        }

        $this->userModel = new $config['userModel']($config, $pdo, $validateService);
        $this->roleModel = new $config['roleModel']($config, $pdo, $validateService);
        $this->permissionModel = new $config['permissionModel']($config, $pdo, $validateService);

        // the models resolve string role/permission arguments back through
        // this facade - without this wiring those lookups would fail on an
        // uninitialized property
        $this->userModel->acl = $this;
        $this->roleModel->acl = $this;
    }

    /**
     * get & create entities
     *
     * create will throw ValidationFailed Exceptions on fail
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
