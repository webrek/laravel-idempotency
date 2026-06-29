# Laravel Idempotency

[![Última versión en Packagist](https://img.shields.io/packagist/v/webrek/laravel-idempotency.svg?style=flat-square)](https://packagist.org/packages/webrek/laravel-idempotency)
[![Descargas totales](https://img.shields.io/packagist/dt/webrek/laravel-idempotency.svg?style=flat-square)](https://packagist.org/packages/webrek/laravel-idempotency)
[![Pruebas](https://img.shields.io/github/actions/workflow/status/webrek/laravel-idempotency/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/webrek/laravel-idempotency/actions/workflows/tests.yml)
[![Versión de PHP](https://img.shields.io/packagist/php-v/webrek/laravel-idempotency.svg?style=flat-square)](https://php.net)
[![Licencia](https://img.shields.io/packagist/l/webrek/laravel-idempotency.svg?style=flat-square)](LICENSE)

Reintentos de peticiones seguros para APIs de Laravel. Un cliente envía un
encabezado `Idempotency-Key` con una petición de escritura; si esa misma
petición llega de nuevo —un reintento tras un timeout, un botón pulsado dos
veces, una reentrega de webhook— se reproduce la respuesta original en lugar de
ejecutar la acción dos veces.

## Quickstart

```bash
composer require webrek/laravel-idempotency
```

Adjunta el *middleware* a las rutas que crean o modifican estado:

```php
Route::post('/orders', [OrderController::class, 'store'])
    ->middleware('idempotency');
```

Los clientes se suscriben por petición enviando una clave única:

```http
POST /orders HTTP/1.1
Idempotency-Key: 0f8fad5b-d9cb-469f-a165-70867728950e
Content-Type: application/json

{"sku": "ABC-123", "qty": 2}
```

La primera llamada ejecuta el controlador y almacena la respuesta. Cualquier
repetición de esa llamada dentro de la ventana de retención devuelve la respuesta
almacenada tal cual, con un encabezado `Idempotency-Replayed: true` para que el
cliente pueda distinguir una reproducción de un resultado nuevo. Sin clave, no hay
interceptación: quienes ya llaman a la API siguen funcionando.

## El problema

`POST` no es seguro de reintentar. Cuando un cliente lanza una petición de
escritura y la conexión se cae antes de que regrese la respuesta, no tiene forma
de saber si el servidor la procesó. Ambas opciones son malas: si reintentas,
arriesgas un cargo, pedido o registro duplicado; si no reintentas, arriesgas
perder la escritura en silencio.

Las claves de idempotencia resuelven la ambigüedad. El cliente genera una clave
por operación lógica y la reutiliza en cada reintento de esa operación. El
servidor promete que todas las peticiones que comparten una clave producen **una**
ejecución y la **misma** respuesta. Así es como Stripe, PayPal, Adyen y la mayoría
de las APIs de pago serias hacen seguros los reintentos, y es exactamente lo que
este paquete añade a tus rutas de Laravel.

## Cómo funciona

El *middleware* se coloca delante de tus rutas protegidas y hace cuatro cosas:

1. **Genera una huella de la petición.** Un SHA-256 del método, la ruta y el
   cuerpo crudo se almacena junto con la respuesta. Si la misma clave llega después
   con un *payload* distinto, eso es un error del cliente, y la petición se rechaza
   con `422` en lugar de devolver en silencio la respuesta en caché equivocada.
2. **Serializa duplicados concurrentes con un *lock* atómico.** Dos peticiones que
   llevan la misma clave al mismo tiempo no pueden ejecutarse ambas. La primera toma
   el *lock* y se ejecuta; la segunda obtiene `409 Conflict` con un encabezado
   `Retry-After`. El *lock* expira automáticamente, así que un worker caído nunca
   deja atascada una clave.
3. **Reproduce la respuesta almacenada.** El código de estado, el cuerpo y un
   conjunto configurable de encabezados se devuelven en los accesos posteriores, sin
   tocar tu controlador, tus jobs en cola ni tu base de datos.
4. **Deja los fallos como reintentables.** Los errores de servidor (`5xx`) nunca se
   almacenan, de modo que un cliente puede reintentar de forma segura tras un fallo
   transitorio. Los éxitos y los errores de cliente deterministas sí se reproducen.

Todo vive en la caché de Laravel, usando los mismos *locks* atómicos que expone
`Cache::lock()`. No hay migraciones ni tablas nuevas.

## Comportamiento de un vistazo

| Escenario | Resultado |
| --- | --- |
| Primera petición con una clave | Ejecuta, almacena la respuesta, `Idempotency-Replayed: false` |
| Misma clave, mismo payload, tras completarse | Reproduce la respuesta almacenada, `Idempotency-Replayed: true` |
| Misma clave, mismo payload, aún en curso | `409 Conflict` + `Retry-After` |
| Misma clave, payload **distinto** | `422 Unprocessable Entity` |
| Sin clave (y `require_key` es false) | Pasa de largo sin tocarse |
| Petición `GET` / `HEAD` | Se ignora: ya es seguro repetirla |
| La respuesta es `5xx` | No se almacena: el siguiente intento la vuelve a ejecutar |

## Requisitos

| Componente | Versión |
| --------- | ------- |
| PHP | 8.2+ |
| Laravel | 12.x / 13.x |
| Almacén de caché | Cualquier almacén que soporte locks atómicos (redis, memcached, dynamodb, database, file, array) |

## Configuración

Los valores por defecto están listos para producción. Publica la configuración
solo si necesitas cambiarlos:

```bash
php artisan vendor:publish --tag=idempotency-config
```

```php
return [
    // Encabezado que envían los clientes para identificar una operación reintentable.
    'header' => env('IDEMPOTENCY_HEADER', 'Idempotency-Key'),

    // Rechaza con 400 las peticiones sin clave en rutas protegidas cuando es true.
    'require_key' => false,

    // Métodos HTTP que protege el middleware. GET/HEAD ya son seguros.
    'methods' => ['POST', 'PUT', 'PATCH', 'DELETE'],

    // Almacén de caché para respuestas y locks (null = almacén por defecto).
    'store' => env('IDEMPOTENCY_STORE'),

    'prefix' => 'idempotency:',

    // Cuánto tiempo una respuesta sigue siendo reproducible, en segundos.
    'ttl' => (int) env('IDEMPOTENCY_TTL', 86400),

    // Tiempo máximo que una petición retiene el lock de su clave, en segundos.
    'lock_timeout' => 10,

    'max_key_length' => 255,

    // Agrupa las claves por usuario autenticado para que no colisionen los llamadores.
    'scope_by_user' => true,

    // Null reproduce todo lo < 500; o lista códigos explícitos, p. ej. [200, 201, 422].
    'replay_status_codes' => null,

    // Encabezados copiados a la respuesta reproducida.
    'persist_headers' => ['Content-Type'],

    // Marca agregada a cada respuesta protegida: "true" | "false".
    'replay_header' => 'Idempotency-Replayed',
];
```

### Retención por ruta

Sobrescribe el TTL configurado (en segundos) para rutas específicas pasándolo como
parámetro del *middleware*:

```php
Route::post('/payments', ...)->middleware('idempotency:3600');   // 1 hora
Route::post('/imports', ...)->middleware('idempotency:86400');   // 1 día
```

### Evento de reproducción

Se despacha un evento `Idempotency\Events\IdempotentReplay` cada vez que se
reproduce una respuesta almacenada, para que puedas medir cuántos reintentos estás
absorbiendo:

```php
use Webrek\Idempotency\Events\IdempotentReplay;

Event::listen(IdempotentReplay::class, function (IdempotentReplay $event) {
    Metrics::increment('idempotency.replays', tags: ['key' => $event->key]);
});
```

### Exigir una clave en rutas específicas

Deja `require_key` desactivado globalmente y activa rutas individuales cambiando la
configuración en el punto de entrada, o ponlo en `true` si toda ruta protegida debe
llevar una clave. Con esto activado, una petición protegida sin el encabezado se
rechaza con `400` antes de hacer cualquier trabajo.

### Elegir un almacén de caché

Las reproducciones son tan duraderas como el almacén que las respalda. `array` es
para pruebas; en producción apunta `IDEMPOTENCY_STORE` a `redis` (o cualquier
almacén compartido y persistente con locks atómicos) para que las reproducciones
sobrevivan entre web workers y despliegues. Un almacén por proceso como `array` no
puede coordinar locks entre máquinas.

## Guía para el cliente

- **Una clave por operación lógica, reutilizada en el reintento.** Genera un UUID
  antes del primer intento y envía el *mismo* valor en cada reintento de ese intento.
  Una clave nueva por reintento anula el propósito.
- **Maneja el `409` aplicando *backoff* y reintentando**: significa que un intento
  anterior sigue ejecutándose. Respeta el encabezado `Retry-After`.
- **Trata el `422` como un error de tu lado**: significa que reutilizaste una clave
  para una petición genuinamente distinta.

## Comparación con enfoques caseros

| Enfoque | Seguro ante concurrencia | Detección de payload distinto | Reproduce la respuesta completa | Migraciones |
| --- | --- | --- | --- | --- |
| `firstOrCreate` sobre una columna `request_id` | No (carrera entre la comprobación y el insert) | No | No | Sí |
| Restricción única en BD + capturar duplicado | Parcialmente (depende de que la escritura llegue a la tabla restringida) | No | No | Sí |
| Este paquete | Sí (lock atómico) | Sí (huella de la petición) | Sí | No |

Una restricción única detiene una *fila* duplicada, pero no detiene los efectos
secundarios duplicados que se ejecutaron antes del insert (el correo ya enviado, el
cargo de terceros ya realizado), y le devuelve al cliente un error en lugar del éxito
original. La idempotencia en la frontera HTTP detiene por completo la segunda
ejecución y devuelve la primera respuesta.

## Pruebas

```bash
composer install
composer test
```

La suite se ejecuta sobre el almacén de caché `array`, así que no se necesitan
servicios externos.

## Contribuir

Consulta [CONTRIBUTING.md](CONTRIBUTING.md).

## Seguridad

Por favor revisa la [política de seguridad](SECURITY.md) antes de reportar una
vulnerabilidad.

## Licencia

La Licencia MIT (MIT). Consulta [LICENSE](LICENSE).
