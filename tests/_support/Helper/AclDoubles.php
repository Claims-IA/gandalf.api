<?php
/**
 * In-memory doubles for the ACL middleware unit test (tests/unit/AclCoverageTest.php).
 *
 * Kept out of the test file: Codeception 2.1 reads every `class` keyword of a
 * test file as a test class name, anonymous classes included.
 */

namespace Helper;

use Illuminate\Contracts\Auth\Factory;

/** Auth factory whose guard returns the given caller (null for a guest). */
class AclAuth implements Factory
{
    private $caller;

    public function __construct($caller)
    {
        $this->caller = $caller;
    }

    public function guard($name = null)
    {
        return new AclGuard($this->caller);
    }

    public function shouldUse($name)
    {
    }
}

class AclGuard
{
    private $caller;

    public function __construct($caller)
    {
        $this->caller = $caller;
    }

    public function guest()
    {
        return $this->caller === null;
    }

    public function user()
    {
        return $this->caller;
    }
}

/** Authenticated user: member of the current application with $scopes, or not a member (null). */
class AclCaller
{
    private $scopes;

    public function __construct(array $scopes = null)
    {
        $this->scopes = $scopes;
    }

    public function getApplicationUser()
    {
        return $this->scopes === null ? null : new AclMember($this->scopes);
    }
}

/** Member entry, or consumer, answering canX() from its scopes (like hasAccess()). */
class AclMember
{
    private $scopes;

    public function __construct(array $scopes)
    {
        $this->scopes = $scopes;
    }

    public function __call($method, $parameters)
    {
        return in_array(strtolower(substr($method, 3)), $this->scopes, true);
    }
}
