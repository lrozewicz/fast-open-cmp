#!/usr/bin/env bash
# Regenerates the admin translation template and updates the Polish catalogue.
#
#   scripts/i18n.sh          → plugin/languages/fast-open-cmp.pot (from PHP sources),
#                              merges it into fast-open-cmp-pl_PL.po, compiles the .mo
#                              and lists strings that still need a Polish translation.
#
# Requires GNU gettext (xgettext, msgmerge, msgfmt, msgattrib).
set -euo pipefail
cd "$(dirname "$0")/../plugin"

LANG_DIR=languages
POT="$LANG_DIR/fast-open-cmp.pot"
PO="$LANG_DIR/fast-open-cmp-pl_PL.po"
MO="$LANG_DIR/fast-open-cmp-pl_PL.mo"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

find . -name '*.php' -not -path './tests/*' -not -path './vendor/*' | sort > "$TMP/files"
xgettext --language=PHP --from-code=UTF-8 \
  --keyword=__ --keyword=_e --keyword=esc_html__ --keyword=esc_html_e \
  --keyword=esc_attr__ --keyword=esc_attr_e --keyword=_x:1,2c --keyword=_n:1,2 \
  --keyword=esc_html_x:1,2c --add-comments=translators \
  --package-name="Fast Open CMP" -f "$TMP/files" -o "$TMP/new.pot"

# Keep the existing header, replace the body.
{ awk 'BEGIN{RS="";ORS="\n\n"} NR==1{print; exit}' "$POT"; awk 'BEGIN{RS="";ORS="\n\n"} NR>1' "$TMP/new.pot"; } > "$TMP/final.pot"
mv "$TMP/final.pot" "$POT"

msgmerge --quiet --no-fuzzy-matching --update --backup=none "$PO" "$POT"
msgfmt -o "$MO" "$PO"

missing=$(msgattrib --untranslated --no-obsolete "$PO" | grep -c '^msgid ' || true)
# The header entry itself counts as one "untranslated" msgid.
echo "Untranslated strings: $(( missing > 0 ? missing - 1 : 0 ))"
msgattrib --untranslated --no-obsolete "$PO" | grep '^msgid ' | tail -n +2 || true
