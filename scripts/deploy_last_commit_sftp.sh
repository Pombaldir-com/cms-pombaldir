#!/usr/bin/env bash
set -euo pipefail

# Deploy only files from HEAD commit via SFTP using .vscode/sftp.json config.
# Filters non-production files by default.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

CONFIG_FILE=".vscode/sftp.json"
if [[ ! -f "$CONFIG_FILE" ]]; then
  echo "Config file not found: $CONFIG_FILE" >&2
  exit 1
fi

if ! command -v expect >/dev/null 2>&1; then
  echo "Missing dependency: expect" >&2
  exit 1
fi

HOST="$(php -r '$j=json_decode(file_get_contents("'"$CONFIG_FILE"'"), true); echo $j["host"] ?? "";')"
PORT="$(php -r '$j=json_decode(file_get_contents("'"$CONFIG_FILE"'"), true); echo $j["port"] ?? "22";')"
USER="$(php -r '$j=json_decode(file_get_contents("'"$CONFIG_FILE"'"), true); echo $j["username"] ?? "";')"
PASS="$(php -r '$j=json_decode(file_get_contents("'"$CONFIG_FILE"'"), true); echo $j["password"] ?? "";')"
REMOTE="$(php -r '$j=json_decode(file_get_contents("'"$CONFIG_FILE"'"), true); echo trim((string)($j["remotePath"] ?? ""), "/");')"

if [[ -z "$HOST" || -z "$PORT" || -z "$USER" || -z "$PASS" || -z "$REMOTE" ]]; then
  echo "Invalid SFTP config in $CONFIG_FILE" >&2
  exit 1
fi

ALL_FILES=()
while IFS= read -r line; do
  [[ -z "$line" ]] && continue
  ALL_FILES+=("$line")
done < <(git show --name-only --pretty=format: HEAD | sed '/^$/d')

if [[ "${#ALL_FILES[@]}" -eq 0 ]]; then
  echo "No files found in HEAD commit."
  exit 0
fi

should_skip() {
  local f="$1"
  [[ ! -f "$f" ]] && return 0
  [[ "$f" == ".gitignore" ]] && return 0
  [[ "$f" == *.md || "$f" == *.MD ]] && return 0
  [[ "$f" == .vscode/* ]] && return 0
  [[ "$f" == .git/* ]] && return 0
  [[ "$f" == tests/* ]] && return 0
  [[ "$f" == .history/* ]] && return 0
  [[ "$f" == *".DS_Store" ]] && return 0
  [[ "$f" == *debug* ]] && return 0
  return 1
}

FILES=()
for f in "${ALL_FILES[@]}"; do
  if should_skip "$f"; then
    continue
  fi
  FILES+=("$f")
done

if [[ "${#FILES[@]}" -eq 0 ]]; then
  echo "No deployable files in HEAD after filters."
  exit 0
fi

CMD_FILE="$(mktemp)"
{
  echo "cd $REMOTE"

  for f in "${FILES[@]}"; do
    d="${f%/*}"
    if [[ "$d" != "$f" && "$d" != "." ]]; then
      path=""
      OLDIFS="$IFS"
      IFS='/'
      for part in $d; do
        [[ -z "$part" ]] && continue
        if [[ -z "$path" ]]; then
          path="$part"
        else
          path="$path/$part"
        fi
        echo "mkdir $path"
      done
      IFS="$OLDIFS"
    fi
  done | awk '!seen[$0]++'

  for f in "${FILES[@]}"; do
    echo "put $f $f"
  done
  echo "bye"
} > "$CMD_FILE"

echo "Deploying ${#FILES[@]} files from HEAD:"
printf ' - %s\n' "${FILES[@]}"

export HOST PORT USER PASS CMD_FILE
expect <<'EOF'
set timeout 1200
set host $env(HOST)
set port $env(PORT)
set user $env(USER)
set pass $env(PASS)
set cmdFile $env(CMD_FILE)

set fh [open $cmdFile r]
set cmds [split [read $fh] "\n"]
close $fh

spawn sftp -oPort=$port -oPreferredAuthentications=password -oPubkeyAuthentication=no $user@$host
expect {
  -re "Are you sure you want to continue connecting.*" { send "yes\r"; exp_continue }
  -re "(?i)password:" { send "$pass\r"; exp_continue }
  -re "sftp>" {}
  timeout { puts "TIMEOUT_LOGIN"; exit 2 }
}

foreach cmd $cmds {
  if {[string trim $cmd] eq ""} { continue }
  send -- "$cmd\r"
  expect {
    -re "sftp>" {}
    timeout { puts "TIMEOUT_CMD:$cmd"; exit 3 }
  }
}
EOF

rm -f "$CMD_FILE"
echo "Done."
