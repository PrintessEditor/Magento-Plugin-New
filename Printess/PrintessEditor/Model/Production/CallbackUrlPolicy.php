<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * PURE: decides whether a base URL can be handed to Printess as callback URL (PMD-26).
 *
 * Printess rejects callback URLs that are not https or that point at an IP address, localhost or a
 * non-public host (HTTP 406, error c102 at submit time), which would fail production. Used for the store's
 * own base URL (unusable → fall back to polling) and to validate the "Callback URL" override on save.
 */
class CallbackUrlPolicy
{
    public const PROBLEM_NOT_HTTPS       = 'not_https';
    public const PROBLEM_IP_ADDRESS      = 'ip_address';
    public const PROBLEM_NOT_PUBLIC_HOST = 'not_public_host';
    public const PROBLEM_QUERY_FRAGMENT  = 'query_or_fragment';

    /** Host suffixes that never resolve publicly (RFC 6761 / mDNS). */
    private const PRIVATE_SUFFIXES = ['.localhost', '.test', '.local'];

    public function isUsable(string $url): bool
    {
        return $this->getProblem($url) === null;
    }

    /**
     * Why the URL cannot be used (one of the PROBLEM_* constants), or null when it is usable.
     */
    public function getProblem(string $url): ?string
    {
        $url = trim($url);
        $parts = $url === '' ? false : parse_url($url);
        if (!is_array($parts)) {
            return self::PROBLEM_NOT_HTTPS;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(rtrim(trim((string)($parts['host'] ?? ''), '[]'), '.'));
        if ($scheme !== 'https' || $host === '') {
            return self::PROBLEM_NOT_HTTPS;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::PROBLEM_IP_ADDRESS;
        }

        if ($host === 'localhost' || !str_contains($host, '.')) {
            return self::PROBLEM_NOT_PUBLIC_HOST;
        }
        foreach (self::PRIVATE_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return self::PROBLEM_NOT_PUBLIC_HOST;
            }
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            return self::PROBLEM_QUERY_FRAGMENT;
        }

        return null;
    }

    /**
     * Cleans a merchant-entered override like Shopware's getCallbackBaseUrl(): trims it, adds "https://"
     * when no scheme is given, and ends it with exactly one '/'. Returns '' for an empty value or a
     * non-http(s) scheme. Does not check usability; call isUsable() on the result.
     */
    public function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) && !preg_match('#^https?://#i', $url)) {
            return '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $url = rtrim($url, '/');
        return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url . '/' : '';
    }
}
