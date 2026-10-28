<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Query\Builder;

/**
 * Read-only money ledger computed as a UNION over the existing money tables plus
 * finance_entries / finance_transfers. Columns of every row:
 *   uid, source_type, source_id, txn_date, created_at, direction, amount, method, mkey,
 *   account_id, party, party_id, reference, category, note, link_type, link_id, link_ref
 */
interface IFinanceLedgerRepository
{
    /** Builder over the ledger (aliased "l"); callers add where/order/aggregate. */
    public function query(int $companyId): Builder;
}
