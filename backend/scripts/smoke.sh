#!/usr/bin/env bash
# Humo: registro → onboarding → alimento → diario → resumen.
# También: la foto del diario no cambia al editar o borrar la receta,
# las porciones de un alimento usan su tamaño en gramos, y el OCR
# explica que falta GEMINI_API_KEY (no llama a Gemini).
set -euo pipefail
BASE="${1:-http://127.0.0.1:8888}"
EMAIL="smoke-$(date +%s)@macrofit.test"
DATE="2026-10-29"
DATE_PORTION="2026-10-28"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "→ register $EMAIL"
REG=$(curl -fsS -X POST "$BASE/auth/register" \
	-H 'Content-Type: application/json' \
	-d "{\"name\":\"Ana Smoke\",\"email\":\"$EMAIL\",\"password\":\"secret-pass-1\"}")

TOKEN=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["body"]["token"])' "$REG")
AUTH=( -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' )

echo "→ onboarding"
ONB=$(curl -fsS -X POST "$BASE/profile/onboarding" "${AUTH[@]}" \
	-d '{"gender":"male","age":30,"weight_kg":80,"height_cm":180,"activity_level":"moderate"}')

echo "→ create food"
FOOD=$(curl -fsS -X POST "$BASE/foods" "${AUTH[@]}" \
	-d '{"name":"Yogurt humo","brand":"Macrofit","kcal_per_100g":62,"protein_per_100g":3.5,"carbs_per_100g":4.7,"fat_per_100g":3.3,"source":"local"}')

echo "→ log 150 g"
FOOD_ID=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["body"]["id"])' "$FOOD")
curl -fsS -X POST "$BASE/logs" "${AUTH[@]}" \
	-d "{\"food_id\":$FOOD_ID,\"quantity\":150,\"unit\":\"grams\",\"meal_type\":\"lunch\",\"log_date\":\"$DATE\"}" > "$TMP/food-log.json"

echo "→ summary"
SUM=$(curl -fsS "$BASE/logs/summary?date=$DATE" -H "Authorization: Bearer $TOKEN")

python3 - "$ONB" "$SUM" << 'PY'
import json, sys
user = json.loads(sys.argv[1])["body"]
summary = json.loads(sys.argv[2])["body"]
assert user["bmr"] == 1780, user["bmr"]
assert user["tdee"] == 2759, user["tdee"]
assert user["goal_kcal"] == 2759, user
assert user["goal_protein"] == 160, user
assert user["goal_fat"] == 76.64, user
assert user["goal_carbs"] == 357.31, user
# 150 g de yogurt: 62/100*150 = 93 kcal, proteína 5.25, carbos 7.05, grasa 4.95
assert summary["consumed"] == {"kcal": 93, "protein": 5.25, "carbs": 7.05, "fat": 4.95}, summary["consumed"]
assert summary["goals"]["kcal"] == 2759
assert summary["remaining"]["kcal"] == 2666, summary["remaining"]
print("smoke ok", user["tdee"], summary["consumed"])
PY

echo "→ recipe log, then edit and delete the recipe"
RECIPE=$(curl -fsS -X POST "$BASE/recipes" "${AUTH[@]}" \
	-d "{\"name\":\"Yogurt receta humo\",\"servings\":2,\"ingredients\":[{\"food_id\":$FOOD_ID,\"grams\":100}]}")
RECIPE_ID=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["body"]["id"])' "$RECIPE")
curl -fsS -X POST "$BASE/logs" "${AUTH[@]}" \
	-d "{\"recipe_id\":$RECIPE_ID,\"quantity\":1,\"unit\":\"portions\",\"meal_type\":\"dinner\",\"log_date\":\"$DATE\"}" > "$TMP/recipe-log.json"
curl -fsS -X PUT "$BASE/recipes/$RECIPE_ID" "${AUTH[@]}" \
	-d "{\"name\":\"Yogurt receta cambiada\",\"servings\":1,\"ingredients\":[{\"food_id\":$FOOD_ID,\"grams\":400}]}" > "$TMP/recipe-edited.json"
curl -fsS "$BASE/logs/summary?date=$DATE" -H "Authorization: Bearer $TOKEN" > "$TMP/summary-after-edit.json"
curl -fsS "$BASE/logs?date=$DATE" -H "Authorization: Bearer $TOKEN" > "$TMP/logs-after-edit.json"
curl -fsS -X DELETE "$BASE/recipes/$RECIPE_ID" -H "Authorization: Bearer $TOKEN" > "$TMP/recipe-deleted.json"
curl -fsS "$BASE/logs/summary?date=$DATE" -H "Authorization: Bearer $TOKEN" > "$TMP/summary-after-delete.json"
curl -fsS "$BASE/logs?date=$DATE" -H "Authorization: Bearer $TOKEN" > "$TMP/logs-after-delete.json"

