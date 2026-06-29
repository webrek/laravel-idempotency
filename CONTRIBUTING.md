# Contribuir

Gracias por tomarte el tiempo de contribuir.

## Para empezar

```bash
git clone https://github.com/webrek/laravel-idempotency
cd laravel-idempotency
composer install
```

## Antes de abrir un pull request

Corre la batería completa de revisiones localmente — CI corre lo mismo en toda
la matriz de PHP soportada:

```bash
make check     # pint --test, phpstan, phpunit
```

O por separado:

```bash
composer pint        # formatear
composer pint:check  # verificar formato sin escribir
composer stan        # análisis estático (nivel 6)
composer test        # phpunit
```

## Lineamientos

- Mantén los *pull requests* enfocados; un cambio lógico por PR.
- Agrega o actualiza pruebas para cualquier cambio de comportamiento. Las
  correcciones de errores deben venir con una prueba que falle antes del arreglo.
- Respeta el estilo de código existente — Pint con el *preset* de Laravel es la
  fuente de verdad, así que córrelo antes de hacer *push*.
- Actualiza `CHANGELOG.md` bajo el encabezado `Unreleased`.
