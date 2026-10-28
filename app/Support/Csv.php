<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Small CSV download helper (UTF-8 with BOM so Excel shows ৳ and Bangla text correctly). */
class Csv
{
    /**
     * @param array<string, string|\Closure> $columns header => dot-path into the row, or fn(array $row)
     * @param iterable<array> $rows
     */
    public static function download(string $filename, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_keys($columns));
            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $col) {
                    $v = $col instanceof \Closure ? $col($row) : data_get($row, $col);
                    if (is_bool($v)) {
                        $v = $v ? 'Yes' : 'No';
                    } elseif (is_array($v)) {
                        $v = implode(', ', array_map('strval', $v));
                    }
                    $line[] = $v;
                }
                fputcsv($out, $line);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
