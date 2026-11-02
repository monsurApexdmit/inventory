<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceHealthService;
use Illuminate\Console\Command;

/** Finance reconciliation checks R2–R11 for one company (exit code 1 when a check fails). */
class FinanceReconcile extends Command
{
    protected $signature = 'finance:reconcile {company : Company id} {--period=this_month : Period preset} {--from= : From date (YYYY-MM-DD)} {--to= : To date (YYYY-MM-DD)} {--channel=all : all|pos|online|manual} {--json : Print JSON}';

    protected $description = 'Run the finance reconciliation checks (R2–R11) for a company';

    public function handle(FinanceHealthService $health): int
    {
        $in = ['preset' => $this->option('period'), 'channel' => $this->option('channel')];
        if ($this->option('from') || $this->option('to')) {
            $in = ['from' => $this->option('from'), 'to' => $this->option('to'), 'channel' => $this->option('channel')];
        }
        $res = $health->run((int) $this->argument('company'), $in);
        if ($this->option('json')) {
            $this->line(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $p = $res['context']['period'];
            $this->info("Company {$this->argument('company')} · {$p['label']} · channel {$res['context']['channel']} · {$res['context']['tz']} · {$res['context']['currency']['code']}");
            $this->table(['Check', 'Status', 'Explanation'], array_map(fn ($c) => [$c['id'] . ' ' . $c['title'], strtoupper($c['status']), $c['explanation']], $res['checks']));
            $this->line("Passed {$res['passed']} · info {$res['info']} · failed {$res['failed']}");
        }

        return $res['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
