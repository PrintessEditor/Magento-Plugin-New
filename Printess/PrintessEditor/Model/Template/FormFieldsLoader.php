<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Template;

/**
 * Loads a template's form fields from Printess for the admin product form (PMD-19).
 *
 * Mirrors the Shopware PrintessFormFieldsClient: the template's fields are read from
 * `/template/formFields/list` (published version). A design template can name a base template
 * (`/template/details` → `template.baseTemplate`); the storefront opens the base template with the
 * design merged into it, so the base template's form fields are read too and merged by name
 * (the template's own fields win).
 *
 * The response keeps the raw Printess shape (`formFields`, `documentFormFields`, …) so existing
 * consumers keep working; the base template's fields are appended to `formFields`, and two keys
 * are added: `baseTemplate` (cleaned name, '' when none) and `baseTemplateError` ('' when fine).
 * A base template that cannot be read is reported, not fatal.
 *
 * The service token stays server-side and is never put into a message.
 */
class FormFieldsLoader
{
    private const API_BASE = 'https://api.printess.com';

    /**
     * @return array{status: int, body: string} the HTTP status and JSON body to send to the browser
     */
    public function load(string $serviceToken, string $templateName): array
    {
        $main = $this->request($serviceToken, '/template/formFields/list', [
            'templateName'        => $templateName,
            'usePublishedVersion' => true,
        ]);

        if ($main['error'] !== null) {
            return ['status' => 502, 'body' => (string) json_encode(['error' => $main['error']])];
        }

        // Errors and unexpected shapes are passed through unchanged, as before.
        if ($main['status'] >= 300 || !$main['data'] instanceof \stdClass) {
            return ['status' => $main['status'] ?: 500, 'body' => $main['raw']];
        }

        $data = $main['data'];
        $data->baseTemplate = '';
        $data->baseTemplateError = '';

        $details = $this->request($serviceToken, '/template/details', ['templateName' => $templateName]);
        $stored = '';
        if ($details['error'] !== null || $details['status'] >= 300) {
            $data->baseTemplateError = $this->failure('the template details', $details);
        } else {
            $template = ($details['data'] instanceof \stdClass && ($details['data']->template ?? null) instanceof \stdClass)
                ? $details['data']->template
                : null;
            $stored = ($template !== null && is_scalar($template->baseTemplate ?? null))
                ? trim((string) $template->baseTemplate)
                : '';
        }

        if ($stored !== '') {
            $data->baseTemplate = self::cleanTemplateName($stored);
            $base = $this->request($serviceToken, '/template/formFields/list', [
                'templateName'        => $data->baseTemplate,
                // The published version, which is what the storefront opens, unless the
                // stored name asks for the draft.
                'usePublishedVersion' => !str_contains($stored, '~draft'),
            ]);
            if ($base['error'] !== null || $base['status'] >= 300 || !$base['data'] instanceof \stdClass) {
                $data->baseTemplateError = $this->failure("the base template's form fields", $base);
            } else {
                $own = is_array($data->formFields ?? null) ? $data->formFields : [];
                $data->formFields = self::merge(
                    $own,
                    self::allFields($data),
                    self::allFields($base['data'])
                );
            }
        }

        return [
            'status' => $main['status'] ?: 200,
            'body'   => (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Strips the version/location markers Printess stores in the name: `~draft`, `~published`,
     * `~merged`, and a trailing `~gid` (template of the global account).
     */
    public static function cleanTemplateName(string $baseTemplate): string
    {
        $name = str_replace(['~draft', '~published', '~merged'], '', $baseTemplate);

        return str_ends_with($name, '~gid') ? substr($name, 0, -4) : $name;
    }

    /**
     * Appends every base field whose name the template does not define itself (first occurrence wins).
     *
     * @param list<mixed> $formFields  the template's top-level `formFields` (returned list starts with these)
     * @param list<mixed> $ownAll      all of the template's fields, global and per document (names to skip)
     * @param list<mixed> $baseAll     all of the base template's fields
     * @return list<mixed>
     */
    public static function merge(array $formFields, array $ownAll, array $baseAll): array
    {
        $seen = [];
        foreach ($ownAll as $field) {
            $name = self::nameOf($field);
            if ($name !== '') {
                $seen[$name] = true;
            }
        }

        $result = array_values($formFields);
        foreach ($baseAll as $field) {
            $name = self::nameOf($field);
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $result[] = $field;
        }

        return $result;
    }

    /**
     * Global fields first, then each document's own fields.
     *
     * @return list<mixed>
     */
    private static function allFields(\stdClass $data): array
    {
        $fields = is_array($data->formFields ?? null) ? array_values($data->formFields) : [];
        foreach (is_array($data->documentFormFields ?? null) ? $data->documentFormFields : [] as $document) {
            if ($document instanceof \stdClass && is_array($document->formFields ?? null)) {
                array_push($fields, ...array_values($document->formFields));
            }
        }

        return $fields;
    }

    private static function nameOf(mixed $field): string
    {
        if ($field instanceof \stdClass) {
            $field = (array) $field;
        }

        return is_array($field) && is_scalar($field['name'] ?? null) ? (string) $field['name'] : '';
    }

    /**
     * @param array{status: int, data: mixed, raw: string, error: ?string} $response
     */
    private function failure(string $subject, array $response): string
    {
        if ($response['error'] !== null) {
            return 'Could not read ' . $subject . ' from Printess: ' . $response['error'];
        }
        $data = $response['data'];
        $message = ($data instanceof \stdClass && is_string($data->m ?? null) && $data->m !== '')
            ? $data->m
            : 'HTTP ' . $response['status'];

        return 'Could not read ' . $subject . ' from Printess: ' . $message;
    }

    /**
     * JSON POST with the same conventions as AbstractProxy. Objects are decoded as stdClass so an
     * empty `{}` in the Printess response stays `{}` when re-encoded.
     *
     * @param array<string, mixed> $payload
     * @return array{status: int, data: mixed, raw: string, error: ?string}
     */
    private function request(string $serviceToken, string $path, array $payload): array
    {
        $ch = curl_init(self::API_BASE . $path);
        if ($ch === false) {
            return ['status' => 0, 'data' => null, 'raw' => '', 'error' => 'Printess API unreachable: curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $serviceToken,
            ],
        ]);
        $response   = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['status' => 0, 'data' => null, 'raw' => '', 'error' => 'Printess API unreachable: ' . $curlError];
        }

        $raw = (string) $response;
        $decoded = json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Never pass an HTML page (e.g. a Printess CDN 401/403 page) to the browser.
            return [
                'status' => $statusCode,
                'data'   => null,
                'raw'    => '',
                'error'  => sprintf(
                    'Printess API returned a non-JSON response (HTTP %d). '
                    . 'Check that the service token is correct in Printess Designer → API Token.',
                    $statusCode
                ),
            ];
        }

        return ['status' => $statusCode, 'data' => $decoded, 'raw' => $raw, 'error' => null];
    }
}
