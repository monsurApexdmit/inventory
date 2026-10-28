<?php

namespace App\Repositories\Eloquent;

use App\Models\PurchaseOrder;
use App\Models\Sell;
use App\Repositories\Contracts\IFinanceLedgerRepository;
use App\Services\Finance\FinanceContext;
use App\Services\Finance\FinanceMethods;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Money ledger = one row per real money movement, each counted once:
 *  - sale payments / refunds / COD collections: due_payments on sells (+ sells marked paid without any payment row)
 *  - supplier payments: due_payments on purchase orders + vendor_payments (opening balances)
 *  - expenses: the expenses table, which already mirrors paid salaries (source=salary) and dorji payouts
 *    (source=dorji_payout); salary_payments / tailor dorji payouts are only read when they have NO live mirror
 *    expense (old data), so a payout is never counted twice
 *  - tailor customer payments, cash refunds of customer returns not reversed through due_payments,
 *    supplier returns settled as cash refunds
 *  - finance_entries (manual; kind supplier_receipt shows as a supplier refund) and finance_transfers (two lines)
 *  - voided expenses add a reversing line (expense_void) on the void date
 * Purchase-order "payments" with method "Credit note" (applied supplier credits) are not money and are left out.
 * Every line carries the sale channel (pos|online|manual) and sell id when it belongs to a sale (else NULL).
 * Dates of timestamp-based lines are local dates in the company time zone.
 */
class FinanceLedgerRepository implements IFinanceLedgerRepository
{
    private const COLS = 'uid, source_type, source_id, txn_date, created_at, direction, amount, method, mkey, account_id, party, party_id, reference, category, note, link_type, link_id, link_ref, channel, sell_id';

    public function query(int $companyId): Builder
    {
        $parts = [
            $this->sellPayments($companyId),
            $this->sellsWithoutPayments($companyId),
            $this->purchasePayments($companyId),
            $this->vendorOpeningPayments($companyId),
            $this->expenses($companyId),
            $this->expenseVoids($companyId),
            $this->legacySalaries($companyId),
            $this->tailorCustomerPayments($companyId),
            $this->legacyDorjiPayouts($companyId),
            $this->customerReturnCashRefunds($companyId),
            $this->vendorReturnRefunds($companyId),
            $this->manualEntries($companyId),
            $this->transfers($companyId, 'out'),
            $this->transfers($companyId, 'in'),
        ];
        $union = array_shift($parts);
        foreach ($parts as $p) {
            $union->unionAll($p);
        }

        return DB::query()->fromSub($union, 'l');
    }

