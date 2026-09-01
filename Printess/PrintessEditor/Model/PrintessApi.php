<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model;

class PrintessApi
{
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
     * Checks whether a previously issued save token is still active/valid on the Printess
     * side. Save tokens expire, and unlike produce()/getJobStatus() (which are only ever
     * called for tokens we just created ourselves), this is used to validate a save token
     * that may be weeks or months old — e.g. when a customer wants to reorder a past order's
     * personalised item. Fails closed: any network/API error, or an inactive/expired result,
     * is treated as "not active" so callers never offer to reopen a design that may no
     * longer exist.
     */
    public function isSaveTokenActive(string $saveToken): bool
    {
        $saveToken = trim($saveToken);
        if ($saveToken === '') {
            return false;
        }

        try {
            $result = $this->postList('/savetoken/list', ['saveToken' => $saveToken]);
        } catch (\Throwable $e) {
            return false;
        }

        return $this->isTokenRecordActive($result);
    }

    /**
     * Interprets the decoded /savetoken/list response. The exact shape wasn't verified against
     * the live API ahead of this change, so this accepts several plausible shapes defensively:
     * a bare list of matching tokens, an object wrapping the list under a common key, or a
     * single record carrying an explicit active/expired flag. Any other non-empty payload is
     * treated as "found" (active); an empty result is treated as "not found" (inactive).
     */
    private function isTokenRecordActive(array $result): bool
    {
        if ($result === []) {
            return false;
        }

        if (array_is_list($result)) {
            return count($result) > 0;
        }

        foreach (['items', 'saveTokens', 'data', 'results'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                return count($result[$key]) > 0;
            }
        }

        if (array_key_exists('expired', $result)) {
            return !$result['expired'];
        }

        foreach (['active', 'isActive', 'valid'] as $key) {
            if (array_key_exists($key, $result)) {
                return (bool) $result[$key];
            }
        }

        return true;
    }

    /**
     * Like post(), but decodes to an associative array instead of an object, and treats a
     * 404 as an empty (not found) result rather than throwing — an unknown/expired save
     * token is an expected outcome here, not an API error.
     */
    private function postList(string $path, array $payload): array
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
                'timeout'       => 15,
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

        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/HTTP\/\S+\s+(\d+)/', $statusLine, $m)) {
            $status = (int) $m[1];
            if ($status === 404) {
                return [];
            }
            if ($status >= 400) {
                throw new \RuntimeException('Printess API error ' . $status . ' on ' . $path . ': ' . $response);
            }
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON from Printess API: ' . substr($response, 0, 200));
        }

        return is_array($decoded) ? $decoded : [];
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
