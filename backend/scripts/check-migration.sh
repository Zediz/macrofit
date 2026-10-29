#!/usr/bin/env bash
# Comprueba la migración sobre una base con el esquema viejo: rellena la foto,
# y borrar alimento o receta no borra el diario.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB="${MIGRATE_DB:-macrofit_migtest}"
DOCKER="${DOCKER:-docker}"

$DOCKER exec -i mysql mysql -uroot -proot_password -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
{
	echo "USE \`$DB\`;"
	cat "$ROOT/scripts/fixtures/pre_snapshot.sql"
} | $DOCKER exec -i mysql mysql -uroot -proot_password

sed "s/^USE macrofit;$/USE \`$DB\`;/" "$ROOT/database/migrations/20261029_log_snapshots.sql" \
	| $DOCKER exec -i mysql mysql -uroot -proot_password

query() {
	$DOCKER exec -i mysql mysql -uroot -proot_password -N -B "$DB" -e "$1"
}

BEFORE="$(query "SELECT id, IFNULL(food_id,-1), IFNULL(recipe_id,-1), item_name, grams+0, kcal+0, protein+0, carbs+0, fat+0 FROM daily_logs ORDER BY id")"

$DOCKER exec -i mysql mysql -uroot -proot_password "$DB" -e "
	UPDATE foods SET name = 'Pollo nuevo', kcal_per_100g = 999 WHERE id = 1;
	UPDATE recipes SET name = 'Receta nueva', total_kcal = 999, total_protein = 999 WHERE id = 1;
"
AFTER_EDIT="$(query "SELECT id, IFNULL(food_id,-1), IFNULL(recipe_id,-1), item_name, grams+0, kcal+0, protein+0, carbs+0, fat+0 FROM daily_logs ORDER BY id")"

$DOCKER exec -i mysql mysql -uroot -proot_password "$DB" -e "
	DELETE FROM foods WHERE id = 2;
	DELETE FROM recipes WHERE id = 1;
	DELETE FROM foods WHERE id = 1;
"
AFTER_DELETE="$(query "SELECT id, IFNULL(food_id,-1), IFNULL(recipe_id,-1), item_name, grams+0, kcal+0, protein+0, carbs+0, fat+0 FROM daily_logs ORDER BY id")"
RULES="$(query "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '$DB' AND TABLE_NAME = 'daily_logs' AND CONSTRAINT_NAME IN ('fk_daily_logs_food','fk_daily_logs_recipe') ORDER BY CONSTRAINT_NAME")"

python3 - "$BEFORE" "$AFTER_EDIT" "$AFTER_DELETE" "$RULES" << 'PY'
import sys

def rows(text):
    parsed = []
    for line in text.strip().splitlines():
        parts = line.split("\t")
        parsed.append({
            "id": int(parts[0]),
            "food_id": int(parts[1]),
            "recipe_id": int(parts[2]),
            "name": parts[3],
            "grams": float(parts[4]),
            "kcal": float(parts[5]),
            "protein": float(parts[6]),
            "carbs": float(parts[7]),
            "fat": float(parts[8]),
        })
    return parsed

before = {row["id"]: row for row in rows(sys.argv[1])}
edited = {row["id"]: row for row in rows(sys.argv[2])}
deleted = {row["id"]: row for row in rows(sys.argv[3])}
rules = [line.strip() for line in sys.argv[4].splitlines() if line.strip()]

expected = {
    1: {"food_id": 1, "recipe_id": -1, "name": "Pechuga de pollo", "grams": 200, "kcal": 220, "protein": 46.2, "carbs": 0, "fat": 2.4},
    2: {"food_id": 2, "recipe_id": -1, "name": "Yogurt humo", "grams": 150, "kcal": 93, "protein": 5.25, "carbs": 7.05, "fat": 4.95},
    3: {"food_id": -1, "recipe_id": 1, "name": "Pollo receta", "grams": 100, "kcal": 110, "protein": 23.1, "carbs": 0, "fat": 1.2},
}

def close(row, exp, label):
    for key, value in exp.items():
        actual = row[key]
        if isinstance(value, float):
            assert abs(actual - value) < 0.001, (label, key, actual, value)
        else:
            assert actual == value, (label, key, actual, value)

for log_id, exp in expected.items():
    close(before[log_id], exp, f"backfill {log_id}")
    close(edited[log_id], exp, f"after edit {log_id}")

for log_id, exp in expected.items():
    gone = dict(exp)
    gone["food_id"] = -1
    gone["recipe_id"] = -1
    close(deleted[log_id], gone, f"after delete {log_id}")

assert rules == ["SET NULL", "SET NULL"], rules
print("migration ok", len(deleted))
PY
