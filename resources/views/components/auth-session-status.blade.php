@props(['status'])

@if ($status)
    <div role="status" {{ $attributes->merge(['class' => 'account-save-status']) }}>
        {{ $status }}
    </div>
@endif
