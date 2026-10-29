# Macrofit

App de nutrición y conteo de macros para iOS y Android. El monorepo tiene dos partes:

- `backend/` — API REST en PHP y esquema MySQL.
- `app/` — Ionic + Angular (componentes standalone, signals y control flow `@if` / `@for`) con Capacitor.

La spec pedía `app-routing.module.ts`. Angular actual arranca sin NgModules, así que el enrutamiento vive en `app/src/app/app.routes.ts`, con las 7 pantallas y el login en rutas perezosas.

## Cómo correrlo

Terminal 1, la API:

```bash
cd backend
cp .env.example .env
openssl rand -hex 32   # pégalo en JWT_SECRET
docker network create app-network
docker compose -f docker-compose.mysql.yml up -d
docker compose up -d --build
docker compose exec php composer install
docker exec -i mysql mysql -uroot -proot_password < database/schema.sql
```

Ese `schema.sql` ya incluye la foto del diario y el tamaño de porción. Si la base se creó con un esquema anterior, no lo reimportes: aplica la migración una sola vez.

```bash
docker exec -i mysql mysql -uroot -proot_password macrofit < database/migrations/20261029_log_snapshots.sql
```

En Hostinger importa ese mismo archivo con la base seleccionada. Si no puedes ejecutar `USE macrofit`, bórralo del archivo. La migración rellena los registros viejos con el nombre, los gramos y los macros que tenían en ese momento (en alimentos, 1 porción histórica = 100 g).

La API queda en `http://127.0.0.1:8888`. El detalle de Hostinger y de los endpoints está en `backend/README.md`.

### Clave de Gemini para leer etiquetas

La foto de la tabla nutrimental se manda al API y el servidor llama a Gemini (plan gratis de Google AI Studio). La clave no va en la app Ionic ni en el repositorio.

1. Entra a [Google AI Studio](https://aistudio.google.com/apikey) con una cuenta de Google.
2. Crea una API key.
3. En `backend/.env` pon `GEMINI_API_KEY="la-clave"`. `backend/.env.example` trae el placeholder `replace-with-a-google-ai-studio-key`: sustitúyelo y no subas el `.env`.
4. Opcional: `GEMINI_MODEL`. El valor por defecto es `gemini-2.5-flash`. Si AI Studio muestra otro modelo flash del plan gratis, pon aquí ese nombre.
5. PHP lee el `.env` en cada petición. No hace falta reconstruir la imagen de Docker.

Sin clave, o con el placeholder, `POST /foods/ocr` responde 503 y dice que falta `GEMINI_API_KEY`. No llama a Gemini.

Terminal 2, la app:

```bash
cd app
npm install
npm start
```

Abre `http://127.0.0.1:43123`. La URL de la API sale de `src/environments/environment.ts` (`apiBaseUrl`). En producción cámbiala en `environment.prod.ts`.

```bash
cd app
npm test
npm run build
```

## Qué hace

1. Onboarding: sexo, edad, peso, altura y actividad. El servidor calcula BMR (Mifflin-St Jeor), TDEE y macros (proteína 2 g/kg, grasa 25 %, carbohidratos el resto).
2. Dashboard: anillo de calorías, barras de macros y el día agrupado en desayuno, comida, cena y snack.
3. Añadir alimento: búsqueda y código de barras (API propia, luego Open Food Facts). Se registra en gramos, 100 g por defecto. Si el alimento tiene tamaño de porción, se puede cambiar a porciones (1.5 porciones de 30 g = 45 g). Los valores nutricionales siguen guardados por 100 g.
4. OCR: foto con la cámara (o de la galería). El API la envía a Gemini y rellena el formulario. Hay que revisar y corregir los números antes de guardar.
5. Diario: cada registro guarda el nombre, los gramos y los macros de ese momento. Editar o borrar un alimento o una receta no cambia los días ya apuntados, y borrar no elimina el registro.
6. Recetario: lista con kcal y macros por porción.
7. Editor: ingredientes en gramos, total y porción en vivo.
8. Perfil: cambiar el peso recalcula las metas. Los porcentajes manuales sustituyen el reparto automático.

El escáner de barras (`@capacitor-mlkit/barcode-scanning`) y la cámara (`@capacitor/camera`) funcionan en el dispositivo. En el navegador se escribe el código a mano y la cámara abre el selector de archivos.

Proyectos nativos:

```bash
cd app
npx cap add android
npx cap add ios    # en macOS
npx cap sync
```
