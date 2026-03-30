#!/bin/bash
# Simple heuristic report for potentially unused PHP functions

ROOT="$(dirname "$0")/.."
cd "$ROOT" || exit 1

# gather function names

echo "Scanning for function definitions..."

grep -R --include='*.php' -nP 'function\s+\w+' | \
  sed -E 's/^(.*):[0-9]+:.*function\s+(\w+).*/\1:\2/' > /tmp/defs.txt

# for each function check references
> /tmp/unused.txt
while IFS=":" read -r file func; do
    # search for the function name in project excluding its definition
    count=$(grep -R --include='*.php' -n "\b$func\b" | grep -v "$file" || true | wc -l)
    if [ "$count" -eq 0 ]; then
        echo "$func defined in $file appears unused" >> /tmp/unused.txt
    fi
done < /tmp/defs.txt

if [ -s /tmp/unused.txt ]; then
    echo "Potentially unused functions:" 
    cat /tmp/unused.txt
else
    echo "No unused functions detected by heuristics."
fi

# cleanup
rm -f /tmp/defs.txt

# Note: this script is very naive and false positives/negatives are likely.
