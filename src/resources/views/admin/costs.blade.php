@extends('admin.layout')

@section('title', 'Google API Cost Control & Monitoring')

@section('content')

    <!-- Top Header -->
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
        <div>
            <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--gray-900);">
                📊 Google API Cost Control & Monitoring
            </h1>
            <div style="font-size: 0.875rem; color: var(--gray-500); margin-top: 0.25rem;">
                Usage tracking, automatic budget protection & service-level breakdown
            </div>
        </div>

        <div style="display: flex; gap: 0.5rem;">
            <a href="{{ route('admin.index') }}" class="btn btn-secondary btn-sm">← Back to Dashboard</a>
        </div>
    </div>

    @if ($monthlyStats['is_exceeded'])
        <div class="alert alert-error" style="border-left: 4px solid var(--danger);">
            <span style="font-size: 1.25rem;">🚨</span>
            <div>
                <strong>Monthly Budget Limit Exceeded!</strong>
                <p style="margin-top: 0.25rem;">
                    All outgoing Google API calls (Places Nearby Search, Details, Crawls, Geocoding) are currently <strong>BLOCKED</strong> to protect against cost overruns.
                    Increase your budget below if you wish to re-enable API calls.
                </p>
            </div>
        </div>
    @endif

    <!-- Main Overview Grid: Budget & Intelligent Caching Saved Costs -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
        <!-- Monthly Budget & Progress Card -->
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header">
                <div class="card-title">
                    <span>💳 Current Month Budget ({{ date('F Y') }})</span>
                </div>
                <div style="font-size: 0.8125rem; color: var(--gray-500);">
                    Automatic hard stop when 100% reached
                </div>
            </div>
            <div class="card-body">
                <div style="display: flex; align-items: baseline; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem;">
                    <div>
                        <span style="font-size: 2rem; font-weight: 700; color: {{ $monthlyStats['is_exceeded'] ? 'var(--danger)' : 'var(--gray-900)' }};">
                            ${{ number_format($monthlyStats['total_cost'], 2) }}
                        </span>
                        <span style="font-size: 1.125rem; color: var(--gray-500); font-weight: 500;">
                            / ${{ number_format($monthlyStats['budget'], 2) }} USD
                        </span>
                    </div>
                    <div style="font-size: 0.9375rem; font-weight: 600; color: {{ $monthlyStats['percentage_used'] >= 90 ? 'var(--danger)' : ($monthlyStats['percentage_used'] >= 70 ? 'var(--warning)' : 'var(--success)') }};">
                        {{ number_format($monthlyStats['percentage_used'], 1) }}% Used (${{ number_format($monthlyStats['remaining_budget'], 2) }} remaining)
                    </div>
                </div>

                <!-- Progress Bar -->
                <div style="width: 100%; height: 12px; background-color: var(--border-main); border-radius: 6px; overflow: hidden; margin-bottom: 1.25rem;">
                    <div style="width: {{ min(100, $monthlyStats['percentage_used']) }}%; height: 100%; background-color: {{ $monthlyStats['percentage_used'] >= 90 ? 'var(--danger)' : ($monthlyStats['percentage_used'] >= 70 ? 'var(--warning)' : 'var(--primary)') }}; transition: width 0.3s ease;"></div>
                </div>

                <!-- Update Budget Form -->
                <form action="{{ route('admin.costs.budget') }}" method="POST" style="background: var(--bg-subtle); padding: 0.875rem; border-radius: var(--radius-md); border: 1px solid var(--border-main); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
                    @csrf
                    <div>
                        <label for="budget" style="font-weight: 600; font-size: 0.8125rem; color: var(--text-secondary); display: block;">
                            Monthly Budget Limit (USD)
                        </label>
                        <div class="form-hint" style="font-size: 0.75rem; margin-top: 0.125rem;">
                            Hard stop threshold for billed API calls.
                        </div>
                    </div>

                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <div style="position: relative;">
                            <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-weight: 600;">$</span>
                            <input
                                type="number"
                                step="1"
                                min="0"
                                max="10000"
                                id="budget"
                                name="budget"
                                class="form-control"
                                value="{{ number_format($monthlyStats['budget'], 0, '', '') }}"
                                style="width: 120px; padding-left: 24px; height: 36px; font-weight: 600; font-size: 0.875rem;"
                                required
                            >
                        </div>
                        <button type="submit" class="btn btn-primary" style="height: 36px; font-size: 0.8125rem;">
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Intelligent Spatial Caching & Saved Costs Card -->
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header">
                <div class="card-title">
                    <span>⚡ Intelligent Caching & Saved Costs</span>
                </div>
                <span class="badge" style="background: #dcfce7; color: #166534; font-weight: 700; font-size: 0.75rem;">
                    30-Day Spatial Cache Active
                </span>
            </div>
            <div class="card-body" style="display: flex; flex-direction: column; justify-content: space-between;">
                <div style="display: flex; align-items: baseline; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem;">
                    <div>
                        <span style="font-size: 2rem; font-weight: 700; color: #16a34a;">
                            +${{ number_format($monthlyStats['saved_cost'], 2) }}
                        </span>
                        <span style="font-size: 1.125rem; color: var(--gray-500); font-weight: 500;">
                            USD Saved
                        </span>
                    </div>
                    <div style="font-size: 0.9375rem; font-weight: 600; color: #16a34a;">
                        {{ number_format($monthlyStats['cache_hit_rate'], 1) }}% Cache Hit Rate
                    </div>
                </div>

                <!-- Cache Ratio Bar -->
                <div style="width: 100%; height: 12px; background-color: #fee2e2; border-radius: 6px; overflow: hidden; margin-bottom: 1.25rem;">
                    <div style="width: {{ min(100, $monthlyStats['cache_hit_rate']) }}%; height: 100%; background-color: #22c55e; transition: width 0.3s ease;"></div>
                </div>

                <!-- Cache Stats Breakdown -->
                <div style="background: var(--bg-subtle); padding: 0.875rem; border-radius: var(--radius-md); border: 1px solid var(--border-main); display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; text-align: center;">
                    <div>
                        <div style="font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">Cache Hits</div>
                        <div style="font-size: 1.125rem; font-weight: 700; color: #16a34a; margin-top: 0.125rem;">
                            {{ number_format($monthlyStats['cache_hits']) }}
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">Billed API Calls</div>
                        <div style="font-size: 1.125rem; font-weight: 700; color: #dc2626; margin-top: 0.125rem;">
                            {{ number_format($monthlyStats['api_calls']) }}
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">Total Queries</div>
                        <div style="font-size: 1.125rem; font-weight: 700; color: var(--text-heading); margin-top: 0.125rem;">
                            {{ number_format($monthlyStats['total_requests']) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 4 Services Breakdown Grid -->
    <div class="grid-4" style="margin-bottom: 1.5rem;">
        @foreach ($monthlyStats['services'] as $serviceKey => $svc)
            <div class="card" style="margin-bottom: 0; padding: 1.25rem;">
                <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">
                    {{ $svc['name'] }}
                </div>
                <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-heading); margin-top: 0.25rem;">
                    ${{ number_format($svc['cost'], 2) }}
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.75rem; color: var(--text-muted); margin-top: 0.5rem; border-top: 1px dashed var(--border-main); padding-top: 0.4rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Billed Calls:</span>
                        <strong style="color: {{ $svc['api_calls'] > 0 ? '#dc2626' : 'var(--text-body)' }};">{{ number_format($svc['api_calls']) }}</strong>
                    </div>
                    @if ($svc['cache_hits'] > 0)
                        <div style="display: flex; justify-content: space-between; align-items: center; color: #16a34a;">
                            <span>Cache Hits:</span>
                            <strong>{{ number_format($svc['cache_hits']) }} (+${{ number_format($svc['saved_cost'], 2) }})</strong>
                        </div>
                    @endif
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.6875rem; margin-top: 0.1rem;">
                        <span>Unit Rate:</span>
                        <span style="font-family: monospace;">${{ $svc['unit_cost'] }}/req</span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Recent Google API Calls Log Table -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span>📝 Recent Google API Queries (Last {{ $recentLogs->count() }})</span>
            </div>
            <div style="font-size: 0.8125rem; color: var(--gray-500);">
                Total this month: {{ number_format($monthlyStats['total_requests']) }} queries ({{ number_format($monthlyStats['api_calls']) }} billed · {{ number_format($monthlyStats['cache_hits']) }} cached)
            </div>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 80px;">ID</th>
                        <th>Service</th>
                        <th>Type / Cost</th>
                        <th>Endpoint / Query</th>
                        <th>Status</th>
                        <th>Context / Details</th>
                        <th style="text-align: right;">Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentLogs as $log)
                        <tr>
                            <td style="font-family: monospace; font-size: 0.8125rem; color: var(--gray-500);">
                                #{{ $log->id }}
                            </td>
                            <td>
                                @if ($log->service === 'places_nearby')
                                    <span class="badge" style="background: #e0f2fe; color: #0369a1;">Places Nearby</span>
                                @elseif ($log->service === 'places_details')
                                    <span class="badge" style="background: #e0e7ff; color: #4338ca;">Place Details</span>
                                @elseif ($log->service === 'custom_search')
                                    <span class="badge" style="background: #fef3c7; color: #b45309;">Website Crawl</span>
                                @else
                                    <span class="badge" style="background: #f1f5f9; color: #475569;">Geocoding</span>
                                @endif
                            </td>
                            <td>
                                @if ($log->is_cache_hit)
                                    <span class="badge" style="background: #dcfce7; color: #166534; font-weight: 700; border: 1px solid #86efac;">
                                        ⚡ Cache Hit ($0.00)
                                    </span>
                                @else
                                    <span class="badge" style="background: #fee2e2; color: #991b1b; font-weight: 600; border: 1px solid #fca5a5;">
                                        🌐 API Call (${{ number_format($log->cost_usd, 3) }})
                                    </span>
                                @endif
                            </td>
                            <td style="font-family: monospace; font-size: 0.75rem; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $log->endpoint }}">
                                {{ $log->endpoint }}
                            </td>
                            <td>
                                @if ($log->status_code >= 200 && $log->status_code < 300)
                                    <span class="badge badge-active">{{ $log->status_code }}</span>
                                @else
                                    <span class="badge badge-deleted">{{ $log->status_code }}</span>
                                @endif
                            </td>
                            <td style="font-size: 0.75rem; color: var(--gray-600); max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                @if (!empty($log->context))
                                    <code>{{ json_encode($log->context) }}</code>
                                @else
                                    <span style="color: var(--gray-400);">-</span>
                                @endif
                            </td>
                            <td style="text-align: right; font-size: 0.8125rem; color: var(--gray-500); white-space: nowrap;">
                                {{ $log->created_at ? $log->created_at->format('Y-m-d H:i:s') : '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 2.5rem; color: var(--gray-500);">
                                No Google API calls recorded yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@endsection
