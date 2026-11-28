@extends('assinatura::layout')

@section('titulo', 'Ver documento')
@section('icone-tela', 'fa-file-pdf-o')
@section('titulo-tela', $pedido['titulo'] ?? 'Documento')
@section('lead')
    @php
        $metaLead = is_array($pedido['meta'] ?? null) ? $pedido['meta'] : [];
        $etapaLead = trim((string) ($metaLead['etapa_rotulo'] ?? $metaLead['status_documento'] ?? ''));
        if ($etapaLead === '' && ! empty($metaLead['etapa'])) {
            $et = (string) $metaLead['etapa'];
            $etapaLead = match ($et) {
                'separacao' => 'Separação',
                'aceite' => 'Aceite',
                'entrega' => 'Entrega',
                'conferencia' => 'Conferência',
                default => ucfirst($et),
            };
        }
        $moduloLead = trim((string) ($metaLead['modulo_origem'] ?? ''));
        if ($moduloLead !== '') {
            $moduloLead = str_contains(mb_strtolower($moduloLead), 'almox')
                ? 'Almoxarifado escolar'
                : str_replace(['-', '_'], ' ', $moduloLead);
            $moduloLead = mb_convert_case($moduloLead, MB_CASE_TITLE, 'UTF-8');
        }
    @endphp
    Pedido #{{ $pedido['id'] }}
    @if($moduloLead !== '')
        · {{ $moduloLead }}
    @endif
    @if($etapaLead !== '')
        · Status: <strong>{{ $etapaLead }}</strong>
    @else
        · {{ $pedido['status'] ?? '—' }}
    @endif
@endsection
@section('acoes-topo')
    <a class="botao claro icone" href="{{ url('/assinatura-digital/pedidos/'.$pedido['id']) }}" title="Detalhe" aria-label="Detalhe"><i aria-hidden="true" class="fa fa-info-circle"></i></a>
    <a class="botao claro icone" href="{{ url('/assinatura-digital/pedidos/'.$pedido['id'].'/pdf') }}" target="_blank" title="Só PDF" aria-label="Só PDF"><i aria-hidden="true" class="fa fa-external-link"></i></a>
    <a class="botao icone" href="{{ url('/assinatura-digital/pedidos/'.$pedido['id'].'/baixar') }}" title="Baixar" aria-label="Baixar"><i aria-hidden="true" class="fa fa-download"></i></a>
@endsection

@push('estilos')
<style>
    .ver-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(220px, 280px);
        gap: 14px;
        align-items: start;
    }
    .ver-pdf {
        background: linear-gradient(180deg, #e8eef1 0%, #d5dee3 100%);
        border: 1px solid var(--linha);
        border-radius: 14px;
        overflow: hidden;
        min-height: 72vh;
        box-shadow: 0 10px 24px rgba(21,34,43,.05);
        padding: 12px 12px 8px;
    }
    .ver-pdf__folha {
        background: #fff;
        border-radius: 6px;
        box-shadow: 0 8px 28px rgba(15, 23, 42, .18), 0 0 0 1px rgba(15,23,42,.06);
        overflow: hidden;
        min-height: 70vh;
    }
    .ver-pdf iframe, .ver-pdf embed, .ver-pdf object {
        width: 100%;
        height: 78vh;
        border: 0;
        display: block;
        background: #fff;
    }
    .ver-lado .bloco { margin: 0 0 12px; }
    .ver-lado .grade-assina {
        display: grid;
        gap: 4px;
    }
    .ver-lado .ls-assina {
        padding-top: 18px !important;
    }
    .ver-lado .ls-assina > div:first-child {
        margin: 0 8px 6px !important;
    }
    @media (max-width: 960px) {
        .ver-grid { grid-template-columns: 1fr; }
        .ver-pdf iframe, .ver-pdf embed, .ver-pdf object { height: 62vh; }
    }
</style>
@endpush

@section('conteudo')
@if(!empty($erroPdf))
    <p class="flash erro"><i class="fa fa-exclamation-circle"></i> {{ $erroPdf }}</p>
@endif

<div class="ver-grid">
    <div class="ver-pdf" aria-label="Pré-visualização do documento">
        @if(empty($erroPdf))
            <div class="ver-pdf__folha">
                <iframe
                    title="Documento do pedido {{ $pedido['id'] }}"
                    src="{{ url('/assinatura-digital/pedidos/'.$pedido['id'].'/pdf') }}#view=FitH"
                    type="application/pdf"
                ></iframe>
            </div>
        @else
            <div style="padding:28px;color:var(--suave)">
                Não foi possível embutir o documento. Use <strong>Só PDF</strong> ou <strong>Baixar</strong>.
            </div>
        @endif
    </div>

    <aside class="ver-lado">
        <div class="bloco">
            <h2><i class="fa fa-pencil-square-o"></i> Assinaturas</h2>
            <div class="grade-assina">
                @php
                    $lista = is_array($assinantes ?? null) ? $assinantes : [];
                    if ($lista === []) {
                        $visuais = is_array($pedido['meta']['assinaturas_visuais'] ?? null)
                            ? $pedido['meta']['assinaturas_visuais']
                            : [];
                        $lista = $visuais !== [] ? $visuais : ($pedido['signatarios'] ?? []);
                    }
                @endphp
                @forelse($lista as $s)
                    @php
                        $concluida = array_key_exists('concluida', $s)
                            ? ! empty($s['concluida'])
                            : trim((string) ($s['nome'] ?? $s['displayName'] ?? $s['responsavel'] ?? '')) !== '';
                        $statusLocal = $s['status'] ?? null;
                        if ($statusLocal === null || $statusLocal === '') {
                            if ($concluida) {
                                $statusLocal = 'Assinado digitalmente';
                            } elseif (($pedido['status'] ?? '') === 'parcial') {
                                $statusLocal = 'Assinatura parcial';
                            } else {
                                $statusLocal = 'Aguardando assinatura';
                            }
                        }
                        $assinante = [
                            'nome' => $concluida
                                ? ($s['nome'] ?? ($s['displayName'] ?? $s['account'] ?? $s['email'] ?? $s['responsavel'] ?? ''))
                                : '',
                            'cargo' => $s['cargo'] ?? '',
                            'account' => $s['account'] ?? null,
                            'quando' => $concluida ? ($s['quando'] ?? null) : null,
                            'status' => $statusLocal,
                            'codigo_validacao' => $concluida ? ($s['codigo_validacao'] ?? null) : null,
                            'url_validacao' => $concluida ? ($s['url_validacao'] ?? null) : null,
                        ];
                    @endphp
                    @include('assinatura::partials.bloco-assinante', [
                        'assinante' => $assinante,
                        'cor' => ($identidade['cor'] ?? null) ?: '#0f4c5c',
                    ])
                @empty
                    <p class="texto-suave">Nenhum signatário neste pedido.</p>
                @endforelse
            </div>
        </div>

        @if(!empty($pedido['codigo_validacao']))
            <div class="bloco">
                <h2><i class="fa fa-qrcode"></i> Validação</h2>
                <p>Código: <strong>{{ $pedido['codigo_validacao'] }}</strong></p>
                <a class="botao sm claro" href="{{ $pedido['url_validacao'] ?? url('/assinatura-digital/verificar/'.$pedido['codigo_validacao']) }}" target="_blank" rel="noopener">
                    Conferir autenticidade
                </a>
            </div>
        @endif
    </aside>
</div>
@endsection
