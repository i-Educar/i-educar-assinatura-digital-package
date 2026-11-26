<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Tests\Feature;

use iEducar\Packages\BuritiAssinaturaDigital\Services\LibreSign\LibreSignClient;
use iEducar\Packages\BuritiAssinaturaDigital\Services\PedidoAssinaturaService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Dois cenários: assinatura única e múltiplas assinaturas.
 * Usa Http::fake (sem Nextcloud). Com LibreSign real: ASSINATURA_TESTE_LIVE=1.
 */
final class AssinaturaUnicaEMultiplaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('assinatura_digital.pedido')) {
            $this->markTestSkipped('Schema assinatura_digital não migrado.');
        }

        config([
            'assinatura.ativo' => true,
            'assinatura.libresign.base_url' => 'http://libresign.test',
            'assinatura.libresign.user' => 'ieducar',
            'assinatura.libresign.password' => 'ieducar',
            'assinatura.libresign.verify_ssl' => false,
            'assinatura.libresign.signer1' => 'signer1',
            'assinatura.libresign.signer1_password' => 'signer1',
            'assinatura.libresign.signer2' => 'signer2',
            'assinatura.libresign.signer2_password' => 'signer2',
            'assinatura.validacao.habilitado' => true,
        ]);
    }

    public function test_assinatura_unica_cria_pedido_e_proxy_sem_storage_local(): void
    {
        $uuid = '11111111-1111-1111-1111-111111111111';
        $signUuid = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $pdfUrl = 'http://libresign.test/remote.php/dav/files/ieducar/signed-unica.pdf';

        Http::fake([
            'libresign.test/status.php' => Http::response(['installed' => true], 200),
            'libresign.test/ocs/v2.php/apps/libresign/api/v1/request-signature' => Http::response([
                'ocs' => ['meta' => ['status' => 'ok', 'statuscode' => 200], 'data' => [
                    'id' => 10, 'uuid' => $uuid, 'status' => 1, 'statusText' => 'able to sign',
                ]],
            ], 200),
            'libresign.test/ocs/v2.php/apps/libresign/api/v1/file/list*' => Http::sequence()
                ->push(['ocs' => ['meta' => ['statuscode' => 200], 'data' => ['data' => [[
                    'id' => 10, 'uuid' => $uuid, 'status' => 1,
                    'signers' => [['sign_request_uuid' => $signUuid, 'signed' => false]],
                    'file' => ['url' => $pdfUrl],
                ]]]]], 200)
                ->push(['ocs' => ['meta' => ['statuscode' => 200], 'data' => ['data' => [[
                    'id' => 10, 'uuid' => $uuid, 'status' => 3, 'statusText' => 'signed',
                    'signers' => [['sign_request_uuid' => $signUuid, 'signed' => true]],
                    'file' => ['url' => $pdfUrl],
                ]]]]], 200),
            'libresign.test/ocs/v2.php/apps/libresign/api/v1/sign/uuid/'.$signUuid => Http::response([
                'ocs' => ['meta' => ['statuscode' => 200], 'data' => ['message' => 'Signed with success']],
            ], 200),
            $pdfUrl => Http::response('%PDF-1.4 unica', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $svc = app(PedidoAssinaturaService::class);
        $pedido = $svc->criarEAssinarTeste(
            'Teste única',
            'unica',
            $svc->pdfDemo('Teste única'),
            [['account' => 'signer1', 'password' => 'signer1', 'displayName' => 'A1']],
            true,
        );

        $this->assertSame('assinado', $pedido['status']);
        $this->assertSame($uuid, $pedido['libresign_uuid']);
        $this->assertNotEmpty($pedido['codigo_validacao'] ?? null, 'Deve registrar na validação pública');

        $bin = $svc->baixarPdfAssinado((int) $pedido['id']);
        $this->assertStringContainsString('%PDF', $bin);

        // Garantia: não há arquivo no storage local do pedido
        $this->assertTrue((bool) config('assinatura.armazenamento.somente_libresign'));
        $meta = $pedido['meta'] ?? [];
        $this->assertTrue((bool) ($meta['somente_libresign'] ?? false));
    }

    public function test_assinatura_multipla_requer_dois_signatarios(): void
    {
        $uuid = '22222222-2222-2222-2222-222222222222';
        $s1 = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
        $s2 = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
        $pdfUrl = 'http://libresign.test/remote.php/dav/files/ieducar/signed-multi.pdf';

        Http::fake([
            'libresign.test/ocs/v2.php/apps/libresign/api/v1/request-signature' => Http::response([
                'ocs' => ['meta' => ['statuscode' => 200], 'data' => [
                    'id' => 20, 'uuid' => $uuid, 'status' => 1,
                ]],
            ], 200),
            'libresign.test/ocs/v2.php/apps/libresign/api/v1/file/list*' => Http::sequence()
                ->push($this->listPayload($uuid, 1, $s1, false, $pdfUrl), 200) // signer1
                ->push($this->listPayload($uuid, 2, $s2, false, $pdfUrl), 200) // signer2
                ->push($this->listPayload($uuid, 3, $s2, true, $pdfUrl), 200), // sync final
            'libresign.test/ocs/v2.php/apps/libresign/api/v1/sign/uuid/'.$s1 => Http::response([
                'ocs' => ['meta' => ['statuscode' => 200], 'data' => ['message' => 'Signed']],
            ], 200),
            'libresign.test/ocs/v2.php/apps/libresign/api/v1/sign/uuid/'.$s2 => Http::response([
                'ocs' => ['meta' => ['statuscode' => 200], 'data' => ['message' => 'Signed']],
            ], 200),
            $pdfUrl => Http::response('%PDF-1.4 multi', 200),
        ]);

        $svc = app(PedidoAssinaturaService::class);
        $pedido = $svc->criarEAssinarTeste(
            'Teste múltipla',
            'multipla',
            $svc->pdfDemo('Teste múltipla'),
            [
                ['account' => 'signer1', 'password' => 'signer1', 'displayName' => 'A1'],
                ['account' => 'signer2', 'password' => 'signer2', 'displayName' => 'A2'],
            ],
            true,
        );

        $this->assertCount(2, $pedido['assinaturas_teste'] ?? []);
        $this->assertTrue(collect($pedido['assinaturas_teste'])->every(fn ($a) => $a['ok'] === true));
        $this->assertSame('assinado', $pedido['status']);
        $this->assertNotEmpty($pedido['codigo_validacao'] ?? null);
    }

    public function test_cliente_detecta_configuracao(): void
    {
        $client = new LibreSignClient('http://x', 'u', 'p');
        $this->assertTrue($client->configurado());
        $this->assertStringContainsString('/ocs/v2.php/apps/libresign/api/v1', $client->apiBase());
    }

    /** @return array<string,mixed> */
    private function listPayload(string $uuid, int $status, string $signUuid, bool $signed, string $pdfUrl): array
    {
        return [
            'ocs' => [
                'meta' => ['statuscode' => 200],
                'data' => [
                    'data' => [[
                        'id' => 20,
                        'uuid' => $uuid,
                        'status' => $status,
                        'statusText' => $status === 3 ? 'signed' : 'able to sign',
                        'signers' => [['sign_request_uuid' => $signUuid, 'signed' => $signed]],
                        'file' => ['url' => $pdfUrl],
                    ]],
                ],
            ],
        ];
    }
}
