{{-- The sign-in pages' language links: Türkçe · English. --}}
<nav class="mt-4 flex items-center justify-center gap-2 text-2xs text-muted" aria-label="{{ __('Dil') }}">
    @foreach (\App\Support\Locales::all() as $code => $name)
        @if (! $loop->first)<span aria-hidden="true">·</span>@endif
        @if ($code === app()->getLocale())
            <span class="font-medium text-fg" aria-current="true" lang="{{ $code }}">{{ $name }}</span>
        @else
            <a href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}" class="hover:text-fg hover:underline" lang="{{ $code }}">{{ $name }}</a>
        @endif
    @endforeach
</nav>
