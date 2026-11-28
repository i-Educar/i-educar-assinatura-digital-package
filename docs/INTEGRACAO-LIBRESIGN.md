# Integração LibreSign — implementação Laguna

Referências:

- [Developer manual](https://docs.libresign.coop/main/developer_manual/)
- [API guide](https://docs.libresign.coop/main/developer_manual/api/guide-api.html)
- [NCDD](https://github.com/LibreCodeCoop/nextcloud-docker-development)
- Compose local: `docker/libresign/`

## Rodapé do PDF (LibreSign)

Por padrão o LibreSign desenha QR grande no rodapé — isso **cobre o conteúdo** e pode gerar página extra.

Configuração Laguna (bootstrap Docker + API no ping / ao criar pedido):

- `write_qrcode_on_footer=0`
- `footer_signed_by=Signed by LibreSign`
- `footer_validate_in=` (vazio)
- `footer_template` Twig de **uma linha** (destaque só no Signed by LibreSign)

Variáveis: `LIBRESIGN_FOOTER_QRCODE=false`, `LIBRESIGN_FOOTER_SIGNED_BY`, `LIBRESIGN_ADMIN_USER/PASSWORD` (para aplicar via API).

Documentos **já assinados** com QR antigo não mudam — é preciso reemitir / assinar de novo.

## Decisão de armazenamento

O **PDF assinado permanece no LibreSign**. O i-Educar guarda só metadados (`assinatura_digital.pedido`) e faz:

- **Ver** → `GET /assinatura-digital/pedidos/{id}/ver` (inline, proxy)
- **Baixar** → `GET /assinatura-digital/pedidos/{id}/baixar` (attachment, proxy)
- **Validar** → registra no `ValidadorDocumentoBridge` (Exportação) com links de ver/baixar

Não há `Storage::put` do PDF no disco do i-Educar quando `ASSINATURA_SOMENTE_LIBRESIGN=true`.

## Adapter

```text
LibreSignClient
  requestSignature(name, pdfBinary, signers) → id, uuid, status
  listFiles / findFileByUuid
  signByUuid(signRequestUuid, method, asUser, asPassword)
  downloadFromUrl(url) → pdfBytes
  ping()
```

Base OCS: `{LIBRESIGN_BASE_URL}/ocs/v2.php/apps/libresign/api/v1`  
Header obrigatório: `OCS-APIRequest: true`  
Auth: Basic (usuário + senha ou App Password)

## Status LibreSign

| Código | Significado |
| --- | --- |
| 0 | Draft |
| 1 | Able to sign |
| 2 | Partially signed |
| 3 | Signed (terminal) |
| 4 | Deleted |

## Testes

| Cenário | Signatários | Comando / UI |
| --- | --- | --- |
| Única | `signer1` | `assinatura:teste unica` · `/testes` |
| Múltipla | `signer1` + `signer2` | `assinatura:teste multipla` · `/testes` |

PHPUnit (Http::fake): `tests/Feature/AssinaturaUnicaEMultiplaTest.php`

## Orquestrador (todos os documentos com assinatura)

`AssinaturaOrquestrador` é a porta única para módulos (exportação unificada e demais):

1. PDF gerado com bloco minimalista sob cada assinatura: **Conferir autenticidade · {código}**
2. Signatários 1..N mapeados por `ASSINATURA_CARGO_MAP` (ou fallback `signer1`/`signer2`…)
3. Pedido criado no LibreSign; PDF permanece só no Nextcloud
4. Mesmo código/URL de validação da exportação é reutilizado no pedido

## Configuração na UI (preferencial)

Fluxo padrão Laguna (igual aos demais módulos):

1. **Configurações → Módulos → Assinatura digital** (`/configuracoes/modulos?modulo=assinatura`)
2. Ou no app: `/assinatura-digital/configuracoes` → «Editar no catálogo»
3. Campos: URL LibreSign, usuário/senha, SSL, timeout, signatários 1–4, mapa cargo→conta, PDF só no LibreSign, validação pública, encaminhar exportação

Runtime: `ConfigAssinatura::hidratarDoAmbiente()` aplica as diretivas salvas em `settings` sobre `config('assinatura.*')`.  
Senhas em branco no formulário **mantêm** o valor já gravado.

## Variáveis (.env — bootstrap / fallback)

```env
ASSINATURA_DIGITAL_ATIVO=true
LIBRESIGN_BASE_URL=http://localhost:8088
LIBRESIGN_USER=ieducar
LIBRESIGN_PASSWORD=ieducar
LIBRESIGN_VERIFY_SSL=false
ASSINATURA_SOMENTE_LIBRESIGN=true
ASSINATURA_VALIDACAO_PUBLICA=true
ASSINATURA_CARGO_MAP="Responsável técnico:signer1,Gestor(a) da unidade:signer2"
EXPORTACAO_LIBRESIGN=true
EXPORTACAO_ASSINATURAS=true
```
