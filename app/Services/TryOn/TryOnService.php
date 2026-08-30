<?php

namespace App\Services\TryOn;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Virtual try-on: composites a user photo with a garment image and returns a
 * data-URI image. Nothing is persisted (client-only feature).
 *
 * Provider is chosen by config('services.tryon.provider'):
 *   - 'huggingface' : calls a Gradio IDM-VTON Space (free, queue-based, flaky)
 *   - 'mock'        : returns the user photo as-is (dev/no-cost fallback)
 *
 * The mock keeps the whole UI flow working end-to-end with zero external
 * dependency; swap to 'huggingface' (or a paid provider later) via env.
 */
class TryOnService
{
    public function generate(UploadedFile $userPhoto, string $garmentUrl, ?string $category): array
    {
        if (!$userPhoto->isValid() || !Str::startsWith((string) $userPhoto->getMimeType(), 'image/')) {
            throw new HttpException(422, 'Please upload a valid image.');
        }
        if ($userPhoto->getSize() > 8 * 1024 * 1024) {
            throw new HttpException(422, 'Image too large (max 8MB).');
        }

        $provider = config('services.tryon.provider', 'mock');

        return match ($provider) {
            'huggingface' => $this->viaHuggingFace($userPhoto, $garmentUrl, $category),
            default       => $this->viaMock($userPhoto),
        };
    }

    /** Fallback: echo the user's photo back as a data URI. */
    private function viaMock(UploadedFile $userPhoto): array
    {
        $data = base64_encode(file_get_contents($userPhoto->getRealPath()));
        return [
            'image'    => 'data:' . $userPhoto->getMimeType() . ';base64,' . $data,
            'provider' => 'mock',
            'note'     => 'Preview only — connect a try-on provider for AI results.',
        ];
    }

    /**
     * Calls a Gradio IDM-VTON Space. Space + endpoint come from config so a
     * different Space can be dropped in without code changes. Free Spaces are
     * queue-based and can be asleep; failures surface as a friendly 503.
     */
    private function viaHuggingFace(UploadedFile $userPhoto, string $garmentUrl, ?string $category): array
    {
        $space = config('services.tryon.hf_space');          // e.g. https://<user>-idm-vton.hf.space
        $token = config('services.tryon.hf_token');          // optional HF token for higher limits
        if (!$space) {
            throw new HttpException(503, 'Try-on is not configured.');
        }

        $garment = @file_get_contents($garmentUrl);
        if ($garment === false) {
            throw new HttpException(422, 'Could not load the garment image.');
        }

        $person = base64_encode(file_get_contents($userPhoto->getRealPath()));
        $garmentB64 = base64_encode($garment);

        $headers = ['Content-Type' => 'application/json'];
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            // Gradio's queue API: submit, then poll. Payload shape varies per
            // Space; this targets the common IDM-VTON signature
            // (person image, garment image, description).
            $submit = Http::withHeaders($headers)->timeout(30)->post(rtrim($space, '/') . '/call/tryon', [
                'data' => [
                    ['name' => 'person.jpg',  'data' => 'data:' . $userPhoto->getMimeType() . ';base64,' . $person],
                    ['name' => 'garment.jpg', 'data' => 'data:image/jpeg;base64,' . $garmentB64],
                    (string) ($category ?? 'upper_body'),
                ],
            ]);

            if (!$submit->successful()) {
                throw new HttpException(503, 'Try-on service is busy. Please try again.');
            }

            $eventId = $submit->json('event_id');
            if (!$eventId) {
                throw new HttpException(503, 'Try-on service did not respond as expected.');
            }

            // Poll the SSE result endpoint (bounded).
            $deadline = time() + 60;
            do {
                usleep(1_500_000);
                $poll = Http::withHeaders($headers)->timeout(30)
                    ->get(rtrim($space, '/') . '/call/tryon/' . $eventId);
                $body = $poll->body();
                if (Str::contains($body, '"msg":"process_completed"') || Str::contains($body, 'data:image')) {
                    if (preg_match('/(data:image\/[a-zA-Z]+;base64,[A-Za-z0-9+\/=]+)/', $body, $m)) {
                        return ['image' => $m[1], 'provider' => 'huggingface'];
                    }
                }
            } while (time() < $deadline);

            throw new HttpException(504, 'Try-on timed out. Please try again.');
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw new HttpException(503, 'Try-on is temporarily unavailable.');
        }
    }
}
