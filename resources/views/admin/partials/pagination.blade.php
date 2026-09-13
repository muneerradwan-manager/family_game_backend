{{-- ترقيم صفحات بلا Tailwind: قالب Livewire الافتراضي يعتمد أصنافه. --}}
@if ($paginator->hasPages())
    <nav class="pagination" aria-label="الصفحات">
        <button type="button" class="page-link" wire:click="previousPage('{{ $paginator->getPageName() }}')"
                @disabled($paginator->onFirstPage())>السابق</button>

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="muted">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    <button type="button"
                            class="page-link {{ $page == $paginator->currentPage() ? 'is-current' : '' }}"
                            wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')">{{ $page }}</button>
                @endforeach
            @endif
        @endforeach

        <button type="button" class="page-link" wire:click="nextPage('{{ $paginator->getPageName() }}')"
                @disabled(! $paginator->hasMorePages())>التالي</button>

        <span class="muted small">{{ $paginator->total() }} نتيجة</span>
    </nav>
@endif
