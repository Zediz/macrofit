# Macrofit app

Ionic + Angular standalone + Capacitor. El enrutamiento principal está en `src/app/app.routes.ts` (la spec mencionaba `app-routing.module.ts`; este proyecto no usa NgModules).

## Desarrollo

```bash
npm install
npm start
```

Sirve en `http://127.0.0.1:43123`. La API se configura en `src/environments/environment.ts`.

```bash
npm test          # BMR, TDEE, macros, recetas, diario en gramos o porciones, OCR y Open Food Facts
npm run build     # equivalente a ionic build
```

## Pantallas

| Ruta | Pantalla |
| --- | --- |
| `/login` | Entrar o crear cuenta |
| `/onboarding` | Sexo, edad, peso, altura, actividad y metas |
| `/dashboard` | Día, anillo de kcal, barras y comidas |
| `/add-food` | Búsqueda, escáner y registro |
| `/ocr` | Foto de la tabla, lectura con Gemini y revisión |
| `/recipes` | Recetario |
| `/recipes/new` y `/recipes/:id` | Editor con totales en vivo |
| `/profile` | Peso, actividad y porcentajes |

Textos en español (es-MX).

## Nativo

`capacitor.config.ts` usa el id `io.macrofit.app`. Hay que generar los proyectos en cada plataforma:

```bash
npx cap add android
npx cap add ios
npx cap sync
```

El escáner ML Kit pide permiso de cámara y, en Android, el módulo de Google Barcode Scanner. En el navegador el servicio avisa y deja escribir el código.
