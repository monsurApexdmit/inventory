<?php

namespace App\Services\Customer;

use App\DTOs\Customer\CustomerDTO;
use App\DTOs\Customer\CustomerMapper;
use App\Models\User;
use App\Models\Customer;
use App\Models\Sell;
use Illuminate\Support\Facades\DB;
use App\Repositories\Contracts\ICustomerRepository;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerService
{
    private readonly CustomerMapper $mapper;

    public function __construct(private readonly ICustomerRepository $repository)
    {
        $this->mapper = new CustomerMapper();
    }

    public function list(int $companyId, array $filters): array
    {
        $paginated = $this->repository->findByCompany($companyId, $filters);
        $data = array_map(fn ($customer) => $this->mapper->toDTO($customer), $paginated->items());
        return [
            'data' => $data,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'counts' => $this->repository->getCounts($companyId),
        ];
    }

    public function get(int $id, int $companyId): CustomerDTO
    {
        $customer = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$customer) {
            throw new HttpException(404, 'Customer not found');
        }

        $customer->setAttribute('recent_orders', Sell::where('company_id', $companyId)
            ->where('customer_id', $id)
            ->orderByDesc('order_time')->orderByDesc('id')->limit(5)
            ->get(['id', 'invoice_no', 'order_time', 'amount', 'status'])
            ->map(fn ($o) => [
                'id' => $o->id,
                'invoiceNo' => $o->invoice_no,
                'date' => $o->order_time ? \Carbon\Carbon::parse($o->order_time)->toIso8601String() : null,
                'amount' => (float) $o->amount,
                'status' => $o->status,
            ])->all());

        return $this->mapper->toDTO($customer);
    }

    public function create(int $companyId, array $data): CustomerDTO
    {
        $hasEmail = !empty($data['email']);

        // Email is optional. When provided, enforce per-company uniqueness and
        // create a linked login user; phone-only customers get no linked user.
        $user = null;
        if ($hasEmail) {
            $existing = $this->repository->findByEmailAndCompany($data['email'], $companyId);
            if ($existing) {
                throw new HttpException(409, 'Email already registered for this company');
            }

            $user = User::create([
                'username' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make('changeme'),
            ]);
        }

        // Prepare customer data
        $dbData = $this->mapInputToDb($data);
        $dbData['company_id'] = $companyId;
        $dbData['user_id'] = $user?->id;
        $dbData['country'] = $dbData['country'] ?? 'Bangladesh';

        $customer = $this->repository->create($dbData);

        return $this->mapper->toDTO($customer->fresh('user', 'user.role'));
    }

    public function update(int $id, int $companyId, array $data): CustomerDTO
    {
        $customer = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$customer) {
            throw new HttpException(404, 'Customer not found');
        }

        // Check email uniqueness if email is being updated
        if (isset($data['email']) && $data['email'] !== $customer->email) {
            $existing = $this->repository->findByEmailAndCompany($data['email'], $companyId);
            if ($existing) {
                throw new HttpException(409, 'Email already registered for this company');
            }
        }

        $dbData = $this->mapInputToDb($data);

        // Sync user record if name or email changed
        if ($customer->user_id && (isset($data['name']) || isset($data['email']))) {
            $userUpdate = [];
            if (isset($data['name'])) {
                $userUpdate['username'] = $data['name'];
            }
            if (isset($data['email'])) {
                $userUpdate['email'] = $data['email'];
            }
            $customer->user()->update($userUpdate);
        }

        $this->repository->update($id, $dbData);

        return $this->get($id, $companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $customer = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$customer) {
            throw new HttpException(404, 'Customer not found');
        }

        $sellCount = \App\Models\Sell::where('company_id', $companyId)->where('customer_id', $id)->count();
        if ($sellCount > 0) {
            throw new HttpException(422, "Cannot delete customer '{$customer->name}': they have {$sellCount} sale(s) with payment/due history. Deactivate the customer instead.");
        }
        $returnCount = \App\Models\CustomerReturn::where('customer_id', $id)->count();
        if ($returnCount > 0) {
            throw new HttpException(422, "Cannot delete customer '{$customer->name}': they have {$returnCount} return(s) on record.");
        }

        // Soft delete linked user if exists
        if ($customer->user_id) {
            $customer->user()->delete();
        }

        $this->repository->delete($id);
    }

    public function getStats(int $companyId): array
    {
        return $this->repository->getStats($companyId);
    }

    private function mapInputToDb(array $data): array
    {
        $dbData = [];

        if (isset($data['name'])) {
            $dbData['name'] = $data['name'];
        }
        if (isset($data['email'])) {
            $dbData['email'] = $data['email'];
        }
        if (isset($data['phone'])) {
            $dbData['phone'] = $data['phone'];
        }
        if (isset($data['address'])) {
            $dbData['address'] = $data['address'];
        }
        if (isset($data['city'])) {
            $dbData['city'] = $data['city'];
        }
        if (isset($data['state'])) {
            $dbData['state'] = $data['state'];
        }
        if (isset($data['zipCode'])) {
            $dbData['zip_code'] = $data['zipCode'];
        }
        if (isset($data['country'])) {
            $dbData['country'] = $data['country'];
        }
        if (isset($data['customerType'])) {
            $dbData['customer_type'] = $data['customerType'];
        }
        if (isset($data['status'])) {
            $dbData['status'] = $data['status'];
        }
        if (isset($data['notes'])) {
            $dbData['notes'] = $data['notes'];
        }
        if (isset($data['storeCredit'])) {
            $dbData['store_credit'] = $data['storeCredit'];
        }

        // Phase 3 fields. customerType is a legacy alias of customerGroup; the old
        // enum column only knows retail/wholesale, so vip is stored as retail there.
        $group = $data['customerGroup'] ?? $data['customerType'] ?? null;
        if ($group) {
            $dbData['customer_group'] = $group;
            $dbData['customer_type'] = $group === 'wholesale' ? 'wholesale' : 'retail';
        }
        $map = [
            'type' => 'type', 'businessName' => 'business_name', 'licenceBin' => 'licence_bin',
            'creditLimit' => 'credit_limit', 'openingBalance' => 'opening_balance',
            'birthday' => 'birthday', 'smsOptIn' => 'sms_opt_in', 'district' => 'district', 'area' => 'area',
        ];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $data)) {
                $v = $data[$in];
                if (in_array($in, ['creditLimit', 'openingBalance'], true)) {
                    $v = $v ?? 0;
                } elseif ($in === 'smsOptIn') {
                    $v = $v ?? true;
                } elseif ($in === 'type') {
                    $v = $v ?: 'individual';
                }
                $dbData[$col] = $v;
            }
        }
        if (array_key_exists('tags', $data)) {
            $dbData['tags'] = $this->cleanTags($data['tags']);
        }
        // A person (individual) has no business name / licence.
        if (($dbData['type'] ?? null) === 'individual') {
            $dbData['business_name'] = $dbData['business_name'] ?? null;
        }

        return $dbData;
    }

    private function cleanTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }
        $out = [];
        foreach ((array) $tags as $t) {
            $t = trim((string) $t);
            if ($t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $out), true)) {
                $out[] = mb_substr($t, 0, 50);
            }
        }
        return $out;
    }

    private function digits(?string $phone): string
    {
        // Normalised for comparison: digits only, compared on the last 10 so +880/0 prefixes match.
        $d = preg_replace('/\D/', '', (string) $phone);
        return strlen($d) > 10 ? substr($d, -10) : $d;
    }

    public function setStatus(int $id, int $companyId, string $status): CustomerDTO
    {
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new HttpException(422, 'Status must be active or inactive');
        }
        $this->get($id, $companyId);
        $this->repository->update($id, ['status' => $status]);

        return $this->get($id, $companyId);
    }

    public function bulkTag(int $companyId, array $ids, string $tag): int
    {
        $tag = trim($tag);
        if ($tag === '' || empty($ids)) {
            throw new HttpException(422, 'ids and tag are required');
        }
        $n = 0;
        foreach (Customer::where('company_id', $companyId)->whereIn('id', $ids)->get() as $c) {
            $tags = $this->cleanTags(array_merge($c->tags ?? [], [$tag]));
            $c->forceFill(['tags' => $tags])->save();
            $n++;
        }
        return $n;
    }

    public function smsNumbers(int $companyId, array $filters): array
    {
        $filters['smsOptIn'] = true;
        $query = Customer::where('company_id', $companyId);
        $this->repository->applyFilters($query, $filters);
        $numbers = $query->pluck('phone')->map(fn ($p) => trim((string) $p))->filter()->unique()->values()->all();

        return ['numbers' => $numbers, 'count' => count($numbers)];
    }

    /** Groups of likely-duplicate customers: same phone digits, or same lowercase name + email. */
    public function findDuplicates(int $companyId): array
    {
        $all = Customer::where('company_id', $companyId)->get(['id', 'name', 'email', 'phone']);
        $groups = [];
        $used = [];

        $byPhone = [];
        foreach ($all as $c) {
            $d = $this->digits($c->phone);
            if (strlen($d) >= 7) {
                $byPhone[$d][] = $c->id;
            }
        }
        foreach ($byPhone as $key => $ids) {
            if (count($ids) > 1) {
                $groups[] = ['key' => 'phone:' . $key, 'reason' => 'phone', 'ids' => $ids];
                array_push($used, ...$ids);
            }
        }

        $byNameEmail = [];
        foreach ($all as $c) {
            if (in_array($c->id, $used, true) || !$c->email) {
                continue;
            }
            $byNameEmail[mb_strtolower(trim($c->name)) . '|' . mb_strtolower(trim($c->email))][] = $c->id;
        }
        foreach ($byNameEmail as $key => $ids) {
            if (count($ids) > 1) {
                $groups[] = ['key' => 'name_email:' . $key, 'reason' => 'name_email', 'ids' => $ids];
            }
        }

        foreach ($groups as &$g) {
            $g['customers'] = array_map(fn ($id) => $this->get($id, $companyId)->toArray(), $g['ids']);
            unset($g['ids']);
        }

        return ['groups' => array_slice($groups, 0, 200)];
    }

    /** Move everything owned by the duplicates onto the primary customer, then delete them. */
    public function merge(int $companyId, int $primaryId, array $duplicateIds): array
    {
        $duplicateIds = array_values(array_unique(array_map('intval', $duplicateIds)));
        $duplicateIds = array_values(array_diff($duplicateIds, [$primaryId]));
        if (empty($duplicateIds)) {
            throw new HttpException(422, 'Select at least one duplicate to merge');
        }

        DB::transaction(function () use ($companyId, $primaryId, $duplicateIds) {
            $primary = Customer::where('company_id', $companyId)->lockForUpdate()->find($primaryId);
            $dups = Customer::where('company_id', $companyId)->whereIn('id', $duplicateIds)->lockForUpdate()->get();
            if (!$primary || $dups->count() !== count($duplicateIds)) {
                throw new HttpException(404, 'Customer not found');
            }

            $tags = $primary->tags ?? [];
            $fill = [];
            $openingBalance = (float) $primary->opening_balance;
            $storeCredit = (float) $primary->store_credit;
            foreach ($dups as $d) {
                $tags = array_merge($tags, $d->tags ?? []);
                $openingBalance += (float) $d->opening_balance;
                $storeCredit += (float) $d->store_credit;
                foreach (['email', 'phone', 'address', 'city', 'state', 'zip_code', 'district', 'area', 'business_name', 'licence_bin', 'birthday', 'notes'] as $f) {
                    if (empty($primary->$f) && empty($fill[$f] ?? null) && !empty($d->$f)) {
                        $fill[$f] = $d->$f;
                    }
                }
                if ((float) $d->credit_limit > (float) $primary->credit_limit) {
                    $fill['credit_limit'] = $d->credit_limit;
                }
            }

            // Release unique emails on the duplicates before the primary may take one.
            if (isset($fill['email'])) {
                Customer::whereIn('id', $duplicateIds)->update(['email' => null]);
            }

            // Every table that points at customers.id. (Tailor tables use tailor_customers, not this one.)
            DB::table('sells')->whereIn('customer_id', $duplicateIds)->update(['customer_id' => $primaryId]);
            DB::table('customer_returns')->whereIn('customer_id', $duplicateIds)->update(['customer_id' => $primaryId]);
            DB::table('shipping_addresses')->whereIn('customer_id', $duplicateIds)->update(['customer_id' => $primaryId]);
            DB::table('coupon_usages')->whereIn('customer_id', $duplicateIds)->update(['customer_id' => $primaryId]);
            DB::table('support_tickets')->whereIn('customer_id', $duplicateIds)->update(['customer_id' => $primaryId]);
            DB::table('support_messages')->whereIn('customer_id', $duplicateIds)->update(['customer_id' => $primaryId]);
            DB::table('product_reviews')->whereIn('customer_id', $duplicateIds)->update(['customer_id' => $primaryId]);
            if (\Illuminate\Support\Facades\Schema::hasTable('held_sales')) {
                DB::table('held_sales')->whereIn('customer_id', $duplicateIds)->update(['customer_id' => $primaryId]);
            }
            // wishlists has unique(customer_id, product_id): drop rows the primary already has.
            $have = DB::table('wishlists')->where('customer_id', $primaryId)->pluck('product_id');
            DB::table('wishlists')->whereIn('customer_id', $duplicateIds)->whereIn('product_id', $have)->delete();
            DB::table('wishlists')->whereIn('customer_id', $duplicateIds)->update(['customer_id' => $primaryId]);

            $primary->forceFill(array_merge($fill, [
                'tags' => $this->cleanTags($tags),
                'opening_balance' => $openingBalance,
                'store_credit' => $storeCredit,
            ]))->save();

            // Nothing references the duplicates any more, so the delete guard does not apply.
            foreach ($dups as $d) {
                if ($d->user_id && $d->user_id !== $primary->user_id) {
                    $d->user()->delete();
                }
                $d->delete();
            }
        });

        return ['merged' => count($duplicateIds), 'customer' => $this->get($primaryId, $companyId)->toArray()];
    }

    public function import(int $companyId, array $rows): array
    {
        $existing = Customer::where('company_id', $companyId)->pluck('phone')
            ->map(fn ($p) => $this->digits($p))->filter()->flip()->all();
        $emails = Customer::where('company_id', $companyId)->whereNotNull('email')->pluck('email')
            ->map(fn ($e) => mb_strtolower($e))->flip()->all();

        $imported = 0;
        $skippedRows = [];
        foreach ($rows as $i => $row) {
            $n = $i + 1;
            $name = trim((string) ($row['name'] ?? ''));
            $phone = trim((string) ($row['phone'] ?? ''));
            $d = $this->digits($phone);
            $email = trim((string) ($row['email'] ?? ''));

            $reason = null;
            if ($name === '' || $phone === '') {
                $reason = 'Missing name or phone';
            } elseif (strlen(preg_replace('/\D/', '', $phone)) < 7 || strlen(preg_replace('/\D/', '', $phone)) > 15) {
                $reason = 'Invalid phone';
            } elseif (isset($existing[$d])) {
                $reason = 'Duplicate phone';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $reason = 'Invalid email';
            } elseif ($email !== '' && isset($emails[mb_strtolower($email)])) {
                $reason = 'Duplicate email';
            }
            if ($reason) {
                $skippedRows[] = ['row' => $n, 'reason' => $reason];
                continue;
            }

            $row['name'] = $name;
            $row['phone'] = $phone;
            $row['email'] = $email !== '' ? $email : null;
            foreach (['customerGroup', 'customerType'] as $k) {
                if (isset($row[$k])) {
                    $row[$k] = in_array(strtolower($row[$k]), ['retail', 'wholesale', 'vip'], true) ? strtolower($row[$k]) : 'retail';
                }
            }
            if (isset($row['type'])) {
                $row['type'] = strtolower($row['type']) === 'business' ? 'business' : 'individual';
            }
            if (!empty($row['birthday']) && (strtotime($row['birthday']) === false || strtotime($row['birthday']) > time())) {
                unset($row['birthday']);
            }
            foreach (['creditLimit', 'openingBalance'] as $k) {
                if (isset($row[$k])) {
                    $row[$k] = is_numeric($row[$k]) && $row[$k] >= 0 ? (float) $row[$k] : 0;
                }
            }
            if (isset($row['smsOptIn'])) {
                $row['smsOptIn'] = filter_var($row['smsOptIn'], FILTER_VALIDATE_BOOLEAN);
            }
            if (($row['type'] ?? null) === 'business' && empty($row['businessName'])) {
                $row['businessName'] = $name;
            }

            $db = $this->mapInputToDb($row);
            $db['company_id'] = $companyId;
            $db['country'] = $db['country'] ?? 'Bangladesh';
            $this->repository->create($db);

            $existing[$d] = true;
            if ($email !== '') {
                $emails[mb_strtolower($email)] = true;
            }
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => count($skippedRows), 'skippedRows' => $skippedRows];
    }
}
