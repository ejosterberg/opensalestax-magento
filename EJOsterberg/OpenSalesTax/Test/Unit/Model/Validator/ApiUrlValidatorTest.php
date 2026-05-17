<?php
// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later
declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\Test\Unit\Model\Validator;

use EJOsterberg\OpenSalesTax\Model\Validator\ApiUrlValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ApiUrlValidatorTest extends TestCase
{
    public function testEmptyValueReturnsNull(): void
    {
        $validator = new ApiUrlValidator(false);

        self::assertNull($validator->validate(''));
    }

    public function testValidHttpsUrlWithRestrictOffReturnsNull(): void
    {
        $validator = new ApiUrlValidator(false);

        self::assertNull($validator->validate('https://ost.example.com'));
    }

    public function testValidHttpUrlWithRestrictOffReturnsNull(): void
    {
        $validator = new ApiUrlValidator(false);

        self::assertNull($validator->validate('http://ost.example.com:8080'));
    }

    public function testMalformedUrlIsRejected(): void
    {
        $validator = new ApiUrlValidator(false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/fully-qualified/');
        $validator->validate('not a url');
    }

    public function testUrlMissingSchemeIsRejected(): void
    {
        $validator = new ApiUrlValidator(false);

        $this->expectException(InvalidArgumentException::class);
        $validator->validate('ost.example.com');
    }

    public function testFtpSchemeIsRejected(): void
    {
        $validator = new ApiUrlValidator(false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/http or https/');
        $validator->validate('ftp://ost.example.com');
    }

    public function testFileSchemeIsRejected(): void
    {
        $validator = new ApiUrlValidator(false);

        $this->expectException(InvalidArgumentException::class);
        $validator->validate('file:///etc/passwd');
    }

    public function testWithRestrictOffPrivateIpsAreAllowedAndReturnNull(): void
    {
        $validator = new ApiUrlValidator(false);

        self::assertNull($validator->validate('http://192.168.1.100:8080'));
        self::assertNull($validator->validate('http://10.0.0.1:8080'));
        self::assertNull($validator->validate('http://127.0.0.1:8080'));
    }

    public function testWithRestrictOnPublicIpIsAcceptedAndReturnedForPinning(): void
    {
        $validator = new ApiUrlValidator(
            restrictToPublicIps: true,
            hostResolver: static fn(string $host): string => '8.8.8.8'
        );

        self::assertSame('8.8.8.8', $validator->validate('https://ost.example.com'));
    }

    public function testWithRestrictOnRfc1918IpIsRejected(): void
    {
        $validator = new ApiUrlValidator(
            restrictToPublicIps: true,
            hostResolver: static fn(string $host): string => $host
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/public IP/');
        $validator->validate('http://192.168.1.100:8080');
    }

    public function testWithRestrictOnLoopbackIsRejected(): void
    {
        $validator = new ApiUrlValidator(
            restrictToPublicIps: true,
            hostResolver: static fn(string $host): string => $host === 'localhost' ? '127.0.0.1' : $host
        );

        $this->expectException(InvalidArgumentException::class);
        $validator->validate('http://localhost:8080');
    }

    public function testWithRestrictOnLinkLocalIsRejected(): void
    {
        $validator = new ApiUrlValidator(
            restrictToPublicIps: true,
            hostResolver: static fn(string $host): string => '169.254.169.254'
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/public IP/');
        $validator->validate('http://metadata.example/');
    }

    public function testWithRestrictOnUnresolvableHostIsRejected(): void
    {
        $validator = new ApiUrlValidator(
            restrictToPublicIps: true,
            hostResolver: static fn(string $host): ?string => null
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/could not be resolved/');
        $validator->validate('https://no-such-host.invalid');
    }

    public function testDefaultResolverHandlesLiteralIpLiteralWithoutDns(): void
    {
        $validator = new ApiUrlValidator(restrictToPublicIps: true);

        $this->expectException(InvalidArgumentException::class);
        $validator->validate('http://10.20.30.40:8080');
    }
}
