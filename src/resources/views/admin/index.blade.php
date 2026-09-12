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
                        <th style="min-width: 250px;">Assign Google Place (~40m)</th>
                        <th style="width: 220px; text-align: right;">Action</th>
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
                                @if ($toilet->lat !== null && $toilet->lon !== null)
                                    <div style="margin-top: 0.25rem;">
                                        <a
                                            href="https://www.google.com/maps/search/?api=1&query={{ $toilet->lat }},{{ $toilet->lon }}"
                                            target="_blank"
                                            rel="noopener"
                                            style="display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.75rem; color: #1a73e8; text-decoration: none; font-weight: 500;"
                                            title="Open coordinates in Google Maps"
                                        >
                                            🗺️ Google Maps ↗
                                        </a>
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
                                <div style="display: flex; gap: 0.35rem; align-items: center; justify-content: flex-end; flex-wrap: wrap;">
                                    <button
                                        type="button"
                                        class="btn btn-sm"
                                        id="ai-btn-{{ $toilet->id }}"
                                        onclick="requestAiPlaceSuggestion(this, {{ $toilet->id }})"
                                        title="Find and suggest matching Google Place via Gemini AI"
                                        style="background: linear-gradient(135deg, #4f46e5, #7c3aed); color: #ffffff; border: none; font-weight: 600; white-space: nowrap; font-size: 0.75rem; padding: 0.25rem 0.5rem; border-radius: var(--radius-sm);"
                                    >
                                        🤖 AI Match
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-sm"
                                        id="unflag-btn-{{ $toilet->id }}"
                                        onclick="unflagToilet(this, {{ $toilet->id }})"
                                        title="Remove flag and take out of review list"
                                        style="white-space: nowrap; font-size: 0.75rem; padding: 0.25rem 0.5rem;"
                                    >
                                        ✓ Unflag
                                    </button>
                                    <a href="{{ route('admin.toilets.show', $toilet->id) }}" class="btn btn-secondary btn-sm" title="Edit details" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">
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
                                @if ($toilet->lat !== null && $toilet->lon !== null)
                                    <a
                                        href="https://www.google.com/maps/search/?api=1&query={{ $toilet->lat }},{{ $toilet->lon }}"
                                        target="_blank"
                                        rel="noopener"
                                        style="color: #1a73e8; text-decoration: none;"
                                        title="Open coordinates in Google Maps"
                                    >
                                        {{ number_format($toilet->lat, 4) }}, {{ number_format($toilet->lon, 4) }} ↗
                                    </a>
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

    <!-- AI Suggestion Confirmation Modal -->
    <div id="ai-modal-overlay" style="display: none; position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 9999; align-items: center; justify-content: center; padding: 1.5rem; overflow-y: auto;">
        <div style="background: #ffffff; border-radius: var(--radius-lg); max-width: 620px; width: 100%; box-shadow: var(--shadow-lg); overflow: hidden; border: 1px solid var(--gray-200); position: relative; margin: auto;">
            <!-- Header -->
            <div style="padding: 1.25rem 1.5rem; background: linear-gradient(135deg, #4f46e5, #7c3aed); color: #ffffff; display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 0.625rem; font-weight: 700; font-size: 1.125rem;">
                    <span>🤖</span>
                    <span>AI Place Suggestion</span>
                </div>
                <button type="button" onclick="closeAiModal()" style="background: none; border: none; color: #ffffff; font-size: 1.5rem; line-height: 1; cursor: pointer; opacity: 0.8;" title="Close">&times;</button>
            </div>

            <!-- Body -->
            <div style="padding: 1.5rem;">
                <!-- Toilet info bar -->
                <div style="background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: var(--radius-md); padding: 0.75rem 1rem; margin-bottom: 1.25rem;">
                    <div style="font-size: 0.75rem; color: var(--gray-500); font-weight: 600; text-transform: uppercase;">Toilet Record:</div>
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.25rem;">
                        <span id="ai-modal-toilet-name" style="font-weight: 700; color: var(--gray-900);"></span>
                        <span id="ai-modal-toilet-coords" style="font-family: monospace; font-size: 0.8125rem; color: var(--gray-600);"></span>
                    </div>
                </div>

                <!-- Matched Place Box -->
                <div style="border: 2px solid #818cf8; background: #f5f3ff; border-radius: var(--radius-md); padding: 1.25rem; margin-bottom: 1.25rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.5rem; flex-wrap: wrap;">
                        <div style="font-size: 0.75rem; font-weight: 700; color: #4338ca; text-transform: uppercase; letter-spacing: 0.05em;">
                            ⭐ Suggested Google Place
                        </div>
                        <span id="ai-modal-confidence-badge" class="badge" style="background: #dcfce7; color: #166534; font-size: 0.75rem;">High Confidence</span>
                    </div>

                    <h3 id="ai-modal-place-name" style="font-size: 1.125rem; font-weight: 700; color: #1e1b4b; margin-bottom: 0.35rem;"></h3>
                    <div id="ai-modal-place-address" style="font-size: 0.875rem; color: #4b5563; margin-bottom: 0.5rem;"></div>

                    <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.75rem;">
                        <span id="ai-modal-place-distance" class="badge" style="background: #e0e7ff; color: #3730a3; font-size: 0.75rem;">~0m away</span>
                        <div id="ai-modal-place-types" style="display: flex; gap: 0.25rem; flex-wrap: wrap;"></div>
                    </div>

                    <!-- AI Reasoning -->
                    <div style="background: #ffffff; border-radius: var(--radius-sm); padding: 0.75rem; border: 1px solid #e0e7ff; font-size: 0.8125rem; color: #374151;">
                        <div style="font-weight: 600; color: #4f46e5; margin-bottom: 0.25rem;">💡 AI Reasoning:</div>
                        <div id="ai-modal-reasoning" style="line-height: 1.4;"></div>
                    </div>

                    <!-- Google Maps Link -->
                    <div style="margin-top: 1rem;">
                        <a
                            id="ai-modal-maps-link"
                            href="#"
                            target="_blank"
                            rel="noopener"
                            class="btn btn-secondary btn-sm"
                            style="background: #ffffff; border: 1px solid #c7d2fe; color: #4338ca; font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem;"
                        >
                            🗺️ View Toilet Coordinates & Place on Google Maps ↗
                        </a>
                    </div>
                </div>

                <!-- Public accessibility checkbox -->
                <div id="ai-modal-public-container" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--radius-md); padding: 0.875rem; display: flex; align-items: flex-start; gap: 0.75rem;">
                    <input type="checkbox" id="ai-modal-public-checkbox" style="margin-top: 0.2rem; cursor: pointer; width: 1.1rem; height: 1.1rem; accent-color: var(--success);">
                    <label for="ai-modal-public-checkbox" style="font-size: 0.875rem; color: #166534; cursor: pointer; line-height: 1.4;">
                        <strong>Set "public_accessible" property to Yes</strong>
                        <div style="font-size: 0.75rem; color: #15803d; margin-top: 0.125rem;">The suggested place belongs to public transport, parks, civic buildings, or public amenities.</div>
                    </label>
                </div>
            </div>

            <!-- Footer -->
            <div style="padding: 1rem 1.5rem; background: var(--gray-50); border-top: 1px solid var(--gray-200); display: flex; align-items: center; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" onclick="closeAiModal()" class="btn btn-secondary" style="font-weight: 600;">
                    ✕ Reject / Close
                </button>
                <button type="button" id="ai-modal-confirm-btn" onclick="confirmAiMatch()" class="btn btn-primary" style="background: linear-gradient(135deg, #16a34a, #15803d); border: none; font-weight: 700;">
                    ✓ Confirm & Assign Place
                </button>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
