@props(['messages'])

@if ($messages)
    <ul role="alert" {{ $attributes->merge(['class' => 'form-error']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
