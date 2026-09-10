<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model;

/**
 * Shared validation for Printess thumbnail URLs before they are persisted.
 *
 * Thumbnail URLs are attacker/client-controlled (submitted via cart/project save requests)
 * and get stored on quote items, orders and saved projects, then rendered in the cart,
 * account "My Projects" page and admin order view. Escaping at every render site is not
 * a substitute for validating at the point of storage, so all write paths must go through
 * this validator rather than persisting the raw client value.
 */
class ThumbnailUrlValidator
{
    private const MAX_LENGTH = 2048;

    /**
     * Return the URL if it is a well-formed, length-bounded HTTPS URL, or null otherwise.
     *
     * The Printess SDK may pass action signals (e.g. "close") as the thumbnail argument
     * rather than a real URL — treat anything that isn't a valid HTTPS URL as absent rather
     * than failing the caller.
     *
     * @param  string|null $thumbnailUrl
     * @return string|null
     */
    public function validate(?string $thumbnailUrl): ?string
    {
        $thumbnailUrl = trim((string)$thumbnailUrl);

        if ($thumbnailUrl === '' || !str_starts_with(strtolower($thumbnailUrl), 'https://')) {
            return null;
        }

        if (mb_strlen($thumbnailUrl) > self::MAX_LENGTH || filter_var($thumbnailUrl, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $thumbnailUrl;
    }
}
