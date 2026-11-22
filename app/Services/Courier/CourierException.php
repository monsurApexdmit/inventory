<?php

namespace App\Services\Courier;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A courier call failed. The message is safe to show to staff ("Steadfast: Invalid phone number").
 * 422 = the courier rejected the request; 502 = the courier could not be reached / errored.
 */
class CourierException extends HttpException
{
    public function __construct(string $message, int $status = 422, public readonly ?string $provider = null, public readonly bool $unsupported = false)
    {
        parent::__construct($status, $message);
    }

    public static function unsupported(string $label, string $what): self
    {
        return new self("{$label} does not support {$what}", 422, null, true);
    }
}
