# UPEC Market

Marketplace desacoplado: React/Vite funciona como cliente y Laravel expone una API JSON versionada. Los datos del mercado y las reglas de negocio se gestionan en Laravel; React no se conecta directamente a PostgreSQL.

## Estructura

```text
backend/       API Laravel 12
upec-market/   cliente React + Vite
```

## Configurar y ejecutar

### 1. API Laravel y PostgreSQL

Conserva el `backend/.env` que ya configuraste. Comprueba que tenga `DB_CONNECTION=pgsql` y los valores válidos de host, puerto, base, usuario y contraseña. No publiques ni compartas ese archivo.

Si usas Supabase y el host directo `db.<project-ref>.supabase.co` no resuelve en Windows, verifica ese host en **Supabase → Connect**. El endpoint directo puede requerir IPv6; para redes IPv4 usa **Session pooler** y copia de allí el host, puerto y usuario exactos a `DB_HOST`, `DB_PORT` y `DB_USERNAME` (el usuario del pooler suele incluir `.<project-ref>`). No inventes el host/región ni publiques `DB_PASSWORD`. La conexión exige SSL (`DB_SSLMODE=require`). Reinicia `php artisan serve` después de editar `.env`; verifica el acceso con `php artisan migrate:status` antes de correr `php artisan migrate --seed`.

En una terminal:

```powershell
Set-Location .\backend
composer install
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

La migración detecta las tablas `users`, `categories`, `products` y `orders` del SQL compartido, conserva esos registros y agrega campos/tablas necesarios. **No uses `migrate:fresh` ni `db:wipe`** sobre la base con datos. El seeder inserta las categorías iniciales sin crear usuarios de prueba.

La API queda en `http://127.0.0.1:8000/api/v1`; la comprobación es `GET /api/v1/health`. Para habilitar las imágenes de productos subidas al disco público, ejecuta una vez `php artisan storage:link` desde `backend`.

El rol `admin` debe asignarlo una persona con acceso autorizado a PostgreSQL. Por ejemplo, después de reemplazar el correo por el de la cuenta institucional elegida, puede ejecutar:

```sql
UPDATE users SET role = 'admin' WHERE email = 'admin@upec.edu.ec';
```

No se permite que un usuario se otorgue ese rol desde React. Después del cambio, la cuenta debe cerrar sesión y volver a ingresar para cargar el rol actualizado. El enlace **Moderación** aparece a toda persona autenticada; solo el rol `admin` puede consultar o aprobar productos, y el backend conserva esta restricción.

### Despliegue en Render con Docker

Render ejecuta Laravel mediante el contenedor [`backend/Dockerfile`](backend/Dockerfile). El frontend React/Vite se publica por separado como un **Static Site** de Render; no necesita PHP ni Docker.

1. Crea un **Web Service** conectado al repositorio y selecciona **Docker**. Usa `backend/Dockerfile` como Dockerfile y la raíz del repositorio (`.`) como contexto de Docker.
2. Configura en el Web Service estas variables de entorno desde el panel de Render; no las guardes en el repositorio:

   ```dotenv
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=<clave generada para Laravel>
   APP_URL=https://<nombre-api>.onrender.com
   DB_CONNECTION=pgsql
   DB_HOST=<host-postgresql>
   DB_PORT=5432
   DB_DATABASE=<base>
   DB_USERNAME=<usuario>
   DB_PASSWORD=<contraseña>
   DB_SSLMODE=require
   FRONTEND_URLS=https://<nombre-frontend>.onrender.com
   ```

   Genera `APP_KEY` localmente ejecutando `php artisan key:generate --show` desde `backend` y guarda el resultado solo en las variables del servicio. Configura también `STRIPE_SECRET` y `STRIPE_WEBHOOK_SECRET` si habilitarás pagos.
