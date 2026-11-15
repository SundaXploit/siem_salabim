<?php

namespace App\Services;

use Throwable;

class OpenSearchConnectionError
{
    public static function message(Throwable $exception): string
    {
        for ($depth = 0; $exception && $depth < 5; $depth++, $exception = $exception->getPrevious()) {
            $context = method_exists($exception, 'getHandlerContext') ? $exception->getHandlerContext() : [];
            if (in_array((int) ($context['errno'] ?? 0), [51, 60, 77, 83], true)
                || preg_match('/certificate verify failed|certificate problem|self.signed certificate|peer certificate|certificate.*(hostname|issuer)/i', $exception->getMessage())) {
                return 'Verifikasi sertifikat SSL/TLS Wazuh/OpenSearch gagal. Periksa CA, masa berlaku, dan nama host sertifikat. Untuk diagnosis sementara pada jaringan tepercaya, pilih Verifikasi SSL = false di installer atau Settings → OpenSearch & Alert, lalu uji ulang. Aktifkan kembali true setelah sertifikat diperbaiki.';
            }
        }

        return 'Tidak dapat terhubung ke OpenSearch. Periksa host, port, sertifikat TLS, dan jaringan.';
    }
}
