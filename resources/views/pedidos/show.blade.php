@extends('assinatura::layout')

@section('titulo', $pedido['titulo'] ?? 'Pedido')
@section('icone-tela', 'fa-file-text-o')
@section('titulo-tela', $pedido['titulo'] ?? 'Pedido')
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
    @endphp
    Pedido #{{ $pedido['id'] }}
    @if($etapaLead !== '')
        · Status: <strong>{{ $etapaLead }}</strong>
    @else
        · status {{ $pedido['status'] ?? '—' }}
    @endif
    · cenário {{ $pedido['cenario'] ?? '—' }}
@endsection
@section('acoes-topo')
    <a class="botao claro icone" href="{{ url('/assinatura-digital/pedidos') }}" title="Pedidos" aria-label="Pedidos"><i aria-hidden="true" class="fa fa-arrow-left"></i></a>
    @if(!empty($pedido['libresign_uuid']))
        <a class="botao icone" href="{{ url('/assinatura-digital/pedidos/'.$pedido['id'].'/ver') }}" target="_blank" title="Ver PDF" aria-label="Ver PDF"><i aria-hidden="true" class="fa fa-eye"></i></a>
        <a class="botao claro icone" href="{{ url('/assinatura-digital/pedidos/'.$pedido['id'].'/baixar') }}" title="Baixar" aria-label="Baixar"><i aria-hidden="true" class="fa fa-download"></i></a>
    @endif
    <form method="post" action="{{ url('/assinatura-digital/pedidos/'.$pedido['id'].'/sincronizar') }}" style="display:inline">
        @csrf
        <button class="botao claro icone" type="submit" title="Sincronizar" aria-label="Sincronizar"><i aria-hidden="true" class="fa fa-refresh"></i></button>
    </form>
@endsection

@section('conteudo')
<div class="grade-2">
    <div class="bloco">
        <h2><i class="fa fa-cloud"></i> LibreSign</h2>
        <p>UUID: <code>{{ $pedido['libresign_uuid'] ?? '—' }}</code></p>
        <p>File id: <code>{{ $pedido['libresign_file_id'] ?? '—' }}</code></p>
        <p>Status LS: <code>{{ $pedido['status_libresign'] ?? '—' }}</code></p>
        <p class="texto-suave">Arquivo físico somente no Nextcloud/LibreSign.</p>
    </div>
    <div class="bloco">
        <h2><i class="fa fa-qrcode"></i> Validação pública</h2>
        @if(!empty($pedido['codigo_validacao']))
            <p>Código: <strong>{{ $pedido['codigo_validacao'] }}</strong></p>
            <p>
                <a class="botao sm claro" href="{{ $pedido['url_validacao'] ?? url('/assinatura-digital/verificar/'.$pedido['codigo_validacao']) }}" target="_blank">
                    <i class="fa fa-external-link"></i> Conferir autenticidade
                </a>
            </p>
        @else
            <p class="texto-suave">Disponível quando o fluxo estiver totalmente assinado ou registrado na emissão.</p>
        @endif
    </div>
</div>

<div class="bloco">
    <h2><i class="fa fa-pencil-square-o"></i> Assinaturas</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:28px 24px;margin-top:8px;">
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
                  $statusLocal = $concluida
                      ? 'Assinado digitalmente'
                      : 'Aguardando assinatura';
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
            <p class="texto-suave">Nenhum signatário.</p>
        @endforelse
    </div>
</div>
@endsection