echo "→ food portions use serving grams"
COOKIE=$(curl -fsS -X POST "$BASE/foods" "${AUTH[@]}" \
	-d '{"name":"Galleta humo","serving_grams":30,"kcal_per_100g":200,"protein_per_100g":10,"carbs_per_100g":20,"fat_per_100g":5,"source":"local"}')
COOKIE_ID=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["body"]["id"])' "$COOKIE")
curl -fsS -X POST "$BASE/logs" "${AUTH[@]}" \
	-d "{\"food_id\":$COOKIE_ID,\"quantity\":1.5,\"unit\":\"portions\",\"meal_type\":\"snack\",\"log_date\":\"$DATE_PORTION\"}" > "$TMP/portion-log.json"
curl -fsS "$BASE/logs/summary?date=$DATE_PORTION" -H "Authorization: Bearer $TOKEN" > "$TMP/portion-summary.json"

echo "→ portions without a serving size are rejected"
PORTION_CODE=$(curl -sS -o "$TMP/portion-missing.json" -w '%{http_code}' -X POST "$BASE/logs" "${AUTH[@]}" \
	-d "{\"food_id\":$FOOD_ID,\"quantity\":1,\"unit\":\"portions\",\"meal_type\":\"snack\",\"log_date\":\"$DATE_PORTION\"}")

echo "→ ocr without GEMINI_API_KEY"
OCR_CODE=$(curl -sS -o "$TMP/ocr.json" -w '%{http_code}' -X POST "$BASE/foods/ocr" "${AUTH[@]}" -d '{}')

python3 - "$TMP" "$PORTION_CODE" "$OCR_CODE" << 'PY'
import json, sys
from pathlib import Path
tmp = Path(sys.argv[1])
portion_code = sys.argv[2]
ocr_code = sys.argv[3]

def load(name):
    return json.loads((tmp / name).read_text())

def body(name):
    return load(name)["body"]

recipe_log = body("recipe-log.json")
assert recipe_log["name"] == "Yogurt receta humo", recipe_log
assert recipe_log["unit"] == "portions", recipe_log
assert recipe_log["quantity"] == 1, recipe_log
assert recipe_log["grams"] == 50, recipe_log
assert recipe_log["kcal"] == 31, recipe_log
assert recipe_log["protein"] == 1.75, recipe_log
assert recipe_log["carbs"] == 2.35, recipe_log
assert recipe_log["fat"] == 1.65, recipe_log

snap = {"kcal": 124, "protein": 7, "carbs": 9.4, "fat": 6.6}
assert body("summary-after-edit.json")["consumed"] == snap, body("summary-after-edit.json")
assert body("summary-after-delete.json")["consumed"] == snap, body("summary-after-delete.json")

def find_recipe(name):
    rows = load(name)
    match = [row for row in rows if row["id"] == recipe_log["id"]]
    assert len(match) == 1, rows
    return match[0]

edited = find_recipe("logs-after-edit.json")
assert edited["name"] == "Yogurt receta humo", edited
assert edited["kcal"] == 31, edited
assert edited["recipe_id"] == recipe_log["recipe_id"], edited

removed = find_recipe("logs-after-delete.json")
assert removed["recipe_id"] is None, removed
assert removed["name"] == "Yogurt receta humo", removed
assert removed["kcal"] == 31 and removed["grams"] == 50, removed
assert len(load("logs-after-delete.json")) == 2, load("logs-after-delete.json")

portion = body("portion-log.json")
assert portion["quantity"] == 45, portion
assert portion["grams"] == 45, portion
assert portion["unit"] == "grams", portion
assert portion["name"] == "Galleta humo", portion
assert portion["kcal"] == 90 and portion["protein"] == 4.5 and portion["carbs"] == 9 and portion["fat"] == 2.25, portion
assert body("portion-summary.json")["consumed"] == {"kcal": 90, "protein": 4.5, "carbs": 9, "fat": 2.25}

assert portion_code == "422", portion_code
assert "porción" in load("portion-missing.json")["body"], load("portion-missing.json")

assert ocr_code == "503", (ocr_code, (tmp / "ocr.json").read_text())
ocr = load("ocr.json")
assert ocr["success"] is False, ocr
assert "GEMINI_API_KEY" in ocr["body"], ocr
print("snapshot, portions and ocr ok")
PY
