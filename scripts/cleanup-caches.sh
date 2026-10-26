#!/usr/bin/env bash
# Tarefa de rotina (cron): remove caches e temporarios regeneraveis.
#
#   uploads/**/*.qr-cache-p{N}-d{DPI}.png  cache de paginas do detectar_qr.py
#   uploads/**/*.preview-p{N}.png          pre-visualizacoes do upload
#   data/efatura_jobs/*.runner.log         logs dos workers E-fatura
#   contabilidade/magick-*                 temporarios do ImageMagick
#
# Os job_*.json do E-fatura nao sao tocados: a propria app limpa-os
# (efaturaPruneExpiredArtifacts, setting efatura_artifact_retention_days).
#
# Uso: scripts/cleanup-caches.sh [--dry-run]
# Retencao (dias) ajustavel por variaveis de ambiente:
#   QR_CACHE_DAYS (7), PREVIEW_DAYS (7), EFATURA_LOG_DAYS (30), TEMP_DAYS (1)

set -u

APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
QR_CACHE_DAYS="${QR_CACHE_DAYS:-7}"
PREVIEW_DAYS="${PREVIEW_DAYS:-7}"
EFATURA_LOG_DAYS="${EFATURA_LOG_DAYS:-30}"
TEMP_DAYS="${TEMP_DAYS:-1}"
LOG_FILE="$APP_ROOT/data/cleanup.log"

DRY_RUN=0
if [ "${1:-}" = "--dry-run" ]; then
    DRY_RUN=1
fi

summary=""

# purge <descricao> <dias> <dir> <find args...>
purge() {
    local label="$1" days="$2" dir="$3"
    shift 3
    [ -d "$dir" ] || return 0
    local stats
    stats=$(find "$dir" -type f \( "$@" \) -mtime +"$days" -printf '%s\n' | awk '{s+=$1; c++} END {printf "%d %.1f", c, s/1048576}')
    if [ "$DRY_RUN" -eq 0 ]; then
        find "$dir" -type f \( "$@" \) -mtime +"$days" -delete
    fi
    summary="$summary $label=${stats% *}f/${stats#* }MB"
}

purge qr_cache "$QR_CACHE_DAYS" "$APP_ROOT/uploads" -name '*.qr-cache-p*-d*.png'
purge preview "$PREVIEW_DAYS" "$APP_ROOT/uploads" -name '*.preview-p*.png'
purge efatura_logs "$EFATURA_LOG_DAYS" "$APP_ROOT/data/efatura_jobs" -name '*.runner.log'
purge magick_tmp "$TEMP_DAYS" "$APP_ROOT/contabilidade" -name 'magick-*'

line="[$(date '+%Y-%m-%d %H:%M:%S')] cleanup-caches$([ "$DRY_RUN" -eq 1 ] && echo ' (dry-run)'):$summary"
echo "$line"
if [ "$DRY_RUN" -eq 0 ]; then
    echo "$line" >> "$LOG_FILE"
fi