3. Configura en Render la ruta del health check como `/up`. El contenedor escucha el puerto `PORT` que Render asigna y aplica `php artisan migrate --force` al iniciar (se puede desactivar con `RUN_MIGRATIONS=false`). La migración del proyecto es aditiva; no usa `migrate:fresh` ni elimina tablas.
4. Crea un **Static Site** para el frontend con directorio raíz `upec-market`, comando de compilación `npm ci && npm run build` y directorio de publicación `dist`. Define durante la compilación `VITE_API_BASE_URL=https://<nombre-api>.onrender.com/api/v1`.
5. Sustituye `FRONTEND_URLS` por el dominio real del Static Site. Si Render asigna otro nombre de dominio al servicio, actualiza ambas variables para que el cliente apunte a la API y la API acepte el origen del navegador.

La instancia del contenedor tiene almacenamiento efímero. Si se conservarán imágenes subidas después de reinicios o despliegues, agrega un disco persistente al servicio y móntalo en `/var/www/html/storage/app/public`; sin ese disco, las imágenes almacenadas localmente pueden perderse.

### 2. Stripe Checkout

Agrega estas variables a `backend/.env` usando las claves de tu cuenta de Stripe (para pruebas, claves y webhook de prueba):

```dotenv
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_CURRENCY=usd
FRONTEND_APP_URL=http://localhost:5173
```

Configura en Stripe un webhook que apunte a `https://TU_API/api/v1/stripe/webhook` y suscríbelo a `checkout.session.completed`, `checkout.session.async_payment_succeeded` y `checkout.session.expired`. Localmente puedes reenviar eventos con Stripe CLI. Sin clave secreta el endpoint de compra responde `503`; no inventa una confirmación de pago. La orden solo pasa a `Completado` después de verificar el pago con Stripe.

El total, la comisión del 3% y el saldo calculado para el vendedor se guardan en `orders`. Esta primera integración recibe el cobro en la cuenta Stripe de la plataforma; **no hace transferencias automáticas a vendedores**. La liquidación al vendedor es manual hasta configurar Stripe Connect y guardar/verificar la cuenta conectada de cada vendedor.

### 3. Cliente React

En una segunda terminal:

```powershell
Set-Location .\upec-market
npm install
npm run dev
```

Vite reenvía `/api` y `/storage` a `http://127.0.0.1:8000`. Para cambiarlo, define `VITE_API_PROXY_TARGET` en `upec-market/.env.local`. Para desplegar con el API en otro dominio, configura `VITE_API_BASE_URL` al compilar y agrega el origen del frontend a `FRONTEND_URLS` en `backend/.env`.

## Funciones conectadas

- Registro/inicio/cierre de sesión con token Sanctum y restricción al dominio `@upec.edu.ec`.
- Catálogo, categorías, búsqueda, publicaciones y revisión de productos por administradores.
- Publicación de imágenes JPG, PNG o WebP (máximo 5 MB) en el almacenamiento público de Laravel.
- Favoritos y carrito persistentes por cuenta.
- Órdenes con Checkout de Stripe y confirmación por webhook/API.
- Reseñas permitidas únicamente a compradores con una orden completada.
- Conversaciones y mensajes persistentes, con actualización periódica mientras el chat está abierto.
- Compras del usuario y estado de órdenes.

El requisito de dominio institucional valida el formato del correo; **no envía un código ni verifica que la persona controle esa bandeja**. El SMTP y Stripe Connect requieren configuración adicional.

La bandeja de correo que existía en el prototipo era una simulación y se retiró; los correos transaccionales reales requieren configurar SMTP antes de implementarse. Los mensajes de chat sí se guardan en la base de datos.

## API principal

Pública: `GET /categories`, `GET /products?q=&category_id=`, `GET /products/{id}`, `GET /products/{id}/reviews`, `POST /auth/register`, `POST /auth/login`.

Las rutas privadas requieren el token de Sanctum y habilitan `/auth/me`, `/auth/logout`, `/auth/role`, `/products/mine`, `POST /products`, `/favorites`, `/cart`, `/orders`, `/products/{id}/checkout`, `/products/{id}/reviews`, `/products/{id}/conversations`, `/conversations`, `/admin/products/pending` y acciones de aprobación/rechazo.

## Pruebas

```powershell
Set-Location .\backend
php artisan test

Set-Location ..\upec-market
npm run lint
npm run build
```

Las pruebas Laravel usan SQLite en memoria para no modificar la base PostgreSQL configurada.
