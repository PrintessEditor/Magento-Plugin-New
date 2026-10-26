<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model;

class PrintessApi
{
    /** Results of getSaveTokenState(). */
    public const TOKEN_STATE_ACTIVE = SaveTokenStatus::ACTIVE;
    public const TOKEN_STATE_INACTIVE = SaveTokenStatus::INACTIVE;
    /** The check failed (network, HTTP 5xx, auth, invalid response): we don't know whether the token is active. */
    public const TOKEN_STATE_UNKNOWN = SaveTokenStatus::UNKNOWN;

    private string $serviceToken;
    private string $apiUrl;

    public function __construct(string $serviceToken, string $apiUrl = 'https://api.printess.com')
    {
        $this->serviceToken = $serviceToken;
        $this->apiUrl       = rtrim($apiUrl, '/');
    }

    public function readUserSettings(array $keys): object
    {
        return $this->post('/user/settings/read', ['keys' => $keys]);
    }

    public function produce(string $saveToken, string $externalOrderId = '', string $printSettingsTemplate = ''): string
    {
        $payload = [
            'templateName'        => $saveToken,
            'externalOrderId'     => $externalOrderId,
            'usePublishedVersion' => true,
            'outputSettings'      => ['dpi' => 300],
            'origin'              => 'Magento',
        ];

        if ($printSettingsTemplate !== '') {
            $payload['printSettingsTemplate'] = $printSettingsTemplate;
        }

        $result = $this->post('/production/produce', $payload);

        if (empty($result->jobId)) {
            throw new \RuntimeException('Printess produce call returned no jobId');
        }

        return $result->jobId;
    }

    /**
     * Returns the raw status object for a production job.
     * Unlike pollUntilDone() this makes exactly ONE request — suitable for use in a cron job
     * where the caller is responsible for retrying on the next cron run.
     */
    public function getJobStatus(string $jobId): object
    {
        return $this->post('/production/status/get', ['jobId' => $jobId]);
    }

    /**
     * Polls the status endpoint until the job finishes, then returns the first PDF URL.
     * Throws on error or timeout.
     */
    public function pollUntilDone(string $jobId, int $maxAttempts = 60, int $sleepMs = 2000): string
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $status = $this->post('/production/status/get', ['jobId' => $jobId]);

            if (!empty($status->isFinalStatus) && $status->isFinalStatus === true) {
                if (empty($status->isSuccess) || $status->isSuccess !== true) {
                    throw new \RuntimeException(
                        'Printess production failed: ' . json_encode($status->errorDetails ?? 'unknown error')
                    );
                }

                foreach ((array)($status->result->r ?? []) as $url) {
                    return $url;
                }

                throw new \RuntimeException('Printess production succeeded but result contained no PDF URL');
            }

