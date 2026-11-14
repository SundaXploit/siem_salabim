<?php

namespace App\Http\Controllers;

use App\Models\PermanentAlertIgnore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class PermanentAlertIgnoreController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate(['rule_id' => ['required', 'string', 'regex:/\A[0-9]{1,64}\z/']], [
            'rule_id.required' => 'Masukkan Rule ID yang akan di-ignore.',
            'rule_id.string' => 'Rule ID harus berupa 1–64 digit angka.',
            'rule_id.regex' => 'Rule ID harus berupa 1–64 digit angka, tanpa wildcard atau pemisah.',
        ]);
        $this->withRuleLock(function () use ($data, $request): void {
            if (PermanentAlertIgnore::where('rule_id', $data['rule_id'])->exists()) {
                throw ValidationException::withMessages(['rule_id' => 'Rule ID ini sudah ada dalam daftar ignore permanen.']);
            }
            PermanentAlertIgnore::create([
                'rule_id' => $data['rule_id'],
                'created_by_user_id' => $request->user()->id,
                'created_by_name' => $request->user()->name,
            ]);
        });

        return redirect()->route('settings.index', ['section' => 'permanent-ignore'])
            ->with('settings_section', 'permanent-ignore')->with('success', 'Ignore permanen ditambahkan. Alert baru dengan Rule ID '.$data['rule_id'].' tidak akan diimpor.');
    }

    public function destroy(PermanentAlertIgnore $ignoreRule)
    {
        $this->withRuleLock(fn () => $ignoreRule->delete());

        return redirect()->route('settings.index', ['section' => 'permanent-ignore'])
            ->with('settings_section', 'permanent-ignore')->with('success', 'Aturan dihapus. Rule ID tersebut dapat diimpor kembali pada fetch berikutnya.');
    }

    private function withRuleLock(callable $operation): void
    {
        try {
            Cache::lock(PermanentAlertIgnore::WRITE_LOCK, 60)->block(10, $operation);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['rule_id' => 'Satu batch alert sedang diproses. Coba simpan aturan kembali sesaat lagi.']);
        }
    }
}
