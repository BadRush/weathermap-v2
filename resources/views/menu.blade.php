<li class="dropdown-submenu">
    <a href="{{ route('weathermap.index') }}">
        <i class="fa fa-sitemap fa-fw fa-lg" aria-hidden="true"></i>
        {{ __('weathermap::weathermap.title') }}
    </a>
    @if(!empty($maps))
        <ul class="dropdown-menu">
            <li><a href="{{ route('weathermap.index') }}">{{ __('weathermap::weathermap.title') }} &mdash; All</a></li>
            <li role="separator" class="divider"></li>
            @foreach($maps as $wmMap)
                <li>
                    <a href="{{ route('weathermap.maps.show', $wmMap) }}">{{ $wmMap->name }}</a>
                </li>
            @endforeach
        </ul>
    @endif
</li>
