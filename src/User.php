<?php

declare(strict_types=1);

namespace orange\acl;

use orange\framework\base\Singleton;
use orange\session\SessionInterface;
use orange\acl\interfaces\AclInterface;
use orange\acl\interfaces\UserInterface;
use orange\acl\interfaces\UserEntityInterface;
use orange\framework\traits\ConfigurationTrait;
use orange\framework\exceptions\MissingRequired;
use orange\acl\exceptions\RecordNotFoundException;

/**
 * Session-aware "current user" helper - remembers which user id is logged
 * in (in the session) and resolves it to an ACL user entity, falling back
 * to the configured guest user.
 */
class User extends Singleton implements UserInterface
{
    use ConfigurationTrait;

    protected array $config = [];

    protected string $sessionKey;
    protected int $guestUserId;

    /**
     * @param array<string, mixed> $config
     */
    protected function __construct(array $config, protected AclInterface $acl, protected SessionInterface $sessionService)
    {
        $this->config = $this->mergeConfigWith($config);

        // required value
        if (!isset($this->config['guest user']) || !is_int($this->config['guest user'])) {
            throw new MissingRequired('Invalid Guest User Id');
        }

        $this->guestUserId = $this->config['guest user'];

        $this->sessionKey = $this->config['sessionKey'];
    }

    /**
     * The current user - resolved from the session, the guest user when
     * nothing (or something stale) is stored there.
     */
    public function load(): UserEntityInterface
    {
        try {
            $userId = $this->retrieve();

            $user = $this->acl->getUser($userId);

            // a session outlives the account it names. orange/auth can only
            // refuse a deleted or deactivated account at the login gate, so
            // without this check an account revoked *after* it logged in keeps
            // a fully-privileged session until it next tries to log in - which
            // a revoked account has no reason to do. Treat it as stale instead.
            if ($userId !== $this->guestUserId && !$this->isUsable($user)) {
                throw new RecordNotFoundException('User Record ' . $userId . ' is no longer usable');
            }

            return $user;
        } catch (RecordNotFoundException) {
            // a stale session (the user was removed or revoked since logging in)
            // is not an error - drop back to the guest user and reset the session
            $this->save($this->guestUserId);

            try {
                return $this->acl->getUser($this->guestUserId);
            } catch (RecordNotFoundException $e) {
                // The fallback itself is missing, which is a setup problem
                // rather than a stale session - and every anonymous request
                // hits it. Rethrown naming what is absent and which config
                // value points at it, because "User Record 2" on its own sends
                // you looking through the session code instead of the seed.
                throw new MissingRequired(
                    'guest user row (id ' . $this->guestUserId . '). '
                    . 'Every request without a login resolves to it, so it has to exist. '
                    . "Seed it - see the package's support/seed.sql - or point 'guest user' "
                    . 'in config/user.php at a row that does.',
                    $e->getCode(),
                    $e
                );
            }
        }
    }


    /**
     * Whether an account may still act as the session's identity.
     *
     * Deliberately not applied to the guest user: guest is the fallback itself,
     * so rejecting it would leave nothing to fall back to. UserModel::read()
     * stays unfiltered on purpose - admin tooling legitimately needs to load a
     * deleted or deactivated user - so this is the layer that enforces it.
     */
    protected function isUsable(UserEntityInterface $user): bool
    {
        return $user->is_active === 1 && $user->is_deleted === 0;
    }

    public function change(int $userID): UserEntityInterface
    {
        // switching users is a privilege boundary - never keep the previous
        // session id (fixation defense). logout() routes through here, so
        // logging out is covered too. Guarded because CLI runs may have no
        // active session to regenerate
        if ($this->sessionService->isActive()) {
            $this->sessionService->regenerateId(true);
        }

        $this->save($userID);

        return $this->acl->getUser($userID);
    }

    public function logout(): UserEntityInterface
    {
        return $this->change($this->guestUserId);
    }

    /* session */
    protected function retrieve(): int
    {
        // session get() takes no default - an unset key returns null, which
        // casts to 0 and falls through to the guest id below
        $sessionUserId = (int)$this->sessionService->get($this->sessionKey);

        // anything that isn't a positive user id falls back to guest
        return $sessionUserId > 0 ? $sessionUserId : $this->guestUserId;
    }

    /* session */
    protected function save(int $userId): bool
    {
        $this->sessionService->set($this->sessionKey, $userId);

        return true;
    }
}
