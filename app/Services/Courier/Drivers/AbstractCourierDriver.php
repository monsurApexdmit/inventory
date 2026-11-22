<?php

namespace App\Services\Courier\Drivers;

use App\Models\CourierAccount;
use App\Services\Courier\CourierDriver;
use App\Services\Courier\CourierException;
use App\Services\Courier\ParcelData;
use App\Services\Courier\ParcelResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Shared HTTP plumbing: timeouts, GET-only retries, friendly errors. Never logs credentials or bodies. */
abstract class AbstractCourierDriver implements CourierDriver
{
    public function __construct(protected readonly CourierAccount $account)
    {
    }

    abstract protected function baseUrl(): string;

    /** Headers incl. auth for this provider. */
    abstract protected function headers(): array;

    protected function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl(), '/'))
            ->timeout((int) config('couriers.timeout', 15))
            ->connectTimeout((int) config('couriers.connect_timeout', 10))
            ->acceptJson()
            ->asJson()
            ->withHeaders($this->headers());
    }

    /**
     * Send a request and return the decoded JSON body. Throws CourierException on transport or HTTP errors.
     * GETs are retried twice on connection errors / 5xx; writes are never retried (they could double-book).
     */
    protected function send(string $method, string $path, array $data = []): array
    {
        $response = $this->raw($method, $path, $data);
        if ($response->failed()) {
            throw $this->errorFrom($response, $path);
        }
        $json = $response->json();
        return is_array($json) ? $json : [];
    }

    protected function raw(string $method, string $path, array $data = []): Response
    {
        $method = strtoupper($method);
        try {
            $req = $this->http();
            if ($method === 'GET') {
                $req = $req->retry(2, 300, fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response->serverError()), false);
                return $req->get($path, $data);
            }
            return $req->send($method, $path, ['json' => $data]);
        } catch (ConnectionException $e) {
            Log::warning("Courier {$this->provider()} unreachable", ['path' => $path, 'account' => $this->account->id]);
            throw new CourierException("{$this->label()} is not reachable right now. Try again in a minute.", 502, $this->provider());
        }
    }

    protected function errorFrom(Response $response, string $path): CourierException
    {
        $status = $response->status();
        Log::warning("Courier {$this->provider()} request failed", ['path' => $path, 'status' => $status, 'account' => $this->account->id]);
        $message = self::extractMessage($response->json()) ?? ($status === 401 || $status === 403
            ? 'the credentials were rejected'
            : ($response->serverError() ? 'the courier server returned an error' : "request failed (HTTP {$status})"));

        return new CourierException("{$this->label()}: {$message}", $response->serverError() ? 502 : 422, $this->provider());
    }

    /** Best readable message from a courier error body: first field error, else message. */
    public static function extractMessage(mixed $json): ?string
    {
        if (!is_array($json)) {
            return null;
        }
        $errors = $json['errors'] ?? null;
        if (is_array($errors)) {
            foreach ($errors as $field => $msgs) {
                $msg = is_array($msgs) ? (string) reset($msgs) : (string) $msgs;
                if ($msg !== '') {
                    return mb_substr($msg, 0, 300);
                }
            }
        } elseif (is_string($errors) && $errors !== '') {
            return mb_substr($errors, 0, 300);
        }
        foreach (['message', 'error', 'msg'] as $k) {
            if (!empty($json[$k]) && is_string($json[$k])) {
                return mb_substr($json[$k], 0, 300);
            }
        }
        return null;
    }

    /** Default bulk: one create per parcel (partial success). */
    public function bulkCreate(array $parcels): array
    {
        $out = [];
        foreach ($parcels as $p) {
            try {
                $out[$p->ref] = $this->createParcel($p);
            } catch (CourierException $e) {
                $out[$p->ref] = $e;
            }
        }
        return $out;
    }

    public function supportsCancel(): bool
    {
        return false;
    }

    public function cancel(\App\Models\OrderShipment $shipment): void
    {
        throw CourierException::unsupported($this->label(), 'cancelling a parcel through the API');
    }

    public function quote(array $params): array
    {
        return ['available' => false, 'deliveryFee' => null, 'codFeePercent' => null, 'message' => "{$this->label()} does not provide a price API"];
    }

    public function locations(string $type, ?int $parentId): array
    {
        throw CourierException::unsupported($this->label(), 'location lists');
    }

    public function stores(): array
    {
        throw CourierException::unsupported($this->label(), 'pickup stores');
    }

    public function customerSuccessRate(string $phone): ?array
    {
        return null;
    }

    public function trackingEvents(\App\Models\OrderShipment $shipment): ?array
    {
        return null;
    }

    protected static function trim(?string $v, int $max): ?string
    {
        $v = trim(preg_replace('/\s+/', ' ', (string) $v));
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** Drop bulky / personal keys before storing a courier response on the shipment. */
    protected static function slim(array $raw): array
    {
        $json = json_encode($raw);
        if ($json !== false && strlen($json) <= 4000) {
            return $raw;
        }
        return array_map(fn ($v) => is_scalar($v) || $v === null ? $v : '[…]', array_slice($raw, 0, 40, true));
    }
}
