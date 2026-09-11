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
            <div style="font-size: 0.8125rem; color: var(--gray-500); font-weight: 500; text-transform: uppercase;">Flagged for Review</div>
            <div style="font-size: 1.75rem; font-weight: 700; color: #d97706; margin-top: 0.25rem;">{{ number_format($stats['flagged_count']) }}</div>
            <div style="font-size: 0.75rem; color: var(--gray-500); margin-top: 0.25rem;">
                <span class="badge" style="background: #fef3c7; color: #92400e;">🚩 Needs verification</span>
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

    <!-- Google Cost Summary Banner -->
    <div class="card" style="padding: 1rem 1.5rem; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; background-color: {{ $stats['is_budget_exceeded'] ? 'var(--danger-light)' : '#ffffff' }}; border-color: {{ $stats['is_budget_exceeded'] ? '#fca5a5' : 'var(--gray-200)' }};">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="font-size: 1.5rem;">📊</div>
            <div>
                <div style="font-weight: 600; font-size: 0.9375rem; color: {{ $stats['is_budget_exceeded'] ? 'var(--danger)' : 'var(--gray-900)' }};">
                    Google Cloud API Budget ({{ date('F Y') }}):
                    <span style="font-family: monospace;">${{ number_format($stats['current_month_cost'], 2) }}</span> /
                    <span style="font-family: monospace;">${{ number_format($stats['monthly_budget'], 2) }} USD</span>
                    @if ($stats['is_budget_exceeded'])
                        <span class="badge badge-deleted" style="margin-left: 0.5rem;">⚠️ Limit Exceeded</span>
                    @else
                        <span class="badge badge-active" style="margin-left: 0.5rem;">✓ Active</span>
                    @endif
                </div>
                <div style="font-size: 0.8125rem; color: var(--gray-500); margin-top: 0.125rem;">
                    Calls to Places Nearby, Place Details, and Custom Search are tracked and throttled to prevent runaway costs.
                </div>
            </div>
        </div>
        <div>
            <a href="{{ route('admin.costs') }}" class="btn btn-secondary btn-sm" style="font-weight: 600;">
                Manage Budget & Logs →
            </a>
        </div>
    </div>

    <!-- Section 1: Flagged Toilets for Review -->
    <div class="card" style="margin-bottom: 1.5rem;">
        <div class="card-header" style="background: #fffdf5; border-bottom: 1px solid #fef3c7;">
            <div class="card-title" style="color: #92400e;">
                <span>🚩 Flagged Toilets for Review</span>
                <span id="flagged-count-badge" class="badge" style="background: #fef3c7; color: #92400e; font-size: 0.8125rem;">{{ $flaggedToilets->total() }}</span>
            </div>
            <div style="font-size: 0.8125rem; color: #b45309;">
                Fast Place correction workflow • Places API queries only on dropdown open
            </div>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 70px;">ID</th>
                        <th style="width: 180px;">Name & Owner</th>
                        <th style="width: 180px;">Comment</th>
                        <th style="width: 180px;">Address</th>
                        <th style="width: 180px;">Current Place</th>
                        <th style="min-width: 260px;">Assign Google Place (~40m)</th>
                        <th style="width: 140px; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody id="flagged-toilets-tbody">
                    @forelse ($flaggedToilets as $toilet)
                        <tr id="flagged-row-{{ $toilet->id }}">
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
                            <td style="font-size: 0.8125rem; color: var(--gray-700); max-width: 200px; word-break: break-word;">
                                {{ $toilet->propertyValue('comment') ?: '-' }}
                            </td>
                            <td style="font-size: 0.8125rem; color: var(--gray-700); max-width: 200px; word-break: break-word;">
                                {{ $toilet->propertyValue('address') ?: '-' }}
                            </td>
                            <td style="font-size: 0.8125rem;">
                                <div id="current-place-name-{{ $toilet->id }}" style="font-weight: 600; color: var(--gray-900);">
                                    {{ $toilet->place?->getName() ?? ($toilet->place_id ?: '-') }}
                                </div>
                                @if (!empty($toilet->place_id))
                                    <div style="font-family: monospace; font-size: 0.6875rem; color: var(--gray-400);">
                                        {{ \Illuminate\Support\Str::limit($toilet->place_id, 16) }}
                                    </div>
                                @endif
                            </td>
                            <td>
                                <select
                                    class="form-control places-dropdown"
                                    id="places-dropdown-{{ $toilet->id }}"
                                    data-toilet-id="{{ $toilet->id }}"
                                    data-loaded="false"
                                    onfocus="loadNearbyPlaces(this)"
                                    onchange="assignPlace(this)"
                                    style="font-size: 0.8125rem; height: 34px; padding: 0.25rem 0.5rem;"
                                >
                                    <option value="{{ $toilet->place_id ?? '' }}">
                                        {{ $toilet->place?->getName() ? 'Current: ' . $toilet->place->getName() : ($toilet->place_id ? 'Current ID: ' . $toilet->place_id : '-- Click to load places (~40m) --') }}
                                    </option>
                                </select>
                                <div id="status-msg-{{ $toilet->id }}" style="font-size: 0.6875rem; color: var(--gray-500); margin-top: 0.125rem; display: none;"></div>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: flex; gap: 0.35rem; align-items: center; justify-content: flex-end;">
                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-sm"
                                        id="unflag-btn-{{ $toilet->id }}"
                                        onclick="unflagToilet(this, {{ $toilet->id }})"
                                        title="Remove flag and take out of review list"
                                        style="white-space: nowrap;"
                                    >
                                        ✓ Unflag
                                    </button>
                                    <a href="{{ route('admin.toilets.show', $toilet->id) }}" class="btn btn-secondary btn-sm" title="Edit details">
                                        Edit
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr id="flagged-empty-row">
                            <td colspan="7" style="text-align: center; padding: 2.5rem 1rem; color: var(--gray-500);">
                                <div style="font-size: 1.75rem; margin-bottom: 0.25rem;">🎉</div>
                                <div style="font-weight: 600; font-size: 0.9375rem; color: var(--gray-700);">No toilets currently flagged for review</div>
                                <div style="font-size: 0.8125rem; color: var(--gray-400); margin-top: 0.25rem;">Flag toilets in the edit view or when importing data to review and correct Google Places here.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($flaggedToilets->hasPages())
            <div class="pagination-container">
                <div>
                    Showing <strong>{{ $flaggedToilets->firstItem() }}</strong> to <strong>{{ $flaggedToilets->lastItem() }}</strong> of <strong>{{ $flaggedToilets->total() }}</strong> flagged toilets
                </div>
                <div>
                    {{ $flaggedToilets->appends(request()->except('flagged_page'))->links() }}
                </div>
            </div>
        @endif
    </div>

    <!-- Section 2: Toilets Added in the Last 24 Hours -->
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

