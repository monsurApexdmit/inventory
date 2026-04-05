<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ParseMultipartPutRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only process PUT/PATCH requests with multipart form-data
        if (!in_array($request->method(), ['PUT', 'PATCH']) || !str_contains($request->header('Content-Type', ''), 'multipart/form-data')) {
            return $next($request);
        }

        // Parse multipart form data manually
        $this->parseMultipartFormData($request);

        return $next($request);
    }

    private function parseMultipartFormData(Request $request): void
    {
        $contentType = $request->header('Content-Type', '');
        if (!preg_match('/boundary=([a-zA-Z0-9\-_]+)/', $contentType, $matches)) {
            return;
        }

        $boundary = $matches[1];
        $input = file_get_contents('php://input');
        $parts = explode('--' . $boundary, $input);

        $parsedData = [];
        $files = [];

        foreach ($parts as $part) {
            if (empty($part) || $part === '--') {
                continue;
            }

            // Split headers from body
            $parts_split = explode("\r\n\r\n", $part, 2);
            if (count($parts_split) !== 2) {
                continue;
            }

            [$headers, $body] = $parts_split;
            $body = rtrim($body, "\r\n");

            // Parse Content-Disposition header
            if (preg_match('/name="([^"]+)"(?:;\s*filename="([^"]+)")?/i', $headers, $matches)) {
                $fieldName = $matches[1];
                $fileName = $matches[2] ?? null;

                if ($fileName) {
                    // This is a file
                    $contentType = 'application/octet-stream';
                    if (preg_match('/Content-Type:\s*([^\r\n]+)/i', $headers, $typeMatch)) {
                        $contentType = trim($typeMatch[1]);
                    }

                    // Create a temporary file that persists
                    $tmpDir = sys_get_temp_dir();
                    $tmpName = tempnam($tmpDir, 'laravel_put_');
                    file_put_contents($tmpName, $body);

                    // Store file info for later processing
                    $files[$fieldName] = [
                        'name' => $fileName,
                        'type' => $contentType,
                        'tmp_name' => $tmpName,
                        'error' => 0,
                        'size' => strlen($body),
                    ];
                } else {
                    // Handle array notation for regular fields e.g. keep_images[]
                    if (preg_match('/^([^\[]+)\[\d*\]$/', $fieldName, $m)) {
                        $parsedData[$m[1]][] = $body;
                    } else {
                        $parsedData[$fieldName] = $body;
                    }
                }
            }
        }

        // Merge parsed data into request
        $request->merge($parsedData);

        // Handle files - support array notation e.g. image[0], image[1]
        if (!empty($files)) {
            $fileGroups = [];
            foreach ($files as $fieldName => $fileInfo) {
                $uploadedFile = new \Illuminate\Http\UploadedFile(
                    $fileInfo['tmp_name'],
                    $fileInfo['name'],
                    $fileInfo['type'],
                    $fileInfo['error'],
                    true
                );
                if (preg_match('/^([^\[]+)\[\d*\]$/', $fieldName, $m)) {
                    $fileGroups[$m[1]][] = $uploadedFile;
                } else {
                    $request->files->set($fieldName, $uploadedFile);
                }
            }
            foreach ($fileGroups as $baseName => $uploadedFiles) {
                $request->files->set($baseName, $uploadedFiles);
            }
        }
    }
}
