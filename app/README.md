# Muse Media Ops

Herramienta interna para configurar flujos de imagen por marca y campaña, generar piezas, editar versiones y consultar la galería. Laravel 12, Filament 5, Livewire 4, Tailwind CSS 4.1+ y PHP 8.5. La aplicación vive en `app/`; ejecuta los comandos siguientes desde la raíz del repositorio con Docker y DDEV disponibles.

## Desarrollo local

En un clon nuevo, copia `app/.env.example` a `app/.env` si todavía no existe. Inicia DDEV e instala las dependencias dentro del contenedor:

```sh
ddev start
ddev composer install
ddev exec bash -c 'cd /var/www/html/app && npm ci'
ddev artisan key:generate --no-interaction
```

Genera `APP_KEY` solamente en una instalación nueva: las claves de marca existentes dependen de esa clave de cifrado. Configura las variables locales de la tabla de abajo antes de migrar. La primera instalación crea el usuario Art Director `ad@picante.local`, con la contraseña de `SEED_AD_PASSWORD` (el valor de desarrollo por defecto del seeder es `password`). Crea los editores y asígnales sus marcas desde administración.

```sh
ddev artisan migrate --seed --no-interaction
ddev launch
ddev exec bash -c 'cd /var/www/html/app && npm run dev'
```

El panel de administración está en `/admin`; el panel editor, en `/app`. Para una instalación existente usa `ddev artisan migrate --no-interaction`, sin volver a ejecutar los seeders. Para compilar los assets usa `ddev exec bash -c 'cd /var/www/html/app && npm run build'`. El comando abreviado `ddev npm` no está disponible en este entorno; usa siempre la ruta explícita de `app/`.

Para trabajar sin gasto de proveedor, establece **`APP_ENV=local` y `FAKE_ENGINE=1`** en la configuración local. Después de cambiar variables o código del worker:

```sh
ddev artisan config:clear --no-interaction
ddev exec supervisorctl restart 'webextradaemons:*'
ddev exec supervisorctl status
```

La simulación permite probar conexión, esquemas y trabajos con imágenes de prueba. Devuelve cuatro mockups de producto distintos de 1024 × 768 por ejecución, rotulados como simulación local; no interpreta el prompt ni demuestra calidad del proveedor o salida 4K. La bandera solo funciona en `local`: no habilita simulación en staging, previews de Cloud ni producción. Los tests habilitan sus propios dobles explícitamente.

## Colas, scheduler y herramientas

DDEV inicia dos daemons: `queue-worker` (`queue:work --timeout=150 --tries=1 --sleep=2`) y `scheduler` (`schedule:work`). Ambos deben aparecer como `RUNNING` en `ddev exec supervisorctl status`. Usa el reinicio anterior tras cambiar código de trabajos o configuración.

| Comando programado | Frecuencia | Comportamiento |
| --- | --- | --- |
| `media:reconcile` | Cada minuto | Recupera trabajo local pendiente sin volver a enviar una generación al proveedor. |
| `media:clean-inputs` | Cada hora | Marca cargas finalizadas hace más de 24 horas sin referencias. En una ejecución posterior, tras más de 10 minutos de gracia, vuelve a comprobar las referencias bajo bloqueo. |
| `queue:prune-failed` | Diario | Elimina registros de trabajos fallidos con la retención predeterminada de Laravel: 24 horas. |

Los tres comandos evitan ejecuciones solapadas. Consulta el calendario con `ddev artisan schedule:list --no-interaction`. La limpieza es **horaria**, conforme a la decisión de integración, aunque el encabezado inicial del plan decía diaria. Conserva cargas referenciadas por generaciones o pipelines; al enlazar una carga se bloquea su fila y se retira la marca. Se confirma la eliminación de la fila antes de intentar borrar el objeto. Si falla el almacenamiento, el comando devuelve error y muestra únicamente el ID de la carga; puede quedar un objeto huérfano. No barre prefijos de piezas, portadas o logotipos. Los temporales de Livewire tienen su propia expiración de un día en `inputs/tmp/`.

