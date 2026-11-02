<?php

namespace App\DTOs\Marketing;

use App\DTOs\BaseMapper;
use App\Models\CampaignLink;
use App\Models\Collection;
use App\Models\Product;
use App\Support\StorefrontUrl;
use Illuminate\Database\Eloquent\Model;

/**
 * CampaignLink → DTO, including the shareable URLs.
 * Pass $context['stats'] = [clicks, orders, revenue, conversionRate] and optionally preloaded
 * $context['targets'] = ['product' => [id => Product], 'collection' => [id => Collection]] to avoid per-row queries.
 */
class CampaignLinkMapper extends BaseMapper
{
    public function toDTO(Model $model, array $context = []): CampaignLinkDTO
    {
        if (!$model instanceof CampaignLink) {
            throw new \InvalidArgumentException('Model must be instance of CampaignLink');
        }

        $target = $this->target($model, $context['targets'] ?? null);
        $targetPath = self::targetPath($model, $target);
        $base = StorefrontUrl::base((int) $model->company_id);
        $stats = $context['stats'] ?? ['clicks' => 0, 'orders' => 0, 'revenue' => 0.0, 'conversionRate' => 0.0];

        return new CampaignLinkDTO(
            id: (int) $model->id,
            name: (string) $model->name,
            channel: (string) $model->channel,
            targetType: (string) $model->target_type,
            targetId: $model->target_id !== null ? (int) $model->target_id : null,
            target: $target,
            code: (string) $model->code,
            utmSource: $model->utm_source,
            utmMedium: $model->utm_medium,
            utmCampaign: $model->utm_campaign,
            utmContent: $model->utm_content,
            notes: $model->notes,
            isActive: (bool) $model->is_active,
            createdBy: $model->created_by !== null ? (int) $model->created_by : null,
            createdByName: $model->relationLoaded('creator') ? $model->creator?->full_name : null,
            storeBaseUrl: $base,
            targetPath: $targetPath,
            fullUrl: $base ? $base . $targetPath : null,
            shortUrl: $base ? $base . '/l/' . $model->code : null,
            stats: $stats,
            lastClickedAt: $this->formatTimestamp($model->last_clicked_at),
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }

    /** Relative storefront path + utm query + cl=<code>, e.g. /product/blue-panjabi?utm_source=facebook&...&cl=k7m2qx */
    public static function targetPath(CampaignLink $link, ?array $target = null): string
    {
        $key = $target ? (($target['slug'] ?? '') !== '' ? $target['slug'] : (string) $target['id']) : (string) $link->target_id;
        $path = match ($link->target_type) {
            'product' => '/product/' . rawurlencode($key),
            'quick_order' => '/order/' . rawurlencode($key),
            'collection' => '/collections/' . rawurlencode($key),
            default => '/',
        };
        $query = array_filter([
            'utm_source' => $link->utm_source,
            'utm_medium' => $link->utm_medium,
            'utm_campaign' => $link->utm_campaign,
            'utm_content' => $link->utm_content,
        ], fn ($v) => $v !== null && $v !== '');
        $query['cl'] = $link->code;

        return $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function target(CampaignLink $link, ?array $preloaded): ?array
    {
        if ($link->target_id === null || !in_array($link->target_type, ['product', 'quick_order', 'collection'], true)) {
            return null;
        }
        $kind = $link->target_type === 'collection' ? 'collection' : 'product';
        $row = $preloaded !== null
            ? ($preloaded[$kind][(int) $link->target_id] ?? null)
            : ($kind === 'collection'
                ? Collection::where('company_id', $link->company_id)->find($link->target_id)
                : Product::with('images')->where('company_id', $link->company_id)->find($link->target_id));
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'slug' => $row->slug,
            'image' => $kind === 'product' ? self::productImage($row) : $row->image,
        ];
    }

    public static function productImage(Product $p): ?string
    {
        if ($p->relationLoaded('images') && $p->images->count()) {
            return $p->images->firstWhere('is_primary', true)?->path ?? $p->images->sortBy('position')->first()?->path ?? $p->image;
        }

        return $p->image;
    }
}
