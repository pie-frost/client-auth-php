<?php
declare(strict_types=1);
namespace PIEFrost\ClientAuth\Tests;

use PHPUnit\Framework\TestCase;
use PIEFrost\ClientAuth\Config;

class ConfigTest extends TestCase
{
    public function testImmutability()
    {
        $config = new Config();

        $with = $config->withDomain('test.phpunit');
        $this->assertSame('test.phpunit', $with->getDomain());

        $this->expectExceptionMessage('Client domain not configured');
        $fail = $config->getDomain();
        $this->assertEmpty($fail, 'Exception failed silently.');
    }
}
