<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Services;

use iEducar\Packages\BuritiAssinaturaDigital\Services\LibreSign\LibreSignClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Porta única para qualquer módulo enviar PDF (1..N signatários) ao LibreSign.
 */
final class AssinaturaOrquestrador
{
    public function __construct(
        private readonly LibreSignClient $libreSign = new LibreSignClient(),
        private readonly PedidoAssinaturaService $pedidos = new PedidoAssinaturaService(),
        private readonly DocumentoValidacaoAssinatura $validacao = new DocumentoValidacaoAssinatura(),
        private readonly UsuarioLibreSign $usuarios = new UsuarioLibreSign(),
    ) {}

    public function disponivel(): bool
    {
        return (bool) config('assinatura.ativo', true)
            && $this->libreSign->configurado();
    }

    /**
     * Cria pedido LibreSign a partir de um PDF já gerado.
     *
     * @param  list<array{account?:string,email?:string,displayName?:string,nome?:string,cargo?:string,password?:string,login?:string,user_id?:int}>  $signers
     * @param  array<string,mixed>  $meta
     * @return array{
     *   pedido:array<string,mixed>,
     *   cenario:string,
     *   assinaturas_visuais:list<array<string,mixed>>,
     *   codigo_validacao:?string,
     *   url_validacao:?string
     * }
     */
    public function solicitar(
        string $titulo,
        string $pdfBinary,
        array $signers,
        array $meta = [],
        string $fileName = 'documento.pdf',
        bool $registrarValidacaoImediata = true,
    ): array {
        if (! $this->disponivel()) {
            throw new RuntimeException('Assinatura digital / LibreSign não configurado.');
        }

        $normalizados = $this->normalizarSigners($signers);
        if ($normalizados === []) {
            throw new RuntimeException('Informe ao menos um signatário (account ou email).');
        }

        $cenario = count($normalizados) === 1 ? 'unica' : 'multipla';
        $created = $this->libreSign->requestSignature(
            $titulo,
            $pdfBinary,
            array_map(static fn (array $s): array => array_filter([
                'account' => $s['account'] ?? null,
                'email' => $s['email'] ?? null,
                'displayName' => $s['displayName'] ?? null,
            ]), $normalizados),
            $fileName,
        );

        $pedidoId = (int) DB::table('assinatura_digital.pedido')->insertGetId([
            'titulo' => $titulo,
            'cenario' => $cenario,
            'status' => 'aguardando',
            'status_libresign' => $created['status'],
            'libresign_file_id' => (string) ($created['id'] ?? ''),
            'libresign_uuid' => $created['uuid'] !== '' ? $created['uuid'] : null,
            'signatarios' => json_encode($normalizados, JSON_UNESCAPED_UNICODE),
            'meta' => json_encode(array_merge(['somente_libresign' => true], $meta), JSON_UNESCAPED_UNICODE),
            'solicitante_id' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('assinatura_digital.evento')->insert([
            'pedido_id' => $pedidoId,
            'tipo' => 'criado',
            'mensagem' => 'Pedido enviado ao LibreSign via orquestrador.',
            'payload' => json_encode($created, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);

        $pedido = $this->pedidos->obter($pedidoId);
        $codigo = trim((string) ($meta['codigo_validacao_exportacao'] ?? ''));
        $url = trim((string) ($meta['url_validacao_exportacao'] ?? ''));
        $pub = app(ValidacaoPublicaService::class);

        if ($codigo !== '' || $url !== '') {
            if ($codigo !== '') {
                $url = $pub->urlPublica($codigo);
            }
            DB::table('assinatura_digital.pedido')->where('id', $pedidoId)->update([
                'codigo_validacao' => $codigo !== '' ? $codigo : null,
                'url_validacao' => $url !== '' ? $url : null,
                'updated_at' => now(),
            ]);
            $pedido = $this->pedidos->obter($pedidoId);
        } elseif ($registrarValidacaoImediata) {
            $reg = $this->validacao->registrar($pedido);
            if ($reg !== null) {
                $codigo = $reg['codigo'];
                $url = $pub->urlPublica($codigo);
                DB::table('assinatura_digital.pedido')->where('id', $pedidoId)->update([
                    'codigo_validacao' => $codigo,
                    'url_validacao' => $url,
                    'updated_at' => now(),
                ]);
                $pedido = $this->pedidos->obter($pedidoId);
            }
        }

        $codigo = $codigo !== '' ? $codigo : null;
        $url = $url !== '' ? $url : null;

        $urlLibreSign = $this->libreSign->urlValidacao((string) ($created['uuid'] ?? ''));

        $visuais = [];
        foreach ($normalizados as $s) {
            $base = [
                'nome' => (string) ($s['displayName'] ?? $s['nome'] ?? $s['account'] ?? $s['email'] ?? ''),
                'cargo' => (string) ($s['cargo'] ?? ''),
                'quando' => null,
                'account' => $s['account'] ?? null,
                'email' => $s['email'] ?? null,
                'login' => $s['login'] ?? null,
                'user_id' => $s['user_id'] ?? null,
                'status' => 'Aguardando assinatura digital',
            ];
            $codigoSigner = trim((string) ($s['codigo_validacao'] ?? ''));
            if ($codigoSigner === '') {
                $regSigner = $this->validacao->registrar([
                    'id' => $pedidoId,
                    'titulo' => $titulo.' — '.(string) ($base['cargo'] !== '' ? $base['cargo'] : $base['nome']),
                    'cenario' => $cenario,
                    'libresign_uuid' => $created['uuid'] ?? '',
                    'libresign_file_id' => (string) ($created['id'] ?? ''),
                    'signatarios' => [$s],
                ]);
                if ($regSigner !== null) {
                    $codigoSigner = $regSigner['codigo'];
                }
            }
            if ($codigoSigner !== '') {
                $base['codigo_validacao'] = $codigoSigner;
            }
            // Link sob a assinatura: portal i-Educar (cidadão) + LibreSign (técnico).
            $portal = $codigoSigner !== '' ? $pub->urlPublica($codigoSigner) : '';
            if ($portal !== '') {
                $base['url_validacao'] = $portal;
                $base['url_validacao_portal'] = $portal;
            }
            if ($urlLibreSign !== '') {
                $base['url_validacao_libresign'] = $urlLibreSign;
                if ($portal === '') {
                    $base['url_validacao'] = $urlLibreSign;
                }
            }
            $visuais[] = $base;
        }

        $this->persistirVisuais($pedidoId, $pedido, $visuais);
        $pedido = $this->pedidos->obter($pedidoId);

        // Igual ao CLI: se a ação no i-Educar já confirmou a assinatura (ou há senhas),
        // conclui no LibreSign para não ficar eterno em "aguardando".
        $assinarAgora = array_key_exists('assinar_imediatamente', $meta)
            ? (bool) $meta['assinar_imediatamente']
            : (bool) config('assinatura.assinar_imediatamente', true);

        // Fila por usuário: documentos gerados por um perfil e assinados por outros
        // ficam pendentes até cada signatário confirmar no painel /assinatura-digital/pendencias.
        if ($assinarAgora
            && empty($meta['forcar_assinatura_imediata'])
            && (bool) config('assinatura.fila_usuario', true)
            && $this->temSignatariosDeOutrosUsuarios($normalizados, Auth::id())
        ) {
            $assinarAgora = false;
        }

        if ($assinarAgora && ($created['uuid'] ?? '') !== '') {
            $comSenha = $this->enriquecerSenhas($normalizados);
            if ($this->todosComSenha($comSenha)) {
                try {
                    $this->pedidos->assinarTodos((string) $created['uuid'], $comSenha);
                    $this->pedidos->sincronizarAposAssinatura($pedidoId, (string) $created['uuid']);
                    $pedido = $this->pedidos->obter($pedidoId);
                    $visuais = $this->atualizarVisuaisAposSync($pedido, $visuais);
                } catch (Throwable $e) {
                    report($e);
                    DB::table('assinatura_digital.evento')->insert([
                        'pedido_id' => $pedidoId,
                        'tipo' => 'assinatura_imediata_falhou',
                        'mensagem' => $e->getMessage(),
                        'payload' => json_encode([], JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                    ]);
                }
            } else {
                // Sem senha dos signatários: ainda sincroniza status real do LibreSign.
                try {
                    $this->pedidos->sincronizarAposAssinatura($pedidoId, (string) $created['uuid']);
                    $pedido = $this->pedidos->obter($pedidoId);
                    $visuais = $this->atualizarVisuaisAposSync($pedido, $visuais);
                } catch (Throwable) {
                    //
                }
            }
        } else {
            try {
                app(PendenciasAssinaturaService::class)->notificarSignatarios($pedido);
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Módulo de origem já confirmou a etapa (ex.: nota almox): garante slots na lista
        // "Já assinei" mesmo se o LibreSign atrasar o flag signed.
        if (! empty($meta['forcar_assinatura_imediata'])) {
            $pedido = $this->pedidos->obter($pedidoId);
            $metaAtual = is_array($pedido['meta'] ?? null) ? $pedido['meta'] : [];
            $visuais = is_array($metaAtual['assinaturas_visuais'] ?? null) && $metaAtual['assinaturas_visuais'] !== []
                ? $metaAtual['assinaturas_visuais']
                : $visuais;
            $visuais = $this->garantirVisuaisAssinadosLocalmente($visuais, Auth::id());
            $this->persistirVisuais($pedidoId, $pedido, $visuais);
            $pedido = $this->pedidos->obter($pedidoId);
        }

        return [
            'pedido' => $pedido,
            'cenario' => $cenario,
            'assinaturas_visuais' => $visuais,
            'codigo_validacao' => $codigo ?? ($pedido['codigo_validacao'] ?? null),
            'url_validacao' => $url ?? ($pedido['url_validacao'] ?? null),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $signers
     */
    private function temSignatariosDeOutrosUsuarios(array $signers, mixed $solicitanteId): bool
    {
        $solicitante = (int) ($solicitanteId ?? 0);
        foreach ($signers as $s) {
            if (! is_array($s)) {
                continue;
            }
            $uid = (int) ($s['user_id'] ?? 0);
            if ($uid > 0 && ($solicitante <= 0 || $uid !== $solicitante)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Converte blocos institucionais no signatário LibreSign do usuário i-Educar.
     *
     * @param  list<array<string,mixed>>  $assinaturas
     * @return list<array<string,mixed>>
     */
    public function mapearAssinaturasInstitucionais(array $assinaturas): array
    {
        $mapaCargos = $this->mapaCargos();
        $defaults = $this->contasPadrao();
        $out = [];
        $idx = 0;

        foreach ($assinaturas as $a) {
            if (! is_array($a)) {
                $idx++;
                continue;
            }

            $resolvido = $this->usuarios->resolver($a);
            $cargo = trim((string) ($resolvido['cargo'] ?? $a['cargo'] ?? ''));
            // Conta/senha explícitas do chamador (ex.: etapas almox) têm prioridade.
            $account = trim((string) ($a['account'] ?? $resolvido['account'] ?? ''));
            $email = trim((string) ($resolvido['email'] ?? $a['email'] ?? ''));
            $password = trim((string) ($a['password'] ?? $resolvido['password'] ?? ''));
            $nome = trim((string) ($a['displayName'] ?? $a['description'] ?? $resolvido['displayName'] ?? $resolvido['nome'] ?? ''));

            $accountCargo = ($cargo !== '' && isset($mapaCargos[$this->chaveCargo($cargo)]))
                ? $mapaCargos[$this->chaveCargo($cargo)]
                : '';
            $accountDefault = $defaults[$idx] ?? '';

            // Sem conta do usuário: cargo → default.
            if ($account === '' && $accountCargo !== '') {
                $account = $accountCargo;
            }
            if ($account === '' && $email === '' && $accountDefault !== '') {
                $account = $accountDefault;
            }

            if ($account === '' && $email === '') {
                $idx++;
                continue;
            }

            if ($password === '' && $account !== '') {
                $password = $this->usuarios->senhaDaConta($account);
            }

            // Conta = login do portal sem senha conhecida: usa cargo/default com senha
            // (mantém o nome do usuário i-Educar como displayName).
            if ($password === '') {
                foreach ([$accountCargo, $accountDefault] as $alt) {
                    if ($alt === '' || strcasecmp($alt, $account) === 0) {
                        continue;
                    }
                    $pwdAlt = $this->usuarios->senhaDaConta($alt);
                    if ($pwdAlt !== '') {
                        $account = $alt;
                        $password = $pwdAlt;
                        break;
                    }
                }
            }

            $userId = $resolvido['user_id'] ?? ($a['user_id'] ?? ($a['responsavel_id'] ?? null));
            $login = $resolvido['login'] ?? ($a['login'] ?? null);
            $out[] = array_filter([
                'account' => $account !== '' ? $account : null,
                'email' => $email !== '' ? $email : null,
                'password' => $password !== '' ? $password : null,
                'displayName' => $nome !== '' ? $nome : ($cargo !== '' ? $cargo : $account),
                'cargo' => $cargo,
                'nome' => $nome,
                'login' => $login !== null && trim((string) $login) !== '' ? trim((string) $login) : null,
                'user_id' => $userId !== null && (int) $userId > 0 ? (int) $userId : null,
                'codigo_validacao' => trim((string) ($a['codigo_validacao'] ?? '')) ?: null,
                'url_validacao' => trim((string) ($a['url_validacao'] ?? '')) ?: null,
            ], static fn ($v) => $v !== null && $v !== '');

            $idx++;
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $assinaturas
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>|null
     */
    public function tentarSolicitarDeDocumento(
        string $titulo,
        string $pdfBinary,
        array $assinaturas,
        array $meta = [],
        string $fileName = 'documento.pdf',
    ): ?array {
        if (! $this->disponivel() || $assinaturas === [] || $pdfBinary === '' || ! str_starts_with($pdfBinary, '%PDF')) {
            return null;
        }

        $signers = $this->mapearAssinaturasInstitucionais($assinaturas);
        if ($signers === []) {
            return null;
        }

        // Não força assinatura imediata: solicitar() decide pela fila de usuários.
        try {
            return $this->solicitar($titulo, $pdfBinary, $signers, $meta, $fileName, true);
        } catch (Throwable $e) {
            // Se a conta = login do portal não existir no Nextcloud, tenta de novo só com fallbacks de cargo/default.
            $fallback = $this->mapearSomenteFallback($assinaturas);
            if ($fallback !== [] && $fallback !== $signers) {
                try {
                    return $this->solicitar($titulo, $pdfBinary, $fallback, $meta, $fileName, true);
                } catch (Throwable $e2) {
                    report($e2);

                    return null;
                }
            }
            report($e);

            return null;
        }
    }

    /**
     * @param  list<array<string,mixed>>  $assinaturas
     * @return list<array<string,mixed>>
     */
    private function mapearSomenteFallback(array $assinaturas): array
    {
        $mapaCargos = $this->mapaCargos();
        $defaults = $this->contasPadrao();
        $out = [];
        $idx = 0;
        foreach ($assinaturas as $a) {
            if (! is_array($a)) {
                $idx++;
                continue;
            }
            $resolvido = $this->usuarios->resolver($a);
            $cargo = trim((string) ($resolvido['cargo'] ?? $a['cargo'] ?? ''));
            $nome = trim((string) ($resolvido['displayName'] ?? $resolvido['nome'] ?? ''));
            $account = '';
            if ($cargo !== '' && isset($mapaCargos[$this->chaveCargo($cargo)])) {
                $account = $mapaCargos[$this->chaveCargo($cargo)];
            }
            if ($account === '' && isset($defaults[$idx])) {
                $account = $defaults[$idx];
            }
            if ($account === '') {
                $idx++;
                continue;
            }
            $password = $this->usuarios->senhaDaConta($account);
            $out[] = array_filter([
                'account' => $account,
                'password' => $password !== '' ? $password : null,
                'displayName' => $nome !== '' ? $nome : $cargo,
                'cargo' => $cargo,
                'nome' => $nome,
                'login' => $resolvido['login'] ?? null,
                'user_id' => $resolvido['user_id'] ?? null,
                'codigo_validacao' => trim((string) ($a['codigo_validacao'] ?? '')) ?: null,
                'url_validacao' => trim((string) ($a['url_validacao'] ?? '')) ?: null,
            ], static fn ($v) => $v !== null && $v !== '');
            $idx++;
        }

        return $out;
    }

    /** @return array<string,string> */
    private function mapaCargos(): array
    {
        $raw = (array) config('assinatura.mapa_cargos', []);
        $out = [];
        foreach ($raw as $cargo => $account) {
            $k = $this->chaveCargo((string) $cargo);
            $acc = trim((string) $account);
            if ($k !== '' && $acc !== '') {
                $out[$k] = $acc;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function contasPadrao(): array
    {
        $lista = [];
        foreach (['signer1', 'signer2', 'signer3', 'signer4'] as $key) {
            $acc = trim((string) config('assinatura.libresign.'.$key, ''));
            if ($acc !== '') {
                $lista[] = $acc;
            }
        }

        return $lista;
    }

    private function chaveCargo(string $cargo): string
    {
        $s = mb_strtolower(trim($cargo));
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return $s;
    }

    /**
     * @param  list<array<string,mixed>>  $signers
     * @return list<array<string,mixed>>
     */
    private function normalizarSigners(array $signers): array
    {
        $out = [];
        foreach ($signers as $s) {
            $resolvido = $this->usuarios->resolver(is_array($s) ? $s : []);
            $account = trim((string) ($resolvido['account'] ?? $s['account'] ?? ''));
            $email = trim((string) ($resolvido['email'] ?? $s['email'] ?? ''));
            if ($account === '' && $email === '') {
                continue;
            }
            $display = trim((string) ($resolvido['displayName'] ?? $s['displayName'] ?? $s['nome'] ?? ''));
            $password = trim((string) ($s['password'] ?? $resolvido['password'] ?? ''));
            if ($password === '' && $account !== '') {
                $password = $this->usuarios->senhaDaConta($account);
            }
            $row = [
                'account' => $account !== '' ? $account : null,
                'email' => $email !== '' ? $email : null,
                'displayName' => $display !== '' ? $display : ($account !== '' ? $account : $email),
                'cargo' => trim((string) ($s['cargo'] ?? $resolvido['cargo'] ?? '')),
                'nome' => trim((string) ($s['nome'] ?? $display)),
                'login' => $resolvido['login'] ?? null,
                'user_id' => $resolvido['user_id'] ?? null,
                'codigo_validacao' => trim((string) ($s['codigo_validacao'] ?? '')) ?: null,
                'url_validacao' => trim((string) ($s['url_validacao'] ?? '')) ?: null,
            ];
            if ($password !== '') {
                $row['password'] = $password;
            }
            $out[] = array_filter($row, static fn ($v) => $v !== null && $v !== '');
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $signers
     * @return list<array<string,mixed>>
     */
    private function enriquecerSenhas(array $signers): array
    {
        foreach ($signers as &$s) {
            if (empty($s['password']) && ! empty($s['account'])) {
                $pwd = $this->usuarios->senhaDaConta((string) $s['account']);
                if ($pwd !== '') {
                    $s['password'] = $pwd;
                }
            }
        }
        unset($s);

        return $signers;
    }

    /** @param  list<array<string,mixed>>  $signers */
    private function todosComSenha(array $signers): bool
    {
        if ($signers === []) {
            return false;
        }
        foreach ($signers as $s) {
            if (trim((string) ($s['password'] ?? '')) === '' || trim((string) ($s['account'] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $pedido
     * @param  list<array<string,mixed>>  $visuais
     */
    private function persistirVisuais(int $pedidoId, array $pedido, array $visuais): void
    {
        $metaAtual = $pedido['meta'] ?? [];
        if (is_string($metaAtual)) {
            $metaAtual = json_decode($metaAtual, true);
        }
        if (! is_array($metaAtual)) {
            $metaAtual = [];
        }
        $metaAtual['assinaturas_visuais'] = $visuais;
        DB::table('assinatura_digital.pedido')->where('id', $pedidoId)->update([
            'meta' => json_encode($metaAtual, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }

    /**
     * Após sincronizar com o LibreSign, preserva o que já foi gravado em meta
     * (signed/status por signatário). Nunca sobrescreve com "Assinatura parcial"
     * genérico — isso apagava o flag signed e sumia da lista "Já assinei".
     *
     * @param  array<string,mixed>  $pedido
     * @param  list<array<string,mixed>>  $visuais
     * @return list<array<string,mixed>>
     */
    private function atualizarVisuaisAposSync(array $pedido, array $visuais): array
    {
        $meta = is_array($pedido['meta'] ?? null) ? $pedido['meta'] : [];
        $synced = is_array($meta['assinaturas_visuais'] ?? null) ? $meta['assinaturas_visuais'] : [];
        if ($synced !== []) {
            return array_values(array_filter($synced, 'is_array'));
        }

        $status = (string) ($pedido['status'] ?? '');
        if ($status !== 'assinado') {
            return $visuais;
        }

        $quando = $pedido['assinado_em'] ?? now()->toDateTimeString();
        foreach ($visuais as &$v) {
            if (! is_array($v)) {
                continue;
            }
            $v['status'] = 'Assinado digitalmente';
            $v['signed'] = true;
            $v['quando'] = $quando;
            $portal = trim((string) ($v['url_validacao_portal'] ?? ''));
            if ($portal !== '') {
                $atual = trim((string) ($v['url_validacao'] ?? ''));
                if ($atual !== '' && str_contains($atual, '/apps/libresign/')) {
                    $v['url_validacao_libresign'] = $atual;
                }
                $v['url_validacao'] = $portal;
            }
        }
        unset($v);
        $this->persistirVisuais((int) $pedido['id'], $pedido, $visuais);

        return $visuais;
    }

    /**
     * Com forçar imediato, todas as etapas acumuladas já foram confirmadas no módulo de origem.
     *
     * @param  list<array<string,mixed>>  $visuais
     * @return list<array<string,mixed>>
     */
    private function garantirVisuaisAssinadosLocalmente(array $visuais, mixed $solicitanteId): array
    {
        unset($solicitanteId);
        $quando = now()->toDateTimeString();
        $out = [];
        foreach ($visuais as $v) {
            if (! is_array($v)) {
                continue;
            }
            if (empty($v['signed'])) {
                $v['signed'] = true;
                $v['status'] = 'Assinado digitalmente';
                if (trim((string) ($v['quando'] ?? '')) === '') {
                    $v['quando'] = $quando;
                }
            }
            $out[] = $v;
        }

        return $out;
    }
}
