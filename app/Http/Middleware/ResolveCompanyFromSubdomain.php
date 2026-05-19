<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCompanyFromSubdomain
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only activate when no company_id already provided (backwards-compatible)
        if ($request->filled('company_id')) {
            return $next($request);
        }

        $host = $request->getHost(); // e.g. "acme.localhost" or "acme.yourstore.com"
        $parts = explode('.', $host);

        // Need at least subdomain.domain — skip plain "localhost" or IP
        if (count($parts) < 2) {
            return $next($request);
        }

        $subdomain = $parts[0];

        // Skip "www" and "api" subdomains
        if (in_array($subdomain, ['www', 'api', 'admin'])) {
            return $next($request);
        }

        $company = Company::where('subdomain', $subdomain)->first();

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => "No store found for subdomain: {$subdomain}",
            ], 404);
        }

        // Inject company_id into request so all controllers keep working unchanged
        $request->query->set('company_id', $company->id);
        $request->merge(['company_id' => $company->id]);

        return $next($request);
    }
}
