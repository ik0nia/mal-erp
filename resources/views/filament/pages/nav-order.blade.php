<x-filament-panels::page>

    {{-- SortableJS --}}
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js"></script>

    <div
        x-data="{
            groups: @js($groups),

            initSortable() {
                const groupsList = this.$refs.groupsList;

                Sortable.create(groupsList, {
                    handle: '.group-handle',
                    animation: 150,
                    ghostClass: 'opacity-40',
                    onEnd: () => {
                        const keys = [...groupsList.querySelectorAll('[data-group-key]')]
                            .map(el => el.dataset.groupKey);
                        $wire.updateGroupOrder(keys);
                    }
                });

                this.$nextTick(() => {
                    document.querySelectorAll('[data-items-list]').forEach(list => {
                        Sortable.create(list, {
                            handle: '.item-handle',
                            animation: 150,
                            ghostClass: 'opacity-40',
                            onEnd: () => {
                                const groupKey = list.dataset.itemsList;
                                const keys = [...list.querySelectorAll('[data-item-key]')]
                                    .map(el => el.dataset.itemKey);
                                $wire.updateItemOrder(groupKey, keys);
                            }
                        });
                    });
                });
            }
        }"
        x-init="initSortable()"
        wire:ignore.self
    >

        {{-- Groups list --}}
        <div x-ref="groupsList" class="space-y-3">
            @foreach($groups as $group)
            <div
                data-group-key="{{ $group['key'] }}"
                wire:key="group-{{ $group['key'] }}"
                class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden"
            >
                {{-- Group header --}}
                <div class="flex items-center gap-3 px-4 py-3 bg-gray-50 dark:bg-gray-700/50 border-b border-gray-200 dark:border-gray-700">
                    <button type="button" class="group-handle cursor-grab active:cursor-grabbing text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 touch-none">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                        </svg>
                    </button>
                    <span class="font-semibold text-gray-900 dark:text-white text-sm uppercase tracking-wide">
                        {{ $group['label'] }}
                    </span>
                    <span class="ml-auto text-xs text-gray-400 dark:text-gray-500">
                        {{ count($group['items']) }} {{ count($group['items']) === 1 ? 'element' : 'elemente' }}
                    </span>
                </div>

                {{-- Items list --}}
                <div
                    data-items-list="{{ $group['key'] }}"
                    class="divide-y divide-gray-100 dark:divide-gray-700/50 px-2 py-2 min-h-[48px]"
                >
                    @forelse($group['items'] as $item)
                    <div
                        data-item-key="{{ $item['key'] }}"
                        wire:key="item-{{ $item['key'] }}"
                        class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700/30 group/item"
                    >
                        <button type="button" class="item-handle cursor-grab active:cursor-grabbing text-gray-300 hover:text-gray-500 dark:hover:text-gray-400 touch-none flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9h16.5m-16.5 6.75h16.5"/>
                            </svg>
                        </button>
                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ $item['label'] }}</span>
                    </div>
                    @empty
                    <p class="px-3 py-2 text-xs text-gray-400 italic">Niciun element</p>
                    @endforelse
                </div>
            </div>
            @endforeach
        </div>

        {{-- Save button --}}
        <div class="mt-6 flex justify-start">
            <button
                wire:click="save"
                wire:loading.attr="disabled"
                type="button"
                class="fi-btn fi-btn-size-md relative grid-flow-col items-center justify-center gap-1.5 font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-primary fi-color-primary fi-color-custom bg-custom-600 text-white shadow-sm hover:bg-custom-500 focus-visible:ring-custom-500/50 dark:bg-custom-500 dark:hover:bg-custom-400 inline-grid px-3 py-2"
                style="--c-400:var(--primary-400);--c-500:var(--primary-500);--c-600:var(--primary-600);"
            >
                <svg wire:loading wire:target="save" class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4z"></path>
                </svg>
                <svg wire:loading.remove wire:target="save" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                </svg>
                <span>Salvează ordinea</span>
            </button>
        </div>

    </div>

</x-filament-panels::page>
