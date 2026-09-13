@php
    [$class, $label] = match ($status) {
        'lobby' => ['b-amber', 'لوبي'],
        'playing' => ['b-green', 'تلعب'],
        'finished' => ['b-blue', 'انتهت'],
        'abandoned' => ['', 'متروكة'],
        default => ['', $status],
    };
@endphp
<span class="badge {{ $class }}">{{ $label }}</span>
