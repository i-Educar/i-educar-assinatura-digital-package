{{-- Modal de pré-visualização do PDF (fila Ver / Assinar). --}}
@php
  $csrf = csrf_token();
@endphp
<style>
  .ls-pdf-modal[hidden] { display: none !important; }
  .ls-pdf-modal {
    position: fixed; inset: 0; z-index: 10050;
    display: flex; align-items: center; justify-content: center;
    padding: 16px;
  }
  .ls-pdf-modal__backdrop {
    position: absolute; inset: 0;
    background: rgba(15, 23, 42, .58);
    backdrop-filter: blur(2px);
  }
  .ls-pdf-modal__dialog {
    position: relative; z-index: 1;
    width: min(960px, 100%);
    max-height: calc(100vh - 32px);
    display: flex; flex-direction: column;
    background: #fff;
    border-radius: 16px;
    border: 1px solid #cfd8df;
    box-shadow: 0 24px 60px rgba(15, 23, 42, .28);
    overflow: hidden;
  }
  .ls-pdf-modal__head {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
    padding: 14px 16px;
    border-bottom: 1px solid #e5ebf0;
    background: linear-gradient(120deg, #e6f4f6, #fff 55%);
  }
  .ls-pdf-modal__head h3 {
    margin: 0; font-size: 1.05rem; color: #0f4c5c; line-height: 1.3;
  }
  .ls-pdf-modal__head p {
    margin: 4px 0 0; font-size: .82rem; color: #5b6b76;
  }
  .ls-pdf-modal__fechar {
    border: 0; background: #f1f5f8; color: #1a242b;
    width: 36px; height: 36px; border-radius: 10px;
    cursor: pointer; font-size: 1.1rem; flex: none;
  }
  .ls-pdf-modal__fechar:hover { background: #e2e8f0; }
  .ls-pdf-modal__body {
    flex: 1; min-height: 0;
    background: #525659;
  }
  .ls-pdf-modal__body iframe {
    width: 100%; height: min(68vh, 720px); border: 0; display: block; background: #525659;
  }
  .ls-pdf-modal__foot {
    display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; align-items: center;
    padding: 12px 16px;
    border-top: 1px solid #e5ebf0;
    background: #f8fafc;
  }
  .ls-pdf-modal__foot .botao,
  .ls-pdf-modal__foot button {
    display: inline-flex; align-items: center; gap: 8px;
    border-radius: 10px; padding: 8px 12px; font: inherit; font-weight: 700; cursor: pointer;
    text-decoration: none; border: 0;
  }
  .ls-pdf-modal__foot .botao.primario,
  .ls-pdf-modal__foot button.primario {
    background: #0f4c5c; color: #fff;
  }
  .ls-pdf-modal__foot .botao.claro,
  .ls-pdf-modal__foot button.claro {
    background: #fff; color: #0f4c5c; border: 1px solid #9ec4cb;
  }
  body.ls-pdf-modal-aberta { overflow: hidden; }
  @media (max-width: 640px) {
    .ls-pdf-modal { padding: 8px; }
    .ls-pdf-modal__body iframe { height: 58vh; }
  }
</style>

<div id="ls-pdf-modal" class="ls-pdf-modal" hidden role="dialog" aria-modal="true" aria-labelledby="ls-pdf-modal-titulo">
  <div class="ls-pdf-modal__backdrop" data-ls-pdf-fechar></div>
  <div class="ls-pdf-modal__dialog">
    <header class="ls-pdf-modal__head">
      <div>
        <h3 id="ls-pdf-modal-titulo">Documento</h3>
        <p id="ls-pdf-modal-sub">Pré-visualização do PDF gerado</p>
      </div>
      <button type="button" class="ls-pdf-modal__fechar" data-ls-pdf-fechar aria-label="Fechar">
        <i class="fa fa-times" aria-hidden="true"></i>
      </button>
    </header>
    <div class="ls-pdf-modal__body">
      <iframe id="ls-pdf-modal-frame" title="Pré-visualização do PDF" src="about:blank"></iframe>
    </div>
    <footer class="ls-pdf-modal__foot">
      <a id="ls-pdf-modal-nova-aba" class="botao claro" href="#" target="_blank" rel="noopener">
        <i class="fa fa-external-link" aria-hidden="true"></i> Abrir em nova aba
      </a>
      <form id="ls-pdf-modal-assinar" method="post" style="display:none;margin:0">
        <input type="hidden" name="_token" value="{{ $csrf }}">
        <input type="hidden" name="_redirect" id="ls-pdf-modal-redirect" value="">
        <button type="submit" class="botao primario" onclick="return confirm('Confirmar sua assinatura digital neste documento?')">
          <i class="fa fa-pencil" aria-hidden="true"></i> Assinar
        </button>
      </form>
      <button type="button" class="botao claro" data-ls-pdf-fechar>
        <i class="fa fa-times" aria-hidden="true"></i> Fechar
      </button>
    </footer>
  </div>
</div>

<script>
(function () {
  if (window.__lsPdfModalInit) return;
  window.__lsPdfModalInit = true;

  var modal = document.getElementById('ls-pdf-modal');
  if (!modal) return;
  var frame = document.getElementById('ls-pdf-modal-frame');
  var titulo = document.getElementById('ls-pdf-modal-titulo');
  var sub = document.getElementById('ls-pdf-modal-sub');
  var linkAba = document.getElementById('ls-pdf-modal-nova-aba');
  var formAssinar = document.getElementById('ls-pdf-modal-assinar');
  var redirectInput = document.getElementById('ls-pdf-modal-redirect');

  function fechar() {
    modal.hidden = true;
    document.body.classList.remove('ls-pdf-modal-aberta');
    if (frame) frame.src = 'about:blank';
  }

  function abrir(btn) {
    var pdf = btn.getAttribute('data-pdf-url') || '';
    var ver = btn.getAttribute('data-ver-url') || pdf;
    var nome = btn.getAttribute('data-titulo') || 'Documento';
    var cargo = btn.getAttribute('data-cargo') || '';
    var assinar = btn.getAttribute('data-assinar-url') || '';
    var redirect = btn.getAttribute('data-redirect') || window.location.pathname;

    if (!pdf) return;
    titulo.textContent = nome;
    if (cargo !== '') {
      // data-cargo também carrega resumo de tempos humanizados quando vier da caixa de assinados.
      sub.textContent = cargo.indexOf('Gerado ') === 0 || cargo.indexOf('Você ') === 0 || cargo.indexOf('Processo ') === 0
        ? cargo
        : (cargo.indexOf('Seu papel:') === 0 ? cargo : ('Seu papel: ' + cargo));
    } else {
      sub.textContent = 'Pré-visualização do PDF gerado';
    }
    frame.src = pdf + (pdf.indexOf('#') === -1 ? '#view=FitH' : '');
    linkAba.href = ver || pdf;

    if (assinar) {
      formAssinar.action = assinar;
      formAssinar.style.display = 'inline-flex';
      redirectInput.value = redirect;
    } else {
      formAssinar.style.display = 'none';
      formAssinar.removeAttribute('action');
    }

    modal.hidden = false;
    document.body.classList.add('ls-pdf-modal-aberta');
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t || !t.closest) return;
    if (t.closest('[data-ls-pdf-fechar]')) {
      ev.preventDefault();
      fechar();
      return;
    }
    var btn = t.closest('.js-abrir-pdf-modal');
    if (btn) {
      ev.preventDefault();
      abrir(btn);
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !modal.hidden) fechar();
  });
})();
</script>