Redis y la conexión de cola database usan `retry_after=420` segundos. Los límites de los trabajos son 90 segundos para envío, 60 para consulta y 120 para descarga; el worker usa 150, siempre inferior a 420. Cada generación autoriza un solo envío al proveedor. Consultar estado o recuperar una descarga reutiliza los trabajos conocidos. Una nueva ejecución completa requiere la confirmación del editor y puede generar un nuevo cargo; conserva el snapshot de la solicitud original.

```sh
ddev pest
ddev pint
ddev pint --test
ddev mysql
ddev mc --help
ddev minio
ddev redis-cli
```

Pest usa la base separada `muse_test` creada por DDEV; las pruebas habituales simulan almacenamiento y HTTP. `ddev minio` abre la consola local. Todas las imágenes, incluidos temporales de Livewire, usan almacenamiento de objetos privado; los enlaces de vista y descarga se emiten tras autorizar el registro y caducan a los 10 minutos. El límite por imagen es 20 MiB (JPEG, PNG o WebP) y el agregado por solicitud es 96 MiB.

## Variables de entorno

Mantén valores reales en el archivo local ignorado o en los secretos del entorno. Los siguientes valores de acceso a servicios DDEV son únicamente los valores locales de desarrollo.

| Variables | Configuración |
| --- | --- |
| `APP_NAME`, `APP_ENV`, `APP_URL` | `Muse Media Ops`, `local`, URL que abre `ddev launch`. En Cloud usa el entorno y dominio reales. |
| `APP_KEY`, `APP_DEBUG` | Clave generada por Laravel; debug solo en local. |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE` | `es`. |
| `SEED_AD_PASSWORD` | Contraseña inicial del Art Director; úsala al ejecutar el seeder. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Local: `mysql`, `db`, `3306`, `db`, `db`, `db`. DDEV administra la conexión local; Cloud inyecta sus credenciales. |
| `QUEUE_CONNECTION` | `redis`. |
| `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | Local: `phpredis`, `redis`, `6379`, `null`. Cloud puede inyectar `REDIS_URL` con TLS y autenticación. |
| `CACHE_STORE`, `SESSION_DRIVER` | `redis` para caché y sesiones compartidas. Cloud requiere prefijos y bases aislados por entorno. |
| `FILESYSTEM_DISK` | `inputs`; los discos `inputs` y `pieces` usan prefijos separados en el mismo bucket privado. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | Local MinIO: ambos `ddevminio`. En AWS usa los secretos del usuario IAM autorizado. |
| `AWS_DEFAULT_REGION`, `AWS_BUCKET` | Local: `us-east-1`, `muse-media`. En AWS, bucket y región reales. |
| `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` | Local: `https://muse.ddev.site:10101`, `true`; el endpoint debe ser accesible al contenedor y al navegador. En AWS elimina el override y usa `false`. |
| `AWS_URL` | Déjala vacía; no publiques un origen alternativo. |
| `MEDIA_URL_PROVIDER`, `MEDIA_SIGNED_URL_TTL` | Local: `presigned`, `600`; Cloud: `cloudfront`, `600`. |
| `CLOUDFRONT_DOMAIN`, `CLOUDFRONT_KEY_PAIR_ID` | Dominio de distribución y ID de clave pública para URLs firmadas. |
| `CLOUDFRONT_PRIVATE_KEY_BASE64`, `CLOUDFRONT_PRIVATE_KEY_PATH` | PEM privado en base64 mediante secreto de Cloud, o ruta accesible en cada réplica. Base64 tiene prioridad y una clave inválida falla de forma segura. |
| `KREA_API_KEY`, `KREA_BASE_URL` | Clave opcional del estudio y `https://api.krea.ai`; una clave de marca tiene prioridad. Las claves se resuelven en servidor desde la fuente fijada al crear el trabajo. |
| `FAKE_ENGINE` | `1` para simulación exclusivamente con `APP_ENV=local`; `false` en Cloud. |
| `LOG_CHANNEL`, `LOG_LEVEL` | Cloud: `stderr`, `warning`. No incluyas claves, data URLs, entradas completas ni URLs firmadas en logs. |

## Añadir y configurar un pipeline

