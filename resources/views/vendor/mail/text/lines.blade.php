@props([
    'lines' => [],
])
@foreach ($lines as $line)
{{ $line }}

@endforeach
