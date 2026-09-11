@extends('admin.layout')

@section('title', 'Toilet #' . $toilet->id . ' - ' . ($toilet->name ?: 'Details'))

@section('content')

    <!-- Top Action Bar -->
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
        <div>
            <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--gray-900);">
                    Toilet #{{ $toilet->id }}
                </h1>
                @if ($toilet->status === 'active')
                    <span class="badge badge-active" style="font-size: 0.8125rem;">Active</span>
                @elseif ($toilet->status === 'hidden')
                    <span class="badge badge-hidden" style="font-size: 0.8125rem;">Hidden</span>
                @else
                    <span class="badge badge-deleted" style="font-size: 0.8125rem;">Deleted</span>
                @endif

                @if ($toilet->is_qualified)
                    <span class="badge badge-qualified" style="font-size: 0.8125rem;">✓ Qualified</span>
                @else
                    <span class="badge badge-unqualified" style="font-size: 0.8125rem;">Unqualified</span>
                @endif

                @if ($toilet->flagged)
                    <span class="badge" style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 0.8125rem;">🚩 Flagged for Review</span>
                @endif

                <span class="badge" style="background: #ede9fe; color: #5b21b6; border: 1px solid #ddd6fe; font-size: 0.8125rem; font-family: monospace;">
                    v{{ $toilet->version ?: 1 }}
                </span>
            </div>
            <div style="font-size: 0.875rem; color: var(--gray-500); margin-top: 0.25rem;">
                {{ $toilet->name ?: 'Unnamed Toilet' }} @if($toilet->owner) • {{ $toilet->owner }} @endif
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
            <a href="{{ route('admin.index') }}" class="btn btn-secondary btn-sm">← Back to List</a>
            @if ($toilet->place_id)
                <a href="https://wc-info.de/Toilets/Place---{{ $toilet->place_id }}/Toilette---{{ $toilet->id }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">
                    ↗ View on Public Site
                </a>
            @endif
            <form action="{{ route('admin.toilets.reschedule-discovery', $toilet->id) }}" method="POST" style="display: inline;">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm" title="Sets last_included to NOW() so discover-places cronjob processes this toilet next">
                    🔄 Reschedule Discovery
                </button>
            </form>
        </div>
    </div>

    <!-- Section 1: Last Update & Discovery Status -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span>🕒 Last Update & Discovery State</span>
            </div>
            <div style="font-size: 0.8125rem; color: var(--gray-500);">
                Audit & timestamps
            </div>
        </div>
        <div class="card-body">
            <div class="grid-3" style="margin-bottom: 1.25rem;">
                <div style="background: var(--gray-50); padding: 0.875rem; border-radius: var(--radius-md); border: 1px solid var(--gray-200);">
                    <div style="font-size: 0.75rem; color: var(--gray-500); font-weight: 600; text-transform: uppercase;">Last Database Update</div>
                    <div style="font-size: 1rem; font-weight: 600; color: var(--gray-900); margin-top: 0.25rem;">
                        {{ $toilet->updated ? $toilet->updated->format('Y-m-d H:i:s') : 'Never' }}
                    </div>
                    @if ($toilet->updated)
                        <div style="font-size: 0.75rem; color: var(--gray-500);">{{ $toilet->updated->diffForHumans() }}</div>
                    @endif
                </div>

                <div style="background: var(--gray-50); padding: 0.875rem; border-radius: var(--radius-md); border: 1px solid var(--gray-200);">
                    <div style="font-size: 0.75rem; color: var(--gray-500); font-weight: 600; text-transform: uppercase;">Last Included (Discovery Queue)</div>
                    <div style="font-size: 1rem; font-weight: 600; color: var(--gray-900); margin-top: 0.25rem;">
                        {{ $toilet->last_included ? $toilet->last_included->format('Y-m-d H:i:s') : 'Never' }}
                    </div>
                    @if ($toilet->last_included)
                        <div style="font-size: 0.75rem; color: var(--gray-500);">{{ $toilet->last_included->diffForHumans() }}</div>
                    @endif
                </div>

                <div style="background: var(--gray-50); padding: 0.875rem; border-radius: var(--radius-md); border: 1px solid var(--gray-200);">
                    <div style="font-size: 0.75rem; color: var(--gray-500); font-weight: 600; text-transform: uppercase;">Last Discovered (Crawled)</div>
                    <div style="font-size: 1rem; font-weight: 600; color: var(--gray-900); margin-top: 0.25rem;">
                        {{ $toilet->last_discovered ? $toilet->last_discovered->format('Y-m-d H:i:s') : 'Never' }}
                    </div>
                    @if ($toilet->last_discovered)
                        <div style="font-size: 0.75rem; color: var(--gray-500);">{{ $toilet->last_discovered->diffForHumans() }}</div>
                    @endif
                </div>
            </div>

            @if (!empty($toilet->user_overridden) && is_array($toilet->user_overridden))
                <div style="margin-bottom: 1.25rem;">
                    <div style="font-size: 0.75rem; font-weight: 600; color: var(--gray-600); text-transform: uppercase; margin-bottom: 0.375rem;">
                        Manually Overridden Fields (Protected from Discovery Overwrite):
                    </div>
                    <div style="display: flex; gap: 0.375rem; flex-wrap: wrap;">
                        @foreach ($toilet->user_overridden as $field)
                            <span class="badge badge-unqualified" style="background: #e2e8f0; color: #1e293b; font-family: monospace;">{{ $field }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Last Diff Details -->
            <div>
                <div style="font-size: 0.75rem; font-weight: 600; color: var(--gray-600); text-transform: uppercase; margin-bottom: 0.375rem;">
                    Last Changes Applied (Diff):
                </div>
                @if (!empty($lastDiff) && is_array($lastDiff))
                    <div class="diff-container">
                        @foreach ($lastDiff as $field => $change)
                            <div class="diff-row">
                                <div class="diff-field">{{ $field }}</div>
                                <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                    <span class="diff-old">
                                        {{ is_array($change['old'] ?? null) ? json_encode($change['old']) : (string)($change['old'] ?? 'null') }}
                                    </span>
                                    <span style="color: var(--gray-400);">→</span>
                                    <span class="diff-new">
                                        {{ is_array($change['new'] ?? null) ? json_encode($change['new']) : (string)($change['new'] ?? 'null') }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div style="font-size: 0.875rem; color: var(--gray-500); font-style: italic; background: var(--gray-50); padding: 0.75rem; border-radius: var(--radius-md); border: 1px solid var(--gray-200);">
                        No change diff recorded for this toilet yet.
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Section 2: Edit Toilet Details Form -->
    <form action="{{ route('admin.toilets.update', $toilet->id) }}" method="POST">
        @csrf

        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <span>✏ Edit Toilet Details & Properties</span>
                </div>
                <button type="submit" class="btn btn-success">
                    💾 Save Changes
                </button>
            </div>
            <div class="card-body">

                <!-- Basic Information -->
                <h3 style="font-size: 1rem; font-weight: 600; color: var(--gray-800); margin-bottom: 1rem; border-bottom: 1px solid var(--gray-200); padding-bottom: 0.5rem;">
                    1. Basic Information & Status
                </h3>

                <div class="grid-3">
                    <div class="form-group">
                        <label class="form-label" for="name">Name</label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            class="form-control"
                            value="{{ old('name', $toilet->name) }}"
                            placeholder="e.g. Öffentliche Toilette am Rathaus"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="owner">Owner / Facility</label>
                        <input
                            type="text"
                            id="owner"
                            name="owner"
                            class="form-control"
                            value="{{ old('owner', $toilet->owner) }}"
                            placeholder="e.g. Stadtverwaltung Berlin"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="status">Status *</label>
                        <select id="status" name="status" class="form-control" required>
                            <option value="active" {{ old('status', $toilet->status) === 'active' ? 'selected' : '' }}>🟢 Active (Visible)</option>
                            <option value="hidden" {{ old('status', $toilet->status) === 'hidden' ? 'selected' : '' }}>⚪ Hidden</option>
                            <option value="deleted" {{ old('status', $toilet->status) === 'deleted' ? 'selected' : '' }}>🔴 Deleted</option>
                        </select>
                    </div>
                </div>

                <div class="grid-3">
                    <div class="form-group">
                        <label class="form-label" for="lat">Latitude (Decimal WGS84)</label>
                        <input
                            type="number"
                            step="any"
                            id="lat"
                            name="lat"
                            class="form-control"
                            value="{{ old('lat', $toilet->lat) }}"
                            placeholder="e.g. 52.520008"
                            oninput="updateGoogleMapsLink()"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="lon">Longitude (Decimal WGS84)</label>
                        <input
                            type="number"
                            step="any"
                            id="lon"
                            name="lon"
                            class="form-control"
                            value="{{ old('lon', $toilet->lon) }}"
                            placeholder="e.g. 13.404954"
                            oninput="updateGoogleMapsLink()"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="coords_paste" style="color: var(--primary); font-weight: 600;">
                            📋 Paste from Google Maps
                        </label>
                        <input
                            type="text"
                            id="coords_paste"
                            class="form-control"
                            placeholder="e.g. 51.761468, 11.436103"
                            style="background-color: var(--primary-light); border-color: #bfdbfe;"
                            oninput="parseCombinedCoordinates(this.value)"
                            onpaste="setTimeout(() => parseCombinedCoordinates(this.value), 50)"
                        >
                        <div class="form-hint">Auto-splits "lat, lon" copied from Maps into fields.</div>
                    </div>
                </div>

                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-top: -0.25rem; margin-bottom: 1.25rem;">
                    <div id="google-maps-container" style="{{ ($toilet->lat === null || $toilet->lon === null) ? 'display: none;' : '' }}">
                        <a
                            id="google-maps-link"
                            href="{{ ($toilet->lat !== null && $toilet->lon !== null) ? 'https://www.google.com/maps/search/?api=1&query=' . $toilet->lat . ',' . $toilet->lon : '#' }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="btn btn-secondary btn-sm"
                            style="display: inline-flex; align-items: center; gap: 0.35rem;"
                        >
                            <span>📍</span>
                            <span>Open in Google Maps ↗</span>
                        </a>
                    </div>

                    <div style="display: flex; gap: 1.5rem; align-items: center; flex-wrap: wrap;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="checkbox-label" for="is_qualified">
                                <input
                                    type="checkbox"
                                    id="is_qualified"
                                    name="is_qualified"
                                    value="1"
                                    {{ old('is_qualified', $toilet->is_qualified) ? 'checked' : '' }}
                                >
                                <span>Mark as <strong>Qualified (Verified)</strong></span>
                            </label>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="checkbox-label" for="flagged" style="color: #92400e;">
                                <input
                                    type="checkbox"
                                    id="flagged"
                                    name="flagged"
                                    value="1"
                                    {{ old('flagged', $toilet->flagged) ? 'checked' : '' }}
                                >
                                <span>🚩 <strong>Flagged for Review</strong> (Shows in dashboard queue)</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label" for="contact_email">Contact Email</label>
                        <input
                            type="email"
                            id="contact_email"
                            name="contact_email"
                            class="form-control"
                            value="{{ old('contact_email', $toilet->contact_email) }}"
                            placeholder="e.g. info@example.com"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="source">Data Source</label>
                        <input
                            type="text"
                            id="source"
                            name="source"
                            class="form-control"
                            value="{{ old('source', $toilet->source) }}"
                            placeholder="e.g. photo_upload, manual, osm"
                        >
                    </div>
                </div>

                <!-- Google Place Association -->
                <h3 style="font-size: 1rem; font-weight: 600; color: var(--gray-800); margin-top: 1.5rem; margin-bottom: 1rem; border-bottom: 1px solid var(--gray-200); padding-bottom: 0.5rem;">
                    2. Google Place Association (Nearby Places within ~40m)
                </h3>

                <div class="form-group">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.375rem;">
                        <label class="form-label" for="place_id_select" style="margin-bottom: 0;">Select Nearby Google Place (within 100m)</label>
                        <label class="checkbox-label" for="use_place_coordinates" style="font-size: 0.8125rem; font-weight: 600; color: var(--primary);">
                            <input
                                type="checkbox"
                                id="use_place_coordinates"
                                name="use_place_coordinates"
                                value="1"
                                onchange="handleUsePlaceCoordinatesToggle(this)"
                            >
                            <span>📍 Use place coordinates</span>
                        </label>
                    </div>

                    <select
                        id="place_id_select"
                        class="form-control"
                        onchange="handlePlaceSelectionChange(this)"
                    >
                        <option value="" data-lat="" data-lon="" {{ empty($toilet->place_id) ? 'selected' : '' }}>-- No Google Place Assigned (Unlink) --</option>
                        @foreach ($nearbyPlaces as $p)
                            <option
                                value="{{ $p['place_id'] }}"
                                data-lat="{{ $p['lat'] ?? '' }}"
                                data-lon="{{ $p['lon'] ?? '' }}"
                                {{ old('place_id', $toilet->place_id) === $p['place_id'] ? 'selected' : '' }}
                            >
                                {{ $p['name'] }} @if($p['address']) ({{ $p['address'] }}) @endif
                                @if(isset($p['distance_m'])) [~{{ $p['distance_m'] }}m away] @endif
                                — ID: {{ $p['place_id'] }}
                                @if(!empty($p['is_current'])) (Current) @endif
                            </option>
                        @endforeach
                    </select>
                    <div class="form-hint">
                        Checking "Use place coordinates" inserts the place's coordinates and removes lat/lon manual override protection.
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="place_id">Google Place ID (Direct Text Input)</label>
                    <input
                        type="text"
                        id="place_id"
                        name="place_id"
                        class="form-control"
                        value="{{ old('place_id', $toilet->place_id) }}"
                        placeholder="e.g. ChIJgUbEo8cfqEcR577uL8r68vI"
                        style="font-family: monospace;"
                    >
                    <div class="form-hint">You can also paste a Google Place ID directly.</div>
                </div>

                <!-- Boolean Flag Properties -->
                <h3 style="font-size: 1rem; font-weight: 600; color: var(--gray-800); margin-top: 1.5rem; margin-bottom: 1rem; border-bottom: 1px solid var(--gray-200); padding-bottom: 0.5rem;">
                    3. Accessibility & Feature Flags
                </h3>

                <div class="grid-3" style="background: var(--gray-50); padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--gray-200); margin-bottom: 1.5rem;">
                    <div class="form-group" style="margin-bottom: 0.5rem;">
                        <label class="checkbox-label" for="is_unisex">
                            <input
                                type="checkbox"
                                id="is_unisex"
                                name="is_unisex"
                                value="1"
                                {{ old('is_unisex', ($propertyValues['is_unisex'] ?? '0') === '1') ? 'checked' : '' }}
                            >
                            <span>Unisex (All genders)</span>
                        </label>
                    </div>

                    <div class="form-group" style="margin-bottom: 0.5rem;">
                        <label class="checkbox-label" for="is_gender_separated">
                            <input
                                type="checkbox"
                                id="is_gender_separated"
                                name="is_gender_separated"
                                value="1"
                                {{ old('is_gender_separated', ($propertyValues['is_gender_separated'] ?? '0') === '1') ? 'checked' : '' }}
                            >
                            <span>Gender Separated (Men/Women)</span>
                        </label>
                    </div>

                    <div class="form-group" style="margin-bottom: 0.5rem;">
                        <label class="checkbox-label" for="has_wheelchair_access">
                            <input
                                type="checkbox"
                                id="has_wheelchair_access"
                                name="has_wheelchair_access"
                                value="1"
                                {{ old('has_wheelchair_access', ($propertyValues['has_wheelchair_access'] ?? '0') === '1') ? 'checked' : '' }}
                            >
                            <span>Wheelchair Accessible ♿</span>
                        </label>
                    </div>

                    <div class="form-group" style="margin-bottom: 0.5rem;">
                        <label class="checkbox-label" for="has_changing_table">
                            <input
                                type="checkbox"
                                id="has_changing_table"
                                name="has_changing_table"
                                value="1"
                                {{ old('has_changing_table', ($propertyValues['has_changing_table'] ?? '0') === '1') ? 'checked' : '' }}
                            >
                            <span>Changing Table (Baby Wickeltisch) 👶</span>
                        </label>
                    </div>

                    <div class="form-group" style="margin-bottom: 0.5rem;">
                        <label class="checkbox-label" for="accessible_outside_opening_times">
                            <input
                                type="checkbox"
                                id="accessible_outside_opening_times"
                                name="accessible_outside_opening_times"
                                value="1"
                                {{ old('accessible_outside_opening_times', ($propertyValues['accessible_outside_opening_times'] ?? '0') === '1') ? 'checked' : '' }}
                            >
                            <span>Accessible Outside Opening Times</span>
                        </label>
                    </div>

                    <div class="form-group" style="margin-bottom: 0.5rem;">
                        <label class="checkbox-label" for="public_accessible">
                            <input
                                type="checkbox"
                                id="public_accessible"
                                name="public_accessible"
                                value="1"
                                {{ old('public_accessible', ($propertyValues['public_accessible'] ?? '0') === '1') ? 'checked' : '' }}
                            >
                            <span>Publicly Accessible</span>
                        </label>
                    </div>
                </div>

                <!-- Value Properties -->
                <h3 style="font-size: 1rem; font-weight: 600; color: var(--gray-800); margin-top: 1.5rem; margin-bottom: 1rem; border-bottom: 1px solid var(--gray-200); padding-bottom: 0.5rem;">
                    4. Property Values & Details
                </h3>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label" for="euro_key">Euro Key (Euroschlüssel)</label>
                        <select id="euro_key" name="euro_key" class="form-control">
                            <option value="" {{ old('euro_key', $propertyValues['euro_key'] ?? '') === '' ? 'selected' : '' }}>-- Not Set / Unknown --</option>
                            <option value="yes" {{ old('euro_key', $propertyValues['euro_key'] ?? '') === 'yes' ? 'selected' : '' }}>Yes (Euro Key required/available)</option>
                            <option value="no" {{ old('euro_key', $propertyValues['euro_key'] ?? '') === 'no' ? 'selected' : '' }}>No (No Euro Key required)</option>
                            <option value="euro_only" {{ old('euro_key', $propertyValues['euro_key'] ?? '') === 'euro_only' ? 'selected' : '' }}>Euro Key Only</option>
                            <option value="unknown" {{ old('euro_key', $propertyValues['euro_key'] ?? '') === 'unknown' ? 'selected' : '' }}>Unknown</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="storage_space">Storage Space (Ablagefläche)</label>
                        <select id="storage_space" name="storage_space" class="form-control">
                            <option value="" {{ old('storage_space', $propertyValues['storage_space'] ?? '') === '' ? 'selected' : '' }}>-- Not Set --</option>
                            <option value="none" {{ old('storage_space', $propertyValues['storage_space'] ?? '') === 'none' ? 'selected' : '' }}>None</option>
                            <option value="little" {{ old('storage_space', $propertyValues['storage_space'] ?? '') === 'little' ? 'selected' : '' }}>Little</option>
                            <option value="much" {{ old('storage_space', $propertyValues['storage_space'] ?? '') === 'much' ? 'selected' : '' }}>Much</option>
                        </select>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label" for="address">Address</label>
                        <input
                            type="text"
                            id="address"
                            name="address"
                            class="form-control"
                            value="{{ old('address', $propertyValues['address'] ?? '') }}"
                            placeholder="e.g. Alexanderplatz 1, 10178 Berlin"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="website">Website</label>
                        <input
                            type="url"
                            id="website"
                            name="website"
                            class="form-control"
                            value="{{ old('website', $propertyValues['website'] ?? '') }}"
                            placeholder="https://example.com"
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="comment">Comment / Directions</label>
                    <textarea
                        id="comment"
                        name="comment"
                        class="form-control"
                        rows="2"
                        placeholder="e.g. Entrance around the corner, 50 cents fee..."
                    >{{ old('comment', $propertyValues['comment'] ?? '') }}</textarea>
                </div>

                <div class="form-group">
                    <label class="form-label" for="place_opening_hours">Place Opening Hours (JSON format)</label>
                    <textarea
                        id="place_opening_hours"
                        name="place_opening_hours"
                        class="form-control"
                        rows="3"
                        style="font-family: monospace; font-size: 0.8125rem;"
                        placeholder='[{"open":{"day":1,"hour":8,"minute":0},"close":{"day":1,"hour":20,"minute":0}}]'
                    >{{ old('place_opening_hours', $propertyValues['place_opening_hours'] ?? '') }}</textarea>
                    <div class="form-hint">Google Places New periods format (array of open/close day, hour, minute objects).</div>
                </div>

                <!-- Custom Properties -->
                <h3 style="font-size: 1rem; font-weight: 600; color: var(--gray-800); margin-top: 1.5rem; margin-bottom: 0.5rem; border-bottom: 1px solid var(--gray-200); padding-bottom: 0.5rem;">
                    5. Additional / Custom Properties
                </h3>

                <div id="custom-properties-list" style="margin-bottom: 1rem;">
                    @foreach ($customProperties as $idx => $cp)
                        <div class="grid-2" style="margin-bottom: 0.5rem;">
                            <input
                                type="text"
                                name="custom_property_type[]"
                                class="form-control"
                                value="{{ $cp['type'] }}"
                                placeholder="Property key"
                            >
                            <input
                                type="text"
                                name="custom_property_value[]"
                                class="form-control"
                                value="{{ $cp['value'] }}"
                                placeholder="Property value"
                            >
                        </div>
                    @endforeach
                </div>

                <button
                    type="button"
                    class="btn btn-secondary btn-sm"
                    onclick="addCustomPropertyRow()"
                >
                    + Add Custom Property Row
                </button>

            </div>
            <div class="card-header" style="border-top: 1px solid var(--gray-200); border-bottom: none; background: var(--gray-50);">
                <div style="font-size: 0.875rem; color: var(--gray-600);">
                    All edits will be recorded in the change history (diff) and user_overridden flags.
                </div>
                <button type="submit" class="btn btn-success btn-lg">
                    💾 Save All Changes
                </button>
            </div>
        </div>
    </form>

    <!-- Section 3: Photos & Deletion -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span>📷 Toilet Photos ({{ $toilet->photos->count() }})</span>
            </div>
            <div style="font-size: 0.8125rem; color: var(--gray-500);">
                Uploaded image attachments
            </div>
        </div>
        <div class="card-body">
            @if ($toilet->photos->isEmpty())
                <div style="text-align: center; padding: 2rem; color: var(--gray-500); background: var(--gray-50); border-radius: var(--radius-md); border: 1px dashed var(--gray-300);">
                    No active photos attached to this toilet.
                </div>
            @else
                <div class="photo-grid">
                    @foreach ($toilet->photos as $photo)
                        @php
                            $photoPath = \App\Services\S3PhotoStorageService::pathForId($toilet->id);
                            $publicUrl = config('wcinfo.s3.public_url') . $photoPath . '/' . ($photo->filename_thumb ?: $photo->filename);
                            $fullUrl = config('wcinfo.s3.public_url') . $photoPath . '/' . $photo->filename;
                        @endphp
                        <div class="photo-card">
                            <a href="{{ $fullUrl }}" target="_blank" rel="noopener">
                                <img src="{{ $publicUrl }}" alt="Photo {{ $photo->id }}" loading="lazy">
                            </a>
                            <div class="photo-card-info">
                                <div class="photo-card-meta">
                                    <strong>#{{ $photo->id }}</strong>: {{ $photo->filename }}
                                </div>
                                @if ($photo->deleted_ts)
                                    <div class="badge badge-deleted" style="font-size: 0.6875rem;">
                                        Deleted: {{ $photo->deleted_ts->format('Y-m-d H:i') }}
                                    </div>
                                @endif

                                <div style="display: flex; gap: 0.375rem; margin-top: auto; padding-top: 0.5rem;">
                                    <!-- Soft Delete -->
                                    <form
                                        action="{{ route('admin.toilets.photos.delete', ['id' => $toilet->id, 'filename' => $photo->filename]) }}"
                                        method="POST"
                                        onsubmit="return confirm('Soft-delete this photo? (Files on S3 will be prefixed with _DELETED_)');"
                                        style="flex: 1;"
                                    >
                                        @csrf
                                        <button type="submit" class="btn btn-danger-outline btn-sm" style="width: 100%;">
                                            🗑 Soft Delete
                                        </button>
                                    </form>

                                    <!-- Hard Delete -->
                                    <form
                                        action="{{ route('admin.toilets.photos.delete', ['id' => $toilet->id, 'filename' => $photo->filename]) }}"
                                        method="POST"
                                        onsubmit="return confirm('PERMANENTLY hard delete this photo from S3 and database? This action cannot be undone!');"
                                    >
                                        @csrf
                                        <input type="hidden" name="hard" value="1">
                                        <button type="submit" class="btn btn-danger btn-sm" title="Permanently Delete">
                                            Permanent
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <!-- Section 4: Version History & Revisions (Audit Trail) -->
    <div class="card" style="margin-top: 1.5rem;">
        <div class="card-header">
            <div class="card-title">
                <span>📜 Version History & Audit Trail</span>
                <span class="badge" style="background: #ede9fe; color: #5b21b6; font-size: 0.8125rem;">{{ $revisions->count() }} revision(s)</span>
            </div>
            <div style="font-size: 0.8125rem; color: var(--gray-500);">
                Track all historical changes, diffs, and restore previous states
            </div>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 90px;">Version</th>
                            <th style="width: 170px;">Date & Time</th>
                            <th style="width: 140px;">Source</th>
                            <th>Summary & Diffs</th>
                            <th style="width: 150px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($revisions as $rev)
                            <tr style="{{ $rev->version === ($toilet->version ?: 1) ? 'background-color: #f8fafc;' : '' }}">
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.35rem;">
                                        <span class="badge" style="background: #ede9fe; color: #5b21b6; font-family: monospace; font-size: 0.8125rem; font-weight: 700;">
                                            v{{ $rev->version }}
                                        </span>
                                        @if ($rev->version === ($toilet->version ?: 1))
                                            <span class="badge badge-active" style="font-size: 0.6875rem;">Current</span>
                                        @endif
                                    </div>
                                </td>
                                <td style="font-size: 0.8125rem; color: var(--gray-600);">
                                    <div>{{ $rev->created_at->format('Y-m-d H:i:s') }}</div>
                                    <div style="font-size: 0.6875rem; color: var(--gray-400);">{{ $rev->created_at->diffForHumans() }}</div>
                                </td>
                                <td>
                                    <span class="badge badge-unqualified" style="text-transform: uppercase; font-size: 0.6875rem; letter-spacing: 0.05em;">
                                        {{ str_replace('_', ' ', $rev->source) }}
                                    </span>
                                </td>
                                <td>
                                    @if (!empty($rev->summary))
                                        <div style="font-weight: 600; font-size: 0.8125rem; color: var(--gray-800); margin-bottom: 0.25rem;">
                                            {{ $rev->summary }}
                                        </div>
                                    @endif

                                    @if (!empty($rev->diff) && is_array($rev->diff))
                                        <div class="diff-container" style="font-size: 0.75rem; padding: 0.5rem 0.75rem; border-radius: 6px;">
                                            @foreach ($rev->diff as $field => $change)
                                                <div class="diff-row" style="padding: 0.15rem 0;">
                                                    <span class="diff-field" style="min-width: 120px;">{{ $field }}:</span>
                                                    <span class="diff-old">
                                                        {{ is_array($change['old'] ?? null) ? json_encode($change['old']) : (string)($change['old'] ?? 'null') }}
                                                    </span>
                                                    <span style="color: var(--gray-400); margin: 0 0.25rem;">→</span>
                                                    <span class="diff-new">
                                                        {{ is_array($change['new'] ?? null) ? json_encode($change['new']) : (string)($change['new'] ?? 'null') }}
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <div style="font-size: 0.75rem; color: var(--gray-400); font-style: italic;">
                                            Snapshot created
                                        </div>
                                    @endif
                                </td>
                                <td style="text-align: right;">
                                    @if ($rev->version !== ($toilet->version ?: 1))
                                        <form
                                            action="{{ route('admin.toilets.restore-version', ['id' => $toilet->id, 'version' => $rev->version]) }}"
                                            method="POST"
                                            onsubmit="return confirm('Restore Toilet #{{ $toilet->id }} to Version {{ $rev->version }}? This will create a new version with all data from v{{ $rev->version }}.');"
                                            style="display: inline;"
                                        >
                                            @csrf
                                            <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--primary); font-weight: 600;">
                                                ↩ Restore v{{ $rev->version }}
                                            </button>
                                        </form>
                                    @else
                                        <span class="badge badge-active" style="font-size: 0.75rem;">✓ Active</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 2rem 1rem; color: var(--gray-500); font-size: 0.875rem;">
                                    No historical revisions recorded yet for this toilet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
<script>
    function addCustomPropertyRow() {
        const container = document.getElementById('custom-properties-list');
        const row = document.createElement('div');
        row.className = 'grid-2';
        row.style.marginBottom = '0.5rem';
        row.innerHTML = `
            <input type="text" name="custom_property_type[]" class="form-control" placeholder="Property key (e.g. key_code)">
            <input type="text" name="custom_property_value[]" class="form-control" placeholder="Property value">
        `;
        container.appendChild(row);
    }

    function updateGoogleMapsLink() {
        const latInput = document.getElementById('lat');
        const lonInput = document.getElementById('lon');
        const link = document.getElementById('google-maps-link');
        const container = document.getElementById('google-maps-container');

        const lat = latInput ? latInput.value.trim() : '';
        const lon = lonInput ? lonInput.value.trim() : '';

        if (lat !== '' && lon !== '' && !isNaN(Number(lat)) && !isNaN(Number(lon))) {
            if (link) {
                link.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(lat)},${encodeURIComponent(lon)}`;
            }
            if (container) {
                container.style.display = 'block';
            }
        } else {
            if (container) {
                container.style.display = 'none';
            }
        }
    }

    function parseCombinedCoordinates(raw) {
        if (!raw || typeof raw !== 'string') return;
        const input = raw.trim();
        if (!input) return;

        // Check if it's a URL containing coordinates (e.g. @51.761468,11.436103 or q=51.761468,11.436103)
        const urlMatch = input.match(/[@?&](?:q=)?(-?\d+(?:\.\d+)?)[,\s]+(-?\d+(?:\.\d+)?)/);
        if (urlMatch) {
            setLatLon(urlMatch[1], urlMatch[2]);
            return;
        }

        // Standard decimal numbers separated by comma, semicolon, or whitespace (e.g. "51.76146829789323, 11.436103193794011")
        const directMatch = input.match(/^(-?\d+(?:\.\d+)?)[,\s;]+(-?\d+(?:\.\d+)?)$/);
        if (directMatch) {
            setLatLon(directMatch[1], directMatch[2]);
            return;
        }

        // Fallback: search for two numbers anywhere in the input
        const numbers = input.match(/-?\d+(?:\.\d+)?/g);
        if (numbers && numbers.length >= 2) {
            setLatLon(numbers[0], numbers[1]);
        }
    }

    function setLatLon(lat, lon) {
        const latInput = document.getElementById('lat');
        const lonInput = document.getElementById('lon');
        if (latInput && lonInput) {
            latInput.value = lat;
            lonInput.value = lon;
            updateGoogleMapsLink();
        }
    }

    function handlePlaceSelectionChange(selectEl) {
        const placeIdInput = document.getElementById('place_id');
        if (placeIdInput) {
            placeIdInput.value = selectEl.value;
        }

        const usePlaceCoordsCheckbox = document.getElementById('use_place_coordinates');
        if (usePlaceCoordsCheckbox && usePlaceCoordsCheckbox.checked) {
            applySelectedPlaceCoordinates(selectEl);
        }
    }

    function handleUsePlaceCoordinatesToggle(checkboxEl) {
        if (checkboxEl.checked) {
            const selectEl = document.getElementById('place_id_select');
            if (selectEl) {
                applySelectedPlaceCoordinates(selectEl);
            }
        }
    }

    function applySelectedPlaceCoordinates(selectEl) {
        const selectedOption = selectEl.options[selectEl.selectedIndex];
        if (!selectedOption) return;

        const pLat = selectedOption.getAttribute('data-lat');
        const pLon = selectedOption.getAttribute('data-lon');

        if (pLat && pLon && !isNaN(Number(pLat)) && !isNaN(Number(pLon))) {
            setLatLon(pLat, pLon);
        }
    }
</script>
@endpush