<script>
    let activeAiMatchData = null;

    function getCsrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    async function requestAiPlaceSuggestion(btnElem, toiletId) {
        const originalText = btnElem.textContent;
        btnElem.disabled = true;
        btnElem.textContent = '⏳ Thinking...';

        const statusMsg = document.getElementById(`status-msg-${toiletId}`);
        if (statusMsg) {
            statusMsg.style.display = 'block';
            statusMsg.textContent = '🤖 Gemini AI searching and evaluating places...';
            statusMsg.style.color = '#6366f1';
        }

        try {
            const response = await fetch(`/admin/toilets/${toiletId}/ai-suggest-place`, {
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                const errMsg = data.error || 'Failed to get AI place suggestion.';
                alert(`AI Suggestion Error: ${errMsg}`);
                if (statusMsg) {
                    statusMsg.textContent = `❌ ${errMsg}`;
                    statusMsg.style.color = 'var(--danger)';
                }
                btnElem.disabled = false;
                btnElem.textContent = originalText;
                return;
            }

            if (!data.matched) {
                alert(`ℹ️ No Matching Place Found:\n\n${data.message || ''}\n\nReason: ${data.reasoning || 'No candidate places matched.'}`);
                if (statusMsg) {
                    statusMsg.textContent = '⚠️ AI found no confident match.';
                    statusMsg.style.color = 'var(--warning)';
                }
                btnElem.disabled = false;
                btnElem.textContent = originalText;
                return;
            }

            // Populate Modal
            activeAiMatchData = data;

            document.getElementById('ai-modal-toilet-name').textContent = `Toilet #${data.toilet_id} - ${data.toilet_name || 'Unnamed'}`;
            const coordsText = (data.toilet_lat && data.toilet_lon) ? `Lat: ${data.toilet_lat}, Lon: ${data.toilet_lon}` : 'No coordinates';
            document.getElementById('ai-modal-toilet-coords').textContent = coordsText;

            document.getElementById('ai-modal-place-name').textContent = data.place.name || data.place.place_id;
            document.getElementById('ai-modal-place-address').textContent = data.place.address || 'Address not specified';

            const confBadge = document.getElementById('ai-modal-confidence-badge');
            if (data.confidence === 'high') {
                confBadge.textContent = '✓ High Confidence';
                confBadge.style.background = '#dcfce7';
                confBadge.style.color = '#166534';
            } else if (data.confidence === 'medium') {
                confBadge.textContent = '⚡ Medium Confidence';
                confBadge.style.background = '#fef3c7';
                confBadge.style.color = '#92400e';
            } else {
                confBadge.textContent = '⚠️ Low Confidence';
                confBadge.style.background = '#fee2e2';
                confBadge.style.color = '#991b1b';
            }

            const distBadge = document.getElementById('ai-modal-place-distance');
            if (data.place.distance_m !== null) {
                distBadge.textContent = `~${data.place.distance_m}m away`;
                distBadge.style.display = 'inline-block';
            } else {
                distBadge.style.display = 'none';
            }

            // Types chips
            const typesContainer = document.getElementById('ai-modal-place-types');
            typesContainer.innerHTML = '';
            if (Array.isArray(data.place.types)) {
                data.place.types.forEach(t => {
                    const tag = document.createElement('span');
                    tag.className = 'badge';
                    tag.style.background = '#e2e8f0';
                    tag.style.color = '#334155';
                    tag.style.fontSize = '0.6875rem';
                    tag.textContent = t;
                    typesContainer.appendChild(tag);
                });
            }

            document.getElementById('ai-modal-reasoning').textContent = data.reasoning || 'Matched place with toilet record.';

            const mapsLink = document.getElementById('ai-modal-maps-link');
            mapsLink.href = data.place.maps_url || '#';

            const publicCheckbox = document.getElementById('ai-modal-public-checkbox');
            publicCheckbox.checked = Boolean(data.place.is_public_accessible);

            // Open Modal
            const overlay = document.getElementById('ai-modal-overlay');
            overlay.style.display = 'flex';

            if (statusMsg) {
                statusMsg.textContent = '✓ AI suggested a place. Awaiting confirmation...';
                statusMsg.style.color = '#4f46e5';
            }
        } catch (err) {
            console.error('AI place match error:', err);
            alert('An unexpected error occurred while requesting AI place suggestion.');
            if (statusMsg) {
                statusMsg.textContent = '❌ AI request error.';
                statusMsg.style.color = 'var(--danger)';
            }
        } finally {
            btnElem.disabled = false;
            btnElem.textContent = originalText;
        }
    }

    async function confirmAiMatch() {
        if (!activeAiMatchData || !activeAiMatchData.place) {
            return;
        }

        const confirmBtn = document.getElementById('ai-modal-confirm-btn');
        confirmBtn.disabled = true;
        confirmBtn.textContent = '⏳ Saving...';

        const toiletId = activeAiMatchData.toilet_id;
        const placeId = activeAiMatchData.place.place_id;
        const setPublic = document.getElementById('ai-modal-public-checkbox').checked;

        try {
            const response = await fetch(`/admin/toilets/${toiletId}/ai-accept-place`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken()
                },
                body: JSON.stringify({
                    place_id: placeId,
                    set_public_accessible: setPublic
                })
            });

            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }

            const resData = await response.json();

            // Update UI table row
            const currentPlaceElem = document.getElementById(`current-place-name-${toiletId}`);
            if (currentPlaceElem) {
                currentPlaceElem.textContent = resData.place_name || activeAiMatchData.place.name || placeId;
            }

            const dropdown = document.getElementById(`places-dropdown-${toiletId}`);
            if (dropdown) {
                dropdown.value = placeId;
            }

            const statusMsg = document.getElementById(`status-msg-${toiletId}`);
            if (statusMsg) {
                statusMsg.style.display = 'block';
                statusMsg.textContent = '✓ AI Place & properties applied!';
                statusMsg.style.color = 'var(--success)';
                setTimeout(() => { statusMsg.style.display = 'none'; }, 3000);
            }

            // Visual highlight row
            const row = document.getElementById(`flagged-row-${toiletId}`);
            if (row) {
                row.style.transition = 'background-color 0.5s ease';
                row.style.backgroundColor = '#dcfce7';
                setTimeout(() => { row.style.backgroundColor = ''; }, 2000);
            }

            closeAiModal();
        } catch (err) {
            console.error('Failed to confirm AI place:', err);
            alert('Failed to save the assigned place. Please try again.');
        } finally {
            confirmBtn.disabled = false;
            confirmBtn.textContent = '✓ Confirm & Assign Place';
        }
    }

    function closeAiModal() {
        const overlay = document.getElementById('ai-modal-overlay');
        overlay.style.display = 'none';
        activeAiMatchData = null;
    }

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAiModal();
        }
    });

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
