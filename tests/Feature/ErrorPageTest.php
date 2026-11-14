<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    public function test_html_error_pages_preserve_status_and_show_the_matching_ui(): void
    {
        config(['app.debug' => false]);
        Route::get('/__test-error/{status}', fn (int $status) => abort($status));

        foreach ([403, 404, 419, 429, 500, 503] as $status) {
            $this->get('/__test-error/'.$status)
                ->assertStatus($status)
                ->assertSee('data-ui-view="errors/'.$status.'"', false)
                ->assertSee('SIEM Salabim')
                ->assertSee('Kembali ke halaman utama')
                ->assertDontSee('Stack trace');
        }
    }

    public function test_json_errors_keep_their_json_response(): void
    {
        config(['app.debug' => false]);
        Route::get('/__test-error/403', fn () => abort(403));

        $this->getJson('/__test-error/403')
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message'])
            ->assertDontSee('<!DOCTYPE html>', false);
    }
}
