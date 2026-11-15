<?php

use App\Models\Alert;
use App\Services\SensitiveDataCipher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('configurations')->where('key', 'telegram_destinations')->first();
        $destinations = $row ? json_decode($row->value, true) : null;
        if (! is_array($destinations)) {
            return;
        }

        $names = collect($destinations)->flatMap(fn ($destination) => $destination['agents'] ?? [])->filter(fn ($name) => is_string($name))->unique()->all();
        $identities = [];
        if ($names !== []) {
            DB::table('alerts')->whereIn('agent_name', $names)->orderBy('id')->chunkById(500, function ($alerts) use (&$identities): void {
                foreach ($alerts as $alert) {
                    $id = data_get(SensitiveDataCipher::decryptJson($alert->raw_data), 'agent.id');
                    $key = Alert::agentScopeKey((string) $alert->source_cluster, $id);
                    if ($key) {
                        $identities[$alert->agent_name][$key] = [
                            'key' => $key, 'id' => (string) $id, 'name' => $alert->agent_name, 'source_cluster' => $alert->source_cluster,
                        ];
                    }
                }
            });
        }
        foreach ($destinations as &$destination) {
            if (isset($destination['agent_keys'])) {
                continue;
            }
            $destination['agent_keys'] = $destination['agent_details'] = $destination['unresolved_agents'] = [];
            foreach ($destination['agents'] ?? [] as $name) {
                $matches = $identities[$name] ?? [];
                if (count($matches) === 1) {
                    $agent = reset($matches);
                    $destination['agent_keys'][] = $agent['key'];
                    $destination['agent_details'][] = $agent;
                } else {
                    // Names that are absent or ambiguous never become routing authority.
                    $destination['unresolved_agents'][] = $name;
                }
            }
            $destination['agent_keys'] = array_values(array_unique($destination['agent_keys']));
            unset($destination['agents']);
        }
        unset($destination);
        DB::table('configurations')->where('id', $row->id)->update(['value' => json_encode($destinations, JSON_THROW_ON_ERROR)]);
    }

    public function down(): void
    {
        // Deliberately retain ID-based mappings: rollback must not re-enable name routing.
    }
};
