<?php

namespace App\Tests\Security;

use App\Security\ClientKey;
use PHPUnit\Framework\TestCase;

final class ClientKeyTest extends TestCase
{
    public function testTheAddressNeverAppearsInTheKey(): void
    {
        $key = (new ClientKey('secret'))->of('203.0.113.7');

        $this->assertStringNotContainsString('203.0.113.7', $key);
        $this->assertSame(64, \strlen($key));
    }

    public function testTheKeyDependsOnTheSecret(): void
    {
        $this->assertNotSame((new ClientKey('one'))->of('203.0.113.7'), (new ClientKey('two'))->of('203.0.113.7'));
    }

    public function testAnIpv6ClientIsCountedByItsSlash64(): void
    {
        $keys = new ClientKey('secret');

        $this->assertSame($keys->of('2001:db8:1:2::1'), $keys->of('2001:db8:1:2:ffff::9'));
        $this->assertNotSame($keys->of('2001:db8:1:2::1'), $keys->of('2001:db8:1:3::1'));
    }

    public function testEachIpv4AddressIsCountedApart(): void
    {
        $keys = new ClientKey('secret');

        $this->assertNotSame($keys->of('203.0.113.7'), $keys->of('203.0.113.8'));
    }
}
