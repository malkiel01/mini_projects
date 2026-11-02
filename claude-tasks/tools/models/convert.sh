#!/usr/bin/env bash
# ההמרה שלנו של מודלי התרגום (docs/spec.md §5.11.1): מהמודלים הרשמיים של Helsinki-NLP (Apache-2.0)
# ל-ONNX ‏q8 ש-transformers.js 3.7.6 טוען. רץ ב-Actions (claude-tasks-models.yml), וגם מקומית.
#
#   bash claude-tasks/tools/models/convert.sh <workdir>      # ‏python 3.12 + requirements.txt מותקנים
#
# יוצא: <workdir>/dist/<pair>/<build>/ — בדיוק מה שעולה לשרת אל claude-tasks/models/:
#   config.json generation_config.json tokenizer.json tokenizer_config.json README.md (כרטיס המודל)
#   onnx/encoder_model_quantized.onnx onnx/decoder_model_merged_quantized.onnx manifest.json (גדלים, sha256)
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
W="$(mkdir -p "$1" && cd "$1" && pwd)"
TJS=3.7.6
BUILD="$(python -c 'import json,sys; print(json.load(open(sys.argv[1]))["build"])' "$HERE/build.json")"

# הסקריפטים של transformers.js — באותה גרסה של הספרייה שבדפדפן
mkdir -p "$W/tjs/scripts/extra"
for f in convert.py quantize.py utils.py float16.py extra/marian.py; do
  curl -sSfL --retry 3 -o "$W/tjs/scripts/$f" "https://raw.githubusercontent.com/huggingface/transformers.js/$TJS/scripts/$f"
done
touch "$W/tjs/scripts/__init__.py" "$W/tjs/scripts/extra/__init__.py"

python - "$HERE/build.json" <<'PY' > "$W/pairs.txt"
import json, sys
for pair, p in json.load(open(sys.argv[1]))["pairs"].items():
    print(pair, p["source"], p["revision"])
PY

while read -r PAIR SRC REV; do
  echo "::group::$PAIR ← $SRC@$REV"
  M="$W/src/$PAIR"
  rm -rf "$M"
  python - "$SRC" "$REV" "$M" <<'PY'
import sys, time
from huggingface_hub import snapshot_download
# ‏Hugging Face נופל לפעמים לרגע מה-runner ("check your internet connection") — 4 נסיונות: 30, 90, 180 ש'
for i, wait in enumerate([0, 30, 90, 180]):
    time.sleep(wait)
    try:
        snapshot_download(sys.argv[1], revision=sys.argv[2], local_dir=sys.argv[3],
                          allow_patterns=["config.json", "generation_config.json", "pytorch_model.bin", "*.spm",
                                          "vocab.json", "tokenizer_config.json", "README.md"])
        break
    except Exception as e:
        print(f"::warning::הורדה מ-Hugging Face נכשלה (נסיון {i + 1}): {e}", flush=True)
else:
    sys.exit("לא הצלחתי להוריד את המודל מ-Hugging Face")
PY
  # ייצוא ב-Optimum (fp32) — בלי onnxslim (ראו fold_q8.py) ובלי הכימות שלו
  (cd "$W/tjs" && python -m scripts.convert --model_id "$M" --task text2text-generation-with-past --skip_onnxslim)
  ls "$M/onnx/encoder_model.onnx" "$M/onnx/decoder_model_merged.onnx"
  (cd "$W/tjs" && PYTHONPATH="$W/tjs" python "$HERE/fold_q8.py" "$M/onnx/encoder_model.onnx" "$M/q-enc.onnx")
  (cd "$W/tjs" && PYTHONPATH="$W/tjs" python "$HERE/fold_q8.py" "$M/onnx/decoder_model_merged.onnx" "$M/q-dec.onnx" --slim --lm-head)

  D="$W/dist/$PAIR/$BUILD"
  rm -rf "$D"; mkdir -p "$D/onnx"
  cp "$M"/{config.json,generation_config.json,tokenizer.json,tokenizer_config.json,README.md} "$D/"
  mv "$M/q-enc.onnx" "$D/onnx/encoder_model_quantized.onnx"
  mv "$M/q-dec.onnx" "$D/onnx/decoder_model_merged_quantized.onnx"
  python - "$D" "$PAIR" "$SRC" "$REV" "$BUILD" "$TJS" <<'PY'
import hashlib, json, os, sys
d, pair, src, rev, build, tjs = sys.argv[1:]
files = {}
for root, _, names in os.walk(d):
    for n in sorted(names):
        p = os.path.join(root, n); rel = os.path.relpath(p, d).replace(os.sep, "/")
        h = hashlib.sha256()
        with open(p, "rb") as fh:
            for b in iter(lambda: fh.read(1 << 20), b""): h.update(b)
        files[rel] = {"size": os.path.getsize(p), "sha256": h.hexdigest()}
json.dump({"pair": pair, "build": build, "source": src, "revision": rev, "license": "apache-2.0",
           "credit": "Opus-MT · University of Helsinki · Apache-2.0", "converter": f"transformers.js {tjs} + fold_q8.py (q8)",
           "files": files}, open(os.path.join(d, "manifest.json"), "w"), indent=1, ensure_ascii=False)
for k, v in files.items(): print(f"{v['size']:>10}  {k}")
PY
  rm -rf "$M"
  echo "::endgroup::"
done < "$W/pairs.txt"
