<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * Lists the output files of a Printess production result (`result` of /production/status/get or of the
 * rendering callback). Which keys are filled depends on the print settings' output type:
 *
 *  - `r`   PDF output
 *  - `p`   picture output (jpg / png / tif / webp ...), one entry per page
 *  - `zip` one archive URL, when the print settings bundle the output
 *
 * `r` and `p` entries are objects `{d: document name, u: url, i: page/file index}`. Older results used a map
 * `{documentName: url}` (or a plain list of URLs), which is still accepted. `ff` (form fields JSON), `s`
 * (statistics) and `oi` (output info) are not production files and are ignored.
 *
 * Pure: no Magento dependencies.
 */
final class ProductionResultFiles
{
    public const TYPE_PDF   = 'pdf';
    public const TYPE_IMAGE = 'image';
    public const TYPE_ZIP   = 'zip';

    /**
     * @param mixed $result decoded `result` object (array) — anything else yields no files
     * @return list<array{type: string, document: string, index: int, url: string, extension: string}>
     */
    public static function fromResult(mixed $result): array
    {
        if (!is_array($result)) {
            return [];
        }

        $files = array_merge(
            self::entries($result['r'] ?? null, self::TYPE_PDF),
            self::entries($result['p'] ?? null, self::TYPE_IMAGE)
        );

        $zip = $result['zip'] ?? null;
        if (is_string($zip) && self::isHttpsUrl($zip)) {
            $files[] = self::file(self::TYPE_ZIP, '', 0, $zip);
        }

        // De-duplicate by URL, keep the first occurrence.
        $seen = [];
        $unique = [];
        foreach ($files as $file) {
            if (!isset($seen[$file['url']])) {
                $seen[$file['url']] = true;
                $unique[] = $file;
            }
        }
        return $unique;
    }

    /**
     * Short admin label, e.g. "Production (PDF)", "Production page 2 (JPG)", "Archive (ZIP)".
     *
     * @param array{type: string, document: string, index: int, extension: string} $file
     * @param bool $withPage whether the document has several files (then the page number is shown)
     */
    public static function label(array $file, bool $withPage): string
    {
        if ($file['type'] === self::TYPE_ZIP) {
            return 'Archive (ZIP)';
        }
        $name = $file['document'] !== '' ? $file['document'] : 'File';
        if ($withPage) {
            $name .= ' page ' . ($file['index'] + 1);
        }
        return $name . ' (' . strtoupper($file['extension']) . ')';
    }

    /**
     * Labels for a whole list, unique and in order (keys of the returned array are the list positions).
     *
     * @param list<array{type: string, document: string, index: int, url: string, extension: string}> $files
     * @return list<string>
     */
    public static function labels(array $files): array
    {
        $perDocument = [];
        foreach ($files as $file) {
            $key = $file['type'] . "\0" . $file['document'];
            $perDocument[$key] = ($perDocument[$key] ?? 0) + 1;
        }

        $labels = [];
        $used = [];
        foreach ($files as $file) {
            $label = self::label($file, $perDocument[$file['type'] . "\0" . $file['document']] > 1);
            $candidate = $label;
            for ($n = 2; isset($used[$candidate]); $n++) {
                $candidate = $label . ' #' . $n;
            }
            $used[$candidate] = true;
            $labels[] = $candidate;
        }
        return $labels;
    }

    /**
     * @return list<array{type: string, document: string, index: int, url: string, extension: string}>
     */
    private static function entries(mixed $list, string $type): array
    {
        if (!is_array($list)) {
            return [];
        }

        $files = [];
        $position = 0;
        foreach ($list as $key => $entry) {
            if (is_string($entry)) {
                // Legacy: {documentName: url} or [url, ...]
                if (self::isHttpsUrl($entry)) {
                    $files[] = self::file($type, is_string($key) ? $key : '', is_int($key) ? $key : 0, $entry);
                }
            } elseif (is_array($entry)) {
                $url = $entry['u'] ?? $entry['url'] ?? null;
                if (is_string($url) && self::isHttpsUrl($url)) {
                    $document = $entry['d'] ?? $entry['document'] ?? (is_string($key) ? $key : '');
                    $index = $entry['i'] ?? $entry['index'] ?? $position;
                    $files[] = self::file(
                        $type,
                        is_scalar($document) ? (string) $document : '',
                        is_numeric($index) ? max(0, (int) $index) : $position,
                        $url
                    );
                }
            }
            $position++;
        }
        return $files;
    }

    /** @return array{type: string, document: string, index: int, url: string, extension: string} */
    private static function file(string $type, string $document, int $index, string $url): array
    {
        return [
            'type'      => $type,
            'document'  => trim($document),
            'index'     => $index,
            'url'       => $url,
            'extension' => self::extension($url, $type),
        ];
    }

    private static function extension(string $url, string $type): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== '' && preg_match('/^[a-z0-9]{1,5}$/', $ext)) {
            return $ext;
        }
        return match ($type) {
            self::TYPE_PDF => 'pdf',
            self::TYPE_ZIP => 'zip',
            default        => 'bin',
        };
    }

    /** Only https:// files are downloaded into var/ (hardening from PR #8, Cron PDF download). */
    private static function isHttpsUrl(string $url): bool
    {
        return strtolower((string) parse_url(trim($url), PHP_URL_SCHEME)) === 'https';
    }
}
