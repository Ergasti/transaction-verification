#!/usr/bin/env bash
# One tv-ocr archive for the platform this runs on: a frozen RapidOCR, the models it reads with, and
# the closure's licences. Nothing else -- tesseract and pdftoppm come from the host's package manager.
#
#   ./build-archive.sh [outdir]      VERSION=v1.2.3 PYTHON=python3.12 ./build-archive.sh
#
# Run on the oldest glibc you intend to support (binary.yml uses a manylinux container): the frozen
# binary links the build host's libc, so a newer one silently narrows where the archive will start.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
out="$(mkdir -p "${1:-$here/dist}" && cd "${1:-$here/dist}" && pwd)"
python="${PYTHON:-python3}"
version="${VERSION:-$(git -C "$here" describe --tags --always 2>/dev/null || echo 0.0.0-dev)}"
build="$out/build"

case "$(uname -s)" in
    Linux) os=linux ;;
    Darwin) os=macos ;;
    *) echo "build-archive.sh: $(uname -s) is not a supported build host." >&2; exit 1 ;;
esac
name="tv-ocr-${version}-${os}-$(uname -m)"

# onnxruntime and numpy are pinned at versions that publish no wheel below 3.11, so this is the real floor.
"$python" -c 'import sys; sys.exit(0 if sys.version_info >= (3, 11) else 1)' || {
    echo "build-archive.sh: need Python 3.11+, got $("$python" -V 2>&1)" >&2; exit 1
}

# Debian splits this out, so a new enough Python can still be unable to make a venv -- and `python3 -m venv`
# reports it only after half-creating one.
"$python" -c 'import ensurepip' 2>/dev/null || {
    echo "build-archive.sh: $("$python" -V 2>&1) cannot create a venv; install its venv package (e.g. apt install python3-venv)." >&2
    exit 1
}

rm -rf "$build"
"$python" -m venv "$build/venv"
venv="$build/venv/bin"

# --no-deps: requirements.txt is the whole closure, so a rebuild never resolves anything newer.
"$venv/pip" install --quiet --upgrade pip
"$venv/pip" install --quiet --no-deps -r "$here/requirements.txt"
"$venv/pip" install --quiet pyinstaller==6.22.3

# The sidecar's unit tests used to run at Docker image build; they run here now, so retiring the image
# does not quietly drop them. A build that fails its own tests must not produce an archive.
( cd "$here" && "$venv/python" -m unittest -q test_server )

# macOS only: PyInstaller rewrites every bundled dylib's dependency paths with install_name_tool, which
# refuses OpenVINO's prebuilt libhwloc ("file not in an order that can be processed") and so fails the whole
# build. hwloc and tbbbind only pin threads to NUMA nodes -- OpenVINO loads them if present and runs plainly
# without them, and a laptop has one node anyway -- so the bundle is built without them.
# OpenVINO is not bundled on macOS. PyInstaller has to rewrite the rpath of every dylib it collects, and
# install_name_tool refuses OpenVINO's ("link edit information does not fill the __LINKEDIT segment") -- not one
# bad file but dozens, core libraries among them, so there is nothing to delete past. server.py therefore
# defaults to ONNX Runtime on darwin, which reads the 9 real fixtures identically (~2x slower, measured).
# Before freezing, not after: RapidOCR fetches its models on first use, and they have to be inside the
# archive or every server needs internet access the first time it reads a receipt.
( cd "$here" && "$venv/python" server.py --warm )

"$venv/pyinstaller" --noconfirm --clean --onedir --name tv-ocr \
    --distpath "$build/dist" --workpath "$build/work" --specpath "$build/spec" \
    --collect-all rapidocr --collect-all onnxruntime --collect-all openvino \
    --collect-all shapely --collect-all pyclipper \
    "$here/server.py"

# Attribution for the closure. Tesseract and Poppler are not here, so neither are their licences.
licenses="$build/dist/tv-ocr/LICENSES"
mkdir -p "$licenses"
for info in "$build"/venv/lib/python*/site-packages/*.dist-info; do
    package="$(basename "$info" .dist-info)"
    for file in "$info"/LICENSE* "$info"/licenses/* "$info"/COPYING* "$info"/NOTICE*; do
        [ -f "$file" ] && mkdir -p "$licenses/$package" && cp "$file" "$licenses/$package/"
    done
done

mv "$build/dist/tv-ocr" "$build/dist/$name"
( cd "$build/dist" && tar -czf "$out/$name.tar.gz" "$name" )
( cd "$out" && { shasum -a 256 "$name.tar.gz" || sha256sum "$name.tar.gz"; } > "$name.tar.gz.sha256" )

echo "$out/$name.tar.gz  $(du -sh "$out/$name.tar.gz" | cut -f1)"
