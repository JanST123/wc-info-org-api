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

    <!-- Main Overview Card: Monthly Budget & Progress -->
    <div class="card" style="margin-bottom: 1.5rem;">
        <div class="card-header">
            <div class="card-title">
                <span>💳 Current Month Budget ({{ date('F Y') }})</span>
            </div>
            <div style="font-size: 0.8125rem; color: var(--gray-500);">
                Automatic hard stop when 100% reached
            </div>
        </div>
        <div class="card-body">
            <div style="display: flex; align-items: baseline; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 0.75rem;">
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
            <div style="width: 100%; height: 12px; background-color: var(--gray-200); border-radius: 6px; overflow: hidden; margin-bottom: 1.5rem;">
                <div style="width: {{ min(100, $monthlyStats['percentage_used']) }}%; height: 100%; background-color: {{ $monthlyStats['percentage_used'] >= 90 ? 'var(--danger)' : ($monthlyStats['percentage_used'] >= 70 ? 'var(--warning)' : 'var(--primary)') }}; transition: width 0.3s ease;"></div>
            </div>

            <!-- Update Budget Form -->
            <form action="{{ route('admin.costs.budget') }}" method="POST" style="background: var(--gray-50); padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--gray-200); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                @csrf
                <div>
                    <label for="budget" style="font-weight: 600; font-size: 0.875rem; color: var(--gray-800); display: block;">
                        Change Monthly Budget Limit (USD)
                    </label>
                    <div class="form-hint" style="margin-top: 0.125rem;">
                        Once reached, Google API calls are paused until next month or until budget is increased.
                    </div>
                </div>

                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <div style="position: relative;">
                        <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--gray-500); font-weight: 600;">$</span>
                        <input
                            type="number"
                            step="1"
                            min="0"
                            max="10000"
                            id="budget"
                            name="budget"
                            class="form-control"
                            value="{{ number_format($monthlyStats['budget'], 0, '', '') }}"
                            style="width: 140px; padding-left: 24px; height: 38px; font-weight: 600;"
                            required
                        >
                    </div>
                    <button type="submit" class="btn btn-primary" style="height: 38px;">
                        Save Budget
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 4 Services Breakdown Grid -->
    <div class="grid-4" style="margin-bottom: 1.5rem;">
        @foreach ($monthlyStats['services'] as $serviceKey => $svc)
            <div class="card" style="margin-bottom: 0; padding: 1.25rem;">
                <div style="font-size: 0.75rem; color: var(--gray-500); font-weight: 600; text-transform: uppercase;">
                    {{ $svc['name'] }}
                </div>
                <div style="font-size: 1.5rem; font-weight: 700; color: var(--gray-900); margin-top: 0.25rem;">
                    ${{ number_format($svc['cost'], 2) }}
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.75rem; color: var(--gray-500); margin-top: 0.375rem;">
                    <span>{{ number_format($svc['count']) }} requests</span>
                    <span style="font-family: monospace;">${{ $svc['unit_cost'] }}/req</span>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Recent Google API Calls Log Table -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span>📝 Recent Google API Calls (Last {{ $recentLogs->count() }})</span>
            </div>
            <div style="font-size: 0.8125rem; color: var(--gray-500);">
                Total this month: {{ number_format($monthlyStats['total_requests']) }} calls
            </div>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 80px;">ID</th>
                        <th>Service</th>
                        <th>Endpoint / Query</th>
                        <th>Cost</th>
                        <th>Status</th>
                        <th>Context</th>
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
                            <td style="font-family: monospace; font-size: 0.75rem; max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $log->endpoint }}">
                                {{ $log->endpoint }}
                            </td>
                            <td style="font-family: monospace; font-weight: 600; font-size: 0.8125rem; color: var(--gray-900);">
                                ${{ number_format($log->cost_usd, 3) }}
                            </td>
                            <td>
                                @if ($log->status_code >= 200 && $log->status_code < 300)
                                    <span class="badge badge-active">{{ $log->status_code }}</span>
                                @else
                                    <span class="badge badge-deleted">{{ $log->status_code }}</span>
                                @endif
                            </td>
                            <td style="font-size: 0.75rem; color: var(--gray-600); max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
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
