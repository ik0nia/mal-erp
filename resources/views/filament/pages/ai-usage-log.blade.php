<x-filament-panels::page>

    {{-- Selector perioada --}}
    <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.5rem;">
        <span style="font-size:0.875rem; font-weight:500; color:#374151;">Perioada:</span>
        @foreach($this->getPeriodOptions() as $val => $label)
            <button
                wire:click="$set('period', '{{ $val }}')"
                style="padding:0.25rem 0.75rem; border-radius:9999px; font-size:0.875rem; font-weight:500; border:none; cursor:pointer; transition:background 0.15s;
                    {{ $period == $val
                        ? 'background:#8B1A1A; color:#fff;'
                        : 'background:#f3f4f6; color:#374151;' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    @php
        $stats = $this->getStats();
        $bySource = $this->getBySource();
        $byDay = $this->getByDay();
        $logs = $this->getRecentLogs();
    @endphp

    {{-- Stat cards --}}
    <div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:1rem; margin-bottom:1.5rem;" class="ai-stats-grid">
        <div style="background:#fff; border-radius:0.75rem; box-shadow:0 1px 3px rgba(0,0,0,0.1); padding:1rem; border:1px solid #e5e7eb;">
            <p style="font-size:0.75rem; color:#6b7280; margin:0 0 0.25rem;">Cost total</p>
            <p style="font-size:1.5rem; font-weight:700; color:#dc2626; margin:0;">${{ number_format($stats['total_cost'], 4) }}</p>
        </div>
        <div style="background:#fff; border-radius:0.75rem; box-shadow:0 1px 3px rgba(0,0,0,0.1); padding:1rem; border:1px solid #e5e7eb;">
            <p style="font-size:0.75rem; color:#6b7280; margin:0 0 0.25rem;">Medie / zi</p>
            <p style="font-size:1.5rem; font-weight:700; color:#ea580c; margin:0;">${{ number_format($stats['avg_per_day'], 4) }}</p>
        </div>
        <div style="background:#fff; border-radius:0.75rem; box-shadow:0 1px 3px rgba(0,0,0,0.1); padding:1rem; border:1px solid #e5e7eb;">
            <p style="font-size:0.75rem; color:#6b7280; margin:0 0 0.25rem;">Apeluri API</p>
            <p style="font-size:1.5rem; font-weight:700; color:#1f2937; margin:0;">{{ number_format($stats['call_count'], 0, '.', '') }}</p>
        </div>
        <div style="background:#fff; border-radius:0.75rem; box-shadow:0 1px 3px rgba(0,0,0,0.1); padding:1rem; border:1px solid #e5e7eb;">
            <p style="font-size:0.75rem; color:#6b7280; margin:0 0 0.25rem;">Tokeni input</p>
            <p style="font-size:1.5rem; font-weight:700; color:#2563eb; margin:0;">{{ number_format($stats['total_input'], 0, '.', '') }}</p>
        </div>
        <div style="background:#fff; border-radius:0.75rem; box-shadow:0 1px 3px rgba(0,0,0,0.1); padding:1rem; border:1px solid #e5e7eb;">
            <p style="font-size:0.75rem; color:#6b7280; margin:0 0 0.25rem;">Tokeni output</p>
            <p style="font-size:1.5rem; font-weight:700; color:#9333ea; margin:0;">{{ number_format($stats['total_output'], 0, '.', '') }}</p>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:1fr; gap:1.5rem; margin-bottom:1.5rem;" class="ai-two-col-grid">

        {{-- Cost pe sursa --}}
        <div style="background:#fff; border-radius:0.75rem; box-shadow:0 1px 3px rgba(0,0,0,0.1); border:1px solid #e5e7eb; overflow:hidden;">
            <div style="padding:0.75rem 1rem; border-bottom:1px solid #e5e7eb;">
                <h3 style="font-weight:600; color:#1f2937; margin:0;">Cost pe sursa</h3>
            </div>
            <div style="overflow-x:auto;">
                <table style="width:100%; font-size:0.875rem; border-collapse:collapse;">
                    <thead style="background:#f9fafb;">
                        <tr>
                            <th style="text-align:left; padding:0.5rem 1rem; color:#4b5563; font-weight:500;">Sursa</th>
                            <th style="text-align:left; padding:0.5rem 1rem; color:#4b5563; font-weight:500;">Model</th>
                            <th style="text-align:right; padding:0.5rem 1rem; color:#4b5563; font-weight:500;">Apeluri</th>
                            <th style="text-align:right; padding:0.5rem 1rem; color:#4b5563; font-weight:500;">Cost USD</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($bySource as $row)
                            <tr style="border-top:1px solid #f3f4f6;">
                                <td style="padding:0.5rem 1rem; color:#1f2937;">{{ $row->source_label }}</td>
                                <td style="padding:0.5rem 1rem;">
                                    <span style="font-size:0.75rem; padding:0.125rem 0.5rem; border-radius:9999px;
                                        {{ str_contains($row->model, 'sonnet') ? 'background:#f3e8ff; color:#7e22ce;' : 'background:#dbeafe; color:#1d4ed8;' }}">
                                        {{ str_contains($row->model, 'sonnet') ? 'Sonnet' : 'Haiku' }}
                                    </span>
                                </td>
                                <td style="padding:0.5rem 1rem; text-align:right; color:#4b5563;">{{ number_format($row->calls, 0, '.', '') }}</td>
                                <td style="padding:0.5rem 1rem; text-align:right; font-weight:600; {{ $row->cost_usd > 1 ? 'color:#dc2626;' : 'color:#1f2937;' }}">
                                    ${{ number_format($row->cost_usd, 4) }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" style="padding:1.5rem 1rem; text-align:center; color:#9ca3af;">Nicio inregistrare</td></tr>
                        @endforelse
                    </tbody>
                    @if($bySource->isNotEmpty())
                    <tfoot style="background:#f9fafb; border-top:2px solid #e5e7eb;">
                        <tr>
                            <td colspan="3" style="padding:0.5rem 1rem; font-weight:600; color:#374151;">TOTAL</td>
                            <td style="padding:0.5rem 1rem; text-align:right; font-weight:700; color:#dc2626;">
                                ${{ number_format($bySource->sum('cost_usd'), 4) }}
                            </td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- Cost pe zi --}}
        <div style="background:#fff; border-radius:0.75rem; box-shadow:0 1px 3px rgba(0,0,0,0.1); border:1px solid #e5e7eb; overflow:hidden;">
            <div style="padding:0.75rem 1rem; border-bottom:1px solid #e5e7eb;">
                <h3 style="font-weight:600; color:#1f2937; margin:0;">Cost pe zi</h3>
            </div>
            <div style="overflow-x:auto;">
                <table style="width:100%; font-size:0.875rem; border-collapse:collapse;">
                    <thead style="background:#f9fafb;">
                        <tr>
                            <th style="text-align:left; padding:0.5rem 1rem; color:#4b5563; font-weight:500;">Data</th>
                            <th style="text-align:right; padding:0.5rem 1rem; color:#4b5563; font-weight:500;">Apeluri</th>
                            <th style="text-align:right; padding:0.5rem 1rem; color:#4b5563; font-weight:500;">Input tok.</th>
                            <th style="text-align:right; padding:0.5rem 1rem; color:#4b5563; font-weight:500;">Output tok.</th>
                            <th style="text-align:right; padding:0.5rem 1rem; color:#4b5563; font-weight:500;">Cost USD</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($byDay as $row)
                            <tr style="border-top:1px solid #f3f4f6;">
                                <td style="padding:0.5rem 1rem; font-weight:500; color:#1f2937;">{{ $row->day }}</td>
                                <td style="padding:0.5rem 1rem; text-align:right; color:#4b5563;">{{ number_format($row->calls, 0, '.', '') }}</td>
                                <td style="padding:0.5rem 1rem; text-align:right; color:#2563eb;">{{ number_format($row->input_tokens, 0, '.', '') }}</td>
                                <td style="padding:0.5rem 1rem; text-align:right; color:#9333ea;">{{ number_format($row->output_tokens, 0, '.', '') }}</td>
                                <td style="padding:0.5rem 1rem; text-align:right; font-weight:600; {{ $row->cost_usd > 1 ? 'color:#dc2626;' : 'color:#1f2937;' }}">
                                    ${{ number_format($row->cost_usd, 4) }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" style="padding:1.5rem 1rem; text-align:center; color:#9ca3af;">Nicio inregistrare</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    {{-- Log detaliat --}}
    <div style="background:#fff; border-radius:0.75rem; box-shadow:0 1px 3px rgba(0,0,0,0.1); border:1px solid #e5e7eb; overflow:hidden;">
        <div style="padding:0.75rem 1rem; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
            <h3 style="font-weight:600; color:#1f2937; margin:0;">Log detaliat (ultimele 200 apeluri)</h3>
            <span style="font-size:0.75rem; color:#6b7280;">{{ $logs->count() }} inregistrari</span>
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%; font-size:0.875rem; border-collapse:collapse;">
                <thead style="background:#f9fafb;">
                    <tr>
                        <th style="text-align:left; padding:0.5rem 1rem; color:#4b5563; font-weight:500;">Data / Ora</th>
                        <th style="text-align:left; padding:0.5rem 1rem; color:#4b5563; font-weight:500;">Sursa</th>
                        <th style="text-align:left; padding:0.5rem 1rem; color:#4b5563; font-weight:500;">Model</th>
                        <th style="text-align:right; padding:0.5rem 1rem; color:#4b5563; font-weight:500;">Input</th>
                        <th style="text-align:right; padding:0.5rem 1rem; color:#4b5563; font-weight:500;">Output</th>
                        <th style="text-align:right; padding:0.5rem 1rem; color:#4b5563; font-weight:500;">Cost</th>
                        <th style="text-align:left; padding:0.5rem 1rem; color:#4b5563; font-weight:500;">Context</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr style="border-top:1px solid #f3f4f6;">
                            <td style="padding:0.5rem 1rem; color:#6b7280; white-space:nowrap; font-size:0.75rem;">
                                {{ $log->created_at->format('d.m.Y H:i:s') }}
                            </td>
                            <td style="padding:0.5rem 1rem; color:#1f2937; font-size:0.75rem;">{{ $log->source_label }}</td>
                            <td style="padding:0.5rem 1rem;">
                                <span style="font-size:0.75rem; padding:0.125rem 0.375rem; border-radius:0.25rem;
                                    {{ str_contains($log->model, 'sonnet') ? 'background:#f3e8ff; color:#7e22ce;' : 'background:#dbeafe; color:#1d4ed8;' }}">
                                    {{ str_contains($log->model, 'sonnet') ? 'Sonnet' : 'Haiku' }}
                                </span>
                            </td>
                            <td style="padding:0.5rem 1rem; text-align:right; color:#2563eb;">{{ number_format($log->input_tokens, 0, '.', '') }}</td>
                            <td style="padding:0.5rem 1rem; text-align:right; color:#9333ea;">{{ number_format($log->output_tokens, 0, '.', '') }}</td>
                            <td style="padding:0.5rem 1rem; text-align:right; font-family:monospace; font-size:0.75rem; {{ $log->cost_usd > 0.1 ? 'color:#dc2626; font-weight:600;' : 'color:#374151;' }}">
                                ${{ number_format($log->cost_usd, 5) }}
                            </td>
                            <td style="padding:0.5rem 1rem; color:#6b7280; font-size:0.75rem;">
                                @if($log->metadata)
                                    @foreach($log->metadata as $k => $v)
                                        <span style="display:inline-block; background:#f3f4f6; border-radius:0.25rem; padding:0 0.25rem; margin-right:0.25rem;">{{ $k }}: {{ $v }}</span>
                                    @endforeach
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" style="padding:2rem 1rem; text-align:center; color:#9ca3af;">Nicio inregistrare in aceasta perioada</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

<style>
@media (min-width: 768px) {
    .ai-stats-grid { grid-template-columns: repeat(5, 1fr) !important; }
}
@media (min-width: 1024px) {
    .ai-two-col-grid { grid-template-columns: 1fr 1fr !important; }
}
</style>

</x-filament-panels::page>
