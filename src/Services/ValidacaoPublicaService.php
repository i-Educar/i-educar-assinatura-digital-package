<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Consulta pública de autenticidade (LGPD): confirma validade sem expor dados sensíveis.
 */
final class ValidacaoPublicaService
{
    /**
     * @return array{
     *   encontrado:bool,
     *   autentico:bool,
     *   mensagem:string,
     *   via:string,
     *   documento:?array<string,mixed>,
     *   assinaturas:list<array<string,mixed>>,
     *   aviso_lgpd:string
     * }
     */
    public function consultar(string $codigo): array
    {
        $codigo = strtoupper(trim($codigo));
        $aviso = 'Esta verificação confirma apenas a autenticidade do documento/assinatura digital. '
            .'Não são exibidos dados pessoais sensíveis (CPF, e-mail, login, endereço ou conteúdo pedagógico completo), '
            .'em conformidade com a LGPD (Lei nº 13.709/2018). O uso desta consulta é destinado a conferência '
            .'de autenticidade por interessados legítimos.';

        if ($codigo === '') {
            return $this->vazio('Informe o código impresso no documento ou no bloco de assinatura.', $aviso);
        }

        // 1) Pedido LibreSign local (código do documento ou de um signatário visual)
        $pedido = $this->buscarPedidoLocal($codigo);
        if ($pedido !== null) {
            return $this->montarDePedido($this->sincronizarPedidoSePossivel($pedido), $codigo, $aviso);
        }

        // 2) Validador unificado (Advanced Reports / exportação / almox)
        if (class_exists(\iEducar\Packages\BuritiExportacaoUnificada\Services\ValidadorDocumentoBridge::class)) {
            try {
                $res = app(\iEducar\Packages\BuritiExportacaoUnificada\Services\ValidadorDocumentoBridge::class)
                    ->validar($codigo);
                if (($res['documento'] ?? null) !== null || ($res['ok'] ?? false) || ($res['mensagem'] ?? '') !== '') {
                    // Preferir a verdade do LibreSign quando houver pedido vinculado.
                    $pedidoViaAr = $this->buscarPedidoPorCodigoEmbutido($codigo, $res);
                    if ($pedidoViaAr !== null) {
                        return $this->montarDePedido($this->sincronizarPedidoSePossivel($pedidoViaAr), $codigo, $aviso);
                    }

                    return $this->montarDeBridge($res, $codigo, $aviso);
                }
            } catch (Throwable) {
                //
            }
        }

        return $this->vazio(
            'Código não encontrado. Confira se digitou corretamente ou se o documento foi emitido neste ambiente.',
            $aviso
        );
    }

    public function urlPublica(string $codigo): string
    {
        $codigo = strtoupper(trim($codigo));

        return $codigo !== ''
            ? url('/assinatura-digital/verificar/'.$codigo)
            : url('/assinatura-digital/verificar');
    }

