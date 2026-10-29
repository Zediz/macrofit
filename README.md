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

La API queda en `http://127.0.0.1:8888`. El detalle de Hostinger y de los endpoints está en `backend/README.md`.

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
3. Añadir alimento: búsqueda, código de barras (API propia, luego Open Food Facts) y, si no existe, captura OCR.
4. OCR: foto con la cámara y un formulario de revisión. La extracción es un marcador de posición.
5. Recetario: lista con kcal y macros por porción.
6. Editor: ingredientes en gramos, total y porción en vivo.
7. Perfil: cambiar el peso recalcula las metas. Los porcentajes manuales sustituyen el reparto automático.

El escáner de barras (`@capacitor-mlkit/barcode-scanning`) y la cámara (`@capacitor/camera`) funcionan en el dispositivo. En el navegador se escribe el código a mano y la cámara abre el selector de archivos.

Proyectos nativos:

```bash
cd app
npx cap add android
npx cap add ios    # en macOS
npx cap sync
```
