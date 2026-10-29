# Macrofit API

API REST en PHP (controladores atómicos) y MySQL para la app Ionic. El esqueleto de `src/core`, `router.php`, `.htaccess`, `docker_php` y `docker-compose.yml` sigue la arquitectura del proyecto: front controller, JWT por defecto y respuestas JSON.

## Arranque local con Docker

```bash
cd backend
cp .env.example .env
# Sustituye JWT_SECRET. Tiene que ser largo:
openssl rand -hex 32

docker network create app-network
docker compose -f docker-compose.mysql.yml up -d
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php -r 'echo "ok\n";'
docker exec -i mysql mysql -uroot -proot_password < database/schema.sql
```

La API queda en `http://127.0.0.1:8888` (o el `APP_PORT` del `.env`). `DB_HOST=mysql` funciona dentro de la red de Docker. El contenedor de MySQL publica el puerto **33060** en el host para no chocar con un MySQL local.

No subas `.env` ni `vendor/`.

### Humo

```bash
./scripts/smoke.sh http://127.0.0.1:8888
```

Cubre registro, onboarding (Mifflin-St Jeor), alta de alimento, registro en el diario y resumen del día. También comprueba que editar y borrar una receta no cambia los totales ya guardados, que 1.5 porciones de 30 g se registran como 45 g, y que `POST /foods/ocr` responde 503 si falta `GEMINI_API_KEY` (no llama a Gemini: deja la clave vacía o con el placeholder).

```bash
php tests/run.php
```

Esas pruebas cubren la matemática del diario y el cliente de Gemini con respuestas simuladas. `scripts/check-migration.sh` (con el MySQL de Docker ya levantado) aplica la migración sobre una copia del esquema viejo.

## Endpoints

Todas las rutas exigen `Authorization: Bearer <jwt>` salvo registro y login.

| Método | Ruta | Notas |
| --- | --- | --- |
| POST | `/auth/register` | pública |
| POST | `/auth/login` | pública |
| GET | `/profile` | usuario del token |
| PUT | `/profile` | si cambia peso, edad, altura, género o actividad, recalcula metas |
| POST | `/profile/onboarding` | calcula BMR, TDEE y macros |
| PUT | `/profile/macros` | porcentajes que deben sumar 100, o `{ "reset": true }` |
| GET | `/foods/search?q=` | catálogo global |
| GET | `/foods/barcode/{barcode}` | |
| GET | `/foods/{id}` | |
| POST | `/foods` | `source`: `local`, `openfoodfacts` o `ocr`. `serving_grams` es opcional |
| POST | `/foods/ocr` | foto en JSON (`image`, data URL o base64) o multipart con el campo `image` |
| GET/POST | `/recipes` | |
| GET/PUT/DELETE | `/recipes/{id}` | solo las del usuario |
| GET | `/logs?date=YYYY-MM-DD` | |
| POST | `/logs` | `food_id` o `recipe_id`, no ambos |
| DELETE | `/logs/{id}` | |
| GET | `/logs/summary?date=YYYY-MM-DD` | consumido, metas y restante |

El `id` de usuario sale de `Auth::user()`, nunca del cliente.

### Fórmulas

- BMR Mifflin-St Jeor. Hombre: `(10·kg) + (6.25·cm) − (5·edad) + 5`. Mujer: igual, con `− 161`.
- Actividad: sedentario 1.2, ligero 1.375, moderado 1.55, activo 1.725. TDEE = BMR × multiplicador. La meta de kcal es el TDEE.
- Macros por defecto: proteína `2 g/kg` (4 kcal/g), grasa 25 % de las kcal (9 kcal/g), carbohidratos el resto (4 kcal/g).
- Porcentajes manuales sustituyen ese reparto y se conservan al recalcular por un cambio de peso.
- Receta: suma de `(macro por 1 g × gramos)`. Por porción = total / porciones.
- Diario, alimento: siempre se guarda en gramos. `(macro por 100 g / 100) × gramos`.
- Si el alimento tiene `serving_grams` y el cliente manda `unit: portions`, el servidor convierte `gramos = cantidad × serving_grams` (1.5 × 30 g = 45 g) y persiste la entrada en gramos. Sin `serving_grams`, `portions` en un alimento responde 422. Ya no se asume 1 porción = 100 g.
- Receta en `portions`: macros por porción × cantidad. En `grams`: se escala con el peso total de los ingredientes. Las recetas no cambiaron esa cuenta.
- Al crear el registro se guardan `item_name`, `grams`, `kcal`, `protein`, `carbs` y `fat`. El listado y el resumen leen esa foto. Editar el alimento o la receta no reescribe el diario. Borrar deja `food_id` o `recipe_id` en NULL y conserva el registro.
- Los valores guardados se redondean a 2 decimales. `remaining` puede ser negativo si la persona se pasa de la meta.
- OCR: `GEMINI_API_KEY` (obligatoria para leer etiquetas; créala en https://aistudio.google.com/apikey) y `GEMINI_MODEL` (opcional, por defecto `gemini-2.5-flash`). La clave va en el header, no en la URL. Sin clave la ruta responde 503. Una etiqueta ilegible responde 422. Un fallo de Gemini responde 502 y un tiempo de espera, 504. Si la etiqueta solo trae valores por porción, se convierten a 100 g con los gramos de esa porción.
- Zona horaria por defecto del “hoy” cuando el cliente no manda fecha: `America/Mexico_City` (`APP_TIMEZONE`).

Género en la API: `male` / `female`. Actividad: `sedentary`, `light`, `moderate`, `active`. Comidas: `breakfast`, `lunch`, `dinner`, `snack`.

## Hostinger (shared hosting)

1. Crea la base MySQL en el panel e importa `database/schema.sql`. Si no puedes ejecutar `CREATE DATABASE`, borra esas dos primeras sentencias e importa el resto con la base ya seleccionada.
2. En `.env` pon el host remoto (`DB_HOST`, a veces `localhost` o un host `*.hostinger.com`), `DB_NAME=macrofit`, usuario, contraseña y un `JWT_SECRET` nuevo. No pongas `APP_DEBUG=true`.
3. Sube el proyecto (incluye `vendor/` después de `composer install` en tu máquina si el plan no tiene Composer ni SSH). El document root debe ser la carpeta de la API, donde viven `.htaccess` y `routes.php`.
4. Apache tiene que permitir `.htaccess` (`AllowOverride All`). El rewrite ya pasa `Authorization` a PHP y manda el resto de peticiones a `routes.php`.
5. Si la API vive en un subdirectorio (`https://dominio.com/macrofit`), no hace falta reescribir las rutas: `Router` recorta el directorio de `SCRIPT_NAME`. La app Ionic debe usar esa URL como `apiBaseUrl`, con el subdirectorio incluido y sin barra final.
6. PHP 8.1 o superior. El contenedor de desarrollo usa 8.5; el código no depende de sintaxis posterior a 8.1. Activa `pdo_mysql`.
7. CORS: en producción define `CORS_ALLOWED_ORIGINS` con el origen de la app. Vacío o ausente equivale a `*`.
