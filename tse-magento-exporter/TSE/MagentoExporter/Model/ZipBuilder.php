<?php
declare(strict_types=1);

namespace TSE\MagentoExporter\Model;

/**
 * Builds a ZIP archive in a temp file from a {filename => array payload} map
 * and returns the path. Caller is responsible for streaming and unlinking.
 */
class ZipBuilder
{
    public function build(array $bundle, string $basename = 'tse-magento-export'): string
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP ZipArchive extension is not available.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tsemag-') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create ZIP archive at ' . $tmp);
        }

        foreach ($bundle as $filename => $payload) {
            $json = json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if ($json === false) continue;
            $zip->addFromString($filename, $json);
        }
        $zip->close();
        return $tmp;
    }
}
