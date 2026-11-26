# EPIC — Assinatura digital (LibreSign)

| Campo | Valor |
| --- | --- |
| Pacote | `buriti/i-educar-assinatura-digital-package` |
| TR Laguna | **5.3.87** (assinatura eletrônica em documentos) · apoia **5.3.85/5.3.86** (atas/históricos) · **5.12.13** (certificado com assinatura) · transversal a protocolos/anexos |
| Prioridade | **P0** (transversal PoC / compliance documental) |
| Motor de assinatura | **LibreSign** ([LibreCode](https://librecode.coop/)) |
| Fonte | PE 77/2026 Laguna · cooperação Buriti / LibreCode |
| Regra de ouro | PoC ≥ 85% dos fluxos deste EPIC · gaps ≤ 30 dias |
| Base atual | i-Educar 2.12 · Sanctum (apps) · anexos/auditoria Laguna · **sem** pacote de assinatura no monorepo |

## Objetivo

Disponibilizar no i-Educar um módulo de **assinatura digital de documentos, protocolos e artefatos** (atas, históricos, atestados, chamados, editais ACT, certificados de formação, etc.), orquestrado pelo SME e executado via **LibreSign**, com:

1. **Signatários internos** — usuários cadastrados no i-Educar (diretor, secretário, técnico SME, professor…).
2. **Signatários externos** — pais/responsáveis, candidatos ACT, rede de atores e afins **sem** conta SME, convidados por link seguro.
3. **Canal Android** — APIs para **notificar** e abrir o fluxo de **assinar na web** (WebView / Custom Tab), sem exigir assinatura nativa no app.

Este pacote **não** reimplementa ICP-Brasil do zero: integra LibreSign como provedor de assinatura e guarda no i-Educar o ciclo de vida (pedido → pendências → concluído → PDF assinado + trilha de auditoria).

## Padrão técnico (i-Educar 2.12)

| Item | Valor |
| --- | --- |
| Alvo | i-Educar **2.12** · PHP **≥ 8.3** · Illuminate **^11\|^12\|^13** |
| Composer | `buriti/i-educar-assinatura-digital-package` |
| Namespace | `iEducar\Packages\BuritiAssinaturaDigital\` → `src/` |
| Compat | `extra.ieducar.compatible: ["2.12"]` · GPL-2.0-or-later · `type: library` |
| Schema | `assinatura_digital` (pedidos, signatários, eventos, vínculos a documentos) |
| Rotas SME | prefixo `/assinatura-digital` · middleware `ieducar.*` + `auth` |
| Rotas públicas | `/assinatura-digital/convite/{token}` (externo) |
| API apps | `api/apps/v1/assinaturas/*` (Sanctum) **ou** proxy no `apps-api-package` |
| Tenancy | `PackageTenant::allows('buriti/i-educar-assinatura-digital-package')` |
| Referência | `packages/buriti/PADRAO-PACOTE-2.12.md` |

## Arquitetura (visão)

```text
  Módulos i-Educar (escolar, ACT, eventos, solicitação, …)
            │  anexa PDF / metadados
            ▼
  assinatura-digital-package  ──►  LibreSign (API)
            │                         │
            │  pedido + signatários   │ URLs de assinatura
            ▼                         ▼
     SME (solicitar / acompanhar)   Página web LibreSign
            │                         ▲
            │  push + deep link       │ assinar (interno ou externo)
            ▼                         │
     App Android (notificar) ─────────┘  WebView / browser
```

## Épicos filhos

1. **Integração LibreSign** — credenciais, criação de arquivo, convite de signatários, status, download do PDF assinado, webhooks/callback.
2. **Pedidos no SME** — criar solicitação a partir de documento/protocolo; ordem de assinatura; prazos; cancelamento; reenvio.
3. **Signatários internos** — seleção de usuários i-Educar; notificação no painel/topo; link autenticado.
4. **Signatários externos** — CPF/e-mail/telefone; token de convite; tela pública; LGPD/consentimento; expiração.
5. **APIs Android** — listar pendências, detalhe, deep link para assinar na web, push (device/tópico), marcar “visto”.
6. **Catálogo de tipos** — mapeamento de artefatos (ata, histórico, atestado, protocolo, certificado, anexo de chamado…).
7. **Auditoria e validação** — trilha quem/quando; verificação pública do documento assinado (quando LibreSign/ICP permitir).
8. **Gate PoC** — fluxo feliz interno + externo + notificação app demonstráveis.

## Critérios de aceite (PoC)

- [ ] Checklist [`REQUISITOS.md`](./REQUISITOS.md) 100% classificado (atende / gap / N/A)
- [ ] ≥ **85%** dos fluxos críticos demonstráveis (SME + externo + API notificar/assinar web)
- [ ] Gaps ≤ **15%** com plano ≤ **30 dias**
- [ ] Evidência: 1 documento assinado por usuário interno + 1 por signatário externo + 1 notificação no canal app
- [ ] PDF assinado armazenado/recuperável no i-Educar com auditoria

## Fora de escopo deste EPIC

- UI nativa Android de desenhar assinatura (o app **abre a web** LibreSign/i-Educar).
- Contas Play Store / App Store e publicação do app (equipe mobile / apps-api handoff).
- Substituição do LibreSign por outro motor (pode ser adapter futuro; PoC = LibreSign).
- ICP-Brasil em HSM municipal sem passar pelo LibreSign (salvo decisão explícita).

## Dependências

- **LibreSign** operacional (Nextcloud LibreSign ou instância acordada com LibreCode).
- **apps-api** (Sanctum, devices, push) para canal Android.
- Módulos produtores de PDF: escolar/aderência, ACT, eventos, solicitação, exportação 4.x, etc.
- Identidade de pessoa (cadastro) para vincular externos a responsável/aluno quando existir.
