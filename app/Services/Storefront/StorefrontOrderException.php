<?php

namespace App\Services\Storefront;

/** A storefront order that cannot be placed; rendered as { success:false, message, errors? } with $status. */
class StorefrontOrderException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly ?array $errors = null)
    {
        parent::__construct($message, $status);
    }

    public function toResponse(array $errorKeyMap = []): \Illuminate\Http\JsonResponse
    {
        $body = ['success' => false, 'message' => $this->getMessage()];
        if ($this->errors !== null) {
            $errors = [];
            foreach ($this->errors as $k => $v) {
                $errors[$errorKeyMap[$k] ?? $k] = $v;
            }
            $body['errors'] = $errors;
        }

        return response()->json($body, $this->status);
    }
}
