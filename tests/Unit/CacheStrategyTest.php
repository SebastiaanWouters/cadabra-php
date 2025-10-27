<?php

declare(strict_types=1);

namespace Cadabra\Tests\Unit;

use Cadabra\SymfonyBundle\Service\CacheStrategy;
use PHPUnit\Framework\TestCase;

class CacheStrategyTest extends TestCase
{
    public function testShouldCacheReadQueries(): void
    {
        $strategy = new CacheStrategy();

        $analysis = [
            'operation_type' => 'read',
            'cache_key' => [
                'fingerprint' => 'abc123',
                'tables' => [
                    ['table' => 'users'],
                ],
            ],
        ];

        $this->assertTrue($strategy->shouldCache($analysis));
    }

    public function testShouldNotCacheWriteQueries(): void
    {
        $strategy = new CacheStrategy();

        $analysis = [
            'operation_type' => 'write',
            'cache_key' => [
                'fingerprint' => 'abc123',
                'tables' => [
                    ['table' => 'users'],
                ],
            ],
        ];

        $this->assertFalse($strategy->shouldCache($analysis));
    }

    public function testGetPrimaryTable(): void
    {
        $strategy = new CacheStrategy();

        $analysis = [
            'cache_key' => [
                'tables' => [
                    ['table' => 'users'],
                    ['table' => 'orders'],
                ],
            ],
        ];

        $this->assertEquals('users', $strategy->getPrimaryTable($analysis));
    }

    public function testGetPrimaryTableReturnsNullWhenNoTables(): void
    {
        $strategy = new CacheStrategy();

        $analysis = [
            'cache_key' => [
                'tables' => [],
            ],
        ];

        $this->assertNull($strategy->getPrimaryTable($analysis));
    }
}
