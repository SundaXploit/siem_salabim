@props([
    'name',
    'show' => false,
    'maxWidth' => '2xl'
])

@php
$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
][$maxWidth];
$dialogWidth = ['sm:max-w-sm' => '24rem', 'sm:max-w-md' => '28rem', 'sm:max-w-lg' => '32rem', 'sm:max-w-xl' => '36rem', 'sm:max-w-2xl' => '42rem'][$maxWidth];
@endphp

<div
    x-data="{
        show: @js($show),
        previousFocus: null,
        focusables() {
            // All focusable element types...
            let selector = 'a, button, input:not([type=\'hidden\']), textarea, select, details, [tabindex]:not([tabindex=\'-1\'])'
            return [...$el.querySelectorAll(selector)]
                // All non-disabled elements...
                .filter(el => ! el.hasAttribute('disabled') && el.getClientRects().length)
        },
        firstFocusable() { return this.focusables()[0] },
        lastFocusable() { return this.focusables().slice(-1)[0] },
        nextFocusable() { return this.focusables()[this.nextFocusableIndex()] || this.firstFocusable() },
        prevFocusable() { return this.focusables()[this.prevFocusableIndex()] || this.lastFocusable() },
        nextFocusableIndex() { return (this.focusables().indexOf(document.activeElement) + 1) % (this.focusables().length + 1) },
        prevFocusableIndex() { return Math.max(0, this.focusables().indexOf(document.activeElement)) -1 },
    }"
    x-init="$watch('show', value => {
        if (value) {
            previousFocus = document.activeElement;
            document.body.classList.add('overflow-y-hidden');
            $nextTick(() => firstFocusable()?.focus());
        } else {
            document.body.classList.remove('overflow-y-hidden');
            if (previousFocus?.isConnected) previousFocus.focus();
        }
    }); if (show) {
        previousFocus = document.activeElement;
        document.body.classList.add('overflow-y-hidden');
        $nextTick(() => firstFocusable()?.focus());
    }"
    x-on:open-modal.window="$event.detail == '{{ $name }}' ? show = true : null"
    x-on:close-modal.window="$event.detail == '{{ $name }}' ? show = false : null"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false"
    x-on:keydown.tab.prevent="$event.shiftKey || nextFocusable().focus()"
    x-on:keydown.shift.tab.prevent="prevFocusable().focus()"
    x-show="show"
    class="modal-backdrop"
    style="display: {{ $show ? 'grid' : 'none' }};"
>
    <div
        x-show="show"
        class="modal-overlay"
        x-on:click="show = false"
    >
        <div class="modal-overlay-surface"></div>
    </div>

    <div
        x-show="show"
        class="modal-box"
        style="--dialog-max-width: {{ $dialogWidth }}"
        role="dialog"
        aria-modal="true"
        aria-label="{{ $attributes->get('aria-label', 'Konfirmasi tindakan') }}"
    >
        {{ $slot }}
    </div>
</div>
