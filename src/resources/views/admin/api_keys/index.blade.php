@extends('admin.layout')

@section('title', 'API Keys & Rate Limits')

@section('content')

    <!-- Top Header -->
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
        <div>
            <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--text-heading);">
                🔑 API Keys & Anti-Scraping Rate Limits
            </h1>
            <div style="font-size: 0.875rem; color: var(--text-muted); margin-top: 0.25rem;">
                Manage client API keys, monitor real-time request rates, and manage IP blocklists
            </div>
        </div>

        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('new-key-modal').style.display='flex'">
                + New API Key
            </button>
            <a href="{{ route('admin.index') }}" class="btn btn-secondary btn-sm">← Back to Dashboard</a>
        </div>
    </div>

    <!-- Live Rate & API Key Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 1.25rem; margin-bottom: 2rem;">
        @foreach ($apiKeys as $apiKey)
            @php
                $metric = $metrics[$apiKey->id] ?? ['current_req_per_min' => 0, 'global_limit' => 800, 'usage_percent' => 0, 'remaining' => 800];
                $percent = $metric['usage_percent'];
                $barColor = $percent >= 90 ? 'var(--danger)' : ($percent >= 70 ? 'var(--warning)' : 'var(--primary)');
            @endphp
            <div class="card" style="margin-bottom: 0; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div class="card-header" style="display: flex; align-items: center; justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <strong style="font-size: 1.05rem; color: var(--text-heading);">{{ $apiKey->name }}</strong>
                            @if ($apiKey->is_active)
                                <span class="badge badge-active">Active</span>
                            @else
                                <span class="badge badge-hidden" style="background-color: var(--danger-light); color: var(--danger);">Inactive</span>
                            @endif
                        </div>
                        <span style="font-size: 0.75rem; color: var(--text-muted);">ID: {{ $apiKey->id }}</span>
                    </div>

                    <div class="card-body">
                        @if ($apiKey->description)
                            <p style="font-size: 0.8125rem; color: var(--text-muted); margin-bottom: 0.75rem;">
                                {{ $apiKey->description }}
                            </p>
                        @endif

                        <!-- Key Token Display -->
                        <div style="background: var(--bg-subtle); border: 1px solid var(--border-main); border-radius: var(--radius-md); padding: 0.6rem 0.75rem; margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;">
                            <code style="font-size: 0.8125rem; font-family: 'JetBrains Mono', monospace; color: var(--text-main); word-break: break-all;">
                                {{ $apiKey->key }}
                            </code>
                            <button type="button" class="btn btn-secondary btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.75rem;" onclick="navigator.clipboard.writeText('{{ $apiKey->key }}'); this.innerText='Copied!'; setTimeout(() => this.innerText='Copy', 2000);">
                                Copy
                            </button>
                        </div>

                        <!-- Live Traffic / Global Ceiling Gauge -->
                        <div style="margin-bottom: 1rem;">
                            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.35rem;">
                                <span style="font-size: 0.8125rem; font-weight: 600; color: var(--text-main);">
                                    Global Usage (All IPs)
                                </span>
                                <span style="font-size: 0.8125rem; font-weight: 600; color: {{ $barColor }};">
                                    {{ $metric['current_req_per_min'] }} / {{ $metric['global_limit'] }} req/min ({{ $percent }}%)
                                </span>
                            </div>
                            <div style="width: 100%; height: 8px; background-color: var(--border-main); border-radius: 4px; overflow: hidden;">
                                <div style="width: {{ min(100, $percent) }}%; height: 100%; background-color: {{ $barColor }}; transition: width 0.3s ease;"></div>
                            </div>
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                                {{ $metric['remaining'] }} req/min remaining before alert & throttling
                            </div>
                        </div>

                        <!-- Rate Limit Specs -->
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; background: var(--bg-subtle); border-radius: var(--radius-md); padding: 0.5rem; margin-bottom: 1rem; text-align: center;">
                            <div>
                                <div style="font-size: 0.6875rem; color: var(--text-muted); text-transform: uppercase;">Per-IP Limit</div>
                                <div style="font-size: 0.875rem; font-weight: 600; color: var(--text-main);">{{ $apiKey->rate_limit_per_minute }} /min</div>
                            </div>
                            <div>
                                <div style="font-size: 0.6875rem; color: var(--text-muted); text-transform: uppercase;">Block Time</div>
                                <div style="font-size: 0.875rem; font-weight: 600; color: var(--text-main);">{{ $apiKey->rate_limit_block_duration }}s</div>
                            </div>
                            <div>
                                <div style="font-size: 0.6875rem; color: var(--text-muted); text-transform: uppercase;">Penalty / Slow</div>
                                <div style="font-size: 0.875rem; font-weight: 600; color: var(--text-main);">{{ $apiKey->rate_limit_penalty_period }}s</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Toolbar -->
                <div class="card-footer" style="display: flex; gap: 0.5rem; justify-content: flex-end; flex-wrap: wrap; background: var(--bg-card); border-top: 1px solid var(--border-main); padding: 0.75rem 1rem;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="openEditModal({{ json_encode($apiKey) }})">
                        Edit
                    </button>
                    <form action="{{ route('admin.api_keys.toggle', $apiKey->id) }}" method="POST" style="display: inline;">
                        @csrf
                        <button type="submit" class="btn btn-secondary btn-sm">
                            {{ $apiKey->is_active ? 'Deactivate' : 'Activate' }}
                        </button>
                    </form>
                    <form action="{{ route('admin.api_keys.regenerate', $apiKey->id) }}" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to regenerate this API key token? Existing apps using the old key will stop working until updated!');">
                        @csrf
                        <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--danger);">
                            Regenerate
                        </button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Blocked & Penalized IPs List -->
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <div>
                <div class="card-title">
                    <span>🛡️ Active IP Blocks & Slowdown Penalties</span>
                </div>
                <div style="font-size: 0.8125rem; color: var(--text-muted);">
                    Clients currently restricted due to excessive requests exceeding rate limits
                </div>
            </div>
            <span class="badge {{ count($blockedIps) > 0 ? 'badge-hidden' : 'badge-active' }}" style="{{ count($blockedIps) > 0 ? 'background-color: var(--warning-light); color: var(--warning);' : '' }}">
                {{ count($blockedIps) }} Active {{ count($blockedIps) === 1 ? 'Restriction' : 'Restrictions' }}
            </span>
        </div>

        <div class="card-body" style="padding: 0;">
            @if (empty($blockedIps))
                <div style="padding: 2rem; text-align: center; color: var(--text-muted);">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">✓</div>
                    <strong style="color: var(--text-main);">All clear!</strong>
                    <p style="font-size: 0.875rem; margin-top: 0.25rem;">No client IPs are currently blocked or penalized.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>API Key</th>
                                <th>Client IP</th>
                                <th>Status</th>
                                <th>Hard Block Remaining</th>
                                <th>Penalty (Slowdown) Remaining</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($blockedIps as $item)
                                <tr>
                                    <td>
                                        <strong>{{ $item['api_key_name'] }}</strong>
                                    </td>
                                    <td>
                                        <code style="font-family: 'JetBrains Mono', monospace; font-size: 0.875rem;">{{ $item['ip'] }}</code>
                                    </td>
                                    <td>
                                        @if ($item['is_blocked'])
                                            <span class="badge" style="background-color: var(--danger-light); color: var(--danger);">
                                                🚫 Hard-Blocked (429)
                                            </span>
                                        @elseif ($item['is_penalized'])
                                            <span class="badge" style="background-color: var(--warning-light); color: var(--warning);">
                                                ⏳ Penalty Slowdown (10 req/min)
                                            </span>
                                        @else
                                            <span class="badge badge-active">Normal</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($item['blocked_remaining_seconds'] > 0)
                                            <span style="font-weight: 600; color: var(--danger);">{{ $item['blocked_remaining_seconds'] }}s</span>
                                        @else
                                            <span style="color: var(--text-muted);">Expired</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($item['penalty_remaining_seconds'] > 0)
                                            <span style="font-weight: 600; color: var(--warning);">{{ $item['penalty_remaining_seconds'] }}s</span>
                                        @else
                                            <span style="color: var(--text-muted);">None</span>
                                        @endif
                                    </td>
                                    <td style="text-align: right;">
                                        <form action="{{ route('admin.api_keys.unblock') }}" method="POST" style="display: inline;">
                                            @csrf
                                            <input type="hidden" name="api_key_id" value="{{ $item['api_key_id'] }}">
                                            <input type="hidden" name="ip" value="{{ $item['ip'] }}">
                                            <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--success);">
                                                Unblock Now
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <!-- Client Integration Documentation Guide -->
    <div class="card" style="margin-top: 1.5rem;">
        <div class="card-header">
            <div class="card-title">
                <span>📖 API Key Client Integration Guide</span>
            </div>
        </div>
        <div class="card-body">
            <p style="font-size: 0.875rem; color: var(--text-main); margin-bottom: 0.75rem;">
                Client applications (iOS, Android, Web) must provide their API key with every request using one of the following methods:
            </p>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
                <div style="background: var(--bg-subtle); padding: 0.875rem; border-radius: var(--radius-md); border: 1px solid var(--border-main);">
                    <div style="font-weight: 600; font-size: 0.8125rem; margin-bottom: 0.25rem;">1. HTTP Header (Recommended)</div>
                    <code style="font-size: 0.75rem; display: block; font-family: 'JetBrains Mono', monospace; color: var(--primary);">
                        X-Api-Key: wc_ios_...
                    </code>
                </div>
                <div style="background: var(--bg-subtle); padding: 0.875rem; border-radius: var(--radius-md); border: 1px solid var(--border-main);">
                    <div style="font-weight: 600; font-size: 0.8125rem; margin-bottom: 0.25rem;">2. Bearer Authorization</div>
                    <code style="font-size: 0.75rem; display: block; font-family: 'JetBrains Mono', monospace; color: var(--primary);">
                        Authorization: Bearer wc_ios_...
                    </code>
                </div>
                <div style="background: var(--bg-subtle); padding: 0.875rem; border-radius: var(--radius-md); border: 1px solid var(--border-main);">
                    <div style="font-weight: 600; font-size: 0.8125rem; margin-bottom: 0.25rem;">3. Query Parameter</div>
                    <code style="font-size: 0.75rem; display: block; font-family: 'JetBrains Mono', monospace; color: var(--primary);">
                        https://api.wc-info.org/toilets/nearby/... ?api_key=wc_ios_...
                    </code>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Key Modal -->
    <div id="new-key-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 1rem;">
        <div style="background: var(--bg-card); border-radius: var(--radius-lg); max-width: 500px; width: 100%; box-shadow: var(--shadow-lg); overflow: hidden; border: 1px solid var(--border-main);">
            <div style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-main); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-heading); margin: 0;">Create New API Key</h3>
                <button type="button" onclick="document.getElementById('new-key-modal').style.display='none'" style="background: none; border: none; font-size: 1.25rem; cursor: pointer; color: var(--text-muted);">&times;</button>
            </div>
            <form action="{{ route('admin.api_keys.store') }}" method="POST">
                @csrf
                <div style="padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem;">
                    <div>
                        <label class="form-label">Client / Application Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Partner Service, Third-Party App" required>
                    </div>
                    <div>
                        <label class="form-label">Description (Optional)</label>
                        <input type="text" name="description" class="form-control" placeholder="Brief note about the client integration">
                    </div>
                    <div class="grid-2">
                        <div>
                            <label class="form-label">Per-IP Limit (req/min)</label>
                            <input type="number" name="rate_limit_per_minute" class="form-control" value="40" min="1" required>
                        </div>
                        <div>
                            <label class="form-label">Global Limit (req/min)</label>
                            <input type="number" name="global_rate_limit_per_minute" class="form-control" value="800" min="1" required>
                        </div>
                    </div>
                    <div class="grid-2">
                        <div>
                            <label class="form-label">Block Duration (sec)</label>
                            <input type="number" name="rate_limit_block_duration" class="form-control" value="120" min="1" required>
                        </div>
                        <div>
                            <label class="form-label">Penalty / Slowdown (sec)</label>
                            <input type="number" name="rate_limit_penalty_period" class="form-control" value="300" min="1" required>
                        </div>
                    </div>
                </div>
                <div style="padding: 0.75rem 1.25rem; background: var(--bg-subtle); border-top: 1px solid var(--border-main); display: flex; justify-content: flex-end; gap: 0.5rem;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('new-key-modal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Create API Key</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Key Modal -->
    <div id="edit-key-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 1rem;">
        <div style="background: var(--bg-card); border-radius: var(--radius-lg); max-width: 500px; width: 100%; box-shadow: var(--shadow-lg); overflow: hidden; border: 1px solid var(--border-main);">
            <div style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-main); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-heading); margin: 0;">Edit API Key Configuration</h3>
                <button type="button" onclick="document.getElementById('edit-key-modal').style.display='none'" style="background: none; border: none; font-size: 1.25rem; cursor: pointer; color: var(--text-muted);">&times;</button>
            </div>
            <form id="edit-key-form" method="POST">
                @csrf
                <div style="padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem;">
                    <div>
                        <label class="form-label">Client / Application Name</label>
                        <input type="text" id="edit-name" name="name" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label">Description (Optional)</label>
                        <input type="text" id="edit-description" name="description" class="form-control">
                    </div>
                    <div class="grid-2">
                        <div>
                            <label class="form-label">Per-IP Limit (req/min)</label>
                            <input type="number" id="edit-ip-limit" name="rate_limit_per_minute" class="form-control" min="1" required>
                        </div>
                        <div>
                            <label class="form-label">Global Limit (req/min)</label>
                            <input type="number" id="edit-global-limit" name="global_rate_limit_per_minute" class="form-control" min="1" required>
                        </div>
                    </div>
                    <div class="grid-2">
                        <div>
                            <label class="form-label">Block Duration (sec)</label>
                            <input type="number" id="edit-block-duration" name="rate_limit_block_duration" class="form-control" min="1" required>
                        </div>
                        <div>
                            <label class="form-label">Penalty / Slowdown (sec)</label>
                            <input type="number" id="edit-penalty-period" name="rate_limit_penalty_period" class="form-control" min="1" required>
                        </div>
                    </div>
                </div>
                <div style="padding: 0.75rem 1.25rem; background: var(--bg-subtle); border-top: 1px solid var(--border-main); display: flex; justify-content: flex-end; gap: 0.5rem;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('edit-key-modal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(apiKey) {
            const form = document.getElementById('edit-key-form');
            form.action = `/admin/api-keys/${apiKey.id}`;
            document.getElementById('edit-name').value = apiKey.name;
            document.getElementById('edit-description').value = apiKey.description || '';
            document.getElementById('edit-ip-limit').value = apiKey.rate_limit_per_minute;
            document.getElementById('edit-global-limit').value = apiKey.global_rate_limit_per_minute;
            document.getElementById('edit-block-duration').value = apiKey.rate_limit_block_duration;
            document.getElementById('edit-penalty-period').value = apiKey.rate_limit_penalty_period;
            document.getElementById('edit-key-modal').style.display = 'flex';
        }
    </script>
@endsection
