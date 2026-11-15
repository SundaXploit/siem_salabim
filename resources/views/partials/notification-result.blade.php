@php
    $state = $state ?? null;
    $success = $state === 'sent' || ($state === null && (int) $status === 200);
    $label = match ($state) {
        'pending' => 'Menunggu pengiriman',
        'not_sent' => 'Belum dikirim',
        'sending', 'unconfirmed' => 'Belum dikonfirmasi',
        'cancelled' => 'Dibatalkan',
        'daily_limited' => 'Batas harian tercapai',
        'sent' => 'SENT',
        'partial' => 'Sebagian evidence gagal',
        default => $success ? 'SENT' : 'FAILED ('.($status ?? 'tanpa respons').')',
    };
@endphp
<span class="badge {{ $success ? 'badge-sent' : (in_array($state, ['pending', 'sending', 'unconfirmed', 'cancelled', 'daily_limited'], true) ? 'badge-new' : 'badge-failed') }}">{{ $label }}</span>
