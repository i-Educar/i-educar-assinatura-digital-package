# Requisitos — Assinatura digital (LibreSign)

> Pacote: `buriti/i-educar-assinatura-digital-package`  
> Legenda: **atende** / **gap** / **N/A** / **parcial** (preencher na implementação)  
> Âncora TR: **5.3.87** · relacionados: **5.3.85**, **5.3.86**, **5.12.13**

## A. TR Laguna (mapeamento)

| ID TR | Resumo | Status | Nota |
| --- | --- | --- | --- |
| 5.3.87 | Assinatura eletrônica em documentos (atestados, históricos, atas, planilhas…) | gap | Motivo deste pacote |
| 5.3.85 | Ata/boletim com opção de assinatura direção/secretário | gap | Consumidor: emitir PDF → solicitar assinatura |
| 5.3.86 | Históricos/certificados com assinatura direção/secretário | gap | Idem |
| 5.12.13 | Certificado de evento com assinatura eletrônica da autoridade | gap | Consumidor eventos-formação |
| 5.13.* | App família — ciência/assinatura quando houver documento | N/A→parcial | Via API notificar + assinar web |
| 5.14.* | App professor — ciência/assinatura institucional | N/A→parcial | Via API notificar + assinar web |

## B. Capacidades do pacote (checklist funcional)

### B1 · Motor LibreSign

| ID | Requisito | Status |
| --- | --- | --- |
| AD.1 | Configurar endpoint e credenciais LibreSign por município/tenant | gap |
| AD.2 | Enviar PDF e metadados ao LibreSign | gap |
| AD.3 | Registrar um ou mais signatários no LibreSign | gap |
| AD.4 | Obter URL web de assinatura por signatário | gap |
| AD.5 | Sincronizar status (pendente, assinado, recusado, expirado, erro) | gap |
| AD.6 | Baixar e armazenar PDF assinado no i-Educar | gap |
| AD.7 | Webhook ou polling confiável de conclusão | gap |

### B2 · Pedidos e SME

| ID | Requisito | Status |
| --- | --- | --- |
| AD.10 | Criar pedido de assinatura a partir de upload | gap |
| AD.11 | Criar pedido a partir de anexo/protocolo já existente | gap |
| AD.12 | Definir prazo e ordem (paralela/sequencial) | gap |
| AD.13 | Cancelar / reenviar / prorrogar | gap |
| AD.14 | Listar e filtrar pedidos (status, escola, tipo, período) | gap |
| AD.15 | Timeline de eventos (auditoria) | gap |
| AD.16 | Download original e assinado | gap |
| AD.17 | Permissões por perfil (SME / escola) | gap |

### B3 · Signatários internos (usuários do sistema)

| ID | Requisito | Status |
| --- | --- | --- |
| AD.20 | Selecionar usuário cadastrado no i-Educar | gap |
| AD.21 | Notificar no painel/topo (e e-mail quando configurado) | gap |
| AD.22 | Acessar URL de assinatura autenticado | gap |
| AD.23 | Registrar conclusão vinculada ao `user_id` | gap |

### B4 · Signatários externos (pais, rede de atores, afins)

| ID | Requisito | Status |
| --- | --- | --- |
| AD.30 | Cadastrar externo com nome + identificador (CPF e/ou e-mail e/ou telefone) | gap |
| AD.31 | Vincular opcionalmente a pessoa/aluno/responsável existente | gap |
| AD.32 | Gerar convite com token seguro e expiração | gap |
| AD.33 | Página pública de status + CTA assinar na web | gap |
| AD.34 | Consentimento LGPD / ciência do documento | gap |
| AD.35 | Permitir recusa com motivo | gap |
| AD.36 | Reenviar convite sem invalidar trilha anterior | gap |

### B5 · APIs Android (notificar e assinar web)

| ID | Requisito | Status |
| --- | --- | --- |
| AD.40 | Listar assinaturas pendentes do usuário autenticado (Sanctum) | gap |
| AD.41 | Detalhe com metadados do documento e `url_assinar_web` | gap |
| AD.42 | Push de nova solicitação e lembrete de prazo | gap |
| AD.43 | Deep link / documentação para abrir a URL no browser/WebView | gap |
| AD.44 | Marcar ciência (“visto”) sem assinar | gap |
| AD.45 | OpenAPI / coleção para handoff mobile | gap |
| AD.46 | **Não** exigir assinatura manuscrita nativa no app para PoC | N/A |

### B6 · Tipos de documento / protocolos

| ID | Requisito | Status |
| --- | --- | --- |
| AD.50 | Tipo ata / histórico / atestado (escolar) | gap |
| AD.51 | Tipo certificado de formação/evento | gap |
| AD.52 | Tipo protocolo/anexo de solicitação de serviços | gap |
| AD.53 | Tipo documento ACT / termo | gap |
| AD.54 | Tipo genérico (qualquer PDF) | gap |
| AD.55 | API interna para outros pacotes solicitarem assinatura | gap |

### B7 · Segurança, LGPD e operação

| ID | Requisito | Status |
| --- | --- | --- |
| AD.60 | Não expor URL de um signatário a outro | gap |
| AD.61 | Rate limit em rotas públicas | gap |
| AD.62 | Retenção / exclusão conforme política municipal | gap |
| AD.63 | Multi-tenant (`PackageTenant`) | gap |
| AD.64 | Logs sem dados sensíveis em claro (tokens) | gap |

## C. Critério PoC (≥ 85%)

Demonstrar no mínimo:

1. Pedido SME com **2 signatários internos** → PDF assinado.
2. Pedido com **1 signatário externo** via convite → PDF assinado.
3. Usuário no **app** recebe push e abre **assinar web**.
4. Auditoria consultável no SME.

Itens AD.* fora desse núcleo podem ficar no plano de 30 dias.
