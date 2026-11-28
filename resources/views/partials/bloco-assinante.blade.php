{{-- Bloco minimalista: nome · cargo · Conferir autenticidade --}}
@php
  $nome = trim((string) ($assinante['nome'] ?? $assinante['displayName'] ?? ''));
  $cargo = trim((string) ($assinante['cargo'] ?? ''));
  $quando = trim((string) ($assinante['quando'] ?? ''));
  $status = trim((string) ($assinante['status'] ?? ''));
  // Só código/URL do signatário — nunca o do documento.
  $codigo = trim((string) ($assinante['codigo_validacao'] ?? ''));
  $url = trim((string) ($assinante['url_validacao'] ?? ''));
  $cor = $cor ?? '#0a7a8a';
@endphp
<div class="ls-assina" style="text-align:center;padding-top:36px;page-break-inside:avoid;">
  <div style="border-top:1px solid #1a242b;margin:0 18px 8px;height:0;"></div>
  <strong style="display:block;font-size:11px;color:#1a242b;">{{ $nome !== '' ? $nome : '________________' }}</strong>
  @if($cargo !== '')
    <span style="display:block;color:#5b6b76;font-size:9px;line-height:1.35;">{{ $cargo }}</span>
  @endif
  @if($quando !== '')
    <span style="display:block;color:#5b6b76;font-size:8px;">{{ $quando }}</span>
  @endif
  @if($status !== '')
    <span style="display:block;color:#5b6b76;font-size:8px;">{{ $status }}</span>
  @endif
  @if($codigo !== '' || $url !== '')
    @php $label = 'Conferir autenticidade'.($codigo !== '' ? ' · '.$codigo : ''); @endphp
    @if($url !== '')
      <a href="{{ $url }}" style="display:inline-block;margin-top:6px;font-size:8px;color:{{ $cor }};text-decoration:none;border-bottom:1px dotted {{ $cor }};">{{ $label }}</a>
    @else
      <span style="display:inline-block;margin-top:6px;font-size:8px;color:{{ $cor }};">{{ $label }}</span>
    @endif
  @endif
</div>
