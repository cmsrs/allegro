#!/usr/bin/env bash
set -euo pipefail

# Domyślnie tworzymy szkic INACTIVE, który można sprawdzić w panelu Sandbox.
# Aby użyć innej propozycji dla „inna”, ustaw LINE_TEXT.
LINE_TEXT="${LINE_TEXT:-For Her}"

php src/cli/create_narciso_draft.php \
  --brand-value=248811_1981839 \
  --line-value=250583_1935162 \
  --line-text="$LINE_TEXT" \
  "$@"
