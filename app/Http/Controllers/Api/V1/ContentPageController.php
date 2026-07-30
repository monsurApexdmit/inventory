<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Setting\ContentPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContentPageController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ContentPageService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->service->list($companyId));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $this->validatePayload($request, $companyId);

        return $this->success($this->service->create($companyId, $data), 'Content page created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $this->validatePayload($request, $companyId, $id, true);

        return $this->success($this->service->update($id, $companyId, $data), 'Content page updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $this->service->delete($id, $companyId);

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
}
