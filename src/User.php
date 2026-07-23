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

    protected AclInterface $acl;
    protected SessionInterface $sessionService;

    protected string $sessionKey;
    protected int $guestUserId;

    protected function __construct(array $config, AclInterface $acl, SessionInterface $sessionService)
    {
        $this->config = $this->mergeConfigWith($config);

        $this->acl = $acl;
        $this->sessionService = $sessionService;

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
            return $this->acl->getUser($this->retrieve());
        } catch (RecordNotFoundException) {
            // a stale session (the user was removed since logging in) is not
            // an error - drop back to the guest user and reset the session
            $this->save($this->guestUserId);

            return $this->acl->getUser($this->guestUserId);
        }
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
        $sessionUserId = (int)$this->sessionService->get($this->sessionKey, 0);

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
