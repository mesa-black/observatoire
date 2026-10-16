<?php

namespace App\Security;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Where the emailed code waits for the admin to type it. The admin is not a
 * database row (AdminUser), so the code lives in the app cache, ten minutes.
 */
final readonly class AdminCodeStore
{
    private const string KEY = 'admin_email_code';
    private const int TTL = 600;

    public function __construct(private CacheItemPoolInterface $cacheApp)
    {
    }

    public function get(): ?string
    {
        $item = $this->cacheApp->getItem(self::KEY);

        return $item->isHit() && \is_string($item->get()) ? $item->get() : null;
    }

    public function set(?string $code): void
    {
        if (null === $code) {
            $this->cacheApp->deleteItem(self::KEY);

            return;
        }
        $this->cacheApp->save($this->cacheApp->getItem(self::KEY)->set($code)->expiresAfter(self::TTL));
    }
}
