<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Which account each ledger line posts to: lines with an explicit account (manual entries, transfers)
 * use it; every other line goes to the account whose method_keys contain the line's method key,
 * else to the default account.
 */
final class FinanceAccountMap
{
    /** @var array<int, FinanceAccount> */
    public array $accounts = [];

    /** @var array<string, int> method key => account id */
    public array $keyToAccount = [];

    public ?int $defaultId = null;

    /** Company of the map (set by FinanceAccountService::map). */
    public ?int $companyId = null;

    /** @param Collection<int, FinanceAccount> $accounts */
    public function __construct(Collection $accounts)
    {
        foreach ($accounts as $a) {
            $this->accounts[(int) $a->id] = $a;
            foreach ((array) ($a->method_keys ?? []) as $k) {
                $k = (string) $k;
                if ($k !== '' && !isset($this->keyToAccount[$k])) {
                    $this->keyToAccount[$k] = (int) $a->id;
                }
            }
            if ($a->is_default && $this->defaultId === null) {
                $this->defaultId = (int) $a->id;
            }
        }
        if ($this->defaultId === null && $this->accounts) {
            $this->defaultId = (int) array_key_first($this->accounts);
        }
    }

    public function resolve(mixed $accountId, ?string $mkey): ?int
    {
        if ($accountId !== null && $accountId !== '') {
            return (int) $accountId;
        }
        if ($mkey !== null && isset($this->keyToAccount[$mkey])) {
            return $this->keyToAccount[$mkey];
        }

        return $this->defaultId;
    }

    public function name(?int $id): ?string
    {
        return $id !== null && isset($this->accounts[$id]) ? (string) $this->accounts[$id]->name : null;
    }

    /** Limit a ledger builder (alias l) to the lines of one account. */
    public function applyAccountFilter(Builder $q, int $accountId): Builder
    {
        $keys = array_keys(array_filter($this->keyToAccount, fn ($id) => $id === $accountId));
        $isDefault = $accountId === $this->defaultId;
        $mapped = array_keys($this->keyToAccount);

        return $q->where(function ($w) use ($accountId, $keys, $isDefault, $mapped) {
            $w->where('l.account_id', $accountId)
                ->orWhere(function ($x) use ($keys, $isDefault, $mapped) {
                    $x->whereNull('l.account_id')->where(function ($y) use ($keys, $isDefault, $mapped) {
                        $y->whereIn('l.mkey', $keys ?: ['__none__']);
                        if ($isDefault) {
                            $mapped ? $y->orWhereNotIn('l.mkey', $mapped) : $y->orWhereRaw('1 = 1');
                        }
                    });
                });
        });
    }
}
