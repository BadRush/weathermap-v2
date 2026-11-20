@extends('weathermap::layout')

@section('weathermap-content')
    @foreach($assets['css'] as $cssUrl)
        <link rel="stylesheet" href="{{ $cssUrl }}">
    @endforeach

    <div
        id="weathermap-viewer-root"
        data-map-id="{{ $map->id }}"
        data-map-name="{{ $map->name }}"
        data-can-edit="{{ $canEdit ? '1' : '0' }}"
    ></div>

    @foreach($assets['js'] as $jsUrl)
        <script type="module" src="{{ $jsUrl }}"></script>
    @endforeach
@endsection
