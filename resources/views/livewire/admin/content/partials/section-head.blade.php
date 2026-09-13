{{-- رأس بطاقة إعداد: عنوان + شارة «معدّل» + زر الرجوع للافتراضي. --}}
<div class="card-head">
    <h2>{{ $title }}</h2>
    <div class="row">
        @if ($overridden)
            <span class="badge b-amber">معدّل</span>
            <button type="button" class="btn btn-sm btn-ghost" wire:click="resetSection('{{ $key }}')"
                    wire:confirm="إرجاع «{{ $title }}» للافتراضي؟">رجوع للافتراضي</button>
        @else
            <span class="badge">افتراضي</span>
        @endif
    </div>
</div>
