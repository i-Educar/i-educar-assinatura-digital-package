# Assinatura digital (LibreSign)

Pacote Buriti para o i-Educar **2.12** — assinatura digital via **LibreSign**, com PDF **somente no LibreSign** e proxy ver/baixar no i-Educar + validação pública.

Composer: `buriti/i-educar-assinatura-digital-package`

## Quick start

1. `docker/libresign` — Nextcloud + LibreSign em `:8088` ([INSTALL.md](./INSTALL.md))
2. Configurar `LIBRESIGN_*` no `.env`
3. `php artisan migrate`
4. Testes:
   - `php artisan assinatura:teste unica`
   - `php artisan assinatura:teste multipla`
5. UI: `/assinatura-digital`

## Documentos

| Arquivo | Conteúdo |
| --- | --- |
| [`INSTALL.md`](./INSTALL.md) | Docker + env |
| [`EPIC.md`](./EPIC.md) | Objetivo / aceite |
| [`docs/INTEGRACAO-LIBRESIGN.md`](./docs/INTEGRACAO-LIBRESIGN.md) | API e fluxo |
| [`ROADMAP.md`](./ROADMAP.md) / [`TODO.md`](./TODO.md) | Fases |

## Padrão

`packages/buriti/PADRAO-PACOTE-2.12.md`