@push('scripts')
<script>
    function getCsrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    async function loadNearbyPlaces(selectElem) {
        if (selectElem.dataset.loaded === 'true' || selectElem.dataset.loading === 'true') {
            return;
        }

        const toiletId = selectElem.dataset.toiletId;
        selectElem.dataset.loading = 'true';

        const originalVal = selectElem.value;
        const statusMsg = document.getElementById(`status-msg-${toiletId}`);
        if (statusMsg) {
            statusMsg.style.display = 'block';
            statusMsg.textContent = '⏳ Fetching places from Google Places API (~40m)...';
            statusMsg.style.color = 'var(--primary)';
        }

        try {
            const response = await fetch(`/admin/toilets/${toiletId}/nearby-places`, {
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (response.status === 429) {
                if (statusMsg) {
                    statusMsg.textContent = '⚠️ Google API budget limit exceeded.';
                    statusMsg.style.color = 'var(--danger)';
                }
                selectElem.dataset.loading = 'false';
                return;
            }

            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }

            const data = await response.json();
            const places = data.places || [];

            // Clear current options
            selectElem.innerHTML = '';

            const emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = '-- No Place Assigned --';
            selectElem.appendChild(emptyOpt);

            if (places.length === 0) {
                const noOpt = document.createElement('option');
                noOpt.disabled = true;
                noOpt.textContent = 'No places found within ~40m';
                selectElem.appendChild(noOpt);
            } else {
                places.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.place_id;
                    const distStr = p.distance_m !== null ? ` (${p.distance_m}m)` : '';
                    const addrStr = p.address ? ` - ${p.address}` : '';
                    opt.textContent = `${p.name}${distStr}${addrStr}`;
                    if (p.is_current || p.place_id === originalVal) {
                        opt.selected = true;
                    }
                    selectElem.appendChild(opt);
                });
            }

            selectElem.dataset.loaded = 'true';
            selectElem.dataset.loading = 'false';

            if (statusMsg) {
                statusMsg.textContent = `✓ Loaded ${places.length} place(s)`;
                statusMsg.style.color = 'var(--success)';
                setTimeout(() => { statusMsg.style.display = 'none'; }, 2500);
            }
        } catch (err) {
            console.error('Error fetching nearby places:', err);
            selectElem.dataset.loading = 'false';
            if (statusMsg) {
                statusMsg.textContent = '❌ Failed to load places.';
                statusMsg.style.color = 'var(--danger)';
            }
        }
    }

    async function assignPlace(selectElem) {
        const toiletId = selectElem.dataset.toiletId;
        const placeId = selectElem.value;
        const statusMsg = document.getElementById(`status-msg-${toiletId}`);

        if (statusMsg) {
            statusMsg.style.display = 'block';
            statusMsg.textContent = '💾 Saving place assignment...';
            statusMsg.style.color = 'var(--primary)';
        }

        try {
            const response = await fetch(`/admin/toilets/${toiletId}/assign-place`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken()
                },
                body: JSON.stringify({ place_id: placeId })
            });

            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }

            const data = await response.json();

            // Update current place text in table
            const currentPlaceElem = document.getElementById(`current-place-name-${toiletId}`);
            if (currentPlaceElem) {
                currentPlaceElem.textContent = data.place_name || '-';
            }

            // Visual feedback
            selectElem.style.borderColor = 'var(--success)';
            selectElem.style.backgroundColor = 'var(--success-light)';
            setTimeout(() => {
                selectElem.style.borderColor = '';
                selectElem.style.backgroundColor = '';
            }, 1500);

            if (statusMsg) {
                statusMsg.textContent = '✓ Place updated!';
                statusMsg.style.color = 'var(--success)';
                setTimeout(() => { statusMsg.style.display = 'none'; }, 2000);
            }
        } catch (err) {
            console.error('Error assigning place:', err);
            if (statusMsg) {
                statusMsg.textContent = '❌ Failed to save place.';
                statusMsg.style.color = 'var(--danger)';
            }
        }
    }

    async function unflagToilet(btnElem, toiletId) {
        btnElem.disabled = true;
        btnElem.textContent = '⏳...';

        try {
            const response = await fetch(`/admin/toilets/${toiletId}/unflag`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken()
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }

            const row = document.getElementById(`flagged-row-${toiletId}`);
            if (row) {
                row.style.transition = 'all 0.3s ease';
                row.style.backgroundColor = '#dcfce7';
                row.style.opacity = '0';
                setTimeout(() => {
                    row.remove();
                    // Update badge count
                    const badge = document.getElementById('flagged-count-badge');
                    if (badge) {
                        const count = parseInt(badge.textContent, 10);
                        if (!isNaN(count) && count > 0) {
                            badge.textContent = (count - 1).toString();
                        }
                    }

                    // If no rows left, show empty row
                    const tbody = document.getElementById('flagged-toilets-tbody');
                    if (tbody && tbody.children.length === 0) {
                        tbody.innerHTML = `
                            <tr id="flagged-empty-row">
                                <td colspan="7" style="text-align: center; padding: 2.5rem 1rem; color: var(--gray-500);">
                                    <div style="font-size: 1.75rem; margin-bottom: 0.25rem;">🎉</div>
                                    <div style="font-weight: 600; font-size: 0.9375rem; color: var(--gray-700);">No toilets currently flagged for review</div>
                                    <div style="font-size: 0.8125rem; color: var(--gray-400); margin-top: 0.25rem;">Flag toilets in the edit view or when importing data to review and correct Google Places here.</div>
                                </td>
                            </tr>
                        `;
                    }
                }, 300);
            }
        } catch (err) {
            console.error('Error unflagging toilet:', err);
            btnElem.disabled = false;
            btnElem.textContent = '✓ Unflag';
            alert('Failed to unflag toilet. Please try again.');
        }
    }
</script>
@endpush
