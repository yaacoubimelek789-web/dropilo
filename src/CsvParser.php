<?php
declare(strict_types=1);

/**
 * Parse CSV with header row; supports quoted newlines (RFC 4180).
 * Returns array of assoc arrays keyed by header names.
 */
class CsvParser
{
    public static function read(string $path): array
    {
        $fp = fopen($path, 'rb');
        if (!$fp) {
            throw new RuntimeException('Cannot open file: ' . $path);
        }
        $header = fgetcsv($fp, 0, ',', '"', '');
        if ($header === false) {
            fclose($fp);
            return [];
        }
        $header = array_map('trim', $header);
        $rows = [];
        while (($row = fgetcsv($fp, 0, ',', '"', '')) !== false) {
            if (count($row) !== count($header)) {
                $row = array_pad($row, count($header), '');
            }
            $rows[] = array_combine($header, $row);
        }
        fclose($fp);
        return $rows;
    }
}
