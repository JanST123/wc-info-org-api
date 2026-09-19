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

        <div class="card" style="margin-bottom: 0; padding: 1.25rem; border-color: var(--primary);">
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
    <div class="card" style="padding: 1rem 1.5rem; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; background-color: {{ $stats['is_budget_exceeded'] ? 'var(--danger-light)' : 'var(--bg-card)' }}; border-color: {{ $stats['is_budget_exceeded'] ? 'var(--danger)' : 'var(--border-main)' }};">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="font-size: 1.5rem;">📊</div>
            <div>
                <div style="font-weight: 600; font-size: 0.9375rem; color: {{ $stats['is_budget_exceeded'] ? 'var(--danger)' : 'var(--text-heading)' }};">
                    Google Cloud API Budget ({{ date('F Y') }}):
                    <span style="font-family: monospace;">${{ number_format($stats['current_month_cost'], 2) }}</span> /
                    <span style="font-family: monospace;">${{ number_format($stats['monthly_budget'], 2) }} USD</span>
                    @if ($stats['is_budget_exceeded'])
                        <span class="badge badge-deleted" style="margin-left: 0.5rem;">⚠️ Limit Exceeded</span>
                    @else
                        <span class="badge badge-active" style="margin-left: 0.5rem;">✓ Active</span>
                    @endif
                </div>
                <div style="font-size: 0.8125rem; color: var(--text-muted); margin-top: 0.125rem;">
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
        <div class="card-header card-header-flagged">
            <div class="card-title">
                <span>🚩 Flagged Toilets for Review</span>
                <span id="flagged-count-badge" class="badge" style="background: #fef3c7; color: #92400e; font-size: 0.8125rem;">{{ $flaggedToilets->total() }}</span>
            </div>
            <div class="card-subtitle">
                Fast Place correction workflow • Places API queries only on dropdown open
            </div>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 70px;">ID</th>
                        <th style="width: 110px;">Status</th>
                        <th style="width: 180px;">Name & Owner</th>
                        <th style="width: 180px;">Comment</th>
                        <th style="width: 180px;">Address</th>
                        <th style="width: 180px;">Current Place</th>
                        <th style="width: 100px; text-align: center;">Public Acc.</th>
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
                                <select
                                    class="form-control status-select-{{ $toilet->status }}"
                                    id="status-select-{{ $toilet->id }}"
                                    data-toilet-id="{{ $toilet->id }}"
                                    data-original-value="{{ $toilet->status }}"
                                    onchange="updateToiletStatus(this, {{ $toilet->id }})"
                                    style="font-size: 0.75rem; height: 32px; padding: 0.125rem 0.375rem; width: 105px; font-weight: 600;"
                                >
                                    <option value="active" {{ $toilet->status === 'active' ? 'selected' : '' }}>🟢 Active</option>
                                    <option value="hidden" {{ $toilet->status === 'hidden' ? 'selected' : '' }}>⚪ Hidden</option>
                                    <option value="deleted" {{ $toilet->status === 'deleted' ? 'selected' : '' }}>🔴 Deleted</option>
                                </select>
                            </td>
                            <td id="name-cell-{{ $toilet->id }}" style="min-width: 180px;">
                                <div id="name-display-container-{{ $toilet->id }}" style="display: flex; align-items: flex-start; justify-content: space-between; gap: 0.25rem;">
                                    <div>
                                        <a href="{{ route('admin.toilets.show', $toilet->id) }}" id="toilet-name-link-{{ $toilet->id }}" style="font-weight: 600; color: var(--gray-900);">
                                            {{ $toilet->name ?: 'Unnamed Toilet' }}
                                        </a>
                                        @if (!empty($toilet->owner))
                                            <div style="font-size: 0.75rem; color: var(--gray-500);">
                                                {{ $toilet->owner }}
                                            </div>
                                        @endif
                                    </div>
                                    <button
                                        type="button"
                                        onclick="startInlineNameEdit({{ $toilet->id }})"
                                        title="Quickly edit name"
                                        style="background: none; border: none; cursor: pointer; padding: 0.125rem 0.25rem; font-size: 0.75rem; opacity: 0.6; transition: opacity 0.2s; line-height: 1;"
                                        onmouseover="this.style.opacity='1'"
                                        onmouseout="this.style.opacity='0.6'"
                                    >
                                        ✏️
                                    </button>
                                </div>

                                <div id="name-edit-container-{{ $toilet->id }}" style="display: none; margin-bottom: 0.25rem;">
                                    <div style="display: flex; gap: 0.25rem; align-items: center;">
                                        <input
                                            type="text"
                                            class="form-control"
                                            id="name-input-{{ $toilet->id }}"
                                            value="{{ $toilet->name ?? '' }}"
                                            data-original-value="{{ $toilet->name ?? '' }}"
                                            style="font-size: 0.75rem; height: 28px; padding: 0.125rem 0.375rem; width: 140px; font-weight: 600;"
                                            onkeydown="handleNameInputKeydown(event, {{ $toilet->id }})"
                                        >
                                        <button
                                            type="button"
                                            class="btn btn-primary btn-sm"
                                            onclick="saveInlineName({{ $toilet->id }})"
                                            title="Save name (Enter)"
                                            style="height: 28px; padding: 0 0.375rem; font-size: 0.75rem; display: flex; align-items: center; justify-content: center;"
                                        >
                                            ✓
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-secondary btn-sm"
                                            onclick="cancelInlineNameEdit({{ $toilet->id }})"
                                            title="Cancel (Esc)"
                                            style="height: 28px; padding: 0 0.375rem; font-size: 0.75rem; display: flex; align-items: center; justify-content: center;"
                                        >
                                            ✕
                                        </button>
                                    </div>
                                </div>

                                <div id="maps-container-{{ $toilet->id }}" style="margin-top: 0.25rem; display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; {{ ($toilet->lat !== null && $toilet->lon !== null) ? '' : 'display: none;' }}">
                                    <a
                                        id="maps-link-{{ $toilet->id }}"
                                        href="{{ ($toilet->lat !== null && $toilet->lon !== null) ? 'https://www.google.com/maps/search/?api=1&query=' . $toilet->lat . ',' . $toilet->lon : '#' }}"
                                        target="_blank"
                                        rel="noopener"
                                        style="display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.75rem; color: var(--primary); text-decoration: none; font-weight: 500;"
                                        title="Open coordinates in Google Maps"
                                    >
                                        🗺️ Google Maps ↗
                                    </a>
                                    <button
                                        type="button"
                                        onclick="openToiletMapModal({{ $toilet->id }}, {{ $toilet->lat ?? 0 }}, {{ $toilet->lon ?? 0 }}, '{{ addslashes($toilet->name ?: 'Toilet #' . $toilet->id) }}')"
                                        style="background: none; border: none; padding: 0; display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.75rem; color: #7c3aed; cursor: pointer; font-weight: 600; text-decoration: underline;"
                                        title="Open interactive satellite map with POIs & database toilets"
                                    >
                                        🛰️ Satellite Map
                                    </button>
                                </div>
                            </td>
                            <td style="font-size: 0.8125rem; color: var(--gray-700); max-width: 200px; word-break: break-word;">
                                {{ $toilet->propertyValue('comment') ?: '-' }}
                            </td>
                            <td style="font-size: 0.8125rem; color: var(--gray-700); max-width: 200px; word-break: break-word;">
                                {{ $toilet->propertyValue('address') ?: '-' }}
                            </td>
                            <td style="font-size: 0.8125rem;">
                                <div id="current-place-name-{{ $toilet->id }}" style="font-weight: 600; color: var(--gray-900);">
                                    @php
                                        $placeEmoji = $toilet->place?->getEmoji() ?? '';
                                        $prefix = $placeEmoji ? $placeEmoji . ' ' : '';
                                    @endphp
                                    {{ $toilet->place?->getName() ? $prefix . $toilet->place->getName() : ($toilet->place_id ?: '-') }}
                                </div>
                                @if (!empty($toilet->place_id))
                                    <div style="font-family: monospace; font-size: 0.6875rem; color: var(--gray-400);">
                                        {{ \Illuminate\Support\Str::limit($toilet->place_id, 16) }}
                                    </div>
                                @endif
                            </td>
                            <td style="text-align: center; vertical-align: middle;">
                                <label style="cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem; margin: 0; user-select: none;" title="Toggle public_accessible flag">
                                    <input
                                        type="checkbox"
                                        id="public-accessible-checkbox-{{ $toilet->id }}"
                                        data-toilet-id="{{ $toilet->id }}"
                                        {{ $toilet->isFlagSet('public_accessible') ? 'checked' : '' }}
                                        onchange="updateToiletPublicAccessible(this, {{ $toilet->id }})"
                                        style="cursor: pointer; width: 16px; height: 16px; accent-color: var(--primary);"
                                    >
                                    <span id="public-accessible-label-{{ $toilet->id }}" style="font-size: 0.75rem; font-weight: 600; color: {{ $toilet->isFlagSet('public_accessible') ? '#166534' : '#64748b' }};">
                                        {{ $toilet->isFlagSet('public_accessible') ? 'Yes' : 'No' }}
                                    </span>
                                </label>
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
                                        @php
                                            $placeEmoji = $toilet->place?->getEmoji() ?? '';
                                            $prefix = $placeEmoji ? $placeEmoji . ' ' : '';
                                        @endphp
                                        {{ $toilet->place?->getName() ? 'Current: ' . $prefix . $toilet->place->getName() : ($toilet->place_id ? 'Current ID: ' . $toilet->place_id : '-- Click to load places (~40m) --') }}
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
                            <td colspan="9" style="text-align: center; padding: 2.5rem 1rem; color: var(--gray-500);">
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
                                    <div style="display: flex; flex-direction: column; gap: 0.15rem;">
                                        <a
                                            href="https://www.google.com/maps/search/?api=1&query={{ $toilet->lat }},{{ $toilet->lon }}"
                                            target="_blank"
                                            rel="noopener"
                                            style="color: var(--primary); text-decoration: none;"
                                            title="Open coordinates in Google Maps"
                                        >
                                            {{ number_format($toilet->lat, 4) }}, {{ number_format($toilet->lon, 4) }} ↗
                                        </a>
                                        <button
                                            type="button"
                                            onclick="openToiletMapModal({{ $toilet->id }}, {{ $toilet->lat }}, {{ $toilet->lon }}, '{{ addslashes($toilet->name ?: 'Toilet #' . $toilet->id) }}')"
                                            style="background: none; border: none; padding: 0; display: inline-flex; align-items: center; gap: 0.2rem; font-size: 0.6875rem; color: #7c3aed; cursor: pointer; font-weight: 600; text-decoration: underline; text-align: left;"
                                            title="Open interactive satellite map with POIs & database toilets"
                                        >
                                            🛰️ Satellite Map
                                        </button>
                                    </div>
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
    <div id="ai-modal-overlay" style="display: none; position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); z-index: 9999; align-items: center; justify-content: center; padding: 1.5rem; overflow-y: auto;">
        <div class="card" style="max-width: 620px; width: 100%; box-shadow: var(--shadow-lg); overflow: hidden; position: relative; margin: auto; padding: 0;">
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
                <div style="background: var(--bg-subtle); border: 1px solid var(--border-main); border-radius: var(--radius-md); padding: 0.75rem 1rem; margin-bottom: 1.25rem;">
                    <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Toilet Record:</div>
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.25rem;">
                        <span id="ai-modal-toilet-name" style="font-weight: 700; color: var(--text-heading);"></span>
                        <span id="ai-modal-toilet-coords" style="font-family: monospace; font-size: 0.8125rem; color: var(--text-muted);"></span>
                    </div>
                </div>

                <!-- Matched Place Box -->
                <div class="ai-place-card">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.5rem; flex-wrap: wrap;">
                        <div class="ai-place-card-title">
                            ⭐ Suggested Google Place
                        </div>
                        <span id="ai-modal-confidence-badge" class="badge" style="background: #dcfce7; color: #166534; font-size: 0.75rem;">High Confidence</span>
                    </div>

                    <h3 id="ai-modal-place-name" style="font-size: 1.125rem; font-weight: 700; margin-bottom: 0.35rem;"></h3>
                    <div id="ai-modal-place-address" style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 0.5rem;"></div>

                    <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.75rem;">
                        <span id="ai-modal-place-distance" class="badge badge-distance-near" style="font-size: 0.75rem;">~0m away</span>
                        <div id="ai-modal-place-types" style="display: flex; gap: 0.25rem; flex-wrap: wrap;"></div>
                    </div>

                    <!-- AI Reasoning -->
                    <div class="ai-reasoning-box">
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
                            style="font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem;"
                        >
                            🗺️ View Toilet Coordinates & Place on Google Maps ↗
                        </a>
                    </div>
                </div>

                <!-- Public accessibility checkbox -->
                <div id="ai-modal-public-container" class="ai-public-box">
                    <input type="checkbox" id="ai-modal-public-checkbox" style="margin-top: 0.2rem; cursor: pointer; width: 1.1rem; height: 1.1rem; accent-color: var(--success);">
                    <label for="ai-modal-public-checkbox">
                        <strong>Set "public_accessible" property to Yes</strong>
                        <div class="ai-public-desc">The suggested place belongs to public transport, parks, civic buildings, or public amenities.</div>
                    </label>
                </div>
            </div>

            <!-- Footer -->
            <div style="padding: 1rem 1.5rem; background: var(--bg-subtle); border-top: 1px solid var(--border-main); display: flex; align-items: center; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" onclick="closeAiModal()" class="btn btn-secondary" style="font-weight: 600;">
                    ✕ Reject / Close
                </button>
                <button type="button" id="ai-modal-confirm-btn" onclick="confirmAiMatch()" class="btn btn-primary" style="background: linear-gradient(135deg, #16a34a, #15803d); border: none; font-weight: 700;">
                    ✓ Confirm & Assign Place
                </button>
            </div>
        </div>
    </div>

    <!-- Satellite & POI Map Modal Overlay -->
    <div id="toilet-map-modal-overlay" style="display: none; position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); z-index: 9999; align-items: center; justify-content: center; padding: 1rem; overflow: hidden;">
        <div class="card" style="max-width: 1400px; width: 96vw; height: 90vh; box-shadow: var(--shadow-lg); overflow: hidden; position: relative; margin: auto; padding: 0; display: flex; flex-direction: column;">
            <!-- Header -->
            <div style="padding: 0.75rem 1.25rem; background: linear-gradient(135deg, #1e1b4b, #312e81); color: #ffffff; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: nowrap;">
                <div style="display: flex; align-items: center; gap: 0.625rem; min-width: 0; flex-shrink: 1;">
                    <span style="font-size: 1.25rem; flex-shrink: 0;">🛰️</span>
                    <div style="min-width: 0; overflow: hidden;">
                        <div style="font-weight: 700; font-size: 1rem; display: flex; align-items: center; gap: 0.5rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            <span>Satellite & POI Map Preview</span>
                            <span id="map-modal-target-badge" class="badge" style="background: rgba(255,255,255,0.2); color: #ffffff; font-family: monospace; flex-shrink: 0;"></span>
                            <span id="map-modal-target-name" style="font-size: 0.875rem; font-weight: 600; color: #e0e7ff; overflow: hidden; text-overflow: ellipsis;"></span>
                        </div>
                        <div id="map-modal-target-sub" style="font-size: 0.75rem; color: #c7d2fe; margin-top: 0.125rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"></div>
                    </div>
                </div>

                <!-- Layer and Filter Bar -->
                <div style="display: flex; align-items: center; gap: 0.625rem; flex-wrap: nowrap; flex-shrink: 0;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; background: rgba(0,0,0,0.3); padding: 0.3rem 0.6rem; border-radius: var(--radius-sm); font-size: 0.75rem; white-space: nowrap;">
                        <label style="display: flex; align-items: center; gap: 0.25rem; cursor: pointer; color: #ffffff; font-weight: 500;">
                            <input type="checkbox" id="map-filter-target" checked onchange="toggleMapFilter('target')" style="accent-color: #ef4444; cursor: pointer;">
                            <span>🚩 Target</span>
                        </label>
                        <span style="color: rgba(255,255,255,0.3);">|</span>
                        <label style="display: flex; align-items: center; gap: 0.25rem; cursor: pointer; color: #ffffff; font-weight: 500;">
                            <input type="checkbox" id="map-filter-pois" checked onchange="toggleMapFilter('pois')" style="accent-color: #818cf8; cursor: pointer;">
                            <span>⭐ Google POIs (<span id="map-pois-count">0</span>)</span>
                        </label>
                        <span style="color: rgba(255,255,255,0.3);">|</span>
                        <label style="display: flex; align-items: center; gap: 0.25rem; cursor: pointer; color: #ffffff; font-weight: 500;">
                            <input type="checkbox" id="map-filter-toilets" checked onchange="toggleMapFilter('toilets')" style="accent-color: #3b82f6; cursor: pointer;">
                            <span>🚽 DB Toilets (<span id="map-toilets-count">0</span>)</span>
                        </label>
                    </div>

                    <!-- View Mode Switcher -->
                    <div style="display: flex; align-items: center; gap: 0.2rem; background: rgba(0,0,0,0.4); padding: 0.2rem; border-radius: var(--radius-sm); white-space: nowrap;">
                        <button
                            type="button"
                            id="map-mode-map-btn"
                            onclick="setMapDisplayMode('map')"
                            style="font-size: 0.75rem; padding: 0.2rem 0.5rem; background: #4f46e5; color: #ffffff; border: none; font-weight: 600; border-radius: var(--radius-xs); cursor: pointer; transition: all 0.15s ease;"
                            title="Full Satellite Map"
                        >
                            🛰️ Map
                        </button>
                        <button
                            type="button"
                            id="map-mode-split-btn"
                            onclick="setMapDisplayMode('split')"
                            style="font-size: 0.75rem; padding: 0.2rem 0.5rem; background: transparent; color: #e0e7ff; border: none; font-weight: 500; border-radius: var(--radius-xs); cursor: pointer; transition: all 0.15s ease;"
                            title="Side-by-side Map & Street View"
                        >
                            🌓 Split
                        </button>
                        <button
                            type="button"
                            id="map-mode-streetview-btn"
                            onclick="setMapDisplayMode('streetview')"
                            style="font-size: 0.75rem; padding: 0.2rem 0.5rem; background: transparent; color: #e0e7ff; border: none; font-weight: 500; border-radius: var(--radius-xs); cursor: pointer; transition: all 0.15s ease;"
                            title="Full Google Street View"
                        >
                            🚶 Street View
                        </button>
                    </div>

                    <button type="button" class="btn btn-sm" onclick="recenterMapTarget()" style="background: rgba(255,255,255,0.15); color: #ffffff; border: 1px solid rgba(255,255,255,0.25); font-size: 0.75rem; padding: 0.25rem 0.625rem; white-space: nowrap;" title="Recenter on target toilet">
                        🎯 Recenter
                    </button>
                    <button type="button" onclick="closeToiletMapModal()" style="background: none; border: none; color: #ffffff; font-size: 1.5rem; line-height: 1; cursor: pointer; opacity: 0.8;" title="Close (Esc)">&times;</button>
                </div>
            </div>

            <!-- Main Content Area: Map + Street View + Sidebar -->
            <div style="display: flex; flex: 1; overflow: hidden; position: relative;">
                <!-- Floating POIs Loading Pill -->
                <div id="map-pois-loading-badge" style="display: none; position: absolute; top: 14px; left: 50%; transform: translateX(-50%); z-index: 1500; align-items: center; gap: 0.45rem; font-size: 0.75rem; color: #ffffff; font-weight: 600; background: rgba(15, 23, 42, 0.88); backdrop-filter: blur(6px); padding: 0.35rem 0.85rem; border-radius: 9999px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -4px rgba(0, 0, 0, 0.2); border: 1px solid rgba(255, 255, 255, 0.2); pointer-events: none;">
                    <span style="display: inline-block; width: 10px; height: 10px; border: 2px solid rgba(253,224,71,0.4); border-top-color: #fde047; border-radius: 50%; animation: spin 0.8s infinite linear;"></span>
                    <span style="color: #fde047;">Loading view POIs...</span>
                </div>

                <!-- Map Container -->
                <div id="toilet-leaflet-map" style="flex: 1; height: 100%; min-height: 400px; z-index: 1;"></div>

                <!-- Google Street View Container -->
                <div id="toilet-streetview-wrapper" style="display: none; flex: 1; height: 100%; position: relative; background: #0f172a; flex-direction: column; border-left: 2px solid var(--border-main); z-index: 2;">
                    <div style="padding: 0.4rem 0.75rem; background: rgba(15, 23, 42, 0.95); color: #ffffff; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.12); font-size: 0.75rem; z-index: 10; gap: 0.5rem; flex-wrap: wrap;">
                        <div style="display: flex; align-items: center; gap: 0.4rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <span style="font-size: 0.9rem;">🚶</span>
                            <span id="streetview-location-label" style="font-weight: 600; color: #e0e7ff;">Target Toilet Viewpoint</span>
                            <span id="streetview-coords-label" style="font-family: monospace; color: #94a3b8; font-size: 0.6875rem;"></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <button type="button" class="btn btn-sm" onclick="syncStreetViewTarget()" style="background: rgba(255,255,255,0.15); color: #ffffff; border: 1px solid rgba(255,255,255,0.2); font-size: 0.6875rem; padding: 0.15rem 0.5rem;" title="Reset Street View to target toilet coordinates">
                                🎯 Target Coords
                            </button>
                            <a id="streetview-external-link" href="#" target="_blank" rel="noopener" class="btn btn-secondary btn-sm" style="font-size: 0.6875rem; padding: 0.15rem 0.5rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.25rem;">
                                Google Maps ↗
                            </a>
                        </div>
                    </div>
                    <iframe
                        id="streetview-iframe"
                        src=""
                        style="width: 100%; height: 100%; border: none; flex: 1; background: #000000;"
                        allowfullscreen
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                    ></iframe>
                </div>

                <!-- Loading overlay -->
                <div id="map-loading-overlay" style="display: none; position: absolute; inset: 0; background: rgba(15, 23, 42, 0.7); z-index: 2000; align-items: center; justify-content: center; color: #ffffff; font-weight: 600; font-size: 1rem; gap: 0.75rem;">
                    <div style="width: 24px; height: 24px; border: 3px solid rgba(255,255,255,0.3); border-top-color: #ffffff; border-radius: 50%; animation: spin 1s infinite linear;"></div>
                    <span>Loading satellite map & POI data...</span>
                </div>

                <!-- Assign Place & Apply Coordinates Confirmation Dialog -->
                <div id="map-assign-confirm-dialog" style="display: none; position: absolute; inset: 0; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); z-index: 2500; align-items: center; justify-content: center; padding: 1rem;">
                    <div class="card" style="max-width: 480px; width: 100%; box-shadow: var(--shadow-lg); border: 1px solid var(--border-main); background: var(--bg-card); padding: 0; overflow: hidden; border-radius: var(--radius-lg); margin: auto;">
                        <!-- Dialog Header -->
                        <div style="padding: 0.875rem 1.25rem; background: linear-gradient(135deg, #1e1b4b, #312e81); color: #ffffff; display: flex; align-items: center; justify-content: space-between;">
                            <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 700; font-size: 0.9375rem;">
                                <span>📍</span>
                                <span>Assign Place to Toilet</span>
                            </div>
                            <button type="button" onclick="closeMapAssignDialog()" style="background: none; border: none; color: #ffffff; font-size: 1.25rem; line-height: 1; cursor: pointer; opacity: 0.8;" title="Cancel">&times;</button>
                        </div>

                        <!-- Dialog Body -->
                        <div style="padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem;">
                            <div>
                                <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); margin-bottom: 0.25rem;">Selected Google Place:</div>
                                <div id="map-dialog-place-name" style="font-size: 1rem; font-weight: 700; color: var(--text-heading); display: flex; align-items: center; gap: 0.35rem;"></div>
                            </div>

                            <!-- Coordinate Comparison Box -->
                            <div style="background: var(--bg-subtle); border: 1px solid var(--border-main); border-radius: var(--radius-md); padding: 0.875rem; font-size: 0.8125rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                                    <span style="font-weight: 600; color: var(--text-muted);">Current Toilet Coords:</span>
                                    <span id="map-dialog-toilet-coords" style="font-family: monospace; font-weight: 600;"></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                                    <span style="font-weight: 600; color: var(--text-muted);">Place Coords:</span>
                                    <span id="map-dialog-place-coords" style="font-family: monospace; font-weight: 600; color: #4338ca;"></span>
                                </div>
                                <div id="map-dialog-distance-row" style="display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed var(--border-main); padding-top: 0.4rem; margin-top: 0.25rem;">
                                    <span style="font-weight: 600; color: var(--text-muted);">Distance Difference:</span>
                                    <span id="map-dialog-distance" class="badge"></span>
                                </div>
                            </div>

                            <div style="font-size: 0.875rem; color: var(--text-body); line-height: 1.4;">
                                Would you also like to update the toilet's coordinates to match this place?
                            </div>

                            <!-- Public accessibility checkbox -->
                            <div id="map-dialog-public-container" class="ai-public-box" style="margin-top: 0.25rem;">
                                <input type="checkbox" id="map-dialog-public-checkbox" style="margin-top: 0.2rem; cursor: pointer; width: 1.1rem; height: 1.1rem; accent-color: var(--success);">
                                <label for="map-dialog-public-checkbox" style="cursor: pointer;">
                                    <strong>Set "public_accessible" property to Yes</strong>
                                    <div class="ai-public-desc" style="font-size: 0.75rem; color: var(--text-muted);">The selected place belongs to public transport, parks, civic buildings, or public amenities.</div>
                                </label>
                            </div>
                        </div>

                        <!-- Dialog Actions -->
                        <div style="padding: 0.875rem 1.25rem; background: var(--bg-subtle); border-top: 1px solid var(--border-main); display: flex; flex-direction: column; gap: 0.5rem;">
                            <button
                                type="button"
                                id="map-dialog-assign-apply-btn"
                                class="btn btn-primary"
                                style="width: 100%; justify-content: center; font-weight: 700; background: linear-gradient(135deg, #16a34a, #15803d); border: none; padding: 0.6rem 1rem;"
                            >
                                ✓ Assign Place & Apply Coordinates
                            </button>
                            <button
                                type="button"
                                id="map-dialog-assign-only-btn"
                                class="btn btn-secondary"
                                style="width: 100%; justify-content: center; font-weight: 600; padding: 0.5rem 1rem;"
                            >
                                Assign Place Only (Keep Coords)
                            </button>
                            <button
                                type="button"
                                onclick="closeMapAssignDialog()"
                                class="btn btn-sm"
                                style="background: none; border: none; color: var(--text-muted); cursor: pointer; text-decoration: underline; margin-top: 0.125rem; font-size: 0.75rem;"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Right Sidebar (Places & Nearby Toilets list) -->
                <div id="map-sidebar" style="width: 340px; border-left: 1px solid var(--border-main); background: var(--bg-card); display: flex; flex-direction: column; overflow: hidden; z-index: 10;">
                    <div style="padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-main); background: var(--bg-subtle); display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-weight: 700; font-size: 0.8125rem; color: var(--text-heading); text-transform: uppercase; letter-spacing: 0.05em;">
                            POIs & DB Toilets
                        </span>
                        <span id="map-sidebar-total" class="badge" style="background: var(--primary-light); color: var(--primary); font-size: 0.6875rem;">0</span>
                    </div>

                    <div id="map-sidebar-items" style="flex: 1; overflow-y: auto; padding: 0.5rem; display: flex; flex-direction: column; gap: 0.5rem;">
                        <!-- Items rendered dynamically -->
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div style="padding: 0.625rem 1.25rem; background: var(--bg-subtle); border-top: 1px solid var(--border-main); display: flex; align-items: center; justify-content: space-between; font-size: 0.75rem; color: var(--text-muted); flex-wrap: wrap; gap: 0.5rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                    <span>💡 <em>Click any Google POI marker on the map to inspect details or assign it directly to the toilet.</em></span>
                </div>
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <span id="map-modal-coords" style="font-family: monospace;"></span>
                    <button type="button" onclick="closeToiletMapModal()" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 0.2rem 0.6rem;">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Undo Toast for Unflagging -->
    <div id="undo-toast" style="display: none; position: fixed; bottom: 2rem; left: 50%; transform: translateX(-50%) translateY(20px); background: #0f172a; color: #f8fafc; padding: 0.75rem 1.25rem; border-radius: 9999px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3), 0 10px 10px -5px rgba(0, 0, 0, 0.2); z-index: 99999; align-items: center; gap: 1rem; font-size: 0.875rem; border: 1px solid rgba(255, 255, 255, 0.15); opacity: 0; transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);">
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <span style="display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; background: #22c55e; color: #ffffff; border-radius: 50%; font-size: 0.75rem; font-weight: bold;">✓</span>
            <span id="undo-toast-text" style="font-weight: 500;">Toilet unflagged</span>
        </div>
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <button
                type="button"
                id="undo-toast-btn"
                onclick="executeUndoUnflag()"
                style="background: #3b82f6; color: #ffffff; border: none; font-weight: 600; font-size: 0.8125rem; padding: 0.3rem 0.85rem; border-radius: 9999px; cursor: pointer; transition: all 0.15s ease;"
                onmouseover="this.style.background='#2563eb'"
                onmouseout="this.style.background='#3b82f6'"
            >
                ↩ Undo (<span id="undo-toast-timer">5</span>s)
            </button>
            <button
                type="button"
                onclick="dismissUndoToast()"
                title="Dismiss"
                style="background: none; border: none; color: #94a3b8; font-size: 1.125rem; cursor: pointer; padding: 0.125rem 0.375rem; line-height: 1; border-radius: 4px;"
                onmouseover="this.style.color='#ffffff'"
                onmouseout="this.style.color='#94a3b8'"
            >
                ✕
            </button>
        </div>
    </div>

