# Assinatura digital

Orquestra assinatura eletrônica de documentos e protocolos no i-Educar via LibreSign, com signatários internos e externos e validação pública.

Composer: `buriti/i-educar-assinatura-digital-package`

## Para que serve

Permite que a SME e as escolas solicitem, acompanhem e concluam assinaturas (atas, históricos, atestados, pedidos, certificados etc.) sem reimplementar ICP-Brasil: o LibreSign assina; o i-Educar guarda o ciclo de vida, PDF e auditoria.

## Desenho de funções

| Função | Entrega |
| --- | --- |
| Pedidos de assinatura | Criar fluxo, convidar signatários, acompanhar pendências |
| Signatários internos | Usuários do i-Educar (diretor, secretário, técnico, professor…) |
| Signatários externos | Pais, candidatos e terceiros por link seguro (sem conta SME) |
| Proxy ver/baixar | Acesso controlado ao PDF gerenciado no LibreSign |
| Validação pública | Consulta do documento assinado |
| API para apps | Notificar e abrir fluxo “assinar na web” (WebView/Custom Tab) |
| UI SME | `/assinatura-digital` e configurações |

## Quem usa

- SME e escolas (solicitantes)
- Signatários internos e externos
- Cidadãos (validação pública)

## Integrações

- LibreSign / Nextcloud
- Almoxarifado, eventos, ACT e demais módulos que emitem documentos
- Apps API

## Compatibilidade

| Produto | Versão |
| --- | --- |
| **i-Educar** | **2.12.x** |
| **i-Diário** | Portabilis i-Diário (API / SSO do núcleo) |
| Pacote | `0.1.5` |

Declarado em `composer.json` → `extra.ieducar` / `extra.idiario`.

## Documentação

| Documento | Uso |
| --- | --- |
| [`EPIC.md`](./EPIC.md) | Escopo de produto, aceite e critérios |
| [`ROADMAP.md`](./ROADMAP.md) | Fases e entregas |
| [`TODO.md`](./TODO.md) | Backlog operacional |
| [`INSTALL.md`](./INSTALL.md) | Docker LibreSign + variáveis de ambiente |
| [`docs/INTEGRACAO-LIBRESIGN.md`](./docs/INTEGRACAO-LIBRESIGN.md) | API e fluxo técnico |

---

Licença: **GPL-2.0-or-later** · Organização [i-Educar](https://github.com/i-Educar)
