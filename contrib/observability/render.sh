#!/bin/sh
# Substitutes the chart tokens in the rule file for a local vmalert-tool run.
# Usage: contrib/observability/render.sh [release] [namespace] <outdir>
set -eu

release="${1:-konventix}"
namespace="${2:-konventix}"
outdir="${3:?outdir}"
here="$(cd "$(dirname "$0")" && pwd)"

mkdir -p "$outdir"
sed -e "s/__RELEASE__/${release}/g" -e "s/__NAMESPACE__/${namespace}/g" \
    "$here/alerts/konventix.rules.yaml" > "$outdir/konventix.rules.yaml"
cp "$here/alerts/tests/konventix.rules.test.yaml" "$outdir/"
echo "$outdir/konventix.rules.yaml"