@endsection

@push('scripts')
<script>
    let activeAiMatchData = null;

    function getPlaceEmoji(types) {
        if (!Array.isArray(types) || types.length === 0) return '';
        
        const typeEmojiMap = {
            public_bathroom: '🚽',
            restroom: '🚽',
            toilet: '🚽',
            train_station: '🚉',
            subway_station: '🚉',
            transit_station: '🚉',
            light_rail_station: '🚉',
            railway_station: '🚉',
            bus_station: '🚌',
            bus_stop: '🚌',
            airport: '✈️',
            restaurant: '🍽️',
            fast_food_restaurant: '🍽️',
            meal_takeaway: '🍽️',
            meal_delivery: '🍽️',
            food_court: '🍽️',
            cafe: '☕',
            coffee_shop: '☕',
            bakery: '☕',
            bar: '🍺',
            pub: '🍺',
            night_club: '🍺',
            park: '🌳',
            campground: '🌳',
            garden: '🌳',
            national_park: '🌳',
            supermarket: '🛒',
            grocery_store: '🛒',
            shopping_mall: '🛒',
            department_store: '🛒',
            convenience_store: '🛒',
            store: '🛒',
            gas_station: '⛽',
            electric_vehicle_charging_station: '⛽',
            lodging: '🏨',
            hotel: '🏨',
            motel: '🏨',
            hospital: '🏥',
            doctor: '🏥',
            pharmacy: '🏥',
            parking: '🅿️',
            museum: '🏛️',
            art_gallery: '🏛️',
            library: '🏛️',
            tourist_attraction: '🏛️',
            place_of_worship: '🏛️',
            church: '🏛️',
            city_hall: '🏛️',
            town_hall: '🏛️',
            stadium: '🏟️',
            sports_complex: '🏟️',
            gym: '🏟️',
            playground: '🎡',
            amusement_park: '🎡'
        };

        for (const [key, emoji] of Object.entries(typeEmojiMap)) {
            if (types.includes(key)) {
                return emoji;
            }
        }
        return '';
    }

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

            const placeEmoji = data.place?.emoji || getPlaceEmoji(data.place?.types) || (data.place?.is_public_bathroom ? '🚽' : '');
            const placePrefix = placeEmoji ? `${placeEmoji} ` : '';
            document.getElementById('ai-modal-place-name').textContent = `${placePrefix}${data.place.name || data.place.place_id}`;
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
                const dist = data.place.distance_m;
                const isFar = dist > 100;
                distBadge.textContent = isFar ? `⚠️ ~${dist}m away (>100m!)` : `~${dist}m away`;
                distBadge.className = isFar ? 'badge badge-distance-far' : 'badge badge-distance-near';
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
                    const chipEmoji = getPlaceEmoji([t]);
                    if (chipEmoji) {
                        tag.style.background = (t === 'public_bathroom' || t === 'restroom' || t === 'toilet') ? '#dcfce7' : '#e0e7ff';
                        tag.style.color = (t === 'public_bathroom' || t === 'restroom' || t === 'toilet') ? '#166534' : '#3730a3';
                        tag.style.fontWeight = '600';
                        tag.textContent = `${chipEmoji} ${t}`;
                    } else {
                        tag.style.background = '#e2e8f0';
                        tag.style.color = '#334155';
                        tag.textContent = t;
                    }
                    tag.style.fontSize = '0.6875rem';
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
            const placeEmoji = resData.emoji || activeAiMatchData?.place?.emoji || getPlaceEmoji(activeAiMatchData?.place?.types) || (resData.is_public_bathroom ? '🚽' : '');
            const prefix = placeEmoji ? `${placeEmoji} ` : '';

            const currentPlaceElem = document.getElementById(`current-place-name-${toiletId}`);
            if (currentPlaceElem) {
                currentPlaceElem.textContent = `${prefix}${resData.place_name || activeAiMatchData.place.name || placeId}`;
            }

            const dropdown = document.getElementById(`places-dropdown-${toiletId}`);
            if (dropdown) {
                let found = false;
                for (let i = 0; i < dropdown.options.length; i++) {
                    if (dropdown.options[i].value === placeId) {
                        dropdown.options[i].selected = true;
                        if (placeEmoji && !dropdown.options[i].textContent.startsWith(placeEmoji)) {
                            dropdown.options[i].textContent = `${prefix}${resData.place_name || activeAiMatchData.place.name || placeId}`;
                        }
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    const newOpt = document.createElement('option');
                    newOpt.value = placeId;
                    newOpt.selected = true;
                    newOpt.textContent = `Current: ${prefix}${resData.place_name || activeAiMatchData.place.name || placeId}`;
                    dropdown.prepend(newOpt);
                }
            }

            // Update maps link if lat/lon changed
            if (resData.lat !== undefined && resData.lon !== undefined && resData.lat !== null && resData.lon !== null) {
                const mapsLink = document.getElementById(`maps-link-${toiletId}`);
                const mapsContainer = document.getElementById(`maps-container-${toiletId}`);
                if (mapsLink) {
                    mapsLink.href = `https://www.google.com/maps/search/?api=1&query=${resData.lat},${resData.lon}`;
                }
                if (mapsContainer) {
                    mapsContainer.style.display = '';
                }
            }

            // Update public accessible checkbox & label
            if (resData.public_accessible !== undefined) {
                const publicCheckbox = document.getElementById(`public-accessible-checkbox-${toiletId}`);
                const publicLabel = document.getElementById(`public-accessible-label-${toiletId}`);
                if (publicCheckbox) {
                    publicCheckbox.checked = !!resData.public_accessible;
                }
                if (publicLabel) {
                    publicLabel.textContent = resData.public_accessible ? 'Yes' : 'No';
                    publicLabel.style.color = resData.public_accessible ? '#166534' : '#64748b';
                }
            }

            const statusMsg = document.getElementById(`status-msg-${toiletId}`);
            if (statusMsg) {
                statusMsg.style.display = 'block';
                statusMsg.textContent = '✓ AI Place, coordinates & properties applied!';
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

    function startInlineNameEdit(toiletId) {
        const displayContainer = document.getElementById(`name-display-container-${toiletId}`);
        const editContainer = document.getElementById(`name-edit-container-${toiletId}`);
        const input = document.getElementById(`name-input-${toiletId}`);

        if (displayContainer && editContainer && input) {
            displayContainer.style.display = 'none';
            editContainer.style.display = 'block';
            input.focus();
            input.select();
        }
    }

    function cancelInlineNameEdit(toiletId) {
        const displayContainer = document.getElementById(`name-display-container-${toiletId}`);
        const editContainer = document.getElementById(`name-edit-container-${toiletId}`);
        const input = document.getElementById(`name-input-${toiletId}`);

        if (displayContainer && editContainer && input) {
            input.value = input.dataset.originalValue || '';
            editContainer.style.display = 'none';
            displayContainer.style.display = 'flex';
        }
    }

    function handleNameInputKeydown(event, toiletId) {
        if (event.key === 'Enter') {
            event.preventDefault();
            saveInlineName(toiletId);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            cancelInlineNameEdit(toiletId);
        }
    }

    async function saveInlineName(toiletId) {
        const input = document.getElementById(`name-input-${toiletId}`);
        if (!input) return;

        const newName = input.value.trim();
        const originalValue = input.dataset.originalValue || '';

        if (newName === originalValue) {
            cancelInlineNameEdit(toiletId);
            return;
        }

        input.disabled = true;
        const statusMsg = document.getElementById(`status-msg-${toiletId}`);
        if (statusMsg) {
            statusMsg.style.display = 'block';
            statusMsg.textContent = '💾 Saving name...';
            statusMsg.style.color = 'var(--primary)';
        }

        try {
            const response = await fetch(`/admin/toilets/${toiletId}/name`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken()
                },
                body: JSON.stringify({ name: newName })
            });

            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }

            const data = await response.json();
            input.dataset.originalValue = data.name || '';
            input.value = data.name || '';

            const link = document.getElementById(`toilet-name-link-${toiletId}`);
            if (link) {
                link.textContent = data.display_name || 'Unnamed Toilet';
            }

            const displayContainer = document.getElementById(`name-display-container-${toiletId}`);
            const editContainer = document.getElementById(`name-edit-container-${toiletId}`);
            if (displayContainer && editContainer) {
                editContainer.style.display = 'none';
                displayContainer.style.display = 'flex';
            }

            if (statusMsg) {
                statusMsg.textContent = '✓ Name updated!';
                statusMsg.style.color = 'var(--success)';
                setTimeout(() => { statusMsg.style.display = 'none'; }, 2000);
            }
        } catch (err) {
            console.error('Error updating toilet name:', err);
            alert('Failed to save name. Please try again.');
            if (statusMsg) {
                statusMsg.textContent = '❌ Failed to save name.';
                statusMsg.style.color = 'var(--danger)';
            }
        } finally {
            input.disabled = false;
        }
    }

    async function updateToiletStatus(selectElem, toiletId) {
        const newStatus = selectElem.value;
        const originalVal = selectElem.dataset.originalValue || 'active';
        selectElem.disabled = true;

        const statusMsg = document.getElementById(`status-msg-${toiletId}`);
        if (statusMsg) {
            statusMsg.style.display = 'block';
            statusMsg.textContent = '💾 Updating status...';
            statusMsg.style.color = 'var(--primary)';
        }

        try {
            const response = await fetch(`/admin/toilets/${toiletId}/status`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken()
                },
                body: JSON.stringify({ status: newStatus })
            });

            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }

            const data = await response.json();
            selectElem.dataset.originalValue = data.status;

            // Update styling based on new status
            selectElem.className = `form-control status-select-${data.status}`;

            // Visual feedback on row
            const row = document.getElementById(`flagged-row-${toiletId}`);
            if (row) {
                if (data.status === 'deleted') {
                    row.style.opacity = '0.5';
                } else {
                    row.style.opacity = '1.0';
                }
            }

            if (statusMsg) {
                statusMsg.textContent = `✓ Status changed to ${data.status}!`;
                statusMsg.style.color = 'var(--success)';
                setTimeout(() => { statusMsg.style.display = 'none'; }, 2000);
            }
        } catch (err) {
            console.error('Error updating toilet status:', err);
            selectElem.value = originalVal;
            alert('Failed to update status. Please try again.');
            if (statusMsg) {
                statusMsg.textContent = '❌ Failed to change status.';
                statusMsg.style.color = 'var(--danger)';
            }
        } finally {
            selectElem.disabled = false;
        }
    }

    async function updateToiletPublicAccessible(checkboxElem, toiletId) {
        const isChecked = checkboxElem.checked;
        checkboxElem.disabled = true;

        const labelElem = document.getElementById(`public-accessible-label-${toiletId}`);
        const statusMsg = document.getElementById(`status-msg-${toiletId}`);
        if (statusMsg) {
            statusMsg.style.display = 'block';
            statusMsg.textContent = '💾 Saving public accessibility...';
            statusMsg.style.color = 'var(--primary)';
        }

        try {
            const response = await fetch(`/admin/toilets/${toiletId}/public-accessible`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken()
                },
                body: JSON.stringify({ public_accessible: isChecked })
            });

            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }

            const data = await response.json();
            if (labelElem) {
                labelElem.textContent = data.public_accessible ? 'Yes' : 'No';
                labelElem.style.color = data.public_accessible ? '#166534' : '#64748b';
            }

            if (statusMsg) {
                statusMsg.textContent = `✓ Public accessibility: ${data.public_accessible ? 'Yes' : 'No'}!`;
                statusMsg.style.color = 'var(--success)';
                setTimeout(() => { statusMsg.style.display = 'none'; }, 2000);
            }
        } catch (err) {
            console.error('Error updating public accessibility:', err);
            checkboxElem.checked = !isChecked;
            if (labelElem) {
                labelElem.textContent = !isChecked ? 'Yes' : 'No';
                labelElem.style.color = !isChecked ? '#166534' : '#64748b';
            }
            alert('Failed to update public accessibility. Please try again.');
            if (statusMsg) {
                statusMsg.textContent = '❌ Failed to change public accessibility.';
                statusMsg.style.color = 'var(--danger)';
            }
        } finally {
            checkboxElem.disabled = false;
        }
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
                    const emoji = p.emoji || getPlaceEmoji(p.types) || (p.is_public_bathroom ? '🚽' : '');
                    const prefix = emoji ? `${emoji} ` : '';
                    opt.textContent = `${prefix}${p.name}${distStr}${addrStr}`;
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
                const selectedOpt = selectElem.options[selectElem.selectedIndex];
                let prefix = data.emoji ? `${data.emoji} ` : '';
                if (!prefix && selectedOpt) {
                    const match = selectedOpt.textContent.match(/^(\p{Extended_Pictographic}|\p{Emoji_Presentation}|\uD83C[\uDF00-\uDFFF]|\uD83D[\uDC00-\uDE4F]|\uD83E[\uDD00-\uDDFF]|🅿️|☕|✈️|🍽️|🏛️|🏟️|🚉|🚆|🚌|⛽|🏨|🏥|🛒|🌳|🍺|🚽)\s*/u);
                    if (match) {
                        prefix = match[0];
                    }
                }
                const cleanName = data.place_name ? data.place_name.replace(/^(\p{Extended_Pictographic}|\p{Emoji_Presentation}|\uD83C[\uDF00-\uDFFF]|\uD83D[\uDC00-\uDE4F]|\uD83E[\uDD00-\uDDFF]|🅿️|☕|✈️|🍽️|🏛️|🏟️|🚉|🚆|🚌|⛽|🏨|🏥|🛒|🌳|🍺|🚽)\s*/u, '') : '';
                currentPlaceElem.textContent = data.place_name ? `${prefix}${cleanName}` : '-';
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

    let undoToastTimeout = null;
    let undoToastInterval = null;
    let activeUndoToilet = null;

    function showUndoToast(toiletId, toiletName, rowElem, btnElem) {
        if (undoToastTimeout) clearTimeout(undoToastTimeout);
        if (undoToastInterval) clearInterval(undoToastInterval);

        activeUndoToilet = {
            id: toiletId,
            name: toiletName,
            rowElem: rowElem,
            btnElem: btnElem
        };

        const toast = document.getElementById('undo-toast');
        const toastText = document.getElementById('undo-toast-text');
        const undoBtn = document.getElementById('undo-toast-btn');

        if (!toast || !toastText) return;

        const displayName = toiletName ? `"${toiletName}" (#${toiletId})` : `#${toiletId}`;
        toastText.textContent = `Toilet ${displayName} unflagged`;
        if (undoBtn) {
            undoBtn.style.display = 'inline-block';
            undoBtn.disabled = false;
            undoBtn.innerHTML = `↩ Undo (<span id="undo-toast-timer">5</span>s)`;
        }

        let secondsLeft = 5;
        toast.style.display = 'flex';
        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(-50%) translateY(0)';
        });

        undoToastInterval = setInterval(() => {
            secondsLeft -= 1;
            const currentTimerSpan = document.getElementById('undo-toast-timer');
            if (currentTimerSpan && secondsLeft > 0) {
                currentTimerSpan.textContent = secondsLeft.toString();
            } else {
                clearInterval(undoToastInterval);
            }
        }, 1000);

        undoToastTimeout = setTimeout(() => {
            dismissUndoToast();
        }, 5000);
    }

    function dismissUndoToast() {
        if (undoToastTimeout) clearTimeout(undoToastTimeout);
        if (undoToastInterval) clearInterval(undoToastInterval);

        const toast = document.getElementById('undo-toast');
        if (toast) {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(-50%) translateY(20px)';
            setTimeout(() => {
                toast.style.display = 'none';
            }, 250);
        }
        activeUndoToilet = null;
    }

    async function executeUndoUnflag() {
        if (!activeUndoToilet) return;

        const { id, name, rowElem, btnElem } = activeUndoToilet;
        const undoBtn = document.getElementById('undo-toast-btn');
        const toastText = document.getElementById('undo-toast-text');

        if (undoBtn) {
            undoBtn.disabled = true;
            undoBtn.textContent = '⏳ Restoring...';
        }

        try {
            const response = await fetch(`/admin/toilets/${id}/toggle-flag`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken()
                },
                body: JSON.stringify({ flagged: true })
            });

            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }

            // Restore row in table
            if (rowElem) {
                rowElem.style.display = '';
                rowElem.style.transition = 'background-color 0.4s ease';
                rowElem.style.backgroundColor = '#dcfce7';
                setTimeout(() => {
                    rowElem.style.backgroundColor = '';
                }, 1500);
            }

            if (btnElem) {
                btnElem.disabled = false;
                btnElem.textContent = '✓ Unflag';
            }

            // Update badge count (+1)
            const badge = document.getElementById('flagged-count-badge');
            if (badge) {
                const count = parseInt(badge.textContent, 10);
                if (!isNaN(count)) {
                    badge.textContent = (count + 1).toString();
                }
            }

            // Hide empty row if visible
            const emptyRow = document.getElementById('flagged-empty-row');
            if (emptyRow) {
                emptyRow.remove();
            }

            if (toastText) {
                const displayName = name ? `"${name}" (#${id})` : `#${id}`;
                toastText.textContent = `✓ Toilet ${displayName} restored!`;
            }
            if (undoBtn) {
                undoBtn.style.display = 'none';
            }

            if (undoToastTimeout) clearTimeout(undoToastTimeout);
            if (undoToastInterval) clearInterval(undoToastInterval);

            setTimeout(() => {
                dismissUndoToast();
            }, 1800);
        } catch (err) {
            console.error('Error undoing unflag:', err);
            alert('Failed to undo unflagging. Please try again.');
            dismissUndoToast();
        }
    }

    async function unflagToilet(btnElem, toiletId) {
        btnElem.disabled = true;
        btnElem.textContent = '⏳...';

        const row = document.getElementById(`flagged-row-${toiletId}`);
        const nameLink = document.getElementById(`toilet-name-link-${toiletId}`);
        const toiletName = nameLink ? nameLink.textContent.trim() : '';

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

            if (row) {
                row.style.transition = 'all 0.25s ease';
                row.style.opacity = '0';
                setTimeout(() => {
                    row.style.display = 'none';
                    row.style.opacity = '1';

                    // Update badge count
                    const badge = document.getElementById('flagged-count-badge');
                    if (badge) {
                        const count = parseInt(badge.textContent, 10);
                        if (!isNaN(count) && count > 0) {
                            badge.textContent = (count - 1).toString();
                        }
                    }

                    // If no visible rows left, show empty row
                    const tbody = document.getElementById('flagged-toilets-tbody');
                    if (tbody) {
                        const visibleRows = Array.from(tbody.querySelectorAll('tr[id^="flagged-row-"]')).filter(r => r.style.display !== 'none');
                        if (visibleRows.length === 0 && !document.getElementById('flagged-empty-row')) {
                            const emptyTr = document.createElement('tr');
                            emptyTr.id = 'flagged-empty-row';
                            emptyTr.innerHTML = `
                                <td colspan="9" style="text-align: center; padding: 2.5rem 1rem; color: var(--gray-500);">
                                    <div style="font-size: 1.75rem; margin-bottom: 0.25rem;">🎉</div>
                                    <div style="font-weight: 600; font-size: 0.9375rem; color: var(--gray-700);">No toilets currently flagged for review</div>
                                    <div style="font-size: 0.8125rem; color: var(--gray-400); margin-top: 0.25rem;">Flag toilets in the edit view or when importing data to review and correct Google Places here.</div>
                                </td>
                            `;
                            tbody.appendChild(emptyTr);
                        }
                    }

                    // Trigger bottom Undo Toast
                    showUndoToast(toiletId, toiletName, row, btnElem);
                }, 250);
            }
        } catch (err) {
            console.error('Error unflagging toilet:', err);
            btnElem.disabled = false;
            btnElem.textContent = '✓ Unflag';
            alert('Failed to unflag toilet. Please try again.');
        }
    }

    // ==========================================
    // SATELLITE & POI MAP & STREET VIEW LOGIC
    // ==========================================
    let toiletMap = null;
    let mapTargetMarker = null;
    let mapPoiMarkers = [];
    let mapToiletMarkers = [];
    let currentMapContext = null;
    let mapLayerControl = null;
    let mapMoveTimeout = null;
    let isMapFetching = false;
    let mapDisplayMode = 'map'; // 'map', 'split', 'streetview'
    let streetViewCoords = { lat: null, lon: null, label: '' };

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }

    function setMapDisplayMode(mode) {
        mapDisplayMode = mode;

        const mapBtn = document.getElementById('map-mode-map-btn');
        const splitBtn = document.getElementById('map-mode-split-btn');
        const svBtn = document.getElementById('map-mode-streetview-btn');

        const mapEl = document.getElementById('toilet-leaflet-map');
        const svWrapper = document.getElementById('toilet-streetview-wrapper');

        const activeStyle = 'background: rgba(255, 255, 255, 0.25); color: #ffffff; font-weight: 700; box-shadow: 0 1px 2px rgba(0,0,0,0.2);';
        const inactiveStyle = 'background: transparent; color: #e0e7ff; font-weight: 500; box-shadow: none;';

        if (mapBtn) mapBtn.style.cssText = `font-size: 0.75rem; padding: 0.2rem 0.5rem; border: none; border-radius: var(--radius-xs); cursor: pointer; transition: all 0.15s ease; ${mode === 'map' ? activeStyle : inactiveStyle}`;
        if (splitBtn) splitBtn.style.cssText = `font-size: 0.75rem; padding: 0.2rem 0.5rem; border: none; border-radius: var(--radius-xs); cursor: pointer; transition: all 0.15s ease; ${mode === 'split' ? activeStyle : inactiveStyle}`;
        if (svBtn) svBtn.style.cssText = `font-size: 0.75rem; padding: 0.2rem 0.5rem; border: none; border-radius: var(--radius-xs); cursor: pointer; transition: all 0.15s ease; ${mode === 'streetview' ? activeStyle : inactiveStyle}`;

        if (mode === 'map') {
            if (mapEl) {
                mapEl.style.display = 'block';
                mapEl.style.flex = '1';
            }
            if (svWrapper) {
                svWrapper.style.display = 'none';
            }
        } else if (mode === 'split') {
            if (mapEl) {
                mapEl.style.display = 'block';
                mapEl.style.flex = '1';
            }
            if (svWrapper) {
                svWrapper.style.display = 'flex';
                svWrapper.style.flex = '1';
            }
        } else if (mode === 'streetview') {
            if (mapEl) {
                mapEl.style.display = 'none';
            }
            if (svWrapper) {
                svWrapper.style.display = 'flex';
                svWrapper.style.flex = '1';
            }
        }

        if (toiletMap && (mode === 'map' || mode === 'split')) {
            setTimeout(() => {
                toiletMap.invalidateSize();
            }, 100);
        }

        // If switching to street view or split, make sure Street View has coordinates loaded
        if (mode === 'split' || mode === 'streetview') {
            if (!streetViewCoords.lat && currentMapContext && currentMapContext.toilet) {
                loadStreetView(
                    currentMapContext.toilet.lat,
                    currentMapContext.toilet.lon,
                    `Target: Toilet #${currentMapContext.toilet.id}`
                );
            }
        }
    }

    function loadStreetView(lat, lon, label) {
        if (lat === null || lon === null || isNaN(lat) || isNaN(lon)) return;

        streetViewCoords = { lat, lon, label: label || 'Street View' };

        const iframe = document.getElementById('streetview-iframe');
        const locLabel = document.getElementById('streetview-location-label');
        const coordsLabel = document.getElementById('streetview-coords-label');
        const extLink = document.getElementById('streetview-external-link');

        if (locLabel) locLabel.textContent = label || 'Selected Location';
        if (coordsLabel) coordsLabel.textContent = `(${Number(lat).toFixed(5)}, ${Number(lon).toFixed(5)})`;
        if (extLink) extLink.href = `https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${lat},${lon}`;

        if (iframe) {
            iframe.src = `https://maps.google.com/maps?layer=c&cbll=${lat},${lon}&cbp=12,0,,0,0&output=svembed`;
        }
    }

    function syncStreetViewTarget() {
        if (currentMapContext && currentMapContext.toilet) {
            loadStreetView(
                currentMapContext.toilet.lat,
                currentMapContext.toilet.lon,
                `Target: Toilet #${currentMapContext.toilet.id}`
            );
        }
    }

    function openStreetViewAt(lat, lon, label) {
        if (mapDisplayMode === 'map') {
            setMapDisplayMode('split');
        }
        loadStreetView(lat, lon, label);
    }

    async function openToiletMapModal(toiletId, lat, lon, name) {
        const overlay = document.getElementById('toilet-map-modal-overlay');
        const loading = document.getElementById('map-loading-overlay');
        const badge = document.getElementById('map-modal-target-badge');
        const titleName = document.getElementById('map-modal-target-name');
        const sub = document.getElementById('map-modal-target-sub');
        const coords = document.getElementById('map-modal-coords');

        badge.textContent = `Toilet #${toiletId}`;
        titleName.textContent = name ? `— ${name}` : '';
        sub.textContent = `Target coordinates: ${Number(lat).toFixed(5)}, ${Number(lon).toFixed(5)}`;
        coords.textContent = `Lat: ${Number(lat).toFixed(5)}, Lon: ${Number(lon).toFixed(5)}`;

        // Default mode is Map
        setMapDisplayMode('map');
        streetViewCoords = { lat, lon, label: `Target: Toilet #${toiletId}` };

        // Preload Street View iframe in background
        const iframe = document.getElementById('streetview-iframe');
        if (iframe) {
            iframe.src = `https://maps.google.com/maps?layer=c&cbll=${lat},${lon}&cbp=12,0,,0,0&output=svembed`;
        }
        const locLabel = document.getElementById('streetview-location-label');
        const coordsLabel = document.getElementById('streetview-coords-label');
        const extLink = document.getElementById('streetview-external-link');
        if (locLabel) locLabel.textContent = `Target: Toilet #${toiletId}`;
        if (coordsLabel) coordsLabel.textContent = `(${Number(lat).toFixed(5)}, ${Number(lon).toFixed(5)})`;
        if (extLink) extLink.href = `https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${lat},${lon}`;

        overlay.style.display = 'flex';
        loading.style.display = 'flex';

        // Initialize Leaflet map if needed
        if (!toiletMap) {
            const googleHybrid = L.tileLayer('https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
                maxZoom: 20,
                subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
                attribution: '&copy; Google Maps Satellite'
            });

            const esriSatellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                maxZoom: 19,
                attribution: '&copy; Esri World Imagery'
            });

            const osmStreets = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            });

            toiletMap = L.map('toilet-leaflet-map', {
                center: [lat, lon],
                zoom: 18,
                layers: [googleHybrid]
            });

            const baseLayers = {
                '🛰️ Google Satellite (Hybrid)': googleHybrid,
                '🛰️ Esri Satellite (Clean)': esriSatellite,
                '🗺️ OpenStreetMap (Streets)': osmStreets
            };

            mapLayerControl = L.control.layers(baseLayers, null, { position: 'topright' }).addTo(toiletMap);

            toiletMap.on('moveend', () => {
                if (!currentMapContext || !currentMapContext.toilet) return;
                const mapOverlay = document.getElementById('toilet-map-modal-overlay');
                if (!mapOverlay || mapOverlay.style.display === 'none') return;

                if (mapMoveTimeout) clearTimeout(mapMoveTimeout);
                mapMoveTimeout = setTimeout(() => {
                    fetchViewportPois();
                }, 450);
            });

            toiletMap.on('contextmenu', (e) => {
                const clickLat = e.latlng.lat;
                const clickLng = e.latlng.lng;
                const targetToiletId = currentMapContext && currentMapContext.toilet ? currentMapContext.toilet.id : null;

                const popupContent = `
                    <div style="padding: 0.25rem; font-family: inherit; min-width: 170px;">
                        <div style="font-weight: 700; font-size: 0.8125rem; margin-bottom: 0.25rem; color: #1e293b;">📍 Selected Point</div>
                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.5rem; font-family: monospace;">
                            ${clickLat.toFixed(5)}, ${clickLng.toFixed(5)}
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                            ${targetToiletId ? `
                            <button type="button" class="btn btn-primary btn-sm" onclick="setTargetToiletCoordinates(${targetToiletId}, ${clickLat}, ${clickLng})" style="font-size: 0.75rem; padding: 0.3rem 0.6rem; text-align: left; display: flex; align-items: center; gap: 0.35rem;">
                                <span>🎯</span> <strong>Set this coordinates</strong>
                            </button>
                            ` : ''}
                            <button type="button" class="btn btn-secondary btn-sm" onclick="toiletMap.closePopup(); openStreetViewAt(${clickLat}, ${clickLng}, 'Map Point (${clickLat.toFixed(5)}, ${clickLng.toFixed(5)})')" style="font-size: 0.75rem; padding: 0.3rem 0.6rem; text-align: left; display: flex; align-items: center; gap: 0.35rem;">
                                <span>🚶</span> <span>Street View</span>
                            </button>
                        </div>
                    </div>
                `;

                L.popup()
                    .setLatLng(e.latlng)
                    .setContent(popupContent)
                    .openOn(toiletMap);
            });
        } else {
            toiletMap.setView([lat, lon], 18);
        }

        setTimeout(() => {
            if (toiletMap) toiletMap.invalidateSize();
        }, 150);

        clearMapMarkers();

        try {
            const response = await fetch(`/admin/toilets/${toiletId}/map-context`, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken()
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }

            const data = await response.json();
            currentMapContext = data;

            renderMapContext(data);
        } catch (err) {
            console.error('Failed to load map context:', err);
            alert('Could not load map context: ' + err.message);
        } finally {
            loading.style.display = 'none';
            setTimeout(() => { if (toiletMap) toiletMap.invalidateSize(); }, 200);
        }
    }

    async function fetchViewportPois() {
        if (!toiletMap || !currentMapContext || !currentMapContext.toilet || isMapFetching) return;

        const toiletId = currentMapContext.toilet.id;
        const bounds = toiletMap.getBounds();
        const south = bounds.getSouth();
        const west = bounds.getWest();
        const north = bounds.getNorth();
        const east = bounds.getEast();

        const indicator = document.getElementById('map-pois-loading-badge');
        if (indicator) indicator.style.display = 'inline-flex';
        isMapFetching = true;

        try {
            const url = `/admin/toilets/${toiletId}/map-context?south=${south}&west=${west}&north=${north}&east=${east}`;
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken()
                }
            });

            if (!response.ok) return;

            const data = await response.json();
            mergeMapContextData(data);
        } catch (err) {
            console.warn('Failed to load viewport POIs:', err);
        } finally {
            isMapFetching = false;
            if (indicator) indicator.style.display = 'none';
        }
    }

    function mergeMapContextData(newData) {
        if (!currentMapContext || !newData) return;

        const existingPois = currentMapContext.google_places || [];
        const newPois = newData.google_places || [];
        const seenPois = new Set(existingPois.map(p => p.place_id));

        newPois.forEach(p => {
            if (!seenPois.has(p.place_id)) {
                existingPois.push(p);
                seenPois.add(p.place_id);
            }
        });
        currentMapContext.google_places = existingPois;

        const existingToilets = currentMapContext.nearby_toilets || [];
        const newToilets = newData.nearby_toilets || [];
        const seenToilets = new Set(existingToilets.map(t => t.id));

        newToilets.forEach(t => {
            if (!seenToilets.has(t.id)) {
                existingToilets.push(t);
                seenToilets.add(t.id);
            }
        });
        currentMapContext.nearby_toilets = existingToilets;

        renderMapContext(currentMapContext);
    }

    function clearMapMarkers() {
        if (mapTargetMarker && toiletMap) {
            toiletMap.removeLayer(mapTargetMarker);
            mapTargetMarker = null;
        }
        mapPoiMarkers.forEach(m => {
            if (toiletMap) toiletMap.removeLayer(m);
        });
        mapPoiMarkers = [];

        mapToiletMarkers.forEach(m => {
            if (toiletMap) toiletMap.removeLayer(m);
        });
        mapToiletMarkers = [];
    }

    function renderMapContext(data) {
        if (!toiletMap || !data || !data.toilet) return;

        const targetLat = data.toilet.lat;
        const targetLon = data.toilet.lon;

        const showTarget = document.getElementById('map-filter-target')?.checked ?? true;
        const showPois = document.getElementById('map-filter-pois')?.checked ?? true;
        const showToilets = document.getElementById('map-filter-toilets')?.checked ?? true;

        // 1. Target Toilet Marker
        if (!mapTargetMarker) {
            const targetCustomIcon = L.divIcon({
                className: 'map-target-icon-wrapper',
                html: `
                    <div style="position: relative; width: 32px; height: 32px;">
                        <div class="map-pulse-ring"></div>
                        <div class="map-pin-target-icon">🚽</div>
                    </div>
                `,
                iconSize: [32, 32],
                iconAnchor: [16, 16],
                popupAnchor: [0, -18]
            });

            mapTargetMarker = L.marker([targetLat, targetLon], { icon: targetCustomIcon, zIndexOffset: 1000 })
                .bindPopup(`
                    <div style="padding: 0.25rem;">
                        <div style="font-weight: 700; font-size: 0.9375rem; color: #dc2626;">🚩 Target: Toilet #${data.toilet.id}</div>
                        <div style="font-weight: 600; font-size: 0.8125rem; margin-top: 0.125rem;">${escapeHtml(data.toilet.name)}</div>
                        ${data.toilet.owner ? `<div style="font-size: 0.75rem; color: var(--text-muted);">${escapeHtml(data.toilet.owner)}</div>` : ''}
                        ${data.toilet.address ? `<div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">📍 ${escapeHtml(data.toilet.address)}</div>` : ''}
                        <div style="font-size: 0.75rem; margin-top: 0.35rem; display: flex; gap: 0.25rem; align-items: center; flex-wrap: wrap;">
                            <span class="badge badge-${data.toilet.status}">${data.toilet.status}</span>
                            ${data.toilet.place_name ? `<span class="badge" style="background: #e0e7ff; color: #4338ca;">⭐ ${escapeHtml(data.toilet.place_name)}</span>` : ''}
                        </div>
                        <div style="margin-top: 0.6rem; display: flex; gap: 0.35rem; flex-wrap: wrap;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="openStreetViewAt(${targetLat}, ${targetLon}, 'Target: Toilet #${data.toilet.id}')" style="font-size: 0.75rem; padding: 0.2rem 0.5rem;">
                                🚶 Street View
                            </button>
                            <a href="/admin/toilets/${data.toilet.id}" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 0.2rem 0.5rem;" target="_blank">Open Details ↗</a>
                        </div>
                    </div>
                `);

            if (showTarget) {
                mapTargetMarker.addTo(toiletMap);
            }
        }

        // 2. Google Places POIs
        const pois = data.google_places || [];
        document.getElementById('map-pois-count').textContent = pois.length;

        pois.forEach((p) => {
            if (p.lat === null || p.lon === null) return;
            if (mapPoiMarkers.some(m => m._poiData && m._poiData.place_id === p.place_id)) {
                return;
            }

            const emoji = p.emoji || (p.is_public_bathroom ? '🚽' : '⭐');
            const isCurrent = p.is_current || p.place_id === data.toilet.place_id;
            const isFar = p.distance_m !== null && p.distance_m > 100;
            const distText = p.distance_m !== null ? `~${Math.round(p.distance_m)}m` : '';

            const poiCustomIcon = L.divIcon({
                className: 'map-poi-wrapper',
                html: `
                    <div class="map-pin-poi-icon ${p.is_public_bathroom ? 'is-bathroom' : ''}" style="${isCurrent ? 'border-color: #f59e0b; box-shadow: 0 0 0 2px #f59e0b;' : ''}">
                        <span>${emoji}</span>
                        <span style="max-width: 90px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(p.name)}</span>
                        ${distText ? `<span style="font-size: 0.625rem; opacity: 0.8; margin-left: 2px;">${distText}</span>` : ''}
                    </div>
                `,
                iconSize: [null, 24],
                iconAnchor: [12, 12],
                popupAnchor: [0, -14]
            });

            const marker = L.marker([p.lat, p.lon], { icon: poiCustomIcon, zIndexOffset: isCurrent ? 900 : 500 })
                .bindPopup(`
                    <div style="padding: 0.25rem; min-width: 220px;">
                        <div style="display: flex; align-items: center; gap: 0.35rem; font-weight: 700; font-size: 0.875rem; color: #4338ca;">
                            <span>${emoji}</span>
                            <span>${escapeHtml(p.name)}</span>
                        </div>
                        ${p.address ? `<div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">${escapeHtml(p.address)}</div>` : ''}
                        <div style="display: flex; gap: 0.25rem; align-items: center; margin-top: 0.35rem; flex-wrap: wrap;">
                            ${distText ? `<span class="badge ${isFar ? 'badge-distance-far' : 'badge-distance-near'}">${distText} away</span>` : ''}
                            ${isCurrent ? `<span class="badge badge-active">Current Assigned</span>` : ''}
                        </div>
                        <div style="margin-top: 0.6rem; display: flex; gap: 0.35rem; flex-wrap: wrap;">
                            <button type="button" class="btn btn-primary btn-sm" onclick="promptAssignPlaceFromMap(${data.toilet.id}, '${escapeHtml(p.place_id)}', '${escapeHtml(p.name)}', ${p.lat !== null ? p.lat : 'null'}, ${p.lon !== null ? p.lon : 'null'}, ${p.distance_m !== null ? p.distance_m : 'null'}, ${p.is_public_bathroom ? 'true' : 'false'})" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">
                                ✓ Assign this Place
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="openStreetViewAt(${p.lat}, ${p.lon}, '${escapeHtml(p.name)}')" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">
                                🚶 Street View
                            </button>
                            <a href="https://www.google.com/maps/place/?q=place_id:${encodeURIComponent(p.place_id)}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">
                                Maps ↗
                            </a>
                        </div>
                    </div>
                `);

            if (showPois) {
                marker.addTo(toiletMap);
            }

            marker._poiData = p;
            mapPoiMarkers.push(marker);
        });

        // 3. Nearby Database Toilets
        const toilets = data.nearby_toilets || [];
        document.getElementById('map-toilets-count').textContent = toilets.length;

        toilets.forEach((t) => {
            if (t.lat === null || t.lon === null) return;
            if (mapToiletMarkers.some(m => m._toiletData && m._toiletData.id === t.id)) {
                return;
            }

            const toiletIcon = L.divIcon({
                className: 'map-db-toilet-wrapper',
                html: `
                    <div class="map-pin-db-icon status-${t.status}" title="Toilet #${t.id}: ${escapeHtml(t.name)}">
                        🚽
                    </div>
                `,
                iconSize: [26, 26],
                iconAnchor: [13, 13],
                popupAnchor: [0, -14]
            });

            const marker = L.marker([t.lat, t.lon], { icon: toiletIcon, zIndexOffset: 200 })
                .bindPopup(`
                    <div style="padding: 0.25rem; min-width: 180px;">
                        <div style="font-weight: 700; font-size: 0.875rem; color: #2563eb;">Toilet #${t.id}</div>
                        <div style="font-weight: 600; font-size: 0.8125rem; margin-top: 0.125rem;">${escapeHtml(t.name)}</div>
                        ${t.owner ? `<div style="font-size: 0.75rem; color: var(--text-muted);">${escapeHtml(t.owner)}</div>` : ''}
                        <div style="font-size: 0.75rem; margin-top: 0.35rem; display: flex; gap: 0.25rem; align-items: center; flex-wrap: wrap;">
                            <span class="badge badge-${t.status}">${t.status}</span>
                            <span class="badge" style="background: var(--bg-subtle); color: var(--text-muted);">~${t.distance_m}m away</span>
                        </div>
                        <div style="margin-top: 0.5rem; display: flex; gap: 0.35rem; flex-wrap: wrap;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="openStreetViewAt(${t.lat}, ${t.lon}, 'Toilet #${t.id}: ${escapeHtml(t.name)}')" style="font-size: 0.75rem; padding: 0.2rem 0.5rem;">
                                🚶 Street View
                            </button>
                            <a href="/admin/toilets/${t.id}" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 0.2rem 0.5rem;" target="_blank">
                                Open Toilet #${t.id} ↗
                            </a>
                        </div>
                    </div>
                `);

            if (showToilets) {
                marker.addTo(toiletMap);
            }

            marker._toiletData = t;
            mapToiletMarkers.push(marker);
        });

        // 4. Render Sidebar Items
        renderMapSidebar(data);
    }

    function renderMapSidebar(data) {
        const sidebarContainer = document.getElementById('map-sidebar-items');
        const sidebarTotal = document.getElementById('map-sidebar-total');
        sidebarContainer.innerHTML = '';

        const pois = [...(data.google_places || [])];
        const toilets = [...(data.nearby_toilets || [])];

        pois.sort((a, b) => (a.distance_m ?? 999999) - (b.distance_m ?? 999999));
        toilets.sort((a, b) => (a.distance_m ?? 999999) - (b.distance_m ?? 999999));

        sidebarTotal.textContent = `${pois.length} POIs / ${toilets.length} WCs`;

        // Section: Google POIs
        if (pois.length > 0) {
            const sectionTitle = document.createElement('div');
            sectionTitle.style.cssText = 'font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-top: 0.25rem; margin-bottom: 0.25rem; padding-left: 0.25rem;';
            sectionTitle.textContent = `⭐ Google Places POIs (${pois.length})`;
            sidebarContainer.appendChild(sectionTitle);

            pois.forEach(p => {
                const isCurrent = p.is_current || p.place_id === data.toilet.place_id;
                const emoji = p.emoji || (p.is_public_bathroom ? '🚽' : '⭐');
                const isFar = p.distance_m !== null && p.distance_m > 100;
                const distText = p.distance_m !== null ? `~${Math.round(p.distance_m)}m` : '';

                const card = document.createElement('div');
                card.style.cssText = `
                    padding: 0.5rem 0.625rem;
                    border: 1px solid ${isCurrent ? '#f59e0b' : 'var(--border-main)'};
                    border-radius: var(--radius-md);
                    background: ${isCurrent ? 'var(--bg-subtle)' : 'var(--bg-card)'};
                    display: flex;
                    flex-direction: column;
                    gap: 0.25rem;
                    cursor: pointer;
                    transition: all 0.15s ease;
                `;
                card.onmouseover = () => { card.style.borderColor = 'var(--primary)'; };
                card.onmouseout = () => { card.style.borderColor = isCurrent ? '#f59e0b' : 'var(--border-main)'; };
                card.onclick = () => {
                    if (p.lat !== null && p.lon !== null && toiletMap) {
                        toiletMap.setView([p.lat, p.lon], 19, { animate: true });
                        const found = mapPoiMarkers.find(m => m._poiData && m._poiData.place_id === p.place_id);
                        if (found) found.openPopup();
                    }
                };

                card.innerHTML = `
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.35rem;">
                        <span style="font-weight: 600; font-size: 0.8125rem; color: var(--text-heading); display: flex; align-items: center; gap: 0.35rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <span>${emoji}</span>
                            <span>${escapeHtml(p.name)}</span>
                        </span>
                        ${distText ? `<span class="badge ${isFar ? 'badge-distance-far' : 'badge-distance-near'}" style="font-size: 0.625rem; padding: 0.1rem 0.35rem;">${distText}</span>` : ''}
                    </div>
                    ${p.address ? `<div style="font-size: 0.6875rem; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(p.address)}</div>` : ''}
                    <div style="display: flex; justify-content: flex-end; gap: 0.25rem; margin-top: 0.125rem;">
                        ${p.lat !== null && p.lon !== null ? `
                            <button type="button" class="btn btn-secondary btn-sm" onclick="event.stopPropagation(); openStreetViewAt(${p.lat}, ${p.lon}, '${escapeHtml(p.name)}')" style="font-size: 0.6875rem; padding: 0.125rem 0.375rem; height: 22px;" title="View in Street View">
                                🚶 SV
                            </button>
                        ` : ''}
                        <button type="button" class="btn btn-primary btn-sm" onclick="event.stopPropagation(); promptAssignPlaceFromMap(${data.toilet.id}, '${escapeHtml(p.place_id)}', '${escapeHtml(p.name)}', ${p.lat !== null ? p.lat : 'null'}, ${p.lon !== null ? p.lon : 'null'}, ${p.distance_m !== null ? p.distance_m : 'null'}, ${p.is_public_bathroom ? 'true' : 'false'})" style="font-size: 0.6875rem; padding: 0.125rem 0.375rem; height: 22px;">
                            Assign
                        </button>
                    </div>
                `;

                sidebarContainer.appendChild(card);
            });
        }

        // Section: DB Toilets
        if (toilets.length > 0) {
            const sectionTitle = document.createElement('div');
            sectionTitle.style.cssText = 'font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-top: 0.75rem; margin-bottom: 0.25rem; padding-left: 0.25rem;';
            sectionTitle.textContent = `🚽 Database Toilets (${toilets.length})`;
            sidebarContainer.appendChild(sectionTitle);

            toilets.forEach(t => {
                const card = document.createElement('div');
                card.style.cssText = `
                    padding: 0.5rem 0.625rem;
                    border: 1px solid var(--border-main);
                    border-radius: var(--radius-md);
                    background: var(--bg-card);
                    display: flex;
                    flex-direction: column;
                    gap: 0.25rem;
                    cursor: pointer;
                    transition: all 0.15s ease;
                `;
                card.onmouseover = () => { card.style.borderColor = 'var(--primary)'; };
                card.onmouseout = () => { card.style.borderColor = 'var(--border-main)'; };
                card.onclick = () => {
                    if (t.lat !== null && t.lon !== null && toiletMap) {
                        toiletMap.setView([t.lat, t.lon], 19, { animate: true });
                        const found = mapToiletMarkers.find(m => m._toiletData && m._toiletData.id === t.id);
                        if (found) found.openPopup();
                    }
                };

                card.innerHTML = `
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.35rem;">
                        <span style="font-weight: 600; font-size: 0.8125rem; color: var(--text-heading); display: flex; align-items: center; gap: 0.35rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <span>🚽</span>
                            <span>Toilet #${t.id}: ${escapeHtml(t.name)}</span>
                        </span>
                        <span class="badge badge-${t.status}" style="font-size: 0.625rem; padding: 0.1rem 0.35rem;">${t.status}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.6875rem; color: var(--text-muted);">
                        <span>${t.owner ? escapeHtml(t.owner) : ''}</span>
                        <div style="display: flex; align-items: center; gap: 0.35rem;">
                            <span>~${t.distance_m}m away</span>
                            ${t.lat !== null && t.lon !== null ? `
                                <button type="button" class="btn btn-secondary btn-sm" onclick="event.stopPropagation(); openStreetViewAt(${t.lat}, ${t.lon}, 'Toilet #${t.id}: ${escapeHtml(t.name)}')" style="font-size: 0.6875rem; padding: 0.125rem 0.375rem; height: 20px;" title="View in Street View">
                                    🚶 SV
                                </button>
                            ` : ''}
                        </div>
                    </div>
                `;

                sidebarContainer.appendChild(card);
            });
        }
    }

    function recenterMapTarget() {
        if (currentMapContext && currentMapContext.toilet && toiletMap) {
            toiletMap.setView([currentMapContext.toilet.lat, currentMapContext.toilet.lon], 18, { animate: true });
            if (mapTargetMarker) {
                mapTargetMarker.openPopup();
            }
        }
    }

    function toggleMapFilter(type) {
        const isChecked = document.getElementById(`map-filter-${type}`).checked;
        if (type === 'target') {
            if (mapTargetMarker) {
                if (isChecked) mapTargetMarker.addTo(toiletMap);
                else toiletMap.removeLayer(mapTargetMarker);
            }
        } else if (type === 'pois') {
            mapPoiMarkers.forEach(m => {
                if (isChecked) m.addTo(toiletMap);
                else toiletMap.removeLayer(m);
            });
        } else if (type === 'toilets') {
            mapToiletMarkers.forEach(m => {
                if (isChecked) m.addTo(toiletMap);
                else toiletMap.removeLayer(m);
            });
        }
    }

    function closeToiletMapModal() {
        const overlay = document.getElementById('toilet-map-modal-overlay');
        if (overlay) {
            overlay.style.display = 'none';
        }
        const iframe = document.getElementById('streetview-iframe');
        if (iframe) {
            iframe.src = '';
        }
    }

    function closeMapAssignDialog() {
        const dialog = document.getElementById('map-assign-confirm-dialog');
        if (dialog) {
            dialog.style.display = 'none';
        }
    }

    function promptAssignPlaceFromMap(toiletId, placeId, placeName, placeLat, placeLon, distanceM, isPublicBathroom) {
        if (placeLat === null || placeLon === null || typeof placeLat === 'undefined' || typeof placeLon === 'undefined') {
            // No coordinates available for place, assign directly without coords
            executeAssignPlaceFromMap(toiletId, placeId, placeName, false, null, null);
            return;
        }

        const dialog = document.getElementById('map-assign-confirm-dialog');
        const nameElem = document.getElementById('map-dialog-place-name');
        const toiletCoordsElem = document.getElementById('map-dialog-toilet-coords');
        const placeCoordsElem = document.getElementById('map-dialog-place-coords');
        const distElem = document.getElementById('map-dialog-distance');
        const publicCheckbox = document.getElementById('map-dialog-public-checkbox');
        const applyBtn = document.getElementById('map-dialog-assign-apply-btn');
        const onlyBtn = document.getElementById('map-dialog-assign-only-btn');

        if (!dialog) {
            const applyCoords = confirm(`Assign "${placeName}" to Toilet #${toiletId}?\n\nClick OK to also apply the place's coordinates, or Cancel to only assign the place.`);
            executeAssignPlaceFromMap(toiletId, placeId, placeName, applyCoords, placeLat, placeLon);
            return;
        }

        nameElem.textContent = placeName;
        const currentLat = currentMapContext && currentMapContext.toilet ? currentMapContext.toilet.lat : null;
        const currentLon = currentMapContext && currentMapContext.toilet ? currentMapContext.toilet.lon : null;
        const currentPublic = currentMapContext && currentMapContext.toilet ? !!currentMapContext.toilet.public_accessible : false;

        toiletCoordsElem.textContent = (currentLat !== null && currentLon !== null)
            ? `${Number(currentLat).toFixed(5)}, ${Number(currentLon).toFixed(5)}`
            : 'None';

        placeCoordsElem.textContent = `${Number(placeLat).toFixed(5)}, ${Number(placeLon).toFixed(5)}`;

        if (distanceM !== null && !isNaN(distanceM)) {
            const dist = Math.round(distanceM);
            distElem.textContent = `~${dist}m away`;
            distElem.className = 'badge ' + (dist > 100 ? 'badge-distance-far' : 'badge-distance-near');
        } else {
            distElem.textContent = 'Unknown distance';
            distElem.className = 'badge';
        }

        if (publicCheckbox) {
            publicCheckbox.checked = currentPublic || !!isPublicBathroom;
        }

        applyBtn.disabled = false;
        applyBtn.textContent = '✓ Assign Place & Apply Coordinates';
        applyBtn.onclick = () => {
            applyBtn.disabled = true;
            applyBtn.textContent = 'Saving...';
            executeAssignPlaceFromMap(toiletId, placeId, placeName, true, placeLat, placeLon);
        };

        onlyBtn.disabled = false;
        onlyBtn.textContent = 'Assign Place Only (Keep Coords)';
        onlyBtn.onclick = () => {
            onlyBtn.disabled = true;
            onlyBtn.textContent = 'Saving...';
            executeAssignPlaceFromMap(toiletId, placeId, placeName, false, null, null);
        };

        dialog.style.display = 'flex';
    }

    async function executeAssignPlaceFromMap(toiletId, placeId, placeName, applyCoordinates, placeLat, placeLon) {
        try {
            const publicCheckbox = document.getElementById('map-dialog-public-checkbox');
            const setPublicAccessible = publicCheckbox ? publicCheckbox.checked : false;

            const payload = {
                place_id: placeId,
                apply_coordinates: applyCoordinates,
                set_public_accessible: setPublicAccessible
            };
            if (applyCoordinates && placeLat !== null && placeLon !== null) {
                payload.lat = placeLat;
                payload.lon = placeLon;
            }

            const response = await fetch(`/admin/toilets/${toiletId}/assign-place`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken()
                },
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                throw new Error(`HTTP error ${response.status}`);
            }

            const data = await response.json();
            closeMapAssignDialog();

            // Update dropdown in main table
            const dropdown = document.getElementById(`places-dropdown-${toiletId}`);
            if (dropdown) {
                let found = false;
                for (let opt of dropdown.options) {
                    if (opt.value === placeId) {
                        opt.selected = true;
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    const newOpt = document.createElement('option');
                    newOpt.value = placeId;
                    newOpt.textContent = `Assigned: ${placeName}`;
                    newOpt.selected = true;
                    dropdown.appendChild(newOpt);
                }
            }

            // Update current place name cell in table
            const currentPlaceElem = document.getElementById(`current-place-name-${toiletId}`);
            if (currentPlaceElem) {
                const prefix = data.emoji ? `${data.emoji} ` : '';
                currentPlaceElem.textContent = `${prefix}${placeName}`;
            }

            // Update public accessibility checkbox in main table
            const tablePublicCheckbox = document.getElementById(`public-accessible-checkbox-${toiletId}`);
            const tablePublicLabel = document.getElementById(`public-accessible-label-${toiletId}`);
            if (tablePublicCheckbox) {
                tablePublicCheckbox.checked = !!data.public_accessible;
            }
            if (tablePublicLabel) {
                tablePublicLabel.textContent = data.public_accessible ? 'Yes' : 'No';
                tablePublicLabel.style.color = data.public_accessible ? '#166534' : '#64748b';
            }

            // Update map context for public_accessible
            if (currentMapContext && currentMapContext.toilet && currentMapContext.toilet.id === toiletId) {
                currentMapContext.toilet.public_accessible = !!data.public_accessible;
            }

            // If coordinates were updated, update main table maps links & map context
            if (data.coordinates_updated && data.lat !== null && data.lon !== null) {
                const mapsLink = document.getElementById(`maps-link-${toiletId}`);
                if (mapsLink) {
                    mapsLink.href = `https://www.google.com/maps/search/?api=1&query=${data.lat},${data.lon}`;
                }

                if (currentMapContext && currentMapContext.toilet) {
                    currentMapContext.toilet.lat = data.lat;
                    currentMapContext.toilet.lon = data.lon;

                    const sub = document.getElementById('map-modal-target-sub');
                    const coords = document.getElementById('map-modal-coords');
                    if (sub) sub.textContent = `Target coordinates: ${Number(data.lat).toFixed(5)}, ${Number(data.lon).toFixed(5)}`;
                    if (coords) coords.textContent = `Lat: ${Number(data.lat).toFixed(5)}, Lon: ${Number(data.lon).toFixed(5)}`;

                    if (mapTargetMarker) {
                        mapTargetMarker.setLatLng([data.lat, data.lon]);
                    }
                }
            }

            // Close map modal
            closeToiletMapModal();

            // Status message feedback
            const statusMsg = document.getElementById(`status-msg-${toiletId}`);
            if (statusMsg) {
                statusMsg.style.display = 'block';
                statusMsg.textContent = `✓ Assigned "${placeName}"${data.coordinates_updated ? ' & updated coordinates' : ''}!`;
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
        } catch (err) {
            console.error('Failed to assign place from map:', err);
            alert('Failed to assign place: ' + err.message);
        }
    }

    async function setTargetToiletCoordinates(toiletId, lat, lon) {
        if (!confirm(`Are you sure you want to update the coordinates of Toilet #${toiletId} to (${lat.toFixed(5)}, ${lon.toFixed(5)})?`)) {
            return;
        }

        try {
            if (toiletMap) toiletMap.closePopup();

            const response = await fetch(`/admin/toilets/${toiletId}/coordinates`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken()
                },
                body: JSON.stringify({ lat, lon })
            });

            if (!response.ok) {
                const errData = await response.json().catch(() => ({}));
                throw new Error(errData.message || `HTTP error ${response.status}`);
            }

            const data = await response.json();

            // Update main table maps link
            const mapsLink = document.getElementById(`maps-link-${toiletId}`);
            if (mapsLink) {
                mapsLink.href = `https://www.google.com/maps/search/?api=1&query=${data.lat},${data.lon}`;
            }

            // Update map context & markers
            if (currentMapContext && currentMapContext.toilet && currentMapContext.toilet.id === toiletId) {
                currentMapContext.toilet.lat = data.lat;
                currentMapContext.toilet.lon = data.lon;

                const sub = document.getElementById('map-modal-target-sub');
                const coords = document.getElementById('map-modal-coords');
                if (sub) sub.textContent = `Target coordinates: ${Number(data.lat).toFixed(5)}, ${Number(data.lon).toFixed(5)}`;
                if (coords) coords.textContent = `Lat: ${Number(data.lat).toFixed(5)}, Lon: ${Number(data.lon).toFixed(5)}`;

                if (mapTargetMarker) {
                    mapTargetMarker.setLatLng([data.lat, data.lon]);
                }
            }

            // Status message feedback
            const statusMsg = document.getElementById(`status-msg-${toiletId}`);
            if (statusMsg) {
                statusMsg.style.display = 'block';
                statusMsg.textContent = `✓ Coordinates updated to (${Number(data.lat).toFixed(5)}, ${Number(data.lon).toFixed(5)})!`;
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
        } catch (err) {
            console.error('Failed to update coordinates:', err);
            alert('Failed to update coordinates: ' + err.message);
        }
    }

    // ESC key closes modals (inner dialog first, then outer modals)
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const confirmDialog = document.getElementById('map-assign-confirm-dialog');
            if (confirmDialog && confirmDialog.style.display !== 'none') {
                closeMapAssignDialog();
                return;
            }
            closeAiModal();
            closeToiletMapModal();
        }
    });
</script>
@endpush
