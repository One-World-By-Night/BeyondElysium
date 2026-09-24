#!/usr/bin/env bash
#
# Dumps the local seeded catalog (system schema blocks, creature stacks, system templates) into one JSON file per
# record, as the input emit_catalog.py reads. Local be_dev only: never point this at a production host.
#
# Usage: tools/catalog/dump_live.sh [out-dir]     (default: tools/catalog/out/live)
set -euo pipefail
cd "$(dirname "$0")"
OUT="${1:-out/live}"
mkdir -p "$OUT/blocks" "$OUT/stacks" "$OUT/templates"
MY=(mysql -h127.0.0.1 -P3307 -ube_dev -pbe_dev_pw be_dev -N --raw)

for s in $("${MY[@]}" -e "select slug from wp_be_schema_blocks where is_system=1" 2>/dev/null); do
	"${MY[@]}" -e "select json_object('slug',slug,'name',name,'section_type',section_type,'storyteller_only',storyteller_only,'definition',definition) from wp_be_schema_blocks where is_system=1 and slug='$s'" 2>/dev/null >| "$OUT/blocks/$s.json"
done
for s in $("${MY[@]}" -e "select slug from wp_be_creature_stacks where is_system=1" 2>/dev/null); do
	"${MY[@]}" -e "select json_object('slug',slug,'name',name,'game_line',game_line,'stack_definition',stack_definition,'creation_rules',creation_rules) from wp_be_creature_stacks where is_system=1 and slug='$s'" 2>/dev/null >| "$OUT/stacks/$s.json"
done
for id in $("${MY[@]}" -e "select id from wp_be_templates where is_system=1 and game_id is null" 2>/dev/null); do
	"${MY[@]}" -e "select json_object('stack_slug',stack_slug,'name',name,'template_type',template_type,'layout',layout) from wp_be_templates where id=$id" 2>/dev/null >| "$OUT/templates/$id.json"
done
echo "dumped: $(ls "$OUT/blocks" | wc -l) blocks, $(ls "$OUT/stacks" | wc -l) stacks, $(ls "$OUT/templates" | wc -l) templates -> $OUT"
