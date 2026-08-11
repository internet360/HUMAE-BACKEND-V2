# Datos de seeders que no se versionan

Esta carpeta guarda catálogos que se editan fuera del ciclo de deploy. Los
archivos están en `.gitignore`; solo se versionan este README y el `.gitignore`.

## `positions.json` — catálogo de puestos

Alimenta `PositionSeeder` (tabla `positions`). Se sube a mano al servidor y el
seeder se corre desde WHM, para poder cambiar el catálogo sin un deploy.

**Fuente:** documento del cliente `PUESTOS DE BUSQUEDA.docx` — 7 áreas macro y
60 puestos estandarizados, más 13 puestos heredados que no están en el documento.

### Formato

```json
{
  "positions": [
    { "code": "quality_engineer", "name": "Ingeniero de Calidad", "area": "quality" },
    { "code": "frontend_developer", "name": "Desarrollador/a Frontend", "area": "it_systems", "legacy": true }
  ]
}
```

| Campo    | Obligatorio | Qué es                                                        |
| -------- | ----------- | ------------------------------------------------------------- |
| `code`   | sí          | Llave única del puesto. **No la cambies** una vez publicada.   |
| `name`   | sí          | Nombre visible en los selectores.                              |
| `area`   | sí          | `code` de una fila de `functional_areas`.                      |
| `legacy` | no          | `true` = no viene del documento; se ordena al final.           |

El orden del arreglo es el orden en que aparecen los puestos en los selectores.

### Reglas que el seeder valida (y por las que falla)

- JSON válido, con la clave `positions` conteniendo un arreglo no vacío.
- `code`, `name` y `area` presentes y no vacíos en cada fila.
- Sin `code` repetidos.
- Cada `area` tiene que existir en `functional_areas`.

Los errores indican el índice de la fila culpable. Si el archivo **no existe**,
el seeder no falla: avisa y se salta el catálogo, para que `migrate:fresh --seed`
siga funcionando en local y en CI.

### Cuidado al editar

`code` es la llave contra la que apuntan `candidate_profiles.position_id` y
`vacancies.position_id`. Renombrar un `code` no renombra el puesto: crea uno
nuevo y deja el viejo huérfano en los perfiles y vacantes que ya lo usaban. Para
cambiar el texto visible edita `name`, no `code`.

Borrar una fila tampoco borra el puesto de la base — `updateOrCreate` solo
agrega y actualiza. Para retirar un puesto de circulación, desactívalo desde el
panel admin (`is_active`), que conserva las referencias existentes.

### Correrlo en el servidor

```bash
php artisan db:seed --class=PositionSeeder --force
```

Es idempotente: se puede correr las veces que haga falta.
