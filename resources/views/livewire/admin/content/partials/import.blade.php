{{-- رفع ملف JSONL لبنك — مشترك بين الأسئلة والمشاهد. --}}
<div class="card">
    <div class="card-head"><h2>📥 استيراد من ملف</h2></div>
    <form class="card-body stack" wire:submit="import">
        <p class="muted small" style="margin:0">{!! $formatHint !!}</p>
        <div class="row">
            <input type="file" wire:model="importFile" accept=".jsonl,.json">
            <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="importFile,import">استيراد</button>
            <span wire:loading wire:target="importFile" class="muted small">عم يرفع…</span>
            <span wire:loading wire:target="import" class="muted small">عم يستورد…</span>
        </div>
        @error('importFile') <span class="error">{{ $message }}</span> @enderror
        @if ($importOutput !== '')
            <pre class="json">{{ $importOutput }}</pre>
        @endif
    </form>
</div>
