<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\ContentPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContentPageController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $pages = ContentPage::where('company_id', $companyId)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn(ContentPage $page) => $this->format($page));

        return $this->success($pages);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $this->validatePayload($request, $companyId);

        $page = ContentPage::create(array_merge($data, [
            'company_id' => $companyId,
            'published_at' => ($data['is_published'] ?? false) ? now() : null,
        ]));

        return $this->success($this->format($page), 'Content page created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $page = ContentPage::where('company_id', $companyId)->findOrFail($id);
        $data = $this->validatePayload($request, $companyId, $page->id, true);

        $nextPublished = array_key_exists('is_published', $data) ? (bool) $data['is_published'] : $page->is_published;
        $publishedAt = $page->published_at;

        if ($nextPublished && !$page->is_published) {
            $publishedAt = now();
        }

        if (!$nextPublished) {
            $publishedAt = null;
        }

        $page->update(array_merge($data, [
            'published_at' => $publishedAt,
        ]));

        return $this->success($this->format($page->fresh()), 'Content page updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $page = ContentPage::where('company_id', $companyId)->findOrFail($id);
        $page->delete();

        return $this->success(null, 'Content page deleted');
    }

    private function validatePayload(Request $request, int $companyId, ?int $pageId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'slug' => [
                $required,
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('content_pages', 'slug')
                    ->where(fn($query) => $query->where('company_id', $companyId))
                    ->ignore($pageId),
            ],
            'summary' => ['nullable', 'string'],
            'content' => [$required, 'array'],
            'content.template' => ['nullable', 'string', Rule::in(['about', 'faq', 'policy'])],
            'content.hero' => ['nullable', 'array'],
            'content.hero.kicker' => ['nullable', 'string'],
            'content.hero.title' => ['nullable', 'string'],
            'content.hero.description' => ['nullable', 'string'],
            'content.valuesHeading' => ['nullable', 'string'],
            'content.teamHeading' => ['nullable', 'string'],
            'content.stats' => ['nullable', 'array'],
            'content.values' => ['nullable', 'array'],
            'content.team' => ['nullable', 'array'],
            'content.faqCategories' => ['nullable', 'array'],
            'content.sections' => ['nullable', 'array'],
            'content.seo' => ['nullable', 'array'],
            'content.seo.title' => ['nullable', 'string'],
            'content.seo.description' => ['nullable', 'string'],
            'is_published' => ['sometimes', 'boolean'],
        ]);
    }

    private function format(ContentPage $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'summary' => $page->summary,
            'content' => $page->content,
            'isPublished' => (bool) $page->is_published,
            'publishedAt' => optional($page->published_at)?->toIso8601String(),
            'createdAt' => optional($page->created_at)?->toIso8601String(),
            'updatedAt' => optional($page->updated_at)?->toIso8601String(),
        ];
    }
}
