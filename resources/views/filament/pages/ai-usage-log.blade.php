<x-filament-panels::page>

    {{-- Selector perioadă --}}
    <div class="flex items-center gap-3 mb-6">
        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Perioadă:</span>
        @foreach($this->getPeriodOptions() as $val => $label)
            <button
                wire:click="$set('period', '{{ $val }}')"
                class="px-3 py-1 rounded-full text-sm font-medium transition
                    {{ $period == $val
                        ? 'bg-primary-600 text-white'
                        : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600' }}"
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
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border border-gray-200 dark:border-gray-700">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Cost total</p>
            <p class="text-2xl font-bold text-red-600 dark:text-red-400">${{ number_format($stats['total_cost'], 4) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border border-gray-200 dark:border-gray-700">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Medie / zi</p>
            <p class="text-2xl font-bold text-orange-600 dark:text-orange-400">${{ number_format($stats['avg_per_day'], 4) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border border-gray-200 dark:border-gray-700">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Apeluri API</p>
            <p class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ number_format($stats['call_count'], 0, '.', '') }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border border-gray-200 dark:border-gray-700">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Tokeni input</p>
            <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ number_format($stats['total_input'], 0, '.', '') }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border border-gray-200 dark:border-gray-700">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Tokeni output</p>
            <p class="text-2xl font-bold text-purple-600 dark:text-purple-400">{{ number_format($stats['total_output'], 0, '.', '') }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

        {{-- Cost pe sursă --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">Cost pe sursă</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="text-left px-4 py-2 text-gray-600 dark:text-gray-400 font-medium">Sursă</th>
                            <th class="text-left px-4 py-2 text-gray-600 dark:text-gray-400 font-medium">Model</th>
                            <th class="text-right px-4 py-2 text-gray-600 dark:text-gray-400 font-medium">Apeluri</th>
                            <th class="text-right px-4 py-2 text-gray-600 dark:text-gray-400 font-medium">Cost USD</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($bySource as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                                <td class="px-4 py-2 text-gray-800 dark:text-gray-200">{{ $row->source_label }}</td>
                                <td class="px-4 py-2">
                                    <span class="text-xs px-2 py-0.5 rounded-full
                                        {{ str_contains($row->model, 'sonnet') ? 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' }}">
                                        {{ str_contains($row->model, 'sonnet') ? 'Sonnet' : 'Haiku' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right text-gray-600 dark:text-gray-400">{{ number_format($row->calls, 0, '.', '') }}</td>
                                <td class="px-4 py-2 text-right font-semibold {{ $row->cost_usd > 1 ? 'text-red-600 dark:text-red-400' : 'text-gray-800 dark:text-gray-200' }}">
                                    ${{ number_format($row->cost_usd, 4) }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">Nicio înregistrare</td></tr>
                        @endforelse
                    </tbody>
                    @if($bySource->isNotEmpty())
                    <tfoot class="bg-gray-50 dark:bg-gray-900 border-t-2 border-gray-200 dark:border-gray-600">
                        <tr>
                            <td colspan="3" class="px-4 py-2 font-semibold text-gray-700 dark:text-gray-300">TOTAL</td>
                            <td class="px-4 py-2 text-right font-bold text-red-600 dark:text-red-400">
                                ${{ number_format($bySource->sum('cost_usd'), 4) }}
                            </td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- Cost pe zi --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">Cost pe zi</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="text-left px-4 py-2 text-gray-600 dark:text-gray-400 font-medium">Data</th>
                            <th class="text-right px-4 py-2 text-gray-600 dark:text-gray-400 font-medium">Apeluri</th>
                            <th class="text-right px-4 py-2 text-gray-600 dark:text-gray-400 font-medium">Input tok.</th>
                            <th class="text-right px-4 py-2 text-gray-600 dark:text-gray-400 font-medium">Output tok.</th>
                            <th class="text-right px-4 py-2 text-gray-600 dark:text-gray-400 font-medium">Cost USD</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($byDay as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                                <td class="px-4 py-2 font-medium text-gray-800 dark:text-gray-200">{{ $row->day }}</td>
                                <td class="px-4 py-2 text-right text-gray-600 dark:text-gray-400">{{ number_format($row->calls, 0, '.', '') }}</td>
                                <td class="px-4 py-2 text-right text-blue-600 dark:text-blue-400">{{ number_format($row->input_tokens, 0, '.', '') }}</td>
                                <td class="px-4 py-2 text-right text-purple-600 dark:text-purple-400">{{ number_format($row->output_tokens, 0, '.', '') }}</td>
                                <td class="px-4 py-2 text-right font-semibold {{ $row->cost_usd > 1 ? 'text-red-600 dark:text-red-400' : 'text-gray-800 dark:text-gray-200' }}">
                                    ${{ number_format($row->cost_usd, 4) }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">Nicio înregistrare</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    {{-- Log detaliat --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">Log detaliat (ultimele 200 apeluri)</h3>
            <span class="text-xs text-gray-500">{{ $logs->count() }} înregistrări</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th class="text-left px-4 py-2 text-gray-600 dark:text-gray-400 font-medium">Data / Ora</th>
                        <th class="text-left px-4 py-2 text-gray-600 dark:text-gray-400 font-medium">Sursă</th>
                        <th class="text-left px-4 py-2 text-gray-600 dark:text-gray-400 font-medium">Model</th>
                        <th class="text-right px-4 py-2 text-gray-600 dark:text-gray-400 font-medium">Input</th>
                        <th class="text-right px-4 py-2 text-gray-600 dark:text-gray-400 font-medium">Output</th>
                        <th class="text-right px-4 py-2 text-gray-600 dark:text-gray-400 font-medium">Cost</th>
                        <th class="text-left px-4 py-2 text-gray-600 dark:text-gray-400 font-medium">Context</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($logs as $log)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                            <td class="px-4 py-2 text-gray-500 dark:text-gray-400 whitespace-nowrap text-xs">
                                {{ $log->created_at->format('d.m.Y H:i:s') }}
                            </td>
                            <td class="px-4 py-2 text-gray-800 dark:text-gray-200 text-xs">{{ $log->source_label }}</td>
                            <td class="px-4 py-2">
                                <span class="text-xs px-1.5 py-0.5 rounded
                                    {{ str_contains($log->model, 'sonnet') ? 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' }}">
                                    {{ str_contains($log->model, 'sonnet') ? 'Sonnet' : 'Haiku' }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right text-blue-600 dark:text-blue-400">{{ number_format($log->input_tokens, 0, '.', '') }}</td>
                            <td class="px-4 py-2 text-right text-purple-600 dark:text-purple-400">{{ number_format($log->output_tokens, 0, '.', '') }}</td>
                            <td class="px-4 py-2 text-right font-mono text-xs {{ $log->cost_usd > 0.1 ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-700 dark:text-gray-300' }}">
                                ${{ number_format($log->cost_usd, 5) }}
                            </td>
                            <td class="px-4 py-2 text-gray-500 dark:text-gray-400 text-xs">
                                @if($log->metadata)
                                    @foreach($log->metadata as $k => $v)
                                        <span class="inline-block bg-gray-100 dark:bg-gray-700 rounded px-1 mr-1">{{ $k }}: {{ $v }}</span>
                                    @endforeach
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Nicio înregistrare în această perioadă</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</x-filament-panels::page>
