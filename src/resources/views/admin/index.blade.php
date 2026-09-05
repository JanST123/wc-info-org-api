@extends('admin.layout')

@section('title', 'Admin Dashboard')

@section('content')

    <!-- Top Grid: Quick Search & Statistics -->
    <div class="grid-4" style="margin-bottom: 1.5rem;">
        <div class="card" style="margin-bottom: 0; padding: 1.25rem;">
            <div style="font-size: 0.8125rem; color: var(--gray-500); font-weight: 500; text-transform: uppercase;">Total Toilets</div>
            <div style="font-size: 1.75rem; font-weight: 700; color: var(--gray-900); margin-top: 0.25rem;">{{ number_format($stats['total']) }}</div>
            <div style="font-size: 0.75rem; color: var(--gray-500); margin-top: 0.25rem;">
                <span class="badge badge-active">{{ number_format($stats['active']) }} active</span>
                <span class="badge badge-hidden">{{ number_format($stats['hidden']) }} hidden</span>
            </div>
        </div>

        <div class="card" style="margin-bottom: 0; padding: 1.25rem;">
            <div style="font-size: 0.8125rem; color: var(--gray-500); font-weight: 500; text-transform: uppercase;">Qualified Toilets</div>
            <div style="font-size: 1.75rem; font-weight: 700; color: #4338ca; margin-top: 0.25rem;">{{ number_format($stats['qualified']) }}</div>
            <div style="font-size: 0.75rem; color: var(--gray-500); margin-top: 0.25rem;">
                Verified & qualified entries
            </div>
        </div>

        <div class="card" style="margin-bottom: 0; padding: 1.25rem;">
            <div style="font-size: 0.8125rem; color: var(--gray-500); font-weight: 500; text-transform: uppercase;">Added Last 24 Hours</div>
            <div style="font-size: 1.75rem; font-weight: 700; color: var(--success); margin-top: 0.25rem;">{{ number_format($stats['recent_count']) }}</div>
            <div style="font-size: 0.75rem; color: var(--gray-500); margin-top: 0.25rem;">
                Since {{ $since->format('M d, H:i') }}
            </div>
        </div>

        <div class="card" style="margin-bottom: 0; padding: 1.25rem; background: linear-gradient(to bottom right, #ffffff, var(--primary-light));">
            <div style="font-size: 0.8125rem; color: var(--primary); font-weight: 600; text-transform: uppercase;">Quick Open by ID</div>
            <form action="{{ route('admin.toilets.find') }}" method="POST" style="margin-top: 0.5rem; display: flex; gap: 0.5rem;">
                @csrf
                <input
                    type="number"
                    name="id"
                    class="form-control"
                    placeholder="Toilet ID..."
                    required
                    min="1"
                    style="height: 38px;"
                >
                <button type="submit" class="btn btn-primary" style="white-space: nowrap; height: 38px;">Go →</button>
            </form>
        </div>
    </div>

    <!-- Main Card: Toilets Added in the Last 24 Hours -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span>⏱ Toilets Added in the Last 24 Hours</span>
                <span class="badge badge-active" style="font-size: 0.8125rem;">{{ $recentToilets->count() }}</span>
            </div>
            <div style="font-size: 0.8125rem; color: var(--gray-500);">
                Click any toilet to view details and edit
            </div>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 80px;">ID</th>
                        <th>Name & Owner</th>
                        <th>Status</th>
                        <th>Qualified</th>
                        <th>Coordinates</th>
                        <th>Place ID</th>
                        <th>Created / Updated</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentToilets as $toilet)
                        <tr>
                            <td>
                                <a href="{{ route('admin.toilets.show', $toilet->id) }}" style="font-weight: 700; font-family: monospace;">
                                    #{{ $toilet->id }}
                                </a>
                            </td>
                            <td>
                                <a href="{{ route('admin.toilets.show', $toilet->id) }}" style="font-weight: 600; color: var(--gray-900);">
                                    {{ $toilet->name ?: 'Unnamed Toilet' }}
                                </a>
                                @if (!empty($toilet->owner))
                                    <div style="font-size: 0.75rem; color: var(--gray-500);">
                                        {{ $toilet->owner }}
                                    </div>
                                @endif
                            </td>
                            <td>
                                @if ($toilet->status === 'active')
                                    <span class="badge badge-active">Active</span>
                                @elseif ($toilet->status === 'hidden')
                                    <span class="badge badge-hidden">Hidden</span>
                                @else
                                    <span class="badge badge-deleted">Deleted</span>
                                @endif
                            </td>
                            <td>
                                @if ($toilet->is_qualified)
                                    <span class="badge badge-qualified">✓ Qualified</span>
                                @else
                                    <span class="badge badge-unqualified">Unqualified</span>
                                @endif
                            </td>
                            <td style="font-family: monospace; font-size: 0.8125rem;">
                                @if ($toilet->lat && $toilet->lon)
                                    {{ number_format($toilet->lat, 4) }}, {{ number_format($toilet->lon, 4) }}
                                @else
                                    <span style="color: var(--gray-400);">-</span>
                                @endif
                            </td>
                            <td style="font-family: monospace; font-size: 0.75rem;">
                                @if ($toilet->place_id)
                                    <span title="{{ $toilet->place_id }}">{{ \Illuminate\Support\Str::limit($toilet->place_id, 18) }}</span>
                                @else
                                    <span style="color: var(--gray-400);">-</span>
                                @endif
                            </td>
                            <td style="font-size: 0.8125rem; color: var(--gray-600);">
                                @if ($toilet->created_at)
                                    <div>{{ $toilet->created_at->format('Y-m-d H:i') }}</div>
                                    <div style="font-size: 0.6875rem; color: var(--gray-400);">{{ $toilet->created_at->diffForHumans() }}</div>
                                @elseif ($toilet->updated)
                                    <div>{{ $toilet->updated->format('Y-m-d H:i') }}</div>
                                    <div style="font-size: 0.6875rem; color: var(--gray-400);">{{ $toilet->updated->diffForHumans() }}</div>
                                @else
                                    -
                                @endif
                            </td>
                            <td style="text-align: right;">
                                <a href="{{ route('admin.toilets.show', $toilet->id) }}" class="btn btn-secondary btn-sm">
                                    Edit Toilet →
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 3rem 1rem; color: var(--gray-500);">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">🎉</div>
                                <div style="font-weight: 600; font-size: 1rem; color: var(--gray-700);">No toilets added in the last 24 hours</div>
                                <div style="font-size: 0.875rem; margin-top: 0.25rem;">Use the search box above to open any existing toilet by its ID.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@endsection
