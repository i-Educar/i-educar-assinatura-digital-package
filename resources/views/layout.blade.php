<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo', 'Assinatura digital')</title>
    <link rel="stylesheet" href="{{ url('/intranet/styles/font-awesome.css') }}">
    <style>
        :root {
            --cor: {{ $identidade['cor'] ?? '#0f4c5c' }};
            --destaque: {{ $identidade['destaque'] ?? '#1a7a8c' }};
            --clara: {{ $identidade['clara'] ?? '#e6f4f6' }};
            --nav: #15222b; --nav-texto: #e8eef3; --fundo: #e8edf2;
            --tinta: #1a242b; --suave: #5b6b76; --linha: #cfd8df;
            --ok: #17663f; --ok-fundo: #e5f6ec; --erro: #9b2c2c; --erro-fundo: #fdeceb;
            --cor-tela: var(--cor); --superficie: #fff;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", sans-serif; font-size: 14px; color: var(--tinta);
            background: radial-gradient(900px 360px at 8% -8%, color-mix(in srgb, var(--clara) 80%, transparent), transparent 55%), linear-gradient(180deg, #f3f6f8, var(--fundo)); }
        .app { display: flex; min-height: 100vh; }
        .lateral { width: 248px; flex: none; background: linear-gradient(180deg, color-mix(in srgb, var(--cor) 35%, #15222b), var(--nav)); color: var(--nav-texto); }
        .marca { display: flex; align-items: center; justify-content: center; color: #fff; text-decoration: none; padding: 14px; border-bottom: 1px solid rgba(255,255,255,.08); }
        .marca .marca-titulo { display: block; font-size: .82rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
        .marca .marca-desc { display: block; color: #8aa0ad; font-size: .72rem; }
        .icone { width: 40px; height: 40px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center;
            background: linear-gradient(145deg, var(--destaque), var(--cor)); color: #fff; }
        .modulo { padding: 8px 10px; }
        .modulo-nome { margin: 0; padding: 8px; color: #8aa0ad; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; font-weight: 700; }
        .modulo a { display: flex; align-items: center; gap: 10px; color: var(--nav-texto); text-decoration: none; border-radius: 10px; padding: 8px 10px; }
        .modulo a i { width: 18px; text-align: center; }
        .modulo a:hover { background: color-mix(in srgb, var(--cor) 42%, rgba(255,255,255,.08)); color: #fff; }
        .modulo a.ativo { background: color-mix(in srgb, var(--cor) 68%, #060b10); color: #fff; font-weight: 700; box-shadow: inset 3px 0 0 var(--destaque); }
        .miolo { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .topo { position: sticky; top: 0; z-index: 10; min-height: 56px; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 8px 18px; background: color-mix(in srgb,#fff 90%, transparent); backdrop-filter: blur(8px); border-bottom: 1px solid var(--linha); }
        .migalha { margin: 0; color: var(--suave); font-size: 13px; } .migalha a { color: var(--cor); font-weight: 700; text-decoration: none; }
        main { padding: 18px 20px 28px; flex: 1; }
        .cabeca-tela { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin: -18px -20px 16px; padding: 14px 16px 14px 20px;
            background: linear-gradient(120deg, color-mix(in srgb, var(--clara) 70%, #fff), #fff 55%); border-bottom: 1px solid var(--linha); border-left: 4px solid var(--cor-tela); }
        .identidade-tela { display: flex; align-items: center; gap: 12px; }
        .marca-tela { width: 46px; height: 46px; border-radius: 14px; display: grid; place-items: center; background: linear-gradient(145deg, var(--destaque), var(--cor-tela)); color: #fff; font-size: 18px; }
        .cabeca-tela h1 { margin: 0; font-size: 1.25rem; }
        .titulo-modulo { margin: 0 0 2px; font-size: .7rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: var(--cor-tela); }
        .cabeca-tela .lead { margin: 2px 0 0; color: var(--suave); }
        .acoes-tela { display: flex; flex-wrap: wrap; gap: 8px; margin-left: auto; }
        .botao { display: inline-flex; align-items: center; gap: 8px; background: var(--cor-tela); color: #fff; border: 0; border-radius: 10px; padding: 8px 12px; font: inherit; text-decoration: none; cursor: pointer; }
        .botao.claro { background: #fff; color: var(--cor-tela); border: 1px solid color-mix(in srgb, var(--cor-tela) 35%, var(--linha)); }
        .botao.sm { padding: 6px 9px; font-size: .85rem; }
        .flash { border-radius: 12px; padding: 12px 14px; margin: 0 0 16px; }
        .flash.ok { background: var(--ok-fundo); color: var(--ok); }
        .flash.erro { background: var(--erro-fundo); color: var(--erro); }
        .bloco { background: #fff; border: 1px solid var(--linha); border-radius: 14px; padding: 14px 16px; margin: 0 0 12px; border-top: 3px solid var(--cor-tela); }
        .bloco h2 { margin: 0 0 10px; font-size: 1.05rem; }
        .texto-suave { color: var(--suave); font-size: .85rem; }
        .acoes-linha { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        table.lista { width: 100%; border-collapse: collapse; }
        table.lista th, table.lista td { text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--linha); vertical-align: top; }
        table.lista th { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: var(--suave); }
        .badge { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: .75rem; font-weight: 700; }
        .badge.ok { background: var(--ok-fundo); color: var(--ok); }
        .badge.warn { background: #fff4e0; color: #b86e00; }
        .badge.erro { background: var(--erro-fundo); color: var(--erro); }
        .grade-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
        code { background: #f1f5f8; padding: 2px 6px; border-radius: 6px; font-size: .85rem; }
        @media (max-width: 900px) { .app { flex-direction: column; } .lateral { width: 100%; } }
    </style>
    @stack('estilos')
</head>
<body>
<div class="app">
    <aside class="lateral">
        <a class="marca" href="{{ url('/assinatura-digital') }}">
            <span class="icone"><i class="fa {{ $identidade['icone'] ?? 'fa-pencil-square-o' }}"></i></span>
            <span style="margin-left:10px">
                <span class="marca-titulo">Assinatura</span>
                <span class="marca-desc">LibreSign</span>
            </span>
        </a>
        @foreach($menuGrupos ?? [] as $grupo)
            <div class="modulo">
                <p class="modulo-nome">{{ $grupo['titulo'] }}</p>
                @foreach($grupo['itens'] as $item)
                    <a href="{{ url($item['url']) }}" class="{{ request()->is(ltrim($item['url'],'/').'*') || (request()->is('assinatura-digital') && $item['chave']==='painel') ? 'ativo' : '' }}">
                        <i class="fa {{ $item['icone'] }}"></i> {{ $item['rotulo'] }}
                    </a>
                @endforeach
            </div>
        @endforeach
    </aside>
    <div class="miolo">
        <header class="topo">
            <p class="migalha"><a href="{{ url('/') }}">Início</a> · Assinatura digital</p>
            <a class="botao claro sm" href="{{ url('/intranet/index.php') }}"><i class="fa fa-arrow-left"></i> Voltar</a>
        </header>
        <main>
            @if(session('ok'))
                <div class="flash ok">{{ session('ok') }}</div>
            @endif
            @if($errors->any())
                <div class="flash erro">{{ $errors->first() }}</div>
            @endif
            @yield('conteudo')
        </main>
    </div>
</div>
</body>
</html>
