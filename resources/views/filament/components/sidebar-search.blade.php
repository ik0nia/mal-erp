{{-- Căutare rapidă în meniul lateral: filtrează itemele + deschide grupurile la tastare --}}
<div style="padding:4px 8px 8px">
    <div style="position:relative">
        <svg style="position:absolute;left:9px;top:50%;transform:translateY(-50%);width:14px;height:14px;color:#9ca3af;pointer-events:none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
        <input id="mlMenuSearch" type="text" placeholder="Caută în meniu…" autocomplete="off"
            style="width:100%;padding:6px 10px 6px 30px;font-size:13px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;outline:none"
            onfocus="this.style.borderColor='#c8102e';this.style.background='#fff'"
            onblur="this.style.borderColor='#e5e7eb';this.style.background='#f9fafb'">
    </div>
</div>
<style>
    .ml-menu-searching .fi-sidebar-group-items { display: flex !important; }
    .ml-menu-hidden { display: none !important; }
</style>
<script>
(function () {
    function norm(s) {
        return (s || '').toLowerCase()
            .replace(/[ăâ]/g, 'a').replace(/[îí]/g, 'i')
            .replace(/[șş]/g, 's').replace(/[țţ]/g, 't');
    }
    function init() {
        var input = document.getElementById('mlMenuSearch');
        if (!input || input.dataset.mlInit) return;
        input.dataset.mlInit = '1';
        input.addEventListener('input', function () {
            var q = norm(input.value.trim());
            var sidebar = input.closest('.fi-sidebar-nav') || document;
            sidebar.classList.toggle('ml-menu-searching', q !== '');
            sidebar.querySelectorAll('.fi-sidebar-item').forEach(function (item) {
                var hit = q === '' || norm(item.textContent).indexOf(q) !== -1;
                item.classList.toggle('ml-menu-hidden', !hit);
            });
            sidebar.querySelectorAll('.fi-sidebar-group').forEach(function (group) {
                var any = group.querySelector('.fi-sidebar-item:not(.ml-menu-hidden)');
                group.classList.toggle('ml-menu-hidden', q !== '' && !any);
            });
        });
        // Ctrl+K / Cmd+K focusează căutarea
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                input.focus();
                input.select();
            }
        });
    }
    document.addEventListener('DOMContentLoaded', init);
    document.addEventListener('livewire:navigated', init);
    init();
})();
</script>
