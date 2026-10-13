# Módulo 2.9 — Tickets y comprobantes

## Revisión contra REQ-E2-2.9-01

La solicitud técnica exige que el comprobante sea evidencia de operaciones confirmadas por el ledger. La implementación conserva `FinancialTransaction` y `LedgerEntry` como fuente de verdad y no modifica saldos.

### Cambios realizados

- Solo `COMPLETADA` y `REVERTIDA` pueden emitir comprobante.
- Una transacción sin evidencia de ledger responde `409`.
- Se persiste únicamente la identidad del comprobante:
  - UUID técnico (`public_id`)
  - `transaction_id`
  - folio de la operación origen cuando ya existe
  - identificador aleatorio de verificación
- Consultar o verificar un comprobante no mueve fondos.
- Repetir la consulta devuelve el mismo UUID y el mismo identificador de verificación.
- Se agregan `request_id`, `correlation_id` y `api_version`.
- El QR se genera en Vue con la dependencia `qrcode` ya presente en el proyecto.
- El QR apunta a un endpoint versionado y contiene únicamente la URL con un identificador aleatorio.
- La verificación pública no devuelve wallet, saldo, credenciales ni datos personales.
- Se mantiene la impresión del navegador; no se agrega generador PDF de servidor.
- La vista web continúa limitada a transacciones de la wallet del usuario autenticado.

## API

### Comprobante autorizado

```http
GET /api/v1/financial/transactions/{transactionId}/receipt
Authorization: Bearer <financial:read>
```

La respuesta incluye evidencia privada autorizada: UUID técnico, folio si existe, estado, emisión, moneda, total, referencias, componentes de pago, verificación y movimientos del ledger.

### Verificación pública

```http
GET /api/v1/financial/receipts/{verificationId}
```

Respuesta válida mínima:

```json
{
  "data": {
    "valid": true,
    "folio": null,
    "status": "COMPLETADA",
    "issued_at": "2026-10-13T18:00:00.000000Z",
    "currency": "MXN",
    "total_amount_cents": 9500
  },
  "meta": {
    "request_id": "uuid",
    "correlation_id": "uuid",
    "api_version": "v1"
  }
}
```

Un identificador inválido devuelve `200` con `data.valid=false`. No se usa una diferencia de `404` que permita inferir la existencia de otras operaciones.

## Compras y pagos mixtos

Se inspeccionó `PurchasePaymentService`.

La transacción de wallet usa `idempotency_key`, pero `reference_id` no apunta de forma consistente a `PurchasePayment`: en un flujo usa un bono y en otro puede ser `null`. Por ello, 2.9 relaciona el pago de compra mediante la `idempotency_key` compartida, que es la referencia técnica estable observada.

Los componentes wallet/bono se exponen con sus montos reales. `payment_method` se deja `null` para compras porque los valores aceptados para clasificar pagos mixtos están marcados como decisión pendiente.

## Decisiones pendientes que NO se inventaron

1. **Formato y alcance del folio legible.**
   - Se agregó el campo persistente `folio`.
   - Solo se copia el folio de TopUp/Withdrawal cuando ya existe.
   - No se genera un prefijo, secuencia ni alcance global/por organización hasta que el equipo lo apruebe.

2. **Valores de `payment_method` para compras wallet/bono.**
   - Se devuelven `payment_components` con evidencia objetiva.
   - No se inventa un enum de negocio.

3. **Expiración/revocación de QR.**
   - El identificador es aleatorio y no secuencial.
   - No expira ni se revoca hasta que exista una regla aprobada.

4. **Evento e inscripción del Equipo 6.**
   - Se exponen `event_id` y `registration_id` solo si ya vienen en metadata de una operación autorizada.
   - No se crean relaciones ni se aceptan esos valores como prueba de pago.

## Limitación detectada fuera de 2.9

Un pago de compra cubierto 100% por bonos puede no crear `FinancialTransaction` ni `LedgerEntry` del ledger principal en la implementación actual de `PurchasePaymentService`. 2.9 no fabrica evidencia para ese caso. Corregir la trazabilidad del pago origen pertenece al flujo de pagos/bonos y debe acordarse antes de cambiarlo.

## Persistencia

La migración `financial_receipts` es reversible y no duplica importes, movimientos ni saldos. La evidencia financiera sigue viviendo en las tablas del ledger.

## Pruebas añadidas/ajustadas

- operación completada;
- consulta repetida estable;
- rechazo de transacción pendiente;
- acceso de wallet ajena;
- verificación pública válida;
- identificador de verificación inválido con respuesta segura;
- metadatos `correlation_id` y `api_version`.

Las pruebas deben ejecutarse contra el motor SQL Server configurado antes de afirmar que pasan.

## OpenAPI

Contrato: `docs/openapi/financial-2.9.yaml`.