    private function sellPayments(int $cid): Builder
    {
        $mkey = FinanceMethods::sqlKey('dp.payment_method');

        return DB::table('due_payments as dp')
            ->join('sells as s', 's.id', '=', 'dp.payable_id')
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->where('dp.company_id', $cid)
            ->where('dp.payable_type', Sell::class)
            ->selectRaw("CONCAT('sell_payment:', dp.id) AS uid,
                CASE WHEN dp.direction = 'out' THEN 'refund'
                     WHEN LOWER(TRIM(dp.payment_method)) IN ('cod', 'cash on delivery', 'cash_on_delivery')
                       OR (dp.reference IS NOT NULL AND EXISTS (SELECT 1 FROM order_shipments os WHERE os.sell_id = dp.payable_id AND os.tracking_number = dp.reference))
                     THEN 'cod' ELSE 'sale' END AS source_type,
                dp.id AS source_id, dp.payment_date AS txn_date, dp.created_at AS created_at, dp.direction AS direction,
                dp.amount AS amount, dp.payment_method AS method, {$mkey} AS mkey, NULL AS account_id,
                COALESCE(c.name, s.customer_name) AS party, s.customer_id AS party_id, s.invoice_no AS reference,
                NULL AS category, dp.notes AS note, 'sale' AS link_type, s.id AS link_id, s.invoice_no AS link_ref,
                {$this->ch('s')} AS channel, s.id AS sell_id");
    }

    private function ch(string $alias): string
    {
        return FinanceContext::channelSql($alias);
    }

    /** Sales marked paid (old data / online gateway) that have no payment rows at all. */
    private function sellsWithoutPayments(int $cid): Builder
    {
        $mkey = FinanceMethods::sqlKey('s.method');
        $day = FinanceContext::localDateSql($cid, 's.order_time');

        return DB::table('sells as s')
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->where('s.company_id', $cid)
            ->whereNull('s.deleted_at')
            ->where('s.status', '!=', 'Cancelled')
            ->where(fn ($q) => $q->where('s.paid_amount', '>', 0)->orWhere('s.payment_status', 'paid'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('due_payments as x')
                ->whereColumn('x.payable_id', 's.id')->where('x.payable_type', Sell::class))
            ->selectRaw("CONCAT('sell_unrecorded:', s.id) AS uid, 'sale' AS source_type, s.id AS source_id,
                {$day} AS txn_date, s.created_at AS created_at, 'in' AS direction,
                CASE WHEN s.paid_amount > 0 THEN s.paid_amount ELSE ROUND(s.amount, 2) END AS amount,
                s.method AS method, {$mkey} AS mkey, NULL AS account_id,
                COALESCE(c.name, s.customer_name) AS party, s.customer_id AS party_id, s.invoice_no AS reference,
                NULL AS category, 'Paid at sale (no payment record)' AS note, 'sale' AS link_type, s.id AS link_id, s.invoice_no AS link_ref,
                {$this->ch('s')} AS channel, s.id AS sell_id");
    }

    private function purchasePayments(int $cid): Builder
    {
        $mkey = FinanceMethods::sqlKey('dp.payment_method');

        return DB::table('due_payments as dp')
            ->join('purchase_orders as po', 'po.id', '=', 'dp.payable_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'po.vendor_id')
            ->where('dp.company_id', $cid)
            ->where('dp.payable_type', PurchaseOrder::class)
            ->whereRaw("LOWER(TRIM(COALESCE(dp.payment_method, ''))) <> 'credit note'")
            ->selectRaw("CONCAT('po_payment:', dp.id) AS uid,
                CASE WHEN dp.direction = 'out' THEN 'supplier_payment' ELSE 'supplier_refund' END AS source_type,
                dp.id AS source_id, dp.payment_date AS txn_date, dp.created_at AS created_at, dp.direction AS direction,
                dp.amount AS amount, dp.payment_method AS method, {$mkey} AS mkey, NULL AS account_id,
                v.name AS party, po.vendor_id AS party_id, po.po_number AS reference, NULL AS category, dp.notes AS note,
                'purchase_order' AS link_type, po.id AS link_id, CAST(po.vendor_id AS CHAR) AS link_ref,
                NULL AS channel, NULL AS sell_id");
    }

    private function vendorOpeningPayments(int $cid): Builder
    {
        $mkey = FinanceMethods::sqlKey('vp.payment_method');

        return DB::table('vendor_payments as vp')
            ->leftJoin('vendors as v', 'v.id', '=', 'vp.vendor_id')
            ->where('vp.company_id', $cid)
            ->selectRaw("CONCAT('vendor_payment:', vp.id) AS uid, 'supplier_payment' AS source_type, vp.id AS source_id,
                vp.payment_date AS txn_date, vp.created_at AS created_at, 'out' AS direction, vp.amount AS amount,
                vp.payment_method AS method, {$mkey} AS mkey, NULL AS account_id, v.name AS party, vp.vendor_id AS party_id,
                'Opening balance' AS reference, NULL AS category, vp.notes AS note,
                'vendor' AS link_type, vp.vendor_id AS link_id, CAST(vp.vendor_id AS CHAR) AS link_ref,
                NULL AS channel, NULL AS sell_id");
    }

    /** All live expenses, including the salary and dorji-payout mirrors (each payout counted here once). */
    private function expenses(int $cid): Builder
    {
        $mkey = FinanceMethods::sqlKey('e.payment_method');

        return DB::table('expenses as e')
            ->leftJoin('expense_categories as ec', 'ec.id', '=', 'e.expense_category_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'e.vendor_id')
            ->leftJoin('salary_payments as sp', 'sp.id', '=', 'e.salary_payment_id')
            ->where('e.company_id', $cid)
            ->whereNull('e.deleted_at')
            ->selectRaw("CONCAT('expense:', e.id) AS uid,
                CASE e.source WHEN 'salary' THEN 'salary' WHEN 'dorji_payout' THEN 'dorji_payout' ELSE 'expense' END AS source_type,
                e.id AS source_id, e.expense_date AS txn_date, e.created_at AS created_at, 'out' AS direction, e.amount AS amount,
                e.payment_method AS method, {$mkey} AS mkey, NULL AS account_id,
                COALESCE(NULLIF(e.paid_to, ''), v.name) AS party,
                CASE WHEN e.source = 'salary' THEN sp.staff_id ELSE e.vendor_id END AS party_id,
                CASE WHEN e.source = 'salary' THEN COALESCE(NULLIF(e.notes, ''), e.title)
                     ELSE COALESCE(NULLIF(e.reference_no, ''), CONCAT('EXP-', e.id)) END AS reference,
                ec.name AS category, e.title AS note,
                CASE e.source WHEN 'salary' THEN 'salary' WHEN 'dorji_payout' THEN 'tailor_payments' ELSE 'expense' END AS link_type,
                CASE WHEN e.source = 'salary' THEN sp.staff_id ELSE e.id END AS link_id, NULL AS link_ref,
                NULL AS channel, NULL AS sell_id");
    }

    /** Reversing line for a voided expense, dated the void date (the original line stays on its own date). */
    private function expenseVoids(int $cid): Builder
    {
        $mkey = FinanceMethods::sqlKey('e.payment_method');

        return DB::table('expenses as e')
            ->leftJoin('expense_categories as ec', 'ec.id', '=', 'e.expense_category_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'e.vendor_id')
            ->where('e.company_id', $cid)
            ->whereNull('e.deleted_at')
            ->whereNotNull('e.voided_at')
            ->selectRaw("CONCAT('expense_void:', e.id) AS uid, 'expense_void' AS source_type, e.id AS source_id,
                e.voided_at AS txn_date, e.updated_at AS created_at, 'in' AS direction, e.amount AS amount,
                e.payment_method AS method, {$mkey} AS mkey, NULL AS account_id,
                COALESCE(NULLIF(e.paid_to, ''), v.name) AS party, e.vendor_id AS party_id,
                COALESCE(NULLIF(e.reference_no, ''), CONCAT('EXP-', e.id)) AS reference,
                ec.name AS category, CONCAT('Void: ', e.title, COALESCE(CONCAT(' (', NULLIF(e.void_reason, ''), ')'), '')) AS note,
                'expense' AS link_type, e.id AS link_id, NULL AS link_ref,
                NULL AS channel, NULL AS sell_id");
    }

    /** Paid salaries that have no live Salaries expense (recorded before the expense mirror existed). */
    private function legacySalaries(int $cid): Builder
    {
        $method = "COALESCE(NULLIF(sp.payment_method, ''), NULLIF(st.payment_method, ''), 'Cash')";
        $mkey = FinanceMethods::sqlKey($method);

        return DB::table('salary_payments as sp')
            ->join('staff as st', 'st.id', '=', 'sp.staff_id')
            ->where('st.company_id', $cid)
            ->whereNull('sp.deleted_at')
            ->whereRaw("LOWER(sp.status) = 'paid'")
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('expenses as x')
                ->whereColumn('x.salary_payment_id', 'sp.id')->where('x.source', 'salary')->whereNull('x.deleted_at'))
            ->selectRaw("CONCAT('salary_payment:', sp.id) AS uid, 'salary' AS source_type, sp.id AS source_id,
                DATE(COALESCE(sp.payment_date, sp.updated_at, sp.created_at)) AS txn_date, sp.created_at AS created_at,
                'out' AS direction, COALESCE(NULLIF(sp.paid_amount, 0), sp.amount) AS amount,
                {$method} AS method, {$mkey} AS mkey, NULL AS account_id, st.name AS party, st.id AS party_id,
                CONCAT('Salary ', sp.month) AS reference, 'Salaries' AS category, sp.remarks AS note,
                'salary' AS link_type, st.id AS link_id, NULL AS link_ref,
                NULL AS channel, NULL AS sell_id");
    }

    private function tailorCustomerPayments(int $cid): Builder
    {
        $mkey = FinanceMethods::sqlKey('tp.payment_method');

        return DB::table('tailor_payments as tp')
            ->leftJoin('tailor_orders as o', 'o.id', '=', 'tp.order_id')
            ->leftJoin('tailor_customers as tc', 'tc.id', '=', 'o.customer_id')
            ->where('tp.company_id', $cid)
            ->where('tp.kind', '!=', 'dorji')
            ->selectRaw("CONCAT('tailor_payment:', tp.id) AS uid, 'tailor_payment' AS source_type, tp.id AS source_id,
                tp.payment_date AS txn_date, tp.created_at AS created_at, 'in' AS direction, tp.amount AS amount,
                tp.payment_method AS method, {$mkey} AS mkey, NULL AS account_id, tc.name AS party, tc.id AS party_id,
                o.order_number AS reference, NULL AS category, tp.notes AS note,
                'tailor_order' AS link_type, o.id AS link_id, NULL AS link_ref,
                NULL AS channel, NULL AS sell_id");
    }

    /** Dorji payouts without their live dorji_payout expense (TP-{id}); normally none. */
    private function legacyDorjiPayouts(int $cid): Builder
    {
        $mkey = FinanceMethods::sqlKey('tp.payment_method');

        return DB::table('tailor_payments as tp')
            ->leftJoin('tailor_dorjis as d', 'd.id', '=', 'tp.dorji_id')
            ->where('tp.company_id', $cid)
            ->where('tp.kind', 'dorji')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('expenses as x')
                ->whereColumn('x.company_id', 'tp.company_id')->where('x.source', 'dorji_payout')
                ->whereRaw("x.reference_no = CONCAT('TP-', tp.id)")->whereNull('x.deleted_at'))
            ->selectRaw("CONCAT('dorji_payout:', tp.id) AS uid, 'dorji_payout' AS source_type, tp.id AS source_id,
                tp.payment_date AS txn_date, tp.created_at AS created_at, 'out' AS direction, tp.amount AS amount,
                tp.payment_method AS method, {$mkey} AS mkey, NULL AS account_id, d.name AS party, tp.dorji_id AS party_id,
                CONCAT('TP-', tp.id) AS reference, 'Salaries' AS category, tp.notes AS note,
                'tailor_payments' AS link_type, NULL AS link_id, NULL AS link_ref,
                NULL AS channel, NULL AS sell_id");
    }

    /** Completed returns refunded in cash that did not reverse a sale payment (those are already due_payments "out"). */
    private function customerReturnCashRefunds(int $cid): Builder
    {
        $day = FinanceContext::localDateSql($cid, 'COALESCE(cr.processed_date, cr.updated_at)');

        return DB::table('customer_returns as cr')
            ->leftJoin('sells as s', 's.id', '=', 'cr.order_id')
            ->where('cr.company_id', $cid)
            ->whereNull('cr.deleted_at')
            ->where('cr.status', 'completed')
            ->where('cr.refund_amount', '>', 0)
            ->where(fn ($q) => $q->whereNull('cr.refunded_via')->orWhere('cr.refunded_via', ''))
            ->selectRaw("CONCAT('customer_return:', cr.id) AS uid, 'refund' AS source_type, cr.id AS source_id,
                {$day} AS txn_date, cr.created_at AS created_at, 'out' AS direction,
                cr.refund_amount AS amount, 'Cash' AS method, 'cash' AS mkey, NULL AS account_id,
                cr.customer_name AS party, cr.customer_id AS party_id, cr.return_number AS reference, NULL AS category,
                CONCAT('Cash refund for return ', cr.return_number) AS note,
                'customer_return' AS link_type, cr.id AS link_id, cr.order_number AS link_ref,
                CASE WHEN s.id IS NULL THEN NULL ELSE {$this->ch('s')} END AS channel, s.id AS sell_id");
    }

    /** Supplier returns settled as a cash refund (money comes back in). */
    private function vendorReturnRefunds(int $cid): Builder
    {
        $day = FinanceContext::localDateSql($cid, 'COALESCE(vr.credited_at, vr.completed_date, vr.updated_at)');

        return DB::table('vendor_returns as vr')
            ->where('vr.company_id', $cid)
            ->whereNull('vr.deleted_at')
            ->where('vr.status', 'credited')
            ->where('vr.credit_type', 'refund')
            ->where('vr.total_amount', '>', 0)
            ->selectRaw("CONCAT('vendor_return:', vr.id) AS uid, 'supplier_refund' AS source_type, vr.id AS source_id,
                {$day} AS txn_date, vr.created_at AS created_at,
                'in' AS direction, ROUND(vr.total_amount, 2) AS amount, 'Cash' AS method, 'cash' AS mkey, NULL AS account_id,
                vr.vendor_name AS party, vr.vendor_id AS party_id, vr.return_number AS reference, NULL AS category,
                CONCAT('Refund for supplier return ', vr.return_number) AS note,
                'vendor_return' AS link_type, vr.id AS link_id, NULL AS link_ref,
                NULL AS channel, NULL AS sell_id");
    }

    private function manualEntries(int $cid): Builder
    {
        return DB::table('finance_entries as fe')
            ->where('fe.company_id', $cid)
            ->selectRaw("CONCAT('entry:', fe.id) AS uid,
                CASE WHEN fe.kind = 'supplier_receipt' THEN 'supplier_refund' ELSE 'manual' END AS source_type, fe.id AS source_id,
                fe.date AS txn_date, fe.created_at AS created_at, fe.direction AS direction, fe.amount AS amount,
                NULL AS method, NULL AS mkey, fe.account_id AS account_id, fe.party AS party, fe.vendor_id AS party_id,
                fe.reference AS reference, fe.category AS category, fe.note AS note,
                CASE WHEN fe.kind = 'supplier_receipt' THEN 'vendor' ELSE 'entry' END AS link_type,
                CASE WHEN fe.kind = 'supplier_receipt' THEN fe.vendor_id ELSE fe.id END AS link_id, fe.kind AS link_ref,
                NULL AS channel, NULL AS sell_id");
    }

    private function transfers(int $cid, string $side): Builder
    {
        $own = $side === 'out' ? 'from_account_id' : 'to_account_id';
        $other = $side === 'out' ? 'to_account_id' : 'from_account_id';
        $prefix = $side === 'out' ? 'To ' : 'From ';

        return DB::table('finance_transfers as ft')
            ->leftJoin('finance_accounts as oa', 'oa.id', '=', "ft.{$other}")
            ->where('ft.company_id', $cid)
            ->selectRaw("CONCAT('transfer:', ft.id, ':{$side}') AS uid, 'transfer' AS source_type, ft.id AS source_id,
                ft.date AS txn_date, ft.created_at AS created_at, '{$side}' AS direction, ft.amount AS amount,
                NULL AS method, NULL AS mkey, ft.{$own} AS account_id, CONCAT('{$prefix}', COALESCE(oa.name, 'account')) AS party,
                ft.{$other} AS party_id, COALESCE(NULLIF(ft.reference, ''), CONCAT('TR-', ft.id)) AS reference, NULL AS category,
                ft.note AS note, 'transfer' AS link_type, ft.id AS link_id, NULL AS link_ref,
                NULL AS channel, NULL AS sell_id");
    }
}
