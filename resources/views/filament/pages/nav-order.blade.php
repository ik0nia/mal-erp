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
        <div x-ref="groupsList" style="display:flex;flex-direction:column;gap:12px">
            @foreach($groups as $group)
            <div
                data-group-key="{{ $group['key'] }}"
                wire:key="group-{{ $group['key'] }}"
                style="border-radius:12px;border:1px solid #e5e7eb;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,0.05);overflow:hidden"
            >
                {{-- Group header --}}
                <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:#f9fafb;border-bottom:1px solid #e5e7eb">
                    <button type="button" class="group-handle" style="cursor:grab;color:#9ca3af;touch-action:none;background:none;border:none;padding:0"
                        onmouseover="this.style.color='#4b5563'" onmouseout="this.style.color='#9ca3af'">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:20px;height:20px">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                        </svg>
                    </button>
                    <span style="font-weight:600;color:#111827;font-size:14px;text-transform:uppercase;letter-spacing:0.05em">
                        {{ $group['label'] }}
                    </span>
                    <span style="margin-left:auto;font-size:12px;color:#9ca3af">
                        {{ count($group['items']) }} {{ count($group['items']) === 1 ? 'element' : 'elemente' }}
                    </span>
                </div>

                {{-- Items list --}}
                <div
                    data-items-list="{{ $group['key'] }}"
                    style="padding:8px;min-height:48px"
                >
                    @forelse($group['items'] as $item)
                    <div
                        data-item-key="{{ $item['key'] }}"
                        wire:key="item-{{ $item['key'] }}"
                        style="display:flex;align-items:center;gap:12px;padding:8px 12px;border-radius:8px;border-bottom:1px solid #f3f4f6"
                        onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background=''"
                    >
                        <button type="button" class="item-handle" style="cursor:grab;color:#d1d5db;touch-action:none;flex-shrink:0;background:none;border:none;padding:0"
                            onmouseover="this.style.color='#6b7280'" onmouseout="this.style.color='#d1d5db'">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px;height:16px">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9h16.5m-16.5 6.75h16.5"/>
                            </svg>
                        </button>
                        <span style="font-size:14px;color:#374151">{{ $item['label'] }}</span>
                    </div>
                    @empty
                    <p style="padding:8px 12px;font-size:12px;color:#9ca3af;font-style:italic">Niciun element</p>
                    @endforelse
                </div>
            </div>
            @endforeach
        </div>

        {{-- Save button --}}
        <div style="margin-top:24px;display:flex;justify-content:flex-start">
            <button
                wire:click="save"
                wire:loading.attr="disabled"
                type="button"
                class="fi-btn fi-btn-size-md relative grid-flow-col items-center justify-center gap-1.5 font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-primary fi-color-primary fi-color-custom bg-custom-600 text-white shadow-sm hover:bg-custom-500 focus-visible:ring-custom-500/50 dark:bg-custom-500 dark:hover:bg-custom-400 inline-grid px-3 py-2"
                style="--c-400:var(--primary-400);--c-500:var(--primary-500);--c-600:var(--primary-600);"
            >
                <svg wire:loading wire:target="save" style="animation:spin 1s linear infinite;width:16px;height:16px" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle style="opacity:0.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path style="opacity:0.75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4z"></path>
                </svg>
                <svg wire:loading.remove wire:target="save" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px;height:16px">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                </svg>
                <span>Salvează ordinea</span>
            </button>
        </div>

    </div>

</x-filament-panels::page>
