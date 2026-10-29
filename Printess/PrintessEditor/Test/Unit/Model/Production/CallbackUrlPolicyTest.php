<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Test\Unit\Model\Production;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Printess\PrintessEditor\Model\Production\CallbackUrlPolicy;

/**
 * PMD-26: only public https domain names may be sent to Printess as callback URL (it answers 406 c102 otherwise).
 */
class CallbackUrlPolicyTest extends TestCase
{
    /**
     * @dataProvider urls
     */
    #[DataProvider('urls')]
    public function testGetProblem(string $url, ?string $expected): void
    {
        $policy = new CallbackUrlPolicy();
        self::assertSame($expected, $policy->getProblem($url));
        self::assertSame($expected === null, $policy->isUsable($url));
    }

    public static function urls(): array
    {
        return [
            'public https'            => ['https://shop.example.com/', null],
            'public https with path'  => ['https://example.com/de/', null],
            'with port'               => ['https://shop.example.com:8443/', null],
            'tunnel'                  => ['https://abc123.ngrok-free.app/', null],
            'upper-case scheme/host'  => ['HTTPS://Shop.Example.COM/', null],
            'empty'                   => ['', CallbackUrlPolicy::PROBLEM_NOT_HTTPS],
            'http'                    => ['http://shop.example.com/', CallbackUrlPolicy::PROBLEM_NOT_HTTPS],
            'no scheme'               => ['shop.example.com', CallbackUrlPolicy::PROBLEM_NOT_HTTPS],
            'relative'                => ['/printess/', CallbackUrlPolicy::PROBLEM_NOT_HTTPS],
            'ipv4'                    => ['https://192.168.1.10/', CallbackUrlPolicy::PROBLEM_IP_ADDRESS],
            'ipv6'                    => ['https://[::1]/', CallbackUrlPolicy::PROBLEM_IP_ADDRESS],
            'localhost'               => ['https://localhost/', CallbackUrlPolicy::PROBLEM_NOT_PUBLIC_HOST],
            'sub.localhost'           => ['https://magento.localhost/', CallbackUrlPolicy::PROBLEM_NOT_PUBLIC_HOST],
            'dot test'                => ['https://magento.test/', CallbackUrlPolicy::PROBLEM_NOT_PUBLIC_HOST],
            'dot local'               => ['https://shop.local/', CallbackUrlPolicy::PROBLEM_NOT_PUBLIC_HOST],
            'trailing dot local'      => ['https://shop.local./', CallbackUrlPolicy::PROBLEM_NOT_PUBLIC_HOST],
            'single label host'       => ['https://magento/', CallbackUrlPolicy::PROBLEM_NOT_PUBLIC_HOST],
            'query'                   => ['https://shop.example.com/?a=1', CallbackUrlPolicy::PROBLEM_QUERY_FRAGMENT],
            'fragment'                => ['https://shop.example.com/#x', CallbackUrlPolicy::PROBLEM_QUERY_FRAGMENT],
            'testing.com is public'   => ['https://testing.com/', null],
        ];
    }

    /**
     * @dataProvider overrides
     */
    #[DataProvider('overrides')]
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, (new CallbackUrlPolicy())->normalize($input));
    }

    public static function overrides(): array
    {
        return [
            'empty'               => ['', ''],
            'blank'               => ['   ', ''],
            'adds slash'          => ['https://shop.example.com', 'https://shop.example.com/'],
            'one slash only'      => [' https://shop.example.com/// ', 'https://shop.example.com/'],
            'adds https'          => ['abc.ngrok-free.app', 'https://abc.ngrok-free.app/'],
            'keeps path'          => ['https://example.com/de', 'https://example.com/de/'],
            'keeps http'          => ['http://shop.example.com', 'http://shop.example.com/'],
            'other scheme'        => ['ftp://shop.example.com', ''],
            'scheme only'         => ['https://', ''],
        ];
    }
}