    /** @return array<string,mixed>|null */
    private function buscarPedidoLocal(string $codigo): ?array
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('assinatura_digital.pedido')) {
                return null;
            }
            $row = DB::table('assinatura_digital.pedido')
                ->where('codigo_validacao', $codigo)
                ->orderByDesc('id')
                ->first();
            if ($row) {
                return (array) $row;
            }

            $needle = strtoupper($codigo);
            $porMeta = DB::table('assinatura_digital.pedido')
                ->whereRaw('upper(meta::text) like ?', ['%'.$needle.'%'])
                ->orderByDesc('id')
                ->limit(5)
                ->get([
                    'id', 'titulo', 'cenario', 'status', 'codigo_validacao', 'url_validacao',
                    'signatarios', 'meta', 'created_at', 'assinado_em', 'libresign_uuid',
                ]);
            $pedidoPorExportacao = null;
            foreach ($porMeta as $c) {
                $meta = json_decode((string) ($c->meta ?? ''), true);
                if (! is_array($meta)) {
                    continue;
                }
                foreach ((array) ($meta['assinaturas_visuais'] ?? []) as $a) {
                    if (! is_array($a)) {
                        continue;
                    }
                    if (strtoupper(trim((string) ($a['codigo_validacao'] ?? ''))) === $codigo) {
                        $arr = (array) $c;
                        $arr['_codigo_consultado_eh_assinatura'] = true;
                        $arr['_assinatura_consultada'] = $a;

                        return $arr;
                    }
                }
                // Código do documento (exportação) embutido no meta do pedido LS.
                if (strtoupper(trim((string) ($meta['codigo_validacao_exportacao'] ?? ''))) === $codigo) {
                    $pedidoPorExportacao ??= (array) $c;
                }
            }
            if ($pedidoPorExportacao !== null) {
                return $pedidoPorExportacao;
            }

            if (class_exists(\iEducar\Packages\AdvancedReports\Models\AdvancedReportsDocument::class)) {
                $doc = \iEducar\Packages\AdvancedReports\Models\AdvancedReportsDocument::query()
                    ->where('code', $codigo)
                    ->first();
                if ($doc !== null) {
                    $payload = is_array($doc->payload) ? $doc->payload : [];
                    $pedidoId = (int) ($payload['pedido_id'] ?? 0);
                    if ($pedidoId > 0) {
                        $p = DB::table('assinatura_digital.pedido')->where('id', $pedidoId)->first();
                        if ($p) {
                            $arr = (array) $p;
                            if (! empty($payload['etapa']) || str_starts_with((string) $doc->type, 'almox_assinatura_')) {
                                $arr['_codigo_consultado_eh_assinatura'] = true;
                                $arr['_assinatura_consultada'] = [
                                    'codigo_validacao' => $codigo,
                                    'cargo' => (string) ($payload['rotulo'] ?? $payload['etapa'] ?? 'Assinatura'),
                                    'nome' => (string) ($payload['responsavel'] ?? ''),
                                    'quando' => (string) ($payload['quando'] ?? ''),
                                    'status' => ! empty($payload['quando']) || ! empty($payload['assinado'])
                                        ? 'registrada'
                                        : 'Aguardando assinatura digital',
                                ];
                            }

                            return $arr;
                        }
                    }
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param  array{ok:bool,via:string,documento:?array<string,mixed>,mensagem:string}  $resBridge
     * @return array<string,mixed>|null
     */
    private function buscarPedidoPorCodigoEmbutido(string $codigo, array $resBridge): ?array
    {
        $doc = is_array($resBridge['documento'] ?? null) ? $resBridge['documento'] : [];
        $payload = is_array($doc['payload'] ?? null) ? $doc['payload'] : [];
        $pedidoId = (int) ($payload['pedido_id'] ?? 0);
        if ($pedidoId > 0) {
            try {
                $p = DB::table('assinatura_digital.pedido')->where('id', $pedidoId)->first();
                if ($p) {
                    $arr = (array) $p;
                    if (! empty($payload['etapa']) || str_contains((string) ($doc['tipo'] ?? ''), 'almox_assinatura_')) {
                        $arr['_codigo_consultado_eh_assinatura'] = true;
                        $arr['_assinatura_consultada'] = [
                            'codigo_validacao' => $codigo,
                            'cargo' => (string) ($payload['rotulo'] ?? $payload['etapa'] ?? 'Assinatura'),
                            'nome' => (string) ($payload['responsavel'] ?? ''),
                            'quando' => (string) ($payload['quando'] ?? ''),
                            'status' => ! empty($payload['quando']) || ! empty($payload['assinado'])
                                ? 'registrada'
                                : 'Aguardando assinatura digital',
                        ];
                    }

                    return $arr;
                }
            } catch (Throwable) {
                //
            }
        }

        return $this->buscarPedidoLocal($codigo);
    }

    /**
     * @param  array<string,mixed>  $pedido
     * @return array<string,mixed>
     */
    private function montarDePedido(array $pedido, string $codigo, string $aviso): array
    {
        $signatarios = json_decode((string) ($pedido['signatarios'] ?? '[]'), true);
        if (! is_array($signatarios)) {
            $signatarios = [];
        }
        $meta = json_decode((string) ($pedido['meta'] ?? ''), true);
        if (! is_array($meta)) {
            $meta = [];
        }

        $assinaturas = [];
        $visuais = (array) ($meta['assinaturas_visuais'] ?? []);
        if ($visuais !== []) {
            foreach ($visuais as $a) {
                if (! is_array($a)) {
                    continue;
                }
                $assinaturas[] = $this->linhaAssinaturaPublica(
                    (string) ($a['cargo'] ?? 'Signatário'),
                    (string) ($a['nome'] ?? $a['displayName'] ?? ''),
                    (string) ($a['status'] ?? ($pedido['status'] ?? 'aguardando')),
                    (string) ($a['quando'] ?? ''),
                    strtoupper(trim((string) ($a['codigo_validacao'] ?? ''))) === $codigo,
                    ! empty($a['signed']),
                );
            }
        } else {
            foreach ($signatarios as $s) {
                if (! is_array($s)) {
                    continue;
                }
                $assinaturas[] = $this->linhaAssinaturaPublica(
                    (string) ($s['cargo'] ?? 'Signatário'),
                    (string) ($s['displayName'] ?? $s['nome'] ?? ''),
                    (string) ($pedido['status'] ?? 'aguardando'),
                    '',
                    false,
                    false,
                );
            }
        }

        // Fluxo multi-etapa (ex.: almox Separação→Conferência): amplia a lista com etapas do documento.
        $assinaturas = $this->enriquecerEtapasDoFluxo($meta, $assinaturas, $codigo);

        $status = (string) ($pedido['status'] ?? 'aguardando');
        $escopoAssinatura = ! empty($pedido['_codigo_consultado_eh_assinatura']);
        $assinaturaConsultada = is_array($pedido['_assinatura_consultada'] ?? null)
            ? $pedido['_assinatura_consultada']
            : [];
        $statusAssinatura = mb_strtolower(trim((string) ($assinaturaConsultada['status'] ?? '')));
        $assinaturaConcluida = $escopoAssinatura && (
            ! empty($assinaturaConsultada['signed'])
            || str_contains($statusAssinatura, 'assinado')
            || str_contains($statusAssinatura, 'assinada')
            || $statusAssinatura === 'registrada'
            || ! empty($assinaturaConsultada['quando'])
        );
        $assinaturaPendente = $escopoAssinatura && ! $assinaturaConcluida;

        $totalAssinaturas = count($assinaturas);
        $assinadas = count(array_filter($assinaturas, static fn (array $a): bool => ! empty($a['concluida'])));
        $pendentes = max(0, $totalAssinaturas - $assinadas);
        $multiEtapa = $totalAssinaturas > 1;
        $documentoCompleto = ($totalAssinaturas > 0 && $pendentes === 0 && $assinadas === $totalAssinaturas)
            || ($totalAssinaturas === 0 && $status === 'assinado');
        $documentoAndamento = ! $documentoCompleto && (
            $assinadas > 0
            || $status === 'parcial'
            || ($multiEtapa && ($assinaturaConcluida || $assinadas > 0))
            || ($multiEtapa && $pendentes > 0 && $assinadas === 0 && $status === 'assinado')
        );
        // Código/assinatura encontrados = autênticos; completo ≠ andamento.
        $autentico = $documentoCompleto || $documentoAndamento || $assinaturaConcluida
            || ($escopoAssinatura && $assinaturaConcluida)
            || in_array($status, ['assinado', 'parcial'], true);
        $progresso = $totalAssinaturas > 0
            ? $assinadas.' de '.$totalAssinaturas.' etapa'.($totalAssinaturas === 1 ? '' : 's')
            : null;
        // Faltantes: só o nome da etapa/função — sem atores.
        $etapasFaltantes = array_values(array_filter(array_map(
            static fn (array $a): string => empty($a['concluida']) ? trim((string) ($a['papel'] ?? '')) : '',
            $assinaturas,
        )));
        $etapasConcluidas = array_values(array_filter(array_map(
            static fn (array $a): string => ! empty($a['concluida']) ? trim((string) ($a['papel'] ?? '')) : '',
            $assinaturas,
        )));

        $uuid = trim((string) ($pedido['libresign_uuid'] ?? ''));
        $urlLibreSign = '';
        if ($uuid !== '' && class_exists(LibreSign\LibreSignClient::class)) {
            try {
                $urlLibreSign = app(LibreSign\LibreSignClient::class)->urlValidacao($uuid);
            } catch (Throwable) {
                $urlLibreSign = '';
            }
        }

        $faltantesTxt = $etapasFaltantes !== [] ? ' Etapas faltantes: '.implode(', ', $etapasFaltantes).'.' : '';

        $veredicto = match (true) {
            $documentoCompleto => $this->veredicto(
                'ok',
                'Documento válido e completo',
                'Todas as etapas de assinatura necessárias foram concluídas. Este documento pode ser usado com confiança.',
            ),
            $documentoAndamento && $assinaturaConcluida => $this->veredicto(
                'warn',
                'Assinatura válida — documento em andamento',
                'Esta assinatura é autêntica. O documento é válido, porém ainda está em andamento'
                    .($progresso !== null ? ' ('.$progresso.')' : '')
                    .'.'
                    .$faltantesTxt
                    .' Aguarde a conclusão de todas as etapas para uso como documento final.',
            ),
            $documentoAndamento => $this->veredicto(
                'warn',
                'Documento válido — em andamento',
                'O código é autêntico. O documento é válido, porém ainda está em andamento'
                    .($progresso !== null ? ' ('.$progresso.')' : '')
                    .'.'
                    .($etapasConcluidas !== [] ? ' Concluídas: '.implode(', ', $etapasConcluidas).'.' : '')
                    .$faltantesTxt
                    .' Não utilize como documento final enquanto houver etapas pendentes.',
            ),
            $assinaturaPendente => $this->veredicto(
                'warn',
                'Assinatura encontrada — ainda pendente',
                'O código corresponde a uma etapa prevista, mas ela ainda não foi concluída. O documento permanece em andamento.',
            ),
            in_array($status, ['aguardando', 'rascunho', ''], true) => $this->veredicto(
                'warn',
                'Documento válido — aguardando etapas',
                'O documento foi emitido e o código é autêntico, mas nenhuma etapa de assinatura foi concluída ainda.'
                    .$faltantesTxt,
            ),
            default => $this->veredicto(
                'erro',
                'Documento com problema',
                'Encontramos o registro, mas ele não está concluído. Peça uma nova emissão à escola ou secretaria.',
            ),
        };

        // Sem etapas listadas mas código do documento existe e LS assinado de uma etapa: ainda andamento se meta indica fluxo.
        if (! $documentoCompleto && ! $documentoAndamento && $this->metaIndicaFluxoMultiEtapa($meta) && $status === 'assinado') {
            $documentoAndamento = true;
            $autentico = true;
            $veredicto = $this->veredicto(
                'warn',
                'Documento válido — em andamento',
                'O código é autêntico. Há um fluxo com várias etapas; confira as etapas faltantes abaixo.'.$faltantesTxt,
            );
        }

        return [
            'encontrado' => true,
            'autentico' => $autentico,
            'mensagem' => $veredicto['resumo'],
            'via' => 'assinatura-digital',
            'veredicto' => $veredicto,
            'documento' => [
                'tipo_publico' => $this->tipoPublico((string) ($pedido['titulo'] ?? ''), (string) ($meta['origem'] ?? $meta['modulo_origem'] ?? '')),
                'emitido_em' => $this->formatarData($pedido['created_at'] ?? null),
                'assinado_em' => $this->formatarData($pedido['assinado_em'] ?? null),
                'status' => $documentoCompleto ? 'completo' : ($documentoAndamento ? 'andamento' : $status),
                'status_amigavel' => $documentoCompleto
                    ? 'Completo — todas as etapas'
                    : ($documentoAndamento
                        ? 'Válido — em andamento'.($progresso !== null ? ' ('.$progresso.')' : '')
                        : $this->statusPublico($status)),
                'completo' => $documentoCompleto,
                'parcial' => $documentoAndamento,
                'andamento' => $documentoAndamento,
                'progresso_assinaturas' => $progresso,
                'assinadas' => $assinadas,
                'pendentes' => $pendentes,
                'total_assinaturas' => $totalAssinaturas,
                'etapas_faltantes' => $etapasFaltantes,
                'etapas_concluidas' => $etapasConcluidas,
                'referencia' => 'Pedido digital #'.(int) ($pedido['id'] ?? 0),
                'escopo_consulta' => $escopoAssinatura ? 'assinatura' : 'documento',
                'trilha_libresign' => true,
                'libresign_uuid' => $uuid !== '' ? $uuid : null,
                'url_libresign' => $urlLibreSign !== '' ? $urlLibreSign : null,
            ],
            'assinaturas' => $assinaturas,
            'aviso_lgpd' => $aviso,
        ];
    }

    /**
     * @param  array<string,mixed>  $pedido
     * @return array<string,mixed>
     */
    private function sincronizarPedidoSePossivel(array $pedido): array
    {
        $id = (int) ($pedido['id'] ?? 0);
        $uuid = trim((string) ($pedido['libresign_uuid'] ?? ''));
        if ($id <= 0 || $uuid === '') {
            return $pedido;
        }
        $status = (string) ($pedido['status'] ?? '');
        if (! in_array($status, ['aguardando', 'parcial', 'rascunho', ''], true)) {
            return $pedido;
        }
        try {
            app(PedidoAssinaturaService::class)->sincronizarAposAssinatura($id, $uuid);
            $flags = array_intersect_key($pedido, array_flip([
                '_codigo_consultado_eh_assinatura',
                '_assinatura_consultada',
            ]));
            $atual = app(PedidoAssinaturaService::class)->obter($id);
            if ($atual === []) {
                return $pedido;
            }
            // Reaplica destaque da assinatura consultada após sync.
            if (! empty($flags['_codigo_consultado_eh_assinatura'])) {
                $atual['_codigo_consultado_eh_assinatura'] = true;
                $codigo = strtoupper(trim((string) (
                    is_array($flags['_assinatura_consultada'] ?? null)
                        ? ($flags['_assinatura_consultada']['codigo_validacao'] ?? '')
                        : ''
                )));
                $meta = is_array($atual['meta'] ?? null) ? $atual['meta'] : [];
                foreach ((array) ($meta['assinaturas_visuais'] ?? []) as $a) {
                    if (! is_array($a)) {
                        continue;
                    }
                    if ($codigo !== '' && strtoupper(trim((string) ($a['codigo_validacao'] ?? ''))) === $codigo) {
                        $atual['_assinatura_consultada'] = $a;
                        break;
                    }
                }
                if (empty($atual['_assinatura_consultada']) && is_array($flags['_assinatura_consultada'] ?? null)) {
                    $atual['_assinatura_consultada'] = $flags['_assinatura_consultada'];
                }
            }

            // obter() já hidrata; montarDePedido espera JSON strings em alguns campos legados.
            if (is_array($atual['signatarios'] ?? null)) {
                $atual['signatarios'] = json_encode($atual['signatarios'], JSON_UNESCAPED_UNICODE);
            }
            if (is_array($atual['meta'] ?? null)) {
                $atual['meta'] = json_encode($atual['meta'], JSON_UNESCAPED_UNICODE);
            }

            return $atual;
        } catch (Throwable) {
            return $pedido;
        }
    }

    /**
     * @param  array{ok:bool,via:string,documento:?array<string,mixed>,mensagem:string}  $res
     * @return array<string,mixed>
     */
    private function montarDeBridge(array $res, string $codigo, string $aviso): array
    {
        $doc = is_array($res['documento'] ?? null) ? $res['documento'] : [];
        $payload = is_array($doc['payload'] ?? null) ? $doc['payload'] : [];
        $tipo = (string) ($doc['tipo'] ?? $payload['tipo'] ?? 'documento');
        $ok = (bool) ($res['ok'] ?? false);

        $assinaturas = [];
        foreach ((array) ($payload['assinaturas'] ?? $payload['signatarios'] ?? []) as $chave => $a) {
            if (! is_array($a)) {
                continue;
            }
            // Payload almox: mapa etapa => {responsavel,...}
            $papel = (string) ($a['cargo'] ?? $a['papel'] ?? $a['rotulo'] ?? '');
            if ($papel === '' && is_string($chave) && ! is_numeric($chave)) {
                $papel = class_exists(\iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PedidoCarrinho::class)
                    ? \iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PedidoCarrinho::rotuloEtapa($chave)
                    : $chave;
            }
            if ($papel === '') {
                $papel = (string) ($a['etapa'] ?? 'Signatário');
            }
            $temAssinatura = trim((string) ($a['responsavel'] ?? $a['nome'] ?? '')) !== ''
                || ! empty($a['codigo_validacao'])
                || ! empty($a['quando']);
            $assinaturas[] = $this->linhaAssinaturaPublica(
                $papel,
                $temAssinatura ? (string) ($a['nome'] ?? $a['responsavel'] ?? $a['displayName'] ?? '') : '',
                $temAssinatura ? 'registrada' : 'pendente',
                (string) ($a['quando'] ?? ''),
                strtoupper(trim((string) ($a['codigo_validacao'] ?? ''))) === $codigo,
                $temAssinatura,
            );
        }

        // Almox: etapa isolada no payload do código de assinatura
        if ($assinaturas === [] && ! empty($payload['etapa'])) {
            $assinaturas[] = $this->linhaAssinaturaPublica(
                (string) ($payload['rotulo'] ?? $payload['etapa']),
                (string) ($payload['responsavel'] ?? ''),
                'registrada',
                (string) ($payload['quando'] ?? ''),
                true,
                true,
            );
        }

        // Documento multi-etapa: amplia com etapas do pedido de origem.
        $metaFluxo = [
            'origem' => $tipo,
            'modulo_origem' => (string) ($payload['modulo_origem'] ?? ''),
            'pedido_id' => (int) ($payload['pedido_id'] ?? 0),
            'etapa' => (string) ($payload['etapa'] ?? ''),
            'etapas_esperadas' => $payload['etapas_esperadas'] ?? null,
        ];
        if (str_starts_with($tipo, 'almox_') || ! empty($payload['pedido_id'])) {
            $assinaturas = $this->enriquecerEtapasDoFluxo($metaFluxo, $assinaturas, $codigo);
        }

        $encontrado = $doc !== [] || $ok;
        $escopoAssinatura = str_starts_with($tipo, 'almox_assinatura_') || ! empty($payload['etapa']);
        $mensagemTecnica = (string) ($res['mensagem'] ?? '');
        $hmacFalha = $encontrado && ! $ok && str_contains(mb_strtolower($mensagemTecnica), 'hmac');

        $total = count($assinaturas);
        $assinadas = count(array_filter($assinaturas, static fn (array $a): bool => ! empty($a['concluida'])));
        $pendentes = max(0, $total - $assinadas);
        $completo = $total > 0 && $pendentes === 0;
        $andamento = $ok && $total > 1 && ! $completo;
        $etapasFaltantes = array_values(array_filter(array_map(
            static fn (array $a): string => empty($a['concluida']) ? trim((string) ($a['papel'] ?? '')) : '',
            $assinaturas,
        )));
        $progresso = $total > 0 ? $assinadas.' de '.$total.' etapa'.($total === 1 ? '' : 's') : null;
        $faltantesTxt = $etapasFaltantes !== [] ? ' Etapas faltantes: '.implode(', ', $etapasFaltantes).'.' : '';

        $veredicto = match (true) {
            $ok && $completo => $this->veredicto(
                'ok',
                $escopoAssinatura && $total <= 1 ? 'Assinatura válida' : 'Documento válido e completo',
                $escopoAssinatura && $total <= 1
                    ? 'Esta assinatura foi registrada neste sistema. Você pode confiar nela.'
                    : 'Todas as etapas necessárias foram concluídas. Este documento pode ser usado com confiança.',
            ),
            $ok && $andamento => $this->veredicto(
                'warn',
                $escopoAssinatura ? 'Assinatura válida — documento em andamento' : 'Documento válido — em andamento',
                'O código é autêntico. O documento é válido, porém ainda está em andamento'
                    .($progresso !== null ? ' ('.$progresso.')' : '')
                    .'.'
                    .$faltantesTxt
                    .' Aguarde a conclusão de todas as etapas para uso como documento final.',
            ),
            $ok => $this->veredicto(
                'ok',
                $escopoAssinatura ? 'Assinatura válida' : 'Documento válido',
                $escopoAssinatura
                    ? 'Esta assinatura foi registrada neste sistema. Você pode confiar nela.'
                    : 'Este documento foi emitido neste sistema e o conteúdo confere. Você pode confiar nele.',
            ),
            $encontrado && $hmacFalha => $this->veredicto(
                'erro',
                'Documento suspeito',
                'O código existe, mas o conteúdo não confere com o original. Não use este papel como prova — peça uma nova emissão.',
            ),
            $encontrado => $this->veredicto(
                'warn',
                'Documento encontrado com ressalvas',
                'Localizamos o código, porém não foi possível confirmar totalmente a autenticidade. Em caso de dúvida, peça uma nova emissão.',
            ),
            default => $this->veredicto(
                'erro',
                'Código não encontrado',
                'Não encontramos este código. Confira se digitou certo ou se o documento é deste município/sistema.',
            ),
        };

        return [
            'encontrado' => $encontrado,
            'autentico' => $ok,
            'mensagem' => $veredicto['resumo'],
            'via' => (string) ($res['via'] ?? 'validador'),
            'veredicto' => $veredicto,
            'documento' => [
                'tipo_publico' => $this->tipoPublico((string) ($payload['titulo'] ?? ''), $tipo),
                'emitido_em' => (string) ($doc['emitido_em'] ?? $this->formatarData($doc['issued_at'] ?? null)),
                'assinado_em' => null,
                'status' => ! $ok ? ($encontrado ? 'invalido' : 'nao_encontrado') : ($completo ? 'completo' : ($andamento ? 'andamento' : 'valido')),
                'status_amigavel' => ! $ok
                    ? ($encontrado ? 'Não confere' : 'Não encontrado')
                    : ($completo
                        ? 'Completo — todas as etapas'
                        : ($andamento
                            ? 'Válido — em andamento'.($progresso !== null ? ' ('.$progresso.')' : '')
                            : 'Válido')),
                'completo' => $ok && $completo,
                'parcial' => $ok && $andamento,
                'andamento' => $ok && $andamento,
                'progresso_assinaturas' => $progresso,
                'etapas_faltantes' => $etapasFaltantes,
                'referencia' => ! empty($payload['pedido_id']) ? 'Pedido #'.(int) $payload['pedido_id'] : null,
                'escopo_consulta' => $escopoAssinatura ? 'assinatura' : 'documento',
                'trilha_libresign' => ! empty($payload['libresign_uuid']) || ! empty($payload['pedido_id']),
            ],
            'assinaturas' => $assinaturas,
            'aviso_lgpd' => $aviso,
        ];
    }

    /**
     * @return array{papel:string,responsavel_mascarado:?string,status:string,quando:?string,destaque:bool,concluida:bool}
     */
    private function linhaAssinaturaPublica(
        string $papel,
        string $nome,
        string $status,
        string $quando,
        bool $destaque,
        bool $signedFlag = false,
    ): array {
        $concluida = $signedFlag || $this->statusIndicaAssinado($status);

        return [
            'papel' => $papel !== '' ? $papel : 'Signatário',
            // Pendentes: sem ator (LGPD + fluxo compartilhado por vários servidores).
            'responsavel_mascarado' => $concluida
                ? ($this->mascararNome($nome) ?? 'Servidor autorizado')
                : null,
            'status' => $concluida ? 'Já assinou' : 'Etapa pendente',
            'quando' => $concluida ? $this->formatarData($quando !== '' ? $quando : null) : null,
            'destaque' => $destaque,
            'concluida' => $concluida,
        ];
    }


    /**
     * Amplia a lista pública com todas as etapas do fluxo (ex.: nota almox),
     * para a verificação mostrar andamento e faltantes sem inventar atores.
     *
     * @param  array<string,mixed>  $meta
     * @param  list<array<string,mixed>>  $assinaturas
     * @return list<array<string,mixed>>
     */
    private function enriquecerEtapasDoFluxo(array $meta, array $assinaturas, string $codigo): array
    {
        $etapasEspelhadas = $this->etapasDoDocumentoOrigem($meta);
        if ($etapasEspelhadas === []) {
            // Garante que pendentes da lista atual não exponham atores.
            return array_map(function (array $a): array {
                if (empty($a['concluida'])) {
                    $a['responsavel_mascarado'] = null;
                    $a['status'] = 'Etapa pendente';
                }

                return $a;
            }, $assinaturas);
        }

        $porPapel = [];
        foreach ($assinaturas as $a) {
            $k = mb_strtolower(trim((string) ($a['papel'] ?? '')));
            if ($k !== '') {
                $porPapel[$k] = $a;
            }
        }

        $out = [];
        foreach ($etapasEspelhadas as $e) {
            $papel = (string) ($e['papel'] ?? 'Etapa');
            $chave = mb_strtolower($papel);
            $existente = $porPapel[$chave] ?? null;
            $concluida = ! empty($e['concluida']) || (! empty($existente['concluida']));
            $nome = $concluida
                ? (string) ($e['nome'] ?? $existente['responsavel_mascarado'] ?? '')
                : '';
            $quando = $concluida
                ? (string) ($e['quando'] ?? $existente['quando'] ?? '')
                : '';
            $destaque = strtoupper(trim((string) ($e['codigo_validacao'] ?? ''))) === $codigo
                || (! empty($existente['destaque']));
            $out[] = $this->linhaAssinaturaPublica(
                $papel,
                $nome,
                $concluida ? 'registrada' : 'pendente',
                $quando,
                $destaque,
                $concluida,
            );
        }

        return $out !== [] ? $out : $assinaturas;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return list<array{papel:string,nome?:string,quando?:string,concluida:bool,codigo_validacao?:string}>
     */
    private function etapasDoDocumentoOrigem(array $meta): array
    {
        if (! $this->metaIndicaFluxoMultiEtapa($meta)) {
            return [];
        }

        $pedidoOrigemId = (int) ($meta['pedido_id'] ?? 0);
        if ($pedidoOrigemId <= 0) {
            // Meta explícita de etapas esperadas (sem payload).
            $esperadas = $meta['etapas_esperadas'] ?? $meta['etapas'] ?? null;
            if (! is_array($esperadas) || $esperadas === []) {
                return [];
            }
            $out = [];
            foreach ($esperadas as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $out[] = ['papel' => trim($item), 'concluida' => false];
                    continue;
                }
                if (! is_array($item)) {
                    continue;
                }
                $papel = trim((string) ($item['papel'] ?? $item['rotulo'] ?? $item['etapa'] ?? ''));
                if ($papel === '') {
                    continue;
                }
                $out[] = [
                    'papel' => $papel,
                    'nome' => (string) ($item['responsavel'] ?? $item['nome'] ?? ''),
                    'quando' => (string) ($item['quando'] ?? ''),
                    'concluida' => ! empty($item['concluida']) || ! empty($item['quando']) || ! empty($item['responsavel']),
                    'codigo_validacao' => (string) ($item['codigo_validacao'] ?? ''),
                ];
            }

            return $out;
        }

        if (! class_exists(\iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PedidoCarrinho::class)) {
            return [];
        }

        try {
            $pedido = \iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PedidoCarrinho::obter($pedidoOrigemId);
        } catch (Throwable) {
            return [];
        }

        $payload = is_array($pedido['payload'] ?? null) ? $pedido['payload'] : [];
        $assin = is_array($payload['assinaturas'] ?? null) ? $payload['assinaturas'] : [];
        // Só etapas com assinatura formal — sem Separação / Em trânsito (operacionais).
        $ordem = class_exists(\iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PedidoCarrinho::class)
            ? \iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PedidoCarrinho::ETAPAS_ASSINATURA
            : ['aceite', 'entrega', 'conferencia'];
        $out = [];
        foreach ($ordem as $etapa) {
            $rotulo = class_exists(\iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PedidoCarrinho::class)
                ? \iEducar\Packages\BuritiAlmoxarifadoEscolar\Services\PedidoCarrinho::rotuloEtapa($etapa)
                : $etapa;
            $a = is_array($assin[$etapa] ?? null) ? $assin[$etapa] : null;
            // Ignora marca operacional de separação (via=operacional) se ainda vier no payload.
            if ($etapa === 'separacao' && is_array($a) && ($a['via'] ?? '') === 'operacional') {
                continue;
            }
            $concluida = $a !== null && trim((string) ($a['responsavel'] ?? '')) !== '';
            $out[] = [
                'papel' => $rotulo,
                'nome' => $concluida ? (string) ($a['responsavel'] ?? '') : '',
                'quando' => $concluida ? (string) ($a['quando'] ?? $a['quando_raw'] ?? '') : '',
                'concluida' => $concluida,
                'codigo_validacao' => $concluida ? (string) ($a['codigo_validacao'] ?? '') : '',
            ];
        }

        return $out;
    }

    /** @param  array<string,mixed>  $meta */
    private function metaIndicaFluxoMultiEtapa(array $meta): bool
    {
        $origem = mb_strtolower(trim((string) ($meta['origem'] ?? '')));
        $modulo = mb_strtolower(trim((string) ($meta['modulo_origem'] ?? '')));
        if (str_contains($origem, 'almox') || str_contains($modulo, 'almox')) {
            return true;
        }
        if (! empty($meta['etapas_esperadas']) || ! empty($meta['etapas'])) {
            return true;
        }
        if (! empty($meta['pedido_id']) && ! empty($meta['etapa'])) {
            return true;
        }

        return false;
    }

    private function statusIndicaAssinado(string $status): bool
    {
        $s = mb_strtolower(trim($status));
        if ($s === '' || str_contains($s, 'aguard') || str_contains($s, 'pend')) {
            return false;
        }

        return str_contains($s, 'assinado')
            || str_contains($s, 'assinada')
            || $s === 'registrada'
            || $s === 'valido'
            || str_contains($s, 'conclu');
    }

    private function mascararNome(string $nome): ?string
    {
        $nome = trim(preg_replace('/\s+/u', ' ', $nome) ?? '');
        if ($nome === '') {
            return null;
        }
        // Primeiro e último nome (LGPD: sem CPF/e-mail; identidade do signatário permanece legível).
        $partes = array_values(array_filter(explode(' ', $nome), static fn (string $p): bool => $p !== ''));
        if ($partes === []) {
            return null;
        }
        if (count($partes) === 1) {
            return $partes[0];
        }

        return $partes[0].' '.$partes[count($partes) - 1];
    }

    private function statusPublico(string $status): string
    {
        $s = mb_strtolower(trim($status));

        return match (true) {
            str_contains($s, 'aguard') || str_contains($s, 'pend') => 'Aguardando assinatura',
            str_contains($s, 'parcial') => 'Incompleto (assinaturas parciais)',
            $this->statusIndicaAssinado($s) => 'Assinatura registrada',
            default => 'Emitido — conferir no LibreSign',
        };
    }

    private function tipoPublico(string $titulo, string $origem): string
    {
        $t = mb_strtolower($titulo.' '.$origem);
        if (str_contains($t, 'almox')) {
            return 'Nota de material (almoxarifado escolar)';
        }
        if (str_contains($t, 'transporte')) {
            return 'Documento de transporte escolar';
        }
        if (str_contains($t, 'diploma') || str_contains($t, 'certificado')) {
            return 'Diploma / certificado';
        }
        if (str_contains($t, 'ata')) {
            return 'Ata escolar';
        }
        if (str_contains($t, 'declara')) {
            return 'Declaração escolar';
        }
        if (str_contains($t, 'cardapio') || str_contains($t, 'aliment')) {
            return 'Documento de alimentação escolar';
        }
        if (str_contains($t, 'libresign') || str_contains($t, 'assinatura')) {
            return 'Documento com assinatura digital';
        }

        return 'Documento institucional';
    }

    private function formatarData(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse((string) $valor)->format('d/m/Y');
        } catch (Throwable) {
            if (is_string($valor) && preg_match('/^\d{2}\/\d{2}\/\d{4}/', $valor)) {
                return substr($valor, 0, 10);
            }

            return null;
        }
    }

    /**
     * @return array{nivel:string,titulo:string,resumo:string,icone:string,cor:string}
     */
    private function veredicto(string $nivel, string $titulo, string $resumo): array
    {
        return match ($nivel) {
            'ok' => [
                'nivel' => 'ok',
                'titulo' => $titulo,
                'resumo' => $resumo,
                'icone' => 'fa-check-circle',
                'cor' => 'ok',
            ],
            'warn' => [
                'nivel' => 'warn',
                'titulo' => $titulo,
                'resumo' => $resumo,
                'icone' => 'fa-exclamation-triangle',
                'cor' => 'warn',
            ],
            default => [
                'nivel' => 'erro',
                'titulo' => $titulo,
                'resumo' => $resumo,
                'icone' => 'fa-times-circle',
                'cor' => 'erro',
            ],
        };
    }

    /** @return array<string,mixed> */
    private function vazio(string $mensagem, string $aviso): array
    {
        $veredicto = str_contains(mb_strtolower($mensagem), 'informe')
            ? $this->veredicto('warn', 'Informe o código', $mensagem)
            : $this->veredicto('erro', 'Código não encontrado', $mensagem);

        return [
            'encontrado' => false,
            'autentico' => false,
            'mensagem' => $veredicto['resumo'],
            'via' => '',
            'veredicto' => $veredicto,
            'documento' => null,
            'assinaturas' => [],
            'aviso_lgpd' => $aviso,
        ];
    }
}
