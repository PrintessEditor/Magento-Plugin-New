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

    public function getJobStatus(string $jobId): object
    {
        return $this->post('/production/status/get', ['jobId' => $jobId]);
    }

    /**
     * Fetch verified book info for a saved design.
     *
     * Returns an associative array with:
     *   - inside.pageCount          (int)   current page count in the design
     *   - inside.minimumSpreadCount (int)   template minimum spreads → minPages = spreads*2-2
     *   - formFieldData.formFields  (array) saved form field values
     *
     * @param  string $saveToken  The st:... token from the Printess editor
     * @return array              Decoded response as a nested array
     * @throws \RuntimeException  On API or network failure
     */
    public function bookInfo(string $saveToken): array
    {
        $result = $this->post('/book/info', ['saveToken' => $saveToken]);
        // Convert stdClass tree to plain array
        return json_decode(json_encode($result), true) ?: [];
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

        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- file_get_contents is the appropriate
        // method here; error suppression replaced with set_error_handler to capture network failures.
        set_error_handler(static function (int $errno, string $errstr) use ($url): never {
            throw new \RuntimeException('Printess API request to ' . $url . ' failed: ' . $errstr, $errno);
        });
        try {
            $response = file_get_contents($url, false, $ctx);
        } finally {
            restore_error_handler();
        }

        if ($response === false) {
            throw new \RuntimeException('Printess API request to ' . $url . ' failed (network error)');
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
