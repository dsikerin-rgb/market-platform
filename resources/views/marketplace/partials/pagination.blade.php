@if ($paginator->hasPages())
    <nav class="mp-pagination" role="navigation" aria-label="Pagination Navigation">
        <div class="mp-pagination__meta">
            Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}
        </div>

        <div class="mp-pagination__list">
            @if ($paginator->onFirstPage())
                <span class="mp-pagination__item is-disabled" aria-disabled="true">&lsaquo;</span>
            @else
                <a class="mp-pagination__item" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page">&lsaquo;</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="mp-pagination__item is-disabled" aria-disabled="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="mp-pagination__item is-active" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="mp-pagination__item" href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a class="mp-pagination__item" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page">&rsaquo;</a>
            @else
                <span class="mp-pagination__item is-disabled" aria-disabled="true">&rsaquo;</span>
            @endif
        </div>
    </nav>
@endif