1. Como Art Director, crea una marca, configura su clave si corresponde y usa «Probar conexión». En modo fake la comprobación es simulada.
2. Crea una campaña y añade sus pipelines con tipo `generator`, `editor` o `upscaler` y la referencia de versión del node app.
3. Actualiza el esquema. Configura tipo, etiqueta, visibilidad, rol y valores fijos de cada campo; las imágenes fijas se cargan al almacenamiento privado. Resuelve los campos pendientes o incompatibles antes de activar.
4. El editor requiere exactamente un rol de imagen y uno de prompt; el upscaler requiere un rol de imagen y ningún prompt. Configura los demás campos obligatorios como ocultos con valores fijos. El editor recibe la pieza y la instrucción desde el visor; el upscaler recibe la pieza y, si el esquema lo permite, dimensiones o factor calculados.
5. Activa el pipeline cuando esté listo y selecciona el generador predeterminado de la campaña. Asigna el editor a la marca para que pueda generar y consultar sus piezas.

Referencias seleccionadas para esta entrega:

| Uso | Referencia | Campos del esquema disponible |
| --- | --- | --- |
| Generador | `fbe97b3b-d810-4de4-859f-49aa2a7887ab` | `describe_la_escena`. |
| Editor | `1276c054-ee4c-4ec4-8e2f-8c8b9901557b` | `foto_para_editar`, `quiero_editar`. |
| Upscaler provisional | `1276c054-ee4c-4ec4-8e2f-8c8b9901557b` | Reutiliza el esquema del editor; no expone control de tamaño calificado. |

La continuidad con estos node apps imperfectos fue autorizada; la calificación real (Gate B) sigue pendiente. Los archivos en `tests/Fixtures/krea/schema-*.json` describen esquemas, no ejecuciones reales verificadas. La aplicación acepta como 4K únicamente una salida de tipo upscale que mida `round(W × s)` por `round(H × s)`, con `s = 3840 / max(W, H)`. La referencia provisional no garantiza ese resultado; una salida que no cumple se registra como fallo de tamaño.

Antes de habilitar ejecuciones reales, rota la clave usada por el prototipo y configura una clave nueva mediante secretos o administración. No reutilices ni copies la clave del prototipo a este repositorio.

## Infraestructura y validación pendiente

Muse Media Ops targets Laravel Cloud with `app/` as the application root, managed MySQL, Redis queues, private AWS S3, and CloudFront signed media URLs. Start with [the production environment reference](.env.production.example), the [Cloud deployment research](../docs/superpowers/research/2026-09-07-laravel-cloud-deployment.md), and the [dated infrastructure checklist](../docs/superpowers/plans/2026-09-07-infra-checklist.md). All real values belong in environment settings or secrets.

Run the Redis worker on a dedicated Worker cluster with `php artisan queue:work redis --timeout=150 --tries=1`; the application keeps `retry_after=420` and job budgets of 90/60/120 seconds. Use `CLOUDFRONT_PRIVATE_KEY_BASE64` for a Cloud-injected signing secret, or `CLOUDFRONT_PRIVATE_KEY_PATH` where every replica has a provisioned PEM. The base64 value takes precedence and invalid keys fail closed. Enable Cloud's scheduler on one selected cluster and verify shared Redis locks there. Cloud Flex's 90-second shutdown grace requires separate worker shutdown validation; it is not a fixed runtime cap.

The real local MinIO 15 MiB upload/preview/download server flow passed in Task 24 (36 assertions, 65,028,096-byte peak under a 256 MiB PHP limit). Local fake-engine, Livewire, queue, viewer, gallery, and download tests provide server-side integration evidence. They do not establish a completed browser walkthrough or real Krea qualification. CUA was unavailable at the last browser check; visual validation remains pending.

The full manual walkthrough remains: brand/key and connection → campaign → three pipelines → schema refresh/configuration/activation → editor login → generate/wait → viewer → edit → 4K → gallery filters → download. Run the local rehearsal with `FAKE_ENGINE=1`; real execution requires a separately authorized Gate B run with its fresh key and spend ceiling. The fake engine's 1024 × 768 demo mockups deliberately cannot qualify 4K.

Browser rendering and expiry checks, AWS/Cloud staging, memory sizing, worker shutdown, remote scheduler/retention, and CI deployment gating remain pending; see the checklist for exact evidence and owner checks. No remote deployment or real Krea execution was performed.
