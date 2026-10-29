#!/usr/bin/env bash
# Humo: registro → onboarding → alimento → diario → resumen.
set -euo pipefail
BASE="${1:-http://127.0.0.1:8888}"
EMAIL="smoke-$(date +%s)@macrofit.test"
DATE="2026-10-29"

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
LOG=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["body"]["id"])' "$FOOD")
curl -fsS -X POST "$BASE/logs" "${AUTH[@]}" \
	-d "{\"food_id\":$LOG,\"quantity\":150,\"unit\":\"grams\",\"meal_type\":\"lunch\",\"log_date\":\"$DATE\"}" >/dev/null

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
