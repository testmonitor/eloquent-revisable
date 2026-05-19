<?php

namespace TestMonitor\Revisable;

use Closure;
use Illuminate\Auth\AuthManager;

class UserResolver
{
    protected ?Closure $resolver = null;

    public function __construct(
        protected AuthManager $auth,
        protected ?string $guard = null,
    ) {}

    public function resolve(): int|string|null
    {
        if ($this->resolver !== null) {
            return ($this->resolver)();
        }

        return $this->auth->guard($this->guard)->id();
    }

    public function matches(int|string|null $userId): bool
    {
        $resolvedUserId = $this->resolve();

        if ($resolvedUserId === null || $userId === null) {
            return $resolvedUserId === $userId;
        }

        return $this->normalizeUserId($resolvedUserId) === $this->normalizeUserId($userId);
    }

    private function normalizeUserId(int|string|null $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        return (string) $userId;
    }

    public function resolveUsing(Closure $resolver): static
    {
        $this->resolver = $resolver;

        return $this;
    }
}
