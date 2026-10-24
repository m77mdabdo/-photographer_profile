<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ContactAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContactAddressTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function addresses(): array
    {
        return [
            'the old default' => ['studio@example.com', true],
            'any example domain' => ['owner@mail.example.org', true],
            'a .test address' => ['owner@anasfareed.test', true],
            'a .invalid address' => ['harness@harness.invalid', true],
            'localhost' => ['root@localhost', true],
            'empty' => ['', true],
            'not an address' => ['the studio', true],
            'a real-looking address' => ['hello@anasfareed.com', false],
            'a real subdomain' => ['studio@mail.anasfareed.co', false],
            'case does not matter' => ['Studio@Example.COM', true],
        ];
    }

    #[DataProvider('addresses')]
    public function test_placeholder_addresses_are_recognised(string $address, bool $placeholder): void
    {
        $this->assertSame($placeholder, ContactAddress::isPlaceholder($address), $address);
    }
}
