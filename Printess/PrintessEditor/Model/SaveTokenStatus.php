<?php
declare(strict_types=1);

namespace Printess\PrintessEditor\Model;

/**
 * Interprets Printess's `POST /shop/template/info` answer for a save token (KB: Save Tokens and Shop →
 * "save-token-info"). Request `{id: "st:..."}`; response `{createdOn, expiresOn, basketId, shopUserId,
 * shopUserDisplay}` or HTTP 404 (empty body) when the token doesn't exist.
 *
 * `expiresOn`:
 *  - `null`                     → never expires                → active
 *  - `1999-01-01T00:00:00Z`     → deleted (Printess's marker)   → inactive
 *  - a date in the past or now  → expired                        → inactive
 *  - a date in the future       → active until then
 *  - missing / unparseable      → unknown (the caller decides; never claim "expired" on a guess)
 *
 * Pure: no Magento dependencies.
 */
final class SaveTokenStatus
{
    public const ACTIVE   = 'active';
    public const INACTIVE = 'inactive';
    public const UNKNOWN  = 'unknown';

    /** Printess marks deleted save tokens with this expiry. */
    private const DELETED_MARKER = '1999-01-01T00:00:00Z';

    /**
     * @param array<string, mixed>|null $info decoded response, or null when Printess answered 404 (not found)
     * @return array{state: string, expires_on: ?string}
     */
    public static function fromInfo(?array $info, ?\DateTimeImmutable $now = null): array
    {
        if ($info === null) {
            return ['state' => self::INACTIVE, 'expires_on' => null];
        }

        if (!array_key_exists('expiresOn', $info)) {
            return ['state' => self::UNKNOWN, 'expires_on' => null];
        }

        $raw = $info['expiresOn'];
        if ($raw === null) {
            return ['state' => self::ACTIVE, 'expires_on' => null];
        }
        if (!is_string($raw) || trim($raw) === '') {
            return ['state' => self::UNKNOWN, 'expires_on' => null];
        }

        try {
            $expires = new \DateTimeImmutable(trim($raw));
        } catch (\Exception) {
            return ['state' => self::UNKNOWN, 'expires_on' => null];
        }

        $deleted = new \DateTimeImmutable(self::DELETED_MARKER);
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $iso = $expires->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        if ($expires <= $deleted || $expires <= $now) {
            return ['state' => self::INACTIVE, 'expires_on' => $iso];
        }

        return ['state' => self::ACTIVE, 'expires_on' => $iso];
    }
}
