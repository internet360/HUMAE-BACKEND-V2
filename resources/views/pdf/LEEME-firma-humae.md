# Firma del apoderado de HUMAE

> **Lo normal es cargarla desde el panel**, no por aquí: Administración →
> Contrato → «Firma de HUMAE». Queda en el disco privado, no requiere acceso al
> servidor, y la zona de carga muestra un fondo cuadriculado para que se vea si
> el PNG es realmente transparente.
>
> Esta carpeta es el **respaldo**: si no hay firma cargada en el panel, el
> contrato usa el archivo de abajo. Sirve para entornos recién creados donde
> nadie entró todavía al panel.

El archivo de respaldo va acá:

```
resources/views/pdf/humae-signature.png
```

Es la firma escaneada de quien firma **por EL PRESTADOR** en el contrato de
prestación de servicios (`company-contract.blade.php`). Pega la imagen con ese
nombre exacto y no hace falta tocar código ni configuración.

## Mientras no exista

El contrato se genera igual, pero el bloque de firmas sale con la línea del lado
de HUMAE **vacía**: firmado sólo por el cliente. Funciona para probar el flujo;
no lo envíes así a una empresa real.

El nombre y el cargo que aparecen bajo la línea **no** vienen de aquí: se editan
en el panel (tabla `contract_settings`). Las variables `CONTRACT_SIGNATORY_NAME` y
`CONTRACT_SIGNATORY_TITLE` del `.env` solo siembran esos valores la primera vez
que se consulta la configuración; después manda lo que diga el panel.

## Requisitos de la imagen

| Qué | Por qué |
|---|---|
| **PNG con fondo transparente** | El PDF es blanco; un fondo opaco dibuja un recuadro alrededor de la firma. |
| **Ancho ~600 px** (alto proporcional) | El Blade la limita a 200 px de ancho impreso; menos resolución se ve pixelada. |
| **Trazo oscuro** (negro o azul tinta) | Contraste sobre el blanco del documento. |
| **Sin márgenes sobrantes** | El recorte define dónde se apoya sobre la línea de firma. |

## Si necesitas otra ruta o otro nombre

Se configura sin editar el Blade — la ruta es relativa a `resources/`:

```env
CONTRACT_SIGNATORY_SIGNATURE=views/pdf/humae-signature.png
```

## Cómo verificar que quedó bien

```bash
php artisan tinker --execute="echo is_file(resource_path(config('contracts.signatory.signature_path'))) ? 'OK' : 'FALTA';"
```

Y para verla en el documento, abre la vista previa del contrato desde el paso 3
del wizard de firma, o `GET /api/v1/me/company/contract/preview`.

> DomPDF corre con `isRemoteEnabled = false` y la imagen se incrusta como data
> URI: tiene que ser un archivo local, no una URL.
