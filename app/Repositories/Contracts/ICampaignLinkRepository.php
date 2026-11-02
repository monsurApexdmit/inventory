<?php

namespace App\Repositories\Contracts;

use App\Models\CampaignLink;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ICampaignLinkRepository
{
    public function paginateByCompany(int $companyId, array $filters): LengthAwarePaginator;

    public function findByIdAndCompany(int $id, int $companyId): ?CampaignLink;

    /** Active, not deleted link by its code (case-insensitive). */
    public function findActiveByCode(string $code, int $companyId): ?CampaignLink;

    /** Includes deleted links so a code is never reused. */
    public function codeExists(string $code, int $companyId, ?int $excludeId = null): bool;

    public function create(array $data): CampaignLink;

    public function update(CampaignLink $link, array $data): CampaignLink;

    public function delete(CampaignLink $link): void;

    /** @return array<int, int> link id => clicks in [from, to] (from null = since ever) */
    public function clicksByLink(int $companyId, array $linkIds, ?\DateTimeInterface $from, \DateTimeInterface $to): array;

    /** @return array<int, array{orders:int, revenue:float}> link id => order stats in [from, to] */
    public function ordersByLink(int $companyId, array $linkIds, ?\DateTimeInterface $from, \DateTimeInterface $to): array;

    /** Clicks in [from, to] per link channel. @return array<string, int> */
    public function clicksByChannel(int $companyId, ?\DateTimeInterface $from, \DateTimeInterface $to): array;

    /** Orders with an ad source in [from, to] per ad source. @return array<string, array{orders:int, revenue:float}> */
    public function ordersByAdSource(int $companyId, ?\DateTimeInterface $from, \DateTimeInterface $to): array;

    /** Status breakdown of one link's orders. @return array{orders:int, revenue:float, pending:int, confirmed:int, delivered:int, cancelled:int} */
    public function linkOrderBreakdown(int $companyId, int $linkId, ?\DateTimeInterface $from, \DateTimeInterface $to): array;

    /** @return array<string, int> Y-m-d => clicks */
    public function clicksPerDay(int $linkId, ?\DateTimeInterface $from, \DateTimeInterface $to): array;

    /** @return array<string, array{orders:int, revenue:float}> Y-m-d => orders */
    public function ordersPerDay(int $companyId, int $linkId, ?\DateTimeInterface $from, \DateTimeInterface $to): array;

    /** @return \Illuminate\Support\Collection<int, \App\Models\Sell> */
    public function recentOrders(int $companyId, int $linkId, int $limit = 10): \Illuminate\Support\Collection;

    /** Top links by orders then revenue in [from, to]. @return array<int, array{id:int, orders:int, revenue:float}> */
    public function topLinks(int $companyId, ?\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 5): array;

    public function recentClickExists(int $linkId, string $ipHash, \DateTimeInterface $since): bool;

    public function recordClick(CampaignLink $link, array $click): void;

    /** @return array{links:int, activeLinks:int} */
    public function linkCounts(int $companyId): array;
}
