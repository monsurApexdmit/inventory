<?php

namespace App\Services\Setting;

use App\Models\ContentPage;
use App\Repositories\Contracts\IContentPageRepository;

class ContentPageService
{
    public function __construct(private readonly IContentPageRepository $repository)
    {
    }

    public function list(int $companyId): array
    {
        return $this->repository->findByCompany($companyId)
            ->map(fn (ContentPage $page) => $this->format($page))
            ->all();
    }

    public function create(int $companyId, array $data): array
    {
        $page = $this->repository->create(array_merge($data, [
            'company_id'   => $companyId,
            'published_at' => ($data['is_published'] ?? false) ? now() : null,
        ]));

        return $this->format($page);
    }

    public function update(int $id, int $companyId, array $data): array
    {
        $page = $this->repository->findForCompany($id, $companyId);

        $nextPublished = array_key_exists('is_published', $data)
            ? (bool) $data['is_published']
            : $page->is_published;
        $publishedAt = $page->published_at;

        if ($nextPublished && ! $page->is_published) {
            $publishedAt = now();
        }

        if (! $nextPublished) {
            $publishedAt = null;
        }

        $page = $this->repository->update($page, array_merge($data, [
            'published_at' => $publishedAt,
        ]));

        return $this->format($page);
    }

    public function delete(int $id, int $companyId): void
    {
        $page = $this->repository->findForCompany($id, $companyId);
        $this->repository->delete($page);
    }

    private function format(ContentPage $page): array
    {
        return [
            'id'          => $page->id,
            'title'       => $page->title,
            'slug'        => $page->slug,
            'summary'     => $page->summary,
            'content'     => $page->content,
            'isPublished' => (bool) $page->is_published,
            'publishedAt' => optional($page->published_at)?->toIso8601String(),
            'createdAt'   => optional($page->created_at)?->toIso8601String(),
            'updatedAt'   => optional($page->updated_at)?->toIso8601String(),
        ];
    }
}
