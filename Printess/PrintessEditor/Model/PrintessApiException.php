<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model;

/**
 * Error from a Printess API call. Callers need to distinguish two cases that look alike but must be
 * handled very differently for write calls (produce, dropshipData/save, order/retry):
 *  - transport errors (no HTTP response): the request may or may not have reached Printess, so the
 *    outcome is UNKNOWN and blindly retrying could create a duplicate order;
 *  - HTTP 4xx (incl. Printess' 402/406 `{c, m}` rejections): permanent, retrying the same request
 *    cannot succeed.
 * Messages never contain the service token.
 */
class PrintessApiException extends \RuntimeException
{
    private const BODY_EXCERPT_LENGTH = 300;

    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly ?int $printessCode = null,
        private readonly ?string $printessMessage = null,
        private readonly bool $transportError = false,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function transport(string $path, string $error): self
    {
        return new self('Printess API request to ' . $path . ' failed (transport error): ' . $error, null, null, null, true);
    }

    public static function http(string $path, int $status, ?int $c, ?string $m, string $bodyExcerpt): self
    {
        $message = 'Printess API error ' . $status . ' on ' . $path;
        if ($c !== null || $m !== null) {
            $message .= ': ' . ($c !== null ? '[' . $c . '] ' : '') . ($m ?? '');
        } elseif ($bodyExcerpt !== '') {
            $message .= ': ' . self::excerpt($bodyExcerpt);
        }

        return new self(rtrim($message), $status, $c, $m);
    }

    /**
     * A 2xx response we cannot use (non-JSON, missing jobId, ...). Treated like a server-side error:
     * not a transport error, not permanent.
     */
    public static function invalidResponse(string $path, string $reason, string $bodyExcerpt = '', ?int $status = null): self
    {
        $message = 'Invalid response from Printess API on ' . $path . ': ' . $reason;
        if ($bodyExcerpt !== '') {
            $message .= ' (' . self::excerpt($bodyExcerpt) . ')';
        }

        return new self($message, $status);
    }

    public static function notConfigured(?int $storeId): self
    {
        return new self(
            'Printess service token is not configured' . ($storeId !== null ? ' for store ' . $storeId : '')
        );
    }

    /**
     * Null when no HTTP response was received (transport error) or for local errors such as a missing token.
     */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getPrintessCode(): ?int
    {
        return $this->printessCode;
    }

    public function getPrintessMessage(): ?string
    {
        return $this->printessMessage;
    }

    public function isTransportError(): bool
    {
        return $this->transportError;
    }

    public function isPermanent(): bool
    {
        return $this->httpStatus !== null && $this->httpStatus >= 400 && $this->httpStatus < 500;
    }

    private static function excerpt(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');
        return mb_strlen($body) > self::BODY_EXCERPT_LENGTH
            ? mb_substr($body, 0, self::BODY_EXCERPT_LENGTH) . '…'
            : $body;
    }
}