            usleep($sleepMs * 1000);
        }

        throw new \RuntimeException('Printess production timed out after ' . $maxAttempts . ' attempts for jobId ' . $jobId);
    }

    /**
     * Whether a save token still exists and hasn't expired, via `POST /shop/template/info` `{id}` (Printess's
     * documented save-token info call). Fails closed: any error counts as "not active", so callers never offer
     * to reopen a design that may no longer exist. Use getSaveTokenState() where an outage must not be
     * reported as "expired".
     */
    public function isSaveTokenActive(string $saveToken): bool
    {
        return $this->getSaveTokenState($saveToken) === self::TOKEN_STATE_ACTIVE;
    }

    /**
     * Tri-state check (TOKEN_STATE_*): INACTIVE only when Printess definitely says so (404 not found, deleted
     * marker or an expiry in the past); UNKNOWN for network errors, other HTTP errors or an unreadable answer.
     */
    public function getSaveTokenState(string $saveToken): string
    {
        return $this->getSaveTokenInfo($saveToken)['state'];
    }

    /**
     * Status and expiry of a save token from `POST /shop/template/info` `{id: "st:..."}`.
     * `expires_on` is an ISO-8601 UTC string, or null when the token never expires / isn't known.
     *
     * @return array{state: string, expires_on: ?string}
     */
    public function getSaveTokenInfo(string $saveToken): array
    {
        $saveToken = trim($saveToken);
        if ($saveToken === '') {
            return ['state' => self::TOKEN_STATE_INACTIVE, 'expires_on' => null];
        }

        try {
            $result = $this->request('/shop/template/info', ['id' => $saveToken]);
        } catch (PrintessApiException $e) {
            // 404 (empty body) = the save token doesn't exist (anymore).
            return $e->getHttpStatus() === 404
                ? SaveTokenStatus::fromInfo(null)
                : ['state' => self::TOKEN_STATE_UNKNOWN, 'expires_on' => null];
        } catch (\Throwable) {
            return ['state' => self::TOKEN_STATE_UNKNOWN, 'expires_on' => null];
        }

        return SaveTokenStatus::fromInfo(is_array($result) ? $result : []);
    }

    // ---------------------------------------------------------------------------------------------
    // Production / dropshipping API (PMD-02). All of these go through request() and throw
    // PrintessApiException, so callers can tell "outcome unknown" (transport) from "rejected" (4xx).
    // ---------------------------------------------------------------------------------------------

    /**
     * POST /production/produce with a caller-built body (see ProduceRequestBuilder).
     *
     * @return array{jobId: string, orderId: ?string}
     */
    public function productionProduce(array $body): array
    {
        return $this->requireJob('/production/produce', $this->request('/production/produce', $body));
    }

    /**
     * POST /dropship/produce — same body as /production/produce plus the `dropship` object.
     *
     * @return array{jobId: string, orderId: ?string}
     */
    public function dropshipProduce(array $body): array
    {
        return $this->requireJob('/dropship/produce', $this->request('/dropship/produce', $body));
    }

    /**
     * Stores a shipping address on Printess' side and returns its dropshipDataId. Printess answers
     * with a bare JSON number; {id} / {dropshipDataId} are accepted defensively. `json` is sent as an
     * object so an (unlikely) empty address still encodes as `{}`, not `[]`.
     */
    public function saveDropshipAddress(array $address, ?int $id = null, ?string $userId = null): int
    {
        $path = '/dropshipData/save';
        $body = ['type' => 'printess-shipping', 'json' => (object) $address];
        if ($id !== null) {
            $body['id'] = $id;
        }
        // Not in the documented body, but sent by the Shopware integration; Printess accepts any
        // string as long as it is unique per customer (decision D5: customer id or guest-<increment id>).
        if ($userId !== null && $userId !== '') {
            $body['userId'] = $userId;
        }

        $result = $this->request($path, $body);
        $value  = is_array($result) ? ($result['id'] ?? $result['dropshipDataId'] ?? null) : $result;

        if (is_int($value)) {
            return $value;
        }
        if ((is_string($value) && ctype_digit($value)) || (is_float($value) && floor($value) === $value)) {
            return (int) $value;
        }

        throw PrintessApiException::invalidResponse($path, 'no numeric dropshipDataId', (string) json_encode($result), 200);
    }

    /**
     * POST /productDefinitions/load. The endpoint 400s on a `[]` body, so an empty OBJECT is sent.
     * Returns the decoded response as-is (keys productDefinitions, dropshippers).
     */
    public function loadProductDefinitions(): array
    {
        return $this->requireArray('/productDefinitions/load', $this->request('/productDefinitions/load', new \stdClass()));
    }

    /**
     * POST /dropship/orders/list — polling fallback. $filter: startDate, endDate, take, skip.
     */
    public function listDropshipOrders(array $filter): array
    {
        return $this->requireArray('/dropship/orders/list', $this->request('/dropship/orders/list', (object) $filter));
    }

    /**
     * POST /order/retry — re-runs an existing Printess order under a new job/order id.
     *
     * @return array{jobId: string, orderId: ?string}
     */
    public function retryOrder(
        string $orderId,
        ?string $origin = null,
        bool $clearDropshipData = false,
        bool $reloadPaymentData = false
    ): array {
        $body = [
            'orderId'           => $orderId,
            'clearDropshipData' => $clearDropshipData,
            'reloadPaymentData' => $reloadPaymentData,
        ];
        if ($origin !== null && $origin !== '') {
            $body['origin'] = $origin;
        }

        return $this->requireJob('/order/retry', $this->request('/order/retry', $body));
    }

    /**
     * POST /production/status/get, decoded as an associative array (getJobStatus() returns an object
     * and is kept for back-compat).
     */
    public function getJobStatusArray(string $jobId): array
    {
        return $this->requireArray('/production/status/get', $this->request('/production/status/get', ['jobId' => $jobId]));
    }

    /**
     * @return array{jobId: string, orderId: ?string}
     */
    private function requireJob(string $path, mixed $result): array
    {
        $jobId = is_array($result) ? ($result['jobId'] ?? null) : null;
        if (!is_string($jobId) || $jobId === '') {
            throw PrintessApiException::invalidResponse($path, 'no jobId', (string) json_encode($result), 200);
        }

        $orderId = $result['orderId'] ?? null;

        return [
            'jobId'   => $jobId,
            'orderId' => is_scalar($orderId) && (string) $orderId !== '' ? (string) $orderId : null,
        ];
    }

    private function requireArray(string $path, mixed $result): array
    {
        if (!is_array($result)) {
            throw PrintessApiException::invalidResponse($path, 'expected a JSON object', (string) json_encode($result), 200);
        }

        return $result;
    }

    /**
     * curl-based JSON POST used by all PMD-02 methods. Objects in $payload stay JSON objects
     * (`(object) []` encodes as `{}`). Returns the decoded body (assoc arrays; bare scalars allowed,
     * e.g. /dropshipData/save returns a number). The token is never put into exception messages.
     *
     * @throws PrintessApiException
     */
    private function request(string $path, array|object $payload): mixed
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new PrintessApiException('Could not encode Printess API request for ' . $path . ': ' . $e->getMessage());
        }

        $ch = curl_init($this->apiUrl . $path);
        if ($ch === false) {
            throw PrintessApiException::transport($path, 'curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->serviceToken,
            ],
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false || $errno !== 0) {
            throw PrintessApiException::transport($path, $this->redact($error !== '' ? $error : 'curl error ' . $errno));
        }

        $response = (string) $response;
        $decoded  = null;
        $isJson   = false;
        if (trim($response) !== '') {
            try {
                $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
                $isJson  = true;
            } catch (\JsonException) {
                $isJson = false;
            }
        }

        if ($status >= 400) {
            $c = null;
            $m = null;
            if ($isJson && is_array($decoded)) {
                if (isset($decoded['c']) && is_numeric($decoded['c'])) {
                    $c = (int) $decoded['c'];
                }
                if (isset($decoded['m']) && is_scalar($decoded['m'])) {
                    $m = $this->redact((string) $decoded['m']);
                }
            }
            throw PrintessApiException::http($path, $status, $c, $m, $this->redact($response));
        }

        if ($status < 200 || $status >= 300) {
            throw PrintessApiException::invalidResponse($path, 'unexpected HTTP status ' . $status, $this->redact($response), $status ?: null);
        }

        if (!$isJson) {
            throw PrintessApiException::invalidResponse($path, 'response is not JSON', $this->redact($response), $status);
        }

        return $decoded;
    }

    private function redact(string $text): string
    {
        return $this->serviceToken !== '' ? str_replace($this->serviceToken, '***', $text) : $text;
    }

    private function post(string $path, array $payload): object
    {
        $url  = $this->apiUrl . $path;
        $body = json_encode($payload);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $this->serviceToken,
                    'Content-Length: ' . strlen($body),
                ]),
                'content'       => $body,
                'ignore_errors' => true,
                'timeout'       => 30,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);

        if ($response === false) {
            throw new \RuntimeException('Printess API request to ' . $path . ' failed (network error)');
        }

        // Check HTTP status from response headers
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/HTTP\/\S+\s+(\d+)/', $statusLine, $m) && (int)$m[1] >= 400) {
            throw new \RuntimeException('Printess API error ' . $m[1] . ' on ' . $path . ': ' . $response);
        }

        $decoded = json_decode($response);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON from Printess API: ' . substr($response, 0, 200));
        }

        return $decoded;
    }
}
