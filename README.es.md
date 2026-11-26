# Paymos para WHMCS

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | **Español** | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Módulo oficial de pasarela de pago de Paymos para WHMCS. Añade una opción de pago con stablecoins en la factura del cliente y, cuando la transferencia se confirma en la cadena, registra el pago a través del propio WHMCS, de modo que el aprovisionamiento, la activación de servicios y todas sus reglas de automatización se ejecutan exactamente igual que con cualquier otra pasarela.

WHMCS es un sistema de facturación, no un escaparate de tienda, y este módulo está diseñado para ese modelo. Opera sobre facturas en lugar de carritos y transmite el ID de cliente de WHMCS para que cada pago permanezca asociado a la cuenta correcta. La factura conserva la moneda en la que la emitió WHMCS; el tipo de cambio al que paga el cliente se bloquea en el momento del pago, de modo que el importe facturado coincide con el importe liquidado.

[![Ver vídeo: 12 plugins oficiales de CMS para pagos cripto — Conexión en 1 clic (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Demostración en vídeo (1:12, subtítulos en 6 idiomas):** [Ver en YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Requisitos

- WHMCS de la versión 8.13 a la 9.1;
- PHP 7.4 o superior con las extensiones `curl`, `hash`, `json` y `openssl`;
- una System URL con **HTTPS** — la conexión sobre HTTP sin cifrar se rechaza;
- una cuenta de Paymos con el proyecto de facturación abierto en el panel.

No requiere Composer ni resolución de dependencias en tiempo de ejecución: el SDK de PHP de Paymos ya viene incluido en el paquete. El panel de administración incluye inglés, ruso, alemán, español, turco y chino simplificado, y adopta automáticamente el idioma configurado por el administrador de WHMCS.

## Instalación y conexión

1. Descargue el último paquete desde [GitHub Releases](https://github.com/paymos-labs/whmcs/releases/latest).
2. Extraiga el archivo en la raíz de su instalación de WHMCS: colocará los ficheros en `modules/gateways/` e `includes/hooks/`.
3. Abra **Configuration → Apps & Integrations → Payments → Paymos → Activate** (WHMCS 8.13–9.1) para activar el módulo y abrir su panel de ajustes en **Configuration → System Settings → Payment Gateways**.
4. Abra en el panel de Paymos el proyecto que recibirá las facturas de esta instalación; ese proyecto activo se usará automáticamente.
5. Haga clic en **Conectar Paymos** (Connect Paymos) dentro del panel de Paymos en **Configuration → System Settings → Payment Gateways**.
6. Apruebe la conexión en la pestaña de Paymos: la página muestra la System URL que se va a vincular y el proyecto destino, sin ningún segundo selector de proyecto en todo el flujo.

Todos los comercios descargan el mismo paquete, sin ninguna clave de API, secreto de API, ID de proyecto, secreto de webhook, token OAuth ni código de dispositivo. Por sí solo no acepta pagos; la aprobación del paso 6 es la que activa esta instalación.

Esa única aprobación aprovisiona ambos entornos. Paymos reutiliza su única clave de pago activa en cada entorno (o crea una si no existe) y registra el webhook de facturas para esta instalación: reutiliza un webhook existente solo si coinciden exactamente la URL de retorno, la categoría y el proyecto, y deja intacto cualquier otro webhook distinto que ya apunte a esa dirección sin sobrescribirlo. El token de dispositivo generado en la aprobación es de corta duración y se descarta de inmediato; a partir de ese momento, todas las llamadas a la Merchant API se firman con HMAC.

Dirección de callback registrada para esta instalación:

```text
https://your-store.example/modules/gateways/callback/paymosgateway.php
```

Después de conectar, el módulo muestra solo tres ajustes y ninguno contiene credenciales: **Modo** (Sandbox o Producción), **Texto del botón** y una línea de solo lectura de **Conexión** que indica si la instalación está vinculada.

Deje la opción **Convert To For Processing** de WHMCS en **None**. Paymos liquida cada factura en la propia moneda con la que se emitió en WHMCS; si **Convert To For Processing** convierte la factura a otra divisa, el módulo falla de forma segura antes de crear la factura en Paymos, registra `Currency mismatch: disable Convert To For Processing` en **Billing → Gateway Log** e informa al cliente de que este método de pago no está disponible para esa factura.

## Almacenamiento de secretos

Las credenciales y el estado temporal de vinculación del dispositivo se guardan cifrados en un sobre AES-256-GCM dentro de `tblconfiguration`, con clave derivada del hash de cifrado de WHMCS. Los secretos guardados nunca se muestran en la página de administración y no existe ningún campo para introducirlos manualmente. Vuelva a conectar el módulo si cambia la System URL, revoca una clave de pago o rota un secreto de webhook.

## Qué ve su cliente

La factura muestra un botón **Pagar con Paymos** (Pay with Paymos) que abre la página de pago alojada de Paymos. Allí, su cliente elige el token y la red dentro de los habilitados en su proyecto, realiza la transferencia y sigue el cambio de estado en tiempo real. No necesita cuenta de Paymos ni se le pide dirección de correo en el pago.

Si una factura ya tiene asociada una factura activa en Paymos con el mismo importe, moneda, proyecto y entorno, se reutiliza para que recargar la página no disperse facturas a medio terminar por su cuenta. Si cambia el importe, el siguiente clic generará una nueva factura de Paymos con una nueva referencia, muy útil cuando rectifica el precio de un concepto.

## Qué ocurre con la factura en WHMCS

Solo una clase de eventos modifica la factura. Al recibir `invoice.paid` o `invoice.paid_over`, el módulo llama a `addInvoicePayment` de WHMCS, el saldo pasa a cero, la factura cambia a **Paid** y WHMCS se encarga del resto.

Todo lo anterior se anota en **Billing → Gateway Log** en lugar de alterar la factura: `invoice.confirming` mientras la transferencia reúne confirmaciones, `invoice.underpaid_waiting` cuando ha llegado solo parte del importe, e `invoice.expired` e `invoice.cancelled` cuando vence el plazo. La factura permanece en estado Unpaid y el cliente puede volver a pagarla. Este comportamiento es deliberado: si una factura de alojamiento pasara por cinco estados intermedios, activaría reglas de suspensión y avisos de impago que nunca se diseñaron para ellos.

Antes de registrar nada, todo evento terminal se verifica dos veces: primero la firma y después consultando la factura directamente en la Merchant API para compararla con la instantánea almacenada. A continuación actúan dos protecciones adicionales. Una factura de WHMCS ya marcada como Paid ignora cualquier evento posterior de finalización o retroceso, de modo que un `paid` seguido de un `paid_over` no puede registrar un segundo pago ni abonar un saldo a favor por sobrepago al cliente. Y si un evento de pago presenta un importe que ya no coincide con la factura, se registra para revisión manual en lugar de aplicarse.

## Renovaciones y servicios recurrentes

Paymos no almacena métodos de pago ni realiza cargos automáticos. Una transferencia en blockchain siempre la inicia el titular de los fondos; nadie puede cobrarla de forma programada. Por tanto, las facturas de renovación generadas por WHMCS se pagan igual que la primera: el cliente abre la factura y completa el pago.

Todo lo demás de la facturación recurrente permanece igual. WHMCS sigue generando facturas en su ciclo habitual, enviando recordatorios y ejecutando suspensiones o cancelaciones según sus reglas; el pago simplemente se aplica cuando el cliente lo envía. Si su negocio depende de cobrar tarjetas en segundo plano el día de la renovación, conviene tener claro este límite antes de instalar el módulo.

## Pruebas en Sandbox antes de pasar a producción

Configure **Modo** en Sandbox, abra una factura de prueba como cliente y avance hasta la página de pago alojada. Los pagos en Sandbox no se confirman por temporizador: abra esa factura en el panel de Paymos y dispare el resultado que desee probar. Se emiten exactamente los mismos eventos que en una transferencia real, de modo que lo que valide en Sandbox es lo que ocurrirá en Producción.

A continuación, cambie **Modo** a Producción (Live). Ambos entornos se aprovisionaron en una sola conexión, así que no necesita una segunda configuración ni reconectar el módulo.

## Entrega de webhooks y red de seguridad con cron

Los webhooks van firmados con `X-Webhook-Signature` — `t={timestamp},v1={hmac_hex}`, HMAC-SHA256, comparación en tiempo constante y compatibilidad simultánea con ambos secretos durante una rotación. Cada entrega lleva un ID de evento estable que se conserva entre reintentos; el módulo guarda esos IDs, de modo que una entrega repetida responde con 200 sin aplicarse dos veces.

Las notificaciones perdidas se recuperan mediante el cron de WHMCS. En `AfterCronJob`, como máximo una vez cada diez minutos, el módulo toma hasta 50 facturas de Paymos impagadas de las últimas 24 horas, consulta cada una en la API y la procesa por la misma ruta que un webhook, con verificación inversa, control de importe y protección frente a facturas ya pagadas. Un segundo hook `AfterCronJob` concilia los reembolsos pendientes de Paymos.

## Reembolsos

El módulo admite dos modos de reembolso calculados en el servidor mediante la Merchant API de Paymos (`/v1/refunds`): `full` (reembolso íntegro de la factura) y `overpayment` (reembolso del excedente en una factura `paid_over`). Los fondos se envían mediante una nueva transacción saliente en la cadena estrictamente a la dirección verificada `first_sender` que Paymos registró al pagarse la factura: no se introduce manualmente ninguna dirección de billetera ni el protocolo admite importes parciales arbitrarios.

Los administradores con el permiso **Refund Invoice Payments** disponen de dos puntos de entrada en una factura pagada con Paymos en WHMCS:

- el panel **Reembolsos de Paymos** (Paymos refunds) integrado directamente en la vista de factura del administrador (`AdminInvoicesControlsOutput`), que ofrece las acciones disponibles `full` y (en facturas `paid_over`) `overpayment`, oculta los modos no aplicables o ya completados y muestra el estado de la operación junto con posibles errores;
- la pestaña nativa **Refund** de la factura en WHMCS (`paymosgateway_refund`), que envía una intención de reembolso `full`. Como WHMCS no cuenta con un estado nativo de «reembolso en curso», `paymosgateway_refund` registra la solicitud sin contabilizar un cargo anticipado y actualiza la factura de WHMCS cuando el reembolso en Paymos alcanza el estado `completed`.

Cuando un reembolso `full` pasa a `completed` (por webhook o conciliación cron), el módulo lo registra con la función nativa `refundInvoicePayment` de WHMCS (sin enviar una segunda solicitud a la pasarela). En WHMCS 9.1 con **Invoice Immutability** activada, WHMCS crea de forma atómica la transacción saliente de reembolso y la nota de crédito correspondiente (`invoice_billing_adjustment_credit`) y pasa la factura a **Refunded** con saldo cero; en WHMCS 8.13 (o sin inmutabilidad) registra la transacción de reembolso estándar y marca la factura como **Refunded**.

La conciliación en segundo plano se ejecuta en el hook `AfterCronJob` de WHMCS (`includes/hooks/paymosgateway_reconcile.php`) y sincroniza las solicitudes de reembolso en curso con la Merchant API. En modo Sandbox puede simular resultados finales de reembolso (`completed`, `failed`, `cancelled`) antes de pasar a Producción.

## Solución de problemas

**La conexión no se inicia.** La System URL debe empezar por `https://`. WHMCS la obtiene de Configuration → System Settings → General Settings y el módulo compara la respuesta de aprobación con ella, por lo que una discrepancia también detendrá una conexión que antes funcionaba.

**El cliente pagó, pero la factura sigue en estado Unpaid.** Revise primero el Gateway Log. `Manual review` tiene dos causas posibles y el propio texto del registro indica cuál es: o bien el importe de la factura cambió tras crearse la factura de Paymos, o bien el importe pendiente, el modo o el proyecto cambiaron mientras la factura anterior de Paymos ya estaba pagada, seguía siendo pagable o no pudo consultarse. En el segundo caso no se emite una nueva factura y se indica al cliente que se ponga en contacto con usted; revise primero la factura anterior en el panel de Paymos. En ambos casos hay que conciliar manualmente. `Snapshot mismatch` indica que el registro guardado y la factura real no coinciden, y no se aplicará nada hasta resolver la diferencia.

**El cliente ve «Este método de pago no está disponible para esta factura.»** Revise **Billing → Gateway Log**. Si aparece `Currency mismatch: disable Convert To For Processing`, abra **Configuration → System Settings → Payment Gateways** y ajuste **Convert To For Processing** en Paymos a **None**: Paymos factura en la divisa nativa de la factura y bloquea la operación antes de crear la factura en Paymos cuando WHMCS convierte el importe a otra moneda de procesamiento.

**No aparece nada en el Gateway Log.** En ese caso el callback nunca llegó al servidor. Pruebe la URL de callback indicada más arriba desde fuera de su red: debe ser accesible sin autenticación HTTP, sin lista blanca de IP y sin redirecciones, ya que estas no se siguen.

- [Documentación](https://paymos.io/es/docs/cms-whmcs)
- [Código fuente](https://github.com/paymos-labs/whmcs)
- [Historial de cambios](CHANGELOG.md)
- [Soporte](mailto:support@paymos.io)
